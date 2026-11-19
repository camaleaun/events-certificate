<?php
/**
 * The Base design: the certificate a site starts from.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Designs;
use EventsCertificate\Render;

/**
 * Flush with the page, no wording of its own, and the site (a network, a Playground blueprint) may give another.
 */
class BaseDesignTest extends Test_Case {

	/**
	 * Takes the filter off the tests that follow.
	 */
	protected function tear_down() {
		remove_all_filters( 'evcert_base_design' );
		parent::tear_down();
	}

	/**
	 * No bleed: the page is the sheet, so what is flush with its edge looks flush in the editor too.
	 */
	public function test_the_page_has_no_bleed() {
		$page = Designs\base()['page'];

		$this->assertSame( 0, (int) $page['bleed'] );
	}

	/**
	 * Nothing says "WordPress meetup" unless the site chose it.
	 */
	public function test_the_defaults_do_not_say_meetup() {
		$phrases = Render\phrases();

		$this->assertSame( '', $phrases['band_text'] );
		$this->assertStringNotContainsStringIgnoringCase( 'meetup', $phrases['took_part_text'] );
		$this->assertStringNotContainsStringIgnoringCase( 'meetup', (string) wp_json_encode( Designs\base() ) );
	}

	/**
	 * A filter gives another Base; what is not a design is ignored.
	 */
	public function test_the_base_is_filterable() {
		$other = static function ( $design ) {
			$design['colors']['band']['light'] = '#ff0000';

			return $design;
		};
		add_filter( 'evcert_base_design', $other );
		$this->assertSame( '#ff0000', Designs\base()['colors']['band']['light'] );
		remove_filter( 'evcert_base_design', $other );

		add_filter( 'evcert_base_design', '__return_empty_array' );
		$this->assertSame( 28, count( Designs\base()['elements'] ), 'Not a design: the Base that ships.' );
		remove_filter( 'evcert_base_design', '__return_empty_array' );

		add_filter( 'evcert_base_design', '__return_false' );
		$this->assertSame( 28, count( Designs\base()['elements'] ) );
	}

	/**
	 * A site without a design of its own gets one from the filtered Base (on a network: each site, the first time it is used).
	 */
	public function test_a_new_site_design_starts_from_the_filtered_base() {
		wp_set_current_user( 1 );
		delete_option( Designs\SITE_DESIGN_OPTION );
		add_filter(
			'evcert_base_design',
			static function ( $design ) {
				$design['colors']['band']['light'] = '#123456';

				return $design;
			}
		);

		$id = Designs\site_design_id();
		wp_set_current_user( 0 );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( '#123456', Designs\get( $id )['colors']['band']['light'] );
	}

	/**
	 * The Playground blueprint gives the meetup wording through the same filter, and it reaches the certificate.
	 */
	public function test_the_playground_blueprint_gives_the_meetup_wording() {
		$blueprint = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/.wordpress-org/blueprints/blueprint.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the blueprint of the plugin.
		$this->assertIsArray( $blueprint );
		$this->assertSame( 'installPlugin', $blueprint['steps'][1]['step'] );
		$this->assertSame( 'events-certificates', $blueprint['steps'][1]['pluginData']['slug'] );

		// The mu-plugin it writes, run here.
		$this->assertSame( 'writeFile', $blueprint['steps'][0]['step'] );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = wp_tempnam( 'evcert-meetup.php' );
		file_put_contents( $file, $blueprint['steps'][0]['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file.
		require $file;
		wp_delete_file( $file );

		$html = Render\pdf_body(
			array(
				array(
					array(
						'name'      => 'Ana',
						'code'      => '2611-ABCD-EFGH-JKLM',
						'status'    => 'issued',
						'issued_at' => '2026-10-31 15:00:00',
					),
					Designs\base(),
					array(),
				),
			),
			static function ( $text, $family, $weight, $size ) {
				return strlen( (string) $text ) * $size * 0.5;
			}
		);
		$text = wp_strip_all_tags( $html );

		$this->assertStringContainsString( strtoupper( 'WordPress' ) . ' MEETUP', $text ); // The band is in capital letters; built so the sniff for the name does not rewrite it.
		$this->assertStringContainsString( 'took part in the meetup', $text );
	}
}
