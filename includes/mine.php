<?php
/**
 * "Get my certificate": a block (and the [evcert_mine] shortcode that draws
 * it) where someone types the e-mail they registered with and gets their
 * certificate, in a page of its own (/certificates/). docs/decisions/0030.
 *
 * Who can: the e-mail is on record (a CampTix ticket, a participant of an
 * event) and the person was marked present; otherwise a message. How the e-mail
 * is proven is includes/mine-auth.php.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Mine;

use EventsCertificate\Certificates;
use EventsCertificate\Designs;
use EventsCertificate\Events;
use EventsCertificate\MineAuth;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

/**
 * Option: the ID of the page with the form (0 when none was made).
 */
const OPTION = 'evcert_mine_page';

/**
 * Block name.
 */
const BLOCK = 'events-certificates/mine';

/**
 * Form fields and arguments.
 */
const EMAIL_FIELD = 'evcert_mine_email';
const CODE_FIELD  = 'evcert_mine_code';
const ACTION      = 'evcert_mine_action';
const GET_ARG     = 'evcert_mine_get';
const FORGET_ARG  = 'evcert_mine_forget';
const VIEW_ARG    = 'evcert_mine_view';

/**
 * What the request did, for the form to draw (set by handle()).
 *
 * @var array<string, mixed>
 */
$GLOBALS['evcert_mine_outcome'] = array();

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register' );
	add_action( 'template_redirect', __NAMESPACE__ . '\handle', 5 );
	add_action( 'admin_init', __NAMESPACE__ . '\ensure_page' );
}

/**
 * Registers the block and the shortcode.
 */
function register() {
	register_block_type( dirname( __DIR__ ) . '/blocks/mine' );
	add_shortcode( 'evcert_mine', __NAMESPACE__ . '\shortcode' );
}

/**
 * The slug of the page, translatable ("certificates", "certificados").
 *
 * @return string
 */
function slug() {
	$slug = sanitize_title( _x( 'certificates', 'URL slug of the "Get my certificate" page', 'events-certificates' ) );

	return '' !== $slug ? $slug : 'certificates';
}

/**
 * The page with the form, when it is published.
 *
 * @return int Page ID, or 0.
 */
function page_id() {
	$id = (int) get_option( OPTION, 0 );

	return $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
}

/**
 * The address of the form.
 *
 * @return string
 */
function url() {
	$id = page_id();

	return $id ? (string) get_permalink( $id ) : home_url( user_trailingslashit( slug() ) );
}

/**
 * Makes the page once, for administrators. An existing page at the address is
 * left alone; a deleted page isn't made again.
 */
function ensure_page() {
	if ( false !== get_option( OPTION ) || ! current_user_can( \EventsCertificate\Admin\CAPABILITY ) || wp_doing_ajax() ) {
		return;
	}
	if ( get_page_by_path( slug() ) ) {
		update_option( OPTION, 0, false );
		return;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Get my certificate', 'events-certificates' ),
			'post_name'    => slug(),
			'post_content' => '<!-- wp:' . BLOCK . ' /-->',
		),
		true
	);
	update_option( OPTION, is_wp_error( $id ) ? 0 : (int) $id, false );
}

/**
 * Who an e-mail is, among CampTix tickets and the participants of events.
 *
 * The state is missing, invalid, unknown (nobody has it), not_attended (it is on record but nobody was marked present) or ok.
 * The matches (only those marked present) have name, event, role, url ('' when the certificate isn't a link yet), kind, ref.
 *
 * @param string $email E-mail typed.
 * @return array{state: string, email: string, matches: array<int, array<string, string>>}
 */
function lookup( $email ) {
	$email  = MineAuth\normalize( $email );
	$result = array(
		'state'   => 'unknown',
		'email'   => $email,
		'matches' => array(),
	);
	if ( '' === $email ) {
		$result['state'] = 'missing';
		return $result;
	}
	if ( ! is_email( $email ) ) {
		$result['state'] = 'invalid';
		return $result;
	}
	$known = false;

	$tix = \EventsCertificate\Compat\CampTix\camptix();
	if ( $tix ) {
		$tickets = get_posts(
			array(
				'post_type'      => 'tix_attendee',
				'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
				'posts_per_page' => 20,
				'meta_key'       => 'tix_email', // phpcs:ignore WordPress.DB.SlowDBQuery -- one lookup by e-mail.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $tickets as $attendee ) {
			$known = true;
			if ( '' !== \EventsCertificate\Compat\CampTix\unavailable_reason( $attendee ) ) {
				continue;
			}
			$role                = \EventsCertificate\Compat\CampTix\attendee_role( $attendee );
			$result['matches'][] = array(
				'kind'  => 'camptix',
				'ref'   => (string) $attendee->ID,
				'name'  => trim( get_post_meta( $attendee->ID, 'tix_first_name', true ) . ' ' . get_post_meta( $attendee->ID, 'tix_last_name', true ) ),
				'event' => (string) get_bloginfo( 'name' ),
				'role'  => (string) ( Designs\role_labels()[ $role ] ?? '' ),
				'url'   => \EventsCertificate\Compat\CampTix\certificate_link( $attendee->ID ),
			);
		}
	}

	if ( \EventsCertificate\Environment\participants_screens() ) {
		foreach ( People\find_by_email( $email ) as $person ) {
			$known = true;
			if ( 'attended' !== $person['status'] ) {
				continue;
			}
			$event = Events\get( (int) $person['event_id'] );
			foreach ( array_keys( (array) $person['roles'] ) as $role ) {
				$result['matches'][] = array(
					'kind'  => 'person',
					'ref'   => $person['id'] . ':' . $role,
					'name'  => (string) $person['name'],
					'event' => $event ? (string) $event['title'] : '',
					'role'  => (string) ( Designs\role_labels()[ $role ] ?? '' ),
					'url'   => '',
				);
			}
		}
	}

	$result['state'] = $result['matches'] ? 'ok' : ( $known ? 'not_attended' : 'unknown' );

	return $result;
}

/**
 * The messages.
 *
 * @param string $key Which.
 * @return string
 */
function message( $key ) {
	$messages = array(
		'missing'      => __( 'Missing email address!', 'events-certificates' ),
		'invalid'      => __( 'Invalid email address!', 'events-certificates' ),
		'unknown'      => __( 'We couldn’t find your e-mail. If this keeps happening, please contact us with your full name, e-mail and the activities you took part in.', 'events-certificates' ),
		'not_attended' => __( 'You are not marked as present at this event. If you were there, please ask the organizers.', 'events-certificates' ),
		'neutral'      => __( 'If this e-mail is on the list and was marked as present, we have just sent you what you need to get your certificate.', 'events-certificates' ),
		'throttled'    => __( 'Too many requests. Please wait a few minutes and try again.', 'events-certificates' ),
		'bad_code'     => __( 'That code is not valid. Check it and try again, or ask for a new one.', 'events-certificates' ),
		'bad_link'     => __( 'This link is not valid any more. Ask for a new one.', 'events-certificates' ),
		'nonce'        => __( 'This form expired. Please try again.', 'events-certificates' ),
	);

	return $messages[ $key ] ?? '';
}

/**
 * Handles what a request asks, before anything is printed: the form's posts,
 * the mailed link and the certificate links.
 */
function handle() {
	global $evcert_mine_outcome;

	// The mailed link: prove the e-mail, then show the page without the token.
	// phpcs:disable WordPress.Security.NonceVerification -- the token is the proof; the form posts below check a nonce.
	if ( isset( $_GET[ MineAuth\TOKEN_ARG ] ) ) {
		$email = MineAuth\redeem( sanitize_text_field( wp_unslash( $_GET[ MineAuth\TOKEN_ARG ] ) ) );
		if ( '' !== $email ) {
			MineAuth\grant( $email );
			wp_safe_redirect( url() );
			exit;
		}
		$evcert_mine_outcome = array( 'error' => message( 'bad_link' ) );
		return;
	}
	if ( isset( $_GET[ FORGET_ARG ] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'evcert_mine_forget' ) ) {
		MineAuth\revoke();
		wp_safe_redirect( url() );
		exit;
	}
	if ( isset( $_GET[ VIEW_ARG ] ) ) {
		open_simplified( sanitize_text_field( wp_unslash( $_GET[ VIEW_ARG ] ) ), sanitize_key( wp_unslash( $_GET['k'] ?? '' ) ) );
		return;
	}
	if ( isset( $_GET[ GET_ARG ] ) ) {
		open_certificate( sanitize_text_field( wp_unslash( $_GET[ GET_ARG ] ) ), sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ) );
		return;
	}
	if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! isset( $_POST[ ACTION ] ) ) {
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'evcert_mine' ) ) {
		$evcert_mine_outcome = array( 'error' => message( 'nonce' ) );
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST[ ACTION ] ) );
	$email  = isset( $_POST[ EMAIL_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ EMAIL_FIELD ] ) ) : '';
	$result = lookup( $email );

	if ( 'verify' === $action ) {
		$typed = isset( $_POST[ CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ CODE_FIELD ] ) ) : '';
		if ( 'ok' === $result['state'] && MineAuth\verify( $email, $typed ) ) {
			MineAuth\grant( $email );
			$evcert_mine_outcome = array(
				'view'  => 'list',
				'email' => $email,
			);
			return;
		}
		$evcert_mine_outcome = array(
			'view'  => 'code',
			'email' => $email,
			'error' => message( 'bad_code' ),
		);
		return;
	}

	if ( ! in_array( $result['state'], array( 'ok', 'not_attended', 'unknown' ), true ) ) {
		$evcert_mine_outcome = array( 'error' => message( $result['state'] ) );
		return;
	}

	// Modes that mail something never say whether the e-mail is on the list.
	if ( MineAuth\mails() && ( 'send' === $action || in_array( MineAuth\mode(), array( 'digits4', 'digits6', 'link' ), true ) ) ) {
		$view = 'sent';
		if ( 'ok' === $result['state'] && ! MineAuth\start( $email ) ) {
			$evcert_mine_outcome = array( 'error' => message( 'throttled' ) );
			return;
		}
		$evcert_mine_outcome = array(
			'view'    => MineAuth\digits() > 0 ? 'code' : $view,
			'email'   => $email,
			'message' => message( 'neutral' ),
		);
		return;
	}

	if ( 'ok' !== $result['state'] ) {
		$evcert_mine_outcome = array( 'error' => message( $result['state'] ) );
		return;
	}
	if ( 'none' === MineAuth\mode() ) {
		MineAuth\grant( $email );
	}
	$evcert_mine_outcome = array(
		'view'  => 'list',
		'email' => $email,
	);
}

/**
 * Opens a person's certificate (issuing it when needed), for the proven e-mail.
 *
 * @param string $ref   person ID and role, "12:speaker".
 * @param string $nonce Nonce.
 */
function open_certificate( $ref, $nonce ) {
	global $evcert_mine_outcome;

	list( $id, $role ) = array_pad( explode( ':', $ref, 2 ), 2, '' );
	$person            = People\get( (int) $id );
	if ( ! $person || ! wp_verify_nonce( $nonce, 'evcert_mine_get_' . $ref ) || MineAuth\current_email() !== MineAuth\normalize( $person['email'] ) ) {
		$evcert_mine_outcome = array( 'error' => message( 'nonce' ) );
		return;
	}
	$cert = People\issue( $person, sanitize_key( $role ) );
	if ( is_wp_error( $cert ) ) {
		$evcert_mine_outcome = array( 'error' => $cert->get_error_message() );
		return;
	}
	wp_safe_redirect( Certificates\url( $cert['code'] ) );
	exit;
}

/**
 * What a person's certificate says, without being one: the artwork's data with no
 * code, no hours and nothing kept. For the simplified view of the e-mail step.
 *
 * @param string $ref Person ID and role, "12:speaker".
 * @return array<string, mixed> Empty when it is not someone who attended.
 */
function simple_certificate( $ref ) {
	list( $id, $role ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	$person            = (int) $id ? People\get( (int) $id ) : null;
	$role              = sanitize_key( $role );
	$event             = $person ? Events\get( (int) $person['event_id'] ) : null;
	if ( ! $person || ! $event || 'attended' !== $person['status'] || ! isset( $person['roles'][ $role ] ) ) {
		return array();
	}

	return array(
		'id'          => 0,
		'name'        => (string) $person['name'],
		'email'       => (string) $person['email'],
		'role'        => $role,
		'code'        => '',
		'status'      => 'issued',
		'hours'       => '',
		'details'     => '',
		'context'     => Events\CONTEXT,
		'event_id'    => (int) $event['id'],
		'event_title' => (string) $event['title'],
		'event_start' => Events\utc( $event['start'] ),
		'event_end'   => Events\utc( $event['end'] ),
		'event_tz'    => wp_timezone_string(),
		'issued_at'   => gmdate( 'Y-m-d H:i:s' ),
		'source'      => People\SOURCE,
		'source_ref'  => (string) $person['id'],
		'data_json'   => (string) wp_json_encode( array( 'location' => $event['location'] ) ),
	);
}

/**
 * Opens the simplified certificate (the Dock offers to authenticate); someone who proved the e-mail goes on to the certificate.
 *
 * @param string $ref Person and role.
 * @param string $key Key of the link (view_key()).
 */
function open_simplified( $ref, $key ) {
	global $evcert_mine_outcome;

	$cert = hash_equals( view_key( $ref ), $key ) ? simple_certificate( $ref ) : array();
	if ( ! $cert ) {
		$evcert_mine_outcome = array( 'error' => message( 'nonce' ) );
		return;
	}
	if ( MineAuth\current_email() !== '' ) {
		wp_safe_redirect( url() );
		exit;
	}
	\EventsCertificate\Verify\render_page( $cert, '', determine_locale(), false, true );
	exit;
}

/**
 * The [evcert_mine] shortcode: the block, drawn the same way.
 *
 * @param array<string, string>|string $atts title.
 * @return string
 */
function shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
		),
		(array) $atts,
		'evcert_mine'
	);

	return render_block(
		array(
			'blockName'    => BLOCK,
			'attrs'        => array(
				'title' => (string) $atts['title'],
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

/**
 * A form row in CampTix's table.
 *
 * @param string $label Label.
 * @param string $field Field HTML (already escaped).
 * @param string $id    Field ID.
 * @return string
 */
function row( $label, $field, $id ) {
	return '<tr><td class="tix-required tix-left"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' <span aria-hidden="true" class="tix-required-star">*</span></label></td><td class="tix-right">' . $field . '</td></tr>';
}

/**
 * The form, in CampTix's markup (its stylesheet when CampTix is active).
 *
 * @param array<string, mixed> $attributes title.
 * @return string
 */
function form( array $attributes ) {
	global $evcert_mine_outcome;

	if ( wp_style_is( 'camptix', 'registered' ) ) {
		wp_enqueue_style( 'camptix' );
	}
	$out     = (array) $evcert_mine_outcome;
	$title   = trim( (string) ( $attributes['title'] ?? '' ) );
	$title   = '' !== $title ? $title : __( 'Get my certificate', 'events-certificates' );
	$view    = (string) ( $out['view'] ?? '' );
	$email   = (string) ( $out['email'] ?? '' );
	$proven  = MineAuth\current_email();
	$wrapper = function_exists( 'get_block_wrapper_attributes' ) && \WP_Block_Supports::$block_to_render ? get_block_wrapper_attributes( array( 'class' => 'evcert-mine-form' ) ) : 'class="evcert-mine-form"';

	// Someone who already proved an e-mail sees their certificates.
	if ( '' === $view && '' !== $proven && empty( $out['error'] ) ) {
		$view  = 'list';
		$email = $proven;
	}

	ob_start();
	?>
	<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes. ?>>
		<div id="tix" class="evcert-tix">
			<?php if ( ! empty( $out['error'] ) ) : ?>
				<div id="tix-errors"><div class="tix-error"><?php echo esc_html( (string) $out['error'] ); ?></div></div>
			<?php endif; ?>
			<?php if ( ! empty( $out['message'] ) ) : ?>
				<div class="tix-notice"><?php echo esc_html( (string) $out['message'] ); ?></div>
			<?php endif; ?>
			<?php
			if ( 'list' === $view ) {
				echo list_view( $email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			} elseif ( 'code' === $view ) {
				echo code_view( $title, $email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			} elseif ( 'sent' !== $view ) {
				echo email_view( $title, $email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
			?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The e-mail step.
 *
 * @param string $title Title.
 * @param string $email E-mail to show back.
 * @return string
 */
function email_view( $title, $email ) {
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( url() ); ?>">
		<?php wp_nonce_field( 'evcert_mine' ); ?>
		<input type="hidden" name="<?php echo esc_attr( ACTION ); ?>" value="lookup">
		<table class="tix_tickets_table tix-attendee-form">
			<tbody>
				<tr><th colspan="2"><?php echo esc_html( $title ); ?></th></tr>
				<tr><td colspan="2"><?php esc_html_e( 'Enter the e-mail you used to register for the event:', 'events-certificates' ); ?></td></tr>
				<?php
				echo row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row() escapes its parts.
					__( 'Email', 'events-certificates' ),
					'<input id="evcert-mine-email" name="' . esc_attr( EMAIL_FIELD ) . '" type="email" required value="' . esc_attr( $email ) . '" placeholder="my@email.com" autocomplete="email" />',
					'evcert-mine-email'
				);
				?>
			</tbody>
		</table>
		<p class="tix-submit">
			<input type="submit" value="<?php esc_attr_e( 'Generate', 'events-certificates' ); ?>" />
			<br class="tix-clear" />
		</p>
	</form>
	<?php
	return (string) ob_get_clean();
}

/**
 * The code step.
 *
 * @param string $title Title.
 * @param string $email E-mail.
 * @return string
 */
function code_view( $title, $email ) {
	$length = max( 4, MineAuth\digits() );
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( url() ); ?>">
		<?php wp_nonce_field( 'evcert_mine' ); ?>
		<input type="hidden" name="<?php echo esc_attr( ACTION ); ?>" value="verify">
		<input type="hidden" name="<?php echo esc_attr( EMAIL_FIELD ); ?>" value="<?php echo esc_attr( $email ); ?>">
		<table class="tix_tickets_table tix-attendee-form">
			<tbody>
				<tr><th colspan="2"><?php echo esc_html( $title ); ?></th></tr>
				<?php
				echo row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row() escapes its parts.
					__( 'Code', 'events-certificates' ),
					'<input id="evcert-mine-code" name="' . esc_attr( CODE_FIELD ) . '" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="' . (int) $length . '" pattern="[0-9]{' . (int) $length . '}" placeholder="' . esc_attr( str_repeat( '0', $length ) ) . '" />',
					'evcert-mine-code'
				);
				?>
			</tbody>
		</table>
		<p class="tix-submit">
			<input type="submit" value="<?php esc_attr_e( 'Confirm', 'events-certificates' ); ?>" />
			<br class="tix-clear" />
		</p>
	</form>
	<?php
	return (string) ob_get_clean();
}

/**
 * The certificates of an e-mail: with links when the e-mail was proven, else the simplified view.
 *
 * @param string $email E-mail.
 * @return string
 */
function list_view( $email ) {
	$result = lookup( $email );
	if ( 'ok' !== $result['state'] ) {
		return email_view( __( 'Get my certificate', 'events-certificates' ), $email );
	}
	$proven = MineAuth\current_email() === MineAuth\normalize( $email );
	$titles = array_unique( wp_list_pluck( $result['matches'], 'event' ) );
	$many   = count( $result['matches'] ) > 1;
	if ( 1 === count( $titles ) && '' !== (string) reset( $titles ) ) {
		/* translators: %s: event title. */
		$thanks = $many ? sprintf( __( 'Thank you for taking part in %s! Here are your certificates.', 'events-certificates' ), reset( $titles ) ) : sprintf( __( 'Thank you for taking part in %s! Here is your certificate.', 'events-certificates' ), reset( $titles ) );
	} else {
		$thanks = $many ? __( 'Thank you for taking part! Here are your certificates.', 'events-certificates' ) : __( 'Thank you for taking part! Here is your certificate.', 'events-certificates' );
	}

	ob_start();
	?>
	<table class="tix_tickets_table tix-attendee-form">
		<tbody>
			<tr><th colspan="2"><?php echo esc_html( $thanks ); ?></th></tr>
			<?php foreach ( $result['matches'] as $found ) : ?>
				<tr>
					<td class="tix-left"><?php echo esc_html( $found['name'] ); ?></td>
					<td class="tix-right">
						<?php echo esc_html( trim( $found['event'] . ( '' !== $found['role'] ? ' · ' . $found['role'] : '' ) ) ); ?>
						<?php if ( $proven ) : ?>
							<br><a href="<?php echo esc_url( match_url( $found ) ); ?>"><?php esc_html_e( 'Get certificate', 'events-certificates' ); ?></a>
						<?php elseif ( 'person' === $found['kind'] ) : ?>
							<br><a href="<?php echo esc_url( view_url( $found ) ); ?>"><?php esc_html_e( 'View', 'events-certificates' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! $proven ) : ?>
		<form method="post" action="<?php echo esc_url( url() ); ?>">
			<?php wp_nonce_field( 'evcert_mine' ); ?>
			<input type="hidden" name="<?php echo esc_attr( ACTION ); ?>" value="send">
			<input type="hidden" name="<?php echo esc_attr( EMAIL_FIELD ); ?>" value="<?php echo esc_attr( $email ); ?>">
			<p><?php esc_html_e( 'To get the certificate itself, confirm that this e-mail is yours.', 'events-certificates' ); ?></p>
			<p class="tix-submit">
				<input type="submit" value="<?php esc_attr_e( 'Authenticate', 'events-certificates' ); ?>" />
				<br class="tix-clear" />
			</p>
		</form>
	<?php else : ?>
		<p><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( FORGET_ARG, 1, url() ), 'evcert_mine_forget' ) ); ?>"><?php esc_html_e( 'Not you? Use another e-mail.', 'events-certificates' ); ?></a></p>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * The link to the simplified view of a match.
 *
 * @param array<string, string> $found Match from lookup().
 * @return string
 */
function view_url( array $found ) {
	return add_query_arg(
		array(
			VIEW_ARG => $found['ref'],
			'k'      => view_key( $found['ref'] ),
		),
		url()
	);
}

/**
 * The key a simplified-view link carries. A nonce would be the same for every visitor who is not
 * logged in, and person IDs are sequential: only who was shown the link can open it.
 *
 * @param string $ref Person and role.
 * @return string
 */
function view_key( $ref ) {
	return substr( hash_hmac( 'sha256', 'evcert_mine_view|' . $ref, wp_salt( 'auth' ) ), 0, 24 );
}

/**
 * The link of a match for someone who proved the e-mail.
 *
 * @param array<string, string> $found Match from lookup().
 * @return string
 */
function match_url( array $found ) {
	if ( '' !== $found['url'] ) {
		return $found['url'];
	}

	return add_query_arg(
		array(
			GET_ARG    => $found['ref'],
			'_wpnonce' => wp_create_nonce( 'evcert_mine_get_' . $found['ref'] ),
		),
		url()
	);
}
