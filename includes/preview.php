<?php
/**
 * Preview in a new tab: the design editor sends the design as it is (unsaved
 * changes included), kept for a while per user, and opens the site with
 * ?evcert_preview=1 (like a post's ?preview=true), drawn like a certificate
 * page with the preview data, in any of the site's languages (?lang=).
 * The QR code and the code are the placeholders the editor shows, on any site.
 * Only people who may edit designs see it.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Preview;

use EventsCertificate\Admin;
use EventsCertificate\Designs;
use EventsCertificate\Render;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The query argument that asks for the preview.
 */
const QUERY_ARG = 'evcert_preview';

/**
 * The code a preview shows: clearly not a real one, as long as a real one.
 */
const SAMPLE_CODE = 'XXXX-XXXX-XXXX-XXXX';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_route' );
	add_action( 'template_redirect', __NAMESPACE__ . '\maybe_render', 1 );
}

/**
 * The preview's address in a language ('' = the site's).
 *
 * @param string $locale Locale.
 * @return string
 */
function url( $locale = '' ) {
	$args = array( QUERY_ARG => '1' );
	if ( $locale && get_locale() !== $locale ) {
		$args['lang'] = $locale;
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Prints the preview when the address asks for it.
 */
function maybe_render() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page, shown only to people who may edit designs.
	if ( ! isset( $_GET[ QUERY_ARG ] ) ) {
		return;
	}
	$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
	// phpcs:enable
	$locale   = in_array( $lang, \EventsCertificate\Verify\locales(), true ) ? $lang : get_locale();
	$switched = switch_to_locale( $locale );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	render( $locale );
	if ( $switched ) {
		restore_previous_locale();
	}
	exit;
}

/**
 * The transient holding a user's previewed design.
 *
 * @param int $user_id User ID.
 * @return string
 */
function key( $user_id ) {
	return 'evcert_preview_' . (int) $user_id;
}

/**
 * REST route: POST /evcert/v1/preview (design) → the preview page's address.
 */
function register_route() {
	register_rest_route(
		'evcert/v1',
		'/preview',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\store',
			'permission_callback' => static function () {
				return current_user_can( Admin\CAPABILITY );
			},
			'args'                => array(
				'design' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Keeps the design for an hour and answers with the preview address.
 *
 * @param WP_REST_Request $request Request.
 * @return array<string, string>|WP_Error
 */
function store( WP_REST_Request $request ) {
	$design = $request['design'];
	if ( ! is_array( $design ) || ! isset( $design['page'], $design['elements'] ) || ! is_array( $design['elements'] ) ) {
		return new WP_Error( 'evcert_design', 'Not a design.', array( 'status' => 400 ) );
	}
	set_transient( key( get_current_user_id() ), $design, HOUR_IN_SECONDS );

	return array( 'url' => url() );
}

/**
 * The design to preview: the one sent last by this user, else the site's.
 *
 * @return array<string, mixed>
 */
function design() {
	$sent = get_transient( key( get_current_user_id() ) );

	return is_array( $sent ) ? $sent : (array) Designs\get( Designs\site_design_id() );
}

/**
 * The fields' values: a certificate of today with the design's preview data,
 * the signer, event and place of the site, and the placeholder code.
 *
 * @param array<string, mixed> $design Design.
 * @return array<string, string>
 */
function values( array $design ) {
	$values = Render\values(
		array(
			'name'      => 'Alex Rivera',
			'role'      => 'attendee',
			'hours'     => '8',
			'code'      => SAMPLE_CODE,
			'issued_at' => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	// The signer previews the participant too, as in the editor.
	if ( '' !== $values['signer_name'] ) {
		$values['name'] = $values['signer_name'];
	}
	foreach ( (array) ( $design['sample'] ?? array() ) as $key => $value ) {
		if ( is_string( $value ) && '' !== $value && isset( $values[ $key ] ) && ! in_array( $key, array( 'code', 'verify_url' ), true ) ) {
			$values[ $key ] = $value;
		}
	}

	return $values;
}

/**
 * Prints the preview page (only for people who may edit designs).
 *
 * @param string $locale Locale.
 */
function render( $locale ) {
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		status_header( 404 );
		\EventsCertificate\Verify\render_page( null, '', $locale, false );
		return;
	}
	$design = design();
	$values = values( $design );
	$base   = plugins_url( '', dirname( __DIR__ ) . '/events-certificate.php' );
	wp_register_style( 'evcert-verify', $base . '/assets/verify.css', array(), \EventsCertificate\VERSION );
	wp_add_inline_style( 'evcert-verify', Render\fonts_css( false ) );
	wp_register_script( 'evcert-verify', $base . '/assets/verify.js', array(), \EventsCertificate\VERSION, true );
	$title = __( 'Certificate preview', 'events-certificate' );
	$opts  = array( 'preview' => true );
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', $locale ) ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_print_styles( array( 'evcert-verify' ) ); ?>
</head>
<body class="evcert-verify has-certificate is-preview">
	<h1 class="screen-reader-text"><?php echo esc_html( $title ); ?></h1>
	<main class="evcert-verify__stage">
		<div class="evcert-verify__art" style="<?php echo esc_attr( sprintf( '--evcert-w:%.2F;--evcert-h:%.2F', $design['page']['w'], $design['page']['h'] ) ); ?>">
			<div class="evcert-verify__mode is-light"><?php echo Render\artwork( $design, $values, $opts + array( 'mode' => 'light' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Render\element_html(). ?></div>
			<div class="evcert-verify__mode is-dark"><?php echo Render\artwork( $design, $values, $opts + array( 'mode' => 'dark' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></div>
		</div>
	</main>
	<nav class="evcert-dock" data-evcert-dock aria-label="<?php esc_attr_e( 'Certificate tools', 'events-certificate' ); ?>">
		<div class="evcert-dock__bar">
			<div class="evcert-dock__tools">
				<span class="evcert-dock__status evcert-dock__preview"><?php esc_html_e( 'Preview', 'events-certificate' ); ?></span>
				<?php
				// Language: a globe with the current language's name; two languages switch on click, more open a menu.
				$others = array_values( array_diff( \EventsCertificate\Verify\locales(), array( $locale ) ) );
				?>
				<?php if ( 1 === count( $others ) ) : ?>
					<a class="evcert-dock__tool" href="<?php echo esc_url( url( $others[0] ) ); ?>" hreflang="<?php echo esc_attr( str_replace( '_', '-', $others[0] ) ); ?>" title="<?php echo esc_attr( \EventsCertificate\Verify\locale_name( $others[0] ) ); ?>"><?php echo \EventsCertificate\Verify\icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( \EventsCertificate\Verify\locale_name( $locale ) ); ?></span></a>
				<?php elseif ( count( $others ) > 1 ) : ?>
					<button type="button" class="evcert-dock__tool" data-evcert-pop="langs" aria-expanded="false"><?php echo \EventsCertificate\Verify\icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( \EventsCertificate\Verify\locale_name( $locale ) ); ?></span></button>
				<?php endif; ?>
				<button type="button" class="evcert-dock__tool" data-evcert-mode data-to-light="<?php esc_attr_e( 'Light', 'events-certificate' ); ?>" data-to-dark="<?php esc_attr_e( 'Dark', 'events-certificate' ); ?>"><span class="evcert-dock__glyph" aria-hidden="true">☀︎</span><span class="evcert-dock__mode-label"><?php esc_html_e( 'Light', 'events-certificate' ); ?></span></button>
			</div>
		</div>
		<?php if ( count( \EventsCertificate\Verify\locales() ) > 2 ) : ?>
			<div class="evcert-dock__pop is-menu" data-evcert-popover="langs" hidden>
				<?php foreach ( \EventsCertificate\Verify\locales() as $l ) : ?>
					<a href="<?php echo esc_url( url( $l ) ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $l ) ); ?>" <?php echo $l === $locale ? 'aria-current="true"' : ''; ?>><?php echo esc_html( \EventsCertificate\Verify\locale_name( $l ) ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</nav>
	<?php wp_print_scripts( array( 'evcert-verify' ) ); ?>
</body>
</html>
	<?php
}
