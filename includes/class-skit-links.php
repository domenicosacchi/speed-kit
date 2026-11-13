<?php
/**
 * Links — Prefetch dei link interni al passaggio del mouse (stile
 * instant.page / "Preload Links" di WP Rocket): quando l'utente sfiora
 * un link, la pagina di destinazione viene prefetchata; al click la
 * navigazione parte dalla cache del browser.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Links {

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
	 * Registra il filtro sul buffer.
	 */
	public function init() {
		if ( empty( $this->settings['preload_links'] ) ) {
			return;
		}
		add_filter( 'skit_buffer', array( $this, 'inject' ), 30 );
	}

	/**
	 * Inietta lo script di prefetch prima di </body>.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function inject( $html ) {
		if ( '' === $html || false !== strpos( $html, 'skit-preload-links' ) ) {
			return $html;
		}

		$js = <<<'JS'
(function(){
if(navigator.connection&&(navigator.connection.saveData||/2g/.test(navigator.connection.effectiveType||''))){return;}
var seen=new Set(),t;
function ok(a){
if(!a||!a.href||a.origin!==location.origin){return false;}
if(seen.has(a.href)||a.href===location.href){return false;}
if(a.search){return false;}
if(a.hash&&a.pathname===location.pathname){return false;}
if(a.hasAttribute('download')||a.hasAttribute('data-no-prefetch')||/(^|\s)nofollow(\s|$)/i.test(a.getAttribute('rel')||'')){return false;}
if(/wp-admin|wp-login|logout|\.(?:jpe?g|png|gif|webp|pdf|zip|mp4|xml)$/i.test(a.href)){return false;}
return true;
}
function prefetch(a){
if(!ok(a)){return;}
seen.add(a.href);
var l=document.createElement('link');
l.rel='prefetch';l.href=a.href;l.as='document';
document.head.appendChild(l);
}
document.addEventListener('mouseover',function(e){
var a=e.target.closest('a');
if(!a){return;}
t=setTimeout(function(){prefetch(a);},65);
},{passive:true});
document.addEventListener('mouseout',function(){clearTimeout(t);},{passive:true});
document.addEventListener('touchstart',function(e){
var a=e.target.closest('a');
if(a){prefetch(a);}
},{passive:true});
})();
JS;

		$markup = '<script id="skit-preload-links" data-no-delay>' . $js . '</script>';

		$pos = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html . $markup;
		}

		return substr( $html, 0, $pos ) . $markup . substr( $html, $pos );
	}
}
