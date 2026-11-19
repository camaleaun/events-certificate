<?php
/**
 * Plugin Name:       Events Certificates
 * Plugin URI:        https://github.com/camaleaun/events-certificates
 * Description:       Attendance certificates for meetups, courses and WordCamps, with an optional code and QR code anyone can verify. Participants typed, pasted, from a Meetup CSV, GatherPress or WordCamp.org's CampTix.
 * Version:           0.71-dev
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            camaleaun
 * Author URI:        https://profiles.wordpress.org/camaleaun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       events-certificates
 * Domain Path:       /languages
 *
 * @package EventsCertificate
 */

namespace EventsCertificate;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.71-dev';

// Bundled libraries (dompdf, php-qrcode), namespaced EventsCertificate\Vendor\ by Strauss: docs/decisions/0003.
if ( is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}
require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/dates.php';
require_once __DIR__ . '/includes/code.php';
require_once __DIR__ . '/includes/designs.php';
require_once __DIR__ . '/includes/days.php';
require_once __DIR__ . '/includes/sessions.php';
require_once __DIR__ . '/includes/countries.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-app.php';
require_once __DIR__ . '/includes/templates.php';
require_once __DIR__ . '/includes/certificates.php';
require_once __DIR__ . '/includes/events.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/events-admin.php';
require_once __DIR__ . '/includes/generator.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/verify.php';
require_once __DIR__ . '/includes/verify-page.php';
require_once __DIR__ . '/includes/share-image.php';
require_once __DIR__ . '/includes/preview.php';
require_once __DIR__ . '/includes/signer.php';
require_once __DIR__ . '/includes/compat/camptix.php';
require_once __DIR__ . '/includes/compat/camptix-front.php';
require_once __DIR__ . '/includes/compat/camptix-days.php';
require_once __DIR__ . '/includes/compat/camptix-choices.php';
require_once __DIR__ . '/includes/compat/gatherpress.php';
require_once __DIR__ . '/includes/compat/wcpt.php';
require_once __DIR__ . '/includes/mine-auth.php';
require_once __DIR__ . '/includes/mine.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/quick-generator.php';
require_once __DIR__ . '/includes/design-tokens.php';

Install\bootstrap();
Designs\bootstrap();
Events\bootstrap();
Verify\bootstrap();
VerifyPage\bootstrap();
Mine\bootstrap();
ShareImage\bootstrap();
Preview\bootstrap();
Signer\bootstrap();
Admin\bootstrap();
AdminApp\bootstrap();
Templates\bootstrap();
EventsAdmin\bootstrap();
Generator\bootstrap();
Abilities\bootstrap();
QuickGenerator\bootstrap();
DesignTokens\bootstrap();

// Integrations with other plugins register on this action, after every
// plugin has loaded; each checks that its plugin is active.
add_action( 'evcert_register_compat', 'EventsCertificate\Compat\CampTix\register' );
add_action( 'evcert_register_compat', 'EventsCertificate\Compat\GatherPress\register' );
add_action( 'evcert_register_compat', 'EventsCertificate\Compat\WCPT\register' );
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
