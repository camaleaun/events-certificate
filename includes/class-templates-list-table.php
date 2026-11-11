<?php
/**
 * The Templates screen's list, like the Certificates list (WP_List_Table):
 * the templates in the order they are tried, what each applies to, how many
 * certificates it draws now.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Templates;

use EventsCertificate\AdminApp;
use EventsCertificate\Designs;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Templates list.
 */
class Templates_List_Table extends \WP_List_Table {

	/**
	 * Certificates each template draws.
	 *
	 * @var array<int, int>
	 */
	private $usage = array();

	/**
	 * Setup.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'template',
				'plural'   => 'templates',
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
			'name'     => __( 'Name', 'events-certificate' ),
			'applies'  => __( 'Applies to', 'events-certificate' ),
			'used'     => __( 'Certificates', 'events-certificate' ),
			'modified' => __( 'Modified', 'events-certificate' ),
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
	 * Loads the templates, in the order they are tried.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
		$this->items           = Designs\templates();
		$this->usage           = usage();
		$this->set_pagination_args(
			array(
				'total_items' => count( $this->items ),
				'per_page'    => max( 1, count( $this->items ) ),
			)
		);
	}

	/**
	 * Whether a template is the default one.
	 *
	 * @param \WP_Post $item Template.
	 * @return bool
	 */
	private function is_default( $item ) {
		return Designs\site_design_id() === (int) $item->ID;
	}

	/**
	 * Name, with the row actions.
	 *
	 * @param \WP_Post $item Template.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit    = AdminApp\available() ? AdminApp\url( $item->ID ) : '';
		$actions = array();
		if ( $edit ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'events-certificate' ) );
		}
		$actions['duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( action_url( 'duplicate', $item->ID ) ), esc_html__( 'Duplicate', 'events-certificate' ) );
		if ( ! $this->is_default( $item ) ) {
			$actions['default'] = sprintf( '<a href="%s">%s</a>', esc_url( action_url( 'default', $item->ID ) ), esc_html__( 'Make default', 'events-certificate' ) );
			$actions['up']      = sprintf( '<a href="%s" aria-label="%s">↑</a>', esc_url( action_url( 'up', $item->ID ) ), esc_attr__( 'Move up', 'events-certificate' ) );
			$actions['down']    = sprintf( '<a href="%s" aria-label="%s">↓</a>', esc_url( action_url( 'down', $item->ID ) ), esc_attr__( 'Move down', 'events-certificate' ) );
			$actions['trash']   = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( action_url( 'trash', $item->ID ) ), esc_html__( 'Trash', 'events-certificate' ) );
		}
		$title = '' !== $item->post_title ? $item->post_title : __( '(no title)', 'events-certificate' );

		return sprintf(
			'<strong>%s%s</strong>%s',
			$edit ? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit ), esc_html( $title ) ) : esc_html( $title ),
			$this->is_default( $item ) ? ' — <span class="post-state">' . esc_html__( 'Default', 'events-certificate' ) . '</span>' : '',
			$this->row_actions( $actions )
		);
	}

	/**
	 * The roles it applies to.
	 *
	 * @param \WP_Post $item Template.
	 * @return string
	 */
	protected function column_applies( $item ) {
		if ( $this->is_default( $item ) ) {
			return esc_html__( 'Everyone else', 'events-certificate' );
		}
		$labels = Designs\role_labels();
		$roles  = array_map(
			static function ( $role ) use ( $labels ) {
				return $labels[ $role ];
			},
			Designs\roles( $item->ID )
		);

		return $roles ? esc_html( implode( ', ', $roles ) ) : '<span class="description">' . esc_html__( 'Nobody yet (choose roles in the editor)', 'events-certificate' ) . '</span>';
	}

	/**
	 * Certificates it draws now.
	 *
	 * @param \WP_Post $item Template.
	 * @return string
	 */
	protected function column_used( $item ) {
		return esc_html( number_format_i18n( $this->usage[ (int) $item->ID ] ?? 0 ) );
	}

	/**
	 * Last change.
	 *
	 * @param \WP_Post $item Template.
	 * @return string
	 */
	protected function column_modified( $item ) {
		$ts = strtotime( $item->post_modified_gmt . ' UTC' );

		return esc_html( wp_date( get_option( 'date_format' ), $ts ) ) . '<br><span class="description">' . esc_html( wp_date( get_option( 'time_format' ), $ts ) ) . '</span>';
	}

	/**
	 * Empty list.
	 */
	public function no_items() {
		esc_html_e( 'No templates yet.', 'events-certificate' );
	}
}
