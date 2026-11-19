<?php
/**
 * The Events screen's list: each event with its date, place, participants
 * and certificates.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\EventsAdmin;

use EventsCertificate\Events;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Events list.
 */
class Events_List_Table extends \WP_List_Table {

	/**
	 * Setup.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'evcert_event',
				'plural'   => 'evcert_events',
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
			'title'        => __( 'Event', 'events-certificates' ),
			'date'         => __( 'Date', 'events-certificates' ),
			'location'     => __( 'Place', 'events-certificates' ),
			'participants' => __( 'Participants', 'events-certificates' ),
			'certificates' => __( 'Certificates', 'events-certificates' ),
		);
	}

	/**
	 * Sortable columns: key => [orderby, descending first].
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'date', true ),
		);
	}

	/**
	 * The primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'title';
	}

	/**
	 * Loads the page of events: searched, sorted (newest first by default) and paged.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list search and sorting.
		$search  = isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		$order   = isset( $_GET['order'] ) && 'asc' === sanitize_key( wp_unslash( $_GET['order'] ) ) ? 'asc' : 'desc';
		// phpcs:enable
		$events = array_filter( array_map( 'EventsCertificate\Events\get', wp_list_pluck( Events\all(), 'ID' ) ) );
		if ( '' !== $search ) {
			$events = array_filter(
				$events,
				static function ( $event ) use ( $search ) {
					return false !== stripos( $event['title'] . ' ' . $event['location'], $search );
				}
			);
		}
		usort(
			$events,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$result = 'title' === $orderby ? strcasecmp( $a['title'], $b['title'] ) : strcmp( $a['start'], $b['start'] );

				return 'asc' === $order ? $result : -$result;
			}
		);
		$per_page    = $this->get_items_per_page( 'evcert_events_per_page', 20 );
		$this->items = array_slice( $events, ( $this->get_pagenum() - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => count( $events ),
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * When there are none.
	 */
	public function no_items() {
		esc_html_e( 'No events yet. Add one, then its participants: who came gets a certificate.', 'events-certificates' );
	}

	/**
	 * Name, with Edit and Trash.
	 *
	 * @param array<string, string> $item Event.
	 * @return string
	 */
	protected function column_title( $item ) {
		$edit    = url( array( 'event' => (int) $item['id'] ) );
		$actions = array(
			'edit'         => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'events-certificates' ) ),
			'participants' => sprintf( '<a href="%s">%s</a>', esc_url( participants_url( (int) $item['id'] ) ), esc_html__( 'Participants', 'events-certificates' ) ),
			'trash'        => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( wp_nonce_url( url( array( 'evcert_trash' => (int) $item['id'] ) ), 'evcert_event_trash_' . (int) $item['id'] ) ), esc_html__( 'Trash', 'events-certificates' ) ),
		);

		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $item['title'] ) ) . $this->row_actions( $actions );
	}

	/**
	 * Date and times.
	 *
	 * @param array<string, string> $item Event.
	 * @return string
	 */
	protected function column_date( $item ) {
		$start = strtotime( (string) Events\utc( $item['start'] ) . ' UTC' );
		$end   = strtotime( (string) Events\utc( $item['end'] ) . ' UTC' );

		return esc_html( wp_date( (string) get_option( 'date_format' ), $start ) ) . '<br><span class="description">' . esc_html( wp_date( (string) get_option( 'time_format' ), $start ) . ( $end > $start ? '–' . wp_date( (string) get_option( 'time_format' ), $end ) : '' ) ) . '</span>';
	}

	/**
	 * Place.
	 *
	 * @param array<string, string> $item Event.
	 * @return string
	 */
	protected function column_location( $item ) {
		return '' !== $item['location'] ? esc_html( $item['location'] ) : '—';
	}

	/**
	 * Participants: registered, attended.
	 *
	 * @param array<string, string> $item Event.
	 * @return string
	 */
	protected function column_participants( $item ) {
		$counts = People\counts( (int) $item['id'] );

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( participants_url( (int) $item['id'] ) ),
			esc_html(
				sprintf(
					/* translators: 1: number of participants, 2: number who attended. */
					__( '%1$s (%2$s attended)', 'events-certificates' ),
					number_format_i18n( $counts['all'] ),
					number_format_i18n( $counts['attended'] )
				)
			)
		);
	}

	/**
	 * Certificates issued.
	 *
	 * @param array<string, string> $item Event.
	 * @return string
	 */
	protected function column_certificates( $item ) {
		return esc_html( number_format_i18n( People\counts( (int) $item['id'] )['certified'] ) );
	}
}
