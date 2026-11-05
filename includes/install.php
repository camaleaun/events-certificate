<?php
/**
 * Database tables: created and upgraded by comparing a schema version, with
 * no activation hook (activation doesn't run on network-wide installs,
 * updates or WP-CLI copies; a version check always does).
 *
 * - certificates: one row per person and event, from draft to issued or
 *   revoked. Data is frozen when issued: the PDF is drawn again from it on
 *   every download, so no files are kept.
 * - designs: immutable snapshots of the artwork a certificate was issued
 *   with, stored once per hash and shared by every certificate using it.
 * - audit: who checked, locked or changed what. Never holds a document number.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Schema version: raise it whenever schema() changes.
 */
const DB_VERSION = '1';

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
 * @return array{certificates: string, designs: string, audit: string}
 */
function tables() {
	global $wpdb;

	return array(
		'certificates' => $wpdb->prefix . 'evcert_certificates',
		'designs'      => $wpdb->prefix . 'evcert_designs',
		'audit'        => $wpdb->prefix . 'evcert_audit',
	);
}

/**
 * Creates or upgrades the tables when the schema version changed.
 *
 * @return bool True when it ran dbDelta.
 */
function maybe_install() {
	if ( DB_VERSION === get_option( DB_VERSION_OPTION ) ) {
		return false;
	}
	install();

	return true;
}

/**
 * Creates or upgrades the tables of the current site.
 */
function install() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( schema() );
	update_option( DB_VERSION_OPTION, DB_VERSION, true );
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
  doc_check_fail smallint(5) unsigned NOT NULL DEFAULT 0,
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
  KEY email_queue (email_status)
) $charset;
CREATE TABLE {$t['designs']} (
  hash char(64) NOT NULL,
  design longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (hash)
) $charset;
CREATE TABLE {$t['audit']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  certificate_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY certificate_id (certificate_id),
  KEY created_at (created_at)
) $charset;";
}
