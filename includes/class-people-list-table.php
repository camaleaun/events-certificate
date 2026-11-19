<?php
/**
 * An event's participants: their roles as tags, each with the certificate of
 * that role, the status, and the actions to mark who came and issue certificates
 * (one by one or in bulk, all roles or one).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\EventsAdmin;

use EventsCertificate\Certificates;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Participants list.
 */
class People_List_Table extends \WP_List_Table {

	/**
	 * Event.
	 *
	 * @var int
	 */
	private $event_id;

	/**
	 * Setup.
	 *
	 * @param int $event_id Event.
	 */
	public function __construct( $event_id ) {
		$this->event_id = (int) $event_id;
		parent::__construct(
			array(
				'singular' => 'participant',
				'plural'   => 'participants',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox">',
			'name'         => __( 'Name', 'events-certificates' ),
			'roles'        => __( 'Roles', 'events-certificates' ),
			'certificates' => __( 'Certificates', 'events-certificates' ),
			'status'       => __( 'Status', 'events-certificates' ),
			'source'       => __( 'Added from', 'events-certificates' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array<int, string|bool>>
	 */
	protected function get_sortable_columns() {
		return array(
			'name'   => array( 'name', false ),
			'status' => array( 'status', false ),
		);
	}

	/**
	 * The primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * The status view chosen.
	 *
	 * @return string
	 */
	private function view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		$view = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $view, array_merge( People\STATUSES, array( 'certified' ) ), true ) ? $view : '';
	}

	/**
	 * The role view chosen: only the people who have that role.
	 *
	 * @return string
	 */
	private function role_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';

		return isset( \EventsCertificate\Designs\role_labels()[ $role ] ) ? $role : '';
	}

	/**
	 * Loads a page of participants.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'evcert_people_per_page', 50 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$result = People\query(
			$this->event_id,
			array(
				'status'   => $this->view(),
				'role'     => $this->role_view(),
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->items           = $result['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Status views with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$counts = People\counts( $this->event_id );
		$labels = array(
			''           => __( 'All', 'events-certificates' ),
			'registered' => People\status_labels()['registered'],
			'attended'   => People\status_labels()['attended'],
			'no_show'    => People\status_labels()['no_show'],
			'certified'  => __( 'With certificate', 'events-certificates' ),
		);
		$views  = array();
		foreach ( $labels as $key => $label ) {
			$args = array(
				'event' => $this->event_id,
				'view'  => 'participants',
			);
			if ( '' !== $key ) {
				$args['status'] = $key;
			}
			if ( '' !== $this->role_view() ) {
				$args['role'] = $this->role_view();
			}
			$views[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( url( $args ) ),
				$this->view() === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( '' === $key ? $counts['all'] : $counts[ $key ] ) )
			);
		}

		return $views;
	}

	/**
	 * Above the list: a select of the roles the event has with Filter (like the posts list's category filter), and
	 * the select to add a role to the people ticked or take it off ("Change role to…" of the users list).
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$counts = People\counts( $this->event_id )['roles'];
		$labels = \EventsCertificate\Designs\role_labels();
		echo '<div class="alignleft actions">';
		if ( count( $counts ) > 1 ) {
			echo '<label class="screen-reader-text" for="filter-by-role">' . esc_html__( 'Filter by role', 'events-certificates' ) . '</label>';
			echo '<select name="role" id="filter-by-role"><option value="">' . esc_html__( 'All roles', 'events-certificates' ) . '</option>';
			foreach ( array_intersect_key( $labels, $counts ) as $role => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $role ), selected( $this->role_view(), $role, false ), esc_html( sprintf( '%s (%s)', $label, number_format_i18n( $counts[ $role ] ) ) ) );
			}
			echo '</select>';
			if ( '' !== $this->view() ) {
				echo '<input type="hidden" name="status" value="' . esc_attr( $this->view() ) . '">';
			}
			submit_button( __( 'Filter', 'events-certificates' ), '', 'filter_action', false, array( 'id' => 'evcert-people-query-submit' ) );
		}
		echo '</div><div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="evcert-change-role">' . esc_html__( 'Role of the people ticked', 'events-certificates' ) . '</label>';
		echo '<select name="evcert_role" id="evcert-change-role"><option value="">' . esc_html__( 'Role…', 'events-certificates' ) . '</option>';
		foreach ( $labels as $role => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $role ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<button type="submit" class="button" name="evcert_role_do" value="add">' . esc_html__( 'Add role', 'events-certificates' ) . '</button> ';
		echo '<button type="submit" class="button" name="evcert_role_do" value="remove">' . esc_html__( 'Remove role', 'events-certificates' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'attended'   => __( 'Mark as attended', 'events-certificates' ),
			'no_show'    => __( 'Mark as no-show', 'events-certificates' ),
			'registered' => __( 'Mark as registered', 'events-certificates' ),
			'issue'      => __( 'Issue certificates', 'events-certificates' ),
		);
		// One role's certificates only, when the event has people in more than one.
		$roles = array_keys( People\counts( $this->event_id )['roles'] );
		if ( count( $roles ) > 1 ) {
			foreach ( array_intersect_key( \EventsCertificate\Designs\role_labels(), array_flip( $roles ) ) as $role => $label ) {
				/* translators: %s: a role (Speaker). */
				$actions[ 'issue_' . $role ] = sprintf( __( 'Issue certificates: %s only', 'events-certificates' ), $label );
			}
		}

		return $actions + array(
			'remove'       => __( 'Remove from the event', 'events-certificates' ),
			'download_zip' => __( 'Download certificates (ZIP)', 'events-certificates' ),
			'download_pdf' => __( 'Download certificates (single PDF)', 'events-certificates' ),
		);
	}

	/**
	 * When there are none.
	 */
	public function no_items() {
		esc_html_e( 'No participants here yet. Add them below: typed, pasted from a spreadsheet or from a Meetup export.', 'events-certificates' );
	}

	/**
	 * Checkbox.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="evcert-person-%1$d">%2$s</label><input type="checkbox" id="evcert-person-%1$d" name="participant[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %s: participant's name. */
			esc_html( sprintf( __( 'Select %s', 'events-certificates' ), $item['name'] ) )
		);
	}

	/**
	 * Name and e-mail, with the actions that apply.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_name( $item ) {
		$id      = (int) $item['id'];
		$actions = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( person_url( $this->event_id, $id ) ), esc_html__( 'Edit', 'events-certificates' ) ),
		);
		if ( 'attended' !== $item['status'] ) {
			$actions['attended'] = sprintf( '<a href="%s">%s</a>', esc_url( action_url( 'attended', $this->event_id, $id ) ), esc_html__( 'Attended', 'events-certificates' ) );
		}
		$missing = $this->missing( $item );
		if ( 'attended' === $item['status'] && $missing ) {
			$actions['issue'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( action_url( 'issue', $this->event_id, $id ) ),
				// With several roles: how many certificates it makes.
				count( $missing ) > 1 ? esc_html( sprintf( /* translators: %s: number of certificates. */ _n( 'Issue the missing certificate (%s)', 'Issue the missing certificates (%s)', count( $missing ), 'events-certificates' ), number_format_i18n( count( $missing ) ) ) ) : esc_html__( 'Issue certificate', 'events-certificates' )
			);
		}
		if ( 'no_show' !== $item['status'] ) {
			$actions['no_show'] = sprintf( '<a href="%s">%s</a>', esc_url( action_url( 'no_show', $this->event_id, $id ) ), esc_html__( 'No-show', 'events-certificates' ) );
		}
		$actions['remove'] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( action_url( 'remove', $this->event_id, $id ) ), esc_html__( 'Remove', 'events-certificates' ) );

		$email = '' !== (string) $item['email'] ? '<br><span class="description">' . esc_html( $item['email'] ) . '</span>' : '';

		return '<strong>' . esc_html( $item['name'] ) . '</strong>' . $email . $this->row_actions( $actions );
	}

	/**
	 * The roles of a person that have no valid certificate and were not revoked: what "issue" would make.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string[]
	 */
	private function missing( array $item ) {
		$missing = array();
		foreach ( array_keys( $item['roles'] ) as $role ) {
			$cert = People\latest_certificate( $item, $role );
			if ( ! $cert || ! in_array( $cert['status'], array( 'issued', 'revoked' ), true ) ) {
				$missing[] = $role;
			}
		}

		return $missing;
	}

	/**
	 * Roles, like a post's categories: links that filter the list, separated by commas.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_roles( $item ) {
		$labels = \EventsCertificate\Designs\role_labels();
		$links  = array();
		foreach ( array_keys( $item['roles'] ) as $role ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'role', $role, remove_query_arg( array( 'paged', 'role' ) ) ) ), esc_html( $labels[ $role ] ?? $role ) );
		}

		return $links ? implode( wp_get_list_item_separator(), $links ) : '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No role', 'events-certificates' ) . '</span>';
	}

	/**
	 * The certificate of each role, a line each: its code (a link), the code struck when revoked, a dash when none.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_certificates( $item ) {
		$labels = \EventsCertificate\Designs\role_labels();
		$lines  = array();
		foreach ( array_keys( $item['roles'] ) as $role ) {
			$cert  = People\latest_certificate( $item, $role );
			$label = esc_html( $labels[ $role ] ?? $role );
			if ( ! $cert ) {
				$lines[] = $label . ' <span aria-hidden="true">&#8212;</span>';
				continue;
			}
			// Without verification no code is shown: a link to the certificate says "View".
			$code    = '<a href="' . esc_url( Certificates\url( $cert['code'] ) ) . '" target="_blank" rel="noopener">' . ( \EventsCertificate\Environment\verification_enabled() ? '<code>' . esc_html( $cert['code'] ) . '</code>' : esc_html__( 'View', 'events-certificates' ) ) . '</a>';
			$lines[] = $label . ' ' . ( 'revoked' === $cert['status'] ? '<s>' . $code . '</s> <span class="post-state">' . esc_html__( 'Revoked', 'events-certificates' ) . '</span>' : $code );
		}

		return implode( '<br>', $lines );
	}

	/**
	 * Status, as a badge.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_status( $item ) {
		return sprintf( '<span class="evcert-badge is-%s">%s</span>', esc_attr( (string) $item['status'] ), esc_html( People\status_labels()[ $item['status'] ] ?? (string) $item['status'] ) );
	}

	/**
	 * Where the participant came from.
	 *
	 * @param array<string, mixed> $item Participant.
	 * @return string
	 */
	protected function column_source( $item ) {
		$labels = array(
			'manual'      => __( 'Typed or pasted', 'events-certificates' ),
			'csv'         => __( 'CSV file', 'events-certificates' ),
			'gatherpress' => 'GatherPress',
		);

		return esc_html( $labels[ $item['source'] ] ?? (string) $item['source'] );
	}
}
