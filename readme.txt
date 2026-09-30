=== Beltoft WebP ===
Contributors: internal
Tags: webp, avif, images, performance, nginx
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps .avif and .webp siblings next to every uploaded JPEG/PNG, so an nginx vhost can serve the best format each browser accepts.

== Description ==

Beltoft WebP converts every JPEG/PNG WordPress creates (the original, and
every generated thumbnail size) into `.avif` and `.webp` sibling files
sitting right next to it — `photo.jpg` gets `photo.jpg.avif` and
`photo.jpg.webp`. A `wp beltoft-webp backfill` command does the same for
existing media.

Fully works without any license key. A free key is optional and only
unlocks WordPress's built-in automatic-update flow — see Licensing below.

Delivery is handled entirely at the web-server level, not by this plugin:

	# http {} level. The defaults are suffixes that never exist, so a missing
	# or unaccepted format falls through to the next candidate — an empty
	# default would make try_files hit the original first and skip WebP.
	map $http_accept $bwebp_avif { default ".no-avif"; "~*image/avif" ".avif"; }
	map $http_accept $bwebp_webp { default ".no-webp"; "~*image/webp" ".webp"; }

	location ~* \.(png|jpe?g)$ {
	    add_header Vary Accept;
	    try_files $uri$bwebp_avif $uri$bwebp_webp $uri =404;
	}

A request for `photo.jpg` gets `photo.jpg.avif` when the browser accepts
AVIF and that sibling exists, otherwise `photo.jpg.webp` when it accepts
WebP, otherwise the plain JPEG — no markup change, no `<picture>` element,
nothing for CSS or other plugins to trip over, and `Vary: Accept` keeps
shared caches honest. A WebP-only vhost (just the second map and
`try_files $uri$bwebp_webp $uri`) works too; AVIF siblings then simply go
unused, so turn AVIF off in settings rather than spend CPU on it.

= Why a plugin, not theme code =

Image delivery is not presentation — it has to keep working across a theme
change. If this lived in the child theme, switching themes would silently
stop generating WebP for new photos while existing files kept being
served, so the regression would be invisible: nothing breaks, the site
just gets slower again.

= Why not a third-party WebP plugin =

The delivery half (the nginx config above) was already solved before this
existed. Third-party WebP plugins generally want to install their own
rewrite rules, which would duplicate or contend with that. All that was
actually missing was the files — hence the append-only naming, which is
exactly what `try_files` looks for.

= Quality defaults =

Both quality settings are configurable (Settings > Beltoft WebP), but the
defaults are measured, not guessed, against this server's specific image
library build (ImageMagick 6.9.12 + GD):

* **WebP (default 85):** this build's ImageMagick WebP delegate ignores
  the quality setting entirely, wand-level and image-level alike, so
  conversion goes through GD's `imagewebp()` instead, which does honor it.
  85 measured a 3 dB PSNR gain over the encoder's silent default (33.6 dB).
* **AVIF (default 70):** chosen from a measured quality ladder (PSNR
  against the source JPEG) and deliberately matched against the WebP
  output — at quality 65 the AVIF measured *below* its own WebP fallback
  on this build's encoder, meaning the better-equipped browser would have
  gotten the worse picture. It must never be the weaker of the two.
  Higher isn't free either: at quality 75 a real front-page hero encoded
  larger than its WebP sibling, so every AVIF-capable browser would have
  downloaded *more* bytes for choosing the better format.

A sibling that isn't smaller than its source is discarded automatically
(both at conversion time and again when AVIF turns out larger than WebP),
so a bad quality setting degrades to "no sibling" rather than a slower
site.

Backfill on this install measured 350 files, 41MB -> 18.8MB, taking
throttled front-page LCP from 12.6s to 5.0s and the shop from 4.4s to 1.7s.

= Integration with other plugins =

Hooks for anything that wants to know when sibling files change, without
a hard dependency on this plugin:

* `beltoft_webp_file_converted( $path )` — after a sibling was actually
  (re)written, from upload or backfill; not when everything was current.
* `beltoft_webp_backfill_complete` — once, after a `wp beltoft-webp backfill`
  run that wrote at least one sibling. Backfill walks the filesystem
  directly and never fires WordPress's attachment hooks, so a plugin that
  only detects new sibling files via `wp_generate_attachment_metadata`
  (as beltoft-media-offload does) would otherwise never learn that
  backfill created files for attachments it already considers done.
  beltoft-media-offload listens for this and re-syncs.
* `beltoft_webp_conversion_failed( $path, $format, $reason )` — when a
  sibling could not be produced at all (unreadable source, encoder error),
  as opposed to produced and discarded for not being smaller. Conversion
  failures are otherwise silent: the site just keeps serving the original.
  In WP-CLI they are also printed as warnings by backfill, and every
  decision is logged with `--debug=beltoft-webp`.
* Filter `beltoft_webp_backfill_excluded_dirs` — top-level `uploads/`
  directories backfill never enters. Default: `woocommerce_uploads`,
  `wc-logs`, `edd`, `gravity_forms`, `wpforms` (protected downloads, logs,
  form submissions); hidden directories are always skipped.

= Files, permissions and skip markers =

* Each sibling is encoded to a temporary file and renamed into place, so
  nginx never serves a half-written image, and a sibling this process
  can't write to (e.g. root-owned) is still replaced.
* A sibling gets its source file's permission bits and, when running as
  root (`wp --allow-root`), its owner and group. Whatever lets nginx read
  the original then lets it read the sibling. Before 2.2.0 siblings were
  hardcoded to 0640 and a root backfill left them root-owned, unreadable
  to an nginx worker that could read the originals; backfill now repairs
  such files on its next run.
* When a sibling is deliberately not kept (not smaller than its source,
  or an AVIF not smaller than its WebP), a tiny marker file
  `photo.jpg.avif.skip` records that for this version of the source, so
  backfill doesn't re-encode it on every run. Markers are removed with
  their source and ignored by `--force`.
* JPEG EXIF orientation is applied to the pixels before the metadata is
  stripped, so siblings of camera originals aren't shown sideways.

= Background conversion =

Outside WP-CLI, an upload (or thumbnail regeneration) only marks the
attachment pending and queues a job: Action Scheduler when it's loaded
(WooCommerce ships it), WP-Cron otherwise. The upload request no longer
waits for AVIF/WebP encoding (measured on a 2400×1600 photo: 16.6s inline,
5.9s queued), and until the job runs nginx simply serves the originals.
Switch it off under Settings > Beltoft WebP > Background conversion
to convert inline as before.

* WP-CLI (`wp media regenerate`, `wp media import`, backfill) always
  converts inline.
* With beltoft-media-offload older than 1.6.0 it also stays inline: those
  versions delete local files straight after offloading, which would
  leave the job nothing to convert.
* A job that never runs (stalled queue, WP-Cron not firing on a quiet
  site) is picked up by the next `wp beltoft-webp backfill`, which runs
  every pending attachment first. On low-traffic sites, a real system
  cron calling WP-Cron keeps the delay short.
* Deactivating the plugin drops queued jobs and pending marks.

Hooks for other plugins (the beltoft-media-offload 1.6.0 contract):

* Filter `beltoft_webp_conversion_pending( $pending, $attachment_id )` —
  true from the moment the upload hook (priority 10) queues the
  attachment until its job ends, including while queued but not started.
* Action `beltoft_webp_attachment_conversion_finished( $attachment_id,
  $success )` — when the job ends, successful or not, after the pending
  mark is cleared. beltoft-media-offload uploads the new siblings and
  completes the local delete it held back.
* Filter `beltoft_webp_use_action_scheduler` — force WP-Cron by returning
  false.

= Licensing =

Conversion, the backfill command, the Settings page, and everything else
work fully without a license — a license is entirely optional here.

The one thing it unlocks is WordPress's built-in plugin update flow
(new-version notices, one-click update, "View details" in the Plugins
screen), via a self-hosted updater checking beltoft.net — the same
mechanism this codebase's other Beltoft plugins use. It's a free ($0)
key: activating one sends the key and this site's domain to
`https://beltoft.net` (this codebase's license server); a daily cron
check re-validates it the same way. No key is included by default —
request one and enter it under Settings > Beltoft WebP if you want
automatic updates; without one, everything still works, you'd just
update the plugin manually.

Self-hosted updaters are against WordPress.org's plugin directory rules,
so `wp plugin check` flags `plugin_updater_detected` here; that's expected
and accepted for a plugin that's never distributed through wp.org, same
as this codebase's other Pro plugins.

== Installation ==

1. Upload the plugin to `wp-content/plugins/beltoft-webp`.
2. Activate it through the Plugins screen.
3. Configure Settings > Beltoft WebP if the defaults don't suit your
   server's image library build.
4. Run `wp beltoft-webp backfill` to convert existing media.
5. Configure your web server to serve the siblings (see Description) — the AVIF-first snippet there.

== Frequently Asked Questions ==

= Does this create a &lt;picture&gt; element or change any markup? =

No — delivery is handled by the web server's Accept-header negotiation,
not by this plugin. Nothing in the page's HTML changes.

= What happens if I disable WebP or AVIF in settings? =

New conversions skip that format. Existing sibling files are left alone while they're
current, and keep being served until the source image changes (a stale
one is then removed, so an outdated picture is never served) or is deleted
(which removes all of its siblings, whichever formats they're in).

= I used another WebP converter before (e.g. EWWW Image Optimizer). Do its files still work? =

Any `photo.jpg.webp` it made uses the same append
naming, so nginx keeps serving it and this plugin treats it as current
(it only rebuilds a sibling older than its source), repairs its
permissions to match the source, and deletes it along with the source.
Run `wp beltoft-webp backfill --force` if you'd rather re-encode them at
this plugin's measured quality. Files named by replacing the extension
(`photo.webp`) aren't seen by this plugin or by the nginx chain above.

= I changed a quality setting — do existing images update? =

Not automatically. Run `wp beltoft-webp backfill --force` to rebuild every
sibling at the new quality.

= Do I need a license key? =

No — the plugin fully works without one. A free key only enables
WordPress's built-in automatic-update flow; skip it if you're fine
updating manually.

== Changelog ==

= 2.3.0 =
* Added background conversion (on by default, Settings > Beltoft WebP): uploads and thumbnail regenerations are queued with Action Scheduler, or WP-Cron without it, instead of encoding every size during the upload request. WP-CLI stays synchronous, and so does any site running beltoft-media-offload older than 1.6.0.
* Added the `beltoft_webp_conversion_pending` filter answer and the `beltoft_webp_attachment_conversion_finished` action, so beltoft-media-offload 1.6.0+ holds back its "delete local files" until the siblings exist.
* `wp beltoft-webp backfill` first runs any background jobs that never ran.
* Fixed: backfill repaired permissions only on siblings of enabled formats, while nginx still serves an existing sibling of a disabled format. It now repairs every format.

= 2.2.0 =
* Fixed: siblings were hardcoded to mode 0640, and a `wp --allow-root` backfill left them root-owned, so an nginx worker that can read the originals could get a 403 for every AVIF/WebP-accepting browser, and PHP could never overwrite them (a regenerated thumbnail kept serving its old sibling). Siblings now copy their source's permissions (and owner/group when running as root), are written to a temp file and renamed into place, and backfill repairs existing ones.
* Fixed: PNGs never took the GD WebP path, because `getimagesize()` never reports channels for PNGs — every PNG went through Imagick, whose WebP ignores quality on this build. Transparency is now read from the PNG header; palette PNGs are converted to truecolor for GD.
* Fixed: a sibling rejected for not being smaller (or an AVIF not smaller than its WebP) looked stale forever, so every backfill re-encoded it, counted it as converted, and re-fired `beltoft_webp_file_converted` and `beltoft_webp_backfill_complete` (making beltoft-media-offload re-sync every run). A `.skip` marker now records the rejection, and both hooks only fire when a sibling was actually written.
* Fixed: the documented nginx snippet only served WebP, so AVIF siblings were generated but never served. The readme now has a tested AVIF-first chain.
* Fixed: reactivating the plugin re-activated a license the admin had deliberately deactivated; plugin deactivation no longer calls the license server when no license is active.
* Fixed: WebP conversion required Imagick even though it goes through GD.
* Fixed: JPEG EXIF orientation was stripped without being applied, so siblings of camera originals could show sideways.
* Fixed: a stale sibling (source changed but not reconverted — format disabled, conversion switched off, or encoding failed) kept being served as the old picture; it is now removed.
* Backfill skips protected/non-media upload directories (filter `beltoft_webp_backfill_excluded_dirs`) and hidden ones, and reports failures separately from "not smaller".
* Added `beltoft_webp_conversion_failed` action; failures are printed by backfill and logged under `--debug=beltoft-webp`.
* Corrected the WebP quality description on the Settings page and a code comment that contradicted the GD path.

= 2.1.0 =
* Added an optional, free ($0) license key (Settings > Beltoft WebP), activated against beltoft.net — the same self-hosted mechanism as this codebase's other Beltoft plugins. It only gates WordPress's built-in automatic-update flow; conversion, the backfill command, and everything else work fully without one, and no admin notice nags anyone who hasn't entered a key.
* Fixed: `wp beltoft-webp backfill` checked staleness (and reported byte savings) against every format this plugin can produce, instead of only the formats actually enabled in settings. Disabling a format on the new Settings page (added in 2.0.0) made every file look permanently stale on every future backfill run, re-converting the entire media library each time and firing the integration hooks needlessly. Corrects the 2.0.0 changelog's claim of "no functional change to the conversion logic itself" — this bug was introduced by that restructuring.

= 2.0.0 =
* Restructured from a single file into a proper plugin (src/ classes, Settings page, uninstall.php) — no functional change to the conversion logic itself.
* Quality (WebP, AVIF) and which formats to generate are now configurable under Settings > Beltoft WebP, instead of hardcoded constants. Defaults unchanged from the measured values.
* Added an Enabled toggle for upload-time/regeneration conversion (the WP-CLI backfill command still works regardless).
* Added `beltoft_webp_file_converted` and `beltoft_webp_backfill_complete` actions so other plugins (e.g. beltoft-media-offload) can react to new sibling files without a hard dependency on this plugin.

= 1.2.0 =
* Initial version (single-file plugin): upload-time/regeneration conversion, delete-cascade cleanup, WP-CLI backfill command.
