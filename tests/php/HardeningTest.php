<?php
/**
 * What the directory's review looks for: escaped output, no inline tags.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Render;

/**
 * Hardening tests.
 */
class HardeningTest extends Test_Case {

	/**
	 * A design with hostile values.
	 *
	 * @return array<string, mixed>
	 */
	private function design() {
		$design                   = \EventsCertificate\Designs\base();
		$design['colors']['text'] = array(
			'light' => 'red;background:url(//example.com/x)',
			'dark'  => '#fff',
		);
		$design['elements']       = array(
			array(
				'id'       => 't',
				'type'     => 'text',
				'x'        => 10,
				'y'        => 10,
				'w'        => 100,
				'h'        => 20,
				'runs'     => array( array( 'text' => '<script>alert(1)</script>' ) ),
				'rotation' => 90,
				'opacity'  => 0.5,
			),
			array(
				'id'   => 'j',
				'type' => 'image',
				'x'    => 0,
				'y'    => 0,
				'w'    => 10,
				'h'    => 10,
				'src'  => 'javascript:alert(1)',
			),
			array(
				'id'   => 'p',
				'type' => 'image',
				'x'    => 0,
				'y'    => 0,
				'w'    => 10,
				'h'    => 10,
				'src'  => 'https://example.com/a.png',
			),
		);

		return $design;
	}

	/**
	 * Hostile values don't reach the markup; the layout stays.
	 */
	public function test_artwork_escaped() {
		$html = Render\kses_artwork( Render\artwork( $this->design(), Render\values( array( 'code' => 'X' ) ) ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'url(', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'transform:rotate(90deg)', $html );
		$this->assertStringContainsString( 'position:absolute', $html );
		$this->assertStringContainsString( 'opacity:0.500', $html );
		$this->assertStringContainsString( 'src="https://example.com/a.png"', $html );
	}

	/**
	 * Only color values come out of color().
	 */
	public function test_colors() {
		$design = $this->design();
		$this->assertSame( 'none', Render\color( $design, '$text', 'light' ) );
		$this->assertSame( '#fff', Render\color( $design, '$text', 'dark' ) );
		$this->assertSame( 'none', Render\color( $design, 'red;x:y', 'light' ) );
		$this->assertSame( '#a1b2c3', Render\color( $design, '#a1b2c3', 'light' ) );
	}

	/**
	 * The PDF reads only files inside the uploads folder.
	 */
	public function test_image_src_stays_in_uploads() {
		$base = wp_get_upload_dir()['baseurl'];
		$this->assertSame( '', Render\image_src( $base . '/../../wp-config.php', true ) );
		$this->assertSame( '', Render\image_src( 'https://example.com/a.png', true ) );
		$this->assertSame( '', Render\image_src( 'data:text/html;base64,PHA+', true ) );
		$this->assertSame( 'data:image/png;base64,iVBO', Render\image_src( 'data:image/png;base64,iVBO', true ) );
	}

	/**
	 * A signature pasted in Settings keeps only filled paths; DTDs (XXE)
	 * are refused; it can be removed.
	 */
	public function test_settings_signature() {
		$before = get_option( \EventsCertificate\Signer\SIGNATURE_OPTION );
		\EventsCertificate\Admin\sanitize_posted( array( 'signature_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40"><script>alert(1)</script><a href="javascript:x"><path d="M0 0L10 10Z" onclick="x()"/></a></svg>' ) );
		$saved = get_option( \EventsCertificate\Signer\SIGNATURE_OPTION );
		$this->assertSame( array( array( 'd' => 'M0 0L10 10Z' ) ), $saved['paths'] );
		$this->assertNull( \EventsCertificate\Signer\parse_svg( '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><svg>&e;</svg>' ) );
		\EventsCertificate\Admin\sanitize_posted( array( 'signature_remove' => '1' ) );
		$this->assertFalse( get_option( \EventsCertificate\Signer\SIGNATURE_OPTION ) );
		if ( $before ) {
			update_option( \EventsCertificate\Signer\SIGNATURE_OPTION, $before, false );
		}
	}

	/**
	 * Dates format on a site whose time zone is a UTC offset (WordPress's
	 * default, "+00:00"), which ICU doesn't take as it is.
	 */
	public function test_date_with_utc_offset() {
		$saved = get_option( 'timezone_string' );
		$gmt   = get_option( 'gmt_offset' );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -3 );
		$date = \EventsCertificate\Render\format_date( 1792533600, 'long' );
		update_option( 'timezone_string', $saved );
		update_option( 'gmt_offset', $gmt );
		$this->assertNotSame( '', $date );
		$this->assertStringContainsString( '2026', $date );
	}

	/**
	 * A separator next to an empty field is dropped.
	 */
	public function test_separator_of_empty_field() {
		$el = array(
			'runs' => array(
				array( 'field' => 'location' ),
				array( 'text' => ', ' ),
				array( 'field' => 'details' ),
			),
		);
		$this->assertSame(
			'May 5',
			Render\runs_html(
				$el,
				array(
					'location' => '',
					'details'  => 'May 5',
				)
			)
		);
		$this->assertSame(
			'Recife, May 5',
			Render\runs_html(
				$el,
				array(
					'location' => 'Recife',
					'details'  => 'May 5',
				)
			)
		);
	}

	/**
	 * A design's JSON survives kses (users without unfiltered_html, such as
	 * a site administrator on a network).
	 */
	public function test_design_json_survives_kses() {
		$design                        = \EventsCertificate\Designs\base();
		$design['elements'][2]['runs'] = array( array( 'text' => 'A & B <b>"q"</b> 5 < 6 > 4' ) );
		$before                        = get_current_user_id();
		wp_set_current_user( 1 );
		kses_init_filters();
		$id = wp_insert_post(
			array(
				'post_type'    => \EventsCertificate\Designs\POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'kses test',
				'post_content' => wp_slash( (string) wp_json_encode( $design ) ),
			)
		);
		kses_remove_filters();
		wp_set_current_user( $before );
		$back = json_decode( get_post_field( 'post_content', $id ), true );
		wp_delete_post( $id, true );
		$this->assertSame( 'A & B <b>"q"</b> 5 < 6 > 4', $back['elements'][2]['runs'][0]['text'] );
	}

	/**
	 * No <script> or <style> tags in the PHP that ships: scripts and styles
	 * are enqueued files.
	 */
	public function test_no_inline_tags() {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( array( 'events-certificates.php', 'uninstall.php', 'includes', 'blocks', 'build', 'build-signer' ) as $path ) {
			$files = is_dir( "$root/$path" ) ? new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$root/$path", \FilesystemIterator::SKIP_DOTS ) ) : array( new \SplFileInfo( "$root/$path" ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() || ! $file->isFile() ) {
					continue;
				}
				if ( preg_match( '/<(script|style)[\s>]/i', (string) file_get_contents( $file->getPathname() ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own files.
					$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
