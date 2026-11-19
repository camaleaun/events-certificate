<?php
/**
 * Settings: one option, read through sanitize() so callers always get every
 * key with a valid value (unknown keys dropped, missing ones defaulted).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Option name.
 */
const OPTION = 'evcert_settings';

/**
 * Default settings.
 *
 * - force_public: treat this site as public even when it looks local
 *   (a staging site behind a public URL), so certificates get a real QR code.
 * - signer_name, signer_role: who signs, on sites without WordCamp organizers
 *   (the signature is the evcert_signature option).
 * - issued_date: the date certificates show as their date of issue: when each
 *   was issued (issued), or the event's last day (event_end).
 * - slug: the URL base of certificate pages (/certificate/CODE/), where
 *   anyone sees a certificate and checks it is authentic.
 * - delete_data: drop the tables on uninstall. Off by default: printed
 *   certificates point to their verification page.
 * - location: where the event happens ("City, Country"), for the {location}
 *   field when the event source doesn't say (on WordCamp.org it comes from
 *   the WordCamp's Location).
 * - self_issue: who gets their own certificate from the tickets page
 *   (CampTix): "attended" (marked present), "confirmed" (any confirmed
 *   ticket) or "off" (only organizers issue).
 *
 * @return array<string, mixed>
 */
function defaults() {
	return array(
		'force_public'       => false,
		'slug'               => 'certificate',
		'delete_data'        => false,
		'self_issue'         => 'attended',
		'location'           => '',
		'issued_date'        => 'issued',
		'signer_name'        => '',
		'signer_role'        => '',
		// Verification: a code and a QR code on the certificates, and the page that checks them. Off by default.
		'verification'       => false,
		// The declaration page (second page: activities and an optional identification document): a Brazilian custom, off by default.
		'declaration'        => false,
		// The country whose identification document the declaration page asks for: one, none by default.
		'document_country'   => '',
		// Whether an attendee may say they are not from that country (a passport, or nothing).
		'document_foreigner' => false,
		// "Get my certificate" by e-mail: how the e-mail is proven (none, simple, digits4, digits6, link) and whether the code mails carry the link too.
		'email_auth'         => 'simple',
		'email_auth_link'    => false,
		// The languages certificates are shown in (Verify\locales()): empty is every installed one. The site's language is always there.
		'languages'          => array(),
	);
}

/**
 * Sanitizes a settings array.
 *
 * @param mixed $value Raw value.
 * @return array<string, mixed>
 */
function sanitize( $value ) {
	$value    = is_array( $value ) ? $value : array();
	$defaults = defaults();
	$clean    = array();

	foreach ( array( 'force_public', 'delete_data', 'declaration', 'verification', 'document_foreigner', 'email_auth_link' ) as $key ) {
		$clean[ $key ] = array_key_exists( $key, $value ) ? (bool) $value[ $key ] : $defaults[ $key ];
	}

	$slug          = array_key_exists( 'slug', $value ) ? sanitize_title( (string) $value['slug'] ) : '';
	$clean['slug'] = '' !== $slug ? $slug : $defaults['slug'];

	foreach ( array( 'location', 'signer_name', 'signer_role' ) as $key ) {
		$clean[ $key ] = array_key_exists( $key, $value ) ? sanitize_text_field( (string) $value[ $key ] ) : $defaults[ $key ];
	}

	$date                 = array_key_exists( 'issued_date', $value ) ? (string) $value['issued_date'] : '';
	$clean['issued_date'] = in_array( $date, array( 'issued', 'event_end' ), true ) ? $date : $defaults['issued_date'];

	// One country of the catalogue, in capitals; none by default.
	$country                   = array_key_exists( 'document_country', $value ) ? strtoupper( sanitize_text_field( (string) $value['document_country'] ) ) : '';
	$clean['document_country'] = isset( \EventsCertificate\Documents\catalog()[ $country ] ) ? $country : '';

	$auth                = array_key_exists( 'email_auth', $value ) ? (string) $value['email_auth'] : '';
	$clean['email_auth'] = in_array( $auth, \EventsCertificate\MineAuth\modes(), true ) ? $auth : $defaults['email_auth'];

	$languages = array();
	foreach ( is_array( $value['languages'] ?? null ) ? $value['languages'] : array() as $locale ) {
		$locale = trim( (string) $locale );
		if ( preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $locale ) && ! in_array( $locale, $languages, true ) ) {
			$languages[] = $locale;
		}
	}
	$clean['languages'] = $languages;

	$who                 = array_key_exists( 'self_issue', $value ) ? (string) $value['self_issue'] : '';
	$clean['self_issue'] = in_array( $who, array( 'attended', 'confirmed', 'off' ), true ) ? $who : $defaults['self_issue'];

	return $clean;
}

/**
 * The settings, sanitized.
 *
 * @return array<string, mixed>
 */
function get() {
	return sanitize( get_option( OPTION, array() ) );
}

/**
 * One setting.
 *
 * @param string $key Key from defaults().
 * @return mixed
 */
function value( $key ) {
	$settings = get();

	return $settings[ $key ] ?? null;
}

/**
 * Saves the given keys, keeping the others.
 *
 * @param array<string, mixed> $changes Keys to change.
 * @return array<string, mixed> The saved settings.
 */
function update( array $changes ) {
	$settings = sanitize( array_merge( get(), $changes ) );
	update_option( OPTION, $settings );

	return $settings;
}
