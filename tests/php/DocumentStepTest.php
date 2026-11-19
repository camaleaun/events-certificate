<?php
/**
 * The ticket step's identity document: the countries the site accepts, one or several, and a foreigner's passport.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Compat\CampTix\Choices;
use EventsCertificate\Days;

/**
 * No country on: no document asked. One: its document alone. Several: a select of them. A foreigner: a passport.
 */
class DocumentStepTest extends Test_Case {

	/**
	 * The filter that turns countries on.
	 *
	 * @var callable|null
	 */
	private $enabled;

	/**
	 * An attendee who was present on a day with a table to choose.
	 *
	 * @var \WP_Post
	 */
	private $attendee;

	/**
	 * A day and an attendee present on it.
	 */
	protected function set_up() {
		parent::set_up();
		Days\save(
			array(
				array(
					'date'   => '2026-11-14',
					'names'  => array( 'en_US' => 'Day one' ),
					'mode'   => 'table',
					'tables' => array( 'en_US' => array( 'Table 1' ) ),
				),
			)
		);
		$id = wp_insert_post(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
				'post_title'  => 'Ana',
			)
		);
		update_post_meta( $id, 'evcert_days', array( '2026-11-14' ) );
		$this->attendee = get_post( $id );
	}

	/**
	 * Takes the countries off and the posted data away.
	 */
	protected function tear_down() {
		if ( $this->enabled ) {
			remove_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $this->enabled, 2 );
		}
		$_POST = array();
		remove_filter( 'evcert_more_document_countries', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Chooses the country of the document (and whether a foreigner may say so); the declaration is on.
	 *
	 * @param string $country   Code ('' for none).
	 * @param bool   $foreigner Whether a foreigner may say so.
	 */
	private function enable( $country, $foreigner = false ) {
		$this->enabled = static function ( $settings ) use ( $country, $foreigner ) {
			return array_merge(
				(array) $settings,
				array(
					'declaration'        => true,
					'document_country'   => $country,
					'document_foreigner' => $foreigner,
				)
			);
		};
		add_filter( 'pre_option_' . \EventsCertificate\Settings\OPTION, $this->enabled, 2 );
	}

	/**
	 * What a participant posts for the second page.
	 *
	 * @param array<string, string> $extra More fields.
	 */
	private function post( array $extra = array() ) {
		$_POST = $extra + array(
			'evcert_days'  => array( '2026-11-14' ),
			'evcert_table' => array( '2026-11-14' => 'Table 1' ),
			'evcert_page2' => '1',
		);
	}

	/**
	 * The declaration page is off by default (it is a Brazilian custom): the ticket step has no second page and
	 * ignores one that is posted. The option, or the filter, turns it on.
	 */
	public function test_the_declaration_page_is_off_by_default() {
		$this->assertFalse( \EventsCertificate\Documents\declaration_enabled() );
		$this->assertFalse( \EventsCertificate\Settings\defaults()['declaration'] );
		$html = Choices\rows( $this->attendee, null );
		$this->assertStringNotContainsString( 'evcert_page2', $html );
		$this->assertStringNotContainsString( 'evcert_doc', $html );

		$this->post(
			array(
				'evcert_country' => 'BR',
				'evcert_doc'     => '529.982.247-25',
			)
		);
		$read = Choices\read( $this->attendee, null );
		$this->assertFalse( $read['data']['page2'], 'A posted second page is ignored.' );
		$this->assertSame( array(), $read['columns'] );

		add_filter( 'evcert_declaration_enabled', '__return_true' );
		$this->assertTrue( \EventsCertificate\Documents\declaration_enabled(), 'The filter turns it on.' );
		remove_filter( 'evcert_declaration_enabled', '__return_true' );
	}

	/**
	 * With the declaration on and no country, the second page asks for no document.
	 */
	public function test_no_country_no_document_rows() {
		$this->enable( '' );
		$html = Choices\rows( $this->attendee, null );

		$this->assertStringContainsString( 'evcert_page2', $html );
		$this->assertStringNotContainsString( 'evcert_doc', $html );
		$this->assertStringNotContainsString( 'evcert_country', $html );

		$this->post();
		$read = Choices\read( $this->attendee, null );
		$this->assertIsArray( $read );
		$this->assertSame( array(), $read['columns'], 'No document stored.' );
	}

	/**
	 * The country chosen: its document alone, with its mask; no select, no foreigner box unless the site allows it.
	 */
	public function test_the_chosen_country_asks_only_its_document() {
		$this->enable( 'BR' );
		$html = Choices\rows( $this->attendee, null );

		$this->assertStringNotContainsString( '<select id="evcert-country"', $html, 'One country: no select.' );
		$this->assertStringContainsString( 'name="evcert_country" value="BR"', $html );
		$this->assertStringContainsString( 'data-mask="000.000.000-00"', $html );
		$this->assertStringContainsString( '>CPF<', $html );
		$this->assertStringContainsString( 'placeholder="000.000.000-00"', $html );
		$this->assertStringNotContainsString( 'evcert_foreigner', $html, 'Not offered unless the site allows it.' );
	}

	/**
	 * Allowed by the site, a box "Not from the event's country" lets the attendee give a passport or nothing.
	 */
	public function test_the_foreigner_box_needs_the_setting() {
		$this->enable( 'BR', true );
		$html = Choices\rows( $this->attendee, null );

		$this->assertStringContainsString( 'name="evcert_foreigner"', $html );
		$this->assertStringContainsString( 'Not from the event', $html );
	}

	/**
	 * What is read: the document checked by its rule, and required.
	 */
	public function test_reading_the_document() {
		$this->enable( 'BR' );

		$this->post( array( 'evcert_doc' => '529.982.247-25' ) );
		$read = Choices\read( $this->attendee, null );
		$this->assertSame( 'cpf', $read['columns']['doc_type'] );
		$this->assertSame( 'BR', $read['data']['country'] );

		$this->post( array( 'evcert_doc' => '529.982.247-24' ) );
		$this->assertWPErrorCode( 'evcert_doc', Choices\read( $this->attendee, null ) );

		$this->post();
		$this->assertWPErrorCode( 'evcert_doc', Choices\read( $this->attendee, null ) );

		add_filter( 'evcert_more_document_countries', '__return_true' );
		$this->enable( 'PT' );
		$this->post( array( 'evcert_doc' => '123456789' ) );
		$this->assertSame( 'nif', Choices\read( $this->attendee, null )['columns']['doc_type'] );
	}

	/**
	 * A foreigner (when the site allows it) gives a passport, or nothing at all.
	 */
	public function test_a_foreigner_gives_a_passport_or_nothing() {
		$this->enable( 'BR', true );

		$this->post(
			array(
				'evcert_foreigner' => '1',
				'evcert_doc'       => 'ab-123456',
			)
		);
		$read = Choices\read( $this->attendee, null );
		$this->assertSame( 'passport', $read['columns']['doc_type'] );
		$this->assertSame( 'Passport', $read['columns']['doc_label'] );

		$this->post( array( 'evcert_foreigner' => '1' ) );
		$read = Choices\read( $this->attendee, null );
		$this->assertIsArray( $read, 'A foreigner may give nothing.' );
		$this->assertSame( array(), $read['columns'] );
		$this->assertTrue( $read['data']['foreigner'], 'It is kept that they said they are not from the country.' );

		$this->post(
			array(
				'evcert_foreigner' => '1',
				'evcert_doc'       => 'A1',
			)
		);
		$this->assertWPErrorCode( 'evcert_doc', Choices\read( $this->attendee, null ) );
	}

	/**
	 * A site that does not allow it ignores the box: the country's document is required.
	 */
	public function test_a_foreigner_is_ignored_when_not_allowed() {
		$this->enable( 'BR', false );

		$this->post( array( 'evcert_foreigner' => '1' ) );
		$this->assertWPErrorCode( 'evcert_doc', Choices\read( $this->attendee, null ) );
	}

	/**
	 * Asserts a WP_Error with a code.
	 *
	 * @param string $code  Code.
	 * @param mixed  $error Value.
	 */
	private function assertWPErrorCode( $code, $error ) {
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( $code, $error->get_error_code() );
	}
}
