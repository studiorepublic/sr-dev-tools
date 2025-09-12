<?php
/**
 * Plugin Name: SR Dev Tools
 * Description: Sync WordPress to version-controlled JSON files for easy Git workflows. Manage database dumps and plugin zip exports
 * Version:     1.4.0
 * Author:      Studio Republic (based on original plugin by Robert DeVore)
 * Author URI:  https://www.studiorepublic.com
 * Text Domain: srdt
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/studiorepublic/sr-dev-tools/
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

require 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/studiorepublic/sr-dev-tools/',
	__FILE__,
	'sr-dev-tools'
);

// Set the branch that contains the stable release.
$myUpdateChecker->setBranch( 'main' );

// Check if Composer's autoloader is already registered globally.
if ( ! class_exists( 'RobertDevore\WPComCheck\WPComPluginHandler' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler( plugin_basename( __FILE__ ), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/' );

// Define constants for the plugin.
define( 'SRDT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SRDT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SRDT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SRDT_PLUGIN_VERSION', '1.4.0' );

require_once SRDT_PLUGIN_PATH . 'includes/functions.php';
require_once SRDT_PLUGIN_PATH . 'includes/class-sync-posts.php';
require_once SRDT_PLUGIN_PATH . 'includes/hooks.php';
require_once SRDT_PLUGIN_PATH . 'commands/class-wp-cli-commands.php';
if ( is_admin() ) {
	require_once SRDT_PLUGIN_PATH . 'admin/admin-menu.php';
    require_once SRDT_PLUGIN_PATH . 'admin/admin-page.php';
}

// Hook into post save.
add_action( 'save_post', [ 'SRDT_Sync_Posts', 'export_post_to_json' ], 10, 2 );

/**
 * Load plugin text domain for translations.
 * 
 * @since  1.0.0
 * @return void
 */
function srdt_load_textdomain() {
	load_plugin_textdomain( 'srdt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'srdt_load_textdomain' );

/**
 * Add settings link to plugin action links.
 * 
 * @param mixed $links
 * 
 * @since  1.0.0
 * @return array
 */
function srdt_add_settings_link( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=sr-dev-tools' ) . '">' . esc_html__( 'Settings', 'srdt' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'srdt_add_settings_link' );
