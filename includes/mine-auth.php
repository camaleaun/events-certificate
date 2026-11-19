<?php
/**
 * "Get my certificate": how someone proves the e-mail is theirs
 * (docs/decisions/0030). Five modes, one setting (email_auth):
 *
 * - none: the e-mail is enough.
 * - simple (default): the e-mail shows a simplified view; the full certificate
 *   asks to authenticate with a 6-digit code and a link, mailed.
 * - digits4, digits6: a numeric code, mailed (and the link too, with email_auth_link).
 * - link: a one-use link, mailed.
 *
 * A code is kept as a hash for 15 minutes and burns after 5 wrong tries; a link
 * works once for an hour. A proven e-mail becomes a signed cookie (HMAC, no
 * WordPress user). Asking for mail is limited per e-mail and address.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\MineAuth;

use EventsCertificate\Mine;
use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cookie of the signed session.
 */
const COOKIE = 'evcert_mine';

/**
 * Query argument of the mailed link.
 */
const TOKEN_ARG = 'evcert_mine_token';

/**
 * Minutes a code is good for.
 */
const CODE_MINUTES = 15;

/**
 * Wrong tries a code allows.
 */
const MAX_TRIES = 5;

/**
 * Mails asked for, per e-mail and address, in CODE_MINUTES.
 */
const MAX_MAILS = 5;

/**
 * Hours a session lasts.
 */
const SESSION_HOURS = 12;

/**
 * The modes.
 *
 * @return string[]
 */
function modes() {
	return array( 'none', 'simple', 'digits4', 'digits6', 'link' );
}

/**
 * The mode in force.
 *
 * @return string
 */
function mode() {
	$mode = (string) apply_filters( 'evcert_email_auth', (string) Settings\value( 'email_auth' ) );

	return in_array( $mode, modes(), true ) ? $mode : 'simple';
}

/**
 * Digits of the code the mode mails (0 for none).
 *
 * @return int
 */
function digits() {
	$mode = mode();

	return 'digits4' === $mode ? 4 : ( 'digits6' === $mode || 'simple' === $mode ? 6 : 0 );
}

/**
 * Whether the mail carries the link.
 *
 * @return bool
 */
function with_link() {
	$mode = mode();

	return 'link' === $mode || 'simple' === $mode || ( digits() > 0 && (bool) Settings\value( 'email_auth_link' ) );
}

/**
 * Whether the mode mails anything to prove the e-mail.
 *
 * @return bool
 */
function mails() {
	return 'none' !== mode();
}

/**
 * Normalized e-mail.
 *
 * @param string $email E-mail.
 * @return string
 */
function normalize( $email ) {
	return strtolower( trim( (string) $email ) );
}

/**
 * Key of a transient.
 *
 * @param string $kind  code, link or rate.
 * @param string $value E-mail or token.
 * @return string
 */
function transient_key( $kind, $value ) {
	return 'evcert_mine_' . $kind . '_' . substr( hash( 'sha256', normalize( $value ) ), 0, 32 );
}

/**
 * The visitor's address, for the limit.
 *
 * @return string
 */
function address() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Counts a request and says whether it may go on.
 *
 * @param string $email E-mail.
 * @return bool
 */
function allow_request( $email ) {
	$key   = transient_key( 'rate', normalize( $email ) . '|' . address() );
	$count = (int) get_transient( $key );
	if ( $count >= MAX_MAILS ) {
		return false;
	}
	set_transient( $key, $count + 1, CODE_MINUTES * MINUTE_IN_SECONDS );

	return true;
}

/**
 * The hash a code is kept as.
 *
 * @param string $email E-mail.
 * @param string $code  Code.
 * @return string
 */
function code_hash( $email, $code ) {
	return hash_hmac( 'sha256', normalize( $email ) . '|' . $code, wp_salt( 'auth' ) );
}

/**
 * Sends what proves the e-mail: the numeric code, the link or both, as the mode says.
 *
 * @param string $email E-mail (already on record: the caller checked).
 * @return bool False when the limit was reached or the mail failed.
 */
function start( $email ) {
	$email = normalize( $email );
	if ( ! is_email( $email ) || ! allow_request( $email ) ) {
		return false;
	}
	$code   = '';
	$length = digits();
	if ( $length > 0 ) {
		for ( $i = 0; $i < $length; ++$i ) {
			$code .= (string) random_int( 0, 9 );
		}
		set_transient(
			transient_key( 'code', $email ),
			array(
				'hash'    => code_hash( $email, $code ),
				'tries'   => 0,
				'expires' => time() + CODE_MINUTES * MINUTE_IN_SECONDS,
			),
			CODE_MINUTES * MINUTE_IN_SECONDS
		);
	}
	$link = '';
	if ( with_link() ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( transient_key( 'link', $token ), $email, HOUR_IN_SECONDS );
		$link = add_query_arg( TOKEN_ARG, $token, Mine\url() );
	}

	return send( $email, $code, $link );
}

/**
 * Mails the code and/or the link, in CampTix's voice when CampTix is there.
 *
 * @param string $email E-mail.
 * @param string $code  Code ('' for none).
 * @param string $link  Link ('' for none).
 * @return bool
 */
function send( $email, $code, $link ) {
	$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$lines = array( __( 'Hi,', 'events-certificates' ), '' );
	if ( '' !== $code ) {
		/* translators: %d: minutes. */
		$lines[] = sprintf( __( 'Your code to get your certificate is below. It is good for %d minutes.', 'events-certificates' ), CODE_MINUTES );
		$lines[] = '';
		$lines[] = $code;
		$lines[] = '';
	}
	if ( '' !== $link ) {
		$lines[] = '' !== $code ? __( 'Or open this link (it works once, for an hour):', 'events-certificates' ) : __( 'Open this link to get your certificate (it works once, for an hour):', 'events-certificates' );
		$lines[] = $link;
		$lines[] = '';
	}
	$lines[] = __( 'If you did not ask for this, you can ignore this e-mail.', 'events-certificates' );
	$lines[] = '';
	$lines[] = $site;
	/* translators: %s: site name. */
	$subject = sprintf( __( '[%s] Your certificate', 'events-certificates' ), $site );

	/**
	 * Filters the e-mail that proves who is asking for a certificate.
	 *
	 * @param array{to: string, subject: string, message: string} $mail  E-mail.
	 * @param string                                              $email Address.
	 * @param string                                              $code  The numeric code ('' in link mode).
	 * @param string                                              $link  The link ('' when none).
	 */
	$mail = (array) apply_filters(
		'evcert_mine_email',
		array(
			'to'      => $email,
			'subject' => $subject,
			'message' => implode( "\n", $lines ),
		),
		$email,
		$code,
		$link
	);
	$tix  = \EventsCertificate\Compat\CampTix\camptix();
	if ( $tix && method_exists( $tix, 'wp_mail' ) ) {
		return (bool) $tix->wp_mail( $mail['to'], $mail['subject'], $mail['message'] );
	}

	return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['message'] );
}

/**
 * Checks a typed code. A right code works once; five wrong tries burn it.
 *
 * @param string $email E-mail.
 * @param string $typed What was typed.
 * @return bool
 */
function verify( $email, $typed ) {
	$email  = normalize( $email );
	$key    = transient_key( 'code', $email );
	$record = get_transient( $key );
	if ( ! is_array( $record ) ) {
		return false;
	}
	if ( (int) $record['expires'] < time() || (int) $record['tries'] >= MAX_TRIES ) {
		delete_transient( $key );
		return false;
	}
	$typed = preg_replace( '/\D+/', '', (string) $typed );
	if ( '' !== $typed && hash_equals( (string) $record['hash'], code_hash( $email, (string) $typed ) ) ) {
		delete_transient( $key );
		return true;
	}
	++$record['tries'];
	set_transient( $key, $record, CODE_MINUTES * MINUTE_IN_SECONDS );

	return false;
}

/**
 * Redeems a mailed link: the e-mail it was for, once.
 *
 * @param string $token Token from the link.
 * @return string E-mail, '' when unknown, used or expired.
 */
function redeem( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( '' === $token ) {
		return '';
	}
	$key   = transient_key( 'link', $token );
	$email = get_transient( $key );
	if ( ! is_string( $email ) || '' === $email ) {
		return '';
	}
	delete_transient( $key );

	return $email;
}

/**
 * The signed value of a session.
 *
 * @param string $email   E-mail.
 * @param int    $expires Unix time it ends.
 * @return string
 */
function session_value( $email, $expires ) {
	$body = rtrim( strtr( base64_encode( normalize( $email ) ), '+/', '-_' ), '=' ) . '.' . (int) $expires; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a cookie value, not obfuscation.

	return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
}

/**
 * The e-mail of a session value, when it is genuine and not over.
 *
 * @param string $value Cookie value.
 * @return string '' otherwise.
 */
function read_session( $value ) {
	$parts = explode( '.', (string) $value );
	if ( 3 !== count( $parts ) ) {
		return '';
	}
	list( $name, $expires, $sig ) = $parts;
	if ( ! hash_equals( hash_hmac( 'sha256', $name . '.' . $expires, wp_salt( 'auth' ) ), $sig ) || (int) $expires < time() ) {
		return '';
	}
	$email = base64_decode( strtr( $name, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- our own cookie value.

	return is_string( $email ) && is_email( $email ) ? $email : '';
}

/**
 * Remembers a proven e-mail in this browser.
 *
 * @param string $email E-mail.
 */
function grant( $email ) {
	$expires = time() + SESSION_HOURS * HOUR_IN_SECONDS;
	$value   = session_value( $email, $expires );
	if ( ! headers_sent() ) {
		setcookie(
			COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
	$_COOKIE[ COOKIE ] = $value;
}

/**
 * Forgets the session.
 */
function revoke() {
	if ( ! headers_sent() ) {
		setcookie(
			COOKIE,
			'',
			array(
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
	unset( $_COOKIE[ COOKIE ] );
}

/**
 * The e-mail this browser proved, if any.
 *
 * @return string
 */
function current_email() {
	return isset( $_COOKIE[ COOKIE ] ) ? read_session( sanitize_text_field( wp_unslash( $_COOKIE[ COOKIE ] ) ) ) : '';
}
