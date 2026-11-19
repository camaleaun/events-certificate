<?php
/**
 * Abilities: registered with complete metadata, and the event flow through
 * the REST bridge an MCP client uses.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

/**
 * Abilities tests (as an administrator; they delete what they make).
 */
class AbilitiesTest extends Test_Case {

	/**
	 * User before the test.
	 *
	 * @var int
	 */
	private $user = 0;

	/**
	 * Events made by the test.
	 *
	 * @var int[]
	 */
	private $events = array();

	/**
	 * As an administrator.
	 */
	protected function set_up() {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API.' );
		}
		$this->user = get_current_user_id();
		wp_set_current_user(
			(int) get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			)[0]
		);
	}

	/**
	 * Deletes the events, their people and certificates.
	 */
	protected function tear_down() {
		global $wpdb;

		foreach ( $this->events as $id ) {
			foreach ( \EventsCertificate\People\query( $id )['rows'] as $row ) {
				foreach ( array_filter( $row['roles'] ) as $certificate_id ) {
					$wpdb->delete( \EventsCertificate\Certificates\table(), array( 'id' => (int) $certificate_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			$wpdb->delete( \EventsCertificate\People\table(), array( 'event_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_post( $id, true );
		}
		wp_set_current_user( $this->user );
		parent::tear_down();
	}

	/**
	 * Runs an ability through REST, with the method its annotations ask for.
	 *
	 * @param string     $name  Ability.
	 * @param array|null $input Input.
	 * @return \WP_REST_Response
	 */
	private function run_ability( $name, $input = null ) {
		$annotations = wp_get_ability( $name )->get_meta()['annotations'];
		$method      = $annotations['readonly'] ? 'GET' : ( $annotations['destructive'] ? 'DELETE' : 'POST' );
		$request     = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );
		if ( null !== $input ) {
			if ( 'POST' === $method ) {
				$request->set_header( 'content-type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
			} else {
				$request->set_query_params( array( 'input' => $input ) );
			}
		}

		return rest_do_request( $request );
	}

	/**
	 * Every ability has a description, schemas, all three annotations and is
	 * public to REST and MCP.
	 */
	public function test_registered_with_metadata() {
		$names = array_filter(
			array_keys( wp_get_abilities() ),
			static function ( $name ) {
				return 0 === strpos( $name, 'events-certificates/' );
			}
		);
		// Every mode has the certificates, revoking and settings; the events and participants depend on the mode (decision 0026).
		$this->assertGreaterThanOrEqual( 5, count( $names ) );
		if ( \EventsCertificate\Environment\own_events() ) {
			$this->assertGreaterThanOrEqual( 14, count( $names ) );
		}
		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			$meta    = $ability->get_meta();
			$this->assertGreaterThan( 40, strlen( $ability->get_description() ), $name );
			$this->assertSame( 'object', $ability->get_input_schema()['type'], $name );
			foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
				$this->assertIsBool( $meta['annotations'][ $key ], "$name $key" );
			}
			$this->assertTrue( $meta['show_in_rest'], $name );
			$this->assertTrue( $meta['mcp']['public'], $name );
		}
	}

	/**
	 * Quick issue, statuses, issuing and revoking through REST; errors an
	 * agent can act on.
	 */
	public function test_flow() {
		if ( ! \EventsCertificate\Environment\own_events() ) {
			$this->markTestSkipped( 'Events and the quick issue exist only without CampTix and GatherPress.' );
		}
		$quick = $this->run_ability(
			'events-certificates/quick-issue',
			array(
				'title'        => 'Abilities Meetup',
				'start'        => '2026-11-01 19:00',
				'end'          => '2026-11-01 21:00',
				'participants' => array( array( 'name' => 'Pia Lins' ) ),
				'csv'          => "Name,RSVP,Attended\nSara Luz,Yes,No show\nTeo Vaz,No,\n",
			)
		);
		$this->assertSame( 200, $quick->get_status() );
		$data           = $quick->get_data();
		$this->events[] = $data['event']['id'];
		$this->assertCount( 1, $data['certificates'] );
		$this->assertSame( 2, $data['event']['participants']['all'] );

		$people = $this->run_ability(
			'events-certificates/get-participants',
			array(
				'event_id' => $data['event']['id'],
				'status'   => 'no_show',
			)
		)->get_data();
		$this->assertSame( 'Sara Luz', $people['participants'][0]['name'] );
		$this->run_ability(
			'events-certificates/set-participant-status',
			array(
				'event_id'        => $data['event']['id'],
				'participant_ids' => array( $people['participants'][0]['id'] ),
				'status'          => 'attended',
			)
		);
		$issued = $this->run_ability( 'events-certificates/issue-certificates', array( 'event_id' => $data['event']['id'] ) )->get_data();
		$this->assertSame( 2, $issued['issued'] );

		$revoked = $this->run_ability(
			'events-certificates/revoke-certificate',
			array(
				'code'   => $data['certificates'][0]['code'],
				'reason' => 'Test',
			)
		);
		$this->assertSame( 'revoked', $revoked->get_data()['status'] );

		$this->assertSame( 404, $this->run_ability( 'events-certificates/get-participants', array( 'event_id' => 999999 ) )->get_status() );
		$this->assertSame( 404, $this->run_ability( 'events-certificates/revoke-certificate', array( 'code' => 'AAAA-AAAA-AAAA-AAAA' ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->run_ability( 'events-certificates/get-overview' )->get_status() );
	}

	/**
	 * A person with several roles through REST: one certificate per role, the roles on the participant, a filter by role.
	 */
	public function test_roles_through_the_abilities() {
		if ( ! \EventsCertificate\Environment\own_events() ) {
			$this->markTestSkipped( 'Events and the quick issue exist only without CampTix and GatherPress.' );
		}
		$quick = $this->run_ability(
			'events-certificates/quick-issue',
			array(
				'title'        => 'Roles Meetup',
				'start'        => '2026-11-01 19:00',
				'end'          => '2026-11-01 21:00',
				'participants' => array(
					array(
						'name'  => 'Ana Lima',
						'roles' => array( 'speaker', 'organizer' ),
					),
					array(
						'name' => 'Ana Lima',
						'role' => 'volunteer',
					),
					array(
						'name' => 'Bruno Souza',
						'role' => 'speaker / organizer',
					),
				),
			)
		);
		$this->assertSame( 200, $quick->get_status() );
		$data           = $quick->get_data();
		$event          = $data['event']['id'];
		$this->events[] = $event;
		$this->assertCount( 5, $data['certificates'], 'Ana: three roles; Bruno: two.' );

		$people = $this->run_ability( 'events-certificates/get-participants', array( 'event_id' => $event ) )->get_data()['participants'];
		$this->assertCount( 2, $people );
		$ana = $people[0];
		$this->assertSame( array( 'speaker', 'organizer', 'volunteer' ), wp_list_pluck( $ana['roles'], 'role' ) );
		$this->assertSame( 'speaker', $ana['role'] );
		foreach ( $ana['roles'] as $row ) {
			$this->assertSame( \EventsCertificate\Designs\role_labels()[ $row['role'] ], $row['certificate']['role'], 'The certificate says the role by its label.' );
		}

		$speakers = $this->run_ability(
			'events-certificates/get-certificates',
			array(
				'event_id' => $event,
				'role'     => 'speaker',
			)
		)->get_data()['certificates'];
		$this->assertCount( 2, $speakers );

		// Revoke one of Ana's, issue again for her speaker role only.
		$volunteer = array_values(
			array_filter(
				$ana['roles'],
				static function ( $row ) {
					return 'volunteer' === $row['role'];
				}
			)
		)[0]['certificate'];
		$this->run_ability( 'events-certificates/revoke-certificate', array( 'code' => $volunteer['code'] ) );
		$again = $this->run_ability(
			'events-certificates/issue-certificates',
			array(
				'event_id'        => $event,
				'participant_ids' => array( $ana['id'] ),
				'roles'           => array( 'speaker', 'volunteer' ),
			)
		)->get_data();
		$this->assertSame( 1, $again['issued'], 'The speaker one is there; the volunteer one is revoked for good.' );
		$this->assertSame( 1, $again['revoked'] );
	}
}
