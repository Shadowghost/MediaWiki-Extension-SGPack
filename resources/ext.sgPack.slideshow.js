( function () {
	/**
	 * Per-slideshow state, keyed by the container element.
	 *
	 * A WeakMap rather than jQuery.data(): the values hold DOM nodes and a timer id,
	 * and .data() both coerces them and loses their type.
	 *
	 * @type {WeakMap<Element, Show>}
	 */
	const shows = new WeakMap();

	/** Every initialised container, so the tab-visibility handler can reach them. */
	const running = new Set();

	/**
	 * Readers who have asked for less animation get a still image and the controls,
	 * never a box that changes on its own.
	 *
	 * @return {boolean}
	 */
	function prefersReducedMotion() {
		return window.matchMedia &&
			window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches;
	}

	/**
	 * The `data-mw-sgpack-slideshow` payload written by Slideshow.php. Every field is
	 * optional here because the attribute is parsed at runtime and may be anything.
	 *
	 * @typedef {Object} SlideshowConfig
	 * @property {number} [timeout] Milliseconds a slide stays up; 0 never advances
	 * @property {number} [speed] Milliseconds a crossfade takes
	 * @property {string} [effect] `none` or `fade`
	 * @property {boolean} [random] Shuffle the playback order
	 * @property {boolean} [autostart] Start playing on load
	 */

	/**
	 * @typedef {Object} Show
	 * @property {HTMLElement} root
	 * @property {Element[]} slides
	 * @property {Element[]} captions
	 * @property {number[]} order Indexes into slides, in playback order
	 * @property {number} position Index into order, not into slides
	 * @property {number} timeout Milliseconds a slide stays up; 0 never advances
	 * @property {boolean} playing
	 * @property {?number} timer
	 * @property {?HTMLButtonElement} toggle
	 */

	/**
	 * The lightbox, created once for the whole page on first use.
	 *
	 * @type {?HTMLDialogElement}
	 */
	let dialog = null;

	/** @type {?HTMLImageElement} */
	let dialogImage = null;

	/**
	 * @return {HTMLDialogElement|null} null when the browser has no <dialog>
	 */
	function getDialog() {
		if ( dialog ) {
			return dialog;
		}

		const element = document.createElement( 'dialog' );
		if ( typeof element.showModal !== 'function' ) {
			return null;
		}

		element.className = 'mw-sgpack-slideshow-dialog';

		const image = document.createElement( 'img' );
		image.className = 'mw-sgpack-slideshow-dialog-image';
		image.alt = '';
		element.appendChild( image );

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'mw-sgpack-slideshow-dialog-close';
		close.title = mw.msg( 'sgpack-slideshow-close' );
		close.setAttribute( 'aria-label', mw.msg( 'sgpack-slideshow-close' ) );
		close.addEventListener( 'click', () => {
			element.close();
		} );
		element.appendChild( close );

		// Clicking the backdrop lands on the <dialog> itself, never on its children.
		element.addEventListener( 'click', ( e ) => {
			if ( e.target === element ) {
				element.close();
			}
		} );

		// Esc closes natively; drop the source either way so the image is not kept
		// decoded in memory for the rest of the session.
		element.addEventListener( 'close', () => {
			image.removeAttribute( 'src' );
		} );

		document.body.appendChild( element );
		dialog = element;
		dialogImage = image;
		return dialog;
	}

	/**
	 * @param {Show} show
	 *
	 * @return {Element|null} The slide currently on screen
	 */
	function currentSlide( show ) {
		return show.slides[ show.order[ show.position ] ] || null;
	}

	/**
	 * @param {Show} show
	 * @param {number} position Index into show.order; wraps at both ends
	 */
	function goTo( show, position ) {
		const length = show.order.length;
		show.position = ( ( position % length ) + length ) % length;

		const active = show.order[ show.position ];
		show.slides.forEach( ( slide, i ) => {
			slide.classList.toggle( 'mw-sgpack-slideshow-current', i === active );
		} );
		show.captions.forEach( ( caption, i ) => {
			caption.classList.toggle( 'mw-sgpack-slideshow-current', i === active );
		} );
	}

	/**
	 * @param {Show} show
	 */
	function clearTimer( show ) {
		if ( show.timer !== null ) {
			clearTimeout( show.timer );
			show.timer = null;
		}
	}

	/**
	 * Queue the next advance.
	 *
	 * A chained setTimeout rather than setInterval: a background tab that throttles
	 * timers would otherwise queue up a burst of advances and fire them all at once
	 * when the reader comes back.
	 *
	 * @param {Show} show
	 */
	function schedule( show ) {
		clearTimer( show );
		if ( !show.playing || show.timeout <= 0 || document.hidden ) {
			return;
		}
		show.timer = setTimeout( () => {
			show.timer = null;
			goTo( show, show.position + 1 );
			schedule( show );
		}, show.timeout );
	}

	/**
	 * @param {Show} show
	 * @param {boolean} playing
	 */
	function setPlaying( show, playing ) {
		show.playing = playing;
		if ( show.toggle ) {
			const label = playing ?
				mw.msg( 'sgpack-slideshow-pause' ) :
				mw.msg( 'sgpack-slideshow-play' );
			show.toggle.title = label;
			show.toggle.setAttribute( 'aria-label', label );
			show.toggle.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
			show.toggle.classList.toggle( 'mw-sgpack-slideshow-playing', playing );
		}
		schedule( show );
	}

	/**
	 * Step one slide and stop autoplay, which is what a reader taking manual control
	 * means by pressing an arrow.
	 *
	 * @param {Show} show
	 * @param {number} delta
	 */
	function step( show, delta ) {
		setPlaying( show, false );
		goTo( show, show.position + delta );
	}

	/**
	 * @param {Show} show
	 */
	function enlarge( show ) {
		const slide = currentSlide( show );
		const element = getDialog();
		if ( !slide || !element || !dialogImage ) {
			return;
		}

		const src = slide.getAttribute( 'data-mw-sgpack-slideshow-full' );
		if ( !src ) {
			return;
		}

		setPlaying( show, false );
		dialogImage.src = src;
		dialogImage.alt = slide.getAttribute( 'data-mw-sgpack-slideshow-name' ) || '';
		element.showModal();
	}

	/**
	 * @param {string} modifier Class suffix and message-key suffix, e.g. `prev`
	 * @param {string} message
	 * @param {function(): void} onClick
	 *
	 * @return {HTMLButtonElement}
	 */
	function makeButton( modifier, message, onClick ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		// The following classes are used here:
		// * mw-sgpack-slideshow-button-prev
		// * mw-sgpack-slideshow-button-toggle
		// * mw-sgpack-slideshow-button-next
		// * mw-sgpack-slideshow-button-enlarge
		button.className = 'mw-sgpack-slideshow-button mw-sgpack-slideshow-button-' + modifier;
		button.title = message;
		button.setAttribute( 'aria-label', message );
		button.addEventListener( 'click', onClick );
		return button;
	}

	/**
	 * Build the control bar.
	 *
	 * Rendered here rather than server-side: every button needs JavaScript to do
	 * anything, so without it there is nothing to show but four dead controls.
	 *
	 * @param {Show} show
	 */
	function addControls( show ) {
		const controls = document.createElement( 'div' );
		controls.className = 'mw-sgpack-slideshow-controls';

		controls.appendChild( makeButton( 'prev', mw.msg( 'sgpack-slideshow-prev' ), () => {
			step( show, -1 );
		} ) );

		show.toggle = makeButton( 'toggle', mw.msg( 'sgpack-slideshow-play' ), () => {
			setPlaying( show, !show.playing );
		} );
		controls.appendChild( show.toggle );

		controls.appendChild( makeButton( 'next', mw.msg( 'sgpack-slideshow-next' ), () => {
			step( show, 1 );
		} ) );

		controls.appendChild( makeButton( 'enlarge', mw.msg( 'sgpack-slideshow-enlarge' ), () => {
			enlarge( show );
		} ) );

		show.root.appendChild( controls );
	}

	/**
	 * @param {HTMLElement} root
	 */
	function initShow( root ) {
		if ( shows.has( root ) ) {
			return;
		}

		const slides = Array.prototype.slice.call(
			root.querySelectorAll( '.mw-sgpack-slideshow-slide' )
		);
		if ( slides.length < 2 ) {
			// One image is a picture, not a slideshow. Leave it as the plain link the
			// server rendered.
			return;
		}

		/** @type {SlideshowConfig} */
		let config = {};
		try {
			config = JSON.parse( root.getAttribute( 'data-mw-sgpack-slideshow' ) || '{}' );
		} catch ( e ) {
			config = {};
		}

		const order = slides.map( ( slide, i ) => i );
		if ( config.random ) {
			for ( let i = order.length - 1; i > 0; i-- ) {
				const j = Math.floor( Math.random() * ( i + 1 ) );
				[ order[ i ], order[ j ] ] = [ order[ j ], order[ i ] ];
			}
		}

		/** @type {Show} */
		const show = {
			root: root,
			slides: slides,
			captions: Array.prototype.slice.call(
				root.querySelectorAll( '.mw-sgpack-slideshow-caption' )
			),
			order: order,
			position: 0,
			timeout: Number( config.timeout ) || 0,
			playing: false,
			timer: null,
			toggle: null
		};

		if ( config.effect === 'fade' ) {
			root.classList.add( 'mw-sgpack-slideshow-fade' );
			// The transition duration is per-slideshow, so it cannot live in the
			// stylesheet. A custom property keeps the rule itself there.
			root.style.setProperty(
				'--sgpack-slideshow-speed', ( Number( config.speed ) || 0 ) + 'ms'
			);
		}

		shows.set( root, show );
		running.add( root );
		addControls( show );
		goTo( show, 0 );
		setPlaying( show, !!config.autostart && !prefersReducedMotion() );
	}

	/**
	 * Forget slideshows whose markup has been replaced.
	 *
	 * Live preview swaps the whole content element out. `shows` is a WeakMap and
	 * looks after itself, but `running` holds strong references, and a detached
	 * slideshow left in it goes on running its timer for the rest of the session.
	 */
	function prune() {
		running.forEach( ( root ) => {
			if ( document.contains( root ) ) {
				return;
			}
			const show = shows.get( root );
			if ( show ) {
				clearTimer( show );
			}
			running.delete( root );
		} );
	}

	/**
	 * @param {Element} container
	 */
	function init( container ) {
		prune();

		const roots = container.querySelectorAll( '.mw-sgpack-slideshow' );
		Array.prototype.forEach.call( roots, ( root ) => {
			initShow( /** @type {HTMLElement} */ ( root ) );
		} );
	}

	// Timers are pointless while the tab is hidden, and resuming from where the
	// reader left off beats catching up on a minute of missed advances.
	document.addEventListener( 'visibilitychange', () => {
		running.forEach( ( root ) => {
			const show = shows.get( root );
			if ( show ) {
				schedule( show );
			}
		} );
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
