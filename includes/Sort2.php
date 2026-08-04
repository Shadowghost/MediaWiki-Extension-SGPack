<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Sanitizer;

class Sort2 {
	/**
	 * @var Parser
	 */
	var $parser;

	/**
	 * @var string
	 */
	var $order;

	/**
	 * @var string
	 */
	var $type;

	/**
	 * @var string
	 */
	var $separator;

	/**
	 * @var string
	 */
	var $casesense;

	/**
	 * @var string
	 */
	var $style;

	/**
	 * @var int|null
	 */
	var $start;

	/**
	 * @var string
	 */
	var $title;

	/**
	 * @var string
	 */
	var $allowStyles;

	/**
	 * @param Parser &$parser
	 */
	function __construct( &$parser ) {
		$this->parser = &$parser;
		$this->order = 'asc';
		$this->type = 'ul';
		$this->separator = "\n";
		$this->casesense = "false";
		$this->style = "";
		$this->start = null;
		$this->title = "";
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
		$sorter2 = new Sort2( $parser );
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
		$inter = [];
		foreach ( $lines as $line ) {
			$inter[$line] = $this->stripWikiTokens( $line );
		}

		if ( $this->order != "none" ) {
			if ( $this->casesense == "true" ) {
				natsort( $inter );
			} else {
				natcasesort( $inter );
			}
		}

		if ( $this->order == 'desc' ) {
			$inter = array_reverse( $inter, true );
		}

		return array_keys( $inter );
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

		if ( $this->type == "ul" or $this->type == "ol" or $this->type == "dl" ) {
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
		$title = &$this->parser->getTitle();
		$options = &$this->parser->getOptions();
		$output = $this->parser->parse( $text, $title, $options, true, false );
		return $output->getText();
	}
}
