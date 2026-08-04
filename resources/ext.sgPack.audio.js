( function () {
	/**
	 * Audio element per control, created on demand. null means the browser said it
	 * cannot decode the file, so the control stays a plain link.
	 *
	 * A WeakMap rather than jQuery.data(): the values are DOM objects, and .data()
	 * both coerces them and loses their type.
	 *
	 * @type {WeakMap<Element, HTMLAudioElement|null>}
	 */
	const players = new WeakMap();

	/** @type {WeakSet<Element>} */
	const initialised = new WeakSet();

	/**
	 * The clip currently playing, so starting one stops the other. A paragraph full
	 * of these overlapping is the obvious failure mode.
	 *
	 * @type {?{ audio: HTMLAudioElement, control: Element }}
	 */
	let current = null;

	/**
	 * Format seconds as m:ss, so screen readers announce a position rather than a
	 * bare float.
	 *
	 * @param {number} seconds
	 *
	 * @return {string}
	 */
	function formatTime( seconds ) {
		const whole = Math.max( 0, Math.floor( seconds || 0 ) );
		const mins = Math.floor( whole / 60 );
		const secs = whole % 60;
		return mins + ':' + ( secs < 10 ? '0' : '' ) + secs;
	}

	/**
	 * @param {Element} control
	 *
	 * @return {HTMLElement|null}
	 */
	function toggleOf( control ) {
		return /** @type {HTMLElement|null} */ (
			control.querySelector( '.mw-sgpack-audio-toggle' )
		);
	}

	/**
	 * @param {Element} control
	 *
	 * @return {HTMLInputElement|null}
	 */
	function rangeOf( control ) {
		return /** @type {HTMLInputElement|null} */ (
			control.querySelector( '.mw-sgpack-audio-seek' )
		);
	}

	/**
	 * @param {Element} control
	 * @param {boolean} playing
	 */
	function setState( control, playing ) {
		const toggle = toggleOf( control );
		if ( !toggle ) {
			return;
		}

		const name = toggle.getAttribute( 'data-mw-sgpack-audio-name' ) || '';
		const label = playing ?
			mw.msg( 'sgpack-audio-stop', name ) :
			mw.msg( 'sgpack-audio-play', name );

		control.classList.toggle( 'mw-sgpack-audio-playing', playing );
		toggle.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
		toggle.setAttribute( 'aria-label', label );
		toggle.setAttribute( 'title', label );
	}

	/**
	 * @param {Element} control
	 */
	function markUnplayable( control ) {
		const toggle = toggleOf( control );
		const range = rangeOf( control );

		control.classList.add( 'mw-sgpack-audio-unplayable' );
		if ( toggle ) {
			toggle.setAttribute( 'title', mw.msg( 'sgpack-audio-unplayable' ) );
		}
		if ( range ) {
			range.remove();
		}
	}

	/**
	 * Stop whatever is playing and rewind it.
	 */
	function stopCurrent() {
		if ( !current ) {
			return;
		}
		current.audio.pause();
		current.audio.currentTime = 0;
		setState( current.control, false );
		current = null;
	}

	/**
	 * @param {Element} control
	 * @param {HTMLAudioElement} audio
	 */
	function bindSeekBar( control, audio ) {
		const range = rangeOf( control );
		if ( !range ) {
			return;
		}

		// True while the user drags or holds a key. Without it the timeupdate
		// handler overwrites the value under their fingers and the thumb jitters or
		// snaps back - the classic custom-seek-bar bug.
		let seeking = false;

		audio.addEventListener( 'loadedmetadata', () => {
			// Streams and some headerless Ogg files report Infinity or NaN. A slider
			// with no finite range cannot work, so leave it disabled and let the
			// control behave as plain play/stop.
			if ( !isFinite( audio.duration ) || audio.duration <= 0 ) {
				return;
			}
			range.max = String( audio.duration );
			range.disabled = false;
		} );

		audio.addEventListener( 'timeupdate', () => {
			if ( seeking ) {
				return;
			}
			range.value = String( audio.currentTime );
			range.setAttribute( 'aria-valuetext', formatTime( audio.currentTime ) );
		} );

		range.addEventListener( 'pointerdown', () => {
			seeking = true;
		} );
		range.addEventListener( 'keydown', () => {
			seeking = true;
		} );
		range.addEventListener( 'pointerup', () => {
			seeking = false;
		} );
		range.addEventListener( 'keyup', () => {
			seeking = false;
		} );
		range.addEventListener( 'change', () => {
			seeking = false;
		} );

		range.addEventListener( 'input', () => {
			audio.currentTime = Number( range.value );
			range.setAttribute( 'aria-valuetext', formatTime( audio.currentTime ) );
		} );

		// The range is a sibling of the link rather than inside it, but a stray
		// click must still never navigate.
		range.addEventListener( 'click', ( e ) => {
			e.preventDefault();
		} );
	}

	/**
	 * Get the audio element for a control, creating it on first use.
	 *
	 * @param {Element} control
	 *
	 * @return {HTMLAudioElement|null} null when the file cannot be decoded
	 */
	function getAudio( control ) {
		if ( players.has( control ) ) {
			return players.get( control ) || null;
		}

		const toggle = toggleOf( control );
		if ( !toggle ) {
			players.set( control, null );
			return null;
		}

		const src = toggle.getAttribute( 'data-mw-sgpack-audio-src' ) || '';
		const type = toggle.getAttribute( 'data-mw-sgpack-audio-type' ) || '';
		const seekable = toggle.getAttribute( 'data-mw-sgpack-audio-seek' ) === '1';
		const audio = new Audio();

		// Ask before committing. An empty answer means no chance of playback, and a
		// dead button is worse than the link we started with.
		if ( !src || ( type && audio.canPlayType( type ) === '' ) ) {
			markUnplayable( control );
			players.set( control, null );
			return null;
		}

		// metadata only when a seek bar needs the duration up front; otherwise
		// nothing is fetched until the reader actually clicks.
		audio.preload = seekable ? 'metadata' : 'none';
		audio.src = src;

		audio.addEventListener( 'ended', () => {
			setState( control, false );
			if ( current && current.audio === audio ) {
				current = null;
			}
		} );

		audio.addEventListener( 'error', () => {
			setState( control, false );
			markUnplayable( control );
			if ( current && current.audio === audio ) {
				current = null;
			}
		} );

		players.set( control, audio );
		bindSeekBar( control, audio );
		return audio;
	}

	/**
	 * Prepare the controls inside a container.
	 *
	 * Seekable controls are set up eagerly, because their slider is inert until the
	 * duration is known. Plain ones wait for a click and fetch nothing.
	 *
	 * @param {Element} container
	 */
	function init( container ) {
		const controls = container.querySelectorAll( '.mw-sgpack-audio' );

		Array.prototype.forEach.call( controls, ( control ) => {
			if ( initialised.has( control ) ) {
				return;
			}
			initialised.add( control );

			const toggle = toggleOf( control );
			if ( !toggle ) {
				return;
			}
			toggle.setAttribute( 'role', 'button' );
			toggle.setAttribute( 'aria-pressed', 'false' );

			if ( toggle.getAttribute( 'data-mw-sgpack-audio-seek' ) === '1' ) {
				getAudio( control );
			}
		} );
	}

	// Delegated from the document, so live preview and other dynamically inserted
	// content work without rebinding. No inline handlers anywhere.
	document.addEventListener( 'click', ( e ) => {
		const target = /** @type {Element|null} */ ( e.target );
		if ( !target || !target.closest ) {
			return;
		}

		const toggle = target.closest( '.mw-sgpack-audio-toggle' );
		if ( !toggle ) {
			return;
		}

		const control = toggle.closest( '.mw-sgpack-audio' );
		if ( !control ) {
			return;
		}

		const audio = getAudio( control );

		// Undecodable: leave the click alone so the href still opens the file page.
		if ( !audio ) {
			return;
		}

		e.preventDefault();

		if ( current && current.audio === audio ) {
			stopCurrent();
			return;
		}

		stopCurrent();
		current = { audio: audio, control: control };
		setState( control, true );

		// A rejection here is autoplay policy or a decode failure, not a bug.
		const started = audio.play();
		if ( started && typeof started.catch === 'function' ) {
			started.catch( () => {
				setState( control, false );
				markUnplayable( control );
				current = null;
			} );
		}
	} );

	$( () => {
		init( document.body );
	} );

	/**
	 * @param {JQuery} $content
	 */
	mw.hook( 'wikipage.content' ).add( ( $content ) => {
		$content.toArray().forEach( init );
	} );
}() );
