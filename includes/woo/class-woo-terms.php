<?php
/**
 * WooCommerce catalog terms: categories, tags, shipping classes and global attribute terms.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Items wc_term:<taxonomy>:<slug>. Data never contains IDs; the parent is a slug, the thumbnail a file path.
 */
class Woo_Terms {

	const TAXES          = array( 'product_cat', 'product_tag', 'product_shipping_class', 'product_brand' );
	const IMAGE_TAXES    = array( 'product_cat', 'product_brand' );
	const SKIP_TERM_META = array( 'thumbnail_id', 'order', 'display_type' );

	/**
	 * Taxonomies handled by term items.
	 *
	 * @return string[]
	 */
	private static function taxes() {
		$out = self::TAXES;
		foreach ( array_keys( Woo_Taxonomies::attr_rows() ) as $slug ) {
			$out[] = wc_attribute_taxonomy_name( $slug );
		}
		return array_values( array_filter( $out, 'taxonomy_exists' ) );
	}

	/**
	 * Meta key that stores the manual order of a taxonomy's terms ('' = none).
	 *
	 * @param string $tax Taxonomy.
	 * @return string
	 */
	private static function order_key( $tax ) {
		if ( 'product_cat' === $tax ) {
			return 'order';
		}
		return 0 === strpos( $tax, 'pa_' ) ? 'order_' . $tax : '';
	}

	/**
	 * Export terms.
	 *
	 * @param array|null $keys Keys filter.
	 * @return array List of {key,data,label,ord}.
	 */
	public static function export_terms( $keys ) {
		$want = Woo::keys_for( $keys, 'wc_term:' );
		$out  = array();
		if ( array() === $want ) {
			return $out;
		}
		foreach ( self::taxes() as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				if ( null !== $want && ! in_array( $tax . ':' . $t->slug, $want, true ) ) {
					continue;
				}
				$out[] = self::term_item( $t );
			}
		}
		return $out;
	}

	/**
	 * Plain-language name of a taxonomy: the registered singular label, a translated fallback map,
	 * and "<Attribute label> value" for global attributes.
	 *
	 * @param string $tax Taxonomy.
	 * @return string
	 */
	public static function tax_label( $tax ) {
		if ( 0 === strpos( $tax, 'pa_' ) ) {
			/* translators: %s: attribute label (e.g. Color), so "Color value". */
			return sprintf( __( '%s value', 'avesta-sync' ), function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $tax ) : substr( $tax, 3 ) );
		}
		$o = get_taxonomy( $tax );
		if ( $o && ! empty( $o->labels->singular_name ) ) {
			return (string) $o->labels->singular_name;
		}
		$map = array(
			'product_cat'            => __( 'Category', 'avesta-sync' ),
			'product_tag'            => __( 'Tag', 'avesta-sync' ),
			'product_shipping_class' => __( 'Shipping class', 'avesta-sync' ),
			'product_brand'          => __( 'Brand', 'avesta-sync' ),
		);
		return isset( $map[ $tax ] ) ? $map[ $tax ] : $tax;
	}

	/**
	 * Item for one term.
	 *
	 * @param \WP_Term $t Term.
	 * @return array
	 */
	private static function term_item( \WP_Term $t ) {
		$tax    = $t->taxonomy;
		$parent = '';
		$depth  = 0;
		$cursor = $t;
		while ( $cursor->parent && $depth < 10 ) {
			$cursor = get_term( (int) $cursor->parent, $tax );
			if ( ! $cursor instanceof \WP_Term ) {
				break;
			}
			if ( 0 === $depth ) {
				$parent = (string) $cursor->slug;
			}
			++$depth;
		}
		$okey  = self::order_key( $tax );
		$thumb = in_array( $tax, self::IMAGE_TAXES, true ) ? Woo_Refs::file_of( (int) get_term_meta( $t->term_id, 'thumbnail_id', true ) ) : '';
		$meta  = array();
		foreach ( get_term_meta( $t->term_id ) as $mk => $vals ) {
			if ( 0 === strpos( $mk, '_' ) || 0 === strpos( $mk, 'product_count_' ) || in_array( $mk, self::SKIP_TERM_META, true ) || $mk === $okey ) {
				continue;
			}
			$list = array();
			foreach ( $vals as $raw ) {
				$val = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- local term meta of this site, no objects allowed.
				if ( ! is_object( $val ) ) {
					$list[] = Content::tok( $val );
				}
			}
			if ( $list ) {
				$meta[ $mk ] = $list;
			}
		}
		ksort( $meta );
		return array(
			'key'   => 'wc_term:' . $tax . ':' . $t->slug,
			'label' => self::tax_label( $tax ) . ': ' . $t->name,
			'ord'   => 2 + min( $depth, 2 ),
			'data'  => array(
				'type'         => 'wc_term',
				'taxonomy'     => $tax,
				'slug'         => (string) $t->slug,
				'name'         => Content::tok( $t->name ),
				'description'  => Content::tok( $t->description ),
				'parent'       => $parent,
				'order'        => '' !== $okey ? (int) get_term_meta( $t->term_id, $okey, true ) : 0,
				'display_type' => 'product_cat' === $tax ? (string) get_term_meta( $t->term_id, 'display_type', true ) : '',
				'thumbnail'    => $thumb,
				'meta'         => $meta,
			),
		);
	}

	/**
	 * Import a term.
	 *
	 * @param array  $d   Data.
	 * @param string $run Run id.
	 * @return string|\WP_Error
	 */
	public static function import_term( array $d, $run ) {
		$tax  = isset( $d['taxonomy'] ) && is_string( $d['taxonomy'] ) ? $d['taxonomy'] : '';
		$slug = isset( $d['slug'] ) && is_string( $d['slug'] ) ? $d['slug'] : '';
		if ( '' === $slug || ! in_array( $tax, self::taxes(), true ) ) {
			/* translators: %s: taxonomy. */
			return new \WP_Error( 'avesta_sync_wc', sprintf( __( 'The attribute "%s" does not exist on this site yet. Sync attributes first.', 'avesta-sync' ), 0 === strpos( $tax, 'pa_' ) ? substr( $tax, 3 ) : $tax ) );
		}
		$args = array(
			'slug'        => $slug,
			'description' => (string) Content::untok( isset( $d['description'] ) ? $d['description'] : '' ),
			'parent'      => 0,
		);
		$par  = isset( $d['parent'] ) && is_string( $d['parent'] ) ? $d['parent'] : '';
		if ( '' !== $par ) {
			$pid = Woo_Refs::term_id( $tax, $par );
			if ( ! $pid ) {
				/* translators: %s: parent term slug. */
				return new \WP_Error( 'avesta_sync_parent', sprintf( __( 'The parent "%s" does not exist on the receiving site yet. Sync it first.', 'avesta-sync' ), $par ) );
			}
			$args['parent'] = $pid;
		}
		$name = sanitize_text_field( (string) Content::untok( isset( $d['name'] ) ? $d['name'] : $slug ) );
		$old  = Woo_Refs::term_id( $tax, $slug );
		if ( $old ) {
			$cur = get_term( $old, $tax );
			if ( $cur instanceof \WP_Term ) {
				$item = self::term_item( $cur );
				if ( ! Backup::save_json( $item['key'], $item['data'], $run ) ) {
					return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was changed.', 'avesta-sync' ) );
				}
			}
			$args['name'] = $name;
			$res          = wp_update_term( $old, $tax, $args );
		} else {
			$res = wp_insert_term( $name, $tax, $args );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (int) $res['term_id'];
		self::write_term_meta( $id, $tax, $d );
		return $old ? __( 'Updated.', 'avesta-sync' ) : __( 'Created.', 'avesta-sync' );
	}

	/**
	 * Write order, display type, thumbnail and extra meta of a term.
	 *
	 * @param int    $id  Term ID.
	 * @param string $tax Taxonomy.
	 * @param array  $d   Data.
	 */
	private static function write_term_meta( $id, $tax, array $d ) {
		$okey = self::order_key( $tax );
		if ( '' !== $okey ) {
			update_term_meta( $id, $okey, isset( $d['order'] ) ? (int) $d['order'] : 0 );
		}
		if ( in_array( $tax, self::IMAGE_TAXES, true ) ) {
			if ( 'product_cat' === $tax ) {
				update_term_meta( $id, 'display_type', isset( $d['display_type'] ) && is_string( $d['display_type'] ) ? sanitize_key( $d['display_type'] ) : '' );
			}
			$thumb = isset( $d['thumbnail'] ) && is_string( $d['thumbnail'] ) ? $d['thumbnail'] : '';
			$att   = Woo_Refs::attachment_id( $thumb );
			if ( $att ) {
				update_term_meta( $id, 'thumbnail_id', $att );
			} else {
				delete_term_meta( $id, 'thumbnail_id' );
				if ( '' !== $thumb ) {
					/* translators: %s: file path. */
					Woo_Refs::note( sprintf( __( 'Image "%s" was not found on the receiving site. Sync the media library first', 'avesta-sync' ), $thumb ) );
				}
			}
		}
		$meta = isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array();
		foreach ( array_keys( get_term_meta( $id ) ) as $mk ) {
			if ( 0 !== strpos( $mk, '_' ) && 0 !== strpos( $mk, 'product_count_' ) && ! in_array( $mk, self::SKIP_TERM_META, true ) && $mk !== $okey && ! isset( $meta[ $mk ] ) ) {
				delete_term_meta( $id, $mk );
			}
		}
		foreach ( $meta as $mk => $vals ) {
			$mk = (string) $mk;
			if ( '' === $mk || 0 === strpos( $mk, '_' ) || ! is_array( $vals ) || in_array( $mk, self::SKIP_TERM_META, true ) || $mk === $okey ) {
				continue;
			}
			delete_term_meta( $id, $mk );
			foreach ( $vals as $val ) {
				add_term_meta( $id, $mk, wp_slash( Content::untok( $val ) ) );
			}
		}
	}

	/**
	 * Delete a term (refused while products use it or it has children).
	 *
	 * @param string $rest "<taxonomy>:<slug>".
	 * @param string $key  Item key.
	 * @param string $run  Run id.
	 * @return string|\WP_Error
	 */
	public static function delete_term( $rest, $key, $run ) {
		$parts = explode( ':', (string) $rest, 2 );
		$tax   = $parts[0];
		$slug  = isset( $parts[1] ) ? $parts[1] : '';
		$id    = in_array( $tax, self::taxes(), true ) ? Woo_Refs::term_id( $tax, $slug ) : 0;
		$term  = $id ? get_term( $id, $tax ) : null;
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'avesta_sync_wc', __( 'It was not found.', 'avesta-sync' ) );
		}
		if ( Woo_Taxonomies::in_use( $tax, $id ) || get_terms(
			array(
				'taxonomy'   => $tax,
				'parent'     => $id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
			)
		) ) {
			return new \WP_Error( 'avesta_sync_wc_in_use', __( 'Not deleted: products on the receiving site still use it, or it has child items.', 'avesta-sync' ) );
		}
		$item = self::term_item( $term );
		if ( ! Backup::save_json( $key, $item['data'], $run ) ) {
			return new \WP_Error( 'avesta_sync_backup', __( 'The backup failed, so nothing was deleted.', 'avesta-sync' ) );
		}
		$res = wp_delete_term( $id, $tax );
		return true === $res ? __( 'Deleted.', 'avesta-sync' ) : new \WP_Error( 'avesta_sync_wc', __( 'It could not be deleted. The default category cannot be deleted.', 'avesta-sync' ) );
	}
}
