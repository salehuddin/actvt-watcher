<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The admin-specific functionality of the plugin.
 */
class Actvt_Watcher_Admin {

	/**
	 * The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 */
	public function add_plugin_admin_menu() {
		add_menu_page(
			__( 'ACTVT Watcher Logs', 'actvt-watcher' ), 
			__( 'ACTVT Watcher', 'actvt-watcher' ), 
			'manage_options', 
			$this->plugin_name, 
			array( $this, 'display_plugin_admin_page' ), 
			'dashicons-visibility', 
			100
		);
		add_submenu_page(
			$this->plugin_name,
			__( 'Settings — ACTVT Watcher', 'actvt-watcher' ),
			__( 'Settings', 'actvt-watcher' ),
			'manage_options',
			'actvt-watcher-settings',
			array( $this, 'display_settings_page' )
		);
		add_submenu_page(
			$this->plugin_name,
			__( 'How-To &amp; Docs — ACTVT Watcher', 'actvt-watcher' ),
			__( 'How-To & Docs', 'actvt-watcher' ),
			'manage_options',
			'actvt-watcher-help',
			array( $this, 'display_help_page' )
		);
	}

	/**
	 * Render the log viewer admin page.
	 */
	public function display_plugin_admin_page() {
		include_once ACTVT_WATCHER_PATH . 'admin/partials/actvt-watcher-admin-display.php';
	}

	/**
	 * Render the settings page.
	 */
	public function display_settings_page() {
		include_once ACTVT_WATCHER_PATH . 'admin/partials/actvt-watcher-settings-display.php';
	}

	/**
	 * Render the help / documentation page.
	 */
	public function display_help_page() {
		include_once ACTVT_WATCHER_PATH . 'admin/partials/actvt-watcher-help-display.php';
	}

	/**
	 * Save settings (admin_post_actvt_save_settings).
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		check_admin_referer( 'actvt_save_settings' );

		$all_event_types = array( 'auth', 'content', 'system', 'security', 'general' );
		$valid_roles     = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
		$valid_intervals = array( 'daily', 'weekly', 'monthly' );
		$valid_periods   = array( '', 'this_month', 'last_month', 'last_3_months', 'custom' );

		// Metadata detail level
		$metadata_detail_level = isset( $_POST['metadata_detail_level'] ) && $_POST['metadata_detail_level'] === 'detailed'
		                         ? 'detailed' : 'simple';

		// ── Logging Exclusions ─────────────────────────────────────────────
		$excluded_event_types = array();
		if ( isset( $_POST['excluded_event_types'] ) && is_array( $_POST['excluded_event_types'] ) ) {
			$excluded_event_types = array_values( array_filter(
				array_map( 'sanitize_text_field', $_POST['excluded_event_types'] ),
				function( $t ) use ( $all_event_types ) { return in_array( $t, $all_event_types, true ); }
			) );
		}

		$excluded_user_ids = array();
		if ( isset( $_POST['excluded_user_ids'] ) && is_array( $_POST['excluded_user_ids'] ) ) {
			$excluded_user_ids = array_values( array_filter( array_map( 'intval', $_POST['excluded_user_ids'] ) ) );
		}

		$excluded_ips = isset( $_POST['excluded_ips'] ) ? sanitize_textarea_field( $_POST['excluded_ips'] ) : '';

		$excluded_post_types = array();
		if ( isset( $_POST['excluded_post_types'] ) && is_array( $_POST['excluded_post_types'] ) ) {
			$excluded_post_types = array_values( array_filter( array_map( 'sanitize_text_field', $_POST['excluded_post_types'] ) ) );
		}

		$min_role_to_log = isset( $_POST['min_role_to_log'] ) && in_array( $_POST['min_role_to_log'], array_merge( array( '' ), $valid_roles ), true )
		                    ? sanitize_text_field( $_POST['min_role_to_log'] ) : '';

		// ── Storage / Retention ────────────────────────────────────────────
		$log_retention_days     = isset( $_POST['log_retention_days'] ) ? max( 0, intval( $_POST['log_retention_days'] ) ) : 0;
		$max_log_rows           = isset( $_POST['max_log_rows'] )        ? max( 0, intval( $_POST['max_log_rows'] ) )       : 0;
		$auto_export_before_purge = ! empty( $_POST['auto_export_before_purge'] ) ? 1 : 0;

		// ── Mirror to Log File ─────────────────────────────────────────────
		$log_file_enabled = ! empty( $_POST['log_file_enabled'] ) ? 1 : 0;
		$log_file_path    = isset( $_POST['log_file_path'] ) ? sanitize_text_field( $_POST['log_file_path'] ) : '';

		// ── Email Reports ──────────────────────────────────────────────────
		$email_enabled    = ! empty( $_POST['email_enabled'] ) ? 1 : 0;
		$email_interval   = in_array( $_POST['email_interval'] ?? '', $valid_intervals, true )
		                    ? sanitize_text_field( $_POST['email_interval'] ) : 'monthly';
		$email_recipients = isset( $_POST['email_recipients'] ) ? sanitize_textarea_field( $_POST['email_recipients'] ) : '';
		$email_time       = isset( $_POST['email_time'] ) && preg_match( '/^\d{2}:\d{2}$/', $_POST['email_time'] )
		                    ? $_POST['email_time'] : '08:00';

		// ── Security Alerts ────────────────────────────────────────────────
		$security_alerts_enabled   = ! empty( $_POST['security_alerts_enabled'] ) ? 1 : 0;
		$security_alert_recipients = isset( $_POST['security_alert_recipients'] ) ? sanitize_textarea_field( $_POST['security_alert_recipients'] ) : '';
		$security_alert_threshold  = isset( $_POST['security_alert_threshold'] ) ? max( 1, intval( $_POST['security_alert_threshold'] ) ) : 5;
		$security_alert_events     = array();
		if ( isset( $_POST['security_alert_events'] ) && is_array( $_POST['security_alert_events'] ) ) {
			$valid_alert_actions   = array_keys( Actvt_Watcher_DB::$alert_actions );
			$security_alert_events = array_values( array_filter(
				array_map( 'sanitize_text_field', $_POST['security_alert_events'] ),
				function( $a ) use ( $valid_alert_actions ) { return in_array( $a, $valid_alert_actions, true ); }
			) );
		}

		// ── Log Viewer Defaults ────────────────────────────────────────────
		$default_per_page   = isset( $_POST['default_per_page'] ) && in_array( intval( $_POST['default_per_page'] ), array( 25, 50, 100, 200 ) )
		                      ? intval( $_POST['default_per_page'] ) : 50;
		$default_date_range = isset( $_POST['default_date_range'] ) && in_array( $_POST['default_date_range'], $valid_periods, true )
		                      ? sanitize_text_field( $_POST['default_date_range'] ) : '';
		$metadata_display   = isset( $_POST['metadata_display'] ) && $_POST['metadata_display'] === 'raw' ? 'raw' : 'formatted';

		$settings = array(
			// Exclusions
			'excluded_event_types'   => $excluded_event_types,
			'excluded_user_ids'      => $excluded_user_ids,
			'excluded_ips'           => $excluded_ips,
			'excluded_post_types'    => $excluded_post_types,
			'min_role_to_log'        => $min_role_to_log,
			// Storage
			'log_retention_days'       => $log_retention_days,
			'max_log_rows'             => $max_log_rows,
			'auto_export_before_purge' => $auto_export_before_purge,
			// Mirror to file
			'log_file_enabled'         => $log_file_enabled,
			'log_file_path'            => $log_file_path,
			// Email reports
			'email_enabled'          => $email_enabled,
			'email_interval'         => $email_interval,
			'email_recipients'       => $email_recipients,
			'email_time'             => $email_time,
			// Security alerts
			'security_alerts_enabled'   => $security_alerts_enabled,
			'security_alert_recipients' => $security_alert_recipients,
			'security_alert_threshold'  => $security_alert_threshold,
			'security_alert_events'     => $security_alert_events,
			// Viewer defaults
			'default_per_page'       => $default_per_page,
			'default_date_range'     => $default_date_range,
			'metadata_display'       => $metadata_display,
			// Metadata
			'metadata_detail_level'  => $metadata_detail_level,
		);

		update_option( 'actvt_watcher_settings', $settings );

		// Reschedule cron with updated email settings
		Actvt_Watcher_Cron::schedule_report( true );
		Actvt_Watcher_Cron::schedule_purge();

		wp_redirect( add_query_arg( array( 'page' => 'actvt-watcher-settings', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Purge logs now (admin_post_actvt_purge_now).
	 */
	public function purge_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		check_admin_referer( 'actvt_purge_now' );

		$settings       = get_option( 'actvt_watcher_settings', array() );
		$retention_days = isset( $settings['log_retention_days'] ) ? intval( $settings['log_retention_days'] ) : 0;

		$deleted = 0;
		if ( $retention_days > 0 ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'actvt_watcher_logs';
			$cutoff     = date( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
			$deleted    = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE timestamp < %s", $cutoff ) );
		}

		wp_redirect( add_query_arg( array( 'page' => 'actvt-watcher-settings', 'purged' => max( 0, intval( $deleted ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle CSV export download.
	 * Hooked on: admin_post_actvt_export_logs
	 */
	public function export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'actvt_export_logs' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'actvt-watcher' ) );
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . 'actvt_watcher_logs';
		$all_event_types = array( 'auth', 'content', 'system', 'security', 'general' );

		// ── Sanitize the same filters as the display page ──────────────────
		if ( isset( $_GET['event_type'] ) && is_array( $_GET['event_type'] ) ) {
			$filter_event_types = array_filter(
				array_map( 'sanitize_text_field', $_GET['event_type'] ),
				function( $t ) use ( $all_event_types ) { return in_array( $t, $all_event_types, true ); }
			);
		} else {
			$filter_event_types = array();
		}

		$filter_user_id   = isset( $_GET['filter_user'] ) ? intval( $_GET['filter_user'] ) : 0;
		$filter_period    = isset( $_GET['period'] )    ? sanitize_text_field( $_GET['period'] )    : '';
		$filter_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$filter_date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( $_GET['date_to'] )   : '';
		$search_query     = isset( $_GET['s'] )         ? sanitize_text_field( $_GET['s'] )         : '';

		// Resolve period presets
		if ( $filter_period && $filter_period !== 'custom' ) {
			switch ( $filter_period ) {
				case 'this_month':
					$filter_date_from = date( 'Y-m-01' );
					$filter_date_to   = date( 'Y-m-t' );
					break;
				case 'last_month':
					$filter_date_from = date( 'Y-m-01', strtotime( 'first day of last month' ) );
					$filter_date_to   = date( 'Y-m-t',  strtotime( 'last day of last month' ) );
					break;
				case 'last_3_months':
					$filter_date_from = date( 'Y-m-01', strtotime( '-2 months' ) );
					$filter_date_to   = date( 'Y-m-t' );
					break;
			}
		}

		// ── Build WHERE clause ─────────────────────────────────────────────
		$where_clauses = array( '1=1' );
		$prepare_args  = array();

		if ( ! empty( $filter_event_types ) && count( $filter_event_types ) < count( $all_event_types ) ) {
			$placeholders    = implode( ', ', array_fill( 0, count( $filter_event_types ), '%s' ) );
			$where_clauses[] = "event_type IN ( $placeholders )";
			foreach ( $filter_event_types as $et ) { $prepare_args[] = $et; }
		}
		if ( $filter_user_id > 0 ) {
			$where_clauses[] = 'user_id = %d';
			$prepare_args[]  = $filter_user_id;
		}
		if ( $filter_date_from ) {
			$where_clauses[] = 'DATE(timestamp) >= %s';
			$prepare_args[]  = $filter_date_from;
		}
		if ( $filter_date_to ) {
			$where_clauses[] = 'DATE(timestamp) <= %s';
			$prepare_args[]  = $filter_date_to;
		}
		if ( $search_query ) {
			$where_clauses[] = '(action LIKE %s OR metadata LIKE %s OR ip_address LIKE %s)';
			$like            = '%' . $wpdb->esc_like( $search_query ) . '%';
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$sql       = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY timestamp DESC";
		$rows      = ! empty( $prepare_args )
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		// ── Stream CSV ────────────────────────────────────────────────────
		$filename = 'actvt-logs-' . date( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens it correctly
		fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

		// Column headers
		fputcsv( $out, array( 'ID', 'Timestamp', 'User ID', 'Username', 'User Role', 'Event Type', 'Action', 'Object ID', 'Metadata', 'IP Address' ) );

		foreach ( $rows as $row ) {
			$user_info = get_userdata( $row['user_id'] );
			$username  = $user_info ? $user_info->user_login : ( $row['user_id'] > 0 ? '#' . $row['user_id'] : 'Guest' );

			// Decode JSON metadata and flatten to "key: value | key: value" so no
			// embedded double-quotes escape the CSV column in Excel / Google Sheets.
			$meta_raw     = $row['metadata'];
			$meta_decoded = json_decode( $meta_raw, true );
			if ( is_array( $meta_decoded ) ) {
				$meta_parts = array();
				array_walk_recursive( $meta_decoded, function( $v, $k ) use ( &$meta_parts ) {
					$meta_parts[] = $k . ': ' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v );
				} );
				$meta_cell = implode( ' | ', $meta_parts );
			} else {
				// Fallback: strip backslash-escapes so quotes do not break CSV
				$meta_cell = str_replace( '\\"', "'", str_replace( '\\', '', $meta_raw ) );
			}

			fputcsv( $out, array(
				$row['id'],
				$row['timestamp'],
				$row['user_id'],
				$username,
				$row['user_role'],
				$row['event_type'],
				$row['action'],
				$row['object_id'],
				$meta_cell,
				$row['ip_address'],
			) );
		}


		fclose( $out );
		exit;
	}

	// ═══════════════════════════════════════════════════════════════
	//  FEATURE: Manual Email Report
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Send an on-demand HTML report + CSV attachment.
	 * Hooked on: admin_post_actvt_send_manual_report
	 */
	public function send_manual_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'actvt_send_manual_report' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'actvt-watcher' ) );
		}

		$to = isset( $_POST['report_email'] ) ? sanitize_email( $_POST['report_email'] ) : '';
		if ( ! is_email( $to ) ) {
			wp_redirect( add_query_arg( array( 'page' => 'actvt-watcher', 'actvt_report_error' => 'invalid_email' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . 'actvt_watcher_logs';
		$all_event_types = array( 'auth', 'content', 'system', 'security', 'general' );

		// ── Sanitize filters (from POST, passed as hidden fields) ──────────
		if ( isset( $_POST['event_type'] ) && is_array( $_POST['event_type'] ) ) {
			$filter_event_types = array_filter(
				array_map( 'sanitize_text_field', $_POST['event_type'] ),
				function( $t ) use ( $all_event_types ) { return in_array( $t, $all_event_types, true ); }
			);
		} else {
			$filter_event_types = array();
		}

		$filter_user_id   = isset( $_POST['filter_user'] ) ? intval( $_POST['filter_user'] ) : 0;
		$filter_period    = isset( $_POST['period'] )    ? sanitize_text_field( $_POST['period'] )    : '';
		$filter_date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '';
		$filter_date_to   = isset( $_POST['date_to'] )   ? sanitize_text_field( $_POST['date_to'] )   : '';
		$search_query     = isset( $_POST['s'] )         ? sanitize_text_field( $_POST['s'] )         : '';

		// Resolve period presets
		if ( $filter_period && $filter_period !== 'custom' ) {
			switch ( $filter_period ) {
				case 'this_month':
					$filter_date_from = date( 'Y-m-01' );
					$filter_date_to   = date( 'Y-m-t' );
					break;
				case 'last_month':
					$filter_date_from = date( 'Y-m-01', strtotime( 'first day of last month' ) );
					$filter_date_to   = date( 'Y-m-t',  strtotime( 'last day of last month' ) );
					break;
				case 'last_3_months':
					$filter_date_from = date( 'Y-m-01', strtotime( '-2 months' ) );
					$filter_date_to   = date( 'Y-m-t' );
					break;
			}
		}

		// ── Build WHERE clause ─────────────────────────────────────────────
		$where_clauses = array( '1=1' );
		$prepare_args  = array();

		if ( ! empty( $filter_event_types ) && count( $filter_event_types ) < count( $all_event_types ) ) {
			$placeholders    = implode( ', ', array_fill( 0, count( $filter_event_types ), '%s' ) );
			$where_clauses[] = "event_type IN ( $placeholders )";
			foreach ( $filter_event_types as $et ) { $prepare_args[] = $et; }
		}
		if ( $filter_user_id > 0 ) {
			$where_clauses[] = 'user_id = %d';
			$prepare_args[]  = $filter_user_id;
		}
		if ( $filter_date_from ) {
			$where_clauses[] = 'DATE(timestamp) >= %s';
			$prepare_args[]  = $filter_date_from;
		}
		if ( $filter_date_to ) {
			$where_clauses[] = 'DATE(timestamp) <= %s';
			$prepare_args[]  = $filter_date_to;
		}
		if ( $search_query ) {
			$where_clauses[] = '(action LIKE %s OR metadata LIKE %s OR ip_address LIKE %s)';
			$like            = '%' . $wpdb->esc_like( $search_query ) . '%';
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// ── Digest query (grouped) ─────────────────────────────────────────
		$digest_sql  = "SELECT event_type, user_id, COUNT(*) as cnt FROM {$table_name} WHERE {$where_sql} GROUP BY event_type, user_id ORDER BY cnt DESC";
		$digest_rows = ! empty( $prepare_args )
			? $wpdb->get_results( $wpdb->prepare( $digest_sql, ...$prepare_args ) )
			: $wpdb->get_results( $digest_sql );

		// ── Full rows for CSV ──────────────────────────────────────────────
		$full_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY timestamp DESC";
		$full_rows = ! empty( $prepare_args )
			? $wpdb->get_results( $wpdb->prepare( $full_sql, ...$prepare_args ), ARRAY_A )
			: $wpdb->get_results( $full_sql, ARRAY_A );

		// ── Build HTML digest ──────────────────────────────────────────────
		$label_parts = array();
		if ( $filter_date_from || $filter_date_to ) {
			$label_parts[] = trim( $filter_date_from . ' – ' . $filter_date_to, ' – ' );
		} elseif ( $filter_period ) {
			$label_parts[] = ucwords( str_replace( '_', ' ', $filter_period ) );
		} else {
			$label_parts[] = 'All Time';
		}
		if ( ! empty( $filter_event_types ) ) {
			$label_parts[] = implode( ', ', array_map( 'ucfirst', $filter_event_types ) );
		}
		$label     = implode( ' | ', $label_parts );
		$site_name = get_bloginfo( 'name' );

		$rows_html = '';
		if ( ! empty( $digest_rows ) ) {
			foreach ( $digest_rows as $row ) {
				$user   = get_userdata( $row->user_id );
				$uname  = $user ? esc_html( $user->user_login ) : 'Guest/System';
				$rows_html .= sprintf(
					'<tr><td style="padding:6px 12px; border-bottom:1px solid #eee;">%s</td><td style="padding:6px 12px; border-bottom:1px solid #eee;">%s</td><td style="padding:6px 12px; border-bottom:1px solid #eee; text-align:right;"><strong>%d</strong></td></tr>',
					esc_html( $row->event_type ), $uname, intval( $row->cnt )
				);
			}
		} else {
			$rows_html = '<tr><td colspan="3" style="padding:12px; text-align:center; color:#646970;">No activity found for this period.</td></tr>';
		}

		$log_url  = admin_url( 'admin.php?page=actvt-watcher' );
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif; color:#1d2327; margin:0; padding:0;">
  <table width="600" cellpadding="0" cellspacing="0" style="margin:30px auto; border:1px solid #c3c4c7; border-radius:6px; overflow:hidden;">
    <tr><td style="background:#2271b1; padding:20px 24px;">
      <h2 style="color:#fff; margin:0; font-size:18px;">&#128203; Activity Report &mdash; {$site_name}</h2>
      <p style="color:#c8d8ea; margin:6px 0 0; font-size:13px;">{$label}</p>
    </td></tr>
    <tr><td style="padding:24px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <thead>
          <tr style="background:#f6f7f7;">
            <th style="padding:8px 12px; text-align:left; font-size:12px; text-transform:uppercase; color:#646970;">Event Type</th>
            <th style="padding:8px 12px; text-align:left; font-size:12px; text-transform:uppercase; color:#646970;">User</th>
            <th style="padding:8px 12px; text-align:right; font-size:12px; text-transform:uppercase; color:#646970;">Count</th>
          </tr>
        </thead>
        <tbody>{$rows_html}</tbody>
      </table>
      <p style="margin-top:24px; font-size:12px; color:#646970;">
        Generated by ACTVT Watcher &bull; <a href="{$log_url}" style="color:#2271b1;">View full logs</a><br>
        A full CSV export is attached to this email.
      </p>
    </td></tr>
  </table>
</body>
</html>
HTML;

		// ── Build CSV attachment ───────────────────────────────────────────
		$csv_filename = 'actvt-report-' . date( 'Y-m-d-His' ) . '.csv';
		$csv_path     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $csv_filename;

		ob_start();
		$buf = fopen( 'php://output', 'w' );
		fprintf( $buf, chr(0xEF) . chr(0xBB) . chr(0xBF) ); // UTF-8 BOM
		fputcsv( $buf, array( 'ID', 'Timestamp', 'User ID', 'Username', 'User Role', 'Event Type', 'Action', 'Object ID', 'Metadata', 'IP Address' ) );
		foreach ( $full_rows as $row ) {
			$u         = get_userdata( $row['user_id'] );
			$username  = $u ? $u->user_login : ( $row['user_id'] > 0 ? '#' . $row['user_id'] : 'Guest' );
			$meta_raw  = $row['metadata'];
			$meta_dec  = json_decode( $meta_raw, true );
			if ( is_array( $meta_dec ) ) {
				$parts = array();
				array_walk_recursive( $meta_dec, function( $v, $k ) use ( &$parts ) {
					$parts[] = $k . ': ' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v );
				} );
				$meta_cell = implode( ' | ', $parts );
			} else {
				$meta_cell = str_replace( array( '\\"', '\\' ), array( "'", '' ), $meta_raw );
			}
			fputcsv( $buf, array(
				$row['id'], $row['timestamp'], $row['user_id'], $username,
				$row['user_role'], $row['event_type'], $row['action'],
				$row['object_id'], $meta_cell, $row['ip_address'],
			) );
		}
		fclose( $buf );
		$csv_content = ob_get_clean();
		file_put_contents( $csv_path, $csv_content );

		// ── Send email ─────────────────────────────────────────────────────
		$subject = sprintf( '[%s] Manual Activity Report — %s', $site_name, $label );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $html, $headers, array( $csv_path ) );
		@unlink( $csv_path );

		wp_redirect( add_query_arg( array( 'page' => 'actvt-watcher', 'actvt_report_sent' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// ═══════════════════════════════════════════════════════════════
	//  FEATURE: Export Logs
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Export settings as a JSON file download (admin_post_actvt_export_settings).
	 */
	public function export_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		check_admin_referer( 'actvt_export_settings' );

		$settings = get_option( 'actvt_watcher_settings', array() );
		$export   = array(
			'_plugin'    => 'actvt-watcher',
			'_version'   => ACTVT_WATCHER_VERSION,
			'_exported'  => current_time( 'mysql' ),
			'settings'   => $settings,
		);

		$filename = 'actvt-watcher-settings-' . date( 'Y-m-d' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		echo json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Import settings from an uploaded JSON file (admin_post_actvt_import_settings).
	 */
	public function import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'actvt-watcher' ) );
		}
		check_admin_referer( 'actvt_import_settings' );

		$redirect = add_query_arg( array( 'page' => 'actvt-watcher-settings' ), admin_url( 'admin.php' ) );

		if ( empty( $_FILES['actvt_settings_file']['tmp_name'] ) ) {
			wp_redirect( add_query_arg( 'import_error', 'no_file', $redirect ) );
			exit;
		}

		$raw = file_get_contents( $_FILES['actvt_settings_file']['tmp_name'] );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['settings'] ) || ( $data['_plugin'] ?? '' ) !== 'actvt-watcher' ) {
			wp_redirect( add_query_arg( 'import_error', 'invalid', $redirect ) );
			exit;
		}

		// Merge imported settings over current (preserves any keys not in the file)
		$current  = get_option( 'actvt_watcher_settings', array() );
		$imported = array_merge( $current, (array) $data['settings'] );
		update_option( 'actvt_watcher_settings', $imported );

		// Re-schedule cron with potentially updated email/purge settings
		Actvt_Watcher_Cron::schedule_report( true );
		Actvt_Watcher_Cron::schedule_purge();

		wp_redirect( add_query_arg( 'saved', 'imported', $redirect ) );
		exit;
	}

	// ═══════════════════════════════════════════════════════════════
	//  FEATURE: Saved Filter Presets (AJAX)
	// ═══════════════════════════════════════════════════════════════

	/**
	 * AJAX: save a named filter preset for the current user.
	 * POST: nonce, preset_name, query_string
	 */
	public function save_filter_preset() {
		check_ajax_referer( 'actvt_filter_preset', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'actvt-watcher' ) );
		}

		$name = sanitize_text_field( $_POST['preset_name'] ?? '' );
		$qs   = sanitize_text_field( $_POST['query_string'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( __( 'Preset name is required.', 'actvt-watcher' ) );
		}

		$user_id = get_current_user_id();
		$presets = get_user_meta( $user_id, 'actvt_filter_presets', true );
		if ( ! is_array( $presets ) ) {
			$presets = array();
		}

		$presets[ $name ] = $qs;
		update_user_meta( $user_id, 'actvt_filter_presets', $presets );

		wp_send_json_success( array( 'presets' => $presets ) );
	}

	/**
	 * AJAX: delete a named filter preset.
	 * POST: nonce, preset_name
	 */
	public function delete_filter_preset() {
		check_ajax_referer( 'actvt_filter_preset', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'actvt-watcher' ) );
		}

		$name = sanitize_text_field( $_POST['preset_name'] ?? '' );

		$user_id = get_current_user_id();
		$presets = get_user_meta( $user_id, 'actvt_filter_presets', true );
		if ( is_array( $presets ) && isset( $presets[ $name ] ) ) {
			unset( $presets[ $name ] );
			update_user_meta( $user_id, 'actvt_filter_presets', $presets );
		}

		wp_send_json_success( array( 'presets' => $presets ?? array() ) );
	}
}
