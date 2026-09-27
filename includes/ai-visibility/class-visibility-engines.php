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
	 * @param string $engine   chatgpt|gemini|claude|perplexity|grok|mistral
	 * @param string $key      The merchant's key for that engine.
	 * @param string $question
	 * @return array { text, cited_urls[], searched_urls[], error|null, latency_ms, model, tools[] }
	 */
	public static function ask_with_citations( $engine, $key, $question ) {
		$started  = microtime( true );
		$engine   = (string) $engine;
		$key      = (string) $key;
		$question = (string) $question;
		$models   = TWTAEO_Visibility_Types::MODELS;
		$model    = isset( $models[ $engine ] ) ? $models[ $engine ] : '';

		$result = self::blank( $model );
		try {
			if ( '' === $key ) {
				$result['error'] = __( 'No key for this engine.', 'twt-aeo-ultimate' );
			} elseif ( '' === trim( $question ) ) {
				$result['error'] = __( 'Empty question.', 'twt-aeo-ultimate' );
			} else {
				switch ( $engine ) {
					case 'claude':
						$result = self::ask_claude( $key, $question, $model );
						break;
					case 'chatgpt':
						$result = self::ask_chatgpt( $key, $question, $model );
						break;
					case 'gemini':
						$result = self::ask_gemini( $key, $question, $model );
						break;
					case 'perplexity':
						$result = self::ask_perplexity( $key, $question, $model );
						break;
					case 'grok':
						$result = self::ask_grok( $key, $question, $model );
						break;
					case 'mistral':
						$result = self::ask_mistral( $key, $question, $model );
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

	private static function ask_claude( $key, $question, $model ) {
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
					'system'        => self::SHOPPER_PROMPT,
					'tools'         => array(
						array( 'type' => $tool, 'name' => 'web_search', 'max_uses' => 3 ),
					),
					'messages'      => array(
						array( 'role' => 'user', 'content' => $question ),
					),
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

	private static function ask_chatgpt( $key, $question, $model ) {
		$tool = 'web_search';
		$res  = self::chatgpt_request( $key, $question, $model, $tool );
		// Some accounts/models still only know the preview tool name; one retry.
		if ( ! $res['ok'] && 400 === (int) $res['status'] && false !== stripos( (string) $res['error'], 'web_search' ) ) {
			$tool = 'web_search_preview';
			$res  = self::chatgpt_request( $key, $question, $model, $tool );
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

	private static function chatgpt_request( $key, $question, $model, $tool_type ) {
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
					'instructions'      => self::SHOPPER_PROMPT,
					'input'             => $question,
					'max_output_tokens' => self::MAX_ANSWER_TOKENS,
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
	private static function ask_gemini( $key, $question, $model ) {
		$out = self::blank( $model, array( 'google_search' ) );
		$res = TWTAEO_Visibility_Http::post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
			array(
				'headers' => array( 'content-type' => 'application/json' ),
				'body'    => array(
					'systemInstruction' => array( 'parts' => array( array( 'text' => self::SHOPPER_PROMPT ) ) ),
					'contents'          => array(
						array( 'role' => 'user', 'parts' => array( array( 'text' => $question ) ) ),
					),
					'tools'             => array( array( 'google_search' => (object) array() ) ),
					'generationConfig'  => array( 'maxOutputTokens' => self::MAX_ANSWER_TOKENS ),
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
	private static function ask_perplexity( $key, $question, $model ) {
		$out = self::blank( $model, array( 'web_search' ) );
		$res = TWTAEO_Visibility_Http::post(
			TWTAEO_AI_Client::PERPLEXITY_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => array(
					'preset'            => $model,
					'instructions'      => self::SHOPPER_PROMPT,
					'input'             => $question,
					'max_output_tokens' => self::MAX_ANSWER_TOKENS,
				),
			),
			$key
		);
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
	private static function ask_grok( $key, $question, $model ) {
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
					'instructions' => self::SHOPPER_PROMPT,
					'input'        => array(
						array( 'role' => 'user', 'content' => $question ),
					),
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
	private static function ask_mistral( $key, $question, $model ) {
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
					'instructions' => self::SHOPPER_PROMPT,
					'inputs'       => array(
						array( 'role' => 'user', 'content' => $question ),
					),
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
