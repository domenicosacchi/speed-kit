<?php
/**
 * Media — Image lazyload and YouTube facade.
 *
 * Lazyload: adds loading="lazy" and decoding="async" to images, skipping
 * the first N (above the fold, typically the LCP image, which must load
 * right away).
 *
 * YouTube facade: replaces YouTube iframes with just the thumbnail and a
 * play button; the real iframe (and its JavaScript) is created only on
 * click. Equivalent of WP Rocket's "Replace YouTube iframe with preview
 * image".
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Media {

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Number of images found in the page so far.
	 *
	 * @var int
	 */
	private $image_count = 0;

	/**
	 * True when at least one facade was inserted in the page.
	 *
	 * @var bool
	 */
	private $has_facade = false;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the buffer filter (before the other URL optimizations).
	 */
	public function init() {
		add_filter( 'skit_buffer', array( $this, 'process' ), 8 );
	}

	/**
	 * Applies image lazyload and the YouTube facade.
	 *
	 * @param string $html Page buffer.
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
	 * Image lazyload
	 * ------------------------------------------------------------------ */

	/**
	 * Adds loading="lazy" and decoding="async" to img tags, skipping the
	 * first N and the explicitly excluded ones.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function lazyload_images( $html ) {
		$skip_first = max( 0, (int) $this->settings['lazyload_skip_first'] );

		/**
		 * Filters the strings that exclude an image from lazyload
		 * (matched against the whole tag).
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

				// The first N images (above the fold / LCP) load right away.
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
	 * YouTube facade
	 * ------------------------------------------------------------------ */

	/**
	 * Replaces YouTube iframes with thumbnail + play button.
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

				// Extract the video ID from youtube.com/embed/ID or youtube-nocookie.com/embed/ID.
				if ( ! preg_match( '#(?:youtube(?:-nocookie)?\.com)/embed/([A-Za-z0-9_-]{6,15})#i', $src[1], $id ) ) {
					return $matches[0];
				}
				$video_id = $id[1];

				// Keep an existing title for accessibility.
				$title = __( 'YouTube video', 'speed-kit' );
				if ( preg_match( '/(?<![\w-])title\s*=\s*"([^"]*)"/i', $attrs, $t ) ) {
					$title = $t[1];
				}

				// Original query parameters (start=, list=...) passed on to the
				// real iframe on click.
				$query = '';
				if ( preg_match( '/\/embed\/[A-Za-z0-9_-]+\?([^"\'\s>]+)/i', $src[1], $q ) ) {
					$query = htmlspecialchars( html_entity_decode( $q[1] ), ENT_QUOTES );
				}

				$this->has_facade = true;

				return '<button type="button" class="skit-yt" data-skit-yt="' . esc_attr( $video_id ) . '"'
					. ( '' !== $query ? ' data-skit-yt-q="' . $query . '"' : '' )
					/* translators: %s: video title. */
					. ' aria-label="' . esc_attr( sprintf( __( 'Play: %s', 'speed-kit' ), $title ) ) . '">'
					. '<img src="https://i.ytimg.com/vi/' . esc_attr( $video_id ) . '/hqdefault.jpg" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async">'
					. '<span class="skit-yt-play" aria-hidden="true"></span>'
					. '</button>';
			},
			$html
		);
	}

	/**
	 * Injects the facade CSS and JS (once, before </body>).
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
