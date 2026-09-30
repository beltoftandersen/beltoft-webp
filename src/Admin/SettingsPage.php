<?php
namespace BeltoftWebp\Admin;

use BeltoftWebp\Licensing\License;
use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_beltoft-webp' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'bwebp-admin-license',
			plugins_url( 'assets/admin-license.js', BWEBP_FILE ),
			array(),
			BWEBP_VERSION,
			true
		);
		wp_localize_script(
			'bwebp-admin-license',
			'bwebpLicense',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bwebp_license' ),
				'i18n'    => array(
					'activate'           => __( 'Activate', 'beltoft-webp' ),
					'activating'         => __( 'Activating…', 'beltoft-webp' ),
					'activationFailed'   => __( 'Activation failed.', 'beltoft-webp' ),
					'deactivate'         => __( 'Deactivate', 'beltoft-webp' ),
					'deactivating'       => __( 'Deactivating…', 'beltoft-webp' ),
					'deactivationFailed' => __( 'Deactivation failed.', 'beltoft-webp' ),
					'confirmDeactivate'  => __( 'Deactivate this license on this site?', 'beltoft-webp' ),
					'requestFailed'      => __( 'Request failed. Please try again.', 'beltoft-webp' ),
				),
			)
		);
	}

	public static function add_menu() {
		add_options_page(
			__( 'Beltoft WebP', 'beltoft-webp' ),
			__( 'Beltoft WebP', 'beltoft-webp' ),
			'manage_options',
			'beltoft-webp',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			Options::SETTING_GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BeltoftWebp\\Support\\Options', 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'beltoft-webp' ) );
		}

		$options        = Options::all();
		$license_key    = Options::get( 'license_key' );
		$license_status = Options::get( 'license_status' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Beltoft WebP Settings', 'beltoft-webp' ); ?></h1>
			<p><?php esc_html_e( 'Keeps .avif and .webp siblings next to every uploaded JPEG/PNG, for a web server configured to serve the best format each browser accepts.', 'beltoft-webp' ); ?></p>

			<h2><?php esc_html_e( 'License', 'beltoft-webp' ); ?></h2>
			<p>
				<?php
				if ( License::is_active() ) {
					esc_html_e( 'License active — automatic updates are enabled.', 'beltoft-webp' );
				} else {
					esc_html_e( 'Optional. A free license enables automatic updates for this plugin; everything else works without one.', 'beltoft-webp' );
				}
				?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="bwebp-license-key"><?php esc_html_e( 'License Key', 'beltoft-webp' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="bwebp-license-key" value="<?php echo esc_attr( $license_key ); ?>" <?php disabled( License::is_active() ); ?> />
						<?php if ( License::is_active() ) : ?>
							<button type="button" id="bwebp-deactivate-license" class="button"><?php esc_html_e( 'Deactivate', 'beltoft-webp' ); ?></button>
						<?php else : ?>
							<button type="button" id="bwebp-activate-license" class="button button-primary"><?php esc_html_e( 'Activate', 'beltoft-webp' ); ?></button>
						<?php endif; ?>
						<p id="bwebp-license-message"></p>
						<?php if ( $license_status && ! License::is_active() ) : ?>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: license status */ __( 'Status: %s', 'beltoft-webp' ), $license_status ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Conversion Settings', 'beltoft-webp' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( Options::SETTING_GROUP ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="bwebp_enabled"><?php esc_html_e( 'Enabled', 'beltoft-webp' ); ?></label></th>
						<td><input type="checkbox" id="bwebp_enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[enabled]" value="1" <?php checked( '1', $options['enabled'] ); ?> /> <span class="description"><?php esc_html_e( 'Convert on upload and thumbnail regeneration. The WP-CLI backfill command works regardless of this setting.', 'beltoft-webp' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="bwebp_webp_enabled"><?php esc_html_e( 'WebP', 'beltoft-webp' ); ?></label></th>
						<td>
							<input type="checkbox" id="bwebp_webp_enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[webp_enabled]" value="1" <?php checked( '1', $options['webp_enabled'] ); ?> />
							<label for="bwebp_webp_quality"><?php esc_html_e( 'Quality', 'beltoft-webp' ); ?></label>
							<input type="number" min="1" max="100" step="1" class="small-text" id="bwebp_webp_quality" name="<?php echo esc_attr( Options::OPTION ); ?>[webp_quality]" value="<?php echo esc_attr( $options['webp_quality'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Default 85, chosen for this server\'s GD encoder — 65 measured well below the WebP default and 85 gave a measurable quality gain for the extra bytes. See readme for the full measurement.', 'beltoft-webp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bwebp_avif_enabled"><?php esc_html_e( 'AVIF', 'beltoft-webp' ); ?></label></th>
						<td>
							<input type="checkbox" id="bwebp_avif_enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[avif_enabled]" value="1" <?php checked( '1', $options['avif_enabled'] ); ?> />
							<label for="bwebp_avif_quality"><?php esc_html_e( 'Quality', 'beltoft-webp' ); ?></label>
							<input type="number" min="1" max="100" step="1" class="small-text" id="bwebp_avif_quality" name="<?php echo esc_attr( Options::OPTION ); ?>[avif_quality]" value="<?php echo esc_attr( $options['avif_quality'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Default 70, deliberately matched against this server\'s WebP output so AVIF never loses to its own WebP sibling — raising it can make AVIF larger than WebP on this server\'s encoder. See readme before changing.', 'beltoft-webp' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Changing a quality value does not touch existing files — run wp beltoft-webp backfill --force to rebuild them.', 'beltoft-webp' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
