<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * frontend-facing side of the site and the admin area.
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator;

use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API\API;
use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_Includes\CLI;
use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_Includes\I18n;
use Psr\Log\LoggerInterface;
use WP_CLI;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * frontend-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 */
class BH_WP_Support_Candy_To_KB_Support_Migrator {

	/**
	 * A PSR logger.
	 */
	protected LoggerInterface $logger;

	/**
	 * The main plugin functions.
	 */
	protected API $api;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the frontend-facing side of the site.
	 *
	 * @param API             $api The heavy lifting of the plugin.
	 * @param LoggerInterface $logger A PSR logger.
	 */
	public function __construct( API $api, LoggerInterface $logger ) {

		$this->logger = $logger;
		$this->api    = $api;

		$this->set_locale();
		$this->define_cli_hooks();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 */
	protected function set_locale(): void {

		$plugin_i18n = new I18n();

		add_action( 'init', array( $plugin_i18n, 'load_plugin_textdomain' ) );
	}

	/**
	 * Register the CLI commands.
	 */
	protected function define_cli_hooks(): void {

		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		$cli = new CLI( $this->api );

		try {
			WP_CLI::add_command( 'wpsc_kbs_migrator move_tickets', array( $cli, 'move_tickets' ) );
			WP_CLI::add_command( 'wpsc_kbs_migrator get_ticket_metadata', array( $cli, 'display_ticket_metadata' ) );
			WP_CLI::add_command( 'wpsc_kbs_migrator add_custom_ticket_status', array( $cli, 'add_custom_ticket_status' ) );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to register WP CLI commands: ' . $e->getMessage(), array( 'exception' => $e ) );
		}
	}
}
