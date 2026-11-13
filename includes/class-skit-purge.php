<?php
/**
 * Purge — Cache invalidation: automatic on WordPress events (post saves,
 * comments, terms, theme, menus) and manual from the admin. Also contains
 * the cron garbage collector for expired files.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Purge {

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Permalinks captured before a post update/trash, keyed by post ID.
	 * After unpublishing, trashing or a slug change get_permalink() no longer
	 * returns the URL that is in the cache.
	 *
	 * @var array<int,string>
	 */
	private $old_urls = array();

	/**
	 * URLs already purged in this request (save_post and
	 * transition_post_status both fire on publish).
	 *
	 * @var array<string,bool>
	 */
	private $purged = array();

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the invalidation hooks.
	 */
	public function init() {
		// Content.
		add_action( 'pre_post_update', array( $this, 'remember_old_url' ) );
		add_action( 'wp_trash_post', array( $this, 'remember_old_url' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_delete_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// Comments (they change the content of the post page).
		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );

		// Terms / taxonomies.
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		// Before deletion: afterwards get_term_link() fails.
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );

		// Global changes: the whole domain.
		add_action( 'switch_theme', array( $this, 'clean_domain' ) );
		add_action( 'customize_save_after', array( $this, 'clean_domain' ) );
		add_action( 'permalink_structure_changed', array( $this, 'clean_domain' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'clean_domain' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'clean_domain' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clean_domain' ) );

		// Garbage collector for expired files.
		add_action( 'skit_cache_gc', array( $this, 'garbage_collect' ) );

		// Reverse proxy detection (admin requests only).
		add_action( 'admin_init', array( __CLASS__, 'detect_varnish_env' ) );
	}

	/* ---------------------------------------------------------------------
	 * Event handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Stores the current permalink of a published post right before it is
	 * updated or trashed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remember_old_url( $post_id ) {
		// Keep the first capture: wp_trash_post() calls wp_update_post(), and by
		// then the slug may already carry the "__trashed" suffix.
		if ( isset( $this->old_urls[ (int) $post_id ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			$this->old_urls[ $post->ID ] = $url;
		}
	}

	/**
	 * Term about to be deleted: purge its archive while the link still resolves.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ) {
		$this->on_edited_term( $term_id, 0, $taxonomy );
	}

	/**
	 * Post saved/updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$this->clean_post( $post );
	}

	/**
	 * Post permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post (since WP 5.5).
	 */
	public function on_delete_post( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
			$this->clean_post( $post );
		}
	}

	/**
	 * Post trashed: its URL goes away, purge the post and related archives.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_trashed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
			$this->clean_post( $post );
		}
	}

	/**
	 * Publish/unpublish.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$this->clean_post( $post );
	}

	/**
	 * New approved comment.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   1 if approved.
	 */
	public function on_comment( $comment_id, $approved ) {
		if ( 1 !== (int) $approved ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			$this->clean_url( (string) get_permalink( (int) $comment->comment_post_ID ), true );
		}
	}

	/**
	 * Comment status change (approval/removal).
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_comment_status( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status || ( 'approved' !== $new_status && 'approved' !== $old_status ) ) {
			return;
		}
		if ( $comment && $comment->comment_post_ID ) {
			$this->clean_url( (string) get_permalink( (int) $comment->comment_post_ID ), true );
		}
	}

	/**
	 * Term edited: purge its archive.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->publicly_queryable ) {
			return;
		}
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->clean_url( (string) $link, true );
		}
	}

	/* ---------------------------------------------------------------------
	 * Purge operations
	 * ------------------------------------------------------------------ */

	/**
	 * Full purge of the current domain. Equivalent of rocket_clean_domain.
	 */
	public function clean_domain() {
		$host = SKIT_Config::current_host();
		if ( '' === $host ) {
			return;
		}
		self::delete_dir( SKIT_CACHE_DIR . $host );
		$this->varnish_purge( home_url( '/' ), 'all' );

		/**
		 * Fires after a full purge (used e.g. to start the preload).
		 */
		do_action( 'skit_after_clean_domain' );
	}

	/**
	 * Purges a post and all related URLs: home, term archives, author
	 * archive, date archives. Equivalent of rocket_clean_post.
	 *
	 * @param WP_Post $post Post.
	 */
	public function clean_post( $post ) {
		$urls = array();

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		// Home page and its pagination.
		$urls[] = home_url( '/' );

		// Post type archive.
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}

		// Archives of the assigned terms.
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		// Author archive.
		$author = get_author_posts_url( (int) $post->post_author );
		if ( $author ) {
			$urls[] = $author;
		}

		// Date archives (posts only).
		if ( 'post' === $post->post_type ) {
			$year  = get_the_time( 'Y', $post );
			$month = get_the_time( 'm', $post );
			$urls[] = get_year_link( $year );
			$urls[] = get_month_link( $year, $month );
		}

		// URL the post had before this update (unpublish, trash, slug change).
		if ( isset( $this->old_urls[ $post->ID ] ) ) {
			$urls[] = $this->old_urls[ $post->ID ];
		}

		/**
		 * Filters the URLs purged when a post changes.
		 *
		 * @param string[] $urls
		 * @param WP_Post  $post
		 */
		$urls = apply_filters( 'skit_clean_post_urls', array_values( array_unique( array_filter( $urls ) ) ), $post );

		$new = 0;
		foreach ( $urls as $url ) {
			// Same post version + same URL = already done in this request. A
			// later update (bulk import, WP-CLI) changes post_modified.
			$key = $post->ID . '|' . $post->post_modified_gmt . '|' . $post->post_status . '|' . $url;
			if ( isset( $this->purged[ $key ] ) ) {
				continue;
			}
			$this->purged[ $key ] = true;
			++$new;
			// true = also the pagination subpages (/page/2/...).
			$this->clean_url( (string) $url, true );
		}
		if ( 0 === $new ) {
			return;
		}

		/**
		 * Fires after a post purge (used for the targeted preload).
		 *
		 * @param WP_Post  $post
		 * @param string[] $urls
		 */
		do_action( 'skit_after_clean_post', $post, $urls );
	}

	/**
	 * Deletes the cache files of a single URL.
	 *
	 * @param string $url             Full URL.
	 * @param bool   $with_pagination True to also delete /page/N/.
	 */
	public function clean_url( $url, $with_pagination = false ) {
		$pagination_base = 'page';
		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite && ! empty( $wp_rewrite->pagination_base ) ) {
			$pagination_base = $wp_rewrite->pagination_base;
		}

		// The proxy (Varnish) first: it must be invalidated even when the disk
		// cache has nothing for this URL. Pagination is a
		// separate, narrow regex purge: a regex on the URL itself would flush
		// the whole site when the URL is the home page.
		$this->varnish_purge( $url, 'url' );
		if ( $with_pagination ) {
			$this->varnish_purge( trailingslashit( $url ) . $pagination_base . '/', 'subtree' );
		}

		$dir = self::url_to_cache_dir( $url );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		// Delete the index* files (all variants: https, mobile, gzip).
		foreach ( (array) glob( $dir . '/index*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( $with_pagination ) {
			self::delete_dir( $dir . '/' . $pagination_base );
		}
	}

	/**
	 * Varnish purge mode actually in use: 'cloudways', 'generic' or 'off'.
	 *
	 * "auto" (default) follows what was detected on the server, see
	 * detect_varnish_env(): nothing is sent when no Varnish was seen.
	 *
	 * @return string
	 */
	private function varnish_mode() {
		if ( defined( 'SKIT_NO_VARNISH' ) && SKIT_NO_VARNISH ) {
			return 'off';
		}

		$mode = isset( $this->settings['varnish_mode'] ) ? (string) $this->settings['varnish_mode'] : 'auto';
		if ( 'auto' === $mode ) {
			$env  = (string) get_option( 'skit_varnish_env', 'none' );
			$mode = in_array( $env, array( 'cloudways', 'generic' ), true ) ? $env : 'off';
		}

		/**
		 * Filters whether Varnish purge requests are sent at all. Returning
		 * true while the mode is "off" uses the generic mode.
		 *
		 * @param bool $enabled True when a purge mode is active.
		 */
		if ( ! apply_filters( 'skit_varnish_enabled', 'off' !== $mode ) ) {
			return 'off';
		}

		return 'off' === $mode ? 'generic' : $mode;
	}

	/**
	 * Records which reverse proxy sits in front of the site, read from the
	 * request headers of an administrator's request (headers of anonymous
	 * requests can be forged, so they are never used).
	 *
	 * Same signals Breeze (the Cloudways plugin) uses: Varnish adds X-Varnish
	 * to backend requests; Cloudways also adds X-Application, which is
	 * "varnishpass" or "bypass" when Varnish is disabled for the app.
	 */
	public static function detect_varnish_env() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$app = isset( $_SERVER['HTTP_X_APPLICATION'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_APPLICATION'] ) ) ) : null;
		$env = '';
		if ( null !== $app && in_array( $app, array( 'varnishpass', 'bypass' ), true ) ) {
			$env = 'none';
		} elseif ( isset( $_SERVER['HTTP_X_VARNISH'] ) ) {
			$env = null === $app ? 'generic' : 'cloudways';
		}

		if ( '' !== $env && get_option( 'skit_varnish_env' ) !== $env ) {
			update_option( 'skit_varnish_env', $env );
		}
	}

	/**
	 * Invalidates a URL on the reverse proxy (Varnish) too.
	 *
	 * - cloudways: same requests as Breeze, the official Cloudways plugin:
	 *   URLPURGE for a single URL and PURGE "/.*" for the whole application,
	 *   sent to the local Varnish (127.0.0.1) with the site's Host header,
	 *   so that a CDN in front of the site is never involved. Cloudways has
	 *   no partial regex purge: pagination pages expire with Varnish's TTL.
	 * - generic: the Proxy Cache Purge convention: PURGE with an
	 *   X-Purge-Method header (default | regex), sent to the site host or to
	 *   SKIT_VARNISH_IP.
	 *
	 * Never blocks the save: non-blocking request, short timeout.
	 * Can be disabled with `define( 'SKIT_NO_VARNISH', true )`, with the
	 * "Varnish purge" setting or with the `skit_varnish_enabled` filter.
	 *
	 * @param string $url   Full URL to invalidate.
	 * @param string $scope 'url' (this URL), 'subtree' (regex on everything
	 *                      below the URL) or 'all' (whole site).
	 */
	private function varnish_purge( $url, $scope = 'url' ) {
		$mode = $this->varnish_mode();
		if ( 'off' === $mode ) {
			return;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return;
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		$ip     = defined( 'SKIT_VARNISH_IP' ) && SKIT_VARNISH_IP ? (string) SKIT_VARNISH_IP : '';
		$host   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

		if ( 'cloudways' === $mode ) {
			if ( 'subtree' === $scope ) {
				return;
			}
			$method  = 'all' === $scope ? 'PURGE' : 'URLPURGE';
			$target  = $scheme . '://' . ( '' !== $ip ? $ip : '127.0.0.1' ) . ( 'all' === $scope ? '/.*' : $path );
			$headers = array( 'Host' => $parts['host'] );
		} else {
			$regex   = 'url' !== $scope;
			$target  = $scheme . '://' . ( '' !== $ip ? $ip : $host ) . ( 'all' === $scope ? '/' : $path ) . ( $regex ? '.*' : '' );
			$method  = 'PURGE';
			$headers = array(
				'Host'           => $host,
				'X-Purge-Method' => $regex ? 'regex' : 'default',
			);
		}

		/**
		 * Filters a Varnish purge request before it is sent. Return an empty
		 * 'url' to skip it.
		 *
		 * @param array  $request { @type string $url Target URL. @type array $args wp_remote_request() arguments. }
		 * @param string $url     URL being purged.
		 * @param string $scope   'url', 'subtree' or 'all'.
		 * @param string $mode    'cloudways' or 'generic'.
		 */
		$request = (array) apply_filters(
			'skit_varnish_purge_request',
			array(
				'url'  => $target,
				'args' => array(
					'method'    => $method,
					'blocking'  => false,
					'timeout'   => 3,
					'sslverify' => false, // Local proxy, usually without a valid certificate for its IP.
					'headers'   => $headers,
				),
			),
			$url,
			$scope,
			$mode
		);
		if ( empty( $request['url'] ) ) {
			return;
		}

		wp_remote_request( (string) $request['url'], isset( $request['args'] ) ? (array) $request['args'] : array() );
	}

	/**
	 * Converts a URL into the matching cache directory.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	public static function url_to_cache_dir( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '/[^a-z0-9.\-:]/i', '', $parts['host'] ) );
		$host = str_replace( ':', '-', $host );
		if ( ! empty( $parts['port'] ) ) {
			$host .= '-' . (int) $parts['port'];
		}
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '/';
		$path = '/' . ltrim( $path, '/' );
		$path = rtrim( $path, '/' );
		// Path traversal: never accept ".." (str_replace can be bypassed).
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
			return '';
		}

		return SKIT_CACHE_DIR . $host . $path;
	}

	/**
	 * Recursively deletes a directory (limited to the cache directory).
	 *
	 * @param string $dir Directory to delete.
	 */
	public static function delete_dir( $dir ) {
		$dir  = untrailingslashit( $dir );
		$base = untrailingslashit( SKIT_CACHE_DIR );

		// Safety: never leave the cache directory. The comparison is done on
		// resolved paths (realpath), not on strings: "base/../x" would pass a
		// plain prefix check.
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$real_dir  = realpath( $dir );
		$real_base = realpath( $base );
		if ( false === $real_dir || false === $real_base || 0 !== strpos( $real_dir . '/', $real_base . '/' ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Cron: deletes the files older than the configured lifespan.
	 */
	public function garbage_collect() {
		$lifespan = (int) $this->settings['cache_lifespan'];
		if ( $lifespan <= 0 || ! is_dir( SKIT_CACHE_DIR ) ) {
			return;
		}

		$cutoff = time() - $lifespan;
		$root   = untrailingslashit( SKIT_CACHE_DIR );
		$items  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( SKIT_CACHE_DIR, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		$assets_dir = $root . '/_assets';
		foreach ( $items as $item ) {
			if ( $item->isFile() && $item->getMTime() < $cutoff ) {
				// Files in the root (protective .htaccess and index.html) are not
				// cache files: never touch them.
				if ( untrailingslashit( dirname( $item->getPathname() ) ) === $root ) {
					continue;
				}
				// Minified CSS files in _assets/ are referenced by cached pages:
				// they go only with a full purge, never by age.
				if ( 0 === strpos( $item->getPathname(), $assets_dir ) ) {
					continue;
				}
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( $item->isDir() ) {
				// Removes directories left empty.
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
