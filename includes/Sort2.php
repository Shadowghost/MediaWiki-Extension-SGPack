<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

class Sort2
{
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
	 * @var string
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
	function __construct(&$parser)
	{
		$this->parser = &$parser;
		$this->order = 'asc';
		$this->type = 'ul';
		$this->separator = "\n";
		$this->casesense = "false";
		$this->style = "";
		$this->start = "";
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
	public static function sgPackRenderSort($input, $args, $parser, $frame)
	{
		$sorter2 = new Sort2($parser);
		$sorter2->loadSettings($args);
		return $sorter2->sortToHtml($input);
	}

	/**
	 * @param array $settings
	 */
	private function loadSettings($settings): void
	{
		if (isset($settings['order'])) {
			$o = strtolower($settings['order']);
			if ($o == 'asc' || $o == 'desc' || $o == 'none') {
				$this->order = $o;
			}
		}
		if (isset($settings['type'])) {
			$c = strtolower($settings['type']);
			if ($c == 'ol' || $c == 'ul' || $c == 'dl' || $c == 'inline' || $c == "br") {
				$this->type = $c;
			}
		}
		if (isset($settings['separator']) and $this->type == "inline") {
			$this->separator = str_ireplace("&sp;", " ", $settings['separator']);
		}
		if (isset($settings['casesense']) and strtolower($settings['casesense']) == "true") {
			$this->casesense = "true";
		}
		if (isset($settings['style']) and $this->allowStyles == true) {
			$this->style = 'style="' . $settings['style'] . '"';
		}
		if (isset($settings['start'])) {
			$this->start = 'start="' . $settings['start'] . '"';
		}
		if (isset($settings['title'])) {
			$this->title = str_ireplace("&sp;", " ", $settings['title']);
		}
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function sortToHtml($text)
	{
		$lines = $this->internalSort($text);
		$list = $this->makeList($lines);
		$html = $this->parse($list);
		return $html;
	}

	/**
	 * @param string $text
	 *
	 * @return array
	 */
	private function internalSort($text)
	{
		$lines = explode("\n", $text);
		$inter = [];
		foreach ($lines as $line) {
			$inter[$line] = $this->stripWikiTokens($line);
		}

		if ($this->order != "none") {
			if ($this->casesense == "true") {
				natsort($inter);
			} else {
				natcasesort($inter);
			}
		}

		if ($this->order == 'desc') {
			$inter = array_reverse($inter, true);
		}

		return array_keys($inter);
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function stripWikiTokens($text)
	{
		$find = ['[', '{', '\'', '}', ']'];
		return trim(str_replace($find, '', $text));
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function stripWikiListTokens($text)
	{
		$find = ['*', '#', ':'];
		return trim(str_replace($find, '', $text));
	}

	/**
	 * @param array $lines
	 *
	 * @return string
	 */
	private function makeList($lines)
	{
		$list = [];
		$listtoken = "<li>";
		$endlisttoken = "</li>";

		switch ($this->type) {
			case ("ul"):
				$starttoken = "<ul $this->style>";
				$endtoken = "</ul>";
				break;
			case ("ol"):
				$starttoken = "<ol {$this->style} {$this->start}>";
				$endtoken = "</ol>";
				break;
			case ("dl"):
				$starttoken = "<dl $this->style>";
				$endtoken = "</dl>";
				$listtoken = "<dd>";
				$endlisttoken = "";
				break;
			case ("br"):
				$starttoken = "";
				$endtoken = "";
				$listtoken = "";
				$endlisttoken = "";
				$this->separator = "<br />";
				break;
			case ("inline"):
				$starttoken = "";
				$endtoken = "";
				$listtoken = "";
				$endlisttoken = "";
				break;
			default:
				$starttoken = "<ul $this->style>";
				$endtoken = "</ul>";
				break;
		}

		foreach ($lines as $line) {
			if (strlen($line) > 0) {
				$list[] = "$listtoken" . $this->stripWikiListTokens($line) . "$endlisttoken";
			}
		}

		if ($this->type == "ul" or $this->type == "ol" or $this->type == "dl") {
			array_unshift($list, $starttoken);
			array_push($list, $endtoken);
		}

		return $this->title . implode($this->separator, $list);
	}

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	private function parse($text)
	{
		$title = &$this->parser->mTitle;
		$options = &$this->parser->mOptions;
		$output = $this->parser->parse($text, $title, $options, true, false);
		return $output->getText();
	}
}
