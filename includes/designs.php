<?php
/**
 * Designs: the certificate artwork, one post per design (post type
 * evcert_design) holding the elements as JSON in post_content, so core gives
 * revisions, permissions and edit locking. The editor saves through the REST
 * API (/wp/v2/evcert-designs).
 *
 * The site's design is the first one; the "Base" starting point lives in
 * templates/base-design.json, shared by the editor (bundled at build time)
 * and the PHP renderer.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Post type.
 */
const POST_TYPE = 'evcert_design';

/**
 * Option holding the site design's post ID.
 */
const SITE_DESIGN_OPTION = 'evcert_site_design';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register_post_type' );
}

/**
 * Registers the post type. Not public: designs are only reached through the
 * editor and drawn on certificates.
 */
function register_post_type() {
	\register_post_type(
		POST_TYPE,
		array(
			'labels'          => array(
				'name'          => __( 'Certificate designs', 'events-certificate' ),
				'singular_name' => __( 'Certificate design', 'events-certificate' ),
			),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => true,
			'rest_base'       => 'evcert-designs',
			'supports'        => array( 'title', 'editor', 'revisions' ),
			'capability_type' => 'evcert_design',
			'map_meta_cap'    => false,
			'capabilities'    => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
				'delete_posts'       => 'manage_options',
				'create_posts'       => 'manage_options',
			),
		)
	);
}

/**
 * The Base design: the starting point of every new design.
 *
 * @return array<string, mixed>
 */
function base() {
	$json   = file_get_contents( dirname( __DIR__ ) . '/templates/base-design.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the plugin.
	$design = json_decode( (string) $json, true );

	return is_array( $design ) ? $design : array();
}

/**
 * The site's design post ID, created from the Base design the first time.
 *
 * @return int Post ID, or 0 when it couldn't be created.
 */
function site_design_id() {
	$id = (int) get_option( SITE_DESIGN_OPTION );
	if ( $id && POST_TYPE === get_post_type( $id ) ) {
		return $id;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => __( 'Certificate', 'events-certificate' ),
			'post_content' => wp_slash( (string) wp_json_encode( base() ) ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	update_option( SITE_DESIGN_OPTION, $id, false );

	return (int) $id;
}

/**
 * A design's elements, or the Base design when the post has none.
 *
 * @param int $id Post ID.
 * @return array<string, mixed>
 */
function get( $id ) {
	$post   = get_post( $id );
	$design = $post ? json_decode( $post->post_content, true ) : null;

	return is_array( $design ) && isset( $design['elements'] ) ? $design : base();
}
