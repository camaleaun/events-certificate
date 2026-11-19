<?php
/**
 * The declaration page's parts: documents (CPF), countries, overlapping talks, hours.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Countries;
use EventsCertificate\Documents;
use EventsCertificate\Sessions;

/**
 * Declaration tests.
 */
class DeclarationTest extends Test_Case {

	/**
	 * CPF: check digits, formatting, the mask shows at most 60 %.
	 */
	public function test_cpf() {
		$this->assertTrue( Documents\valid( 'cpf', '529.982.247-25' ) );
		$this->assertTrue( Documents\valid( 'cpf', '52998224725' ) );
		$this->assertFalse( Documents\valid( 'cpf', '529.982.247-24' ) );
		$this->assertFalse( Documents\valid( 'cpf', '111.111.111-11' ) );
		$this->assertFalse( Documents\valid( 'cpf', '123' ) );
		$this->assertSame( '529.982.247-25', Documents\format( 'cpf', '52998224725' ) );
		$this->assertSame( '•••.•••.247-••', Documents\mask( 'cpf', '529.982.247-25' ) );
	}

	/**
	 * Turns countries on for a test (the site's setting; none by default).
	 *
	 * @param string $country   Code ('' for none).
	 * @param bool   $foreigner Whether a foreigner may say so.
	 */
	private function enable( $country, $foreigner = false ) {
		$this->enabled = static function ( $settings ) use ( $country, $foreigner ) {
			return array_merge(
				(array) $settings,
				array(
					'document_country'   => $country,
					'document_foreigner' => $foreigner,
				)
			);
		};
		add_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $this->enabled, 2 );
	}

	/**
	 * The filter that turned countries on, to take off.
	 *
	 * @var callable|null
	 */
	private $enabled;

	/**
	 * Takes the countries off again.
	 */
	protected function tear_down() {
		if ( $this->enabled ) {
			remove_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $this->enabled, 2 );
		}
		parent::tear_down();
	}

	/**
	 * No country is chosen by default: no document is asked until the site picks one, and a foreigner is not offered.
	 */
	public function test_no_document_unless_a_country_is_chosen() {
		$this->assertSame( '', \EventsCertificate\Settings\defaults()['document_country'] );
		$this->assertFalse( \EventsCertificate\Settings\defaults()['document_foreigner'] );
		$this->assertSame( array(), Documents\enabled() );
		$this->assertSame( array(), Documents\types( 'BR' ) );

		$this->enable( 'BR' );
		$this->assertSame( array( 'BR' ), array_keys( Documents\enabled() ) );
		$this->assertSame( array( 'cpf' => 'CPF' ), Documents\types( 'br' ), 'Brazil: only the CPF.' );
		$this->assertSame( array(), Documents\types( 'PT' ), 'Portugal is not the one chosen.' );
	}

	/**
	 * One country at most, and only one of the catalogue (Brazil's alone unless the flag is on).
	 */
	public function test_the_setting_keeps_one_catalogue_country() {
		$this->assertSame( 'BR', \EventsCertificate\Settings\sanitize( array( 'document_country' => 'br' ) )['document_country'] );
		$this->assertSame( '', \EventsCertificate\Settings\sanitize( array( 'document_country' => 'XX' ) )['document_country'], 'Not in the catalogue.' );
		$this->assertSame( '', \EventsCertificate\Settings\sanitize( array( 'document_country' => 'PT' ) )['document_country'], 'Only Brazil is offered by default.' );
		$this->assertSame( '', \EventsCertificate\Settings\sanitize( array() )['document_country'], 'None by default.' );
		$this->assertTrue( \EventsCertificate\Settings\sanitize( array( 'document_foreigner' => '1' ) )['document_foreigner'] );
		$this->assertFalse( \EventsCertificate\Settings\sanitize( array() )['document_foreigner'] );

		add_filter( 'evcert_more_document_countries', '__return_true' );
		$pt = \EventsCertificate\Settings\sanitize( array( 'document_country' => 'pt' ) )['document_country'];
		remove_filter( 'evcert_more_document_countries', '__return_true' );
		$this->assertSame( 'PT', $pt, 'With the other countries on.' );
	}

	/**
	 * Each document in the catalogue checks its own rule; the numbers are made up with the rule itself.
	 */
	public function test_each_document_has_its_rule() {
		$cases = array(
			'rut'      => array( '12.345.678-5', '12.345.678-4', 'RUT' ),
			'nif'      => array( '123456789', '123456788', 'NIF' ),
			'dni_es'   => array( '12345678Z', '12345678A', 'DNI/NIE' ),
			'ci_uy'    => array( '1.234.567-2', '1.234.567-3', 'CI' ),
			'dni_ar'   => array( '12.345.678', '1234', 'DNI' ),
			'dni_pe'   => array( '12345678', '1234567', 'DNI' ),
			'cc_co'    => array( '1.234.567.890', '12', 'Cédula' ),
			// A foreigner gives a passport instead, whatever the country: 6 to 9 letters and digits.
			'passport' => array( 'ab-123456', 'A1', 'Passport' ),
		);
		foreach ( $cases as $type => $case ) {
			$this->assertTrue( Documents\valid( $type, $case[0] ), "$type accepts {$case[0]}" );
			$this->assertFalse( Documents\valid( $type, $case[1] ), "$type refuses {$case[1]}" );
			$this->assertStringContainsString( $case[2], Documents\label( $type ), $type );
		}
		$this->assertTrue( Documents\valid( 'dni_es', 'X1234567L' ), 'A NIE: the letter X, 7 digits and its letter.' );
		$this->assertFalse( Documents\valid( 'dni_es', 'X1234567A' ) );

		// The CURP: 18 characters with its check digit (the number is built from the rule).
		$curp = 'HEGG560427MVZRRL0';
		$this->assertTrue( Documents\valid( 'curp', $curp . Documents\curp_check_digit( $curp ) ) );
		$this->assertFalse( Documents\valid( 'curp', $curp . ( ( 1 + (int) Documents\curp_check_digit( $curp ) ) % 10 ) ) );
	}

	/**
	 * Written out like the country writes it.
	 */
	public function test_documents_are_written_like_their_country() {
		$this->assertSame( '12.345.678-5', Documents\format( 'rut', '123456785' ) );
		$this->assertSame( '12345678Z', Documents\format( 'dni_es', '12.345.678-z' ) );
		$this->assertSame( '•••.•••.247-••', Documents\mask( 'cpf', '52998224725' ) );
	}

	/**
	 * Generic documents: the mask hides most of it.
	 */
	public function test_generic_mask() {
		$mask = Documents\mask( 'id', 'ab-1234567' );
		$this->assertSame( 9, mb_strlen( $mask ) );
		$this->assertSame( '••••••567', $mask ); // 9 characters, 3 shown (40 %).
		$this->assertLessThanOrEqual( 0.6, substr_count( $mask, '•' ) ? ( 9 - substr_count( $mask, '•' ) ) / 9 : 1 );
	}

	/**
	 * The stored hash confirms the number and nothing else; the number isn't in it.
	 */
	public function test_digest() {
		$columns = Documents\columns( 'cpf', '529.982.247-25' );
		$this->assertStringNotContainsString( '52998224725', $columns['doc_hash'] );
		$cert = $columns + array( 'id' => 0 );
		$this->assertTrue( Documents\check( $cert, '52998224725' ) );
		$this->assertFalse( Documents\check( $cert, '111.444.777-35' ) );
		$this->assertNull( Documents\check( array( 'id' => 1 ), '52998224725' ), 'No document, no check.' );
	}

	/**
	 * Wrong tries are limited per visitor and per certificate, for a while: guessing a number is not worth it,
	 * and a stranger can not turn the check off for everyone for good.
	 */
	public function test_wrong_tries_are_limited_for_a_while() {
		$cert                   = Documents\columns( 'cpf', '529.982.247-25' ) + array( 'id' => 777001 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		for ( $i = 0; $i < Documents\MAX_CHECKS_PER_VISITOR; $i++ ) {
			$this->assertFalse( Documents\check( $cert, '111.444.777-35' ), "Try $i is answered." );
		}
		$this->assertNull( Documents\check( $cert, '111.444.777-35' ), 'The visitor used their tries.' );
		$this->assertNull( Documents\check( $cert, '52998224725' ), 'Not even the right number is answered then: no oracle.' );

		// Another visitor is not locked by it.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->assertTrue( Documents\check( $cert, '52998224725' ) );

		// Many visitors together reach the certificate's own limit.
		for ( $i = 0; $i < Documents\MAX_CHECKS; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
			Documents\check( $cert, '111.444.777-35' );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertNull( Documents\check( $cert, '52998224725' ), 'The certificate used its tries.' );

		// And it is for a while, not for good.
		Documents\forget_tries( $cert );
		$this->assertTrue( Documents\check( $cert, '52998224725' ) );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Countries are translatable names by ISO code: only the ones that have a document, Brazil among them.
	 */
	public function test_countries() {
		$this->assertSame( array( 'BR' ), array_keys( Documents\catalog() ), 'Brazil alone is offered; the others are in the code (known) behind a flag.' );
		$this->assertCount( 9, Documents\known() );
		$this->assertTrue( Documents\more_countries() === false );
		$this->assertNotSame( '', Countries\name( 'br' ) );
		$this->assertSame( array_keys( Documents\catalog() ), array_values( array_intersect( array_keys( Documents\catalog() ), array_keys( Countries\all() ) ) ), 'Every country with a document has a name.' );
		$this->assertLessThan( 20, count( Countries\all() ), 'Only the countries used.' );
		$this->assertSame( '', Countries\name( 'US' ) );
		$this->assertSame( '', Countries\guess(), 'None on, none guessed.' );
	}

	/**
	 * Overlap is any shared time; back to back isn't.
	 */
	public function test_overlap() {
		$a = array(
			'start' => 100,
			'end'   => 200,
		);
		$this->assertTrue(
			Sessions\overlap(
				$a,
				array(
					'start' => 150,
					'end'   => 250,
				)
			)
		);
		$this->assertTrue(
			Sessions\overlap(
				$a,
				array(
					'start' => 120,
					'end'   => 130,
				)
			)
		);
		$this->assertFalse(
			Sessions\overlap(
				$a,
				array(
					'start' => 200,
					'end'   => 300,
				)
			)
		);
		$this->assertSame(
			60,
			Sessions\minutes(
				array(
					array(
						'start' => 0,
						'end'   => 1800,
					),
					array(
						'start' => 1800,
						'end'   => 3600,
					),
				)
			)
		);
	}

	/**
	 * Choosing talks of a day: parallel ones are refused (needs the local schedule).
	 */
	public function test_pick_refuses_parallel_talks() {
		$day   = array( 'date' => '2026-10-31' );
		$talks = Sessions\talks( $day );
		$same  = array();
		foreach ( $talks as $talk ) {
			$same[ $talk['start'] ][] = $talk['id'];
		}
		$pair = current(
			array_filter(
				$same,
				static function ( $ids ) {
					return count( $ids ) > 1;
				}
			)
		);
		if ( ! $pair ) {
			$this->markTestSkipped( 'No parallel talks in this schedule.' );
		}
		$this->assertWPError( Sessions\pick( $day + array( 'names' => array() ), array_slice( $pair, 0, 2 ) ) );
		$this->assertIsArray( Sessions\pick( $day + array( 'names' => array() ), array( $pair[0] ) ) );
	}

	/**
	 * A template's own declaration sheet is drawn with its fields (statement, activities).
	 */
	public function test_template_sheet() {
		$cert   = array(
			'name'       => 'Ana Lima',
			'code'       => '2611-04K7-M29Q-XAP3',
			'doc_label'  => 'CPF',
			'doc_masked' => '•••.•••.247-••',
			'data_json'  => wp_json_encode( array( 'page2' => true ) ),
		);
		$design = array(
			'page'     => array(
				'w'  => 400,
				'h'  => 300,
				'bg' => '#fff',
			),
			'colors'   => array(
				'text' => array(
					'light' => '#000',
					'dark'  => '#fff',
				),
			),
			'elements' => array(),
			'back'     => array(
				'elements' => array(
					array(
						'id'    => 'statement',
						'type'  => 'text',
						'x'     => 10,
						'y'     => 10,
						'w'     => 380,
						'h'     => 40,
						'font'  => 'Inter',
						'size'  => 9,
						'color' => '$text',
						'align' => 'left',
						'runs'  => array( array( 'field' => 'declaration_statement' ) ),
					),
				),
			),
		);
		$values = \EventsCertificate\Render\values( $cert );
		$html   = \EventsCertificate\Render\declaration( $cert, $design, $values, array( 'document' => '529.982.247-25' ) );
		$this->assertStringContainsString( 'Ana Lima', $html );
		$this->assertStringContainsString( '529.982.247-25', $html );
		$this->assertStringNotContainsString( 'evcert-page--declaration', $html ); // Not the standard page.
	}

	/**
	 * A WP_Error.
	 *
	 * @param mixed $value Value.
	 */
	private function assertWPError( $value ) {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}

	/**
	 * The Settings section says the document is optional and for countries that ask for one, naming none that does not.
	 */
	public function test_the_settings_section_says_it_is_optional() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		ob_start();
		\EventsCertificate\Admin\render_document_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Optional.', $html );
		$this->assertStringContainsString( 'such as Brazil', $html );
		$this->assertStringNotContainsString( 'United States', $html, 'No country is named as left out.' );
		$this->assertStringNotContainsString( 'United Kingdom', $html );
	}
}
