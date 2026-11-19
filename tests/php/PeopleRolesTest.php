<?php
/**
 * One person, several roles, one certificate per role.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Install;
use EventsCertificate\People;

/**
 * A participant who is also a speaker and an organizer gets a certificate of each (docs/decisions/0025).
 */
class PeopleRolesTest extends Test_Case {

	/**
	 * The event.
	 *
	 * @var int
	 */
	private $event;

	/**
	 * An event.
	 */
	protected function set_up() {
		parent::set_up();
		$this->event = Fixtures::event();
	}

	/**
	 * A person with the given roles, who attended.
	 *
	 * @param string   $name  Name.
	 * @param string[] $roles Roles.
	 * @return array<string, mixed>
	 */
	private function person( $name, array $roles ) {
		People\import(
			$this->event,
			array(
				array(
					'name'   => $name,
					'email'  => str_replace( ' ', '.', strtolower( $name ) ) . '@example.test',
					'roles'  => $roles,
					'status' => 'attended',
				),
			)
		);

		return People\query( $this->event, array( 'search' => $name ) )['rows'][0];
	}

	/**
	 * The same person added again with another role gains it: no second row, no role lost.
	 */
	public function test_a_person_gains_roles_instead_of_being_overwritten() {
		People\import(
			$this->event,
			array(
				array(
					'name' => 'Ana Lima',
					'role' => 'speaker',
				),
				array(
					'name' => 'Ana Lima',
					'role' => 'organizer',
				),
				array( 'name' => 'Ana Lima' ),
			)
		);

		$rows = People\query( $this->event )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'speaker', 'organizer' ), array_keys( $rows[0]['roles'] ), 'In the order they came; a line with no role adds none.' );
		$this->assertSame( 'speaker', $rows[0]['role'], 'The first role is the primary one.' );
	}

	/**
	 * A person who is new and gives no role is an attendee; unknown roles are not roles.
	 */
	public function test_the_default_role_is_attendee() {
		People\import(
			$this->event,
			array(
				array( 'name' => 'Bruno Souza' ),
				array(
					'name'  => 'Carla Dias',
					'roles' => array( 'wizard', 'volunteer' ),
				),
			)
		);

		$by_name = array();
		foreach ( People\query( $this->event )['rows'] as $row ) {
			$by_name[ $row['name'] ] = array_keys( $row['roles'] );
		}
		$this->assertSame( array( 'attendee' ), $by_name['Bruno Souza'] );
		$this->assertSame( array( 'volunteer' ), $by_name['Carla Dias'] );
	}

	/**
	 * Pasted lines and the Meetup export take several roles; a line repeated is the same person.
	 */
	public function test_lines_and_rows_take_several_roles() {
		$people = People\parse_lines( "Ana Lima, ana@example.test, speaker / organizer\nAna Lima, volunteer\nBruno Souza\tSpeaker + Volunteer" );

		$this->assertSame( array( 'speaker', 'organizer' ), $people[0]['roles'] );
		$this->assertSame( array( 'volunteer' ), $people[1]['roles'] );
		$this->assertSame( array( 'speaker', 'volunteer' ), $people[2]['roles'], 'The labels in the language shown too.' );

		People\import( $this->event, $people );
		$rows = People\query( $this->event )['rows'];
		$this->assertCount( 2, $rows );

		$parsed = People\parse_rows(
			array(
				array( 'Name', 'Email', 'Papel' ),
				array( 'Davi Souza', 'davi@example.test', 'organizer, speaker' ),
			)
		);
		$this->assertSame( array( 'organizer', 'speaker' ), $parsed['people'][0]['roles'] );
	}

	/**
	 * Issuing makes one certificate per role, each with its code and its role; a second issue makes none.
	 */
	public function test_one_certificate_per_role() {
		$person = $this->person( 'Ana Lima', array( 'speaker', 'organizer' ) );

		$result = People\issue_many( array( (int) $person['id'] ), $this->event );
		$this->assertSame( 2, $result['issued'] );
		$this->assertSame( array( 'speaker', 'organizer' ), wp_list_pluck( $result['certificates'], 'role' ) );
		$this->assertCount( 2, array_unique( wp_list_pluck( $result['certificates'], 'code' ) ) );

		$person = People\get( (int) $person['id'] );
		$this->assertSame( 'speaker', People\certificate( $person, 'speaker' )['role'] );
		$this->assertSame( 'organizer', People\certificate( $person, 'organizer' )['role'] );
		$this->assertNull( People\certificate( $person, 'volunteer' ) );

		$again = People\issue_many( array( (int) $person['id'] ), $this->event );
		$this->assertSame( 2, $again['issued'] );
		$this->assertSame( wp_list_pluck( $result['certificates'], 'id' ), wp_list_pluck( $again['certificates'], 'id' ), 'The same two, no third.' );
		$this->assertCount( 2, People\certificates( $this->event ) );
	}

	/**
	 * Only the roles asked for are issued.
	 */
	public function test_issuing_can_be_limited_to_some_roles() {
		$person = $this->person( 'Ana Lima', array( 'speaker', 'organizer' ) );

		$result = People\issue_many( array( (int) $person['id'] ), $this->event, array( 'organizer' ) );

		$this->assertSame( 1, $result['issued'] );
		$this->assertSame( 'organizer', $result['certificates'][0]['role'] );
		$this->assertNull( People\certificate( People\get( (int) $person['id'] ), 'speaker' ) );
	}

	/**
	 * Correcting or revoking one role leaves the other alone; revoked is final for that role only.
	 */
	public function test_a_correction_or_a_revocation_is_per_role() {
		$person = $this->person( 'Ana Lima', array( 'speaker', 'organizer' ) );
		People\issue_many( array( (int) $person['id'] ), $this->event );
		$person  = People\get( (int) $person['id'] );
		$speaker = People\certificate( $person, 'speaker' );
		$organiz = People\certificate( $person, 'organizer' );

		$fixed = Certificates\reissue( $speaker, array( 'name' => 'Ana Lima Corrigida' ) );
		$this->assertSame( 'speaker', $fixed['role'] );
		$person = People\get( (int) $person['id'] );
		$this->assertSame( $fixed['id'], People\certificate( $person, 'speaker' )['id'], 'Followed to the corrected one.' );
		$this->assertSame( $organiz['id'], People\certificate( $person, 'organizer' )['id'], 'The other role untouched.' );

		Certificates\revoke( (int) $organiz['id'], 'Wrong role' );
		$result = People\issue_many( array( (int) $person['id'] ), $this->event );
		$this->assertSame( 1, $result['issued'], 'The speaker certificate is still valid.' );
		$this->assertSame( 1, $result['revoked'], 'The organizer one is revoked for good.' );
		$this->assertSame( 'evcert_revoked', People\issue( People\get( (int) $person['id'] ), 'organizer' )->get_error_code() );
	}

	/**
	 * A role is taken off only while it has no certificate.
	 */
	public function test_a_role_with_a_certificate_cannot_be_removed() {
		$person = $this->person( 'Ana Lima', array( 'speaker', 'organizer' ) );
		People\issue_many( array( (int) $person['id'] ), $this->event, array( 'speaker' ) );

		$this->assertFalse( People\remove_role( (int) $person['id'], 'speaker' ), 'It has a certificate.' );
		$this->assertTrue( People\remove_role( (int) $person['id'], 'organizer' ) );
		$this->assertSame( array( 'speaker' ), array_keys( People\get( (int) $person['id'] )['roles'] ) );

		People\add_roles( (int) $person['id'], array( 'volunteer', 'wizard' ) );
		$this->assertSame( array( 'speaker', 'volunteer' ), array_keys( People\get( (int) $person['id'] )['roles'] ) );
	}

	/**
	 * The count is of certificates; the people who have any are counted apart.
	 */
	public function test_the_counts() {
		$ana   = $this->person( 'Ana Lima', array( 'speaker', 'organizer' ) );
		$bruno = $this->person( 'Bruno Souza', array( 'attendee' ) );
		People\issue_many( array( (int) $ana['id'], (int) $bruno['id'] ), $this->event );

		$counts = People\counts( $this->event );
		$this->assertSame( 3, $counts['certified'] );
		$this->assertSame( 2, $counts['people_certified'] );
		$this->assertSame( 2, $counts['all'] );
		$this->assertSame( 2, People\query( $this->event, array( 'status' => 'certified' ) )['total'] );
	}

	/**
	 * The match across sources (the same e-mail and event) is per role: the speaker's does not stand for the attendee's.
	 */
	public function test_the_twin_match_is_per_role() {
		$person = $this->person( 'Ana Lima', array( 'speaker' ) );
		$cert   = People\issue( $person, 'speaker' );

		$this->assertSame( $cert['id'], Certificates\find_for_person( 'ana.lima@example.test', $cert['event_title'], 'camptix', 'speaker' )['id'] );
		$this->assertNull( Certificates\find_for_person( 'ana.lima@example.test', $cert['event_title'], 'camptix', 'attendee' ) );
		$this->assertNull( Certificates\find_by_source( People\SOURCE, (int) $person['id'], 'attendee' ) );
		$this->assertSame( $cert['id'], Certificates\find_by_source( People\SOURCE, (int) $person['id'], 'speaker' )['id'] );
	}

	/**
	 * Taking a person off the list leaves the certificates (records of their own) and drops the roles.
	 */
	public function test_removing_a_person_keeps_the_certificates() {
		global $wpdb;

		$person = $this->person( 'Ana Lima', array( 'speaker' ) );
		$cert   = People\issue( $person, 'speaker' );

		People\remove( array( (int) $person['id'] ), $this->event );

		$this->assertSame( 'issued', Certificates\get( (int) $cert['id'] )['status'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE person_id = %d', Install\tables()['person_roles'], (int) $person['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	/**
	 * A site on schema 2 keeps its roles and certificates: the single role and certificate of each person move to the new table.
	 */
	public function test_the_schema_2_data_moves_to_the_roles_table() {
		global $wpdb;

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', People\table(), 'certificate_id' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$this->markTestSkipped( 'This site never had schema 2: its people table has no role or certificate column.' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			People\table(),
			array(
				'event_id'       => $this->event,
				'name'           => 'Legado Antigo',
				'role'           => 'speaker',
				'status'         => 'attended',
				'certificate_id' => 4242,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$id = (int) $wpdb->insert_id;

		Install\migrate( '2' );
		Install\migrate( '2' ); // Twice: nothing doubles.

		$this->assertSame( array( 'speaker' => 4242 ), People\get( $id )['roles'] );
	}
}
