<?php
/**
 * The languages certificates are shown in: all the installed ones by default, or the ones the site chose.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Settings;
use EventsCertificate\Verify;

/**
 * Languages tests.
 */
class LanguagesTest extends Test_Case {

	/**
	 * Settings as a test wants them (the base case pins the defaults).
	 *
	 * @param array<string, mixed> $changes Settings to change.
	 */
	private function settings( array $changes ) {
		add_filter(
			'pre_option_' . Settings\OPTION,
			function () use ( $changes ) {
				return $changes + Settings\defaults();
			},
			2
		);
	}

	/**
	 * Takes back the filters of the test.
	 */
	protected function tear_down() {
		remove_all_filters( 'pre_option_' . Settings\OPTION, 2 );
		remove_all_filters( 'evcert_installed_languages' );
		parent::tear_down();
	}

	/**
	 * The default is every installed language, so a site that never touches it works as before.
	 */
	public function test_default_is_every_installed_language() {
		$this->assertSame( array(), Settings\defaults()['languages'] );
		add_filter(
			'evcert_installed_languages',
			static function () {
				return array( 'pt_BR', 'es_ES' );
			}
		);
		$this->assertEqualsCanonicalizing( array_unique( array( get_locale(), 'pt_BR', 'es_ES', 'en_US' ) ), Verify\locales() );
	}

	/**
	 * A chosen list limits them; the site's own language is always there, first.
	 */
	public function test_a_chosen_list_limits_the_languages() {
		add_filter(
			'evcert_installed_languages',
			static function () {
				return array( 'pt_BR', 'es_ES', 'fr_FR' );
			}
		);
		$this->settings( array( 'languages' => array( 'pt_BR', 'fr_FR' ) ) );
		$locales = Verify\locales();
		$this->assertSame( get_locale(), $locales[0], 'The site language comes first.' );
		$this->assertContains( 'pt_BR', $locales );
		$this->assertContains( 'fr_FR', $locales );
		$this->assertNotContains( 'es_ES', $locales );
	}

	/**
	 * A language that is not installed can't be chosen (English always can).
	 */
	public function test_an_uninstalled_language_is_ignored() {
		add_filter(
			'evcert_installed_languages',
			static function () {
				return array( 'pt_BR' );
			}
		);
		$this->settings( array( 'languages' => array( 'xx_XX', 'en_US' ) ) );
		$this->assertNotContains( 'xx_XX', Verify\locales() );
		$this->assertContains( 'en_US', Verify\locales() );
	}

	/**
	 * The setting keeps clean locale names, once each.
	 */
	public function test_the_setting_is_sanitized() {
		$clean = Settings\sanitize( array( 'languages' => array( 'pt_BR', 'pt_BR', ' es_ES ', '<b>x</b>', '', 'de_DE_formal' ) ) );
		$this->assertSame( array( 'pt_BR', 'es_ES', 'de_DE_formal' ), $clean['languages'] );
		$this->assertSame( array(), Settings\sanitize( array( 'languages' => 'pt_BR' ) )['languages'] );
	}
}
