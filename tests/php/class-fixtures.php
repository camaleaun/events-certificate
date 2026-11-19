<?php
/**
 * Records the tests need: events, participants, CampTix attendees.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Events;
use EventsCertificate\People;

/**
 * Everything is made inside the test's transaction, so it is rolled back with it.
 */
final class Fixtures {

	/**
	 * An event of the plugin.
	 *
	 * @param array<string, mixed> $args Fields to change.
	 * @return int Event ID.
	 */
	public static function event( array $args = array() ) {
		return (int) Events\save(
			$args + array(
				'title'    => 'Meetup Lifecycle',
				'start'    => '2026-10-10 19:00',
				'end'      => '2026-10-10 21:30',
				'location' => 'Test City',
			)
		);
	}

	/**
	 * A participant who attended.
	 *
	 * @param int    $event_id Event.
	 * @param string $name     Name.
	 * @param string $email    E-mail.
	 * @return array<string, mixed> The participant.
	 */
	public static function attendee_of_event( $event_id, $name, $email = '' ) {
		People\import(
			$event_id,
			array(
				array(
					'name'  => $name,
					'email' => $email,
				),
			)
		);
		$person = People\query( $event_id, array( 'search' => $name ) )['rows'][0];
		People\set_status( array( (int) $person['id'] ), 'attended', $event_id );

		return (array) People\get( (int) $person['id'] );
	}

	/**
	 * A CampTix attendee (the CampTix scenarios only): confirmed, present, with the edit token the ticket e-mail links carry.
	 *
	 * @param array<string, string> $args first, last, email.
	 * @return \WP_Post
	 */
	public static function camptix_attendee( array $args = array() ) {
		$args = $args + array(
			'first' => 'Maria',
			'last'  => 'Camptix',
			'email' => 'maria.camptix@example.com',
		);
		$id   = wp_insert_post(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
				'post_title'  => $args['first'] . ' ' . $args['last'],
			)
		);
		update_post_meta( $id, 'tix_first_name', $args['first'] );
		update_post_meta( $id, 'tix_last_name', $args['last'] );
		update_post_meta( $id, 'tix_email', $args['email'] );
		update_post_meta( $id, 'tix_attended', 1 );
		update_post_meta( $id, 'tix_edit_token', 'abc123def456' );

		return get_post( $id );
	}

	/**
	 * A GatherPress event (the GatherPress scenarios only).
	 *
	 * @param string $title Title.
	 * @return int Post ID.
	 */
	public static function gatherpress_event( $title = 'Meetup GatherPress' ) {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * An RSVP to a GatherPress event, as GatherPress stores it: a comment of its own type, with its status and flags as terms.
	 *
	 * @param int    $event_id GatherPress event.
	 * @param string $name     Name.
	 * @param string $email    E-mail.
	 * @param string $status   attending|not_attending|waiting_list.
	 * @param bool   $checked  Whether they checked in.
	 * @return int Comment ID.
	 */
	public static function gatherpress_rsvp( $event_id, $name, $email, $status = 'attending', $checked = false ) {
		$id = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $event_id,
				'comment_type'         => 'gatherpress_rsvp',
				'comment_approved'     => 1,
				'comment_author'       => $name,
				'comment_author_email' => $email,
			)
		);
		wp_set_object_terms( $id, $status, '_gatherpress_rsvp_status' );
		if ( $checked ) {
			wp_set_object_terms( $id, 'checked-in', '_gatherpress_rsvp_flag' );
		}

		return $id;
	}
}
