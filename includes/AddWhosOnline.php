<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;

class AddWhosOnline {
	/**
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 *
	 * @return true
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, array &$links ) {
		$title = $sktemplate->getTitle();
		// Title of the WhosOnline specialpage
		$sp = Title::makeTitle( NS_SPECIAL, 'WhosOnline' );
		// Be sure we are not on the specialpage
		if ($title->getNamespace() != NS_SPECIAL || SpecialPage::getTitleFor( 'WhosOnline', false )->getText() != $title->getText()) {
			$usermenu = $links['user-menu'];
			$a['online'] = [
				'class' => '',
				'href' => $sp->getLocalURL(),
				'text' => wfMessage( 'addwhosonline-pmenu' )->text()
			];
			// Place new item on second last position
			$links['user-menu'] = array_slice($usermenu, 0, count( $usermenu ) - 1, true) + $a + array_slice($usermenu, -1, true);
		}

		return true;
	}

	/**
	 * @param User &$user
	 *
	 * @return true
	 */
	public function onUserLogout( &$user ) {
		$dbProvider = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$dbw = $dbProvider->getPrimaryDatabase();
		$dbw->delete('online', [ 'userid = ' . $user->mId ], __METHOD__ );

		return true;
	}
}
