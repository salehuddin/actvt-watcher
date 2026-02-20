# ACTVT Watcher — Documentation

**Version:** 1.0.0  
**Requires:** WordPress 5.5+, PHP 7.4+

---

## Overview

ACTVT Watcher is a **passive activity logging plugin** for WordPress. It silently captures state changes across your site — logins, content edits, plugin changes, security events — and stores them in a custom database table for review.

No action is required after activation. All logging happens automatically in the background.

---

## Log Viewer

Navigate to **ACTVT Watcher** in your WordPress admin sidebar to view the activity log.

### Filter Bar

Apply any combination of filters to narrow down log entries:

| Filter             | Description                                                                                               |
| ------------------ | --------------------------------------------------------------------------------------------------------- |
| **Event Type**     | Multi-select dropdown — filter by one or more event categories (Auth, Content, System, Security, General) |
| **User**           | Show activity for a specific user only                                                                    |
| **Period**         | Quick presets: This Month, Last Month, Last 3 Months, or Custom date range                                |
| **Date From / To** | Visible when Period is set to Custom                                                                      |
| **Search**         | Searches across the Action, Metadata, and IP Address fields                                               |

Click **Apply** to run the filtered query. Click **Reset** to clear all filters.

### Table Columns

| Column         | Description                                               |
| -------------- | --------------------------------------------------------- |
| **Timestamp**  | When the event occurred (server local time)               |
| **User**       | Who triggered it — login name, role badge, and email      |
| **Event Type** | Category badge: auth, content, system, security, general  |
| **Action**     | Specific event slug (e.g. `post_updated`, `login_failed`) |
| **Details**    | Object ID and JSON metadata with contextual information   |
| **IP Address** | Client IP at the time of the event                        |

### Pagination & Per-Page

Use the **per-page selector** on the right of the table header to change how many rows are shown. Available: 25, 50, 100, 200. Your site default can be configured in **Settings → Log Viewer Defaults**.

### Deleting Entries

- **Single row:** Click the **Delete** link that appears on hover in any row. A confirmation prompt will display.
- **Bulk delete:** Tick the checkboxes on the left of each row (or use **Select All / None**), then choose **Delete Selected** from the bulk action dropdown and click **Apply**.

### Exporting Logs

Click the **Export CSV** button in the filter bar. The export respects all active filters — only the rows you're currently viewing will be included. The file includes all columns and uses a UTF-8 BOM for correct display in Excel.

---

## Events Tracked

### 🔐 Auth Events

| Event                     | Hook                |
| ------------------------- | ------------------- |
| Login success             | `wp_login`          |
| Login failure             | `wp_login_failed`   |
| Logout                    | `wp_logout`         |
| Profile update            | `profile_update`    |
| New user registration     | `user_register`     |
| User role changed         | `set_user_role`     |
| User deleted              | `delete_user`       |
| Password reset requested  | `retrieve_password` |
| Password reset completed  | `password_reset`    |
| Password changed manually | `wp_set_password`   |

### 📄 Content Events

| Event                                                | Hook                     |
| ---------------------------------------------------- | ------------------------ |
| Post/page updated                                    | `post_updated`           |
| Post status changed (publish, draft, trash, restore) | `transition_post_status` |
| Post/page deleted                                    | `before_delete_post`     |
| Media uploaded                                       | `add_attachment`         |
| Comment posted                                       | `comment_post`           |
| Site content exported                                | `export_wp`              |

### ⚙️ System Events

| Event                       | Hook                          |
| --------------------------- | ----------------------------- |
| Plugin activated            | `activated_plugin`            |
| Plugin deactivated          | `deactivated_plugin`          |
| Plugin/theme updated        | `upgrader_process_complete`   |
| Theme switched              | `switch_theme`                |
| WordPress core updated      | `_core_updated_successfully`  |
| Settings/option changed     | `updated_option`              |
| Permalink structure changed | `permalink_structure_changed` |
| Navigation menu created     | `wp_create_nav_menu`          |
| Navigation menu updated     | `wp_update_nav_menu`          |
| Navigation menu deleted     | `wp_delete_nav_menu`          |
| Theme file editor accessed  | `load-theme-editor.php`       |
| Plugin file editor accessed | `load-plugin-editor.php`      |

### 🛡️ Security Events

Security events are a subset of auth and system events that carry higher risk. They are tagged with the `security` event type in addition to being in their own category.

| Event                     |
| ------------------------- |
| Failed login attempts     |
| Password resets           |
| Theme file editor access  |
| Plugin file editor access |

---

## Settings

Navigate to **ACTVT Watcher → Settings** to configure the plugin.

---

### Section 1 — Logging Exclusions

Control which events, users, IPs, and post types are **not** recorded.

#### Exclude Event Types

Check any event category to completely disable logging for that type. For example, checking **General** stops all general-category events from being stored.

#### Exclude Users

Select one or more users whose activity should be ignored entirely. Useful for automated accounts, deployment users, or monitoring bots that log in frequently.

#### Exclude IP Addresses

Enter IPs one per line (or comma-separated) to ignore traffic from those addresses. Supports individual IPs. Useful for:

- Your office static IP
- Known load balancer / CDN IPs
- Uptime monitoring services

> **Note:** ACTVT Watcher automatically detects the real client IP through Cloudflare, reverse proxy, and CDN headers (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, etc.).

#### Exclude Post Types

Check any post type to stop logging content events (create, update, delete, status change) for that type. All registered public post types — including custom post types from plugins — are listed automatically.

#### Minimum Role to Log

Set the lowest user role that will have activity recorded. Users below this role are ignored completely.

| Setting               | Records activity for                       |
| --------------------- | ------------------------------------------ |
| All users (default)   | Everyone, including guests                 |
| Subscriber and above  | All logged-in users                        |
| Contributor and above | Contributor, Author, Editor, Administrator |
| Editor and above      | Editor and Administrator only              |
| Administrator only    | Only administrators                        |

> Custom roles from plugins (WooCommerce, membership plugins, etc.) are ranked automatically based on their WordPress capabilities — no manual configuration needed.

---

### Section 2 — Storage & Retention

#### Delete logs older than N days

Logs older than this many days are automatically deleted by the daily background job. Set to **0** to keep logs forever.

#### Maximum log rows

Caps the total number of rows in the log table. When a new entry is inserted and the table exceeds this cap, the oldest rows are deleted automatically. Set to **0** for no limit.

#### Auto-export Before Purge

When enabled, the daily purge job sends a CSV email attachment containing the about-to-be-deleted rows **before** deleting them. Uses the same recipients as Email Reports.

#### Purge Now

Immediately deletes all log entries that fall outside the configured retention window. Requires a retention period greater than 0 to be set.

---

### Section 3 — Security Alerts

Send an **instant email** when specific high-risk events occur — separate from the periodic digest.

#### Enable Instant Alerts

Toggle on to activate real-time security notifications.

#### Alert Events

Choose which events trigger an instant alert:

| Event                       | Notes                                                      |
| --------------------------- | ---------------------------------------------------------- |
| Failed Login                | Triggers only after the Failed Login Threshold is exceeded |
| User Role Changed           | Any role escalation or change                              |
| User Deleted                | Any user account deletion                                  |
| Password Reset              | When a reset link is requested or completed                |
| Theme File Editor Accessed  | High security risk                                         |
| Plugin File Editor Accessed | High security risk                                         |

#### Failed Login Threshold

How many failed login attempts from the **same IP address within 1 hour** before an alert is sent. Default: 5. This prevents alert fatigue from isolated typos.

#### Alert Recipients

Comma-separated email addresses for security alerts. Leave blank to use the WordPress admin email. Can be different from the report recipients (e.g. a security team address).

---

### Section 4 — Email Reports

Send a periodic HTML summary of activity to designated recipients.

#### Enable Email Reports

Toggle on to activate scheduled report emails.

#### Interval

- **Daily** — Covers yesterday's activity
- **Weekly** — Covers the previous Monday to Sunday
- **Monthly** — Covers the previous calendar month

#### Send Time

What time of day the report should be sent (server local time). The cron schedule is updated automatically when you save settings.

#### Recipients

Comma-separated email addresses. Also used for Auto-export Before Purge attachments.

> The **Next report** and **Next purge** scheduled times are shown at the bottom of the Settings page.

---

### Section 2b — Mirror Logs to Flat File

Write every event to a daily rotating `.log` file on disk — useful as a backup, for ingestion by log management systems (ELK, Datadog, Splunk, etc.), or for audit trails that must survive a database loss.

#### How to enable

1. Go to **ACTVT Watcher → Settings → Mirror Logs to Flat File**
2. Toggle **Enable file mirroring** on
3. _(Optional)_ Enter a custom absolute server path for the log directory. Leave blank to use the default:
   ```
   wp-content/uploads/actvt-logs/
   ```
4. Click **Save Settings**

#### File format

Each event is written as a single JSON line:

```json
{
  "ts": "2026-02-20 10:12:34",
  "user_id": 1,
  "user_role": "administrator",
  "event_type": "auth",
  "action": "login_success",
  "object_id": 0,
  "metadata": "{\"username\":\"admin\"}",
  "ip": "127.0.0.1"
}
```

Files are named `actvt-YYYY-MM-DD.log` and rotate automatically at midnight. A `.htaccess` blocking direct browser access is written to the directory on first use.

---

### Section 5 — Metadata Detail Level

Controls how much contextual data is stored with each event.

| Mode                 | Description                                                               |
| -------------------- | ------------------------------------------------------------------------- |
| **Simple** (default) | Core identifiers only — lower storage, faster inserts                     |
| **Detailed**         | Full context per event (changed fields, old/new values, user-agent, etc.) |

See the comparison table in **Settings → Metadata Detail Level** for a per-event breakdown of what each mode captures.

---

### Section 6 — Log Viewer Defaults

Set the default view applied when you open the log viewer without any URL filters.

#### Rows per page

How many log entries to show per page: 25, 50, 100, or 200.

#### Default date range

Which period preset the log viewer opens to:

- **All time** (default) — no date filter applied
- **This month**
- **Last month**
- **Last 3 months**

---

### Export & Import Settings

Back up your plugin configuration or copy it to another WordPress site in seconds.

#### How to export

1. Go to **ACTVT Watcher → Settings → Export & Import Settings**
2. Click **Download Settings JSON**
3. A file named `actvt-watcher-settings-YYYY-MM-DD.json` will be downloaded

#### How to import

1. Go to **ACTVT Watcher → Settings → Export & Import Settings**
2. Click **Choose File** and select a previously exported `.json` file
3. Click **Import Settings**
4. Confirm the prompt — imported values are **merged** into the current settings (keys present in the file overwrite current values; keys not in the file are left unchanged)

> The imported file must have been exported from ACTVT Watcher. Importing a file from a different plugin will be rejected.

---

## How-To: Saved Filter Presets

Save any combination of filters (event type, user, date range, search term) as a named preset so you can return to it instantly without reconfiguring the filters every time.

### Saving a preset

1. Navigate to **ACTVT Watcher** (log viewer)
2. Apply the filters you want to save using the filter bar
3. Click **Apply** to run the query
4. In the **Saved Filters** bar below the filter panel, click **+ Save Current Filter**
5. Enter a name (e.g. _"Admin logins this month"_) and click **Save**
6. The page reloads with your preset stored — it will appear in the dropdown from now on

### Loading a preset

1. In the **Saved Filters** bar, select a preset from the dropdown
2. Click **Load** — the page reloads with all filters from the preset applied

### Deleting a preset

1. In the **Saved Filters** bar, select the preset to remove
2. Click **Delete** and confirm the prompt

> Presets are stored per admin user — each user manages their own preset list.

---

## Database

All logs are stored in the custom table `{prefix}_actvt_watcher_logs` with the following columns:

| Column       | Type     | Description                                        |
| ------------ | -------- | -------------------------------------------------- |
| `id`         | BIGINT   | Auto-increment primary key                         |
| `timestamp`  | DATETIME | Server time when the event occurred                |
| `user_id`    | BIGINT   | WordPress user ID (0 for guests/system)            |
| `user_role`  | VARCHAR  | Role at the time of the action                     |
| `event_type` | VARCHAR  | Category: auth, content, system, security, general |
| `action`     | VARCHAR  | Specific event slug                                |
| `object_id`  | BIGINT   | ID of the affected post, user, or attachment       |
| `metadata`   | LONGTEXT | JSON-encoded contextual data                       |
| `ip_address` | VARCHAR  | Client IP address                                  |

---

## Security

- All admin pages require the `manage_options` capability
- All form submissions use WordPress nonce verification
- All database writes use `$wpdb->prepare()` to prevent SQL injection
- Delete and bulk-delete operations use nonce-verified POST/GET requests
- Export and purge endpoints verify both nonce and capability before any action
- AJAX preset handlers verify nonce and capability on every request
- Mirror log directory is protected by an `.htaccess` blocking direct browser access
- Settings import validates the file signature before applying any values
