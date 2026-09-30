<?php
namespace BeltoftWebp\Cli;

use BeltoftWebp\Converter;
use BeltoftWebp\Queue;
use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * wp beltoft-webp backfill [--dry-run] [--force]
 *
 * Walks the uploads tree and converts anything missing or stale. Safe to re-run; it
 * skips files whose sibling is already newer than the source. Use after bulk-importing
 * photos or after regenerating thumbnails.
 *
 * --force rebuilds every sibling even when it looks current. Use this after changing a
 * quality setting. It exists specifically so nobody reaches for
 * `find uploads -name '*.webp' -delete` to force a rebuild: uploads also contains
 * genuine .webp ORIGINALS - WooCommerce's placeholder is one - and that command deletes
 * them along with the generated siblings. It has happened.
 *
 * Skips protected/non-media top-level directories (EXCLUDED_DIRS) and hidden ones. Also
 * repairs the permissions/ownership of current siblings so they match their source (see
 * Converter::repair_permissions()); run it as the web server's user, or as root, which
 * hands every file it writes to the source file's owner.
 */
class BackfillCommand {

	public static function register() {
		\WP_CLI::add_command( 'beltoft-webp backfill', array( __CLASS__, 'run' ) );
	}

	/**
	 * Top-level uploads directories that hold protected or non-media files (private
	 * downloads, logs, form submissions). Filterable via beltoft_webp_backfill_excluded_dirs.
	 */
	const EXCLUDED_DIRS = array( 'woocommerce_uploads', 'wc-logs', 'edd', 'gravity_forms', 'wpforms' );

	public static function run( $args, $assoc_args ) {
		$dry   = ! empty( $assoc_args['dry-run'] );
		$force = ! empty( $assoc_args['force'] );
		$root  = untrailingslashit( wp_get_upload_dir()['basedir'] );

		$made     = 0;
		$skipped  = 0;
		$rejected = 0;
		$failed   = 0;
		$repaired = 0;
		$src      = 0;
		$out      = 0;

		// Only currently-enabled formats, avif-first — not Converter::KNOWN_FORMATS
		// (every format this plugin can produce, regardless of settings). Using
		// KNOWN_FORMATS here would mark every file stale forever after disabling
		// a format (its sibling would never exist, so the staleness check below
		// would never pass), and would report savings against whichever format
		// happens to be listed first rather than the one the browser prefers.
		$formats = Options::enabled_formats();

		/**
		 * Top-level directories under uploads/ that backfill never enters.
		 *
		 * @param string[] $dirs Directory names relative to the uploads base directory.
		 */
		$excluded = (array) apply_filters( 'beltoft_webp_backfill_excluded_dirs', self::EXCLUDED_DIRS );

		// Background jobs that never ran (a stalled queue, cron not firing) first: running
		// them here converts those attachments, clears their pending marks, and fires
		// beltoft_webp_attachment_conversion_finished so beltoft-media-offload can complete
		// the local deletes it is holding back. The filesystem walk below then finds their
		// files current.
		$pending_done = 0;
		$pending      = Queue::pending_ids();

		if ( $dry ) {
			$pending_done = count( $pending );
		} else {
			$seen = array();
			while ( $pending ) {
				foreach ( $pending as $attachment_id ) {
					if ( isset( $seen[ $attachment_id ] ) ) {
						break 2; // Mark could not be cleared; don't loop on it.
					}
					$seen[ $attachment_id ] = true;
					Queue::run_now( $attachment_id );
					++$pending_done;
				}
				$pending = Queue::pending_ids();
			}
		}

		if ( $pending_done ) {
			\WP_CLI::log( sprintf( $dry ? 'pending background conversions to run: %d' : 'pending background conversions run: %d', $pending_done ) );
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				function ( $entry ) use ( $root, $excluded ) {
					if ( ! $entry->isDir() ) {
						return true;
					}
					if ( 0 === strpos( $entry->getFilename(), '.' ) ) {
						return false;
					}
					return ! ( dirname( $entry->getPathname() ) === $root && in_array( $entry->getFilename(), $excluded, true ) );
				}
			)
		);

		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();

			if ( ! in_array( strtolower( $file->getExtension() ), Converter::SOURCE_EXTENSIONS, true ) ) {
				continue;
			}

			// Current means the sibling is newer than its source, or was deliberately
			// rejected for this version of it (see Converter::SKIP_SUFFIX) — otherwise
			// every rejected sibling would be re-encoded on every run.
			$stale = $force;

			foreach ( $formats as $format ) {
				if ( ! Converter::is_current( $path, $format ) ) {
					$stale = true;
				}
			}

			if ( ! $stale ) {
				++$skipped;

				if ( ! $dry ) {
					// Every format, not only enabled ones: a sibling left from when a
					// format was enabled is still served by nginx.
					foreach ( Converter::KNOWN_FORMATS as $format ) {
						if ( Converter::repair_permissions( $path . '.' . $format, $path ) ) {
							++$repaired;
						}
					}
				}
				continue;
			}

			if ( $dry ) {
				++$made;
				continue;
			}

			$result = Converter::convert_file_detailed( $path, $force );

			foreach ( $result['errors'] as $format => $reason ) {
				\WP_CLI::warning( sprintf( '%s -> %s: %s', $path, $format, $reason ) );
			}

			if ( $result['written'] > 0 ) {
				++$made;
				$src += filesize( $path );

				// Report against the format the browser will actually prefer.
				$best = $path;
				foreach ( $formats as $format ) {
					if ( file_exists( $path . '.' . $format ) ) {
						$best = $path . '.' . $format;
						break;
					}
				}
				$out += filesize( $best );
			} elseif ( $result['failed'] > 0 ) {
				++$failed;
			} elseif ( $result['rejected'] > 0 ) {
				++$rejected;
			} else {
				++$skipped;
			}
		}

		\WP_CLI::log(
			sprintf(
				$dry
					? 'would convert: %d   already current: %d'
					: 'converted: %d   already current: %d   not smaller (original kept): %d   failed: %d   permissions repaired: %d',
				$made,
				$skipped,
				$rejected,
				$failed,
				$repaired
			)
		);

		if ( $src ) {
			\WP_CLI::log( sprintf( 'bytes: %s KB -> %s KB (%.1f%% saved)', number_format( $src / 1024 ), number_format( $out / 1024 ), ( 1 - $out / $src ) * 100 ) );
		}

		if ( ! $dry && $made > 0 ) {
			/**
			 * Fires once after a (non-dry-run) backfill run that wrote at least
			 * one sibling. This command walks the filesystem directly and never
			 * fires WordPress's own attachment hooks, so a plugin that offloads
			 * media to remote storage (and only picks up new sibling files when an
			 * attachment's metadata hook fires) would otherwise never learn that
			 * a backfill created files for attachments it already considers done.
			 */
			do_action( 'beltoft_webp_backfill_complete' );
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( '%d file(s) failed to convert; see warnings above. Run with --debug=beltoft-webp for every conversion decision.', $failed ) );
		}

		\WP_CLI::success( $dry ? 'Dry run complete.' : 'Backfill complete.' );
	}
}
