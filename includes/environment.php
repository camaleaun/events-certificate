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
