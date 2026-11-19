<?php
/**
 * The addresses of a certificate: /certificate/CODE/, /pt-br/ and /pdf/.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Verify;

/**
 * The language and the PDF are path endpoints, not query args.
 */
class UrlsTest extends Test_Case {

	/**
	 * The rewrite rules, in the order WordPress tries them.
	 *
	 * @var array<string, string>
	 */
	private $rules = array();

	/**
	 * The rules the plugin adds, apart from the ones the site has.
	 */
	protected function set_up() {
		global $wp_rewrite;

		parent::set_up();
		$saved                       = $wp_rewrite->extra_rules_top;
		$wp_rewrite->extra_rules_top = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Verify\add_rules();
		$this->rules                 = $wp_rewrite->extra_rules_top;
		$wp_rewrite->extra_rules_top = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Which query a path answers with, by the first rule that matches.
	 *
	 * @param string $path Path without the site address.
	 * @return array<string, string>|null
	 */
	private function route( $path ) {
		foreach ( $this->rules as $regex => $query ) {
			if ( preg_match( '#' . $regex . '#', $path, $m ) ) {
				$query = preg_replace_callback(
					'/\$matches\[(\d+)\]/',
					static function ( $n ) use ( $m ) {
						return $m[ (int) $n[1] ];
					},
					substr( $query, strlen( 'index.php?' ) )
				);
				parse_str( $query, $out );

				return $out;
			}
		}

		return null;
	}

	/**
	 * Each endpoint reaches the page, with its language and format.
	 */
	public function test_the_endpoints_are_routed() {
		$code = '2611-ABCD-EFGH-JKLM';

		$this->assertSame(
			array(
				'evcert_verify' => '1',
				'evcert_code'   => $code,
			),
			$this->route( "certificate/$code/" )
		);
		$this->assertSame(
			array(
				'evcert_verify' => '1',
				'evcert_code'   => $code,
				'evcert_format' => 'pdf',
			),
			$this->route( "certificate/$code/pdf/" ),
			'pdf is the format, not a language'
		);
		$this->assertSame(
			array(
				'evcert_verify' => '1',
				'evcert_code'   => $code,
				'evcert_lang'   => 'pt-br',
			),
			$this->route( "certificate/$code/pt-br" )
		);
		$this->assertSame(
			array(
				'evcert_verify' => '1',
				'evcert_code'   => $code,
				'evcert_lang'   => 'de-de-formal',
				'evcert_format' => 'pdf',
			),
			$this->route( "verify/$code/de-de-formal/pdf/" )
		);
		$this->assertNull( $this->route( "other/$code/" ) );
		$this->assertNull( $this->route( "certificate/$code/pt-br/pdf/extra/" ) );
	}

	/**
	 * A language is the locale lowercased with a hyphen; going back looks it up among the installed ones.
	 */
	public function test_a_language_is_a_path_segment_and_back() {
		$this->assertSame( 'pt-br', Verify\locale_slug( 'pt_BR' ) );
		$this->assertSame( 'de-de-formal', Verify\locale_slug( 'de_DE_formal' ) );
		$this->assertSame( 'ast', Verify\locale_slug( 'ast' ) );

		$this->assertSame( 'en_US', Verify\locale_from_slug( 'en-us' ) );
		$this->assertSame( 'pt_BR', Verify\locale_from_slug( 'pt-br' ), 'pt_BR is installed on the test site.' );
		$this->assertSame( '', Verify\locale_from_slug( 'zz-zz' ) );
		$this->assertSame( '', Verify\locale_from_slug( 'pdf' ) );
	}

	/**
	 * The address without a language is the one it was issued in; another language is a segment; the PDF follows it.
	 */
	public function test_the_addresses() {
		$cert = array(
			'code'   => '2611-ABCD-EFGH-JKLM',
			'locale' => 'pt_BR',
		);
		$base = \EventsCertificate\Certificates\url( $cert['code'] );

		$this->assertSame( $base, Verify\page_url( $cert ) );
		$this->assertSame( $base, Verify\page_url( $cert, 'pt_BR' ), 'The language it was issued in has no segment.' );
		$this->assertSame( $base . 'en-us/', Verify\page_url( $cert, 'en_US' ) );
		$this->assertSame( $base . 'pdf/', Verify\pdf_url( $cert ) );
		$this->assertSame( $base . 'en-us/pdf/', Verify\pdf_url( $cert, 'en_US' ) );
		$this->assertStringNotContainsString( '?', Verify\pdf_url( $cert, 'en_US' ) );
	}

	/**
	 * Links of earlier builds (?format=pdf, ?evcert_lang=en_US) go to the paths for good.
	 */
	public function test_the_old_addresses_redirect_to_the_paths() {
		$cert = \EventsCertificate\Certificates\issue(
			array(
				'name'   => 'Maria Antiga',
				'locale' => 'pt_BR',
			)
		);
		global $wp_query;
		$wp_query->set( Verify\QUERY_VAR, 1 );
		$wp_query->set( Verify\CODE_VAR, $cert['code'] );
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new \RuntimeException( $status . ' ' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test.
			},
			10,
			2
		);

		$go   = function ( array $get ) {
			$_GET = $get;
			try {
				Verify\template_redirect();
			} catch ( \RuntimeException $e ) {
				return $e->getMessage();
			} finally {
				$_GET = array();
			}

			return '';
		};
		$base = \EventsCertificate\Certificates\url( $cert['code'] );

		$this->assertSame( '301 ' . $base . 'pdf/', $go( array( 'format' => 'pdf' ) ) );
		$this->assertSame( '301 ' . $base . 'en-us/', $go( array( 'evcert_lang' => 'en_US' ) ) );
		$this->assertSame(
			'301 ' . $base . 'en-us/pdf/',
			$go(
				array(
					'evcert_lang' => 'en_US',
					'format'      => 'pdf',
				)
			)
		);
		$this->assertSame(
			'301 ' . $base . '?tix_attendee_id=7&tix_edit_token=abc',
			$go(
				array(
					'evcert_lang'     => 'pt_BR',
					'tix_attendee_id' => '7',
					'tix_edit_token'  => 'abc',
				)
			),
			'The language it was issued in has no segment; the ticket link is kept.'
		);
		remove_all_filters( 'wp_redirect' );
	}
}
