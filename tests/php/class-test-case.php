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
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Undoes set_up().
	 */
	protected function tear_down() {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		remove_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, '\EventsCertificate\Settings\defaults', 1 );
		restore_previous_locale();
		parent::tear_down();
	}
}
