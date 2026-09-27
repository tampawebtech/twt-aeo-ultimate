<?php
/**
 * Chunker — split content the way a retrieval index would, then judge whether
 * each piece can survive on its own.
 *
 * AI engines do not retrieve pages. They retrieve chunks. A page ranks; a
 * passage gets cited. Everything in SEO tooling is built around the page as the
 * unit, which means nobody is looking at the thing citation actually operates
 * on — so this class exists to make that unit visible.
 *
 * Splitting is recursive and structural, never a blind character count: heading
 * boundaries first, then paragraphs, then sentences, and only inside an
 * abnormally long sentence does it fall back to words. Cutting a torque spec or
 * a diagnostic step in half is the failure mode that matters, and a fixed
 * window guarantees it.
 *
 * Self-sufficiency is the other half. A chunk that reads "it requires 15Nm of
 * torque" is not retrievable no matter how relevant it is, because nothing in
 * it says what "it" is. Those failures are mechanical, deterministic, and
 * therefore measurable without an API call — which is what lets the whole of
 * Chunk View run free, offline, with no key configured.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Chunker {

	/** Target chunk size in tokens. Small enough to isolate one fact. */
	const TARGET_TOKENS = 450;

	/** Hard ceiling before a chunk is split again regardless of structure. */
	const MAX_TOKENS = 600;

	/** Below this a fragment is merged forward rather than left orphaned. */
	const MIN_TOKENS = 80;

	/** Overlap carried between adjacent chunks, as a fraction of TARGET_TOKENS. */
	const OVERLAP_FRACTION = 0.12;

	/** Rough characters-per-token for English prose. Good enough for sizing. */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Openers that cannot resolve without the preceding chunk. A chunk starting
	 * with one of these is dependent on context it will not be retrieved with.
	 *
	 * Deliberately excludes first and second person. "We rebuild HSD spindles"
	 * and "You should torque to 15Nm" are fully self-contained — "we" is the
	 * site and "you" is the reader, and both resolve from the document the
	 * passage was retrieved from. Flagging them would fire on the opening line
	 * of nearly every service page and teach users to ignore the warnings.
	 */
	const DANGLING_OPENERS = array(
		'it', 'this', 'that', 'these', 'those', 'they', 'them', 'their', 'its',
		'he', 'she', 'his', 'her', 'him',
		'such', 'both', 'either', 'neither', 'the former', 'the latter',
	);

	/** Phrases that explicitly point outside the chunk. */
	const BACKREFERENCES = array(
		'as mentioned above', 'as noted above', 'as described above', 'as stated above',
		'as discussed earlier', 'as shown above', 'see above', 'the above',
		'as mentioned previously', 'previously mentioned', 'in the previous section',
		'as we saw', 'as explained earlier', 'refer to the', 'see below', 'as follows',
		'the following', 'listed below', 'shown below', 'in this section',
	);

	private function __construct() {}

	/* ─────────────────────────── entry point ─────────────────────────── */

	/**
	 * Split Markdown into retrieval-shaped chunks.
	 *
	 * @param string $markdown Clean Markdown, typically from TWTAEO_HTML_To_Markdown.
	 * @param array  $args     Optional overrides: target, max, min, overlap.
	 * @return array[] Chunk arrays: index, content, heading_path, tokens, chars, score, issues.
	 */
	public static function chunk( $markdown, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'target'  => self::TARGET_TOKENS,
				'max'     => self::MAX_TOKENS,
				'min'     => self::MIN_TOKENS,
				'overlap' => self::OVERLAP_FRACTION,
			)
		);

		$markdown = self::normalize( $markdown );
		if ( '' === $markdown ) {
			return array();
		}

		// Sections carry their heading trail, so a chunk always knows what it
		// sits under even after it has been cut away from the document.
		$sections = self::split_by_heading( $markdown );

		$chunks = array();
		foreach ( $sections as $section ) {
			foreach ( self::split_section( $section['body'], $args ) as $piece ) {
				$chunks[] = array(
					'content'      => $piece,
					'heading_path' => $section['path'],
				);
			}
		}

		$chunks = self::apply_overlap( $chunks, $args );

		// Score the chunk's OWN text, never its overlap. The overlap is a
		// retrieval aid borrowed from the previous chunk; judging the borrowed
		// sentence would report on the wrong passage entirely — a chunk that
		// opens "It requires 15Nm" would look fine purely because the chunk
		// before it ended with a well-formed sentence.
		$out = array();
		foreach ( $chunks as $i => $chunk ) {
			$assessment = self::assess( $chunk['content'], $chunk['heading_path'] );
			$full       = '' !== $chunk['overlap']
				? $chunk['overlap'] . "\n\n" . $chunk['content']
				: $chunk['content'];

			$out[] = array(
				'index'        => $i,
				'content'      => $chunk['content'],
				'overlap'      => $chunk['overlap'],
				'embed_text'   => $full,
				'heading_path' => $chunk['heading_path'],
				'tokens'       => self::estimate_tokens( $full ),
				'chars'        => strlen( $full ),
				'score'        => $assessment['score'],
				'issues'       => $assessment['issues'],
			);
		}

		return $out;
	}

	/* ─────────────────────────── splitting ─────────────────────────── */

	/**
	 * Collapse whitespace without destroying paragraph boundaries — those are
	 * the primary split signal and must survive normalization.
	 */
	private static function normalize( $markdown ) {
		$markdown = (string) $markdown;
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$markdown = preg_replace( '/[ \t]+/', ' ', $markdown );
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Break the document at Markdown headings, tracking the heading trail so
	 * every chunk can report where it came from.
	 *
	 * @return array[] { path: string, body: string }
	 */
	private static function split_by_heading( $markdown ) {
		$lines    = explode( "\n", $markdown );
		$sections = array();
		$trail    = array();
		$buffer   = array();
		$current  = '';

		foreach ( $lines as $line ) {
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				if ( array_filter( $buffer, 'strlen' ) ) {
					$sections[] = array(
						'path' => $current,
						'body' => trim( implode( "\n", $buffer ) ),
					);
				}
				$buffer = array();

				$depth = strlen( $m[1] );
				$text  = trim( wp_strip_all_tags( $m[2] ) );

				// Keep only headings ABOVE this level, then set this one. The
				// trail is keyed by level, not position: a page that opens at
				// H2 (no H1 — most WordPress content) otherwise nests every
				// later H2 under the first, reporting siblings as "A › B".
				$kept = array();
				foreach ( $trail as $level => $heading ) {
					if ( $level < $depth ) {
						$kept[ $level ] = $heading;
					}
				}
				$kept[ $depth ] = $text;
				ksort( $kept );
				$trail   = $kept;
				$current = implode( ' › ', array_filter( $trail, 'strlen' ) );

				continue;
			}

			$buffer[] = $line;
		}

		if ( array_filter( $buffer, 'strlen' ) ) {
			$sections[] = array(
				'path' => $current,
				'body' => trim( implode( "\n", $buffer ) ),
			);
		}

		return $sections;
	}

	/**
	 * Split one section's body into chunks: paragraphs first, sentences when a
	 * paragraph alone exceeds the ceiling, words only inside a runaway sentence.
	 *
	 * @return string[]
	 */
	private static function split_section( $body, array $args ) {
		$body = trim( $body );
		if ( '' === $body ) {
			return array();
		}

		$paragraphs = preg_split( '/\n{2,}/', $body );
		$paragraphs = array_values( array_filter( array_map( 'trim', $paragraphs ), 'strlen' ) );

		$chunks        = array();
		$current       = '';
		$table_caption = '';

		foreach ( $paragraphs as $paragraph ) {
			$para_tokens = self::estimate_tokens( $paragraph );
			if ( ! self::is_table( $paragraph ) ) {
				$table_caption = '';
			}

			// A long table comes apart between rows, each piece keeping the
			// header row — a passage of "Option | Option | N/A" without the
			// column names says nothing.
			if ( $para_tokens > $args['target'] && self::is_table( $paragraph ) ) {
				// A short line just before the table is its title ("Standard and
				// Optional Equipment"): every piece carries it, so a search for
				// the title lands on the rows.
				// A second table straight after the first (two printed side by
				// side) shares its title.
				$caption = '' === $current ? $table_caption : '';
				if ( '' !== $current && self::estimate_tokens( $current ) <= 30 ) {
					$caption = $current;
				} elseif ( '' !== $current ) {
					$chunks[] = $current;
				}
				$current       = '';
				$table_caption = $caption;
				foreach ( self::split_table( $paragraph, $args ) as $piece ) {
					$chunks[] = '' !== $caption ? $caption . "\n\n" . $piece : $piece;
				}
				continue;
			}

			// A single paragraph over the ceiling has to come apart on its own.
			if ( $para_tokens > $args['max'] ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				foreach ( self::split_long_paragraph( $paragraph, $args ) as $piece ) {
					$chunks[] = $piece;
				}
				continue;
			}

			$candidate = '' === $current ? $paragraph : $current . "\n\n" . $paragraph;

			if ( self::estimate_tokens( $candidate ) > $args['target'] && '' !== $current ) {
				$chunks[] = $current;
				$current  = $paragraph;
				continue;
			}

			$current = $candidate;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return self::merge_runts( $chunks, $args );
	}

	/**
	 * Sentence-level split for a paragraph that will not fit, falling back to
	 * words only when one "sentence" is itself oversized (tables, spec dumps,
	 * and anything without terminal punctuation land here).
	 *
	 * @return string[]
	 */
	private static function split_long_paragraph( $paragraph, array $args ) {
		$sentences = preg_split( '/(?<=[.!?])\s+(?=[A-Z0-9"\'(\[])/', $paragraph );
		$sentences = array_values( array_filter( array_map( 'trim', (array) $sentences ), 'strlen' ) );

		if ( empty( $sentences ) ) {
			$sentences = array( $paragraph );
		}

		$chunks  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			if ( self::estimate_tokens( $sentence ) > $args['max'] ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				foreach ( self::split_by_words( $sentence, $args['max'] ) as $piece ) {
					$chunks[] = $piece;
				}
				continue;
			}

			$candidate = '' === $current ? $sentence : $current . ' ' . $sentence;

			if ( self::estimate_tokens( $candidate ) > $args['target'] && '' !== $current ) {
				$chunks[] = $current;
				$current  = $sentence;
				continue;
			}

			$current = $candidate;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/** Whether a paragraph is a Markdown table: every line a "| … |" row. */
	public static function is_table( $paragraph ) {
		$lines = explode( "\n", trim( (string) $paragraph ) );
		if ( count( $lines ) < 3 || ! preg_match( '/^\|(\s*:?-{3,}:?\s*\|)+$/', trim( $lines[1] ) ) ) {
			return false;
		}
		foreach ( $lines as $line ) {
			if ( 0 !== strpos( trim( $line ), '|' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A table in pieces of whole rows, each under the target size and each
	 * starting with the header row and its separator.
	 *
	 * @return string[]
	 */
	private static function split_table( $table, array $args ) {
		$lines  = explode( "\n", trim( $table ) );
		$head   = $lines[0] . "\n" . $lines[1];
		$rows   = array_slice( $lines, 2 );
		$pieces = array();
		$batch  = array();
		foreach ( $rows as $row ) {
			$candidate = $head . "\n" . implode( "\n", array_merge( $batch, array( $row ) ) );
			if ( $batch && self::estimate_tokens( $candidate ) > $args['target'] ) {
				$pieces[] = $head . "\n" . implode( "\n", $batch );
				$batch    = array();
			}
			$batch[] = $row;
		}
		if ( $batch ) {
			$pieces[] = $head . "\n" . implode( "\n", $batch );
		}

		return $pieces;
	}

	/**
	 * Last resort. Only reached inside a single oversized sentence.
	 *
	 * @return string[]
	 */
	private static function split_by_words( $text, $max_tokens ) {
		$words     = preg_split( '/\s+/', trim( $text ) );
		$per_chunk = max( 1, (int) ( $max_tokens * 0.75 ) );

		$chunks = array();
		foreach ( array_chunk( $words, $per_chunk ) as $group ) {
			$chunks[] = implode( ' ', $group );
		}

		return $chunks;
	}

	/**
	 * Fold undersized trailing fragments back into their neighbour. An orphaned
	 * two-line chunk retrieves badly and tells the user nothing useful.
	 *
	 * @return string[]
	 */
	private static function merge_runts( array $chunks, array $args ) {
		$out = array();

		foreach ( $chunks as $chunk ) {
			$tokens = self::estimate_tokens( $chunk );

			if ( $tokens < $args['min'] && ! empty( $out ) ) {
				$prev     = array_pop( $out );
				$combined = $prev . "\n\n" . $chunk;

				// Only merge when the result still fits; otherwise keep it separate.
				if ( self::estimate_tokens( $combined ) <= $args['max'] ) {
					$out[] = $combined;
					continue;
				}

				$out[] = $prev;
			}

			$out[] = $chunk;
		}

		return $out;
	}

	/**
	 * Carry a tail of each chunk into the next so a fact spanning a boundary is
	 * not lost to whichever side it fell on.
	 *
	 * Two rules keep this honest:
	 *
	 *   - The overlap is stored separately from the chunk's own text, never
	 *     prepended to it, so scoring and heading attribution stay correct.
	 *   - It never crosses a heading boundary. Carrying the end of "Bearing
	 *     Replacement" into the start of "Diagnostic Process" mislabels the
	 *     passage and pollutes its embedding with a different topic — the exact
	 *     opposite of what overlap is for.
	 *
	 * @return array[]
	 */
	private static function apply_overlap( array $chunks, array $args ) {
		$overlap_tokens = (int) round( $args['target'] * (float) $args['overlap'] );

		$out = array();
		foreach ( $chunks as $i => $chunk ) {
			$chunk['overlap'] = '';

			$same_section = $i > 0
				&& $chunks[ $i - 1 ]['heading_path'] === $chunk['heading_path'];

			if ( $overlap_tokens >= 1 && $same_section ) {
				$chunk['overlap'] = self::tail_sentences(
					$chunks[ $i - 1 ]['content'],
					$overlap_tokens * self::CHARS_PER_TOKEN
				);
			}

			$out[] = $chunk;
		}

		return $out;
	}

	/**
	 * Take whole sentences from the end of a chunk, never a partial one — a
	 * half sentence of overlap is noise that degrades the embedding.
	 */
	private static function tail_sentences( $text, $max_chars ) {
		$text = trim( $text );
		if ( '' === $text || $max_chars < 1 ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		$sentences = array_values( array_filter( array_map( 'trim', (array) $sentences ), 'strlen' ) );

		$tail = '';
		for ( $i = count( $sentences ) - 1; $i >= 0; $i-- ) {
			$candidate = '' === $tail ? $sentences[ $i ] : $sentences[ $i ] . ' ' . $tail;

			if ( strlen( $candidate ) > $max_chars ) {
				break;
			}

			$tail = $candidate;
		}

		return $tail;
	}

	/* ─────────────────────── self-sufficiency ─────────────────────── */

	/**
	 * Judge whether a chunk can stand alone in a retrieval index.
	 *
	 * Deterministic and offline by design. These are mechanical retrieval
	 * failures, not questions of writing quality, so no model is needed — which
	 * is what keeps Chunk View free and usable with no API key configured.
	 *
	 * @param string $content      The chunk.
	 * @param string $heading_path Heading trail, if any.
	 * @return array{score:int, issues:array[]}
	 */
	public static function assess( $content, $heading_path = '' ) {
		$issues = array();
		$score  = 100;

		$text  = trim( wp_strip_all_tags( (string) $content ) );
		$lower = strtolower( $text );

		if ( '' === $text ) {
			return array(
				'score'  => 0,
				'issues' => array(
					array(
						'code'  => 'empty',
						'level' => 'error',
						'label' => __( 'Chunk is empty.', 'twt-aeo-ultimate' ),
					),
				),
			);
		}

		// 1. An opening pronoun with no antecedent inside the chunk.
		$first_words = strtolower( implode( ' ', array_slice( preg_split( '/\s+/', $text ), 0, 2 ) ) );
		foreach ( self::DANGLING_OPENERS as $opener ) {
			if ( 0 === strpos( $first_words, $opener . ' ' ) || $first_words === $opener ) {
				$issues[] = array(
					'code'  => 'dangling_opener',
					'level' => 'error',
					'label' => sprintf(
						/* translators: %s: the pronoun the chunk opens with. */
						__( 'Opens with "%s" — nothing in this chunk says what that refers to.', 'twt-aeo-ultimate' ),
						esc_html( trim( $opener ) )
					),
				);
				$score -= 30;
				break;
			}
		}

		// 2. Explicit pointers to text that will not be retrieved alongside it.
		foreach ( self::BACKREFERENCES as $phrase ) {
			if ( false !== strpos( $lower, $phrase ) ) {
				$issues[] = array(
					'code'  => 'backreference',
					'level' => 'warning',
					'label' => sprintf(
						/* translators: %s: the back-reference phrase found. */
						__( 'Contains "%s" — points at content outside this chunk.', 'twt-aeo-ultimate' ),
						esc_html( $phrase )
					),
				);
				$score -= 12;
				break;
			}
		}

		// 3. No heading trail: the chunk has no stated subject at all.
		if ( '' === trim( (string) $heading_path ) ) {
			$issues[] = array(
				'code'  => 'no_heading',
				'level' => 'warning',
				'label' => __( 'Sits under no heading, so the retrieved passage carries no topic label.', 'twt-aeo-ultimate' ),
			);
			$score -= 15;
		}

		// 4. Too short to answer anything on its own.
		$tokens = self::estimate_tokens( $text );
		if ( $tokens < self::MIN_TOKENS ) {
			$issues[] = array(
				'code'  => 'too_short',
				'level' => 'warning',
				'label' => sprintf(
					/* translators: %d: estimated token count. */
					__( 'Only ~%d tokens — usually too thin to satisfy a query by itself.', 'twt-aeo-ultimate' ),
					$tokens
				),
			);
			$score -= 15;
		}

		// 5. Oversized chunks get diluted: the embedding averages across too
		//    many ideas and matches none of them strongly.
		if ( $tokens > self::MAX_TOKENS ) {
			$issues[] = array(
				'code'  => 'too_long',
				'level' => 'warning',
				'label' => sprintf(
					/* translators: %d: estimated token count. */
					__( '~%d tokens — covers enough separate ideas that it matches none of them strongly.', 'twt-aeo-ultimate' ),
					$tokens
				),
			);
			$score -= 10;
		}

		// 6. Not prose at all — an image gallery, a link list, or a call-to-
		//    action button. These carry almost no retrievable language, so
		//    scoring one highly would be actively misleading: a chunk made of
		//    nothing but alt text is not a passage anything will ever cite.
		$prose_ratio = self::prose_ratio( $text );
		if ( $prose_ratio < 0.4 ) {
			$issues[] = array(
				'code'  => 'not_prose',
				'level' => 'error',
				'label' => __( 'Mostly links, buttons or image captions rather than sentences — there is no passage here to retrieve.', 'twt-aeo-ultimate' ),
			);
			$score -= 45;
		} elseif ( $prose_ratio < 0.65 ) {
			$issues[] = array(
				'code'  => 'link_heavy',
				'level' => 'warning',
				'label' => __( 'Heavy on links and captions relative to explanatory text.', 'twt-aeo-ultimate' ),
			);
			$score -= 15;
		}

		// 7. Numbers without units or a subject. Spec values are exactly what
		//    gets cited, and exactly what is useless when stranded.
		if ( preg_match( '/(?:^|\s)(\d+(?:\.\d+)?)\s*(?:%|mm|nm|kg|lb|rpm|hz|v|w|a)?\b/i', $text )
			&& ! preg_match( '/[A-Z][a-z]+\s+(?:is|are|requires|needs|measures|weighs|uses|has)/', $text ) ) {

			$has_subject = preg_match( '/\b[A-Z][A-Za-z0-9-]{2,}\b/', $text );
			if ( ! $has_subject ) {
				$issues[] = array(
					'code'  => 'unanchored_figure',
					'level' => 'warning',
					'label' => __( 'Contains figures but names no product, part, or entity they belong to.', 'twt-aeo-ultimate' ),
				);
				$score -= 10;
			}
		}

		return array(
			'score'  => max( 0, min( 100, $score ) ),
			'issues' => $issues,
		);
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * Fraction of a chunk that is running prose rather than markup furniture.
	 *
	 * Link labels and image captions are stripped of their syntax and the
	 * remainder measured against the whole. A paragraph containing a couple of
	 * inline links scores high; a gallery, a nav list or a lone button scores
	 * near zero, which is the signal we want.
	 *
	 * @param string $text Chunk text.
	 * @return float 0.0–1.0
	 */
	private static function prose_ratio( $text ) {
		$total = strlen( $text );
		if ( $total < 1 ) {
			return 0.0;
		}

		// Remove image embeds entirely — alt text is not a retrievable passage.
		$prose = preg_replace( '/!?\[Image:[^\]]*\]/i', '', $text );
		$prose = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $prose );

		// Keep link labels but drop the URL, which is markup, not language.
		$prose = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $prose );

		// Bullet glyphs and list markers are structure, not sentences.
		$prose = preg_replace( '/^[\s>*\-•+]+/m', '', $prose );

		$prose = trim( preg_replace( '/\s+/', ' ', $prose ) );

		// Sentence-ending punctuation is the clearest signal of real prose. A
		// chunk with none is a list or a label however long it is.
		if ( ! preg_match( '/[.!?]/', $prose ) ) {
			return min( 0.35, strlen( $prose ) / $total );
		}

		return strlen( $prose ) / $total;
	}

	/**
	 * Estimated token count. Deliberately an approximation — the real tokenizer
	 * varies per model, and sizing decisions do not need better than this.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	public static function estimate_tokens( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}

		return (int) max( 1, ceil( strlen( $text ) / self::CHARS_PER_TOKEN ) );
	}

	/**
	 * Aggregate score for a whole page: the mean of its chunk scores.
	 *
	 * @param array[] $chunks Output of chunk().
	 * @return int
	 */
	public static function page_score( array $chunks ) {
		if ( empty( $chunks ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $chunks as $chunk ) {
			$total += isset( $chunk['score'] ) ? (int) $chunk['score'] : 0;
		}

		return (int) round( $total / count( $chunks ) );
	}
}
