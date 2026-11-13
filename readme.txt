=== SpeedKit ===
Tags: cache, page cache, performance, varnish, lazyload
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disk page cache with advanced-cache drop-in, automatic and Varnish purge, browser cache rules, minify, defer/delay JS, lazyload, preload.

== Description ==

SpeedKit is a self-contained caching and performance plugin: no external service, no license key, no telemetry.

* **Disk page cache** served by an `advanced-cache.php` drop-in before plugins and theme load, with https and optional mobile variants, pre-compressed gzip copies and 304 Not Modified support.
* **Direct serving from Apache** through `mod_rewrite` rules in `.htaccess`: on a cache hit PHP is not started.
* **Never cached:** logged-in users, cart/session/comment/password cookies (plus any cookie you add), POST requests, query strings, previews, search, 404s, feeds, WooCommerce cart/checkout/account, `Cache-Control: no-store/no-cache/private` responses.
* **Automatic purge** on post, comment and term changes (including the old URL of unpublished, trashed or renamed posts), theme, menu, widget and permalink changes. Manual purge from the admin bar.
* **Varnish purge** with a Cloudways mode (`URLPURGE` / `PURGE` to 127.0.0.1) and a generic mode (`PURGE` + `X-Purge-Method`), detected automatically.
* **Preload** from the sitemap after a full purge, in background batches.
* **.htaccess rules:** browser caching, gzip, no ETag.
* **Optimizations:** HTML minify, JavaScript defer and delay with exclusions, iframe and image lazyload (LCP images are skipped), YouTube facade, local CSS minify, Google Fonts `display=swap`, font preload, dns-prefetch, emoji script removal, link prefetch on hover, CDN rewriting.
* **Database cleanup:** revisions, old auto-drafts, trash, spam, expired transients; manual with confirmation, or weekly.

Full documentation, hooks and constants: https://github.com/domenicosacchi/speed-kit

== Installation ==

1. Deactivate any other page cache plugin (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, Breeze, Cache Enabler, Comet Cache, Hummingbird, Speed Optimizer, WP-Optimize, Swift Performance Lite).
2. Upload the `speed-kit` folder to `/wp-content/plugins/` and activate the plugin. Activation installs the drop-in, adds `WP_CACHE` to `wp-config.php`, writes the `.htaccess` rules and the config file.
3. Open Settings > SpeedKit. If the drop-in is "NOT active", click "Reinstall components".
4. From a private window, the page source must end with `<!-- Cached by SpeedKit ... -->` and the second visit must return the `X-SpeedKit-Cache: HIT` header (or be served directly by Apache).

Deactivation removes the drop-in, the `WP_CACHE` line added by SpeedKit, the `.htaccess` block, the config files and the cache. Uninstalling also deletes the settings.

== Frequently Asked Questions ==

= How do I exclude a page from the cache? =

Add a regex to "Excluded URIs", define `DONOTCACHEPAGE` in the template, or return `false` from the `skit_do_cache_page` filter.

= How do I exclude a script from defer? =

Add a regex matching its URL to the defer exclusions (for example `/wp-content/plugins/my-plugin/(.*).js`) or use the `skit_exclude_defer_js` filter.

= Does it work with Nginx? =

Yes, through the PHP drop-in. Direct serving without PHP requires Apache reading `.htaccess`.

= Does it work on Cloudways? =

Yes. Set "Varnish purge" to Automatic or Cloudways: content changes are purged from Varnish with the same kind of requests Breeze sends. Deactivate Breeze, which is a page cache too.

= My cookie consent banner is rendered on the server. =

Add its cookie to "Cookies that bypass the cache": visitors carrying it neither receive nor generate cached pages. Banners that decide in the browser need nothing.

= Does SpeedKit have WP-CLI commands? =

No. `wp plugin activate`, `deactivate` and `uninstall` run the same routines as the admin.

== Changelog ==

= 1.3.0 =
* Purge the old URL of unpublished, trashed or renamed posts (they kept being served from the cache).
* Safer `wp-config.php` and `.htaccess` writes with verification and rollback; WP_CACHE line on its own line and restored on deactivation.
* Varnish purge rewritten: Cloudways mode, generic mode, automatic detection, no more whole-site flush on every update.
* Cache only requests for the site's own host; respect `Cache-Control: no-store/no-cache/private`.
* Configurable cookies that bypass the cache.
* Database cleanup keeps recent auto-drafts and requires confirmation.
* CSS minify keeps `calc()` and descendant selectors intact; link prefetch skips URLs with a query string.
* JavaScript defer keeps scripts with "after" inline code (and their dependencies) blocking, like WordPress core.
* English source strings, Italian translation.

= 1.2.0 =
* Varnish purge requests on every cache purge.
* Automatic drop-in update when its version differs from the plugin.

= 1.1.0 =
* Advanced image lazyload, YouTube facade, local CSS minify, Google Fonts swap/preconnect, font preload, link preload on hover, CDN, database cleanup, emoji removal.

= 1.0.0 =
* First version: page cache, automatic purge, .htaccess rules, HTML minify, JS defer/delay, iframe lazyload, dns-prefetch, sitemap preload.

== Upgrade Notice ==

= 1.3.0 =
Fixes stale pages after unpublishing or trashing a post and makes wp-config.php/.htaccess writes safer. Varnish purge now defaults to automatic detection.
