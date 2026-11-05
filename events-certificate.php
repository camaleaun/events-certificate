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

require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/code.php';

Install\bootstrap();
