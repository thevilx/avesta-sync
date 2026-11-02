<?php
/**
 * Database content export/import (posts, templates, options). Menus live in Menus.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Content engine.
 */
class Content {

	const OPTIONS    = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts' );
	const THEME_TYPE = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );
	const SKIP_META  = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_thumbnail_id', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_pingme', '_encloseme' );
	const STATUSES   = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Replace site URLs with tokens (recursive).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function tok( $v ) {
		return self::swap( $v, true );
	}

	/**
	 * Restore tokens to this site's URLs (recursive).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function untok( $v ) {
		return self::swap( $v, false );
	}

	/**
	 * Token swap.
	 *
	 * @param mixed $v    Value.
	 * @param bool  $to   True: URL to token.
	 * @return mixed
	 */
	private static function swap( $v, $to ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				$v[ $k ] = self::swap( $item, $to );
			}
			return $v;
		}
		if ( ! is_string( $v ) || '' === $v ) {
			return $v;
		}
		$up   = untrailingslashit( wp_get_upload_dir()['baseurl'] );
		$home = untrailingslashit( home_url() );
		$map  = array(
			array( $up, '{{AS_UPLOADS}}' ),
			array( str_replace( '/', '\/', $up ), '{{AS_UPLOADS_E}}' ),
			array( $home, '{{AS_HOME}}' ),
			array( str_replace( '/', '\/', $home ), '{{AS_HOME_E}}' ),
		);
		foreach ( $map as $pair ) {
			$v = $to ? str_replace( $pair[0], $pair[1], $v ) : str_replace( $pair[1], $pair[0], $v );
		}
		return $v;
	}

	/**
	 * Regular post types that are synced.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$all = get_post_types( array( 'show_ui' => true ), 'names' );
		$ex  = array( 'attachment', 'nav_menu_item', 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_font_family', 'wp_font_face', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles' );
		$out = array();
		foreach ( array_diff( $all, $ex ) as $t ) {
			if ( 0 !== strpos( $t, 'flamingo_' ) && 0 !== strpos( $t, 'shop_order' ) ) {
				$out[] = $t;
			}
		}
		return array_values( apply_filters( 'avesta_sync_post_types', $out ) );
	}

	/**
	 * Types for a group name.
	 *
	 * @param string $group posts|templates.
	 * @return string[]
	 */
	private static function group_types( $group ) {
		if ( 'templates' === $group ) {
			return array_merge( self::THEME_TYPE, array( 'wp_navigation' ) );
		}
		return self::post_types();
	}

	/**
	 * Export items.
	 *
	 * @param array      $groups      Any of posts, templates, menus, options (or 'all').
	 * @param bool       $hashes_only Omit data.
	 * @param array|null $keys        Restrict to these keys.
	 * @return array key => {hash,label,ord,data?}
	 */
	public static function export( array $groups, $hashes_only = false, $keys = null ) {
		if ( in_array( 'all', $groups, true ) || ! $groups ) {
			$groups = array( 'posts', 'templates', 'menus', 'options' );
		}
		$out = array();
		foreach ( array_intersect( $groups, array( 'posts', 'templates' ) ) as $group ) {
			$posts = get_posts(
				array(
					'post_type'        => self::group_types( $group ),
					'post_status'      => self::STATUSES,
					'numberposts'      => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			foreach ( $posts as $p ) {
				$item = self::export_post( $p );
				if ( $item && ( null === $keys || in_array( $item['key'], $keys, true ) ) ) {
					$out[ $item['key'] ] = self::pack( $item['data'], $item['label'], $item['ord'], $hashes_only );
				}
			}
		}
		if ( in_array( 'menus', $groups, true ) ) {
			foreach ( Menus::export() as $key => $item ) {
				if ( null === $keys || in_array( $key, $keys, true ) ) {
					$out[ $key ] = self::pack( $item['data'], $item['label'], 50, $hashes_only );
				}
			}
		}
		if ( in_array( 'options', $groups, true ) ) {
			foreach ( self::OPTIONS as $name ) {
				$key = 'option:' . $name;
				if ( null === $keys || in_array( $key, $keys, true ) ) {
					$out[ $key ] = self::pack( self::export_option( $name ), $name, 90, $hashes_only );
				}
			}
		}
		return $out;
	}

	/**
	 * Build an export record.
	 *
	 * @param array  $data  Data.
	 * @param string $label Label.
	 * @param int    $ord   Apply order.
	 * @param bool   $only  Hash only.
	 * @return array
	 */
	private static function pack( array $data, $label, $ord, $only ) {
		$rec = array(
			'hash'  => hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'label' => $label,
			'ord'   => $ord,
		);
		if ( ! $only ) {
			$rec['data'] = $data;
		}
		return $rec;
	}

	/**
	 * Normalise one post.
	 *
	 * @param \WP_Post $p Post.
	 * @return array|null
	 */
	public static function export_post( \WP_Post $p ) {
		if ( '' === $p->post_name ) {
			return null;
		}
		$type   = $p->post_type;
		$theme  = '';
		$parent = '';
		if ( in_array( $type, self::THEME_TYPE, true ) ) {
			$names = wp_get_object_terms( $p->ID, 'wp_theme', array( 'fields' => 'names' ) );
			$theme = ( ! is_wp_error( $names ) && $names ) ? (string) $names[0] : '';
		}
		if ( is_post_type_hierarchical( $type ) && $p->post_parent ) {
			$par    = get_post( $p->post_parent );
			$parent = $par ? get_page_uri( $par ) : '';
		}
		$key = $type . ':' . ( '' !== $theme ? $theme . '//' : '' ) . ( '' !== $parent ? $parent . '/' : '' ) . $p->post_name;
		$gmt = '0000-00-00 00:00:00' === $p->post_date_gmt ? '' : $p->post_date_gmt;

		$meta = array();
		foreach ( get_post_meta( $p->ID ) as $mk => $vals ) {
			if ( in_array( $mk, self::SKIP_META, true ) || 0 === strpos( $mk, '_oembed_' ) || 0 === strpos( $mk, '_wp_attachment' ) ) {
				continue;
			}
			$list = array();
			foreach ( $vals as $raw ) {
				$val = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
				if ( ! is_object( $val ) ) {
					$list[] = self::tok( $val );
				}
			}
			if ( $list ) {
				$meta[ $mk ] = $list;
			}
		}
		ksort( $meta );

		$terms = array();
		foreach ( array_diff( get_object_taxonomies( $type, 'names' ), array( 'post_format', 'wp_theme', 'nav_menu', 'link_category' ) ) as $tax ) {
			$rows = wp_get_object_terms( $p->ID, $tax );
			if ( is_wp_error( $rows ) || ! $rows ) {
				continue;
			}
			$list = array();
			foreach ( $rows as $t ) {
				$list[] = array(
					'slug' => $t->slug,
					'name' => $t->name,
				);
			}
			usort(
				$list,
				static function ( $a, $b ) {
					return strcmp( $a['slug'], $b['slug'] );
				}
			);
			$terms[ $tax ] = $list;
		}
		ksort( $terms );

		$thumb    = (int) get_post_thumbnail_id( $p->ID );
		$featured = $thumb ? (string) get_post_meta( $thumb, '_wp_attached_file', true ) : '';

		$data = array(
			'type'           => $type,
			'slug'           => $p->post_name,
			'parent'         => $parent,
			'theme'          => $theme,
			'title'          => self::tok( $p->post_title ),
			'content'        => self::tok( $p->post_content ),
			'excerpt'        => self::tok( $p->post_excerpt ),
			'status'         => $p->post_status,
			'menu_order'     => (int) $p->menu_order,
			'comment_status' => $p->comment_status,
			'date_gmt'       => $gmt,
			'featured'       => $featured,
			'meta'           => $meta,
			'terms'          => $terms,
		);
		return array(
			'key'   => $key,
			'data'  => $data,
			'label' => $type . ': ' . ( '' !== $p->post_title ? $p->post_title : $p->post_name ),
			'ord'   => 10 + substr_count( $key, '/' ) + ( '' !== $theme ? -2 : 0 ),
		);
	}

	/**
	 * Export a whitelisted option.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	private static function export_option( $name ) {
		$val = get_option( $name );
		if ( in_array( $name, array( 'page_on_front', 'page_for_posts' ), true ) ) {
			$post = $val ? get_post( (int) $val ) : null;
			$val  = $post ? array( 'page' => get_page_uri( $post ) ) : array( 'page' => '' );
		} else {
			$val = (string) $val;
		}
		return array(
			'type'  => 'option',
			'name'  => $name,
			'value' => $val,
		);
	}
}
