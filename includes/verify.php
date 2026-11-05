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
 * Prints the page.
 *
 * @param array<string, mixed>|null $cert   Certificate, or null.
 * @param string                    $code   Code asked for.
 * @param string                    $locale Display locale.
 * @param bool                      $invalid Whether a typed code was invalid.
 */
function render_page( $cert, $code, $locale, $invalid ) {
	$base = plugins_url( '', dirname( __DIR__ ) . '/events-certificate.php' );
	wp_register_style( 'evcert-verify', $base . '/assets/verify.css', array(), \EventsCertificate\VERSION );
	wp_add_inline_style( 'evcert-verify', Render\fonts_css( false ) );
	wp_register_script( 'evcert-verify', $base . '/assets/verify.js', array(), \EventsCertificate\VERSION, true );

	$title = $cert ? sprintf( /* translators: %s: person's name. */ __( 'Certificate: %s', 'events-certificate' ), $cert['name'] ) : __( 'Check a certificate', 'events-certificate' );
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
<body class="evcert-verify">
	<header class="evcert-verify__bar">
		<a class="evcert-verify__site" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		<span class="evcert-verify__what"><?php esc_html_e( 'Certificate check', 'events-certificate' ); ?></span>
		<?php if ( $cert && count( locales() ) > 1 ) : ?>
			<nav class="evcert-verify__langs" aria-label="<?php esc_attr_e( 'Language', 'events-certificate' ); ?>">
				<?php foreach ( locales() as $l ) : ?>
					<a href="<?php echo esc_url( page_url( $cert['code'], $l ) ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $l ) ); ?>" <?php echo $l === $locale ? 'aria-current="true"' : ''; ?>><?php echo esc_html( locale_name( $l ) ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
		<button type="button" class="evcert-verify__mode-toggle" data-evcert-mode data-to-light="<?php esc_attr_e( 'Light mode', 'events-certificate' ); ?>" data-to-dark="<?php esc_attr_e( 'Dark mode', 'events-certificate' ); ?>">☀︎</button>
	</header>
	<main class="evcert-verify__main">
		<?php if ( $cert ) : ?>
			<?php list( $class, $heading, $detail ) = status( $cert ); ?>
			<section class="evcert-verify__status <?php echo esc_attr( $class ); ?>" role="status">
				<h1><?php echo esc_html( $heading ); ?></h1>
				<?php if ( $detail ) : ?>
					<p>
					<?php
					echo wp_kses(
						$detail,
						array(
							'a'      => array( 'href' => true ),
							'strong' => array(),
						)
					);
					?>
						</p>
				<?php endif; ?>
			</section>
			<?php
			$design = Certificates\design( $cert );
			$values = Render\values( $cert );
			$ratio  = $design['page']['h'] / $design['page']['w'];
			?>
			<div class="evcert-verify__art" style="<?php echo esc_attr( sprintf( '--evcert-w:%.2F;--evcert-ratio:%.5F', $design['page']['w'], $ratio ) ); ?>">
				<div class="evcert-verify__mode is-light"><?php echo Render\artwork( $design, $values, array( 'mode' => 'light' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Render\element_html(). ?></div>
				<div class="evcert-verify__mode is-dark"><?php echo Render\artwork( $design, $values, array( 'mode' => 'dark' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></div>
			</div>
			<dl class="evcert-verify__data">
				<div><dt><?php esc_html_e( 'Name', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['name'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Event', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['event_title'] ); ?></dd></div>
				<?php if ( '' !== $values['hours'] ) : ?>
					<div><dt><?php esc_html_e( 'Workload', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\field_value( 'hours', '', $values ) ); ?></dd></div>
				<?php endif; ?>
				<div><dt><?php esc_html_e( 'Issued on', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\format_date( (int) $values['issued_ts'], 'site' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Code', 'events-certificate' ); ?></dt><dd><code><?php echo esc_html( $cert['code'] ); ?></code></dd></div>
			</dl>
			<p class="evcert-verify__actions">
				<?php if ( 'issued' === $cert['status'] ) : ?>
					<a class="evcert-verify__button is-primary" href="<?php echo esc_url( page_url( $cert['code'], $locale, array( 'format' => 'pdf' ) ) ); ?>"><?php esc_html_e( 'Download PDF', 'events-certificate' ); ?></a>
				<?php endif; ?>
				<button type="button" class="evcert-verify__button" data-evcert-copy="<?php echo esc_attr( page_url( $cert['code'], $locale ) ); ?>" data-evcert-copied="<?php esc_attr_e( 'Link copied', 'events-certificate' ); ?>"><?php esc_html_e( 'Copy link', 'events-certificate' ); ?></button>
			</p>
		<?php else : ?>
			<section class="evcert-verify__status <?php echo '' !== $code || $invalid ? 'is-revoked' : ''; ?>">
				<h1><?php echo esc_html( '' !== $code || $invalid ? __( 'Certificate not found', 'events-certificate' ) : __( 'Check a certificate', 'events-certificate' ) ); ?></h1>
				<p><?php echo esc_html( '' !== $code || $invalid ? __( 'No certificate has this code. Check it and try again: it has 16 letters and numbers, like 2611-04K7-M29Q-XAP3.', 'events-certificate' ) : __( 'Type the code printed on the certificate, below its QR code.', 'events-certificate' ) ); ?></p>
			</section>
			<form class="evcert-verify__form" method="get" action="<?php echo esc_url( home_url( user_trailingslashit( Settings\value( 'slug' ) ) ) ); ?>">
				<label for="evcert-code"><?php esc_html_e( 'Certificate code', 'events-certificate' ); ?></label>
				<input id="evcert-code" name="code" type="text" required autocomplete="off" spellcheck="false" placeholder="2611-04K7-M29Q-XAP3" value="<?php echo esc_attr( $code ); ?>">
				<button type="submit" class="evcert-verify__button is-primary"><?php esc_html_e( 'Check', 'events-certificate' ); ?></button>
			</form>
		<?php endif; ?>
	</main>
	<?php wp_print_scripts( array( 'evcert-verify' ) ); ?>
</body>
</html>
	<?php
}
