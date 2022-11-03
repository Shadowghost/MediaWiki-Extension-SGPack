<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaTransformOutput;

class AudioTransformOutput extends MediaTransformOutput {
	/**
	 * @var string
	 */
	private $pSourceFileURL;

	/**
	 * @var string
	 */
	private $pFileName;

	/**
	 * @var string
	 */
	private $pMimeType;

	/**
	 * @param string $SourceFileURL
	 * @param string $FileName
	 */
	public function __construct( $sourceFileURL, $fileName, $mimeType ) {
		$this->pSourceFileURL = $sourceFileURL;
		$this->pFileName = $fileName;
		$this->pMimeType = $mimeType;
	}

	/**
	 * @param array $options
	 *
	 * @return string
	 */
	public function toHtml( $options = [] ) {
		$output = '<audio id="audio-player" controls="" '
			. 'src="$1" type="$2" />';

		$args = [
			'$1' => $this->pSourceFileURL,
			'$2' => $this->pMimeType,
			'$3' => $this->pFileName,
		];

		return $this->expandHtml( $output, $args );
	}

	/**
	 * @param string $HTML
	 * @param string[] $Args
	 *
	 * @return string
	 */
	private function expandHtml( $html, $args ) {
		foreach ( $args as $key => $value ) {
			$args[$key] = htmlspecialchars( $value );
		}

		return str_replace( array_keys( $args ), array_values( $args ), $html );
	}
}
