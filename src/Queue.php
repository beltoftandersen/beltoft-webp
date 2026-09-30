<?php
namespace BeltoftWebp;

use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Background conversion of a newly uploaded (or regenerated) attachment.
 *
 * Encoding AVIF and WebP for the original and every size can take long enough to hit a
 * request timeout, so outside WP-CLI the upload hook only marks the attachment pending and
 * queues it here - Action Scheduler when available (WooCommerce ships it), WP-Cron
 * otherwise. Until the job runs, nginx serves the originals, as for any image without
 * siblings.
 *
 * Contract with beltoft-media-offload 1.6.0+, which may delete local files right after
 * offloading them:
 * - The pending mark is set inside the priority-10 metadata filter, before media-offload's
 *   priority-20 one reads it through the beltoft_webp_conversion_pending filter, and stays
 *   until the job ends - including while it is queued but not started.
 * - When the job ends, successful or not, the mark is cleared and then
 *   beltoft_webp_attachment_conversion_finished fires, so media-offload uploads the new
 *   siblings and completes its postponed local delete.
 * - Media-offload up to 1.5.0 deletes local files at priority 20 without asking, which
 *   would leave the job nothing to convert, so with that installed conversion stays
 *   synchronous (see is_available()).
 */
class Queue {

	const HOOK         = 'bwebp_convert_attachment';
	const GROUP        = 'beltoft-webp';
	const PENDING_META = '_bwebp_pending';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'beltoft_webp_conversion_pending', array( __CLASS__, 'filter_pending' ), 10, 2 );
	}

	/**
	 * Whether upload-time conversion should be queued rather than run inline.
	 */
	public static function is_available() {
		// WP-CLI (wp media regenerate, imports) has no request timeout to dodge, and
		// callers there expect the siblings to exist when the command returns.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'BMO_VERSION' ) && ! ( defined( 'BMO_SUPPORTS_DEFERRED_DELETE' ) && BMO_SUPPORTS_DEFERRED_DELETE ) ) {
			return false;
		}

		return Options::is_background_enabled();
	}

	/**
	 * Mark an attachment pending and queue its conversion.
	 *
	 * @return bool False if it could not be queued; the caller then converts inline.
	 */
	public static function enqueue( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$args          = array( $attachment_id );

		update_post_meta( $attachment_id, self::PENDING_META, time() );

		if ( self::use_action_scheduler() ) {
			if ( self::as_is_queued( $args ) ) {
				return true;
			}
			if ( as_enqueue_async_action( self::HOOK, $args, self::GROUP ) ) {
				return true;
			}
		} else {
			if ( wp_next_scheduled( self::HOOK, $args ) ) {
				return true;
			}
			if ( true === wp_schedule_single_event( time(), self::HOOK, $args, true ) ) {
				return true;
			}
		}

		delete_post_meta( $attachment_id, self::PENDING_META );

		return false;
	}

	/**
	 * The job: convert every file of the attachment, clear the pending mark, and report.
	 */
	public static function run( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$success       = true;

		try {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'image' );
			}

			$enabled = Options::is_enabled();

			foreach ( AttachmentHooks::attachment_files( $attachment_id, wp_get_attachment_metadata( $attachment_id ) ) as $file ) {
				if ( $enabled ) {
					$result = Converter::convert_file_detailed( $file );
					if ( $result['failed'] > 0 ) {
						$success = false;
					}
				} else {
					Converter::discard_stale_siblings( $file );
				}
			}
		} catch ( \Throwable $e ) {
			$success = false;
		} finally {
			delete_post_meta( $attachment_id, self::PENDING_META );

			/**
			 * Fires when a background conversion job ends, successful or not, after the
			 * attachment stopped being reported as pending. beltoft-media-offload uploads
			 * the new siblings and completes its postponed local delete on this.
			 *
			 * @param int  $attachment_id Attachment ID.
			 * @param bool $success       Whether every file converted without an error
			 *                            (a sibling discarded for not being smaller counts
			 *                            as success).
			 */
			do_action( 'beltoft_webp_attachment_conversion_finished', $attachment_id, $success );
		}
	}

	/**
	 * Run a pending attachment's job now and drop its queued copy (backfill).
	 */
	public static function run_now( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		self::unschedule( array( $attachment_id ) );
		self::run( $attachment_id );
	}

	public static function is_pending( $attachment_id ) {
		return '' !== get_post_meta( (int) $attachment_id, self::PENDING_META, true );
	}

	public static function filter_pending( $pending, $attachment_id ) {
		return $pending || self::is_pending( $attachment_id );
	}

	/**
	 * IDs of attachments still marked pending, oldest first.
	 *
	 * @return int[]
	 */
	public static function pending_ids( $limit = 100 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'posts_per_page' => (int) $limit,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only attachments carrying this plugin's own pending flag, from WP-CLI, not a hot path.
					'meta_query'     => array(
						array(
							'key'     => self::PENDING_META,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Drop every queued job and pending mark (plugin deactivation / uninstall). With the
	 * marks gone, media-offload's sweep completes any local deletes it was holding back.
	 */
	public static function clear_all() {
		wp_unschedule_hook( self::HOOK );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Hook only: with a group too, Action Scheduler matches only empty-args actions.
			as_unschedule_all_actions( self::HOOK );
		}

		delete_post_meta_by_key( self::PENDING_META );
	}

	private static function use_action_scheduler() {
		/**
		 * Whether to queue with Action Scheduler (when available) rather than WP-Cron.
		 *
		 * @param bool $use Default: whether Action Scheduler is loaded.
		 */
		return (bool) apply_filters( 'beltoft_webp_use_action_scheduler', function_exists( 'as_enqueue_async_action' ) );
	}

	private static function as_is_queued( array $args ) {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( self::HOOK, $args, self::GROUP );
		}

		return function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::HOOK, $args, self::GROUP );
	}

	private static function unschedule( array $args ) {
		wp_clear_scheduled_hook( self::HOOK, $args );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		}
	}
}
