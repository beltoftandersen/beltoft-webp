<?php
/**
 * Plugin Name: Beltoft WebP
 * Description: Keeps .avif and .webp siblings next to every uploaded JPEG/PNG, so nginx serves the best format each browser accepts and the original to those that accept neither. Includes a backfill command.
 * Version:     1.2.0
 * Author:      Tapas til Døren
 * Requires PHP: 8.0
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
 *
 * @package beltoft-webp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BELTOFT_WEBP_QUALITY = 85;

/**
 * AVIF quality.
 *
 * Chosen from a measured ladder over three representative photos, scoring each rung's
 * RMSE against the source JPEG (ImageMagick 6 has no SSIM) expressed as PSNR:
 *
 *     q50 (the encoder default)  35% of JPEG size   32.9 dB  - soft on fine detail
 *     q60                        51%                35.1 dB
 *     q70                        63%                36.6 dB
 *     q65                        57%                35.8 dB
 *     q70                        63%                36.6 dB  <- chosen
 *     q75                        69%                37.3 dB
 *     q80                        76%                37.8 dB  - little gained for the bytes
 *
 * 70 rather than the encoder default, because the photography *is* the product on a
 * catering site and the default sat at the weakest rung. It is also matched to the WebP
 * quality deliberately: at 65 the AVIF measured *below* its own WebP fallback, so the
 * better-equipped browser saw the worse picture. It must never be the weaker of the two.
 *
 * Why not higher: this build's AVIF encoder (ImageMagick 6.9 via the heif delegate) is
 * not competitive at high quality. At q75 the front-page hero encoded to 153KB while its
 * WebP sibling was 104KB - so every AVIF-capable browser would have been served the
 * LARGER file for choosing the better format, and throttled LCP went from 2.1s to 6.2s.
 * A current libavif would not behave this way; on this box it does. Hence the guard
 * below rather than trusting the format to win.
 */
const BELTOFT_AVIF_QUALITY = 70;

/**
 * Formats generated for every source image, best first.
 *
 * Order matters and must match the try_files chain in the vhost: nginx tries
 * $uri$avif_suffix, then $uri$webp_suffix, then the original, so a browser that accepts
 * AVIF gets it, one that only accepts WebP gets that, and anything older gets the
 * untouched JPEG/PNG.
 */
function beltoft_webp_formats() {
	return array( 'avif', 'webp' );
}

/**
 * Convert one image file to one sibling format.
 *
 * @param string $path   Absolute path to a JPEG or PNG.
 * @param string $format 'webp' or 'avif'.
 * @return bool Whether a sibling in that format is now in place.
 */
function beltoft_webp_convert_file_to( $path, $format, $force = false ) {
	if ( ! is_string( $path ) || ! is_readable( $path ) || ! class_exists( 'Imagick' ) ) {
		return false;
	}

	if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
		return false;
	}

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

	if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
		return false;
	}

	$out = $path . '.' . $format;

	if ( ! $force && file_exists( $out ) && filemtime( $out ) >= filemtime( $path ) ) {
		return true;
	}

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
		$probe = @getimagesize( $path );
		$png_alpha = ! empty( $probe ) && ! empty( $probe['channels'] ) ? 4 === (int) $probe['channels'] : true;
	}

	if ( 'webp' === $format && ! $png_alpha && function_exists( 'imagewebp' ) ) {
		$src = 'png' === $ext ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );

		if ( ! $src ) {
			return false;
		}

		$written = @imagewebp( $src, $out, BELTOFT_WEBP_QUALITY );
		imagedestroy( $src );

		if ( ! $written || ! file_exists( $out ) ) {
			return false;
		}

		if ( filesize( $out ) >= filesize( $path ) ) {
			wp_delete_file( $out );

			return false;
		}

		chmod( $out, 0640 );

		return true;
	}

	try {
		$im = new Imagick( $path );
		$im->setImageFormat( $format );

		if ( 'png' === $ext && $im->getImageAlphaChannel() && 'webp' === $format ) {
			// Flat graphics with transparency (logos, badges) go lossless: lossy
			// compression fringes hard edges, and these files are small to begin with.
			$im->setOption( 'webp:lossless', 'true' );
		} elseif ( 'webp' === $format ) {
			$im->setCompressionQuality( BELTOFT_WEBP_QUALITY );
			$im->setOption( 'webp:method', '6' );
		} else {
			$im->setCompressionQuality( BELTOFT_AVIF_QUALITY );
		}

		/*
		 * setCompressionQuality(), not setImageCompressionQuality().
		 *
		 * The image-level setter is a silent no-op for both formats on this build
		 * (ImageMagick 6.9.12): every quality from 10 to 95 produced byte-identical
		 * output, so the quality constants above did nothing and everything was encoded
		 * at the encoder's own default. Only the wand-level setter reaches the delegate.
		 * Verify with a spread - q30 vs q95 must differ in size - after any change here,
		 * because nothing errors when it is ignored.
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
	} catch ( Throwable $e ) {
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
	chmod( $out, 0640 );

	return true;
}

/**
 * Generate every configured sibling format for one file.
 *
 * @param string $path Absolute path to a JPEG or PNG.
 * @return int Number of sibling files now in place.
 */
function beltoft_webp_convert_file( $path, $force = false ) {
	$made = 0;

	// WebP first, deliberately: AVIF is only worth serving if it beats WebP, and that
	// cannot be checked until the WebP sibling exists. Serving preference is the other
	// way round and lives in the vhost's try_files chain.
	if ( beltoft_webp_convert_file_to( $path, 'webp', $force ) ) {
		++$made;
	}

	if ( beltoft_webp_convert_file_to( $path, 'avif', $force ) ) {
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

	return $made;
}

/**
 * Convert the original and every generated size when an attachment is created or its
 * sizes are regenerated.
 *
 * Hooked on metadata generation rather than on upload, so it also covers regeneration
 * and any image size registered later.
 */
add_filter(
	'wp_generate_attachment_metadata',
	static function ( $metadata, $attachment_id ) {
		$original = get_attached_file( $attachment_id );

		if ( ! $original ) {
			return $metadata;
		}

		beltoft_webp_convert_file( $original );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $original );

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					beltoft_webp_convert_file( $dir . '/' . $size['file'] );
				}
			}
		}

		return $metadata;
	},
	10,
	2
);

/**
 * Remove the sibling when WordPress removes an image.
 *
 * Without this, deleting or re-cropping an image leaves an orphaned .webp that
 * try_files would happily keep serving - so the site would show the old picture with no
 * trace of why.
 */
add_filter(
	'wp_delete_file',
	static function ( $file ) {
		if ( ! is_string( $file ) ) {
			return $file;
		}

		foreach ( beltoft_webp_formats() as $format ) {
			$sibling = $file . '.' . $format;

			if ( file_exists( $sibling ) ) {
				wp_delete_file( $sibling );
			}
		}

		return $file;
	}
);

/**
 * wp beltoft-webp backfill [--dry-run] [--force]
 *
 * Walks the uploads tree and converts anything missing or stale. Safe to re-run; it
 * skips files whose sibling is already newer than the source. Use after bulk-importing
 * photos or after regenerating thumbnails.
 *
 * --force rebuilds every sibling even when it looks current. Use this after changing a
 * quality constant. It exists specifically so nobody reaches for
 * `find uploads -name '*.webp' -delete` to force a rebuild: uploads also contains
 * genuine .webp ORIGINALS - WooCommerce's placeholder is one - and that command deletes
 * them along with the generated siblings. It has happened.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'beltoft-webp backfill',
		static function ( $args, $assoc_args ) {
			$dry   = ! empty( $assoc_args['dry-run'] );
			$force = ! empty( $assoc_args['force'] );
			$root  = wp_get_upload_dir()['basedir'];

			$made = 0;
			$skipped = 0;
			$rejected = 0;
			$src = 0;
			$out = 0;

			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path = $file->getPathname();

				if ( ! in_array( strtolower( $file->getExtension() ), array( 'jpg', 'jpeg', 'png' ), true ) ) {
					continue;
				}

				$stale = $force;

				foreach ( beltoft_webp_formats() as $format ) {
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

				if ( beltoft_webp_convert_file( $path, $force ) ) {
					++$made;
					$src += filesize( $path );

					// Report against the format the browser will actually prefer.
					$best = $path . '.' . beltoft_webp_formats()[0];
					$out += file_exists( $best ) ? filesize( $best ) : filesize( $path );
				} else {
					++$rejected;
				}
			}

			WP_CLI::log( sprintf( 'converted: %d   already current: %d   not smaller (original kept): %d', $made, $skipped, $rejected ) );

			if ( $src ) {
				WP_CLI::log( sprintf( 'bytes: %s KB -> %s KB (%.1f%% saved)', number_format( $src / 1024 ), number_format( $out / 1024 ), ( 1 - $out / $src ) * 100 ) );
			}

			WP_CLI::success( $dry ? 'Dry run complete.' : 'Backfill complete.' );
		}
	);
}
