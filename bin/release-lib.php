<?php
/**
 * The release helpers: versions, changelog entries and how they become the
 * readme's Changelog. Pure functions, no WP-CLI and no WordPress, so they are
 * tested (tests/php/ReleaseFlowTest.php); bin/release.php is the command that
 * uses them (docs/decisions/0031).
 *
 * Not shipped (bin/ is export-ignored).
 *
 * @package EventsCertificate
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a dev tool run from the command line.

/**
 * The next version. A version is X.Y, with a patch (X.Y.Z) only when one is needed.
 *
 * @param string $current Current version (X.Y or X.Y.Z, or a dev / pre-release of one).
 * @param string $bump    patch, minor, major or an explicit X.Y[.Z][-dev|-rc.N].
 * @param bool   $reopen  The current final was never released: it may go back to its own X.Y.Z-dev.
 * @return string
 * @throws RuntimeException When the bump makes no sense.
 */
function evcert_release_bump( string $current, string $bump, bool $reopen = false ): string {
	// A final that was never released goes back to being in development: 0.71 → 0.71-dev.
	if ( $reopen && $bump === $current . '-dev' ) {
		return $bump;
	}
	// An explicit version, maybe a dev or pre-release (GitHub only: WordPress.org takes numbers only).
	if ( preg_match( '/^\d+\.\d+(\.\d+)?(-[0-9A-Za-z.]+)?$/', $bump ) ) {
		if ( version_compare( $bump, $current, '<=' ) ) {
			throw new RuntimeException( "$bump is not after $current." );
		}
		return $bump;
	}
	// From a dev or pre-release version, patch is the final of its series: 0.71-dev → 0.71.
	if ( 'patch' === $bump && preg_match( '/^(\d+\.\d+(?:\.\d+)?)-/', $current, $m ) ) {
		return $m[1];
	}
	if ( ! preg_match( '/^(\d+)\.(\d+)(?:\.(\d+))?$/', $current, $m ) ) {
		throw new RuntimeException( "Current version $current is not X.Y or X.Y.Z: give the next version (like 0.72), or patch for its final." );
	}
	switch ( $bump ) {
		case 'major':
			return ( $m[1] + 1 ) . '.0';
		case 'minor':
			return $m[1] . '.' . ( $m[2] + 1 );
		case 'patch':
			return $m[1] . '.' . $m[2] . '.' . ( isset( $m[3] ) ? $m[3] + 1 : 1 );
	}

	throw new RuntimeException( "Unknown bump \"$bump\": patch, minor, major or X.Y[.Z][-dev|-rc.N]." );
}

/**
 * The bump from one final version to the next.
 *
 * @param string $from Last final (X.Y or X.Y.Z).
 * @param string $to   Next final (X.Y or X.Y.Z).
 * @return string patch, minor or major.
 */
function evcert_release_bump_kind( string $from, string $to ): string {
	$a = array_pad( array_map( 'intval', explode( '.', $from ) ), 3, 0 );
	$b = array_pad( array_map( 'intval', explode( '.', $to ) ), 3, 0 );
	if ( $a[0] !== $b[0] ) {
		return 'major';
	}

	return $a[1] !== $b[1] ? 'minor' : 'patch';
}

/**
 * The bump the entries need: the highest Significance.
 *
 * @param array<int, array{ significance: string }> $entries Changelog entries.
 * @return string patch, minor, major, or '' without entries.
 */
function evcert_release_needed_bump( array $entries ): string {
	$rank = array(
		''      => 0,
		'patch' => 1,
		'minor' => 2,
		'major' => 3,
	);
	$max  = '';
	foreach ( $entries as $entry ) {
		if ( $rank[ $entry['significance'] ] > $rank[ $max ] ) {
			$max = $entry['significance'];
		}
	}

	return $max;
}

/**
 * The lines of one version's entry in a readme section.
 *
 * @param string $readme  readme.txt.
 * @param string $section Section title ("Changelog", "Upgrade Notice").
 * @param string $version Version.
 * @return string Non-empty lines, or ''.
 */
function evcert_release_entry( string $readme, string $section, string $version ): string {
	if ( ! preg_match( '/^== ' . preg_quote( $section, '/' ) . ' ==\s*$(.*?)(?=^== |\z)/ms', $readme, $part ) ) {
		return '';
	}
	if ( ! preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*$(.*?)(?=^= |\z)/ms', $part[1], $entry ) ) {
		return '';
	}

	return trim( (string) preg_replace( '/^\s*$\n/m', '', $entry[1] ) );
}

/**
 * The changelog entries waiting in changelog/: one file per change, written on
 * its branch ("Significance: patch|minor|major", "Type: add|fix|update|dev", a
 * blank line, one sentence).
 *
 * @param string $dir Plugin folder.
 * @return array<int, array{ file: string, significance: string, type: string, text: string }>
 * @throws RuntimeException When a file is not well formed.
 */
function evcert_release_changelog_entries( string $dir ): array {
	$entries = array();
	foreach ( (array) glob( $dir . '/changelog/*' ) as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}
		$name = basename( $file );
		if ( ! preg_match( '/^Significance:\s*(patch|minor|major)\s*\nType:\s*(add|fix|update|dev)\s*\n\s*\n(.+)$/s', str_replace( "\r", '', (string) file_get_contents( $file ) ), $m ) ) {
			throw new RuntimeException( "changelog/$name: expected \"Significance: patch|minor|major\", \"Type: add|fix|update|dev\", a blank line and the entry." );
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', $m[3] ) );
		if ( '' === $text ) {
			throw new RuntimeException( "changelog/$name has no entry." );
		}
		$entries[] = array(
			'file'         => $file,
			'significance' => $m[1],
			'type'         => $m[2],
			'text'         => $text,
		);
	}

	return $entries;
}

/**
 * The readme with the entries added to the version's Changelog: Added, Changed,
 * Fixed (dev entries stay out of the readme). Lines already there, written by
 * hand or by an earlier pre-release, are kept and not repeated.
 *
 * @param string                                          $readme  readme.txt.
 * @param string                                          $version The version's Changelog heading (X.Y.Z).
 * @param array<int, array{ type: string, text: string }> $entries Changelog entries.
 * @return string
 * @throws RuntimeException When readme.txt has no Changelog section.
 */
function evcert_release_compile_changelog( string $readme, string $version, array $entries ): string {
	$prefixes = array(
		'add'    => 'Added',
		'update' => 'Changed',
		'fix'    => 'Fixed',
	);
	$current  = evcert_release_entry( $readme, 'Changelog', $version );
	$lines    = array();
	foreach ( $prefixes as $type => $prefix ) {
		foreach ( $entries as $entry ) {
			$line = "* $prefix: " . $entry['text'];
			if ( $type === $entry['type'] && false === strpos( $current, $line ) && ! in_array( $line, $lines, true ) ) {
				$lines[] = $line;
			}
		}
	}
	if ( ! $lines ) {
		return $readme;
	}
	$heading = '= ' . $version . ' =';
	$rows    = explode( "\n", $readme );
	$section = array_search( '== Changelog ==', array_map( 'trim', $rows ), true );
	if ( false === $section ) {
		throw new RuntimeException( 'readme.txt has no "== Changelog ==" section.' );
	}
	$total = count( $rows );
	// The section's end: the next "== " heading.
	$end = $total;
	foreach ( array_slice( $rows, $section + 1, null, true ) as $i => $row ) {
		if ( 0 === strpos( $row, '== ' ) ) {
			$end = $i;
			break;
		}
	}
	// The version's heading inside the section, if it is there.
	$at = array_search( $heading, array_map( 'trim', array_slice( $rows, $section + 1, $end - $section - 1, true ) ), true );
	if ( false === $at ) {
		// A new entry, on top of the section.
		$first = $section + 1;
		while ( $first < $total && '' === trim( $rows[ $first ] ) ) {
			++$first;
		}
		array_splice( $rows, $section + 1, $first - $section - 1, array_merge( array( '', $heading ), $lines, array( '' ) ) );
		return implode( "\n", $rows );
	}
	// After the entry's last non-empty line.
	$last = $at;
	foreach ( array_slice( $rows, $at + 1, $end - $at - 1, true ) as $i => $row ) {
		if ( 0 === strpos( $row, '= ' ) ) {
			break;
		}
		if ( '' !== trim( $row ) ) {
			$last = $i;
		}
	}
	array_splice( $rows, $last + 1, 0, $lines );

	return implode( "\n", $rows );
}
