=== SpeedKit ===
Contributors: speedkit
Tags: cache, performance, page cache, defer js, preload
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Caching e ottimizzazione performance senza abbonamenti: page cache su disco, purge automatico, regole browser cache, defer/delay JS, preload da sitemap.

== Description ==

SpeedKit è un plugin di caching completo e autonomo (nessun servizio esterno, nessuna licenza) pensato per sostituire WP Rocket sulle funzionalità che contano davvero:

* **Page cache su disco** con drop-in `advanced-cache.php`: le pagine in cache vengono servite senza caricare plugin e tema.
* **Serve diretto da Apache** (mod_rewrite): sulle cache hit PHP non parte proprio, come WP Rocket.
* **Varianti** https e mobile, con pre-compressione gzip dei file e supporto 304 Not Modified.
* **Purge automatico**: salvataggio/eliminazione post e pagine (con home, archivi, autore, date), commenti, termini, cambio tema/menu/widget. Cron orario per la scadenza dei file.
* **Regole .htaccess**: browser caching (Expires/Cache-Control immutable), gzip via mod_deflate, no ETag.
* **Ottimizzazioni HTML**: minify HTML, defer JS con esclusioni (filtro `skit_exclude_defer_js`), delay JS alla prima interazione (filtro `skit_delay_js_exclusions`), lazyload iframe, dns-prefetch.
* **Media**: lazyload immagini con skip delle prime N (LCP salvaguardato), facade YouTube (thumbnail + play, player caricato solo al click).
* **Asset**: minify CSS locale con cache e cache-busting automatico, Google Fonts con display=swap e preconnect, preload dei font critici, rimozione script emoji.
* **Preload link**: prefetch delle pagine interne al passaggio del mouse (stile instant.page).
* **CDN**: riscrittura degli URL statici verso un host CDN/pull-zone.
* **Database**: pulizia di revisioni, bozze automatiche, cestino, spam e transient scaduti, manuale o settimanale.
* **Preload da sitemap**: dopo ogni purge completo la cache viene rigenerata in background dalla sitemap (Yoast, RankMath, core WP).
* **Trucchi ereditati da WP Rocket**: cookie commenti a 3 minuti, esclusione automatica di utenti loggati, WooCommerce (carrello/checkout), query string di tracciamento ignorate (utm_*, fbclid, gclid...).

Niente di ciò che segue, per scelta: Remove Unused CSS e Critical CSS (dipendono dal SaaS di WP Rocket), CDN a pagamento, tracking, licenze.

== Installation ==

1. **Disattiva WP Rocket** (e i suoi helper) prima di attivare SpeedKit: due plugin di cache non possono convivere.
2. Attiva SpeedKit: installa da solo drop-in, costante WP_CACHE, regole .htaccess e file di configurazione.
3. Controlla Impostazioni → SpeedKit: se il drop-in risulta "NON attivo" usa "Reinstalla componenti".
4. Verifica una pagina da navigazione anonima: nel sorgente deve comparire `<!-- Cached by SpeedKit ... -->` e alla seconda visita l'header `X-SpeedKit-Cache: HIT` (o il serve diretto Apache).

== Frequently Asked Questions ==

= Come escludo uno script dal defer? =
Impostazioni → SpeedKit → Defer JavaScript: una regex per riga (es. `/wp-content/plugins/mio-plugin/(.*).js`), oppure via codice con il filtro `skit_exclude_defer_js` (stessa logica di `rocket_exclude_defer_js`).

= Come escludo una pagina dalla cache? =
Aggiungi una regex in "URI esclusi", oppure definisci `DONOTCACHEPAGE` nel template, oppure usa il filtro `skit_do_cache_page`.

= Funziona con Nginx? =
Sì tramite il drop-in PHP (che è comunque molto veloce). Il serve diretto senza PHP richiede Apache; su Cloudways (nginx davanti ad Apache) le regole .htaccess funzionano.

== Changelog ==

= 1.1.0 =
* Lazyload immagini avanzato, facade YouTube, minify CSS locale, Google Fonts swap/preconnect, preload font, preload link al hover, CDN, pulizia database, rimozione emoji.

= 1.0.0 =
* Prima versione: page cache, purge automatico, .htaccess, minify HTML, defer/delay JS, lazyload iframe, dns-prefetch, preload da sitemap.
