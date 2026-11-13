<?php
/**
 * Preload — Rigenera la cache visitando gli URL della sitemap.
 *
 * Versione leggera del preload di WP Rocket: coda di URL in un'opzione,
 * processata a lotti da un evento cron ricorrente. Parte dopo un purge
 * completo o manualmente dall'admin.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Preload {

	const QUEUE_OPTION = 'skit_preload_queue';
	const CRON_HOOK    = 'skit_preload_batch';
	const BATCH_SIZE   = 10;

	/**
	 * Impostazioni correnti.
	 *
	 * @var array
	 */
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra hook e cron.
	 */
	public function init() {
		// L'intervallo custom va registrato sempre: eventi già schedulati
		// devono poterlo risolvere anche se il preload viene disattivato.
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
			function ( $schedules ) {
				if ( ! isset( $schedules['skit_minute'] ) ) {
					$schedules['skit_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						'display'  => 'Ogni minuto (SpeedKit preload)',
					);
				}
				return $schedules;
			}
		);

		if ( empty( $this->settings['preload_enabled'] ) ) {
			// Ripulisce eventuali eventi rimasti schedulati.
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
				delete_option( self::QUEUE_OPTION );
			}
			return;
		}

		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );
		add_action( 'skit_preload_start', array( $this, 'start' ) );
		add_action( 'skit_after_clean_domain', array( $this, 'schedule_start' ) );
		add_action( 'skit_after_clean_post', array( $this, 'preload_urls_now' ), 10, 2 );
	}

	/**
	 * Pianifica l'avvio del preload in background: il parsing della sitemap
	 * fa richieste HTTP bloccanti e non deve girare dentro una richiesta
	 * admin (salvataggio impostazioni, cambio menu, ecc.).
	 */
	public function schedule_start() {
		if ( ! wp_next_scheduled( 'skit_preload_start' ) ) {
			wp_schedule_single_event( time() + 30, 'skit_preload_start' );
		}
	}

	/**
	 * Avvia un preload completo: raccoglie gli URL dalla sitemap e accoda.
	 */
	public function start() {
		$urls = $this->collect_urls();
		if ( empty( $urls ) ) {
			return;
		}

		update_option( self::QUEUE_OPTION, array_values( $urls ), false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'skit_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Dopo il purge di un post: rigenera subito i primi URL (permalink e home)
	 * senza attendere il giro di cron.
	 *
	 * @param WP_Post  $post Post ripulito.
	 * @param string[] $urls URL ripuliti.
	 */
	public function preload_urls_now( $post, $urls ) {
		foreach ( array_slice( (array) $urls, 0, 3 ) as $url ) {
			$this->fetch( (string) $url );
		}
	}

	/**
	 * Cron: processa un lotto di URL dalla coda.
	 */
	public function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			delete_option( self::QUEUE_OPTION );
			return;
		}

		/**
		 * Dimensione del lotto di preload per ogni giro di cron.
		 *
		 * @param int $batch_size
		 */
		$batch_size = (int) apply_filters( 'skit_preload_batch_size', self::BATCH_SIZE );
		$batch      = array_splice( $queue, 0, max( 1, $batch_size ) );

		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $url ) {
			$this->fetch( (string) $url );
			usleep( 500000 ); // 0,5s tra le richieste per non saturare il server.

			// Con cache mobile separata serve anche la variante mobile.
			if ( ! empty( $this->settings['cache_mobile_separate'] ) ) {
				$this->fetch( (string) $url, true );
				usleep( 500000 );
			}
		}
	}

	/**
	 * Esegue la richiesta HTTP che fa generare la cache.
	 *
	 * @param string $url    URL da precaricare.
	 * @param bool   $mobile True per simulare uno user agent mobile.
	 */
	private function fetch( $url, $mobile = false ) {
		if ( '' === $url ) {
			return;
		}

		// Salta se il file di cache esiste già.
		$dir      = SKIT_Purge::url_to_cache_dir( $url );
		$suffix   = $mobile ? '-mobile' : '';
		$is_https = 0 === strpos( $url, 'https://' );
		$file     = $dir . '/index' . $suffix . ( $is_https ? '-https' : '' ) . '.html';
		if ( is_file( $file ) ) {
			return;
		}

		$args = array(
			'timeout'    => 10,
			'blocking'   => false,
			'sslverify'  => false,
			'user-agent' => $mobile
				? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 SpeedKit/Preload'
				: 'SpeedKit/Preload',
		);

		wp_safe_remote_get( esc_url_raw( $url ), $args );
	}

	/**
	 * Raccoglie gli URL dalla sitemap (con ricorsione sugli indici).
	 *
	 * @return string[]
	 */
	private function collect_urls() {
		$urls = array( home_url( '/' ) );

		$sitemaps = array();
		if ( ! empty( $this->settings['preload_sitemap'] ) ) {
			$sitemaps[] = (string) $this->settings['preload_sitemap'];
		} else {
			// Candidati standard: Yoast/RankMath/SEOPress, core WP.
			$sitemaps = array(
				home_url( '/sitemap_index.xml' ),
				home_url( '/sitemap.xml' ),
				home_url( '/wp-sitemap.xml' ),
			);
		}

		/**
		 * Filtra la lista di sitemap da cui precaricare.
		 *
		 * @param string[] $sitemaps
		 */
		$sitemaps = (array) apply_filters( 'skit_preload_sitemaps', $sitemaps );

		foreach ( $sitemaps as $sitemap ) {
			$found = $this->parse_sitemap( (string) $sitemap, 0 );
			if ( $found ) {
				$urls = array_merge( $urls, $found );
				break; // Basta la prima sitemap valida.
			}
		}

		// Normalizza allo schema del sito: sitemap con URL http su sito
		// https produrrebbero solo redirect (il fetch non-bloccante non li segue).
		$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$urls   = array_map(
			function ( $url ) use ( $scheme ) {
				return set_url_scheme( (string) $url, $scheme );
			},
			$urls
		);

		// Solo URL dello stesso host, deduplicati.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_filter(
			array_unique( $urls ),
			function ( $url ) use ( $host ) {
				return wp_parse_url( $url, PHP_URL_HOST ) === $host;
			}
		);

		/**
		 * Filtra gli URL accodati per il preload.
		 *
		 * @param string[] $urls
		 */
		return (array) apply_filters( 'skit_preload_urls', array_values( $urls ) );
	}

	/**
	 * Scarica e interpreta una sitemap XML; ricorre negli indici di sitemap.
	 *
	 * @param string $url   URL della sitemap.
	 * @param int    $depth Profondità di ricorsione corrente.
	 * @return string[]
	 */
	private function parse_sitemap( $url, $depth ) {
		if ( $depth > 2 ) {
			return array();
		}

		$response = wp_safe_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || false === strpos( $body, '<' ) ) {
			return array();
		}

		$use_errors = libxml_use_internal_errors( true );
		$xml        = simplexml_load_string( $body );
		libxml_use_internal_errors( $use_errors );
		if ( false === $xml ) {
			return array();
		}

		$urls = array();
		$name = strtolower( $xml->getName() );

		if ( 'sitemapindex' === $name ) {
			foreach ( $xml->sitemap as $entry ) {
				$loc = trim( (string) $entry->loc );
				if ( '' !== $loc ) {
					$urls = array_merge( $urls, $this->parse_sitemap( $loc, $depth + 1 ) );
				}
			}
		} elseif ( 'urlset' === $name ) {
			foreach ( $xml->url as $entry ) {
				$loc = trim( (string) $entry->loc );
				if ( '' !== $loc ) {
					$urls[] = $loc;
				}
			}
		}

		return $urls;
	}
}
