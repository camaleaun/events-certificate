<?php
/**
 * Countries, ISO 3166-1 alpha-2 code => name, translatable (like WooCommerce's
 * i18n/countries.php), only the ones that have an identification document
 * (includes/documents.php, decision 0027).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * The countries the plugin has a document for (includes/documents.php), sorted by name in the current language.
 * Only the ones used: a site adds more with the evcert_countries filter, together with a document for each.
 *
 * @return array<string, string>
 */
function all() {
	$countries = array(
		'AR' => __( 'Argentina', 'events-certificates' ),
		'BR' => __( 'Brazil', 'events-certificates' ),
		'CL' => __( 'Chile', 'events-certificates' ),
		'CO' => __( 'Colombia', 'events-certificates' ),
		'ES' => __( 'Spain', 'events-certificates' ),
		'MX' => __( 'Mexico', 'events-certificates' ),
		'PE' => __( 'Peru', 'events-certificates' ),
		'PT' => __( 'Portugal', 'events-certificates' ),
		'UY' => __( 'Uruguay', 'events-certificates' ),
	);

	/**
	 * Filters the countries (ISO 3166-1 alpha-2 code => name).
	 *
	 * @param array<string, string> $countries Countries.
	 */
	$countries = (array) apply_filters( 'evcert_countries', $countries );

	if ( class_exists( 'Collator' ) ) {
		$collator = new \Collator( determine_locale() );
		uasort( $countries, array( $collator, 'compare' ) );
	} else {
		asort( $countries );
	}

	return $countries;
}

/**
 * A country's name.
 *
 * @param string $code Code.
 * @return string
 */
function name( $code ) {
	$all = all();

	return $all[ strtoupper( (string) $code ) ] ?? '';
}

/**
 * The country to start with: the site language's region (pt_BR → BR) when the site accepts it, else none.
 *
 * @return string
 */
function guess() {
	$region = strtoupper( (string) substr( (string) strrchr( determine_locale(), '_' ), 1 ) );

	return isset( \EventsCertificate\Documents\enabled()[ $region ] ) && isset( all()[ $region ] ) ? $region : '';
}
