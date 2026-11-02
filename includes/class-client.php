<?php
/**
 * Signed REST client for the remote site.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Remote caller.
 */
class Client {

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Secret.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Use ?rest_route= instead of /wp-json/.
	 *
	 * @var bool
	 */
	private $query_mode = false;

	/**
	 * Cached /info.
	 *
	 * @var array|null
	 */
	private $info = null;

	/**
	 * Constructor.
	 *
	 * @param string $url Remote site URL.
	 * @param string $key Remote secret.
	 */
	public function __construct( $url, $key ) {
		$this->base = untrailingslashit( (string) $url );
		$this->key  = (string) $key;
	}

	/**
	 * Build from saved settings.
	 *
	 * @param array|null $settings Settings.
	 * @return Client|\WP_Error
	 */
	public static function from_settings( $settings = null ) {
		$s = $settings ? $settings : Settings::get();
		if ( '' === $s['remote_url'] || '' === $s['remote_key'] ) {
			return new \WP_Error( 'avesta_sync_config', 'Remote URL and key are not configured.' );
		}
		return new self( $s['remote_url'], $s['remote_key'] );
	}

	/**
	 * Remote base URL.
	 *
	 * @return string
	 */
	public function url() {
		return $this->base;
	}

	/**
	 * Local-development host?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_local_host( $host ) {
		$host = strtolower( (string) $host );
		return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) || '.localhost' === substr( $host, -10 ) || '.test' === substr( $host, -5 );
	}

	/**
	 * Validate the remote URL (HTTPS required except for local hosts).
	 *
	 * @return true|\WP_Error
	 */
	public function check() {
		$p = wp_parse_url( $this->base );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( $p['scheme'], array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'avesta_sync_url', 'Invalid remote URL.' );
		}
		if ( 'https' !== $p['scheme'] && ! self::is_local_host( $p['host'] ) ) {
			return new \WP_Error( 'avesta_sync_https', 'Remote URL must use HTTPS.' );
		}
		return true;
	}

	/**
	 * Signed request.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $ep      Endpoint, e.g. info.
	 * @param array|null $payload JSON payload.
	 * @return array|\WP_Error Decoded JSON.
	 */
	public function request( $method, $ep, $payload = null ) {
		$ok = $this->check();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$body  = null === $payload ? '' : wp_json_encode( $payload );
		$route = '/avesta-sync/v1/' . $ep;
		$res   = $this->send( $method, $route, $body );
		if ( ! is_wp_error( $res ) && 404 === wp_remote_retrieve_response_code( $res ) && ! $this->query_mode ) {
			$d = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $d ) && isset( $d['code'] ) && 'rest_no_route' === $d['code'] ) {
				$this->query_mode = true;
				$res              = $this->send( $method, $route, $body );
			}
		}
		if ( is_wp_error( $res ) && 0 === strpos( $res->get_error_code(), 'http_request_failed' ) ) {
			sleep( 1 );
			$res = $this->send( $method, $route, $body );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = preg_replace( '/^\xEF\xBB\xBF/', '', trim( wp_remote_retrieve_body( $res ) ) );
		$data = json_decode( $raw, true );
		if ( $code >= 300 && $code < 400 ) {
			return new \WP_Error( 'avesta_sync_redirect', 'Remote redirected (HTTP ' . $code . '). Use the final HTTPS URL of the remote site.' );
		}
		if ( $code >= 400 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
			$c   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'avesta_sync_http';
			return new \WP_Error( $c, $msg, array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'avesta_sync_response', 'Unexpected response from remote (HTTP ' . $code . '). Is Avesta Sync active there?' );
		}
		return $data;
	}

	/**
	 * Perform the HTTP call.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param string $body   Body.
	 * @return array|\WP_Error
	 */
	private function send( $method, $route, $body ) {
		$ts  = (string) time();
		$p   = wp_parse_url( $this->base );
		$url = $this->query_mode ? $this->base . '/index.php?rest_route=' . $route : $this->base . '/wp-json' . $route;
		$args = array(
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 0,
			'sslverify'   => ! self::is_local_host( $p['host'] ),
			'headers'     => array(
				'Content-Type'   => 'application/json',
				'X-AS-Timestamp' => $ts,
				'X-AS-Signature' => Auth::sign( $this->key, $method, $route, $ts, $body ),
			),
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		return self::is_local_host( $p['host'] ) ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
	}

	/**
	 * Remote info.
	 *
	 * @return array|\WP_Error
	 */
	public function info() {
		if ( null === $this->info ) {
			$r = $this->request( 'GET', 'info' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$this->info = $r;
		}
		return $this->info;
	}

	/**
	 * Chunk size for transfers.
	 *
	 * @return int
	 */
	public function chunk_size() {
		$info = $this->info();
		$max  = is_array( $info ) && ! empty( $info['post_max_size'] ) ? (int) $info['post_max_size'] : 0;
		$size = $max > 0 ? (int) floor( $max * 0.5 ) : 1048576;
		return max( 65536, min( 1048576, $size ) );
	}

	/**
	 * Remote file manifest.
	 *
	 * @param string $scope Scope.
	 * @param array  $items Items.
	 * @return array|\WP_Error
	 */
	public function manifest( $scope, array $items ) {
		$r = $this->request( 'POST', 'manifest', array( 'scope' => $scope, 'items' => array_values( $items ) ) );
		return is_wp_error( $r ) ? $r : ( isset( $r['files'] ) ? (array) $r['files'] : array() );
	}

	/**
	 * Remote content export.
	 *
	 * @param array      $types  Groups.
	 * @param bool       $hashes Hashes only.
	 * @param array|null $keys   Keys.
	 * @return array|\WP_Error
	 */
	public function content_export( array $types, $hashes, $keys = null ) {
		$r = $this->request( 'POST', 'content/export', array( 'types' => $types, 'hashes_only' => (bool) $hashes, 'keys' => $keys ) );
		return is_wp_error( $r ) ? $r : ( isset( $r['items'] ) ? (array) $r['items'] : array() );
	}

	/**
	 * Import items on remote.
	 *
	 * @param array  $items key => data.
	 * @param string $run   Run id.
	 * @return array|\WP_Error
	 */
	public function content_import( array $items, $run ) {
		return $this->request( 'POST', 'content/import', array( 'items' => $items, 'run' => $run ) );
	}

	/**
	 * Delete items on remote.
	 *
	 * @param array  $keys Keys.
	 * @param string $run  Run id.
	 * @return array|\WP_Error
	 */
	public function content_delete( array $keys, $run ) {
		return $this->request( 'POST', 'content/delete', array( 'keys' => $keys, 'run' => $run ) );
	}

	/**
	 * Delete remote file.
	 *
	 * @param string $rel Path.
	 * @param string $run Run id.
	 * @return array|\WP_Error
	 */
	public function delete_file( $rel, $run ) {
		return $this->request( 'POST', 'file/delete', array( 'path' => $rel, 'run' => $run ) );
	}

	/**
	 * Upload a local file in chunks.
	 *
	 * @param string $rel Path.
	 * @param string $abs Absolute local path.
	 * @param string $sha SHA-256.
	 * @param string $run Run id.
	 * @return true|\WP_Error
	 */
	public function put_file( $rel, $abs, $sha, $run ) {
		$chunk = $this->chunk_size();
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}
		$size = filesize( $abs );
		$fh   = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new \WP_Error( 'avesta_sync_read', 'Cannot read local file.' );
		}
		$offset = 0;
		do {
			$data   = (string) fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$offset2 = $offset + strlen( $data );
			$res    = $this->request(
				'POST',
				'file/put',
				array(
					'path'   => $rel,
					'offset' => $offset,
					'total'  => $size,
					'sha256' => $sha,
					'chunk'  => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					'final'  => $offset2 >= $size,
					'run'    => $run,
				)
			);
			if ( is_wp_error( $res ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return $res;
			}
			$offset = $offset2;
		} while ( $offset < $size );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Download a remote file into the local site (via Files::write).
	 *
	 * @param string $rel Path.
	 * @param string $run Run id.
	 * @return array|\WP_Error {sha} or {missing:true}.
	 */
	public function pull_file( $rel, $run ) {
		$chunk  = $this->chunk_size();
		$offset = 0;
		do {
			$r = $this->request( 'POST', 'file/get', array( 'path' => $rel, 'offset' => $offset, 'length' => $chunk ) );
			if ( is_wp_error( $r ) ) {
				return 'avesta_sync_not_found' === $r->get_error_code() && 0 === $offset ? array( 'missing' => true ) : $r;
			}
			$raw = base64_decode( (string) $r['chunk'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw ) {
				return new \WP_Error( 'avesta_sync_chunk', 'Corrupt chunk from remote.' );
			}
			$next = $offset + strlen( $raw );
			$w    = Files::write( $rel, $offset, (int) $r['size'], (string) $r['sha256'], (string) $r['chunk'], ! empty( $r['eof'] ), $run );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			if ( strlen( $raw ) === 0 && empty( $r['eof'] ) ) {
				return new \WP_Error( 'avesta_sync_chunk', 'Remote returned an empty chunk.' );
			}
			$offset = $next;
		} while ( empty( $r['eof'] ) );
		return array( 'sha' => (string) $r['sha256'] );
	}
}
