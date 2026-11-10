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

	// Claude, OpenAI and Gemini models are the site's choice — see TWTAEO_AI_Models.

	// Perplexity's Agent API preset. "fast" is Perplexity's documented stand-in
	// for the Sonar model, retired with the Sonar API on 2026-09-27.
	const PERPLEXITY_PRESET   = 'fast';

	/** OpenRouter speaks OpenAI's chat API, so one key reaches many vendors. */
	const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

	/**
	 * Largest raw image sent inline. Base64 grows it by about a third, which
	 * keeps the encoded payload inside the providers' per-image limits.
	 */
	const MAX_INLINE_IMAGE_BYTES = 3500000;
	const PERPLEXITY_ENDPOINT = 'https://api.perplexity.ai/v1/agent';

	// Set when a grounded Gemini 3 call fails for tier/entitlement reasons —
	// Search grounding on Gemini 3 needs a paid-tier key, while gemini-2.5-flash
	// still grounds on the free tier. Routes later grounded calls straight to
	// the 2.5 fallback for a day instead of re-failing on 3.x first.
	const GROUNDING_FALLBACK_FLAG  = 'twtaeo_gemini3_grounding_fallback';
	const GROUNDING_FALLBACK_MODEL = 'gemini-2.5-flash';

	/**
	 * Run a completion against the chosen provider.
	 *
	 * @param string $provider claude | openai | gemini | perplexity | openrouter.
	 *                         Empty → enrich default.
	 * @param string $prompt
	 * @param array  $opts {
	 *     @type string $model       Override the default model.
	 *     @type int    $max_tokens  Default 1024.
	 *     @type float  $temperature Default 0.2 (deterministic extraction).
	 *     @type bool   $json        Ask the provider for strict JSON.
	 *     @type bool   $grounding   Gemini Google Search grounding.
	 *     @type array  $images      Image URLs for multimodal input.
	 *     @type string $system      System instruction (role/behavior).
	 *     @type int    $timeout     Request timeout in seconds. Default 60. Callers
	 *                               with their own budget pass it: a 5-token yes/no
	 *                               on a page load must not sit for a minute.
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
			case 'openrouter':
				return self::call_openrouter( $key, $prompt, $opts );
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
		$p = (string) ( $s['ai_enrich_provider'] ?? '' );

		// OpenRouter counts here: enrichment is a writing and extraction job,
		// which is exactly what it is for. Perplexity still does not, because
		// its grounded-answer endpoint is a different shape from the chat
		// completion this expects.
		if ( in_array( $p, array( 'claude', 'openai', 'gemini', 'openrouter' ), true ) ) {
			return $p;
		}

		// Nothing chosen for enrichment: inherit the meta-description provider,
		// which now resolves to one that actually holds a key rather than
		// defaulting to Claude on a site that has no Claude key. Perplexity is
		// left out here on purpose: enrichment pulls structured facts out of
		// page text, which is not what its grounded-answer endpoint is for.
		$inherited = class_exists( 'TWTAEO_AI_Description' )
			? TWTAEO_AI_Description::get_provider()
			: 'claude';

		return in_array( $inherited, array( 'claude', 'openai', 'gemini', 'openrouter' ), true ) ? $inherited : 'claude';
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

	/** An explicit per-call model wins; otherwise the site's choice in Settings. */
	private static function model( $provider, $opts ) {
		return ! empty( $opts['model'] ) ? $opts['model'] : TWTAEO_AI_Models::get( $provider );
	}

	private static function max_tokens( $opts ) {
		return isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 1024;
	}

	/** Seconds to wait. Callers that had their own budget keep it. */
	private static function timeout( $opts ) {
		$t = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 60;
		return $t > 0 ? $t : 60;
	}

	private static function temperature( $opts ) {
		return isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.2;
	}

	private static function call_claude( $api_key, $prompt, $opts ) {
		$model = self::model( 'claude', $opts );

		// Build a single user turn, adding image blocks before the text.
		$content = array();
		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			$source = self::image_source( $url );
			$content[] = array(
				'type'   => 'image',
				'source' => isset( $source['inline'] )
					? array(
						'type'       => 'base64',
						'media_type' => $source['inline']['mime_type'],
						'data'       => $source['inline']['data'],
					)
					: array( 'type' => 'url', 'url' => $source['url'] ),
			);
		}
		$content[] = array( 'type' => 'text', 'text' => $prompt );

		$payload = array(
			'model'      => $model,
			'max_tokens' => TWTAEO_AI_Models::min_output_tokens( 'claude', $model, self::max_tokens( $opts ) ),
			'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
		);
		if ( TWTAEO_AI_Models::claude_accepts_temperature( $model ) ) {
			$payload['temperature'] = self::temperature( $opts );
		}
		$payload += TWTAEO_AI_Models::claude_thinking_fields( $model );
		if ( ! empty( $opts['system'] ) ) {
			$payload['system'] = $opts['system']; // Anthropic uses a top-level system field.
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => self::timeout( $opts ),
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

		// Thinking models put a thinking block ahead of the answer — take the
		// first text block, not the first block.
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				return $block['text'];
			}
		}
		return new WP_Error( 'claude_empty', __( 'Claude returned no content.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * The messages array for an OpenAI-shaped chat API.
	 *
	 * Shared by OpenAI and OpenRouter, which speaks the same wire format. The
	 * image handling in particular belongs in one place: a second copy is how
	 * one caller quietly keeps sending unreachable URLs after the other is
	 * fixed.
	 *
	 * @param string $prompt
	 * @param array  $opts
	 * @return array
	 */
	private static function openai_style_messages( $prompt, $opts ) {
		$content = array( array( 'type' => 'text', 'text' => $prompt ) );

		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			$source = self::image_source( $url );
			// Both take a data: URI in the same field as a real one.
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => isset( $source['inline'] )
						? 'data:' . $source['inline']['mime_type'] . ';base64,' . $source['inline']['data']
						: $source['url'],
				),
			);
		}

		$messages = array();
		if ( ! empty( $opts['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $opts['system'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $content );

		return $messages;
	}

	private static function call_openai( $api_key, $prompt, $opts ) {
		$model    = self::model( 'openai', $opts );
		$messages = self::openai_style_messages( $prompt, $opts );

		$payload = array(
			'model'    => $model,
			'messages' => $messages,
		);
		if ( TWTAEO_AI_Models::openai_is_reasoning( $model ) ) {
			// Reasoning models reject max_tokens + temperature, and reasoning tokens
			// draw from the output budget — give headroom and hold reasoning at the
			// model's floor for these extraction/summary tasks.
			$payload['max_completion_tokens'] = max( self::max_tokens( $opts ), 512 );
			$effort                           = TWTAEO_AI_Models::openai_reasoning_effort( $model );
			if ( null !== $effort ) {
				$payload['reasoning_effort'] = $effort;
			}
		} else {
			$payload['max_tokens']  = self::max_tokens( $opts );
			$payload['temperature'] = self::temperature( $opts );
		}
		if ( ! empty( $opts['json'] ) ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		$request = static function ( $payload ) use ( $api_key, $opts ) {
			return wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
				'timeout' => self::timeout( $opts ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			) );
		};

		$response = $request( $payload );

		// A model newer than this build may not take the effort we guessed.
		// One retry without it beats failing a model the user chose on purpose.
		if ( isset( $payload['reasoning_effort'] ) && ! is_wp_error( $response ) && 400 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$rejected = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( TWTAEO_AI_Models::is_param_rejection( $rejected['error']['message'] ?? '', 'reasoning' ) ) {
				unset( $payload['reasoning_effort'] );
				$response = $request( $payload );
			}
		}

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
		$model = self::model( 'gemini', $opts );

		// Grounded calls on a 3.x model: if a previous attempt failed for
		// tier/entitlement reasons, skip straight to the free-tier-capable
		// fallback model instead of re-failing first.
		if ( ! empty( $opts['grounding'] )
			&& empty( $opts['_grounding_retry'] )
			&& 0 === strpos( $model, 'gemini-3' )
			&& get_transient( self::GROUNDING_FALLBACK_FLAG ) ) {
			$model = self::GROUNDING_FALLBACK_MODEL;
		}

		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. $model . ':generateContent?key=' . rawurlencode( $api_key );

		$parts = array( array( 'text' => $prompt ) );
		foreach ( (array) ( $opts['images'] ?? array() ) as $url ) {
			// Gemini has no "go and fetch this URL" form here, so an image it
			// cannot read inline is left out rather than named and missing.
			$source = self::image_source( $url );
			if ( isset( $source['inline'] ) ) {
				$parts[] = array( 'inline_data' => $source['inline'] );
			}
		}

		// Gemini models think by default and the thinking draws from
		// maxOutputTokens — hold it to the model's floor (see TWTAEO_AI_Models).
		$generation = array(
			'maxOutputTokens' => TWTAEO_AI_Models::min_output_tokens( 'gemini', $model, max( 512, self::max_tokens( $opts ) ) ),
		) + TWTAEO_AI_Models::gemini_generation_fields( $model, self::temperature( $opts ) );
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

		$request = static function ( $payload ) use ( $endpoint, $opts ) {
			return wp_remote_post( $endpoint, array(
				'timeout' => self::timeout( $opts ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			) );
		};

		$response = $request( $payload );

		// A Gemini release newer than this build may not take the thinking level
		// we guessed. Retry once on the model's own default.
		if ( ! is_wp_error( $response ) && 400 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$rejected = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( TWTAEO_AI_Models::is_param_rejection( $rejected['error']['message'] ?? '', 'thinking' ) ) {
				unset( $payload['generationConfig']['thinkingConfig'] );
				$response = $request( $payload );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = $body['error']['message'] ?? "Gemini API error (HTTP $code)";

			// Grounding on Gemini 3 requires a paid-tier key. When a grounded 3.x
			// call fails with an entitlement-type error, retry once on 2.5 Flash —
			// which still grounds on the free tier for accounts that have used
			// it — and remember a hard entitlement failure (400/403) so the next
			// day's calls skip the doomed first attempt. 429s fall back per call
			// only: they may just be a rate-limit burst.
			if ( ! empty( $opts['grounding'] )
				&& empty( $opts['_grounding_retry'] )
				&& 0 === strpos( $model, 'gemini-3' )
				&& in_array( (int) $code, array( 400, 403, 429 ), true ) ) {
				$opts['model']            = self::GROUNDING_FALLBACK_MODEL;
				$opts['_grounding_retry'] = true;
				$retry                    = self::call_gemini( $api_key, $prompt, $opts );
				if ( ! is_wp_error( $retry ) ) {
					if ( in_array( (int) $code, array( 400, 403 ), true ) ) {
						set_transient( self::GROUNDING_FALLBACK_FLAG, $msg, DAY_IN_SECONDS );
					}
					if ( class_exists( 'TWTAEO_Logger' ) ) {
						TWTAEO_Logger::info(
							'Gemini 3 grounded call failed — fell back to ' . self::GROUNDING_FALLBACK_MODEL . '.',
							array(
								'http_code' => $code,
								'error'     => $msg,
							)
						);
					}
					return $retry;
				}
			}

			return new WP_Error( 'gemini_error', $msg );
		}
		self::telemetry( 'gemini', $model, $body['usageMetadata'] ?? array() );

		// Take the first answer text — thinking models can put parts flagged
		// "thought": true ahead of the actual response.
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( empty( $part['thought'] ) && '' !== (string) ( $part['text'] ?? '' ) ) {
				$text = (string) $part['text'];
				break;
			}
		}
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

	/**
	 * Perplexity Agent API. A preset ("fast") picks the model and switches web
	 * search on; a caller may pass a preset name, or a full provider/model ID
	 * such as "openai/gpt-6-luna", as $opts['model'].
	 */
	/**
	 * OpenRouter: one key, many vendors' models.
	 *
	 * Its chat API is OpenAI's, so this sends the same messages the OpenAI
	 * caller builds. What it deliberately does not carry over is the
	 * reasoning-model branch: those rules are keyed to OpenAI's own model
	 * names, and an id here is "vendor/model" chosen by the site owner from a
	 * catalogue that changes weekly. Guessing which of those take
	 * max_completion_tokens would be wrong more often than right, so this
	 * sends the plain, universally accepted fields and lets the model decide.
	 *
	 * @param string $api_key
	 * @param string $prompt
	 * @param array  $opts
	 * @return string|WP_Error
	 */
	/**
	 * Headers that identify the calling application to OpenRouter.
	 *
	 * These name THIS PLUGIN, not the site running it, and that is deliberate.
	 * OpenRouter treats HTTP-Referer as an application's unique identity: it
	 * is what creates the app's page and what its public rankings are keyed
	 * on. Sending each site's own address would publish a separate entry for
	 * every install, on a page anyone can read, and would scatter the plugin
	 * across thousands of one-request apps instead of showing it as one. The
	 * application here is AEO Ultimate; the site is its user.
	 *
	 * Nothing about the site, the page or the visitor goes in these headers.
	 *
	 * An agency that would rather its own requests were attributed to itself
	 * can say so with the twtaeo_openrouter_app filter.
	 *
	 * @param string $api_key
	 * @return array
	 */
	private static function openrouter_headers( $api_key ) {
		$app = apply_filters(
			'twtaeo_openrouter_app',
			array(
				'url'        => 'https://aeoultimate.com/',
				'title'      => 'AEO Ultimate',
				'categories' => 'writing-assistant',
			)
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => esc_url_raw( (string) ( $app['url'] ?? '' ) ),
			// The current name for the display title, with the older one kept
			// beside it: OpenRouter still honours X-Title for compatibility
			// and some proxies in front of it may only know that one.
			'X-OpenRouter-Title' => sanitize_text_field( (string) ( $app['title'] ?? '' ) ),
			'X-Title'            => sanitize_text_field( (string) ( $app['title'] ?? '' ) ),
		);

		if ( ! empty( $app['categories'] ) ) {
			$headers['X-OpenRouter-Categories'] = sanitize_text_field( (string) $app['categories'] );
		}

		return $headers;
	}

	private static function call_openrouter( $api_key, $prompt, $opts ) {
		$model = self::model( 'openrouter', $opts );

		$payload = array(
			'model'    => $model,
			'messages' => self::openai_style_messages( $prompt, $opts ),
			// A reasoning model spends this budget thinking before it writes,
			// and the thinking comes out of the same allowance. Asked for 200
			// tokens, nemotron-3.5-lightning spent all 200 reasoning and
			// returned content: null with finish_reason "length". The floor is
			// the same guard the Gemini caller already needs.
			'max_tokens'  => max( self::max_tokens( $opts ), 1024 ),
			'temperature' => self::temperature( $opts ),
			// None of the work this client does wants chain-of-thought: these
			// are short, factual writing tasks. OpenRouter normalises this
			// across vendors and ignores it for models that cannot reason.
			'reasoning'   => array( 'enabled' => false ),
		);
		if ( ! empty( $opts['json'] ) ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post( self::OPENROUTER_ENDPOINT, array(
			'timeout' => self::timeout( $opts ),
			'headers' => self::openrouter_headers( $api_key ),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = $body['error']['message'] ?? '';
			if ( 402 === (int) $code ) {
				$detail = $detail ? $detail : __( 'Your OpenRouter account is out of credit.', 'twt-aeo-ultimate' );
			}
			if ( 429 === (int) $code ) {
				// The usual way a free model fails, and the message from the
				// provider rarely says which limit was hit or that a paid
				// model would not have stopped.
				$detail = __( 'OpenRouter is rate limiting this model. Free models allow only a few requests at a time, so this is expected part way through a bulk run. Wait a moment, or pick a paid model under Settings - AI Models.', 'twt-aeo-ultimate' );
			}
			/* translators: 1: HTTP status code, 2: error text from OpenRouter. */
			return new WP_Error( 'openrouter_error', trim( sprintf( __( 'OpenRouter error (HTTP %1$d). %2$s', 'twt-aeo-ultimate' ), (int) $code, $detail ) ) );
		}

		// OpenRouter reports the model it actually served, which can differ
		// from the one asked for when a fallback route is used. Record that
		// one, so token telemetry describes what was really billed.
		self::telemetry( 'openrouter', (string) ( $body['model'] ?? $model ), $body['usage'] ?? array() );

		$choice  = $body['choices'][0] ?? array();
		$content = $choice['message']['content'] ?? null;

		if ( is_string( $content ) && '' !== trim( $content ) ) {
			return $content;
		}

		// Empty content with finish_reason "length" means the model ran out of
		// room, and on a reasoning model that usually means it thought until
		// the budget was gone. Say which it was: "no content" on its own sends
		// people looking at their key.
		if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
			return new WP_Error(
				'openrouter_truncated',
				sprintf(
					/* translators: %s: the model id that was used. */
					__( '%s used its whole reply allowance before writing an answer, which usually means it is a reasoning model. Try a different model under Settings - AI Models.', 'twt-aeo-ultimate' ),
					$model
				)
			);
		}

		return new WP_Error( 'openrouter_empty', __( 'OpenRouter returned no content.', 'twt-aeo-ultimate' ) );
	}

	private static function call_perplexity( $api_key, $prompt, $opts ) {
		$choice = ! empty( $opts['model'] ) ? (string) $opts['model'] : self::PERPLEXITY_PRESET;

		$payload = array( 'input' => $prompt );
		if ( false !== strpos( $choice, '/' ) ) {
			$payload['model'] = $choice;
		} else {
			$payload['preset'] = $choice;
		}
		if ( ! empty( $opts['system'] ) ) {
			$payload['instructions'] = $opts['system'];
		}

		$response = wp_remote_post( self::PERPLEXITY_ENDPOINT, array(
			'timeout' => self::timeout( $opts ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'perplexity_error', self::perplexity_error_message( $code, $body['error']['message'] ?? '' ) );
		}
		self::telemetry( 'perplexity', (string) ( $body['model'] ?? $choice ), $body['usage'] ?? array() );

		$text = self::perplexity_answer_text( is_array( $body ) ? $body : array() );
		return '' !== $text ? $text : new WP_Error( 'perplexity_empty', __( 'Perplexity returned no content.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * The answer text from an Agent API response: the `output_text` convenience
	 * field when present, otherwise every output_text part of every message.
	 *
	 * @param array $body Decoded response.
	 * @return string
	 */
	public static function perplexity_answer_text( array $body ) {
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) && '' !== $body['output_text'] ) {
			return $body['output_text'];
		}

		$text = '';
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		return $text;
	}

	/**
	 * A Perplexity failure in words a site owner can act on. The provider's own
	 * detail is kept on the end so support can still see what it said.
	 *
	 * The "retired" branch is for the day Perplexity changes its API again: a
	 * site on an old build then gets told to update the plugin, rather than a
	 * bare HTTP 404 that looks like a problem with their key.
	 *
	 * @param int    $code   HTTP status.
	 * @param string $detail Provider error message, if any.
	 * @return string
	 */
	public static function perplexity_error_message( $code, $detail = '' ) {
		$code   = (int) $code;
		$detail = trim( (string) $detail );

		if ( in_array( $code, array( 404, 410 ), true )
			|| (bool) preg_match( '/deprecat|retired|no longer (supported|available)|sunset|discontinu/i', $detail ) ) {
			$message = __( 'Perplexity no longer accepts the request this version of TWT AEO Ultimate sends — Perplexity has retired that API. Update TWT AEO Ultimate under Dashboard → Updates to restore Perplexity features.', 'twt-aeo-ultimate' );
		} elseif ( 401 === $code || 403 === $code ) {
			$message = __( 'Perplexity rejected the API key. Check the key under TWT AEO → Settings, and that it is active in your Perplexity account.', 'twt-aeo-ultimate' );
		} elseif ( 429 === $code ) {
			$message = __( 'Perplexity is rate-limiting this key. Wait a minute, then try again.', 'twt-aeo-ultimate' );
		} elseif ( 402 === $code || (bool) preg_match( '/credit|balance|billing|payment/i', $detail ) ) {
			$message = __( 'Your Perplexity account is out of API credit. Add credit in your Perplexity account, then try again.', 'twt-aeo-ultimate' );
		} elseif ( $code >= 500 ) {
			$message = __( 'Perplexity had a server error. This is usually temporary — try again shortly.', 'twt-aeo-ultimate' );
		} else {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Perplexity API error (HTTP %d).', 'twt-aeo-ultimate' ), $code );
		}

		return '' !== $detail
			/* translators: 1: plain-language explanation, 2: Perplexity's own error text. */
			? sprintf( __( '%1$s (Perplexity said: %2$s)', 'twt-aeo-ultimate' ), $message, $detail )
			: $message;
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
		$model   = self::model( $provider, $opts );
		// wp_ai_client_prompt() ships in WordPress 7.0. The plugin supports 6.2+, so it
		// is only reached behind the function_exists() guard above and called indirectly
		// to keep the static WP-version compatibility scanner satisfied.
		$ai_client_prompt = 'wp_ai_client_prompt';
		$builder          = $ai_client_prompt( $prompt );
		if ( ! is_object( $builder ) ) {
			return new WP_Error( 'ai_client_shape', __( 'The WordPress AI Client did not return a prompt builder.', 'twt-aeo-ultimate' ) );
		}

		// 🛑 **`is_callable()`, never `method_exists()`.** Measured against WordPress
		// 7.0.2: `WP_AI_Client_Prompt_Builder` declares exactly three methods —
		// `__construct`, `using_abilities` and `__call`. Every fluent method on it,
		// including `generate_text()`, is served by `__call`, so `method_exists()` is
		// **false for all of them**. The guards here used to be `method_exists()`,
		// which meant the model preference was never applied and `generate_text()`
		// was **never called at all** — the request short-circuited to an empty
		// string and every caller got "returned no content" without a single request
		// being made. The feature looked implemented and was dead.
		// The wrapper does not throw for unsupported names — it poisons the builder
		// and generate_text() later returns `prompt_builder_error`. Probe the SDK
		// class directly; see the matching note in TWTAEO_AI_Description.
		if ( class_exists( '\\WordPress\\AiClient\\Builders\\PromptBuilder' )
			&& method_exists( '\\WordPress\\AiClient\\Builders\\PromptBuilder', 'usingModelPreference' ) ) {
			$preferred = $builder->using_model_preference( $model );
			if ( is_object( $preferred ) ) {
				$builder = $preferred;
			}
		}

		try {
			$result = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_client_error', $e->getMessage() );
		}

		// ⚠️ WordPress returns a WP_Error here rather than throwing when no connected
		// provider can serve the prompt — e.g. `prompt_invalid_argument: No models
		// found that support text_generation`. Passing it through matters: the
		// generic "returned no content" it used to become told the user nothing, and
		// the actual cause is usually that no provider is connected yet.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				sprintf(
					/* translators: %s: the error reported by WordPress. */
					__( 'The WordPress AI Client could not generate text: %s Connect a provider under Settings → Connectors, or enter a key on this screen.', 'twt-aeo-ultimate' ),
					$result->get_error_message()
				)
			);
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
		// wp_safe_remote_get, not wp_remote_get. This URL comes out of an
		// <img src> in post content, so anyone who can edit a post chooses it,
		// and the unsafe call would happily fetch http://localhost:9200 or a
		// cloud metadata endpoint on the server's behalf. The safe variant
		// applies WordPress's own blocklist for private and loopback hosts.
		//
		// This site's own media never reaches here: read_local_image() takes
		// attachments off disk first, which is both faster and immune to the
		// blocklist.
		$response = wp_safe_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$binary = wp_remote_retrieve_body( $response );
		if ( '' === $binary ) {
			return null;
		}

		// Too large to send inline. Every provider caps the encoded size, and
		// base64 adds about a third, so this refuses rather than building a
		// payload that will be rejected after the upload.
		if ( strlen( $binary ) > self::MAX_INLINE_IMAGE_BYTES ) {
			return null;
		}

		// A URL that needs a login answers 200 with an HTML page, and sending
		// that as an image wastes a billed call on nothing.
		//
		// The header is not always there: some servers, Local's among them,
		// serve an image with no Content-Type at all. An empty header is
		// therefore "unknown", not "not an image", and the extension decides.
		$mime = trim( strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) );
		if ( '' === $mime ) {
			$guess = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$mime  = ! empty( $guess['type'] ) ? $guess['type'] : '';
		}
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}

		return array(
			'mime_type' => $mime,
			'data'      => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * How an image should reach the model.
	 *
	 * Inline bytes where this site can read the image itself, the bare URL
	 * otherwise.
	 *
	 * Passing a URL asks the provider's own servers to go and fetch it, which
	 * only works for an image the public internet can reach. On a Local site,
	 * a staging host behind HTTP auth, an intranet or any site not yet
	 * launched, that fetch fails and the model is asked to describe an image
	 * it never saw. Fetching it here instead uses the site's own loopback
	 * request, which reaches its own files whatever the outside world can see.
	 *
	 * @param string $url
	 * @return array { inline?: array{mime_type,data}, url?: string }
	 */
	private static function image_source( $url ) {
		// Disk first. For this site's own media there is no reason to go
		// through HTTP at all, and doing so is what fails on the hosts that
		// need this most: a loopback request can be blocked, redirected to a
		// login, or answered without a Content-Type.
		$inline = self::read_local_image( $url );

		if ( ! $inline ) {
			$inline = self::fetch_inline_image( self::scaled_image_url( $url ) );
		}

		return $inline ? array( 'inline' => $inline ) : array( 'url' => $url );
	}

	/**
	 * Read one of this site's own attachments straight off disk.
	 *
	 * @param string $url
	 * @return array|null { mime_type, data }
	 */
	private static function read_local_image( $url ) {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return null;
		}

		$attachment_id = attachment_url_to_postid( $url );
		if ( ! $attachment_id ) {
			return null;
		}

		$path = self::attachment_path( $attachment_id );
		if ( ! $path ) {
			return null;
		}

		$type = wp_check_filetype( $path );
		$mime = ! empty( $type['type'] ) ? $type['type'] : '';
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}

		$filesystem = self::filesystem();
		if ( ! $filesystem ) {
			return null;
		}

		$binary = $filesystem->get_contents( $path );
		if ( ! is_string( $binary ) || '' === $binary ) {
			return null;
		}

		return array(
			'mime_type' => $mime,
			'data'      => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding image bytes for a multimodal API request, not obfuscation.
		);
	}

	/**
	 * The file to send for an attachment: a registered size where one exists
	 * and is small enough, otherwise the original.
	 *
	 * Alt text does not need a camera original, and the original is what
	 * usually pushes a request past the inline limit.
	 *
	 * @param int $attachment_id
	 * @return string|null Absolute path, or null when nothing usable is there.
	 */
	private static function attachment_path( $attachment_id ) {
		$original = get_attached_file( $attachment_id );
		if ( ! $original ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( array( 'large', 'medium_large', 'medium' ) as $size ) {
			if ( empty( $meta['sizes'][ $size ]['file'] ) ) {
				continue;
			}
			$candidate = dirname( $original ) . DIRECTORY_SEPARATOR . $meta['sizes'][ $size ]['file'];
			if ( file_exists( $candidate ) && filesize( $candidate ) <= self::MAX_INLINE_IMAGE_BYTES ) {
				return $candidate;
			}
		}

		if ( file_exists( $original ) && filesize( $original ) <= self::MAX_INLINE_IMAGE_BYTES ) {
			return $original;
		}

		return null;
	}

	/**
	 * WP_Filesystem, or null when it cannot be set up.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return null;
		}

		return $wp_filesystem;
	}

	/**
	 * A registered size in place of the original, where the URL is one of this
	 * site's own attachments.
	 *
	 * Alt text does not need a 6000px camera original, and the original is
	 * what usually pushes a request over the inline limit.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function scaled_image_url( $url ) {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return $url;
		}

		$attachment_id = attachment_url_to_postid( $url );
		if ( ! $attachment_id ) {
			return $url;
		}

		$scaled = wp_get_attachment_image_url( $attachment_id, 'large' );

		return $scaled ? $scaled : $url;
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
