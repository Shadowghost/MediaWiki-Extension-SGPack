<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\Sanitizer;

class Sort2 {
	/** @var Parser */
	private $parser;

	/** @var PPFrame */
	private $frame;

	/** @var string */
	private $order = 'asc';

	/** @var string */
	private $type = 'ul';

	/** @var string */
	private $separator = "\n";

	/** @var string */
	private $casesense = "false";

	/** @var string */
	private $style = "";

	/** @var int|null */
	private $start = null;

	/** @var string */
	private $title = "";

	/**
	 * Whether the documented `style=` attribute is honoured.
	 *
	 * This was declared and never assigned, so `$this->allowStyles == true` was
	 * always false and `style=` had in fact never worked. Values are escaped by
	 * Html::openElement() and filtered by Sanitizer::checkCss() in loadSettings().
	 *
	 * @var bool
	 */
	private $allowStyles = true;

	/**
	 * @param Parser $parser
	 * @param PPFrame $frame
	 */
	public function __construct( $parser, $frame ) {
		$this->parser = $parser;
		$this->frame = $frame;
	}

	/**
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function sgPackRenderSort( $input, $args, $parser, $frame ) {
		$sorter2 = new Sort2( $parser, $frame );
		$sorter2->loadSettings( $args );
		return $sorter2->sortToHtml( $input );
	}

	/**
	 * @param array $settings
	 */
	private function loadSettings( $settings ): void {
		if ( isset( $settings['order'] ) ) {
			$o = strtolower( $settings['order'] );
			if ( $o == 'asc' || $o == 'desc' || $o == 'none' ) {
				$this->order = $o;
			}
		}
		if ( isset( $settings['type'] ) ) {
			$c = strtolower( $settings['type'] );
			if ( $c == 'ol' || $c == 'ul' || $c == 'dl' || $c == 'inline' || $c == "br" ) {
				$this->type = $c;
			}
		}
		if ( isset( $settings['separator'] ) && $this->type == "inline" ) {
			$this->separator = str_ireplace( "&sp;", " ", $settings['separator'] );
		}
		if ( isset( $settings['casesense'] ) && strtolower( $settings['casesense'] ) == "true" ) {
			$this->casesense = "true";
		}
		// Both of these used to be interpolated into the start tag raw. They are
		// stored as plain values now and escaped by Html::openElement() in
		// makeList(); CSS additionally goes through Sanitizer::checkCss().
		if ( isset( $settings['style'] ) && $this->allowStyles ) {
			$this->style = Sanitizer::checkCss( $settings['style'] );
		}
		if ( isset( $settings['start'] ) ) {
			// <ol start> is an integer
			$this->start = (int)$settings['start'];
		}
		if ( isset( $settings['title'] ) ) {
			$this->title = str_ireplace( "&sp;", " ", $settings['title'] );
		}
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function sortToHtml( $text ) {
		$lines = $this->internalSort( $text );
		$list = $this->makeList( $lines );
		$html = $this->parse( $list );
		return $html;
	}

	/**
	 * @param string $text
	 *
	 * @return array
	 */
	private function internalSort( $text ) {
		$lines = explode( "\n", $text );

		// Map line *index* to sort key. This used to be keyed by the line content
		// itself ( $inter[$line] = ... ), which silently collapsed identical
		// entries — a list with two equal lines came back with one.
		$keys = [];
		foreach ( $lines as $index => $line ) {
			$keys[$index] = $this->stripWikiTokens( $line );
		}

		// natsort/natcasesort preserve keys, so the indexes survive the sort
		if ( $this->order != "none" ) {
			if ( $this->casesense == "true" ) {
				natsort( $keys );
			} else {
				natcasesort( $keys );
			}
		}

		if ( $this->order == 'desc' ) {
			$keys = array_reverse( $keys, true );
		}

		// Resolve the sorted indexes back to the original lines
		$sorted = [];
		foreach ( array_keys( $keys ) as $index ) {
			$sorted[] = $lines[$index];
		}
		return $sorted;
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function stripWikiTokens( $text ) {
		$find = [ '[', '{', '\'', '}', ']' ];
		return trim( str_replace( $find, '', $text ) );
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function stripWikiListTokens( $text ) {
		$find = [ '*', '#', ':' ];
		return trim( str_replace( $find, '', $text ) );
	}

	/**
	 * @param array $lines
	 *
	 * @return string
	 */
	private function makeList( $lines ) {
		$list = [];
		$listtoken = "<li>";
		$endlisttoken = "</li>";

		$attribs = [];
		if ( $this->style !== "" ) {
			$attribs['style'] = $this->style;
		}

		switch ( $this->type ) {
			case ( "ul" ):
				$starttoken = Html::openElement( 'ul', $attribs );
				$endtoken = "</ul>";
				break;
			case ( "ol" ):
				if ( $this->start !== null ) {
					$attribs['start'] = $this->start;
				}
				$starttoken = Html::openElement( 'ol', $attribs );
				$endtoken = "</ol>";
				break;
			case ( "dl" ):
				$starttoken = Html::openElement( 'dl', $attribs );
				$endtoken = "</dl>";
				$listtoken = "<dd>";
				$endlisttoken = "";
				break;
			case ( "br" ):
				$starttoken = "";
				$endtoken = "";
				$listtoken = "";
				$endlisttoken = "";
				$this->separator = "<br />";
				break;
			case ( "inline" ):
				$starttoken = "";
				$endtoken = "";
				$listtoken = "";
				$endlisttoken = "";
				break;
			default:
				$starttoken = Html::openElement( 'ul', $attribs );
				$endtoken = "</ul>";
				break;
		}

		foreach ( $lines as $line ) {
			if ( strlen( $line ) > 0 ) {
				$list[] = "$listtoken" . $this->stripWikiListTokens( $line ) . "$endlisttoken";
			}
		}

		if ( $this->type == "ul" || $this->type == "ol" || $this->type == "dl" ) {
			array_unshift( $list, $starttoken );
			array_push( $list, $endtoken );
		}

		return $this->title . implode( $this->separator, $list );
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function parse( $text ) {
		// recursiveTagParse(), not Parser::parse(): re-entering a full parse from
		// inside a tag hook can corrupt the state of the parse already in progress.
		// The tag hook already receives the frame, which this class used to discard.
		return $this->parser->recursiveTagParse( $text, $this->frame );
	}
}
