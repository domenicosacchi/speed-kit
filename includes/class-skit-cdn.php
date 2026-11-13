<?php
/**
 * CDN — Riscrive gli URL degli asset statici verso un host CDN
 * (es. cdn.tuosito.it o un pull-zone). Equivalente della scheda CDN di
 * WP Rocket, senza il loro RocketCDN in abbonamento.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Cdn {

	/**
	 * Impostazioni correnti.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Host CDN normalizzato (senza schema né slash).
	 *
	 * @var string
	 */
	private $cdn_host = '';

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra il filtro sul buffer (per ultimo: vede anche gli URL
	 * riscritti dal minify CSS).
	 */
	public function init() {
		if ( empty( $this->settings['cdn_enabled'] ) || empty( $this->settings['cdn_host'] ) ) {
			return;
		}

		$host = (string) $this->settings['cdn_host'];
		$host = preg_replace( '#^(?:https?:)?//#i', '', trim( $host ) );
		$this->cdn_host = untrailingslashit( $host );
		if ( '' === $this->cdn_host ) {
			return;
		}

		add_filter( 'skit_buffer', array( $this, 'rewrite' ), 100 );
	}

	/**
	 * Riscrive src/href/srcset degli asset statici verso il CDN.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function rewrite( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		$home       = home_url();
		$home_host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		$extensions = 'css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm';

		$cdn  = '//' . $this->cdn_host;
		$site = '(?:(?:https?:)?//' . preg_quote( $home_host, '#' ) . ')?';

		// URL assoluti del proprio host o relativi a /wp-content|/wp-includes,
		// dentro attributi src/href e con estensione statica.
		$pattern = '#(\b(?:src|href|data-src)\s*=\s*["\'])'
			. $site
			. '(/(?:wp-content|wp-includes)/[^"\'\s>]+?\.(?:' . $extensions . ')(?:\?[^"\'\s>]*)?)'
			. '(["\'])#i';

		$html = (string) preg_replace_callback(
			$pattern,
			function ( $m ) use ( $cdn ) {
				return $m[1] . $cdn . $m[2] . $m[3];
			},
			$html
		);

		// srcset: più URL separati da virgola dentro lo stesso attributo,
		// riscritti uno per uno.
		$url_in_srcset = '#' . $site . '(/(?:wp-content|wp-includes)/[^\s,"\']+?\.(?:' . $extensions . ')(?:\?[^\s,"\']*)?)#i';
		$html          = (string) preg_replace_callback(
			'#((?:srcset|data-srcset)\s*=\s*)(["\'])(.*?)\2#is',
			function ( $m ) use ( $cdn, $url_in_srcset ) {
				$value = (string) preg_replace( $url_in_srcset, $cdn . '$1', $m[3] );
				return $m[1] . $m[2] . $value . $m[2];
			},
			$html
		);

		// Preconnect al CDN.
		$link = '<link rel="preconnect" href="' . esc_url( $cdn ) . '" crossorigin>';
		if ( false === stripos( $html, $link ) ) {
			$html = (string) preg_replace( '/<head(\s[^>]*)?>/i', '$0' . $link, $html, 1 );
		}

		return $html;
	}
}
