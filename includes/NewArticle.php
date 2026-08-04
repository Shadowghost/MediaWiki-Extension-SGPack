<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Content\TextContent;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

class NewArticle implements
	EditPage__showEditForm_initialHook
{

	/**
	 * Load a template page and return its filtered wikitext.
	 *
	 * Returns null for a name that is not a valid title, a page that does not
	 * exist, and a page whose content model is not text-based.
	 *
	 * @param string $name Template name, without the namespace prefix
	 *
	 * @return string|null
	 */
	private static function loadTemplateText( $name ) {
		$title = Title::makeTitleSafe( NS_TEMPLATE, trim( $name ) );
		if ( !$title ) {
			return null;
		}

		$content = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $title )
			->getContent();
		if ( !$content instanceof TextContent ) {
			return null;
		}

		return self::filterPage( $content->getText() );
	}

	/**
	 * Filter article, noinclude remove
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private static function filterPage( $text ) {
		$replace = '$1$2';
		// <noinclude> - remove everything inbetween
		$expr = '/(.*)<noinclude>(?s).*<\/noinclude>(.*)/';
		$text = preg_replace( $expr, $replace, $text );
		// <includeonly> - remove tags only
		$expr = '/(.*)<includeonly>|<\/includeonly>(.*)/';
		$text = preg_replace( $expr, $replace, $text );
		return $text;
	}

	/**
	 * @param EditPage $editPage
	 * @param OutputPage $output
	 *
	 * @return true
	 */
	public function onEditPage__showEditForm_initial( $editPage, $output ) {
		$parser = MediaWikiServices::getInstance()->getParser();
		$pageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$title = $output->getTitle();

		// Check if new article
		if ( $title && !$title->exists() ) {
			// Load control page "MediaWiki:NewArticle-NS"
			$controlTitle = Title::makeTitleSafe( NS_MEDIAWIKI, 'NewArticle-' . $title->getNamespace() );
			if ( !$controlTitle ) {
				return true;
			}
			$page = $pageFactory->newFromTitle( $controlTitle );
			$content = $page->getContent();
			// Check if something is loaded
			if ( $content instanceof TextContent ) {
				// Init buffer
				$html = '';
				$idNr = 0;
				// Seite parsen
				$text = $parser->parse(
					$content->getText(),
					$page->getTitle(),
					ParserOptions::newFromUser( $output->getUser() )
				);
				// Definition der Auswahlliste(n) herrauslösen
				$teile = preg_split(
					'/(\[\[\[.*?\]\]\])/s',
					$text->getText(),
					-1,
					PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
				);
				foreach ( $teile as $teil ) {
					// Wenn Auswahlliste [[[...]]]
					if ( substr( $teil, 0, 3 ) == '[[[' && substr( $teil, -3, 3 ) == ']]]' ) {
						// Klammern entfernen
						$teil = substr( $teil, 3, strlen( $teil ) - 4 );
						$tarray = explode( ',', $teil );
						// Nur ein Argument -> Button statt Liste
						if ( count( $tarray ) == 1 ) {
							$idNr += 1;
							$zeile = explode( '|', $tarray[0] );
							$zeile[] = '';
							// Artikel einlesen, umwandeln und im HTML Code ablegen
							$tmpText = self::loadTemplateText( $zeile[0] );
							if ( $tmpText !== null && $tmpText !== '' ) {
								// Payload in a data attribute, picked up by the delegated
								// click handler in ext.sgPack.js. It was an inline onclick,
								// which required script-src 'unsafe-inline'.
								$html .= Html::element(
									'button',
									[
										'type' => 'button',
										'class' => 'mw-sgpack-ddinsert-button',
										'id' => 'NewArticleButton' . $idNr,
										'data-mw-sgpack-insert' => DDInsert::sgpEncode( '+' . $tmpText . '+' ),
									],
									$zeile[1]
								);
							}
						}
						if ( count( $tarray ) > 1 ) {
							$idNr += 1;
							// Dropdown Auswahl erstellen
							$html .= Html::openElement( 'select', [
								'size' => 1,
								'id' => 'NewArticleSelect' . $idNr,
								'class' => 'mw-sgpack-ddinsert-select',
							] ) . "\n";
							// Erstes Element ist Bezeichnung für die "Überschrift"
							$erst = empty( $tarray[0] )
								? wfMessage( 'newarticle-selecttitle' )->text()
								: $tarray[0];
							// '++' rather than '': the payload is split on '+' into
							// pre/peri/post, so an empty value yields no usable parts
							$html .= Html::element(
								'option',
								[
									'selected' => 'selected',
									'value' => '++'
								],
								$erst
							);
							unset( $tarray[0] );
							foreach ( $tarray as $value ) {
								// Die Zeile aufteilen
								$zeile = explode( '|', $value );
								$zeile[] = '';
								// Artikel einlesen, umwandeln und im HTML Code ablegen
								$tmpText = self::loadTemplateText( $zeile[0] );
								if ( $tmpText !== null && $tmpText !== '' ) {
									$html .= Html::element(
										'option',
										[
											'value' => DDInsert::sgpEncode( '+' . $tmpText . '+' )
										],
										$zeile[1]
									);
								}
							}
							$html .= Html::closeElement( 'select' );
						}
					} else {
						// Sonstigen Text nur übernehmen
						$html .= $teil;
					}
				}
				// Ergebniss in die Ausgabe einfuegen
				$editPage->editFormPageTop .= $html;
			}
		}
		return true;
	}
}
