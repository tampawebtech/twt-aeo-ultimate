<?php
/**
 * Coupon and price-rule normalisation — the data layer the Promotions module reads.
 *
 * ── What this class will and will not read ────────────────────────────────────
 * Every coupon plugin worth supporting (Advanced Coupons, StoreApps Smart Coupons,
 * WebToffee Smart Coupons, Power Coupons) stores its coupons as real `shop_coupon`
 * posts and extends `WC_Coupon` rather than replacing it. So reading WooCommerce's
 * own first-party API gets us genuine data for all of them without touching a
 * single undocumented meta key.
 *
 * What we deliberately do **not** do is guess at their internals. Their BOGO
 * structures, cart-condition trees and store-credit balances live in private meta
 * whose shape is not published, and a wrong guess here does not fail loudly — it
 * produces confident, incorrect structured data. So a rule this class cannot read
 * is reported as unreadable, with the plugin named. That is the same call the Local
 * Store module made about geocoding: entered or absent, never invented.
 *
 * Flycart's Discount Rules is the one that is not a coupon plugin at all. It is
 * dynamic pricing — it rewrites the cart price without a code — and it keeps its
 * rules in its own `wdr_*` tables, not in `shop_coupon`. It therefore appears here
 * only as a **price-integrity risk**: if something is changing displayed prices
 * that we cannot read, the price in our schema and in the Merchant Center feed may
 * not be the price the shopper is charged, and the merchant needs to be told that
 * rather than reassured.
 *
 * ── Why almost nothing here becomes an Offer price ────────────────────────────
 * A coupon code is a *cart-level* fact. The shopper sees the undiscounted price on
 * the product page and the discount appears after they type the code at checkout.
 * Writing the discounted figure into `Offer.price` — or into a bare
 * `priceSpecification` that a crawler will read as the price — breaks the rule that
 * marked-up price must equal the price on the landing page, and it puts the JSON-LD
 * at odds with our own GMC and BMC feed engines, which send the catalogue price. A
 * price mismatch is a Merchant Center disapproval, self-inflicted from two of our
 * own modules disagreeing.
 *
 * Coupon codes therefore leave this plugin through two doors only:
 *   1. A Merchant Center **promotion**, which is the channel Google actually built
 *      for "use SUMMER10 for 10% off" (see TWTAEO_Promotions_Feed).
 *   2. A **visible** FAQ pair on the product page, marked up only because it is
 *      rendered (see TWTAEO_Offer_Pricing).
 *
 * Conditional *prices* in schema come from TWTAEO_Price_Tiers instead, where the
 * condition is machine-readable (`eligibleQuantity`, `validForMemberTier`).
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Coupon_Rules {

	/** Cache key for the normalised rule set. Short — coupons change by hand. */
	const CACHE_KEY = 'twtaeo_coupon_rules';
	const CACHE_TTL = 300;

	/**
	 * WooCommerce core discount types we can reason about.
	 *
	 * Anything outside this list is a third-party type (Advanced Coupons' BOGO,
	 * store credit, and so on). Those are recorded and reported, never interpreted.
	 */
	const CORE_TYPES = array( 'percent', 'fixed_product', 'fixed_cart' );

	// ── Plugin detection ──────────────────────────────────────────────────────

	/**
	 * Coupon and pricing plugins we recognise.
	 *
	 * Detection is by plugin **directory name** and by public constants/classes —
	 * both are stable, published identifiers. Nothing here reads a private data
	 * structure. `dynamic_pricing` marks a plugin that can change the price a
	 * shopper is shown without a coupon code, which is the price-integrity case.
	 *
	 * @return array<string,array>
	 */
	private static function known_plugins() {
		return array(
			'advanced-coupons' => array(
				'label'      => __( 'Advanced Coupons', 'twt-aeo-ultimate' ),
				'dirs'       => array( 'advanced-coupons-for-woocommerce-free', 'advanced-coupons-for-woocommerce' ),
				// Verified against Advanced Coupons (free) on the test site: it defines
				// no public version constant, so the bootstrap class is the identifier.
				'constants'  => array(),
				'classes'    => array( 'ACFWF', 'ACFWP' ),
				'wc_coupons' => true,
				'dynamic'    => false,
				'note'       => __( 'Stores its coupons as WooCommerce coupons, so codes, amounts, product and category limits and expiry dates are read directly. BOGO structures and cart-condition trees live in private meta and are not read.', 'twt-aeo-ultimate' ),
			),
			'storeapps-smart-coupons' => array(
				'label'      => __( 'Smart Coupons (StoreApps)', 'twt-aeo-ultimate' ),
				'dirs'       => array( 'woocommerce-smart-coupons' ),
				'constants'  => array( 'WC_SC_PLUGIN_FILE' ),
				'classes'    => array( 'WC_Smart_Coupons' ),
				'wc_coupons' => true,
				'dynamic'    => false,
				'note'       => __( 'Gift certificates and store credit are account balances, not product prices, and have no honest product-page representation. Its ordinary discount coupons are read as WooCommerce coupons.', 'twt-aeo-ultimate' ),
			),
			'webtoffee-smart-coupons' => array(
				'label'      => __( 'Smart Coupons for WooCommerce (WebToffee)', 'twt-aeo-ultimate' ),
				'dirs'       => array( 'wt-smart-coupons-for-woocommerce', 'wt-smart-coupons-for-woocommerce-pro' ),
				// Verified against WebToffee Smart Coupons 2.3.0 on the test site.
				'constants'  => array( 'WEBTOFFEE_SMARTCOUPON_VERSION', 'WT_SC_PLUGIN_NAME' ),
				'classes'    => array(),
				'wc_coupons' => true,
				'dynamic'    => false,
				'note'       => __( 'Its coupons are WooCommerce coupons. Auto-apply and cart-count conditions are private and are not read, so every coupon is treated as requiring a code — the conservative assumption for structured data.', 'twt-aeo-ultimate' ),
			),
			'power-coupons' => array(
				'label'      => __( 'Power Coupons', 'twt-aeo-ultimate' ),
				'dirs'       => array( 'power-coupons', 'power-coupons-for-woocommerce' ),
				'constants'  => array( 'POWER_COUPONS_VERSION' ),
				'classes'    => array(),
				'wc_coupons' => true,
				'dynamic'    => false,
				'note'       => __( 'Spend-threshold progress bars are a cart interface, not a product fact. Its coupon records are read as WooCommerce coupons.', 'twt-aeo-ultimate' ),
			),
			'flycart-discount-rules' => array(
				'label'      => __( 'Discount Rules for WooCommerce (Flycart)', 'twt-aeo-ultimate' ),
				'dirs'       => array( 'woo-discount-rules', 'woo-discount-rules-pro' ),
				// Verified against Discount Rules 2.6.16 on the test site — the
				// guessed names were wrong and only the directory match saved it.
				'constants'  => array( 'WDR_VERSION', 'WDR_SLUG', 'WOO_DISCOUNT_PLUGIN_BASENAME' ),
				'classes'    => array(),
				'wc_coupons' => false,
				'dynamic'    => true,
				'note'       => __( 'Dynamic pricing, not coupons: it changes the price without a code, and keeps its rules in its own database tables. Those rules cannot be read back — but it publishes a documented filter that answers what it will charge for a product at a given quantity, and that is read instead. Tier prices come from its own answers rather than from anything typed by hand.', 'twt-aeo-ultimate' ),
			),
		);
	}

	/**
	 * Which recognised plugins are actually active.
	 *
	 * @return array<string,array> slug => definition, with 'label' and 'note'.
	 */
	public static function detected_plugins() {
		$found  = array();
		$active = self::active_plugin_dirs();

		foreach ( self::known_plugins() as $slug => $def ) {
			$hit = false;

			foreach ( $def['dirs'] as $dir ) {
				if ( isset( $active[ $dir ] ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				foreach ( $def['constants'] as $const ) {
					if ( defined( $const ) ) {
						$hit = true;
						break;
					}
				}
			}
			if ( ! $hit ) {
				foreach ( $def['classes'] as $class ) {
					if ( class_exists( $class ) ) {
						$hit = true;
						break;
					}
				}
			}

			if ( $hit ) {
				$found[ $slug ] = $def;
			}
		}

		return $found;
	}

	/**
	 * Active plugins we do not recognise but whose directory name suggests they
	 * price things.
	 *
	 * Reported as "unrecognised", never as understood. A merchant running something
	 * we have never heard of that rewrites prices deserves the warning far more than
	 * a merchant running a plugin we support — and silently listing nothing would
	 * read as "nothing else is touching your prices", which we cannot know.
	 *
	 * @return array<string,string> dir => human-ish label.
	 */
	public static function unrecognised_pricing_plugins() {
		$known = array();
		foreach ( self::known_plugins() as $def ) {
			foreach ( $def['dirs'] as $dir ) {
				$known[ $dir ] = true;
			}
		}

		$suspects = array();
		foreach ( array_keys( self::active_plugin_dirs() ) as $dir ) {
			if ( isset( $known[ $dir ] ) ) {
				continue;
			}
			if ( ! preg_match( '/(coupon|discount|dynamic-pric|pricing|bogo|bulk-price|tier)/i', $dir ) ) {
				continue;
			}
			$suspects[ $dir ] = ucwords( str_replace( '-', ' ', $dir ) );
		}

		return $suspects;
	}

	/**
	 * Directory names of every active plugin, including network-activated ones.
	 *
	 * @return array<string,bool>
	 */
	private static function active_plugin_dirs() {
		$basenames = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$basenames = array_merge( $basenames, array_keys( $network ) );
			}
		}

		$dirs = array();
		foreach ( $basenames as $basename ) {
			$basename = (string) $basename;
			$dir      = ( false !== strpos( $basename, '/' ) )
				? substr( $basename, 0, strpos( $basename, '/' ) )
				: $basename;
			if ( '' !== $dir ) {
				$dirs[ $dir ] = true;
			}
		}

		return $dirs;
	}

	/**
	 * Whether anything active can change a displayed price without a coupon code.
	 *
	 * This is the question that decides whether our schema price and our Merchant
	 * Center feed price can be trusted to agree with the storefront.
	 *
	 * @return array<string,string> slug => label of each dynamic pricing plugin.
	 */
	public static function dynamic_pricing_owners() {
		$owners = array();
		foreach ( self::detected_plugins() as $slug => $def ) {
			if ( ! empty( $def['dynamic'] ) ) {
				$owners[ $slug ] = $def['label'];
			}
		}
		return $owners;
	}

	// ── Rule normalisation ────────────────────────────────────────────────────

	/**
	 * Every published coupon, normalised.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array List of normalised rules.
	 */
	public static function all_rules( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rules = array();

		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) ) {
			return $rules;
		}

		$ids = get_posts( array(
			'post_type'        => 'shop_coupon',
			'post_status'      => 'publish',
			'posts_per_page'   => 500,
			'fields'           => 'ids',
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		) );

		foreach ( $ids as $id ) {
			$rule = self::normalise( (int) $id );
			if ( $rule ) {
				$rules[] = $rule;
			}
		}

		set_transient( self::CACHE_KEY, $rules, self::CACHE_TTL );

		return $rules;
	}

	/** Drop the cached rule set. Hooked to coupon saves and deletes. */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Normalise one coupon into the shape every consumer here reads.
	 *
	 * @param int $coupon_id
	 * @return array|null
	 */
	public static function normalise( $coupon_id ) {
		$coupon = new WC_Coupon( $coupon_id );

		$code = $coupon->get_code();
		if ( '' === $code ) {
			return null;
		}

		$type    = (string) $coupon->get_discount_type();
		$created = $coupon->get_date_created();

		// ⚠ `get_date_expires()` is filterable, and Advanced Coupons filters it.
		// Verified against Advanced Coupons (free) on the test site: it mirrors
		// WooCommerce's `date_expires` into its own `schedule_end`, then returns
		// **null** from the getter while the coupon is inside that window — its way
		// of saying "I have taken over expiry, WooCommerce's own check is off".
		//
		// Read naively that is indistinguishable from "this coupon never expires",
		// which would be badly wrong in both directions: no end date to send to
		// Merchant Center, and no "Ends…" on the page for a coupon that does end.
		//
		// So both contexts are read. The unfiltered value is the merchant's own
		// stored end date and is used when the filter has hidden it. The flag is
		// kept so the admin can say a scheduler is also involved — worth stating,
		// because a scheduler could in principle hold a different date than the one
		// WooCommerce stores, and only the merchant can confirm they agree.
		//
		// Note `expired` still comes from the *filtered* value: a null there means
		// the scheduler considers the coupon live right now, which is a better
		// answer than comparing a stored date to the clock ourselves.
		$expires   = $coupon->get_date_expires();
		$stored    = $coupon->get_date_expires( 'edit' );
		$scheduled = ( null === $expires && $stored instanceof WC_DateTime );
		$end       = $expires instanceof WC_DateTime ? $expires : ( $scheduled ? $stored : null );

		$rule = array(
			'id'            => (int) $coupon_id,
			'code'          => $code,
			'label'         => get_the_title( $coupon_id ) ?: strtoupper( $code ),
			'description'   => (string) $coupon->get_description(),
			'discount_type' => $type,
			'is_core_type'  => in_array( $type, self::CORE_TYPES, true ),
			'amount'        => (float) $coupon->get_amount(),
			'free_shipping' => (bool) $coupon->get_free_shipping(),

			// Scope.
			'product_ids'          => array_map( 'intval', (array) $coupon->get_product_ids() ),
			'excluded_product_ids' => array_map( 'intval', (array) $coupon->get_excluded_product_ids() ),
			'category_ids'         => array_map( 'intval', (array) $coupon->get_product_categories() ),
			'excluded_category_ids' => array_map( 'intval', (array) $coupon->get_excluded_product_categories() ),
			'exclude_sale_items'   => (bool) $coupon->get_exclude_sale_items(),

			// Cart conditions.
			'minimum_amount' => (float) $coupon->get_minimum_amount(),
			'maximum_amount' => (float) $coupon->get_maximum_amount(),

			// Restrictions that make a promotion non-public.
			'email_restrictions' => array_filter( (array) $coupon->get_email_restrictions() ),
			'usage_limit'        => (int) $coupon->get_usage_limit(),
			'usage_count'        => (int) $coupon->get_usage_count(),
			'individual_use'     => (bool) $coupon->get_individual_use(),

			// Dates, as plain Y-m-d for schema and feed use.
			'date_from' => $created ? $created->date( 'Y-m-d' ) : '',
			'date_to'   => $end ? $end->date( 'Y-m-d' ) : '',
			'expired'   => (bool) ( $expires && $expires->getTimestamp() < time() ),

			// A scheduling plugin is also managing this coupon's window. The end
			// date above is still the merchant's own stored one; this only says
			// something else has a say in it, which the admin surfaces so the
			// merchant can check the two agree before pushing a promotion.
			'schedule_managed' => $scheduled,
		);

		$verdict                = self::classify( $rule );
		$rule['promotable']     = $verdict['promotable'];
		$rule['skip_reason']    = $verdict['reason'];
		$rule['scope']          = self::scope_label( $rule );

		return $rule;
	}

	/**
	 * Decide whether a coupon can honestly become a public Merchant Center promotion.
	 *
	 * Every rejection carries a reason, because a merchant must never have to guess
	 * why one of their coupons is missing — the same standard the sitemap generator
	 * holds itself to for excluded products.
	 *
	 * @param array $rule
	 * @return array{promotable:bool,reason:string}
	 */
	private static function classify( array $rule ) {
		$no = function ( $reason ) {
			return array( 'promotable' => false, 'reason' => $reason );
		};

		if ( $rule['expired'] ) {
			return $no( __( 'Expired. Its end date has already passed.', 'twt-aeo-ultimate' ) );
		}

		if ( ! $rule['is_core_type'] ) {
			return $no( sprintf(
				/* translators: %s: the coupon's discount type slug. */
				__( 'Uses the custom discount type "%s" from a coupon plugin. Its rules are stored privately and are not read, so we will not describe a deal we cannot verify.', 'twt-aeo-ultimate' ),
				$rule['discount_type']
			) );
		}

		if ( ! empty( $rule['email_restrictions'] ) ) {
			return $no( __( 'Restricted to specific email addresses, so it is a private offer rather than a public promotion.', 'twt-aeo-ultimate' ) );
		}

		if ( $rule['usage_limit'] > 0 && $rule['usage_count'] >= $rule['usage_limit'] ) {
			return $no( __( 'Its usage limit is already spent.', 'twt-aeo-ultimate' ) );
		}

		if ( $rule['amount'] <= 0 && ! $rule['free_shipping'] ) {
			return $no( __( 'Discounts nothing — the amount is zero and free shipping is off.', 'twt-aeo-ultimate' ) );
		}

		return array( 'promotable' => true, 'reason' => '' );
	}

	/**
	 * Human summary of what a coupon applies to.
	 *
	 * @param array $rule
	 * @return string
	 */
	private static function scope_label( array $rule ) {
		if ( ! empty( $rule['product_ids'] ) ) {
			return sprintf(
				/* translators: %d: number of products. */
				_n( '%d specific product', '%d specific products', count( $rule['product_ids'] ), 'twt-aeo-ultimate' ),
				count( $rule['product_ids'] )
			);
		}
		if ( ! empty( $rule['category_ids'] ) ) {
			return sprintf(
				/* translators: %d: number of categories. */
				_n( '%d category', '%d categories', count( $rule['category_ids'] ), 'twt-aeo-ultimate' ),
				count( $rule['category_ids'] )
			);
		}
		return __( 'Whole store', 'twt-aeo-ultimate' );
	}

	// ── Per-product lookup ────────────────────────────────────────────────────

	/**
	 * The coupons that apply to one product.
	 *
	 * Mirrors WooCommerce's own inclusion logic: an explicit product or category
	 * list narrows the coupon to those; an exclusion list removes the product; a
	 * coupon with neither applies store-wide. Sale-item exclusion is honoured
	 * because a coupon that excludes sale items genuinely does not apply to a
	 * product that is on sale.
	 *
	 * @param WC_Product|null $product
	 * @return array
	 */
	public static function rules_for_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product_id = (int) $product->get_id();
		$parent_id  = (int) $product->get_parent_id();
		$ids        = array_filter( array( $product_id, $parent_id ) );
		$cat_ids    = wp_get_post_terms( $parent_id ?: $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$cat_ids    = is_wp_error( $cat_ids ) ? array() : array_map( 'intval', $cat_ids );

		$matches = array();

		foreach ( self::all_rules() as $rule ) {
			if ( ! $rule['promotable'] ) {
				continue;
			}
			if ( array_intersect( $ids, $rule['excluded_product_ids'] ) ) {
				continue;
			}
			if ( array_intersect( $cat_ids, $rule['excluded_category_ids'] ) ) {
				continue;
			}
			if ( $rule['exclude_sale_items'] && $product->is_on_sale() ) {
				continue;
			}

			$scoped = ! empty( $rule['product_ids'] ) || ! empty( $rule['category_ids'] );
			if ( $scoped ) {
				$in_products   = (bool) array_intersect( $ids, $rule['product_ids'] );
				$in_categories = (bool) array_intersect( $cat_ids, $rule['category_ids'] );
				if ( ! $in_products && ! $in_categories ) {
					continue;
				}
			}

			$matches[] = $rule;
		}

		return $matches;
	}

	// ── Formatting helpers shared by the schema writer and the admin page ─────

	/**
	 * A money amount as plain text.
	 *
	 * `wc_price()` returns markup **and HTML entities** — a dollar sign comes back as
	 * `&#36;`. Both of these strings end up in JSON-LD and in a Merchant Center
	 * payload, neither of which decodes entities, so a raw `wp_strip_all_tags()`
	 * here publishes "&#36;25.00 off" to Google. Decoding is the same rule
	 * CLAUDE.md already states for `get_price_html()` and `wp_get_document_title()`.
	 *
	 * @param float $amount
	 * @return string
	 */
	public static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * "10% off" / "$15 off" — formatted with the store's own currency settings.
	 *
	 * @param array $rule
	 * @return string
	 */
	public static function discount_label( array $rule ) {
		if ( 'percent' === $rule['discount_type'] ) {
			/* translators: %s: percentage, e.g. "10". */
			return sprintf( __( '%s%% off', 'twt-aeo-ultimate' ), wc_format_localized_decimal( $rule['amount'] ) );
		}

		if ( in_array( $rule['discount_type'], array( 'fixed_product', 'fixed_cart' ), true ) ) {
			/* translators: %s: formatted money amount. */
			return sprintf( __( '%s off', 'twt-aeo-ultimate' ), self::money( $rule['amount'] ) );
		}

		if ( $rule['free_shipping'] ) {
			return __( 'Free shipping', 'twt-aeo-ultimate' );
		}

		return $rule['discount_type'];
	}

	/**
	 * A one-line, plain-English statement of the deal and its conditions.
	 *
	 * Used both as the visible FAQ answer on the product page and as the promotion
	 * title in the Merchant Center payload, so the two never disagree.
	 *
	 * @param array $rule
	 * @return string
	 */
	public static function plain_terms( array $rule ) {
		$parts = array( self::discount_label( $rule ) );

		if ( $rule['minimum_amount'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: formatted money amount. */
				__( 'on orders over %s', 'twt-aeo-ultimate' ),
				self::money( $rule['minimum_amount'] )
			);
		}

		if ( 'fixed_cart' === $rule['discount_type'] ) {
			$parts[] = __( 'applied to the cart total', 'twt-aeo-ultimate' );
		}

		$text = implode( ' ', $parts );

		if ( '' !== $rule['date_to'] ) {
			$text .= '. ' . sprintf(
				/* translators: %s: date. */
				__( 'Ends %s', 'twt-aeo-ultimate' ),
				date_i18n( get_option( 'date_format' ), strtotime( $rule['date_to'] ) )
			);
		}

		return $text . '.';
	}
}
