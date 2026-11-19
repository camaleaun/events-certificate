<?php
/**
 * The generator page: one block markup, drawn by the page, the shortcode and the one-block version.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\QuickGenerator;

/**
 * The shortcode is the classic counterpart of the page: it renders the same blocks.
 */
class QuickGeneratorTest extends Test_Case {

	/**
	 * The hero's heading is the page's title, a Post Title block, only when asked.
	 */
	public function test_the_title_block_is_only_there_when_asked() {
		$this->assertStringContainsString( 'wp:post-title', QuickGenerator\page_content() );
		$this->assertStringNotContainsString( 'wp:post-title', QuickGenerator\page_content( array(), false ) );
		$this->assertStringNotContainsString( '<h1', QuickGenerator\page_content() );
	}

	/**
	 * The settings of the shortcode go to the blocks that have them, in their comments.
	 */
	public function test_settings_go_to_the_blocks() {
		$content = QuickGenerator\page_content(
			array(
				'community' => 'WP Curitiba',
				'city'      => 'Curitiba, PR',
				'signName'  => 'Ana Lima',
			)
		);

		$this->assertStringContainsString( '<!-- wp:events-certificates/event-info {"community":"WP Curitiba"} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:events-certificates/event-schedule {"city":"Curitiba, PR"} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:events-certificates/signature {"signName":"Ana Lima"} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:events-certificates/people-list /-->', $content );
	}

	/**
	 * The shortcode draws the whole app with its blocks, with the settings given.
	 */
	public function test_the_shortcode_renders_the_blocks() {
		$html = QuickGenerator\render_shortcode( array( 'community' => 'WP Curitiba' ) );

		$this->assertStringContainsString( 'evcert-app', $html );
		$this->assertStringContainsString( 'tut-step', $html );
		$this->assertStringContainsString( 'class="panel"', $html );
		$this->assertStringContainsString( 'WP Curitiba', $html );
		$this->assertStringNotContainsString( 'wp-block-post-title', $html, 'The theme prints the title of a page the shortcode is in.' );
	}

	/**
	 * A custom accent color goes on the app's wrapper.
	 */
	public function test_the_accent_color_goes_on_the_wrapper() {
		$html = QuickGenerator\render_shortcode( array( 'accent' => '#cc0000' ) );

		$this->assertMatchesRegularExpression( '/<div[^>]*class="[^"]*evcert-app[^"]*"[^>]*style="--accent:#cc0000"|<div[^>]*style="--accent:#cc0000"[^>]*class="[^"]*evcert-app/', $html );
	}

	/**
	 * The one-block version draws the same as the shortcode.
	 */
	public function test_the_one_block_version_is_the_same_render() {
		$block = QuickGenerator\render_quick_generator( array( 'community' => 'WP Curitiba' ) );

		$this->assertSame( QuickGenerator\render_shortcode( array( 'community' => 'WP Curitiba' ) ), $block );
	}

	/**
	 * With the Classic Editor the page's content is the shortcode, not blocks.
	 */
	public function test_pages_use_the_shortcode_without_the_block_editor() {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->assertTrue( QuickGenerator\blocks_for_page() );
		add_filter( 'use_block_editor_for_post_type', '__return_false' );
		$this->assertFalse( QuickGenerator\blocks_for_page() );
		remove_filter( 'use_block_editor_for_post_type', '__return_false' );
	}

	/**
	 * The page template is a block template of the plugin, for pages; the page's body class follows the template.
	 */
	public function test_the_page_template_is_registered() {
		// The hooks of init have run before the tests start, and a template registers once.
		QuickGenerator\register_template();
		$template = \WP_Block_Templates_Registry::get_instance()->get_registered( QuickGenerator\TEMPLATE_NAME );

		$this->assertNotNull( $template );
		$this->assertContains( 'page', (array) $template->post_types );
		$this->assertStringContainsString( 'wp:post-content', $template->content );
	}

	/**
	 * Around the app, WordPress does not put an inner container in the groups (classic themes size what is in it);
	 * the rest of the content keeps it.
	 */
	public function test_the_inner_container_is_left_out_only_around_the_app() {
		$restore = 'wp_restore_group_inner_container';

		QuickGenerator\drop_inner_container( '<div class="wp-block-group">Another content</div>' );
		$this->assertNotFalse( has_filter( 'render_block_core/group', $restore ) );

		QuickGenerator\drop_inner_container( '<!-- wp:group {"className":"evcert-app"} -->' );
		$this->assertFalse( has_filter( 'render_block_core/group', $restore ) );

		QuickGenerator\restore_inner_container( '' );
		$this->assertNotFalse( has_filter( 'render_block_core/group', $restore ) );

		// Removed by someone else on purpose: it is not put back.
		remove_filter( 'render_block_core/group', $restore, 10 );
		QuickGenerator\restore_inner_container( '' );
		$this->assertFalse( has_filter( 'render_block_core/group', $restore ) );
		add_filter( 'render_block_core/group', $restore, 10, 2 );
	}

	/**
	 * The public generator draws the site's default design (the one the server draws), with the site's formats.
	 */
	public function test_the_app_gets_the_default_design_and_the_sites_formats() {
		$config = QuickGenerator\config();

		$this->assertSame( \EventsCertificate\Designs\get( \EventsCertificate\Designs\site_design_id() ), $config['design'] );
		$this->assertNotEmpty( $config['design']['elements'] );
		$this->assertSame( \EventsCertificate\Render\phrases(), $config['phrases'] );
		$this->assertSame( (string) get_option( 'date_format' ), $config['dateFormat'] );
		$this->assertSame( (string) get_option( 'time_format' ), $config['timeFormat'] );
		$this->assertArrayHasKey( 'withSpan', $config['i18n'] );
	}

	/**
	 * The generator page is made only where no event plugin brings its own way to issue certificates, and the
	 * Certificates screen has no button to it.
	 */
	public function test_page_only_without_integrations_and_no_button() {
		$this->assertSame( 'none' === \EventsCertificate\Environment\integration(), QuickGenerator\wanted() );
		$this->assertFalse( has_action( 'evcert_certificates_title_actions', 'EventsCertificate\QuickGenerator\render_button' ) );
		$this->assertFalse( function_exists( 'EventsCertificate\QuickGenerator\render_button' ) );
	}
}
