<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Cron Handler — settings-driven scheduling, HTML email reports, log retention.
 */
class Actvt_Watcher_Cron {

    /** WP cron hook names */
    const REPORT_HOOK  = 'actvt_watcher_report_event';
    const PURGE_HOOK   = 'actvt_watcher_purge_event';

    public function __construct() {
        add_action( self::REPORT_HOOK, array( $this, 'run_report' ) );
        add_action( self::PURGE_HOOK,  array( $this, 'purge_old_logs' ) );

        $this->schedule_report();
        $this->schedule_purge();
    }

    // ── Schedule helpers ─────────────────────────────────────────────────

    /**
     * Schedule (or reschedule) the email report cron based on current settings.
     * Called on construct AND after settings save.
     */
    public static function schedule_report() {
        $settings = get_option( 'actvt_watcher_settings', array() );
        $enabled  = ! empty( $settings['email_enabled'] );
        $interval = isset( $settings['email_interval'] ) ? $settings['email_interval'] : 'monthly';

        // Clear existing schedule
        $next = wp_next_scheduled( self::REPORT_HOOK );
        if ( $next ) {
            wp_unschedule_event( $next, self::REPORT_HOOK );
        }

        if ( ! $enabled ) {
            return; // email reports disabled — don't reschedule
        }

        // Map interval to WP cron recurrence
        $recurrence_map = array(
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'monthly',
        );
        $recurrence = isset( $recurrence_map[ $interval ] ) ? $recurrence_map[ $interval ] : 'monthly';

        // Compute first run time based on settings
        $time_str = isset( $settings['email_time'] ) ? $settings['email_time'] : '08:00';
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time_str ) );
        $first_run = mktime( $hour, $minute, 0 );
        if ( $first_run < time() ) {
            // Already passed today — defer to next occurrence
            $offsets = array( 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS, 'monthly' => 30 * DAY_IN_SECONDS );
            $first_run += $offsets[ $recurrence ] ?? DAY_IN_SECONDS;
        }

        wp_schedule_event( $first_run, $recurrence, self::REPORT_HOOK );
    }

    /**
     * Schedule the daily purge cron (always active; it skips if retention = 0).
     */
    public static function schedule_purge() {
        if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::PURGE_HOOK );
        }
    }

    // ── Cron callbacks ───────────────────────────────────────────────────

    /**
     * Send HTML email activity report.
     */
    public function run_report() {
        $settings = get_option( 'actvt_watcher_settings', array() );
        if ( empty( $settings['email_enabled'] ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'actvt_watcher_logs';

        $interval = isset( $settings['email_interval'] ) ? $settings['email_interval'] : 'monthly';

        // Determine date range
        switch ( $interval ) {
            case 'daily':
                $start = date( 'Y-m-d 00:00:00', strtotime( 'yesterday' ) );
                $end   = date( 'Y-m-d 23:59:59', strtotime( 'yesterday' ) );
                $label = 'Daily';
                break;
            case 'weekly':
                $start = date( 'Y-m-d 00:00:00', strtotime( 'last monday' ) );
                $end   = date( 'Y-m-d 23:59:59', strtotime( 'last sunday' ) );
                $label = 'Weekly';
                break;
            default: // monthly
                $start = date( 'Y-m-01 00:00:00', strtotime( 'last month' ) );
                $end   = date( 'Y-m-t 23:59:59',  strtotime( 'last month' ) );
                $label = 'Monthly';
                break;
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, user_id, COUNT(*) as cnt
             FROM {$table_name}
             WHERE timestamp BETWEEN %s AND %s
             GROUP BY event_type, user_id
             ORDER BY cnt DESC",
            $start, $end
        ) );

        if ( empty( $results ) ) {
            return; // nothing to report
        }

        // Build HTML email
        $rows_html = '';
        foreach ( $results as $row ) {
            $user   = get_userdata( $row->user_id );
            $uname  = $user ? esc_html( $user->user_login ) : 'Guest/System';
            $rows_html .= sprintf(
                '<tr><td style="padding:6px 12px; border-bottom:1px solid #eee;">%s</td><td style="padding:6px 12px; border-bottom:1px solid #eee;">%s</td><td style="padding:6px 12px; border-bottom:1px solid #eee; text-align:right;"><strong>%d</strong></td></tr>',
                esc_html( $row->event_type ), $uname, intval( $row->cnt )
            );
        }

        $site_name = get_bloginfo( 'name' );
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif; color:#1d2327; margin:0; padding:0;">
  <table width="600" cellpadding="0" cellspacing="0" style="margin:30px auto; border:1px solid #c3c4c7; border-radius:6px; overflow:hidden;">
    <tr><td style="background:#2271b1; padding:20px 24px;">
      <h2 style="color:#fff; margin:0; font-size:18px;">&#128203; {$label} Activity Report &mdash; {$site_name}</h2>
      <p style="color:#c8d8ea; margin:6px 0 0; font-size:13px;">{$start} &ndash; {$end}</p>
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
        Generated by ACTVT Watcher &bull; <a href="{$this->admin_url()}" style="color:#2271b1;">View full logs</a>
      </p>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $recipients_raw = isset( $settings['email_recipients'] ) ? $settings['email_recipients'] : get_option( 'admin_email' );
        $recipients     = array_filter( array_map( 'trim', explode( ',', $recipients_raw ) ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $subject = sprintf( '[%s] %s Activity Report — %s', $site_name, $label, date( 'Y-m-d' ) );
        wp_mail( $recipients, $subject, $html, $headers );
    }

    /**
     * Delete logs older than the configured retention period.
     */
    public function purge_old_logs() {
        $settings       = get_option( 'actvt_watcher_settings', array() );
        $retention_days = isset( $settings['log_retention_days'] ) ? intval( $settings['log_retention_days'] ) : 0;

        if ( $retention_days <= 0 ) {
            return; // 0 = keep forever
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'actvt_watcher_logs';
        $cutoff     = date( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

        // Auto-export before purge
        if ( ! empty( $settings['auto_export_before_purge'] ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE timestamp < %s ORDER BY timestamp DESC",
                $cutoff
            ), ARRAY_A );

            if ( ! empty( $rows ) ) {
                // Build CSV in memory
                ob_start();
                $buf = fopen( 'php://output', 'w' );
                fprintf( $buf, chr(0xEF) . chr(0xBB) . chr(0xBF) ); // UTF-8 BOM
                fputcsv( $buf, array( 'ID', 'Timestamp', 'User ID', 'Username', 'User Role', 'Event Type', 'Action', 'Object ID', 'Metadata', 'IP Address' ) );
                foreach ( $rows as $row ) {
                    $u = get_userdata( $row['user_id'] );
                    fputcsv( $buf, array(
                        $row['id'], $row['timestamp'], $row['user_id'],
                        $u ? $u->user_login : 'Guest', $row['user_role'],
                        $row['event_type'], $row['action'], $row['object_id'],
                        $row['metadata'], $row['ip_address'],
                    ) );
                }
                fclose( $buf );
                $csv_content = ob_get_clean();

                $site_name  = get_bloginfo( 'name' );
                $recipients_raw = isset( $settings['email_recipients'] ) ? $settings['email_recipients'] : get_option( 'admin_email' );
                $recipients     = array_filter( array_map( 'trim', explode( ',', $recipients_raw ) ) );
                $filename       = 'actvt-pre-purge-' . date( 'Y-m-d' ) . '.csv';
                $subject        = sprintf( '[%s] Activity Log Archive — Pre-purge Export', $site_name );
                $body           = "Please find attached a CSV export of the log entries that are about to be deleted (older than {$retention_days} days).";
                $headers        = array( "Content-Type: text/plain; charset=UTF-8" );
                $attachments    = array( sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename );

                file_put_contents( $attachments[0], $csv_content );
                wp_mail( $recipients, $subject, $body, $headers, $attachments );
                @unlink( $attachments[0] );
            }
        }

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE timestamp < %s", $cutoff ) );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function admin_url() {
        return admin_url( 'admin.php?page=actvt-watcher' );
    }
}
