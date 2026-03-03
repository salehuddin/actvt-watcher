<?php
/**
 * Plugin Name:       ACTVT Watcher
 * Plugin URI:        https://github.com/salehuddin/actvt-watcher
 * Description:       Passively captures state changes across the WordPress ecosystem.
 * Version:           1.0.3
 * Author:            Salehuddin
 * Author URI:        https://github.com/salehuddin
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       actvt-watcher
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'ACTVT_WATCHER_VERSION', '1.0.3' );
define( 'ACTVT_WATCHER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACTVT_WATCHER_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_actvt_watcher() {
	require_once ACTVT_WATCHER_PATH . 'includes/class-actvt-watcher-activator.php';
	Actvt_Watcher_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_actvt_watcher() {
	// subprocesses cleanup if needed
}

register_activation_hook( __FILE__, 'activate_actvt_watcher' );
register_deactivation_hook( __FILE__, 'deactivate_actvt_watcher' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once ACTVT_WATCHER_PATH . 'includes/class-actvt-watcher.php';

/**
 * Begins execution of the plugin.
 */
function run_actvt_watcher() {
	$plugin = new Actvt_Watcher();
	$plugin->run();
}
run_actvt_watcher();
