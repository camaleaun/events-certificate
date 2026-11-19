<?php
/**
 * The role of a CampTix ticket's certificate.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Compat\CampTix;

/**
 * A speaker ticket's certificate is a speaker's; the filter has the last word.
 */
class CampTixRolesTest extends Test_Case {

	/**
	 * Only with CampTix.
	 */
	protected function set_up() {
		parent::set_up();
		if ( ! function_exists( 'EventsCertificate\Compat\CampTix\attendee_role' ) || ! post_type_exists( 'tix_ticket' ) ) {
			$this->markTestSkipped( 'CampTix is not active.' );
		}
	}

	/**
	 * An attendee with a ticket of a role.
	 *
	 * @param string $role Role of the ticket ('' for none).
	 * @return \WP_Post
	 */
	private function attendee( $role ) {
		$ticket = wp_insert_post(
			array(
				'post_type'   => 'tix_ticket',
				'post_status' => 'publish',
				'post_title'  => 'Ticket ' . $role,
			)
		);
		if ( '' !== $role ) {
			update_post_meta( $ticket, CampTix\ROLE_META, $role );
		}
		$attendee = wp_insert_post(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
				'post_title'  => 'Someone',
			)
		);
		update_post_meta( $attendee, 'tix_ticket_id', $ticket );

		return get_post( $attendee );
	}

	/**
	 * Ticket to role.
	 */
	public function test_the_ticket_gives_the_role() {
		$this->assertSame( 'speaker', CampTix\attendee_role( $this->attendee( 'speaker' ) ) );
		$this->assertSame( 'volunteer', CampTix\attendee_role( $this->attendee( 'volunteer' ) ) );
		$this->assertSame( 'attendee', CampTix\attendee_role( $this->attendee( '' ) ), 'No role set: an attendee.' );
		$this->assertSame( 'attendee', CampTix\attendee_role( $this->attendee( 'wizard' ) ), 'A role that does not exist.' );
	}

	/**
	 * The filter can change it.
	 */
	public function test_the_filter_has_the_last_word() {
		$filter = static function () {
			return 'organizer';
		};
		add_filter( 'evcert_camptix_role', $filter );
		$role = CampTix\attendee_role( $this->attendee( 'speaker' ) );
		remove_filter( 'evcert_camptix_role', $filter );

		$this->assertSame( 'organizer', $role );
	}
}
