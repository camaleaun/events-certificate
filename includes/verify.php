<?php
/**
 * The certificate page: /{slug}/{code}/ (slug from the settings, "certificate"
 * by default), with /certificate/{code}/ and /verify/{code}/ always working too. Anyone with the code sees the certificate,
 * whether it is authentic, replaced or revoked, in any of the site's
 * languages, and downloads the PDF. Without a code it asks for one. It never
 * lists certificates (docs/decisions/0006) and asks search engines not to
 * index it.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Verify;

use EventsCertificate\Certificates;
use EventsCertificate\Code;
use EventsCertificate\Render;
use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Query var that marks a certificate page request.
 */
const QUERY_VAR = 'evcert_verify';

/**
 * Query var with the code.
 */
const CODE_VAR = 'evcert_code';

/**
 * Option remembering which slug the rewrite rules were built for.
 */
const RULES_OPTION = 'evcert_rewrite_slug';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\add_rules' );
	add_action( 'init', __NAMESPACE__ . '\maybe_flush', 99 );
	add_filter( 'query_vars', __NAMESPACE__ . '\query_vars' );
	add_action( 'template_redirect', __NAMESPACE__ . '\template_redirect' );
}

/**
 * The addresses a certificate page answers on: the configured slug, plus
 * /certificate/ and /verify/ always, so links keep working whatever the slug.
 *
 * @return string[]
 */
function slugs() {
	return array_values( array_unique( array( Settings\value( 'slug' ), 'certificate', 'verify' ) ) );
}

/**
 * Rewrite rules for every slug.
 */
function add_rules() {
	foreach ( slugs() as $slug ) {
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$', 'index.php?' . QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/([0-9A-Za-z-]+)/?$', 'index.php?' . QUERY_VAR . '=1&' . CODE_VAR . '=$matches[1]', 'top' );
	}
}

/**
 * Rebuilds the rewrite rules once when the slug changed.
 */
function maybe_flush() {
	$key = implode( ',', slugs() );
	if ( get_option( RULES_OPTION ) !== $key ) {
		flush_rewrite_rules( false );
		update_option( RULES_OPTION, $key, false );
	}
}

/**
 * Registers the query vars.
 *
 * @param string[] $vars Query vars.
 * @return string[]
 */
function query_vars( $vars ) {
	$vars[] = QUERY_VAR;
	$vars[] = CODE_VAR;

	return $vars;
}

/**
 * The languages a certificate can be seen in: the site's language, the other
 * installed ones and English.
 *
 * @return string[] Locales.
 */
function locales() {
	$locales = array_merge( array( get_locale() ), get_available_languages(), array( 'en_US' ) );

	return array_values( array_unique( array_filter( $locales ) ) );
}

/**
 * A locale's name in its own language.
 *
 * @param string $locale Locale.
 * @return string
 */
function locale_name( $locale ) {
	if ( class_exists( 'Locale' ) ) {
		$name = \Locale::getDisplayLanguage( $locale, $locale );
		if ( $name ) {
			return function_exists( 'mb_convert_case' ) ? mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' ) : ucfirst( $name );
		}
	}

	return $locale;
}

/**
 * The address of a certificate page in a language.
 *
 * @param string $code   Code.
 * @param string $locale Locale ('' for the default).
 * @param array  $args   More query args.
 * @return string
 */
function page_url( $code, $locale = '', $args = array() ) {
	$url = Certificates\url( $code );
	if ( $locale && get_locale() !== $locale ) {
		$args['lang'] = $locale;
	}

	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Serves the certificate page or its PDF.
 */
function template_redirect() {
	if ( ! get_query_var( QUERY_VAR ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only page.
	if ( isset( $_GET['code'] ) ) {
		$typed = Code\normalize( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
		wp_safe_redirect( $typed ? Certificates\url( $typed ) : home_url( user_trailingslashit( Settings\value( 'slug' ) ) . '?invalid=1' ) );
		exit;
	}
	$lang   = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
	$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
	$typed  = isset( $_GET['invalid'] );
	// phpcs:enable
	$locale = in_array( $lang, locales(), true ) ? $lang : get_locale();
	$code   = (string) get_query_var( CODE_VAR );
	$cert   = '' !== $code ? Certificates\get_by_code( $code ) : null;
	if ( $cert && 'draft' === $cert['status'] ) {
		$cert = null;
	}

	$switched = switch_to_locale( $locale );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );

	if ( $cert && 'pdf' === $format ) {
		$bytes = Render\pdf( $cert, Certificates\design( $cert ) );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="certificate-' . $cert['code'] . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
		exit;
	}

	if ( '' !== $code && ! $cert ) {
		status_header( 404 );
	}
	render_page( $cert, $code, $locale, $typed );
	if ( $switched ) {
		restore_previous_locale();
	}
	exit;
}

/**
 * The status line of a certificate.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return array{0: string, 1: string, 2: string} Class, title, detail (HTML).
 */
function status( array $cert ) {
	if ( 'replaced' === $cert['status'] ) {
		$new = ! empty( $cert['replaced_by'] ) ? Certificates\get( (int) $cert['replaced_by'] ) : null;
		return array(
			'is-replaced',
			__( 'Replaced by a corrected certificate', 'events-certificate' ),
			$new ? sprintf(
				/* translators: %s: link to the new certificate. */
				esc_html__( 'This code was valid, but the certificate was corrected. See the current one: %s', 'events-certificate' ),
				'<a href="' . esc_url( Certificates\url( $new['code'] ) ) . '">' . esc_html( $new['code'] ) . '</a>'
			) : '',
		);
	}
	if ( 'revoked' === $cert['status'] ) {
		return array( 'is-revoked', __( 'Revoked', 'events-certificate' ), esc_html( (string) $cert['revoked_reason'] ) );
	}

	return array(
		'is-valid',
		__( 'Authentic certificate', 'events-certificate' ),
		sprintf(
			/* translators: %s: site name. */
			esc_html__( 'Issued by %s and kept in its records. The data below is what was issued.', 'events-certificate' ),
			'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
		),
	);
}

/**
 * Icons from @wordpress/icons (24×24 path data), drawn inline.
 */
const ICONS = array(
	'download' => 'M18 11.3l-1-1.1-4 4V3h-1.5v11.3L7 10.2l-1 1.1 6.2 5.8 5.8-5.8zm.5 3.7v3.5h-13V15H4v5h16v-5h-1.5z',
	'link'     => 'M10 17.389H8.444A5.194 5.194 0 1 1 8.444 7H10v1.5H8.444a3.694 3.694 0 0 0 0 7.389H10v1.5ZM14 7h1.556a5.194 5.194 0 0 1 0 10.39H14v-1.5h1.556a3.694 3.694 0 0 0 0-7.39H14V7Zm-4.5 6h5v-1.5h-5V13Z',
	'globe'    => 'M12 4c-4.4 0-8 3.6-8 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8Zm6.5 8c0 .6 0 1.2-.2 1.8h-2.7c0-.6.2-1.1.2-1.8s0-1.2-.2-1.8h2.7c.2.6.2 1.1.2 1.8Zm-.9-3.2h-2.4c-.3-.9-.7-1.8-1.1-2.4-.1-.2-.2-.4-.3-.5 1.6.5 3 1.6 3.8 3ZM12.8 17c-.3.5-.6 1-.8 1.3-.2-.3-.5-.8-.8-1.3-.3-.5-.6-1.1-.8-1.7h3.3c-.2.6-.5 1.2-.8 1.7Zm-2.9-3.2c-.1-.6-.2-1.1-.2-1.8s0-1.2.2-1.8H14c.1.6.2 1.1.2 1.8s0 1.2-.2 1.8H9.9ZM11.2 7c.3-.5.6-1 .8-1.3.2.3.5.8.8 1.3.3.5.6 1.1.8 1.7h-3.3c.2-.6.5-1.2.8-1.7Zm-1-1.2c-.1.2-.2.3-.3.5-.4.7-.8 1.5-1.1 2.4H6.4c.8-1.4 2.2-2.5 3.8-3Zm-1.8 8H5.7c-.2-.6-.2-1.1-.2-1.8s0-1.2.2-1.8h2.7c0 .6-.2 1.1-.2 1.8s0 1.2.2 1.8Zm-2 1.4h2.4c.3.9.7 1.8 1.1 2.4.1.2.2.4.3.5-1.6-.5-3-1.6-3.8-3Zm7.4 3c.1-.2.2-.3.3-.5.4-.7.8-1.5 1.1-2.4h2.4c-.8 1.4-2.2 2.5-3.8 3Z',
	'info'     => 'M5.5 12a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0ZM12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm.75 4v1.5h-1.5V8h1.5Zm0 8v-5h-1.5v5h1.5Z',
	'left'     => 'M18 4H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM8.5 18.5H6c-.3 0-.5-.2-.5-.5V6c0-.3.2-.5.5-.5h2.5v13zm10-.5c0 .3-.2.5-.5.5h-8v-13h8c.3 0 .5.2.5.5v12z',
	'right'    => 'M18 4H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-4 14.5H6c-.3 0-.5-.2-.5-.5V6c0-.3.2-.5.5-.5h8v13zm4.5-.5c0 .3-.2.5-.5.5h-2.5v-13H18c.3 0 .5.2.5.5v12z',
	'shield'   => 'M12 3.176l6.75 3.068v4.574c0 3.9-2.504 7.59-6.035 8.755a2.283 2.283 0 01-1.43 0c-3.53-1.164-6.035-4.856-6.035-8.755V6.244L12 3.176zM6.75 7.21v3.608c0 3.313 2.145 6.388 5.005 7.33.159.053.331.053.49 0 2.86-.942 5.005-4.017 5.005-7.33V7.21L12 4.824 6.75 7.21z',
	'check'    => 'M16.5 7.5 10 13.9l-2.5-2.4-1 1 3.5 3.6 7.5-7.6z',
	'share'    => 'M9 11.8l6.1-4.5c.1.4.4.7.9.7h2c.6 0 1-.4 1-1V5c0-.6-.4-1-1-1h-2c-.6 0-1 .4-1 1v.4l-6.4 4.8c-.2-.1-.4-.2-.6-.2H6c-.6 0-1 .4-1 1v2c0 .6.4 1 1 1h2c.2 0 .4-.1.6-.2l6.4 4.8v.4c0 .6.4 1 1 1h2c.6 0 1-.4 1-1v-2c0-.6-.4-1-1-1h-2c-.5 0-.8.3-.9.7L9 12.2v-.4z',
	'copy'     => 'M5 4.5h11a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5H5a.5.5 0 0 1-.5-.5V5a.5.5 0 0 1 .5-.5ZM3 5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm17 3v10.75c0 .69-.56 1.25-1.25 1.25H6v1.5h12.75a2.75 2.75 0 0 0 2.75-2.75V8H20Z',
);

/**
 * Where a certificate can be shared: LinkedIn's "Add to profile" (the
 * Licenses & certifications form, prefilled) and the usual share links.
 *
 * @param array<string, mixed> $cert Certificate.
 * @param string               $url  Certificate page address (in the display language).
 * @return array<string, array{0: string, 1: string}> Key => label, URL.
 */
function share_links( array $cert, $url ) {
	$issued = strtotime( $cert['issued_at'] . ' UTC' );
	$title  = sprintf(
		/* translators: %s: event name. */
		__( 'Certificate of participation: %s', 'events-certificate' ),
		$cert['event_title']
	);
	$text = sprintf(
		/* translators: %s: event name. */
		__( 'My certificate from %s', 'events-certificate' ),
		$cert['event_title']
	);

	return array(
		'linkedin-profile' => array(
			__( 'Add to LinkedIn profile', 'events-certificate' ),
			add_query_arg(
				rawurlencode_deep(
					array(
						'startTask'        => 'CERTIFICATION_NAME',
						'name'             => $title,
						'organizationName' => get_bloginfo( 'name' ),
						'issueYear'        => gmdate( 'Y', $issued ),
						'issueMonth'       => gmdate( 'n', $issued ),
						'certUrl'          => $url,
						'certId'           => $cert['code'],
					)
				),
				'https://www.linkedin.com/profile/add'
			),
		),
		'linkedin'         => array( 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ) ),
		'x'                => array( 'X', 'https://x.com/intent/post?text=' . rawurlencode( $text ) . '&url=' . rawurlencode( $url ) ),
		'facebook'         => array( 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ),
		'whatsapp'         => array( 'WhatsApp', 'https://wa.me/?text=' . rawurlencode( $text . ' ' . $url ) ),
		'email'            => array( __( 'E-mail', 'events-certificate' ), 'mailto:?subject=' . rawurlencode( $text ) . '&body=' . rawurlencode( $url ) ),
	);
}

/**
 * An icon.
 *
 * @param string $name Key of ICONS.
 * @return string
 */
function icon( $name ) {
	// Icons with a hole (info) need the even-odd rule, as in @wordpress/icons.
	$rule = 'info' === $name ? ' fill-rule="evenodd" clip-rule="evenodd"' : '';

	return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor"' . $rule . ' d="' . esc_attr( ICONS[ $name ] ?? '' ) . '"/></svg>';
}

/**
 * Prints the page: the certificate fills the screen; everything else is in a
 * floating Dock (like Playground's toolbar) that docks to the left or right
 * corner as one icon.
 *
 * @param array<string, mixed>|null $cert    Certificate, or null.
 * @param string                    $code    Code asked for.
 * @param string                    $locale  Display locale.
 * @param bool                      $invalid Whether a typed code was invalid.
 */
function render_page( $cert, $code, $locale, $invalid ) {
	$base = plugins_url( '', dirname( __DIR__ ) . '/events-certificate.php' );
	wp_register_style( 'evcert-verify', $base . '/assets/verify.css', array(), \EventsCertificate\VERSION );
	wp_add_inline_style( 'evcert-verify', Render\fonts_css( false ) );
	wp_register_script( 'evcert-verify', $base . '/assets/verify.js', array(), \EventsCertificate\VERSION, true );

	$title = $cert ? sprintf( /* translators: %s: person's name. */ __( 'Certificate: %s', 'events-certificate' ), $cert['name'] ) : __( 'Check a certificate', 'events-certificate' );
	if ( $cert ) {
		list( $class, $heading, $detail ) = status( $cert );
		$short                            = array(
			'is-valid'    => __( 'Authentic', 'events-certificate' ),
			'is-replaced' => __( 'Replaced', 'events-certificate' ),
			'is-revoked'  => __( 'Revoked', 'events-certificate' ),
		)[ $class ];
		$design                           = Certificates\design( $cert );
		$values                           = Render\values( $cert );
	}
	$allowed = array(
		'a'      => array( 'href' => true ),
		'strong' => array(),
	);
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
<body class="evcert-verify<?php echo $cert ? ' has-certificate' : ''; ?>">
	<?php if ( $cert ) : ?>
		<h1 class="screen-reader-text"><?php echo esc_html( $title ); ?></h1>
		<main class="evcert-verify__stage">
			<div class="evcert-verify__art" style="<?php echo esc_attr( sprintf( '--evcert-w:%.2F;--evcert-h:%.2F', $design['page']['w'], $design['page']['h'] ) ); ?>">
				<div class="evcert-verify__mode is-light"><?php echo Render\artwork( $design, $values, array( 'mode' => 'light' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Render\element_html(). ?></div>
				<div class="evcert-verify__mode is-dark"><?php echo Render\artwork( $design, $values, array( 'mode' => 'dark' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></div>
			</div>
		</main>

		<nav class="evcert-dock" data-evcert-dock aria-label="<?php esc_attr_e( 'Certificate tools', 'events-certificate' ); ?>">
			<button type="button" class="evcert-dock__fab <?php echo esc_attr( $class ); ?>" data-evcert-dock-to="center" aria-label="<?php echo esc_attr( $heading . ' — ' . __( 'Show tools', 'events-certificate' ) ); ?>" title="<?php echo esc_attr( $heading ); ?>"><?php echo icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
			<div class="evcert-dock__bar">
				<div class="evcert-dock__row">
					<button type="button" class="evcert-dock__status <?php echo esc_attr( $class ); ?>" data-evcert-pop="details" aria-expanded="false" title="<?php echo esc_attr( $heading ); ?>">
						<?php echo icon( 'is-valid' === $class ? 'check' : 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						<span><?php echo esc_html( $short ); ?></span>
					</button>
					<code class="evcert-dock__code"><?php echo esc_html( $cert['code'] ); ?></code>
					<span class="evcert-dock__spacer"></span>
					<button type="button" class="evcert-dock__icon" data-evcert-dock-to="left" aria-label="<?php esc_attr_e( 'Dock to the left corner', 'events-certificate' ); ?>" title="<?php esc_attr_e( 'Dock to the left corner', 'events-certificate' ); ?>"><?php echo icon( 'left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
					<button type="button" class="evcert-dock__icon" data-evcert-dock-to="right" aria-label="<?php esc_attr_e( 'Dock to the right corner', 'events-certificate' ); ?>" title="<?php esc_attr_e( 'Dock to the right corner', 'events-certificate' ); ?>"><?php echo icon( 'right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
				</div>
				<div class="evcert-dock__tools">
					<?php if ( 'issued' === $cert['status'] ) : ?>
						<a class="evcert-dock__tool" href="<?php echo esc_url( page_url( $cert['code'], $locale, array( 'format' => 'pdf' ) ) ); ?>"><?php echo icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span>PDF</span></a>
					<?php endif; ?>
					<button type="button" class="evcert-dock__tool" data-evcert-pop="share" aria-expanded="false"><?php echo icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Share', 'events-certificate' ); ?></span></button>
					<?php
					// Language: a globe with a name. With two languages it switches to the other (named); with more it opens a menu (named after the current one).
					$others = array_values( array_diff( locales(), array( $locale ) ) );
					?>
					<?php if ( 1 === count( $others ) ) : ?>
						<a class="evcert-dock__tool" href="<?php echo esc_url( page_url( $cert['code'], $others[0] ) ); ?>" hreflang="<?php echo esc_attr( str_replace( '_', '-', $others[0] ) ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $others[0] ) ); ?>"><?php echo icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( locale_name( $others[0] ) ); ?></span></a>
					<?php elseif ( count( $others ) > 1 ) : ?>
						<button type="button" class="evcert-dock__tool" data-evcert-pop="langs" aria-expanded="false" aria-label="<?php echo esc_attr( __( 'Language', 'events-certificate' ) . ': ' . locale_name( $locale ) ); ?>"><?php echo icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( locale_name( $locale ) ); ?></span></button>
					<?php endif; ?>
					<button type="button" class="evcert-dock__tool" data-evcert-mode data-to-light="<?php esc_attr_e( 'Light', 'events-certificate' ); ?>" data-to-dark="<?php esc_attr_e( 'Dark', 'events-certificate' ); ?>"><span class="evcert-dock__glyph" aria-hidden="true">☀︎</span><span class="evcert-dock__mode-label"><?php esc_html_e( 'Light', 'events-certificate' ); ?></span></button>
					<button type="button" class="evcert-dock__tool" data-evcert-pop="details" aria-expanded="false"><?php echo icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Details', 'events-certificate' ); ?></span></button>
				</div>
			</div>

			<div class="evcert-dock__pop" data-evcert-popover="details" hidden>
				<p class="evcert-dock__heading <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $heading ); ?></p>
				<?php if ( $detail ) : ?>
					<p class="evcert-dock__note"><?php echo wp_kses( $detail, $allowed ); ?></p>
				<?php endif; ?>
				<dl>
					<div><dt><?php esc_html_e( 'Name', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['name'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Event', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['event_title'] ); ?></dd></div>
					<?php if ( '' !== $values['hours'] ) : ?>
						<div><dt><?php esc_html_e( 'Workload', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\field_value( 'hours', '', $values ) ); ?></dd></div>
					<?php endif; ?>
					<div><dt><?php esc_html_e( 'Issued on', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\format_date( (int) $values['issued_ts'], 'site' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Code', 'events-certificate' ); ?></dt><dd><code><?php echo esc_html( $cert['code'] ); ?></code></dd></div>
				</dl>
			</div>
			<?php $share_url = page_url( $cert['code'], $locale ); ?>
			<div class="evcert-dock__pop is-menu" data-evcert-popover="share" hidden>
				<button type="button" class="evcert-dock__item" data-evcert-native-share="<?php echo esc_attr( $share_url ); ?>" data-title="<?php echo esc_attr( $title ); ?>" hidden><?php echo icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Share…', 'events-certificate' ); ?></span></button>
				<?php foreach ( share_links( $cert, $share_url ) as $key => $link ) : ?>
					<a class="evcert-dock__item is-<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $link[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link[0] ); ?></a>
				<?php endforeach; ?>
				<button type="button" class="evcert-dock__item" data-evcert-copy="<?php echo esc_attr( $share_url ); ?>" data-evcert-copied="<?php esc_attr_e( 'Link copied', 'events-certificate' ); ?>"><?php echo icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Copy link', 'events-certificate' ); ?></span></button>
			</div>
			<?php if ( count( locales() ) > 2 ) : ?>
				<div class="evcert-dock__pop is-menu" data-evcert-popover="langs" hidden>
					<?php foreach ( locales() as $l ) : ?>
						<a href="<?php echo esc_url( page_url( $cert['code'], $l ) ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $l ) ); ?>" <?php echo $l === $locale ? 'aria-current="true"' : ''; ?>><?php echo esc_html( locale_name( $l ) ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</nav>
	<?php else : ?>
		<main class="evcert-verify__card">
			<p class="evcert-verify__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			<h1><?php echo esc_html( '' !== $code || $invalid ? __( 'Certificate not found', 'events-certificate' ) : __( 'Check a certificate', 'events-certificate' ) ); ?></h1>
			<p><?php echo esc_html( '' !== $code || $invalid ? __( 'No certificate has this code. Check it and try again: it has 16 letters and numbers, like 2611-04K7-M29Q-XAP3.', 'events-certificate' ) : __( 'Type the code printed on the certificate, below its QR code.', 'events-certificate' ) ); ?></p>
			<form class="evcert-verify__form" method="get" action="<?php echo esc_url( home_url( user_trailingslashit( Settings\value( 'slug' ) ) ) ); ?>">
				<label class="screen-reader-text" for="evcert-code"><?php esc_html_e( 'Certificate code', 'events-certificate' ); ?></label>
				<input id="evcert-code" name="code" type="text" required autocomplete="off" spellcheck="false" placeholder="2611-04K7-M29Q-XAP3" value="<?php echo esc_attr( $code ); ?>">
				<button type="submit" class="evcert-verify__button"><?php esc_html_e( 'Check', 'events-certificate' ); ?></button>
			</form>
		</main>
	<?php endif; ?>
	<?php wp_print_scripts( array( 'evcert-verify' ) ); ?>
</body>
</html>
	<?php
}
