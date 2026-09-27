<?php
/**
 * Conditional pricing on the Offer — and the visible page content that earns it.
 *
 * Two halves that are not separable:
 *
 *   1. `priceSpecification` entries on the product's Offer, each carrying a
 *      machine-readable condition (`eligibleQuantity` for a quantity break,
 *      `validForMemberTier` for a membership price), plus the `MemberProgram`
 *      node on the Organization that the tier references resolve to.
 *   2. A visible quantity-break table and, optionally, a visible note naming the
 *      coupons that apply — because marked-up content with no visible counterpart
 *      is a structured-data policy violation, not a cosmetic mismatch.
 *
 * Every emit path is gated on the corresponding block being on the page. That is
 * the same gate the Local Store module built after shipping a Store node and a
 * whole FAQPage onto WooCommerce's coming-soon holding screen.
 *
 * ── What is deliberately never written ────────────────────────────────────────
 * `Offer.price` is not touched. Nor is `priceValidUntil`, which the WooCommerce
 * detector already derives from scheduled sale dates: a coupon expiring does not
 * make the shelf price stale, and overwriting it with a coupon's end date would
 * tell Google the price expires when it does not.
 *
 * `discountCode` is not written either. It is not an `Offer` property — schema.org
 * defines it on `Order` alone, and there is an open proposal (schemaorg#1742) for a
 * `DiscountOffer` type precisely because no on-page promo-code vocabulary exists.
 * Publishing it would validate as an unknown property and do nothing, while looking
 * in the source like a working feature. Coupon codes go to Merchant Center instead,
 * via TWTAEO_Promotions_Feed, which is the channel Google actually built for them.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Offer_Pricing {

	/** UN/CEFACT code for a countable unit ("piece"). */
	const UNIT_PIECE = 'C62';

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public static function register_hooks() {
		// The Offer is a nested value of the Product node, out of reach of the node
		// filter — see the `twtaeo_product_schema` docblock in the detector. Priority
		// 15 so the Local Store module (10) has already attached fulfilment.
		add_filter( 'twtaeo_product_schema', array( __CLASS__, 'add_price_specifications' ), 15, 3 );

		// MemberProgram hangs off the Organization. Priority 30: after the Local
		// Store module (25), so the Organization node already exists to be extended.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 30, 2 );

		// Coupon Q&A joins the single product FAQPage rather than emitting a node.
		add_filter( 'twtaeo_product_faq_pairs', array( __CLASS__, 'faq_pairs' ), 20, 2 );

		// Visible output.
		add_shortcode( 'twtaeo_price_tiers', array( __CLASS__, 'shortcode_tiers' ) );
		add_shortcode( 'twtaeo_coupon_note', array( __CLASS__, 'shortcode_coupon_note' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'auto_tier_table' ), 26 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'auto_coupon_note' ), 27 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ) );

		// Coupon edits invalidate the normalised set.
		add_action( 'save_post_shop_coupon', array( 'TWTAEO_Coupon_Rules', 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_on_coupon_delete' ), 10, 2 );

		// Per-product tier override. A metabox rather than a block sidebar, because
		// products use the classic editor.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_metabox' ), 10, 2 );
	}

	/**
	 * Flush the coupon cache when a coupon is deleted.
	 *
	 * @param int          $post_id
	 * @param WP_Post|null $post
	 */
	public static function flush_on_coupon_delete( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && 'shop_coupon' === $post->post_type ) {
			TWTAEO_Coupon_Rules::flush_cache();
		}
	}

	// ── Visibility gates ──────────────────────────────────────────────────────

	/**
	 * Whether this request is a single product page.
	 *
	 * get_queried_object() rather than is_product(): in block themes is_product()
	 * is unreliable during head rendering, which is when the graph is built.
	 */
	private static function is_product_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$object = get_queried_object();
		return ( $object instanceof WP_Post ) && 'product' === $object->post_type;
	}

	/**
	 * Whether anything from this module may be published on this request at all.
	 *
	 * @return bool
	 */
	private static function output_allowed() {
		if ( ! TWTAEO_Price_Tiers::is_enabled() ) {
			return false;
		}
		if ( class_exists( 'TWTAEO_Store_Location' ) && TWTAEO_Store_Location::output_suppressed() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a placement setting means the block is on this page.
	 *
	 * 'shortcode' is taken at the merchant's word: there is no way at `wp_head`
	 * time to know whether a shortcode later in the body will run, and the admin
	 * page states that obligation plainly next to the setting.
	 *
	 * @param string $placement
	 * @return bool
	 */
	private static function placed( $placement ) {
		if ( 'off' === $placement ) {
			return false;
		}
		if ( 'shortcode' === $placement ) {
			return true;
		}
		return self::is_product_page();
	}

	/**
	 * Whether tier pricing may be marked up on this request.
	 *
	 * Three conditions, all required: output is allowed, the merchant has switched
	 * quantity tiers on, and **something on this store can actually apply a tier**.
	 * The last one is the important one. WooCommerce core has no quantity-break
	 * concept, so a merchant can type a tier that nothing enforces — and publishing
	 * "£85 each when you buy 10" on a store that charges £100 is a false statement
	 * about a price. See TWTAEO_Price_Tiers::enforcement().
	 *
	 * @return bool
	 */
	public static function tiers_visible() {
		if ( ! self::output_allowed() ) {
			return false;
		}
		$settings = TWTAEO_Price_Tiers::get();
		if ( empty( $settings['output_quantity_tiers'] ) ) {
			return false;
		}
		if ( ! TWTAEO_Price_Tiers::has_enforcement() ) {
			return false;
		}
		return self::placed( $settings['tier_table_placement'] );
	}

	/**
	 * Whether the coupon note is on the page, and therefore markable-up.
	 *
	 * @return bool
	 */
	public static function coupon_note_visible() {
		if ( ! self::output_allowed() ) {
			return false;
		}
		$settings = TWTAEO_Price_Tiers::get();
		if ( empty( $settings['output_coupon_note'] ) ) {
			return false;
		}
		return self::placed( $settings['coupon_note_placement'] );
	}

	// ── Schema: price specifications ──────────────────────────────────────────

	/**
	 * Attach conditional price specifications to a product's offers.
	 *
	 * Simple products get them on the Offer. Variable products get them on each
	 * **variant** offer, never on the parent AggregateOffer: that offer describes a
	 * low/high range, and a single tier figure attached to a range has no meaning.
	 *
	 * @param array           $schema  Assembled Product / ProductGroup node.
	 * @param int             $post_id Product post ID.
	 * @param WC_Product|null $product Product object.
	 * @return array
	 */
	public static function add_price_specifications( $schema, $post_id, $product ) {
		if ( ! is_array( $schema ) || ! class_exists( 'TWTAEO_Price_Tiers' ) ) {
			return $schema;
		}
		if ( ! self::output_allowed() ) {
			return $schema;
		}

		// A configurable product — a booking, a per-item bundle — publishes a floor
		// price, not a unit price. "10% off at quantity 5" hung off a figure that is
		// already only a starting point compounds one approximation with another and
		// states a number no shopper can be charged. Nothing is attached.
		if ( 'from' === TWTAEO_WC_Extensions::offer_strategy( $product ) ) {
			return $schema;
		}

		$members     = TWTAEO_Price_Tiers::member_tiers_for_offers();
		$show_tiers  = self::tiers_visible();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$is_variable = TWTAEO_WC_Extensions::is_variable_like( $product );

		// Simple / single-price products: the parent Offer carries a real price.
		if ( ! $is_variable ) {
			$tiers = $show_tiers ? TWTAEO_Price_Tiers::resolve_for( $product ) : array();
			if ( empty( $tiers ) && empty( $members ) ) {
				return $schema;
			}
			$base = TWTAEO_Price_Tiers::base_price( $product );
			if ( null !== $base && ! empty( $schema['offers'] ) && is_array( $schema['offers'] ) ) {
				$specs = self::build_specs( $base, $currency, $tiers, $members );
				if ( $specs ) {
					$schema['offers'] = self::attach( $schema['offers'], $specs );
				}
			}
			return $schema;
		}

		// Variable products: per variation, resolved against that variation.
		if ( empty( $schema['isVariantOf']['hasVariant'] ) || ! is_array( $schema['isVariantOf']['hasVariant'] ) ) {
			return $schema;
		}

		$children = self::variant_map( $product );
		$shared   = $show_tiers ? self::shared_breaks( $product, $children ) : null;

		foreach ( $schema['isVariantOf']['hasVariant'] as $index => $variant ) {
			if ( empty( $variant['offers'] ) || ! is_array( $variant['offers'] ) ) {
				continue;
			}
			$price = isset( $variant['offers']['price'] ) ? (float) $variant['offers']['price'] : 0.0;
			if ( $price <= 0 ) {
				continue;
			}

			$child = self::match_variant( $variant, $children );
			$tiers = array();

			if ( $show_tiers && $child instanceof WC_Product ) {
				$tiers = TWTAEO_Price_Tiers::resolve_for( $child, true );
				// Keep only the break points every variation shares, so the one
				// table on the page describes every variant's markup. A break that
				// exists on some options and not others would leave the odd ones
				// out marked up with nothing visible beside them.
				if ( null !== $shared ) {
					$tiers = array_values( array_filter( $tiers, function ( $tier ) use ( $shared ) {
						return isset( $shared[ self::break_key( $tier ) ] );
					} ) );
				}
			}

			if ( empty( $tiers ) && empty( $members ) ) {
				continue;
			}

			$specs = self::build_specs( $price, $currency, $tiers, $members );
			if ( $specs ) {
				$schema['isVariantOf']['hasVariant'][ $index ]['offers'] =
					self::attach( $variant['offers'], $specs );
			}
		}

		return $schema;
	}

	/** A tier's break point, as a comparable key. */
	private static function break_key( array $tier ) {
		return (int) $tier['min'] . '-' . (int) $tier['max'];
	}

	/**
	 * Variations of a variable product, keyed by the permalink the detector uses
	 * for their variant nodes.
	 *
	 * Keyed by URL rather than by position: the detector skips children it cannot
	 * load, so index alignment between `hasVariant` and `get_children()` is not
	 * guaranteed, and mis-aligning a tier onto the wrong variation would attach a
	 * price to a thing that does not have it.
	 *
	 * @param WC_Product $product
	 * @return array<string,WC_Product>
	 */
	private static function variant_map( WC_Product $product ) {
		$map = array();
		if ( ! method_exists( $product, 'get_children' ) ) {
			return $map;
		}
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product ) {
				$map[ (string) get_permalink( $child_id ) ] = $child;
			}
		}
		return $map;
	}

	/**
	 * Find the variation a variant node describes.
	 *
	 * @param array $variant
	 * @param array $children
	 * @return WC_Product|null
	 */
	private static function match_variant( array $variant, array $children ) {
		$url = isset( $variant['url'] ) ? (string) $variant['url'] : '';
		if ( '' !== $url && isset( $children[ $url ] ) ) {
			return $children[ $url ];
		}
		// SKU is the only other identifier the variant node carries.
		$sku = isset( $variant['sku'] ) ? (string) $variant['sku'] : '';
		if ( '' !== $sku ) {
			foreach ( $children as $child ) {
				if ( (string) $child->get_sku() === $sku ) {
					return $child;
				}
			}
		}
		return null;
	}

	/**
	 * The break points that every variation of a variable product shares.
	 *
	 * Returns null when there is nothing to intersect (no children resolved), which
	 * the caller reads as "do not filter".
	 *
	 * @param WC_Product $product
	 * @param array      $children
	 * @return array<string,array>|null
	 */
	public static function shared_breaks( WC_Product $product, ?array $children = null ) {
		if ( null === $children ) {
			$children = self::variant_map( $product );
		}
		if ( empty( $children ) ) {
			return null;
		}

		$shared = null;
		foreach ( $children as $child ) {
			$keys = array();
			foreach ( TWTAEO_Price_Tiers::resolve_for( $child, true ) as $tier ) {
				$tier['low']                      = $tier['unit_price'];
				$tier['high']                     = $tier['unit_price'];
				$keys[ self::break_key( $tier ) ] = $tier;
			}

			if ( null === $shared ) {
				$shared = $keys;
				continue;
			}

			$shared = array_intersect_key( $shared, $keys );

			// Widen each surviving break to the price range across variations, which
			// is what the visible table has to print when the options differ in price.
			foreach ( $shared as $key => $row ) {
				$shared[ $key ]['low']  = min( $row['low'], $keys[ $key ]['low'] );
				$shared[ $key ]['high'] = max( $row['high'], $keys[ $key ]['high'] );
			}
		}

		return is_array( $shared ) ? $shared : null;
	}

	/**
	 * Write specs onto one offer node, never replacing what is already there.
	 *
	 * The graph's contract everywhere is fill gaps, do not reassign — a merchant
	 * override or an earlier contributor's spec must survive.
	 *
	 * @param array $offer
	 * @param array $specs
	 * @return array
	 */
	private static function attach( array $offer, array $specs ) {
		$existing = array();

		if ( array_key_exists( 'priceSpecification', $offer ) ) {
			$existing = $offer['priceSpecification'];

			// ⚠️ This used to bail out entirely when the key was already set, which
			// was safe while nothing else wrote it. The detector now publishes a
			// StrikethroughPrice spec on the core path — before this filter runs —
			// so bailing would have silently dropped **every quantity tier** on any
			// discounted product. Merge instead: a strikethrough and a set of tier
			// prices are different statements about the same offer and both belong.
			if ( isset( $existing['@type'] ) ) {
				$existing = array( $existing ); // A lone spec is stored unwrapped.
			}
			if ( ! is_array( $existing ) ) {
				return $offer; // Not a shape we wrote; leave whoever owns it alone.
			}
		}

		$all = array_merge( array_values( $existing ), $specs );
		if ( empty( $all ) ) {
			return $offer;
		}

		$offer['priceSpecification'] = ( 1 === count( $all ) ) ? $all[0] : $all;
		return $offer;
	}

	/**
	 * Build every conditional spec for one base price.
	 *
	 * Quantity tiers arrive already resolved to a unit price by
	 * TWTAEO_Price_Tiers::resolve_for() — which is also what the visible table
	 * reads, so the two cannot disagree. Member tiers are still a declared
	 * percentage off this offer's own base.
	 *
	 * @param float  $base     Displayed base price.
	 * @param string $currency
	 * @param array  $tiers    Resolved quantity tiers { min, max, unit_price }.
	 * @param array  $members  Member tiers.
	 * @return array
	 */
	private static function build_specs( $base, $currency, array $tiers, array $members ) {
		$specs    = array();
		$settings = TWTAEO_Price_Tiers::get();

		foreach ( $tiers as $tier ) {
			$price = isset( $tier['unit_price'] ) ? (float) $tier['unit_price'] : 0.0;
			if ( $price <= 0 || $price >= (float) $base ) {
				continue;
			}

			$quantity = array(
				'@type'    => 'QuantitativeValue',
				'minValue' => (int) $tier['min'],
				'unitCode' => self::UNIT_PIECE,
			);
			if ( ! empty( $tier['max'] ) ) {
				$quantity['maxValue'] = (int) $tier['max'];
			}

			$spec = array(
				'@type'           => 'UnitPriceSpecification',
				'price'           => self::money( $price ),
				'priceCurrency'   => $currency,
				'eligibleQuantity' => $quantity,
			);

			// A cart-spend floor is only ever emitted *inside* a spec that already
			// carries eligibleQuantity. On its own it is an unguarded low price that
			// a crawler can read as the shelf price — see the class header.
			if ( ! empty( $settings['transaction_volume'] ) ) {
				$spec['eligibleTransactionVolume'] = array(
					'@type'         => 'PriceSpecification',
					'price'         => self::money( (float) $settings['transaction_volume'] ),
					'priceCurrency' => $currency,
				);
			}

			$specs[] = $spec;
		}

		// Member pricing is only published when the MemberProgram node it points at
		// is being published too, or the reference dangles.
		if ( $members && TWTAEO_Price_Tiers::member_program_node() ) {
			foreach ( $members as $tier ) {
				$price = TWTAEO_Price_Tiers::apply_tier( $base, $tier );
				if ( null === $price ) {
					continue;
				}
				$specs[] = array(
					'@type'              => 'UnitPriceSpecification',
					'price'              => self::money( $price ),
					'priceCurrency'      => $currency,
					'validForMemberTier' => array( '@id' => $tier['@id'] ),
				);
			}
		}

		return $specs;
	}

	/**
	 * Format a price the way the rest of the graph does — a decimal string at the
	 * store's own precision, so schema, storefront and Merchant Center feed agree.
	 *
	 * @param float $value
	 * @return string
	 */
	private static function money( $value ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return number_format( (float) $value, $decimals, '.', '' );
	}

	// ── Schema: the MemberProgram node ────────────────────────────────────────

	/**
	 * Hang the membership programme off the Organization.
	 *
	 * Google reads member pricing as a pair — `hasMemberProgram` on the Organization
	 * declaring the tiers, and `validForMemberTier` on each offer's spec pointing
	 * back at one. merge_node() fills keys the Organization does not already set, so
	 * this adds the relationship without touching the Organization's identity.
	 *
	 * @param array $nodes
	 * @param array $context
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		if ( ! self::output_allowed() || empty( $context['org_id'] ) ) {
			return $nodes;
		}

		$program = TWTAEO_Price_Tiers::member_program_node();
		if ( ! $program ) {
			return $nodes;
		}

		$nodes[] = array(
			'@id'              => $context['org_id'],
			'hasMemberProgram' => $program,
		);

		return $nodes;
	}

	// ── Schema: coupon Q&A ────────────────────────────────────────────────────

	/**
	 * Contribute the coupon note's Q&A to the page's single FAQPage.
	 *
	 * Only when the note is actually rendered on this page, and worded identically
	 * to what renders — the markup and the visible text are built from the same
	 * TWTAEO_Coupon_Rules::plain_terms() call so they cannot drift apart.
	 *
	 * @param array $pairs
	 * @param int   $product_id
	 * @return array
	 */
	public static function faq_pairs( $pairs, $product_id ) {
		if ( ! self::coupon_note_visible() ) {
			return $pairs;
		}

		$product = wc_get_product( $product_id );
		$rules   = self::visible_coupons( $product );
		if ( empty( $rules ) ) {
			return $pairs;
		}

		$lines = array();
		foreach ( $rules as $rule ) {
			$lines[] = sprintf(
				/* translators: 1: coupon code, 2: plain-English terms. */
				__( 'Use code %1$s at checkout: %2$s', 'twt-aeo-ultimate' ),
				strtoupper( $rule['code'] ),
				TWTAEO_Coupon_Rules::plain_terms( $rule )
			);
		}

		$pairs[] = array(
			'question' => __( 'Are there any discount codes for this product?', 'twt-aeo-ultimate' ),
			'answer'   => implode( ' ', $lines ),
		);

		return $pairs;
	}

	/**
	 * The coupons this product may name on the page, capped.
	 *
	 * @param WC_Product|null $product
	 * @return array
	 */
	private static function visible_coupons( $product ) {
		if ( ! $product instanceof WC_Product || ! class_exists( 'TWTAEO_Coupon_Rules' ) ) {
			return array();
		}

		$settings = TWTAEO_Price_Tiers::get();
		$rules    = TWTAEO_Coupon_Rules::rules_for_product( $product );

		// A coupon with no code cannot be typed at checkout, so naming it would be
		// advice a shopper cannot act on.
		$rules = array_values( array_filter( $rules, function ( $rule ) {
			return '' !== trim( $rule['code'] );
		} ) );

		return array_slice( $rules, 0, (int) $settings['coupon_note_limit'] );
	}

	// ── Visible output ────────────────────────────────────────────────────────

	/** Auto placement for the quantity-break table. */
	public static function auto_tier_table() {
		$settings = TWTAEO_Price_Tiers::get();
		if ( 'auto' !== $settings['tier_table_placement'] || ! self::tiers_visible() ) {
			return;
		}
		echo self::tier_table_html( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at build.
	}

	/** Auto placement for the coupon note. */
	public static function auto_coupon_note() {
		$settings = TWTAEO_Price_Tiers::get();
		if ( 'auto' !== $settings['coupon_note_placement'] || ! self::coupon_note_visible() ) {
			return;
		}
		echo self::coupon_note_html( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at build.
	}

	/**
	 * `[twtaeo_price_tiers]` — for merchants who place the table themselves.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function shortcode_tiers( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'twtaeo_price_tiers' );
		$id   = (int) $atts['id'] ?: (int) get_the_ID();

		if ( ! self::tiers_visible() ) {
			return '';
		}
		return self::tier_table_html( $id );
	}

	/**
	 * `[twtaeo_coupon_note]` — for merchants who place the note themselves.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function shortcode_coupon_note( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'twtaeo_coupon_note' );
		$id   = (int) $atts['id'] ?: (int) get_the_ID();

		if ( ! self::coupon_note_visible() ) {
			return '';
		}
		return self::coupon_note_html( $id );
	}

	/**
	 * The quantity-break table.
	 *
	 * Prices come from the same apply_tier() call the schema uses and the tier list
	 * from the same applicable_tiers(), so the table and the markup can never quote
	 * different figures or a different set of breaks.
	 *
	 * A variable product has a price *range*, not a price, so there is no single
	 * "price each" to print. It still gets a table — the discount column — because
	 * its variant offers carry marked-up tier prices, and markup with nothing
	 * visible beside it is the violation this whole gate exists to prevent. That
	 * was found on the live site: fifteen variants each carrying a tier price while
	 * the page showed nothing at all.
	 *
	 * @param int $product_id
	 * @return string
	 */
	public static function tier_table_html( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$is_variable = TWTAEO_WC_Extensions::is_variable_like( $product );
		$rows        = $is_variable
			? self::variable_rows( $product )
			: self::simple_rows( $product );

		if ( empty( $rows ) ) {
			return '';
		}

		// Only render a column something fills. A "Price each" header over empty
		// cells on a variable product, or a "You save" header over empty cells when
		// the saving is already implicit in the price, is noise pretending to be data.
		$has_price = false;
		$has_save  = false;
		foreach ( $rows as $row ) {
			$has_price = $has_price || ( '' !== $row['price'] );
			$has_save  = $has_save || ( '' !== $row['save'] );
		}

		$html  = '<table class="twt-aeo-tiers"><caption>'
			. esc_html__( 'Buy more, pay less', 'twt-aeo-ultimate' ) . '</caption><thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Quantity', 'twt-aeo-ultimate' ) . '</th>';
		if ( $has_price ) {
			$html .= '<th scope="col">' . esc_html__( 'Price each', 'twt-aeo-ultimate' ) . '</th>';
		}
		if ( $has_save ) {
			$html .= '<th scope="col">' . esc_html__( 'You save', 'twt-aeo-ultimate' ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html( $row['qty'] ) . '</td>';
			if ( $has_price ) {
				$html .= '<td>' . esc_html( $row['price'] ) . '</td>';
			}
			if ( $has_save ) {
				$html .= '<td>' . esc_html( $row['save'] ) . '</td>';
			}
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		if ( $is_variable ) {
			$html .= '<p class="twt-aeo-tiers__note">'
				. esc_html__( 'Applies to each option, from that option\'s own price.', 'twt-aeo-ultimate' )
				. '</p>';
		}

		return $html;
	}

	/**
	 * Table rows for a simple product — the same resolved tiers the markup uses.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	private static function simple_rows( WC_Product $product ) {
		$rows = array();
		$base = TWTAEO_Price_Tiers::base_price( $product );

		foreach ( TWTAEO_Price_Tiers::resolve_for( $product ) as $tier ) {
			// A detected tier's own label is its price, which the price column
			// already prints. State the saving as a percentage of this product's
			// price instead, which is the part the price column cannot show.
			$save = $tier['label'];
			if ( 'detected' === $tier['source'] ) {
				$save = ( $base > 0 )
					? sprintf(
						/* translators: %s: percentage, e.g. "10". */
						__( '%s%% off', 'twt-aeo-ultimate' ),
						wc_format_localized_decimal( round( ( 1 - ( $tier['unit_price'] / $base ) ) * 100, 1 ) )
					)
					: '';
			}

			$rows[] = array(
				'qty'   => TWTAEO_Price_Tiers::quantity_label( $tier ),
				'price' => TWTAEO_Price_Tiers::money( $tier['unit_price'] ),
				'save'  => $save,
			);
		}
		return $rows;
	}

	/**
	 * Table rows for a variable product.
	 *
	 * A variable product has a price *range*, so what can be printed depends on
	 * where the tiers came from:
	 *
	 *   Detected — every variation has a real, known tier price, so the row prints
	 *              the range across the options ("$27.20 to $30.60 each"). Only
	 *              breaks shared by every variation appear, which is exactly the
	 *              set the markup publishes.
	 *   Declared — a percentage or amount off, which scales, so the row prints the
	 *              discount and no price.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	private static function variable_rows( WC_Product $product ) {
		$rows   = array();
		$shared = self::shared_breaks( $product );

		if ( ! empty( $shared ) ) {
			$detected = array_filter( $shared, function ( $row ) {
				return 'detected' === $row['source'];
			} );

			if ( ! empty( $detected ) ) {
				usort( $detected, function ( $a, $b ) {
					return $a['min'] <=> $b['min'];
				} );
				foreach ( $detected as $row ) {
					$price = ( $row['low'] === $row['high'] )
						? TWTAEO_Price_Tiers::money( $row['low'] )
						: sprintf(
							/* translators: a range of prices. 1: lowest price, 2: highest price. */
							_x( '%1$s to %2$s', 'price range', 'twt-aeo-ultimate' ),
							TWTAEO_Price_Tiers::money( $row['low'] ),
							TWTAEO_Price_Tiers::money( $row['high'] )
						);
					// No saving column here: each option discounts from its own price,
					// so there is no one percentage that is true of all of them.
					$rows[] = array(
						'qty'   => TWTAEO_Price_Tiers::quantity_label( $row ),
						'price' => $price,
						'save'  => '',
					);
				}
				return $rows;
			}
		}

		// Declared tiers: the discount scales, the price does not print.
		foreach ( TWTAEO_Price_Tiers::declared_labels( $product->get_id() ) as $tier ) {
			$rows[] = array(
				'qty'   => TWTAEO_Price_Tiers::quantity_label( $tier ),
				'price' => '',
				'save'  => $tier['label'],
			);
		}

		return $rows;
	}

	/**
	 * The visible coupon note.
	 *
	 * @param int $product_id
	 * @return string
	 */
	public static function coupon_note_html( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		$rules   = self::visible_coupons( $product );
		if ( empty( $rules ) ) {
			return '';
		}

		$html = '<div class="twt-aeo-coupon-note"><p class="twt-aeo-coupon-note__title">'
			. esc_html__( 'Available discount codes', 'twt-aeo-ultimate' ) . '</p><ul>';

		foreach ( $rules as $rule ) {
			$html .= '<li><code>' . esc_html( strtoupper( $rule['code'] ) ) . '</code> — '
				. esc_html( TWTAEO_Coupon_Rules::plain_terms( $rule ) ) . '</li>';
		}

		$html .= '</ul></div>';

		return $html;
	}

	// ── Per-product override metabox ──────────────────────────────────────────

	/** Nonce action for the product tier metabox. */
	const NONCE_METABOX = 'twtaeo_product_tiers';

	/** How many tier rows the product metabox offers. */
	const METABOX_ROWS = 4;

	public static function add_metabox() {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}
		add_meta_box(
			'twt-aeo-price-tiers',
			__( 'AEO Quantity Breaks', 'twt-aeo-ultimate' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * @param WP_Post $post
	 */
	public static function render_metabox( $post ) {
		$mode  = get_post_meta( $post->ID, TWTAEO_Price_Tiers::META_MODE, true );
		$mode  = in_array( $mode, array( 'inherit', 'custom', 'none' ), true ) ? $mode : 'inherit';
		$rows  = TWTAEO_Price_Tiers::clean_tiers( get_post_meta( $post->ID, TWTAEO_Price_Tiers::META_TIERS, true ) );
		$store = TWTAEO_Price_Tiers::get();

		wp_nonce_field( self::NONCE_METABOX, 'twtaeo_tiers_nonce' );

		// If a pricing engine can tell us what this product actually costs at
		// quantity, say so — and say plainly that it wins over anything typed here,
		// because publishing a typed price the checkout will not charge is the whole
		// failure this module exists to avoid.
		if ( TWTAEO_Dynamic_Pricing::is_available() ) {
			$wc_product = wc_get_product( $post->ID );
			if ( $wc_product instanceof WC_Product ) {
				$probe  = TWTAEO_WC_Extensions::is_variable_like( $wc_product ) ? null : $wc_product;
				$status = $probe ? TWTAEO_Dynamic_Pricing::status_for( $probe ) : 'variable';
				$tiers  = $probe ? TWTAEO_Price_Tiers::resolve_for( $probe ) : array();
				$live   = ( ! empty( $tiers ) && 'detected' === $tiers[0]['source'] );
				?>
				<div class="notice notice-<?php echo $live ? 'success' : 'info'; ?> inline" style="margin:0 0 12px;">
					<p style="margin:8px 0;">
						<strong><?php
						printf(
							/* translators: %s: plugin name. */
							esc_html__( '%s is answering for real prices.', 'twt-aeo-ultimate' ),
							esc_html( TWTAEO_Dynamic_Pricing::engine_label() )
						);
						?></strong><br>
						<?php if ( 'variable' === $status ) : ?>
							<?php esc_html_e( 'Each option is read separately. Prices read from it are published in place of anything typed below.', 'twt-aeo-ultimate' ); ?>
						<?php else : ?>
							<?php echo esc_html( TWTAEO_Dynamic_Pricing::status_label( $status, $probe ) ); ?>
						<?php endif; ?>
					</p>
					<?php if ( $live ) : ?>
						<p style="margin:0 0 8px;">
							<?php foreach ( $tiers as $tier ) : ?>
								<code><?php echo esc_html( TWTAEO_Price_Tiers::quantity_label( $tier ) . ' → ' . TWTAEO_Price_Tiers::money( $tier['unit_price'] ) ); ?></code>&nbsp;
							<?php endforeach; ?>
							<br><em><?php esc_html_e( 'These are published instead of the rows below.', 'twt-aeo-ultimate' ); ?></em>
						</p>
					<?php endif; ?>
				</div>
				<?php
			}
		}

		$modes = array(
			'inherit' => sprintf(
				/* translators: %d: number of store-wide tiers. */
				__( 'Use the store-wide tiers (%d configured)', 'twt-aeo-ultimate' ),
				count( $store['store_tiers'] )
			),
			'custom'  => __( 'Use the tiers below for this product only', 'twt-aeo-ultimate' ),
			'none'    => __( 'No quantity breaks on this product', 'twt-aeo-ultimate' ),
		);
		?>
		<p>
			<?php foreach ( $modes as $value => $label ) : ?>
				<label style="display:block;margin-bottom:4px;">
					<input type="radio" name="twtaeo_tier_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</p>

		<table class="widefat" style="max-width:640px;">
			<thead><tr>
				<th style="width:22%"><?php esc_html_e( 'From qty', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:22%"><?php esc_html_e( 'To qty', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:28%"><?php esc_html_e( 'Adjustment', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:28%"><?php esc_html_e( 'Value', 'twt-aeo-ultimate' ); ?></th>
			</tr></thead>
			<tbody>
			<?php for ( $i = 0; $i < self::METABOX_ROWS; $i++ ) :
				$row = isset( $rows[ $i ] ) ? $rows[ $i ] : array( 'min' => '', 'max' => '', 'type' => 'percent', 'value' => '' );
				?>
				<tr>
					<td><input type="number" min="2" step="1" style="width:100%"
						name="twtaeo_tiers[<?php echo (int) $i; ?>][min]" value="<?php echo esc_attr( $row['min'] ); ?>"></td>
					<td><input type="number" min="0" step="1" style="width:100%"
						name="twtaeo_tiers[<?php echo (int) $i; ?>][max]" value="<?php echo esc_attr( $row['max'] ); ?>"></td>
					<td>
						<select name="twtaeo_tiers[<?php echo (int) $i; ?>][type]" style="width:100%">
							<option value="percent" <?php selected( $row['type'], 'percent' ); ?>><?php esc_html_e( '% off', 'twt-aeo-ultimate' ); ?></option>
							<option value="fixed" <?php selected( $row['type'], 'fixed' ); ?>><?php esc_html_e( 'Amount off each', 'twt-aeo-ultimate' ); ?></option>
							<option value="price" <?php selected( $row['type'], 'price' ); ?>><?php esc_html_e( 'Fixed price each', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</td>
					<td><input type="number" min="0" step="0.01" style="width:100%"
						name="twtaeo_tiers[<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $row['value'] ); ?>"></td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'These prices are published as structured data only if your checkout actually charges them. Nothing in WooCommerce applies quantity breaks on its own.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save_metabox( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['twtaeo_tiers_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['twtaeo_tiers_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_METABOX ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$mode = isset( $_POST['twtaeo_tier_mode'] ) ? sanitize_key( wp_unslash( $_POST['twtaeo_tier_mode'] ) ) : 'inherit';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every row is sanitized field-by-field in TWTAEO_Price_Tiers::save_product_tiers().
		$rows = isset( $_POST['twtaeo_tiers'] ) ? (array) wp_unslash( $_POST['twtaeo_tiers'] ) : array();

		TWTAEO_Price_Tiers::save_product_tiers( $post_id, $mode, $rows );
	}

	/**
	 * Minimal styles for both blocks.
	 *
	 * Printed only when something will render, so a store with the module off ships
	 * no extra bytes.
	 */
	public static function print_styles() {
		if ( ! self::tiers_visible() && ! self::coupon_note_visible() ) {
			return;
		}
		?>
<style id="twt-aeo-offer-pricing">
.twt-aeo-tiers{width:100%;border-collapse:collapse;margin:16px 0;font-size:.95em}
.twt-aeo-tiers caption{text-align:left;font-weight:600;padding-bottom:6px}
.twt-aeo-tiers th,.twt-aeo-tiers td{border:1px solid #e2e8f0;padding:6px 10px;text-align:left}
.twt-aeo-tiers thead th{background:#f6f7f7;font-weight:600}
.twt-aeo-tiers__note{margin:-8px 0 16px;font-size:.85em;opacity:.75}
.twt-aeo-coupon-note{margin:16px 0}
.twt-aeo-coupon-note__title{font-weight:600;margin:0 0 6px}
.twt-aeo-coupon-note ul{margin:0;padding-left:18px}
.twt-aeo-coupon-note code{background:#f6f7f7;padding:1px 6px;border-radius:3px}
</style>
		<?php
	}
}
