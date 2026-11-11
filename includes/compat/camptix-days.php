<?php
/**
 * Presence per day on CampTix's Attendees screen (Tickets > Attendees): a
 * "Days" column with one badge per day of the event, like the admin flags'
 * badges, that marks or clears that day; a "Day" filter that shows only that
 * day's badge (and makes CampTix's "Mark as attended" mark that day); without
 * a filter, "Mark as attended" asks which days. Bulk actions mark a day for
 * many attendees, and the attendee's box has a checkbox per day.
 *
 * CampTix's own "attended" (tix_attended) follows: present on any day.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\CampTix\Days;

use EventsCertificate\Days;

defined( 'ABSPATH' ) || exit;

/**
 * Attendee meta: the IDs of the days they were present.
 */
const META = 'evcert_days';

/**
 * Query argument of the Day filter.
 */
const FILTER = 'evcert_day';

/**
 * Hooks (CampTix is active).
 */
function register() {
	add_filter( 'manage_tix_attendee_posts_columns', __NAMESPACE__ . '\add_column', 20 );
	add_action( 'manage_tix_attendee_posts_custom_column', __NAMESPACE__ . '\render_column', 10, 2 );
	add_action( 'restrict_manage_posts', __NAMESPACE__ . '\render_filter' );
	add_action( 'wp_ajax_evcert_attendee_day', __NAMESPACE__ . '\ajax_toggle' );
	add_filter( 'bulk_actions-edit-tix_attendee', __NAMESPACE__ . '\bulk_actions' );
	add_filter( 'handle_bulk_actions-edit-tix_attendee', __NAMESPACE__ . '\handle_bulk', 10, 3 );
	add_action( 'admin_notices', __NAMESPACE__ . '\bulk_notice' );
	add_action( 'camptix_attendee_submitdiv_misc', __NAMESPACE__ . '\render_box' );
	add_action( 'save_post_tix_attendee', __NAMESPACE__ . '\save_box' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue' );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\follow_attended', 10, 4 );
	add_action( 'added_post_meta', __NAMESPACE__ . '\follow_attended', 10, 4 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\follow_unattended', 10, 3 );
}

/**
 * The days an attendee was present (known days only).
 *
 * @param int $attendee_id Attendee ID.
 * @return string[] Day IDs.
 */
function present( $attendee_id ) {
	$known = wp_list_pluck( Days\all(), 'id' );

	return array_values( array_intersect( $known, array_map( 'strval', (array) get_post_meta( $attendee_id, META, true ) ) ) );
}

/**
 * Sets the days an attendee was present; CampTix's "attended" follows.
 *
 * @param int      $attendee_id Attendee ID.
 * @param string[] $days        Day IDs.
 */
function set_present( $attendee_id, array $days ) {
	$known = wp_list_pluck( Days\all(), 'id' );
	$days  = array_values( array_intersect( $known, array_unique( array_map( 'strval', $days ) ) ) );
	following( true );
	if ( $days ) {
		update_post_meta( $attendee_id, META, $days );
		update_post_meta( $attendee_id, 'tix_attended', true );
	} else {
		delete_post_meta( $attendee_id, META );
		delete_post_meta( $attendee_id, 'tix_attended' );
	}
	following( false );
}

/**
 * Whether set_present() is writing (so the meta hooks don't answer it).
 *
 * @param bool|null $set New state.
 * @return bool
 */
function following( $set = null ) {
	static $state = false;
	if ( null !== $set ) {
		$state = $set;
	}

	return $state;
}

/**
 * CampTix marked someone attended (its button, its box): with a Day filter
 * that day, with a single day that day; otherwise the days stay as they are.
 *
 * @param int    $meta_id   Meta ID.
 * @param int    $object_id Post ID.
 * @param string $key       Meta key.
 * @param mixed  $value     Value.
 */
function follow_attended( $meta_id, $object_id, $key, $value ) {
	if ( 'tix_attended' !== $key || ! $value || following() || 'tix_attendee' !== get_post_type( $object_id ) || present( $object_id ) ) {
		return;
	}
	$days = Days\all();
	$day  = filtered_day();
	if ( '' === $day && 1 === count( $days ) ) {
		$day = $days[0]['id'];
	}
	if ( '' !== $day ) {
		following( true );
		update_post_meta( $object_id, META, array( $day ) );
		following( false );
	}
}

/**
 * CampTix cleared "attended": no day either.
 *
 * @param string[] $meta_ids  Meta IDs.
 * @param int      $object_id Post ID.
 * @param string   $key       Meta key.
 */
function follow_unattended( $meta_ids, $object_id, $key ) {
	if ( 'tix_attended' === $key && ! following() && 'tix_attendee' === get_post_type( $object_id ) ) {
		following( true );
		delete_post_meta( $object_id, META );
		following( false );
	}
}

/**
 * The Day filter's day, from the list's address or the request's referer.
 *
 * @return string Day ID, or ''.
 */
function filtered_day() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
	$day = isset( $_REQUEST[ FILTER ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ FILTER ] ) ) : '';
	if ( '' === $day && wp_doing_ajax() ) {
		$query = (string) wp_parse_url( (string) wp_get_referer(), PHP_URL_QUERY );
		parse_str( $query, $args );
		$day = isset( $args[ FILTER ] ) ? sanitize_text_field( (string) $args[ FILTER ] ) : '';
	}

	return Days\get( $day ) ? $day : '';
}

/**
 * Adds the Days column after CampTix's Attended.
 *
 * @param array<string, string> $columns Columns.
 * @return array<string, string>
 */
function add_column( $columns ) {
	if ( ! Days\all() ) {
		return $columns;
	}
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'attended' === $key ) {
			$out['evcert_days'] = __( 'Days', 'events-certificate' );
		}
	}
	if ( ! isset( $out['evcert_days'] ) ) {
		$out['evcert_days'] = __( 'Days', 'events-certificate' );
	}

	return $out;
}

/**
 * The badges of one attendee: every day, or the filtered one.
 *
 * @param int $attendee_id Attendee ID.
 * @return string
 */
function badges( $attendee_id ) {
	$present  = present( $attendee_id );
	$filtered = filtered_day();
	$html     = '';
	foreach ( Days\all() as $day ) {
		if ( '' !== $filtered && $filtered !== $day['id'] ) {
			continue;
		}
		$on    = in_array( $day['id'], $present, true );
		$html .= sprintf(
			'<button type="button" class="evcert-day%1$s" aria-pressed="%2$s" data-attendee="%3$d" data-day="%4$s" title="%5$s">%6$s</button> ',
			$on ? ' is-present' : '',
			$on ? 'true' : 'false',
			(int) $attendee_id,
			esc_attr( $day['id'] ),
			esc_attr(
				sprintf(
					/* translators: 1: day name, 2: present or not. */
					__( '%1$s: %2$s', 'events-certificate' ),
					Days\name( $day ),
					$on ? __( 'present (click to clear)', 'events-certificate' ) : __( 'not marked (click to mark)', 'events-certificate' )
				)
			),
			esc_html( Days\short_date( $day ) )
		);
	}

	return '<span class="evcert-days" data-nonce="' . esc_attr( wp_create_nonce( 'evcert_day_' . $attendee_id ) ) . '">' . $html . '</span>';
}

/**
 * Prints the Days column.
 *
 * @param string $column      Column.
 * @param int    $attendee_id Attendee ID.
 */
function render_column( $column, $attendee_id ) {
	if ( 'evcert_days' === $column && 'publish' === get_post_status( $attendee_id ) ) {
		echo badges( (int) $attendee_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badges().
	}
}

/**
 * The Day filter above the list.
 *
 * @param string $post_type Post type.
 */
function render_filter( $post_type ) {
	if ( 'tix_attendee' !== $post_type || ! Days\all() ) {
		return;
	}
	$current = filtered_day();
	echo '<label class="screen-reader-text" for="evcert-day-filter">' . esc_html__( 'Filter by day', 'events-certificate' ) . '</label>';
	echo '<select name="' . esc_attr( FILTER ) . '" id="evcert-day-filter"><option value="">' . esc_html__( 'All days', 'events-certificate' ) . '</option>';
	foreach ( Days\all() as $day ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $day['id'] ), selected( $current, $day['id'], false ), esc_html( Days\short_date( $day ) . ' — ' . Days\name( $day ) ) );
	}
	echo '</select>';
}

/**
 * AJAX: marks or clears one day (or sets the days, from the picker).
 */
function ajax_toggle() {
	$attendee_id = isset( $_POST['attendee'] ) ? absint( $_POST['attendee'] ) : 0;
	check_ajax_referer( 'evcert_day_' . $attendee_id, 'nonce' );
	if ( ! $attendee_id || 'tix_attendee' !== get_post_type( $attendee_id ) || ! current_user_can( 'edit_post', $attendee_id ) ) {
		wp_send_json_error( null, 403 );
	}
	$days = present( $attendee_id );
	if ( isset( $_POST['days'] ) ) {
		$days = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['days'] ) );
	} else {
		$day  = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : '';
		$on   = ! empty( $_POST['present'] );
		$days = $on ? array_merge( $days, array( $day ) ) : array_diff( $days, array( $day ) );
	}
	set_present( $attendee_id, $days );

	wp_send_json_success(
		array(
			'days'     => present( $attendee_id ),
			'attended' => (bool) get_post_meta( $attendee_id, 'tix_attended', true ),
			'badges'   => badges( $attendee_id ),
		)
	);
}

/**
 * Bulk actions: mark presence on a day.
 *
 * @param array<string, string> $actions Actions.
 * @return array<string, string>
 */
function bulk_actions( $actions ) {
	foreach ( Days\all() as $day ) {
		/* translators: %s: day of the event, e.g. "30/10 — Contributor Day". */
		$actions[ 'evcert_day_' . $day['id'] ] = sprintf( __( 'Mark present on %s', 'events-certificate' ), Days\short_date( $day ) . ' — ' . Days\name( $day ) );
	}

	return $actions;
}

/**
 * Runs a "mark present on" bulk action.
 *
 * @param string $redirect Redirect URL.
 * @param string $action   Action.
 * @param int[]  $ids      Attendee IDs.
 * @return string
 */
function handle_bulk( $redirect, $action, $ids ) {
	if ( 0 !== strpos( $action, 'evcert_day_' ) ) {
		return $redirect;
	}
	$day = substr( $action, strlen( 'evcert_day_' ) );
	if ( ! Days\get( $day ) ) {
		return $redirect;
	}
	$marked = 0;
	foreach ( array_map( 'absint', (array) $ids ) as $id ) {
		if ( 'tix_attendee' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
			set_present( $id, array_merge( present( $id ), array( $day ) ) );
			++$marked;
		}
	}

	return add_query_arg( 'evcert_marked', $marked, $redirect );
}

/**
 * Notice after a bulk action.
 */
function bulk_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a count.
	if ( ! isset( $_GET['evcert_marked'] ) || 'edit-tix_attendee' !== ( get_current_screen()->id ?? '' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a count.
	$n = absint( $_GET['evcert_marked'] );
	/* translators: %d: number of attendees. */
	printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d attendee marked present.', '%d attendees marked present.', $n, 'events-certificate' ), $n ) ) );
}

/**
 * A checkbox per day in the attendee's box.
 *
 * @param \WP_Post $attendee Attendee.
 */
function render_box( $attendee ) {
	$days = Days\all();
	if ( ! $days ) {
		return;
	}
	$present = present( $attendee->ID );
	echo '<div class="misc-pub-section evcert-days-box"><p><strong>' . esc_html__( 'Present on', 'events-certificate' ) . '</strong></p>';
	wp_nonce_field( 'evcert_days_box_' . $attendee->ID, 'evcert_days_nonce' );
	echo '<input type="hidden" name="evcert_days_sent" value="1">';
	foreach ( $days as $day ) {
		printf(
			'<label style="display:block"><input type="checkbox" name="evcert_days[]" value="%s"%s> %s</label>',
			esc_attr( $day['id'] ),
			checked( in_array( $day['id'], $present, true ), true, false ),
			esc_html( Days\short_date( $day ) . ' — ' . Days\name( $day ) )
		);
	}
	echo '</div>';
}

/**
 * Saves the attendee's days from their box (after CampTix's own save).
 *
 * @param int $attendee_id Attendee ID.
 */
function save_box( $attendee_id ) {
	if ( ! isset( $_POST['evcert_days_sent'], $_POST['evcert_days_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evcert_days_nonce'] ) ), 'evcert_days_box_' . $attendee_id ) || ! current_user_can( 'edit_post', $attendee_id ) ) {
		return;
	}
	$days = isset( $_POST['evcert_days'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['evcert_days'] ) ) : array();
	// CampTix saves its own "attended" box on save_post (later); the days decide.
	add_action(
		'save_post',
		static function ( $id ) use ( $attendee_id, $days ) {
			if ( (int) $id === (int) $attendee_id ) {
				set_present( $attendee_id, $days );
			}
		},
		99
	);
}

/**
 * The column's script and look, on the Attendees list.
 *
 * @param string $hook Admin page.
 */
function enqueue( $hook ) {
	if ( 'edit.php' !== $hook || 'tix_attendee' !== ( get_current_screen()->post_type ?? '' ) || ! Days\all() ) {
		return;
	}
	$base = plugins_url( '', dirname( __DIR__, 2 ) . '/events-certificate.php' );
	wp_enqueue_script( 'evcert-attendee-days', $base . '/assets/attendee-days.js', array(), \EventsCertificate\VERSION, true );
	wp_add_inline_script(
		'evcert-attendee-days',
		'window.evcertDays = ' . wp_json_encode(
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'filtered' => filtered_day(),
				'days'     => array_map(
					static function ( $day ) {
						return array(
							'id'    => $day['id'],
							'label' => Days\short_date( $day ) . ' — ' . Days\name( $day ),
						);
					},
					Days\all()
				),
				'i18n'     => array(
					'which'   => __( 'Present on which days?', 'events-certificate' ),
					'confirm' => __( 'Confirm', 'events-certificate' ),
					'cancel'  => __( 'Cancel', 'events-certificate' ),
				),
			)
		) . ';',
		'before'
	);
	wp_register_style( 'evcert-attendee-days', false, array(), \EventsCertificate\VERSION );
	wp_enqueue_style( 'evcert-attendee-days' );
	wp_add_inline_style(
		'evcert-attendee-days',
		'.evcert-day{border:1px solid #c3c4c7;background:#f6f7f7;color:#50575e;border-radius:3px;padding:1px 6px;font-size:12px;line-height:18px;cursor:pointer;margin:0 2px 2px 0}' .
		'.evcert-day.is-present{background:#00a32a;border-color:#00a32a;color:#fff}' .
		'.evcert-day[aria-busy=true]{opacity:.5}' .
		'.evcert-days-picker{display:flex;flex-direction:column;gap:4px;margin-top:6px;padding:8px;border:1px solid #c3c4c7;background:#fff;max-width:240px}' .
		'.column-evcert_days{width:12em}'
	);
}
