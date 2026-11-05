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

	// Native share sheet (phones, Safari) when the browser has one.
	document.querySelectorAll( '[data-evcert-native-share]' ).forEach( function ( button ) {
		if ( ! navigator.share ) {
			return;
		}
		button.hidden = false;
		button.addEventListener( 'click', function () {
			navigator.share( { title: button.getAttribute( 'data-title' ), url: button.getAttribute( 'data-evcert-native-share' ) } ).catch( function () {} );
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

	// Share image (og:image): drawn here, where the certificate already shows exactly, and sent once
	// per language when the page says it's missing and this viewer may send it (includes/share-image.php).
	var FONTS = [ 'Lora-Regular', 'Lora-Medium', 'Lora-SemiBold', 'Inter-Regular', 'Inter-Medium', 'Inter-SemiBold' ];
	function toDataUrl( blob ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () {
				resolve( reader.result );
			};
			reader.onerror = reject;
			reader.readAsDataURL( blob );
		} );
	}
	function fontFaces( base ) {
		return Promise.all(
			FONTS.map( function ( file ) {
				var parts = file.split( '-' );
				var weight = { Regular: 400, Medium: 500, SemiBold: 600 }[ parts[ 1 ] ];
				return fetch( base + file + '.ttf' )
					.then( function ( r ) {
						return r.blob();
					} )
					.then( toDataUrl )
					.then( function ( url ) {
						return "@font-face{font-family:'" + parts[ 0 ] + "';font-weight:" + weight + ";src:url(" + url + ") format('truetype');}";
					} );
			} )
		).then( function ( rules ) {
			return rules.join( '' );
		} );
	}
	function makeShareImage( config ) {
		var page = document.querySelector( '.evcert-verify__mode.is-light .evcert-page' );
		if ( ! page ) {
			return;
		}
		var clone = page.cloneNode( true );
		clone.style.transform = 'none';
		var w = parseFloat( page.style.width ) * PX_PER_PT;
		var h = parseFloat( page.style.height ) * PX_PER_PT;
		// Images must be inline for an SVG drawn as an image; uploads become data URIs too.
		var images = Array.prototype.filter.call( clone.querySelectorAll( 'img' ), function ( img ) {
			return img.getAttribute( 'src' ).indexOf( 'data:' ) !== 0;
		} );
		return Promise.all(
			images.map( function ( img ) {
				return fetch( img.src )
					.then( function ( r ) {
						return r.blob();
					} )
					.then( toDataUrl )
					.then( function ( url ) {
						img.setAttribute( 'src', url );
					} )
					.catch( function () {
						img.remove();
					} );
			} )
		)
			.then( function () {
				return fontFaces( config.fonts );
			} )
			.then( function ( css ) {
				var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '"><foreignObject width="100%" height="100%"><div xmlns="http://www.w3.org/1999/xhtml"><style>' + css + 'b,strong{font-weight:600}</style>' + new XMLSerializer().serializeToString( clone ) + '</div></foreignObject></svg>';
				return new Promise( function ( resolve, reject ) {
					var img = new Image();
					img.onload = function () {
						resolve( img );
					};
					img.onerror = reject;
					img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
				} );
			} )
			.then( function ( img ) {
				var canvas = document.createElement( 'canvas' );
				canvas.width = config.width;
				canvas.height = config.height;
				var ctx = canvas.getContext( '2d' );
				ctx.fillStyle = '#e9e9ea';
				ctx.fillRect( 0, 0, canvas.width, canvas.height );
				var pad = 36;
				var scale = Math.min( ( canvas.width - pad * 2 ) / w, ( canvas.height - pad * 2 ) / h );
				var dw = w * scale;
				var dh = h * scale;
				var x = ( canvas.width - dw ) / 2;
				var y = ( canvas.height - dh ) / 2;
				ctx.shadowColor = 'rgba(0,0,0,0.18)';
				ctx.shadowBlur = 24;
				ctx.shadowOffsetY = 6;
				ctx.fillStyle = '#ffffff';
				ctx.fillRect( x, y, dw, dh );
				ctx.shadowColor = 'transparent';
				ctx.drawImage( img, x, y, dw, dh );
				return fetch( config.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify( {
						code: config.code,
						locale: config.locale,
						image: canvas.toDataURL( 'image/png' ),
						tix_attendee_id: config.tix_attendee_id,
						tix_edit_token: config.tix_edit_token,
					} ),
				} );
			} )
			.catch( function () {} );
	}

	var docked = store( 'evcert-dock' );
	if ( docked === 'left' || docked === 'right' ) {
		dockTo( docked );
	}
	layout();
	window.addEventListener( 'resize', layout );
	( document.fonts ? document.fonts.ready : Promise.resolve() ).then( function () {
		fit();
		var share = document.querySelector( '[data-evcert-share-image]' );
		if ( share ) {
			makeShareImage( JSON.parse( share.getAttribute( 'data-evcert-share-image' ) ) );
		}
	} );
} )();
