<?php
/**
 * "Check a certificate": the form block and shortcode, code normalization.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Code;
use EventsCertificate\VerifyPage;

/**
 * Verify page tests.
 */
class VerifyPageTest extends Test_Case {

	/**
	 * Lowercase, no separators, spaces, and O/I/L for 0/1/1 all give the same code.
	 */
	public function test_typed_codes_normalize() {
		$code = '2611-054W-JW68-8J96';
		foreach ( array( '2611054wjw688j96', '2611 054w jw68 8j96', ' 2611-054w-jw68-8j96 ', '26II-O54W-JW68-8J96' ) as $typed ) {
			$this->assertSame( $code, Code\normalize( $typed ), $typed );
		}
		$this->assertSame( '', Code\normalize( 'zzzz' ) );
	}

	/**
	 * The shortcode draws the block's form, with its title and the code field.
	 */
	public function test_shortcode_draws_the_form() {
		if ( ! shortcode_exists( 'evcert_verify' ) ) {
			VerifyPage\register(); // The test bootstrap may load the plugin after init.
		}
		$html = do_shortcode( '[evcert_verify title="Conferir"]' );
		$this->assertStringContainsString( 'evcert-verify-form', $html );
		$this->assertStringContainsString( '>Conferir<', $html );
		$this->assertStringContainsString( 'name="' . VerifyPage\CODE_ARG . '"', $html );
		$this->assertStringContainsString( 'data-evcert-code', $html );
		$this->assertStringNotContainsString( 'evcert-code-help', $html );
		$this->assertStringNotContainsString( '16 letters and numbers', $html, 'The code field has no help text.' );
	}
}
