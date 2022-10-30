<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use ContentHandler;
use MediaWiki\Revision\SlotRecord;
use Title;
use WikiPage;

class CacheArray {

	private static $cache = [];
	private static $keyDelimiter = '_';

	// Combine keys
	public static function sgPackKeys() {
		// Get the parser parameter
		$param = func_get_args();

		// Get the parts for the key
		$key = '';
		while ( $value = next( $param ) ) {
			// Get key-modifier(s) m:key
			$mod = explode( ':', $value, 2 );

			// If count(mod[]) == 2 means we also have modifier
			if ( count( $mod ) == 2 ) {
				$value = $mod[1];
				if ( strpos( $mod[0], 'u' ) !== false ) { // uppercase
					$value = strtoupper( $value );
				}
				if ( strpos( $mod[0], 'l' ) !== false ) { // lowercase
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
		}
		return $key;
	}

	// CacheArray main part
	public static function sgPackCacheArray() {
		// Minimum parser, cachenumber and action are needed
		if ( func_num_args() < 3 ) {
			return [ '', 'noparse' => true ];
		}

		// Get the parser parameter
		$param = func_get_args();

		// Get the first two wiki-parameters (chachenumber, action)
		$cnumber = trim( next( $param ) );
		$action = strtolower( trim( next( $param ) ) );

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

				// If carray is already set do not read it again (cache!)
				if ( !isset( self::$cache[$cnumber] ) ) {
					$wp = new WikiPage( Title::newFromText( $file ) );
					$revisionRecord = $wp->getRevisionRecord();
					$text = $revisionRecord->getContent( SlotRecord::MAIN );
					if ( $text ) {
						$content = ContentHandler::getContentText( $text );
						$cont = explode( '|', $content );
						foreach ( $cont as $line ) {
							$sp = explode( '=', $line, 2 );
							if ( count( $sp ) == 2 ) {
								self::$cache[$cnumber][trim( $sp[0] )] = trim( $sp[1] );
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
				if ( isset( self::$cache[$cnumber][$key] ) ) {
					$output = self::$cache[$cnumber][$key];
				} else {
					if ( isset( self::$cache[$cnumber]['#default'] ) ) {
						$output = str_replace( '{{K}}', $key, self::$cache[$cnumber]['#default'] );
					}
				}
				break;
			case 'w': // Only create new carray
			case 'write':
			case 'rw': // Write new carray and read one value
			case 'readwrite':
				// Read key (only if readwrite)
				if ( ( $action === 'rw' ) || ( $action === 'readwrite' ) ) {
					$key = trim( next( $param ) );
				}
				// If carray is already set do not read it again (cache!)
				if ( !isset( self::$cache[$cnumber] ) ) {
					// Read the keys and values and save in carray
					while ( $values = next( $param ) ) {
						$sp = explode( '=', $values, 2 );
						if ( count( $sp ) == 2 ) {
							self::$cache[$cnumber][trim( $sp[0] )] = trim( $sp[1] );
						}
					}
				}
				// Leave switch (only if write)
				if ( ( $action === 'w' ) || ( $action === 'write' ) ) {
					break;
				}
			case 'r': // Read value out of carray
			case 'read':
				// Read key, if not already set by action readwrite
				if ( !isset( $key ) ) {
					$key = trim( next( $param ) );
				}
				// Read cache, if no value, look for default
				if ( isset( self::$cache[$cnumber][$key] ) ) {
					$output = self::$cache[$cnumber][$key];
				} else {
					if ( isset( self::$cache[$cnumber]['#default'] ) ) {
						$output = str_replace( '{{K}}', $key, self::$cache[$cnumber]['#default'] );
					}
				}
				break;
			case 'd': // Delete carray
			case 'delete':
				unset( self::$cache[$cnumber] );
				break;
			case 'c': // Count elements in carray
			case 'count':
				$output = count( self::$cache[$cnumber] );
				break;
			case 'u': // Test if cache is used
			case 'used':
				// If carray is used give size
				if ( isset( self::$cache[$cnumber] ) ) {
					$output = count( self::$cache[$cnumber] );
				}
				break;
		}
		return [ $output, 'noparse' => false ];
	}
}
