<?php
/**
 * Admin: the Certificates screen and the settings fields.
 *
 * Where they appear depends on the site: with CampTix, the screen is a
 * submenu of Tickets and the settings a tab of Tickets > Setup
 * (includes/compat/camptix.php); otherwise the screen is a menu of its own
 * and the settings live on it.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Admin;

use EventsCertificate\Certificates;

use EventsCertificate\Environment;
use EventsCertificate\Install;
use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug.
 */
const SLUG = 'evcert-certificates';

/**
 * Settings group of the plugin's own settings form (sites without CampTix).
 */
const GROUP = 'evcert';

/**
 * Capability to manage certificates.
 */
const CAPABILITY = 'manage_options';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'admin_menu', __NAMESPACE__ . '\register_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
	add_action( 'evcert_certificates_screen', __NAMESPACE__ . '\render_settings_form' );
}

/**
 * The menu the Certificates screen goes under: '' for a menu of its own.
 *
 * @return string Parent slug.
 */
function menu_parent() {
	/**
	 * Filters the admin menu the Certificates screen is a submenu of.
	 * Integrations (CampTix) set it; '' gives Certificates a menu of its own.
	 *
	 * @param string $parent Parent menu slug.
	 */
	return (string) apply_filters( 'evcert_admin_menu_parent', '' );
}

/**
 * The Certificates screen's address, under its parent menu
 * (edit.php?post_type=tix_ticket&page=evcert-certificates with CampTix).
 * menu_page_url() can't tell from a screen outside that menu, such as the
 * design editor.
 *
 * @return string
 */
function page_url() {
	$parent = menu_parent();
	$base   = '' !== $parent && false !== strpos( $parent, '.php' ) ? $parent : 'admin.php';

	return add_query_arg( 'page', SLUG, admin_url( $base ) );
}

/**
 * Registers the Certificates screen.
 */
function register_menu() {
	$title  = __( 'Certificates', 'events-certificate' );
	$parent = menu_parent();

	if ( '' === $parent ) {
		$hook = add_menu_page( $title, $title, CAPABILITY, SLUG, __NAMESPACE__ . '\render_page', 'dashicons-awards', 26 );
	} else {
		$hook = add_submenu_page( $parent, $title, $title, CAPABILITY, SLUG, __NAMESPACE__ . '\render_page' );
	}
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\load_screen' );
	}
}

/**
 * The avatar beside the name, placed like the Users screen's.
 */
function list_styles() {
	wp_add_inline_style( 'list-tables', '.column-name .evcert-avatar{float:left;margin:1px 10px 0 0;border-radius:50%}' );
}

/**
 * Before the Certificates screen prints: the per-page screen option and the
 * revoke action (row or bulk), then back to the list with a notice.
 */
function load_screen() {
	add_screen_option(
		'per_page',
		array(
			'label'   => __( 'Certificates per page', 'events-certificate' ),
			'default' => 20,
			'option'  => 'evcert_certificates_per_page',
		)
	);
	add_filter( 'set_screen_option_evcert_certificates_per_page', __NAMESPACE__ . '\save_per_page', 10, 3 );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\list_styles' );

	$table  = new List_Table();
	$action = $table->current_action();
	if ( 'revoke' !== $action ) {
		return;
	}
	check_admin_referer( 'bulk-certificates' );
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to revoke certificates.', 'events-certificate' ) );
	}
	$ids     = isset( $_REQUEST['certificate'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['certificate'] ) ) : array();
	$revoked = 0;
	foreach ( array_filter( $ids ) as $id ) {
		$revoked += Certificates\revoke( $id ) ? 1 : 0;
	}
	wp_safe_redirect( add_query_arg( 'revoked', $revoked, remove_query_arg( array( 'action', 'action2', 'certificate', '_wpnonce', '_wp_http_referer', 'revoked' ) ) ) );
	exit;
}

/**
 * Keeps the per-page screen option.
 *
 * @param mixed  $keep   Value to keep (false to not save).
 * @param string $option Option name.
 * @param int    $value  Value chosen.
 * @return int
 */
function save_per_page( $keep, $option, $value ) {
	return max( 1, min( 999, (int) $value ) );
}

/**
 * Registers the settings with a settings group: the plugin's own, or the one
 * of the form they are shown in (CampTix's Setup).
 */
function register_settings() {
	/**
	 * Filters the settings group the plugin's settings are saved with: the
	 * option_page of the form that shows its fields.
	 *
	 * @param string $group Settings group.
	 */
	$group = (string) apply_filters( 'evcert_settings_group', GROUP );

	register_setting(
		$group,
		Settings\OPTION,
		array(
			'type'              => 'object',
			'sanitize_callback' => 'EventsCertificate\Admin\sanitize_posted',
			'default'           => Settings\defaults(),
		)
	);
}

/**
 * Sanitizes the settings posted by a settings form. options.php updates every
 * option of the form's settings group, with null for those not posted (the
 * other tabs of CampTix's Setup, for example): then the saved settings stay.
 * It has to happen here, because sanitizing runs before the pre_update_option
 * filters and would turn null into the defaults.
 *
 * @param mixed $value Posted value.
 * @return array<string, mixed>
 */
function sanitize_posted( $value ) {
	if ( null === $value ) {
		return Settings\get();
	}
	// The page with the "Check a certificate" form is its own option; its slug becomes the certificates' slug.
	if ( is_array( $value ) && array_key_exists( 'verify_page', $value ) ) {
		$page = absint( $value['verify_page'] );
		$page = $page && 'page' === get_post_type( $page ) ? $page : 0;
		update_option( \EventsCertificate\VerifyPage\OPTION, $page, false );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$value['slug'] = (string) get_post_field( 'post_name', $page );
		}
		unset( $value['verify_page'] );
	}

	return Settings\sanitize( $value );
}

/**
 * Adds the settings sections and fields to a settings page.
 *
 * @param string $page Settings page slug (do_settings_sections()).
 */
function add_settings_fields( $page ) {
	add_settings_section(
		'evcert_authenticity',
		__( 'Authenticity', 'events-certificate' ),
		__NAMESPACE__ . '\render_authenticity_section',
		$page
	);
	add_settings_field(
		'evcert_force_public',
		__( 'Public site', 'events-certificate' ),
		__NAMESPACE__ . '\render_force_public_field',
		$page,
		'evcert_authenticity',
		array( 'label_for' => 'evcert_force_public' )
	);
	add_settings_field(
		'evcert_verify_page',
		__( 'Check page', 'events-certificate' ),
		__NAMESPACE__ . '\render_verify_page_field',
		$page,
		'evcert_authenticity',
		array( 'label_for' => 'evcert_verify_page' )
	);
	add_settings_field(
		'evcert_slug',
		__( 'Certificate address', 'events-certificate' ),
		__NAMESPACE__ . '\render_slug_field',
		$page,
		'evcert_authenticity',
		array( 'label_for' => 'evcert_slug' )
	);

	add_settings_section(
		'evcert_issuing',
		__( 'Issuing', 'events-certificate' ),
		'__return_false',
		$page
	);
	add_settings_field(
		'evcert_location',
		__( 'Event location', 'events-certificate' ),
		__NAMESPACE__ . '\render_location_field',
		$page,
		'evcert_issuing',
		array( 'label_for' => 'evcert_location' )
	);
	add_settings_field(
		'evcert_self_issue',
		__( 'Attendees get their own', 'events-certificate' ),
		__NAMESPACE__ . '\render_self_issue_field',
		$page,
		'evcert_issuing',
		array( 'label_for' => 'evcert_self_issue' )
	);

	add_settings_section(
		'evcert_data',
		__( 'Data', 'events-certificate' ),
		'__return_false',
		$page
	);
	add_settings_field(
		'evcert_delete_data',
		__( 'Uninstall', 'events-certificate' ),
		__NAMESPACE__ . '\render_delete_data_field',
		$page,
		'evcert_data',
		array( 'label_for' => 'evcert_delete_data' )
	);
}

/**
 * Explains whether certificates get a QR code on this site, and why not.
 */
function render_authenticity_section() {
	if ( Environment\is_public() ) {
		echo '<p>' . esc_html__( 'Certificates issued on this site carry a QR code and a code anyone can check on its verification page.', 'events-certificate' ) . '</p>';
		return;
	}

	$labels  = array(
		'playground'        => __( 'it runs on WordPress Playground', 'events-certificate' ),
		'local-environment' => __( 'its environment type is “local”', 'events-certificate' ),
		'local-host'        => __( 'its address only works on this computer or network', 'events-certificate' ),
		'private-ip'        => __( 'its address is a private IP', 'events-certificate' ),
	);
	$reasons = array();
	foreach ( Environment\non_public_reasons() as $reason ) {
		$reasons[] = $labels[ $reason ] ?? $reason;
	}

	echo '<div class="notice notice-warning inline"><p>';
	printf(
		/* translators: %s: reasons, e.g. "its address only works on this computer or network". */
		esc_html__( 'This site doesn’t look public (%s), so certificates show a placeholder where the QR code and the verification address go: nobody else could open them.', 'events-certificate' ),
		esc_html( implode( '; ', $reasons ) )
	);
	echo '</p></div>';
}

/**
 * Field: force public.
 */
function render_force_public_field() {
	printf(
		'<label><input type="checkbox" id="evcert_force_public" name="%1$s[force_public]" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
		esc_attr( Settings\OPTION ),
		checked( Settings\value( 'force_public' ), true, false ),
		esc_html__( 'Treat this site as public', 'events-certificate' ),
		esc_html__( 'For a staging site with a public address. Only turn it on when anyone can open this site’s pages.', 'events-certificate' )
	);
}

/**
 * Field: the page with the "Check a certificate" form.
 */
function render_verify_page_field() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes.
	echo wp_dropdown_pages(
		array(
			'name'              => esc_attr( Settings\OPTION ) . '[verify_page]',
			'id'                => 'evcert_verify_page',
			'selected'          => (int) get_option( \EventsCertificate\VerifyPage\OPTION, 0 ),
			'show_option_none'  => esc_html__( '— None (the plugin’s own page) —', 'events-certificate' ),
			'option_none_value' => '0',
			'echo'              => 0,
		)
	);
	printf( '<p class="description">%s</p>', esc_html__( 'The page with the Check a certificate block. Certificates live under its address.', 'events-certificate' ) );
}

/**
 * Field: certificate address (the page's when there is one).
 */
function render_slug_field() {
	$page = \EventsCertificate\VerifyPage\page_id();
	if ( $page ) {
		printf(
			'<input type="hidden" name="%1$s[slug]" value="%2$s"><code>%3$s%2$s/&lt;%4$s&gt;/</code><p class="description">%5$s</p>',
			esc_attr( Settings\OPTION ),
			esc_attr( Settings\value( 'slug' ) ),
			esc_html( trailingslashit( home_url() ) ),
			esc_html_x( 'code', 'certificate code placeholder', 'events-certificate' ),
			esc_html__( 'Set by the check page’s address (change it in the page).', 'events-certificate' )
		);
		return;
	}
	printf(
		'<input type="text" id="evcert_slug" name="%2$s[slug]" value="%3$s" class="regular-text code"><p class="description">%4$s<br><code>%1$s%3$s/&lt;%5$s&gt;/</code></p>',
		esc_html( trailingslashit( home_url() ) ),
		esc_attr( Settings\OPTION ),
		esc_attr( Settings\value( 'slug' ) ),
		esc_html__( 'The address printed on certificates and in their QR code. Change it before issuing: printed certificates keep the old one.', 'events-certificate' ),
		/* translators: Stands for a certificate code in an example address, as in https://example.com/certificate/<code>/. */
		esc_html_x( 'code', 'certificate code placeholder', 'events-certificate' )
	);
}

/**
 * Field: event location.
 */
function render_location_field() {
	printf(
		'<input type="text" id="evcert_location" name="%1$s[location]" value="%2$s" class="regular-text" placeholder="%3$s"><p class="description">%4$s</p>',
		esc_attr( Settings\OPTION ),
		esc_attr( Settings\value( 'location' ) ),
		esc_attr__( 'City, Country', 'events-certificate' ),
		esc_html__( 'Shown where the design uses the Location field. On WordCamp.org it comes from the WordCamp’s location.', 'events-certificate' )
	);
}

/**
 * Field: who gets their own certificate from the tickets page.
 */
function render_self_issue_field() {
	$options = array(
		'attended'  => __( 'When marked as present at the event', 'events-certificate' ),
		'confirmed' => __( 'With any confirmed ticket', 'events-certificate' ),
		'off'       => __( 'No: only organizers issue certificates', 'events-certificate' ),
	);
	printf( '<select id="evcert_self_issue" name="%s[self_issue]">', esc_attr( Settings\OPTION ) );
	foreach ( $options as $value => $label ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( Settings\value( 'self_issue' ), $value, false ), esc_html( $label ) );
	}
	echo '</select>';
	printf( '<p class="description">%s</p>', esc_html__( 'From the link in their ticket e-mail, attendees check how their name goes on the certificate and get it with its code.', 'events-certificate' ) );
}

/**
 * Field: delete data on uninstall.
 */
function render_delete_data_field() {
	printf(
		'<label><input type="checkbox" id="evcert_delete_data" name="%1$s[delete_data]" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
		esc_attr( Settings\OPTION ),
		checked( Settings\value( 'delete_data' ), true, false ),
		esc_html__( 'Delete all certificates when the plugin is deleted', 'events-certificate' ),
		esc_html__( 'Printed certificates would no longer be verifiable. Leave it off unless you are sure.', 'events-certificate' )
	);
}

/**
 * Number of certificates by status.
 *
 * @return array<string, int>
 */
function counts() {
	global $wpdb;

	$table  = Install\tables()['certificates'];
	$counts = array(
		'draft'    => 0,
		'issued'   => 0,
		'replaced' => 0,
		'revoked'  => 0,
	);
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $row ) {
		$counts[ $row->status ] = (int) $row->total;
	}

	return $counts;
}

/**
 * The Certificates screen: issued certificates in a list table, like
 * CampTix's Attendees.
 */
function render_page() {
	if ( ! current_user_can( CAPABILITY ) ) {
		return;
	}
	$table = new List_Table();
	$table->prepare_items();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only a count for the notice.
	$revoked = isset( $_GET['revoked'] ) ? absint( $_GET['revoked'] ) : null;
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Certificates', 'events-certificate' ); ?></h1>
		<?php
		/**
		 * Fires next to the Certificates screen title (links like Edit certificate design).
		 */
		do_action( 'evcert_certificates_title_actions' );
		?>
		<hr class="wp-header-end">
		<?php if ( null !== $revoked ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				/* translators: %s: number of certificates. */
				echo esc_html( sprintf( _n( '%s certificate revoked.', '%s certificates revoked.', $revoked, 'events-certificate' ), number_format_i18n( $revoked ) ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php $table->views(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( SLUG ); ?>">
			<?php if ( 'edit.php' === $GLOBALS['pagenow'] && isset( $_GET['post_type'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keeps the parent screen in the form. ?>
				<input type="hidden" name="post_type" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['post_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
			<?php endif; ?>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keeps the status view in the search.
			if ( isset( $_GET['status'] ) ) :
				?>
				<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
			<?php endif; ?>
			<?php
			$table->search_box( __( 'Search certificates', 'events-certificate' ), 'evcert-search' );
			$table->display();
			?>
		</form>
		<?php
		/**
		 * Fires at the end of the Certificates screen, where the settings go
		 * when no integration shows them elsewhere.
		 */
		do_action( 'evcert_certificates_screen' );
		?>
	</div>
	<?php
}

/**
 * The plugin's own settings form, on the Certificates screen (sites without
 * an integration that shows the settings elsewhere).
 */
function render_settings_form() {
	add_settings_fields( SLUG );
	?>
	<h2><?php esc_html_e( 'Settings', 'events-certificate' ); ?></h2>
	<form method="post" action="options.php">
		<?php
		settings_fields( GROUP );
		do_settings_sections( SLUG );
		submit_button();
		?>
	</form>
	<?php
}
