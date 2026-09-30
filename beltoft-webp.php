<?php
/**
 * Plugin Name:       Beltoft WebP
 * Description:       Keeps .avif and .webp siblings next to every uploaded JPEG/PNG, so nginx serves the best format each browser accepts and the original to those that accept neither. Includes a backfill command.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Internal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-webp
 *
 * @package BeltoftWebp
 */

defined( 'ABSPATH' ) || exit;

// PSR-4 autoloader for the BeltoftWebp\ namespace.
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'BeltoftWebp\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'BeltoftWebp\\' ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		$file     = plugin_dir_path( __FILE__ ) . 'src/' . $relative;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

define( 'BWEBP_VERSION', '2.0.0' );
define( 'BWEBP_FILE', __FILE__ );
define( 'BWEBP_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWEBP_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( \BeltoftWebp\Support\Options::OPTION, false ) ) {
			add_option( \BeltoftWebp\Support\Options::OPTION, \BeltoftWebp\Support\Options::defaults() );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		\BeltoftWebp\AttachmentHooks::init();
		if ( is_admin() ) {
			\BeltoftWebp\Admin\SettingsPage::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\BeltoftWebp\Cli\BackfillCommand::register();
		}
	}
);
