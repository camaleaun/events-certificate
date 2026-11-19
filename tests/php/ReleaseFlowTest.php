<?php
/**
 * The release helpers (bin/release-lib.php): versions, changelog entries and their compilation into readme.txt.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

/**
 * Release flow tests. They run in a temporary folder and touch nothing of the plugin.
 */
class ReleaseFlowTest extends Test_Case {

	/**
	 * A folder with a changelog/ in it.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Loads the helpers and makes the folder.
	 */
	protected function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 2 ) . '/bin/release-lib.php';
		$this->dir = sys_get_temp_dir() . '/evcert-release-' . wp_generate_password( 8, false );
		mkdir( $this->dir . '/changelog', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}

	/**
	 * Removes the folder.
	 */
	protected function tear_down() {
		foreach ( (array) glob( $this->dir . '/changelog/{*,.gitkeep}', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $this->dir . '/changelog' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	/**
	 * Writes a changelog entry.
	 *
	 * @param string $name Name.
	 * @param string $body Content.
	 */
	private function entry( $name, $body ) {
		file_put_contents( $this->dir . '/changelog/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A readme with a Changelog.
	 *
	 * @param string $changelog What is under "== Changelog ==".
	 * @return string
	 */
	private function readme( $changelog = "= 1.0.0 =\n\n* First public release.\n" ) {
		return "=== Events Certificates ===\nStable tag: 1.0.0\n\n== Changelog ==\n\n" . $changelog . "\n== Upgrade Notice ==\n\n= 1.0.0 =\n\nFirst public release.\n";
	}

	/**
	 * Versions: a dev one leads to its final with patch; a final to the next with the kind asked; explicit ones must be newer.
	 */
	public function test_bump() {
		$this->assertSame( '1.0.0', evcert_release_bump( '1.0.0-dev', 'patch' ) );
		$this->assertSame( '1.0.0', evcert_release_bump( '1.0.0-rc.2', 'patch' ) );
		$this->assertSame( '1.0.1', evcert_release_bump( '1.0.0', 'patch' ) );
		$this->assertSame( '1.1', evcert_release_bump( '1.0.4', 'minor' ) );
		$this->assertSame( '2.0', evcert_release_bump( '1.3.2', 'major' ) );
		$this->assertSame( '1.1.0-dev', evcert_release_bump( '1.0.0', '1.1.0-dev' ) );
		$this->assertSame( '1.0.0-rc.1', evcert_release_bump( '1.0.0-dev', '1.0.0-rc.1' ) );
		$this->assertSame( '1.0.0-rc.2', evcert_release_bump( '1.0.0-rc.1', '1.0.0-rc.2' ) );
	}

	/**
	 * Versions without a patch (0.71): the patch only appears when one is needed.
	 */
	public function test_bump_without_a_patch() {
		$this->assertSame( '0.71', evcert_release_bump( '0.71-dev', 'patch' ), 'The final of a dev series.' );
		$this->assertSame( '0.71', evcert_release_bump( '0.71-rc.2', 'patch' ) );
		$this->assertSame( '0.71.1', evcert_release_bump( '0.71', 'patch' ), 'A patch of a release is its first X.Y.1.' );
		$this->assertSame( '0.71.2', evcert_release_bump( '0.71.1', 'patch' ) );
		$this->assertSame( '0.72', evcert_release_bump( '0.71', 'minor' ) );
		$this->assertSame( '0.72', evcert_release_bump( '0.71.3', 'minor' ), 'A minor leaves the patch behind.' );
		$this->assertSame( '1.0', evcert_release_bump( '0.71', 'major' ) );
		$this->assertSame( '0.72-dev', evcert_release_bump( '0.71', '0.72-dev' ) );
		$this->assertSame( '0.71-rc.1', evcert_release_bump( '0.71-dev', '0.71-rc.1' ) );
		$this->assertSame( '0.71.1', evcert_release_bump( '0.71', '0.71.1' ) );
		$this->assertSame( '0.71-dev', evcert_release_bump( '0.71', '0.71-dev', true ), 'An unreleased 0.71 goes back to 0.71-dev.' );
	}

	/**
	 * Going to an older or the same version stays refused, with or without a patch.
	 */
	public function test_bump_without_a_patch_refuses_going_back() {
		foreach ( array( array( '0.72', '0.71' ), array( '0.71', '0.71' ), array( '0.71.1', '0.71' ), array( '0.71', '0.71-dev' ) ) as $case ) {
			try {
				evcert_release_bump( $case[0], $case[1] );
				$this->fail( 'Expected a refusal for ' . implode( ' → ', $case ) );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * The kind of bump also reads versions without a patch.
	 */
	public function test_kinds_without_a_patch() {
		$this->assertSame( 'minor', evcert_release_bump_kind( '0.71', '0.72' ) );
		$this->assertSame( 'patch', evcert_release_bump_kind( '0.71', '0.71.1' ) );
		$this->assertSame( 'patch', evcert_release_bump_kind( '0.71.1', '0.71.2' ) );
		$this->assertSame( 'major', evcert_release_bump_kind( '0.71.2', '1.0' ) );
	}

	/**
	 * A final that was never released can be reopened as its dev version, and only then.
	 */
	public function test_bump_reopens_an_unreleased_final_as_dev() {
		$this->assertSame( '1.0.0-dev', evcert_release_bump( '1.0.0', '1.0.0-dev', true ) );
		$this->expectException( \RuntimeException::class );
		evcert_release_bump( '1.0.0', '1.0.0-dev' );
	}

	/**
	 * Reopening is for the dev version of the very same final: no other going back.
	 */
	public function test_bump_reopens_only_the_same_series() {
		$this->expectException( \RuntimeException::class );
		evcert_release_bump( '1.1.0', '1.0.0-dev', true );
	}

	/**
	 * A version that is not after the current one, or a bump nobody knows, stops the release.
	 */
	public function test_bump_refuses_nonsense() {
		foreach ( array( array( '1.2.0', '1.1.0' ), array( '1.2.0', '1.2.0' ), array( '1.2.0', 'huge' ), array( '1.2.0-dev', 'minor' ) ) as $case ) {
			try {
				evcert_release_bump( $case[0], $case[1] );
				$this->fail( 'Expected a refusal for ' . implode( ' → ', $case ) );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * The kind of bump between two finals, and the biggest one the entries ask for.
	 */
	public function test_kinds() {
		$this->assertSame( 'patch', evcert_release_bump_kind( '1.0.0', '1.0.1' ) );
		$this->assertSame( 'minor', evcert_release_bump_kind( '1.0.3', '1.1.0' ) );
		$this->assertSame( 'major', evcert_release_bump_kind( '1.9.0', '2.0.0' ) );
		$this->assertSame( '', evcert_release_needed_bump( array() ) );
		$this->assertSame(
			'minor',
			evcert_release_needed_bump(
				array(
					array( 'significance' => 'patch' ),
					array( 'significance' => 'minor' ),
					array( 'significance' => 'patch' ),
				)
			)
		);
	}

	/**
	 * Entries are read from changelog/, ignoring .gitkeep, one sentence each.
	 */
	public function test_entries_are_read() {
		$this->entry( '.gitkeep', '' );
		$this->entry( '7-menus', "Significance: minor\nType: add\n\nExport navigation menus,\nwith their locations.\n" );
		$this->entry( '9-typo', "Significance: patch\nType: fix\n\nFix a typo.\n" );
		$entries = evcert_release_changelog_entries( $this->dir );
		$this->assertCount( 2, $entries );
		$by = array_column( $entries, null, 'type' );
		$this->assertSame( 'minor', $by['add']['significance'] );
		$this->assertSame( 'Export navigation menus, with their locations.', $by['add']['text'], 'Lines are joined.' );
		$this->assertSame( 'Fix a typo.', $by['fix']['text'] );
	}

	/**
	 * An entry that is not well formed stops the release with the file's name.
	 */
	public function test_a_malformed_entry_is_refused() {
		foreach ( array(
			'no-blank' => "Significance: patch\nType: fix\nFix it.\n",
			'bad-type' => "Significance: patch\nType: change\n\nFix it.\n",
			'bad-sig'  => "Significance: huge\nType: fix\n\nFix it.\n",
			'no-entry' => "Significance: patch\nType: fix\n\n  \n",
			'order'    => "Type: fix\nSignificance: patch\n\nFix it.\n",
		) as $name => $body ) {
			$this->entry( $name, $body );
			try {
				evcert_release_changelog_entries( $this->dir );
				$this->fail( "Expected a refusal for $name" );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( $name, $e->getMessage() );
			}
			unlink( $this->dir . '/changelog/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Entries become Added, Changed and Fixed lines in the version's Changelog; dev ones stay out.
	 */
	public function test_compile_adds_lines_to_the_versions_entry() {
		$entries = array(
			array(
				'type' => 'fix',
				'text' => 'Fix a typo.',
			),
			array(
				'type' => 'add',
				'text' => 'Export menus.',
			),
			array(
				'type' => 'update',
				'text' => 'Faster list.',
			),
			array(
				'type' => 'dev',
				'text' => 'Rename a test.',
			),
		);
		$text    = evcert_release_compile_changelog( $this->readme(), '1.0.0', $entries );
		$this->assertStringContainsString( "* First public release.\n* Added: Export menus.\n* Changed: Faster list.\n* Fixed: Fix a typo.\n", $text );
		$this->assertStringNotContainsString( 'Rename a test', $text );
		$this->assertSame( 1, substr_count( $text, '= 1.0.0 =' ) - substr_count( $text, "== Upgrade Notice ==\n\n= 1.0.0 =" ), 'The Upgrade Notice is untouched.' );
	}

	/**
	 * A version with no entry yet gets one, on top, and a second run adds nothing twice.
	 */
	public function test_compile_makes_a_new_entry_on_top_and_is_idempotent() {
		$entries = array(
			array(
				'type' => 'fix',
				'text' => 'Fix a typo.',
			),
		);
		$once    = evcert_release_compile_changelog( $this->readme(), '1.0.1', $entries );
		$this->assertMatchesRegularExpression( '/== Changelog ==\s+= 1\.0\.1 =\s+\* Fixed: Fix a typo\.\s+= 1\.0\.0 =/', $once );
		$this->assertSame( $once, evcert_release_compile_changelog( $once, '1.0.1', $entries ), 'Running twice (a pre-release, then the release) repeats nothing.' );
		$this->assertSame( $this->readme(), evcert_release_compile_changelog( $this->readme(), '1.0.1', array() ), 'Nothing to add: the readme is as it was.' );
	}

	/**
	 * A version's notes are what is under its heading; they are the release's text.
	 */
	public function test_entry_of_a_version() {
		$this->assertSame( '* First public release.', evcert_release_entry( $this->readme(), 'Changelog', '1.0.0' ) );
		$this->assertSame( 'First public release.', evcert_release_entry( $this->readme(), 'Upgrade Notice', '1.0.0' ) );
		$this->assertSame( '', evcert_release_entry( $this->readme(), 'Changelog', '9.9.9' ) );
	}
}
