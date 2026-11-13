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
		);

		$content  = "<?php\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n";
		$content .= "// SpeedKit config — generato automaticamente, non modificare a mano.\n";
		$content .= "// Rigenerato a ogni salvataggio delle impostazioni.\n";
		$content .= 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$file = SKIT_CONFIG_DIR . $host . '.php';

		$written = (bool) file_put_contents( $file, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Protegge la cartella da listing.
		$index = SKIT_CONFIG_DIR . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $written;
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
