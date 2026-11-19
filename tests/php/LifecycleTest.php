<?php
/**
 * A certificate's life: issued, corrected, revoked; what each state allows.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Compat\CampTix;
use EventsCertificate\Compat\GatherPress;
use EventsCertificate\Generator;
use EventsCertificate\EventsAdmin;
use EventsCertificate\People;
use EventsCertificate\Verify;

/**
 * Run in the four integration scenarios (composer test:all).
 */
class LifecycleTest extends Test_Case {

	/**
	 * The event of the plugin the tests use.
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
	 * How many certificates the participant has had, in any state.
	 *
	 * @param array<string, mixed> $person Participant.
	 * @return int
	 */
	private function certificates_of( array $person ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s AND source_ref = %s', Certificates\table(), People\SOURCE, (string) $person['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	/**
	 * A correction moves the participant to the new certificate: it is the one downloaded, and issuing again makes no third.
	 */
	public function test_a_correction_keeps_the_participant_on_the_current_certificate() {
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Antiga' );
		$old    = People\issue( $person );
		$new    = Certificates\reissue( $old, array( 'name' => 'Maria Nova' ) );

		$person = People\get( (int) $person['id'] );
		$this->assertSame( $new['id'], People\certificate( $person )['id'] );
		$this->assertSame( array( $new['code'] ), wp_list_pluck( People\certificates( $this->event ), 'code' ) );

		$again = People\issue_many( array( (int) $person['id'] ), $this->event );
		$this->assertSame( 1, $again['issued'] );
		$this->assertSame( $new['id'], $again['certificates'][0]['id'] );
		$this->assertSame( 2, $this->certificates_of( $person ), 'The old and the corrected one, no third.' );
	}

	/**
	 * Revoked is final for issuing: who was revoked is left out and counted, not given a new certificate.
	 */
	public function test_a_revoked_participant_is_not_certified_again() {
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Revogada' );
		$cert   = People\issue( $person );
		Certificates\revoke( (int) $cert['id'], 'Issued by mistake' );

		$result = People\issue_many( array( (int) $person['id'] ), $this->event );
		$this->assertSame( 0, $result['issued'] );
		$this->assertSame( 1, $result['revoked'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 1, $this->certificates_of( $person ) );
		$this->assertSame( 'evcert_revoked', People\issue( People\get( (int) $person['id'] ) )->get_error_code() );
		$this->assertSame( array(), People\certificates( $this->event ) );
	}

	/**
	 * Only a valid certificate is corrected: correcting a replaced or revoked one would leave two valid.
	 */
	public function test_only_a_valid_certificate_can_be_corrected() {
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Troca' );
		$first  = People\issue( $person );
		$second = Certificates\reissue( $first, array( 'name' => 'Maria Troca Dois' ) );

		$again = Certificates\reissue( Certificates\get( (int) $first['id'] ), array( 'name' => 'Outra' ) );
		$this->assertSame( 'evcert_not_current', $again->get_error_code() );

		Certificates\revoke( (int) $second['id'] );
		$revoked = Certificates\reissue( Certificates\get( (int) $second['id'] ), array( 'name' => 'Outra' ) );
		$this->assertSame( 'evcert_not_current', $revoked->get_error_code() );

		$this->assertSame( 2, $this->certificates_of( $person ), 'No certificate was made by the refused corrections.' );
	}

	/**
	 * The PDF is for valid certificates: a replaced or revoked code answers with its page, which says what happened.
	 */
	public function test_the_pdf_of_a_revoked_or_replaced_certificate_is_not_served() {
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Pdf' );
		$first  = People\issue( $person );
		$second = Certificates\reissue( $first, array( 'name' => 'Maria Pdf Dois' ) );

		$this->assertTrue( Verify\serves_pdf( $second ) );
		$this->assertFalse( Verify\serves_pdf( Certificates\get( (int) $first['id'] ) ) );

		Certificates\revoke( (int) $second['id'] );
		$this->assertFalse( Verify\serves_pdf( Certificates\get( (int) $second['id'] ) ) );

		// The request itself: the redirect to the page, not a PDF.
		global $wp_query;
		$wp_query->set( Verify\QUERY_VAR, 1 );
		$wp_query->set( Verify\CODE_VAR, $second['code'] );
		$wp_query->set( Verify\FORMAT_VAR, 'pdf' );
		$caught = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test.
			}
		);
		try {
			Verify\template_redirect();
		} catch ( \RuntimeException $e ) {
			$caught = $e->getMessage();
		}
		remove_all_filters( 'wp_redirect' );

		$this->assertNotNull( $caught, 'The request was redirected.' );
		$this->assertSame( Verify\page_url( Certificates\get( (int) $second['id'] ) ), $caught, 'To the page, not the PDF.' );
		$this->assertStringNotContainsString( '/pdf/', $caught );
	}

	/**
	 * What anyone who opens the code sees: the page with the name and the code and the status, and a PDF.
	 */
	public function test_an_issued_certificate_has_its_page_and_its_pdf() {
		add_filter( 'evcert_is_public_site', '__return_true' );
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Pública' );
		$cert   = People\issue( $person );

		ob_start();
		Verify\render_page( $cert, $cert['code'], 'en_US', false );
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'Maria Pública', $page );
		$this->assertStringContainsString( $cert['code'], $page );
		$this->assertStringContainsString( 'Certificate on record', $page );
		$this->assertStringContainsString( '<dt>Role</dt><dd>Attendee</dd>', $page, 'The role the certificate is for.' );
		$this->assertStringContainsString( '/pdf/', $page, 'The PDF button.' );
		$this->assertStringStartsWith( '%PDF', \EventsCertificate\Render\pdf( $cert, Certificates\design( $cert ) ) );

		Certificates\revoke( (int) $cert['id'], 'Wrong person' );
		ob_start();
		Verify\render_page( Certificates\get( (int) $cert['id'] ), $cert['code'], 'en_US', false );
		$revoked = (string) ob_get_clean();
		remove_filter( 'evcert_is_public_site', '__return_true' );

		$this->assertStringContainsString( 'Revoked', $revoked );
		$this->assertStringContainsString( 'Wrong person', $revoked );
		$this->assertStringNotContainsString( '/pdf/', $revoked, 'No PDF button for a revoked certificate.' );
	}

	/**
	 * The generator makes the event, adds the people as attended unless said otherwise, and certifies who attended.
	 */
	public function test_the_generator_makes_the_event_and_the_certificates() {
		$people   = People\parse_lines( "Ana Souza, ana@example.com\nBruno Lima" );
		$people[] = array(
			'name'   => 'Carla Faltou',
			'status' => 'no_show',
		);
		$event_id = Generator\run(
			array(
				'title' => 'Meetup do Gerador',
				'start' => '2026-10-10 19:00',
				'end'   => '2026-10-10 21:00',
				'city'  => 'Curitiba',
			),
			$people
		);

		$this->assertIsInt( $event_id );
		$certs = People\certificates( $event_id );
		$this->assertSame( array( 'Ana Souza', 'Bruno Lima' ), wp_list_pluck( $certs, 'name' ) );
		$values = \EventsCertificate\Render\values( $certs[0] );
		$this->assertSame( 'Meetup do Gerador', $values['event'] );
		$this->assertStringContainsString( 'Curitiba', $values['location'] );
		$this->assertSame( 2, People\counts( $event_id )['certified'] );
		$this->assertInstanceOf( \WP_Error::class, Generator\run( array( 'title' => 'Sem gente' ), array() ) );
		$this->assertInstanceOf( \WP_Error::class, Generator\run( array( 'title' => '' ), $people ) );
	}

	/**
	 * Revoking a certificate that is not valid says so instead of answering as if it had worked.
	 */
	public function test_revoking_twice_is_an_error() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
		$person = Fixtures::attendee_of_event( $this->event, 'Maria Duas Vezes' );
		$cert   = People\issue( $person );
		wp_set_current_user( 1 );
		$ability = wp_get_ability( 'events-certificates/revoke-certificate' );

		$first  = $ability->execute( array( 'code' => $cert['code'] ) );
		$second = $ability->execute( array( 'code' => $cert['code'] ) );
		wp_set_current_user( 0 );

		$this->assertIsArray( $first );
		$this->assertSame( 'revoked', $first['status'] );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'evcert_not_revocable', $second->get_error_code() );
	}

	/**
	 * The notice after issuing says how many were left out, and why.
	 */
	public function test_the_notice_after_issuing_counts_what_was_left_out() {
		$_GET = array(
			'evcert_done'    => 'issue',
			'evcert_n'       => '1',
			'evcert_skipped' => '2',
			'evcert_revoked' => '3',
		);
		ob_start();
		EventsAdmin\notice();
		$html = (string) ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( '1 certificate issued.', $html );
		$this->assertStringContainsString( '2 left out: only who attended gets one.', $html );
		$this->assertStringContainsString( '3 left out: their certificates were revoked.', $html );
	}

	/**
	 * What an attendee does on the ticket page: issues, corrects, and after a revocation cannot get another.
	 */
	public function test_the_ticket_page_issues_corrects_and_respects_a_revocation() {
		$this->requires_integration( 'camptix', 'both' );
		$attendee = Fixtures::camptix_attendee();

		$issued = CampTix\issue_for_attendee( $attendee, 'Maria Camptix' );
		$this->assertSame( 'issued', $issued['done'] );
		$this->assertSame( 'issued', $issued['certificate']['status'] );
		$this->assertSame( $issued['certificate']['id'], Certificates\find_by_source( CampTix\SOURCE, $attendee->ID, 'attendee' )['id'] );

		$this->assertNull( CampTix\issue_for_attendee( $attendee, 'Maria Camptix' ), 'Nothing changed.' );

		$corrected = CampTix\issue_for_attendee( $attendee, 'Maria Corrigida' );
		$this->assertSame( 'corrected', $corrected['done'] );
		$this->assertSame( 'Maria Corrigida', $corrected['certificate']['name'] );
		$this->assertSame( 'replaced', Certificates\get( (int) $issued['certificate']['id'] )['status'] );

		Certificates\revoke( (int) $corrected['certificate']['id'], 'Wrong person' );
		$after = CampTix\issue_for_attendee( $attendee, 'Maria Outra Vez' );
		$this->assertInstanceOf( \WP_Error::class, $after );
		$this->assertSame( 'evcert_revoked', $after->get_error_code() );
		$this->assertStringContainsString( 'revoked', CampTix\unavailable_reason( $attendee ) );
		$this->assertSame( $corrected['certificate']['id'], Certificates\latest_by_source( CampTix\SOURCE, $attendee->ID, 'attendee' )['id'] );
	}

	/**
	 * The attendee's list of tickets says what is missing for the certificate, or links it once it is there.
	 */
	public function test_the_tickets_page_says_what_is_missing_for_the_certificate() {
		$this->requires_integration( 'camptix', 'both' );
		$attendee = Fixtures::camptix_attendee();

		// Present (the fixture marks it, and self-issue wants a present attendee): the link.
		$cell = CampTix\edit_info_cell( '<td>Edit</td>', $attendee );
		$this->assertStringContainsString( 'tix_action=certificate', $cell );
		$this->assertStringNotContainsString( 'evcert-certificate-hint', $cell );

		// Not marked present: the reason, no link.
		delete_post_meta( $attendee->ID, 'tix_attended' );
		$cell = CampTix\edit_info_cell( '<td>Edit</td>', $attendee );
		$this->assertStringNotContainsString( 'tix_action=certificate', $cell );
		$this->assertStringContainsString( 'marked as present', $cell );

		// Revoked: it says so.
		update_post_meta( $attendee->ID, 'tix_attended', 1 );
		$issued = CampTix\issue_for_attendee( $attendee, 'Maria Camptix' )['certificate'];
		Certificates\revoke( (int) $issued['id'] );
		$this->assertStringContainsString( 'revoked', CampTix\edit_info_cell( '', $attendee ) );
	}

	/**
	 * A certificate of a WordCamp says the days of the WordCamp, not the day it was issued.
	 */
	public function test_the_ticket_certificate_has_the_dates_of_the_wordcamp() {
		$this->requires_integration( 'camptix', 'both' );
		$wordcamp = (object) array(
			'meta' => array(
				'Start Date (YYYY-mm-dd)' => array( (string) strtotime( '2026-11-14 00:00:00 UTC' ) ),
				'End Date (YYYY-mm-dd)'   => array( (string) strtotime( '2026-11-15 00:00:00 UTC' ) ),
			),
		);

		$this->assertSame(
			array(
				'event_start' => '2026-11-14 12:00:00',
				'event_end'   => '2026-11-15 12:00:00',
			),
			CampTix\dates_of( $wordcamp )
		);
		$this->assertSame( array(), CampTix\dates_of( false ) );
		$this->assertSame(
			array(
				'event_start' => '2026-11-14 12:00:00',
				'event_end'   => '2026-11-14 12:00:00',
			),
			CampTix\dates_of( (object) array( 'meta' => array( 'Start Date (YYYY-mm-dd)' => array( (string) strtotime( '2026-11-14 00:00:00 UTC' ) ) ) ) )
		);
	}

	/**
	 * With both plugins, a person the ticket page certified is not certified again by an imported event, and the other way round.
	 */
	public function test_one_certificate_per_person_and_event_with_both_integrations() {
		$this->requires_integration( 'both' );
		$title = \EventsCertificate\Compat\CampTix\event_name();
		$event = Fixtures::event( array( 'title' => $title ) );

		// The ticket page first.
		$attendee = Fixtures::camptix_attendee( array( 'email' => 'dupla@example.com' ) );
		$ticket   = CampTix\issue_for_attendee( $attendee, 'Dupla Pessoa' )['certificate'];
		$person   = Fixtures::attendee_of_event( $event, 'Dupla Pessoa', 'dupla@example.com' );
		$result   = People\issue_many( array( (int) $person['id'] ), $event );
		$this->assertSame( $ticket['id'], $result['certificates'][0]['id'], 'The certificate the ticket page issued.' );
		$this->assertSame( $ticket['id'], People\certificate( People\get( (int) $person['id'] ) )['id'] );
		$this->assertSame( 0, $this->certificates_of( $person ) );

		// The event first.
		$other  = Fixtures::attendee_of_event( $event, 'Outra Dupla', 'outra@example.com' );
		$first  = People\issue( $other );
		$second = Fixtures::camptix_attendee( array( 'email' => 'OUTRA@example.com' ) );
		$this->assertStringContainsString( $first['code'], CampTix\unavailable_reason( $second ) );
		$this->assertSame( 'evcert_exists', CampTix\issue_for_attendee( $second, 'Outra Dupla' )->get_error_code() );
	}

	/**
	 * A GatherPress event comes in with who is attending, the check-ins are who attended, they get certificates, and syncing again changes nothing.
	 */
	public function test_a_gatherpress_event_is_imported_certified_and_synced() {
		$this->requires_integration( 'gatherpress', 'both' );
		$post = Fixtures::gatherpress_event( 'Meetup Importado' );
		Fixtures::gatherpress_rsvp( $post, 'Gui Presente', 'gui@example.com', 'attending', true );
		Fixtures::gatherpress_rsvp( $post, 'Bia Ausente', 'bia@example.com', 'attending' );
		Fixtures::gatherpress_rsvp( $post, 'Caio Nao', 'caio@example.com', 'not_attending' );

		$imported = GatherPress\import( $post );
		$this->assertSame( 2, $imported['added'] );
		$event = $imported['event'];
		$this->assertSame( $event, GatherPress\linked( $post ) );
		$this->assertSame( 'Meetup Importado', \EventsCertificate\Events\get( $event )['title'] );
		$this->assertSame( 1, People\counts( $event )['attended'] );
		$this->assertSame( 1, People\counts( $event )['registered'] );

		$everyone = wp_list_pluck( People\query( $event )['rows'], 'id' );
		$result   = People\issue_many( $everyone, $event );
		$this->assertSame( 1, $result['issued'], 'Only the check-in.' );
		$this->assertSame( 1, $result['skipped'] );
		$cert = $result['certificates'][0];
		$this->assertSame( 'Gui Presente', $cert['name'] );
		$this->assertSame( 'Meetup Importado', \EventsCertificate\Render\values( $cert )['event'] );
		$this->assertSame( 'is-valid', Verify\status( $cert )[0] );

		$synced = GatherPress\import( $post );
		$this->assertSame( 0, $synced['added'] );
		$this->assertSame( $event, $synced['event'] );
		$this->assertSame( 1, People\issue_many( $everyone, $event )['issued'], 'The same certificate, not a second.' );
		$this->assertCount( 1, People\certificates( $event ) );

		$this->assertInstanceOf( \WP_Error::class, GatherPress\import( 999999 ) );
	}
}
