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
	 */
	public static function on_generate_attachment_metadata( $metadata, $attachment_id ) {
		if ( ! Options::is_enabled() ) {
			return $metadata;
		}

		$original = get_attached_file( $attachment_id );

		if ( ! $original ) {
			return $metadata;
		}

		Converter::convert_file( $original );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $original );

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					Converter::convert_file( $dir . '/' . $size['file'] );
				}
			}
		}

		return $metadata;
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

			if ( file_exists( $sibling ) ) {
				wp_delete_file( $sibling );
			}
		}

		return $file;
	}
}
