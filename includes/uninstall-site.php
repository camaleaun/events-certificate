<?php
/**
 * Uninstall of one site. Kept apart from the plugin's code, which isn't
 * loaded when WordPress runs uninstall.php.
 *
 * @package EventsCertificate
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'evcert_uninstall_transients' ) ) {
	/**
	 * Removes the plugin's transients (tries of the document check, messages
	 * between a request and its redirect, the language names): short-lived
	 * data, gone whatever "delete data" says.
	 */
	function evcert_uninstall_transients() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options of the plugin's own transients, on uninstall.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$wpdb->esc_like( '_transient_evcert_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_evcert_' ) . '%'
			)
		);
	}
}

if ( ! function_exists( 'evcert_uninstall_site' ) ) {
	/**
	 * Deletes the plugin's caches (share images, font metrics) and, only when
	 * an admin chose "delete all data", its tables, templates, options and
	 * meta. By default the certificates stay: printed
	 * certificates point to their verification page, and deleting the plugin
	 * by mistake must not make them unverifiable.
	 */
	function evcert_uninstall_site() {
		global $wpdb;

		evcert_uninstall_transients();

		$settings = get_option( 'evcert_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) ) {
			foreach ( array( 'evcert_certificates', 'evcert_designs', 'evcert_people', 'evcert_person_roles' ) as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- table names from the prefix, not user input.
			}
			// The certificate templates and events, and the pages the plugin made.
			foreach ( get_posts(
				array(
					'post_type'   => array( 'evcert_design', 'evcert_event' ),
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			) as $design ) {
				wp_delete_post( (int) $design, true );
			}
			// The pages the plugin made: the "Check a certificate" form and the generator.
			foreach ( array( 'evcert_verify_page', 'evcert_quick_generator_page_id' ) as $page_option ) {
				$page = (int) get_option( $page_option );
				if ( $page && 'page' === get_post_type( $page ) ) {
					wp_trash_post( $page );
				}
			}
			// Signers (organizers), templates' roles and attendees' days.
			foreach ( array( '_evcert_signs', '_evcert_signer_role', '_evcert_signature', '_evcert_roles', 'evcert_days' ) as $key ) {
				delete_post_meta_by_key( $key );
			}
			foreach ( array( 'evcert_db_version', 'evcert_settings', 'evcert_locale_names', 'evcert_verify_page', 'evcert_quick_generator_page_id', 'evcert_days', 'evcert_doc_pepper', 'evcert_site_design', 'evcert_rewrite_slug', 'evcert_signature' ) as $option ) {
				delete_option( $option );
			}
			delete_transient( 'evcert_locale_names_tried' );
		}

		// Caches, made again when needed: share images and dompdf's font metrics.
		$uploads = wp_get_upload_dir();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			foreach ( array( 'evcert-share', 'evcert-fonts' ) as $dir ) {
				if ( is_dir( $uploads['basedir'] . '/' . $dir ) ) {
					$wp_filesystem->rmdir( $uploads['basedir'] . '/' . $dir, true );
				}
			}
		}
	}
}
