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
		update_option( 'skit_version', SKIT_VERSION );

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

		// Atomic replace: wp-settings.php include()s this file on every
		// request, a half-copied file would be a fatal parse error.
		$tmp = $target . '.' . uniqid( 'tmp', true );
		if ( ! @copy( SKIT_PLUGIN_DIR . 'includes/advanced-cache.php', $tmp ) || ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		SKIT_Config::opcache_invalidate( $target );

		return true;
	}

	/**
	 * True if wp-content/advanced-cache.php exists and belongs to SpeedKit.
	 *
	 * @return bool
	 */
	public static function dropin_is_ours() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $target ) ) {
			return false;
		}
		$head = (string) file_get_contents( $target, false, null, 0, 200 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return false !== strpos( $head, 'SpeedKit' );
	}

	/**
	 * Runs once after the plugin files are updated (e.g. 1.2.0 -> 1.3.0):
	 * refreshes the per-host config, the drop-in (only if it is ours) and the
	 * .htaccess block (only if it is already there), so that a conflict
	 * state left at activation is never overridden.
	 */
	public static function maybe_upgrade() {
		if ( SKIT_VERSION === get_option( 'skit_version' ) ) {
			return;
		}
		update_option( 'skit_version', SKIT_VERSION );

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			return;
		}

		SKIT_Config::write_config_file();
		if ( self::dropin_is_ours() ) {
			self::install_dropin();
		}
		if ( SKIT_Htaccess::has_rules() ) {
			SKIT_Htaccess::write_rules( SKIT_Config::get_settings() );
		}
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

		$original = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $original ) {
			return false;
		}

		$ours = "define( 'WP_CACHE', true ); // Added by SpeedKit";
		// Any active (not commented out) WP_CACHE define, without its line ending.
		$any_define = "/^[\t ]*define\\(\\s*['\"]WP_CACHE['\"]\\s*,\\s*[^)]*\\)\\s*;[^\r\n]*/m";
		// Only the lines written by SpeedKit (including the "false" line that
		// version 1.2.0 left behind on deactivation).
		$our_define = "/^[\t ]*define\\(\\s*['\"]WP_CACHE['\"][^\r\n]*Added by SpeedKit[^\r\n]*/m";
		$content    = $original;

		if ( $enable ) {
			if ( preg_match( $any_define, $content, $match ) ) {
				$is_ours = false !== strpos( $match[0], 'Added by SpeedKit' );
				if ( ! $is_ours && preg_match( '/,\s*(?:true|1)\s*\)/i', $match[0] ) ) {
					// Already enabled by the site owner or the host: leave it alone.
					return true;
				}
				if ( ! $is_ours ) {
					// Remember the original line to restore it on deactivation.
					update_option( 'skit_wp_cache_original', trim( $match[0] ), false );
				}
				$content = (string) preg_replace_callback(
					$any_define,
					function () use ( $ours ) {
						return $ours;
					},
					$content,
					1
				);
			} else {
				// Right after the first PHP open tag (strpos rather than an
				// anchored regex: tolerates a BOM or leading whitespace). The
				// define always gets its own line, otherwise the rest of the
				// original first line would end up inside our "//" comment.
				$pos = strpos( $content, '<?php' );
				if ( false === $pos ) {
					return false;
				}
				$eol    = false !== strpos( $content, "\r\n" ) ? "\r\n" : "\n";
				$after  = substr( $content, $pos + 5, strlen( $eol ) );
				$insert = '<?php' . $eol . $ours . ( $after === $eol ? '' : $eol );
				$content = substr_replace( $content, $insert, $pos, 5 );
			}
		} else {
			$restore = (string) get_option( 'skit_wp_cache_original', '' );
			if ( '' !== $restore ) {
				$content = (string) preg_replace_callback(
					$our_define,
					function () use ( $restore ) {
						return $restore;
					},
					$content,
					1
				);
			}
			// Drop our remaining line(s) together with their line ending.
			$content = (string) preg_replace( "/^[\t ]*define\\(\\s*['\"]WP_CACHE['\"][^\r\n]*Added by SpeedKit[^\r\n]*(?:\r\n|\n)?/m", '', $content );
			delete_option( 'skit_wp_cache_original' );
		}

		if ( $content === $original ) {
			return true;
		}

		// Sanity check before touching the file that keeps the site alive:
		// at most one define added or removed, PHP open tag still present.
		if ( false === strpos( $content, '<?php' ) || abs( substr_count( $content, 'define' ) - substr_count( $original, 'define' ) ) > 1 ) {
			return false;
		}

		return SKIT_Config::safe_write( $file, $content, $original );
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
