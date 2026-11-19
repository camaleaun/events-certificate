<?php
/**
 * Base test case.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as Polyfill_Test_Case;

/**
 * Runs in English whatever the site's language, with the default settings
 * whatever the site saved, and inside a database transaction rolled back
 * after the test, so nothing a test writes stays on the dev site.
 */
abstract class Test_Case extends Polyfill_Test_Case {

	/**
	 * Locale, settings and transaction.
	 */
	protected function set_up() {
		global $wpdb;

		parent::set_up();
		switch_to_locale( 'en_US' );
		add_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, '\EventsCertificate\Settings\defaults', 1 );
		// Verification is off by default; the tests run with it on, the one about the default turns it off.
		add_filter( 'evcert_verification_enabled', '__return_true' );
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Skips the test unless the run has one of these integrations active
	 * (none, camptix, gatherpress, both; composer test:all runs the four).
	 *
	 * @param string ...$allowed Integrations the test is for.
	 */
	protected function requires_integration( string ...$allowed ) {
		$now = \EventsCertificate\Environment\integration();
		if ( ! in_array( $now, $allowed, true ) ) {
			$this->markTestSkipped( "For the integration '" . implode( "' or '", $allowed ) . "'; this run has '$now'." );
		}
	}

	/**
	 * Undoes set_up().
	 */
	protected function tear_down() {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		remove_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, '\EventsCertificate\Settings\defaults', 1 );
		remove_filter( 'evcert_verification_enabled', '__return_true' );
		restore_previous_locale();
		parent::tear_down();
	}
}
