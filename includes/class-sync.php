<?php
/**
 * Sync orchestration: compare and apply.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Engine shared by admin AJAX and WP-CLI.
 */
class Sync {

	/**
	 * Normalise a file manifest into id => {sha,size}.
	 *
	 * @param array $files Manifest list.
	 * @return array
	 */
	private static function files_map( array $files ) {
		$map = array();
		foreach ( $files as $f ) {
			if ( isset( $f['path'], $f['sha256'] ) ) {
				$map[ $f['path'] ] = array(
					'sha'  => $f['sha256'],
					'size' => (int) ( isset( $f['size'] ) ? $f['size'] : 0 ),
				);
			}
		}
		return $map;
	}

	/**
	 * Normalise a content export into id => {sha,label,ord}.
	 *
	 * @param array $items Export.
	 * @return array
	 */
	private static function content_map( array $items ) {
		$map = array();
		foreach ( $items as $key => $it ) {
			if ( isset( $it['hash'] ) ) {
				$map[ (string) $key ] = array(
					'sha'   => $it['hash'],
					'label' => isset( $it['label'] ) ? (string) $it['label'] : (string) $key,
					'ord'   => isset( $it['ord'] ) ? (int) $it['ord'] : 0,
				);
			}
		}
		return $map;
	}

	/**
	 * Compare local vs remote for scopes.
	 *
	 * @param Client $client   Remote client.
	 * @param array  $settings Settings.
	 * @param array  $scopes   Scopes to compare.
	 * @return array|\WP_Error
	 */
	public static function compare( Client $client, array $settings, array $scopes ) {
		$info = $client->info();
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$base      = Diff::baseline( $client->url() );
		$rows      = array();
		$identical = 0;
		foreach ( array_intersect( Settings::scopes(), $scopes ) as $scope ) {
			$items = Settings::scope_items( $scope, $settings );
			if ( 'content' === $scope ) {
				$local  = self::content_map( Content::export( array( 'all' ), true ) );
				$remote = $client->content_export( array( 'all' ), true );
				if ( is_wp_error( $remote ) ) {
					return $remote;
				}
				$remote = self::content_map( $remote );
			} else {
				$lf = Files::manifest( $scope, $items );
				$rf = $client->manifest( $scope, $items );
				if ( is_wp_error( $lf ) ) {
					return $lf;
				}
				if ( is_wp_error( $rf ) ) {
					return $rf;
				}
				$local  = self::files_map( $lf );
				$remote = self::files_map( $rf );
			}
			$res = Diff::classify( $local, $remote, $base );
			foreach ( $res['identical'] as $id ) {
				$sha = $local[ $id ]['sha'];
				if ( ! isset( $base[ $id ] ) || $base[ $id ] !== $sha ) {
					Diff::record( $client->url(), $id, $sha );
				}
			}
			$identical += count( $res['identical'] );
			foreach ( $res['rows'] as $row ) {
				$row['scope'] = $scope;
				$row['group'] = self::group_of( $scope, $row['id'] );
				$rows[]       = $row;
			}
		}
		return array(
			'rows'      => $rows,
			'identical' => $identical,
		);
	}

	/**
	 * Group heading for an item.
	 *
	 * @param string $scope Scope.
	 * @param string $id    Item id.
	 * @return string
	 */
	private static function group_of( $scope, $id ) {
		if ( 'content' === $scope ) {
			return 'content';
		}
		$p = explode( '/', $id );
		return in_array( $scope, array( 'theme', 'plugins' ), true ) && isset( $p[1] ) ? $p[0] . '/' . $p[1] : 'uploads';
	}

	/**
	 * Whether a file id is inside the user's selection.
	 *
	 * @param string $id       Path.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	private static function selected( $id, array $settings ) {
		$p = explode( '/', $id );
		if ( 'uploads' === $p[0] ) {
			return in_array( 'uploads', $settings['scopes'], true );
		}
		if ( 'themes' === $p[0] ) {
			return in_array( 'theme', $settings['scopes'], true ) && isset( $p[1] ) && in_array( $p[1], $settings['themes'], true );
		}
		if ( 'plugins' === $p[0] ) {
			return in_array( 'plugins', $settings['scopes'], true ) && isset( $p[1] ) && in_array( $p[1], $settings['plugins'], true );
		}
		return false;
	}

	/**
	 * Apply one item.
	 *
	 * @param Client $client   Remote client.
	 * @param array  $settings Settings.
	 * @param array  $item     {scope,id,action,del}.
	 * @param string $run      Run id.
	 * @return string|\WP_Error Message.
	 */
	public static function apply( Client $client, array $settings, array $item, $run ) {
		$scope  = isset( $item['scope'] ) ? (string) $item['scope'] : '';
		$id     = isset( $item['id'] ) ? (string) $item['id'] : '';
		$action = isset( $item['action'] ) ? (string) $item['action'] : '';
		$del    = ! empty( $item['del'] );
		$run    = Backup::clean_run( $run );
		if ( ! in_array( $action, array( 'push', 'pull' ), true ) || ! in_array( $scope, Settings::scopes(), true ) ) {
			return new \WP_Error( 'avesta_sync_item', 'Invalid item.' );
		}
		if ( 'content' === $scope ) {
			if ( ! in_array( 'content', $settings['scopes'], true ) ) {
				return new \WP_Error( 'avesta_sync_item', 'Content scope is not enabled.' );
			}
			return 'push' === $action ? self::push_content( $client, $id, $del, $run ) : self::pull_content( $client, $id, $del, $run );
		}
		if ( ! self::selected( $id, $settings ) ) {
			return new \WP_Error( 'avesta_sync_item', 'Item is outside the selected scope.' );
		}
		return 'push' === $action ? self::push_file( $client, $id, $del, $run ) : self::pull_file( $client, $id, $del, $run );
	}

	/**
	 * Push a file (or delete remote).
	 */
	private static function push_file( Client $c, $id, $del, $run ) {
		$abs = Paths::resolve( $id );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( is_file( $abs ) ) {
			$sha = Files::hash( $id, $abs );
			$r   = $c->put_file( $id, $abs, $sha, $run );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			Diff::record( $c->url(), $id, $sha );
			return 'pushed';
		}
		if ( ! $del ) {
			return new \WP_Error( 'avesta_sync_item', 'Local file is missing; choose Pull or confirm deletion.' );
		}
		$r = $c->delete_file( $id, $run );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		Diff::record( $c->url(), $id, null );
		return 'deleted on remote (backed up there)';
	}

	/**
	 * Pull a file (or delete local).
	 */
	private static function pull_file( Client $c, $id, $del, $run ) {
		$check = Paths::resolve( $id, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$r = $c->pull_file( $id, $run );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! empty( $r['missing'] ) ) {
			if ( ! $del ) {
				return new \WP_Error( 'avesta_sync_item', 'Remote file is missing; choose Push or confirm deletion.' );
			}
			$d = Files::delete( $id, $run );
			if ( is_wp_error( $d ) ) {
				return $d;
			}
			Diff::record( $c->url(), $id, null );
			return 'deleted locally (backed up)';
		}
		Diff::record( $c->url(), $id, $r['sha'] );
		return 'pulled';
	}

	/**
	 * Result check for import/delete responses.
	 *
	 * @param mixed  $res Response with 'results'.
	 * @param string $key Item key.
	 * @return string|\WP_Error
	 */
	private static function result_of( $res, $key ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rows = isset( $res['results'] ) ? $res['results'] : $res;
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $key ) {
				return ! empty( $row['ok'] ) ? (string) $row['message'] : new \WP_Error( 'avesta_sync_content', (string) $row['message'] );
			}
		}
		return new \WP_Error( 'avesta_sync_content', 'No result for item.' );
	}

	/**
	 * Push a content item.
	 */
	private static function push_content( Client $c, $key, $del, $run ) {
		$exp = Content::export( array( 'all' ), false, array( $key ) );
		if ( isset( $exp[ $key ] ) ) {
			$msg = self::result_of( $c->content_import( array( $key => $exp[ $key ]['data'] ), $run ), $key );
			if ( ! is_wp_error( $msg ) ) {
				Diff::record( $c->url(), $key, $exp[ $key ]['hash'] );
			}
			return $msg;
		}
		if ( ! $del ) {
			return new \WP_Error( 'avesta_sync_item', 'Local item is missing; choose Pull or confirm deletion.' );
		}
		$msg = self::result_of( $c->content_delete( array( $key ), $run ), $key );
		if ( ! is_wp_error( $msg ) ) {
			Diff::record( $c->url(), $key, null );
		}
		return $msg;
	}

	/**
	 * Pull a content item.
	 */
	private static function pull_content( Client $c, $key, $del, $run ) {
		$items = $c->content_export( array( 'all' ), false, array( $key ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		if ( isset( $items[ $key ]['data'] ) ) {
			$msg = self::result_of( Importer::import( array( $key => $items[ $key ]['data'] ), $run ), $key );
			if ( ! is_wp_error( $msg ) ) {
				Diff::record( $c->url(), $key, $items[ $key ]['hash'] );
			}
			return $msg;
		}
		if ( ! $del ) {
			return new \WP_Error( 'avesta_sync_item', 'Remote item is missing; choose Push or confirm deletion.' );
		}
		$msg = self::result_of( Importer::delete( array( $key ), $run ), $key );
		if ( ! is_wp_error( $msg ) ) {
			Diff::record( $c->url(), $key, null );
		}
		return $msg;
	}
}
