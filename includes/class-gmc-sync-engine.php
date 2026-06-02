<?php
/**
 * GMC Sync Engine
 *
 * Fetches live WooCommerce product data and Google Merchant Center feed data,
 * diffs the two, stores per-product results in postmeta, and calculates an
 * E-Commerce Integrity Score. Also registers the AJAX endpoints used by the
 * Merchant Center admin tab.
 *
 * Postmeta stored per product:
 *   _twt_gmc_sync  — serialized comparison snapshot (see run_sync())
 *
 * Options:
 *   twtaeo_gmc_last_sync  — Unix timestamp of the most recent full sync
 *
 * @package TWTAEO_Connector
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_GMC_Sync_Engine {

	const META_SYNC    = '_twt_gmc_sync';
	const OPTION_LAST  = 'twtaeo_gmc_last_sync';
	const NONCE_SYNC   = 'twtaeo_gmc_sync_nonce';
	const NONCE_SAVE   = 'twtaeo_gmc_save_nonce';

	// ── Public entry points ──────────────────────────────────────────────────

	/**
	 * Run a full sync: fetch WC + GMC data, diff, and persist per product.
	 *
	 * @return array|WP_Error  Summary array on success.
	 */
	public static function run_sync() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not active.' );
		}

		$merchant_id = TWTAEO_Google_Merchant_Center::get_merchant_id();
		if ( ! $merchant_id ) {
			return new WP_Error( 'no_merchant_id', 'Merchant Center ID is not configured.' );
		}

		// Fetch GMC feed data.
		$gmc_products = TWTAEO_Google_Merchant_Center::list_all_products( $merchant_id );
		if ( is_wp_error( $gmc_products ) ) {
			error_log( '[TWT AEO] GMC sync — list_all_products failed: ' . $gmc_products->get_error_code() . ': ' . $gmc_products->get_error_message() );
			return $gmc_products;
		}

		$gmc_statuses = TWTAEO_Google_Merchant_Center::list_all_product_statuses( $merchant_id );
		if ( is_wp_error( $gmc_statuses ) ) {
			error_log( '[TWT AEO] GMC sync — list_all_product_statuses failed: ' . $gmc_statuses->get_error_code() . ': ' . $gmc_statuses->get_error_message() );
			return $gmc_statuses;
		}

		// Build GMC lookup maps.
		$gmc_by_sku  = array();
		$gmc_by_gtin = array();

		foreach ( $gmc_products as $item ) {
			$offer_id = $item['offerId'] ?? '';
			$gtin     = $item['gtin'] ?? '';

			if ( $offer_id ) {
				$gmc_by_sku[ strtolower( $offer_id ) ] = $item;
			}
			if ( $gtin ) {
				$gmc_by_gtin[ $gtin ] = $item;
			}
		}

		// Fetch WC products.
		$wc_posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$summary = array(
			'total'          => count( $wc_posts ),
			'matched'        => 0,
			'unmatched'      => 0,
			'price_mismatch' => 0,
			'avail_mismatch' => 0,
			'rejected'       => 0,
			'schema_issues'  => 0,
		);

		foreach ( $wc_posts as $post_id ) {
			$wc_product = wc_get_product( $post_id );
			if ( ! $wc_product ) {
				continue;
			}

			$snapshot = self::build_product_snapshot( $post_id, $wc_product, $gmc_by_sku, $gmc_by_gtin, $gmc_statuses );
			update_post_meta( $post_id, self::META_SYNC, $snapshot );

			if ( $snapshot['matched'] ) {
				$summary['matched']++;
			} else {
				$summary['unmatched']++;
			}
			if ( $snapshot['price_mismatch'] )  $summary['price_mismatch']++;
			if ( $snapshot['avail_mismatch'] )  $summary['avail_mismatch']++;
			if ( ! empty( $snapshot['rejection_codes'] ) ) $summary['rejected']++;
			if ( $snapshot['schema_discrepancy'] ) $summary['schema_issues']++;
		}

		update_option( self::OPTION_LAST, time() );
		delete_transient( 'twtaeo_gmc_integrity_score' );

		return $summary;
	}

	/**
	 * Calculate and cache the E-Commerce Integrity Score (0–100).
	 *
	 * @return array { score: int, label: string, counts: array }
	 */
	public static function get_integrity_score() {
		$cached = get_transient( 'twtaeo_gmc_integrity_score' );
		if ( $cached !== false ) {
			return $cached;
		}

		$wc_posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		if ( empty( $wc_posts ) ) {
			return array( 'score' => 100, 'label' => 'Excellent', 'counts' => array(), 'synced' => false );
		}

		$counts = array(
			'total'          => count( $wc_posts ),
			'synced'         => 0,
			'matched'        => 0,
			'unmatched'      => 0,
			'price_mismatch' => 0,
			'avail_mismatch' => 0,
			'rejected'       => 0,
			'schema_issues'  => 0,
		);

		foreach ( $wc_posts as $post_id ) {
			$snap = get_post_meta( $post_id, self::META_SYNC, true );
			if ( ! is_array( $snap ) ) {
				continue;
			}
			$counts['synced']++;
			if ( $snap['matched'] )                      $counts['matched']++;
			else                                         $counts['unmatched']++;
			if ( $snap['price_mismatch'] )               $counts['price_mismatch']++;
			if ( $snap['avail_mismatch'] )               $counts['avail_mismatch']++;
			if ( ! empty( $snap['rejection_codes'] ) )   $counts['rejected']++;
			if ( $snap['schema_discrepancy'] )           $counts['schema_issues']++;
		}

		$deductions = 0;
		$deductions += min( $counts['rejected']       * 5, 40 );
		$deductions += min( $counts['price_mismatch'] * 2, 20 );
		$deductions += min( $counts['avail_mismatch'] * 1, 10 );
		$deductions += min( $counts['unmatched']      * 1, 10 );
		$deductions += min( $counts['schema_issues']  * 2, 20 );

		$score = max( 0, 100 - $deductions );

		if ( $score >= 90 )      $label = 'Excellent';
		elseif ( $score >= 70 )  $label = 'Good';
		elseif ( $score >= 50 )  $label = 'Fair';
		else                     $label = 'Poor';

		$result = array(
			'score'  => $score,
			'label'  => $label,
			'counts' => $counts,
			'synced' => $counts['synced'] > 0,
		);

		set_transient( 'twtaeo_gmc_integrity_score', $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Get per-product sync snapshots for table display.
	 *
	 * @return array[]
	 */
	public static function get_product_snapshots() {
		$wc_posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$rows = array();
		foreach ( $wc_posts as $post_id ) {
			$snap = get_post_meta( $post_id, self::META_SYNC, true );
			$rows[] = array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'edit_url'=> get_edit_post_link( $post_id, 'raw' ),
				'snap'    => is_array( $snap ) ? $snap : null,
			);
		}

		return $rows;
	}

	/**
	 * Get all products that have rejection codes, for the Rejection Log section.
	 *
	 * @return array[]
	 */
	public static function get_rejection_log() {
		$wc_posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$log = array();
		foreach ( $wc_posts as $post_id ) {
			$snap = get_post_meta( $post_id, self::META_SYNC, true );
			if ( ! is_array( $snap ) || empty( $snap['rejection_codes'] ) ) {
				continue;
			}
			$log[] = array(
				'post_id'         => $post_id,
				'title'           => get_the_title( $post_id ),
				'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				'rejection_codes' => $snap['rejection_codes'],
			);
		}

		return $log;
	}

	// ── Private: build one product snapshot ──────────────────────────────────

	/**
	 * Compare one WC product against its GMC counterpart and return a snapshot.
	 *
	 * @param int         $post_id
	 * @param WC_Product  $wc_product
	 * @param array       $gmc_by_sku     GMC products keyed by lowercase offerId.
	 * @param array       $gmc_by_gtin    GMC products keyed by GTIN.
	 * @param array       $gmc_statuses   GMC product statuses keyed by GMC product ID.
	 * @return array
	 */
	private static function build_product_snapshot( $post_id, $wc_product, $gmc_by_sku, $gmc_by_gtin, $gmc_statuses ) {
		// --- Local WC data ---
		$sku_local          = (string) $wc_product->get_sku();
		$gtin_local         = (string) get_post_meta( $post_id, '_global_unique_id', true ) ?: (string) get_post_meta( $post_id, '_gtin', true );
		$mpn_local          = (string) get_post_meta( $post_id, '_mpn', true );
		$title_local        = $wc_product->get_name();
		$brand_local        = (string) get_post_meta( $post_id, '_brand', true );
		$price_local        = (string) $wc_product->get_price();
		$regular_local      = (string) $wc_product->get_regular_price();
		$sale_local         = (string) $wc_product->get_sale_price();
		$currency_local     = get_woocommerce_currency();
		$avail_local        = self::wc_availability( $wc_product );
		$qty_local          = (int) $wc_product->get_stock_quantity();
		$desc_local         = wp_strip_all_tags( $wc_product->get_description() );
		$cat_local          = implode( ', ', wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) ) );
		$modified_ts        = strtotime( get_post_field( 'post_modified_gmt', $post_id ) );

		// --- Find matching GMC product ---
		$gmc_item = null;

		if ( $gtin_local && isset( $gmc_by_gtin[ $gtin_local ] ) ) {
			$gmc_item = $gmc_by_gtin[ $gtin_local ];
		} elseif ( $sku_local && isset( $gmc_by_sku[ strtolower( $sku_local ) ] ) ) {
			$gmc_item = $gmc_by_sku[ strtolower( $sku_local ) ];
		}

		$matched = $gmc_item !== null;

		// --- GMC data (populated only when matched) ---
		$gmc_id             = '';
		$gtin_gmc           = '';
		$mpn_gmc            = '';
		$sku_gmc            = '';
		$title_gmc          = '';
		$brand_gmc          = '';
		$price_gmc          = '';
		$currency_gmc       = '';
		$avail_gmc          = '';
		$category_gmc       = '';
		$gmc_update_ts      = 0;
		$rejection_codes    = array();
		$price_mismatch     = false;
		$avail_mismatch     = false;
		$schema_discrepancy = false;
		$sync_latency       = 0;

		if ( $matched ) {
			$gmc_id       = $gmc_item['id'] ?? '';
			$gtin_gmc     = $gmc_item['gtin'] ?? '';
			$mpn_gmc      = $gmc_item['mpn'] ?? '';
			$sku_gmc      = $gmc_item['offerId'] ?? '';
			$title_gmc    = $gmc_item['title'] ?? '';
			$brand_gmc    = $gmc_item['brand'] ?? '';
			$currency_gmc = $gmc_item['price']['currency'] ?? '';
			$price_gmc    = $gmc_item['price']['value'] ?? '';
			$avail_gmc    = $gmc_item['availability'] ?? '';
			$category_gmc = $gmc_item['googleProductCategory'] ?? '';

			if ( isset( $gmc_item['updateTime'] ) ) {
				$gmc_update_ts = strtotime( $gmc_item['updateTime'] );
			}

			// Price comparison — normalise to 2 decimal places.
			if ( $price_local !== '' && $price_gmc !== '' ) {
				$price_mismatch = ( number_format( (float) $price_local, 2 ) !== number_format( (float) $price_gmc, 2 ) )
				               || ( $currency_local !== $currency_gmc );
			}

			// Availability comparison.
			$avail_gmc_norm  = strtolower( str_replace( '_', ' ', $avail_gmc ) );
			$avail_local_gmc = self::wc_avail_to_gmc( $avail_local );
			$avail_mismatch  = ( $avail_local_gmc !== $avail_gmc_norm );

			// Schema discrepancy: check if local WC structured data attributes
			// differ from GMC feed (GTIN/MPN present locally but not in GMC).
			if ( ( $gtin_local && ! $gtin_gmc ) || ( $mpn_local && ! $mpn_gmc ) ) {
				$schema_discrepancy = true;
			}

			// Rejection codes from productstatuses.
			if ( $gmc_id && isset( $gmc_statuses[ $gmc_id ] ) ) {
				$status_data = $gmc_statuses[ $gmc_id ];
				foreach ( $status_data['itemLevelIssues'] ?? array() as $issue ) {
					if ( ( $issue['servability'] ?? '' ) === 'disapproved' ) {
						$rejection_codes[] = array(
							'code'        => $issue['code'] ?? '',
							'description' => $issue['description'] ?? '',
							'resolution'  => $issue['resolution'] ?? '',
							'destination' => $issue['applicableCountries'][0] ?? '',
						);
					}
				}
			}

			// Latency: seconds between last WP save and GMC update.
			if ( $modified_ts && $gmc_update_ts ) {
				$sync_latency = max( 0, $modified_ts - $gmc_update_ts );
			}
		}

		return array(
			// Identity
			'matched'            => $matched,
			'gmc_id'             => $gmc_id,
			'sku_local'          => $sku_local,
			'sku_gmc'            => $sku_gmc,
			'gtin_local'         => $gtin_local,
			'gtin_gmc'           => $gtin_gmc,
			'mpn_local'          => $mpn_local,
			'mpn_gmc'            => $mpn_gmc,
			'brand_local'        => $brand_local,
			'brand_gmc'          => $brand_gmc,
			// Titles
			'title_local'        => $title_local,
			'title_gmc'          => $title_gmc,
			// Pricing
			'price_local'        => $price_local,
			'regular_price'      => $regular_local,
			'sale_price'         => $sale_local,
			'price_gmc'          => $price_gmc,
			'currency_local'     => $currency_local,
			'currency_gmc'       => $currency_gmc,
			'price_mismatch'     => $price_mismatch,
			// Inventory
			'avail_local'        => $avail_local,
			'avail_gmc'          => $avail_gmc,
			'qty_local'          => $qty_local,
			'avail_mismatch'     => $avail_mismatch,
			// Categorisation
			'category_local'     => $cat_local,
			'category_gmc'       => $category_gmc,
			// Integrity
			'schema_discrepancy' => $schema_discrepancy,
			'rejection_codes'    => $rejection_codes,
			// Timestamps
			'last_sync_local'    => $modified_ts,
			'last_sync_gmc'      => $gmc_update_ts,
			'sync_latency'       => $sync_latency,
			'synced_at'          => time(),
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function wc_availability( WC_Product $product ) {
		if ( $product->is_on_backorder() ) {
			return 'backorder';
		}
		if ( $product->is_in_stock() ) {
			return 'in_stock';
		}
		return 'out_of_stock';
	}

	private static function wc_avail_to_gmc( $avail ) {
		$map = array(
			'in_stock'    => 'in stock',
			'out_of_stock'=> 'out of stock',
			'backorder'   => 'backorder',
		);
		return $map[ $avail ] ?? $avail;
	}
}
