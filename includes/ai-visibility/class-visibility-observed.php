<?php
/**
 * AI Visibility — the observed layer.
 *
 * The probes ask what an assistant WOULD say; this class reports what actually
 * happened: GA4 sessions whose source is an AI assistant, Search Console's top
 * queries, and Bing Webmaster's query stats. Each summary is independent and
 * degrades to `[ 'connected' => false ]` when its integration is not
 * configured — the board renders a connect card, never a notice.
 *
 * Every result the page renders is defensive on the page side too
 * (see TWTAEO_Page_AI_Visibility::render_observed), so a shape drift here
 * shows an empty card rather than a fatal.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Observed {

	/** Transient keys (12 h — observation, not measurement-in-flight). */
	const TRANSIENT_REFERRALS = 'twtaeo_vis_obs_referrals';
	const TRANSIENT_GSC       = 'twtaeo_vis_obs_gsc';
	const TRANSIENT_BING      = 'twtaeo_vis_obs_bing';
	/** Rows asked of Search Console, and rows shown after the machine-query filter. */
	const QUERY_FETCH = 100;
	const QUERY_SHOW  = 10;

	const CACHE_TTL           = 12 * HOUR_IN_SECONDS;

	/** Referrer sources that are assistants but not probe engines. */
	const EXTRA_LABELS = array(
		'copilot'    => 'Copilot',
		'you'        => 'You.com',
		'duckduckgo' => 'DuckDuckGo',
		'meta'       => 'Meta AI',
	);

	private function __construct() {}

	/** Drop every cached summary (used after a reconnect). */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT_REFERRALS );
		delete_transient( self::TRANSIENT_GSC );
		delete_transient( self::TRANSIENT_BING );
	}

	/* ─────────────────────────── GA4 referrals ────────────────────────── */

	/**
	 * Sessions whose source is an AI assistant, per assistant, with the pages
	 * they landed on.
	 *
	 * @param int $days Window, default 28.
	 * @return array {
	 *   connected: bool, error?: string, property?: string,
	 *   total_sessions?: int,
	 *   by_assistant?: array<string,{label:string,sessions:int,users:int,landing_pages:array<int,array{0:string,1:int}>}>
	 * }
	 */
	public static function ai_referrals( $days = 28 ) {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return array( 'connected' => false );
		}
		$config   = TWTAEO_Google_OAuth::get_config();
		$property = isset( $config['ga4_property_id'] ) ? trim( (string) $config['ga4_property_id'] ) : '';
		if ( '' === $property ) {
			return array( 'connected' => false );
		}

		$cached = get_transient( self::TRANSIENT_REFERRALS );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$days  = max( 1, (int) $days );
		$hosts = array_keys( TWTAEO_Visibility_Types::AI_REFERRER_HOSTS );
		$body  = array(
			'dateRanges'      => array(
				array(
					'startDate' => $days . 'daysAgo',
					'endDate'   => 'today',
				),
			),
			'dimensions'      => array(
				array( 'name' => 'sessionSource' ),
				array( 'name' => 'landingPagePlusQueryString' ),
			),
			'metrics'         => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
			),
			'dimensionFilter' => array(
				'filter' => array(
					'fieldName'    => 'sessionSource',
					'inListFilter' => array(
						'values'        => array_values( $hosts ),
						'caseSensitive' => false,
					),
				),
			),
			'limit'           => 1000,
		);

		$data = TWTAEO_Google_OAuth::ga4_run_report( $property, $body );
		if ( is_wp_error( $data ) ) {
			// Errors are not cached, so a transient outage clears on the next load.
			return array( 'connected' => true, 'error' => $data->get_error_message() );
		}

		$by    = array();
		$total = 0;
		$rows  = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$dims    = isset( $row['dimensionValues'] ) && is_array( $row['dimensionValues'] ) ? $row['dimensionValues'] : array();
			$metrics = isset( $row['metricValues'] ) && is_array( $row['metricValues'] ) ? $row['metricValues'] : array();
			$source  = isset( $dims[0]['value'] ) ? strtolower( trim( (string) $dims[0]['value'] ) ) : '';
			$landing = isset( $dims[1]['value'] ) ? (string) $dims[1]['value'] : '';
			$sess    = isset( $metrics[0]['value'] ) ? (int) $metrics[0]['value'] : 0;
			$users   = isset( $metrics[1]['value'] ) ? (int) $metrics[1]['value'] : 0;

			$assistant = isset( TWTAEO_Visibility_Types::AI_REFERRER_HOSTS[ $source ] )
				? TWTAEO_Visibility_Types::AI_REFERRER_HOSTS[ $source ]
				: '';
			if ( '' === $assistant ) {
				continue;
			}
			if ( ! isset( $by[ $assistant ] ) ) {
				$by[ $assistant ] = array(
					'label'         => self::assistant_label( $assistant ),
					'sessions'      => 0,
					'users'         => 0,
					'landing_pages' => array(),
				);
			}
			$by[ $assistant ]['sessions'] += $sess;
			$by[ $assistant ]['users']    += $users;
			$total                        += $sess;
			if ( '' !== $landing ) {
				if ( ! isset( $by[ $assistant ]['landing_pages'][ $landing ] ) ) {
					$by[ $assistant ]['landing_pages'][ $landing ] = 0;
				}
				$by[ $assistant ]['landing_pages'][ $landing ] += $sess;
			}
		}

		// Landing pages: top 6 per assistant, as [ path, sessions ] pairs.
		foreach ( $by as $assistant => $row ) {
			arsort( $by[ $assistant ]['landing_pages'] );
			$pages = array();
			foreach ( array_slice( $by[ $assistant ]['landing_pages'], 0, 6, true ) as $path => $sessions ) {
				$pages[] = array( (string) $path, (int) $sessions );
			}
			$by[ $assistant ]['landing_pages'] = $pages;
		}

		// Busiest assistant first.
		uasort( $by, function ( $a, $b ) {
			return $b['sessions'] - $a['sessions'];
		} );

		$result = array(
			'connected'      => true,
			'property'       => $property,
			'total_sessions' => $total,
			'by_assistant'   => $by,
		);
		set_transient( self::TRANSIENT_REFERRALS, $result, self::CACHE_TTL );
		return $result;
	}

	/** Assistant id → display label: probe engines by their label, the rest by name. */
	private static function assistant_label( $assistant ) {
		if ( isset( TWTAEO_Visibility_Types::ENGINES[ $assistant ]['label'] ) ) {
			return TWTAEO_Visibility_Types::ENGINES[ $assistant ]['label'];
		}
		if ( isset( self::EXTRA_LABELS[ $assistant ] ) ) {
			return self::EXTRA_LABELS[ $assistant ];
		}
		return ucfirst( (string) $assistant );
	}

	/* ─────────────────────────── Search Console ───────────────────────── */

	/**
	 * Top queries and totals from Search Console, last 28 days.
	 *
	 * @return array { connected, error?, queries?: [{query,clicks,impressions}], clicks?, impressions? }
	 */
	public static function gsc_summary() {
		// Fetch wide, show few: the wide fetch is what survives the machine-query
		// filter with real queries still left to show.

		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return array( 'connected' => false );
		}
		$config   = TWTAEO_Google_OAuth::get_config();
		$site_url = isset( $config['gsc_site_url'] ) ? trim( (string) $config['gsc_site_url'] ) : '';
		if ( '' === $site_url ) {
			return array( 'connected' => false );
		}

		$cached = get_transient( self::TRANSIENT_GSC );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Ask for far more than we show. Search Console's top rows are routinely
		// rank-tracker traffic (see is_machine_query()), and filtering ten rows
		// down leaves a panel with two lines in it.
		$data = TWTAEO_Google_OAuth::gsc_search_analytics( $site_url, array(
			'startDate'  => gmdate( 'Y-m-d', time() - 28 * DAY_IN_SECONDS ),
			'endDate'    => gmdate( 'Y-m-d' ),
			'dimensions' => array( 'query' ),
			'rowLimit'   => self::QUERY_FETCH,
		) );
		if ( is_wp_error( $data ) ) {
			return array( 'connected' => true, 'error' => $data->get_error_message() );
		}

		$queries     = array();
		$clicks      = 0;
		$impressions = 0;
		$machine     = 0;
		$rows        = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$query = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $query ) {
				continue;
			}
			$c = isset( $row['clicks'] ) ? (int) round( (float) $row['clicks'] ) : 0;
			$i = isset( $row['impressions'] ) ? (int) round( (float) $row['impressions'] ) : 0;

			// Totals count everything Search Console reported, machine rows
			// included — they are real impressions and hiding them would
			// misstate the account.
			$clicks      += $c;
			$impressions += $i;

			if ( self::is_machine_query( $query ) ) {
				$machine++;
				continue;
			}
			$queries[] = array(
				'query'       => $query,
				'clicks'      => $c,
				'impressions' => $i,
				'question'    => self::is_question_query( $query ),
			);
		}

		// Questions first — those are the ones an assistant answers, and the
		// only ones this module can act on — then by clicks, then impressions.
		usort(
			$queries,
			function ( $a, $b ) {
				if ( $a['question'] !== $b['question'] ) {
					return $a['question'] ? -1 : 1;
				}
				if ( $a['clicks'] !== $b['clicks'] ) {
					return $b['clicks'] - $a['clicks'];
				}
				if ( $a['impressions'] !== $b['impressions'] ) {
					return $b['impressions'] - $a['impressions'];
				}
				return strcmp( $a['query'], $b['query'] );
			}
		);
		$questions = 0;
		foreach ( $queries as $q ) {
			if ( $q['question'] ) {
				$questions++;
			}
		}
		$queries = array_slice( $queries, 0, self::QUERY_SHOW );

		$result = array(
			'connected'   => true,
			'queries'     => $queries,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'machine'     => $machine,
			'questions'   => $questions,
		);
		set_transient( self::TRANSIENT_GSC, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * A query no human typed.
	 *
	 * Search Console's top rows are frequently rank-tracker and SERP-scraper
	 * traffic: long operator chains like
	 * `"aioseo" -site:reddit.com -site:twitter.com …`, almost always 1 impression
	 * and 0 clicks. They are not questions anybody asked, so they tell you
	 * nothing about how you are found — and ten of them fill the panel and push
	 * every real query out of sight.
	 *
	 * These are dropped from the LIST only. Their impressions still count in the
	 * totals, because they did happen and understating the account would be its
	 * own kind of lie.
	 *
	 * @param string $query The query.
	 * @return bool
	 */
	public static function is_machine_query( $query ) {
		$q = strtolower( trim( (string) $query ) );
		if ( '' === $q ) {
			return true;
		}
		// Search operators: no shopper types these.
		if ( preg_match( '/(^|\s)-?(site|inurl|intitle|intext|filetype|cache|related|allintitle|allinurl):/', $q ) ) {
			return true;
		}
		// Negated terms ("-\"hybrid\"") are a rank-tracker disambiguation trick.
		if ( preg_match_all( '/(^|\s)-\S/', $q ) >= 2 ) {
			return true;
		}
		// Boolean operator soup.
		if ( substr_count( $q, '"' ) >= 4 || false !== strpos( $q, ' OR ' ) || false !== strpos( $q, ' AND ' ) ) {
			return true;
		}
		// Nobody types eighteen words into a search box.
		$words = preg_split( '/\s+/', $q );
		if ( is_array( $words ) && count( $words ) > 18 ) {
			return true;
		}
		return false;
	}

	/**
	 * Does this read like a question an assistant would answer? These are the
	 * queries this module can actually act on, so they sort to the top.
	 *
	 * @param string $query The query.
	 * @return bool
	 */
	public static function is_question_query( $query ) {
		$q = strtolower( trim( (string) $query ) );
		if ( '' === $q ) {
			return false;
		}
		if ( false !== strpos( $q, '?' ) ) {
			return true;
		}
		return 1 === preg_match(
			'/(^|\s)(who|what|what\'s|whats|when|where|why|how|which|can|does|do|is|are|should|will|best|top|vs|versus|compare|alternative|alternatives|near me|cost|price|pricing|review|reviews)(\s|$)/',
			$q
		);
	}

	/* ─────────────────────────── Bing Webmaster ───────────────────────── */

	/**
	 * Top queries from Bing Webmaster's query stats.
	 *
	 * @return array { connected, error?, queries?: [{query,clicks,impressions}] }
	 */
	public static function bing_summary() {
		if ( ! class_exists( 'TWTAEO_Bing_Webmaster' ) || ! TWTAEO_Bing_Webmaster::is_connected() ) {
			return array( 'connected' => false );
		}

		$cached = get_transient( self::TRANSIENT_BING );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = TWTAEO_Bing_Webmaster::get_query_stats();
		if ( is_wp_error( $data ) ) {
			return array( 'connected' => true, 'error' => $data->get_error_message() );
		}

		$rows = array();
		if ( isset( $data['d'] ) && is_array( $data['d'] ) ) {
			// The OData JSON shape is either { d: [...] } or { d: { results: [...] } }.
			$rows = isset( $data['d']['results'] ) && is_array( $data['d']['results'] )
				? $data['d']['results']
				: $data['d'];
		}

		$queries = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$query = isset( $row['Query'] ) ? (string) $row['Query'] : ( isset( $row['query'] ) ? (string) $row['query'] : '' );
			if ( '' === $query ) {
				continue;
			}
			$queries[] = array(
				'query'       => $query,
				'clicks'      => isset( $row['Clicks'] ) ? (int) $row['Clicks'] : 0,
				'impressions' => isset( $row['Impressions'] ) ? (int) $row['Impressions'] : 0,
			);
		}
		usort( $queries, function ( $a, $b ) {
			if ( $b['clicks'] !== $a['clicks'] ) {
				return $b['clicks'] - $a['clicks'];
			}
			return $b['impressions'] - $a['impressions'];
		} );
		$queries = array_slice( $queries, 0, 10 );

		$result = array(
			'connected' => true,
			'queries'   => $queries,
		);
		set_transient( self::TRANSIENT_BING, $result, self::CACHE_TTL );
		return $result;
	}
}
