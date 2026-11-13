# Changelog

All notable changes to SpeedKit. Versions follow [Semantic Versioning](https://semver.org/).

## [1.3.0] - Unreleased

### Security and correctness
- The URL a post had before being unpublished, trashed or renamed is now purged; before, the retracted page kept being served from the cache.
- Term archives are purged before a term is deleted (afterwards the link no longer resolves).
- Cache files are written only for requests whose `Host` header matches the site; hosts that are empty, start with a dot or contain `..` are rejected when building cache paths.
- Responses sent with `Cache-Control: no-store`, `no-cache` or `private` are not cached.
- `wp-config.php`: the `WP_CACHE` line is always inserted on its own line (on a one-line `wp-config.php` the first statement used to be turned into a comment), an existing `WP_CACHE = true` is left alone, the original line is restored on deactivation, the result is checked and rolled back on mismatch.
- `.htaccess`: no rewrite when nothing changes, writability check, read-back check with rollback.
- The drop-in and the per-host config file are written atomically and invalidated in OPcache.
- Database cleanup removes only auto-drafts older than 7 days (a fresh auto-draft is a post being written); the manual cleanup is a POST request that requires a confirmation checkbox.
- Link prefetch skips URLs with a query string (for example `?add-to-cart=`) and any `rel` containing `nofollow`.
- CSS minify no longer removes the spaces around `+` (which broke `calc()`) or before `:` (which turned `.a :hover` into `.a:hover`).
- HTML minify returns the original HTML if a regular expression fails on a very large page.

### Varnish
- New *Varnish purge* setting: Automatic (default), Cloudways, Generic, Off.
- Cloudways mode sends `URLPURGE` for single URLs and `PURGE /.*` for the whole site to `127.0.0.1`, like Breeze. Version 1.2.0 sent `PURGE` for every URL, which on Cloudways flushes the whole application cache, and sent it to the public host, where a CDN could intercept it.
- Generic mode keeps the 1.2.0 request format (`PURGE` + `X-Purge-Method`), but pagination is purged with a separate `<url>/page/.*` regex instead of a regex on the URL itself (on the home page that regex matched the whole site).
- Automatic mode detects Varnish from the headers of administrator requests and sends nothing when no Varnish was seen.
- New `SKIT_VARNISH_IP` constant and `skit_varnish_purge_request` filter.

### Added
- *Cookies that bypass the cache* setting and `skit_skip_cookies` filter (applied to the drop-in, `.htaccess` rules and page cache).
- Admin notice when another known page cache plugin is active, filterable with `skit_conflicting_plugins`.
- One-off upgrade routine: after an update, the first admin page load refreshes the drop-in, the config file and the `.htaccess` block.
- English source strings with the `speed-kit` text domain, Italian translation (`it_IT`) and `speed-kit.pot`.
- Images with `fetchpriority="high"` are never lazyloaded.
- `README.md`, `CHANGELOG.md`, `LICENSE`.

### Changed
- `skit_after_db_cleanup` results use the keys `revisions`, `auto_drafts`, `trashed_posts`, `spam_comments`, `expired_transients` (they were Italian labels).
- The YouTube facade replaces the button with a wrapper when the player loads (the iframe was nested inside the `<button>`).
- Direct-serve rules and minified CSS URLs follow a custom `SKIT_CACHE_DIR` (both are disabled when it is outside `wp-content`).
- The weekly cleanup event is scheduled on `init`.
- Plugin URI and author metadata.

### Upgrading from 1.2.0
- Settings are kept; the new settings start from their defaults.
- On the first admin page load the drop-in, `wp-content/speed-kit-config/<host>.php` and the `.htaccess` block are rewritten. The `.htaccess` block comments are now in English.
- Varnish purge now defaults to *Automatic*: on a server without Varnish nothing is sent any more. If you run Varnish outside Cloudways and the purge stops working, choose *Generic Varnish*.
- The `define( 'WP_CACHE', false ); // Added by SpeedKit` line that 1.2.0 left in `wp-config.php` after a deactivation is replaced on activation and removed on deactivation.
- Code listening to `skit_after_db_cleanup` must use the new result keys.

## [1.2.0]
- Varnish purge: `PURGE` requests with `X-Purge-Method` on every cache purge, can be disabled with `SKIT_NO_VARNISH` or the `skit_varnish_enabled` filter.
- The drop-in is updated automatically from the admin when its version differs from the plugin.

## [1.1.0]
- Advanced image lazyload, YouTube facade, local CSS minify, Google Fonts swap/preconnect, font preload, link preload on hover, CDN, database cleanup, emoji removal.

## [1.0.0]
- First version: page cache, automatic purge, `.htaccess` rules, HTML minify, JS defer/delay, iframe lazyload, dns-prefetch, sitemap preload.
