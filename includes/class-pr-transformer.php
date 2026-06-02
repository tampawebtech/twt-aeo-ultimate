<?php
/**
 * PR Bridge — AP-Style Transformer
 *
 * Calls Claude with a professional AP-style press release prompt and
 * stores the formatted result in post meta without touching the original.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_PR_Transformer {

	const META_KEY          = '_twtaeo_pr_formatted';
	const META_KEY_SAVED_AT = '_twtaeo_pr_formatted_at';

	/**
	 * Call Claude to transform a post into AP-style press release copy.
	 * Returns the formatted text or a WP_Error.
	 *
	 * @param int $post_id
	 * @return string|WP_Error
	 */
	public static function transform( $post_id ) {
		$api_key = TWTAEO_Key_Resolver::get( 'claude' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Claude API key is not configured. Add it in AEO → Settings.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', 'Post not found.' );
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );
		$content   = wp_strip_all_tags( $post->post_content );
		$today     = gmdate( 'F j, Y' );

		$prompt = self::build_prompt( $post->post_title, $content, $site_name, $site_url, $today );

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'      => 'claude-sonnet-4-6',
					'max_tokens' => 2000,
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? "Claude API error (HTTP $code)";
			return new WP_Error( 'api_error', $msg );
		}

		$text = $body['content'][0]['text'] ?? '';
		if ( empty( $text ) ) {
			return new WP_Error( 'empty_response', 'Claude returned no content.' );
		}

		return $text;
	}

	/**
	 * Save the formatted press release to post meta.
	 *
	 * @param int    $post_id
	 * @param string $content  The formatted AP-style text.
	 * @return bool
	 */
	public static function save( $post_id, $content ) {
		$saved = update_post_meta( $post_id, self::META_KEY, sanitize_textarea_field( $content ) );
		update_post_meta( $post_id, self::META_KEY_SAVED_AT, current_time( 'mysql' ) );
		return (bool) $saved;
	}

	/**
	 * Retrieve the saved formatted press release.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function get( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Delete the saved formatted press release.
	 *
	 * @param int $post_id
	 */
	public static function delete( $post_id ) {
		delete_post_meta( $post_id, self::META_KEY );
		delete_post_meta( $post_id, self::META_KEY_SAVED_AT );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private static function build_prompt( $title, $content, $site_name, $site_url, $today ) {
		$lines = array(
			'You are a senior AP-style press release editor. Transform the following blog post into a professional, distribution-ready press release.',
			'',
			'POST TITLE: ' . $title,
			'SITE/COMPANY: ' . $site_name . ' (' . $site_url . ')',
			'DATE: ' . $today,
			'',
			'POST CONTENT:',
			$content,
			'',
			'---',
			'',
			'OUTPUT FORMAT — follow this structure exactly:',
			'',
			'FOR IMMEDIATE RELEASE',
			'',
			'[MEDIA CONTACT BLOCK — infer from content or use site name]',
			'Media Contact:',
			'[Full Name or "' . $site_name . ' Press Team"]',
			'[Title if available]',
			'[Email — use placeholder press@' . $site_name . '.com if not found]',
			'[Phone — use placeholder if not found]',
			'',
			'[HEADLINE — compelling, present tense, active voice, no more than 12 words, ALL CAPS]',
			'',
			'[SUBHEADLINE — one sentence expanding the headline, title case]',
			'',
			'[City, State] — ' . $today . ' — [Lead paragraph: answer who, what, when, where, why in 1–2 punchy sentences. Lead with the most newsworthy fact.]',
			'',
			'[Body paragraph 1: expand on the key announcement with specifics, data points, or context]',
			'',
			'[Body paragraph 2: supporting details, background, or market context]',
			'',
			'"[Compelling quote from a key stakeholder — infer the speaker\'s name/title from the content or use \'' . $site_name . ' leadership\']," said [Name], [Title], ' . $site_name . '.',
			'',
			'[Body paragraph 3: call to action, next steps, or additional information]',
			'',
			'About ' . $site_name . ':',
			'[2–3 sentence boilerplate about the company. Infer from the content and site name. Focus on what they do, who they serve, and their mission.]',
			'',
			'###',
		);
		return implode( "\n", $lines );
	}
}
