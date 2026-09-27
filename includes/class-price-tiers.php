<?php
/**
 * Quantity tiers and member tiers — the only conditional prices this plugin will
 * publish, and the data model behind them.
 *
 * ── Why these two and nothing else ────────────────────────────────────────────
 * A conditional price is only safe to publish when the *condition* is machine
 * readable. Schema.org gives exactly two ways to say that on an Offer:
 *
 *   `eligibleQuantity`    — this price applies when you buy at least N.
 *   `validForMemberTier`  — this price applies if you are in this membership tier.
 *
 * Both make it unambiguous that the figure is not the shelf price, so a crawler
 * cannot mistake it for one. A bare `priceSpecification` carrying a lower number
 * has no such guard, which is why coupon-code discounts never become one here —
 * they would read as a price the page does not show, and that is a Merchant Center
 * price mismatch waiting to happen. See TWTAEO_Coupon_Rules for the long version.
 *
 * `eligibleTransactionVolume` (spend over £1,000 and this price applies) is real
 * vocabulary and is supported here, but only *inside* a spec that already carries
 * one of the two guards above. Alone it is an unguarded low price.
 *
 * ── Why the tiers are typed in rather than detected ───────────────────────────
 * WooCommerce core has no quantity-tier or role-price concept at all, and the
 * plugins that add one (Flycart above all) keep their rules in private tables. So
 * a tier is entered by the merchant, exactly as store coordinates are in the Local
 * Store module: a value the merchant can supply in thirty seconds is not worth
 * inventing, and an invented one is confidently wrong.
 *
 * That creates one risk worth naming, and this class exists partly to catch it:
 * **a merchant can declare a tier that nothing on the store actually applies.** The
 * page would then advertise a price the checkout will not honour. `has_enforcement()`
 * answers "is anything here capable of applying this?" and the admin page refuses to
 * be quiet about a no.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Price_Tiers {

	const OPTION_KEY = 'twtaeo_price_tiers';
	const NONCE_SAVE = 'twtaeo_price_tiers_save';

	/** Per-product override. */
	const META_MODE  = '_twtaeo_tier_mode';
	const META_TIERS = '_twtaeo_tiers';

	/** Google's benefit value for a tier that grants a better price. */
	const BENEFIT_LOYALTY_PRICE = 'https://schema.org/TierBenefitLoyaltyPrice';

	/** How a tier row expresses its discount. */
	const ADJUST_TYPES = array( 'percent', 'fixed', 'price' );

	// ── Defaults and storage ──────────────────────────────────────────────────

	public static function defaults() {
		return array(
			'enabled'               => 0,
			'output_quantity_tiers' => 1,
			'output_member_tiers'   => 0,

			// Store-wide quantity tiers. Each row:
			// { min:int, max:int|0, type:percent|fixed|price, value:float }
			'store_tiers'           => array(),

			// Cart-spend threshold attached to quantity tiers, in store currency.
			// Optional; 0 means none. Only ever emitted alongside eligibleQuantity.
			'transaction_volume'    => 0,

			// Membership pricing.
			'member_program_name'   => '',
			'member_tiers'          => array(),

			// The merchant's explicit acknowledgement that they have wired the tiers
			// up somewhere. Recorded rather than assumed — see has_enforcement().
			'enforcement_confirmed' => 0,

			// ── On-page output ────────────────────────────────────────────────
			// This record is the settings store for the whole Promotions module,
			// including what it renders on the storefront, because placement and
			// markup are one decision: schema is only emitted for something the
			// visitor can see. 'auto' renders for us, 'shortcode' means the
			// merchant placed it themselves, 'off' renders and marks up nothing.
			'tier_table_placement'  => 'auto',
			'output_coupon_note'    => 0,
			'coupon_note_placement' => 'auto',
			'coupon_note_limit'     => 3,
		);
	}

	/** Placement values accepted by both on-page blocks. */
	const PLACEMENTS = array( 'auto', 'shortcode', 'off' );

	/** Stored settings with defaults filled in. */
	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		$data  = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$data['store_tiers']  = self::clean_tiers( $data['store_tiers'] );
		$data['member_tiers'] = self::clean_member_tiers( $data['member_tiers'] );

		return $data;
	}

	/**
	 * Both gates, as everywhere else in this plugin: the module has to be active AND
	 * the merchant has to have switched this on. Activating a module must never start
	 * publishing prices.
	 */
	public static function is_enabled() {
		$s = self::get();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( class_exists( 'TWTAEO_Module_Loader' ) ) {
			$active = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
			if ( is_array( $active ) && ! in_array( 'promotions', $active, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitise and persist.
	 *
	 * @param array $raw Raw $_POST slice, already unslashed by the caller.
	 * @return array The stored settings.
	 */
	public static function save( array $raw ) {
		$out = self::defaults();

		foreach ( array( 'enabled', 'output_quantity_tiers', 'output_member_tiers', 'enforcement_confirmed', 'output_coupon_note' ) as $flag ) {
			$out[ $flag ] = empty( $raw[ $flag ] ) ? 0 : 1;
		}

		foreach ( array( 'tier_table_placement', 'coupon_note_placement' ) as $key ) {
			$value       = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
			$out[ $key ] = in_array( $value, self::PLACEMENTS, true ) ? $value : 'auto';
		}

		// Capped rather than unbounded: every coupon named here is also rendered on
		// the page, and a product carrying twenty store-wide coupons would bury the
		// product description under them.
		$limit                   = isset( $raw['coupon_note_limit'] ) ? (int) $raw['coupon_note_limit'] : 3;
		$out['coupon_note_limit'] = max( 1, min( 10, $limit ) );

		$out['transaction_volume']  = isset( $raw['transaction_volume'] ) ? max( 0, (float) $raw['transaction_volume'] ) : 0;
		$out['member_program_name'] = isset( $raw['member_program_name'] )
			? sanitize_text_field( $raw['member_program_name'] )
			: '';

		$out['store_tiers']  = self::clean_tiers( isset( $raw['store_tiers'] ) ? (array) $raw['store_tiers'] : array() );
		$out['member_tiers'] = self::clean_member_tiers( isset( $raw['member_tiers'] ) ? (array) $raw['member_tiers'] : array() );

		update_option( self::OPTION_KEY, $out );

		return $out;
	}

	// ── Sanitising ────────────────────────────────────────────────────────────

	/**
	 * Clean a set of quantity-tier rows.
	 *
	 * Rows that cannot describe a real price are dropped rather than repaired: a
	 * tier with a zero minimum, an inverted range or a non-positive value is not a
	 * weaker signal, it is a wrong one. Rows are sorted by minimum quantity so the
	 * emitted schema reads in the order a shopper would.
	 *
	 * @param mixed $rows
	 * @return array
	 */
	public static function clean_tiers( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$min   = isset( $row['min'] ) ? (int) $row['min'] : 0;
			$max   = isset( $row['max'] ) ? (int) $row['max'] : 0;
			$type  = isset( $row['type'] ) && in_array( $row['type'], self::ADJUST_TYPES, true ) ? $row['type'] : 'percent';
			$value = isset( $row['value'] ) ? (float) $row['value'] : 0.0;

			// A quantity tier that starts at one is not a tier, it is the price.
			if ( $min < 2 ) {
				continue;
			}
			if ( $max > 0 && $max < $min ) {
				continue;
			}
			if ( $value <= 0 ) {
				continue;
			}
			if ( 'percent' === $type && $value >= 100 ) {
				continue;
			}

			$clean[] = array(
				'min'   => $min,
				'max'   => $max,
				'type'  => $type,
				'value' => $value,
			);
		}

		usort( $clean, function ( $a, $b ) {
			return $a['min'] <=> $b['min'];
		} );

		return $clean;
	}

	/**
	 * Clean member-tier rows.
	 *
	 * A tier is only kept when its role still exists on this site. A tier pointing at
	 * a deleted role describes a membership nobody can be in.
	 *
	 * @param mixed $rows
	 * @return array
	 */
	public static function clean_member_tiers( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$roles = self::available_roles();
		$clean = array();
		$seen  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '';
			if ( '' === $role || ! isset( $roles[ $role ] ) || isset( $seen[ $role ] ) ) {
				continue;
			}

			$type  = isset( $row['type'] ) && in_array( $row['type'], self::ADJUST_TYPES, true ) ? $row['type'] : 'percent';
			$value = isset( $row['value'] ) ? (float) $row['value'] : 0.0;
			if ( $value <= 0 || ( 'percent' === $type && $value >= 100 ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $name ) {
				$name = $roles[ $role ];
			}

			$seen[ $role ] = true;
			$clean[]       = array(
				'role'  => $role,
				'name'  => $name,
				'type'  => $type,
				'value' => $value,
			);
		}

		return $clean;
	}

	/**
	 * Roles a customer could plausibly hold, for the member-tier picker.
	 *
	 * Administrator and shop manager are excluded — a staff account is not a
	 * membership tier, and publishing one as though it were invites a shopper to ask
	 * how to join it.
	 *
	 * @return array<string,string> role => label.
	 */
	public static function available_roles() {
		$roles = array();
		$wp    = wp_roles();

		if ( ! $wp ) {
			return $roles;
		}

		$skip = array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor' );
		foreach ( $wp->get_names() as $key => $label ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$roles[ $key ] = translate_user_role( $label );
		}

		return $roles;
	}

	// ── Per-product resolution ────────────────────────────────────────────────

	/**
	 * The quantity tiers that apply to one product.
	 *
	 * Modes: 'inherit' uses the store-wide set, 'custom' uses the product's own rows,
	 * 'none' publishes no tiers for this product at all.
	 *
	 * @param int $product_id
	 * @return array
	 */
	public static function tiers_for_product( $product_id ) {
		$product_id = (int) $product_id;
		$mode       = get_post_meta( $product_id, self::META_MODE, true );

		if ( 'none' === $mode ) {
			return array();
		}

		if ( 'custom' === $mode ) {
			return self::clean_tiers( get_post_meta( $product_id, self::META_TIERS, true ) );
		}

		$settings = self::get();
		return $settings['store_tiers'];
	}

	/**
	 * Save a product's tier override.
	 *
	 * @param int    $product_id
	 * @param string $mode  inherit | custom | none
	 * @param mixed  $rows
	 */
	public static function save_product_tiers( $product_id, $mode, $rows ) {
		$product_id = (int) $product_id;
		$mode       = in_array( $mode, array( 'inherit', 'custom', 'none' ), true ) ? $mode : 'inherit';

		update_post_meta( $product_id, self::META_MODE, $mode );

		if ( 'custom' === $mode ) {
			update_post_meta( $product_id, self::META_TIERS, self::clean_tiers( $rows ) );
		} else {
			delete_post_meta( $product_id, self::META_TIERS );
		}
	}

	// ── Price arithmetic ──────────────────────────────────────────────────────

	/**
	 * Apply one tier row to a base price.
	 *
	 * The base must already be a *displayed* price — `wc_get_price_to_display()` —
	 * so the tier figure lands in the same tax treatment as the price beside it on
	 * the page and in the Merchant Center feed. Passing a raw `get_price()` here
	 * would produce a tier that disagrees with the shelf price by exactly the tax
	 * rate, which is the subtlest possible version of this bug.
	 *
	 * Returns null when the result is not a sane price — zero, negative, or higher
	 * than the price it is supposed to beat.
	 *
	 * @param float $base
	 * @param array $tier
	 * @return float|null
	 */
	public static function apply_tier( $base, array $tier ) {
		$base = (float) $base;
		if ( $base <= 0 ) {
			return null;
		}

		switch ( $tier['type'] ) {
			case 'percent':
				$price = $base - ( $base * ( (float) $tier['value'] / 100 ) );
				break;
			case 'fixed':
				$price = $base - (float) $tier['value'];
				break;
			case 'price':
				$price = (float) $tier['value'];
				break;
			default:
				return null;
		}

		$price = round( $price, wc_get_price_decimals() );

		if ( $price <= 0 || $price >= $base ) {
			return null;
		}

		return $price;
	}

	/**
	 * The displayed base price for a product, or null when there is not one.
	 *
	 * Variable products are excluded deliberately: their offer is an AggregateOffer
	 * spanning a price *range*, and "10% off when you buy 5" applied to a range has
	 * no single figure to attach it to. Tier pricing is emitted per variation
	 * instead, by the schema writer.
	 *
	 * @param WC_Product|null $product
	 * @return float|null
	 */
	public static function base_price( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_price_to_display' ) ) {
			return null;
		}

		// Where a pricing engine sets the shelf price, that is the base a tier has to
		// beat — the same figure the Offer publishes. Comparing against the untouched
		// catalogue price would call a tier a discount when it is only the shelf
		// price by another name.
		if ( class_exists( 'TWTAEO_Dynamic_Pricing' ) ) {
			$effective = TWTAEO_Dynamic_Pricing::effective_unit_price( $product );
			if ( null !== $effective && $effective > 0 ) {
				return $effective;
			}
		}

		$price = wc_get_price_to_display( $product );
		if ( '' === $price || null === $price ) {
			return null;
		}

		$price = (float) $price;

		return $price > 0 ? $price : null;
	}

	// ── Resolution: the one place tiers become real numbers ───────────────────

	/**
	 * The tiers to publish for one sellable thing — a simple product or a single
	 * variation — already resolved to prices.
	 *
	 * Two sources, and **detected beats declared**. A tier read back from the
	 * pricing engine is what the checkout will charge; a declared tier is what the
	 * merchant believes. When the two disagree, publishing the belief would publish
	 * a price the shopper will not be given.
	 *
	 * Everything downstream — the JSON-LD and the visible table — reads this and
	 * only this, so they cannot quote different figures.
	 *
	 * @param WC_Product $product        A simple product or a variation.
	 * @param bool       $drop_flat_rate Drop "fixed price each" declared tiers,
	 *                                   which are incoherent across a variable
	 *                                   product's differently-priced options.
	 * @return array List of { min, max, unit_price, label, source }.
	 */
	public static function resolve_for( $product, $drop_flat_rate = false ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		// 1. Detected — read back from whatever actually prices this store.
		if ( class_exists( 'TWTAEO_Dynamic_Pricing' ) ) {
			$detected = TWTAEO_Dynamic_Pricing::tiers_for( $product );
			if ( ! empty( $detected ) ) {
				$out = array();
				foreach ( $detected as $tier ) {
					$out[] = array(
						'min'        => (int) $tier['min'],
						'max'        => (int) $tier['max'],
						'unit_price' => (float) $tier['unit_price'],
						/* translators: %s: money amount. */
						'label'      => sprintf( __( '%s each', 'twt-aeo-ultimate' ), self::money( $tier['unit_price'] ) ),
						'source'     => 'detected',
					);
				}
				return self::clamp_to_order_rules( $out, $product );
			}
		}

		// 2. Declared. Tier configuration lives on the parent product, so a
		//    variation reads its parent's rows rather than its own (empty) meta.
		$config_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$declared  = self::tiers_for_product( $config_id );

		if ( $drop_flat_rate ) {
			$declared = array_values( array_filter( $declared, function ( $tier ) {
				return 'price' !== $tier['type'];
			} ) );
		}

		$base = self::base_price( $product );
		if ( null === $base || empty( $declared ) ) {
			return array();
		}

		$out = array();
		foreach ( $declared as $tier ) {
			$price = self::apply_tier( $base, $tier );
			if ( null === $price ) {
				continue;
			}
			$out[] = array(
				'min'        => (int) $tier['min'],
				'max'        => (int) $tier['max'],
				'unit_price' => (float) $price,
				'label'      => self::tier_label( $tier ),
				'source'     => 'declared',
			);
		}

		return self::clamp_to_order_rules( $out, $product );
	}

	/**
	 * Reconcile quantity breaks with the order quantities the store actually
	 * accepts (Min/Max Quantities).
	 *
	 * A break at quantity 3 on a product with a six-item minimum is not a discount
	 * a shopper can take — it is a row in a table describing an order the cart will
	 * reject. Publishing it in schema is worse still, because an AI assistant will
	 * quote it. So a break entirely outside the accepted range is dropped, and one
	 * that straddles the floor starts at the floor instead.
	 *
	 * Applied to the resolved rows rather than to the merchant's saved
	 * configuration: their rules are left exactly as they wrote them, and only what
	 * gets published is corrected. Switch Min/Max off and the original rows return.
	 *
	 * @param array      $tiers   Resolved tier rows.
	 * @param WC_Product $product
	 * @return array
	 */
	private static function clamp_to_order_rules( array $tiers, $product ) {
		if ( empty( $tiers ) || ! class_exists( 'TWTAEO_WC_Extensions' ) ) {
			return $tiers;
		}

		$floor   = TWTAEO_WC_Extensions::min_quantity( $product );
		$ceiling = TWTAEO_WC_Extensions::max_quantity( $product );

		if ( ! $floor && ! $ceiling ) {
			return $tiers;
		}

		$out = array();
		foreach ( $tiers as $tier ) {
			$min = (int) $tier['min'];
			$max = (int) $tier['max']; // 0 means open-ended.

			// Wholly below the minimum order, or wholly above the maximum.
			if ( $floor && $max && $max < $floor ) {
				continue;
			}
			if ( $ceiling && $min > $ceiling ) {
				continue;
			}

			if ( $floor && $min < $floor ) {
				$tier['min'] = $floor;
			}
			if ( $ceiling && ( ! $max || $max > $ceiling ) ) {
				$tier['max'] = $ceiling;
			}

			$out[] = $tier;
		}

		return $out;
	}

	/**
	 * Declared tiers as labels only, for a variable product's visible table where
	 * there is no single "price each" to print.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array List of { min, max, label }.
	 */
	public static function declared_labels( $product_id ) {
		$out = array();
		foreach ( self::tiers_for_product( (int) $product_id ) as $tier ) {
			if ( 'price' === $tier['type'] ) {
				continue; // Incoherent across differently-priced options.
			}
			$out[] = array(
				'min'   => (int) $tier['min'],
				'max'   => (int) $tier['max'],
				'label' => self::tier_label( $tier ),
			);
		}
		return $out;
	}

	// ── Enforcement ───────────────────────────────────────────────────────────

	/**
	 * Whether anything on this store could actually apply a declared tier.
	 *
	 * WooCommerce core cannot: it has no quantity-break or role-price concept. So
	 * the answer is yes only when a dynamic pricing plugin is active, or when the
	 * merchant has explicitly confirmed they have wired it up some other way (a
	 * custom snippet, a B2B plugin we do not recognise).
	 *
	 * This is deliberately not a silent default. Publishing "£85 when you buy 10"
	 * on a store where buying 10 costs £100 each is a false statement about a price,
	 * and it is the one way this module can do real harm.
	 *
	 * @return array{ok:bool,owners:array,confirmed:bool}
	 */
	public static function enforcement() {
		$owners    = class_exists( 'TWTAEO_Coupon_Rules' ) ? TWTAEO_Coupon_Rules::dynamic_pricing_owners() : array();
		$settings  = self::get();
		$confirmed = ! empty( $settings['enforcement_confirmed'] );
		$readable  = class_exists( 'TWTAEO_Dynamic_Pricing' ) && TWTAEO_Dynamic_Pricing::is_available();

		// Three strengths, and the difference matters.
		//
		//   'read'      — a pricing engine answers the documented price filter, so a
		//                 published tier is the engine's own answer. Enforced by
		//                 construction; nothing here is being taken on trust.
		//   'detected'  — a pricing plugin is present but not answering, so we know
		//                 something applies tiers without knowing what it charges.
		//                 Declared tiers are taken at the merchant's word.
		//   'confirmed' — the merchant states they wired it up themselves.
		$level = 'none';
		if ( $readable ) {
			$level = 'read';
		} elseif ( ! empty( $owners ) ) {
			$level = 'detected';
		} elseif ( $confirmed ) {
			$level = 'confirmed';
		}

		return array(
			'ok'        => ( 'none' !== $level ),
			'level'     => $level,
			'readable'  => $readable,
			'owners'    => $owners,
			'confirmed' => $confirmed,
		);
	}

	/** Convenience wrapper. */
	public static function has_enforcement() {
		$e = self::enforcement();
		return $e['ok'];
	}

	// ── Member program schema ─────────────────────────────────────────────────

	/** Stable @id for the store's membership programme. */
	public static function member_program_id() {
		return home_url( '/#member-program' );
	}

	/** Stable @id for one tier. */
	public static function member_tier_id( $role ) {
		return home_url( '/#member-tier-' . sanitize_key( $role ) );
	}

	/**
	 * The MemberProgram node, or null when there is nothing to publish.
	 *
	 * Google reads membership pricing as a pair: a `hasMemberProgram` on the
	 * Organization declaring the tiers, and a `validForMemberTier` on each offer's
	 * UnitPriceSpecification pointing back at one. Emitting the offer half without
	 * this half leaves a dangling reference, so the schema writer checks for this
	 * node before it writes one.
	 *
	 * @return array|null
	 */
	public static function member_program_node() {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$settings = self::get();
		if ( empty( $settings['output_member_tiers'] ) || empty( $settings['member_tiers'] ) ) {
			return null;
		}

		$tiers = array();
		foreach ( $settings['member_tiers'] as $tier ) {
			$tiers[] = array(
				'@type'          => 'MemberProgramTier',
				'@id'            => self::member_tier_id( $tier['role'] ),
				'name'           => $tier['name'],
				'hasTierBenefit' => self::BENEFIT_LOYALTY_PRICE,
			);
		}

		$name = trim( (string) $settings['member_program_name'] );
		if ( '' === $name ) {
			$name = __( 'Member pricing', 'twt-aeo-ultimate' );
		}

		return array(
			'@type'    => 'MemberProgram',
			'@id'      => self::member_program_id(),
			'name'     => $name,
			'hasTiers' => $tiers,
		);
	}

	/**
	 * Member tiers ready for the offer writer, with their @ids resolved.
	 *
	 * @return array
	 */
	public static function member_tiers_for_offers() {
		if ( ! self::is_enabled() ) {
			return array();
		}

		$settings = self::get();
		if ( empty( $settings['output_member_tiers'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $settings['member_tiers'] as $tier ) {
			$tier['@id'] = self::member_tier_id( $tier['role'] );
			$out[]       = $tier;
		}

		return $out;
	}

	// ── Labels ────────────────────────────────────────────────────────────────

	/**
	 * A money amount as plain text.
	 *
	 * `wc_price()` returns HTML entities, and these labels are printed through
	 * `esc_html()`, which would escape the ampersand again and show the visitor a
	 * literal `&#36;`. Same rule as TWTAEO_Coupon_Rules::money().
	 *
	 * @param float $amount
	 * @return string
	 */
	public static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * "10% off" / "£5 off" / "£85 each" for one tier row.
	 *
	 * @param array $tier
	 * @return string
	 */
	public static function tier_label( array $tier ) {
		switch ( $tier['type'] ) {
			case 'percent':
				/* translators: %s: percentage, e.g. "10". */
				return sprintf( __( '%s%% off', 'twt-aeo-ultimate' ), wc_format_localized_decimal( $tier['value'] ) );
			case 'fixed':
				/* translators: %s: money amount. */
				return sprintf( __( '%s off each', 'twt-aeo-ultimate' ), self::money( $tier['value'] ) );
			case 'price':
				/* translators: %s: money amount. */
				return sprintf( __( '%s each', 'twt-aeo-ultimate' ), self::money( $tier['value'] ) );
		}
		return '';
	}

	/**
	 * "Buy 5–9" / "Buy 10 or more" for one tier row.
	 *
	 * @param array $tier
	 * @return string
	 */
	public static function quantity_label( array $tier ) {
		if ( ! empty( $tier['max'] ) ) {
			/* translators: 1: minimum quantity, 2: maximum quantity. */
			return sprintf( __( 'Buy %1$d to %2$d', 'twt-aeo-ultimate' ), $tier['min'], $tier['max'] );
		}
		/* translators: %d: minimum quantity. */
		return sprintf( __( 'Buy %d or more', 'twt-aeo-ultimate' ), $tier['min'] );
	}
}
