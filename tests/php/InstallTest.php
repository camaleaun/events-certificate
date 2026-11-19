<?php
/**
 * Tables.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Install;

/**
 * Install tests.
 */
class InstallTest extends Test_Case {

	public function test_tables_exist_after_plugins_loaded() {
		global $wpdb;

		Install\maybe_install();
		foreach ( Install\tables() as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->assertSame( Install\DB_VERSION, get_option( Install\DB_VERSION_OPTION ) );
	}

	public function test_maybe_install_runs_only_when_the_version_changes() {
		Install\maybe_install();
		$this->assertFalse( Install\maybe_install() );
	}

	public function test_drafts_have_no_code_and_codes_are_unique() {
		global $wpdb;

		Install\maybe_install();
		$table = Install\tables()['certificates'];
		$now   = current_time( 'mysql', true );
		$row   = array(
			'name'       => 'Maria Silva',
			'created_at' => $now,
			'updated_at' => $now,
		);

		// Two drafts with the same name (homonyms) and no code.
		$this->assertSame( 1, $wpdb->insert( $table, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$this->assertSame( 1, $wpdb->insert( $table, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$this->assertSame( 1, $wpdb->insert( $table, $row + array( 'code' => '2611-04K7-M29Q-XAP3' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$suppress = $wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( $table, $row + array( 'code' => '2611-04K7-M29Q-XAP3' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->suppress_errors( $suppress );
	}
}
