<?php
/**
 * The signer's signature is read into restricted fields.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Signer;

/**
 * Signature SVG and roles.
 */
class SignerTest extends Test_Case {

	/**
	 * Filled paths are kept, with fills from styles; outlines, DTDs and other shapes are not.
	 */
	public function test_parse_svg() {
		$svg = Signer\parse_svg( '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 44" fill="none"><g><path style="fill:#1a1919" d="M0 0L10 10Z"/><path fill="none" stroke="#000" d="M0 0L5 5"/><circle r="4"/><path d="javascript:alert(1)"/></g></svg>' );
		$this->assertSame( 120.0, $svg['vw'] );
		$this->assertSame( 44.0, $svg['vh'] );
		$this->assertSame(
			array(
				array(
					'd'    => 'M0 0L10 10Z',
					'fill' => '#1a1919',
				),
			),
			$svg['paths']
		);
		$this->assertNull( Signer\parse_svg( '<!DOCTYPE svg [<!ENTITY a "b">]><svg><path d="M0 0Z"/></svg>' ) );
		$this->assertNull( Signer\parse_svg( '<svg><path fill="none" d="M0 0L1 1"/></svg>' ) );
		$this->assertNull( Signer\parse_svg( 'not svg' ) );
	}

	/**
	 * The signature is in the organizers' REST API (a schema with one type), so the block editor saves it.
	 */
	public function test_signature_in_rest() {
		if ( ! post_type_exists( Signer\ORGANIZER ) ) {
			$this->markTestSkipped( 'wc-post-types is not active.' );
		}
		$fields = ( new \WP_REST_Post_Meta_Fields( Signer\ORGANIZER ) )->get_field_schema()['properties'];
		$this->assertArrayHasKey( Signer\SIGNATURE_META, $fields );
		$this->assertSame( 'object', $fields[ Signer\SIGNATURE_META ]['type'] );
	}

	/**
	 * A role falls back to the site language, then to any.
	 */
	public function test_role_in() {
		$roles = array(
			'en_US' => 'Event Coordinator',
			'pt_BR' => 'Coordenação do evento',
		);
		$this->assertSame( 'Coordenação do evento', Signer\role_in( $roles, 'pt_BR' ) );
		$this->assertSame( 'Event Coordinator', Signer\role_in( $roles, 'en_US' ) );
		$this->assertSame( 'Event Coordinator', Signer\role_in( array( 'en_US' => 'Event Coordinator' ), 'es_ES' ) );
		$this->assertSame( '', Signer\role_in( array(), 'pt_BR' ) );
	}
}
