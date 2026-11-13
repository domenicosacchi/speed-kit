<?php
/**
 * SpeedKit — advanced-cache.php drop-in.
 *
 * Copiato in wp-content/advanced-cache.php all'attivazione del plugin.
 * Viene eseguito da wp-settings.php molto presto (prima di plugin e tema):
 * se esiste un file di cache valido per la richiesta lo serve e termina,
 * altrimenti lascia proseguire WordPress (il salvataggio del buffer è
 * gestito dal plugin via template_redirect).
 *
 * SKIT_DROPIN_VERSION: 1.2.0
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Questo file viene incluso in due contesti:
 * 1. come drop-in wp-content/advanced-cache.php, prima di plugin e tema:
 *    definisce la costante e prova a servire la cache;
 * 2. come libreria dal plugin (se il drop-in manca o per riusare le funzioni):
 *    in quel caso NON deve servire nulla né ridefinire le funzioni.
 */
if ( ! function_exists( 'skit_ac_maybe_serve' ) ) :

/**
 * Punto d'ingresso: valuta la richiesta e serve la cache se possibile.
 */
function skit_ac_maybe_serve() {
	if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	// Solo richieste GET/HEAD.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}

	$config = skit_ac_load_config();
	if ( empty( $config ) || empty( $config['cache_enabled'] ) ) {
		return;
	}

	// NB: qui WordPress non ha ancora caricato functions.php, solo PHP nativo.
	$uri  = (string) $_SERVER['REQUEST_URI'];
	$path = (string) parse_url( 'http://x' . $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$path = rawurldecode( $path );

	// Percorsi mai serviti da cache.
	if ( skit_ac_is_excluded_path( $path, $config ) ) {
		return;
	}

	// Query string: consentita solo se composta esclusivamente da parametri
	// di tracciamento ignorabili (utm_*, fbclid, gclid...): in quel caso si
	// serve la cache dell'URL base.
	if ( ! skit_ac_query_string_ok( $config ) ) {
		return;
	}

	// Cookie che identificano utenti loggati / stati personalizzati.
	if ( skit_ac_has_skip_cookie( $config ) ) {
		return;
	}

	$file = skit_ac_cache_file_path( $path, $config );
	if ( ! is_readable( $file ) ) {
		return;
	}

	// Scadenza: 0 = nessuna scadenza (si affida al purge automatico).
	$mtime    = (int) @filemtime( $file );
	$lifespan = isset( $config['cache_lifespan'] ) ? (int) $config['cache_lifespan'] : 0;
	if ( $lifespan > 0 && ( time() - $mtime ) > $lifespan ) {
		return;
	}

	skit_ac_serve_file( $file, $mtime, 'HEAD' === $method, ! empty( $config['cache_mobile_separate'] ) );
}

/**
 * Carica il file di configurazione generato dal plugin per questo host.
 *
 * @return array|null
 */
function skit_ac_load_config() {
	$host = strtolower( preg_replace( '/[^a-z0-9.\-:]/i', '', (string) $_SERVER['HTTP_HOST'] ) );
	$host = str_replace( ':', '-', $host );
	if ( '' === $host ) {
		return null;
	}

	$dir  = defined( 'SKIT_CONFIG_DIR' ) ? SKIT_CONFIG_DIR : WP_CONTENT_DIR . '/speed-kit-config/';
	$file = $dir . $host . '.php';
	if ( ! is_readable( $file ) ) {
		// Fallback senza eventuale prefisso www.
		$alt = $dir . preg_replace( '/^www\./', '', $host ) . '.php';
		if ( ! is_readable( $alt ) ) {
			return null;
		}
		$file = $alt;
	}

	$config = include $file;

	return is_array( $config ) ? $config : null;
}

/**
 * Verifica se il percorso è escluso dalla cache.
 *
 * @param string $path   Percorso della richiesta.
 * @param array  $config Configurazione.
 * @return bool
 */
function skit_ac_is_excluded_path( $path, $config ) {
	// Esclusioni fisse.
	static $always = array(
		'#^/wp-admin/#',
		'#^/wp-login\.php#',
		'#^/wp-cron\.php#',
		'#^/xmlrpc\.php#',
		'#^/wp-json(/|$)#',
		'#\.(?:php|xml|xsl|txt|gz)$#',
		'#/feed/?$#',
		'#/(?:sitemap|sitemap_index)[^/]*\.xml$#',
	);

	foreach ( $always as $pattern ) {
		if ( preg_match( $pattern, $path ) ) {
			return true;
		}
	}

	if ( ! empty( $config['exclude_uris'] ) && is_array( $config['exclude_uris'] ) ) {
		foreach ( $config['exclude_uris'] as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', $path ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * True se la query string è vuota o contiene solo parametri ignorabili.
 *
 * @param array $config Configurazione.
 * @return bool
 */
function skit_ac_query_string_ok( $config ) {
	$qs = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
	if ( '' === $qs ) {
		return true;
	}

	// Lista vuota = usa i default (una lista personalizzata la sostituisce).
	$ignored = ! empty( $config['ignored_query_params'] ) && is_array( $config['ignored_query_params'] )
		? $config['ignored_query_params']
		: array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'utm_expid', 'fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'fb_action_ids', 'fb_action_types', 'fb_source', '_ga', 'age-verified', 'usqp', 'cn-reloaded', 'ao_noptimize' );

	parse_str( $qs, $params );
	foreach ( array_keys( $params ) as $key ) {
		if ( ! in_array( (string) $key, $ignored, true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * True se è presente un cookie che impedisce di servire la cache.
 *
 * @param array $config Configurazione.
 * @return bool
 */
function skit_ac_has_skip_cookie( $config ) {
	if ( empty( $_COOKIE ) ) {
		return false;
	}

	$prefixes = ! empty( $config['skip_cookies'] ) && is_array( $config['skip_cookies'] )
		? $config['skip_cookies']
		: array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'edd_items_in_cart' );

	foreach ( array_keys( $_COOKIE ) as $name ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( (string) $name, (string) $prefix ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * True se lo user agent è mobile (stessa logica lato salvataggio).
 *
 * @return bool
 */
function skit_ac_is_mobile() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	if ( '' === $ua ) {
		return false;
	}

	return (bool) preg_match( '#Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi#i', $ua );
}

/**
 * Costruisce il percorso del file di cache per la richiesta corrente.
 *
 * @param string $path   Percorso della richiesta.
 * @param array  $config Configurazione.
 * @return string
 */
function skit_ac_cache_file_path( $path, $config ) {
	$host = strtolower( preg_replace( '/[^a-z0-9.\-:]/i', '', (string) $_SERVER['HTTP_HOST'] ) );
	$host = str_replace( ':', '-', $host );

	$is_https = ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );

	$mobile = '';
	if ( ! empty( $config['cache_mobile_separate'] ) && skit_ac_is_mobile() ) {
		$mobile = '-mobile';
	}

	// Normalizza il path: /percorso/ -> host/percorso/index-https.html.
	$path = '/' . ltrim( $path, '/' );
	$path = rtrim( $path, '/' );
	// Protezione da path traversal: qualsiasi ".." residuo dopo il decode
	// rende la richiesta non cacheabile (str_replace non basta: è aggirabile
	// con sequenze annidate tipo "....//").
	if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
		return '';
	}

	$dir = defined( 'SKIT_CACHE_DIR' ) ? SKIT_CACHE_DIR : WP_CONTENT_DIR . '/cache/speed-kit/';

	return $dir . $host . $path . '/index' . $mobile . ( $is_https ? '-https' : '' ) . '.html';
}

/**
 * Serve il file di cache e termina l'esecuzione.
 *
 * @param string $file      Percorso file HTML in cache.
 * @param int    $mtime     Timestamp ultima modifica.
 * @param bool   $head_only True per richieste HEAD.
 * @param bool   $vary_ua   True con cache mobile separata (Vary: User-Agent).
 */
function skit_ac_serve_file( $file, $mtime, $head_only, $vary_ua = false ) {
	// 304 Not Modified.
	$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
	if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && trim( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) === $last_modified ) {
		header( 'HTTP/1.1 304 Not Modified' );
		header( 'X-SpeedKit-Cache: HIT' );
		exit;
	}

	$zlib_on = (bool) ini_get( 'zlib.output_compression' );

	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Last-Modified: ' . $last_modified );
	header( 'Vary: Accept-Encoding' . ( $vary_ua ? ', User-Agent' : '' ) );
	header( 'X-SpeedKit-Cache: HIT' );

	// Variante gzip pre-compressa.
	$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
	$gzip   = $file . '.gz';
	if ( false !== strpos( $accept, 'gzip' ) && is_readable( $gzip ) && ! $zlib_on ) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . (string) filesize( $gzip ) );
		if ( ! $head_only ) {
			readfile( $gzip );
		}
		exit;
	}

	// Con zlib.output_compression attiva PHP comprime l'output al volo:
	// il Content-Length del file non corrisponderebbe alla risposta.
	if ( ! $zlib_on ) {
		header( 'Content-Length: ' . (string) filesize( $file ) );
	}
	if ( ! $head_only ) {
		readfile( $file );
	}
	exit;
}

endif;

// Esecuzione (dopo le definizioni: le funzioni in blocchi condizionali non
// sono disponibili prima che il blocco venga eseguito).
if ( ! defined( 'SKIT_ADVANCED_CACHE' ) ) {
	// Segnala al plugin che il drop-in è attivo e aggiornato.
	define( 'SKIT_ADVANCED_CACHE', '1.2.0' );

	// SKIT_PLUGIN_DIR esiste solo se siamo stati inclusi dal plugin a
	// WordPress già avviato: in quel caso niente serve, solo definizioni.
	if ( ! defined( 'SKIT_PLUGIN_DIR' ) ) {
		skit_ac_maybe_serve();
	}
}
