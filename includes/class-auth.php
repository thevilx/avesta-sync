<?php
/**
 * HMAC request authentication.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies REST requests.
 */
class Auth {

	const OPT_KEY   = 'avesta_sync_key';
	const MAX_SKEW  = 300;
	const MAX_FAILS = 10;

	/**
	 * Activation: create key and backup dir.
	 */
	public static function activate() {
		if ( '' === self::key() ) {
			self::regenerate();
		}
		Backup::dir();
	}

	/**
	 * This site's secret key.
	 *
	 * @return string
	 */
	public static function key() {
		$key = get_option( self::OPT_KEY, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Generate a new key.
	 *
	 * @return string
	 */
	public static function regenerate() {
		$key = wp_generate_password( 64, false, false );
		update_option( self::OPT_KEY, $key, false );
		return $key;
	}

	/**
	 * Compute signature.
	 *
	 * @param string $key    Secret.
	 * @param string $method HTTP method.
	 * @param string $route  REST route, e.g. /avesta-sync/v1/info.
	 * @param string $ts     Unix timestamp.
	 * @param string $body   Raw body.
	 * @return string
	 */
	public static function sign( $key, $method, $route, $ts, $body ) {
		return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $ts . "\n" . hash( 'sha256', (string) $body ), $key );
	}

	/**
	 * REST permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission( $request ) {
		static $verified = array();
		$sig_hdr = strtolower( (string) $request->get_header( 'X-AS-Signature' ) );
		if ( isset( $verified[ $sig_hdr ] ) ) {
			return true; // Same request evaluated again within this PHP process.
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$tkey   = 'avesta_sync_fail_' . md5( $remote );
		$fails  = (int) get_transient( $tkey );
		if ( $fails >= self::MAX_FAILS ) {
			return new \WP_Error( 'avesta_sync_throttled', 'Too many failed attempts. Try again later.', array( 'status' => 429 ) );
		}
		$key = self::key();
		if ( '' === $key ) {
			return new \WP_Error( 'avesta_sync_nokey', 'Sync is not configured on this site.', array( 'status' => 503 ) );
		}
		$ts  = (string) $request->get_header( 'X-AS-Timestamp' );
		$sig = strtolower( (string) $request->get_header( 'X-AS-Signature' ) );
		$ok  = false;
		if ( ctype_digit( $ts ) && abs( time() - (int) $ts ) <= self::MAX_SKEW && preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			$expected = self::sign( $key, $request->get_method(), $request->get_route(), $ts, $request->get_body() );
			$ok       = hash_equals( $expected, $sig );
			$seen     = 'avesta_sync_sig_' . md5( $sig );
			if ( $ok && get_transient( $seen ) ) {
				$ok = false;
			} elseif ( $ok ) {
				set_transient( $seen, 1, self::MAX_SKEW );
			}
		}
		if ( ! $ok ) {
			set_transient( $tkey, $fails + 1, 300 );
			return new \WP_Error( 'avesta_sync_forbidden', 'Invalid or expired signature.', array( 'status' => 401 ) );
		}
		$verified[ $sig ] = true;
		return true;
	}
}
