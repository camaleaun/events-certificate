<?php
/**
 * Plugin Name:       Events Certificate
 * Plugin URI:        https://github.com/camaleaun/events-certificate
 * Description:       Verifiable attendance certificates for events, with a QR code anyone can check. Attendees from CampTix, GatherPress, a Meetup.com or other CSV export, or added by hand.
 * Version:           0.1.0-dev
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            camaleaun
 * Author URI:        https://profiles.wordpress.org/camaleaun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       events-certificate
 * Domain Path:       /languages
 *
 * @package EventsCertificate
 */

namespace EventsCertificate;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0-dev';

// Bundled libraries (dompdf, php-qrcode), namespaced EventsCertificate\Vendor\ by Strauss: docs/decisions/0003.
if ( is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}
require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/code.php';
require_once __DIR__ . '/includes/designs.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/class-list-table.php';
require_once __DIR__ . '/includes/admin-app.php';
require_once __DIR__ . '/includes/certificates.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/verify.php';
require_once __DIR__ . '/includes/share-image.php';
require_once __DIR__ . '/includes/preview.php';
require_once __DIR__ . '/includes/signer.php';
require_once __DIR__ . '/includes/compat/camptix.php';
require_once __DIR__ . '/includes/compat/camptix-front.php';

Install\bootstrap();
Designs\bootstrap();
Verify\bootstrap();
ShareImage\bootstrap();
Preview\bootstrap();
Signer\bootstrap();
Admin\bootstrap();
AdminApp\bootstrap();

// Integrations with other plugins register on this action, after every
// plugin has loaded; each checks that its plugin is active.
add_action( 'evcert_register_compat', 'EventsCertificate\Compat\CampTix\register' );
add_action(
	'plugins_loaded',
	static function () {
		/**
		 * Fires after all plugins have loaded, for the integrations with
		 * other plugins (CampTix, GatherPress) to register.
		 */
		do_action( 'evcert_register_compat' );
	},
	20
);
