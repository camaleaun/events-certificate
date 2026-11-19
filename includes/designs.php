<?php
/**
 * Designs: the certificate artwork, one post per design (post type
 * evcert_design) holding the elements as JSON in post_content, so core gives
 * revisions, permissions and edit locking. The editor saves through the REST
 * API (/wp/v2/evcert-designs).
 *
 * Designs are the certificate templates ("Modelos"): each one may apply to
 * some roles (volunteers, speakers…); the default one (the site's design)
 * takes everyone else. The first matching template, in the list's order,
 * draws a certificate (for_certificate(); docs/decisions/0013). The "Base"
 * starting point lives in templates/base-design.json, shared by the editor
 * (bundled at build time) and the PHP renderer.
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
 * Meta: the roles a template applies to (empty: none; the default takes everyone left).
 */
const ROLES_META = '_evcert_roles';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register_post_type' );
	add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\keep_json', 10, 3 );
	add_action( 'init', __NAMESPACE__ . '\register_meta' );
}

/**
 * The roles a certificate can have, with their names.
 *
 * @return array<string, string>
 */
function role_labels() {
	return array(
		'attendee'  => __( 'Attendee', 'events-certificates' ),
		'speaker'   => __( 'Speaker', 'events-certificates' ),
		'organizer' => __( 'Organizer', 'events-certificates' ),
		'volunteer' => __( 'Volunteer', 'events-certificates' ),
		'sponsor'   => __( 'Sponsor', 'events-certificates' ),
	);
}

/**
 * Registers the roles meta for the editor (REST).
 */
function register_meta() {
	register_post_meta(
		POST_TYPE,
		ROLES_META,
		array(
			'type'              => 'array',
			'single'            => true,
			'default'           => array(),
			'sanitize_callback' => 'EventsCertificate\Designs\sanitize_roles',
			'auth_callback'     => static function () {
				return current_user_can( \EventsCertificate\Admin\CAPABILITY );
			},
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		)
	);
}

/**
 * Keeps known roles only, once each.
 *
 * @param mixed $value Roles.
 * @return string[]
 */
function sanitize_roles( $value ) {
	return array_values( array_intersect( array_keys( role_labels() ), array_map( 'strval', (array) $value ) ) );
}

/**
 * The roles a template applies to.
 *
 * @param int $id Post ID.
 * @return string[]
 */
function roles( $id ) {
	return sanitize_roles( get_post_meta( $id, ROLES_META, true ) );
}

/**
 * The templates, in the list's order (menu_order, then oldest first); the
 * default one included.
 *
 * @return \WP_Post[]
 */
function templates() {
	site_design_id(); // Make the default one exist.

	return get_posts(
		array(
			'post_type'   => POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
		)
	);
}

/**
 * The template that draws a certificate: the first one, in the list's order,
 * that applies to its role; else the default.
 *
 * @param array<string, mixed> $cert Certificate (role).
 * @return int Post ID.
 */
function for_certificate( array $cert ) {
	$default = site_design_id();
	$role    = (string) ( $cert['role'] ?? '' );
	if ( '' !== $role ) {
		foreach ( templates() as $post ) {
			if ( $post->ID !== $default && in_array( $role, roles( $post->ID ), true ) ) {
				return (int) $post->ID;
			}
		}
	}

	return $default;
}

/**
 * A short version of the templates as they are now (content, conditions,
 * order, default): changes when any certificate may look different.
 *
 * @return string
 */
function fingerprint() {
	$all = array( site_design_id() );
	foreach ( templates() as $post ) {
		$all[] = array( $post->ID, $post->post_modified_gmt, $post->menu_order, roles( $post->ID ) );
	}

	return md5( (string) wp_json_encode( $all ) );
}

/**
 * Makes a template from another one (or from the Base design): its content
 * and conditions, named "<name> (copy)", last in the list.
 *
 * @param int    $from Post ID to copy, 0 for the Base design.
 * @param string $name Name of the new template ('' for "<name> (copy)" or "New template").
 * @return int New post ID, or 0.
 */
function duplicate( $from = 0, $name = '' ) {
	$source = $from ? get_post( $from ) : null;
	$last   = templates();
	$order  = $last ? (int) end( $last )->menu_order + 1 : 0;
	$id     = wp_insert_post(
		array(
			'post_type'    => POST_TYPE,
			'post_status'  => 'publish',
			/* translators: %s: name of the certificate template copied. */
			'post_title'   => '' !== trim( $name ) ? trim( $name ) : ( $source ? sprintf( __( '%s (copy)', 'events-certificates' ), $source->post_title ) : __( 'New template', 'events-certificates' ) ),
			'post_content' => wp_slash( $source ? $source->post_content : (string) wp_json_encode( base() ) ),
			'menu_order'   => $order,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	if ( $source ) {
		update_post_meta( $id, ROLES_META, roles( $source->ID ) );
	}

	return (int) $id;
}

/**
 * Makes a template the default one (it then applies to everyone left).
 *
 * @param int $id Post ID.
 * @return bool
 */
function set_default( $id ) {
	if ( POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
		return false;
	}
	update_option( SITE_DESIGN_OPTION, (int) $id, false );
	delete_post_meta( $id, ROLES_META );

	return true;
}

/**
 * Moves a template one place up or down in the list.
 *
 * @param int    $id  Post ID.
 * @param string $dir up|down.
 */
function move( $id, $dir ) {
	$ids = wp_list_pluck( templates(), 'ID' );
	$at  = array_search( (int) $id, array_map( 'intval', $ids ), true );
	$to  = 'up' === $dir ? $at - 1 : $at + 1;
	if ( false === $at || $to < 0 || $to >= count( $ids ) ) {
		return;
	}
	list( $ids[ $at ], $ids[ $to ] ) = array( $ids[ $to ], $ids[ $at ] );
	foreach ( $ids as $i => $post_id ) {
		wp_update_post(
			array(
				'ID'         => (int) $post_id,
				'menu_order' => $i,
			)
		);
	}
}

/**
 * A design's content is JSON read by the editor and the renderer, not HTML:
 * WordPress's kses (which runs for users without unfiltered_html, such as a
 * site administrator on a network) would turn "&" into "&amp;" and drop
 * "<...>" from its texts. Every text is escaped, and the artwork is kses'd
 * again, when a certificate is drawn, so the saved JSON is kept as sent, for
 * users who may edit designs, when it is valid.
 *
 * @param array<string, mixed> $data      Sanitized post data.
 * @param array<string, mixed> $postarr   Post data as sent (slashed).
 * @param array<string, mixed> $unsanitized Post data before sanitizing (slashed).
 * @return array<string, mixed>
 */
function keep_json( $data, $postarr, $unsanitized = array() ) {
	if ( POST_TYPE !== ( $data['post_type'] ?? '' ) || ! current_user_can( \EventsCertificate\Admin\CAPABILITY ) || ! isset( $unsanitized['post_content'] ) ) {
		return $data;
	}
	$json = json_decode( wp_unslash( (string) $unsanitized['post_content'] ), true );
	if ( is_array( $json ) && isset( $json['elements'] ) ) {
		$data['post_content'] = $unsanitized['post_content'];
	}

	return $data;
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
				'name'          => __( 'Certificate designs', 'events-certificates' ),
				'singular_name' => __( 'Certificate design', 'events-certificates' ),
			),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => true,
			'rest_base'       => 'evcert-designs',
			'supports'        => array( 'title', 'editor', 'revisions' ),
			'capability_type' => 'evcert_design',
			'map_meta_cap'    => false,
			'capabilities'    => array(
				'edit_post'          => \EventsCertificate\Admin\CAPABILITY,
				'read_post'          => \EventsCertificate\Admin\CAPABILITY,
				'delete_post'        => \EventsCertificate\Admin\CAPABILITY,
				'edit_posts'         => \EventsCertificate\Admin\CAPABILITY,
				'edit_others_posts'  => \EventsCertificate\Admin\CAPABILITY,
				'publish_posts'      => \EventsCertificate\Admin\CAPABILITY,
				'read_private_posts' => \EventsCertificate\Admin\CAPABILITY,
				'delete_posts'       => \EventsCertificate\Admin\CAPABILITY,
				'create_posts'       => \EventsCertificate\Admin\CAPABILITY,
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
	$design = is_array( $design ) ? $design : array();

	/**
	 * Filters the Base design: the certificate a site starts from. It is the design of a
	 * site that has none yet, what "New template" copies and "Restore the Base design" goes
	 * back to. A network (a WordCamp.org or WordPress.org mu-plugin) or a Playground blueprint
	 * can give another. A design with a page and elements; anything else is ignored.
	 *
	 * @param array<string, mixed> $design The Base design (templates/base-design.json).
	 */
	$filtered = apply_filters( 'evcert_base_design', $design );

	return is_array( $filtered ) && isset( $filtered['page'], $filtered['elements'] ) && is_array( $filtered['elements'] ) ? $filtered : $design;
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
			'post_title'   => __( 'Certificate', 'events-certificates' ),
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
