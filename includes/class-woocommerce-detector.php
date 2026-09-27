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
	 * How many products the on-screen results table scans in one page load.
	 *
	 * A display limit, not a catalogue limit. Scanning parses each product's
	 * content and checks its schema, so doing the whole catalogue synchronously is
	 * what times a page out — but this must never be mistaken for how many products
	 * exist (`total_products()`) or for how many a bulk action processes (it walks
	 * every one via `product_ids_page()`).
	 */
	const DISPLAY_CAP = 200;

	/**
	 * Products generated per round-trip by the bulk schema generator.
	 *
	 * Deliberately smaller than DISPLAY_CAP: generating and saving schema is far
	 * heavier than scanning, and each batch has to finish inside one PHP request.
	 */
	const GENERATE_BATCH = 25;

	/**
	 * Register hooks — only when WooCommerce is active.
	 */
	public static function register_hooks() {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}
		// Rescan on save. save_post_product covers the classic/quick edit; the
		// WooCommerce CRUD hooks additionally cover programmatic, REST API, CLI and
		// block-editor saves — they fire after WC_Product::save() commits data.
		add_action( 'save_post_product', array( __CLASS__, 'on_save_product' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_wc_save_product' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_wc_save_product' ), 20, 1 );

		// Re-detect the returns/refund policy page when pages change.
		add_action( 'save_post_page', array( __CLASS__, 'flush_return_policy_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_return_policy_cache' ) );

		// Fold the Product/ProductGroup node and a Home → category → Product
		// BreadcrumbList into the unified @graph. Priority 20 runs after the
		// custom-schema writer (10) so a merchant-saved Product owns its node and
		// this auto node only fills gaps. See TWTAEO_Knowledge_Graph.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 20, 2 );

		// Deduplicate: suppress WooCommerce core's own Product structured data so
		// our richer connected @graph is the single Product source on the page
		// (prevents two competing Product blocks, a common rich-results warning).
		// ⚠️ Only with the merchant's consent — the filters below check the
		// takeover option and pass WooCommerce's markup through untouched until
		// the merchant has chosen. Removing another plugin's output silently is
		// not playing nicely, even when ours is richer.
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'dedupe_wc_product_schema' ), 99 );
		add_filter( 'woocommerce_structured_data_breadcrumblist', array( __CLASS__, 'dedupe_wc_breadcrumb_schema' ), 99 );
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
	 * Adapter for the WooCommerce CRUD hooks, which pass only a product ID.
	 *
	 * @param int $product_id
	 */
	public static function on_wc_save_product( $product_id ) {
		$post = get_post( $product_id );
		if ( $post instanceof WP_Post ) {
			self::on_save_product( (int) $product_id, $post );
		}
	}

	/**
	 * Merchant's decision on WooCommerce core's built-in structured data:
	 * '' (never asked) or 'both' — leave WooCommerce's markup alone;
	 * 'aeo' — suppress it so our @graph is the page's only Product source.
	 */
	const OPTION_SCHEMA_TAKEOVER = 'twtaeo_wc_schema_takeover';

	/**
	 * Whether the merchant has chosen to let AEO be the only Product schema.
	 *
	 * @return bool
	 */
	public static function schema_takeover_enabled() {
		return 'aeo' === get_option( self::OPTION_SCHEMA_TAKEOVER, '' );
	}

	/**
	 * Suppress WooCommerce core's built-in Product structured data — our unified
	 * @graph emits a fuller Product node, so core's would only duplicate it.
	 *
	 * Runs ONLY once the merchant has opted in on the Products tab. Until then
	 * both blocks are live and the Products tab says so — the conflict belongs
	 * to the merchant to resolve, not to us to hide.
	 *
	 * @param array $markup
	 * @return array
	 */
	public static function dedupe_wc_product_schema( $markup ) {
		return self::schema_takeover_enabled() ? array() : $markup;
	}

	/**
	 * Suppress WooCommerce core's standalone BreadcrumbList on single product
	 * pages — our unified @graph already carries one. Left intact on shop and
	 * category pages, where we don't emit a breadcrumb. Same consent gate as
	 * the Product block.
	 *
	 * @param array $markup
	 * @return array
	 */
	public static function dedupe_wc_breadcrumb_schema( $markup ) {
		if ( ! self::schema_takeover_enabled() ) {
			return $markup;
		}
		// Use the main queried object rather than is_product(): in block (FSE)
		// themes this filter can fire while templates render in the head, where
		// the conditional tags aren't reliably set.
		$obj = get_queried_object();
		$single_product = ( $obj instanceof WP_Post && 'product' === $obj->post_type );
		return $single_product ? array() : $markup;
	}

	// ── Product images ───────────────────────────────────────────────────────────

	/** Most images published in `Product.image`. Filterable per product. */
	const IMAGE_MAX = 10;

	/**
	 * Build `Product.image` — featured image first, then the gallery.
	 *
	 * Returns `ImageObject` nodes rather than bare URLs so real image metadata
	 * (caption, title) can travel with each one. Google accepts either form, and
	 * the objects cost only bytes.
	 *
	 * @param int         $post_id
	 * @param object|null $product  WC_Product, when available.
	 * @param string      $override Merchant-supplied single image URL, or ''.
	 * @return array|string Array of ImageObject nodes, a single URL string, or ''.
	 */
	private static function build_image_nodes( $post_id, $product, $override = '' ) {
		// An explicit choice is not a starting point to build on.
		if ( '' !== $override ) {
			return $override;
		}

		$ids      = array();
		$featured = ( $product && method_exists( $product, 'get_image_id' ) ) ? (int) $product->get_image_id() : 0;
		if ( ! $featured ) {
			$featured = (int) get_post_thumbnail_id( $post_id );
		}
		if ( $featured ) {
			$ids[] = $featured;
		}
		if ( $product && method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		/**
		 * Cap on images published for one product. Every extra node is bytes on
		 * every page view, and a crawler gains little from the fortieth angle.
		 *
		 * @param int $max
		 * @param int $post_id
		 */
		$max = (int) apply_filters( 'twtaeo_product_image_max', self::IMAGE_MAX, $post_id );
		$ids = array_slice( $ids, 0, max( 1, $max ) );

		$permalink = get_permalink( $post_id );
		$nodes     = array();
		$position  = 0;

		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( ! $url ) {
				continue;
			}
			$position++;

			// `#image-N` is safe as a fragment: TWTAEO_Knowledge_Graph::link_main_entity()
			// walks #article, #faqpage, #product-group, #product, #service and
			// #contactpage, so an image can never become the page's mainEntity.
			$node = array(
				'@type' => 'ImageObject',
				'@id'   => $permalink . '#image-' . $position,
				'url'   => $url,
			);

			$caption = self::image_caption( $id );
			if ( '' !== $caption ) {
				$node['caption'] = $caption;
			}
			$name = self::image_name( $id, $url );
			if ( '' !== $name ) {
				$node['name'] = $name;
			}

			$nodes[] = $node;
		}

		if ( ! empty( $nodes ) ) {
			return $nodes;
		}

		// Nothing resolved from the attachment records — fall back to whatever the
		// thumbnail helper can produce rather than publishing no image at all.
		return get_the_post_thumbnail_url( $post_id, 'full' ) ?: '';
	}

	/**
	 * A caption worth publishing: the merchant's own caption, else the alt text.
	 *
	 * Alt text is a reasonable second choice because it is written to describe the
	 * image; schema.org has no `alt` property, and `caption` is where a
	 * descriptive string belongs.
	 *
	 * @param int $attachment_id
	 * @return string
	 */
	private static function image_caption( $attachment_id ) {
		$post = get_post( $attachment_id );

		// post_excerpt is WordPress's caption field.
		$caption = $post ? trim( (string) $post->post_excerpt ) : '';
		if ( '' === $caption ) {
			$caption = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		}
		if ( '' === $caption ) {
			return '';
		}

		// WordPress output carries entities; JSON-LD must not.
		return html_entity_decode( wp_strip_all_tags( $caption ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The image's title, but only when it actually says something.
	 *
	 * ⚠️ **WordPress seeds an attachment's `post_title` from the filename on
	 * upload.** So an image uploaded as `image-1235.png` gets the title
	 * "image-1235", and publishing that as `ImageObject.name` ships noise into the
	 * graph while looking like real metadata. Anything that matches the filename,
	 * or matches a camera/CMS default pattern, is dropped rather than published.
	 *
	 * @param int    $attachment_id
	 * @param string $url
	 * @return string
	 */
	private static function image_name( $attachment_id, $url ) {
		$post  = get_post( $attachment_id );
		$title = $post ? trim( (string) $post->post_title ) : '';
		if ( '' === $title ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = pathinfo( $path, PATHINFO_FILENAME ); // hoodie-2
		$full = basename( $path );                    // hoodie-2.jpg

		// Depending on how it was uploaded, WordPress may keep the extension in the
		// title ("hoodie-2.jpg") or drop it ("hoodie-2"). Comparing only against the
		// extension-less filename let the first form through — verified on the test
		// store, where `hoodie-2.jpg` shipped as an ImageObject name.
		$title_noext = preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', '', $title );

		foreach ( array( $title, $title_noext ) as $candidate ) {
			if ( sanitize_title( $candidate ) === sanitize_title( $base )
				|| sanitize_title( $candidate ) === sanitize_title( $full ) ) {
				return '';
			}
		}

		// Pattern checks run on the extension-less form for the same reason.
		$t = strtolower( trim( $title_noext ) );
		if ( '' === $t ) {
			return '';
		}

		// Camera and CMS defaults carry no meaning even when they differ from the
		// stored filename: IMG_4821, DSC00042, "Screenshot 3", untitled.
		if ( preg_match( '/^(img|dsc|dscn|image|photo|pic|picture|screenshot|screen shot|untitled|scan|final|copy)[\s._-]*\d*$/', $t ) ) {
			return '';
		}
		// Date-stamped exports: 20240115, 20240115_123456.
		if ( preg_match( '/^\d{8}[\s._-]?\d{0,6}$/', $t ) ) {
			return '';
		}
		// Hashes and uuid fragments.
		if ( preg_match( '/^[0-9a-f]{8,}$/', $t ) ) {
			return '';
		}

		return html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * First image URL out of whatever shape `Product.image` currently holds.
	 *
	 * `save_overrides()` compares a merchant's single-URL override against the
	 * generated value, so it needs a string no matter whether the generator
	 * produced a string, a list of URLs, or a list of ImageObject nodes.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function first_image_url( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$first = reset( $value );
			if ( is_string( $first ) ) {
				return $first;
			}
			if ( is_array( $first ) && isset( $first['url'] ) ) {
				return (string) $first['url'];
			}
		}
		return '';
	}

	/**
	 * Price a shopper actually sees — includes or excludes tax per the store's
	 * `woocommerce_tax_display_shop` setting, so schema matches the storefront and
	 * the Merchant Center feed. Falls back to the raw price pre-WC-3.0.
	 *
	 * ⚠ A dynamic pricing plugin can discount from quantity one, rewriting the
	 * *displayed* price while leaving `get_price()` untouched — Flycart's own sample
	 * rule does this, and the product page then renders `~~$45.00~~ $42.75` while
	 * this function would say 45. Publishing 45 contradicts the page the shopper is
	 * reading, which is precisely the price mismatch Google penalises. So where the
	 * engine reports a different single-unit price, that wins. It is read from cache
	 * and never probed here, so a front-end page view never runs someone else's rule
	 * engine, and it is a no-op unless the Promotions module is on.
	 *
	 * @param WC_Product $product
	 * @return string|float
	 */
	private static function display_price( $product ) {
		// ⚠️ A product with no price set is not a product priced at zero, and
		// `wc_get_price_to_display()` cannot tell them apart — it runs the empty
		// string through the tax calculation and returns 0. Callers then publish
		// `price: "0"` beside `InStock`, which reads as "free and available" on a
		// page that renders no price at all: the storefront mismatch this writer
		// exists to prevent. Measured on the test store 2026-08-01 — 8 of 199
		// products, every one of them `is_purchasable() === false`.
		//
		// Emptiness is therefore preserved here rather than at each call site.
		// `build_variable_offer()` and the variant loop already test
		// `get_price()` before calling this, so they are unaffected; the parent
		// path in `build_product_schema()` did not, and its own
		// `'' === $price` guard could never fire because of the conversion above.
		//
		// Note the test is the *stored* price, not `is_purchasable()`: an
		// out-of-stock product has a real price and must still publish an Offer,
		// carrying `OutOfStock`.
		$raw = $product->get_price();
		if ( '' === $raw || null === $raw || false === $raw ) {
			return '';
		}

		if ( class_exists( 'TWTAEO_Dynamic_Pricing' ) ) {
			$effective = TWTAEO_Dynamic_Pricing::effective_unit_price( $product );
			if ( null !== $effective ) {
				return $effective;
			}
		}
		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return wc_get_price_to_display( $product );
		}
		return $product->get_price();
	}

	/**
	 * The price this product is marked down *from*, as a StrikethroughPrice spec.
	 *
	 * Google documents exactly one mechanism for sale pricing: mark the **original**
	 * price with `priceType: StrikethroughPrice` and leave the active price bare.
	 * Their wording is explicit — *"Don't mark the current sale price with the
	 * priceType property"* — so this returns the "was" figure only, and `price` on
	 * the Offer stays untouched.
	 *
	 * Verified against schema.org V30.0 (2026-03-19): `PriceTypeEnumeration` has
	 * exactly eight members and `StrikethroughPrice` is one of them. There is **no**
	 * "actual price" term — an invented one would validate as unknown and silently
	 * do nothing, the same trap as `InStorePickup` and `discountCode`.
	 *
	 * ── Why the regular price is the single source ────────────────────────────
	 * It covers both ways a product ends up discounted here, with one rule:
	 *
	 * - **A WooCommerce sale** — regular 50, sale 45. `<del>50</del>` is what the
	 *   template renders, and 50 is what we publish.
	 * - **A pricing engine discounting from quantity one** — no WooCommerce sale at
	 *   all, so regular == catalogue == 45 while `display_price()` returns the
	 *   engine's 42.75. The page renders `~~$45.00~~ $42.75` (measured against
	 *   Flycart on the test store) and 45 is again the right "was".
	 *
	 * ⚠️ **Published only when the regular price is strictly higher than the price
	 * we publish.** WooCommerce will happily store a regular price equal to — or
	 * below — the active one, and striking through a number that is not a reduction
	 * advertises a discount that does not exist. Same reasoning as the rule that a
	 * price which *rises* with quantity is never dressed up as a break.
	 *
	 * Tax treatment goes through `wc_get_price_to_display()` like every other price
	 * here, or the strikethrough would sit in different terms from the figure beside
	 * it and read as a bigger or smaller discount than the page shows.
	 *
	 * @param WC_Product|null $product Simple product or a single variation.
	 * @return array|null UnitPriceSpecification, or null when nothing is marked down.
	 */
	private static function strikethrough_spec( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$current = self::display_price( $product );
		if ( '' === $current ) {
			return null; // No price set — see display_price()'s note on empty vs zero.
		}
		$current = (float) $current;
		if ( $current <= 0 ) {
			return null;
		}

		$regular = $product->get_regular_price();
		if ( '' === $regular || null === $regular || false === $regular || ! is_numeric( $regular ) ) {
			return null;
		}

		$regular = function_exists( 'wc_get_price_to_display' )
			? (float) wc_get_price_to_display( $product, array( 'price' => $regular ) )
			: (float) $regular;

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		if ( round( $regular, $decimals ) <= round( $current, $decimals ) ) {
			return null;
		}

		return array(
			'@type'         => 'UnitPriceSpecification',
			'priceType'     => 'https://schema.org/StrikethroughPrice',
			'price'         => (string) round( $regular, $decimals ),
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		);
	}

	/**
	 * Physical dimensions and weight, read from WooCommerce's own fields.
	 *
	 * These are stored natively on every product, cost nothing to publish, and
	 * answer a question shoppers and answer engines actually ask ("will it fit").
	 * Nothing else in this plugin emitted them.
	 *
	 * Two things make this less trivial than it looks:
	 *
	 * 1. **The unit is a store setting, not a constant.** `woocommerce_weight_unit`
	 *    and `woocommerce_dimension_unit` are merchant-configurable, so the number
	 *    is meaningless without the matching UN/CEFACT code. An unrecognised unit
	 *    yields **no output at all** rather than a guessed code — a wrong unit
	 *    turns a 30 cm bag into a 30 inch one, which is worse than silence.
	 * 2. **WooCommerce "length" is schema.org `depth`.** There is no `length` on
	 *    schema.org Product; mapping it to `width` or `height` would silently
	 *    transpose two real measurements.
	 *
	 * Only values the merchant actually set are published — WooCommerce stores an
	 * empty string for an unset dimension, and a zero-size product is not a fact.
	 *
	 * @param WC_Product|null $product
	 * @return array<string,array> schema.org keys → QuantitativeValue nodes.
	 */
	private static function build_dimensions( $product ) {
		if ( ! $product || ! function_exists( 'get_option' ) ) {
			return array();
		}

		// UN/CEFACT Recommendation 20 codes, which is what Google reads.
		$weight_codes    = array( 'kg' => 'KGM', 'g' => 'GRM', 'lbs' => 'LBR', 'oz' => 'ONZ' );
		$dimension_codes = array( 'm' => 'MTR', 'cm' => 'CMT', 'mm' => 'MMT', 'in' => 'INH', 'yd' => 'YRD' );

		$weight_unit    = (string) get_option( 'woocommerce_weight_unit', '' );
		$dimension_unit = (string) get_option( 'woocommerce_dimension_unit', '' );

		$out = array();

		$quantity = static function ( $value, $code ) {
			if ( '' === $value || null === $value || false === $value || '' === $code ) {
				return null;
			}
			if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
				return null;
			}
			return array(
				'@type'    => 'QuantitativeValue',
				'value'    => (string) ( 0 + $value ),
				'unitCode' => $code,
			);
		};

		$weight_code = isset( $weight_codes[ $weight_unit ] ) ? $weight_codes[ $weight_unit ] : '';
		$weight      = $quantity( $product->get_weight(), $weight_code );
		if ( $weight ) {
			$out['weight'] = $weight;
		}

		$dimension_code = isset( $dimension_codes[ $dimension_unit ] ) ? $dimension_codes[ $dimension_unit ] : '';
		// WooCommerce length → schema.org depth. See note 2 above.
		$map = array(
			'depth'  => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);
		foreach ( $map as $key => $raw ) {
			$node = $quantity( $raw, $dimension_code );
			if ( $node ) {
				$out[ $key ] = $node;
			}
		}

		return $out;
	}

	/**
	 * Map a WooCommerce stock status to the matching schema.org availability URL,
	 * honouring the third core state (on backorder) rather than collapsing to a
	 * binary in/out of stock.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	private static function availability_url( $product ) {
		switch ( $product->get_stock_status() ) {
			case 'onbackorder':
				return 'https://schema.org/BackOrder';
			case 'outofstock':
				return 'https://schema.org/OutOfStock';
			default:
				return 'https://schema.org/InStock';
		}
	}

	/**
	 * Build an AggregateOffer for a variable product from its variations — the
	 * schema.org-correct representation for a variant group. Price is the low/high
	 * range across purchasable variations and availability is InStock when any
	 * variation is in stock (WooCommerce's parent stock status can wrongly read
	 * out-of-stock while variations are in stock).
	 *
	 * @param WC_Product $product  The variable product.
	 * @param string     $url      Canonical product URL.
	 * @param string     $currency Store currency.
	 * @return array|null
	 */
	private static function build_variable_offer( $product, $url, $currency ) {
		$prices      = array();
		$any_instock = false;

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$raw = $child->get_price();
			if ( '' !== $raw && false !== $raw ) {
				$prices[] = (float) self::display_price( $child );
			}
			if ( 'instock' === $child->get_stock_status() ) {
				$any_instock = true;
			}
		}

		if ( empty( $prices ) ) {
			return null;
		}

		return array(
			'@type'         => 'AggregateOffer',
			'@id'           => $url . '#offer',
			'priceCurrency' => $currency,
			'lowPrice'      => (string) min( $prices ),
			'highPrice'     => (string) max( $prices ),
			'offerCount'    => count( $prices ),
			'availability'  => $any_instock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'itemCondition' => 'https://schema.org/NewCondition',
			'url'           => $url,
		);
	}

	/**
	 * An AggregateOffer carrying a floor price and no ceiling — schema.org's way
	 * of saying "From: $X", which is exactly what the storefront renders for a
	 * booking, a per-item bundle or a composite.
	 *
	 * `highPrice` is deliberately omitted rather than guessed. The maximum for a
	 * booking depends on a date the shopper has not picked yet; inventing one
	 * would replace an honest floor with a fabricated ceiling. `offerCount` is
	 * omitted for the same reason — there is no countable set of offers here.
	 *
	 * @param string $url          Canonical product URL.
	 * @param string $currency     Store currency.
	 * @param mixed  $price        Floor price.
	 * @param string $availability schema.org availability URL.
	 * @return array
	 */
	private static function build_from_offer( $url, $currency, $price, $availability ) {
		return array(
			'@type'         => 'AggregateOffer',
			'@id'           => $url . '#offer',
			'priceCurrency' => $currency,
			'lowPrice'      => (string) $price,
			'availability'  => $availability,
			'itemCondition' => 'https://schema.org/NewCondition',
			'url'           => $url,
		);
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
	 * Products matched by the last scan_all() call, across ALL pages — what a
	 * pager needs when a search narrows the catalogue.
	 *
	 * @var int
	 */
	private static $last_found = 0;

	/**
	 * How many products the last scan_all() query matched in total.
	 *
	 * @return int
	 */
	public static function last_found() {
		return self::$last_found;
	}

	/**
	 * Match the SKU as well as the title when searching — the SKU is what a
	 * merchant reaches for when they know exactly which item they mean.
	 * (Ported from AEO Ultimate for WooCommerce.)
	 *
	 * @param string   $sql
	 * @param WP_Query $query
	 * @return string
	 */
	public static function search_sku( $sql, $query ) {
		global $wpdb;

		$term = $query->get( 's' );
		if ( '' === $term || '' === $sql ) {
			return $sql;
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// Append as an OR against the existing search clause, which arrives
		// wrapped in "AND (...)" — the injection point is inside those brackets.
		$sku = $wpdb->prepare(
			" OR ({$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s )) ",
			$like
		);

		return preg_replace( '/\)\s*$/', $sku . ')', $sql, 1 );
	}

	/**
	 * Scan one page of published WooCommerce products, newest first.
	 *
	 * @param int    $page   1-based page number; page N covers products
	 *                       ((N-1)*DISPLAY_CAP)+1 through N*DISPLAY_CAP, so the
	 *                       screen can walk the whole catalogue instead of
	 *                       stopping at the first DISPLAY_CAP.
	 * @param string $search Optional term matched against title, content and
	 *                       SKU — searched across the WHOLE catalogue, because
	 *                       the product being hunted is almost never on the
	 *                       page already on screen.
	 * @return array[] Each entry: { post, wc_data }
	 */
	public static function scan_all( $page = 1, $search = '' ) {
		if ( ! self::is_woocommerce_active() ) {
			self::$last_found = 0;
			return array();
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::DISPLAY_CAP,
			'paged'          => max( 1, (int) $page ),
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		);

		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$args['s'] = $search;
			add_filter( 'posts_search', array( __CLASS__, 'search_sku' ), 10, 2 );
		}

		$query = new WP_Query( $args );

		if ( '' !== $search ) {
			remove_filter( 'posts_search', array( __CLASS__, 'search_sku' ), 10 );
		}

		self::$last_found = (int) $query->found_posts;
		$products         = $query->posts;

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
	 * How many published products are in the catalogue, regardless of any scan cap.
	 *
	 * Counted, never derived from a scan. `wp_count_posts()` is a single cached
	 * query, so this stays O(1) on a catalogue of any size.
	 *
	 * @return int
	 */
	public static function total_products() {
		if ( ! self::is_woocommerce_active() ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Get summary counts.
	 *
	 * ⚠️ **`total` is the catalogue, `scanned` is what this page could look at.**
	 * These were the same number until it turned out they are not: `total` used to
	 * be `count( scan_all() )`, which is capped at DISPLAY_CAP, so a 949-product
	 * store reported "200 products" and every derived figure was a proportion of
	 * the wrong denominator. The scan cap is real and has to stay — rendering a
	 * whole catalogue in one page load times out — so the fix is to count the
	 * catalogue separately and let the screen say "200 of 949" rather than quietly
	 * redefining 949 as 200. Same rule as the sitemap: never silently truncate.
	 *
	 * @param array[]|null $all A page already returned by scan_all(), so the
	 *                          caller does not pay for a second scan. Null scans
	 *                          the first page.
	 * @return array { total, scanned, truncated, needs_schema, has_schema, service_intent, product_intent }
	 */
	public static function get_summary( $all = null ) {
		if ( null === $all ) {
			$all = self::scan_all();
		}
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

		$total = self::total_products();

		return array(
			'total'          => $total,
			'scanned'        => count( $all ),
			'truncated'      => $total > count( $all ),
			'needs_schema'   => $needs,
			'has_schema'     => $has,
			'service_intent' => $service_intent,
			'product_intent' => $product_intent,
		);
	}

	/**
	 * One page of published product IDs, oldest-stable order.
	 *
	 * The bulk generator walks the catalogue with this instead of calling
	 * `scan_all()`, which stops at DISPLAY_CAP. Ordered by ID so the cursor stays
	 * stable between batches — ordering by date would let a product edited
	 * mid-run jump the queue and be processed twice or skipped.
	 *
	 * @param int $offset Rows to skip.
	 * @param int $limit  Rows to return.
	 * @return int[]
	 */
	public static function product_ids_page( $offset, $limit ) {
		if ( ! self::is_woocommerce_active() ) {
			return array();
		}

		return get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $limit ),
			'offset'         => max( 0, (int) $offset ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
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
		// A subscription product is sold as a Product with a recurring Offer —
		// that is what WooCommerce sells it as and what the storefront shows. It
		// also matches "subscription" and "plan" in the keyword list below, which
		// would classify it as a service and make this plugin flag its own
		// correct Product schema as the wrong schema. The product type is a fact
		// and the keyword scan is a guess, so the fact wins.
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		if ( $product instanceof WC_Product
			&& in_array( $product->get_type(), array( 'subscription', 'variable-subscription' ), true ) ) {
			return 'product';
		}

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

		// Strategy 0: this plugin's own saved schema. ⚠️ Without this the table
		// and the bulk generator contradict each other: the generator skips a
		// product because Custom_Schema_Writer already holds its schema, while
		// this detection still reports it missing — so N products sit flagged
		// "missing" that "Generate Schema for All" refuses to touch, with no
		// explanation. The writer's own meta must count as schema present.
		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' ) ) {
			foreach ( $product_types as $type ) {
				if ( TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, $type ) ) {
					$has_product = true;
					$source      = 'TWT AEO';
				}
			}
			foreach ( $service_types as $type ) {
				if ( TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, $type ) ) {
					$has_service = true;
					$source      = 'TWT AEO';
				}
			}
		}

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

	/** Transient caching the detected returns/refund policy page URL. */
	const TRANSIENT_RETURN_URL = 'twtaeo_return_policy_url';

	/**
	 * Postmeta keys for manually-picked product relationships (arrays of IDs).
	 */
	const META_SIMILAR   = '_twtaeo_wc_similar';
	const META_ACCESSORY = '_twtaeo_wc_accessory';

	/** Postmeta key for resolved brand sameAs authority URLs. */
	const META_SAMEAS = '_twtaeo_ai_brand_sameas';

	/**
	 * Postmeta key for the merchant's *edits* to the generated product schema.
	 *
	 * Only values that differ from what the generator produces are stored. The
	 * schema itself is never frozen: every render rebuilds it from live
	 * WooCommerce data and lays these edits on top, so price, stock, variants
	 * and shipping stay current while a hand-written name or description sticks.
	 */
	const META_OVERRIDES = '_twtaeo_product_overrides';

	/** Scalar fields a merchant can override from the product modal. */
	const OVERRIDE_KEYS = array(
		'name', 'description', 'sku', 'price', 'currency', 'brand', 'color',
		'material', 'size', 'gtin', 'mpn', 'image', 'availability',
		'item_condition', 'category', 'additional', 'include_reviews',
	);

	/**
	 * Reduce submitted values to genuine edits by diffing against a freshly
	 * generated baseline, then store them.
	 *
	 * The modal posts every field, including the ones it merely displayed. Saving
	 * all of them would re-freeze the schema — which is exactly the staleness this
	 * replaces — so anything equal to the generated value is discarded.
	 *
	 * @param int   $post_id
	 * @param array $submitted Raw override candidates from the modal.
	 * @return array The edits actually stored.
	 */
	public static function save_overrides( $post_id, array $submitted ) {
		$baseline = self::build_product_schema( $post_id );
		$baseline = is_array( $baseline ) ? $baseline : array();

		// Map an override key to the generated value it would replace.
		$generated = array(
			'name'           => $baseline['name'] ?? '',
			'description'    => $baseline['description'] ?? '',
			'sku'            => $baseline['sku'] ?? '',
			'brand'          => is_array( $baseline['brand'] ?? null ) ? ( $baseline['brand']['name'] ?? '' ) : ( $baseline['brand'] ?? '' ),
			'image'          => self::first_image_url( $baseline['image'] ?? '' ),
			'category'       => $baseline['category'] ?? '',
			'color'          => $baseline['color'] ?? '',
			'material'       => $baseline['material'] ?? '',
			'size'           => $baseline['size'] ?? '',
			'gtin'           => $baseline['gtin'] ?? '',
			'mpn'            => $baseline['mpn'] ?? '',
			'item_condition' => $baseline['itemCondition'] ?? '',
			'price'          => $baseline['offers']['price'] ?? ( $baseline['offers']['lowPrice'] ?? '' ),
			'currency'       => $baseline['offers']['priceCurrency'] ?? '',
			'availability'   => $baseline['offers']['availability'] ?? '',
		);

		$edits = array();
		foreach ( $submitted as $key => $value ) {
			if ( ! in_array( $key, self::OVERRIDE_KEYS, true ) ) {
				continue;
			}
			// Non-scalar and behavioural keys have no simple generated twin — keep
			// them whenever they carry content.
			if ( 'additional' === $key ) {
				if ( ! empty( $value ) ) {
					$edits[ $key ] = $value;
				}
				continue;
			}
			if ( 'include_reviews' === $key ) {
				if ( empty( $value ) ) {
					$edits[ $key ] = false; // Only a deliberate opt-out is an edit.
				}
				continue;
			}
			$submitted_value = is_string( $value ) ? trim( $value ) : $value;
			if ( '' === $submitted_value || null === $submitted_value ) {
				continue;
			}
			$generated_value = isset( $generated[ $key ] ) ? ( is_string( $generated[ $key ] ) ? trim( $generated[ $key ] ) : $generated[ $key ] ) : null;
			if ( (string) $submitted_value !== (string) $generated_value ) {
				$edits[ $key ] = $submitted_value;
			}
		}

		if ( empty( $edits ) ) {
			delete_post_meta( $post_id, self::META_OVERRIDES );
		} else {
			// wp_slash(): update_post_meta() unslashes, which would strip the
			// backslash from every \uXXXX escape wp_json_encode() emits.
			update_post_meta( $post_id, self::META_OVERRIDES, wp_slash( wp_json_encode( $edits ) ) );
		}

		return $edits;
	}

	/**
	 * The merchant's stored edits for a product, if any.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_overrides( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_OVERRIDES, true );
		if ( empty( $raw ) ) {
			return array();
		}
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $decoded ) ? $decoded : array();
	}

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
		//
		// A taxonomy the merchant has *declared* as their brand taxonomy comes
		// first, because it is an answer rather than a guess. Rank Math asks
		// exactly this question ("Select Product Brand Taxonomy to use in
		// Schema.org & OpenGraph markup") and stores it in its own options; if it
		// is set, it beats our list — including for a custom taxonomy we have
		// never heard of, which is the case our list can never cover.
		// We only ever read it. Absent Rank Math, or with the setting unset,
		// nothing here changes.
		if ( $out['brand'] === '' ) {
			$candidates = array( 'product_brand', 'pwb-brand', 'pa_brand' );

			$declared = self::declared_brand_taxonomy();
			if ( '' !== $declared ) {
				array_unshift( $candidates, $declared );
			}

			foreach ( $candidates as $tax ) {
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
	 * A brand taxonomy the merchant has explicitly nominated in another plugin.
	 *
	 * Read-only, and never a dependency: with no such plugin, or the setting
	 * left unset, this returns '' and brand detection is unchanged.
	 *
	 * Only Rank Math is read, because only Rank Math's storage has been
	 * confirmed by reading its source (`Helper::get_settings( 'general.product_brand' )`
	 * → the `rank_math_general` option, populated by the `product_brand` select
	 * in its WooCommerce settings). Yoast WooCommerce SEO has a comparable
	 * setting; its key has **not** been verified here, and guessing an option key
	 * fails silently, so it is left out until someone checks.
	 *
	 * @return string Taxonomy name, or '' when nothing is declared.
	 */
	private static function declared_brand_taxonomy() {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return '';
		}

		$general = get_option( 'rank_math_general', array() );
		if ( ! is_array( $general ) || empty( $general['product_brand'] ) ) {
			return '';
		}

		$tax = sanitize_key( $general['product_brand'] );

		return taxonomy_exists( $tax ) ? $tax : '';
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

		// Variant groups → ProductGroup with hasVariant. Must agree with the offer
		// branch in build_product_schema(): a product that gets an AggregateOffer
		// spanning its children and no ProductGroup to hang them on describes a
		// range of prices for a set of variants it never names.
		if ( TWTAEO_WC_Extensions::is_variable_like( $product ) && method_exists( $product, 'get_children' ) ) {
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
				// A variation may carry its own description. When it does not,
				// build_schema() fills in the parent's — Google grades each variant
				// as a merchant listing in its own right and wants one on each.
				$child_desc = wp_strip_all_tags( (string) $child->get_description() );
				if ( '' !== trim( $child_desc ) ) {
					$variant['description'] = html_entity_decode( $child_desc, ENT_QUOTES, 'UTF-8' );
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
					$child_offer = array(
						'@type'         => 'Offer',
						'price'         => (string) self::display_price( $child ),
						'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
						'availability'  => self::availability_url( $child ),
						'itemCondition' => 'https://schema.org/NewCondition',
						'url'           => get_permalink( $child_id ),
					);
					// Sale window + price validity, per variation.
					$child_valid_until = '';
					if ( $child->is_on_sale() ) {
						$c_from = $child->get_date_on_sale_from();
						$c_to   = $child->get_date_on_sale_to();
						if ( $c_from ) {
							$child_offer['validFrom'] = $c_from->date( 'Y-m-d' );
						}
						if ( $c_to ) {
							$child_offer['validThrough'] = $c_to->date( 'Y-m-d' );
							$child_valid_until           = $c_to->date( 'Y-m-d' );
						}
					}
					if ( '' === $child_valid_until ) {
						$child_valid_until = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
					}
					$child_offer['priceValidUntil'] = $child_valid_until;

					// Per variation, against that variation's own regular price —
					// options on one product are routinely discounted differently.
					$child_struck = self::strikethrough_spec( $child );
					if ( $child_struck ) {
						$child_offer['priceSpecification'] = $child_struck;
					}

					$variant['offers'] = $child_offer;
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
			'number'  => 20,
		) );

		// Split verified purchasers from the rest — WooCommerce flags genuine
		// buyers via the 'verified' comment meta, the signal AI weights most.
		$verified = array();
		$others   = array();
		foreach ( $comments as $comment ) {
			$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( ! $rating ) {
				continue;
			}
			$node = array(
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
			if ( get_comment_meta( $comment->comment_ID, 'verified', true ) ) {
				$verified[] = $node;
			} else {
				$others[] = $node;
			}
		}

		// Surface verified-purchaser reviews when we have them; only fall back to
		// unverified ones if the product has none.
		$selected        = ! empty( $verified ) ? $verified : $others;
		$out['reviews']  = array_slice( $selected, 0, 10 );

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
			'page_id' => 0,                // 0 = auto-detect the policy page.
		);
		$saved = get_option( self::OPTION_RETURN, array() );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * Build OfferShippingDetails for the product.
	 *
	 * Prefers the store's real WooCommerce Shipping Zones (core-WC fidelity): one
	 * OfferShippingDetails per zone, destinations taken from the zone's locations
	 * and the rate from the cheapest determinable flat rate (or 0 for
	 * unconditional free shipping). Falls back to the manually-configured defaults
	 * only when no usable zone rate exists. WooCommerce core has no handling or
	 * transit-time model, so delivery time stays configurable.
	 *
	 * @return array|null A single OfferShippingDetails, a list of them, or null.
	 */
	public static function build_shipping_details() {
		$from_zones = self::build_shipping_details_from_zones();
		if ( ! empty( $from_zones ) ) {
			return $from_zones;
		}
		return self::build_shipping_details_default();
	}

	/**
	 * Derive OfferShippingDetails from the live WooCommerce Shipping Zones.
	 *
	 * @return array List of OfferShippingDetails nodes (possibly empty).
	 */
	private static function build_shipping_details_from_zones() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$delivery = self::shipping_delivery_time();
		$details  = array();

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone = WC_Shipping_Zones::get_zone( (int) $zone_data['id'] );
			if ( ! $zone ) {
				continue;
			}
			$region = self::zone_defined_region( $zone );
			if ( null === $region ) {
				continue; // No resolvable countries (e.g. postcode-only zone).
			}
			$rate = self::zone_shipping_rate( $zone );
			if ( null === $rate ) {
				continue; // No statically determinable flat/free rate.
			}

			$node = array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => (string) $rate,
					'currency' => $currency,
				),
				'shippingDestination' => $region,
			);
			if ( $delivery ) {
				$node['deliveryTime'] = $delivery;
			}
			$details[] = $node;
		}

		return $details;
	}

	/**
	 * Reduce a shipping zone's locations to a schema.org DefinedRegion. Country
	 * and continent locations resolve to ISO country codes; state locations add
	 * addressRegion codes. Postcode-only zones return null.
	 *
	 * @param WC_Shipping_Zone $zone
	 * @return array|null
	 */
	private static function zone_defined_region( $zone ) {
		$countries = array();
		$regions   = array();

		foreach ( $zone->get_zone_locations() as $loc ) {
			switch ( $loc->type ) {
				case 'country':
					$countries[] = $loc->code;
					break;
				case 'state':
					// WooCommerce stores states as "US:CA".
					$parts = explode( ':', $loc->code );
					if ( ! empty( $parts[0] ) ) {
						$countries[] = $parts[0];
					}
					if ( ! empty( $parts[1] ) ) {
						$regions[] = $parts[1];
					}
					break;
				case 'continent':
					if ( function_exists( 'WC' ) && WC()->countries ) {
						$continents = WC()->countries->get_continents();
						if ( isset( $continents[ $loc->code ]['countries'] ) ) {
							$countries = array_merge( $countries, $continents[ $loc->code ]['countries'] );
						}
					}
					break;
				// 'postcode' is too granular for product-level schema.
			}
		}

		$countries = array_values( array_unique( array_filter( $countries ) ) );
		if ( empty( $countries ) ) {
			return null;
		}

		$region = array(
			'@type'          => 'DefinedRegion',
			'addressCountry' => ( count( $countries ) === 1 ) ? $countries[0] : $countries,
		);

		$regions = array_values( array_unique( array_filter( $regions ) ) );
		if ( ! empty( $regions ) ) {
			$region['addressRegion'] = ( count( $regions ) === 1 ) ? $regions[0] : $regions;
		}

		return $region;
	}

	/**
	 * Representative shipping rate for a zone: 0 for unconditional free shipping,
	 * otherwise the cheapest numeric flat-rate cost. Returns null when no rate can
	 * be determined statically (e.g. cost formulas that depend on the cart).
	 *
	 * @param WC_Shipping_Zone $zone
	 * @return float|null
	 */
	private static function zone_shipping_rate( $zone ) {
		$min = null;
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			switch ( $method->id ) {
				case 'free_shipping':
					$requires = $method->get_instance_option( 'requires' );
					if ( '' === $requires || null === $requires ) {
						return 0.0; // Unconditional free shipping.
					}
					break;
				case 'flat_rate':
					$cost = $method->get_instance_option( 'cost' );
					if ( is_numeric( $cost ) ) {
						$cost = (float) $cost;
						$min  = ( null === $min ) ? $cost : min( $min, $cost );
					}
					break;
			}
		}
		return $min;
	}

	/**
	 * Shipping delivery-time node from the configured handling/transit values.
	 * WooCommerce core does not model delivery time, so this stays configurable.
	 *
	 * @return array
	 */
	private static function shipping_delivery_time() {
		$d = self::get_shipping_defaults();
		return array(
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
		);
	}

	/**
	 * Legacy manual OfferShippingDetails from the plugin's configured defaults —
	 * used only when no usable WooCommerce zone rate is available.
	 *
	 * @return array|null
	 */
	private static function build_shipping_details_default() {
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
			'deliveryTime'        => self::shipping_delivery_time(),
		);
	}

	/**
	 * Build a MerchantReturnPolicy fragment from site defaults.
	 *
	 * @return array|null
	 */
	public static function build_return_policy() {
		$d   = self::get_return_defaults();
		$url = self::find_return_policy_url();

		// Nothing to expose if the merchant hasn't configured a structured policy
		// and we couldn't find a returns/refund policy page either.
		if ( empty( $d['enabled'] ) && '' === $url ) {
			return null;
		}

		// Prefer the store's actual base country over the manual default.
		$country = $d['country'];
		if ( function_exists( 'wc_get_base_location' ) ) {
			$base = wc_get_base_location();
			if ( ! empty( $base['country'] ) ) {
				$country = $base['country'];
			}
		}

		$policy = array(
			'@type'             => 'MerchantReturnPolicy',
			'applicableCountry' => $country,
		);

		// A discoverable link to the human-readable policy page — the property
		// search engines and AI assistants follow to read the actual terms.
		if ( '' !== $url ) {
			$policy['merchantReturnLink'] = $url;
		}

		if ( ! empty( $d['enabled'] ) ) {
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnFiniteReturnWindow';
			$policy['merchantReturnDays']   = (int) $d['days'];
			$policy['returnMethod']         = $d['method'];
			$policy['returnFees']           = ( $d['fees'] === 'free' )
				? 'https://schema.org/FreeReturn'
				: 'https://schema.org/ReturnFeesCustomerResponsibility';
		} else {
			// A policy page exists but the structured window/fees aren't set — still
			// declare that a policy exists and point crawlers to it.
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnUnspecified';
		}

		return $policy;
	}

	/**
	 * URL of the store's returns/refund policy page, cached for 12 hours.
	 *
	 * @return string Empty string when no policy page is found.
	 */
	public static function find_return_policy_url() {
		// 1. An explicit merchant-chosen page always wins over auto-detection.
		$d       = self::get_return_defaults();
		$page_id = (int) ( $d['page_id'] ?? 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		// 2. Cached auto-detection by slug / title.
		$cached = get_transient( self::TRANSIENT_RETURN_URL );
		if ( is_string( $cached ) ) {
			return ( 'none' === $cached ) ? '' : $cached;
		}
		$url = self::detect_return_policy_url();
		set_transient( self::TRANSIENT_RETURN_URL, ( '' === $url ) ? 'none' : $url, 12 * HOUR_IN_SECONDS );
		return $url;
	}

	/**
	 * Find the returns/refund policy page by URL slug or page title.
	 *
	 * Checks WooCommerce's auto-created "Refund and Returns Policy" page and other
	 * common policy slugs first (a URL match), then scans published page titles for
	 * return/refund wording.
	 *
	 * @return string
	 */
	private static function detect_return_policy_url() {
		// 1. Known slugs — WooCommerce's onboarding page uses "refund_returns".
		$slugs = array(
			'refund_returns',
			'refund-and-returns-policy',
			'refunds-and-returns',
			'returns-and-refunds',
			'return-policy',
			'returns-policy',
			'refund-policy',
			'returns',
			'refunds',
			'shipping-and-returns',
		);
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				return (string) get_permalink( $page );
			}
		}

		// 2. Title match — one targeted query rather than scanning every page, so
		// this scales to any catalogue size. Prefers a title that also says
		// "policy" (e.g. "Return Policy" over "How to Return"), then oldest page.
		// The whole lookup is cached for 12h by find_return_policy_url().
		global $wpdb;
		$like_return = '%' . $wpdb->esc_like( 'return' ) . '%';
		$like_refund = '%' . $wpdb->esc_like( 'refund' ) . '%';
		$like_policy = '%' . $wpdb->esc_like( 'polic' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted title lookup not expressible via WP_Query (which searches content too); result is cached in a transient by the caller.
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'page' AND post_status = 'publish'
				   AND ( post_title LIKE %s OR post_title LIKE %s )
				 ORDER BY ( post_title LIKE %s ) DESC, ID ASC
				 LIMIT 1",
				$like_return,
				$like_refund,
				$like_policy
			)
		);

		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Clear the cached policy-page URL when pages change.
	 */
	public static function flush_return_policy_cache() {
		delete_transient( self::TRANSIENT_RETURN_URL );
	}

	/**
	 * URL of the store's privacy policy page — a core YMYL trust signal. Uses
	 * WordPress's native privacy-policy page setting first, then a slug scan.
	 * schema.org has no privacy-policy property, so this feeds the store trust
	 * audit and llms.txt rather than the Product schema.
	 *
	 * @return string Empty string when none is found.
	 */
	public static function find_privacy_policy_url() {
		if ( function_exists( 'get_privacy_policy_url' ) ) {
			$url = get_privacy_policy_url();
			if ( $url ) {
				return (string) $url;
			}
		}
		foreach ( array( 'privacy-policy', 'privacy', 'privacy-notice' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				return (string) get_permalink( $page );
			}
		}
		return '';
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
			$price        = self::display_price( $product );
			$availability = self::availability_url( $product );
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
		// The gallery belongs in the graph as an array of ImageObject nodes —
		// alternative angles, packaging shots and dimension charts are all part of
		// the same entity, and `Product.image` accepts a list.
		//
		// Open Graph deliberately stays a single `og:image`: social platforms and
		// chat previews expect one, and repeating the tag just makes the choice
		// theirs instead of the merchant's. See TWTAEO_OG_Writer.
		//
		// A merchant image override means "publish this one", so it is honoured
		// verbatim rather than having eight gallery shots appended to it.
		$image_override = ( isset( $overrides['image'] ) && is_string( $overrides['image'] ) ) ? trim( $overrides['image'] ) : '';
		$image_value    = self::build_image_nodes( $post_id, $product, $image_override );
		if ( ! empty( $image_value ) ) {
			$schema['image'] = $image_value;
		} elseif ( $image ) {
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
		foreach ( self::build_dimensions( $product ) as $dim_key => $dim_value ) {
			$schema[ $dim_key ] = $dim_value;
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

		// category: Google's recommended merchant-listing property. Google now
		// accepts an array mixing plain-text labels with CategoryCode objects, so
		// we emit the product's own WooCommerce categories AND — when the AI has
		// resolved one — the official Google Product Taxonomy code. That makes the
		// page the single source of truth Google Shopping and AI shopping agents
		// read identically. A modal override, if set, wins outright.
		$category_values = array();
		if ( array_key_exists( 'category', $overrides ) ) {
			$cat_override = is_string( $overrides['category'] ) ? trim( $overrides['category'] ) : $overrides['category'];
			if ( is_array( $cat_override ) ) {
				$category_values = array_values( array_filter( array_map( 'trim', $cat_override ), 'strlen' ) );
			} elseif ( $cat_override !== '' && $cat_override !== null ) {
				$category_values[] = $cat_override;
			}
		} else {
			$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
				$category_values = array_values( $cats );
			}
			// Fold in the AI-resolved Google Product Category as a CategoryCode object.
			if ( class_exists( 'TWTAEO_Product_Enricher' ) ) {
				$gcat = TWTAEO_Product_Enricher::get_cached_google_category( $post_id );
				if ( $gcat && ! empty( $gcat['data']['code'] ) ) {
					$code_obj = array(
						'@type'     => 'CategoryCode',
						'inCodeSet' => TWTAEO_Product_Enricher::GOOGLE_TAXONOMY_URL,
						'codeValue' => (string) $gcat['data']['code'],
					);
					if ( ! empty( $gcat['data']['path'] ) ) {
						$code_obj['name'] = $gcat['data']['path'];
					}
					$category_values[] = $code_obj;
				}
			}
		}
		if ( ! empty( $category_values ) ) {
			$schema['category'] = ( count( $category_values ) === 1 ) ? $category_values[0] : $category_values;
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
		//
		// Which of the three shapes a product gets is decided by asking the product
		// object, not by checking a list of plugin names — see
		// TWTAEO_WC_Extensions::offer_strategy(). A product type nobody here has
		// heard of still lands on 'exact', which is the behaviour this writer had
		// before any of this existed.
		$offer    = null;
		$strategy = TWTAEO_WC_Extensions::offer_strategy( $product );

		if ( 'range' === $strategy ) {
			// Variant group → AggregateOffer spanning the variations. Price is a
			// low/high range and availability is derived from the variations, because
			// WooCommerce's parent stock status is unreliable for variable products
			// (it can read out-of-stock while every variation is in stock).
			$offer = self::build_variable_offer( $product, $url, $currency );
		} elseif ( $price === '' || $price === false || $price === null ) {
			$offer = null;
		} elseif ( 'from' === $strategy ) {
			// Configurable product: the stored price is its cheapest configuration,
			// and the storefront says "From: X". Publishing X as a definite price
			// would contradict the page — the precise mismatch this plugin exists to
			// prevent — so it is published as a floor instead.
			$offer = self::build_from_offer( $url, $currency, $price, $availability );
		} else {
			$offer = array(
				'@type'         => 'Offer',
				'@id'           => $url . '#offer',
				'price'         => (string) $price,
				'priceCurrency' => $currency,
				'availability'  => $availability,
				'itemCondition' => 'https://schema.org/NewCondition',
				'url'           => $url,
			);

			// Sale duration + price validity. A scheduled WooCommerce sale defines
			// the window Google should show the price for; without priceValidUntil
			// Google may treat the price as stale (a common Merchant listing warning).
			$price_valid_until = '';
			if ( $product ) {
				if ( $product->is_on_sale() ) {
					$sale_from = $product->get_date_on_sale_from();
					$sale_to   = $product->get_date_on_sale_to();
					if ( $sale_from ) {
						$offer['validFrom'] = $sale_from->date( 'Y-m-d' );
					}
					if ( $sale_to ) {
						$offer['validThrough'] = $sale_to->date( 'Y-m-d' );
						$price_valid_until     = $sale_to->date( 'Y-m-d' );
					}
				}
				// Fallback so the offer never reads as expired.
				if ( '' === $price_valid_until ) {
					$price_valid_until = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
				}
				$offer['priceValidUntil'] = $price_valid_until;
			}
		}

		// Shipping and returns are store-level facts, so the parent offer and every
		// variant offer share them. Built once here rather than inside the parent
		// branch, because Google grades each variant as its own merchant listing:
		// a variant Offer without them is judged incomplete even when the parent
		// AggregateOffer carries them. Only built when something will consume them —
		// build_shipping_details() walks WC_Shipping_Zones.
		//
		// Both are withheld from a product that is never put in a box. A shipping
		// rate and a ship-it-back return window are not facts about a subscription,
		// a booking or a downloadable — and this is not an extension problem, a
		// plain virtual product in core WooCommerce has it too.
		$has_variants = ! empty( $schema['isVariantOf']['hasVariant'] )
			&& is_array( $schema['isVariantOf']['hasVariant'] );
		$shippable = TWTAEO_WC_Extensions::is_shippable( $product );
		$wants     = $shippable && ( $offer || $has_variants );
		$shipping  = $wants ? self::build_shipping_details() : array();
		$returns   = $wants ? self::build_return_policy() : array();

		// Order-quantity rules (Min/Max Quantities). Published alongside the price
		// so a price of 12 with a minimum of 6 reads as "12 each, six minimum"
		// rather than as an order a shopper can place and then cannot.
		$eligible_qty = TWTAEO_WC_Extensions::eligible_quantity( $product );
		if ( $offer && $eligible_qty ) {
			$offer['eligibleQuantity'] = $eligible_qty;
		}

		if ( $offer ) {
			if ( $shipping ) {
				$offer['shippingDetails'] = $shipping;
			}
			if ( $returns ) {
				$offer['hasMerchantReturnPolicy'] = $returns;
			}

			// Sale pricing, but only on a real Offer. An AggregateOffer describes a
			// low/high *range* across variations, and a single "was" figure hung off
			// a range states nothing a shopper can act on — the same reason tier
			// prices attach to variants rather than to the parent. Variable products
			// get theirs per variation in the variant loop above.
			if ( 'AggregateOffer' !== ( $offer['@type'] ?? '' ) ) {
				$struck = self::strikethrough_spec( $product );
				if ( $struck ) {
					$offer['priceSpecification'] = $struck;
				}
			}

			$schema['offers'] = $offer;
		}

		// Seller — reference the sitewide Organization node by @id so the Offer
		// resolves to the same trusted entity in the unified @graph, with an inline
		// name/url fallback for when the graph engine isn't emitting an Org node.
		//
		// `seller` belongs to Offer/Demand, NOT to Product, so it goes on the offer
		// node rather than alongside it.
		$seller = array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url );
		if ( class_exists( 'TWTAEO_Knowledge_Graph' ) ) {
			$seller['@id'] = TWTAEO_Knowledge_Graph::organization_id();
		}
		if ( ! empty( $schema['offers'] ) && is_array( $schema['offers'] ) && isset( $schema['offers']['@type'] ) ) {
			$schema['offers']['seller'] = $seller;
		}

		// Carry the offer-level trust signals down onto every variant. Google
		// evaluates each entry in hasVariant as a separate merchant listing, so
		// shipping, returns and seller have to be repeated on each variant offer
		// rather than inherited from the parent AggregateOffer. Existing values
		// are never overwritten — a variation-specific figure wins.
		if ( $has_variants ) {
			$group_desc = isset( $schema['description'] ) ? $schema['description'] : '';

			if ( '' !== $group_desc && empty( $schema['isVariantOf']['description'] ) ) {
				$schema['isVariantOf']['description'] = $group_desc;
			}

			foreach ( $schema['isVariantOf']['hasVariant'] as $vi => $variant ) {
				if ( '' !== $group_desc && empty( $variant['description'] ) ) {
					$variant['description'] = $group_desc;
				}
				if ( ! empty( $variant['offers'] ) && is_array( $variant['offers'] ) ) {
					if ( $shipping && empty( $variant['offers']['shippingDetails'] ) ) {
						$variant['offers']['shippingDetails'] = $shipping;
					}
					if ( $returns && empty( $variant['offers']['hasMerchantReturnPolicy'] ) ) {
						$variant['offers']['hasMerchantReturnPolicy'] = $returns;
					}
					if ( empty( $variant['offers']['seller'] ) ) {
						$variant['offers']['seller'] = $seller;
					}
				}
				$schema['isVariantOf']['hasVariant'][ $vi ] = $variant;
			}
		}

		/**
		 * Final say over the assembled product node.
		 *
		 * Exists because the Offer is a *nested* value of the Product node, not a
		 * top-level graph node — so a contributor on `twtaeo_kg_nodes` cannot reach
		 * it. TWTAEO_Knowledge_Graph::merge_node() keys the graph by top-level @id
		 * only, and emitting a second `#offer` node to be merged by a consumer is
		 * a bet on the consumer, not a guarantee. The Local Store module uses this
		 * to attach availableAtOrFrom / availableDeliveryMethod to the real offer.
		 *
		 * Contract for anything hooking here: fill gaps, do not reassign identity.
		 *
		 * @param array           $schema  The assembled Product / ProductGroup node.
		 * @param int             $post_id Product post ID.
		 * @param WC_Product|null $product Product object, when resolvable.
		 */
		return apply_filters( 'twtaeo_product_schema', $schema, $post_id, $product );
	}

	/**
	 * Speakable selectors for a product page — the passages a voice assistant
	 * should read aloud.
	 *
	 * `speakable` is a WebPage/Article property, so this is folded into the
	 * WebPage node by kg_nodes() rather than onto the Product. Selectors are
	 * filterable because themes vary; the defaults match WooCommerce core
	 * templates and blocks.
	 *
	 * @param int $post_id
	 * @return array|null SpeakableSpecification node, or null when empty.
	 */
	private static function speakable_spec( $post_id ) {
		$speakable = apply_filters(
			'twtaeo_speakable_selectors',
			array(
				'.product_title',
				'.woocommerce-product-details__short-description',
			),
			$post_id
		);
		$speakable = array_values( array_filter( array_map( 'strval', (array) $speakable ) ) );
		if ( empty( $speakable ) ) {
			return null;
		}
		return array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => $speakable,
		);
	}

	/**
	 * Contribute the product's schema into the site-wide unified @graph.
	 *
	 * On a single WooCommerce product page this folds two connected nodes into
	 * the same graph the Knowledge Graph engine emits (WebSite → Organization →
	 * WebPage spine):
	 *
	 *   1. The Product (or ProductGroup + hasVariant) node, addressable at
	 *      `<url>#product` / `<url>#product-group`, linked from the WebPage by
	 *      `mainEntity` (no `isPartOf` back-reference — Product is not a
	 *      CreativeWork, so the vocabulary does not allow it).
	 *   2. A BreadcrumbList (`<url>#breadcrumb`) — Home → primary product category
	 *      → Product — whose final item shares the product's canonical URL, so the
	 *      product and its category read as one connected graph. The WebPage node
	 *      gets a `breadcrumb` reference to it.
	 *
	 * Nodes are keyed by @id, so a merchant-saved custom Product node (contributed
	 * at an earlier priority) keeps ownership and this one only fills gaps.
	 *
	 * @param array $nodes   Graph nodes contributed so far.
	 * @param array $context { post_id, url, webpage_id, org_id, has_org, is_front }.
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $nodes;
		}

		$post_id = (int) ( $context['post_id'] ?? get_the_ID() );
		if ( ! $post_id ) {
			return $nodes;
		}
		$url = ! empty( $context['url'] ) ? $context['url'] : get_permalink( $post_id );

		// Product / ProductGroup node. Rebuilt from live WooCommerce data on every
		// render, with the merchant's stored edits laid on top — nothing is frozen,
		// so price, stock, variants and shipping never go stale.
		$schema = self::build_product_schema( $post_id, self::get_overrides( $post_id ) );
		if ( is_array( $schema ) && ! empty( $schema['@type'] ) ) {
			unset( $schema['@context'] );

			$is_group      = ( 'ProductGroup' === $schema['@type'] );
			$schema['@id'] = $url . ( $is_group ? '#product-group' : '#product' );

			// Make the Brand addressable within the graph.
			if ( isset( $schema['brand'] ) && is_array( $schema['brand'] ) && empty( $schema['brand']['@id'] ) ) {
				$schema['brand']['@id'] = $url . '#brand';
			}

			// Address the nested ProductGroup of a variable product so it can be
			// referenced like every other node instead of floating anonymously.
			if ( ! $is_group && isset( $schema['isVariantOf'] ) && is_array( $schema['isVariantOf'] )
				&& empty( $schema['isVariantOf']['@id'] ) ) {
				$schema['isVariantOf']['@id'] = $url . '#product-group';
			}

			$nodes[] = $schema;

			// `speakable` describes the page, not the product — fold it into the
			// WebPage node. merge_node() fills the key without touching identity.
			if ( ! empty( $context['webpage_id'] ) ) {
				$speakable = self::speakable_spec( $post_id );
				if ( $speakable ) {
					$nodes[] = array(
						'@id'       => $context['webpage_id'],
						'speakable' => $speakable,
					);
				}
			}
		}

		// BreadcrumbList relationship (Home → category → Product).
		foreach ( self::build_breadcrumb_nodes( $post_id, $url, $context ) as $node ) {
			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Build the BreadcrumbList node (and a WebPage `breadcrumb` back-reference)
	 * for a product: Home → primary product category chain → Product.
	 *
	 * @param int    $post_id
	 * @param string $url     Canonical product URL.
	 * @param array  $context KG context (for webpage_id).
	 * @return array List of graph nodes (may be empty).
	 */
	private static function build_breadcrumb_nodes( $post_id, $url, $context ) {
		$items = array();
		$pos   = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'item'     => home_url( '/' ),
		);

		$primary = self::primary_product_cat( $post_id );
		if ( $primary ) {
			$chain = array_reverse( get_ancestors( $primary->term_id, 'product_cat' ) );
			$chain[] = $primary->term_id;
			foreach ( $chain as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'item'     => $link,
				);
			}
		}

		// Final item is the product itself, sharing its canonical URL so the
		// product node and the breadcrumb terminate at the same entity.
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'item'     => $url,
		);

		$out = array(
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $items,
			),
		);

		// Link the WebPage to the breadcrumb (gap-filled onto the existing node).
		if ( ! empty( $context['webpage_id'] ) ) {
			$out[] = array(
				'@id'        => $context['webpage_id'],
				'breadcrumb' => array( '@id' => $url . '#breadcrumb' ),
			);
		}

		return $out;
	}

	/**
	 * Resolve the product's primary category — an explicit primary term set by
	 * Yoast or Rank Math when present, otherwise the deepest assigned category.
	 *
	 * @param int $post_id
	 * @return WP_Term|null
	 */
	private static function primary_product_cat( $post_id ) {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}

		$primary_id = (int) get_post_meta( $post_id, '_yoast_wpseo_primary_product_cat', true );
		if ( ! $primary_id ) {
			$primary_id = (int) get_post_meta( $post_id, 'rank_math_primary_product_cat', true );
		}
		if ( $primary_id ) {
			foreach ( $terms as $term ) {
				if ( (int) $term->term_id === $primary_id ) {
					return $term;
				}
			}
		}

		$deepest = null;
		$best    = -1;
		foreach ( $terms as $term ) {
			$depth = count( get_ancestors( $term->term_id, 'product_cat' ) );
			if ( $depth > $best ) {
				$best    = $depth;
				$deepest = $term;
			}
		}
		return $deepest;
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
			$label = __( 'Good', 'twt-aeo-ultimate' );     $color = '#7f54b3';
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