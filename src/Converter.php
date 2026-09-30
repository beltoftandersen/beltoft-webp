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
 * The delivery half is solved at the web-server level (see readme for the full AVIF-first
 * vhost snippet):
 *
 *     try_files $uri$bwebp_avif $uri$bwebp_webp $uri =404;
 *
 * So a request for photo.jpg is answered with photo.jpg.avif or photo.jpg.webp when the
 * browser lists that format in Accept, and falls through to photo.jpg when it does not -
 * no markup change, no <picture> element, nothing for CSS to trip over, and Vary: Accept
 * keeps shared caches honest. WebP Express and Converter for Media both want to install
 * their own rewrite rules, which would duplicate or contend with that. All that was
 * actually missing was the files. Hence the append naming: it is what try_files looks for.
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
	 * Suffix (after the sibling name) of the tiny marker file recording that a sibling was
	 * deliberately not kept for the current version of the source - photo.jpg.avif.skip.
	 * Without it a rejected sibling looks missing, i.e. stale, forever: every backfill
	 * would re-encode it (AVIF is the slow part) and re-fire the integration hooks for
	 * nothing. Its content is the reason: REJECT_SOURCE or REJECT_WEBP.
	 */
	const SKIP_SUFFIX = '.skip';

	/**
	 * Siblings are encoded to this temporary name and renamed into place, so nginx never
	 * serves a half-written file and an existing sibling this process cannot write to
	 * (e.g. root-owned from an earlier `wp --allow-root` backfill) is still replaced -
	 * rename only needs write access to the directory.
	 */
	const TMP_INFIX = '.bwebp-tmp';

	const REJECT_SOURCE = 'source';
	const REJECT_WEBP   = 'webp';

	const STATUS_WRITTEN  = 'written';
	const STATUS_CURRENT  = 'current';
	const STATUS_REJECTED = 'rejected';
	const STATUS_FAILED   = 'failed';

	/**
	 * Reason for the most recent STATUS_FAILED from convert_to().
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Convert one image file to one sibling format.
	 *
	 * @param string $path   Absolute path to a JPEG or PNG.
	 * @param string $format 'webp' or 'avif'.
	 * @param bool   $force  Rebuild even if the sibling looks current.
	 * @return bool Whether a sibling in that format is now in place.
	 */
	public static function convert_file_to( $path, $format, $force = false ) {
		$status = self::convert_to( $path, $format, $force );

		return self::STATUS_WRITTEN === $status
			|| ( self::STATUS_CURRENT === $status && file_exists( $path . '.' . $format ) );
	}

	/**
	 * Generate every enabled sibling format for one file.
	 *
	 * @param string $path  Absolute path to a JPEG or PNG.
	 * @param bool   $force Rebuild even if siblings look current.
	 * @return int Number of sibling files now in place.
	 */
	public static function convert_file( $path, $force = false ) {
		$result = self::convert_file_detailed( $path, $force );

		return $result['in_place'];
	}

	/**
	 * Generate every enabled sibling format for one file, reporting what happened.
	 *
	 * @param string $path  Absolute path to a JPEG or PNG.
	 * @param bool   $force Rebuild even if siblings look current.
	 * @return array{in_place:int,written:int,rejected:int,failed:int,errors:array<string,string>}
	 */
	public static function convert_file_detailed( $path, $force = false ) {
		$result = array(
			'in_place' => 0,
			'written'  => 0,
			'rejected' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);

		$webp_status = null;

		// WebP first, deliberately: AVIF is only worth serving if it beats WebP, and that
		// cannot be checked until the WebP sibling exists. Serving preference is the other
		// way round and lives in the vhost's try_files chain.
		foreach ( self::KNOWN_FORMATS as $format ) {
			if ( ! Options::is_format_enabled( $format ) ) {
				// Not generated any more, but a sibling left over from when it was must
				// not outlive a change to its source: try_files would keep serving the
				// old picture.
				self::discard_stale( $path, $format );
				continue;
			}

			$status = self::convert_to( $path, $format, $force );

			if ( 'webp' === $format ) {
				$webp_status = $status;
			}

			$avif = $path . '.avif';
			$webp = $path . '.webp';

			/*
			 * An AVIF larger than the WebP it outranks is a pure loss: nginx prefers it, so
			 * the better-equipped browser would download more bytes than the older one.
			 * Measured really happening on this build at higher qualities. Drop it and let
			 * the chain fall through to WebP. Re-checked when either side was just rebuilt.
			 */
			if ( 'avif' === $format
				&& ( self::STATUS_WRITTEN === $status || ( self::STATUS_CURRENT === $status && self::STATUS_WRITTEN === $webp_status ) )
				&& file_exists( $avif ) && file_exists( $webp )
				&& filesize( $avif ) >= filesize( $webp )
			) {
				wp_delete_file( $avif );
				self::write_skip_marker( $path, 'avif', self::REJECT_WEBP );
				$status = self::STATUS_REJECTED;
			}

			switch ( $status ) {
				case self::STATUS_WRITTEN:
					++$result['written'];
					++$result['in_place'];
					break;
				case self::STATUS_CURRENT:
					if ( file_exists( $path . '.' . $format ) ) {
						++$result['in_place'];
					}
					break;
				case self::STATUS_REJECTED:
					++$result['rejected'];
					break;
				default:
					++$result['failed'];
					$result['errors'][ $format ] = self::$last_error;
			}
		}

		if ( $result['written'] > 0 ) {
			/**
			 * Fires after at least one sibling format was actually (re)written for a
			 * source file, from any caller (upload-time conversion or the backfill
			 * command). Not fired when every sibling was already current. Other plugins
			 * that need to know a new derivative exists on disk — e.g. one that offloads
			 * media to remote storage and only scans for siblings at the moment it
			 * uploads a file — can listen for this rather than needing their own
			 * filesystem watcher.
			 *
			 * @param string $path Absolute path to the JPEG/PNG source file.
			 */
			do_action( 'beltoft_webp_file_converted', $path );
		}

		return $result;
	}

	/**
	 * Whether the sibling of $path in $format is up to date: either the sibling itself, or
	 * a skip marker recording it was deliberately not kept, is at least as new as the
	 * source.
	 *
	 * @param string $path   Absolute path to a JPEG or PNG.
	 * @param string $format 'webp' or 'avif'.
	 */
	public static function is_current( $path, $format ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$source_mtime = filemtime( $path );
		$sibling      = $path . '.' . $format;

		if ( file_exists( $sibling ) && filemtime( $sibling ) >= $source_mtime ) {
			return true;
		}

		$marker = $sibling . self::SKIP_SUFFIX;

		if ( ! file_exists( $marker ) || filemtime( $marker ) < $source_mtime ) {
			return false;
		}

		// "Larger than its WebP" only holds while there is a WebP to fall back to.
		if ( self::REJECT_WEBP === trim( (string) file_get_contents( $marker ) ) ) {
			return Options::is_format_enabled( 'webp' ) && file_exists( $path . '.webp' );
		}

		return true;
	}

	/**
	 * Delete every sibling (and skip marker) of $path that is older than $path itself.
	 * Used when upload-time conversion is switched off, so a regenerated thumbnail never
	 * keeps being served as its previous picture.
	 *
	 * @param string $path Absolute path to a JPEG or PNG.
	 */
	public static function discard_stale_siblings( $path ) {
		foreach ( self::KNOWN_FORMATS as $format ) {
			self::discard_stale( $path, $format );
		}
	}

	/**
	 * Give an existing sibling the source's permissions (and owner, when running as root).
	 * Backfill runs this on siblings it otherwise leaves alone, to repair files written by
	 * versions before 2.2.0 - which hardcoded 0640 and, under `wp --allow-root`, left them
	 * root-owned, i.e. unreadable to an nginx worker that can read the originals.
	 *
	 * @param string $sibling Absolute path to the sibling.
	 * @param string $path    Absolute path to its source.
	 * @return bool Whether anything was changed.
	 */
	public static function repair_permissions( $sibling, $path ) {
		if ( ! file_exists( $sibling ) || ! file_exists( $path ) ) {
			return false;
		}

		$changed = ( fileperms( $sibling ) & 0777 ) !== self::target_mode( $path );

		if ( self::running_as_root() ) {
			$changed = $changed || fileowner( $sibling ) !== fileowner( $path ) || filegroup( $sibling ) !== filegroup( $path );
		}

		if ( $changed ) {
			self::match_source_permissions( $sibling, $path );
		}

		return $changed;
	}

	/**
	 * Convert one image file to one sibling format.
	 *
	 * @param string $path   Absolute path to a JPEG or PNG.
	 * @param string $format 'webp' or 'avif'.
	 * @param bool   $force  Rebuild even if the sibling looks current.
	 * @return string One of the STATUS_* constants.
	 */
	private static function convert_to( $path, $format, $force ) {
		self::$last_error = '';

		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return self::fail( $path, $format, 'source not readable' );
		}

		if ( ! in_array( $format, self::KNOWN_FORMATS, true ) ) {
			return self::fail( $path, $format, 'unknown format' );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, self::SOURCE_EXTENSIONS, true ) ) {
			return self::fail( $path, $format, 'not a JPEG/PNG' );
		}

		if ( ! $force && self::is_current( $path, $format ) ) {
			return self::STATUS_CURRENT;
		}

		$out = $path . '.' . $format;

		// Ends in the real extension so Imagick picks the right coder from the name too.
		$tmp = $path . self::TMP_INFIX . '.' . $format;

		$quality   = Options::quality( $format );
		$png_alpha = 'png' === $ext && self::png_has_alpha( $path );

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
		if ( 'webp' === $format && ! $png_alpha && function_exists( 'imagewebp' ) ) {
			$error = self::encode_gd_webp( $path, $ext, $tmp, $quality );
		} elseif ( class_exists( 'Imagick' ) ) {
			$error = self::encode_imagick( $path, $ext, $format, $tmp, $quality );
		} else {
			$error = 'no encoder available (needs Imagick, or GD with WebP for opaque WebP)';
		}

		if ( '' === $error && ( ! file_exists( $tmp ) || 0 === filesize( $tmp ) ) ) {
			$error = 'encoder produced no output';
		}

		if ( '' !== $error ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			// Whatever is there now is from an older version of the source; serving the
			// original beats serving the wrong picture.
			self::discard_stale( $path, $format );

			return self::fail( $path, $format, $error );
		}

		// A sibling that is not smaller would make the site slower, because try_files
		// prefers it over the original. Discard it - and any previous sibling, which was
		// made from an older version of the source or at a quality no longer asked for.
		if ( filesize( $tmp ) >= filesize( $path ) ) {
			wp_delete_file( $tmp );
			if ( file_exists( $out ) ) {
				wp_delete_file( $out );
			}
			self::write_skip_marker( $path, $format, self::REJECT_SOURCE );

			return self::STATUS_REJECTED;
		}

		self::match_source_permissions( $tmp, $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a file this process just wrote, within the same directory.
		if ( ! rename( $tmp, $out ) ) {
			wp_delete_file( $tmp );
			self::discard_stale( $path, $format );

			return self::fail( $path, $format, 'could not move sibling into place' );
		}

		$marker = $out . self::SKIP_SUFFIX;
		if ( file_exists( $marker ) ) {
			wp_delete_file( $marker );
		}

		return self::STATUS_WRITTEN;
	}

	/**
	 * @return string Error message, or '' on success.
	 */
	private static function encode_gd_webp( $path, $ext, $tmp, $quality ) {
		$src = 'png' === $ext ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );

		if ( ! $src ) {
			return 'GD could not read the source';
		}

		// imagewebp() refuses palette images ("Palette image not supported by webp").
		if ( ! imageistruecolor( $src ) ) {
			imagepalettetotruecolor( $src );
		}

		if ( 'png' !== $ext ) {
			$src = self::gd_apply_exif_orientation( $src, $path );
		}

		if ( ! @imagewebp( $src, $tmp, $quality ) ) {
			return 'GD imagewebp() failed';
		}

		return '';
	}

	/**
	 * @return string Error message, or '' on success.
	 */
	private static function encode_imagick( $path, $ext, $format, $tmp, $quality ) {
		try {
			$im = new \Imagick( $path );

			// Browsers honour a JPEG's EXIF orientation; stripImage() below removes it, so
			// bake it into the pixels first or the sibling would show sideways.
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}

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
			 * WebP only reaches this path for PNGs with transparency (lossless, so quality
			 * is moot) or when GD lacks WebP support - where the delegate's own default
			 * quality applies, since this build ignores it even wand-level.
			 */

			// Colour profiles and EXIF are dead weight on a web-sized derivative.
			$im->stripImage();
			$im->writeImage( $tmp );
			$im->clear();
			$im->destroy();
		} catch ( \Throwable $e ) {
			return 'Imagick: ' . $e->getMessage();
		}

		return '';
	}

	/**
	 * Rotate/flip a GD image per the source JPEG's EXIF Orientation tag, so the sibling
	 * looks the way browsers display the original.
	 *
	 * @param \GdImage $src  Image read from $path.
	 * @param string   $path Source JPEG.
	 * @return \GdImage
	 */
	private static function gd_apply_exif_orientation( $src, $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $src;
		}

		$exif        = @exif_read_data( $path );
		$orientation = is_array( $exif ) && ! empty( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;

		// imagerotate() angles are counter-clockwise: 270 turns the picture 90 degrees clockwise.
		$rotate = array(
			3 => 180,
			5 => 270,
			6 => 270,
			7 => 270,
			8 => 90,
		);
		$flip   = array(
			2 => IMG_FLIP_HORIZONTAL,
			4 => IMG_FLIP_VERTICAL,
			5 => IMG_FLIP_HORIZONTAL,
			7 => IMG_FLIP_VERTICAL,
		);

		if ( isset( $rotate[ $orientation ] ) ) {
			$rotated = imagerotate( $src, $rotate[ $orientation ], 0 );
			if ( $rotated ) {
				$src = $rotated;
			}
		}

		if ( isset( $flip[ $orientation ] ) ) {
			imageflip( $src, $flip[ $orientation ] );
		}

		return $src;
	}

	/**
	 * Whether a PNG can carry transparency: an alpha colour type (4, 6), or a tRNS chunk
	 * (transparency for palette/greyscale/RGB images) before the image data.
	 *
	 * getimagesize() cannot answer this - it never reports 'channels' for PNGs - which
	 * silently sent every PNG down the Imagick path before 2.2.0.
	 */
	private static function png_has_alpha( $path ) {
		$head = file_get_contents( $path, false, null, 0, 65536 );

		if ( ! is_string( $head ) || strlen( $head ) < 33 || "\x89PNG\r\n\x1a\n" !== substr( $head, 0, 8 ) ) {
			return true; // Unreadable: Imagick copes with whatever it is.
		}

		$color_type = ord( $head[25] );

		if ( 4 === $color_type || 6 === $color_type ) {
			return true;
		}

		$offset = 8;
		$len    = strlen( $head );

		while ( $offset + 8 <= $len ) {
			$chunk_len  = unpack( 'N', substr( $head, $offset, 4 ) )[1];
			$chunk_type = substr( $head, $offset + 4, 4 );

			if ( 'tRNS' === $chunk_type ) {
				return true;
			}
			if ( 'IDAT' === $chunk_type || 'IEND' === $chunk_type ) {
				return false;
			}

			$offset += 12 + $chunk_len;
		}

		// Metadata before the image data exceeded what was read; assume the worst.
		return true;
	}

	private static function discard_stale( $path, $format ) {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$source_mtime = filemtime( $path );

		foreach ( array( $path . '.' . $format, $path . '.' . $format . self::SKIP_SUFFIX ) as $file ) {
			if ( file_exists( $file ) && filemtime( $file ) < $source_mtime ) {
				wp_delete_file( $file );
			}
		}
	}

	private static function write_skip_marker( $path, $format, $reason ) {
		$marker = $path . '.' . $format . self::SKIP_SUFFIX;
		$tmp    = $marker . self::TMP_INFIX;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny local marker file next to a media file this process manages; WP_Filesystem is not available in every context this runs in (uploads, cron, WP-CLI).
		if ( false === file_put_contents( $tmp, $reason ) ) {
			return;
		}

		self::match_source_permissions( $tmp, $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a file this process just wrote, within the same directory.
		if ( ! rename( $tmp, $marker ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Give a file the source's permission bits (minus any execute bit), and its owner and
	 * group when running as root. Whatever lets nginx read the original then lets it read
	 * the sibling - a hardcoded mode cannot promise that, and a root-owned 0640 file is
	 * unreadable to the nginx worker, so the image breaks for exactly the browsers that
	 * asked for the modern format.
	 */
	private static function match_source_permissions( $file, $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- copying the source media file's own mode onto its derivative.
		@chmod( $file, self::target_mode( $path ) );

		if ( self::running_as_root() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chown -- only as root (WP-CLI --allow-root), handing the derivative to the source file's owner.
			@chown( $file, fileowner( $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- same as above, for the group.
			@chgrp( $file, filegroup( $path ) );
		}
	}

	private static function target_mode( $path ) {
		$mode = fileperms( $path ) & 0666;

		return $mode ? $mode : 0644;
	}

	private static function running_as_root() {
		return function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
	}

	/**
	 * Record a failure, surface it, and return STATUS_FAILED.
	 */
	private static function fail( $path, $format, $reason ) {
		self::$last_error = $reason;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::debug( sprintf( '%s -> %s: %s', $path, $format, $reason ), 'beltoft-webp' );
		}

		/**
		 * Fires when a sibling could not be produced (as opposed to being produced and
		 * discarded for not being smaller). Hook it to log conversion problems, which are
		 * otherwise silent: the site just falls back to serving the original.
		 *
		 * @param string $path   Absolute path to the source file.
		 * @param string $format 'webp' or 'avif'.
		 * @param string $reason Human-readable reason.
		 */
		do_action( 'beltoft_webp_conversion_failed', $path, $format, $reason );

		return self::STATUS_FAILED;
	}
}
