<?php
/**
 * AI Visibility — "Load sample data": a fabricated run for the board (pure half).
 *
 * Ported from the Shopify app's `buildDemoRun` (`aiVisibility.ts`, AEO
 * Ultimate, 2026-08-18). Every field is shaped like a real check, none of it
 * is an assistant's words. Deterministic for a given seed so the board is
 * stable across reloads; the caller keeps `demo` runs labelled and out of
 * trends, and never mixes one into a real run.
 *
 * Runs WITHOUT WordPress loaded. No time(), no rand(): the PRNG is seeded
 * from `$seed` and the timestamp is `$started_at`.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Sample {

	/**
	 * Competitors a sample answer cites, with a weight so the rings show a
	 * realistic head-and-tail rather than an even split.
	 */
	const COMPETITORS = array(
		array( 'amazon.com', 9 ),
		array( 'walmart.com', 6 ),
		array( 'target.com', 4 ),
		array( 'reddit.com', 4 ),
		array( 'ebay.com', 3 ),
		array( 'bestbuy.com', 3 ),
		array( 'etsy.com', 3 ),
		array( 'wirecutter.com', 2 ),
		array( 'youtube.com', 2 ),
		array( 'chewy.com', 2 ),
		array( 'wayfair.com', 2 ),
		array( 'homedepot.com', 1 ),
	);

	/** Per engine the weights are nudged so the four rings draw visibly different. */
	const ENGINE_BIAS = array(
		'chatgpt'    => array( 'reddit.com', 'wirecutter.com' ),
		'gemini'     => array( 'youtube.com', 'walmart.com' ),
		'claude'     => array( 'target.com', 'etsy.com' ),
		'perplexity' => array( 'reddit.com', 'ebay.com' ),
	);

	/** Where a policy question's citation would land (WooCommerce's default refund page). */
	const POLICY_PATH = '/refund_returns/';

	const SAMPLE_PREFIX  = 'SAMPLE — not a real answer.';
	const SAMPLE_MESSAGE = 'Sample data — fabricated to show the board. Remove it before reading anything into the numbers.';
	const SAMPLE_ERROR   = 'SAMPLE — the provider was unreachable for this check.';

	private function __construct() {}

	/**
	 * A fabricated run. Verdict spread ~42% cited / 23% named / 32% absent / 3%
	 * unavailable — a site doing well with visible gaps; factual questions come
	 * back ~70% correct / 20% wrong / 10% not stated.
	 *
	 * @param array  $questions  Question[] (from TWTAEO_Visibility_Questions::build_questions).
	 * @param array  $engines    Engine ids to fabricate answers for.
	 * @param array  $ctx        [ hosts[], site_name, policy_path? ].
	 * @param string $seed       Any string; same seed → same run.
	 * @param string $started_at ISO-8601 timestamp to stamp on the run and every check.
	 * @return array Run.
	 */
	public static function build_demo_run( array $questions, array $engines, array $ctx, $seed, $started_at ) {
		$seed      = (string) $seed;
		$state     = self::seed_state( $seed );
		$hosts     = isset( $ctx['hosts'] ) && is_array( $ctx['hosts'] ) ? array_values( $ctx['hosts'] ) : array();
		$host      = isset( $hosts[0] ) && '' !== (string) $hosts[0] ? (string) $hosts[0] : 'example.com';
		$site_name = '';
		if ( isset( $ctx['site_name'] ) ) {
			$site_name = (string) $ctx['site_name'];
		} elseif ( isset( $ctx['shop_name'] ) ) {
			$site_name = (string) $ctx['shop_name'];
		}
		$policy_path = isset( $ctx['policy_path'] ) && '' !== (string) $ctx['policy_path'] ? (string) $ctx['policy_path'] : self::POLICY_PATH;
		$models      = TWTAEO_Visibility_Types::MODELS;
		$utms        = TWTAEO_Visibility_Types::REFERRAL_UTM;

		$queue    = array();
		$checks   = array();
		$weighted = array(); // per engine, built once

		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$level  = isset( $q['level'] ) ? (string) $q['level'] : '';
			$family = isset( $q['family'] ) ? (string) $q['family'] : '';
			$label  = isset( $q['scope_label'] ) ? (string) $q['scope_label'] : '';
			$scope  = isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '';
			$text   = isset( $q['text'] ) ? (string) $q['text'] : '';
			$truth  = isset( $q['truth'] ) && is_array( $q['truth'] ) ? $q['truth'] : null;

			foreach ( $engines as $engine ) {
				$engine  = (string) $engine;
				$queue[] = array(
					'question_id' => (string) $q['id'],
					'engine'      => $engine,
				);

				$roll = self::next( $state );
				if ( $roll < 0.42 ) {
					$verdict = 'cited';
				} elseif ( $roll < 0.65 ) {
					$verdict = 'named';
				} elseif ( $roll < 0.97 ) {
					$verdict = 'absent';
				} else {
					$verdict = 'unavailable';
				}

				$utm = isset( $utms[ $engine ] ) ? (string) $utms[ $engine ] : '';
				if ( 'product' === $level && '' !== $scope ) {
					$slug = self::slugify( $label );
					$path = '/product/' . ( '' !== $slug ? $slug : 'item' ) . '/';
				} elseif ( 'collection' === $level ) {
					$slug = self::slugify( $label );
					$path = '/product-category/' . ( '' !== $slug ? $slug : 'all' ) . '/';
				} elseif ( 'post' === $level ) {
					$slug = self::slugify( $label );
					$path = '/' . ( '' !== $slug ? $slug . '/' : '' );
				} elseif ( 'returns' === $family || 'shipping' === $family || 'shipping_policy' === $family ) {
					$path = $policy_path;
				} else {
					$path = '/';
				}
				$our_url = 'https://' . $host . $path . ( '' !== $utm ? '?' . $utm : '' );

				$competitor_count = 'unavailable' === $verdict ? 0 : 2 + (int) floor( self::next( $state ) * 3 );
				if ( ! isset( $weighted[ $engine ] ) ) {
					$weighted[ $engine ] = self::weighted_for( $engine );
				}
				$pool        = $weighted[ $engine ];
				$competitors = array();
				$guard       = 0;
				while ( count( $competitors ) < $competitor_count && $guard++ < 40 ) {
					$c = $pool[ (int) floor( self::next( $state ) * count( $pool ) ) ];
					if ( ! in_array( $c, $competitors, true ) ) {
						$competitors[] = $c;
					}
				}

				$cited_urls = array();
				if ( 'cited' === $verdict ) {
					$cited_urls[] = $our_url;
				}
				foreach ( $competitors as $c ) {
					$cited_urls[] = 'https://www.' . $c . '/';
				}
				$our_urls = 'cited' === $verdict ? array( $our_url ) : array();
				$domains  = array();
				foreach ( $cited_urls as $u ) {
					$h = TWTAEO_Visibility_Verdict::host_of( $u );
					if ( '' !== $h && ! in_array( $h, $domains, true ) ) {
						$domains[] = $h;
					}
				}

				if ( null === $truth || 'unavailable' === $verdict ) {
					$accuracy = 'n/a';
				} elseif ( self::next( $state ) < 0.7 ) {
					$accuracy = 'correct';
				} elseif ( self::next( $state ) < 0.66 ) {
					$accuracy = 'wrong';
				} else {
					$accuracy = 'not_stated';
				}

				if ( 'cited' === $verdict ) {
					$mention = $site_name . ' is one option — see ' . $host . '.';
				} elseif ( 'named' === $verdict ) {
					$mention = $site_name . ' carries this, alongside larger retailers.';
				} else {
					$mention = 'Several large retailers stock this; compare prices before buying.';
				}

				$checks[] = array(
					'question_id' => (string) $q['id'],
					'engine'      => $engine,
					'at'          => $started_at,
					'verdict'     => $verdict,
					'accuracy'    => $accuracy,
					'cited_urls'  => $cited_urls,
					'our_urls'    => $our_urls,
					'domains'     => $domains,
					'excerpt'     => TWTAEO_Visibility_Verdict::excerpt_of( self::SAMPLE_PREFIX . ' Q: ' . $text . ' A: ' . $mention ),
					'latency_ms'  => 4000 + (int) floor( self::next( $state ) * 9000 ),
					'error'       => 'unavailable' === $verdict ? self::SAMPLE_ERROR : null,
					'model'       => isset( $models[ $engine ] ) ? $models[ $engine ] : '',
					'tools'       => array( 'web_search' ),
				);
			}
		}

		return array(
			'id'          => 'demo-' . $seed,
			'started_at'  => $started_at,
			'finished_at' => $started_at,
			'allocation'  => array(
				'company'        => 0,
				'per_collection' => 0,
				'per_type'       => 0,
				'per_product'    => 0,
				'per_post'       => 0,
				'engines'        => array_values( $engines ),
			),
			'questions'   => $questions,
			'queue'       => $queue,
			'cursor'      => count( $queue ),
			'checks'      => $checks,
			'status'      => 'done',
			'message'     => self::SAMPLE_MESSAGE,
			'demo'        => true,
		);
	}

	/* ─────────────────────────────── PRNG ──────────────────────────────── */

	/**
	 * mulberry32, ported with explicit 32-bit arithmetic. Integer-exact on 64-bit
	 * PHP (every intermediate stays below 2^53); the sequence is deterministic per
	 * seed on any build, which is what the board needs.
	 *
	 * @param string $seed Seed string.
	 * @return array State [ 'a' => uint32 ].
	 */
	private static function seed_state( $seed ) {
		$h = ( 1779033703 ^ strlen( $seed ) ) & 0xFFFFFFFF;
		$n = strlen( $seed );
		for ( $i = 0; $i < $n; $i++ ) {
			$h = self::imul( $h ^ ord( $seed[ $i ] ), 3432918353 );
			$h = ( ( ( $h << 13 ) & 0xFFFFFFFF ) | ( $h >> 19 ) ) & 0xFFFFFFFF;
		}
		return array( 'a' => $h );
	}

	/** Next float in [0, 1). Advances the state in place. */
	private static function next( array &$state ) {
		$a = ( $state['a'] + 0x6D2B79F5 ) & 0xFFFFFFFF;
		$state['a'] = $a;
		$t = self::imul( $a ^ ( $a >> 15 ), ( 1 | $a ) & 0xFFFFFFFF );
		$t = ( ( ( $t + self::imul( $t ^ ( $t >> 7 ), ( 61 | $t ) & 0xFFFFFFFF ) ) & 0xFFFFFFFF ) ^ $t ) & 0xFFFFFFFF;
		$r = ( $t ^ ( $t >> 14 ) ) & 0xFFFFFFFF;
		return $r / 4294967296;
	}

	/** Low 32 bits of a*b for two uint32 values (JS Math.imul), without 64-bit overflow. */
	private static function imul( $a, $b ) {
		$a    = $a & 0xFFFFFFFF;
		$b    = $b & 0xFFFFFFFF;
		$a_lo = $a & 0xFFFF;
		$a_hi = $a >> 16;
		$b_lo = $b & 0xFFFF;
		$b_hi = $b >> 16;
		$lo   = $a_lo * $b_lo;
		$mid  = ( ( $a_hi * $b_lo ) + ( $a_lo * $b_hi ) ) & 0xFFFF;
		return ( ( $mid << 16 ) + $lo ) & 0xFFFFFFFF;
	}

	/* ─────────────────────────────── helpers ───────────────────────────── */

	private static function weighted_for( $engine ) {
		$bias = isset( self::ENGINE_BIAS[ $engine ] ) ? self::ENGINE_BIAS[ $engine ] : array();
		$out  = array();
		foreach ( self::COMPETITORS as $pair ) {
			$domain = $pair[0];
			$w      = $pair[1] + ( in_array( $domain, $bias, true ) ? 3 : 0 );
			for ( $i = 0; $i < $w; $i++ ) {
				$out[] = $domain;
			}
		}
		return $out;
	}

	private static function slugify( $label ) {
		$s = strtolower( (string) $label );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}
}
