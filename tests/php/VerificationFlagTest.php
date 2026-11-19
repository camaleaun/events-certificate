<?php
/**
 * Verification (a code and a QR code on the certificates) is off by default.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Environment;
use EventsCertificate\Render;
use EventsCertificate\Settings;

/**
 * A custom of some countries (decision 0028): the setting or the filter turns it on, and it still needs a public site.
 */
class VerificationFlagTest extends Test_Case {

	/**
	 * A public site, verification as the setting says (the base class turns it on for the other tests).
	 */
	protected function set_up() {
		parent::set_up();
		remove_filter( 'evcert_verification_enabled', '__return_true' );
		add_filter( 'evcert_is_public_site', '__return_true' );
	}

	/**
	 * Back as the base class has it.
	 */
	protected function tear_down() {
		remove_filter( 'evcert_is_public_site', '__return_true' );
		add_filter( 'evcert_verification_enabled', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Off by default: no QR code, code or hint, even on a public site.
	 */
	public function test_off_by_default() {
		$this->assertFalse( Settings\defaults()['verification'] );
		$this->assertFalse( Environment\verification_enabled() );
		$this->assertTrue( Environment\is_public() );
		$this->assertFalse( Render\verifiable() );

		$html = Render\artwork(
			\EventsCertificate\Designs\base(),
			Render\values(
				array(
					'name' => 'Ana',
					'code' => '2611-ABCD-EFGH-JKLM',
				)
			)
		);
		$this->assertStringNotContainsString( '2611-ABCD-EFGH-JKLM', $html, 'No code.' );
		$this->assertStringNotContainsString( 'verify at', $html, 'No hint.' );
		$off = substr_count( $html, '<img' );
		add_filter( 'evcert_verification_enabled', '__return_true' );
		$on = substr_count(
			Render\artwork(
				\EventsCertificate\Designs\base(),
				Render\values(
					array(
						'name' => 'Ana',
						'code' => '2611-ABCD-EFGH-JKLM',
					)
				)
			),
			'<img'
		);
		remove_filter( 'evcert_verification_enabled', '__return_true' );
		$this->assertSame( $off + 1, $on, 'The QR code is the one image the certificate has only with verification on.' );
	}

	/**
	 * The filter turns it on; then a public site is still needed.
	 */
	public function test_the_filter_turns_it_on_and_the_site_must_be_public() {
		add_filter( 'evcert_verification_enabled', '__return_true' );
		$this->assertTrue( Render\verifiable() );

		$html = Render\artwork(
			\EventsCertificate\Designs\base(),
			Render\values(
				array(
					'name' => 'Ana',
					'code' => '2611-ABCD-EFGH-JKLM',
				)
			)
		);
		$this->assertStringContainsString( '2611-ABCD-EFGH-JKLM', $html );

		remove_filter( 'evcert_is_public_site', '__return_true' );
		add_filter( 'evcert_is_public_site', '__return_false' );
		$this->assertFalse( Render\verifiable(), 'A site nobody else can open has nothing to verify.' );
		remove_filter( 'evcert_is_public_site', '__return_false' );
		add_filter( 'evcert_is_public_site', '__return_true' );
		remove_filter( 'evcert_verification_enabled', '__return_true' );
	}

	/**
	 * The setting, sanitized: a flag, false unless sent.
	 */
	public function test_the_setting() {
		$this->assertTrue( Settings\sanitize( array( 'verification' => '1' ) )['verification'] );
		$this->assertFalse( Settings\sanitize( array() )['verification'] );
	}
}
