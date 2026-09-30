<?php
namespace BeltoftWebp\Admin;

use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
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

		$options = Options::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Beltoft WebP Settings', 'beltoft-webp' ); ?></h1>
			<p><?php esc_html_e( 'Keeps .avif and .webp siblings next to every uploaded JPEG/PNG, for a web server configured to serve the best format each browser accepts.', 'beltoft-webp' ); ?></p>
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
