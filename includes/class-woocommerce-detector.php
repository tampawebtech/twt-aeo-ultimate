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
}