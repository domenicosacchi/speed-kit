<?php
/**
 * Media — Lazyload avanzato delle immagini e facade YouTube.
 *
 * Lazyload: aggiunge loading="lazy" e decoding="async" alle immagini,
 * saltando le prime N (above the fold, tipicamente l'immagine LCP che
 * deve invece caricarsi subito).
 *
 * Facade YouTube: sostituisce gli iframe di YouTube con la sola thumbnail
 * più un pulsante play; l'iframe reale (con i suoi ~800 KB di JS) viene
 * creato solo al click. Equivalente del "Replace YouTube iframe with
 * preview image" di WP Rocket.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Media {

	/**
	 * Impostazioni correnti.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Contatore immagini incontrate nella pagina.
	 *
	 * @var int
	 */
	private $image_count = 0;

	/**
	 * True se nella pagina è stata inserita almeno una facade.
	 *
	 * @var bool
	 */
	private $has_facade = false;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra il filtro sul buffer (prima delle altre ottimizzazioni URL).
	 */
	public function init() {
		add_filter( 'skit_buffer', array( $this, 'process' ), 8 );
	}

	/**
	 * Applica lazyload immagini e facade YouTube.
	 *
	 * @param string $html Buffer della pagina.
	 * @return string
	 */
	public function process( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		$this->image_count = 0;
		$this->has_facade  = false;

		if ( ! empty( $this->settings['youtube_facade'] ) ) {
			$html = $this->youtube_facade( $html );
			if ( $this->has_facade ) {
				$html = $this->inject_facade_assets( $html );
			}
		}

		if ( ! empty( $this->settings['lazyload_images'] ) ) {
			$html = $this->lazyload_images( $html );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Lazyload immagini
	 * ------------------------------------------------------------------ */

	/**
	 * Aggiunge loading="lazy" e decoding="async" alle img, saltando le
	 * prime N e quelle esplicitamente escluse.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function lazyload_images( $html ) {
		$skip_first = max( 0, (int) $this->settings['lazyload_skip_first'] );

		/**
		 * Filtra le stringhe che escludono un'immagine dal lazyload
		 * (match sull'intero tag).
		 *
		 * @param string[] $exclusions
		 */
		$exclusions = (array) apply_filters(
			'skit_lazyload_exclusions',
			array( 'skip-lazy', 'data-skip-lazy', 'no-lazy', 'eager', 'fetchpriority="high"', "fetchpriority='high'" )
		);

		return (string) preg_replace_callback(
			'/<img\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
			function ( $matches ) use ( $skip_first, $exclusions ) {
				$tag   = $matches[0];
				$attrs = $matches[1];

				$this->image_count++;

				// Le prime N immagini (above the fold / LCP) caricano subito.
				if ( $this->image_count <= $skip_first ) {
					return $tag;
				}

				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
						return $tag;
					}
				}

				$add = '';
				if ( ! preg_match( '/(?<![\w-])loading\s*=/i', $attrs ) ) {
					$add .= ' loading="lazy"';
				}
				if ( ! preg_match( '/(?<![\w-])decoding\s*=/i', $attrs ) ) {
					$add .= ' decoding="async"';
				}
				if ( '' === $add ) {
					return $tag;
				}

				return '<img' . $add . $attrs . '>';
			},
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * Facade YouTube
	 * ------------------------------------------------------------------ */

	/**
	 * Sostituisce gli iframe YouTube con thumbnail + pulsante play.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function youtube_facade( $html ) {
		return (string) preg_replace_callback(
			'/<iframe\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>\s*<\/iframe>/i',
			function ( $matches ) {
				$attrs = $matches[1];

				if ( ! preg_match( '/(?<![\w-])src\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $src ) ) {
					return $matches[0];
				}

				// Estrae l'ID video da youtube.com/embed/ID o youtube-nocookie.com/embed/ID.
				if ( ! preg_match( '#(?:youtube(?:-nocookie)?\.com)/embed/([A-Za-z0-9_-]{6,15})#i', $src[1], $id ) ) {
					return $matches[0];
				}
				$video_id = $id[1];

				// Rispetta un eventuale title per l'accessibilità.
				$title = 'Video YouTube';
				if ( preg_match( '/(?<![\w-])title\s*=\s*"([^"]*)"/i', $attrs, $t ) ) {
					$title = $t[1];
				}

				// Parametri della query originale (start=, list=...) da
				// ripassare all'iframe reale al click.
				$query = '';
				if ( preg_match( '/\/embed\/[A-Za-z0-9_-]+\?([^"\'\s>]+)/i', $src[1], $q ) ) {
					$query = htmlspecialchars( html_entity_decode( $q[1] ), ENT_QUOTES );
				}

				$this->has_facade = true;

				return '<button type="button" class="skit-yt" data-skit-yt="' . esc_attr( $video_id ) . '"'
					. ( '' !== $query ? ' data-skit-yt-q="' . $query . '"' : '' )
					. ' aria-label="Riproduci: ' . esc_attr( $title ) . '">'
					. '<img src="https://i.ytimg.com/vi/' . esc_attr( $video_id ) . '/hqdefault.jpg" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async">'
					. '<span class="skit-yt-play" aria-hidden="true"></span>'
					. '</button>';
			},
			$html
		);
	}

	/**
	 * Inietta CSS e JS della facade (una sola volta, prima di </body>).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function inject_facade_assets( $html ) {
		$css = '.skit-yt{position:relative;display:block;width:100%;aspect-ratio:16/9;padding:0;border:0;cursor:pointer;background:#000;overflow:hidden}'
			. '.skit-yt img{width:100%;height:100%;object-fit:cover;display:block}'
			. '.skit-yt-play{position:absolute;top:50%;left:50%;width:68px;height:48px;transform:translate(-50%,-50%);background:rgba(23,23,23,.8);border-radius:14px}'
			. '.skit-yt-play:before{content:"";position:absolute;top:50%;left:50%;transform:translate(-40%,-50%);border-style:solid;border-width:12px 0 12px 20px;border-color:transparent transparent transparent #fff}'
			. '.skit-yt:hover .skit-yt-play,.skit-yt:focus .skit-yt-play{background:#f00}'
			. '.skit-yt iframe{position:absolute;inset:0;width:100%;height:100%;border:0}';

		$js = <<<'JS'
document.addEventListener('click',function(e){
var b=e.target.closest('[data-skit-yt]');
if(!b){return;}
var id=b.getAttribute('data-skit-yt'),q=b.getAttribute('data-skit-yt-q')||'';
var f=document.createElement('iframe');
f.setAttribute('allow','accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture');
f.setAttribute('allowfullscreen','');
f.src='https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1'+(q?'&'+q:'');
f.title=b.getAttribute('aria-label')||'';
var w=document.createElement('div');w.className='skit-yt';w.appendChild(f);
b.parentNode.replaceChild(w,b);
});
JS;

		$markup = '<style id="skit-yt-css">' . $css . '</style><script id="skit-yt-js" data-no-delay>' . $js . '</script>';

		$pos = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html . $markup;
		}

		return substr( $html, 0, $pos ) . $markup . substr( $html, $pos );
	}
}
