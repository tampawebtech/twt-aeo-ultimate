<?php
/**
 * WooCommerce Detector
 *
 * Scans WooCommerce products for schema presence and intent classification.
 * Only runs when WooCommerce is active. Detects Product and Service schema
 * from Rank Math, Yoast, SASWP, and WooCommerce's own structured data.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_WooCommerce_Detector {

	/**
	 * Postmeta key for storing the WooCommerce scan result.
	 */
	const META_WC_SCAN = '_twtaeo_wc_scan';

	/**
	 * Register hooks — only when WooCommerce is active.
	 */
	public static function register_hooks() {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}
		add_action( 'save_post_product', array( __CLASS__, 'on_save_product' ), 20, 2 );
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Fires on save_post_product — rescans the product.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_product( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( $post->post_status !== 'publish' ) {
			return;
		}

		$result = self::scan( $post );
		update_post_meta( $post_id, self::META_WC_SCAN, $result );
	}

	/**
	 * Scan a single product.
	 *
	 * @param WP_Post|int $post
	 * @return array {
	 *   product_type: string,
	 *   intent: string,
	 *   has_product_schema: bool,
	 *   has_service_schema: bool,
	 *   schema_source: string,
	 *   needs_schema: bool,
	 *   recommended_schema: string,
	 *   signals: string[]
	 * }
	 */
	public static function scan( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post || $post->post_type !== 'product' ) {
			return self::empty_result();
		}

		$signals            = array();
		$has_product_schema = false;
		$has_service_schema = false;
		$schema_source      = 'none';
		$product_type       = self::get_product_type( $post->ID );

		// Determine intent — service vs physical product.
		$intent = self::classify_product_intent( $post );
		$signals[] = 'Product intent: ' . $intent;

		// Schema detection.
		$schema_data = self::detect_product_schema( $post );
		$has_product_schema = $schema_data['has_product'];
		$has_service_schema = $schema_data['has_service'];
		$schema_source      = $schema_data['source'];

		if ( $has_product_schema ) {
			$signals[] = 'Product schema detected via ' . $schema_source;
		}
		if ( $has_service_schema ) {
			$signals[] = 'Service schema detected via ' . $schema_source;
		}

		// Determine what schema is recommended based on intent.
		$recommended_schema = ( $intent === 'service' ) ? 'Service' : 'Product';

		$has_recommended = ( $intent === 'service' ) ? $has_service_schema : $has_product_schema;
		$needs_schema    = ! $has_recommended;

		if ( $needs_schema ) {
			$signals[] = $recommended_schema . ' schema missing';
		}

		return array(
			'product_type'       => $product_type,
			'intent'             => $intent,
			'has_product_schema' => $has_product_schema,
			'has_service_schema' => $has_service_schema,
			'schema_source'      => $schema_source,
			'needs_schema'       => $needs_schema,
			'recommended_schema' => $recommended_schema,
			'signals'            => $signals,
		);
	}

	/**
	 * Scan all published WooCommerce products.
	 *
	 * @return array[] Each entry: { post, wc_data }
	 */
	public static function scan_all() {
		if ( ! self::is_woocommerce_active() ) {
			return array();
		}

		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
		) );

		$results = array();

		foreach ( $products as $product ) {
			$wc_data   = self::scan( $product );
			$results[] = array(
				'post'    => $product,
				'wc_data' => $wc_data,
			);
		}

		return $results;
	}

	/**
	 * Get summary counts.
	 *
	 * @return array { total, needs_schema, has_schema, service_intent, product_intent }
	 */
	public static function get_summary() {
		$all            = self::scan_all();
		$needs          = 0;
		$has            = 0;
		$service_intent = 0;
		$product_intent = 0;

		foreach ( $all as $item ) {
			if ( $item['wc_data']['needs_schema'] ) {
				$needs++;
			} else {
				$has++;
			}
			if ( $item['wc_data']['intent'] === 'service' ) {
				$service_intent++;
			} else {
				$product_intent++;
			}
		}

		return array(
			'total'          => count( $all ),
			'needs_schema'   => $needs,
			'has_schema'     => $has,
			'service_intent' => $service_intent,
			'product_intent' => $product_intent,
		);
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Get the WooCommerce product type (simple, variable, etc).
	 *
	 * @param int $post_id
	 * @return string
	 */
	private static function get_product_type( $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'simple';
		}
		$product = wc_get_product( $post_id );
		return $product ? $product->get_type() : 'simple';
	}

	/**
	 * Classify whether a product is service-oriented or a physical product.
	 * Uses page intent classifier then falls back to keyword scan.
	 *
	 * @param WP_Post $post
	 * @return string service|product
	 */
	private static function classify_product_intent( WP_Post $post ) {
		// Use the page intent classifier first.
		$intent = TWTAEO_Page_Intent::classify( $post );
		if ( in_array( $intent['intent'], array( 'service', 'product_service' ), true ) ) {
			return 'service';
		}

		// Fall back to keyword scan on title and description.
		$service_keywords = array(
			'service', 'repair', 'installation', 'maintenance',
			'consulting', 'support', 'subscription', 'plan',
			'setup', 'training', 'inspection', 'cleaning',
		);

		$text = strtolower( $post->post_title . ' ' . wp_strip_all_tags( $post->post_content ) );
		foreach ( $service_keywords as $kw ) {
			if ( strpos( $text, $kw ) !== false ) {
				return 'service';
			}
		}

		return 'product';
	}

	/**
	 * Detect Product and Service schema from all available sources.
	 *
	 * @param WP_Post $post
	 * @return array { has_product: bool, has_service: bool, source: string }
	 */
	private static function detect_product_schema( WP_Post $post ) {
		$has_product = false;
		$has_service = false;
		$source      = 'none';

		$product_types = array( 'Product' );
		$service_types = array( 'Service', 'LocalBusiness', 'ProfessionalService' );

		// Strategy 1: Stored scan from main scan store.
		$stored = get_post_meta( $post->ID, TWTAEO_Scan_Store::META_SCHEMA, true );
		if ( is_array( $stored ) ) {
			$types = $stored['schema_types'] ?? array();
			foreach ( $types as $type ) {
				if ( in_array( $type, $product_types, true ) ) { $has_product = true; }
				if ( in_array( $type, $service_types, true ) ) { $has_service = true; }
			}
			if ( $has_product || $has_service ) {
				$source = $stored['source'] ?? 'stored scan';
			}
		}

		// Strategy 2: Rank Math postmeta.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$meta = get_post_meta( $post->ID );
			foreach ( $meta as $key => $values ) {
				if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
					foreach ( $values as $value ) {
						$data = maybe_unserialize( $value );
						if ( is_array( $data ) && isset( $data['@type'] ) ) {
							$found = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
							foreach ( $found as $t ) {
								if ( in_array( $t, $product_types, true ) ) { $has_product = true; $source = 'Rank Math'; }
								if ( in_array( $t, $service_types, true ) ) { $has_service = true; $source = 'Rank Math'; }
							}
						}
					}
				}
			}
		}

		// Strategy 3: Yoast postmeta.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$page_type = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
			if ( in_array( $page_type, $product_types, true ) ) { $has_product = true; $source = 'Yoast SEO'; }
			if ( in_array( $page_type, $service_types, true ) ) { $has_service = true; $source = 'Yoast SEO'; }
		}

		// Strategy 4: SASWP custom schema field.
		$saswp = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
		if ( ! empty( $saswp ) ) {
			$data = json_decode( $saswp, true );
			if ( is_array( $data ) ) {
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					$types = isset( $item['@type'] )
						? ( is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] ) )
						: array();
					foreach ( $types as $t ) {
						if ( in_array( $t, $product_types, true ) ) { $has_product = true; $source = 'SASWP'; }
						if ( in_array( $t, $service_types, true ) ) { $has_service = true; $source = 'SASWP'; }
					}
				}
			}
		}

		// Strategy 5: WooCommerce own structured data.
		// WooCommerce outputs Product schema natively via its structured data class.
		if ( class_exists( 'WC_Structured_Data' ) ) {
			$has_product = true;
			if ( $source === 'none' ) {
				$source = 'WooCommerce';
			}
		}

		// Strategy 6: Inline JSON-LD in post content.
		$content = $post->post_content ?? '';
		foreach ( $product_types as $type ) {
			if ( strpos( $content, '"' . $type . '"' ) !== false ) {
				$has_product = true;
				if ( $source === 'none' ) $source = 'inline JSON-LD';
			}
		}
		foreach ( $service_types as $type ) {
			if ( strpos( $content, '"' . $type . '"' ) !== false ) {
				$has_service = true;
				if ( $source === 'none' ) $source = 'inline JSON-LD';
			}
		}

		return array(
			'has_product' => $has_product,
			'has_service' => $has_service,
			'source'      => $source,
		);
	}

	/**
	 * Return an empty/default scan result.
	 */
	private static function empty_result() {
		return array(
			'product_type'       => '',
			'intent'             => 'product',
			'has_product_schema' => false,
			'has_service_schema' => false,
			'schema_source'      => 'none',
			'needs_schema'       => true,
			'recommended_schema' => 'Product',
			'signals'            => array(),
		);
	}

	// ── Rich Product schema: detection ─────────────────────────────────────────

	/**
	 * Option keys for site-wide shipping / return defaults.
	 */
	const OPTION_SHIPPING = 'twtaeo_wc_shipping_defaults';
	const OPTION_RETURN   = 'twtaeo_wc_return_defaults';

	/**
	 * Postmeta keys for manually-picked product relationships (arrays of IDs).
	 */
	const META_SIMILAR   = '_twtaeo_wc_similar';
	const META_ACCESSORY = '_twtaeo_wc_accessory';

	/** Postmeta key for resolved brand sameAs authority URLs. */
	const META_SAMEAS = '_twtaeo_ai_brand_sameas';

	/**
	 * Detect product attributes that map to dedicated schema fields.
	 *
	 * Color / material / size / brand are pulled out as first-class fields; every
	 * other visible attribute becomes an additionalProperty key/value pair.
	 *
	 * @param WC_Product $product
	 * @return array { brand:string, color:string, material:string, size:string, additional:array[], varies:string[] }
	 */
	public static function detect_attributes( $product ) {
		$out = array(
			'brand'      => '',
			'color'      => '',
			'material'   => '',
			'size'       => '',
			'additional' => array(),
			// Mapped keys (color/size/material) that are WooCommerce variation
			// attributes — distinct buying options, not a parent-level value.
			'varies'     => array(),
		);
		if ( ! $product || ! method_exists( $product, 'get_attributes' ) ) {
			return $out;
		}

		$product_id = $product->get_id();
		$map_keys   = array( 'color', 'colour', 'material', 'size', 'brand' );

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}
			$raw_name = $attribute->get_name();
			$label    = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $raw_name ) : $raw_name;

			// Resolve the displayed value(s).
			if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ) {
				$terms = wc_get_product_terms( $product_id, $raw_name, array( 'fields' => 'names' ) );
				$value = is_array( $terms ) ? implode( ', ', $terms ) : '';
			} else {
				$opts  = method_exists( $attribute, 'get_options' ) ? $attribute->get_options() : array();
				$value = is_array( $opts ) ? implode( ', ', array_map( 'wp_strip_all_tags', $opts ) ) : '';
			}
			$value = trim( $value );
			if ( $value === '' ) {
				continue;
			}

			// Normalize: strip the pa_ taxonomy prefix, lowercase.
			$norm = strtolower( preg_replace( '/^pa_/', '', $raw_name ) );

			if ( in_array( $norm, $map_keys, true ) ) {
				if ( $norm === 'colour' ) {
					$norm = 'color';
				}
				if ( $out[ $norm ] === '' ) {
					$out[ $norm ] = $value;
				}
				// Flag variation attributes so the parent schema can use variesBy
				// instead of a comma-joined "Black, Purple, Teal" string.
				if ( method_exists( $attribute, 'get_variation' ) && $attribute->get_variation()
					&& in_array( $norm, array( 'color', 'size', 'material' ), true )
					&& ! in_array( $norm, $out['varies'], true ) ) {
					$out['varies'][] = $norm;
				}
			} else {
				$out['additional'][] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		// Brand fallback: dedicated brand taxonomies if no brand attribute was found.
		if ( $out['brand'] === '' ) {
			foreach ( array( 'product_brand', 'pwb-brand', 'pa_brand' ) as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$terms = get_the_terms( $product_id, $tax );
				if ( is_array( $terms ) && ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$names = wp_list_pluck( $terms, 'name' );
					$out['brand'] = implode( ', ', $names );
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Detect product identifiers (GTIN / MPN).
	 *
	 * @param WC_Product $product
	 * @return array { gtin:string, mpn:string }
	 */
	public static function detect_identifiers( $product ) {
		$out = array( 'gtin' => '', 'mpn' => '' );
		if ( ! $product ) {
			return $out;
		}
		$product_id = $product->get_id();

		// GTIN: WooCommerce 9.2+ stores a global unique id (GTIN/UPC/EAN/ISBN).
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$out['gtin'] = (string) $product->get_global_unique_id();
		}
		if ( $out['gtin'] === '' ) {
			foreach ( array( '_gtin', '_wpm_gtin_code', '_woosea_gtin' ) as $key ) {
				$val = get_post_meta( $product_id, $key, true );
				if ( $val !== '' && $val !== false ) {
					$out['gtin'] = (string) $val;
					break;
				}
			}
		}

		foreach ( array( '_mpn', '_wpm_mpn_code' ) as $key ) {
			$val = get_post_meta( $product_id, $key, true );
			if ( $val !== '' && $val !== false ) {
				$out['mpn'] = (string) $val;
				break;
			}
		}

		return $out;
	}

	/**
	 * Detect product relationships — variations and cross-sells.
	 *
	 * @param WC_Product $product
	 * @return array { is_variant_of:array|null, related:array[] }
	 */
	public static function detect_relationships( $product ) {
		$out = array( 'is_variant_of' => null, 'related' => array() );
		if ( ! $product ) {
			return $out;
		}

		// Variable products → ProductGroup with hasVariant.
		if ( $product->get_type() === 'variable' && method_exists( $product, 'get_children' ) ) {
			$variants = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) {
					continue;
				}
				$variant = array(
					'@type' => 'Product',
					'name'  => html_entity_decode( $child->get_name(), ENT_QUOTES, 'UTF-8' ),
					'url'   => get_permalink( $child_id ),
				);
				$sku = $child->get_sku();
				if ( $sku ) {
					$variant['sku'] = $sku;
				}
				$img_id = $child->get_image_id();
				if ( $img_id ) {
					$variant['image'] = wp_get_attachment_url( $img_id );
				}
				// Variation attributes (e.g. color / size).
				$attrs = $child->get_attributes();
				foreach ( $attrs as $key => $val ) {
					$norm = strtolower( preg_replace( '/^pa_/', '', $key ) );
					if ( $norm === 'colour' ) {
						$norm = 'color';
					}
					if ( in_array( $norm, array( 'color', 'size', 'material' ), true ) && $val ) {
						$variant[ $norm ] = wc_attribute_label( $val );
					}
				}
				$price = $child->get_price();
				if ( $price !== '' && $price !== false ) {
					$variant['offers'] = array(
						'@type'         => 'Offer',
						'price'         => (string) $price,
						'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
						'availability'  => ( $child->get_stock_status() === 'instock' )
							? 'https://schema.org/InStock'
							: 'https://schema.org/OutOfStock',
						'url'           => get_permalink( $child_id ),
					);
				}
				$variants[] = $variant;
			}

			if ( ! empty( $variants ) ) {
				$group = array(
					'@type'          => 'ProductGroup',
					'name'           => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
					'url'            => get_permalink( $product->get_id() ),
					'productGroupID' => $product->get_sku() ?: (string) $product->get_id(),
					'hasVariant'     => $variants,
				);
				$out['is_variant_of'] = $group;
			}
		}

		// Cross-sells → isRelatedTo.
		$related_ids = array();
		if ( method_exists( $product, 'get_cross_sell_ids' ) ) {
			$related_ids = $product->get_cross_sell_ids();
		}
		if ( empty( $related_ids ) && function_exists( 'wc_get_related_products' ) ) {
			$related_ids = wc_get_related_products( $product->get_id(), 4 );
		}
		foreach ( (array) $related_ids as $rid ) {
			$ref = self::product_ref( $rid );
			if ( $ref ) {
				$out['related'][] = $ref;
			}
		}

		return $out;
	}

	/**
	 * Build a lightweight Product reference for a related/similar product.
	 *
	 * @param int $product_id
	 * @return array|null
	 */
	public static function product_ref( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return null;
		}
		$post = get_post( $product_id );
		if ( ! $post || $post->post_type !== 'product' || $post->post_status !== 'publish' ) {
			return null;
		}
		return array(
			'@type' => 'Product',
			'name'  => html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $product_id ),
		);
	}

	/**
	 * Detect aggregate rating + individual reviews from WooCommerce reviews.
	 *
	 * @param int        $post_id
	 * @param WC_Product $product
	 * @return array { aggregate:array|null, reviews:array[] }
	 */
	public static function detect_reviews( $post_id, $product ) {
		$out = array( 'aggregate' => null, 'reviews' => array() );
		if ( ! $product ) {
			return $out;
		}

		if ( method_exists( $product, 'get_rating_count' ) && $product->get_rating_count() > 0 ) {
			$out['aggregate'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $product->get_average_rating(),
				'reviewCount' => (int) $product->get_review_count(),
				'bestRating'  => '5',
			);
		}

		$comments = get_comments( array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => 10,
		) );

		foreach ( $comments as $comment ) {
			$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( ! $rating ) {
				continue;
			}
			$out['reviews'][] = array(
				'@type'         => 'Review',
				'author'        => array( '@type' => 'Person', 'name' => $comment->comment_author ),
				'datePublished' => mysql2date( 'c', $comment->comment_date_gmt ),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating,
					'bestRating'  => '5',
				),
				'reviewBody'    => wp_strip_all_tags( $comment->comment_content ),
			);
		}

		return $out;
	}

	// ── Site-wide shipping / return defaults ────────────────────────────────────

	/**
	 * Get site-wide shipping defaults merged over hardcoded fallbacks.
	 *
	 * @return array
	 */
	public static function get_shipping_defaults() {
		$defaults = array(
			'enabled'       => false,
			'country'       => 'US',
			'rate_type'     => 'flat', // flat | free
			'rate'          => '',
			'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'handling_min'  => 0,
			'handling_max'  => 1,
			'transit_min'   => 1,
			'transit_max'   => 5,
		);
		$saved = get_option( self::OPTION_SHIPPING, array() );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * Get site-wide return-policy defaults merged over hardcoded fallbacks.
	 *
	 * @return array
	 */
	public static function get_return_defaults() {
		$defaults = array(
			'enabled' => false,
			'country' => 'US',
			'days'    => 30,
			'fees'    => 'free',           // free | paid
			'method'  => 'https://schema.org/ReturnByMail',
		);
		$saved = get_option( self::OPTION_RETURN, array() );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * Build an OfferShippingDetails fragment from site defaults.
	 *
	 * @return array|null
	 */
	public static function build_shipping_details() {
		$d = self::get_shipping_defaults();
		if ( empty( $d['enabled'] ) ) {
			return null;
		}

		$rate_value = ( $d['rate_type'] === 'free' ) ? '0' : (string) $d['rate'];

		return array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => $rate_value,
				'currency' => $d['currency'],
			),
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $d['country'],
			),
			'deliveryTime'        => array(
				'@type'        => 'ShippingDeliveryTime',
				'handlingTime' => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => (int) $d['handling_min'],
					'maxValue' => (int) $d['handling_max'],
					'unitCode' => 'DAY',
				),
				'transitTime'  => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => (int) $d['transit_min'],
					'maxValue' => (int) $d['transit_max'],
					'unitCode' => 'DAY',
				),
			),
		);
	}

	/**
	 * Build a MerchantReturnPolicy fragment from site defaults.
	 *
	 * @return array|null
	 */
	public static function build_return_policy() {
		$d = self::get_return_defaults();
		if ( empty( $d['enabled'] ) ) {
			return null;
		}

		return array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $d['country'],
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'   => (int) $d['days'],
			'returnMethod'         => $d['method'],
			'returnFees'           => ( $d['fees'] === 'free' )
				? 'https://schema.org/FreeReturn'
				: 'https://schema.org/ReturnFeesCustomerResponsibility',
		);
	}

	// ── Central Product schema assembler ────────────────────────────────────────

	/**
	 * Assemble the full rich Product schema for a product.
	 *
	 * Auto-detects attributes, identifiers, relationships, reviews, and merges
	 * site-wide shipping/return defaults. Scalar values present in $overrides
	 * (edited in the modal) win over auto-detected values.
	 *
	 * @param int   $post_id
	 * @param array $overrides Optional edited values + manual relationship IDs.
	 * @return array|null
	 */
	public static function build_product_schema( $post_id, array $overrides = array() ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return null;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;

		$url       = get_permalink( $post_id );
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$currency  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		// Auto-detected base values.
		$name        = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$description = '';
		$image       = '';
		$sku         = '';
		$price       = '';
		$availability = 'https://schema.org/InStock';

		if ( $product ) {
			$short        = wp_strip_all_tags( $product->get_short_description() );
			$description  = $short ?: wp_strip_all_tags( $post->post_content );
			$sku          = $product->get_sku();
			$price        = $product->get_price();
			$availability = ( $product->get_stock_status() === 'instock' )
				? 'https://schema.org/InStock'
				: 'https://schema.org/OutOfStock';
			$img_id = $product->get_image_id();
			if ( $img_id ) {
				$image = wp_get_attachment_url( $img_id );
			}
		}
		if ( ! $image ) {
			$image = get_the_post_thumbnail_url( $post_id, 'full' ) ?: '';
		}

		$attrs       = self::detect_attributes( $product );
		$ids         = self::detect_identifiers( $product );
		$rel         = self::detect_relationships( $product );
		$is_variable = ! empty( $rel['is_variant_of'] );

		// Resolve scalar fields: override wins when provided non-empty.
		$pick = function( $key, $fallback ) use ( $overrides ) {
			if ( array_key_exists( $key, $overrides ) ) {
				$v = is_string( $overrides[ $key ] ) ? trim( $overrides[ $key ] ) : $overrides[ $key ];
				if ( $v !== '' && $v !== null ) {
					return $v;
				}
			}
			return $fallback;
		};

		$name         = $pick( 'name', $name );
		$description  = $pick( 'description', $description );
		$image        = $pick( 'image', $image );
		$sku          = $pick( 'sku', $sku );
		$price        = $pick( 'price', $price );
		$currency     = $pick( 'currency', $currency );
		$availability = $pick( 'availability', $availability );
		$brand        = $pick( 'brand', $attrs['brand'] );
		$color        = $pick( 'color', $attrs['color'] );
		$material     = $pick( 'material', $attrs['material'] );
		$size         = $pick( 'size', $attrs['size'] );
		$gtin         = $pick( 'gtin', $ids['gtin'] );
		$mpn          = $pick( 'mpn', $ids['mpn'] );
		$condition    = $pick( 'item_condition', 'https://schema.org/NewCondition' );

		// For a variable product, a variation attribute (e.g. Color with options
		// "Black, Purple, Teal") describes distinct buying options, not one parent
		// value. Declare it via the ProductGroup's variesBy and drop the ambiguous
		// comma-joined string from the parent — unless the merchant set one in the
		// modal.
		$varies_by = array();
		if ( $is_variable && ! empty( $attrs['varies'] ) ) {
			$prop_url = array(
				'color'    => 'https://schema.org/color',
				'size'     => 'https://schema.org/size',
				'material' => 'https://schema.org/material',
			);
			$flat = array( 'color' => &$color, 'size' => &$size, 'material' => &$material );
			foreach ( $attrs['varies'] as $k ) {
				$override_set = array_key_exists( $k, $overrides )
					&& is_string( $overrides[ $k ] ) && trim( $overrides[ $k ] ) !== '';
				if ( $override_set || ! isset( $flat[ $k ] ) ) {
					continue;
				}
				$flat[ $k ] = '';
				$varies_by[] = $prop_url[ $k ];
			}
			unset( $flat );
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $name,
			'url'      => $url,
		);
		if ( $description ) {
			$schema['description'] = $description;
		}
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $sku ) {
			$schema['sku'] = $sku;
		}
		if ( $brand ) {
			$brand_obj = array( '@type' => 'Brand', 'name' => $brand );
			// sameAs: override (modal save) wins, else stored meta so the bulk path
			// keeps previously-resolved authority links.
			$same_as = array_key_exists( 'same_as', $overrides )
				? $overrides['same_as']
				: get_post_meta( $post_id, self::META_SAMEAS, true );
			$same_as = self::clean_url_list( $same_as );
			if ( ! empty( $same_as ) ) {
				$brand_obj['sameAs'] = $same_as;
			}
			$schema['brand'] = $brand_obj;
		}
		if ( $color ) {
			$schema['color'] = $color;
		}
		if ( $material ) {
			$schema['material'] = $material;
		}
		if ( $size ) {
			$schema['size'] = $size;
		}
		if ( $gtin ) {
			$schema[ self::gtin_key( $gtin ) ] = $gtin;
		}
		if ( $mpn ) {
			$schema['mpn'] = $mpn;
		}
		if ( $condition ) {
			$schema['itemCondition'] = $condition;
		}

		// additionalProperty: overrides (modal rows) win over detected.
		$additional = array();
		if ( array_key_exists( 'additional', $overrides ) && is_array( $overrides['additional'] ) ) {
			$additional = $overrides['additional'];
		} else {
			$additional = $attrs['additional'];
		}
		$prop_list = array();
		foreach ( $additional as $row ) {
			$pname = isset( $row['name'] ) ? trim( $row['name'] ) : '';
			$pval  = isset( $row['value'] ) ? trim( $row['value'] ) : '';
			if ( $pname === '' || $pval === '' ) {
				continue;
			}
			$prop_list[] = array( '@type' => 'PropertyValue', 'name' => $pname, 'value' => $pval );
		}
		if ( ! empty( $prop_list ) ) {
			$schema['additionalProperty'] = $prop_list;
		}

		// Relationships ( $rel resolved above ).
		if ( $rel['is_variant_of'] ) {
			$group = $rel['is_variant_of'];
			if ( ! empty( $varies_by ) ) {
				$group['variesBy'] = $varies_by;
			}
			$schema['isVariantOf'] = $group;
		}
		if ( ! empty( $rel['related'] ) ) {
			$schema['isRelatedTo'] = $rel['related'];
		}
		// Manual picks: use override when provided (modal save), else fall back to
		// stored meta so the bulk path also includes manual relationships.
		$similar_ids = array_key_exists( 'is_similar_to', $overrides )
			? $overrides['is_similar_to']
			: get_post_meta( $post_id, self::META_SIMILAR, true );
		$similar = self::resolve_refs( $similar_ids );
		if ( ! empty( $similar ) ) {
			$schema['isSimilarTo'] = $similar;
		}
		$accessory_ids = array_key_exists( 'is_accessory_for', $overrides )
			? $overrides['is_accessory_for']
			: get_post_meta( $post_id, self::META_ACCESSORY, true );
		$accessory = self::resolve_refs( $accessory_ids );
		if ( ! empty( $accessory ) ) {
			$schema['isAccessoryOrSparePartFor'] = $accessory;
		}

		// Trust — aggregate rating + reviews (unless explicitly excluded).
		$include_reviews = ! array_key_exists( 'include_reviews', $overrides ) || ! empty( $overrides['include_reviews'] );
		if ( $include_reviews ) {
			$reviews = self::detect_reviews( $post_id, $product );
			if ( $reviews['aggregate'] ) {
				$schema['aggregateRating'] = $reviews['aggregate'];
			}
			if ( ! empty( $reviews['reviews'] ) ) {
				$schema['review'] = $reviews['reviews'];
			}
		}

		// Offer + logistics.
		if ( $price !== '' && $price !== false && $price !== null ) {
			$offer = array(
				'@type'         => 'Offer',
				'price'         => (string) $price,
				'priceCurrency' => $currency,
				'availability'  => $availability,
				'url'           => $url,
			);
			$shipping = self::build_shipping_details();
			if ( $shipping ) {
				$offer['shippingDetails'] = $shipping;
			}
			$returns = self::build_return_policy();
			if ( $returns ) {
				$offer['hasMerchantReturnPolicy'] = $returns;
			}
			$schema['offers'] = $offer;
		}

		$schema['seller'] = array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url );

		return $schema;
	}

	/**
	 * Choose the most specific GTIN key based on digit length.
	 *
	 * @param string $gtin
	 * @return string
	 */
	private static function gtin_key( $gtin ) {
		$len = strlen( preg_replace( '/\D/', '', (string) $gtin ) );
		switch ( $len ) {
			case 8:
				return 'gtin8';
			case 12:
				return 'gtin12';
			case 13:
				return 'gtin13';
			case 14:
				return 'gtin14';
			default:
				return 'gtin';
		}
	}

	/**
	 * Resolve an array of product IDs into Product reference objects.
	 *
	 * @param array $ids
	 * @return array[]
	 */
	private static function resolve_refs( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$ref = self::product_ref( $id );
			if ( $ref ) {
				$out[] = $ref;
			}
		}
		return $out;
	}

	/**
	 * Sanitize + dedupe a list of URLs (used for brand sameAs).
	 *
	 * @param mixed $urls
	 * @return string[]
	 */
	private static function clean_url_list( $urls ) {
		$out = array();
		foreach ( (array) $urls as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( '' !== $u ) {
				$out[] = $u;
			}
		}
		return array_values( array_unique( $out ) );
	}

	// ── Schema optimization score ────────────────────────────────────────────────

	/**
	 * Score how rich the saved Product schema is across the signals that matter
	 * for rich results and AI shopping agents.
	 *
	 * @param int $post_id
	 * @return array { has_schema:bool, score:int, label:string, color:string, missing:string[] }
	 */
	public static function schema_optimization( $post_id ) {
		$schema = TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, 'Product' );
		$offer  = is_array( $schema ) && isset( $schema['offers'] ) && is_array( $schema['offers'] ) ? $schema['offers'] : array();

		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return array(
				'has_schema' => false,
				'score'      => 0,
				'label'      => __( 'Not set', 'twt-aeo-ultimate' ),
				'color'      => '#9ca3af',
				'missing'    => array(),
			);
		}

		$checks = array(
			'Image'         => ! empty( $schema['image'] ),
			'Brand'         => ! empty( $schema['brand'] ),
			'Attributes'    => ! empty( $schema['color'] ) || ! empty( $schema['material'] )
				|| ! empty( $schema['size'] ) || ! empty( $schema['additionalProperty'] ),
			'Identifier'    => ! empty( $schema['gtin'] ) || ! empty( $schema['gtin8'] ) || ! empty( $schema['gtin12'] )
				|| ! empty( $schema['gtin13'] ) || ! empty( $schema['gtin14'] ) || ! empty( $schema['mpn'] ),
			'Offer'         => ! empty( $offer ),
			'Shipping'      => ! empty( $offer['shippingDetails'] ),
			'Returns'       => ! empty( $offer['hasMerchantReturnPolicy'] ),
			'Reviews'       => ! empty( $schema['aggregateRating'] ) || ! empty( $schema['review'] ),
			'Relationships' => ! empty( $schema['isVariantOf'] ) || ! empty( $schema['isRelatedTo'] )
				|| ! empty( $schema['isSimilarTo'] ) || ! empty( $schema['isAccessoryOrSparePartFor'] ),
		);

		$total   = count( $checks );
		$present = count( array_filter( $checks ) );
		$score   = (int) round( $present / $total * 100 );
		$missing = array_keys( array_filter( $checks, function( $v ) { return ! $v; } ) );

		if ( $score >= 100 ) {
			$label = __( 'Complete', 'twt-aeo-ultimate' ); $color = '#16a34a';
		} elseif ( $score >= 70 ) {
			$label = __( 'Strong', 'twt-aeo-ultimate' );   $color = '#16a34a';
		} elseif ( $score >= 40 ) {
			$label = __( 'Good', 'twt-aeo-ultimate' );     $color = '#0ea5e9';
		} else {
			$label = __( 'Basic', 'twt-aeo-ultimate' );    $color = '#f59e0b';
		}

		return array(
			'has_schema' => true,
			'score'      => $score,
			'label'      => $label,
			'color'      => $color,
			'missing'    => $missing,
		);
	}

	/**
	 * Render the optimization score as a small badge (escaped, safe to echo).
	 *
	 * @param array $opt Result of schema_optimization().
	 * @return string
	 */
	public static function optimization_badge_html( $opt ) {
		if ( empty( $opt['has_schema'] ) ) {
			return '<span class="twt-aeo-badge" style="background:rgba(156,163,175,.12);color:#6b7280;">'
				. esc_html__( 'Not set', 'twt-aeo-ultimate' ) . '</span>';
		}
		$title = empty( $opt['missing'] )
			? __( 'All key schema signals present', 'twt-aeo-ultimate' )
			/* translators: %s: comma-separated list of missing schema signals. */
			: sprintf( __( 'Missing: %s', 'twt-aeo-ultimate' ), implode( ', ', $opt['missing'] ) );

		return sprintf(
			'<span class="twt-aeo-badge" title="%1$s" style="background:%2$s;color:%3$s;">%4$s &middot; %5$d%%</span>',
			esc_attr( $title ),
			esc_attr( self::tint( $opt['color'] ) ),
			esc_attr( $opt['color'] ),
			esc_html( $opt['label'] ),
			(int) $opt['score']
		);
	}

	/**
	 * Low-opacity background tint for a hex colour used in badges.
	 */
	private static function tint( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( strlen( $hex ) !== 6 ) {
			return 'rgba(107,114,128,.12)';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return "rgba($r,$g,$b,.12)";
	}
}