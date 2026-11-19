<?php
/**
 * The GatherPress organizers as a role of the people of an event.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

/**
 * Organizers are people with the organizer role; one who also RSVPed is one person with two roles.
 */
class GatherPressRolesTest extends Test_Case {

	/**
	 * Only with GatherPress.
	 */
	protected function set_up() {
		parent::set_up();
		if ( ! function_exists( 'EventsCertificate\Compat\GatherPress\add_organizers' ) ) {
			$this->markTestSkipped( 'GatherPress is not active.' );
		}
	}

	/**
	 * Creates a user (the test's transaction rolls it back).
	 *
	 * @param array<string, string> $data Fields.
	 * @return int
	 */
	private function user( array $data ) {
		static $n = 0;
		++$n;

		return (int) wp_insert_user(
			$data + array(
				'user_login' => 'gprole' . $n . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
			)
		);
	}

	/**
	 * A user the site lists as organizer, through the filter.
	 *
	 * @param int[] $ids User IDs.
	 * @return callable The filter, to remove.
	 */
	private function organizers( array $ids ) {
		$filter = static function () use ( $ids ) {
			return array( 'organizer' => $ids );
		};
		add_filter( 'evcert_gatherpress_roles', $filter );

		return $filter;
	}

	/**
	 * Someone with an RSVP gains the role; someone without one is a new person who attended.
	 */
	public function test_organizers_join_the_participants() {
		$rsvp   = $this->user(
			array(
				'user_email'   => 'org-rsvp@example.test',
				'display_name' => 'Org Rsvp',
			)
		);
		$only   = $this->user(
			array(
				'user_email'   => 'org-only@example.test',
				'display_name' => 'Org Only',
			)
		);
		$filter = $this->organizers( array( $rsvp, $only ) );

		$people = \EventsCertificate\Compat\GatherPress\add_organizers(
			array(
				array(
					'name'       => 'Org Rsvp',
					'email'      => 'ORG-RSVP@example.test',
					'source'     => 'gatherpress',
					'source_ref' => '5',
				),
			)
		);
		remove_filter( 'evcert_gatherpress_roles', $filter );

		$this->assertCount( 2, $people );
		$this->assertSame( array( 'attendee', 'organizer' ), $people[0]['roles'], 'The RSVP person gains the role.' );
		$this->assertSame( array( 'organizer' ), $people[1]['roles'] );
		$this->assertSame( 'attended', $people[1]['status'], 'An organizer was there.' );
		$this->assertArrayNotHasKey( 'status', $people[0], 'The RSVP person\'s status is the check-in\'s.' );
	}

	/**
	 * A user that no longer exists is skipped; a site adds roles with the filter.
	 */
	public function test_unknown_users_are_skipped_and_the_filter_adds_roles() {
		$user   = $this->user( array( 'user_email' => 'vol@example.test' ) );
		$filter = static function () use ( $user ) {
			return array(
				'organizer' => array( 999999 ),
				'volunteer' => array( $user ),
			);
		};
		add_filter( 'evcert_gatherpress_roles', $filter );
		$people = \EventsCertificate\Compat\GatherPress\add_organizers( array() );
		remove_filter( 'evcert_gatherpress_roles', $filter );

		$this->assertCount( 1, $people );
		$this->assertSame( array( 'volunteer' ), $people[0]['roles'] );
	}
}
