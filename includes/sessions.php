<?php
/**
 * The schedule (wc-post-types' sessions) as certificates use it: the talks of
 * a day people can declare they attended, the rule that two chosen talks
 * can't overlap (even in part; back to back is fine), and the hours.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Sessions;

use EventsCertificate\Days;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every session of a day, by start time: id, title, start, end (Unix), kind
 * (session = a talk, custom = opening, breaks…), speakers.
 *
 * @param array<string, mixed> $day Day (includes/days.php).
 * @return array<int, array{id: int, title: string, start: int, end: int, kind: string, speakers: string[]}>
 */
function of_day( array $day ) {
	if ( ! post_type_exists( 'wcb_session' ) ) {
		return array();
	}
	$out = array();
	foreach ( get_posts(
		array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	) as $post ) {
		$start = (int) get_post_meta( $post->ID, '_wcpt_session_time', true );
		if ( ! $start || wp_date( 'Y-m-d', $start ) !== $day['date'] ) {
			continue;
		}
		$speakers = array();
		foreach ( (array) get_post_meta( $post->ID, '_wcpt_speaker_id', false ) as $speaker ) {
			$name = get_the_title( (int) $speaker );
			if ( '' !== $name ) {
				$speakers[] = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
			}
		}
		$out[] = array(
			'id'       => (int) $post->ID,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'start'    => $start,
			'end'      => $start + max( 0, (int) get_post_meta( $post->ID, '_wcpt_session_duration', true ) ),
			'kind'     => 'session' === get_post_meta( $post->ID, '_wcpt_session_type', true ) ? 'session' : 'custom',
			'speakers' => $speakers,
		);
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return $a['start'] === $b['start'] ? strcmp( $a['title'], $b['title'] ) : $a['start'] <=> $b['start'];
		}
	);

	return $out;
}

/**
 * The talks of a day (what people choose from).
 *
 * @param array<string, mixed> $day Day.
 * @return array<int, array<string, mixed>>
 */
function talks( array $day ) {
	return array_values(
		array_filter(
			of_day( $day ),
			static function ( $session ) {
				return 'session' === $session['kind'];
			}
		)
	);
}

/**
 * Whether two sessions overlap, even in part (one ending as the other starts doesn't).
 *
 * @param array<string, int> $a Session.
 * @param array<string, int> $b Session.
 * @return bool
 */
function overlap( array $a, array $b ) {
	return $a['start'] < $b['end'] && $b['start'] < $a['end'];
}

/**
 * The chosen talks of a day, checked: from that day, at least one, none
 * overlapping another.
 *
 * @param array<string, mixed> $day Day.
 * @param int[]                $ids Chosen session IDs.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function pick( array $day, array $ids ) {
	$ids    = array_map( 'intval', $ids );
	$chosen = array_values(
		array_filter(
			talks( $day ),
			static function ( $session ) use ( $ids ) {
				return in_array( $session['id'], $ids, true );
			}
		)
	);
	if ( ! $chosen ) {
		/* translators: %s: day of the event. */
		return new WP_Error( 'evcert_no_talks', sprintf( __( 'Choose at least one talk you attended on %s.', 'events-certificate' ), Days\name( $day ) ) );
	}
	$count = count( $chosen );
	for ( $i = 0; $i < $count; $i++ ) {
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( overlap( $chosen[ $i ], $chosen[ $j ] ) ) {
				return new WP_Error(
					'evcert_overlap',
					/* translators: 1, 2: talk titles. */
					sprintf( __( '“%1$s” and “%2$s” happen at the same time: choose one.', 'events-certificate' ), $chosen[ $i ]['title'], $chosen[ $j ]['title'] )
				);
			}
		}
	}

	return $chosen;
}

/**
 * Minutes of a list of sessions.
 *
 * @param array<int, array<string, int>> $sessions Sessions.
 * @return int
 */
function minutes( array $sessions ) {
	$total = 0;
	foreach ( $sessions as $session ) {
		$total += max( 0, (int) $session['end'] - (int) $session['start'] );
	}

	return (int) round( $total / 60 );
}

/**
 * Minutes of a whole day (tables days): from its first item to its last.
 *
 * @param array<string, mixed> $day Day.
 * @return int
 */
function day_minutes( array $day ) {
	$span = day_span( $day );

	return $span ? (int) round( ( $span[1] - $span[0] ) / 60 ) : 0;
}

/**
 * A day's span: its first item's start and last item's end (a tables day's time).
 *
 * @param array<string, mixed> $day Day.
 * @return int[] [start, end], or [] without a schedule.
 */
function day_span( array $day ) {
	$all = of_day( $day );

	return $all ? array( (int) min( array_column( $all, 'start' ) ), (int) max( array_column( $all, 'end' ) ) ) : array();
}

/**
 * A session as frozen in a certificate.
 *
 * @param array<string, mixed> $session Session.
 * @return array<string, mixed>
 */
function frozen( array $session ) {
	return array(
		'id'       => (int) $session['id'],
		'title'    => (string) $session['title'],
		'start'    => (int) $session['start'],
		'end'      => (int) $session['end'],
		'speakers' => array_values( (array) $session['speakers'] ),
	);
}
