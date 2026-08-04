/*
 * no-jquery/no-global-selector is disabled for this file: the entire job of this
 * module is to insert text into the edit form's textbox, which it has to locate
 * in the document. There is no longer-lived node to hold in memory instead.
 */
/* eslint-disable no-jquery/no-global-selector */
( function () {
	// The textbox an insert should go to. Bound once at init, not per insert.
	let $currentFocused = $( '#wpTextbox1' );

	/**
	 * String (UTF-8 sicher) decode
	 *
	 * @param {string} str
	 *
	 * @return {string}
	 */
	function rawdecode( str ) {
		return decodeURIComponent( str ).replace( /%(?![\da-f]{2})/gi, () => '%25' );
	}

	/**
	 * Insert an encoded `pre+peri+post` payload at the cursor.
	 *
	 * @param {string|undefined} str Payload as produced by DDInsert::sgpEncode().
	 *   May be undefined: callers read it out of a data attribute, which can be
	 *   absent, and the guard below is what handles that.
	 */
	function insert( str ) {
		if ( !str || !$currentFocused.length ) {
			return;
		}

		const astr = rawdecode( String( str ) ).split( '+' );

		$currentFocused.textSelection( 'encapsulateSelection', {
			pre: astr[ 0 ] || '',
			peri: astr[ 1 ] || '',
			post: astr[ 2 ] || ''
		} );
	}

	/**
	 * String aus Dropdown Auswahl auslesen und in Editorfeld einsetzen
	 *
	 * @param {HTMLSelectElement} sel
	 */
	function insertSelect( sel ) {
		insert( sel.options[ sel.selectedIndex ].value );
	}

	$( () => {
		$currentFocused = $( '#wpTextbox1' );

		// Apply to dynamically created textboxes as well as normal ones
		$( document ).on( 'focus', 'textarea, input:text, .CodeMirror', function () {
			// CodeMirror hooks into #wpTextbox1 for textSelection changes
			$currentFocused = $( this ).is( '.CodeMirror' ) ? $( '#wpTextbox1' ) : $( this );
		} );

		// Delegated from the document rather than inline onclick/onchange
		// attributes, so no script-src 'unsafe-inline' is needed and markup added
		// later — live preview, for instance — works without re-binding.
		$( document ).on( 'click', '.mw-sgpack-ddinsert-button', function ( e ) {
			e.preventDefault();
			// attr(), not data(): jQuery's data() coerces values that look like
			// numbers or booleans, and this payload must stay a string.
			insert( $( this ).attr( 'data-mw-sgpack-insert' ) );
		} );

		$( document ).on( 'change', '.mw-sgpack-ddinsert-select', function () {
			insertSelect( this );
			// Back to the placeholder, so picking the same entry twice works
			this.selectedIndex = 0;
		} );
	} );

	// Exposed for wiki content that calls these directly; the extension's own
	// markup does not need it.
	mw.SGPack = {
		rawdecode: rawdecode,
		insert: insert,
		insertSelect: insertSelect
	};
}() );
