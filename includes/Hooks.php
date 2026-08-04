<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;

class Hooks implements
	ParserFirstCallInitHook,
	ParserOptionsRegisterHook
{

	/**
	 * Cache-key fragment shared by all anonymous readers.
	 *
	 * Not a valid username, so it cannot collide with a registered account.
	 */
	private const ANON_CACHE_KEY = '#anon';

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'jsbutton', [ DDInsert::class, 'jsButton' ] );
		$parser->setHook( 'ddselect', [ DDInsert::class, 'ddISelect' ] );
		$parser->setHook( 'ddvalue', [ DDInsert::class, 'ddIValue' ] );
		$parser->setHook( 'ddbutton', [ DDInsert::class, 'ddIButton' ] );
		$parser->setHook( 'sort2', [ Sort2::class, 'sgPackRenderSort' ] );
		$parser->setHook( 'audioplay', [ InlineAudio::class, 'renderTag' ] );
		$parser->setHook( 'useredit', [ UserStatistics::class, 'renderUserEdit' ] );
		$parser->setHook( 'usercreate', [ UserStatistics::class, 'renderUserCreate' ] );
		$parser->setHook( 'useredittopten', [ UserStatistics::class, 'renderTopEditors' ] );
		$parser->setHook( 'usereditfirst', [ UserStatistics::class, 'renderFirstEdit' ] );
		$parser->setHook( 'usereditlast', [ UserStatistics::class, 'renderLastEdit' ] );
		$parser->setFunctionHook( 'carray', [ CacheArray::class, 'sgPackCacheArray' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'keys', [ CacheArray::class, 'sgPackKeys' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'trim', [ ParserAdds::class, 'sgPackTrim' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'tocmod', [ ParserAdds::class, 'sgPackTOCMod' ] );
		$parser->setFunctionHook( 'userinfo', [ ParserAdds::class, 'sgPackUserInfo' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'recursiv', [ ParserAdds::class, 'sgPackRecursive' ] );
		$parser->setFunctionHook( 'in', [ ParserAdds::class, 'sgPackIn' ] );
		$parser->setFunctionHook( 'link', [ ParserAdds::class, 'sgPackLink' ] );
	}

	/**
	 * Register the user-dependent option {{userinfo}} varies on.
	 *
	 * Declaring it here and reading it in the parser function is the supported way
	 * to make a parse user-specific: the cache key gains the user's name, so each
	 * user gets their own cache entry. The alternative the function used before,
	 * ParserOutput::updateCacheExpiry( 0 ), removed every page using {{userinfo}}
	 * from the parser cache altogether.
	 *
	 * Anonymous readers deliberately collapse to one shared key. Their name is
	 * their IP address, so keying on it would give every distinct IP its own
	 * parser-cache entry. The fields that actually differ between anonymous
	 * readers are handled in ParserAdds::sgPackUserInfo(), which opts those
	 * individual parses out of the cache instead.
	 *
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 */
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
		$defaults[ParserAdds::USER_PARSER_OPTION] = null;
		$inCacheKey[ParserAdds::USER_PARSER_OPTION] = true;
		$lazyLoad[ParserAdds::USER_PARSER_OPTION] = static function ( ParserOptions $options ) {
			$user = $options->getUserIdentity();
			if ( !$user->isRegistered() ) {
				return self::ANON_CACHE_KEY;
			}

			// Group membership belongs in the key, not just the name.
			// {{userinfo:group}} and {{userinfo:groups}} change what they render when
			// a user is promoted or demoted, and a name-only key left the stale entry
			// in place until something else re-parsed the page — so a new member of a
			// group kept seeing the old output. Sorted, so an ordering change in the
			// group list does not invalidate every entry for nothing.
			//
			// getUserGroups(), matching ParserAdds: effective groups would add
			// implicit ones such as `autoconfirmed`, churning keys on transitions the
			// parser functions never report.
			$groups = MediaWikiServices::getInstance()
				->getUserGroupManager()
				->getUserGroups( $user );
			sort( $groups );

			return $user->getName() . '|' . implode( ',', $groups );
		};
	}
}
