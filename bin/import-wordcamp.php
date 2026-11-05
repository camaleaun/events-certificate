<?php
/**
 * Dev tool: copies a WordCamp's schedule and team from its public REST API
 * into this site's wc-post-types (tracks, session categories, organizer
 * teams, speakers, sessions, organizers), to test certificates with real
 * data. Not shipped (.distignore). Run again to refresh: posts are matched by
 * the source ID kept in `_evcert_import_id`.
 *
 *   studio wp eval-file wp-content/plugins/events-certificate/bin/import-wordcamp.php https://brasil.wordcamp.org/2026
 *
 * @package EventsCertificate
 */

// phpcs:disable -- a dev script run with WP-CLI.

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run with wp eval-file.\n" );
}
if ( ! post_type_exists( 'wcb_session' ) ) {
	WP_CLI::error( 'Activate wc-post-types first.' );
}

$base = rtrim( $args[0] ?? 'https://brasil.wordcamp.org/2026', '/' ) . '/wp-json/wp/v2/';
wp_set_current_user( 1 );

$get = static function ( $route ) use ( $base ) {
	$all  = array();
	$page = 1;
	do {
		$response = wp_remote_get( $base . $route . ( false === strpos( $route, '?' ) ? '?' : '&' ) . 'per_page=100&page=' . $page, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $route . ': ' . $response->get_error_message() );
		}
		$items = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $items ) || isset( $items['code'] ) ) {
			break;
		}
		$all   = array_merge( $all, $items );
		$total = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
		$page++;
	} while ( $page <= $total );

	return $all;
};

// Terms: source term ID => local term ID.
$terms = array();
foreach ( array(
	'session_track'    => 'wcb_track',
	'session_category' => 'wcb_session_category',
	'organizer_team'   => 'wcb_organizer_team',
) as $route => $taxonomy ) {
	foreach ( $get( $route ) as $term ) {
		$existing = get_term_by( 'slug', $term['slug'], $taxonomy );
		$local    = $existing ? $existing->term_id : wp_insert_term( html_entity_decode( $term['name'] ), $taxonomy, array( 'slug' => $term['slug'] ) )['term_id'] ?? 0;
		$terms[ $taxonomy ][ $term['id'] ] = (int) $local;
	}
	WP_CLI::log( sprintf( '%s: %d', $taxonomy, count( $terms[ $taxonomy ] ?? array() ) ) );
}

$upsert = static function ( $type, $item, $meta ) {
	$found = get_posts(
		array(
			'post_type'   => $type,
			'post_status' => 'any',
			'meta_key'    => '_evcert_import_id',
			'meta_value'  => (string) $item['id'],
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$id    = wp_insert_post(
		array(
			'ID'           => $found ? $found[0] : 0,
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => html_entity_decode( $item['title']['rendered'] ?? '' ),
			'post_name'    => $item['slug'] ?? '',
			'post_content' => $item['content']['rendered'] ?? '',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_evcert_import_id', (string) $item['id'] );
	foreach ( $meta as $key => $value ) {
		delete_post_meta( $id, $key );
		foreach ( (array) $value as $v ) {
			add_post_meta( $id, $key, $v );
		}
	}

	return $id;
};

$speakers = array();
foreach ( $get( 'speakers' ) as $speaker ) {
	$speakers[ $speaker['id'] ] = $upsert( 'wcb_speaker', $speaker, array( '_wcpt_user_name' => $speaker['meta']['_wcpt_user_name'] ?? '' ) );
}
WP_CLI::log( 'speakers: ' . count( $speakers ) );

$sessions = 0;
foreach ( $get( 'sessions' ) as $session ) {
	$meta = $session['meta'] ?? array();
	$id   = $upsert(
		'wcb_session',
		$session,
		array(
			'_wcpt_session_time'     => (int) ( $meta['_wcpt_session_time'] ?? 0 ),
			'_wcpt_session_duration' => (int) ( $meta['_wcpt_session_duration'] ?? 3000 ),
			'_wcpt_session_type'     => $meta['_wcpt_session_type'] ?? 'session',
			'_wcpt_speaker_id'       => array_values( array_filter( array_map( static function ( $sid ) use ( $speakers ) {
				return $speakers[ $sid ] ?? 0;
			}, (array) ( $meta['_wcpt_speaker_id'] ?? array() ) ) ) ),
		)
	);
	foreach ( array(
		'session_track'    => 'wcb_track',
		'session_category' => 'wcb_session_category',
	) as $field => $taxonomy ) {
		wp_set_object_terms( $id, array_values( array_filter( array_map( static function ( $tid ) use ( $terms, $taxonomy ) {
			return $terms[ $taxonomy ][ $tid ] ?? 0;
		}, (array) ( $session[ $field ] ?? array() ) ) ) ), $taxonomy );
	}
	$sessions++;
}
WP_CLI::log( 'sessions: ' . $sessions );

$organizers = 0;
foreach ( $get( 'organizers' ) as $organizer ) {
	$id = $upsert( 'wcb_organizer', $organizer, array( '_wcpt_user_name' => $organizer['meta']['_wcpt_user_name'] ?? '' ) );
	wp_set_object_terms( $id, array_values( array_filter( array_map( static function ( $tid ) use ( $terms ) {
		return $terms['wcb_organizer_team'][ $tid ] ?? 0;
	}, (array) ( $organizer['organizer_team'] ?? array() ) ) ) ), 'wcb_organizer_team' );
	$organizers++;
}
WP_CLI::success( sprintf( 'Imported %d sessions, %d speakers, %d organizers from %s', $sessions, count( $speakers ), $organizers, $base ) );
