<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\BeforePageDisplayHook;
use OutputPage;
use Skin;

class SGHTML implements
    BeforePageDisplayHook
{
    public function onBeforePageDisplay($out, $skin): void
    {
        global $wgSGHTMLImageTop, $wgSGHTMLImageEdit;

        // Jump to Top, Edit-Image
        $suchen = array(
            '<h2><span class="mw-headline"',
            '>' . wfMessage('edit') . '</a><span class="mw-editsection-divider">',
            '>' . wfMessage('edit') . '</a>',
            '[ <a href=',
            '<span class="mw-editsection-bracket"> ]</small></small></span>',
            '<span class="mw-editsection-bracket">[</span>',
            '<span class="mw-editsection-bracket"> | </span>',
            '<span class="mw-editsection-bracket">]</span>',
            wfMessage('visualeditor-ca-editsource-section')
        );
        $ersatz = array(
            '<h2><a href="javascript:window.scrollTo(0,0);" title="' . wfMessage('sghtml-top') . '" style="vertical-align: top; float: right;"><img src="' . $wgSGHTMLImageTop . '" alt="^" /></a><span class="mw-headline"',
            '><img src="' . $wgSGHTMLImageEdit . '" alt="[' . wfMessage('edit') . ']" style="vertical-align:top; margin-top:10px;" /></a><span class="mw-editsection-bracket">',
            '><img src="' . $wgSGHTMLImageEdit . '" alt="[' . wfMessage('edit') . ']" style="vertical-align:top; margin-top:10px;" /></a><span class="mw-editsection-bracket">',
            '<a href=',
            '</small></small>',
            ''
        );
        $out->mBodytext = str_replace($suchen, $ersatz, $out->mBodytext);
    }
}
