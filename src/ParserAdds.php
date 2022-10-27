<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use RequestContext;
use Title;
use User;

class ParserAdds
{
    public static function sgPackLink(&$parser, $rel = '', $page = '', $title = '')
    {
        global $wgOut;

        if (empty($rel)) {
            return '<strong class="error">' . wfMessage('parseradds_link_norel') . '</strong>';
        }

        if (empty($page)) {
            return '<strong class="error">' . wfMessage('parseradds_link_nopage') . '</strong>';
        }

        if (empty($title)) {
            $title = $page;
        }

        if (!($pt = Title::newFromText($page))) {
            return '<strong class="error">' . wfMessage('parseradds_link_illegalpage') . '</strong>';
        }

        if ($pt->exists()) {
            $wgOut->addLink(array('rel' => $rel, 'title' => $title, 'href' => $pt->getFullURL()));
        }

        return '';
    }

    /* in - ermittelt ob ein oder mehrere Werte in einer Menge enthalten sind
     * $element: Wert(e) die gesucht werden. Mehrere Werte müssen durch $trenn getrennt werden
     * $menge: Menge von Elementen, getrennt durch $trenn
     * $trenn: Trennzeichen, default = ','
     * $modus: Art der Suche. 'a' - Alle Elemente, 'e' - Ein Element
     * $result: Rückgabe bei Erfolg bzw. Misserfolg durch $trenn getrennt
     * Rückgabe: Gefundene Elemente oder Leer
     */
    public static function sgPackIn(&$parser, $element = '', $menge = '', $trenn = ',', $modus = 'a', $result = '')
    {
        // Parameter prüfen
        if (empty($trenn)) {
            $trenn = ',';
        }

        if (empty($modus)) {
            $modus = 'a';
        }

        // Variablen vorbereiten
        $back = '';

        // Listen in Arrays umwandeln
        $result = explode($trenn, $result);
        $aelement = explode($trenn, $element);
        $amenge = explode($trenn, $menge);

        // Prüfen ob alle Elemente in der Menge
        if ($modus == 'a') {
            $count = count($aelement);
            foreach ($aelement as $wert) {
                if (in_array($wert, $amenge)) {
                    $count -= 1;
                }
            }

            // Alle Elemente gefunden wenn Zähler auf Null
            if ($count == 0) {
                $back = $element;
            }
        }

        // Prüfen ob ein Element in der Menge
        if ($modus == 'e' or $modus == 's') {
            foreach ($aelement as $wert) {
                if (in_array($wert, $amenge)) {
                    $back .= (empty($back) ? '' : $trenn) . $wert;
                }
            }
        }

        // Prüfen ob spezielle Rückgabe erforderlich
        if (empty($back)) {
            if (isset($result[1])) {
                $back = $result[1];
            }
        } else {
            if (!empty($result[0])) {
                $back = $result[0];
            }
        }

        return array($back, 'noparse' => true);
    }

    // trim
    public static function sgPackTrim(&$parser, $inStr = '')
    {
        return array(trim($inStr), 'noparse' => true);
    }

    public static function sgTOCMod(&$parser, $inPara = '', $default = 'set')
    {
        $parser->getOutput()->updateCacheExpiry(0);
        $back = '';
        if (empty($inPara)) {
            $inPara = $default;
        }

        $arPara = explode(',', $inPara);
        foreach ($arPara as $para) {
            switch (strtolower($para)) {
                case 'no':
                    $back .= '__NOTOC__';
                    break;
                case 'set':
                    $back .= '__TOC__';
                    break;
                case 'hide':
                    // $wgOut->addInlineScript("function tocHide() { if(document.getElementById('toc')) { var toc = document.getElementById('toc').getElementsByTagName('ul')[0]; var toggleLink = document.getElementById('togglelink'); if(toc.style.display != 'none') { changeText(toggleLink, tocShowText); toc.style.display = 'none'; }}} addOnloadHook(tocHide);");
                    /*$wgOut->addInlineScript("$(function() {
                      var $tocList = $('#toc ul:first');
                      if($tocList.length()) {
                        if(!$tocList.is(':hidden')) {
                          util.toggleToc($('#togglelink'));
                        }
                      }
                    });");*/
                    break;
                case 'show':
                    // $wgOut->addInlineScript("function tocShow() { if(document.getElementById('toc')) { var toc = document.getElementById('toc').getElementsByTagName('ul')[0]; var toggleLink = document.getElementById('togglelink'); if(toc.style.display != 'block') { changeText(toggleLink, tocHideText); toc.style.display = 'block'; }}} addOnloadHook(tocShow);");
                    break;
                case 'force':
                    $back .= '__FORCETOC__';
                    break;
            }
        }

        return array($back, 'found' => true);
    }

    public static function sgPackUserInfo(&$parser, $inStr = 'name', $inPara = '')
    {
        global $wgDBname;

        $parser->getOutput()->updateCacheExpiry(0);
        $back = '';
        $user = RequestContext::getMain()->getUser();
        switch (strtolower($inStr)) {
            case 'name':
                $back = $user->getName();
                break;
            case 'id':
                $back = $user->getId();
                break;
            case 'realname':
                $back = $user->getRealName();
                break;
            case 'email':
                if (!empty($inPara)) {
                    $user = User::NewFromName($inPara);
                    if ($user === false) {
                        return '<strong class="error">' . wfMessage('parseradds_userinfo_illegal') . '</strong>';
                    }
                }
                $back = $user->mEmail;
                break;
            case 'skin':
                $back = $user->getSkin()->skinname;
                break;
            case 'home':
                if (!empty($inPara)) {
                    $user = User::NewFromName($inPara);
                    if ($user === false) {
                        return '<strong class="error">' . wfMessage('parseradds_userinfo_illegal') . '</strong>';
                    }
                }
                $back = '[[' . $user->getUserPage()->getFullText() . ']]';
                break;
            case 'talk':
                if (!empty($inPara)) {
                    $user = User::NewFromName($inPara);
                    if ($user === false) {
                        return '<strong class="error">' . wfMessage('parseradds_userinfo_illegal') . '</strong>';
                    }
                }
                $back = '[[' . $user->getUserPage()->getTalkNsText() . $user->mName . ']]';
                break;
            case 'groups':
                $back = implode(",", $user->getGroups());
                break;
            case 'group':
                $back = in_array($inPara, $user->getGroups()) ? $inPara : '';
                break;
            case 'browser':
                $back = $_SERVER['HTTP_USER_AGENT'];
                if (!empty($inPara)) {
                    if (false === strpos($back, $inPara)) {
                        $back = '';
                    } else {
                        $back = $inPara;
                    }
                }
                break;
            case 'online':
                if (function_exists('wfWhosOnline_update_data')) {
                    $db = wfGetDB(DB_SLAVE);
                    $db->selectDB($wgDBname);
                    $res = $db->selectField('online', array('count(*)'), array('username' => $inPara));
                    $back = $res == '1' ? 'online' : 'offline';
                } else {
                    $back = 'unknown';
                }
                break;
        }

        return array($back, 'noparse' => true);
    }

    /* Vorlage mehrfach aufrufen
     * Alle Ausdrücke in () werden an die Vorlage "calltemplate" übergeben.
     * Weitere Parameter "callparameter" werden ebenfalls übergeben.
     * Ausdrücke in [[]] werden nicht beachtet
    */
    public static function sgPackRecursive()
    {
        $p = func_get_args();
        $parser = $p[0];
        $calltemplate = isset($p[1]) ? $p[1] : '';
        $parstext = isset($p[2]) ? $p[2] : '';
        // Weitere Übergabeparameter vorbereiten
        $callparameter = '';
        $i = 3;
        while (isset($p[$i])) {
            $callparameter .= '|' . $p[$i];
            $i++;
        }

        $output = '';

        // Text aufspalten in geklammerte und nicht geklammerte Teile, Elemente in [[]] werden nicht beachtet
        $split = preg_split('/(\[\[.*?\]\]|\(.*?\))/i', $parstext, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        // Alle Elemente parsen
        foreach ($split as $para) {
            if ($para[0] == '(' && $para[strlen($para) - 1] == ')') {
                $sub = substr($para, 1, strlen($para) - 2); // "Ausklammern"
            } else {
                $sub = $para;
            }

            $ask = '{{' . $calltemplate . '|' . $sub . $callparameter . '}}';  // Erzeuge Anfrage
            $result = $parser->recursiveTagParse($ask);

            // Wenn Ergebniss == leer oder == Anfrage dann kennt die Vorlage den Parameter nicht
            if (empty($result) || $result == trim($sub)) {  // Leerzeichen werden nicht zurückgegeben
                $output .= $para;  // Eingabe 1:1 in Ausgabe einfügen
            } else {
                $output .= $ask;  // Ersetze Ausdruck durch Vorlage
            }
        }

        return array($output, 'noparse' => false);
    }
}
