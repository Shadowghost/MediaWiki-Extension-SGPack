<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\MediaWikiServices;

class DDInsert
{
	/**
	 * @var array
	 */
	private static $ddIBlock = [];

	/**
	 * @param string $text
	 *
	 * @return string
	 */
	public static function sgpEncode($text)
	{
		$encoded = '';
		$length = mb_strlen($text);
		for ($i = 0; $i < $length; $i++) {
			$encoded .= '%' . wordwrap(bin2hex(mb_substr($text, $i, 1)), 2, '%', true);
		}
		return $encoded;
	}

	/**
	 * JSButton - just for normal use in page
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function JSButton($input, $args, $parser, $frame)
	{
		$param = 'type="button"';
		$param .= isset($args['name']) ? ' name = "' . $args['name'] . '"' : ' name = "jsbutton"';
		$param .= isset($args['id']) ? ' id = "' . $args['id'] . '"' : '';
		$param .= isset($args['value']) ? ' value = "' . $args['value'] . '"' : '';
		$param .= isset($args['class']) ? ' class = "' . $args['class'] . '"' : ' class = "jsbutton"';
		$param .= isset($args['style']) ? ' style = "' . $args['style'] . '"' : '';
		$param .= isset($args['click']) ? ' onclick = "' . $args['click'] . '"' : '';
		$param .= isset($args['mover']) ? ' onmouseover = "' . $args['mover'] . '"' : '';
		$param .= isset($args['mout']) ? ' onmouseout = "' . $args['mout'] . '"' : '';
		return '<button ' . $param . '>' . $parser->recursiveTagParse($input, $frame) . '</button>';
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
	public static function ddIButton($input, $args, $parser, $frame)
	{
		// If no show parameter is given use input also as showText
		$show = isset($args['show']) ? htmlspecialchars($args['show']) : $input;
		// Get sampleText if given
		$sample = isset($args['sample']) ? $args['sample'] : '';
		// Picture
		if (isset($args['picture'])) {
			$image = MediaWikiServices::getInstance()->getRepoGroup()->findFile($args['picture']);
			if ($image) {
				$iwidth = $image->getWidth();
				$iheight = $image->getHeight();
				// Test if picture parameter (iwidth, iheight)
				if (isset($args['iwidth'])) {
					$iwidth = intval($args['iwidth']);
				}
				if (isset($args['iheight'])) {
					$iheight = intval($args['iheight']);
				}
				$show = '<img src="' . $image->getURL() . '" width="' . $iwidth . '" height="' . $iheight . '" />';
			}
		}
		// Split parameter
		$einput = explode('+', $input);
		// If too few parameters, fill with ''
		$einput[] = '';
		$output = '<a class="mw-sgpack-ddinsert-button" href="#" onclick="';
		$output .= "mw.SGPack.insert('" . self::sgpEncode($einput[0] . "+" . $einput[1] . "+" . $sample) . "'); ";
		$output .= 'return false;">' . $show . '</a>';
		return $output;
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
	public static function ddISelect($input, $args, $parser, $frame)
	{
		self::$ddIBlock = ['size' => 1, 'name' => 'DDSelect-' . mt_rand(), 'title' => wfMessage('ddinsert-selecttitle'), 'pwidth' => 0, 'pheight' => 1, 'values' => []];
		if (isset($args['title'])) {
			self::$ddIBlock['title'] = $args['title'];
		}
		if (isset($args['size'])) {
			self::$ddIBlock['size'] = $args['size'];
		}
		if (isset($args['name'])) {
			self::$ddIBlock['name'] = $args['name'];
		}
		$parser->recursiveTagParse($input, $frame);
		return self::ddIOutput();
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
	public static function ddIValue($input, $args, $parser, $frame)
	{
		// If no show parameter is given use input also as showText
		$show = $args['show'] ?? $input;
		// Get sampleText if given
		$sample = $args['sample'] ?? '';
		// Add + to input if not set - need for javascript-split
		if (strpos($input, "+") === false) {
			$input .= "+";
		}
		// Picture
		$iURL = '';
		if (isset($args['picture'])) {
			$image = wfFindFile($args['picture']);
			if ($image) {
				$iURL = $image->getURL();
				$iwidth = $image->getWidth();
				$iheight = $image->getHeight();
				if ($iwidth > (self::$ddIBlock['pwidth'] - 5)) {
					self::$ddIBlock['pwidth'] = $iwidth + 5;
				}
				if ($iheight > (self::$ddIBlock['pheight'])) {
					self::$ddIBlock['pheight'] = $iheight;
				}
			}
		}
		// Save parameter to global array
		self::$ddIBlock['values'][] = ['value' => $input . '+' . $sample, 'text' => $show, 'image' => $iURL];
		return '';
	}

	/**
	 * Create Output
	 *
	 * @return string
	 */
	public static function ddIOutput()
	{
		$output = '';
		$output .= '<select size="' . self::$ddIBlock['size'] . '" name="' . self::$ddIBlock['name'] . '"';
		$output .= ' onchange="';
		$output .= 'mw.SGPack.insertSelect(this); this.options.selectedIndex = 0; ';
		$output .= 'return false;">';
		$output .= '<option value="++" selected="selected">';
		$output .= self::$ddIBlock['title'];
		$output .= '</option>';
		foreach (self::$ddIBlock['values'] as $values) {
			$output .= self::ddILine($values['text'], $values['value'], $values['image']);
		}
		$output .= "</select>";
		return $output;
	}

	/**
	 * Create option line
	 *
	 * @param string $text
	 * @param array $value
	 * @param string $image
	 *
	 * @return string
	 */
	public static function ddILine($text, $value, $image)
	{
		if (self::$ddIBlock['pwidth'] > 0) {
			if (!empty($image)) {
				$css = 'style="height: ' . self::$ddIBlock['pheight'] . 'px; padding-left: ' . self::$ddIBlock['pwidth'] . 'px; padding-right: 5px; background-repeat: no-repeat; background-image: url(' . $image . ');"';
			} else {
				$css = 'style="padding-left: ' . self::$ddIBlock['pwidth'] . 'px; padding-right: 5px;"';
			}
		} else {
			$css = '';
		}
		return '<option ' . $css . ' value="' . self::sgpEncode($value) . '">' . $text . '</option>' . "\n";
	}
}
