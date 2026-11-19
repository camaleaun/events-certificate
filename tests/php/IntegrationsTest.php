<?php
/**
 * What each combination of the integrations (CampTix, GatherPress) switches on.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Admin;
use EventsCertificate\Environment;
use EventsCertificate\QuickGenerator;

/**
 * Run by composer test:all in the four scenarios: no integration, only CampTix,
 * only GatherPress, both. What the plugin does with them is decided in one place
 * (Environment\integration()); these tests pin down what follows from it.
 */
class IntegrationsTest extends Test_Case {

	/**
	 * The detection follows the plugins that are loaded.
	 */
	public function test_integration_follows_the_active_plugins() {
		$camptix     = class_exists( 'CampTix_Plugin' );
		$gatherpress = defined( 'GATHERPRESS_VERSION' );

		$this->assertSame( $camptix, Environment\has_camptix() );
		$this->assertSame( $gatherpress, Environment\has_gatherpress() );
		$names = array(
			'0-0' => 'none',
			'1-0' => 'camptix',
			'0-1' => 'gatherpress',
			'1-1' => 'both',
		);
		$this->assertSame( $names[ (int) $camptix . '-' . (int) $gatherpress ], Environment\integration() );
	}

	/**
	 * The Certificates menu: its own, unless one integration is the only one (then it is a submenu of that plugin's).
	 */
	public function test_menu_parent() {
		$expected = array(
			'none'        => '',
			'camptix'     => 'edit.php?post_type=tix_ticket',
			'gatherpress' => 'edit.php?post_type=gatherpress_event',
			'both'        => '',
		);

		$this->assertSame( $expected[ Environment\integration() ], Admin\menu_parent() );
	}

	/**
	 * The settings are saved with the plugin's own group, or the host plugin's when Certificates lives in its menu.
	 */
	public function test_settings_group() {
		$expected = array(
			'none'        => Admin\GROUP,
			'camptix'     => 'camptix_options',
			'gatherpress' => 'gatherpress_settings',
			'both'        => Admin\GROUP,
		);

		$this->assertSame( $expected[ Environment\integration() ], apply_filters( 'evcert_settings_group', Admin\GROUP ) );
	}

	/**
	 * The page of the generator is made only where there is nothing else that issues certificates.
	 */
	public function test_generator_page_is_wanted_only_without_integrations() {
		$this->assertSame( 'none' === Environment\integration(), QuickGenerator\wanted() );
	}

	/**
	 * What each integration adds stays when the other is active too: the ticket page step (CampTix) and the import (GatherPress).
	 */
	public function test_integration_features_stay_with_both() {
		$this->assertSame( Environment\has_camptix(), (bool) has_filter( 'camptix_shortcode_contents', 'EventsCertificate\Compat\CampTix\shortcode_contents' ) );
		$this->assertSame( Environment\has_camptix(), (bool) has_filter( 'camptix_edit_info_cell_content', 'EventsCertificate\Compat\CampTix\edit_info_cell' ) );
		$this->assertSame( Environment\has_gatherpress(), (bool) has_action( 'admin_post_evcert_gatherpress_open', 'EventsCertificate\Compat\GatherPress\handle_open' ) );
		$this->assertSame( Environment\has_gatherpress(), (bool) has_action( 'admin_post_evcert_gatherpress_sync', 'EventsCertificate\Compat\GatherPress\handle_sync' ) );
	}

	/**
	 * The ability that imports a GatherPress event exists only with GatherPress.
	 */
	public function test_import_ability_needs_gatherpress() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
		$this->assertSame( Environment\has_gatherpress(), null !== wp_get_ability( 'events-certificates/import-gatherpress-event' ) );
	}

	/**
	 * The overview the abilities give reports which integrations are active.
	 */
	public function test_overview_reports_the_integrations() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
		wp_set_current_user( 1 );
		$overview = wp_get_ability( 'events-certificates/get-overview' )->execute( array() );
		wp_set_current_user( 0 );

		$this->assertIsArray( $overview );
		$this->assertSame( Environment\has_camptix(), (bool) $overview['integrations']['camptix'] );
		$this->assertSame( Environment\has_gatherpress(), (bool) $overview['integrations']['gatherpress'] );
	}

	/**
	 * What exists in each mode (decision 0026): events of the plugin and the Generator only without CampTix and
	 * GatherPress; participants where their screens exist; no Settings or Generator button on the Certificates screen.
	 */
	public function test_each_mode_has_only_its_screens() {
		$mode = Environment\integration();

		$this->assertSame( 'none' === $mode, Environment\own_events() );
		$this->assertSame( 'none' === $mode, Environment\generator_enabled() );
		$this->assertSame( 'camptix' !== $mode, Environment\participants_screens() );

		// Only the link to the templates is at the top of the Certificates screen.
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\Compat\CampTix\render_settings_link' ) );
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\Compat\GatherPress\render_settings_link' ) );
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\EventsAdmin\render_button' ) );
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\Generator\render_button' ) );
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\QuickGenerator\render_button' ) );
		$this->assertNotFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\Templates\render_button' ) );
	}

	/**
	 * The abilities follow the mode too.
	 */
	public function test_abilities_follow_the_mode() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
		$has = static function ( $name ) {
			return null !== wp_get_ability( 'events-certificates/' . $name );
		};

		$this->assertTrue( $has( 'get-certificates' ) );
		$this->assertTrue( $has( 'revoke-certificate' ) );
		$this->assertTrue( $has( 'get-settings' ) );
		$this->assertSame( Environment\own_events(), $has( 'create-event' ) );
		$this->assertSame( Environment\own_events(), $has( 'quick-issue' ) );
		$this->assertSame( Environment\participants_screens(), $has( 'get-participants' ) );
		$this->assertSame( Environment\participants_screens(), $has( 'issue-certificates' ) );
	}
}
