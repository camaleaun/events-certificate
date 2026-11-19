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
	 * Issuing stores the frozen data with a code and the design version; the look follows the current design.
	 */
	public function test_issue_freezes_data_with_a_code() {
		$cert = Certificates\issue(
			array(
				'name'        => "  Maria  da\tConceição ",
				'event_title' => 'WordCamp Test',
				'source'      => 'camptix',
				'source_ref'  => '123',
				'role'        => 'attendee',
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
		$this->assertSame( $cert['id'], Certificates\find_by_source( 'camptix', 123, 'attendee' )['id'] );

		// The look follows the current site design, whatever version it was issued with (docs/decisions/0001).
		$current = \EventsCertificate\Designs\get( \EventsCertificate\Designs\site_design_id() );
		$this->assertSame( $current, Certificates\design( $cert ) );
		$this->assertSame( $current, Certificates\design( array_merge( $cert, array( 'design_hash' => str_repeat( '0', 64 ) ) ) ) );
	}

	/**
	 * A correction issues a new certificate and marks the old one replaced.
	 */
	public function test_reissue_replaces_the_old_certificate() {
		$old = Certificates\issue(
			array(
				'name'       => 'Maria Teste',
				'role'       => 'attendee',
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
		$this->assertSame( $new['id'], Certificates\find_by_source( 'camptix', 7, 'attendee' )['id'] );
	}

	/**
	 * The correction form shows the certificate's own values and protects its save with a nonce.
	 */
	public function test_reissue_form_has_the_values_and_a_nonce() {
		$cert = Certificates\issue(
			array(
				'name'  => 'Maria Teste',
				'role'  => 'speaker',
				'hours' => '4',
			)
		);
		// Loaded on admin screens, not in a test.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		ob_start();
		\EventsCertificate\Admin\render_reissue_form( $cert );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="Maria Teste"', $html );
		$this->assertStringContainsString( 'name="evcert_certificate" value="' . (int) $cert['id'] . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '<strong>Speaker</strong>', $html, 'The role is shown, not editable.' );
		$this->assertStringNotContainsString( 'name="role"', $html );
		$this->assertStringContainsString( 'value="4"', $html );
	}

	/**
	 * The message of a failed correction comes from the user's own transient: text in the address is not shown.
	 */
	public function test_reissue_form_does_not_show_text_from_the_address() {
		$cert = Certificates\issue( array( 'name' => 'Maria Teste' ) );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( 1 );

		$_GET['reissue_error'] = 'Your account was suspended, call 555-0100';
		ob_start();
		\EventsCertificate\Admin\render_reissue_form( $cert );
		$spoofed = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'suspended', $spoofed );

		set_transient( 'evcert_reissue_error_1', 'The name on the certificate is required.', MINUTE_IN_SECONDS );
		$_GET['reissue_error'] = '1';
		ob_start();
		\EventsCertificate\Admin\render_reissue_form( $cert );
		$real = (string) ob_get_clean();
		unset( $_GET['reissue_error'] );
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'The name on the certificate is required.', $real );
		$this->assertFalse( get_transient( 'evcert_reissue_error_1' ), 'Shown once.' );
	}

	/**
	 * The uninstall removes the plugin's transients.
	 */
	public function test_uninstall_removes_the_transients() {
		require_once dirname( __DIR__, 2 ) . '/includes/uninstall-site.php';
		set_transient( 'evcert_dc_c_1', array( 'n' => 3 ), HOUR_IN_SECONDS );
		set_transient( 'other_plugin_value', 'kept', HOUR_IN_SECONDS );

		evcert_uninstall_transients();
		wp_cache_flush();

		$this->assertFalse( get_transient( 'evcert_dc_c_1' ) );
		$this->assertSame( 'kept', get_transient( 'other_plugin_value' ) );
		delete_transient( 'other_plugin_value' );
	}

	/**
	 * Revoking marks an issued certificate revoked, once.
	 */
	public function test_revoke() {
		$cert = Certificates\issue( array( 'name' => 'Ana Lima' ) );

		$this->assertTrue( Certificates\revoke( (int) $cert['id'], 'Issued by mistake' ) );
		$this->assertFalse( Certificates\revoke( (int) $cert['id'] ) );
		$cert = Certificates\get( (int) $cert['id'] );
		$this->assertSame( 'revoked', $cert['status'] );
		$this->assertSame( 'Issued by mistake', $cert['revoked_reason'] );
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
