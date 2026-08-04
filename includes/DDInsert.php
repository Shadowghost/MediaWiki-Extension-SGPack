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
use MediaWiki\Parser\Sanitizer;

class DDInsert {
	/**
	 * Stack of open <ddselect> blocks, innermost last.
	 *
	 * This is a stack rather than a single block so that a nested <ddselect>
	 * does not overwrite the state of the one enclosing it, and so that a
	 * <ddvalue> outside any <ddselect> can be detected instead of reading
	 * undefined 'pwidth'/'pheight' keys.
	 *
	 * @var array[]
	 */
	private static $ddIStack = [];

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	public static function sgpEncode( $text ) {
		$encoded = '';
		$length = mb_strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			$encoded .= '%' . wordwrap( bin2hex( mb_substr( $text, $i, 1 ) ), 2, '%', true );
		}
		return $encoded;
	}

	/**
	 * JSButton - just for normal use in page
	 *
	 * The `click`, `mover` and `mout` attributes are intentionally not supported.
	 * They wrote editor-supplied text straight into onclick/onmouseover/onmouseout,
	 * which made this tag an arbitrary-JavaScript primitive for anyone who could
	 * edit a page. They cannot be made safe while editors control their contents,
	 * so they are dropped rather than escaped.
	 *
	 * Every attribute goes through Html::rawElement(), which escapes it. That
	 * matters even for plain attributes such as `id` or `value`, because
	 * Sanitizer::decodeTagAttributes() resolves character references before a tag
	 * hook sees them, so an unescaped `&quot;` can close the attribute and inject a
	 * handler of its own.
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function jsButton( $input, $args, $parser, $frame ) {
		$attribs = [
			'type' => 'button',
			'name' => $args['name'] ?? 'jsbutton',
			'class' => $args['class'] ?? 'jsbutton',
		];
		if ( isset( $args['id'] ) ) {
			$attribs['id'] = $args['id'];
		}
		if ( isset( $args['value'] ) ) {
			$attribs['value'] = $args['value'];
		}
		if ( isset( $args['style'] ) ) {
			$attribs['style'] = Sanitizer::checkCss( $args['style'] );
		}

		// rawElement, not element: the label is parsed wikitext and so is already HTML
		return Html::rawElement( 'button', $attribs, $parser->recursiveTagParse( $input, $frame ) );
	}

	/**
	 * Button
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function ddIButton( $input, $args, $parser, $frame ) {
		// If no show parameter is given use input also as showText.
		//
		// $input must not reach the output raw: tag-hook return values are spliced
		// into the page behind a strip marker and are never sanitised by MediaWiki.
		// Parsing it routes the markup through Sanitizer while still allowing
		// wikitext in the label, which plain escaping would not. The insert payload
		// below is built from the unparsed $input and is unaffected.
		$show = isset( $args['show'] )
			? htmlspecialchars( $args['show'] )
			: $parser->recursiveTagParse( $input, $frame );
		// Get sampleText if given
		$sample = $args['sample'] ?? '';
		// Picture
		if ( isset( $args['picture'] ) ) {
			$image = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $args['picture'] );
			if ( $image ) {
				$iwidth = $image->getWidth();
				$iheight = $image->getHeight();
				// Test if picture parameter (iwidth, iheight)
				if ( isset( $args['iwidth'] ) ) {
					$iwidth = intval( $args['iwidth'] );
				}
				if ( isset( $args['iheight'] ) ) {
					$iheight = intval( $args['iheight'] );
				}
				$show = Html::element( 'img', [
					'src' => $image->getURL(),
					'width' => $iwidth,
					'height' => $iheight,
				] );
			}
		}
		// Split parameter
		$einput = explode( '+', $input );
		// If too few parameters, fill with ''
		$einput[] = '';

		// The payload travels in a data attribute and is picked up by the delegated
		// click handler in ext.sgPack.js, so no inline onclick is needed and the
		// extension does not require script-src 'unsafe-inline'.
		return Html::rawElement(
			'a',
			[
				'class' => 'mw-sgpack-ddinsert-button',
				'href' => '#',
				'data-mw-sgpack-insert' => self::sgpEncode( $einput[0] . "+" . $einput[1] . "+" . $sample ),
			],
			$show
		);
	}

	/**
	 * <ddselect title="titleText" size="sizeInt" name="nameText">...</ddselect>
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function ddISelect( $input, $args, $parser, $frame ) {
		$block = [
			'size' => 1,
			'name' => 'DDSelect-' . mt_rand(),
			'title' => wfMessage( 'ddinsert-selecttitle' )->text(),
			'pwidth' => 0,
			'pheight' => 1,
			'values' => [],
		];
		if ( isset( $args['title'] ) ) {
			$block['title'] = $args['title'];
		}
		if ( isset( $args['size'] ) ) {
			$block['size'] = $args['size'];
		}
		if ( isset( $args['name'] ) ) {
			$block['name'] = $args['name'];
		}

		// The nested <ddvalue> tags fill this block in while $input is parsed.
		self::$ddIStack[] = $block;
		try {
			$parser->recursiveTagParse( $input, $frame );
		} finally {
			$block = array_pop( self::$ddIStack );
		}

		return self::ddIOutput( $block );
	}

	/**
	 * <ddvalue show="showText" sample="sampleText" picture="name">value</ddvalue>
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function ddIValue( $input, $args, $parser, $frame ) {
		// A <ddvalue> outside any <ddselect> has no block to add itself to
		if ( !self::$ddIStack ) {
			return '';
		}
		$top = count( self::$ddIStack ) - 1;
		// If no show parameter is given use input also as showText
		$show = $args['show'] ?? $input;
		// Get sampleText if given
		$sample = $args['sample'] ?? '';
		// Add + to input if not set - required for javascript-split
		if ( strpos( $input, "+" ) === false ) {
			$input .= "+";
		}
		// Picture
		$iURL = '';
		if ( isset( $args['picture'] ) ) {
			$image = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $args['picture'] );
			if ( $image ) {
				$iURL = $image->getURL();
				$iwidth = $image->getWidth();
				$iheight = $image->getHeight();
				if ( $iwidth > ( self::$ddIStack[$top]['pwidth'] - 5 ) ) {
					self::$ddIStack[$top]['pwidth'] = $iwidth + 5;
				}
				if ( $iheight > ( self::$ddIStack[$top]['pheight'] ) ) {
					self::$ddIStack[$top]['pheight'] = $iheight;
				}
			}
		}
		// Save parameter to the enclosing <ddselect> block
		self::$ddIStack[$top]['values'][] = [ 'value' => $input . '+' . $sample, 'text' => $show, 'image' => $iURL ];
		return '';
	}

	/**
	 * Create Output
	 *
	 * @param array $block A completed <ddselect> block
	 *
	 * @return string
	 */
	private static function ddIOutput( array $block ) {
		// The mw-sgpack-ddinsert-select class is what the delegated change handler
		// in ext.sgPack.js binds to, so no inline onchange is needed. Resetting the
		// selection back to the placeholder is handled there too.
		$output = Html::openElement( 'select', [
			'size' => $block['size'],
			'name' => $block['name'],
			'class' => 'mw-sgpack-ddinsert-select',
		] );
		$output .= Html::element(
			'option',
			[ 'value' => '++', 'selected' => 'selected' ],
			$block['title']
		);
		foreach ( $block['values'] as $values ) {
			$output .= self::ddILine( $block, $values['text'], $values['value'], $values['image'] );
		}
		$output .= Html::closeElement( 'select' );
		return $output;
	}

	/**
	 * Create option line
	 *
	 * @param array $block The enclosing <ddselect> block
	 * @param string $text
	 * @param string $value
	 * @param string $image
	 *
	 * @return string
	 */
	private static function ddILine( array $block, $text, $value, $image ) {
		$attribs = [ 'value' => self::sgpEncode( $value ) ];
		if ( $block['pwidth'] > 0 ) {
			$css = 'padding-left: ' . $block['pwidth'] . 'px; padding-right: 5px;';
			if ( !empty( $image ) ) {
				$css = 'height: ' . $block['pheight'] . 'px; ' . $css
					. ' background-repeat: no-repeat; background-image: url(' . $image . ');';
			}
			$attribs['style'] = $css;
		}
		return Html::element( 'option', $attribs, $text ) . "\n";
	}
}
