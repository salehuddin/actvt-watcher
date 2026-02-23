<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fired during plugin activation.
 */
class Actvt_Watcher_Activator {

	/**
	 * Create the database table.
	 */
	public static function activate() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'actvt_watcher_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			user_id bigint(20) NOT NULL,
			user_role varchar(50) DEFAULT '' NOT NULL,
			event_type varchar(50) DEFAULT '' NOT NULL,
			action varchar(100) DEFAULT '' NOT NULL,
			object_id bigint(20) DEFAULT 0 NOT NULL,
			metadata longtext DEFAULT '' NOT NULL,
			ip_address varchar(100) DEFAULT '' NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
}
