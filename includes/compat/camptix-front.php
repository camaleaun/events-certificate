<?php
/**
 * CampTix front end: attendees get their certificate from the tickets page,
 * the same way they edit their ticket.
 *
 * The edit link CampTix e-mails (tix_action=edit_attendee with the attendee
 * ID and its edit token) opens a "Certificate" step in [camptix]
 * (tix_action=certificate, same tix_attendee_id and tix_edit_token), with
 * CampTix's markup: the attendee's name (editable, as it goes on the
 * certificate), the event and the ticket. Issuing freezes the data and gives
 * a code; correcting the name later issues a new certificate and marks the
 * old one replaced (docs/decisions/0001).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\CampTix;

use EventsCertificate\Certificates;
use EventsCertificate\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The tix_action value of the certificate step.
 */
const ACTION = 'certificate';

/**
 * Source name for certificates issued from CampTix attendees.
 */
const SOURCE = 'camptix';

/**
 * Front-end hooks.
 */
function register_front() {
	add_filter( 'camptix_shortcode_contents', __NAMESPACE__ . '\shortcode_contents', 10, 2 );
	add_filter( 'camptix_edit_info_cell_content', __NAMESPACE__ . '\edit_info_cell', 10, 2 );
	add_action( 'add_meta_boxes_' . TICKET_TYPE, __NAMESPACE__ . '\add_role_box' );
	add_action( 'save_post_' . TICKET_TYPE, __NAMESPACE__ . '\save_role_box' );
}

/**
 * CampTix's ticket post type.
 */
const TICKET_TYPE = 'tix_ticket';

/**
 * The ticket's meta that holds the role its certificate is for.
 */
const ROLE_META = '_evcert_role';

/**
 * A box on the ticket's screen: which role the certificate of this ticket is for.
 */
function add_role_box() {
	add_meta_box( 'evcert-ticket-role', __( 'Certificate', 'events-certificates' ), __NAMESPACE__ . '\render_role_box', TICKET_TYPE, 'side' );
}

/**
 * The box: the roles to choose from.
 *
 * @param \WP_Post $ticket Ticket.
 */
function render_role_box( $ticket ) {
	$current = (string) get_post_meta( $ticket->ID, ROLE_META, true );
	wp_nonce_field( 'evcert_ticket_role_' . $ticket->ID, 'evcert_ticket_role_nonce' );
	echo '<p><label for="evcert_ticket_role">' . esc_html__( 'Role on the certificate', 'events-certificates' ) . '</label></p><p><select name="evcert_ticket_role" id="evcert_ticket_role">';
	foreach ( \EventsCertificate\Designs\role_labels() as $role => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $role ), selected( '' !== $current ? $current : 'attendee', $role, false ), esc_html( $label ) );
	}
	echo '</select></p><p class="description">' . esc_html__( 'Whoever has this ticket gets a certificate for this role: a speaker ticket, an organizer ticket, a volunteer ticket.', 'events-certificates' ) . '</p>';
}

/**
 * Saves the box.
 *
 * @param int $ticket_id Ticket.
 */
function save_role_box( $ticket_id ) {
	$nonce = isset( $_POST['evcert_ticket_role_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_ticket_role_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'evcert_ticket_role_' . $ticket_id ) || ! current_user_can( 'edit_post', $ticket_id ) ) {
		return;
	}
	$role = isset( $_POST['evcert_ticket_role'] ) ? sanitize_key( wp_unslash( $_POST['evcert_ticket_role'] ) ) : 'attendee';
	if ( isset( \EventsCertificate\Designs\role_labels()[ $role ] ) && 'attendee' !== $role ) {
		update_post_meta( $ticket_id, ROLE_META, $role );
	} else {
		delete_post_meta( $ticket_id, ROLE_META );
	}
}

/**
 * The CampTix instance.
 *
 * @return \CampTix_Plugin|null
 */
function camptix() {
	return isset( $GLOBALS['camptix'] ) && $GLOBALS['camptix'] instanceof \CampTix_Plugin ? $GLOBALS['camptix'] : null;
}

/**
 * The attendee a request points to, when its edit token matches.
 *
 * @return \WP_Post|null
 */
function requested_attendee() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the edit token is the credential, as in CampTix's own edit link.
	$id    = isset( $_GET['tix_attendee_id'] ) ? absint( $_GET['tix_attendee_id'] ) : 0;
	$token = isset( $_GET['tix_edit_token'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_edit_token'] ) ) : '';
	// phpcs:enable
	if ( ! $id || '' === $token || ! ctype_alnum( $token ) ) {
		return null;
	}
	$attendee = get_post( $id );
	if ( ! $attendee || 'tix_attendee' !== $attendee->post_type ) {
		return null;
	}

	return hash_equals( (string) get_post_meta( $id, 'tix_edit_token', true ), $token ) ? $attendee : null;
}

/**
 * Why an attendee can't get their certificate yet, or '' when they can.
 *
 * @param \WP_Post $attendee Attendee.
 * @return string
 */
function unavailable_reason( $attendee ) {
	$who = Settings\value( 'self_issue' );
	if ( 'off' === $who ) {
		return __( 'Certificates for this event are issued by the organizers.', 'events-certificates' );
	}
	$blocked = blocked( $attendee );
	if ( $blocked ) {
		return $blocked->get_error_message();
	}
	if ( 'publish' !== $attendee->post_status ) {
		return __( 'Your ticket isn’t confirmed yet. The certificate is available once it is.', 'events-certificates' );
	}
	if ( 'attended' === $who && ! get_post_meta( $attendee->ID, 'tix_attended', true ) ) {
		return __( 'Certificates are available to attendees marked as present at the event. If you were there, please ask the organizers.', 'events-certificates' );
	}

	return '';
}

/**
 * The link to an attendee's certificate step.
 *
 * @param int $attendee_id Attendee ID.
 * @return string
 */
function certificate_link( $attendee_id ) {
	$tix = camptix();

	return add_query_arg(
		array(
			'tix_action'      => ACTION,
			'tix_attendee_id' => $attendee_id,
			'tix_edit_token'  => get_post_meta( $attendee_id, 'tix_edit_token', true ),
		),
		$tix ? $tix->get_tickets_url() : home_url( '/' )
	) . '#tix';
}

/**
 * The signer frozen into a certificate.
 *
 * @return array<string, mixed>
 */
function signer_data() {
	$signer = \EventsCertificate\Signer\current();

	return $signer ? array(
		'signer_name'  => $signer['name'],
		'signer_roles' => $signer['roles'],
		'signature'    => $signer['signature'],
	) : array();
}

/**
 * The event's name: the WordCamp's on WordCamp.org (get_wordcamp_name()),
 * else CampTix's event name (an option it has no field for), else the site title.
 *
 * @return string
 */
function event_name() {
	if ( function_exists( 'get_wordcamp_name' ) ) {
		$name = trim( (string) get_wordcamp_name() );
		if ( '' !== $name ) {
			return $name;
		}
	}
	$options = camptix() ? (array) camptix()->get_options() : array();
	$name    = trim( (string) ( $options['event_name'] ?? '' ) );

	return '' !== $name ? $name : (string) get_bloginfo( 'name' );
}

/**
 * Where the event happens: the WordCamp's Location on WordCamp.org, else the
 * plugin setting.
 *
 * @return string
 */
function event_location() {
	if ( function_exists( 'get_wordcamp_post' ) ) {
		$wordcamp = get_wordcamp_post();
		if ( $wordcamp && ! empty( $wordcamp->meta['Location'][0] ) ) {
			return (string) $wordcamp->meta['Location'][0];
		}
	}

	return (string) Settings\value( 'location' );
}

/**
 * The role an attendee's certificate is for: the one set on the ticket (a speaker's, an organizer's, a volunteer's
 * ticket), an attendee's by default, and the filter has the last word.
 *
 * @param \WP_Post $attendee Attendee.
 * @return string
 */
function attendee_role( $attendee ) {
	/**
	 * Filters the role of the certificate of a CampTix attendee.
	 *
	 * @param string   $role     Role key (see Designs\role_labels()); attendee by default.
	 * @param \WP_Post $attendee Attendee.
	 */
	$ticket_role = (string) get_post_meta( (int) get_post_meta( $attendee->ID, 'tix_ticket_id', true ), ROLE_META, true );
	$role        = (string) apply_filters( 'evcert_camptix_role', '' !== $ticket_role ? $ticket_role : 'attendee', $attendee );

	return isset( \EventsCertificate\Designs\role_labels()[ $role ] ) ? $role : 'attendee';
}

/**
 * What keeps an attendee without a certificate from getting one: theirs was
 * revoked (a revoked certificate is final), or the same person already has one
 * for this event from another source (CampTix with GatherPress: the first
 * one issued is the certificate).
 *
 * @param \WP_Post $attendee Attendee.
 * @return WP_Error|null
 */
function blocked( $attendee ) {
	$role = attendee_role( $attendee );
	if ( Certificates\find_by_source( SOURCE, $attendee->ID, $role ) ) {
		return null;
	}
	$latest = Certificates\latest_by_source( SOURCE, $attendee->ID, $role );
	if ( $latest && 'revoked' === $latest['status'] ) {
		return new WP_Error( 'evcert_revoked', __( 'Your certificate was revoked, so it is not issued again. Please contact the organizers.', 'events-certificates' ) );
	}
	$twin = Certificates\find_for_person( (string) get_post_meta( $attendee->ID, 'tix_email', true ), event_name(), SOURCE, $role );
	if ( $twin ) {
		return new WP_Error(
			'evcert_exists',
			\EventsCertificate\Environment\verification_enabled()
				? sprintf(
					/* translators: %s: certificate code. */
					__( 'A certificate for this event is already issued to your e-mail address, with the code %s.', 'events-certificates' ),
					$twin['code']
				)
				: __( 'A certificate for this event is already issued to your e-mail address.', 'events-certificates' )
		);
	}

	return null;
}

/**
 * The days of the event, from the WordCamp's post: its start and end dates,
 * at noon UTC so the day is the same in any timezone.
 *
 * @param object|false $wordcamp WordCamp post (get_wordcamp_post()), with its "meta".
 * @return array<string, string> event_start and event_end, or nothing without a start date.
 */
function dates_of( $wordcamp ) {
	$meta  = $wordcamp && isset( $wordcamp->meta ) ? (array) $wordcamp->meta : array();
	$start = (int) ( $meta['Start Date (YYYY-mm-dd)'][0] ?? 0 );
	$end   = (int) ( $meta['End Date (YYYY-mm-dd)'][0] ?? 0 );
	if ( ! $start ) {
		return array();
	}

	return array(
		'event_start' => gmdate( 'Y-m-d 12:00:00', $start ),
		'event_end'   => gmdate( 'Y-m-d 12:00:00', $end ? $end : $start ),
	);
}

/**
 * What the certificate says, from the attendee.
 *
 * @param \WP_Post $attendee Attendee.
 * @return array<string, mixed>
 */
function attendee_data( $attendee ) {
	$tix      = camptix();
	$first    = (string) get_post_meta( $attendee->ID, 'tix_first_name', true );
	$last     = (string) get_post_meta( $attendee->ID, 'tix_last_name', true );
	$username = (string) get_post_meta( $attendee->ID, 'tix_username', true );
	if ( class_exists( 'CampTix_Require_Login' ) && \CampTix_Require_Login::UNCONFIRMED_USERNAME === $username ) {
		$username = '';
	}
	$data = array(
		'context'        => 'site',
		'event_id'       => 0,
		'name'           => trim( $tix ? $tix->format_name_string( '%first% %last%', $first, $last ) : "$first $last" ),
		'email'          => (string) get_post_meta( $attendee->ID, 'tix_email', true ),
		'wporg_username' => $username,
		'role'           => attendee_role( $attendee ),
		'attended'       => get_post_meta( $attendee->ID, 'tix_attended', true ) ? 1 : 0,
		'event_title'    => event_name(),
		'source'         => SOURCE,
		'source_ref'     => (string) $attendee->ID,
		'self_service'   => 'attendee',
		'data'           => array_merge( array( 'location' => event_location() ), signer_data() ),
	) + dates_of( function_exists( 'get_wordcamp_post' ) ? get_wordcamp_post() : false );

	/**
	 * Filters what a certificate issued from a CampTix attendee says (event
	 * dates, workload, role…), before it is frozen.
	 *
	 * @param array<string, mixed> $data     Certificate fields.
	 * @param \WP_Post             $attendee Attendee.
	 */
	return apply_filters( 'evcert_camptix_certificate_data', $data, $attendee );
}

/**
 * Issues or corrects an attendee's certificate from what they sent.
 *
 * @param \WP_Post $attendee Attendee.
 * @param string   $name     Name to have on the certificate.
 * @return array{certificate: array<string, mixed>, done: string, full: string}|WP_Error|null The certificate and what was
 *         done (issued|corrected), plus the full document number when they asked to download it; null when nothing changed.
 */
function issue_for_attendee( $attendee, $name ) {
	$current = Certificates\find_by_source( SOURCE, $attendee->ID, attendee_role( $attendee ) );
	$blocked = blocked( $attendee );
	if ( $blocked ) {
		return $blocked;
	}
	// Days, table, talks, the second page and its document (includes/compat/camptix-choices.php).
	$choices = Choices\read( $attendee, $current );
	if ( is_wp_error( $choices ) ) {
		return $choices;
	}
	$changes = array( 'name' => $name );
	if ( $choices ) {
		$changes['hours'] = $choices['hours'];
		$changes         += $choices['columns'];
	}
	$full = $choices ? (string) $choices['full'] : '';

	if ( $current ) {
		$kept = (array) json_decode( (string) $current['data_json'], true );
		$same = $name === $current['name'] && ( ! $choices || ( array_intersect_key( $kept, $choices['data'] ) == $choices['data'] && ! $choices['columns'] && (float) $current['hours'] === (float) $choices['hours'] ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- arrays compared by value.
		if ( $same && '' === $full ) {
			return null;
		}
		$result = $same ? $current : Certificates\reissue( $current, $choices ? $changes + array( 'data' => array_merge( $kept, $choices['data'] ) ) : $changes );
		$done   = $same ? '' : 'corrected';
	} else {
		$base   = attendee_data( $attendee );
		$data   = $choices ? array_merge( (array) $base['data'], $choices['data'] ) : (array) $base['data'];
		$result = Certificates\issue( array_merge( $base, $changes, array( 'data' => $data ) ) );
		$done   = 'issued';
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'certificate' => $result,
		'done'        => $done,
		'full'        => $full,
	);
}

/**
 * Handles the certificate form (issue or correct), then redirects back.
 *
 * @param \WP_Post $attendee Attendee.
 * @return string The error to show; it does not return when the form was handled.
 */
function handle_post( $attendee ) {
	if ( ! isset( $_POST['evcert_certificate_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evcert_certificate_nonce'] ) ), 'evcert_certificate_' . $attendee->ID ) ) {
		return __( 'The form expired. Please try again.', 'events-certificates' );
	}
	$name = isset( $_POST['evcert_name'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_name'] ) ) : '';
	$done = issue_for_attendee( $attendee, $name );
	if ( is_wp_error( $done ) ) {
		return $done->get_error_message();
	}
	if ( null === $done ) {
		return '';
	}

	// The full document number goes only in this download, never stored (decision 0014).
	if ( '' !== $done['full'] ) {
		$bytes = \EventsCertificate\Render\pdf( $done['certificate'], Certificates\design( $done['certificate'] ), array( 'document' => $done['full'] ) );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="certificate-' . $done['certificate']['code'] . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
		exit;
	}

	wp_safe_redirect( add_query_arg( 'evcert_done', $done['done'], certificate_link( $attendee->ID ) ) );
	exit;
}

/**
 * Replaces [camptix]'s contents on the certificate step, and adds a link to
 * the certificate on the edit attendee step.
 *
 * @param string $contents   Shortcode markup.
 * @param string $tix_action Current step.
 * @return string
 */
function shortcode_contents( $contents, $tix_action ) {
	if ( 'edit_attendee' === $tix_action ) {
		$attendee = requested_attendee();
		if ( $attendee && '' === unavailable_reason( $attendee ) ) {
			$notice   = sprintf(
				'<div class="tix-notice">%s <a href="%s">%s</a></div>',
				esc_html__( 'Your certificate is available.', 'events-certificates' ),
				esc_url( certificate_link( $attendee->ID ) ),
				Certificates\find_by_source( SOURCE, $attendee->ID, attendee_role( $attendee ) ) ? esc_html__( 'View certificate', 'events-certificates' ) : esc_html__( 'Get your certificate', 'events-certificates' )
			);
			$contents = preg_replace( '/(<div id="tix">)/', '$1' . $notice, $contents, 1 );
		}
		return $contents;
	}
	if ( ACTION !== $tix_action ) {
		return $contents;
	}

	$attendee = requested_attendee();
	if ( ! $attendee ) {
		return '<div id="tix"><div id="tix-errors"><div class="tix-error">' . esc_html__( 'This link isn’t valid. Use the link from your ticket e-mail.', 'events-certificates' ) . '</div></div></div>';
	}

	$error = '';
	if ( isset( $_POST['evcert_certificate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence only; the nonce is checked in handle_post().
		$error = '' === unavailable_reason( $attendee ) ? handle_post( $attendee ) : unavailable_reason( $attendee );
	}

	return render_step( $attendee, $error );
}

/**
 * The certificate step.
 *
 * @param \WP_Post $attendee Attendee.
 * @param string   $error    Error to show.
 * @return string
 */
function render_step( $attendee, $error = '' ) {
	$tix     = camptix();
	$reason  = unavailable_reason( $attendee );
	$current = Certificates\find_by_source( SOURCE, $attendee->ID, attendee_role( $attendee ) );
	$data    = attendee_data( $attendee );
	$ticket  = $tix ? $tix->get_ticket_title( (int) get_post_meta( $attendee->ID, 'tix_ticket_id', true ) ) : '';
	$done    = isset( $_GET['evcert_done'] ) ? sanitize_key( wp_unslash( $_GET['evcert_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message.
	$name    = $current ? $current['name'] : $data['name'];

	ob_start();
	?>
	<div id="tix" class="evcert-tix">
		<?php if ( $error ) : ?>
			<div id="tix-errors"><div class="tix-error"><?php echo esc_html( $error ); ?></div></div>
		<?php elseif ( 'issued' === $done ) : ?>
			<div id="tix-notices"><div class="tix-notice"><?php esc_html_e( 'Your certificate was issued.', 'events-certificates' ); ?></div></div>
		<?php elseif ( 'corrected' === $done ) : ?>
			<div id="tix-notices"><div class="tix-notice"><?php esc_html_e( 'Your certificate was corrected. The previous code now says it was replaced.', 'events-certificates' ); ?></div></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Certificate', 'events-certificates' ); ?></h2>

		<?php if ( $reason && ! $current ) : ?>
			<div id="tix-infos"><div class="tix-info"><?php echo esc_html( $reason ); ?></div></div>
		<?php else : ?>
			<?php if ( $current ) : ?>
				<table class="tix_tickets_table tix-attendee-form evcert-certificate-summary">
					<tbody>
						<tr><th colspan="2"><?php echo esc_html( \EventsCertificate\Render\event_title( $current ) ); ?></th></tr>
						<tr><td class="tix-left"><?php esc_html_e( 'Name', 'events-certificates' ); ?></td><td class="tix-right"><strong><?php echo esc_html( $current['name'] ); ?></strong></td></tr>
						<?php if ( \EventsCertificate\Environment\verification_enabled() ) : ?>
							<tr><td class="tix-left"><?php esc_html_e( 'Code', 'events-certificates' ); ?></td><td class="tix-right"><code><?php echo esc_html( $current['code'] ); ?></code></td></tr>
						<?php endif; ?>
						<tr><td class="tix-left"><?php esc_html_e( 'Issued on', 'events-certificates' ); ?></td><td class="tix-right"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $current['issued_at'] . ' UTC' ) ) ); ?></td></tr>
						<tr><td class="tix-left"><?php echo esc_html( \EventsCertificate\Environment\verification_enabled() ? __( 'Address', 'events-certificates' ) : __( 'Certificate', 'events-certificates' ) ); ?></td><td class="tix-right"><a href="<?php echo esc_url( own_certificate_link( $attendee, $current['code'] ) ); ?>"><?php echo esc_html( \EventsCertificate\Environment\verification_enabled() ? Certificates\url( $current['code'] ) : __( 'View the certificate', 'events-certificates' ) ); ?></a></td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! $reason ) : ?>
				<form action="<?php echo esc_url( certificate_link( $attendee->ID ) ); ?>" method="POST">
					<input type="hidden" name="evcert_certificate" value="1" />
					<?php wp_nonce_field( 'evcert_certificate_' . $attendee->ID, 'evcert_certificate_nonce' ); ?>
					<table class="tix_tickets_table tix-attendee-form">
						<tbody>
							<tr><th colspan="2"><?php echo esc_html( $current ? __( 'Correct the certificate', 'events-certificates' ) : trim( $data['event_title'] . ( $ticket ? ' — ' . $ticket : '' ) ) ); ?></th></tr>
							<tr>
								<td class="tix-required tix-left">
									<label for="evcert_name">
										<?php esc_html_e( 'Name on the certificate', 'events-certificates' ); ?>
										<span aria-hidden="true" class="tix-required-star">*</span>
									</label>
								</td>
								<td class="tix-right">
									<input id="evcert_name" name="evcert_name" type="text" required value="<?php echo esc_attr( $name ); ?>" />
									<p class="tix-description"><?php esc_html_e( 'As it should appear: check accents and capital letters.', 'events-certificates' ); ?></p>
								</td>
							</tr>
							<?php if ( ! $current ) : ?>
								<tr><td class="tix-left"><?php esc_html_e( 'E-mail', 'events-certificates' ); ?></td><td class="tix-right"><?php echo esc_html( $data['email'] ); ?></td></tr>
							<?php endif; ?>
							<?php echo Choices\rows( $attendee, $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Choices\rows(). ?>
						</tbody>
					</table>
					<p>
						<input type="submit" value="<?php echo esc_attr( $current ? ( \EventsCertificate\Environment\verification_enabled() ? __( 'Correct and reissue', 'events-certificates' ) : __( 'Save the correction', 'events-certificates' ) ) : __( 'Issue certificate', 'events-certificates' ) ); ?>" style="float: right; cursor: pointer;" />
						<br class="tix-clear" />
					</p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div><!-- #tix -->
	<?php
	return (string) ob_get_clean();
}

/**
 * The attendee's own link to their certificate page: with their edit token,
 * so their visit can make the share image (includes/share-image.php).
 *
 * @param \WP_Post $attendee Attendee.
 * @param string   $code     Certificate code.
 * @return string
 */
function own_certificate_link( $attendee, $code ) {
	return add_query_arg(
		array(
			'tix_attendee_id' => $attendee->ID,
			'tix_edit_token'  => get_post_meta( $attendee->ID, 'tix_edit_token', true ),
		),
		Certificates\url( $code )
	);
}

/**
 * Adds a Certificate link next to "Edit information" in the tickets list.
 *
 * @param string   $html     Cell markup.
 * @param \WP_Post $attendee Attendee.
 * @return string
 */
function edit_info_cell( $html, $attendee ) {
	$reason = unavailable_reason( $attendee );
	if ( '' !== $reason ) {
		// Not there yet (the organizers have not recorded their presence, a revocation…): the attendee is told why, not left to guess.
		return $html . sprintf( '<br /><span class="description evcert-certificate-hint">%s</span>', esc_html( $reason ) );
	}

	return $html . sprintf( '<br /><a href="%s">%s</a>', esc_url( certificate_link( $attendee->ID ) ), esc_html__( 'Certificate', 'events-certificates' ) );
}
