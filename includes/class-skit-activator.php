<?php
/**
 * Activator — Installazione e rimozione pulita di tutte le parti "invasive":
 * drop-in advanced-cache.php, costante WP_CACHE in wp-config.php, regole
 * .htaccess, file di configurazione, eventi cron.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Activator {

	/**
	 * Attivazione plugin.
	 */
	public static function activate() {
		// Con WP Rocket ancora attivo non tocchiamo drop-in e htaccess.
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			set_transient( 'skit_activation_conflict', 'wp-rocket', 5 * MINUTE_IN_SECONDS );
			return;
		}

		if ( false === get_option( SKIT_OPTION_KEY ) ) {
			add_option( SKIT_OPTION_KEY, SKIT_Config::defaults() );
		}

		wp_mkdir_p( SKIT_CACHE_DIR );
		self::protect_cache_dir();

		SKIT_Config::write_config_file();
		self::install_dropin();
		self::set_wp_cache_constant( true );
		SKIT_Htaccess::write_rules( SKIT_Config::get_settings() );

		if ( ! wp_next_scheduled( 'skit_cache_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'skit_cache_gc' );
		}
	}

	/**
	 * Disattivazione: rimuove tutto ciò che è fuori dalla cartella del plugin.
	 */
	public static function deactivate() {
		self::remove_dropin();
		self::set_wp_cache_constant( false );
		SKIT_Htaccess::remove_rules();
		SKIT_Config::delete_config_files();

		wp_clear_scheduled_hook( 'skit_cache_gc' );
		wp_clear_scheduled_hook( 'skit_preload_start' );
		wp_clear_scheduled_hook( SKIT_Preload::CRON_HOOK );
		wp_clear_scheduled_hook( SKIT_Database::CRON_HOOK );
		delete_option( SKIT_Preload::QUEUE_OPTION );

		if ( is_dir( SKIT_CACHE_DIR ) ) {
			SKIT_Purge::delete_dir( SKIT_CACHE_DIR );
		}
	}

	/**
	 * Copia il drop-in advanced-cache.php in wp-content.
	 *
	 * @return bool
	 */
	public static function install_dropin() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';

		// Non sovrascrivere il drop-in di un altro plugin di cache.
		if ( file_exists( $target ) ) {
			$head = (string) file_get_contents( $target, false, null, 0, 200 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' !== trim( $head ) && false === strpos( $head, 'SpeedKit' ) ) {
				set_transient( 'skit_activation_conflict', 'dropin', 5 * MINUTE_IN_SECONDS );
				return false;
			}
		}

		return (bool) copy( SKIT_PLUGIN_DIR . 'includes/advanced-cache.php', $target );
	}

	/**
	 * Rimuove il drop-in solo se è il nostro.
	 */
	public static function remove_dropin() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $target ) ) {
			return;
		}
		$head = (string) file_get_contents( $target, false, null, 0, 200 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $head, 'SpeedKit' ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Imposta o rimuove define('WP_CACHE', true) in wp-config.php.
	 *
	 * @param bool $enable True per abilitare la costante.
	 * @return bool
	 */
	public static function set_wp_cache_constant( $enable ) {
		$file = self::locate_wp_config();
		if ( '' === $file || ! is_writable( $file ) ) {
			return false;
		}

		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $content ) {
			return false;
		}

		$define  = "define( 'WP_CACHE', " . ( $enable ? 'true' : 'false' ) . " ); // Added by SpeedKit";
		$pattern = "/^[\t ]*define\\(\\s*['\"]WP_CACHE['\"]\\s*,\\s*[^)]*\\)\\s*;[^\n]*/m";

		if ( preg_match( $pattern, $content ) ) {
			$content = (string) preg_replace( $pattern, $define, $content, 1 );
		} elseif ( $enable ) {
			// Inserisce subito dopo il primo tag di apertura PHP (strpos e
			// non regex ancorata: tollera BOM o whitespace iniziali).
			$pos = strpos( $content, '<?php' );
			if ( false === $pos ) {
				return false;
			}
			$content = substr_replace( $content, "<?php\n" . $define, $pos, 5 );
		} else {
			return true;
		}

		return false !== file_put_contents( $file, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Trova wp-config.php (anche un livello sopra la root, setup comune).
	 *
	 * @return string
	 */
	private static function locate_wp_config() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $parent;
		}

		return '';
	}

	/**
	 * Impedisce il listing della cartella di cache.
	 */
	private static function protect_cache_dir() {
		$htaccess = SKIT_CACHE_DIR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = SKIT_CACHE_DIR . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
