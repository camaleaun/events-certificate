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
			'issued'   => _x( 'Issued', 'certificate status', 'events-certificate' ),
			'replaced' => _x( 'Replaced', 'certificate status', 'events-certificate' ),
			'revoked'  => _x( 'Revoked', 'certificate status', 'events-certificate' ),
		);
	}

	/**
	 * View labels (plural, like Published in the posts list).
	 *
	 * @return array<string, string>
	 */
	public static function view_labels() {
		return array(
			'issued'   => _x( 'Issued', 'certificates view', 'events-certificate' ),
			'replaced' => _x( 'Replaced', 'certificates view', 'events-certificate' ),
			'revoked'  => _x( 'Revoked', 'certificates view', 'events-certificate' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Name', 'events-certificate' ),
			'code'      => __( 'Code', 'events-certificate' ),
			'event'     => __( 'Event', 'events-certificate' ),
			'hours'     => __( 'Workload', 'events-certificate' ),
			'source'    => __( 'Source', 'events-certificate' ),
			'status'    => __( 'Status', 'events-certificate' ),
			'issued_at' => __( 'Issued on', 'events-certificate' ),
		);
	}

	/**
	 * Sortable columns: key => [orderby, descending first].
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'code'      => array( 'code', false ),
			'status'    => array( 'status', false ),
			'issued_at' => array( 'issued_at', true ),
		);
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
				esc_html_x( 'All', 'certificates view', 'events-certificate' ),
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
		return array( 'revoke' => __( 'Revoke', 'events-certificate' ) );
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
	 * Loads the page of certificates.
	 */
	public function prepare_items() {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and sorting.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'issued_at';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable
		$orderby  = in_array( $orderby, array( 'name', 'code', 'status', 'issued_at' ), true ) ? $orderby : 'issued_at';
		$per_page = $this->get_items_per_page( 'evcert_certificates_per_page', 20 );
		$status   = $this->current_status();

		$like   = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
		$offset = ( $this->get_pagenum() - 1 ) * $per_page;
		// Fixed SQL: empty filters match everything; the sort column is an identifier (%i), the order one of two strings.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table, not cached.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'draft' AND ( %s = '' OR status = %s ) AND ( %s = '' OR name LIKE %s OR email LIKE %s OR code LIKE %s )", Certificates\table(), $status, $status, $like, $like, $like, $like ) );
		if ( 'ASC' === $order ) {
			$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status <> 'draft' AND ( %s = '' OR status = %s ) AND ( %s = '' OR name LIKE %s OR email LIKE %s OR code LIKE %s ) ORDER BY %i ASC, id DESC LIMIT %d OFFSET %d", Certificates\table(), $status, $status, $like, $like, $like, $like, $orderby, $per_page, $offset ), ARRAY_A );
		} else {
			$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status <> 'draft' AND ( %s = '' OR status = %s ) AND ( %s = '' OR name LIKE %s OR email LIKE %s OR code LIKE %s ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d", Certificates\table(), $status, $status, $like, $like, $like, $like, $orderby, $per_page, $offset ), ARRAY_A );
		}
		// phpcs:enable

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
		esc_html_e( 'No certificates found.', 'events-certificate' );
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
	 * Name, e-mail and row actions.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_name( $item ) {
		$url     = Certificates\url( $item['code'] );
		$actions = array(
			'view' => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html__( 'View', 'events-certificate' ) ),
		);
		if ( 'issued' === $item['status'] ) {
			$actions['pdf']    = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( add_query_arg( 'format', 'pdf', $url ) ), esc_html__( 'PDF', 'events-certificate' ) );
			$actions['revoke'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(this.dataset.confirm)" data-confirm="%s">%s</a>',
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
				esc_attr__( 'Revoke this certificate? Its code will then say it was revoked.', 'events-certificate' ),
				esc_html__( 'Revoke', 'events-certificate' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%s" target="_blank">%s</a></strong>%s%s',
			esc_url( $url ),
			esc_html( $item['name'] ),
			$item['email'] ? '<br><span class="description">' . esc_html( $item['email'] ) . '</span>' : '',
			$this->row_actions( $actions )
		);
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
	 * Event.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_event( $item ) {
		return esc_html( $item['event_title'] );
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
	 * Where it came from (a CampTix attendee links to it).
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_source( $item ) {
		if ( 'camptix' === $item['source'] && get_post( (int) $item['source_ref'] ) ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( (int) $item['source_ref'] ) ), esc_html__( 'Attendee', 'events-certificate' ) );
		}

		return esc_html( 'manual' === $item['source'] ? __( 'Manual', 'events-certificate' ) : (string) $item['source'] );
	}

	/**
	 * Status (replaced links to the new one; revoked shows the reason).
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_status( $item ) {
		$label = self::status_labels()[ $item['status'] ] ?? $item['status'];
		if ( 'replaced' === $item['status'] && ! empty( $item['replaced_by'] ) ) {
			$new = Certificates\get( (int) $item['replaced_by'] );
			if ( $new ) {
				/* translators: %s: code of the new certificate. */
				return esc_html( $label ) . '<br><span class="description">' . sprintf( esc_html__( 'by %s', 'events-certificate' ), '<code>' . esc_html( $new['code'] ) . '</code>' ) . '</span>';
			}
		}
		if ( 'revoked' === $item['status'] && $item['revoked_reason'] ) {
			return esc_html( $label ) . '<br><span class="description">' . esc_html( $item['revoked_reason'] ) . '</span>';
		}

		return esc_html( $label );
	}

	/**
	 * Issue date.
	 *
	 * @param array<string, mixed> $item Certificate.
	 * @return string
	 */
	protected function column_issued_at( $item ) {
		$ts = strtotime( $item['issued_at'] . ' UTC' );

		return esc_html( wp_date( get_option( 'date_format' ), $ts ) ) . '<br><span class="description">' . esc_html( wp_date( get_option( 'time_format' ), $ts ) ) . '</span>';
	}
}
