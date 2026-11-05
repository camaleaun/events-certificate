/**
 * The certificate page: scales the artwork (drawn in points) to the width
 * available, shrinks one-line texts (names) that don't fit, and copies the
 * link.
 */
( function () {
	var PX_PER_PT = 96 / 72;

	function layout() {
		document.querySelectorAll( '.evcert-verify__art' ).forEach( function ( art ) {
			var width = parseFloat( getComputedStyle( art ).getPropertyValue( '--evcert-w' ) ) || 842;
			art.style.setProperty( '--evcert-scale', art.clientWidth / ( width * PX_PER_PT ) );
		} );
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

	document.querySelectorAll( '[data-evcert-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( button.getAttribute( 'data-evcert-copy' ) ).then( function () {
					button.textContent = button.getAttribute( 'data-evcert-copied' );
				} );
			}
		} );
	} );

	// Light/dark: follows the system until chosen; the choice is remembered.
	var root = document.documentElement;
	var toggle = document.querySelector( '[data-evcert-mode]' );
	function isDark() {
		var chosen = root.getAttribute( 'data-mode' );
		return chosen ? chosen === 'dark' : window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
	}
	function label() {
		if ( toggle ) {
			toggle.textContent = isDark() ? '☾' : '☀︎';
			toggle.setAttribute( 'aria-label', toggle.getAttribute( isDark() ? 'data-to-light' : 'data-to-dark' ) );
			toggle.setAttribute( 'title', toggle.getAttribute( 'aria-label' ) );
		}
	}
	try {
		var saved = window.localStorage.getItem( 'evcert-mode' );
		if ( saved === 'light' || saved === 'dark' ) {
			root.setAttribute( 'data-mode', saved );
		}
	} catch ( e ) {}
	if ( toggle ) {
		toggle.addEventListener( 'click', function () {
			var next = isDark() ? 'light' : 'dark';
			root.setAttribute( 'data-mode', next );
			try {
				window.localStorage.setItem( 'evcert-mode', next );
			} catch ( e ) {}
			label();
		} );
	}
	label();

	layout();
	window.addEventListener( 'resize', layout );
	( document.fonts ? document.fonts.ready : Promise.resolve() ).then( fit );
} )();
