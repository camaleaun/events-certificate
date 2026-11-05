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
		return __( 'Certificates for this event are issued by the organizers.', 'events-certificate' );
	}
	if ( 'publish' !== $attendee->post_status ) {
		return __( 'Your ticket isn’t confirmed yet. The certificate is available once it is.', 'events-certificate' );
	}
	if ( 'attended' === $who && ! get_post_meta( $attendee->ID, 'tix_attended', true ) ) {
		return __( 'Certificates are available to attendees marked as present at the event. If you were there, please ask the organizers.', 'events-certificate' );
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
	$options = $tix ? (array) $tix->get_options() : array();

	$data = array(
		'context'        => 'site',
		'event_id'       => 0,
		'name'           => trim( $tix ? $tix->format_name_string( '%first% %last%', $first, $last ) : "$first $last" ),
		'email'          => (string) get_post_meta( $attendee->ID, 'tix_email', true ),
		'wporg_username' => $username,
		'role'           => 'attendee',
		'attended'       => get_post_meta( $attendee->ID, 'tix_attended', true ) ? 1 : 0,
		'event_title'    => (string) ( $options['event_name'] ?? get_bloginfo( 'name' ) ),
		'source'         => SOURCE,
		'source_ref'     => (string) $attendee->ID,
		'self_service'   => 'attendee',
		'data'           => array( 'location' => event_location() ),
	);

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
 * Handles the certificate form (issue or correct), then redirects back.
 *
 * @param \WP_Post $attendee Attendee.
 */
function handle_post( $attendee ) {
	if ( ! isset( $_POST['evcert_certificate_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evcert_certificate_nonce'] ) ), 'evcert_certificate_' . $attendee->ID ) ) {
		return __( 'The form expired. Please try again.', 'events-certificate' );
	}
	$name    = isset( $_POST['evcert_name'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_name'] ) ) : '';
	$current = Certificates\find_by_source( SOURCE, $attendee->ID );

	if ( $current ) {
		if ( $name === $current['name'] ) {
			return '';
		}
		$result = Certificates\reissue( $current, array( 'name' => $name ) );
		$done   = 'corrected';
	} else {
		$result = Certificates\issue( array_merge( attendee_data( $attendee ), array( 'name' => $name ) ) );
		$done   = 'issued';
	}
	if ( is_wp_error( $result ) ) {
		return $result->get_error_message();
	}

	wp_safe_redirect( add_query_arg( 'evcert_done', $done, certificate_link( $attendee->ID ) ) );
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
				esc_html__( 'Your certificate is available.', 'events-certificate' ),
				esc_url( certificate_link( $attendee->ID ) ),
				Certificates\find_by_source( SOURCE, $attendee->ID ) ? esc_html__( 'View certificate', 'events-certificate' ) : esc_html__( 'Get your certificate', 'events-certificate' )
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
		return '<div id="tix"><div id="tix-errors"><div class="tix-error">' . esc_html__( 'This link isn’t valid. Use the link from your ticket e-mail.', 'events-certificate' ) . '</div></div></div>';
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
	$current = Certificates\find_by_source( SOURCE, $attendee->ID );
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
			<div id="tix-notices"><div class="tix-notice"><?php esc_html_e( 'Your certificate was issued.', 'events-certificate' ); ?></div></div>
		<?php elseif ( 'corrected' === $done ) : ?>
			<div id="tix-notices"><div class="tix-notice"><?php esc_html_e( 'Your certificate was corrected. The previous code now says it was replaced.', 'events-certificate' ); ?></div></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Certificate', 'events-certificate' ); ?></h2>

		<?php if ( $reason && ! $current ) : ?>
			<div id="tix-infos"><div class="tix-info"><?php echo esc_html( $reason ); ?></div></div>
		<?php else : ?>
			<?php if ( $current ) : ?>
				<table class="tix_tickets_table tix-attendee-form evcert-certificate-summary">
					<tbody>
						<tr><th colspan="2"><?php echo esc_html( $current['event_title'] ); ?></th></tr>
						<tr><td class="tix-left"><?php esc_html_e( 'Name', 'events-certificate' ); ?></td><td class="tix-right"><strong><?php echo esc_html( $current['name'] ); ?></strong></td></tr>
						<tr><td class="tix-left"><?php esc_html_e( 'Code', 'events-certificate' ); ?></td><td class="tix-right"><code><?php echo esc_html( $current['code'] ); ?></code></td></tr>
						<tr><td class="tix-left"><?php esc_html_e( 'Issued on', 'events-certificate' ); ?></td><td class="tix-right"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $current['issued_at'] . ' UTC' ) ) ); ?></td></tr>
						<tr><td class="tix-left"><?php esc_html_e( 'Address', 'events-certificate' ); ?></td><td class="tix-right"><a href="<?php echo esc_url( own_certificate_link( $attendee, $current['code'] ) ); ?>"><?php echo esc_html( Certificates\url( $current['code'] ) ); ?></a></td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! $reason ) : ?>
				<form action="<?php echo esc_url( certificate_link( $attendee->ID ) ); ?>" method="POST">
					<input type="hidden" name="evcert_certificate" value="1" />
					<?php wp_nonce_field( 'evcert_certificate_' . $attendee->ID, 'evcert_certificate_nonce' ); ?>
					<table class="tix_tickets_table tix-attendee-form">
						<tbody>
							<tr><th colspan="2"><?php echo esc_html( $current ? __( 'Correct the name', 'events-certificate' ) : trim( $data['event_title'] . ( $ticket ? ' — ' . $ticket : '' ) ) ); ?></th></tr>
							<tr>
								<td class="tix-required tix-left">
									<label for="evcert_name">
										<?php esc_html_e( 'Name on the certificate', 'events-certificate' ); ?>
										<span aria-hidden="true" class="tix-required-star">*</span>
									</label>
								</td>
								<td class="tix-right">
									<input id="evcert_name" name="evcert_name" type="text" required value="<?php echo esc_attr( $name ); ?>" />
									<p class="tix-description"><?php esc_html_e( 'As it should appear: check accents and capital letters.', 'events-certificate' ); ?></p>
								</td>
							</tr>
							<?php if ( ! $current ) : ?>
								<tr><td class="tix-left"><?php esc_html_e( 'E-mail', 'events-certificate' ); ?></td><td class="tix-right"><?php echo esc_html( $data['email'] ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
					<p>
						<input type="submit" value="<?php echo esc_attr( $current ? __( 'Correct and reissue', 'events-certificate' ) : __( 'Issue certificate', 'events-certificate' ) ); ?>" style="float: right; cursor: pointer;" />
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
	if ( '' !== unavailable_reason( $attendee ) ) {
		return $html;
	}

	return $html . sprintf( '<br /><a href="%s">%s</a>', esc_url( certificate_link( $attendee->ID ) ), esc_html__( 'Certificate', 'events-certificate' ) );
}
