<?php
/**
 * Identification documents on certificates (decision 0006): stored like passwords
 * (an HMAC with a site pepper, then password_hash()), never readable; the
 * certificate shows a mask; anyone holding the document can confirm it
 * matches. The site picks the countries it accepts (none by default) and each
 * has its document with its rule (the CPF for Brazil); a foreigner gives a
 * passport (decision 0027). Filters: evcert_document_catalog, evcert_document_types.
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
 * Wrong document checks allowed per certificate in a window (CHECK_WINDOW), from all visitors together.
 */
const MAX_CHECKS = 20;

/**
 * Wrong document checks allowed per visitor and certificate in a window (VISITOR_WINDOW).
 */
const MAX_CHECKS_PER_VISITOR = 5;

/**
 * How long wrong tries count: the certificate's, and the visitor's.
 */
const CHECK_WINDOW   = HOUR_IN_SECONDS;
const VISITOR_WINDOW = 15 * MINUTE_IN_SECONDS;

/**
 * The type a foreigner gives instead of the country's document.
 */
const PASSPORT = 'passport';

/**
 * The option that turns on the countries other than Brazil (no screen: set it with WP-CLI or the filter).
 */
const MORE_OPTION = 'evcert_more_document_countries';

/**
 * Every document the code knows, by country (ISO 3166-1 alpha-2): only ones with a format and a rule to check
 * (decision 0027). Brazil has the CPF and nothing else. The labels and masks of documents already issued come from
 * here, whatever countries the site offers (catalog()).
 *
 * @return array<string, array{type: string, label: string, example: string, mask: string}> Country => document
 *         (mask: "0" is a digit, any other character stays; '' for none).
 */
function known() {
	return array(
		'AR' => array(
			'type'    => 'dni_ar',
			'label'   => 'DNI',
			'example' => '12.345.678',
			'mask'    => '',
		),
		'BR' => array(
			'type'    => 'cpf',
			'label'   => 'CPF',
			'example' => '000.000.000-00',
			'mask'    => '000.000.000-00',
		),
		'CL' => array(
			'type'    => 'rut',
			'label'   => 'RUT',
			'example' => '12.345.678-5',
			'mask'    => '',
		),
		'CO' => array(
			'type'    => 'cc_co',
			'label'   => 'Cédula',
			'example' => '1.234.567.890',
			'mask'    => '',
		),
		'ES' => array(
			'type'    => 'dni_es',
			'label'   => 'DNI/NIE',
			'example' => '12345678Z',
			'mask'    => '',
		),
		'MX' => array(
			'type'    => 'curp',
			'label'   => 'CURP',
			'example' => 'AAAA000000HAAAAA00',
			'mask'    => '',
		),
		'PE' => array(
			'type'    => 'dni_pe',
			'label'   => 'DNI',
			'example' => '12345678',
			'mask'    => '',
		),
		'PT' => array(
			'type'    => 'nif',
			'label'   => 'NIF',
			'example' => '123456789',
			'mask'    => '',
		),
		'UY' => array(
			'type'    => 'ci_uy',
			'label'   => 'CI',
			'example' => '1.234.567-2',
			'mask'    => '',
		),
	);
}

/**
 * Whether the countries other than Brazil are offered. Off by default (decision 0027): the option, or the filter, turns them on.
 *
 * @return bool
 */
function more_countries() {
	/**
	 * Filters whether the other countries' documents are offered besides Brazil's CPF.
	 *
	 * @param bool $on The option evcert_more_document_countries (false by default).
	 */
	return (bool) apply_filters( 'evcert_more_document_countries', (bool) get_option( MORE_OPTION, false ) );
}

/**
 * The documents a site can offer, by country: Brazil's CPF, and the other countries only when more_countries() is on.
 * A site picks the countries it accepts in Settings; none by default. Filter: evcert_document_catalog.
 *
 * @return array<string, array{type: string, label: string, example: string, mask: string}>
 */
function catalog() {
	$catalog = known();
	if ( ! more_countries() ) {
		$catalog = array_intersect_key( $catalog, array( 'BR' => true ) );
	}

	/**
	 * Filters the identification documents by country.
	 *
	 * @param array<string, array<string, string>> $catalog Country => type, label, example, mask.
	 */
	return (array) apply_filters( 'evcert_document_catalog', $catalog );
}

/**
 * The typing mask of a document type ("0" is a digit, any other character stays), or '' when it has none.
 *
 * @param string $type Type.
 * @return string
 */
function mask_pattern( $type ) {
	foreach ( known() as $document ) {
		if ( $document['type'] === $type ) {
			return (string) $document['mask'];
		}
	}

	return '';
}

/**
 * Whether the declaration page is offered (the second page of the certificate: the activities declared, and an optional
 * identification document, a Brazilian custom). Off by default; the setting, or the filter, turns it on.
 *
 * @return bool
 */
function declaration_enabled() {
	/**
	 * Filters whether the declaration page is offered.
	 *
	 * @param bool $on The setting "declaration" (false by default).
	 */
	return (bool) apply_filters( 'evcert_declaration_enabled', (bool) \EventsCertificate\Settings\value( 'declaration' ) );
}

/**
 * The country the site asks the document of, from Settings (one; none by default), with its document.
 *
 * @return array<string, array<string, string>> Country => document of catalog(): empty or one.
 */
function enabled() {
	$catalog = catalog();
	$chosen  = strtoupper( (string) \EventsCertificate\Settings\value( 'document_country' ) );

	return isset( $catalog[ $chosen ] ) ? array( $chosen => $catalog[ $chosen ] ) : array();
}

/**
 * Whether an attendee may say they are not from that country (and give a passport, or nothing).
 *
 * @return bool
 */
function foreigner_allowed() {
	return (bool) \EventsCertificate\Settings\value( 'document_foreigner' ) && array() !== enabled();
}

/**
 * The document types of a country, when the site accepts it (none otherwise).
 *
 * @param string $country ISO 3166-1 alpha-2 code.
 * @return array<string, string> Type => label.
 */
function types( $country ) {
	$country = strtoupper( (string) $country );
	$on      = enabled();
	$types   = isset( $on[ $country ] ) ? array( $on[ $country ]['type'] => $on[ $country ]['label'] ) : array();

	/**
	 * Filters the identification document types offered for a country.
	 *
	 * @param array<string, string> $types   Type => label.
	 * @param string                $country Country code.
	 */
	return (array) apply_filters( 'evcert_document_types', $types, $country );
}

/**
 * A type's label.
 *
 * @param string $type Type.
 * @return string
 */
function label( $type ) {
	if ( PASSPORT === $type ) {
		return __( 'Passport', 'events-certificates' );
	}
	foreach ( known() as $document ) {
		if ( $document['type'] === $type ) {
			return $document['label'];
		}
	}

	return __( 'Document', 'events-certificates' );
}

/**
 * The number as compared and hashed: digits only for numeric documents; letters and digits in capitals, without
 * spaces, dots or dashes, for the others.
 *
 * @param string $type   Type.
 * @param string $number Typed number.
 * @return string
 */
function normalize( $type, $number ) {
	$number = (string) $number;
	if ( in_array( $type, array( 'cpf', 'dni_ar', 'dni_pe', 'cc_co', 'nif', 'ci_uy' ), true ) ) {
		return (string) preg_replace( '/\D/', '', $number );
	}

	return strtoupper( (string) preg_replace( '/[\s.\-\/]/', '', $number ) );
}

/**
 * Whether a number is valid for its type: the length and the check digit or letter where the country has one.
 *
 * @param string $type   Type.
 * @param string $number Typed number.
 * @return bool
 */
function valid( $type, $number ) {
	$n = normalize( $type, $number );
	switch ( $type ) {
		case 'cpf':
			return valid_cpf( $n );
		case 'rut':
			return (bool) preg_match( '/^\d{7,8}[0-9K]$/', $n ) && rut_check( substr( $n, 0, -1 ) ) === substr( $n, -1 );
		case 'nif':
			return (bool) preg_match( '/^\d{9}$/', $n ) && nif_check( substr( $n, 0, 8 ) ) === (int) $n[8];
		case 'dni_es':
			return valid_dni_es( $n );
		case 'ci_uy':
			return (bool) preg_match( '/^\d{7,8}$/', $n ) && ci_uy_check( substr( str_pad( $n, 8, '0', STR_PAD_LEFT ), 0, 7 ) ) === (int) substr( $n, -1 );
		case 'curp':
			return (bool) preg_match( '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HMX][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[0-9A-Z]\d$/', $n ) && substr( $n, -1 ) === curp_check_digit( substr( $n, 0, 17 ) );
		case 'dni_ar':
			return (bool) preg_match( '/^\d{7,8}$/', $n );
		case 'dni_pe':
			return (bool) preg_match( '/^\d{8}$/', $n );
		case 'cc_co':
			return (bool) preg_match( '/^\d{6,10}$/', $n );
		case PASSPORT:
			return (bool) preg_match( '/^[0-9A-Z]{6,9}$/', $n );
	}

	return (bool) preg_match( '/^[0-9A-Z]{3,40}$/', $n );
}

/**
 * CPF: 11 digits, not all the same, with its two check digits.
 *
 * @param string $n Digits.
 * @return bool
 */
function valid_cpf( $n ) {
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
 * The RUT's check character (module 11, weights 2 to 7 from the right): a digit or K.
 *
 * @param string $body The digits before the check character.
 * @return string
 */
function rut_check( $body ) {
	$sum    = 0;
	$weight = 2;
	for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
		$sum   += (int) $body[ $i ] * $weight;
		$weight = 7 === $weight ? 2 : $weight + 1;
	}
	$check = 11 - ( $sum % 11 );

	return 11 === $check ? '0' : ( 10 === $check ? 'K' : (string) $check );
}

/**
 * The Portuguese NIF's check digit (module 11, weights 9 to 2).
 *
 * @param string $body The first 8 digits.
 * @return int
 */
function nif_check( $body ) {
	$sum = 0;
	for ( $i = 0; $i < 8; $i++ ) {
		$sum += (int) $body[ $i ] * ( 9 - $i );
	}
	$check = 11 - ( $sum % 11 );

	return $check >= 10 ? 0 : $check;
}

/**
 * The Uruguayan CI's check digit (weights 2987634).
 *
 * @param string $body The first 7 digits.
 * @return int
 */
function ci_uy_check( $body ) {
	$weights = array( 2, 9, 8, 7, 6, 3, 4 );
	$sum     = 0;
	for ( $i = 0; $i < 7; $i++ ) {
		$sum += (int) $body[ $i ] * $weights[ $i ];
	}

	return ( 10 - ( $sum % 10 ) ) % 10;
}

/**
 * The Spanish DNI (8 digits) or NIE (X, Y or Z, 7 digits) and its letter (the number modulo 23).
 *
 * @param string $n Letters and digits, in capitals.
 * @return bool
 */
function valid_dni_es( $n ) {
	if ( ! preg_match( '/^([XYZ]\d{7}|\d{8})([A-Z])$/', $n, $m ) ) {
		return false;
	}
	$number = (int) strtr(
		$m[1],
		array(
			'X' => '0',
			'Y' => '1',
			'Z' => '2',
		)
	);

	return substr( 'TRWAGMYFPDXBNJZSQVHLCKE', $number % 23, 1 ) === $m[2];
}

/**
 * The CURP's check digit (the first 17 characters, weights 18 to 2, module 10).
 *
 * @param string $first17 The first 17 characters.
 * @return string
 */
function curp_check_digit( $first17 ) {
	$chars = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
	$sum   = 0;
	$first = preg_split( '//u', strtoupper( $first17 ), -1, PREG_SPLIT_NO_EMPTY );
	foreach ( (array) $first as $i => $char ) {
		$sum += (int) mb_strpos( $chars, $char ) * ( 18 - $i );
	}

	return (string) ( ( 10 - ( $sum % 10 ) ) % 10 );
}

/**
 * The number written out (CPF: 000.000.000-00; RUT: 12.345.678-5; the others as they are).
 *
 * @param string $type   Type.
 * @param string $number Number.
 * @return string
 */
function format( $type, $number ) {
	$n = normalize( $type, $number );
	if ( 'cpf' === $type && 11 === strlen( $n ) ) {
		return sprintf( '%s.%s.%s-%s', substr( $n, 0, 3 ), substr( $n, 3, 3 ), substr( $n, 6, 3 ), substr( $n, 9, 2 ) );
	}
	if ( 'rut' === $type && strlen( $n ) >= 8 ) {
		return number_format( (int) substr( $n, 0, -1 ), 0, '', '.' ) . '-' . substr( $n, -1 );
	}

	return $n;
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
 * The visitor's key for a certificate: their address, hashed with the site's salt (the address itself is not kept).
 * The address is the connection's: a forwarded header is whatever the visitor writes. Behind a proxy
 * the filter gives the real one.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return string
 */
function visitor_key( array $cert ) {
	$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	/**
	 * Filters the visitor's address that limits the tries of the document check
	 * (for sites behind a proxy or a CDN, where it is not the connection's).
	 *
	 * @param string $address Address.
	 */
	$address = (string) apply_filters( 'evcert_visitor_address', $address );

	return 'evcert_dc_v_' . substr( wp_hash( (int) $cert['id'] . '|' . $address ), 0, 32 );
}

/**
 * Wrong tries counted under a key in its window (the window starts at the first one).
 *
 * @param string $key Transient.
 * @return int
 */
function tries( $key ) {
	$state = get_transient( $key );

	return is_array( $state ) ? (int) $state['n'] : 0;
}

/**
 * Counts a wrong try under a key; its window is not extended by later ones.
 *
 * @param string $key    Transient.
 * @param int    $window Seconds.
 */
function count_try( $key, $window ) {
	$state = get_transient( $key );
	$until = is_array( $state ) ? (int) $state['until'] : time() + $window;
	set_transient(
		$key,
		array(
			'n'     => tries( $key ) + 1,
			'until' => $until,
		),
		max( 1, $until - time() )
	);
}

/**
 * Forgets the wrong tries of a certificate (all visitors'): the certificate's count, and the current visitor's.
 *
 * @param array<string, mixed> $cert Certificate.
 */
function forget_tries( array $cert ) {
	delete_transient( 'evcert_dc_c_' . (int) $cert['id'] );
	delete_transient( visitor_key( $cert ) );
}

/**
 * Whether a typed number is the certificate's document. Counts wrong tries:
 * a visitor gets MAX_CHECKS_PER_VISITOR per VISITOR_WINDOW and the certificate
 * MAX_CHECKS per CHECK_WINDOW from everyone, then nothing is answered (not even
 * the right number, which would tell it) until the window ends. Nobody can
 * turn the check off for good, and guessing a number is not worth it.
 *
 * @param array<string, mixed> $cert   Certificate.
 * @param string               $number Typed number.
 * @return bool|null True/false, or null when the tries are used up or there is no document.
 */
function check( array $cert, $number ) {
	if ( empty( $cert['doc_hash'] ) ) {
		return null;
	}
	$visitor     = visitor_key( $cert );
	$certificate = 'evcert_dc_c_' . (int) $cert['id'];
	if ( tries( $visitor ) >= MAX_CHECKS_PER_VISITOR || tries( $certificate ) >= MAX_CHECKS ) {
		return null;
	}
	$ok = password_verify( hash_hmac( 'sha256', $cert['doc_type'] . ':' . normalize( (string) $cert['doc_type'], $number ), pepper() ), (string) $cert['doc_hash'] );
	if ( ! $ok ) {
		count_try( $visitor, VISITOR_WINDOW );
		count_try( $certificate, CHECK_WINDOW );
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
