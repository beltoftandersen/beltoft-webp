<?php
namespace BeltoftWebp;

use BeltoftWebp\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Converts JPEG/PNG files to WebP/AVIF siblings.
 *
 * WHY THIS IS A PLUGIN AND NOT CHILD-THEME CODE
 *
 * Image delivery is not presentation - it has to keep working across a theme change,
 * the same reasoning that moved the minimum-cover rules into their own plugin. If this
 * lived in the child theme, switching themes would silently stop generating WebP for
 * new photos while the existing files carried on being served, so the regression would
 * be invisible: nothing breaks, the site just gets slower again.
 *
 * WHY NOT A THIRD-PARTY WEBP PLUGIN
 *
 * The delivery half was already solved at the web-server level before this existed. The
 * vhost does:
 *
 *     map $http_accept $webp_suffix { default ""; "~*webp" ".webp"; }
 *     location ~* ^.+\.(png|jpe?g|gif)$ {
 *         add_header Vary Accept;
 *         try_files $uri$webp_suffix $uri =404;
 *     }
 *
 * So a request for photo.jpg is answered with photo.jpg.webp when the browser lists
 * image/webp in Accept, and falls through to photo.jpg when it does not - no markup
 * change, no <picture> element, nothing for CSS to trip over, and Vary: Accept keeps
 * shared caches honest. WebP Express and Converter for Media both want to install their
 * own rewrite rules, which would duplicate or contend with that. All that was actually
 * missing was the files. Hence the append naming: it is what try_files looks for.
 *
 * Backfill measured 350 files, 41MB -> 18.8MB, taking throttled front-page LCP from
 * 12.6s to 5.0s and the shop from 4.4s to 1.7s.
 */
class Converter {

	/**
	 * Source extensions this plugin will convert.
	 */
	const SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png' );

	/**
	 * Formats this plugin knows how to produce, regardless of whether each
	 * is currently enabled in settings.
	 */
	const KNOWN_FORMATS = array( 'webp', 'avif' );

	/**
	 * Convert one image file to one sibling format.
	 *
	 * @param string $path   Absolute path to a JPEG or PNG.
	 * @param string $format 'webp' or 'avif'.
	 * @return bool Whether a sibling in that format is now in place.
	 */
	public static function convert_file_to( $path, $format, $force = false ) {
		if ( ! is_string( $path ) || ! is_readable( $path ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		if ( ! in_array( $format, self::KNOWN_FORMATS, true ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, self::SOURCE_EXTENSIONS, true ) ) {
			return false;
		}

		$out = $path . '.' . $format;

		if ( ! $force && file_exists( $out ) && filemtime( $out ) >= filemtime( $path ) ) {
			return true;
		}

		$quality = Options::quality( $format );

		/*
		 * WebP goes through GD, not Imagick.
		 *
		 * On this build (ImageMagick 6.9.12) the WebP delegate ignores the quality setting
		 * entirely - wand-level and image-level alike - so every WebP was written at the
		 * encoder default and measured 33.6 dB PSNR. GD's imagewebp() takes quality as an
		 * argument and honours it: 85 measures 36.6 dB, a 3 dB gain. On a catering site the
		 * photography is the product, so that is worth the bytes.
		 *
		 * Imagick still handles AVIF (where it does honour wand-level quality) and PNGs with
		 * transparency, which need lossless.
		 */
		$png_alpha = false;

		if ( 'png' === $ext ) {
			$probe     = @getimagesize( $path );
			$png_alpha = ! empty( $probe ) && ! empty( $probe['channels'] ) ? 4 === (int) $probe['channels'] : true;
		}

		if ( 'webp' === $format && ! $png_alpha && function_exists( 'imagewebp' ) ) {
			$src = 'png' === $ext ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );

			if ( ! $src ) {
				return false;
			}

			$written = @imagewebp( $src, $out, $quality );
			imagedestroy( $src );

			if ( ! $written || ! file_exists( $out ) ) {
				return false;
			}

			if ( filesize( $out ) >= filesize( $path ) ) {
				wp_delete_file( $out );

				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- matching the permissions WordPress itself gives uploaded media, on a file this process just created.
			chmod( $out, 0640 );

			return true;
		}

		try {
			$im = new \Imagick( $path );
			$im->setImageFormat( $format );

			if ( 'png' === $ext && $im->getImageAlphaChannel() && 'webp' === $format ) {
				// Flat graphics with transparency (logos, badges) go lossless: lossy
				// compression fringes hard edges, and these files are small to begin with.
				$im->setOption( 'webp:lossless', 'true' );
			} elseif ( 'webp' === $format ) {
				$im->setCompressionQuality( $quality );
				$im->setOption( 'webp:method', '6' );
			} else {
				$im->setCompressionQuality( $quality );
			}

			/*
			 * setCompressionQuality(), not setImageCompressionQuality().
			 *
			 * The image-level setter is a silent no-op for both formats on this build
			 * (ImageMagick 6.9.12): every quality from 10 to 95 produced byte-identical
			 * output, so a wrong setter here would silently undo the setting above and
			 * everything would be encoded at the encoder's own default. Only the
			 * wand-level setter reaches the delegate. Verify with a spread - q30 vs q95
			 * must differ in size - after any change here, because nothing errors when
			 * it is ignored.
			 *
			 * Known limitation: WebP ignores quality even wand-level on this build, so WebP
			 * output is whatever the delegate defaults to (measured 33.6 dB PSNR, ~48% of
			 * JPEG). It is the narrow fallback - AVIF covers the large majority of browsers -
			 * so this is accepted rather than worked around via GD.
			 */

			// Colour profiles and EXIF are dead weight on a web-sized derivative.
			$im->stripImage();
			$im->writeImage( $out );
			$im->clear();
			$im->destroy();
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! file_exists( $out ) ) {
			return false;
		}

		// A sibling that is not smaller would make the site slower, because try_files
		// prefers it over the original. Discard it.
		if ( filesize( $out ) >= filesize( $path ) ) {
			wp_delete_file( $out );

			return false;
		}

		// Match the permissions WordPress gives the originals (640), or nginx cannot read
		// the file and the image 404s for exactly the browsers that asked for it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same justification as convert_file_to()'s WebP branch above.
		chmod( $out, 0640 );

		return true;
	}

	/**
	 * Generate every enabled sibling format for one file.
	 *
	 * @param string $path Absolute path to a JPEG or PNG.
	 * @return int Number of sibling files now in place.
	 */
	public static function convert_file( $path, $force = false ) {
		$made = 0;

		// WebP first, deliberately: AVIF is only worth serving if it beats WebP, and that
		// cannot be checked until the WebP sibling exists. Serving preference is the other
		// way round and lives in the vhost's try_files chain.
		$do_webp = Options::is_format_enabled( 'webp' );
		$do_avif = Options::is_format_enabled( 'avif' );

		if ( $do_webp && self::convert_file_to( $path, 'webp', $force ) ) {
			++$made;
		}

		if ( $do_avif && self::convert_file_to( $path, 'avif', $force ) ) {
			$avif = $path . '.avif';
			$webp = $path . '.webp';

			/*
			 * An AVIF larger than the WebP it outranks is a pure loss: nginx prefers it, so
			 * the better-equipped browser would download more bytes than the older one.
			 * Measured really happening on this build at higher qualities. Drop it and let
			 * the chain fall through to WebP.
			 */
			if ( file_exists( $webp ) && filesize( $avif ) >= filesize( $webp ) ) {
				wp_delete_file( $avif );
			} else {
				++$made;
			}
		}

		if ( $made > 0 ) {
			/**
			 * Fires after at least one sibling format was created or updated for a
			 * source file, from any caller (upload-time conversion or the backfill
			 * command). Other plugins that need to know a new derivative exists on
			 * disk — e.g. one that offloads media to remote storage and only scans
			 * for siblings at the moment it uploads a file — can listen for this
			 * rather than needing their own filesystem watcher.
			 *
			 * @param string $path Absolute path to the JPEG/PNG source file.
			 */
			do_action( 'beltoft_webp_file_converted', $path );
		}

		return $made;
	}
}
