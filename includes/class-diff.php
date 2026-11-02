<?php
/**
 * Three-way comparison and baseline storage.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Diff engine.
 */
class Diff {

	const OPT = 'avesta_sync_baseline';

	/**
	 * Baseline cache.
	 *
	 * @var array|null
	 */
	private static $baseline = null;

	/**
	 * Dirty flag.
	 *
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * Load baseline store.
	 */
	private static function load() {
		if ( null === self::$baseline ) {
			$b                = get_option( self::OPT, array() );
			self::$baseline   = is_array( $b ) ? $b : array();
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Baseline (id => sha) for a remote.
	 *
	 * @param string $remote Remote URL.
	 * @return array
	 */
	public static function baseline( $remote ) {
		self::load();
		$k = md5( $remote );
		return isset( self::$baseline[ $k ] ) ? self::$baseline[ $k ] : array();
	}

	/**
	 * Record (or clear) an item's synced hash.
	 *
	 * @param string      $remote Remote URL.
	 * @param string      $id     Item id.
	 * @param string|null $sha    Hash or null to forget.
	 */
	public static function record( $remote, $id, $sha ) {
		self::load();
		$k = md5( $remote );
		if ( null === $sha ) {
			unset( self::$baseline[ $k ][ $id ] );
		} else {
			self::$baseline[ $k ][ $id ] = $sha;
		}
		self::$dirty = true;
	}

	/**
	 * Persist baseline.
	 */
	public static function flush() {
		if ( self::$dirty ) {
			update_option( self::OPT, self::$baseline, false );
			self::$dirty = false;
		}
	}

	/**
	 * Classify items.
	 *
	 * @param array $local  id => {sha,size?,label?,ord?}.
	 * @param array $remote id => same.
	 * @param array $base   id => sha.
	 * @return array {rows: list, identical: id[]}
	 */
	public static function classify( array $local, array $remote, array $base ) {
		$rows      = array();
		$identical = array();
		$ids       = array_unique( array_merge( array_keys( $local ), array_keys( $remote ) ) );
		sort( $ids );
		foreach ( $ids as $id ) {
			$id = (string) $id;
			$l  = isset( $local[ $id ] ) ? $local[ $id ] : null;
			$r  = isset( $remote[ $id ] ) ? $remote[ $id ] : null;
			$b  = isset( $base[ $id ] ) ? $base[ $id ] : null;
			if ( $l && $r && $l['sha'] === $r['sha'] ) {
				$identical[] = $id;
				continue;
			}
			if ( $l && ! $r ) {
				$status = null === $b ? 'local-new' : ( $b === $l['sha'] ? 'remote-deleted' : 'conflict' );
			} elseif ( ! $l && $r ) {
				$status = null === $b ? 'remote-new' : ( $b === $r['sha'] ? 'local-deleted' : 'conflict' );
			} elseif ( null === $b ) {
				$status = 'conflict';
			} elseif ( $b === $l['sha'] ) {
				$status = 'remote-changed';
			} elseif ( $b === $r['sha'] ) {
				$status = 'local-changed';
			} else {
				$status = 'conflict';
			}
			$action = 'skip';
			if ( in_array( $status, array( 'local-new', 'local-changed' ), true ) ) {
				$action = 'push';
			} elseif ( in_array( $status, array( 'remote-new', 'remote-changed' ), true ) ) {
				$action = 'pull';
			}
			$src    = $l ? $l : $r;
			$rows[] = array(
				'id'     => $id,
				'status' => $status,
				'action' => $action,
				'label'  => isset( $src['label'] ) ? $src['label'] : $id,
				'ord'    => isset( $src['ord'] ) ? (int) $src['ord'] : 0,
				'local'  => $l ? array( 'sha' => $l['sha'], 'size' => isset( $l['size'] ) ? $l['size'] : null ) : null,
				'remote' => $r ? array( 'sha' => $r['sha'], 'size' => isset( $r['size'] ) ? $r['size'] : null ) : null,
			);
		}
		return array(
			'rows'      => $rows,
			'identical' => $identical,
		);
	}
}
