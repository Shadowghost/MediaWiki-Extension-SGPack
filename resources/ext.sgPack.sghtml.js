( function () {
	/**
	 * Add a jump-to-top link to each section heading.
	 *
	 * This is the one part of the old SGHTML rewriting that CSS cannot do, since
	 * it needs a real element. href="#top" is handled natively by browsers, so no
	 * inline handler and no scroll script are involved.
	 *
	 * @param {jQuery} $content
	 */
	function addTopLinks( $content ) {
		$content.find( 'h2, h3, h4, h5, h6' ).each( function () {
			const $heading = $( this );

			// wikipage.content can fire more than once for the same nodes
			if ( $heading.find( '.mw-sgpack-top' ).length ) {
				return;
			}

			$heading.prepend(
				$( '<a>' )
					.addClass( 'mw-sgpack-top' )
					.attr( {
						href: '#top',
						title: mw.msg( 'sghtml-top' )
					} )
			);
		} );
	}

	// Fires for the initial render and again for live preview and other
	// dynamically replaced content.
	mw.hook( 'wikipage.content' ).add( addTopLinks );
}() );
