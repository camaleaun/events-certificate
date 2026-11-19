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
		// A foreigner gives a passport.
		'passport' => Documents\foreigner_allowed() && ( ! empty( $data['foreigner'] ) || ( $current && Documents\PASSPORT === (string) ( $current['doc_type'] ?? '' ) ) ),
	);
	// Only the countries the site accepts; none: no document is asked.
	$accepted = Documents\enabled();
	if ( $accepted && ! isset( $accepted[ $chosen['country'] ] ) ) {
		$chosen['country'] = (string) key( $accepted );
	}
	$script = \EventsCertificate\Assets\src( 'certificate-step', 'js' );
	wp_enqueue_script( 'evcert-certificate-step', $script['url'], array(), $script['version'], true );
	wp_register_style( 'evcert-certificate-step', false, array(), \EventsCertificate\VERSION );
	wp_enqueue_style( 'evcert-certificate-step' );
	wp_add_inline_style( 'evcert-certificate-step', '.evcert-block{display:block}' );

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
					<label for="evcert-table-<?php echo esc_attr( $day['id'] ); ?>"><?php esc_html_e( 'Your table', 'events-certificates' ); ?> <span aria-hidden="true" class="tix-required-star">*</span></label>
					<?php if ( $span ) : ?>
						<span class="tix-description"><?php echo esc_html( time_of( $span[0] ) . '–' . time_of( $span[1] ) ); ?></span>
					<?php endif; ?>
					<select id="evcert-table-<?php echo esc_attr( $day['id'] ); ?>" name="evcert_table[<?php echo esc_attr( $day['id'] ); ?>]">
						<option value=""><?php esc_html_e( 'Choose…', 'events-certificates' ); ?></option>
						<?php foreach ( Days\tables( $day ) as $table ) : ?>
							<option value="<?php echo esc_attr( $table ); ?>" <?php selected( $chosen['table'][ $day['id'] ] ?? '', $table ); ?>><?php echo esc_html( $table ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<fieldset class="evcert-talks">
						<legend><?php esc_html_e( 'Talks you attended', 'events-certificates' ); ?> <span aria-hidden="true" class="tix-required-star">*</span></legend>
						<?php foreach ( Sessions\talks( $day ) as $talk ) : ?>
							<label class="evcert-block">
								<input type="checkbox" name="evcert_sessions[<?php echo esc_attr( $day['id'] ); ?>][]" value="<?php echo esc_attr( (string) $talk['id'] ); ?>" data-start="<?php echo esc_attr( (string) $talk['start'] ); ?>" data-end="<?php echo esc_attr( (string) $talk['end'] ); ?>" <?php checked( in_array( $talk['id'], $chosen['sessions'], true ) ); ?> />
								<?php echo esc_html( time_of( $talk['start'] ) . '–' . time_of( $talk['end'] ) . ' · ' . $talk['title'] ); ?>
								<?php if ( $talk['speakers'] ) : ?>
									<span class="tix-description"> — <?php echo esc_html( implode( ', ', $talk['speakers'] ) ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="tix-description"><?php esc_html_e( 'Talks at the same time can’t be chosen together. Your workload is the sum of the talks you choose.', 'events-certificates' ); ?></p>
					</fieldset>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
	// The second page (the declaration, with an optional document) is a Brazilian custom: off unless the site turns it on.
	if ( ! Documents\declaration_enabled() ) {
		return (string) ob_get_clean();
	}
	?>
	<tr>
		<td class="tix-left"><?php esc_html_e( 'Second page', 'events-certificates' ); ?></td>
		<td class="tix-right">
			<label><input type="checkbox" name="evcert_page2" value="1" <?php checked( $chosen['page2'] ); ?> data-evcert-page2 /> <?php esc_html_e( 'Add a declaration page with my identification document and the activities', 'events-certificates' ); ?></label>
		</td>
	</tr>
	<?php if ( $accepted ) : ?>
		<?php $now = $accepted[ $chosen['country'] ]; ?>
		<tr data-evcert-page2-body>
			<td class="tix-left"><label for="evcert-doc" data-evcert-doc-label><?php echo esc_html( $chosen['passport'] ? Documents\label( Documents\PASSPORT ) . ' ' . __( '(optional)', 'events-certificates' ) : $now['label'] ); ?></label></td>
			<td class="tix-right">
				<input type="hidden" name="evcert_country" value="<?php echo esc_attr( $chosen['country'] ); ?>" data-evcert-country data-label="<?php echo esc_attr( $now['label'] ); ?>" data-mask="<?php echo esc_attr( $now['mask'] ); ?>" data-example="<?php echo esc_attr( $now['example'] ); ?>">
				<input id="evcert-doc" name="evcert_doc" type="text" autocomplete="off" inputmode="<?php echo esc_attr( $now['mask'] && ! $chosen['passport'] ? 'numeric' : 'text' ); ?>" value="" placeholder="<?php echo esc_attr( $current && ! empty( $current['doc_masked'] ) ? $current['doc_masked'] : ( $chosen['passport'] ? '' : $now['example'] ) ); ?>" data-masked="<?php echo esc_attr( $current && ! empty( $current['doc_masked'] ) ? $current['doc_masked'] : '' ); ?>" data-evcert-doc />
				<?php if ( Documents\foreigner_allowed() ) : ?>
					<label class="tix-description"><input type="checkbox" name="evcert_foreigner" value="1" <?php checked( $chosen['passport'] ); ?> data-evcert-foreigner data-label="<?php echo esc_attr( Documents\label( Documents\PASSPORT ) . ' ' . __( '(optional)', 'events-certificates' ) ); ?>" /> <?php esc_html_e( 'Not from the event’s country', 'events-certificates' ); ?></label>
					<p class="tix-description"><?php esc_html_e( 'Give your passport, or leave it empty.', 'events-certificates' ); ?></p>
				<?php endif; ?>
				<p class="tix-description"><?php esc_html_e( 'Stored so it can’t be read back: the certificate shows only part of it, and anyone holding the document can confirm it matches.', 'events-certificates' ); ?></p>
				<label class="tix-description"><input type="checkbox" name="evcert_doc_full" value="1" /> <?php esc_html_e( 'Download now the PDF with the full number. I understand showing the full number is my responsibility.', 'events-certificates' ); ?></label>
			</td>
		</tr>
	<?php endif; ?>
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
	$page2     = ! empty( $_POST['evcert_page2'] ) && Documents\declaration_enabled();
	$country   = isset( $_POST['evcert_country'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['evcert_country'] ) ) ) : '';
	$number    = isset( $_POST['evcert_doc'] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_doc'] ) ) : '';
	$want_full = ! empty( $_POST['evcert_doc_full'] );
	$foreigner = ! empty( $_POST['evcert_foreigner'] ) && Documents\foreigner_allowed();
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
				return new WP_Error( 'evcert_table', sprintf( __( 'Choose your table on %s.', 'events-certificates' ), Days\name( $day ) ) );
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
		return new WP_Error( 'evcert_days', __( 'Choose at least one day.', 'events-certificates' ) );
	}

	$columns = array();
	$full    = '';
	if ( $page2 && Documents\enabled() ) {
		$accepted        = Documents\enabled();
		$country         = (string) key( $accepted );
		$data['country'] = $country;
		if ( $foreigner ) {
			// Not from the country: a passport, or nothing (kept that they said so).
			$data['foreigner'] = true;
			if ( '' !== $number ) {
				if ( ! Documents\valid( Documents\PASSPORT, $number ) ) {
					/* translators: %s: document type, e.g. CPF. */
					return new WP_Error( 'evcert_doc', sprintf( __( 'This %s isn’t valid. Check the number.', 'events-certificates' ), Documents\label( Documents\PASSPORT ) ) );
				}
				$columns = Documents\columns( Documents\PASSPORT, $number );
				$full    = $want_full ? Documents\format( Documents\PASSPORT, $number ) : '';
			}
		} else {
			$type = (string) key( Documents\types( $country ) );
			if ( '' !== $number ) {
				if ( ! Documents\valid( $type, $number ) ) {
					/* translators: %s: document type, e.g. CPF. */
					return new WP_Error( 'evcert_doc', sprintf( __( 'This %s isn’t valid. Check the number.', 'events-certificates' ), Documents\label( $type ) ) );
				}
				$columns = Documents\columns( $type, $number );
				$full    = $want_full ? Documents\format( $type, $number ) : '';
			} elseif ( ! $current || empty( $current['doc_hash'] ) ) {
				/* translators: %s: document type, e.g. CPF. */
				return new WP_Error( 'evcert_doc', sprintf( __( 'Type your %s for the declaration page.', 'events-certificates' ), Documents\label( $type ) ) );
			}
		}
	}

	return array(
		'data'    => $data,
		'hours'   => round( $minutes / 60, 2 ),
		'columns' => $columns,
		'full'    => $full,
	);
}
