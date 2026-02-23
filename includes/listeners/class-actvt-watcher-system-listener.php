<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * System Listener — expanded with optional detailed metadata.
 *
 * Hooks covered:
 *  activated_plugin, deactivated_plugin, switch_theme, _core_updated_successfully,
 *  updated_option, upgrader_process_complete, permalink_structure_changed,
 *  wp_update_nav_menu, wp_create_nav_menu, wp_delete_nav_menu,
 *  load-theme-editor.php (admin), load-plugin-editor.php (admin)
 */
class Actvt_Watcher_System_Listener {

    public function __construct() {
        add_action( 'activated_plugin',            array( $this, 'log_plugin_activation' ),   10, 2 );
        add_action( 'deactivated_plugin',          array( $this, 'log_plugin_deactivation' ), 10, 2 );
        add_action( 'switch_theme',                array( $this, 'log_theme_switch' ),         10, 3 );
        add_action( '_core_updated_successfully',  array( $this, 'log_core_update' ),          10, 1 );
        add_action( 'updated_option',              array( $this, 'log_option_update' ),         10, 3 );
        add_action( 'upgrader_process_complete',   array( $this, 'log_upgrader_complete' ),    10, 2 );
        add_action( 'permalink_structure_changed', array( $this, 'log_permalink_change' ),     10, 2 );
        add_action( 'wp_create_nav_menu',          array( $this, 'log_menu_create' ),          10, 2 );
        add_action( 'wp_update_nav_menu',          array( $this, 'log_menu_update' ),          10, 1 );
        add_action( 'wp_delete_nav_menu',          array( $this, 'log_menu_delete' ),          10, 1 );
        add_action( 'load-theme-editor.php',       array( $this, 'log_theme_editor_access' ) );
        add_action( 'load-plugin-editor.php',      array( $this, 'log_plugin_editor_access' ) );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function is_detailed() {
        $s = get_option( 'actvt_watcher_settings', array() );
        return ! empty( $s['metadata_detail_level'] ) && $s['metadata_detail_level'] === 'detailed';
    }

    /**
     * Read plugin header data from the plugin file.
     *
     * @param string $plugin_file Plugin file path relative to WP plugins dir.
     * @return array [ name, version, author, author_uri ]
     */
    private static function plugin_info( $plugin_file ) {
        $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
        return array_filter( array(
            'name'       => $data['Name']      ?? '',
            'version'    => $data['Version']   ?? '',
            'author'     => wp_strip_all_tags( $data['Author'] ?? '' ),
        ) );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function log_plugin_activation( $plugin, $network_wide = false ) {
        $meta = array( 'plugin' => $plugin, 'network_wide' => $network_wide );

        if ( self::is_detailed() ) {
            $meta = array_merge( $meta, self::plugin_info( $plugin ) );
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'plugin_activated',
            'metadata'   => $meta,
        ) );
    }

    public function log_plugin_deactivation( $plugin, $network_wide = false ) {
        $meta = array( 'plugin' => $plugin, 'network_wide' => $network_wide );

        if ( self::is_detailed() ) {
            $meta = array_merge( $meta, self::plugin_info( $plugin ) );
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'plugin_deactivated',
            'metadata'   => $meta,
        ) );
    }

    public function log_theme_switch( $new_name, $new_theme, $old_theme ) {
        $meta = array(
            'new_theme' => $new_name,
            'old_theme' => $old_theme->get( 'Name' ),
        );

        if ( self::is_detailed() ) {
            $meta['new_theme_version'] = $new_theme->get( 'Version' );
            $meta['new_theme_author']  = wp_strip_all_tags( $new_theme->get( 'Author' ) );
            $meta['old_theme_version'] = $old_theme->get( 'Version' );
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'theme_switched',
            'metadata'   => $meta,
        ) );
    }

    public function log_core_update( $new_version ) {
        $meta = array( 'new_version' => $new_version );

        if ( self::is_detailed() ) {
            $meta['old_version'] = get_bloginfo( 'version' );
            $meta['php_version'] = PHP_VERSION;
            $meta['db_version']  = get_option( 'db_version' );
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'core_updated',
            'metadata'   => $meta,
        ) );
    }

    public function log_option_update( $option, $old_value, $new_value ) {
        // Skip transients, cron, and options that fire constantly
        $skip_prefixes = array( '_transient_', '_site_transient_', '_user_meta' );
        $skip_exact    = array( 'cron', 'active_plugins', 'rewrite_rules', 'recently_activated' );

        foreach ( $skip_prefixes as $pfx ) {
            if ( strpos( $option, $pfx ) === 0 ) return;
        }
        if ( in_array( $option, $skip_exact, true ) ) return;

        $meta = array( 'option' => $option );

        if ( self::is_detailed() ) {
            // Safely represent the before/after values (scalar only; truncate if long)
            $meta['old_value'] = self::safe_option_value( $old_value );
            $meta['new_value'] = self::safe_option_value( $new_value );
        } else {
            $meta['changes'] = 'Value changed';
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'option_updated',
            'metadata'   => $meta,
        ) );
    }

    /**
     * Returns a safe, readable representation of an option value.
     * Arrays/objects are JSON-encoded and truncated.
     */
    private static function safe_option_value( $value ) {
        if ( is_bool( $value ) ) return $value ? 'true' : 'false';
        if ( is_null( $value ) ) return 'null';
        if ( is_scalar( $value ) ) return substr( (string) $value, 0, 200 );
        $json = @json_encode( $value );
        return $json ? substr( $json, 0, 300 ) : '[complex value]';
    }

    public function log_upgrader_complete( $upgrader, $hook_extra ) {
        $type   = isset( $hook_extra['type'] )   ? $hook_extra['type']   : 'unknown';
        $action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : 'unknown';

        if ( ! in_array( $action, array( 'update', 'install' ), true ) ) return;

        $plugins = array();
        if ( isset( $hook_extra['plugins'] ) )      $plugins = (array) $hook_extra['plugins'];
        elseif ( isset( $hook_extra['plugin'] ) )   $plugins = array( $hook_extra['plugin'] );

        $themes = array();
        if ( isset( $hook_extra['themes'] ) )       $themes = (array) $hook_extra['themes'];
        elseif ( isset( $hook_extra['theme'] ) )    $themes = array( $hook_extra['theme'] );

        $meta = array_filter( array(
            'type'    => $type,
            'action'  => $action,
            'plugins' => $plugins ?: null,
            'themes'  => $themes  ?: null,
        ) );

        if ( self::is_detailed() ) {
            // Enrich plugin list with name + version
            if ( $plugins ) {
                $plugin_details = array();
                foreach ( $plugins as $pf ) {
                    $info = self::plugin_info( $pf );
                    $plugin_details[] = ( $info['name'] ?? $pf ) . ' v' . ( $info['version'] ?? '?' );
                }
                $meta['plugin_details'] = implode( ', ', $plugin_details );
            }
            if ( $themes ) {
                $theme_details = array();
                foreach ( $themes as $ts ) {
                    $t = wp_get_theme( $ts );
                    $theme_details[] = $t->get( 'Name' ) . ' v' . $t->get( 'Version' );
                }
                $meta['theme_details'] = implode( ', ', $theme_details );
            }
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => $type . '_' . $action . 'd',  // e.g. plugin_updated
            'metadata'   => $meta,
        ) );
    }

    public function log_permalink_change( $old_permalink_structure, $permalink_structure ) {
        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'permalink_structure_changed',
            'metadata'   => array(
                'old' => $old_permalink_structure,
                'new' => $permalink_structure,
            ),
        ) );
    }

    public function log_menu_create( $menu_id, $menu_data ) {
        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $menu_id,
            'event_type' => 'content',
            'action'     => 'nav_menu_created',
            'metadata'   => array( 'menu_name' => isset( $menu_data['menu-name'] ) ? $menu_data['menu-name'] : '' ),
        ) );
    }

    public function log_menu_update( $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        $meta = array( 'menu_name' => $menu ? $menu->name : '#' . $menu_id );

        if ( self::is_detailed() && $menu ) {
            $items = wp_get_nav_menu_items( $menu_id );
            $meta['item_count'] = $items ? count( $items ) : 0;
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $menu_id,
            'event_type' => 'content',
            'action'     => 'nav_menu_updated',
            'metadata'   => $meta,
        ) );
    }

    public function log_menu_delete( $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $menu_id,
            'event_type' => 'content',
            'action'     => 'nav_menu_deleted',
            'metadata'   => array( 'menu_name' => $menu ? $menu->name : '#' . $menu_id ),
        ) );
    }

    public function log_theme_editor_access() {
        $file  = isset( $_GET['file'] )  ? sanitize_text_field( $_GET['file'] )  : '';
        $theme = isset( $_GET['theme'] ) ? sanitize_text_field( $_GET['theme'] ) : get_option( 'stylesheet' );
        $meta  = array( 'file' => $file );

        if ( self::is_detailed() ) {
            $t                   = wp_get_theme( $theme );
            $meta['theme']       = $t->get( 'Name' );
            $meta['theme_slug']  = $theme;
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'security',
            'action'     => 'theme_editor_accessed',
            'metadata'   => $meta,
        ) );
    }

    public function log_plugin_editor_access() {
        $file   = isset( $_GET['file'] )   ? sanitize_text_field( $_GET['file'] )   : '';
        $plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( $_GET['plugin'] ) : '';
        $meta   = array( 'plugin' => $plugin, 'file' => $file );

        if ( self::is_detailed() && $plugin ) {
            $info               = self::plugin_info( $plugin );
            $meta['plugin_name']   = $info['name']    ?? $plugin;
            $meta['plugin_version'] = $info['version'] ?? '';
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'security',
            'action'     => 'plugin_editor_accessed',
            'metadata'   => $meta,
        ) );
    }
}
