<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use ExtensionRegistry;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use RequestContext;
use Title;

class ParserAdds {

	/**
	 * Name of the parser option registered via the ParserOptionsRegister hook.
	 *
	 * Reading it from a parser function records it as used, which fragments the
	 * parser cache key by user instead of disabling the cache for the page.
	 *
	 * @see Hooks::onParserOptionsRegister()
	 */
	public const USER_PARSER_OPTION = 'sgpack-userinfo-user';

	/**
	 * @param Parser &$parser
	 * @param string $rel
	 * @param string $page
	 * @param string $title
	 *
	 * @return string
	 */
	public static function sgPackLink( &$parser, $rel = '', $page = '', $title = '' ) {
		if ( empty( $rel ) ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_norel' ) . '</strong>';
		}

		if ( empty( $page ) ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_nopage' ) . '</strong>';
		}

		if ( empty( $title ) ) {
			$title = $page;
		}

		$pt = Title::newFromText( $page );
		if ( !$pt ) {
			return '<strong class="error">' . wfMessage( 'parseradds_link_illegalpage' ) . '</strong>';
		}

		if ( $pt->exists() ) {
			// addHeadItem(), not $wgOut->addLink(): a parser function only runs on a
			// parser-cache miss, so anything written to OutputPage from here silently
			// disappeared on every cached view. Head items are stored in the
			// ParserOutput and so survive the cache.
			$parser->getOutput()->addHeadItem(
				Html::element( 'link', [ 'rel' => $rel, 'title' => $title, 'href' => $pt->getFullURL() ] ),
				// Keyed so repeated calls for the same rel/page collapse instead of
				// emitting duplicate <link> tags
				'sgpack-link-' . $rel . '-' . $pt->getPrefixedDBkey()
			);
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
		if ( $modus == 'e' || $modus == 's' ) {
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
	 * @param string $default
	 *
	 * @return array
	 */
	public static function sgPackTOCMod( &$parser, $arg = '', $default = 'set' ) {
		// No updateCacheExpiry( 0 ) here: the output is a __TOC__/__NOTOC__/__FORCETOC__
		// magic word derived purely from $arg, so it is neither user- nor
		// request-dependent and there is nothing for it to invalidate.
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
		$services = MediaWikiServices::getInstance();
		$options = $parser->getOptions();

		// Reading the registered option marks this parse as varying by user, so the
		// parser cache key splits per user. That replaces the previous blanket
		// updateCacheExpiry( 0 ), which disabled caching entirely for every page
		// using this function.
		$options->getOption( self::USER_PARSER_OPTION );

		// The user the parse is *for*, not RequestContext::getMain()->getUser().
		// The latter is meaningless during job-queue and refreshLinks re-parses,
		// which is why this function used to render the wrong user's data.
		$user = $services->getUserFactory()->newFromUserIdentity( $options->getUserIdentity() );

		$back = '';
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
				// Deliberately ignores $param. Honouring it loaded an arbitrary
				// account and rendered its address into the page, so anyone able
				// to edit could expose any registered user's email to every
				// viewer. Only the requesting user's own address is available.
				$back = $user->getEmail();
				break;
			case 'skin':
				// User::getSkin() has not existed for many releases, so this case
				// was a hard fatal. The user's skin preference is the equivalent.
				$back = $services->getUserOptionsLookup()->getOption( $user, 'skin' );
				break;
			case 'home':
				if ( !empty( $param ) ) {
					$user = $services->getUserFactory()->newFromName( $param );
					if ( !$user ) {
						return '<strong class="error">' . wfMessage( 'parseradds_userinfo_illegal' ) . '</strong>';
					}
				}
				$back = '[[' . $user->getUserPage()->getFullText() . ']]';
				break;
			case 'talk':
				if ( !empty( $param ) ) {
					$user = $services->getUserFactory()->newFromName( $param );
					if ( !$user ) {
						return '<strong class="error">' . wfMessage( 'parseradds_userinfo_illegal' ) . '</strong>';
					}
				}
				// getUserPage()->getTalkNsText() . getName() produced
				// "[[User talkFoo]]" — the namespace text carries no colon.
				$back = '[[' . $user->getTalkPage()->getFullText() . ']]';
				break;
			case 'groups':
				$userService = $services->getUserGroupManager();
				$back = implode( ",", $userService->getUserGroups( $user ) );
				break;
			case 'group':
				$userService = $services->getUserGroupManager();
				$back = in_array( $param, $userService->getUserGroups( $user ) ) ? $param : '';
				break;
			case 'browser':
				// Derived from the current HTTP request, so it cannot be represented
				// in the parser cache key at all — this case alone still has to opt
				// the page out of caching.
				$parser->getOutput()->updateCacheExpiry( 0 );
				// Absent on requests without a User-Agent header and in CLI contexts (jobs, maintenance)
				$userAgent = RequestContext::getMain()->getRequest()->getHeader( 'User-Agent' );
				$back = $userAgent !== false ? $userAgent : '';
				if ( !empty( $param ) ) {
					if ( strpos( $back, $param ) === false ) {
						$back = '';
					} else {
						$back = $param;
					}
				}
				break;
			case 'online':
				// Who is online right now changes minute to minute and is not a
				// function of the parse, so this case also cannot be cached.
				$parser->getOutput()->updateCacheExpiry( 0 );
				if ( ExtensionRegistry::getInstance()->isLoaded( 'WhosOnline' ) ) {
					$dbProvider = $services->getDBLoadBalancerFactory();
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
		// $p was never defined, so every parameter past $text was dropped. isset()
		// on an undefined variable does not warn, which is why this went unnoticed.
		$p = func_get_args();
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
