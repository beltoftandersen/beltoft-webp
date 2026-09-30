<?php
namespace BeltoftWebp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings storage.
 *
 * Defaults for the quality values are NOT arbitrary — see readme.txt's
 * "Quality defaults" section for the measurements behind them (specific to
 * this box's ImageMagick/GD build). They're admin-configurable because a
 * different server's image libraries can behave differently (this plugin's
 * own comments document one such case: WebP quality being silently ignored
 * by one ImageMagick delegate), not because the defaults are guesses.
 */
class Options {

	const OPTION        = 'bwebp_options';
	const SETTING_GROUP = 'bwebp_settings';

	public static function defaults() {
		return array(
			'enabled'      => '1',
			'webp_enabled' => '1',
			'avif_enabled' => '1',
			'webp_quality' => '85',
			'avif_quality' => '70',

			// License (see Licensing\License) — never posted by the main
			// settings form, only ever written internally via save().
			'license_key'             => '',
			'license_status'          => '',
			'license_expires'         => '',
			'license_last_checked'    => '',
			'license_remote_version'  => '',
			'license_max_activations' => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Programmatic write helper for internal/CLI use (Licensing\License,
	 * ad hoc scripts) — never fed raw request input directly. Bypasses the
	 * registered Settings API sanitize filter (removed for the duration of
	 * this call, same pattern as beltoft-media-offload's Options::save())
	 * so a partial write here can't be silently reshaped by a sanitize
	 * callback tuned for full form submissions.
	 *
	 * Deliberately does NOT call self::sanitize() on its own write: sanitize()
	 * always pulls license_* fields from the currently-stored value (see its
	 * docblock) precisely so a settings-form submit can't touch them — running
	 * a save() write through sanitize() would immediately discard the very
	 * license values this method exists to persist, since update_option()
	 * hasn't happened yet when sanitize() reads "current".
	 */
	public static function save( array $values ) {
		$merged = wp_parse_args( $values, self::all() );

		$sanitize_hook = 'sanitize_option_' . self::OPTION;
		$sanitize_cb   = array( __CLASS__, 'sanitize' );
		$priority      = has_filter( $sanitize_hook, $sanitize_cb );

		if ( false !== $priority ) {
			remove_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}

		update_option( self::OPTION, $merged );

		if ( false !== $priority ) {
			add_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function is_enabled() {
		return '1' === self::get( 'enabled' );
	}

	/**
	 * @param string $format 'webp' or 'avif'.
	 */
	public static function is_format_enabled( $format ) {
		return '1' === self::get( $format . '_enabled' );
	}

	/**
	 * @param string $format 'webp' or 'avif'.
	 * @return int 1-100.
	 */
	public static function quality( $format ) {
		return (int) self::get( $format . '_quality' );
	}

	/**
	 * Enabled formats, best-first — same order the try_files chain in the
	 * vhost must use, so AVIF (when enabled) is always offered before WebP.
	 *
	 * @return string[]
	 */
	public static function enabled_formats() {
		$formats = array();
		if ( self::is_format_enabled( 'avif' ) ) {
			$formats[] = 'avif';
		}
		if ( self::is_format_enabled( 'webp' ) ) {
			$formats[] = 'webp';
		}
		return $formats;
	}

	/**
	 * Settings API sanitize callback.
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$out['enabled']      = ! empty( $input['enabled'] ) ? '1' : '0';
		$out['webp_enabled'] = ! empty( $input['webp_enabled'] ) ? '1' : '0';
		$out['avif_enabled'] = ! empty( $input['avif_enabled'] ) ? '1' : '0';

		$out['webp_quality'] = isset( $input['webp_quality'] ) ? (string) min( 100, max( 1, absint( $input['webp_quality'] ) ) ) : $out['webp_quality'];
		$out['avif_quality'] = isset( $input['avif_quality'] ) ? (string) min( 100, max( 1, absint( $input['avif_quality'] ) ) ) : $out['avif_quality'];

		// License fields are deliberately never taken from $input, under any
		// circumstance — the main settings form never includes them (so a
		// normal submit must not silently wipe the license), and a crafted
		// POST adding them must not be able to forge a license status either.
		// Always keep whatever is currently stored; only save()'s filter-
		// bypassed write path (used by Licensing\License) can change them.
		$current = self::all();
		foreach ( array( 'license_key', 'license_status', 'license_expires', 'license_last_checked', 'license_remote_version', 'license_max_activations' ) as $license_field ) {
			$out[ $license_field ] = $current[ $license_field ];
		}

		return $out;
	}
}
