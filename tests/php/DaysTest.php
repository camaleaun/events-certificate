<?php
/**
 * Event days and presence per day.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Compat\CampTix\Days as Presence;
use EventsCertificate\Days;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Days tests (they set the days option and restore it).
 */
class DaysTest extends TestCase {

	/**
	 * Days option before the test.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Attendee made by a test.
	 *
	 * @var int
	 */
	private $attendee = 0;

	/**
	 * Two days: a tables day and a talks day.
	 */
	protected function set_up() {
		parent::set_up();
		$this->saved = get_option( Days\OPTION );
		Days\save(
			array(
				array(
					'date'   => '2026-10-30',
					'mode'   => 'table',
					'names'  => array( 'pt_BR' => 'Dia da Colaboração' ),
					'tables' => array( 'pt_BR' => "Core\nPolyglots\n" ),
				),
				array(
					'date' => '2026-10-31',
					'mode' => 'sessions',
				),
				array( 'date' => 'not a date' ),
			)
		);
	}

	/**
	 * Restores the option and deletes the attendee.
	 */
	protected function tear_down() {
		if ( false === $this->saved ) {
			delete_option( Days\OPTION );
		} else {
			update_option( Days\OPTION, $this->saved, false );
		}
		if ( $this->attendee ) {
			wp_delete_post( $this->attendee, true );
		}
		parent::tear_down();
	}

	/**
	 * Saved days are clean, sorted, with names and tables per language.
	 */
	public function test_days() {
		$days = Days\all();
		$this->assertCount( 2, $days );
		$this->assertSame( '2026-10-30', $days[0]['id'] );
		$this->assertSame( 'table', $days[0]['mode'] );
		$this->assertSame( 'Dia da Colaboração', Days\name( $days[0], 'pt_BR' ) );
		$this->assertSame( array( 'Core', 'Polyglots' ), Days\tables( $days[0], 'pt_BR' ) );
		$this->assertSame( Days\short_date( $days[1] ), Days\name( $days[1], 'pt_BR' ) );
		$this->assertNull( Days\get( '2026-11-01' ) );
	}

	/**
	 * Presence per day drives CampTix's "attended", and back.
	 */
	public function test_presence_follows_attended() {
		if ( ! post_type_exists( 'tix_attendee' ) ) {
			$this->markTestSkipped( 'CampTix is not active.' );
		}
		$this->attendee = (int) wp_insert_post(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
				'post_title'  => 'Test attendee',
			)
		);
		Presence\set_present( $this->attendee, array( '2026-10-31', 'nope' ) );
		$this->assertSame( array( '2026-10-31' ), Presence\present( $this->attendee ) );
		$this->assertNotEmpty( get_post_meta( $this->attendee, 'tix_attended', true ) );

		Presence\set_present( $this->attendee, array() );
		$this->assertSame( array(), Presence\present( $this->attendee ) );
		$this->assertEmpty( get_post_meta( $this->attendee, 'tix_attended', true ) );

		// CampTix unmarking clears the days.
		Presence\set_present( $this->attendee, array( '2026-10-30' ) );
		delete_post_meta( $this->attendee, 'tix_attended' );
		$this->assertSame( array(), Presence\present( $this->attendee ) );
	}
}
