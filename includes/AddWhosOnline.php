<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\Hook\UserLogoutHook;
use MediaWiki\User\User;

class AddWhosOnline implements
	SkinTemplateNavigation__UniversalHook,
	UserLogoutHook
{
	/**
	 * SkinTemplate is not namespaced in MediaWiki 1.43, hence the leading backslash.
	 *
	 * @param \SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Both the link target and the `online` table belong to the separate
		// WhosOnline extension, so do nothing at all when it is not installed
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WhosOnline' ) ) {
			return;
		}

		if ( !isset( $links['user-menu'] ) ) {
			return;
		}

		$title = $sktemplate->getTitle();
		// Title of the WhosOnline specialpage
		$sp = SpecialPage::getTitleFor( 'WhosOnline' );
		// Be sure we are not on the specialpage
		if ( $title && $title->equals( $sp ) ) {
			return;
		}

		$usermenu = $links['user-menu'];
		$a = [];
		$a['online'] = [
			'class' => '',
			'href' => $sp->getLocalURL(),
			'text' => wfMessage( 'addwhosonline-pmenu' )->text()
		];
		// Place new item on second last position.
		//
		// The trailing `true` is $preserve_keys, so $length has to be passed
		// explicitly as null.
		$links['user-menu'] = array_slice( $usermenu, 0, count( $usermenu ) - 1, true )
			+ $a
			+ array_slice( $usermenu, -1, null, true );
	}

	/**
	 * @param User $user
	 *
	 * @return true
	 */
	public function onUserLogout( $user ) {
		// The `online` table is provided by the WhosOnline extension
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WhosOnline' ) ) {
			return true;
		}

		$dbProvider = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$dbProvider->getPrimaryDatabase()
			->newDeleteQueryBuilder()
			->deleteFrom( 'online' )
			->where( [ 'userid' => $user->getId() ] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}
}
