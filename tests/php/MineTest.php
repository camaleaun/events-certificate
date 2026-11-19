<?php
/**
 * "Get my certificate": who can get one (the e-mail is on record and was marked present), the block, the shortcode and the page.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Mine;

/**
 * Block, page and lookup tests.
 */
class MineTest extends Test_Case {

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
	 * An e-mail the site knows nothing about has no match.
	 */
	public function test_unknown_email_is_not_found() {
		$result = Mine\lookup( 'nobody@example.com' );
		$this->assertSame( 'unknown', $result['state'] );
		$this->assertSame( array(), $result['matches'] );
	}

	/**
	 * Someone on record but not marked present is told so, and gets nothing.
	 */
	public function test_not_present_is_told() {
		$this->requires_integration( 'none', 'gatherpress' );
		$event = Fixtures::event();
		\EventsCertificate\People\import(
			$event,
			array(
				array(
					'name'  => 'Ana Ausente',
					'email' => 'ana.ausente@example.com',
				),
			)
		);
		$result = Mine\lookup( 'ana.ausente@example.com' );
		$this->assertSame( 'not_attended', $result['state'] );
		$this->assertSame( array(), $result['matches'] );
	}

	/**
	 * Someone marked present matches, with the event and the role, ignoring the e-mail's case.
	 */
	public function test_present_matches() {
		$this->requires_integration( 'none', 'gatherpress' );
		$event = Fixtures::event();
		Fixtures::attendee_of_event( $event, 'Bia Presente', 'bia.presente@example.com' );
		$result = Mine\lookup( ' Bia.Presente@Example.com ' );
		$this->assertSame( 'ok', $result['state'] );
		$this->assertCount( 1, $result['matches'] );
		$this->assertSame( 'Bia Presente', $result['matches'][0]['name'] );
		$this->assertSame( 'Meetup Lifecycle', $result['matches'][0]['event'] );
	}

	/**
	 * With CampTix, a ticket marked present matches and links to the certificate step; a ticket not marked doesn't.
	 */
	public function test_camptix_tickets() {
		$this->requires_integration( 'camptix', 'both' );
		$here = Fixtures::camptix_attendee( array( 'email' => 'cleo.camptix@example.com' ) );
		$this->assertSame( 'ok', Mine\lookup( 'cleo.camptix@example.com' )['state'] );
		$this->assertStringContainsString( 'tix_attendee_id=' . $here->ID, Mine\lookup( 'cleo.camptix@example.com' )['matches'][0]['url'] );

		$absent = Fixtures::camptix_attendee( array( 'email' => 'davi.camptix@example.com' ) );
		delete_post_meta( $absent->ID, 'tix_attended' );
		$this->assertSame( 'not_attended', Mine\lookup( 'davi.camptix@example.com' )['state'] );
	}

	/**
	 * The shortcode and the block draw the same form: an e-mail field and the Generate button, in CampTix's markup.
	 */
	public function test_shortcode_and_block_draw_the_form() {
		if ( ! shortcode_exists( 'evcert_mine' ) ) {
			Mine\register();
		}
		$html = do_shortcode( '[evcert_mine title="Meu certificado"]' );
		$this->assertStringContainsString( 'evcert-mine-form', $html );
		$this->assertStringContainsString( '>Meu certificado<', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'name="evcert_mine_email"', $html );
		$this->assertStringContainsString( 'value="Generate"', $html );
		$this->assertStringContainsString( 'id="tix"', $html );
		$this->assertStringContainsString( 'tix_tickets_table', $html );
		$this->assertStringContainsString( 'tix-submit', $html );
		$this->assertStringContainsString( '_wpnonce', $html, 'The form carries a nonce.' );

		$block = render_block(
			array(
				'blockName'    => Mine\BLOCK,
				'attrs'        => array( 'title' => 'Meu certificado' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertSame( preg_replace( '/_wpnonce" value="[^"]+"/', '', $html ), preg_replace( '/_wpnonce" value="[^"]+"/', '', $block ) );
	}

	/**
	 * The page is made once at /certificates/ (the slug is translatable), and renaming it is allowed.
	 */
	public function test_page_is_made_once() {
		wp_set_current_user(
			(int) get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			)[0]
		);
		delete_option( Mine\OPTION );
		Mine\ensure_page();
		$id = (int) get_option( Mine\OPTION );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'certificates', get_post_field( 'post_name', $id ) );
		$this->assertStringContainsString( '<!-- wp:' . Mine\BLOCK, get_post_field( 'post_content', $id ) );
		wp_delete_post( $id, true );
		Mine\ensure_page();
		$this->assertSame( $id, (int) get_option( Mine\OPTION ), 'A deleted page is not made again.' );
	}

	/**
	 * Posts the form, as a visitor would, and draws the page.
	 *
	 * @param array<string, string> $fields Fields (the action, e-mail, code).
	 * @return string The form's HTML.
	 */
	private function post( array $fields ) {
		global $evcert_mine_outcome;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $fields + array( '_wpnonce' => wp_create_nonce( 'evcert_mine' ) );
		$evcert_mine_outcome       = array();
		Mine\handle();
		$html                      = Mine\form( array() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();

		return $html;
	}

	/**
	 * Settings for a flow test.
	 *
	 * @param string $mode Mode.
	 */
	private function mode( $mode ) {
		add_filter(
			'pre_option_' . \EventsCertificate\Settings\OPTION,
			function () use ( $mode ) {
				return array( 'email_auth' => $mode ) + \EventsCertificate\Settings\defaults();
			},
			2
		);
		add_filter( 'pre_wp_mail', '__return_true' );
		unset( $_COOKIE[ \EventsCertificate\MineAuth\COOKIE ] );
	}

	/**
	 * Simple mode: the e-mail shows the simplified view (no certificate link) and offers to authenticate.
	 */
	public function test_simple_mode_shows_the_simplified_view() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'simple' );
		Fixtures::attendee_of_event( Fixtures::event(), 'Eva Simples', 'eva.simples@example.com' );
		$html = $this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'eva.simples@example.com',
			)
		);
		$this->assertStringContainsString( 'Eva Simples', $html );
		$this->assertStringContainsString( 'Meetup Lifecycle', $html );
		$this->assertStringNotContainsString( Mine\GET_ARG . '=', $html, 'No certificate link before authenticating.' );
		$this->assertStringContainsString( 'value="Authenticate"', $html );
	}

	/**
	 * Simple mode tells an unknown e-mail, and someone not marked present, apart.
	 */
	public function test_simple_mode_messages() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'simple' );
		\EventsCertificate\People\import(
			Fixtures::event(),
			array(
				array(
					'name'  => 'Ivo Ausente',
					'email' => 'ivo@example.com',
				),
			)
		);
		$this->assertStringContainsString(
			'couldn’t find your e-mail',
			$this->post(
				array(
					Mine\ACTION      => 'lookup',
					Mine\EMAIL_FIELD => 'ninguem@example.com',
				)
			)
		);
		$this->assertStringContainsString(
			'not marked as present',
			$this->post(
				array(
					Mine\ACTION      => 'lookup',
					Mine\EMAIL_FIELD => 'ivo@example.com',
				)
			)
		);
		$this->assertStringContainsString(
			'Invalid email address!',
			$this->post(
				array(
					Mine\ACTION      => 'lookup',
					Mine\EMAIL_FIELD => 'not-an-email',
				)
			)
		);
		$this->assertStringContainsString(
			'Missing email address!',
			$this->post(
				array(
					Mine\ACTION      => 'lookup',
					Mine\EMAIL_FIELD => '',
				)
			)
		);
	}

	/**
	 * A mode that mails never says whether the e-mail is on the list: the same answer either way.
	 */
	public function test_mailing_modes_do_not_leak_the_list() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'digits6' );
		Fixtures::attendee_of_event( Fixtures::event(), 'Gil Lista', 'gil.lista@example.com' );
		$known   = $this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'gil.lista@example.com',
			)
		);
		$unknown = $this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'ninguem@example.com',
			)
		);
		$this->assertStringContainsString( 'we have just sent you', $known );
		$this->assertStringContainsString( 'we have just sent you', $unknown );
		$this->assertStringContainsString( 'evcert-mine-code', $unknown, 'The code field shows either way.' );
	}

	/**
	 * A right code lets the person in: the list gets the certificate link; a wrong one does not.
	 */
	public function test_code_lets_the_person_in() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'digits4' );
		$mail = '';
		add_filter(
			'evcert_mine_email',
			function ( $m ) use ( &$mail ) {
				$mail = $m['message'];
				return $m;
			}
		);
		Fixtures::attendee_of_event( Fixtures::event(), 'Ana Codigo', 'ana.codigo@example.com' );
		$this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'ana.codigo@example.com',
			)
		);
		preg_match( '/\b(\d{4})\b/', $mail, $m );
		$wrong = '0000' === $m[1] ? '1111' : '0000';
		$bad   = $this->post(
			array(
				Mine\ACTION      => 'verify',
				Mine\EMAIL_FIELD => 'ana.codigo@example.com',
				Mine\CODE_FIELD  => $wrong,
			)
		);
		$this->assertStringContainsString( 'not valid', $bad );
		$this->assertStringNotContainsString( Mine\GET_ARG . '=', $bad );

		$ok = $this->post(
			array(
				Mine\ACTION      => 'verify',
				Mine\EMAIL_FIELD => 'ana.codigo@example.com',
				Mine\CODE_FIELD  => $m[1],
			)
		);
		$this->assertStringContainsString( 'Ana Codigo', $ok );
		$this->assertStringContainsString( Mine\GET_ARG . '=', $ok );
		$this->assertStringContainsString( 'Get certificate', $ok );
	}

	/**
	 * With no authentication the e-mail alone opens the certificates.
	 */
	public function test_no_authentication_opens_at_once() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'none' );
		Fixtures::attendee_of_event( Fixtures::event(), 'Teo Livre', 'teo.livre@example.com' );
		$html = $this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'teo.livre@example.com',
			)
		);
		$this->assertStringContainsString( Mine\GET_ARG . '=', $html );
	}

	/**
	 * A form posted without a valid nonce is refused.
	 */
	public function test_form_needs_its_nonce() {
		$this->mode( 'none' );
		$html = $this->post(
			array(
				Mine\ACTION      => 'lookup',
				Mine\EMAIL_FIELD => 'x@example.com',
				'_wpnonce'       => 'bad',
			)
		);
		$this->assertStringContainsString( 'expired', $html );
	}

	/**
	 * In Portuguese the page lives at /certificados/ and the button says "Gerar" (when the translation is installed).
	 */
	public function test_portuguese() {
		switch_to_locale( 'pt_BR' );
		if ( 'Generate' === __( 'Generate', 'events-certificates' ) ) {
			restore_previous_locale();
			$this->markTestSkipped( 'The pt_BR translation is not installed here.' );
		}
		$this->assertSame( 'certificados', Mine\slug() );
		$this->assertStringContainsString( 'value="Gerar"', Mine\form( array() ) );
		restore_previous_locale();
	}

	/**
	 * The simplified certificate: the artwork with name, event and role, no code or hours, no PDF, share or details, and an Authenticate tool in the Dock.
	 */
	public function test_simplified_view_has_authenticate_in_the_dock() {
		$this->requires_integration( 'none', 'gatherpress' );
		$this->mode( 'simple' );
		$event  = Fixtures::event( array( 'location' => 'Recife, Brasil' ) );
		$person = Fixtures::attendee_of_event( $event, 'Lia Simplificada', 'lia.simples@example.com' );
		$ref    = $person['id'] . ':' . $person['role'];

		$cert = Mine\simple_certificate( $ref );
		$this->assertSame( 'Lia Simplificada', $cert['name'] );
		$this->assertSame( '', $cert['code'] );
		$this->assertSame( '', (string) $cert['hours'], 'No hours in the simplified view.' );

		ob_start();
		\EventsCertificate\Verify\render_page( $cert, '', 'en_US', false, true );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Lia Simplificada', $html );
		$this->assertStringContainsString( 'data-evcert-popover="auth"', $html );
		$this->assertStringContainsString( 'Authenticate', $html );
		$this->assertStringContainsString( 'value="' . Mine\ACTION . '"', str_replace( 'name="' . Mine\ACTION . '" value="send"', 'value="' . Mine\ACTION . '"', $html ) );
		$this->assertStringNotContainsString( 'data-evcert-popover="share"', $html );
		$this->assertStringNotContainsString( 'data-evcert-popover="details"', $html );
		$this->assertStringNotContainsString( '>PDF<', $html );
	}

	/**
	 * Someone else's reference, or no nonce, opens nothing.
	 */
	public function test_simplified_view_needs_a_matching_person() {
		$this->assertSame( array(), Mine\simple_certificate( '999999:attendee' ) );
		$this->assertSame( array(), Mine\simple_certificate( 'nonsense' ) );
	}

	/**
	 * A link to the simplified view carries a key only the list can give: a sequential person ID alone opens nothing.
	 */
	public function test_simplified_link_needs_its_key() {
		$this->requires_integration( 'none', 'gatherpress' );
		$person = Fixtures::attendee_of_event( Fixtures::event(), 'Noa Chave', 'noa.chave@example.com' );
		$ref    = $person['id'] . ':' . $person['role'];
		$found  = array( 'ref' => $ref );
		$url    = Mine\view_url( $found );
		$this->assertStringContainsString( 'k=' . Mine\view_key( $ref ), $url );
		$this->assertNotSame( Mine\view_key( $ref ), Mine\view_key( '999:attendee' ) );
		$this->assertSame( 24, strlen( Mine\view_key( $ref ) ) );
	}
}
