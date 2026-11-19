<?php
/**
 * Snapshots of the markup the PDF is made from.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Designs;
use EventsCertificate\Events;
use EventsCertificate\Render;

/**
 * The HTML dompdf receives, for a few certificates, kept in tests/php/snapshots/.
 * It is the safety net of changes to the text, the dates and the code that makes
 * the pages: a change that is meant shows as a diff to review and accept
 * (EVCERT_UPDATE_SNAPSHOTS=1 composer test), one that is not fails the test.
 */
class SnapshotTest extends Test_Case {

	/**
	 * A site that prints the code and the QR code, whatever the one running the tests.
	 */
	protected function set_up() {
		parent::set_up();
		add_filter( 'evcert_is_public_site', '__return_true' );
		add_filter( 'pre_option_blogname', array( $this, 'site_name' ) );
		add_filter( 'pre_option_date_format', array( $this, 'date_format' ) );
		add_filter( 'pre_option_time_format', array( $this, 'time_format' ) );
		add_action( 'pre_get_posts', array( $this, 'no_organizers' ) );
	}

	/**
	 * Undoes set_up().
	 */
	protected function tear_down() {
		remove_filter( 'evcert_is_public_site', '__return_true' );
		remove_filter( 'pre_option_blogname', array( $this, 'site_name' ) );
		remove_filter( 'pre_option_date_format', array( $this, 'date_format' ) );
		remove_filter( 'pre_option_time_format', array( $this, 'time_format' ) );
		remove_action( 'pre_get_posts', array( $this, 'no_organizers' ) );
		parent::tear_down();
	}

	/**
	 * The site's name, the same wherever the tests run.
	 *
	 * @return string
	 */
	public function site_name() {
		return 'Snapshot Site';
	}

	/**
	 * The site's date format, the same wherever the tests run.
	 *
	 * @return string
	 */
	public function date_format() {
		return 'F j, Y';
	}

	/**
	 * The site's time format, the same wherever the tests run.
	 *
	 * @return string
	 */
	public function time_format() {
		return 'g:i a';
	}

	/**
	 * No organizer signs: the signer is the site's, and a test site has none, so the same markup comes out of every site.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function no_organizers( $query ) {
		if ( \EventsCertificate\Signer\ORGANIZER === $query->get( 'post_type' ) ) {
			$query->set( 'post__in', array( 0 ) );
		}
	}

	/**
	 * A certificate of an event of the plugin, with its talk and speakers.
	 */
	public function test_event_certificate() {
		$event = (int) Events\save(
			array(
				'title'     => 'Meetup Snapshot',
				'start'     => '2026-10-31 19:00',
				'end'       => '2026-10-31 21:30',
				'location'  => 'Belo Horizonte, Minas Gerais',
				'community' => 'Comunidade Snapshot',
				'speakers'  => 'Ana Lima, Bruno Souza and Carla Dias',
				'link'      => 'https://example.org/meetup-snapshot',
			)
		);

		$this->assert_snapshot(
			'event',
			$this->cert(
				array(
					'context'  => 'event',
					'event_id' => $event,
					'name'     => 'Maria da Conceição',
					'role'     => 'attendee',
				)
			)
		);
	}

	/**
	 * A speaker's certificate: its own title and "gave a talk at", and the hours of the role.
	 */
	public function test_speaker_certificate() {
		$event = (int) Events\save(
			array(
				'title'         => 'Meetup Snapshot',
				'start'         => '2026-10-31 19:00',
				'end'           => '2026-10-31 21:30',
				'location'      => 'Belo Horizonte, Minas Gerais',
				'hours_by_role' => array( 'speaker' => '1' ),
			)
		);

		$this->assert_snapshot(
			'speaker',
			$this->cert(
				array(
					'context'  => 'event',
					'event_id' => $event,
					'name'     => 'Ana Lima',
					'role'     => 'speaker',
					'hours'    => '1',
				)
			)
		);
	}

	/**
	 * What the CampTix page issues: no event of the plugin, the title and the dates on the certificate.
	 */
	public function test_event_less_certificate() {
		$this->assert_snapshot(
			'camptix',
			$this->cert(
				array(
					'name'        => 'João Pereira',
					'event_title' => 'WordCamp Snapshot 2026',
					'event_start' => '2026-11-14 12:00:00',
					'event_end'   => '2026-11-15 21:00:00',
					'event_tz'    => 'America/Sao_Paulo',
					'hours'       => '16.5',
					'role'        => 'attendee',
					'source'      => 'camptix',
					'source_ref'  => '99',
				)
			)
		);
	}

	/**
	 * With the declaration page: the document and the days the person declared.
	 */
	public function test_declaration_certificate() {
		$this->assert_snapshot(
			'declaration',
			$this->cert(
				array(
					'name'        => 'Ana Lima',
					'event_title' => 'WordCamp Snapshot 2026',
					'event_start' => '2026-11-14 12:00:00',
					'event_end'   => '2026-11-15 21:00:00',
					'hours'       => '1',
					'doc_label'   => 'CPF',
					'doc_masked'  => '•••.•••.247-••',
					'data_json'   => wp_json_encode(
						array(
							'page2'   => true,
							'days'    => array( '2026-11-14', '2026-11-15' ),
							'minutes' => 90,
						)
					),
				)
			),
			array( 'document' => '123.456.247-89' )
		);
	}

	/**
	 * A certificate that does not depend on the site: a fixed code, issue date and design.
	 *
	 * @param array<string, mixed> $data Fields.
	 * @return array<string, mixed>
	 */
	private function cert( array $data ) {
		return $data + array(
			'code'      => '2611-ABCD-EFGH-JKLM',
			'status'    => 'issued',
			'issued_at' => '2026-10-31 15:00:00',
			'locale'    => 'en_US',
		);
	}

	/**
	 * Compares the pages of a certificate with the stored markup, or stores it.
	 *
	 * @param string               $name  File name.
	 * @param array<string, mixed> $cert  Certificate.
	 * @param array<string, mixed> $extra Extras of pdf().
	 */
	private function assert_snapshot( $name, array $cert, array $extra = array() ) {
		$html = Render\pdf_body(
			array( array( $cert, Designs\base(), $extra ) ),
			static function ( $text, $family, $weight, $size ) {
				return strlen( (string) $text ) * $size * 0.5;
			}
		);
		$html = $this->normalize( $html );
		$file = __DIR__ . '/snapshots/' . $name . '.html';

		if ( getenv( 'EVCERT_UPDATE_SNAPSHOTS' ) || ! file_exists( $file ) ) {
			wp_mkdir_p( dirname( $file ) );
			file_put_contents( $file, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		}

		$this->assertSame( file_get_contents( $file ), $html, "The markup of the '$name' certificate changed. Review it and, if it is meant, run EVCERT_UPDATE_SNAPSHOTS=1 composer test." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a test fixture.
	}

	/**
	 * Takes out what differs from one site to another: the address and the images (the QR code and the drawings).
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function normalize( $html ) {
		$home = untrailingslashit( home_url() );
		$html = str_replace( array( $home, (string) preg_replace( '#^https?://#', '', $home ) ), '{home}', $html );
		$html = preg_replace( '#data:image/[a-z+]+;base64,[A-Za-z0-9+/=]+#', 'data:IMAGE', $html );
		$html = preg_replace( '#(<(?:div|p|h1|h2|table|tr|td|ul|li)\b)#', "\n$1", $html );

		return trim( $html ) . "\n";
	}
}
