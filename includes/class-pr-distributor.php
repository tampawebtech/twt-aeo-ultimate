<?php
/**
 * PR Bridge — Distributor
 *
 * Submits formatted press releases to wire service APIs.
 * Supported tiers:
 *   growth     → EIN Presswire  (SMBs, SEO agencies)
 *   budget     → EasyPRWire     (niche / local)
 *   enterprise → PR Newswire    (contact for credentials — placeholder)
 *
 * Submission history is stored in post meta so the Media Report page
 * can list all distributions without a custom database table.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_PR_Distributor {

	const META_KEY_SUBMISSIONS = '_twtaeo_pr_submissions';

	const TIERS = array(
		'growth' => array(
			'label'    => 'EIN Presswire',
			'audience' => 'SMBs, Startups & SEO Agencies',
			'setting'  => 'api_ein_presswire',
		),
		'budget' => array(
			'label'    => 'EasyPRWire',
			'audience' => 'Niche & Local Announcements',
			'setting'  => 'api_easypwire',
		),
		'enterprise' => array(
			'label'    => 'PR Newswire / Cision',
			'audience' => 'Fortune 500 & Financial News',
			'setting'  => 'api_prnewswire',
			'contact'  => true,
		),
	);

	/**
	 * Return available tiers with their configured status.
	 *
	 * @return array
	 */
	public static function get_tiers() {
		$settings = get_option( 'twtaeo_settings', array() );
		$result   = array();

		foreach ( self::TIERS as $slug => $tier ) {
			$configured = ! empty( trim( $settings[ $tier['setting'] ] ?? '' ) );
			$result[]   = array_merge( $tier, array(
				'slug'       => $slug,
				'configured' => $configured,
			) );
		}

		return $result;
	}

	/**
	 * Submit the formatted press release to the chosen tier.
	 *
	 * @param int    $post_id
	 * @param string $tier_slug  'growth' | 'budget' | 'enterprise'
	 * @return array { success: bool, message: string, url: string }
	 */
	public static function submit( $post_id, $tier_slug ) {
		$formatted = TWTAEO_PR_Transformer::get( $post_id );
		if ( empty( $formatted ) ) {
			return array( 'success' => false, 'message' => 'No formatted press release found. Professionalize the post first.' );
		}

		$tier = self::TIERS[ $tier_slug ] ?? null;
		if ( ! $tier ) {
			return array( 'success' => false, 'message' => 'Unknown distribution tier.' );
		}

		if ( ! empty( $tier['contact'] ) ) {
			return array( 'success' => false, 'message' => 'Enterprise tier requires a direct account. Contact PR Newswire / Cision to set up API access.' );
		}

		$settings = get_option( 'twtaeo_settings', array() );
		$api_key  = trim( $settings[ $tier['setting'] ] ?? '' );

		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'message' => "No API key configured for {$tier['label']}. Add it in AEO → Settings." );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'message' => 'Post not found.' );
		}

		// Extract headline from the formatted PR (first ALL-CAPS line after the contact block).
		$headline = self::extract_headline( $formatted ) ?: $post->post_title;

		switch ( $tier_slug ) {
			case 'growth':
				$result = self::submit_ein( $api_key, $headline, $formatted, $post );
				break;
			case 'budget':
				$result = self::submit_easypwire( $api_key, $headline, $formatted, $post );
				break;
			default:
				$result = array( 'success' => false, 'message' => 'Unsupported tier.' );
		}

		// Store the submission record.
		self::record_submission( $post_id, $tier_slug, $tier['label'], $result );

		// Notify the pro dashboard.
		if ( $result['success'] && class_exists( 'TWTAEO_Pro_Transmitter' ) ) {
			TWTAEO_Pro_Transmitter::send_press_release_distribution(
				$post_id, $tier_slug, $tier['label'], $result['url'] ?? ''
			);
		}

		return $result;
	}

	/**
	 * Get all submission records for a post.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_submissions( $post_id ) {
		$data = get_post_meta( $post_id, self::META_KEY_SUBMISSIONS, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get all posts that have at least one submission.
	 *
	 * @return WP_Post[]
	 */
	public static function get_all_distributed_posts() {
		$query = new WP_Query( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_KEY_SUBMISSIONS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'compare' => 'EXISTS',
				),
			),
		) );

		return $query->posts;
	}

	// ── Wire service API calls ────────────────────────────────────────────────

	/**
	 * Submit to EIN Presswire REST API.
	 * Docs: https://www.einpresswire.com/api/
	 */
	private static function submit_ein( $api_key, $headline, $body, WP_Post $post ) {
		$response = wp_remote_post(
			'https://www.einpresswire.com/api/v1/news',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'headline'    => $headline,
					'body'        => $body,
					'source_url'  => get_permalink( $post ),
					'publish'     => true,
				) ),
			)
		);

		return self::parse_response( $response, 'EIN Presswire' );
	}

	/**
	 * Submit to EasyPRWire API.
	 * Docs: https://www.easypwire.com/api
	 */
	private static function submit_easypwire( $api_key, $headline, $body, WP_Post $post ) {
		$response = wp_remote_post(
			'https://www.easypwire.com/api/submit',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'api_key'   => $api_key,
					'title'     => $headline,
					'body'      => $body,
					'source'    => get_permalink( $post ),
				),
			)
		);

		return self::parse_response( $response, 'EasyPRWire' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function parse_response( $response, $provider ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $provider . ' connection error: ' . $response->get_error_message(),
				'url'     => '',
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$url = $body['url'] ?? $body['release_url'] ?? $body['link'] ?? '';
			return array(
				'success' => true,
				'message' => "Successfully submitted to $provider.",
				'url'     => $url,
			);
		}

		$error = $body['message'] ?? $body['error'] ?? "HTTP $code";
		return array(
			'success' => false,
			'message' => "$provider error: $error",
			'url'     => '',
		);
	}

	private static function extract_headline( $formatted ) {
		$lines = explode( "\n", $formatted );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Look for a line in ALL CAPS that's long enough to be a headline.
			if ( strlen( $line ) > 10 && $line === strtoupper( $line ) && preg_match( '/[A-Z]{3}/', $line ) ) {
				return ucwords( strtolower( $line ) );
			}
		}
		return '';
	}

	private static function record_submission( $post_id, $tier_slug, $tier_label, $result ) {
		$submissions   = self::get_submissions( $post_id );
		$submissions[] = array(
			'tier'         => $tier_slug,
			'provider'     => $tier_label,
			'submitted_at' => current_time( 'mysql' ),
			'success'      => $result['success'],
			'message'      => $result['message'],
			'url'          => $result['url'] ?? '',
		);
		update_post_meta( $post_id, self::META_KEY_SUBMISSIONS, $submissions );
	}
}
