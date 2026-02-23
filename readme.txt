=== ACTVT Watcher ===
Contributors: salehuddin
Tags: activity log, security, audit log, logging
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Passively captures state changes across the WordPress ecosystem to provide an audit trail of user and system activity.

== Description ==

ACTVT Watcher is a robust, passive activity logging plugin for WordPress. It silently captures state changes across your site—such as logins, content edits, theme/plugin activations, and security events—and stores them in a custom, highly-performant database table for review.

= Features =

* **Passive Event Architecture:** Silently listens to WordPress Core hooks (Auth, Content, Media, System) without bloating the native `wp_options` or `wp_postmeta` tables.
* **High-Performance Storage:** Uses a dedicated `wp_actvt_watcher_logs` table, keeping your database queries fast on high-traffic sites.
* **Rich Dashboard:** View logs via a dashboard featuring sorting, complex date/event filtering, user correlation, and a toggleable JSON Metadata inspector.
* **Security Alerts:** Set up instant real-time email alerts for high-risk actions (e.g., failed logins, role escalations, theme file edits).
* **Digest Reports:** Schedule Daily, Weekly, or Monthly HTML email reports aggregating site activity without needing a third-party service.
* **Smart Exclusions:** Prevent noisy logs by excluding specific Event Types, Post Types, User Accounts, IP Addresses, or Role Levels from being tracked.
* **Auto-Pruning:** Keep your database lean using the background Daily Cron Job to automatically purge records older than `X` days, or cap your log table at a maximum row count.
* **Flat-File Mirroring:** Optionally mirror every log entry to a `.log` JSONL file on disk for easy ingestion by ELK, Splunk, Datadog, or external SIEMs.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/actvt-watcher` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. The plugin automatically creates its custom database table upon activation and begins logging immediately. No external setup is required!

== Frequently Asked Questions ==

= Does it bloat the native WordPress tables? =
No, ACTVT Watcher uses a dedicated custom table (`wp_actvt_watcher_logs`) to ensure your main site tables stay fast and uncluttered.

= Will this slow down my site? =
No, the plugin writes logs asynchronously where possible and uses highly optimized queries for both inserting and reading logs.

== Screenshots ==

1. The main Activity Dashboard showing the log entries.
2. Setting exclusions and alerts in the Settings panel.

== Changelog ==

= 1.0.0 =
* Initial release on the WordPress plugin repository.
