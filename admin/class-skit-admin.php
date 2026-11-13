<?php
/**
 * Admin — Pagina impostazioni, admin bar (svuota cache / preload),
 * azioni manuali e avvisi di stato dell'installazione.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Admin {

	/**
	 * Core del plugin.
	 *
	 * @var SKIT_Core
	 */
	private $core;

	public function __construct( SKIT_Core $core ) {
		$this->core = $core;
	}

	/**
	 * Registra gli hook (admin bar anche sul front).
	 */
	public function init() {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'admin_post_skit_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_skit_purge_url', array( $this, 'handle_purge_url' ) );
		add_action( 'admin_post_skit_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_post_skit_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_skit_reinstall', array( $this, 'handle_reinstall' ) );
		add_action( 'admin_post_skit_db_cleanup', array( $this, 'handle_db_cleanup' ) );

		if ( is_admin() ) {
			// One-off refresh of drop-in, config and .htaccess after an update.
			add_action( 'admin_init', array( 'SKIT_Activator', 'maybe_upgrade' ) );
			add_action( 'admin_menu', array( $this, 'menu' ) );
			add_action( 'admin_notices', array( $this, 'notices' ) );
			add_filter( 'plugin_action_links_' . SKIT_PLUGIN_BASENAME, array( $this, 'action_links' ) );

			// Auto-riparazione: il GC è l'unica scadenza per i file serviti
			// direttamente da Apache; se manca (es. attivazione andata in
			// conflitto con WP Rocket) va rischedulato.
			add_action(
				'admin_init',
				function () {
					if ( ! wp_next_scheduled( 'skit_cache_gc' ) ) {
						wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'skit_cache_gc' );
					}
				}
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin bar
	 * ------------------------------------------------------------------ */

	/**
	 * Voci nella admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'speed-kit',
				'title' => 'SpeedKit',
				'href'  => admin_url( 'options-general.php?page=speed-kit' ),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'speed-kit',
				'id'     => 'skit-purge-all',
				'title'  => 'Svuota tutta la cache',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=skit_purge_all' ), 'skit_purge_all' ),
			)
		);

		if ( ! is_admin() ) {
			$current = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
			$bar->add_node(
				array(
					'parent' => 'speed-kit',
					'id'     => 'skit-purge-url',
					'title'  => 'Svuota cache di questa pagina',
					'href'   => wp_nonce_url(
						admin_url( 'admin-post.php?action=skit_purge_url&url=' . rawurlencode( $current ) ),
						'skit_purge_url'
					),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'speed-kit',
				'id'     => 'skit-preload',
				'title'  => 'Avvia preload cache',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=skit_preload' ), 'skit_preload' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Azioni manuali
	 * ------------------------------------------------------------------ */

	public function handle_purge_all() {
		$this->verify_action( 'skit_purge_all' );
		$this->core->purge->clean_domain();
		$this->redirect_back( 'purged' );
	}

	public function handle_purge_url() {
		$this->verify_action( 'skit_purge_url' );
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		if ( '' !== $url && 0 === strpos( $url, home_url() ) ) {
			$this->core->purge->clean_url( $url, true );
		}
		$this->redirect_back( 'purged' );
	}

	public function handle_preload() {
		$this->verify_action( 'skit_preload' );
		$this->core->preload->schedule_start();
		$this->redirect_back( 'preload' );
	}

	public function handle_db_cleanup() {
		$this->verify_action( 'skit_db_cleanup' );
		// Destructive action: POST only, with an explicit confirmation checkbox
		// checked server-side (the JS confirm() alone can be bypassed).
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['skit_confirm_db'] ) ) {
			$this->redirect_back( 'db_not_confirmed' );
		}
		$results = $this->core->database->cleanup();
		set_transient( 'skit_db_cleanup_result', $results, 5 * MINUTE_IN_SECONDS );
		$this->redirect_back( 'db_cleaned' );
	}

	public function handle_reinstall() {
		$this->verify_action( 'skit_reinstall' );
		SKIT_Config::write_config_file();
		SKIT_Activator::install_dropin();
		SKIT_Activator::set_wp_cache_constant( true );
		SKIT_Htaccess::write_rules( $this->core->settings );
		$this->redirect_back( 'reinstalled' );
	}

	/**
	 * Verifica nonce e permessi per un'azione admin-post.
	 *
	 * @param string $action Nome azione/nonce.
	 */
	private function verify_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( $action );
	}

	/**
	 * Torna alla pagina precedente (o alle impostazioni) con un flag esito.
	 *
	 * @param string $message Codice del messaggio.
	 */
	private function redirect_back( $message ) {
		$fallback = admin_url( 'options-general.php?page=speed-kit' );
		$referer  = wp_get_referer();
		$target   = $referer ? $referer : $fallback;
		wp_safe_redirect( add_query_arg( 'skit_msg', rawurlencode( $message ), $target ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$conflict = get_transient( 'skit_activation_conflict' );
		if ( 'wp-rocket' === $conflict ) {
			echo '<div class="notice notice-error"><p><strong>SpeedKit:</strong> WP Rocket è ancora attivo. Disattivalo e poi riattiva SpeedKit (o usa "Reinstalla componenti" nelle impostazioni) per installare drop-in e regole.</p></div>';
		} elseif ( 'dropin' === $conflict ) {
			echo '<div class="notice notice-error"><p><strong>SpeedKit:</strong> è presente un advanced-cache.php di un altro plugin di cache. Rimuovilo e usa "Reinstalla componenti" nelle impostazioni di SpeedKit.</p></div>';
		}

		// Other page cache plugins active at the same time.
		$others = $this->active_cache_plugins();
		if ( $others && 'wp-rocket' !== $conflict ) {
			echo '<div class="notice notice-error"><p><strong>SpeedKit:</strong> sono attivi altri plugin di cache (' . esc_html( implode( ', ', $others ) ) . '). Due page cache insieme si sovrascrivono a vicenda: disattivane una.</p></div>';
		}

		// Stato drop-in: assente o versione diversa.
		if ( ! $conflict && ! empty( $this->core->settings['cache_enabled'] ) ) {
			if ( ! defined( 'SKIT_ADVANCED_CACHE' ) ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=skit_reinstall' ), 'skit_reinstall' );
				echo '<div class="notice notice-warning"><p><strong>SpeedKit:</strong> il drop-in advanced-cache.php non è attivo (WP_CACHE mancante o file assente): le pagine non vengono servite dalla cache. <a href="' . esc_url( $url ) . '">Reinstalla componenti</a>.</p></div>';
			} elseif ( SKIT_ADVANCED_CACHE !== SKIT_VERSION && defined( 'SKIT_ADVANCED_CACHE' ) ) {
				// Auto-riparazione: dopo un aggiornamento del plugin il drop-in
				// resta la copia vecchia; ricopiarlo è idempotente e senza rischi
				// (install_dropin rifiuta i drop-in di altri plugin).
				if ( SKIT_Activator::install_dropin() ) {
					echo '<div class="notice notice-success"><p><strong>SpeedKit:</strong> drop-in advanced-cache.php aggiornato automaticamente dalla versione ' . esc_html( SKIT_ADVANCED_CACHE ) . ' alla ' . esc_html( SKIT_VERSION ) . '. Ricarica la pagina per confermare.</p></div>';
				} else {
					$url = wp_nonce_url( admin_url( 'admin-post.php?action=skit_reinstall' ), 'skit_reinstall' );
					echo '<div class="notice notice-warning"><p><strong>SpeedKit:</strong> il drop-in advanced-cache.php (versione ' . esc_html( SKIT_ADVANCED_CACHE ) . ') non corrisponde al plugin (versione ' . esc_html( SKIT_VERSION ) . ') e la copia automatica non è riuscita: controlla i permessi di wp-content. <a href="' . esc_url( $url ) . '">Aggiorna i componenti</a>.</p></div>';
				}
			}
		}

		if ( isset( $_GET['skit_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = array(
				'purged'      => 'Cache svuotata.',
				'preload'     => 'Preload avviato: la cache verrà rigenerata in background.',
				'saved'       => 'Impostazioni salvate. Cache svuotata e configurazione rigenerata.',
				'reinstalled' => 'Componenti reinstallati (drop-in, WP_CACHE, regole .htaccess, config).',
				'db_cleaned'  => 'Database ripulito.',
			);
			$db_result = get_transient( 'skit_db_cleanup_result' );
			if ( is_array( $db_result ) ) {
				$parts = array();
				foreach ( $db_result as $label => $count ) {
					$parts[] = (int) $count . ' ' . $label;
				}
				$messages['db_cleaned'] = 'Database ripulito: ' . implode( ', ', $parts ) . '.';
				delete_transient( 'skit_db_cleanup_result' );
			}
			$key = sanitize_key( wp_unslash( $_GET['skit_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'db_not_confirmed' === $key ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>SpeedKit:</strong> pulizia database non eseguita: spunta la casella di conferma.</p></div>';
			} elseif ( isset( $messages[ $key ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>SpeedKit:</strong> ' . esc_html( $messages[ $key ] ) . '</p></div>';
			}
		}
	}

	/**
	 * Names of known page cache plugins that are active right now.
	 *
	 * @return string[]
	 */
	private function active_cache_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		/**
		 * Filters the plugin basenames treated as conflicting page caches.
		 *
		 * @param array<string,string> $plugins Basename => display name.
		 */
		$known = (array) apply_filters(
			'skit_conflicting_plugins',
			array(
				'w3-total-cache/w3-total-cache.php'        => 'W3 Total Cache',
				'wp-super-cache/wp-cache.php'              => 'WP Super Cache',
				'litespeed-cache/litespeed-cache.php'      => 'LiteSpeed Cache',
				'wp-fastest-cache/wpFastestCache.php'      => 'WP Fastest Cache',
				'breeze/breeze.php'                        => 'Breeze',
				'cache-enabler/cache-enabler.php'          => 'Cache Enabler',
				'comet-cache/comet-cache.php'              => 'Comet Cache',
				'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
				'sg-cachepress/sg-cachepress.php'          => 'Speed Optimizer (SiteGround)',
				'wp-optimize/wp-optimize.php'              => 'WP-Optimize',
				'swift-performance-lite/performance.php'   => 'Swift Performance Lite',
			)
		);

		// WP Rocket is not on wordpress.org: detected by its constant.
		$active = defined( 'WP_ROCKET_VERSION' ) ? array( 'WP Rocket' ) : array();
		foreach ( $known as $basename => $name ) {
			if ( is_plugin_active( (string) $basename ) ) {
				$active[] = (string) $name;
			}
		}

		return $active;
	}

	/**
	 * Link rapidi nella lista plugin.
	 *
	 * @param string[] $links Link esistenti.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=speed-kit' ) ) . '">Impostazioni</a>' );

		return $links;
	}

	/* ---------------------------------------------------------------------
	 * Pagina impostazioni
	 * ------------------------------------------------------------------ */

	public function menu() {
		add_options_page( 'SpeedKit', 'SpeedKit', 'manage_options', 'speed-kit', array( $this, 'render_page' ) );
	}

	/**
	 * Salvataggio impostazioni.
	 */
	public function handle_save_settings() {
		$this->verify_action( 'skit_save_settings' );

		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$out = SKIT_Config::get_settings();

		foreach ( array( 'cache_enabled', 'cache_mobile_separate', 'htaccess_browser_cache', 'htaccess_gzip', 'htaccess_direct_serve', 'minify_html', 'defer_js', 'delay_js', 'lazyload_iframes', 'preload_enabled', 'lazyload_images', 'youtube_facade', 'minify_css', 'font_display_swap', 'disable_emojis', 'preload_links', 'cdn_enabled', 'db_auto_cleanup' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		$out['cache_lifespan']      = isset( $in['cache_lifespan'] ) ? max( 0, (int) $in['cache_lifespan'] ) : 0;
		$out['lazyload_skip_first'] = isset( $in['lazyload_skip_first'] ) ? min( 10, max( 0, (int) $in['lazyload_skip_first'] ) ) : 2;
		$out['cdn_host']            = isset( $in['cdn_host'] ) ? preg_replace( '#^(?:https?:)?//|[^a-z0-9.\-]#i', '', trim( (string) $in['cdn_host'] ) ) : '';

		foreach ( array( 'exclude_uris', 'defer_js_exclude', 'delay_js_exclude', 'dns_prefetch', 'ignored_query_params', 'minify_css_exclude', 'preload_fonts', 'skip_cookies_extra' ) as $list ) {
			$out[ $list ] = $this->sanitize_lines( isset( $in[ $list ] ) ? (string) $in[ $list ] : '' );
		}

		// Cookie names end up in .htaccess: strict character set.
		$out['skip_cookies_extra'] = array_values( array_filter( array_map( array( 'SKIT_Config', 'sanitize_cookie_name' ), $out['skip_cookies_extra'] ) ) );

		// Le regex utente non valide vengono scartate (stessa logica di WP Rocket).
		foreach ( array( 'exclude_uris', 'defer_js_exclude', 'minify_css_exclude' ) as $regex_list ) {
			$out[ $regex_list ] = array_values(
				array_filter(
					$out[ $regex_list ],
					function ( $pattern ) {
						return false !== @preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#', 'test' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				)
			);
		}

		$out['preload_sitemap'] = isset( $in['preload_sitemap'] ) ? esc_url_raw( trim( (string) $in['preload_sitemap'] ) ) : '';

		update_option( SKIT_OPTION_KEY, $out );

		// Rigenera tutto e riparte pulito.
		SKIT_Config::write_config_file();
		SKIT_Htaccess::write_rules( $out );
		$purge = new SKIT_Purge( $out );
		$purge->clean_domain();

		wp_safe_redirect( add_query_arg( 'skit_msg', 'saved', admin_url( 'options-general.php?page=speed-kit' ) ) );
		exit;
	}

	/**
	 * Textarea → array di righe pulite.
	 *
	 * Niente sanitize_text_field: mutilerebbe le regex utente (es. "<").
	 * Le righe vengono solo ripulite da caratteri di controllo; le regex
	 * non valide vengono comunque scartate dalla validazione preg a valle.
	 *
	 * @param string $raw Testo grezzo.
	 * @return string[]
	 */
	private function sanitize_lines( $raw ) {
		$raw   = (string) wp_check_invalid_utf8( $raw );
		$raw   = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		return array_values( $lines );
	}

	/**
	 * Render della pagina impostazioni.
	 */
	public function render_page() {
		$s = SKIT_Config::get_settings();

		$lifespans = array(
			0                     => 'Mai (solo purge automatico)',
			HOUR_IN_SECONDS       => '1 ora',
			5 * HOUR_IN_SECONDS   => '5 ore',
			10 * HOUR_IN_SECONDS  => '10 ore (consigliato)',
			DAY_IN_SECONDS        => '24 ore',
			WEEK_IN_SECONDS       => '7 giorni',
		);

		$stats = $this->cache_stats();
		?>
		<div class="wrap">
			<h1>SpeedKit</h1>
			<p>Cache pagine: <strong><?php echo (int) $stats['files']; ?></strong> file, <strong><?php echo esc_html( size_format( $stats['size'] ) ); ?></strong>.
				Drop-in: <strong><?php echo defined( 'SKIT_ADVANCED_CACHE' ) ? 'attivo' : 'NON attivo'; ?></strong>.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="skit_save_settings">
				<?php wp_nonce_field( 'skit_save_settings' ); ?>

				<h2 class="title">Cache pagine</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Page cache</th>
						<td>
							<label><input type="checkbox" name="cache_enabled" value="1" <?php checked( $s['cache_enabled'] ); ?>> Abilita la cache su disco delle pagine</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Durata di vita</th>
						<td>
							<select name="cache_lifespan">
								<?php foreach ( $lifespans as $seconds => $label ) : ?>
									<option value="<?php echo (int) $seconds; ?>" <?php selected( (int) $s['cache_lifespan'], $seconds ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">I file più vecchi vengono eliminati da un cron orario e rigenerati alla visita successiva (o dal preload).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Cache mobile separata</th>
						<td>
							<label><input type="checkbox" name="cache_mobile_separate" value="1" <?php checked( $s['cache_mobile_separate'] ); ?>> File separati per dispositivi mobili (serve solo con temi non responsive)</label>
						</td>
					</tr>
					<tr>
						<th scope="row">URI esclusi</th>
						<td>
							<textarea name="exclude_uris" rows="4" class="large-text code" placeholder="/carrello/&#10;/area-riservata/.*"><?php echo esc_textarea( implode( "\n", (array) $s['exclude_uris'] ) ); ?></textarea>
							<p class="description">Una regex per riga, confrontata con il percorso dell'URL.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Parametri query ignorati</th>
						<td>
							<textarea name="ignored_query_params" rows="3" class="large-text code" placeholder="Vuoto = lista predefinita (utm_*, fbclid, gclid...)"><?php echo esc_textarea( implode( "\n", (array) $s['ignored_query_params'] ) ); ?></textarea>
							<p class="description">URL con solo questi parametri ricevono la cache della pagina base. Vuoto = lista predefinita.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Cookie che escludono dalla cache</th>
						<td>
							<textarea name="skip_cookies_extra" rows="3" class="large-text code" placeholder="nome_cookie_"><?php echo esc_textarea( implode( "\n", (array) $s['skip_cookies_extra'] ) ); ?></textarea>
							<p class="description">Un prefisso di nome cookie per riga, in aggiunta a quelli predefiniti (<code><?php echo esc_html( implode( ', ', SKIT_Config::default_skip_cookies() ) ); ?></code>). Chi ha uno di questi cookie non riceve né genera pagine in cache.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Regole server (.htaccess)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Serve diretto</th>
						<td>
							<label><input type="checkbox" name="htaccess_direct_serve" value="1" <?php checked( $s['htaccess_direct_serve'] ); ?>> Servi i file di cache direttamente da Apache senza avviare PHP (più veloce)</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Browser cache</th>
						<td>
							<label><input type="checkbox" name="htaccess_browser_cache" value="1" <?php checked( $s['htaccess_browser_cache'] ); ?>> Intestazioni Expires/Cache-Control per gli asset statici</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Compressione gzip</th>
						<td>
							<label><input type="checkbox" name="htaccess_gzip" value="1" <?php checked( $s['htaccess_gzip'] ); ?>> Comprimi HTML, CSS, JS e font con mod_deflate</label>
						</td>
					</tr>
				</table>

				<h2 class="title">Ottimizzazioni</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Minify HTML</th>
						<td><label><input type="checkbox" name="minify_html" value="1" <?php checked( $s['minify_html'] ); ?>> Rimuovi spazi e commenti dall'HTML in cache</label></td>
					</tr>
					<tr>
						<th scope="row">Defer JavaScript</th>
						<td>
							<label><input type="checkbox" name="defer_js" value="1" <?php checked( $s['defer_js'] ); ?>> Carica gli script esterni con attributo <code>defer</code></label>
							<textarea name="defer_js_exclude" rows="4" class="large-text code" placeholder="/wp-content/plugins/mio-plugin/(.*).js"><?php echo esc_textarea( implode( "\n", (array) $s['defer_js_exclude'] ) ); ?></textarea>
							<p class="description">Esclusioni: una regex per riga sull'URL dello script. jQuery è escluso automaticamente.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Delay JavaScript</th>
						<td>
							<label><input type="checkbox" name="delay_js" value="1" <?php checked( $s['delay_js'] ); ?>> Rinvia l'esecuzione JS alla prima interazione (sostituisce il defer)</label>
							<textarea name="delay_js_exclude" rows="3" class="large-text code" placeholder="stringa contenuta nel tag script da escludere"><?php echo esc_textarea( implode( "\n", (array) $s['delay_js_exclude'] ) ); ?></textarea>
							<p class="description">Molto aggressivo: testa bene analytics, consensi e script critici. Esclusioni: una stringa per riga.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Lazyload iframe</th>
						<td><label><input type="checkbox" name="lazyload_iframes" value="1" <?php checked( $s['lazyload_iframes'] ); ?>> Aggiungi <code>loading="lazy"</code> agli iframe</label></td>
					</tr>
					<tr>
						<th scope="row">DNS prefetch</th>
						<td>
							<textarea name="dns_prefetch" rows="3" class="large-text code" placeholder="fonts.googleapis.com&#10;www.googletagmanager.com"><?php echo esc_textarea( implode( "\n", (array) $s['dns_prefetch'] ) ); ?></textarea>
							<p class="description">Un host per riga: viene aggiunto <code>&lt;link rel="dns-prefetch"&gt;</code>.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Media</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Lazyload immagini</th>
						<td>
							<label><input type="checkbox" name="lazyload_images" value="1" <?php checked( $s['lazyload_images'] ); ?>> Aggiungi <code>loading="lazy"</code> e <code>decoding="async"</code> alle immagini</label>
							<p>
								<label>Salta le prime <input type="number" name="lazyload_skip_first" value="<?php echo (int) $s['lazyload_skip_first']; ?>" min="0" max="10" style="width:60px"> immagini</label>
							</p>
							<p class="description">Le prime immagini (logo, immagine in evidenza / LCP) devono caricarsi subito. Escludi singole immagini con la classe <code>skip-lazy</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Facade YouTube</th>
						<td>
							<label><input type="checkbox" name="youtube_facade" value="1" <?php checked( $s['youtube_facade'] ); ?>> Sostituisci gli iframe YouTube con la thumbnail; il player si carica solo al click</label>
							<p class="description">Risparmia ~800 KB di JavaScript per ogni video incorporato.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Asset statici</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Minify CSS</th>
						<td>
							<label><input type="checkbox" name="minify_css" value="1" <?php checked( $s['minify_css'] ); ?>> Minifica i fogli di stile locali (i <code>.min.css</code> vengono saltati)</label>
							<textarea name="minify_css_exclude" rows="2" class="large-text code" placeholder="regex sull'URL del CSS da escludere"><?php echo esc_textarea( implode( "\n", (array) $s['minify_css_exclude'] ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">Google Fonts</th>
						<td><label><input type="checkbox" name="font_display_swap" value="1" <?php checked( $s['font_display_swap'] ); ?>> Aggiungi <code>display=swap</code> e preconnect a fonts.gstatic.com</label></td>
					</tr>
					<tr>
						<th scope="row">Preload font</th>
						<td>
							<textarea name="preload_fonts" rows="2" class="large-text code" placeholder="/wp-content/themes/tema/fonts/titolo.woff2"><?php echo esc_textarea( implode( "\n", (array) $s['preload_fonts'] ) ); ?></textarea>
							<p class="description">URL dei font critici (uno per riga): vengono precaricati con <code>&lt;link rel="preload"&gt;</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Emoji</th>
						<td><label><input type="checkbox" name="disable_emojis" value="1" <?php checked( $s['disable_emojis'] ); ?>> Rimuovi lo script emoji di WordPress (i browser le rendono nativamente)</label></td>
					</tr>
					<tr>
						<th scope="row">Preload link</th>
						<td>
							<label><input type="checkbox" name="preload_links" value="1" <?php checked( $s['preload_links'] ); ?>> Prefetch delle pagine interne al passaggio del mouse sui link</label>
							<p class="description">La navigazione successiva parte dalla cache del browser (stile instant.page).</p>
						</td>
					</tr>
				</table>

				<h2 class="title">CDN</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Riscrittura URL</th>
						<td>
							<label><input type="checkbox" name="cdn_enabled" value="1" <?php checked( $s['cdn_enabled'] ); ?>> Servi gli asset statici da un host CDN</label>
							<p>
								<input type="text" name="cdn_host" value="<?php echo esc_attr( $s['cdn_host'] ); ?>" class="regular-text code" placeholder="cdn.tuosito.it">
							</p>
							<p class="description">Solo l'hostname del CDN/pull-zone. Vengono riscritti gli URL sotto <code>/wp-content/</code> e <code>/wp-includes/</code>.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Database</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Pulizia automatica</th>
						<td>
							<label><input type="checkbox" name="db_auto_cleanup" value="1" <?php checked( $s['db_auto_cleanup'] ); ?>> Pulizia settimanale: revisioni, bozze automatiche, cestino, spam, transient scaduti</label>
							<p class="description">Attenzione: revisioni e cestino vengono eliminati definitivamente. Puoi anche lanciarla manualmente dagli Strumenti qui sotto.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Preload</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Preload automatico</th>
						<td>
							<label><input type="checkbox" name="preload_enabled" value="1" <?php checked( $s['preload_enabled'] ); ?>> Rigenera la cache dalla sitemap dopo ogni svuotamento completo</label>
						</td>
					</tr>
					<tr>
						<th scope="row">URL sitemap</th>
						<td>
							<input type="url" name="preload_sitemap" value="<?php echo esc_attr( $s['preload_sitemap'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( home_url( '/sitemap_index.xml' ) ); ?>">
							<p class="description">Vuoto = rilevamento automatico (sitemap_index.xml, sitemap.xml, wp-sitemap.xml).</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Salva impostazioni' ); ?>
			</form>

			<h2 class="title">Strumenti</h2>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_purge_all' ), 'skit_purge_all' ) ); ?>">Svuota tutta la cache</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_preload' ), 'skit_preload' ) ); ?>">Avvia preload</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_reinstall' ), 'skit_reinstall' ) ); ?>">Reinstalla componenti</a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Eliminare definitivamente revisioni, cestino, spam e transient scaduti?');">
				<input type="hidden" name="action" value="skit_db_cleanup">
				<?php wp_nonce_field( 'skit_db_cleanup' ); ?>
				<label><input type="checkbox" name="skit_confirm_db" value="1" required> Ho capito che revisioni, bozze automatiche vecchie, cestino, spam e transient scaduti vengono eliminati definitivamente.</label>
				<?php submit_button( 'Pulisci database ora', 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">"Reinstalla componenti" riscrive drop-in advanced-cache.php, WP_CACHE, regole .htaccess e file di configurazione.</p>
		</div>
		<?php
	}

	/**
	 * Conteggio file e dimensione della cache.
	 *
	 * @return array{files:int,size:int}
	 */
	private function cache_stats() {
		$files = 0;
		$size  = 0;
		if ( is_dir( SKIT_CACHE_DIR ) ) {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SKIT_CACHE_DIR, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $items as $item ) {
				if ( $item->isFile() && 'html' === strtolower( $item->getExtension() ) ) {
					$files++;
					$size += $item->getSize();
				}
			}
		}

		return array(
			'files' => $files,
			'size'  => $size,
		);
	}
}
