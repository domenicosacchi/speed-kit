<?php
/**
 * Optimize — Ottimizzazioni dell'HTML applicate al buffer prima del
 * salvataggio in cache (filtro skit_buffer): minify HTML, defer JS con
 * esclusioni, delay JS alla prima interazione, lazyload iframe, dns-prefetch.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Optimize {

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
	 * Registra il filtro sul buffer di pagina.
	 */
	public function init() {
		add_filter( 'skit_buffer', array( $this, 'optimize' ) );
	}

	/**
	 * Applica le ottimizzazioni abilitate, in quest'ordine:
	 * defer/delay JS → lazyload → resource hints → minify.
	 *
	 * @param string $html Buffer della pagina.
	 * @return string
	 */
	public function optimize( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		if ( ! empty( $this->settings['delay_js'] ) ) {
			$html = $this->delay_js( $html );
		} elseif ( ! empty( $this->settings['defer_js'] ) ) {
			$html = $this->defer_js( $html );
		}

		if ( ! empty( $this->settings['lazyload_iframes'] ) ) {
			$html = $this->lazyload_iframes( $html );
		}

		$hosts = array_filter( (array) $this->settings['dns_prefetch'] );
		if ( $hosts ) {
			$html = $this->resource_hints( $html, $hosts );
		}

		if ( ! empty( $this->settings['minify_html'] ) ) {
			$html = $this->minify_html( $html );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Defer JS
	 * ------------------------------------------------------------------ */

	/**
	 * Aggiunge l'attributo defer agli script esterni non esclusi.
	 * Equivalente della "Load JavaScript deferred" di WP Rocket, con il
	 * filtro di esclusione skit_exclude_defer_js (come rocket_exclude_defer_js).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function defer_js( $html ) {
		$exclusions = $this->defer_exclusions();

		return (string) preg_replace_callback(
			'/<script\s+((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)>/i',
			function ( $matches ) use ( $exclusions ) {
				$attrs = $matches[1];

				// Solo script con src (non data-src), non già defer/async, tipo JavaScript.
				if ( ! preg_match( '/(?<![\w-])src\s*=/i', $attrs ) ) {
					return $matches[0];
				}
				if ( preg_match( '/\b(?:defer|async|nomodule)\b/i', $attrs ) ) {
					return $matches[0];
				}
				if ( preg_match( '/(?<![\w-])type\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $type ) &&
					! in_array( strtolower( $type[1] ), array( 'text/javascript', 'application/javascript' ), true ) ) {
					return $matches[0];
				}
				if ( ! preg_match( '/(?<![\w-])src\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $src ) ) {
					return $matches[0];
				}

				foreach ( $exclusions as $pattern ) {
					if ( preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', $src[1] ) ) {
						return $matches[0];
					}
				}

				return '<script defer ' . $attrs . '>';
			},
			$html
		);
	}

	/**
	 * Pattern esclusi dal defer (regex parziali sull'URL dello script).
	 *
	 * @return string[]
	 */
	private function defer_exclusions() {
		$defaults = array(
			// jQuery non differito: gli inline script che usano $ girano subito.
			'/jquery(?:\.min)?(?:-[0-9.]+)*(?:\.min)?\.js',
			'/jquery-migrate(?:\.min)?\.js',
			'lazyload',
			'skit-delay',
		);

		$custom = array_filter( array_map( 'trim', (array) $this->settings['defer_js_exclude'] ) );

		/**
		 * Filtra i pattern esclusi dal defer. Equivalente di rocket_exclude_defer_js.
		 *
		 * @param string[] $exclusions
		 */
		return (array) apply_filters( 'skit_exclude_defer_js', array_merge( $defaults, $custom ) );
	}

	/* ---------------------------------------------------------------------
	 * Delay JS
	 * ------------------------------------------------------------------ */

	/**
	 * Rinvia l'esecuzione degli script alla prima interazione utente
	 * (mousemove, tocco, scroll, tasto). Equivalente della "Delay JavaScript
	 * execution" di WP Rocket: il type viene sostituito e un piccolo loader
	 * ripristina gli script in ordine al primo evento.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function delay_js( $html ) {
		$exclusions = $this->delay_exclusions();

		$html = (string) preg_replace_callback(
			'/<script\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
			function ( $matches ) use ( $exclusions ) {
				$attrs = $matches[1];

				// Salta tipi non-JS (JSON-LD, template) e moduli.
				if ( preg_match( '/(?<![\w-])type\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $type ) &&
					! in_array( strtolower( $type[1] ), array( 'text/javascript', 'application/javascript' ), true ) ) {
					return $matches[0];
				}

				$haystack = $attrs;
				foreach ( $exclusions as $pattern ) {
					if ( '' !== $pattern && false !== stripos( $haystack, $pattern ) ) {
						return $matches[0];
					}
				}

				// Sostituisce/aggiunge il type per bloccare l'esecuzione.
				if ( isset( $type[0] ) ) {
					$attrs = preg_replace( '/(?<![\w-])type\s*=\s*["\']?[^"\'\s>]+["\']?/i', 'type="skit/delay-js"', $attrs );
				} else {
					$attrs .= ' type="skit/delay-js"';
				}

				return '<script' . $attrs . '>';
			},
			$html
		);

		// Gli inline script esclusi che usano jQuery girerebbero prima di
		// jQuery se questo è ritardato: rischio accettato, come in WP Rocket
		// (si risolve aggiungendo esclusioni).

		return $this->inject_before_body_end( $html, $this->delay_loader_script() );
	}

	/**
	 * Stringhe che escludono uno script dal delay (match sull'intero tag).
	 *
	 * @return string[]
	 */
	private function delay_exclusions() {
		$defaults = array(
			'skit/delay-js',
			'application/ld+json',
			'data-no-delay',
		);

		$custom = array_filter( array_map( 'trim', (array) $this->settings['delay_js_exclude'] ) );

		/**
		 * Filtra le esclusioni dal delay JS. Equivalente di rocket_delay_js_exclusions.
		 *
		 * @param string[] $exclusions
		 */
		return (array) apply_filters( 'skit_delay_js_exclusions', array_merge( $defaults, $custom ) );
	}

	/**
	 * Loader che ripristina gli script ritardati alla prima interazione,
	 * preservando l'ordine di esecuzione.
	 *
	 * @return string
	 */
	private function delay_loader_script() {
		$js = <<<'JS'
(function(){
var fired=false,events=['keydown','mousedown','mousemove','touchstart','touchmove','wheel','scroll'];
function load(){
if(fired){return;}fired=true;
events.forEach(function(e){window.removeEventListener(e,load,{passive:true});});
var scripts=Array.prototype.slice.call(document.querySelectorAll('script[type="skit/delay-js"]'));
(function next(){
if(!scripts.length){document.dispatchEvent(new Event('skit:delayed-js-loaded'));return;}
var old=scripts.shift(),s=document.createElement('script');
Array.prototype.slice.call(old.attributes).forEach(function(a){if(a.name!=='type'){s.setAttribute(a.name,a.value);}});
if(old.src){s.src=old.src;s.onload=next;s.onerror=next;old.parentNode.replaceChild(s,old);}
else{s.text=old.text;old.parentNode.replaceChild(s,old);next();}
})();
}
events.forEach(function(e){window.addEventListener(e,load,{passive:true});});
})();
JS;

		return '<script id="skit-delay-loader">' . $js . '</script>';
	}

	/* ---------------------------------------------------------------------
	 * Lazyload / resource hints / minify
	 * ------------------------------------------------------------------ */

	/**
	 * Aggiunge loading="lazy" agli iframe che non lo dichiarano.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function lazyload_iframes( $html ) {
		return (string) preg_replace_callback(
			'/<iframe\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
			function ( $matches ) {
				if ( preg_match( '/(?<![\w-])loading\s*=/i', $matches[1] ) ) {
					return $matches[0];
				}
				return '<iframe loading="lazy"' . $matches[1] . '>';
			},
			$html
		);
	}

	/**
	 * Inietta dns-prefetch/preconnect per gli host configurati.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $hosts Host (es. fonts.googleapis.com o //fonts.googleapis.com).
	 * @return string
	 */
	private function resource_hints( $html, array $hosts ) {
		$links = '';
		foreach ( $hosts as $host ) {
			$host = trim( (string) $host );
			if ( '' === $host ) {
				continue;
			}
			$host  = '//' . preg_replace( '#^(?:https?:)?//#', '', $host );
			$host  = esc_attr( $host );
			$links .= '<link rel="dns-prefetch" href="' . $host . '">';
		}
		if ( '' === $links ) {
			return $html;
		}

		return (string) preg_replace( '/<head(\s[^>]*)?>/i', '$0' . $links, $html, 1 );
	}

	/**
	 * Minifica l'HTML: rimuove commenti e comprime gli spazi, preservando
	 * pre, textarea, script e style.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function minify_html( $html ) {
		$placeholders = array();

		// Protegge i blocchi sensibili agli spazi.
		$protected = (string) preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
			function ( $matches ) use ( &$placeholders ) {
				$key                  = '%%SKIT-MINIFY-' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		// Commenti HTML (mantiene condizionali IE e commenti speciali).
		$protected = (string) preg_replace( '/<!--(?!\[if)(?!<!)(?!\s*(?:noindex|googleoff|googleon|noptimize)).*?-->/s', '', $protected );

		// Comprime gli spazi tra i tag e le sequenze di whitespace.
		$protected = (string) preg_replace( '/>\s{2,}</', '> <', $protected );
		$protected = (string) preg_replace( '/[\r\n\t]+/', ' ', $protected );
		$protected = (string) preg_replace( '/ {2,}/', ' ', $protected );

		// Ripristina i blocchi protetti.
		$minified = strtr( $protected, $placeholders );

		// Sanity check: se qualcosa è andato storto, torna all'originale.
		if ( false !== strpos( $minified, '%%SKIT-MINIFY-' ) ) {
			return $html;
		}

		return trim( $minified );
	}

	/**
	 * Inietta markup subito prima di </body>.
	 *
	 * @param string $html   HTML.
	 * @param string $markup Codice da iniettare.
	 * @return string
	 */
	private function inject_before_body_end( $html, $markup ) {
		$pos = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html . $markup;
		}

		return substr( $html, 0, $pos ) . $markup . substr( $html, $pos );
	}
}
