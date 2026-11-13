<?php
/**
 * Uninstall — Rimozione completa: opzioni, cron, cache, config,
 * drop-in, costante WP_CACHE e regole .htaccess.
 *
 * @package SpeedKit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'SKIT_VERSION', 'uninstall' );
define( 'SKIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKIT_OPTION_KEY', 'skit_settings' );
if ( ! defined( 'SKIT_CACHE_DIR' ) ) {
	define( 'SKIT_CACHE_DIR', WP_CONTENT_DIR . '/cache/speed-kit/' );
}
if ( ! defined( 'SKIT_CONFIG_DIR' ) ) {
	define( 'SKIT_CONFIG_DIR', WP_CONTENT_DIR . '/speed-kit-config/' );
}

require_once SKIT_PLUGIN_DIR . 'includes/class-skit-config.php';
require_once SKIT_PLUGIN_DIR . 'includes/class-skit-purge.php';
require_once SKIT_PLUGIN_DIR . 'includes/class-skit-htaccess.php';
require_once SKIT_PLUGIN_DIR . 'includes/class-skit-activator.php';
require_once SKIT_PLUGIN_DIR . 'includes/class-skit-preload.php';
require_once SKIT_PLUGIN_DIR . 'includes/class-skit-database.php';

SKIT_Activator::remove_dropin();
SKIT_Activator::set_wp_cache_constant( false );
SKIT_Htaccess::remove_rules();
SKIT_Config::delete_config_files();
SKIT_Purge::delete_dir( SKIT_CACHE_DIR );

wp_clear_scheduled_hook( 'skit_cache_gc' );
wp_clear_scheduled_hook( 'skit_preload_start' );
wp_clear_scheduled_hook( SKIT_Preload::CRON_HOOK );
wp_clear_scheduled_hook( SKIT_Database::CRON_HOOK );

delete_option( SKIT_OPTION_KEY );
delete_option( SKIT_Preload::QUEUE_OPTION );
delete_transient( 'skit_activation_conflict' );
