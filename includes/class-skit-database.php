<?php
/**
 * Database — Pulizia del database: revisioni, bozze automatiche, cestino,
 * commenti spam/cestinati, transient scaduti. Equivalente della scheda
 * Database di WP Rocket, con cron settimanale opzionale.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Database {

	const CRON_HOOK = 'skit_db_cleanup';

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
	 * Registra il cron settimanale se abilitato.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'cleanup' ) );

		if ( ! empty( $this->settings['db_auto_cleanup'] ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Esegue la pulizia. Restituisce il conteggio delle righe rimosse
	 * per voce (usato dalla pagina admin).
	 *
	 * @return array<string,int>
	 */
	public function cleanup() {
		global $wpdb;

		$results = array();

		// Revisioni.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_post_revision( (int) $id );
		}
		$results['revisioni'] = count( $ids );

		// Bozze automatiche.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$results['bozze automatiche'] = count( $ids );

		// Post nel cestino.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$results['post nel cestino'] = count( $ids );

		// Commenti spam e cestinati.
		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		$results['commenti spam/cestino'] = count( $ids );

		// Transient scaduti (le coppie _transient_ / _transient_timeout_).
		$expired = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		foreach ( $expired as $timeout_key ) {
			$key = str_replace( '_transient_timeout_', '', $timeout_key );
			delete_transient( $key );
		}
		$results['transient scaduti'] = count( $expired );

		/**
		 * Scatta a pulizia completata.
		 *
		 * @param array<string,int> $results
		 */
		do_action( 'skit_after_db_cleanup', $results );

		return $results;
	}
}
