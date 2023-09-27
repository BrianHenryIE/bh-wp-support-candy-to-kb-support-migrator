<?php
/**
 * Support Candy database functions.
 *
 * Support Candy uses two custom database tables, as defined~:
 *
 * ```
 * create table wp_wpsc_ticket
 * (
 * id               bigint auto_increment
 * primary key,
 * ticket_status    int,
 * customer_name    tinytext,
 * customer_email   tinytext,
 * ticket_subject   longtext,
 * user_type        varchar(30),
 * ticket_category  int,
 * ticket_priority  int,
 * date_created     datetime,
 * date_updated     datetime,
 * ip_address       varchar(30),
 * agent_created    int    default 0,
 * ticket_auth_code longtext,
 * historyId        bigint default 0,
 * active           int    default 1 null
 * )
 * engine = MyISAM
 * charset = utf8;
 * ```
 *
 * ```
 * create table wp_wpsc_ticketmeta
 * (
 * id         bigint auto_increment
 * primary key,
 * ticket_id  bigint,
 * meta_key   longtext,
 * meta_value longtext null
 * )
 * engine = MyISAM
 * charset = utf8;
 *
 * create index ticket_id
 * on wp_wpsc_ticketmeta (ticket_id);
 * ```
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API;

use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;
use WP_Query;

/**
 * Support Candy's own functions weren't very reusable.
 *
 * @see WPSC_Functions
 *
 * There are unavoidable direct database queries and meta queries.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
 */
class SupportCandy {
	use LoggerAwareTrait;

	/**
	 * Constructor
	 *
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->setLogger( $logger );
	}

	/**
	 * This returns the ID of the ticket in the wpsc table. NOT the wp post id.
	 *
	 * To enable using dry-run, the $nth parameter allows iterating forward.
	 *
	 * @param int $nth The ticket offset to fetch.
	 * @return ?int The Support Candy ticket id (number), _not_ the post ID.
	 */
	public function get_next_support_candy_ticket_id( int $nth = 0 ): ?int {

		global $wpdb;

		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wpsc_ticket LIMIT 1 OFFSET %d",
				$nth
			),
			ARRAY_A
		);

		if ( empty( $result ) ) {
			return null;
		}

		return (int) $result[0]['id'];
	}

	/**
	 * Given a ticket id, get all the wp_posts that are for that ticket, including the first post.
	 *
	 * Support Candy does not use a WordPress parent post relationship.
	 *
	 * @param int $support_candy_ticket_id The sequential thread number for the support candy ticket (NOT the post_id).
	 * @return WP_Post[]
	 */
	public function get_support_candy_thread_wp_posts( int $support_candy_ticket_id ): array {

		$args  = array(
			'post_type'  => 'wpsc_ticket_thread',
			'meta_query' => array(
				array(
					'key'     => 'ticket_id',
					'value'   => (string) $support_candy_ticket_id,
					'compare' => '=',
				),
			),
			'orderby'    => 'ID',
			'order'      => 'ASC', // oldest first.
		);
		$query = new WP_Query( $args );

		/**
		 * The WP_Posts for the thread.
		 *
		 * @var WP_Post[] $posts
		 */
		$posts = $query->get_posts();

		return $posts;
	}

	/**
	 * Return the ticket's data from the database tables created by support candy.
	 *
	 * The `wpsc_ticket` table has columns: id, ticket_status, customer_name, customer_email, ticket_subject, user_type, ticket_category, ticket_priority, date_created, date_updated, ip_address, agent_created, ticket_auth_code, historyId, active.
	 * The `wpsc_ticket_meta` table has meta-keys: assigned_agent, prev_assigned_agent, to_email, date_closed, frt_checked, ticket_counted, first_response, woo-product, woo-order, extra_ticket_users, last_reply_by, last_reply_on. And possibly more.
	 *
	 * @param int $support_candy_ticket_id The ticket to query data for.
	 * @return array{id:string, ticket_status:string, customer_name:string, customer_email:string, ticket_subject:string, user_type:string, ticket_category:string, ticket_priority:string, date_created:string, date_updated:string, ip_address:string, agent_created:string, ticket_auth_code:string, historyId:string, active:string, meta:array<string,string>}
	 * @throws Exception When meta keys exist in duplicate (which has not been accounted for in the code).
	 */
	public function get_support_candy_tables_data( int $support_candy_ticket_id ): array {
		global $wpdb;

		$result_main = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpsc_ticket WHERE id = %d",
				$support_candy_ticket_id
			),
			ARRAY_A
		);
		$result      = $result_main[0];

		// Many rows: id, ticket_id, meta_key, meta_value.

		$result_meta = $this->get_support_candy_metadata_table_data( $support_candy_ticket_id );

		$result['meta'] = array();
		foreach ( $result_meta as $meta ) {
			if ( empty( $meta['meta_value'] ) ) {
				continue;
			}
			if ( ! isset( $result['meta'][ $meta['meta_key'] ] ) ) {
				$result['meta'][ $meta['meta_key'] ] = array();
			}
			$result['meta'][ $meta['meta_key'] ][] = $meta['meta_value'];
		}

		return $result;
	}

	/**
	 * Query the `wp_wpsc_ticketmeta` table for meta data by ticket id or by key.
	 *
	 * `SELECT DISTINCT(meta_key) FROM `wp_wpsc_ticketmeta`` returned:
	 * * assigned_agent
	 * * prev_assigned_agent
	 * * to_email
	 * * date_closed
	 * * frt_checked
	 * * ticket_counted
	 * * first_response
	 * * woo-product
	 * * woo-order
	 * * extra_ticket_users
	 * * last_reply_by
	 * * last_reply_on
	 *
	 * @param ?int    $support_candy_ticket_id Optional ticket id. If absent all entries matching the key will be returned.
	 * @param ?string $meta_key Optional meta key. If absent all entries for the specified ticket will be returned.
	 * @return array<int, array{id:string, ticket_id:string, meta_key:string, meta_value:string}>
	 * @throws Exception When both parameters are null.
	 */
	public function get_support_candy_metadata_table_data( ?int $support_candy_ticket_id = null, ?string $meta_key = null ): array {
		global $wpdb;

		if ( is_null( $meta_key ) && is_null( $support_candy_ticket_id ) ) {
			throw new Exception();
		}

		if ( is_null( $meta_key ) ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpsc_ticketmeta WHERE ticket_id = %d",
				$support_candy_ticket_id
			);
		} elseif ( is_null( $support_candy_ticket_id ) ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpsc_ticketmeta WHERE meta_key = %s",
				$meta_key
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpsc_ticketmeta WHERE ticket_id = %d AND meta_key = %s",
				$support_candy_ticket_id,
				$meta_key
			);
		}

		/**
		 * The list of meta-data.
		 *
		 * @var array<int, array{id:string, ticket_id:string, meta_key:string, meta_value:string}> $result_meta
		 *
		 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		 */
		$result_meta = $wpdb->get_results(
			$query,
			ARRAY_A
		);

		return $result_meta;
	}

	/**
	 * Delete a row in the `wp_wpsc_ticket` table.
	 *
	 * @param int $support_candy_ticket_id The ticket to delete.
	 */
	public function delete_ticket( int $support_candy_ticket_id ): bool {
		global $wpdb;
		$result = $wpdb->delete( "{$wpdb->prefix}wpsc_ticket", array( 'id' => $support_candy_ticket_id ), array( '%d' ) );

		if ( false === $result ) {
			$this->logger->error( "Failed to delete SupportCandy ticket {$support_candy_ticket_id}" );
		} else {
			$this->logger->debug( "Deleted SupportCandy ticket {$support_candy_ticket_id}" );
		}

		return 1 === $result;
	}

	/**
	 * Delete rows in the `wp_wpsc_ticketmeta` table.
	 * Specify both the ticket id and meta_key to delete all entries of that type for that ticket.
	 * Specify the ticket id to delete all metadata for that ticket.
	 * Specify only the meta_key to delete all entries for that key.
	 *
	 * @param ?int    $support_candy_ticket_id The ticket id to delete the meta-data for, optional.
	 * @param ?string $meta_key The meta data key to delete, optional.
	 *
	 * @throws Exception On bad input.
	 */
	public function delete_ticket_meta( ?int $support_candy_ticket_id = null, ?string $meta_key = null ): int {
		if ( is_null( $support_candy_ticket_id ) && is_null( $meta_key ) ) {
			throw new Exception();
		}

		global $wpdb;

		if ( is_null( $meta_key ) ) {
			$result = $wpdb->delete(
				"{$wpdb->prefix}wpsc_ticketmeta",
				array( 'ticket_id' => $support_candy_ticket_id ),
				array( '%d' )
			);
		} elseif ( is_null( $support_candy_ticket_id ) ) {
			$result = $wpdb->delete(
				"{$wpdb->prefix}wpsc_ticketmeta",
				array( 'meta_key' => $meta_key ),
				array( '%s' )
			);
		} else {
			$result = $wpdb->delete(
				"{$wpdb->prefix}wpsc_ticketmeta",
				array(
					'ticket_id' => $support_candy_ticket_id,
					'meta_key'  => $meta_key,
				),
				array( '%d', '%s' )
			);
		}

		if ( false === $result ) {
			$this->logger->error( "Failed to delete meta for SupportCandy ticket {$support_candy_ticket_id}" );
		} else {
			$this->logger->debug( "Deleted {$result} rows in SupportCandy ticket meta table for ticket {$support_candy_ticket_id}" );
		}

		return false === $result ? 0 : $result;
	}

	/**
	 * Get the total number of tickets.
	 *
	 * Used by the progress bar.
	 */
	public function get_num_tickets(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT count(*) FROM %i',
				$wpdb->prefix . 'wpsc_ticket'
			)
		);
	}
}
