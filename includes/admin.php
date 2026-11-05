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
 * Registers the Certificates screen.
 */
function register_menu() {
	$title  = __( 'Certificates', 'events-certificate' );
	$parent = menu_parent();

	if ( '' === $parent ) {
		add_menu_page( $title, $title, CAPABILITY, SLUG, __NAMESPACE__ . '\render_page', 'dashicons-awards', 26 );
	} else {
		add_submenu_page( $parent, $title, $title, CAPABILITY, SLUG, __NAMESPACE__ . '\render_page' );
	}
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
 * Field: certificate URL base.
 */
function render_slug_field() {
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
		'draft'   => 0,
		'issued'  => 0,
		'revoked' => 0,
	);
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $row ) {
		$counts[ $row->status ] = (int) $row->total;
	}

	return $counts;
}

/**
 * The Certificates screen. For now a summary: the management screen
 * (DataViews) comes with the build step.
 */
function render_page() {
	if ( ! current_user_can( CAPABILITY ) ) {
		return;
	}
	$counts = counts();
	$labels = array(
		'draft'   => __( 'Drafts', 'events-certificate' ),
		'issued'  => __( 'Issued', 'events-certificate' ),
		'revoked' => __( 'Revoked', 'events-certificate' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Certificates', 'events-certificate' ); ?></h1>
		<p><?php esc_html_e( 'Issue, send and revoke attendance certificates here. This screen is still being built.', 'events-certificate' ); ?></p>
		<ul class="subsubsub">
			<?php foreach ( $labels as $status => $label ) : ?>
				<li><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?>)</span><?php echo 'revoked' === $status ? '' : ' |'; ?></li>
			<?php endforeach; ?>
		</ul>
		<br class="clear">
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
