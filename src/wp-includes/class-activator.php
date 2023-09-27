<?php
/**
 * Fired during plugin activation
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\WP_Includes;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 */
class Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 */
	public static function activate(): void {

		// TODO: Do this via the KBS custom status plugin instead.

		// TODO: Fetch the various WPSC taxonomies and save them in wp_options.
		$wpsc_statuses = get_terms(
			array(
				'taxonomy'   => 'wpsc_statuses',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $wpsc_statuses ) ) {
			return;
		}
		$save_statuses = array();
		foreach ( $wpsc_statuses as $status ) {
			// Skip Open and Closed since they're already part of KBS.
			if ( in_array( $status->slug, array( 'open', 'closed' ), true ) ) {
				continue;
			}
			$save_statuses[ $status->term_taxonomy_id ] = array(
				'slug' => $status->slug,
				'name' => $status->name,
			);
		}

		// TODO: `update_option()`.
	}
}
