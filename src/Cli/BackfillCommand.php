<?php
namespace BeltoftWebp\Cli;

use BeltoftWebp\Converter;
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
 */
class BackfillCommand {

	public static function register() {
		\WP_CLI::add_command( 'beltoft-webp backfill', array( __CLASS__, 'run' ) );
	}

	public static function run( $args, $assoc_args ) {
		$dry   = ! empty( $assoc_args['dry-run'] );
		$force = ! empty( $assoc_args['force'] );
		$root  = wp_get_upload_dir()['basedir'];

		$made     = 0;
		$skipped  = 0;
		$rejected = 0;
		$src      = 0;
		$out      = 0;

		// Only currently-enabled formats, avif-first — not Converter::KNOWN_FORMATS
		// (every format this plugin can produce, regardless of settings). Using
		// KNOWN_FORMATS here would mark every file stale forever after disabling
		// a format (its sibling would never exist, so the staleness check below
		// would never pass), and would report savings against whichever format
		// happens to be listed first rather than the one the browser prefers.
		$formats = Options::enabled_formats();

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();

			if ( ! in_array( strtolower( $file->getExtension() ), Converter::SOURCE_EXTENSIONS, true ) ) {
				continue;
			}

			$stale = $force;

			foreach ( $formats as $format ) {
				$sibling = $path . '.' . $format;

				if ( ! file_exists( $sibling ) || filemtime( $sibling ) < filemtime( $path ) ) {
					$stale = true;
				}
			}

			if ( ! $stale ) {
				++$skipped;
				continue;
			}

			if ( $dry ) {
				++$made;
				continue;
			}

			if ( Converter::convert_file( $path, $force ) ) {
				++$made;
				$src += filesize( $path );

				// Report against the format the browser will actually prefer.
				$best = ! empty( $formats ) ? $path . '.' . $formats[0] : $path;
				$out += file_exists( $best ) ? filesize( $best ) : filesize( $path );
			} else {
				++$rejected;
			}
		}

		\WP_CLI::log( sprintf( 'converted: %d   already current: %d   not smaller (original kept): %d', $made, $skipped, $rejected ) );

		if ( $src ) {
			\WP_CLI::log( sprintf( 'bytes: %s KB -> %s KB (%.1f%% saved)', number_format( $src / 1024 ), number_format( $out / 1024 ), ( 1 - $out / $src ) * 100 ) );
		}

		if ( ! $dry && $made > 0 ) {
			/**
			 * Fires once after a (non-dry-run) backfill run that converted at least
			 * one file. This command walks the filesystem directly and never fires
			 * WordPress's own attachment hooks, so a plugin that offloads media to
			 * remote storage (and only picks up new sibling files when an
			 * attachment's metadata hook fires) would otherwise never learn that
			 * a backfill created files for attachments it already considers done.
			 */
			do_action( 'beltoft_webp_backfill_complete' );
		}

		\WP_CLI::success( $dry ? 'Dry run complete.' : 'Backfill complete.' );
	}
}
