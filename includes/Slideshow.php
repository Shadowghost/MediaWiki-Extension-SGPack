<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;

/**
 * <slideshow> - a floating box that cycles through a handful of images with captions.
 *
 * A reimplementation of René Raule's `Slideshow` 0.5 extension, which stargate-wiki.de
 * used until the 1.43 migration dropped it. Only the wikitext syntax survives:
 *
 *     <slideshow width="200" speed="20" textheight="20" timeout="4000" effect="none" float="left">
 *     Andockklammern.JPG|Die Andockklammern, die die Prometheus festhalten.
 *     Dach Hangar.JPG|Das Hangardach öffnet sich.
 *     </slideshow>
 *
 * The original shipped a copy of the jQuery Cycle plugin (3.0.3, unmaintained since
 * 2013), positioned every slide from JavaScript, drove a lightbox through jQuery UI's
 * dialog widget and carried six PNG player icons. None of that is needed any more:
 * CSS positions the slides, ext.sgPack.slideshow cycles them, and the lightbox is a
 * native <dialog>.
 *
 * Two behavioural notes:
 *
 *   - Each slide is a link to its file description page, so the images stay reachable
 *     without JavaScript. The original made them inert and offered a control that
 *     navigated there instead.
 *   - The enlarged view loads a thumbnail capped at LIGHTBOX_WIDTH rather than the
 *     original upload, which on this wiki is routinely several megabytes.
 */
class Slideshow {

	/** Thumbnail width in pixels. */
	private const DEFAULT_WIDTH = 200;
	private const MIN_WIDTH = 20;
	private const MAX_WIDTH = 1000;

	/**
	 * Line height of the caption area in pixels.
	 *
	 * The caption box is two lines tall, which is what the captions on the wiki were
	 * written to fit; CAPTION_PADDING is the slack the original left below them.
	 */
	private const DEFAULT_TEXT_HEIGHT = 20;
	private const MIN_TEXT_HEIGHT = 0;
	private const MAX_TEXT_HEIGHT = 100;
	private const CAPTION_LINES = 2;
	private const CAPTION_PADDING = 8;

	/** Milliseconds a slide stays up. 0 means "do not advance on its own". */
	private const DEFAULT_TIMEOUT = 4000;
	private const MAX_TIMEOUT = 600000;

	/** Milliseconds a transition takes. Only used when the effect is `fade`. */
	private const DEFAULT_SPEED = 20;
	private const MAX_SPEED = 10000;

	/** Transitions the tag understands. */
	private const EFFECTS = [ 'none', 'fade' ];

	/**
	 * Border and padding drawn around the image by ext.sgPack.slideshow.css, in
	 * pixels, summed over both edges: 2 * ( 15px padding + 1px border ).
	 *
	 * The frame is a border-box, so its inline width and height have to include this;
	 * keep the two in step.
	 */
	private const FRAME_CHROME = 32;

	/** Largest thumbnail the enlarged view will request. */
	private const LIGHTBOX_WIDTH = 1280;

	/** Aspect ratio assumed for the frame when not one image could be measured. */
	private const FALLBACK_RATIO = 16 / 9;

	/** Attribute values that mean "on". */
	private const TRUTHY = [ 'yes', 'true', '1', 'on' ];

	/**
	 * Expand template parameters and templates inside a tag attribute.
	 *
	 * setHook() callbacks are handed the *raw* attribute text, so width="{{{1}}}"
	 * inside a template arrives literally as "{{{1}}}". Tag content is different -
	 * recursiveTagParse() expands that.
	 *
	 * @param string $value Raw attribute value
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	private static function expandAttribute( $value, $parser, $frame ) {
		return trim( $parser->replaceVariables( $value, $frame ) );
	}

	/**
	 * @param string $message
	 *
	 * @return string
	 */
	private static function error( $message ) {
		return Html::element(
			'strong',
			[ 'class' => 'error' ],
			// Content language: this string lands in the parser cache, so it must
			// not vary with the viewing user's language.
			wfMessage( $message )->inContentLanguage()->text()
		);
	}

	/**
	 * Read a numeric attribute, clamped to a sane range.
	 *
	 * An absent or non-numeric value falls back to the default rather than erroring:
	 * these are presentation knobs, and a page full of red error text helps nobody.
	 *
	 * @param array $args
	 * @param string $name
	 * @param int $default
	 * @param int $min
	 * @param int $max
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return int
	 */
	private static function intAttribute( $args, $name, $default, $min, $max, $parser, $frame ) {
		if ( !isset( $args[$name] ) ) {
			return $default;
		}

		$raw = self::expandAttribute( $args[$name], $parser, $frame );
		if ( !preg_match( '/^-?\d+$/', $raw ) ) {
			return $default;
		}

		return max( $min, min( $max, (int)$raw ) );
	}

	/**
	 * Read a boolean attribute.
	 *
	 * @param array $args
	 * @param string $name
	 * @param bool $default
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return bool
	 */
	private static function boolAttribute( $args, $name, $default, $parser, $frame ) {
		if ( !isset( $args[$name] ) ) {
			return $default;
		}

		$raw = strtolower( self::expandAttribute( $args[$name], $parser, $frame ) );
		// An empty value is a template passing through an unset parameter, which
		// means "not specified" rather than "off".
		if ( $raw === '' ) {
			return $default;
		}

		return in_array( $raw, self::TRUTHY, true );
	}

	/**
	 * Turn one content line into a slide.
	 *
	 * @param string $line `Name.jpg|caption`, the caption being optional
	 * @param int $width Thumbnail width in pixels
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return array|null { tag, attribs, content, caption, height }, or null if the
	 *   line names no file. The class list is left to the caller, which alone knows
	 *   which slide starts out visible.
	 */
	private static function buildSlide( $line, $width, $parser, $frame ) {
		[ $name, $captionSource ] = array_pad( explode( '|', $line, 2 ), 2, '' );

		$name = trim( $name );
		if ( $name === '' ) {
			return null;
		}

		// Accepts both "Prometheus.jpg" and "Datei:Prometheus.jpg"
		$title = Title::newFromText( $name, NS_FILE );
		if ( !$title || !$title->inNamespace( NS_FILE ) ) {
			return null;
		}

		// Parsed, never raw: tag-hook return values are not sanitised by MediaWiki.
		$caption = '';
		if ( trim( $captionSource ) !== '' ) {
			$caption = trim( $parser->recursiveTagParse( trim( $captionSource ), $frame ) );
		}

		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );

		// Register the file as a dependency of this parse even when it does not exist
		// yet, so that uploading, re-uploading or deleting it purges the pages that
		// show it.
		$parser->getOutput()->addImage(
			$title->getDBkey(),
			$file ? $file->getTimestamp() : null,
			$file ? $file->getSha1() : null
		);

		$thumb = ( $file && $file->canRender() )
			? $file->transform( [ 'width' => $width ] )
			: false;
		if ( !$thumb || $thumb->isError() ) {
			return [
				'tag' => 'span',
				'attribs' => [ 'class' => 'mw-sgpack-slideshow-missing' ],
				// `content` is raw HTML by contract - the other branch puts an <img>
				// here - so the name has to be escaped on its way in.
				'content' => htmlspecialchars( $title->getPrefixedText() ),
				'caption' => $caption,
				'height' => 0,
			];
		}

		// The enlarged view gets its own, larger thumbnail. Falling back to the
		// original - which is what the previous extension linked - means handing the
		// reader a multi-megabyte upload to look at a picture in a sidebar.
		$fullWidth = min( (int)$file->getWidth(), self::LIGHTBOX_WIDTH );
		$full = $fullWidth > 0 ? $file->transform( [ 'width' => $fullWidth ] ) : false;
		$fullUrl = ( $full && !$full->isError() ) ? $full->getUrl() : $thumb->getUrl();

		$image = Html::element( 'img', [
			'src' => $thumb->getUrl(),
			'width' => $thumb->getWidth(),
			'height' => $thumb->getHeight(),
			'loading' => 'lazy',
			// The caption sits right below the image and says the same thing, so
			// repeating it here would only make screen readers read it twice.
			'alt' => '',
		] );

		return [
			'tag' => 'a',
			'attribs' => [
				'href' => $title->getLocalURL(),
				'title' => $title->getText(),
				// Read by the enlarged view. Kept on the slide rather than on the
				// container so it survives reordering.
				'data-mw-sgpack-slideshow-full' => $fullUrl,
				'data-mw-sgpack-slideshow-name' => $title->getText(),
			],
			'content' => $image,
			'caption' => $caption,
			'height' => (int)$thumb->getHeight(),
		];
	}

	/**
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderTag( $input, $args, $parser, $frame ) {
		$width = self::intAttribute(
			$args, 'width', self::DEFAULT_WIDTH, self::MIN_WIDTH, self::MAX_WIDTH, $parser, $frame
		);
		$textHeight = self::intAttribute(
			$args, 'textheight', self::DEFAULT_TEXT_HEIGHT,
			self::MIN_TEXT_HEIGHT, self::MAX_TEXT_HEIGHT, $parser, $frame
		);
		$timeout = self::intAttribute(
			$args, 'timeout', self::DEFAULT_TIMEOUT, 0, self::MAX_TIMEOUT, $parser, $frame
		);
		$speed = self::intAttribute(
			$args, 'speed', self::DEFAULT_SPEED, 0, self::MAX_SPEED, $parser, $frame
		);

		$effect = isset( $args['effect'] )
			? strtolower( self::expandAttribute( $args['effect'], $parser, $frame ) )
			: '';
		if ( !in_array( $effect, self::EFFECTS, true ) ) {
			$effect = self::EFFECTS[0];
		}

		$float = isset( $args['float'] )
			? strtolower( self::expandAttribute( $args['float'], $parser, $frame ) )
			: '';
		if ( !in_array( $float, [ 'left', 'right' ], true ) ) {
			$float = 'none';
		}

		$random = self::boolAttribute( $args, 'random', false, $parser, $frame );
		$autostart = self::boolAttribute( $args, 'autostart', true, $parser, $frame );

		$slides = [];
		foreach ( preg_split( '/\R/', (string)$input ) as $line ) {
			$slide = self::buildSlide( $line, $width, $parser, $frame );
			if ( $slide ) {
				$slides[] = $slide;
			}
		}

		if ( !$slides ) {
			return self::error( 'sgpack-slideshow-noimages' );
		}

		$parserOutput = $parser->getOutput();
		// Both from the ParserOutput rather than OutputPage, so they survive the
		// parser cache and only load on pages that actually use the tag.
		$parserOutput->addModuleStyles( [ 'ext.sgPack.slideshow.styles' ] );
		$parserOutput->addModules( [ 'ext.sgPack.slideshow' ] );

		// Every slide is the same width, so only the tallest one decides how much room
		// the frame needs. Ceiling, not rounding: half a pixel short crops the image.
		$imageHeight = 0;
		foreach ( $slides as $slide ) {
			$imageHeight = max( $imageHeight, $slide['height'] );
		}
		if ( $imageHeight === 0 ) {
			$imageHeight = (int)ceil( $width / self::FALLBACK_RATIO );
		}

		$captionHeight = $textHeight > 0
			? self::CAPTION_LINES * $textHeight + self::CAPTION_PADDING
			: 0;

		$slideHtml = '';
		$captionHtml = '';
		foreach ( $slides as $index => $slide ) {
			// The first slide is marked server-side, so a reader without JavaScript
			// still sees an image and its caption rather than an empty box.
			$classes = [ 'mw-sgpack-slideshow-slide' ];
			$captionClasses = [ 'mw-sgpack-slideshow-caption' ];
			if ( $index === 0 ) {
				$classes[] = 'mw-sgpack-slideshow-current';
				$captionClasses[] = 'mw-sgpack-slideshow-current';
			}
			if ( isset( $slide['attribs']['class'] ) ) {
				$classes[] = $slide['attribs']['class'];
			}

			$slideHtml .= Html::rawElement(
				$slide['tag'],
				[ 'class' => $classes ] + $slide['attribs'],
				$slide['content']
			);

			$captionHtml .= Html::rawElement(
				'p',
				[ 'class' => $captionClasses ],
				$slide['caption']
			);
		}

		$body = Html::rawElement(
			'div',
			[
				'class' => 'mw-sgpack-slideshow-frame',
				'style' => 'height: ' . ( $imageHeight + self::FRAME_CHROME ) . 'px;',
			],
			$slideHtml
		);

		if ( $captionHeight > 0 ) {
			$body .= Html::rawElement(
				'div',
				[
					'class' => 'mw-sgpack-slideshow-captions',
					'style' => 'height: ' . $captionHeight . 'px; line-height: ' . $textHeight . 'px;',
				],
				$captionHtml
			);
		}

		return Html::rawElement(
			'div',
			[
				'class' => [ 'mw-sgpack-slideshow', 'mw-sgpack-slideshow-float-' . $float ],
				'style' => 'width: ' . ( $width + self::FRAME_CHROME ) . 'px;',
				// One attribute rather than five, because the script wants the whole
				// configuration at once. Read it with getAttribute()/JSON.parse, never
				// jQuery .data(), which coerces values that look numeric or boolean.
				'data-mw-sgpack-slideshow' => json_encode( [
					'timeout' => $timeout,
					'speed' => $speed,
					'effect' => $effect,
					'random' => $random,
					'autostart' => $autostart,
				] ),
			],
			$body
		);
	}
}
