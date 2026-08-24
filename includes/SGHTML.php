<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;

class SGHTML implements
	BeforePageDisplayHook
{
	/**
	 * Skins that do not get the heading edit icon and the jump-to-top link.
	 *
	 * Minerva Neue is what MobileFrontend switches to on mobile devices, so
	 * naming it here is what excludes the mobile view; Citizen brings its own
	 * heading and section-edit styling that these rules would fight with. Every
	 * other skin still gets them.
	 */
	private const HEADING_ICON_SKIN_DENYLIST = [ 'minerva', 'citizen' ];

	/**
	 * Wrap a URL in a CSS url() value, escaped as a quoted CSS string.
	 *
	 * The icon paths are admin-controlled configuration rather than user input,
	 * but they are still interpolated into a stylesheet, so escape them properly
	 * instead of trusting them — a stray quote would otherwise end the
	 * declaration.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	private static function cssUrl( $url ) {
		return 'url("' . strtr( $url, [
			'\\' => '\\\\',
			'"' => '\\"',
			"\n" => '\\A ',
			"\r" => '',
		] ) . '")';
	}

	/**
	 * Skin is not namespaced in MediaWiki 1.43, hence the leading backslash.
	 *
	 * @param OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}

		// Section edit icons and the jump-to-top links. Both are purely
		// presentational: the edit-icon swap is CSS on .mw-editsection, and the
		// jump-to-top link is inserted by a small ResourceLoader module, which is
		// the only part that needs an element to exist.
		//
		// Skipped on the skins in HEADING_ICON_SKIN_DENYLIST, which style headings
		// in a way these rules would fight with.
		if ( !in_array( $skin->getSkinName(), self::HEADING_ICON_SKIN_DENYLIST, true ) ) {
			$out->addModuleStyles( 'ext.sgPack.sghtml.styles' );
			$out->addModules( 'ext.sgPack.sghtml' );

			// The two icon paths are configurable, so they cannot live in the static
			// stylesheet; everything else about these rules does.
			$config = $out->getConfig();
			$out->addInlineStyle(
				'.mw-sgpack-top{background-image:' . self::cssUrl( $config->get( 'SGPackImageTop' ) ) . '}'
				. '.mw-editsection a{background-image:' . self::cssUrl( $config->get( 'SGPackImageEdit' ) ) . '}'
			);
		}

		// Load SGPack specific JS and CSS
		if (
			$title->isSpecial( 'Upload' ) || in_array( $out->getActionName(), [ 'edit', 'submit' ] )
		) {
			$out->addModules( 'ext.sgPack' );
		}
	}
}
