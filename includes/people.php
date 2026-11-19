<?php
/**
 * Participants of the plugin's own events (includes/events.php): who
 * registered, who came (check-in) and who didn't, typed, pasted, read from a
 * Meetup export or synced from GatherPress; the ones who came get certificates.
 *
 * A person has one or more roles (a participant who is also a speaker and an
 * organizer has three) and gets one certificate per role: the roles and the
 * certificate of each are rows of person_roles, and a person read from here
 * carries them as 'roles' (role => certificate ID, 0 while it has none; the
 * order they were given in) and 'role' (the first, the primary one).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\People;

use EventsCertificate\Certificates;
use EventsCertificate\Events;
use EventsCertificate\Install;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Certificates issued here (certificates.source); source_ref is the person.
 */
const SOURCE = 'evcert';

/**
 * Statuses of a participant.
 */
const STATUSES = array( 'registered', 'attended', 'no_show' );

/**
 * Where a participant came from.
 */
const ORIGINS = array( 'manual', 'csv', 'gatherpress', 'wordcamp' );

/**
 * Table name.
 *
 * @return string
 */
function table() {
	return Install\tables()['people'];
}

/**
 * The table of the roles of each person.
 *
 * @return string
 */
function roles_table() {
	return Install\tables()['person_roles'];
}

/**
 * Gives people their roles: 'roles' (role => certificate ID) and 'role' (the first).
 *
 * @param array<int, array<string, mixed>> $rows People.
 * @return array<int, array<string, mixed>>
 */
function hydrate( array $rows ) {
	global $wpdb;

	if ( ! $rows ) {
		return $rows;
	}
	$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	$found = $wpdb->get_results( $wpdb->prepare( 'SELECT person_id, role, certificate_id FROM %i WHERE person_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC', roles_table(), ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; one placeholder per ID.
	$by    = array();
	foreach ( (array) $found as $row ) {
		$by[ (int) $row['person_id'] ][ (string) $row['role'] ] = (int) $row['certificate_id'];
	}
	foreach ( $rows as $i => $row ) {
		$rows[ $i ]['roles'] = $by[ (int) $row['id'] ] ?? array();
		$rows[ $i ]['role']  = (string) ( array_key_first( $rows[ $i ]['roles'] ) ?? 'attendee' );
	}

	return $rows;
}

/**
 * Whether any role of a person has a certificate.
 *
 * @param array<string, mixed> $person Person read from here.
 * @return bool
 */
function has_certificate( array $person ) {
	return (bool) array_filter( (array) ( $person['roles'] ?? array() ) );
}

/**
 * Status labels.
 *
 * @return array<string, string>
 */
function status_labels() {
	return array(
		'registered' => __( 'Registered', 'events-certificates' ),
		'attended'   => __( 'Attended', 'events-certificates' ),
		'no_show'    => __( 'No-show', 'events-certificates' ),
	);
}

/**
 * A participant.
 *
 * @param int $id ID.
 * @return array<string, mixed>|null
 */
function get( $id ) {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table(), (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

	return $row ? hydrate( array( $row ) )[0] : null;
}

/**
 * The people of any event with an e-mail (the case doesn't matter).
 *
 * @param string $email E-mail.
 * @return array<int, array<string, mixed>>
 */
function find_by_email( $email ) {
	global $wpdb;

	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE LOWER(email) = %s ORDER BY id ASC', table(), $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

	return hydrate( (array) $rows );
}

/**
 * Participants of an event.
 *
 * @param int                  $event_id Event.
 * @param array<string, mixed> $args     status, role, search, orderby (name|status|created_at), order, per_page (0 = all), page.
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function query( $event_id, array $args = array() ) {
	global $wpdb;

	$status    = in_array( $args['status'] ?? '', STATUSES, true ) ? (string) $args['status'] : '';
	$certified = 'certified' === ( $args['status'] ?? '' ) ? 1 : 0;
	$role      = sanitize_key( (string) ( $args['role'] ?? '' ) );
	$search    = trim( (string) ( $args['search'] ?? '' ) );
	$like      = '%' . $wpdb->esc_like( $search ) . '%';
	$orderby   = in_array( $args['orderby'] ?? '', array( 'name', 'status', 'created_at' ), true ) ? (string) $args['orderby'] : 'name';
	$per_page  = max( 0, (int) ( $args['per_page'] ?? 0 ) );
	$limit     = $per_page ? $per_page : PHP_INT_MAX;
	$offset    = $per_page * max( 0, (int) ( $args['page'] ?? 1 ) - 1 );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	if ( 'DESC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d AND ( %s = '' OR status = %s ) AND ( %d = 0 OR id IN ( SELECT person_id FROM %i WHERE certificate_id > 0 ) ) AND ( %s = '' OR id IN ( SELECT person_id FROM %i WHERE role = %s ) ) AND ( %s = '' OR name LIKE %s OR email LIKE %s ) ORDER BY CASE %s WHEN 'status' THEN status WHEN 'created_at' THEN created_at ELSE name END DESC, id ASC LIMIT %d OFFSET %d", table(), (int) $event_id, $status, $status, $certified, roles_table(), $role, roles_table(), $role, $search, $like, $like, $orderby, $limit, $offset ), ARRAY_A );
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d AND ( %s = '' OR status = %s ) AND ( %d = 0 OR id IN ( SELECT person_id FROM %i WHERE certificate_id > 0 ) ) AND ( %s = '' OR id IN ( SELECT person_id FROM %i WHERE role = %s ) ) AND ( %s = '' OR name LIKE %s OR email LIKE %s ) ORDER BY CASE %s WHEN 'status' THEN status WHEN 'created_at' THEN created_at ELSE name END ASC, id ASC LIMIT %d OFFSET %d", table(), (int) $event_id, $status, $status, $certified, roles_table(), $role, roles_table(), $role, $search, $like, $like, $orderby, $limit, $offset ), ARRAY_A );
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_id = %d AND ( %s = '' OR status = %s ) AND ( %d = 0 OR id IN ( SELECT person_id FROM %i WHERE certificate_id > 0 ) ) AND ( %s = '' OR id IN ( SELECT person_id FROM %i WHERE role = %s ) ) AND ( %s = '' OR name LIKE %s OR email LIKE %s )", table(), (int) $event_id, $status, $status, $certified, roles_table(), $role, roles_table(), $role, $search, $like, $like ) );
	// phpcs:enable

	return array(
		'rows'  => hydrate( (array) $rows ),
		'total' => $total,
	);
}

/**
 * How many participants an event has, by status, how many certificates they hold
 * and how many of them hold any.
 *
 * @param int $event_id Event.
 * @return array<string, mixed> registered, attended, no_show, certified (certificates), people_certified, all (int), roles (role => people).
 */
function counts( $event_id ) {
	global $wpdb;

	$counts = array_fill_keys( array_merge( STATUSES, array( 'certified', 'people_certified', 'all' ) ), 0 );
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE event_id = %d GROUP BY status', table(), (int) $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	foreach ( (array) $rows as $row ) {
		if ( isset( $counts[ $row['status'] ] ) ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$counts['all'] += (int) $row['n'];
	}
	$held                       = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS certificates, COUNT(DISTINCT r.person_id) AS people FROM %i r INNER JOIN %i p ON p.id = r.person_id WHERE p.event_id = %d AND r.certificate_id > 0', roles_table(), table(), (int) $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.
	$counts['certified']        = (int) ( $held['certificates'] ?? 0 );
	$counts['people_certified'] = (int) ( $held['people'] ?? 0 );
	$counts['roles']            = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT r.role, COUNT(*) AS n FROM %i r INNER JOIN %i p ON p.id = r.person_id WHERE p.event_id = %d GROUP BY r.role', roles_table(), table(), (int) $event_id ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.
		$counts['roles'][ (string) $row['role'] ] = (int) $row['n'];
	}

	return $counts;
}

/**
 * The valid roles in what was given: an array, or one text with several roles ("speaker / organizer",
 * "Palestrante + Voluntário", "organizer, speaker"), by key or by label, each once, in order.
 *
 * @param string|string[] $given Roles.
 * @return string[]
 */
function roles_from( $given ) {
	$parts = is_array( $given ) ? $given : preg_split( '/\s*(?:[,;\/+&]|\s(?:e|and|y)\s)\s*/iu', (string) $given );
	$roles = array();
	foreach ( (array) $parts as $part ) {
		$part = trim( (string) $part );
		$role = isset( \EventsCertificate\Designs\role_labels()[ sanitize_key( $part ) ] ) ? sanitize_key( $part ) : role_from( $part );
		if ( '' !== $role && ! in_array( $role, $roles, true ) ) {
			$roles[] = $role;
		}
	}

	return $roles;
}

/**
 * Cleans a participant's fields.
 *
 * @param array<string, mixed> $person name, email, wporg_username, role or roles, status, source, source_ref.
 * @return array<string, mixed>
 */
function clean( array $person ) {
	$name  = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $person['name'] ?? '' ) ) ) );
	$email = sanitize_email( (string) ( $person['email'] ?? '' ) );

	return array(
		'name'           => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 ),
		'email'          => is_email( $email ) ? $email : '',
		'wporg_username' => sanitize_user( (string) ( $person['wporg_username'] ?? '' ), true ),
		// The roles given, valid ones only; none given is for add() to decide (a new person is an attendee).
		'roles'          => roles_from( $person['roles'] ?? ( $person['role'] ?? array() ) ),
		'status'         => in_array( $person['status'] ?? '', STATUSES, true ) ? (string) $person['status'] : 'registered',
		'source'         => in_array( $person['source'] ?? '', ORIGINS, true ) ? (string) $person['source'] : 'manual',
		'source_ref'     => substr( sanitize_text_field( (string) ( $person['source_ref'] ?? '' ) ), 0, 100 ),
	);
}

/**
 * The participant already on the list: the same record of the same origin,
 * else the same e-mail, else the same name.
 *
 * @param int                   $event_id Event.
 * @param array<string, string> $person   Clean fields.
 * @return array<string, mixed>|null
 */
function find( $event_id, array $person ) {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	if ( '' !== $person['source_ref'] ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND source = %s AND source_ref = %s', table(), (int) $event_id, $person['source'], $person['source_ref'] ), ARRAY_A );
	}
	if ( empty( $row ) && '' !== $person['email'] ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND email = %s', table(), (int) $event_id, $person['email'] ), ARRAY_A );
	}
	if ( empty( $row ) ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND name = %s', table(), (int) $event_id, $person['name'] ), ARRAY_A );
	}
	// phpcs:enable

	return $row ? hydrate( array( $row ) )[0] : null;
}

/**
 * Adds a participant, or updates the one already on the list: the roles it is
 * given are added to the ones it has (never replaced), and its status only
 * moves on when it has no certificate yet.
 *
 * @param int                  $event_id Event.
 * @param array<string, mixed> $person   Fields.
 * @return array{id: int, added: bool}|WP_Error
 */
function add( $event_id, array $person ) {
	global $wpdb;

	if ( ! Events\get( $event_id ) ) {
		return new WP_Error( 'evcert_invalid_event_id', __( 'That event no longer exists.', 'events-certificates' ) );
	}
	$given = $person;
	$row   = clean( $person );
	if ( '' === $row['name'] ) {
		return new WP_Error( 'evcert_missing_name', __( 'The name on the certificate is required.', 'events-certificates' ) );
	}
	$now      = current_time( 'mysql', true );
	$existing = find( $event_id, $row );
	if ( $existing ) {
		$changes = array( 'updated_at' => $now );
		foreach ( array( 'email', 'wporg_username', 'source_ref' ) as $key ) {
			if ( '' !== $row[ $key ] && '' === (string) $existing[ $key ] ) {
				$changes[ $key ] = $row[ $key ];
			}
		}
		if ( isset( $given['status'] ) && ! has_certificate( $existing ) ) {
			$changes['status'] = $row['status'];
		}
		$wpdb->update( table(), $changes, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		add_roles( (int) $existing['id'], $row['roles'] );

		return array(
			'id'    => (int) $existing['id'],
			'added' => false,
		);
	}
	$roles = $row['roles'] ? $row['roles'] : array( 'attendee' );
	unset( $row['roles'] );
	$row += array(
		'event_id'   => (int) $event_id,
		'created_at' => $now,
		'updated_at' => $now,
	);
	if ( ! $wpdb->insert( table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		return new WP_Error( 'evcert_person_failed', __( 'The participant could not be saved.', 'events-certificates' ) );
	}
	$id = (int) $wpdb->insert_id;
	add_roles( $id, $roles );

	return array(
		'id'    => $id,
		'added' => true,
	);
}

/**
 * Changes a participant's name and e-mail (what a certificate issued later says; the ones already issued keep theirs:
 * correct those one by one).
 *
 * @param int    $person_id Person.
 * @param string $name      Name.
 * @param string $email     E-mail, or ''.
 * @return bool Whether it was saved (a name is required).
 */
function update_person( $person_id, $name, $email ) {
	global $wpdb;

	$row = clean(
		array(
			'name'  => $name,
			'email' => $email,
		)
	);
	if ( '' === $row['name'] || ! get( (int) $person_id ) ) {
		return false;
	}
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		table(),
		array(
			'name'       => $row['name'],
			'email'      => $row['email'],
			'updated_at' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $person_id )
	);

	return true;
}

/**
 * Gives a person roles they do not have yet (the valid ones, in order).
 *
 * @param int      $person_id Person.
 * @param string[] $roles     Roles.
 * @return int How many were added.
 */
function add_roles( $person_id, array $roles ) {
	global $wpdb;

	$added = 0;
	$now   = current_time( 'mysql', true );
	foreach ( roles_from( $roles ) as $role ) {
		$added += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i ( person_id, role, certificate_id, created_at, updated_at ) VALUES ( %d, %s, 0, %s, %s )', roles_table(), (int) $person_id, $role, $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; INSERT IGNORE keeps a role once.
	}

	return $added;
}

/**
 * Takes a role off a person: only while it has no certificate (that one is revoked, not removed), and never the last one.
 *
 * @param int    $person_id Person.
 * @param string $role      Role.
 * @return bool Whether it was taken off.
 */
function remove_role( $person_id, $role ) {
	global $wpdb;

	$person = get( (int) $person_id );
	if ( ! $person || ! isset( $person['roles'][ $role ] ) || $person['roles'][ $role ] || count( $person['roles'] ) < 2 ) {
		return false;
	}

	return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		roles_table(),
		array(
			'person_id'      => (int) $person_id,
			'role'           => $role,
			'certificate_id' => 0,
		)
	);
}

/**
 * Adds many participants.
 *
 * @param int                              $event_id Event.
 * @param array<int, array<string, mixed>> $people   Fields of each.
 * @return array{added: int, updated: int, failed: int}
 */
function import( $event_id, array $people ) {
	$result = array(
		'added'   => 0,
		'updated' => 0,
		'failed'  => 0,
	);
	foreach ( $people as $person ) {
		$done = add( $event_id, (array) $person );
		if ( is_wp_error( $done ) ) {
			++$result['failed'];
		} else {
			++$result[ $done['added'] ? 'added' : 'updated' ];
		}
	}

	return $result;
}

/**
 * Sets the status of participants of an event.
 *
 * @param int[]  $ids      Participants.
 * @param string $status   registered|attended|no_show.
 * @param int    $event_id Event they must belong to.
 * @return int How many changed.
 */
function set_status( array $ids, $status, $event_id ) {
	global $wpdb;

	if ( ! in_array( $status, STATUSES, true ) ) {
		return 0;
	}
	$changed = 0;
	foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
		$changed += (int) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'       => $id,
				'event_id' => (int) $event_id,
			)
		);
	}

	return $changed;
}

/**
 * Removes participants from an event. Certificates already issued stay
 * valid: they are records of their own (docs/decisions/0001).
 *
 * @param int[] $ids      Participants.
 * @param int   $event_id Event they must belong to.
 * @return int How many were removed.
 */
function remove( array $ids, $event_id ) {
	global $wpdb;

	$removed = 0;
	foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
		$gone     = (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			table(),
			array(
				'id'       => $id,
				'event_id' => (int) $event_id,
			)
		);
		$removed += $gone;
		if ( $gone ) {
			$wpdb->delete( roles_table(), array( 'person_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		}
	}

	return $removed;
}

/**
 * The latest certificate of a participant in a role, in any state: a correction leaves
 * the role pointing at the replaced one, so the chain of corrections is followed to the newest.
 *
 * @param array<string, mixed> $person Participant.
 * @param string               $role   Role ('' for the primary one).
 * @return array<string, mixed>|null
 */
function latest_certificate( array $person, $role = '' ) {
	$role = '' !== $role ? $role : (string) ( $person['role'] ?? '' );
	$id   = (int) ( $person['roles'][ $role ] ?? 0 );
	$cert = $id ? Certificates\get( $id ) : null;
	for ( $hops = 0; $cert && 'replaced' === $cert['status'] && ! empty( $cert['replaced_by'] ) && $hops < 20; ++$hops ) {
		$next = Certificates\get( (int) $cert['replaced_by'] );
		if ( ! $next ) {
			break;
		}
		$cert = $next;
	}

	return $cert;
}

/**
 * A participant's certificate in a role, when issued and still valid.
 *
 * @param array<string, mixed> $person Participant.
 * @param string               $role   Role ('' for the primary one).
 * @return array<string, mixed>|null
 */
function certificate( array $person, $role = '' ) {
	$cert = latest_certificate( $person, $role );

	return $cert && 'issued' === $cert['status'] ? $cert : null;
}

/**
 * Issues a participant's certificate in a role (only for who attended); the one
 * already issued when there is one.
 *
 * @param array<string, mixed> $person Participant.
 * @param string               $role   One of the person's roles ('' for the primary one).
 * @return array<string, mixed>|WP_Error Certificate.
 */
function issue( array $person, $role = '' ) {
	$role = '' !== $role ? $role : (string) ( $person['role'] ?? '' );
	if ( ! isset( $person['roles'][ $role ] ) ) {
		return new WP_Error( 'evcert_invalid_role', __( 'That person does not have that role.', 'events-certificates' ) );
	}
	$current = certificate( $person, $role );
	if ( $current ) {
		return $current;
	}
	$latest = latest_certificate( $person, $role );
	if ( $latest && 'revoked' === $latest['status'] ) {
		return new WP_Error( 'evcert_revoked', __( 'That certificate was revoked, so it is not issued again.', 'events-certificates' ) );
	}
	if ( 'attended' !== $person['status'] ) {
		return new WP_Error( 'evcert_invalid_status', __( 'Only participants who attended get a certificate.', 'events-certificates' ) );
	}
	$event = Events\get( (int) $person['event_id'] );
	if ( ! $event ) {
		return new WP_Error( 'evcert_invalid_event_id', __( 'That event no longer exists.', 'events-certificates' ) );
	}
	// CampTix and GatherPress together: the ticket page may have issued this person's certificate already.
	$twin = Certificates\find_for_person( (string) $person['email'], (string) $event['title'], SOURCE, $role );
	if ( $twin ) {
		link_certificate( $person, $role, $twin );

		return $twin;
	}
	$signer = \EventsCertificate\Signer\for_event( $event );
	$cert   = Certificates\issue(
		array(
			'context'        => Events\CONTEXT,
			'event_id'       => (int) $event['id'],
			'name'           => (string) $person['name'],
			'email'          => (string) $person['email'],
			'wporg_username' => (string) $person['wporg_username'],
			'role'           => $role,
			'attended'       => 1,
			'event_title'    => $event['title'],
			'event_start'    => Events\utc( $event['start'] ),
			'event_end'      => Events\utc( $event['end'] ),
			'event_tz'       => wp_timezone_string(),
			'hours'          => Events\hours( $event, $role ),
			'source'         => SOURCE,
			'source_ref'     => (string) $person['id'],
			'data'           => array(
				'location'     => $event['location'],
				'signer_name'  => $signer ? $signer['name'] : '',
				'signer_roles' => $signer ? $signer['roles'] : array(),
			),
		)
	);
	if ( is_wp_error( $cert ) ) {
		return $cert;
	}
	link_certificate( $person, $role, $cert );

	return $cert;
}

/**
 * Points a person's role at a certificate.
 *
 * @param array<string, mixed> $person Participant.
 * @param string               $role   Role.
 * @param array<string, mixed> $cert   Certificate.
 */
function link_certificate( array $person, $role, array $cert ) {
	global $wpdb;

	add_roles( (int) $person['id'], array( $role ) );
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		roles_table(),
		array(
			'certificate_id' => (int) $cert['id'],
			'updated_at'     => current_time( 'mysql', true ),
		),
		array(
			'person_id' => (int) $person['id'],
			'role'      => $role,
		)
	);
}

/**
 * Issues the certificates of participants of an event: one for each role of each (or of the roles asked for).
 *
 * @param int[]    $ids      Participants.
 * @param int      $event_id Event they must belong to.
 * @param string[] $roles    Only these roles (all of each person's when empty).
 * @return array{issued: int, skipped: int, revoked: int, certificates: array<int, array<string, mixed>>} issued: certificates
 *         (the valid ones already there included); skipped: people who are not of the event or did not attend; revoked:
 *         roles left out because their certificate was revoked.
 */
function issue_many( array $ids, $event_id, array $roles = array() ) {
	$result = array(
		'issued'       => 0,
		'skipped'      => 0,
		'revoked'      => 0,
		'certificates' => array(),
	);
	$only   = roles_from( $roles );
	foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
		$person = get( $id );
		if ( ! $person || (int) $person['event_id'] !== (int) $event_id ) {
			++$result['skipped'];
			continue;
		}
		$skipped = false;
		foreach ( array_keys( $person['roles'] ) as $role ) {
			if ( $only && ! in_array( $role, $only, true ) ) {
				continue;
			}
			$cert = issue( get( $id ), $role );
			if ( is_wp_error( $cert ) && 'evcert_revoked' === $cert->get_error_code() ) {
				++$result['revoked'];
			} elseif ( is_wp_error( $cert ) ) {
				$skipped = true;
			} else {
				++$result['issued'];
				$result['certificates'][] = $cert;
			}
		}
		if ( $skipped ) {
			++$result['skipped'];
		}
	}

	return $result;
}

/**
 * Certificates issued to an event's participants (valid ones), by name, a person's in the order of their roles.
 *
 * @param int   $event_id Event.
 * @param int[] $ids      Only these participants (all when empty).
 * @return array<int, array<string, mixed>>
 */
function certificates( $event_id, array $ids = array() ) {
	$certs = array();
	foreach ( query( $event_id, array( 'status' => 'certified' ) )['rows'] as $person ) {
		if ( $ids && ! in_array( (int) $person['id'], array_map( 'intval', $ids ), true ) ) {
			continue;
		}
		foreach ( array_keys( $person['roles'] ) as $role ) {
			$cert = certificate( $person, $role );
			if ( $cert ) {
				$certs[] = $cert;
			}
		}
	}

	return $certs;
}

/**
 * The role keys, by key and by label in the site's languages, to read a
 * role typed in a list ("speaker", "Palestrante").
 *
 * @param string $typed Typed role.
 * @return string Role key, or '' when unknown.
 */
function role_from( $typed ) {
	$typed = strtolower( trim( $typed ) );
	foreach ( \EventsCertificate\Designs\role_labels() as $key => $label ) {
		if ( strtolower( $key ) === $typed || ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label ) ) === ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $typed ) : $typed ) ) {
			return $key;
		}
	}

	return '';
}

/**
 * Reads pasted lines: "Name", "Name, e-mail" or "Name, e-mail, role"
 * (a tab or a semicolon also separate, as in a spreadsheet's copy). A person
 * may have several roles: "speaker / organizer", "Palestrante + Voluntário",
 * or one more cell each; a name on two lines is one person (add() joins them).
 *
 * @param string $text Lines.
 * @return array<int, array<string, mixed>>
 */
function parse_lines( $text ) {
	$people = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$cells = array_map( 'trim', preg_split( '/\t|;|,/', $line ) );
		if ( '' === ( $cells[0] ?? '' ) ) {
			continue;
		}
		$person = array( 'name' => $cells[0] );
		foreach ( array_slice( $cells, 1 ) as $cell ) {
			if ( is_email( $cell ) ) {
				$person['email'] = $cell;
			} elseif ( roles_from( $cell ) ) {
				$person['roles'] = roles_from( array_merge( $person['roles'] ?? array(), roles_from( $cell ) ) );
			}
		}
		$people[] = $person;
	}

	return $people;
}

/**
 * Reads rows of a spreadsheet export, such as Meetup's "Attendee details"
 * CSV: the column named Name/Nome; RSVP "No" left out; an attendance column
 * (Attended, Check-in, Presença…) saying no, absent or no-show marks the
 * person as a no-show, and yes as attended. Without a header row, the first
 * filled cell of each row is the name.
 *
 * @param array<int, array<int, string>> $rows Rows of cells.
 * @return array{people: array<int, array<string, mixed>>, rsvp_no: int, absent: int}
 */
function parse_rows( array $rows ) {
	$result = array(
		'people'  => array(),
		'rsvp_no' => 0,
		'absent'  => 0,
	);
	$is     = static function ( $pattern, $cell ) {
		return (bool) preg_match( $pattern, trim( (string) $cell ) );
	};
	$header = -1;
	foreach ( $rows as $i => $row ) {
		foreach ( (array) $row as $cell ) {
			if ( $is( '/^(name|nome|nome completo|full name)$/iu', $cell ) ) {
				$header = $i;
				break 2;
			}
		}
	}
	if ( $header < 0 ) {
		foreach ( $rows as $row ) {
			$filled = array_values( array_filter( array_map( 'trim', (array) $row ), 'strlen' ) );
			if ( $filled ) {
				$result['people'][] = array( 'name' => $filled[0] );
			}
		}
		return $result;
	}
	$head   = array_map( 'trim', (array) $rows[ $header ] );
	$column = static function ( $pattern ) use ( $head, $is ) {
		foreach ( $head as $i => $cell ) {
			if ( $is( $pattern, $cell ) ) {
				return $i;
			}
		}
		return -1;
	};
	$name   = $column( '/^(name|nome|nome completo|full name)$/iu' );
	$email  = $column( '/^(e-?mail|email address|endereço de e-?mail)$/iu' );
	$rsvp   = $column( '/^(rsvp|resposta|response)$/iu' );
	$attend = $column( '/(attend|presen|compareceu|check.?in)/iu' );
	$role   = $column( '/^(roles?|papel|papéis|papeis|função|funcao|cargo)$/iu' );
	$no     = '/^(no|não|nao|n|false|0|not going|absent|no.?show|ausente|faltou)$/iu';
	$yes    = '/^(yes|sim|sí|si|y|true|1|attended|present|presente|compareceu|checked.?in)$/iu';
	foreach ( array_slice( $rows, $header + 1 ) as $row ) {
		$row    = array_map( 'trim', (array) $row );
		$person = array( 'name' => (string) ( $row[ $name ] ?? '' ) );
		if ( '' === $person['name'] ) {
			continue;
		}
		if ( $rsvp >= 0 && $is( '/^(no|não|nao|n|false|0|not going)$/iu', $row[ $rsvp ] ?? '' ) ) {
			++$result['rsvp_no'];
			continue;
		}
		if ( $email >= 0 && is_email( (string) ( $row[ $email ] ?? '' ) ) ) {
			$person['email'] = (string) $row[ $email ];
		}
		if ( $role >= 0 && roles_from( (string) ( $row[ $role ] ?? '' ) ) ) {
			$person['roles'] = roles_from( (string) $row[ $role ] );
		}
		$seen = $attend >= 0 ? (string) ( $row[ $attend ] ?? '' ) : '';
		if ( '' !== $seen && $is( $no, $seen ) ) {
			$person['status'] = 'no_show';
			++$result['absent'];
		} elseif ( '' !== $seen && $is( $yes, $seen ) ) {
			$person['status'] = 'attended';
		}
		$result['people'][] = $person;
	}

	return $result;
}

/**
 * Reads a CSV file (comma, semicolon or tab separated; UTF-8 or Latin-1).
 *
 * @param string $path File.
 * @return array<int, array<int, string>>|WP_Error Rows.
 */
function read_csv( $path ) {
	$text = is_readable( $path ) && filesize( $path ) < 5 * MB_IN_BYTES ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded file, read once.
	if ( '' === $text ) {
		return new WP_Error( 'evcert_csv', __( 'The file is empty or too large (up to 5 MB).', 'events-certificates' ) );
	}

	return parse_csv_text( $text );
}

/**
 * Reads CSV text (comma, semicolon or tab separated; UTF-8 or Latin-1).
 *
 * @param string $text CSV.
 * @return array<int, array<int, string>>|WP_Error Rows.
 */
function parse_csv_text( $text ) {
	$text = (string) $text;
	if ( '' === trim( $text ) || strlen( $text ) > 5 * MB_IN_BYTES ) {
		return new WP_Error( 'evcert_csv', __( 'The file is empty or too large (up to 5 MB).', 'events-certificates' ) );
	}
	if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
		$text = substr( $text, 3 );
	} elseif ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) && function_exists( 'mb_convert_encoding' ) ) {
		$text = (string) mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' );
	}
	$first     = (string) strtok( $text, "\n" );
	$delimiter = ',';
	foreach ( array( "\t", ';' ) as $candidate ) {
		if ( substr_count( $first, $candidate ) > substr_count( $first, $delimiter ) ) {
			$delimiter = $candidate;
		}
	}
	// fgetcsv() on a memory stream: quoted cells may span lines.
	$rows   = array();
	$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a memory stream, not a file.
	if ( ! $stream ) {
		return new WP_Error( 'evcert_csv', __( 'The file could not be read.', 'events-certificates' ) );
	}
	fwrite( $stream, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a memory stream.
	rewind( $stream );
	while ( ! feof( $stream ) ) {
		$row = fgetcsv( $stream, 0, $delimiter, '"', '\\' );
		$row = is_array( $row ) ? array_map( 'strval', $row ) : array();
		if ( array_filter( $row, 'strlen' ) ) {
			$rows[] = $row;
		}
	}
	fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a memory stream.

	return $rows;
}
