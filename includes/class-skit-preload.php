<?php
/**
 * Preload — Rebuilds the cache by visiting the URLs of the sitemap.
 *
 * A lightweight version of WP Rocket's preload: a URL queue stored in an
 * option, processed in batches by a recurring cron event. Starts after a
 * full purge or manually from the admin.
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
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks and cron.
	 */
	public function init() {
		// The custom interval is always registered: already scheduled events
		// must resolve it even when the preload gets disabled.
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
			function ( $schedules ) {
				if ( ! isset( $schedules['skit_minute'] ) ) {
					$schedules['skit_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						// Translated only once translations may load (WordPress 6.7+
						// warns when a text domain is loaded before after_setup_theme).
						'display'  => did_action( 'after_setup_theme' ) ? __( 'Every minute (SpeedKit preload)', 'speed-kit' ) : 'Every minute (SpeedKit preload)',
					);
				}
				return $schedules;
			}
		);

		if ( empty( $this->settings['preload_enabled'] ) ) {
			// Clears any event left scheduled.
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
	 * Schedules the preload start in the background: parsing the sitemap
	 * makes blocking HTTP requests and must not run inside an admin request
	 * (settings save, menu change, etc.).
	 */
	public function schedule_start() {
		if ( ! wp_next_scheduled( 'skit_preload_start' ) ) {
			wp_schedule_single_event( time() + 30, 'skit_preload_start' );
		}
	}

	/**
	 * Starts a full preload: collects the URLs from the sitemap and queues them.
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
	 * After a post purge: rebuilds the first URLs (permalink and home)
	 * right away instead of waiting for cron.
	 *
	 * @param WP_Post  $post Purged post.
	 * @param string[] $urls Purged URLs.
	 */
	public function preload_urls_now( $post, $urls ) {
		foreach ( array_slice( (array) $urls, 0, 3 ) as $url ) {
			$this->fetch( (string) $url );
		}
	}

	/**
	 * Cron: processes one batch of URLs from the queue.
	 */
	public function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			delete_option( self::QUEUE_OPTION );
			return;
		}

		/**
		 * Preload batch size for each cron run.
		 *
		 * @param int $batch_size
		 */
		$batch_size = (int) apply_filters( 'skit_preload_batch_size', self::BATCH_SIZE );
		$batch      = array_splice( $queue, 0, max( 1, $batch_size ) );

		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $url ) {
			$this->fetch( (string) $url );
			usleep( 500000 ); // 0.5 s between requests to avoid overloading the server.

			// With a separate mobile cache the mobile variant is needed too.
			if ( ! empty( $this->settings['cache_mobile_separate'] ) ) {
				$this->fetch( (string) $url, true );
				usleep( 500000 );
			}
		}
	}

	/**
	 * Sends the HTTP request that makes the page generate its cache file.
	 *
	 * @param string $url    URL to preload.
	 * @param bool   $mobile True to simulate a mobile user agent.
	 */
	private function fetch( $url, $mobile = false ) {
		if ( '' === $url ) {
			return;
		}

		// Skip when the cache file already exists.
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
	 * Collects the URLs from the sitemap (recursing into sitemap indexes).
	 *
	 * @return string[]
	 */
	private function collect_urls() {
		$urls = array( home_url( '/' ) );

		$sitemaps = array();
		if ( ! empty( $this->settings['preload_sitemap'] ) ) {
			$sitemaps[] = (string) $this->settings['preload_sitemap'];
		} else {
			// Standard candidates: Yoast/RankMath/SEOPress, WordPress core.
			$sitemaps = array(
				home_url( '/sitemap_index.xml' ),
				home_url( '/sitemap.xml' ),
				home_url( '/wp-sitemap.xml' ),
			);
		}

		/**
		 * Filters the list of sitemaps to preload from.
		 *
		 * @param string[] $sitemaps
		 */
		$sitemaps = (array) apply_filters( 'skit_preload_sitemaps', $sitemaps );

		foreach ( $sitemaps as $sitemap ) {
			$found = $this->parse_sitemap( (string) $sitemap, 0 );
			if ( $found ) {
				$urls = array_merge( $urls, $found );
				break; // The first valid sitemap is enough.
			}
		}

		// Normalize to the site scheme: http URLs in a sitemap of an https site
		// would only produce redirects (the non-blocking fetch does not follow them).
		$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$urls   = array_map(
			function ( $url ) use ( $scheme ) {
				return set_url_scheme( (string) $url, $scheme );
			},
			$urls
		);

		// Same-host URLs only, de-duplicated.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_filter(
			array_unique( $urls ),
			function ( $url ) use ( $host ) {
				return wp_parse_url( $url, PHP_URL_HOST ) === $host;
			}
		);

		/**
		 * Filters the URLs queued for the preload.
		 *
		 * @param string[] $urls
		 */
		return (array) apply_filters( 'skit_preload_urls', array_values( $urls ) );
	}

	/**
	 * Downloads and parses an XML sitemap; recurses into sitemap indexes.
	 *
	 * @param string $url   Sitemap URL.
	 * @param int    $depth Current recursion depth.
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
