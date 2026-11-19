<?php
/**
 * Certificate templates: choice by role, duplicate, default, order.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Designs;
use EventsCertificate\Templates;

/**
 * Templates tests (they make templates and delete them after).
 */
class TemplatesTest extends Test_Case {

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

	/**
	 * A template can be made with the name typed in the form, from the Base design or from another template.
	 */
	public function test_duplicate_takes_a_name() {
		$base = Designs\duplicate( 0, 'Speakers' );
		$this->assertSame( 'Speakers', get_the_title( $base ) );
		$copy = Designs\duplicate( $base, 'Speakers, Portuguese' );
		$this->assertSame( 'Speakers, Portuguese', get_the_title( $copy ) );
		$this->assertSame( 'New template', get_the_title( Designs\duplicate( 0 ) ), 'No name: the default one.' );
	}

	/**
	 * The screen looks like Tags: a form to add on the left, the list on the right.
	 */
	public function test_screen_has_the_add_form_beside_the_list() {
		wp_set_current_user(
			(int) get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			)[0]
		);
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.ValidatedSanitizedInput -- a screen outside wp-admin needs these.
		$GLOBALS['hook_suffix'] = 'admin_page_evcert-templates';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		\set_current_screen( 'admin_page_evcert-templates' );
		$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wp-admin/admin.php?page=evcert-templates';
		// phpcs:enable
		ob_start();
		Templates\render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="col-container"', $html );
		$this->assertStringContainsString( 'id="col-left"', $html );
		$this->assertStringContainsString( 'id="col-right"', $html );
		$this->assertStringContainsString( 'id="addtemplate"', $html );
		$this->assertStringContainsString( 'name="template-name"', $html );
		$this->assertStringContainsString( 'name="template-from"', $html );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertLessThan( strpos( $html, 'id="col-right"' ), strpos( $html, 'id="col-left"' ), 'The form comes first, as in Tags.' );
	}
}
