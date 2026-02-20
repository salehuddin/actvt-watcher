# ACTVT Watcher

**ACTVT Watcher** is a robust, passive activity logging plugin for WordPress. It silently captures state changes across your site—such as logins, content edits, theme/plugin activations, and security events—and stores them in a custom, highly-performant database table for review.

## 🚀 Features

- **Passive Event Architecture:** Silently listens to WordPress Core hooks (Auth, Content, Media, System) without bloating the native `wp_options` or `wp_postmeta` tables.
- **High-Performance Storage:** Uses a dedicated `wp_actvt_watcher_logs` table, keeping your database queries fast on high-traffic sites.
- **Rich Dashboard:** View logs via a React-less, pure PHP/JS dashboard featuring sorting, complex date/event filtering, user correlation, and a toggleable JSON Metadata inspector.
- **Security Alerts:** Set up instant real-time email alerts for high-risk actions (e.g., failed logins, role escalations, theme file edits).
- **Digest Reports:** Schedule Daily, Weekly, or Monthly HTML email reports aggregating site activity without needing a third-party service.
- **Smart Exclusions:** Prevent noisy logs by excluding specific Event Types, Post Types, User Accounts, IP Addresses, or Role Levels from being tracked.
- **Auto-Pruning:** Keep your database lean using the background Daily Cron Job to automatically purge records older than `X` days, or cap your log table at a maximum row count.
- **Flat-File Mirroring:** Optionally mirror every log entry to a `.log` JSONL file on disk for easy ingestion by ELK, Splunk, Datadog, or external SIEMs.

## 📖 Documentation

The full documentation and technical specifications for configuring and using the plugin can be found in the included markdown guides:

- **[User Guide (`USER_GUIDE.md`)](./USER_GUIDE.md)**: Full configuration instructions, retention policies, and UI overviews for site administrators.
- **[Developer Guide (`DEVELOPER.md`)](./DEVELOPER.md)**: Architectural breakdown, database schema, and an extension guide on how to register custom event listeners for third-party plugins.

## 🛠️ Installation

1. Download the latest `.zip` release of the plugin or clone this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/salehuddin/actvt-watcher.git
   ```
2. Navigate to your WordPress Admin dashboard -> **Plugins**.
3. Locate **ACTVT Watcher** and click **Activate**.
4. Upon activation, the plugin automatically runs `dbDelta()` to create its custom tables. No immediate configuration is required; it begins logging instantly.

## 🤝 Contributing

Pull requests are welcome. For major changes or architectural shifts, please open an issue first to discuss the proposed updates. See [`DEVELOPER.md`](./DEVELOPER.md) for a guide on how to safely build and register new modular Listeners.

## 📄 License

This project is licensed under the GPLv2 (or later) License - see standard WordPress licensing guidelines for details.
