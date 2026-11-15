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
		return array(
			'remote_url'    => '',
			'remote_key'    => '',
			'scopes'        => array( 'theme' ),
			'themes'        => array( get_stylesheet() ),
			'plugins'       => array(),
			'excludes'      => array(),
			'schedule'      => 'off',
			'notify_email'  => '',
			'backup_keep'   => 20,
			'media_library' => true,
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
		$schedule = isset( $in['schedule'] ) ? sanitize_key( (string) $in['schedule'] ) : $current['schedule'];
		$email    = isset( $in['notify_email'] ) ? sanitize_email( trim( (string) $in['notify_email'] ) ) : $current['notify_email'];
		$keep     = isset( $in['backup_keep'] ) ? max( 0, min( 200, absint( $in['backup_keep'] ) ) ) : (int) $current['backup_keep'];
		return array(
			'remote_url'    => $url,
			'remote_key'    => '' !== $key ? $key : $current['remote_key'],
			'scopes'        => array_values( array_intersect( self::scopes(), isset( $in['scopes'] ) ? (array) $in['scopes'] : array() ) ),
			'themes'        => array_values( array_intersect( self::theme_slugs(), isset( $in['themes'] ) ? array_map( 'strval', (array) $in['themes'] ) : array() ) ),
			'plugins'       => array_values( array_intersect( self::plugin_folders(), isset( $in['plugins'] ) ? array_map( 'strval', (array) $in['plugins'] ) : array() ) ),
			'excludes'      => isset( $in['excludes'] ) ? self::clean_excludes( $in['excludes'] ) : self::clean_excludes( $current['excludes'] ),
			'schedule'      => in_array( $schedule, self::schedules(), true ) ? $schedule : 'off',
			'notify_email'  => is_email( $email ) ? $email : '',
			'backup_keep'   => $keep,
			'media_library' => ! empty( $in['media_library'] ) && '0' !== (string) $in['media_library'],
		);
	}

	/**
	 * Capability required to see the key and drive syncs.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Valid schedule values.
	 *
	 * @return string[]
	 */
	public static function schedules() {
		return array( 'off', 'hourly', 'twicedaily', 'daily' );
	}

	/**
	 * Normalise exclude patterns (textarea string or array): max 50, each max 200 chars.
	 *
	 * @param mixed $raw String (one per line) or list.
	 * @return string[]
	 */
	public static function clean_excludes( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\R/', $raw );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = trim( preg_replace( '/[\x00-\x1f\x7f\\\\]/', '', $line ) );
			$line = trim( substr( $line, 0, 200 ) );
			$line = (string) preg_replace( '/\*{2,}/', '*', $line );
			if ( substr_count( $line, '*' ) + substr_count( $line, '?' ) > 10 ) {
				continue;
			}
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
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
