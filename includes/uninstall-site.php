<?php
/**
 * Uninstall of one site. Kept apart from the plugin's code, which isn't
 * loaded when WordPress runs uninstall.php.
 *
 * @package EventsCertificate
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'evcert_uninstall_site' ) ) {
	/**
	 * Deletes the plugin's options and, only when an admin chose "delete all
	 * data", its tables. By default the certificates stay: printed
	 * certificates point to their verification page, and deleting the plugin
	 * by mistake must not make them unverifiable.
	 */
	function evcert_uninstall_site() {
		global $wpdb;

		$settings = get_option( 'evcert_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) ) {
			foreach ( array( 'evcert_certificates', 'evcert_designs', 'evcert_audit' ) as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- table names from the prefix, not user input.
			}
			delete_option( 'evcert_db_version' );
			delete_option( 'evcert_settings' );
		}
	}
}
