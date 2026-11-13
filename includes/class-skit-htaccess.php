<?php
/**
 * Htaccess — Scrive le regole Apache tra i marker BEGIN/END SpeedKit:
 * compressione gzip, scadenze browser cache e serve diretto dei file di
 * cache via mod_rewrite (senza mai avviare PHP/WordPress).
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Htaccess {

	const MARKER = 'SpeedKit';

	/**
	 * Scrive (o riscrive) il blocco di regole in .htaccess.
	 *
	 * Il blocco viene messo IN CIMA al file, prima di "# BEGIN WordPress":
	 * la regola di WP (RewriteRule . /index.php [L]) altrimenti
	 * intercetterebbe le richieste prima del nostro serve diretto.
	 * Stesso approccio di WP Rocket.
	 *
	 * @param array $settings Impostazioni correnti.
	 * @return bool
	 */
	public static function write_rules( array $settings ) {
		$rules = implode( "\n", self::build_rules( $settings ) );

		return self::replace_block( $rules );
	}

	/**
	 * Rimuove il blocco di regole (disattivazione/uninstall).
	 *
	 * @return bool
	 */
	public static function remove_rules() {
		return self::replace_block( '' );
	}

	/**
	 * Sostituisce (o rimuove se vuoto) il blocco tra i marker, in testa al file.
	 *
	 * @param string $rules Contenuto del blocco senza marker; '' per rimuovere.
	 * @return bool
	 */
	private static function replace_block( $rules ) {
		$file = self::htaccess_path();
		if ( '' === $file ) {
			return false;
		}

		$exists = file_exists( $file );
		if ( $exists ? ! is_writable( $file ) : ! is_writable( dirname( $file ) ) ) {
			return false;
		}

		$original = $exists ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content  = $original;
		if ( '' === $content && '' === $rules ) {
			return true;
		}

		$begin = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;

		// Rimuove il blocco esistente ovunque si trovi.
		$pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\s*/s';
		$content = (string) preg_replace( $pattern, '', $content );
		$content = ltrim( $content, "\n" );

		if ( '' !== $rules ) {
			$content = $begin . "\n" . $rules . "\n" . $end . "\n\n" . $content;
		}

		if ( $content === $original ) {
			return true;
		}

		// Verified write with rollback to the previous content.
		return SKIT_Config::safe_write( $file, $content, $original );
	}

	/**
	 * True if .htaccess currently contains the SpeedKit block.
	 *
	 * @return bool
	 */
	public static function has_rules() {
		$file = self::htaccess_path();
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		return false !== strpos( (string) file_get_contents( $file ), '# BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Percorso del file .htaccess della root del sito.
	 *
	 * @return string
	 */
	private static function htaccess_path() {
		// get_home_path() also handles WordPress installed in a subdirectory
		// with the site served from the parent one.
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;

		return $home ? trailingslashit( $home ) . '.htaccess' : '';
	}

	/**
	 * Costruisce le righe del blocco regole.
	 *
	 * @param array $settings Impostazioni correnti.
	 * @return string[]
	 */
	private static function build_rules( array $settings ) {
		$lines = array();

		$lines[] = '# Regole generate da SpeedKit ' . SKIT_VERSION . ' — non modificare a mano.';

		// Charset e niente ETag (usiamo Last-Modified + Expires).
		$lines[] = 'AddDefaultCharset UTF-8';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = 'Header unset ETag';
		$lines[] = '</IfModule>';
		$lines[] = 'FileETag None';

		if ( ! empty( $settings['htaccess_gzip'] ) ) {
			$lines = array_merge( $lines, self::gzip_rules() );
		}

		if ( ! empty( $settings['htaccess_browser_cache'] ) ) {
			$lines = array_merge( $lines, self::expires_rules() );
		}

		if ( ! empty( $settings['htaccess_direct_serve'] ) && ! empty( $settings['cache_enabled'] ) && '' !== self::cache_root_relative() ) {
			$lines = array_merge( $lines, self::rewrite_rules( $settings ) );
		}

		return $lines;
	}

	/**
	 * Compressione gzip via mod_deflate.
	 *
	 * @return string[]
	 */
	private static function gzip_rules() {
		return array(
			'<IfModule mod_deflate.c>',
			'AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml text/javascript text/x-component',
			'AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json application/xml application/xhtml+xml application/rss+xml application/atom+xml',
			'AddOutputFilterByType DEFLATE application/vnd.ms-fontobject font/ttf font/otf font/opentype image/svg+xml image/x-icon',
			'<IfModule mod_setenvif.c>',
			'BrowserMatch ^Mozilla/4 gzip-only-text/html',
			'BrowserMatch ^Mozilla/4\.0[678] no-gzip',
			'BrowserMatch \bMSIE !no-gzip !gzip-only-text/html',
			'</IfModule>',
			'</IfModule>',
		);
	}

	/**
	 * Scadenze browser cache per tipo di contenuto.
	 *
	 * @return string[]
	 */
	private static function expires_rules() {
		return array(
			'<IfModule mod_expires.c>',
			'ExpiresActive on',
			'ExpiresDefault "access plus 1 month"',
			'# HTML e feed sempre rivalidati (la cache pagina la gestisce SpeedKit).',
			'ExpiresByType text/html "access plus 0 seconds"',
			'ExpiresByType application/rss+xml "access plus 1 hour"',
			'ExpiresByType application/atom+xml "access plus 1 hour"',
			'ExpiresByType application/json "access plus 0 seconds"',
			'# Asset statici.',
			'ExpiresByType text/css "access plus 1 year"',
			'ExpiresByType application/javascript "access plus 1 year"',
			'ExpiresByType image/jpeg "access plus 1 year"',
			'ExpiresByType image/png "access plus 1 year"',
			'ExpiresByType image/gif "access plus 1 year"',
			'ExpiresByType image/webp "access plus 1 year"',
			'ExpiresByType image/avif "access plus 1 year"',
			'ExpiresByType image/svg+xml "access plus 1 year"',
			'ExpiresByType image/x-icon "access plus 1 year"',
			'ExpiresByType video/mp4 "access plus 1 year"',
			'ExpiresByType font/ttf "access plus 1 year"',
			'ExpiresByType font/otf "access plus 1 year"',
			'ExpiresByType font/woff "access plus 1 year"',
			'ExpiresByType font/woff2 "access plus 1 year"',
			'ExpiresByType application/pdf "access plus 1 month"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "\.(?:css|js|woff2?|ttf|otf|jpe?g|png|gif|webp|avif|svg|ico|mp4)$">',
			'Header set Cache-Control "public, max-age=31536000, immutable"',
			'</FilesMatch>',
			'# Font cross-origin (CDN/sottodomini).',
			'<FilesMatch "\.(?:eot|otf|ttf|woff2?)$">',
			'Header set Access-Control-Allow-Origin "*"',
			'</FilesMatch>',
			'</IfModule>',
		);
	}

	/**
	 * Serve diretto dei file di cache via mod_rewrite, senza PHP.
	 * Ricalca l'approccio di WP Rocket: se esiste il file HTML in cache per
	 * la richiesta, Apache lo serve direttamente.
	 *
	 * @param array $settings Impostazioni correnti.
	 * @return string[]
	 */
	private static function rewrite_rules( array $settings ) {
		$cache_root = self::cache_root_relative();

		// Same list used by the drop-in and by the page cache (names are
		// already restricted to [A-Za-z0-9_.-], quoted here for the regex).
		$skip_cookies = implode(
			'|',
			array_map(
				function ( $cookie ) {
					return preg_quote( $cookie, '#' );
				},
				SKIT_Config::skip_cookies( $settings )
			)
		);

		$lines   = array();
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = 'RewriteEngine On';
		$lines[] = 'RewriteBase /';
		// Variabile d'ambiente per la variante https del file di cache.
		$lines[] = 'RewriteCond %{HTTPS} on [OR]';
		$lines[] = 'RewriteCond %{SERVER_PORT} ^443$ [OR]';
		$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Proto} https';
		$lines[] = 'RewriteRule .* - [E=SKIT_SSL:-https]';
		// Condizioni: GET senza query string, niente cookie "personali".
		$lines[] = 'RewriteCond %{REQUEST_METHOD} GET';
		$lines[] = 'RewriteCond %{QUERY_STRING} =""';
		$lines[] = 'RewriteCond %{HTTP_COOKIE} !(' . $skip_cookies . ') [NC]';

		if ( ! empty( $settings['cache_mobile_separate'] ) ) {
			// Con cache mobile separata i dispositivi mobili passano da PHP
			// (il drop-in seleziona la variante -mobile).
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi) [NC]';
		}

		$target  = $cache_root . '/%{HTTP_HOST}%{REQUEST_URI}/index%{ENV:SKIT_SSL}.html';
		$lines[] = 'RewriteCond "%{DOCUMENT_ROOT}' . $target . '" -f';
		$lines[] = 'RewriteRule .* "' . $target . '" [L]';
		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * Percorso della cache relativo alla document root (es. /wp-content/cache/speed-kit).
	 *
	 * @return string
	 */
	private static function cache_root_relative() {
		// Follows a custom SKIT_CACHE_DIR; '' when it is not web-accessible.
		$cache_url = SKIT_Config::cache_url();
		if ( '' === $cache_url ) {
			return '';
		}
		$path = wp_parse_url( $cache_url, PHP_URL_PATH );

		return $path ? untrailingslashit( $path ) : '';
	}
}
