<?php
/**
 * Merchant Center promotions — where a coupon code can actually go.
 *
 * A promo code has no on-page structured-data representation. `discountCode` is an
 * `Order` property, not an `Offer` one, and the schema.org proposal for on-page
 * promo-code vocabulary (schemaorg#1742) is still open. What Google *did* build for
 * "use SUMMER10 for 10% off" is the Merchant Center promotions feed, and the plugin
 * already holds an authorised Merchant API connection. So that is where codes go.
 *
 * ── Nothing here happens by itself ────────────────────────────────────────────
 * Pushing a promotion writes to the merchant's Google account and makes a public
 * claim about their pricing. Every push is one nonced, capability-checked form
 * submission naming the coupons the merchant ticked. There is no cron job, no save
 * hook and no "sync everything" button, and none should be added: a coupon edited
 * by mistake would otherwise become a live public promotion before anyone noticed.
 *
 * ── Bing ──────────────────────────────────────────────────────────────────────
 * Microsoft's Shopping Content API has **no promotions resource**. Its Product
 * object carries a `promotionId` that references a promotion defined in a separate
 * feed uploaded to Microsoft Merchant Center — there is no endpoint to create that
 * promotion. So for Bing this class generates the feed file and the merchant
 * uploads it. Pretending to push it over the API would fail silently at best.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Promotions_Feed {

	const OPTION_SETTINGS = 'twtaeo_promotions_settings';
	const OPTION_PUSHED   = 'twtaeo_promotions_pushed';
	const NONCE_PUSH      = 'twtaeo_promotions_push';
	const NONCE_SETTINGS  = 'twtaeo_promotions_settings_save';

	/** Countries Merchant Center accepts promotions for. */
	const TARGET_COUNTRIES = array( 'AU', 'BR', 'CA', 'DE', 'ES', 'FR', 'GB', 'IN', 'IT', 'JP', 'KR', 'NL', 'US' );

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function defaults() {
		return array(
			// Google requires an end date on every promotion. A coupon with no expiry
			// therefore has no end date to send. Rather than invent one, the merchant
			// declares how long an open-ended coupon should run as a promotion.
			// 0 means "do not push open-ended coupons at all", which is the default,
			// because a promotion that outlives the merchant's intention is worse than
			// one that was never created.
			'default_duration_days' => 0,

			// ONLINE / IN_STORE. In-store is only offered when the Local Store module
			// is publishing a real shop, since claiming in-store redemption without a
			// store is a false statement about the business.
			'redeem_online'   => 1,
			'redeem_in_store' => 0,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param array $raw Raw $_POST slice, already unslashed.
	 * @return array
	 */
	public static function save_settings( array $raw ) {
		$out = self::defaults();

		$days                          = isset( $raw['default_duration_days'] ) ? (int) $raw['default_duration_days'] : 0;
		$out['default_duration_days']  = max( 0, min( 365, $days ) );
		$out['redeem_online']          = empty( $raw['redeem_online'] ) ? 0 : 1;
		$out['redeem_in_store']        = ( ! empty( $raw['redeem_in_store'] ) && self::has_physical_store() ) ? 1 : 0;

		// A promotion with no redemption channel is invalid, and silently defaulting
		// would put a channel on it the merchant did not choose.
		if ( ! $out['redeem_online'] && ! $out['redeem_in_store'] ) {
			$out['redeem_online'] = 1;
		}

		update_option( self::OPTION_SETTINGS, $out );

		return $out;
	}

	/** Whether the Local Store module is publishing a real, addressable shop. */
	public static function has_physical_store() {
		return class_exists( 'TWTAEO_Store_Location' )
			&& TWTAEO_Store_Location::is_enabled()
			&& class_exists( 'TWTAEO_Local_Schema_Writer' )
			&& (bool) TWTAEO_Local_Schema_Writer::store_node();
	}

	// ── Locale ────────────────────────────────────────────────────────────────

	/**
	 * The store's target country, or '' when Merchant Center does not accept
	 * promotions for it.
	 *
	 * Read from WooCommerce's own base country rather than a setting of our own —
	 * the merchant already told WooCommerce where they sell from, and a second
	 * answer that could disagree with the first is a bug waiting to happen.
	 *
	 * @return string
	 */
	public static function target_country() {
		$base = '';
		if ( function_exists( 'wc_get_base_location' ) ) {
			$location = wc_get_base_location();
			$base     = strtoupper( (string) ( $location['country'] ?? '' ) );
		}
		return in_array( $base, self::TARGET_COUNTRIES, true ) ? $base : '';
	}

	/**
	 * Content language, as the two-letter code Merchant Center expects.
	 *
	 * @return string
	 */
	public static function content_language() {
		$locale = get_locale();
		$lang   = strtolower( substr( (string) $locale, 0, 2 ) );
		return $lang ?: 'en';
	}

	// ── Eligibility ───────────────────────────────────────────────────────────

	/**
	 * Whether one normalised coupon can become a Merchant Center promotion.
	 *
	 * Returns the reason on failure. Every rejection is surfaced in the admin table:
	 * a merchant must never have to guess why one of their coupons was not sent,
	 * which is the same standard the sitemap generator holds for excluded products.
	 *
	 * @param array $rule Normalised rule from TWTAEO_Coupon_Rules.
	 * @return array{ok:bool,reason:string}
	 */
	public static function eligibility( array $rule ) {
		$no = function ( $reason ) {
			return array( 'ok' => false, 'reason' => $reason );
		};

		if ( empty( $rule['promotable'] ) ) {
			return $no( $rule['skip_reason'] );
		}

		if ( '' === trim( $rule['code'] ) ) {
			return $no( __( 'Has no code for a shopper to enter.', 'twt-aeo-ultimate' ) );
		}

		if ( '' === self::target_country() ) {
			return $no( __( 'Merchant Center does not accept promotions for your store\'s base country.', 'twt-aeo-ultimate' ) );
		}

		// A percentage Google can represent. percentOff is an integer, so 12.5% has
		// no faithful representation — rounding it would publish a discount the
		// checkout does not give.
		if ( 'percent' === $rule['discount_type'] ) {
			if ( abs( $rule['amount'] - round( $rule['amount'] ) ) > 0.0001 ) {
				return $no( __( 'Merchant Center takes whole-number percentages only, and rounding this one would advertise a discount your checkout does not give.', 'twt-aeo-ultimate' ) );
			}
		}

		if ( '' === self::resolve_end_date( $rule ) ) {
			return $no( __( 'Has no expiry date. Merchant Center requires an end date — either set one on the coupon, or set a default run length on this tab.', 'twt-aeo-ultimate' ) );
		}

		// Scoped coupons need real Merchant Center item IDs, which only exist for
		// products a completed feed sync has actually matched.
		if ( self::is_scoped( $rule ) ) {
			$items = self::resolve_item_ids( $rule );
			if ( empty( $items ) ) {
				return $no( __( 'Applies to specific products, but none of them have been matched to an item in your Merchant Center feed yet. Run a feed sync on the Products & Feeds page first.', 'twt-aeo-ultimate' ) );
			}
		}

		return array( 'ok' => true, 'reason' => '' );
	}

	/** Whether a coupon is limited to particular products or categories. */
	private static function is_scoped( array $rule ) {
		return ! empty( $rule['product_ids'] ) || ! empty( $rule['category_ids'] );
	}

	/**
	 * The promotion's end date, honouring the merchant's declared default.
	 *
	 * @param array $rule
	 * @return string Y-m-d, or '' when there is no defensible end date.
	 */
	public static function resolve_end_date( array $rule ) {
		if ( '' !== $rule['date_to'] ) {
			return $rule['date_to'];
		}

		$settings = self::get_settings();
		$days     = (int) $settings['default_duration_days'];

		if ( $days <= 0 ) {
			return '';
		}

		return gmdate( 'Y-m-d', strtotime( '+' . $days . ' days' ) );
	}

	/**
	 * Merchant Center item IDs for the products a scoped coupon covers.
	 *
	 * Taken from the sync snapshots the GMC engine already wrote, so these are IDs
	 * Google returned for real feed items — never IDs we guessed from a SKU. A
	 * product the sync has not matched is simply absent, and eligibility() turns a
	 * completely unmatched coupon into a stated reason rather than a silent no-op.
	 *
	 * @param array $rule
	 * @return string[]
	 */
	public static function resolve_item_ids( array $rule ) {
		if ( ! class_exists( 'TWTAEO_GMC_Sync_Engine' ) ) {
			return array();
		}

		$post_ids = array_map( 'intval', $rule['product_ids'] );

		if ( ! empty( $rule['category_ids'] ) ) {
			$in_categories = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- merchant-triggered, bounded.
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => array_map( 'intval', $rule['category_ids'] ),
					),
				),
			) );
			$post_ids = array_merge( $post_ids, array_map( 'intval', $in_categories ) );
		}

		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
		$excluded = array_map( 'intval', $rule['excluded_product_ids'] );

		$items = array();
		foreach ( $post_ids as $post_id ) {
			if ( in_array( $post_id, $excluded, true ) ) {
				continue;
			}
			$snap = get_post_meta( $post_id, TWTAEO_GMC_Sync_Engine::META_SYNC, true );
			if ( ! is_array( $snap ) || empty( $snap['matched'] ) ) {
				continue;
			}
			// offerId is the feed's own item id, which is what `itemId` expects.
			$offer_id = (string) ( $snap['sku_gmc'] ?? '' );
			if ( '' !== $offer_id ) {
				$items[] = $offer_id;
			}
		}

		return array_values( array_unique( $items ) );
	}

	// ── Payload ───────────────────────────────────────────────────────────────

	/**
	 * Build the Merchant Center Promotion resource for one coupon.
	 *
	 * @param array $rule
	 * @return array|WP_Error
	 */
	public static function build_payload( array $rule ) {
		$check = self::eligibility( $rule );
		if ( ! $check['ok'] ) {
			return new WP_Error( 'not_eligible', $check['reason'] );
		}

		$settings = self::get_settings();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		$start = $rule['date_from'] ?: gmdate( 'Y-m-d' );
		$end   = self::resolve_end_date( $rule );

		// A coupon created in the past is fine, but a promotion whose window has
		// already closed is not — clamp the start to today rather than send a
		// period Google will reject.
		if ( strtotime( $start ) > strtotime( $end ) ) {
			$start = gmdate( 'Y-m-d' );
		}

		$channels = array();
		if ( ! empty( $settings['redeem_online'] ) ) {
			$channels[] = 'ONLINE';
		}
		if ( ! empty( $settings['redeem_in_store'] ) && self::has_physical_store() ) {
			$channels[] = 'IN_STORE';
		}

		$payload = array(
			'promotionId'                 => self::promotion_id( $rule ),
			'longTitle'                   => self::long_title( $rule ),
			'offerType'                   => 'GENERIC_CODE',
			'genericRedemptionCode'       => strtoupper( $rule['code'] ),
			'productApplicability'        => self::is_scoped( $rule ) ? 'SPECIFIC_PRODUCTS' : 'ALL_PRODUCTS',
			'redemptionChannel'           => $channels,
			'targetCountry'               => self::target_country(),
			'contentLanguage'             => self::content_language(),
			'promotionEffectiveTimePeriod' => array(
				'startTime' => gmdate( 'Y-m-d\T00:00:00\Z', strtotime( $start ) ),
				'endTime'   => gmdate( 'Y-m-d\T23:59:59\Z', strtotime( $end ) ),
			),
		);

		if ( 'percent' === $rule['discount_type'] ) {
			$payload['couponValueType'] = 'PERCENT_OFF';
			$payload['percentOff']      = (int) round( $rule['amount'] );
		} elseif ( in_array( $rule['discount_type'], array( 'fixed_cart', 'fixed_product' ), true ) ) {
			$payload['couponValueType'] = 'MONEY_OFF';
			$payload['moneyOffAmount']  = array(
				'value'    => number_format( (float) $rule['amount'], 2, '.', '' ),
				'currency' => $currency,
			);
		} elseif ( $rule['free_shipping'] ) {
			$payload['couponValueType'] = 'FREE_SHIPPING_STANDARD';
		} else {
			return new WP_Error( 'not_eligible', __( 'Its discount type has no Merchant Center equivalent.', 'twt-aeo-ultimate' ) );
		}

		if ( 'SPECIFIC_PRODUCTS' === $payload['productApplicability'] ) {
			$payload['itemId'] = self::resolve_item_ids( $rule );
		}

		if ( $rule['minimum_amount'] > 0 ) {
			$payload['minimumPurchaseAmount'] = array(
				'value'    => number_format( (float) $rule['minimum_amount'], 2, '.', '' ),
				'currency' => $currency,
			);
		}

		return $payload;
	}

	/**
	 * A stable promotion ID.
	 *
	 * Derived from the coupon's post ID so re-pushing an edited coupon updates the
	 * same promotion instead of creating a second one. Merchant Center restricts the
	 * character set, so the code itself is not used.
	 *
	 * @param array $rule
	 * @return string
	 */
	public static function promotion_id( array $rule ) {
		return 'twtaeo_' . (int) $rule['id'];
	}

	/**
	 * The promotion's public title.
	 *
	 * Built from the same plain_terms() the on-page coupon note uses, so what a
	 * shopper reads in Google and what they read on the product page cannot drift.
	 *
	 * @param array $rule
	 * @return string
	 */
	public static function long_title( array $rule ) {
		$title = TWTAEO_Coupon_Rules::plain_terms( $rule );
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );

		// Merchant Center caps longTitle at 60 characters.
		if ( function_exists( 'mb_substr' ) && mb_strlen( $title ) > 60 ) {
			$title = rtrim( mb_substr( $title, 0, 59 ) ) . '.';
		} elseif ( strlen( $title ) > 60 ) {
			$title = rtrim( substr( $title, 0, 59 ) ) . '.';
		}

		return $title;
	}

	// ── Push ──────────────────────────────────────────────────────────────────

	/**
	 * Push the selected coupons to Google Merchant Center.
	 *
	 * Called only from the admin page's nonced, capability-checked POST handler.
	 *
	 * @param int[] $coupon_ids
	 * @return array{sent:int,failed:int,results:array}
	 */
	public static function push( array $coupon_ids ) {
		$results = array( 'sent' => 0, 'failed' => 0, 'results' => array() );

		if ( ! class_exists( 'TWTAEO_Google_Merchant_Center' ) || ! TWTAEO_Google_Merchant_Center::is_connected() ) {
			$results['results'][] = array(
				'code'    => '',
				'ok'      => false,
				'message' => __( 'Not connected to Google Merchant Center.', 'twt-aeo-ultimate' ),
			);
			$results['failed']++;
			return $results;
		}

		$merchant_id = TWTAEO_Google_Merchant_Center::get_merchant_id();
		$by_id       = array();
		foreach ( TWTAEO_Coupon_Rules::all_rules( true ) as $rule ) {
			$by_id[ (int) $rule['id'] ] = $rule;
		}

		$log = get_option( self::OPTION_PUSHED, array() );
		$log = is_array( $log ) ? $log : array();

		foreach ( $coupon_ids as $coupon_id ) {
			$coupon_id = (int) $coupon_id;
			if ( ! isset( $by_id[ $coupon_id ] ) ) {
				continue;
			}
			$rule = $by_id[ $coupon_id ];

			$payload = self::build_payload( $rule );
			if ( is_wp_error( $payload ) ) {
				$results['failed']++;
				$results['results'][] = array(
					'code'    => $rule['code'],
					'ok'      => false,
					'message' => $payload->get_error_message(),
				);
				continue;
			}

			$response = TWTAEO_Google_Merchant_Center::create_promotion( $merchant_id, $payload );

			if ( is_wp_error( $response ) ) {
				$results['failed']++;
				$results['results'][] = array(
					'code'    => $rule['code'],
					'ok'      => false,
					'message' => $response->get_error_message(),
				);
				continue;
			}

			$results['sent']++;
			$results['results'][] = array(
				'code'    => $rule['code'],
				'ok'      => true,
				'message' => __( 'Sent.', 'twt-aeo-ultimate' ),
			);

			$log[ $coupon_id ] = array(
				'promotion_id' => $payload['promotionId'],
				'pushed_at'    => time(),
				'title'        => $payload['longTitle'],
			);
		}

		update_option( self::OPTION_PUSHED, $log );

		return $results;
	}

	/** The push log, keyed by coupon post ID. */
	public static function push_log() {
		$log = get_option( self::OPTION_PUSHED, array() );
		return is_array( $log ) ? $log : array();
	}

	// ── Bing feed file ────────────────────────────────────────────────────────

	/**
	 * The Microsoft Merchant Center promotions feed, as TSV.
	 *
	 * Microsoft has no promotions endpoint, so this is generated for the merchant to
	 * upload by hand. Column names follow Microsoft's promotions feed specification;
	 * the values are the same ones the Google payload carries, so the two channels
	 * cannot end up advertising different terms.
	 *
	 * @return string TSV, or '' when there is nothing eligible to write.
	 */
	public static function bing_feed_tsv() {
		$columns = array(
			'promotion_id',
			'long_title',
			'promotion_effective_dates',
			'coupon_value_type',
			'offer_type',
			'promotion_code',
			'percent_off',
			'money_off_amount',
			'product_applicability',
			'redemption_channel',
		);

		$lines = array( implode( "\t", $columns ) );
		$rows  = 0;

		foreach ( TWTAEO_Coupon_Rules::all_rules() as $rule ) {
			$payload = self::build_payload( $rule );
			if ( is_wp_error( $payload ) ) {
				continue;
			}

			$dates = $payload['promotionEffectiveTimePeriod']['startTime']
				. '/' . $payload['promotionEffectiveTimePeriod']['endTime'];

			$money = isset( $payload['moneyOffAmount'] )
				? $payload['moneyOffAmount']['value'] . ' ' . $payload['moneyOffAmount']['currency']
				: '';

			$lines[] = implode( "\t", array_map( array( __CLASS__, 'tsv_cell' ), array(
				$payload['promotionId'],
				$payload['longTitle'],
				$dates,
				$payload['couponValueType'],
				$payload['offerType'],
				$payload['genericRedemptionCode'],
				isset( $payload['percentOff'] ) ? (string) $payload['percentOff'] : '',
				$money,
				$payload['productApplicability'],
				implode( ',', $payload['redemptionChannel'] ),
			) ) );
			$rows++;
		}

		return $rows ? implode( "\n", $lines ) . "\n" : '';
	}

	/**
	 * Make one value safe for a tab-separated cell.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function tsv_cell( $value ) {
		return str_replace( array( "\t", "\r", "\n" ), ' ', (string) $value );
	}
}
