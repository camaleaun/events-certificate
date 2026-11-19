<?php
/**
 * Release command for Events Certificates: bumps the version, refuses a release
 * without changelog and upgrade notice, runs the checks, then commits and tags.
 * The version tag runs .github/workflows/release.yml, which builds the zip and
 * publishes the GitHub release. Pushing is a flag: a pushed tag is a
 * publication, so it waits for the owner's OK (docs/decisions/0031).
 *
 * Three kinds of version (the flow is in .github/CONTRIBUTING.md):
 * - X.Y.Z-dev: trunk's next version. Header and constant only; no notes, no tag,
 *   Stable tag untouched ("Start X.Y.Z-dev").
 * - X.Y.Z-rc.N: a GitHub-only pre-release from release/X.Y. Tagged, with X.Y.Z's
 *   notes; Stable tag untouched.
 * - X.Y.Z: the release, from release/X.Y. Also moves Stable tag.
 *
 * The Changelog comes from changelog/ (one file per change, see
 * .github/CONTRIBUTING.md): a pre-release adds the entries to readme.txt, the
 * release adds them and deletes the files. The Upgrade Notice is written by hand.
 *
 * Not shipped (bin/ is export-ignored).
 *
 * Usage, from the WordPress Studio site root:
 *   studio wp --require=wp-content/plugins/events-certificates/bin/release.php evcert release 0.71-dev --no-checks
 *   … evcert release 0.72-dev          (trunk, after cutting release/0.71)
 *   … evcert release 0.71-rc.1         (release/0.71, GitHub only)
 *   … evcert release patch              (from 0.71-dev or 0.71-rc.1: 0.71)
 *   … evcert release patch --no-commit|--no-tag
 *   … evcert release patch --push       (the branch and the tag: only with the owner's OK)
 *   … evcert release patch --no-checks  (skip composer check, build, types, JS tests and Plugin Check)
 *
 * @package EventsCertificate
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- a WP-CLI dev tool run before WordPress loads.

require_once __DIR__ . '/release-lib.php';

/**
 * Runs a shell command; prints its output; stops the release on failure.
 *
 * @param string      $cmd    Shell command.
 * @param string|null $cwd    Working directory.
 * @param bool        $silent Don't print stdout.
 * @return string Stdout.
 */
function evcert_release_run( string $cmd, ?string $cwd = null, bool $silent = false ): string {
	list( $exit, $stdout, $stderr ) = evcert_release_try_run( $cmd, $cwd );
	if ( ! $silent && '' !== $stdout ) {
		WP_CLI::line( rtrim( $stdout ) );
	}
	if ( 0 !== $exit ) {
		WP_CLI::error( '' !== $stderr ? rtrim( $stderr ) : "Command failed (exit $exit): $cmd" );
	}

	return $stdout;
}

/**
 * Runs a shell command without stopping.
 *
 * @param string      $cmd Shell command.
 * @param string|null $cwd Working directory.
 * @return array{ 0: int, 1: string, 2: string } Exit code, stdout, stderr.
 */
function evcert_release_try_run( string $cmd, ?string $cwd = null ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- runs git, composer, npm and wp for the release.
	$process = proc_open(
		$cmd,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		return array( 1, '', "Failed to start: $cmd" );
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array( proc_close( $process ), $stdout, $stderr );
}

/**
 * Whether a command is on PATH.
 *
 * @param string $cmd Command.
 * @return bool
 */
function evcert_release_has_cmd( string $cmd ): bool {
	return 0 === evcert_release_try_run( 'command -v ' . escapeshellarg( $cmd ) )[0];
}

/**
 * Releases Events Certificates.
 */
class Evcert_Release_Command {

	/**
	 * Bump the version, check, commit and tag a release.
	 *
	 * ## OPTIONS
	 *
	 * <bump>
	 * : patch, minor, major, or an explicit X.Y.Z, X.Y.Z-dev or X.Y.Z-rc.N.
	 *
	 * [--commit]
	 * : Commit the release. Default true; --no-commit stops after bumping (implies --no-tag).
	 *
	 * [--tag]
	 * : Tag the release commit. Default true; --no-tag stops after the commit.
	 *
	 * [--push]
	 * : Push the branch and the tag. Default false: a pushed tag publishes the release.
	 *
	 * [--checks]
	 * : Run composer check, the JS build, types, JS tests and Plugin Check. Default true.
	 *
	 * ## EXAMPLES
	 *
	 *   studio wp --require=wp-content/plugins/events-certificates/bin/release.php evcert release 0.71-dev --no-checks
	 *   studio wp --require=wp-content/plugins/events-certificates/bin/release.php evcert release patch --no-checks
	 *
	 * @when before_wp_load
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Declared positive, negated by WP-CLI's --no-<flag>: --no-commit implies --no-tag; --push needs a tag.
		$commit = false !== ( $assoc_args['commit'] ?? true );
		$tag    = $commit && false !== ( $assoc_args['tag'] ?? true );
		$push   = $tag && ! empty( $assoc_args['push'] );
		$checks = false !== ( $assoc_args['checks'] ?? true );

		$dir    = dirname( __DIR__ );
		$main   = $dir . '/events-certificates.php';
		$readme = $dir . '/readme.txt';

		if ( ! evcert_release_has_cmd( 'git' ) ) {
			WP_CLI::error( 'git is required.' );
		}
		// Only the release notes (readme.txt) may be pending: the release commits everything.
		$dirty = array_filter(
			explode( "\n", rtrim( evcert_release_run( 'git status --porcelain', $dir, true ) ) ), // "XY path": keep the leading space.
			function ( $line ) {
				return '' !== $line && 'readme.txt' !== trim( substr( $line, 3 ) );
			}
		);
		if ( $dirty ) {
			WP_CLI::error( "Commit or stash these first; the release commits everything:\n" . implode( "\n", $dirty ) );
		}

		$source = (string) file_get_contents( $main );
		if ( ! preg_match( '/^ \* Version:\s*(\S+)$/m', $source, $m ) ) {
			WP_CLI::error( 'No " * Version:" line in events-certificates.php.' );
		}
		$current = $m[1];
		try {
			// A final that has no tag was never released: it may be reopened as its own dev version.
			$reopen  = 0 !== evcert_release_try_run( 'git rev-parse --verify --quiet refs/tags/' . escapeshellarg( $current ), $dir )[0];
			$version = evcert_release_bump( $current, (string) ( $args[0] ?? '' ), $reopen );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		// The final this version leads to (0.71 for 0.71-rc.1): its notes are the release's text.
		$final = (string) preg_replace( '/-.*$/', '', $version );
		$dev   = (bool) preg_match( '/-dev$/', $version );
		$pre   = ! $dev && $final !== $version;
		WP_CLI::log( "Events Certificates $current → $version" . ( $dev ? ' (dev: no tag)' : ( $pre ? ' (pre-release: GitHub only)' : '' ) ) );

		list( $has_remote ) = evcert_release_try_run( 'git remote get-url origin', $dir );
		if ( 0 === $has_remote && ! $dev ) {
			evcert_release_run( 'git fetch origin --tags --quiet', $dir, true );
			if ( 0 === evcert_release_try_run( 'git ls-remote --exit-code origin refs/tags/' . escapeshellarg( $version ), $dir )[0] ) {
				WP_CLI::error( "Tag $version already exists on origin." );
			}
		}

		// Every copy of the version: header, the namespaced constant, and Stable tag for a final only.
		WP_CLI::log( '  → bumping the version' );
		$source = preg_replace( '/^( \* Version:\s*)\S+$/m', '${1}' . $version, $source, 1 );
		$source = preg_replace( "/^const VERSION = '[^']*';$/m", "const VERSION = '$version';", (string) $source, 1, $count );
		if ( 1 !== $count ) {
			WP_CLI::error( "No \"const VERSION = '…';\" line in events-certificates.php." );
		}
		$text = (string) file_get_contents( $readme );
		if ( ! $dev && ! $pre ) {
			$text = (string) preg_replace( '/^Stable tag:.*$/m', 'Stable tag: ' . $version, $text, 1 );
		}

		// changelog/ entries become the version's Changelog; a final release also deletes them.
		try {
			$entries = $dev ? array() : evcert_release_changelog_entries( $dir );
			if ( $entries ) {
				$stable = '';
				if ( preg_match( '/^Stable tag:\s*(\d+\.\d+(?:\.\d+)?)\s*$/m', (string) file_get_contents( $readme ), $s ) ) {
					$stable = $s[1];
				}
				$needed = evcert_release_needed_bump( $entries );
				$rank   = array(
					'patch' => 1,
					'minor' => 2,
					'major' => 3,
				);
				if ( '' !== $stable && $stable !== $final && $rank[ $needed ] > $rank[ evcert_release_bump_kind( $stable, $final ) ] ) {
					WP_CLI::warning( "The changelog entries need a $needed release; $stable → $final is less." );
				}
				$text = evcert_release_compile_changelog( $text, $final, $entries );
				WP_CLI::log( '  → ' . count( $entries ) . ' changelog entries into readme.txt' );
			}
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Notes are written before releasing, in both sections; nothing is touched until they are.
		$missing = $dev ? array() : array_filter(
			array( 'Changelog', 'Upgrade Notice' ),
			function ( $section ) use ( $text, $final ) {
				return '' === evcert_release_entry( $text, $section, $final );
			}
		);
		if ( $missing ) {
			WP_CLI::error( "readme.txt has no \"= $final =\" entry with notes under: " . implode( ', ', $missing ) . '.' );
		}
		file_put_contents( $main, $source );
		file_put_contents( $readme, $text );
		if ( ! $pre ) {
			foreach ( $entries as $entry ) {
				unlink( $entry['file'] );
			}
		}

		if ( $checks ) {
			$this->checks( $dir );
		}

		if ( ! $commit ) {
			WP_CLI::success( "Bumped to $version (--no-commit)." );
			return;
		}
		// Only what the release changed: the version, the readme and the used entries.
		evcert_release_run( 'git add events-certificates.php readme.txt', $dir, true );
		// The used entries leave with the release; nothing to add when the folder holds nothing tracked.
		evcert_release_try_run( 'git add -A -- changelog', $dir );
		evcert_release_run( 'git commit --quiet -m ' . escapeshellarg( $dev ? "Start $version" : "chore: release $version" ), $dir, true );
		$branch = trim( evcert_release_run( 'git rev-parse --abbrev-ref HEAD', $dir, true ) );
		if ( $dev ) {
			// A dev version is never tagged: trunk just says what comes next.
			if ( ! $push || 0 !== $has_remote ) {
				WP_CLI::success( "Started $version locally (not pushed). Push with: git push origin $branch" );
				return;
			}
			evcert_release_run( 'git push origin ' . escapeshellarg( $branch ) . ' --quiet', $dir, true );
			WP_CLI::success( "Started $version." );
			return;
		}
		if ( ! $tag ) {
			WP_CLI::success( "Committed $version (--no-tag)." );
			return;
		}
		evcert_release_run( 'git tag ' . escapeshellarg( $version ), $dir, true );
		$kind = $pre ? ' (pre-release)' : '';
		if ( ! $push || 0 !== $has_remote ) {
			WP_CLI::success( "Released $version locally (not pushed)$kind. Publish with the owner's OK: git push origin $branch $version" );
			return;
		}
		evcert_release_run( 'git push origin ' . escapeshellarg( $branch ) . ' --quiet', $dir, true );
		evcert_release_run( 'git push origin ' . escapeshellarg( $version ) . ' --quiet', $dir, true );
		WP_CLI::success( "Released $version$kind: the version tag runs the Release workflow." );
	}

	/**
	 * The release gates: PHP checks, JS build, types, JS tests, Plugin Check.
	 *
	 * @param string $dir Plugin folder.
	 */
	private function checks( string $dir ): void {
		WP_CLI::log( '  → composer check' );
		evcert_release_run( 'composer check', $dir, true );

		$package = json_decode( (string) file_get_contents( $dir . '/package.json' ), true );
		foreach ( array( 'build', 'check-types', 'test:js' ) as $script ) {
			if ( isset( $package['scripts'][ $script ] ) ) {
				WP_CLI::log( "  → npm run $script" );
				evcert_release_run( 'npm run --silent ' . $script, $dir, true );
			}
		}

		// The site the plugin is in: WordPress Studio's CLI there, else plain wp.
		$site = dirname( $dir, 3 );
		$wp   = evcert_release_has_cmd( 'studio' ) && is_file( $site . '/STUDIO.md' ) ? 'studio wp' : 'wp --path=' . escapeshellarg( $site );
		// On what ships: bin/plugin-check.sh leaves out what .distignore does.
		WP_CLI::log( '  → Plugin Check' );
		list( $exit, $out, $err ) = evcert_release_try_run( 'bash ' . escapeshellarg( $dir . '/bin/plugin-check.sh' ) . ' ' . $wp, $site );
		// The script exits 1 when Plugin Check lists a file or an error (it prints nothing of the sort with a dev version's ignored code).
		if ( 0 !== $exit ) {
			WP_CLI::error( "Plugin Check found problems:\n" . trim( $out . $err ) );
		}
	}
}

WP_CLI::add_command( 'evcert release', 'Evcert_Release_Command' );
