<?php
/**
 * REST API (server side).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under avesta-sync/v1. All are HMAC-protected.
 */
class Rest {

	const NS = 'avesta-sync/v1';

	/**
	 * Hook.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register() {
		$routes = array(
			'info'           => array( 'GET', 'info' ),
			'manifest'       => array( 'POST', 'manifest' ),
			'file/get'       => array( 'POST', 'file_get' ),
			'file/put'       => array( 'POST', 'file_put' ),
			'file/delete'    => array( 'POST', 'file_delete' ),
			'content/export' => array( 'POST', 'content_export' ),
			'content/import' => array( 'POST', 'content_import' ),
			'content/delete' => array( 'POST', 'content_delete' ),
		);
		foreach ( $routes as $path => $def ) {
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => array( Auth::class, 'permission' ),
				)
			);
		}
	}

	/**
	 * Decoded JSON body.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private static function body( $r ) {
		$d = json_decode( $r->get_body(), true );
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Raise limits for heavy calls.
	 */
	private static function heavy() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@set_time_limit( 300 );
		ignore_user_abort( true );
	}

	/**
	 * GET /info.
	 *
	 * @return array
	 */
	public static function info() {
		$themes = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$themes[] = array(
				'slug' => (string) $slug,
				'name' => $t->get( 'Name' ),
			);
		}
		return array(
			'version'             => AVESTA_SYNC_VERSION,
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'site_url'            => home_url(),
			'post_max_size'       => (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ),
			'upload_max_filesize' => (int) wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			'themes'              => $themes,
			'plugins'             => Settings::plugin_folders(),
			'time'                => time(),
		);
	}

	/**
	 * POST /manifest.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function manifest( $r ) {
		self::heavy();
		$b     = self::body( $r );
		$items = isset( $b['items'] ) && is_array( $b['items'] ) ? array_map( 'strval', $b['items'] ) : array();
		$files = Files::manifest( isset( $b['scope'] ) ? (string) $b['scope'] : '', $items );
		return is_wp_error( $files ) ? $files : array( 'files' => $files );
	}

	/**
	 * POST /file/get.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function file_get( $r ) {
		$b = self::body( $r );
		return Files::read( isset( $b['path'] ) ? $b['path'] : '', isset( $b['offset'] ) ? $b['offset'] : 0, isset( $b['length'] ) ? $b['length'] : 1048576 );
	}

	/**
	 * POST /file/put.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function file_put( $r ) {
		self::heavy();
		$b = self::body( $r );
		return Files::write(
			isset( $b['path'] ) ? $b['path'] : '',
			isset( $b['offset'] ) ? $b['offset'] : 0,
			isset( $b['total'] ) ? $b['total'] : 0,
			isset( $b['sha256'] ) ? $b['sha256'] : '',
			isset( $b['chunk'] ) ? $b['chunk'] : '',
			! empty( $b['final'] ),
			Backup::clean_run( isset( $b['run'] ) ? $b['run'] : '' )
		);
	}

	/**
	 * POST /file/delete.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function file_delete( $r ) {
		$b = self::body( $r );
		return Files::delete( isset( $b['path'] ) ? $b['path'] : '', Backup::clean_run( isset( $b['run'] ) ? $b['run'] : '' ) );
	}

	/**
	 * POST /content/export.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public static function content_export( $r ) {
		self::heavy();
		$b     = self::body( $r );
		$types = isset( $b['types'] ) && is_array( $b['types'] ) ? array_map( 'strval', $b['types'] ) : array( 'all' );
		$keys  = isset( $b['keys'] ) && is_array( $b['keys'] ) ? array_map( 'strval', $b['keys'] ) : null;
		return array( 'items' => Content::export( $types, ! empty( $b['hashes_only'] ), $keys ) );
	}

	/**
	 * POST /content/import.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public static function content_import( $r ) {
		self::heavy();
		$b = self::body( $r );
		return array( 'results' => Importer::import( isset( $b['items'] ) && is_array( $b['items'] ) ? $b['items'] : array(), isset( $b['run'] ) ? $b['run'] : '' ) );
	}

	/**
	 * POST /content/delete.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public static function content_delete( $r ) {
		self::heavy();
		$b = self::body( $r );
		return array( 'results' => Importer::delete( isset( $b['keys'] ) && is_array( $b['keys'] ) ? array_map( 'strval', $b['keys'] ) : array(), isset( $b['run'] ) ? $b['run'] : '' ) );
	}
}
