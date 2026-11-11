<?php
/**
 * "Check a certificate" in the site's own page: a block (and the
 * [evcert_verify] shortcode that draws it) with the code form, in CampTix's
 * look, inside a regular page with the theme's header and footer. Only a
 * certificate itself (<slug>/<code>/) keeps the full-screen page
 * (includes/verify.php). docs/decisions/0012.
 *
 * The form sends ?evcert_code=; a code on record goes to its certificate,
 * anything else comes back to the form with a message and what was typed.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\VerifyPage;

use EventsCertificate\Certificates;
use EventsCertificate\Code;
use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Option: the ID of the page with the form (0 when none was made).
 */
const OPTION = 'evcert_verify_page';

/**
 * Query argument with a typed code.
 */
const CODE_ARG = 'evcert_code';

/**
 * Block name.
 */
const BLOCK = 'events-certificate/verify';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register' );
	add_action( 'template_redirect', __NAMESPACE__ . '\open_code', 5 );
	add_action( 'admin_init', __NAMESPACE__ . '\ensure_page' );
	add_action( 'save_post_page', __NAMESPACE__ . '\sync_slug', 10, 2 );
}

/**
 * Registers the block and the shortcode.
 */
function register() {
	register_block_type( dirname( __DIR__ ) . '/blocks/verify' );
	add_shortcode( 'evcert_verify', __NAMESPACE__ . '\shortcode' );
}

/**
 * The page with the form, when it is published.
 *
 * @return int Page ID, or 0.
 */
function page_id() {
	$id = (int) get_option( OPTION, 0 );

	return $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
}

/**
 * The address of the form: the page, else the plugin's own card.
 *
 * @param string $typed A code to show back in the field (with the not-found message).
 * @return string
 */
function url( $typed = '' ) {
	$id   = page_id();
	$base = $id ? (string) get_permalink( $id ) : home_url( user_trailingslashit( Settings\value( 'slug' ) ) );

	return '' === $typed ? $base : add_query_arg( CODE_ARG, rawurlencode( $typed ), $base );
}

/**
 * A typed code on any page: a certificate on record opens; anything else
 * stays, and the form shows the message.
 */
function open_code() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only lookup.
	$typed = isset( $_GET[ CODE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ CODE_ARG ] ) ) : '';
	if ( '' === $typed ) {
		return;
	}
	$code = Code\normalize( $typed );
	$cert = '' !== $code ? Certificates\get_by_code( $code ) : null;
	if ( $cert && 'draft' !== $cert['status'] ) {
		wp_safe_redirect( Certificates\url( $cert['code'] ) );
		exit;
	}
}

/**
 * The [evcert_verify] shortcode: the block, drawn the same way.
 *
 * @param array<string, string>|string $atts title, help (0/1).
 * @return string
 */
function shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
			'help'  => '1',
		),
		(array) $atts,
		'evcert_verify'
	);

	return render_block(
		array(
			'blockName'    => BLOCK,
			'attrs'        => array(
				'title'    => (string) $atts['title'],
				'showHelp' => ! in_array( strtolower( (string) $atts['help'] ), array( '0', 'false', 'no' ), true ),
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

/**
 * The form, in CampTix's markup (its stylesheet when CampTix is active).
 *
 * @param array<string, mixed> $attributes title, showHelp.
 * @return string
 */
function form( array $attributes ) {
	if ( wp_style_is( 'camptix', 'registered' ) ) {
		wp_enqueue_style( 'camptix' );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows back what was typed.
	$typed   = isset( $_GET[ CODE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ CODE_ARG ] ) ) : '';
	$shown   = '' !== $typed && '' !== Code\normalize( $typed ) ? Code\normalize( $typed ) : $typed;
	$title   = trim( (string) ( $attributes['title'] ?? '' ) );
	$title   = '' !== $title ? $title : __( 'Check a certificate', 'events-certificate' );
	$help    = ! isset( $attributes['showHelp'] ) || ! empty( $attributes['showHelp'] );
	$wrapper = function_exists( 'get_block_wrapper_attributes' ) && \WP_Block_Supports::$block_to_render ? get_block_wrapper_attributes( array( 'class' => 'evcert-verify-form' ) ) : 'class="evcert-verify-form"';

	ob_start();
	?>
	<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes. ?>>
		<div id="tix" class="evcert-tix">
			<?php if ( '' !== $typed ) : ?>
				<div id="tix-errors"><div class="tix-error"><?php esc_html_e( 'No certificate has this code. Check it and try again.', 'events-certificate' ); ?></div></div>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( remove_query_arg( CODE_ARG ) ); ?>">
				<table class="tix_tickets_table tix-attendee-form">
					<tbody>
						<tr><th colspan="2"><?php echo esc_html( $title ); ?></th></tr>
						<tr>
							<td class="tix-required tix-left">
								<label for="evcert-code-field">
									<?php esc_html_e( 'Certificate code', 'events-certificate' ); ?>
									<span aria-hidden="true" class="tix-required-star">*</span>
								</label>
							</td>
							<td class="tix-right">
								<input id="evcert-code-field" name="<?php echo esc_attr( CODE_ARG ); ?>" type="text" required data-evcert-code
									value="<?php echo esc_attr( $shown ); ?>"
									placeholder="2611-04K7-M29Q-XAP3"
									inputmode="text" autocapitalize="characters" autocomplete="off" spellcheck="false"
									maxlength="24" pattern="[0-9A-Za-z\s\-]{16,24}"
									<?php echo '' !== $typed ? 'aria-invalid="true" aria-describedby="evcert-code-help"' : ( $help ? 'aria-describedby="evcert-code-help"' : '' ); ?> />
								<?php if ( $help ) : ?>
									<p class="tix-description" id="evcert-code-help"><?php esc_html_e( 'The code printed on the certificate, below its QR code: 16 letters and numbers.', 'events-certificate' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="tix-submit">
					<input type="submit" value="<?php esc_attr_e( 'Check', 'events-certificate' ); ?>" />
					<br class="tix-clear" />
				</p>
			</form>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Makes the page once, for administrators: "Certificate" with the block, at
 * the certificates' address (the slug setting). An existing page there is
 * left alone (choose it in Settings); a deleted page isn't made again.
 */
function ensure_page() {
	if ( false !== get_option( OPTION ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
		return;
	}
	$slug = (string) Settings\value( 'slug' );
	if ( get_page_by_path( $slug ) ) {
		update_option( OPTION, 0, false );
		return;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Certificate', 'events-certificate' ),
			'post_name'    => $slug,
			'post_content' => '<!-- wp:' . BLOCK . ' /-->',
		),
		true
	);
	update_option( OPTION, is_wp_error( $id ) ? 0 : (int) $id, false );
}

/**
 * The page's address is the certificates' address: renaming the page's slug
 * changes the slug setting (certificates then live under it too).
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post.
 */
function sync_slug( $post_id, $post ) {
	if ( page_id() !== (int) $post_id || wp_is_post_revision( $post_id ) || '' === $post->post_name ) {
		return;
	}
	if ( Settings\value( 'slug' ) !== $post->post_name ) {
		$settings         = (array) get_option( Settings\OPTION, array() );
		$settings['slug'] = $post->post_name;
		update_option( Settings\OPTION, $settings );
	}
}
