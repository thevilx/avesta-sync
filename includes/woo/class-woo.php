<?php
/**
 * WooCommerce sync: availability, preflight, item registry and dispatch.
 *
 * Item types (data['type']) and keys:
 * wc_attr:<slug>, wc_term:<taxonomy>:<slug>, wc_product:sku:<sku> | wc_product:slug:<slug>,
 * wc_coupon:<code>, wc_set:<group>.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point used by Content, Importer, Sync, Rest and Ajax.
 */
class Woo {

	const TYPES    = array( 'wc_attr', 'wc_term', 'wc_product', 'wc_coupon', 'wc_set' );
	const GROUPS   = array( 'wc_attributes', 'wc_terms', 'wc_products', 'wc_coupons', 'wc_settings' );
	const MIN_PEER = '1.2.0';

	/**
	 * Export notes (unsupported types, ambiguous SKUs) of the last export on this request.
	 *
	 * @var string[]
	 */
	private static $export_notes = array();

	/**
	 * Is WooCommerce usable on this site?
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && class_exists( 'WC_Product' );
	}

	/**
	 * Does a key or type belong to WooCommerce sync?
	 *
	 * @param string $key Item key.
	 * @return bool
	 */
	public static function is_key( $key ) {
		return (bool) preg_match( '/^wc_(attr|term|product|coupon|set):/', (string) $key );
	}

	/**
	 * WooCommerce facts for /info and the preflight.
	 *
	 * @return array {active,version,hpos}
	 */
	public static function info() {
		$active = self::active();
		$hpos   = false;
		if ( $active && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'active'  => $active,
			'version' => $active && defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'hpos'    => $hpos,
			'scope'   => in_array( 'woocommerce', (array) Settings::get()['scopes'], true ),
		);
	}

	/**
	 * Preflight between this site and the remote's /info.
	 *
	 * @param array $remote_info Remote /info response.
	 * @param bool  $reached     False when the remote could not be reached (only local facts are judged).
	 * @return array {wc:{local,remote}, wc_block, wc_warn, wc_message}
	 */
	public static function preflight( array $remote_info, $reached = true ) {
		$local  = self::info();
		$known  = isset( $remote_info['wc'] ) && is_array( $remote_info['wc'] );
		$remote = array(
			'active'  => $known && ! empty( $remote_info['wc']['active'] ),
			'version' => $known && isset( $remote_info['wc']['version'] ) ? (string) $remote_info['wc']['version'] : '',
			'hpos'    => $known && ! empty( $remote_info['wc']['hpos'] ),
		);
		$block   = '';
		$warn    = '';
		$message = '';
		$lv      = $local['version'];
		$rv      = $remote['version'];
		if ( ! $local['active'] ) {
			$block   = 'inactive_local';
			$message = __( 'WooCommerce is not active on this site, so WooCommerce syncing is not available.', 'avesta-sync' );
		} elseif ( ! $reached ) {
			$message = '';
		} elseif ( ! $known ) {
			$block   = 'remote_outdated';
			$message = __( 'The other site runs an older version of Avesta Sync that cannot sync WooCommerce. Update Avesta Sync on both sites.', 'avesta-sync' );
		} elseif ( ! $remote['active'] ) {
			$block   = 'inactive_remote';
			$message = __( 'WooCommerce is not active on the other site, so WooCommerce syncing is not available.', 'avesta-sync' );
		} elseif ( isset( $remote_info['wc']['scope'] ) && ! $remote_info['wc']['scope'] ) {
			$block   = 'disabled_remote';
			$message = __( 'WooCommerce syncing is switched off on the other site. Turn it on under Settings > What to sync on both sites.', 'avesta-sync' );
		} elseif ( (int) strtok( $lv, '.' ) !== (int) strtok( $rv, '.' ) ) {
			$block = 'major_mismatch';
			/* translators: 1: local WooCommerce version, 2: remote WooCommerce version. */
			$message = sprintf( __( 'WooCommerce on the two sites is a different major version (%1$s here, %2$s on the other site). Update the older site before syncing.', 'avesta-sync' ), $lv, $rv );
		} elseif ( self::minor( $lv ) !== self::minor( $rv ) ) {
			$warn = 'minor_mismatch';
			/* translators: 1: local WooCommerce version, 2: remote WooCommerce version. */
			$message = sprintf( __( 'WooCommerce on the two sites is a slightly different version (%1$s here, %2$s on the other site). Syncing works, but update both sites to the same version when you can.', 'avesta-sync' ), $lv, $rv );
		}
		return array(
			'wc'         => array(
				'local'  => $local,
				'remote' => $remote,
			),
			'wc_block'   => $block,
			'wc_warn'    => $warn,
			'wc_message' => $message,
		);
	}

	/**
	 * "major.minor" of a version string.
	 *
	 * @param string $v Version.
	 * @return string
	 */
	private static function minor( $v ) {
		$p = explode( '.', (string) $v );
		return $p[0] . '.' . ( isset( $p[1] ) ? $p[1] : '0' );
	}

	/**
	 * Does this site's own WooCommerce setting allow an item type to be received, deleted or served?
	 * The settings of the receiving (or providing) site decide, whatever the other site claims.
	 *
	 * @param string $type Item type (wc_*).
	 * @param bool   $send True when this site would provide the data (export), false when it would change it.
	 * @return true|\WP_Error
	 */
	private static function allowed_here( $type, $send ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'avesta_sync_wc_inactive', $send ? __( 'WooCommerce is not active on the site providing this data.', 'avesta-sync' ) : __( 'WooCommerce is not active on the site receiving this change.', 'avesta-sync' ) );
		}
		$s    = Settings::get();
		$opts = array(
			'wc_attr'    => array( 'wc_taxonomies', __( 'categories, tags and attributes', 'avesta-sync' ), __( 'Categories, tags and attributes', 'avesta-sync' ) ),
			'wc_term'    => array( 'wc_taxonomies', __( 'categories, tags and attributes', 'avesta-sync' ), __( 'Categories, tags and attributes', 'avesta-sync' ) ),
			'wc_product' => array( 'wc_products', __( 'products', 'avesta-sync' ), __( 'Products', 'avesta-sync' ) ),
			'wc_coupon'  => array( 'wc_coupons', __( 'coupons', 'avesta-sync' ), __( 'Coupons', 'avesta-sync' ) ),
			'wc_set'     => array( 'wc_settings', __( 'store settings', 'avesta-sync' ), __( 'Store settings', 'avesta-sync' ) ),
		);
		if ( ! isset( $opts[ $type ] ) ) {
			return new \WP_Error( 'avesta_sync_type', __( 'This kind of item is not supported. Update Avesta Sync on both sites.', 'avesta-sync' ) );
		}
		if ( ! in_array( 'woocommerce', (array) $s['scopes'], true ) ) {
			return new \WP_Error(
				'avesta_sync_wc_scope',
				$send ? __( 'WooCommerce syncing is switched off on the site providing this data. Turn on "WooCommerce" under Settings > What to sync on both sites.', 'avesta-sync' ) : __( 'WooCommerce syncing is switched off on the site receiving this change. Turn on "WooCommerce" under Settings > What to sync on both sites.', 'avesta-sync' )
			);
		}
		if ( empty( $s[ $opts[ $type ][0] ] ) ) {
			return new \WP_Error(
				'avesta_sync_wc_scope',
				sprintf(
					$send
						/* translators: 1: kind of items (e.g. store settings), 2: the matching option name under Settings > What to sync. */
						? __( 'WooCommerce syncing (%1$s) is switched off on the site providing this data. Turn on "%2$s" under Settings > What to sync on both sites.', 'avesta-sync' )
						/* translators: 1: kind of items (e.g. store settings), 2: the matching option name under Settings > What to sync. */
						: __( 'WooCommerce syncing (%1$s) is switched off on the site receiving this change. Turn on "%2$s" under Settings > What to sync on both sites.', 'avesta-sync' ),
					$opts[ $type ][1],
					$opts[ $type ][2]
				)
			);
		}
		return true;
	}

	/**
	 * Gate for receiving or deleting an item of a type.
	 *
	 * @param string $type Item type (wc_*).
	 * @return true|\WP_Error
	 */
	public static function receive_gate( $type ) {
		return self::allowed_here( $type, false );
	}

	/**
	 * Gate for serving items of a type to the other site (export, preview).
	 *
	 * @param string $type Item type (wc_*).
	 * @return true|\WP_Error
	 */
	public static function export_gate( $type ) {
		return self::allowed_here( $type, true );
	}

	/**
	 * Does an item's payload type belong to its key? WooCommerce keys carry exactly their own type and
	 * no other key may carry a WooCommerce type.
	 *
	 * @param string $key  Item key.
	 * @param string $type data['type'].
	 * @return bool
	 */
	public static function key_matches( $key, $type ) {
		if ( preg_match( '/^(wc_(?:attr|term|product|coupon|set)):/', (string) $key, $m ) ) {
			return $m[1] === $type;
		}
		return ! in_array( $type, self::TYPES, true );
	}

	/**
	 * Is the remote's Avesta Sync new enough to understand WooCommerce items?
	 *
	 * @param array $remote_info /info response.
	 * @return bool
	 */
	public static function peer_ok( array $remote_info ) {
		return isset( $remote_info['version'] ) && version_compare( (string) $remote_info['version'], self::MIN_PEER, '>=' );
	}

	/**
	 * Export groups for the WooCommerce scope from the settings.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function groups( array $settings ) {
		$g = array();
		if ( ! empty( $settings['wc_taxonomies'] ) ) {
			$g[] = 'wc_attributes';
			$g[] = 'wc_terms';
		}
		foreach ( array( 'wc_products', 'wc_coupons', 'wc_settings' ) as $opt ) {
			if ( ! empty( $settings[ $opt ] ) ) {
				$g[] = $opt;
			}
		}
		return $g;
	}

	/**
	 * Compare-row group for an item key.
	 *
	 * @param string $id Key.
	 * @return string
	 */
	public static function group_of( $id ) {
		$map = array(
			'wc_attr:'    => 'wc_attributes',
			'wc_term:'    => 'wc_terms',
			'wc_product:' => 'wc_products',
			'wc_coupon:'  => 'wc_coupons',
			'wc_set:'     => 'wc_settings',
		);
		foreach ( $map as $prefix => $group ) {
			if ( 0 === strpos( (string) $id, $prefix ) ) {
				return $group;
			}
		}
		return 'wc_settings';
	}

	/**
	 * Is an item key enabled by this site's WooCommerce sub-options?
	 *
	 * @param string $id       Key.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	public static function kind_enabled( $id, array $settings ) {
		return in_array( self::group_of( $id ), self::groups( $settings ), true );
	}

	/**
	 * Suffixes of the keys that start with a prefix. Null input means "all".
	 *
	 * @param array|null $keys   Requested keys.
	 * @param string     $prefix Prefix including the colon.
	 * @return array|null Null = unrestricted, array() = nothing requested.
	 */
	public static function keys_for( $keys, $prefix ) {
		if ( null === $keys ) {
			return null;
		}
		$out = array();
		foreach ( $keys as $k ) {
			if ( 0 === strpos( (string) $k, $prefix ) ) {
				$out[] = substr( (string) $k, strlen( $prefix ) );
			}
		}
		return $out;
	}

	/**
	 * Remember an export note.
	 *
	 * @param string $note Note.
	 */
	public static function export_note( $note ) {
		self::$export_notes[] = (string) $note;
	}

	/**
	 * Notes of the last export.
	 *
	 * @return string[]
	 */
	public static function export_notes() {
		return array_values( array_unique( self::$export_notes ) );
	}

	/**
	 * Content hash of an item: stock fields never count.
	 *
	 * @param array $data Item data.
	 * @return string
	 */
	public static function hash( array $data ) {
		unset( $data['stock'] );
		if ( isset( $data['variations'] ) && is_array( $data['variations'] ) ) {
			foreach ( $data['variations'] as $i => $v ) {
				if ( is_array( $v ) ) {
					unset( $data['variations'][ $i ]['stock'] );
				}
			}
		}
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Export WooCommerce items.
	 *
	 * @param array      $groups      Groups (wc_attributes, wc_terms, wc_products, wc_coupons, wc_settings).
	 * @param bool       $hashes_only Omit data.
	 * @param array|null $keys        Restrict to these keys.
	 * @return array key => {hash,label,ord,meta?,data?}
	 */
	public static function export( array $groups, $hashes_only, $keys = null ) {
		$out = array();
		if ( ! self::active() ) {
			return $out;
		}
		// This site's own switches decide what it serves: nothing leaves it for a kind that is turned off.
		$kinds = array(
			'wc_attributes' => 'wc_attr',
			'wc_terms'      => 'wc_term',
			'wc_products'   => 'wc_product',
			'wc_coupons'    => 'wc_coupon',
			'wc_settings'   => 'wc_set',
		);
		foreach ( $groups as $i => $g ) {
			if ( isset( $kinds[ $g ] ) && is_wp_error( self::export_gate( $kinds[ $g ] ) ) ) {
				unset( $groups[ $i ] );
			}
		}
		self::$export_notes = array();
		$lists              = array();
		if ( in_array( 'wc_attributes', $groups, true ) ) {
			$lists[] = Woo_Taxonomies::export_attrs( $keys );
		}
		if ( in_array( 'wc_terms', $groups, true ) ) {
			$lists[] = Woo_Terms::export_terms( $keys );
		}
		if ( in_array( 'wc_products', $groups, true ) ) {
			$lists[] = Woo_Products::export( $keys );
		}
		if ( in_array( 'wc_coupons', $groups, true ) ) {
			$lists[] = Woo_Coupons::export( $keys );
		}
		if ( in_array( 'wc_settings', $groups, true ) ) {
			$lists[] = Woo_Settings::export( $keys );
		}
		foreach ( $lists as $list ) {
			foreach ( $list as $item ) {
				$rec = array(
					'hash'  => self::hash( $item['data'] ),
					'label' => $item['label'],
					'ord'   => (int) $item['ord'],
				);
				if ( isset( $item['meta'] ) ) {
					$rec['meta'] = $item['meta'];
				}
				if ( ! $hashes_only ) {
					$rec['data'] = $item['data'];
				}
				$out[ $item['key'] ] = $rec;
			}
		}
		return $out;
	}

	/**
	 * Import one item.
	 *
	 * @param array  $d    Item data.
	 * @param string $run  Run id.
	 * @param array  $opts {stock:bool}.
	 * @return string|\WP_Error
	 */
	public static function import( array $d, $run, array $opts = array() ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'avesta_sync_wc_inactive', __( 'WooCommerce is not active on the site receiving this change.', 'avesta-sync' ) );
		}
		Woo_Refs::reset();
		switch ( (string) $d['type'] ) {
			case 'wc_attr':
				$r = Woo_Taxonomies::import_attr( $d, $run );
				break;
			case 'wc_term':
				$r = Woo_Terms::import_term( $d, $run );
				break;
			case 'wc_product':
				$r = Woo_Product_Import::run( $d, $run, $opts );
				break;
			case 'wc_coupon':
				$r = Woo_Coupons::import( $d, $run );
				break;
			case 'wc_set':
				$r = Woo_Settings::import( $d, $run );
				break;
			default:
				return new \WP_Error( 'avesta_sync_type', __( 'This kind of item is not supported. Update Avesta Sync on both sites.', 'avesta-sync' ) );
		}
		return is_wp_error( $r ) ? $r : $r . Woo_Refs::suffix();
	}

	/**
	 * Delete (trash) one item.
	 *
	 * @param string $key Item key.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function delete( $key, $run ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'avesta_sync_wc_inactive', __( 'WooCommerce is not active on the site receiving this change.', 'avesta-sync' ) );
		}
		$key = (string) $key;
		if ( 0 === strpos( $key, 'wc_attr:' ) ) {
			return Woo_Taxonomies::delete_attr( substr( $key, 8 ), $key, $run );
		}
		if ( 0 === strpos( $key, 'wc_term:' ) ) {
			return Woo_Terms::delete_term( substr( $key, 8 ), $key, $run );
		}
		if ( 0 === strpos( $key, 'wc_product:' ) ) {
			return Woo_Products::delete( substr( $key, 11 ), $key, $run );
		}
		if ( 0 === strpos( $key, 'wc_coupon:' ) ) {
			return Woo_Coupons::delete( substr( $key, 10 ), $key, $run );
		}
		return new \WP_Error( 'avesta_sync_wc', __( 'Store settings cannot be deleted.', 'avesta-sync' ) );
	}

	/**
	 * Product types that this site's sync handles (filterable).
	 *
	 * @return string[]
	 */
	public static function product_types() {
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'avesta_sync_wc_product_types', array( 'simple', 'variable', 'grouped', 'external' ) ) ) ) );
	}
}
