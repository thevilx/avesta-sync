<?php
/**
 * Navigation menu export/import.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Classic nav menus.
 */
class Menus {

	/**
	 * Export all menus.
	 *
	 * @return array key => {data,label}
	 */
	public static function export() {
		$out = array();
		$loc = get_nav_menu_locations();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'publish' ) );
			$items = $items ? $items : array();
			$index = array();
			foreach ( array_values( $items ) as $i => $it ) {
				$index[ (int) $it->ID ] = $i;
			}
			$rows = array();
			foreach ( $items as $it ) {
				$slug = '';
				if ( 'post_type' === $it->type ) {
					$obj  = get_post( (int) $it->object_id );
					$slug = $obj ? get_page_uri( $obj ) : '';
				} elseif ( 'taxonomy' === $it->type ) {
					$term = get_term( (int) $it->object_id, $it->object );
					$slug = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
				}
				$rows[] = array(
					'title'       => Content::tok( $it->post_title ),
					'type'        => $it->type,
					'object'      => $it->object,
					'object_slug' => $slug,
					'url'         => 'custom' === $it->type ? Content::tok( $it->url ) : '',
					'parent'      => isset( $index[ (int) $it->menu_item_parent ] ) ? $index[ (int) $it->menu_item_parent ] : -1,
					'order'       => (int) $it->menu_order,
					'target'      => (string) $it->target,
					'classes'     => array_values( array_filter( (array) $it->classes, 'strlen' ) ),
					'xfn'         => (string) $it->xfn,
					'description' => (string) $it->description,
					'attr_title'  => (string) $it->attr_title,
				);
			}
			$locs = array_keys( array_filter( $loc, static function ( $id ) use ( $menu ) {
				return (int) $id === (int) $menu->term_id;
			} ) );
			sort( $locs );
			$out[ 'menu:' . $menu->slug ] = array(
				'label' => 'menu: ' . $menu->name,
				'data'  => array(
					'type'      => 'menu',
					'slug'      => $menu->slug,
					'name'      => $menu->name,
					'locations' => $locs,
					'items'     => $rows,
				),
			);
		}
		return $out;
	}

	/**
	 * Import (replace) a menu.
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import( array $d, $run ) {
		$slug = sanitize_title( isset( $d['slug'] ) ? $d['slug'] : '' );
		$name = sanitize_text_field( isset( $d['name'] ) ? $d['name'] : $slug );
		if ( '' === $slug ) {
			return new \WP_Error( 'avesta_sync_menu', 'Menu slug missing.' );
		}
		$menu = wp_get_nav_menu_object( $slug );
		if ( $menu ) {
			$old = self::export();
			if ( isset( $old[ 'menu:' . $slug ] ) ) {
				Backup::save_json( 'menu:' . $slug, $old[ 'menu:' . $slug ]['data'], $run );
			}
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $it ) {
				wp_delete_post( $it->ID, true );
			}
			wp_update_nav_menu_object( $menu->term_id, array( 'menu-name' => $name ) );
			$menu_id = (int) $menu->term_id;
		} else {
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
			wp_update_term( $menu_id, 'nav_menu', array( 'slug' => $slug ) );
		}
		$ids  = array();
		$rows = isset( $d['items'] ) ? array_values( (array) $d['items'] ) : array();
		foreach ( $rows as $i => $r ) {
			$args = array(
				'menu-item-title'       => sanitize_text_field( (string) Content::untok( $r['title'] ) ),
				'menu-item-position'    => (int) $r['order'],
				'menu-item-target'      => '_blank' === $r['target'] ? '_blank' : '',
				'menu-item-classes'     => implode( ' ', array_map( 'sanitize_html_class', (array) $r['classes'] ) ),
				'menu-item-xfn'         => sanitize_text_field( $r['xfn'] ),
				'menu-item-description' => sanitize_textarea_field( $r['description'] ),
				'menu-item-attr-title'  => sanitize_text_field( $r['attr_title'] ),
				'menu-item-status'      => 'publish',
			);
			$obj_id = 0;
			if ( 'post_type' === $r['type'] && '' !== $r['object_slug'] ) {
				$p      = get_page_by_path( $r['object_slug'], OBJECT, $r['object'] );
				$obj_id = $p ? $p->ID : 0;
			} elseif ( 'taxonomy' === $r['type'] && '' !== $r['object_slug'] ) {
				$t      = get_term_by( 'slug', $r['object_slug'], $r['object'] );
				$obj_id = $t ? $t->term_id : 0;
			}
			if ( $obj_id ) {
				$args['menu-item-type']      = $r['type'];
				$args['menu-item-object']    = sanitize_key( $r['object'] );
				$args['menu-item-object-id'] = $obj_id;
			} else {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = esc_url_raw( (string) Content::untok( '' !== $r['url'] ? $r['url'] : home_url( '/' ) ) );
			}
			$new = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! is_wp_error( $new ) ) {
				$ids[ $i ] = (int) $new;
			}
		}
		foreach ( $rows as $i => $r ) {
			if ( $r['parent'] >= 0 && isset( $ids[ $i ], $ids[ $r['parent'] ] ) ) {
				update_post_meta( $ids[ $i ], '_menu_item_menu_item_parent', $ids[ $r['parent'] ] );
			}
		}
		$locs = get_nav_menu_locations();
		foreach ( $locs as $loc => $mid ) {
			if ( (int) $mid === $menu_id ) {
				unset( $locs[ $loc ] );
			}
		}
		$registered = get_registered_nav_menus();
		foreach ( (array) ( isset( $d['locations'] ) ? $d['locations'] : array() ) as $loc ) {
			if ( isset( $registered[ $loc ] ) ) {
				$locs[ $loc ] = $menu_id;
			}
		}
		set_theme_mod( 'nav_menu_locations', $locs );
		return 'menu replaced (' . count( $ids ) . ' items)';
	}

	/**
	 * Delete a menu by slug.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function delete( $slug ) {
		$menu = wp_get_nav_menu_object( $slug );
		return $menu && ! is_wp_error( wp_delete_nav_menu( $menu->term_id ) );
	}
}
