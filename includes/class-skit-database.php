<?php
/**
 * Database — Database cleanup: revisions, auto-drafts, trashed posts,
 * spam/trashed comments, expired transients. Equivalent of WP Rocket's
 * Database tab, with an optional weekly cron.
 *
 * @package SpeedKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKIT_Database {

	const CRON_HOOK = 'skit_db_cleanup';

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
	 * Registers the weekly cron when enabled.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'cleanup' ) );
		// On init, not plugins_loaded: wp_schedule_event() runs the
		// cron_schedules filters, whose labels are translated strings.
		add_action( 'init', array( $this, 'schedule' ) );
	}

	/**
	 * Schedules or clears the weekly cleanup event according to the settings.
	 */
	public function schedule() {
		if ( ! empty( $this->settings['db_auto_cleanup'] ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Runs the cleanup. Returns the number of removed items per type
	 * (shown on the admin page).
	 *
	 * Keys: revisions, auto_drafts, trashed_posts, spam_comments,
	 * expired_transients.
	 *
	 * @return array<string,int>
	 */
	public function cleanup() {
		global $wpdb;

		$results = array();

		// Revisions.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_post_revision( (int) $id );
		}
		$results['revisions'] = count( $ids );

		// Auto-drafts.
		// Only auto-drafts older than 7 days, like core's wp_delete_auto_drafts():
		// a fresh auto-draft is a post someone is writing right now.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s",
				wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$results['auto_drafts'] = count( $ids );

		// Trashed posts.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$results['trashed_posts'] = count( $ids );

		// Spam and trashed comments.
		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		$results['spam_comments'] = count( $ids );

		// Expired transients (the _transient_ / _transient_timeout_ pairs).
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
		$results['expired_transients'] = count( $expired );

		/**
		 * Fires when the cleanup is done.
		 *
		 * @param array<string,int> $results
		 */
		do_action( 'skit_after_db_cleanup', $results );

		return $results;
	}
}
