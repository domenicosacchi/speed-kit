<?php
/**
 * Admin — Settings page, admin bar (purge cache / preload), manual actions
 * and installation status notices.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Admin {

	/**
	 * Plugin core.
	 *
	 * @var SKIT_Core
	 */
	private $core;

	public function __construct( SKIT_Core $core ) {
		$this->core = $core;
	}

	/**
	 * Registers the hooks (the admin bar also on the front end).
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

			// Self-repair: the GC is the only expiry for files served directly
			// by Apache; if it is missing (e.g. activation stopped by a WP
			// Rocket conflict) it gets scheduled again.
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
	 * Admin bar entries.
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
				'title'  => esc_html__( 'Purge all cache', 'speed-kit' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=skit_purge_all' ), 'skit_purge_all' ),
			)
		);

		if ( ! is_admin() ) {
			$current = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
			$bar->add_node(
				array(
					'parent' => 'speed-kit',
					'id'     => 'skit-purge-url',
					'title'  => esc_html__( 'Purge this page', 'speed-kit' ),
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
				'title'  => esc_html__( 'Start cache preload', 'speed-kit' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=skit_preload' ), 'skit_preload' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Manual actions
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
	 * Checks capability and nonce for an admin-post action.
	 *
	 * @param string $action Action/nonce name.
	 */
	private function verify_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'speed-kit' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Goes back to the previous page (or the settings page) with a result flag.
	 *
	 * @param string $message Message code.
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

	/**
	 * Prints a notice. $message must already be escaped.
	 *
	 * @param string $type        notice-error | notice-warning | notice-success.
	 * @param string $message     Escaped HTML.
	 * @param bool   $dismissible Whether the notice can be dismissed.
	 */
	private function print_notice( $type, $message, $dismissible = false ) {
		printf(
			'<div class="notice %1$s%2$s"><p><strong>SpeedKit:</strong> %3$s</p></div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		);
	}

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reinstall_url  = wp_nonce_url( admin_url( 'admin-post.php?action=skit_reinstall' ), 'skit_reinstall' );
		$reinstall_link = '<a href="' . esc_url( $reinstall_url ) . '">' . esc_html__( 'Reinstall components', 'speed-kit' ) . '</a>';

		$conflict = get_transient( 'skit_activation_conflict' );
		if ( 'wp-rocket' === $conflict ) {
			$this->print_notice( 'notice-error', esc_html__( 'WP Rocket is still active. Deactivate it, then activate SpeedKit again (or use "Reinstall components" in the settings) to install the drop-in and the rules.', 'speed-kit' ) );
		} elseif ( 'dropin' === $conflict ) {
			$this->print_notice( 'notice-error', esc_html__( 'An advanced-cache.php file from another cache plugin is present. Remove it and use "Reinstall components" in the SpeedKit settings.', 'speed-kit' ) );
		}

		// Other page cache plugins active at the same time.
		$others = $this->active_cache_plugins();
		if ( $others && 'wp-rocket' !== $conflict ) {
			$this->print_notice(
				'notice-error',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of plugin names. */
						__( 'Other cache plugins are active (%s). Two page caches overwrite each other: deactivate one of them.', 'speed-kit' ),
						implode( ', ', $others )
					)
				)
			);
		}

		// Drop-in status: missing or a different version.
		if ( ! $conflict && ! empty( $this->core->settings['cache_enabled'] ) ) {
			if ( ! defined( 'SKIT_ADVANCED_CACHE' ) ) {
				$this->print_notice( 'notice-warning', esc_html__( 'The advanced-cache.php drop-in is not active (WP_CACHE missing or file absent): pages are not served from the cache.', 'speed-kit' ) . ' ' . $reinstall_link . '.' );
			} elseif ( SKIT_ADVANCED_CACHE !== SKIT_VERSION && defined( 'SKIT_ADVANCED_CACHE' ) ) {
				// Self-repair: after a plugin update the drop-in is still the old
				// copy; copying it again is idempotent and safe (install_dropin
				// refuses other plugins' drop-ins).
				if ( SKIT_Activator::install_dropin() ) {
					$this->print_notice(
						'notice-success',
						esc_html(
							sprintf(
								/* translators: 1: old drop-in version, 2: plugin version. */
								__( 'advanced-cache.php drop-in updated automatically from version %1$s to %2$s. Reload the page to confirm.', 'speed-kit' ),
								SKIT_ADVANCED_CACHE,
								SKIT_VERSION
							)
						)
					);
				} else {
					$this->print_notice(
						'notice-warning',
						esc_html(
							sprintf(
								/* translators: 1: drop-in version, 2: plugin version. */
								__( 'The advanced-cache.php drop-in (version %1$s) does not match the plugin (version %2$s) and the automatic copy failed: check the permissions of wp-content.', 'speed-kit' ),
								SKIT_ADVANCED_CACHE,
								SKIT_VERSION
							)
						) . ' ' . $reinstall_link . '.'
					);
				}
			}
		}

		if ( isset( $_GET['skit_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = array(
				'purged'      => __( 'Cache purged.', 'speed-kit' ),
				'preload'     => __( 'Preload started: the cache will be rebuilt in the background.', 'speed-kit' ),
				'saved'       => __( 'Settings saved. Cache purged and configuration regenerated.', 'speed-kit' ),
				'reinstalled' => __( 'Components reinstalled (drop-in, WP_CACHE, .htaccess rules, config).', 'speed-kit' ),
				'db_cleaned'  => __( 'Database cleaned.', 'speed-kit' ),
			);
			$db_result = get_transient( 'skit_db_cleanup_result' );
			if ( is_array( $db_result ) ) {
				$labels = array(
					'revisions'          => __( 'revisions', 'speed-kit' ),
					'auto_drafts'        => __( 'auto-drafts', 'speed-kit' ),
					'trashed_posts'      => __( 'trashed posts', 'speed-kit' ),
					'spam_comments'      => __( 'spam/trashed comments', 'speed-kit' ),
					'expired_transients' => __( 'expired transients', 'speed-kit' ),
				);
				$parts  = array();
				foreach ( $db_result as $key => $count ) {
					$parts[] = (int) $count . ' ' . ( isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key );
				}
				/* translators: %s: comma-separated list such as "3 revisions, 1 auto-drafts". */
				$messages['db_cleaned'] = sprintf( __( 'Database cleaned: %s.', 'speed-kit' ), implode( ', ', $parts ) );
				delete_transient( 'skit_db_cleanup_result' );
			}
			$key = sanitize_key( wp_unslash( $_GET['skit_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'db_not_confirmed' === $key ) {
				$this->print_notice( 'notice-warning', esc_html__( 'Database cleanup not run: tick the confirmation checkbox.', 'speed-kit' ), true );
			} elseif ( isset( $messages[ $key ] ) ) {
				$this->print_notice( 'notice-success', esc_html( $messages[ $key ] ), true );
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
				'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
				'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
				'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
				'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
				'breeze/breeze.php'                          => 'Breeze',
				'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
				'comet-cache/comet-cache.php'                => 'Comet Cache',
				'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
				'sg-cachepress/sg-cachepress.php'            => 'Speed Optimizer (SiteGround)',
				'wp-optimize/wp-optimize.php'                => 'WP-Optimize',
				'swift-performance-lite/performance.php'     => 'Swift Performance Lite',
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
	 * Quick link in the plugin list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=speed-kit' ) ) . '">' . esc_html__( 'Settings', 'speed-kit' ) . '</a>' );

		return $links;
	}

	/* ---------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------ */

	public function menu() {
		add_options_page( 'SpeedKit', 'SpeedKit', 'manage_options', 'speed-kit', array( $this, 'render_page' ) );
	}

	/**
	 * Saves the settings.
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

		// Invalid user regexes are dropped (same logic as WP Rocket).
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

		$out['varnish_mode'] = isset( $in['varnish_mode'] ) && in_array( $in['varnish_mode'], array( 'auto', 'cloudways', 'generic', 'off' ), true ) ? $in['varnish_mode'] : 'auto';

		$out['preload_sitemap'] = isset( $in['preload_sitemap'] ) ? esc_url_raw( trim( (string) $in['preload_sitemap'] ) ) : '';

		update_option( SKIT_OPTION_KEY, $out );

		// Regenerate everything and start clean.
		SKIT_Config::write_config_file();
		SKIT_Htaccess::write_rules( $out );
		$purge = new SKIT_Purge( $out );
		$purge->clean_domain();

		wp_safe_redirect( add_query_arg( 'skit_msg', 'saved', admin_url( 'options-general.php?page=speed-kit' ) ) );
		exit;
	}

	/**
	 * Textarea -> array of clean lines.
	 *
	 * No sanitize_text_field(): it would mangle user regexes (e.g. "<").
	 * Lines are only stripped of control characters; invalid regexes are
	 * dropped anyway by the preg validation afterwards.
	 *
	 * @param string $raw Raw text.
	 * @return string[]
	 */
	private function sanitize_lines( $raw ) {
		$raw   = (string) wp_check_invalid_utf8( $raw );
		$raw   = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		return array_values( $lines );
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page() {
		$s = SKIT_Config::get_settings();

		$lifespans = array(
			0                    => __( 'Never (automatic purge only)', 'speed-kit' ),
			HOUR_IN_SECONDS      => __( '1 hour', 'speed-kit' ),
			5 * HOUR_IN_SECONDS  => __( '5 hours', 'speed-kit' ),
			10 * HOUR_IN_SECONDS => __( '10 hours (recommended)', 'speed-kit' ),
			DAY_IN_SECONDS       => __( '24 hours', 'speed-kit' ),
			WEEK_IN_SECONDS      => __( '7 days', 'speed-kit' ),
		);

		$code  = array( 'code' => array() );
		$stats = $this->cache_stats();
		?>
		<div class="wrap">
			<h1>SpeedKit</h1>
			<p>
				<?php
				printf(
					/* translators: 1: number of cached pages, 2: total size (e.g. "1 MB"). */
					esc_html__( 'Page cache: %1$s files, %2$s.', 'speed-kit' ),
					'<strong>' . (int) $stats['files'] . '</strong>',
					'<strong>' . esc_html( size_format( $stats['size'] ) ) . '</strong>'
				);
				?>
				<?php esc_html_e( 'Drop-in:', 'speed-kit' ); ?> <strong><?php echo defined( 'SKIT_ADVANCED_CACHE' ) ? esc_html__( 'active', 'speed-kit' ) : esc_html__( 'NOT active', 'speed-kit' ); ?></strong>.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="skit_save_settings">
				<?php wp_nonce_field( 'skit_save_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Page cache', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page cache', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="cache_enabled" value="1" <?php checked( $s['cache_enabled'] ); ?>> <?php esc_html_e( 'Enable the disk cache for pages', 'speed-kit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lifespan', 'speed-kit' ); ?></th>
						<td>
							<select name="cache_lifespan">
								<?php foreach ( $lifespans as $seconds => $label ) : ?>
									<option value="<?php echo (int) $seconds; ?>" <?php selected( (int) $s['cache_lifespan'], $seconds ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Older files are deleted by an hourly cron job and rebuilt on the next visit (or by the preload).', 'speed-kit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Separate mobile cache', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="cache_mobile_separate" value="1" <?php checked( $s['cache_mobile_separate'] ); ?>> <?php esc_html_e( 'Separate files for mobile devices (only needed with non-responsive themes)', 'speed-kit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded URIs', 'speed-kit' ); ?></th>
						<td>
							<textarea name="exclude_uris" rows="4" class="large-text code" placeholder="/cart/&#10;/members/.*"><?php echo esc_textarea( implode( "\n", (array) $s['exclude_uris'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One regex per line, matched against the URL path.', 'speed-kit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ignored query parameters', 'speed-kit' ); ?></th>
						<td>
							<textarea name="ignored_query_params" rows="3" class="large-text code" placeholder="<?php esc_attr_e( 'Empty = default list (utm_*, fbclid, gclid...)', 'speed-kit' ); ?>"><?php echo esc_textarea( implode( "\n", (array) $s['ignored_query_params'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'URLs that only carry these parameters get the cache of the base page. Empty = default list.', 'speed-kit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cookies that bypass the cache', 'speed-kit' ); ?></th>
						<td>
							<textarea name="skip_cookies_extra" rows="3" class="large-text code" placeholder="cookie_name_"><?php echo esc_textarea( implode( "\n", (array) $s['skip_cookies_extra'] ) ); ?></textarea>
							<p class="description">
								<?php
								printf(
									/* translators: %s: list of default cookie name prefixes. */
									esc_html__( 'One cookie name prefix per line, in addition to the defaults (%s). Visitors carrying one of these cookies neither receive nor generate cached pages.', 'speed-kit' ),
									'<code>' . esc_html( implode( ', ', SKIT_Config::default_skip_cookies() ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Server rules (.htaccess)', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Direct serving', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="htaccess_direct_serve" value="1" <?php checked( $s['htaccess_direct_serve'] ); ?>> <?php esc_html_e( 'Serve cache files directly from Apache without starting PHP (faster)', 'speed-kit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Browser cache', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="htaccess_browser_cache" value="1" <?php checked( $s['htaccess_browser_cache'] ); ?>> <?php esc_html_e( 'Expires/Cache-Control headers for static assets', 'speed-kit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gzip compression', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="htaccess_gzip" value="1" <?php checked( $s['htaccess_gzip'] ); ?>> <?php esc_html_e( 'Compress HTML, CSS, JS and fonts with mod_deflate', 'speed-kit' ); ?></label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Optimizations', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Minify HTML', 'speed-kit' ); ?></th>
						<td><label><input type="checkbox" name="minify_html" value="1" <?php checked( $s['minify_html'] ); ?>> <?php esc_html_e( 'Remove whitespace and comments from the cached HTML', 'speed-kit' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Defer JavaScript', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="defer_js" value="1" <?php checked( $s['defer_js'] ); ?>> <?php echo wp_kses( __( 'Load external scripts with the <code>defer</code> attribute', 'speed-kit' ), $code ); ?></label>
							<textarea name="defer_js_exclude" rows="4" class="large-text code" placeholder="/wp-content/plugins/my-plugin/(.*).js"><?php echo esc_textarea( implode( "\n", (array) $s['defer_js_exclude'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Exclusions: one regex per line, matched against the script URL. jQuery is excluded automatically.', 'speed-kit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delay JavaScript', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="delay_js" value="1" <?php checked( $s['delay_js'] ); ?>> <?php esc_html_e( 'Delay JavaScript execution until the first interaction (replaces defer)', 'speed-kit' ); ?></label>
							<textarea name="delay_js_exclude" rows="3" class="large-text code" placeholder="<?php esc_attr_e( 'text found in the script tag to exclude', 'speed-kit' ); ?>"><?php echo esc_textarea( implode( "\n", (array) $s['delay_js_exclude'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Very aggressive: test analytics, consent and critical scripts carefully. Exclusions: one string per line.', 'speed-kit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazyload iframes', 'speed-kit' ); ?></th>
						<td><label><input type="checkbox" name="lazyload_iframes" value="1" <?php checked( $s['lazyload_iframes'] ); ?>> <?php echo wp_kses( __( 'Add <code>loading="lazy"</code> to iframes', 'speed-kit' ), $code ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'DNS prefetch', 'speed-kit' ); ?></th>
						<td>
							<textarea name="dns_prefetch" rows="3" class="large-text code" placeholder="fonts.googleapis.com&#10;www.googletagmanager.com"><?php echo esc_textarea( implode( "\n", (array) $s['dns_prefetch'] ) ); ?></textarea>
							<p class="description"><?php echo wp_kses( __( 'One host per line: a <code>&lt;link rel="dns-prefetch"&gt;</code> is added for each.', 'speed-kit' ), $code ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Media', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazyload images', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="lazyload_images" value="1" <?php checked( $s['lazyload_images'] ); ?>> <?php echo wp_kses( __( 'Add <code>loading="lazy"</code> and <code>decoding="async"</code> to images', 'speed-kit' ), $code ); ?></label>
							<p>
								<label>
									<?php
									printf(
										/* translators: %s: number input. */
										esc_html__( 'Skip the first %s images', 'speed-kit' ),
										'<input type="number" name="lazyload_skip_first" value="' . (int) $s['lazyload_skip_first'] . '" min="0" max="10" style="width:60px">'
									);
									?>
								</label>
							</p>
							<p class="description"><?php echo wp_kses( __( 'The first images (logo, featured image / LCP) must load right away. Images with <code>fetchpriority="high"</code> are never lazyloaded. Exclude single images with the <code>skip-lazy</code> class.', 'speed-kit' ), $code ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'YouTube facade', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="youtube_facade" value="1" <?php checked( $s['youtube_facade'] ); ?>> <?php esc_html_e( 'Replace YouTube iframes with their thumbnail; the player loads only on click', 'speed-kit' ); ?></label>
							<p class="description"><?php esc_html_e( 'The YouTube player and its scripts are not downloaded until the visitor clicks play. The thumbnail is loaded from i.ytimg.com and the player from youtube-nocookie.com.', 'speed-kit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Static assets', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Minify CSS', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="minify_css" value="1" <?php checked( $s['minify_css'] ); ?>> <?php echo wp_kses( __( 'Minify local stylesheets (<code>.min.css</code> files are skipped)', 'speed-kit' ), $code ); ?></label>
							<textarea name="minify_css_exclude" rows="2" class="large-text code" placeholder="<?php esc_attr_e( 'regex on the URL of the CSS file to exclude', 'speed-kit' ); ?>"><?php echo esc_textarea( implode( "\n", (array) $s['minify_css_exclude'] ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Google Fonts', 'speed-kit' ); ?></th>
						<td><label><input type="checkbox" name="font_display_swap" value="1" <?php checked( $s['font_display_swap'] ); ?>> <?php echo wp_kses( __( 'Add <code>display=swap</code> and a preconnect to fonts.gstatic.com', 'speed-kit' ), $code ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preload fonts', 'speed-kit' ); ?></th>
						<td>
							<textarea name="preload_fonts" rows="2" class="large-text code" placeholder="/wp-content/themes/my-theme/fonts/heading.woff2"><?php echo esc_textarea( implode( "\n", (array) $s['preload_fonts'] ) ); ?></textarea>
							<p class="description"><?php echo wp_kses( __( 'URLs of critical fonts (one per line): they are preloaded with <code>&lt;link rel="preload"&gt;</code>.', 'speed-kit' ), $code ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emoji', 'speed-kit' ); ?></th>
						<td><label><input type="checkbox" name="disable_emojis" value="1" <?php checked( $s['disable_emojis'] ); ?>> <?php esc_html_e( 'Remove the WordPress emoji script (browsers render emoji natively)', 'speed-kit' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preload links', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="preload_links" value="1" <?php checked( $s['preload_links'] ); ?>> <?php esc_html_e( 'Prefetch internal pages when the mouse hovers a link', 'speed-kit' ); ?></label>
							<p class="description"><?php echo wp_kses( __( 'The next navigation starts from the browser cache (instant.page style). Links with a query string, <code>rel="nofollow"</code>, <code>download</code> or <code>data-no-prefetch</code> are skipped.', 'speed-kit' ), $code ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'CDN', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'URL rewriting', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="cdn_enabled" value="1" <?php checked( $s['cdn_enabled'] ); ?>> <?php esc_html_e( 'Serve static assets from a CDN host', 'speed-kit' ); ?></label>
							<p>
								<input type="text" name="cdn_host" value="<?php echo esc_attr( $s['cdn_host'] ); ?>" class="regular-text code" placeholder="cdn.example.com">
							</p>
							<p class="description"><?php echo wp_kses( __( 'Only the hostname of the CDN / pull zone. URLs under <code>/wp-content/</code> and <code>/wp-includes/</code> are rewritten.', 'speed-kit' ), $code ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Reverse proxy (Varnish)', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="skit-varnish-mode"><?php esc_html_e( 'Varnish purge', 'speed-kit' ); ?></label></th>
						<td>
							<?php
							$varnish_env    = (string) get_option( 'skit_varnish_env', 'none' );
							$varnish_labels = array(
								'cloudways' => __( 'Cloudways Varnish', 'speed-kit' ),
								'generic'   => __( 'Varnish', 'speed-kit' ),
								'none'      => __( 'none', 'speed-kit' ),
							);
							$varnish_modes  = array(
								/* translators: %s: reverse proxy detected on the server, e.g. "Cloudways Varnish" or "none". */
								'auto'      => sprintf( __( 'Automatic (detected: %s)', 'speed-kit' ), isset( $varnish_labels[ $varnish_env ] ) ? $varnish_labels[ $varnish_env ] : $varnish_labels['none'] ),
								'cloudways' => __( 'Cloudways (URLPURGE / PURGE to 127.0.0.1)', 'speed-kit' ),
								'generic'   => __( 'Generic Varnish (PURGE with X-Purge-Method)', 'speed-kit' ),
								'off'       => __( 'Off', 'speed-kit' ),
							);
							?>
							<select name="varnish_mode" id="skit-varnish-mode">
								<?php foreach ( $varnish_modes as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['varnish_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'When content changes SpeedKit also purges the affected URLs from Varnish. Automatic mode detects Varnish from the headers of your admin requests and sends nothing when no Varnish is found. The target IP can be changed with the SKIT_VARNISH_IP constant.', 'speed-kit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Database', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic cleanup', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="db_auto_cleanup" value="1" <?php checked( $s['db_auto_cleanup'] ); ?>> <?php esc_html_e( 'Weekly cleanup: revisions, auto-drafts older than 7 days, trash, spam, expired transients', 'speed-kit' ); ?></label>
							<p class="description"><?php esc_html_e( 'Warning: revisions and trashed items are deleted permanently. You can also run it manually from the Tools below.', 'speed-kit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Preload', 'speed-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic preload', 'speed-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="preload_enabled" value="1" <?php checked( $s['preload_enabled'] ); ?>> <?php esc_html_e( 'Rebuild the cache from the sitemap after every full purge', 'speed-kit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemap URL', 'speed-kit' ); ?></th>
						<td>
							<input type="url" name="preload_sitemap" value="<?php echo esc_attr( $s['preload_sitemap'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( home_url( '/sitemap_index.xml' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Empty = automatic detection (sitemap_index.xml, sitemap.xml, wp-sitemap.xml).', 'speed-kit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'speed-kit' ) ); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Tools', 'speed-kit' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_purge_all' ), 'skit_purge_all' ) ); ?>"><?php esc_html_e( 'Purge all cache', 'speed-kit' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_preload' ), 'skit_preload' ) ); ?>"><?php esc_html_e( 'Start preload', 'speed-kit' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skit_reinstall' ), 'skit_reinstall' ) ); ?>"><?php esc_html_e( 'Reinstall components', 'speed-kit' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( '"Reinstall components" rewrites the advanced-cache.php drop-in, WP_CACHE, the .htaccess rules and the configuration file.', 'speed-kit' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Permanently delete revisions, old auto-drafts, trash, spam and expired transients?', 'speed-kit' ) ) ); ?>);">
				<input type="hidden" name="action" value="skit_db_cleanup">
				<?php wp_nonce_field( 'skit_db_cleanup' ); ?>
				<p>
					<label><input type="checkbox" name="skit_confirm_db" value="1" required> <?php esc_html_e( 'I understand that revisions, auto-drafts older than 7 days, trashed posts, spam/trashed comments and expired transients are deleted permanently.', 'speed-kit' ); ?></label>
				</p>
				<?php submit_button( __( 'Clean up database now', 'speed-kit' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Number of cached pages and their size.
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
			$root = untrailingslashit( wp_normalize_path( SKIT_CACHE_DIR ) );
			foreach ( $items as $item ) {
				// The empty index.html in the cache root only prevents listing.
				if ( $item->isFile() && 'html' === strtolower( $item->getExtension() ) && wp_normalize_path( $item->getPath() ) !== $root ) {
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
