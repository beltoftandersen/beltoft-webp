<?php
namespace BeltoftWebp;

use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

class AttachmentHooks {

	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_attachment_metadata' ), 10, 2 );
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ) );
	}

	/**
	 * Convert the original and every generated size when an attachment is created or its
	 * sizes are regenerated.
	 *
	 * Hooked on metadata generation rather than on upload, so it also covers regeneration
	 * and any image size registered later.
	 *
	 * Priority 10 (the default) is deliberate, not incidental: a plugin that offloads these
	 * files to remote storage on the same filter needs its own hook at a later priority so
	 * these siblings exist on disk before it looks — see beltoft-media-offload's
	 * AttachmentHooks, priority 20, for the other half of that.
	 *
	 * Outside WP-CLI the actual encoding is normally queued (see Queue): this only marks
	 * the attachment pending, which is also what tells beltoft-media-offload, at priority
	 * 20, to hold back deleting the local files until the job has run.
	 */
	public static function on_generate_attachment_metadata( $metadata, $attachment_id ) {
		$files = self::attachment_files( $attachment_id, $metadata );

		if ( empty( $files ) ) {
			return $metadata;
		}

		$enabled = Options::is_enabled();

		if ( $enabled && Queue::is_available() && Queue::enqueue( $attachment_id ) ) {
			return $metadata;
		}

		foreach ( $files as $file ) {
			if ( $enabled ) {
				Converter::convert_file( $file );
			} else {
				// Not converting, but a regenerated size must not keep being served as
				// its previous picture by an existing sibling.
				Converter::discard_stale_siblings( $file );
			}
		}

		return $metadata;
	}

	/**
	 * Absolute paths of an attachment's attached file and every generated size.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Attachment metadata (in-flight or saved).
	 * @return string[]
	 */
	public static function attachment_files( $attachment_id, $metadata ) {
		$original = get_attached_file( (int) $attachment_id );

		if ( ! $original ) {
			return array();
		}

		$files = array( $original );

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $original );

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Remove the sibling when WordPress removes an image.
	 *
	 * Without this, deleting or re-cropping an image leaves an orphaned .webp that
	 * try_files would happily keep serving - so the site would show the old picture with no
	 * trace of why.
	 */
	public static function on_delete_file( $file ) {
		if ( ! is_string( $file ) ) {
			return $file;
		}

		foreach ( Converter::KNOWN_FORMATS as $format ) {
			$sibling = $file . '.' . $format;

			foreach ( array( $sibling, $sibling . Converter::SKIP_SUFFIX ) as $leftover ) {
				if ( file_exists( $leftover ) ) {
					wp_delete_file( $leftover );
				}
			}
		}

		return $file;
	}
}
