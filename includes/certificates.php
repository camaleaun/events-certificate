<?php
/**
 * Certificates: issued records, frozen when issued (docs/decisions/0001).
 *
 * A certificate keeps everything it shows (name, event, dates, role) and the
 * design version it was issued with; nothing reads live data afterwards. A
 * correction issues a new certificate and marks the old one "replaced", so a
 * code people already shared keeps answering.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Certificates;

use EventsCertificate\Code;
use EventsCertificate\Designs;
use EventsCertificate\Install;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fields a caller may set when issuing.
 */
const FIELDS = array( 'context', 'event_id', 'group_id', 'name', 'email', 'wporg_username', 'role', 'details', 'attended', 'locale', 'event_title', 'event_start', 'event_end', 'event_tz', 'hours', 'source', 'source_ref', 'self_service' );

/**
 * Fields that make a certificate what it is (part of its fingerprint).
 */
const FROZEN = array( 'code', 'name', 'role', 'details', 'event_title', 'event_start', 'event_end', 'event_tz', 'hours', 'design_hash' );

/**
 * The certificates table.
 *
 * @return string
 */
function table() {
	return Install\tables()['certificates'];
}

/**
 * A certificate by ID.
 *
 * @param int $id Certificate ID.
 * @return array<string, mixed>|null
 */
function get( $id ) {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; certificates are not cached.

	return $row ? $row : null;
}

/**
 * A certificate by its code.
 *
 * @param string $code Code as typed or scanned.
 * @return array<string, mixed>|null
 */
function get_by_code( $code ) {
	global $wpdb;

	$code = Code\normalize( (string) $code );
	if ( '' === $code ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code = %s', table(), $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

	return $row ? $row : null;
}

/**
 * The current certificate issued from a source record (a CampTix attendee,
 * a GatherPress RSVP…), ignoring replaced and revoked ones.
 *
 * @param string $source     Source, e.g. "camptix".
 * @param string $source_ref Source record, e.g. the attendee ID.
 * @return array<string, mixed>|null
 */
function find_by_source( $source, $source_ref ) {
	global $wpdb;

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->prepare(
			"SELECT * FROM %i WHERE source = %s AND source_ref = %s AND status = 'issued' ORDER BY id DESC LIMIT 1",
			table(),
			$source,
			(string) $source_ref
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Stores a design version (by content hash) and returns its hash.
 *
 * @param array<string, mixed> $design Design.
 * @return string
 */
function store_design( array $design ) {
	global $wpdb;

	$json = (string) wp_json_encode( $design );
	$hash = hash( 'sha256', $json );
	$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (hash, design, created_at) VALUES (%s, %s, %s)', Install\tables()['designs'], $hash, $json, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; INSERT IGNORE keeps one copy per version.

	return $hash;
}

/**
 * The design version a certificate was issued with (falls back to the site
 * design when the snapshot is missing).
 *
 * @param array<string, mixed> $cert Certificate.
 * @return array<string, mixed>
 */
function design( array $cert ) {
	global $wpdb;

	$json   = $wpdb->get_var( $wpdb->prepare( 'SELECT design FROM %i WHERE hash = %s', Install\tables()['designs'], (string) ( $cert['design_hash'] ?? '' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	$design = $json ? json_decode( $json, true ) : null;

	return is_array( $design ) && isset( $design['elements'], $design['page'] ) ? $design : Designs\get( Designs\site_design_id() );
}

/**
 * Fingerprint of what a certificate says, so a changed row can be noticed.
 *
 * @param array<string, mixed> $row Certificate.
 * @return string
 */
function fingerprint( array $row ) {
	$data = array();
	foreach ( FROZEN as $key ) {
		$data[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	return hash( 'sha256', (string) wp_json_encode( $data ) );
}

/**
 * Issues a certificate.
 *
 * @param array<string, mixed> $data Values from FIELDS ("name" is required) and "data", extra frozen values (location, signer…).
 * @return array<string, mixed>|WP_Error The certificate.
 */
function issue( array $data ) {
	global $wpdb;

	$row = array_intersect_key( $data, array_flip( FIELDS ) );
	if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
		$row['data_json'] = (string) wp_json_encode( $data['data'] );
	}

	$row['name'] = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $row['name'] ?? '' ) ) ) );
	if ( '' === $row['name'] ) {
		return new WP_Error( 'evcert_name_required', __( 'The name on the certificate is required.', 'events-certificate' ) );
	}
	if ( function_exists( 'mb_substr' ) ) {
		$row['name'] = mb_substr( $row['name'], 0, 190 );
	}

	$now                     = current_time( 'mysql', true );
	$row['status']           = 'issued';
	$row['locale']           = $row['locale'] ?? get_locale();
	$row['design_hash']      = store_design( Designs\get( Designs\site_design_id() ) );
	$row['issued_at']        = $now;
	$row['created_at']       = $now;
	$row['updated_at']       = $now;
	$row['renderer_version'] = '1';

	for ( $try = 0; $try < 5; $try++ ) {
		$row['code']        = Code\generate();
		$row['fingerprint'] = fingerprint( $row );
		if ( $wpdb->insert( table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			return get( (int) $wpdb->insert_id );
		}
		if ( ! get_by_code( $row['code'] ) ) {
			break; // Failed for another reason than a code collision.
		}
	}

	return new WP_Error( 'evcert_issue_failed', __( 'The certificate could not be issued. Try again.', 'events-certificate' ) );
}

/**
 * Corrects a certificate: issues a new one with the changes and marks the old
 * one replaced by it.
 *
 * @param array<string, mixed> $old     Certificate being corrected.
 * @param array<string, mixed> $changes Changed values from FIELDS.
 * @return array<string, mixed>|WP_Error The new certificate.
 */
function reissue( array $old, array $changes ) {
	global $wpdb;

	$data = array_merge( array_intersect_key( $old, array_flip( FIELDS ) ), $changes );
	if ( ! isset( $changes['data'] ) ) {
		$kept         = json_decode( (string) ( $old['data_json'] ?? '' ), true );
		$data['data'] = is_array( $kept ) ? $kept : array();
	}
	$new = issue( $data );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		table(),
		array(
			'status'      => 'replaced',
			'replaced_by' => (int) $new['id'],
			'updated_at'  => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $old['id'] )
	);

	return $new;
}

/**
 * Revokes a certificate: its code then says it was revoked, with the reason.
 *
 * @param int    $id     Certificate ID.
 * @param string $reason Why (shown on the certificate page).
 * @return bool Whether it changed.
 */
function revoke( $id, $reason = '' ) {
	global $wpdb;

	return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		table(),
		array(
			'status'         => 'revoked',
			'revoked_reason' => sanitize_text_field( $reason ),
			'updated_at'     => current_time( 'mysql', true ),
		),
		array(
			'id'     => (int) $id,
			'status' => 'issued',
		)
	);
}

/**
 * The public address of a certificate (its verification page).
 *
 * @param string $code Certificate code.
 * @return string
 */
function url( $code ) {
	return home_url( user_trailingslashit( \EventsCertificate\Settings\value( 'slug' ) . '/' . $code ) );
}
