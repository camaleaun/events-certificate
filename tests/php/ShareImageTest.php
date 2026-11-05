<?php
/**
 * Who may send a certificate's share image.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\ShareImage;

/**
 * Admins, or the attendee with the edit token of the certificate's source.
 */
class ShareImageTest extends Test_Case {

	/**
	 * Sets up a logged-out visitor.
	 */
	protected function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	/**
	 * The attendee's edit token opens it; anything else doesn't.
	 */
	public function test_can_send() {
		$attendee = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Attendee stand-in',
			)
		);
		update_post_meta( $attendee, 'tix_edit_token', 'abc123' );
		$cert = array(
			'source'     => 'camptix',
			'source_ref' => (string) $attendee,
		);

		$this->assertTrue( ShareImage\can_send( $cert, $attendee, 'abc123' ) );
		$this->assertFalse( ShareImage\can_send( $cert, $attendee, 'wrong1' ) );
		$this->assertFalse( ShareImage\can_send( $cert, $attendee + 1, 'abc123' ) );
		$this->assertFalse( ShareImage\can_send( $cert, $attendee, 'abc-123' ) );
		$this->assertFalse( ShareImage\can_send( array_merge( $cert, array( 'source' => 'manual' ) ), $attendee, 'abc123' ) );

		wp_set_current_user( 1 );
		$this->assertTrue( ShareImage\can_send( array_merge( $cert, array( 'source' => 'manual' ) ) ) );
	}

	/**
	 * Images live in uploads/evcert-share/, one per code, locale and look (design + signer).
	 */
	public function test_location() {
		$where = ShareImage\location( '2611-04K7-M29Q-XAP3', 'pt_BR' );
		$this->assertStringEndsWith( '/evcert-share/2611-04k7-m29q-xap3-pt_BR-' . ShareImage\look() . '.png', $where['path'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{10}$/', ShareImage\look() );
		$this->assertSame( '', ShareImage\url( '0000-0000-0000-0000', 'pt_BR' ) );
	}
}
