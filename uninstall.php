<?php
/**
 * Removes what the plugin leaves behind on every site of a network, or on
 * the site (includes/uninstall-site.php).
 *
 * @package EventsCertificate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/uninstall-site.php';

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $evcert_site ) {
		switch_to_blog( (int) $evcert_site );
		evcert_uninstall_site();
		restore_current_blog();
	}
} else {
	evcert_uninstall_site();
}
