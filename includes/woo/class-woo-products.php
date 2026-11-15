<?php
/**
 * WooCommerce products: export (variations embedded), lookup and trashing.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Item wc_product:sku:<sku> or wc_product:slug:<slug>. Data never contains IDs or GUIDs.
 *
 * "Custom meta" rule: meta keys that do not start with an underscore, are not WooCommerce internal
 * meta keys of the product data store and are not total_sales. Private (underscore) keys written by
 * other plugins are deliberately not synced.
 */
class Woo_Products {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Export products.
	 *
	 * @param array|null $keys Keys filter.
	 * @return array List of {key,data,label,ord,meta}.
	 */
	public static function export( $keys ) {
		$refs = Woo::keys_for( $keys, 'wc_product:' );
		$out  = array();
		if ( array() === $refs ) {
			return $out;
		}
		if ( null === $refs ) {
			$ids = wc_get_products(
				array(
					'limit'   => -1,
					'return'  => 'ids',
					'status'  => self::STATUSES,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
		} else {
			$ids = array();
			foreach ( $refs as $ref ) {
				$id = Woo_Refs::product_id( $ref );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		$skipper   = 0;
		$seen      = array();
		$names     = array();
		$ambiguous = array();
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status( 'edit' ), self::STATUSES, true ) ) {
				continue;
			}
			if ( ! in_array( $p->get_type(), Woo::product_types(), true ) ) {
				self::skip_note( ++$skipper, sprintf( /* translators: 1: product name, 2: product type. */ __( 'Skipped "%1$s": product type "%2$s" is not supported.', 'avesta-sync' ), $p->get_name( 'edit' ), $p->get_type() ) );
				continue;
			}
			$item = self::item( $p );
			if ( ! $item ) {
				self::skip_note( ++$skipper, sprintf( /* translators: %s: product name. */ __( 'Skipped "%s": it has no SKU and no URL name yet, so it cannot be matched.', 'avesta-sync' ), $p->get_name( 'edit' ) ) );
				continue;
			}
			$names[ $item['key'] ][] = $p->get_name( 'edit' );
			if ( isset( $seen[ $item['key'] ] ) ) {
				$ambiguous[ $item['key'] ] = true;
			}
			$seen[ $item['key'] ] = true;
			$out[]                = $item;
		}
		if ( $ambiguous ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $it ) use ( $ambiguous ) {
						return ! isset( $ambiguous[ $it['key'] ] );
					}
				)
			);
			foreach ( array_keys( $ambiguous ) as $key ) {
				$ref = substr( $key, 11 );
				Woo::export_note(
					sprintf(
						/* translators: 1: "SKU" or "slug", 2: the shared SKU or slug, 3: list of product names. */
						__( 'Several products share the %1$s "%2$s", so none of them was synced: %3$s.', 'avesta-sync' ),
						0 === strpos( $ref, 'sku:' ) ? __( 'SKU', 'avesta-sync' ) : __( 'URL name', 'avesta-sync' ),
						Woo_Refs::plain_ref( $ref ),
						'"' . implode( '", "', $names[ $key ] ) . '"'
					)
				);
			}
		}
		return $out;
	}

	/**
	 * Remember a "skipped" note (the first ten only).
	 *
	 * @param int    $n    Running number.
	 * @param string $note Sentence.
	 */
	private static function skip_note( $n, $note ) {
		if ( $n <= 10 ) {
			Woo::export_note( $note );
		}
	}

	/**
	 * Export record of one product.
	 *
	 * @param \WC_Product $p Product.
	 * @return array|null {key,data,label,ord,meta}
	 */
	public static function item( \WC_Product $p ) {
		$ref = Woo_Refs::product_ref( $p->get_id() );
		if ( '' === $ref ) {
			return null;
		}
		$data = self::data( $p );
		$sku  = (string) $p->get_sku( 'edit' );
		return array(
			'key'   => 'wc_product:' . $ref,
			'data'  => $data,
			'label' => $p->get_name( 'edit' ) . ( '' !== $sku ? ' (' . $sku . ')' : '' ),
			'ord'   => 'grouped' === $p->get_type() ? 21 : 20,
			'meta'  => array(
				'type'       => $p->get_type(),
				'sku'        => $sku,
				'variations' => isset( $data['variations'] ) ? count( $data['variations'] ) : 0,
			),
		);
	}

	/**
	 * The whole stock state (protected: not hashed, written on create or with the stock option only).
	 *
	 * @param \WC_Product $p Product or variation.
	 * @return array
	 */
	public static function stock( \WC_Product $p ) {
		return array(
			'manage'     => (bool) $p->get_manage_stock( 'edit' ),
			'backorders' => (string) $p->get_backorders( 'edit' ),
			'low_amount' => $p->get_low_stock_amount( 'edit' ),
			'quantity'   => $p->get_stock_quantity( 'edit' ),
			'status'     => $p->get_stock_status( 'edit' ),
		);
	}

	/**
	 * Timestamp of a WooCommerce date (null when unset).
	 *
	 * @param mixed $dt WC_DateTime|null.
	 * @return int|null
	 */
	public static function ts( $dt ) {
		return $dt ? (int) $dt->getTimestamp() : null;
	}

	/**
	 * Downloadable file entries (name and tokenised file URL; IDs stay local).
	 *
	 * @param \WC_Product $p Product or variation.
	 * @return array
	 */
	public static function downloads( \WC_Product $p ) {
		$out = array();
		foreach ( $p->get_downloads( 'edit' ) as $dl ) {
			$out[] = array(
				'name'    => Content::tok( $dl->get_name() ),
				'file'    => Content::tok( $dl->get_file() ),
				'enabled' => (bool) $dl->get_enabled(),
			);
		}
		return $out;
	}

	/**
	 * Custom (non-internal) meta as key => list of values.
	 *
	 * @param \WC_Product $p Product or variation.
	 * @return array
	 */
	public static function meta( \WC_Product $p ) {
		$out = array();
		foreach ( $p->get_meta_data() as $m ) {
			$k = (string) $m->key;
			if ( ! self::public_meta_key( $p, $k ) ) {
				continue;
			}
			$v = $m->value;
			if ( is_object( $v ) ) {
				continue;
			}
			$out[ $k ][] = Content::tok( $v );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Does a meta key belong to the synced custom meta?
	 *
	 * @param \WC_Product $p Product.
	 * @param string      $k Key.
	 * @return bool
	 */
	public static function public_meta_key( \WC_Product $p, $k ) {
		return '' !== $k && 0 !== strpos( $k, '_' ) && 0 !== strpos( $k, 'attribute_' ) && 0 !== strpos( $k, 'wp_' ) && 'total_sales' !== $k && ! in_array( $k, $p->get_data_store()->get_internal_meta_keys(), true );
	}

	/**
	 * Slug of a shipping class by ID ('' = none).
	 *
	 * @param int $id Term ID.
	 * @return string
	 */
	public static function class_slug( $id ) {
		$t = $id ? get_term( (int) $id, 'product_shipping_class' ) : null;
		return $t instanceof \WP_Term ? (string) $t->slug : '';
	}

	/**
	 * Portable data of a product.
	 *
	 * @param \WC_Product $p Product.
	 * @return array
	 */
	private static function data( \WC_Product $p ) {
		$stock = self::stock( $p );
		$d     = array(
			'type'               => 'wc_product',
			'ptype'              => $p->get_type(),
			'slug'               => (string) $p->get_slug( 'edit' ),
			'sku'                => (string) $p->get_sku( 'edit' ),
			'name'               => Content::tok( $p->get_name( 'edit' ) ),
			'status'             => $p->get_status( 'edit' ),
			'date_created'       => self::ts( $p->get_date_created( 'edit' ) ),
			'description'        => Content::tok( $p->get_description( 'edit' ) ),
			'short_description'  => Content::tok( $p->get_short_description( 'edit' ) ),
			'regular_price'      => (string) $p->get_regular_price( 'edit' ),
			'sale_price'         => (string) $p->get_sale_price( 'edit' ),
			'date_on_sale_from'  => self::ts( $p->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'    => self::ts( $p->get_date_on_sale_to( 'edit' ) ),
			'tax_status'         => $p->get_tax_status( 'edit' ),
			'tax_class'          => $p->get_tax_class( 'edit' ),
			'sold_individually'  => (bool) $p->get_sold_individually( 'edit' ),
			'weight'             => (string) $p->get_weight( 'edit' ),
			'length'             => (string) $p->get_length( 'edit' ),
			'width'              => (string) $p->get_width( 'edit' ),
			'height'             => (string) $p->get_height( 'edit' ),
			'shipping_class'     => self::class_slug( $p->get_shipping_class_id( 'edit' ) ),
			'virtual'            => (bool) $p->get_virtual( 'edit' ),
			'downloadable'       => (bool) $p->get_downloadable( 'edit' ),
			'downloads'          => self::downloads( $p ),
			'download_limit'     => (int) $p->get_download_limit( 'edit' ),
			'download_expiry'    => (int) $p->get_download_expiry( 'edit' ),
			'reviews_allowed'    => (bool) $p->get_reviews_allowed( 'edit' ),
			'purchase_note'      => Content::tok( $p->get_purchase_note( 'edit' ) ),
			'menu_order'         => (int) $p->get_menu_order( 'edit' ),
			'catalog_visibility' => $p->get_catalog_visibility( 'edit' ),
			'featured'           => (bool) $p->get_featured( 'edit' ),
			'categories'         => Woo_Refs::term_slugs( $p->get_id(), 'product_cat' ),
			'tags'               => Woo_Refs::term_slugs( $p->get_id(), 'product_tag' ),
			'attributes'         => self::attributes( $p ),
			'upsells'            => Woo_Refs::product_refs( $p->get_upsell_ids( 'edit' ) ),
			'cross_sells'        => Woo_Refs::product_refs( $p->get_cross_sell_ids( 'edit' ) ),
			'image'              => Woo_Refs::file_of( $p->get_image_id( 'edit' ) ),
			'gallery'            => array_values( array_filter( array_map( array( Woo_Refs::class, 'file_of' ), $p->get_gallery_image_ids( 'edit' ) ) ) ),
			'meta'               => self::meta( $p ),
		);
		if ( taxonomy_exists( 'product_brand' ) ) {
			$brands = Woo_Refs::term_slugs( $p->get_id(), 'product_brand' );
			if ( $brands ) {
				$d['brands'] = $brands; // Only present when the product has brands: hashes of brand-less products are unchanged.
			}
		}
		if ( $p->is_type( 'grouped' ) ) {
			$d['children'] = Woo_Refs::product_refs( $p->get_children( 'edit' ) );
		}
		if ( $p->is_type( 'external' ) ) {
			// WooCommerce forces stock management off for external products: nothing to carry besides the URL and button text.
			$d['product_url']  = Content::tok( $p->get_product_url( 'edit' ) );
			$d['button_text']  = (string) $p->get_button_text( 'edit' );
		}
		if ( $p->is_type( 'variable' ) ) {
			$defaults = (array) $p->get_default_attributes( 'edit' );
			ksort( $defaults );
			$d['default_attributes'] = $defaults;
			$d['variations']         = Woo_Variations::export( $p );
		}
		$d['stock'] = $stock;
		return $d;
	}

	/**
	 * Product attributes (global ones by taxonomy name and term slugs; custom ones by label and values).
	 *
	 * @param \WC_Product $p Product.
	 * @return array
	 */
	private static function attributes( \WC_Product $p ) {
		$out = array();
		foreach ( $p->get_attributes( 'edit' ) as $attr ) {
			if ( ! $attr instanceof \WC_Product_Attribute ) {
				continue;
			}
			$row = array(
				'name'      => $attr->is_taxonomy() ? $attr->get_name() : Content::tok( $attr->get_name() ),
				'global'    => $attr->is_taxonomy(),
				'position'  => (int) $attr->get_position(),
				'visible'   => (bool) $attr->get_visible(),
				'variation' => (bool) $attr->get_variation(),
			);
			if ( $attr->is_taxonomy() ) {
				$slugs        = array();
				foreach ( (array) $attr->get_terms() as $t ) {
					$slugs[] = (string) $t->slug;
				}
				sort( $slugs );
				$row['options'] = $slugs;
			} else {
				$row['options'] = array_map( array( Content::class, 'tok' ), array_map( 'strval', $attr->get_options() ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Move a product to the trash after a JSON backup.
	 *
	 * @param string $ref Product ref.
	 * @param string $key Item key.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function delete( $ref, $key, $run ) {
		$id = Woo_Refs::product_id( $ref );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'It was not found.', 'avesta-sync' ) );
		}
		$item = self::item( $p );
		if ( $item && ! Backup::save_json( $key, $item['data'], $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was deleted.', 'avesta-sync' ) );
		}
		return $p->delete( false ) ? __( 'Moved to Trash.', 'avesta-sync' ) : new \WP_Error( 'avesta_sync_wc', __( 'It could not be moved to the Trash.', 'avesta-sync' ) );
	}
}
