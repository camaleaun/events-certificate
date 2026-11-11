<?php
/**
 * What an attendee declares on the certificate step (CampTix): the days it
 * covers (among the days they were marked present), the table they joined on
 * a tables day, the talks they attended on a talks day (never two at the same
 * time), and, optionally, a second page: a declaration with their identity
 * document. The workload is the sum of what they declared.
 *
 * The document is stored like a password (includes/documents.php, decision
 * 0006). Its full number can be printed only in the PDF downloaded right
 * then, after the person agrees that is their responsibility (decision 0014).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\CampTix\Choices;

use EventsCertificate\Compat\CampTix\Days as Presence;
use EventsCertificate\Countries;
use EventsCertificate\Days;
use EventsCertificate\Documents;
use EventsCertificate\Sessions;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The days an attendee can get a certificate for: those marked present.
 *
 * @param \WP_Post $attendee Attendee.
 * @return array<int, array<string, mixed>>
 */
function days( $attendee ) {
	$present = Presence\present( $attendee->ID );

	return array_values(
		array_filter(
			Days\all(),
			static function ( $day ) use ( $present ) {
				return in_array( $day['id'], $present, true );
			}
		)
	);
}

/**
 * A time of day in the site's format.
 *
 * @param int $ts Unix time.
 * @return string
 */
function time_of( $ts ) {
	return wp_date( (string) get_option( 'time_format' ), $ts );
}

/**
 * The form rows: days, tables, talks, the second page and its document.
 *
 * @param \WP_Post                  $attendee Attendee.
 * @param array<string, mixed>|null $current  Current certificate.
 * @return string
 */
function rows( $attendee, $current ) {
	$days = days( $attendee );
	if ( ! $days ) {
		return '';
	}
	$data   = $current ? (array) json_decode( (string) $current['data_json'], true ) : array();
	$chosen = array(
		'days'     => (array) ( $data['days'] ?? wp_list_pluck( $days, 'id' ) ),
		'table'    => (array) ( $data['table'] ?? array() ),
		'sessions' => array_map( 'intval', wp_list_pluck( (array) ( $data['sessions'] ?? array() ), 'id' ) ),
		'page2'    => ! empty( $data['page2'] ),
		'country'  => (string) ( $data['country'] ?? Countries\guess() ),
	);
	wp_enqueue_script( 'evcert-certificate-step', plugins_url( 'assets/certificate-step.js', dirname( __DIR__, 2 ) . '/events-certificate.php' ), array(), \EventsCertificate\VERSION, true );

	ob_start();
	foreach ( $days as $day ) {
		$on = in_array( $day['id'], $chosen['days'], true );
		?>
		<tr class="evcert-step-day" data-day="<?php echo esc_attr( $day['id'] ); ?>">
			<td class="tix-left">
				<label>
					<input type="checkbox" name="evcert_days[]" value="<?php echo esc_attr( $day['id'] ); ?>" <?php checked( $on ); ?> data-evcert-day-toggle />
					<?php echo esc_html( Days\short_date( $day ) . ' — ' . Days\name( $day ) ); ?>
				</label>
			</td>
			<td class="tix-right" data-evcert-day-body>
				<?php if ( 'table' === $day['mode'] ) : ?>
					<?php $span = Sessions\day_span( $day ); ?>
					<label for="evcert-table-<?php echo esc_attr( $day['id'] ); ?>"><?php esc_html_e( 'Your table', 'events-certificate' ); ?> <span aria-hidden="true" class="tix-required-star">*</span></label>
					<?php if ( $span ) : ?>
						<span class="tix-description"><?php echo esc_html( time_of( $span[0] ) . '–' . time_of( $span[1] ) ); ?></span>
					<?php endif; ?>
					<select id="evcert-table-<?php echo esc_attr( $day['id'] ); ?>" name="evcert_table[<?php echo esc_attr( $day['id'] ); ?>]">
						<option value=""><?php esc_html_e( 'Choose…', 'events-certificate' ); ?></option>
						<?php foreach ( Days\tables( $day ) as $table ) : ?>
							<option value="<?php echo esc_attr( $table ); ?>" <?php selected( $chosen['table'][ $day['id'] ] ?? '', $table ); ?>><?php echo esc_html( $table ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<fieldset class="evcert-talks">
						<legend><?php esc_html_e( 'Talks you attended', 'events-certificate' ); ?> <span aria-hidden="true" class="tix-required-star">*</span></legend>
						<?php foreach ( Sessions\talks( $day ) as $talk ) : ?>
							<label style="display:block">
								<input type="checkbox" name="evcert_sessions[<?php echo esc_attr( $day['id'] ); ?>][]" value="<?php echo esc_attr( (string) $talk['id'] ); ?>" data-start="<?php echo esc_attr( (string) $talk['start'] ); ?>" data-end="<?php echo esc_attr( (string) $talk['end'] ); ?>" <?php checked( in_array( $talk['id'], $chosen['sessions'], true ) ); ?> />
								<?php echo esc_html( time_of( $talk['start'] ) . '–' . time_of( $talk['end'] ) . ' · ' . $talk['title'] ); ?>
								<?php if ( $talk['speakers'] ) : ?>
									<span class="tix-description"> — <?php echo esc_html( implode( ', ', $talk['speakers'] ) ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="tix-description"><?php esc_html_e( 'Talks at the same time can’t be chosen together. Your workload is the sum of the talks you choose.', 'events-certificate' ); ?></p>
					</fieldset>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
	?>
	<tr>
		<td class="tix-left"><?php esc_html_e( 'Second page', 'events-certificate' ); ?></td>
		<td class="tix-right">
			<label><input type="checkbox" name="evcert_page2" value="1" <?php checked( $chosen['page2'] ); ?> data-evcert-page2 /> <?php esc_html_e( 'Add a declaration page with my identity document and the activities', 'events-certificate' ); ?></label>
		</td>
	</tr>
	<tr data-evcert-page2-body>
		<td class="tix-left"><label for="evcert-country"><?php esc_html_e( 'Country', 'events-certificate' ); ?></label></td>
		<td class="tix-right">
			<select id="evcert-country" name="evcert_country" data-evcert-country>
				<?php foreach ( Countries\all() as $code => $country ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $chosen['country'], $code ); ?>><?php echo esc_html( $country ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr data-evcert-page2-body>
		<td class="tix-left"><label for="evcert-doc" data-evcert-doc-label data-cpf="CPF" data-other="<?php esc_attr_e( 'Document', 'events-certificate' ); ?>"><?php echo esc_html( 'BR' === $chosen['country'] ? 'CPF' : __( 'Document', 'events-certificate' ) ); ?></label></td>
		<td class="tix-right">
			<input id="evcert-doc" name="evcert_doc" type="text" autocomplete="off" inputmode="<?php echo 'BR' === $chosen['country'] ? 'numeric' : 'text'; ?>" value="" placeholder="<?php echo esc_attr( $current && ! empty( $current['doc_masked'] ) ? $current['doc_masked'] : ( 'BR' === $chosen['country'] ? '000.000.000-00' : '' ) ); ?>" data-evcert-doc />
			<p class="tix-description"><?php esc_html_e( 'Stored so it can’t be read back: the certificate shows only part of it, and anyone holding the document can confirm it matches.', 'events-certificate' ); ?></p>
			<label style="display:block;margin-top:.5em"><input type="checkbox" name="evcert_doc_full" value="1" /> <?php esc_html_e( 'Download now the PDF with the full number. I understand showing the full number is my responsibility.', 'events-certificate' ); ?></label>
		</td>
	</tr>
	<?php
	return (string) ob_get_clean();
}

/**
 * Reads and checks what was declared.
 *
 * @param \WP_Post                  $attendee Attendee.
 * @param array<string, mixed>|null $current  Current certificate.
 * @return array{data: array<string, mixed>, hours: float, columns: array<string, string>, full: string}|WP_Error|null Null when there are no days to declare.
 */
function read( $attendee, $current ) {
	$days = days( $attendee );
	if ( ! $days ) {
		return null;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the caller (handle_post()).
	$picked    = isset( $_POST['evcert_days'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['evcert_days'] ) ) : array();
	$tables    = isset( $_POST['evcert_table'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['evcert_table'] ) ) : array();
	$sessions  = isset( $_POST['evcert_sessions'] ) ? (array) wp_unslash( $_POST['evcert_sessions'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int below.
	$page2     = ! empty( $_POST['evcert_page2'] );
	$country   = isset( $_POST['evcert_country'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['evcert_country'] ) ) ) : '';
	$number    = isset( $_POST['evcert_doc'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_doc'] ) ) : '';
	$want_full = ! empty( $_POST['evcert_doc_full'] );
	// phpcs:enable

	$data    = array(
		'days'     => array(),
		'table'    => array(),
		'sessions' => array(),
		'page2'    => $page2,
	);
	$minutes = 0;
	foreach ( $days as $day ) {
		if ( ! in_array( $day['id'], $picked, true ) ) {
			continue;
		}
		$data['days'][] = $day['id'];
		if ( 'table' === $day['mode'] ) {
			$table = (string) ( $tables[ $day['id'] ] ?? '' );
			if ( ! in_array( $table, Days\tables( $day ), true ) ) {
				/* translators: %s: day of the event. */
				return new WP_Error( 'evcert_table', sprintf( __( 'Choose your table on %s.', 'events-certificate' ), Days\name( $day ) ) );
			}
			$data['table'][ $day['id'] ]      = $table;
			$data['table_time'][ $day['id'] ] = Sessions\day_span( $day );
			$minutes                         += Sessions\day_minutes( $day );
		} else {
			$chosen = Sessions\pick( $day, array_map( 'intval', (array) ( $sessions[ $day['id'] ] ?? array() ) ) );
			if ( is_wp_error( $chosen ) ) {
				return $chosen;
			}
			foreach ( $chosen as $talk ) {
				$data['sessions'][] = array_merge( Sessions\frozen( $talk ), array( 'day' => $day['id'] ) );
			}
			$minutes += Sessions\minutes( $chosen );
		}
	}
	if ( ! $data['days'] ) {
		return new WP_Error( 'evcert_days', __( 'Choose at least one day.', 'events-certificate' ) );
	}

	$columns = array();
	$full    = '';
	if ( $page2 ) {
		if ( '' === $country || '' === Countries\name( $country ) ) {
			return new WP_Error( 'evcert_country', __( 'Choose your country.', 'events-certificate' ) );
		}
		$data['country'] = $country;
		$type            = (string) key( Documents\types( $country ) );
		if ( '' !== $number ) {
			if ( ! Documents\valid( $type, $number ) ) {
				/* translators: %s: document type, e.g. CPF. */
				return new WP_Error( 'evcert_doc', sprintf( __( 'This %s isn’t valid. Check the number.', 'events-certificate' ), Documents\label( $type ) ) );
			}
			$columns = Documents\columns( $type, $number );
			$full    = $want_full ? Documents\format( $type, $number ) : '';
		} elseif ( ! $current || empty( $current['doc_hash'] ) ) {
			/* translators: %s: document type, e.g. CPF. */
			return new WP_Error( 'evcert_doc', sprintf( __( 'Type your %s for the declaration page.', 'events-certificate' ), Documents\label( $type ) ) );
		}
	}

	return array(
		'data'    => $data,
		'hours'   => round( $minutes / 60, 2 ),
		'columns' => $columns,
		'full'    => $full,
	);
}
