<?php
/**
 * The event's days: each with a date, a name per language, a mode (tables, as
 * on a Contributor Day, or sessions, as at the conference) and, for tables,
 * the tables people can join. Until saved, they come from the schedule
 * (wc-post-types' sessions, grouped by their local date). Presence is kept per
 * day on each attendee (includes/compat/camptix-days.php).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Days;

defined( 'ABSPATH' ) || exit;

/**
 * Option with the saved days.
 */
const OPTION = 'evcert_days';

/**
 * Day modes.
 */
const MODES = array( 'sessions', 'table' );

/**
 * The days, saved or else from the schedule; sorted by date.
 *
 * @return array<int, array{id: string, date: string, names: array<string, string>, mode: string, tables: array<string, string[]>}>
 */
function all() {
	$saved = get_option( OPTION );
	$days  = is_array( $saved ) && $saved ? $saved : from_schedule();
	$days  = array_values( array_filter( array_map( __NAMESPACE__ . '\clean', (array) $days ) ) );
	usort(
		$days,
		static function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);

	return $days;
}

/**
 * One day by its ID.
 *
 * @param string $id Day ID.
 * @return array<string, mixed>|null
 */
function get( $id ) {
	foreach ( all() as $day ) {
		if ( $day['id'] === $id ) {
			return $day;
		}
	}

	return null;
}

/**
 * A day's name in a language: its own, else the site language's, else the
 * date in that language.
 *
 * @param array<string, mixed> $day    Day.
 * @param string               $locale Locale ('' = the current one).
 * @return string
 */
function name( array $day, $locale = '' ) {
	$locale = '' !== $locale ? $locale : determine_locale();
	$names  = (array) ( $day['names'] ?? array() );
	foreach ( array( $locale, get_locale() ) as $l ) {
		if ( ! empty( $names[ $l ] ) ) {
			return (string) $names[ $l ];
		}
	}

	return short_date( $day );
}

/**
 * A day's date, short (as on badges): "30/10" or "10/30" by the language.
 *
 * @param array<string, mixed> $day Day.
 * @return string
 */
function short_date( array $day ) {
	$ts = strtotime( $day['date'] . ' 12:00:00 ' . wp_timezone_string() );

	/* translators: Short date of an event day, as on a badge: PHP date format (day and month). */
	return wp_date( _x( 'd/m', 'short event day', 'events-certificate' ), $ts );
}

/**
 * The days found in the schedule: one per local date with sessions; a day
 * with talks is a "sessions" day, one with only fixed items a "tables" day.
 *
 * @return array<int, array<string, mixed>>
 */
function from_schedule() {
	if ( ! post_type_exists( 'wcb_session' ) ) {
		return array();
	}
	$talks = array();
	foreach ( get_posts(
		array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	) as $id ) {
		$time = (int) get_post_meta( $id, '_wcpt_session_time', true );
		if ( ! $time ) {
			continue;
		}
		$date           = wp_date( 'Y-m-d', $time );
		$talks[ $date ] = ( $talks[ $date ] ?? false ) || 'session' === get_post_meta( $id, '_wcpt_session_type', true );
	}
	ksort( $talks );
	$days = array();
	foreach ( $talks as $date => $has_talks ) {
		$days[] = array(
			'id'     => $date,
			'date'   => $date,
			'names'  => array(),
			'mode'   => $has_talks ? 'sessions' : 'table',
			'tables' => array(),
		);
	}

	return $days;
}

/**
 * A day with known keys and clean values, or null when it has no valid date.
 *
 * @param mixed $day Day.
 * @return array<string, mixed>|null
 */
function clean( $day ) {
	$day  = (array) $day;
	$date = (string) ( $day['date'] ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! strtotime( $date ) ) {
		return null;
	}
	$names = array();
	foreach ( (array) ( $day['names'] ?? array() ) as $locale => $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' !== $name && preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', (string) $locale ) ) {
			$names[ (string) $locale ] = $name;
		}
	}
	$tables = array();
	foreach ( (array) ( $day['tables'] ?? array() ) as $locale => $list ) {
		$list = is_array( $list ) ? $list : preg_split( '/\R/', (string) $list );
		$list = array_values( array_filter( array_map( 'sanitize_text_field', (array) $list ) ) );
		if ( $list ) {
			$tables[ (string) $locale ] = $list;
		}
	}

	return array(
		'id'     => $date,
		'date'   => $date,
		'names'  => $names,
		'mode'   => in_array( $day['mode'] ?? '', MODES, true ) ? $day['mode'] : 'sessions',
		'tables' => $tables,
	);
}

/**
 * Saves the days (from the settings form).
 *
 * @param mixed $days Days.
 */
function save( $days ) {
	$clean = array_values( array_filter( array_map( __NAMESPACE__ . '\clean', (array) $days ) ) );
	update_option( OPTION, $clean, false );
}

/**
 * A day's tables in a language: its own list, else the site language's, else any.
 *
 * @param array<string, mixed> $day    Day.
 * @param string               $locale Locale.
 * @return string[]
 */
function tables( array $day, $locale = '' ) {
	$all    = (array) ( $day['tables'] ?? array() );
	$locale = '' !== $locale ? $locale : determine_locale();
	foreach ( array( $locale, get_locale() ) as $l ) {
		if ( ! empty( $all[ $l ] ) ) {
			return $all[ $l ];
		}
	}

	return $all ? (array) reset( $all ) : array();
}
