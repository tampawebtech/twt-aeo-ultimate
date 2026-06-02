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

		$content_html  = apply_filters( 'the_content', $post->post_content );
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

		// H1 — check for explicit <h1> in content HTML.
		// Note: most themes render post_title as H1 in the template, outside post_content.
		// Flag only when the content block itself contains no H1.
		if ( ! preg_match( '/<h1[\s>]/i', $content_html ) ) {
			$flags[] = array(
				'type'     => 'missing_h1',
				'label'    => 'Missing H1 in Content',
				'detail'   => 'No H1 found in post content. Confirm your theme wraps the post title in an H1 — if not, add one manually.',
				'severity' => 'warning',
			);
		}

		// Meta description — check common SEO plugins.
		$meta = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
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

		return array(
			'flags'       => $flags,
			'has_h1'      => ! (bool) preg_match( '/<h1[\s>]/i', $content_html ),
			'meta_desc'   => $meta,
			'missing_alt' => $missing_alt,
		);
	}
}
