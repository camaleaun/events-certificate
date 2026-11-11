<?php
/**
 * Certificate templates: choice by role, duplicate, default, order.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Designs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Templates tests (they make templates and delete them after).
 */
class TemplatesTest extends TestCase {

	/**
	 * Templates made by a test.
	 *
	 * @var int[]
	 */
	private $made = array();

	/**
	 * Default template before the test.
	 *
	 * @var int
	 */
	private $default = 0;

	/**
	 * Remembers the default template.
	 */
	protected function set_up() {
		parent::set_up();
		wp_set_current_user( 1 );
		$this->default = Designs\site_design_id();
	}

	/**
	 * Deletes the templates made and restores the default.
	 */
	protected function tear_down() {
		update_option( Designs\SITE_DESIGN_OPTION, $this->default, false );
		foreach ( $this->made as $id ) {
			wp_delete_post( $id, true );
		}
		parent::tear_down();
	}

	/**
	 * A copy of the default for some roles.
	 *
	 * @param string[] $roles Roles.
	 * @return int
	 */
	private function make( array $roles ) {
		$id = Designs\duplicate( $this->default );
		update_post_meta( $id, Designs\ROLES_META, $roles );
		$this->made[] = $id;

		return $id;
	}

	/**
	 * A certificate gets the first template for its role, else the default.
	 */
	public function test_choice_by_role() {
		$volunteers = $this->make( array( 'volunteer' ) );
		$this->assertSame( $volunteers, Designs\for_certificate( array( 'role' => 'volunteer' ) ) );
		$this->assertSame( $this->default, Designs\for_certificate( array( 'role' => 'attendee' ) ) );
		$this->assertSame( $this->default, Designs\for_certificate( array() ) );

		// Two for the same role: the first in the list wins; moving changes it.
		$second = $this->make( array( 'volunteer', 'speaker' ) );
		$this->assertSame( $volunteers, Designs\for_certificate( array( 'role' => 'volunteer' ) ) );
		$this->assertSame( $second, Designs\for_certificate( array( 'role' => 'speaker' ) ) );
		Designs\move( $second, 'up' );
		$this->assertSame( $second, Designs\for_certificate( array( 'role' => 'volunteer' ) ) );
	}

	/**
	 * Duplicating copies the content and the roles; unknown roles are dropped.
	 */
	public function test_duplicate_and_roles() {
		$source = $this->make( array( 'sponsor', 'nobody' ) );
		$this->assertSame( array( 'sponsor' ), Designs\roles( $source ) );
		$copy         = Designs\duplicate( $source );
		$this->made[] = $copy;
		$this->assertSame( array( 'sponsor' ), Designs\roles( $copy ) );
		$this->assertSame( get_post_field( 'post_content', $source ), get_post_field( 'post_content', $copy ) );
		$this->assertStringContainsString( get_the_title( $source ), get_the_title( $copy ) );
	}

	/**
	 * The default template applies to everyone else and keeps no roles.
	 */
	public function test_set_default() {
		$id = $this->make( array( 'organizer' ) );
		$this->assertTrue( Designs\set_default( $id ) );
		$this->assertSame( $id, Designs\site_design_id() );
		$this->assertSame( array(), Designs\roles( $id ) );
		$this->assertSame( $id, Designs\for_certificate( array( 'role' => 'attendee' ) ) );
	}
}
