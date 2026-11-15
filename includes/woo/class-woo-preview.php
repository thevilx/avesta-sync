<?php
/**
 * WooCommerce diff preview shaping (preview only: hashes always come from the real data).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Readable preview JSON for WooCommerce items.
 */
class Woo_Preview {

	/**
	 * Data for the diff preview only (hashes are computed from the real data): readable key order, stock under
	 * a key that says compare ignores it, and variations as an object keyed by a readable heading.
	 *
	 * @param array $data Item data.
	 * @return array
	 */
	public static function data( array $data ) {
		$is_product = isset( $data['type'] ) && 'wc_product' === $data['type'];
		$data       = self::dates( $data );
		if ( array_key_exists( 'stock', $data ) ) {
			$data['stock_not_compared'] = $data['stock'];
			unset( $data['stock'] );
		}
		if ( ! $is_product ) {
			return self::order_keys( $data, array() );
		}
		if ( isset( $data['variations'] ) && is_array( $data['variations'] ) ) {
			$vars = array();
			foreach ( $data['variations'] as $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$v = self::dates( $v );
				if ( array_key_exists( 'stock', $v ) ) {
					$v['stock_not_compared'] = $v['stock'];
					unset( $v['stock'] );
				}
				$label = self::variation_label( $v );
				$base  = $label;
				for ( $n = 2; isset( $vars[ $label ] ); $n++ ) {
					$label = $base . ' #' . $n;
				}
				$vars[ $label ] = self::order_keys( array( 'variation' => $label ) + $v, array( 'variation', 'sku', 'attributes', 'status', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'description', 'image' ) );
			}
			$data['variations'] = $vars;
		}
		return self::order_keys( $data, array( 'name', 'sku', 'ptype', 'status', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'description', 'short_description', 'categories', 'tags', 'brands', 'attributes', 'default_attributes', 'image', 'gallery' ) );
	}

	/**
	 * Timestamps as ISO 8601 UTC strings (the same on every site, readable in a diff).
	 *
	 * @param array $row Data.
	 * @return array
	 */
	private static function dates( array $row ) {
		foreach ( array( 'date_created', 'date_on_sale_from', 'date_on_sale_to', 'date_expires' ) as $k ) {
			if ( isset( $row[ $k ] ) && is_numeric( $row[ $k ] ) ) {
				$row[ $k ] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $row[ $k ] );
			}
		}
		return $row;
	}

	/**
	 * Keys in a given order first, the rest after them, "stock_not_compared" last.
	 *
	 * @param array    $row   Data.
	 * @param string[] $first Leading keys.
	 * @return array
	 */
	private static function order_keys( array $row, array $first ) {
		$out = array();
		foreach ( $first as $k ) {
			if ( array_key_exists( $k, $row ) ) {
				$out[ $k ] = $row[ $k ];
			}
		}
		foreach ( $row as $k => $v ) {
			if ( ! array_key_exists( $k, $out ) && 'stock_not_compared' !== $k ) {
				$out[ $k ] = $v;
			}
		}
		if ( array_key_exists( 'stock_not_compared', $row ) ) {
			$out['stock_not_compared'] = $row['stock_not_compared'];
		}
		return $out;
	}

	/**
	 * Readable heading of a variation: "SKU x", or "Color: Blue, Size: L".
	 *
	 * @param array $v Variation data.
	 * @return string
	 */
	private static function variation_label( array $v ) {
		if ( ! empty( $v['sku'] ) && is_string( $v['sku'] ) ) {
			/* translators: %s: variation SKU. */
			return sprintf( __( 'SKU %s', 'avesta-sync' ), $v['sku'] );
		}
		$parts = array();
		foreach ( isset( $v['attributes'] ) && is_array( $v['attributes'] ) ? $v['attributes'] : array() as $k => $val ) {
			$k    = (string) $k;
			$name = 0 === strpos( $k, 'pa_' ) && function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $k ) : $k;
			$term = 0 === strpos( $k, 'pa_' ) && '' !== (string) $val ? get_term_by( 'slug', (string) $val, $k ) : null;
			$text = '' === (string) $val ? __( 'Any', 'avesta-sync' ) : ( $term && ! is_wp_error( $term ) ? $term->name : (string) $val );
			$parts[] = $name . ': ' . $text;
		}
		return $parts ? implode( ', ', $parts ) : __( 'Variation', 'avesta-sync' );
	}
}
