<?php
/**
 * The signer: the lead organizer who signs the certificates, with their role
 * (per language) and signature. On WordCamp sites it is one of the
 * Organizers (wcb_organizer, from wc-post-types): a "Certificate" panel in
 * the organizer's block editor sidebar (src/signer-panel) marks them and
 * holds the role per language and the signature, in post meta.
 * The signature is restricted SVG, like the editor's (docs/decisions/0005):
 * a viewBox and filled paths, nothing else.
 *
 * Certificates freeze the signer's name, role and signature when issued.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Signer;

use EventsCertificate\Render;
use EventsCertificate\Verify;

defined( 'ABSPATH' ) || exit;

/**
 * Organizer meta: signs (bool), role per locale (object) and signature ({vw, vh, paths}).
 */
const SIGNS_META     = '_evcert_signs';
const ROLE_META      = '_evcert_signer_role';
const SIGNATURE_META = '_evcert_signature';

/**
 * Post type of organizers (wc-post-types).
 */
const ORGANIZER = 'wcb_organizer';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register_meta', 20 );
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue' );
	add_action( 'added_post_meta', __NAMESPACE__ . '\one_signer', 10, 4 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\one_signer', 10, 4 );
}

/**
 * Registers the organizer meta for the block editor (REST), protected and sanitized.
 */
function register_meta() {
	if ( ! post_type_exists( ORGANIZER ) ) {
		return;
	}
	$auth = static function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	register_post_meta(
		ORGANIZER,
		SIGNS_META,
		array(
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		)
	);
	register_post_meta(
		ORGANIZER,
		ROLE_META,
		array(
			'type'              => 'object',
			'single'            => true,
			'default'           => array(),
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_roles',
			'auth_callback'     => $auth,
			'show_in_rest'      => array(
				'schema' => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'string' ),
				),
			),
		)
	);
	register_post_meta(
		ORGANIZER,
		SIGNATURE_META,
		array(
			'type'              => 'object',
			'single'            => true,
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_signature',
			'auth_callback'     => $auth,
			'show_in_rest'      => array(
				// One type only: WordPress leaves a meta out of the REST API when its schema type is a list
				// (['object', 'null'] made the signature silently unsaved). Sending null deletes it.
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'vw'    => array( 'type' => 'number' ),
						'vh'    => array( 'type' => 'number' ),
						'paths' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'd'    => array( 'type' => 'string' ),
									'fill' => array( 'type' => 'string' ),
									'rule' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
		)
	);
}

/**
 * Keeps the roles of the site's languages, as plain text.
 *
 * @param mixed $value Roles.
 * @return array<string, string>
 */
function sanitize_roles( $value ) {
	$clean = array();
	foreach ( (array) $value as $locale => $role ) {
		if ( in_array( $locale, Verify\locales(), true ) && '' !== trim( (string) $role ) ) {
			$clean[ $locale ] = sanitize_text_field( (string) $role );
		}
	}

	return $clean;
}

/**
 * Keeps a signature's restricted fields: a positive viewBox and paths with
 * path data, a color fill (or none, the current color) and the fill rule.
 *
 * @param mixed $value Signature.
 * @return array<string, mixed>|null
 */
function sanitize_signature( $value ) {
	if ( ! is_array( $value ) || empty( $value['paths'] ) ) {
		return null;
	}
	$paths = array();
	foreach ( (array) $value['paths'] as $path ) {
		$d = trim( preg_replace( '/\s+/', ' ', (string) ( $path['d'] ?? '' ) ) );
		if ( ! Render\is_path_data( $d ) ) {
			continue;
		}
		$clean = array( 'd' => $d );
		$fill  = strtolower( trim( (string) ( $path['fill'] ?? '' ) ) );
		if ( preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/', $fill ) ) {
			$clean['fill'] = $fill;
		}
		if ( 'evenodd' === ( $path['rule'] ?? '' ) ) {
			$clean['rule'] = 'evenodd';
		}
		$paths[] = $clean;
	}
	$vw = (float) ( $value['vw'] ?? 0 );
	$vh = (float) ( $value['vh'] ?? 0 );

	return $paths && $vw > 0 && $vh > 0 ? array(
		'vw'    => $vw,
		'vh'    => $vh,
		'paths' => $paths,
	) : null;
}

/**
 * One signer per site: marking an organizer unmarks the others.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function one_signer( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( SIGNS_META !== $meta_key || ! $meta_value || ORGANIZER !== get_post_type( $post_id ) ) {
		return;
	}
	foreach ( signers() as $other ) {
		if ( $other !== (int) $post_id ) {
			update_post_meta( $other, SIGNS_META, false );
		}
	}
}

/**
 * Organizers marked as signers (normally one).
 *
 * @return int[]
 */
function signers() {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'   => ORGANIZER,
				'post_status' => 'any',
				'numberposts' => 5,
				'fields'      => 'ids',
				'orderby'     => 'modified',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of organizers.
					array(
						'key'   => SIGNS_META,
						'value' => '1',
					),
				),
			)
		)
	);
}

/**
 * Loads the Certificate panel on the organizer screen.
 */
function enqueue() {
	$screen = get_current_screen();
	if ( ! $screen || ORGANIZER !== $screen->post_type ) {
		return;
	}
	$dir   = dirname( __DIR__ ) . '/build-signer/';
	$asset = is_readable( $dir . 'index.min.asset.php' ) ? require $dir . 'index.min.asset.php' : null;
	if ( ! $asset ) {
		return;
	}
	$url = plugins_url( 'build-signer/', dirname( __DIR__ ) . '/events-certificate.php' );
	wp_enqueue_script( 'evcert-signer-panel', $url . 'index.min.js', $asset['dependencies'], $asset['version'], true );
	if ( is_readable( $dir . 'index.min.css' ) ) {
		wp_enqueue_style( 'evcert-signer-panel', $url . 'index.min.css', array( 'wp-components' ), $asset['version'] );
	}
	$signer = current();
	wp_add_inline_script(
		'evcert-signer-panel',
		'window.evcertSigner = ' . wp_json_encode(
			array(
				'locales' => array_map(
					static function ( $locale ) {
						return array(
							'locale' => $locale,
							'name'   => Verify\locale_name( $locale ),
						);
					},
					Verify\locales()
				),
				'signer'  => $signer ? $signer['name'] : '',
			)
		) . ';',
		'before'
	);
	wp_set_script_translations( 'evcert-signer-panel', 'events-certificate' );
}

/**
 * Reads SVG code into restricted fields: the viewBox size and the paths with
 * their fill (groups unwrapped, their fill passed down). Strokes, other
 * shapes, styles, scripts and links are dropped.
 *
 * @param string $code SVG code.
 * @return array{vw: float, vh: float, paths: array<int, array<string, string>>}|null
 */
function parse_svg( $code ) {
	$code = trim( (string) $code );
	// No DTDs or entities (XXE), no network.
	if ( '' === $code || ! class_exists( 'DOMDocument' ) || preg_match( '/<!(DOCTYPE|ENTITY)/i', $code ) ) {
		return null;
	}
	$doc      = new \DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$ok       = $doc->loadXML( $code, LIBXML_NONET );
	libxml_use_internal_errors( $previous );
	$root = $ok ? $doc->documentElement : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
	if ( ! $root || 'svg' !== strtolower( $root->localName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		return null;
	}
	$fill_of = static function ( $node ) use ( $root ) {
		for ( $n = $node; $n instanceof \DOMElement; $n = $n->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			// A fill in the style attribute wins over the fill attribute, as in CSS (Inkscape writes styles).
			if ( preg_match( '/(?:^|;)\s*fill\s*:\s*([^;]+)/i', $n->getAttribute( 'style' ), $m ) ) {
				return trim( $m[1] );
			}
			if ( $n->hasAttribute( 'fill' ) ) {
				return $n->getAttribute( 'fill' );
			}
			if ( $n === $root ) {
				break;
			}
		}
		return '';
	};
	$paths   = array();
	foreach ( $root->getElementsByTagName( 'path' ) as $node ) {
		$d = trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'd' ) ) );
		if ( ! Render\is_path_data( $d ) ) {
			continue;
		}
		$fill = strtolower( trim( $fill_of( $node ) ) );
		if ( 'none' === $fill ) {
			continue; // Outline-only paths (strokes) aren't kept: convert strokes to filled outlines first.
		}
		$path = array( 'd' => $d );
		if ( preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/', $fill ) ) {
			$path['fill'] = $fill;
		}
		if ( 'evenodd' === $node->getAttribute( 'fill-rule' ) || preg_match( '/fill-rule\s*:\s*evenodd/i', $node->getAttribute( 'style' ) ) ) {
			$path['rule'] = 'evenodd';
		}
		$paths[] = $path;
	}
	if ( ! $paths ) {
		return null;
	}
	$box = preg_split( '/[\s,]+/', trim( $root->getAttribute( 'viewBox' ) ) );
	$vw  = 4 === count( $box ) && (float) $box[2] > 0 ? (float) $box[2] : ( (float) $root->getAttribute( 'width' ) ? (float) $root->getAttribute( 'width' ) : 100.0 );
	$vh  = 4 === count( $box ) && (float) $box[3] > 0 ? (float) $box[3] : ( (float) $root->getAttribute( 'height' ) ? (float) $root->getAttribute( 'height' ) : 100.0 );

	return array(
		'vw'    => $vw,
		'vh'    => $vh,
		'paths' => $paths,
	);
}

/**
 * The signature as SVG markup (for previews), in a color.
 *
 * @param array<string, mixed> $signature Signature fields.
 * @param string               $color     Color of paths without their own fill.
 * @return string
 */
function signature_svg( array $signature, $color = 'currentColor' ) {
	$paths = '';
	foreach ( (array) ( $signature['paths'] ?? array() ) as $path ) {
		if ( Render\is_path_data( $path['d'] ?? '' ) ) {
			$paths .= sprintf( '<path d="%s" fill="%s"%s/>', esc_attr( $path['d'] ), esc_attr( $path['fill'] ?? $color ), 'evenodd' === ( $path['rule'] ?? '' ) ? ' fill-rule="evenodd"' : '' );
		}
	}

	return sprintf( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s">%s</svg>', (float) $signature['vw'], (float) $signature['vh'], $paths );
}

/**
 * The signer now: name, role per locale and signature, or null.
 *
 * @return array{name: string, roles: array<string, string>, signature: array<string, mixed>|null}|null
 */
function current() {
	$ids  = signers();
	$post = $ids ? get_post( $ids[0] ) : null;
	if ( ! $post ) {
		return null;
	}
	$signature = get_post_meta( $post->ID, SIGNATURE_META, true );

	return array(
		'name'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'roles'     => array_filter( (array) get_post_meta( $post->ID, ROLE_META, true ) ),
		'signature' => is_array( $signature ) && ! empty( $signature['paths'] ) ? $signature : null,
	);
}

/**
 * A role in a locale: that locale's, else the site language's, else any.
 *
 * @param array<string, string> $roles  Role per locale.
 * @param string                $locale Locale.
 * @return string
 */
function role_in( array $roles, $locale ) {
	if ( ! empty( $roles[ $locale ] ) ) {
		return $roles[ $locale ];
	}
	if ( ! empty( $roles[ get_locale() ] ) ) {
		return $roles[ get_locale() ];
	}

	return $roles ? (string) reset( $roles ) : '';
}
