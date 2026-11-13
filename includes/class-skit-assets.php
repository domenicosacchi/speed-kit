<?php
/**
 * Assets — Ottimizzazione degli asset statici, tutto in locale:
 * minify dei CSS (con cache su disco e cache-busting automatico),
 * Google Fonts con display=swap e preconnect, preload dei font critici,
 * rimozione dello script emoji.
 *
 * A differenza di WP Rocket qui non c'è il minify/combine JS: i JS
 * distribuiti sono già minificati e con gzip + defer il guadagno reale
 * è nullo a fronte di un rischio concreto di rotture.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Assets {

	/**
	 * Cartella su disco dei CSS minificati (esclusa dal GC e dal purge per host).
	 */
	const ASSETS_DIR = '_assets';

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
	 * Registra filtri e azioni.
	 */
	public function init() {
		add_filter( 'skit_buffer', array( $this, 'process' ), 20 );

		if ( ! empty( $this->settings['disable_emojis'] ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}

		// Al purge completo si buttano anche i CSS minificati:
		// verranno rigenerati insieme alle pagine.
		add_action(
			'skit_after_clean_domain',
			function () {
				SKIT_Purge::delete_dir( SKIT_CACHE_DIR . self::ASSETS_DIR );
			}
		);
	}

	/**
	 * Trasformazioni sul buffer di pagina.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		if ( ! empty( $this->settings['minify_css'] ) ) {
			$html = $this->minify_css_links( $html );
		}

		if ( ! empty( $this->settings['font_display_swap'] ) ) {
			$html = $this->google_fonts( $html );
		}

		$fonts = array_filter( (array) $this->settings['preload_fonts'] );
		if ( $fonts ) {
			$html = $this->preload_fonts( $html, $fonts );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Minify CSS
	 * ------------------------------------------------------------------ */

	/**
	 * Riscrive i <link rel="stylesheet"> locali verso versioni minificate
	 * salvate in cache. Il nome file include l'mtime dell'originale:
	 * cache-busting automatico a ogni aggiornamento del CSS.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function minify_css_links( $html ) {
		return (string) preg_replace_callback(
			'/<link\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
			function ( $matches ) {
				$attrs = $matches[1];

				if ( ! preg_match( '/(?<![\w-])rel\s*=\s*["\']?stylesheet["\']?/i', $attrs ) ) {
					return $matches[0];
				}
				if ( ! preg_match( '/(?<![\w-])href\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $href ) ) {
					return $matches[0];
				}

				$new_url = $this->minified_css_url( html_entity_decode( $href[1] ) );
				if ( '' === $new_url ) {
					return $matches[0];
				}

				return str_replace( $href[1], esc_url( $new_url ), $matches[0] );
			},
			$html
		);
	}

	/**
	 * Dato l'URL di un CSS locale restituisce l'URL della versione
	 * minificata (generandola se serve), o '' se non gestibile.
	 *
	 * @param string $url URL del foglio di stile.
	 * @return string
	 */
	private function minified_css_url( $url ) {
		$path = $this->url_to_local_path( $url );
		if ( '' === $path || false !== strpos( $path, '.min.css' ) ) {
			return '';
		}

		/**
		 * Filtra i pattern di CSS esclusi dal minify (match sull'URL).
		 *
		 * @param string[] $exclusions
		 */
		$exclusions = (array) apply_filters( 'skit_exclude_minify_css', (array) $this->settings['minify_css_exclude'] );
		foreach ( $exclusions as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern && preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', $url ) ) {
				return '';
			}
		}

		$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( 0 === $mtime ) {
			return '';
		}

		$key  = md5( $path . '|' . $mtime ) . '.css';
		$dir  = SKIT_CACHE_DIR . self::ASSETS_DIR . '/';
		$file = $dir . $key;

		if ( ! is_file( $file ) ) {
			$css = (string) @file_get_contents( $path ); // phpcs:ignore
			if ( '' === $css ) {
				return '';
			}
			$css = $this->minify_css( $css, $url );

			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			$tmp = $file . '.' . uniqid( 'tmp', true );
			if ( false === @file_put_contents( $tmp, $css, LOCK_EX ) ) { // phpcs:ignore
				return '';
			}
			@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return content_url( 'cache/speed-kit/' . self::ASSETS_DIR . '/' . $key );
	}

	/**
	 * Minificazione CSS conservativa: commenti, whitespace e rebase degli
	 * url() relativi (che altrimenti punterebbero alla cartella di cache).
	 *
	 * @param string $css     Contenuto CSS.
	 * @param string $src_url URL originale del file (per il rebase).
	 * @return string
	 */
	public function minify_css( $css, $src_url ) {
		// Rebase: url(x.png) relativo alla posizione originale del CSS.
		$base = preg_replace( '#/[^/]*(\?.*)?$#', '/', $src_url );
		$css  = (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			function ( $m ) use ( $base ) {
				$ref = trim( $m[2] );
				if ( '' === $ref || preg_match( '#^(?:https?:)?//|^data:|^\#|^/#i', $ref ) ) {
					return $m[0];
				}
				return 'url(' . $m[1] . $base . $ref . $m[1] . ')';
			},
			$css
		);

		// Commenti (conservando eventuali /*! license */).
		$css = (string) preg_replace( '#/\*(?!!).*?\*/#s', '', $css );
		// Whitespace.
		$css = (string) preg_replace( '/\s+/', ' ', $css );
		$css = (string) preg_replace( '/\s*([{};:,>~+])\s*/', '$1', $css );
		$css = str_replace( ';}', '}', $css );

		return trim( $css );
	}

	/**
	 * Converte un URL in path locale se punta a un file statico del sito.
	 *
	 * @param string $url URL (assoluto, relativo o protocol-relative).
	 * @return string
	 */
	private function url_to_local_path( $url ) {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$parts     = wp_parse_url( $url );

		if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home_host ) ) {
			return '';
		}
		if ( empty( $parts['path'] ) ) {
			return '';
		}

		$path = rawurldecode( $parts['path'] );
		// Normalizzati senza slash finale, a prescindere da come le funzioni
		// WP compongono l'URL (content_url non lo ha, includes_url sì).
		$content_path  = untrailingslashit( (string) wp_parse_url( content_url(), PHP_URL_PATH ) );
		$includes_path = untrailingslashit( (string) wp_parse_url( includes_url(), PHP_URL_PATH ) );

		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}

		if ( '' !== $content_path && 0 === strpos( $path, $content_path . '/' ) ) {
			$file = WP_CONTENT_DIR . substr( $path, strlen( $content_path ) );
		} elseif ( '' !== $includes_path && 0 === strpos( $path, $includes_path . '/' ) ) {
			$file = ABSPATH . WPINC . substr( $path, strlen( $includes_path ) );
		} else {
			return '';
		}

		return is_file( $file ) ? $file : '';
	}

	/* ---------------------------------------------------------------------
	 * Google Fonts / preload font
	 * ------------------------------------------------------------------ */

	/**
	 * Aggiunge display=swap agli URL di Google Fonts che non lo hanno
	 * (testo visibile subito con il font di fallback) e un preconnect a
	 * fonts.gstatic.com.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function google_fonts( $html ) {
		if ( false === stripos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}

		$html = (string) preg_replace_callback(
			'#(https?://fonts\.googleapis\.com/css2?\?[^"\'\s>]+)#i',
			function ( $m ) {
				if ( false !== stripos( $m[1], 'display=' ) ) {
					return $m[1];
				}
				return $m[1] . ( false !== strpos( $m[1], '&#038;' ) ? '&#038;' : '&amp;' ) . 'display=swap';
			},
			$html
		);

		if ( false === stripos( $html, 'rel="preconnect" href="https://fonts.gstatic.com"' ) ) {
			$link = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			$html = (string) preg_replace( '/<head(\s[^>]*)?>/i', '$0' . $link, $html, 1 );
		}

		return $html;
	}

	/**
	 * Inietta <link rel="preload"> per i font critici configurati.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $fonts URL dei font (relativi o assoluti).
	 * @return string
	 */
	private function preload_fonts( $html, array $fonts ) {
		$links = '';
		foreach ( $fonts as $font ) {
			$font = trim( (string) $font );
			if ( '' === $font ) {
				continue;
			}
			$ext  = strtolower( (string) pathinfo( (string) wp_parse_url( $font, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$type = in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ? ' type="font/' . $ext . '"' : '';
			$links .= '<link rel="preload" href="' . esc_url( $font ) . '" as="font"' . $type . ' crossorigin>';
		}
		if ( '' === $links ) {
			return $html;
		}

		return (string) preg_replace( '/<head(\s[^>]*)?>/i', '$0' . $links, $html, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Emoji
	 * ------------------------------------------------------------------ */

	/**
	 * Rimuove lo script emoji di WordPress (~10 KB inutili su ogni pagina:
	 * tutti i browser moderni rendono le emoji nativamente).
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
			'tiny_mce_plugins',
			function ( $plugins ) {
				return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
			}
		);
	}
}
