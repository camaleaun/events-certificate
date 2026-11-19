<?php
/**
 * Where the site runs. Authenticity (QR code, verification links) only makes
 * sense when anyone can open the printed URL: on WordPress Playground,
 * localhost, .local/.test hosts or private IPs the links would never open,
 * so certificates show a placeholder instead of a fake QR code.
 *
 * Nothing here makes a request: a site can't learn whether it is reachable
 * from outside without asking a third party, so this is a heuristic, with a
 * setting and a filter to override it.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Environment;

use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether CampTix is active.
 *
 * @return bool
 */
function has_camptix() {
	return class_exists( 'CampTix_Plugin' );
}

/**
 * Whether GatherPress is active.
 *
 * @return bool
 */
function has_gatherpress() {
	return defined( 'GATHERPRESS_VERSION' );
}

/**
 * Which of the integrations are active: none, camptix, gatherpress or both.
 * The one place that decides it, so the menu, the generator page, the
 * settings and the abilities agree (decision 0019).
 *
 * @return string
 */
function integration() {
	if ( has_camptix() && has_gatherpress() ) {
		return 'both';
	}
	if ( has_camptix() ) {
		return 'camptix';
	}

	return has_gatherpress() ? 'gatherpress' : 'none';
}

/**
 * Whether the plugin has events of its own: only where no event plugin brings its own (with CampTix the
 * WordCamp is the event; with GatherPress, the GatherPress event is). Decision 0026.
 *
 * @return bool
 */
function own_events() {
	return 'none' === integration();
}

/**
 * Whether the screens of an event's participants exist (to add them, mark who came and issue): where the
 * plugin has events of its own, and with GatherPress, from its events. With CampTix alone certificates come
 * from the tickets, there is no list of participants.
 *
 * @return bool
 */
function participants_screens() {
	return 'camptix' !== integration();
}

/**
 * Whether the Generator (the admin screen and the public page) exists: only without CampTix and GatherPress.
 *
 * @return bool
 */
function generator_enabled() {
	return 'none' === integration();
}

/**
 * Whether verification is on: a code and a QR code on the certificates, and the address anyone can check them at.
 * Off by default (decision 0028); the setting, or the filter, turns it on. Even then it needs a public site (is_public()).
 *
 * @return bool
 */
function verification_enabled() {
	/**
	 * Filters whether certificates carry a code and a QR code to verify them.
	 *
	 * @param bool $on The setting "verification" (false by default).
	 */
	return (bool) apply_filters( 'evcert_verification_enabled', (bool) Settings\value( 'verification' ) );
}

/**
 * Whether this is WordPress Playground (PHP compiled to WebAssembly).
 *
 * Checked on the Playground CLI: SERVER_SOFTWARE is "PHP.wasm" and the
 * system name is "Emscripten".
 *
 * @return bool
 */
function is_playground() {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

	return 'PHP.wasm' === $software || 0 === stripos( php_uname( 's' ), 'Emscripten' );
}

/**
 * Why the site doesn't look public, or an empty list when it does.
 *
 * @param string|null $url Site address to check (defaults to home_url()).
 * @return string[] Reason codes: playground, local-environment, local-host,
 *                  private-ip.
 */
function non_public_reasons( $url = null ) {
	$reasons = array();

	if ( is_playground() ) {
		$reasons[] = 'playground';
	}
	if ( 'local' === wp_get_environment_type() ) {
		$reasons[] = 'local-environment';
	}

	$host = strtolower( (string) wp_parse_url( null === $url ? home_url() : $url, PHP_URL_HOST ) );
	$host = trim( $host, '[]' );

	if ( '' === $host ) {
		$reasons[] = 'local-host';
	} elseif ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$reasons[] = 'private-ip';
		}
	} elseif ( is_local_host( $host ) ) {
		$reasons[] = 'local-host';
	}

	return $reasons;
}

/**
 * Whether a host name can only be reached locally: no dot (intranet names),
 * localhost, or a reserved top-level domain (RFC 2606/6761/6762).
 *
 * @param string $host Lowercase host name.
 * @return bool
 */
function is_local_host( $host ) {
	if ( false === strpos( $host, '.' ) ) {
		return true;
	}
	foreach ( array( 'localhost', 'local', 'test', 'example', 'invalid', 'internal', 'lan', 'home.arpa' ) as $tld ) {
		if ( substr( $host, -strlen( '.' . $tld ) ) === '.' . $tld ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether certificates on this site get authenticity (QR code, code and
 * verification URL): the site looks public, or an admin forced it.
 *
 * @return bool
 */
function is_public() {
	$public = Settings\value( 'force_public' ) || array() === non_public_reasons();

	/**
	 * Filters whether the site is public, so certificates carry a real QR code
	 * and verification links.
	 *
	 * @param bool $public Whether the site is treated as public.
	 */
	return (bool) apply_filters( 'evcert_is_public_site', $public );
}
