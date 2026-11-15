<?php
/**
 * WooCommerce shipping zones, locations, methods and instance settings as one replaceable set.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Data of wc_set:shipping_zones: {zones:[{name,rest_of_world,order,locations,methods}]}. No IDs.
 * Methods other than the core three whose settings look like credentials are skipped on both
 * export and import, and are never removed from the target.
 */
class Woo_Shipping {

	const CORE         = array( 'flat_rate', 'free_shipping', 'local_pickup' );
	const SECRET_REGEX = '/(key|secret|token|passw|api|licen|auth|credential|signature|client_?id|account|username|user_?id|app_?id|site_?id)/i';

	/**
	 * Method ids that may be synced: the core three, plus ids added by the filter (additive only).
	 *
	 * @return string[]
	 */
	private static function allowed() {
		$extra = apply_filters( 'avesta_sync_wc_shipping_methods', array() );
		return array_values( array_unique( array_merge( self::CORE, is_array( $extra ) ? array_map( 'sanitize_key', array_map( 'strval', $extra ) ) : array() ) ) );
	}

	/**
	 * Is a method left alone (never exported, created, updated or removed)? Methods outside the allowed
	 * ids, and non-core methods whose settings look like credentials (live-rate carriers).
	 *
	 * @param string $method_id Method id.
	 * @param array  $settings  Instance settings (keys are inspected).
	 * @return bool
	 */
	private static function flagged( $method_id, array $settings ) {
		if ( ! in_array( $method_id, self::allowed(), true ) ) {
			return true;
		}
		if ( in_array( $method_id, self::CORE, true ) ) {
			return false;
		}
		foreach ( array_keys( $settings ) as $k ) {
			if ( preg_match( self::SECRET_REGEX, (string) $k ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Instance settings of a method (shipping class cost keys are exported by class slug).
	 *
	 * @param \WC_Shipping_Method $m Method.
	 * @return array
	 */
	private static function settings_of( $m ) {
		$raw = get_option( $m->get_instance_option_key(), array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $k => $v ) {
			$k = (string) $k;
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			if ( preg_match( '/^class_cost_(\d+)$/', $k, $mt ) ) {
				$slug = Woo_Products::class_slug( (int) $mt[1] );
				if ( '' === $slug ) {
					continue;
				}
				$k = 'class_cost@' . $slug;
			}
			$out[ $k ] = Content::tok( (string) $v );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Zones of this site (zone 0 first), as objects.
	 *
	 * @return \WC_Shipping_Zone[]
	 */
	private static function zones() {
		$zones = array( new \WC_Shipping_Zone( 0 ) );
		foreach ( \WC_Data_Store::load( 'shipping-zone' )->get_zones() as $row ) {
			$zones[] = new \WC_Shipping_Zone( (int) $row->zone_id );
		}
		return $zones;
	}

	/**
	 * Normalised set from this site.
	 *
	 * @return array
	 */
	public static function export() {
		$out = array();
		foreach ( self::zones() as $zone ) {
			$locs = array();
			foreach ( $zone->get_zone_locations( 'edit' ) as $l ) {
				$locs[] = array(
					'type' => (string) $l->type,
					'code' => (string) $l->code,
				);
			}
			usort(
				$locs,
				static function ( $a, $b ) {
					return strcmp( $a['type'] . ':' . $a['code'], $b['type'] . ':' . $b['code'] );
				}
			);
			$methods = array();
			foreach ( $zone->get_shipping_methods( false, 'admin' ) as $m ) {
				$settings = self::settings_of( $m );
				if ( self::flagged( (string) $m->id, $settings ) ) {
					$zname = $zone->get_id() ? $zone->get_zone_name( 'edit' ) : __( 'Locations not covered by your other zones', 'avesta-sync' );
					Woo::export_note(
						in_array( (string) $m->id, self::allowed(), true )
							/* translators: 1: shipping method name, 2: shipping zone name. */
							? sprintf( __( 'Skipped shipping method "%1$s" in zone "%2$s": its settings look like they hold credentials.', 'avesta-sync' ), $m->get_method_title(), $zname )
							/* translators: 1: shipping method name, 2: shipping zone name. */
							: sprintf( __( 'Skipped shipping method "%1$s" in zone "%2$s": only flat rate, free shipping and local pickup are synced.', 'avesta-sync' ), $m->get_method_title(), $zname )
					);
					continue;
				}
				$methods[] = array(
					'method_id' => (string) $m->id,
					'enabled'   => 'yes' === $m->enabled,
					'settings'  => $settings,
				);
			}
			$out[] = array(
				'name'          => $zone->get_id() ? Content::tok( $zone->get_zone_name( 'edit' ) ) : '',
				'rest_of_world' => ! $zone->get_id(),
				'order'         => (int) $zone->get_zone_order( 'edit' ),
				'locations'     => $locs,
				'methods'       => $methods,
			);
		}
		return array( 'zones' => $out );
	}

	/**
	 * Methods of a zone, split into synced and protected (credential-like) ones.
	 *
	 * @param \WC_Shipping_Zone $zone Zone.
	 * @return array {pool: method_id => objects[], flagged: int}
	 */
	private static function pool( \WC_Shipping_Zone $zone ) {
		$pool    = array();
		$flagged = 0;
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $m ) {
			if ( self::flagged( (string) $m->id, self::settings_of( $m ) ) ) {
				++$flagged;
			} else {
				$pool[ (string) $m->id ][] = $m;
			}
		}
		return array(
			'pool'    => $pool,
			'flagged' => $flagged,
		);
	}

	/**
	 * Replace the zones by the set (the caller has already saved a backup).
	 *
	 * @param array $d Item data.
	 * @return string|\WP_Error
	 */
	public static function import( array $d ) {
		global $wpdb;
		$zones = isset( $d['zones'] ) && is_array( $d['zones'] ) ? array_slice( $d['zones'], 0, 200 ) : array();
		$known = array_keys( WC()->shipping()->get_shipping_method_class_names() );
		$old   = array();
		foreach ( self::zones() as $z ) {
			if ( $z->get_id() ) {
				$old[ $z->get_id() ] = $z;
			}
		}
		$used  = array();
		$nz    = 0;
		$nm    = 0;
		foreach ( $zones as $zd ) {
			if ( ! is_array( $zd ) ) {
				continue;
			}
			$zone = null;
			if ( ! empty( $zd['rest_of_world'] ) ) {
				$zone = new \WC_Shipping_Zone( 0 );
			} else {
				$name = sanitize_text_field( (string) Content::untok( isset( $zd['name'] ) && is_string( $zd['name'] ) ? $zd['name'] : '' ) );
				foreach ( $old as $zid => $cand ) {
					if ( ! isset( $used[ $zid ] ) && $cand->get_zone_name( 'edit' ) === $name ) {
						$zone = $cand;
						break;
					}
				}
				$zone = $zone ? $zone : new \WC_Shipping_Zone();
				$zone->set_zone_name( $name );
				$zone->set_zone_order( isset( $zd['order'] ) ? (int) $zd['order'] : 0 );
				$zone->clear_locations();
				foreach ( isset( $zd['locations'] ) && is_array( $zd['locations'] ) ? $zd['locations'] : array() as $l ) {
					if ( is_array( $l ) && isset( $l['type'], $l['code'] ) && is_string( $l['type'] ) && $zone->is_valid_location_type( $l['type'] ) ) {
						$zone->add_location( sanitize_text_field( (string) $l['code'] ), $l['type'] );
					}
				}
				$zone->save();
				$used[ $zone->get_id() ] = true;
			}
			++$nz;
			$p   = self::pool( $zone );
			$pos = 0;
			foreach ( isset( $zd['methods'] ) && is_array( $zd['methods'] ) ? $zd['methods'] : array() as $md ) {
				$mid = is_array( $md ) && isset( $md['method_id'] ) && is_string( $md['method_id'] ) ? sanitize_key( $md['method_id'] ) : '';
				if ( '' !== $mid && self::flagged( $mid, array() ) ) {
					/* translators: %s: shipping method id. */
					Woo_Refs::note( sprintf( __( 'Skipped shipping method "%s": only flat rate, free shipping and local pickup are synced', 'avesta-sync' ), $mid ) );
					continue;
				}
				if ( '' === $mid || ! in_array( $mid, $known, true ) ) {
					/* translators: %s: shipping method id. */
					Woo_Refs::note( sprintf( __( 'Shipping method "%s" is not available on the receiving site', 'avesta-sync' ), $mid ) );
					continue;
				}
				$settings = array();
				foreach ( isset( $md['settings'] ) && is_array( $md['settings'] ) ? $md['settings'] : array() as $k => $v ) {
					$k = (string) $k;
					if ( 0 === strpos( $k, 'class_cost@' ) ) {
						$tid = Woo_Refs::term_id( 'product_shipping_class', substr( $k, 11 ) );
						if ( ! $tid ) {
							/* translators: %s: shipping class slug. */
							Woo_Refs::note( sprintf( __( 'The shipping class "%s" does not exist on the receiving site, so its cost was skipped', 'avesta-sync' ), substr( $k, 11 ) ) );
							continue;
						}
						$k = 'class_cost_' . $tid;
					}
					if ( is_scalar( $v ) ) {
						$settings[ $k ] = sanitize_text_field( (string) Content::untok( (string) $v ) );
					}
				}
				if ( self::flagged( $mid, $settings ) ) {
					/* translators: %s: shipping method id. */
					Woo_Refs::note( sprintf( __( 'Skipped shipping method "%s": only flat rate, free shipping and local pickup are synced', 'avesta-sync' ), $mid ) );
					continue;
				}
				$m = ! empty( $p['pool'][ $mid ] ) ? array_shift( $p['pool'][ $mid ] ) : null;
				if ( ! $m ) {
					$m = \WC_Shipping_Zones::get_shipping_method( (int) $zone->add_shipping_method( $mid ) );
				}
				if ( ! $m ) {
					continue;
				}
				update_option( $m->get_instance_option_key(), $settings );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no WooCommerce API toggles a method or sets its order.
					$wpdb->prefix . 'woocommerce_shipping_zone_methods',
					array(
						'is_enabled'   => ! empty( $md['enabled'] ) ? 1 : 0,
						'method_order' => ++$pos,
					),
					array( 'instance_id' => (int) $m->instance_id ),
					array( '%d', '%d' ),
					array( '%d' )
				);
				++$nm;
			}
			foreach ( $p['pool'] as $list ) {
				foreach ( $list as $m ) {
					$zone->delete_shipping_method( (int) $m->instance_id );
				}
			}
		}
		foreach ( $old as $zid => $zone ) {
			if ( ! isset( $used[ $zid ] ) && ! self::pool( $zone )['flagged'] ) {
				$zone->delete();
			}
		}
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		/* translators: %d: number of shipping zones. */
		$n_zones   = sprintf( _n( '%d zone', '%d zones', $nz, 'avesta-sync' ), $nz );
		/* translators: %d: number of shipping methods. */
		$n_methods = sprintf( _n( '%d method', '%d methods', $nm, 'avesta-sync' ), $nm );
		/* translators: 1: number of zones (e.g. "2 zones"), 2: number of methods (e.g. "3 methods"). */
		return sprintf( __( 'Replaced: %1$s, %2$s.', 'avesta-sync' ), $n_zones, $n_methods );
	}
}
