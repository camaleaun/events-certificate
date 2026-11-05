<?php
/**
 * CampTix (WordCamps): Certificates goes where organizers already look.
 *
 * - Tickets > Certificates: the screen, right after Attendees.
 * - Tickets > Setup > Certificates: the settings, as a Setup tab, saved by
 *   CampTix's own form (settings group camptix_options).
 *
 * Only the plugin's own filters and CampTix's public hooks are used, so
 * CampTix could replace this integration with its own:
 * remove_action( 'evcert_register_compat', 'EventsCertificate\Compat\CampTix\register' ).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\CampTix;

use EventsCertificate\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * CampTix's menu (its tickets post type screen).
 */
const MENU = 'edit.php?post_type=tix_ticket';

/**
 * Setup tab key (?tix_section=).
 */
const SECTION = 'certificates';

/**
 * Hooks, when CampTix is active.
 */
function register() {
	if ( ! class_exists( 'CampTix_Plugin' ) ) {
		return;
	}
	add_filter( 'evcert_admin_menu_parent', __NAMESPACE__ . '\menu_parent' );
	add_filter( 'evcert_settings_group', __NAMESPACE__ . '\settings_group' );
	add_action( 'admin_menu', __NAMESPACE__ . '\move_after_attendees', 99 );
	add_filter( 'camptix_setup_sections', __NAMESPACE__ . '\add_setup_tab' );
	add_action( 'camptix_menu_setup_controls', __NAMESPACE__ . '\setup_controls' );
	remove_action( 'evcert_certificates_screen', 'EventsCertificate\Admin\render_settings_form' );
	add_action( 'evcert_certificates_title_actions', __NAMESPACE__ . '\render_settings_link' );
	register_front();
}

/**
 * Certificates is a submenu of Tickets.
 *
 * @return string
 */
function menu_parent() {
	return MENU;
}

/**
 * The settings are saved by CampTix's Setup form.
 *
 * @return string
 */
function settings_group() {
	return 'camptix_options';
}

/**
 * Moves Tickets > Certificates right after Attendees. CampTix's submenu mixes
 * post type screens and add_submenu_page() items, so the position is found,
 * not fixed. Without Attendees the item stays last.
 */
function move_after_attendees() {
	global $submenu;

	if ( empty( $submenu[ MENU ] ) ) {
		return;
	}
	$items = array_values( $submenu[ MENU ] );
	$ours  = null;
	foreach ( $items as $index => $item ) {
		if ( Admin\SLUG === $item[2] ) {
			$ours = $item;
			unset( $items[ $index ] );
			break;
		}
	}
	if ( null === $ours ) {
		return;
	}
	$items    = array_values( $items );
	$position = count( $items );
	foreach ( $items as $index => $item ) {
		if ( 'edit.php?post_type=tix_attendee' === $item[2] ) {
			$position = $index + 1;
			break;
		}
	}
	array_splice( $items, $position, 0, array( $ours ) );
	$submenu[ MENU ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the submenu is the point.
}

/**
 * Adds the Certificates tab to Tickets > Setup, before Beta.
 *
 * @param array<string, string> $sections Tabs.
 * @return array<string, string>
 */
function add_setup_tab( $sections ) {
	$tab = array( SECTION => __( 'Certificates', 'events-certificate' ) );

	// Before Beta, which CampTix keeps last; at the end when there is no Beta tab.
	$position = array_search( 'beta', array_keys( $sections ), true );
	if ( false === $position ) {
		return $sections + $tab;
	}

	return array_slice( $sections, 0, $position, true ) + $tab + array_slice( $sections, $position, null, true );
}

/**
 * Adds the settings fields when the Certificates tab is shown.
 *
 * @param string $section Current tab.
 */
function setup_controls( $section ) {
	if ( SECTION === $section ) {
		Admin\add_settings_fields( 'camptix_options' );
	}
}

/**
 * Points to the settings from the Certificates screen.
 */
function render_settings_link() {
	printf(
		'<a class="page-title-action" href="%s">%s</a>',
		esc_url( admin_url( MENU . '&page=camptix_options&tix_section=' . SECTION ) ),
		esc_html__( 'Settings', 'events-certificate' )
	);
}
