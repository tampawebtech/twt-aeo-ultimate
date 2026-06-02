<?php
/**
 * Content Generator
 *
 * Orchestrates AI content generation across Claude, OpenAI, and Perplexity.
 * Each provider is called only when its API key is configured and the
 * selected industry/subtopic defines a panel for that provider.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Content_Generator {

	const CLAUDE_ENDPOINT     = 'https://api.anthropic.com/v1/messages';
	const OPENAI_ENDPOINT     = 'https://api.openai.com/v1/chat/completions';
	const PERPLEXITY_ENDPOINT = 'https://api.perplexity.ai/chat/completions';

	const CLAUDE_MODEL     = 'claude-sonnet-4-6';
	const OPENAI_MODEL     = 'gpt-4o-mini';
	const PERPLEXITY_MODEL = 'sonar';

	/**
	 * Generate content for all configured providers for a given post, industry, and subtopic.
	 *
	 * @param int    $post_id
	 * @param string $industry_slug
	 * @param string $subtopic_slug
	 * @return array { provider => { label, content } } | { error => string }
	 */
	public static function generate( $post_id, $industry_slug, $subtopic_slug, $options = array(), $title_override = '' ) {
		$config   = TWTAEO_Industry_Config::get();
		$subtopic = $config[ $industry_slug ]['subtopics'][ $subtopic_slug ] ?? null;

		if ( ! $subtopic ) {
			return array( 'error' => 'Invalid industry or subtopic selection.' );
		}

		$title = $title_override;
		if ( ! $title && $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'error' => 'Post not found.' );
			}
			$title = get_the_title( $post );
		}
		if ( ! $title ) {
			return array( 'error' => 'No title available to generate content for.' );
		}
		$settings = get_option( 'twtaeo_settings', array() );
		$panels   = $subtopic['panels'] ?? array();
		$results  = array();

		foreach ( array( 'claude', 'openai', 'perplexity' ) as $provider ) {
			if ( empty( $panels[ $provider ] ) ) {
				continue;
			}

			$api_key = self::resolve_api_key( $provider, $settings );
			if ( empty( $api_key ) ) {
				$wp7_available = function_exists( 'wp_is_connector_registered' );
				$hint = $wp7_available
					? 'API key not configured. Add it in Settings → Connectors or AEO → Settings.'
					: 'API key not configured. Add it in AEO → Settings.';
				$results[ $provider ] = array(
					'label'   => $panels[ $provider ]['label'],
					'content' => '',
					'error'   => $hint,
				);
				continue;
			}

			$prompt  = str_replace( '{title}', $title, $panels[ $provider ]['prompt'] );
			$prompt .= self::build_options_modifier( $options );
			$content = self::call_provider( $provider, $api_key, $prompt );

			$results[ $provider ] = array(
				'label'   => $panels[ $provider ]['label'],
				'content' => is_array( $content ) ? '' : $content,
				'error'   => is_array( $content ) ? ( $content['error'] ?? 'Unknown error' ) : '',
			);
		}

		return $results;
	}

	// ── Prompt options modifier ───────────────────────────────────────────────

	private static function build_options_modifier( $options ) {
		$intent_map = array(
			'commercial'    => 'Commercial / Product Guide — prioritise persuasion, benefits, and buyer decision-making.',
			'informational' => 'Informational / Deep-Dive Educational — prioritise factual depth, clear explanations, and evergreen value.',
			'faq-local'     => 'FAQ / Local Search Answer — structure answers declaratively for voice search and local queries.',
			'news-trend'    => 'News / Trend Analysis — prioritise recency, cite current data, and frame around what is changing now.',
		);
		$tone_map = array(
			'professional' => 'Professional and authoritative',
			'friendly'     => 'Friendly and conversational',
			'empathetic'   => 'Empathetic and caring',
			'enthusiastic' => 'Enthusiastic and energetic',
			'neutral'      => 'Neutral and informational',
			'educational'  => 'Educational and instructive',
		);
		$audience_map = array(
			'homeowners'      => 'Homeowners and consumers',
			'business-owners' => 'Business owners and SMBs',
			'corporate'       => 'Corporate decision-makers — skip fluff, prioritise metrics and ROI',
			'healthcare-pros' => 'Healthcare professionals',
			'technical'       => 'Technical experts — incorporate deep industry terminology and raw data',
			'parents'         => 'Parents and families',
			'seniors'         => 'Seniors and older adults',
		);
		$pov_map = array(
			'first-person'  => 'First person — use "We" and "Our" throughout',
			'second-person' => 'Second person — use "You" and "Your" throughout',
			'third-person'  => 'Third person — use "they" and "the business"',
		);
		$reading_map = array(
			'simple'   => '6th grade — simple vocabulary, short sentences',
			'standard' => '8th–10th grade — standard and accessible',
			'advanced' => 'College level — advanced vocabulary acceptable',
			'expert'   => 'Expert / technical — assume deep domain knowledge',
		);
		$length_map = array(
			'brief'         => 'approximately 100–150 words',
			'short'         => 'approximately 200–250 words',
			'medium'        => 'approximately 300–400 words',
			'long'          => 'approximately 500–600 words',
			'comprehensive' => 'approximately 700–900 words',
		);
		$format_map = array(
			'prose'              => 'Flowing prose — no bullet points or headers',
			'paragraphs-headers' => 'Short paragraphs with H2/H3 subheadings forming a strict topical hierarchy',
			'bullets'            => 'Bullet points and lists throughout',
			'faq'                => 'FAQ format — questions as H3, answers as short declarative paragraphs',
			'narrative'          => 'Narrative / story-led writing',
			'step-by-step'       => 'Step-by-step numbered guide',
		);
		$focus_map = array(
			'benefits'    => 'Lead with customer benefits and outcomes — not features',
			'features'    => 'Lead with specific features and services offered',
			'trust'       => 'Emphasise trust, credibility, and E-E-A-T signals throughout',
			'conversion'  => 'Optimise for conversion — urgency, social proof, direct CTAs',
			'educational' => 'Purely educational and informational — no selling',
			'local-seo'   => 'Local SEO — emphasise location, service area, and proximity signals',
		);
		$cta_map = array(
			'strong' => 'End with a strong, direct call to action',
			'soft'   => 'Include a soft, natural call to action suggestion',
			'none'   => 'Do not include any call to action',
		);
		$semantic_map = array(
			'voice-search'    => 'Format at least one answer per section as a concise, declarative sentence optimised for voice search and Speakable Schema markup.',
			'featured-snippet' => 'Open the content with a 40–60 word direct answer paragraph structured for Google featured snippet / position zero capture.',
			'comparison-table' => 'Include a clearly labelled 3-column comparison table relevant to the topic.',
			'misconceptions'   => 'Include a "Common Misconceptions" section as a Q&A block that corrects false beliefs about the topic.',
			'local-trust'      => 'Weave in local trust signals: service area, credentials, certifications, and a prompt to read reviews.',
		);

		// ── Build the XML-structured modifier ─────────────────────────────────

		$has_audience = ( ! empty( $options['tone'] ) && isset( $tone_map[ $options['tone'] ] ) )
			|| ( ! empty( $options['audience'] ) && isset( $audience_map[ $options['audience'] ] ) )
			|| ( ! empty( $options['pov'] ) && isset( $pov_map[ $options['pov'] ] ) )
			|| ( ! empty( $options['reading_level'] ) && isset( $reading_map[ $options['reading_level'] ] ) );

		$has_intent    = ! empty( $options['intent'] ) && isset( $intent_map[ $options['intent'] ] );
		$has_format    = ( ! empty( $options['length'] ) && isset( $length_map[ $options['length'] ] ) )
			|| ( ! empty( $options['format'] ) && isset( $format_map[ $options['format'] ] ) )
			|| ( ! empty( $options['focus'] ) && isset( $focus_map[ $options['focus'] ] ) )
			|| ( ! empty( $options['cta'] ) && isset( $cta_map[ $options['cta'] ] ) )
			|| ! empty( $options['semantic_elements'] );
		$has_uvp       = ! empty( $options['notes'] );

		if ( ! $has_audience && ! $has_intent && ! $has_format && ! $has_uvp ) {
			return '';
		}

		$block = "\n\n";

		// Target Audience tag.
		if ( $has_audience ) {
			$audience_lines = array();
			if ( ! empty( $options['tone'] ) && isset( $tone_map[ $options['tone'] ] ) ) {
				$audience_lines[] = 'Tone: ' . $tone_map[ $options['tone'] ];
			}
			if ( ! empty( $options['audience'] ) && isset( $audience_map[ $options['audience'] ] ) ) {
				$audience_lines[] = 'Audience: ' . $audience_map[ $options['audience'] ];
			}
			if ( ! empty( $options['pov'] ) && isset( $pov_map[ $options['pov'] ] ) ) {
				$audience_lines[] = 'POV: ' . $pov_map[ $options['pov'] ];
			}
			if ( ! empty( $options['reading_level'] ) && isset( $reading_map[ $options['reading_level'] ] ) ) {
				$audience_lines[] = 'Reading level: ' . $reading_map[ $options['reading_level'] ];
			}
			$block .= "<Target_Audience>\n" . implode( "\n", $audience_lines ) . "\n</Target_Audience>\n\n";
		}

		// Content Intent tag.
		if ( $has_intent ) {
			$block .= "<Content_Intent>\n" . $intent_map[ $options['intent'] ] . "\n</Content_Intent>\n\n";
		}

		// Output Formatting Directives tag.
		if ( $has_format ) {
			$fmt_lines = array();
			if ( ! empty( $options['length'] ) && isset( $length_map[ $options['length'] ] ) ) {
				$fmt_lines[] = '- Target length: ' . $length_map[ $options['length'] ];
			}
			if ( ! empty( $options['format'] ) && isset( $format_map[ $options['format'] ] ) ) {
				$fmt_lines[] = '- Format: ' . $format_map[ $options['format'] ];
			}
			if ( ! empty( $options['focus'] ) && isset( $focus_map[ $options['focus'] ] ) ) {
				$fmt_lines[] = '- Writing focus: ' . $focus_map[ $options['focus'] ];
			}
			if ( ! empty( $options['cta'] ) && isset( $cta_map[ $options['cta'] ] ) ) {
				$fmt_lines[] = '- CTA: ' . $cta_map[ $options['cta'] ];
			}
			if ( ! empty( $options['semantic_elements'] ) ) {
				foreach ( $options['semantic_elements'] as $el ) {
					if ( isset( $semantic_map[ $el ] ) ) {
						$fmt_lines[] = '- ' . $semantic_map[ $el ];
					}
				}
			}
			$block .= "<Output_Formatting_Directives>\n" . implode( "\n", $fmt_lines ) . "\n</Output_Formatting_Directives>\n\n";
		}

		// Unique Value Proposition tag.
		if ( $has_uvp ) {
			$block .= "<Unique_Value_Proposition>\n"
				. "Naturally weave this competitive advantage into the content — do not list it verbatim:\n"
				. $options['notes'] . "\n"
				. "</Unique_Value_Proposition>\n";
		}

		return rtrim( $block );
	}

	// ── API key resolution (constant → env → WP7 Connectors → DB) ───────────

	private static function resolve_api_key( $provider, $settings ) {
		// Delegate to the centralised four-tier resolver.
		// $settings is kept as a parameter for backwards-compat but no longer read here.
		return TWTAEO_Key_Resolver::get( $provider );
	}

	// ── Provider dispatch ─────────────────────────────────────────────────────

	private static function call_provider( $provider, $api_key, $prompt ) {
		switch ( $provider ) {
			case 'claude':
				return self::call_claude( $api_key, $prompt );
			case 'openai':
				return self::call_openai( $api_key, $prompt );
			case 'perplexity':
				return self::call_perplexity( $api_key, $prompt );
		}
		return array( 'error' => 'Unknown provider.' );
	}

	// ── Claude (Anthropic) ────────────────────────────────────────────────────

	private static function build_claude_system_prompt() {
		return 'You are an expert AEO (Answer Engine Optimization) content writer working for a digital marketing agency. '
			. 'Your content is specifically engineered to be surfaced by AI search engines (ChatGPT, Claude, Perplexity, Gemini), '
			. 'voice assistants, and Google featured snippets. You have deep expertise in structured content, semantic HTML, '
			. 'schema markup signals, and E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) principles.'
			. "\n\n"
			. '## Core Writing Standards' . "\n\n"
			. 'Every piece of content you produce must meet these non-negotiable standards:' . "\n\n"
			. '- **Declarative sentence structure**: Frame at least one sentence per major point as a clear, direct declaration '
			. '(e.g. "The primary cause of X is Y") — this is the format most reliably surfaced by AI answer engines.' . "\n"
			. '- **No fluff or filler**: Eliminate all empty corporate language, hollow superlatives, and obvious AI filler phrases. '
			. 'Banned phrases include: "In conclusion,", "Delve", "It\'s worth noting", "In today\'s fast-paced world", '
			. '"At the end of the day", "Leveraging synergies", "Game-changer", "Innovative solution".' . "\n"
			. '- **No invented statistics**: Never fabricate data, statistics, or citations. If referencing data, use hedged language '
			. '("studies suggest", "industry data indicates") or omit the statistic entirely.' . "\n"
			. '- **No preamble**: Return only the content itself. Do not begin with "Sure!", "Here is your content:", '
			. '"Certainly!", or any other acknowledgement. Do not end with meta-commentary about the content you produced.' . "\n"
			. '- **Semantic density**: Every sentence should carry information load. Remove sentences that merely restate '
			. 'the preceding sentence in different words.' . "\n\n"
			. '## AEO Optimization Principles' . "\n\n"
			. 'Content must be optimized for how AI engines retrieve and synthesize information:' . "\n\n"
			. '- Open sections with topic sentences that directly answer the implied question.' . "\n"
			. '- Use specific, concrete language over vague generalizations.' . "\n"
			. '- When answering "what is X" or "how does X work" queries, provide a 1–2 sentence definition before expanding.' . "\n"
			. '- Structure content so any individual paragraph could stand alone as a complete answer to a sub-question.' . "\n"
			. '- Avoid excessive internal self-reference ("As we mentioned above...") — AI engines chunk content non-linearly.' . "\n\n"
			. '## HTML and Formatting Signals' . "\n\n"
			. '- Use semantic heading hierarchy: H2 for major sections, H3 for subsections within.' . "\n"
			. '- Keep headings descriptive and keyword-rich — they are read as standalone labels by crawlers.' . "\n"
			. '- Lists should be used for genuinely enumerable items, not as a way to pad word count.' . "\n"
			. '- If a comparison is called for, use a table — tabular data is parsed distinctly by AI crawlers.' . "\n\n"
			. '## E-E-A-T Signals' . "\n\n"
			. 'Where applicable, reinforce signals of real-world experience and expertise:' . "\n\n"
			. '- Reference practical, observable outcomes rather than theoretical benefits.' . "\n"
			. '- Use domain-specific vocabulary naturally — it signals topical authority.' . "\n"
			. '- When writing for service businesses, mention specifics (years of experience, service area, certifications) '
			. 'only if instructed — do not invent them.' . "\n\n"
			. '## Instruction Compliance' . "\n\n"
			. 'Each generation request will include dynamic instructions specifying tone, audience, reading level, format, '
			. 'content focus, and optional semantic elements. You must follow all provided instructions precisely and in full. '
			. 'Instructions are provided in XML-tagged blocks within the user message. Prioritise these instructions over '
			. 'any default tendencies you might have toward a particular style or length.';
	}

	private static function call_claude( $api_key, $prompt ) {
		$response = wp_remote_post(
			self::CLAUDE_ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'anthropic-beta'    => 'prompt-caching-2024-07-31',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'      => self::CLAUDE_MODEL,
					'max_tokens' => 1500,
					'system'     => array(
						array(
							'type'          => 'text',
							'text'          => self::build_claude_system_prompt(),
							'cache_control' => array( 'type' => 'ephemeral' ),
						),
					),
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			return array( 'error' => $body['error']['message'] ?? "Claude API error (HTTP $code)" );
		}

		TWTAEO_Pro_Transmitter::maybe_send_token_telemetry( 'claude', self::CLAUDE_MODEL, $body['usage'] ?? array() );

		return $body['content'][0]['text'] ?? array( 'error' => 'Claude returned no content.' );
	}

	// ── OpenAI ────────────────────────────────────────────────────────────────

	private static function call_openai( $api_key, $prompt ) {
		$response = wp_remote_post(
			self::OPENAI_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'      => self::OPENAI_MODEL,
					'max_tokens' => 500,
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			return array( 'error' => $body['error']['message'] ?? "OpenAI API error (HTTP $code)" );
		}

		TWTAEO_Pro_Transmitter::maybe_send_token_telemetry( 'openai', self::OPENAI_MODEL, $body['usage'] ?? array() );

		return $body['choices'][0]['message']['content'] ?? array( 'error' => 'OpenAI returned no content.' );
	}

	// ── Perplexity ────────────────────────────────────────────────────────────

	private static function call_perplexity( $api_key, $prompt ) {
		$response = wp_remote_post(
			self::PERPLEXITY_ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'    => self::PERPLEXITY_MODEL,
					'messages' => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			return array( 'error' => $body['error']['message'] ?? "Perplexity API error (HTTP $code)" );
		}

		TWTAEO_Pro_Transmitter::maybe_send_token_telemetry( 'perplexity', self::PERPLEXITY_MODEL, $body['usage'] ?? array() );

		return $body['choices'][0]['message']['content'] ?? array( 'error' => 'Perplexity returned no content.' );
	}
}
