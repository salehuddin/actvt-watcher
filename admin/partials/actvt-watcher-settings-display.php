<?php
// Guard
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}

$s = get_option( 'actvt_watcher_settings', array() );

// ── Helpers to read settings with defaults ─────────────────────────────────
function actvt_s( $key, $default = '' ) {
    $s = get_option( 'actvt_watcher_settings', array() );
    return isset( $s[ $key ] ) ? $s[ $key ] : $default;
}

$excluded_event_types    = (array) actvt_s( 'excluded_event_types', array() );
$excluded_user_ids       = array_map( 'intval', (array) actvt_s( 'excluded_user_ids', array() ) );
$excluded_ips            = actvt_s( 'excluded_ips', '' );
$excluded_post_types     = (array) actvt_s( 'excluded_post_types', array() );
$min_role_to_log         = actvt_s( 'min_role_to_log', '' );

$log_retention_days      = intval( actvt_s( 'log_retention_days', 0 ) );
$max_log_rows            = intval( actvt_s( 'max_log_rows', 0 ) );
$auto_export_before_purge = ! empty( $s['auto_export_before_purge'] );

$email_enabled           = ! empty( $s['email_enabled'] );
$email_interval          = actvt_s( 'email_interval', 'monthly' );
$email_recipients        = actvt_s( 'email_recipients', get_option( 'admin_email' ) );
$email_time              = actvt_s( 'email_time', '08:00' );

$security_alerts_enabled   = ! empty( $s['security_alerts_enabled'] );
$security_alert_recipients = actvt_s( 'security_alert_recipients', '' );
$security_alert_threshold  = intval( actvt_s( 'security_alert_threshold', 5 ) );
$security_alert_events     = (array) actvt_s( 'security_alert_events', array() );

$default_per_page        = intval( actvt_s( 'default_per_page', 50 ) );
$default_date_range      = actvt_s( 'default_date_range', '' );
$metadata_display        = actvt_s( 'metadata_display', 'formatted' );

$all_event_types = array( 'auth', 'content', 'system', 'security', 'general' );
$all_wp_users    = get_users( array( 'fields' => array( 'ID', 'user_login' ), 'orderby' => 'login' ) );
$all_post_types  = get_post_types( array( 'public' => true ), 'objects' );
$wp_roles        = wp_roles()->get_names();

$saved  = isset( $_GET['saved'] ) ? intval( $_GET['saved'] )   : 0;
$purged = isset( $_GET['purged'] ) ? intval( $_GET['purged'] ) : -1;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="wrap actvt-settings-wrap">
<style>
/* ── Layout ────────────────────────────────────────────── */
.actvt-settings-wrap h1{display:flex;align-items:center;gap:8px;margin-bottom:20px}
.actvt-settings-wrap h1 .dashicons{color:#2271b1;font-size:26px;width:26px;height:26px}
.actvt-settings-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:16px}
.actvt-settings-card-header{padding:14px 20px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;gap:8px}
.actvt-settings-card-header h2{margin:0;font-size:14px;font-weight:600;color:#1d2327}
.actvt-settings-card-header .dashicons{color:#2271b1}
.actvt-settings-card-body{padding:20px}
/* ── Fields ────────────────────────────────────────────── */
.actvt-sf{margin-bottom:18px}
.actvt-sf:last-child{margin-bottom:0}
.actvt-sf-label{display:block;font-weight:600;margin-bottom:5px;font-size:13px}
.actvt-sf .description{font-size:12px;color:#646970;margin-top:4px;display:block}
.actvt-cb-grid{display:flex;flex-wrap:wrap;gap:8px 20px}
.actvt-cb-grid label{display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer}
.actvt-radio-row{display:flex;flex-wrap:wrap;gap:8px 20px}
.actvt-radio-row label{display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer}
.actvt-inline-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px}
.actvt-inline-row .actvt-sf{margin-bottom:0}
/* ── Toggle ────────────────────────────────────────────── */
.actvt-toggle-row{display:flex;align-items:center;gap:10px}
.actvt-toggle{position:relative;display:inline-block;width:40px;height:22px}
.actvt-toggle input{opacity:0;width:0;height:0}
.actvt-toggle-slider{position:absolute;inset:0;background:#c3c4c7;border-radius:22px;transition:.2s;cursor:pointer}
.actvt-toggle-slider:before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.actvt-toggle input:checked+.actvt-toggle-slider{background:#2271b1}
.actvt-toggle input:checked+.actvt-toggle-slider:before{transform:translateX(18px)}
/* ── Actions bar ───────────────────────────────────────── */
.actvt-settings-actions{display:flex;align-items:center;gap:10px;padding:16px 20px;background:#f6f7f7;border-top:1px solid #dcdcde;border-radius:0 0 4px 4px}
/* ── Select2 ───────────────────────────────────────────── */
.select2-container--default .select2-selection--multiple{min-height:34px;border-color:#8c8f94}
/* ── Alert badge ───────────────────────────────────────── */
.actvt-badge-alert{display:inline-block;background:#d63638;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;text-transform:uppercase;vertical-align:middle;margin-left:6px}
/* ── Sticky section nav ─────────────────────────────────── */
.actvt-settings-layout{display:flex;align-items:flex-start;gap:20px}
.actvt-settings-nav{
    width:178px;
    flex-shrink:0;
    position:sticky;
    top:40px;
    background:#fff;
    border:1px solid #c3c4c7;
    border-radius:4px;
    padding:8px 0 12px;
    font-size:13px;
}
.actvt-settings-nav .actvt-nav-title{
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:#8c8f94;
    padding:6px 14px 4px;
}
.actvt-settings-nav a{
    display:flex;
    align-items:center;
    gap:6px;
    padding:5px 14px;
    color:#3c434a;
    text-decoration:none;
    border-left:3px solid transparent;
    transition:background .12s,border-color .12s,color .12s;
    line-height:1.4;
}
.actvt-settings-nav a:hover{background:#f0f6fc;color:#2271b1}
.actvt-settings-nav a.actvt-nav-active{border-left-color:#2271b1;color:#2271b1;background:#f0f6fc;font-weight:600}
.actvt-settings-nav a .dashicons{font-size:14px;width:14px;height:14px;flex-shrink:0;color:inherit}
.actvt-settings-nav hr{margin:8px 0;border:none;border-top:1px solid #f0f0f1}
.actvt-settings-nav .actvt-nav-save{padding:8px 12px 0}
.actvt-settings-nav .actvt-nav-save .button{width:100%;text-align:center;justify-content:center}
.actvt-settings-cards{flex:1;min-width:0}
</style>

<h1><span class="dashicons dashicons-admin-settings"></span>ACTVT Watcher &mdash; Settings</h1>

<?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
<?php if ( isset( $_GET['saved'] ) && $_GET['saved'] === 'imported' ) : ?><div class="notice notice-success is-dismissible"><p>Settings imported successfully.</p></div><?php endif; ?>
<?php if ( isset( $_GET['import_error'] ) ) : ?>
<div class="notice notice-error is-dismissible"><p><?php echo $_GET['import_error'] === 'no_file' ? 'No file was uploaded.' : 'Invalid settings file. Make sure it was exported from ACTVT Watcher.'; ?></p></div>
<?php endif; ?>
<?php if ( $purged >= 0 ) : ?><div class="notice notice-success is-dismissible"><p><?php echo $purged === 0 ? 'No logs matched the retention window.' : esc_html( $purged ) . ' log entries purged.'; ?></p></div><?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="actvt-main-settings-form">
    <?php wp_nonce_field( 'actvt_save_settings' ); ?>
    <input type="hidden" name="action" value="actvt_save_settings">

<div class="actvt-settings-layout">

<!-- ── Sticky section nav ── -->
<nav class="actvt-settings-nav" id="actvt-section-nav" aria-label="Settings sections">
    <div class="actvt-nav-title">Jump to section</div>
    <a href="#actvt-s-exclusions"><span class="dashicons dashicons-dismiss"></span>Logging Exclusions</a>
    <a href="#actvt-s-storage"><span class="dashicons dashicons-database"></span>Storage &amp; Retention</a>
    <a href="#actvt-s-mirror"><span class="dashicons dashicons-media-text"></span>Mirror to File</a>
    <a href="#actvt-s-security"><span class="dashicons dashicons-shield-alt"></span>Security Alerts</a>
    <a href="#actvt-s-email"><span class="dashicons dashicons-email-alt"></span>Email Reports</a>
    <a href="#actvt-s-metadata"><span class="dashicons dashicons-code-standards"></span>Metadata Level</a>
    <a href="#actvt-s-viewer"><span class="dashicons dashicons-visibility"></span>Viewer Defaults</a>
    <hr>
    <a href="#actvt-s-exportimport"><span class="dashicons dashicons-migrate"></span>Export &amp; Import</a>
    <div class="actvt-nav-save">
        <button type="submit" form="actvt-main-settings-form" class="button button-primary">
            <span class="dashicons dashicons-saved" style="font-size:14px;width:14px;height:14px;line-height:1;vertical-align:text-bottom;margin-right:3px;"></span>
            Save Settings
        </button>
    </div>
</nav>

<!-- ── Cards column ── -->
<div class="actvt-settings-cards">

    <!-- ══════════════════════════════════════
         1. LOGGING EXCLUSIONS
    ════════════════════════════════════════ -->
    <div class="actvt-settings-card" id="actvt-s-exclusions">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-dismiss"></span>
            <h2>Logging Exclusions</h2>
        </div>
        <div class="actvt-settings-card-body">

            <!-- Event types -->
            <div class="actvt-sf">
                <label class="actvt-sf-label">Exclude Event Types</label>
                <div class="actvt-cb-grid">
                    <?php foreach ( $all_event_types as $et ) : ?>
                    <label>
                        <input type="checkbox" name="excluded_event_types[]" value="<?php echo esc_attr( $et ); ?>"
                               <?php checked( in_array( $et, $excluded_event_types, true ) ); ?>>
                        <?php echo esc_html( ucfirst( $et ) ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <span class="description">Events of checked types will <strong>not</strong> be logged.</span>
            </div>

            <!-- Users -->
            <div class="actvt-sf">
                <label class="actvt-sf-label" for="actvt-excluded-users">Exclude Users</label>
                <select name="excluded_user_ids[]" id="actvt-excluded-users" multiple style="width:100%;max-width:480px;">
                    <?php foreach ( $all_wp_users as $u ) : ?>
                    <option value="<?php echo intval( $u->ID ); ?>" <?php echo in_array( intval( $u->ID ), $excluded_user_ids, true ) ? 'selected' : ''; ?>>
                        <?php echo esc_html( $u->user_login ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">Activity from these users will not be recorded.</span>
            </div>

            <!-- IP addresses -->
            <div class="actvt-sf">
                <label class="actvt-sf-label" for="actvt-excluded-ips">Exclude IP Addresses</label>
                <textarea name="excluded_ips" id="actvt-excluded-ips" rows="4" style="width:100%;max-width:480px;font-family:monospace;"><?php echo esc_textarea( $excluded_ips ); ?></textarea>
                <span class="description">One IP per line (or comma-separated). Useful for monitoring bots, office IPs, and load balancers.</span>
            </div>

            <!-- Post types -->
            <div class="actvt-sf">
                <label class="actvt-sf-label">Exclude Post Types</label>
                <div class="actvt-cb-grid">
                    <?php foreach ( $all_post_types as $pt ) : ?>
                    <label>
                        <input type="checkbox" name="excluded_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
                               <?php checked( in_array( $pt->name, $excluded_post_types, true ) ); ?>>
                        <?php echo esc_html( $pt->labels->singular_name ); ?>
                        <span style="color:#8c8f94;font-size:11px;">(<?php echo esc_html( $pt->name ); ?>)</span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <span class="description">Content events for these post types will be skipped.</span>
            </div>

            <!-- Minimum role -->
            <div class="actvt-sf">
                <label class="actvt-sf-label" for="actvt-min-role">Minimum Role to Log</label>
                <select name="min_role_to_log" id="actvt-min-role">
                    <option value="">All users (including guests)</option>
                    <?php foreach ( $wp_roles as $slug => $label ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $min_role_to_log, $slug ); ?>>
                        <?php echo esc_html( $label ); ?> and above
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">Only log activity from users at or above this role level.</span>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════════════
         2. STORAGE & RETENTION
    ════════════════════════════════════════ -->
    <div class="actvt-settings-card" id="actvt-s-storage">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-database"></span>
            <h2>Storage &amp; Retention</h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-inline-row">
                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-retention">Delete logs older than</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="log_retention_days" id="actvt-retention"
                               value="<?php echo esc_attr( $log_retention_days ); ?>" min="0" style="width:90px;">
                        <span style="font-size:13px;">days &nbsp;<em style="color:#8c8f94;">(0 = keep forever)</em></span>
                    </div>
                </div>

                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-maxrows">Maximum log rows</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="max_log_rows" id="actvt-maxrows"
                               value="<?php echo esc_attr( $max_log_rows ); ?>" min="0" style="width:110px;">
                        <span style="font-size:13px;">entries &nbsp;<em style="color:#8c8f94;">(0 = no limit)</em></span>
                    </div>
                </div>
            </div>
            <span class="description" style="margin-top:8px;margin-bottom:16px;display:block;">Oldest entries are removed automatically when the cap is exceeded (checked on every new log insert).</span>

            <!-- Auto-export -->
            <div class="actvt-sf">
                <label class="actvt-sf-label">Auto-export Before Purge</label>
                <div class="actvt-toggle-row">
                    <label class="actvt-toggle">
                        <input type="checkbox" name="auto_export_before_purge" value="1"
                               <?php checked( $auto_export_before_purge ); ?>>
                        <span class="actvt-toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:#646970;">Email a CSV of about-to-be-deleted logs before the daily purge runs</span>
                </div>
                <span class="description">Uses the same recipients as Email Reports. Requires a retention period > 0.</span>
            </div>

            <!-- Purge now -->
            <div class="actvt-sf">
                <label class="actvt-sf-label">Purge Now</label>
                <?php if ( $log_retention_days > 0 ) : ?>
                <button type="submit" form="actvt-purge-form" class="button button-secondary"
                        onclick="return confirm('Delete all logs older than <?php echo intval( $log_retention_days ); ?> days?');">
                    <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;line-height:1;margin-right:3px;vertical-align:text-bottom;"></span>
                    Purge Old Logs
                </button>
                <?php else : ?>
                <button type="button" class="button button-secondary" disabled>Purge Old Logs</button>
                <span style="font-size:12px;color:#8c8f94;margin-left:8px;">Set a retention period first.</span>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════════════
         2b. MIRROR TO LOG FILE
    ════════════════════════════════════════ -->
    <?php
        $log_file_enabled = actvt_s( 'log_file_enabled', 0 );
        $log_file_path    = actvt_s( 'log_file_path', '' );
        $default_log_dir  = WP_CONTENT_DIR . '/uploads/actvt-logs';
    ?>
    <div class="actvt-settings-card" id="actvt-s-mirror">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-media-text"></span>
            <h2>Mirror Logs to Flat File</h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-sf">
                <label class="actvt-sf-label">Enable file mirroring</label>
                <div class="actvt-toggle-row">
                    <label class="actvt-toggle">
                        <input type="checkbox" name="log_file_enabled" value="1"
                               id="actvt-log-file-enabled"
                               <?php checked( $log_file_enabled, 1 ); ?>
                               onchange="document.getElementById('actvt-log-file-path-row').style.display=this.checked?'flex':'none';">
                        <span class="actvt-toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:#646970;">Write a JSON line per event to a rotating daily <code>.log</code> file on disk</span>
                </div>
            </div>

            <div class="actvt-sf" id="actvt-log-file-path-row"
                 style="<?php echo $log_file_enabled ? 'display:flex;' : 'display:none;'; ?>">
                <label class="actvt-sf-label" for="actvt-log-file-path">Log directory path</label>
                <div>
                    <input type="text" name="log_file_path" id="actvt-log-file-path"
                           value="<?php echo esc_attr( $log_file_path ); ?>"
                           placeholder="<?php echo esc_attr( $default_log_dir ); ?>"
                           style="width:100%;max-width:480px;">
                    <p class="description">Leave blank to use the default: <code><?php echo esc_html( $default_log_dir ); ?></code>.<br>
                    Files are named <code>actvt-YYYY-MM-DD.log</code>. An <code>.htaccess</code> is written automatically to block direct web access.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════════════
         3. SECURITY ALERTS
    ════════════════════════════════════════ -->
    <div class="actvt-settings-card" id="actvt-s-security">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-shield-alt" style="color:#d63638;"></span>
            <h2>Security Alerts <span class="actvt-badge-alert">Instant</span></h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-sf">
                <label class="actvt-sf-label">Enable Instant Alerts</label>
                <div class="actvt-toggle-row">
                    <label class="actvt-toggle">
                        <input type="checkbox" name="security_alerts_enabled" value="1" id="actvt-alerts-toggle"
                               <?php checked( $security_alerts_enabled ); ?>
                               onchange="document.getElementById('actvt-alerts-options').style.display=this.checked?'':'none';">
                        <span class="actvt-toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:#646970;">Send an immediate email when a critical security event occurs</span>
                </div>
            </div>

            <div id="actvt-alerts-options" <?php echo $security_alerts_enabled ? '' : 'style="display:none;"'; ?>>

                <div class="actvt-sf">
                    <label class="actvt-sf-label">Alert Events</label>
                    <div class="actvt-cb-grid">
                        <?php foreach ( Actvt_Watcher_DB::$alert_actions as $act_slug => $act_label ) : ?>
                        <label>
                            <input type="checkbox" name="security_alert_events[]" value="<?php echo esc_attr( $act_slug ); ?>"
                                   <?php checked( in_array( $act_slug, $security_alert_events, true ) ); ?>>
                            <?php echo esc_html( $act_label ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-alert-threshold">Failed Login Threshold</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="security_alert_threshold" id="actvt-alert-threshold"
                               value="<?php echo esc_attr( $security_alert_threshold ); ?>" min="1" style="width:80px;">
                        <span style="font-size:13px;">failures from the same IP within 1 hour before alerting</span>
                    </div>
                </div>

                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-alert-recipients">Alert Recipients</label>
                    <input type="text" name="security_alert_recipients" id="actvt-alert-recipients"
                           value="<?php echo esc_attr( $security_alert_recipients ); ?>"
                           style="width:100%;max-width:480px;"
                           placeholder="security@example.com, admin@example.com">
                    <span class="description">Comma-separated. Leave blank to use the admin email.</span>
                </div>

            </div><!-- #actvt-alerts-options -->
        </div>
    </div>

    <!-- ══════════════════════════════════════
         4. EMAIL REPORTS
    ════════════════════════════════════════ -->
    <div class="actvt-settings-card" id="actvt-s-email">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-email-alt"></span>
            <h2>Email Reports</h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-sf">
                <label class="actvt-sf-label">Enable Email Reports</label>
                <div class="actvt-toggle-row">
                    <label class="actvt-toggle">
                        <input type="checkbox" name="email_enabled" value="1" id="actvt-email-toggle"
                               <?php checked( $email_enabled ); ?>
                               onchange="document.getElementById('actvt-email-options').style.display=this.checked?'':'none';">
                        <span class="actvt-toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:#646970;">Send periodic activity summary emails</span>
                </div>
            </div>

            <div id="actvt-email-options" <?php echo $email_enabled ? '' : 'style="display:none;"'; ?>>
                <div class="actvt-sf">
                    <label class="actvt-sf-label">Interval</label>
                    <div class="actvt-radio-row">
                        <?php foreach ( array( 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly' ) as $val => $lbl ) : ?>
                        <label><input type="radio" name="email_interval" value="<?php echo $val; ?>" <?php checked( $email_interval, $val ); ?>><?php echo $lbl; ?></label>
                        <?php endforeach; ?>
                    </div>
                    <span class="description">Daily = yesterday &nbsp;|&nbsp; Weekly = last Mon–Sun &nbsp;|&nbsp; Monthly = last calendar month</span>
                </div>

                <div class="actvt-inline-row">
                    <div class="actvt-sf">
                        <label class="actvt-sf-label" for="actvt-email-time">Send Time</label>
                        <input type="time" name="email_time" id="actvt-email-time" value="<?php echo esc_attr( $email_time ); ?>">
                        <span class="description">Server local time</span>
                    </div>
                </div>

                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-email-recipients">Recipients</label>
                    <input type="text" name="email_recipients" id="actvt-email-recipients"
                           value="<?php echo esc_attr( $email_recipients ); ?>"
                           style="width:100%;max-width:480px;" placeholder="admin@example.com, another@example.com">
                    <span class="description">Comma-separated email addresses. Also used for Auto-export attachments.</span>
                </div>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════════════
         5. METADATA DETAIL LEVEL
    ════════════════════════════════════════ -->
    <?php $metadata_detail_level = actvt_s( 'metadata_detail_level', 'simple' ); ?>
    <div class="actvt-settings-card" id="actvt-s-metadata">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-code-standards"></span>
            <h2>Metadata Detail Level</h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-sf">
                <div class="actvt-radio-row" style="gap:24px;">
                    <label style="align-items:flex-start;gap:10px;">
                        <input type="radio" name="metadata_detail_level" value="simple"
                               <?php checked( $metadata_detail_level, 'simple' ); ?> style="margin-top:3px;">
                        <span>
                            <strong>Simple</strong><br>
                            <span style="font-size:12px;color:#646970;">Minimal fields — lower storage overhead, faster insert. Recommended for most sites.</span>
                        </span>
                    </label>
                    <label style="align-items:flex-start;gap:10px;">
                        <input type="radio" name="metadata_detail_level" value="detailed"
                               <?php checked( $metadata_detail_level, 'detailed' ); ?> style="margin-top:3px;">
                        <span>
                            <strong>Detailed</strong><br>
                            <span style="font-size:12px;color:#646970;">Full context per event — richer audit trail, slightly larger metadata column.</span>
                        </span>
                    </label>
                </div>
            </div>

            <table style="width:100%;max-width:680px;border-collapse:collapse;font-size:12px;margin-top:8px;">
                <thead>
                    <tr style="background:#f6f7f7;">
                        <th style="padding:7px 10px;text-align:left;border:1px solid #dcdcde;">Event</th>
                        <th style="padding:7px 10px;text-align:left;border:1px solid #dcdcde;">Simple fields</th>
                        <th style="padding:7px 10px;text-align:left;border:1px solid #dcdcde;">Extra in Detailed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $compare = array(
                        'Login success'      => array( 'username',              'email, role, user_agent, referrer' ),
                        'Login failed'       => array( 'attempted_username',    'user_agent, referrer, failures in last hour' ),
                        'Profile update'     => array( '"Profile fields updated"', 'changed_fields (before → after), email' ),
                        'User registered'    => array( 'login, role',           'email, created_by, registration_date, user_agent' ),
                        'Role changed'       => array( 'username, old→new role', 'user_email, changed_by' ),
                        'User deleted'       => array( 'login, email, reassign', 'role, registered_date, deleted_by, post_count' ),
                        'Password events'    => array( 'username',              'changed_by, self_change, user_agent' ),
                        'Post updated'       => array( 'title, type, status',   'changed_fields, old_title, word_count before/after, author, permalink, categories, tags' ),
                        'Post deleted'       => array( 'title, type',           'post_status, author, published_date, permalink, comment_count, deleted_by' ),
                        'Media uploaded'     => array( 'filename',              'filesize, MIME type, dimensions, URL, uploaded_by' ),
                        'Comment posted'     => array( 'author, post_id, status', 'post_title, author_email, comment excerpt' ),
                        'Post status change' => array( 'title, type, old→new',  'author, permalink, word_count, changed_by' ),
                        'Plugin activated'   => array( 'plugin file',           'name, version, author' ),
                        'Theme switched'     => array( 'new theme, old theme',  'version of both themes, author' ),
                        'Core updated'       => array( 'new version',           'old version, PHP version, DB version' ),
                        'Option updated'     => array( 'option name, "Value changed"', 'old_value, new_value (truncated)' ),
                        'Upgrader complete'  => array( 'type, action, files',   'name + version of each updated item' ),
                        'File editors'       => array( 'file, plugin/theme slug', 'plugin name + version, theme display name' ),
                    );
                    $alt = false;
                    foreach ( $compare as $event => $row ) :
                        $alt = ! $alt;
                    ?>
                    <tr style="<?php echo $alt ? 'background:#fafafa;' : ''; ?>">
                        <td style="padding:6px 10px;border:1px solid #dcdcde;font-weight:500;"><?php echo esc_html( $event ); ?></td>
                        <td style="padding:6px 10px;border:1px solid #dcdcde;color:#3c434a;"><?php echo esc_html( $row[0] ); ?></td>
                        <td style="padding:6px 10px;border:1px solid #dcdcde;color:#2271b1;"><?php echo esc_html( $row[1] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </div>

    <!-- ══════════════════════════════════════
         6. LOG VIEWER DEFAULTS
    ════════════════════════════════════════ -->
    <div class="actvt-settings-card" id="actvt-s-viewer">
        <div class="actvt-settings-card-header">
            <span class="dashicons dashicons-visibility"></span>
            <h2>Log Viewer Defaults</h2>
        </div>
        <div class="actvt-settings-card-body">

            <div class="actvt-inline-row">
                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-default-per-page">Rows per page</label>
                    <select name="default_per_page" id="actvt-default-per-page">
                        <?php foreach ( array( 25, 50, 100, 200 ) as $n ) : ?>
                        <option value="<?php echo $n; ?>" <?php selected( $default_per_page, $n ); ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="actvt-sf">
                    <label class="actvt-sf-label" for="actvt-default-date-range">Default date range</label>
                    <select name="default_date_range" id="actvt-default-date-range">
                        <option value="" <?php selected( $default_date_range, '' ); ?>>All time</option>
                        <option value="this_month"   <?php selected( $default_date_range, 'this_month' ); ?>>This month</option>
                        <option value="last_month"   <?php selected( $default_date_range, 'last_month' ); ?>>Last month</option>
                        <option value="last_3_months" <?php selected( $default_date_range, 'last_3_months' ); ?>>Last 3 months</option>
                    </select>
                </div>
            </div>

            <div class="actvt-sf">
                <label class="actvt-sf-label">Metadata display</label>
                <div class="actvt-radio-row">
                    <label>
                        <input type="radio" name="metadata_display" value="formatted"
                               <?php checked( $metadata_display, 'formatted' ); ?>>
                        Formatted key&thinsp;/&thinsp;value
                    </label>
                    <label>
                        <input type="radio" name="metadata_display" value="raw"
                               <?php checked( $metadata_display, 'raw' ); ?>>
                        Raw JSON
                    </label>
                </div>
                <span class="description">Default view for the Metadata column. Toggle individual rows inline with the <code>&lt;/&gt;</code> button.</span>
            </div>

        </div>

        <div class="actvt-settings-actions">
            <button type="submit" class="button button-primary">Save Settings</button>
            <span style="font-size:12px;color:#646970;">
                Next report: <?php
                    $nr = wp_next_scheduled( Actvt_Watcher_Cron::REPORT_HOOK );
                    echo $nr ? esc_html( wp_date( 'Y-m-d H:i', $nr ) ) : '—';
                ?>
                &bull;
                Next purge: <?php
                    $np = wp_next_scheduled( Actvt_Watcher_Cron::PURGE_HOOK );
                    echo $np ? esc_html( wp_date( 'Y-m-d H:i', $np ) ) : '—';
                ?>
            </span>
        </div>
    </div>

</div><!-- .actvt-settings-cards -->
</div><!-- .actvt-settings-layout -->

</form><!-- end main settings form -->

<!-- ══════════════════════════════════════
     EXPORT / IMPORT SETTINGS (outside main form, inside .actvt-settings-layout)
════════════════════════════════════════ -->
<!-- We need a second mini-layout row so export/import card aligns with the cards column -->
<div class="actvt-settings-layout" style="margin-top:0;">
<div style="width:178px;flex-shrink:0;"></div><!-- spacer matching nav width + gap -->
<div class="actvt-settings-cards">
<div class="actvt-settings-card" style="margin-top:0;" id="actvt-s-exportimport">
    <div class="actvt-settings-card-header">
        <span class="dashicons dashicons-migrate"></span>
        <h2>Export &amp; Import Settings</h2>
    </div>
    <div class="actvt-settings-card-body">

        <div class="actvt-sf">
            <label class="actvt-sf-label">Export</label>
            <div>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=actvt_export_settings' ), 'actvt_export_settings' ) ); ?>"
                   class="button button-secondary">
                    <span class="dashicons dashicons-download" style="font-size:15px;width:15px;height:15px;line-height:1;vertical-align:text-bottom;margin-right:3px;"></span>
                    Download Settings JSON
                </a>
                <p class="description">Downloads all current settings as a <code>.json</code> file. Use it as a backup or to clone settings to another site.</p>
            </div>
        </div>

        <div class="actvt-sf">
            <label class="actvt-sf-label">Import</label>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  enctype="multipart/form-data" id="actvt-import-form"
                  onsubmit="return confirm('This will overwrite your current settings. Continue?');">
                <?php wp_nonce_field( 'actvt_import_settings' ); ?>
                <input type="hidden" name="action" value="actvt_import_settings">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <input type="file" name="actvt_settings_file" accept=".json" required
                           style="font-size:13px;">
                    <button type="submit" class="button button-secondary">
                        <span class="dashicons dashicons-upload" style="font-size:15px;width:15px;height:15px;line-height:1;vertical-align:text-bottom;margin-right:3px;"></span>
                        Import Settings
                    </button>
                </div>
                <p class="description">Select a <code>.json</code> file previously exported from this plugin. Imported values are merged into the current settings.</p>
            </form>
        </div>

    </div>
</div>

</div><!-- .actvt-settings-cards (export/import) -->
</div><!-- .actvt-settings-layout (export/import row) -->

<?php if ( $log_retention_days > 0 ) : ?>
<!-- Standalone purge form — must live OUTSIDE the main form to avoid invalid nested-form HTML -->
<form id="actvt-purge-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
    <?php wp_nonce_field( 'actvt_purge_now' ); ?>
    <input type="hidden" name="action" value="actvt_purge_now">
</form>
<?php endif; ?>

</div><!-- .actvt-settings-wrap -->

<script>
jQuery(function($) {
    // Select2 for user exclusion
    $('#actvt-excluded-users').select2({
        placeholder: 'Select users to exclude…',
        allowClear:  true,
        width:       'element'
    });

    // ── Active nav highlight via IntersectionObserver ──
    var navLinks = document.querySelectorAll('#actvt-section-nav a[href^="#"]');
    if ( navLinks.length && 'IntersectionObserver' in window ) {
        var sectionIds = Array.from(navLinks).map(function(a){ return a.getAttribute('href').slice(1); });
        var activeSectionId = sectionIds[0];

        function setActive(id) {
            navLinks.forEach(function(a){
                var match = a.getAttribute('href') === '#' + id;
                a.classList.toggle('actvt-nav-active', match);
            });
        }
        setActive(activeSectionId);

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                if (e.isIntersecting) {
                    activeSectionId = e.target.id;
                    setActive(activeSectionId);
                }
            });
        }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });

        sectionIds.forEach(function(id){
            var el = document.getElementById(id);
            if (el) observer.observe(el);
        });

        // Smooth-scroll on click
        navLinks.forEach(function(a){
            a.addEventListener('click', function(e){
                var target = document.getElementById(this.getAttribute('href').slice(1));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    history.replaceState(null, '', this.getAttribute('href'));
                }
            });
        });
    }
});
</script>
