<?php
/**
 * With verification off: no code on the screens, no revoking or replacing; a correction edits the certificate.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Admin;
use EventsCertificate\Certificates;
use EventsCertificate\Environment;
use EventsCertificate\People;

/**
 * Verification off is the default; the base class turns it on for the other tests (decision 0028).
 */
class NoVerificationTest extends Test_Case {

	/**
	 * The event.
	 *
	 * @var int
	 */
	private $event;

	/**
	 * Verification off, admin screens loaded.
	 */
	protected function set_up() {
		parent::set_up();
		remove_filter( 'evcert_verification_enabled', '__return_true' );
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-list-table.php';
		require_once dirname( __DIR__, 2 ) . '/includes/events-admin.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-people-list-table.php';
		$this->event = Fixtures::event();
	}

	/**
	 * Back as the base class has it.
	 */
	protected function tear_down() {
		add_filter( 'evcert_verification_enabled', '__return_true' );
		parent::tear_down();
	}

	/**
	 * A certificate of a person.
	 *
	 * @param string $name Name.
	 * @return array<string, mixed>
	 */
	private function certificate( $name ) {
		$person = Fixtures::attendee_of_event( $this->event, $name, 'x@example.test' );
		People\set_status( array( (int) $person['id'] ), 'attended', $this->event );

		return People\issue( People\get( (int) $person['id'] ), 'attendee' );
	}

	/**
	 * A correction edits the certificate where it is: same record, same code, nothing replaced.
	 */
	public function test_a_correction_edits_in_place() {
		$old   = $this->certificate( 'Maria Antiga' );
		$count = $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Certificates\table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$new = Certificates\reissue(
			$old,
			array(
				'name'  => 'Maria Nova',
				'hours' => '3',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $new );
		$this->assertSame( (int) $old['id'], (int) $new['id'], 'The same record.' );
		$this->assertSame( $old['code'], $new['code'], 'The same code.' );
		$this->assertSame( 'Maria Nova', $new['name'] );
		$this->assertSame( '3', (string) (float) $new['hours'] );
		$this->assertSame( 'issued', $new['status'] );
		$this->assertSame( $count, $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Certificates\table() ), 'No new certificate.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'issued', Certificates\get( (int) $old['id'] )['status'] );
	}

	/**
	 * Without a name an edit is refused, as an issue would.
	 */
	public function test_an_edit_needs_a_name() {
		$this->assertInstanceOf( \WP_Error::class, Certificates\reissue( $this->certificate( 'Maria Sem' ), array( 'name' => '  ' ) ) );
	}

	/**
	 * The list has no code column, no checkboxes, no revoke; its action is Edit.
	 */
	public function test_the_certificates_list_has_no_code_and_no_revoke() {
		$cert  = $this->certificate( 'Maria Lista' );
		$table = new Admin\List_Table();
		$cols  = $table->get_columns();

		$this->assertArrayNotHasKey( 'code', $cols );
		$this->assertArrayNotHasKey( 'cb', $cols );
		$this->assertSame( array(), ( new \ReflectionMethod( $table, 'get_bulk_actions' ) )->invoke( $table ) );

		$name = ( new \ReflectionMethod( $table, 'column_name' ) );
		$name->setAccessible( true );
		$html = $name->invoke( $table, $cert );
		$this->assertStringNotContainsString( 'Revoke', $html );
		$this->assertStringContainsString( '>Edit<', $html );
		$this->assertStringNotContainsString( 'Correct and reissue', $html );
	}

	/**
	 * With verification on the list has them all (the base class's default).
	 */
	public function test_with_verification_on_the_list_has_code_and_revoke() {
		add_filter( 'evcert_verification_enabled', '__return_true' );
		$table = new Admin\List_Table();

		$this->assertArrayHasKey( 'code', $table->get_columns() );
		$this->assertArrayHasKey( 'cb', $table->get_columns() );
		$this->assertArrayHasKey( 'revoke', ( new \ReflectionMethod( $table, 'get_bulk_actions' ) )->invoke( $table ) );
		remove_filter( 'evcert_verification_enabled', '__return_true' );
	}

	/**
	 * The participants' certificates column shows a link, not the code.
	 */
	public function test_the_people_list_shows_no_code() {
		$cert   = $this->certificate( 'Maria Pessoas' );
		$person = People\get( (int) People\query( $this->event )['rows'][0]['id'] );
		$table  = new \EventsCertificate\EventsAdmin\People_List_Table( $this->event );
		$method = new \ReflectionMethod( $table, 'column_certificates' );
		$method->setAccessible( true );
		$html = $method->invoke( $table, $person );

		$this->assertStringNotContainsString( $cert['code'], wp_strip_all_tags( $html ), 'No code in the text.' );
		$this->assertStringContainsString( Certificates\url( $cert['code'] ), $html, 'A link to the certificate (its address holds the code, the text does not).' );
		$this->assertStringContainsString( 'View', $html );
	}

	/**
	 * The ability to revoke only exists with verification on.
	 */
	public function test_no_revoke_ability_without_verification() {
		$this->assertFalse( \EventsCertificate\Abilities\exists_in_mode( 'events-certificates/revoke-certificate' ) );
		add_filter( 'evcert_verification_enabled', '__return_true' );
		$this->assertTrue( \EventsCertificate\Abilities\exists_in_mode( 'events-certificates/revoke-certificate' ) );
		remove_filter( 'evcert_verification_enabled', '__return_true' );
	}

	/**
	 * The mode is off here.
	 */
	public function test_verification_is_off() {
		$this->assertFalse( Environment\verification_enabled() );
	}
}
