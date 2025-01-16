<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\MediaWikiServices;
use ParserOptions;
use Title;
use Xml;

class NewArticle
{

	/**
	 * Filter article, noinclude remove
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private static function filterPage($text)
	{
		$replace = '$1$2';
		// <noinclude> - remove everything inbetween
		$expr = '/(.*)<noinclude>(?s).*<\/noinclude>(.*)/';
		$text = preg_replace($expr, $replace, $text);
		// <includeonly> - remove tags only
		$expr = '/(.*)<includeonly>|<\/includeonly>(.*)/';
		$text = preg_replace($expr, $replace, $text);
		return $text;
	}

	/**
	 * @param EditPage $editPage
	 * @param OutputPage $output
	 *
	 * @return true
	 */
	public static function onEditPage__showEditForm_initial($editPage, $output)
	{
		$parser = MediaWikiServices::getInstance()->getParser();
		$pageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$title = $output->getTitle();

		// Check if new article
		if (!$title->exists()) {
			// Load control page "MediaWiki:NewArticle-NS"
			$page = $pageFactory->newFromTitle(Title::makeTitleSafe(8, 'NewArticle-' . $title->getNamespace()));
			$content = $page->getContent();
			// Check if something is loaded
			if (!empty($content)) {
				// Init buffer
				$html = '';
				$idNr = 0;
				// Seite parsen
				$text = $parser->parse($content->getNativeData(), $page->getTitle(), new ParserOptions($output->getUser()));
				// Definition der Auswahlliste(n) herrauslösen
				$teile = preg_split('/(\[\[\[.*?\]\]\])/s', $text->getText(), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
				foreach ($teile as $teil) {
					// Wenn Auswahlliste [[[...]]]
					if (substr($teil, 0, 3) == '[[[' and substr($teil, -3, 3) == ']]]') {
						// Klammern entfernen
						$teil = substr($teil, 3, strlen($teil) - 4);
						$tarray = explode(',', $teil);
						// Nur ein Argument -> Button statt Liste
						if (count($tarray) == 1) {
							$idNr += 1;
							$zeile = explode('|', $tarray[0]);
							$zeile[] = '';
							// Artikel einlesen, umwandeln und im HTML Code ablegen
							$tmpPage = $pageFactory->newFromTitle(Title::makeTitleSafe(10, trim($zeile[0])));
							$tmpContent = $tmpPage->getContent();
							if (!empty($tmpContent)) {
								$html .= Xml::element(
									'button',
									[
										'onclick' => "mw.SGPack.insert('" . DDInsert::sgpEncode('+' . self::filterPage($tmpContent) . '+') . "');",
										'id' => 'NewArticleButton' . $idNr,
										'type' => 'button'
									],
									$zeile[1]
								);
							}
							unset($tmpPage);
							unset($tmpContent);
						}
						if (count($tarray) > 1) {
							$idNr += 1;
							// Dropdown Auswahl erstellen
							$html .= '<select size="1" id="NewArticleSelect' . $idNr . '" onchange="mw.SGPack.insertSelect(this);">' . "\n";
							// Erstes Element ist Bezeichnung für die "Überschrift"
							$erst = empty($tarray[0]) ? wfMessage('newarticle-selecttitle') : $tarray[0];
							$html .= Xml::element(
								'option',
								[
									'selected' => 'selected',
									'value' => ''
								],
								$erst
							);
							unset($tarray[0]);
							foreach ($tarray as $index => $value) {
								// Die Zeile aufteilen
								$zeile = explode('|', $value);
								$zeile[] = '';
								// Artikel einlesen, umwandeln und im HTML Code ablegen
								$tmpPage = $pageFactory->newFromTitle(Title::makeTitleSafe(10, trim($zeile[0])));
								$tmpContent = $tmpPage->getContent()->getNativeData();
								if (!empty($tmpContent)) {
									$html .= Xml::element(
										'option',
										[
											'value' => DDInsert::sgpEncode('+' . self::filterPage($tmpContent) . '+')
										],
										$zeile[1]
									);
								}
								unset($tmpPage);
								unset($tmpContent);
							}
							$html .= '</select>';
						}
					} else {    // Sonstigen Text nur übernehmen
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
