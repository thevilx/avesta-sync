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

	const TEXT_EXT    = array( 'php', 'css', 'scss', 'js', 'mjs', 'ts', 'tsx', 'jsx', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'yml', 'yaml', 'twig', 'pot', 'po', 'ini', 'csv' );
	const PREVIEW_MAX = 524288;

	/**
	 * Content groups for this site's settings.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function groups( array $settings ) {
		return Content::groups( ! empty( $settings['media_library'] ) );
	}

	/**
	 * Whether an item can be shown in the diff viewer.
	 *
	 * @param string $scope Scope.
	 * @param string $id    Item id.
	 * @return bool
	 */
	public static function previewable( $scope, $id ) {
		return 'content' === $scope || in_array( strtolower( pathinfo( $id, PATHINFO_EXTENSION ) ), self::TEXT_EXT, true );
	}

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
				$groups = self::groups( $settings );
				$local  = self::content_map( Content::export( $groups, true ) );
				$remote = $client->content_export( $groups, true );
				if ( is_wp_error( $remote ) ) {
					return $remote;
				}
				$remote = self::content_map( $remote );
			} else {
				$ex = isset( $settings['excludes'] ) ? (array) $settings['excludes'] : array();
				$lf = Files::manifest( $scope, $items, $ex );
				$rf = $client->manifest( $scope, $items, $ex );
				if ( is_wp_error( $lf ) ) {
					return $lf;
				}
				if ( is_wp_error( $rf ) ) {
					return $rf;
				}
				$local  = self::files_map( $lf );
				$remote = self::files_map( $rf );
				// A remote running an older version ignores the exclude list, so filter here too.
				foreach ( array_keys( $remote ) as $rid ) {
					if ( Paths::matches_user_exclude( (string) $rid, $ex ) ) {
						unset( $remote[ $rid ] );
					}
				}
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
				$row['previewable'] = self::previewable( $scope, $row['id'] );
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
			return new \WP_Error( 'avesta_sync_item', __( 'Invalid item.', 'avesta-sync' ) );
		}
		if ( 'content' === $scope ) {
			if ( ! in_array( 'content', $settings['scopes'], true ) ) {
				return new \WP_Error( 'avesta_sync_item', __( 'Content scope is not enabled.', 'avesta-sync' ) );
			}
			$groups = self::groups( $settings );
			return 'push' === $action ? self::push_content( $client, $id, $del, $run, $groups ) : self::pull_content( $client, $id, $del, $run, $groups );
		}
		if ( ! self::selected( $id, $settings ) ) {
			return new \WP_Error( 'avesta_sync_item', __( 'Item is outside the selected scope.', 'avesta-sync' ) );
		}
		return 'push' === $action ? self::push_file( $client, $id, $del, $run ) : self::pull_file( $client, $id, $del, $run );
	}

	/**
	 * Is a string displayable text (valid UTF-8, no NUL)?
	 *
	 * @param string $s Body.
	 * @return bool
	 */
	private static function is_text( $s ) {
		return false === strpos( $s, "\0" ) && (bool) preg_match( '//u', $s );
	}

	/**
	 * Side-by-side preview data for one item (nothing is written anywhere).
	 *
	 * @param Client $client   Remote client.
	 * @param array  $settings Settings.
	 * @param string $scope    Scope.
	 * @param string $id       Item id.
	 * @return array|\WP_Error {label,binary,too_large,local,remote}
	 */
	public static function preview( Client $client, array $settings, $scope, $id ) {
		$scope = (string) $scope;
		$id    = (string) $id;
		if ( ! in_array( $scope, Settings::scopes(), true ) ) {
			return new \WP_Error( 'avesta_sync_item', __( 'Invalid item.', 'avesta-sync' ) );
		}
		$out = array(
			'label'     => $id,
			'binary'    => false,
			'too_large' => false,
			'local'     => null,
			'remote'    => null,
		);
		if ( 'content' === $scope ) {
			if ( ! in_array( 'content', $settings['scopes'], true ) ) {
				return new \WP_Error( 'avesta_sync_item', __( 'Content scope is not enabled.', 'avesta-sync' ) );
			}
			$groups = self::groups( $settings );
			$flags  = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
			$local  = Content::export( $groups, false, array( $id ) );
			$remote = $client->content_export( $groups, false, array( $id ) );
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}
			if ( isset( $local[ $id ] ) ) {
				$out['label'] = (string) $local[ $id ]['label'];
				$out['local'] = (string) wp_json_encode( $local[ $id ]['data'], $flags );
			}
			if ( isset( $remote[ $id ]['data'] ) ) {
				$out['label']  = isset( $remote[ $id ]['label'] ) && ! isset( $local[ $id ] ) ? (string) $remote[ $id ]['label'] : $out['label'];
				$out['remote'] = (string) wp_json_encode( $remote[ $id ]['data'], $flags );
			}
			return $out;
		}
		if ( ! self::selected( $id, $settings ) || ! self::previewable( $scope, $id ) ) {
			return new \WP_Error( 'avesta_sync_item', __( 'Item is outside the selected scope.', 'avesta-sync' ) );
		}
		$abs = Paths::resolve( $id );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( is_file( $abs ) ) {
			if ( filesize( $abs ) > self::PREVIEW_MAX ) {
				$out['too_large'] = true;
			} else {
				$out['local'] = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$remote = $client->read_text( $id, self::PREVIEW_MAX );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		if ( ! empty( $remote['too_large'] ) ) {
			$out['too_large'] = true;
		} elseif ( isset( $remote['data'] ) ) {
			$out['remote'] = $remote['data'];
		}
		if ( ! $out['too_large'] && ( ( null !== $out['local'] && ! self::is_text( $out['local'] ) ) || ( null !== $out['remote'] && ! self::is_text( $out['remote'] ) ) ) ) {
			$out['binary'] = true;
		}
		if ( $out['binary'] || $out['too_large'] ) {
			$out['local']  = null;
			$out['remote'] = null;
		}
		return $out;
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
			return __( 'pushed', 'avesta-sync' );
		}
		if ( ! $del ) {
			return new \WP_Error( 'avesta_sync_item', __( 'Local file is missing; choose Pull or confirm deletion.', 'avesta-sync' ) );
		}
		$r = $c->delete_file( $id, $run );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		Diff::record( $c->url(), $id, null );
		return __( 'deleted on remote (backed up there)', 'avesta-sync' );
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
				return new \WP_Error( 'avesta_sync_item', __( 'Remote file is missing; choose Push or confirm deletion.', 'avesta-sync' ) );
			}
			$d = Files::delete( $id, $run );
			if ( is_wp_error( $d ) ) {
				return $d;
			}
			Diff::record( $c->url(), $id, null );
			return __( 'deleted locally (backed up)', 'avesta-sync' );
		}
		Diff::record( $c->url(), $id, $r['sha'] );
		return __( 'pulled', 'avesta-sync' );
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
		return new \WP_Error( 'avesta_sync_content', __( 'No result for item.', 'avesta-sync' ) );
	}

	/**
	 * Push a content item.
	 */
	private static function push_content( Client $c, $key, $del, $run, array $groups ) {
		$exp = Content::export( $groups, false, array( $key ) );
		if ( isset( $exp[ $key ] ) ) {
			$msg = self::result_of( $c->content_import( array( $key => $exp[ $key ]['data'] ), $run ), $key );
			if ( ! is_wp_error( $msg ) ) {
				Diff::record( $c->url(), $key, $exp[ $key ]['hash'] );
			}
			return $msg;
		}
		if ( ! $del ) {
			return new \WP_Error( 'avesta_sync_item', __( 'Local item is missing; choose Pull or confirm deletion.', 'avesta-sync' ) );
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
	private static function pull_content( Client $c, $key, $del, $run, array $groups ) {
		$items = $c->content_export( $groups, false, array( $key ) );
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
			return new \WP_Error( 'avesta_sync_item', __( 'Remote item is missing; choose Push or confirm deletion.', 'avesta-sync' ) );
		}
		$msg = self::result_of( Importer::delete( array( $key ), $run ), $key );
		if ( ! is_wp_error( $msg ) ) {
			Diff::record( $c->url(), $key, null );
		}
		return $msg;
	}
}
