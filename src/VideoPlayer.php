<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

class VideoPlayer
{
    private int $number = 0;    // counter for the player id on the page

    // Special trim, looks also for " and ' characters
    private function bigtrim($str)
    {
        return trim($str, " \t\n\r\0\x0B'\"");
    }

    /**
     * Converts an array of values in form [0] => "name=value" into a real
     * associative array in form [name] => value. If value is not set "name"
     * create [name] => 1, used for parameter without a value
     *
     * @param array string $options
     * @return array $results
     */
    function extractOptions(array $options)
    {
        $results = array();

        foreach ($options as $option) {
            $pair = explode('=', $option, 2);
            if (count($pair) == 2) {
                $name = $this->bigtrim($pair[0]);
                $value = $this->bigtrim($pair[1]);
                $results[$name] = $value;
            } else {
                $name = $this->bigtrim($option);
                $value = 1;
                $results[$name] = $value;
            }
        }
        return $results;
    }

    // Audioplayer
    function AudioPlayer($file, $args, $parser, $frame)
    {
    }

    // Videoplayer
    function VideoPlayer(&$parser)
    {
        global $wgOut, $wgScriptPath, $wgSgpVideoPlayerEngine, $wgSgpVideoPlayerCacheOff;

        $opts = func_get_args();    // get all parameters
        unset($opts[0]);    // remove first parameter (==$parser)
        $options = $this->VideoPlayerParser($opts); // create token array out of parameter array

        // No video or playlist -> error
        if (empty($options['video']["value"]) && empty($options['playlist']["value"])) {
            return array('<strong class="error">' . wfMsgForContent('videoplayer-error') . '</strong>', 'noparse' => true, 'isHTML' => true);
        }
        // create single ID for each player
        $this->number++;
        $id = 'video_player_' . $this->number;
        switch ($wgSgpVideoPlayerEngine) {  // create output for chosen videoplayer
            case 'jwplayer':
                // container with defined css-class (empty if not defined)
                $html = "<div class='video_player {$options['css']['value']}' id='{$id}'>" . wfMsgForContent('videoplayer-load') . "</div>";
                if (empty($options["video"]["value"])) { // no video -> it is a playlist
                    $js = "jwplayer('{$id}').setup({ playlist: [ "; // player call, start playlist
                    $comma = "";
                    foreach ($options["playlist"]["value"] as $key => $value) { // for each video in the playlist create object
                        $js .= $comma . "{ sources: [{ file: '{$value}' }]";  // insert video
                        foreach (array("title", "poster", "description") as $name) {    // more parameters
                            $js .= " ,{$name}: '{$options[$name]['value'][$key]}'";
                        }
                        $image = wfFindFile($options['poster']['value'][$key]); // look for wiki image
                        if ($image) {   // if image found
                            $js .= " ,image: '" . $image->getURL() . "'";
                        }
                        $js .= '}'; // close source object
                        $comma = ',';
                    }
                    $js .= "] ";    // close playlist
                    $js .= " ,listbar: { "; // create listbar object
                    $comma = "";
                    foreach (array("position", "size", "layout") as $name) {
                        $js .= $comma . $name . ": '" . $options["l." . $name]["value"] . "'";
                        $comma = ",";
                    }
                    $js .= "}";     // listbar
                } else {    // single video
                    $js = "jwplayer('{$id}').setup({ file: '{$options['video']['value']}'";    // single player call
                    foreach (array("title", "description") as $name) {    // more parameters
                        $js .= " ,{$name}: '{$options[$name]['value'][0]}'";    // all parameters are defined as list!
                    }
                    $image = wfFindFile($options['poster']['value'][0]); // look for wiki image, defined as list!
                    if ($image) {   // if image found
                        $js .= " ,image: '" . $image->getURL() . "'";
                    }
                }
                // following parameters are the same for playliste and single video
                foreach (array("width", "height", "autostart", "repeat", "mute", "controls", "stretching", "displaytitle") as $name) {
                    $js .= " ,{$name}: '{$options[$name]['value']}'";
                }
                // position of player scripts, needed because of problems with mw-resources loader
                $js .= " ,flashplayer: '" . $wgScriptPath . "/extensions/SGPack/jwplayer/jwplayer.flash.swf'";
                $js .= " ,html5player: '" . $wgScriptPath . "/extensions/SGPack/jwplayer/jwplayer.html5.js'";
                // close player init
                $js .= "});";
                break;
            default:    // no player -> error
                return array('<strong class="error">' . wfMsgForContent('videoplayer-noplayer') . '</strong>', 'noparse' => true, 'isHTML' => true);
        }
        $wgOut->addInlineScript($js);   // javascript for the player
        if ($wgSgpVideoPlayerCacheOff) { // if no cache
            $parser->disableCache();
        }
        return array($html, 'noparse' => true, 'isHTML' => true); // need HTML
    }

    // Videoplayer Parameter Parser
    private function VideoPlayerParser($parameters)
    {
        // Define the typ of the value
        define("vp_int", 1);     // Integer
        define("vp_str", 2);     // String
        define("vp_bool", 4);    // Bool - set value true if parameter is given
        define("vp_bool_false", 8);  // Bool - set value false if parameter is given
        define("vp_list", 1024);  // Comma separated list
        $token = array(
            "video" => array("typ" => vp_str, "value" => ""),
            "playlist" => array("typ" => vp_str | vp_list, "value" => ""),
            "height" => array("typ" => vp_int, "value" => 270),
            "width" => array("typ" => vp_int, "value" => 480),
            "title" => array("typ" => vp_str | vp_list, "value" => ""),
            "poster" => array("typ" => vp_str | vp_list, "value" => ""),
            "description" => array("typ" => vp_str | vp_list, "value" => ""),
            "stretching" => array("typ" => vp_str, "value" => "uniform"),
            "css" => array("typ" => vp_str, "value" => ""),
            "autostart" => array("typ" => vp_bool, "value" => "false"),
            "repeat" => array("typ" => vp_bool, "value" => "false"),
            "mute" => array("typ" => vp_bool, "value" => "false"),
            "controls" => array("typ" => vp_bool_false, "value" => "true"),
            "displaytitle" => array("typ" => vp_bool_false, "value" => "true"),
            "l.position" => array("typ" => vp_str, "value" => "right"),
            "l.size" => array("typ" => vp_int, "value" => "150"),
            "l.layout" => array("typ" => vp_str, "value" => "extended"),

        );

        // split arguments name=value to [name]=>value
        $options = $this->extractOptions($parameters);

        // test every token if set in parameter array
        foreach ($token as $name => $def) {
            if (isset($options[$name])) {   // if parameter is set
                if ($def["typ"] & vp_list) {    // if parameter can be comma list
                    // at the moment we only have vp_str in list
                    $value = explode(",", $options[$name]); // split list
                    foreach ($value as $key => $str) {       // trim all parts of the list
                        $value[$key] = $this->bigtrim($str);
                    }
                } else {    // no list parameters
                    $value = $options[$name];
                    switch ($options["typ"]) {   // create value by typ od the parameter
                        case vp_int:
                            $value = intval($options[$name]);
                            break;
                        case vp_str:
                            $value = $options[$name];
                            break;
                        case vp_bool:
                            $value = "true";
                            break;
                        case vp_bool_false:
                            $value = "false";
                            break;
                    }
                }
                $token[$name]["value"] = $value;
            }
        }
        return $token;
    }
}
