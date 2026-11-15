<?php
/**
 * WooCommerce variations: embedded export and in-parent import.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Variations travel inside their parent's item. Matching: SKU first, then the attribute combination.
 */
class Woo_Variations {

	const STATUSES = array( 'publish', 'private', 'draft', 'pending' );

	/**
	 * Variation IDs of a variable product (trash excluded unless asked for).
	 *
	 * @param int      $parent_id Parent ID.
	 * @param string[] $statuses  Post statuses.
	 * @return int[]
	 */
	private static function ids( $parent_id, array $statuses = self::STATUSES ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'product_variation',
					'post_parent'      => (int) $parent_id,
					'post_status'      => $statuses,
					'numberposts'      => -1,
					'fields'           => 'ids',
					'orderby'          => 'menu_order ID',
					'order'            => 'ASC',
				)
			)
		);
	}

	/**
	 * Case-insensitive signature of an attribute combination.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private static function combo( array $attrs ) {
		$attrs = array_map( 'strtolower', array_map( 'strval', $attrs ) );
		ksort( $attrs );
		return (string) wp_json_encode( $attrs );
	}

	/**
	 * Export the variations in display order (menu order, then creation order: the import keeps it).
	 *
	 * @param \WC_Product $parent Variable product.
	 * @return array
	 */
	public static function export( \WC_Product $parent ) {
		$list = array();
		foreach ( self::ids( $parent->get_id() ) as $id ) {
			$v = wc_get_product( $id );
			if ( $v instanceof \WC_Product_Variation ) {
				$row               = self::data( $v );
				$row['menu_order'] = count( $list ); // Position, not the raw value: ties are broken by ID here, and the target reproduces the order.
				$list[]            = $row;
			}
		}
		return $list;
	}

	/**
	 * Portable data of one variation.
	 *
	 * @param \WC_Product_Variation $v Variation.
	 * @return array
	 */
	private static function data( \WC_Product_Variation $v ) {
		$attrs = array_map( 'strval', (array) $v->get_attributes( 'edit' ) );
		ksort( $attrs );
		return array(
			'sku'               => (string) $v->get_sku( 'edit' ),
			'attributes'        => $attrs,
			'status'            => $v->get_status( 'edit' ),
			'description'       => Content::tok( $v->get_description( 'edit' ) ),
			'regular_price'     => (string) $v->get_regular_price( 'edit' ),
			'sale_price'        => (string) $v->get_sale_price( 'edit' ),
			'date_on_sale_from' => Woo_Products::ts( $v->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'   => Woo_Products::ts( $v->get_date_on_sale_to( 'edit' ) ),
			'tax_status'        => $v->get_tax_status( 'edit' ),
			'tax_class'         => $v->get_tax_class( 'edit' ),
			'weight'            => (string) $v->get_weight( 'edit' ),
			'length'            => (string) $v->get_length( 'edit' ),
			'width'             => (string) $v->get_width( 'edit' ),
			'height'            => (string) $v->get_height( 'edit' ),
			'shipping_class'    => Woo_Products::class_slug( $v->get_shipping_class_id( 'edit' ) ),
			'virtual'           => (bool) $v->get_virtual( 'edit' ),
			'downloadable'      => (bool) $v->get_downloadable( 'edit' ),
			'downloads'         => Woo_Products::downloads( $v ),
			'download_limit'    => (int) $v->get_download_limit( 'edit' ),
			'download_expiry'   => (int) $v->get_download_expiry( 'edit' ),
			'image'             => Woo_Refs::file_of( $v->get_image_id( 'edit' ) ),
			'menu_order'        => (int) $v->get_menu_order( 'edit' ),
			'meta'              => Woo_Products::meta( $v ),
			'stock'             => Woo_Products::stock( $v ),
		);
	}

	/**
	 * Find an existing variation: by SKU first, then by attribute combination.
	 *
	 * @param string $sku      SKU.
	 * @param array  $attrs    Attributes.
	 * @param array  $by_sku   lower-case SKU => ID.
	 * @param array  $by_combo combination => IDs.
	 * @param int[]  $kept     IDs already used.
	 * @return int 0 when none.
	 */
	private static function match( $sku, array $attrs, array $by_sku, array $by_combo, array $kept ) {
		if ( '' !== $sku && isset( $by_sku[ strtolower( $sku ) ] ) && ! in_array( $by_sku[ strtolower( $sku ) ], $kept, true ) ) {
			return (int) $by_sku[ strtolower( $sku ) ];
		}
		foreach ( isset( $by_combo[ self::combo( $attrs ) ] ) ? $by_combo[ self::combo( $attrs ) ] : array() as $cand ) {
			if ( ! in_array( $cand, $kept, true ) ) {
				return (int) $cand;
			}
		}
		return 0;
	}

	/**
	 * Apply the variations of an item to a saved parent.
	 *
	 * @param \WC_Product $parent    Saved variable product.
	 * @param array       $list      Variations from the item.
	 * @param bool        $stock     Overwrite stock on existing variations.
	 * @return string Summary.
	 */
	public static function import( \WC_Product $parent, array $list, $stock ) {
		$by_sku   = array();
		$by_combo = array();
		$objs     = array();
		foreach ( self::ids( $parent->get_id() ) as $id ) {
			$v = wc_get_product( $id );
			if ( ! $v instanceof \WC_Product_Variation ) {
				continue;
			}
			$objs[ $id ] = $v;
			$sku         = strtolower( (string) $v->get_sku( 'edit' ) );
			if ( '' !== $sku ) {
				$by_sku[ $sku ] = $id;
			}
			$by_combo[ self::combo( (array) $v->get_attributes( 'edit' ) ) ][] = $id;
		}
		// Variations in the Trash are matched too (newest first), so that old order lines keep their variation.
		$t_sku   = array();
		$t_combo = array();
		foreach ( array_reverse( self::ids( $parent->get_id(), array( 'trash' ) ) ) as $id ) {
			$v = wc_get_product( $id );
			if ( ! $v instanceof \WC_Product_Variation ) {
				continue;
			}
			$objs[ $id ] = $v;
			$sku         = strtolower( (string) $v->get_sku( 'edit' ) );
			if ( '' !== $sku && ! isset( $t_sku[ $sku ] ) ) {
				$t_sku[ $sku ] = $id;
			}
			$t_combo[ self::combo( (array) $v->get_attributes( 'edit' ) ) ][] = $id;
		}
		$kept    = array();
		$new     = 0;
		$revived = 0;
		foreach ( $list as $vd ) {
			if ( ! is_array( $vd ) ) {
				continue;
			}
			$attrs = isset( $vd['attributes'] ) && is_array( $vd['attributes'] ) ? array_map( 'strval', $vd['attributes'] ) : array();
			$sku   = isset( $vd['sku'] ) && is_string( $vd['sku'] ) ? $vd['sku'] : '';
			$vid   = self::match( $sku, $attrs, $by_sku, $by_combo, $kept );
			if ( ! $vid ) {
				$vid = self::match( $sku, $attrs, $t_sku, $t_combo, $kept );
				$revived += $vid ? 1 : 0;
			}
			$is_new = ! $vid;
			$v      = $vid ? $objs[ $vid ] : new \WC_Product_Variation();
			try {
				if ( $is_new ) {
					$v->set_parent_id( $parent->get_id() );
				}
				self::apply( $v, $vd, $attrs, $is_new || $stock );
				$v->save();
			} catch ( \Exception $e ) {
				/* translators: 1: variation SKU or attributes, 2: error. */
				Woo_Refs::note( sprintf( __( 'The variation "%1$s" could not be saved. WooCommerce said: %2$s', 'avesta-sync' ), '' !== $sku ? $sku : implode( '/', $attrs ), wp_strip_all_tags( $e->getMessage() ) ) );
				continue;
			}
			$kept[] = $v->get_id();
			$new   += $is_new ? 1 : 0;
		}
		$trashed = 0;
		foreach ( $objs as $vid => $v ) {
			if ( ! in_array( $vid, $kept, true ) && 'trash' !== $v->get_status( 'edit' ) ) {
				$v->delete( false );
				++$trashed;
			}
		}
		if ( $revived ) {
			/* translators: %d: number of variations. */
			Woo_Refs::note( sprintf( _n( '%d variation was restored from the Trash', '%d variations were restored from the Trash', $revived, 'avesta-sync' ), $revived ) );
		}
		\WC_Product_Variable::sync( $parent->get_id() );
		wc_delete_product_transients( $parent->get_id() );
		/* translators: 1: variations total, 2: created, 3: moved to Trash. */
		return sprintf( _n( '%1$d variation (%2$d new, %3$d moved to Trash).', '%1$d variations (%2$d new, %3$d moved to Trash).', count( $kept ), 'avesta-sync' ), count( $kept ), $new, $trashed );
	}

	/**
	 * Set the properties of a variation from item data.
	 *
	 * @param \WC_Product_Variation $v      Variation.
	 * @param array                 $d      Data.
	 * @param array                 $attrs  Attributes.
	 * @param bool                  $stock  Write stock quantity and status.
	 */
	private static function apply( \WC_Product_Variation $v, array $d, array $attrs, $stock ) {
		$str = static function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) ? (string) $d[ $k ] : '';
		};
		$v->set_sku( $str( 'sku' ) );
		$v->set_attributes( $attrs );
		$status = $str( 'status' );
		$v->set_status( in_array( $status, self::STATUSES, true ) ? $status : 'publish' );
		$v->set_description( (string) Content::untok( $str( 'description' ) ) );
		$v->set_regular_price( $str( 'regular_price' ) );
		$v->set_sale_price( $str( 'sale_price' ) );
		$v->set_date_on_sale_from( isset( $d['date_on_sale_from'] ) && is_numeric( $d['date_on_sale_from'] ) ? (int) $d['date_on_sale_from'] : null );
		$v->set_date_on_sale_to( isset( $d['date_on_sale_to'] ) && is_numeric( $d['date_on_sale_to'] ) ? (int) $d['date_on_sale_to'] : null );
		$v->set_tax_status( '' !== $str( 'tax_status' ) ? $str( 'tax_status' ) : 'taxable' );
		$v->set_tax_class( $str( 'tax_class' ) );
		$v->set_weight( $str( 'weight' ) );
		$v->set_length( $str( 'length' ) );
		$v->set_width( $str( 'width' ) );
		$v->set_height( $str( 'height' ) );
		$class = $str( 'shipping_class' );
		$ids   = '' !== $class ? Woo_Refs::term_ids( 'product_shipping_class', array( $class ), __( 'shipping classes', 'avesta-sync' ) ) : array( 'ids' => array() );
		$v->set_shipping_class_id( $ids['ids'] ? $ids['ids'][0] : 0 );
		$v->set_virtual( ! empty( $d['virtual'] ) );
		$v->set_downloadable( ! empty( $d['downloadable'] ) );
		Woo_Product_Import::set_downloads( $v, isset( $d['downloads'] ) && is_array( $d['downloads'] ) ? $d['downloads'] : array() );
		$v->set_download_limit( isset( $d['download_limit'] ) ? (int) $d['download_limit'] : -1 );
		$v->set_download_expiry( isset( $d['download_expiry'] ) ? (int) $d['download_expiry'] : -1 );
		$v->set_menu_order( isset( $d['menu_order'] ) ? (int) $d['menu_order'] : 0 );
		$file = $str( 'image' );
		$att  = Woo_Refs::attachment_id( $file );
		if ( $att || '' === $file ) {
			$v->set_image_id( $att );
		} else {
			/* translators: %s: file path. */
			Woo_Refs::note( sprintf( __( 'Image "%s" was not found on the receiving site. Sync the media library first', 'avesta-sync' ), $file ) );
		}
		Woo_Product_Import::set_meta( $v, isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array() );
		if ( $stock ) {
			Woo_Product_Import::set_stock( $v, isset( $d['stock'] ) && is_array( $d['stock'] ) ? $d['stock'] : array(), true );
		}
	}
}
