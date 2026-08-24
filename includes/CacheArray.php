<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class CacheArray {

	/**
	 * ParserOutput extension-data key the carrays are stored under.
	 *
	 * Scoping the store to the ParserOutput ties it to the parse that created it,
	 * so carrays cannot leak between unrelated parses sharing one PHP process
	 * such as job runners and API batch parses.
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
	 * @return array
	 */
	public static function sgPackCacheArray() {
		// Minimum parser, cachenumber and action are needed
		if ( func_num_args() < 3 ) {
			return [ '', 'noparse' => true ];
		}

		// Get the parser parameter
		$param = func_get_args();
		$parser = $param[0];
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
			// w/write only create a new carray; rw/readwrite also read one value
			case 'w':
			case 'write':
			case 'rw':
			case 'readwrite':
				// Read key (only if readwrite)
				if ( ( $action === 'rw' ) || ( $action === 'readwrite' ) ) {
					$key = trim( next( $param ) );
				}
				// If carray is already set do not read it again (cache!)
				if ( !isset( $cache[$cnumber] ) ) {
					// Read the keys and values and save in carray
					// Note: a falsy parameter ends the loop, as it always has
					$values = next( $param );
					while ( $values ) {
						$sp = explode( '=', $values, 2 );
						if ( count( $sp ) == 2 ) {
							$cache[$cnumber][trim( $sp[0] )] = trim( $sp[1] );
						}
						$values = next( $param );
					}
				}
				// Leave switch (only if write)
				if ( ( $action === 'w' ) || ( $action === 'write' ) ) {
					break;
				}
				// Fall through: rw/readwrite writes the carray and then reads one value
			// Read value out of carray
			case 'r':
			case 'read':
				// Read key, if not already set by action readwrite
				if ( !isset( $key ) ) {
					$key = trim( next( $param ) );
				}
				// Read cache, if no value, look for default
				if ( isset( $cache[$cnumber][$key] ) ) {
					$output = $cache[$cnumber][$key];
				} elseif ( isset( $cache[$cnumber]['#default'] ) ) {
					$output = str_replace( '{{K}}', $key, $cache[$cnumber]['#default'] );
				}
				break;
			// Delete carray
			case 'd':
			case 'delete':
				unset( $cache[$cnumber] );
				break;
			// Count elements in carray
			case 'c':
			case 'count':
				// count( null ) is a TypeError on PHP 8, so an unset carray counts as 0
				$output = isset( $cache[$cnumber] ) ? count( $cache[$cnumber] ) : 0;
				break;
			// Test if cache is used
			case 'u':
			case 'used':
				// If carray is used give size
				if ( isset( $cache[$cnumber] ) ) {
					$output = count( $cache[$cnumber] );
				}
				break;
		}

		$parserOutput->setExtensionData( self::EXT_DATA_KEY, $cache );

		return [ $output, 'noparse' => false ];
	}
}
