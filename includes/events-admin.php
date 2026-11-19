<?php
/**
 * The Events screens: the plugin's own events, and each event's participants,
 * to add (typed, pasted or from a Meetup export), mark who came and issue
 * their certificates. docs/decisions/0015.
 *
 * Four screens of one page, like core's edit.php and post.php: the list of
 * events, one event's form, its participants (a wide list) and the form to add
 * participants (docs/decisions/0026).
 *
 * Every change is a POST (or a row link) with its own nonce, checked with the
 * capability before anything happens, then a redirect back with a notice.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\EventsAdmin;

use EventsCertificate\Admin;
use EventsCertificate\Designs;
use EventsCertificate\Environment;
use EventsCertificate\Events;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug.
 */
const SLUG = 'evcert-events';

/**
 * Participant actions (rows and bulk).
 */
const ACTIONS = array( 'attended', 'no_show', 'registered', 'issue', 'remove' );

/**
 * The screens of an event (the list of events is the page without an event).
 */
const VIEWS = array( 'edit', 'participants', 'add', 'person' );

/**
 * Query args of the notice after a change.
 */
const NOTICE_ARGS = array( 'evcert_done', 'evcert_n', 'evcert_skipped', 'evcert_revoked', 'evcert_added', 'evcert_updated', 'evcert_rsvp_no', 'evcert_absent' );

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'admin_menu', __NAMESPACE__ . '\add_page', 15 );
}

/**
 * The screen's address.
 *
 * @param array<string, string|int> $args More query args.
 * @return string
 */
function url( array $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => SLUG ), $args ), admin_url( 'admin.php' ) );
}

/**
 * An action on one participant, with its nonce.
 *
 * @param string $action   One of ACTIONS.
 * @param int    $event_id Event.
 * @param int    $person   Participant.
 * @return string
 */
function action_url( $action, $event_id, $person ) {
	return wp_nonce_url(
		url(
			array(
				'event'     => (int) $event_id,
				'evcert_do' => $action,
				'person'    => (int) $person,
			)
		),
		'evcert_person_' . $action . '_' . (int) $person
	);
}

/**
 * The address of an event's participants (the list), with more query args.
 *
 * @param int                       $event_id Event.
 * @param array<string, string|int> $args     More query args.
 * @return string
 */
function participants_url( $event_id, array $args = array() ) {
	return url(
		array_merge(
			array(
				'event' => (int) $event_id,
				'view'  => 'participants',
			),
			$args
		)
	);
}

/**
 * The address of the form to add an event's participants.
 *
 * @param int $event_id Event.
 * @return string
 */
function add_url( $event_id ) {
	return url(
		array(
			'event' => (int) $event_id,
			'view'  => 'add',
		)
	);
}

/**
 * The address of a participant's form.
 *
 * @param int $event_id Event.
 * @param int $person   Participant.
 * @return string
 */
function person_url( $event_id, $person ) {
	return url(
		array(
			'event'  => (int) $event_id,
			'view'   => 'person',
			'person' => (int) $person,
		)
	);
}

/**
 * The participant whose form is asked for (one of the event's), or null.
 *
 * @return array<string, mixed>|null
 */
function current_person() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which participant to show.
	$id     = isset( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
	$person = $id ? People\get( $id ) : null;

	return $person && (int) current_event() === (int) $person['event_id'] ? $person : null;
}

/**
 * The screen asked for: list, edit (the event's form), participants or add (participants).
 *
 * @return string
 */
function current_view() {
	if ( null === current_event() ) {
		return 'list';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen to show.
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'edit';
	if ( 0 === current_event() ) {
		return 'edit';
	}

	if ( 'person' === $view && ! current_person() ) {
		return 'participants';
	}

	return in_array( $view, VIEWS, true ) ? $view : 'edit';
}

/**
 * Registers the screen. Where the plugin has events of its own (no CampTix, no GatherPress) it is an Events item
 * in the plugin's menu; with GatherPress it is a page with no menu item, opened from a GatherPress event (its
 * participants); with CampTix alone there is no such screen: the WordCamp is the event.
 */
function add_page() {
	if ( ! Environment\participants_screens() ) {
		return;
	}
	$title = __( 'Events', 'events-certificates' );
	if ( Environment\own_events() && '' === Admin\menu_parent() ) {
		$hook = add_submenu_page( Admin\SLUG, $title, $title, Admin\CAPABILITY, SLUG, __NAMESPACE__ . '\render' );
	} else {
		$hook = add_submenu_page( '', $title, '', Admin\CAPABILITY, SLUG, __NAMESPACE__ . '\render' );
	}
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\load' );
	}
}

/**
 * The event the screen shows: an ID, 0 for a new one, null for the list.
 *
 * @return int|null
 */
function current_event() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen to show.
	$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
	if ( 'new' === $event ) {
		return 0;
	}

	return '' !== $event && Events\get( absint( $event ) ) ? absint( $event ) : null;
}

/**
 * Back to the screen with a notice.
 *
 * @param array<string, string|int> $args Query args.
 */
function back( array $args ) {
	wp_safe_redirect( url( $args ) );
	exit;
}

/**
 * Before the screen prints: the menu highlight, the styles, and the
 * requested change.
 */
function load() {
	// Without events of its own the list of events and the event's form do not exist: the participants are reached from the GatherPress event.
	if ( ! Environment\own_events() && in_array( current_view(), array( 'list', 'edit' ), true ) ) {
		wp_safe_redirect( Admin\page_url() );
		exit;
	}
	if ( ! Environment\own_events() || '' !== Admin\menu_parent() ) {
		$GLOBALS['title'] = __( 'Events', 'events-certificates' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a page with no menu item has no title of its own.
		add_filter(
			'parent_file',
			static function () {
				return '' !== Admin\menu_parent() ? Admin\menu_parent() : Admin\SLUG;
			}
		);
		add_filter(
			'submenu_file',
			static function () {
				return Admin\SLUG;
			}
		);
	}
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue' );
	// The notice's arguments stay out of the list's sort and page links (as core's edit.php does).
	add_filter(
		'removable_query_args',
		static function ( $args ) {
			return array_merge( $args, NOTICE_ARGS );
		}
	);
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$_SERVER['REQUEST_URI'] = remove_query_arg( NOTICE_ARGS, esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}
	// The page of the list on screen: events, or an event's participants.
	if ( 'participants' === current_view() ) {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Participants per page', 'events-certificates' ),
				'default' => 50,
				'option'  => 'evcert_people_per_page',
			)
		);
	} elseif ( 'list' === current_view() ) {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Events per page', 'events-certificates' ),
				'default' => 20,
				'option'  => 'evcert_events_per_page',
			)
		);
	}
	add_filter( 'set_screen_option_evcert_people_per_page', 'EventsCertificate\Admin\save_per_page', 10, 3 );
	add_filter( 'set_screen_option_evcert_events_per_page', 'EventsCertificate\Admin\save_per_page', 10, 3 );

	// phpcs:disable WordPress.Security.NonceVerification -- each branch checks its own nonce before changing anything.
	if ( isset( $_POST['evcert_event_form'] ) ) {
		save_event();
	} elseif ( isset( $_POST['evcert_people_form'] ) ) {
		add_people();
	} elseif ( isset( $_POST['evcert_person_form'] ) ) {
		save_person();
	} elseif ( isset( $_POST['evcert_download'] ) ) {
		$id = isset( $_POST['evcert_event_id'] ) ? absint( $_POST['evcert_event_id'] ) : 0;
		check_admin_referer( 'evcert_download_' . $id );
		must_manage();
		$format = sanitize_key( wp_unslash( $_POST['evcert_download'] ) );
		download( $id, in_array( $format, array( 'zip', 'pdf' ), true ) ? $format : 'pdf' );
	} elseif ( isset( $_GET['evcert_do'], $_GET['person'] ) ) {
		row_action();
	} elseif ( isset( $_GET['evcert_trash'] ) ) {
		trash_event();
	} elseif ( null !== current_event() && ( isset( $_REQUEST['participant'] ) ) ) {
		bulk_action();
	}
	// phpcs:enable
}

/**
 * Styles of the screen (status badges).
 */
function enqueue() {
	$style = \EventsCertificate\Assets\src( 'events-admin', 'css' );
	wp_enqueue_style( 'evcert-events', $style['url'], array(), $style['version'] );
	if ( 'edit' === current_view() ) {
		// The boxes of the event's form are core's (post.php).
		wp_enqueue_style( 'edit' );
	}
}

/**
 * Stops unless the user may manage certificates.
 */
function must_manage() {
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage certificates.', 'events-certificates' ), 403 );
	}
}

/**
 * Saves the event form.
 */
function save_event() {
	$id = isset( $_POST['evcert_event_id'] ) ? absint( $_POST['evcert_event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
	check_admin_referer( 'evcert_event_save_' . $id );
	must_manage();

	$fields = array();
	foreach ( array( 'title', 'start', 'end', 'location', 'link', 'hours', 'signer_name', 'signer_role', 'community', 'venue', 'city', 'speakers' ) as $field ) {
		$fields[ $field ] = isset( $_POST[ 'evcert_' . $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'evcert_' . $field ] ) ) : '';
	}
	// A box that is not checked is not sent: no value means the workload is not shown.
	$fields['show_hours']    = empty( $_POST['evcert_show_hours'] ) ? '0' : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked above.
	$fields['hours_by_role'] = isset( $_POST['evcert_hours_by_role'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['evcert_hours_by_role'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is checked above; each value is cleaned by Events\clean().
	$current                 = $id ? Events\get( $id ) : null;
	if ( $current ) {
		$fields['source']     = $current['source'];
		$fields['source_ref'] = $current['source_ref'];
	}
	$saved = Events\save( $fields, $id );
	if ( is_wp_error( $saved ) ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), $saved->get_error_message(), MINUTE_IN_SECONDS );
		back(
			array(
				'event'       => $id ? $id : 'new',
				'evcert_done' => 'error',
			)
		);
	}
	// A new event goes on to its participants; a saved one stays on its form.
	back(
		array(
			'event'       => $saved,
			'evcert_done' => $id ? 'saved' : 'added',
		) + ( $id ? array() : array( 'view' => 'add' ) )
	);
}

/**
 * Adds participants: pasted lines and/or a spreadsheet export (CSV).
 */
function add_people() {
	$id = isset( $_POST['evcert_event_id'] ) ? absint( $_POST['evcert_event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
	check_admin_referer( 'evcert_people_add_' . $id );
	must_manage();
	if ( ! Events\get( $id ) ) {
		back( array() );
	}

	$people = People\parse_lines( isset( $_POST['evcert_lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evcert_lines'] ) ) : '' );
	$notes  = array(
		'rsvp_no' => 0,
		'absent'  => 0,
	);
	$file   = uploaded_csv();
	if ( is_wp_error( $file ) ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), $file->get_error_message(), MINUTE_IN_SECONDS );
		back(
			array(
				'event'       => $id,
				'view'        => 'add',
				'evcert_done' => 'error',
			)
		);
	}
	if ( $file ) {
		$read = People\parse_rows( $file );
		foreach ( $read['people'] as $person ) {
			$people[] = $person + array( 'source' => 'csv' );
		}
		$notes = array(
			'rsvp_no' => $read['rsvp_no'],
			'absent'  => $read['absent'],
		);
	}
	if ( ! empty( $_POST['evcert_all_attended'] ) ) {
		foreach ( $people as $i => $person ) {
			$people[ $i ]['status'] = $person['status'] ?? 'attended';
		}
	}
	$result = People\import( $id, $people );
	back(
		array(
			'event'          => $id,
			'view'           => 'participants',
			'evcert_done'    => 'imported',
			'evcert_added'   => $result['added'],
			'evcert_updated' => $result['updated'],
			'evcert_rsvp_no' => $notes['rsvp_no'],
			'evcert_absent'  => $notes['absent'],
		)
	);
}

/**
 * The rows of the uploaded CSV, when one was sent.
 *
 * @return array<int, array<int, string>>|\WP_Error|null
 */
function uploaded_csv() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- called after check_admin_referer().
	if ( empty( $_FILES['evcert_csv']['name'] ) ) {
		return null;
	}
	$name  = sanitize_file_name( wp_unslash( (string) $_FILES['evcert_csv']['name'] ) );
	$tmp   = isset( $_FILES['evcert_csv']['tmp_name'] ) ? (string) $_FILES['evcert_csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a temporary path from PHP, checked with is_uploaded_file().
	$error = isset( $_FILES['evcert_csv']['error'] ) ? (int) $_FILES['evcert_csv']['error'] : UPLOAD_ERR_NO_FILE;
	// phpcs:enable
	$type = wp_check_filetype( $name, array( 'csv' => 'text/csv' ) );
	if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp ) || 'csv' !== $type['ext'] ) {
		return new \WP_Error( 'evcert_csv', __( 'Send a .csv file (in Meetup: Attendees → Manage → Attendee details; in a spreadsheet: Save as CSV).', 'events-certificates' ) );
	}

	return People\read_csv( $tmp );
}

/**
 * An action on one participant (a row link).
 */
function row_action() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked below.
	$action = isset( $_GET['evcert_do'] ) ? sanitize_key( wp_unslash( $_GET['evcert_do'] ) ) : '';
	$person = isset( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
	// phpcs:enable
	check_admin_referer( 'evcert_person_' . $action . '_' . $person );
	must_manage();
	$row = People\get( $person );
	if ( ! $row || ! in_array( $action, ACTIONS, true ) ) {
		back( array() );
	}
	run( $action, array( $person ), (int) $row['event_id'] );
}

/**
 * A bulk action on the participants list.
 */
function bulk_action() {
	check_admin_referer( 'bulk-participants' );
	must_manage();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
	$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( '' === $action && isset( $_REQUEST['action2'] ) ) {
		$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
	}
	$ids     = isset( $_REQUEST['participant'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['participant'] ) ) : array();
	$role_do = isset( $_REQUEST['evcert_role_do'] ) ? sanitize_key( wp_unslash( $_REQUEST['evcert_role_do'] ) ) : '';
	$role    = isset( $_REQUEST['evcert_role'] ) ? sanitize_key( wp_unslash( $_REQUEST['evcert_role'] ) ) : '';
	// phpcs:enable
	if ( in_array( $role_do, array( 'add', 'remove' ), true ) && $ids ) {
		$n = isset( Designs\role_labels()[ $role ] ) ? change_roles( $ids, $role, $role_do, (int) current_event() ) : 0;
		back(
			array(
				'event'       => (int) current_event(),
				'view'        => 'participants',
				'evcert_done' => 'add' === $role_do ? 'roles_added' : 'roles_removed',
				'evcert_n'    => $n,
			)
		);
	}
	if ( in_array( $action, array( 'download_zip', 'download_pdf' ), true ) && $ids ) {
		download( (int) current_event(), 'download_zip' === $action ? 'zip' : 'pdf', $ids );
	}
	// "Issue the certificates of one role": issue_speaker.
	if ( 0 === strpos( $action, 'issue_' ) && isset( Designs\role_labels()[ substr( $action, 6 ) ] ) && $ids ) {
		run( $action, $ids, (int) current_event() );
	}
	if ( ! in_array( $action, ACTIONS, true ) || ! $ids ) {
		return;
	}
	run( $action, $ids, (int) current_event() );
}

/**
 * Gives a role to the people of an event, or takes it off (only while it has no certificate, never the last).
 *
 * @param int[]  $ids      Participants.
 * @param string $role     Role.
 * @param string $mode     add|remove.
 * @param int    $event_id Event they must belong to.
 * @return int How many changed.
 */
function change_roles( array $ids, $role, $mode, $event_id ) {
	$changed = 0;
	foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
		$person = People\get( $id );
		if ( ! $person || (int) $person['event_id'] !== (int) $event_id ) {
			continue;
		}
		$changed += 'add' === $mode ? People\add_roles( $id, array( $role ) ) : (int) People\remove_role( $id, $role );
	}

	return $changed;
}

/**
 * Saves a participant: name, e-mail and roles. The roles that have a certificate stay (they are revoked, not removed);
 * the ones unchecked without one are taken off, never the last.
 *
 * @param int                  $id   Participant.
 * @param array<string, mixed> $data name, email, roles (array of role keys).
 * @return bool Whether it was saved (a name is required).
 */
function save_person_data( $id, array $data ) {
	if ( ! People\update_person( $id, (string) ( $data['name'] ?? '' ), (string) ( $data['email'] ?? '' ) ) ) {
		return false;
	}
	$person = People\get( $id );
	$wanted = People\roles_from( (array) ( $data['roles'] ?? array() ) );
	People\add_roles( $id, $wanted );
	foreach ( array_keys( $person['roles'] ) as $role ) {
		if ( ! in_array( $role, $wanted, true ) ) {
			People\remove_role( $id, $role );
		}
	}

	return true;
}

/**
 * Saves the participant's form.
 */
function save_person() {
	$id = isset( $_POST['evcert_person_id'] ) ? absint( $_POST['evcert_person_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
	check_admin_referer( 'evcert_person_save_' . $id );
	must_manage();
	$person = People\get( $id );
	if ( ! $person ) {
		back( array() );
	}
	$saved = save_person_data(
		$id,
		array(
			'name'  => isset( $_POST['evcert_person_name'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_person_name'] ) ) : '',
			'email' => isset( $_POST['evcert_person_email'] ) ? sanitize_email( wp_unslash( $_POST['evcert_person_email'] ) ) : '',
			'roles' => isset( $_POST['evcert_person_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['evcert_person_roles'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is cleaned by sanitize_key().
		)
	);
	if ( ! $saved ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), __( 'The participant needs a name.', 'events-certificates' ), MINUTE_IN_SECONDS );
	}
	back(
		array(
			'event'       => (int) $person['event_id'],
			'view'        => $saved ? 'participants' : 'person',
			'evcert_done' => $saved ? 'person_saved' : 'error',
		) + ( $saved ? array() : array( 'person' => $id ) )
	);
}

/**
 * Runs a participant action, then back to the event.
 *
 * @param string $action   One of ACTIONS.
 * @param int[]  $ids      Participants.
 * @param int    $event_id Event.
 */
function run( $action, array $ids, $event_id ) {
	// issue = every role of each person; issue_speaker = the speaker certificates only.
	$only = 0 === strpos( $action, 'issue_' ) ? array( substr( $action, 6 ) ) : array();
	if ( $only ) {
		$action = 'issue';
	}
	switch ( $action ) {
		case 'issue':
			$result = People\issue_many( $ids, $event_id, $only );
			$n      = $result['issued'];
			$extra  = array(
				'evcert_skipped' => $result['skipped'],
				'evcert_revoked' => $result['revoked'],
			);
			break;
		case 'remove':
			$n     = People\remove( $ids, $event_id );
			$extra = array();
			break;
		default:
			$n     = People\set_status( $ids, $action, $event_id );
			$extra = array();
	}
	back(
		array(
			'event'       => $event_id,
			'view'        => 'participants',
			'evcert_done' => $action,
			'evcert_n'    => $n,
		) + $extra
	);
}

/**
 * Sends an event's certificates: a ZIP with one PDF each, or one PDF with
 * them all. Only certificates already issued (and not revoked).
 *
 * @param int    $event_id Event.
 * @param string $format   zip|pdf.
 * @param int[]  $ids      Only these participants (all when empty).
 */
function download( $event_id, $format, array $ids = array() ) {
	must_manage();
	$event = Events\get( $event_id );
	$certs = $event ? People\certificates( $event_id, $ids ) : array();
	if ( ! $certs ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), __( 'There are no certificates to download yet: mark who attended and issue them first.', 'events-certificates' ), MINUTE_IN_SECONDS );
		back(
			array(
				'event'       => $event_id,
				'view'        => 'participants',
				'evcert_done' => 'error',
			)
		);
	}
	wp_raise_memory_limit( 'admin' );
	$base = sanitize_file_name( remove_accents( sanitize_title( $event['title'] ) ) );
	$base = '' !== $base ? $base : 'event';

	if ( 'pdf' === $format ) {
		$items = array();
		foreach ( $certs as $cert ) {
			$items[] = array( $cert, \EventsCertificate\Certificates\design( $cert ) );
		}
		send( \EventsCertificate\Render\pdf_many( $items ), 'application/pdf', $base . '-certificates.pdf' );
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), __( 'This server cannot make ZIP files (PHP ZipArchive is missing). Download a single PDF instead.', 'events-certificates' ), MINUTE_IN_SECONDS );
		back(
			array(
				'event'       => $event_id,
				'view'        => 'participants',
				'evcert_done' => 'error',
			)
		);
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = wp_tempnam( $base . '.zip' );
	$zip  = new \ZipArchive();
	if ( true !== $zip->open( $file, \ZipArchive::OVERWRITE ) ) {
		wp_die( esc_html__( 'The ZIP file could not be made.', 'events-certificates' ) );
	}
	$names = array();
	foreach ( $certs as $cert ) {
		$name = sanitize_file_name( remove_accents( (string) $cert['name'] ) . '-' . $cert['code'] . '.pdf' );
		if ( isset( $names[ $name ] ) ) {
			$name = $cert['code'] . '.pdf';
		}
		$names[ $name ] = true;
		$zip->addFromString( $name, \EventsCertificate\Render\pdf( $cert, \EventsCertificate\Certificates\design( $cert ) ) );
	}
	$zip->close();
	send_file( $file, 'application/zip', $base . '-certificates.zip' );
}

/**
 * Sends a file to download, deletes it, and stops (a ZIP can be big: it is streamed, not read into memory).
 *
 * @param string $path File.
 * @param string $type Content type.
 * @param string $name Name to download it as.
 */
function send_file( $path, $type, $name ) {
	nocache_headers();
	header( 'Content-Type: ' . $type );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	header( 'Content-Length: ' . (int) filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- the temporary file just made, streamed without holding it in memory.
	wp_delete_file( $path );
	exit;
}

/**
 * Sends bytes to download and stops.
 *
 * @param string $bytes File bytes.
 * @param string $type  Content type.
 * @param string $name  File name.
 */
function send( $bytes, $type, $name ) {
	nocache_headers();
	header( 'Content-Type: ' . $type );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	header( 'Content-Length: ' . strlen( $bytes ) );
	echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file bytes.
	exit;
}

/**
 * Moves an event to the Trash.
 */
function trash_event() {
	$id = isset( $_GET['evcert_trash'] ) ? absint( $_GET['evcert_trash'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
	check_admin_referer( 'evcert_event_trash_' . $id );
	must_manage();
	back( array( 'evcert_done' => Events\trash( $id ) ? 'trashed' : 'error' ) );
}

/**
 * The notice after a change.
 */
function notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks a message and its numbers.
	$done = isset( $_GET['evcert_done'] ) ? sanitize_key( wp_unslash( $_GET['evcert_done'] ) ) : '';
	$num  = static function ( $key ) {
		return isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
	};
	// phpcs:enable
	if ( '' === $done ) {
		return;
	}
	if ( 'error' === $done ) {
		$message = (string) get_transient( 'evcert_event_error_' . get_current_user_id() );
		delete_transient( 'evcert_event_error_' . get_current_user_id() );
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( '' !== $message ? $message : __( 'Something went wrong. Please try again.', 'events-certificates' ) ) );
		return;
	}
	$n        = $num( 'evcert_n' );
	$skipped  = $num( 'evcert_skipped' );
	$revoked  = $num( 'evcert_revoked' );
	$added    = $num( 'evcert_added' );
	$updated  = $num( 'evcert_updated' );
	$rsvp_no  = $num( 'evcert_rsvp_no' );
	$absent   = $num( 'evcert_absent' );
	$messages = array(
		'added'         => __( 'Event added. Now add its participants.', 'events-certificates' ),
		'saved'         => __( 'Event saved.', 'events-certificates' ),
		'trashed'       => __( 'Event moved to the Trash.', 'events-certificates' ),
		/* translators: %s: number of participants. */
		'attended'      => sprintf( _n( '%s participant marked as attended.', '%s participants marked as attended.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of participants. */
		'no_show'       => sprintf( _n( '%s participant marked as no-show.', '%s participants marked as no-show.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of participants. */
		'registered'    => sprintf( _n( '%s participant marked as registered.', '%s participants marked as registered.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of participants. */
		'remove'        => sprintf( _n( '%s participant removed.', '%s participants removed.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of certificates. */
		'issue'         => sprintf( _n( '%s certificate issued.', '%s certificates issued.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of participants. */
		'roles_added'   => sprintf( _n( 'The role was added to %s participant.', 'The role was added to %s participants.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		/* translators: %s: number of participants. */
		'roles_removed' => sprintf( _n( 'The role was taken off %s participant. A role with a certificate stays (revoke the certificate), and so does the last role.', 'The role was taken off %s participants. A role with a certificate stays (revoke the certificate), and so does the last role.', $n, 'events-certificates' ), number_format_i18n( $n ) ),
		'person_saved'  => __( 'Participant saved.', 'events-certificates' ),
	);
	if ( 'issue' === $done && $skipped ) {
		/* translators: %s: number of participants. */
		$messages['issue'] .= ' ' . sprintf( _n( '%s left out: only who attended gets one.', '%s left out: only who attended gets one.', $skipped, 'events-certificates' ), number_format_i18n( $skipped ) );
	}
	if ( 'issue' === $done && $revoked ) {
		/* translators: %s: number of participants. */
		$messages['issue'] .= ' ' . sprintf( _n( '%s left out: their certificate was revoked.', '%s left out: their certificates were revoked.', $revoked, 'events-certificates' ), number_format_i18n( $revoked ) );
	}
	if ( 'imported' === $done ) {
		$bits = array(
			/* translators: %s: number of participants. */
			sprintf( _n( '%s participant added', '%s participants added', $added, 'events-certificates' ), number_format_i18n( $added ) ),
		);
		if ( $updated ) {
			/* translators: %s: number of participants. */
			$bits[] = sprintf( _n( '%s already on the list', '%s already on the list', $updated, 'events-certificates' ), number_format_i18n( $updated ) );
		}
		if ( $rsvp_no ) {
			/* translators: %s: number of people. */
			$bits[] = sprintf( _n( '%s with RSVP "No" left out', '%s with RSVP "No" left out', $rsvp_no, 'events-certificates' ), number_format_i18n( $rsvp_no ) );
		}
		if ( $absent ) {
			/* translators: %s: number of people. */
			$bits[] = sprintf( _n( '%s marked absent, kept as no-show', '%s marked absent, kept as no-shows', $absent, 'events-certificates' ), number_format_i18n( $absent ) );
		}
		$messages['imported'] = implode( ' · ', $bits ) . '.';
	}
	if ( isset( $messages[ $done ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
	}
}

/**
 * Prints the screen: the events, one event's form, its participants or the form to add them.
 */
function render() {
	must_manage();
	switch ( current_view() ) {
		case 'list':
			render_list();
			break;
		case 'participants':
			render_participants( (array) Events\get( (int) current_event() ) );
			break;
		case 'add':
			render_add( (array) Events\get( (int) current_event() ) );
			break;
		case 'person':
			render_person( (array) Events\get( (int) current_event() ), (array) current_person() );
			break;
		default:
			render_event( (int) current_event() );
	}
}

/**
 * The events list.
 */
function render_list() {
	require_once __DIR__ . '/class-events-list-table.php';
	$table = new Events_List_Table();
	$table->prepare_items();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'events-certificates' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( url( array( 'event' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add event', 'events-certificates' ); ?></a>
		<?php
		/**
		 * Fires next to the Events screen title (Generator, Import from GatherPress).
		 */
		do_action( 'evcert_events_title_actions' );
		?>
		<hr class="wp-header-end">
		<?php notice(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( SLUG ); ?>">
			<?php
			$table->search_box( __( 'Search events', 'events-certificates' ), 'evcert-event-search' );
			$table->display();
			?>
		</form>
	</div>
	<?php
}

/**
 * A box of the event's form, with core's markup (post.php).
 *
 * @param string   $id       Box ID.
 * @param string   $title    Heading.
 * @param callable $content  Prints the box's content.
 */
function event_box( $id, $title, callable $content ) {
	?>
	<div id="<?php echo esc_attr( $id ); ?>" class="postbox">
		<div class="postbox-header"><h2 class="hndle"><?php echo esc_html( $title ); ?></h2></div>
		<div class="inside"><?php $content(); ?></div>
	</div>
	<?php
}

/**
 * One event's form, like post.php: the name above, boxes of fields and, on the side, Save.
 *
 * @param int $event_id Event (0 for a new one).
 */
function render_event( $event_id ) {
	$event  = $event_id ? Events\get( $event_id ) : null;
	$signer = \EventsCertificate\Signer\current();
	$value  = static function ( $field ) use ( $event ) {
		return $event ? (string) $event[ $field ] : '';
	};
	?>
	<div class="wrap evcert-event">
		<h1 class="wp-heading-inline"><?php echo esc_html( $event ? __( 'Edit event', 'events-certificates' ) : __( 'Add event', 'events-certificates' ) ); ?></h1>
		<?php if ( $event ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( participants_url( $event_id ) ); ?>"><?php esc_html_e( 'Participants', 'events-certificates' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( url( array( 'event' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add event', 'events-certificates' ); ?></a>
		<?php else : ?>
			<a class="page-title-action" href="<?php echo esc_url( url() ); ?>"><?php esc_html_e( 'All events', 'events-certificates' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php notice(); ?>

		<form method="post" action="<?php echo esc_url( url( array( 'event' => $event_id ? $event_id : 'new' ) ) ); ?>">
			<?php wp_nonce_field( 'evcert_event_save_' . $event_id ); ?>
			<input type="hidden" name="evcert_event_form" value="1">
			<input type="hidden" name="evcert_event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div id="titlediv">
							<div id="titlewrap">
								<label class="screen-reader-text" for="title"><?php esc_html_e( 'Name', 'events-certificates' ); ?></label>
								<input type="text" name="evcert_title" size="30" id="title" value="<?php echo esc_attr( $value( 'title' ) ); ?>" required spellcheck="true" autocomplete="off" placeholder="<?php esc_attr_e( 'WordPress Meetup: Blocks in practice', 'events-certificates' ); ?>">
							</div>
						</div>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php
						event_box(
							'submitdiv',
							__( 'Save', 'events-certificates' ),
							static function () use ( $event, $event_id ) {
								?>
								<div id="major-publishing-actions">
									<?php if ( $event ) : ?>
										<div id="delete-action"><a class="submitdelete deletion" href="<?php echo esc_url( wp_nonce_url( url( array( 'evcert_trash' => $event_id ) ), 'evcert_event_trash_' . $event_id ) ); ?>"><?php esc_html_e( 'Move to Trash', 'events-certificates' ); ?></a></div>
									<?php endif; ?>
									<div id="publishing-action"><?php submit_button( $event ? __( 'Save', 'events-certificates' ) : __( 'Add event', 'events-certificates' ), 'primary large', 'submit', false ); ?></div>
									<div class="clear"></div>
								</div>
								<?php
							}
						);
	if ( $event ) {
		event_box(
			'evcert-participants-box',
			__( 'Participants', 'events-certificates' ),
			static function () use ( $event_id ) {
				$counts = People\counts( $event_id );
				?>
									<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of participants, 2: number who attended, 3: number of certificates. */
							__( '%1$s participants, %2$s attended, %3$s certificates issued.', 'events-certificates' ),
							number_format_i18n( $counts['all'] ),
							number_format_i18n( $counts['attended'] ),
							number_format_i18n( $counts['certified'] )
						)
					);
					?>
									</p>
									<p>
										<a class="button" href="<?php echo esc_url( participants_url( $event_id ) ); ?>"><?php esc_html_e( 'Participants', 'events-certificates' ); ?></a>
										<a class="button" href="
										<?php
										echo esc_url(
											url(
												array(
													'event' => $event_id,
													'view' => 'add',
												)
											)
										);
										?>
																"><?php esc_html_e( 'Add participants', 'events-certificates' ); ?></a>
									</p>
									<?php
			}
		);
	}
	?>
					</div>

					<div id="postbox-container-2" class="postbox-container">
						<?php
						event_box(
							'evcert-when-where',
							__( 'When and where', 'events-certificates' ),
							static function () use ( $value ) {
								?>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="evcert_start"><?php esc_html_e( 'Starts', 'events-certificates' ); ?></label></th>
										<td>
											<input type="datetime-local" id="evcert_start" name="evcert_start" value="<?php echo esc_attr( str_replace( ' ', 'T', $value( 'start' ) ) ); ?>" required>
											<label for="evcert_end" class="evcert-event__to"><?php esc_html_e( 'Ends', 'events-certificates' ); ?></label>
											<input type="datetime-local" id="evcert_end" name="evcert_end" value="<?php echo esc_attr( str_replace( ' ', 'T', $value( 'end' ) ) ); ?>">
											<p class="description"><?php echo esc_html( sprintf( /* translators: %s: time zone name. */ __( 'Time zone: %s.', 'events-certificates' ), wp_timezone_string() ) ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="evcert_venue"><?php esc_html_e( 'Venue and city', 'events-certificates' ); ?></label></th>
										<td>
											<input type="text" class="regular-text" id="evcert_venue" name="evcert_venue" value="<?php echo esc_attr( $value( 'venue' ) ); ?>">
											<label for="evcert_city" class="screen-reader-text"><?php esc_html_e( 'City', 'events-certificates' ); ?></label>
											<input type="text" class="regular-text" id="evcert_city" name="evcert_city" value="<?php echo esc_attr( $value( 'city' ) ); ?>" placeholder="<?php esc_attr_e( 'City, State', 'events-certificates' ); ?>">
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="evcert_location"><?php esc_html_e( 'Place on the certificate', 'events-certificates' ); ?></label></th>
										<td>
											<input type="text" class="regular-text" id="evcert_location" name="evcert_location" value="<?php echo esc_attr( $value( 'location' ) ); ?>">
											<p class="description"><?php esc_html_e( 'Leave empty to use the venue and the city.', 'events-certificates' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="evcert_link"><?php esc_html_e( 'Event link', 'events-certificates' ); ?></label></th>
										<td><input type="url" class="regular-text" id="evcert_link" name="evcert_link" value="<?php echo esc_attr( $value( 'link' ) ); ?>" placeholder="https://"></td>
									</tr>
								</table>
								<?php
							}
						);
						event_box(
							'evcert-on-certificate',
							__( 'On the certificate', 'events-certificates' ),
							static function () use ( $value, $event ) {
								?>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="evcert_community"><?php esc_html_e( 'Community', 'events-certificates' ); ?></label></th>
										<td><input type="text" class="regular-text" id="evcert_community" name="evcert_community" value="<?php echo esc_attr( $value( 'community' ) ); ?>"></td>
									</tr>
									<tr>
										<th scope="row"><label for="evcert_speakers"><?php esc_html_e( 'Speakers', 'events-certificates' ); ?></label></th>
										<td>
											<input type="text" class="large-text" id="evcert_speakers" name="evcert_speakers" value="<?php echo esc_attr( $value( 'speakers' ) ); ?>">
											<p class="description"><?php esc_html_e( 'Names as they go on the certificates. Leave empty and the speakers column is left out.', 'events-certificates' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="evcert_hours"><?php esc_html_e( 'Hours', 'events-certificates' ); ?></label></th>
										<td>
											<input type="number" class="small-text" id="evcert_hours" name="evcert_hours" min="0" max="999" step="0.25" value="<?php echo esc_attr( $value( 'hours' ) ); ?>">
											<label><input type="checkbox" name="evcert_show_hours" value="1" <?php checked( ! $event || '0' !== $event['show_hours'] ); ?>> <?php esc_html_e( 'Show the workload on the certificates', 'events-certificates' ); ?></label>
											<p class="description"><?php esc_html_e( 'Leave empty to count from the start to the end.', 'events-certificates' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Hours by role', 'events-certificates' ); ?></th>
										<td>
											<?php foreach ( Designs\role_labels() as $role => $label ) : ?>
												<label class="evcert-event__role-hours">
													<span><?php echo esc_html( $label ); ?></span>
													<input type="number" class="small-text" name="evcert_hours_by_role[<?php echo esc_attr( $role ); ?>]" min="0" max="999" step="0.25" value="<?php echo esc_attr( (string) ( $event['hours_by_role'][ $role ] ?? '' ) ); ?>">
												</label>
											<?php endforeach; ?>
											<p class="description"><?php esc_html_e( 'A role with no hours here uses the event\'s hours above (an organizer\'s may be 40, a volunteer\'s 8).', 'events-certificates' ); ?></p>
										</td>
									</tr>
								</table>
								<?php
							}
						);
						event_box(
							'evcert-signature',
							__( 'Signature', 'events-certificates' ),
							static function () use ( $value, $signer ) {
								?>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="evcert_signer_name"><?php esc_html_e( 'Signed by', 'events-certificates' ); ?></label></th>
										<td>
											<input type="text" class="regular-text" id="evcert_signer_name" name="evcert_signer_name" value="<?php echo esc_attr( $value( 'signer_name' ) ); ?>" placeholder="<?php echo esc_attr( $signer ? $signer['name'] : __( 'Name', 'events-certificates' ) ); ?>">
											<input type="text" class="regular-text" id="evcert_signer_role" name="evcert_signer_role" aria-label="<?php esc_attr_e( 'Role of who signs', 'events-certificates' ); ?>" value="<?php echo esc_attr( $value( 'signer_role' ) ); ?>" placeholder="<?php echo esc_attr( $signer ? \EventsCertificate\Signer\role_in( $signer['roles'], get_locale() ) : __( 'Role', 'events-certificates' ) ); ?>">
											<p class="description"><?php esc_html_e( 'Leave empty to use who signs in Settings.', 'events-certificates' ); ?></p>
										</td>
									</tr>
								</table>
								<?php
							}
						);
	?>
					</div>
				</div>
			</div>
		</form>
	</div>
	<?php
}

/**
 * The buttons to download the event's certificates.
 *
 * @param array<string, string> $event Event.
 */
function render_downloads( array $event ) {
	$n = People\counts( (int) $event['id'] )['certified'];
	if ( ! $n ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( participants_url( (int) $event['id'] ) ); ?>" class="evcert-event__downloads">
		<?php wp_nonce_field( 'evcert_download_' . (int) $event['id'] ); ?>
		<input type="hidden" name="evcert_event_id" value="<?php echo esc_attr( (string) $event['id'] ); ?>">
		<span><?php echo esc_html( sprintf( /* translators: %s: number of certificates. */ _n( '%s certificate issued:', '%s certificates issued:', $n, 'events-certificates' ), number_format_i18n( $n ) ) ); ?></span>
		<button type="submit" class="button" name="evcert_download" value="zip"><?php esc_html_e( 'Download ZIP (one PDF each)', 'events-certificates' ); ?></button>
		<button type="submit" class="button" name="evcert_download" value="pdf"><?php esc_html_e( 'Download a single PDF', 'events-certificates' ); ?></button>
	</form>
	<?php
}

/**
 * An event's participants: only the list (a wide one), like the posts list.
 *
 * @param array<string, string> $event Event.
 */
function render_participants( array $event ) {
	require_once __DIR__ . '/class-people-list-table.php';
	$table = new People_List_Table( (int) $event['id'] );
	$table->prepare_items();
	?>
	<div class="wrap evcert-event">
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: event name. */
			echo esc_html( sprintf( __( 'Participants: %s', 'events-certificates' ), $event['title'] ) );
			?>
		</h1>
		<a class="page-title-action" href="<?php echo esc_url( add_url( (int) $event['id'] ) ); ?>"><?php esc_html_e( 'Add participants', 'events-certificates' ); ?></a>
		<?php if ( Environment\own_events() ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( url( array( 'event' => (int) $event['id'] ) ) ); ?>"><?php esc_html_e( 'Edit event', 'events-certificates' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( url() ); ?>"><?php esc_html_e( 'All events', 'events-certificates' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php
		notice();
		render_downloads( $event );
		$table->views();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( SLUG ); ?>">
			<input type="hidden" name="event" value="<?php echo esc_attr( (string) $event['id'] ); ?>">
			<input type="hidden" name="view" value="participants">
			<?php
			$table->search_box( __( 'Search participants', 'events-certificates' ), 'evcert-people-search' );
			$table->display();
			?>
		</form>
	</div>
	<?php
}

/**
 * The form to add participants to an event: pasted lines, a CSV file, and the imports of the active integrations.
 *
 * @param array<string, string> $event Event.
 */
function render_add( array $event ) {
	?>
	<div class="wrap evcert-event">
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: event name. */
			echo esc_html( sprintf( __( 'Add participants: %s', 'events-certificates' ), $event['title'] ) );
			?>
		</h1>
		<a class="page-title-action" href="<?php echo esc_url( participants_url( (int) $event['id'] ) ); ?>"><?php esc_html_e( 'Participants', 'events-certificates' ); ?></a>
		<?php if ( Environment\own_events() ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( url( array( 'event' => (int) $event['id'] ) ) ); ?>"><?php esc_html_e( 'Edit event', 'events-certificates' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php notice(); ?>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_url( (int) $event['id'] ) ); ?>" class="evcert-event__add">
			<?php wp_nonce_field( 'evcert_people_add_' . (int) $event['id'] ); ?>
			<input type="hidden" name="evcert_people_form" value="1">
			<input type="hidden" name="evcert_event_id" value="<?php echo esc_attr( (string) $event['id'] ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="evcert_lines"><?php esc_html_e( 'Names', 'events-certificates' ); ?></label></th>
					<td>
						<textarea id="evcert_lines" name="evcert_lines" rows="8" class="large-text code" placeholder="<?php echo esc_attr( "Ana Souza, ana@example.com\nBruno Lima, bruno@example.com, " . __( 'Speaker', 'events-certificates' ) ); ?>"></textarea>
						<p class="description"><?php esc_html_e( 'One per line: name, and optionally e-mail and roles (several with / or +, such as Speaker / Organizer), separated by commas (or pasted from a spreadsheet). A name on two lines is one person with both roles.', 'events-certificates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="evcert_csv"><?php esc_html_e( 'CSV file', 'events-certificates' ); ?></label></th>
					<td>
						<input type="file" id="evcert_csv" name="evcert_csv" accept=".csv,text/csv">
						<p class="description"><?php esc_html_e( 'Such as the Meetup attendee export. In Meetup: open the past event, Attendees, Manage, Attendee details. People with RSVP "No" are left out; those marked absent come in as no-shows.', 'events-certificates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attendance', 'events-certificates' ); ?></th>
					<td><label><input type="checkbox" name="evcert_all_attended" value="1"> <?php esc_html_e( 'They all attended (unless the file says otherwise)', 'events-certificates' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add participants', 'events-certificates' ) ); ?>
		</form>

		<?php
		if ( has_action( 'evcert_event_participants_actions' ) ) {
			echo '<h2>' . esc_html__( 'Import', 'events-certificates' ) . '</h2>';
			/**
			 * Fires in the screen to add participants, for the imports of an integration (GatherPress sync, WordCamp people).
			 *
			 * @param array<string, string> $event Event.
			 */
			do_action( 'evcert_event_participants_actions', $event );
		}
		?>
	</div>
	<?php
}

/**
 * A participant's form: name, e-mail and the roles as a checklist (like the categories of a post).
 *
 * @param array<string, string> $event  Event.
 * @param array<string, mixed>  $person Participant.
 */
function render_person( array $event, array $person ) {
	$labels = Designs\role_labels();
	?>
	<div class="wrap evcert-event">
		<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( /* translators: %s: participant's name. */ __( 'Edit participant: %s', 'events-certificates' ), $person['name'] ) ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( participants_url( (int) $event['id'] ) ); ?>"><?php esc_html_e( 'Participants', 'events-certificates' ); ?></a>
		<hr class="wp-header-end">
		<?php notice(); ?>
		<form method="post" action="<?php echo esc_url( person_url( (int) $event['id'], (int) $person['id'] ) ); ?>">
			<?php wp_nonce_field( 'evcert_person_save_' . (int) $person['id'] ); ?>
			<input type="hidden" name="evcert_person_form" value="1">
			<input type="hidden" name="evcert_person_id" value="<?php echo esc_attr( (string) $person['id'] ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="evcert_person_name"><?php esc_html_e( 'Name', 'events-certificates' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="evcert_person_name" name="evcert_person_name" value="<?php echo esc_attr( $person['name'] ); ?>" required>
						<p class="description"><?php esc_html_e( 'As it goes on the certificates issued from now on; the ones already issued are corrected one by one.', 'events-certificates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="evcert_person_email"><?php esc_html_e( 'E-mail', 'events-certificates' ); ?></label></th>
					<td><input type="email" class="regular-text" id="evcert_person_email" name="evcert_person_email" value="<?php echo esc_attr( $person['email'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Roles', 'events-certificates' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'events-certificates' ); ?></legend>
							<ul class="categorychecklist">
								<?php foreach ( $labels as $role => $label ) : ?>
									<?php $has_certificate = ! empty( $person['roles'][ $role ] ); ?>
									<li>
										<label>
											<input type="checkbox" name="evcert_person_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( isset( $person['roles'][ $role ] ) ); ?> <?php disabled( $has_certificate ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
										<?php if ( $has_certificate ) : ?>
											<input type="hidden" name="evcert_person_roles[]" value="<?php echo esc_attr( $role ); ?>">
											<span class="description"><?php esc_html_e( 'Has a certificate: to stop it, revoke it.', 'events-certificates' ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Each role has its own certificate.', 'events-certificates' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'events-certificates' ) ); ?>
		</form>
	</div>
	<?php
}
