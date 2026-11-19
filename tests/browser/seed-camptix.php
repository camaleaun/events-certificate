<?php
/**
 * Seed for the CampTix browser run: a page with [camptix], a free ticket, one day with tables for the
 * certificate's declaration, and who may get their own certificate.
 *
 *   bin/e2e-seed.sh camptix [attended|confirmed|off]
 */
$who = isset( $args[0] ) && in_array( $args[0], array( 'attended', 'confirmed', 'off' ), true ) ? $args[0] : 'attended';

update_option( 'permalink_structure', '/%postname%/' );

if ( ! get_page_by_path( 'tickets' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_name'    => 'tickets',
			'post_content' => '[camptix]',
		)
	);
}

$tickets = get_posts(
	array(
		'post_type'   => 'tix_ticket',
		'post_status' => 'publish',
		'numberposts' => 1,
	)
);
if ( ! $tickets ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => 'General Admission',
		)
	);
	update_post_meta( $id, 'tix_price', 0 );
	update_post_meta( $id, 'tix_quantity', 100 );
}

$options               = (array) get_option( 'camptix_options', array() );
$options['event_name'] = 'WordCamp E2E';
// CampTix will not sell without a payment method, even a free ticket; PayPal is never called for a $0 order.
$options['payment_methods'] = array( 'paypal' => true );
update_option( 'camptix_options', $options );

// One day with tables, today: the certificate step asks which day and table.
update_option(
	'evcert_days',
	array(
		array(
			'id'     => gmdate( 'Y-m-d' ),
			'date'   => gmdate( 'Y-m-d' ),
			'names'  => array( 'en_US' => 'Contributor Day' ),
			'mode'   => 'table',
			'tables' => array( 'en_US' => array( 'Core', 'Community' ) ),
		),
	)
);

$settings                 = (array) get_option( 'evcert_settings', array() );
$settings['force_public'] = true;
$settings['self_issue']   = $who;
$settings['location']     = 'E2E City';
update_option( 'evcert_settings', $settings );

flush_rewrite_rules();
echo "seeded: camptix, self_issue=$who\n";
