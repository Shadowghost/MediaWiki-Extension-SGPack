<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;

class Hooks implements
    ParserFirstCallInitHook
{
    public function onParserFirstCallInit($parser): void
    {
        $parser->setHook('jsbutton', [DDInsert::class, 'JSButton']);
        $parser->setHook('ddselect', [DDInsert::class, 'ddISelect']);
        $parser->setHook('ddvalue', [DDInsert::class, 'ddIValue']);
        $parser->setHook('ddbutton', [DDInsert::class, 'ddIButton']);
        $parser->setFunctionHook('carray', [CacheArray::class, 'sgPackCacheArray'], Parser::SFH_NO_HASH);
        $parser->setFunctionHook('keys', [CacheArray::class, 'sgPackKeys'], Parser::SFH_NO_HASH);
        $parser->setFunctionHook('trim', [ParserAdds::class, 'sgPackTrim'], Parser::SFH_NO_HASH);
        $parser->setFunctionHook('tocmod', [ParserAdds::class, 'sgTOCMod']);
        $parser->setFunctionHook('userinfo', [ParserAdds::class, 'sgPackUserInfo'], Parser::SFH_NO_HASH);
        $parser->setFunctionHook('recursiv', [ParserAdds::class, 'sgPackRecursive']);
        $parser->setFunctionHook('in', [ParserAdds::class, 'sgPackIn']);
        $parser->setFunctionHook('link', [ParserAdds::class, 'sgPackLink']);
        $parser->setFunctionHook('vplayer', [VideoPlayer::class, 'VideoPlayer']);
    }
}
