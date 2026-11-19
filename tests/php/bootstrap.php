<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress site this plugin is installed in (set WP_LOAD to use
 * another wp-load.php). Tests that write to the plugin's tables run inside a
 * transaction rolled back after each test (Test_Case).
 *
 * EVCERT_INTEGRATIONS=none|camptix|gatherpress|both chooses which of the two
 * integrations are active for the run, whatever the site has active (the
 * plugins must be installed in the site); composer test:all runs all four.
 * Not set: the site as it is.
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

// Which integrations are active, decided before WordPress loads its plugins.
$evcert_scenario = getenv( 'EVCERT_INTEGRATIONS' ) ? getenv( 'EVCERT_INTEGRATIONS' ) : '';
if ( '' !== $evcert_scenario ) {
	$evcert_wanted = array(
		'none'        => array(),
		'camptix'     => array( 'camptix/camptix.php' ),
		'gatherpress' => array( 'gatherpress/gatherpress.php' ),
		'both'        => array( 'camptix/camptix.php', 'gatherpress/gatherpress.php' ),
	);
	if ( ! isset( $evcert_wanted[ $evcert_scenario ] ) ) {
		fwrite( STDERR, "EVCERT_INTEGRATIONS must be none, camptix, gatherpress or both, not '$evcert_scenario'.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}
	$evcert_integrations = array( 'camptix/camptix.php', 'gatherpress/gatherpress.php' );
	// Like WordPress's own tests_add_filter(): the hooks table exists before plugin.php is loaded.
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- what tests_add_filter() does, before WordPress is loaded.
	$GLOBALS['wp_filter']['option_active_plugins'][10]['evcert_scenario']               = array(
		'function'      => static function ( $plugins ) use ( $evcert_wanted, $evcert_scenario, $evcert_integrations ) {
			return array_values( array_unique( array_merge( array_diff( (array) $plugins, $evcert_integrations ), $evcert_wanted[ $evcert_scenario ] ) ) );
		},
		'accepted_args' => 1,
	);
	$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10]['evcert_scenario'] = array(
		'function'      => static function ( $plugins ) use ( $evcert_integrations ) {
			return array_diff_key( (array) $plugins, array_flip( $evcert_integrations ) );
		},
		'accepted_args' => 1,
	);
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
}

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core constant.
require_once $evcert_wp_load;

// The plugin may not be active on that site.
if ( ! defined( 'EventsCertificate\VERSION' ) ) {
	require_once dirname( __DIR__, 2 ) . '/events-certificates.php';
}

// The run must have the integrations it was asked for (a plugin missing from the site would silently test another scenario).
if ( '' !== $evcert_scenario && \EventsCertificate\Environment\integration() !== $evcert_scenario ) {
	fwrite( STDERR, "EVCERT_INTEGRATIONS=$evcert_scenario, but the site loaded '" . \EventsCertificate\Environment\integration() . "': is the plugin installed (camptix, gatherpress)?\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once __DIR__ . '/class-test-case.php';
require_once __DIR__ . '/class-fixtures.php';
