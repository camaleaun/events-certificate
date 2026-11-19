<?php
/**
 * The generator's colors as theme.json presets, only where the generator page
 * is: its tokens (accent, ink, line…) show in the Styles and block color
 * pickers of that page in the editor and can be recolored there, and the app's
 * stylesheet reads them (light mode; the dark mode keeps its own colors). The
 * defaults are the app's own, so an untouched site looks the same. In the
 * editor of that page the canvas has the app's background and text color too
 * (light and dark), and the editor's own title field is left out: the page's
 * title is the heading of the app (a Post Title block in its content).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * App token => name and default color (the light values in assets/generator.css).
 */
const PALETTE = array(
	'evcert-accent'      => array( 'Certificate accent', '#3858e9', '--accent' ),
	'evcert-accent-soft' => array( 'Certificate accent soft', '#e8ecfd', '--accent-soft' ),
	'evcert-surface'     => array( 'Certificate paper', '#ffffff', '--surface' ),
	'evcert-ink'         => array( 'Certificate text', '#1b1f2e', '--ink' ),
	'evcert-muted'       => array( 'Certificate muted text', '#5d6478', '--muted' ),
	'evcert-line'        => array( 'Certificate line', '#dde0e8', '--line' ),
	'evcert-bg'          => array( 'Certificate background', '#f4f5f8', '--bg' ),
);

/**
 * The app's background and text color in dark mode (the dark values in assets/scss/quick-generator/_tokens.scss).
 */
const DARK = array(
	'background' => '#12141b',
	'text'       => '#e8eaf2',
);

/**
 * The app's background and text color in light mode (the light values in assets/scss/quick-generator/_tokens.scss).
 */
const LIGHT = array(
	'background' => '#f4f5f8',
	'text'       => '#1b1f2e',
);

/**
 * Hooks.
 */
function bootstrap() {
	add_filter( 'wp_theme_json_data_theme', __NAMESPACE__ . '\add_palette' );
	add_action( 'enqueue_block_assets', __NAMESPACE__ . '\enqueue' );
	// The canvas of the editor, with any theme: a block theme's theme.json styles don't reach a classic theme's editor, the editor's settings do.
	add_filter( 'block_editor_settings_all', __NAMESPACE__ . '\editor_settings', 10, 2 );
}

/**
 * Is this the generator page, on the site or being edited?
 *
 * @return bool
 */
function on_generator_page() {
	$id = (int) get_option( 'evcert_quick_generator_page_id' );
	if ( ! $id ) {
		return false;
	}
	if ( is_admin() ) {
		// Which post the editor opened: a read of the address, nothing is changed.
		return isset( $_GET['post'] ) && absint( wp_unslash( $_GET['post'] ) ) === $id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	return did_action( 'wp' ) && is_page( $id );
}

/**
 * Appends the generator's palette to the theme's colors, keeping the theme's
 * own entries, on the generator page.
 *
 * @param \WP_Theme_JSON_Data $theme_json Theme JSON data.
 * @return \WP_Theme_JSON_Data
 */
function add_palette( $theme_json ) {
	if ( ! on_generator_page() ) {
		return $theme_json;
	}
	$data    = $theme_json->get_data();
	$palette = $data['settings']['color']['palette'] ?? array();
	$palette = isset( $palette['theme'] ) ? $palette['theme'] : $palette;
	$palette = array_values(
		array_filter(
			$palette,
			static function ( $color ) {
				return ! isset( PALETTE[ $color['slug'] ?? '' ] );
			}
		)
	);
	foreach ( PALETTE as $slug => $token ) {
		$palette[] = array(
			'slug'  => $slug,
			'name'  => $token[0],
			'color' => $token[1],
		);
	}

	return $theme_json->update_with(
		array(
			'version'  => 3,
			'settings' => array( 'color' => array( 'palette' => $palette ) ),
		)
	);
}

/**
 * The canvas of the editor: the app's colors (the dark ones when the system is dark), and no title
 * field of the editor's own: the title is a block of the content.
 *
 * @return string
 */
function canvas_css() {
	return 'html body{background-color:' . LIGHT['background'] . ';color:' . LIGHT['text'] . '}'
		. '.editor-visual-editor__post-title-wrapper,.edit-post-visual-editor__post-title-wrapper{display:none}'
		. '@media (prefers-color-scheme: dark){html body{background-color:' . DARK['background'] . ';color:' . DARK['text'] . '}}';
}

/**
 * Adds the canvas styles to the editor of the generator page.
 *
 * @param array<string, mixed>     $settings Editor settings.
 * @param \WP_Block_Editor_Context $context  Editor context.
 * @return array<string, mixed>
 */
function editor_settings( $settings, $context ) {
	$id = (int) get_option( 'evcert_quick_generator_page_id' );
	if ( $id && ! empty( $context->post ) && (int) $context->post->ID === $id ) {
		$settings['styles']   = $settings['styles'] ?? array();
		$settings['styles'][] = array(
			'css'            => canvas_css(),
			'__unstableType' => 'plugin',
		);
	}

	return $settings;
}

/**
 * The CSS that binds the app's tokens to the presets (light mode).
 *
 * @return string
 */
function override_css() {
	$css = '';
	foreach ( PALETTE as $slug => $token ) {
		$css .= $token[2] . ':var(--wp--preset--color--' . $slug . ',' . $token[1] . ');';
	}

	return '@media (prefers-color-scheme: light){.evcert-app{' . $css . '}}';
}

/**
 * Loads the generator's stylesheet in the editor of its page (the front end
 * loads it with the blocks), with the token binding.
 */
function enqueue() {
	if ( ! on_generator_page() ) {
		return;
	}
	wp_enqueue_style( 'evcert-quick-generator' );
	if ( is_admin() ) {
		wp_enqueue_style( 'evcert-generator-sheet' );
	}
	wp_add_inline_style( 'evcert-quick-generator', override_css() );
}
