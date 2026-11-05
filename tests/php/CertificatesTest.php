<?php
/**
 * Issuing and correcting certificates.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Settings;

/**
 * Certificates are frozen records; a correction issues a new one.
 */
class CertificatesTest extends Test_Case {

	/**
	 * A certificate needs a name.
	 */
	public function test_issue_requires_a_name() {
		$this->assertInstanceOf( \WP_Error::class, Certificates\issue( array( 'name' => '   ' ) ) );
	}

	/**
	 * Issuing stores the frozen data with a code and the design version.
	 */
	public function test_issue_freezes_data_with_a_code() {
		$cert = Certificates\issue(
			array(
				'name'        => "  Maria  da\tConceição ",
				'event_title' => 'WordCamp Test',
				'source'      => 'camptix',
				'source_ref'  => '123',
				'not_a_field' => 'ignored',
			)
		);

		$this->assertIsArray( $cert );
		$this->assertSame( 'Maria da Conceição', $cert['name'] );
		$this->assertSame( 'issued', $cert['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $cert['code'] );
		$this->assertSame( 64, strlen( $cert['design_hash'] ) );
		$this->assertSame( Certificates\fingerprint( $cert ), $cert['fingerprint'] );
		$this->assertSame( $cert['id'], Certificates\get_by_code( strtolower( $cert['code'] ) )['id'] );
		$this->assertSame( $cert['id'], Certificates\find_by_source( 'camptix', 123 )['id'] );
	}

	/**
	 * A correction issues a new certificate and marks the old one replaced.
	 */
	public function test_reissue_replaces_the_old_certificate() {
		$old = Certificates\issue(
			array(
				'name'       => 'Maria Teste',
				'source'     => 'camptix',
				'source_ref' => '7',
			)
		);
		$new = Certificates\reissue( $old, array( 'name' => 'Maria Testé' ) );

		$this->assertNotSame( $old['code'], $new['code'] );
		$this->assertSame( 'Maria Testé', $new['name'] );
		$this->assertSame( 'camptix', $new['source'] );

		$old = Certificates\get( (int) $old['id'] );
		$this->assertSame( 'replaced', $old['status'] );
		$this->assertSame( (string) $new['id'], (string) $old['replaced_by'] );
		$this->assertSame( $new['id'], Certificates\find_by_source( 'camptix', 7 )['id'] );
	}

	/**
	 * The self-issue setting keeps only known values.
	 */
	public function test_self_issue_setting() {
		$this->assertSame( 'attended', Settings\defaults()['self_issue'] );
		$this->assertSame( 'confirmed', Settings\sanitize( array( 'self_issue' => 'confirmed' ) )['self_issue'] );
		$this->assertSame( 'off', Settings\sanitize( array( 'self_issue' => 'off' ) )['self_issue'] );
		$this->assertSame( 'attended', Settings\sanitize( array( 'self_issue' => 'everyone' ) )['self_issue'] );
	}
}
