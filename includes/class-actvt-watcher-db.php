<?php

/**
 * Database handler class.
 */
class Actvt_Watcher_DB {

	/**
	 * Return a numeric rank for any WP role based on its capabilities.
	 * Works for built-in AND custom roles (WooCommerce, membership plugins, etc.).
	 *
	 * Ladder:
	 *   4 — manage_options      (administrator-level)
	 *   3 — edit_others_posts   (editor-level)
	 *   2 — publish_posts       (author-level)
	 *   1 — edit_posts          (contributor-level)
	 *   0 — everything else     (subscriber-level)
	 *
	 * @param string $role_slug  WordPress role slug.
	 * @return int 0–4
	 */
	public static function get_role_rank( $role_slug ) {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return 0;
		}
		$caps = $role->capabilities;
		if ( ! empty( $caps['manage_options'] ) )    return 4;
		if ( ! empty( $caps['edit_others_posts'] ) ) return 3;
		if ( ! empty( $caps['publish_posts'] ) )     return 2;
		if ( ! empty( $caps['edit_posts'] ) )        return 1;
		return 0;
	}

	/**
	 * Actions that can trigger instant security alerts.
	 * Maps action slug → human-readable label.
	 */
	public static $alert_actions = array(
		'login_failed'       => 'Failed Login',
		'set_user_role'      => 'User Role Changed',
		'delete_user'        => 'User Deleted',
		'password_reset'     => 'Password Reset',
		'theme_editor_open'  => 'Theme File Editor Accessed',
		'plugin_editor_open' => 'Plugin File Editor Accessed',
	);

	/**
	 * Insert a log entry.
	 *
	 * @param array $data Log data. Optional extra key: 'post_type' (for post-type exclusion).
	 * @return int|false The item ID on success, or false on error/exclusion.
	 */
	public static function insert_log( $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'actvt_watcher_logs';

		// ── Load settings once ────────────────────────────────────────────
		$settings = get_option( 'actvt_watcher_settings', array() );

		$event_type = isset( $data['event_type'] ) ? $data['event_type'] : 'general';
		$user_id    = isset( $data['user_id'] )    ? intval( $data['user_id'] ) : get_current_user_id();
		$ip         = self::get_ip_address();
		$action     = isset( $data['action'] ) ? $data['action'] : '';

		// ── 1. Exclude event types ────────────────────────────────────────
		$excluded_event_types = isset( $settings['excluded_event_types'] ) ? (array) $settings['excluded_event_types'] : array();
		if ( ! empty( $excluded_event_types ) && in_array( $event_type, $excluded_event_types, true ) ) {
			return false;
		}

		// ── 2. Exclude user IDs ───────────────────────────────────────────
		$excluded_user_ids = isset( $settings['excluded_user_ids'] ) ? array_map( 'intval', (array) $settings['excluded_user_ids'] ) : array();
		if ( ! empty( $excluded_user_ids ) && in_array( $user_id, $excluded_user_ids, true ) ) {
			return false;
		}

		// ── 3. Exclude IPs ────────────────────────────────────────────────
		$excluded_ips_raw = isset( $settings['excluded_ips'] ) ? $settings['excluded_ips'] : '';
		if ( $excluded_ips_raw ) {
			$excluded_ips = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $excluded_ips_raw ) ) );
			if ( in_array( $ip, $excluded_ips, true ) ) {
				return false;
			}
		}

		// ── 4. Exclude post types ─────────────────────────────────────────
		$excluded_post_types = isset( $settings['excluded_post_types'] ) ? (array) $settings['excluded_post_types'] : array();
		$data_post_type      = isset( $data['post_type'] ) ? $data['post_type'] : '';
		if ( ! empty( $excluded_post_types ) && $data_post_type && in_array( $data_post_type, $excluded_post_types, true ) ) {
			return false;
		}

		// ── 5. Minimum user role ──────────────────────────────────────────
		$min_role = isset( $settings['min_role_to_log'] ) ? $settings['min_role_to_log'] : '';
		if ( $min_role && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->roles ) ) {
				$user_role_slug = reset( $user->roles );
				if ( self::get_role_rank( $user_role_slug ) < self::get_role_rank( $min_role ) ) {
					return false;
				}
			}
		}

		// ── Build row ─────────────────────────────────────────────────────
		$defaults = array(
			'timestamp'  => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
			'user_role'  => '',
			'event_type' => 'general',
			'action'     => '',
			'object_id'  => 0,
			'metadata'   => '',
			'ip_address' => $ip,
		);

		// Strip internal-only key before building row
		$data_for_insert = $data;
		unset( $data_for_insert['post_type'] );

		$args = wp_parse_args( $data_for_insert, $defaults );

		// Fill user_role if blank
		if ( empty( $args['user_role'] ) && ! empty( $args['user_id'] ) ) {
			$user = get_userdata( $args['user_id'] );
			if ( $user && ! empty( $user->roles ) ) {
				$args['user_role'] = reset( $user->roles );
			}
		}

		// JSON-encode metadata arrays
		if ( is_array( $args['metadata'] ) || is_object( $args['metadata'] ) ) {
			$args['metadata'] = json_encode( $args['metadata'] );
		}

		$result = $wpdb->insert(
			$table_name,
			$args,
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( $result ) {
			// ── 6. Security alerts ────────────────────────────────────────
			self::maybe_send_security_alert( $args, $settings );

			// ── 7. Enforce max row cap ────────────────────────────────────
			self::enforce_max_rows( $settings, $table_name );

			// ── 8. Mirror to flat log file ────────────────────────────────
			self::maybe_mirror_to_file( $args, $settings );
		}

		return $result;
	}

	/**
	 * Write a single JSON line to the mirror log file (if enabled).
	 *
	 * @param array $args     The inserted row data.
	 * @param array $settings Plugin settings.
	 */
	private static function maybe_mirror_to_file( $args, $settings ) {
		if ( empty( $settings['log_file_enabled'] ) ) {
			return;
		}

		$base_path = ! empty( $settings['log_file_path'] )
			? rtrim( $settings['log_file_path'], '/\\' )
			: trailingslashit( WP_CONTENT_DIR ) . 'uploads/actvt-logs';

		// Create directory if it does not exist
		if ( ! file_exists( $base_path ) ) {
			wp_mkdir_p( $base_path );
			// Drop an .htaccess to block direct browser access
			file_put_contents( $base_path . '/.htaccess', "Deny from all\n" );
		}

		// Daily rotating filename: actvt-YYYY-MM-DD.log
		$filename = $base_path . '/actvt-' . date( 'Y-m-d' ) . '.log';

		$line = json_encode( array(
			'ts'         => $args['timestamp'],
			'user_id'    => $args['user_id'],
			'user_role'  => $args['user_role'],
			'event_type' => $args['event_type'],
			'action'     => $args['action'],
			'object_id'  => $args['object_id'],
			'metadata'   => $args['metadata'],
			'ip'         => $args['ip_address'],
		) ) . "\n";

		file_put_contents( $filename, $line, FILE_APPEND | LOCK_EX );
	}

	// ── Security Alert Engine ────────────────────────────────────────────────

	/**
	 * Send an instant security alert email if the event matches configured triggers.
	 *
	 * @param array $args     The inserted row data.
	 * @param array $settings Plugin settings.
	 */
	private static function maybe_send_security_alert( $args, $settings ) {
		if ( empty( $settings['security_alerts_enabled'] ) ) {
			return;
		}

		$alert_events = isset( $settings['security_alert_events'] ) ? (array) $settings['security_alert_events'] : array();
		if ( empty( $alert_events ) ) {
			return;
		}

		$action = $args['action'];

		// Failed login threshold check
		if ( $action === 'login_failed' && in_array( 'login_failed', $alert_events, true ) ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'actvt_watcher_logs';
			$threshold  = isset( $settings['security_alert_threshold'] ) ? intval( $settings['security_alert_threshold'] ) : 5;
			$since      = date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
			$ip         = $args['ip_address'];

			$recent_fails = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE action = 'login_failed' AND ip_address = %s AND timestamp >= %s",
				$ip, $since
			) );

			if ( $recent_fails < $threshold ) {
				return; // threshold not yet hit
			}
		} elseif ( ! in_array( $action, $alert_events, true ) ) {
			return; // action not in alert list
		}

		// Build alert email
		$site_name  = get_bloginfo( 'name' );
		$recipients = isset( $settings['security_alert_recipients'] ) && $settings['security_alert_recipients']
		              ? array_filter( array_map( 'trim', explode( ',', $settings['security_alert_recipients'] ) ) )
		              : array( get_option( 'admin_email' ) );

		$action_label = isset( self::$alert_actions[ $action ] ) ? self::$alert_actions[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
		$user_info    = get_userdata( $args['user_id'] );
		$username     = $user_info ? $user_info->user_login : ( $args['user_id'] > 0 ? '#' . $args['user_id'] : 'Guest' );

		$detail_rows = '';
		foreach ( array(
			'Event'     => $action_label,
			'User'      => $username,
			'IP'        => $args['ip_address'],
			'Time'      => $args['timestamp'],
			'Details'   => $args['metadata'],
		) as $k => $v ) {
			$detail_rows .= sprintf(
				'<tr><td style="padding:6px 12px;background:#f6f7f7;font-weight:600;white-space:nowrap;border-bottom:1px solid #eee;">%s</td><td style="padding:6px 12px;border-bottom:1px solid #eee;">%s</td></tr>',
				esc_html( $k ), esc_html( $v )
			);
		}

		$html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#1d2327;margin:0;padding:0;">
<table width="600" cellpadding="0" cellspacing="0" style="margin:30px auto;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;">
  <tr><td style="background:#d63638;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:18px;">&#128673; Security Alert &mdash; {$site_name}</h2>
    <p style="color:#f8c8c8;margin:4px 0 0;font-size:13px;">{$action_label}</p>
  </td></tr>
  <tr><td style="padding:24px;">
    <table width="100%" cellpadding="0" cellspacing="0">{$detail_rows}</table>
    <p style="margin-top:20px;font-size:12px;color:#646970;">
      <a href="{admin_url}" style="color:#2271b1;">&#8594; View full logs</a>
    </p>
  </td></tr>
</table>
</body></html>
HTML;
		$html    = str_replace( '{admin_url}', admin_url( 'admin.php?page=actvt-watcher' ), $html );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$subject = sprintf( '[%s] Security Alert: %s', $site_name, $action_label );
		wp_mail( $recipients, $subject, $html, $headers );
	}

	// ── Max Row Cap ──────────────────────────────────────────────────────────

	/**
	 * Delete the oldest rows if total count exceeds max_log_rows.
	 *
	 * @param array  $settings   Plugin settings.
	 * @param string $table_name Prefixed table name.
	 */
	private static function enforce_max_rows( $settings, $table_name ) {
		$max = isset( $settings['max_log_rows'] ) ? intval( $settings['max_log_rows'] ) : 0;
		if ( $max <= 0 ) {
			return;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		if ( $total <= $max ) {
			return;
		}

		$excess = $total - $max;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} ORDER BY timestamp ASC LIMIT %d",
			$excess
		) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Get client IP address.
	 *
	 * @return string IP address.
	 */
	private static function get_ip_address() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
				return sanitize_text_field( $ip );
			}
		}
		return '';
	}
}
