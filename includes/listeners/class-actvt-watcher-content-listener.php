<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Content Listener — expanded with optional detailed metadata.
 *
 * Hooks covered:
 *  wp_insert_post, post_updated, before_delete_post, add_attachment,
 *  comment_post, transition_post_status, export_wp
 */
class Actvt_Watcher_Content_Listener {

    public function __construct() {
        add_action( 'post_updated',           array( $this, 'log_post_update' ),           10, 3 );
        add_action( 'before_delete_post',     array( $this, 'log_post_deletion' ),         10, 1 );
        add_action( 'add_attachment',         array( $this, 'log_attachment' ),             10, 1 );
        add_action( 'comment_post',           array( $this, 'log_comment' ),               10, 2 );
        add_action( 'transition_post_status', array( $this, 'log_post_status_transition' ), 10, 3 );
        add_action( 'export_wp',              array( $this, 'log_export' ),                10, 1 );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function is_detailed() {
        $s = get_option( 'actvt_watcher_settings', array() );
        return ! empty( $s['metadata_detail_level'] ) && $s['metadata_detail_level'] === 'detailed';
    }

    private static function word_count( $content ) {
        return str_word_count( wp_strip_all_tags( $content ) );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function log_post_update( $post_id, $post_after, $post_before ) {
        if ( wp_is_post_revision( $post_id ) ) return;

        // Skip if only status changed — handled by transition_post_status
        if ( $post_before->post_content === $post_after->post_content
            && $post_before->post_title   === $post_after->post_title
            && $post_before->post_status  === $post_after->post_status ) {
            return;
        }

        $meta = array(
            'post_title' => $post_after->post_title,
            'post_type'  => $post_after->post_type,
            'status'     => $post_after->post_status,
        );

        if ( self::is_detailed() ) {
            // Detect which fields actually changed
            $changed = array();
            if ( $post_before->post_title   !== $post_after->post_title )   $changed[] = 'title';
            if ( $post_before->post_content !== $post_after->post_content )  $changed[] = 'content';
            if ( $post_before->post_excerpt !== $post_after->post_excerpt )  $changed[] = 'excerpt';
            if ( $post_before->post_status  !== $post_after->post_status )   $changed[] = 'status';

            $author = get_userdata( $post_after->post_author );
            $cats   = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
            $tags   = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

            $meta['changed_fields']       = implode( ', ', $changed );
            $meta['author']               = $author  ? $author->user_login : '#' . $post_after->post_author;
            $meta['word_count_before']    = self::word_count( $post_before->post_content );
            $meta['word_count_after']     = self::word_count( $post_after->post_content );
            $meta['permalink']            = get_permalink( $post_id );
            if ( ! empty( $cats ) ) $meta['categories'] = implode( ', ', $cats );
            if ( ! empty( $tags ) ) $meta['tags']        = implode( ', ', $tags );
            if ( $post_before->post_title !== $post_after->post_title ) {
                $meta['old_title'] = $post_before->post_title;
            }
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $post_id,
            'event_type' => 'content',
            'action'     => 'post_updated',
            'post_type'  => $post_after->post_type,
            'metadata'   => $meta,
        ) );
    }

    public function log_post_deletion( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) return;
        $post = get_post( $post_id );

        $meta = array(
            'post_title' => $post ? $post->post_title : '',
            'post_type'  => $post ? $post->post_type  : '',
        );

        if ( self::is_detailed() && $post ) {
            $author                  = get_userdata( $post->post_author );
            $meta['post_status']     = $post->post_status;
            $meta['post_author']     = $author ? $author->user_login : '#' . $post->post_author;
            $meta['post_date']       = $post->post_date;
            $meta['permalink']       = get_permalink( $post_id );
            $meta['comment_count']   = (int) $post->comment_count;
            $meta['deleted_by']      = get_current_user_id() ? get_userdata( get_current_user_id() )->user_login : 'system';
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $post_id,
            'event_type' => 'content',
            'action'     => 'post_deleted',
            'post_type'  => $post ? $post->post_type : '',
            'metadata'   => $meta,
        ) );
    }

    public function log_attachment( $post_id ) {
        $filename = basename( get_attached_file( $post_id ) );
        $meta     = array( 'filename' => $filename );

        if ( self::is_detailed() ) {
            $filepath         = get_attached_file( $post_id );
            $meta['filesize'] = $filepath && file_exists( $filepath ) ? size_format( filesize( $filepath ) ) : '';
            $meta['mime_type'] = get_post_mime_type( $post_id );
            $meta['url']      = wp_get_attachment_url( $post_id );

            // Image dimensions
            $img_meta = wp_get_attachment_metadata( $post_id );
            if ( ! empty( $img_meta['width'] ) && ! empty( $img_meta['height'] ) ) {
                $meta['dimensions'] = $img_meta['width'] . 'x' . $img_meta['height'] . 'px';
            }

            $uploader = get_userdata( get_current_user_id() );
            $meta['uploaded_by'] = $uploader ? $uploader->user_login : '';
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $post_id,
            'event_type' => 'content',
            'action'     => 'media_uploaded',
            'metadata'   => $meta,
        ) );
    }

    public function log_comment( $comment_id, $comment_approved ) {
        $comment = get_comment( $comment_id );

        $meta = array(
            'author'  => $comment->comment_author,
            'post_id' => $comment->comment_post_ID,
            'status'  => $comment_approved,
        );

        if ( self::is_detailed() ) {
            $post                    = get_post( $comment->comment_post_ID );
            $meta['post_title']      = $post  ? $post->post_title  : '';
            $meta['author_email']    = $comment->comment_author_email;
            $meta['author_url']      = $comment->comment_author_url;
            $meta['comment_excerpt'] = wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 20 );
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $comment_id,
            'user_id'    => $comment->user_id,
            'event_type' => 'content',
            'action'     => 'comment_posted',
            'metadata'   => $meta,
        ) );
    }

    /**
     * Post status transition — captures publish, draft, trash, restore events.
     */
    public function log_post_status_transition( $new_status, $old_status, $post ) {
        $ignored_types    = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' );
        $ignored_statuses = array( 'auto-draft', 'inherit' );

        if ( wp_is_post_revision( $post->ID ) ) return;
        if ( in_array( $post->post_type, $ignored_types, true ) ) return;
        if ( in_array( $new_status, $ignored_statuses, true ) ) return;
        if ( $new_status === $old_status ) return;

        $meta = array(
            'post_title' => $post->post_title,
            'post_type'  => $post->post_type,
            'old_status' => $old_status,
            'new_status' => $new_status,
        );

        if ( self::is_detailed() ) {
            $author              = get_userdata( $post->post_author );
            $meta['author']      = $author ? $author->user_login : '#' . $post->post_author;
            $meta['permalink']   = get_permalink( $post->ID );
            $meta['word_count']  = self::word_count( $post->post_content );
            $actor               = get_userdata( get_current_user_id() );
            $meta['changed_by']  = $actor ? $actor->user_login : 'system';
        }

        Actvt_Watcher_DB::insert_log( array(
            'object_id'  => $post->ID,
            'event_type' => 'content',
            'action'     => 'post_status_changed',
            'post_type'  => $post->post_type,
            'metadata'   => $meta,
        ) );
    }

    /**
     * Site content exported.
     */
    public function log_export( $args ) {
        $meta = array(
            'export_type' => isset( $args['content'] ) ? $args['content'] : 'all',
        );

        if ( self::is_detailed() ) {
            $actor              = get_userdata( get_current_user_id() );
            $meta['exported_by'] = $actor ? $actor->user_login : 'system';
            if ( isset( $args['author'] ) && $args['author'] ) {
                $author = get_userdata( $args['author'] );
                $meta['filter_author'] = $author ? $author->user_login : '#' . $args['author'];
            }
            if ( isset( $args['start_date'] ) ) $meta['filter_start'] = $args['start_date'];
            if ( isset( $args['end_date'] ) )   $meta['filter_end']   = $args['end_date'];
        }

        Actvt_Watcher_DB::insert_log( array(
            'event_type' => 'system',
            'action'     => 'content_exported',
            'metadata'   => $meta,
        ) );
    }
}
