<?php

/**
 * Authentication Listener — expanded with optional detailed metadata.
 *
 * Hooks covered:
 *  wp_login, wp_login_failed, wp_logout, profile_update, user_register,
 *  set_user_role, delete_user, retrieve_password, password_reset, wp_set_password
 */
class Actvt_Watcher_Auth_Listener {

    public function __construct() {
        add_action( 'wp_login',               array( $this, 'log_login' ),                   10, 2 );
        add_action( 'wp_login_failed',        array( $this, 'log_login_failed' ),             10, 1 );
        add_action( 'wp_logout',              array( $this, 'log_logout' ),                   10, 1 );
        add_action( 'profile_update',         array( $this, 'log_profile_update' ),           10, 2 );
        add_action( 'user_register',          array( $this, 'log_user_register' ),            10, 1 );
        add_action( 'set_user_role',          array( $this, 'log_role_change' ),              10, 3 );
        add_action( 'delete_user',            array( $this, 'log_user_delete' ),              10, 2 );
        add_action( 'retrieve_password',      array( $this, 'log_password_reset_request' ),   10, 1 );
        add_action( 'password_reset',         array( $this, 'log_password_reset' ),           10, 2 );
        add_action( 'wp_set_password',        array( $this, 'log_password_change' ),          10, 2 );
    }

    // ── Helper: is detailed mode on? ─────────────────────────────────────────

    private static function is_detailed() {
        $s = get_option( 'actvt_watcher_settings', array() );
        return ! empty( $s['metadata_detail_level'] ) && $s['metadata_detail_level'] === 'detailed';
    }

    // ── Helper: browser / request context ────────────────────────────────────

    private static function request_context() {
        $ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 300 ) : '';
        $referrer = isset( $_SERVER['HTTP_REFERER'] )    ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '';
        $method   = isset( $_SERVER['REQUEST_METHOD'] )  ? sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) : '';
        return array_filter( array(
            'user_agent' => $ua,
            'referrer'   => $referrer,
            'method'     => $method,
        ) );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function log_login( $user_login, $user ) {
        $meta = array( 'username' => $user_login );

        if ( self::is_detailed() ) {
            $meta['email']      = $user->user_email;
            $meta['role']       = ! empty( $user->roles ) ? reset( $user->roles ) : '';
            $meta['registered'] = $user->user_registered;
            $meta               = array_merge( $meta, self::request_context() );
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => $user->ID,
            'event_type' => 'auth',
            'action'     => 'wp_login',
            'metadata'   => $meta,
        ) );
    }

    public function log_login_failed( $username ) {
        $meta = array( 'attempted_username' => $username );

        if ( self::is_detailed() ) {
            $meta = array_merge( $meta, self::request_context() );

            // Count recent failures from this IP in the past hour
            global $wpdb;
            $table_name = $wpdb->prefix . 'actvt_watcher_logs';
            $ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
            $since      = date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
            $count      = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE action = 'login_failed' AND ip_address = %s AND timestamp >= %s",
                $ip, $since
            ) );
            $meta['recent_failures_this_ip_1h'] = $count + 1; // +1 for the one being logged now
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => 0,
            'event_type' => 'security',
            'action'     => 'login_failed',
            'metadata'   => $meta,
        ) );
    }

    public function log_logout( $user_id ) {
        $meta = array();
        if ( self::is_detailed() ) {
            $meta = self::request_context();
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => $user_id,
            'event_type' => 'auth',
            'action'     => 'wp_logout',
            'metadata'   => $meta ?: null,
        ) );
    }

    public function log_profile_update( $user_id, $old_user_data ) {
        $new_user = get_userdata( $user_id );
        $meta     = array( 'note' => 'Profile fields updated' );

        if ( self::is_detailed() && $new_user ) {
            $changed = array();
            $watch   = array(
                'user_email'    => 'Email',
                'display_name'  => 'Display Name',
                'user_url'      => 'Website',
            );
            foreach ( $watch as $field => $label ) {
                if ( $old_user_data->$field !== $new_user->$field ) {
                    $changed[ $label ] = array(
                        'from' => $old_user_data->$field,
                        'to'   => $new_user->$field,
                    );
                }
            }
            // Check first/last name (user meta)
            $old_first = get_user_meta( $user_id, 'first_name', true );
            $old_last  = get_user_meta( $user_id, 'last_name',  true );

            $meta = array(
                'username'       => $new_user->user_login,
                'email'          => $new_user->user_email,
                'changed_fields' => $changed ?: 'multiple',
            );
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => $user_id,
            'object_id'  => $user_id,
            'event_type' => 'auth',
            'action'     => 'profile_update',
            'metadata'   => $meta,
        ) );
    }

    public function log_user_register( $user_id ) {
        $user = get_userdata( $user_id );
        $role = ( $user && ! empty( $user->roles ) ) ? reset( $user->roles ) : '';

        $meta = array(
            'new_user_login' => $user ? $user->user_login : '',
            'new_user_role'  => $role,
        );

        if ( self::is_detailed() && $user ) {
            $meta['new_user_email']      = $user->user_email;
            $meta['new_user_registered'] = $user->user_registered;
            $meta['created_by']          = get_current_user_id() ? get_userdata( get_current_user_id() )->user_login : 'self-registration';
            $meta                        = array_merge( $meta, self::request_context() );
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => get_current_user_id(),
            'object_id'  => $user_id,
            'event_type' => 'auth',
            'action'     => 'user_register',
            'metadata'   => $meta,
        ) );
    }

    public function log_role_change( $user_id, $role, $old_roles ) {
        $user = get_userdata( $user_id );

        $meta = array(
            'username' => $user ? $user->user_login : '#' . $user_id,
            'old_role' => implode( ', ', (array) $old_roles ),
            'new_role' => $role,
        );

        if ( self::is_detailed() && $user ) {
            $meta['user_email']  = $user->user_email;
            $meta['changed_by']  = get_current_user_id() ? get_userdata( get_current_user_id() )->user_login : 'system';
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $user_id,
            'event_type' => 'security',
            'action'     => 'user_role_changed',
            'metadata'   => $meta,
        ) );
    }

    public function log_user_delete( $user_id, $reassign = null ) {
        $user = get_userdata( $user_id );

        $meta = array(
            'deleted_username' => $user ? $user->user_login : '#' . $user_id,
            'deleted_email'    => $user ? $user->user_email : '',
            'reassign_to'      => $reassign ? (int) $reassign : null,
        );

        if ( self::is_detailed() && $user ) {
            $meta['deleted_role']       = ! empty( $user->roles ) ? reset( $user->roles ) : '';
            $meta['deleted_registered'] = $user->user_registered;
            $meta['deleted_by']         = get_current_user_id() ? get_userdata( get_current_user_id() )->user_login : 'system';
            $post_count                 = count_user_posts( $user_id );
            $meta['post_count']         = (int) $post_count;
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $user_id,
            'event_type' => 'auth',
            'action'     => 'delete_user',
            'metadata'   => $meta,
        ) );
    }

    public function log_password_reset_request( $user_login ) {
        $meta = array( 'username' => $user_login );
        if ( self::is_detailed() ) {
            $meta = array_merge( $meta, self::request_context() );
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => 0,
            'event_type' => 'security',
            'action'     => 'password_reset_request',
            'metadata'   => $meta,
        ) );
    }

    public function log_password_reset( $user, $new_pass ) {
        $meta = array( 'username' => $user->user_login );
        if ( self::is_detailed() ) {
            $meta['email'] = $user->user_email;
            $meta          = array_merge( $meta, self::request_context() );
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => $user->ID,
            'object_id'  => $user->ID,
            'event_type' => 'security',
            'action'     => 'password_reset',
            'metadata'   => $meta,
        ) );
    }

    public function log_password_change( $password, $user_id ) {
        $user      = get_userdata( $user_id );
        $actor     = get_userdata( get_current_user_id() );
        $meta = array(
            'username' => $user ? $user->user_login : '#' . $user_id,
        );

        if ( self::is_detailed() ) {
            $meta['changed_by'] = $actor ? $actor->user_login : 'system';
            $meta['self_change'] = get_current_user_id() === $user_id;
        }

        Actvt_Watcher_DB::insert_log( array(
            'user_id'    => get_current_user_id(),
            'object_id'  => $user_id,
            'event_type' => 'security',
            'action'     => 'password_changed',
            'metadata'   => $meta,
        ) );
    }
}
