<?php
/**
 * The people list: roles as tags, and the actions around them.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\EventsAdmin;
use EventsCertificate\People;

/**
 * What the Events screen shows of a person's roles.
 */
class PeopleListTest extends Test_Case {

	/**
	 * The event.
	 *
	 * @var int
	 */
	private $event;

	/**
	 * The table of its people.
	 *
	 * @var EventsAdmin\People_List_Table
	 */
	private $table;

	/**
	 * An event and the list table.
	 */
	protected function set_up() {
		parent::set_up();
		// Admin screens, not loaded outside wp-admin.
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once dirname( __DIR__, 2 ) . '/includes/events-admin.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-people-list-table.php';
		$this->event = Fixtures::event();
		$this->table = new EventsAdmin\People_List_Table( $this->event );
	}

	/**
	 * A protected method of the table.
	 *
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $this->table, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->table, $args );
	}

	/**
	 * A person with roles.
	 *
	 * @param string[] $roles Roles.
	 * @return array<string, mixed>
	 */
	private function person( array $roles ) {
		$person = Fixtures::attendee_of_event( $this->event, 'Ana Lima', 'ana@example.test' );
		People\add_roles( (int) $person['id'], $roles );

		return People\get( (int) $person['id'] );
	}

	/**
	 * Roles are links that filter the list, like a post's categories; none would be a dash.
	 */
	public function test_roles_are_links_that_filter() {
		$person = $this->person( array( 'speaker', 'organizer' ) );

		$html = $this->call( 'column_roles', array( $person ) );

		$this->assertMatchesRegularExpression( '#<a href="[^"]*role=attendee[^"]*">Attendee</a>, <a href="[^"]*role=speaker[^"]*">Speaker</a>, <a href="[^"]*role=organizer[^"]*">Organizer</a>#', $html );
		$this->assertStringNotContainsString( 'evcert-role', $html, 'No tokens.' );
		$this->assertStringNotContainsString( '<select', $html, 'No select in the row.' );
	}

	/**
	 * The certificates of a person, one line per role: its code (a link), revoked, or a dash.
	 */
	public function test_certificates_column_has_a_line_per_role() {
		$person = $this->person( array( 'speaker', 'organizer' ) );
		People\issue( $person, 'speaker' );
		$revoked = People\issue( People\get( (int) $person['id'] ), 'organizer' );
		Certificates\revoke( (int) $revoked['id'] );
		$person = People\get( (int) $person['id'] );

		$html = $this->call( 'column_certificates', array( $person ) );

		$this->assertStringContainsString( 'Speaker', $html );
		$this->assertStringContainsString( Certificates\url( People\certificate( $person, 'speaker' )['code'] ), $html );
		$this->assertStringContainsString( '<s>', $html, 'A revoked certificate is struck.' );
		$this->assertStringContainsString( '&#8212;', $html, 'The attendee role has no certificate yet.' );
		$this->assertSame( 3, substr_count( $html, '<br>' ) + 1, 'A line per role.' );
	}

	/**
	 * The filter above the list is a select of the roles the event has, with a Filter button.
	 */
	public function test_the_role_filter_is_a_select() {
		$this->person( array( 'speaker' ) );

		ob_start();
		$this->call( 'extra_tablenav', array( 'top' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<select name="role" id="filter-by-role">', $html );
		$filter = substr( $html, 0, (int) strpos( $html, '</select>' ) );
		$this->assertStringContainsString( '<option value="speaker"', $filter );
		$this->assertStringNotContainsString( '<option value="organizer"', $filter, 'Only the roles the event has.' );
		$this->assertStringContainsString( 'name="filter_action"', $html );
		$this->assertStringContainsString( 'name="evcert_role_do"', $html, 'Add role and Remove role buttons, with their select.' );
	}

	/**
	 * Adding a role and taking one off, in bulk, through the events screen's bulk action.
	 */
	public function test_roles_in_bulk() {
		$person = $this->person( array( 'speaker' ) );

		$this->assertSame( 1, EventsAdmin\change_roles( array( (int) $person['id'] ), 'organizer', 'add', $this->event ) );
		$this->assertArrayHasKey( 'organizer', People\get( (int) $person['id'] )['roles'] );

		$this->assertSame( 1, EventsAdmin\change_roles( array( (int) $person['id'] ), 'organizer', 'remove', $this->event ) );
		$this->assertArrayNotHasKey( 'organizer', People\get( (int) $person['id'] )['roles'] );

		People\issue( People\get( (int) $person['id'] ), 'speaker' );
		$this->assertSame( 0, EventsAdmin\change_roles( array( (int) $person['id'] ), 'speaker', 'remove', $this->event ), 'A role with a certificate stays.' );
		$this->assertSame( 0, EventsAdmin\change_roles( array( 999999 ), 'organizer', 'add', $this->event ), 'Only people of this event.' );
	}

	/**
	 * The person's form: name, e-mail and the roles as a checklist; a role with a certificate cannot be unchecked.
	 */
	public function test_the_person_form_saves_name_and_roles() {
		$person = $this->person( array( 'speaker' ) );
		People\issue( $person, 'speaker' );

		$done  = EventsAdmin\save_person_data(
			(int) $person['id'],
			array(
				'name'  => 'Ana Lima Souza',
				'email' => 'ana.souza@example.test',
				'roles' => array( 'volunteer' ),
			)
		);
		$after = People\get( (int) $person['id'] );

		$this->assertTrue( $done );
		$this->assertSame( 'Ana Lima Souza', $after['name'] );
		$this->assertSame( 'ana.souza@example.test', $after['email'] );
		$this->assertSame( array( 'speaker', 'volunteer' ), array_values( array_intersect( array( 'attendee', 'speaker', 'organizer', 'volunteer' ), array_keys( $after['roles'] ) ) ), 'The speaker (with certificate) stays; attendee had none and was unchecked; volunteer added.' );

		$this->assertFalse(
			EventsAdmin\save_person_data(
				(int) $person['id'],
				array(
					'name'  => '',
					'email' => '',
					'roles' => array(),
				)
			),
			'A name is required.'
		);
	}

	/**
	 * The bulk actions gain one issue action per role once the event has people in more than one.
	 */
	public function test_bulk_issue_per_role_appears_with_several_roles() {
		$this->person( array( 'speaker' ) );
		$actions = $this->call( 'get_bulk_actions' );
		$this->assertArrayHasKey( 'issue', $actions );
		$this->assertArrayHasKey( 'issue_speaker', $actions );
		$this->assertArrayHasKey( 'issue_attendee', $actions );

		$alone = new EventsAdmin\People_List_Table( Fixtures::event( array( 'title' => 'Other' ) ) );
		$only  = new \ReflectionMethod( $alone, 'get_bulk_actions' );
		$only->setAccessible( true );
		$this->assertArrayNotHasKey( 'issue_speaker', $only->invoke( $alone ) );
	}

	/**
	 * Only the people of a role, when a role is asked for.
	 */
	public function test_the_query_filters_by_role() {
		$this->person( array( 'speaker' ) );
		People\import( $this->event, array( array( 'name' => 'Carla Dias' ) ) );

		$this->assertSame( 1, People\query( $this->event, array( 'role' => 'speaker' ) )['total'] );
		$this->assertSame( 2, People\query( $this->event, array( 'role' => 'attendee' ) )['total'] );
		$this->assertSame(
			array(
				'attendee' => 2,
				'speaker'  => 1,
			),
			People\counts( $this->event )['roles']
		);
	}
}
