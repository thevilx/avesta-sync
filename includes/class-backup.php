<?php
/**
 * Backups of overwritten / deleted items and temp storage.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Backup helper.
 */
class Backup {

	/**
	 * Protected backup directory (created on demand).
	 *
	 * @return string
	 */
	public static function dir() {
		$token = get_option( 'avesta_sync_backup_token', '' );
		if ( ! is_string( $token ) || ! preg_match( '/^[a-z0-9]{16,}$/', $token ) ) {
			$token = strtolower( wp_generate_password( 20, false, false ) );
			update_option( 'avesta_sync_backup_token', $token, false );
		}
		$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/avesta-sync-backups-' . $token;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	/**
	 * Temp dir for partial uploads.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		$dir = self::dir() . '/.tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * New run id.
	 *
	 * @return string
	 */
	public static function new_run() {
		return gmdate( 'Ymd-His' );
	}

	/**
	 * Validate a run id or create a new one.
	 *
	 * @param mixed $run Candidate.
	 * @return string
	 */
	public static function clean_run( $run ) {
		return ( is_string( $run ) && preg_match( '/^\d{8}-\d{6}$/', $run ) ) ? $run : self::new_run();
	}

	/**
	 * Copy an existing file into the backup tree.
	 *
	 * @param string $abs Absolute path (already validated).
	 * @param string $rel Relative path (already validated).
	 * @param string $run Run id.
	 * @return bool
	 */
	public static function save_file( $abs, $rel, $run ) {
		if ( ! is_file( $abs ) ) {
			return true;
		}
		$dest = self::dir() . '/' . self::clean_run( $run ) . '/' . $rel;
		wp_mkdir_p( dirname( $dest ) );
		return copy( $abs, $dest );
	}

	/**
	 * Save structured data (content item) into the backup tree.
	 *
	 * @param string $name Item key.
	 * @param mixed  $data Data.
	 * @param string $run  Run id.
	 * @return bool
	 */
	public static function save_json( $name, $data, $run ) {
		$dir = self::dir() . '/' . self::clean_run( $run ) . '/content';
		wp_mkdir_p( $dir );
		$safe = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ), '._' );
		return false !== file_put_contents( $dir . '/' . substr( $safe, 0, 150 ) . '.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Remove stale partial uploads (older than a day).
	 */
	public static function purge_tmp() {
		foreach ( (array) glob( self::tmp_dir() . '/*.part' ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}
}
