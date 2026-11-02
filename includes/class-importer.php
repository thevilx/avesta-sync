<?php
/**
 * Content import/delete (upsert by key).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Importer.
 */
class Importer {

	/**
	 * Import items (upsert by key).
	 *
	 * @param array  $items key => data.
	 * @param string $run   Run id.
	 * @return array List of {key,ok,message}.
	 */
	public static function import( array $items, $run ) {
		$results = array();
		$run     = Backup::clean_run( $run );
		foreach ( $items as $key => $data ) {
			$key = (string) $key;
			if ( ! is_array( $data ) || ! isset( $data['type'] ) ) {
				$results[] = array(
					'key'     => $key,
					'ok'      => false,
					'message' => 'Malformed item.',
				);
				continue;
			}
			if ( 'menu' === $data['type'] ) {
				$r = Menus::import( $data, $run );
			} elseif ( 'option' === $data['type'] ) {
				$r = self::import_option( $data );
			} else {
				$r = self::import_post( $data, $run );
			}
			$results[] = array(
				'key'     => $key,
				'ok'      => ! is_wp_error( $r ),
				'message' => is_wp_error( $r ) ? $r->get_error_message() : (string) $r,
			);
		}
		return $results;
	}

	/**
	 * Locate an existing post for an item.
	 *
	 * @param string $type   Post type.
	 * @param string $slug   Slug.
	 * @param string $parent Parent URI.
	 * @param string $theme  Theme term (templates).
	 * @return \WP_Post|null
	 */
	private static function find( $type, $slug, $parent, $theme ) {
		if ( is_post_type_hierarchical( $type ) ) {
			$p = get_page_by_path( ( '' !== $parent ? $parent . '/' : '' ) . $slug, OBJECT, $type );
			return $p instanceof \WP_Post ? $p : null;
		}
		$args = array(
			'post_type'        => $type,
			'name'             => $slug,
			'post_status'      => array_merge( Content::STATUSES, array( 'trash' ) ),
			'numberposts'      => 1,
			'suppress_filters' => true,
		);
		if ( '' !== $theme ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $theme,
				),
			);
		}
		$found = get_posts( $args );
		return $found ? $found[0] : null;
	}

	/**
	 * Import one post.
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	private static function import_post( array $d, $run ) {
		$type   = (string) $d['type'];
		$slug   = sanitize_title( isset( $d['slug'] ) ? $d['slug'] : '' );
		$parent = isset( $d['parent'] ) ? trim( (string) $d['parent'], '/' ) : '';
		$theme  = isset( $d['theme'] ) ? sanitize_text_field( $d['theme'] ) : '';
		$allowed = array_merge( Content::post_types(), Content::THEME_TYPE, array( 'wp_navigation' ) );
		if ( '' === $slug || ! in_array( $type, $allowed, true ) || ! post_type_exists( $type ) ) {
			return new \WP_Error( 'avesta_sync_type', 'Post type not allowed or missing slug: ' . $type );
		}
		$status = isset( $d['status'] ) && in_array( $d['status'], Content::STATUSES, true ) ? $d['status'] : 'draft';
		$post   = array(
			'post_type'      => $type,
			'post_name'      => $slug,
			'post_title'     => (string) Content::untok( isset( $d['title'] ) ? $d['title'] : '' ),
			'post_content'   => (string) Content::untok( isset( $d['content'] ) ? $d['content'] : '' ),
			'post_excerpt'   => (string) Content::untok( isset( $d['excerpt'] ) ? $d['excerpt'] : '' ),
			'post_status'    => $status,
			'menu_order'     => isset( $d['menu_order'] ) ? (int) $d['menu_order'] : 0,
			'comment_status' => isset( $d['comment_status'] ) && 'open' === $d['comment_status'] ? 'open' : 'closed',
			'edit_date'      => true,
		);
		if ( ! empty( $d['date_gmt'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d['date_gmt'] ) ) {
			$post['post_date_gmt'] = $d['date_gmt'];
			$post['post_date']     = get_date_from_gmt( $d['date_gmt'] );
		}
		if ( is_post_type_hierarchical( $type ) && '' !== $parent ) {
			$par = get_page_by_path( $parent, OBJECT, $type );
			if ( ! $par ) {
				return new \WP_Error( 'avesta_sync_parent', 'Parent not found: ' . $parent . ' (sync parent first).' );
			}
			$post['post_parent'] = $par->ID;
		} elseif ( is_post_type_hierarchical( $type ) ) {
			$post['post_parent'] = 0;
		}
		$existing = self::find( $type, $slug, $parent, $theme );
		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$old        = Content::export_post( $existing );
			if ( $old ) {
				Backup::save_json( $old['key'], $old['data'], $run );
			}
		} else {
			$post['post_author'] = self::default_author();
		}
		$had_kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		$id = wp_insert_post( wp_slash( $post ), true );
		if ( $had_kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( '' !== $theme ) {
			wp_set_object_terms( $id, array( $theme ), 'wp_theme' );
		}
		self::import_meta( $id, isset( $d['meta'] ) ? (array) $d['meta'] : array() );
		self::import_terms( $id, $type, isset( $d['terms'] ) ? (array) $d['terms'] : array() );
		$notes = '';
		if ( ! empty( $d['featured'] ) ) {
			$att = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'inherit',
					'numberposts'      => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_key'         => '_wp_attached_file', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => (string) $d['featured'], // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $att ) {
				set_post_thumbnail( $id, (int) $att[0] );
			} else {
				$notes = ' (featured image attachment not found here; media rows are not synced)';
			}
		} else {
			delete_post_thumbnail( $id );
		}
		return ( $existing ? 'updated #' : 'created #' ) . $id . $notes;
	}

	/**
	 * Replace synced meta on a post.
	 *
	 * @param int   $id   Post ID.
	 * @param array $meta key => list of values.
	 */
	private static function import_meta( $id, array $meta ) {
		if ( ! $meta ) {
			return;
		}
		foreach ( array_keys( get_post_meta( $id ) ) as $existing ) {
			if ( ! isset( $meta[ $existing ] ) && ! in_array( $existing, Content::SKIP_META, true ) && 0 !== strpos( $existing, '_oembed_' ) && 0 !== strpos( $existing, '_wp_attachment' ) ) {
				delete_post_meta( $id, $existing );
			}
		}
		foreach ( $meta as $mk => $vals ) {
			$mk = (string) $mk;
			if ( '' === $mk || in_array( $mk, Content::SKIP_META, true ) || ! is_array( $vals ) || ! $vals ) {
				continue;
			}
			delete_post_meta( $id, $mk );
			foreach ( $vals as $val ) {
				add_post_meta( $id, $mk, wp_slash( Content::untok( $val ) ) );
			}
		}
	}

	/**
	 * Assign terms by slug (creating missing ones).
	 *
	 * @param int    $id    Post ID.
	 * @param string $type  Post type.
	 * @param array  $terms taxonomy => [{slug,name}].
	 */
	private static function import_terms( $id, $type, array $terms ) {
		$taxes = array_diff( get_object_taxonomies( $type, 'names' ), array( 'post_format', 'wp_theme', 'nav_menu', 'link_category' ) );
		foreach ( $taxes as $tax ) {
			$ids = array();
			foreach ( ( isset( $terms[ $tax ] ) ? (array) $terms[ $tax ] : array() ) as $t ) {
				if ( empty( $t['slug'] ) ) {
					continue;
				}
				$slug = sanitize_title( $t['slug'] );
				$term = get_term_by( 'slug', $slug, $tax );
				if ( ! $term ) {
					$new  = wp_insert_term( sanitize_text_field( isset( $t['name'] ) ? $t['name'] : $slug ), $tax, array( 'slug' => $slug ) );
					$term = is_wp_error( $new ) ? null : get_term( $new['term_id'], $tax );
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
				}
			}
			wp_set_object_terms( $id, $ids, $tax );
		}
	}

	/**
	 * Import a whitelisted option.
	 *
	 * @param array $d Data.
	 * @return string|\WP_Error
	 */
	private static function import_option( array $d ) {
		$name = isset( $d['name'] ) ? (string) $d['name'] : '';
		if ( ! in_array( $name, Content::OPTIONS, true ) ) {
			return new \WP_Error( 'avesta_sync_option', 'Option not whitelisted.' );
		}
		$val = isset( $d['value'] ) ? $d['value'] : '';
		if ( in_array( $name, array( 'page_on_front', 'page_for_posts' ), true ) ) {
			$uri = is_array( $val ) && isset( $val['page'] ) ? (string) $val['page'] : '';
			if ( '' === $uri ) {
				update_option( $name, 0 );
				return 'cleared';
			}
			$page = get_page_by_path( $uri, OBJECT, 'page' );
			if ( ! $page ) {
				return new \WP_Error( 'avesta_sync_option', 'Page not found: ' . $uri . ' (sync pages first).' );
			}
			update_option( $name, $page->ID );
		} elseif ( 'show_on_front' === $name ) {
			update_option( $name, 'page' === $val ? 'page' : 'posts' );
		} else {
			update_option( $name, sanitize_text_field( (string) $val ) );
		}
		return 'updated';
	}

	/**
	 * Trash / remove items (posts go to trash, menus are deleted after backup).
	 *
	 * @param array  $keys Keys.
	 * @param string $run  Run id.
	 * @return array
	 */
	public static function delete( array $keys, $run ) {
		$run     = Backup::clean_run( $run );
		$current = Content::export( array( 'all' ), false, $keys );
		$results = array();
		foreach ( $keys as $key ) {
			$key = (string) $key;
			$rec = isset( $current[ $key ] ) ? $current[ $key ] : null;
			$ok  = false;
			$msg = 'Not found.';
			if ( $rec && 'option' === $rec['data']['type'] ) {
				$msg = 'Options cannot be deleted.';
			} elseif ( $rec && 'menu' === $rec['data']['type'] ) {
				Backup::save_json( $key, $rec['data'], $run );
				$ok  = Menus::delete( $rec['data']['slug'] );
				$msg = $ok ? 'menu deleted' : 'delete failed';
			} elseif ( $rec ) {
				$d     = $rec['data'];
				$found = self::find( $d['type'], $d['slug'], $d['parent'], $d['theme'] );
				if ( $found ) {
					Backup::save_json( $key, $d, $run );
					$ok  = (bool) wp_trash_post( $found->ID );
					$msg = $ok ? 'moved to trash' : 'trash failed';
				}
			}
			$results[] = array(
				'key'     => $key,
				'ok'      => $ok,
				'message' => $msg,
			);
		}
		return $results;
	}

	/**
	 * Author for newly created posts.
	 *
	 * @return int
	 */
	private static function default_author() {
		$uid = get_current_user_id();
		if ( $uid ) {
			return $uid;
		}
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);
		return $ids ? (int) $ids[0] : 1;
	}
}
