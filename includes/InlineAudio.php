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
 * <audioplay file="Clip.oga">label</audioplay> - a play control sized to fit a line of prose.
 *
 * Deliberately never emits `controls`: that hands rendering to the browser, which
 * draws a transport bar tens of pixels tall and inflates the line box. An <audio>
 * element without `controls` renders nothing at all, so it serves purely as a
 * playback engine while the visible control is a 1em glyph drawn in CSS.
 *
 * The server emits a plain link to the file description page; ext.sgPack.audio
 * upgrades it in place. Without JavaScript, or for a format the browser cannot
 * decode, the reader is left with a working link rather than a dead button.
 */
class InlineAudio {

	/**
	 * Media types this tag is willing to hand to an <audio> element.
	 *
	 * MULTIMEDIA is included because without TimedMediaHandler installed, core
	 * cannot tell audio-only Ogg from Ogg with a video track: .oga, .opus and
	 * .ogg all resolve to the MIME type application/ogg, whose media type is
	 * MULTIMEDIA rather than AUDIO. Requiring AUDIO here would reject every Ogg
	 * file on such a wiki. Anything that really is undecodable falls back to the
	 * plain link on the client, where canPlayType() can answer properly.
	 */
	private const PLAYABLE_MEDIA_TYPES = [ MEDIATYPE_AUDIO, MEDIATYPE_MULTIMEDIA ];

	/**
	 * Attribute values that turn the seek bar on for a single control.
	 */
	private const TRUTHY = [ 'yes', 'true', '1', 'on' ];

	/**
	 * Expand template parameters and templates inside a tag attribute.
	 *
	 * MediaWiki hands setHook() callbacks the *raw* attribute text, so an attribute
	 * written as file="{{{1}}}" inside a template arrives literally as "{{{1}}}" and
	 * has to be expanded against the frame. Tag content is different:
	 * recursiveTagParse() already expands that.
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
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderTag( $input, $args, $parser, $frame ) {
		$fileName = isset( $args['file'] )
			? self::expandAttribute( $args['file'], $parser, $frame )
			: '';
		if ( $fileName === '' ) {
			return self::error( 'sgpack-audio-nofile' );
		}

		// Accepts both "Clip.oga" and "File:Clip.oga"
		$title = Title::newFromText( $fileName, NS_FILE );
		if ( !$title || !$title->inNamespace( NS_FILE ) ) {
			return self::error( 'sgpack-audio-notfound' );
		}

		$parserOutput = $parser->getOutput();

		// Register the file as a dependency of this parse even when it does not
		// exist yet, so that uploading, re-uploading or deleting it purges the
		// pages referring to it.
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		$parserOutput->addImage(
			$title->getDBkey(),
			$file ? $file->getTimestamp() : null,
			$file ? $file->getSha1() : null
		);

		if ( !$file ) {
			return self::error( 'sgpack-audio-notfound' );
		}

		if ( !in_array( $file->getMediaType(), self::PLAYABLE_MEDIA_TYPES, true ) ) {
			return self::error( 'sgpack-audio-notaudio' );
		}

		// Wiki-wide default, overridable per control. Off by default because a
		// seek bar needs preload="metadata" to know the duration, which costs a
		// request per control on page load.
		$seekable = (bool)MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'SGPackAudioSeekBar' );
		if ( isset( $args['seek'] ) ) {
			$seek = strtolower( self::expandAttribute( $args['seek'], $parser, $frame ) );
			// An empty seek= (a template passing through an unset parameter) means
			// "not specified", so the wiki default still applies.
			if ( $seek !== '' ) {
				$seekable = in_array( $seek, self::TRUTHY, true );
			}
		}

		// Both from the ParserOutput rather than OutputPage, so they survive the
		// parser cache and only load on pages that actually use the tag. Note both
		// take an array, unlike their OutputPage counterparts.
		$parserOutput->addModuleStyles( [ 'ext.sgPack.audio.styles' ] );
		$parserOutput->addModules( [ 'ext.sgPack.audio' ] );

		$name = $title->getText();

		$classes = [ 'mw-sgpack-audio' ];
		if ( $seekable ) {
			$classes[] = 'mw-sgpack-audio-seekable';
		}

		// The icon carries no text, so the toggle needs an explicit accessible
		// name. Content language again, for the same cache reason as error().
		$playLabel = wfMessage( 'sgpack-audio-play', $name )->inContentLanguage()->text();

		$html = Html::rawElement(
			'a',
			[
				'class' => 'mw-sgpack-audio-toggle',
				'href' => $title->getLocalURL(),
				'title' => $playLabel,
				'aria-label' => $playLabel,
				'data-mw-sgpack-audio-src' => $file->getUrl(),
				'data-mw-sgpack-audio-type' => $file->getMimeType(),
				'data-mw-sgpack-audio-name' => $name,
				// Behaviour keyed on data, presentation on the wrapper class. The
				// script must not have to read a styling class to decide what to do.
				'data-mw-sgpack-audio-seek' => $seekable ? '1' : '0',
			],
			Html::element( 'span', [ 'class' => 'mw-sgpack-audio-icon' ] )
		);

		if ( $seekable ) {
			// A native range input, not a div with role="slider": it brings keyboard
			// operation and ARIA value semantics for free. Inert until the client
			// knows the duration.
			$html .= Html::element( 'input', [
				'class' => 'mw-sgpack-audio-seek',
				'type' => 'range',
				'min' => 0,
				'max' => 0,
				'value' => 0,
				'step' => 'any',
				'disabled' => true,
				'aria-label' => wfMessage( 'sgpack-audio-seek' )->inContentLanguage()->text(),
			] );
		}

		// Parsed, never raw: tag-hook return values are not sanitised by MediaWiki.
		//
		// Emptiness has to be judged *after* expanding. A template passing a label
		// through as <audioplay …>{{{text|}}}</audioplay> hands us the literal
		// "{{{text|}}}", which is not empty.
		$label = '';
		if ( $input !== null && trim( $input ) !== '' ) {
			$label = trim( $parser->recursiveTagParse( trim( $input ), $frame ) );
		}
		if ( $label !== '' ) {
			$html .= Html::rawElement(
				'span',
				[ 'class' => 'mw-sgpack-audio-label' ],
				$label
			);
		}

		return Html::rawElement( 'span', [ 'class' => $classes ], $html );
	}
}
