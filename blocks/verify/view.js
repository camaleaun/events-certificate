/**
 * The code field's mask: shows what is typed (or pasted) as
 * XXXX-XXXX-XXXX-XXXX in capitals, adding the dashes, keeping the caret in
 * place. Only the display: lowercase and codes without dashes are fine, and
 * the server normalizes whatever is sent (Code\normalize()). Without this
 * script the form works the same.
 */
const GROUP = 4;
const LENGTH = 16;

/**
 * The code's letters and digits, in capitals, at most a code's length.
 *
 * @param {string} value Typed value.
 * @return {string} Characters.
 */
const chars = ( value ) => value.toUpperCase().replace( /[^0-9A-Z]/g, '' ).slice( 0, LENGTH );

/**
 * Characters grouped by four with dashes.
 *
 * @param {string} raw Characters.
 * @return {string} Formatted.
 */
const format = ( raw ) => ( raw.match( new RegExp( `.{1,${ GROUP }}`, 'g' ) ) || [] ).join( '-' );

document.querySelectorAll( '[data-evcert-code]' ).forEach( ( input ) => {
	const apply = () => {
		const caret = input.selectionStart ?? input.value.length;
		// How many code characters come before the caret, to put it back after them.
		const before = chars( input.value.slice( 0, caret ) ).length;
		const next = format( chars( input.value ) );
		if ( next === input.value ) {
			return;
		}
		input.value = next;
		// Dashes only between groups (never trailing), so Backspace isn't stuck on one.
		const at = before + Math.floor( Math.max( 0, before - 1 ) / GROUP );
		input.setSelectionRange( Math.min( at, next.length ), Math.min( at, next.length ) );
	};
	input.addEventListener( 'input', apply );
	apply();
} );
