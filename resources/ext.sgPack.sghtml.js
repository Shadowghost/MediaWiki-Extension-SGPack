( function () {
	/**
	 * Decide whether a heading is a section heading of the page content.
	 *
	 * @param {JQuery} $heading
	 *
	 * @return {boolean}
	 */
	function isSectionHeading( $heading ) {
		const marked = $heading.children( '.mw-headline' ).length > 0 ||
			$heading.parent().is( '.mw-heading' );

		return marked && $heading.closest( '.mw-parser-output' ).length > 0;
	}

	/**
	 * Add a jump-to-top link to each section heading.
	 *
	 * Needs a real element, so it cannot be done in CSS. href="#top" is handled
	 * natively by browsers, so no inline handler and no scroll script are
	 * involved.
	 *
	 * @param {JQuery} $content
	 */
	function addTopLinks( $content ) {
		$content.find( 'h2, h3, h4, h5, h6' ).each( function () {
			const $heading = $( this );

			if ( !isSectionHeading( $heading ) ) {
				return;
			}

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
