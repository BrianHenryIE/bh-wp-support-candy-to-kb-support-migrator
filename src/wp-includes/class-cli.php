<?php
/**
 * Run `wp wpsc_kbs_migrator --help` for commands and options.
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_Includes;

use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API\API;

/**
 * Registers the CLI commands.
 */
class CLI {

	/**
	 * The class handling the main plugin functions.
	 */
	protected API $api;

	/**
	 * Constructor
	 *
	 * @param API $api The main plugin functions.
	 */
	public function __construct( API $api ) {
		$this->api = $api;
	}

	/**
	 * Move tickets from Support Candy to KB Support.
	 *
	 * ## OPTIONS
	 *
	 * [<ticket_ids>...]
	 * : Optional list of ticket ids to move. Defaults to all tickets, beginning with the oldest.
	 *
	 * [--count=<int>]
	 * : Optional limit to the number of tickets moved. Defaults to 10.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--all]
	 * : Optional flag to run on all tickets.
	 *
	 * [--dry-run=<bool>]
	 * : Preview changes that will be made but do not make them. Defaults to true.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--delete-metadata=<array>]
	 * : Optional comma separated list of metadata keys to delete. This can be controlled by `wpsc_to_kbs_delete_metadata_keys` filter too. Setting via CLI overrides defaults.
	 * ---
	 * default: user_seen,to_email,date_closed,first_response,frt_checked,ticket_counted,last_reply_by,last_reply_on,_edit_lock
	 * ---
	 *
	 * [--ignore-metadata=<array>]
	 * : Optional comma separated list of metadata keys to preserve, without any corresponding migration. This can be controlled by `wpsc_to_kbs_ignore_metadata_keys` filter too. Setting via CLI overrides defaults.
	 * ---
	 * default: os,browser
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *      # Test migrating the 10 oldest tickets.
	 *      $ wp wpsc_kbs_migrator move_tickets --count=10 --debug=bh-wp-support-candy-to-kb-support-migrator
	 *
	 *      # Migrate the three named tickets.
	 *      $ wp wpsc_kbs_migrator move_tickets 123 128 135 --dry-run=false
	 *
	 *      # Run the full migration.
	 *      $ wp wpsc_kbs_migrator move_tickets --all --dry-run=false
	 *
	 * @param string[]                                                                                  $args The list of command line arguments.
	 * @param array{count:int, dry-run:bool, delete-metadata:string, ignore-metadata:string, all?:bool} $assoc_args The named command line arguments.
	 */
	public function move_tickets( array $args, array $assoc_args ): void {

		$ticket_ids = array_map( 'intval', $args );

		$count   = (int) $assoc_args['count'];
		$dry_run = 'false' !== $assoc_args['dry-run'];

		// TODO: trim quotation marks.
		$delete_metadata_keys = explode( ',', $assoc_args['delete-metadata'] );
		$ignore_metadata_keys = explode( ',', $assoc_args['ignore-metadata'] );

		if ( isset( $assoc_args['all'] ) && true === $assoc_args['all'] ) {
			$count = PHP_INT_MAX;
		}

		add_filter(
			'wpsc_to_kbs_delete_metadata_keys',
			function ( array $metakeys ) use ( $delete_metadata_keys ): array {
				return $metakeys + $delete_metadata_keys;
			}
		);

		add_filter(
			'wpsc_to_kbs_ignore_metadata_keys',
			function ( array $metakeys ) use ( $ignore_metadata_keys ): array {
				return $metakeys + $ignore_metadata_keys;
			}
		);

		$result = $this->api->move_tickets( $ticket_ids, $count, $dry_run );

		// WP_CLI::success();
		// "to migrate settings run..."
		// Add option flag to show tickets moved, delete on uninstall.
	}

	/**
	 * Display the metadata for a Support Candy ticket. Both ticket_id and meta_key parameters are optional, one must be supplied. Order is irrelevant. If a meta key is numeric it will be interpreted as a ticket id, but what are the odds!
	 *
	 * ## OPTIONS
	 *
	 * [<ticket_id>]
	 * : The ticket id whose metadata is being queried.
	 *
	 * [<meta_key>]
	 * : The type of meta data to query.
	 *
	 * ## EXAMPLES
	 *
	 *      # Display metadata for ticket 123.
	 *      $ wp wpsc_kbs_migrator get_ticket_metadata 123
	 *
	 *      # Display all metadata with key `assigned_agent`.
	 *      $ wp wpsc_kbs_migrator get_ticket_metadata assigned_agent
	 *
	 *      # Display `assigned_agent` meta for ticket 123.
	 *      $ wp wpsc_kbs_migrator get_ticket_metadata 123 assigned_agent
	 *
	 * @param string[] $args The unnamed command line arguments passed.
	 */
	public function display_ticket_metadata( array $args ): void {

		$support_candy_ticket_id = array_filter( array_map( 'intval', $args ) )[0] ?? null;

		$string_args = array_filter(
			$args,
			function ( string $element ) {
				return ! is_numeric( $element );
			}
		);
		$meta_key    = array_pop( $string_args );

		// TODO: Echo to the user that this is NOT the wp_post metadata.

		$data = $this->api->get_support_candy_metadata( $support_candy_ticket_id, $meta_key );

		$formatter = new \WP_CLI\Formatter(
			$assoc_args,
			array(
				'id',
				'ticket_id',
				'meta_key',
				'meta_value',
			)
		);

		$formatter->display_items( $data );
	}

	/**
	 * Add a custom ticket status in the KBS Custom Status plugin (required).
	 *
	 * ## OPTIONS
	 *
	 * <status_title>
	 * : The title of the new status to be added.
	 *
	 * [<status_on_delete>]
	 * :  The status you set here will replace any tickets that are in this custom status should it be deleted.
	 * ---
	 * default: open
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *      # Add the status "Awaiting Agent Reply".
	 *      $ wp wpsc_kbs_migrator add_custom_ticket_status "Awaiting Agent Reply"
	 *
	 *      # Add the status "Awaiting Customer Reply", whose tickets will change to "closed" if this new status is deleted.
	 *      $ wp wpsc_kbs_migrator add_custom_ticket_status "Awaiting Customer Reply" closed
	 *
	 * @param string[] $args The argv unlabelled input.
	 */
	public function add_custom_ticket_status( array $args ): void {
		list( $status, $status_on_delete ) = $args;

		$result = $this->api->register_custom_status( $status, $status_on_delete );
	}
}
