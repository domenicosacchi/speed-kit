<?php
/**
 * Optimize — HTML optimizations applied to the buffer before it is saved
 * to the cache (skit_buffer filter): HTML minify, JS defer with
 * exclusions, JS delay until the first interaction, iframe lazyload,
 * dns-prefetch.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Optimize {

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
	 * Registers the page buffer filter.
	 */
	public function init() {
		add_filter( 'skit_buffer', array( $this, 'optimize' ) );
	}

	/**
	 * Applies the enabled optimizations, in this order:
	 * defer/delay JS -> lazyload -> resource hints -> minify.
	 *
	 * @param string $html Page buffer.
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
	 * Adds the defer attribute to external scripts that are not excluded.
	 * Equivalent of WP Rocket's "Load JavaScript deferred", with the
	 * skit_exclude_defer_js exclusion filter (like rocket_exclude_defer_js).
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

				// Only scripts with src (not data-src), not already defer/async, JavaScript type.
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
	 * Patterns excluded from defer (partial regexes on the script URL).
	 *
	 * @return string[]
	 */
	private function defer_exclusions() {
		$defaults = array(
			// jQuery is not deferred: inline scripts using $ run immediately.
			'/jquery(?:\.min)?(?:-[0-9.]+)*(?:\.min)?\.js',
			'/jquery-migrate(?:\.min)?\.js',
			'lazyload',
			'skit-delay',
		);

		$custom = array_filter( array_map( 'trim', (array) $this->settings['defer_js_exclude'] ) );

		/**
		 * Filters the patterns excluded from defer. Equivalent of rocket_exclude_defer_js.
		 *
		 * @param string[] $exclusions
		 */
		return (array) apply_filters( 'skit_exclude_defer_js', array_merge( $defaults, $custom ) );
	}

	/* ---------------------------------------------------------------------
	 * Delay JS
	 * ------------------------------------------------------------------ */

	/**
	 * Delays script execution until the first user interaction (mouse move,
	 * touch, scroll, key press). Equivalent of WP Rocket's "Delay
	 * JavaScript execution": the type is replaced and a small loader
	 * restores the scripts in order on the first event.
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

				// Skip non-JS types (JSON-LD, templates) and modules.
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

				// Replace/add the type to block execution.
				if ( isset( $type[0] ) ) {
					$attrs = preg_replace( '/(?<![\w-])type\s*=\s*["\']?[^"\'\s>]+["\']?/i', 'type="skit/delay-js"', $attrs );
				} else {
					$attrs .= ' type="skit/delay-js"';
				}

				return '<script' . $attrs . '>';
			},
			$html
		);

		// Excluded inline scripts that use jQuery would run before jQuery
		// when jQuery is delayed: accepted risk, as in WP Rocket (fixed by
		// adding exclusions).

		return $this->inject_before_body_end( $html, $this->delay_loader_script() );
	}

	/**
	 * Strings that exclude a script from the delay (matched against the opening tag).
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
		 * Filters the JS delay exclusions. Equivalent of rocket_delay_js_exclusions.
		 *
		 * @param string[] $exclusions
		 */
		return (array) apply_filters( 'skit_delay_js_exclusions', array_merge( $defaults, $custom ) );
	}

	/**
	 * Loader that restores the delayed scripts on the first interaction,
	 * keeping the execution order.
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
	 * Adds loading="lazy" to iframes that do not declare it.
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
	 * Injects dns-prefetch hints for the configured hosts.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $hosts Hosts (e.g. fonts.googleapis.com or //fonts.googleapis.com).
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
	 * Minifies the HTML: removes comments and collapses whitespace, keeping
	 * pre, textarea, script and style intact.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function minify_html( $html ) {
		$placeholders = array();

		// Protect whitespace-sensitive blocks.
		$protected = (string) preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
			function ( $matches ) use ( &$placeholders ) {
				$key                  = '%%SKIT-MINIFY-' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		// HTML comments (keeps IE conditionals and special comments).
		$protected = (string) preg_replace( '/<!--(?!\[if)(?!<!)(?!\s*(?:noindex|googleoff|googleon|noptimize)).*?-->/s', '', $protected );

		// Collapse whitespace between tags and whitespace runs.
		$protected = (string) preg_replace( '/>\s{2,}</', '> <', $protected );
		$protected = (string) preg_replace( '/[\r\n\t]+/', ' ', $protected );
		$protected = (string) preg_replace( '/ {2,}/', ' ', $protected );

		// Restore the protected blocks.
		$minified = strtr( $protected, $placeholders );

		// Sanity check: if anything went wrong, return the original.
		// A failing regex (e.g. PCRE backtrack limit on a huge page) returns
		// null, cast to '' above: never replace a page with an empty string.
		if ( false !== strpos( $minified, '%%SKIT-MINIFY-' ) || strlen( $minified ) < strlen( $html ) / 4 ) {
			return $html;
		}

		return trim( $minified );
	}

	/**
	 * Injects markup right before </body>.
	 *
	 * @param string $html   HTML.
	 * @param string $markup Markup to inject.
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
