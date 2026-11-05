/**
 * The design model: elements in pt on a fixed page, colors as variables with
 * Light and Dark values. The same JSON is drawn by the editor (SVG + HTML
 * text in foreignObject) and by the PDF renderer (dompdf reads the same
 * positioned HTML).
 */

export type Mode = 'light' | 'dark';

export type ColorVar = { name: string; light: string; dark: string };

export type Base = {
	id: string;
	name?: string;
	slot?: string;
	x: number;
	y: number;
	w: number;
	h: number;
	opacity?: number;
	rotation?: number;
	locked?: boolean;
	hidden?: boolean;
};

export type TextEl = Base & {
	type: 'text';
	text?: string;
	runs?: Run[];
	font: string;
	weight: number;
	size: number;
	color: string;
	align?: 'left' | 'center' | 'right';
	lh?: number;
	ls?: number;
	upper?: boolean;
	fit?: boolean;
	min?: number;
};
export type CornerShape = 'round' | 'scoop' | 'bevel' | 'notch' | 'squircle';
export type RectEl = Base & { type: 'rect'; fill?: string; stroke?: string; strokeWidth?: number; radius?: number[]; corner?: CornerShape };
export type SvgEl = Base & { type: 'svg'; markup: string; vw: number; vh: number; fill?: string };
export type EllipseEl = Base & { type: 'ellipse'; fill?: string; stroke?: string; strokeWidth?: number };
export type PathEl = Base & { type: 'path'; d: string; vw: number; vh: number; fill?: string; stroke?: string; strokeWidth?: number };
export type LineEl = Omit< Base, 'x' | 'y' | 'w' | 'h' > & {
	type: 'line';
	x1: number;
	y1: number;
	x2: number;
	y2: number;
	stroke?: string;
	strokeWidth?: number;
	dash?: 'solid' | 'dashed' | 'dotted';
	// Bounding box, derived (kept for a uniform API).
	x?: number;
	y?: number;
	w?: number;
	h?: number;
};
export type ImageEl = Base & { type: 'image'; src: string; fit?: 'contain' | 'cover' };
export type QrEl = Base & { type: 'qr' };

export type Element = TextEl | RectEl | EllipseEl | PathEl | LineEl | ImageEl | QrEl | SvgEl;

export type Design = {
	version: 1;
	page: { w: number; h: number; bg: string; safe: number; bleed: number };
	colors: Record< string, ColorVar >;
	elements: Element[];
};

export type Box = { x: number; y: number; w: number; h: number };

/** The element's box (lines: the box around both ends). */
export function box( el: Element ): Box {
	if ( el.type === 'line' ) {
		const x = Math.min( el.x1, el.x2 );
		const y = Math.min( el.y1, el.y2 );
		return { x, y, w: Math.abs( el.x2 - el.x1 ), h: Math.abs( el.y2 - el.y1 ) };
	}
	return { x: el.x, y: el.y, w: el.w, h: el.h };
}

/** Moves an element by dx, dy. */
export function moved< T extends Element >( el: T, dx: number, dy: number ): T {
	if ( el.type === 'line' ) {
		return { ...el, x1: el.x1 + dx, y1: el.y1 + dy, x2: el.x2 + dx, y2: el.y2 + dy };
	}
	return { ...el, x: ( el as Base ).x + dx, y: ( el as Base ).y + dy };
}

/** A color value: "$name" reads the variable in the mode; anything else is literal. */
export function color( design: Design, value: string | undefined, mode: Mode ): string {
	if ( ! value ) {
		return 'none';
	}
	if ( value.startsWith( '$' ) ) {
		const v = design.colors[ value.slice( 1 ) ];
		return v ? v[ mode ] : 'none';
	}
	return value;
}

/** Slots the certificate can't do without: they can be moved, not removed. */
export const REQUIRED_SLOTS = [ 'name', 'authenticity' ];

export const PLACEHOLDERS = [ 'name', 'event', 'location', 'issued_date', 'signer_name', 'signer_role', 'code', 'hours', 'role' ];

/**
 * Rich text with fields: a list of runs, plain text or a field (a chip in the
 * editor), each with its own marks. Stored as data, never as text with
 * braces, so a field can't be mistyped and formats travel with it.
 */
export type Run = { text: string; b?: boolean; i?: boolean } | { field: string; format?: string; b?: boolean; i?: boolean };

export type FieldDef = { key: string; label: string; kind?: 'date' | 'hours' | 'text'; formats?: { key: string; label: string }[] };

/** Fields a text can show. Labels are set by the editor (translated). */
export const FIELD_KEYS = [ 'name', 'event', 'location', 'issued_date', 'event_date', 'hours', 'role', 'details', 'signer_name', 'signer_role', 'code', 'document' ];

/** Old "{field} and **bold**" text, as runs. */
export function parseText( text: string ): Run[] {
	const runs: Run[] = [];
	const parts = text.split( /(\*\*.+?\*\*)/ );
	for ( const part of parts ) {
		const bold = /^\*\*(.+)\*\*$/.exec( part );
		const body = bold ? bold[ 1 ] : part;
		for ( const piece of body.split( /(\{[a-z_]+\})/ ) ) {
			if ( ! piece ) {
				continue;
			}
			const field = /^\{([a-z_]+)\}$/.exec( piece );
			const run: Run = field ? { field: field[ 1 ] } : { text: piece };
			if ( bold ) {
				run.b = true;
			}
			runs.push( run );
		}
	}
	return runs;
}

/** A text element's runs (from runs, or from its old text). */
export function runsOf( el: { runs?: Run[]; text?: string } ): Run[] {
	return el.runs || parseText( el.text || '' );
}

const esc = ( v: string ) => v.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

/** Formats a field's sample value. */
export function fieldValue( field: string, format: string | undefined, sample: Record< string, string >, locale: string ): string {
	if ( field === 'issued_date' || field === 'event_date' ) {
		// Default: the site's date format (Settings > General), formatted by WordPress.
		if ( ! format || format === 'site' ) {
			return sample.date_site || sample.date_iso;
		}
		const date = new Date( sample.date_iso || Date.now() );
		const options: Record< string, Intl.DateTimeFormatOptions > = {
			long: { day: 'numeric', month: 'long', year: 'numeric' },
			medium: { day: 'numeric', month: 'short', year: 'numeric' },
			short: { day: '2-digit', month: '2-digit', year: 'numeric' },
			month: { month: 'long', year: 'numeric' },
		};
		if ( format === 'iso' ) {
			return date.toISOString().slice( 0, 10 );
		}
		return new Intl.DateTimeFormat( locale, options[ format || 'long' ] || options.long ).format( date );
	}
	if ( field === 'hours' ) {
		const hours = sample.hours || '8';
		return format === 'short' ? `${ hours }h` : sample.hours_long || `${ hours } h`;
	}
	return sample[ field ] !== undefined ? sample[ field ] : field;
}

/** Runs as HTML with the sample values in place of the fields. */
export function runsHtml( runs: Run[], sample: Record< string, string >, locale: string, upper?: boolean ): string {
	return runs
		.map( ( run ) => {
			let html = esc( 'field' in run ? fieldValue( run.field, run.format, sample, locale ) : run.text );
			if ( upper ) {
				html = html.toUpperCase();
			}
			if ( run.i ) {
				html = `<em>${ html }</em>`;
			}
			if ( run.b ) {
				html = `<strong>${ html }</strong>`;
			}
			return html;
		} )
		.join( '' );
}

let counter = 0;
/** A new element id. */
export function newId( type: string ): string {
	counter++;
	return `${ type }-${ Date.now().toString( 36 ) }-${ counter }`;
}

/** Default new elements, centred on the page. */
export function create( type: Element[ 'type' ], design: Design ): Element {
	const cx = design.page.w / 2;
	const cy = design.page.h / 2;
	const id = newId( type );
	switch ( type ) {
		case 'text':
			return { id, type, name: 'Text', x: cx - 120, y: cy - 10, w: 240, h: 20, runs: [ { text: 'Text' } ], font: 'Inter', weight: 400, size: 14, color: '$text', align: 'center', lh: 1.3 };
		case 'rect':
			return { id, type, name: 'Rectangle', x: cx - 60, y: cy - 40, w: 120, h: 80, fill: '$primary', stroke: '', strokeWidth: 1, radius: [ 0, 0, 0, 0 ] };
		case 'ellipse':
			return { id, type, name: 'Ellipse', x: cx - 40, y: cy - 40, w: 80, h: 80, fill: '$primary', stroke: '', strokeWidth: 1 };
		case 'line':
			return { id, type, name: 'Line', x1: cx - 100, y1: cy, x2: cx + 100, y2: cy, stroke: '$line', strokeWidth: 1, dash: 'solid' };
		case 'qr':
			return { id, type, name: 'Authenticity', slot: 'authenticity', x: cx - 24, y: cy - 24, w: 48, h: 48 };
		default:
			return { id, type: 'image', name: 'Image', x: cx - 60, y: cy - 60, w: 120, h: 120, src: '', fit: 'contain' };
	}
}

/**
 * A rectangle's outline as an SVG path, with each corner drawn in a shape like
 * CSS corner-shape (round, scoop, bevel, notch, squircle). A path, so the PDF
 * draws exactly what the editor shows. Radii: [top-left, top-right,
 * bottom-right, bottom-left], clamped to half the shorter side.
 */
export function rectPath( x: number, y: number, w: number, h: number, radius: number[] = [], shape: CornerShape = 'round' ): string {
	const r = [ 0, 1, 2, 3 ].map( ( i ) => Math.max( 0, Math.min( radius[ i ] || 0, w / 2, h / 2 ) ) );
	const f = ( n: number ) => Math.round( n * 100 ) / 100;
	// Corner points and the directions along the edges into and out of each corner.
	const corners = [
		{ c: [ x, y ], in: [ 0, 1 ], out: [ 1, 0 ] }, // top-left: coming up the left edge, leaving right.
		{ c: [ x + w, y ], in: [ -1, 0 ], out: [ 0, 1 ] },
		{ c: [ x + w, y + h ], in: [ 0, -1 ], out: [ -1, 0 ] },
		{ c: [ x, y + h ], in: [ 1, 0 ], out: [ 0, -1 ] },
	];
	let d = '';
	corners.forEach( ( { c, in: din, out }, i ) => {
		const rr = r[ i ];
		const start = [ c[ 0 ] + din[ 0 ] * rr, c[ 1 ] + din[ 1 ] * rr ];
		const end = [ c[ 0 ] + out[ 0 ] * rr, c[ 1 ] + out[ 1 ] * rr ];
		d += ( i === 0 ? 'M' : 'L' ) + `${ f( start[ 0 ] ) } ${ f( start[ 1 ] ) }`;
		if ( ! rr ) {
			return;
		}
		switch ( shape ) {
			case 'scoop':
				d += `A${ f( rr ) } ${ f( rr ) } 0 0 0 ${ f( end[ 0 ] ) } ${ f( end[ 1 ] ) }`;
				break;
			case 'bevel':
				d += `L${ f( end[ 0 ] ) } ${ f( end[ 1 ] ) }`;
				break;
			case 'notch': {
				const inner = [ start[ 0 ] + end[ 0 ] - c[ 0 ], start[ 1 ] + end[ 1 ] - c[ 1 ] ];
				d += `L${ f( inner[ 0 ] ) } ${ f( inner[ 1 ] ) }L${ f( end[ 0 ] ) } ${ f( end[ 1 ] ) }`;
				break;
			}
			case 'squircle': {
				const k = 0.18;
				const c1 = [ c[ 0 ] + ( start[ 0 ] - c[ 0 ] ) * k, c[ 1 ] + ( start[ 1 ] - c[ 1 ] ) * k ];
				const c2 = [ c[ 0 ] + ( end[ 0 ] - c[ 0 ] ) * k, c[ 1 ] + ( end[ 1 ] - c[ 1 ] ) * k ];
				d += `C${ f( c1[ 0 ] ) } ${ f( c1[ 1 ] ) } ${ f( c2[ 0 ] ) } ${ f( c2[ 1 ] ) } ${ f( end[ 0 ] ) } ${ f( end[ 1 ] ) }`;
				break;
			}
			default:
				d += `A${ f( rr ) } ${ f( rr ) } 0 0 1 ${ f( end[ 0 ] ) } ${ f( end[ 1 ] ) }`;
		}
	} );
	return d + 'Z';
}

/**
 * SVG kept from pasted code: as little as possible, like the wp_kses() lists
 * themes use for their SVG icons — <svg> with its size and viewBox and paths
 * with their fill. Groups are unwrapped (their fill goes to their paths). No stroke, no other shapes, no styles, scripts,
 * links or embedded content. The server sanitizes again with the same list
 * (wp_kses) when the design is saved.
 */
export const SVG_ALLOWED: Record< string, string[] > = {
	svg: [ 'width', 'height', 'viewbox' ],
	path: [ 'd', 'fill', 'fill-rule', 'clip-rule' ],
};
export const SVG_TAGS = Object.keys( SVG_ALLOWED );

/** Sanitizes SVG code; returns the inner markup and the viewBox size, or null. */
export function sanitizeSvg( code: string ): { markup: string; vw: number; vh: number } | null {
	const doc = new DOMParser().parseFromString( code, 'image/svg+xml' );
	const root = doc.documentElement;
	if ( ! root || root.nodeName.toLowerCase() !== 'svg' || doc.querySelector( 'parsererror' ) ) {
		return null;
	}
	// Unwrap groups: their paths move up, inheriting the group's fill.
	for ( let group = root.querySelector( 'g' ); group; group = root.querySelector( 'g' ) ) {
		const fill = group.getAttribute( 'fill' );
		for ( const path of Array.from( group.querySelectorAll( 'path' ) ) ) {
			if ( fill && ! path.hasAttribute( 'fill' ) ) {
				path.setAttribute( 'fill', fill );
			}
		}
		group.replaceWith( ...Array.from( group.childNodes ) );
	}
	const clean = ( node: globalThis.Element ) => {
		for ( const child of Array.from( node.children ) ) {
			const tag = child.nodeName.toLowerCase();
			if ( tag === 'svg' || ! SVG_ALLOWED[ tag ] ) {
				child.remove();
				continue;
			}
			for ( const attr of Array.from( child.attributes ) ) {
				const name = attr.name.toLowerCase();
				const value = attr.value.trim();
				const color = name !== 'fill' || /^(none|currentcolor|#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i.test( value );
				if ( ! SVG_ALLOWED[ tag ].includes( name ) || ! color ) {
					child.removeAttribute( attr.name );
				}
			}
			clean( child );
		}
	};
	clean( root );
	const box = ( root.getAttribute( 'viewBox' ) || '' ).split( /[\s,]+/ ).map( Number );
	const vw = box.length === 4 && box[ 2 ] > 0 ? box[ 2 ] : Number( root.getAttribute( 'width' ) ) || 100;
	const vh = box.length === 4 && box[ 3 ] > 0 ? box[ 3 ] : Number( root.getAttribute( 'height' ) ) || 100;
	const markup = Array.from( root.childNodes )
		.map( ( n ) => ( n.nodeType === 1 ? new XMLSerializer().serializeToString( n ) : '' ) )
		.join( '' )
		.replace( / xmlns="http:\/\/www\.w3\.org\/2000\/svg"/g, '' );
	return { markup, vw, vh };
}

/** SVG markup with every fill (except "none") replaced, to recolor an icon with a color variable. */
export function recolor( markup: string, fill?: string ): string {
	return fill ? markup.replace( /fill="(?!none)[^"]*"/g, `fill="${ fill }"` ) : markup;
}
