<?php
/**
 * WooCommerce tax classes and tax rates as one replaceable set.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Data of wc_set:tax_rates: {classes:[{name,slug}], rates:[...]} without IDs.
 */
class Woo_Tax {

	/**
	 * Normalised set from this site.
	 *
	 * @return array
	 */
	public static function export() {
		$classes = array();
		$slugs   = array( '' );
		foreach ( \WC_Tax::get_tax_rate_classes() as $c ) {
			$classes[] = array(
				'name' => (string) $c->name,
				'slug' => (string) $c->slug,
			);
			$slugs[]   = (string) $c->slug;
		}
		usort(
			$classes,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);
		$rates = array();
		foreach ( $slugs as $slug ) {
			foreach ( (array) \WC_Tax::get_rates_for_tax_class( $slug ) as $r ) {
				$post = isset( $r->postcode ) ? array_values( array_map( 'strval', (array) $r->postcode ) ) : array();
				$city = isset( $r->city ) ? array_values( array_map( 'strval', (array) $r->city ) ) : array();
				sort( $post );
				sort( $city );
				$rates[] = array(
					'class'     => $slug,
					'country'   => (string) $r->tax_rate_country,
					'state'     => (string) $r->tax_rate_state,
					'rate'      => (string) $r->tax_rate,
					'name'      => Content::tok( (string) $r->tax_rate_name ),
					'priority'  => (int) $r->tax_rate_priority,
					'compound'  => (int) $r->tax_rate_compound,
					'shipping'  => (int) $r->tax_rate_shipping,
					'order'     => (int) $r->tax_rate_order,
					'postcodes' => $post,
					'cities'    => $city,
				);
			}
		}
		usort(
			$rates,
			static function ( $a, $b ) {
				return strcmp(
					$a['class'] . '|' . sprintf( '%08d', $a['order'] ) . '|' . $a['country'] . '|' . $a['state'] . '|' . sprintf( '%04d', $a['priority'] ) . '|' . $a['name'] . '|' . $a['rate'] . '|' . implode( ';', $a['postcodes'] ),
					$b['class'] . '|' . sprintf( '%08d', $b['order'] ) . '|' . $b['country'] . '|' . $b['state'] . '|' . sprintf( '%04d', $b['priority'] ) . '|' . $b['name'] . '|' . $b['rate'] . '|' . implode( ';', $b['postcodes'] )
				);
			}
		);
		return array(
			'classes' => $classes,
			'rates'   => $rates,
		);
	}

	/**
	 * Does any product or variation here use a tax class?
	 *
	 * @param string $slug Class slug.
	 * @return bool
	 */
	private static function class_in_use( $slug ) {
		$q = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array_merge( Woo_Refs::STATUSES, array( 'private' ) ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_tax_class', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (bool) $q->posts;
	}

	/**
	 * Replace all tax classes and rates by the set (the caller has already saved a backup).
	 *
	 * @param array $d Item data.
	 * @return string|\WP_Error
	 */
	public static function import( array $d ) {
		$classes = array();
		foreach ( isset( $d['classes'] ) && is_array( $d['classes'] ) ? $d['classes'] : array() as $c ) {
			if ( is_array( $c ) && ! empty( $c['slug'] ) && is_string( $c['slug'] ) ) {
				$classes[ sanitize_title( $c['slug'] ) ] = sanitize_text_field( isset( $c['name'] ) && is_string( $c['name'] ) ? $c['name'] : $c['slug'] );
			}
		}
		$rates = array();
		foreach ( isset( $d['rates'] ) && is_array( $d['rates'] ) ? $d['rates'] : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$class = isset( $r['class'] ) && is_string( $r['class'] ) ? sanitize_title( $r['class'] ) : '';
			if ( '' !== $class && ! isset( $classes[ $class ] ) ) {
				return new \WP_Error( 'avesta_sync_wc', __( 'A tax rate uses a tax class that is not part of the settings being synced.', 'avesta-sync' ) );
			}
			$rates[] = $r;
		}
		// Remove the rates and the classes that the source does not have.
		foreach ( array_merge( array( '' ), wp_list_pluck( \WC_Tax::get_tax_rate_classes(), 'slug' ) ) as $slug ) {
			foreach ( array_keys( (array) \WC_Tax::get_rates_for_tax_class( $slug ) ) as $rid ) {
				\WC_Tax::_delete_tax_rate( (int) $rid );
			}
		}
		$kept = array();
		foreach ( \WC_Tax::get_tax_rate_classes() as $c ) {
			if ( ! isset( $classes[ $c->slug ] ) ) {
				if ( self::class_in_use( (string) $c->slug ) ) {
					$kept[] = (string) $c->slug; // Products here use it: keep the class (its rates are still replaced).
				} else {
					\WC_Tax::delete_tax_class_by( 'slug', $c->slug );
				}
			}
		}
		foreach ( $classes as $slug => $name ) {
			if ( ! in_array( $slug, \WC_Tax::get_tax_class_slugs(), true ) ) {
				\WC_Tax::create_tax_class( $name, $slug );
			}
		}
		foreach ( $rates as $r ) {
			$id = \WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_class'    => isset( $r['class'] ) && is_string( $r['class'] ) ? sanitize_title( $r['class'] ) : '',
					'tax_rate_country'  => isset( $r['country'] ) && is_string( $r['country'] ) ? $r['country'] : '',
					'tax_rate_state'    => isset( $r['state'] ) && is_string( $r['state'] ) ? $r['state'] : '',
					'tax_rate'          => isset( $r['rate'] ) && is_scalar( $r['rate'] ) ? (string) $r['rate'] : '',
					'tax_rate_name'     => sanitize_text_field( (string) Content::untok( isset( $r['name'] ) && is_string( $r['name'] ) ? $r['name'] : '' ) ),
					'tax_rate_priority' => isset( $r['priority'] ) ? (int) $r['priority'] : 1,
					'tax_rate_compound' => ! empty( $r['compound'] ) ? 1 : 0,
					'tax_rate_shipping' => ! empty( $r['shipping'] ) ? 1 : 0,
					'tax_rate_order'    => isset( $r['order'] ) ? (int) $r['order'] : 0,
				)
			);
			if ( $id ) {
				\WC_Tax::_update_tax_rate_postcodes( $id, array_map( 'strval', isset( $r['postcodes'] ) && is_array( $r['postcodes'] ) ? $r['postcodes'] : array() ) );
				\WC_Tax::_update_tax_rate_cities( $id, array_map( 'strval', isset( $r['cities'] ) && is_array( $r['cities'] ) ? $r['cities'] : array() ) );
			}
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		/* translators: %d: number of tax rates. */
		$n_rates   = sprintf( _n( '%d rate', '%d rates', count( $rates ), 'avesta-sync' ), count( $rates ) );
		/* translators: %d: number of tax classes. */
		$n_classes = sprintf( _n( '%d tax class', '%d tax classes', count( $classes ), 'avesta-sync' ), count( $classes ) );
		/* translators: 1: number of tax rates (e.g. "6 rates"), 2: number of tax classes (e.g. "2 tax classes"). */
		$msg = sprintf( __( 'Replaced: %1$s, %2$s.', 'avesta-sync' ), $n_rates, $n_classes );
		if ( $kept ) {
			/* translators: %s: list of tax class slugs. */
			$msg .= ' ' . sprintf( __( 'These tax classes were kept because products still use them: %s.', 'avesta-sync' ), implode( ', ', $kept ) );
		}
		return $msg;
	}
}
