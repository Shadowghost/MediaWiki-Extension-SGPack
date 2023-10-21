<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use RequestContext;
use Title;
use User;

class ParserAdds {
	/**
	 * @param Parser &$parser
	 * @param string $rel
	 * @param string $page
	 * @param string title
	 *
	 * @return string
	 */
	public static function sgPackLink( &$parser, $rel = '', $page = '', $title = '' ) {
		global $wgOut;

		if ( empty( $rel ) ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_norel' ) . '</strong>';
		}

		if ( empty( $page ) ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_nopage' ) . '</strong>';
		}

		if ( empty( $title ) ) {
			$title = $page;
		}

		if ( !( $pt = Title::newFromText( $page ) ) ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_illegalpage' ) . '</strong>';
		}

		if ( $pt->exists() ) {
			$wgOut->addLink( [ 'rel' => $rel, 'title' => $title, 'href' => $pt->getFullURL() ] );
		}

		return '';
	}

	/**
	 * in - ermittelt ob ein oder mehrere Werte in einer Menge enthalten sind
	 *
	 * @param Parser &$parser
	 * @param string $element Wert(e) die gesucht werden. Mehrere Werte müssen durch $trenn getrennt werden
	 * @param string $menge Menge von Elementen, getrennt durch $trenn
	 * @param string $trenn Trennzeichen, default = ','
	 * @param string $modus Art der Suche. 'a' - Alle Elemente, 'e' - Ein Element
	 * @param string $result Rückgabe bei Erfolg bzw. Misserfolg durch $trenn getrennt
	 *
	 * @return string Gefundene Elemente oder Leer
	 */
	public static function sgPackIn( &$parser, $element = '', $menge = '', $trenn = ',', $modus = 'a', $result = '' ) {
		// Parameter prüfen
		if ( empty( $trenn ) ) {
			$trenn = ',';
		}

		if ( empty( $modus ) ) {
			$modus = 'a';
		}

		// Variablen vorbereiten
		$back = '';

		// Listen in Arrays umwandeln
		$result = explode( $trenn, $result );
		$aelement = explode( $trenn, $element );
		$amenge = explode( $trenn, $menge );

		// Prüfen, ob alle Elemente in der Menge
		if ( $modus == 'a' ) {
			$count = count( $aelement );
			foreach ( $aelement as $wert ) {
				if ( in_array( $wert, $amenge ) ) {
					$count -= 1;
				}
			}

			// Alle Elemente gefunden wenn Zähler auf Null
			if ( $count == 0 ) {
				$back = $element;
			}
		}

		// Prüfen, ob ein Element in der Menge
		if ( $modus == 'e' or $modus == 's' ) {
			foreach ( $aelement as $wert ) {
				if ( in_array( $wert, $amenge ) ) {
					$back .= ( empty( $back ) ? '' : $trenn ) . $wert;
				}
			}
		}

		// Prüfen, ob spezielle Rückgabe erforderlich
		if ( empty( $back ) ) {
			if ( isset( $result[1] ) ) {
				$back = $result[1];
			}
		} else {
			if ( !empty( $result[0] ) ) {
				$back = $result[0];
			}
		}

		return [ $back, 'noparse' => true ];
	}

	/**
	 * @param Parser &$parser
	 * @param string $text
	 *
	 * @return string
	 */
	public static function sgPackTrim( &$parser, $text = '' ) {
		return [ trim( $text ), 'noparse' => true ];
	}

	/**
	 * @param Parser &$parser
	 * @param string $arg
	 * @param string $text
	 *
	 * @return array
	 */
	public static function sgPackTOCMod( &$parser, $arg = '', $default = 'set' ) {
		$parser->getOutput()->updateCacheExpiry( 0 );
		$back = '';
		if ( empty( $arg ) ) {
			$arg = $default;
		}

		$arPara = explode( ',', $arg );
		foreach ( $arPara as $para ) {
			switch ( strtolower( $para ) ) {
				case 'no':
					$back .= '__NOTOC__';
					break;
				case 'set':
					$back .= '__TOC__';
					break;
				case 'hide':
					// $wgOut->addInlineScript("function tocHide() { if(document.getElementById('toc')) { var toc = document.getElementById('toc').getElementsByTagName('ul')[0]; var toggleLink = document.getElementById('togglelink'); if(toc.style.display != 'none') { changeText(toggleLink, tocShowText); toc.style.display = 'none'; }}} addOnloadHook(tocHide);");
					/*$wgOut->addInlineScript("$(function() {
					  var $tocList = $('#toc ul:first');
					  if($tocList.length()) {
						if(!$tocList.is(':hidden')) {
						  util.toggleToc($('#togglelink'));
						}
					  }
					});");*/
					break;
				case 'show':
					// $wgOut->addInlineScript("function tocShow() { if(document.getElementById('toc')) { var toc = document.getElementById('toc').getElementsByTagName('ul')[0]; var toggleLink = document.getElementById('togglelink'); if(toc.style.display != 'block') { changeText(toggleLink, tocHideText); toc.style.display = 'block'; }}} addOnloadHook(tocShow);");
					break;
				case 'force':
					$back .= '__FORCETOC__';
					break;
			}
		}

		return [ $back, 'found' => true ];
	}

	/**
	 * @param Parser &$parser
	 * @param string $arg
	 * @param string $param
	 *
	 * @return array
	 */
	public static function sgPackUserInfo( &$parser, $arg = 'name', $param = '' ) {
		$parser->getOutput()->updateCacheExpiry( 0 );
		$back = '';
		$user = RequestContext::getMain()->getUser();
		switch ( strtolower( $arg ) ) {
			case 'name':
				$back = $user->getName();
				break;
			case 'id':
				$back = $user->getId();
				break;
			case 'realname':
				$back = $user->getRealName();
				break;
			case 'email':
				if ( !empty( $param ) ) {
					$user = User::NewFromName( $param );
					if ( $user === false ) {
						return '<strong class="error">' . wfMessage( 'parseradds_userinfo_illegal' ) . '</strong>';
					}
				}
				$back = $user->mEmail;
				break;
			case 'skin':
				$back = $user->getSkin()->skinname;
				break;
			case 'home':
				if ( !empty( $param ) ) {
					$user = User::NewFromName( $param );
					if ( $user === false ) {
						return '<strong class="error">' . wfMessage( 'parseradds_userinfo_illegal' ) . '</strong>';
					}
				}
				$back = '[[' . $user->getUserPage()->getFullText() . ']]';
				break;
			case 'talk':
				if ( !empty( $param ) ) {
					$user = User::NewFromName( $param );
					if ( $user === false ) {
						return '<strong class="error">' . wfMessage( 'parseradds_userinfo_illegal' ) . '</strong>';
					}
				}
				$back = '[[' . $user->getUserPage()->getTalkNsText() . $user->mName . ']]';
				break;
			case 'groups':
				$back = implode( ",", $user->getGroups() );
				break;
			case 'group':
				$back = in_array( $param, $user->getGroups() ) ? $param : '';
				break;
			case 'browser':
				$back = $_SERVER['HTTP_USER_AGENT'];
				if ( !empty( $param ) ) {
					if ( false === strpos( $back, $param ) ) {
						$back = '';
					} else {
						$back = $param;
					}
				}
				break;
			case 'online':
				if ( ExtensionRegistry::getInstance()->isLoaded( 'WhosOnline' ) ) {
					$dbProvider = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
					$dbr = $dbProvider->getReplicaDatabase();
					$res = $dbr->newSelectQueryBuilder()
						->select( [ 'count(*)' ] )
						->from( 'online' )
						->where( [ 'username' => $param ] )
						->caller( __METHOD__ )
						->fetchField();
					$back = $res == '1' ? 'online' : 'offline';
				} else {
					$back = 'unknown';
				}
				break;
		}

		return [ $back, 'noparse' => true ];
	}

	/**
	 * Vorlage mehrfach aufrufen
	 * Alle Ausdrücke in () werden an die Vorlage "calltemplate" übergeben.
	 * Weitere Parameter "callparameter" werden ebenfalls übergeben.
	 * Ausdrücke in [[]] werden nicht beachtet
	 *
	 * @param Parser $parser
	 * @param string $calltemplate
	 * @param string $text
	 *
	 * @return array
	 */
	public static function sgPackRecursive( $parser, $calltemplate = '', $text = '' ) {
		// Weitere Übergabeparameter vorbereiten
		$callparameter = '';
		$i = 3;
		while ( isset( $p[$i] ) ) {
			$callparameter .= '|' . $p[$i];
			$i++;
		}

		$output = '';

		// Text aufspalten in geklammerte und nicht geklammerte Teile, Elemente in [[]] werden nicht beachtet
		$split = preg_split( '/(\[\[.*?\]\]|\(.*?\))/i', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );

		// Alle Elemente parsen
		foreach ( $split as $para ) {
			if ( $para[0] == '(' && $para[strlen( $para ) - 1] == ')' ) {
				$sub = substr( $para, 1, strlen( $para ) - 2 ); // "Ausklammern"
			} else {
				$sub = $para;
			}

			$ask = '{{' . $calltemplate . '|' . $sub . $callparameter . '}}';  // Erzeuge Anfrage
			$result = $parser->recursiveTagParse( $ask );

			// Wenn Ergebnis == leer oder == Anfrage dann kennt die Vorlage den Parameter nicht
			// Leerzeichen werden nicht zurückgegeben
			if ( empty( $result ) || $result == trim( $sub ) ) {
				// Eingabe 1:1 in Ausgabe einfügen
				$output .= $para;
			} else {
				// Ersetze Ausdruck durch Vorlage
				$output .= $ask;
			}
		}

		return [ $output, 'noparse' => false ];
	}
}
