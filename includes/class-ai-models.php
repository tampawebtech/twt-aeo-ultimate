<?php
/**
 * AI Models — which model each provider runs, and how to talk to it.
 *
 * One place for the model IDs the plugin's everyday AI work uses (meta
 * descriptions, enrichment, extraction), the choices offered in Settings, and
 * the per-family request rules the providers now enforce.
 *
 * Those rules are why this class exists. Each provider's newest family rejects
 * a parameter the previous one required, with a 400 rather than a warning:
 *
 *   - Claude 5 (and Opus 4.7/4.8) reject `temperature`, and Claude 5 thinks by
 *     default, so a small `max_tokens` is spent before any text is written.
 *   - GPT-6 rejects `reasoning_effort: minimal`; its floor is `none`.
 *   - Gemini 3.x ignores `thinkingBudget`; 3.7/3.8 reject `thinkingLevel:
 *     minimal` and take `low`. Gemini 2.5 is closed to accounts that have not
 *     already used it, so it cannot be a default for a new install.
 *
 * Upgrades are gentle: a site that already had a key for a provider is pinned
 * to the model it was running before this class existed, so nothing it
 * generates changes under it. New installs, and providers configured later,
 * get the current defaults. Either way the owner can pick another model, or
 * type in an ID released after this build, under Settings → AI Models.
 *
 * AI Visibility deliberately does NOT use this: its probing models mirror each
 * consumer app's default, which is a measurement choice, not a preference.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_AI_Models {

	/** Providers with a selectable model. */
	const PROVIDERS = array( 'claude', 'openai', 'gemini' );

	/** Defaults for new installs. Researched 2026-09-24; cheap, fast tiers. */
	const DEFAULTS = array(
		'claude' => 'claude-haiku-4-5',
		'openai' => 'gpt-6-luna',
		'gemini' => 'gemini-3.6-flash',
	);

	/** What every install ran through 2.23.0 — the pin for existing sites. */
	const LEGACY = array(
		'claude' => 'claude-haiku-4-5-20251001',
		'openai' => 'gpt-5.4-mini',
		'gemini' => 'gemini-2.5-flash',
	);

	/** Settings key holding the chosen model for a provider: ai_model_{provider}. */
	const SETTING_PREFIX = 'ai_model_';

	/** Settings flag: the one-time legacy pin has run. */
	const SETTING_PINNED = 'ai_models_pinned';

	/** Longest model ID accepted from the "Other" field. */
	const MAX_ID_LENGTH = 80;

	private function __construct() {}

	/**
	 * Models offered in the Settings dropdown, per provider, cheapest first.
	 *
	 * @return array<string,array<string,string>> provider => [ model ID => label ]
	 */
	public static function choices() {
		return array(
			'claude' => array(
				'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 — fastest, lowest cost (recommended)', 'twt-aeo-ultimate' ),
				'claude-sonnet-5'   => __( 'Claude Sonnet 5 — stronger writing, about 2× the cost', 'twt-aeo-ultimate' ),
				'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 — previous Sonnet', 'twt-aeo-ultimate' ),
				'claude-opus-5'     => __( 'Claude Opus 5 — most capable, highest cost', 'twt-aeo-ultimate' ),
			),
			'openai' => array(
				'gpt-6-luna'   => __( 'GPT-6 Luna — fastest, lowest cost (recommended)', 'twt-aeo-ultimate' ),
				'gpt-6.1-sol'  => __( 'GPT-6.1 Sol — near-Astra quality, higher cost', 'twt-aeo-ultimate' ),
				'gpt-6-sol'    => __( 'GPT-6 Sol — previous Sol', 'twt-aeo-ultimate' ),
				'gpt-6-astra'  => __( 'GPT-6 Astra — most capable, highest cost', 'twt-aeo-ultimate' ),
				'gpt-5.6-luna' => __( 'GPT-5.6 Luna — previous generation', 'twt-aeo-ultimate' ),
				'gpt-5.4-mini' => __( 'GPT-5.4 mini — used by this plugin through 2.23', 'twt-aeo-ultimate' ),
			),
			'gemini' => array(
				'gemini-3.6-flash'      => __( 'Gemini 3.6 Flash — balanced (recommended)', 'twt-aeo-ultimate' ),
				'gemini-3.5-flash-lite' => __( 'Gemini 3.5 Flash-Lite — lowest cost', 'twt-aeo-ultimate' ),
				'gemini-3.8-flash'      => __( 'Gemini 3.8 Flash — newest, most capable Flash', 'twt-aeo-ultimate' ),
				'gemini-3.7-flash'      => __( 'Gemini 3.7 Flash', 'twt-aeo-ultimate' ),
				'gemini-3.5-flash'      => __( 'Gemini 3.5 Flash', 'twt-aeo-ultimate' ),
				'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash — legacy; only for accounts already using it', 'twt-aeo-ultimate' ),
			),
		);
	}

	/**
	 * The model this site runs for a provider.
	 *
	 * @param string $provider claude | openai | gemini.
	 * @return string Model ID. Empty for an unknown provider.
	 */
	public static function get( $provider ) {
		if ( ! isset( self::DEFAULTS[ $provider ] ) ) {
			return '';
		}

		self::maybe_pin_legacy();

		$settings = get_option( 'twtaeo_settings', array() );
		$chosen   = is_array( $settings ) ? (string) ( $settings[ self::SETTING_PREFIX . $provider ] ?? '' ) : '';

		return self::is_valid_id( $chosen ) ? $chosen : self::DEFAULTS[ $provider ];
	}

	/**
	 * Accept a model ID from the Settings form. Anything that is not a plausible
	 * ID comes back empty, which means "use the default".
	 *
	 * @param string $id Raw posted value.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		$id = strtolower( trim( (string) $id ) );

		return self::is_valid_id( $id ) ? $id : '';
	}

	/**
	 * Model IDs are short slugs: letters, digits, dots, dashes, and the "@" or
	 * "/" some platforms put in versioned names. Anything else is a typo.
	 */
	private static function is_valid_id( $id ) {
		return '' !== $id
			&& strlen( $id ) <= self::MAX_ID_LENGTH
			&& (bool) preg_match( '/^[a-z0-9][a-z0-9._@\/-]*$/i', $id );
	}

	/**
	 * One-time, on the first model lookup after upgrading: pin each provider
	 * that already has a key to the model it ran before, so an upgrade never
	 * silently changes a site's output or its bill.
	 *
	 * A provider with no key is left on the new default — nothing was running
	 * on it, and Gemini 2.5 would refuse a key that never used it. Fresh installs
	 * are marked pinned by the activator, so they start on the new defaults.
	 */
	private static function maybe_pin_legacy() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$settings = get_option( 'twtaeo_settings', array() );
		if ( ! is_array( $settings ) || ! empty( $settings[ self::SETTING_PINNED ] ) ) {
			return;
		}

		foreach ( self::LEGACY as $provider => $model ) {
			$key = self::SETTING_PREFIX . $provider;
			if ( empty( $settings[ $key ] ) && '' !== TWTAEO_Key_Resolver::get( $provider ) ) {
				$settings[ $key ] = $model;
			}
		}
		$settings[ self::SETTING_PINNED ] = 1;

		update_option( 'twtaeo_settings', $settings );
	}

	/* ───────────────────────── request rules ───────────────────────── */

	/**
	 * Claude generation as [major, minor], e.g. claude-opus-4-8 → [4, 8],
	 * claude-sonnet-5 → [5, 0]. A trailing date snapshot is not a minor
	 * version. Unknown shapes read as [0, 0], the permissive old behaviour.
	 *
	 * @param string $model
	 * @return int[]
	 */
	private static function claude_version( $model ) {
		if ( preg_match( '/^claude-[a-z]+-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $model, $m ) ) {
			return array( (int) $m[1], isset( $m[2] ) ? (int) $m[2] : 0 );
		}

		return array( 0, 0 );
	}

	/**
	 * Claude 5 and Opus 4.7/4.8 return a 400 on temperature, top_p or top_k.
	 *
	 * @param string $model
	 * @return bool
	 */
	public static function claude_accepts_temperature( $model ) {
		list( $major, $minor ) = self::claude_version( $model );

		return $major < 4 || ( 4 === $major && $minor < 7 );
	}

	/**
	 * Extra request fields that keep Claude's answer inside a small token budget.
	 *
	 * Claude 5 thinks by default, and the thinking draws from max_tokens — a
	 * 200-token description budget would be spent before a word of it was
	 * written. Sonnet 5 and Opus 5 can switch thinking off; later models
	 * cannot, so they get the lowest effort instead.
	 *
	 * @param string $model
	 * @return array Fields to merge into the Messages payload.
	 */
	public static function claude_thinking_fields( $model ) {
		list( $major ) = self::claude_version( $model );
		if ( $major < 5 ) {
			return array(); // Thinking is off unless asked for.
		}
		if ( in_array( $model, array( 'claude-sonnet-5', 'claude-opus-5' ), true ) ) {
			return array( 'thinking' => array( 'type' => 'disabled' ) );
		}

		return array( 'output_config' => array( 'effort' => 'low' ) );
	}

	/**
	 * Smallest max_tokens worth sending. Models that must think need room to
	 * answer after they have.
	 *
	 * @param string $provider
	 * @param string $model
	 * @param int    $requested
	 * @return int
	 */
	public static function min_output_tokens( $provider, $model, $requested ) {
		$floor = 0;
		if ( 'claude' === $provider ) {
			list( $major ) = self::claude_version( $model );
			if ( $major >= 5 && ! isset( self::claude_thinking_fields( $model )['thinking'] ) ) {
				$floor = 2048;
			}
		} elseif ( 'gemini' === $provider && 'low' === self::gemini_thinking_level( $model ) ) {
			$floor = 2048;
		}

		return max( (int) $requested, $floor );
	}

	/**
	 * Lowest reasoning effort an OpenAI reasoning model accepts, for short
	 * extraction and summary work. Null means send none at all.
	 *
	 * GPT-6 takes none…max; GPT-5.x keeps the "minimal" this plugin always sent.
	 * An unknown model that rejects the value is retried without it by the
	 * caller, so a wrong guess here costs one extra request, not a failure.
	 *
	 * @param string $model
	 * @return string|null
	 */
	public static function openai_reasoning_effort( $model ) {
		// GPT-6.1 onward: low, medium, high, xhigh, max — "none" and
		// "minimal" are refused, so low is the floor. GPT-6.0 takes none.
		if ( preg_match( '/^gpt-(\d+)(?:\.(\d+))?/', $model, $m ) && ( (int) $m[1] > 6 || ( 6 === (int) $m[1] && isset( $m[2] ) && (int) $m[2] >= 1 ) ) ) {
			return 'low';
		}
		if ( preg_match( '/^gpt-(\d+)/', $model, $m ) && (int) $m[1] >= 6 ) {
			return 'none';
		}
		if ( 0 === strpos( $model, 'gpt-5' ) || preg_match( '/^o[1345]/', $model ) ) {
			return 'minimal';
		}

		return null;
	}

	/**
	 * GPT-5 onward and the o-series are reasoning models: max_completion_tokens,
	 * and no custom temperature.
	 *
	 * @param string $model
	 * @return bool
	 */
	public static function openai_is_reasoning( $model ) {
		return ( preg_match( '/^gpt-(\d+)/', $model, $m ) && (int) $m[1] >= 5 )
			|| (bool) preg_match( '/^o[1345]/', $model );
	}

	/**
	 * Gemini thinking level to request, or null for the 2.x family, which is
	 * controlled by thinkingBudget instead.
	 *
	 * 3.5, 3.6 and the Flash-Lite line accept "minimal". 3.7, 3.8, Pro, and
	 * anything newer this build has not seen take "low" as their floor.
	 *
	 * @param string $model
	 * @return string|null
	 */
	public static function gemini_thinking_level( $model ) {
		if ( ! preg_match( '/^gemini-(\d+)(?:\.(\d+))?/', $model, $m ) || (int) $m[1] < 3 ) {
			return null;
		}
		$minor = isset( $m[2] ) ? (int) $m[2] : 0;

		if ( false !== strpos( $model, 'flash-lite' ) ) {
			return 'minimal';
		}
		if ( 3 === (int) $m[1] && $minor <= 6 && false === strpos( $model, '-pro' ) ) {
			return 'minimal';
		}

		return 'low';
	}

	/**
	 * The generationConfig fields that control thinking and sampling.
	 *
	 * @param string $model
	 * @param float  $temperature
	 * @return array
	 */
	public static function gemini_generation_fields( $model, $temperature ) {
		$level = self::gemini_thinking_level( $model );
		if ( null === $level ) {
			// 2.x: switch thinking off so the whole budget goes to the answer.
			return array(
				'temperature'    => (float) $temperature,
				'thinkingConfig' => array( 'thinkingBudget' => 0 ),
			);
		}

		// 3.x: Google advises leaving temperature at its default.
		return array( 'thinkingConfig' => array( 'thinkingLevel' => $level ) );
	}

	/**
	 * Whether an API error is the provider refusing a parameter we chose from
	 * this class's rules — worth one retry without it.
	 *
	 * @param string $message Provider error message.
	 * @param string $param   Parameter name as it appears in the error.
	 * @return bool
	 */
	public static function is_param_rejection( $message, $param ) {
		return false !== stripos( (string) $message, $param );
	}
}
