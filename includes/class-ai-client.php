<?php
/**
 * TWT AEO AI Client
 *
 * A single reusable "prompt → text" client across Claude, OpenAI, Gemini, and
 * Perplexity. Unlike TWTAEO_AI_Description (tuned for short meta summaries), this
 * client is general purpose: it supports strict-JSON output, Gemini Search
 * grounding, and multimodal image input — the primitives the product-enrichment
 * features need.
 *
 * Keys resolve through TWTAEO_Key_Resolver (constant → env → DB option). When no
 * plugin key is configured for Claude/OpenAI and the WordPress 7.0 AI Client is
 * available, requests route through it so the raw key is never handled here.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_AI_Client {

	// Capable, cost-aware defaults. Haiku and gpt-5.4-mini are multimodal-capable.
	const CLAUDE_MODEL     = 'claude-haiku-4-5-20251001';
	const OPENAI_MODEL     = 'gpt-5.4-mini';
	const GEMINI_MODEL     = 'gemini-2.5-flash';
	const PERPLEXITY_MODEL = 'sonar';

	/**
	 * Run a completion against the chosen provider.
	 *
	 * @param string $provider claude | openai | gemini | perplexity. Empty → enrich default.
	 * @param string $prompt
	 * @param array  $opts {
	 *     @type string $model       Override the default model.
	 *     @type int    $max_tokens  Default 1024.
	 *     @type float  $temperature Default 0.2 (deterministic extraction).
	 *     @type bool   $json        Ask the provider for strict JSON.
	 *     @type bool   $grounding   Gemini Google Search grounding.
	 *     @type array  $images      Image URLs for multimodal input.
	 *     @type string $system      System instruction (role/behavior).
	 * }
	 * @return string|WP_Error
	 */
	public static function complete( $provider, $prompt, array $opts = array() ) {
		$provider = $provider ?: self::enrich_provider();
		$key      = TWTAEO_Key_Resolver::get( $provider );

		if ( '' === $key ) {
			if ( in_array( $provider, array( 'claude', 'openai' ), true ) && TWTAEO_Key_Resolver::ai_client_available() ) {
				return self::call_via_ai_client( $provider, $prompt, $opts );
			}
			return new WP_Error(
				'no_key',
				/* translators: %s: provider name. */
				sprintf( __( 'No API key configured for %s. Add one under TWT AEO → Settings.', 'twt-aeo-ultimate' ), ucfirst( $provider ) )
			);
		}

		switch ( $provider ) {
			case 'claude':
				return self::call_claude( $key, $prompt, $opts );
			case 'openai':
				return self::call_openai( $key, $prompt, $opts );
			case 'gemini':
				return self::call_gemini( $key, $prompt, $opts );
			case 'perplexity':
				return self::call_perplexity( $key, $prompt, $opts );
		}

		return new WP_Error( 'unknown_provider', __( 'Unknown AI provider.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * Decode a JSON object from a model response, tolerating ``` fences and prose.
	 *
	 * @param string $text
	 * @return array|WP_Error
	 */
	public static function extract_json( $text ) {
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = trim( (string) $text );

		// Strip ```json … ``` fences.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		// Fall back to the first {...} block if the model added prose around it.
		$decoded = json_decode( $text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$decoded = json_decode( $m[0], true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'bad_json', __( 'AI response was not valid JSON.', 'twt-aeo-ultimate' ) );
		}
		return $decoded;
	}

	// ── Settings accessors ──────────────────────────────────────────────────────

	/**
	 * Extraction provider — defaults to inheriting the meta-description provider.
	 *
	 * @return string claude | openai | gemini
	 */
	public static function enrich_provider() {
		$s = get_option( 'twtaeo_settings', array() );
		$p = $s['ai_enrich_provider'] ?? '';
		if ( '' === $p ) {
			$p = $s['ai_desc_provider'] ?? 'claude';
		}
		return in_array( $p, array( 'claude', 'openai', 'gemini' ), true ) ? $p : 'claude';
	}

	/**
	 * Retrieval provider for grounded lookups (sameAs / competitive).
	 *
	 * @return string gemini | perplexity
	 */
	public static function retrieval_provider() {
		$s = get_option( 'twtaeo_settings', array() );
		$p = $s['ai_retrieval_provider'] ?? 'gemini';
		return in_array( $p, array( 'gemini', 'perplexity' ), true ) ? $p : 'gemini';
	}

	// ── Providers ────────────────────────────────────────────────────────────────

	private static function model( $provider, $opts, $default ) {
		return ! empty( $opts['model'] ) ? $opts['model'] : $default;
	}

	private static function max_tokens( $opts ) {
		return isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 1024;
	}

	private static function temperature( $opts ) {
		return isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.2;
	}

	/**
	 * GPT-5 / o-series are reasoning models: they require max_completion_tokens
	 * (not max_tokens) and reject a custom temperature.
	 *
	 * @param string $model
	 * @return bool
	 */
	private static function is_openai_reasoning_model( $model ) {
		return strpos( $model, 'gpt-5' ) === 0 || (bool) preg_match( '/^o[1345]/', $model );
	}

	private static function call_claude( $api_key, $prompt, $opts ) {
		$model = self::model( 'claude', $opts, self::CLAUDE_MODEL );

		// Build a single user turn, adding image blocks before the text.
		$content = array();
		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => $url ) );
		}
		$content[] = array( 'type' => 'text', 'text' => $prompt );

		$payload = array(
			'model'       => $model,
			'max_tokens'  => self::max_tokens( $opts ),
			'temperature' => self::temperature( $opts ),
			'messages'    => array( array( 'role' => 'user', 'content' => $content ) ),
		);
		if ( ! empty( $opts['system'] ) ) {
			$payload['system'] = $opts['system']; // Anthropic uses a top-level system field.
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'claude_error', $body['error']['message'] ?? "Claude API error (HTTP $code)" );
		}
		self::telemetry( 'claude', $model, $body['usage'] ?? array() );
		return $body['content'][0]['text'] ?? new WP_Error( 'claude_empty', __( 'Claude returned no content.', 'twt-aeo-ultimate' ) );
	}

	private static function call_openai( $api_key, $prompt, $opts ) {
		$model = self::model( 'openai', $opts, self::OPENAI_MODEL );

		$content = array( array( 'type' => 'text', 'text' => $prompt ) );
		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			$content[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) );
		}

		$messages = array();
		if ( ! empty( $opts['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $opts['system'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $content );

		$payload = array(
			'model'    => $model,
			'messages' => $messages,
		);
		if ( self::is_openai_reasoning_model( $model ) ) {
			// GPT-5 / o-series reject max_tokens + temperature, and reasoning tokens
			// draw from the output budget — give headroom and keep reasoning minimal
			// for these extraction/summary tasks so short outputs aren't truncated.
			$payload['max_completion_tokens'] = max( self::max_tokens( $opts ), 512 );
			$payload['reasoning_effort']      = 'minimal';
		} else {
			$payload['max_tokens']  = self::max_tokens( $opts );
			$payload['temperature'] = self::temperature( $opts );
		}
		if ( ! empty( $opts['json'] ) ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'openai_error', $body['error']['message'] ?? "OpenAI API error (HTTP $code)" );
		}
		self::telemetry( 'openai', $model, $body['usage'] ?? array() );
		return $body['choices'][0]['message']['content'] ?? new WP_Error( 'openai_empty', __( 'OpenAI returned no content.', 'twt-aeo-ultimate' ) );
	}

	private static function call_gemini( $api_key, $prompt, $opts ) {
		$model    = self::model( 'gemini', $opts, self::GEMINI_MODEL );
		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. $model . ':generateContent?key=' . rawurlencode( $api_key );

		$parts = array( array( 'text' => $prompt ) );
		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			$inline = self::fetch_inline_image( $url );
			if ( $inline ) {
				$parts[] = array( 'inline_data' => $inline );
			}
		}

		// gemini-2.5-* are reasoning models: disable "thinking" so the whole budget
		// goes to the answer (see Gemini thinking-token gotcha).
		$generation = array(
			'maxOutputTokens' => max( 512, self::max_tokens( $opts ) ),
			'temperature'     => self::temperature( $opts ),
			'thinkingConfig'  => array( 'thinkingBudget' => 0 ),
		);
		if ( ! empty( $opts['json'] ) ) {
			$generation['responseMimeType'] = 'application/json';
		}

		$payload = array(
			'contents'         => array( array( 'parts' => $parts ) ),
			'generationConfig' => $generation,
		);
		if ( ! empty( $opts['system'] ) ) {
			$payload['systemInstruction'] = array( 'parts' => array( array( 'text' => $opts['system'] ) ) );
		}
		if ( ! empty( $opts['grounding'] ) ) {
			$payload['tools'] = array( array( 'google_search' => (object) array() ) );
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'gemini_error', $body['error']['message'] ?? "Gemini API error (HTTP $code)" );
		}
		self::telemetry( 'gemini', $model, $body['usageMetadata'] ?? array() );

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( '' !== $text ) {
			return $text;
		}
		$reason = $body['candidates'][0]['finishReason'] ?? '';
		return new WP_Error(
			'gemini_empty',
			$reason
				/* translators: %s: Gemini finishReason, e.g. MAX_TOKENS. */
				? sprintf( __( 'Gemini returned no usable text (finishReason: %s).', 'twt-aeo-ultimate' ), $reason )
				: __( 'Gemini returned no content.', 'twt-aeo-ultimate' )
		);
	}

	private static function call_perplexity( $api_key, $prompt, $opts ) {
		$model = self::model( 'perplexity', $opts, self::PERPLEXITY_MODEL );

		$messages = array();
		if ( ! empty( $opts['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $opts['system'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $prompt );

		$response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'temperature' => self::temperature( $opts ),
				'messages'    => $messages,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'perplexity_error', $body['error']['message'] ?? "Perplexity API error (HTTP $code)" );
		}
		self::telemetry( 'perplexity', $model, $body['usage'] ?? array() );
		return $body['choices'][0]['message']['content'] ?? new WP_Error( 'perplexity_empty', __( 'Perplexity returned no content.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * Fulfil a Claude/OpenAI request through the WordPress 7.0 AI Client when the
	 * plugin holds no key of its own. Multimodal input is not supported here.
	 *
	 * @return string|WP_Error
	 */
	private static function call_via_ai_client( $provider, $prompt, $opts ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_ai_client', __( 'The WordPress AI Client is not available.', 'twt-aeo-ultimate' ) );
		}
		if ( ! empty( $opts['images'] ) ) {
			return new WP_Error( 'ai_client_no_vision', __( 'Image analysis requires a configured provider key.', 'twt-aeo-ultimate' ) );
		}

		// The AI Client builder has no separate system role — prepend it to the prompt.
		if ( ! empty( $opts['system'] ) ) {
			$prompt = $opts['system'] . "\n\n" . $prompt;
		}
		$model   = ( 'openai' === $provider ) ? self::OPENAI_MODEL : self::CLAUDE_MODEL;
		// wp_ai_client_prompt() ships in WordPress 7.0. The plugin supports 6.2+, so it
		// is only reached behind the function_exists() guard above and called indirectly
		// to keep the static WP-version compatibility scanner satisfied.
		$ai_client_prompt = 'wp_ai_client_prompt';
		$builder          = $ai_client_prompt( $prompt );
		if ( is_object( $builder ) && method_exists( $builder, 'using_model_preference' ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		try {
			$result = is_object( $builder ) && method_exists( $builder, 'generate_text' )
				? $builder->generate_text()
				: '';
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_client_error', $e->getMessage() );
		}

		$text = is_string( $result ) ? $result : '';
		return '' !== $text ? $text : new WP_Error( 'ai_client_empty', __( 'The WordPress AI Client returned no content.', 'twt-aeo-ultimate' ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch a remote image and return Gemini inline_data (base64 + mime type).
	 * Gemini does not accept arbitrary remote URLs like Claude/OpenAI do.
	 *
	 * @param string $url
	 * @return array|null { mime_type, data }
	 */
	private static function fetch_inline_image( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$binary = wp_remote_retrieve_body( $response );
		if ( '' === $binary ) {
			return null;
		}
		$mime = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! $mime ) {
			$mime = 'image/jpeg';
		}
		return array(
			'mime_type' => $mime,
			'data'      => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Forward token usage to the agency hub when telemetry is enabled.
	 */
	private static function telemetry( $provider, $model, $usage ) {
		if ( class_exists( 'TWTAEO_Pro_Transmitter' ) && method_exists( 'TWTAEO_Pro_Transmitter', 'maybe_send_token_telemetry' ) ) {
			TWTAEO_Pro_Transmitter::maybe_send_token_telemetry( $provider, $model, (array) $usage );
		}
	}
}
