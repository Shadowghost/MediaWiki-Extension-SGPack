<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

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
	 * userinfo fields whose value differs between anonymous readers.
	 *
	 * For an anonymous user these all derive from the IP address, which is exactly
	 * what the shared anonymous cache key cannot represent.
	 */
	private const ANON_VARYING_INFO = [ 'name', 'home', 'talk' ];

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
			// parser-cache miss, so anything written to OutputPage from here would be
			// absent on a cached view. Head items are stored in the ParserOutput and
			// so survive the cache.
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
	 * @return array Gefundene Elemente oder Leer, in der Parserfunktions-Form
	 *   [ $text, 'noparse' => true ]
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
	 * @return array
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
				// 'hide' and 'show' are accepted but do nothing: they would need
				// addOnloadHook()/util.toggleToc(), neither of which exists any more.
				// The cases stay so that existing wikitext passing them is not treated
				// as an unknown option.
				case 'hide':
				case 'show':
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
	 * @return array|string The parser-function array form, or a bare error string
	 *   for an invalid username
	 */
	public static function sgPackUserInfo( &$parser, $arg = 'name', $param = '' ) {
		$services = MediaWikiServices::getInstance();
		$options = $parser->getOptions();

		// Reading the registered option marks this parse as varying by user, so the
		// parser cache key splits per user rather than the page being excluded from
		// the cache entirely.
		$options->getOption( self::USER_PARSER_OPTION );

		// The user the parse is *for*, not RequestContext::getMain()->getUser():
		// the latter is meaningless during job-queue and refreshLinks re-parses.
		$user = $services->getUserFactory()->newFromUserIdentity( $options->getUserIdentity() );

		$arg = strtolower( $arg );

		// All anonymous readers share one cache entry (see
		// Hooks::onParserOptionsRegister), so a field derived from their IP address
		// must not be stored in it — one reader would be served another's IP. Those
		// fields opt this parse out of the cache instead. Everything else is
		// identical across anonymous readers and stays cacheable: id is always 0,
		// realname and email are empty, groups and skin come from the defaults.
		if ( !$user->isRegistered() && in_array( $arg, self::ANON_VARYING_INFO, true ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		$back = '';
		switch ( $arg ) {
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
				// Deliberately ignores $param: only the requesting user's own address
				// is exposed. Honouring a username here would let anyone who can edit
				// publish any registered user's email address.
				$back = $user->getEmail();
				break;
			case 'skin':
				// The user's skin preference; User::getSkin() does not exist.
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
		$p = func_get_args();
		$callparameter = '';
		$i = 3;
		while ( isset( $p[$i] ) ) {
			$callparameter .= '|' . $p[$i];
			$i++;
		}

		$output = '';

		// Text aufspalten in geklammerte und nicht geklammerte Teile. Ein Link zählt
		// samt der direkt anschließenden Buchstaben als ein Stück ([[Olesianer]]in),
		// sonst würde die Endung als eigenes Kürzel behandelt.
		$split = preg_split(
			'/(\[\[.*?\]\]\p{L}*|\(.*?\))/u',
			$text,
			-1,
			PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
		);
		if ( $split === false ) {
			// Ungültiges UTF-8: unverändert durchreichen statt abzubrechen
			return [ $text, 'noparse' => false ];
		}

		// Alle Elemente parsen
		foreach ( $split as $para ) {
			// Links werden nicht beachtet, also unverändert übernommen und nicht an
			// die Vorlage übergeben
			if ( str_starts_with( $para, '[[' ) ) {
				$output .= $para;
				continue;
			}

			if ( $para[0] == '(' && $para[strlen( $para ) - 1] == ')' ) {
				// "Ausklammern"
				$sub = substr( $para, 1, strlen( $para ) - 2 );
			} else {
				$sub = $para;
			}

			// Erzeuge Anfrage. "=" und "|" im Text würden die Parameterliste der
			// Vorlage verschieben - aus {{V|a<span style="x">|k}} würde ein
			// benannter Parameter und "k" rutschte auf Position 1.
			$ask = '{{' . $calltemplate . '|' . self::escapeTemplateArg( $sub ) . $callparameter . '}}';

			// Nur expandieren statt zu parsen: verglichen wird mit dem unveränderten
			// Wikitext, und der ist kein HTML. recursiveTagParse() lieferte für jedes
			// Stück mit Markup ein Ergebnis, das nie gleich der Eingabe sein konnte.
			$result = trim( $parser->replaceVariables( $ask ) );

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

	/**
	 * "=" und "|" in einem Textstück maskieren, damit es als ein Parameter bei der
	 * aufgerufenen Vorlage ankommt.
	 *
	 * {{=}} und {{!}} sind Magic Words des Kerns und werden erst nach dem Aufteilen
	 * der Parameter ersetzt, die Vorlage sieht also wieder das Originalzeichen.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private static function escapeTemplateArg( $text ) {
		return strtr( $text, [ '=' => '{{=}}', '|' => '{{!}}' ] );
	}
}
