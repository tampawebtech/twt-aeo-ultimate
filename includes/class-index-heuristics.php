<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Local PHP heuristic scan for de-indexed pages.
 * No AI API required — runs entirely on-server.
 */
class TWTAEO_Index_Heuristics {

	const MIN_WORD_COUNT      = 400;
	const MAX_KEYWORD_DENSITY = 3.0;

	private static $stop_words = array(
		'the','a','an','and','or','but','in','on','at','to','for','of','with',
		'by','from','up','about','into','through','during','is','are','was',
		'were','be','been','being','have','has','had','do','does','did','will',
		'would','could','should','may','might','it','its','this','that','these',
		'those','i','you','he','she','we','they','me','him','her','us','them',
		'my','your','his','our','their','what','which','who','when','where',
		'why','how','all','each','every','both','few','more','most','other',
		'some','such','no','not','only','same','so','than','too','very','as',
		'if','then','because','while','although','though','can','also','just',
		'get','got','use','used','make','made','one','two','new','also','more',
	);

	/**
	 * Run all heuristic checks for a post ID.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function analyze( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return null;

		// Core the_content filter, applied via a variable hook name (core's, not ours).
		$core_filter   = 'the_content';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hook, not ours to prefix.
		$content_html  = apply_filters( $core_filter, $post->post_content );
		$content_plain = wp_strip_all_tags( $content_html );

		$flags = array();
		$data  = array();

		// ── Word Count ──────────────────────────────────────────────────────
		$word_count         = str_word_count( $content_plain );
		$data['word_count'] = $word_count;

		if ( $word_count < self::MIN_WORD_COUNT ) {
			$flags[] = array(
				'type'     => 'word_count',
				'label'    => 'Low Word Count',
				'detail'   => sprintf(
					'%d words — Google often skips thin content. Aim for %d+ words with genuine depth.',
					$word_count,
					self::MIN_WORD_COUNT
				),
				'severity' => 'warning',
			);
		}

		// ── Keyword Density ─────────────────────────────────────────────────
		$density         = self::keyword_density( $content_plain );
		$data['density'] = $density;

		if ( $density['density'] > self::MAX_KEYWORD_DENSITY ) {
			$flags[] = array(
				'type'     => 'keyword_density',
				'label'    => 'High Keyword Density',
				'detail'   => sprintf(
					'"%s" appears %.1f%% (%d of %d words) — over-optimisation can suppress indexing. Max %.1f%% recommended.',
					$density['keyword'],
					$density['density'],
					$density['count'],
					$density['total'],
					self::MAX_KEYWORD_DENSITY
				),
				'severity' => 'warning',
			);
		}

		// ── Technical Health ────────────────────────────────────────────────
		$tech         = self::technical_health( $post, $content_html );
		$data['tech'] = $tech;
		foreach ( $tech['flags'] as $flag ) {
			$flags[] = $flag;
		}

		return array(
			'post_id' => $post_id,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'flags'   => $flags,
			'data'    => $data,
			'clean'   => empty( $flags ),
			'excerpt' => wp_trim_words( $content_plain, 60 ),
		);
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	private static function keyword_density( $text ) {
		$words = preg_split( '/\s+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$total = count( $words );

		if ( $total === 0 ) {
			return array( 'keyword' => '', 'count' => 0, 'total' => 0, 'density' => 0.0 );
		}

		$freq = array();
		foreach ( $words as $word ) {
			$clean = preg_replace( '/[^a-z]/', '', $word );
			if ( strlen( $clean ) < 3 || in_array( $clean, self::$stop_words, true ) ) {
				continue;
			}
			$freq[ $clean ] = ( $freq[ $clean ] ?? 0 ) + 1;
		}

		if ( empty( $freq ) ) {
			return array( 'keyword' => '', 'count' => 0, 'total' => $total, 'density' => 0.0 );
		}

		arsort( $freq );
		$top   = array_key_first( $freq );
		$count = $freq[ $top ];

		return array(
			'keyword' => $top,
			'count'   => $count,
			'total'   => $total,
			'density' => round( ( $count / $total ) * 100, 2 ),
		);
	}

	private static function technical_health( $post, $content_html ) {
		$flags = array();

		// ── Indexability blockers — the real reasons a page won't get indexed ──
		// noindex robots directive set in any common SEO plugin.
		if ( self::is_noindexed( $post->ID ) ) {
			$flags[] = array(
				'type'     => 'noindex',
				'label'    => 'Set to noindex',
				'detail'   => 'This page is marked "noindex", so search engines are told not to index it — this alone will keep it out of Google. Open the page in your SEO plugin\'s Advanced/Robots settings and switch it back to "index".',
				'severity' => 'critical',
			);
		}

		// Canonical URL pointing somewhere else — the page is consolidated away.
		$canonical = self::get_canonical( $post->ID );
		if ( $canonical ) {
			$permalink = get_permalink( $post );
			if ( $permalink && ! self::same_url( $canonical, $permalink ) ) {
				$flags[] = array(
					'type'     => 'canonical_mismatch',
					'label'    => 'Canonical points elsewhere',
					'detail'   => sprintf(
						'The canonical URL is set to %s, so Google treats that page as the original and usually will not index this one. Clear the canonical override unless this duplication is intentional.',
						$canonical
					),
					'severity' => 'warning',
				);
			}
		}

		// Heading hierarchy — WordPress themes render the post title as the page's
		// H1 (outside post_content), so an in-content H1 isn't needed. What matters
		// for readers and AI/search parsing is section structure: H2/H3 subheadings.
		// Flag when the content has no subheadings at all.
		$has_headings = (bool) preg_match( '/<h[2-6][\s>]/i', $content_html );
		if ( ! $has_headings ) {
			$flags[] = array(
				'type'     => 'no_heading_hierarchy',
				'label'    => 'No Heading Hierarchy',
				'detail'   => 'This content has no H2/H3 subheadings, so it reads as one undifferentiated block. Break it into sections with H2 (and H3 where needed) — clear hierarchy helps readers scan and helps search engines and AI assistants understand the page structure.',
				'severity' => 'warning',
			);
		}

		// Meta description — check the plugin's own field and common SEO plugins.
		$meta = get_post_meta( $post->ID, '_twtaeo_meta_description', true )
			?: get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			?: get_post_meta( $post->ID, 'rank_math_description', true )
			?: get_post_meta( $post->ID, '_aioseop_description', true )
			?: get_post_meta( $post->ID, 'seopress_titles_desc', true )
			?: '';

		if ( empty( $meta ) ) {
			$flags[] = array(
				'type'     => 'missing_meta_desc',
				'label'    => 'Missing Meta Description',
				'detail'   => 'No meta description found in Yoast, Rank Math, AIOSEO, or SEOPress. Add one to improve CTR and give Google context.',
				'severity' => 'warning',
			);
		}

		// Alt tags — scan img elements in content.
		preg_match_all( '/<img[^>]+>/i', $content_html, $img_matches );
		$missing_alt = 0;
		foreach ( $img_matches[0] as $img_tag ) {
			// Flag if alt attribute is absent or empty.
			if ( ! preg_match( '/\balt=["\'][^"\']+["\']/i', $img_tag ) ) {
				$missing_alt++;
			}
		}
		if ( $missing_alt > 0 ) {
			$flags[] = array(
				'type'     => 'missing_alt',
				'label'    => 'Images Missing Alt Text',
				'detail'   => sprintf(
					'%d image(s) in content are missing descriptive alt text. Alt text helps Google understand image context.',
					$missing_alt
				),
				'severity' => 'warning',
			);
		}

		// ── AEO opportunities (fixable inline from the Index Status modal) ─────
		// Open Graph — flag when title/description/image aren't all set. The 'fix'
		// key tells the UI which inline editor to open.
		if ( class_exists( 'TWTAEO_OG_Detector' ) ) {
			$og = TWTAEO_OG_Detector::scan( $post );
			if ( ! empty( $og['missing'] ) ) {
				$flags[] = array(
					'type'     => 'missing_og',
					'label'    => 'Open Graph Incomplete',
					'detail'   => sprintf(
						'Missing %s — social shares and AI link previews will look bare. Fix it here to add a title, description, and image.',
						implode( ', ', $og['missing'] )
					),
					'severity' => 'warning',
					'fix'      => 'og',
					'og'       => array(
						'title'       => $og['og_title'] ?? '',
						'description' => $og['og_description'] ?? '',
						'image'       => $og['og_image'] ?? '',
						'type'        => $og['og_type'] ?? 'website',
					),
				);
			}
		}

		// FAQ — Q&A-style content that has no FAQPage schema yet.
		if ( class_exists( 'TWTAEO_FAQ_Detector' ) ) {
			$faq = TWTAEO_FAQ_Detector::scan( $post );
			if ( ! empty( $faq['needs_schema'] ) ) {
				$flags[] = array(
					'type'     => 'faq_opportunity',
					'label'    => 'FAQ Schema Opportunity',
					'detail'   => 'This page has Q&A-style content but no FAQ schema. Generate FAQPage schema to qualify for rich results and AI answers.',
					'severity' => 'notice',
					'fix'      => 'faq',
				);
			}
		}

		return array(
			'flags'        => $flags,
			'has_headings' => $has_headings,
			'meta_desc'    => $meta,
			'missing_alt'  => $missing_alt,
		);
	}

	/**
	 * Whether a post is set to noindex in any common SEO plugin.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function is_noindexed( $post_id ) {
		// Yoast — '1' means noindex ('2' = index, '0'/empty = default).
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		// Rank Math — array of robots directives.
		$rm = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
			return true;
		}

		// SEOPress — 'yes' means "set to noindex".
		if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}

		// All in One SEO (legacy v3 meta) — 'on' means noindex.
		if ( 'on' === (string) get_post_meta( $post_id, '_aioseop_noindex', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get a custom canonical URL set in any common SEO plugin, if any.
	 *
	 * @param int $post_id
	 * @return string Empty string when none is set.
	 */
	private static function get_canonical( $post_id ) {
		$keys = array(
			'_yoast_wpseo_canonical',
			'rank_math_canonical_url',
			'_seopress_robots_canonical',
		);

		foreach ( $keys as $key ) {
			$val = get_post_meta( $post_id, $key, true );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		return '';
	}

	/**
	 * Compare two URLs ignoring scheme and trailing slash.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	private static function same_url( $a, $b ) {
		$normalize = static function ( $url ) {
			$url = strtolower( trim( $url ) );
			$url = preg_replace( '#^https?://#', '', $url );
			return untrailingslashit( $url );
		};

		return $normalize( $a ) === $normalize( $b );
	}
}
