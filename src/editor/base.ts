/**
 * The Base design (templates/base-design.json) in the admin's language: its
 * texts, element names and color names go through translation when a design
 * starts from it. And the required parts every design keeps: when a design
 * (an import, an older save) lacks one, it is added from the Base, placed
 * under the authenticity QR code and the code.
 */
import { __ } from '@wordpress/i18n';
import raw from '../../templates/base-design.json';
import { box, REQUIRED_SLOTS, resolve, type Design, type Element } from './model';

/** English strings of the Base design and their translations. */
function strings(): Record< string, string > {
	return {
		// Texts.
		'took part in ': __( 'took part in ', 'events-certificate' ),
		', with a workload of ': __( ', with a workload of ', 'events-certificate' ),
		// Element names.
		Authenticity: __( 'Authenticity', 'events-certificate' ),
		Code: __( 'Code', 'events-certificate' ),
		'Date label': __( 'Date label', 'events-certificate' ),
		'Date line': __( 'Date line', 'events-certificate' ),
		Intro: __( 'Intro', 'events-certificate' ),
		Name: __( 'Name', 'events-certificate' ),
		Signature: __( 'Signature', 'events-certificate' ),
		'Signature line': __( 'Signature line', 'events-certificate' ),
		'Signer name': __( 'Signer name', 'events-certificate' ),
		'Signer role': __( 'Signer role', 'events-certificate' ),
		Text: __( 'Text', 'events-certificate' ),
		Title: __( 'Title', 'events-certificate' ),
		'Verification hint': __( 'Verification hint', 'events-certificate' ),
		// Color names.
		Primary: __( 'Primary', 'events-certificate' ),
		'Muted text': __( 'Muted text', 'events-certificate' ),
		Lines: __( 'Lines', 'events-certificate' ),
		Background: __( 'Background', 'events-certificate' ),
		'On primary': __( 'On primary', 'events-certificate' ),
	};
}

/** The Base design, translated. */
export function baseDesign(): Design {
	const t = strings();
	const tr = ( s?: string ) => ( s !== undefined && t[ s ] !== undefined ? t[ s ] : s );
	const design = JSON.parse( JSON.stringify( raw ) ) as Design;
	design.elements = design.elements.map( ( el ) => {
		const next = { ...el, name: tr( el.name ) } as Element;
		if ( next.type === 'text' && next.runs ) {
			next.runs = next.runs.map( ( r ) => ( 'text' in r ? { ...r, text: tr( r.text ) as string } : r ) );
		}
		return next;
	} );
	design.colors = Object.fromEntries( Object.entries( design.colors ).map( ( [ k, v ] ) => [ k, { ...v, name: tr( v.name ) as string } ] ) );
	return design;
}

/** Slots every design keeps; the code and its hint follow the QR code. */
export const KEPT_SLOTS = [ ...REQUIRED_SLOTS, 'code', 'verify' ];

/**
 * Adds the required parts a design lacks, from the Base: placed under the
 * QR code (the code) and under the code (the hint), centred on the QR code.
 */
export function withRequired( design: Design ): Design {
	const missing = KEPT_SLOTS.filter( ( slot ) => ! design.elements.some( ( el ) => el.slot === slot ) );
	if ( ! missing.length ) {
		return design;
	}
	const from = baseDesign();
	const elements = [ ...design.elements ];
	const find = ( slot: string ) => elements.find( ( el ) => el.slot === slot );
	for ( const slot of missing ) {
		const source = from.elements.find( ( el ) => el.slot === slot );
		if ( ! source ) {
			continue;
		}
		const resolved = box( resolve( source, from.page ) );
		const above = slot === 'verify' ? find( 'code' ) || find( 'authenticity' ) : slot === 'code' ? find( 'authenticity' ) : null;
		let el: Element;
		if ( above ) {
			const a = box( resolve( above, design.page ) );
			const qr = find( 'authenticity' );
			const cx = qr ? box( resolve( qr, design.page ) ).x + box( resolve( qr, design.page ) ).w / 2 : a.x + a.w / 2;
			const { layout, ...rest } = source as Element & { layout?: unknown };
			el = { ...rest, x: Math.round( ( cx - resolved.w / 2 ) * 100 ) / 100, y: Math.round( ( a.y + a.h + 2 ) * 100 ) / 100, w: resolved.w, h: resolved.h } as Element;
		} else {
			el = source;
		}
		if ( elements.some( ( e ) => e.id === el.id ) ) {
			el = { ...el, id: `${ el.id }-${ Date.now().toString( 36 ) }` };
		}
		elements.push( el );
	}
	return { ...design, elements };
}
