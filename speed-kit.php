<?php
/**
 * Plugin Name:       SpeedKit
 * Plugin URI:        https://github.com/domenicosacchi/speed-kit
 * Description:       Caching and performance optimization: disk page cache with advanced-cache drop-in, automatic purge, browser cache/gzip rules, HTML and CSS minify, defer and delay JS, image and iframe lazyload, YouTube facade, sitemap and link preload, CDN, database cleanup.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Domenico Sacchi
 * Author URI:        https://github.com/domenicosacchi/speed-kit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       speed-kit
 * Domain Path:       /languages
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
// On every version bump ALSO update the two version strings in
// includes/advanced-cache.php (SKIT_DROPIN_VERSION comment and the
// SKIT_ADVANCED_CACHE define).
define( 'SKIT_VERSION', '1.3.0' );
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

// Translations bundled in /languages (the plugin is distributed outside
// wordpress.org, so WordPress would not download them).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'speed-kit', false, dirname( SKIT_PLUGIN_BASENAME ) . '/languages' );
	},
	0
);

// Bootstrap.
add_action(
	'plugins_loaded',
	function () {
		SKIT_Core::instance();
	},
	1
);
