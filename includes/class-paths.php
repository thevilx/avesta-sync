<?php
/**
 * Path validation and allowlisting. Every file operation goes through here.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Safe path resolver.
 */
class Paths {

	const ROOTS     = array( 'themes', 'plugins', 'uploads' );
	const EXEC_RE   = '/\.(php\d?|phtml|phar|pht|phps|shtml?|cgi|pl|py|sh|exe|jsp|aspx?)(\.|$)/i';
	const CACHE_DIR = array( 'cache', 'caches', '.cache', 'wp-cache', 'wpo-cache', 'avesta-sync-backups' );

	/**
	 * Valid folder slug.
	 *
	 * @param mixed $slug Candidate.
	 * @return bool
	 */
	public static function valid_slug( $slug ) {
		return is_string( $slug ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $slug );
	}

	/**
	 * Absolute directory for a root.
	 *
	 * @param string $root themes|plugins|uploads.
	 * @return string
	 */
	public static function root_dir( $root ) {
		if ( 'themes' === $root ) {
			return untrailingslashit( wp_normalize_path( get_theme_root() ) );
		}
		if ( 'plugins' === $root ) {
			return untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		}
		$u = wp_upload_dir( null, false );
		return untrailingslashit( wp_normalize_path( $u['basedir'] ) );
	}

	/**
	 * Normalise a relative path.
	 *
	 * @param mixed $rel Candidate.
	 * @return string|\WP_Error
	 */
	public static function normalize( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || strlen( $rel ) > 1024 || preg_match( '/[\x00-\x1f\x7f\\\\]/', $rel ) || preg_match( '#^([A-Za-z]:|/)#', $rel ) ) {
			return self::err( __( 'Invalid path.', 'avesta-sync' ) );
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				return self::err( __( 'Invalid path segment.', 'avesta-sync' ) );
			}
		}
		return $rel;
	}

	/**
	 * Whether a normalised path is excluded from syncing.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	public static function is_excluded( $rel ) {
		$segs = explode( '/', $rel );
		$base = strtolower( end( $segs ) );
		foreach ( $segs as $seg ) {
			if ( in_array( strtolower( $seg ), array( '.git', '.svn', 'node_modules', '.ds_store' ), true ) ) {
				return true;
			}
		}
		if ( 'wp-config.php' === $base || '.env' === $base || 0 === strpos( $base, '.env.' ) || '.log' === substr( $base, -4 ) ) {
			return true;
		}
		if ( 'plugins' === $segs[0] && isset( $segs[1] ) && strtolower( basename( untrailingslashit( AVESTA_SYNC_DIR ) ) ) === strtolower( $segs[1] ) ) {
			return true;
		}
		foreach ( array_slice( $segs, 1 ) as $seg ) {
			if ( 0 === strpos( strtolower( $seg ), 'avesta-sync-backups' ) ) {
				return true;
			}
		}
		if ( 'uploads' === $segs[0] ) {
			foreach ( array_slice( $segs, 1 ) as $seg ) {
				if ( in_array( strtolower( $seg ), self::CACHE_DIR, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a path matches one of the user's exclude patterns (listing filter only,
	 * NOT a security boundary). Glob: * and ? ; a pattern without "/" matches the basename;
	 * a pattern naming a folder also covers everything below it.
	 *
	 * @param string   $rel      Relative path.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	public static function matches_user_exclude( $rel, array $patterns ) {
		if ( ! $patterns ) {
			return false;
		}
		$base = basename( $rel );
		foreach ( $patterns as $pat ) {
			if ( ! is_string( $pat ) || '' === $pat ) {
				continue;
			}
			$pat   = ltrim( $pat, '/' );
			$pat   = (string) preg_replace( '/\*{2,}/', '*', $pat );
			$re    = '#^' . str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), preg_quote( rtrim( $pat, '/' ), '#' ) ) . '$#s';
			$re_in = substr( $re, 0, -3 ) . '/.*$#s';
			if ( false === strpos( $pat, '/' ) ) {
				if ( preg_match( $re, $base ) ) {
					return true;
				}
				continue;
			}
			if ( preg_match( $re, $rel ) || preg_match( $re_in, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Executable / server-config file inside uploads.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	public static function ext_blocked( $rel ) {
		if ( 0 !== strpos( $rel, 'uploads/' ) ) {
			return false;
		}
		$base = strtolower( basename( $rel ) );
		return in_array( $base, array( '.htaccess', '.user.ini', 'web.config' ), true ) || (bool) preg_match( self::EXEC_RE, $base );
	}

	/**
	 * Resolve a relative path to a validated absolute path.
	 *
	 * @param mixed $rel    Relative path (to wp-content roots).
	 * @param bool  $write  Whether it will be written (extra checks).
	 * @param bool  $dir_ok Allow directory-level paths (themes/foo).
	 * @return string|\WP_Error
	 */
	public static function resolve( $rel, $write = false, $dir_ok = false ) {
		$rel = self::normalize( $rel );
		if ( is_wp_error( $rel ) ) {
			return $rel;
		}
		$segs = explode( '/', $rel );
		$root = $segs[0];
		if ( ! in_array( $root, self::ROOTS, true ) ) {
			return self::err( __( 'Path root not allowed.', 'avesta-sync' ) );
		}
		$need = 'uploads' === $root ? 2 : 3;
		if ( $dir_ok ) {
			$need = 'uploads' === $root ? 1 : 2;
		}
		if ( count( $segs ) < $need ) {
			return self::err( __( 'Path too short.', 'avesta-sync' ) );
		}
		if ( $write && 'uploads' !== $root && ! wp_is_file_mod_allowed( 'avesta_sync' ) ) {
			return self::err( __( 'File modifications are disabled on this site (DISALLOW_FILE_MODS).', 'avesta-sync' ) );
		}
		if ( 'uploads' !== $root && ! self::valid_slug( $segs[1] ) ) {
			return self::err( __( 'Invalid folder name.', 'avesta-sync' ) );
		}
		if ( self::is_excluded( $rel ) ) {
			return self::err( __( 'Path is excluded from sync.', 'avesta-sync' ) );
		}
		if ( $write && self::ext_blocked( $rel ) ) {
			return self::err( __( 'File type not allowed in uploads.', 'avesta-sync' ) );
		}
		$base = self::root_dir( $root );
		$abs  = $base . substr( $rel, strlen( $root ) );
		if ( is_link( $abs ) ) {
			return self::err( __( 'Symlinks are not allowed.', 'avesta-sync' ) );
		}
		$probe = $abs;
		while ( ! file_exists( $probe ) && ! is_link( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		$real      = realpath( $probe );
		$real_base = realpath( $base );
		if ( false === $real || false === $real_base ) {
			return self::err( __( 'Path cannot be resolved.', 'avesta-sync' ) );
		}
		$real      = wp_normalize_path( $real );
		$real_base = wp_normalize_path( $real_base );
		if ( $real !== $real_base && 0 !== strpos( $real, $real_base . '/' ) ) {
			return self::err( __( 'Path escapes its root.', 'avesta-sync' ) );
		}
		return $abs;
	}

	/**
	 * Error helper.
	 *
	 * @param string $msg Message.
	 * @return \WP_Error
	 */
	private static function err( $msg ) {
		return new \WP_Error( 'avesta_sync_path', $msg, array( 'status' => 400 ) );
	}
}
