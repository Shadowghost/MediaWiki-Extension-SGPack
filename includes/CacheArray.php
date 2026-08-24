<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

/**
 * Look a key up in a data page, and build the composite keys to look it up with.
 *
 * `{{carray:BUCKET|fr|Data page|KEY}}` reads a page of `| key = value` lines and
 * returns one value, falling back to that page's own `#default` with `{{K}}` replaced
 * by the key that missed. `{{keys:u:a|b}}` joins parts into `A_B`. On this wiki the
 * pair is the episode-title table: `Vorlage:EpName/Zuordnung` holds the codes, and
 * `Vorlage:EpName`, `Vorlage:Ep` and `Vorlage:EpLink` read it - several hundred pages
 * of episode links between them.
 *
 * ## Why the write/read actions are gone
 *
 * This function used to double as a mutable per-parse store: `w`/`write` put values in,
 * `r`/`read` took one out further down the page, plus `rw`, `d`/`delete`, `c`/`count`
 * and `u`/`used`. Those made the result depend on **where in the page the call sat** -
 * a write had to be expanded before the read consuming it. Parsoid does not promise
 * that expansion order, which is the same reason `Extension:Variables` has no Parsoid
 * module and is being taken out of this wiki's templates. Scoping the store to
 * ParserOutput fixed leakage *between* parses; it could not fix an order dependency
 * *inside* one, and nothing can.
 *
 * A sweep of all 20,877 pages found no production caller for any of them - the only
 * page using them was the feature's own documentation, `Benutzer:Rene/SGPack`, which
 * now describes an API that is gone.
 *
 * What is left is a **pure function**: the value depends on the data page and the key,
 * never on call order, so it holds under either parser. Parser functions also need no
 * Parsoid extension module - Parsoid expands them through core's preprocessor - so
 * this needed no port, just the removal of the half that could never have had one. The
 * memoisation stays, because caching a page read is an optimisation, not a semantic.
 */
class CacheArray {

	/**
	 * ParserOutput extension-data key the parsed data pages are memoised under.
	 *
	 * This is a cache and nothing else: the value a lookup returns is decided by the
	 * data page and the key alone, never by what a previous call did. That is what
	 * makes the function safe under Parsoid, which does not promise the expansion
	 * order the removed write/read actions depended on - see the class docblock.
	 *
	 * Scoping it to the ParserOutput ties it to the parse that created it, so nothing
	 * leaks between unrelated parses sharing one PHP process such as job runners and
	 * API batch parses.
	 */
	private const EXT_DATA_KEY = 'sgpack-carray';

	/**
	 * @var string
	 */
	private static $keyDelimiter = '_';

	/**
	 * @return string
	 */
	public static function sgPackKeys() {
		// Get the parser parameter
		$param = func_get_args();

		// Get the parts for the key
		$key = '';
		// Note: a falsy parameter ends the loop, as it always has
		$value = next( $param );
		while ( $value ) {
			// Get key-modifier(s) m:key
			$mod = explode( ':', $value, 2 );

			// If count(mod[]) == 2 means we also have modifier
			if ( count( $mod ) == 2 ) {
				$value = $mod[1];
				// uppercase
				if ( strpos( $mod[0], 'u' ) !== false ) {
					$value = strtoupper( $value );
				}
				// lowercase
				if ( strpos( $mod[0], 'l' ) !== false ) {
					$value = strtolower( $value );
				}
			} else {
				$value = $mod[0];
			}

			// Keys always trim
			$value = trim( $value );

			// Drop empty mw-variables
			$value = preg_replace( '/\{\{\{.*?\}\}\}/', '', $value );

			// If value is not empty add to key
			if ( !empty( $value ) ) {
				if ( !empty( $key ) ) {
					$key .= self::$keyDelimiter;
				}
				$key .= $value;
			}

			$value = next( $param );
		}
		return $key;
	}

	/**
	 * Declared rather than read out of func_get_args() so Phan can type it.
	 *
	 * @param Parser $parser
	 *
	 * @return array
	 */
	public static function sgPackCacheArray( $parser ) {
		// Minimum parser, cachenumber and action are needed
		if ( func_num_args() < 3 ) {
			return [ '', 'noparse' => true ];
		}

		$param = func_get_args();
		$parserOutput = $parser->getOutput();

		// Get the first two wiki-parameters (chachenumber, action)
		$cnumber = trim( next( $param ) );
		$action = strtolower( trim( next( $param ) ) );

		// Per-parse store, see EXT_DATA_KEY
		$cache = $parserOutput->getExtensionData( self::EXT_DATA_KEY ) ?? [];

		// Default output is empty
		$output = '';

		// action
		switch ( $action ) {
			case 'f':
			case 'file':
			case 'fr':
			case 'fileread':
				// Read array out of "file"
				$file = next( $param );

				// An invalid page name or a page with no current revision must not
				// fatal; treat both as an empty data source.
				$dataTitle = Title::newFromText( $file );
				if ( $dataTitle ) {
					$wikiPage = MediaWikiServices::getInstance()
						->getWikiPageFactory()
						->newFromTitle( $dataTitle );

					// Record the data page as a dependency of this parse. Without it
					// editing the data page did not purge the pages reading it, so
					// carray served stale values until something else invalidated them.
					// Registered even when the carray is already populated, so the
					// dependency does not depend on which call happened to read it.
					$parserOutput->addTemplate(
						$dataTitle,
						$wikiPage->getId(),
						$wikiPage->getLatest()
					);

					// If carray is already set do not read it again (cache!)
					if ( !isset( $cache[$cnumber] ) ) {
						$revisionRecord = $wikiPage->getRevisionRecord();
						$content = $revisionRecord ? $revisionRecord->getContent( SlotRecord::MAIN ) : null;
						// ContentHandler::getContentText() was removed in 1.45
						if ( $content instanceof TextContent ) {
							$cont = explode( '|', $content->getText() );
							foreach ( $cont as $line ) {
								$sp = explode( '=', $line, 2 );
								if ( count( $sp ) == 2 ) {
									$cache[$cnumber][trim( $sp[0] )] = trim( $sp[1] );
								}
							}
						}
					}
				}

				// Leave switch (only if file)
				if ( ( $action === 'f' ) || ( $action === 'file' ) ) {
					break;
				}

				// Read key
				$key = trim( next( $param ) );

				// Read cache, if no value, look for default
				if ( isset( $cache[$cnumber][$key] ) ) {
					$output = $cache[$cnumber][$key];
				} elseif ( isset( $cache[$cnumber]['#default'] ) ) {
					$output = str_replace( '{{K}}', $key, $cache[$cnumber]['#default'] );
				}
				break;
		}

		$parserOutput->setExtensionData( self::EXT_DATA_KEY, $cache );

		return [ $output, 'noparse' => false ];
	}
}
