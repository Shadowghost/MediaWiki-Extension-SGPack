<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use SpecialPage;
use Title;

class AddWhosOnline {

	public function onPersonalUrls( &$personal_urls, $title, $skin ) {
		// Title of the WhosOnline specialpage
		$sp = Title::makeTitle( NS_SPECIAL, 'WhosOnline' );
		if (
			$title->getNamespace() != NS_SPECIAL
			|| SpecialPage::getTitleFor( 'WhosOnline', false )->getText() != $title->getText()
		) {
			// Be sure we are not on the specialpage
			$a['online'] = [ 'text' => wfMessage( 'addwhosonline-pmenu' )->text(), 'href' => $sp->getLocalURL() ];
			// Place new item(s) on second last position
			array_splice( $personal_urls, -1, 0, $a );
		}
		return true;
	}

	public function onUserLogout( &$user ) {
		global $wgDBname;

		$db = wfGetDB( DB_PRIMARY );
		$db->selectDB( $wgDBname );
		$db->delete( 'online', [ 'userid = ' . $user->mId ], __METHOD__ );
		return true;
	}
}
