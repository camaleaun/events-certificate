<?php
/**
 * The Certificates screen's table, a WP_List_Table like CampTix's Attendees:
 * views by status, search by name, e-mail or code, sortable columns,
 * pagination with screen options, row actions and bulk revoke.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Admin;

use EventsCertificate\Certificates;
use EventsCertificate\Render;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Issued certificates.
 */
class List_Table extends \WP_List_Table {

	/**
	 * Statuses shown as views (drafts are not certificates yet).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'issued', 'replaced', 'revoked' );

	/**
	 * Sets the table up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'certificate',
				'plural'   => 'certificates',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			'issued'   => _x( 'Issued', 'certificate status', 'events-certificates' ),
			'replaced' => _x( 'Replaced', 'certificate status', 'events-certificates' ),
			'revoked'  => _x( 'Revoked', 'certificate status', 'events-certificates' ),
		);
	}

	/**
	 * View labels (plural, like Published in the posts list).
	 *
	 * @return array<string, string>
	 */
	public static function view_labels() {
		return array(
			'issued'   => _x( 'Issued', 'certificates view', 'events-certificates' ),
			'replaced' => _x( 'Replaced', 'certificates view', 'events-certificates' ),
			'revoked'  => _x( 'Revoked', 'certificates view', 'events-certificates' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		// Without verification no certificate shows a code, so there is no code column, and nothing to revoke in bulk.
		$verification = \EventsCertificate\Environment\verification_enabled();

		return array_filter(
			array(
				'cb'        => $verification ? '<input type="checkbox" />' : '',
				'name'      => __( 'Name', 'events-certificates' ),
				'code'      => $verification ? __( 'Code', 'events-certificates' ) : '',
				'role'      => __( 'Role', 'events-certificates' ),
				'event'     => __( 'Event', 'events-certificates' ),
				'hours'     => __( 'Workload', 'events-certificates' ),
				'issued_at' => __( 'Date', 'events-certificates' ),
			),
			'strlen'
		);
	}

	/**
	 * Sortable columns: key => [orderby, descending first].
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		$sortable = array(
			'name'      => array( 'name', false ),
			'issued_at' => array( 'issued_at', true ),
		);
		if ( \EventsCertificate\Environment\verification_enabled() ) {
			$sortable['code'] = array( 'code', false );
		}

		return $sortable;
	}

	/**
	 * The primary column (holds the row actions).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Views: all and per status, with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$counts  = counts();
		$current = $this->current_status();
		$base    = remove_query_arg( array( 'status', 'paged', 's' ) );
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html_x( 'All', 'certificates view', 'events-certificates' ),
				esc_html( number_format_i18n( array_sum( array_intersect_key( $counts, array_flip( self::STATUSES ) ) ) ) )
			),
		);
		foreach ( self::view_labels() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return \EventsCertificate\Environment\verification_enabled() ? array( 'revoke' => __( 'Revoke', 'events-certificates' ) ) : array();
	}

	/**
	 * The status view asked for.
	 *
	 * @return string
	 */
	private function current_status() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.

		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	/**
	 * The role chosen in the filter above the list (a tag), or ''.
	 *
	 * @return string
	 */
	private function current_role() {
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.

		return isset( \EventsCertificate\Designs\role_labels()[ $role ] ) ? $role : '';
	}

	/**
	 * The event chosen in the filter, or 0.
	 *
	 * @return int
	 */
	private function current_event() {
		return isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
	}

	/**
	 * Filters above the list, like the posts list's category filter: a select of roles, a select of events and Filter.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="filter-by-role">' . esc_html__( 'Filter by role', 'events-certificates' ) . '</label>';
		echo '<select name="role" id="filter-by-role"><option value="">' . esc_html__( 'All roles', 'events-certificates' ) . '</option>';
		foreach ( \EventsCertificate\Designs\role_labels() as $role => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $role ), selected( $this->current_role(), $role, false ), esc_html( $label ) );
		}
		echo '</select>';
		$events = \EventsCertificate\Events\all();
		if ( $events ) {
			echo '<label class="screen-reader-text" for="filter-by-event">' . esc_html__( 'Filter by event', 'events-certificates' ) . '</label>';
			echo '<select name="event_id" id="filter-by-event"><option value="0">' . esc_html__( 'All events', 'events-certificates' ) . '</option>';
			foreach ( $events as $event ) {
				printf( '<option value="%d"%s>%s</option>', (int) $event->ID, selected( $this->current_event(), (int) $event->ID, false ), esc_html( $event->post_title ) );
			}
			echo '</select>';
		}
		submit_button( __( 'Filter', 'events-certificates' ), '', 'filter_action', false, array( 'id' => 'evcert-post-query-submit' ) );
		echo '</div>';
	}

	/**
	 * Loads the page of certificates.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'evcert_certificates_per_page', 20 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and sorting.
		$result = Certificates\query(
			array(
				'status'   => $this->current_status(),
				'role'     => $this->current_role(),
				'event_id' => $this->current_event(),
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'issued_at',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items = $result['rows'];
		$total       = $result['total'];

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when there are none.
	 */
	public function no_items() {
		esc_html_e( 'No certificates found.', 'events-certificates' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="certificate[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Name, state, e-mail and row actions.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_name( $item ) {
		$url     = Certificates\url( $item['code'] );
		$actions = array(
			'view' => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html__( 'View', 'events-certificates' ) ),
		);
		if ( 'issued' === $item['status'] ) {
			$actions['pdf']     = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( \EventsCertificate\Verify\pdf_url( $item ) ), esc_html__( 'PDF', 'events-certificates' ) );
			$verification       = \EventsCertificate\Environment\verification_enabled();
			$actions['reissue'] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'evcert_reissue', (int) $item['id'], page_url() ) ), $verification ? esc_html__( 'Correct and reissue', 'events-certificates' ) : esc_html__( 'Edit', 'events-certificates' ) );
		}
		if ( 'issued' === $item['status'] && \EventsCertificate\Environment\verification_enabled() ) {
			$actions['revoke'] = sprintf(
				'<a href="%s" class="submitdelete" data-evcert-confirm="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'      => 'revoke',
								'certificate' => (int) $item['id'],
							)
						),
						'bulk-certificates'
					)
				),
				esc_attr__( 'Revoke this certificate? Its code will then say it was revoked.', 'events-certificates' ),
				esc_html__( 'Revoke', 'events-certificates' )
			);
		}
		$source = $this->source_link( $item );
		if ( '' !== $source ) {
			$actions['source'] = $source;
		}

		// The state beside the name, like a post's "— Draft": only a certificate that is not valid says it.
		$state = '';
		if ( 'issued' !== $item['status'] ) {
			$state = ' &#8212; <span class="post-state">' . esc_html( self::status_labels()[ $item['status'] ] ?? $item['status'] ) . '</span>';
		}
		$note = '';
		if ( 'replaced' === $item['status'] && ! empty( $item['replaced_by'] ) ) {
			$new = Certificates\get( (int) $item['replaced_by'] );
			if ( $new ) {
				/* translators: %s: code of the new certificate. */
				$note = sprintf( esc_html__( 'Replaced by %s', 'events-certificates' ), '<code>' . esc_html( $new['code'] ) . '</code>' );
			}
		} elseif ( 'revoked' === $item['status'] && $item['revoked_reason'] ) {
			$note = esc_html( $item['revoked_reason'] );
		}
		$under = array_filter( array( $item['email'] ? esc_html( $item['email'] ) : '', $note ) );

		return sprintf(
			'%s<strong><a class="row-title" href="%s" target="_blank">%s</a>%s</strong>%s%s',
			$this->avatar( $item ),
			esc_url( $url ),
			esc_html( $item['name'] ),
			$state,
			$under ? '<br><span class="description">' . implode( ' · ', $under ) . '</span>' : '',
			$this->row_actions( $actions )
		);
	}

	/**
	 * Where it came from, as a row action: the CampTix attendee or the event's participants.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string Link, or ''.
	 */
	private function source_link( array $item ) {
		if ( 'camptix' === $item['source'] && get_post( (int) $item['source_ref'] ) ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( (int) $item['source_ref'] ) ), esc_html__( 'Attendee', 'events-certificates' ) );
		}
		$event = \EventsCertificate\Events\of_certificate( $item );
		if ( $event ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( \EventsCertificate\EventsAdmin\participants_url( (int) $event['id'] ) ), esc_html__( 'Participant', 'events-certificates' ) );
		}

		return '';
	}

	/**
	 * The participant's avatar, as WordPress.org shows it: from their
	 * WordPress.org username (wordpress.org/grav-redirect.php sends the
	 * browser to that account's Gravatar), else from the certificate's email.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	private function avatar( array $item ) {
		$size     = 32;
		$username = sanitize_user( (string) ( $item['wporg_username'] ?? '' ), true );
		if ( '' !== $username ) {
			return sprintf(
				'<img class="evcert-avatar avatar avatar-%1$d photo" src="%2$s" srcset="%3$s 2x" width="%1$d" height="%1$d" alt="" loading="lazy">',
				$size,
				esc_url(
					add_query_arg(
						array(
							'user' => $username,
							's'    => $size,
						),
						'https://wordpress.org/grav-redirect.php'
					)
				),
				esc_url(
					add_query_arg(
						array(
							'user' => $username,
							's'    => $size * 2,
						),
						'https://wordpress.org/grav-redirect.php'
					)
				)
			);
		}

		return (string) get_avatar( (string) ( $item['email'] ?? '' ), $size, '', '', array( 'class' => 'evcert-avatar' ) );
	}

	/**
	 * Code.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_code( $item ) {
		return '<code>' . esc_html( $item['code'] ) . '</code>';
	}

	/**
	 * Role, as a link that filters the list, like a post's tags and categories.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_role( $item ) {
		$role = (string) $item['role'];
		if ( '' === $role ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No role', 'events-certificates' ) . '</span>';
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'role', $role, remove_query_arg( array( 'paged', 'role' ) ) ) ), esc_html( \EventsCertificate\Designs\role_labels()[ $role ] ?? $role ) );
	}

	/**
	 * Event.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_event( $item ) {
		return esc_html( \EventsCertificate\Render\event_title( $item ) );
	}

	/**
	 * Workload.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_hours( $item ) {
		$values = Render\values( $item );

		return '' === $values['hours'] ? '—' : esc_html( Render\field_value( 'hours', '', $values ) );
	}

	/**
	 * Date, like a post's: the state and when ("Issued" 2026/11/18 at 3:00 pm).
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_issued_at( $item ) {
		$ts = strtotime( $item['issued_at'] . ' UTC' );

		return esc_html( self::status_labels()[ $item['status'] ] ?? $item['status'] ) . '<br>' . esc_html(
			sprintf(
				/* translators: 1: date, 2: time. */
				__( '%1$s at %2$s', 'events-certificates' ),
				wp_date( get_option( 'date_format' ), $ts ),
				wp_date( get_option( 'time_format' ), $ts )
			)
		);
	}
}
