<?php
/**
 * Page Cache — Cattura l'output delle pagine cacheabili e lo salva su disco.
 *
 * Il drop-in advanced-cache.php serve i file salvati qui alle richieste
 * successive senza caricare tutto WordPress.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Page_Cache {

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
	 * Registra il buffering sul front-end.
	 */
	public function init() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Tardi su template_redirect: a quel punto query e stato utente sono noti.
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 99999 );
	}

	/**
	 * Avvia l'output buffering se la richiesta è cacheabile.
	 */
	public function maybe_start_buffer() {
		if ( ! $this->is_cacheable() ) {
			return;
		}

		ob_start( array( $this, 'save_buffer' ) );
	}

	/**
	 * Verifica se la richiesta corrente può essere salvata in cache.
	 *
	 * @return bool
	 */
	public function is_cacheable() {
		if ( empty( $this->settings['cache_enabled'] ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}

		// Con una query string (anche solo utm_*) la pagina NON viene salvata:
		// l'HTML potrebbe contenere l'URL corrente (og:url, form, paginazione)
		// e finirebbe nel file dell'URL base servito a tutti. Il drop-in
		// serve comunque la cache base a queste richieste.
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		// Only cache requests addressed to the site's own host. The Host header
		// is client-controlled: without this check arbitrary Host values would
		// create arbitrary cache directories (disk fill).
		if ( ! $this->request_host_matches_site() ) {
			return false;
		}

		// Utenti loggati: mai in cache. La pagina di un utente loggato
		// (admin bar, nonce, contenuti personali) finirebbe nel file
		// condiviso servito agli anonimi.
		if ( is_user_logged_in() ) {
			return false;
		}

		if ( is_404() || is_search() || is_feed() || is_preview() || is_customize_preview() || is_trackback() || is_robots() ) {
			return false;
		}

		if ( is_singular() && post_password_required() ) {
			return false;
		}

		// Pagine WooCommerce dinamiche, se presente.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}

		if ( ! $this->request_matches_dropin_rules() ) {
			return false;
		}

		/**
		 * Filtro finale: consente di bloccare la generazione della cache.
		 * Equivalente di do_rocket_generate_caching_files.
		 *
		 * @param bool $cacheable
		 */
		return (bool) apply_filters( 'skit_do_cache_page', true );
	}

	/**
	 * Riapplica le stesse regole del drop-in (esclusioni URI, query string,
	 * cookie) così da non salvare mai un file che non verrebbe servito.
	 *
	 * @return bool
	 */
	private function request_matches_dropin_rules() {
		if ( ! function_exists( 'skit_ac_is_excluded_path' ) ) {
			// Drop-in non caricato (es. WP_CACHE mancante): carica le funzioni.
			require_once SKIT_PLUGIN_DIR . 'includes/advanced-cache.php';
		}

		$config = array(
			'exclude_uris'         => (array) $this->settings['exclude_uris'],
			'ignored_query_params' => (array) $this->settings['ignored_query_params'],
			'skip_cookies'         => SKIT_Config::skip_cookies( $this->settings ),
			'cache_enabled'        => 1,
		);

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = rawurldecode( (string) wp_parse_url( 'http://x' . $uri, PHP_URL_PATH ) );

		if ( skit_ac_is_excluded_path( $path, $config ) ) {
			return false;
		}
		if ( ! skit_ac_query_string_ok( $config ) ) {
			return false;
		}
		if ( skit_ac_has_skip_cookie( $config ) ) {
			return false;
		}

		return true;
	}

	/**
	 * True if the request Host header (normalized like the drop-in does)
	 * is the host of home_url().
	 *
	 * @return bool
	 */
	private function request_host_matches_site() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return false;
		}
		$host = strtolower( (string) preg_replace( '/[^a-z0-9.\-:]/i', '', (string) $_SERVER['HTTP_HOST'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$host = str_replace( ':', '-', $host );

		return '' !== $host && SKIT_Config::current_host() === $host;
	}

	/**
	 * Callback dell'output buffer: ottimizza, salva su disco e restituisce l'HTML.
	 *
	 * @param string $buffer HTML generato dalla pagina.
	 * @return string
	 */
	public function save_buffer( $buffer ) {
		// Pagina incompleta o errore: non salvare.
		if ( strlen( $buffer ) < 255 || false === stripos( $buffer, '</html>' ) ) {
			return $buffer;
		}

		if ( 200 !== http_response_code() ) {
			return $buffer;
		}

		// DONOTCACHEPAGE può essere definita anche durante il rendering.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return $buffer;
		}

		// Solo contenuto HTML.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $buffer;
			}
			// Respect responses marked as uncacheable by WordPress
			// (nocache_headers()) or by other plugins.
			if ( 0 === stripos( $header, 'Cache-Control:' ) && preg_match( '/no-store|no-cache|private/i', $header ) ) {
				return $buffer;
			}
		}

		/**
		 * Ottimizzazioni sull'HTML prima del salvataggio (minify, defer, ecc.).
		 * Equivalente di rocket_buffer.
		 *
		 * @param string $buffer
		 */
		$optimized = (string) apply_filters( 'skit_buffer', $buffer );
		if ( '' === $optimized ) {
			$optimized = $buffer;
		}

		$file = $this->cache_file_path();
		if ( '' === $file ) {
			return $optimized;
		}

		$footprint  = "\n<!-- Cached by SpeedKit " . SKIT_VERSION . ' - ' . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
		$this->write_file( $file, $optimized . $footprint );

		return $optimized;
	}

	/**
	 * Percorso del file di cache per la richiesta corrente (speculare al drop-in).
	 *
	 * @return string
	 */
	private function cache_file_path() {
		if ( ! function_exists( 'skit_ac_cache_file_path' ) ) {
			require_once SKIT_PLUGIN_DIR . 'includes/advanced-cache.php';
		}

		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = rawurldecode( (string) wp_parse_url( 'http://x' . $uri, PHP_URL_PATH ) );

		$config = array(
			'cache_mobile_separate' => (int) $this->settings['cache_mobile_separate'],
		);

		return skit_ac_cache_file_path( $path, $config );
	}

	/**
	 * Scrive il file di cache in modo atomico, più la variante gzip.
	 *
	 * @param string $file    Percorso di destinazione.
	 * @param string $content HTML da salvare.
	 */
	private function write_file( $file, $content ) {
		$dir = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$tmp = $file . '.' . uniqid( 'tmp', true );
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( function_exists( 'gzencode' ) ) {
			$gz = gzencode( $content, 6 );
			if ( false !== $gz ) {
				$tmp_gz = $file . '.gz.' . uniqid( 'tmp', true );
				if ( false !== file_put_contents( $tmp_gz, $gz, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					@rename( $tmp_gz, $file . '.gz' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					@unlink( $tmp_gz ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}
}
