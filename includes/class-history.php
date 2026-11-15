<?php
/**
 * Run history (what was applied, by whom, with what result).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in a non-autoloaded option, newest run first.
 */
class History {

	const OPTION    = 'avesta_sync_history';
	const MAX_RUNS  = 50;
	const MAX_ITEMS = 200;
	const MAX_STR   = 300;

	/**
	 * All runs, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? array_values( $h ) : array();
	}

	/**
	 * Forget everything.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function cut( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::MAX_STR, 'UTF-8' ) : substr( $s, 0, self::MAX_STR );
	}

	/**
	 * Display name of whoever triggered the action.
	 *
	 * @param string $source admin|cli.
	 * @return string
	 */
	private static function who( $source ) {
		if ( 'cli' === $source ) {
			return 'WP-CLI';
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'Scheduled';
		}
		$u = wp_get_current_user();
		return $u && $u->exists() ? (string) $u->display_name : '';
	}

	/**
	 * Log one applied item; the run entry is created on the first item.
	 *
	 * @param string          $run     Run id.
	 * @param string          $source  admin|cli.
	 * @param string          $remote  Remote URL.
	 * @param array           $item    {scope,id,action,del,label?}.
	 * @param bool            $ok      Success.
	 * @param string|\WP_Error $message Result message.
	 */
	public static function log_item( $run, $source, $remote, array $item, $ok, $message ) {
		$hist  = self::all();
		$run   = (string) $run;
		$index = null;
		foreach ( $hist as $i => $r ) {
			if ( isset( $r['run'] ) && $r['run'] === $run ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			array_unshift(
				$hist,
				array(
					'run'      => $run,
					'time'     => time(),
					'user'     => self::who( $source ),
					'source'   => 'cli' === $source ? 'cli' : 'admin',
					'remote'   => (string) $remote,
					'ok'       => 0,
					'failed'   => 0,
					'overflow' => 0,
					'items'    => array(),
				)
			);
			$index = 0;
		}
		$ok = (bool) $ok;
		if ( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}
		$entry = array(
			'scope'   => isset( $item['scope'] ) ? (string) $item['scope'] : '',
			'id'      => isset( $item['id'] ) ? self::cut( $item['id'] ) : '',
			'label'   => isset( $item['label'] ) && '' !== $item['label'] ? self::cut( $item['label'] ) : ( isset( $item['id'] ) ? self::cut( $item['id'] ) : '' ),
			'action'  => isset( $item['action'] ) && 'pull' === $item['action'] ? 'pull' : 'push',
			'del'     => ! empty( $item['del'] ),
			'ok'      => $ok,
			'message' => self::cut( wp_strip_all_tags( (string) $message ) ),
		);
		if ( $ok ) {
			++$hist[ $index ]['ok'];
		} else {
			++$hist[ $index ]['failed'];
		}
		if ( count( $hist[ $index ]['items'] ) < self::MAX_ITEMS ) {
			$hist[ $index ]['items'][] = $entry;
		} else {
			$hist[ $index ]['overflow'] = (int) $hist[ $index ]['overflow'] + 1;
		}
		update_option( self::OPTION, array_slice( $hist, 0, self::MAX_RUNS ), false );
	}
}
