<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress site this plugin is installed in (set WP_LOAD to use
 * another wp-load.php). Tests that write to the plugin's tables run inside a
 * transaction rolled back after each test (Test_Case).
 *
 * @package EventsCertificate
 */

// PHPUnit polyfills (yoast/phpunit-polyfills) for the snake_case fixtures.
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$evcert_wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : dirname( __DIR__, 5 ) . '/wp-load.php';
if ( ! is_file( $evcert_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at $evcert_wp_load; set WP_LOAD.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core constant.
require_once $evcert_wp_load;

// The plugin may not be active on that site.
if ( ! defined( 'EventsCertificate\VERSION' ) ) {
	require_once dirname( __DIR__, 2 ) . '/events-certificate.php';
}

require_once __DIR__ . '/class-test-case.php';
