<?php
/**
 * WooCommerce Extension Compatibility
 *
 * WooCommerce is a base, not a whole. Bookings, Subscriptions, Bundles and the
 * rest each add product types whose `get_price()` does not mean what it means on
 * a simple product — it is a *starting* figure the storefront renders as
 * "From: $X" — and each adds rules (a minimum order quantity, a billing period)
 * that change what a published Offer is actually promising.
 *
 * Publishing a starting figure as a definite `price` is the exact failure this
 * plugin exists to prevent everywhere else: schema that contradicts the page the
 * shopper is reading. So this class answers two questions for the writers:
 *
 *   1. **Does this product have one price, a range, or a floor?**
 *      `offer_strategy()` — asked of the product object, not of a plugin list,
 *      so an extension we have never heard of that subclasses a variable product
 *      is still handled correctly.
 *   2. **Who is responsible, so the merchant can be told by name?**
 *      `detected()` / `notices()` — a registry, used only for labelling and
 *      admin warnings. If an entry here is wrong the schema is still right.
 *
 * That split is deliberate. Detection registries rot: version constants get
 * renamed, plugin directories get forked. Nothing that decides what goes in the
 * JSON-LD is allowed to depend on one.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_WC_Extensions {

	/** Cache key for the per-type product census shown in the admin. */
	const CENSUS_TRANSIENT = 'twtaeo_wc_ext_census';

	/** How long the census may be stale. It only drives an admin warning. */
	const CENSUS_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Meta keys written by the Min/Max Quantities extension.
	 *
	 * Unprefixed, which is unusual and worth stating: these are the names the
	 * extension's own documentation gives under "Meta:" for its import/export
	 * columns. Because they are generic enough for another plugin to have picked
	 * the same words, they are only ever read once Min/Max has been detected.
	 *
	 * @link https://woocommerce.com/document/minmax-quantities/
	 */
	const META_MIN_QTY   = 'minimum_allowed_quantity';
	const META_MAX_QTY   = 'maximum_allowed_quantity';
	const META_GROUP_QTY = 'group_of_quantity';

	/** Where the developer simulator's settings live. */
	const OPTION_SIMULATE = 'twtaeo_simulate_extensions';

	/** @var array|null Per-request cache of detected extensions. */
	private static $detected = null;

	/** @var array|null Per-request cache of simulator settings. */
	private static $simulation = null;

	/**
	 * Set while building a baseline for the simulator's before/after preview.
	 *
	 * @var bool
	 */
	private static $suspend_simulation = false;

	// ── Developer simulator ───────────────────────────────────────────────────

	/**
	 * Whether pretending an extension is installed is permitted here.
	 *
	 * These extensions are paid, so the only way to see what this plugin does
	 * with a booking or a required add-on — without buying seven licences — is to
	 * pretend. That is a developer tool and a liability anywhere else: a store
	 * left simulating Bookings would publish "from" prices for products that have
	 * a real fixed price.
	 *
	 * So it is gated on the environment, not on a checkbox. A saved simulation is
	 * ignored outright on a live site rather than merely hidden — if this code is
	 * deployed with the option still set, it does nothing.
	 *
	 * @return bool
	 */
	public static function simulation_allowed() {
		// An explicit constant wins in both directions, so a staging box can opt in
		// and a dev machine can opt out.
		if ( defined( 'TWTAEO_SIMULATE_EXTENSIONS' ) ) {
			return (bool) TWTAEO_SIMULATE_EXTENSIONS;
		}
		return function_exists( 'twtaeo_is_local_environment' ) && twtaeo_is_local_environment();
	}

	/** @return array The empty simulation — what a live site always sees. */
	private static function simulation_defaults() {
		return array(
			'extensions' => array(),
			'product_id' => 0,
			'as_type'    => '',
			'min_qty'    => 0,
			'max_qty'    => 0,
			'addon_min'  => 0.0,
		);
	}

	/**
	 * Current simulator settings, normalised.
	 *
	 * @return array
	 */
	public static function simulation() {
		if ( self::$suspend_simulation || ! self::simulation_allowed() ) {
			return self::simulation_defaults();
		}
		if ( null !== self::$simulation ) {
			return self::$simulation;
		}

		$saved = get_option( self::OPTION_SIMULATE, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::simulation_defaults();

		if ( ! empty( $saved['extensions'] ) && is_array( $saved['extensions'] ) ) {
			// Only slugs this plugin actually knows about.
			$out['extensions'] = array_values( array_intersect( array_keys( self::known() ), $saved['extensions'] ) );
		}
		$out['product_id'] = isset( $saved['product_id'] ) ? absint( $saved['product_id'] ) : 0;
		$out['as_type']    = isset( $saved['as_type'] ) ? sanitize_key( $saved['as_type'] ) : '';
		$out['min_qty']    = isset( $saved['min_qty'] ) ? absint( $saved['min_qty'] ) : 0;
		$out['max_qty']    = isset( $saved['max_qty'] ) ? absint( $saved['max_qty'] ) : 0;
		$out['addon_min']  = isset( $saved['addon_min'] ) ? max( 0, (float) $saved['addon_min'] ) : 0.0;

		// Pretending a product is a booking without also pretending Bookings is
		// installed would produce nothing: the type only reads as indicative while
		// its owner is detected. Implying it removes a way to mis-configure this
		// and then conclude the feature is broken.
		if ( '' !== $out['as_type'] ) {
			foreach ( self::known() as $slug => $def ) {
				if ( in_array( $out['as_type'], self::vector( $def, 'types' ), true )
					&& ! in_array( $slug, $out['extensions'], true ) ) {
					$out['extensions'][] = $slug;
				}
			}
		}
		// Same for the two product-level knobs and their owners.
		if ( ( $out['min_qty'] || $out['max_qty'] ) && ! in_array( 'min-max-quantities', $out['extensions'], true ) ) {
			$out['extensions'][] = 'min-max-quantities';
		}
		if ( $out['addon_min'] > 0 && ! in_array( 'product-addons', $out['extensions'], true ) ) {
			$out['extensions'][] = 'product-addons';
		}

		self::$simulation = $out;
		return $out;
	}

	/**
	 * Product types the simulator can pretend a product is, as type => label.
	 *
	 * Only the types that visibly change the published Offer are offered. There is
	 * deliberately no `variable-subscription` entry: on a variable product it
	 * produces byte-identical output to `variable`, because treating them the same
	 * *is* the fix. There would be nothing to look at.
	 *
	 * @return array<string,string>
	 */
	/**
	 * Every extension the simulator can pretend is installed, as slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function simulatable_extensions() {
		$out = array();
		foreach ( self::known() as $slug => $def ) {
			$out[ $slug ] = $def['label'];
		}
		return $out;
	}

	public static function simulatable_types() {
		$out = array();
		foreach ( self::known() as $def ) {
			if ( empty( $def['indicative'] ) ) {
				continue;
			}
			foreach ( self::vector( $def, 'types' ) as $type ) {
				$out[ $type ] = $def['label'] . ' — ' . $type;
			}
		}
		return $out;
	}

	/** @return bool Whether anything is currently being pretended. */
	public static function is_simulating() {
		$sim = self::simulation();
		return ! empty( $sim['extensions'] ) || ! empty( $sim['as_type'] )
			|| $sim['min_qty'] || $sim['max_qty'] || $sim['addon_min'] > 0;
	}

	/**
	 * Store simulator settings. Refuses outright where simulation is not allowed,
	 * so there is no path that writes this on a live store.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function save_simulation( array $settings ) {
		if ( ! self::simulation_allowed() ) {
			return false;
		}
		update_option( self::OPTION_SIMULATE, $settings, false );
		self::reset_caches();
		return true;
	}

	/** Forget the per-request caches so a saved change is visible immediately. */
	public static function reset_caches() {
		self::$detected   = null;
		self::$simulation = null;
		self::flush_census();
	}

	/**
	 * Run a callback with the simulator switched off, for before/after previews.
	 *
	 * @param callable $callback
	 * @return mixed
	 */
	public static function without_simulation( $callback ) {
		$was                      = self::$suspend_simulation;
		self::$suspend_simulation = true;
		self::$detected           = null;

		try {
			return call_user_func( $callback );
		} finally {
			self::$suspend_simulation = $was;
			self::$detected           = null;
		}
	}

	/**
	 * The simulated settings that apply to one product, if it is the one under
	 * test. Product-level pretending is scoped to a single product on purpose —
	 * a catalogue-wide override would make every other preview a lie.
	 *
	 * @param WC_Product $product
	 * @return array Empty when this product is not the one being simulated.
	 */
	private static function simulation_for( $product ) {
		$sim = self::simulation();
		if ( ! $sim['product_id'] || ! $product instanceof WC_Product ) {
			return array();
		}
		return ( (int) $product->get_id() === (int) $sim['product_id'] ) ? $sim : array();
	}

	/**
	 * The census counts published products by type, so it goes stale whenever one
	 * is saved or removed, and whenever a plugin is switched on or off.
	 */
	public static function register_hooks() {
		add_action( 'save_post_product', array( __CLASS__, 'flush_census' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_census' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush_census' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_census' ) );
	}

	// ── Registry ──────────────────────────────────────────────────────────────

	/**
	 * Extensions we recognise by name.
	 *
	 * Matched on any of directory / class / constant / function, because none is
	 * reliable alone: a constant can be renamed between versions, a class can be
	 * lazy-loaded, and a directory can be forked. Any single hit is enough.
	 *
	 * Every entry below was checked against the extension's own published
	 * documentation or against WooCommerce's own integration code — not from
	 * memory. Where a vector could not be confirmed it is still listed, because a
	 * name that never matches costs nothing while a missing one costs a detection.
	 *
	 * `types`      — product types the extension registers.
	 * `indicative` — whether those types price as "from X" rather than "X".
	 * `impact`     — plain-English note shown to the merchant.
	 *
	 * @return array<string,array>
	 */
	private static function known() {
		return array(
			'bookings' => array(
				'label'      => 'WooCommerce Bookings',
				'dirs'       => array( 'woocommerce-bookings' ),
				'classes'    => array( 'WC_Bookings', 'WC_Product_Booking', 'WC_Product_Booking_Rule_Manager' ),
				'constants'  => array( 'WC_BOOKINGS_VERSION' ),
				'functions'  => array( 'get_wc_booking', 'is_wc_booking_product' ),
				'types'      => array( 'booking' ),
				'indicative' => true,
				'impact'     => 'A booking\'s price depends on the date, duration and number of people, so the stored price is a starting figure. Booking products are published as a "from" price rather than a fixed one.',
			),
			'subscriptions' => array(
				'label'      => 'WooCommerce Subscriptions',
				'dirs'       => array( 'woocommerce-subscriptions' ),
				'classes'    => array( 'WC_Subscriptions', 'WC_Subscriptions_Product', 'WC_Product_Subscription', 'WC_Product_Variable_Subscription' ),
				'constants'  => array( 'WC_SUBSCRIPTIONS_VERSION' ),
				'functions'  => array( 'wcs_get_subscription', 'wcs_is_subscription' ),
				'types'      => array( 'subscription', 'variable-subscription', 'subscription_variation' ),
				'indicative' => false,
				'impact'     => 'Variable subscriptions are published as a price range across their variations. The recurring period, sign-up fee and free trial are not yet expressed in the Offer — the published figure is the recurring amount only.',
			),
			'product-bundles' => array(
				'label'      => 'Product Bundles',
				'dirs'       => array( 'woocommerce-product-bundles' ),
				'classes'    => array( 'WC_Bundles', 'WC_Product_Bundle' ),
				'constants'  => array( 'WC_PB_VERSION' ),
				'functions'  => array( 'WC_PB', 'wc_pb_get_bundled_product_map' ),
				'types'      => array( 'bundle' ),
				'indicative' => true,
				'impact'     => 'A bundle costs whatever its contents are configured to, so its stored price is the cheapest configuration and it is published as a "from" price. If a bundle of yours really does carry one fixed price, the twtaeo_offer_strategy filter can say so per product.',
			),
			'composite-products' => array(
				'label'      => 'Composite Products',
				'dirs'       => array( 'woocommerce-composite-products' ),
				'classes'    => array( 'WC_Composite_Products', 'WC_Product_Composite' ),
				'constants'  => array( 'WC_CP_VERSION' ),
				'functions'  => array( 'WC_CP' ),
				'types'      => array( 'composite' ),
				'indicative' => true,
				'impact'     => 'A composite product is priced from its selected components, so it is published as a "from" price.',
			),
			'product-addons' => array(
				'label'      => 'Product Add-Ons',
				'dirs'       => array( 'woocommerce-product-addons' ),
				'classes'    => array( 'WC_Product_Addons', 'WC_Product_Addons_Helper' ),
				'constants'  => array( 'WC_PRODUCT_ADDONS_VERSION' ),
				'functions'  => array( 'get_product_addons' ),
				'types'      => array(),
				'indicative' => false,
				'impact'     => 'Optional add-ons do not change the published price, which is correct — the base price is what a shopper can pay. A product with a *required* paid add-on cannot be bought at its base price, so that product is published as a "from" price instead of a fixed one. Global add-on groups are counted too.',
			),
			'min-max-quantities' => array(
				'label'      => 'Min/Max Quantities',
				'dirs'       => array( 'woocommerce-min-max-quantities' ),
				'classes'    => array( 'WC_Min_Max_Quantities' ),
				'constants'  => array( 'WC_MIN_MAX_QUANTITIES_VERSION' ),
				'functions'  => array(),
				'types'      => array(),
				'indicative' => false,
				'impact'     => 'A minimum order quantity is published as eligibleQuantity on the Offer, so an AI assistant quoting your price also knows the smallest order you accept.',
			),
			// PluginEver's free plugin, not the paid WooCommerce.com extension. A
			// store that wants order-quantity rules without buying one will almost
			// certainly be running this, so it gets first-class support.
			'min-max-quantities-free' => array(
				'label'      => 'Min Max Quantities (PluginEver)',
				'dirs'       => array( 'wc-min-max-quantities' ),
				'classes'    => array( 'WooCommerceMinMaxQuantities\\Plugin' ),
				'constants'  => array(),
				'functions'  => array( 'wcmmq_get_product_limits' ),
				'types'      => array(),
				'indicative' => false,
				'impact'     => 'A minimum order quantity is published as eligibleQuantity on the Offer, so an AI assistant quoting your price also knows the smallest order you accept. Read through the plugin\'s own wcmmq_get_product_limits(), so global rules and per-product overrides are both respected.',
			),
			'shipment-tracking' => array(
				'label'      => 'Shipment Tracking',
				'dirs'       => array( 'woocommerce-shipment-tracking' ),
				'classes'    => array( 'WC_Shipment_Tracking' ),
				'constants'  => array( 'WC_SHIPMENT_TRACKING_VERSION' ),
				'functions'  => array( 'wc_st_get_formatted_tracking_items' ),
				'types'      => array(),
				'indicative' => false,
				'impact'     => 'Order-level and post-purchase. It does not touch product schema, and this plugin publishes no order schema, so the two never meet.',
			),
		);
	}

	// ── Detection ─────────────────────────────────────────────────────────────

	/**
	 * Which recognised extensions are active on this request.
	 *
	 * @return array<string,array> Slug => definition.
	 */
	public static function detected() {
		if ( null !== self::$detected ) {
			return self::$detected;
		}

		$active    = self::active_plugin_dirs();
		$pretended = self::simulation();
		$pretended = $pretended['extensions'];
		$found     = array();

		foreach ( self::known() as $slug => $def ) {
			$real = self::matches( $def, $active );
			$fake = in_array( $slug, $pretended, true );

			if ( ! $real && ! $fake ) {
				continue;
			}
			// Flagged so the admin can never mistake a pretend detection for a
			// real one — an unlabelled simulation is just a bug you believe.
			$def['simulated'] = ( $fake && ! $real );
			$found[ $slug ]   = $def;
		}

		self::$detected = $found;
		return $found;
	}

	/**
	 * @param string $slug
	 * @return bool
	 */
	public static function is_active( $slug ) {
		$found = self::detected();
		return isset( $found[ $slug ] );
	}

	/**
	 * @param array $def
	 * @param array $active Directory name => true.
	 * @return bool
	 */
	private static function matches( array $def, array $active ) {
		foreach ( self::vector( $def, 'dirs' ) as $dir ) {
			if ( isset( $active[ $dir ] ) ) {
				return true;
			}
		}
		foreach ( self::vector( $def, 'classes' ) as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}
		foreach ( self::vector( $def, 'constants' ) as $const ) {
			if ( defined( $const ) ) {
				return true;
			}
		}
		// Instance accessors like WC_PB() and WC_CP() are the vector these
		// extensions' own integration plugins use, so they are the most likely of
		// the four to survive a refactor.
		foreach ( self::vector( $def, 'functions' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One detection vector from a registry entry, tolerating a missing key so a
	 * hand-added entry cannot fatal the whole plugin.
	 *
	 * @param array  $def
	 * @param string $key
	 * @return array
	 */
	private static function vector( array $def, $key ) {
		return isset( $def[ $key ] ) && is_array( $def[ $key ] ) ? $def[ $key ] : array();
	}

	/**
	 * Active plugin directory names, keyed for O(1) lookup.
	 *
	 * @return array<string,bool>
	 */
	private static function active_plugin_dirs() {
		$plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$plugins = array_merge( $plugins, array_keys( $network ) );
		}

		$dirs = array();
		foreach ( $plugins as $file ) {
			$parts = explode( '/', (string) $file );
			if ( '' !== $parts[0] ) {
				$dirs[ $parts[0] ] = true;
			}
		}
		return $dirs;
	}

	// ── Product shape ─────────────────────────────────────────────────────────

	/**
	 * Whether a product behaves like a variable parent — a group whose price is a
	 * range across children.
	 *
	 * `is_type( 'variable' )` is a strict string comparison, so it answers *false*
	 * for `variable-subscription` even though WC_Product_Variable_Subscription
	 * extends WC_Product_Variable and behaves identically for every purpose this
	 * plugin has. Testing the class instead of the type string is what makes this
	 * correct for extensions that have not been written yet.
	 *
	 * @param mixed $product
	 * @return bool
	 */
	public static function is_variable_like( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( $product->is_type( 'variable' ) ) {
			return true;
		}
		if ( class_exists( 'WC_Product_Variable' ) && $product instanceof WC_Product_Variable ) {
			return true;
		}
		return false;
	}

	/**
	 * How this product's price should be published.
	 *
	 *   'range' — a variant group. AggregateOffer with lowPrice + highPrice.
	 *   'from'  — a configurable product whose stored price is its cheapest
	 *             configuration. AggregateOffer with lowPrice only, which is what
	 *             "From: $X" means in schema.org's vocabulary.
	 *   'exact' — one price, payable as published. A plain Offer.
	 *
	 * Every test here is asked of the product object. A product type this plugin
	 * has never heard of falls through to 'exact', which is the behaviour it
	 * already had — new extensions degrade to today's output rather than to a
	 * crash or to silence.
	 *
	 * @param mixed $product
	 * @return string range|from|exact
	 */
	public static function offer_strategy( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 'exact';
		}

		$strategy = 'exact';

		// Under simulation, the chosen product answers as whatever type is being
		// pretended. Nothing is written to the product — this is read-only theatre
		// for one product id.
		$sim  = self::simulation_for( $product );
		$type = ! empty( $sim['as_type'] ) ? $sim['as_type'] : $product->get_type();

		if ( self::is_variable_like( $product ) ) {
			$strategy = 'range';
		} elseif ( in_array( $type, self::indicative_types(), true ) ) {
			// Product Bundles and Composite Products used to answer this outright:
			// a bundle could be flagged as statically priced, and a static price is
			// a real one that a floor would understate. Product Bundles 5.0 moved
			// the decision down to each bundled item ("Priced Individually"), so on
			// current versions there is no single bundle-level answer to ask for.
			//
			// The flag is therefore treated as an override rather than a gate: when
			// it is present and says the price is fixed, that is believed. When it
			// is absent — which is the normal case now — a floor is the reading that
			// cannot overstate. Stores that know better can say so through the
			// filter below.
			$fixed = method_exists( $product, 'is_priced_per_product' ) && ! $product->is_priced_per_product();

			$strategy = $fixed ? 'exact' : 'from';
		}

		// A required paid add-on means the base price is not payable: the shopper
		// cannot reach the checkout without adding the surcharge. Publishing the
		// base as a definite `price` asserts a transaction that cannot happen.
		//
		// The surcharge is deliberately NOT added to the published figure. The
		// product page shows the base price until the shopper configures the
		// add-ons, and a schema price the page does not show is the same mismatch
		// from the other direction. A floor is true from both sides.
		if ( 'exact' === $strategy && self::required_addon_minimum( $product ) > 0 ) {
			$strategy = 'from';
		}

		/**
		 * Filter the offer strategy for one product.
		 *
		 * The escape hatch for a store that knows something this plugin cannot ask
		 * for — a bundle it knows carries a fixed price, or a custom product type
		 * whose price is really a floor. Return 'exact', 'from' or 'range'.
		 *
		 * @param string     $strategy range|from|exact
		 * @param WC_Product $product
		 */
		$filtered = (string) apply_filters( 'twtaeo_offer_strategy', $strategy, $product );

		return in_array( $filtered, array( 'range', 'from', 'exact' ), true ) ? $filtered : $strategy;
	}

	/**
	 * Product types whose stored price is a floor, from detected extensions only.
	 *
	 * @return string[]
	 */
	private static function indicative_types() {
		$types = array();
		foreach ( self::detected() as $def ) {
			if ( ! empty( $def['indicative'] ) ) {
				$types = array_merge( $types, self::vector( $def, 'types' ) );
			}
		}
		return $types;
	}

	/**
	 * The extension responsible for a product's type, for naming in the admin.
	 *
	 * @param mixed $product
	 * @return string Label, or '' when it is a core type.
	 */
	public static function owner_label( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$type = $product->get_type();
		foreach ( self::detected() as $def ) {
			if ( in_array( $type, self::vector( $def, 'types' ), true ) ) {
				return $def['label'];
			}
		}
		return '';
	}

	/**
	 * Whether shipping and return facts belong on this product's Offer.
	 *
	 * A subscription, a booking and a downloadable are never put in a box, so a
	 * shipping rate and a ship-it-back return window are not facts about them.
	 * This is not extension-specific — a plain virtual product in core
	 * WooCommerce has the same problem.
	 *
	 * @param mixed $product
	 * @return bool
	 */
	public static function is_shippable( $product ) {
		if ( ! $product instanceof WC_Product ) {
			// No product object means no evidence either way. Keep the previous
			// behaviour rather than silently dropping a merchant's shipping data.
			return true;
		}
		if ( method_exists( $product, 'needs_shipping' ) ) {
			return (bool) $product->needs_shipping();
		}
		return true;
	}

	// ── Product Add-Ons ───────────────────────────────────────────────────────

	/**
	 * Every add-on that applies to a product, global groups included.
	 *
	 * Read through the extension's own helper rather than from post meta, because
	 * a store's global add-on groups are not stored on the product and reading the
	 * meta alone would miss them — a required, priced add-on applied store-wide is
	 * exactly the case that matters here.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	private static function addons_for( $product ) {
		if ( ! self::is_active( 'product-addons' )
			|| ! class_exists( 'WC_Product_Addons_Helper' )
			|| ! method_exists( 'WC_Product_Addons_Helper', 'get_product_addons' ) ) {
			return array();
		}

		$addons = WC_Product_Addons_Helper::get_product_addons( $product->get_id() );

		return is_array( $addons ) ? $addons : array();
	}

	/**
	 * The smallest surcharge a shopper cannot avoid, at quantity one.
	 *
	 * Field names follow the add-on schema in the extension's REST API reference:
	 * `required`, `type`, `price`, `price_type` (flat_fee | quantity_based |
	 * percentage_based), `min`, and `options` each carrying their own `label`,
	 * `price`, `price_type` and `visibility`.
	 *
	 * @link https://woocommerce.com/document/product-add-ons-rest-api-reference/
	 *
	 * Every lookup is guarded, so an add-on shape this does not recognise scores
	 * zero and the product keeps its ordinary treatment. Being wrong here should
	 * cost a missed warning, never a wrong price.
	 *
	 * @param mixed $product
	 * @param float $base Product price, for percentage-based add-ons.
	 * @return float
	 */
	public static function required_addon_minimum( $product, $base = 0.0 ) {
		if ( ! $product instanceof WC_Product ) {
			return 0.0;
		}

		$sim = self::simulation_for( $product );
		if ( ! empty( $sim['addon_min'] ) ) {
			return (float) $sim['addon_min'];
		}

		$total = 0.0;

		foreach ( self::addons_for( $product ) as $addon ) {
			if ( ! is_array( $addon ) || empty( $addon['required'] ) ) {
				continue;
			}

			$type = isset( $addon['type'] ) ? $addon['type'] : '';
			if ( 'heading' === $type ) {
				continue; // Never priced — it is a label.
			}

			$total += self::addon_floor( $addon, $type, $base );
		}

		return $total;
	}

	/**
	 * The unavoidable cost of one required add-on.
	 *
	 * @param array  $addon
	 * @param string $type
	 * @param float  $base
	 * @return float
	 */
	private static function addon_floor( array $addon, $type, $base ) {
		// Choice add-ons: the shopper must pick one, so the cheapest option they
		// can actually see is the floor. A free option among them means no floor.
		if ( in_array( $type, array( 'multiple_choice', 'checkbox' ), true ) ) {
			if ( empty( $addon['options'] ) || ! is_array( $addon['options'] ) ) {
				return 0.0;
			}
			$cheapest = null;
			foreach ( $addon['options'] as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}
				// `visibility` is documented as a boolean on each option; a hidden
				// option is not a choice, so it cannot set the floor.
				if ( array_key_exists( 'visibility', $option ) && ! $option['visibility'] ) {
					continue;
				}
				$cost     = self::addon_price( $option, $base );
				$cheapest = ( null === $cheapest ) ? $cost : min( $cheapest, $cost );
			}
			return null === $cheapest ? 0.0 : max( 0.0, $cheapest );
		}

		// The shopper names their own figure; `min` is the smallest allowed.
		if ( 'custom_price' === $type ) {
			return isset( $addon['min'] ) ? max( 0.0, (float) $addon['min'] ) : 0.0;
		}

		// Priced per unit entered, so the floor is the smallest entry allowed.
		if ( 'input_multiplier' === $type ) {
			$min = isset( $addon['min'] ) ? (float) $addon['min'] : 0.0;
			return max( 0.0, self::addon_price( $addon, $base ) * $min );
		}

		// custom_text, custom_textarea, file_upload, datepicker — filling in a
		// required field costs whatever the add-on itself costs.
		return max( 0.0, self::addon_price( $addon, $base ) );
	}

	/**
	 * One add-on or option's price at quantity one.
	 *
	 * `quantity_based` multiplies by the product quantity, which is one here, so
	 * it lands on the same figure as `flat_fee`.
	 *
	 * @param array $row
	 * @param float $base
	 * @return float
	 */
	private static function addon_price( array $row, $base ) {
		$price = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
		if ( 0.0 === $price ) {
			return 0.0;
		}

		$type = isset( $row['price_type'] ) ? $row['price_type'] : 'flat_fee';

		if ( 'percentage_based' === $type ) {
			return $base > 0 ? ( $base * $price / 100 ) : 0.0;
		}

		return $price;
	}

	// ── Order quantity rules ──────────────────────────────────────────────────

	/**
	 * The smallest order this product accepts, per Min/Max Quantities.
	 *
	 * Published as `eligibleQuantity` so that a price of £12 with a minimum of 6
	 * reads as "£12 each, six minimum" rather than as an order a shopper can
	 * place and then cannot.
	 *
	 * Only the product-level rule is read. Min/Max also supports a store-wide
	 * default and per-variation values; neither is unambiguously true for the one
	 * Offer being published here, and a guess would be worse than silence.
	 *
	 * @param mixed $product
	 * @return int 0 when there is no rule worth publishing.
	 */
	public static function min_quantity( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		$sim = self::simulation_for( $product );
		if ( ! empty( $sim['min_qty'] ) ) {
			return (int) $sim['min_qty'] > 1 ? (int) $sim['min_qty'] : 0;
		}

		$min = 0;
		if ( self::is_active( 'min-max-quantities' ) ) {
			$min = (int) get_post_meta( $product->get_id(), self::META_MIN_QTY, true );
		}

		$free = self::free_minmax_limits( $product );
		if ( isset( $free['min_qty'] ) && (int) $free['min_qty'] > $min ) {
			$min = (int) $free['min_qty'];
		}

		// WooCommerce core has its own answer, which any extension is free to
		// override on the product object. Read it too and take whichever floor is
		// higher: a rule from either source is a rule a shopper will hit.
		if ( method_exists( $product, 'get_min_purchase_quantity' ) ) {
			$core = (int) $product->get_min_purchase_quantity();
			if ( $core > $min ) {
				$min = $core;
			}
		}

		// A minimum of one is core's default and states nothing.
		return $min > 1 ? $min : 0;
	}

	/**
	 * The largest order this product accepts, per Min/Max Quantities.
	 *
	 * @param mixed $product
	 * @return int 0 when there is no rule.
	 */
	public static function max_quantity( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		$sim = self::simulation_for( $product );
		if ( ! empty( $sim['max_qty'] ) ) {
			return (int) $sim['max_qty'];
		}

		$max = 0;
		if ( self::is_active( 'min-max-quantities' ) ) {
			$max = (int) get_post_meta( $product->get_id(), self::META_MAX_QTY, true );
		}

		// This plugin uses 0 for "no ceiling", so only a positive figure counts.
		$free = self::free_minmax_limits( $product );
		if ( isset( $free['max_qty'] ) ) {
			$free_max = (int) $free['max_qty'];
			if ( $free_max > 0 && ( $max <= 0 || $free_max < $max ) ) {
				$max = $free_max;
			}
		}

		// Core returns -1 for "unlimited", so only a positive figure is a ceiling.
		// The tighter of the two wins — the looser one is not enforceable.
		if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
			$core = (int) $product->get_max_purchase_quantity();
			if ( $core > 0 && ( $max <= 0 || $core < $max ) ) {
				$max = $core;
			}
		}

		return $max > 0 ? $max : 0;
	}

	/**
	 * Order-quantity limits from PluginEver's free Min Max Quantities plugin.
	 *
	 * Asked through the plugin's own `wcmmq_get_product_limits()` rather than by
	 * reading its `_wcmmq_*` meta directly, because that function is what resolves
	 * the whole picture: the per-product override flag, the store-wide defaults it
	 * falls back to, per-product exclusions, and its own filter. Reading the meta
	 * would see a product-level number the plugin itself is ignoring, or miss a
	 * global rule that is being enforced.
	 *
	 * Returns `step`, `min_qty`, `max_qty`, `min_total`, `max_total` and `rule`.
	 * Only the two quantity figures are used here — the totals are cart-wide and
	 * say nothing about one product's Offer.
	 *
	 * @param WC_Product $product
	 * @return array Empty when the plugin is not present.
	 */
	private static function free_minmax_limits( $product ) {
		if ( ! self::is_active( 'min-max-quantities-free' ) || ! function_exists( 'wcmmq_get_product_limits' ) ) {
			return array();
		}

		// Variations carry their own rules, and the function takes the parent id
		// plus a variation id rather than one product id.
		$parent_id    = $product->get_parent_id();
		$variation_id = $parent_id ? $product->get_id() : 0;
		$product_id   = $parent_id ? $parent_id : $product->get_id();

		$limits = wcmmq_get_product_limits( $product_id, $variation_id );

		return is_array( $limits ) ? $limits : array();
	}

	/**
	 * A schema.org QuantitativeValue for the order-quantity rules on a product.
	 *
	 * @param mixed $product
	 * @return array|null
	 */
	public static function eligible_quantity( $product ) {
		$min = self::min_quantity( $product );
		$max = self::max_quantity( $product );

		if ( ! $min && ! $max ) {
			return null;
		}

		// C62 is the UN/CEFACT code for "one" — a countable unit. Without it the
		// numbers are dimensionless and a consumer has to guess whether 6 means
		// six items or six kilos.
		$qty = array(
			'@type'    => 'QuantitativeValue',
			'unitCode' => 'C62',
		);
		if ( $min ) {
			$qty['minValue'] = $min;
		}
		if ( $max ) {
			$qty['maxValue'] = $max;
		}
		return $qty;
	}

	// ── Admin reporting ───────────────────────────────────────────────────────

	/**
	 * How many published products carry each extension-registered type.
	 *
	 * Cached — it drives a warning panel, not a decision, and a count that is six
	 * hours old is worth far more than a COUNT(*) per admin page load.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string,int> Product type => count.
	 */
	public static function type_census( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CENSUS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$census = array();

		if ( function_exists( 'wc_get_products' ) ) {
			foreach ( self::detected() as $def ) {
				foreach ( self::vector( $def, 'types' ) as $type ) {
					// subscription_variation and the like are children, never
					// listed on their own. Counting them would double-count.
					if ( false !== strpos( $type, '_variation' ) ) {
						continue;
					}
					$ids = wc_get_products(
						array(
							'type'   => $type,
							'status' => 'publish',
							'limit'  => -1,
							'return' => 'ids',
						)
					);
					$count = is_array( $ids ) ? count( $ids ) : 0;
					if ( $count > 0 ) {
						$census[ $type ] = $count;
					}
				}
			}
		}

		set_transient( self::CENSUS_TRANSIENT, $census, self::CENSUS_TTL );
		return $census;
	}

	/** Drop the cached census — called when a product is saved. */
	public static function flush_census() {
		delete_transient( self::CENSUS_TRANSIENT );
	}

	/**
	 * What the merchant should be told, if anything.
	 *
	 * Each entry: level (info|warning), title, body, and an optional `advice`
	 * naming the module to switch off when the honest answer is that this plugin
	 * cannot describe the product safely.
	 *
	 * @return array<int,array>
	 */
	public static function notices() {
		$found = self::detected();
		if ( empty( $found ) ) {
			return array();
		}

		$census = self::type_census();
		$out    = array();

		foreach ( $found as $slug => $def ) {
			$count = 0;
			foreach ( self::vector( $def, 'types' ) as $type ) {
				$count += isset( $census[ $type ] ) ? $census[ $type ] : 0;
			}

			$note = array(
				'slug'      => $slug,
				'level'     => ! empty( $def['indicative'] ) && $count > 0 ? 'warning' : 'info',
				'title'     => $def['label'],
				'count'     => $count,
				'body'      => $def['impact'],
				'advice'    => '',
				'simulated' => ! empty( $def['simulated'] ),
			);

			// The one case where switching a module off is the right advice: the
			// Local Store inventory feed publishes per-location stock for things
			// a shopper collects from a shelf. A booking has no shelf, so a feed
			// row for one is a claim about the world that is simply not true.
			if ( 'bookings' === $slug && $count > 0
				&& class_exists( 'TWTAEO_Local_Inventory_Feed' )
				&& class_exists( 'TWTAEO_Store_Location' )
				&& TWTAEO_Store_Location::is_enabled() ) {
				$note['advice'] = __( 'Your Local Store inventory feed publishes shelf stock per location. Booking products have no shelf stock, so either exclude them from the feed or switch the Local Store module off while you sell bookings.', 'twt-aeo-ultimate' );
			}

			$out[] = $note;
		}

		return $out;
	}

	/**
	 * One-line summary for the Diagnostics support report.
	 *
	 * @return string
	 */
	public static function report_line() {
		$found = self::detected();
		if ( empty( $found ) ) {
			return 'none detected';
		}

		$census = self::type_census();
		$parts  = array();

		foreach ( $found as $def ) {
			$count = 0;
			foreach ( self::vector( $def, 'types' ) as $type ) {
				$count += isset( $census[ $type ] ) ? $census[ $type ] : 0;
			}
			$parts[] = $def['label'] . ( $count ? ' (' . $count . ' products)' : '' );
		}

		return implode( ', ', $parts );
	}
}
