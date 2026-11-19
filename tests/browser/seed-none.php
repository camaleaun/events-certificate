<?php
/**
 * Seed for the "no integration" browser run: pretty permalinks and a certificate page that shows its code
 * (a local site is "not public", so the code and QR code are hidden unless forced).
 *
 *   bin/e2e-seed.sh none
 */
update_option( 'permalink_structure', '/%postname%/' );
$settings                 = (array) get_option( 'evcert_settings', array() );
$settings['force_public'] = true;
update_option( 'evcert_settings', $settings );
flush_rewrite_rules();
echo "seeded: none\n";
