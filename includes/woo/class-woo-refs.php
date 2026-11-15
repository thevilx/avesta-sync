<?php
/**
 * WooCommerce portable references: products, terms and attachments are never exchanged as raw IDs.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Reference helpers plus the per-item note collector.
 */
class Woo_Refs {

	const PENDING   = 'avesta_sync_wc_pending';
	const STATUSES  = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
	const FIELD_SET = array(
		'upsells'     => 'set_upsell_ids',
		'cross_sells' => 'set_cross_sell_ids',
		'children'    => 'set_children',
	);
	const FIELD_GET = array(
		'upsells'     => 'get_upsell_ids',
		'cross_sells' => 'get_cross_sell_ids',
		'children'    => 'get_children',
	);

	/**
	 * Notes collected while one item is imported.
	 *
	 * @var string[]
	 */
	private static $notes = array();

	/**
	 * Start a new item.
	 */
	public static function reset() {
		self::$notes = array();
	}

	/**
	 * Remember a note (shown in the item's result message).
	 *
	 * @param string $note Note.
	 */
	public static function note( $note ) {
		self::$notes[] = (string) $note;
	}

	/**
	 * Result-message suffix: the collected notes as separate sentences.
	 *
	 * @return string
	 */
	public static function suffix() {
		$notes = array_slice( array_values( array_unique( self::$notes ) ), 0, 12 );
		$out   = '';
		foreach ( $notes as $n ) {
			$n    = trim( $n );
			$n    = mb_strtoupper( mb_substr( $n, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $n, 1, null, 'UTF-8' );
			$out .= ' ' . ( preg_match( '/[.!?]$/', $n ) ? $n : $n . '.' );
		}
		return $out;
	}

	/**
	 * Portable reference for a product: "sku:<sku>" or "slug:<slug>".
	 *
	 * @param int $id Product ID.
	 * @return string Empty when the product is unusable.
	 */
	public static function product_ref( $id ) {
		$p = $id ? wc_get_product( (int) $id ) : null;
		if ( ! $p || $p->is_type( 'variation' ) ) {
			return '';
		}
		$sku = (string) $p->get_sku( 'edit' );
		if ( '' !== $sku ) {
			return 'sku:' . $sku;
		}
		$slug = (string) $p->get_slug( 'edit' );
		return '' !== $slug ? 'slug:' . $slug : '';
	}

	/**
	 * Resolve a product reference.
	 *
	 * @param string $ref     Reference.
	 * @param bool   $trashed Also consider trashed products.
	 * @return int 0 when not found.
	 */
	public static function product_id( $ref, $trashed = false ) {
		$ref = (string) $ref;
		if ( 0 === strpos( $ref, 'sku:' ) ) {
			$sku = substr( $ref, 4 );
			$id  = '' !== $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			if ( $id && 'product' === get_post_type( $id ) ) {
				return $id;
			}
			if ( '' === $sku || ! $trashed ) {
				return 0;
			}
			$ids = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'trash',
					'numberposts'      => 1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'meta_key'         => '_sku', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $sku, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			return $ids ? (int) $ids[0] : 0;
		}
		if ( 0 === strpos( $ref, 'slug:' ) && strlen( $ref ) > 5 ) {
			$ids = get_posts(
				array(
					'post_type'        => 'product',
					'name'             => substr( $ref, 5 ),
					'post_status'      => $trashed ? self::STATUSES : array_diff( self::STATUSES, array( 'trash' ) ),
					'numberposts'      => 1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
				)
			);
			return $ids ? (int) $ids[0] : 0;
		}
		return 0;
	}

	/**
	 * Refs for a list of product IDs (unusable ones dropped, order kept).
	 *
	 * @param int[] $ids IDs.
	 * @return string[]
	 */
	public static function product_refs( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$ref = self::product_ref( (int) $id );
			if ( '' !== $ref ) {
				$out[] = $ref;
			}
		}
		return $out;
	}

	/**
	 * Resolve product refs. Unresolved refs are reported and returned separately.
	 *
	 * @param array  $refs  Refs.
	 * @param string $label Field label for the note.
	 * @return array {ids:int[], missing:string[]}
	 */
	public static function product_ids( array $refs, $label ) {
		$ids     = array();
		$missing = array();
		foreach ( $refs as $ref ) {
			$id = is_string( $ref ) ? self::product_id( $ref ) : 0;
			if ( $id ) {
				$ids[] = $id;
			} elseif ( is_string( $ref ) && '' !== $ref ) {
				$missing[] = $ref;
			}
		}
		if ( $missing ) {
			/* translators: 1: field (upsells, cross-sells, grouped products), 2: list of product references (SKUs). */
			self::note( sprintf( __( '%1$s that do not exist on the receiving site yet will be linked on a later sync: %2$s', 'avesta-sync' ), $label, implode( ', ', array_slice( array_map( array( __CLASS__, 'plain_ref' ), $missing ), 0, 5 ) ) ) );
		}
		return array(
			'ids'     => array_values( array_unique( $ids ) ),
			'missing' => $missing,
		);
	}

	/**
	 * A product ref without its "sku:" or "slug:" prefix.
	 *
	 * @param string $ref Ref.
	 * @return string
	 */
	public static function plain_ref( $ref ) {
		return preg_replace( '/^(sku|slug):/', '', (string) $ref );
	}

	/**
	 * Slugs of the terms of a taxonomy on a product.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $tax     Taxonomy.
	 * @return string[] Sorted.
	 */
	public static function term_slugs( $post_id, $tax ) {
		$terms = taxonomy_exists( $tax ) ? wp_get_object_terms( (int) $post_id, $tax ) : array();
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = (string) $t->slug;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Term ID by taxonomy and slug.
	 *
	 * @param string $tax  Taxonomy.
	 * @param string $slug Slug.
	 * @return int
	 */
	public static function term_id( $tax, $slug ) {
		if ( '' === (string) $slug || ! taxonomy_exists( $tax ) ) {
			return 0;
		}
		$t = get_term_by( 'slug', (string) $slug, $tax );
		return $t && ! is_wp_error( $t ) ? (int) $t->term_id : 0;
	}

	/**
	 * Resolve term slugs. Unresolved slugs are reported.
	 *
	 * @param string   $tax   Taxonomy.
	 * @param string[] $slugs Slugs.
	 * @param string   $label Field label for the note.
	 * @return array {ids:int[], complete:bool}
	 */
	public static function term_ids( $tax, array $slugs, $label ) {
		$ids     = array();
		$missing = array();
		foreach ( $slugs as $slug ) {
			$id = is_string( $slug ) ? self::term_id( $tax, $slug ) : 0;
			if ( $id ) {
				$ids[] = $id;
			} elseif ( is_string( $slug ) && '' !== $slug ) {
				$missing[] = $slug;
			}
		}
		if ( $missing ) {
			/* translators: 1: field (categories, tags, brands, shipping class), 2: list of slugs. */
			self::note( sprintf( __( 'Some %1$s do not exist on the receiving site yet: %2$s. Sync categories, tags and attributes first', 'avesta-sync' ), $label, implode( ', ', array_slice( $missing, 0, 5 ) ) ) );
		}
		return array(
			'ids'      => $ids,
			'complete' => ! $missing,
		);
	}

	/**
	 * Uploads-relative file path of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function file_of( $id ) {
		return $id ? (string) get_post_meta( (int) $id, '_wp_attached_file', true ) : '';
	}

	/**
	 * Attachment ID for a file path (0 when the media row is not on this site).
	 *
	 * @param string $file Path relative to uploads.
	 * @return int
	 */
	public static function attachment_id( $file ) {
		$att = '' !== (string) $file ? Media::find( (string) $file ) : null;
		return $att ? (int) $att->ID : 0;
	}

	/**
	 * Remember a link that could not be resolved yet.
	 *
	 * @param string $holder  Ref of the product holding the link.
	 * @param string $field   upsells|cross_sells|children.
	 * @param array  $missing Missing refs.
	 */
	public static function defer( $holder, $field, array $missing ) {
		if ( ! $missing || ! isset( self::FIELD_SET[ $field ] ) ) {
			return;
		}
		$all = get_option( self::PENDING, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( $missing as $ref ) {
			$entry = $holder . '|' . $field;
			if ( strlen( (string) $ref ) > 200 || strlen( $entry ) > 400 ) {
				continue;
			}
			if ( ! isset( $all[ $ref ] ) || ( ! in_array( $entry, $all[ $ref ], true ) && count( $all[ $ref ] ) < 50 ) ) {
				$all[ $ref ][] = $entry;
			}
		}
		update_option( self::PENDING, array_slice( $all, -500, null, true ), false );
	}

	/**
	 * A product with this ref now exists: complete the links that were waiting for it.
	 *
	 * @param string $ref Product ref.
	 * @param int    $id  Product ID.
	 */
	public static function resolve_pending( $ref, $id ) {
		$all = get_option( self::PENDING, array() );
		if ( ! is_array( $all ) || empty( $all[ $ref ] ) ) {
			return;
		}
		foreach ( $all[ $ref ] as $entry ) {
			list( $holder, $field ) = array_pad( explode( '|', (string) $entry, 2 ), 2, '' );
			$hid                    = self::product_id( $holder );
			$prod                   = $hid && isset( self::FIELD_GET[ $field ] ) ? wc_get_product( $hid ) : null;
			if ( ! $prod || $hid === (int) $id ) {
				continue;
			}
			$get = self::FIELD_GET[ $field ];
			$set = self::FIELD_SET[ $field ];
			if ( 'children' === $field && ! $prod->is_type( 'grouped' ) ) {
				continue;
			}
			$ids = array_map( 'intval', (array) $prod->$get( 'edit' ) );
			if ( ! in_array( (int) $id, $ids, true ) ) {
				$ids[] = (int) $id;
				$prod->$set( $ids );
				$prod->save();
			}
		}
		unset( $all[ $ref ] );
		update_option( self::PENDING, $all, false );
	}
}
