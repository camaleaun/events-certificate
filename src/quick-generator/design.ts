/**
 * Draws the site's default design (the certificate template) on a canvas or a
 * jsPDF page, in the browser: the public generator makes its certificates from
 * the same design the server draws, not from a layout of its own. The two
 * backends (assets/es/quick-generator.js) take millimetres and three fonts;
 * what they can't draw (SVG art, ellipses, rounded corners) is rasterized once.
 *
 * Not drawn: the parts that vouch for a record (the QR code, the code and its
 * hint): the generator keeps none, as the server leaves them out on a site
 * where they would mean nothing.
 */
import { box, color, pageBg, rectPath, resolve, runsIn, type Design, type Element, type Run, type TextEl } from '../editor/model';

/** Points to millimetres. */
const PT = 0.3528;

/** A picture the backends can draw: a PNG as a data URL, the image, and its width ÷ height. */
export type Pic = { src: string; img?: HTMLImageElement; ratio: number; alias: string; rot?: { w: number; h: number } };

/** A face of a font, "Family:weight" (Lora:500, CertSans:600). */
type Font = string;

type Backend = {
	/** The faces the backend has (all the design's fonts that the site ships); the others fall back to the generator's two. */
	fonts: string[];
	/** Everything drawn next is this opaque (0 to 1). */
	alpha( a: number ): void;
	rect( x: number, y: number, w: number, h: number, c: string ): void;
	line( x1: number, y1: number, x2: number, y2: number, c: string, width: number ): void;
	measure( text: string, font: Font, size: number, spacing?: number ): number;
	text( text: string, x: number, y: number, o: { font: Font; size: number; color: string; cs?: number; angle?: number } ): void;
	image( pic: Pic, x: number, y: number, w: number, h: number ): void;
};

/** What a field says: its text, in the format a run asks for. */
export type Value = ( field: string, format?: string ) => string;

/** Pictures that come with the certificate: the signer's signature, the logo, and the rasterized art. */
export type Pictures = { signature?: Pic | null; logo?: Pic | null; art?: Record< string, Pic > };

/** Slots of the parts that vouch for a record. */
const VOUCHING = [ 'authenticity', 'code', 'verify' ];

const SERIF = /serif|lora|georgia|times|garamond/i;

/** The face of a design font and weight: the font itself when the site ships that weight of it, else the generator's own serif or sans. */
function fontOf( b: Backend, el: TextEl ): Font {
	const family = ( el.font || '' ).replace( /['"]/g, '' ).split( ',' )[ 0 ].trim();
	const want = el.weight || 400;
	const weight = [ 400, 500, 600 ].reduce( ( best, w ) => ( Math.abs( w - want ) < Math.abs( best - want ) ? w : best ), 400 );
	const face = `${ family }:${ weight }`;
	if ( b.fonts.includes( face ) ) {
		return face;
	}
	return SERIF.test( family ) && ! /sans/i.test( family ) ? 'CertSerif:400' : want >= 600 ? 'CertSans:600' : 'CertSans:400';
}

/** The CSS font of a face at a size in pixels, with a generic family to fall back on. */
export function cssFont( face: Font, px: number ): string {
	const [ family, weight ] = face.split( ':' );
	return `${ weight } ${ px }px "${ family }", ${ SERIF.test( family ) && ! /sans/i.test( family ) ? 'Georgia, serif' : 'system-ui, sans-serif' }`;
}

const METRICS: Record< string, { ascent: number; descent: number } > = {};

/** A face's ascent and descent in font sizes, as the browser lays its lines out (a canvas knows them); fixed values where there is no canvas. */
function metricsOf( face: Font ): { ascent: number; descent: number } {
	if ( METRICS[ face ] ) {
		return METRICS[ face ];
	}
	let found = { ascent: SERIF.test( face ) ? 0.99 : 1.025, descent: SERIF.test( face ) ? 0.31 : 0.275 };
	if ( typeof document !== 'undefined' ) {
		const ctx = document.createElement( 'canvas' ).getContext( '2d' );
		if ( ctx ) {
			ctx.font = cssFont( face, 100 );
			const m = ctx.measureText( 'Hg' );
			if ( m.fontBoundingBoxAscent ) {
				found = { ascent: m.fontBoundingBoxAscent / 100, descent: m.fontBoundingBoxDescent / 100 };
				// Only what a loaded font says is kept (an unloaded one answers with its fallback's).
				if ( document.fonts && document.fonts.check( cssFont( face, 16 ) ) ) {
					METRICS[ face ] = found;
				}
			}
		}
	}
	return found;
}

/** The faces a design's texts use, among the ones the site ships (for the PDF to embed and the browser to load). */
export function fontsUsed( design: Design, shipped: string[] ): string[] {
	const probe = { fonts: shipped } as Backend;
	const faces = new Set< string >( [ 'CertSerif:400', 'CertSans:400', 'CertSans:600' ] );
	design.elements.forEach( ( raw ) => {
		const el = resolve( raw, design.page );
		if ( 'text' === el.type ) {
			faces.add( fontOf( probe, el as TextEl ) );
		}
	} );
	return [ ...faces ].filter( ( face ) => shipped.includes( face ) );
}

/** The text of a text element's runs. */
function textOf( runs: Run[] | undefined, value: Value ): string {
	return ( runs || [] ).map( ( run ) => ( 'field' in run ? value( run.field, run.format ) : run.text ) ).join( '' );
}

/** The labels with nothing under them: "x-label" whose "x-value" says nothing is left out, and its column with it. */
function orphanLabels( design: Design, value: Value ): Set< string > {
	const texts = new Map< string, TextEl >();
	design.elements.forEach( ( raw ) => {
		const el = resolve( raw, design.page );
		if ( 'text' === el.type && el.id ) {
			texts.set( el.id, el as TextEl );
		}
	} );
	const orphans = new Set< string >();
	texts.forEach( ( _el, id ) => {
		const pair = id.endsWith( '-label' ) ? texts.get( id.slice( 0, -6 ) + '-value' ) : undefined;
		if ( pair && ! pair.hidden && '' === textOf( pair.runs, value ).trim() ) {
			orphans.add( id );
		}
	} );
	return orphans;
}

/** Words wrapped to a width, in millimetres. */
function wrap( b: Backend, text: string, font: Font, size: number, max: number, spacing: number ): string[] {
	const lines: string[] = [];
	text.split( '\n' ).forEach( ( paragraph ) => {
		let line = '';
		paragraph.split( /\s+/ ).filter( Boolean ).forEach( ( word ) => {
			const attempt = line ? line + ' ' + word : word;
			if ( ! line || b.measure( attempt, font, size, spacing ) <= max ) {
				line = attempt;
			} else {
				lines.push( line );
				line = word;
			}
		} );
		lines.push( line );
	} );
	return lines;
}

function isFilled( value: string ): boolean {
	return !! value && 'none' !== value && 'transparent' !== value;
}

/** Draws a text element. */
function drawText( b: Backend, design: Design, el: TextEl, value: Value, locale: string ): void {
	let text = textOf( runsIn( el, locale ), value );
	if ( el.upper ) {
		text = text.toLocaleUpperCase( locale );
	}
	if ( '' === text.trim() ) {
		return;
	}
	const font = fontOf( b, el );
	const spacing = ( el.ls || 0 ) * PT;
	const width = el.w * PT;
	let size = el.size || 14;
	if ( el.fit ) {
		const natural = b.measure( text.replace( /\n/g, ' ' ), font, size, spacing );
		if ( natural > width && natural > 0 ) {
			size = Math.max( el.min || 8, ( size * width ) / natural );
		}
	}
	const lh = el.lh || 1.2;
	const lines = el.fit ? [ text.replace( /\n/g, ' ' ) ] : wrap( b, text, font, size, width, spacing );
	// The browser centres the font's own ascent + descent in the line: the first baseline sits half the leading plus the ascent below the box top.
	const m = metricsOf( font );
	const base = size * ( ( lh - ( m.ascent + m.descent ) ) / 2 + m.ascent ) * PT;
	const pitch = size * lh * PT;
	const left = el.x * PT;
	const top = el.y * PT;
	const cx = left + width / 2;
	const cy = top + ( el.h * PT ) / 2;
	const turn = ( ( el.rotation || 0 ) * Math.PI ) / 180;
	const ink = color( design, el.color || '$text', 'light' );

	lines.forEach( ( line, i ) => {
		const w = b.measure( line, font, size, spacing );
		const x = 'center' === el.align ? left + ( width - w ) / 2 : 'right' === el.align ? left + width - w : left;
		const y = top + base + i * pitch;
		if ( ! turn ) {
			b.text( line, x, y, { font, size, color: ink, cs: spacing } );
			return;
		}
		// Turned about the box's centre, as the browser turns it; the backends turn counter-clockwise.
		const dx = x - cx;
		const dy = y - cy;
		b.text( line, cx + dx * Math.cos( turn ) - dy * Math.sin( turn ), cy + dx * Math.sin( turn ) + dy * Math.cos( turn ), { font, size, color: ink, cs: spacing, angle: -( el.rotation || 0 ) } );
	} );
}

/** The SVG of an element the backends can't draw, or '' when they can. */
function artOf( design: Design, el: Element ): { svg: string; w: number; h: number } | null {
	const fill = ( value: string | undefined ) => color( design, value, 'light' );
	if ( el.type === 'svg' ) {
		const paths = ( el.paths || [] ).map( ( p ) => `<path d="${ p.d }" fill="${ isFilled( fill( p.fill ) ) ? fill( p.fill ) : fill( el.color || '$text' ) }"${ 'evenodd' === p.rule ? ' fill-rule="evenodd"' : '' }/>` ).join( '' );
		return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ el.vw } ${ el.vh }" width="${ el.w }" height="${ el.h }">${ paths }</svg>`, w: el.w, h: el.h };
	}
	if ( el.type === 'path' ) {
		return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ el.vw } ${ el.vh }" width="${ el.w }" height="${ el.h }"><path d="${ el.d }" fill="${ fill( el.fill ) }" stroke="${ fill( el.stroke ) }" stroke-width="${ el.strokeWidth || 0 }"/></svg>`, w: el.w, h: el.h };
	}
	if ( el.type === 'ellipse' ) {
		return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ el.w } ${ el.h }" width="${ el.w }" height="${ el.h }"><ellipse cx="${ el.w / 2 }" cy="${ el.h / 2 }" rx="${ el.w / 2 }" ry="${ el.h / 2 }" fill="${ fill( el.fill ) }"/></svg>`, w: el.w, h: el.h };
	}
	if ( el.type === 'rect' && ( ( el.radius && el.radius.some( Boolean ) ) || el.rotation ) ) {
		const sw = el.strokeWidth || 0;
		return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${ -sw / 2 } ${ -sw / 2 } ${ el.w + sw } ${ el.h + sw }" width="${ el.w + sw }" height="${ el.h + sw }"><path d="${ rectPath( 0, 0, el.w, el.h, el.radius, el.corner ) }" fill="${ fill( el.fill ) }" stroke="${ fill( el.stroke ) }" stroke-width="${ sw }"/></svg>`, w: el.w + sw, h: el.h + sw };
	}
	return null;
}

/** An SVG as a PNG (canvas and jsPDF get the same picture); about 4 pixels to the point. */
function rasterize( svg: string, w: number, h: number, alias: string ): Promise< Pic > {
	return new Promise( ( resolveImage, reject ) => {
		const img = new Image();
		img.onload = () => {
			const scale = 4;
			const canvas = document.createElement( 'canvas' );
			canvas.width = Math.max( 1, Math.round( w * scale ) );
			canvas.height = Math.max( 1, Math.round( h * scale ) );
			canvas.getContext( '2d' )?.drawImage( img, 0, 0, canvas.width, canvas.height );
			const src = canvas.toDataURL( 'image/png' );
			const png = new Image();
			png.onload = () => resolveImage( { src, img: png, ratio: w / h, alias } );
			png.src = src;
		};
		img.onerror = reject;
		img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
	} );
}

/** A picture turned about its centre by an angle (degrees, clockwise): the bitmap of its bounding box, with that box's size in points. */
function rotated( pic: Pic, angle: number, w: number, h: number ): Promise< Pic > {
	return new Promise( ( resolveImage ) => {
		const img = pic.img;
		if ( ! img ) {
			resolveImage( pic );
			return;
		}
		const rad = ( angle * Math.PI ) / 180;
		const cos = Math.abs( Math.cos( rad ) );
		const sin = Math.abs( Math.sin( rad ) );
		const bw = w * cos + h * sin;
		const bh = w * sin + h * cos;
		const scale = img.naturalWidth / w;
		const canvas = document.createElement( 'canvas' );
		canvas.width = Math.max( 1, Math.round( bw * scale ) );
		canvas.height = Math.max( 1, Math.round( bh * scale ) );
		const ctx = canvas.getContext( '2d' );
		if ( ctx ) {
			ctx.translate( canvas.width / 2, canvas.height / 2 );
			ctx.rotate( rad );
			ctx.drawImage( img, -img.naturalWidth / 2, -img.naturalHeight / 2 );
		}
		const src = canvas.toDataURL( 'image/png' );
		const png = new Image();
		png.onload = () => resolveImage( { src, img: png, ratio: bw / bh, alias: pic.alias + '-r', rot: { w: bw, h: bh } } );
		png.src = src;
	} );
}

/** Rasterizes the art of a design (SVG shapes, rounded or turned rectangles), once, by element id. */
export async function prepare( design: Design ): Promise< Record< string, Pic > > {
	const art: Record< string, Pic > = {};
	for ( const raw of design.elements ) {
		const el = resolve( raw, design.page );
		if ( el.hidden || ( 'svg' === el.type && ( 'signature' === el.slot || 'signature' === el.id ) ) ) {
			continue;
		}
		const found = artOf( design, el );
		if ( found ) {
			try {
				const flat = await rasterize( found.svg, found.w, found.h, 'art-' + el.id );
				// A turned shape is turned once here, so the backends only ever place a picture.
				art[ el.id ] = el.rotation ? await rotated( flat, el.rotation, found.w, found.h ) : flat;
			} catch ( e ) {
				// A shape that can't be drawn is left out; the certificate still comes out.
			}
		}
	}
	return art;
}

/**
 * Draws the design: the page, then each element in order.
 *
 * @param b        Canvas or jsPDF backend.
 * @param design   The default design.
 * @param value    What each field says.
 * @param pictures Signature, logo and art.
 * @param locale   For capital letters.
 */
export function draw( b: Backend, design: Design, value: Value, pictures: Pictures, locale: string ): void {
	const page = design.page;
	const bg = color( design, pageBg( page, 'light' ), 'light' );
	b.rect( 0, 0, page.w * PT, page.h * PT, isFilled( bg ) ? bg : '#ffffff' );
	let logoDrawn = false;
	const orphans = orphanLabels( design, value );

	for ( const raw of design.elements ) {
		const el = resolve( raw, page );
		if ( el.hidden || VOUCHING.includes( el.slot || '' ) || orphans.has( el.id ) ) {
			continue;
		}
		// Every element is as opaque as it says (the next one sets its own).
		b.alpha( el.opacity === undefined ? 1 : el.opacity );
		const art = pictures.art && pictures.art[ el.id ];
		if ( art ) {
			const at = box( el );
			if ( art.rot ) {
				const cx = at.x + at.w / 2;
				const cy = at.y + at.h / 2;
				b.image( art, ( cx - art.rot.w / 2 ) * PT, ( cy - art.rot.h / 2 ) * PT, art.rot.w * PT, art.rot.h * PT );
			} else {
				b.image( art, at.x * PT, at.y * PT, at.w * PT, at.h * PT );
			}
			continue;
		}
		switch ( el.type ) {
			case 'text':
				drawText( b, design, el, value, locale );
				break;
			case 'rect': {
				const fill = color( design, el.fill, 'light' );
				if ( isFilled( fill ) ) {
					b.rect( el.x * PT, el.y * PT, el.w * PT, el.h * PT, fill );
				}
				const stroke = color( design, el.stroke, 'light' );
				if ( isFilled( stroke ) && ( el.strokeWidth || 0 ) > 0 ) {
					const [ x, y, w, h ] = [ el.x * PT, el.y * PT, el.w * PT, el.h * PT ];
					const width = ( el.strokeWidth || 0 ) * PT;
					[ [ x, y, x + w, y ], [ x + w, y, x + w, y + h ], [ x + w, y + h, x, y + h ], [ x, y + h, x, y ] ].forEach( ( [ x1, y1, x2, y2 ] ) => b.line( x1, y1, x2, y2, stroke, width ) );
				}
				break;
			}
			case 'line':
				b.line( el.x1 * PT, el.y1 * PT, el.x2 * PT, el.y2 * PT, color( design, el.stroke || '$line', 'light' ), ( el.strokeWidth || 1 ) * PT );
				break;
			case 'svg':
				// The signer's signature, when the form has one: upright, filling the box from its left and bottom.
				if ( ( 'signature' === el.slot || 'signature' === el.id ) && pictures.signature ) {
					const w = Math.min( el.w, el.h * pictures.signature.ratio ) * PT;
					const h = w / pictures.signature.ratio;
					b.image( pictures.signature, el.x * PT, ( el.y + el.h ) * PT - h, w, h );
				}
				break;
			case 'image':
				if ( ( 'logo' === el.slot || 'logo' === el.id ) && pictures.logo ) {
					const ratio = pictures.logo.ratio;
					const w = Math.min( el.w * PT, el.h * PT * ratio );
					const h = w / ratio;
					b.image( pictures.logo, ( el.x + el.w ) * PT - w, el.y * PT + ( el.h * PT - h ) / 2, w, h );
					logoDrawn = true;
				}
				break;
			default:
				break;
		}
	}

	b.alpha( 1 );

	// A design with no place for the community's logo gets it in the top right corner, over the title's right edge.
	if ( pictures.logo && ! logoDrawn ) {
		const found = design.elements.map( ( e ) => resolve( e, page ) ).find( ( e ) => 'title' === e.id || 'title' === e.slot );
		const title = found ? box( found ) : null;
		const right = ( title ? title.x + title.w : page.w - ( page.safe || 28 ) ) * PT;
		let w = 62;
		let h = w / pictures.logo.ratio;
		if ( h > 18 ) {
			h = 18;
			w = h * pictures.logo.ratio;
		}
		b.image( pictures.logo, right - w, 20 + ( 18 - h ) / 2, w, h );
	}
}

declare global {
	interface Window {
		evcertDesign?: { draw: typeof draw; prepare: typeof prepare; css: typeof cssFont; fontsUsed: typeof fontsUsed };
	}
}
window.evcertDesign = { draw, prepare, css: cssFont, fontsUsed };
