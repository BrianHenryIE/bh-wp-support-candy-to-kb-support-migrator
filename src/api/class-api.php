<?php
/**
 * TODO: Copy settings function
 * TODO: Move tickets function
 * TODO: Update user roles function ??
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API;

use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WC_Order;
use WP_Post;
use WP_Term;

class API {

	use LoggerAwareTrait;

	protected SupportCandy $support_candy;

	public function __construct( LoggerInterface $logger, ?SupportCandy $support_candy = null ) {
		$this->setLogger( $logger );

		$this->support_candy = $support_candy ?? new SupportCandy( $logger );
	}

	/**
	 * @param int[] $ticket_ids
	 * @param int   $count
	 * @param bool  $dry_run
	 * @return array{parameters:array{ticket_ids:array<int>,count:int,dry_run:bool},moved:array<array<mixed>>}
	 */
	public function move_tickets( array $ticket_ids = array(), $count = 10, $dry_run = true ): array {

		$result = array(
			'parameters' => get_defined_vars(),
			'moved'      => array(),
		);

		$this->logger->info( 'Requirements.' );

		if ( ! is_plugin_active( 'kb-support/kb-support.php' ) ) {
			$this->logger->error( 'Required plugin not active, run `wp plugin activate kbs-ticket`' );
		}

		if ( ! is_plugin_active( 'kbs-custom-status/kbs-custom-status.php' ) ) {
			$this->logger->error( 'Required plugin not active, run `wp plugin activate kbs-custom-status`' );
		}

		if ( is_plugin_active( 'woocommerce/woocommerce.php' ) && ! is_plugin_active( 'kbs-woocommerce/kbs-woocommerce.php' ) ) {
			$this->logger->warning( 'Required plugin not active, run `wp plugin activate kbs-woocommerce`' );
		}

		if ( ! is_plugin_active( 'bh-wp-kbs-ticket-priorities/bh-wp-kbs-ticket-priorities.php' ) ) {
			$this->logger->warning( 'Required plugin not active, run `wp plugin activate bh-wp-kbs-ticket-priorities`' );
		}

		if ( is_plugin_active( 'wpsc-email-piping/wpsc-email-piping.php' ) ) {
			$this->logger->warning( 'Support Candy Email Piping is active. Best to disable this before beginning migration. Run `wp plugin deactivate wpsc-email-piping`' );
		}

		$this->logger->warning( 'Recommended: Disable site emails during migration' );

		// Support candy uses sequential ticket numbers.
		if ( ! kbs_use_sequential_ticket_numbers() ) {
			// Setting this in the UI initiates a re-numbering process.
			$this->logger->warning( 'Run `wp shell` and then `kbs_update_option(\'enable_sequential\', true );` ' );
		}

		// Hide the KB "Submission Page" / "Log a Support Ticket" page while running.
		$submission_page_id          = kbs_get_option( 'submission_page' );
		$submission_page             = get_post( $submission_page_id );
		$submission_page_post_status = $submission_page->post_status;

		// Hide the KB "Tickets Manager" page while running.
		$tickets_manager_page_id     = kbs_get_option( 'tickets_page' );
		$tickets_manager_page        = get_post( $tickets_manager_page_id );
		$tickets_manager_page_status = $tickets_manager_page->post_status;

		$this->logger->info( $this->support_candy->get_num_tickets() . ' Support Candy tickets to migrate' );

		if ( $dry_run ) {
			$this->logger->info( 'Analysing.' );
		} else {
			$this->logger->info( 'Moving.' );

			if ( 'draft' !== $submission_page_post_status ) {
				wp_update_post(
					array(
						'ID'          => $submission_page_id,
						'post_status' => 'draft',
					)
				);
				$this->logger->info( 'Setting KB Support Submission page to draft during migration (will be reverted to ' . $submission_page_post_status . ' after).' );
			}
			if ( 'draft' !== $tickets_manager_page_status ) {
				wp_update_post(
					array(
						'ID'          => $tickets_manager_page_id,
						'post_status' => 'draft',
					)
				);
				$this->logger->info( 'Setting KB Tickets Manager page to draft during migration (will be reverted to ' . $tickets_manager_page_status . ' after).' );
			}

			// TODO: Hide WPSC's submission page while running.
			$this->logger->notice( 'Recommended: Disable Support Candy ticket submission page before migration.' );
		}

		if ( PHP_INT_MAX === $count ) {
			$count = $this->support_candy->get_num_tickets();
		}

		/**
		 * Progress bar.
		 *
		 * TODO: This forces a requirement on WP_CLI. Is there a better way?! Delegate.
		 *
		 * @see https://chrisk.io/adding-progress-bars-wp-cli-processes/
		 *
		 * @var \cli\progress\Bar $progress
		 */
		$progress = \WP_CLI\Utils\make_progress_bar( 'Tickets: ', $count );

		// TODO: KBS Sequential ticket id.
		// This will also be the KBS sequential ticket id... presuming there are no existing tickets.
		// TODO... if there ARE existing KBS tickets, those ids should be moved to AFTER the WPSC ones?
		// TODO: throw an exception.

		$moved = 0;
		// Sometimes there are WP_Posts with a ticket number, but no corresponding ticket in the WPSC database tables.
		$failed = 0;

		if ( ! empty( $ticket_ids ) ) {

			foreach ( $ticket_ids as $wpsc_ticket_id ) {

				$move_ticket_result = $this->move_ticket( $wpsc_ticket_id, $dry_run );

				$result['moved'][] = $move_ticket_result;

				++$moved;

				$progress->tick();
			}
		} else {

			do {

				$nth = $dry_run ? $moved : $failed;

				$next_wpsc_ticket = $this->support_candy->get_next_support_candy_ticket_id( $nth );

				if ( is_null( $next_wpsc_ticket ) ) {
					break;
				}

				$move_ticket_result = $this->move_ticket( $next_wpsc_ticket, $dry_run );
				$result['moved'][]  = $move_ticket_result;

				++$moved;

				if ( false === $move_ticket_result['success'] ) {
					++$failed;
				}

				$progress->tick();

			} while ( 0 !== $count && $moved < $count );
		}

		if ( ! $dry_run ) {
			// Return the KB Support and SupportCandy ticket submission pages to their status before running the command.
			// I.e. they were hidden to prevent ticket submission during the migration.
			wp_update_post(
				array(
					'ID'          => $submission_page_id,
					'post_status' => $submission_page_post_status,
				)
			);
			wp_update_post(
				array(
					'ID'          => $tickets_manager_page_id,
					'post_status' => $tickets_manager_page_status,
				)
			);
		}

		$progress->finish();

		return $result;
	}

	/**
	 * @var string[]
	 */
	protected array $exceptions_seen = array();

	/**
	 * As the migration (presumably dry run, where this is most useful) progresses, record
	 * each meta key that was not migrated, so new meta keys can be logged for attention.
	 *
	 * @var string[]
	 */
	protected array $unmapped_meta_keys = array();

	/**
	 * As the migration (presumably dry run, where this is most useful) progresses, record
	 * each meta key that was not migrated, so new meta keys can be logged for attention.
	 *
	 * @var string[]
	 */
	protected array $unmapped_wpsc_meta_keys = array();

	/**
	 * @param int $wpsc_ticket_id The ticket id to move (NOT the wp_post ID).
	 * @para bool $dry_run Toggle to execute the changes or just calculate the intended changes
	 * @return array{parameters:array{wpsc_ticket_id:int,dry_run:bool},success:bool,updates?:array}
	 * @throw Exception
	 */
	protected function move_ticket( int $wpsc_ticket_id, bool $dry_run = true ): array {

		sleep( 3 );

		$result = array(
			'parameters' => get_defined_vars(),
			'success'    => false,
		);

		$thread_posts = $this->support_candy->get_support_candy_thread_wp_posts( $wpsc_ticket_id );

		if ( empty( $thread_posts ) ) {
			$this->logger->error( "Provided Support Candy ticket id: $wpsc_ticket_id did not match any WP_Posts.", $result );

			return $result;
		}

		$thread_post_ids       = array_map(
			function ( WP_Post $post ) {
				return $post->ID;
			},
			$thread_posts
		);
		$thread_parent_post_id = $thread_post_ids[0];

		// $thread_parent_post = array_shift( $thread_posts );

		$wpsc_ticket_data = $this->support_candy->get_support_candy_tables_data( $wpsc_ticket_id );

		// Get regular post meta.
		$thread_post_meta = array();
		foreach ( $thread_post_ids as $post_id ) {
			$post_meta                    = get_post_meta( $post_id );
			$thread_post_meta[ $post_id ] = array();
			foreach ( $post_meta as $key => $value ) {
				if ( count( $value ) > 1 ) {
					throw new Exception( "Unexpectedly found multiple meta values for `get_post_meta( $post_id, $key )`" );
				}
				$thread_post_meta[ $post_id ][ $key ] = $value[0];
			}
		}
		unset( $post_id );

		// Map data from WPSC to KBS.
		try {
			$updates = $this->calculate_changes( $thread_posts, $thread_post_meta, $wpsc_ticket_data );

			$result['updates'] = $updates;

		} catch ( Exception $exception ) {

			if ( $dry_run ) {
				$message = $exception->getMessage();

				if ( $exception instanceof Solution_Exception ) {
					$message = $exception->get_solution();
				}

				// Only print the exception once.
				if ( ! in_array( $message, $this->exceptions_seen ) ) {
					$wp_post_id = count( $thread_post_ids ) === 1 ? $thread_parent_post_id : '?';
					$this->logger->warning( "{wpsc:$wpsc_ticket_id,thread_wp_post:$thread_parent_post_id,wp_post:$wp_post_id} : " . $message );
					$this->exceptions_seen[] = $message;
				}

				$result['success'] = false;
				return $result;
			} else {
				// Throw the exception so all Support Candy data is mapped before beginning to migrate a ticket.
				throw $exception;
			}
		}

		// Do nothing with these keys. They don't have a corresponding KBS entry, but we still want them.
		$ignore_metadata_keys = apply_filters( 'wpsc_to_kbs_ignore_metadata_keys', array() );

		// Print out any unmapped metadata keys that have been seen for the first time.
		$unmapped_metadata_by_post_id = $updates['unmapped_metadata_by_post_id'];

		foreach ( $unmapped_metadata_by_post_id as $post_id => $unmapped_metadata_for_post ) {
			foreach ( $unmapped_metadata_for_post as $unmapped_meta_key => $unmapped_meta_value ) {
				if ( in_array( $unmapped_meta_key, $ignore_metadata_keys, true ) ) {
					continue;
				}
				if ( in_array( $unmapped_meta_key, $this->unmapped_meta_keys, true ) ) {
					continue;
				}
				$message = "{wpsc:$wpsc_ticket_id,thread_wp_post:$thread_parent_post_id,wp_post:$post_id}: Found unmapped metadata `$unmapped_meta_key`=>`$unmapped_meta_value`";
				if ( $dry_run ) {
					$this->logger->warning( $message );
					$this->unmapped_meta_keys[] = $unmapped_meta_key;
				} else {
					throw new Exception( $message );
				}
			}
		}

		$unmapped_wpsc_metadata = $updates['unmapped_wpsc_fields']['meta'];

		foreach ( $unmapped_wpsc_metadata as $unmapped_wpsc_metadata_key => $unmapped_wpsc_metadata_value ) {
			if ( in_array( $unmapped_wpsc_metadata_key, $ignore_metadata_keys, true ) ) {
				continue;
			}
			if ( in_array( $unmapped_wpsc_metadata_key, $this->unmapped_wpsc_meta_keys, true ) ) {
				continue;
			}
			$message = "{wpsc:$wpsc_ticket_id,thread_wp_post:$thread_parent_post_id}: Found unmapped wpsc metadata `$unmapped_wpsc_metadata_key`=>`$unmapped_wpsc_metadata_value`";
			if ( $dry_run ) {
				$this->logger->warning( $message );
				$this->unmapped_wpsc_meta_keys[] = $unmapped_wpsc_metadata_key;
			} else {
				throw new Exception( $message );
			}
		}

		// Commit the changes.
		if ( false === $dry_run ) {

			// TODO: We have yet to check all taxonomies.

			// $customer_data = $updates['thread_customer_data'];
			// $kbs_customer = new \KBS_Customer( $customer_data['email'] );
			// if( empty( $kbs_customer->id)) {
			// $customer_id = $kbs_customer->create( $customer_data );
			// } else {
			// $customer_id = $kbs_customer->id;
			// }

			// Need to keep the sequential ticket number correct.
			$highest_ticket_number = intval( get_option( 'kbs_next_ticket_number' ) );

			/**
			 * Don't change the last modified time.
			 *
			 * @see https://wordpress.stackexchange.com/questions/237878/how-to-prevent-wordpress-from-updating-the-modified-time
			 *
			 * @param $new
			 * @param $old
			 * @return mixed
			 */
			$stop_modified_date_update = function ( $new_post, $old ) {
				$new_post['post_modified']     = $old['post_modified'];
				$new_post['post_modified_gmt'] = $old['post_modified_gmt'];
				return $new_post;
			};
			add_filter( 'wp_insert_post_data', $stop_modified_date_update, 10, 2 );

			// Update the posts.
			foreach ( $updates['wp_update_post'] as $wp_post_id => $args ) {

				/**
				 * Create/read/update KBS_Customer.
				 */
				if ( ! empty( $updates['customer_email_by_post_id'][ $wp_post_id ] ) ) {
					$customer_email = $updates['customer_email_by_post_id'][ $wp_post_id ];

					$kbs_customer = new \KBS_Customer( $customer_email );
					if ( empty( $kbs_customer->id ) && isset( $this->customers[ $customer_email ] ) ) {
						$customer_data                                 = $this->customers[ $customer_email ];
						$customer_id                                   = $kbs_customer->create( $customer_data );
						$args['meta_input']['_kbs_ticket_customer_id'] = $customer_id;
					} elseif ( ! empty( $kbs_customer->id ) && isset( $this->customers[ $customer_email ] ) ) {
						$customer_data = $this->customers[ $customer_email ];
						$customer_id   = $kbs_customer->id;
						$kbs_customer->update( $customer_data );
						$args['meta_input']['_kbs_ticket_customer_id'] = $customer_id;
					}
					if ( isset( $this->customers[ $customer_email ]['user_id'] ) ) {
						$args['meta_input']['_kbs_ticket_user_id']         = $this->customers[ $customer_email ]['user_id'];
						$args['meta_input']['_kbs_ticket_woo_customer_id'] = $this->customers[ $customer_email ]['user_id'];
					}

					unset( $kbs_customer, $customer_data, $this->customers[ $customer_email ], $customer_email );
				}

				$this->logger->debug( "Updating post {$wp_post_id}:\n" . wp_json_encode( $args, JSON_PRETTY_PRINT ), $args );

				$updated = wp_update_post( $args, true );
				if ( is_wp_error( $updated ) ) {
					$this->logger->error( 'Failed to update post' . $updated->get_error_message() );
					throw new \Exception( 'Failed to update post' . $updated->get_error_message() );
				}

				// Sequential ticket numbering.
				if ( isset( $args['meta_input']['_kbs_ticket_number'] ) ) {
					$ticket_number         = intval( $args['meta_input']['_kbs_ticket_number'] );
					$highest_ticket_number = max( $highest_ticket_number, $ticket_number );
				}
			}
			unset( $wp_post_id, $args, $customer_id );

			/**
			 * Notes are stored in WPSC as WP_Posts but in KBS as WP_Comments
			 *
			 * @see kbs_insert_note()
			 */
			foreach ( $updates['thread_notes_by_post_id'] as $note_post_id ) {
				$note_wp_post = get_post( $note_post_id );

				$args = array(
					'comment_post_ID'      => $thread_parent_post_id,
					'comment_content'      => $note_wp_post->post_content,
					'user_id'              => $note_wp_post->post_author,
					'comment_date'         => $note_wp_post->post_date,
					'comment_date_gmt'     => $note_wp_post->post_date_gmt,
					'comment_approved'     => 1,
					'comment_parent'       => 0,
					'comment_author'       => '',
					'comment_author_IP'    => '',
					'comment_author_url'   => '',
					'comment_author_email' => '',
					'comment_type'         => 'kbs_ticket_note',
				);

				wp_insert_comment( $args );

				wp_delete_post( $note_post_id );
			}
			unset( $note_post_id, $note_wp_post );

			/**
			 * Terms
			 */
			foreach ( $updates['new_terms_by_post_id'] as $post_id => $terms ) {
				foreach ( $terms as $taxonomy => $term_slugs_or_ids ) {
					wp_set_object_terms( $post_id, $term_slugs_or_ids, $taxonomy, true );
				}
				unset( $post_id, $terms, $taxonomy, $term_slugs_or_ids );
			}

			/**
			 * Attachments
			 *
			 * WPSC used a protected uploads directory to store the files.
			 * KBS just uses the WordPress media library.
			 *
			 * @see kbs_attach_file_to_ticket()
			 * There is another function which ultimately calls the first.
			 * @see kbs_attach_files_to_reply()
			 *
			 * Use `wp term list wpsc_attachment` to get a list of SC ticket ids that have attachments.
			 * `wp term get wpsc_attachment <term_id>` doesn't tell us much.
			 * `wp term meta list <term_id>` give us information.
			 */
			// Attachments!
			// WPSC stores attachments as WP_Terms, and adds post meta 'attachments' an array of term ids. e.g. [106].
			// get_term_meta(106);
			// metakeys: filename, is_image, save_file_name, active, time_uploaded, is_restructured.
			// neither the postmeta, term, nor term meta contain the full path to the attachment.
			// but the filepath is uploads_dir / wpsc/ yyyy / mm / filename.
			foreach ( $updates['attachments_by_post'] as $wp_post_id => $attachments ) {
				foreach ( $attachments as $attachment ) {
					$attachment_data = get_term_meta( $attachment );

					if ( false === $attachment_data ) {
						// Probably already doesn't exist.
						wp_delete_term( $attachment, 'wpsc_attachment' );
						$this->logger->error( 'Term meta not found for term id' . $attachment );
						continue;
					}

					// e.g.  "1579123789_4123540F-D777-4E35-B70D-B123DF7E60A5.png".
					$attachment_wpsc_filename = $attachment_data['save_file_name'][0];
					// e.g. "2020-01-14 15:13:09".
					$attachment_time_uploaded = $attachment_data['time_uploaded'][0];
					$yyyy_mm                  = substr( $attachment_time_uploaded, 0, 4 ) . '/' . substr( $attachment_time_uploaded, 5, 2 );

					$attachment_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpsc/' . $yyyy_mm . '/' . $attachment_wpsc_filename;

					if ( ! file_exists( $attachment_path ) ) {
						wp_delete_term( $attachment, 'wpsc_attachment' );
						$this->logger->error( 'attachments_by_post File not found at ' . $attachment_path );
						continue;
					}

					$filename = $attachment_data['filename'][0];

					$new_attachment_post_id = $this->move_and_attach_file( $filename, $attachment_path, $yyyy_mm, $attachment_time_uploaded, $wp_post_id );

					wp_delete_term( $attachment, 'wpsc_attachment' );

					$this->logger->debug(
						'Moved attachment ' . $filename,
						array(
							'filename'               => $filename,
							'deleted_term_id'        => $attachment,
							'new_attachment_post_id' => $new_attachment_post_id,
						)
					);
				}
				unset( $wp_post_id, $attachments, $attachment, $new_attachment_post_id );
			}

			foreach ( $updates['wpsc_image_attachment_by_post'] as $wp_post_id => $inline_image_term_ids ) {
				foreach ( $inline_image_term_ids as $term_id ) {
					$attachment_data = get_term_meta( $term_id );

					if ( false === $attachment_data ) {
						// Probably already doesn't exist.
						wp_delete_term( $term_id, 'wpsc_image_attachment' );
						$this->logger->error( 'Term meta not found for wpsc_image_attachment term id' . $term_id );
						continue;
					}

					// e.g.  "/full/path/to/wp-content/uploads/wpsc/2022/01/1579014789_4443540F-D777-4E35-B70D-B415DF7E60A5.png".
					$attachment_path = $attachment_data['file_path'][0];

					$attachment_time_uploaded = get_post_field( 'post_date', $wp_post_id );
					$yyyy_mm                  = substr( $attachment_time_uploaded, 0, 4 ) . '/' . substr( $attachment_time_uploaded, 5, 2 );

					preg_match( '/\/\d+_(.*)$/', $attachment_path, $output_array );
					$filename = $output_array[1];

					if ( ! file_exists( $attachment_path ) ) {
						wp_delete_term( $term_id, 'wpsc_image_attachment' );
						$this->logger->error( 'wpsc_image_attachment_by_post File not found at ' . $attachment_path );
						continue;
					}

					$new_attachment_post_id = $this->move_and_attach_file( $filename, $attachment_path, $yyyy_mm, $attachment_time_uploaded, $wp_post_id );

					wp_delete_term( $term_id, 'wpsc_image_attachment' );

					$new_url = get_attachment_link( $new_attachment_post_id );

					$post_content     = get_post_field( 'post_content', $wp_post_id );
					$new_post_content = preg_replace( '/src=".*?wpsc_img_attachment=' . $term_id . '"/', 'src="' . $new_url . '"', $post_content );

					$updated = wp_update_post(
						array(
							'ID'           => $wp_post_id,
							'post_content' => $new_post_content,
						),
						true
					);

					if ( is_wp_error( $updated ) ) {
						$this->logger->error( 'Failed to update post' );
						throw new \Exception();
					}

					$this->logger->debug(
						'Moved inline image ' . $filename,
						array(
							'filename'               => $filename,
							'deleted_term_id'        => $term_id,
							'new_attachment_post_id' => $new_attachment_post_id,
						)
					);
					unset( $attachments, $attachment, $new_attachment_post_id );
				}
				unset( $wp_post_id );
			}

			// Delete old meta data.
			foreach ( $updates['delete_meta_keys_by_post_id'] as $post_id => $meta_keys ) {
				foreach ( $meta_keys as $meta_key ) {
					delete_post_meta( $post_id, $meta_key );
				}
			}

			// delete wpsc_ticket.
			$this->support_candy->delete_ticket( $wpsc_ticket_id );

			// delete wpsc_ticket_meta.
			$this->support_candy->delete_ticket_meta( $wpsc_ticket_id );

			update_option( 'kbs_last_ticket_number', $highest_ticket_number );
			++$highest_ticket_number;
			update_option( 'kbs_next_ticket_number', $highest_ticket_number );
		}

		$result['success'] = true;

		return $result;
	}
	// TODO: So far, we've only looked at the two Support Candy database tables, wp_posts and wp_postmeta.
	// TODO: Look at `wp taxonomy list` for all wpsc taxonomies.

	/**
	 * Move an existing file to the media library and delete the original
	 *
	 * @return int The new attachment id.
	 */
	protected function move_and_attach_file( string $filename, string $attachment_path, string $yyyy_mm, $attachment_time_uploaded, int $post_id ) {

		// Look at the extension.
		$mime     = wp_check_filetype( $attachment_path );
		$mimetype = $mime['type'];
		if ( ! $mimetype && function_exists( 'mime_content_type' ) ) {
			// Use ext-fileinfo to look inside the file.
			$mimetype = mime_content_type( $attachment_path );
		}

		$file = array(
			'name'     => $filename, // The original file upload name (before wpsc changed it).
			'type'     => $mimetype,
			'tmp_name' => $attachment_path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $attachment_path ),
		);

		$action = array( 'action' => 'wp_handle_wpsc_kbs_migration' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing
		$_POST = $_POST + $action;

		$uploaded = wp_handle_upload( $file, $action, $yyyy_mm );

		$set_attachment_date = function ( $new_attachment, $old ) use ( $attachment_time_uploaded ) {
			$new_attachment['post_date']         = $attachment_time_uploaded;
			$new_attachment['post_date_gmt']     = $attachment_time_uploaded;
			$new_attachment['post_modified']     = $attachment_time_uploaded;
			$new_attachment['post_modified_gmt'] = $attachment_time_uploaded;
			return $new_attachment;
		};
		add_filter( 'wp_insert_attachment_data', $set_attachment_date, 10, 2 );

		$args        = array();
		$post        = get_post( $post_id );
		$post_author = $post->post_author;
		if ( get_current_user() !== $post_author && 0 !== $post_author ) {
			$args['post_author'] = $post_author;
		}
		unset( $post, $post_author );
		if ( isset( $uploaded['file'] ) ) {
			$new_attachment_post_id = wp_insert_attachment( $args, $uploaded['file'], $post_id );
		} else {
			$this->logger->error( 'wp_handle_upload $uploaded[\'file\'] is not set', array( 'uploaded' => $uploaded ) );
			return 0;
		}

		remove_filter( 'wp_insert_attachment_data', $set_attachment_date );

		// Delete the original file.
		if ( file_exists( $attachment_path ) ) {
			wp_delete_file( $attachment_path );
		}

		return $new_attachment_post_id;
	}

	protected function get_wpsc_ticket_taxonomies( int $wpsc_ticket_id ): array {

		return array();
	}

	/**
	 * The big function... loop over the provided posts and determine what changes should be made.
	 *
	 * @see SupportCandy::get_support_candy_tables_data()
	 *
	 * @param WP_Post[]                                                                                                                                                                                                                                                                                                                                            $thread_posts
	 * @param array<int, array<string, mixed>>                                                                                                                                                                                                                                                                                                                     $thread_post_meta
	 * @param array{id:string, ticket_status:string, customer_name:string, customer_email:string, ticket_subject:string, user_type:string, ticket_category:string, ticket_priority:string, date_created:string, date_updated:string, ip_address:string, agent_created:string, ticket_auth_code:string, historyId:string, active:string, meta:array<string,string>} $wpsc_ticket_data
	 *
	 * The unmapped_wpsc_fields is the $wpsc_ticket_data input with each accounted for field removed.
	 *
	 * @return array{
	 *      wpsc_ticket_id: int,
	 *      thread_customer_email: string,
	 *      customer_email_by_post_id: array<int,string>,
	 *      wp_update_post: array<int,array<string,mixed>>,
	 *      thread_notes_by_post_id: array<int, mixed>,
	 *      new_terms_by_post_id: array<int, mixed>,
	 *      attachments_by_post: array<int, mixed>,
	 *      wpsc_image_attachment_by_post: array<int, int>,
	 *      delete_meta_keys_by_post_id: array<int,mixed>,
	 *      unmapped_wpsc_fields: array<string, mixed>,
	 *      unmapped_metadata_by_post_id: array<int,mixed>
	 *          } Array of updates.
	 * @throws Exception
	 */
	protected function calculate_changes( array $thread_posts, array $thread_post_meta, array $wpsc_ticket_data ): array {

		$wpsc_ticket_id = $wpsc_ticket_data['id'];

		/**
		 * An array, indexed by post_id, of $args arrays to be passed to wp_update_post().
		 *
		 * @var array<int, array<string, mixed>> $wp_update_post array<post_id, $args>.
		 */
		$wp_update_post = array();

		/**
		 * Taxonomy terms to apply to the posts.
		 *
		 * @var array<int, array<string, int>> $new_terms_by_post_id array<post_id, array<taxonomy, term_id>>.
		 */
		$new_terms_by_post_id = array();

		/**
		 * Support Candy terms to delete from migrated posts.
		 */
		$delete_post_terms = array();

		/**
		 * Email attachments
		 *
		 * @var int[] $attachments_by_post
		 */
		$attachments_by_post = array();

		/**
		 * Inline images in the emails
		 *
		 * Taxonomy type: `wpsc_image_attachment`.
		 *
		 * @var array<int,int> $wpsc_image_attachment_by_post Post id: wpsc_image_attachment term id.
		 */
		$wpsc_image_attachment_by_post = array();

		/**
		 * A list of meta keys to delete on each post which have been migrated or are not needed.
		 */
		$delete_meta_keys_by_post_id = array();

		/**
		 * A list of meta data that was not accounted for in this function's mapping.
		 * Used for reporting and to halt migrations.
		 *
		 * @var string[]
		 */
		$unmapped_meta_data_by_post_id = array();

		$thread_customer_data = array();

		/**
		 * Notes are stored in WPSC as WP_Posts and in KBS as WP_Comments.
		 *
		 * @var int[] $thread_notes_by_post_id
		 */
		$thread_notes_by_post_id = array();

		// TODO Can more than one customer be part of a thread?
		$customer_email_by_post_id = array();

		// Prime the arrays as arrays of arrays indexed by post_id.
		foreach ( $thread_posts as $post ) {
			$new_terms_by_post_id[ $post->ID ]          = array();
			$delete_post_terms[ $post->ID ]             = array();
			$attachments_by_post[ $post->ID ]           = array();
			$wpsc_image_attachment_by_post[ $post->ID ] = array();
			$delete_meta_keys_by_post_id[ $post->ID ]   = array();
			$unmapped_meta_data_by_post_id[ $post->ID ] = array();
			$customer_email_by_post_id[ $post->ID ]     = array();

			// WPSC data that will not be migrated.
			// TODO: does KBS keep the parent ticket id (not post id) on each child... seems like a reasonable idea to do so.
			$delete_meta_keys_by_post_id[ $post->ID ][] = 'ticket_id';
			unset( $thread_post_meta[ $post->ID ]['ticket_id'] );
		}
		unset( $post );

		$thread_parent_post_id = $thread_posts[0]->ID;

		/**
		 * Array of metadata keys that will deleted, i.e. will not be migrated or preserved.
		 *
		 * See `wpsc_to_kbs_ignore_metadata_keys` filter for preserving keys.
		 *
		 * All metadata keys must be accounted for or an exception will be thrown and the migration will halt.
		 * Discover metadata keys by running a dry run over all tickets.
		 *
		 * TODO: Move out of this function!
		 *
		 * TODO: Show defaults in this docblock.
		 *
		 * @var string[] $delete_metadata_keys
		 */
		$delete_metadata_keys = apply_filters( 'wpsc_to_kbs_delete_metadata_keys', array() );

		foreach ( $delete_metadata_keys as $delete_metadata_key ) {
			if ( isset( $wpsc_ticket_data['meta'][ $delete_metadata_key ] ) ) {
				unset( $wpsc_ticket_data['meta'][ $delete_metadata_key ] );
			}
		}
		unset( $delete_metadata_key );

		foreach ( $thread_posts as $wp_post ) {

			$post_id = $wp_post->ID;

			foreach ( $delete_metadata_keys as $delete_metadata_key ) {
				if ( isset( $thread_post_meta[ $post_id ][ $delete_metadata_key ] ) ) {
					unset( $thread_post_meta[ $post_id ][ $delete_metadata_key ] );
					$delete_meta_keys_by_post_id[ $post_id ][] = $delete_metadata_key;
				}
			}
			unset( $delete_metadata_key );

			// The $args to pass to `wp_update_post`.
			$update = array( 'ID' => $post_id );
			// Later we'll add this as "meta_input" key.
			$new_meta = array();

			// Set post_parent on all child posts.
			if ( $post_id !== $thread_parent_post_id ) {
				$update['post_parent'] = $thread_parent_post_id;
			}

			/**
			 * The post_type.
			 */
			switch ( $thread_post_meta[ $post_id ]['thread_type'] ) {
				case 'report':
					// Presuming "report" is always the thread parent.
					$update['post_type'] = 'kbs_ticket';
					break;
				case 'reply':
					$update['post_type'] = 'kbs_ticket_reply';
					break;
				case 'log':
					$update['post_type'] = 'kbs_log';
					break;
				case 'note':
					/**
					 * KBS Notes are stored as WP_Comments.
					 *
					 * Later we will fetch the posts, insert the comments and delete the original posts.
					 */
					$thread_notes_by_post_id[] = $post_id;
					continue 2;
				default:
					throw new Solution_Exception(
						"{wpsc:{$wpsc_ticket_id},wp_post:$post_id}: Unexpected thread_type: {$thread_post_meta[ $post_id ]['thread_type']}",
						'You will need to edit class-api to account for the new thread_type.'
					);
			}
			unset( $thread_post_meta[ $post_id ]['thread_type'] );
			$delete_meta_keys_by_post_id[ $post_id ][] = 'thread_type';

			/**
			 * The `post_title`.
			 *
			 * Support Candy does not use the WP_Post->post_title.
			 */
			if ( $post_id === $thread_parent_post_id ) {
				$update['post_title'] = $wpsc_ticket_data['ticket_subject'];
			} else {
				$update['post_title'] = 'Re: ' . $wpsc_ticket_data['ticket_subject'];
			}

			// checking isset here because replies won't have this?
			if ( ! empty( $thread_post_meta[ $post_id ]['customer_email'] ) && ! empty( $thread_post_meta[ $post_id ]['customer_name'] ) ) {

				$ticket_email         = strtolower( $thread_post_meta[ $post_id ]['customer_email'] );
				$ticket_customer_name = $thread_post_meta[ $post_id ]['customer_name'];

				$new_meta['_kbs_ticket_user_email'] = $ticket_email;

				$this->get_kbs_customer_object_data_array( $ticket_email, $ticket_customer_name );
				$customer_email_by_post_id[ $wp_post->ID ] = $ticket_email;

				unset( $ticket_email );
				$delete_meta_keys_by_post_id[ $wp_post->ID ][] = 'customer_email';
				$delete_meta_keys_by_post_id[ $wp_post->ID ][] = 'customer_name';
				unset( $thread_post_meta[ $post_id ]['customer_email'], $thread_post_meta[ $post_id ]['customer_name'] );
			}

			/**
			 * The `post_author`.
			 *
			 * WPSC should have the post author already set, but sometimes the user account was created later.
			 */
			if ( 0 === intval( $wp_post->post_author ) && isset( $thread_post_meta[ $post_id ]['customer_email'] ) ) {
				$wp_user = get_user_by( 'email', $thread_post_meta[ $post_id ]['customer_email'] );
				if ( $wp_user instanceof \WP_User ) {
					$update['post_author'] = $wp_user->ID;
				}
				unset( $wp_user );
			}

			// post_date
			// Post date is later preserved when updating.

			/**
			 * `post_status` is 'publish' on all except the thread parent.
			 */
			$update['post_status'] = 'publish';

			/**
			 * Ticket source
			 *
			 * Map WPSC "reply_source" meta to KBS "ticket_source" term.
			 * 'kbs-website' | 'kbs-email | 'kbs-rest' | 'kbs-telephone' | 'kbs-other'.
			 */
			if ( ! isset( $new_terms_by_post_id[ $post_id ]['ticket_source'] ) ) {
				$new_terms_by_post_id[ $post_id ]['ticket_source'] = array();
			}
			// Status changes have no reply_source.
			if ( isset( $thread_post_meta[ $post_id ]['reply_source'] ) ) {
				switch ( $thread_post_meta[ $post_id ]['reply_source'] ) {
					case 'browser':
						$new_terms_by_post_id[ $post_id ]['ticket_source'][] = 'kbs-website';
						break;
					case 'imap':
						$new_terms_by_post_id[ $post_id ]['ticket_source'][] = 'kbs-email';
						break;
					case 'Not Found':
						// Occurs when a ticket has been anonymized.
						break;
					default:
						// There is no reply source on status changes (e.g. open -> awaiting customer reply).
						if ( empty( $thread_post_meta[ $post_id ]['reply_source'] ) ) {
							break;
						}
						throw new Exception( "{wpsc:{$thread_post_meta[ $post_id ]['ticket_id']},thread_wp_post:$thread_parent_post_id,wp_post:$post_id} Unexpected reply_source: {$thread_post_meta[ $post_id ]['reply_source']}." );
				}
				unset( $thread_post_meta[ $post_id ]['reply_source'] );
			}
			$delete_meta_keys_by_post_id[ $wp_post->ID ][] = 'reply_source';

			/**
			 * Map WPSC meta "ip_address" to KBS meta "_kbs_ticket_user_ip".
			 */
			if ( isset( $thread_post_meta[ $post_id ]['ip_address'] ) ) {
				if ( ! empty( $thread_post_meta[ $post_id ]['ip_address'] ) ) {
					$new_meta['_kbs_ticket_user_ip'] = $thread_post_meta[ $post_id ]['ip_address'];
				}
				unset( $thread_post_meta[ $post_id ]['ip_address'] );
				$delete_meta_keys_by_post_id[ $wp_post->ID ][] = 'ip_address';
			}

			/**
			 * Attachments.
			 *
			 * Each message in a thread can contain attachments. Ids of WP_Terms are stored in an array
			 * in postmeta. Later we will fetch that term (really the term's meta), move the attachment
			 * and associate it as KBS expects.
			 *
			 * @see WPSC_Actions::file_download()
			 */
			if ( ! empty( $thread_post_meta[ $post_id ]['attachments'] ) ) {
				$attachments_deserialize = unserialize( $thread_post_meta[ $post_id ]['attachments'] );
				if ( ! empty( $attachments_deserialize ) ) {
					$attachments_by_post[ $post_id ] = $attachments_deserialize;
				}
			}
			unset( $thread_post_meta[ $post_id ]['attachments'] );
			$delete_meta_keys_by_post_id[ $wp_post->ID ][] = 'attachments';

			/**
			 * Inline images.
			 *
			 * `wp term list wpsc_image_attachment`
			 * `wp search-replace ?wpsc_img_attachment= ?kbs wp_posts --dry-run --log`
			 */
			if ( 1 === preg_match_all( '/\?wpsc_img_attachment=(\d+)/', get_post_field( 'post_content', $wp_post->ID ), $output_array ) ) {
				// Term ids.
				// `wp term get wpsc_image_attachment <term_id>`.
				$wpsc_image_attachment_by_post[ $wp_post->ID ] = $output_array[1];
			}

			$update['meta_input']                      = $new_meta;
			$wp_update_post[ $post_id ]                = $update;
			$unmapped_meta_data_by_post_id[ $post_id ] = $thread_post_meta[ $post_id ];
		}

		unset( $wp_post, $post_id, $new_meta, $update, $wp_post_meta ); // Using a lot of XDebug... this is to keep it clean.
		// Unsetting this outside the loop because it is needed multiple times.
		unset( $wpsc_ticket_data['ticket_subject'] );

		$thread_parent_update = $wp_update_post[ $thread_parent_post_id ];

		// Save the old post id on the parent post.
		// TODO: Enable sequential ticketing.
		// TODO: After everything is imported: `update_option( 'kbs_last_ticket_number', $number );`.

		$thread_parent_update['meta_input']['_kbs_ticket_number'] = $wpsc_ticket_data['id'];
		$thread_parent_update['meta_input']['wpsc_ticket_number'] = $wpsc_ticket_data['id'];
		unset( $wpsc_ticket_data['id'] );

		// TODO: `agent_id`
		// how does wpsc handle it?
		// whoever replied to it..?

		// post_password
		// TODO: This should both create a new kbs_ticket_key and preserve the existing Support Candy one with a filter to enable it continue working.
		// Both Support Candy and KBS Support have "access" passwords so non-wp_user users can access their tickets.
		$thread_parent_update['meta_input']['wpsc_ticket_auth_code'] = $wpsc_ticket_data['ticket_auth_code'];
		/**
		 * @see \KBS_Ticket::insert_ticket()
		 */
		// $thread_parent_update['meta_input']['_kbs_ticket_key'] = $wpsc_ticket_data['ticket_auth_code'];
		unset( $wpsc_ticket_data['ticket_auth_code'] );

		// IP address.
		$thread_parent_update['meta_input']['_kbs_ticket_user_ip'] = $wpsc_ticket_data['ip_address'];
		unset( $wpsc_ticket_data['ip_address'] );

		// e.g. 46.
		$wpsc_ticket_status = $wpsc_ticket_data['ticket_status'];
		/** @var WP_Term $status_term */
		$status_term = get_term( $wpsc_ticket_status );
		if ( 'wpsc_statuses' !== $status_term->taxonomy ) {
			throw new Solution_Exception(
				"{wpsc:$wpsc_ticket_id,wp_post:$thread_parent_post_id}: Error getting ticket status.",
				"`wp term get {$wpsc_ticket_status} wpsc_statuses`"
			);
		}

		$kb_status = substr( $status_term->slug, 0, 20 );

		/** @var array<string,string> $existing_statuses Status name (slug), status label (display). */
		$existing_statuses = kbs_get_ticket_statuses();
		// NB Post statuses are limited to 20 characters long.
		if ( ! isset( $existing_statuses[ substr( $status_term->slug, 0, 20 ) ] ) ) {
			throw new Solution_Exception(
				"{wpsc:$wpsc_ticket_id,wp_post:$thread_parent_post_id}: WPSC ticket status: {$status_term->slug} not present in KB Support.",
				"`wp wpsc_kbs_migrator add_custom_ticket_status \"{$status_term->name}\" {open|closed|...}`"
			);
		}
		$thread_parent_update['post_status'] = $kb_status;

		unset( $wpsc_ticket_status, $status_term, $kb_status, $existing_statuses );
		unset( $wpsc_ticket_data['ticket_status'] );

		// Not all tickets have the priority set.
		if ( isset( $wpsc_ticket_data['ticket_priority'] ) ) {
			// e.g. 42.
			$wpsc_ticket_priority = $wpsc_ticket_data['ticket_priority'];
			$priority_term        = get_term( $wpsc_ticket_priority );
			// e.g. "medium".
			$priority_term_slug               = $priority_term->slug;
			$kbs_priorities                   = array();
			$registered_kbs_ticket_priorities = get_terms(
				array(
					'taxonomy'   => 'ticket_priority',
					'hide_empty' => false,
				)
			);
			foreach ( $registered_kbs_ticket_priorities as $kbs_priority_term ) {
				$kbs_priorities[ $kbs_priority_term->slug ] = $kbs_priority_term->term_id;
			}

			if ( isset( $kbs_priorities[ $priority_term_slug ] ) ) {
				if ( ! isset( $new_terms_by_post_id[ $thread_parent_post_id ]['ticket_priority'] ) ) {
					$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_priority'] = array();
				}
				$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_priority'][] = $kbs_priorities[ $priority_term_slug ];
				unset( $wpsc_ticket_data['ticket_priority'] );
			} else {
				throw new Exception( "{wpsc:$wpsc_ticket_id,wp_post:$thread_parent_post_id}: Could not map ticket priority." );
			}
			unset( $wpsc_ticket_priority, $priority_term, $priority_term_slug, $kbs_priorities, $registered_kbs_ticket_priorities, $kbs_priority_term );
		}

		/**
		 * Ticket category.
		 *
		 * Stored in WPSC table in `ticket_category` column as the WordPress taxonomy term id. e.g. int: "34".
		 * The WPSC "ticket category" WordPress taxonomy slug is "wpsc_categories".
		 * WPSC term slug "general" is the default.
		 *
		 * KB Support "ticket category" WordPress taxonomy slug is "ticket_category".
		 */
		$wpsc_ticket_category_term_id             = $wpsc_ticket_data['ticket_category'];
		$wpsc_ticket_category_term                = get_term( $wpsc_ticket_category_term_id );
		$registered_kbs_ticket_category_terms     = get_terms(
			array(
				'taxonomy'   => 'ticket_category',
				'hide_empty' => false,
			)
		);
		$registered_kbs_ticket_category_terms_map = array();
		foreach ( $registered_kbs_ticket_category_terms as $registered_kbs_ticket_category_term ) {
			$registered_kbs_ticket_category_terms_map[ $registered_kbs_ticket_category_term->slug ] = $registered_kbs_ticket_category_term->term_id;
		}
		if ( isset( $registered_kbs_ticket_category_terms_map[ $wpsc_ticket_category_term->slug ] ) ) {
			if ( ! isset( $new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'] ) ) {
				$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'] = array();
			}
			$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'][] = $registered_kbs_ticket_category_terms_map[ $wpsc_ticket_category_term->slug ];
		} elseif ( 'general' !== $wpsc_ticket_category_term->slug ) {
			throw new Solution_Exception(
				"No mapping from SupportCandy category: `{$wpsc_ticket_category_term->slug}` to KBS category.",
				"`wp term create ticket_category \"{$wpsc_ticket_category_term->name}\" --slug={$wpsc_ticket_category_term->slug}`"
			);
		}
		unset( $wpsc_ticket_category_term_id, $wpsc_ticket_category_term, $registered_kbs_ticket_category_terms, $registered_kbs_ticket_category_term, $registered_kbs_ticket_category_terms_map );
		unset( $wpsc_ticket_data['ticket_category'] );

		/**
		 * TODO:
		 * WooCommerce product terms.
		 *
		 * $wpsc_ticket_data['meta']['woo-product']
		 *
		 * Added to thread or post?! (presumably thread).
		 *
		 * @see kbs_woo_get_term()
		 */
		if ( isset( $wpsc_ticket_data['meta']['woo-product'] ) ) {
			if ( function_exists( 'kbs_woo_get_term' ) ) {
				foreach ( $wpsc_ticket_data['meta']['woo-product'] as $product_id ) {
					$kbs_woo_product_category_term_id = kbs_woo_get_term( $product_id, 'id' );
					if ( ! is_int( $kbs_woo_product_category_term_id ) ) {
						continue;
					}
					if ( ! isset( $new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'] ) ) {
						$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'] = array();
					}
					$new_terms_by_post_id[ $thread_parent_post_id ]['ticket_category'][] = $kbs_woo_product_category_term_id;
				}

				unset( $wpsc_ticket_data['meta']['woo-product'] );
			}
		}

		/**
		 * SupportCandy 'user_type': 'user' | 'guest'.
		 *
		 * The post_author will be 0 for guest and the WP_User->ID for registered 'user's.
		 */
		unset( $wpsc_ticket_data['user_type'] );

		/**
		 * SupportCandy 'historyId': int.
		 * I think this is the wp_posts:ID of the last post in the thread.
		 * Does not seem to have a corresponding KBS value.
		 */
		unset( $wpsc_ticket_data['historyId'] );

		// TODO: Move the code above that queries for orders for the phone number into its own function and call it here too.
		// TODO: Have this return the full customer array and not just the email.
		$thread_customer_email = $wpsc_ticket_data['customer_email'];
		unset( $wpsc_ticket_data['customer_email'] );
		unset( $wpsc_ticket_data['customer_name'] );

		/**
		 * This is the thread parent created date. There is nothing to map it to and no data is lost by removing it.
		 */
		unset( $wpsc_ticket_data['date_created'] );

		/**
		 * This is the final thread post modified date.
		 * We will calculate it based on the thread posts and notes (comments).
		 */
		unset( $wpsc_ticket_data['date_updated'] );
		$last_update_post                          = $thread_posts[ array_key_last( $thread_posts ) ];
		$thread_parent_update['post_modified']     = $last_update_post->post_modified;
		$thread_parent_update['post_modified_gmt'] = $last_update_post->post_modified_gmt;

		// TODO: Loop through $wp_update_post looking for the last post_type = kbs_log and use that post's time for this.
		// `$wp_update_post[$thread_parent_update]['meta_input']['_kbs_ticket_last_status_change'] = $unix_updated_time;`.

		$wp_update_post[ $thread_parent_post_id ] = $thread_parent_update;
		unset( $thread_parent_update );

		// Mark empty meta values to be deleted.
		foreach ( $unmapped_meta_data_by_post_id as $post_id => $unmapped_meta_data ) {
			foreach ( $unmapped_meta_data as $key => $value ) {
				if ( ( '0' !== $value && empty( $value ) )
					|| 'null' === $value
					|| 'a:0:{}' === $value
					|| '[]' === $value ) {
					unset( $unmapped_meta_data_by_post_id[ $post_id ][ $key ] );
					$delete_meta_keys_by_post_id[ $post_id ][] = $key;
				}
			}
		}
		unset( $post_id, $unmapped_meta_data, $key, $value );

		$result = array(
			'wpsc_ticket_id'                => $wpsc_ticket_id,
			'thread_customer_email'         => $thread_customer_email,
			'customer_email_by_post_id'     => $customer_email_by_post_id,
			'wp_update_post'                => $wp_update_post,
			'thread_notes_by_post_id'       => $thread_notes_by_post_id,
			'new_terms_by_post_id'          => $new_terms_by_post_id,
			'attachments_by_post'           => $attachments_by_post,
			'wpsc_image_attachment_by_post' => $wpsc_image_attachment_by_post,
			'delete_meta_keys_by_post_id'   => $delete_meta_keys_by_post_id,
			'unmapped_wpsc_fields'          => $wpsc_ticket_data,
			'unmapped_metadata_by_post_id'  => $unmapped_meta_data_by_post_id,
		);

		unset(
			$thread_customer_email,
			$customer_email_by_post_id,
			$wp_update_post,
			$thread_notes_by_post_id,
			$new_terms_by_post_id,
			// $delete_post_terms,
			$attachments_by_post,
			$wpsc_image_attachment_by_post,
			$delete_meta_keys_by_post_id,
			$wpsc_ticket_data,
			$unmapped_meta_data_by_post_id
		);

		return $result;
	}

	/**
	 * Get all meta-data for a ticket, get all meta-data by key, or get the meta-data by key for a ticket.
	 *
	 * @param ?int    $wpsc_ticket_id Optional ticket it.
	 * @param ?string $meta_key Optional meta-key.
	 *
	 * @return array<int, array{id:string, ticket_id:string, meta_key:string, meta_value:string}>
	 * @throws Exception
	 */
	public function get_support_candy_metadata( ?int $wpsc_ticket_id = null, ?string $meta_key = null ): array {
		return $this->support_candy->get_support_candy_metadata_table_data( $wpsc_ticket_id, $meta_key );
	}

	/**
	 * When another plugin has added metadata to WPSC tickets, it's easiest to just delete it all
	 * from all tickets by the metakey name.
	 *
	 * @param ?int    $support_candy_ticket_id
	 * @param ?string $meta_key
	 * @return int
	 */
	public function delete_metadata_on_wpsc_tickets( ?int $support_candy_ticket_id = null, ?string $meta_key = null ): int {

		return $this->support_candy->delete_ticket_meta( $support_candy_ticket_id, $meta_key );
	}

	/**
	 * @var array<string, array{name:string,email:string,id?:int,user_id?:int,primary_phone?:string}> array of customers indexed by email.
	 */
	protected array $customers = array();

	/**
	 * Update the self::$customers array with data for the ticket email address.
	 *
	 * @see self::$customers
	 *
	 * @param string $ticket_email
	 * @param string $ticket_customer_name
	 *
	 * @return void
	 */
	protected function get_kbs_customer_object_data_array( string $ticket_email, string $ticket_customer_name ) {

		$ticket_email = strtolower( $ticket_email );
		if ( ! isset( $this->customer[ $ticket_email ] ) ) {
			$this->customers[ $ticket_email ] = array(
				'name'  => $ticket_customer_name,
				'email' => $ticket_email,
			);
		} else {
			// Only run this function once per customer email.
			return;
		}

		$kbs_customer = new \KBS_Customer( $ticket_email );

		if ( ! empty( $kbs_customer->id ) ) {

			$this->customers[ $ticket_email ]['id'] = $kbs_customer->id;

		}

		if ( empty( $kbs_customer->user_id ) && ! isset( $this->customers[ $ticket_email ]['user_id'] ) ) {

			$customer_wp_user = get_user_by( 'email', $ticket_email );

			if ( $customer_wp_user ) {
				$this->customers[ $ticket_email ]['user_id'] = $customer_wp_user->ID;
			}
		}

		if ( empty( $kbs_customer->primary_phone ) && ! isset( $this->customers[ $ticket_email ]['primary_phone'] ) ) {

			if ( function_exists( 'wc_get_orders' ) ) {
				/**
				 * Search db for the customer's most recent order to get their phone number.
				 *
				 * Thankfully this is cached by WooCommerce.
				 *
				 * @var WC_Order[] $customer_orders
				 */
				$customer_orders = wc_get_orders(
					array(
						'billing_email' => $ticket_email,
						'limit'         => 1,
						'orderby'       => 'ID',
						'order'         => 'DESC',
					)
				);
				if ( count( $customer_orders ) > 0 ) {
					$order = $customer_orders[0];
					$this->customers[ $ticket_email ]['primary_phone'] = $order->get_billing_phone();
				}
			}
		}
	}

	/**
	 * Register a new ticket custom status with the KBS Custom Status plugin.
	 *
	 * @see KBS_Custom_Status::get_custom_status()
	 *
	 * @param string $title The status name.
	 * @param string $status_on_delete The status tickets should revert to should the custom status be removed.
	 *
	 * @return array{params:array{title:string,status_on_delete:string},result:string,message:string,title?:string,status_on_delete?:string,post_id?:int}
	 */
	public function register_custom_status( string $title, string $status_on_delete = 'open' ): array {

		$result = array(
			'params' => array(
				'title'            => $title,
				'status_on_delete' => $status_on_delete,
			),
		);

		if ( ! class_exists( \KBS_Custom_Status::class ) ) {
			// We can't create a post of a post type that does not exist.
			$this->logger->warning( 'KBS Custom Status plugin required and not available.' );
			$result['result']  = 'error';
			$result['message'] = 'KBS Custom Status plugin required and not available.';
			return $result;
		}

		/** @var \KBS_Custom_Status $kbs_custom_status */
		$kbs_custom_status        = \KBS_Custom_Status::instance();
		$existing_custom_statuses = $kbs_custom_status->get_custom_status( true );

		// If status exists, return.
		if ( in_array(
			strtolower( $title ),
			array_map( 'strtolower', array_column( $existing_custom_statuses, 'post_title' ) ),
			true
		) ) {
			$this->logger->notice( 'Status ' . $title . ' already registered.' );
			$result['result']  = 'notice';
			$result['message'] = 'Status ' . $title . ' already registered.';
			return $result;
		}

		// Check $status_on_delete exists.
		$found = false;
		/** @var array<string,string> $existing_statuses Status name (slug/identifier), label. */
		$existing_statuses = kbs_get_ticket_statuses();
		foreach ( $existing_statuses as $slug => $existing_status ) {
			if ( sanitize_title( $status_on_delete ) === $slug ) {
				$status_on_delete = $existing_status;
				$found            = true;
				break;
			}
		}
		if ( false === $found ) {
			$status_on_delete = 'open';
			$this->logger->warning( 'Expected status on delete: ' . $status_on_delete . ' not found. `Open` will be used instead. Use `wp post meta <post_id> _kbs_custom_status_replacement_status <correct_status_title>` to update.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'kbs_custom_status',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_kbs_custom_status_plural_label' => $title,
					'_kbs_custom_status_replacement_status' => $status_on_delete,
				),
			)
		);

		delete_transient( '_kbs_custom_status_registered' );

		$this->logger->info( 'New custom status: `' . $title . '` registered. Post id:' . $post_id );

		$result['result']           = 'success';
		$result['message']          = 'New custom status: `' . $title . '` registered. Post id:' . $post_id;
		$result['title']            = $title;
		$result['status_on_delete'] = $status_on_delete;
		$result['post_id']          = $post_id;
		return $result;
	}
}
