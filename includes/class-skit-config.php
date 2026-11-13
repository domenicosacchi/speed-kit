<?php
/**
 * Config — Genera il file di configurazione per host letto dal drop-in
 * advanced-cache.php (che gira prima del caricamento di opzioni e plugin).
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Config {

	/**
	 * Impostazioni di default del plugin.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Page cache.
			'cache_enabled'         => 1,
			'cache_mobile_separate' => 0,       // Cache separata per mobile (serve solo con temi non responsive).
			'cache_lifespan'        => 10 * HOUR_IN_SECONDS, // 0 = mai, altrimenti secondi.
			'exclude_uris'          => array(), // Regex (una per riga in admin) di URI esclusi.
			'ignored_query_params'  => array(), // Vuoto = usa i default del drop-in.
			'skip_cookies_extra'    => array(), // Extra cookie name prefixes that bypass the cache.

			// Regole .htaccess.
			'htaccess_browser_cache' => 1,      // Expires/Cache-Control per asset statici.
			'htaccess_gzip'          => 1,      // mod_deflate.
			'htaccess_direct_serve'  => 1,      // mod_rewrite: serve i file di cache senza PHP.

			// Ottimizzazioni HTML.
			'minify_html'           => 1,
			'defer_js'              => 1,
			'defer_js_exclude'      => array(), // Pattern (una per riga) esclusi dal defer.
			'delay_js'              => 0,       // Rinvia l'esecuzione JS alla prima interazione.
			'delay_js_exclude'      => array(),
			'lazyload_iframes'      => 1,
			'dns_prefetch'          => array(), // Host per dns-prefetch/preconnect.

			// Media.
			'lazyload_images'       => 1,
			'lazyload_skip_first'   => 2,       // Le prime N immagini (LCP) caricano subito.
			'youtube_facade'        => 0,       // Thumbnail al posto dell'iframe YouTube.

			// Asset statici.
			'minify_css'            => 1,
			'minify_css_exclude'    => array(),
			'font_display_swap'     => 1,       // Google Fonts: display=swap + preconnect.
			'preload_fonts'         => array(), // URL font critici da precaricare.
			'disable_emojis'        => 1,
			'preload_links'         => 1,       // Prefetch dei link interni al hover.

			// CDN.
			'cdn_enabled'           => 0,
			'cdn_host'              => '',

			// Database.
			'db_auto_cleanup'       => 0,       // Pulizia settimanale automatica.

			// Preload.
			'preload_enabled'       => 1,
			'preload_sitemap'       => '',      // Vuoto = /sitemap.xml e /sitemap_index.xml.
		);
	}

	/**
	 * Restituisce le impostazioni correnti unite ai default.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( SKIT_OPTION_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Scrive il file di configurazione per l'host corrente.
	 *
	 * @return bool
	 */
	public static function write_config_file() {
		$settings = self::get_settings();
		$host     = self::current_host();
		if ( '' === $host ) {
			return false;
		}

		if ( ! wp_mkdir_p( SKIT_CONFIG_DIR ) ) {
			return false;
		}

		// Sottoinsieme delle impostazioni necessarie al drop-in.
		$config = array(
			'cache_enabled'         => (int) $settings['cache_enabled'],
			'cache_mobile_separate' => (int) $settings['cache_mobile_separate'],
			'cache_lifespan'        => (int) $settings['cache_lifespan'],
			'exclude_uris'          => array_values( (array) $settings['exclude_uris'] ),
			'ignored_query_params'  => array_values( (array) $settings['ignored_query_params'] ),
			'skip_cookies'          => self::skip_cookies( $settings ),
		);

		$content  = "<?php\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n";
		$content .= "// SpeedKit config — generato automaticamente, non modificare a mano.\n";
		$content .= "// Rigenerato a ogni salvataggio delle impostazioni.\n";
		$content .= 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$file = SKIT_CONFIG_DIR . $host . '.php';

		// Atomic write (temp file + rename): the drop-in include()s this file
		// on every request and would hit a parse error on a half-written file.
		$tmp     = $file . '.' . uniqid( 'tmp', true );
		$written = false !== file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = $written && @rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::opcache_invalidate( $file );

		// Protegge la cartella da listing.
		$index = SKIT_CONFIG_DIR . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $written;
	}

	/**
	 * Cookie name prefixes that bypass the page cache (both when serving and
	 * when saving). Must stay in sync with the drop-in fallback list.
	 *
	 * @return string[]
	 */
	public static function default_skip_cookies() {
		return array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'edd_items_in_cart' );
	}

	/**
	 * Full list of cache-bypass cookie prefixes: defaults, the ones added in
	 * the settings and the `skit_skip_cookies` filter. Names are restricted
	 * to a safe character set because they are also written to .htaccess.
	 *
	 * @param array $settings Plugin settings.
	 * @return string[]
	 */
	public static function skip_cookies( array $settings ) {
		$extra   = isset( $settings['skip_cookies_extra'] ) ? (array) $settings['skip_cookies_extra'] : array();
		$cookies = array_merge( self::default_skip_cookies(), $extra );

		/**
		 * Filters the cookie name prefixes that bypass the page cache.
		 *
		 * Use it for consent or session cookies when the HTML differs for
		 * visitors that carry them (e.g. a consent banner rendered server-side).
		 *
		 * @param string[] $cookies Cookie name prefixes.
		 */
		$cookies = (array) apply_filters( 'skit_skip_cookies', $cookies );

		$clean = array();
		foreach ( $cookies as $cookie ) {
			$cookie = self::sanitize_cookie_name( $cookie );
			if ( '' !== $cookie ) {
				$clean[] = $cookie;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Keeps only characters that are safe both in a PHP prefix match and in
	 * an Apache regex.
	 *
	 * @param string $name Cookie name or prefix.
	 * @return string
	 */
	public static function sanitize_cookie_name( $name ) {
		return (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $name );
	}

	/**
	 * Writes a critical site file (wp-config.php, .htaccess) in place, then
	 * reads it back; on any mismatch the original content is written back.
	 * In-place writing (not temp + rename) keeps owner and permissions.
	 *
	 * @param string $file     Absolute path.
	 * @param string $content  New content.
	 * @param string $original Content to restore on failure.
	 * @return bool True if the new content is in place.
	 */
	public static function safe_write( $file, $content, $original ) {
		$bytes = @file_put_contents( $file, $content, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		clearstatcache( true, $file );
		$check = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( strlen( $content ) !== $bytes || $check !== $content ) {
			// Rollback.
			@file_put_contents( $file, $original, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			clearstatcache( true, $file );
			return false;
		}

		self::opcache_invalidate( $file );

		return true;
	}

	/**
	 * Invalidates a PHP file in OPcache so that changes are picked up even
	 * with opcache.validate_timestamps=0.
	 *
	 * @param string $file Absolute path.
	 */
	public static function opcache_invalidate( $file ) {
		// Silenced: opcache.restrict_api may forbid the call (it then warns).
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Public URL of the cache directory, or '' when SKIT_CACHE_DIR is not
	 * inside WP_CONTENT_DIR (then it cannot be served over HTTP).
	 *
	 * @return string
	 */
	public static function cache_url() {
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$cache   = trailingslashit( wp_normalize_path( SKIT_CACHE_DIR ) );
		if ( 0 !== strpos( $cache, $content ) ) {
			return '';
		}

		return content_url( substr( $cache, strlen( $content ) ) );
	}

	/**
	 * Elimina i file di configurazione (disattivazione/uninstall).
	 */
	public static function delete_config_files() {
		if ( ! is_dir( SKIT_CONFIG_DIR ) ) {
			return;
		}
		foreach ( (array) glob( SKIT_CONFIG_DIR . '*.php' ) as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@rmdir( SKIT_CONFIG_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Host corrente normalizzato come nel drop-in.
	 *
	 * @return string
	 */
	public static function current_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		if ( ! $host ) {
			return '';
		}
		$host = strtolower( $host );
		if ( $port ) {
			$host .= '-' . $port;
		}

		return preg_replace( '/[^a-z0-9.\-]/', '', $host );
	}
}
