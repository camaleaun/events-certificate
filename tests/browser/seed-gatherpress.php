<?php
/**
 * Seed for the GatherPress browser run: pretty permalinks, a member who will RSVP (GatherPress asks a visitor to
 * log in), and the plugin's settings. The event itself is made in the block editor with the "Event with RSVP"
 * starter pattern (the roteiro does it): its RSVP block needs the inner blocks the editor creates.
 *
 *   bin/e2e-seed.sh gatherpress
 */
update_option( 'permalink_structure', '/%postname%/' );

if ( ! get_user_by( 'login', 'e2e.member' ) ) {
	wp_insert_user(
		array(
			'user_login'   => 'e2e.member',
			'user_pass'    => 'e2e-pass-123',
			'user_email'   => 'e2e.member@example.test',
			'display_name' => 'Joana Membra',
			'role'         => 'subscriber',
		)
	);
}

$settings                 = (array) get_option( 'evcert_settings', array() );
$settings['force_public'] = true;
update_option( 'evcert_settings', $settings );

flush_rewrite_rules();
echo "seeded: gatherpress, member e2e.member / e2e-pass-123\n";
