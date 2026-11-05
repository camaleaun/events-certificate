<?php
/**
 * Admin screens and settings.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Admin;
use EventsCertificate\Compat\CampTix;
use EventsCertificate\Settings;

/**
 * Admin tests.
 */
class AdminTest extends Test_Case {

	public function test_sanitize_posted_keeps_saved_settings_when_not_posted() {
		$saved = static function () {
			return array(
				'slug'         => 'certificado',
				'force_public' => true,
			);
		};
		add_filter( 'pre_option_' . Settings\OPTION, $saved, 5 );
		remove_filter( 'pre_option_' . Settings\OPTION, '\EventsCertificate\Settings\defaults', 1 );

		$kept = Admin\sanitize_posted( null );
		$this->assertSame( 'certificado', $kept['slug'] );
		$this->assertTrue( $kept['force_public'] );

		// A posted form without the checkbox turns it off.
		$posted = Admin\sanitize_posted( array( 'slug' => 'Certificado Válido' ) );
		$this->assertSame( 'certificado-valido', $posted['slug'] );
		$this->assertFalse( $posted['force_public'] );

		remove_filter( 'pre_option_' . Settings\OPTION, $saved, 5 );
	}

	public function test_empty_slug_falls_back_to_certificate() {
		$this->assertSame( 'certificate', Settings\sanitize( array( 'slug' => '  ' ) )['slug'] );
	}

	public function test_certificates_goes_right_after_attendees_in_camptix_menu() {
		global $submenu;

		$backup                  = $submenu;
		$submenu[ CampTix\MENU ] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			array( 'Tickets', 'read', CampTix\MENU ),
			array( 'Add New', 'read', 'post-new.php?post_type=tix_ticket' ),
			array( 'Attendees', 'read', 'edit.php?post_type=tix_attendee' ),
			array( 'Coupons', 'read', 'edit.php?post_type=tix_coupon' ),
			array( 'Setup', 'read', 'camptix_options' ),
			array( 'Certificates', 'read', Admin\SLUG ),
		);
		CampTix\move_after_attendees();
		$slugs   = wp_list_pluck( $submenu[ CampTix\MENU ], 2 );
		$submenu = $backup; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( array( CampTix\MENU, 'post-new.php?post_type=tix_ticket', 'edit.php?post_type=tix_attendee', Admin\SLUG, 'edit.php?post_type=tix_coupon', 'camptix_options' ), array_values( $slugs ) );
	}

	public function test_setup_tab_is_added() {
		$tabs = CampTix\add_setup_tab(
			array(
				'general'         => 'General',
				'payment'         => 'Payment',
				'email-templates' => 'E-mail Templates',
				'beta'            => 'Beta',
			)
		);
		$this->assertSame( array( 'general', 'payment', 'email-templates', CampTix\SECTION, 'beta' ), array_keys( $tabs ) );

		$this->assertSame( array( 'general', CampTix\SECTION ), array_keys( CampTix\add_setup_tab( array( 'general' => 'General' ) ) ) );
	}
}
