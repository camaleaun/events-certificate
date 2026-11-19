<?php
/**
 * Certificate codes.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Code;

/**
 * Code tests.
 */
class CodeTest extends Test_Case {

	public function test_generate_starts_with_the_issue_date_and_uses_crockford() {
		$timestamp = strtotime( '2026-11-04 12:00:00 UTC' );
		$code      = Code\generate( $timestamp );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}[0-9A-HJKMNP-TV-Z]{2}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code );
		$this->assertSame( wp_date( 'ym', $timestamp ), substr( $code, 0, 4 ) );
		$this->assertSame( wp_date( 'd', $timestamp ), substr( $code, 5, 2 ) );
	}

	public function test_generate_is_random() {
		$codes = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$codes[] = Code\generate();
		}

		$this->assertCount( 200, array_unique( $codes ) );
	}

	public function test_normalize_forgives_case_spaces_hyphens_and_lookalikes() {
		$this->assertSame( '2611-04K7-M29Q-XAP3', Code\normalize( ' 2611 04k7 m29q xap3 ' ) );
		$this->assertSame( '2611-04K7-M29Q-XAP3', Code\normalize( '261104K7M29QXAP3' ) );
		$this->assertSame( '2611-0410-1100-0000', Code\normalize( '2611-04IO-LIOO-OOOO' ) );
	}

	public function test_normalize_rejects_what_cannot_be_a_code() {
		$this->assertSame( '', Code\normalize( '' ) );
		$this->assertSame( '', Code\normalize( '2611-04K7-M29Q' ) );
		$this->assertSame( '', Code\normalize( 'AB11-04K7-M29Q-XAP3' ) );
		$this->assertSame( '', Code\normalize( '2611-04K7-M29Q-XAPU' ) );
		$this->assertSame( '', Code\normalize( '2611-04K7-M29Q-XAP3-0' ) );
	}
}
