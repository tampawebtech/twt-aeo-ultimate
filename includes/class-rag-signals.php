<?php
/**
 * RAG Signals — where the site nearly wins, and what its documents could add.
 *
 * Two sources the plugin already collects, read (never re-run) and matched
 * against the Content Index:
 *
 *  - Search placement: Google Search Console and Bing Webmaster queries where
 *    the site ranks in the "almost" band (default positions 4–15 — with ads
 *    and AI Overviews on top, page one effectively ends around position 3).
 *  - AI citations: questions from the latest AI Visibility run that one or
 *    more engines answered without citing the site.
 *
 * An opportunity is a query or question the site's documents answer and the
 * relevant page does not. Matching here is keyword-based; the reserved
 * embedding column brings meaning-based matching in a later phase.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_RAG_Signals {

	/** Default "almost ranking" band. */
	const POS_MIN = 4;
	const POS_MAX = 15;

	/** Provider data is cached; rankings do not move by the minute. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	const CACHE_GSC  = 'twtaeo_rag_gsc_rows';
	const CACHE_BING = 'twtaeo_rag_bing_rows';

	/** Most provider rows matched per view — keeps the page fast on big sites. */
	const MAX_ROWS = 150;

	/*
	 * Which searches a document can answer, and which words must match.
	 *
	 * A document answers "how", "what" and "which model" searches. It cannot
	 * answer "who do I hire" — "cnc spindle repair near me", "multi spindle
	 * atlanta, ga", "buy new precision spindles" — however many of its words
	 * a brochure happens to contain. And in the searches it can answer, words
	 * like "services", "cost" or "best" say what the searcher wants to do, not
	 * what the subject is: a passage must match the subject, not those.
	 */

	/** Words that always mean the searcher wants a business to hire or buy from. */
	const HIRE_WORDS = '/\b(near\s+me|nearby|for\s+sale|buy|hire|looking\s+for|recommend\w*|who\s+(?:should|can|do|does|offers?|repairs?|fix\w*|rebuilds?)|which\s+(?:compan(?:y|ies)|shops?|business\w*|providers?|vendors?)|where\s+(?:can|do|to|should)\s+i\s+(?:find|get|buy|send|go))\b/i';

	/** Words that mean it unless the search is also a question ("how much do spindle repair services cost"). */
	const HIRE_WORDS_WEAK = '/\b(services?|compan(?:y|ies)|contractors?|suppliers?|dealers?|distributors?|providers?|vendors?|solutions|manufacturers?|rebuilders?|repairers?|specialists?|remanufactur\w*|shops?)\b/i';

	/** Words that turn "spindle repair" from a hiring search into a question. */
	const HOW_WORDS = '/\b(how|why|what|when|which|can|should|does|do|is|diy|steps?|guide|instructions?|troubleshoot\w*|symptoms?|signs?|causes?|vs|versus|cost|price|much|long|procedure|manual)\b/i';

	/** Search words that never decide whether a passage is about the subject. */
	const INTENT_TERMS = array( 'servic', 'service', 'company', 'cost', 'pric', 'price', 'pricing', 'much', 'new', 'used', 'best', 'top', 'cheap', 'cheapest', 'affordabl', 'affordable', 'quality', 'professional', 'expert', 'quick', 'fast', 'reliabl', 'reliable', 'near', 'nearby', 'local', 'solution', 'buy', 'online' );

	/** Two-letter state codes that are not also everyday words. */
	const STATE_CODES = array( 'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'dc', 'de', 'fl', 'ga', 'ia', 'il', 'ks', 'ky', 'la', 'ma', 'md', 'mi', 'mn', 'mo', 'ms', 'mt', 'nc', 'nd', 'ne', 'nh', 'nj', 'nm', 'nv', 'ny', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'va', 'vt', 'wa', 'wi', 'wv', 'wy' );


	/**
	 * Why a search is looking for a business rather than an answer, or ''.
	 *
	 * @param string $query
	 * @return string hire | place | repair | brand | ''
	 */
	public static function hiring_intent( $query ) {
		$q = strtolower( trim( (string) $query ) );
		// "site:example.com": the owner (or a tool) checking the index.
		if ( preg_match( '/\b(?:site|inurl|intitle):/', $q ) ) {
			return 'brand';
		}
		$question = (bool) preg_match( self::HOW_WORDS, $q );
		if ( preg_match( self::HIRE_WORDS, $q ) || ( ! $question && preg_match( self::HIRE_WORDS_WEAK, $q ) ) ) {
			return 'hire';
		}
		if ( self::names_place( $q ) ) {
			return 'place';
		}
		// "atlanta precision spindles" on atlantaprecisionspindles.com: the
		// search spells out the business's own name. A search that only
		// shares a couple of its words ("precision spindles") is a product
		// search, not a name search.
		$name = rtrim( self::domain_name(), 's' );
		if ( strlen( $name ) >= 6 && ! $question && false !== strpos( preg_replace( '/[^a-z0-9]/', '', $q ), $name ) ) {
			return 'brand';
		}
		// "precise spindle repair", "broken spindle repair": a shop to send
		// it to. "how to repair a spindle", "spindle repair cost": a question.
		// "air conditioning repair seo": the repair names the searcher's
		// industry; the search is about marketing it.
		$marketing = (bool) preg_match( '/\b(seo|marketing|leads?|keywords?|advertising|ads|ppc|website|web\s+design)\b/', $q );
		if ( preg_match( '/\b(repairs?|repairing|rebuil(?:d|ds|ding|t)|reconditioning|refurbish\w*|replacement)\b/', $q ) && ! $question && ! $marketing && ! self::identifiers( $q ) ) {
			return 'repair';
		}

		return '';
	}

	/** Whether a search names a US state or the business's own city. */
	private static function names_place( $q ) {
		if ( TWTAEO_Doc_Profile::states_in( $q ) ) {
			return true;
		}
		// "multi spindle atlanta, ga", "precision spindle repair norristown pa".
		$words = preg_split( '/[\s,]+/', $q, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) >= 3 && in_array( end( $words ), self::STATE_CODES, true ) ) {
			return true;
		}
		// A street address: "1645 lakes pkwy lawrenceville ga 30043".
		if ( preg_match( '/\b\d{5}(?:-\d{4})?$/', $q ) && preg_match( '/\b(?:' . implode( '|', self::STATE_CODES ) . ')\s+\d{5}/', $q ) ) {
			return true;
		}
		if ( preg_match( '/^\d{2,6}\s+\w+.*\b(?:st|street|ave|avenue|rd|road|blvd|pkwy|parkway|hwy|highway|dr|drive|ln|lane|way|ct|court)\b/', $q ) ) {
			return true;
		}
		foreach ( self::business_places() as $place ) {
			if ( preg_match( '/\b' . preg_quote( $place, '/' ) . '\b/', $q ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The business's city and the towns in its Local Pack service area
	 * ("Atlanta, Marietta, Lawrenceville"): searches naming them are looking
	 * for a local business.
	 *
	 * @return string[]
	 */
	private static function business_places() {
		static $places = null;
		if ( null === $places ) {
			$places = array();
			$city   = self::business_city();
			if ( '' !== $city ) {
				$places[] = $city;
			}
			$lp = class_exists( 'TWTAEO_Local_Pack' ) ? (array) TWTAEO_Local_Pack::get_settings() : array();
			foreach ( preg_split( '/[,;\n]+/', (string) ( isset( $lp['area_served'] ) ? $lp['area_served'] : '' ) ) as $area ) {
				// "Atlanta, GA" → "atlanta"; "Metro Atlanta" stays as typed.
				$area = strtolower( trim( (string) preg_replace( '/\s+\b[A-Z]{2}\b$/', '', trim( (string) $area ) ) ) );
				if ( strlen( $area ) >= 3 && ! TWTAEO_Doc_Profile::states_in( $area ) && ! in_array( $area, $places, true ) ) {
					$places[] = $area;
				}
			}
		}

		return $places;
	}

	/** The site's domain name without "www." or the ending: "atlantaprecisionspindles". */
	private static function domain_name() {
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		return preg_replace( '/[^a-z0-9]/', '', (string) preg_replace( '/\.[a-z.]+$/', '', (string) $host ) );
	}

	/** The business's city, from the Local Pack profile or the store address. */
	private static function business_city() {
		static $city = null;
		if ( null === $city ) {
			$lp   = class_exists( 'TWTAEO_Local_Pack' ) ? (array) TWTAEO_Local_Pack::get_settings() : array();
			$city = strtolower( trim( (string) ( ! empty( $lp['city'] ) ? $lp['city'] : get_option( 'woocommerce_store_city', '' ) ) ) );
		}

		return $city;
	}

	/**
	 * The terms a passage must match: the search's terms without the ones
	 * that only state intent. All of them when there are only intent words.
	 *
	 * @return string[]
	 */
	private static function subject_terms( $query ) {
		$terms   = TWTAEO_Content_Index::query_terms( $query );
		$subject = array_values( array_diff( $terms, self::INTENT_TERMS ) );

		return $subject ? $subject : $terms;
	}

	/**
	 * Share of the subject terms a passage must contain. Up to three: every
	 * one. Four: three. Long conversational questions rarely have most of
	 * their words in one passage, so the bar drops to 60%.
	 */
	private static function required_share( $n ) {
		if ( $n <= 3 ) {
			return 1.0;
		}

		return 4 === $n ? 0.75 : 0.6;
	}

	/** Whether a passage matches the subject of a search well enough. */
	private static function passage_answers( array $hit, array $subject ) {
		if ( TWTAEO_Content_Index::terms_share( $hit, $subject ) < self::required_share( count( $subject ) ) ) {
			return false;
		}
		// A brochure page says "accurate positioning" in one paragraph and
		// "milling spindle" in another. For a short search the words must sit
		// together: two words as a phrase, three within a sentence or two.
		if ( 2 === count( $subject ) ) {
			return TWTAEO_Content_Index::terms_near( $hit, $subject, 8 );
		}
		if ( 3 === count( $subject ) ) {
			return TWTAEO_Content_Index::terms_near( $hit, $subject, 20 );
		}

		return true;
	}

	private function __construct() {}

	/* ─────────────────────────── status ─────────────────────────── */

	/**
	 * What is connected and what has been run, for the Overview tab and the
	 * empty states of the other tabs.
	 *
	 * @return array
	 */
	public static function status() {
		$google_connected = class_exists( 'TWTAEO_Google_OAuth' ) && TWTAEO_Google_OAuth::is_connected();
		$config           = class_exists( 'TWTAEO_Google_OAuth' ) ? (array) TWTAEO_Google_OAuth::get_config() : array();
		$gsc_site         = isset( $config['gsc_site_url'] ) ? (string) $config['gsc_site_url'] : '';

		$run = self::latest_run_row();

		return array(
			'google'     => array(
				'connected' => $google_connected,
				'site_url'  => $gsc_site,
				'ready'     => $google_connected && '' !== $gsc_site,
			),
			'bing'       => array(
				'ready' => class_exists( 'TWTAEO_Bing_Webmaster' ) && TWTAEO_Bing_Webmaster::is_connected(),
			),
			'visibility' => array(
				'module' => self::visibility_module_active(),
				'run'    => $run,
			),
			'setup_url'      => admin_url( 'admin.php?page=twt-aeo-command-center&twtaeo_tab=settings' ),
			'visibility_url' => class_exists( 'TWTAEO_Visibility_Types' ) ? admin_url( 'admin.php?page=' . TWTAEO_Visibility_Types::PAGE_SLUG ) : '',
			'modules_url'    => admin_url( 'admin.php?page=twt-aeo-modules' ),
		);
	}

	/** Forget cached provider rows (the "Refresh data" button). */
	public static function clear_cache() {
		delete_transient( self::CACHE_GSC );
		delete_transient( self::CACHE_BING );
	}

	/* ─────────────────────────── search placement ─────────────────────────── */

	/**
	 * Queries ranking inside the band that the documents answer and the page
	 * does not (fully) answer.
	 *
	 * @param int $min Best position counted (inclusive).
	 * @param int $max Worst position counted (inclusive).
	 * @return array { opportunities: array[], in_band: int, hiring: int, errors: string[] }
	 */
	public static function placement( $min = self::POS_MIN, $max = self::POS_MAX ) {
		$min    = max( 1, min( 100, (int) $min ) );
		$max    = max( $min, min( 100, (int) $max ) );
		$errors = array();
		$rows   = array();

		$google = self::gsc_rows();
		if ( is_wp_error( $google ) ) {
			$errors[] = sprintf(
				/* translators: %s: error message. */
				__( 'Google Search Console: %s', 'twt-aeo-ultimate' ),
				$google->get_error_message()
			);
		} else {
			$rows = array_merge( $rows, $google );
		}

		$bing = self::bing_rows();
		if ( is_wp_error( $bing ) ) {
			$errors[] = sprintf(
				/* translators: %s: error message. */
				__( 'Bing Webmaster Tools: %s', 'twt-aeo-ultimate' ),
				$bing->get_error_message()
			);
		} else {
			$rows = array_merge( $rows, $bing );
		}

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $min, $max ) {
					return null !== $r['position'] && $r['position'] >= $min && $r['position'] < $max + 1;
				}
			)
		);
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		$in_band = count( $rows );

		$out      = array();
		$hiring   = array();
		$restored = array();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			// "Trane XR14 installation manual": the searcher wants the
			// document itself. If it is one of the uploads, the opportunity is
			// to publish it — whatever the ranking page already says.
			if ( self::wants_document( $row['query'] ) ) {
				$doc = self::matching_document( $row['query'] );
				if ( $doc ) {
					$row['type']         = 'publish';
					$row['document']     = $doc;
					$row['docs']         = $doc['passages'];
					$row['candidates']   = $row['post_id']
						? array( self::candidate( (int) $row['post_id'], array( __( 'Google ranks this page', 'twt-aeo-ultimate' ) ) ) )
						: self::likely_pages( $row['query'], 2 );
					foreach ( $row['candidates'] as &$c ) {
						$c['coverage'] = self::coverage( $row['query'], $c['post_id'] );
					}
					unset( $c );
					$row['identifiers']  = self::identifiers( $row['query'] );
					$row['no_page_for']  = array();
					$row['domain_match'] = self::domain_matches( $row['query'] );
					$out[]               = $row;
					continue;
				}
			}

			// "cnc spindle repair near me": a search for a business to hire.
			// Counted, so the screen can say why it is not listed.
			if ( '' !== self::hiring_intent( $row['query'] ) ) {
				$hiring[ strtolower( $row['query'] ) ] = true;
				continue;
			}

			// "vibrocontrol 6000": the site still ranks for a page it has since
			// trashed or unpublished. Putting it back is the quickest win, so it
			// is shown whether or not a document answers the search.
			$former = self::former_page( $row['query'] );
			// Google names a live page for it (or the home page): the site
			// still has a page ranking, so there is nothing to restore.
			if ( $former && ! empty( $row['page_url'] ) ) {
				$live = (int) $row['post_id'];
				$home = untrailingslashit( home_url( '/' ) ) === untrailingslashit( strtok( (string) $row['page_url'], '?#' ) );
				if ( $home || ( $live && 'publish' === get_post_status( $live ) ) ) {
					$former = null;
				}
			}
			// One restore card per search and page, however many ranking rows.
			if ( $former && isset( $restored[ strtolower( $row['query'] ) . '|' . $former['post_id'] ] ) ) {
				continue;
			}
			if ( $former ) {
				$restored[ strtolower( $row['query'] ) . '|' . $former['post_id'] ] = true;
				$row['type']         = 'restore';
				$row['former']       = $former;
				$row['docs']         = self::doc_hits( $row['query'] );
				$row['candidates']   = array();
				$row['identifiers']  = self::identifiers( $row['query'] );
				$row['no_page_for']  = array();
				$row['domain_match'] = false;
				$out[]               = $row;
				continue;
			}

			$docs = self::doc_hits( $row['query'] );
			if ( empty( $docs ) ) {
				continue;
			}
			$row['type'] = 'answer';

			$ids        = self::identifiers( $row['query'] );
			$candidates = array();

			if ( $row['post_id'] ) {
				// Google names the ranking page. Recommend it — unless the
				// search names a model the ranking page does not, and a more
				// specific page for that model exists (a hub outranking its
				// own spoke): then the answer belongs on the spoke.
				$candidates[] = self::candidate( (int) $row['post_id'], array( __( 'Google ranks this page', 'twt-aeo-ultimate' ) ) );
				if ( $ids && ! self::page_names_identifier( (int) $row['post_id'], $ids ) ) {
					foreach ( self::likely_pages( $row['query'], 3 ) as $better ) {
						if ( $better['post_id'] !== (int) $row['post_id'] && self::page_names_identifier( $better['post_id'], $ids ) ) {
							$better['more_specific'] = true;
							array_unshift( $candidates, $better );
							break;
						}
					}
				}
			} else {
				// Bing does not name the page: offer the likeliest few.
				$candidates = self::likely_pages( $row['query'], 3 );
			}

			foreach ( $candidates as &$candidate ) {
				$candidate['coverage'] = self::coverage( $row['query'], $candidate['post_id'] );
			}
			unset( $candidate );

			// Drop the search only when every candidate already answers it.
			$open = array_filter(
				$candidates,
				static function ( $c ) {
					return 'covered' !== $c['coverage']['state'];
				}
			);
			if ( $candidates && ! $open ) {
				continue;
			}

			$named_somewhere = false;
			foreach ( $candidates as $c ) {
				if ( self::page_names_identifier( $c['post_id'], $ids ) ) {
					$named_somewhere = true;
					break;
				}
			}

			$row['candidates']   = $candidates;
			$row['identifiers']  = $ids;
			// A bare number is named with the word beside it: "VIBROCONTROL 6000", not "6000".
			$row['no_page_for']  = ( $ids && ! $named_somewhere ) ? ( self::bare_numbers_only( $ids ) ? array( $row['query'] ) : $ids ) : array();
			$row['domain_match'] = self::domain_matches( $row['query'] );
			$row['docs']         = $docs;
			$out[]               = $row;
		}

		return array(
			'opportunities' => $out,
			'in_band'       => $in_band,
			'hiring'        => count( $hiring ),
			'errors'        => $errors,
		);
	}

	/**
	 * Search Console query + page rows for the last 28 days (cached).
	 *
	 * @return array[]|WP_Error
	 */
	private static function gsc_rows() {
		$status = self::status();
		if ( ! $status['google']['ready'] ) {
			return array();
		}

		$cached = get_transient( self::CACHE_GSC );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::gsc_search_analytics(
			$status['google']['site_url'],
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				'dimensions' => array( 'query', 'page' ),
				'rowLimit'   => 1000,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $r ) {
			$query = isset( $r['keys'][0] ) ? trim( (string) $r['keys'][0] ) : '';
			$page  = isset( $r['keys'][1] ) ? (string) $r['keys'][1] : '';
			if ( '' === $query ) {
				continue;
			}
			$rows[] = array(
				'engine'      => 'google',
				'query'       => $query,
				'page_url'    => $page,
				'post_id'     => $page ? (int) url_to_postid( $page ) : 0,
				'position'    => isset( $r['position'] ) ? round( (float) $r['position'], 1 ) : null,
				'impressions' => (int) ( $r['impressions'] ?? 0 ),
				'clicks'      => (int) ( $r['clicks'] ?? 0 ),
			);
		}

		set_transient( self::CACHE_GSC, $rows, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * Bing query rows, aggregated across dates by impression-weighted position
	 * (cached). Bing's query report does not name the page.
	 *
	 * @return array[]|WP_Error
	 */
	private static function bing_rows() {
		if ( ! class_exists( 'TWTAEO_Bing_Webmaster' ) || ! TWTAEO_Bing_Webmaster::is_connected() ) {
			return array();
		}

		$cached = get_transient( self::CACHE_BING );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = TWTAEO_Bing_Webmaster::get_query_stats();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw = $result['d']['results'] ?? $result['d'] ?? array();
		$agg = array();
		foreach ( (array) $raw as $r ) {
			$query = trim( (string) ( $r['Query'] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}
			$impr = (int) ( $r['Impressions'] ?? 0 );
			$pos  = isset( $r['AvgImpressionPosition'] ) ? (float) $r['AvgImpressionPosition'] : 0.0;
			if ( ! isset( $agg[ $query ] ) ) {
				$agg[ $query ] = array( 'impressions' => 0, 'clicks' => 0, 'pos_weight' => 0.0, 'pos_impr' => 0 );
			}
			$agg[ $query ]['impressions'] += $impr;
			$agg[ $query ]['clicks']      += (int) ( $r['Clicks'] ?? 0 );
			if ( $pos > 0 && $impr > 0 ) {
				$agg[ $query ]['pos_weight'] += $pos * $impr;
				$agg[ $query ]['pos_impr']   += $impr;
			}
		}

		$rows = array();
		foreach ( $agg as $query => $a ) {
			$rows[] = array(
				'engine'      => 'bing',
				'query'       => $query,
				'page_url'    => '',
				'post_id'     => 0,
				'position'    => $a['pos_impr'] > 0 ? round( $a['pos_weight'] / $a['pos_impr'], 1 ) : null,
				'impressions' => $a['impressions'],
				'clicks'      => $a['clicks'],
			);
		}

		set_transient( self::CACHE_BING, $rows, self::CACHE_TTL );

		return $rows;
	}

	/* ─────────────────────────── AI citations ─────────────────────────── */

	/**
	 * Questions from the latest finished AI Visibility run that at least one
	 * engine answered without citing the site, and that the documents answer.
	 *
	 * @return array { run: array|null, opportunities: array[], asked: int }
	 */
	public static function citations() {
		$row = self::latest_run_row();
		if ( ! $row || ! class_exists( 'TWTAEO_Visibility_Store' ) ) {
			return array( 'run' => null, 'opportunities' => array(), 'asked' => 0 );
		}

		$run = TWTAEO_Visibility_Store::get_run( $row['id'] );
		if ( ! $run ) {
			return array( 'run' => null, 'opportunities' => array(), 'asked' => 0 );
		}

		$questions = array();
		foreach ( (array) $run['questions'] as $q ) {
			if ( is_array( $q ) && isset( $q['id'], $q['text'] ) ) {
				$questions[ $q['id'] ] = $q;
			}
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$site_host = preg_replace( '/^www\./', '', $site_host );

		$by_q = array();
		foreach ( (array) $run['checks'] as $check ) {
			$qid = $check['question_id'];
			if ( ! isset( $questions[ $qid ] ) || 'unavailable' === $check['verdict'] ) {
				continue;
			}
			if ( ! isset( $by_q[ $qid ] ) ) {
				$by_q[ $qid ] = array( 'cited' => array(), 'named' => array(), 'absent' => array(), 'our_urls' => array(), 'others' => array(), 'no_sources' => array() );
			}
			$by_q[ $qid ][ $check['verdict'] ][] = $check['engine'];
			// Answered, did not cite you, and cited nobody else either.
			if ( in_array( $check['verdict'], array( 'absent', 'named' ), true ) && empty( $check['domains'] ) && empty( $check['cited_urls'] ) ) {
				$by_q[ $qid ]['no_sources'][] = $check['engine'];
			}
			$by_q[ $qid ]['our_urls']            = array_merge( $by_q[ $qid ]['our_urls'], (array) $check['our_urls'] );

			$owned = array_map( 'strtolower', (array) $check['owned_domains'] );
			foreach ( (array) $check['domains'] as $domain ) {
				$domain = preg_replace( '/^www\./', '', strtolower( (string) $domain ) );
				if ( '' !== $domain && $domain !== $site_host && ! in_array( $domain, $owned, true ) ) {
					$by_q[ $qid ]['others'][ $domain ] = isset( $by_q[ $qid ]['others'][ $domain ] ) ? $by_q[ $qid ]['others'][ $domain ] + 1 : 1;
				}
			}
		}

		$out = array();
		foreach ( $by_q as $qid => $agg ) {
			$missed = count( $agg['named'] ) + count( $agg['absent'] );
			if ( 0 === $missed ) {
				continue; // Every engine that answered cited the site.
			}
			$q    = $questions[ $qid ];
			$docs = self::doc_hits( $q['text'] );
			if ( empty( $docs ) ) {
				continue;
			}

			arsort( $agg['others'] );
			$target = self::question_target( $q );

			$out[] = array(
				'question'    => (string) $q['text'],
				'level'       => isset( $q['level'] ) ? (string) $q['level'] : '',
				'scope_label' => isset( $q['scope_label'] ) ? (string) $q['scope_label'] : '',
				'cited'       => $agg['cited'],
				'named'       => $agg['named'],
				'absent'      => $agg['absent'],
				'our_urls'    => array_values( array_unique( $agg['our_urls'] ) ),
				'others'      => array_slice( array_keys( $agg['others'] ), 0, 5 ),
				'no_sources'  => $agg['no_sources'],
				'post_id'     => $target,
				'coverage'    => self::coverage( $q['text'], $target ),
				'docs'        => $docs,
				'missed'      => $missed,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['missed'] <=> $a['missed'];
			}
		);

		return array(
			'run'           => $row,
			'opportunities' => $out,
			'asked'         => count( $by_q ),
		);
	}

	/* ─────────────────────────── Next questions ─────────────────────────── */

	/** Distinct follow-up questions matched per view — each is a search. */
	const MAX_FOLLOW_UPS = 120;

	/**
	 * The follow-up questions the engines predicted in the latest AI
	 * Visibility run — what they expect the buyer to ask next — checked
	 * against the site's pages and documents.
	 *
	 * Identical follow-ups from several engines are merged and counted: a
	 * question three engines predict weighs more than one only one does. Each
	 * lands in one of four places:
	 *
	 *  - answered:      a page already covers it (counted, not listed);
	 *  - opportunities: the documents answer it and the page does not — a card
	 *                   with the passages to copy, like the questions above;
	 *  - gaps:          neither the pages nor the documents answer it — content
	 *                   the site does not have yet;
	 *  - hiring:        it looks for a business, not an answer — a document
	 *                   cannot answer it (counted, not listed).
	 *
	 * Follow-ups predicted at later journey turns count too, and a follow-up
	 * journey mode went on to ask carries `asked` { engine: verdict } — what
	 * each engine said when the buyer actually asked it.
	 *
	 * These are predictions, not questions anyone typed; the screen says so.
	 *
	 * @return array { run, persona, predicted, distinct, answered, hiring, opportunities[], gaps[] }
	 */
	public static function next_questions() {
		$empty = array( 'run' => null, 'persona' => '', 'predicted' => 0, 'distinct' => 0, 'answered' => 0, 'hiring' => 0, 'opportunities' => array(), 'gaps' => array(), 'business' => array(), 'business_count' => 0 );
		$row   = self::latest_run_row();
		if ( ! $row || ! class_exists( 'TWTAEO_Visibility_Store' ) ) {
			return $empty;
		}
		$run = TWTAEO_Visibility_Store::get_run( $row['id'] );
		if ( ! $run ) {
			return $empty;
		}

		$questions = array();
		foreach ( (array) $run['questions'] as $q ) {
			if ( is_array( $q ) && isset( $q['id'], $q['text'] ) ) {
				$questions[ $q['id'] ] = $q;
			}
		}

		$norm = static function ( $text ) {
			return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', strtolower( (string) $text ) ) );
		};

		// Journey mode already asked some of these: what each engine then said.
		$journey = isset( $run['journey'] ) ? (array) $run['journey'] : array();
		$asked   = array();
		foreach ( $journey as $jc ) {
			if ( '' !== (string) $jc['asked'] && 'unavailable' !== $jc['verdict'] ) {
				$asked[ $norm( $jc['asked'] ) ][ $jc['engine'] ] = $jc['verdict'];
			}
		}

		// Merge identical follow-ups (case and punctuation aside) across engines
		// and across turns — a later turn's predictions are next questions too.
		$groups    = array();
		$predicted = 0;
		foreach ( array_merge( (array) $run['checks'], $journey ) as $check ) {
			if ( empty( $check['follow_ups'] ) || ! isset( $questions[ $check['question_id'] ] ) ) {
				continue;
			}
			$before = ! empty( $check['turn'] ) && (int) $check['turn'] > 1 ? (string) $check['asked'] : (string) $questions[ $check['question_id'] ]['text'];
			foreach ( (array) $check['follow_ups'] as $fq ) {
				$fq  = trim( (string) $fq );
				$key = $norm( $fq );
				if ( '' === $key ) {
					continue;
				}
				++$predicted;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'question' => $fq,
						'engines'  => array(),
						'after'    => array(),
						'asked'    => array(),
						'post_id'  => 0,
						'company'  => false,
					);
				}
				// Asked after a question about the business itself ("Is X a
				// legitimate store?", "What is X's return policy?").
				if ( 'company' === ( isset( $questions[ $check['question_id'] ]['level'] ) ? $questions[ $check['question_id'] ]['level'] : '' ) ) {
					$groups[ $key ]['company'] = true;
				}
				$groups[ $key ]['engines'][ $check['engine'] ] = true;
				$groups[ $key ]['after'][ $before ]             = true;
				$groups[ $key ]['asked']                        = isset( $asked[ $key ] ) ? $asked[ $key ] : array();
				if ( ! $groups[ $key ]['post_id'] ) {
					$groups[ $key ]['post_id'] = self::question_target( $questions[ $check['question_id'] ] );
				}
			}
		}

		// Most-predicted first, then capped: every group costs a few searches.
		uasort(
			$groups,
			static function ( $a, $b ) {
				return count( $b['engines'] ) <=> count( $a['engines'] );
			}
		);
		$groups = array_slice( $groups, 0, self::MAX_FOLLOW_UPS, true );

		$out = $empty;
		$out['run']       = $row;
		$out['persona']   = isset( $run['persona']['label'] ) ? (string) $run['persona']['label'] : '';
		$out['predicted'] = $predicted;
		$out['distinct']  = count( $groups );

		foreach ( $groups as $g ) {
			$fq    = $g['question'];
			$topic = self::business_topic( $fq, ! empty( $g['company'] ) );
			if ( '' !== $topic ) {
				// About the business: the owner's own pages answer it, never a
				// brochure. Grouped by topic, with the page built for that
				// topic (FAQ, warranty, shipping…) when the site has one — a
				// product page that happens to share words is not it.
				$page = self::topic_page( $topic );
				$out['business'][ $topic ][] = array(
					'question' => $fq,
					'engines'  => array_keys( $g['engines'] ),
					'after'    => array_keys( $g['after'] ),
					'asked'    => $g['asked'],
					'page'     => $page,
				);
				++$out['business_count'];
				continue;
			}
			if ( '' !== self::hiring_intent( $fq ) ) {
				++$out['hiring'];
				continue;
			}

			// The page the original question was about, else the likeliest pages.
			$candidates = $g['post_id']
				? array( self::candidate( $g['post_id'], array( __( 'the page the original question was about', 'twt-aeo-ultimate' ) ) ) )
				: self::likely_pages( $fq, 3 );
			$best = null;
			foreach ( $candidates as &$c ) {
				$c['coverage'] = self::coverage( $fq, $c['post_id'] );
				if ( 'covered' === $c['coverage']['state'] ) {
					$best = 'covered';
				} elseif ( 'partial' === $c['coverage']['state'] && 'covered' !== $best ) {
					$best = 'partial';
				}
			}
			unset( $c );
			if ( 'covered' === $best ) {
				++$out['answered'];
				continue;
			}

			$engines = array_keys( $g['engines'] );
			$after   = array_keys( $g['after'] );
			$docs    = self::doc_hits( $fq, (string) $after[0] );
			if ( $docs ) {
				$out['opportunities'][] = array(
					'question'    => $fq,
					'engines'     => $engines,
					'after'       => $after,
					'asked'       => $g['asked'],
					'candidates'  => $candidates,
					'identifiers' => self::identifiers( $fq ),
					'no_page_for' => array(),
					'docs'        => $docs,
				);
				continue;
			}

			$partial_page = 0;
			foreach ( $candidates as $c ) {
				if ( 'partial' === $c['coverage']['state'] ) {
					$partial_page = (int) $c['post_id'];
					break;
				}
			}
			$out['gaps'][] = array(
				'question' => $fq,
				'engines'  => $engines,
				'after'    => $after,
				'asked'    => $g['asked'],
				'partial'  => $partial_page,
			);
		}

		return $out;
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * The page a question is about, when it is about one: post and product
	 * questions carry the post ID as their scope.
	 */
	private static function question_target( array $q ) {
		$level = isset( $q['level'] ) ? $q['level'] : '';
		if ( in_array( $level, array( 'post', 'product' ), true ) && isset( $q['scope_id'] ) && ctype_digit( (string) $q['scope_id'] ) ) {
			$post = get_post( (int) $q['scope_id'] );
			return $post ? (int) $post->ID : 0;
		}

		return 0;
	}

	/* ─────────────────────── page choice and specificity ─────────────────────── */

	/**
	 * Model numbers and part numbers in a query: words mixing letters and
	 * digits (ES989, i-450H, 6205-2RS) or bare numbers of three digits or more.
	 * "ES 989" and "ES-989" are joined first, so every spelling yields "es989".
	 *
	 * @param string $query
	 * @return string[] Normalized: lowercase, letters and digits only.
	 */
	public static function identifiers( $query ) {
		$q = strtolower( TWTAEO_Content_Index::join_model_numbers( (string) $query ) );

		// Code sections such as NEC 210.8 or 430.52: three digits, a dot, then
		// more. (Decimals like 1.5 hp or 14.8 kN have fewer before the dot.)
		// Taken out first, so "210" is not also read as a part number.
		$dotted = array();
		if ( preg_match_all( '/\b\d{3,4}\.\d{1,3}\b/', $q, $dm ) ) {
			$dotted = $dm[0];
			$q      = str_replace( $dotted, ' ', $q );
		}

		preg_match_all( '/\b[a-z0-9][a-z0-9\-]*[a-z0-9]\b/', (string) $q, $m );
		$out = array();
		foreach ( $m[0] as $token ) {
			$norm       = preg_replace( '/[^a-z0-9]/', '', $token );
			$has_digit  = (bool) preg_match( '/\d/', $norm );
			$has_letter = (bool) preg_match( '/[a-z]/', $norm );
			if ( strlen( $norm ) < 3 || ! $has_digit ) {
				continue;
			}
			// A bare number is a part number only if it looks like one: not a
			// year, not the "000" of "10,000", not a long quantity.
			if ( ! $has_letter && ( '0' === $norm[0] || strlen( $norm ) > 7 || preg_match( '/^(19|20)\d{2}$/', $norm ) ) ) {
				continue;
			}
			$out[] = $norm;
		}

		foreach ( $dotted as $code ) {
			$out[] = $code;
		}

		// Error and fault codes are short — F9, E1, code 33, blinks 4 — so
		// they only count as exact identifiers when the search is about codes.
		if ( preg_match( '/\b(codes?|errors?|faults?|blink\w*|flash\w*|alarms?|diagnostic\w*|lights?)\b/', $q ) ) {
			if ( preg_match_all( '/\b[a-z]{1,2}-?\d{1,3}\b/', $q, $short ) ) {
				foreach ( $short[0] as $code ) {
					$out[] = str_replace( '-', '', $code );
				}
			}
			if ( preg_match_all( '/\b(?:codes?|errors?|faults?|blinks?|flashes)\s*#?\s*(\d{1,3})\b/', $q, $numbered ) ) {
				foreach ( $numbered[1] as $code ) {
					$out[] = $code;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Model years in a query ("2024 Camry oil capacity"). Not identifiers — a
	 * manual rarely prints its year on every page — but a passage from a
	 * different year's document must not answer this year's question.
	 *
	 * @return string[]
	 */
	private static function years( $query ) {
		preg_match_all( '/\b(19[5-9]\d|20[0-4]\d)\b/', (string) $query, $m );

		return array_values( array_unique( $m[1] ) );
	}

	/** Every model year a text mentions. */
	private static function years_in( $text ) {
		preg_match_all( '/\b(19[5-9]\d|20[0-4]\d)\b/', (string) $text, $m );

		return array_values( array_unique( $m[1] ) );
	}

	/* ─────────────────────── "publish this document" ─────────────────────── */

	/** Words that mean the searcher wants the document itself. */
	const DOCUMENT_WORDS = '/\b(manuals?|pdfs?|guides?|instructions?|specs?|specifications?|spec\s*sheets?|data\s*sheets?|datasheets?|m?sds|diagrams?|wiring|schematics?|brochures?|catalogu?es?|warrant(?:y|ies)|installation|install|owners?|parts\s*lists?|exploded\s*views?)\b/';

	/** Whether a search is looking for a document rather than an answer. */
	private static function wants_document( $query ) {
		return (bool) preg_match( self::DOCUMENT_WORDS, strtolower( (string) $query ) );
	}

	/**
	 * The uploaded document a document-search is looking for: one whose title
	 * or passages name the same model and the same subject. The words that
	 * only say "a document" (manual, pdf, guide) are left out of the match.
	 *
	 * @return array|null { id, title, passages[] }
	 */
	private static function matching_document( $query ) {
		$subject = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( self::DOCUMENT_WORDS, ' ', strtolower( (string) $query ) ) ) );
		if ( '' === $subject ) {
			return null;
		}
		$ids   = self::identifiers( $subject );
		$terms = self::subject_terms( $subject );
		if ( ! $ids && count( $terms ) < 2 ) {
			return null; // "installation guide" alone names no product.
		}

		$hits   = TWTAEO_Content_Index::search( $subject, TWTAEO_Content_Index::SOURCE_DOC, 12 );
		$titles = TWTAEO_Doc_Library::titles( wp_list_pluck( $hits, 'source_id' ) );
		$best   = array();
		foreach ( $hits as $hit ) {
			$doc   = (int) $hit['source_id'];
			$title = isset( $titles[ $doc ] ) ? $titles[ $doc ] : '';
			$text  = $title . ' ' . $hit['heading_path'] . ' ' . $hit['content'];
			// "omlat spindle manual": the document must name Omlat — every
			// subject word, title included — not just say "spindle".
			$as_row = array(
				'heading_path' => $title . ' ' . $hit['heading_path'],
				'content'      => $hit['content'],
			);
			if ( $ids ? ! self::text_has_identifier( $text, $ids ) : TWTAEO_Content_Index::terms_share( $as_row, $terms ) < self::required_share( count( $terms ) ) ) {
				continue;
			}
			if ( ! isset( $best[ $doc ] ) ) {
				$best[ $doc ] = array(
					'id'       => $doc,
					'title'    => $title,
					'score'    => 0.0,
					'passages' => array(),
				);
			}
			$best[ $doc ]['score']     += (float) $hit['score'];
			$best[ $doc ]['passages'][] = $hit;
		}
		if ( ! $best ) {
			return null;
		}
		usort(
			$best,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$best[0]['passages'] = array_slice( $best[0]['passages'], 0, 2 );

		return $best[0];
	}

	/**
	 * Whether text names one of the identifiers as a whole word, in any of its
	 * spellings (ES989, ES-989, ES 989) — and not as part of a longer one.
	 */
	private static function text_has_identifier( $text, array $ids ) {
		foreach ( $ids as $id ) {
			// Allow a space, hyphen or underscore at each letter/digit switch.
			$parts = preg_split( '/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', $id );
			$parts = array_map(
				static function ( $part ) {
					return preg_quote( $part, '/' );
				},
				(array) $parts
			);
			$pattern = '/(?<![a-z0-9])' . implode( '[\s\-_]?', $parts ) . '(?![a-z0-9])/i';
			if ( preg_match( $pattern, (string) $text ) ) {
				return true;
			}
		}

		return false;
	}

	/** Whether a page's title or web address names one of the identifiers. */
	private static function page_names_identifier( $post_id, array $ids ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! $ids ) {
			return false;
		}
		$path = (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );
		// A WooCommerce SKU names the product as surely as its title does.
		$sku = 'product' === get_post_type( $post_id ) ? (string) get_post_meta( $post_id, '_sku', true ) : '';

		return self::text_has_identifier( get_the_title( $post_id ) . ' ' . str_replace( '/', ' ', $path ) . ' ' . $sku, $ids );
	}

	/**
	 * The pages most likely to rank for a query when the engine does not say,
	 * ranked the way Bing tends to: the search's words in the web address
	 * count most, then in the title, then in the content. A page named for a
	 * model number in the query outranks the series or hub page above it.
	 *
	 * @param string $query
	 * @param int    $limit
	 * @return array[] post_id, reasons[], more_specific, rank_score
	 */
	private static function likely_pages( $query, $limit = 3 ) {
		$terms = TWTAEO_Content_Index::query_terms( $query );
		$ids   = self::identifiers( $query );
		$hits  = TWTAEO_Content_Index::search( $query, TWTAEO_Content_Index::SOURCE_POST, 25 );
		if ( ! $terms || ! $hits ) {
			return array();
		}

		$pages = array();
		foreach ( $hits as $hit ) {
			$id = (int) $hit['source_id'];
			if ( ! isset( $pages[ $id ] ) || $hit['score'] > $pages[ $id ] ) {
				$pages[ $id ] = (float) $hit['score'];
			}
		}

		$ranked = array();
		foreach ( $pages as $post_id => $content_score ) {
			$path  = strtolower( (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH ) );
			$title = strtolower( wp_strip_all_tags( get_the_title( $post_id ) ) );

			$in_url   = 0;
			$in_title = 0;
			foreach ( $terms as $term ) {
				$in_url   += false !== strpos( $path, $term ) ? 1 : 0;
				$in_title += false !== strpos( $title, $term ) ? 1 : 0;
			}
			$url_share   = $in_url / count( $terms );
			$title_share = $in_title / count( $terms );
			$named       = self::page_names_identifier( $post_id, $ids );
			$depth       = count( array_filter( explode( '/', $path ), 'strlen' ) );

			// Between equally good matches, the deeper (more specific) page
			// wins when the search names a model.
			$score = 100 * $url_share + 40 * $title_share + min( 30, $content_score ) + ( $named ? 150 : 0 ) + ( $ids ? $depth * 2 : 0 );

			$reasons = array();
			if ( $named ) {
				/* translators: %s: model or part number as searched. */
				$reasons[] = sprintf( __( 'named for %s', 'twt-aeo-ultimate' ), strtoupper( $ids[0] ) );
			}
			if ( $url_share >= 0.5 ) {
				$reasons[] = __( 'search words in its web address', 'twt-aeo-ultimate' );
			}
			if ( $title_share >= 0.5 ) {
				$reasons[] = __( 'search words in its title', 'twt-aeo-ultimate' );
			}
			if ( ! $reasons ) {
				$reasons[] = __( 'mentions the search words', 'twt-aeo-ultimate' );
			}

			$ranked[] = array(
				'post_id'       => (int) $post_id,
				'reasons'       => $reasons,
				'more_specific' => false,
				'rank_score'    => $score,
			);
		}

		usort(
			$ranked,
			static function ( $a, $b ) {
				return $b['rank_score'] <=> $a['rank_score'];
			}
		);

		return array_slice( $ranked, 0, max( 1, (int) $limit ) );
	}

	/** A candidate entry for a page the engine itself reported. */
	private static function candidate( $post_id, array $reasons ) {
		return array(
			'post_id'       => (int) $post_id,
			'reasons'       => $reasons,
			'more_specific' => false,
			'rank_score'    => 0,
		);
	}

	/**
	 * Whether the site's own domain name spells out the search — something
	 * Bing weighs heavily (hsdspindlerepair.com for "hsd spindle repair").
	 * Every search word must appear in the domain name.
	 */
	private static function domain_matches( $query ) {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host  = preg_replace( '/^www\./', '', $host );
		$name  = preg_replace( '/[^a-z0-9]/', '', (string) preg_replace( '/\.[a-z.]+$/', '', (string) $host ) );
		$terms = TWTAEO_Content_Index::query_terms( $query );
		if ( '' === $name || count( $terms ) < 2 ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( false === strpos( $name, preg_replace( '/[^a-z0-9]/', '', $term ) ) ) {
				return false;
			}
		}

		return true;
	}

	/** Strong document passages for a query or question. */
	private static function doc_hits( $query, $inherit = '' ) {
		if ( '' !== self::hiring_intent( $query ) ) {
			return array(); // "Who do I hire" is not answered by a document.
		}
		$subject = self::subject_terms( $query );
		$ids     = self::identifiers( $query );
		$years   = self::years( $query );

		// Words a passage must contain beyond the share of subject words.
		$must = array();
		// "What is Hybrid Ceramic vs Steel Bearings in CNC Spindles?": on a
		// spindle site "spindle", "cnc" and "repair" are in every document, so
		// sixty percent of the words matched a Hurco catalogue page. The
		// question's rarest word (ceramic) says what it is about; a passage
		// without it does not answer it.
		if ( ! $ids && count( $subject ) >= 3 ) {
			$rare = self::distinctive_term( $subject );
			if ( '' !== $rare ) {
				$must[] = $rare;
			}
		}
		// "What electrical requirements does it have?" after "Is VEM ECR11A
		// Spindle good?": "it" is the VEM ECR11A. A follow-up that points back
		// takes its model number, or failing that its rarest word, from the
		// question it followed.
		// When the earlier question named a model and this one names none
		// ("What are the typical runout and torque specifications?"), it is
		// still about that model, pronoun or not.
		if ( '' !== $inherit && ! $ids && ( self::refers_back( $query ) || self::identifiers( $inherit ) ) ) {
			$ids = self::identifiers( $inherit );
			if ( ! $ids ) {
				$rare = self::distinctive_term( self::subject_terms( $inherit ) );
				if ( '' !== $rare ) {
					$must[] = $rare;
				}
			}
		}
		$search_for = trim( $query . ' ' . implode( ' ', $ids ) . ' ' . implode( ' ', $must ) );
		$hits       = TWTAEO_Content_Index::search( $search_for, TWTAEO_Content_Index::SOURCE_DOC, 12 );
		$hits       = array_values(
			array_filter(
				$hits,
				static function ( $h ) use ( $subject, $ids, $must ) {
					foreach ( $must as $term ) {
						if ( TWTAEO_Content_Index::terms_share( $h, array( $term ) ) < 1 ) {
							return false;
						}
					}
					// An office list ("Switzerland FISCHER Ltd. +41 62 … info@…")
					// shares a brand's words with every search about it and
					// answers none of them.
					if ( self::is_contact_block( $h['content'] ) || self::is_contents_page( $h['content'] ) ) {
						return false;
					}
					// A model number must appear as itself: ES989 is not ES9891.
					if ( $ids ) {
						// "vibrocontrol 6000": a bare number names nothing on its
						// own (6000 rpm, 6000 hours), so the name beside it must
						// be there too. "hsd es368": ES368 already says what it is.
						$share = self::bare_numbers_only( $ids ) ? 1.0 : 0.5;
						return self::text_has_identifier( $h['heading_path'] . ' ' . $h['content'], $ids ) && TWTAEO_Content_Index::terms_share( $h, $subject ) >= $share;
					}
					return self::passage_answers( $h, $subject );
				}
			)
		);

		// The same passage twice (the same document uploaded twice, or a page
		// repeated in it) shows once.
		$seen = array();
		$hits = array_values(
			array_filter(
				$hits,
				static function ( $h ) use ( &$seen ) {
					$sig = md5( strtolower( (string) preg_replace( '/\s+/', ' ', substr( (string) $h['content'], 0, 400 ) ) ) );
					if ( isset( $seen[ $sig ] ) ) {
						return false;
					}
					$seen[ $sig ] = true;
					return true;
				}
			)
		);

		$profiles = $hits ? TWTAEO_Doc_Profile::profiles( wp_list_pluck( $hits, 'source_id' ) ) : array();

		// "Florida seller disclosure": a Georgia statute is the wrong answer,
		// however well its words match. Only documents labelled for another
		// state are dropped; unlabelled ones may still apply.
		$states = TWTAEO_Doc_Profile::states_in( $query );
		if ( $states && $hits ) {
			$hits = array_values(
				array_filter(
					$hits,
					static function ( $h ) use ( $states, $profiles ) {
						$doc_states = isset( $profiles[ $h['source_id'] ] ) ? TWTAEO_Doc_Profile::states_in( $profiles[ $h['source_id'] ]['jurisdiction'] ) : array();
						return ! $doc_states || array_intersect( $doc_states, $states );
					}
				)
			);
		}

		if ( $years && $hits ) {
			// "2024 Camry": the document's title (or its labelled edition)
			// usually carries the year, the passage rarely does. Drop passages
			// that belong to a different year; put those that name this year
			// first.
			$kept = array();
			foreach ( $hits as $h ) {
				$doc  = isset( $profiles[ $h['source_id'] ] ) ? $profiles[ $h['source_id'] ] : array( 'title' => '', 'edition' => '' );
				$seen = self::years_in( $doc['title'] . ' ' . $doc['edition'] . ' ' . $h['heading_path'] . ' ' . $h['content'] );
				if ( $seen && ! array_intersect( $seen, $years ) ) {
					continue;
				}
				$h['year_match'] = (bool) array_intersect( $seen, $years );
				$kept[]          = $h;
			}
			usort(
				$kept,
				static function ( $a, $b ) {
					return ( $b['year_match'] <=> $a['year_match'] ) ?: ( $b['score'] <=> $a['score'] );
				}
			);
			$hits = $kept;
		}

		return array_slice( $hits, 0, 2 );
	}

	/**
	 * A page for this search that is no longer published — in the Trash,
	 * back to draft, pending or private — when no published page carries the
	 * search in its title or web address. Its title or web address must hold
	 * every subject word ("VibroControl 6000" for "vibrocontrol 6000").
	 *
	 * @return array|null { post_id, status, title }
	 */
	private static function former_page( $query ) {
		$terms = self::subject_terms( $query );
		if ( ! $terms ) {
			return null;
		}
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		// "Precision Starts to Drift" holds both words of "precision spindle"
		// and is not a page for it. The words must sit together, in the
		// search's order, in the title or the web address ("VibroControl 6000
		// Vibration Monitor", /vibrocontrol-6000/); a model number alone
		// (ES368) is enough when the search names one.
		$ids     = self::identifiers( $query );
		$pattern = '/(?<![a-z0-9])' . implode( '[a-z0-9]*[\s\-_:,&\/]+(?:(?:and|of|for|the|a)[\s\-]+)?', array_map( static function ( $t ) { return implode( '[\s\-_]?', array_map( static function ( $part ) { return preg_quote( $part, '/' ); }, (array) preg_split( '/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', $t ) ) ); }, $terms ) ) . '/i';
		$names   = static function ( $post ) use ( $pattern, $ids ) {
			$slug = (string) preg_replace( '/__trashed(?:-\d+)?$/', '', (string) $post->post_name );
			foreach ( array( strtolower( wp_strip_all_tags( (string) $post->post_title ) ), str_replace( '-', ' ', $slug ) ) as $text ) {
				if ( preg_match( $pattern, $text ) ) {
					return true;
				}
			}
			return $ids && self::page_names_identifier( (int) $post->ID, $ids );
		};
		// The words as typed: "hcs 160", not the joined "hcs160" the matcher uses.
		$typed  = array_values( array_diff( preg_split( '/\s+/', strtolower( trim( (string) $query ) ), -1, PREG_SPLIT_NO_EMPTY ), array( 'new', 'used', 'best', 'buy', 'cheap' ) ) );
		$search = implode( ' ', array_slice( $typed, 0, 4 ) );

		// A published page already named for it: nothing to restore.
		foreach ( get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 's' => $search, 'posts_per_page' => 20, 'no_found_rows' => true ) ) as $post ) {
			if ( $names( $post ) ) {
				return null;
			}
		}

		$gone = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'trash', 'draft', 'pending', 'private' ),
				's'              => $search,
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		// WordPress search reads titles and content, not slugs: a page whose
		// title was changed but whose web address still names it is looked
		// up by slug too.
		$slug_like = sanitize_title( $query );
		if ( '' !== $slug_like ) {
			$gone = array_merge(
				$gone,
				get_posts(
					array(
						'post_type'      => $types,
						'post_status'    => array( 'trash', 'draft', 'pending', 'private' ),
						'name'           => $slug_like,
						'posts_per_page' => 5,
						'no_found_rows'  => true,
					)
				)
			);
		}
		foreach ( $gone as $post ) {
			if ( $names( $post ) ) {
				return array(
					'post_id' => (int) $post->ID,
					'status'  => (string) $post->post_status,
					'title'   => wp_strip_all_tags( (string) $post->post_title ),
				);
			}
		}

		return null;
	}

	/**
	 * The subject word that occurs in the fewest passages across the site's
	 * pages and documents: what a question is about, rather than what every
	 * page on the site mentions. Ties go to the longer word. '' when none of
	 * the words is four letters or more.
	 *
	 * @param string[] $terms From subject_terms().
	 * @return string
	 */
	private static function distinctive_term( array $terms ) {
		$best  = '';
		$count = PHP_INT_MAX;
		foreach ( $terms as $term ) {
			if ( strlen( $term ) < 4 || in_array( $term, self::INTENT_TERMS, true ) ) {
				continue;
			}
			$n = self::passages_with( $term );
			if ( $n < $count || ( $n === $count && strlen( $term ) > strlen( $best ) ) ) {
				$best  = $term;
				$count = $n;
			}
		}

		return $best;
	}

	/** How many indexed passages (pages and documents) contain a word. Cached per request. */
	private static function passages_with( $term ) {
		static $cache = array();
		if ( isset( $cache[ $term ] ) ) {
			return $cache[ $term ];
		}
		global $wpdb;
		$table = TWTAEO_Content_Index::chunks_table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours; counted once per word per request.
		$cache[ $term ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE content LIKE %s OR heading_path LIKE %s", $like, $like ) );

		return $cache[ $term ];
	}

	/** Whether a question points back at what came before ("does it", "their warranty"). */
	private static function refers_back( $query ) {
		return (bool) preg_match( '/\b(?:it|its|it\'s|this|that|these|those|they|them|their|the\s+same)\b/i', (string) $query );
	}

	/**
	 * What a follow-up asks about the business itself, or ''. "What's their
	 * turnaround?", "Do they offer rush service?", "What do reviews say about
	 * them?": no brochure answers those. Only the owner's own pages can.
	 *
	 * @param string $question The follow-up.
	 * @param bool   $after_company Whether it followed a question about the company.
	 * @return string Topic key, or '' when it is not about the business.
	 */
	public static function business_topic( $question, $after_company ) {
		$q      = strtolower( (string) $question );
		$topics = array(
			'turnaround' => '/\b(?:turnaround|lead\s*times?|how\s+long|how\s+quickly|how\s+fast|evaluation\s+process|teardown)\b/',
			'warranty'   => '/\b(?:warrant(?:y|ies)|guarantee\w*)\b/',
			'rush'       => '/\b(?:rush|emergency|24\/7|expedit\w*|urgent|on-?site|downtime)\b/',
			'shipping'   => '/\b(?:ship\w*|freight|pick\s*-?up|drop-?off|deliver\w*)\b/',
			'returns'    => '/\b(?:returns?|cancel\w*|restocking|refund\w*)\b/',
			'trust'      => '/\b(?:reviews?|feedback|complaints?|reputation|legitimate|verify|references?|safe|trust\w*|payment\s+protections?|vetting)\b/',
			'pricing'    => '/\b(?:cost\w*|price\w*|pricing|how\s+much|charge\w*|fees?)\b/',
			'brands'     => '/\b(?:brands?|types?\s+of|specialize\w*|kinds?\s+of|do\s+they\s+(?:repair|rebuild|service))\b/',
			'contact'    => '/\b(?:contact|phone|hours|located|location|address|quote|reach|request)\b/',
		);
		$topic = '';
		foreach ( $topics as $key => $pattern ) {
			if ( preg_match( $pattern, $q ) ) {
				$topic = $key;
				break;
			}
		}
		// Named outright, or — right after a question about the company — a
		// question about "them", or on one of these business topics ("Can I
		// pick up my order locally?", "how long does it take?").
		$about = self::names_business( $q )
			|| ( $after_company && ( '' !== $topic || preg_match( '/\b(?:they|them|their|theirs|you|your|this\s+(?:company|shop|business))\b/', $q ) ) );
		if ( ! $about ) {
			return '';
		}

		return '' !== $topic ? $topic : 'other';
	}

	/**
	 * The site's page for a business topic: a published page whose web
	 * address or title is about it (warranty, shipping, FAQ, about, contact,
	 * terms…). 0 when there is none. Cached per request.
	 *
	 * @param string $topic From business_topic().
	 * @return int Post ID.
	 */
	private static function topic_page( $topic ) {
		static $cache = array();
		if ( isset( $cache[ $topic ] ) ) {
			return $cache[ $topic ];
		}
		// Most specific first; FAQ and About pages catch most topics.
		$words = array(
			'turnaround' => array( 'turnaround', 'lead-time', 'process', 'how-it-works', 'faq' ),
			'warranty'   => array( 'warranty', 'guarantee', 'terms', 'faq' ),
			'rush'       => array( 'emergency', 'rush', 'expedite', 'on-site', 'faq' ),
			'shipping'   => array( 'shipping', 'ship', 'pickup', 'delivery', 'faq' ),
			'returns'    => array( 'return', 'refund', 'cancel', 'terms', 'faq' ),
			'trust'      => array( 'reviews', 'testimonials', 'case-stud', 'about' ),
			'pricing'    => array( 'pricing', 'price', 'cost', 'faq' ),
			'brands'     => array( 'brands', 'spindles-we-repair', 'what-we', 'services', 'about' ),
			'contact'    => array( 'contact', 'quote', 'location' ),
			'other'      => array( 'about', 'faq' ),
		);
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		$found = 0;
		foreach ( isset( $words[ $topic ] ) ? $words[ $topic ] : array() as $word ) {
			foreach ( $pages as $p ) {
				$slug  = (string) $p->post_name;
				$title = sanitize_title( (string) $p->post_title );
				if ( false !== strpos( $slug, $word ) || false !== strpos( $title, $word ) ) {
					$found = (int) $p->ID;
					break 2;
				}
			}
		}
		$cache[ $topic ] = $found;

		return $found;
	}

	/** Whether text names the business: its site name (without LLC, Inc…) or its Local Pack name. */
	private static function names_business( $q ) {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			$lp    = class_exists( 'TWTAEO_Local_Pack' ) ? (array) TWTAEO_Local_Pack::get_settings() : array();
			foreach ( array( get_bloginfo( 'name' ), isset( $lp['business_name'] ) ? $lp['business_name'] : '' ) as $n ) {
				$n = strtolower( trim( (string) preg_replace( '/[,\s]+(?:llc|inc|ltd|co|corp|corporation|company)\.?$/i', '', trim( (string) $n ) ) ) );
				if ( strlen( $n ) >= 5 && ! in_array( $n, $names, true ) ) {
					$names[] = $n;
				}
			}
		}
		foreach ( $names as $n ) {
			if ( false !== strpos( $q, $n ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a passage is a contact list rather than content: several phone
	 * numbers or email addresses making up much of it. A checklist with the
	 * shop's phone number at the top is still content.
	 */
	public static function is_contact_block( $text ) {
		$text = (string) $text;
		// The card shows a passage's opening: an office list there reads as
		// the answer however much text follows it.
		if ( strlen( $text ) > 700 && self::is_contact_block( substr( $text, 0, 600 ) ) ) {
			return true;
		}
		$emails = preg_match_all( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text );
		$phones = preg_match_all( '/(?:\+\d{1,3}[\s.\-]?)?\(?\d{2,4}\)?[\s.\-]\d{2,4}[\s.\-]\d{2,4}(?:[\s.\-]\d{2,4})?/', $text );
		$marks  = $emails + $phones;
		if ( $marks < 4 ) {
			return false;
		}
		if ( $marks >= 8 ) {
			return true; // Content does not carry eight phone numbers and emails.
		}
		// Words left once the contact details are gone: an office list is
		// little more than place names and company names between them.
		$rest  = preg_replace( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}|[+\d()\s.\-]{7,}/i', ' ', $text );
		$words = str_word_count( (string) $rest );

		return $words / $marks < 12;
	}

	/**
	 * Whether a passage is a table of contents ("VM Series 6 VMX Series 8
	 * VMXD Series 12 …"): it names every subject in the document and
	 * answers none of them.
	 */
	public static function is_contents_page( $text ) {
		$text    = (string) $text;
		$entries = preg_match_all( '/\b(?:series|chapter|section|part)\s+\d{1,3}\b/i', $text );
		if ( $entries >= 5 ) {
			return true;
		}
		// "CONTENTS … Accessories 45 Standard & Optional Features 48".
		if ( preg_match( '/\b(?:table\s+of\s+)?contents\b/i', $text ) ) {
			return preg_match_all( '/[A-Za-z)]\s+\d{1,3}(?=\s+[A-Z]|\s*$)/', $text ) >= 6;
		}

		return false;
	}

	/** Whether every identifier is a plain number (6000), none a model code (ES368). */
	private static function bare_numbers_only( array $ids ) {
		foreach ( $ids as $id ) {
			if ( ! ctype_digit( str_replace( '.', '', (string) $id ) ) ) {
				return false;
			}
		}

		return (bool) $ids;
	}

	/**
	 * How much of a query the page already answers, by its indexed passages.
	 *
	 * @return array { state: missing|partial|covered|unknown, share: float }
	 */
	private static function coverage( $query, $post_id ) {
		if ( ! $post_id ) {
			return array( 'state' => 'unknown', 'share' => 0.0 );
		}
		$hits  = TWTAEO_Content_Index::search( $query, TWTAEO_Content_Index::SOURCE_POST, 3, $post_id );
		$share = 0.0;
		foreach ( $hits as $hit ) {
			$share = max( $share, (float) $hit['matched'] );
		}

		$state = $share >= 1.0 ? 'covered' : ( $share >= 0.5 ? 'partial' : 'missing' );

		return array( 'state' => $state, 'share' => $share );
	}

	/** The newest finished, non-demo AI Visibility run: id, finished_at, or null. */
	private static function latest_run_row() {
		if ( ! class_exists( 'TWTAEO_Visibility_Store' ) || ! TWTAEO_Visibility_Store::tables_exist() ) {
			return null;
		}
		global $wpdb;
		$table = TWTAEO_Visibility_Store::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$checks = TWTAEO_Visibility_Store::checks_table();
		// Any real run with answers in it counts, whatever its status. A run
		// the daily cap paused, or one whose page was closed partway ("running"
		// until resumed), still holds real results — requiring "done" made a
		// site that had run checks read as one that never had.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are ours.
		$row = $wpdb->get_row( "SELECT r.id, r.started_at, r.finished_at, r.status, r.queue, (SELECT COUNT(*) FROM {$checks} c WHERE c.run_id = r.id) AS checked FROM {$table} r WHERE r.demo = 0 AND EXISTS (SELECT 1 FROM {$checks} c WHERE c.run_id = r.id) ORDER BY r.started_at DESC LIMIT 1", ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$queue          = json_decode( (string) $row['queue'], true );
		$row['total']   = is_array( $queue ) ? count( $queue ) : 0;
		$row['checked'] = (int) $row['checked'];
		$row['partial'] = ! in_array( $row['status'], array( 'done' ), true ) && $row['checked'] < $row['total'];
		unset( $row['queue'] );

		return $row;
	}

	private static function visibility_module_active() {
		if ( ! class_exists( 'TWTAEO_Module_Loader' ) || ! class_exists( 'TWTAEO_Visibility_Types' ) ) {
			return false;
		}
		$loader = new TWTAEO_Module_Loader();

		return $loader->is_active( TWTAEO_Visibility_Types::MODULE_SLUG );
	}
}
