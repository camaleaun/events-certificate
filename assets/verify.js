/**
 * The certificate page: fits the certificate (drawn in points) to the screen,
 * shrinks one-line texts (names) that don't fit, runs the Dock (popovers,
 * copy link, light/dark, docking to a corner) and remembers the choices.
 */
( function () {
	var PX_PER_PT = 96 / 72;
	var root = document.documentElement;
	var dock = document.querySelector( '[data-evcert-dock]' );

	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.localStorage.getItem( key );
			}
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
		return null;
	}

	// Fit: the certificate as large as the stage allows.
	function layout() {
		var art = document.querySelector( '.evcert-verify__art' );
		var stage = document.querySelector( '.evcert-verify__stage' );
		if ( ! art || ! stage ) {
			return;
		}
		var cs = getComputedStyle( art );
		var w = parseFloat( cs.getPropertyValue( '--evcert-w' ) ) * PX_PER_PT;
		var h = parseFloat( cs.getPropertyValue( '--evcert-h' ) ) * PX_PER_PT;
		var ss = getComputedStyle( stage );
		var aw = stage.clientWidth - parseFloat( ss.paddingLeft ) - parseFloat( ss.paddingRight );
		var ah = stage.clientHeight - parseFloat( ss.paddingTop ) - parseFloat( ss.paddingBottom );
		var scale = Math.max( 0.1, Math.min( aw / w, ah / h ) );
		art.style.width = w * scale + 'px';
		art.style.height = h * scale + 'px';
		art.style.setProperty( '--evcert-scale', scale );
	}

	function fit() {
		document.querySelectorAll( '.evcert-page .evcert-t' ).forEach( function ( el ) {
			if ( el.style.whiteSpace !== 'nowrap' ) {
				return;
			}
			var size = parseFloat( el.style.fontSize );
			for ( var i = 0; i < 40 && el.scrollWidth > el.clientWidth + 1; i++ ) {
				size *= 0.97;
				el.style.fontSize = size + 'pt';
			}
		} );
	}

	// Docking: centre (the bar) or a corner (one icon).
	function dockTo( where ) {
		if ( ! dock ) {
			return;
		}
		if ( where === 'left' || where === 'right' ) {
			dock.setAttribute( 'data-docked', where );
			document.body.classList.add( 'is-docked' );
		} else {
			dock.removeAttribute( 'data-docked' );
			document.body.classList.remove( 'is-docked' );
		}
		closePops();
		store( 'evcert-dock', where );
		layout();
	}

	// Popovers.
	function closePops( except ) {
		document.querySelectorAll( '[data-evcert-popover]' ).forEach( function ( pop ) {
			if ( pop !== except ) {
				pop.hidden = true;
			}
		} );
		document.querySelectorAll( '[data-evcert-pop]' ).forEach( function ( button ) {
			if ( ! except || button.getAttribute( 'data-evcert-pop' ) !== except.getAttribute( 'data-evcert-popover' ) ) {
				button.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}
	document.querySelectorAll( '[data-evcert-pop]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			var pop = document.querySelector( '[data-evcert-popover="' + button.getAttribute( 'data-evcert-pop' ) + '"]' );
			var open = pop.hidden;
			closePops( pop );
			pop.hidden = ! open;
			document.querySelectorAll( '[data-evcert-pop="' + button.getAttribute( 'data-evcert-pop' ) + '"]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
	} );
	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '[data-evcert-popover]' ) ) {
			closePops();
		}
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			closePops();
		}
	} );

	document.querySelectorAll( '[data-evcert-dock-to]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			dockTo( button.getAttribute( 'data-evcert-dock-to' ) );
		} );
	} );

	// Copy link.
	document.querySelectorAll( '[data-evcert-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( button.getAttribute( 'data-evcert-copy' ) ).then( function () {
					var label = button.querySelector( 'span' );
					var before = label.textContent;
					label.textContent = button.getAttribute( 'data-evcert-copied' );
					setTimeout( function () {
						label.textContent = before;
					}, 1600 );
				} );
			}
		} );
	} );

	// Light/dark: follows the system until chosen; the choice is remembered.
	var toggle = document.querySelector( '[data-evcert-mode]' );
	function isDark() {
		var chosen = root.getAttribute( 'data-mode' );
		return chosen ? chosen === 'dark' : window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
	}
	function label() {
		if ( ! toggle ) {
			return;
		}
		// The button shows the mode it switches to.
		var to = isDark() ? 'light' : 'dark';
		toggle.querySelector( '.evcert-dock__glyph' ).textContent = to === 'light' ? '☀︎' : '☾';
		toggle.querySelector( '.evcert-dock__mode-label' ).textContent = toggle.getAttribute( to === 'light' ? 'data-to-light' : 'data-to-dark' );
	}
	var saved = store( 'evcert-mode' );
	if ( saved === 'light' || saved === 'dark' ) {
		root.setAttribute( 'data-mode', saved );
	}
	if ( toggle ) {
		toggle.addEventListener( 'click', function () {
			var next = isDark() ? 'light' : 'dark';
			root.setAttribute( 'data-mode', next );
			store( 'evcert-mode', next );
			label();
		} );
	}
	label();

	var docked = store( 'evcert-dock' );
	if ( docked === 'left' || docked === 'right' ) {
		dockTo( docked );
	}
	layout();
	window.addEventListener( 'resize', layout );
	( document.fonts ? document.fonts.ready : Promise.resolve() ).then( fit );
} )();
