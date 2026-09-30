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

		// Deactivating the plugin frees the license's activation slot on the
		// server; re-register this domain on reactivation.
		\BeltoftWebp\Licensing\License::reactivate_if_key_present();
	}

	public static function deactivate() {
		\BeltoftWebp\Licensing\License::remote_deactivate();
		wp_clear_scheduled_hook( 'bwebp_license_check' );
	}
}
