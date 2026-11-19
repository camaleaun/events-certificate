<?php
/**
 * Abilities (the WordPress Abilities API): events, participants and
 * certificates for AI agents and scripts — at /wp-abilities/v1 and, with the
 * MCP adapter, as tools. Thin adapters over the same code the screens use
 * (Events, People, Certificates, Generator\run(), the GatherPress import), with
 * the same capability.
 *
 * A typical run: get-overview → create-event (or quick-issue) →
 * add-participants → set-participant-status → issue-certificates →
 * get-certificates (each with its verification and PDF addresses).
 *
 * Errors use codes an agent can act on: evcert_missing_<field> and
 * evcert_invalid_<field> (fix the input and retry), evcert_not_initialized
 * (tell a person). docs/decisions/0016.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Abilities;

use EventsCertificate\Admin;
use EventsCertificate\Certificates;
use EventsCertificate\Events;
use EventsCertificate\People;
use EventsCertificate\Render;
use EventsCertificate\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Category.
 */
const CATEGORY = 'events-certificates';

/**
 * The format of event times in input and output.
 */
const TIME_PATTERN = '^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$';

/**
 * The format of a certificate code.
 */
const CODE_PATTERN = '^[0-9A-Za-z]{4}-?[0-9A-Za-z]{4}-?[0-9A-Za-z]{4}-?[0-9A-Za-z]{4}$';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_category' );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );
}

/**
 * Registers the category.
 */
function register_category() {
	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => __( 'Events Certificates', 'events-certificates' ),
			'description' => __( 'Attendance certificates anyone can verify by code: events, their participants (registered, attended, no-show) and the certificates of who attended.', 'events-certificates' ),
		)
	);
}

/**
 * Whether the current user may manage certificates (the screens' capability).
 *
 * @return bool
 */
function can_manage() {
	return current_user_can( Admin\CAPABILITY );
}

/**
 * The meta of an ability: its annotations, shown in REST and as an MCP tool.
 *
 * @param bool $is_readonly Changes nothing.
 * @param bool $destructive May delete or revoke.
 * @param bool $idempotent  Repeating it with the same input changes nothing more.
 * @return array<string, mixed>
 */
function meta( $is_readonly, $destructive, $idempotent ) {
	return array(
		'annotations'  => array(
			'readonly'    => $is_readonly,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		),
		'show_in_rest' => true,
		'mcp'          => array(
			'public' => true,
			'type'   => 'tool',
		),
	);
}

/**
 * An input with no fields (PHP sends [] where JSON Schema needs {}).
 *
 * @return array<string, mixed>
 */
function no_input() {
	return array(
		'type'                 => 'object',
		'default'              => (object) array(),
		'additionalProperties' => false,
	);
}

/**
 * Paging fields of list inputs.
 *
 * @param int $size Default page size.
 * @return array<string, array<string, mixed>>
 */
function paging_input( $size = 50 ) {
	return array(
		'per_page' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => 500,
			'default'     => $size,
			'description' => __( 'How many per page.', 'events-certificates' ),
		),
		'page'     => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'default'     => 1,
			'description' => __( 'Page number, from 1.', 'events-certificates' ),
		),
	);
}

/**
 * Paging fields of list outputs.
 *
 * @return array<string, array<string, string>>
 */
function paging_output() {
	return array(
		'total'       => array( 'type' => 'integer' ),
		'page'        => array( 'type' => 'integer' ),
		'per_page'    => array( 'type' => 'integer' ),
		'total_pages' => array( 'type' => 'integer' ),
	);
}

/**
 * The paging of a result.
 *
 * @param int $total    Total.
 * @param int $page     Page.
 * @param int $per_page Per page.
 * @return array<string, int>
 */
function paging( $total, $page, $per_page ) {
	return array(
		'total'       => (int) $total,
		'page'        => (int) $page,
		'per_page'    => (int) $per_page,
		'total_pages' => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
	);
}

/**
 * Input as an array with the schema's defaults (the Abilities API doesn't
 * fill them in).
 *
 * @param mixed                $input    Input.
 * @param array<string, mixed> $defaults Defaults.
 * @return array<string, mixed>
 */
function input( $input, array $defaults = array() ) {
	$input = is_array( $input ) ? $input : ( is_object( $input ) ? (array) $input : array() );
	foreach ( $defaults as $key => $value ) {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			$input[ $key ] = $value;
		}
	}

	return $input;
}

/**
 * The event fields of an input (create-event, update-event, quick-issue).
 *
 * @return array<string, array<string, mixed>>
 */
function event_input() {
	return array(
		'title'         => array(
			'type'        => 'string',
			'minLength'   => 1,
			'description' => __( 'The event\'s name, as certificates show it, e.g. "WordPress Meetup: Blocks in practice".', 'events-certificates' ),
		),
		'start'         => array(
			'type'        => 'string',
			'pattern'     => TIME_PATTERN,
			'description' => __( 'When it starts, "YYYY-MM-DD HH:MM" in the site\'s time zone (see get-overview).', 'events-certificates' ),
		),
		'end'           => array(
			'type'        => 'string',
			'pattern'     => TIME_PATTERN,
			'description' => __( 'When it ends, "YYYY-MM-DD HH:MM" in the site\'s time zone. Defaults to the start.', 'events-certificates' ),
		),
		'location'      => array(
			'type'        => 'string',
			'description' => __( 'Where it happens, e.g. "Recife, PE".', 'events-certificates' ),
		),
		'community'     => array(
			'type'        => 'string',
			'description' => __( 'The community or group that holds it, e.g. "WordPress Curitiba".', 'events-certificates' ),
		),
		'venue'         => array(
			'type'        => 'string',
			'description' => __( 'The venue, e.g. "Community Hall". With the city it makes the place the certificates show.', 'events-certificates' ),
		),
		'city'          => array(
			'type'        => 'string',
			'description' => __( 'The city, e.g. "Curitiba, PR".', 'events-certificates' ),
		),
		'speakers'      => array(
			'type'        => 'string',
			'description' => __( 'The speakers, as they go on the certificates (optional).', 'events-certificates' ),
		),
		'show_hours'    => array(
			'type'        => 'boolean',
			'default'     => true,
			'description' => __( 'Show the workload (from start to end) on the certificates.', 'events-certificates' ),
		),
		'link'          => array(
			'type'        => 'string',
			'format'      => 'uri',
			'description' => __( 'The event\'s page (Meetup, GatherPress…).', 'events-certificates' ),
		),
		'hours'         => array(
			'type'        => 'number',
			'minimum'     => 0,
			'maximum'     => 999,
			'description' => __( 'Workload in hours on the certificates. Omit to count from start to end.', 'events-certificates' ),
		),
		'hours_by_role' => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 999,
			),
			'description'          => __( 'Workload in hours by role (attendee, speaker, organizer, volunteer, sponsor): an organizer\'s 40, a volunteer\'s 8. A role left out uses the event\'s hours.', 'events-certificates' ),
		),
		'signer_name'   => array(
			'type'        => 'string',
			'description' => __( 'Who signs this event\'s certificates. Omit to use the site\'s signer (get-settings).', 'events-certificates' ),
		),
		'signer_role'   => array(
			'type'        => 'string',
			'description' => __( 'The signer\'s role, e.g. "Meetup organizer".', 'events-certificates' ),
		),
	);
}

/**
 * The output schema of an event.
 *
 * @return array<string, mixed>
 */
function event_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'                   => array( 'type' => 'integer' ),
			'title'                => array( 'type' => 'string' ),
			'start'                => array(
				'type'        => 'string',
				'description' => 'YYYY-MM-DD HH:MM, site time zone',
			),
			'end'                  => array( 'type' => 'string' ),
			'location'             => array( 'type' => 'string' ),
			'community'            => array( 'type' => 'string' ),
			'venue'                => array( 'type' => 'string' ),
			'city'                 => array( 'type' => 'string' ),
			'speakers'             => array( 'type' => 'string' ),
			'show_hours'           => array( 'type' => 'boolean' ),
			'link'                 => array( 'type' => 'string' ),
			'hours'                => array( 'type' => array( 'number', 'null' ) ),
			'hours_by_role'        => array( 'type' => 'object' ),
			'signer_name'          => array( 'type' => 'string' ),
			'signer_role'          => array( 'type' => 'string' ),
			'source'               => array(
				'type' => 'string',
				'enum' => array( 'manual', 'gatherpress' ),
			),
			'gatherpress_event_id' => array( 'type' => 'integer' ),
			'participants'         => array(
				'type'       => 'object',
				'properties' => array(
					'all'        => array( 'type' => 'integer' ),
					'registered' => array( 'type' => 'integer' ),
					'attended'   => array( 'type' => 'integer' ),
					'no_show'    => array( 'type' => 'integer' ),
					'certified'  => array( 'type' => 'integer' ),
				),
			),
			'admin_url'            => array( 'type' => 'string' ),
		),
	);
}

/**
 * An event for output.
 *
 * @param array<string, string> $event Event.
 * @return array<string, mixed>
 */
function event_out( array $event ) {
	$counts = People\counts( (int) $event['id'] );

	return array(
		'id'                   => (int) $event['id'],
		'title'                => $event['title'],
		'start'                => $event['start'],
		'end'                  => $event['end'],
		'location'             => $event['location'],
		'community'            => $event['community'],
		'venue'                => $event['venue'],
		'city'                 => $event['city'],
		'speakers'             => $event['speakers'],
		'show_hours'           => '0' !== $event['show_hours'],
		'link'                 => $event['link'],
		'hours'                => Events\hours( $event ),
		'hours_by_role'        => (object) array_map( 'floatval', $event['hours_by_role'] ),
		'signer_name'          => $event['signer_name'],
		'signer_role'          => $event['signer_role'],
		'source'               => 'gatherpress' === $event['source'] ? 'gatherpress' : 'manual',
		'gatherpress_event_id' => 'gatherpress' === $event['source'] ? (int) $event['source_ref'] : 0,
		'participants'         => array_map( 'intval', array_diff_key( $counts, array( 'roles' => true ) ) ),
		'admin_url'            => \EventsCertificate\EventsAdmin\participants_url( (int) $event['id'] ),
	);
}

/**
 * The output schema of a certificate.
 *
 * @return array<string, mixed>
 */
function certificate_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'             => array( 'type' => 'integer' ),
			'code'           => array( 'type' => 'string' ),
			'status'         => array(
				'type' => 'string',
				'enum' => array( 'issued', 'replaced', 'revoked' ),
			),
			'name'           => array( 'type' => 'string' ),
			'email'          => array( 'type' => 'string' ),
			'role'           => array( 'type' => 'string' ),
			'event'          => array( 'type' => 'string' ),
			'event_id'       => array(
				'type'        => 'integer',
				'description' => '0 when not issued from an event of this plugin (CampTix)',
			),
			'location'       => array( 'type' => 'string' ),
			'hours'          => array( 'type' => array( 'number', 'null' ) ),
			'issued_at'      => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'revoked_reason' => array( 'type' => 'string' ),
			'url'            => array(
				'type'        => 'string',
				'description' => 'The public verification page',
			),
			'pdf_url'        => array( 'type' => 'string' ),
			'verifiable'     => array(
				'type'        => 'boolean',
				'description' => 'False on sites others can\'t open (localhost, Playground): no QR code or code is printed',
			),
		),
	);
}

/**
 * A certificate for output.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return array<string, mixed>
 */
function certificate_out( array $cert ) {
	$values = Render\values( $cert );
	$url    = Certificates\url( (string) $cert['code'] );

	return array(
		'id'             => (int) $cert['id'],
		'code'           => (string) $cert['code'],
		'status'         => (string) $cert['status'],
		'name'           => (string) $cert['name'],
		'email'          => (string) $cert['email'],
		'role'           => (string) $values['role'],
		'event'          => (string) $values['event'],
		'event_id'       => Events\CONTEXT === ( $cert['context'] ?? '' ) ? (int) $cert['event_id'] : 0,
		'location'       => (string) $values['location'],
		'hours'          => isset( $cert['hours'] ) && '' !== (string) $cert['hours'] ? (float) $cert['hours'] : null,
		'issued_at'      => gmdate( 'c', (int) strtotime( (string) $cert['issued_at'] . ' UTC' ) ),
		'revoked_reason' => (string) $cert['revoked_reason'],
		'url'            => $url,
		'pdf_url'        => \EventsCertificate\Verify\pdf_url( $cert ),
		'verifiable'     => Render\verifiable(),
	);
}

/**
 * The output schema of a participant.
 *
 * @return array<string, mixed>
 */
function participant_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'     => array( 'type' => 'integer' ),
			'name'   => array( 'type' => 'string' ),
			'email'  => array( 'type' => 'string' ),
			'role'   => array(
				'type'        => 'string',
				'description' => 'The primary (first) role; see roles for all of them',
			),
			'roles'  => array(
				'type'        => 'array',
				'description' => 'Every role the person has, each with the certificate of that role (null until issued)',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'role'        => array( 'type' => 'string' ),
						'certificate' => array( 'type' => array( 'object', 'null' ) ) + array_diff_key( certificate_schema(), array( 'type' => true ) ),
					),
				),
			),
			'status' => array(
				'type' => 'string',
				'enum' => People\STATUSES,
			),
			'source' => array(
				'type' => 'string',
				'enum' => People\ORIGINS,
			),
		),
	);
}

/**
 * A participant for output.
 *
 * @param array<string, mixed> $person Participant.
 * @return array<string, mixed>
 */
function participant_out( array $person ) {
	$roles = array();
	foreach ( array_keys( $person['roles'] ) as $role ) {
		$cert    = People\certificate( $person, $role );
		$roles[] = array(
			'role'        => (string) $role,
			'certificate' => $cert ? certificate_out( $cert ) : null,
		);
	}

	return array(
		'id'     => (int) $person['id'],
		'name'   => (string) $person['name'],
		'email'  => (string) $person['email'],
		'role'   => (string) $person['role'],
		'roles'  => $roles,
		'status' => (string) $person['status'],
		'source' => (string) $person['source'],
	);
}

/**
 * The participants of an input: a list of objects and/or CSV text.
 *
 * @param array<string, mixed> $input Input.
 * @return array{people: array<int, array<string, mixed>>, rsvp_no: int, absent: int}|WP_Error
 */
function people_of( array $input ) {
	$people = array();
	foreach ( (array) $input['participants'] as $person ) {
		$person = (array) $person;
		// One role, several in a text ("speaker / organizer") or a list: People\clean() reads them (none: an attendee, or no change for someone already on the list).
		if ( isset( $person['roles'] ) || isset( $person['role'] ) ) {
			$person['roles'] = $person['roles'] ?? $person['role'];
		}
		$people[] = array_intersect_key( $person, array_flip( array( 'name', 'email', 'roles', 'status', 'wporg_username' ) ) );
	}
	$notes = array(
		'rsvp_no' => 0,
		'absent'  => 0,
	);
	if ( '' !== trim( (string) $input['csv'] ) ) {
		$rows = People\parse_csv_text( (string) $input['csv'] );
		if ( is_wp_error( $rows ) ) {
			return rest_error( new WP_Error( 'evcert_invalid_csv', $rows->get_error_message() ) );
		}
		$read = People\parse_rows( $rows );
		foreach ( $read['people'] as $person ) {
			$people[] = $person + array( 'source' => 'csv' );
		}
		$notes = array(
			'rsvp_no' => $read['rsvp_no'],
			'absent'  => $read['absent'],
		);
	}

	return array( 'people' => $people ) + $notes;
}

/**
 * The participants fields of an input.
 *
 * @return array<string, array<string, mixed>>
 */
function people_input() {
	return array(
		'participants' => array(
			'type'        => 'array',
			'default'     => array(),
			'description' => __( 'People to add. The same person (same e-mail, or same name) is updated, not added twice.', 'events-certificates' ),
			'items'       => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'additionalProperties' => false,
				'properties'           => array(
					'name'           => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The name exactly as it goes on the certificate.', 'events-certificates' ),
					),
					'email'          => array(
						'type'   => 'string',
						'format' => 'email',
					),
					'role'           => array(
						'type'        => 'string',
						'description' => __( 'attendee, speaker, organizer, volunteer or sponsor (or their translated names); several with / or +. Templates are chosen by role.', 'events-certificates' ),
					),
					'roles'          => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Several roles of the same person, each with its own certificate: a speaker who is also an organizer. A person already on the list gains them, never loses one.', 'events-certificates' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => People\STATUSES,
						'description' => __( 'registered (default), attended or no_show. Only attended ones get certificates.', 'events-certificates' ),
					),
					'wporg_username' => array(
						'type'        => 'string',
						'description' => __( 'WordPress.org username, for the avatar.', 'events-certificates' ),
					),
				),
			),
		),
		'csv'          => array(
			'type'        => 'string',
			'default'     => '',
			'description' => __( 'Or CSV text, such as Meetup\'s "Attendee details" export: a "Name" column (and optionally "Email"); RSVP "No" rows are left out and rows marked absent come in as no_show. Up to 5 MB.', 'events-certificates' ),
		),
	);
}

/**
 * An event from an input's event_id, or an error.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, string>|WP_Error
 */
function event_of( array $input ) {
	if ( empty( $input['event_id'] ) ) {
		return rest_error( new WP_Error( 'evcert_missing_event_id', __( 'Give the "event_id": see events-certificates/get-events.', 'events-certificates' ) ) );
	}
	$event = Events\get( absint( $input['event_id'] ) );

	return $event ? $event : rest_error( new WP_Error( 'evcert_invalid_event_id', __( 'There is no such event: see events-certificates/get-events.', 'events-certificates' ) ) );
}

/**
 * An error with the HTTP status REST answers with: 404 for something that
 * doesn't exist, 400 for any other input to fix. Errors from the shared code
 * (which knows nothing of HTTP) get one here.
 *
 * @param WP_Error $error Error.
 * @return WP_Error
 */
function rest_error( WP_Error $error ) {
	$data = $error->get_error_data();
	if ( ! is_array( $data ) || empty( $data['status'] ) ) {
		$code = (string) $error->get_error_code();
		$gone = 0 === strpos( $code, 'evcert_invalid_' ) && ( '_id' === substr( $code, -3 ) || 'evcert_invalid_code' === $code );
		$error->add_data( array( 'status' => $gone ? 404 : 400 ) );
	}

	return $error;
}

/**
 * Whether an ability exists in this mode (decision 0026): events and the quick issue only without CampTix and
 * GatherPress; participants where their screens exist (not with CampTix alone); the rest always.
 *
 * @param string $name Ability name, with its namespace.
 * @return bool
 */
function exists_in_mode( $name ) {
	$short = substr( $name, strlen( 'events-certificates/' ) );
	// Without verification nothing is revoked or replaced (decision 0028).
	if ( 'revoke-certificate' === $short ) {
		return \EventsCertificate\Environment\verification_enabled();
	}
	if ( in_array( $short, array( 'create-event', 'update-event', 'quick-issue' ), true ) ) {
		return \EventsCertificate\Environment\own_events();
	}
	if ( in_array( $short, array( 'get-events', 'get-participants', 'add-participants', 'set-participant-status', 'remove-participants', 'issue-certificates', 'import-wordcamp-people' ), true ) ) {
		return \EventsCertificate\Environment\participants_screens();
	}

	return true;
}

/**
 * Registers an ability, if this mode has it.
 *
 * @param string               $name Ability name.
 * @param array<string, mixed> $args Ability arguments.
 */
function register_for_mode( $name, array $args ) {
	if ( exists_in_mode( $name ) ) {
		wp_register_ability( $name, $args );
	}
}

/**
 * Registers the abilities.
 */
function register() {
	$event_id = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'The event\'s ID (events-certificates/get-events).', 'events-certificates' ),
	);
	$ids      = array(
		'type'        => 'array',
		'items'       => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'minItems'    => 1,
		'description' => __( 'Participant IDs (events-certificates/get-participants).', 'events-certificates' ),
	);

	register_for_mode(
		'events-certificates/get-overview',
		array(
			'label'               => __( 'Get certificates overview', 'events-certificates' ),
			'description'         => __( 'Start here. Says whether certificates can be verified on this site (a public address) and where; who signs; how many certificates exist by status; the events and templates; the integrations active (GatherPress, CampTix); the site\'s time zone for event times; and the usual order of the other abilities.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => no_input(),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => __NAMESPACE__ . '\get_overview',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( true, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/get-events',
		array(
			'label'               => __( 'Get events', 'events-certificates' ),
			'description'         => __( 'Lists the events certificates are issued for, newest first, with their dates, place, signer and how many participants registered, attended, didn\'t come and got certificates.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
				'properties'           => array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Words in the event\'s name.', 'events-certificates' ),
					),
				) + paging_input( 20 ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'events' => array(
						'type'  => 'array',
						'items' => event_schema(),
					),
				) + paging_output(),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_events',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( true, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/get-participants',
		array(
			'label'               => __( 'Get participants', 'events-certificates' ),
			'description'         => __( 'Lists an event\'s participants with their status (registered, attended, no_show) and certificate, if any (code, verification and PDF addresses). Filter by status, or "certified" for those with a certificate.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id' ),
				'additionalProperties' => false,
				'properties'           => array(
					'event_id' => $event_id,
					'status'   => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), People\STATUSES, array( 'certified' ) ),
					),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Part of a name or e-mail.', 'events-certificates' ),
					),
				) + paging_input( 100 ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'participants' => array(
						'type'  => 'array',
						'items' => participant_schema(),
					),
				) + paging_output(),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_participants',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( true, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/get-certificates',
		array(
			'label'               => __( 'Get certificates', 'events-certificates' ),
			'description'         => __( 'Lists or searches certificates (by name, e-mail or code), of every event or one, with their verification page and PDF addresses. Also answers "is this code valid?": search the code and read its status.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Part of a name, e-mail or code.', 'events-certificates' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( '', 'issued', 'replaced', 'revoked' ),
						'description' => __( 'issued (valid), replaced (corrected by a newer one) or revoked.', 'events-certificates' ),
					),
					'event_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Only this event\'s certificates.', 'events-certificates' ),
					),
					'role'     => array(
						'type'        => 'string',
						'description' => __( 'Only the certificates of this role (attendee, speaker, organizer, volunteer, sponsor).', 'events-certificates' ),
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'issued_at', 'name', 'code', 'status' ),
						'default' => 'issued_at',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
				) + paging_input( 50 ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'certificates' => array(
						'type'  => 'array',
						'items' => certificate_schema(),
					),
				) + paging_output(),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_certificates',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( true, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/create-event',
		array(
			'label'               => __( 'Create event', 'events-certificates' ),
			'description'         => __( 'Adds an event to issue certificates for. Then add its participants (events-certificates/add-participants). For a list of people who all came, events-certificates/quick-issue does everything at once.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'start' ),
				'additionalProperties' => false,
				'properties'           => event_input(),
			),
			'output_schema'       => event_schema(),
			'execute_callback'    => __NAMESPACE__ . '\create_event',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, false ),
		)
	);

	register_for_mode(
		'events-certificates/update-event',
		array(
			'label'               => __( 'Update event', 'events-certificates' ),
			'description'         => __( 'Changes an event\'s name, dates, place, link, hours or signer; only the fields given change. Its certificates show the new name, place, dates and signer at once (the people\'s names and hours stay as issued).', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id' ),
				'additionalProperties' => false,
				'properties'           => array( 'event_id' => $event_id ) + event_input(),
			),
			'output_schema'       => event_schema(),
			'execute_callback'    => __NAMESPACE__ . '\update_event',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/add-participants',
		array(
			'label'               => __( 'Add participants', 'events-certificates' ),
			'description'         => __( 'Adds people to an event, as a list and/or CSV text (such as a Meetup attendee export). Someone already on the list is updated, not added again. Says how many were added, updated, left out for RSVP "No" and came in as no-shows.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id' ),
				'additionalProperties' => false,
				'properties'           => array(
					'event_id'     => $event_id,
					'all_attended' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Mark everyone given as attended (unless the CSV says absent or a person has a status).', 'events-certificates' ),
					),
				) + people_input(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'added'   => array( 'type' => 'integer' ),
					'updated' => array( 'type' => 'integer' ),
					'failed'  => array( 'type' => 'integer' ),
					'rsvp_no' => array( 'type' => 'integer' ),
					'absent'  => array( 'type' => 'integer' ),
					'event'   => event_schema(),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\add_participants',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/set-participant-status',
		array(
			'label'               => __( 'Set participant status', 'events-certificates' ),
			'description'         => __( 'Marks participants of an event as attended (check-in), no_show or registered. Certificates already issued stay valid; revoke one with events-certificates/revoke-certificate.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id', 'participant_ids', 'status' ),
				'additionalProperties' => false,
				'properties'           => array(
					'event_id'        => $event_id,
					'participant_ids' => $ids,
					'status'          => array(
						'type' => 'string',
						'enum' => People\STATUSES,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'changed' => array( 'type' => 'integer' ),
					'event'   => event_schema(),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\set_participant_status',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/remove-participants',
		array(
			'label'               => __( 'Remove participants', 'events-certificates' ),
			'description'         => __( 'Removes people from an event\'s list. Their certificates, if issued, stay valid (revoke them separately).', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id', 'participant_ids' ),
				'additionalProperties' => false,
				'properties'           => array(
					'event_id'        => $event_id,
					'participant_ids' => $ids,
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'removed' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\remove_participants',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, true, true ),
		)
	);

	register_for_mode(
		'events-certificates/issue-certificates',
		array(
			'label'               => __( 'Issue certificates', 'events-certificates' ),
			'description'         => __( 'Issues the certificates of an event\'s participants who attended: the ones given, or everyone who attended; one certificate for each role of each person (or of the roles given). Someone who already has one keeps it (same code); a revoked one is not issued again. Returns each certificate with its code, verification page and PDF address.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'event_id' ),
				'additionalProperties' => false,
				'properties'           => array(
					'event_id'        => $event_id,
					'participant_ids' => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'default'     => array(),
						'description' => __( 'Only these participants; omit for everyone who attended.', 'events-certificates' ),
					),
					'roles'           => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'default'     => array(),
						'description' => __( 'Only the certificates of these roles; omit for every role of each participant (one certificate per role).', 'events-certificates' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'issued'       => array( 'type' => 'integer' ),
					'skipped'      => array(
						'type'        => 'integer',
						'description' => 'Participants left out: they did not attend',
					),
					'revoked'      => array(
						'type'        => 'integer',
						'description' => 'Participants left out: their certificate was revoked, and a revoked one is not issued again',
					),
					'certificates' => array(
						'type'  => 'array',
						'items' => certificate_schema(),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\issue_certificates',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/revoke-certificate',
		array(
			'label'               => __( 'Revoke certificate', 'events-certificates' ),
			'description'         => __( 'Revokes a certificate: its page then says it was revoked, with the reason. It can\'t be undone; issue a new one if needed.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'code' ),
				'additionalProperties' => false,
				'properties'           => array(
					'code'   => array(
						'type'        => 'string',
						'pattern'     => CODE_PATTERN,
						'description' => __( 'The certificate code, XXXX-XXXX-XXXX-XXXX.', 'events-certificates' ),
					),
					'reason' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Shown on the certificate\'s page.', 'events-certificates' ),
					),
				),
			),
			'output_schema'       => certificate_schema(),
			'execute_callback'    => __NAMESPACE__ . '\revoke_certificate',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, true, true ),
		)
	);

	register_for_mode(
		'events-certificates/quick-issue',
		array(
			'label'               => __( 'Quick issue', 'events-certificates' ),
			'description'         => __( 'Creates an event, adds who attended (a list and/or CSV text such as a Meetup export) and issues their certificates, in one step. Everyone given counts as attended unless they have a status or the CSV marks them absent. Returns the event and the certificates with their addresses. Running it again creates another event.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'start' ),
				'additionalProperties' => false,
				'properties'           => event_input() + people_input(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'event'        => event_schema(),
					'certificates' => array(
						'type'  => 'array',
						'items' => certificate_schema(),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\quick_issue',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, false ),
		)
	);

	register_for_mode(
		'events-certificates/get-settings',
		array(
			'label'               => __( 'Get certificate settings', 'events-certificates' ),
			'description'         => __( 'The settings: who signs (name, role, whether a signature is set), the place shown when an event has none, the date certificates show as issued, and whether the site is treated as public.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => no_input(),
			'output_schema'       => settings_schema(),
			'execute_callback'    => __NAMESPACE__ . '\read_settings',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( true, false, true ),
		)
	);

	register_for_mode(
		'events-certificates/update-settings',
		array(
			'label'               => __( 'Update certificate settings', 'events-certificates' ),
			'description'         => __( 'Changes the settings given: who signs (name, role, a signature as SVG code with filled paths), the default place, the date of issue, and whether the site is treated as public. Every certificate shows the change.', 'events-certificates' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'signer_name'        => array( 'type' => 'string' ),
					'signer_role'        => array( 'type' => 'string' ),
					'signature_svg'      => array(
						'type'        => 'string',
						'description' => __( 'SVG code of the signature; only filled paths are kept.', 'events-certificates' ),
					),
					'remove_signature'   => array( 'type' => 'boolean' ),
					'location'           => array( 'type' => 'string' ),
					'issued_date'        => array(
						'type'        => 'string',
						'enum'        => array( 'issued', 'event_end' ),
						'description' => __( 'issued: the day each certificate was issued; event_end: the event\'s last day.', 'events-certificates' ),
					),
					'force_public'       => array(
						'type'        => 'boolean',
						'description' => __( 'Treat the site as public (prints QR codes and codes). Only for a staging site anyone can open.', 'events-certificates' ),
					),
					'verification'       => array(
						'type'        => 'boolean',
						'description' => __( 'Print a code and a QR code on the certificates so anyone can check them (needs a public site). Off by default.', 'events-certificates' ),
					),
					'declaration'        => array(
						'type'        => 'boolean',
						'description' => __( 'Offer attendees the declaration page (a second page with the days and talks declared and, if you ask for it, an identification document). Off by default.', 'events-certificates' ),
					),
					'document_country'   => array(
						'type'        => 'string',
						'description' => __( 'The country (ISO code, like BR) whose identification document the declaration page asks for: the CPF for Brazil. One, or empty for none (the default).', 'events-certificates' ),
					),
					'document_foreigner' => array(
						'type'        => 'boolean',
						'description' => __( 'Let attendees say they are not from that country (“Sou estrangeiro”): they give a passport instead, or nothing.', 'events-certificates' ),
					),
				),
			),
			'output_schema'       => settings_schema(),
			'execute_callback'    => __NAMESPACE__ . '\update_settings',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => meta( false, false, true ),
		)
	);

	if ( \EventsCertificate\Compat\WCPT\available() ) {
		register_for_mode(
			'events-certificates/import-wordcamp-people',
			array(
				'label'               => __( 'Import WordCamp people', 'events-certificates' ),
				'description'         => __( 'Imports into an event the speakers, organizers and volunteers this WordCamp site keeps (wc-post-types): they come in as attended, each with their role; someone with two roles is one person with both, and importing again adds roles, never removes. Then issue certificates with events-certificates/issue-certificates (one per role).', 'events-certificates' ),
				'category'            => CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'event_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'event_id' => $event_id,
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'added'   => array( 'type' => 'integer' ),
						'updated' => array( 'type' => 'integer' ),
						'event'   => event_schema(),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\import_wordcamp_people',
				'permission_callback' => __NAMESPACE__ . '\can_manage',
				'meta'                => meta( false, false, true ),
			)
		);
	}

	if ( \EventsCertificate\Environment\has_gatherpress() ) {
		register_for_mode(
			'events-certificates/import-gatherpress-event',
			array(
				'label'               => __( 'Import GatherPress event', 'events-certificates' ),
				'description'         => __( 'Imports a GatherPress event and its RSVPs (or syncs it again): "attending" ones come in as registered, checked-in ones as attended; waiting list and "not attending" stay out; nobody is demoted. Then issue certificates with events-certificates/issue-certificates. GatherPress event IDs are its gatherpress_event posts.', 'events-certificates' ),
				'category'            => CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'gatherpress_event_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'gatherpress_event_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'added'   => array( 'type' => 'integer' ),
						'updated' => array( 'type' => 'integer' ),
						'event'   => event_schema(),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\import_gatherpress_event',
				'permission_callback' => __NAMESPACE__ . '\can_manage',
				'meta'                => meta( false, false, true ),
			)
		);
	}
}

/**
 * The output schema of the settings.
 *
 * @return array<string, mixed>
 */
function settings_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'signer_name'        => array( 'type' => 'string' ),
			'signer_role'        => array( 'type' => 'string' ),
			'has_signature'      => array( 'type' => 'boolean' ),
			'location'           => array( 'type' => 'string' ),
			'issued_date'        => array(
				'type' => 'string',
				'enum' => array( 'issued', 'event_end' ),
			),
			'force_public'       => array( 'type' => 'boolean' ),
			'verification'       => array( 'type' => 'boolean' ),
			'declaration'        => array( 'type' => 'boolean' ),
			'document_country'   => array( 'type' => 'string' ),
			'document_foreigner' => array( 'type' => 'boolean' ),
		),
	);
}

/**
 * Ability: get-overview.
 *
 * @return array<string, mixed>
 */
function get_overview() {
	$counts = Admin\counts();
	$signer = \EventsCertificate\Signer\current();

	return array(
		'verifiable'      => Render\verifiable(),
		'verify_page'     => home_url( user_trailingslashit( (string) Settings\value( 'slug' ) ) ),
		'certificate_url' => Certificates\url( 'XXXX-XXXX-XXXX-XXXX' ),
		'time_zone'       => wp_timezone_string(),
		'signer'          => array(
			'name'          => $signer ? $signer['name'] : '',
			'role'          => $signer ? \EventsCertificate\Signer\role_in( $signer['roles'], get_locale() ) : '',
			'has_signature' => $signer && ! empty( $signer['signature'] ),
		),
		'certificates'    => array(
			'issued'   => (int) $counts['issued'],
			'replaced' => (int) $counts['replaced'],
			'revoked'  => (int) $counts['revoked'],
		),
		'events'          => count( Events\all( array( 'fields' => 'ids' ) ) ),
		'templates'       => count( \EventsCertificate\Designs\templates() ),
		'integrations'    => array(
			'gatherpress' => \EventsCertificate\Environment\has_gatherpress(),
			'camptix'     => \EventsCertificate\Environment\has_camptix(),
		),
		'admin_url'       => Admin\page_url(),
		'how_to'          => __( 'To certify an event: events-certificates/create-event, then add-participants (a list or a Meetup CSV), set-participant-status to "attended" for who came, and issue-certificates; or quick-issue for all at once. get-certificates lists codes with their verification and PDF addresses. When "verifiable" is false the site is not public, so certificates carry no QR code or code.', 'events-certificates' ),
	);
}

/**
 * Ability: get-events.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>
 */
function get_events( $input = null ) {
	$input    = input(
		$input,
		array(
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
		)
	);
	$per_page = max( 1, min( 500, (int) $input['per_page'] ) );
	$page     = max( 1, (int) $input['page'] );
	$filter   = '' !== trim( (string) $input['search'] ) ? array( 's' => sanitize_text_field( (string) $input['search'] ) ) : array();
	$total    = count( Events\all( array( 'fields' => 'ids' ) + $filter ) );
	$posts    = Events\all(
		array(
			'numberposts' => $per_page,
			'offset'      => $per_page * ( $page - 1 ),
		) + $filter
	);

	return array(
		'events' => array_values( array_map( __NAMESPACE__ . '\event_out', array_filter( array_map( 'EventsCertificate\Events\get', wp_list_pluck( $posts, 'ID' ) ) ) ) ),
	) + paging( $total, $page, $per_page );
}

/**
 * Ability: get-participants.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function get_participants( $input = null ) {
	$input = input(
		$input,
		array(
			'status'   => '',
			'search'   => '',
			'per_page' => 100,
			'page'     => 1,
		)
	);
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	$per_page = max( 1, min( 500, (int) $input['per_page'] ) );
	$result   = People\query(
		(int) $event['id'],
		array(
			'status'   => sanitize_key( (string) $input['status'] ),
			'search'   => sanitize_text_field( (string) $input['search'] ),
			'per_page' => $per_page,
			'page'     => max( 1, (int) $input['page'] ),
		)
	);

	return array(
		'participants' => array_map( __NAMESPACE__ . '\participant_out', $result['rows'] ),
	) + paging( $result['total'], max( 1, (int) $input['page'] ), $per_page );
}

/**
 * Ability: get-certificates.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>
 */
function get_certificates( $input = null ) {
	$input    = input(
		$input,
		array(
			'search'   => '',
			'status'   => '',
			'event_id' => 0,
			'role'     => '',
			'orderby'  => 'issued_at',
			'order'    => 'desc',
			'per_page' => 50,
			'page'     => 1,
		)
	);
	$per_page = max( 1, min( 500, (int) $input['per_page'] ) );
	$result   = Certificates\query(
		array(
			'search'   => sanitize_text_field( (string) $input['search'] ),
			'status'   => sanitize_key( (string) $input['status'] ),
			'event_id' => absint( $input['event_id'] ),
			'role'     => sanitize_key( (string) $input['role'] ),
			'orderby'  => sanitize_key( (string) $input['orderby'] ),
			'order'    => sanitize_key( (string) $input['order'] ),
			'per_page' => $per_page,
			'page'     => max( 1, (int) $input['page'] ),
		)
	);

	return array(
		'certificates' => array_map( __NAMESPACE__ . '\certificate_out', $result['rows'] ),
	) + paging( $result['total'], max( 1, (int) $input['page'] ), $per_page );
}

/**
 * The event fields given in an input.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, string>
 */
function event_fields( array $input ) {
	$fields = array();
	foreach ( array_keys( event_input() ) as $field ) {
		if ( array_key_exists( $field, $input ) && null !== $input[ $field ] ) {
			$fields[ $field ] = 'hours_by_role' === $field ? (array) $input[ $field ] : (string) $input[ $field ];
		}
	}

	return $fields;
}

/**
 * Ability: create-event.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function create_event( $input = null ) {
	$id = Events\save( event_fields( input( $input ) ) );

	return is_wp_error( $id ) ? rest_error( $id ) : event_out( Events\get( $id ) );
}

/**
 * Ability: update-event.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function update_event( $input = null ) {
	$input = input( $input );
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	$id = Events\save( event_fields( $input ) + $event, (int) $event['id'] );

	return is_wp_error( $id ) ? rest_error( $id ) : event_out( Events\get( $id ) );
}

/**
 * Ability: add-participants.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function add_participants( $input = null ) {
	$input = input(
		$input,
		array(
			'participants' => array(),
			'csv'          => '',
			'all_attended' => false,
		)
	);
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	$read = people_of( $input );
	if ( is_wp_error( $read ) ) {
		return rest_error( $read );
	}
	if ( ! $read['people'] ) {
		return rest_error( new WP_Error( 'evcert_missing_participants', __( 'Give "participants" (a list of names) or "csv".', 'events-certificates' ) ) );
	}
	$people = $read['people'];
	if ( ! empty( $input['all_attended'] ) ) {
		foreach ( $people as $i => $person ) {
			$people[ $i ]['status'] = $person['status'] ?? 'attended';
		}
	}
	$result = People\import( (int) $event['id'], $people );

	return $result + array(
		'rsvp_no' => (int) $read['rsvp_no'],
		'absent'  => (int) $read['absent'],
		'event'   => event_out( $event ),
	);
}

/**
 * Ability: set-participant-status.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function set_participant_status( $input = null ) {
	$input = input(
		$input,
		array(
			'participant_ids' => array(),
			'status'          => '',
		)
	);
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	if ( ! in_array( $input['status'], People\STATUSES, true ) ) {
		return rest_error( new WP_Error( 'evcert_invalid_status', __( 'The "status" must be registered, attended or no_show.', 'events-certificates' ) ) );
	}
	if ( ! $input['participant_ids'] ) {
		return rest_error( new WP_Error( 'evcert_missing_participant_ids', __( 'Give the "participant_ids": see events-certificates/get-participants.', 'events-certificates' ) ) );
	}

	return array(
		'changed' => People\set_status( array_map( 'absint', (array) $input['participant_ids'] ), (string) $input['status'], (int) $event['id'] ),
		'event'   => event_out( $event ),
	);
}

/**
 * Ability: remove-participants.
 *
 * @param mixed $input Input.
 * @return array<string, int>|WP_Error
 */
function remove_participants( $input = null ) {
	$input = input( $input, array( 'participant_ids' => array() ) );
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	if ( ! $input['participant_ids'] ) {
		return rest_error( new WP_Error( 'evcert_missing_participant_ids', __( 'Give the "participant_ids": see events-certificates/get-participants.', 'events-certificates' ) ) );
	}

	return array( 'removed' => People\remove( array_map( 'absint', (array) $input['participant_ids'] ), (int) $event['id'] ) );
}

/**
 * Ability: issue-certificates.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function issue_certificates( $input = null ) {
	$input = input(
		$input,
		array(
			'participant_ids' => array(),
			'roles'           => array(),
		)
	);
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	$ids    = $input['participant_ids'] ? array_map( 'absint', (array) $input['participant_ids'] ) : wp_list_pluck( People\query( (int) $event['id'], array( 'status' => 'attended' ) )['rows'], 'id' );
	$result = People\issue_many( $ids, (int) $event['id'], People\roles_from( (array) $input['roles'] ) );

	return array(
		'issued'       => (int) $result['issued'],
		'skipped'      => (int) $result['skipped'],
		'revoked'      => (int) $result['revoked'],
		'certificates' => array_map( __NAMESPACE__ . '\certificate_out', $result['certificates'] ),
	);
}

/**
 * Ability: revoke-certificate.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function revoke_certificate( $input = null ) {
	$input = input(
		$input,
		array(
			'code'   => '',
			'reason' => '',
		)
	);
	if ( '' === trim( (string) $input['code'] ) ) {
		return rest_error( new WP_Error( 'evcert_missing_code', __( 'Give the certificate "code".', 'events-certificates' ) ) );
	}
	$cert = Certificates\get_by_code( (string) $input['code'] );
	if ( ! $cert ) {
		return rest_error( new WP_Error( 'evcert_invalid_code', __( 'There is no certificate with that code: see events-certificates/get-certificates.', 'events-certificates' ) ) );
	}
	if ( ! Certificates\revoke( (int) $cert['id'], sanitize_text_field( (string) $input['reason'] ) ) ) {
		return rest_error( new WP_Error( 'evcert_not_revocable', __( 'Only a valid certificate can be revoked: this one was already replaced or revoked.', 'events-certificates' ) ) );
	}

	return certificate_out( Certificates\get( (int) $cert['id'] ) );
}

/**
 * Ability: quick-issue.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function quick_issue( $input = null ) {
	$input = input(
		$input,
		array(
			'participants' => array(),
			'csv'          => '',
		)
	);
	$read  = people_of( $input );
	if ( is_wp_error( $read ) ) {
		return rest_error( $read );
	}
	$id = \EventsCertificate\Generator\run( event_fields( $input ), $read['people'] );
	if ( is_wp_error( $id ) ) {
		return rest_error( $id );
	}

	return array(
		'event'        => event_out( Events\get( $id ) ),
		'certificates' => array_map( __NAMESPACE__ . '\certificate_out', People\certificates( $id ) ),
	);
}

/**
 * Ability: get-settings (named read_settings: core has a deprecated get_settings()).
 *
 * @return array<string, mixed>
 */
function read_settings() {
	$signature = get_option( \EventsCertificate\Signer\SIGNATURE_OPTION );

	return array(
		'signer_name'        => (string) Settings\value( 'signer_name' ),
		'signer_role'        => (string) Settings\value( 'signer_role' ),
		'has_signature'      => is_array( $signature ) && ! empty( $signature['paths'] ),
		'location'           => (string) Settings\value( 'location' ),
		'issued_date'        => (string) Settings\value( 'issued_date' ),
		'force_public'       => (bool) Settings\value( 'force_public' ),
		'verification'       => (bool) Settings\value( 'verification' ),
		'declaration'        => (bool) Settings\value( 'declaration' ),
		'document_country'   => (string) Settings\value( 'document_country' ),
		'document_foreigner' => (bool) Settings\value( 'document_foreigner' ),
	);
}

/**
 * Ability: update-settings.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function update_settings( $input = null ) {
	$input   = input( $input );
	$changes = array_intersect_key( $input, array_flip( array( 'signer_name', 'signer_role', 'location', 'issued_date', 'force_public', 'verification', 'declaration', 'document_country', 'document_foreigner' ) ) );
	if ( ! empty( $input['remove_signature'] ) ) {
		delete_option( \EventsCertificate\Signer\SIGNATURE_OPTION );
	} elseif ( '' !== trim( (string) ( $input['signature_svg'] ?? '' ) ) ) {
		$signature = \EventsCertificate\Signer\sanitize_signature( \EventsCertificate\Signer\parse_svg( (string) $input['signature_svg'] ) );
		if ( ! $signature || ! $signature['paths'] ) {
			return rest_error( new WP_Error( 'evcert_invalid_signature_svg', __( 'The signature was not saved: paste the code of an SVG file with filled paths.', 'events-certificates' ) ) );
		}
		update_option( \EventsCertificate\Signer\SIGNATURE_OPTION, $signature, false );
	}
	if ( $changes ) {
		Settings\update( $changes );
	}

	return read_settings();
}

/**
 * Ability: import-wordcamp-people.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function import_wordcamp_people( $input = null ) {
	$input = input( $input, array( 'event_id' => 0 ) );
	$event = event_of( $input );
	if ( is_wp_error( $event ) ) {
		return rest_error( $event );
	}
	$result = \EventsCertificate\Compat\WCPT\import( (int) $event['id'] );
	if ( is_wp_error( $result ) ) {
		return rest_error( $result );
	}

	return array(
		'added'   => (int) $result['added'],
		'updated' => (int) $result['updated'],
		'event'   => event_out( Events\get( (int) $event['id'] ) ),
	);
}

/**
 * Ability: import-gatherpress-event.
 *
 * @param mixed $input Input.
 * @return array<string, mixed>|WP_Error
 */
function import_gatherpress_event( $input = null ) {
	$input = input( $input, array( 'gatherpress_event_id' => 0 ) );
	if ( ! absint( $input['gatherpress_event_id'] ) ) {
		return rest_error( new WP_Error( 'evcert_missing_gatherpress_event_id', __( 'Give the "gatherpress_event_id".', 'events-certificates' ) ) );
	}
	$result = \EventsCertificate\Compat\GatherPress\import( absint( $input['gatherpress_event_id'] ) );
	if ( is_wp_error( $result ) ) {
		return rest_error( $result );
	}

	return array(
		'added'   => (int) $result['added'],
		'updated' => (int) $result['updated'],
		'event'   => event_out( Events\get( $result['event'] ) ),
	);
}
