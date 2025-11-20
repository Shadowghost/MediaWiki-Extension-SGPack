<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\BeforePageDisplayHook;

class SGHTML implements
	BeforePageDisplayHook
{
	/**
	 * @param OutPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay($out, $skin): void
	{
		global $wgSGHTMLImageTop, $wgSGHTMLImageEdit;

		// Replace correspoding elements with jump-to-top and edit images
		$jumpToTop = '<a href="javascript:window.scrollTo(0,0);" title="' . wfMessage('sghtml-top') . '" style="vertical-align: top; float: right;"><img src="' . $wgSGHTMLImageTop . '" alt="^" /></a>';
		$editIcon = '<img src="' . $wgSGHTMLImageEdit . '" alt="[' . wfMessage('edit') . ']" style="vertical-align:text-bottom;" />';
		$suchen = [
			'<h2><span class="mw-headline"',
			'<h3><span class="mw-headline"',
			'<h4><span class="mw-headline"',
			'<h5><span class="mw-headline"',
			'<h6><span class="mw-headline"',
			'<span>' . wfMessage('edit') . '</span></a>',
			'>' . wfMessage('edit') . '</a><span class="mw-editsection-divider">',
			'>' . wfMessage('edit') . '</a>',
			'[ <a href=',
			'<span class="mw-editsection-bracket"> ]</small></small></span>',
			'<span style="white-space:nowrap">[ ',
			'</span> ]</span>',
			'<span class="mw-editsection-bracket">[</span>',
			'<span class="mw-editsection-bracket"> | </span>',
			'<span class="mw-editsection-bracket">]</span>',
			wfMessage('visualeditor-ca-editsource-section')
		];
		$ersatz = [
			'<h2>' . $jumpToTop . '<span class="mw-headline"',
			'<h3>' . $jumpToTop . '<span class="mw-headline"',
			'<h4>' . $jumpToTop . '<span class="mw-headline"',
			'<h5>' . $jumpToTop . '<span class="mw-headline"',
			'<h6>' . $jumpToTop . '<span class="mw-headline"',
			$editIcon . '</a>',
			'>' . $editIcon . '</a><span class="mw-editsection-bracket">',
			'>' . $editIcon . '</a><span class="mw-editsection-bracket">',
			'<a href=',
			'</small></small>',
			'<span style="white-space:nowrap">',
			'</span></span>'
		];
		$out->mBodytext = str_replace($suchen, $ersatz, $out->mBodytext);

		// Load SGPack specific JS and CSS
		if (
			$out->getTitle()->isSpecial('Upload') || in_array($out->getActionName(), ['edit', 'submit'])
		) {
			$out->addModules('ext.sgPack');
			$out->addModuleStyles('ext.sgPack.styles');
		}
	}
}
