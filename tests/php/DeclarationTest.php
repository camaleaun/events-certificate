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
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Declaration tests.
 */
class DeclarationTest extends TestCase {

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
		$this->assertSame( array( 'cpf' => 'CPF' ), Documents\types( 'br' ) );
		$this->assertArrayHasKey( 'id', Documents\types( 'PT' ) );
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
		$cert = $columns + array(
			'id'             => 0,
			'doc_check_fail' => 0,
		);
		$this->assertTrue( Documents\check( $cert, '52998224725' ) );
		$this->assertFalse( Documents\check( $cert, '111.444.777-35' ) );
		$this->assertNull( Documents\check( array_merge( $cert, array( 'doc_check_fail' => Documents\MAX_CHECKS ) ), '52998224725' ) );
	}

	/**
	 * Countries are translatable names by ISO code, and Brazil is there.
	 */
	public function test_countries() {
		$this->assertNotSame( '', Countries\name( 'br' ) );
		$this->assertGreaterThan( 200, count( Countries\all() ) );
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
	 * A WP_Error.
	 *
	 * @param mixed $value Value.
	 */
	private function assertWPError( $value ) {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
