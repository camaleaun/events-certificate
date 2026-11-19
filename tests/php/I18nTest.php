<?php
/**
 * What a certificate says, in the language it is shown in.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Certificates;
use EventsCertificate\Designs;
use EventsCertificate\Render;
use EventsCertificate\Verify;

/**
 * The tests run in English; a certificate issued in pt_BR must come out in Portuguese from it
 * (a download by an English-speaking admin, a visitor of an English site), and the visitor
 * may switch. Needs the pt_BR translation of the plugin installed (the tests skip without it).
 * Run in the four integration scenarios.
 */
class I18nTest extends Test_Case {

	/**
	 * Skips without the Portuguese translation, and shows the code and QR code.
	 */
	protected function set_up() {
		parent::set_up();
		if ( ! file_exists( WP_LANG_DIR . '/plugins/events-certificates-pt_BR.mo' ) || ! in_array( 'pt_BR', Verify\locales(), true ) ) {
			$this->markTestSkipped( 'The pt_BR translation of the plugin is not installed.' );
		}
		add_filter( 'evcert_is_public_site', '__return_true' );
		// The site's own formats (Settings → General): a Portuguese site's clock, whatever the site running the tests.
		add_filter( 'pre_option_time_format', array( $this, 'time_format' ) );
		add_filter( 'pre_option_date_format', array( $this, 'date_format' ) );
	}

	/**
	 * The site's time format.
	 *
	 * @return string
	 */
	public function time_format() {
		return 'G\\hi';
	}

	/**
	 * The site's date format.
	 *
	 * @return string
	 */
	public function date_format() {
		return 'j \\d\\e F \\d\\e Y';
	}

	/**
	 * Undoes set_up().
	 */
	protected function tear_down() {
		remove_filter( 'evcert_is_public_site', '__return_true' );
		remove_filter( 'pre_option_time_format', array( $this, 'time_format' ) );
		remove_filter( 'pre_option_date_format', array( $this, 'date_format' ) );
		parent::tear_down();
	}

	/**
	 * A certificate issued in Portuguese, of an event with times, a place, speakers and a link.
	 *
	 * @param array<string, mixed> $more More fields.
	 * @return array<string, mixed>
	 */
	private function certificate( array $more = array() ) {
		$event = Fixtures::event(
			array(
				'title'     => 'Meetup Tradução',
				'start'     => '2026-10-31 19:00',
				'end'       => '2026-10-31 21:30',
				'location'  => 'Belo Horizonte',
				'speakers'  => 'Ana Lima, Bruno Souza e Carla Dias',
				'community' => 'Comunidade Teste',
				'link'      => 'https://example.org/meetup',
			)
		);

		return $more + array(
			'context'   => 'event',
			'event_id'  => $event,
			'name'      => 'Maria da Conceição',
			'role'      => 'attendee',
			'hours'     => '2.5',
			'code'      => '2611-ABCD-EFGH-JKLM',
			'status'    => 'issued',
			'issued_at' => '2026-10-31 15:00:00',
			'locale'    => 'pt_BR',
		);
	}

	/**
	 * The text of the pages of a certificate, as the PDF makes them.
	 *
	 * @param array<string, mixed> $cert  Certificate.
	 * @param array<string, mixed> $extra Extras of pdf().
	 * @return string
	 */
	private function text_of( array $cert, array $extra = array() ) {
		$html = Render\pdf_body(
			array( array( $cert, Designs\base(), $extra ) ),
			static function ( $text, $family, $weight, $size ) {
				return strlen( (string) $text ) * $size * 0.5;
			}
		);

		return html_entity_decode( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '/<(\/?)(div|p|h1|h2|li|tr|td)\b/', ' <$1$2', $html ) ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Strings of the certificate that must not come out in English from a Portuguese certificate.
	 *
	 * @return string[]
	 */
	private function english() {
		return array(
			'Certificate of participation',
			'This is to certify that',
			'took part in',
			'Issued on',
			'Use the code above to verify',
			'Declaration of participation',
			'We declare that',
			'self-declared workload',
			'SPEAKERS',
			'VENUE',
			'October',
			'November',
			'hours',
		);
	}

	/**
	 * A Portuguese certificate downloaded from an English admin comes out in Portuguese: the first page.
	 */
	public function test_the_first_page_comes_out_in_the_language_it_was_issued_in() {
		$text = $this->text_of( $this->certificate() );

		$this->assertStringContainsString( 'Certificado de participação', $text );
		$this->assertStringContainsString( 'Certificamos que', $text );
		$this->assertStringContainsString( 'participou de', $text );
		$this->assertStringContainsString( '31 de outubro de 2026', $text );
		$this->assertStringContainsString( 'das 19h00 às 21h30 (2h30)', $text, 'The hours, in the site\'s clock format.' );
		$this->assertStringContainsString( 'PALESTRANTES', $text, 'Three speakers written with "e".' );
		$this->assertStringContainsString( 'Emitido em 31 de outubro de 2026', $text );
		foreach ( $this->english() as $word ) {
			$this->assertStringNotContainsString( $word, $text, "'$word' is English." );
		}
	}

	/**
	 * The declaration page: the sentence, the document, the days and the workload.
	 */
	public function test_the_declaration_page_comes_out_in_the_language_it_was_issued_in() {
		$text = $this->text_of(
			$this->certificate(
				array(
					'doc_type'   => 'id',
					'doc_label'  => 'Document',
					'doc_masked' => '••••567',
					'data_json'  => wp_json_encode( array( 'page2' => true ) ),
				)
			),
			array( 'document' => 'AB-1234567' )
		);

		$this->assertStringContainsString( 'Declaração de participação', $text );
		$this->assertStringContainsString( 'Declaramos que', $text );
		$this->assertStringContainsString( 'AB-1234567', $text );
		$this->assertStringContainsString( 'Documento', $text, 'The label of the document type, in the language shown, not the one stored.' );
		$this->assertStringContainsString( 'Belo Horizonte, 31 de outubro de 2026', $text, 'The place and the date, in the order the language writes them.' );
		foreach ( $this->english() as $word ) {
			$this->assertStringNotContainsString( $word, $text, "'$word' is English." );
		}
	}

	/**
	 * The visitor's choice of language wins over the one it was issued in.
	 */
	public function test_the_visitors_language_wins() {
		$english = $this->text_of( $this->certificate(), array( 'locale' => 'en_US' ) );

		$this->assertStringContainsString( 'Certificate of participation', $english );
		$this->assertStringContainsString( 'October 31, 2026', $english );
		$this->assertStringContainsString( 'from 19h00 to 21h30 (2h30)', $english );
		$this->assertStringNotContainsString( 'Certificado', $english );

		// A language that is not installed is not switched to: the one it was issued in.
		$this->assertStringContainsString( 'Certificado de participação', $this->text_of( $this->certificate(), array( 'locale' => 'xx_XX' ) ) );
	}

	/**
	 * The language shown by default: the one it was issued in, the site's when that one is gone.
	 */
	public function test_the_default_language_is_the_one_it_was_issued_in() {
		$this->assertSame( 'pt_BR', Render\certificate_locale( array( 'locale' => 'pt_BR' ) ) );
		$this->assertSame( 'en_US', Render\certificate_locale( array( 'locale' => 'en_US' ) ) );
		$this->assertSame( \EventsCertificate\Dates\site_locale(), Render\certificate_locale( array( 'locale' => 'xx_XX' ) ) );
		$this->assertSame( \EventsCertificate\Dates\site_locale(), Render\certificate_locale( array() ) );
	}

	/**
	 * Showing a certificate in a language leaves WordPress in the language it was in.
	 */
	public function test_the_language_is_put_back() {
		$before = determine_locale();
		Render\with_locale( 'pt_BR', fn() => $this->assertSame( 'pt_BR', determine_locale() ) );
		$this->assertSame( $before, determine_locale() );

		try {
			Render\with_locale(
				'pt_BR',
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( $before, determine_locale(), 'Even when what runs fails.' );
	}

	/**
	 * The certificate's page: the tools and the status in the language shown.
	 */
	public function test_the_page_is_in_the_language_shown() {
		$cert = Certificates\issue( $this->certificate() + array( 'source' => 'test' ) );
		$page = static function ( $locale ) use ( $cert ) {
			return Render\with_locale(
				$locale,
				static function () use ( $cert, $locale ) {
					ob_start();
					Verify\render_page( $cert, $cert['code'], $locale, false );

					return (string) ob_get_clean();
				}
			);
		};

		$pt = $page( 'pt_BR' );
		$this->assertStringContainsString( 'Compartilhar', $pt );
		$this->assertStringContainsString( 'Detalhes', $pt );
		$this->assertStringNotContainsString( '>Share<', $pt );
		$this->assertStringNotContainsString( 'Certificate on record', $pt );
		$this->assertStringNotContainsString( '>Details<', $pt );

		$en = $page( 'en_US' );
		$this->assertStringContainsString( '>Share<', $en );
		$this->assertStringContainsString( 'Certificate on record', $en );
	}

	/**
	 * A speaker's certificate issued in Portuguese comes out in Portuguese with its own title and verb.
	 */
	public function test_a_speaker_certificate_comes_out_in_portuguese() {
		$text = $this->text_of( $this->certificate( array( 'role' => 'speaker' ) ) );

		$this->assertStringContainsString( 'Certificado de palestrante', $text );
		$this->assertStringContainsString( 'proferiu palestra em', $text );
		$this->assertStringNotContainsString( 'Speaker certificate', $text );
		$this->assertStringNotContainsString( 'gave a talk at', $text );
		$this->assertStringNotContainsString( 'Certificado de participação', $text );
	}
}
