# SpeedKit

A self-contained caching and performance plugin for WordPress: disk page cache served by an `advanced-cache.php` drop-in (and optionally straight from Apache), automatic purge, Varnish purge, browser cache and gzip rules, HTML/CSS minify, JavaScript defer/delay, lazyload, YouTube facade, sitemap preload, link prefetch, CDN rewriting and database cleanup.

No external service, no license key, no telemetry.

- **Version:** 1.3.0
- **Requires:** WordPress 6.0+, PHP 7.4+
- **License:** GPL-2.0-or-later

## Features

### Page cache
- Pages are saved as static HTML files (plus a pre-compressed `.gz` copy) in `wp-content/cache/speed-kit/<host>/<path>/`.
- The `advanced-cache.php` drop-in serves them before plugins and theme are loaded, with `Last-Modified` / `304 Not Modified` support and the `X-SpeedKit-Cache: HIT` response header.
- Optional direct serving from Apache through `mod_rewrite` rules in `.htaccess` (PHP is not started on a cache hit).
- Variants for https and, optionally, mobile devices (only needed with non-responsive themes).
- Lifespan from 1 hour to 7 days, or "never" (automatic purge only); an hourly cron job deletes expired files.
- Every cached file ends with `<!-- Cached by SpeedKit <version> - <date> UTC -->`.

**Never cached:**
- Logged-in users and any request carrying one of these cookies: `wordpress_logged_in_`, `wordpress_sec_`, `wp-postpass_`, `comment_author_`, `woocommerce_cart_hash`, `woocommerce_items_in_cart`, `wp_woocommerce_session_`, `edd_items_in_cart`, plus any prefix you add in the settings or with the `skit_skip_cookies` filter.
- Non-GET requests (POST, PUT, ...).
- Any request with a query string. Pages whose query string contains only tracking parameters (`utm_*`, `fbclid`, `gclid`, ... the list is editable) receive the cached copy of the base URL, but are never saved.
- Previews, search results, 404s, feeds, password-protected posts, `robots.txt`, the Customizer preview, WooCommerce cart/checkout/account pages.
- `wp-admin`, `wp-login.php`, `wp-cron.php`, `xmlrpc.php`, the REST API, `.php`/`.xml`/`.txt` URLs, sitemaps.
- Responses sent with `Cache-Control: no-store`, `no-cache` or `private`, non-HTML responses, non-200 responses, pages that define `DONOTCACHEPAGE`.
- URIs matching your exclusion regexes.
- Requests whose `Host` header is not the site's host.

### Automatic purge
- Post published, updated, unpublished, trashed or deleted: the post URL (also the URL it had *before* an unpublish, trash or slug change), home page, post type archive, term archives, author archive, date archives and their pagination.
- Comment approved or unapproved: the post URL.
- Term edited or deleted: the term archive.
- Theme switch, Customizer save, permalink change, widgets, menus, plugin/theme/core updates: the whole site.
- Manual purge from the admin bar (whole site or current page) and from the settings page.

### Varnish purge
When content changes, the affected URLs are purged from Varnish too. See [Hosting with Varnish (Cloudways)](#hosting-with-varnish-cloudways).

### Preload
- After a full purge the cache is rebuilt in the background from the sitemap (`sitemap_index.xml`, `sitemap.xml`, `wp-sitemap.xml`, or a custom URL), in batches run by WP-Cron.
- After a post purge, the first purged URLs (post and home page) are requested again right away.

### HTML and asset optimizations
Applied only to the HTML that gets cached (anonymous visitors):
- HTML minify (keeps `pre`, `textarea`, `script`, `style` intact).
- JavaScript `defer` for external scripts, with regex exclusions (jQuery is never deferred). Like WordPress core, scripts followed by an "after" inline script (`wp_add_inline_script()`) and excluded scripts stay blocking together with their dependencies.
- JavaScript delay until the first user interaction (opt-in), with exclusions.
- `loading="lazy"` for iframes.
- `loading="lazy"` and `decoding="async"` for images, skipping the first N images, images with `fetchpriority="high"` and images with the `skip-lazy` class.
- YouTube facade (opt-in): the iframe is replaced by its thumbnail and the player is loaded from `youtube-nocookie.com` only on click.
- Local CSS minify with on-disk cache and automatic cache busting (`.min.css` files are skipped).
- Google Fonts `display=swap` and a preconnect to `fonts.gstatic.com`.
- `<link rel="preload">` for critical fonts, `dns-prefetch` hints.
- Removal of the WordPress emoji script.
- Prefetch of internal links on hover (skips links with a query string, `rel="nofollow"`, `download` or `data-no-prefetch`).
- CDN rewriting of static asset URLs under `/wp-content/` and `/wp-includes/`.

### Server rules (.htaccess)
Written between `# BEGIN SpeedKit` and `# END SpeedKit` at the top of `.htaccess`: charset, no ETag, `mod_deflate` compression, `mod_expires` / `Cache-Control` headers for static assets, and the optional direct-serve rewrite rules. Each block can be switched off.

### Database cleanup
Revisions, auto-drafts older than 7 days, trashed posts, spam/trashed comments and expired transients. Manual (requires ticking a confirmation checkbox) or weekly (opt-in).

### Minor tweaks
- Comment cookies last 3 minutes, so commenters quickly get cached pages again.
- The Heartbeat API interval is set to 60 seconds.

## Requirements
- WordPress 6.0 or later, PHP 7.4 or later.
- Write access to `wp-content/` (drop-in, cache and config folders) and, for the full setup, to `wp-config.php` and `.htaccess`.
- Direct serving without PHP requires Apache (or another server that reads `.htaccess` and supports `mod_rewrite`). With Nginx alone, cached pages are served by the PHP drop-in.
- Single-site installs. Multisite is not supported.

## Installation
1. **Deactivate any other page cache plugin first** (see [Incompatible plugins](#incompatible-plugins)) and make sure `wp-content/advanced-cache.php` does not belong to another plugin.
2. Copy the `speed-kit` folder to `wp-content/plugins/` (or upload a zip of it from *Plugins → Add New → Upload*).
3. Activate SpeedKit. Activation:
   - copies `includes/advanced-cache.php` to `wp-content/advanced-cache.php`;
   - adds `define( 'WP_CACHE', true ); // Added by SpeedKit` to `wp-config.php` (unless `WP_CACHE` is already `true`);
   - writes the `# BEGIN SpeedKit` block to `.htaccess`;
   - writes `wp-content/speed-kit-config/<host>.php` and creates `wp-content/cache/speed-kit/`.
4. Open *Settings → SpeedKit*. If the drop-in is reported as "NOT active", use **Reinstall components**.
5. Check from a private window: the page source must end with `<!-- Cached by SpeedKit ... -->` and, from the second visit, the response must carry `X-SpeedKit-Cache: HIT` (or be served by Apache directly).

### Deactivation and uninstall
- **Deactivation** removes the drop-in (only if it is SpeedKit's), removes the `WP_CACHE` line added by SpeedKit (restoring the previous `WP_CACHE` line if SpeedKit had replaced one), removes the `.htaccess` block, deletes the config files, the cache and the cron events. Settings are kept.
- **Uninstall** (delete from the Plugins screen) does the same and also deletes all options and transients.

`wp-config.php` and `.htaccess` are written in place, read back and restored to their previous content if the result does not match.

## Configuration
Everything is in *Settings → SpeedKit*:

| Section | Settings |
| --- | --- |
| Page cache | enable, lifespan, separate mobile cache, excluded URIs (regex), ignored query parameters, cookies that bypass the cache |
| Server rules | direct serving, browser cache, gzip |
| Optimizations | minify HTML, defer JS + exclusions, delay JS + exclusions, lazyload iframes, DNS prefetch |
| Media | lazyload images + number of images to skip, YouTube facade |
| Static assets | minify CSS + exclusions, Google Fonts swap, preload fonts, emoji, preload links |
| CDN | URL rewriting and CDN host |
| Reverse proxy | Varnish purge mode |
| Database | weekly automatic cleanup |
| Preload | automatic preload, sitemap URL |

Saving the settings regenerates the config file and the `.htaccess` block and purges the whole cache.

### Constants (in `wp-config.php`)
| Constant | Effect |
| --- | --- |
| `SKIT_CACHE_DIR` | Cache directory (default `wp-content/cache/speed-kit/`). Direct serving and CSS minify need it inside `wp-content`. Define it in `wp-config.php` so the drop-in sees it too. |
| `SKIT_CONFIG_DIR` | Config directory (default `wp-content/speed-kit-config/`). Same note as above. |
| `SKIT_NO_VARNISH` | `true` disables every Varnish purge request. |
| `SKIT_VARNISH_IP` | IP (or `ip:port`) that receives the purge requests instead of the default target. |
| `DONOTCACHEPAGE` | Standard WordPress constant: the current page is not cached. |

## Hosting with Varnish (Cloudways)
SpeedKit's disk cache works behind Varnish: Varnish answers the requests it has cached, SpeedKit serves the rest. What matters is that Varnish is purged when content changes. The *Varnish purge* setting has four modes:

- **Automatic** (default): uses the proxy detected from the headers of your own admin requests: `X-Varnish` means Varnish, `X-Application` (other than `varnishpass` or `bypass`) means Cloudways. Nothing is sent until a Varnish has been detected. Headers of anonymous requests are never used, because they can be forged.
- **Cloudways**: the same requests sent by Breeze, the Cloudways plugin: `URLPURGE` for a single URL and `PURGE /.*` for the whole site, sent to `127.0.0.1` (or `SKIT_VARNISH_IP`) with the site's `Host` header, so the request never goes through a CDN. Paginated archive pages are not purged individually in this mode and expire with Varnish's own TTL.
- **Generic Varnish**: the Proxy Cache Purge convention: `PURGE` with `X-Purge-Method: default` for one URL and `X-Purge-Method: regex` for pagination (`<url>/page/.*`) and the whole site (`/.*`), sent to the site host or to `SKIT_VARNISH_IP`. Your VCL must accept these requests.
- **Off**.

Breeze is a page cache plugin as well, so deactivate it when you use SpeedKit; in the Cloudways mode SpeedKit sends the same kind of Varnish purge requests (`URLPURGE` / `PURGE`).

## Incompatible plugins
Only one page cache can be active. Deactivate these before activating SpeedKit; SpeedKit shows an admin notice when one of them is active:

WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, Breeze, Cache Enabler, Comet Cache, Hummingbird, Speed Optimizer (SiteGround), WP-Optimize, Swift Performance Lite.

SpeedKit never overwrites an `advanced-cache.php` that belongs to another plugin. If WP Rocket is active during activation, SpeedKit installs nothing and asks you to deactivate it first.

Plugins that only optimize images, or that minify/combine CSS and JS, may overlap with SpeedKit's optimizations: switch off the duplicated feature in one of them.

## Cookie consent and other personalized content
A cached page is the same HTML for every anonymous visitor. Consent plugins that decide in the browser which scripts to run work as they are. If your consent banner (or any other feature) changes the HTML on the server depending on a cookie, add that cookie name to **Cookies that bypass the cache** (or use the `skit_skip_cookies` filter): visitors carrying it will neither receive nor generate cached pages.

## WP-CLI
SpeedKit does not register its own WP-CLI commands. `wp plugin activate speed-kit`, `wp plugin deactivate speed-kit` and `wp plugin uninstall speed-kit` run the same activation, deactivation and uninstall routines as the admin.

## Hooks

### Filters
| Filter | Arguments | Purpose |
| --- | --- | --- |
| `skit_do_cache_page` | `bool $cacheable` | Return `false` to stop the current page from being cached. |
| `skit_buffer` | `string $html` | The HTML about to be saved (all optimizations run on this filter). |
| `skit_skip_cookies` | `string[] $cookies` | Cookie name prefixes that bypass the cache. |
| `skit_clean_post_urls` | `string[] $urls, WP_Post $post` | URLs purged when a post changes. |
| `skit_varnish_enabled` | `bool $enabled` | Return `false` to send no Varnish purge; `true` forces the generic mode when the setting is off. |
| `skit_varnish_purge_request` | `array $request, string $url, string $scope, string $mode` | Change (or cancel with an empty `url`) a Varnish purge request. |
| `skit_preload_sitemaps` | `string[] $sitemaps` | Sitemaps the preload reads. |
| `skit_preload_urls` | `string[] $urls` | URLs queued for the preload. |
| `skit_preload_batch_size` | `int $size` | URLs per preload cron run (default 10). |
| `skit_exclude_defer_js` | `string[] $patterns` | Regexes of script URLs excluded from defer. |
| `skit_delay_js_exclusions` | `string[] $strings` | Strings that exclude a script tag from the delay. |
| `skit_exclude_minify_css` | `string[] $patterns` | Regexes of CSS URLs excluded from minify. |
| `skit_lazyload_exclusions` | `string[] $strings` | Strings that exclude an `img` tag from lazyload. |
| `skit_conflicting_plugins` | `array $plugins` | Plugin basenames treated as conflicting page caches. |

### Actions
| Action | Arguments | Fires |
| --- | --- | --- |
| `skit_after_clean_domain` | none | After a full purge. |
| `skit_after_clean_post` | `WP_Post $post, string[] $urls` | After a post purge. |
| `skit_after_db_cleanup` | `array $results` | After a database cleanup; keys: `revisions`, `auto_drafts`, `trashed_posts`, `spam_comments`, `expired_transients`. |

### Markup flags
- `data-no-delay` on a `<script>` tag: never delayed.
- `skip-lazy` class or `data-skip-lazy` attribute on an image: never lazyloaded.
- `data-no-prefetch` on a link: never prefetched.

## Outgoing requests
SpeedKit has no telemetry and calls no third-party API. It sends HTTP requests only to:
- your own site: preload requests and, in the generic Varnish mode, purge requests;
- `127.0.0.1` (or `SKIT_VARNISH_IP`): purge requests in the Cloudways mode;
- the sitemap URL you configure, if it is on another host (only URLs of your own host are then preloaded).

Visitors' browsers load the YouTube thumbnail from `i.ytimg.com` when the YouTube facade is on, and the player from `youtube-nocookie.com` after a click.

## FAQ

**How do I check that a page is cached?**
Open it in a private window twice. The page source ends with `<!-- Cached by SpeedKit ... -->` and the second response carries `X-SpeedKit-Cache: HIT`. When Apache serves the file directly the header is missing, but the HTML comment is there.

**How do I exclude a page from the cache?**
Add a regex to *Excluded URIs*, define `DONOTCACHEPAGE` in the template, or return `false` from the `skit_do_cache_page` filter.

**How do I exclude a script from defer?**
Add a regex matching its URL to the defer exclusions (for example `/wp-content/plugins/my-plugin/(.*).js`) or use the `skit_exclude_defer_js` filter.

**A page breaks with "Delay JavaScript".**
Add a string that identifies the script tag (its file name, id or a word in its attributes) to the delay exclusions, or add `data-no-delay` to the tag.

**Does it work with Nginx?**
Yes, through the PHP drop-in. Direct serving without PHP needs Apache reading `.htaccess`; with Nginx alone you can switch off *Direct serving*.

**I changed the settings but the old version of a page is still served.**
Saving the settings purges the whole disk cache. If a reverse proxy or CDN sits in front of the site, check the Varnish purge mode or purge the CDN.

**What changes when I update from 1.2.0?**
See [CHANGELOG.md](CHANGELOG.md). On the first admin page load after the update SpeedKit refreshes the drop-in, the config file and the `.htaccess` block.

## License
SpeedKit is free software released under the GNU General Public License, version 2 or (at your option) any later version. See [LICENSE](LICENSE).
