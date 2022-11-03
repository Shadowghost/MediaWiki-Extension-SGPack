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
	public function onBeforePageDisplay( $out, $skin ): void {
		global $wgSGHTMLImageTop, $wgSGHTMLImageEdit;

		// Replace correspoding elements with jump-to-top and edit images
		$suchen = [
			'<h2><span class="mw-headline"',
			'>' . wfMessage( 'edit' ) . '</a><span class="mw-editsection-divider">',
			'>' . wfMessage( 'edit' ) . '</a>',
			'[ <a href=',
			'<span class="mw-editsection-bracket"> ]</small></small></span>',
			'<span class="mw-editsection-bracket">[</span>',
			'<span class="mw-editsection-bracket"> | </span>',
			'<span class="mw-editsection-bracket">]</span>',
			wfMessage( 'visualeditor-ca-editsource-section' )
		];
		$ersatz = [
			'<h2><a href="javascript:window.scrollTo(0,0);" title="' . wfMessage( 'sghtml-top' ) . '" style="vertical-align: top; float: right;"><img src="' . $wgSGHTMLImageTop . '" alt="^" /></a><span class="mw-headline"',
			'><img src="' . $wgSGHTMLImageEdit . '" alt="[' . wfMessage( 'edit' ) . ']" style="vertical-align:top; margin-top:10px;" /></a><span class="mw-editsection-bracket">',
			'><img src="' . $wgSGHTMLImageEdit . '" alt="[' . wfMessage( 'edit' ) . ']" style="vertical-align:top; margin-top:10px;" /></a><span class="mw-editsection-bracket">',
			'<a href=',
			'</small></small>',
			''
		];
		$out->mBodytext = str_replace( $suchen, $ersatz, $out->mBodytext );

		// Load SGPack specific JS and CSS
		if (
			$out->getTitle()->isSpecial( 'Upload' ) || in_array( $out->getActionName(), [ 'edit', 'submit' ] )
		) {
			$out->addModules( 'ext.sgPack' );
			$out->addModuleStyles( 'ext.sgPack.styles' );
		}
	}
}
