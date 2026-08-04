/*
 * no-jquery/no-global-selector is disabled for this file: the entire job of this
 * module is to insert text into the edit form's textbox, which it has to locate
 * in the document. There is no longer-lived node to hold in memory instead.
 */
/* eslint-disable no-jquery/no-global-selector */
( function () {
	// The textbox an insert should go to.
	//
	// This used to be set up inside insert(), which registered a fresh
	// $( document ).on( 'focus', … ) handler on *every* invocation — and did so
	// after reading the value the handler was meant to supply. Binding once at
	// init fixes both the leak and the ordering.
	let $currentFocused = $( '#wpTextbox1' );

	$( () => {
		$currentFocused = $( '#wpTextbox1' );
		// Apply to dynamically created textboxes as well as normal ones
		$( document ).on( 'focus', 'textarea, input:text, .CodeMirror', function () {
			// CodeMirror hooks into #wpTextbox1 for textSelection changes
			$currentFocused = $( this ).is( '.CodeMirror' ) ? $( '#wpTextbox1' ) : $( this );
		} );
	} );

	mw.SGPack = {
		// String (UTF-8 sicher) decode
		rawdecode: function ( str ) {
			return decodeURIComponent( str ).replace( /%(?![\da-f]{2})/gi, () => '%25' );
		},

		// String decodieren und mit insertTags in Editorfeld einsetzen
		insert: function ( str ) {
			const astr = this.rawdecode( String( str ) ).split( '+' );

			if ( $currentFocused.length ) {
				$currentFocused.textSelection( 'encapsulateSelection', {
					pre: astr[ 0 ],
					peri: astr[ 1 ],
					post: astr[ 2 ]
				} );
			}
		},

		// String aus Dropdown Auswahl auslesen und in Editorfeld einsetzen
		insertSelect: function ( sel ) {
			this.insert( sel.options[ sel.options.selectedIndex ].value );
		}
	};
}() );
