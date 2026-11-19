<?php
/**
 * Events of the plugin's own and their participants.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Events;
use EventsCertificate\People;
use EventsCertificate\Render;

/**
 * People tests (they make an event and delete it, its people and certificates).
 */
class PeopleTest extends Test_Case {

	/**
	 * Event made by the test.
	 *
	 * @var int
	 */
	private $event = 0;

	/**
	 * A meetup.
	 */
	protected function set_up() {
		parent::set_up();
		$this->event = (int) Events\save(
			array(
				'title'    => 'Meetup Test',
				'start'    => '2026-10-10 19:00',
				'end'      => '2026-10-10 21:30',
				'location' => 'Test City',
			)
		);
	}

	/**
	 * Deletes the event, its people and the certificates they got.
	 */
	protected function tear_down() {
		global $wpdb;

		foreach ( People\query( $this->event )['rows'] as $person ) {
			foreach ( array_filter( $person['roles'] ) as $certificate_id ) {
				$wpdb->delete( Certificates\table(), array( 'id' => (int) $certificate_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$wpdb->delete( People\table(), array( 'event_id' => $this->event ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_delete_post( $this->event, true );
		parent::tear_down();
	}

	/**
	 * An event keeps clean fields; hours come from its times.
	 */
	public function test_event() {
		$event = Events\get( $this->event );
		$this->assertSame( 'Meetup Test', $event['title'] );
		$this->assertSame( 2.5, Events\hours( $event ) );
		$this->assertInstanceOf( \WP_Error::class, Events\save( array( 'title' => '' ) ) );
		$this->assertInstanceOf( \WP_Error::class, Events\save( array( 'title' => 'No date' ) ) );
	}

	/**
	 * Pasted lines: name, e-mail and role in any order after the name; the
	 * same person twice is one.
	 */
	public function test_paste_and_dedupe() {
		$people = People\parse_lines( "Ana Souza, ana@example.com\nBruno Lima\tspeaker\n\n  Carla  ;  carla@example.com ; organizer\nAna Souza" );
		$this->assertCount( 4, $people );
		$this->assertSame( array( 'speaker' ), $people[1]['roles'] );
		$this->assertSame( 'carla@example.com', $people[2]['email'] );

		$done = People\import( $this->event, $people );
		$this->assertSame( 3, $done['added'] );
		$this->assertSame( 1, $done['updated'] );
		$this->assertSame( 3, People\counts( $this->event )['registered'] );
	}

	/**
	 * Meetup's export: RSVP "No" left out, absent ones are no-shows.
	 */
	public function test_meetup_rows() {
		$rows   = array(
			array( 'Name', 'User ID', 'RSVP', 'Attended', 'Email' ),
			array( 'Ana Souza', '1', 'Yes', 'Yes', 'ana@example.com' ),
			array( 'Bruno Lima', '2', 'No', '', '' ),
			array( 'Carla Dias', '3', 'Yes', 'No show', '' ),
			array( 'Davi Rocha', '4', 'Yes', '', '' ),
		);
		$result = People\parse_rows( $rows );
		$this->assertCount( 3, $result['people'] );
		$this->assertSame( 1, $result['rsvp_no'] );
		$this->assertSame( 1, $result['absent'] );
		$this->assertSame( 'attended', $result['people'][0]['status'] );
		$this->assertSame( 'ana@example.com', $result['people'][0]['email'] );
		$this->assertSame( 'no_show', $result['people'][1]['status'] );
		$this->assertArrayNotHasKey( 'status', $result['people'][2] );

		// No header row: the first cell of each row.
		$this->assertSame( 'Eva', People\parse_rows( array( array( '', 'Eva' ) ) )['people'][0]['name'] );
	}

	/**
	 * Only who attended gets a certificate, once; it shows the event's
	 * current name.
	 */
	public function test_issue() {
		People\import( $this->event, People\parse_lines( "Ana Souza\nBruno Lima" ) );
		$rows = People\query( $this->event )['rows'];
		$ana  = $rows[0];
		$this->assertInstanceOf( \WP_Error::class, People\issue( $ana ) );

		People\set_status( array( (int) $ana['id'] ), 'attended', $this->event );
		$cert = People\issue( People\get( (int) $ana['id'] ) );
		$this->assertIsArray( $cert );
		$this->assertSame( 'Ana Souza', $cert['name'] );
		$this->assertSame( 2.5, (float) $cert['hours'] );
		$again = People\issue( People\get( (int) $ana['id'] ) );
		$this->assertSame( $cert['id'], $again['id'] );

		Events\save( array( 'title' => 'Meetup Renamed' ) + Events\get( $this->event ), $this->event );
		$this->assertSame( 'Meetup Renamed', Render\values( $cert )['event'] );
		$this->assertSame( 'Test City', Render\values( $cert )['location'] );

		$result = People\issue_many( wp_list_pluck( $rows, 'id' ), $this->event );
		$this->assertSame( 1, $result['issued'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertCount( 1, People\certificates( $this->event ) );

		// Two certificates in one PDF: two pages.
		People\set_status( array( (int) $rows[1]['id'] ), 'attended', $this->event );
		People\issue_many( wp_list_pluck( $rows, 'id' ), $this->event );
		$items = array();
		foreach ( People\certificates( $this->event ) as $one ) {
			$items[] = array( $one, Certificates\design( $one ) );
		}
		$pdf = Render\pdf_many( $items );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertSame( 2, preg_match_all( '#/Type\s*/Page[^s]#', $pdf ) );
	}
}
