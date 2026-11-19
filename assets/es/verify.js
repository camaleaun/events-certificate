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
		var stage = document.querySelector( '.evcert-verify__stage' );
		if ( ! stage ) {
			return;
		}
		// Every page (the certificate, and the declaration when there is one) fits the stage.
		document.querySelectorAll( '.evcert-verify__art' ).forEach( function ( art ) {
			fitArt( art, stage );
		} );
	}

	function fitArt( art, stage ) {
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
			var where = button.getAttribute( 'data-evcert-dock-to' );
			if ( where === 'center' ) {
				unfold( button.getBoundingClientRect() );
			} else {
				fold( where );
			}
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


	/*
	 * Dragging, as Playground's Dock does it (packages/playground/website/src/components/dock): the bar follows
	 * the pointer sideways; pulled past an edge it previews a corner and, let go, folds into a button there;
	 * the button unfolds when clicked, and dragging it out brings the bar back under the pointer.
	 * Not on a narrow screen, where the bar spans the width. The buttons stay as the way to dock by keyboard.
	 */
	var DRAG_THRESHOLD = 4;
	var EDGE = 8;
	var OVERDRAG = 36;
	var CORNER = 16;
	var wide = window.matchMedia( '(min-width: 1025px)' );
	var reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	function setCenter( px ) {
		if ( px === null ) {
			dock.classList.remove( 'is-dragged' );
			dock.style.removeProperty( '--dock-center' );
			return;
		}
		dock.style.setProperty( '--dock-center', px + 'px' );
		dock.classList.add( 'is-dragged' );
	}

	function limits() {
		var half = dock.offsetWidth / 2;
		var min = half + EDGE;
		return { min: min, max: Math.max( min, window.innerWidth - half - EDGE ) };
	}

	function clampCenter( px ) {
		var l = limits();
		return Math.min( Math.max( px, l.min ), l.max );
	}

	/** Writes the grab sheen (a light on the bar's top edge under the pointer) without redrawing anything. */
	function sheen( opacity, clientX ) {
		dock.style.setProperty( '--sheen-o', String( opacity ) );
		if ( clientX !== undefined ) {
			var rect = dock.getBoundingClientRect();
			dock.style.setProperty( '--sheen-x', Math.min( Math.max( clientX - rect.left, 12 ), Math.max( rect.width - 12, 12 ) ) + 'px' );
		}
	}

	function isControl( el ) {
		return !! el.closest( 'button, a, input, select, textarea, label, [data-evcert-popover]' );
	}

	/** The centre of the corner button, in the viewport. */
	function cornerCenter( side ) {
		return { x: side === 'left' ? CORNER + 24 : window.innerWidth - CORNER - 24, y: window.innerHeight - CORNER - 24 };
	}

	function centerOf( rect ) {
		return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
	}

	/** Moves the bar into a corner as a button (animated, unless asked for less motion). */
	function fold( side ) {
		var from = centerOf( dock.getBoundingClientRect() );
		var to = cornerCenter( side );
		var finished = false;
		function done() {
			// Once: the animation ends, or the timer does when the browser does not run it (a tab in the background).
			if ( finished ) {
				return;
			}
			finished = true;
			dock.classList.remove( 'is-folding' );
			setCenter( null );
			dockTo( side );
		}
		if ( ! wide.matches || reduceMotion.matches || ! dock.animate || dock.hasAttribute( 'data-docked' ) ) {
			done();
			return;
		}
		closePops();
		dock.classList.add( 'is-folding' );
		var animation = dock.animate(
			[
				{ transform: 'translateX(-50%)', opacity: 1 },
				{ transform: 'translateX(-50%) translate(' + ( to.x - from.x ) + 'px,' + ( to.y - from.y ) + 'px) scale(0.1)', opacity: 0 },
			],
			{ duration: 340, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' }
		);
		animation.onfinish = done;
		animation.oncancel = done;
		setTimeout( done, 420 );
	}

	/** Brings the bar back from the corner button it was folded into. */
	function unfold( buttonRect ) {
		dockTo( 'center' );
		var x = parseFloat( store( 'evcert-dock-x' ) );
		if ( wide.matches && x > 0 ) {
			setCenter( clampCenter( x * window.innerWidth ) );
		}
		if ( ! wide.matches || reduceMotion.matches || ! dock.animate ) {
			return;
		}
		var to = centerOf( dock.getBoundingClientRect() );
		var from = centerOf( buttonRect );
		dock.animate(
			[
				{ transform: 'translateX(-50%) translate(' + ( from.x - to.x ) + 'px,' + ( from.y - to.y ) + 'px) scale(0.1)', opacity: 0 },
				{ transform: 'translateX(-50%)', opacity: 1 },
			],
			{ duration: 340, easing: 'cubic-bezier(0.2, 0.9, 0.3, 1)' }
		);
	}

	/** One drag of the bar; ends on release, cancel or when the window loses focus. */
	function startDrag( event, startCenter, started ) {
		var startX = event.clientX;
		var side = null;
		var moved = !! started;
		if ( started ) {
			dock.classList.add( 'is-dragging' );
		}
		try {
			dock.setPointerCapture( event.pointerId );
		} catch ( e ) {}

		function move( e ) {
			var delta = e.clientX - startX;
			if ( ! moved ) {
				if ( Math.abs( delta ) < DRAG_THRESHOLD ) {
					return;
				}
				moved = true;
				dock.classList.add( 'is-dragging' );
				closePops();
			}
			sheen( 1, e.clientX );
			var l = limits();
			var want = startCenter + delta;
			side = want < l.min - OVERDRAG ? 'left' : want > l.max + OVERDRAG ? 'right' : null;
			if ( side ) {
				dock.setAttribute( 'data-will-corner', side );
			} else {
				dock.removeAttribute( 'data-will-corner' );
			}
			setCenter( Math.min( Math.max( want, l.min ), l.max ) );
		}

		function end( cancelled ) {
			window.removeEventListener( 'pointermove', move, true );
			window.removeEventListener( 'pointerup', up, true );
			window.removeEventListener( 'pointercancel', cancel, true );
			window.removeEventListener( 'blur', cancel );
			try {
				dock.releasePointerCapture( event.pointerId );
			} catch ( e ) {}
			if ( ! moved ) {
				return;
			}
			// The click that ends a drag must not press the control under the pointer.
			var eat = function ( click ) {
				click.stopPropagation();
				click.preventDefault();
			};
			window.addEventListener( 'click', eat, { capture: true, once: true } );
			setTimeout( function () {
				window.removeEventListener( 'click', eat, { capture: true } );
			}, 250 );
			dock.classList.remove( 'is-dragging' );
			dock.removeAttribute( 'data-will-corner' );
			sheen( 0 );
			if ( cancelled ) {
				var x = parseFloat( store( 'evcert-dock-x' ) );
				setCenter( x > 0 ? clampCenter( x * window.innerWidth ) : null );
			} else if ( side ) {
				fold( side );
			} else {
				store( 'evcert-dock-x', Math.round( ( parseFloat( dock.style.getPropertyValue( '--dock-center' ) ) / window.innerWidth ) * 1000 ) / 1000 );
			}
		}
		function up() {
			end( false );
		}
		function cancel() {
			end( true );
		}
		window.addEventListener( 'pointermove', move, true );
		window.addEventListener( 'pointerup', up, true );
		window.addEventListener( 'pointercancel', cancel, true );
		window.addEventListener( 'blur', cancel );
	}

	if ( dock ) {
		// The sheen answers the pointer on approach (softer over a control) and goes when it leaves; not while dragging.
		dock.addEventListener( 'pointermove', function ( event ) {
			if ( ! wide.matches || dock.hasAttribute( 'data-docked' ) || dock.classList.contains( 'is-dragging' ) ) {
				return;
			}
			sheen( isControl( event.target ) ? 0.12 : 1, event.clientX );
		} );
		dock.addEventListener( 'pointerleave', function () {
			if ( ! dock.classList.contains( 'is-dragging' ) ) {
				sheen( 0 );
			}
		} );
		// The bar: anywhere that is not a control.
		dock.addEventListener( 'pointerdown', function ( event ) {
			if ( ! wide.matches || event.button !== 0 || dock.hasAttribute( 'data-docked' ) || isControl( event.target ) ) {
				return;
			}
			startDrag( event, centerOf( dock.getBoundingClientRect() ).x );
		} );
		// The corner button: dragged out, it becomes the bar again, following the pointer.
		var corner = dock.querySelector( '.evcert-dock__fab' );
		if ( corner ) {
			corner.addEventListener( 'pointerdown', function ( event ) {
				if ( ! wide.matches || event.button !== 0 ) {
					return;
				}
				var startX = event.clientX;
				function pull( e ) {
					if ( Math.abs( e.clientX - startX ) < DRAG_THRESHOLD ) {
						return;
					}
					release();
					dockTo( 'center' );
					var center = clampCenter( e.clientX );
					setCenter( center );
					startDrag( e, center, true );
				}
				function release() {
					window.removeEventListener( 'pointermove', pull, true );
					window.removeEventListener( 'pointerup', release, true );
					window.removeEventListener( 'pointercancel', release, true );
				}
				window.addEventListener( 'pointermove', pull, true );
				window.addEventListener( 'pointerup', release, true );
				window.addEventListener( 'pointercancel', release, true );
			} );
		}
		// Keeps the bar on screen when the window changes.
		window.addEventListener( 'resize', function () {
			var x = parseFloat( store( 'evcert-dock-x' ) );
			if ( dock.classList.contains( 'is-dragged' ) && x > 0 ) {
				setCenter( clampCenter( x * window.innerWidth ) );
			}
		} );
	}

	var docked = store( 'evcert-dock' );
	if ( docked === 'left' || docked === 'right' ) {
		dockTo( docked );
	} else if ( dock && wide.matches && parseFloat( store( 'evcert-dock-x' ) ) > 0 ) {
		setCenter( clampCenter( parseFloat( store( 'evcert-dock-x' ) ) * window.innerWidth ) );
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

// "Check the document": after a check, open Details to show the answer.
( function () {
	var answer = document.querySelector( '[data-evcert-open="details"]' );
	var button = answer && document.querySelector( '[data-evcert-pop="details"]' );
	if ( button ) {
		button.click();
	}
} )();

// "Check the document": written with its mask while typing ("0" is a digit, any other character stays).
( function () {
	var input = document.querySelector( 'input[data-evcert-mask]' );
	if ( ! input ) {
		return;
	}
	var mask = input.getAttribute( 'data-evcert-mask' );
	input.addEventListener( 'input', function () {
		var digits = input.value.replace( /\D/g, '' );
		var out = '';
		var used = 0;
		for ( var i = 0; i < mask.length && used < digits.length; i++ ) {
			out += mask.charAt( i ) === '0' ? digits.charAt( used++ ) : mask.charAt( i );
		}
		input.value = out;
	} );
} )();
