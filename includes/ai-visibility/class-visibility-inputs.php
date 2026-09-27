<?php
/**
 * AI Visibility — the site facts the question families fill from.
 *
 * WordPress/WooCommerce stand-in for the Shopify app's `loadQuestionInputs`:
 * products, categories (the "collection" role), tags (the "type" role),
 * policies, ship-to countries — plus posts, the WordPress addition. Every
 * WooCommerce call sits behind a `class_exists`/`function_exists` guard so a
 * content-only site still gets company + post questions.
 *
 * Shape returned by `load()` (QuestionInput):
 *   [ 'site'=>['name','hosts'=>[],'brands'=>[],'x_handle','company_urls'=>[],'brand_items'=>[]],
 *     'policies'=>['refund','shipping','privacy'],
 *     'ships_to'=>[names],
 *     'collections'=>[['id','title','slug','product_ids'=>[]]],
 *     'types'=>[['name','count']],
 *     'products'=>[['id','title','brand','slug','type','options'=>[['name','values'=>[]]],'price'=>['amount','currency']|null,'available'=>bool|null,'url','is_gift_card'=>bool]],
 *     'posts'=>[['id','title','slug','url','categories'=>[],'tags'=>[],'headings'=>[],'excerpt','date']] ]
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Inputs {

	const MAX_PRODUCTS         = 100;
	const MAX_POSTS            = 100;
	const MAX_TYPES            = 50;
	const MAX_COLLECTIONS      = 50;
	const PRODUCTS_PER_CAT     = 25;
	const MAX_HEADINGS         = 5;

	private function __construct() {}

	/* ─────────────────────────── identity ─────────────────────────── */

	/**
	 * Who "we" are for the verdict: name, hosts, brands carried, X handle.
	 *
	 * @return array { name, hosts[], brands[], x_handle, company_urls[], brand_items[] }
	 */
	public static function identity() {
		$settings = get_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$hosts = array();
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $u ) {
			$h = self::host_of( $u );
			if ( '' !== $h && ! in_array( $h, $hosts, true ) ) {
				$hosts[] = $h;
			}
		}
		$alt = isset( $settings['alternate_hosts'] ) ? $settings['alternate_hosts'] : array();
		if ( is_string( $alt ) ) {
			$alt = preg_split( '/[\s,]+/', $alt );
		}
		foreach ( (array) $alt as $a ) {
			$a = strtolower( trim( (string) $a ) );
			$a = preg_replace( '#^https?://#i', '', $a );
			$a = preg_replace( '#/.*$#', '', (string) $a );
			if ( '' !== $a && ! in_array( $a, $hosts, true ) ) {
				$hosts[] = $a;
			}
		}
		$name     = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$x_handle = isset( $settings['x_handle'] ) ? ltrim( trim( (string) $settings['x_handle'] ), '@' ) : '';
		return array(
			'name'         => $name,
			'hosts'        => $hosts,
			'brands'       => self::brands( $name ),
			'x_handle'     => $x_handle,
			'company_urls' => self::company_urls( $settings, $x_handle ),
			'brand_items'  => self::brand_items( $settings ),
		);
	}

	/**
	 * Profiles the COMPANY owns off-site: whatever Company Profile already holds
	 * (it imports Yoast / Rank Math too, so most sites arrive pre-filled), the X
	 * profile implied by the handle, and anything typed into the module's own
	 * field.
	 *
	 * A channel the business does not have is simply absent — never a zero row.
	 * An empty X field means "we have no X account", which is not the same claim
	 * as "X never cites us", and the board must not blur the two.
	 *
	 * @param array  $settings Module settings.
	 * @param string $x_handle Handle without the leading @.
	 * @return string[] URLs as typed.
	 */
	public static function company_urls( array $settings = array(), $x_handle = '' ) {
		$urls = array();
		$push = function ( $u ) use ( &$urls ) {
			$u = trim( (string) $u );
			if ( '' === $u ) {
				return;
			}
			foreach ( $urls as $seen ) {
				if ( 0 === strcasecmp( $seen, $u ) ) {
					return;
				}
			}
			$urls[] = $u;
		};
		if ( class_exists( 'TWTAEO_Company_Profile' ) && method_exists( 'TWTAEO_Company_Profile', 'get_same_as' ) ) {
			foreach ( (array) TWTAEO_Company_Profile::get_same_as() as $u ) {
				$push( $u );
			}
		}
		$x_handle = ltrim( trim( (string) $x_handle ), '@' );
		if ( '' !== $x_handle && preg_match( '/^[A-Za-z0-9_]{1,15}$/', $x_handle ) ) {
			$push( 'https://x.com/' . $x_handle );
		}
		$extra = isset( $settings['company_urls'] ) ? $settings['company_urls'] : array();
		if ( is_string( $extra ) ) {
			$extra = preg_split( '/[
,]+/', $extra );
		}
		foreach ( (array) $extra as $u ) {
			$push( $u );
		}
		return $urls;
	}

	/**
	 * Brand items the merchant tracks in their own right.
	 *
	 * @param array $settings Module settings.
	 * @return array Item[].
	 */
	public static function brand_items( array $settings = array() ) {
		$raw = isset( $settings['brand_items'] ) ? $settings['brand_items'] : array();
		if ( ! class_exists( 'TWTAEO_Visibility_Brands' ) ) {
			return array();
		}
		return TWTAEO_Visibility_Brands::normalize_items( $raw );
	}

	/**
	 * Distinct brand names from the `product_brand` taxonomy (Woo 9.6+) or a
	 * brand attribute; the site's own name is excluded — a store is never a
	 * manufacturer.
	 *
	 * @param string $site_name
	 * @return string[]
	 */
	public static function brands( $site_name = '' ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$out  = array();
		$push = function ( $v ) use ( &$out, $site_name ) {
			$v = trim( wp_strip_all_tags( (string) $v ) );
			if ( '' === $v ) {
				return;
			}
			if ( '' !== $site_name && 0 === strcasecmp( $v, $site_name ) ) {
				return;
			}
			foreach ( $out as $seen ) {
				if ( 0 === strcasecmp( $seen, $v ) ) {
					return;
				}
			}
			$out[] = $v;
		};
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => true, 'number' => 100 ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$push( $t->name );
				}
			}
		}
		if ( empty( $out ) ) {
			foreach ( array( 'pa_brand', 'pa_brands', 'pa_manufacturer' ) as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 100 ) );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $t ) {
							$push( $t->name );
						}
					}
					break;
				}
			}
		}
		return $out;
	}

	/* ─────────────────────────── load ─────────────────────────────── */

	/**
	 * Everything the question families fill from.
	 *
	 * @param array $alloc Allocation (only per_collection/per_product decide how much to read).
	 * @return array QuestionInput
	 */
	public static function load( array $alloc ) {
		$identity = self::identity();
		$woo      = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );

		$input = array(
			'site'        => $identity,
			'policies'    => self::policies(),
			'ships_to'    => $woo ? self::ships_to() : array(),
			'collections' => array(),
			'types'       => array(),
			'products'    => array(),
			'posts'       => self::posts(),
		);
		if ( $woo ) {
			$input['products']    = self::products();
			$input['collections'] = self::collections();
			$input['types']       = self::types( $input['products'] );
		}
		return $input;
	}

	/**
	 * Cheap counts for the allocation bar: how many of each scope a run will
	 * actually ask about. Each count follows the same caps and exclusions as the
	 * loader that feeds build_questions(), or the arithmetic promises questions
	 * the run never asks.
	 */
	public static function catalog_counts() {
		$counts = array( 'brands' => 0, 'collections' => 0, 'types' => 0, 'products' => 0, 'posts' => 0 );
		$settings = get_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, array() );
		foreach ( self::brand_items( is_array( $settings ) ? $settings : array() ) as $item ) {
			// build_questions() skips switched-off items.
			if ( ! isset( $item['enabled'] ) || $item['enabled'] ) {
				$counts['brands']++;
			}
		}
		$posts  = wp_count_posts( 'post' );
		if ( is_object( $posts ) && isset( $posts->publish ) ) {
			$counts['posts'] = min( self::MAX_POSTS, (int) $posts->publish );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$p = wp_count_posts( 'product' );
			if ( is_object( $p ) && isset( $p->publish ) ) {
				$counts['products'] = min( self::MAX_PRODUCTS, (int) $p->publish );
			}
			if ( taxonomy_exists( 'product_cat' ) ) {
				// Same query as collections(): top MAX_COLLECTIONS, minus Uncategorized.
				$slugs = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => self::MAX_COLLECTIONS, 'orderby' => 'count', 'order' => 'DESC', 'fields' => 'id=>slug' ) );
				$counts['collections'] = is_array( $slugs ) ? count( array_diff( $slugs, array( 'uncategorized' ) ) ) : 0;
			}
			if ( taxonomy_exists( 'product_tag' ) ) {
				$n = wp_count_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => true ) );
				$counts['types'] = is_wp_error( $n ) ? 0 : min( self::MAX_TYPES, (int) $n );
			}
		}
		return $counts;
	}

	/** Dropdown choices for the results board. */
	public static function scope_options() {
		$out = array(
			array( 'value' => '', 'label' => __( 'All', 'twt-aeo-ultimate' ) ),
		);
		$woo = class_exists( 'WooCommerce' );
		if ( $woo ) {
			$out[] = array( 'value' => 'products', 'label' => __( 'All products', 'twt-aeo-ultimate' ) );
		}
		$out[] = array( 'value' => 'posts', 'label' => __( 'All posts', 'twt-aeo-ultimate' ) );
		$settings = get_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, array() );
		foreach ( self::brand_items( is_array( $settings ) ? $settings : array() ) as $item ) {
			// MUST be "brand:<scope_id>": filter_questions_for_scope() switches on
			// the part before the colon, so a bare value matches no case at all
			// and silently narrows the board to zero questions.
			$out[] = array(
				'value' => 'brand:' . TWTAEO_Visibility_Questions::brand_scope_id( $item['label'] ),
				/* translators: %s: brand name */
				'label' => sprintf( __( 'Brand: %s', 'twt-aeo-ultimate' ), $item['label'] ),
			);
		}
		if ( $woo && taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => self::MAX_COLLECTIONS, 'orderby' => 'count', 'order' => 'DESC' ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					/* translators: %s: category name */
					$out[] = array( 'value' => 'collection:' . (int) $t->term_id, 'label' => sprintf( __( 'Category: %s', 'twt-aeo-ultimate' ), $t->name ) );
				}
			}
		}
		if ( $woo && taxonomy_exists( 'product_tag' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => true, 'number' => self::MAX_TYPES, 'orderby' => 'count', 'order' => 'DESC' ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					/* translators: %s: product tag name */
					$out[] = array( 'value' => 'type:' . $t->name, 'label' => sprintf( __( 'Type: %s', 'twt-aeo-ultimate' ), $t->name ) );
				}
			}
		}
		$cats = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 50, 'orderby' => 'count', 'order' => 'DESC' ) );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				/* translators: %s: post category name */
				$out[] = array( 'value' => 'postcat:' . (int) $t->term_id, 'label' => sprintf( __( 'Post category: %s', 'twt-aeo-ultimate' ), $t->name ) );
			}
		}
		return $out;
	}

	/* ─────────────────────────── products ─────────────────────────── */

	/** @return array product rows (see class docblock) */
	public static function products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::MAX_PRODUCTS,
				'orderby' => 'modified',
				'order'   => 'DESC',
			)
		);
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$out      = array();
		foreach ( (array) $products as $p ) {
			if ( ! is_object( $p ) || ! method_exists( $p, 'get_id' ) ) {
				continue;
			}
			$out[] = self::product_row( $p, $currency );
		}
		return $out;
	}

	private static function product_row( $p, $currency ) {
		$type  = method_exists( $p, 'get_type' ) ? (string) $p->get_type() : '';
		$price = method_exists( $p, 'get_price' ) ? $p->get_price() : '';
		$price = ( '' !== $price && null !== $price && is_numeric( $price ) ) ? array( 'amount' => (string) $price, 'currency' => $currency ) : null;

		$options = array();
		if ( 'variable' === $type && method_exists( $p, 'get_variation_attributes' ) ) {
			foreach ( (array) $p->get_variation_attributes() as $name => $values ) {
				$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( (string) $name, $p ) : (string) $name;
				$vals  = array();
				foreach ( (array) $values as $v ) {
					$v = trim( (string) $v );
					if ( '' !== $v ) {
						$vals[] = $v;
					}
				}
				if ( ! empty( $vals ) ) {
					$options[] = array( 'name' => (string) $label, 'values' => $vals );
				}
			}
		} elseif ( method_exists( $p, 'get_attributes' ) ) {
			foreach ( (array) $p->get_attributes() as $name => $attr ) {
				if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_options' ) ) {
					continue;
				}
				$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( (string) $name, $p ) : (string) $name;
				$vals  = array();
				if ( method_exists( $attr, 'is_taxonomy' ) && $attr->is_taxonomy() && method_exists( $attr, 'get_terms' ) ) {
					foreach ( (array) $attr->get_terms() as $term ) {
						if ( is_object( $term ) && isset( $term->name ) ) {
							$vals[] = (string) $term->name;
						}
					}
				} else {
					foreach ( (array) $attr->get_options() as $v ) {
						$v = trim( (string) $v );
						if ( '' !== $v ) {
							$vals[] = $v;
						}
					}
				}
				if ( ! empty( $vals ) ) {
					$options[] = array( 'name' => (string) $label, 'values' => $vals );
				}
			}
		}

		$brand = '';
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $p->get_id(), 'product_brand' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$brand = (string) $terms[0]->name;
			}
		}
		if ( '' === $brand ) {
			foreach ( array( 'pa_brand', 'pa_brands', 'pa_manufacturer' ) as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_the_terms( $p->get_id(), $tax );
					if ( is_array( $terms ) && ! empty( $terms ) ) {
						$brand = (string) $terms[0]->name;
						break;
					}
				}
			}
		}

		$product_type = '';
		$tags         = get_the_terms( $p->get_id(), 'product_tag' );
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$product_type = (string) $tags[0]->name;
		}

		$is_gift = ( 'gift_card' === $type );
		if ( ! $is_gift && method_exists( $p, 'is_virtual' ) && $p->is_virtual() && is_array( $tags ) ) {
			foreach ( $tags as $t ) {
				if ( preg_match( '/gift[\s-]?card/i', (string) $t->name ) ) {
					$is_gift = true;
					break;
				}
			}
		}

		$permalink = method_exists( $p, 'get_permalink' ) ? (string) $p->get_permalink() : get_permalink( $p->get_id() );

		return array(
			'id'           => (int) $p->get_id(),
			'title'        => trim( wp_strip_all_tags( (string) $p->get_name() ) ),
			'brand'        => $brand,
			'slug'         => method_exists( $p, 'get_slug' ) ? (string) $p->get_slug() : '',
			'type'         => $product_type,
			'options'      => $options,
			'price'        => $price,
			'available'    => method_exists( $p, 'is_in_stock' ) ? (bool) $p->is_in_stock() : null,
			'url'          => (string) $permalink,
			'is_gift_card' => $is_gift,
		);
	}

	/* ─────────────────────── collections / types ──────────────────── */

	/** Product categories with at least one product, product ids capped per category. */
	public static function collections() {
		if ( ! taxonomy_exists( 'product_cat' ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => self::MAX_COLLECTIONS,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug ) {
				continue;
			}
			$ids = wc_get_products(
				array(
					'status'   => 'publish',
					'category' => array( $t->slug ),
					'limit'    => self::PRODUCTS_PER_CAT,
					'return'   => 'ids',
				)
			);
			$ids = array_values( array_map( 'intval', (array) $ids ) );
			if ( empty( $ids ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $t->term_id,
				'title'       => trim( wp_strip_all_tags( (string) $t->name ) ),
				'slug'        => (string) $t->slug,
				'product_ids' => $ids,
			);
		}
		return $out;
	}

	/** Product tags play the "product type" role: top N by count. */
	public static function types( array $products = array() ) {
		$out = array();
		if ( taxonomy_exists( 'product_tag' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_tag',
					'hide_empty' => true,
					'number'     => self::MAX_TYPES,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[] = array( 'name' => trim( wp_strip_all_tags( (string) $t->name ) ), 'count' => (int) $t->count );
				}
			}
		}
		// Any type seen on a loaded product but missing from the tag list.
		foreach ( $products as $p ) {
			$t = isset( $p['type'] ) ? trim( (string) $p['type'] ) : '';
			if ( '' === $t ) {
				continue;
			}
			$found = false;
			foreach ( $out as &$row ) {
				if ( 0 === strcasecmp( $row['name'], $t ) ) {
					$found = true;
					break;
				}
			}
			unset( $row );
			if ( ! $found ) {
				$out[] = array( 'name' => $t, 'count' => 1 );
			}
		}
		return $out;
	}

	/* ─────────────────────────── policies ─────────────────────────── */

	/** @return array { refund?, shipping?, privacy? } — plain text */
	public static function policies() {
		$out = array();

		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		$text       = self::page_text( $privacy_id );
		if ( '' !== $text ) {
			$out['privacy'] = $text;
		}

		$refund_id = class_exists( 'WooCommerce' ) ? (int) get_option( 'woocommerce_refund_returns_page_id' ) : 0;
		$text      = self::page_text( $refund_id );
		if ( '' === $text ) {
			$text = self::page_text( self::find_page_by_title( '/return|refund/i' ) );
		}
		if ( '' !== $text ) {
			$out['refund'] = $text;
		}

		$text = self::page_text( self::find_page_by_title( '/shipping|delivery/i' ) );
		if ( '' !== $text ) {
			$out['shipping'] = $text;
		}
		return $out;
	}

	private static function page_text( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return '';
		}
		$page = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return '';
		}
		return self::strip( $page->post_content );
	}

	private static function find_page_by_title( $pattern ) {
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		foreach ( (array) $pages as $p ) {
			if ( preg_match( $pattern, (string) $p->post_title ) ) {
				return (int) $p->ID;
			}
		}
		return 0;
	}

	/* ─────────────────────────── ships to ─────────────────────────── */

	/** Country names from active WooCommerce shipping zones; base country as a fallback. */
	public static function ships_to() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc        = WC();
		$countries = ( $wc && isset( $wc->countries ) && is_object( $wc->countries ) ) ? $wc->countries : null;
		$all       = ( $countries && method_exists( $countries, 'get_countries' ) ) ? (array) $countries->get_countries() : array();
		$conts     = ( $countries && method_exists( $countries, 'get_continents' ) ) ? (array) $countries->get_continents() : array();
		$codes     = array();

		$zones = WC_Shipping_Zones::get_zones();
		foreach ( (array) $zones as $zone ) {
			$methods = isset( $zone['shipping_methods'] ) ? (array) $zone['shipping_methods'] : array();
			$active  = false;
			foreach ( $methods as $m ) {
				if ( is_object( $m ) && ( ! isset( $m->enabled ) || 'yes' === $m->enabled ) ) {
					$active = true;
					break;
				}
			}
			if ( ! $active ) {
				continue;
			}
			foreach ( (array) ( isset( $zone['zone_locations'] ) ? $zone['zone_locations'] : array() ) as $loc ) {
				$type = is_object( $loc ) ? ( isset( $loc->type ) ? $loc->type : '' ) : ( isset( $loc['type'] ) ? $loc['type'] : '' );
				$code = is_object( $loc ) ? ( isset( $loc->code ) ? $loc->code : '' ) : ( isset( $loc['code'] ) ? $loc['code'] : '' );
				if ( 'country' === $type ) {
					$codes[ strtoupper( (string) $code ) ] = true;
				} elseif ( 'continent' === $type && isset( $conts[ $code ]['countries'] ) ) {
					foreach ( (array) $conts[ $code ]['countries'] as $c ) {
						$codes[ strtoupper( (string) $c ) ] = true;
					}
				} elseif ( 'state' === $type && false !== strpos( (string) $code, ':' ) ) {
					$codes[ strtoupper( substr( (string) $code, 0, strpos( (string) $code, ':' ) ) ) ] = true;
				}
			}
		}
		if ( empty( $codes ) && function_exists( 'wc_get_base_location' ) ) {
			$base = wc_get_base_location();
			if ( ! empty( $base['country'] ) ) {
				$codes[ strtoupper( (string) $base['country'] ) ] = true;
			}
		}
		$names = array();
		foreach ( array_keys( $codes ) as $code ) {
			$name = isset( $all[ $code ] ) ? html_entity_decode( (string) $all[ $code ], ENT_QUOTES, 'UTF-8' ) : $code;
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/* ─────────────────────────── posts ────────────────────────────── */

	public static function posts() {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_POSTS,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( (array) $posts as $p ) {
			$cats = array();
			foreach ( (array) get_the_terms( $p->ID, 'category' ) as $t ) {
				if ( is_object( $t ) && isset( $t->name ) && 'uncategorized' !== $t->slug ) {
					$cats[] = (string) $t->name;
				}
			}
			$tags = array();
			foreach ( (array) get_the_terms( $p->ID, 'post_tag' ) as $t ) {
				if ( is_object( $t ) && isset( $t->name ) ) {
					$tags[] = (string) $t->name;
				}
			}
			$out[] = array(
				'id'         => (int) $p->ID,
				'title'      => trim( wp_strip_all_tags( (string) $p->post_title ) ),
				'slug'       => (string) $p->post_name,
				'url'        => (string) get_permalink( $p ),
				'categories' => $cats,
				'tags'       => $tags,
				'headings'   => self::headings( $p->post_content ),
				'excerpt'    => wp_trim_words( self::strip( $p->post_content ), 40, '…' ),
				'date'       => (string) get_the_date( 'Y-m-d', $p ),
			);
		}
		return $out;
	}

	/** `<h2>…</h2>` texts, tags stripped, at most MAX_HEADINGS. */
	public static function headings( $html ) {
		$out = array();
		if ( ! preg_match_all( '#<h2\b[^>]*>(.*?)</h2>#is', (string) $html, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $h ) {
			$t = self::strip( $h );
			if ( '' !== $t ) {
				$out[] = $t;
			}
			if ( count( $out ) >= self::MAX_HEADINGS ) {
				break;
			}
		}
		return $out;
	}

	/* ─────────────────────────── helpers ──────────────────────────── */

	/** Tags/shortcodes/entities → plain text, whitespace collapsed. */
	public static function strip( $html ) {
		if ( class_exists( 'TWTAEO_Visibility_Questions' ) && method_exists( 'TWTAEO_Visibility_Questions', 'strip_html' ) ) {
			return TWTAEO_Visibility_Questions::strip_html( $html );
		}
		$text = (string) $html;
		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = wp_strip_all_tags( (string) $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	public static function host_of( $url ) {
		$h = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}
}
