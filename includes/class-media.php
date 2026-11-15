<?php
/**
 * Media library (attachment rows) export/import. Files themselves travel in the uploads scope.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment rows keyed "media:<_wp_attached_file>". Data never contains IDs, GUIDs or site URLs.
 */
class Media {

	const PREFIX = 'media:';

	/**
	 * String field helper.
	 *
	 * @param array  $d Data.
	 * @param string $k Key.
	 * @return string
	 */
	private static function str( array $d, $k ) {
		return isset( $d[ $k ] ) && is_string( $d[ $k ] ) ? $d[ $k ] : '';
	}

	/**
	 * Whether a stored relative file path is safe to sync.
	 *
	 * @param string $file Path relative to uploads.
	 * @return bool
	 */
	private static function syncable( $file ) {
		$rel = Paths::normalize( 'uploads/' . $file );
		return ! is_wp_error( $rel ) && ! Paths::is_excluded( $rel ) && ! Paths::ext_blocked( $rel );
	}

	/**
	 * Sanitise attachment metadata: arrays and scalars only, `file` forced to the item path,
	 * size file names reduced to basenames. Empty when the source has none.
	 *
	 * @param mixed  $meta Raw metadata.
	 * @param string $file Item path relative to uploads.
	 * @return array
	 */
	private static function clean_meta( $meta, $file ) {
		if ( ! is_array( $meta ) || ! $meta ) {
			return array();
		}
		$meta         = self::scalars( $meta, 0 );
		$meta['file'] = $file;
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( ! is_array( $size ) ) {
					unset( $meta['sizes'][ $name ] );
					continue;
				}
				if ( isset( $size['file'] ) ) {
					$meta['sizes'][ $name ]['file'] = wp_basename( (string) $size['file'] );
				}
			}
		}
		if ( isset( $meta['original_image'] ) ) {
			$meta['original_image'] = wp_basename( (string) $meta['original_image'] );
		}
		return $meta;
	}

	/**
	 * Recursively keep only arrays and scalars (depth-limited).
	 *
	 * @param array $a     Array.
	 * @param int   $depth Depth.
	 * @return array
	 */
	private static function scalars( array $a, $depth ) {
		$out = array();
		foreach ( $a as $k => $v ) {
			if ( is_array( $v ) ) {
				if ( $depth < 6 ) {
					$out[ $k ] = self::scalars( $v, $depth + 1 );
				}
			} elseif ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Find the attachment for a relative file path.
	 *
	 * @param string $file Path relative to uploads.
	 * @return \WP_Post|null
	 */
	public static function find( $file ) {
		$query = new \WP_Query(
			array(
				'post_type'           => 'attachment',
				'post_status'         => 'any',
				'posts_per_page'      => 1,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'meta_key'            => '_wp_attached_file', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'          => $file, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $query->posts ? $query->posts[0] : null;
	}

	/**
	 * Normalise one attachment.
	 *
	 * @param \WP_Post $p      Attachment.
	 * @param bool     $dedupe Skip it when a lower-ID attachment has the same file.
	 * @return array|null {key,data,label}
	 */
	public static function export_post( \WP_Post $p, $dedupe = true ) {
		$file = (string) get_post_meta( $p->ID, '_wp_attached_file', true );
		if ( '' === $file || ! self::syncable( $file ) ) {
			return null;
		}
		$first = $dedupe ? self::find( $file ) : null;
		if ( $first && (int) $first->ID !== (int) $p->ID ) {
			return null; // Duplicate _wp_attached_file: only the lowest ID is exported.
		}
		$gmt = '0000-00-00 00:00:00' === $p->post_date_gmt ? '' : $p->post_date_gmt;
		return array(
			'key'   => self::PREFIX . $file,
			/* translators: %s: media title or file name. */
			'label' => sprintf( __( 'Media: %s', 'avesta-sync' ), '' !== $p->post_title ? $p->post_title : basename( $file ) ),
			'data'  => array(
				'type'        => 'media',
				'file'        => $file,
				'title'       => Content::tok( $p->post_title ),
				'caption'     => Content::tok( $p->post_excerpt ),
				'description' => Content::tok( $p->post_content ),
				'alt'         => sanitize_text_field( (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ) ),
				'mime'        => (string) $p->post_mime_type,
				'date_gmt'    => $gmt,
				'metadata'    => self::clean_meta( get_post_meta( $p->ID, '_wp_attachment_metadata', true ), $file ),
			),
		);
	}

	/**
	 * Export attachment rows.
	 *
	 * @param array|null $keys Restrict to these keys.
	 * @return array key => {data,label}
	 */
	public static function export( $keys = null ) {
		$posts = array();
		if ( null === $keys ) {
			$query = new \WP_Query(
				array(
					'post_type'           => 'attachment',
					'post_status'         => 'any',
					'posts_per_page'      => -1,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				)
			);
			$posts = $query->posts;
		} else {
			foreach ( $keys as $key ) {
				if ( 0 === strpos( (string) $key, self::PREFIX ) ) {
					$p = self::find( substr( (string) $key, strlen( self::PREFIX ) ) );
					if ( $p ) {
						$posts[] = $p;
					}
				}
			}
		}
		$out = array();
		foreach ( $posts as $p ) {
			$item = self::export_post( $p, null !== $keys ); // The full list is ID-ordered: first one wins below.
			if ( $item && ! isset( $out[ $item['key'] ] ) ) {
				$out[ $item['key'] ] = array(
					'data'  => $item['data'],
					'label' => $item['label'],
				);
			}
		}
		return $out;
	}

	/**
	 * Create or update an attachment row (the file must already exist locally).
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import( array $d, $run ) {
		$file = self::str( $d, 'file' );
		if ( '' === $file || ! self::syncable( $file ) ) {
			return new \WP_Error( 'avesta_sync_media', __( 'Invalid media file path.', 'avesta-sync' ) );
		}
		$abs = Paths::resolve( 'uploads/' . $file, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return new \WP_Error( 'avesta_sync_media_file', __( 'File is missing on this site: sync the file first (Media uploads scope).', 'avesta-sync' ) );
		}
		$mime = sanitize_mime_type( self::str( $d, 'mime' ) );
		if ( '' === $mime ) {
			$type = wp_check_filetype( $abs );
			$mime = (string) $type['type'];
		}
		$post = array(
			'post_title'     => (string) Content::untok( self::str( $d, 'title' ) ),
			'post_excerpt'   => (string) Content::untok( self::str( $d, 'caption' ) ),
			'post_content'   => (string) Content::untok( self::str( $d, 'description' ) ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
			'edit_date'      => true,
		);
		$gmt  = self::str( $d, 'date_gmt' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $gmt ) ) {
			$post['post_date_gmt'] = $gmt;
			$post['post_date']     = get_date_from_gmt( $gmt );
		}
		$existing = self::find( $file );
		$had_kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		if ( $existing ) {
			$old = self::export_post( $existing );
			if ( $old ) {
				Backup::save_json( $old['key'], $old['data'], $run );
			}
			$post['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash( $post ), true );
		} else {
			$id = wp_insert_attachment( wp_slash( $post ), $abs, 0, true );
		}
		if ( $had_kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_wp_attached_file', $file );
		$alt = sanitize_text_field( self::str( $d, 'alt' ) );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		} else {
			delete_post_meta( $id, '_wp_attachment_image_alt' );
		}
		// Metadata is written as-is: regenerating would rewrite sub-size files the uploads scope already synced.
		$meta = isset( $d['metadata'] ) && is_array( $d['metadata'] ) ? self::clean_meta( $d['metadata'], $file ) : array();
		if ( $meta ) {
			update_post_meta( $id, '_wp_attachment_metadata', wp_slash( $meta ) );
		} else {
			delete_post_meta( $id, '_wp_attachment_metadata' );
		}
		return $existing ? __( 'Updated.', 'avesta-sync' ) : __( 'Created.', 'avesta-sync' );
	}

	/**
	 * Delete an attachment row only; files are left on disk (the uploads scope owns them).
	 * The row is saved as JSON first so it can be restored.
	 *
	 * @param array  $d   Exported data.
	 * @param string $key Item key.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function delete( array $d, $key, $run ) {
		$p = self::find( self::str( $d, 'file' ) );
		if ( ! $p ) {
			return new \WP_Error( 'avesta_sync_media', __( 'It was not found.', 'avesta-sync' ) );
		}
		if ( ! Backup::save_json( $key, $d, $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was deleted.', 'avesta-sync' ) );
		}
		add_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX );
		$res = wp_delete_attachment( $p->ID, true );
		remove_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX );
		return $res ? __( 'Media entry deleted. The files were kept.', 'avesta-sync' ) : new \WP_Error( 'avesta_sync_media', __( 'Delete failed.', 'avesta-sync' ) );
	}
}
