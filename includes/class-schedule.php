<?php
/**
 * Scheduled read-only comparison with optional e-mail notification.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron integration. Never applies anything.
 */
class Schedule {

	const HOOK     = 'avesta_sync_scheduled_check';
	const LAST     = 'avesta_sync_last_check';
	const NOTIFIED = 'avesta_sync_last_notified';

	/**
	 * Hooks (must run on every request so cron can fire).
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * (Re)schedule according to settings; clears the event when off.
	 *
	 * @param array $settings Settings.
	 */
	public static function sync( array $settings ) {
		self::clear();
		$mode = isset( $settings['schedule'] ) ? (string) $settings['schedule'] : 'off';
		if ( 'off' !== $mode && in_array( $mode, Settings::schedules(), true ) && ! empty( $settings['remote_url'] ) && ! empty( $settings['remote_key'] ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $mode, self::HOOK );
		}
	}

	/**
	 * Remove the cron event.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Next scheduled run.
	 *
	 * @return int|null
	 */
	public static function next() {
		$t = wp_next_scheduled( self::HOOK );
		return $t ? (int) $t : null;
	}

	/**
	 * Persist the outcome of a comparison (or its error).
	 *
	 * @param array|\WP_Error $res Result of Sync::compare.
	 * @return array Stored record.
	 */
	public static function store( $res ) {
		$rec = array(
			'time'      => time(),
			'counts'    => array(),
			'total'     => 0,
			'identical' => 0,
			'error'     => '',
		);
		if ( is_wp_error( $res ) ) {
			$rec['error'] = $res->get_error_message();
		} else {
			foreach ( $res['rows'] as $row ) {
				$st                   = (string) $row['status'];
				$rec['counts'][ $st ] = ( isset( $rec['counts'][ $st ] ) ? $rec['counts'][ $st ] : 0 ) + 1;
			}
			$rec['total']     = count( $res['rows'] );
			$rec['identical'] = (int) $res['identical'];
			if ( ! empty( $res['wc_skipped'] ) ) {
				// The WooCommerce scope is on but was not compared (blocked or no sub-option ticked): not "in sync".
				$rec['wc_skipped'] = true;
				$rec['wc_block']   = ! empty( $res['wc_block'] ) ? (string) $res['wc_block'] : 'no_options';
			}
		}
		update_option( self::LAST, $rec, false );
		return $rec;
	}

	/**
	 * Stored last check.
	 *
	 * @return array|null
	 */
	public static function last() {
		$l = get_option( self::LAST, null );
		return is_array( $l ) ? $l : null;
	}

	/**
	 * Cron handler: compare read-only, record, notify on change.
	 */
	public static function run() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync step; the host may disable set_time_limit.
		@set_time_limit( 300 );
		$s = Settings::get();
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) || ! $s['scopes'] ) {
			self::store( is_wp_error( $c ) ? $c : new \WP_Error( 'avesta_sync_scope', __( 'No scope selected.', 'avesta-sync' ) ) );
			return;
		}
		$res = Sync::compare( $c, $s, $s['scopes'] );
		Diff::flush();
		Files::flush();
		$rec = self::store( $res );
		if ( is_wp_error( $res ) ) {
			return;
		}
		if ( 0 === $rec['total'] ) {
			delete_option( self::NOTIFIED );
			return;
		}
		$ids = array();
		foreach ( $res['rows'] as $row ) {
			$ids[] = $row['scope'] . '|' . $row['id'] . '|' . $row['status'];
		}
		sort( $ids );
		$fp = md5( implode( "\n", $ids ) );
		if ( '' !== $s['notify_email'] && is_email( $s['notify_email'] ) && get_option( self::NOTIFIED, '' ) !== $fp ) {
			if ( self::notify( $s['notify_email'], $rec ) ) {
				update_option( self::NOTIFIED, $fp, false );
			}
		}
	}

	/**
	 * Send the summary e-mail.
	 *
	 * @param string $to  Recipient.
	 * @param array  $rec Stored check record.
	 * @return bool
	 */
	private static function notify( $to, array $rec ) {
		$lines = array();
		foreach ( $rec['counts'] as $status => $n ) {
			$lines[] = sprintf( '%s: %d', $status, $n );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Avesta Sync: differences found', 'avesta-sync' ), $site );
		$body    = sprintf(
			/* translators: 1: number of differing items, 2: number of identical items. */
			__( 'The scheduled check found %1$d differing item(s) (%2$d identical).', 'avesta-sync' ),
			$rec['total'],
			$rec['identical']
		) . "\n\n" . implode( "\n", $lines ) . "\n\n" . __( 'Review and apply:', 'avesta-sync' ) . ' ' . admin_url( 'tools.php?page=' . Admin::SLUG ) . "\n";
		return wp_mail( $to, $subject, $body );
	}
}
