<?php
/**
 * Display labels for content backups (sidecar file; the backed-up JSON is never changed).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Labels live in content/.labels.json of a run: file name => label. Old backups simply have none.
 */
class Backup_Labels {

	const FILE = '.labels.json';

	/**
	 * Display label for a content backup, derived from its data (the file itself is never changed).
	 *
	 * @param string $name Item key.
	 * @param mixed  $data Data.
	 * @return string
	 */
	public static function label_of( $name, $data ) {
		$d    = is_array( $data ) ? $data : array();
		$type = isset( $d['type'] ) && is_string( $d['type'] ) ? $d['type'] : '';
		$s    = static function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && is_string( $d[ $k ] ) ? $d[ $k ] : '';
		};
		switch ( $type ) {
			case 'wc_product':
				$label = $s( 'name' ) . ( '' !== $s( 'sku' ) ? ' (' . $s( 'sku' ) . ')' : '' );
				break;
			case 'wc_term':
				$label = Woo_Terms::tax_label( $s( 'taxonomy' ) ) . ': ' . $s( 'name' );
				break;
			case 'wc_attr':
				/* translators: %s: attribute label. */
				$label = sprintf( __( 'Attribute: %s', 'avesta-sync' ), $s( 'label' ) );
				break;
			case 'wc_coupon':
				/* translators: %s: coupon code. */
				$label = sprintf( __( 'Coupon: %s', 'avesta-sync' ), $s( 'code' ) );
				break;
			case 'wc_set':
				/* translators: %s: settings group. */
				$label = sprintf( __( 'Store settings: %s', 'avesta-sync' ), Woo_Settings::group_label( $s( 'group' ) ) );
				break;
			case 'media':
				/* translators: %s: media title or file name. */
				$label = sprintf( __( 'Media: %s', 'avesta-sync' ), '' !== $s( 'title' ) ? $s( 'title' ) : basename( $s( 'file' ) ) );
				break;
			case 'menu':
				/* translators: %s: menu name. */
				$label = sprintf( __( 'Menu: %s', 'avesta-sync' ), '' !== $s( 'name' ) ? $s( 'name' ) : $s( 'slug' ) );
				break;
			case 'option':
				$label = Content::option_label( $s( 'name' ) );
				break;
			default:
				$label = '' !== $type && '' !== $s( 'slug' ) ? Content::type_label( $type ) . ': ' . ( '' !== $s( 'title' ) ? $s( 'title' ) : $s( 'slug' ) ) : '';
		}
		return trim( $label, ' :' ) === '' ? '' : $label;
	}

	/**
	 * Record a label in the run's content/.labels.json sidecar (file name => label).
	 *
	 * @param string $dir  Content directory of the run.
	 * @param string $file Backup file name.
	 * @param string $name Item key.
	 * @param mixed  $data Data.
	 */
	public static function save( $dir, $file, $name, $data ) {
		$label = self::label_of( $name, $data );
		if ( '' === $label ) {
			return;
		}
		$path = $dir . '/' . self::FILE;
		$all  = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$all  = is_array( $all ) ? $all : array();
		$all[ $file ] = mb_substr( sanitize_text_field( $label ), 0, 300, 'UTF-8' );
		file_put_contents( $path, wp_json_encode( $all, JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Labels sidecar of a run (empty for old backups).
	 *
	 * @param string $dir Run directory.
	 * @return array file name => label
	 */
	public static function read( $dir ) {
		$path = $dir . '/content/' . self::FILE;
		$all  = is_file( $path ) && ! is_link( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out  = array();
		foreach ( is_array( $all ) ? $all : array() as $k => $v ) {
			if ( is_string( $k ) && is_string( $v ) && '' !== $v ) {
				$out[ $k ] = mb_substr( sanitize_text_field( $v ), 0, 300, 'UTF-8' );
			}
		}
		return $out;
	}
}
