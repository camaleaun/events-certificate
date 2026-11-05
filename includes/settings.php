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
		'force_public' => false,
		'slug'         => 'certificate',
		'delete_data'  => false,
		'self_issue'   => 'attended',
		'location'     => '',
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

	foreach ( array( 'force_public', 'delete_data' ) as $key ) {
		$clean[ $key ] = array_key_exists( $key, $value ) ? (bool) $value[ $key ] : $defaults[ $key ];
	}

	$slug          = array_key_exists( 'slug', $value ) ? sanitize_title( (string) $value['slug'] ) : '';
	$clean['slug'] = '' !== $slug ? $slug : $defaults['slug'];

	$clean['location'] = array_key_exists( 'location', $value ) ? sanitize_text_field( (string) $value['location'] ) : $defaults['location'];

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
