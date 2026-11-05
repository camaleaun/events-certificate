<?php
/**
 * Fixes what Strauss can't prefix in vendor-prefixed/ (run after it by
 * `composer prefix-namespaces`): class names php-font-lib builds from strings
 * and from its own namespace's position. See docs/decisions/0003.
 *
 * Usage: php bin/patch-prefixed.php
 *
 * @package EventsCertificate
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a CLI build script, outside WordPress.

$root    = dirname( __DIR__ ) . '/vendor-prefixed';
$patches = array(
	'dompdf/php-font-lib/src/FontLib/TrueType/File.php' => array(
		// The font type is the namespace segment before the class, wherever the namespace starts.
		'$class_parts = explode("\\\\", get_class($this));' . "\n" . '    return $class_parts[1];' => '$class_parts = explode("\\\\", get_class($this));' . "\n" . '    return $class_parts[count($class_parts) - 2];',
		'$class = "FontLib\\\\$type\\\\TableDirectoryEntry";' => '$class = "EventsCertificate\\\\Vendor\\\\FontLib\\\\$type\\\\TableDirectoryEntry";',
	),
);

$failed = false;
foreach ( $patches as $file => $replacements ) {
	$path = $root . '/' . $file;
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "missing: $file\n" );
		$failed = true;
		continue;
	}
	$code = file_get_contents( $path );
	foreach ( $replacements as $from => $to ) {
		if ( false !== strpos( $code, $to ) ) {
			continue; // Already patched.
		}
		if ( false === strpos( $code, $from ) ) {
			fwrite( STDERR, "not found in $file: $from\n" );
			$failed = true;
			continue;
		}
		$code = str_replace( $from, $to, $code );
	}
	file_put_contents( $path, $code );
	echo "patched: $file\n";
}
exit( $failed ? 1 : 0 );
