<?php
/**
 * Identity documents on certificates (decision 0006): stored like passwords
 * (an HMAC with a site pepper, then password_hash()), never readable; the
 * certificate shows a mask; anyone holding the document can confirm it
 * matches. Types depend on the country: Brazil's is the CPF (with its check
 * digits); elsewhere a generic "Document". Filter: evcert_document_types.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Documents;

defined( 'ABSPATH' ) || exit;

/**
 * Option with the site's pepper.
 */
const PEPPER_OPTION = 'evcert_doc_pepper';

/**
 * Wrong document checks allowed per certificate.
 */
const MAX_CHECKS = 20;

/**
 * The document types of a country.
 *
 * @param string $country ISO 3166-1 alpha-2 code.
 * @return array<string, string> Type => label.
 */
function types( $country ) {
	$types = 'BR' === strtoupper( (string) $country ) ? array( 'cpf' => 'CPF' ) : array( 'id' => __( 'Document', 'events-certificate' ) );

	/**
	 * Filters the identity document types offered for a country.
	 *
	 * @param array<string, string> $types   Type => label.
	 * @param string                $country Country code.
	 */
	return (array) apply_filters( 'evcert_document_types', $types, strtoupper( (string) $country ) );
}

/**
 * A type's label.
 *
 * @param string $type Type.
 * @return string
 */
function label( $type ) {
	return 'cpf' === $type ? 'CPF' : __( 'Document', 'events-certificate' );
}

/**
 * The number as compared and hashed: CPF digits only; others in capitals
 * without spaces, dots or dashes.
 *
 * @param string $type   Type.
 * @param string $number Typed number.
 * @return string
 */
function normalize( $type, $number ) {
	$number = (string) $number;

	return 'cpf' === $type ? (string) preg_replace( '/\D/', '', $number ) : strtoupper( (string) preg_replace( '/[\s.\-\/]/', '', $number ) );
}

/**
 * Whether a number is valid for its type (CPF: 11 digits and check digits).
 *
 * @param string $type   Type.
 * @param string $number Typed number.
 * @return bool
 */
function valid( $type, $number ) {
	$n = normalize( $type, $number );
	if ( 'cpf' !== $type ) {
		return (bool) preg_match( '/^[0-9A-Z]{3,40}$/', $n );
	}
	if ( 11 !== strlen( $n ) || preg_match( '/^(\d)\1{10}$/', $n ) ) {
		return false;
	}
	for ( $len = 9; $len < 11; $len++ ) {
		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += (int) $n[ $i ] * ( $len + 1 - $i );
		}
		$check = ( $sum * 10 ) % 11 % 10;
		if ( (int) $n[ $len ] !== $check ) {
			return false;
		}
	}

	return true;
}

/**
 * The number written out (CPF: 000.000.000-00).
 *
 * @param string $type   Type.
 * @param string $number Number.
 * @return string
 */
function format( $type, $number ) {
	$n = normalize( $type, $number );

	return 'cpf' === $type && 11 === strlen( $n ) ? sprintf( '%s.%s.%s-%s', substr( $n, 0, 3 ), substr( $n, 3, 3 ), substr( $n, 6, 3 ), substr( $n, 9, 2 ) ) : $n;
}

/**
 * The mask a certificate shows, at most 60 % of the characters visible
 * (CPF: only the 7th–9th digits, •••.•••.789-••).
 *
 * @param string $type   Type.
 * @param string $number Number.
 * @return string
 */
function mask( $type, $number ) {
	$n = normalize( $type, $number );
	if ( 'cpf' === $type && 11 === strlen( $n ) ) {
		return '•••.•••.' . substr( $n, 6, 3 ) . '-••';
	}
	$keep = (int) floor( strlen( $n ) * 0.4 );

	return str_repeat( '•', strlen( $n ) - $keep ) . substr( $n, -$keep );
}

/**
 * The site's pepper (made once; without it, hashes can't be checked).
 *
 * @return string
 */
function pepper() {
	$pepper = (string) get_option( PEPPER_OPTION );
	if ( '' === $pepper ) {
		$pepper = wp_generate_password( 64, true, true );
		update_option( PEPPER_OPTION, $pepper, false );
	}

	return $pepper;
}

/**
 * The stored hash of a number.
 *
 * @param string $type   Type.
 * @param string $number Number.
 * @return string
 */
function digest( $type, $number ) {
	$algo = defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

	return (string) password_hash( hash_hmac( 'sha256', $type . ':' . normalize( $type, $number ), pepper() ), $algo );
}

/**
 * Whether a typed number is the certificate's document. Counts wrong tries;
 * after MAX_CHECKS the certificate answers no more.
 *
 * @param array<string, mixed> $cert   Certificate.
 * @param string               $number Typed number.
 * @return bool|null True/false, or null when checks are used up or there is no document.
 */
function check( array $cert, $number ) {
	global $wpdb;

	if ( empty( $cert['doc_hash'] ) || (int) ( $cert['doc_check_fail'] ?? 0 ) >= MAX_CHECKS ) {
		return null;
	}
	$ok = password_verify( hash_hmac( 'sha256', $cert['doc_type'] . ':' . normalize( (string) $cert['doc_type'], $number ), pepper() ), (string) $cert['doc_hash'] );
	if ( ! $ok ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET doc_check_fail = doc_check_fail + 1 WHERE id = %d', \EventsCertificate\Certificates\table(), (int) $cert['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	return $ok;
}

/**
 * The columns a certificate stores for a document.
 *
 * @param string $type   Type.
 * @param string $number Number.
 * @return array<string, string>
 */
function columns( $type, $number ) {
	return array(
		'doc_type'   => $type,
		'doc_label'  => label( $type ),
		'doc_masked' => mask( $type, $number ),
		'doc_hash'   => digest( $type, $number ),
	);
}
