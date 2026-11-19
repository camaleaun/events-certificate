<?php
/**
 * Public or not: whether certificates get authenticity.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Environment;

/**
 * Environment tests.
 */
class EnvironmentTest extends Test_Case {

	/**
	 * Host reasons only: the dev site's own environment type must not count.
	 *
	 * @param string $url Site address.
	 * @return string[]
	 */
	private function host_reasons( $url ) {
		return array_values( array_diff( Environment\non_public_reasons( $url ), array( 'playground', 'local-environment' ) ) );
	}

	public function test_public_hosts() {
		foreach ( array( 'https://saopaulo.wordcamp.org/2026/', 'https://wp-brasil.org', 'https://events.wordpress.org/curitiba/', 'http://8.8.8.8/' ) as $url ) {
			$this->assertSame( array(), $this->host_reasons( $url ), $url );
		}
	}

	public function test_local_hosts() {
		foreach ( array( 'http://localhost:8920', 'http://site.local', 'https://wp.test', 'http://intranet', 'http://a.localhost', 'http://x.example', 'http://box.home.arpa' ) as $url ) {
			$this->assertSame( array( 'local-host' ), $this->host_reasons( $url ), $url );
		}
	}

	public function test_private_and_loopback_ips() {
		foreach ( array( 'http://127.0.0.1:8080', 'http://192.168.0.10', 'http://10.1.2.3', 'http://172.16.0.1', 'http://[::1]/' ) as $url ) {
			$this->assertSame( array( 'private-ip' ), $this->host_reasons( $url ), $url );
		}
	}

	public function test_force_public_setting_and_filter() {
		$forced = static function () {
			return array( 'force_public' => true );
		};
		add_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $forced );
		$this->assertTrue( Environment\is_public() );

		add_filter( 'evcert_is_public_site', '__return_false' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own hook.
		$this->assertFalse( Environment\is_public() );
		remove_filter( 'evcert_is_public_site', '__return_false' );
		remove_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $forced );
	}

	public function test_this_dev_site_is_not_public() {
		$this->assertFalse( Environment\is_public() );
	}
}
