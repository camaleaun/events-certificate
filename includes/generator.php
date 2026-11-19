<?php
/**
 * Generator: an event and its list of names on one screen; one click
 * makes the event, its participants (as attended) and their certificates,
 * and downloads them, like the meetup certificate generators that run in a
 * browser, but every certificate is verifiable by its code.
 * docs/decisions/0015.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Generator;

use EventsCertificate\Admin;
use EventsCertificate\Events;
use EventsCertificate\EventsAdmin;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug.
 */
const SLUG = 'evcert-generator';

/**
 * Hooks.
 */
function bootstrap() {
	// The hooks are always there (the other plugins load after this one, so the mode is not known yet);
	// the screen itself exists only without CampTix and GatherPress (decision 0026).
	add_action( 'admin_menu', __NAMESPACE__ . '\add_page', 16 );
	add_filter( 'admin_body_class', __NAMESPACE__ . '\body_class' );
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
 * Registers the screen (a menu item in the plugin's own menu, else a page
 * opened from Certificates and Events).
 */
function add_page() {
	if ( ! \EventsCertificate\Environment\generator_enabled() ) {
		return;
	}
	$title = __( 'Generator', 'events-certificates' );
	$own   = '' === Admin\menu_parent();
	$hook  = add_submenu_page( $own ? Admin\SLUG : '', $title, $own ? $title : '', Admin\CAPABILITY, SLUG, __NAMESPACE__ . '\render' );
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\load' );
	}
}

/**
 * The screen's full-screen editor chrome: on the quick-issue screen the admin
 * menu and footer are hidden (the same body class the design editor uses), so
 * the page takes the window like the block editor. Kept out of
 * EventsAdmin\enqueue() so other screens stay the same.
 */
function enqueue() {
	$screen = \EventsCertificate\Assets\src( 'editor-screen', 'css' );
	wp_enqueue_style( 'evcert-editor-screen', $screen['url'], array(), $screen['version'] );
	// The real Gutenberg components stylesheet, so the panels, toggles and
	// buttons render exactly as in the block editor's Inspector.
	wp_enqueue_style( 'wp-components' );
	// The certificate sample's colors (the same file the front-end generator uses).
	wp_enqueue_style( 'evcert-quick-generator' );
	// The screen's own layout (the editor's full-screen shell around the form).
	$admin = \EventsCertificate\Assets\src( 'generator', 'css' );
	wp_enqueue_style( 'evcert-generator', $admin['url'], array( 'wp-components', 'evcert-quick-generator' ), $admin['version'] );

	// The form is the editor's own components, built (src/generator); its texts are translated there.
	$signer = \EventsCertificate\Signer\current();
	wp_add_inline_script(
		'evcert-generator',
		'window.evcertGenerator = ' . wp_json_encode(
			array(
				'signerName' => $signer ? $signer['name'] : '',
				'signerRole' => $signer && $signer['roles'] ? \EventsCertificate\Signer\role_in( $signer['roles'], get_locale() ) : '',
				'tutorial'   => \EventsCertificate\QuickGenerator\tutorial_data(),
			)
		) . ';',
		'before'
	);
	wp_enqueue_style( 'evcert-generator-sheet' );
	wp_enqueue_script( 'evcert-generator' );
}

/**
 * Full-screen body class on the quick-issue screen, like the editor's.
 *
 * @param string $classes Body classes.
 * @return string
 */
function body_class( $classes ) {
	return isset( $_GET['page'] ) && SLUG === $_GET['page'] ? $classes . ' evcert-editor-screen' : $classes; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen this is.
}


/**
 * Before the screen prints: the menu highlight, the styles, and the form.
 */
function load() {
	if ( '' !== Admin\menu_parent() ) {
		$GLOBALS['title'] = __( 'Generator', 'events-certificates' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a page with no menu item has no title of its own.
		add_filter(
			'parent_file',
			static function () {
				return Admin\menu_parent();
			}
		);
		add_filter(
			'submenu_file',
			static function () {
				return Admin\SLUG;
			}
		);
	}
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue' );
	if ( isset( $_POST['evcert_generator'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in issue().
		issue();
	}
}

/**
 * Back to the screen with an error.
 *
 * @param string $message Message.
 */
function fail( $message ) {
	set_transient( 'evcert_generator_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	wp_safe_redirect( url( array( 'evcert_error' => 1 ) ) );
	exit;
}

/**
 * The generator itself (the screen and the quick-issue ability): makes the
 * event, adds the people (as attended, unless a file said otherwise) and
 * issues the certificates of who attended.
 *
 * @param array<string, mixed>             $fields Event fields (Events\save()).
 * @param array<int, array<string, mixed>> $people Participants (People\add()).
 * @return int|\WP_Error The event's ID.
 */
function run( array $fields, array $people ) {
	if ( ! $people ) {
		return new \WP_Error( 'evcert_missing_participants', __( 'Add at least one name, or a CSV file with the participants.', 'events-certificates' ) );
	}
	$check = Events\clean( $fields );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	foreach ( $people as $i => $person ) {
		$people[ $i ]['status'] = $person['status'] ?? 'attended';
	}
	$event_id = Events\save( $fields );
	if ( is_wp_error( $event_id ) ) {
		return $event_id;
	}
	People\import( $event_id, $people );
	People\issue_many( wp_list_pluck( People\query( $event_id, array( 'status' => 'attended' ) )['rows'], 'id' ), $event_id );

	return (int) $event_id;
}

/**
 * Makes the event, its participants and their certificates, then sends
 * them (ZIP or one PDF).
 */
function issue() {
	check_admin_referer( 'evcert_generator' );
	EventsAdmin\must_manage();

	// The same fields as the front-end generator: the date and the times are apart.
	$post = static function ( $key ) {
		return isset( $_POST[ 'evcert_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'evcert_' . $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- issue() checked the nonce.
	};
	$date = $post( 'date' );
	$from = $post( 'start_time' );
	$to   = $post( 'end_time' );
	$end  = '' !== $date && '' !== $to ? $date . ' ' . $to : '';
	if ( '' !== $end && '' !== $from && $to < $from ) {
		// Past midnight: it ends the next day.
		$end = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' ' . $to;
	}
	$fields = array(
		'title'       => $post( 'title' ),
		'community'   => $post( 'community' ),
		'start'       => '' !== $date ? $date . ' ' . ( '' !== $from ? $from : '00:00' ) : '',
		'end'         => $end,
		'venue'       => $post( 'venue' ),
		'city'        => $post( 'city' ),
		'speakers'    => $post( 'speakers' ),
		'link'        => $post( 'link' ),
		'show_hours'  => $post( 'show_hours' ),
		'signer_name' => $post( 'signer_name' ),
		'signer_role' => $post( 'signer_role' ),
	);
	$people = People\parse_lines( isset( $_POST['evcert_lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evcert_lines'] ) ) : '' );
	$file   = EventsAdmin\uploaded_csv();
	if ( is_wp_error( $file ) ) {
		fail( $file->get_error_message() );
	}
	if ( $file ) {
		foreach ( People\parse_rows( $file )['people'] as $person ) {
			$people[] = $person + array( 'source' => 'csv' );
		}
	}
	$event_id = run( $fields, $people );
	if ( is_wp_error( $event_id ) ) {
		fail( $event_id->get_error_message() );
	}

	$format = isset( $_POST['evcert_generator'] ) ? sanitize_key( wp_unslash( $_POST['evcert_generator'] ) ) : 'pdf';
	EventsAdmin\download( $event_id, in_array( $format, array( 'zip', 'pdf' ), true ) ? $format : 'pdf' );
}

/**
 * Prints the screen with the design editor's chrome: a 64px header (back,
 * document bar, status), then the editor body — the certificate preview as the
 * canvas stage, and the issue form as the 280px Inspector sidebar styled after
 * the @wordpress/components panels.
 */
function render() {
	EventsAdmin\must_manage();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the stored message.
	$error = isset( $_GET['evcert_error'] ) ? (string) get_transient( 'evcert_generator_error_' . get_current_user_id() ) : '';
	delete_transient( 'evcert_generator_error_' . get_current_user_id() );
	$has_error = '' !== $error;
	$back      = EventsAdmin\url();
	?>
	<div class="wrap evcert-generator">
		<header class="evcert-generator__header">
			<div class="evcert-generator__header-left">
				<a class="evcert-generator__back" href="<?php echo esc_url( $back ); ?>" aria-label="<?php esc_attr_e( 'Back to Events', 'events-certificates' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</a>
				<button type="button" class="evcert-generator__header-icon evcert-generator__toggle-left" id="evcert-generator-toggle-left" aria-expanded="false" aria-controls="evcert-generator-left-panel" aria-label="<?php esc_attr_e( 'Toggle Issue steps', 'events-certificates' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
				</svg>
				</button>
				<span class="evcert-generator__header-title"><?php esc_html_e( 'Generator', 'events-certificates' ); ?></span>
				<button type="button" class="evcert-generator__header-icon evcert-generator__help" id="evcert-generator-help" aria-label="<?php esc_attr_e( 'Show the tutorial', 'events-certificates' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M9.6 9.4a2.4 2.4 0 1 1 3.4 2.2c-.9.4-1.4 1-1.4 1.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="16.4" r="1" fill="currentColor"/></svg>
				</button>
			</div>
			<div class="evcert-generator__header-center">
				<span class="evcert-generator__doc-bar">
					<span class="evcert-generator__doc-title"><?php esc_html_e( 'Certificates', 'events-certificates' ); ?></span>
					<span class="evcert-generator__doc-type">· <?php esc_html_e( 'Issue for this event', 'events-certificates' ); ?></span>
				</span>
			</div>
			<div class="evcert-generator__header-right">
				<button type="submit" form="evcert-generator-form" class="components-button is-secondary" name="evcert_generator_format" value="pdf"><?php esc_html_e( 'Single PDF', 'events-certificates' ); ?></button>
				<button type="submit" form="evcert-generator-form" class="components-button is-primary" name="evcert_generator_format" value="zip"><?php esc_html_e( 'Issue ZIP', 'events-certificates' ); ?></button>
			</div>
		</header>

		<form id="evcert-generator-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( url() ); ?>">
			<?php wp_nonce_field( 'evcert_generator' ); ?>
			<div class="evcert-generator__body">
				<aside class="evcert-generator__left-panel" id="evcert-generator-left-panel" aria-hidden="true">
					<div class="evcert-generator__left-head"><?php esc_html_e( 'Issue steps', 'events-certificates' ); ?></div>
					<div id="evcert-generator-left-root"></div>
				</aside>

				<div class="evcert-generator__canvas">
					<?php if ( $has_error ) : ?>
						<div class="evcert-generator__error"><?php echo esc_html( $error ); ?></div>
					<?php endif; ?>
					<div class="evcert-generator__sheet">
						<?php
						// A sample of the site's certificate design, drawn by the server (the browser app is on the front-end page).
						echo wp_kses( \EventsCertificate\QuickGenerator\sheet(), \EventsCertificate\QuickGenerator\svg_allowed(), \EventsCertificate\Render\allowed_protocols() );
						?>
					</div>
				</div>

				<aside class="evcert-generator__sidebar" aria-label="<?php esc_attr_e( 'Generator settings', 'events-certificates' ); ?>">
					<div id="evcert-generator-root"></div>
				</aside>
			</div>
		</form>
	</div>
	<?php
}
