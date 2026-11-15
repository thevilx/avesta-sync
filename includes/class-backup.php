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

	const SUFFIX = '.asbak';

	/**
	 * Resolved backup directory (per request).
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Protected backup directory: <uploads>/avesta-sync-backups-<token>/ (created on demand).
	 * A legacy wp-content/avesta-sync-backups-<token>/ is moved here once; if the move fails
	 * the legacy location keeps being used.
	 *
	 * @return string
	 */
	public static function dir() {
		if ( null !== self::$dir && is_dir( self::$dir ) ) {
			return self::$dir;
		}
		$token = get_option( 'avesta_sync_backup_token', '' );
		if ( ! is_string( $token ) || ! preg_match( '/^[a-z0-9]{16,}$/', $token ) ) {
			$token = strtolower( wp_generate_password( 20, false, false ) );
			update_option( 'avesta_sync_backup_token', $token, false );
		}
		$dir = self::migrate( Paths::root_dir( 'uploads' ) . '/avesta-sync-backups-' . $token, $token );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( $dir );
		self::$dir = $dir;
		self::suffix_pass( $dir );
		self::$dir = $dir;
		return $dir;
	}

	/**
	 * Move a legacy wp-content backup directory into uploads once.
	 *
	 * @param string $dir   Target directory.
	 * @param string $token Backup token.
	 * @return string Directory to use (the legacy one if it could not be moved).
	 */
	private static function migrate( $dir, $token ) {
		$old = wp_normalize_path( WP_CONTENT_DIR ) . '/avesta-sync-backups-' . $token;
		if ( ! is_dir( $dir ) && is_dir( $old ) ) {
			wp_mkdir_p( dirname( $dir ) );
			return @rename( $old, $dir ) ? $dir : $old; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- one-time migration; fall back to the legacy path.
		}
		return $dir;
	}

	/**
	 * One-time pass: append the neutral suffix to un-suffixed backed-up files (legacy 1.0.0
	 * backups keep raw extensions such as .php). A marker file records completion; the pass is
	 * idempotent, so an interrupted run simply resumes next time. Symlinks are never followed.
	 *
	 * @param string $dir Backup directory.
	 */
	private static function suffix_pass( $dir ) {
		$marker = $dir . '/.suffixed';
		if ( ! is_dir( $dir ) || is_file( $marker ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $run_dir ) {
			if ( ! self::valid_run( basename( $run_dir ) ) || is_link( $run_dir ) ) {
				continue;
			}
			$todo = array();
			foreach ( array( 'themes', 'plugins', 'uploads' ) as $root ) {
				$tree = $run_dir . '/' . $root;
				if ( ! is_dir( $tree ) || is_link( $tree ) ) {
					continue;
				}
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tree, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f ) {
					if ( $f->isFile() && ! $f->isLink() && self::SUFFIX !== substr( $f->getFilename(), -strlen( self::SUFFIX ) ) ) {
						$todo[] = $f->getPathname();
					}
				}
			}
			foreach ( $todo as $path ) {
				if ( ! file_exists( $path . self::SUFFIX ) ) {
					rename( $path, $path . self::SUFFIX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
			if ( $todo && is_file( $run_dir . '/.stats.json' ) ) {
				wp_delete_file( $run_dir . '/.stats.json' );
			}
		}
		file_put_contents( $marker, '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Write the deny files into a backup directory.
	 *
	 * @param string $dir Backup directory.
	 */
	private static function protect( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
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
		// Neutral suffix: nothing stored in the (web-served) backup tree is directly executable.
		$dest = self::dir() . '/' . self::clean_run( $run ) . '/' . $rel . self::SUFFIX;
		wp_mkdir_p( dirname( $dest ) );
		self::invalidate( dirname( $dest ), $run );
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
		self::invalidate( $dir, $run );
		$safe = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ), '._' );
		return false !== file_put_contents( $dir . '/' . substr( $safe, 0, 150 ) . '.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Strict run id check.
	 *
	 * @param mixed $run Candidate.
	 * @return bool
	 */
	public static function valid_run( $run ) {
		return is_string( $run ) && (bool) preg_match( '/^\d{8}-\d{6}$/', $run );
	}

	/**
	 * Absolute directory of an existing run.
	 *
	 * @param mixed $run Run id.
	 * @return string|\WP_Error
	 */
	private static function run_dir( $run ) {
		if ( ! self::valid_run( $run ) ) {
			return new \WP_Error( 'avesta_sync_run', __( 'Invalid backup run.', 'avesta-sync' ) );
		}
		$dir = self::dir() . '/' . $run;
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return new \WP_Error( 'avesta_sync_run', __( 'Backup run not found.', 'avesta-sync' ) );
		}
		return $dir;
	}

	/**
	 * File count and total size of a directory tree.
	 *
	 * @param string $dir Directory.
	 * @return array {files,size}
	 */
	private static function tree_stats( $dir ) {
		$cache = $dir . '/.stats.json';
		if ( is_file( $cache ) && filemtime( $cache ) >= filemtime( $dir ) ) {
			$c = json_decode( (string) file_get_contents( $cache ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $c ) && isset( $c['files'], $c['size'] ) ) {
				return array(
					'files' => (int) $c['files'],
					'size'  => (int) $c['size'],
				);
			}
		}
		$files = 0;
		$size  = 0;
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && ! $f->isLink() && '.stats.json' !== $f->getFilename() ) {
				++$files;
				$size += $f->getSize();
			}
		}
		$stats = array(
			'files' => $files,
			'size'  => $size,
		);
		file_put_contents( $cache, wp_json_encode( $stats ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $stats;
	}

	/**
	 * Drop the cached stats of a run (called whenever a file is added to it).
	 *
	 * @param string $any_dir Any directory inside the run.
	 * @param string $run     Run id.
	 */
	private static function invalidate( $any_dir, $run ) {
		$run_dir = self::dir() . '/' . self::clean_run( $run );
		if ( is_file( $run_dir . '/.stats.json' ) ) {
			wp_delete_file( $run_dir . '/.stats.json' );
		}
	}

	/**
	 * Run ids, newest first (no filesystem walking).
	 *
	 * @return string[]
	 */
	private static function run_ids() {
		$ids = array();
		foreach ( (array) glob( self::dir() . '/*', GLOB_ONLYDIR ) as $d ) {
			if ( self::valid_run( basename( $d ) ) && ! is_link( $d ) ) {
				$ids[] = basename( $d );
			}
		}
		rsort( $ids );
		return $ids;
	}

	/**
	 * Backup runs, newest first.
	 *
	 * @return array List of {run,time,files,size}.
	 */
	public static function runs() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/*', GLOB_ONLYDIR ) as $d ) {
			$run = basename( $d );
			if ( ! self::valid_run( $run ) || is_link( $d ) ) {
				continue;
			}
			$dt    = \DateTime::createFromFormat( 'Ymd-His', $run, new \DateTimeZone( 'UTC' ) );
			$stats = self::tree_stats( $d );
			$out[] = array(
				'run'   => $run,
				'time'  => $dt ? $dt->getTimestamp() : 0,
				'files' => $stats['files'],
				'size'  => $stats['size'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['run'], $a['run'] );
			}
		);
		return $out;
	}

	/**
	 * Items inside a run.
	 *
	 * @param string $run Run id.
	 * @return array|\WP_Error List of {path,type,size}.
	 */
	public static function items( $run ) {
		$dir = self::run_dir( $run );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || $f->isLink() ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( $dir ) ) ), '/' );
			if ( 0 === strpos( $rel, 'content/' ) ) {
				if ( '.stats.json' === basename( $rel ) ) {
					continue;
				}
				$type = 'content';
			} elseif ( preg_match( '#^(themes|plugins|uploads)/#', $rel ) ) {
				$type = 'file';
				if ( self::SUFFIX === substr( $rel, -strlen( self::SUFFIX ) ) ) {
					$rel = substr( $rel, 0, -strlen( self::SUFFIX ) );
				}
			} else {
				continue;
			}
			$out[] = array(
				'path' => $rel,
				'type' => $type,
				'size' => $f->getSize(),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);
		return $out;
	}

	/**
	 * Restore one item from a run. The current state is backed up into a new run first.
	 *
	 * @param string $run  Run id.
	 * @param string $path Item path as listed by items().
	 * @return string|\WP_Error Message.
	 */
	public static function restore( $run, $path ) {
		$dir = self::run_dir( $run );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$new = self::new_run();
		if ( $new === $run ) {
			$new = gmdate( 'Ymd-His', time() + 1 );
		}
		if ( is_string( $path ) && preg_match( '#^content/[A-Za-z0-9._-]+\.json$#', $path ) ) {
			return self::restore_content( $dir . '/' . $path, $new );
		}
		$abs = Paths::resolve( $path, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$src      = realpath( $dir . '/' . $path . self::SUFFIX );
		if ( false === $src ) {
			$src = realpath( $dir . '/' . $path ); // Legacy un-suffixed backup.
		}
		$real_run = realpath( $dir );
		if ( false === $src || false === $real_run || ! is_file( $src ) || 0 !== strpos( wp_normalize_path( $src ), wp_normalize_path( $real_run ) . '/' ) ) {
			return new \WP_Error( 'avesta_sync_restore', __( 'Backup file not found.', 'avesta-sync' ) );
		}
		if ( is_dir( $abs ) ) {
			return new \WP_Error( 'avesta_sync_restore', __( 'Target is a directory.', 'avesta-sync' ) );
		}
		if ( preg_match( '/\.php$/i', $path ) && ! Files::php_ok( $src ) ) {
			return new \WP_Error( 'avesta_sync_syntax', __( 'Refused: PHP file fails to parse.', 'avesta-sync' ) );
		}
		if ( ! self::save_file( $abs, $path, $new ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'Backup of existing file failed; not overwritten.', 'avesta-sync' ) );
		}
		wp_mkdir_p( dirname( $abs ) );
		if ( ! copy( $src, $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'avesta_sync_move', __( 'Cannot move file into place.', 'avesta-sync' ) );
		}
		@chmod( $abs, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		Files::forget( $path );
		Files::flush();
		/* translators: %s: relative file path. */
		return sprintf( __( 'Restored %s (the previous version was backed up).', 'avesta-sync' ), $path );
	}

	/**
	 * Restore a content item from its JSON backup via the importer.
	 *
	 * @param string $file Absolute JSON file.
	 * @param string $new  Run id for the pre-restore backup.
	 * @return string|\WP_Error
	 */
	private static function restore_content( $file, $new ) {
		$json = is_file( $file ) && ! is_link( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['type'] ) || ! is_string( $data['type'] ) ) {
			return new \WP_Error( 'avesta_sync_restore', __( 'This backup is not in a restorable format.', 'avesta-sync' ) );
		}
		$res = Importer::import( array( basename( $file, '.json' ) => $data ), $new );
		if ( empty( $res[0]['ok'] ) ) {
			return new \WP_Error( 'avesta_sync_restore', isset( $res[0]['message'] ) ? (string) $res[0]['message'] : __( 'Restore failed.', 'avesta-sync' ) );
		}
		/* translators: %s: importer result message. */
		return sprintf( __( 'Content restored (%s).', 'avesta-sync' ), $res[0]['message'] );
	}

	/**
	 * Delete a run (recursive, confined to the backup directory).
	 *
	 * @param string $run Run id.
	 * @return true|\WP_Error
	 */
	public static function delete_run( $run ) {
		$dir = self::run_dir( $run );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$real = realpath( $dir );
		$base = realpath( self::dir() );
		if ( false === $real || false === $base || dirname( wp_normalize_path( $real ) ) !== wp_normalize_path( $base ) ) {
			return new \WP_Error( 'avesta_sync_run', __( 'Invalid backup run.', 'avesta-sync' ) );
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				rmdir( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				wp_delete_file( $f->getPathname() );
			}
		}
		if ( ! rmdir( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'avesta_sync_run', __( 'Could not delete the backup run.', 'avesta-sync' ) );
		}
		return true;
	}

	/**
	 * Keep only the newest N runs (0 = keep all).
	 *
	 * @param int $keep Runs to keep.
	 * @return int Number of runs deleted.
	 */
	public static function prune( $keep ) {
		$keep = (int) $keep;
		if ( $keep < 1 ) {
			return 0;
		}
		$n = 0;
		foreach ( array_slice( self::run_ids(), $keep ) as $id ) {
			if ( true === self::delete_run( $id ) ) {
				++$n;
			}
		}
		return $n;
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
