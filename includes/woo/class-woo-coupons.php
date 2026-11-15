<?php
/**
 * WooCommerce coupons (product and category restrictions as portable refs).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Item wc_coupon:<code>. Usage counters and "used by" are never exported or written on update.
 */
class Woo_Coupons {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );
	const TYPES    = array( 'percent', 'fixed_cart', 'fixed_product' );

	/**
	 * Export coupons.
	 *
	 * @param array|null $keys Keys filter.
	 * @return array List of {key,data,label,ord}.
	 */
	public static function export( $keys ) {
		$want = Woo::keys_for( $keys, 'wc_coupon:' );
		$out  = array();
		if ( array() === $want ) {
			return $out;
		}
		$ids = get_posts(
			array(
				'post_type'        => 'shop_coupon',
				'post_status'      => self::STATUSES,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$c = new \WC_Coupon( (int) $id );
			if ( ! $c->get_id() || '' === $c->get_code( 'edit' ) ) {
				continue;
			}
			if ( null !== $want && ! in_array( wc_format_coupon_code( $c->get_code( 'edit' ) ), $want, true ) ) {
				continue;
			}
			$out[] = self::item( $c );
		}
		return $out;
	}

	/**
	 * Item for one coupon.
	 *
	 * @param \WC_Coupon $c Coupon.
	 * @return array
	 */
	private static function item( \WC_Coupon $c ) {
		$code  = wc_format_coupon_code( $c->get_code( 'edit' ) );
		$prods = Woo_Refs::product_refs( $c->get_product_ids( 'edit' ) );
		$excl  = Woo_Refs::product_refs( $c->get_excluded_product_ids( 'edit' ) );
		$meta  = array();
		foreach ( $c->get_meta_data() as $m ) {
			$k = (string) $m->key;
			if ( self::public_meta_key( $c, $k ) && ! is_object( $m->value ) ) {
				$meta[ $k ][] = Content::tok( $m->value );
			}
		}
		ksort( $meta );
		$emails = array_map( 'strval', (array) $c->get_email_restrictions( 'edit' ) );
		sort( $emails );
		return array(
			'key'   => 'wc_coupon:' . $code,
			/* translators: %s: coupon code. */
			'label' => sprintf( __( 'Coupon: %s', 'avesta-sync' ), $code ),
			'ord'   => 30,
			'data'  => array(
				'type'                       => 'wc_coupon',
				'code'                       => $code,
				'status'                     => $c->get_status( 'edit' ),
				'description'                => Content::tok( $c->get_description( 'edit' ) ),
				'discount_type'              => $c->get_discount_type( 'edit' ),
				'amount'                     => (string) $c->get_amount( 'edit' ),
				'date_expires'               => Woo_Products::ts( $c->get_date_expires( 'edit' ) ),
				'free_shipping'              => (bool) $c->get_free_shipping( 'edit' ),
				'individual_use'             => (bool) $c->get_individual_use( 'edit' ),
				'exclude_sale_items'         => (bool) $c->get_exclude_sale_items( 'edit' ),
				'minimum_amount'             => (string) $c->get_minimum_amount( 'edit' ),
				'maximum_amount'             => (string) $c->get_maximum_amount( 'edit' ),
				'usage_limit'                => (int) $c->get_usage_limit( 'edit' ),
				'usage_limit_per_user'       => (int) $c->get_usage_limit_per_user( 'edit' ),
				'limit_usage_to_x_items'     => null === $c->get_limit_usage_to_x_items( 'edit' ) ? null : (int) $c->get_limit_usage_to_x_items( 'edit' ),
				'products'                   => $prods,
				'excluded_products'          => $excl,
				'categories'                 => self::cat_slugs( $c->get_product_categories( 'edit' ) ),
				'excluded_categories'        => self::cat_slugs( $c->get_excluded_product_categories( 'edit' ) ),
				'email_restrictions'         => $emails,
				'meta'                       => $meta,
			),
		);
	}

	/**
	 * Does a meta key belong to the synced custom meta? Internal WooCommerce keys (usage_count,
	 * customer_email, discount_type, ...) never do: add_meta_data() would route them to setters.
	 *
	 * @param \WC_Coupon $c Coupon.
	 * @param string     $k Key.
	 * @return bool
	 */
	private static function public_meta_key( \WC_Coupon $c, $k ) {
		$deny = array( 'usage_count', 'used_by', '_used_by', 'date_expires', 'customer_email' );
		return '' !== $k && 0 !== strpos( $k, '_' ) && 0 !== strpos( $k, 'wp_' ) && ! in_array( $k, $deny, true ) && ! in_array( $k, $c->get_data_store()->get_internal_meta_keys(), true );
	}

	/**
	 * Category IDs to slugs.
	 *
	 * @param int[] $ids IDs.
	 * @return string[]
	 */
	private static function cat_slugs( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$t = get_term( (int) $id, 'product_cat' );
			if ( $t instanceof \WP_Term ) {
				$out[] = (string) $t->slug;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Locate a coupon by code (trashed ones included).
	 *
	 * @param string $code Code.
	 * @return \WC_Coupon|null
	 */
	private static function find( $code ) {
		$id = (int) wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			$ids = get_posts(
				array(
					'post_type'        => 'shop_coupon',
					'post_status'      => 'trash',
					'title'            => $code,
					'numberposts'      => 1,
					'fields'           => 'ids',
				)
			);
			$id  = $ids ? (int) $ids[0] : 0;
		}
		return $id ? new \WC_Coupon( $id ) : null;
	}

	/**
	 * Import a coupon.
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import( array $d, $run ) {
		$code = isset( $d['code'] ) && is_string( $d['code'] ) ? wc_format_coupon_code( $d['code'] ) : '';
		if ( '' === $code ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'The coupon has no code.', 'avesta-sync' ) );
		}
		$c = self::find( $code );
		if ( $c ) {
			$old = self::item( $c );
			if ( ! Backup::save_json( $old['key'], $old['data'], $run ) ) {
				return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was changed.', 'avesta-sync' ) );
			}
		}
		$new = ! $c;
		$c   = $c ? $c : new \WC_Coupon();
		$str = static function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) ? (string) $d[ $k ] : '';
		};
		$int = static function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && is_numeric( $d[ $k ] ) ? (int) $d[ $k ] : 0;
		};
		try {
			$c->set_code( $code );
			$status = $str( 'status' );
			$c->set_status( in_array( $status, self::STATUSES, true ) ? $status : 'draft' );
			$c->set_description( (string) Content::untok( $str( 'description' ) ) );
			$type = $str( 'discount_type' );
			$c->set_discount_type( in_array( $type, self::TYPES, true ) ? $type : 'fixed_cart' );
			$c->set_amount( $str( 'amount' ) );
			$c->set_date_expires( isset( $d['date_expires'] ) && is_numeric( $d['date_expires'] ) ? (int) $d['date_expires'] : null );
			$c->set_free_shipping( ! empty( $d['free_shipping'] ) );
			$c->set_individual_use( ! empty( $d['individual_use'] ) );
			$c->set_exclude_sale_items( ! empty( $d['exclude_sale_items'] ) );
			$c->set_minimum_amount( $str( 'minimum_amount' ) );
			$c->set_maximum_amount( $str( 'maximum_amount' ) );
			$c->set_usage_limit( $int( 'usage_limit' ) );
			$c->set_usage_limit_per_user( $int( 'usage_limit_per_user' ) );
			$c->set_limit_usage_to_x_items( isset( $d['limit_usage_to_x_items'] ) && is_numeric( $d['limit_usage_to_x_items'] ) ? (int) $d['limit_usage_to_x_items'] : null );
			$p = Woo_Refs::product_ids( isset( $d['products'] ) && is_array( $d['products'] ) ? $d['products'] : array(), __( 'coupon products', 'avesta-sync' ) );
			$c->set_product_ids( $p['ids'] );
			$p = Woo_Refs::product_ids( isset( $d['excluded_products'] ) && is_array( $d['excluded_products'] ) ? $d['excluded_products'] : array(), __( 'excluded products', 'avesta-sync' ) );
			$c->set_excluded_product_ids( $p['ids'] );
			$r = Woo_Refs::term_ids( 'product_cat', isset( $d['categories'] ) && is_array( $d['categories'] ) ? $d['categories'] : array(), __( 'coupon categories', 'avesta-sync' ) );
			$c->set_product_categories( $r['ids'] );
			$r = Woo_Refs::term_ids( 'product_cat', isset( $d['excluded_categories'] ) && is_array( $d['excluded_categories'] ) ? $d['excluded_categories'] : array(), __( 'excluded categories', 'avesta-sync' ) );
			$c->set_excluded_product_categories( $r['ids'] );
			$c->set_email_restrictions( array_map( 'sanitize_email', array_map( 'strval', isset( $d['email_restrictions'] ) && is_array( $d['email_restrictions'] ) ? $d['email_restrictions'] : array() ) ) );
			$meta = isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array();
			foreach ( $c->get_meta_data() as $m ) {
				$k = (string) $m->key;
				if ( self::public_meta_key( $c, $k ) && ! isset( $meta[ $k ] ) ) {
					$c->delete_meta_data( $k );
				}
			}
			foreach ( $meta as $k => $vals ) {
				if ( is_array( $vals ) && self::public_meta_key( $c, (string) $k ) ) {
					$c->delete_meta_data( (string) $k );
					foreach ( $vals as $val ) {
						$c->add_meta_data( (string) $k, Content::untok( $val ), false );
					}
				}
			}
			$c->save();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'avesta_sync_wc', $e->getMessage() );
		}
		return $new ? __( 'Created.', 'avesta-sync' ) : __( 'Updated.', 'avesta-sync' );
	}

	/**
	 * Trash a coupon after a JSON backup.
	 *
	 * @param string $code Coupon code.
	 * @param string $key  Item key.
	 * @param string $run  Run id.
	 * @return string|\WP_Error
	 */
	public static function delete( $code, $key, $run ) {
		$id = (int) wc_get_coupon_id_by_code( wc_format_coupon_code( $code ) );
		if ( ! $id ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'It was not found.', 'avesta-sync' ) );
		}
		$c    = new \WC_Coupon( $id );
		$item = self::item( $c );
		if ( ! Backup::save_json( $key, $item['data'], $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was deleted.', 'avesta-sync' ) );
		}
		return $c->delete( false ) ? __( 'Moved to Trash.', 'avesta-sync' ) : new \WP_Error( 'avesta_sync_wc', __( 'It could not be moved to the Trash.', 'avesta-sync' ) );
	}
}
