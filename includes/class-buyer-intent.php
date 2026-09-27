<?php
/**
 * Buyer intent classification.
 *
 * Search queries are not equal. "buy nike air max size 10" is a shopper with a
 * card in hand; "how to clean running shoes" is someone three steps earlier. A
 * store needs different structural signals for each, so this classifies the
 * queries a store actually ranks for and turns them into concrete advice.
 *
 * Classification is pattern-based and deterministic — no API call, no cost, and
 * the same query always lands in the same bucket.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Buyer_Intent {

	const TRANSACTIONAL = 'transactional';
	const COMMERCIAL    = 'commercial';
	const INFORMATIONAL = 'informational';
	const NAVIGATIONAL  = 'navigational';

	/**
	 * Intent definitions — label, colour, what it means, and the structural
	 * signals that matter for that intent on a store.
	 *
	 * @return array
	 */
	public static function intents() {
		return array(
			self::TRANSACTIONAL => array(
				'label'   => __( 'Ready to buy', 'twt-aeo-ultimate' ),
				'color'   => '#16a34a',
				'meaning' => __( 'The shopper has decided what they want and is looking for somewhere to buy it. These are your highest-value queries.', 'twt-aeo-ultimate' ),
				'signals' => array(
					__( 'Complete Offer schema — price, currency, availability and condition', 'twt-aeo-ultimate' ),
					__( 'Shipping cost and delivery time exposed as structured data', 'twt-aeo-ultimate' ),
					__( 'A return policy the shopper (and an AI) can actually find', 'twt-aeo-ultimate' ),
					__( 'GTIN/MPN identifiers so the product matches across shopping surfaces', 'twt-aeo-ultimate' ),
					__( 'A healthy Merchant Center feed with no disapprovals', 'twt-aeo-ultimate' ),
				),
			),
			self::COMMERCIAL    => array(
				'label'   => __( 'Comparing options', 'twt-aeo-ultimate' ),
				'color'   => '#7f54b3',
				'meaning' => __( 'The shopper is weighing choices — "best", "vs", "review". Win these and you are in the consideration set before they search to buy.', 'twt-aeo-ultimate' ),
				'signals' => array(
					__( 'Ratings and genuine reviews, especially from verified buyers', 'twt-aeo-ultimate' ),
					__( 'A specification table an assistant can lift for comparisons', 'twt-aeo-ultimate' ),
					__( 'Clear differentiators stated plainly in the description', 'twt-aeo-ultimate' ),
					__( 'Related and alternative products linked in the graph', 'twt-aeo-ultimate' ),
				),
			),
			self::INFORMATIONAL => array(
				'label'   => __( 'Researching', 'twt-aeo-ultimate' ),
				'color'   => '#d97706',
				'meaning' => __( 'The shopper is learning — how, what, why. This is where AI assistants answer directly, so being the cited source builds the trust that pays off later.', 'twt-aeo-ultimate' ),
				'signals' => array(
					__( 'A direct answer in the opening lines, not marketing atmosphere', 'twt-aeo-ultimate' ),
					__( 'FAQ content answering the actual question asked', 'twt-aeo-ultimate' ),
					__( 'Buying guides that link through to the relevant products', 'twt-aeo-ultimate' ),
				),
			),
			self::NAVIGATIONAL  => array(
				'label'   => __( 'Looking for you', 'twt-aeo-ultimate' ),
				'color'   => '#0891b2',
				'meaning' => __( 'The shopper already knows your brand and is searching for it by name. Protect these — losing them means losing customers you already earned.', 'twt-aeo-ultimate' ),
				'signals' => array(
					__( 'Organization schema with logo, contact details and sameAs links', 'twt-aeo-ultimate' ),
					__( 'Consistent brand naming across your site and review profiles', 'twt-aeo-ultimate' ),
				),
			),
		);
	}

	/**
	 * Classify a single search query.
	 *
	 * Checked most-specific first: an explicit purchase word beats a comparison
	 * word ("buy the best running shoes" is transactional), and a brand match
	 * only wins when nothing commercial or transactional is present.
	 *
	 * @param string $query      The search query.
	 * @param string $brand_name Optional store/brand name for navigational detection.
	 * @return string One of the intent constants.
	 */
	public static function classify( $query, $brand_name = '' ) {
		$q = ' ' . strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $query ) ) ) . ' ';

		// Transactional — explicit purchase or acquisition language.
		$transactional = array(
			'buy', 'purchase', 'order', 'for sale', 'shop', 'checkout', 'add to cart',
			'price', 'prices', 'pricing', 'cost', 'cheap', 'cheapest', 'discount',
			'coupon', 'promo', 'deal', 'deals', 'sale', 'clearance', 'bargain',
			'free shipping', 'next day delivery', 'in stock', 'near me', 'store near',
			'where to buy', 'online store',
		);
		foreach ( $transactional as $needle ) {
			if ( false !== strpos( $q, ' ' . $needle . ' ' ) || false !== strpos( $q, ' ' . $needle . 's ' ) ) {
				return self::TRANSACTIONAL;
			}
		}

		// Commercial investigation — comparison and evaluation language.
		$commercial = array(
			'best', 'top', 'review', 'reviews', 'vs', 'versus', 'compare', 'comparison',
			'alternative', 'alternatives', 'which', 'rated', 'rating', 'ranked',
			'worth it', 'good', 'better', 'recommended', 'pros and cons',
		);
		foreach ( $commercial as $needle ) {
			if ( false !== strpos( $q, ' ' . $needle . ' ' ) ) {
				return self::COMMERCIAL;
			}
		}

		// Informational — question and learning language.
		$informational = array(
			'how', 'what', 'why', 'when', 'guide', 'tutorial', 'tips', 'ideas',
			'meaning', 'difference', 'can i', 'do i', 'should i', 'is it',
			'instructions', 'diy', 'care', 'clean', 'fix', 'repair', 'size chart',
		);
		foreach ( $informational as $needle ) {
			if ( false !== strpos( $q, ' ' . $needle . ' ' ) ) {
				return self::INFORMATIONAL;
			}
		}

		// Navigational — the store's own name, once nothing stronger matched.
		$brand = strtolower( trim( (string) $brand_name ) );
		if ( '' !== $brand && false !== strpos( $q, $brand ) ) {
			return self::NAVIGATIONAL;
		}

		// A bare product-style query ("nike air max 90") is someone shopping.
		return self::COMMERCIAL;
	}

	/**
	 * Classify a set of Search Console query rows.
	 *
	 * @param array  $rows       Rows of { query, clicks, impressions, ctr, position }.
	 * @param string $brand_name Store name, for navigational detection.
	 * @return array {
	 *   buckets      — per-intent totals and their top queries
	 *   opportunities — specific, actionable findings
	 *   total_*      — overall counts
	 * }
	 */
	public static function analyze( array $rows, $brand_name = '' ) {
		$buckets = array();
		foreach ( array_keys( self::intents() ) as $key ) {
			$buckets[ $key ] = array(
				'queries'     => array(),
				'clicks'      => 0,
				'impressions' => 0,
			);
		}

		$total_clicks      = 0;
		$total_impressions = 0;

		foreach ( $rows as $row ) {
			$query = isset( $row['query'] ) ? (string) $row['query'] : '';
			if ( '' === $query ) {
				continue;
			}
			$intent      = self::classify( $query, $brand_name );
			$clicks      = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0.0;

			$buckets[ $intent ]['clicks']      += $clicks;
			$buckets[ $intent ]['impressions'] += $impressions;
			$buckets[ $intent ]['queries'][]    = array(
				'query'       => $query,
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'position'    => $position,
				'ctr'         => $impressions > 0 ? ( $clicks / $impressions ) : 0.0,
			);

			$total_clicks      += $clicks;
			$total_impressions += $impressions;
		}

		// Sort each bucket by impressions so the biggest opportunities lead.
		foreach ( $buckets as $key => $bucket ) {
			usort( $buckets[ $key ]['queries'], function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			} );
		}

		return array(
			'buckets'           => $buckets,
			'opportunities'     => self::find_opportunities( $buckets ),
			'total_clicks'      => $total_clicks,
			'total_impressions' => $total_impressions,
			'total_queries'     => count( $rows ),
		);
	}

	/**
	 * Turn the classified data into specific findings a merchant can act on.
	 *
	 * @param array $buckets
	 * @return array List of { severity, title, detail, queries }.
	 */
	private static function find_opportunities( array $buckets ) {
		$out = array();

		// 1. High-impression buy-intent queries with poor click-through — the page
		//    is being shown to ready buyers but not winning the click.
		$weak_ctr = array();
		foreach ( $buckets[ self::TRANSACTIONAL ]['queries'] as $q ) {
			if ( $q['impressions'] >= 50 && $q['ctr'] < 0.02 ) {
				$weak_ctr[] = $q;
			}
		}
		if ( $weak_ctr ) {
			$out[] = array(
				'severity' => 'high',
				'title'    => __( 'Ready-to-buy searches that are not converting to clicks', 'twt-aeo-ultimate' ),
				'detail'   => __( 'These queries show your store to people ready to purchase, but almost nobody clicks. That usually means the result looks thin next to competitors — no price, no rating, no availability in the listing. Completing Offer and review schema on the matching products is the fix.', 'twt-aeo-ultimate' ),
				'queries'  => array_slice( $weak_ctr, 0, 5 ),
			);
		}

		// 2. Buy-intent queries ranking just off the first page.
		$near_miss = array();
		foreach ( $buckets[ self::TRANSACTIONAL ]['queries'] as $q ) {
			if ( $q['position'] >= 8 && $q['position'] <= 20 && $q['impressions'] >= 20 ) {
				$near_miss[] = $q;
			}
		}
		if ( $near_miss ) {
			$out[] = array(
				'severity' => 'high',
				'title'    => __( 'Purchase-intent queries sitting just off page one', 'twt-aeo-ultimate' ),
				'detail'   => __( 'You already rank for these buying searches, just not high enough to earn traffic. These are the cheapest wins available — richer product data and stronger review coverage move them furthest.', 'twt-aeo-ultimate' ),
				'queries'  => array_slice( $near_miss, 0, 5 ),
			);
		}

		// 3. Strong comparison demand — reviews and specs decide these.
		if ( $buckets[ self::COMMERCIAL ]['impressions'] > $buckets[ self::TRANSACTIONAL ]['impressions'] && $buckets[ self::COMMERCIAL ]['impressions'] > 0 ) {
			$out[] = array(
				'severity' => 'medium',
				'title'    => __( 'Most of your visibility is shoppers comparing options', 'twt-aeo-ultimate' ),
				'detail'   => __( 'More people find you while weighing choices than while ready to buy. Comparison results are decided by ratings, specifications and clear differences — make sure your products carry verified reviews and a real specification table.', 'twt-aeo-ultimate' ),
				'queries'  => array_slice( $buckets[ self::COMMERCIAL ]['queries'], 0, 5 ),
			);
		}

		// 4. Research demand with no matching content is a citation opportunity.
		if ( $buckets[ self::INFORMATIONAL ]['impressions'] >= 100 ) {
			$out[] = array(
				'severity' => 'medium',
				'title'    => __( 'Research questions you already appear for', 'twt-aeo-ultimate' ),
				'detail'   => __( 'These are the questions AI assistants answer directly. Answering them plainly on your own pages — with FAQ content and a direct opening statement — is how a store becomes the cited source rather than a skipped link.', 'twt-aeo-ultimate' ),
				'queries'  => array_slice( $buckets[ self::INFORMATIONAL ]['queries'], 0, 5 ),
			);
		}

		return $out;
	}
}
