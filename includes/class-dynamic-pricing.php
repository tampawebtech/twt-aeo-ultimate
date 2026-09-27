<?php
/**
 * Reading real tier prices out of a dynamic pricing engine.
 *
 * ── Why this exists, and why it is better than reading their rules ────────────
 * Flycart's Discount Rules keeps its configuration in its own `wdr_*` tables, and
 * its developer documentation confirms there is no public API for reading that
 * configuration back. What it *does* publish is a set of documented filters that
 * answer a different and better question — not "what rules are configured" but
 * **"what will you charge for this product at this quantity"**:
 *
 *     $price = apply_filters(
 *         'advanced_woo_discount_rules_get_product_discount_price',
 *         $product->get_price(), $product, $quantity
 *     );
 *
 * That is the number the checkout will actually take. Deriving a tier from it
 * removes the single biggest risk in this whole module: a merchant declaring a
 * quantity break that nothing on the store honours. A tier discovered here is
 * enforced by construction, because the thing that enforces it is what told us.
 *
 * ── The quantity-1 answer is the shelf price ──────────────────────────────────
 * A pricing engine can discount from quantity **one**. Flycart's own sample rule
 * does: its first band starts at 1. It rewrites the *displayed* price and charges
 * the discount in the cart, but leaves `get_price()` alone — so on the test site
 * the product page rendered `~~$45.00~~ $42.75` while `wc_get_price_to_display()`
 * still said 45.
 *
 * Publishing 45 there is simply wrong: it contradicts the page the shopper is
 * reading, which is the price mismatch Google penalises, and it makes our own
 * Merchant Center comparison report a disagreement that is ours rather than the
 * feed's. So `effective_unit_price()` returns the engine's quantity-1 answer
 * whenever it is *lower* than the catalogue figure, and that becomes the price the
 * Offer publishes and the feed engines compare against. Tiers are then measured
 * against the same base, so the whole page sits on one number.
 *
 * A quantity-1 answer that is *higher* than the catalogue price is ignored. That is
 * a surcharge or a role-based markup, and quietly inflating a merchant's published
 * price is not something to do on inference.
 *
 * ── The front end never probes ────────────────────────────────────────────────
 * Discovery walks a ladder of quantities and runs someone else's rule engine once
 * per rung. That is fine in an admin request and unacceptable in `wp_head`. So
 * discovery only ever runs in admin, CLI or save context; the front end reads the
 * cached result and publishes nothing if there is none.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Dynamic_Pricing {

	/** Documented Flycart filters. See the class header. */
	const FILTER_PRICE   = 'advanced_woo_discount_rules_get_product_discount_price';
	const FILTER_DETAILS = 'advanced_woo_discount_rules_get_product_discount_details';

	/** Cached discovery, per product/variation. */
	const META_TIERS = '_twtaeo_detected_tiers';

	/**
	 * Bumped whenever the ladder or the shape below changes, so stale caches from
	 * an older algorithm are recomputed rather than trusted.
	 */
	const CACHE_VERSION = 3;

	/**
	 * Quantities probed, in order.
	 *
	 * Every quantity up to 12 is probed, because that is where breaks actually
	 * cluster and a gap there is visible in the published table — a sparse ladder
	 * reported a 6-10 band as 6-11 against real Flycart rules on the test site.
	 * Above 12 it thins out to the round numbers merchants use, rather than running
	 * a third-party rule engine a hundred times per product.
	 *
	 * A break set at a quantity not on the ladder is found at the next rung up,
	 * which **understates** the discount's reach and never overstates it: we may
	 * quote the higher price for one quantity that would actually get the lower one.
	 * Erring the other way would promise a price the checkout does not give.
	 */
	const LADDER = array( 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 15, 20, 24, 25, 30, 40, 50, 75, 100 );

	/** Most tiers we will publish for one product. */
	const MAX_TIERS = 8;

	// ── Availability ──────────────────────────────────────────────────────────

	/**
	 * Whether a pricing engine is answering the documented filter.
	 *
	 * `has_filter()` is the real capability test — the plugin being installed is
	 * not the same as it being hooked in on this request.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return (bool) has_filter( self::FILTER_PRICE );
	}

	/**
	 * Who is answering, for the admin to name.
	 *
	 * @return string
	 */
	public static function engine_label() {
		if ( class_exists( 'TWTAEO_Coupon_Rules' ) ) {
			$owners = TWTAEO_Coupon_Rules::dynamic_pricing_owners();
			if ( ! empty( $owners ) ) {
				return implode( ', ', $owners );
			}
		}
		return __( 'a dynamic pricing plugin', 'twt-aeo-ultimate' );
	}

	/**
	 * Whether this request may run discovery.
	 *
	 * Never on a front-end page view — see the class header.
	 *
	 * @return bool
	 */
	public static function may_discover() {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	// ── Probing ───────────────────────────────────────────────────────────────

	/**
	 * Ask the engine what one unit costs when buying `$quantity`.
	 *
	 * Returns the engine's raw (pre-tax-display) figure, or null when nothing
	 * answered or the answer was not a number.
	 *
	 * @param WC_Product $product
	 * @param int        $quantity
	 * @return float|null
	 */
	public static function price_for( WC_Product $product, $quantity ) {
		$base = $product->get_price();
		if ( '' === $base || null === $base ) {
			return null;
		}

		// Someone else's rule engine runs inside this call. Their documented examples
		// invoke the filter with two, three or four arguments, so their callback has
		// defaults — but a future signature change, or another plugin hooking the
		// same name badly, must not take a product page down with it. A throw here
		// means "no answer", which is already a state this class handles.
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_PRICE is Woo Discount Rules' own hook ('advanced_woo_discount_rules_get_product_discount_price'); we invoke THEIR filter to ask their rule engine for its price. Prefixing it would break the integration.
			$answer = apply_filters( self::FILTER_PRICE, (float) $base, $product, (int) $quantity );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::log_exception( $e, array( 'filter' => self::FILTER_PRICE ) );
			}
			return null;
		}

		if ( ! is_numeric( $answer ) ) {
			return null;
		}

		$answer = (float) $answer;

		return ( $answer > 0 ) ? $answer : null;
	}

	/**
	 * Whether the engine's quantity-1 answer agrees with the catalogue price.
	 *
	 * A disagreement is not an error — it means the engine is setting the shelf
	 * price, and `effective_unit_price()` hands that figure to everything that
	 * publishes a price. See the class header.
	 *
	 * @param WC_Product $product
	 * @return array{agrees:bool,catalogue:float,engine:float|null}
	 */
	public static function base_agreement( WC_Product $product ) {
		$catalogue = (float) $product->get_price();
		$engine    = self::price_for( $product, 1 );

		if ( null === $engine ) {
			return array( 'agrees' => false, 'catalogue' => $catalogue, 'engine' => null );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$agrees   = ( round( $catalogue, $decimals ) === round( $engine, $decimals ) );

		return array( 'agrees' => $agrees, 'catalogue' => $catalogue, 'engine' => $engine );
	}

	// ── Discovery ─────────────────────────────────────────────────────────────

	/**
	 * Walk the ladder and collapse it into tiers.
	 *
	 * Runs only where may_discover() allows, and stores the result so the front end
	 * can read it without touching the engine. Consecutive quantities that return
	 * the same price collapse into one tier, so "buy 5 through 9 for £40.50" is one
	 * row rather than five.
	 *
	 * @param WC_Product $product
	 * @return array Resolved tiers: { min, max, unit_price, source }.
	 */
	public static function discover( WC_Product $product ) {
		if ( ! self::is_available() ) {
			return array();
		}

		$agreement = self::base_agreement( $product );
		if ( null === $agreement['engine'] ) {
			self::store( $product, array(), 'no_answer', $agreement );
			return array();
		}

		// The engine's quantity-1 answer is the price for one unit — and where it
		// differs from the catalogue figure, it is the price the storefront actually
		// renders (verified against Flycart on the test site: the page showed the
		// engine's number struck through against the catalogue one). So it becomes
		// the shelf price this plugin publishes, via effective_unit_price(), and the
		// tiers below are measured against it. Everything then sits on one base.
		$unit_at_one = $agreement['engine'];
		$decimals    = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		// Probe the ladder.
		$readings = array();
		foreach ( self::LADDER as $qty ) {
			$price = self::price_for( $product, $qty );
			if ( null === $price ) {
				continue;
			}
			$price = round( $price, $decimals );
			// Only a *reduction* is a quantity break. An engine that raises the
			// price with volume is describing something else, and we will not
			// dress it up as a discount.
			if ( $price >= round( $unit_at_one, $decimals ) ) {
				continue;
			}
			$readings[ $qty ] = $price;
		}

		if ( empty( $readings ) ) {
			self::store( $product, array(), 'no_breaks', $agreement );
			return array();
		}

		// Collapse consecutive rungs that quote the same price.
		$tiers   = array();
		$current = null;
		foreach ( $readings as $qty => $price ) {
			if ( null !== $current && $current['unit_price'] === $price ) {
				continue; // Same price, still inside the current tier.
			}
			if ( null !== $current ) {
				$current['max'] = $qty - 1;
				$tiers[]        = $current;
			}
			$current = array(
				'min'        => (int) $qty,
				'max'        => 0,
				'unit_price' => $price,
				'source'     => 'detected',
			);
		}
		if ( null !== $current ) {
			$tiers[] = $current; // Last tier is open-ended.
		}

		$tiers = array_slice( $tiers, 0, self::MAX_TIERS );

		// Convert to the store's displayed tax treatment, so a tier sits in the same
		// terms as the Offer price beside it and as the Merchant Center feed.
		foreach ( $tiers as $i => $tier ) {
			$display = function_exists( 'wc_get_price_to_display' )
				? wc_get_price_to_display( $product, array( 'price' => $tier['unit_price'] ) )
				: $tier['unit_price'];
			$tiers[ $i ]['unit_price'] = round( (float) $display, $decimals );
		}

		self::store( $product, $tiers, 'ok', $agreement );

		return $tiers;
	}

	// ── The shelf price ───────────────────────────────────────────────────────

	/**
	 * The raw single-unit price the engine will actually charge, when that differs
	 * from the catalogue figure. Null means "nothing to correct".
	 *
	 * ── Why this exists ───────────────────────────────────────────────────────
	 * A dynamic pricing plugin can discount from quantity one. Flycart's own sample
	 * rule does exactly that. It rewrites the *displayed* price (`get_price_html`)
	 * and charges the discount in the cart, but leaves `get_price()` alone — so the
	 * product page renders `~~$45.00~~ $42.75` while `wc_get_price_to_display()`
	 * still says 45. Publishing 45 puts our structured data at odds with the page a
	 * shopper is looking at, which is a Google price mismatch, and it makes the
	 * Merchant Center comparison report a disagreement that is ours, not the feed's.
	 *
	 * Reads the cache only — never probes. Discovery runs in admin, cron or CLI, so
	 * a product that has never been scanned simply falls through to the normal path
	 * rather than making a front-end page view wait on someone else's rule engine.
	 *
	 * @param WC_Product|null $product
	 * @return float|null
	 */
	public static function effective_unit_price_raw( $product ) {
		if ( ! $product instanceof WC_Product || ! self::is_available() ) {
			return null;
		}
		if ( ! class_exists( 'TWTAEO_Price_Tiers' ) || ! TWTAEO_Price_Tiers::is_enabled() ) {
			return null;
		}

		$cached = self::cached( $product );
		if ( null === $cached || null === $cached['engine'] ) {
			return null;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$engine   = round( (float) $cached['engine'], $decimals );

		if ( $engine <= 0 || $engine === round( (float) $cached['catalogue'], $decimals ) ) {
			return null;
		}

		// A rule that *raises* the single-unit price is not a shelf price we will
		// publish on the merchant's behalf — surcharges and role-based markups are
		// not what this is for, and quietly inflating a published price is worse
		// than leaving the catalogue figure alone.
		if ( $engine > (float) $cached['catalogue'] ) {
			return null;
		}

		return $engine;
	}

	/**
	 * The same figure in the store's displayed tax treatment, ready for schema.
	 *
	 * @param WC_Product|null $product
	 * @return float|null
	 */
	public static function effective_unit_price( $product ) {
		$raw = self::effective_unit_price_raw( $product );
		if ( null === $raw ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_price_to_display' ) ) {
			return $raw;
		}
		return (float) wc_get_price_to_display( $product, array( 'price' => $raw ) );
	}

	/** Whether a product's shelf price is coming from the pricing engine. */
	public static function has_engine_shelf_price( $product ) {
		return null !== self::effective_unit_price_raw( $product );
	}

	// ── Cache ─────────────────────────────────────────────────────────────────

	/**
	 * Persist a discovery result against the price it was computed from.
	 *
	 * @param WC_Product $product
	 * @param array      $tiers
	 * @param string     $status ok | no_breaks | no_answer
	 * @param array      $agreement
	 */
	private static function store( WC_Product $product, array $tiers, $status, array $agreement ) {
		update_post_meta( $product->get_id(), self::META_TIERS, array(
			'version'   => self::CACHE_VERSION,
			'base'      => (string) $product->get_price(),
			'status'    => $status,
			'tiers'     => $tiers,
			'engine'    => $agreement['engine'],
			'catalogue' => $agreement['catalogue'],
			'at'        => time(),
		) );
	}

	/**
	 * Read a stored discovery, or null when there is none that still applies.
	 *
	 * A cache computed against a different price is discarded rather than used:
	 * the merchant changing a price is exactly when a stale tier becomes a lie.
	 *
	 * @param WC_Product $product
	 * @return array|null
	 */
	public static function cached( WC_Product $product ) {
		$stored = get_post_meta( $product->get_id(), self::META_TIERS, true );

		if ( ! is_array( $stored ) || empty( $stored['version'] ) ) {
			return null;
		}
		if ( (int) $stored['version'] !== self::CACHE_VERSION ) {
			return null;
		}
		if ( (string) $stored['base'] !== (string) $product->get_price() ) {
			return null;
		}

		return $stored;
	}

	/**
	 * The tiers to publish for a product: cached where possible, discovered where
	 * this request is allowed to.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	public static function tiers_for( WC_Product $product ) {
		if ( ! self::is_available() ) {
			return array();
		}

		$cached = self::cached( $product );
		if ( null !== $cached ) {
			return ( 'ok' === $cached['status'] ) ? $cached['tiers'] : array();
		}

		if ( ! self::may_discover() ) {
			// Front end with a cold cache. Publishing nothing is correct: a tier we
			// have not verified against the engine is exactly what this class exists
			// to avoid.
			return array();
		}

		return self::discover( $product );
	}

	/**
	 * Why a product has no detected tiers, for the admin to report.
	 *
	 * @param WC_Product $product
	 * @return string One of: unavailable, unscanned, ok, no_breaks, no_answer.
	 */
	public static function status_for( WC_Product $product ) {
		if ( ! self::is_available() ) {
			return 'unavailable';
		}
		$cached = self::cached( $product );
		if ( null === $cached ) {
			return 'unscanned';
		}
		return (string) $cached['status'];
	}

	/**
	 * Human-readable version of status_for().
	 *
	 * @param string     $status
	 * @param WC_Product $product
	 * @return string
	 */
	public static function status_label( $status, ?WC_Product $product = null ) {
		switch ( $status ) {
			case 'ok':
				return __( 'Tier prices read from your pricing plugin.', 'twt-aeo-ultimate' );
			case 'no_breaks':
				return __( 'Your pricing plugin charges the same price at every quantity for this product, so there is no quantity break to publish.', 'twt-aeo-ultimate' );
			case 'no_answer':
				return __( 'Your pricing plugin did not return a price for this product, so nothing is read from it.', 'twt-aeo-ultimate' );
			case 'unscanned':
				return __( 'Not scanned yet. Save the product, or run a scan from the Price Rules tab.', 'twt-aeo-ultimate' );
		}
		return __( 'No pricing plugin is answering, so tier prices cannot be read from one.', 'twt-aeo-ultimate' );
	}

	// ── Invalidation and bulk scan ────────────────────────────────────────────

	public static function register_hooks() {
		// A price edit is exactly when a stored tier becomes a lie. cached() also
		// checks the base price, so this is belt and braces — but it also clears
		// a stale engine price the merchant has since changed.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'forget' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'forget' ) );
		add_action( 'save_post_product', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Drop a product's stored discovery, and its parent's.
	 *
	 * @param int $product_id
	 */
	public static function forget( $product_id ) {
		$product_id = (int) $product_id;
		delete_post_meta( $product_id, self::META_TIERS );

		$parent = wp_get_post_parent_id( $product_id );
		if ( $parent ) {
			delete_post_meta( $parent, self::META_TIERS );
		}

		$product = wc_get_product( $product_id );
		if ( TWTAEO_WC_Extensions::is_variable_like( $product ) ) {
			foreach ( $product->get_children() as $child_id ) {
				delete_post_meta( (int) $child_id, self::META_TIERS );
			}
		}
	}

	/**
	 * Scan a bounded batch of the catalogue.
	 *
	 * Bounded because each product costs a ladder of calls into someone else's rule
	 * engine, and an unbounded loop over a large catalogue is a timeout. The admin
	 * page reports how far it got and lets the merchant run it again.
	 *
	 * @param int $limit  Products to scan.
	 * @param int $offset Where to start.
	 * @return array{scanned:int,with_tiers:int,engine_priced:int,total:int,next:int}
	 */
	public static function scan_batch( $limit = 25, $offset = 0 ) {
		$result = array( 'scanned' => 0, 'with_tiers' => 0, 'engine_priced' => 0, 'total' => 0, 'next' => 0 );

		if ( ! self::is_available() ) {
			return $result;
		}

		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'offset'         => (int) $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$result['total'] = (int) $query->found_posts;

		foreach ( $query->posts as $post_id ) {
			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$targets = TWTAEO_WC_Extensions::is_variable_like( $product )
				? array_filter( array_map( 'wc_get_product', $product->get_children() ) )
				: array( $product );

			$found  = false;
			$engine = false;
			foreach ( $targets as $target ) {
				$tiers = self::discover( $target );
				if ( ! empty( $tiers ) ) {
					$found = true;
				}
				if ( self::has_engine_shelf_price( $target ) ) {
					$engine = true;
				}
			}

			$result['scanned']++;
			if ( $found ) {
				$result['with_tiers']++;
			}
			if ( $engine ) {
				$result['engine_priced']++;
			}
		}

		$result['next'] = ( $offset + $result['scanned'] < $result['total'] )
			? $offset + $result['scanned']
			: 0;

		return $result;
	}
}
