<?php
/**
 * Plugin settings.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage and sanitisation.
 */
class Settings {

	const OPTION = 'avesta_sync_settings';

	/**
	 * Valid scopes.
	 *
	 * @return string[]
	 */
	public static function scopes() {
		return array( 'theme', 'plugins', 'content', 'uploads' );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$theme = wp_get_theme( 'avestatech' )->exists() ? 'avestatech' : get_stylesheet();
		return array(
			'remote_url' => '',
			'remote_key' => '',
			'scopes'     => array( 'theme' ),
			'themes'     => array( $theme ),
			'plugins'    => array(),
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Installed theme folder slugs.
	 *
	 * @return string[]
	 */
	public static function theme_slugs() {
		return array_map( 'strval', array_keys( wp_get_themes() ) );
	}

	/**
	 * Plugin folders (excluding this plugin).
	 *
	 * @return string[]
	 */
	public static function plugin_folders() {
		$out = array();
		foreach ( (array) glob( trailingslashit( WP_PLUGIN_DIR ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$slug = basename( $dir );
			if ( strtolower( basename( untrailingslashit( AVESTA_SYNC_DIR ) ) ) !== strtolower( $slug ) && Paths::valid_slug( $slug ) ) {
				$out[] = $slug;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Sanitise submitted settings.
	 *
	 * @param array $in      Raw input (unslashed).
	 * @param array $current Current settings.
	 * @return array
	 */
	public static function sanitize( array $in, array $current ) {
		$url = isset( $in['remote_url'] ) ? untrailingslashit( esc_url_raw( trim( (string) $in['remote_url'] ) ) ) : '';
		$key = isset( $in['remote_key'] ) ? preg_replace( '/[^\x21-\x7E]/', '', (string) $in['remote_key'] ) : '';
		return array(
			'remote_url' => $url,
			'remote_key' => '' !== $key ? $key : $current['remote_key'],
			'scopes'     => array_values( array_intersect( self::scopes(), isset( $in['scopes'] ) ? (array) $in['scopes'] : array() ) ),
			'themes'     => array_values( array_intersect( self::theme_slugs(), isset( $in['themes'] ) ? array_map( 'strval', (array) $in['themes'] ) : array() ) ),
			'plugins'    => array_values( array_intersect( self::plugin_folders(), isset( $in['plugins'] ) ? array_map( 'strval', (array) $in['plugins'] ) : array() ) ),
		);
	}

	/**
	 * Items (folder slugs) for a scope.
	 *
	 * @param string $scope    Scope.
	 * @param array  $settings Settings.
	 * @return string[]
	 */
	public static function scope_items( $scope, array $settings ) {
		if ( 'theme' === $scope ) {
			return (array) $settings['themes'];
		}
		if ( 'plugins' === $scope ) {
			return (array) $settings['plugins'];
		}
		return array();
	}
}
