<?php
/**
 * Plugin Name:       SpeedKit
 * Plugin URI:        https://github.com/example/speed-kit
 * Description:       Caching e ottimizzazione performance: page cache su disco con drop-in advanced-cache, purge automatico, regole browser cache/gzip, minify HTML e CSS, defer e delay JS, lazyload immagini e iframe, facade YouTube, preload sitemap e link, CDN, pulizia database.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SpeedKit
 * Author URI:        https://github.com/example/speed-kit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       speed-kit
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
// A ogni bump aggiornare ANCHE le due stringhe di versione in includes/advanced-cache.php
// (commento SKIT_DROPIN_VERSION e define SKIT_ADVANCED_CACHE), poi rigenerare lo zip.
define( 'SKIT_VERSION', '1.2.0' );
define( 'SKIT_PLUGIN_FILE', __FILE__ );
define( 'SKIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SKIT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SKIT_OPTION_KEY', 'skit_settings' );

if ( ! defined( 'SKIT_CACHE_DIR' ) ) {
	define( 'SKIT_CACHE_DIR', WP_CONTENT_DIR . '/cache/speed-kit/' );
}
if ( ! defined( 'SKIT_CONFIG_DIR' ) ) {
	define( 'SKIT_CONFIG_DIR', WP_CONTENT_DIR . '/speed-kit-config/' );
}

// Autoloader for plugin classes (SKIT_* prefix).
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'SKIT_' ) !== 0 ) {
			return;
		}
		$slug      = strtolower( str_replace( array( 'SKIT_', '_' ), array( '', '-' ), $class ) );
		$filename  = 'class-skit-' . $slug . '.php';
		$locations = array(
			SKIT_PLUGIN_DIR . 'includes/' . $filename,
			SKIT_PLUGIN_DIR . 'admin/' . $filename,
		);
		foreach ( $locations as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

// Activation / Deactivation.
register_activation_hook( __FILE__, array( 'SKIT_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SKIT_Activator', 'deactivate' ) );

// Bootstrap.
add_action(
	'plugins_loaded',
	function () {
		SKIT_Core::instance();
	},
	1
);
