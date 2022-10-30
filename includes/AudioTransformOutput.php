<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaTransformOutput;

class AudioTransformOutput extends MediaTransformOutput {
	private $pSourceFileURL;
	private $pFileName;
	private $pMimeType;

	public function __construct( $sourceFileURL, $fileName, $mimeType ) {
		$this->pSourceFileURL = $sourceFileURL;
		$this->pFileName = $fileName;
		$this->pMimeType = $mimeType;
	}

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

	private function expandHtml( $html, $args ) {
		foreach ( $args as $key => $value ) {
			$args[$key] = htmlspecialchars( $value );
		}

		return str_replace( array_keys( $args ), array_values( $args ), $html );
	}
}
