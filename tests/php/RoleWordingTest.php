<?php
/**
 * What a certificate says, and for how many hours, by the role it is for.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Designs;
use EventsCertificate\Events;
use EventsCertificate\People;
use EventsCertificate\Render;
use EventsCertificate\Verify;

/**
 * A speaker's certificate does not say "took part in" and an organizer's counts the hours of an organizer (docs/decisions/0025).
 */
class RoleWordingTest extends Test_Case {

	/**
	 * The standard texts differ by role; an attendee's are the ones that were always there.
	 */
	public function test_each_role_has_its_standard_texts() {
		$attendee = Render\phrases();
		$this->assertSame( 'Certificate of participation', $attendee['title_text'] );
		$this->assertSame( 'took part in', $attendee['took_part_text'] );
		$this->assertSame( $attendee, Render\phrases( 'attendee' ), 'Attendee is the default.' );
		$this->assertSame( $attendee, Render\phrases( '' ) );

		$expected = array(
			'speaker'   => array( 'Speaker certificate', 'gave a talk at' ),
			'organizer' => array( 'Organizer certificate', 'organized' ),
			'volunteer' => array( 'Volunteer certificate', 'volunteered at' ),
			'sponsor'   => array( 'Sponsor certificate', 'sponsored' ),
		);
		foreach ( $expected as $role => $texts ) {
			$phrases = Render\phrases( $role );
			$this->assertSame( $texts[0], $phrases['title_text'], $role );
			$this->assertSame( $texts[1], $phrases['took_part_text'], $role );
			$this->assertSame( $attendee['intro_text'], $phrases['intro_text'], 'The same introduction: This is to certify that.' );
			$this->assertSame( $attendee['date_label'], $phrases['date_label'], 'The labels are the same for every role.' );
		}
		$this->assertSame( $attendee, Render\phrases( 'wizard' ), 'A role that does not exist reads as an attendee.' );
	}

	/**
	 * A site changes the texts of a role with a filter.
	 */
	public function test_the_texts_are_filterable_by_role() {
		$filter = static function ( $phrases, $role ) {
			if ( 'speaker' === $role ) {
				$phrases['took_part_text'] = 'presented at';
			}

			return $phrases;
		};
		add_filter( 'evcert_phrases', $filter, 10, 2 );

		$this->assertSame( 'presented at', Render\phrases( 'speaker' )['took_part_text'] );
		$this->assertSame( 'took part in', Render\phrases( 'attendee' )['took_part_text'] );
		remove_filter( 'evcert_phrases', $filter, 10 );
	}

	/**
	 * The values of a certificate carry the texts of its role.
	 */
	public function test_a_certificate_says_what_its_role_says() {
		$speaker = Render\values(
			array(
				'name' => 'Ana',
				'role' => 'speaker',
				'code' => '2611-ABCD-EFGH-JKLM',
			)
		);
		$none    = Render\values( array( 'name' => 'Ana' ) );

		$this->assertSame( 'gave a talk at', $speaker['took_part_text'] );
		$this->assertSame( 'Speaker', $speaker['role'] );
		$this->assertSame( 'took part in', $none['took_part_text'], 'No role: an attendee.' );
	}

	/**
	 * The declaration page (the participant's own account of days and talks) is for attendees only.
	 */
	public function test_only_an_attendee_has_the_declaration_page() {
		$page2 = wp_json_encode( array( 'page2' => true ) );

		$this->assertTrue(
			Render\has_declaration(
				array(
					'role'      => 'attendee',
					'data_json' => $page2,
				)
			)
		);
		$this->assertTrue( Render\has_declaration( array( 'data_json' => $page2 ) ), 'No role: an attendee, as before.' );
		$this->assertFalse(
			Render\has_declaration(
				array(
					'role'      => 'speaker',
					'data_json' => $page2,
				)
			)
		);
		$this->assertFalse( Render\has_declaration( array( 'role' => 'attendee' ) ) );
	}

	/**
	 * A role has its own hours on the event; empty falls back to the event's.
	 */
	public function test_hours_by_role() {
		$id    = Fixtures::event(
			array(
				'hours'         => '4',
				'hours_by_role' => array(
					'organizer' => '40',
					'volunteer' => '8,5',
					'speaker'   => 'abc',
					'wizard'    => '9',
				),
			)
		);
		$event = Events\get( $id );

		$this->assertSame(
			array(
				'organizer' => '40',
				'volunteer' => '8.5',
			),
			$event['hours_by_role'],
			'Valid roles and numbers only.'
		);
		$this->assertSame( 4.0, Events\hours( $event ), 'The event\'s own.' );
		$this->assertSame( 4.0, Events\hours( $event, 'attendee' ) );
		$this->assertSame( 4.0, Events\hours( $event, 'speaker' ), 'Not set for speakers: the event\'s.' );
		$this->assertSame( 40.0, Events\hours( $event, 'organizer' ) );
		$this->assertSame( 8.5, Events\hours( $event, 'volunteer' ) );

		// An event that shows no hours shows none for any role.
		$no_hours = Events\get(
			Fixtures::event(
				array(
					'show_hours'    => '0',
					'hours_by_role' => array( 'organizer' => '40' ),
				)
			)
		);
		$this->assertNull( Events\hours( $no_hours, 'organizer' ) );
	}

	/**
	 * Issuing freezes the hours of the role.
	 */
	public function test_a_certificate_has_the_hours_of_its_role() {
		$event  = Fixtures::event(
			array(
				'hours'         => '4',
				'hours_by_role' => array( 'organizer' => '40' ),
			)
		);
		$person = Fixtures::attendee_of_event( $event, 'Ana Lima', 'ana@example.test' );
		People\add_roles( (int) $person['id'], array( 'organizer' ) );
		People\issue_many( array( (int) $person['id'] ), $event );
		$person = People\get( (int) $person['id'] );

		$this->assertSame( 4.0, (float) People\certificate( $person, 'attendee' )['hours'] );
		$this->assertSame( 40.0, (float) People\certificate( $person, 'organizer' )['hours'] );
	}

	/**
	 * A role is written in the language it was issued in: a speaker in Portuguese does not "participou".
	 */
	public function test_the_texts_of_a_role_are_translated() {
		if ( ! file_exists( WP_LANG_DIR . '/plugins/events-certificates-pt_BR.mo' ) || ! in_array( 'pt_BR', Verify\locales(), true ) ) {
			$this->markTestSkipped( 'The pt_BR translation of the plugin is not installed.' );
		}
		$pt = Render\with_locale(
			'pt_BR',
			static function () {
				return array(
					'speaker'   => Render\phrases( 'speaker' ),
					'organizer' => Render\phrases( 'organizer' ),
					'volunteer' => Render\phrases( 'volunteer' ),
					'sponsor'   => Render\phrases( 'sponsor' ),
				);
			}
		);

		$this->assertSame( 'Certificado de palestrante', $pt['speaker']['title_text'] );
		$this->assertSame( 'proferiu palestra em', $pt['speaker']['took_part_text'] );
		$this->assertSame( 'organizou', $pt['organizer']['took_part_text'] );
		$this->assertSame( 'atuou como voluntário em', $pt['volunteer']['took_part_text'] );
		$this->assertSame( 'patrocinou', $pt['sponsor']['took_part_text'] );
	}

	/**
	 * An event with no speakers shows no "SPEAKER" label; with them it does, in the singular or the plural.
	 */
	public function test_no_speakers_no_label() {
		$none = Render\values( array( 'name' => 'Ana' ) );
		$this->assertSame( '', $none['speakers_label'] );

		$one  = Events\get( Fixtures::event( array( 'speakers' => 'Ana Lima' ) ) );
		$two  = Events\get( Fixtures::event( array( 'speakers' => 'Ana Lima, Bruno Souza' ) ) );
		$cert = array(
			'name'    => 'X',
			'context' => 'event',
		);
		$this->assertSame( 'SPEAKER', Render\values( $cert + array( 'event_id' => $one['id'] ) )['speakers_label'] );
		$this->assertSame( 'SPEAKERS', Render\values( $cert + array( 'event_id' => $two['id'] ) )['speakers_label'] );

		$blank = Events\get( Fixtures::event( array( 'speakers' => '' ) ) );
		$this->assertSame( '', Render\values( $cert + array( 'event_id' => $blank['id'] ) )['speakers_label'] );
	}

	/**
	 * A label with nothing under it is left out (no time on a WordCamp that runs for days); with a value it stays.
	 */
	public function test_a_label_without_a_value_is_left_out() {
		$design   = \EventsCertificate\Designs\base();
		$elements = (array) $design['elements'];
		$values   = Render\values( array( 'name' => 'Ana' ) );

		$this->assertContains( 'time-label', Render\orphan_labels( $elements, $values ), 'No event, no time.' );
		$this->assertNotContains( 'date-label', Render\orphan_labels( $elements, $values ) );

		$values['event_time'] = 'from 7pm to 9pm';
		$this->assertNotContains( 'time-label', Render\orphan_labels( $elements, $values ) );
	}
}
