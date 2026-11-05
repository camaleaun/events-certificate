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
 *
 * @return array<string, mixed>
 */
function defaults() {
	return array(
		'force_public' => false,
		'slug'         => 'certificate',
		'delete_data'  => false,
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
