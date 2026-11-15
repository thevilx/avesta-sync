<?php
/**
 * WooCommerce product import (create or update through the CRUD API only).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a wc_product item. Stock quantity and status are written on create, and on update only
 * when the run asks for it ($opts['stock']).
 */
class Woo_Product_Import {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Import one product item.
	 *
	 * @param array  $d    Data.
	 * @param string $run  Run id.
	 * @param array  $opts {stock:bool}.
	 * @return string|\WP_Error
	 */
	public static function run( array $d, $run, array $opts ) {
		$ptype = isset( $d['ptype'] ) && is_string( $d['ptype'] ) ? $d['ptype'] : '';
		if ( ! in_array( $ptype, Woo::product_types(), true ) ) {
			/* translators: %s: product type. */
			return new \WP_Error( 'avesta_sync_wc_type', sprintf( __( 'Product type "%s" is not supported.', 'avesta-sync' ), '' !== $ptype ? $ptype : '?' ) );
		}
		$sku  = isset( $d['sku'] ) && is_string( $d['sku'] ) ? $d['sku'] : '';
		$slug = isset( $d['slug'] ) && is_string( $d['slug'] ) ? sanitize_title( $d['slug'] ) : '';
		if ( '' === $sku && '' === $slug ) {
			/* translators: %s: product name. */
			return new \WP_Error( 'avesta_sync_wc', sprintf( __( 'Skipped "%s": it has no SKU and no URL name yet, so it cannot be matched.', 'avesta-sync' ), isset( $d['name'] ) && is_string( $d['name'] ) ? $d['name'] : '?' ) );
		}
		$ref = '' !== $sku ? 'sku:' . $sku : 'slug:' . $slug;
		$id  = Woo_Refs::product_id( $ref, true );
		if ( ! $id && '' !== $sku && '' !== $slug ) {
			$cand = Woo_Refs::product_id( 'slug:' . $slug, true ); // Same slug and no SKU on this side: the same product.
			$cp   = $cand ? wc_get_product( $cand ) : null;
			$id   = $cp && '' === (string) $cp->get_sku( 'edit' ) ? $cand : 0;
		}
		$existing = $id ? wc_get_product( $id ) : null;
		if ( $id && ( ! $existing || $existing->is_type( 'variation' ) ) ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'The matching item on the receiving site is not a product.', 'avesta-sync' ) );
		}
		if ( $existing ) {
			$old = Woo_Products::item( $existing );
			if ( $old && ! Backup::save_json( $old['key'], $old['data'], $run ) ) {
				return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was changed.', 'avesta-sync' ) );
			}
			if ( $existing->get_type() !== $ptype ) {
				$existing = self::change_type( $existing, $ptype );
			}
		}
		$stock = ! empty( $opts['stock'] );
		$p     = $existing ? $existing : self::blank( $ptype );
		try {
			self::apply( $p, $d, ! $existing, ! $existing || $stock, $ref );
			self::save( $p );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'avesta_sync_wc', $e->getMessage() );
		}
		$id   = (int) $p->get_id();
		self::set_brands( $id, $d );
		$info = '';
		if ( $p->is_type( 'variable' ) ) {
			$info = Woo_Variations::import( $p, isset( $d['variations'] ) && is_array( $d['variations'] ) ? $d['variations'] : array(), $stock );
		}
		if ( '' !== $slug && get_post_field( 'post_name', $id ) !== $slug ) {
			Woo_Refs::note( __( 'The URL name is already used by another product on the receiving site', 'avesta-sync' ) );
		}
		Woo_Refs::resolve_pending( $ref, $id );
		$head = $existing ? __( 'Updated', 'avesta-sync' ) : __( 'Created', 'avesta-sync' );
		return $head . ( '' !== $info ? ', ' . $info : '.' );
	}

	/**
	 * Fresh product object of a type.
	 *
	 * @param string $type Type.
	 * @return \WC_Product
	 */
	private static function blank( $type ) {
		$class = \WC_Product_Factory::get_classname_from_product_type( $type );
		return class_exists( $class ) ? new $class() : new \WC_Product_Simple();
	}

	/**
	 * Switch an existing product to another type (variations are trashed when leaving "variable").
	 *
	 * @param \WC_Product $p    Product.
	 * @param string      $type New type.
	 * @return \WC_Product
	 */
	private static function change_type( \WC_Product $p, $type ) {
		$id = $p->get_id();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$v->delete( false );
				}
			}
		}
		wp_set_object_terms( $id, $type, 'product_type' );
		wc_delete_product_transients( $id );
		clean_post_cache( $id );
		$new = wc_get_product( $id );
		return $new ? $new : $p;
	}

	/**
	 * Save with the content filters relaxed (the peer is authenticated, as for posts).
	 *
	 * @param \WC_Data $obj Product or variation.
	 * @return int
	 */
	private static function save( $obj ) {
		$had = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		try {
			return (int) $obj->save();
		} finally {
			if ( $had ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * String field of the data.
	 *
	 * @param array  $d   Data.
	 * @param string $k   Key.
	 * @param string $def Default.
	 * @return string
	 */
	private static function s( array $d, $k, $def = '' ) {
		return isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) ? (string) $d[ $k ] : $def;
	}

	/**
	 * Timestamp field (null when empty).
	 *
	 * @param array  $d Data.
	 * @param string $k Key.
	 * @return int|null
	 */
	private static function t( array $d, $k ) {
		return isset( $d[ $k ] ) && is_numeric( $d[ $k ] ) ? (int) $d[ $k ] : null;
	}

	/**
	 * Set every synced property.
	 *
	 * @param \WC_Product $p     Product.
	 * @param array       $d     Data.
	 * @param bool        $new   Creating.
	 * @param bool        $stock Write stock quantity and status.
	 * @param string      $ref   Product ref (holder of deferred links).
	 */
	private static function apply( \WC_Product $p, array $d, $new, $stock, $ref ) {
		$p->set_name( sanitize_text_field( (string) Content::untok( self::s( $d, 'name' ) ) ) );
		if ( '' !== self::s( $d, 'slug' ) ) {
			$p->set_slug( sanitize_title( self::s( $d, 'slug' ) ) );
		}
		$p->set_sku( self::s( $d, 'sku' ) );
		$status = self::s( $d, 'status' );
		$p->set_status( in_array( $status, self::STATUSES, true ) ? $status : 'draft' );
		if ( null !== self::t( $d, 'date_created' ) ) {
			$p->set_date_created( self::t( $d, 'date_created' ) );
		}
		$p->set_description( (string) Content::untok( self::s( $d, 'description' ) ) );
		$p->set_short_description( (string) Content::untok( self::s( $d, 'short_description' ) ) );
		$p->set_regular_price( self::s( $d, 'regular_price' ) );
		$p->set_sale_price( self::s( $d, 'sale_price' ) );
		$p->set_date_on_sale_from( self::t( $d, 'date_on_sale_from' ) );
		$p->set_date_on_sale_to( self::t( $d, 'date_on_sale_to' ) );
		$tax = self::s( $d, 'tax_status', 'taxable' );
		$p->set_tax_status( in_array( $tax, array( 'taxable', 'shipping', 'none' ), true ) ? $tax : 'taxable' );
		$p->set_tax_class( self::s( $d, 'tax_class' ) );
		$p->set_sold_individually( ! empty( $d['sold_individually'] ) );
		$p->set_weight( self::s( $d, 'weight' ) );
		$p->set_length( self::s( $d, 'length' ) );
		$p->set_width( self::s( $d, 'width' ) );
		$p->set_height( self::s( $d, 'height' ) );
		$class = self::s( $d, 'shipping_class' );
		$cid   = '' !== $class ? Woo_Refs::term_ids( 'product_shipping_class', array( $class ), __( 'shipping classes', 'avesta-sync' ) ) : array( 'ids' => array() );
		$p->set_shipping_class_id( $cid['ids'] ? $cid['ids'][0] : 0 );
		$p->set_virtual( ! empty( $d['virtual'] ) );
		$p->set_downloadable( ! empty( $d['downloadable'] ) );
		self::set_downloads( $p, isset( $d['downloads'] ) && is_array( $d['downloads'] ) ? $d['downloads'] : array() );
		$p->set_download_limit( isset( $d['download_limit'] ) ? (int) $d['download_limit'] : -1 );
		$p->set_download_expiry( isset( $d['download_expiry'] ) ? (int) $d['download_expiry'] : -1 );
		$p->set_reviews_allowed( ! empty( $d['reviews_allowed'] ) );
		$p->set_purchase_note( (string) Content::untok( self::s( $d, 'purchase_note' ) ) );
		$p->set_menu_order( isset( $d['menu_order'] ) ? (int) $d['menu_order'] : 0 );
		$vis = self::s( $d, 'catalog_visibility', 'visible' );
		$p->set_catalog_visibility( array_key_exists( $vis, wc_get_product_visibility_options() ) ? $vis : 'visible' );
		$p->set_featured( ! empty( $d['featured'] ) );
		self::set_terms( $p, $d );
		self::set_images( $p, $d );
		self::set_links( $p, $d, $ref );
		if ( $p->is_type( 'external' ) ) {
			$p->set_product_url( (string) Content::untok( self::s( $d, 'product_url' ) ) );
			$p->set_button_text( self::s( $d, 'button_text' ) );
		}
		if ( $p->is_type( 'variable' ) || ( isset( $d['attributes'] ) && is_array( $d['attributes'] ) && $d['attributes'] ) || ! $new ) {
			$p->set_attributes( self::attributes( isset( $d['attributes'] ) && is_array( $d['attributes'] ) ? $d['attributes'] : array() ) );
		}
		if ( $p->is_type( 'variable' ) ) {
			$defaults = array();
			foreach ( isset( $d['default_attributes'] ) && is_array( $d['default_attributes'] ) ? $d['default_attributes'] : array() as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$defaults[ (string) $k ] = (string) $v;
				}
			}
			$p->set_default_attributes( $defaults );
		}
		self::set_meta( $p, isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array() );
		self::set_stock( $p, isset( $d['stock'] ) && is_array( $d['stock'] ) ? $d['stock'] : array(), $stock );
	}

	/**
	 * Categories, tags (an empty resolution never wipes existing terms).
	 *
	 * @param \WC_Product $p Product.
	 * @param array       $d Data.
	 */
	private static function set_terms( \WC_Product $p, array $d ) {
		$cats = isset( $d['categories'] ) && is_array( $d['categories'] ) ? $d['categories'] : array();
		$r    = Woo_Refs::term_ids( 'product_cat', $cats, __( 'categories', 'avesta-sync' ) );
		if ( $r['ids'] || ! $cats ) {
			$p->set_category_ids( $r['ids'] );
		}
		$tags = isset( $d['tags'] ) && is_array( $d['tags'] ) ? $d['tags'] : array();
		$r    = Woo_Refs::term_ids( 'product_tag', $tags, __( 'tags', 'avesta-sync' ) );
		if ( $r['ids'] || ! $tags ) {
			$p->set_tag_ids( $r['ids'] );
		}
	}

	/**
	 * Brands (taxonomy product_brand): skipped with a note on sites without it.
	 *
	 * @param int   $id Product ID.
	 * @param array $d  Data.
	 */
	private static function set_brands( $id, array $d ) {
		$brands = isset( $d['brands'] ) && is_array( $d['brands'] ) ? $d['brands'] : array();
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			if ( $brands ) {
				Woo_Refs::note( __( 'Brands were skipped because the receiving site has no product brands', 'avesta-sync' ) );
			}
			return;
		}
		$r = Woo_Refs::term_ids( 'product_brand', $brands, __( 'brands', 'avesta-sync' ) );
		if ( $r['ids'] || ! $brands ) {
			wp_set_object_terms( $id, $r['ids'], 'product_brand' );
		}
	}

	/**
	 * Image and gallery by file path (unresolved ones are reported and left as they are).
	 *
	 * @param \WC_Product $p Product.
	 * @param array       $d Data.
	 */
	private static function set_images( \WC_Product $p, array $d ) {
		$file = self::s( $d, 'image' );
		$att  = Woo_Refs::attachment_id( $file );
		if ( $att || '' === $file ) {
			$p->set_image_id( $att );
		} else {
			/* translators: %s: file path. */
			Woo_Refs::note( sprintf( __( 'Image "%s" was not found on the receiving site. Sync the media library first', 'avesta-sync' ), $file ) );
		}
		$ids     = array();
		$missing = 0;
		foreach ( isset( $d['gallery'] ) && is_array( $d['gallery'] ) ? $d['gallery'] : array() as $f ) {
			$a = is_string( $f ) ? Woo_Refs::attachment_id( $f ) : 0;
			if ( $a ) {
				$ids[] = $a;
			} else {
				++$missing;
			}
		}
		if ( $missing ) {
			/* translators: %d: number of gallery images. */
			Woo_Refs::note( sprintf( _n( '%d gallery image was not found on the receiving site. Sync the media library first', '%d gallery images were not found on the receiving site. Sync the media library first', $missing, 'avesta-sync' ), $missing ) );
		}
		if ( $ids || ! $missing ) {
			$p->set_gallery_image_ids( $ids );
		}
	}

	/**
	 * Upsells, cross-sells and grouped children (unresolved links are completed when those products arrive).
	 *
	 * @param \WC_Product $p   Product.
	 * @param array       $d   Data.
	 * @param string      $ref Holder ref.
	 */
	private static function set_links( \WC_Product $p, array $d, $ref ) {
		$fields = array(
			'upsells'     => array( 'set_upsell_ids', __( 'upsells', 'avesta-sync' ) ),
			'cross_sells' => array( 'set_cross_sell_ids', __( 'cross-sells', 'avesta-sync' ) ),
		);
		if ( $p->is_type( 'grouped' ) ) {
			$fields['children'] = array( 'set_children', __( 'grouped products', 'avesta-sync' ) );
		}
		foreach ( $fields as $field => $def ) {
			$r = Woo_Refs::product_ids( isset( $d[ $field ] ) && is_array( $d[ $field ] ) ? $d[ $field ] : array(), $def[1] );
			$p->{$def[0]}( $r['ids'] );
			Woo_Refs::defer( $ref, $field, $r['missing'] );
		}
	}

	/**
	 * Attribute objects from item data (global attributes and their terms are created when missing).
	 *
	 * @param array $list Attribute list.
	 * @return \WC_Product_Attribute[]
	 */
	private static function attributes( array $list ) {
		$out = array();
		foreach ( $list as $i => $a ) {
			if ( ! is_array( $a ) || empty( $a['name'] ) || ! is_string( $a['name'] ) ) {
				continue;
			}
			$attr    = new \WC_Product_Attribute();
			$options = isset( $a['options'] ) && is_array( $a['options'] ) ? array_map( 'strval', $a['options'] ) : array();
			if ( ! empty( $a['global'] ) ) {
				$tax = sanitize_title( $a['name'] );
				if ( 0 !== strpos( $tax, 'pa_' ) || ! Woo_Taxonomies::ensure_attr( $tax, '' ) ) {
					continue;
				}
				$ids = array();
				foreach ( $options as $slug ) {
					$tid = Woo_Taxonomies::ensure_term( $tax, $slug );
					if ( $tid ) {
						$ids[] = $tid;
					}
				}
				$attr->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
				$attr->set_name( $tax );
				$attr->set_options( $ids );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( (string) Content::untok( $a['name'] ) );
				$attr->set_options( array_map( array( Content::class, 'untok' ), $options ) );
			}
			$attr->set_position( isset( $a['position'] ) ? (int) $a['position'] : (int) $i );
			$attr->set_visible( ! empty( $a['visible'] ) );
			$attr->set_variation( ! empty( $a['variation'] ) );
			$out[] = $attr;
		}
		return $out;
	}

	/**
	 * Downloadable file entries (existing entries with the same file keep their ID, which orders refer to).
	 *
	 * @param \WC_Product $obj  Product or variation.
	 * @param array       $list Entries.
	 */
	public static function set_downloads( \WC_Product $obj, array $list ) {
		$known = array();
		foreach ( $obj->get_downloads( 'edit' ) as $did => $dl ) {
			$known[ $dl->get_file() ] = $did;
		}
		$rows = array();
		foreach ( $list as $e ) {
			if ( ! is_array( $e ) || empty( $e['file'] ) || ! is_string( $e['file'] ) ) {
				continue;
			}
			$file   = (string) Content::untok( $e['file'] );
			$rows[] = array(
				'download_id' => isset( $known[ $file ] ) ? $known[ $file ] : '',
				'name'        => isset( $e['name'] ) && is_string( $e['name'] ) ? (string) Content::untok( $e['name'] ) : '',
				'file'        => $file,
				'enabled'     => ! isset( $e['enabled'] ) || ! empty( $e['enabled'] ),
			);
		}
		try {
			$obj->set_downloads( $rows );
		} catch ( \Exception $e ) {
			/* translators: %s: error. */
			Woo_Refs::note( sprintf( __( 'WooCommerce did not accept a downloadable file: %s', 'avesta-sync' ), wp_strip_all_tags( $e->getMessage() ) ) );
		}
	}

	/**
	 * Custom (non-internal) meta: replaced as a set.
	 *
	 * @param \WC_Product $obj  Product or variation.
	 * @param array       $meta key => list of values.
	 */
	public static function set_meta( \WC_Product $obj, array $meta ) {
		foreach ( $obj->get_meta_data() as $m ) {
			$k = (string) $m->key;
			if ( Woo_Products::public_meta_key( $obj, $k ) && ! isset( $meta[ $k ] ) ) {
				$obj->delete_meta_data( $k );
			}
		}
		foreach ( $meta as $k => $vals ) {
			$k = (string) $k;
			if ( ! is_array( $vals ) || ! Woo_Products::public_meta_key( $obj, $k ) ) {
				continue;
			}
			$obj->delete_meta_data( $k );
			foreach ( $vals as $val ) {
				$obj->add_meta_data( $k, Content::untok( $val ), false );
			}
		}
	}

	/**
	 * The whole stock state: management, backorders, low-stock threshold, quantity and status.
	 * Written only when $write is true (create, or a run with the stock option).
	 *
	 * @param \WC_Product $obj   Product or variation.
	 * @param array       $stock {manage,backorders,low_amount,quantity,status}.
	 * @param bool        $write Write them.
	 */
	public static function set_stock( \WC_Product $obj, array $stock, $write ) {
		if ( ! $write || ! $stock ) {
			return;
		}
		$manage = ! empty( $stock['manage'] );
		$obj->set_manage_stock( $manage );
		$back = isset( $stock['backorders'] ) && is_string( $stock['backorders'] ) ? $stock['backorders'] : 'no';
		$obj->set_backorders( in_array( $back, array( 'no', 'notify', 'yes' ), true ) ? $back : 'no' );
		$obj->set_low_stock_amount( isset( $stock['low_amount'] ) && is_numeric( $stock['low_amount'] ) ? (int) $stock['low_amount'] : '' );
		$obj->set_stock_quantity( $manage && isset( $stock['quantity'] ) && is_numeric( $stock['quantity'] ) ? wc_stock_amount( $stock['quantity'] ) : null );
		if ( ! $obj->is_type( 'variable' ) && ! $obj->is_type( 'grouped' ) && isset( $stock['status'] ) && array_key_exists( (string) $stock['status'], wc_get_product_stock_status_options() ) ) {
			$obj->set_stock_status( (string) $stock['status'] );
		}
	}
}
