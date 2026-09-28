<?php
/**
 * AI Visibility — hosts, the verdict rule, share of voice, run summaries (pure half).
 *
 * Ported from the Shopify app's `aiVisibility.ts` (AEO Ultimate, 2026-08-18).
 * Runs WITHOUT WordPress loaded: `parse_url` not `wp_parse_url`, no esc_*,
 * no __(). Deterministic — no time(), no rand().
 *
 * Verdict vocabulary (TWTAEO_Visibility_Types::VERDICTS): cited > named >
 * absent. `unavailable` is the CALLER's verdict for an errored check — the
 * judge here only ever sees an answer that arrived.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Verdict {

	/** Competitor domains shown before the rest folds into "other". */
	const MAX_COMPETITOR_SLICES = 6;

	private function __construct() {}

	/* ─────────────────────────────── hosts ─────────────────────────────── */

	/**
	 * Lowercase host without a leading "www."; "" when the value is not an http(s) URL.
	 * A bare host ("example.com/path") is accepted; anything that already carries a
	 * scheme (mailto:, javascript:, http:) is parsed as-is so "mailto:a@b.com" cannot
	 * masquerade as host b.com.
	 *
	 * @param mixed $url URL or bare host.
	 * @return string
	 */
	public static function host_of( $url ) {
		$raw = trim( self::to_string( $url ) );
		if ( '' === $raw ) {
			return '';
		}
		$has_scheme = (bool) preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $raw );
		$candidate  = $has_scheme ? $raw : 'https://' . $raw;
		$parts      = parse_url( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, WP is not loaded.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		// parse_url is lenient ("not a url" becomes a host); only hostname characters pass.
		if ( ! preg_match( '/^[a-z0-9\-._\x80-\xff]+$/', $host ) ) {
			return '';
		}
		$host = preg_replace( '/^www\./', '', $host );
		return $host;
	}

	/** Lowercase, no leading "www.", no trailing dot. */
	public static function normalize_host( $host ) {
		$h = strtolower( trim( self::to_string( $host ) ) );
		$h = preg_replace( '/^www\./', '', $h );
		$h = preg_replace( '/\.$/', '', $h );
		return $h;
	}

	/**
	 * Same host, or a subdomain of one of ours ("shop.brand.com" counts).
	 *
	 * @param string $host      Host to test.
	 * @param array  $our_hosts The site's hosts.
	 * @return bool
	 */
	public static function is_our_host( $host, array $our_hosts ) {
		$h = self::normalize_host( $host );
		if ( '' === $h ) {
			return false;
		}
		foreach ( $our_hosts as $raw ) {
			$ours = self::normalize_host( $raw );
			if ( '' === $ours ) {
				continue;
			}
			if ( $h === $ours || self::ends_with( $h, '.' . $ours ) ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────────── text ──────────────────────────────── */

	/**
	 * Whitespace-collapsed text cut to `$max` characters with a trailing ellipsis.
	 *
	 * @param mixed $text Text.
	 * @param int   $max  Character limit (the ellipsis counts).
	 * @return string
	 */
	public static function excerpt_of( $text, $max = 300 ) {
		$clean = trim( preg_replace( '/\s+/', ' ', self::to_string( $text ) ) );
		$max   = (int) $max;
		if ( self::u_length( $clean ) <= $max ) {
			return $clean;
		}
		return rtrim( self::u_substr( $clean, 0, max( 0, $max - 1 ) ) ) . '…';
	}

	/* ─────────────────────────────── verdict ───────────────────────────── */

	/**
	 * Whole-word, case-insensitive: "Ace" must not match "Aceto". Names shorter
	 * than three characters never match.
	 *
	 * @param string $text Answer text.
	 * @param string $name Site or brand name.
	 * @return bool
	 */
	public static function mentions_name( $text, $name ) {
		$clean = trim( self::to_string( $name ) );
		if ( self::u_length( $clean ) < 3 ) {
			return false;
		}
		$quoted  = preg_replace( '/\s+/', '\s+', preg_quote( $clean, '/' ) );
		$pattern = '/(^|[^\p{L}\p{N}])' . $quoted . '(?=$|[^\p{L}\p{N}])/iu';
		$text    = self::to_string( $text );
		$hit     = preg_match( $pattern, $text ); // false (no warning) on invalid UTF-8.
		if ( false === $hit ) {
			// Invalid UTF-8 in the answer: retry without the unicode flag (ASCII boundaries).
			$ascii = '/(^|[^A-Za-z0-9])' . $quoted . '(?=$|[^A-Za-z0-9])/i';
			$hit   = preg_match( $ascii, $text );
		}
		return 1 === $hit;
	}

	/**
	 * Where the answer said each name: the text surrounding every whole-word
	 * occurrence (~110 characters each side), whitespace-collapsed. Being
	 * "named" alone says nothing about HOW — one of five shops in a list, the
	 * recommended pick, or a dismissive aside all score the same. The snippet
	 * shows the difference.
	 *
	 * Candidates are [ label, phrase ] pairs. A loose brand phrase whose words
	 * matched scattered ("Ultimate AEO" hitting "AEO … Ultimate") has no
	 * contiguous span; each word of four+ characters is tried instead so the
	 * mention still surfaces with SOME context.
	 *
	 * @param string $text       Answer text.
	 * @param array  $candidates [ [ label, phrase ] ].
	 * @param int    $cap        Maximum snippets returned across all candidates.
	 * @return array [ [ name, snippet ] ] — deduped, possibly empty.
	 */
	public static function mention_snippets( $text, array $candidates, $cap = 4 ) {
		$text = self::to_string( $text );
		$cap  = max( 1, (int) $cap );
		$out  = array();
		$seen = array();

		foreach ( $candidates as $candidate ) {
			if ( count( $out ) >= $cap ) {
				break;
			}
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$phrase = isset( $candidate['phrase'] ) ? trim( self::to_string( $candidate['phrase'] ) ) : '';
			$label  = isset( $candidate['label'] ) && '' !== trim( self::to_string( $candidate['label'] ) )
				? trim( self::to_string( $candidate['label'] ) )
				: $phrase;
			if ( self::u_length( $phrase ) < 3 ) {
				continue;
			}

			$snippets = self::snippets_for_phrase( $text, $phrase );
			if ( empty( $snippets ) ) {
				// Loose match: fall back to the phrase's individual words.
				foreach ( preg_split( '/\s+/', $phrase ) as $word ) {
					if ( self::u_length( $word ) >= 4 ) {
						$snippets = self::snippets_for_phrase( $text, $word );
						if ( ! empty( $snippets ) ) {
							break;
						}
					}
				}
			}
			foreach ( $snippets as $snippet ) {
				if ( count( $out ) >= $cap ) {
					break;
				}
				$key = strtolower( $snippet );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'name'    => $label,
					'snippet' => $snippet,
				);
			}
		}
		return $out;
	}

	/**
	 * Whole-word occurrences of one phrase with surrounding context, at most two.
	 *
	 * @param string $text   Answer text.
	 * @param string $phrase Contiguous phrase.
	 * @return string[] Whitespace-collapsed snippets.
	 */
	private static function snippets_for_phrase( $text, $phrase ) {
		$quoted  = preg_replace( '/\s+/', '\s+', preg_quote( $phrase, '/' ) );
		$pattern = '/.{0,110}(?:^|[^\p{L}\p{N}])' . $quoted . '(?:$|[^\p{L}\p{N}]).{0,110}/isu';
		$hit     = preg_match_all( $pattern, $text, $m );
		if ( false === $hit ) {
			// Invalid UTF-8 in the answer: ASCII boundaries, like mentions_name().
			$ascii = '/.{0,110}(?:^|[^A-Za-z0-9])' . $quoted . '(?:$|[^A-Za-z0-9]).{0,110}/is';
			$hit   = preg_match_all( $ascii, $text, $m );
		}
		if ( ! $hit ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $m[0], 0, 2 ) as $raw ) {
			$clean = trim( preg_replace( '/\s+/', ' ', $raw ) );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * cited > named > absent.
	 *
	 * A citation is ours when it lands on one of our hosts OR on a property we
	 * own somewhere else — the company LinkedIn page, a brand's YouTube channel.
	 * Being cited on a profile we own is still being cited; scoring it as a miss
	 * both under-counted us and, through `share_of_voice()`, filed our own page
	 * under the competition. `citation_surface` keeps the distinction visible
	 * ('site' vs 'profile'), so a site that only ever gets picked up second-hand
	 * can still see that its own domain is not the one being cited.
	 *
	 * `owned_domains` travels with the check because a host alone cannot settle
	 * it later: `linkedin.com` is ours on one answer and a stranger's on the
	 * next, and only the path tells them apart.
	 *
	 * @param array $answer [ text, cited_urls[] ].
	 * @param array $ctx    [ hosts[], names[], properties[]?, items[]? ].
	 * @return array [ verdict, our_urls[], domains[], owned_domains[], citation_surface, brand_hits[] ].
	 */
	public static function judge_verdict( array $answer, array $ctx ) {
		$hosts      = isset( $ctx['hosts'] ) && is_array( $ctx['hosts'] ) ? $ctx['hosts'] : array();
		$names      = isset( $ctx['names'] ) && is_array( $ctx['names'] ) ? $ctx['names'] : array();
		$properties = isset( $ctx['properties'] ) && is_array( $ctx['properties'] ) ? $ctx['properties'] : array();
		$items      = isset( $ctx['items'] ) && is_array( $ctx['items'] ) ? $ctx['items'] : array();
		$urls       = isset( $answer['cited_urls'] ) && is_array( $answer['cited_urls'] ) ? $answer['cited_urls'] : array();
		$text       = isset( $answer['text'] ) ? self::to_string( $answer['text'] ) : '';

		$has_brands = class_exists( 'TWTAEO_Visibility_Brands' );

		$domains       = array();
		$our_urls      = array();
		$owned_domains = array();
		$on_site       = false;
		$on_profile    = false;

		foreach ( $urls as $url ) {
			$host = self::host_of( $url );
			if ( '' === $host ) {
				continue;
			}
			if ( ! in_array( $host, $domains, true ) ) {
				$domains[] = $host;
			}
			$is_site  = self::is_our_host( $host, $hosts );
			$is_owned = $is_site;
			if ( ! $is_site && $has_brands && ! empty( $properties ) ) {
				// A brand can own a whole domain (its own website) as well as a
				// page on a shared platform. The property says which it is, so
				// a brand site is reported as 'site', not as somebody's profile.
				foreach ( TWTAEO_Visibility_Brands::properties_for( $url, $properties ) as $match ) {
					$is_owned = true;
					if ( isset( $match['kind'] ) && 'site' === $match['kind'] ) {
						$is_site = true;
					}
				}
			}
			if ( ! $is_owned ) {
				continue;
			}
			$our_urls[] = $url;
			if ( ! in_array( $host, $owned_domains, true ) ) {
				$owned_domains[] = $host;
			}
			if ( $is_site ) {
				$on_site = true;
			} else {
				$on_profile = true;
			}
		}

		$brand_hits = ( $has_brands && ! empty( $items ) )
			? TWTAEO_Visibility_Brands::evaluate( array( 'text' => $text, 'cited_urls' => $urls ), $items )
			: array();

		// Where the answer said the name — recorded even when we were also
		// cited, because a link and a prose mention are different exposures.
		$candidates = array();
		foreach ( $names as $name ) {
			$candidates[] = array(
				'label'  => self::to_string( $name ),
				'phrase' => self::to_string( $name ),
			);
		}
		foreach ( $brand_hits as $hit ) {
			if ( is_array( $hit ) && isset( $hit['phrase'] ) && '' !== (string) $hit['phrase'] ) {
				$candidates[] = array(
					'label'  => isset( $hit['label'] ) && '' !== (string) $hit['label'] ? (string) $hit['label'] : (string) $hit['phrase'],
					'phrase' => (string) $hit['phrase'],
				);
			}
		}
		$mentions = self::mention_snippets( $text, $candidates );

		if ( $on_site && $on_profile ) {
			$surface = 'both';
		} elseif ( $on_site ) {
			$surface = 'site';
		} elseif ( $on_profile ) {
			$surface = 'profile';
		} else {
			$surface = '';
		}

		if ( count( $our_urls ) > 0 ) {
			return array(
				'verdict'          => 'cited',
				'our_urls'         => $our_urls,
				'domains'          => $domains,
				'owned_domains'    => $owned_domains,
				'citation_surface' => $surface,
				'brand_hits'       => $brand_hits,
				'mentions'         => $mentions,
			);
		}

		$named = false;
		foreach ( $names as $name ) {
			if ( self::mentions_name( $text, $name ) ) {
				$named = true;
				break;
			}
		}
		// A tracked brand being named is the business being named.
		if ( ! $named ) {
			foreach ( $brand_hits as $hit ) {
				if ( isset( $hit['verdict'] ) && 'absent' !== $hit['verdict'] ) {
					$named = true;
					break;
				}
			}
		}
		return array(
			'verdict'          => $named ? 'named' : 'absent',
			'our_urls'         => $our_urls,
			'domains'          => $domains,
			'owned_domains'    => $owned_domains,
			'citation_surface' => $surface,
			'brand_hits'       => $brand_hits,
			'mentions'         => $mentions,
		);
	}

	/* ─────────────────────────── share of voice ────────────────────────── */

	/**
	 * Who was cited, per engine, optionally narrowed to a level / scope through
	 * the question map. Our hosts merge into ONE slice first, then the top six
	 * competitors by count, then "other".
	 *
	 * @param array $checks    Check[].
	 * @param array $questions Question[].
	 * @param array $filter    [ engine, level?, scope_id? ].
	 * @param array $our_hosts The site's hosts.
	 * @return array ShareOfVoice.
	 */
	public static function share_of_voice( array $checks, array $questions, array $filter, array $our_hosts ) {
		$engine   = isset( $filter['engine'] ) ? (string) $filter['engine'] : '';
		$level    = isset( $filter['level'] ) ? (string) $filter['level'] : '';
		$by_scope = array_key_exists( 'scope_id', $filter );
		$scope_id = $by_scope ? self::to_string( $filter['scope_id'] ) : '';

		$by_id = array();
		foreach ( $questions as $q ) {
			if ( is_array( $q ) && isset( $q['id'] ) ) {
				$by_id[ (string) $q['id'] ] = $q;
			}
		}

		$considered = array();
		foreach ( $checks as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['engine'] ) || (string) $c['engine'] !== $engine ) {
				continue;
			}
			if ( '' !== $level || $by_scope ) {
				$qid = isset( $c['question_id'] ) ? (string) $c['question_id'] : '';
				if ( ! isset( $by_id[ $qid ] ) ) {
					continue;
				}
				$q = $by_id[ $qid ];
				if ( '' !== $level && ( ! isset( $q['level'] ) || (string) $q['level'] !== $level ) ) {
					continue;
				}
				if ( $by_scope && '' !== $scope_id && ( ! isset( $q['scope_id'] ) || (string) $q['scope_id'] !== $scope_id ) ) {
					continue;
				}
			}
			$considered[] = $c;
		}

		$counts         = array();
		$ours           = 0;
		$with_citations = 0;
		$cited          = 0;
		$unavailable    = 0;
		foreach ( $considered as $c ) {
			if ( isset( $c['verdict'] ) && 'unavailable' === $c['verdict'] ) {
				$unavailable++;
			}
			if ( isset( $c['verdict'] ) && 'cited' === $c['verdict'] ) {
				$cited++;
			}
			$domains = array();
			$seen    = array();
			$raw     = isset( $c['domains'] ) && is_array( $c['domains'] ) ? $c['domains'] : array();
			foreach ( $raw as $d ) {
				$key = self::normalize_host( $d );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$domains[]    = $d;
			}
			if ( count( $domains ) > 0 ) {
				$with_citations++;
			}
			// Hosts this particular answer cited US on. Recorded per check because
			// a shared host cannot be judged from its name: linkedin.com is our
			// company page on one answer and somebody else's on the next.
			$owned = array();
			$raw_owned = isset( $c['owned_domains'] ) && is_array( $c['owned_domains'] ) ? $c['owned_domains'] : array();
			foreach ( $raw_owned as $od ) {
				$owned[ self::normalize_host( $od ) ] = true;
			}
			$counted_ours = false;
			foreach ( $domains as $d ) {
				if ( self::is_our_host( $d, $our_hosts ) || isset( $owned[ self::normalize_host( $d ) ] ) ) {
					if ( ! $counted_ours ) {
						$ours++;
						$counted_ours = true;
					}
					continue;
				}
				$host = self::normalize_host( $d );
				$counts[ $host ] = ( isset( $counts[ $host ] ) ? $counts[ $host ] : 0 ) + 1;
			}
		}

		$slices = array();
		if ( count( $considered ) > 0 && count( $our_hosts ) > 0 ) {
			$slices[] = array(
				'domain' => self::normalize_host( $our_hosts[0] ),
				'count'  => $ours,
				'ours'   => true,
			);
		}
		$competitors = array();
		foreach ( $counts as $domain => $n ) {
			$competitors[] = array( (string) $domain, $n );
		}
		usort(
			$competitors,
			function ( $a, $b ) {
				if ( $a[1] !== $b[1] ) {
					return $b[1] - $a[1];
				}
				return strcmp( $a[0], $b[0] );
			}
		);
		foreach ( array_slice( $competitors, 0, self::MAX_COMPETITOR_SLICES ) as $pair ) {
			$slices[] = array(
				'domain' => $pair[0],
				'count'  => $pair[1],
				'ours'   => false,
			);
		}
		$rest = 0;
		foreach ( array_slice( $competitors, self::MAX_COMPETITOR_SLICES ) as $pair ) {
			$rest += $pair[1];
		}
		if ( $rest > 0 ) {
			$slices[] = array(
				'domain' => 'other',
				'count'  => $rest,
				'ours'   => false,
			);
		}

		// Divide by ANSWERED checks, exactly as summarize_run() does. Dividing by
		// every considered check counted an errored call as a miss, so the same
		// engine read one percentage on its card and a lower one on its donut —
		// on a live run, Grok showed 57% and 36% (4 cited, 4 unavailable, 11
		// total). Both numbers were defensible; disagreeing on one screen was
		// not, and the card's reading is the correct one: a refused call is not
		// evidence that nobody cited you.
		$total    = count( $considered );
		$answered = max( 0, $total - $unavailable );
		return array(
			'engine'         => $engine,
			'checks'         => $total,
			'answered'       => $answered,
			'unavailable'    => $unavailable,
			'with_citations' => $with_citations,
			'our_cited_pct'  => $answered > 0 ? (int) round( ( $cited / $answered ) * 100 ) : 0,
			'slices'         => $slices,
		);
	}

	/* ───────────────────────────── run helpers ─────────────────────────── */

	/**
	 * cited_pct / named_pct over the checks that produced an answer (unavailable
	 * ones are excluded from the denominator — an errored call is not evidence of
	 * absence). "Named" counts cited answers too: a site that was linked was
	 * surfaced, so the headline reads "Cited in 23% · Named in 41%" with named ≥
	 * cited, never the other way round.
	 *
	 * @param array $run Run.
	 * @return array RunSummary.
	 */
	public static function summarize_run( array $run ) {
		$all      = isset( $run['checks'] ) && is_array( $run['checks'] ) ? $run['checks'] : array();
		$answered = array();
		$wrong    = 0;
		foreach ( $all as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			if ( isset( $c['accuracy'] ) && 'wrong' === $c['accuracy'] ) {
				$wrong++;
			}
			if ( ! isset( $c['verdict'] ) || 'unavailable' !== $c['verdict'] ) {
				$answered[] = $c;
			}
		}
		$cited = 0;
		$named = 0;
		foreach ( $answered as $c ) {
			$v = isset( $c['verdict'] ) ? $c['verdict'] : '';
			if ( 'cited' === $v ) {
				$cited++;
				$named++;
			} elseif ( 'named' === $v ) {
				$named++;
			}
		}
		$denominator = count( $answered );

		$engines = array();
		$alloc   = isset( $run['allocation'] ) && is_array( $run['allocation'] ) ? $run['allocation'] : array();
		if ( isset( $alloc['engines'] ) && is_array( $alloc['engines'] ) ) {
			foreach ( $alloc['engines'] as $e ) {
				if ( is_string( $e ) && ! in_array( $e, $engines, true ) ) {
					$engines[] = $e;
				}
			}
		}
		foreach ( $all as $c ) {
			if ( is_array( $c ) && isset( $c['engine'] ) && is_string( $c['engine'] ) && ! in_array( $c['engine'], $engines, true ) ) {
				$engines[] = $c['engine'];
			}
		}
		$by_engine = array();
		foreach ( $engines as $engine ) {
			$mine       = 0;
			$cited_here = 0;
			foreach ( $answered as $c ) {
				if ( isset( $c['engine'] ) && $c['engine'] === $engine ) {
					$mine++;
					if ( isset( $c['verdict'] ) && 'cited' === $c['verdict'] ) {
						$cited_here++;
					}
				}
			}
			$by_engine[ $engine ] = $mine > 0 ? (int) round( ( $cited_here / $mine ) * 100 ) : null;
		}

		// Per-brand cited %, computed here so the trend can read it straight off
		// stored summaries instead of reloading every run's checks. Runs recorded
		// before brand tracking carry no brand_hits and simply contribute nothing,
		// which is why a brand's line starts at the run it was first tracked in
		// rather than pretending to a history it does not have.
		$brand_seen  = array();
		$brand_cited = array();
		foreach ( $answered as $c ) {
			$hits = isset( $c['brand_hits'] ) && is_array( $c['brand_hits'] ) ? $c['brand_hits'] : array();
			foreach ( $hits as $hit ) {
				if ( ! is_array( $hit ) || ! isset( $hit['label'] ) || '' === (string) $hit['label'] ) {
					continue;
				}
				$label = (string) $hit['label'];
				if ( ! isset( $brand_seen[ $label ] ) ) {
					$brand_seen[ $label ]  = 0;
					$brand_cited[ $label ] = 0;
				}
				$brand_seen[ $label ]++;
				if ( isset( $hit['verdict'] ) && 'cited' === $hit['verdict'] ) {
					$brand_cited[ $label ]++;
				}
			}
		}
		$by_brand = array();
		foreach ( $brand_seen as $label => $seen ) {
			$by_brand[ $label ] = $seen > 0 ? (int) round( ( $brand_cited[ $label ] / $seen ) * 100 ) : 0;
		}

		return array(
			'id'          => isset( $run['id'] ) ? $run['id'] : '',
			'started_at'  => isset( $run['started_at'] ) ? $run['started_at'] : '',
			'finished_at' => isset( $run['finished_at'] ) && '' !== $run['finished_at'] ? $run['finished_at'] : null,
			'status'      => isset( $run['status'] ) ? $run['status'] : '',
			'demo'        => ! empty( $run['demo'] ),
			'checks'      => count( $all ),
			'cited_pct'   => $denominator > 0 ? (int) round( ( $cited / $denominator ) * 100 ) : 0,
			'named_pct'   => $denominator > 0 ? (int) round( ( $named / $denominator ) * 100 ) : 0,
			'wrong'       => $wrong,
			'by_engine'   => $by_engine,
			'by_brand'    => $by_brand,
		);
	}

	/**
	 * The next (question, engine) pair to run, or null when the queue is spent.
	 *
	 * @param array $run Run.
	 * @return array|null [ question_id, engine, turn? ] — turn only on follow-up turns (journey mode).
	 */
	public static function next_step( array $run ) {
		$cursor = isset( $run['cursor'] ) && is_numeric( $run['cursor'] ) ? max( 0, (int) $run['cursor'] ) : 0;
		$queue  = isset( $run['queue'] ) && is_array( $run['queue'] ) ? array_values( $run['queue'] ) : array();
		if ( ! isset( $queue[ $cursor ] ) || ! is_array( $queue[ $cursor ] ) ) {
			return null;
		}
		$item = $queue[ $cursor ];
		if ( ! isset( $item['question_id'] ) || ! isset( $item['engine'] ) ) {
			return null;
		}
		$step = array(
			'question_id' => (string) $item['question_id'],
			'engine'      => (string) $item['engine'],
		);
		if ( isset( $item['turn'] ) && (int) $item['turn'] > 1 ) {
			$step['turn'] = (int) $item['turn'];
		}
		return $step;
	}

	/* ─────────────────────────────── helpers ───────────────────────────── */

	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return substr( $haystack, -$len ) === $needle;
	}

	/** Character length, UTF-8 aware, without assuming mbstring is loaded. */
	private static function u_length( $s ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s, 'UTF-8' );
		}
		$n = preg_match_all( '/./us', $s, $m );
		return false === $n ? strlen( $s ) : $n;
	}

	/** Character substring, UTF-8 aware, without assuming mbstring is loaded. */
	private static function u_substr( $s, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $length, 'UTF-8' );
		}
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return substr( $s, $start, $length );
		}
		return implode( '', array_slice( $chars, $start, $length ) );
	}

	private static function to_string( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}
}
