<?php
/**
 * Purge — Invalidazione della cache: automatica sugli eventi di WordPress
 * (salvataggio post, commenti, termini, tema, menu) e manuale dall'admin.
 * Include il garbage collector cron per i file scaduti.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Purge {

	/**
	 * Impostazioni correnti.
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
	 * Registra gli hook di invalidazione.
	 */
	public function init() {
		// Contenuti.
		add_action( 'pre_post_update', array( $this, 'remember_old_url' ) );
		add_action( 'wp_trash_post', array( $this, 'remember_old_url' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_delete_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// Commenti (cambiano il contenuto della pagina del post).
		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );

		// Termini / tassonomie.
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		// Before deletion: afterwards get_term_link() fails.
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );

		// Cambi globali: tutto il dominio.
		add_action( 'switch_theme', array( $this, 'clean_domain' ) );
		add_action( 'customize_save_after', array( $this, 'clean_domain' ) );
		add_action( 'permalink_structure_changed', array( $this, 'clean_domain' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'clean_domain' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'clean_domain' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clean_domain' ) );

		// Garbage collector file scaduti.
		add_action( 'skit_cache_gc', array( $this, 'garbage_collect' ) );
	}

	/* ---------------------------------------------------------------------
	 * Handler eventi
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
	 * Post salvato/aggiornato.
	 *
	 * @param int     $post_id ID post.
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
	 * Post eliminato definitivamente.
	 *
	 * @param int          $post_id ID post.
	 * @param WP_Post|null $post    Post (dal 5.5).
	 */
	public function on_delete_post( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
			$this->clean_post( $post );
		}
	}

	/**
	 * Post cestinato: l'URL sparisce, purge di post e archivi collegati.
	 *
	 * @param int $post_id ID post.
	 */
	public function on_trashed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
			$this->clean_post( $post );
		}
	}

	/**
	 * Pubblicazione/depubblicazione.
	 *
	 * @param string  $new_status Nuovo stato.
	 * @param string  $old_status Vecchio stato.
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
	 * Nuovo commento approvato.
	 *
	 * @param int        $comment_id ID commento.
	 * @param int|string $approved   1 se approvato.
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
	 * Cambio stato commento (approvazione/rimozione).
	 *
	 * @param string     $new_status Nuovo stato.
	 * @param string     $old_status Vecchio stato.
	 * @param WP_Comment $comment    Commento.
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
	 * Termine modificato/eliminato: purge dell'archivio.
	 *
	 * @param int    $term_id  ID termine.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Tassonomia.
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
	 * Operazioni di pulizia
	 * ------------------------------------------------------------------ */

	/**
	 * Purge completo del dominio corrente. Equivalente di rocket_clean_domain.
	 */
	public function clean_domain() {
		$host = SKIT_Config::current_host();
		if ( '' === $host ) {
			return;
		}
		self::delete_dir( SKIT_CACHE_DIR . $host );
		$this->varnish_purge( home_url( '/' ), true );

		/**
		 * Scatta dopo un purge completo (usato ad es. per avviare il preload).
		 */
		do_action( 'skit_after_clean_domain' );
	}

	/**
	 * Purge di un post e di tutti gli URL correlati: home, archivi di termine,
	 * archivio autore, archivio data, feed. Equivalente di rocket_clean_post.
	 *
	 * @param WP_Post $post Post.
	 */
	public function clean_post( $post ) {
		$urls = array();

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		// Home e paginazione della home.
		$urls[] = home_url( '/' );

		// Archivio del post type.
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}

		// Archivi dei termini associati.
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

		// Archivio autore.
		$author = get_author_posts_url( (int) $post->post_author );
		if ( $author ) {
			$urls[] = $author;
		}

		// Archivio data (solo per i post).
		if ( 'post' === $post->post_type ) {
			$year  = get_the_time( 'Y', $post );
			$month = get_the_time( 'm', $post );
			$urls[] = get_year_link( $year );
			$urls[] = get_month_link( $year, $month );
		}

		/**
		 * Filtra gli URL da ripulire quando un post cambia.
		 *
		 * @param string[] $urls
		 * @param WP_Post  $post
		 */
		// URL the post had before this update (unpublish, trash, slug change).
		if ( isset( $this->old_urls[ $post->ID ] ) ) {
			$urls[] = $this->old_urls[ $post->ID ];
		}

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
			// true = include le sottopagine di paginazione (/page/2/...).
			$this->clean_url( (string) $url, true );
		}
		if ( 0 === $new ) {
			return;
		}

		/**
		 * Scatta dopo il purge di un post (usato per il preload mirato).
		 *
		 * @param WP_Post  $post
		 * @param string[] $urls
		 */
		do_action( 'skit_after_clean_post', $post, $urls );
	}

	/**
	 * Elimina i file di cache di un singolo URL.
	 *
	 * @param string $url             URL completo.
	 * @param bool   $with_pagination True per eliminare anche /page/N/.
	 */
	public function clean_url( $url, $with_pagination = false ) {
		// Prima di tutto il proxy (Varnish): va invalidato anche quando
		// la cache su file non ha nulla per questo URL.
		$this->varnish_purge( $url, $with_pagination );

		$dir = self::url_to_cache_dir( $url );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		// Elimina i file index* (tutte le varianti: https, mobile, gzip).
		foreach ( (array) glob( $dir . '/index*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( $with_pagination ) {
			$pagination_base = 'page';
			global $wp_rewrite;
			if ( $wp_rewrite instanceof WP_Rewrite && ! empty( $wp_rewrite->pagination_base ) ) {
				$pagination_base = $wp_rewrite->pagination_base;
			}
			self::delete_dir( $dir . '/' . $pagination_base );
		}
	}

	/**
	 * Invalida un URL anche sul reverse proxy (Varnish di Cloudways e
	 * compatibili): richiesta HTTP con metodo PURGE, come fanno Breeze e
	 * Proxy Cache Purge. Con $subtree true usa il purge regex sull'intero
	 * sottoalbero del percorso (paginazioni comprese; sulla home equivale
	 * al purge dell'intero dominio).
	 *
	 * Non blocca il salvataggio: richiesta non bloccante, timeout breve.
	 * Disattivabile con `define( 'SKIT_NO_VARNISH', true )` o col filtro
	 * `skit_varnish_enabled`.
	 *
	 * @param string $url     URL completo da invalidare.
	 * @param bool   $subtree True per invalidare anche tutto il sottoalbero.
	 */
	private function varnish_purge( $url, $subtree = false ) {
		if ( defined( 'SKIT_NO_VARNISH' ) && SKIT_NO_VARNISH ) {
			return;
		}
		if ( ! apply_filters( 'skit_varnish_enabled', true ) ) {
			return;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( $subtree ) {
			$path = rtrim( $path, '/' ) . '/.*';
		}
		$target = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . $path;

		wp_remote_request( $target, array(
			'method'    => 'PURGE',
			'blocking'  => false,
			'timeout'   => 3,
			'sslverify' => false,
			'headers'   => array(
				'Host'           => $parts['host'],
				'X-Purge-Method' => $subtree ? 'regex' : 'default',
			),
		) );
	}

	/**
	 * Converte un URL nella cartella di cache corrispondente.
	 *
	 * @param string $url URL completo.
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
		// Path traversal: mai accettare ".." (str_replace è aggirabile).
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
			return '';
		}

		return SKIT_CACHE_DIR . $host . $path;
	}

	/**
	 * Elimina ricorsivamente una cartella (limitata alla cache dir).
	 *
	 * @param string $dir Cartella da eliminare.
	 */
	public static function delete_dir( $dir ) {
		$dir  = untrailingslashit( $dir );
		$base = untrailingslashit( SKIT_CACHE_DIR );

		// Sicurezza: mai uscire dalla cartella di cache. Il confronto va
		// fatto sui path risolti (realpath), non sulle stringhe: "base/../x"
		// supererebbe un semplice controllo di prefisso.
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
	 * Cron: elimina i file più vecchi della durata di vita configurata.
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
				// I file nella root (.htaccess e index.html di protezione)
				// non sono file di cache: non vanno mai toccati.
				if ( untrailingslashit( dirname( $item->getPathname() ) ) === $root ) {
					continue;
				}
				// I CSS minificati in _assets/ sono referenziati dalle pagine
				// in cache: si buttano solo con il purge completo, mai a tempo.
				if ( 0 === strpos( $item->getPathname(), $assets_dir ) ) {
					continue;
				}
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( $item->isDir() ) {
				// Rimuove le cartelle rimaste vuote.
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
