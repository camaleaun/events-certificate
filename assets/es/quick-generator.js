/**
 * The certificate generator's browser app. It reads the form the blocks print,
 * draws the certificate (a canvas preview and jsPDF with one layout) and makes
 * a ZIP or a single PDF; the Meetup list is read by SheetJS. Nothing leaves the
 * page. Every text comes from window.evcertQuickGenerator (translated by PHP).
 *
 * Inspired by the generator Pamela Ribeiro (pamprn), Débora Venâncio de
 * Oliveira and Sandra Peres made at WordCamp Brasil 2026's Contributor Day.
 */
( function () {
	'use strict';

	const cfg = window.evcertQuickGenerator || { locale: 'en', fonts: {}, i18n: {} };
	const t = cfg.i18n;
	const $ = ( id ) => document.getElementById( 'evcert-' + id );

	/** printf for the translated texts: %s, %d, %1$s, %2$02d. */
	function fmt( text, ...args ) {
		let next = 0;
		return String( text ).replace( /%(?:(\d+)\$)?(0?)(\d*)[sd]/g, ( match, position, zero, width ) => {
			let value = String( args[ position ? position - 1 : next++ ] );
			if ( width ) {
				value = value.padStart( Number( width ), zero ? '0' : ' ' );
			}
			return value;
		} );
	}

	function start() {
		if ( ! $( 'canvas' ) ) {
			return;
		}
		init();
	}

	/* ---------- State ---------- */
	const FIELDS = { community: 'community', title: 'title', date: 'date', start: 'start', end: 'end', venue: 'venue', city: 'city', speakers: 'speakers', link: 'link', signName: 'sign-name', signRole: 'sign-role' };
	let people = []; // { name, on, tag }.
	let current = 0;
	let signature = { src: '', ratio: 4, alias: 'sig' };
	let logo = null;

	/** Any image (PNG, JPG, SVG, WebP) as a PNG, so canvas and jsPDF get the same thing. */
	function toPng( src, maxPx = 1600 ) {
		return new Promise( ( resolve, reject ) => {
			const img = new Image();
			if ( ! /^data:/.test( src ) ) {
				img.crossOrigin = 'anonymous';
			}
			img.onload = () => {
				let w = img.naturalWidth || 800;
				let h = img.naturalHeight || 400;
				const k = /^data:image\/svg/.test( src ) ? maxPx / Math.max( w, h ) : Math.min( 1, maxPx / Math.max( w, h ) );
				w *= k;
				h *= k;
				const c = document.createElement( 'canvas' );
				c.width = Math.round( w );
				c.height = Math.round( h );
				c.getContext( '2d' ).drawImage( img, 0, 0, c.width, c.height );
				const png = c.toDataURL( 'image/png' );
				const out = new Image();
				out.onload = () => resolve( { src: png, img: out, ratio: c.width / c.height } );
				out.src = png;
			};
			img.onerror = () => reject( new Error( 'image' ) );
			img.src = src;
		} );
	}

	function readAsDataURL( file ) {
		return new Promise( ( resolve ) => {
			const reader = new FileReader();
			reader.onload = () => resolve( reader.result );
			reader.readAsDataURL( file );
		} );
	}

	/** Shows a picture (or its placeholder) in a thumbnail box. */
	function thumb( id, image, alt, placeholder ) {
		const box = $( id );
		box.textContent = '';
		if ( image ) {
			const img = document.createElement( 'img' );
			img.src = image;
			img.alt = alt;
			box.append( img );
		} else {
			const span = document.createElement( 'span' );
			span.textContent = placeholder;
			box.append( span );
		}
	}

	async function loadSignature( src ) {
		if ( ! src ) {
			signature = { src: '', ratio: 4, alias: 'sig' };
			thumb( 'sign-thumb', '', '', t.noSignature );
			$( 'sign-remove' ).hidden = true;
			return;
		}
		try {
			signature = { ...( await toPng( src ) ), alias: 'sig' + Date.now() };
			thumb( 'sign-thumb', signature.src, t.currentSignature, '' );
			$( 'sign-remove' ).hidden = false;
		} catch ( e ) {
			$( 'gen-status' ).textContent = t.imageError;
		}
	}

	async function loadLogo( src ) {
		if ( ! src ) {
			logo = null;
			thumb( 'logo-thumb', '', '', t.noLogo );
			$( 'logo-remove' ).hidden = true;
		} else {
			try {
				logo = { ...( await toPng( src ) ), alias: 'logo' + Date.now() };
				thumb( 'logo-thumb', logo.src, t.currentLogo, '' );
				$( 'logo-remove' ).hidden = false;
			} catch ( e ) {
				logo = null;
				$( 'gen-status' ).textContent = t.imageError;
			}
		}
		renderPreview();
	}

	/* ---------- Formatting ---------- */
	function longDate( iso ) {
		if ( ! iso ) {
			return '';
		}
		const [ y, m, d ] = iso.split( '-' ).map( Number );
		return new Intl.DateTimeFormat( cfg.locale, { dateStyle: 'long' } ).format( new Date( y, m - 1, d ) );
	}

	function clock( hhmm ) {
		const [ h, m ] = hhmm.split( ':' ).map( Number );
		return new Intl.DateTimeFormat( cfg.locale, { timeStyle: 'short' } ).format( new Date( 2000, 0, 1, h, m ) );
	}

	function duration( from, to ) {
		if ( ! from || ! to ) {
			return '';
		}
		const minutes = ( hhmm ) => {
			const [ h, m ] = hhmm.split( ':' ).map( Number );
			return h * 60 + m;
		};
		let total = minutes( to ) - minutes( from );
		if ( total <= 0 ) {
			total += 24 * 60;
		}
		const h = Math.floor( total / 60 );
		const m = total % 60;
		if ( ! m ) {
			return 1 === h ? t.oneHour : fmt( t.nHours, h );
		}
		return h ? fmt( t.hoursMinutes, h, m ) : fmt( t.nMinutes, m );
	}

	function shortLink( url ) {
		return ( url || '' ).replace( /^https?:\/\/(www\.)?/, '' ).replace( /\?.*$/, '' ).replace( /\/$/, '' );
	}

	function slug( text ) {
		return text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ).slice( 0, 60 ) || t.fileParticipant;
	}

	function readEvent() {
		const event = {};
		Object.keys( FIELDS ).forEach( ( key ) => {
			event[ key ] = $( FIELDS[ key ] ).value.trim();
		} );
		event.showHours = $( 'show-hours' ).checked;
		return event;
	}

	/** Darkens a #rrggbb color (the side band). */
	function darken( hex, k ) {
		const n = parseInt( hex.replace( '#', '' ).padEnd( 6, '0' ).slice( 0, 6 ), 16 );
		const part = ( shift ) => Math.round( ( ( n >> shift ) & 255 ) * k );
		return '#' + [ 16, 8, 0 ].map( ( shift ) => part( shift ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}

	/* ---------- Drawing: the site's default design, on two backends (canvas and jsPDF) ---------- */
	// Units: millimetres; the page is the design's (A4 landscape in the Base design); font sizes in points.
	const PT = 0.3528;
	const W = cfg.design ? cfg.design.page.w * PT : 297;
	const H = cfg.design ? cfg.design.page.h * PT : 210;
	let art = {}; // The design's shapes (SVG art, rounded corners), rasterized once.
	let artReady = Promise.resolve();

	/** The design with the block's accent: it recolors the primary color and darkens it for the band. */
	function themed() {
		const custom = $( 'canvas' ).closest( '.evcert-app' ).style.getPropertyValue( '--accent' ).trim();
		const design = cfg.design;
		if ( ! /^#[0-9a-f]{6}$/i.test( custom ) || '#3858e9' === custom.toLowerCase() ) {
			return design;
		}
		const next = JSON.parse( JSON.stringify( design ) );
		if ( next.colors.primary ) {
			next.colors.primary.light = custom;
		}
		if ( next.colors.band ) {
			next.colors.band.light = darken( custom, 0.5 );
		}
		return next;
	}

	/** A date or time as PHP's date() writes it (the formats of Settings → General), with the names of the language. */
	function phpDate( format, d ) {
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const name = ( options ) => new Intl.DateTimeFormat( cfg.locale, options ).format( d );
		const hours12 = d.getHours() % 12 || 12;
		const period = () => {
			const part = new Intl.DateTimeFormat( cfg.locale, { hour: 'numeric', hour12: true } ).formatToParts( d ).find( ( p ) => 'dayPeriod' === p.type );
			return part ? part.value : d.getHours() < 12 ? 'AM' : 'PM';
		};
		let out = '';
		for ( let i = 0; i < format.length; i++ ) {
			const c = format[ i ];
			switch ( c ) {
				case '\\': out += format[ ++i ] || ''; break;
				case 'd': out += pad( d.getDate() ); break;
				case 'j': out += d.getDate(); break;
				case 'D': out += name( { weekday: 'short' } ); break;
				case 'l': out += name( { weekday: 'long' } ); break;
				case 'm': out += pad( d.getMonth() + 1 ); break;
				case 'n': out += d.getMonth() + 1; break;
				case 'M': out += name( { month: 'short' } ); break;
				case 'F': out += name( { month: 'long' } ); break;
				case 'Y': out += d.getFullYear(); break;
				case 'y': out += pad( d.getFullYear() % 100 ); break;
				case 'H': out += pad( d.getHours() ); break;
				case 'G': out += d.getHours(); break;
				case 'h': out += pad( hours12 ); break;
				case 'g': out += hours12; break;
				case 'i': out += pad( d.getMinutes() ); break;
				case 's': out += pad( d.getSeconds() ); break;
				case 'a': out += period().toLowerCase(); break;
				case 'A': out += period().toUpperCase(); break;
				default: out += c;
			}
		}
		return out;
	}

	/** A date for a field's format: long, medium, short, month or iso by the language, "site" (or none) by the site's date format. */
	function dateText( iso, format ) {
		if ( ! iso ) {
			return '';
		}
		const [ y, m, d ] = iso.split( '-' ).map( Number );
		const date = new Date( y, m - 1, d );
		if ( 'iso' === format ) {
			return iso;
		}
		if ( 'month' === format ) {
			return new Intl.DateTimeFormat( cfg.locale, { month: 'long', year: 'numeric' } ).format( date );
		}
		if ( 'long' === format || 'medium' === format || 'short' === format ) {
			return new Intl.DateTimeFormat( cfg.locale, { dateStyle: format } ).format( date );
		}
		return phpDate( cfg.dateFormat, date );
	}

	function timeText( hhmm ) {
		const [ h, m ] = hhmm.split( ':' ).map( Number );
		return phpDate( cfg.timeFormat, new Date( 2000, 0, 1, h, m ) );
	}

	/** What each field of the design says for an event and a person. */
	function valueOf( ev, name, preview ) {
		const today = new Date().toLocaleDateString( 'sv-SE' );
		const manyPeople = /,|;/.test( ev.speakers ) || new RegExp( '\\s' + t.and + '\\s', 'i' ).test( ev.speakers );
		let time = '';
		if ( ev.start ) {
			time = ev.end ? fmt( t.fromTo, timeText( ev.start ), timeText( ev.end ) ) : fmt( t.startingAt, timeText( ev.start ) );
			if ( ev.showHours && ev.end ) {
				time = fmt( t.withSpan, time, duration( ev.start, ev.end ) );
			}
		}
		const fields = Object.assign( {}, cfg.phrases, {
			name: name || t.participantName,
			event: ev.title || t.meetupTitle,
			community: ev.community,
			location: [ ev.venue, ev.city ].filter( Boolean ).join( ', ' ),
			speakers: ev.speakers,
			// No speakers: no label either.
			speakers_label: ev.speakers.trim() ? ( manyPeople ? t.speakers : t.speaker ) : '',
			event_time: time,
			event_link: shortLink( ev.link ),
			issued_on: fmt( t.issuedOn, dateText( today, 'long' ) ),
			signer_name: ev.signName,
			signer_role: ev.signRole,
		} );
		return ( field, format ) => {
			if ( 'event_date' === field ) {
				// The preview of an event with no date yet shows today's, as the design's own sample does (QuickGenerator\sheet()).
				return dateText( ev.date || ( preview ? today : '' ), format );
			}
			if ( 'issued_date' === field ) {
				return dateText( today, format );
			}
			return fields[ field ] || '';
		};
	}

	function drawCertificate( b, ev, name, preview ) {
		window.evcertDesign.draw( b, themed(), valueOf( ev, name, preview ), { signature: signature.src ? signature : null, logo, art }, cfg.locale );
	}

	const FACES = Object.keys( cfg.fonts || {} );

	function canvasBackend( ctx, scale ) {
		const font = ( face, size ) => window.evcertDesign.css( face, size * PT * scale );
		return {
			fonts: FACES,
			alpha( a ) {
				ctx.globalAlpha = a;
			},
			// Boxes and pictures land on whole device pixels, as the browser lays out the design's own HTML (the same edges, not a blur).
			rect( x, y, w, h, c ) {
				const left = Math.round( x * scale );
				const top = Math.round( y * scale );
				ctx.fillStyle = c;
				ctx.fillRect( left, top, Math.round( ( x + w ) * scale ) - left, Math.round( ( y + h ) * scale ) - top );
			},
			line( x1, y1, x2, y2, c, width ) {
				ctx.strokeStyle = c;
				ctx.lineWidth = Math.max( 1, width * scale );
				ctx.beginPath();
				ctx.moveTo( x1 * scale, y1 * scale );
				ctx.lineTo( x2 * scale, y2 * scale );
				ctx.stroke();
			},
			measure( text, f, size, spacing = 0 ) {
				ctx.font = font( f, size );
				ctx.letterSpacing = '0px';
				return ctx.measureText( text ).width / scale + spacing * text.length;
			},
			text( text, x, y, o ) {
				ctx.save();
				ctx.font = font( o.font, o.size );
				ctx.fillStyle = o.color;
				ctx.textBaseline = 'alphabetic';
				ctx.letterSpacing = ( o.cs || 0 ) * scale + 'px';
				// The browser puts the baseline of the design's own HTML on a whole device pixel: so does this.
				ctx.translate( x * scale, o.angle ? y * scale : Math.round( y * scale ) );
				if ( o.angle ) {
					ctx.rotate( ( -o.angle * Math.PI ) / 180 );
				}
				ctx.fillText( text, 0, 0 );
				ctx.restore();
			},
			image( pic, x, y, w, h ) {
				if ( pic.img ) {
					const left = Math.round( x * scale );
					const top = Math.round( y * scale );
					ctx.drawImage( pic.img, left, top, Math.max( 1, Math.round( ( x + w ) * scale ) - left ), Math.max( 1, Math.round( ( y + h ) * scale ) - top ) );
				}
			},
		};
	}

	/* ---------- PDF (jsPDF) ---------- */
	let pdfFonts = null;

	async function fontBase64( url ) {
		const bytes = new Uint8Array( await ( await fetch( url ) ).arrayBuffer() );
		let binary = '';
		for ( let i = 0; i < bytes.length; i += 0x8000 ) {
			binary += String.fromCharCode.apply( null, bytes.subarray( i, i + 0x8000 ) );
		}
		return btoa( binary );
	}

	/** The faces the design's texts use (the others are not embedded in the PDF). */
	function usedFaces() {
		return cfg.design ? window.evcertDesign.fontsUsed( cfg.design, FACES ) : FACES.filter( ( f ) => /^Cert/.test( f ) );
	}

	function loadPdfFonts() {
		if ( ! pdfFonts ) {
			pdfFonts = Promise.all( usedFaces().map( async ( key ) => [ key, await fontBase64( cfg.fonts[ key ] ) ] ) ).then( Object.fromEntries );
		}
		// The design's shapes must be ready before a page is drawn.
		return pdfFonts.then( async ( fonts ) => {
			await artReady;
			return fonts;
		} );
	}

	function newDoc( fonts ) {
		const doc = new window.jspdf.jsPDF( { orientation: W > H ? 'landscape' : 'portrait', unit: 'mm', format: [ W, H ], compress: true } );
		Object.keys( fonts ).forEach( ( key ) => {
			const file = key.replace( ':', '-' ) + '.ttf';
			doc.addFileToVFS( file, fonts[ key ] );
			doc.addFont( file, key.replace( ':', '-' ), 'normal' );
		} );
		return doc;
	}

	function pdfBackend( doc ) {
		const use = ( f, size ) => {
			doc.setFont( f.replace( ':', '-' ), 'normal' );
			doc.setFontSize( size );
		};
		return {
			fonts: usedFaces(),
			alpha( a ) {
				doc.setGState( new doc.GState( { opacity: a, 'stroke-opacity': a } ) );
			},
			rect( x, y, w, h, c ) {
				doc.setFillColor( c );
				doc.rect( x, y, w, h, 'F' );
			},
			line( x1, y1, x2, y2, c, width ) {
				doc.setDrawColor( c );
				doc.setLineWidth( width );
				doc.line( x1, y1, x2, y2 );
			},
			measure( text, f, size, spacing = 0 ) {
				use( f, size );
				return doc.getTextWidth( text ) + spacing * text.length;
			},
			text( text, x, y, o ) {
				use( o.font, o.size );
				doc.setTextColor( o.color );
				doc.text( text, x, y, { angle: o.angle || 0, charSpace: o.cs || 0, baseline: 'alphabetic' } );
			},
			image( pic, x, y, w, h ) {
				if ( pic.src ) {
					doc.addImage( pic.src, 'PNG', x, y, w, h, pic.alias, 'FAST' );
				}
			},
		};
	}

	function buildPdf( list, ev, fonts ) {
		const doc = newDoc( fonts );
		list.forEach( ( person, i ) => {
			if ( i ) {
				doc.addPage( [ W, H ], W > H ? 'landscape' : 'portrait' );
			}
			drawCertificate( pdfBackend( doc ), ev, person.name );
		} );
		return doc.output( 'blob' );
	}

	/* ---------- Preview ---------- */
	let frame = 0;

	function renderPreview() {
		cancelAnimationFrame( frame );
		frame = requestAnimationFrame( () => {
			const canvas = $( 'canvas' );
			const width = canvas.parentElement.getBoundingClientRect().width;
			const ratio = Math.min( window.devicePixelRatio || 1, 2 );
			canvas.width = Math.round( width * ratio );
			canvas.height = Math.round( ( width * ratio * H ) / W );
			const person = people[ current ];
			drawCertificate( canvasBackend( canvas.getContext( '2d' ), canvas.width / W ), readEvent(), person ? person.name : '', true );
		} );
	}

	/* ---------- Participants ---------- */
	function renderPeople() {
		const body = $( 'people-body' );
		body.textContent = '';
		people.forEach( ( person, i ) => {
			const row = document.createElement( 'tr' );
			row.className = i === current ? 'sel' : '';
			const cell1 = document.createElement( 'td' );
			cell1.className = 'c';
			const box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.checked = person.on;
			box.setAttribute( 'aria-label', fmt( t.include, person.name ) );
			box.addEventListener( 'change', () => {
				person.on = box.checked;
				updateCount();
			} );
			cell1.append( box );
			const cell2 = document.createElement( 'td' );
			const input = document.createElement( 'input' );
			input.type = 'text';
			input.value = person.name;
			input.setAttribute( 'aria-label', t.nameLabel );
			input.addEventListener( 'input', () => {
				person.name = input.value;
				if ( i === current ) {
					renderPreview();
				}
			} );
			input.addEventListener( 'focus', () => select( i ) );
			cell2.append( input );
			if ( person.tag ) {
				const tag = document.createElement( 'span' );
				tag.className = 'tag';
				tag.textContent = person.tag;
				cell2.append( ' ', tag );
			}
			const cell3 = document.createElement( 'td' );
			cell3.className = 'a';
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'small';
			button.textContent = 'PDF';
			button.setAttribute( 'aria-label', fmt( t.downloadFor, person.name ) );
			button.addEventListener( 'click', () => {
				select( i );
				downloadOne( i );
			} );
			cell3.append( button );
			row.append( cell1, cell2, cell3 );
			row.addEventListener( 'click', ( e ) => {
				if ( 'INPUT' !== e.target.tagName && 'BUTTON' !== e.target.tagName ) {
					select( i );
				}
			} );
			body.append( row );
		} );
		if ( ! people.length ) {
			const row = document.createElement( 'tr' );
			const cell = document.createElement( 'td' );
			cell.colSpan = 3;
			cell.className = 'empty';
			cell.textContent = t.noPeople;
			row.append( cell );
			body.append( row );
		}
		updateCount();
	}

	function select( i ) {
		current = i;
		[ ...$( 'people-body' ).children ].forEach( ( row, j ) => row.classList.toggle( 'sel', j === i ) );
		renderPreview();
	}

	function updateCount() {
		const n = people.filter( ( p ) => p.on && p.name.trim() ).length;
		$( 'count' ).textContent = fmt( t.selectedOf, n, people.length );
		$( 'toggle-all' ).textContent = people.every( ( p ) => p.on ) ? t.deselectAll : t.selectAll;
	}

	function setPeople( list ) {
		const seen = new Set();
		people = [];
		list.forEach( ( person ) => {
			const key = person.name.trim().toLowerCase();
			if ( key && ! seen.has( key ) ) {
				seen.add( key );
				people.push( { ...person, name: person.name.trim() } );
			}
		} );
		current = 0;
		renderPeople();
		renderPreview();
	}

	/* ---------- Import (Meetup's export: CSV, XLS or XLSX) ---------- */
	const NAME = /^(name|nome|nome completo|full name)$/i;
	const NO = /^(no|não|nao|n|false|0|not going)$/i;

	function parseRows( rows ) {
		const header = rows.findIndex( ( row ) => row.some( ( cell ) => NAME.test( String( cell ).trim() ) ) );
		if ( header < 0 ) {
			return { list: rows.map( ( row ) => ( { name: String( row.find( ( cell ) => String( cell ).trim() ) || '' ), on: true } ) ).filter( ( p ) => p.name ), info: t.noHeader };
		}
		const head = rows[ header ].map( ( cell ) => String( cell ).trim() );
		const nameAt = head.findIndex( ( cell ) => NAME.test( cell ) );
		const rsvpAt = head.findIndex( ( cell ) => /^(rsvp|resposta|response)$/i.test( cell ) );
		const seenAt = head.findIndex( ( cell ) => /(attend|presen|compareceu|check.?in)/i.test( cell ) );
		const list = [];
		let skipped = 0;
		let absent = 0;
		rows.slice( header + 1 ).forEach( ( row ) => {
			const name = String( row[ nameAt ] ?? '' ).trim();
			if ( ! name ) {
				return;
			}
			if ( rsvpAt >= 0 && NO.test( String( row[ rsvpAt ] ?? '' ).trim() ) ) {
				skipped++;
				return;
			}
			let on = true;
			let tag = '';
			const seen = seenAt >= 0 ? String( row[ seenAt ] ?? '' ).trim() : '';
			if ( seen && ( NO.test( seen ) || /absent|no.?show|ausente|faltou/i.test( seen ) ) ) {
				on = false;
				tag = t.didNotAttend;
				absent++;
			}
			list.push( { name, on, tag } );
		} );
		const info = [ fmt( t.imported, list.length ) ];
		if ( skipped ) {
			info.push( fmt( t.rsvpNo, skipped ) );
		}
		if ( absent ) {
			info.push( fmt( t.absent, absent ) );
		}
		return { list, info: info.join( ' · ' ) + '.' };
	}

	async function importFile( file ) {
		const status = $( 'import-status' );
		try {
			const book = window.XLSX.read( await file.arrayBuffer(), { type: 'array', codepage: 65001 } );
			const rows = window.XLSX.utils.sheet_to_json( book.Sheets[ book.SheetNames[ 0 ] ], { header: 1, defval: '', raw: false } );
			const { list, info } = parseRows( rows );
			if ( ! list.length ) {
				status.textContent = t.noNames;
				return;
			}
			setPeople( list );
			status.textContent = file.name + ': ' + info;
		} catch ( e ) {
			status.textContent = t.readError;
		}
	}

	/* ---------- Downloads ---------- */
	function saveFile( blob, filename ) {
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = filename;
		document.body.append( link );
		link.click();
		link.remove();
		setTimeout( () => URL.revokeObjectURL( link.href ), 4000 );
	}

	function eventSlug( ev ) {
		return slug( ( ev.title || t.fileMeetup ).split( ':' )[ 0 ] );
	}

	function missing( ev ) {
		const what = [];
		if ( ! ev.title ) {
			what.push( t.theTitle );
		}
		if ( ! ev.date ) {
			what.push( t.theDate );
		}
		if ( what.length ) {
			$( 'gen-status' ).textContent = fmt( t.fill, what.join( ' ' + t.and + ' ' ) );
		}
		return what.length > 0;
	}

	const selected = () => people.filter( ( p ) => p.on && p.name.trim() );

	function busy( on, message ) {
		[ 'btn-zip', 'btn-all', 'btn-one' ].forEach( ( id ) => {
			$( id ).disabled = on;
		} );
		$( 'gen-status' ).textContent = message || '';
	}

	const pause = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	async function downloadOne( index ) {
		const person = people[ index ?? current ];
		const ev = readEvent();
		if ( ! person || ! person.name.trim() || missing( ev ) ) {
			return;
		}
		busy( true, t.generating );
		try {
			saveFile( buildPdf( [ person ], ev, await loadPdfFonts() ), t.fileOne + '-' + eventSlug( ev ) + '-' + slug( person.name ) + '.pdf' );
			busy( false, fmt( t.pdfReady, person.name ) );
		} catch ( e ) {
			busy( false, t.failed );
		}
	}

	async function downloadAll() {
		const list = selected();
		const ev = readEvent();
		if ( ! list.length ) {
			$( 'gen-status' ).textContent = t.pickOne;
			return;
		}
		if ( missing( ev ) ) {
			return;
		}
		busy( true, t.generating );
		await pause( 30 );
		try {
			saveFile( buildPdf( list, ev, await loadPdfFonts() ), t.fileMany + '-' + eventSlug( ev ) + '.pdf' );
			busy( false, fmt( t.pdfAllReady, list.length ) );
		} catch ( e ) {
			busy( false, t.failed );
		}
	}

	async function downloadZip() {
		const list = selected();
		const ev = readEvent();
		if ( ! list.length ) {
			$( 'gen-status' ).textContent = t.pickOne;
			return;
		}
		if ( missing( ev ) ) {
			return;
		}
		busy( true, fmt( t.progress, 0, list.length ) );
		try {
			const fonts = await loadPdfFonts();
			const zip = new window.JSZip();
			const used = new Set();
			for ( let i = 0; i < list.length; i++ ) {
				let name = t.fileOne + '-' + eventSlug( ev ) + '-' + slug( list[ i ].name );
				let n = 2;
				while ( used.has( name ) ) {
					name = name.replace( /-\d+$/, '' ) + '-' + n++;
				}
				used.add( name );
				zip.file( name + '.pdf', buildPdf( [ list[ i ] ], ev, fonts ) );
				if ( i % 5 === 4 ) {
					$( 'gen-status' ).textContent = fmt( t.progress, i + 1, list.length );
					await pause( 0 );
				}
			}
			saveFile( await zip.generateAsync( { type: 'blob' } ), t.fileMany + '-' + eventSlug( ev ) + '.zip' );
			busy( false, fmt( t.zipReady, list.length ) );
		} catch ( e ) {
			busy( false, t.failed );
		}
	}

	/* ---------- Start ---------- */
	async function init() {
		// The design's shapes, drawn once (the colors follow the block's accent).
		if ( cfg.design && window.evcertDesign ) {
			artReady = window.evcertDesign.prepare( themed() ).then( ( found ) => {
				art = found;
				renderPreview();
			} ).catch( () => {} );
		}
		const query = new URLSearchParams( window.location.search );
		Object.keys( FIELDS ).forEach( ( key ) => {
			const input = $( FIELDS[ key ] );
			if ( query.has( key ) ) {
				input.value = query.get( key );
			}
			input.addEventListener( 'input', renderPreview );
		} );
		if ( query.has( 'showHours' ) ) {
			$( 'show-hours' ).checked = '0' !== query.get( 'showHours' );
		}
		$( 'show-hours' ).addEventListener( 'change', renderPreview );
		setPeople( [] );
		thumb( 'sign-thumb', '', '', t.noSignature );
		thumb( 'logo-thumb', '', '', t.noLogo );
		await Promise.all( usedFaces().map( ( face ) => document.fonts.load( window.evcertDesign.css( face, 16 ) ) ) ).catch( () => {} );
		renderPreview();
		const defaultLogo = $( 'logo-field' ) ? $( 'logo-field' ).dataset.src : '';
		if ( defaultLogo ) {
			loadLogo( defaultLogo );
		}

		$( 'sign-file' ).addEventListener( 'change', async ( e ) => {
			if ( e.target.files[ 0 ] ) {
				await loadSignature( await readAsDataURL( e.target.files[ 0 ] ) );
				renderPreview();
			}
		} );
		$( 'sign-remove' ).addEventListener( 'click', async () => {
			$( 'sign-file' ).value = '';
			await loadSignature( '' );
			renderPreview();
		} );
		$( 'logo-file' ).addEventListener( 'change', async ( e ) => {
			if ( e.target.files[ 0 ] ) {
				loadLogo( await readAsDataURL( e.target.files[ 0 ] ) );
			}
		} );
		$( 'logo-remove' ).addEventListener( 'click', () => {
			$( 'logo-file' ).value = '';
			loadLogo( '' );
		} );
		$( 'list-file' ).addEventListener( 'change', ( e ) => {
			if ( e.target.files[ 0 ] ) {
				importFile( e.target.files[ 0 ] );
			}
		} );
		const drop = $( 'drop' );
		drop.addEventListener( 'dragover', ( e ) => {
			e.preventDefault();
			drop.classList.add( 'over' );
		} );
		drop.addEventListener( 'dragleave', () => drop.classList.remove( 'over' ) );
		drop.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			drop.classList.remove( 'over' );
			if ( e.dataTransfer.files[ 0 ] ) {
				importFile( e.dataTransfer.files[ 0 ] );
			}
		} );
		$( 'add-manual' ).addEventListener( 'click', () => {
			const names = $( 'manual' ).value.split( /\n/ ).map( ( s ) => s.trim() ).filter( Boolean );
			if ( names.length ) {
				setPeople( [ ...people, ...names.map( ( name ) => ( { name, on: true } ) ) ] );
				$( 'manual' ).value = '';
				$( 'import-status' ).textContent = fmt( t.added, names.length );
			}
		} );
		$( 'toggle-all' ).addEventListener( 'click', () => {
			const all = people.every( ( p ) => p.on );
			people.forEach( ( p ) => {
				p.on = ! all;
			} );
			renderPeople();
		} );
		$( 'btn-zip' ).addEventListener( 'click', downloadZip );
		$( 'btn-all' ).addEventListener( 'click', downloadAll );
		$( 'btn-one' ).addEventListener( 'click', () => downloadOne() );
		const tutorial = $( 'tutorial' );
		const box = $( 'lightbox' );
		const link = document.querySelector( '.evcert-app .tut-link a' );
		if ( link && tutorial ) {
			link.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				tutorial.open = true;
				tutorial.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} );
		}
		if ( box ) {
			document.querySelectorAll( '.evcert-app .tut img' ).forEach( ( shot ) => shot.addEventListener( 'click', () => {
				const big = document.createElement( 'img' );
				big.src = shot.src;
				big.alt = shot.alt;
				box.textContent = '';
				box.append( big );
				box.hidden = false;
			} ) );
			box.addEventListener( 'click', () => {
				box.hidden = true;
			} );
			document.addEventListener( 'keydown', ( e ) => {
				if ( 'Escape' === e.key ) {
					box.hidden = true;
				}
			} );
		}
		new ResizeObserver( renderPreview ).observe( $( 'canvas' ).parentElement );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
