<?php
/**
 * AI Visibility — market and plan tier.
 *
 * Two questions this class answers, and nothing else:
 *
 *   1. Which tiers does a run ask? That follows from the site's MARKET. A DTC
 *      site is asked `free` and `throttled` — essentially no consumer is
 *      paying, but plenty are past a free plan's daily quota and are being
 *      answered by a smaller model without being told. A B2B site adds `paid`,
 *      because a large minority of business buyers are on a plan.
 *
 *   2. What does a tier change about the request? Either the model (going
 *      `throttled`, and `paid` on any engine that really does switch model by
 *      plan) or the reasoning dial (`paid` on the engines that expose one).
 *      Where a tier would change neither, the run does not ask it twice —
 *      that would spend the owner's daily cap on a duplicate answer.
 *
 * `free` is byte-for-byte what this module sent before tiers existed, so no
 * existing trend line moves, and rows written earlier read as `free` rather
 * than as unknown.
 *
 * Reporting rule: rungs are shown as separate lines, never blended. The
 * percentages behind the market choice come from surveys this plugin cannot
 * cite, so they decide what to ASK and never appear as a weighted number.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Tier {

	private function __construct() {}

	/* ─────────────────────────── market ─────────────────────────── */

	/**
	 * The site's market, from AI Visibility settings.
	 *
	 * @return string dtc | b2b
	 */
	public static function market() {
		$settings = TWTAEO_Visibility_Store::get_settings();

		return self::clean_market( isset( $settings['market'] ) ? $settings['market'] : '' );
	}

	/**
	 * Coerce any input to a known market id.
	 *
	 * @param string $market
	 * @return string dtc | b2b
	 */
	public static function clean_market( $market ) {
		$market = strtolower( trim( (string) $market ) );

		return isset( TWTAEO_Visibility_Types::MARKETS[ $market ] )
			? $market
			: (string) TWTAEO_Visibility_Types::DEFAULT_MARKET;
	}

	/**
	 * The market a run asked at: the persona's own, else the site's.
	 *
	 * A run is asked AS one persona, and a persona belongs to one side of the
	 * consumer/business line -- a homeowner and a distributor do not meet the
	 * same AI even on the same site. So the persona decides, and the site
	 * setting covers the baseline run, asked as nobody in particular.
	 *
	 * @param array|null $persona Persona, or null for the baseline run.
	 * @return string dtc | b2b
	 */
	public static function market_for_persona( $persona ) {
		if ( is_array( $persona ) && ! empty( $persona['market'] ) ) {
			$market = strtolower( trim( (string) $persona['market'] ) );
			if ( isset( TWTAEO_Visibility_Types::MARKETS[ $market ] ) ) {
				return $market;
			}
		}

		return self::market();
	}

	/**
	 * The market a stored run was opened at. Copied onto the run when it
	 * started, so changing a persona or the site setting afterwards cannot
	 * rewrite what that run measured. Runs from before markets existed carry
	 * none and report the default.
	 *
	 * @param array $run
	 * @return string dtc | b2b
	 */
	public static function market_of_run( array $run ) {
		return self::clean_market( isset( $run['allocation']['market'] ) ? $run['allocation']['market'] : '' );
	}

	/**
	 * Coerce any input to a known tier id.
	 *
	 * @param string $tier
	 * @return string paid | free | throttled
	 */
	public static function clean_tier( $tier ) {
		$tier = strtolower( trim( (string) $tier ) );

		return in_array( $tier, TWTAEO_Visibility_Types::TIERS, true )
			? $tier
			: (string) TWTAEO_Visibility_Types::DEFAULT_TIER;
	}

	/** Human label for a tier. */
	public static function label( $tier ) {
		$tier = self::clean_tier( $tier );

		return isset( TWTAEO_Visibility_Types::TIER_LABEL[ $tier ] )
			? TWTAEO_Visibility_Types::TIER_LABEL[ $tier ]
			: $tier;
	}

	/* ──────────────────────── the audience mix ──────────────────────── */

	/**
	 * The starting mix for a market, derived rather than tabulated: the paid
	 * share is given, the rest is a free plan, and part of that free share is
	 * answered after the quota ran out. Two inputs, everything else by
	 * subtraction -- see the constants in TWTAEO_Visibility_Types.
	 *
	 * @param string $market
	 * @return array tier => [ on => bool, weight => int ]
	 */
	public static function default_mix( $market ) {
		$market = self::clean_market( $market );
		$paid   = isset( TWTAEO_Visibility_Types::MARKET_PAID_SHARE[ $market ] )
			? (int) TWTAEO_Visibility_Types::MARKET_PAID_SHARE[ $market ]
			: 0;
		$paid      = max( 0, min( 100, $paid ) );
		$on_free   = 100 - $paid;
		$throttled = (int) round( $on_free * (int) TWTAEO_Visibility_Types::THROTTLED_SHARE_OF_FREE / 100 );
		$throttled = max( 0, min( $on_free, $throttled ) );
		$free      = $on_free - $throttled;
		$floor     = (int) TWTAEO_Visibility_Types::TIER_WEIGHT_FLOOR;

		return array(
			'paid'      => array( 'on' => $paid >= $floor, 'weight' => $paid ),
			// The baseline is always asked: it is the row every engine has and
			// the one every figure recorded before tiers existed describes.
			'free'      => array( 'on' => true, 'weight' => $free ),
			'throttled' => array( 'on' => $throttled >= $floor, 'weight' => $throttled ),
		);
	}

	/**
	 * Coerce any stored mix into shape. Weights are clamped to 0..100 and are
	 * NOT forced to total 100 -- an owner mid-edit should not have their
	 * numbers silently rewritten. Normalisation happens where the weights are
	 * used, in weights_for_market().
	 *
	 * @param mixed  $raw
	 * @param string $market Market whose defaults fill the gaps.
	 * @return array tier => [ on => bool, weight => int ]
	 */
	public static function clean_mix( $raw, $market ) {
		$out = self::default_mix( $market );
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( TWTAEO_Visibility_Types::TIERS as $tier ) {
			if ( ! isset( $raw[ $tier ] ) || ! is_array( $raw[ $tier ] ) ) {
				continue;
			}
			$row = $raw[ $tier ];
			if ( isset( $row['weight'] ) ) {
				$out[ $tier ]['weight'] = max( 0, min( 100, (int) $row['weight'] ) );
			}
			if ( isset( $row['on'] ) ) {
				$out[ $tier ]['on'] = (bool) $row['on'];
			}
		}
		// The baseline cannot be switched off: every engine is asked at it, and
		// without it a run would have nothing to compare the others against.
		$out[ (string) TWTAEO_Visibility_Types::DEFAULT_TIER ]['on'] = true;

		return $out;
	}

	/**
	 * This site's mix for a market: what the owner saved, else the default.
	 *
	 * @param string|null $market Null reads the site's market.
	 * @return array tier => [ on => bool, weight => int ]
	 */
	public static function mix( $market = null ) {
		$market   = null === $market ? self::market() : self::clean_market( $market );
		$settings = TWTAEO_Visibility_Store::get_settings();
		$stored   = isset( $settings['tier_mix'][ $market ] ) ? $settings['tier_mix'][ $market ] : null;

		return self::clean_mix( $stored, $market );
	}

	/**
	 * Share of the audience each ASKED tier stands for, totalling 100.
	 *
	 * Only ticked tiers are counted, and they are renormalised between them:
	 * switching a tier off means "do not ask it", and the audience it stood
	 * for has to go somewhere rather than vanish from the denominator.
	 *
	 * @param string|null $market
	 * @param array|null  $mix    A mix to use instead of this site's (a stored run's).
	 * @return array tier => int, totalling 100
	 */
	public static function weights_for_market( $market = null, $mix = null ) {
		$mix   = is_array( $mix ) ? self::clean_mix( $mix, $market ) : self::mix( $market );
		$total = 0;
		$kept  = array();
		foreach ( TWTAEO_Visibility_Types::TIERS as $tier ) {
			if ( empty( $mix[ $tier ]['on'] ) ) {
				continue;
			}
			$kept[ $tier ] = max( 0, (int) $mix[ $tier ]['weight'] );
			$total        += $kept[ $tier ];
		}
		if ( empty( $kept ) ) {
			return array( (string) TWTAEO_Visibility_Types::DEFAULT_TIER => 100 );
		}
		if ( $total <= 0 ) {
			// Everything ticked but every weight zeroed: share it out evenly
			// rather than divide by nothing.
			$even = (int) floor( 100 / count( $kept ) );
			foreach ( $kept as $tier => $ignored ) {
				$kept[ $tier ] = $even;
			}
			$total = $even * count( $kept );
		}
		$out  = array();
		$sum  = 0;
		$last = '';
		foreach ( $kept as $tier => $weight ) {
			$out[ $tier ] = (int) round( $weight / $total * 100 );
			$sum         += $out[ $tier ];
			$last         = $tier;
		}
		// Rounding can leave the total at 99 or 101; put the difference on the
		// last row so the figures a reader adds up come to 100.
		if ( '' !== $last && 100 !== $sum ) {
			$out[ $last ] = max( 0, $out[ $last ] + ( 100 - $sum ) );
		}

		return $out;
	}

	/**
	 * Tiers a market asks, in TIERS order (strongest first) so a run's queue
	 * is stable.
	 *
	 * @param string|null $market Null reads the setting.
	 * @return string[]
	 */
	public static function tiers_for_market( $market = null ) {
		$mix = self::mix( $market );
		$out = array();
		foreach ( TWTAEO_Visibility_Types::TIERS as $tier ) {
			if ( ! empty( $mix[ $tier ]['on'] ) ) {
				$out[] = $tier;
			}
		}

		return empty( $out ) ? array( (string) TWTAEO_Visibility_Types::DEFAULT_TIER ) : $out;
	}

	/**
	 * Which ROW an engine's answer at this tier was recorded under.
	 *
	 * A tier that changes nothing for an engine was never asked separately,
	 * and that is not a gap: if paid and free run the same model with the same
	 * dial on Grok, then what a paid user sees on Grok IS the free row. So the
	 * audience share for that tier belongs on the baseline row, which is what
	 * this returns.
	 *
	 * @param string $engine
	 * @param string $tier
	 * @return string
	 */
	public static function row_tier_for( $engine, $tier ) {
		$tier = self::clean_tier( $tier );

		return self::differs( $engine, $tier ) ? $tier : (string) TWTAEO_Visibility_Types::DEFAULT_TIER;
	}

	/**
	 * Tiers worth asking THIS engine, for a market. A tier that would send an
	 * identical request is dropped, so a B2B site is never billed three times
	 * for three identical Mistral answers. The default tier is always present:
	 * an engine is never skipped altogether.
	 *
	 * @param string      $engine
	 * @param string|null $market
	 * @return string[]
	 */
	public static function tiers_for_engine( $engine, $market = null ) {
		$out = array();
		foreach ( self::tiers_for_market( $market ) as $tier ) {
			if ( self::differs( $engine, $tier ) ) {
				$out[] = $tier;
			}
		}
		if ( ! in_array( (string) TWTAEO_Visibility_Types::DEFAULT_TIER, $out, true ) ) {
			$out[] = (string) TWTAEO_Visibility_Types::DEFAULT_TIER;
		}

		// Back into TIERS order, so the queue is stable.
		$ordered = array();
		foreach ( TWTAEO_Visibility_Types::TIERS as $tier ) {
			if ( in_array( $tier, $out, true ) ) {
				$ordered[] = $tier;
			}
		}

		return $ordered;
	}

	/**
	 * Whether asking this engine at this tier produces a different request
	 * from the default tier — a different model, or a different reasoning
	 * dial. When it does not, there is nothing to measure and a second call
	 * would only cost a check.
	 *
	 * @param string $engine
	 * @param string $tier
	 * @return bool
	 */
	public static function differs( $engine, $tier ) {
		$engine = (string) $engine;
		$tier   = self::clean_tier( $tier );

		if ( (string) TWTAEO_Visibility_Types::DEFAULT_TIER === $tier ) {
			return true;
		}
		if ( 'throttled' === $tier ) {
			// Purely a model swap: real only where a lower model is known.
			return ! empty( TWTAEO_Visibility_Types::MODELS_THROTTLED[ $engine ] );
		}
		if ( 'paid' === $tier ) {
			// A different model, or the same one allowed to think harder.
			return ! empty( TWTAEO_Visibility_Types::MODELS_PAID[ $engine ] )
				|| in_array( $engine, TWTAEO_Visibility_Types::EFFORT_DIAL, true );
		}

		return false;
	}

	/* ─────────────────────────── requests ─────────────────────────── */

	/**
	 * Web-search cap for a tier, for the providers that take one.
	 *
	 * @param string $tier
	 * @return int
	 */
	public static function searches( $tier ) {
		$tier = self::clean_tier( $tier );

		return (int) TWTAEO_Visibility_Types::TIER_PROFILE[ $tier ]['searches'];
	}

	/**
	 * Reasoning effort for a tier, in the vocabulary shared by Anthropic's
	 * `output_config.effort` and OpenAI's `reasoning.effort`.
	 *
	 * @param string $tier
	 * @return string low | high
	 */
	public static function effort( $tier ) {
		$tier = self::clean_tier( $tier );

		return (string) TWTAEO_Visibility_Types::TIER_PROFILE[ $tier ]['effort'];
	}

	/**
	 * Whether this engine's reasoning dial should be turned up for this tier.
	 *
	 * Only `paid` ever turns it up, and only where the paid plan does NOT also
	 * change model. Where it does -- ChatGPT Plus moving to Sol, the Gemini app
	 * moving from Flash-Lite to Flash -- the model IS the difference, and
	 * asking that bigger model to think hard on top would describe somebody who
	 * went and turned reasoning up by hand, not somebody who simply pays.
	 *
	 * Claude is the engine this leaves dialled: Claude Pro runs the same
	 * Sonnet 5 as Claude Free and spends the money on thinking.
	 *
	 * @param string $engine
	 * @param string $tier
	 * @return bool
	 */
	public static function dial_up( $engine, $tier ) {
		return 'paid' === self::clean_tier( $tier )
			&& empty( TWTAEO_Visibility_Types::MODELS_PAID[ (string) $engine ] );
	}

	/**
	 * Anthropic's `output_config.effort` for a tier on an engine. Unlike the
	 * other two this always sends something, because the field was always sent;
	 * the tier only decides whether it reads low or high.
	 *
	 * @param string $tier
	 * @param string $engine
	 * @return string low | high
	 */
	public static function anthropic_effort( $tier, $engine = 'claude' ) {
		return self::dial_up( $engine, $tier ) ? self::effort( 'paid' ) : self::effort( 'free' );
	}

	/**
	 * Gemini's thinking level for a tier, or null to send nothing.
	 *
	 * Only `paid` sets the field, and only while Gemini has no paid MODEL to
	 * swap to -- see dial_up(). Before tiers existed this module sent no
	 * thinkingConfig at all and let the model's own default stand, which is
	 * what the consumer app does — so `free` and `throttled` keep sending
	 * nothing and every existing Gemini trend line holds.
	 *
	 * @param string $tier
	 * @return string|null
	 */
	public static function gemini_thinking_level( $tier, $engine = 'gemini' ) {
		return self::dial_up( $engine, $tier ) ? 'high' : null;
	}

	/**
	 * OpenAI reasoning effort for a tier, or null to send nothing. Same rule
	 * as Gemini's: only `paid`, and only when no paid model swap covers it.
	 *
	 * @param string $tier
	 * @return string|null
	 */
	public static function openai_effort( $tier, $engine = 'chatgpt' ) {
		return self::dial_up( $engine, $tier ) ? self::effort( 'paid' ) : null;
	}

	/**
	 * The model to probe an engine with at a tier.
	 *
	 * The consumer app's default unless this tier overrides it: `throttled`
	 * drops to the smaller model a free account gets once it is over quota,
	 * and `paid` only changes model on an engine that really does switch by
	 * plan (MODELS_PAID, empty until a live key has confirmed one).
	 *
	 * @param string $engine
	 * @param string $tier
	 * @return string Model ID, or '' for an unknown engine.
	 */
	public static function model_for( $engine, $tier ) {
		$engine = (string) $engine;
		$tier   = self::clean_tier( $tier );

		if ( 'throttled' === $tier && ! empty( TWTAEO_Visibility_Types::MODELS_THROTTLED[ $engine ] ) ) {
			return (string) TWTAEO_Visibility_Types::MODELS_THROTTLED[ $engine ];
		}
		if ( 'paid' === $tier && ! empty( TWTAEO_Visibility_Types::MODELS_PAID[ $engine ] ) ) {
			return (string) TWTAEO_Visibility_Types::MODELS_PAID[ $engine ];
		}

		return isset( TWTAEO_Visibility_Types::MODELS[ $engine ] )
			? (string) TWTAEO_Visibility_Types::MODELS[ $engine ]
			: '';
	}

	/**
	 * Exactly what this engine is called with at this tier: the model, and any
	 * reasoning dial or search cap that goes with it.
	 *
	 * This is the whole foundation of the feature, so it is readable in the
	 * interface rather than only in the source. Returns null where the tier
	 * sends an identical request to the baseline -- the caller says "same as
	 * free" rather than repeating a model id and implying a difference.
	 *
	 * @param string $engine
	 * @param string $tier
	 * @return array|null { model, notes[] }
	 */
	public static function describe( $engine, $tier ) {
		$engine = (string) $engine;
		$tier   = self::clean_tier( $tier );
		if ( (string) TWTAEO_Visibility_Types::DEFAULT_TIER !== $tier && ! self::differs( $engine, $tier ) ) {
			return null;
		}
		$notes = array();
		if ( 'claude' === $engine || 'deepseek' === $engine ) {
			$notes[] = sprintf( 'effort %s', self::anthropic_effort( $tier, $engine ) );
			$notes[] = sprintf( '%d searches', self::searches( $tier ) );
		} elseif ( 'chatgpt' === $engine ) {
			$effort  = self::openai_effort( $tier, $engine );
			$notes[] = null === $effort ? 'the model’s own reasoning default' : sprintf( 'effort %s', $effort );
		} elseif ( 'gemini' === $engine ) {
			$level   = self::gemini_thinking_level( $tier, $engine );
			$notes[] = null === $level ? 'the model’s own thinking default' : sprintf( 'thinking %s', $level );
		}

		return array(
			'model' => self::model_for( $engine, $tier ),
			'notes' => $notes,
		);
	}

	/**
	 * Checks one question costs across every engine, at this market's tiers.
	 * The run queue and the Settings estimate both count the same way.
	 *
	 * @param string[]    $engines Engine ids with a key.
	 * @param string|null $market
	 * @return int
	 */
	public static function steps_per_question( array $engines, $market = null ) {
		$steps = 0;
		foreach ( $engines as $engine ) {
			$steps += count( self::tiers_for_engine( (string) $engine, $market ) );
		}

		return $steps;
	}
}
