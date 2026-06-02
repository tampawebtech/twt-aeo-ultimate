<?php
/**
 * PR Bridge — Intent Detector
 *
 * Detects whether a post is likely a press release via three signals:
 *  1. Category match  (Press Release / News / Media / Announcements)
 *  2. Keyword scan    (AP-style trigger phrases in title or content)
 *  3. Claude analysis (optional — runs when Claude API key is present)
 *
 * Result is cached in post meta so the metabox reads it instantly.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_PR_Detector {

	const META_KEY = '_twtaeo_pr_intent';

	/** Category slugs/names that signal a press release. */
	const PR_CATEGORIES = array(
		'press-release', 'press-releases',
		'news', 'media', 'announcements', 'announcement',
		'press', 'newsroom',
	);

	/** Phrases that immediately identify AP-style press release content. */
	const PR_KEYWORDS = array(
		'FOR IMMEDIATE RELEASE',
		'Media Contact',
		'MEDIA CONTACT',
		'FOR IMMEDIATE RELEASE:',
		'Contact Information',
		'###',
		'About [',
	);

	/** Title/slug words that immediately identify a page as a press release. */
	const PR_TITLE_TERMS = array(
		'press release',
		'press-release',
		'news release',
		'news-release',
		'media release',
		'media-release',
		'press announcement',
		'newsroom',
	);

	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	/**
	 * Run detection on post save and cache the result.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$result = self::detect( $post );
		update_post_meta( $post_id, self::META_KEY, $result );
	}

	/**
	 * Show a dismissible admin notice on the post edit screen when intent is detected.
	 */
	public static function maybe_show_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post' ), true ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}

		$intent = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $intent['detected'] ) ) {
			return;
		}

		// Don't show if user already has a formatted PR saved.
		$has_formatted = (bool) get_post_meta( $post_id, TWTAEO_PR_Transformer::META_KEY, true );
		if ( $has_formatted ) {
			return;
		}

		$method_labels = array(
			'title'    => 'page title',
			'category' => 'post category',
			'keyword'  => 'content keywords',
			'claude'   => 'AI analysis',
		);
		$method = $method_labels[ $intent['method'] ] ?? 'content signals';

		?>
		<div class="notice notice-info is-dismissible twt-aeo-pr-notice" id="twt-aeo-pr-notice">
			<p>
				<strong><?php esc_html_e( 'PR Bridge AI:', 'twt-aeo-ultimate' ); ?></strong>
				<?php
				printf(
					// translators: %s: detection method label (e.g. 'page title', 'AI analysis').
					esc_html__( 'This post looks like a press release (detected via %s). Open the PR Bridge panel to professionalize and distribute it.', 'twt-aeo-ultimate' ),
					esc_html( $method )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Run all detection signals against a post.
	 *
	 * @param WP_Post $post
	 * @return array { detected: bool, method: string, confidence: string }
	 */
	public static function detect( WP_Post $post ) {
		// Signal 1 — page title or slug contains press release terms.
		if ( self::has_pr_title( $post ) ) {
			return array(
				'detected'   => true,
				'method'     => 'title',
				'confidence' => 'high',
			);
		}

		// Signal 2 — category (posts only; pages don't use categories by default).
		if ( self::has_pr_category( $post ) ) {
			return array(
				'detected'   => true,
				'method'     => 'category',
				'confidence' => 'high',
			);
		}

		// Signal 3 — keywords in content.
		if ( self::has_pr_keywords( $post ) ) {
			return array(
				'detected'   => true,
				'method'     => 'keyword',
				'confidence' => 'high',
			);
		}

		// Signal 3 — Claude intent (only if API key is available).
		$api_key = TWTAEO_Key_Resolver::get( 'claude' );

		if ( $api_key ) {
			$claude_result = self::analyze_with_claude( $post, $api_key );
			if ( $claude_result ) {
				return array(
					'detected'   => true,
					'method'     => 'claude',
					'confidence' => 'medium',
				);
			}
		}

		return array(
			'detected'   => false,
			'method'     => '',
			'confidence' => '',
		);
	}

	/**
	 * Get the cached detection result for a post, or run a fresh detect.
	 *
	 * @param int|WP_Post $post
	 * @return array
	 */
	public static function get_intent( $post ) {
		$post = is_int( $post ) ? get_post( $post ) : $post;
		if ( ! $post ) {
			return array( 'detected' => false, 'method' => '', 'confidence' => '' );
		}

		$cached = get_post_meta( $post->ID, self::META_KEY, true );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		return self::detect( $post );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private static function has_pr_title( WP_Post $post ) {
		$haystack = strtolower( $post->post_title . ' ' . $post->post_name );
		foreach ( self::PR_TITLE_TERMS as $term ) {
			if ( strpos( $haystack, $term ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function has_pr_category( WP_Post $post ) {
		$categories = get_the_category( $post->ID );
		foreach ( $categories as $cat ) {
			$slug = strtolower( $cat->slug );
			$name = strtolower( $cat->name );
			foreach ( self::PR_CATEGORIES as $pr_cat ) {
				if ( $slug === $pr_cat || $name === $pr_cat ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function has_pr_keywords( WP_Post $post ) {
		$haystack = strtoupper( $post->post_title . ' ' . wp_strip_all_tags( $post->post_content ) );
		foreach ( self::PR_KEYWORDS as $phrase ) {
			if ( strpos( $haystack, strtoupper( $phrase ) ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function analyze_with_claude( WP_Post $post, $api_key ) {
		$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 120 );
		$prompt  = 'Analyze this post title and excerpt. Reply with only "YES" if it reads like a press release announcing a major event (product launch, partnership, acquisition, executive hire, funding round, award, or official statement). Reply "NO" otherwise.'
			. "\n\nTitle: " . $post->post_title
			. "\n\nExcerpt: " . $excerpt;

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'      => 'claude-haiku-4-5-20251001',
					'max_tokens' => 5,
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = strtoupper( trim( $body['content'][0]['text'] ?? '' ) );

		return strpos( $text, 'YES' ) !== false;
	}
}
