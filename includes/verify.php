<?php
/**
 * The certificate page: /{slug}/{code}/ (slug from the settings, "certificate"
 * by default), with /certificate/{code}/ and /verify/{code}/ always working too. Anyone with the code sees the certificate,
 * as it is on record (to compare with the copy at hand), replaced or
 * revoked, in any of the site's
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
use EventsCertificate\ShareImage;

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
	add_action( 'admin_init', __NAMESPACE__ . '\store_locale_names' );
}

/**
 * Option with the native names of the site's languages, for the public page.
 */
const NAMES_OPTION = 'evcert_locale_names';

/**
 * Keeps the native names of the site's languages ("Português do Brasil"), as
 * WordPress's language selector shows them. They come from WordPress's list
 * of translations (wp_get_available_translations(), cached by core for hours);
 * fetched in the admin only when a language is missing, at most once a day.
 */
function store_locale_names() {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$names   = get_option( NAMES_OPTION, array() );
	$names   = is_array( $names ) ? $names : array();
	$missing = array_diff( locales(), array_keys( $names ), array( 'en_US' ) );
	if ( ! $missing || get_transient( 'evcert_locale_names_tried' ) ) {
		return;
	}
	set_transient( 'evcert_locale_names_tried', 1, DAY_IN_SECONDS );
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	foreach ( wp_get_available_translations() as $locale => $translation ) {
		if ( in_array( $locale, $missing, true ) && ! empty( $translation['native_name'] ) ) {
			$names[ $locale ] = (string) $translation['native_name'];
		}
	}
	update_option( NAMES_OPTION, $names, false );
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
 * A locale's name in its own language, as WordPress's language selector shows
 * it ("Português do Brasil", "English (United States)"): the names kept by
 * store_locale_names() (no request is made here), else from Intl.
 *
 * @param string $locale Locale.
 * @return string
 */
function locale_name( $locale ) {
	if ( 'en_US' === $locale ) {
		return 'English (United States)';
	}
	$names = get_option( NAMES_OPTION, array() );
	if ( is_array( $names ) && ! empty( $names[ $locale ] ) ) {
		return (string) $names[ $locale ];
	}
	if ( class_exists( 'Locale' ) ) {
		$name = \Locale::getDisplayName( $locale, $locale );
		if ( $name ) {
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 ) : ucfirst( $name );
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

	// "On record", not "authentic": a valid code proves the record exists, not that a printed copy wasn't
	// altered (a real QR code on a copy with another name). Whoever checks compares the copy with this page.
	return array(
		'is-valid',
		__( 'Certificate on record', 'events-certificate' ),
		'',
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
	// Brand icons from Gutenberg's Social Icons block (block-library/src/social-link/icons).
	'linkedin' => 'M19.7,3H4.3C3.582,3,3,3.582,3,4.3v15.4C3,20.418,3.582,21,4.3,21h15.4c0.718,0,1.3-0.582,1.3-1.3V4.3 C21,3.582,20.418,3,19.7,3z M8.339,18.338H5.667v-8.59h2.672V18.338z M7.004,8.574c-0.857,0-1.549-0.694-1.549-1.548 c0-0.855,0.691-1.548,1.549-1.548c0.854,0,1.547,0.694,1.547,1.548C8.551,7.881,7.858,8.574,7.004,8.574z M18.339,18.338h-2.669 v-4.177c0-0.996-0.017-2.278-1.387-2.278c-1.389,0-1.601,1.086-1.601,2.206v4.249h-2.667v-8.59h2.559v1.174h0.037 c0.356-0.675,1.227-1.387,2.526-1.387c2.703,0,3.203,1.779,3.203,4.092V18.338z',
	'x'        => 'M13.982 10.622 20.54 3h-1.554l-5.693 6.618L8.745 3H3.5l6.876 10.007L3.5 21h1.554l6.012-6.989L15.868 21h5.245l-7.131-10.378Zm-2.128 2.474-.697-.997-5.543-7.93H8l4.474 6.4.697.996 5.815 8.318h-2.387l-4.745-6.787Z',
	'facebook' => 'M12 2C6.5 2 2 6.5 2 12c0 5 3.7 9.1 8.4 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7C18.3 21.1 22 17 22 12c0-5.5-4.5-10-10-10z',
	'whatsapp' => 'M 12.011719 2 C 6.5057187 2 2.0234844 6.478375 2.0214844 11.984375 C 2.0204844 13.744375 2.4814687 15.462563 3.3554688 16.976562 L 2 22 L 7.2324219 20.763672 C 8.6914219 21.559672 10.333859 21.977516 12.005859 21.978516 L 12.009766 21.978516 C 17.514766 21.978516 21.995047 17.499141 21.998047 11.994141 C 22.000047 9.3251406 20.962172 6.8157344 19.076172 4.9277344 C 17.190172 3.0407344 14.683719 2.001 12.011719 2 z M 12.009766 4 C 14.145766 4.001 16.153109 4.8337969 17.662109 6.3417969 C 19.171109 7.8517969 20.000047 9.8581875 19.998047 11.992188 C 19.996047 16.396187 16.413812 19.978516 12.007812 19.978516 C 10.674812 19.977516 9.3544062 19.642812 8.1914062 19.007812 L 7.5175781 18.640625 L 6.7734375 18.816406 L 4.8046875 19.28125 L 5.2851562 17.496094 L 5.5019531 16.695312 L 5.0878906 15.976562 C 4.3898906 14.768562 4.0204844 13.387375 4.0214844 11.984375 C 4.0234844 7.582375 7.6067656 4 12.009766 4 z M 8.4765625 7.375 C 8.3095625 7.375 8.0395469 7.4375 7.8105469 7.6875 C 7.5815469 7.9365 6.9355469 8.5395781 6.9355469 9.7675781 C 6.9355469 10.995578 7.8300781 12.182609 7.9550781 12.349609 C 8.0790781 12.515609 9.68175 15.115234 12.21875 16.115234 C 14.32675 16.946234 14.754891 16.782234 15.212891 16.740234 C 15.670891 16.699234 16.690438 16.137687 16.898438 15.554688 C 17.106437 14.971687 17.106922 14.470187 17.044922 14.367188 C 16.982922 14.263188 16.816406 14.201172 16.566406 14.076172 C 16.317406 13.951172 15.090328 13.348625 14.861328 13.265625 C 14.632328 13.182625 14.464828 13.140625 14.298828 13.390625 C 14.132828 13.640625 13.655766 14.201187 13.509766 14.367188 C 13.363766 14.534188 13.21875 14.556641 12.96875 14.431641 C 12.71875 14.305641 11.914938 14.041406 10.960938 13.191406 C 10.218937 12.530406 9.7182656 11.714844 9.5722656 11.464844 C 9.4272656 11.215844 9.5585938 11.079078 9.6835938 10.955078 C 9.7955938 10.843078 9.9316406 10.663578 10.056641 10.517578 C 10.180641 10.371578 10.223641 10.267562 10.306641 10.101562 C 10.389641 9.9355625 10.347156 9.7890625 10.285156 9.6640625 C 10.223156 9.5390625 9.737625 8.3065 9.515625 7.8125 C 9.328625 7.3975 9.131125 7.3878594 8.953125 7.3808594 C 8.808125 7.3748594 8.6425625 7.375 8.4765625 7.375 z',
	'email'    => 'M19 5H5c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm.5 12c0 .3-.2.5-.5.5H5c-.3 0-.5-.2-.5-.5V9.8l7.5 5.6 7.5-5.6V17zm0-9.1L12 13.6 4.5 7.9V7c0-.3.2-.5.5-.5h14c.3 0 .5.2.5.5v.9z',
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
	// Icons with a hole (info, copy) need the even-odd rule, as in @wordpress/icons.
	$rule = in_array( $name, array( 'info', 'copy' ), true ) ? ' fill-rule="evenodd" clip-rule="evenodd"' : '';

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
			'is-valid'    => __( 'On record', 'events-certificate' ),
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
	<?php if ( $cert ) : ?>
		<?php
		// Social previews (LinkedIn, Facebook, X, WhatsApp): the certificate image when generated, else the site icon.
		$og_image = ShareImage\url( $cert['code'], $locale );
		$og_image = $og_image ? $og_image : get_site_icon_url( 512 );
		?>
		<meta property="og:type" content="website">
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
		<meta property="og:description" content="<?php echo esc_attr( $cert['event_title'] ); ?>">
		<meta property="og:url" content="<?php echo esc_url( page_url( $cert['code'], $locale ) ); ?>">
		<meta property="og:locale" content="<?php echo esc_attr( $locale ); ?>">
		<?php if ( $og_image ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>">
			<?php if ( ShareImage\url( $cert['code'], $locale ) ) : ?>
				<meta property="og:image:width" content="<?php echo esc_attr( (string) ShareImage\WIDTH ); ?>">
				<meta property="og:image:height" content="<?php echo esc_attr( (string) ShareImage\HEIGHT ); ?>">
				<meta property="og:image:alt" content="<?php echo esc_attr( $title ); ?>">
			<?php endif; ?>
		<?php endif; ?>
		<meta name="twitter:card" content="<?php echo ShareImage\url( $cert['code'], $locale ) ? 'summary_large_image' : 'summary'; ?>">
	<?php endif; ?>
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

		<?php
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the attendee's edit token is the credential, checked in ShareImage\can_send().
		$attendee_id = isset( $_GET['tix_attendee_id'] ) ? absint( $_GET['tix_attendee_id'] ) : 0;
		$edit_token  = isset( $_GET['tix_edit_token'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_edit_token'] ) ) : '';
		// phpcs:enable
		$make_image = 'issued' === $cert['status'] && ! ShareImage\url( $cert['code'], $locale ) && ShareImage\can_send( $cert, $attendee_id, $edit_token );
		?>
		<?php if ( $make_image ) : ?>
			<div hidden data-evcert-share-image="
			<?php
			echo esc_attr(
				(string) wp_json_encode(
					array(
						'endpoint'        => rest_url( 'evcert/v1/share-image' ),
						'nonce'           => wp_create_nonce( 'wp_rest' ),
						'code'            => $cert['code'],
						'locale'          => $locale,
						'width'           => ShareImage\WIDTH,
						'height'          => ShareImage\HEIGHT,
						'fonts'           => plugins_url( 'fonts/', dirname( __DIR__ ) . '/events-certificate.php' ),
						'tix_attendee_id' => $attendee_id,
						'tix_edit_token'  => $edit_token,
					)
				)
			);
			?>
			"></div>
		<?php endif; ?>
		<nav class="evcert-dock" data-evcert-dock aria-label="<?php esc_attr_e( 'Certificate tools', 'events-certificate' ); ?>">
			<button type="button" class="evcert-dock__fab <?php echo esc_attr( $class ); ?>" data-evcert-dock-to="center" aria-label="<?php echo esc_attr( $heading . ' — ' . __( 'Show tools', 'events-certificate' ) ); ?>" title="<?php echo esc_attr( $heading ); ?>"><?php echo icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
			<div class="evcert-dock__bar">
				<div class="evcert-dock__row">
					<?php if ( 'is-valid' !== $class ) : ?>
						<button type="button" class="evcert-dock__status <?php echo esc_attr( $class ); ?>" data-evcert-pop="details" aria-expanded="false" title="<?php echo esc_attr( $heading ); ?>">
							<?php echo icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<span><?php echo esc_html( $short ); ?></span>
						</button>
					<?php endif; ?>
					<?php // Sites that aren't public show no code (docs/decisions/0010): the button keeps only its icon. ?>
					<button type="button" class="evcert-dock__code <?php echo esc_attr( $class ); ?>" data-evcert-pop="details" aria-expanded="false" aria-label="<?php echo esc_attr( Render\verifiable() ? __( 'Details', 'events-certificate' ) . ' — ' . $cert['code'] : __( 'Details', 'events-certificate' ) ); ?>"><?php echo icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php if ( Render\verifiable() ) : ?><span><?php echo esc_html( $cert['code'] ); ?></span><?php endif; ?></button>
					<button type="button" class="evcert-dock__icon" data-evcert-dock-to="left" aria-label="<?php esc_attr_e( 'Dock to the left corner', 'events-certificate' ); ?>" title="<?php esc_attr_e( 'Dock to the left corner', 'events-certificate' ); ?>"><?php echo icon( 'left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
					<button type="button" class="evcert-dock__icon" data-evcert-dock-to="right" aria-label="<?php esc_attr_e( 'Dock to the right corner', 'events-certificate' ); ?>" title="<?php esc_attr_e( 'Dock to the right corner', 'events-certificate' ); ?>"><?php echo icon( 'right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
				</div>
				<div class="evcert-dock__tools">
					<?php if ( 'issued' === $cert['status'] ) : ?>
						<a class="evcert-dock__tool" href="<?php echo esc_url( page_url( $cert['code'], $locale, array( 'format' => 'pdf' ) ) ); ?>"><?php echo icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span>PDF</span></a>
					<?php endif; ?>
					<button type="button" class="evcert-dock__tool" data-evcert-pop="share" aria-expanded="false"><?php echo icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Share', 'events-certificate' ); ?></span></button>
					<?php
					// Language: a globe with the current language's name. With two languages a click switches to the other; with more it opens a menu.
					$others = array_values( array_diff( locales(), array( $locale ) ) );
					?>
					<?php if ( 1 === count( $others ) ) : ?>
						<a class="evcert-dock__tool" href="<?php echo esc_url( page_url( $cert['code'], $others[0] ) ); ?>" hreflang="<?php echo esc_attr( str_replace( '_', '-', $others[0] ) ); ?>" title="<?php echo esc_attr( locale_name( $others[0] ) ); ?>" aria-label="<?php echo esc_attr( __( 'Language', 'events-certificate' ) . ': ' . locale_name( $locale ) . ' — ' . locale_name( $others[0] ) ); ?>"><?php echo icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( locale_name( $locale ) ); ?></span></a>
					<?php elseif ( count( $others ) > 1 ) : ?>
						<button type="button" class="evcert-dock__tool" data-evcert-pop="langs" aria-expanded="false" aria-label="<?php echo esc_attr( __( 'Language', 'events-certificate' ) . ': ' . locale_name( $locale ) ); ?>"><?php echo icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( locale_name( $locale ) ); ?></span></button>
					<?php endif; ?>
					<button type="button" class="evcert-dock__tool" data-evcert-mode data-to-light="<?php esc_attr_e( 'Light', 'events-certificate' ); ?>" data-to-dark="<?php esc_attr_e( 'Dark', 'events-certificate' ); ?>"><span class="evcert-dock__glyph" aria-hidden="true">☀︎</span><span class="evcert-dock__mode-label"><?php esc_html_e( 'Light', 'events-certificate' ); ?></span></button>
					<button type="button" class="evcert-dock__tool" data-evcert-pop="details" aria-expanded="false"><?php echo icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Details', 'events-certificate' ); ?></span></button>
				</div>
			</div>

			<div class="evcert-dock__pop" data-evcert-popover="details" hidden>
				<?php if ( 'is-valid' !== $class ) : ?>
					<p class="evcert-dock__heading <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $heading ); ?></p>
					<?php if ( $detail ) : ?>
						<p class="evcert-dock__note"><?php echo wp_kses( $detail, $allowed ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<dl>
					<div><dt><?php esc_html_e( 'Name', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['name'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Event', 'events-certificate' ); ?></dt><dd><?php echo esc_html( $cert['event_title'] ); ?></dd></div>
					<?php if ( '' !== $values['hours'] ) : ?>
						<div><dt><?php esc_html_e( 'Workload', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\field_value( 'hours', '', $values ) ); ?></dd></div>
					<?php endif; ?>
					<div><dt><?php esc_html_e( 'Issued on', 'events-certificate' ); ?></dt><dd><?php echo esc_html( Render\format_date( (int) $values['issued_ts'], 'site' ) ); ?></dd></div>
					<?php if ( Render\verifiable() ) : ?>
						<div><dt><?php esc_html_e( 'Code', 'events-certificate' ); ?></dt><dd><code><?php echo esc_html( $cert['code'] ); ?></code></dd></div>
					<?php endif; ?>
				</dl>
			</div>
			<?php $share_url = page_url( $cert['code'], $locale ); ?>
			<div class="evcert-dock__pop is-menu" data-evcert-popover="share" hidden>
				<button type="button" class="evcert-dock__item" data-evcert-native-share="<?php echo esc_attr( $share_url ); ?>" data-title="<?php echo esc_attr( $title ); ?>" hidden><?php echo icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Share…', 'events-certificate' ); ?></span></button>
				<?php foreach ( share_links( $cert, $share_url ) as $key => $link ) : ?>
					<a class="evcert-dock__item is-<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $link[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo icon( 'linkedin-profile' === $key ? 'linkedin' : $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $link[0] ); ?></span></a>
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
