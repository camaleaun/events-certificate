<?php
/**
 * Database tables: created and upgraded by comparing a schema version, with
 * no activation hook (activation doesn't run on network-wide installs,
 * updates or WP-CLI copies; a version check always does).
 *
 * - certificates: one row per person, event and role, from draft to issued or
 *   revoked. Data is frozen when issued: the PDF is drawn again from it on
 *   every download, so no files are kept.
 * - people: who is on an event's list; person_roles: the roles each has (a
 *   participant who is also a speaker has both) and the certificate of each.
 * - designs: immutable snapshots of the artwork a certificate was issued
 *   with, stored once per hash and shared by every certificate using it.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Schema version: raise it whenever schema() changes.
 */
const DB_VERSION = '3';

/**
 * Option holding the installed schema version.
 */
const DB_VERSION_OPTION = 'evcert_db_version';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\maybe_install', 5 );
}

/**
 * Table names on the current site.
 *
 * @return array{certificates: string, designs: string, people: string, person_roles: string}
 */
function tables() {
	global $wpdb;

	return array(
		'certificates' => $wpdb->prefix . 'evcert_certificates',
		'designs'      => $wpdb->prefix . 'evcert_designs',
		'people'       => $wpdb->prefix . 'evcert_people',
		'person_roles' => $wpdb->prefix . 'evcert_person_roles',
	);
}

/**
 * Creates or upgrades the tables when the schema version changed.
 *
 * @return bool True when it ran dbDelta.
 */
function maybe_install() {
	$installed = get_option( DB_VERSION_OPTION );
	if ( DB_VERSION === $installed ) {
		return false;
	}
	install( (string) $installed );

	return true;
}

/**
 * Creates or upgrades the tables of the current site.
 *
 * @param string $from The schema version the site had ('' on a new one).
 */
function install( $from = '' ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( schema() );
	migrate( $from );
	update_option( DB_VERSION_OPTION, DB_VERSION, true );
}

/**
 * Moves data to the shape of the current schema. Safe to run twice.
 *
 * Schema 3: a person had one role and one certificate (columns of the people table); now each is a row of
 * person_roles. The old columns are left where they are, unused: dbDelta does not drop columns and a copy
 * that is read nowhere harms nothing.
 *
 * @param string $from The schema version the site had ('' on a new one).
 */
function migrate( $from ) {
	global $wpdb;

	if ( '' === $from || version_compare( $from, '3', '>=' ) ) {
		return;
	}
	$people = tables()['people'];
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $people, 'certificate_id' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		return;
	}
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i ( person_id, role, certificate_id, created_at, updated_at ) SELECT id, CASE WHEN role = '' THEN 'attendee' ELSE role END, certificate_id, created_at, updated_at FROM %i", tables()['person_roles'], $people ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own tables, once, when the schema moves to 3.
}

/**
 * CREATE TABLE statements in dbDelta's format (two spaces before PRIMARY
 * KEY, one index per line).
 *
 * Certificates keep structured values, never formatted text (dates in UTC
 * with their time zone, hours as a number, the role as an id), so a
 * certificate can be shown in any language with the same fingerprint.
 * The person's document is never stored: only its masked form and a
 * password-style hash (doc_hash) to check a typed number against.
 *
 * @return string SQL.
 */
function schema() {
	global $wpdb;

	$charset = $wpdb->get_charset_collate();
	$t       = tables();

	return "CREATE TABLE {$t['certificates']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(20) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  context varchar(20) NOT NULL DEFAULT 'site',
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  wporg_username varchar(60) NOT NULL DEFAULT '',
  role varchar(60) NOT NULL DEFAULT '',
  details text,
  attended tinyint(1) NOT NULL DEFAULT 0,
  locale varchar(12) NOT NULL DEFAULT '',
  event_title varchar(255) NOT NULL DEFAULT '',
  event_start datetime DEFAULT NULL,
  event_end datetime DEFAULT NULL,
  event_tz varchar(40) NOT NULL DEFAULT '',
  hours decimal(5,2) DEFAULT NULL,
  data_json longtext,
  doc_type varchar(40) NOT NULL DEFAULT '',
  doc_label varchar(60) NOT NULL DEFAULT '',
  doc_masked varchar(40) NOT NULL DEFAULT '',
  doc_hash varchar(255) NOT NULL DEFAULT '',
  self_service varchar(20) NOT NULL DEFAULT '',
  fingerprint char(64) NOT NULL DEFAULT '',
  design_hash char(64) NOT NULL DEFAULT '',
  renderer_version varchar(10) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'manual',
  source_ref varchar(100) NOT NULL DEFAULT '',
  revoked_reason varchar(255) NOT NULL DEFAULT '',
  replaced_by bigint(20) unsigned DEFAULT NULL,
  issued_at datetime DEFAULT NULL,
  email_status varchar(10) NOT NULL DEFAULT '',
  email_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  emailed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY event (context,event_id),
  KEY group_id (group_id),
  KEY email (email),
  KEY wporg_username (wporg_username),
  KEY source (context,event_id,source,source_ref),
  KEY source_role (source,source_ref,role),
  KEY email_queue (email_status)
) $charset;
CREATE TABLE {$t['designs']} (
  hash char(64) NOT NULL,
  design longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (hash)
) $charset;
CREATE TABLE {$t['people']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  wporg_username varchar(60) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'registered',
  source varchar(20) NOT NULL DEFAULT 'manual',
  source_ref varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event_status (event_id,status),
  KEY source (event_id,source,source_ref),
  KEY email (event_id,email)
) $charset;
CREATE TABLE {$t['person_roles']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL DEFAULT 0,
  role varchar(60) NOT NULL DEFAULT 'attendee',
  certificate_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_role (person_id,role),
  KEY certificate (certificate_id)
) $charset;";
}
