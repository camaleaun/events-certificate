<?php
/**
 * "Get my certificate" by e-mail: the authentication modes, the numeric code, the one-use link and the signed session.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\MineAuth;
use EventsCertificate\Settings;

/**
 * Authentication tests.
 */
class MineAuthTest extends Test_Case {

	/**
	 * Mails the test "sent".
	 *
	 * @var array<int, array<string, string>>
	 */
	private $mails = array();

	/**
	 * Catches the e-mails.
	 */
	protected function set_up() {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	/**
	 * Takes back what the test added, so no mode or mail trap leaks into the next one.
	 */
	protected function tear_down() {
		remove_all_filters( 'pre_option_' . \EventsCertificate\Settings\OPTION, 2 );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'evcert_mine_email' );
		unset( $_COOKIE[ \EventsCertificate\MineAuth\COOKIE ] );
		parent::tear_down();
	}

	/**
	 * Mode through the filter, the way a test turns it on (the base case pins the defaults).
	 *
	 * @param string $mode  Mode.
	 * @param bool   $link  Link too.
	 */
	private function mode( $mode, $link = false ) {
		add_filter(
			'pre_option_' . Settings\OPTION,
			function () use ( $mode, $link ) {
				return array(
					'email_auth'      => $mode,
					'email_auth_link' => $link,
				) + Settings\defaults();
			},
			2
		);
	}

	/**
	 * The default is the simple mode, and unknown values fall back to it.
	 */
	public function test_default_mode_is_simple() {
		$this->assertSame( 'simple', Settings\defaults()['email_auth'] );
		$this->assertFalse( Settings\defaults()['email_auth_link'] );
		$this->assertSame( 'simple', Settings\sanitize( array( 'email_auth' => 'bogus' ) )['email_auth'] );
		foreach ( array( 'none', 'simple', 'digits4', 'digits6', 'link' ) as $mode ) {
			$this->assertSame( $mode, Settings\sanitize( array( 'email_auth' => $mode ) )['email_auth'] );
		}
	}

	/**
	 * A numeric code of the mode's length is mailed, and only its hash is kept.
	 */
	public function test_code_is_numeric_mailed_and_kept_hashed() {
		foreach ( array(
			'digits4' => 4,
			'digits6' => 6,
		) as $mode => $length ) {
			$this->mails = array();
			$this->mode( $mode );
			$sent = MineAuth\start( 'ana@example.com' );
			$this->assertTrue( $sent );
			$this->assertCount( 1, $this->mails );
			$this->assertSame( 'ana@example.com', $this->mails[0]['to'] );
			$this->assertSame( 1, preg_match( '/\b(\d{' . $length . '})\b/', $this->mails[0]['message'], $m ), $mode );
			$stored = wp_json_encode( get_transient( MineAuth\transient_key( 'code', 'ana@example.com' ) ) );
			$this->assertStringNotContainsString( $m[1], $stored, 'The code itself is not stored.' );
			$this->assertTrue( MineAuth\verify( 'ana@example.com', $m[1] ) );
			$this->assertFalse( MineAuth\verify( 'ana@example.com', $m[1] ), 'A code works once.' );
			remove_all_filters( 'pre_option_' . Settings\OPTION, 2 );
		}
	}

	/**
	 * Five wrong tries burn the code, even the right one afterwards.
	 */
	public function test_five_wrong_tries_burn_the_code() {
		$this->mode( 'digits6' );
		MineAuth\start( 'ana@example.com' );
		preg_match( '/\b(\d{6})\b/', $this->mails[0]['message'], $m );
		$wrong = '000000' === $m[1] ? '111111' : '000000';
		for ( $i = 0; $i < 5; ++$i ) {
			$this->assertFalse( MineAuth\verify( 'ana@example.com', $wrong ) );
		}
		$this->assertFalse( MineAuth\verify( 'ana@example.com', $m[1] ) );
	}

	/**
	 * The code expires after 15 minutes.
	 */
	public function test_code_expires() {
		$this->mode( 'digits4' );
		MineAuth\start( 'ana@example.com' );
		preg_match( '/\b(\d{4})\b/', $this->mails[0]['message'], $m );
		$key               = MineAuth\transient_key( 'code', 'ana@example.com' );
		$record            = get_transient( $key );
		$record['expires'] = time() - 1;
		set_transient( $key, $record, HOUR_IN_SECONDS );
		$this->assertFalse( MineAuth\verify( 'ana@example.com', $m[1] ) );
	}

	/**
	 * Digit modes can send the link too; the link mode always does, and a link works once for an hour.
	 */
	public function test_link_is_one_use() {
		$this->mode( 'link' );
		MineAuth\start( 'ana@example.com' );
		$this->assertSame( 1, preg_match( '~evcert_mine_token=([A-Za-z0-9]{32})~', $this->mails[0]['message'], $m ), 'The e-mail carries the link.' );
		$this->assertDoesNotMatchRegularExpression( '/\b\d{6}\b/', preg_replace( '~https?://\S+~', '', $this->mails[0]['message'] ), 'No code in link mode.' );
		$this->assertSame( 'ana@example.com', MineAuth\redeem( $m[1] ) );
		$this->assertSame( '', MineAuth\redeem( $m[1] ), 'A link works once.' );
		$this->assertSame( '', MineAuth\redeem( 'nope' ) );

		$this->mails = array();
		$this->mode( 'digits6', true );
		remove_all_filters( 'pre_option_' . Settings\OPTION, 2 );
		$this->mode( 'digits6', true );
		MineAuth\start( 'ana@example.com' );
		$this->assertMatchesRegularExpression( '~evcert_mine_token=~', $this->mails[0]['message'] );
		$this->assertMatchesRegularExpression( '/\b\d{6}\b/', $this->mails[0]['message'] );
	}

	/**
	 * The e-mail text can be changed by a filter.
	 */
	public function test_mail_is_filterable() {
		$this->mode( 'digits4' );
		add_filter(
			'evcert_mine_email',
			function ( $mail ) {
				$mail['subject'] = 'Custom subject';
				return $mail;
			}
		);
		MineAuth\start( 'ana@example.com' );
		$this->assertSame( 'Custom subject', $this->mails[0]['subject'] );
	}

	/**
	 * The session is a signed value: it names the e-mail, expires, and any change breaks it.
	 */
	public function test_session_is_signed() {
		$value = MineAuth\session_value( 'ana@example.com', time() + 600 );
		$this->assertSame( 'ana@example.com', MineAuth\read_session( $value ) );
		$this->assertSame( '', MineAuth\read_session( $value . 'x' ) );
		$this->assertSame( '', MineAuth\read_session( 'Ym9i' . substr( $value, strpos( $value, '.' ) ) ), 'Another name with the same signature.' );
		$this->assertSame( '', MineAuth\read_session( MineAuth\session_value( 'ana@example.com', time() - 1 ) ), 'Expired.' );
		$this->assertSame( '', MineAuth\read_session( '' ) );
	}

	/**
	 * Asking for codes is limited per e-mail and address.
	 */
	public function test_requests_are_throttled() {
		$this->mode( 'digits4' );
		$sent = 0;
		for ( $i = 0; $i < 8; ++$i ) {
			if ( MineAuth\start( 'ana@example.com' ) ) {
				++$sent;
			}
		}
		$this->assertSame( 5, $sent );
	}

	/**
	 * Settings has the select with the five modes (Simple chosen) and the checkbox to send the link too.
	 */
	public function test_settings_field() {
		ob_start();
		\EventsCertificate\Admin\render_email_auth_field();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="evcert_settings[email_auth]"', $html );
		foreach ( MineAuth\modes() as $mode ) {
			$this->assertStringContainsString( 'value="' . $mode . '"', $html );
		}
		$this->assertMatchesRegularExpression( '/<option value="simple"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'name="evcert_settings[email_auth_link]"', $html );
	}
}
