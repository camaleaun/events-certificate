<?php
/**
 * Where the plugin's stylesheets and scripts are. Their sources are assets/scss
 * and assets/es, built by `npm run build` (bin/build-assets.mjs) into
 * assets/css and assets/js, in a readable copy and a .min one. Like WordPress
 * core, the .min files load unless SCRIPT_DEBUG is on; on a "local"
 * environment the readable ones load, with the file's time as the version.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * What to load: the file name suffix and where the version comes from.
 *
 * @param bool $debug Whether SCRIPT_DEBUG is on.
 * @param bool $local Whether the environment type is "local".
 * @return array{suffix: string, version: string} The suffix ('' or '.min') and the version source ('mtime' or 'plugin').
 */
function choose( $debug, $local ) {
	return array(
		'suffix'  => ( $debug || $local ) ? '' : '.min',
		'version' => $local ? 'mtime' : 'plugin',
	);
}

/**
 * The address and version of a built stylesheet or script.
 *
 * @param string $name Name of the entry: "generator" for assets/css/generator.css.
 * @param string $type "css" or "js".
 * @return array{url: string, version: string}
 */
function src( $name, $type ) {
	$debug  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
	$local  = 'local' === wp_get_environment_type();
	$choice = choose( $debug, $local );
	$dir    = dirname( __DIR__ ) . '/assets/' . $type . '/';
	$file   = $name . $choice['suffix'] . '.' . $type;
	// Half-built: the other copy is as good.
	if ( ! is_readable( $dir . $file ) ) {
		$other = $name . ( '' === $choice['suffix'] ? '.min' : '' ) . '.' . $type;
		if ( is_readable( $dir . $other ) ) {
			$file = $other;
		} elseif ( $debug || $local ) {
			_doing_it_wrong( __FUNCTION__, esc_html( 'The assets are not built. Run "npm ci && npm run build" in the plugin folder.' ), esc_html( \EventsCertificate\VERSION ) );
		}
	}
	$mtime = 'mtime' === $choice['version'] && is_readable( $dir . $file ) ? (int) filemtime( $dir . $file ) : 0;

	return array(
		'url'     => plugins_url( 'assets/' . $type . '/' . $file, dirname( __DIR__ ) . '/events-certificates.php' ),
		'version' => $mtime ? (string) $mtime : \EventsCertificate\VERSION,
	);
}
