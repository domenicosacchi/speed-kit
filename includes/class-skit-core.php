<?php
/**
 * Core — Orchestrator: instantiates the modules and registers global behaviours.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Core {

	/**
	 * Singleton instance.
	 *
	 * @var SKIT_Core|null
	 */
	private static $instance = null;

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Purge module (also used by the admin).
	 *
	 * @var SKIT_Purge
	 */
	public $purge;

	/**
	 * Preload module (also used by the admin).
	 *
	 * @var SKIT_Preload
	 */
	public $preload;

	/**
	 * Database module (also used by the admin).
	 *
	 * @var SKIT_Database
	 */
	public $database;

	/**
	 * @return SKIT_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings = SKIT_Config::get_settings();

		// Cache invalidation: always on (admin, cron, front end).
		$this->purge = new SKIT_Purge( $this->settings );
		$this->purge->init();

		// Preload: cron and restart after purges.
		$this->preload = new SKIT_Preload( $this->settings );
		$this->preload->init();

		// Page capture and optimization: front end only.
		$page_cache = new SKIT_Page_Cache( $this->settings );
		$page_cache->init();

		$optimize = new SKIT_Optimize( $this->settings );
		$optimize->init();

		// Image lazyload and YouTube facade.
		$media = new SKIT_Media( $this->settings );
		$media->init();

		// CSS minify, Google Fonts, font preload, emoji.
		$assets = new SKIT_Assets( $this->settings );
		$assets->init();

		// Prefetch of internal links on hover.
		$links = new SKIT_Links( $this->settings );
		$links->init();

		// URL rewriting to a CDN.
		$cdn = new SKIT_Cdn( $this->settings );
		$cdn->init();

		// Database cleanup (optional weekly cron + manual action).
		$this->database = new SKIT_Database( $this->settings );
		$this->database->init();

		// Admin: settings page, admin bar, manual actions.
		$admin = new SKIT_Admin( $this );
		$admin->init();

		$this->global_tweaks();
	}

	/**
	 * Minor global behaviours.
	 */
	private function global_tweaks() {
		// Short-lived comment cookies (3 minutes): commenters quickly go
		// back to receiving cached pages. A WP Rocket trick.
		add_filter(
			'comment_cookie_lifetime',
			function () {
				return 3 * MINUTE_IN_SECONDS;
			},
			PHP_INT_MAX
		);

		// Less frequent Heartbeat: fewer background admin-ajax requests.
		add_filter(
			'heartbeat_settings',
			function ( $settings ) {
				$settings['interval'] = 60;
				return $settings;
			}
		);

		// Tells other plugins that a page cache is active.
		if ( ! defined( 'WP_CACHE_PLUGIN' ) ) {
			define( 'WP_CACHE_PLUGIN', 'speed-kit' );
		}
	}
}
