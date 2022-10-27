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
    private static $ddIBlock = array();

    public static function sgpEncode($text)
    {
        $encoded = '';
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $encoded .= '%' . wordwrap(bin2hex(mb_substr($text, $i, 1)), 2, '%', true);
        }
        return $encoded;
    }

    // JSButton - just for normal use in page
    public static function JSButton($input, $argv, $parser, $frame)
    {

        $param = 'type="button"';
        $param .= isset($argv['name']) ? ' name = "' . $argv['name'] . '"' : ' name = "jsbutton"';
        $param .= isset($argv['id']) ? ' id = "' . $argv['id'] . '"' : '';
        $param .= isset($argv['value']) ? ' value = "' . $argv['value'] . '"' : '';
        $param .= isset($argv['class']) ? ' class = "' . $argv['class'] . '"' : ' class = "jsbutton"';
        $param .= isset($argv['style']) ? ' style = "' . $argv['style'] . '"' : '';
        $param .= isset($argv['click']) ? ' onclick = "' . $argv['click'] . '"' : '';
        $param .= isset($argv['mover']) ? ' onmouseover = "' . $argv['mover'] . '"' : '';
        $param .= isset($argv['mout']) ? ' onmouseout = "' . $argv['mout'] . '"' : '';
        return '<button ' . $param . '>' . $parser->recursiveTagParse($input, $frame) . '</button>';
    }

    // Button
    public static function ddIButton($input, $argv, $parser, $frame)
    {

        // If no show parameter is given use input also as showText
        $show = isset($argv['show']) ? htmlspecialchars($argv['show']) : $input;
        // Get sampleText if given
        $sample = isset($argv['sample']) ? $argv['sample'] : '';
        // Picture
        if (isset($argv['picture'])) {
            $image = MediaWikiServices::getInstance()->getRepoGroup()->findFile($argv['picture']);
            if ($image) {
                $iwidth = $image->getWidth();
                $iheight = $image->getHeight();
                // Test if picture parameter (iwidth, iheight)
                if (isset($argv['iwidth'])) {
                    $iwidth = intval($argv['iwidth']);
                }
                if (isset($argv['iheight'])) {
                    $iheight = intval($argv['iheight']);
                }
                $show = '<img src="' . $image->getURL() . '" width="' . $iwidth . '" height="' . $iheight . '" />';
            }
        }
        $einput = explode('+', $input);    // Split parameter
        $einput[] = '';
        $einput[] = '';    // If to few parameters, fill with ''
        $output = '<a class="ibutton" href="#" onclick="';
        $output .= "mw.sgpack.insert('" . self::sgpEncode($einput[0] . "+" . $einput[1] . "+" . $sample) . "'); ";
        $output .= 'return false;">' . $show . '</a>';
        return $output;
    }

    // <ddselect title="titleText" size="sizeInt" name="nameText">...</ddselect>
    public static function ddISelect($input, $argv, $parser, $frame)
    {
        self::$ddIBlock = array('size' => 1, 'name' => 'DDSelect-' . mt_rand(), 'title' => wfMessage('ddinsert-selecttitle'), 'pwidth' => 0, 'pheight' => 1, 'values' => array());
        if (isset($argv['title'])) {
            self::$ddIBlock['title'] = $argv['title'];
        }
        if (isset($argv['size'])) {
            self::$ddIBlock['size'] = $argv['size'];
        }
        if (isset($argv['name'])) {
            self::$ddIBlock['name'] = $argv['name'];
        }
        $parser->recursiveTagParse($input, $frame);
        return self::ddIOutput();
    }

    // <ddvalue show="showText" sample="sampleText" picture="name">value</ddvalue>
    public static function ddIValue($input, $argv, $paser, $frame)
    {
        // If no show parameter is given use input also as showText
        $show = isset($argv['show']) ? $argv['show'] : $input;
        // Get sampleText if given
        $sample = isset($argv['sample']) ? $argv['sample'] : '';
        // Add + to input if not set - need for javascript-split
        if (strpos($input, "+") === false) {
            $input .= "+";
        }
        // Picture
        $iURL = '';
        if (isset($argv['picture'])) {
            $image = wfFindFile($argv['picture']);
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
        self::$ddIBlock['values'][] = array('value' => $input . '+' . $sample, 'text' => $show, 'image' => $iURL);
        return '';
    }

    // Create Output
    public static function ddIOutput()
    {
        $output = '';
        $output .= '<select size="' . self::$ddIBlock['size'] . '" name="' . self::$ddIBlock['name'] . '"';
        $output .= ' onchange="';
        $output .= 'mw.sgpack.insertSelect(this); this.options.selectedIndex = 0; ';
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

    // Create option line
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
