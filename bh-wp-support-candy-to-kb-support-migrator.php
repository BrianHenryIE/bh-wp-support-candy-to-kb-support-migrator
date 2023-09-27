<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://example.com
 * @since             1.0.0
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 *
 * @wordpress-plugin
 * Plugin Name:       Support Candy to KB Support Migrator
 * Plugin URI:        http://github.com/username/bh-wp-support-candy-to-kb-support-migrator/
 * Description:       A WP CLI tool to migrate from Support Candy to KB Support. Use `wp help wpsc_kbs_migrator` for documentation and instructions.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            BrianHenryIE
 * Author URI:        http://example.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       bh-wp-support-candy-to-kb-support-migrator
 * Domain Path:       /languages
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator;

use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API\API;
use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_CLI_Logger\WP_CLI_Logger;
use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_Includes\Activator;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	throw new \Exception( 'WPINC not defined. WordPress is required.' );
}

require_once plugin_dir_path( __FILE__ ) . 'autoload.php';

/**
 * Current plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'BH_WP_SUPPORT_CANDY_TO_KB_SUPPORT_MIGRATOR_VERSION', '1.0.0' );

define( 'BH_WP_SUPPORT_CANDY_TO_KB_SUPPORT_MIGRATOR_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function instantiate_bh_wp_support_candy_to_kb_support_migrator(): BH_WP_Support_Candy_To_KB_Support_Migrator {

	$logger = new WP_CLI_Logger();
	$api    = new API( $logger );

	$plugin = new BH_WP_Support_Candy_To_KB_Support_Migrator( $api, $logger );

	return $plugin;
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and frontend-facing site hooks.
 */
$GLOBALS['bh_wp_support_candy_to_kb_support_migrator'] = instantiate_bh_wp_support_candy_to_kb_support_migrator();
