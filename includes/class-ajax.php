<?php
/**
 * AJAX endpoints used by the admin page. All: POST + nonce + manage_options.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Admin AJAX layer.
 */
class Ajax {

	const ACTIONS = array( 'test', 'compare', 'apply', 'preview', 'dashboard', 'backups', 'backup_items', 'restore', 'backup_delete', 'history', 'history_clear' );

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( self::ACTIONS as $a ) {
			add_action( 'wp_ajax_avesta_sync_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Common guard: POST only, nonce, capability.
	 */
	private static function guard() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error(
				array(
					'message' => __( 'POST required.', 'avesta-sync' ),
					'code'    => 'avesta_sync_method',
				),
				405
			);
		}
		check_ajax_referer( 'avesta_sync', 'nonce' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Not allowed.', 'avesta-sync' ),
					'code'    => 'avesta_sync_forbidden',
				),
				403
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync step; the host may disable set_time_limit.
		@set_time_limit( 0 );
		ignore_user_abort( true );
	}

	/**
	 * Send a message as a JSON error.
	 *
	 * @param string $message Message.
	 * @param string $code    Code.
	 */
	private static function error( $message, $code = 'avesta_sync_error' ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => $code,
			)
		);
	}

	/**
	 * Send a WP_Error as JSON error.
	 *
	 * @param \WP_Error $e Error.
	 */
	private static function fail( $e ) {
		self::error( $e->get_error_message(), (string) $e->get_error_code() );
	}

	/**
	 * Read a plain POST string (control chars stripped, length capped, slashes removed).
	 *
	 * @param string $name Field.
	 * @param int    $max  Max length.
	 * @return string
	 */
	private static function post_str( $name, $max = 1024 ) {
		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); sanitised below.
		$v = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
		return substr( (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $v ), 0, $max );
	}

	/**
	 * Client from saved settings or fail.
	 *
	 * @param array $s Settings.
	 * @return Client
	 */
	private static function client( array $s ) {
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) ) {
			self::fail( $c );
		}
		return $c;
	}

	/**
	 * Test connection (uses posted, unsaved values if given).
	 */
	public static function handle_test() {
		self::guard();
		$s   = Settings::get();
		$url = untrailingslashit( esc_url_raw( trim( self::post_str( 'remote_url' ) ) ) );
		$key = preg_replace( '/[^\x21-\x7E]/', '', self::post_str( 'remote_key', 512 ) );
		if ( '' !== $url ) {
			$s['remote_url'] = $url;
		}
		if ( '' !== $key ) {
			$s['remote_key'] = $key;
		}
		$c    = self::client( $s );
		$info = $c->info( true );
		if ( is_wp_error( $info ) ) {
			self::fail( $info );
		}
		wp_send_json_success(
			array(
				'site'          => isset( $info['site_url'] ) ? (string) $info['site_url'] : '',
				'version'       => isset( $info['version'] ) ? (string) $info['version'] : '',
				'wp'            => isset( $info['wp_version'] ) ? (string) $info['wp_version'] : '',
				'php'           => isset( $info['php_version'] ) ? (string) $info['php_version'] : '',
				'post_max_size' => size_format( isset( $info['post_max_size'] ) ? (int) $info['post_max_size'] : 0 ),
				'chunk'         => size_format( $c->chunk_size() ),
				'themes'        => array_values( array_map( 'strval', wp_list_pluck( isset( $info['themes'] ) ? (array) $info['themes'] : array(), 'slug' ) ) ),
			) + Woo::preflight( $info )
		);
	}

	/**
	 * Compare (read-only on both sides).
	 */
	public static function handle_compare() {
		self::guard();
		$s = Settings::get();
		$c = self::client( $s );
		if ( ! $s['scopes'] ) {
			self::error( __( 'No scope selected. Tick at least one scope and save settings.', 'avesta-sync' ), 'avesta_sync_scope' );
		}
		$res = Sync::compare( $c, $s, $s['scopes'] );
		Schedule::store( $res );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		Backup::purge_tmp();
		Backup::prune( (int) $s['backup_keep'] );
		Diff::flush();
		Files::flush();
		$res['run'] = Backup::new_run();
		wp_send_json_success( $res );
	}

	/**
	 * Apply one item.
	 */
	public static function handle_apply() {
		self::guard();
		$raw = json_decode( self::post_str( 'item', 4096 ), true );
		if ( ! is_array( $raw ) ) {
			self::error( __( 'Bad request.', 'avesta-sync' ), 'avesta_sync_bad_request' );
		}
		$item = array(
			'scope'  => isset( $raw['scope'] ) && is_string( $raw['scope'] ) ? sanitize_key( $raw['scope'] ) : '',
			'id'     => isset( $raw['id'] ) && is_string( $raw['id'] ) ? $raw['id'] : '',
			'action' => isset( $raw['action'] ) && is_string( $raw['action'] ) ? sanitize_key( $raw['action'] ) : '',
			'del'    => ! empty( $raw['del'] ),
			'stock'  => ! empty( $raw['stock'] ),
			'label'  => isset( $raw['label'] ) && is_string( $raw['label'] ) ? mb_substr( sanitize_text_field( $raw['label'] ), 0, 300, 'UTF-8' ) : '',
		);
		$run  = Backup::clean_run( self::post_str( 'run', 20 ) );
		$s    = Settings::get();
		$c    = self::client( $s );
		$msg  = Sync::apply( $c, $s, $item, $run );
		Diff::flush();
		Files::flush();
		History::log_item( $run, 'admin', $c->url(), $item, ! is_wp_error( $msg ), $msg );
		if ( is_wp_error( $msg ) ) {
			self::fail( $msg );
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Diff preview for one item.
	 */
	public static function handle_preview() {
		self::guard();
		$s   = Settings::get();
		$c   = self::client( $s );
		$res = Sync::preview( $c, $s, sanitize_key( self::post_str( 'scope', 20 ) ), self::post_str( 'id' ) );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Dashboard summary (contacts the remote only when ping=1).
	 */
	public static function handle_dashboard() {
		self::guard();
		$s          = Settings::get();
		$configured = '' !== $s['remote_url'] && '' !== $s['remote_key'];
		$connected  = null;
		$error      = '';
		$remote     = null;
		$wc         = Woo::preflight( array(), false );
		if ( $configured && '1' === self::post_str( 'ping', 4 ) ) {
			$c    = Client::from_settings( $s );
			$info = is_wp_error( $c ) ? $c : $c->info( true );
			if ( is_wp_error( $info ) ) {
				$connected = false;
				$error     = $info->get_error_message();
			} else {
				$connected = true;
				$remote    = array(
					'url'     => $s['remote_url'],
					'site'    => isset( $info['site_url'] ) ? (string) $info['site_url'] : '',
					'version' => isset( $info['version'] ) ? (string) $info['version'] : '',
					'wp'      => isset( $info['wp_version'] ) ? (string) $info['wp_version'] : '',
					'php'     => isset( $info['php_version'] ) ? (string) $info['php_version'] : '',
				);
				$wc        = Woo::preflight( $info );
			}
		}
		$hist = History::all();
		$last = null;
		if ( $hist ) {
			$last = array(
				'run'    => (string) $hist[0]['run'],
				'time'   => (int) $hist[0]['time'],
				'user'   => (string) $hist[0]['user'],
				'source' => (string) $hist[0]['source'],
				'ok'     => (int) $hist[0]['ok'],
				'failed' => (int) $hist[0]['failed'],
			);
		}
		$check = Schedule::last();
		if ( $check ) {
			$check = array(
				'time'      => (int) $check['time'],
				'total'     => (int) $check['total'],
				'identical' => (int) $check['identical'],
				'counts'    => (object) $check['counts'],
				'error'      => (string) $check['error'],
				'wc_skipped' => ! empty( $check['wc_skipped'] ),
				'wc_block'   => isset( $check['wc_block'] ) ? (string) $check['wc_block'] : '',
			);
		}
		$runs = Backup::runs();
		wp_send_json_success(
			array(
				'configured' => $configured,
				'connected'  => $connected,
				'error'      => $error,
				'remote'     => $remote,
				'last_sync'  => $last,
				'last_check' => $check,
				'backups'    => array(
					'runs' => count( $runs ),
					'size' => (int) array_sum( wp_list_pluck( $runs, 'size' ) ),
				),
				'schedule'   => array(
					'mode' => $s['schedule'],
					'next' => Schedule::next(),
				),
				'scopes'     => array_values( $s['scopes'] ),
			) + $wc
		);
	}

	/**
	 * List backup runs.
	 */
	public static function handle_backups() {
		self::guard();
		wp_send_json_success( array( 'runs' => Backup::runs() ) );
	}

	/**
	 * List items in a backup run.
	 */
	public static function handle_backup_items() {
		self::guard();
		$items = Backup::items( self::post_str( 'run', 20 ) );
		if ( is_wp_error( $items ) ) {
			self::fail( $items );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Restore one backed-up item.
	 */
	public static function handle_restore() {
		self::guard();
		$msg = Backup::restore( self::post_str( 'run', 20 ), self::post_str( 'path' ) );
		Diff::flush();
		if ( is_wp_error( $msg ) ) {
			self::fail( $msg );
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Delete a backup run.
	 */
	public static function handle_backup_delete() {
		self::guard();
		$res = Backup::delete_run( self::post_str( 'run', 20 ) );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		wp_send_json_success( array( 'message' => __( 'Backup deleted.', 'avesta-sync' ) ) );
	}

	/**
	 * Run history.
	 */
	public static function handle_history() {
		self::guard();
		wp_send_json_success( array( 'entries' => History::all() ) );
	}

	/**
	 * Clear run history.
	 */
	public static function handle_history_clear() {
		self::guard();
		History::clear();
		wp_send_json_success( array( 'message' => __( 'History cleared.', 'avesta-sync' ) ) );
	}
}
