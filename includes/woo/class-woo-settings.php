<?php
/**
 * WooCommerce store settings from an in-code allow-list, plus tax rate and shipping zone sets.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Items wc_set:<group>. Nothing outside the lists below is ever exported or written:
 * no gateway settings, API keys, webhooks, emails, licence or integration options, no page or
 * attachment IDs, no order/customer retention (data deletion) options.
 */
class Woo_Settings {

	const GROUPS = array( 'general', 'products', 'tax', 'tax_rates', 'shipping_zones', 'shipping_options', 'accounts' );

	/**
	 * Allow-listed option names per option group.
	 *
	 * @return array group => string[]
	 */
	private static function lists() {
		return array(
			'general'          => array(
				'woocommerce_store_address',
				'woocommerce_store_address_2',
				'woocommerce_store_city',
				'woocommerce_default_country',
				'woocommerce_store_postcode',
				'woocommerce_allowed_countries',
				'woocommerce_all_except_countries',
				'woocommerce_specific_allowed_countries',
				'woocommerce_ship_to_countries',
				'woocommerce_specific_ship_to_countries',
				'woocommerce_default_customer_address',
				'woocommerce_calc_taxes',
				'woocommerce_enable_coupons',
				'woocommerce_calc_discounts_sequentially',
				'woocommerce_currency',
				'woocommerce_currency_pos',
				'woocommerce_price_thousand_sep',
				'woocommerce_price_decimal_sep',
				'woocommerce_price_num_decimals',
			),
			'products'         => array(
				'woocommerce_weight_unit',
				'woocommerce_dimension_unit',
				'woocommerce_enable_reviews',
				'woocommerce_review_rating_verification_label',
				'woocommerce_review_rating_verification_required',
				'woocommerce_enable_review_rating',
				'woocommerce_review_rating_required',
				'woocommerce_manage_stock',
				'woocommerce_hold_stock_minutes',
				'woocommerce_notify_low_stock_amount',
				'woocommerce_notify_no_stock_amount',
				'woocommerce_hide_out_of_stock_items',
				'woocommerce_stock_format',
				'woocommerce_file_download_method',
				'woocommerce_downloads_require_login',
				'woocommerce_downloads_grant_access_after_payment',
				'woocommerce_downloads_add_hash_to_filename',
				'woocommerce_cart_redirect_after_add',
				'woocommerce_enable_ajax_add_to_cart',
			),
			'tax'              => array(
				'woocommerce_prices_include_tax',
				'woocommerce_tax_based_on',
				'woocommerce_shipping_tax_class',
				'woocommerce_tax_round_at_subtotal',
				'woocommerce_tax_display_shop',
				'woocommerce_tax_display_cart',
				'woocommerce_price_display_suffix',
				'woocommerce_tax_total_display',
			),
			'shipping_options' => array(
				'woocommerce_enable_shipping_calc',
				'woocommerce_shipping_cost_requires_address',
				'woocommerce_shipping_hide_rates_when_free',
				'woocommerce_ship_to_destination',
			),
			'accounts'         => array(
				'woocommerce_enable_guest_checkout',
				'woocommerce_enable_checkout_login_reminder',
				'woocommerce_enable_signup_and_login_from_checkout',
				'woocommerce_enable_myaccount_registration',
				'woocommerce_registration_generate_username',
				'woocommerce_registration_generate_password',
				'woocommerce_registration_privacy_policy_text',
				'woocommerce_checkout_privacy_policy_text',
			),
		);
	}

	/**
	 * Plain-language name of a settings group.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public static function group_label( $group ) {
		$map = array(
			'general'          => __( 'General', 'avesta-sync' ),
			'products'         => __( 'Products and inventory', 'avesta-sync' ),
			'tax'              => __( 'Tax options', 'avesta-sync' ),
			'tax_rates'        => __( 'Tax rates', 'avesta-sync' ),
			'shipping_zones'   => __( 'Shipping zones', 'avesta-sync' ),
			'shipping_options' => __( 'Shipping options', 'avesta-sync' ),
			'accounts'         => __( 'Accounts and privacy', 'avesta-sync' ),
		);
		return isset( $map[ $group ] ) ? $map[ $group ] : $group;
	}

	/**
	 * Option names of a group after the (remove-only) filter.
	 *
	 * @param string $group Group.
	 * @return string[]
	 */
	public static function keys_of( $group ) {
		$lists = self::lists();
		$base  = isset( $lists[ $group ] ) ? $lists[ $group ] : array();
		$kept  = apply_filters( 'avesta_sync_wc_setting_keys', $base, $group );
		return is_array( $kept ) ? array_values( array_intersect( $base, array_map( 'strval', $kept ) ) ) : $base;
	}

	/**
	 * Export all setting groups.
	 *
	 * @param array|null $keys Keys filter.
	 * @return array List of {key,data,label,ord}.
	 */
	public static function export( $keys ) {
		$want = Woo::keys_for( $keys, 'wc_set:' );
		$out  = array();
		if ( array() === $want ) {
			return $out;
		}
		foreach ( self::GROUPS as $group ) {
			if ( null === $want || in_array( $group, $want, true ) ) {
				$out[] = self::item( $group );
			}
		}
		return $out;
	}

	/**
	 * Item for one group.
	 *
	 * @param string $group Group.
	 * @return array
	 */
	private static function item( $group ) {
		$data = array(
			'type'  => 'wc_set',
			'group' => $group,
		);
		if ( 'tax_rates' === $group ) {
			$data += Woo_Tax::export();
		} elseif ( 'shipping_zones' === $group ) {
			$data += Woo_Shipping::export();
		} else {
			$opts = array();
			foreach ( self::keys_of( $group ) as $name ) {
				$val = get_option( $name, null );
				if ( null !== $val ) {
					$opts[ $name ] = Content::tok( $val );
				}
			}
			ksort( $opts );
			$data['options'] = $opts;
		}
		return array(
			'key'   => 'wc_set:' . $group,
			/* translators: %s: settings group (general, products, tax, ...). */
			'label' => sprintf( __( 'Store settings: %s', 'avesta-sync' ), self::group_label( $group ) ),
			'ord'   => 95,
			'data'  => $data,
		);
	}

	/**
	 * Value rule of an option: 'text' (default), 'array' (list of country codes), 'currency', 'location',
	 * 'sep', array('range'=>[min,max,empty allowed]) or a list of allowed values.
	 *
	 * @param string $name Option name.
	 * @return string|array
	 */
	private static function rule( $name ) {
		$yesno = array( 'yes', 'no' );
		$rules = array(
			'woocommerce_allowed_countries'               => array( 'all', 'all_except', 'specific' ),
			'woocommerce_ship_to_countries'               => array( '', 'all', 'specific', 'disabled' ),
			'woocommerce_default_customer_address'        => array( '', 'base', 'geolocation', 'geolocation_ajax' ),
			'woocommerce_currency_pos'                    => array( 'left', 'right', 'left_space', 'right_space' ),
			'woocommerce_weight_unit'                     => array( 'kg', 'g', 'lbs', 'oz' ),
			'woocommerce_dimension_unit'                  => array( 'm', 'cm', 'mm', 'in', 'yd' ),
			'woocommerce_tax_based_on'                    => array( 'shipping', 'billing', 'base' ),
			'woocommerce_tax_display_shop'                => array( 'incl', 'excl' ),
			'woocommerce_tax_display_cart'                => array( 'incl', 'excl' ),
			'woocommerce_tax_total_display'               => array( 'itemized', 'single' ),
			'woocommerce_stock_format'                    => array( '', 'low_amount', 'no_amount' ),
			'woocommerce_file_download_method'            => array( 'force', 'xsendfile', 'redirect' ),
			'woocommerce_ship_to_destination'             => array( 'shipping', 'billing', 'billing_only' ),
			'woocommerce_all_except_countries'            => 'array',
			'woocommerce_specific_allowed_countries'      => 'array',
			'woocommerce_specific_ship_to_countries'      => 'array',
			'woocommerce_price_num_decimals'              => array( 'range' => array( 0, 10, false ) ),
			'woocommerce_hold_stock_minutes'              => array( 'range' => array( 0, 525600, true ) ),
			'woocommerce_notify_low_stock_amount'         => array( 'range' => array( 0, 99999, true ) ),
			'woocommerce_notify_no_stock_amount'          => array( 'range' => array( 0, 99999, true ) ),
			'woocommerce_currency'                        => 'currency',
			'woocommerce_default_country'                 => 'location',
			'woocommerce_price_thousand_sep'              => 'sep',
			'woocommerce_price_decimal_sep'               => 'sep',
		);
		foreach ( array( 'calc_taxes', 'enable_coupons', 'calc_discounts_sequentially', 'enable_reviews', 'review_rating_verification_label', 'review_rating_verification_required', 'enable_review_rating', 'review_rating_required', 'manage_stock', 'hide_out_of_stock_items', 'downloads_require_login', 'downloads_grant_access_after_payment', 'downloads_add_hash_to_filename', 'cart_redirect_after_add', 'enable_ajax_add_to_cart', 'prices_include_tax', 'tax_round_at_subtotal', 'enable_shipping_calc', 'shipping_cost_requires_address', 'shipping_hide_rates_when_free', 'enable_guest_checkout', 'enable_checkout_login_reminder', 'enable_signup_and_login_from_checkout', 'enable_myaccount_registration', 'registration_generate_username', 'registration_generate_password' ) as $flag ) {
			$rules[ 'woocommerce_' . $flag ] = $yesno;
		}
		return isset( $rules[ $name ] ) ? $rules[ $name ] : 'text';
	}

	/**
	 * Validate and sanitise one option value by its rule.
	 *
	 * @param string $name Option name.
	 * @param mixed  $v    Value.
	 * @return mixed|\WP_Error
	 */
	private static function clean( $name, $v ) {
		$rule = self::rule( $name );
		$bad  = new \WP_Error(
			'avesta_sync_wc_setting',
			/* translators: %s: name of a store setting (e.g. "price num decimals"). */
			sprintf( __( 'The value of the store setting "%s" is not valid, so nothing in this group was changed.', 'avesta-sync' ), str_replace( '_', ' ', preg_replace( '/^woocommerce_/', '', $name ) ) )
		);
		if ( 'array' === $rule ) {
			if ( '' === $v ) {
				return ''; // WooCommerce stores an unset country list as an empty string.
			}
			$countries = array_keys( WC()->countries->get_countries() );
			if ( ! is_array( $v ) ) {
				return $bad;
			}
			$out = array();
			foreach ( $v as $item ) {
				if ( ! is_string( $item ) || ! in_array( $item, $countries, true ) ) {
					return $bad;
				}
				$out[] = $item;
			}
			return $out;
		}
		if ( ! is_scalar( $v ) ) {
			return $bad;
		}
		$v = (string) Content::untok( (string) $v );
		if ( 'currency' === $rule ) {
			return array_key_exists( $v, get_woocommerce_currencies() ) ? $v : $bad;
		}
		if ( 'location' === $rule ) {
			$parts = explode( ':', $v, 2 );
			if ( ! array_key_exists( $parts[0], WC()->countries->get_countries() ) ) {
				return $bad;
			}
			$states = WC()->countries->get_states( $parts[0] );
			return isset( $parts[1] ) && is_array( $states ) && $states && ! array_key_exists( $parts[1], $states ) ? $bad : $v;
		}
		if ( 'sep' === $rule ) {
			return mb_strlen( $v, 'UTF-8' ) <= 3 ? (string) preg_replace( '/[<>\x00-\x1f]/', '', $v ) : $bad; // A single space is a valid separator.
		}
		if ( is_array( $rule ) && isset( $rule['range'] ) ) {
			if ( '' === $v ) {
				return $rule['range'][2] ? $v : $bad;
			}
			return preg_match( '/^\d{1,6}$/', $v ) && (int) $v >= $rule['range'][0] && (int) $v <= $rule['range'][1] ? $v : $bad;
		}
		if ( is_array( $rule ) ) {
			return in_array( $v, $rule, true ) ? $v : $bad;
		}
		return wp_kses_post( $v );
	}

	/**
	 * Import one group (the current state is backed up first).
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import( array $d, $run ) {
		$group = isset( $d['group'] ) && is_string( $d['group'] ) ? $d['group'] : '';
		if ( ! in_array( $group, self::GROUPS, true ) ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'This group of store settings is not known.', 'avesta-sync' ) );
		}
		$old = self::item( $group );
		if ( ! Backup::save_json( $old['key'], $old['data'], $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was changed.', 'avesta-sync' ) );
		}
		if ( 'tax_rates' === $group ) {
			return Woo_Tax::import( $d );
		}
		if ( 'shipping_zones' === $group ) {
			return Woo_Shipping::import( $d );
		}
		$allowed = self::keys_of( $group );
		$opts    = isset( $d['options'] ) && is_array( $d['options'] ) ? $d['options'] : array();
		$clean   = array();
		foreach ( $opts as $name => $val ) {
			if ( in_array( (string) $name, $allowed, true ) ) {
				$v = self::clean( (string) $name, $val );
				if ( is_wp_error( $v ) ) {
					return $v; // Validate everything first: nothing is written when one value is wrong.
				}
				$clean[ (string) $name ] = $v;
			}
		}
		foreach ( $clean as $name => $v ) {
			update_option( $name, $v );
		}
		$n = count( $clean );
		/* translators: %d: number of settings. */
		return sprintf( _n( 'Updated: %d setting.', 'Updated: %d settings.', $n, 'avesta-sync' ), $n );
	}
}
