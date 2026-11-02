<?php
/**
 * File operations: manifest, chunked read/write, delete.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * File engine (receiving side logic lives here).
 */
class Files {

	const MAX_CHUNK = 4194304;
	const MAX_TOTAL = 1073741824;
	const CACHE_OPT = 'avesta_sync_hashcache';

	/**
	 * Hash cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Cache dirty flag.
	 *
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * SHA-256 of a file with an mtime/size cache.
	 *
	 * @param string $rel Relative path.
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function hash( $rel, $abs ) {
		self::hash_cache_init();
		clearstatcache( true, $abs );
		$size = filesize( $abs );
		$time = filemtime( $abs );
		$hit  = isset( self::$cache[ $rel ] ) ? self::$cache[ $rel ] : null;
		if ( $hit && $hit[0] === $size && $hit[1] === $time ) {
			return $hit[2];
		}
		$sha                 = (string) hash_file( 'sha256', $abs );
		self::$cache[ $rel ] = array( $size, $time, $sha );
		self::$dirty         = true;
		return $sha;
	}

	/**
	 * Persist the hash cache.
	 */
	public static function flush() {
		if ( self::$dirty && is_array( self::$cache ) ) {
			update_option( self::CACHE_OPT, self::$cache, false );
			self::$dirty = false;
		}
	}

	/**
	 * Forget a cached hash.
	 *
	 * @param string $rel Relative path.
	 */
	private static function forget( $rel ) {
		self::hash_cache_init();
		unset( self::$cache[ $rel ] );
		self::$dirty = true;
	}

	/**
	 * Ensure cache loaded.
	 */
	private static function hash_cache_init() {
		if ( null === self::$cache ) {
			$c           = get_option( self::CACHE_OPT, array() );
			self::$cache = is_array( $c ) ? $c : array();
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Build a manifest for a scope.
	 *
	 * @param string $scope theme|plugins|uploads.
	 * @param array  $items Folder slugs (theme/plugins).
	 * @return array|\WP_Error List of {path,size,sha256}.
	 */
	public static function manifest( $scope, array $items ) {
		$roots = array();
		if ( 'uploads' === $scope ) {
			$roots[] = 'uploads';
		} elseif ( 'theme' === $scope || 'plugins' === $scope ) {
			$prefix = 'theme' === $scope ? 'themes/' : 'plugins/';
			foreach ( $items as $slug ) {
				if ( Paths::valid_slug( $slug ) ) {
					$roots[] = $prefix . $slug;
				}
			}
		} else {
			return new \WP_Error( 'avesta_sync_scope', 'Unknown scope.', array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( array_unique( $roots ) as $rel_root ) {
			$abs = Paths::resolve( $rel_root, false, true );
			if ( is_wp_error( $abs ) || ! is_dir( $abs ) ) {
				continue;
			}
			self::walk( $rel_root, $abs, $out );
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
	 * Walk a directory collecting files.
	 *
	 * @param string $rel_root Relative root.
	 * @param string $abs      Absolute root.
	 * @param array  $out      Output.
	 */
	private static function walk( $rel_root, $abs, array &$out ) {
		$filter = static function ( \SplFileInfo $cur ) use ( $abs, $rel_root ) {
			if ( $cur->isLink() ) {
				return false;
			}
			$rel = $rel_root . substr( wp_normalize_path( $cur->getPathname() ), strlen( $abs ) );
			if ( Paths::is_excluded( $rel ) ) {
				return false;
			}
			return ! ( $cur->isFile() && Paths::ext_blocked( $rel ) );
		};
		$it     = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ), $filter )
		);
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$path  = wp_normalize_path( $f->getPathname() );
			$rel   = $rel_root . substr( $path, strlen( $abs ) );
			$out[] = array(
				'path'   => $rel,
				'size'   => $f->getSize(),
				'sha256' => self::hash( $rel, $path ),
			);
		}
	}

	/**
	 * Read a chunk.
	 *
	 * @param string $rel    Path.
	 * @param int    $offset Offset.
	 * @param int    $length Length.
	 * @return array|\WP_Error
	 */
	public static function read( $rel, $offset, $length ) {
		$abs = Paths::resolve( $rel );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return new \WP_Error( 'avesta_sync_not_found', 'File not found.', array( 'status' => 404 ) );
		}
		$offset = max( 0, (int) $offset );
		$length = max( 1, min( self::MAX_CHUNK, (int) $length ) );
		$size   = filesize( $abs );
		$fh     = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new \WP_Error( 'avesta_sync_read', 'Cannot read file.', array( 'status' => 500 ) );
		}
		fseek( $fh, $offset );
		$data = (string) fread( $fh, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'path'   => $rel,
			'offset' => $offset,
			'size'   => $size,
			'sha256' => self::hash( $rel, $abs ),
			'eof'    => ( $offset + strlen( $data ) ) >= $size,
			'chunk'  => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		);
	}

	/**
	 * Receive a chunk; on final, verify and atomically install.
	 *
	 * @param string $rel     Path.
	 * @param int    $offset  Offset of this chunk.
	 * @param int    $total   Total file size.
	 * @param string $sha     Expected SHA-256 of the whole file.
	 * @param string $chunk64 Base64 chunk.
	 * @param bool   $final   Last chunk.
	 * @param string $run     Run id.
	 * @return array|\WP_Error
	 */
	public static function write( $rel, $offset, $total, $sha, $chunk64, $final, $run ) {
		$abs = Paths::resolve( $rel, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$sha    = strtolower( (string) $sha );
		$offset = (int) $offset;
		$total  = (int) $total;
		$data   = base64_decode( (string) $chunk64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $data || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) || $offset < 0 || $total < 0 || $total > self::MAX_TOTAL || strlen( $data ) > self::MAX_CHUNK ) {
			return new \WP_Error( 'avesta_sync_bad_chunk', 'Invalid chunk parameters.', array( 'status' => 400 ) );
		}
		if ( is_dir( $abs ) ) {
			return new \WP_Error( 'avesta_sync_isdir', 'Target is a directory.', array( 'status' => 400 ) );
		}
		$tmp = Backup::tmp_dir() . '/' . hash( 'sha256', $rel ) . '.part';
		if ( 0 === $offset ) {
			$ok = false !== file_put_contents( $tmp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			clearstatcache( true, $tmp );
			if ( ! is_file( $tmp ) || filesize( $tmp ) !== $offset ) {
				return new \WP_Error( 'avesta_sync_offset', 'Chunk offset mismatch.', array( 'status' => 409 ) );
			}
			$ok = false !== file_put_contents( $tmp, $data, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! $ok ) {
			return new \WP_Error( 'avesta_sync_write', 'Cannot write temp file.', array( 'status' => 500 ) );
		}
		clearstatcache( true, $tmp );
		if ( ! $final ) {
			return array(
				'ok'       => true,
				'received' => filesize( $tmp ),
			);
		}
		$result = self::install( $tmp, $abs, $rel, $total, $sha, $run );
		if ( is_wp_error( $result ) ) {
			wp_delete_file( $tmp );
			return $result;
		}
		return array(
			'ok'      => true,
			'written' => true,
		);
	}

	/**
	 * Verify and move a completed temp file into place.
	 *
	 * @param string $tmp   Temp file.
	 * @param string $abs   Destination.
	 * @param string $rel   Relative path.
	 * @param int    $total Expected size.
	 * @param string $sha   Expected hash.
	 * @param string $run   Run id.
	 * @return true|\WP_Error
	 */
	private static function install( $tmp, $abs, $rel, $total, $sha, $run ) {
		if ( filesize( $tmp ) !== $total || ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
			return new \WP_Error( 'avesta_sync_hash', 'Size or SHA-256 mismatch after transfer.', array( 'status' => 422 ) );
		}
		if ( preg_match( '/\.php$/i', $rel ) && ! self::php_ok( $tmp ) ) {
			return new \WP_Error( 'avesta_sync_syntax', 'Refused: PHP file fails to parse.', array( 'status' => 422 ) );
		}
		if ( ! Backup::save_file( $abs, $rel, $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', 'Backup of existing file failed; not overwritten.', array( 'status' => 500 ) );
		}
		wp_mkdir_p( dirname( $abs ) );
		if ( ! @rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! copy( $tmp, $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new \WP_Error( 'avesta_sync_move', 'Cannot move file into place.', array( 'status' => 500 ) );
			}
			wp_delete_file( $tmp );
		}
		@chmod( $abs, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		self::forget( $rel );
		return true;
	}

	/**
	 * Delete a file (after backing it up).
	 *
	 * @param string $rel Path.
	 * @param string $run Run id.
	 * @return array|\WP_Error
	 */
	public static function delete( $rel, $run ) {
		$abs = Paths::resolve( $rel, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return array( 'ok' => true );
		}
		if ( ! Backup::save_file( $abs, $rel, $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', 'Backup failed; not deleted.', array( 'status' => 500 ) );
		}
		if ( ! wp_delete_file( $abs ) && is_file( $abs ) ) {
			return new \WP_Error( 'avesta_sync_delete', 'Cannot delete file.', array( 'status' => 500 ) );
		}
		self::forget( $rel );
		return array( 'ok' => true );
	}

	/**
	 * Syntax check a PHP file without executing it.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function php_ok( $file ) {
		$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $code ) {
			return false;
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}
}
