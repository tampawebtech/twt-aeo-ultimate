<?php
/**
 * Bing Merchant Center Sync Engine
 *
 * Mirrors the GMC Sync Engine but targets the Microsoft Advertising Shopping
 * Content API. Diffs WooCommerce product data against the Bing Merchant Center
 * feed, stores per-product snapshots, and calculates an Integrity Score.
 *
 * Postmeta: _twtaeo_bmc_sync
 * Option:   twtaeo_bmc_last_sync
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_BMC_Sync_Engine {

	const META_SYNC   = '_twtaeo_bmc_sync';
	const OPTION_LAST = 'twtaeo_bmc_last_sync';
	const NONCE_SYNC  = 'twtaeo_bmc_sync_nonce';
	const NONCE_SAVE  = 'twtaeo_bmc_save_nonce';

	// ── Public entry points ──────────────────────────────────────────────────

	/**
	 * Run a full sync against Bing Merchant Center.
	 *
	 * @return array|WP_Error  Summary array on success.
	 */
	public static function run_sync() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not active.' );
		}

		$store_id = TWTAEO_Bing_Merchant_Center::get_store_id();
		if ( ! $store_id ) {
			return new WP_Error( 'no_store_id', 'Bing Merchant Center Store ID is not configured.' );
		}

		$bmc_products = TWTAEO_Bing_Merchant_Center::list_all_products( $store_id );
		if ( is_wp_error( $bmc_products ) ) {
			return $bmc_products;
		}

		$bmc_statuses = TWTAEO_Bing_Merchant_Center::list_all_product_statuses( $store_id );
		if ( is_wp_error( $bmc_statuses ) ) {
			return $bmc_statuses;
		}

		// Build lookup maps.
		$bmc_by_sku  = array();
		$bmc_by_gtin = array();

		foreach ( $bmc_products as $item ) {
			$offer_id = $item['offerId'] ?? '';
			$gtin     = $item['gtin'] ?? '';

			if ( $offer_id ) {
				$bmc_by_sku[ strtolower( $offer_id ) ] = $item;
			}
			if ( $gtin ) {
				$bmc_by_gtin[ $gtin ] = $item;
			}
		}

		$wc_posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$summary = self::diff_chunk( $wc_posts, $bmc_by_sku, $bmc_by_gtin, $bmc_statuses );

		update_option( self::OPTION_LAST, time() );
		delete_transient( 'twtaeo_bmc_integrity_score' );

		return $summary;
	}

	/**
	 * Diff a batch of WC products against pre-built BMC lookup maps and persist
	 * the per-product snapshots. Used by run_sync() for the full catalog and by
	 * the merchant sync queue one chunk at a time.
	 *
	 * @param int[] $post_ids
	 * @param array $by_sku   BMC products keyed by lowercase offerId.
	 * @param array $by_gtin  BMC products keyed by GTIN.
	 * @param array $statuses BMC product statuses keyed by BMC product ID.
	 * @return array Summary counters for this batch.
	 */
	public static function diff_chunk( array $post_ids, array $by_sku, array $by_gtin, array $statuses ) {
		$summary = array(
			'total'          => count( $post_ids ),
			'matched'        => 0,
			'unmatched'      => 0,
			'price_mismatch' => 0,
			'avail_mismatch' => 0,
			'rejected'       => 0,
			'schema_issues'  => 0,
		);

		foreach ( $post_ids as $post_id ) {
			$wc_product = wc_get_product( $post_id );
			if ( ! $wc_product ) {
				continue;
			}

			$snapshot = self::build_product_snapshot( $post_id, $wc_product, $by_sku, $by_gtin, $statuses );
			update_post_meta( $post_id, self::META_SYNC, $snapshot );

			if ( $snapshot['matched'] ) {
				$summary['matched']++;
			} else {
				$summary['unmatched']++;
			}
			if ( $snapshot['price_mismatch'] )               $summary['price_mismatch']++;
			if ( $snapshot['avail_mismatch'] )               $summary['avail_mismatch']++;
			if ( ! empty( $snapshot['rejection_codes'] ) )   $summary['rejected']++;
			if ( $snapshot['schema_discrepancy'] )           $summary['schema_issues']++;
		}

		return $summary;
	}

	/**
	 * Calculate and cache the Bing E-Commerce Integrity Score (0–100).
	 *
	 * @param array|null $rows Optional pre-fetched get_product_snapshots() rows,
	 *                         so render paths can share one catalog pass.
	 * @return array { score: int, label: string, counts: array, synced: bool }
	 */
	public static function get_integrity_score( $rows = null ) {
		if ( null === $rows ) {
			$cached = get_transient( 'twtaeo_bmc_integrity_score' );
			if ( $cached !== false ) {
				return $cached;
			}
			$rows = self::get_product_snapshots();
		}

		if ( empty( $rows ) ) {
			return array( 'score' => 100, 'label' => 'Excellent', 'counts' => array(), 'synced' => false );
		}

		$counts = array(
			'total'          => count( $rows ),
			'synced'         => 0,
			'matched'        => 0,
			'unmatched'      => 0,
			'price_mismatch' => 0,
			'avail_mismatch' => 0,
			'rejected'       => 0,
			'schema_issues'  => 0,
		);

		foreach ( $rows as $row ) {
			$snap = $row['snap'];
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

		set_transient( 'twtaeo_bmc_integrity_score', $result, HOUR_IN_SECONDS );

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

		// One query for posts + meta instead of two per product below.
		_prime_post_caches( $wc_posts, false, true );

		$rows = array();
		foreach ( $wc_posts as $post_id ) {
			$snap   = get_post_meta( $post_id, self::META_SYNC, true );
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
	 * Get only products with rejection codes, for the Rejection Log section.
	 *
	 * @param array|null $rows Optional pre-fetched get_product_snapshots() rows.
	 * @return array[]
	 */
	public static function get_rejection_log( $rows = null ) {
		if ( null === $rows ) {
			$rows = self::get_product_snapshots();
		}

		$log = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row['snap'] ) || empty( $row['snap']['rejection_codes'] ) ) {
				continue;
			}
			$log[] = array(
				'post_id'         => $row['post_id'],
				'title'           => $row['title'],
				'edit_url'        => $row['edit_url'],
				'rejection_codes' => $row['snap']['rejection_codes'],
			);
		}

		return $log;
	}

	// ── Private: build one product snapshot ──────────────────────────────────

	private static function build_product_snapshot( $post_id, $wc_product, $bmc_by_sku, $bmc_by_gtin, $bmc_statuses ) {
		// --- Local WC data ---
		$sku_local      = (string) $wc_product->get_sku();
		$gtin_local     = (string) get_post_meta( $post_id, '_global_unique_id', true ) ?: (string) get_post_meta( $post_id, '_gtin', true );
		$mpn_local      = (string) get_post_meta( $post_id, '_mpn', true );
		$title_local    = $wc_product->get_name();
		$brand_local    = (string) get_post_meta( $post_id, '_brand', true );
		$price_local    = (string) $wc_product->get_price();
		$regular_local  = (string) $wc_product->get_regular_price();
		$sale_local     = (string) $wc_product->get_sale_price();
		$currency_local = get_woocommerce_currency();
		$avail_local    = self::wc_availability( $wc_product );
		$qty_local      = (int) $wc_product->get_stock_quantity();
		$cat_local      = implode( ', ', wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) ) );
		$modified_ts    = strtotime( get_post_field( 'post_modified_gmt', $post_id ) );

		// --- Find matching BMC product ---
		$bmc_item = null;

		if ( $gtin_local && isset( $bmc_by_gtin[ $gtin_local ] ) ) {
			$bmc_item = $bmc_by_gtin[ $gtin_local ];
		} elseif ( $sku_local && isset( $bmc_by_sku[ strtolower( $sku_local ) ] ) ) {
			$bmc_item = $bmc_by_sku[ strtolower( $sku_local ) ];
		}

		$matched = $bmc_item !== null;

		// --- BMC data ---
		$bmc_id             = '';
		$gtin_bmc           = '';
		$mpn_bmc            = '';
		$sku_bmc            = '';
		$title_bmc          = '';
		$brand_bmc          = '';
		$price_bmc          = '';
		$currency_bmc       = '';
		$avail_bmc          = '';
		$category_bmc       = '';
		$bmc_update_ts      = 0;
		$rejection_codes    = array();
		$price_mismatch     = false;
		$avail_mismatch     = false;
		$schema_discrepancy = false;
		$sync_latency       = 0;

		if ( $matched ) {
			$bmc_id       = $bmc_item['id'] ?? '';
			$gtin_bmc     = $bmc_item['gtin'] ?? '';
			$mpn_bmc      = $bmc_item['mpn'] ?? '';
			$sku_bmc      = $bmc_item['offerId'] ?? '';
			$title_bmc    = $bmc_item['title'] ?? '';
			$brand_bmc    = $bmc_item['brand'] ?? '';
			$currency_bmc = $bmc_item['price']['currency'] ?? '';
			$price_bmc    = $bmc_item['price']['value'] ?? '';
			$avail_bmc    = $bmc_item['availability'] ?? '';
			$category_bmc = $bmc_item['googleProductCategory'] ?? $bmc_item['productType'] ?? '';

			if ( isset( $bmc_item['updateTime'] ) ) {
				$bmc_update_ts = strtotime( $bmc_item['updateTime'] );
			}

			if ( $price_local !== '' && $price_bmc !== '' ) {
				$price_mismatch = ( number_format( (float) $price_local, 2 ) !== number_format( (float) $price_bmc, 2 ) )
				               || ( $currency_local !== $currency_bmc );
			}

			$avail_bmc_norm  = strtolower( str_replace( '_', ' ', $avail_bmc ) );
			$avail_local_bmc = self::wc_avail_to_bmc( $avail_local );
			$avail_mismatch  = ( $avail_local_bmc !== $avail_bmc_norm );

			if ( ( $gtin_local && ! $gtin_bmc ) || ( $mpn_local && ! $mpn_bmc ) ) {
				$schema_discrepancy = true;
			}

			// Bing productstatuses use itemLevelIssues with a 'servability' field.
			if ( $bmc_id && isset( $bmc_statuses[ $bmc_id ] ) ) {
				$status_data = $bmc_statuses[ $bmc_id ];
				foreach ( $status_data['itemLevelIssues'] ?? array() as $issue ) {
					if ( ( $issue['servability'] ?? '' ) === 'disapproved' ) {
						$rejection_codes[] = array(
							'code'        => $issue['code'] ?? '',
							'description' => $issue['description'] ?? '',
							'resolution'  => $issue['resolution'] ?? '',
						);
					}
				}
			}

			if ( $modified_ts && $bmc_update_ts ) {
				$sync_latency = max( 0, $modified_ts - $bmc_update_ts );
			}
		}

		return array(
			'matched'            => $matched,
			'bmc_id'             => $bmc_id,
			'sku_local'          => $sku_local,
			'sku_bmc'            => $sku_bmc,
			'gtin_local'         => $gtin_local,
			'gtin_bmc'           => $gtin_bmc,
			'mpn_local'          => $mpn_local,
			'mpn_bmc'            => $mpn_bmc,
			'brand_local'        => $brand_local,
			'brand_bmc'          => $brand_bmc,
			'title_local'        => $title_local,
			'title_bmc'          => $title_bmc,
			'price_local'        => $price_local,
			'regular_price'      => $regular_local,
			'sale_price'         => $sale_local,
			'price_bmc'          => $price_bmc,
			'currency_local'     => $currency_local,
			'currency_bmc'       => $currency_bmc,
			'price_mismatch'     => $price_mismatch,
			'avail_local'        => $avail_local,
			'avail_bmc'          => $avail_bmc,
			'qty_local'          => $qty_local,
			'avail_mismatch'     => $avail_mismatch,
			'category_local'     => $cat_local,
			'category_bmc'       => $category_bmc,
			'schema_discrepancy' => $schema_discrepancy,
			'rejection_codes'    => $rejection_codes,
			'last_sync_local'    => $modified_ts,
			'last_sync_bmc'      => $bmc_update_ts,
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

	private static function wc_avail_to_bmc( $avail ) {
		$map = array(
			'in_stock'    => 'in stock',
			'out_of_stock'=> 'out of stock',
			'backorder'   => 'backorder',
		);
		return $map[ $avail ] ?? $avail;
	}
}
