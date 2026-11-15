<?php
/**
 * WooCommerce global attributes (items wc_attr:<slug>) and attribute/term creation helpers.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Data never contains IDs.
 */
class Woo_Taxonomies {

	const ORDERBY  = array( 'menu_order', 'name', 'name_num', 'id' );

	/**
	 * Global attribute rows keyed by slug.
	 *
	 * @return array slug => object
	 */
	public static function attr_rows() {
		$out = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $row ) {
			$out[ (string) $row->attribute_name ] = $row;
		}
		return $out;
	}

	/**
	 * Export global attributes.
	 *
	 * @param array|null $keys Keys filter.
	 * @return array List of {key,data,label,ord}.
	 */
	public static function export_attrs( $keys ) {
		$want = Woo::keys_for( $keys, 'wc_attr:' );
		$out  = array();
		if ( array() === $want ) {
			return $out;
		}
		foreach ( self::attr_rows() as $slug => $row ) {
			if ( null !== $want && ! in_array( $slug, $want, true ) ) {
				continue;
			}
			$out[] = self::attr_item( $row );
		}
		return $out;
	}

	/**
	 * Item for one attribute row.
	 *
	 * @param object $row Attribute taxonomy row.
	 * @return array
	 */
	private static function attr_item( $row ) {
		$label = '' !== (string) $row->attribute_label ? (string) $row->attribute_label : (string) $row->attribute_name;
		return array(
			'key'   => 'wc_attr:' . $row->attribute_name,
			/* translators: %s: attribute label. */
			'label' => sprintf( __( 'Attribute: %s', 'avesta-sync' ), $label ),
			'ord'   => 1,
			'data'  => array(
				'type'      => 'wc_attr',
				'slug'      => (string) $row->attribute_name,
				'label'     => Content::tok( $label ),
				'attr_type' => (string) $row->attribute_type,
				'orderby'   => (string) $row->attribute_orderby,
				'public'    => (bool) $row->attribute_public,
			),
		);
	}

	/**
	 * Register an attribute taxonomy for the rest of this request (WooCommerce does it on init).
	 *
	 * @param string $slug  Attribute slug.
	 * @param string $label Label.
	 */
	private static function register_attr_tax( $slug, $label ) {
		$name = wc_attribute_taxonomy_name( $slug );
		if ( taxonomy_exists( $name ) ) {
			return;
		}
		register_taxonomy(
			$name,
			array( 'product' ),
			array(
				'hierarchical'          => false,
				'update_count_callback' => '_update_post_term_count',
				'labels'                => array(
					'name'          => $label,
					'singular_name' => $label,
				),
				'show_ui'               => false,
				'query_var'             => false,
				'rewrite'               => false,
				'sort'                  => false,
				'public'                => false,
			)
		);
	}

	/**
	 * Make sure a global attribute exists (created with defaults when missing).
	 *
	 * @param string $tax   Taxonomy name (pa_*).
	 * @param string $label Label for a new attribute.
	 * @return bool
	 */
	public static function ensure_attr( $tax, $label ) {
		$slug = wc_attribute_taxonomy_slug( $tax );
		if ( '' === $slug ) {
			return false;
		}
		if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
			$id = wc_create_attribute(
				array(
					'slug'         => $slug,
					'name'         => '' !== $label ? $label : ucwords( str_replace( array( '-', '_' ), ' ', urldecode( $slug ) ) ),
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $id ) ) {
				Woo_Refs::note( sprintf( /* translators: 1: attribute slug, 2: error. */ __( 'The attribute "%1$s" could not be created. WooCommerce said: %2$s', 'avesta-sync' ), $slug, wp_strip_all_tags( $id->get_error_message() ) ) );
				return false;
			}
			Woo_Refs::note( sprintf( /* translators: %s: attribute slug. */ __( 'Attribute "%s" was created with default settings. Sync attributes to copy its settings', 'avesta-sync' ), $slug ) );
		}
		self::register_attr_tax( $slug, '' !== $label ? $label : $slug );
		return taxonomy_exists( wc_attribute_taxonomy_name( $slug ) );
	}

	/**
	 * Term ID by slug, creating a plain term when missing (attribute options only).
	 *
	 * @param string $tax  Taxonomy.
	 * @param string $slug Slug.
	 * @return int
	 */
	public static function ensure_term( $tax, $slug ) {
		$id = Woo_Refs::term_id( $tax, $slug );
		if ( $id || ! taxonomy_exists( $tax ) ) {
			return $id;
		}
		$new = wp_insert_term( ucwords( str_replace( array( '-', '_' ), ' ', urldecode( $slug ) ) ), $tax, array( 'slug' => $slug ) );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/**
	 * Import an attribute.
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import_attr( array $d, $run ) {
		$slug = isset( $d['slug'] ) && is_string( $d['slug'] ) ? wc_sanitize_taxonomy_name( $d['slug'] ) : '';
		if ( '' === $slug ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'The attribute has no name.', 'avesta-sync' ) );
		}
		$label = (string) Content::untok( isset( $d['label'] ) && is_string( $d['label'] ) ? $d['label'] : $slug );
		$type  = isset( $d['attr_type'] ) && is_string( $d['attr_type'] ) && array_key_exists( $d['attr_type'], wc_get_attribute_types() ) ? $d['attr_type'] : 'select';
		$order = isset( $d['orderby'] ) && in_array( $d['orderby'], self::ORDERBY, true ) ? $d['orderby'] : 'menu_order';
		$args  = array(
			'slug'         => $slug,
			'name'         => sanitize_text_field( $label ),
			'type'         => $type,
			'order_by'     => $order,
			'has_archives' => ! empty( $d['public'] ),
		);
		$rows  = self::attr_rows();
		if ( isset( $rows[ $slug ] ) ) {
			$old = self::attr_item( $rows[ $slug ] );
			if ( ! Backup::save_json( $old['key'], $old['data'], $run ) ) {
				return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was changed.', 'avesta-sync' ) );
			}
			$res = wc_update_attribute( (int) $rows[ $slug ]->attribute_id, $args );
		} else {
			$res = wc_create_attribute( $args );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::register_attr_tax( $slug, $label );
		return isset( $rows[ $slug ] ) ? __( 'Updated.', 'avesta-sync' ) : __( 'Created.', 'avesta-sync' );
	}

	/**
	 * Is any product (or variation) using the taxonomy / term?
	 *
	 * @param string   $tax     Taxonomy.
	 * @param int|null $term_id Term (null = any term of the taxonomy).
	 * @return bool
	 */
	public static function in_use( $tax, $term_id = null ) {
		$clause = array(
			'taxonomy'         => $tax,
			'include_children' => false,
		);
		if ( null === $term_id ) {
			$clause['operator'] = 'EXISTS';
		} else {
			$clause['field'] = 'term_id';
			$clause['terms'] = array( (int) $term_id );
		}
		$q = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => Woo_Refs::STATUSES,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( $clause ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $q->posts ) {
			return true;
		}
		if ( null === $term_id || 0 === strpos( $tax, 'pa_' ) ) {
			$v = new \WP_Query(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => 'attribute_' . $tax,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			return (bool) $v->posts;
		}
		return false;
	}

	/**
	 * Delete an attribute (refused while products use it).
	 *
	 * @param string $slug Attribute slug.
	 * @param string $key  Item key.
	 * @param string $run  Run id.
	 * @return string|\WP_Error
	 */
	public static function delete_attr( $slug, $key, $run ) {
		$rows = self::attr_rows();
		if ( ! isset( $rows[ $slug ] ) ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'It was not found.', 'avesta-sync' ) );
		}
		$tax = wc_attribute_taxonomy_name( $slug );
		if ( taxonomy_exists( $tax ) && self::in_use( $tax ) ) {
			return new \WP_Error( 'avesta_sync_wc_in_use', __( 'Not deleted: products on the receiving site still use this attribute.', 'avesta-sync' ) );
		}
		$item = self::attr_item( $rows[ $slug ] );
		if ( ! Backup::save_json( $key, $item['data'], $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was deleted.', 'avesta-sync' ) );
		}
		return wc_delete_attribute( (int) $rows[ $slug ]->attribute_id ) ? __( 'Attribute deleted.', 'avesta-sync' ) : new \WP_Error( 'avesta_sync_wc', __( 'Delete failed.', 'avesta-sync' ) );
	}
}
