<?php

/**
 * The core plugin class.
 */
class Actvt_Watcher {

	/**
	 * Loader responsible for maintaining and registering all hooks.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->plugin_name = 'actvt-watcher';
		$this->version = ACTVT_WATCHER_VERSION;

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		
		// Database handler
		require_once ACTVT_WATCHER_PATH . 'includes/class-actvt-watcher-db.php';

		// Listeners
		require_once ACTVT_WATCHER_PATH . 'includes/listeners/class-actvt-watcher-auth-listener.php';
		require_once ACTVT_WATCHER_PATH . 'includes/listeners/class-actvt-watcher-content-listener.php';
		require_once ACTVT_WATCHER_PATH . 'includes/listeners/class-actvt-watcher-system-listener.php';

        // Cron
        require_once ACTVT_WATCHER_PATH . 'includes/cron/class-actvt-watcher-cron.php';

		// Admin
		require_once ACTVT_WATCHER_PATH . 'admin/class-actvt-watcher-admin.php';
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Actvt_Watcher_Admin( $this->plugin_name, $this->version );
        add_action( 'admin_menu',                        array( $plugin_admin, 'add_plugin_admin_menu' ) );
        add_action( 'admin_post_actvt_export_logs',      array( $plugin_admin, 'export_logs' ) );
        add_action( 'admin_post_actvt_save_settings',    array( $plugin_admin, 'save_settings' ) );
        add_action( 'admin_post_actvt_purge_now',        array( $plugin_admin, 'purge_now' ) );
        // Settings export / import
        add_action( 'admin_post_actvt_export_settings',  array( $plugin_admin, 'export_settings' ) );
        add_action( 'admin_post_actvt_import_settings',  array( $plugin_admin, 'import_settings' ) );
        // Filter presets (AJAX)
        add_action( 'wp_ajax_actvt_save_filter_preset',   array( $plugin_admin, 'save_filter_preset' ) );
        add_action( 'wp_ajax_actvt_delete_filter_preset', array( $plugin_admin, 'delete_filter_preset' ) );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * (and listeners which run everywhere).
	 */
	private function define_public_hooks() {
        
        // Initialize listeners
        new Actvt_Watcher_Auth_Listener();
        new Actvt_Watcher_Content_Listener();
        new Actvt_Watcher_System_Listener();

        // Initialize Cron
        new Actvt_Watcher_Cron();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		// In a more complex plugin, we'd use a loader class to orchestrate add_action/add_filter calls.
        // For simplicity here, we're instantiating classes directly in define_public_hooks.
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
