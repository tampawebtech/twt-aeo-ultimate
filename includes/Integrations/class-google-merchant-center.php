<?php
/**
 * Google Merchant Center Integration
 *
 * Handles a dedicated OAuth 2.0 flow for the Merchant Center scope. Credentials,
 * tokens, and merchant config are stored separately from the GA4/GSC OAuth
 * session so they can be connected independently.
 *
 * Speaks the **Merchant API**. The Content API for Shopping it used to call was
 * shut down by Google on 18 August 2026 and every request against it now fails,
 * so the reader is translated here and the rest of the plugin keeps the flat
 * product shape it was written against.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_Merchant_Center {

	const OPTION_CREDS   = 'twtaeo_gmc_creds';
	const OPTION_TOKENS  = 'twtaeo_gmc_tokens';
	const OPTION_CONFIG  = 'twtaeo_gmc_config';
	const OPTION_STATE   = 'twtaeo_gmc_oauth_state';

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Merchant API, which replaced the Content API for Shopping.
	 *
	 * Content API v2.1 (`shoppingcontent.googleapis.com/content/v2.1`) was **sunset
	 * on 18 August 2026** and every call against it stops working. Merchant API is
	 * split into sub-APIs, so there is no single base path any more -- see endpoint().
	 */
	const API_HOST = 'https://merchantapi.googleapis.com';
	const API_VER  = 'v1';

	/**
	 * Unchanged from the Content API -- merchants who already connected do NOT have
	 * to re-authorise. A scope change would have meant every connected store
	 * silently losing access on upgrade.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/content';

	/**
	 * Micros: 1,000,000 micros == one unit of the currency, per the Price schema.
	 * Prices arrive as an int64-in-a-string and must be divided before comparison --
	 * comparing 15000000 against a WooCommerce 15.00 would report every product in
	 * the catalogue as a price mismatch.
	 */
	const MICROS = 1000000;

	/**
	 * Per-request memo of the full product listing, keyed by merchant id.
	 *
	 * Merchant API serves product status inline on the product, so the products and
	 * the statuses are one and the same listing. The sync engine asks for both back
	 * to back; without this it would page the entire catalogue twice per sync for
	 * one set of rows.
	 *
	 * @var array<string,array>
	 */
	private static $product_memo = array();

	/**
	 * Build a Merchant API URL.
	 *
	 * @param string $sub_api 'products', 'promotions', 'accounts', 'datasources'.
	 * @param string $path    Path after the version, e.g. "accounts/123/products".
	 * @return string
	 */
	private static function endpoint( $sub_api, $path ) {
		return self::API_HOST . '/' . $sub_api . '/' . self::API_VER . '/' . $path;
	}

	/**
	 * Merchant API addresses the account as a resource name, not a bare number.
	 *
	 * @param string $merchant_id
	 * @return string e.g. "accounts/1234567"
	 */
	private static function account_name( $merchant_id ) {
		$id = trim( (string) $merchant_id );
		// Tolerate a merchant who pasted the full resource name.
		if ( 0 === strpos( $id, 'accounts/' ) ) {
			return $id;
		}
		return 'accounts/' . rawurlencode( $id );
	}

	// ── Credentials & Config ─────────────────────────────────────────────────

	public static function get_credentials() {
		return get_option( self::OPTION_CREDS, array() );
	}

	public static function save_credentials( $client_id, $client_secret ) {
		update_option( self::OPTION_CREDS, array(
			'client_id'     => sanitize_text_field( $client_id ),
			'client_secret' => sanitize_text_field( $client_secret ),
		) );
	}

	public static function get_config() {
		return get_option( self::OPTION_CONFIG, array() );
	}

	public static function save_config( $merchant_id ) {
		update_option( self::OPTION_CONFIG, array(
			'merchant_id' => sanitize_text_field( $merchant_id ),
		) );
	}

	public static function get_merchant_id() {
		$config = self::get_config();
		return $config['merchant_id'] ?? '';
	}

	// ── Connection State ─────────────────────────────────────────────────────

	public static function is_connected() {
		$tokens = self::get_tokens();
		return ! empty( $tokens['refresh_token'] ) && ! empty( self::get_merchant_id() );
	}

	public static function disconnect() {
		delete_option( self::OPTION_TOKENS );
		delete_option( self::OPTION_STATE );
		delete_transient( 'twtaeo_gmc_products' );
		delete_transient( 'twtaeo_gmc_statuses' );
	}

	// ── OAuth Flow ───────────────────────────────────────────────────────────

	/**
	 * Redirect URI — must be registered in Google Cloud Console.
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=twt-aeo-woocommerce&tab=gmc' );
	}

	/**
	 * Build the Google OAuth URL for the Merchant Center content scope.
	 */
	public static function get_oauth_url() {
		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return '';
		}

		$state = wp_create_nonce( 'twtaeo_gmc_oauth' );
		update_option( self::OPTION_STATE, $state );

		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		) );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code
	 * @param string $state
	 * @return true|WP_Error
	 */
	public static function handle_callback( $code, $state ) {
		$stored = get_option( self::OPTION_STATE, '' );
		if ( empty( $stored ) || ! hash_equals( $stored, $state ) ) {
			return new WP_Error( 'invalid_state', 'Invalid OAuth state. Please try connecting again.' );
		}
		delete_option( self::OPTION_STATE );

		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			return new WP_Error( 'no_credentials', 'Google API credentials are not configured.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'code'          => $code,
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'redirect_uri'  => self::get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( $body['error'], $body['error_description'] ?? 'Token exchange failed.' );
		}

		update_option( self::OPTION_TOKENS, array(
			'access_token'  => $body['access_token'] ?? '',
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
		) );

		return true;
	}

	// ── Token Management ─────────────────────────────────────────────────────

	private static function get_tokens() {
		return get_option( self::OPTION_TOKENS, array() );
	}

	public static function get_access_token() {
		$tokens = self::get_tokens();

		if ( empty( $tokens['access_token'] ) ) {
			return '';
		}

		if ( ! empty( $tokens['expires_at'] ) && time() > ( $tokens['expires_at'] - 60 ) ) {
			if ( is_wp_error( self::refresh_access_token() ) ) {
				return '';
			}
			$tokens = self::get_tokens();
		}

		return $tokens['access_token'] ?? '';
	}

	private static function refresh_access_token() {
		$tokens = self::get_tokens();
		$creds  = self::get_credentials();

		if ( empty( $tokens['refresh_token'] ) || empty( $creds['client_id'] ) ) {
			return new WP_Error( 'no_refresh_token', 'No refresh token available.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'grant_type'    => 'refresh_token',
			),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			self::disconnect();
			return new WP_Error( $body['error'], $body['error_description'] ?? 'Token refresh failed.' );
		}

		$tokens['access_token'] = $body['access_token'] ?? '';
		$tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		update_option( self::OPTION_TOKENS, $tokens );

		return true;
	}

	// -- Merchant API: products ------------------------------------------------

	/**
	 * Fetch a single page (<=250 items) of a listing. Lets the sync queue spread a
	 * large feed across many short requests instead of looping every page inside
	 * one PHP request.
	 *
	 * @param string $merchant_id
	 * @param string $endpoint   'products' or 'productstatuses'.
	 * @param string $page_token Pagination token from the previous page.
	 * @return array|WP_Error  { items: array, next: string }
	 */
	public static function fetch_page( $merchant_id, $endpoint, $page_token = '' ) {
		// Route by service BEFORE checking the token. An unmapped service is a
		// programming error, not an auth state, and reporting "not connected" for
		// one hides it at exactly the moment somebody is trying to debug it.
		//
		// Merchant API returns each product's status inline on the product, so
		// 'products' and 'productstatuses' share one endpoint. Anything else must
		// fail loudly: quietly serving products in answer to a request for
		// something else is worse than an error, because the caller parses them as
		// the thing it asked for and reports confident nonsense.
		$account = self::account_name( $merchant_id );

		switch ( $endpoint ) {
			case 'products':
			case 'productstatuses':
				$url  = self::endpoint( 'products', $account . '/products' );
				$list = 'products';
				break;

			default:
				return new WP_Error(
					'gmc_unknown_endpoint',
					sprintf(
						/* translators: %s: the requested API service name. */
						__( 'No Merchant API equivalent is wired up for "%s". Content API for Shopping was retired, so this data is not being read rather than being read wrongly.', 'twt-aeo-ultimate' ),
						$endpoint
					)
				);
		}

		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Merchant Center.' );
		}

		$params = array( 'pageSize' => 250 );
		if ( $page_token ) {
			$params['pageToken'] = $page_token;
		}

		// http_build_query(), not add_query_arg(): add_query_arg() does not encode
		// the values it adds, and a page token is opaque base64 that can contain
		// characters which would terminate the query string.
		$response = wp_remote_get( $url . '?' . http_build_query( $params ), array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			return new WP_Error( 'gmc_error', self::explain_error( $code, $data ) );
		}

		// One listing, two shapes. Callers that asked for statuses are written
		// against Content API's separate productstatuses service and key rows by
		// `productId`; handing them products would leave that key empty and every
		// rejection code would vanish while the feed looked clean.
		$want_status = ( 'productstatuses' === $endpoint );

		$items = array();
		foreach ( (array) ( $data[ $list ] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$product = self::normalise_product( $row );
			$items[] = $want_status ? self::status_of( $product ) : $product;
		}

		return array(
			'items' => $items,
			'next'  => $data['nextPageToken'] ?? '',
		);
	}

	/**
	 * The status half of a normalised product, shaped like a Content API
	 * ProductStatus resource.
	 *
	 * Merchant API has no productstatuses service -- status rides on the product --
	 * so the identifiers are carried across, letting callers join statuses back to
	 * products exactly as they did before.
	 *
	 * @param array $product A product from normalise_product().
	 * @return array
	 */
	private static function status_of( array $product ) {
		$status = is_array( $product['productStatus'] ?? null ) ? $product['productStatus'] : array();
		// `productId` is gone in Merchant API; the resource name is the stable id,
		// and it is what normalise_product() puts in `id`.
		$status['productId'] = $product['id'] ?? '';
		$status['offerId']   = $product['offerId'] ?? '';
		return $status;
	}

	/**
	 * Flatten a Merchant API product into the shape the rest of the plugin reads.
	 *
	 * Everything downstream (TWTAEO_GMC_Sync_Engine, the parity screens) was
	 * written against Content API's flat product, so the translation happens here
	 * once rather than at ten call sites. Every renamed field is listed explicitly
	 * because each one is a silent failure if missed -- `gtin` became the array
	 * `gtins`, and reading the old key would have reported the whole catalogue as
	 * missing its GTINs.
	 *
	 * @param array $p Merchant API Product resource.
	 * @return array Content-API-shaped product.
	 */
	private static function normalise_product( array $p ) {
		$attrs = isset( $p['productAttributes'] ) && is_array( $p['productAttributes'] )
			? $p['productAttributes']
			: array();

		$gtins  = isset( $attrs['gtins'] ) && is_array( $attrs['gtins'] ) ? $attrs['gtins'] : array();
		$status = self::normalise_status( $p['productStatus'] ?? array() );

		return array(
			// `id` became the resource `name`; `offerId` survived unchanged.
			'id'           => $p['name'] ?? '',
			'offerId'      => $p['offerId'] ?? '',
			'title'        => $attrs['title'] ?? '',
			'link'         => $attrs['link'] ?? '',
			'brand'        => $attrs['brand'] ?? '',
			// A product may carry several GTINs now. The first is the primary one;
			// the rest are kept so a caller can tell "none" from "more than one".
			'gtin'         => $gtins[0] ?? '',
			'gtins'        => $gtins,
			'mpn'          => $attrs['mpn'] ?? '',
			'condition'    => $attrs['condition'] ?? '',
			'availability' => $attrs['availability'] ?? '',
			// The sync engine compares the feed's category against the on-page
			// AI-resolved one; it moved under productAttributes with the rest.
			'googleProductCategory' => $attrs['googleProductCategory'] ?? '',
			'price'        => self::normalise_price( $attrs['price'] ?? array() ),
			'salePrice'    => self::normalise_price( $attrs['salePrice'] ?? array() ),
			// Feed freshness, used for the "how long did our edit take to reach
			// Google" latency figure. Merchant API reports it on the status rather
			// than the product, so it is lifted here to where callers look for it.
			'updateTime'   => $status['lastUpdateDate'] ?? ( $p['updateTime'] ?? '' ),
			// Status now travels with the product instead of a parallel endpoint.
			'productStatus' => $status,
		);
	}

	/**
	 * Reshape a Merchant API ProductStatus for callers written against Content API.
	 *
	 * The trap here is `servability`. Content API marked a disapproving issue with
	 * `servability: "disapproved"`; Merchant API dropped that field entirely and
	 * expresses the same thing as `severity: "DISAPPROVED"` -- different key,
	 * different case. Code filtering on the old key matches nothing, so every
	 * rejection silently disappears and the feed looks clean while products are
	 * being refused. Both keys are published here so neither reading breaks.
	 *
	 * @param mixed $status
	 * @return array
	 */
	private static function normalise_status( $status ) {
		if ( ! is_array( $status ) ) {
			return array();
		}

		$issues = array();
		foreach ( (array) ( $status['itemLevelIssues'] ?? array() ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$severity = strtoupper( (string) ( $issue['severity'] ?? '' ) );
			// DEMOTED and NOT_IMPACTED are not refusals and must not be reported as
			// rejections; only DISAPPROVED stops the product being served.
			$issue['servability'] = ( 'DISAPPROVED' === $severity ) ? 'disapproved' : 'unaffected';
			$issues[]             = $issue;
		}
		$status['itemLevelIssues'] = $issues;

		return $status;
	}

	/**
	 * Merchant API Price -> Content API Price.
	 *
	 * {amountMicros: "15000000", currencyCode: "USD"} -> {value: "15.00", currency: "USD"}
	 *
	 * amountMicros is an int64 delivered as a string precisely because it can
	 * exceed PHP's float precision, so the division is done on the digits and
	 * formatted, never by casting the whole thing to float first.
	 *
	 * @param mixed $price
	 * @return array
	 */
	private static function normalise_price( $price ) {
		if ( ! is_array( $price ) || ! isset( $price['amountMicros'] ) ) {
			return array(
				'value'    => '',
				'currency' => is_array( $price ) ? ( $price['currencyCode'] ?? '' ) : '',
			);
		}

		$micros = (string) $price['amountMicros'];
		$neg    = ( '' !== $micros && '-' === $micros[0] );
		$digits = ltrim( $neg ? substr( $micros, 1 ) : $micros, '+' );
		if ( '' === $digits || ! ctype_digit( $digits ) ) {
			return array( 'value' => '', 'currency' => $price['currencyCode'] ?? '' );
		}

		$digits = str_pad( $digits, 7, '0', STR_PAD_LEFT );
		$whole  = substr( $digits, 0, -6 );
		$frac   = substr( $digits, -6 );
		// Trailing zeros beyond two decimals carry no money, but a currency with
		// three decimal places (KWD, BHD) must not be truncated to two.
		$frac = rtrim( $frac, '0' );
		if ( strlen( $frac ) < 2 ) {
			$frac = str_pad( $frac, 2, '0' );
		}

		return array(
			'value'    => ( $neg ? '-' : '' ) . $whole . '.' . $frac,
			'currency' => $price['currencyCode'] ?? '',
		);
	}

	/**
	 * Turn a Merchant API error into something a merchant can act on.
	 *
	 * The one worth naming is developer registration: Merchant API requires the
	 * calling Google Cloud project to be registered against the Merchant Center
	 * account, one project to one account. Content API had no such step, so a
	 * merchant whose setup worked yesterday gets a 403 that explains nothing.
	 *
	 * @param int   $code
	 * @param mixed $data Decoded error body.
	 * @return string
	 */
	private static function explain_error( $code, $data ) {
		$data    = is_array( $data ) ? $data : array();
		$message = $data['error']['message'] ?? '';
		$reason  = '';
		foreach ( (array) ( $data['error']['details'] ?? array() ) as $detail ) {
			if ( ! empty( $detail['reason'] ) ) {
				$reason = (string) $detail['reason'];
				break;
			}
		}

		if ( 403 === (int) $code && ( false !== stripos( $message, 'developer' ) || 'DEVELOPER_REGISTRATION_REQUIRED' === $reason ) ) {
			return __( 'Google needs this Google Cloud project registered against your Merchant Center account before it will answer. In Merchant Center, open Settings and register the Cloud project you created the OAuth credentials in. A Cloud project can be registered to only one Merchant Center account.', 'twt-aeo-ultimate' );
		}

		if ( '' !== $message ) {
			return $message;
		}
		return 'Merchant Center API error (HTTP ' . (int) $code . ').';
	}

	/**
	 * Fetch all products from Merchant Center (handles pagination).
	 *
	 * Memoized for the life of the request because list_all_product_statuses()
	 * reads the same listing; see $product_memo.
	 *
	 * @param string $merchant_id
	 * @return array|WP_Error  Flat array of product resource objects.
	 */
	public static function list_all_products( $merchant_id ) {
		$key = (string) $merchant_id;
		if ( isset( self::$product_memo[ $key ] ) ) {
			return self::$product_memo[ $key ];
		}

		$products   = array();
		$page_token = '';

		do {
			$page = self::fetch_page( $merchant_id, 'products', $page_token );
			if ( is_wp_error( $page ) ) {
				return $page; // Never memoized: a failure must not be served as a catalogue.
			}
			$products   = array_merge( $products, $page['items'] );
			$page_token = $page['next'];
		} while ( $page_token );

		self::$product_memo[ $key ] = $products;
		return $products;
	}

	/** Forget the memoized catalogue (long-running jobs that want a fresh read). */
	public static function flush_product_cache() {
		self::$product_memo = array();
	}

	/**
	 * Fetch all product statuses (rejection codes, approval status).
	 *
	 * Merchant API has no productstatuses service -- status rides on the product --
	 * so this reads the same listing and reshapes it. `productId` is gone, so rows
	 * are keyed by the resource name, which is what normalise_product() puts in
	 * `id` and what the sync engine already matches products on.
	 *
	 * @param string $merchant_id
	 * @return array|WP_Error  Keyed by GMC product resource name.
	 */
	public static function list_all_product_statuses( $merchant_id ) {
		$products = self::list_all_products( $merchant_id );
		if ( is_wp_error( $products ) ) {
			return $products;
		}

		$statuses = array();
		foreach ( $products as $item ) {
			$key = $item['id'] ?? '';
			if ( '' === $key ) {
				continue;
			}
			$statuses[ $key ] = self::status_of( $item );
		}

		return $statuses;
	}

	// -- Merchant API: promotions ----------------------------------------------

	/**
	 * The merchant's promotions data source, which Merchant API requires.
	 *
	 * **Found, never created.** Creating one would be a second write to the
	 * merchant's Google account that nobody asked for -- the promotion push is
	 * deliberately the only write this plugin makes, and it happens on one nonced
	 * form submission. If no promotions data source exists, say so and let the
	 * merchant make it.
	 *
	 * @param string $merchant_id
	 * @param array  $promotion Used to match country/language where several exist.
	 * @return string|WP_Error Resource name, e.g. accounts/123/dataSources/456.
	 */
	private static function find_promotion_data_source( $merchant_id, array $promotion ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Merchant Center.' );
		}

		$url = self::endpoint( 'datasources', self::account_name( $merchant_id ) . '/dataSources' );

		$response = wp_remote_get( $url . '?' . http_build_query( array( 'pageSize' => 100 ) ), array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 30,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'gmc_datasource_error', self::explain_error( $code, $data ) );
		}

		$want_country  = $promotion['targetCountry'] ?? '';
		$want_language = $promotion['contentLanguage'] ?? '';
		$fallback      = '';

		foreach ( (array) ( $data['dataSources'] ?? array() ) as $source ) {
			if ( empty( $source['promotionDataSource'] ) || empty( $source['name'] ) ) {
				continue;
			}
			$ds = $source['promotionDataSource'];
			// An exact country+language match is the right feed; anything else would
			// file the promotion against the wrong market.
			if ( $want_country && $want_language
				&& ( $ds['targetCountry'] ?? '' ) === $want_country
				&& ( $ds['contentLanguage'] ?? '' ) === $want_language ) {
				return (string) $source['name'];
			}
			if ( '' === $fallback ) {
				$fallback = (string) $source['name'];
			}
		}

		if ( '' !== $fallback && ( '' === $want_country || '' === $want_language ) ) {
			return $fallback;
		}

		return new WP_Error(
			'gmc_no_promotion_data_source',
			$fallback
				? sprintf(
					/* translators: 1: target country, 2: content language. */
					__( 'Your Merchant Center account has a promotions data source, but none for %1$s / %2$s. Add one for that country and language in Merchant Center, then push again.', 'twt-aeo-ultimate' ),
					$want_country,
					$want_language
				)
				: __( 'Google now requires a promotions data source before a promotion can be sent, and this account has none. Create one in Merchant Center under Data sources, then push again. This plugin will not create it for you, because that would mean changing your Google account without being asked.', 'twt-aeo-ultimate' )
		);
	}

	/**
	 * Create or replace one promotion.
	 *
	 * This is the **only write** this plugin makes to a merchant's Google account,
	 * and it never happens on its own: TWTAEO_Promotions_Feed calls it once per
	 * coupon the merchant has explicitly selected and submitted. There is no cron
	 * path and no save hook that reaches here.
	 *
	 * `promotions:insert` is an upsert keyed on promotionId, so re-pushing an
	 * edited coupon updates the existing promotion rather than duplicating it.
	 *
	 * @param string $merchant_id
	 * @param array  $promotion A Promotion resource, built by TWTAEO_Promotions_Feed.
	 * @return array|WP_Error The created resource on success.
	 */
	public static function create_promotion( $merchant_id, array $promotion ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Merchant Center.' );
		}

		// Merchant API requires a promotions data source and wraps the promotion in
		// an insert request. Content API had neither.
		$data_source = self::find_promotion_data_source( $merchant_id, $promotion );
		if ( is_wp_error( $data_source ) ) {
			return $data_source;
		}

		$url = self::endpoint( 'promotions', self::account_name( $merchant_id ) . '/promotions:insert' );

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'promotion'  => $promotion,
				'dataSource' => $data_source,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'gmc_promotion_error',
				$data['error']['message'] ?? 'Merchant Center rejected the promotion (HTTP ' . (int) $code . ').'
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
