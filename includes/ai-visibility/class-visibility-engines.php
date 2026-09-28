<?php
/**
 * AI Visibility — the engine adapters.
 *
 * Ask ONE assistant ONE shopper question with web search on the MERCHANT'S key
 * and read back what it cited. Ported from the Shopify app's
 * `aiVisibility.server.ts` (2026-08-18).
 *
 * Honesty rules (docs/ai-visibility-plan.md):
 *  - The API approximates the consumer app; the page says so.
 *  - A refused or errored check is `unavailable`, never `absent`. Nothing here
 *    fabricates an answer, and `ask_with_citations()` never throws — the error
 *    travels inside the result so a provider hiccup cannot 500 the AJAX step.
 *  - Every call is BYOK through TWTAEO_Key_Resolver. No plugin key, no fallback.
 *
 * Models: TWTAEO_Visibility_Types::MODELS mirrors each consumer app's DEFAULT
 * model (what a shopper's phone runs), not its strongest. Re-check when an
 * app changes its default. The accuracy judge is different work and stays on
 * the cheap tier via TWTAEO_AI_Client.
 *
 * Gemini's citations are redirect URLs: Google Search grounding returns
 * `groundingChunks[].web.uri` as a `vertexaisearch.cloud.google.com/
 * grounding-api-redirect/…` link, so the cited DOMAIN has to come from
 * `web.title`, which Gemini fills with the hostname for most chunks. When the
 * title does not look like a hostname the redirect URL is kept as-is (its host
 * lands in "other" rather than being guessed). Known limitation, stated on the
 * page.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Engines {

	const SHOPPER_PROMPT = "You are answering a shopper's question. Search the web and answer briefly, citing your sources.";

	/** For providers whose max_tokens is answer-only. */
	const MAX_ANSWER_TOKENS = 700;

	/**
	 * ⚠️ On Claude, max_tokens caps THINKING PLUS TEXT, and Sonnet 5 thinks by
	 * default — 700 let it spend the whole budget deciding what to search and
	 * return no text at all (seen live 2026-08-18, twice). Give it room and ask
	 * for low effort: a shopper's quick question, not a research task.
	 */
	const CLAUDE_MAX_TOKENS = 4000;

	/**
	 * ⚠️ Same trap on Gemini 3.x and GPT-5+: their output cap counts thinking
	 * too. Seen live 2026-09-28 on gemini-3.6-flash — 591 of 700 tokens went
	 * to thinking, finishReason MAX_TOKENS, the answer cut off after one
	 * sentence (and any citation after it lost). The app thinks by default, so
	 * the probe keeps its thinking and gets room instead.
	 */
	const THINKING_MAX_TOKENS = 4000;

	const JUDGE_SYSTEM = "You compare an assistant's answer against one known fact about a store. Reply with exactly one word and nothing else: correct, wrong, or not_stated.";

	private function __construct() {}

	/* ───────────────────────────── keys ───────────────────────────── */

	/**
	 * The merchant's key for an engine, or ''.
	 *
	 * @param string $engine
	 * @return string
	 */
	public static function key_for( $engine ) {
		$engines = TWTAEO_Visibility_Types::ENGINES;
		if ( ! isset( $engines[ $engine ] ) || ! class_exists( 'TWTAEO_Key_Resolver' ) ) {
			return '';
		}
		$slug = $engines[ $engine ]['key'];
		$key  = TWTAEO_Key_Resolver::get( $slug );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Which engines can run right now, and why not.
	 *
	 * @return array [ [ engine, label, available, reason ] ]
	 */
	public static function availability() {
		$out     = array();
		$engines = TWTAEO_Visibility_Types::ENGINES;
		$ids     = array_keys( $engines );
		foreach ( $ids as $id ) {
			if ( ! isset( $engines[ $id ] ) ) {
				continue;
			}
			$label = $engines[ $id ]['label'];
			$v1    = in_array( $id, TWTAEO_Visibility_Types::ENGINES_V1, true );
			$key   = self::key_for( $id );
			if ( '' !== $key ) {
				$out[] = array( 'engine' => $id, 'label' => $label, 'available' => true, 'reason' => '' );
			} elseif ( $v1 ) {
				$out[] = array(
					'engine'    => $id,
					'label'     => $label,
					'available' => false,
					// The board appends its own "add it in Settings" link — keep
					// the reason bare or the phrase shows twice.
					/* translators: %s: engine name */
					'reason'    => sprintf( __( 'No %s key', 'twt-aeo-ultimate' ), $label ),
				);
			} else {
				$out[] = array(
					'engine'    => $id,
					'label'     => $label,
					'available' => false,
					'reason'    => __( 'Coming soon', 'twt-aeo-ultimate' ),
				);
			}
		}
		return $out;
	}

	/* ──────────────────────── rejected keys ──────────────────────── */

	/*
	 * A key the provider rejects fails the same way for every question, so a
	 * run would otherwise spend one call per question learning nothing new.
	 * Once rejected, the engine is skipped for the rest of that run. The next
	 * run tries the key again: it may have been fixed in the meantime.
	 */

	/**
	 * Whether an error says the provider refused the key itself (not the
	 * question, a rate limit or an outage).
	 *
	 * @param string|null $error From ask_with_citations().
	 * @return bool
	 */
	public static function is_key_error( $error ) {
		$error = (string) $error;
		if ( '' === $error ) {
			return false;
		}
		if ( preg_match( '/^HTTP 40[13]\b/', $error ) ) {
			return true;
		}

		return (bool) preg_match( '/rejected the api key|invalid[ _-]?(?:x-)?api[ _-]?key|incorrect api key|api key (?:is )?(?:not valid|invalid|expired|revoked)|invalid authentication|authentication_error|unauthori[sz]ed/i', $error );
	}

	/* ──────────────────────── ask with citations ──────────────────── */

	/**
	 * Ask ONE assistant ONE question with web search on the merchant's key.
	 * Never throws; errors come back in `error` with the key redacted.
	 *
	 * The question travels exactly as written. A persona, when given, goes into
	 * the system instructions beside the shopper prompt -- where the consumer
	 * apps put what they know about the user -- never into the question.
	 *
	 * @param string $engine   chatgpt|gemini|claude|perplexity|grok|mistral
	 * @param string $key      The merchant's key for that engine.
	 * @param string $question
	 * @param array  $opts     { persona?: string, history?: [ [ q, a ], … ] } The persona's label;
	 *                          earlier turns of the same conversation (journey mode), oldest first.
	 * @return array { text, cited_urls[], searched_urls[], error|null, latency_ms, model, tools[] }
	 */
	public static function ask_with_citations( $engine, $key, $question, array $opts = array() ) {
		$started  = microtime( true );
		$engine   = (string) $engine;
		$key      = (string) $key;
		$question = (string) $question;
		$models   = TWTAEO_Visibility_Types::MODELS;
		$model    = isset( $models[ $engine ] ) ? $models[ $engine ] : '';
		$system   = self::system_prompt( self::SHOPPER_PROMPT, $opts );
		$history  = self::history( $opts );

		$result = self::blank( $model );
		try {
			if ( '' === $key ) {
				$result['error'] = __( 'No key for this engine.', 'twt-aeo-ultimate' );
			} elseif ( '' === trim( $question ) ) {
				$result['error'] = __( 'Empty question.', 'twt-aeo-ultimate' );
			} else {
				switch ( $engine ) {
					case 'claude':
						$result = self::ask_claude( $key, $question, $model, $system, $history );
						break;
					case 'chatgpt':
						$result = self::ask_chatgpt( $key, $question, $model, $system, $history );
						break;
					case 'gemini':
						$result = self::ask_gemini( $key, $question, $model, $system, $history );
						break;
					case 'perplexity':
						$result = self::ask_perplexity( $key, $question, $model, $system, $history );
						break;
					case 'grok':
						$result = self::ask_grok( $key, $question, $model, $system, $history );
						break;
					case 'mistral':
						$result = self::ask_mistral( $key, $question, $model, $system, $history );
						break;
					default:
						/* translators: %s: engine id */
						$result['error'] = sprintf( __( 'Unknown engine: %s', 'twt-aeo-ultimate' ), $engine );
				}
			}
		} catch ( \Throwable $e ) {
			$result          = self::blank( $model );
			$result['error'] = TWTAEO_Visibility_Http::redact( $e->getMessage(), $key );
		}

		$result['text']          = isset( $result['text'] ) ? (string) $result['text'] : '';
		$result['cited_urls']    = self::uniq( isset( $result['cited_urls'] ) ? $result['cited_urls'] : array() );
		$result['searched_urls'] = self::uniq( isset( $result['searched_urls'] ) ? $result['searched_urls'] : array() );
		$result['error']         = ( isset( $result['error'] ) && '' !== $result['error'] ) ? TWTAEO_Visibility_Http::redact( (string) $result['error'], $key ) : null;
		$result['latency_ms']    = (int) round( ( microtime( true ) - $started ) * 1000 );
		$result['model']         = isset( $result['model'] ) ? (string) $result['model'] : $model;
		$result['tools']         = isset( $result['tools'] ) && is_array( $result['tools'] ) ? array_values( $result['tools'] ) : array();
		return $result;
	}

	private static function blank( $model, $tools = array() ) {
		return array(
			'text'          => '',
			'cited_urls'    => array(),
			'searched_urls' => array(),
			'error'         => null,
			'latency_ms'    => 0,
			'model'         => (string) $model,
			'tools'         => $tools,
		);
	}

	/* ─────────────────────────── Claude ───────────────────────────── */

	private static function ask_claude( $key, $question, $model, $system, array $history = array() ) {
		$tool = TWTAEO_Visibility_Types::CLAUDE_SEARCH_TOOL;
		$out  = self::blank( $model, array( $tool ) );
		$res  = TWTAEO_Visibility_Http::post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => array(
					'model'         => $model,
					'max_tokens'    => self::CLAUDE_MAX_TOKENS,
					'output_config' => array( 'effort' => 'low' ),
					'system'        => $system,
					'tools'         => array(
						array( 'type' => $tool, 'name' => 'web_search', 'max_uses' => 3 ),
					),
					'messages'      => self::turns( $history, $question ),
				),
			),
			$key
		);
		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}
		$body = $res['body'];
		if ( isset( $body['stop_reason'] ) && 'refusal' === $body['stop_reason'] ) {
			$out['error'] = __( 'The provider declined this question.', 'twt-aeo-ultimate' );
			return $out;
		}
		// `pause_turn` (server-side search loop hit its iteration cap) is treated
		// as the answer so far — one check is one call, we do not resume.
		$texts    = array();
		$cited    = array();
		$searched = array();
		$blocks   = isset( $body['content'] ) && is_array( $body['content'] ) ? $body['content'] : array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
				continue;
			}
			if ( 'text' === $block['type'] ) {
				if ( isset( $block['text'] ) && is_string( $block['text'] ) ) {
					$texts[] = $block['text'];
				}
				$cites = isset( $block['citations'] ) && is_array( $block['citations'] ) ? $block['citations'] : array();
				foreach ( $cites as $c ) {
					if ( is_array( $c ) && isset( $c['url'] ) && self::is_url( $c['url'] ) ) {
						$cited[] = $c['url'];
					}
				}
			} elseif ( 'web_search_tool_result' === $block['type'] ) {
				// Success content is a LIST of results; an error is a single object.
				$content = isset( $block['content'] ) && is_array( $block['content'] ) ? $block['content'] : array();
				foreach ( $content as $r ) {
					if ( is_array( $r ) && isset( $r['url'] ) && self::is_url( $r['url'] ) ) {
						$searched[] = $r['url'];
					}
				}
			}
		}
		$out['text']          = implode( '', $texts );
		$out['cited_urls']    = $cited;
		$out['searched_urls'] = $searched;
		return $out;
	}

	/* ─────────────────────────── ChatGPT ──────────────────────────── */

	private static function ask_chatgpt( $key, $question, $model, $system, array $history = array() ) {
		$tool = 'web_search';
		$res  = self::chatgpt_request( $key, $question, $model, $tool, $system, $history );
		// Some accounts/models still only know the preview tool name; one retry.
		if ( ! $res['ok'] && 400 === (int) $res['status'] && false !== stripos( (string) $res['error'], 'web_search' ) ) {
			$tool = 'web_search_preview';
			$res  = self::chatgpt_request( $key, $question, $model, $tool, $system, $history );
		}
		$out = self::blank( $model, array( $tool ) );
		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}
		$body  = $res['body'];
		$texts = array();
		$cited = array();
		$items = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
		foreach ( $items as $item ) {
			$parts = is_array( $item ) && isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) || ! isset( $part['type'] ) || 'output_text' !== $part['type'] ) {
					continue;
				}
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$texts[] = $part['text'];
				}
				$ann = isset( $part['annotations'] ) && is_array( $part['annotations'] ) ? $part['annotations'] : array();
				foreach ( $ann as $a ) {
					if ( is_array( $a ) && isset( $a['type'], $a['url'] ) && 'url_citation' === $a['type'] && self::is_url( $a['url'] ) ) {
						$cited[] = $a['url'];
					}
				}
			}
		}
		if ( empty( $texts ) && isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			$texts[] = $body['output_text'];
		}
		$out['text']       = implode( '', $texts );
		$out['cited_urls'] = $cited;
		return $out;
	}

	private static function chatgpt_request( $key, $question, $model, $tool_type, $system, array $history = array() ) {
		return TWTAEO_Visibility_Http::post(
			'https://api.openai.com/v1/responses',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => array(
					'model'             => $model,
					'tools'             => array( array( 'type' => $tool_type ) ),
					'instructions'      => $system,
					'input'             => empty( $history ) ? $question : self::turns( $history, $question ),
					'max_output_tokens' => self::THINKING_MAX_TOKENS,
				),
			),
			$key
		);
	}

	/* ─────────────────────────── Gemini ───────────────────────────── */

	/**
	 * Gemini with Google Search grounding. ⚠️ `groundingChunks[].web.uri` is a
	 * Google REDIRECT (vertexaisearch.cloud.google.com/grounding-api-redirect/…),
	 * not the page, so the cited host is taken from `web.title` when that looks
	 * like a hostname (has a dot, no spaces) — pushed as `https://{title}/`.
	 * Otherwise the redirect URI is kept as-is and its host will read as
	 * "other" rather than be guessed. Known limitation; the page states it.
	 */
	private static function ask_gemini( $key, $question, $model, $system, array $history = array() ) {
		$out = self::blank( $model, array( 'google_search' ) );
		$res = TWTAEO_Visibility_Http::post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
			array(
				'headers' => array( 'content-type' => 'application/json' ),
				'body'    => array(
					'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
					'contents'          => self::gemini_turns( $history, $question ),
					'tools'             => array( array( 'google_search' => (object) array() ) ),
					'generationConfig'  => array( 'maxOutputTokens' => self::THINKING_MAX_TOKENS ),
				),
			),
			$key
		);
		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}
		$body      = $res['body'];
		$candidate = isset( $body['candidates'][0] ) && is_array( $body['candidates'][0] ) ? $body['candidates'][0] : null;
		if ( null === $candidate ) {
			$blocked = isset( $body['promptFeedback']['blockReason'] ) ? (string) $body['promptFeedback']['blockReason'] : '';
			$out['error'] = '' !== $blocked
				/* translators: %s: provider block reason */
				? sprintf( __( 'The provider declined this question (%s).', 'twt-aeo-ultimate' ), $blocked )
				: __( 'The provider returned no answer.', 'twt-aeo-ultimate' );
			return $out;
		}
		$texts = array();
		$parts = isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ? $candidate['content']['parts'] : array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$texts[] = $part['text'];
			}
		}
		$cited  = array();
		$chunks = isset( $candidate['groundingMetadata']['groundingChunks'] ) && is_array( $candidate['groundingMetadata']['groundingChunks'] )
			? $candidate['groundingMetadata']['groundingChunks']
			: array();
		foreach ( $chunks as $chunk ) {
			$web = is_array( $chunk ) && isset( $chunk['web'] ) && is_array( $chunk['web'] ) ? $chunk['web'] : null;
			if ( null === $web ) {
				continue;
			}
			$title = isset( $web['title'] ) ? $web['title'] : '';
			if ( self::looks_like_host( $title ) ) {
				$cited[] = 'https://' . strtolower( trim( $title ) ) . '/';
			} elseif ( isset( $web['uri'] ) && self::is_url( $web['uri'] ) ) {
				$cited[] = $web['uri'];
			}
		}
		$out['text']       = implode( '', $texts );
		$out['cited_urls'] = $cited;
		return $out;
	}

	/* ────────────────────────── Perplexity ────────────────────────── */

	/**
	 * Perplexity Agent API (the Sonar Chat Completions API was retired on
	 * 2026-09-27). The model is a preset name — "fast" switches web search on.
	 *
	 * Same split as Grok: `cited_urls` are the url_citation annotations on the
	 * answer, `searched_urls` the search_results it consulted. When an answer
	 * carries no annotations the consulted sources count as cited, which is how
	 * Sonar's `citations[]` + `search_results[]` were read before the move, so
	 * runs either side of the migration stay comparable.
	 */
	private static function ask_perplexity( $key, $question, $model, $system, array $history = array() ) {
		$out     = self::blank( $model, array( 'web_search' ) );
		$request = function ( $input ) use ( $key, $model, $system ) {
			return TWTAEO_Visibility_Http::post(
				TWTAEO_AI_Client::PERPLEXITY_ENDPOINT,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'content-type'  => 'application/json',
					),
					'body'    => array(
						'preset'            => $model,
						'instructions'      => $system,
						'input'             => $input,
						'max_output_tokens' => self::MAX_ANSWER_TOKENS,
					),
				),
				$key
			);
		};
		$res = $request( empty( $history ) ? $question : self::turns( $history, $question ) );
		// Earlier turns are sent as message items. If the Agent API refuses that
		// shape, carry them as a transcript in the input text instead — the
		// conversation still reaches the model, just less natively.
		if ( ! $res['ok'] && 400 === (int) $res['status'] && ! empty( $history ) ) {
			$res = $request( self::transcript( $history, $question ) );
		}
		if ( ! $res['ok'] ) {
			// A status means Perplexity answered; say what it meant in plain words.
			$out['error'] = $res['status'] > 0
				? TWTAEO_AI_Client::perplexity_error_message( $res['status'], TWTAEO_Visibility_Http::provider_detail( $res['raw'], $key ) )
				: $res['error'];
			return $out;
		}

		$body     = is_array( $res['body'] ) ? $res['body'] : array();
		$cited    = array();
		$searched = array();
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? $item['type'] : '';
			if ( 'message' === $type ) {
				foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
					foreach ( (array) ( is_array( $part ) && isset( $part['annotations'] ) ? $part['annotations'] : array() ) as $note ) {
						if ( is_array( $note ) && 'url_citation' === ( $note['type'] ?? '' ) && isset( $note['url'] ) && self::is_url( $note['url'] ) ) {
							$cited[] = $note['url'];
						}
					}
				}
			} elseif ( 'search_results' === $type ) {
				foreach ( (array) ( $item['results'] ?? array() ) as $r ) {
					if ( is_array( $r ) && isset( $r['url'] ) && self::is_url( $r['url'] ) ) {
						$searched[] = $r['url'];
					}
				}
			}
		}

		$out['text']          = TWTAEO_AI_Client::perplexity_answer_text( $body );
		$out['cited_urls']    = ! empty( $cited ) ? $cited : $searched;
		$out['searched_urls'] = $searched;
		return $out;
	}

	/* ─────────────────────────── Grok (xAI) ───────────────────────── */

	/**
	 * xAI Responses API with web_search + x_search. The top-level `citations[]` array
	 * is what the model CONSULTED, not what it cited in the answer — it is kept
	 * in `searched_urls`; `cited_urls` comes from url_citation annotations.
	 */
	private static function ask_grok( $key, $question, $model, $system, array $history = array() ) {
		$out = self::blank( $model, array( 'web_search', 'x_search' ) );
		$res = TWTAEO_Visibility_Http::post(
			'https://api.x.ai/v1/responses',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => array(
					'model'        => $model,
					'instructions' => $system,
					'input'        => self::turns( $history, $question ),
					'tools'        => array(
						array( 'type' => 'web_search' ),
						array( 'type' => 'x_search' ),
					),
				),
			),
			$key
		);
		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}
		$body     = $res['body'];
		$texts    = array();
		$cited    = array();
		$searched = array();
		$items    = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
		foreach ( $items as $item ) {
			$parts = is_array( $item ) && isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( isset( $part['text'] ) && is_string( $part['text'] ) && ( ! isset( $part['type'] ) || 'output_text' === $part['type'] ) ) {
					$texts[] = $part['text'];
				}
				$ann = isset( $part['annotations'] ) && is_array( $part['annotations'] ) ? $part['annotations'] : array();
				foreach ( $ann as $a ) {
					if ( is_array( $a ) && isset( $a['type'], $a['url'] ) && 'url_citation' === $a['type'] && self::is_url( $a['url'] ) ) {
						$cited[] = $a['url'];
					}
				}
			}
		}
		$consulted = isset( $body['citations'] ) && is_array( $body['citations'] ) ? $body['citations'] : array();
		foreach ( $consulted as $c ) {
			if ( self::is_url( $c ) ) {
				$searched[] = $c;
			} elseif ( is_array( $c ) && isset( $c['url'] ) && self::is_url( $c['url'] ) ) {
				$searched[] = $c['url'];
			}
		}
		if ( empty( $texts ) && isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			$texts[] = $body['output_text'];
		}
		$out['text']          = implode( '', $texts );
		$out['cited_urls']    = $cited;
		$out['searched_urls'] = $searched;
		return $out;
	}

	/* ────────────────────────── Mistral ───────────────────────────── */

	/**
	 * Mistral Conversations API (NOT chat/completions) with the web_search
	 * connector. References arrive as `tool_reference` chunks interleaved with
	 * text chunks in `outputs[].content[]`. Reachable only once a `mistral`
	 * key slug exists in the resolver.
	 */
	private static function ask_mistral( $key, $question, $model, $system, array $history = array() ) {
		$out = self::blank( $model, array( 'web_search' ) );
		$res = TWTAEO_Visibility_Http::post(
			'https://api.mistral.ai/v1/conversations',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => array(
					'model'        => $model,
					'instructions' => $system,
					'inputs'       => self::turns( $history, $question ),
					'tools'        => array( array( 'type' => 'web_search' ) ),
				),
			),
			$key
		);
		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}
		$body    = $res['body'];
		$texts   = array();
		$cited   = array();
		$outputs = isset( $body['outputs'] ) && is_array( $body['outputs'] ) ? $body['outputs'] : array();
		foreach ( $outputs as $o ) {
			if ( ! is_array( $o ) || ! isset( $o['content'] ) ) {
				continue;
			}
			if ( is_string( $o['content'] ) ) {
				$texts[] = $o['content'];
				continue;
			}
			if ( ! is_array( $o['content'] ) ) {
				continue;
			}
			foreach ( $o['content'] as $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$type = isset( $chunk['type'] ) ? $chunk['type'] : '';
				if ( 'tool_reference' === $type ) {
					if ( isset( $chunk['url'] ) && self::is_url( $chunk['url'] ) ) {
						$cited[] = $chunk['url'];
					}
				} elseif ( 'text' === $type && isset( $chunk['text'] ) && is_string( $chunk['text'] ) ) {
					$texts[] = $chunk['text'];
				}
			}
		}
		$out['text']       = implode( '', $texts );
		$out['cited_urls'] = $cited;
		return $out;
	}

	/* ─────────────────────────── conversation ────────────────────── */

	/** Characters of an earlier answer carried into the next turn. */
	const HISTORY_ANSWER_MAX = 6000;

	/**
	 * Earlier turns from $opts, cleaned: [ [ 'q' => …, 'a' => … ], … ].
	 *
	 * @param array $opts
	 * @return array
	 */
	private static function history( array $opts ) {
		$out = array();
		foreach ( (array) ( isset( $opts['history'] ) ? $opts['history'] : array() ) as $turn ) {
			$q = is_array( $turn ) && isset( $turn['q'] ) ? trim( (string) $turn['q'] ) : '';
			$a = is_array( $turn ) && isset( $turn['a'] ) ? trim( (string) $turn['a'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$a     = function_exists( 'mb_substr' ) ? mb_substr( $a, 0, self::HISTORY_ANSWER_MAX ) : substr( $a, 0, self::HISTORY_ANSWER_MAX );
			$out[] = array( 'q' => $q, 'a' => $a );
		}
		return $out;
	}

	/** user / assistant messages, oldest first, ending with the new question. */
	private static function turns( array $history, $question ) {
		$msgs = array();
		foreach ( $history as $turn ) {
			$msgs[] = array( 'role' => 'user', 'content' => $turn['q'] );
			$msgs[] = array( 'role' => 'assistant', 'content' => $turn['a'] );
		}
		$msgs[] = array( 'role' => 'user', 'content' => (string) $question );
		return $msgs;
	}

	/** Gemini's shape of the same: role "model", text in parts. */
	private static function gemini_turns( array $history, $question ) {
		$out = array();
		foreach ( self::turns( $history, $question ) as $m ) {
			$out[] = array(
				'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $m['content'] ) ),
			);
		}
		return $out;
	}

	/** One string carrying the conversation so far, for an API that takes text only. */
	private static function transcript( array $history, $question ) {
		$lines = array( 'Earlier in this conversation:' );
		foreach ( $history as $turn ) {
			$lines[] = 'User: ' . $turn['q'];
			$lines[] = 'Assistant: ' . $turn['a'];
		}
		$lines[] = '';
		$lines[] = 'The user now asks: ' . $question;
		return implode( "\n", $lines );
	}

	/* ─────────────────────────── persona ──────────────────────────── */

	/**
	 * System instructions with the persona's context line appended, or the base
	 * instructions unchanged when there is no persona.
	 *
	 * @param string $base
	 * @param array  $opts { persona?: string }
	 * @return string
	 */
	public static function system_prompt( $base, array $opts = array() ) {
		$line = isset( $opts['persona'] ) ? self::persona_context( $opts['persona'] ) : '';
		return '' === $line ? (string) $base : (string) $base . "\n\n" . $line;
	}

	/**
	 * "User context: The user is a machine shop owner." Public for tests.
	 *
	 * @param string $label The persona as the owner typed it.
	 * @return string '' when the label is empty.
	 */
	public static function persona_context( $label ) {
		$label = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $label ) ) );
		$label = rtrim( $label, '. ' );
		if ( '' === $label ) {
			return '';
		}
		if ( preg_match( '/^(?:a|an|the|my|our|one)\s/i', $label ) ) {
			return 'User context: The user is ' . $label . '.';
		}
		// "engineers", "homeowners" -- a group, not one person.
		if ( preg_match( '/[^s]s$/i', $label ) && ! preg_match( '/(?:us|is|ss)$/i', $label ) ) {
			return 'User context: The user is one of these: ' . $label . '.';
		}
		$article = preg_match( '/^[aeiou]/i', $label ) ? 'an' : 'a';
		return 'User context: The user is ' . $article . ' ' . $label . '.';
	}

	/* ─────────────────────────── follow-ups ───────────────────────── */

	/*
	 * The follow-ups come from a SECOND, small call to the same engine and model,
	 * not from the answer call. Asking for JSON in the answer call would change
	 * the thing being measured: the answer turns into a JSON string, citations
	 * stop pointing at prose (Claude attaches them to text blocks only), and it
	 * reads less like the consumer app. The consumer apps generate their
	 * suggested follow-ups separately from the answer too.
	 *
	 * No web search on this call, and a failure here never touches the check's
	 * verdict: the check stands, with an empty list and the reason beside it.
	 *
	 * Native structured output per API:
	 *   Claude      output_config.format json_schema (not a forced tool: newer
	 *               Claude models reject forced tool_choice)
	 *   ChatGPT/Grok Responses text.format json_schema, strict
	 *   Gemini      generationConfig responseMimeType + responseJsonSchema
	 *   Perplexity  Agent API response_format json_schema (the Sonar API's
	 *               related-questions flag died with Sonar on 2026-09-27)
	 *   Mistral     chat/completions response_format json_schema
	 */

	const FOLLOW_UP_SYSTEM = 'You predict the follow-up questions a person asks an AI assistant after reading its answer, like the suggested follow-ups an assistant shows under a reply. Write each one the way that person would type it. Reply with only JSON: {"follow_ups": ["..."]}';

	/** Seconds the follow-up call may take; the answer call already spent up to 40. */
	const FOLLOW_UP_TIMEOUT = 25;

	const FOLLOW_UP_MAX_TOKENS = 600;

	/**
	 * Ask the engine that answered for the follow-up questions a person would
	 * ask next. Never throws.
	 *
	 * @param string $engine
	 * @param string $key
	 * @param string $question    The question as asked.
	 * @param string $answer_text The engine's answer.
	 * @param array  $opts        { persona?: string } Same persona as the answer call.
	 * @return array { follow_ups: string[], error: string|null }
	 */
	public static function follow_ups( $engine, $key, $question, $answer_text, array $opts = array() ) {
		$out      = array( 'follow_ups' => array(), 'error' => null );
		$engine   = (string) $engine;
		$key      = (string) $key;
		$question = (string) $question;
		$answer   = trim( (string) $answer_text );
		if ( '' === $key || '' === trim( $question ) || '' === $answer ) {
			$out['error'] = __( 'No answer to follow up on.', 'twt-aeo-ultimate' );
			return $out;
		}
		$models = TWTAEO_Visibility_Types::MODELS;
		$model  = isset( $models[ $engine ] ) ? $models[ $engine ] : '';
		$system = self::system_prompt( self::FOLLOW_UP_SYSTEM, $opts );
		$prompt = self::follow_up_prompt( $question, $answer );

		try {
			$res = self::follow_up_request( $engine, $key, $model, $system, $prompt, true );
			// A provider that refuses the structured-output field (or the effort
			// we guessed for the model) answers 400. Ask once more without them:
			// the instructions already ask for JSON and the parser reads plain
			// lists too.
			if ( ! $res['ok'] && 400 === (int) $res['status'] ) {
				$res = self::follow_up_request( $engine, $key, $model, $system, $prompt, false );
			}
			if ( ! $res['ok'] ) {
				$out['error'] = TWTAEO_Visibility_Http::redact( (string) $res['error'], $key );
				return $out;
			}
			$out['follow_ups'] = self::parse_follow_ups( $res['text'], $question );
			if ( empty( $out['follow_ups'] ) ) {
				$out['error'] = __( 'The engine suggested no follow-up questions.', 'twt-aeo-ultimate' );
			}
		} catch ( \Throwable $e ) {
			$out['follow_ups'] = array();
			$out['error']      = TWTAEO_Visibility_Http::redact( $e->getMessage(), $key );
		}
		return $out;
	}

	private static function follow_up_prompt( $question, $answer ) {
		$answer = function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, 4000 ) : substr( $answer, 0, 4000 );
		return implode(
			"\n",
			array(
				'The person asked:',
				$question,
				'',
				'The assistant answered:',
				$answer,
				'',
				'List the 3 to 5 follow-up questions this person is most likely to ask next.',
			)
		);
	}

	/** JSON Schema for { follow_ups: string[] }. `$closed` adds additionalProperties:false, which strict modes require. */
	private static function follow_up_schema( $closed = true ) {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'follow_ups' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'follow_ups' ),
		);
		if ( $closed ) {
			$schema['additionalProperties'] = false;
		}
		return $schema;
	}

	/**
	 * One follow-up request. `$structured` false drops the structured-output
	 * field and any reasoning-effort guess, for the retry.
	 *
	 * @return array { ok, status, error, text }
	 */
	private static function follow_up_request( $engine, $key, $model, $system, $prompt, $structured ) {
		$bearer = array(
			'Authorization' => 'Bearer ' . $key,
			'content-type'  => 'application/json',
		);
		$named  = array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'follow_ups',
				'schema' => self::follow_up_schema(),
				'strict' => true,
			),
		);

		switch ( $engine ) {
			case 'claude':
				$url     = 'https://api.anthropic.com/v1/messages';
				$headers = array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				);
				// Thinking off (or at its floor) on both attempts: a short list is
				// not a reasoning task, and thinking draws from max_tokens.
				$body = array_merge(
					array(
						'model'      => $model,
						'max_tokens' => TWTAEO_AI_Models::min_output_tokens( 'claude', $model, self::FOLLOW_UP_MAX_TOKENS ),
						'system'     => $system,
						'messages'   => array(
							array( 'role' => 'user', 'content' => $prompt ),
						),
					),
					TWTAEO_AI_Models::claude_thinking_fields( $model )
				);
				if ( $structured ) {
					$config                = isset( $body['output_config'] ) && is_array( $body['output_config'] ) ? $body['output_config'] : array();
					$config['format']      = array( 'type' => 'json_schema', 'schema' => self::follow_up_schema() );
					$body['output_config'] = $config;
				}
				break;

			case 'chatgpt':
			case 'grok':
				$url     = 'chatgpt' === $engine ? 'https://api.openai.com/v1/responses' : 'https://api.x.ai/v1/responses';
				$headers = $bearer;
				$body    = array(
					'model'        => $model,
					'instructions' => $system,
					'input'        => $prompt,
				);
				if ( 'chatgpt' === $engine ) {
					// Reasoning tokens draw from this budget on GPT-5+.
					$body['max_output_tokens'] = 1500;
				}
				if ( $structured ) {
					$body['text'] = array(
						'format' => array(
							'type'   => 'json_schema',
							'name'   => 'follow_ups',
							'schema' => self::follow_up_schema(),
							'strict' => true,
						),
					);
					$effort = 'chatgpt' === $engine ? TWTAEO_AI_Models::openai_reasoning_effort( $model ) : null;
					if ( null !== $effort ) {
						$body['reasoning'] = array( 'effort' => $effort );
					}
				}
				break;

			case 'gemini':
				$url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
				$headers = array( 'content-type' => 'application/json' );
				$gen     = TWTAEO_AI_Models::gemini_generation_fields( $model, 0.4 );
				$gen['maxOutputTokens'] = TWTAEO_AI_Models::min_output_tokens( 'gemini', $model, self::FOLLOW_UP_MAX_TOKENS );
				if ( $structured ) {
					$gen['responseMimeType']   = 'application/json';
					$gen['responseJsonSchema'] = self::follow_up_schema( false );
				}
				$body = array(
					'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
					'contents'          => array(
						array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ),
					),
					'generationConfig'  => $gen,
				);
				break;

			case 'perplexity':
				$url     = TWTAEO_AI_Client::PERPLEXITY_ENDPOINT;
				$headers = $bearer;
				$body    = array(
					'preset'            => $model,
					'instructions'      => $system,
					'input'             => $prompt,
					'max_output_tokens' => self::FOLLOW_UP_MAX_TOKENS,
				);
				if ( $structured ) {
					$body['response_format'] = $named;
					$body['max_steps']       = 1; // No research loop for a list of questions.
				}
				break;

			case 'mistral':
				$url     = 'https://api.mistral.ai/v1/chat/completions';
				$headers = $bearer;
				$body    = array(
					'model'      => $model,
					'max_tokens' => self::FOLLOW_UP_MAX_TOKENS,
					'messages'   => array(
						array( 'role' => 'system', 'content' => $system ),
						array( 'role' => 'user', 'content' => $prompt ),
					),
				);
				if ( $structured ) {
					$body['response_format'] = $named;
				}
				break;

			default:
				return array(
					'ok'     => false,
					'status' => 0,
					/* translators: %s: engine id */
					'error'  => sprintf( __( 'Unknown engine: %s', 'twt-aeo-ultimate' ), $engine ),
					'text'   => '',
				);
		}

		$res = TWTAEO_Visibility_Http::post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => self::FOLLOW_UP_TIMEOUT,
			),
			$key
		);
		if ( ! $res['ok'] ) {
			return array( 'ok' => false, 'status' => (int) $res['status'], 'error' => (string) $res['error'], 'text' => '' );
		}
		$body = is_array( $res['body'] ) ? $res['body'] : array();
		if ( 'claude' === $engine && isset( $body['stop_reason'] ) && 'refusal' === $body['stop_reason'] ) {
			return array( 'ok' => false, 'status' => (int) $res['status'], 'error' => __( 'The provider declined to suggest follow-ups.', 'twt-aeo-ultimate' ), 'text' => '' );
		}
		return array( 'ok' => true, 'status' => (int) $res['status'], 'error' => '', 'text' => self::follow_up_text( $engine, $body ) );
	}

	/** The reply's text, wherever this engine puts it. */
	private static function follow_up_text( $engine, array $body ) {
		$texts = array();
		switch ( $engine ) {
			case 'claude':
				foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
					if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
						$texts[] = $block['text'];
					}
				}
				break;
			case 'chatgpt':
			case 'grok':
				foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
					foreach ( (array) ( is_array( $item ) && isset( $item['content'] ) ? $item['content'] : array() ) as $part ) {
						if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
							$texts[] = $part['text'];
						}
					}
				}
				if ( empty( $texts ) && isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
					$texts[] = $body['output_text'];
				}
				break;
			case 'gemini':
				foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
					if ( is_array( $part ) && empty( $part['thought'] ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$texts[] = $part['text'];
					}
				}
				break;
			case 'perplexity':
				$texts[] = (string) TWTAEO_AI_Client::perplexity_answer_text( $body );
				break;
			case 'mistral':
				$content = $body['choices'][0]['message']['content'] ?? '';
				if ( is_string( $content ) ) {
					$texts[] = $content;
				} elseif ( is_array( $content ) ) {
					foreach ( $content as $chunk ) {
						if ( is_array( $chunk ) && 'text' === ( $chunk['type'] ?? '' ) && isset( $chunk['text'] ) && is_string( $chunk['text'] ) ) {
							$texts[] = $chunk['text'];
						}
					}
				}
				break;
		}
		return implode( '', $texts );
	}

	/**
	 * The follow-up questions in a reply: `{follow_ups: [...]}`, a bare JSON
	 * list, or -- when the model ignored the format -- lines ending in "?".
	 * Trimmed, de-bulleted, de-duplicated, never the question already asked,
	 * at most FOLLOW_UPS_MAX. Anything unreadable is an empty list. Public for
	 * tests.
	 *
	 * @param string $raw
	 * @param string $asked The original question, dropped if echoed back.
	 * @return string[]
	 */
	public static function parse_follow_ups( $raw, $asked = '' ) {
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return array();
		}
		$text = (string) preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = (string) preg_replace( '/\s*```$/', '', $text );

		$items   = null;
		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) && preg_match( '/\{.*\}|\[.*\]/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
		}
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['follow_ups'] ) && is_array( $decoded['follow_ups'] ) ) {
				$items = $decoded['follow_ups'];
			} elseif ( isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ) {
				$items = $decoded['questions'];
			} elseif ( array_values( $decoded ) === $decoded ) {
				$items = $decoded;
			}
		}
		if ( null === $items ) {
			$items = array();
			foreach ( (array) preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
				if ( '?' === substr( rtrim( wp_strip_all_tags( (string) $line ), " \t\"'*" ), -1 ) ) {
					$items[] = (string) $line;
				}
			}
		}

		$lower = function ( $s ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		};
		$asked = $lower( trim( (string) $asked ) );
		$out   = array();
		$seen  = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$q = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item ) ) );
			$q = trim( (string) preg_replace( '/^(?:[-*\x{2022}]|\d+[.)])\s*/u', '', $q ), " \t\"'" );
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
			if ( $len < 8 || $len > 200 ) {
				continue;
			}
			$k = $lower( $q );
			if ( isset( $seen[ $k ] ) || $k === $asked ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $q;
			if ( count( $out ) >= TWTAEO_Visibility_Types::FOLLOW_UPS_MAX ) {
				break;
			}
		}
		return $out;
	}

	/* ─────────────────────────── accuracy judge ───────────────────── */

	/**
	 * One plain call (no search) on the site's cheap-tier provider: does the
	 * answer agree with the fact, contradict it, or not address it? Defensive
	 * parse — anything unrecognised is `not_stated`; a failed call is `n/a`
	 * because we could not judge, which differs from the assistant staying
	 * silent. Only call when the question carries a truth and the verdict is
	 * not `unavailable`.
	 *
	 * @param array  $truth       Fact [ kind, statement ].
	 * @param string $answer_text
	 * @return string correct|wrong|not_stated|n/a
	 */
	public static function judge_accuracy( array $truth, $answer_text ) {
		$answer = trim( (string) $answer_text );
		if ( '' === $answer ) {
			return 'not_stated';
		}
		$statement = isset( $truth['statement'] ) ? trim( (string) $truth['statement'] ) : '';
		if ( '' === $statement ) {
			return 'n/a';
		}
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			return 'n/a';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$answer = mb_substr( $answer, 0, 4000 );
		} else {
			$answer = substr( $answer, 0, 4000 );
		}
		$prompt = implode(
			"\n",
			array(
				'Known fact: ' . $statement,
				'',
				"Assistant's answer: " . $answer,
				'',
				'Does the answer state something that agrees with the fact (correct), contradicts it (wrong), or does it not address the fact at all (not_stated)? Reply with one word: correct, wrong, or not_stated.',
			)
		);
		$provider = TWTAEO_AI_Client::enrich_provider();
		try {
			$raw = TWTAEO_AI_Client::complete(
				$provider,
				$prompt,
				array(
					'max_tokens'  => 10,
					'temperature' => 0,
					'system'      => self::JUDGE_SYSTEM,
				)
			);
		} catch ( \Throwable $e ) {
			return 'n/a';
		}
		if ( is_wp_error( $raw ) ) {
			return 'n/a';
		}
		return self::parse_accuracy( $raw );
	}

	/**
	 * First word of the reply, mapped onto the vocabulary. Public for tests.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	public static function parse_accuracy( $raw ) {
		$text = strtolower( (string) $raw );
		$text = preg_replace( '/[^a-z_ ]/', ' ', $text );
		$text = trim( (string) $text );
		$parts = preg_split( '/\s+/', $text );
		$word  = isset( $parts[0] ) ? $parts[0] : '';
		if ( in_array( $word, array( 'not_stated', 'not', 'notstated', 'unstated' ), true ) ) {
			return 'not_stated';
		}
		if ( in_array( $word, array( 'wrong', 'incorrect', 'false' ), true ) ) {
			return 'wrong';
		}
		if ( in_array( $word, array( 'correct', 'true', 'right' ), true ) ) {
			return 'correct';
		}
		return 'not_stated';
	}

	/* ─────────────────────────── helpers ──────────────────────────── */

	public static function is_url( $v ) {
		return is_string( $v ) && (bool) preg_match( '#^https?://#i', $v );
	}

	/** "example.com" or "shop.example.co.uk" — a bare hostname, no spaces, has a dot. */
	public static function looks_like_host( $v ) {
		if ( ! is_string( $v ) ) {
			return false;
		}
		return (bool) preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', trim( $v ) );
	}

	private static function uniq( $urls ) {
		$out = array();
		foreach ( (array) $urls as $u ) {
			if ( is_string( $u ) && '' !== $u && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}
}
