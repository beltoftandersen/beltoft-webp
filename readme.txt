=== Beltoft WebP ===
Contributors: internal
Tags: webp, avif, images, performance, nginx
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.1.0
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

	map $http_accept $webp_suffix { default ""; "~*webp" ".webp"; }
	location ~* ^.+\.(png|jpe?g|gif)$ {
		add_header Vary Accept;
		try_files $uri$webp_suffix $uri =404;
	}

A request for `photo.jpg` gets `photo.jpg.webp` when the browser's Accept
header lists `image/webp`, and falls through to the plain JPEG otherwise —
no markup change, no `<picture>` element, nothing for CSS or other plugins
to trip over, and `Vary: Accept` keeps shared caches honest.

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

Two actions are fired for anything that wants to know when sibling files
change, without a hard dependency on this plugin:

* `beltoft_webp_file_converted( $path )` — after any conversion, upload or
  backfill.
* `beltoft_webp_backfill_complete` — once, after a `wp beltoft-webp backfill`
  run that changed at least one file. Backfill walks the filesystem
  directly and never fires WordPress's attachment hooks, so a plugin that
  only detects new sibling files via `wp_generate_attachment_metadata`
  (as beltoft-media-offload does) would otherwise never learn that
  backfill created files for attachments it already considers done.
  beltoft-media-offload listens for this and re-syncs.

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
5. Configure your web server to serve the siblings (see Description).

== Frequently Asked Questions ==

= Does this create a &lt;picture&gt; element or change any markup? =

No — delivery is handled by the web server's Accept-header negotiation,
not by this plugin. Nothing in the page's HTML changes.

= What happens if I disable WebP or AVIF in settings? =

New conversions skip that format. Existing sibling files aren't touched or
removed — they keep being served until the source image is deleted (which
removes all of its siblings, whichever formats they're in) or you delete
them yourself.

= I changed a quality setting — do existing images update? =

Not automatically. Run `wp beltoft-webp backfill --force` to rebuild every
sibling at the new quality.

= Do I need a license key? =

No — the plugin fully works without one. A free key only enables
WordPress's built-in automatic-update flow; skip it if you're fine
updating manually.

== Changelog ==

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
