<?php
/**
 * Core — Orchestratore: istanzia i moduli e registra i comportamenti globali.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Core {

	/**
	 * Istanza singleton.
	 *
	 * @var SKIT_Core|null
	 */
	private static $instance = null;

	/**
	 * Impostazioni correnti.
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Modulo purge (usato anche dall'admin).
	 *
	 * @var SKIT_Purge
	 */
	public $purge;

	/**
	 * Modulo preload (usato anche dall'admin).
	 *
	 * @var SKIT_Preload
	 */
	public $preload;

	/**
	 * Modulo database (usato anche dall'admin).
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

		// Invalidazione cache: sempre attiva (admin, cron, front).
		$this->purge = new SKIT_Purge( $this->settings );
		$this->purge->init();

		// Preload: cron e ripartenza dopo i purge.
		$this->preload = new SKIT_Preload( $this->settings );
		$this->preload->init();

		// Cattura e ottimizzazione delle pagine: solo front-end.
		$page_cache = new SKIT_Page_Cache( $this->settings );
		$page_cache->init();

		$optimize = new SKIT_Optimize( $this->settings );
		$optimize->init();

		// Lazyload immagini e facade YouTube.
		$media = new SKIT_Media( $this->settings );
		$media->init();

		// Minify CSS, Google Fonts, preload font, emoji.
		$assets = new SKIT_Assets( $this->settings );
		$assets->init();

		// Prefetch dei link interni al hover.
		$links = new SKIT_Links( $this->settings );
		$links->init();

		// Riscrittura URL verso CDN.
		$cdn = new SKIT_Cdn( $this->settings );
		$cdn->init();

		// Pulizia database (cron settimanale opzionale + azione manuale).
		$this->database = new SKIT_Database( $this->settings );
		$this->database->init();

		// Admin: pagina impostazioni, admin bar, azioni manuali.
		$admin = new SKIT_Admin( $this );
		$admin->init();

		$this->global_tweaks();
	}

	/**
	 * Comportamenti globali minori.
	 */
	private function global_tweaks() {
		// Cookie dei commenti a vita breve (3 minuti): chi commenta torna
		// rapidamente a ricevere pagine dalla cache. Trucco di WP Rocket.
		add_filter(
			'comment_cookie_lifetime',
			function () {
				return 3 * MINUTE_IN_SECONDS;
			},
			PHP_INT_MAX
		);

		// Heartbeat meno frequente: meno richieste admin-ajax in background.
		add_filter(
			'heartbeat_settings',
			function ( $settings ) {
				$settings['interval'] = 60;
				return $settings;
			}
		);

		// Avvisa gli altri plugin che c'è una page cache attiva.
		if ( ! defined( 'WP_CACHE_PLUGIN' ) ) {
			define( 'WP_CACHE_PLUGIN', 'speed-kit' );
		}
	}
}
