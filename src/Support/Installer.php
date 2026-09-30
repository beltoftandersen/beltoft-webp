<?php
namespace BeltoftWebp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Activation-time setup.
 */
class Installer {

	public static function activate() {
		if ( false === get_option( Options::OPTION, false ) ) {
			add_option( Options::OPTION, Options::defaults() );
		}

		if ( ! wp_next_scheduled( 'bwebp_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'bwebp_license_check' );
		}

		// Deactivating the plugin frees an active license's slot on the server;
		// re-register this domain on reactivation.
		\BeltoftWebp\Licensing\License::reactivate_if_previously_active();
	}

	public static function deactivate() {
		\BeltoftWebp\Licensing\License::remote_deactivate();

		// Nothing will run queued jobs or answer the pending filter any more; clearing
		// the marks lets beltoft-media-offload complete the local deletes it held back.
		\BeltoftWebp\Queue::clear_all();
		wp_clear_scheduled_hook( 'bwebp_license_check' );
	}
}
