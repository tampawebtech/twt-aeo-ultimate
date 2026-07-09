<?php
/**
 * E-E-A-T Detector
 *
 * Scans the site for Experience, Expertise, Authoritativeness, and
 * Trustworthiness signals. Reuses existing detector classes where possible.
 * Caches results in a site option and rescans on demand.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_EEAT_Detector {

	/**
	 * Option key for cached scan result.
	 */
	const OPTION_KEY = 'twtaeo_eeat_scan';

	/**
	 * Register hooks that keep the cached scan in sync with the environment.
	 *
	 * The scan reads from SEO plugins (Rank Math, Yoast, …) and the TWT AEO
	 * Company Profile. When a plugin is activated or deactivated/deleted the
	 * available signals change, so the cached result must be discarded — WordPress
	 * forces deactivation before deletion, so deactivated_plugin covers deletes.
	 */
	public static function register_hooks() {
		add_action( 'activated_plugin',   array( __CLASS__, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Run a full E-E-A-T scan and cache the result.
	 *
	 * @return array
	 */
	public static function scan() {
		$result = array(
			'experience'       => self::scan_experience(),
			'expertise'        => self::scan_expertise(),
			'authoritativeness' => self::scan_authoritativeness(),
			'trustworthiness'  => self::scan_trustworthiness(),
			'scanned_at'       => current_time( 'mysql' ),
		);

		$result['total_score'] = $result['experience']['score']
			+ $result['expertise']['score']
			+ $result['authoritativeness']['score']
			+ $result['trustworthiness']['score'];

		update_option( self::OPTION_KEY, $result );
		return $result;
	}

	/**
	 * Get cached scan result, or run a fresh scan if none exists.
	 *
	 * @return array
	 */
	public static function get_or_scan() {
		$cached = get_option( self::OPTION_KEY, null );
		if ( $cached && is_array( $cached ) ) {
			return $cached;
		}
		return self::scan();
	}

	/**
	 * Clear cached scan result.
	 */
	public static function clear_cache() {
		delete_option( self::OPTION_KEY );
	}

	// ── Experience ────────────────────────────────────────────────────────────

	/**
	 * Scan for Experience signals.
	 * Max score: 25
	 */
	private static function scan_experience() {
		$signals  = array();
		$missing  = array();
		$score    = 0;

		// Author bio present (10pts).
		$author_scan = class_exists( 'TWTAEO_Author_Box_Detector' )
			? TWTAEO_Author_Box_Detector::scan_site()
			: null;

		if ( $author_scan && $author_scan['author_info']['has_bio'] ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Author bio present', 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'Author bio missing',
				'points' => 10,
				'fix'    => 'Add a Biographical Info description to your author profile.',
				'action' => array(
					'type'    => 'url',
					'label'   => 'Edit Authors',
					'url'     => admin_url( 'users.php' ),
					'new_tab' => false,
				),
			);
		}

		// About page detected (10pts).
		$about_page = self::find_page_by_slug_or_title( array( 'about', 'about-us', 'our-story', 'who-we-are', 'team' ), array( 'About', 'About Us', 'Our Story', 'Who We Are' ) );
		if ( $about_page ) {
			$score    += 10;
			$signals[] = array( 'label' => 'About page found: ' . get_the_title( $about_page ), 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'No About page detected',
				'points' => 10,
				'fix'    => 'Create an About Us page to establish who is behind the site.',
				'action' => array( 'type' => 'create_page', 'title' => 'About Us', 'slug' => 'about-us', 'content' => "<!-- wp:paragraph -->\n<p>Tell your story here — who you are, what you do, and why clients choose you.</p>\n<!-- /wp:paragraph -->" ),
			);
		}

		// Testimonials or reviews detected (5pts).
		$has_testimonials = self::detect_testimonials();
		if ( $has_testimonials ) {
			$score    += 5;
			$signals[] = array( 'label' => 'Testimonial or review content detected', 'points' => 5 );
		} else {
			$missing[] = array(
				'label'  => 'No testimonials or review content detected',
				'points' => 5,
				'fix'    => 'Add a testimonials page or review section to show real client experiences.',
				'action' => array( 'type' => 'create_page', 'title' => 'Testimonials', 'slug' => 'testimonials', 'content' => "<!-- wp:paragraph -->\n<p>Add your client testimonials and reviews here.</p>\n<!-- /wp:paragraph -->" ),
			);
		}

		return array( 'score' => $score, 'max' => 25, 'signals' => $signals, 'missing' => $missing );
	}

	// ── Expertise ─────────────────────────────────────────────────────────────

	/**
	 * Scan for Expertise signals.
	 * Max score: 25
	 */
	private static function scan_expertise() {
		$signals = array();
		$missing = array();
		$score   = 0;

		// Author schema with name (10pts).
		$has_author_schema = false;

		// TWT AEO Company Profile — the plugin's own Organization schema source.
		if ( class_exists( 'TWTAEO_Company_Profile' ) ) {
			$company = TWTAEO_Company_Profile::get();
			if ( ! empty( $company['output_org_schema'] ) && ! empty( $company['name'] ) ) {
				$has_author_schema = true;
			}
		}

		// Any active SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress, SEO Framework)
		// with a configured Organization / Knowledge-Graph name also counts.
		if ( ! $has_author_schema && ! empty( self::get_seo_plugin_org_names() ) ) {
			$has_author_schema = true;
		}

		if ( $has_author_schema ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Author / Organization schema with name present', 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'Author or Organization schema name missing',
				'points' => 10,
				'fix'    => 'In TWT AEO → Company Profile, enable Organization schema and set your business name. (Rank Math and Yoast company names are also detected.)',
				'action' => null,
			);
		}

		// Article datePublished + dateModified present on posts (10pts).
		$has_dates = self::check_article_dates();
		if ( $has_dates ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Posts have datePublished and dateModified in schema', 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'Article datePublished / dateModified not detected in schema',
				'points' => 10,
				'fix'    => 'Make sure your SEO plugin outputs Article schema with datePublished and dateModified on blog posts. AI models use dateModified to avoid serving stale content.',
				'action' => null,
			);
		}

		// Hub/topic cluster structure (5pts).
		$has_hubs = self::detect_hub_structure();
		if ( $has_hubs ) {
			$score    += 5;
			$signals[] = array( 'label' => 'Topic cluster / hub structure detected', 'points' => 5 );
		} else {
			$missing[] = array(
				'label'  => 'No hub or topic cluster structure detected',
				'points' => 5,
				'fix'    => 'Create hub pages with supporting posts linked back to the hub to establish topic authority.',
				'action' => null,
			);
		}

		return array( 'score' => $score, 'max' => 25, 'signals' => $signals, 'missing' => $missing );
	}

	// ── Authoritativeness ─────────────────────────────────────────────────────

	/**
	 * Scan for Authoritativeness signals.
	 * Max score: 25
	 */
	private static function scan_authoritativeness() {
		$signals = array();
		$missing = array();
		$score   = 0;

		// Organization sameAs links (10pts).
		$same_as = self::get_same_as_links();
		if ( ! empty( $same_as ) ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Organization sameAs links present (' . count( $same_as ) . ' found)', 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'No sameAs links detected',
				'points' => 10,
				'fix'    => 'Add sameAs links to your Organization schema — LinkedIn, Google Business Profile, industry directories.',
				'action' => array( 'type' => 'modal', 'modal' => 'sameas' ),
			);
		}

		// Consistent business name across schema (10pts).
		$name_consistent = self::check_name_consistency();
		if ( $name_consistent ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Business name consistent across schema', 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'Business name inconsistency detected across schema sources',
				'points' => 10,
				'fix'    => 'Make sure your business name is identical in your SEO plugin settings, schema, and site title.',
				'action' => null,
			);
		}

		// Review schema present (5pts).
		$has_reviews = self::detect_review_schema();
		if ( $has_reviews ) {
			$score    += 5;
			$signals[] = array( 'label' => 'Review or AggregateRating schema detected', 'points' => 5 );
		} else {
			$missing[] = array(
				'label'  => 'No Review or AggregateRating schema detected',
				'points' => 5,
				'fix'    => 'Add review schema to service or product pages to show star ratings in search results.',
				'action' => null,
			);
		}

		return array( 'score' => $score, 'max' => 25, 'signals' => $signals, 'missing' => $missing );
	}

	// ── Trustworthiness ───────────────────────────────────────────────────────

	/**
	 * Scan for Trustworthiness signals.
	 * Max score: 25
	 */
	private static function scan_trustworthiness() {
		$signals = array();
		$missing = array();
		$score   = 0;

		// Privacy Policy page (10pts).
		$privacy = self::find_page_by_slug_or_title(
			array( 'privacy-policy', 'privacy', 'privacy-statement' ),
			array( 'Privacy Policy', 'Privacy', 'Privacy Statement' )
		);
		if ( $privacy ) {
			$score    += 10;
			$signals[] = array( 'label' => 'Privacy Policy page found: ' . get_the_title( $privacy ), 'points' => 10 );
		} else {
			$missing[] = array(
				'label'  => 'No Privacy Policy page detected',
				'points' => 10,
				'fix'    => 'Create a Privacy Policy page — required for GDPR compliance and trusted by AI systems.',
				'action' => array( 'type' => 'create_page', 'title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'content' => "<!-- wp:paragraph -->\n<p>This Privacy Policy describes how we collect, use, and protect your personal information.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>Add your full privacy policy content here. Consider using a privacy policy generator for a complete template.</p>\n<!-- /wp:paragraph -->" ),
			);
		}

		// Contact page with address or phone (10pts).
		$contact_scan = null;
		if ( class_exists( 'TWTAEO_Contact_Detector' ) ) {
			$contact_results = TWTAEO_Contact_Detector::scan_all();
			if ( ! empty( $contact_results ) ) {
				$contact_data = $contact_results[0]['contact_data'];
				if ( $contact_data['has_postal_address'] || $contact_data['has_contact_point'] ) {
					$score    += 10;
					$signals[] = array( 'label' => 'Contact page with address or phone detected', 'points' => 10 );
					$contact_scan = true;
				}
			}
		}
		if ( ! $contact_scan ) {
			$contact_page = self::find_page_by_slug_or_title(
				array( 'contact', 'contact-us', 'get-in-touch' ),
				array( 'Contact', 'Contact Us', 'Get In Touch' )
			);
			if ( $contact_page ) {
				$score    += 5; // Partial credit — page exists but schema may be missing.
				$signals[] = array( 'label' => 'Contact page found (schema may be incomplete)', 'points' => 5 );
			} else {
				$missing[] = array(
					'label'  => 'No Contact page detected',
					'points' => 10,
					'fix'    => 'Create a Contact page with your business address and phone number.',
					'action' => array( 'type' => 'create_page', 'title' => 'Contact Us', 'slug' => 'contact-us', 'content' => "<!-- wp:paragraph -->\n<p>Get in touch with us. Add your contact form, address, phone number, and business hours here.</p>\n<!-- /wp:paragraph -->" ),
				);
			}
		}

		// HTTPS active (5pts).
		$is_https = strpos( home_url(), 'https://' ) === 0;
		if ( $is_https ) {
			$score    += 5;
			$signals[] = array( 'label' => 'HTTPS active', 'points' => 5 );
		} else {
			$missing[] = array(
				'label'  => 'HTTPS not detected',
				'points' => 5,
				'fix'    => 'Enable SSL on your hosting account and update your WordPress URL to use https://.',
				'action' => null,
			);
		}

		return array( 'score' => $score, 'max' => 25, 'signals' => $signals, 'missing' => $missing );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Find a published page by slug patterns or title patterns.
	 *
	 * @param string[] $slugs
	 * @param string[] $titles
	 * @return WP_Post|null
	 */
	private static function find_page_by_slug_or_title( $slugs, $titles ) {
		// Check by slug.
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page && $page->post_status === 'publish' ) {
				return $page;
			}
		}

		// Check by title.
		foreach ( $titles as $title ) {
			$pages = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'title'          => $title,
				'posts_per_page' => 1,
			) );
			if ( ! empty( $pages ) ) {
				return $pages[0];
			}
		}

		return null;
	}

	/**
	 * Detect testimonial or review content anywhere on the site.
	 *
	 * @return bool
	 */
	private static function detect_testimonials() {
		$keywords = array( 'testimonial', 'testimonials', 'review', 'reviews', 'what our clients say', 'what customers say', 'client stories' );

		// Check page titles and slugs.
		$pages = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
		) );

		foreach ( $pages as $page ) {
			$title = strtolower( get_the_title( $page ) );
			$slug  = $page->post_name ?? '';
			foreach ( $keywords as $kw ) {
				if ( strpos( $title, $kw ) !== false || strpos( $slug, $kw ) !== false ) {
					return true;
				}
			}
		}

		// Check for review schema on any post.
		$posts_with_review = get_posts( array(
			'post_type'      => array( 'page', 'post', 'product' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'saswp_custom_schema_field', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'compare' => 'EXISTS',
				),
			),
		) );

		foreach ( $posts_with_review as $post ) {
			$schema = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
			if ( $schema && ( strpos( $schema, '"Review"' ) !== false || strpos( $schema, '"AggregateRating"' ) !== false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if posts have datePublished in their schema or meta.
	 *
	 * @return bool
	 */
	private static function check_article_dates() {
		// If Rank Math or Yoast is active they output datePublished automatically on posts.
		if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
			$posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			) );
			if ( ! empty( $posts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect hub/topic cluster structure — pages with child pages or
	 * posts grouped under a common parent.
	 *
	 * @return bool
	 */
	private static function detect_hub_structure() {
		// Check for pages that have child pages.
		$pages_with_children = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
		) );

		foreach ( $pages_with_children as $page ) {
			$children = get_children( array(
				'post_parent' => $page->ID,
				'post_type'   => array( 'page', 'post' ),
				'post_status' => 'publish',
				'numberposts' => 1,
			) );
			if ( ! empty( $children ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get sameAs links from the TWT AEO Company Profile and SEO plugin settings.
	 *
	 * The "Add Links" modal on the E-E-A-T page saves into the Company Profile
	 * option (TWTAEO_Company_Profile), so that store must be read here — otherwise
	 * links the user saves never satisfy the signal on rescan.
	 *
	 * @return string[]
	 */
	private static function get_same_as_links() {
		$links = array();

		if ( class_exists( 'TWTAEO_Company_Profile' ) ) {
			$company = TWTAEO_Company_Profile::get();
			$social_keys = array(
				'social_facebook', 'social_twitter', 'social_instagram',
				'social_linkedin', 'social_youtube', 'social_wikipedia', 'social_pinterest',
			);
			foreach ( $social_keys as $key ) {
				if ( ! empty( $company[ $key ] ) ) {
					$links[] = $company[ $key ];
				}
			}
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm = get_option( 'rank_math_titles', array() );
			$social_keys = array( 'social_url_facebook', 'social_url_twitter', 'social_url_instagram', 'social_url_linkedin', 'social_url_youtube', 'social_url_pinterest' );
			foreach ( $social_keys as $key ) {
				if ( ! empty( $rm[ $key ] ) ) {
					$links[] = $rm[ $key ];
				}
			}
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$social = get_option( 'wpseo_social', array() );
			$social_keys = array( 'facebook_site', 'twitter_site', 'instagram_url', 'linkedin_url', 'youtube_url', 'pinterest_url' );
			foreach ( $social_keys as $key ) {
				if ( ! empty( $social[ $key ] ) ) {
					$links[] = $social[ $key ];
				}
			}
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$urls = self::get_aioseo_options()['social']['profiles']['urls'] ?? array();
			$social_keys = array( 'facebookPageUrl', 'twitterUrl', 'instagramUrl', 'linkedinUrl', 'youtubeUrl', 'pinterestUrl' );
			foreach ( $social_keys as $key ) {
				if ( ! empty( $urls[ $key ] ) ) {
					$links[] = $urls[ $key ];
				}
			}
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$social = get_option( 'seopress_social', array() );
			$social_keys = array(
				'seopress_social_accounts_facebook', 'seopress_social_accounts_twitter',
				'seopress_social_accounts_instagram', 'seopress_social_accounts_linkedin',
				'seopress_social_accounts_youtube', 'seopress_social_accounts_pinterest',
			);
			foreach ( $social_keys as $key ) {
				if ( ! empty( $social[ $key ] ) ) {
					$links[] = $social[ $key ];
				}
			}
		}

		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$tsf = get_option( 'autodescription-site-settings', array() );
			$social_keys = array(
				'knowledge_facebook', 'knowledge_twitter', 'knowledge_instagram',
				'knowledge_linkedin', 'knowledge_youtube', 'knowledge_pinterest',
			);
			foreach ( $social_keys as $key ) {
				if ( ! empty( $tsf[ $key ] ) ) {
					$links[] = $tsf[ $key ];
				}
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	/**
	 * Collect Organization / Knowledge-Graph names configured in any active SEO
	 * plugin (Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework).
	 *
	 * @return string[] Trimmed names (may be empty).
	 */
	private static function get_seo_plugin_org_names() {
		$names = array();

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm = get_option( 'rank_math_titles', array() );
			if ( ! empty( $rm['knowledgegraph_name'] ) ) {
				$names[] = $rm['knowledgegraph_name'];
			}
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_titles', array() );
			if ( ! empty( $wpseo['company_name'] ) ) {
				$names[] = $wpseo['company_name'];
			}
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$name = self::get_aioseo_options()['searchAppearance']['global']['schema']['organizationName'] ?? '';
			if ( ! empty( $name ) ) {
				$names[] = $name;
			}
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$social = get_option( 'seopress_social', array() );
			if ( ! empty( $social['seopress_social_knowledge_name'] ) ) {
				$names[] = $social['seopress_social_knowledge_name'];
			}
		}

		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$tsf = get_option( 'autodescription-site-settings', array() );
			if ( ! empty( $tsf['knowledge_name'] ) ) {
				$names[] = $tsf['knowledge_name'];
			}
		}

		return array_values( array_filter( array_map( 'trim', $names ) ) );
	}

	/**
	 * Decode the AIOSEO v4 options blob, which is stored as a JSON string.
	 *
	 * @return array Decoded options, or empty array if unavailable.
	 */
	private static function get_aioseo_options() {
		$raw = get_option( 'aioseo_options', '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Check if the business name is consistent across schema sources.
	 *
	 * @return bool
	 */
	private static function check_name_consistency() {
		$names = array();

		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$names[] = strtolower( trim( $site_name ) );
		}

		if ( class_exists( 'TWTAEO_Company_Profile' ) ) {
			$company = TWTAEO_Company_Profile::get();
			if ( ! empty( $company['name'] ) ) {
				$names[] = strtolower( trim( $company['name'] ) );
			}
		}

		// Names from any active SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress, SEO Framework).
		foreach ( self::get_seo_plugin_org_names() as $seo_name ) {
			$names[] = strtolower( $seo_name );
		}

		if ( count( $names ) <= 1 ) {
			return true; // Only one source — no conflict possible.
		}

		return count( array_unique( $names ) ) === 1;
	}

	/**
	 * Detect review or aggregate rating schema anywhere on the site.
	 *
	 * @return bool
	 */
	private static function detect_review_schema() {
		$posts = get_posts( array(
			'post_type'      => array( 'page', 'post', 'product' ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
		) );

		foreach ( $posts as $post ) {
			$content = $post->post_content ?? '';
			if ( strpos( $content, '"Review"' ) !== false || strpos( $content, '"AggregateRating"' ) !== false ) {
				return true;
			}

			$saswp = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
			if ( $saswp && ( strpos( $saswp, '"Review"' ) !== false || strpos( $saswp, '"AggregateRating"' ) !== false ) ) {
				return true;
			}
		}

		return false;
	}
}