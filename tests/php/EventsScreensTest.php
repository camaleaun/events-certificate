<?php
/**
 * The Events screens: the list, the event's form, its participants and the form to add them.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\EventsAdmin;

/**
 * Each screen has one job (docs/decisions/0026): a wide list is not on the same screen as a form.
 */
class EventsScreensTest extends Test_Case {

	/**
	 * Admin screens, not loaded outside wp-admin.
	 */
	protected function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once dirname( __DIR__, 2 ) . '/includes/events-admin.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-events-list-table.php';
	}

	/**
	 * Clean query.
	 */
	protected function tear_down() {
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * The screen asked for by the address.
	 */
	public function test_the_view_comes_from_the_address() {
		$event = Fixtures::event();

		$_GET = array();
		$this->assertSame( 'list', EventsAdmin\current_view(), 'No event: the list.' );

		$_GET = array( 'event' => 'new' );
		$this->assertSame( 'edit', EventsAdmin\current_view() );

		$_GET = array( 'event' => (string) $event );
		$this->assertSame( 'edit', EventsAdmin\current_view(), 'An event: its form.' );

		foreach ( array( 'participants', 'add', 'edit' ) as $view ) {
			$_GET = array(
				'event' => (string) $event,
				'view'  => $view,
			);
			$this->assertSame( $view, EventsAdmin\current_view() );
		}

		$_GET = array(
			'event' => (string) $event,
			'view'  => 'whatever',
		);
		$this->assertSame( 'edit', EventsAdmin\current_view(), 'An unknown view is the form.' );

		$_GET = array(
			'event' => '99999999',
			'view'  => 'participants',
		);
		$this->assertSame( 'list', EventsAdmin\current_view(), 'An event that does not exist: the list.' );
	}

	/**
	 * The address of the participants.
	 */
	public function test_participants_url() {
		$url = EventsAdmin\participants_url( 7, array( 'evcert_done' => 'issue' ) );

		$this->assertStringContainsString( 'page=evcert-events', $url );
		$this->assertStringContainsString( 'event=7', $url );
		$this->assertStringContainsString( 'view=participants', $url );
		$this->assertStringContainsString( 'evcert_done=issue', $url );
	}

	/**
	 * The list of events is searched, sorted and paged like the posts list.
	 */
	public function test_events_list_search_and_sort() {
		Fixtures::event(
			array(
				'title' => 'Zeta Screens Meetup',
				'start' => '2026-01-10 19:00',
			)
		);
		Fixtures::event(
			array(
				'title' => 'Alfa Screens Meetup',
				'start' => '2026-03-10 19:00',
			)
		);
		Fixtures::event(
			array(
				'title' => 'Other Thing',
				'start' => '2026-02-10 19:00',
			)
		);

		$_REQUEST['s'] = 'Screens';
		$_GET          = array(
			'orderby' => 'title',
			'order'   => 'asc',
		);
		$table         = new EventsAdmin\Events_List_Table();
		$table->prepare_items();
		$this->assertSame( array( 'Alfa Screens Meetup', 'Zeta Screens Meetup' ), wp_list_pluck( $table->items, 'title' ), 'Searched and by name.' );

		$_GET  = array();
		$table = new EventsAdmin\Events_List_Table();
		$table->prepare_items();
		$this->assertSame( array( 'Alfa Screens Meetup', 'Zeta Screens Meetup' ), wp_list_pluck( $table->items, 'title' ), 'By default the newest first.' );
		$this->assertSame( 2, $table->get_pagination_arg( 'total_items' ) );
	}

	/**
	 * The event's form saves the fields it shows, including the ones it did not have.
	 */
	public function test_the_form_fields_are_saved() {
		$id    = \EventsCertificate\Events\save(
			array(
				'title'      => 'Form Fields',
				'start'      => '2026-05-01 10:00',
				'community'  => 'Comunidade Teste',
				'venue'      => 'Centro',
				'city'       => 'Recife, PE',
				'speakers'   => 'Ana Lima',
				'show_hours' => '0',
			)
		);
		$event = \EventsCertificate\Events\get( $id );

		$this->assertSame( 'Comunidade Teste', $event['community'] );
		$this->assertSame( 'Centro, Recife, PE', $event['location'], 'The place is the venue and the city.' );
		$this->assertSame( 'Ana Lima', $event['speakers'] );
		$this->assertSame( '0', $event['show_hours'] );
	}
}
