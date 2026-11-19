<?php
/**
 * Dates and times as a certificate writes them: the formats of Settings →
 * General, like every date WordPress shows. The plugin has no translatable
 * formats of its own (translators changing the case of a format string, "g" to
 * "G", are refused by GlotPress, and a format is a choice of the site, not of
 * the language): the month and day names come out in the language shown, the
 * order and the clock are the site's.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The site's language (Settings → General), not the one being shown.
 *
 * @return string
 */
function site_locale() {
	$locale = (string) get_option( 'WPLANG' );

	return '' !== $locale ? $locale : 'en_US';
}

/**
 * The site's date format (Settings → General).
 *
 * @return string
 */
function date_format() {
	return (string) get_option( 'date_format' );
}

/**
 * The site's time format (Settings → General).
 *
 * @return string
 */
function time_format() {
	return (string) get_option( 'time_format' );
}

/**
 * A date in the site's format, with the names of the language shown.
 *
 * @param int $ts Timestamp.
 * @return string
 */
function date_text( $ts ) {
	return wp_date( date_format(), (int) $ts );
}

/**
 * A time of day in the site's format.
 *
 * @param int $ts Timestamp.
 * @return string
 */
function time_text( $ts ) {
	return wp_date( time_format(), (int) $ts );
}

/**
 * A short day and month, as on a badge: the month first when the site's date format starts with it.
 *
 * @param int $ts Timestamp.
 * @return string
 */
function short_date( $ts ) {
	return wp_date( preg_match( '/^[mnFMl]/', date_format() ) ? 'm/d' : 'd/m', (int) $ts );
}

/**
 * A UTC datetime ("2026-11-14 12:00:00") as a timestamp.
 *
 * @param string $datetime Datetime in UTC.
 * @return int
 */
function utc_ts( $datetime ) {
	return (int) strtotime( $datetime . ' UTC' );
}
