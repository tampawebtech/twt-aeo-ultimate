<?php
/**
 * FAQ Detector
 *
 * Scans post content for FAQ blocks, accordion structures, and Q&A patterns.
 * Reports pages that have FAQ-style content but are missing FAQPage schema.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_FAQ_Detector {

	/**
	 * Minimum number of Q&A pairs to consider a page FAQ-eligible.
	 */
	const MIN_QA_PAIRS = 2;

	/**
	 * Postmeta key for storing the FAQ scan result.
	 */
	const META_FAQ_SCAN = '_twtaeo_faq_scan';

	/**
	 * Nonce action for the FAQ schema generator.
	 */
	const NONCE_GENERATE = 'twtaeo_faq_generate';

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Fires on save_post — rescans the post for FAQ content and schema.
	 * Clears any stale cached result so the next detector page load is fresh.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_post( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only scan pages and posts.
		if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		// Clear stale TWT AEO schema cache so has_faqpage_schema() runs fresh.
		delete_post_meta( $post_id, TWTAEO_Scan_Store::META_SCHEMA );

		// Keep a plugin-generated, untouched FAQ in step with the rewritten
		// page before scanning, so the cached result reflects the update.
		$content_only = self::scan( $post, false );
		self::sync_generated( $post, $content_only['has_faq_content'] );

		// Run a fresh scan and cache the result.
		$result = self::scan( $post );
		update_post_meta( $post_id, self::META_FAQ_SCAN, $result );
	}

	/**
	 * Scan a post for FAQ content.
	 *
	 * @param WP_Post|int $post
	 * @return array {
	 *   has_faq_content: bool,
	 *   method: string  what triggered detection,
	 *   qa_count: int   number of Q&A pairs found,
	 *   has_faq_schema: bool,
	 *   needs_schema: bool,
	 *   drift: array  see drift(); state 'none' without a stored FAQ,
	 *   out_of_date: bool  the stored FAQ no longer matches the page,
	 *   signals: string[]
	 * }
	 * @param bool $with_drift Compare a stored FAQ with the page (false inside drift() itself).
	 */
	public static function scan( $post, $with_drift = true ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			return self::empty_result();
		}

		$content        = $post->post_content ?? '';
		$signals        = array();
		$has_faq        = false;
		$method         = '';
		$qa_count       = 0;

		// Strategy 1: Gutenberg core/faq-block or rank-math-faq-block.
		if ( has_block( 'core/faq', $post ) || has_block( 'rank-math/faq-block', $post ) || has_block( 'yoast/faq-block', $post ) ) {
			$has_faq   = true;
			$method    = 'block';
			$signals[] = 'Gutenberg FAQ block detected';
		}

		// Strategy 2: Accordion block patterns (common page builders & themes).
		if ( ! $has_faq ) {
			$accordion_patterns = array(
				'/<!-- wp:(?:generateblocks\/|kadence\/|stackable\/|ultimate-blocks\/|otter-blocks\/)?(?:accordion|toggle|faq)[^>]*-->/i',
				'/class=["\'][^"\']*(?:accordion|faq-item|faq-block|qa-block|faq-section|toggle-item)[^"\']*["\']/',
			);
			foreach ( $accordion_patterns as $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					$has_faq   = true;
					$method    = 'accordion';
					$signals[] = 'Accordion / FAQ block markup detected';
					break;
				}
			}
		}

		// Strategy 3: Yoast FAQ block postmeta flag.
		if ( ! $has_faq && defined( 'WPSEO_VERSION' ) ) {
			// Check for Yoast FAQ block presence by scanning content.
			if ( strpos( $content, 'wp:yoast/faq-block' ) !== false ) {
				$has_faq   = true;
				$method    = 'yoast-faq-block';
				$signals[] = 'Yoast FAQ block found in content';
			}
		}

		// Strategy 4: Heading + paragraph Q&A pattern (h3/h4 as question, p as answer).
		if ( ! $has_faq ) {
			$qa_count = self::count_heading_qa_pairs( $content );
			if ( $qa_count >= self::MIN_QA_PAIRS ) {
				$has_faq   = true;
				$method    = 'heading-qa';
				$signals[] = "Found {$qa_count} heading-based Q&A pairs";
			}
		}

		// Strategy 5: Question keyword density in headings.
		if ( ! $has_faq ) {
			$question_headings = self::count_question_headings( $content );
			if ( $question_headings >= self::MIN_QA_PAIRS ) {
				$has_faq   = true;
				$method    = 'question-headings';
				$qa_count  = $question_headings;
				$signals[] = "Found {$question_headings} question-style headings (What/How/Why/Can/Is/Do/Does/Are)";
			}
		}

		// Strategy 6: Numbered Q&A paragraph pattern (Q1:, Q2:, Q: prefixes).
		if ( ! $has_faq ) {
			$numbered_qa = self::count_numbered_qa_paragraphs( $content );
			if ( $numbered_qa >= self::MIN_QA_PAIRS ) {
				$has_faq   = true;
				$method    = 'numbered-qa';
				$qa_count  = $numbered_qa;
				$signals[] = "Found {$numbered_qa} numbered Q&A paragraphs (Q1:, Q2:, Q: pattern)";
			}
		}

		// Check whether FAQPage schema already exists for this post.
		$has_faq_schema = self::has_faqpage_schema( $post );

		if ( $has_faq_schema ) {
			$signals[] = 'FAQPage schema already present';
		}

		$drift = $with_drift ? self::drift( $post, $has_faq ) : array( 'state' => 'none', 'origin' => '', 'added' => array(), 'removed' => array(), 'edited' => array() );

		return array(
			'has_faq_content' => $has_faq,
			'method'          => $method,
			'qa_count'        => $qa_count ?: ( $has_faq ? 1 : 0 ),
			'has_faq_schema'  => $has_faq_schema,
			'needs_schema'    => $has_faq && ! $has_faq_schema,
			'drift'           => $drift,
			'out_of_date'     => in_array( $drift['state'], array( 'changed', 'orphaned' ), true ),
			'signals'         => $signals,
		);
	}

	/**
	 * How many posts one scan block covers; the screen pages through blocks
	 * instead of stopping at the first one.
	 */
	const SCAN_BLOCK = 200;

	/**
	 * The post types this detector covers. Products carry FAQ content too —
	 * sizing questions, care instructions — and a product FAQ an answer engine
	 * can quote is a citation a bare spec sheet never earns.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array( 'page', 'post' );
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * How many published items the scan can see in total, regardless of blocks.
	 *
	 * @return int
	 */
	public static function total_items() {
		$total = 0;
		foreach ( self::post_types() as $type ) {
			$counts = wp_count_posts( $type );
			$total += (int) ( $counts->publish ?? 0 );
		}
		return $total;
	}

	/**
	 * Scan one block of published pages/posts (and products, when WooCommerce
	 * is active), newest first, and return those with FAQ content.
	 *
	 * @param int $block 1-based block number; block N covers items
	 *                   ((N-1)*SCAN_BLOCK)+1 through N*SCAN_BLOCK.
	 * @return array[] Each entry: { post, faq_data }
	 */
	public static function scan_all( $block = 1 ) {
		$posts = get_posts( array(
			'post_type'      => self::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => self::SCAN_BLOCK,
			'paged'          => max( 1, (int) $block ),
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		) );

		$results = array();

		foreach ( $posts as $post ) {
			$faq_data = self::scan( $post );
			// An orphaned FAQ (schema still published, FAQ section gone) has no
			// FAQ content left, and is exactly the row the owner must see.
			if ( $faq_data['has_faq_content'] || 'orphaned' === $faq_data['drift']['state'] ) {
				$results[] = array(
					'post'     => $post,
					'faq_data' => $faq_data,
				);
			}
		}

		return $results;
	}

	/**
	 * Get a summary count: pages with FAQ content, pages missing FAQPage schema.
	 *
	 * @param array[]|null $all A block already returned by scan_all(), so the
	 *                          caller does not pay for a second scan. Null scans
	 *                          the first block.
	 * @return array { total_with_faq, needs_schema, has_schema, out_of_date }
	 */
	public static function get_summary( $all = null ) {
		if ( null === $all ) {
			$all = self::scan_all();
		}
		$needs_schema = 0;
		$has_schema   = 0;
		$out_of_date  = 0;
		$with_faq     = 0;

		foreach ( $all as $item ) {
			if ( $item['faq_data']['has_faq_content'] ) {
				$with_faq++;
			}
			if ( ! empty( $item['faq_data']['out_of_date'] ) ) {
				$out_of_date++;
			} elseif ( $item['faq_data']['needs_schema'] ) {
				$needs_schema++;
			} else {
				$has_schema++;
			}
		}

		return array(
			'total_with_faq' => $with_faq,
			'needs_schema'   => $needs_schema,
			'has_schema'     => $has_schema,
			'out_of_date'    => $out_of_date,
		);
	}

	// ── Schema generation ────────────────────────────────────────────────────

	/**
	 * Extract Q&A pairs from a post using the same detection strategies as scan().
	 * Returns an array of { question, answer } associative arrays.
	 *
	 * @param WP_Post|int $post
	 * @return array[]
	 */
	public static function extract_qa_pairs( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}
		if ( ! $post ) {
			return array();
		}

		$content = $post->post_content ?? '';

		// Rank Math FAQ block — most structured, try first.
		if ( has_block( 'rank-math/faq-block', $post ) ) {
			$pairs = self::extract_from_rank_math_block( $content );
			if ( ! empty( $pairs ) ) {
				return $pairs;
			}
		}

		// Yoast FAQ block.
		if ( has_block( 'yoast/faq-block', $post ) || strpos( $content, 'wp:yoast/faq-block' ) !== false ) {
			$pairs = self::extract_from_yoast_block( $content );
			if ( ! empty( $pairs ) ) {
				return $pairs;
			}
		}

		// Heading Q&A pattern (h3/h4 + following paragraph).
		$pairs = self::extract_heading_qa_pairs( $content );
		if ( count( $pairs ) >= self::MIN_QA_PAIRS ) {
			return $pairs;
		}

		// Question-word headings with following paragraph.
		$pairs = self::extract_question_heading_pairs( $content );
		if ( count( $pairs ) >= self::MIN_QA_PAIRS ) {
			return $pairs;
		}

		// Numbered Q&A paragraphs (Q1:, Q2:, Q: prefix).
		return self::extract_numbered_qa_pairs( $content );
	}

	/**
	 * Build a FAQPage schema array from an array of Q&A pairs.
	 *
	 * @param int   $post_id
	 * @param array $qa_pairs  Each: { question, answer }
	 * @return array  Schema-ready associative array
	 */
	public static function build_faqpage_schema( $post_id, $qa_pairs ) {
		$entities = array();
		foreach ( $qa_pairs as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				),
			);
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post_id ) . '#faqpage',
			'mainEntity' => $entities,
		);
	}

	// ── Drift: stored FAQ schema vs. the page it was built from ─────────────

	/*
	 * FAQPage schema is saved once, as a copy of the Q&A on the page at the
	 * time. Rewrite the page and the copy keeps publishing the old questions
	 * and answers — or, when the FAQ section is gone, Q&A that is no longer on
	 * the page at all, which Google's FAQ rules forbid.
	 *
	 * Drift is judged by CONTENT, never by modified dates: a typo fix or a
	 * plugin re-saving the post moves the date without touching the FAQ.
	 *
	 * A fingerprint of every FAQ this plugin generates is kept beside it, so a
	 * stored FAQ can be told apart as:
	 *  - generated: still exactly what the plugin wrote — safe to rewrite from
	 *    the page automatically;
	 *  - edited:    changed by hand since — never overwritten without asking;
	 *  - unknown:   saved before fingerprints existed — flagged, not rewritten.
	 */

	/** Fingerprint of the Q&A this plugin last wrote into the stored FAQPage. */
	const META_FINGERPRINT = '_twtaeo_faq_fingerprint';

	/**
	 * Build FAQPage schema from Q&A pairs, save it, and remember it as ours.
	 * Every path that generates FAQ schema from page content goes through here.
	 *
	 * @param int   $post_id
	 * @param array $qa_pairs Each: { question, answer }
	 * @return true|WP_Error
	 */
	public static function save_generated( $post_id, array $qa_pairs ) {
		$schema = self::build_faqpage_schema( $post_id, $qa_pairs );
		$saved  = TWTAEO_Custom_Schema_Writer::save( $post_id, 'FAQPage', wp_json_encode( $schema ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::remember_generated( $post_id, $schema );
		return true;
	}

	/**
	 * Record that the stored FAQPage for a post is exactly what the plugin wrote.
	 *
	 * @param int   $post_id
	 * @param array $schema The FAQPage node that was saved.
	 */
	public static function remember_generated( $post_id, array $schema ) {
		update_post_meta( $post_id, self::META_FINGERPRINT, self::fingerprint( self::schema_pairs( $schema ) ) );
	}

	/**
	 * Compare the stored FAQPage with the Q&A on the page now.
	 *
	 * @param WP_Post|int $post
	 * @param bool|null   $has_faq_content Pass scan()'s answer to skip re-detecting.
	 * @return array {
	 *   state:   none | in_sync | changed | orphaned | unverifiable,
	 *   origin:  generated | edited | unknown | '',
	 *   added:   string[] questions on the page, not in the schema,
	 *   removed: string[] questions in the schema, not on the page,
	 *   edited:  string[] questions whose answer changed,
	 * }
	 */
	public static function drift( $post, $has_faq_content = null ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}
		$result = array(
			'state'   => 'none',
			'origin'  => '',
			'added'   => array(),
			'removed' => array(),
			'edited'  => array(),
		);
		// Product FAQs are managed as their own list (Product FAQ generator),
		// not extracted from the page — nothing here to compare against.
		if ( ! $post || 'product' === $post->post_type || ! class_exists( 'TWTAEO_Custom_Schema_Writer' ) ) {
			return $result;
		}
		$stored = TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, 'FAQPage' );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return $result;
		}

		$stored_pairs = self::schema_pairs( $stored );
		$fingerprint  = (string) get_post_meta( $post->ID, self::META_FINGERPRINT, true );
		if ( '' === $fingerprint ) {
			$result['origin'] = 'unknown';
		} else {
			$result['origin'] = hash_equals( $fingerprint, self::fingerprint( $stored_pairs ) ) ? 'generated' : 'edited';
		}

		$page_pairs = self::extract_qa_pairs( $post );
		if ( empty( $page_pairs ) ) {
			if ( null === $has_faq_content ) {
				$has_faq_content = self::scan_content_only( $post );
			}
			// FAQ-looking content we cannot read Q&A out of (accordions and the
			// like) cannot be compared; no FAQ content at all means the schema
			// now describes Q&A the page no longer shows.
			$result['state'] = $has_faq_content ? 'unverifiable' : 'orphaned';
			return $result;
		}

		$now    = self::keyed_pairs( $page_pairs );
		$before = self::keyed_pairs( $stored_pairs );
		foreach ( $now as $key => $pair ) {
			if ( ! isset( $before[ $key ] ) ) {
				$result['added'][] = $pair['question'];
			} elseif ( $pair['answer_key'] !== $before[ $key ]['answer_key'] ) {
				$result['edited'][] = $pair['question'];
			}
		}
		foreach ( $before as $key => $pair ) {
			if ( ! isset( $now[ $key ] ) ) {
				$result['removed'][] = $pair['question'];
			}
		}

		$result['state'] = ( $result['added'] || $result['removed'] || $result['edited'] ) ? 'changed' : 'in_sync';

		// A stored FAQ that matches the page exactly is, in effect, what the
		// plugin would write: adopt it, so later edits to the page keep it in
		// step automatically. Hand-edited ones stay hand-edited.
		if ( 'in_sync' === $result['state'] && 'unknown' === $result['origin'] ) {
			update_post_meta( $post->ID, self::META_FINGERPRINT, self::fingerprint( $stored_pairs ) );
			$result['origin'] = 'generated';
		}

		return $result;
	}

	/**
	 * On save: bring a plugin-generated, untouched FAQ back in step with the
	 * page. Hand-edited and pre-fingerprint FAQs are only flagged, and an FAQ
	 * whose page lost its FAQ section is never removed without the owner.
	 *
	 * @param WP_Post $post
	 * @param bool    $has_faq_content
	 * @return array The drift result after any update.
	 */
	public static function sync_generated( $post, $has_faq_content ) {
		$drift = self::drift( $post, $has_faq_content );
		if ( 'changed' === $drift['state'] && 'generated' === $drift['origin'] ) {
			$pairs = self::extract_qa_pairs( $post );
			if ( $pairs && true === self::save_generated( $post->ID, $pairs ) ) {
				$drift = self::drift( $post, $has_faq_content );
			}
		}
		return $drift;
	}

	/** Q&A pairs out of a stored FAQPage node (mainEntity as a list or a single object). */
	private static function schema_pairs( array $schema ) {
		$entities = isset( $schema['mainEntity'] ) ? $schema['mainEntity'] : array();
		if ( isset( $entities['@type'] ) ) {
			$entities = array( $entities );
		}
		$pairs = array();
		foreach ( (array) $entities as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$answer = isset( $e['acceptedAnswer']['text'] ) ? $e['acceptedAnswer']['text'] : '';
			$pairs[] = array(
				'question' => isset( $e['name'] ) ? (string) $e['name'] : '',
				'answer'   => is_string( $answer ) ? $answer : '',
			);
		}
		return $pairs;
	}

	/** Text reduced to what a reader sees: no tags, entities decoded, one-spaced, lower-case. */
	private static function norm( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/** Pairs keyed by normalized question, each carrying its normalized answer. */
	private static function keyed_pairs( array $pairs ) {
		$out = array();
		foreach ( $pairs as $p ) {
			$key = self::norm( isset( $p['question'] ) ? $p['question'] : '' );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = array(
				'question'   => trim( wp_strip_all_tags( (string) $p['question'] ) ),
				'answer_key' => self::norm( isset( $p['answer'] ) ? $p['answer'] : '' ),
			);
		}
		return $out;
	}

	private static function fingerprint( array $pairs ) {
		$keyed = array();
		foreach ( $pairs as $p ) {
			$keyed[] = self::norm( isset( $p['question'] ) ? $p['question'] : '' ) . "\n" . self::norm( isset( $p['answer'] ) ? $p['answer'] : '' );
		}
		return md5( implode( "\n\n", $keyed ) );
	}

	/** Whether the page shows FAQ-style content, without the schema checks scan() adds. */
	private static function scan_content_only( $post ) {
		$result = self::scan( $post, false );
		return ! empty( $result['has_faq_content'] );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Whether a heading actually reads as a question.
	 *
	 * Heading-based detection used to accept any h3/h4 followed by a paragraph,
	 * which swept feature-card titles, step headings, and section labels into
	 * FAQPage schema as fake questions ("Suppresses duplicates", "🕸️ Connected
	 * Product Graph"). A published Q&A set full of non-questions misinforms
	 * every answer engine that reads it — worse than no FAQ schema at all.
	 *
	 * The rule is deliberately strict: the text must end with a question mark
	 * and be question-length. A section heading that merely starts with "How"
	 * ("How It Works") is a title, not a question, and stays out.
	 *
	 * @param string $text Plain heading text (tags already stripped).
	 * @return bool
	 */
	private static function looks_like_question( $text ) {
		$text = trim( (string) $text );
		// 200 bytes ≈ a long single-sentence question; anything bigger is a
		// swallowed content block, not a question.
		if ( '' === $text || strlen( $text ) > 200 ) {
			return false;
		}
		return substr( $text, -1 ) === '?';
	}

	private static function extract_from_rank_math_block( $content ) {
		preg_match_all( '/<!-- wp:rank-math\/faq-block\s+({[^}]*(?:{[^}]*}[^}]*)*})\s*-->/s', $content, $matches );
		$pairs = array();
		foreach ( $matches[1] as $json_str ) {
			$data = json_decode( $json_str, true );
			if ( is_array( $data ) && ! empty( $data['faqList'] ) ) {
				foreach ( $data['faqList'] as $item ) {
					$q = trim( wp_strip_all_tags( $item['question'] ?? '' ) );
					$a = trim( wp_strip_all_tags( $item['answer'] ?? '' ) );
					if ( $q && $a ) {
						$pairs[] = array( 'question' => $q, 'answer' => $a );
					}
				}
			}
		}
		return $pairs;
	}

	private static function extract_from_yoast_block( $content ) {
		preg_match_all( '/<!-- wp:yoast\/faq-block\s+({[^}]*(?:{[^}]*}[^}]*)*})\s*-->/s', $content, $matches );
		$pairs = array();
		foreach ( $matches[1] as $json_str ) {
			$data = json_decode( $json_str, true );
			if ( is_array( $data ) && ! empty( $data['questions'] ) ) {
				foreach ( $data['questions'] as $item ) {
					$q = trim( wp_strip_all_tags( $item['jsonQuestion'] ?? '' ) );
					$a = trim( wp_strip_all_tags( $item['jsonAnswer'] ?? '' ) );
					if ( $q && $a ) {
						$pairs[] = array( 'question' => $q, 'answer' => $a );
					}
				}
			}
		}
		return $pairs;
	}

	private static function extract_heading_qa_pairs( $content ) {
		$pattern = '/<h[34][^>]*>(.*?)<\/h[34]>\s*(?:<[^>]+>\s*)*<p[^>]*>(.*?)<\/p>/is';
		preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );
		$pairs = array();
		foreach ( $matches as $match ) {
			$q = trim( wp_strip_all_tags( $match[1] ) );
			$a = trim( wp_strip_all_tags( $match[2] ) );
			if ( self::looks_like_question( $q ) && strlen( $a ) >= 20 ) {
				$pairs[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return $pairs;
	}

	private static function extract_question_heading_pairs( $content ) {
		$question_words = array( 'what', 'how', 'why', 'when', 'where', 'who', 'which', 'can', 'is', 'are', 'do', 'does', 'will', 'should', 'could', 'would' );
		preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $content, $headings, PREG_OFFSET_CAPTURE );

		$pairs = array();
		foreach ( $headings[0] as $i => $heading_match ) {
			$q = trim( wp_strip_all_tags( $headings[1][ $i ][0] ) );
			$first_word = strtolower( strtok( $q, ' ' ) );
			if ( ! in_array( $first_word, $question_words, true ) || ! self::looks_like_question( $q ) ) {
				continue;
			}
			$after = substr( $content, $heading_match[1] + strlen( $heading_match[0] ) );
			if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $after, $p_match ) ) {
				$a = trim( wp_strip_all_tags( $p_match[1] ) );
				if ( strlen( $a ) >= 20 ) {
					$pairs[] = array( 'question' => $q, 'answer' => $a );
				}
			}
		}
		return $pairs;
	}

	private static function extract_numbered_qa_pairs( $content ) {
		// Match <p>Q1: question</p> followed by <p>answer or A1: answer</p>.
		preg_match_all(
			'/<p[^>]*>(?:<strong>|<b>)?\s*Q\d*\s*:\s*(.*?)(?:<\/strong>|<\/b>)?<\/p>\s*<p[^>]*>(?:<strong>|<b>)?\s*(?:A\d*\s*:)?\s*(.*?)(?:<\/strong>|<\/b>)?<\/p>/is',
			$content,
			$matches,
			PREG_SET_ORDER
		);
		$pairs = array();
		foreach ( $matches as $match ) {
			$q = trim( wp_strip_all_tags( $match[1] ) );
			$a = trim( wp_strip_all_tags( $match[2] ) );
			if ( $q && strlen( $a ) >= 10 ) {
				$pairs[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return $pairs;
	}

	/**
	 * Count heading (h3/h4) followed by paragraph pairs — classic Q&A format.
	 *
	 * @param string $content Raw post content HTML.
	 * @return int
	 */
	private static function count_heading_qa_pairs( $content ) {
		// Find h3 or h4 followed by a p tag within reasonable proximity — but
		// only count headings that actually read as questions, matching what
		// extract_heading_qa_pairs() would publish.
		$pattern = '/<h[34][^>]*>([^<]+)<\/h[34]>\s*(?:<[^>]+>\s*)*<p[^>]*>[^<]{20,}<\/p>/i';
		preg_match_all( $pattern, $content, $matches );
		$count = 0;
		foreach ( $matches[1] as $heading ) {
			if ( self::looks_like_question( trim( wp_strip_all_tags( $heading ) ) ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Count headings that start with common question words.
	 *
	 * @param string $content
	 * @return int
	 */
	private static function count_question_headings( $content ) {
		preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return 0;
		}

		$question_words = array( 'what', 'how', 'why', 'when', 'where', 'who', 'which', 'can', 'is', 'are', 'do', 'does', 'will', 'should', 'could', 'would' );
		$count          = 0;

		foreach ( $matches[1] as $heading ) {
			$text  = strtolower( trim( wp_strip_all_tags( $heading ) ) );
			$first = strtok( $text, ' ' );
			if ( in_array( $first, $question_words, true ) && self::looks_like_question( $text ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count numbered Q&A paragraphs — Q1:, Q2:, Q3: or plain Q: prefix pattern.
	 * Catches FAQ content written as bold or plain paragraph text rather than headings.
	 *
	 * @param string $content Raw post content HTML.
	 * @return int
	 */
	private static function count_numbered_qa_paragraphs( $content ) {
		// Match <p> tags containing Q1:, Q2:, Q: etc. with optional bold/strong wrap.
		$pattern = '/<p[^>]*>(?:<strong>|<b>)?\s*Q\d*\s*:/i';
		preg_match_all( $pattern, $content, $matches );
		return count( $matches[0] );
	}

	/**
	 * Check whether the post already has FAQPage schema.
	 *
	 * Checks in order:
	 *   1. Stored scan postmeta — only used for positive confirmation.
	 *      Never returns false; always falls through to live checks so that
	 *      schema from plugins like SASWP (stored separately) is never missed.
	 *   2. Rank Math schema postmeta.
	 *   3. Inline JSON-LD in post_content.
	 *   4. Schema & Structured Data For WP (SASWP) custom schema field.
	 *   5. Broad SASWP catch-all across all saswp_ postmeta keys.
	 *
	 * @param WP_Post $post
	 * @return bool
	 */
	private static function has_faqpage_schema( $post ) {

		// Strategy 1: Check stored scan data for positive confirmation only.
		// If FAQPage is confirmed in a previous scan, return true immediately.
		// Never return false here — the stored scan only captures what the main
		// schema detector finds (Rank Math, Yoast, HTTP). It has no knowledge of
		// schema added by other plugins like SASWP that store data separately.
		// Always fall through to live checks so those sources are never missed.
		$stored_schema = get_post_meta( $post->ID, TWTAEO_Scan_Store::META_SCHEMA, true );
		if ( is_array( $stored_schema ) ) {
			$types = $stored_schema['schema_types'] ?? array();
			if ( in_array( 'FAQPage', $types, true ) ) {
				return true;
			}
			// Fall through — stored scan may not include all schema sources.
		}

		// Strategy 1b: TWT AEO custom schema — this is where our own "Add FAQ
		// Schema" action stores the FAQPage it generates. Without this check the
		// detector keeps reporting "Missing" even right after we created it.
		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' ) ) {
			$custom = TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, 'FAQPage' );
			if ( ! empty( $custom ) ) {
				return true;
			}
		}

		// Strategy 1c: the Product FAQ writer. On product pages every FAQ source
		// is assembled into one FAQPage node at #product-faq — but only when a
		// commerce writer is actually publishing (our woocommerce-detector module,
		// or AEO Ultimate for WooCommerce claiming the surface). With no writer
		// active the pairs exist but no schema reaches the page, and reporting
		// "present" then would hide a real gap.
		if ( 'product' === $post->post_type && class_exists( 'TWTAEO_Product_FAQ' ) ) {
			$modules = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->get_modules() : null;
			$emits   = ( $modules && $modules->is_active( 'woocommerce-detector' ) )
				|| function_exists( 'aeowc_claims_surface' );
			if ( $emits && ! empty( TWTAEO_Product_FAQ::collect( $post->ID ) ) ) {
				return true;
			}
		}

		// Strategy 2: Check Rank Math schema postmeta.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$meta = get_post_meta( $post->ID );
			foreach ( $meta as $key => $values ) {
				if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
					foreach ( $values as $value ) {
						$data = maybe_unserialize( $value );
						if ( is_array( $data ) && isset( $data['@type'] ) ) {
							$types = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
							if ( in_array( 'FAQPage', $types, true ) ) {
								return true;
							}
						}
					}
				}
			}
		}

		// Strategy 3: Check post_content for inline JSON-LD.
		if ( strpos( $post->post_content, '"FAQPage"' ) !== false ) {
			return true;
		}

		// Strategy 4: Schema & Structured Data For WP (SASWP) custom schema field.
		// SASWP stores its custom schema JSON directly in saswp_custom_schema_field.
		$saswp_schema = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
		if ( ! empty( $saswp_schema ) ) {
			$data = json_decode( $saswp_schema, true );
			if ( is_array( $data ) ) {
				// Handle both single schema objects and @graph arrays.
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					$types = isset( $item['@type'] )
						? ( is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] ) )
						: array();
					if ( in_array( 'FAQPage', $types, true ) ) {
						return true;
					}
				}
			}
		}

		// Strategy 5: Broad SASWP catch-all.
		// Covers any other SASWP postmeta keys such as saswp_schema_faq_field.
		// (array) guard: get_post_meta() returns false, not [], for an invalid post.
		$all_meta = (array) get_post_meta( $post->ID );
		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, 'saswp_' ) === 0 ) {
				foreach ( $values as $value ) {
					if ( is_string( $value ) && strpos( $value, '"FAQPage"' ) !== false ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Return an empty/default scan result.
	 */
	private static function empty_result() {
		return array(
			'has_faq_content' => false,
			'method'          => '',
			'qa_count'        => 0,
			'has_faq_schema'  => false,
			'needs_schema'    => false,
			'signals'         => array(),
		);
	}
}