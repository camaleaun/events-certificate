<?php
/**
 * Preview in a new tab: placeholders, preview data.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Preview;
use EventsCertificate\Render;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Preview tests.
 */
class PreviewTest extends TestCase {

	/**
	 * The preview shows the placeholder code and the design's preview data.
	 */
	public function test_values() {
		$values = Preview\values(
			array(
				'sample' => array(
					'event' => 'WordCamp Sample',
					'code'  => 'IGNORED',
					'name'  => '',
				),
			)
		);
		$this->assertSame( Preview\SAMPLE_CODE, $values['code'] );
		$this->assertSame( 'WordCamp Sample', $values['event'] );
		$this->assertNotSame( '', $values['name'] );
	}

	/**
	 * In a preview the QR code and the code show, even on a site that isn't public.
	 */
	public function test_placeholders_on_non_public_sites() {
		$design = array(
			'page'   => array(
				'w'  => 100,
				'h'  => 100,
				'bg' => '#fff',
			),
			'colors' => array(
				'text' => array(
					'light' => '#000',
					'dark'  => '#fff',
				),
			),
		);
		$qr     = array(
			'type' => 'qr',
			'x'    => 0,
			'y'    => 0,
			'w'    => 10,
			'h'    => 10,
		);
		$values = Preview\values( array() );

		add_filter( 'evcert_is_public_site', '__return_false' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own hook.
		$this->assertSame( '', Render\element_html( $qr, $design, $values, array( 'mode' => 'light' ) ) );
		$this->assertStringContainsString(
			'<img',
			Render\element_html(
				$qr,
				$design,
				$values,
				array(
					'mode'    => 'light',
					'preview' => true,
				)
			)
		);
		remove_filter( 'evcert_is_public_site', '__return_false' );
	}
}
