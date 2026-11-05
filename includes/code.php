<?php
/**
 * Certificate codes: YYMM-DDxx-xxxx-xxxx. The issue date first (like the
 * codes on gov.br documents), then ten random characters from Crockford's
 * base32 (no I, L, O or U, so a code read aloud or typed by hand is
 * unambiguous): 50 random bits per day.
 *
 * Typing is forgiving: case, spaces and hyphens are ignored, O reads as 0
 * and I/L as 1.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Code;

defined( 'ABSPATH' ) || exit;

/**
 * Crockford's base32 alphabet.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/**
 * Random characters in a code.
 */
const RANDOM_LENGTH = 10;

/**
 * A new code.
 *
 * @param int|null $timestamp Issue time (defaults to now), in the site's time zone.
 * @return string Formatted code, e.g. 2611-04K7-M29Q-XAP3.
 */
function generate( $timestamp = null ) {
	$date   = wp_date( 'ymd', null === $timestamp ? time() : $timestamp );
	$random = '';
	$last   = strlen( ALPHABET ) - 1;
	for ( $i = 0; $i < RANDOM_LENGTH; $i++ ) {
		$random .= ALPHABET[ random_int( 0, $last ) ];
	}

	return format( $date . $random );
}

/**
 * Groups a normalized code in fours.
 *
 * @param string $code Normalized code (16 characters).
 * @return string
 */
function format( $code ) {
	return implode( '-', str_split( $code, 4 ) );
}

/**
 * Normalizes a typed code, or returns an empty string when it can't be one.
 *
 * @param string $input What was typed or scanned.
 * @return string Formatted code, or '' when invalid.
 */
function normalize( $input ) {
	$code = strtoupper( preg_replace( '/[\s\-]+/', '', (string) $input ) );
	$code = strtr( $code, 'OIL', '011' );

	if ( 6 + RANDOM_LENGTH !== strlen( $code ) || ! ctype_digit( substr( $code, 0, 6 ) ) ) {
		return '';
	}
	if ( strspn( $code, ALPHABET ) !== strlen( $code ) ) {
		return '';
	}

	return format( $code );
}
