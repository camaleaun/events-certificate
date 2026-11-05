<?php
/**
 * The renderer mirrors the editor's model.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Render;

/**
 * Lengths, anchors, corner shapes and fields.
 */
class RenderTest extends Test_Case {

	/**
	 * Lengths in units, percentages and calc().
	 */
	public function test_parse_len() {
		$this->assertSame( 20.0, Render\parse_len( '20pt', 100 ) );
		$this->assertEqualsWithDelta( 28.3465, Render\parse_len( '10mm', 100 ), 0.0001 );
		$this->assertSame( 50.0, Render\parse_len( '50%', 100 ) );
		$this->assertSame( 60.0, Render\parse_len( 'calc(100% - 40pt)', 100 ) );
		$this->assertSame( 12.0, Render\parse_len( 12, 100 ) );
		$this->assertSame( 0.0, Render\parse_len( '', 100 ) );
	}

	/**
	 * Anchors resolve against the page or the safe margin.
	 */
	public function test_resolve_anchors() {
		$page = array(
			'w'    => 842,
			'h'    => 595,
			'safe' => 28,
		);
		$el   = Render\resolve(
			array(
				'type'   => 'text',
				'layout' => array(
					'ref' => 'safe',
					'h'   => array(
						'mode' => 'center',
						'size' => '200pt',
					),
					'v'   => array(
						'mode' => 'end',
						'end'  => '10pt',
						'size' => '20pt',
					),
				),
			),
			$page
		);
		$this->assertEqualsWithDelta( 321.0, $el['x'], 0.001 );
		$this->assertEqualsWithDelta( 200.0, $el['w'], 0.001 );
		$this->assertEqualsWithDelta( 595 - 28 - 10 - 20, $el['y'], 0.001 );
	}

	/**
	 * Corner shapes become path data, radii clamped to half the side.
	 */
	/**
	 * A kept aspect ratio: the height follows the width, or the width follows a filled height.
	 */
	public function test_resolve_locked_ratio() {
		$page = array(
			'w'    => 800,
			'h'    => 600,
			'safe' => 20,
		);
		$el   = array(
			'type'   => 'svg',
			'lock'   => 2,
			'layout' => array(
				'ref' => 'page',
				'h'   => array(
					'mode'  => 'stretch',
					'start' => '0pt',
					'end'   => '0pt',
				),
				'v'   => array(
					'mode'  => 'center',
					'start' => '0pt',
					'size'  => '50pt',
				),
			),
		);
		$r    = Render\resolve( $el, $page );
		$this->assertEqualsWithDelta( 400, $r['h'], 0.01 );
		$this->assertEqualsWithDelta( 100, $r['y'], 0.01 );

		$el['layout']['h']   = array(
			'mode' => 'end',
			'end'  => '0pt',
			'size' => '10pt',
		);
		$el['layout']['v']   = array(
			'mode'  => 'stretch',
			'start' => '0pt',
			'end'   => '0pt',
		);
		$el['layout']['ref'] = 'safe';
		$r                   = Render\resolve( $el, $page );
		$this->assertEqualsWithDelta( 1120, $r['w'], 0.01 );
		$this->assertEqualsWithDelta( 780 - 1120, $r['x'], 0.01 );

		$image = array(
			'type'   => 'image',
			'ratio'  => '4/3',
			'layout' => $el['layout'],
		);
		$this->assertEqualsWithDelta( 560 * 4 / 3, Render\resolve( $image, $page )['w'], 0.01 );
	}

	public function test_rect_path() {
		$this->assertSame( 'M0 0L10 0L10 10L0 10Z', Render\rect_path( 0, 0, 10, 10 ) );
		// Round corners are cubic curves bulging toward the corner (dompdf draws SVG arcs wrong).
		$this->assertSame( 'M0 5C0 2.24 2.24 0 5 0L10 0L10 10L0 10Z', Render\rect_path( 0, 0, 10, 10, array( 50, 0, 0, 0 ) ) );
		$this->assertStringNotContainsString( 'A', Render\rect_path( 0, 0, 10, 10, array( 2, 2, 2, 2 ), 'scoop' ) );
	}

	/**
	 * Fields read the certificate's frozen values.
	 */
	public function test_fields() {
		$values = Render\values(
			array(
				'name'        => 'Ana Lima',
				'event_title' => 'WordCamp Test',
				'hours'       => '8.00',
				'role'        => 'attendee',
				'code'        => '2611-04K7-M29Q-XAP3',
				'issued_at'   => '2026-10-31 15:00:00',
			)
		);
		$this->assertSame( 'Ana Lima', Render\field_value( 'name', '', $values ) );
		$this->assertSame( '8 hours', Render\field_value( 'hours', '', $values ) );
		$this->assertSame( '8h', Render\field_value( 'hours', 'short', $values ) );
		$this->assertSame( 'Attendee', Render\field_value( 'role', '', $values ) );
		$this->assertSame( '2026-10-31', Render\field_value( 'issued_date', 'iso', $values ) );
		$this->assertSame(
			'<strong>WordCamp Test</strong> &amp; more',
			Render\runs_html(
				array(
					'runs' => array(
						array(
							'field' => 'event',
							'b'     => true,
						),
						array( 'text' => ' & more' ),
					),
				),
				$values
			)
		);
	}
}
