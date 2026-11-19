<?php
/**
 * The Templates screen ("Modelos"): every certificate template, the roles each
 * applies to, the default one, and the actions to add, duplicate, reorder,
 * make default and trash. It opens from the Certificates screen's Templates
 * button; each template opens in the design editor. docs/decisions/0013.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Templates;

use EventsCertificate\Admin;
use EventsCertificate\AdminApp;
use EventsCertificate\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug.
 */
const SLUG = 'evcert-templates';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'admin_menu', __NAMESPACE__ . '\add_page', 20 );
	add_action( 'evcert_certificates_title_actions', __NAMESPACE__ . '\render_button', 5 );
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
 * An action on a template, with its nonce.
 *
 * @param string $action duplicate|default|trash|up|down|new.
 * @param int    $id     Template ID (0 for new).
 * @return string
 */
function action_url( $action, $id = 0 ) {
	return wp_nonce_url(
		url(
			array(
				'evcert_do' => $action,
				'template'  => (int) $id,
			)
		),
		'evcert_template_' . $action . '_' . (int) $id
	);
}

/**
 * Registers the screen with no menu item (it opens from Certificates).
 */
function add_page() {
	$hook = add_submenu_page( '', __( 'Certificate templates', 'events-certificates' ), '', Admin\CAPABILITY, SLUG, __NAMESPACE__ . '\render' );
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\load' );
	}
}

/**
 * The Templates button on the Certificates screen.
 */
function render_button() {
	printf( '<a class="page-title-action" href="%s">%s</a>', esc_url( url() ), esc_html__( 'Templates', 'events-certificates' ) );
}

/**
 * Before the screen prints: the menu highlight, the screen's title, and an
 * action (with its nonce), then back to the list or into the editor.
 */
function load() {
	$GLOBALS['title'] = __( 'Certificate templates', 'events-certificates' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a page with no menu item has no title of its own.
	add_filter(
		'parent_file',
		static function () {
			return Admin\menu_parent() ? Admin\menu_parent() : Admin\SLUG;
		}
	);
	add_filter(
		'submenu_file',
		static function () {
			return Admin\SLUG;
		}
	);

	// The form on the left: a new template, with a name, from the Base design or from another template.
	if ( isset( $_POST['evcert_template_add'] ) ) {
		check_admin_referer( 'evcert_template_add' );
		if ( ! current_user_can( Admin\CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change certificate templates.', 'events-certificates' ) );
		}
		$name = isset( $_POST['template-name'] ) ? sanitize_text_field( wp_unslash( $_POST['template-name'] ) ) : '';
		$from = isset( $_POST['template-from'] ) ? absint( $_POST['template-from'] ) : 0;
		$from = $from && Designs\POST_TYPE === get_post_type( $from ) ? $from : 0;
		$new  = Designs\duplicate( $from, $name );
		if ( $new && AdminApp\available() ) {
			wp_safe_redirect( AdminApp\url( $new ) );
			exit;
		}
		wp_safe_redirect( url( $new ? array( 'evcert_done' => 'added' ) : array() ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked below for each action.
	$action = isset( $_GET['evcert_do'] ) ? sanitize_key( wp_unslash( $_GET['evcert_do'] ) ) : '';
	$id     = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
	// phpcs:enable
	if ( '' === $action ) {
		return;
	}
	check_admin_referer( 'evcert_template_' . $action . '_' . $id );
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to change certificate templates.', 'events-certificates' ) );
	}
	if ( $id && Designs\POST_TYPE !== get_post_type( $id ) ) {
		wp_die( esc_html__( 'That template no longer exists.', 'events-certificates' ) );
	}

	$done = '';
	switch ( $action ) {
		case 'new':
		case 'duplicate':
			$new = Designs\duplicate( 'new' === $action ? 0 : $id );
			if ( $new && AdminApp\available() ) {
				wp_safe_redirect( AdminApp\url( $new ) );
				exit;
			}
			$done = $new ? 'added' : '';
			break;
		case 'default':
			$done = Designs\set_default( $id ) ? 'default' : '';
			break;
		case 'trash':
			// The default one stays: there is always a template for everyone.
			$done = Designs\site_design_id() !== $id && wp_trash_post( $id ) ? 'trashed' : '';
			break;
		case 'up':
		case 'down':
			Designs\move( $id, $action );
			break;
	}
	wp_safe_redirect( url( $done ? array( 'evcert_done' => $done ) : array() ) );
	exit;
}

/**
 * How many certificates each template draws now (by their roles).
 *
 * @return array<int, int> Template ID => certificates.
 */
function usage() {
	global $wpdb;

	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT role, COUNT(*) AS n FROM %i WHERE status = 'issued' GROUP BY role", \EventsCertificate\Certificates\table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	$counts = array();
	foreach ( (array) $rows as $row ) {
		$id            = Designs\for_certificate( array( 'role' => (string) $row['role'] ) );
		$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + (int) $row['n'];
	}

	return $counts;
}

/**
 * Prints the screen.
 */
function render() {
	require_once __DIR__ . '/class-templates-list-table.php';
	$table = new Templates_List_Table();
	$table->prepare_items();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message.
	$done     = isset( $_GET['evcert_done'] ) ? sanitize_key( wp_unslash( $_GET['evcert_done'] ) ) : '';
	$messages = array(
		'added'   => __( 'Template added.', 'events-certificates' ),
		'default' => __( 'Default template changed.', 'events-certificates' ),
		'trashed' => __( 'Template moved to the Trash.', 'events-certificates' ),
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Certificate templates', 'events-certificates' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( Admin\page_url() ); ?>"><?php esc_html_e( 'Certificates', 'events-certificates' ); ?></a>
		<hr class="wp-header-end">
		<?php if ( isset( $messages[ $done ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $done ] ); ?></p></div>
		<?php endif; ?>
		<div id="col-container" class="wp-clearfix">
			<div id="col-left">
				<div class="col-wrap">
					<div class="form-wrap">
						<h2><?php esc_html_e( 'Add new template', 'events-certificates' ); ?></h2>
						<form id="addtemplate" method="post" action="<?php echo esc_url( url() ); ?>">
							<input type="hidden" name="evcert_template_add" value="1">
							<?php wp_nonce_field( 'evcert_template_add' ); ?>
							<div class="form-field form-required template-name-wrap">
								<label for="template-name"><?php echo esc_html_x( 'Name', 'template name', 'events-certificates' ); ?></label>
								<input name="template-name" id="template-name" type="text" value="" size="40" aria-describedby="template-name-description">
								<p id="template-name-description"><?php esc_html_e( 'How the template is called in this list. Choose which certificates it draws in the editor.', 'events-certificates' ); ?></p>
							</div>
							<div class="form-field template-from-wrap">
								<label for="template-from"><?php esc_html_e( 'Start from', 'events-certificates' ); ?></label>
								<select name="template-from" id="template-from" class="postform" aria-describedby="template-from-description">
									<option value="0"><?php esc_html_e( 'The Base design', 'events-certificates' ); ?></option>
									<?php foreach ( Designs\templates() as $template ) : ?>
										<option value="<?php echo esc_attr( (string) $template->ID ); ?>"><?php echo esc_html( '' !== $template->post_title ? $template->post_title : __( '(no title)', 'events-certificates' ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p id="template-from-description"><?php esc_html_e( 'A copy of another template keeps its layout and texts.', 'events-certificates' ); ?></p>
							</div>
							<p class="submit">
								<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add new template', 'events-certificates' ); ?>">
							</p>
						</form>
					</div>
				</div>
			</div>
			<div id="col-right">
				<div class="col-wrap">
					<?php $table->display(); ?>
				</div>
			</div>
		</div>
	</div>
	<?php
}
