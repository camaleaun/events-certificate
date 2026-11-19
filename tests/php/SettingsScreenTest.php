<?php
/**
 * The settings screen: features first, then what each one needs (decision 0029).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Admin;
use EventsCertificate\Environment;

/**
 * Sections, rows that depend on a feature, and the better fields.
 */
class SettingsScreenTest extends Test_Case {

	/**
	 * A page name for the sections of the test.
	 */
	const PAGE = 'evcert-settings-test';

	/**
	 * Admin functions, and the sections added.
	 */
	protected function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		Admin\add_settings_fields( self::PAGE );
	}

	/**
	 * Takes the test's sections away.
	 */
	protected function tear_down() {
		unset( $GLOBALS['wp_settings_sections'][ self::PAGE ], $GLOBALS['wp_settings_fields'][ self::PAGE ] );
		parent::tear_down();
	}

	/**
	 * Features on top, then the certificates, the verification, the declaration (CampTix) and the data.
	 */
	public function test_sections_are_in_the_order_one_configures() {
		$expected = Environment\has_camptix()
			? array( 'evcert_features', 'evcert_issuing', 'evcert_authenticity', 'evcert_document', 'evcert_days', 'evcert_mine', 'evcert_data' )
			: array( 'evcert_features', 'evcert_issuing', 'evcert_authenticity', 'evcert_mine', 'evcert_data' );

		$this->assertSame( $expected, array_keys( $GLOBALS['wp_settings_sections'][ self::PAGE ] ) );
	}

	/**
	 * The switches are in the features section: verification always, self-issuing and the declaration with CampTix.
	 */
	public function test_the_switches_are_the_first_section() {
		$fields = array_keys( $GLOBALS['wp_settings_fields'][ self::PAGE ]['evcert_features'] );

		$this->assertContains( 'evcert_verification', $fields );
		$this->assertSame( Environment\has_camptix(), in_array( 'evcert_self_issue', $fields, true ) );
		$this->assertSame( Environment\has_camptix(), in_array( 'evcert_declaration', $fields, true ) );
		$this->assertArrayNotHasKey( 'evcert_verification', $GLOBALS['wp_settings_fields'][ self::PAGE ]['evcert_authenticity'], 'It is not repeated in its own section.' );
	}

	/**
	 * What only matters with a feature on is marked, so the script can hide it while the feature is off.
	 */
	public function test_rows_that_need_a_feature_are_marked() {
		$authenticity = $GLOBALS['wp_settings_fields'][ self::PAGE ]['evcert_authenticity'];
		foreach ( array( 'evcert_force_public', 'evcert_verify_page', 'evcert_slug' ) as $field ) {
			$this->assertSame( 'evcert-needs-verification', $authenticity[ $field ]['args']['class'], $field );
		}
		if ( Environment\has_camptix() ) {
			$this->assertSame( 'evcert-needs-declaration', $GLOBALS['wp_settings_fields'][ self::PAGE ]['evcert_document']['evcert_document_country']['args']['class'] );
		}
	}

	/**
	 * The signature can be chosen from a file (and is previewed in the browser), not only pasted.
	 */
	public function test_the_signature_has_a_file_chooser() {
		ob_start();
		Admin\render_signer_field();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="file"', $html );
		$this->assertStringContainsString( 'id="evcert_signature_file"', $html );
		$this->assertStringContainsString( 'name="evcert_settings[signature_svg]"', $html, 'The pasted code is still there.' );
		$this->assertStringNotContainsString( 'style=', $html, 'No inline style.' );
	}

	/**
	 * The date of issue is two choices, each said.
	 */
	public function test_the_date_of_issue_is_two_radios() {
		ob_start();
		Admin\render_issued_date_field();
		$html = ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( 'value="issued"', $html );
		$this->assertStringContainsString( 'value="event_end"', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	/**
	 * The days are cards (a name per language, a kind, tables), not a wide table, and carry no inline style.
	 */
	public function test_days_are_cards_without_inline_style() {
		\EventsCertificate\Days\save(
			array(
				array(
					'date'   => '2026-11-14',
					'names'  => array( 'en_US' => 'Day one' ),
					'mode'   => 'table',
					'tables' => array( 'en_US' => array( 'Table 1' ) ),
				),
			)
		);
		ob_start();
		Admin\render_days_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="evcert-day', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringContainsString( 'evcert-needs-declaration', $html );
	}
}
