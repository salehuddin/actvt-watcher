# ACTVT Watcher — Developer Guide

This document is intended for maintainers and developers looking to extend, debug, or understand the architecture of the **ACTVT Watcher** plugin.

## 1. Architecture Overview

ACTVT Watcher is built using an Object-Oriented, event-driven architecture based loosely on the WordPress Plugin Boilerplate structure. It is strictly segmented into public (future-proofing) and admin responsibilities.

### Core Components

- **`actvt-watcher.php`**: The main plugin file. Handles direct WordPress header requirements and triggers standard activation/deactivation hooks. Calls `run_actvt_watcher()` to bootstrap the plugin.
- **`includes/class-actvt-watcher.php`**: The core registry class. It coordinates all other classes, instantiates the loader, and registers all hooks (actions and filters) via the `Actvt_Watcher_Loader`.
- **`includes/class-actvt-watcher-db.php`**: A singleton wrapper for all `wpdb` interactions. It handles log insertion, formatting metadata, checking exclusions (IPs, users, post types, event types), flat-file mirroring, and triggering security alerts.
- **`includes/listeners/`**: Contains modular classes that actually hook into WordPress core events.
  - `class-actvt-watcher-auth-listener.php`: Handles logins, logouts, profile updates, role changes.
  - `class-actvt-watcher-content-listener.php`: Handles post/page creation, updates, deletions, and media uploads.
  - `class-actvt-watcher-system-listener.php`: Handles plugin activations, theme switches, WordPress core updates, and core settings changes.
- **`includes/cron/`**: Contains schedule event handlers.
  - `class-actvt-watcher-cron.php`: Registers WP-Cron schedules, handles the daily metadata retention purge, and the periodic (Daily/Weekly/Monthly) HTML email digest reports.
- **`admin/class-actvt-watcher-admin.php`**: Handles all wp-admin logic. Enqueues styles/scripts, registers menu pages, and handles POST requests (saving settings, exporting logs, bulk deleting logs).
- **`admin/partials/`**: Contains the raw PHP/HTML views for the admin dashboard.

---

## 2. Global Plugin Settings

All plugin settings are stored in a single serialized array in the `wp_options` table under the key `actvt_watcher_settings`.

To retrieve settings anywhere in the plugin, use the standard WP function:

```php
$settings = get_option( 'actvt_watcher_settings', array() );
$my_setting = isset( $settings['my_setting'] ) ? $settings['my_setting'] : 'default_value';
```

When modifying the settings page (`admin/partials/actvt-watcher-settings-display.php`), ensure new `<input>` fields match the keys expected in the `save_settings()` method inside `admin/class-actvt-watcher-admin.php`.

---

## 3. Extending the Plugin (Adding New Listeners)

To add logging for a new feature (e.g., WooCommerce orders, bbPress forums), follow these steps:

### Step 1: Create a Listener Class

Create a new file in `includes/listeners/class-actvt-watcher-custom-listener.php`.

```php
<?php
class Actvt_Watcher_Custom_Listener {
    public function __construct() {}

    public function log_custom_event( $event_id, $event_data ) {
        // Build metadata array
        $metadata = array(
            'event_id' => $event_id,
            'details'  => $event_data
        );

        // Send to Database wrapper
        Actvt_Watcher_DB::insert_log(
            'custom_category',    // Context (event_type)
            'custom_event_fired', // Action hook/slug
            get_current_user_id(), // User ID
            $event_id,            // Object ID
            $metadata             // JSON-serializable array
        );
    }
}
```

### Step 2: Register the Hooks in the Core Class

Open `includes/class-actvt-watcher.php`. Instantiate your new class and use the loader to register the WP hooks.

```php
private function define_admin_hooks() {
    // ... existing listeners ...

    // Register Custom Listener
    $custom_listener = new Actvt_Watcher_Custom_Listener();
    $this->loader->add_action( 'some_third_party_hook', $custom_listener, 'log_custom_event', 10, 2 );
}
```

> **Note:** The `Actvt_Watcher_DB::insert_log` method automatically handles timestamping, grabbing the user's role, grabbing their IP (including proxy/Cloudflare resolving), checking against global exclusion settings, writing to disk (if Flat File Mirroring is on), and triggering Security Alerts.

---

## 4. Database Schema Lifecycle

The custom database table `*_actvt_watcher_logs` is created via `dbDelta()` when the plugin is activated (`includes/class-actvt-watcher-activator.php`).

If you need to add columns in a future update:

1.  Modify `Actvt_Watcher_Activator::activate()` with the new schema.
2.  Bump the plugin variation ID/version.
3.  Ensure `dbDelta()` runs on update, or add an upgrade routine to migrate the existing table.

### Current Table Structure

- `id` (BIGINT, Auto Increment)
- `timestamp` (DATETIME, UTC)
- `user_id` (BIGINT)
- `user_role` (VARCHAR)
- `event_type` (VARCHAR) - _auth, content, system, security, general_
- `action` (VARCHAR)
- `object_id` (BIGINT)
- `metadata` (LONGTEXT) - _Stores JSON_
- `ip_address` (VARCHAR)

---

## 5. View Layer Architecture

The Admin views emphasize standard WordPress UI components while keeping CSS isolated to `admin/css/actvt-watcher-admin.css`.

- **Log Viewer (`actvt-watcher-admin-display.php`)**: Heavily relies on `$_GET` parameters for filtering and pagination. Form submissions reload the page with new queries. Bulk actions rely on standard WP nonces.
- **Settings (`actvt-watcher-settings-display.php`)**: Uses a flexbox layout with an `IntersectionObserver` sticky JavaScript sidebar for navigation.

To add a new settings section:

1.  Add an anchor link to the `<div class="actvt-settings-nav">`.
2.  Add a `<div class="actvt-settings-card" id="your-new-id">`.
3.  Add the inputs.
4.  Capture and sanitize the inputs in `admin/class-actvt-watcher-admin.php -> save_settings()`.

---

## 6. Testing & Debugging Tricks

- **Bypassing Exclusions**: Temporarily comment out the `return false;` conditions in `Actvt_Watcher_DB::insert_log()` if your test user is excluded.
- **Testing Email Digests**: You don't have to wait for the cron schedule. You can manually trigger the digest hook by hitting:
  `wp-admin/admin-ajax.php?action=actvt_test_digest` (If you register an isolated test hook for it) or by using a WP Crontrol plugin to manually fire `actvt_watcher_send_report`.
- **Metadata Format**: If you add new metadata keys, the Log Viewer will automatically render them nicely formatted (Title Cased) when "Clean" display mode is active. You don't need to add a custom rendering template for every new event type.
