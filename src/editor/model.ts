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
	text: string;
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
export type RectEl = Base & { type: 'rect'; fill?: string; stroke?: string; strokeWidth?: number; radius?: number[] };
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

export type Element = TextEl | RectEl | EllipseEl | PathEl | LineEl | ImageEl | QrEl;

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

/** Text with {placeholders} filled from sample data and **bold** as <strong>, as HTML. */
export function textHtml( text: string, sample: Record< string, string >, upper?: boolean ): string {
	const escaped = text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	const filled = escaped.replace( /\{([a-z_]+)\}/g, ( match, key ) => ( sample[ key ] !== undefined ? sample[ key ] : match ) );
	const html = filled.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
	return upper ? html.toUpperCase().replace( /<(\/?)STRONG>/g, '<$1strong>' ) : html;
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
			return { id, type, name: 'Text', x: cx - 120, y: cy - 10, w: 240, h: 20, text: 'Text', font: 'Inter', weight: 400, size: 14, color: '$text', align: 'center', lh: 1.3 };
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
