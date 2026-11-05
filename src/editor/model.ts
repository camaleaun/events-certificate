/**
 * The design model: elements in pt on a fixed page, colors as variables with
 * Light and Dark values. The same JSON is drawn by the editor (SVG + HTML
 * text in foreignObject) and by the PDF renderer (dompdf reads the same
 * positioned HTML).
 */
import { __ } from '@wordpress/i18n';

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
	layout?: Layout;
	// Keeps width ÷ height: the size along one axis follows the other (images use their aspect ratio setting).
	lock?: number;
	// Per-device position, size and visibility for the web views (the print page uses the element's own).
	views?: Partial< Record< Screen, ElementView > >;
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
/** One path of an SVG element: its outline (path data) and fill (a color or a $variable; none = the current color). */
export type SvgPath = { d: string; fill?: string; rule?: 'nonzero' | 'evenodd' };
/** An SVG drawing kept as fields, never as markup: a viewBox size and a list of filled paths. */
/** title: names the drawing (the SVG's <title> and the image's alt text); empty for decoration. */
export type SvgEl = Base & { type: 'svg'; title?: string; vw: number; vh: number; paths: SvgPath[]; color?: string; markup?: string; fill?: string };
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
export type ImageEl = Base & { type: 'image'; src: string; fit?: 'contain' | 'cover'; alt?: string; attachment?: number; ratio?: string };
export type QrEl = Base & { type: 'qr' };

export type Element = TextEl | RectEl | EllipseEl | PathEl | LineEl | ImageEl | QrEl | SvgEl;

export type Design = {
	version: 1;
	page: { w: number; h: number; bg: string; safe: number; bleed: number };
	colors: Record< string, ColorVar >;
	elements: Element[];
	// Preview values for the fields, only for the editor (certificates use real data).
	sample?: Record< string, string >;
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
export const REQUIRED_SLOTS = [ 'name', 'authenticity', 'code', 'verify' ];

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
		if ( format === 'short' ) {
			return `${ hours }h`;
		}
		const pattern = ( Number( hours ) === 1 ? sample.hours_one : sample.hours_other ) || '%s h';
		return pattern.replace( '%s', hours );
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
		case 'svg':
			return { id, type, name: 'SVG', x: cx - 48, y: cy - 48, w: 96, h: 96, vw: 24, vh: 24, paths: [ { d: 'M12 2 22 12 12 22 2 12Z' } ] };
		default:
			return { id, type: 'image', name: 'Image', x: cx - 130, y: cy - 90, w: 260, h: 180, src: '', fit: 'cover', alt: '' };
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
 * SVG is kept as fields, like the wp_kses() lists themes use for their icons
 * but stricter: a viewBox size and paths with path data, a fill and a fill
 * rule. Pasted code is read into those fields; groups are unwrapped (their
 * fill goes to their paths); strokes, other shapes, styles, scripts, links
 * and embedded content are dropped. The server checks the fields again.
 */

/** Path data: only path commands and numbers, starting with a move. */
export function isPathData( d: string ): boolean {
	return /^[MmLlHhVvCcSsQqTtAaZz0-9eE.,\s+-]*$/.test( d ) && /^\s*[Mm]/.test( d );
}

/** A fill: a hex/rgb color, none, currentColor or a $variable. */
export function isFill( value: string ): boolean {
	return /^(none|currentcolor|#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|\$[a-z0-9_-]+)$/i.test( value.trim() );
}

/** Reads SVG code into fields; returns the viewBox size and the paths, or null. */
export function sanitizeSvg( code: string ): { vw: number; vh: number; paths: SvgPath[] } | null {
	const text = code.trim();
	const doc = new DOMParser().parseFromString( /^<svg[\s>]/i.test( text ) ? text : `<svg xmlns="http://www.w3.org/2000/svg">${ text }</svg>`, 'image/svg+xml' );
	const root = doc.documentElement;
	if ( ! root || root.nodeName.toLowerCase() !== 'svg' || doc.querySelector( 'parsererror' ) ) {
		return null;
	}
	const inherited = ( node: globalThis.Element, name: string ): string | null => {
		for ( let n: globalThis.Element | null = node; n; n = n.parentElement ) {
			if ( n.getAttribute( name ) ) {
				return n.getAttribute( name );
			}
		}
		return null;
	};
	const paths: SvgPath[] = [];
	for ( const node of Array.from( root.getElementsByTagName( 'path' ) ) ) {
		const d = ( node.getAttribute( 'd' ) || '' ).trim().replace( /\s+/g, ' ' );
		if ( ! d || ! isPathData( d ) ) {
			continue;
		}
		const fill = inherited( node, 'fill' );
		const rule = inherited( node, 'fill-rule' );
		paths.push( { d, ...( fill && isFill( fill ) && fill.toLowerCase() !== 'currentcolor' ? { fill } : {} ), ...( rule === 'evenodd' ? { rule: 'evenodd' as const } : {} ) } );
	}
	if ( ! paths.length ) {
		return null;
	}
	const box = ( root.getAttribute( 'viewBox' ) || '' ).split( /[\s,]+/ ).map( Number );
	const vw = box.length === 4 && box[ 2 ] > 0 ? box[ 2 ] : Number( root.getAttribute( 'width' ) ) || 100;
	const vh = box.length === 4 && box[ 3 ] > 0 ? box[ 3 ] : Number( root.getAttribute( 'height' ) ) || 100;
	return { vw, vh, paths };
}

/** The paths of an SVG element; designs saved before the fields kept markup (and one fill for all). */
export function svgPaths( el: SvgEl ): SvgPath[] {
	if ( el.paths ) {
		return el.paths;
	}
	const parsed = el.markup ? sanitizeSvg( el.markup ) : null;
	return ( parsed?.paths || [] ).map( ( p ) => ( el.fill ? { ...p, fill: el.fill } : p ) );
}

/** Moves SVG elements saved as markup to fields. */
/** Standard texts that are fields (FieldText fields(), group 'text'), by their English words. */
const PHRASES: Record< string, string > = {
	'Certificate of participation': 'title_text',
	'This is to certify that': 'intro_text',
	'Date of issue': 'issued_label',
	'Date of emission': 'issued_label',
	'Place and date': 'place_date_label',
	'Use the code above to verify at:': 'verify_hint',
	// The Base's sample signer role, before the signer had one.
	'Event Coordinator': 'signer_role',
};

/**
 * A text run that is a standard text, in English or in the admin's language,
 * becomes its field (with its trailing line break kept as text): designs
 * saved before these fields existed hold the words.
 */
function phraseRuns( runs: Run[] ): Run[] {
	const keys: Record< string, string > = {};
	for ( const [ english, key ] of Object.entries( PHRASES ) ) {
		keys[ english.toLowerCase() ] = key;
		keys[ __( english, 'events-certificate' ).toLowerCase() ] = key; // eslint-disable-line @wordpress/i18n-no-variables
	}
	return runs.flatMap( ( run ) => {
		if ( ! ( 'text' in run ) ) {
			return [ run ];
		}
		const m = /^(\s*)(.*?)(\s*)$/s.exec( run.text ) as RegExpExecArray;
		const key = keys[ m[ 2 ].toLowerCase() ];
		if ( ! key ) {
			return [ run ];
		}
		const { text, ...marks } = run;
		return [ m[ 1 ] && { ...marks, text: m[ 1 ] }, { ...marks, field: key }, m[ 3 ] && { ...marks, text: m[ 3 ] } ].filter( Boolean ) as Run[];
	} );
}

export function migrate( design: Design ): Design {
	return {
		...design,
		elements: design.elements.map( ( el ) => {
			if ( el.type === 'text' && el.runs ) {
				return { ...el, runs: phraseRuns( el.runs ) };
			}
			if ( el.type !== 'svg' || el.paths ) {
				return el;
			}
			const { markup, fill, ...rest } = el;
			return { ...rest, paths: svgPaths( el ) } as Element;
		} ),
	};
}


/**
 * Anchors and relative sizes, like Figma's constraints: per axis, an element
 * is pinned to the start, the end or the centre of its reference box (the page
 * or the safe margin), or stretched between both with an inset on each side.
 * Lengths are open text: "20pt", "10mm", "5%", "calc(100% - 40pt)"; percentages
 * are of the reference box's size on that axis.
 */
export type AxisMode = 'start' | 'center' | 'end' | 'stretch';
export type Axis = { mode: AxisMode; start?: string; end?: string; size?: string };
export type Layout = { h: Axis; v: Axis; ref?: 'page' | 'safe' };

const UNITS: Record< string, number > = { pt: 1, px: 1, mm: 2.83465, cm: 28.3465, in: 72 };

/** A length in pt: "20pt", "10mm", "5%", "calc(100% - 40pt)" (terms joined by + or -), or a bare number (pt). */
export function parseLen( value: string | number | undefined, total: number ): number {
	if ( value === undefined || value === '' ) {
		return 0;
	}
	if ( typeof value === 'number' ) {
		return value;
	}
	let expr = value.trim().toLowerCase();
	const calc = /^calc\((.*)\)$/.exec( expr );
	if ( calc ) {
		expr = calc[ 1 ];
	}
	let sum = 0;
	const re = /([+-]?)\s*(-?\d*\.?\d+)\s*(pt|px|mm|cm|in|%)?/g;
	let m: RegExpExecArray | null;
	let matched = false;
	while ( ( m = re.exec( expr ) ) ) {
		matched = true;
		const n = parseFloat( m[ 2 ] ) * ( m[ 1 ] === '-' ? -1 : 1 );
		sum += m[ 3 ] === '%' ? ( n * total ) / 100 : n * ( UNITS[ m[ 3 ] || 'pt' ] || 1 );
	}
	return matched ? sum : 0;
}

/** Whether a length is valid open text. */
export function isLen( value: string ): boolean {
	const v = value.trim().toLowerCase();
	return /^-?\d*\.?\d+\s*(pt|px|mm|cm|in|%)?$/.test( v ) || /^calc\(\s*-?\d*\.?\d+\s*(pt|px|mm|cm|in|%)?(\s*[+-]\s*\d*\.?\d+\s*(pt|px|mm|cm|in|%)?)*\s*\)$/.test( v );
}

/** The box an element is anchored to. */
export function refBox( page: Design[ 'page' ], ref?: 'page' | 'safe' ): Box {
	return ref === 'safe' ? { x: page.safe, y: page.safe, w: page.w - page.safe * 2, h: page.h - page.safe * 2 } : { x: 0, y: 0, w: page.w, h: page.h };
}

function resolveAxis( axis: Axis, origin: number, total: number, forced?: number ): [ number, number ] {
	const start = parseLen( axis.start, total );
	const end = parseLen( axis.end, total );
	const size = forced !== undefined ? forced : parseLen( axis.size, total );
	switch ( axis.mode ) {
		case 'end':
			return [ origin + total - end - size, size ];
		case 'center':
			return [ origin + ( total - size ) / 2 + start, size ];
		case 'stretch':
			return [ origin + start, Math.max( 1, total - start - end ) ];
		default:
			return [ origin + start, size ];
	}
}

/** An element with its anchors resolved to x, y, w, h in pt (elements without anchors are returned as they are). */
export function resolve< T extends Element >( el: T, page: Design[ 'page' ] ): T {
	const layout = ( el as { layout?: Layout } ).layout;
	if ( ! layout || el.type === 'line' ) {
		return el;
	}
	const r = refBox( page, layout.ref );
	let [ x, w ] = resolveAxis( layout.h, r.x, r.w );
	let [ y, h ] = resolveAxis( layout.v, r.y, r.h );
	const ratio = lockedRatio( el );
	const side = ratio ? lockedSide( layout ) : null;
	if ( ratio && side === 'w' ) {
		[ x, w ] = resolveAxis( layout.h, r.x, r.w, h * ratio );
	} else if ( ratio && side === 'h' ) {
		[ y, h ] = resolveAxis( layout.v, r.y, r.h, w / ratio );
	}
	return { ...el, x, y, w, h };
}

/** The width ÷ height an element keeps, if any: an image's aspect ratio setting, or the lock of other elements. */
export function lockedRatio( el: Element ): number | undefined {
	if ( el.type === 'image' ) {
		const parts = ( el.ratio || 'auto' ).split( '/' ).map( Number );
		return parts.length === 2 && parts[ 0 ] > 0 && parts[ 1 ] > 0 ? parts[ 0 ] / parts[ 1 ] : undefined;
	}
	return el.lock && el.lock > 0 ? el.lock : undefined;
}

/**
 * Which size follows the other when the ratio is kept: the height, unless only
 * the height fills (stretch), then the width. Filling both axes keeps both.
 */
export function lockedSide( layout: Layout ): 'w' | 'h' | null {
	const h = layout.h.mode === 'stretch';
	const v = layout.v.mode === 'stretch';
	if ( h && v ) {
		return null;
	}
	return v ? 'w' : 'h';
}

/** Writes a length back, in the unit it was written in (% stays %, anything else becomes pt). */
function lenLike( previous: string | undefined, pt: number, total: number ): string {
	const round = ( n: number ) => Math.round( n * 100 ) / 100;
	if ( previous && /%\s*$/.test( previous ) && ! /calc/i.test( previous ) ) {
		return `${ round( ( pt / total ) * 100 ) }%`;
	}
	return `${ round( pt ) }pt`;
}

function axisFrom( axis: Axis, pos: number, size: number, origin: number, total: number ): Axis {
	const offset = pos - origin;
	switch ( axis.mode ) {
		case 'end':
			return { ...axis, end: lenLike( axis.end, total - offset - size, total ), size: lenLike( axis.size, size, total ) };
		case 'center':
			return { ...axis, start: lenLike( axis.start, offset - ( total - size ) / 2, total ), size: lenLike( axis.size, size, total ) };
		case 'stretch':
			return { ...axis, start: lenLike( axis.start, offset, total ), end: lenLike( axis.end, total - offset - size, total ) };
		default:
			return { ...axis, start: lenLike( axis.start, offset, total ), size: lenLike( axis.size, size, total ) };
	}
}

/** The anchors that put an element at a box (after dragging or resizing on the canvas). */
export function layoutFrom( layout: Layout, b: Box, page: Design[ 'page' ] ): Layout {
	const r = refBox( page, layout.ref );
	return { ...layout, h: axisFrom( layout.h, b.x, b.w, r.x, r.w ), v: axisFrom( layout.v, b.y, b.h, r.y, r.h ) };
}

/** An element placed at a box: absolute elements take the numbers, anchored ones update their anchors. */
export function placed< T extends Element >( stored: T, b: Box, page: Design[ 'page' ] ): T {
	const layout = ( stored as { layout?: Layout } ).layout;
	if ( stored.type === 'line' ) {
		return stored;
	}
	return layout ? { ...stored, layout: layoutFrom( layout, b, page ) } : { ...stored, x: b.x, y: b.y, w: b.w, h: b.h };
}

/**
 * Previews: the print page (the format set in Size, never rotated) and the
 * screens the certificate page is seen on. Each screen has an orientation;
 * elements keep their content and style everywhere, and may have their own
 * anchors and visibility per screen (views). Without one, an element keeps
 * its anchors, and an element without anchors scales with the page.
 */
export type Screen = 'desktop' | 'tablet' | 'mobile';
export type Device = 'page' | Screen;
export type Orientation = 'landscape' | 'portrait';
export type View = { device: Device; orientation: Orientation };
export type ElementView = { layout?: Layout; hidden?: boolean; line?: { x1: number; y1: number; x2: number; y2: number } };

/** Screen sizes in CSS px (= pt on the canvas), landscape. */
export const SCREENS: Record< Screen, [ number, number ] > = {
	desktop: [ 1280, 800 ],
	tablet: [ 1024, 768 ],
	mobile: [ 844, 390 ],
};

export function viewPage( page: Design[ 'page' ], view: View ): Design[ 'page' ] {
	if ( view.device === 'page' ) {
		return page;
	}
	const [ w, h ] = SCREENS[ view.device ];
	const portrait = view.orientation === 'portrait';
	return { ...page, w: portrait ? h : w, h: portrait ? w : h, bleed: 0, safe: Math.min( page.safe, view.device === 'mobile' ? 16 : 32 ) };
}

const pct = ( n: number ) => `${ Math.round( n * 10000 ) / 100 }%`;

/**
 * Anchors for an unanchored element on a screen: its centre keeps its place
 * (as a share of the page); shapes keep their proportions (one scale for
 * both axes, so a QR code stays square), texts and rectangles take the same
 * share of the width and height.
 */
function scaled( el: Element, from: Design[ 'page' ], to: Design[ 'page' ] ): Layout {
	const b = box( el );
	const cx = ( b.x + b.w / 2 ) / from.w;
	const cy = ( b.y + b.h / 2 ) / from.h;
	const sx = to.w / from.w;
	const sy = to.h / from.h;
	const uniform = el.type === 'qr' || el.type === 'svg' || el.type === 'image' || el.type === 'ellipse';
	const k = Math.min( sx, sy );
	const w = Math.round( b.w * ( uniform ? k : sx ) * 100 ) / 100;
	const h = Math.round( b.h * ( uniform ? k : sy ) * 100 ) / 100;
	return {
		ref: 'page',
		h: { mode: 'start', start: `calc(${ pct( cx ) } - ${ w / 2 }pt)`, size: `${ w }pt` },
		v: { mode: 'start', start: `calc(${ pct( cy ) } - ${ h / 2 }pt)`, size: `${ h }pt` },
	};
}

/** The element as seen on a screen: its view's anchors and visibility, or its own. */
function inView( el: Element, design: Design, device: Screen, page: Design[ 'page' ] ): Element {
	const v = el.views?.[ device ];
	const hidden = v?.hidden ?? el.hidden;
	if ( el.type === 'line' ) {
		const sx = page.w / design.page.w;
		const sy = page.h / design.page.h;
		const line = v?.line || { x1: el.x1 * sx, y1: el.y1 * sy, x2: el.x2 * sx, y2: el.y2 * sy };
		return { ...el, ...line, hidden };
	}
	return { ...el, layout: v?.layout || el.layout || scaled( el, design.page, page ), hidden } as Element;
}

/** The design as the editor shows it for a view. */
export function toView( design: Design, view: View ): Design {
	if ( view.device === 'page' ) {
		return design;
	}
	const device = view.device;
	const page = viewPage( design.page, view );
	return { ...design, page, elements: design.elements.map( ( el ) => inView( el, design, device, page ) ) };
}

/** Edits made in a view, back into the design: content and style are shared, position and visibility go to the view. */
export function fromView( stored: Design, edited: Design, view: View ): Design {
	if ( view.device === 'page' ) {
		return edited;
	}
	const device = view.device;
	const page = viewPage( stored.page, view );
	const byId = new Map( stored.elements.map( ( el ) => [ el.id, el ] ) );
	const elements = edited.elements.map( ( next ) => {
		const orig = byId.get( next.id );
		if ( ! orig ) {
			return next;
		}
		const shown = inView( orig, stored, device, page );
		const views = { ...( orig.views || {} ) };
		const v: ElementView = { ...( views[ device ] || {} ) };
		let shared: Element;
		if ( next.type === 'line' && orig.type === 'line' && shown.type === 'line' ) {
			const { x1, y1, x2, y2, hidden, views: _v, ...rest } = next;
			if ( x1 !== shown.x1 || y1 !== shown.y1 || x2 !== shown.x2 || y2 !== shown.y2 ) {
				v.line = { x1, y1, x2, y2 };
			}
			shared = { ...orig, ...rest } as Element;
		} else {
			const { x, y, w, h, layout, hidden, views: _v, ...rest } = next as Element & { x: number };
			if ( JSON.stringify( layout ) !== JSON.stringify( ( shown as { layout?: Layout } ).layout ) ) {
				v.layout = layout;
			}
			shared = { ...orig, ...rest } as Element;
		}
		if ( !! next.hidden !== !! shown.hidden ) {
			v.hidden = !! next.hidden;
		}
		if ( Object.keys( v ).length ) {
			views[ device ] = v;
		}
		return { ...shared, ...( Object.keys( views ).length ? { views } : {} ) } as Element;
	} );
	return { ...edited, page: stored.page, elements };
}
