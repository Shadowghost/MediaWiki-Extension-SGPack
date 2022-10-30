<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaHandler;

class MP3MediaHandler extends MediaHandler {
	public function doTransform( $file, $dstPath, $dstUrl, $params, $flags = 0 ) {
		return new AudioTransformOutput( $file->getFullUrl(), $file->getTitle(), $file->getMimeType() );
	}
}
