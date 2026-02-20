<?php
// Guard: only admins can access this page.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}

global $wpdb;
$table_name = $wpdb->prefix . 'actvt_watcher_logs';

// ─── Delete handler (runs before any output) ────────────────────────────
$delete_notice = '';

// Single-row delete: ?action=delete_log&log_id=N&_wpnonce=...
if (
    isset( $_GET['action'], $_GET['log_id'] ) &&
    $_GET['action'] === 'delete_log' &&
    wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'actvt_delete_log_' . intval( $_GET['log_id'] ) )
) {
    $del_id = intval( $_GET['log_id'] );
    $wpdb->delete( $table_name, array( 'id' => $del_id ), array( '%d' ) );
    $delete_notice = 'deleted_one';
    // Redirect to strip the action from the URL
    $redirect = remove_query_arg( array( 'action', 'log_id', '_wpnonce' ) );
    wp_redirect( add_query_arg( 'actvt_deleted', 1, $redirect ) );
    exit;
}

// Bulk delete: POST action=bulk_delete with log_ids[]
if (
    isset( $_POST['action'], $_POST['log_ids'] ) &&
    $_POST['action'] === 'bulk_delete' &&
    wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'actvt_bulk_delete' ) &&
    is_array( $_POST['log_ids'] )
) {
    $ids = array_map( 'intval', $_POST['log_ids'] );
    $ids = array_filter( $ids ); // remove zeros
    if ( $ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE id IN ({$placeholders})", ...$ids ) );
    }
    wp_redirect( add_query_arg( 'actvt_deleted', count( $ids ), remove_query_arg( 'action' ) ) );
    exit;
}

$show_notice = isset( $_GET['actvt_deleted'] ) ? intval( $_GET['actvt_deleted'] ) : 0;

// ─── Sanitize inputs ──────────────────────────────────────────────────────────
$all_event_types = array( 'auth', 'content', 'system', 'security', 'general' );

// Multi-select: array of selected event types; empty/unset = all selected
if ( isset( $_GET['event_type'] ) && is_array( $_GET['event_type'] ) ) {
    $filter_event_types = array_filter(
        array_map( 'sanitize_text_field', $_GET['event_type'] ),
        function( $t ) use ( $all_event_types ) { return in_array( $t, $all_event_types, true ); }
    );
} else {
    $filter_event_types = array(); // empty = all
}
$filter_user_id    = isset( $_GET['filter_user'] ) ? intval( $_GET['filter_user'] ) : 0;
$_actvt_settings   = get_option( 'actvt_watcher_settings', array() );

$active_meta_mode  = isset( $_GET['metadata_format'] ) && in_array( $_GET['metadata_format'], array( 'formatted', 'raw' ), true )
                     ? $_GET['metadata_format'] 
                     : ( isset( $_actvt_settings['metadata_display'] ) ? $_actvt_settings['metadata_display'] : 'formatted' );

$_default_period   = isset( $_actvt_settings['default_date_range'] ) ? $_actvt_settings['default_date_range'] : '';
$filter_period     = isset( $_GET['period'] ) ? sanitize_text_field( $_GET['period'] ) : $_default_period;
$filter_date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
$filter_date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';
$search_query      = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

$allowed_per_page  = array( 25, 50, 100, 200 );
$_default_per_page = isset( $_actvt_settings['default_per_page'] ) && in_array( intval( $_actvt_settings['default_per_page'] ), $allowed_per_page ) ? intval( $_actvt_settings['default_per_page'] ) : 50;
$per_page          = isset( $_GET['per_page'] ) && in_array( intval( $_GET['per_page'] ), $allowed_per_page )
                     ? intval( $_GET['per_page'] ) : $_default_per_page;

// ─── Resolve period presets → date range ─────────────────────────────────────
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

// ─── Build WHERE clause ───────────────────────────────────────────────────────
$where_clauses = array( '1=1' );
$prepare_args  = array();

// Multi-select event type filter — only apply if NOT all types are selected
if ( ! empty( $filter_event_types ) && count( $filter_event_types ) < count( $all_event_types ) ) {
    $placeholders    = implode( ', ', array_fill( 0, count( $filter_event_types ), '%s' ) );
    $where_clauses[] = "event_type IN ( $placeholders )";
    foreach ( $filter_event_types as $et ) {
        $prepare_args[] = $et;
    }
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

// ─── Pagination ───────────────────────────────────────────────────────────────
$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$offset       = ( $current_page - 1 ) * $per_page;

$count_sql   = "SELECT COUNT(id) FROM {$table_name} WHERE {$where_sql}";
$total_items = ! empty( $prepare_args )
    ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$prepare_args ) )
    : $wpdb->get_var( $count_sql );

$total_pages = max( 1, ceil( $total_items / $per_page ) );

$query_args = array_merge( $prepare_args, array( $per_page, $offset ) );
$items_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
$items      = ! empty( $query_args )
    ? $wpdb->get_results( $wpdb->prepare( $items_sql, ...$query_args ) )
    : $wpdb->get_results( $items_sql );

// ─── Summary counts (all-time, unfiltered) ────────────────────────────────────
$summary     = $wpdb->get_results( "SELECT event_type, COUNT(*) as count FROM {$table_name} GROUP BY event_type" );
$totals      = array( 'auth' => 0, 'content' => 0, 'system' => 0, 'security' => 0 );
$grand_total = 0;
foreach ( $summary as $row ) {
    $grand_total += $row->count;
    if ( isset( $totals[ $row->event_type ] ) ) {
        $totals[ $row->event_type ] = $row->count;
    }
}

// ─── Users seen in logs ───────────────────────────────────────────────────────
$log_users = $wpdb->get_results( "SELECT DISTINCT user_id FROM {$table_name} WHERE user_id > 0" );

// ─── Helpers ──────────────────────────────────────────────────────────────────
$current_url = admin_url( 'admin.php?page=actvt-watcher' );

$pagination_args = array(
    'filter_user' => $filter_user_id ?: '',
    'period'      => $filter_period,
    'date_from'   => ( $filter_period === 'custom' ) ? $filter_date_from : '',
    'date_to'     => ( $filter_period === 'custom' ) ? $filter_date_to : '',
    's'           => $search_query,
    'per_page'    => $per_page,
    'paged'       => '%#%',
);
// Append event_type[] params manually since add_query_arg doesn't support arrays
$et_query_str = '';
if ( ! empty( $filter_event_types ) ) {
    foreach ( $filter_event_types as $et_val ) {
        $et_query_str .= '&event_type%5B%5D=' . urlencode( $et_val );
    }
}
$page_links = paginate_links( array(
    'base'      => add_query_arg( $pagination_args ) . $et_query_str,
    'format'    => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total'     => $total_pages,
    'current'   => $current_page,
) );

$active_filters = array_filter( array( $filter_event_types, $filter_user_id, $filter_period, $search_query ),
    function( $v ) { return ! empty( $v ) && $v !== array(); }
);
$badge_map = array(
    'auth'     => 'actvt-badge-auth',
    'content'  => 'actvt-badge-content',
    'system'   => 'actvt-badge-system',
    'security' => 'actvt-badge-security',
);
?>
<!-- Select2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<div class="wrap actvt-watcher-wrap">

<style>
/* ── Page header ──────────────────────────────────────── */
.actvt-watcher-wrap h1.actvt-page-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 23px;
    font-weight: 400;
    margin: 0 0 8px;
    line-height: 1.3;
}
.actvt-watcher-wrap h1.actvt-page-title .dashicons {
    font-size: 26px;
    width: 26px;
    height: 26px;
    color: #2271b1;
    flex-shrink: 0;
}

/* ── Summary cards ────────────────────────────────────── */
.actvt-summary-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 16px 0;
}
.actvt-summary-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
    padding: 12px 20px;
    min-width: 110px;
    flex: 1 1 110px;
}
.actvt-summary-card.auth     { border-left-color: #3a7d28; }
.actvt-summary-card.content  { border-left-color: #1d6b9e; }
.actvt-summary-card.system   { border-left-color: #8a6d00; }
.actvt-summary-card.security { border-left-color: #8b1c1c; }
.actvt-summary-card .sc-count { display: block; font-size: 26px; font-weight: 700; color: #1d2327; line-height: 1.2; }
.actvt-summary-card .sc-label { display: block; font-size: 10px; font-weight: 600; color: #646970; text-transform: uppercase; letter-spacing: .6px; margin-top: 3px; }
@media (max-width: 600px) {
    .actvt-summary-card { flex: 1 1 calc(50% - 6px); }
}
@media (max-width: 400px) {
    .actvt-summary-card { flex: 1 1 100%; }
}

/* ── Filters panel ────────────────────────────────────── */
.actvt-filters-panel {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px 18px;
    margin-bottom: 16px;
}
.actvt-filters-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
}
.actvt-fg {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.actvt-fg label {
    font-size: 11px;
    font-weight: 600;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.actvt-fg select,
.actvt-fg input[type="date"],
.actvt-fg input[type="search"] {
    min-width: 150px;
    height: 30px;
}
.actvt-fg.actvt-fg-search input {
    min-width: 200px;
}
.actvt-custom-dates {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.actvt-filter-btns {
    display: flex;
    gap: 6px;
    align-items: flex-end;
    padding-bottom: 1px;
}
.actvt-filters-divider {
    width: 1px;
    height: 30px;
    background: #dcdcde;
    align-self: flex-end;
    margin: 0 2px;
}

/* ── Tablenav ─────────────────────────────────────────── */
.actvt-watcher-wrap .tablenav {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: space-between;
    height: auto;
    padding: 6px 0;
}
.actvt-watcher-wrap .tablenav .tablenav-pages {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    float: none;
    margin-left: auto;
}
.actvt-per-page-form {
    display: flex;
    align-items: center;
    gap: 6px;
}
.actvt-per-page-form label {
    font-size: 13px;
    color: #646970;
    white-space: nowrap;
}
.actvt-per-page-form select {
    height: 28px;
    min-width: 70px;
}
@media (max-width: 600px) {
    .actvt-watcher-wrap .tablenav { flex-direction: column; align-items: flex-start; }
    .actvt-watcher-wrap .tablenav .tablenav-pages { margin-left: 0; width: 100%; }
}

/* ── Badges ───────────────────────────────────────────── */
.actvt-badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .3px;
    text-transform: uppercase;
}
.actvt-badge-auth     { background: #eaf6e4; color: #2d6b1e; }
.actvt-badge-content  { background: #daeef8; color: #14567d; }
.actvt-badge-system   { background: #fef9e0; color: #7a5e00; }
.actvt-badge-security { background: #fde8e8; color: #8b1c1c; }
.actvt-badge-general  { background: #f0f0f1; color: #50575e; }

/* ── Table ────────────────────────────────────────────── */
.actvt-watcher-wrap .widefat td,
.actvt-watcher-wrap .widefat th { vertical-align: middle; }
.actvt-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.actvt-watcher-wrap .widefat { table-layout: auto; width: 100%; }
/* Keep compact columns on one line */
.actvt-watcher-wrap .widefat .col-ts,
.actvt-watcher-wrap .widefat .col-user,
.actvt-watcher-wrap .widefat .col-role,
.actvt-watcher-wrap .widefat .col-type,
.actvt-watcher-wrap .widefat .col-obj,
.actvt-watcher-wrap .widefat .col-ip  { white-space: nowrap; }
/* Cap metadata column */
.actvt-watcher-wrap .widefat .col-meta { width: 350px; max-width: 350px; }
.actvt-metadata-cell { max-width: 350px; overflow-wrap: break-word; word-break: break-word; }
.actvt-metadata-cell { position: relative; }
.actvt-meta-dl {
    margin: 0;
    padding: 0;
    font-size: 12px;
    line-height: 1.6;
}
.actvt-meta-dl dt {
    display: inline;
    font-weight: 600;
    color: #3c434a;
}
.actvt-meta-dl dt::after { content: ':\00a0'; }
.actvt-meta-dl dd {
    display: inline;
    margin: 0;
    color: #1d2327;
}
.actvt-meta-dl dd::after { content: '\A'; white-space: pre; }
.actvt-meta-raw {
    font-family: 'Courier New', Courier, monospace;
    white-space: pre-wrap;
    font-size: 11px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    padding: 5px 8px;
    border-radius: 3px;
    max-height: 120px;
    overflow-y: auto;
    margin: 0;
}
.actvt-meta-toggle {
    display: inline-block;
    margin-top: 4px;
    font-size: 10px;
    color: #2271b1;
    background: none;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    padding: 1px 5px;
    cursor: pointer;
    line-height: 1.5;
    font-family: 'Courier New', monospace;
    font-weight: 600;
}
.actvt-meta-toggle:hover { background: #f0f6fc; border-color: #2271b1; }
.actvt-empty-row td {
    text-align: center;
    padding: 36px !important;
    color: #646970;
    font-style: italic;
}
/* ── Presets bar ───────────────────────────────────── */
.actvt-presets-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    padding: 8px 14px;
    margin-bottom: 10px;
    font-size: 13px;
}
.actvt-presets-bar label { font-weight: 600; color: #646970; white-space:nowrap; }
.actvt-presets-bar select { height: 28px; }
.actvt-presets-bar input[type="text"] { height: 26px; padding: 0 6px; }
.actvt-preset-save-row { display:none; align-items:center; gap:6px; }

.actvt-watcher-wrap .check-column {
    width: 26px;
    padding: 6px 0 6px 8px;
    vertical-align: middle;
}
.actvt-row-actions { font-size: 11px; color: #646970; margin-top: 3px; }
.actvt-row-actions .delete a { color: #b32d2e; }
.actvt-row-actions .delete a:hover { color: #ac2215; text-decoration: underline; }
/* ── Bulk action bar ──────────────────────────────── */
.actvt-bulk-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.actvt-bulk-bar select { height: 28px; }
.actvt-bulk-bar .button { height: 28px; line-height: 26px; padding: 0 10px; }
</style>

    <!-- Page Title -->
    <h1 class="actvt-page-title">
        <span class="dashicons dashicons-visibility"></span>
        ACTVT Watcher
    </h1>
    <hr class="wp-header-end">

    <?php if ( $show_notice ) : ?>
    <div class="notice notice-success is-dismissible" style="margin-top:12px;">
        <p><?php echo $show_notice === 1
            ? 'Log entry deleted.'
            : esc_html( $show_notice ) . ' log entries deleted.'; ?></p>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="actvt-summary-row">
        <div class="actvt-summary-card">
            <span class="sc-count"><?php echo number_format( $grand_total ); ?></span>
            <span class="sc-label">Total Events</span>
        </div>
        <div class="actvt-summary-card auth">
            <span class="sc-count"><?php echo number_format( $totals['auth'] ); ?></span>
            <span class="sc-label">Auth</span>
        </div>
        <div class="actvt-summary-card content">
            <span class="sc-count"><?php echo number_format( $totals['content'] ); ?></span>
            <span class="sc-label">Content</span>
        </div>
        <div class="actvt-summary-card system">
            <span class="sc-count"><?php echo number_format( $totals['system'] ); ?></span>
            <span class="sc-label">System</span>
        </div>
        <div class="actvt-summary-card security">
            <span class="sc-count"><?php echo number_format( $totals['security'] ); ?></span>
            <span class="sc-label">Security</span>
        </div>
    </div>

    <!-- Filters Panel -->
    <form method="get" action="<?php echo esc_url( $current_url ); ?>" id="actvt-filter-form">
        <input type="hidden" name="page" value="actvt-watcher">
        <input type="hidden" name="paged" value="1">

        <div class="actvt-filters-panel">
            <div class="actvt-filters-row">

                <!-- Event Type (Select2 multi-select) -->
                <div class="actvt-fg">
                    <label for="actvt-event-type">Event Type</label>
                    <select name="event_type[]" id="actvt-event-type" multiple
                            style="min-width: 350px;">
                        <?php foreach ( $all_event_types as $et ) :
                            $is_selected = empty( $filter_event_types ) || in_array( $et, $filter_event_types, true );
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr( $et ),
                                $is_selected ? ' selected' : '',
                                esc_html( ucfirst( $et ) )
                            );
                        endforeach; ?>
                    </select>
                </div>

                <!-- User -->
                <div class="actvt-fg">
                    <label for="actvt-filter-user">User</label>
                    <select name="filter_user" id="actvt-filter-user">
                        <option value="">All Users</option>
                        <?php foreach ( $log_users as $lu ) :
                            $u = get_userdata( $lu->user_id );
                            if ( ! $u ) continue;
                            printf( '<option value="%d"%s>%s</option>', $lu->user_id, selected( $filter_user_id, $lu->user_id, false ), esc_html( $u->user_login ) );
                        endforeach; ?>
                    </select>
                </div>

                <div class="actvt-filters-divider"></div>

                <!-- Period Preset -->
                <div class="actvt-fg">
                    <label for="actvt-period">Period</label>
                    <select name="period" id="actvt-period" onchange="actvtToggleDates(this.value)">
                        <option value="">All Time</option>
                        <option value="this_month"  <?php selected( $filter_period, 'this_month' ); ?>>This Month</option>
                        <option value="last_month"  <?php selected( $filter_period, 'last_month' ); ?>>Last Month</option>
                        <option value="last_3_months" <?php selected( $filter_period, 'last_3_months' ); ?>>Last 3 Months</option>
                        <option value="custom"      <?php selected( $filter_period, 'custom' ); ?>>Custom Range</option>
                    </select>
                </div>

                <!-- Custom date pickers (shown only when period=custom) -->
                <div class="actvt-custom-dates" id="actvt-custom-dates" style="<?php echo $filter_period === 'custom' ? '' : 'display:none;'; ?>">
                    <div class="actvt-fg">
                        <label for="actvt-date-from">From</label>
                        <input type="date" name="date_from" id="actvt-date-from" value="<?php echo esc_attr( $filter_period === 'custom' ? $filter_date_from : '' ); ?>">
                    </div>
                    <div class="actvt-fg">
                        <label for="actvt-date-to">To</label>
                        <input type="date" name="date_to" id="actvt-date-to" value="<?php echo esc_attr( $filter_period === 'custom' ? $filter_date_to : '' ); ?>">
                    </div>
                </div>

                <div class="actvt-filters-divider"></div>

                <!-- Search -->
                <div class="actvt-fg actvt-fg-search">
                    <label for="actvt-search">Search</label>
                    <input type="search" name="s" id="actvt-search" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Action, IP, metadata&hellip;">
                </div>

                <!-- Buttons -->
                <div class="actvt-filter-btns">
                    <?php submit_button( 'Apply', 'primary', '', false, array( 'style' => 'height:30px; padding: 0 12px; line-height: 28px;' ) ); ?>
                    <?php if ( $active_filters ) : ?>
                        <a href="<?php echo esc_url( $current_url ); ?>" class="button" style="height:30px; line-height: 28px; padding: 0 10px;">Reset</a>
                    <?php endif; ?>

                    <?php
                    // Build export URL with current filters
                    $export_args = array(
                        'action'      => 'actvt_export_logs',
                        'filter_user' => $filter_user_id ?: '',
                        'period'      => $filter_period,
                        'date_from'   => ( $filter_period === 'custom' ) ? $filter_date_from : '',
                        'date_to'     => ( $filter_period === 'custom' ) ? $filter_date_to   : '',
                        's'           => $search_query,
                        '_wpnonce'    => wp_create_nonce( 'actvt_export_logs' ),
                    );
                    $export_url = add_query_arg( $export_args, admin_url( 'admin-post.php' ) );
                    // Append event_type[] params
                    if ( ! empty( $filter_event_types ) ) {
                        foreach ( $filter_event_types as $et_val ) {
                            $export_url .= '&event_type%5B%5D=' . urlencode( $et_val );
                        }
                    }
                    ?>
                    <a href="<?php echo esc_url( $export_url ); ?>"
                       class="button"
                       style="height:30px; line-height: 28px; padding: 0 10px; display:inline-flex; align-items:center; gap:4px;">
                        <span class="dashicons dashicons-media-spreadsheet" style="font-size:15px; width:15px; height:15px; line-height:1; margin-top:1px;"></span>
                        Export CSV
                    </a>
                </div>

            </div><!-- .actvt-filters-row -->
        </div><!-- .actvt-filters-panel -->
    </form>

    <?php
    // Load current user's presets for the presets bar
    $actvt_presets = get_user_meta( get_current_user_id(), 'actvt_filter_presets', true );
    if ( ! is_array( $actvt_presets ) ) $actvt_presets = array();
    ?>
    <!-- Presets bar -->
    <div class="actvt-presets-bar" id="actvt-presets-bar">
        <label>Saved Filters:</label>

        <?php if ( $actvt_presets ) : ?>
        <select id="actvt-preset-select" style="max-width:220px;">
            <option value="">-- Load a preset --</option>
            <?php foreach ( $actvt_presets as $pname => $pqs ) : ?>
            <option value="<?php echo esc_attr( $pqs ); ?>"
                    data-name="<?php echo esc_attr( $pname ); ?>"><?php echo esc_html( $pname ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button" id="actvt-preset-load">Load</button>
        <button type="button" class="button" id="actvt-preset-delete" style="color:#b32d2e;">Delete</button>
        <?php endif; ?>

        <button type="button" class="button" id="actvt-preset-save-toggle">+ Save Current Filter</button>
        <span class="actvt-preset-save-row" id="actvt-preset-save-row">
            <input type="text" id="actvt-preset-name" placeholder="Preset name…" style="width:180px;">
            <button type="button" class="button button-primary" id="actvt-preset-save-confirm">Save</button>
            <button type="button" class="button" id="actvt-preset-save-cancel">Cancel</button>
        </span>
        <span id="actvt-preset-msg" style="font-size:12px;color:#0a6b23;"></span>
    </div>


    <script>
    var actvtAjax = {
        url:   '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
        nonce: '<?php echo wp_create_nonce( 'actvt_filter_preset' ); ?>'
    };

    function actvtToggleDates(val) {
        var el = document.getElementById('actvt-custom-dates');
        if (val === 'custom') {
            el.style.display = 'flex';
        } else {
            el.style.display = 'none';
            document.getElementById('actvt-date-from').value = '';
            document.getElementById('actvt-date-to').value   = '';
        }
    }

    jQuery(function($) {
        // ── Presets bar ────────────────────────────────────────────────
        var $msg = $('#actvt-preset-msg');

        // Toggle "save" input row
        $('#actvt-preset-save-toggle').on('click', function() {
            $('#actvt-preset-save-row').css('display', 'inline-flex');
            $('#actvt-preset-name').val('').trigger('focus');
            $(this).hide();
        });
        $('#actvt-preset-save-cancel').on('click', function() {
            $('#actvt-preset-save-row').hide();
            $('#actvt-preset-save-toggle').show();
        });

        // Save preset
        $('#actvt-preset-save-confirm').on('click', function() {
            var name = $('#actvt-preset-name').val().trim();
            if ( ! name ) { $msg.css('color','#d63638').text('Please enter a preset name.'); return; }
            var qs = window.location.search; // current filter query string
            $.post( actvtAjax.url, {
                action:       'actvt_save_filter_preset',
                nonce:        actvtAjax.nonce,
                preset_name:  name,
                query_string: qs
            }, function(res) {
                if ( res.success ) {
                    $msg.css('color','#0a6b23').text('Preset "' + name + '" saved! Reloading…');
                    setTimeout(function(){ location.reload(); }, 900);
                } else {
                    $msg.css('color','#d63638').text(res.data || 'Error saving preset.');
                }
            });
        });

        // Load preset
        $('#actvt-preset-load').on('click', function() {
            var qs = $('#actvt-preset-select').val();
            if ( ! qs ) { $msg.css('color','#d63638').text('Please select a preset first.'); return; }
            window.location.search = qs;
        });

        // Delete preset
        $('#actvt-preset-delete').on('click', function() {
            var $sel  = $('#actvt-preset-select');
            var name  = $sel.find(':selected').data('name');
            if ( ! name ) { $msg.css('color','#d63638').text('Please select a preset to delete.'); return; }
            if ( ! confirm('Delete preset "' + name + '"?') ) return;
            $.post( actvtAjax.url, {
                action:      'actvt_delete_filter_preset',
                nonce:       actvtAjax.nonce,
                preset_name: name
            }, function(res) {
                if ( res.success ) {
                    $sel.find(':selected').remove();
                    $msg.css('color','#0a6b23').text('Preset deleted.');
                    if ( $sel.find('option').length <= 1 ) {
                        // No presets left – hide select + buttons
                        $sel.hide();
                        $('#actvt-preset-load, #actvt-preset-delete').hide();
                    }
                }
            });
        });

        // ── Select2 & existing event handlers ─────────────────────────
        $('#actvt-event-type').select2({
            placeholder:     'Filter by type…',

            allowClear:      true,
            closeOnSelect:   false,
            width:           'style'
        });
    });
    </script>

    <!-- Bulk-delete form wraps table nav + table -->
    <form method="post" id="actvt-bulk-form">
        <?php wp_nonce_field( 'actvt_bulk_delete' ); ?>
        <input type="hidden" name="action" value="bulk_delete">

        <!-- Top tablenav: bulk bar (left) | count + per-page + pagination (right) -->
        <div class="tablenav top">

            <!-- Left: bulk actions -->
            <div class="actvt-bulk-bar">
                <label class="screen-reader-text" for="actvt-bulk-action">Bulk action</label>
                <select id="actvt-bulk-action" name="bulk_action_select">
                    <option value="">Bulk Actions</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button type="submit" class="button" onclick="return actvtConfirmBulk();">Apply</button>
                <span style="color:#646970; font-size:12px; margin-left:4px;">
                    (<a href="#" onclick="actvtToggleAll(true); return false;">Select all</a>
                    &nbsp;/&nbsp;
                    <a href="#" onclick="actvtToggleAll(false); return false;">None</a>)
                </span>
            </div>

            <!-- Right: count + per-page + pagination -->
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php echo number_format( $total_items ); ?> item<?php echo intval( $total_items ) !== 1 ? 's' : ''; ?>
                    <?php if ( $active_filters ) echo '<a href="' . esc_url( $current_url ) . '" style="margin-left:6px; font-size:12px;">Clear filters</a>'; ?>
                </span>

                <!-- Per-page & Format switcher (triggered via JS to main form) -->
                <div class="actvt-per-page-form">
                    <label for="actvt-per-page">Show</label>
                    <select name="per_page" id="actvt-per-page" onchange="actvtApplySetting(this.name, this.value)">
                        <?php foreach ( $allowed_per_page as $pp ) :
                            printf( '<option value="%d"%s>%d / page</option>', $pp, selected( $per_page, $pp, false ), $pp );
                        endforeach; ?>
                    </select>

                    <label for="actvt-meta-format" style="margin-left:8px;">Format</label>
                    <select name="metadata_format" id="actvt-meta-format" onchange="actvtApplySetting(this.name, this.value)">
                        <option value="formatted" <?php selected( $active_meta_mode, 'formatted' ); ?>>Clean</option>
                        <option value="raw" <?php selected( $active_meta_mode, 'raw' ); ?>>JSON</option>
                    </select>
                </div>

                <?php if ( $page_links ) echo '<span class="pagination-links">' . $page_links . '</span>'; ?>
            </div>
        <br class="clear">
    </div>

    <!-- Log Table -->
    <?php $col_count = 9; // +1 for checkbox column ?>
    <div class="actvt-table-scroll">
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th class="check-column"><input type="checkbox" id="actvt-cb-all" title="Select all"></th>
                <th class="col-ts"   style="min-width:120px;">Timestamp</th>
                <th class="col-user" style="min-width:100px;">User</th>
                <th class="col-role" style="min-width:80px;">Role</th>
                <th class="col-type" style="min-width:100px;">Event Type</th>
                <th class="col-action" style="min-width:140px;">Action</th>
                <th class="col-obj"  style="min-width:70px;">Object ID</th>
                <th class="col-meta" style="min-width:220px;">Metadata</th>
                <th class="col-ip"   style="min-width:110px;">IP Address</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( $items ) :
            foreach ( $items as $item ) :
                $user_info      = get_userdata( $item->user_id );
                $username       = $user_info ? $user_info->user_login : ( $item->user_id > 0 ? '#' . $item->user_id : 'Guest' );
                $meta_decoded   = json_decode( $item->metadata, true );

                // ── Build metadata view ──────────────────────────────
                if ( $active_meta_mode === 'raw' ) {
                    $html = '<pre class="actvt-meta-raw">' . esc_html(
                        $meta_decoded ? json_encode( $meta_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
                                      : $item->metadata
                    ) . '</pre>';
                } else {
                    if ( is_array( $meta_decoded ) && ! empty( $meta_decoded ) ) {
                        $html = '<dl class="actvt-meta-dl">';
                        foreach ( $meta_decoded as $mk => $mv ) {
                            $label = esc_html( ucwords( str_replace( '_', ' ', $mk ) ) );
                            if ( is_array( $mv ) ) {
                                $val = esc_html( implode( ', ', array_map( 'strval', $mv ) ) );
                            } else {
                                $val = esc_html( (string) $mv );
                            }
                            $html .= '<dt>' . $label . '</dt><dd>' . $val . '</dd>';
                        }
                        $html .= '</dl>';
                    } else {
                        $html = '<span style="color:#bbb;">—</span>';
                    }
                }
                
                $badge_class    = $badge_map[ $item->event_type ] ?? 'actvt-badge-general';
                $delete_url     = wp_nonce_url(
                    add_query_arg( array( 'action' => 'delete_log', 'log_id' => $item->id ) ),
                    'actvt_delete_log_' . $item->id
                );
        ?>
            <tr>
                <td class="check-column"><input type="checkbox" name="log_ids[]" value="<?php echo intval( $item->id ); ?>"></td>
                <td>
                    <?php echo esc_html( $item->timestamp ); ?>
                    <div class="actvt-row-actions">
                        <span class="delete">
                            <a href="<?php echo esc_url( $delete_url ); ?>"
                               onclick="return confirm('Delete this log entry?');">
                                Delete
                            </a>
                        </span>
                    </div>
                </td>
                <td><?php echo esc_html( $username ); ?></td>
                <td><?php echo $item->user_role ? esc_html( $item->user_role ) : '<span style="color:#bbb">—</span>'; ?></td>
                <td><span class="actvt-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $item->event_type ); ?></span></td>
                <td><code style="font-size:11px;"><?php echo esc_html( $item->action ); ?></code></td>
                <td><?php echo $item->object_id > 0 ? esc_html( $item->object_id ) : '<span style="color:#bbb">—</span>'; ?></td>
                <td>
                    <div class="actvt-metadata-cell">
                        <?php echo $html; ?>
                    </div>
                </td>
                <td><?php echo esc_html( $item->ip_address ); ?></td>
            </tr>
        <?php endforeach;
        else : ?>
            <tr class="actvt-empty-row">
                <td colspan="<?php echo $col_count; ?>">
                    <?php echo $active_filters ? 'No logs match your current filters.' : 'No activity logs recorded yet.'; ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th class="check-column"><input type="checkbox" id="actvt-cb-all-foot" title="Select all"></th>
                <th class="col-ts"   style="min-width:150px;">Timestamp</th>
                <th class="col-user" style="min-width:100px;">User</th>
                <th class="col-role" style="min-width:80px;">Role</th>
                <th class="col-type" style="min-width:100px;">Event Type</th>
                <th class="col-action" style="min-width:140px;">Action</th>
                <th class="col-obj"  style="min-width:70px;">Object ID</th>
                <th class="col-meta" style="min-width:220px;">Metadata</th>
                <th class="col-ip"   style="min-width:110px;">IP Address</th>
            </tr>
        </tfoot>
    </table>
    </div><!-- .actvt-table-scroll -->

    <!-- Bottom tablenav -->
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php echo number_format( $total_items ); ?> item<?php echo intval( $total_items ) !== 1 ? 's' : ''; ?>
            </span>
            <?php if ( $page_links ) echo '<span class="pagination-links">' . $page_links . '</span>'; ?>
        </div>
        <br class="clear">
    </div>

    </form><!-- end #actvt-bulk-form -->

    <script>
    function actvtApplySetting(key, val) {
        var form = document.getElementById('actvt-filter-form');
        if (!form) return;
        var input = form.querySelector('input[name="' + key + '"]');
        if (input) {
            input.value = val;
        } else {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = val;
            form.appendChild(input);
        }
        form.submit();
    }

    function actvtToggleDates(val) {
        var el = document.getElementById('actvt-custom-dates');
        if (val === 'custom') {
            el.style.display = 'flex';
        } else {
            el.style.display = 'none';
            document.getElementById('actvt-date-from').value = '';
            document.getElementById('actvt-date-to').value   = '';
        }
    }

    jQuery(function($) {
        // Select2 init
        $('#actvt-event-type').select2({
            placeholder:   'Filter by type…',
            allowClear:    true,
            closeOnSelect: false,
            width:         'style'
        });

        // Header checkboxes sync to footer and rows
        $('#actvt-cb-all, #actvt-cb-all-foot').on('change', function() {
            var checked = $(this).prop('checked');
            $('input[name="log_ids[]"]').prop('checked', checked);
            $('#actvt-cb-all, #actvt-cb-all-foot').prop('checked', checked);
        });
    });

    function actvtToggleAll(state) {
        document.querySelectorAll('input[name="log_ids[]"]').forEach(function(cb) { cb.checked = state; });
        var all = document.getElementById('actvt-cb-all');
        var allF = document.getElementById('actvt-cb-all-foot');
        if (all)  all.checked  = state;
        if (allF) allF.checked = state;
    }

    function actvtConfirmBulk() {
        var sel = document.getElementById('actvt-bulk-action');
        if (sel && sel.value !== 'delete') { alert('Please choose an action.'); return false; }
        var checked = document.querySelectorAll('input[name="log_ids[]"]:checked');
        if (!checked.length) { alert('No rows selected.'); return false; }
        return confirm('Delete ' + checked.length + ' selected log entr' + (checked.length === 1 ? 'y' : 'ies') + '?');
    }
    </script>
