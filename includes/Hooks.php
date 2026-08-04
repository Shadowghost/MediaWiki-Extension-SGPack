<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;

class Hooks implements
	ParserFirstCallInitHook,
	ParserOptionsRegisterHook
{
	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'jsbutton', [ DDInsert::class, 'jsButton' ] );
		$parser->setHook( 'ddselect', [ DDInsert::class, 'ddISelect' ] );
		$parser->setHook( 'ddvalue', [ DDInsert::class, 'ddIValue' ] );
		$parser->setHook( 'ddbutton', [ DDInsert::class, 'ddIButton' ] );
		$parser->setHook( 'sort2', [ Sort2::class, 'sgPackRenderSort' ] );
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
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 */
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
		$defaults[ParserAdds::USER_PARSER_OPTION] = null;
		$inCacheKey[ParserAdds::USER_PARSER_OPTION] = true;
		$lazyLoad[ParserAdds::USER_PARSER_OPTION] = static function ( ParserOptions $options ) {
			return $options->getUserIdentity()->getName();
		};
	}
}
