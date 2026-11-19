<?php
/**
 * GatherPress: an event of GatherPress becomes an event here, with its
 * participants: who RSVPed "attending" comes in as registered, and who was
 * checked in as attended. Waiting list and "not attending" stay out.
 * Syncing again adds new RSVPs and check-ins without demoting anyone.
 *
 * Only GatherPress's data is read (its RSVP comments, their status and
 * check-in terms); nothing is written to GatherPress.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\GatherPress;

use EventsCertificate\Admin;
use EventsCertificate\Environment;
use EventsCertificate\Events;
use EventsCertificate\EventsAdmin;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

/**
 * GatherPress's event post type.
 */
const EVENT_TYPE = 'gatherpress_event';

/**
 * GatherPress's RSVP comment type.
 */
const RSVP_TYPE = 'gatherpress_rsvp';

/**
 * Taxonomies of an RSVP: its status, and its flags (check-in).
 */
const STATUS_TAXONOMY = '_gatherpress_rsvp_status';
const FLAG_TAXONOMY   = '_gatherpress_rsvp_flag';

/**
 * The Certificates screen's parent menu: GatherPress's event menu, so it sits
 * there the way CampTix's sits under Tickets (Tickets > Certificates).
 */
const PARENT_SLUG = 'edit.php?post_type=gatherpress_event';

/**
 * The settings tab's sub-page key in GatherPress > Settings. Its settings
 * page slug is Utility::prefix_key( SUB_PAGE ) (gatherpress_certificates).
 */
const SUB_PAGE = 'certificates';

/**
 * Hooks, when GatherPress is active.
 */
function register() {
	if ( ! Environment\has_gatherpress() ) {
		return;
	}
	// Importing GatherPress events is a GatherPress feature: it stays even when
	// CampTix is also active (the certificates menu itself then goes standalone).
	// The event is GatherPress's: its participants are opened from its row in the events list (Certificates).
	add_filter( 'post_row_actions', __NAMESPACE__ . '\row_actions', 10, 2 );
	add_action( 'evcert_event_participants_actions', __NAMESPACE__ . '\render_sync' );
	add_action( 'admin_post_evcert_gatherpress_open', __NAMESPACE__ . '\handle_open' );
	add_action( 'admin_post_evcert_gatherpress_sync', __NAMESPACE__ . '\handle_sync' );

	// Certificates lives under GatherPress only when it is the sole compatible
	// plugin; alongside CampTix it keeps its own menu (and its own settings).
	if ( Environment\has_camptix() ) {
		return;
	}
	register_screen();
}

/**
 * The menu and settings takeover, when GatherPress is the single compatible
 * plugin: Certificates becomes a submenu of GatherPress's event menu and its
 * settings move to a tab of GatherPress > Settings.
 */
function register_screen() {
	// The Certificates screen becomes a submenu of GatherPress's event menu.
	add_filter( 'evcert_admin_menu_parent', __NAMESPACE__ . '\menu_parent' );
	// Settings are saved by GatherPress's settings form (its option group).
	add_filter( 'evcert_settings_group', __NAMESPACE__ . '\settings_group' );
	// Settings: a Certificates tab in GatherPress > Settings, its sections and
	// fields rendered and saved by GatherPress's own settings form.
	add_filter( 'gatherpress_sub_pages', __NAMESPACE__ . '\add_settings_tab' );
	add_action( 'admin_init', __NAMESPACE__ . '\add_tab_fields' );
	remove_action( 'evcert_certificates_screen', 'EventsCertificate\Admin\render_settings_form' );
}

/**
 * Certificates is a submenu of GatherPress's event menu.
 *
 * @return string Parent menu slug.
 */
function menu_parent() {
	return PARENT_SLUG;
}

/**
 * The settings are saved by GatherPress's settings form (options group
 * gatherpress_settings), alongside GatherPress's own settings.
 *
 * @return string Settings group.
 */
function settings_group() {
	return 'gatherpress_settings';
}

/**
 * Adds a Certificates tab to GatherPress > Settings.
 *
 * @param array<string, array<string, mixed>> $sub_pages GatherPress settings sub-pages.
 * @return array<string, array<string, mixed>>
 */
function add_settings_tab( $sub_pages ) {
	$sub_pages[ SUB_PAGE ] = array(
		'name'     => __( 'Certificates', 'events-certificates' ),
		'priority' => 60,
	);

	return $sub_pages;
}

/**
 * Registers the Certificates settings fields on the GatherPress settings page
 * (the tab's prefixed slug), so GatherPress's settings form renders and saves them.
 */
function add_tab_fields() {
	if ( ! class_exists( '\GatherPress\Core\Utility' ) ) {
		return;
	}
	Admin\add_settings_fields( \GatherPress\Core\Utility::prefix_key( SUB_PAGE ) );
}

/**
 * The event here linked to a GatherPress event.
 *
 * @param int $gatherpress_id GatherPress event.
 * @return int Event ID, or 0.
 */
function linked( $gatherpress_id ) {
	$ids = get_posts(
		array(
			'post_type'   => Events\POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup when importing.
				array(
					'key'   => Events\META['source_ref'],
					'value' => (string) (int) $gatherpress_id,
				),
				array(
					'key'   => Events\META['source'],
					'value' => 'gatherpress',
				),
			),
		)
	);

	return $ids ? (int) $ids[0] : 0;
}

/**
 * A GatherPress event's fields for an event here.
 *
 * @param \WP_Post $post GatherPress event.
 * @return array<string, string>
 */
function fields( \WP_Post $post ) {
	$fields = array(
		'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'start'      => '',
		'end'        => '',
		'location'   => '',
		'link'       => (string) get_permalink( $post ),
		'source'     => 'gatherpress',
		'source_ref' => (string) $post->ID,
	);
	if ( class_exists( '\GatherPress\Core\Event\Event' ) ) {
		$event = new \GatherPress\Core\Event\Event( $post->ID );
		foreach ( array(
			'start' => 'get_datetime_start_iso',
			'end'   => 'get_datetime_end_iso',
		) as $field => $method ) {
			$iso  = method_exists( $event, $method ) ? (string) $event->$method() : '';
			$time = '' !== $iso ? date_create_immutable( $iso ) : false;
			if ( $time ) {
				$fields[ $field ] = $time->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' );
			}
		}
		$venue              = method_exists( $event, 'get_venue_information' ) ? (array) $event->get_venue_information() : array();
		$fields['location'] = sanitize_text_field( (string) ( $venue['name'] ?? '' ) );
	}
	if ( '' === $fields['start'] ) {
		$fields['start'] = get_post_time( 'Y-m-d H:i', false, $post );
	}

	return $fields;
}

/**
 * The approved RSVPs of a GatherPress event, as participants: attending
 * ones, with check-in as attended.
 *
 * @param int $gatherpress_id GatherPress event.
 * @return array<int, array<string, string>>
 */
function participants( $gatherpress_id ) {
	// GatherPress hides RSVPs from comment queries; this query asks for them by type (its documented filter).
	$include = static function ( $exclude, $query ) {
		return RSVP_TYPE === ( $query->query_vars['type'] ?? '' ) ? false : $exclude;
	};
	add_filter( 'gatherpress_rsvp_comment_query_exclusion', $include, 10, 2 );
	$rsvps = get_comments(
		array(
			'post_id' => (int) $gatherpress_id,
			'type'    => RSVP_TYPE,
			'status'  => 'approve',
			'number'  => 0,
		)
	);
	remove_filter( 'gatherpress_rsvp_comment_query_exclusion', $include, 10 );

	$people = array();
	foreach ( (array) $rsvps as $rsvp ) {
		$status = wp_get_object_terms( (int) $rsvp->comment_ID, STATUS_TAXONOMY, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $status ) || ! in_array( 'attending', (array) $status, true ) ) {
			continue;
		}
		$flags = wp_get_object_terms( (int) $rsvp->comment_ID, FLAG_TAXONOMY, array( 'fields' => 'slugs' ) );
		$user  = $rsvp->user_id ? get_userdata( (int) $rsvp->user_id ) : false;
		$name  = $user ? $user->display_name : (string) $rsvp->comment_author;
		if ( '' === trim( $name ) ) {
			continue;
		}
		$person = array(
			'name'       => $name,
			'email'      => $user ? $user->user_email : (string) $rsvp->comment_author_email,
			'source'     => 'gatherpress',
			'source_ref' => (string) $rsvp->comment_ID,
		);
		// Only a check-in sets the status: syncing never demotes someone marked attended here.
		if ( ! is_wp_error( $flags ) && in_array( 'checked-in', (array) $flags, true ) ) {
			$person['status'] = 'attended';
		}
		$people[] = $person;
	}

	return add_organizers( $people );
}

/**
 * The users GatherPress lists as organizers (Settings → Roles), by role of this
 * plugin. The filter lets a site add more roles (speakers, volunteers) with
 * their user IDs.
 *
 * @return array<string, int[]> Role (attendee, speaker, organizer, volunteer, sponsor) => user IDs.
 */
function role_users() {
	$ids = array();
	if ( class_exists( '\GatherPress\Core\Settings' ) ) {
		$json = \GatherPress\Core\Settings::get_instance()->get( 'organizer' );
		foreach ( (array) json_decode( is_string( $json ) ? $json : '[]' ) as $user ) {
			if ( isset( $user->id ) ) {
				$ids[] = (int) $user->id;
			}
		}
	}

	/**
	 * Filters who gets which role in a GatherPress event.
	 *
	 * @param array<string, int[]> $by_role Role => user IDs (organizer: the site's organizers).
	 */
	$by_role = apply_filters( 'evcert_gatherpress_roles', array( 'organizer' => $ids ) );

	return is_array( $by_role ) ? $by_role : array();
}

/**
 * Adds the organizers (and any other role the filter gives) to the participants: someone who also
 * has an RSVP gains the role; someone without one is a new person who attended (they were there).
 *
 * @param array<int, array<string, mixed>> $people Participants from the RSVPs.
 * @return array<int, array<string, mixed>>
 */
function add_organizers( array $people ) {
	foreach ( role_users() as $role => $user_ids ) {
		foreach ( array_unique( array_map( 'absint', (array) $user_ids ) ) as $user_id ) {
			$user = $user_id ? get_userdata( $user_id ) : false;
			if ( ! $user ) {
				continue;
			}
			foreach ( $people as $i => $person ) {
				if ( '' !== (string) $person['email'] && 0 === strcasecmp( (string) $person['email'], $user->user_email ) ) {
					$people[ $i ]['roles'] = array_values( array_unique( array_merge( (array) ( $person['roles'] ?? array( 'attendee' ) ), array( $role ) ) ) );
					continue 2;
				}
			}
			$people[] = array(
				'name'       => $user->display_name,
				'email'      => $user->user_email,
				'roles'      => array( $role ),
				'status'     => 'attended',
				'source'     => 'gatherpress',
				'source_ref' => 'user-' . $user_id,
			);
		}
	}

	return $people;
}

/**
 * Imports (or syncs again) a GatherPress event.
 *
 * @param int $gatherpress_id GatherPress event.
 * @return array{event: int, added: int, updated: int}|\WP_Error
 */
function import( $gatherpress_id ) {
	$post = get_post( (int) $gatherpress_id );
	if ( ! $post || EVENT_TYPE !== $post->post_type ) {
		return new \WP_Error( 'evcert_invalid_gatherpress_event_id', __( 'That GatherPress event no longer exists.', 'events-certificates' ) );
	}
	$event_id = linked( $post->ID );
	if ( ! $event_id ) {
		$event_id = Events\save( fields( $post ) );
		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}
	}
	$result = People\import( $event_id, participants( $post->ID ) );

	return array(
		'event'   => (int) $event_id,
		'added'   => $result['added'],
		'updated' => $result['updated'],
	);
}

/**
 * Sync again, on an event imported from GatherPress.
 *
 * @param array<string, string> $event Event.
 */
function render_sync( array $event ) {
	if ( 'gatherpress' !== $event['source'] || ! get_post( (int) $event['source_ref'] ) ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="evcert-gatherpress">
		<?php wp_nonce_field( 'evcert_gatherpress_sync_' . (int) $event['id'] ); ?>
		<input type="hidden" name="action" value="evcert_gatherpress_sync">
		<input type="hidden" name="evcert_event_id" value="<?php echo esc_attr( (string) $event['id'] ); ?>">
		<span><?php esc_html_e( 'From GatherPress: RSVPs "attending" come in as registered, check-ins as attended.', 'events-certificates' ); ?></span>
		<button type="submit" class="button"><?php esc_html_e( 'Sync with GatherPress', 'events-certificates' ); ?></button>
	</form>
	<?php
}

/**
 * Back to the event with the import's notice.
 *
 * @param array{event: int, added: int, updated: int}|\WP_Error $result Import.
 */
function back( $result ) {
	if ( is_wp_error( $result ) ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
		wp_safe_redirect( EventsAdmin\url( array( 'evcert_done' => 'error' ) ) );
		exit;
	}
	wp_safe_redirect(
		EventsAdmin\url(
			array(
				'event'          => $result['event'],
				'view'           => 'participants',
				'evcert_done'    => 'imported',
				'evcert_added'   => $result['added'],
				'evcert_updated' => $result['updated'],
			)
		)
	);
	exit;
}

/**
 * A "Certificates" action on a GatherPress event's row: its RSVPs come in (or are synced) and its participants open.
 *
 * @param array<string, string> $actions Row actions.
 * @param \WP_Post              $post    Event.
 * @return array<string, string>
 */
function row_actions( $actions, $post ) {
	if ( EVENT_TYPE !== $post->post_type || ! current_user_can( Admin\CAPABILITY ) ) {
		return $actions;
	}
	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action'            => 'evcert_gatherpress_open',
				'gatherpress_event' => (int) $post->ID,
			),
			admin_url( 'admin-post.php' )
		),
		'evcert_gatherpress_open_' . (int) $post->ID
	);

	return $actions + array( 'evcert_certificates' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Certificates', 'events-certificates' ) ) );
}

/**
 * Handles the Certificates row action: imports (or syncs) the event and opens its participants.
 */
function handle_open() {
	$id = isset( $_GET['gatherpress_event'] ) ? absint( $_GET['gatherpress_event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the nonce, checked on the next line.
	check_admin_referer( 'evcert_gatherpress_open_' . $id );
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage certificates.', 'events-certificates' ), 403 );
	}
	back( import( $id ) );
}

/**
 * Handles Sync with GatherPress.
 */
function handle_sync() {
	$id = isset( $_POST['evcert_event_id'] ) ? absint( $_POST['evcert_event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce, checked on the next line.
	check_admin_referer( 'evcert_gatherpress_sync_' . $id );
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage certificates.', 'events-certificates' ), 403 );
	}
	$event = Events\get( $id );
	back( $event && 'gatherpress' === $event['source'] ? import( (int) $event['source_ref'] ) : new \WP_Error( 'evcert_invalid_event_id', __( 'That event is not linked to GatherPress.', 'events-certificates' ) ) );
}
