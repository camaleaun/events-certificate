<?php
/**
 * Share images: the certificate as a 1200×630 PNG for social previews
 * (og:image). PHP can't rasterize the artwork without Imagick (dompdf's GD
 * backend draws no SVG), so the browser draws it — it already shows the
 * certificate exactly — and sends it here, once per language. Only someone
 * entitled to the certificate may send it: an admin, or the attendee with
 * their CampTix edit link. Images are a cache in uploads/evcert-share/,
 * regenerated from the record whenever missing (docs/decisions/0001).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\ShareImage;

use EventsCertificate\Certificates;
use EventsCertificate\Code;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Image size, the Open Graph 1.91:1 that LinkedIn, Facebook, X and WhatsApp show.
 */
const WIDTH  = 1200;
const HEIGHT = 630;

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_route' );
}

/**
 * The image file and URL of a certificate in a locale.
 *
 * @param string $code   Code.
 * @param string $locale Locale.
 * @return array{path: string, url: string}
 */
function location( $code, $locale ) {
	$uploads = wp_get_upload_dir();
	$name    = sanitize_file_name( strtolower( $code ) . '-' . $locale . '.png' );

	return array(
		'path' => $uploads['basedir'] . '/evcert-share/' . $name,
		'url'  => $uploads['baseurl'] . '/evcert-share/' . $name,
	);
}

/**
 * The image URL when it exists.
 *
 * @param string $code   Code.
 * @param string $locale Locale.
 * @return string '' when not generated yet.
 */
function url( $code, $locale ) {
	$where = location( $code, $locale );

	return is_readable( $where['path'] ) ? add_query_arg( 'v', (string) filemtime( $where['path'] ), $where['url'] ) : '';
}

/**
 * Whether the current request may send a certificate's image: admins, or the
 * attendee holding the CampTix edit token of the certificate's source.
 *
 * @param array<string, mixed> $cert  Certificate.
 * @param int                  $attendee_id Attendee ID from the request.
 * @param string               $token Edit token from the request.
 * @return bool
 */
function can_send( array $cert, $attendee_id = 0, $token = '' ) {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( 'camptix' !== $cert['source'] || ! $attendee_id || (string) $attendee_id !== (string) $cert['source_ref'] || '' === $token || ! ctype_alnum( $token ) ) {
		return false;
	}

	return hash_equals( (string) get_post_meta( $attendee_id, 'tix_edit_token', true ), $token );
}

/**
 * REST route: POST /evcert/v1/share-image (code, locale, image as a PNG data URI).
 */
function register_route() {
	register_rest_route(
		'evcert/v1',
		'/share-image',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\save',
			'permission_callback' => '__return_true', // Checked in save(): admin, or the attendee's edit token.
			'args'                => array(
				'code'            => array(
					'type'     => 'string',
					'required' => true,
				),
				'locale'          => array(
					'type'     => 'string',
					'required' => true,
				),
				'image'           => array(
					'type'     => 'string',
					'required' => true,
				),
				'tix_attendee_id' => array( 'type' => 'integer' ),
				'tix_edit_token'  => array( 'type' => 'string' ),
			),
		)
	);
}

/**
 * Saves a share image.
 *
 * @param WP_REST_Request $request Request.
 * @return array<string, string>|WP_Error
 */
function save( WP_REST_Request $request ) {
	$cert = Certificates\get_by_code( (string) $request['code'] );
	if ( ! $cert || 'issued' !== $cert['status'] ) {
		return new WP_Error( 'evcert_not_found', 'Not found.', array( 'status' => 404 ) );
	}
	if ( ! can_send( $cert, (int) $request['tix_attendee_id'], (string) $request['tix_edit_token'] ) ) {
		return new WP_Error( 'evcert_forbidden', 'Not allowed.', array( 'status' => 403 ) );
	}
	$locale = (string) $request['locale'];
	if ( ! in_array( $locale, \EventsCertificate\Verify\locales(), true ) ) {
		return new WP_Error( 'evcert_locale', 'Unknown locale.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string) $request['image'], $m ) ) {
		return new WP_Error( 'evcert_image', 'Expected a PNG data URI.', array( 'status' => 400 ) );
	}
	$png  = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an uploaded image, checked below.
	$info = false !== $png && strlen( $png ) < 3 * MB_IN_BYTES ? getimagesizefromstring( $png ) : false;
	if ( ! $info || IMAGETYPE_PNG !== $info[2] || WIDTH !== $info[0] || HEIGHT !== $info[1] ) {
		return new WP_Error( 'evcert_image', 'Expected a 1200×630 PNG.', array( 'status' => 400 ) );
	}

	$where = location( $cert['code'], $locale );
	wp_mkdir_p( dirname( $where['path'] ) );
	if ( false === file_put_contents( $where['path'], $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a cache file in uploads.
		return new WP_Error( 'evcert_write', 'Could not save.', array( 'status' => 500 ) );
	}

	return array( 'url' => url( $cert['code'], $locale ) );
}
