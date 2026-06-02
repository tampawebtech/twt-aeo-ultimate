<?php
/**
 * Page Intent Classifier
 *
 * Classifies pages by intent using WordPress context, URL slug,
 * page title, headings, and content keywords.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Intent {

	/**
	 * Intent definitions — expected schema, recommended schema,
	 * schema that is NOT appropriate for this page type.
	 *
	 * @var array
	 */
	private static $intent_schema = array(

		'homepage' => array(
			'label'        => 'Homepage',
			'expected'     => array( 'Organization', 'WebSite', 'WebPage' ),
			'recommended'  => array( 'LocalBusiness', 'FAQPage' ),
			'not_required' => array( 'Article', 'BlogPosting', 'Person' ),
		),

		'service' => array(
			'label'        => 'Service Page',
			'expected'     => array( 'Service', 'Organization' ),
			'recommended'  => array( 'FAQPage', 'LocalBusiness' ),
			'not_required' => array( 'Article', 'BlogPosting', 'Person' ),
		),

		'location' => array(
			'label'        => 'Location Page',
			'expected'     => array( 'LocalBusiness', 'PostalAddress' ),
			'recommended'  => array( 'Service', 'FAQPage' ),
			'not_required' => array( 'Article', 'BlogPosting' ),
		),

		'about' => array(
			'label'        => 'About Page',
			'expected'     => array( 'Organization', 'WebPage' ),
			'recommended'  => array( 'Person' ),
			'not_required' => array( 'Article', 'BlogPosting', 'Service' ),
		),

		'contact' => array(
			'label'        => 'Contact Page',
			'expected'     => array( 'Organization', 'WebPage' ),
			'recommended'  => array( 'PostalAddress', 'LocalBusiness' ),
			'not_required' => array( 'Article', 'BlogPosting', 'Service' ),
		),

		'faq' => array(
			'label'        => 'FAQ Page',
			'expected'     => array( 'FAQPage', 'WebPage' ),
			'recommended'  => array( 'Organization' ),
			'not_required' => array( 'Article', 'BlogPosting', 'Service' ),
		),

		'blog_post' => array(
			'label'        => 'Blog Post / Article',
			'expected'     => array( 'Article', 'Person' ),
			'recommended'  => array( 'FAQPage', 'HowTo' ),
			'not_required' => array( 'Service', 'LocalBusiness', 'Product' ),
		),

		'product' => array(
			'label'        => 'Product Page',
			'expected'     => array( 'Product', 'Organization' ),
			'recommended'  => array( 'FAQPage', 'Review' ),
			'not_required' => array( 'Article', 'BlogPosting', 'LocalBusiness' ),
		),

		'product_service' => array(
			'label'        => 'Product-Service Page',
			'expected'     => array( 'Service', 'Product' ),
			'recommended'  => array( 'FAQPage', 'BreadcrumbList' ),
			'not_required' => array( 'BlogPosting', 'Person' ),
		),

		'general' => array(
			'label'        => 'General Page',
			'expected'     => array( 'WebPage' ),
			'recommended'  => array( 'Organization' ),
			'not_required' => array( 'BlogPosting', 'Person' ),
		),
	);

	/**
	 * Classify a post/page by intent.
	 *
	 * @param WP_Post|int|null $post Post object, ID, or null for front page.
	 * @return array {
	 *   intent: string slug,
	 *   label: string human label,
	 *   confidence: string high|medium|low,
	 *   signals: string[] what triggered the classification,
	 *   expected: string[],
	 *   recommended: string[],
	 *   not_required: string[],
	 * }
	 */
	public static function classify( $post = null ) {

		// Resolve post object.
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			$front_page_id = (int) get_option( 'page_on_front' );
			$post          = $front_page_id ? get_post( $front_page_id ) : null;
		}

		// 1. WordPress context checks (most reliable).
		$context = self::check_wp_context( $post );
		if ( $context ) {
			return self::build_result( $context['intent'], $context['signals'], 'high' );
		}

		if ( ! $post ) {
			return self::build_result( 'general', array( 'No post context available' ), 'low' );
		}

		// 2. Check for known placeholder/boilerplate slugs — force General Page.
		$ignored_slugs = array(
			'sample-page', 'sample', 'test', 'test-page', 'example',
			'hello-world', 'placeholder', 'coming-soon', 'under-construction',
			'privacy-policy', 'terms', 'terms-of-service', 'terms-and-conditions',
			'sitemap',
		);

		if ( in_array( $post->post_name ?? '', $ignored_slugs, true ) ) {
			return self::build_result( 'general', array( 'Known placeholder or utility page slug' ), 'low' );
		}

		// 3. Collect all text signals.
		$slug     = $post->post_name ?? '';
		$title    = get_the_title( $post );
		$content  = wp_strip_all_tags( $post->post_content ?? '' );
		$headings = self::extract_headings( $post->post_content ?? '' );

		// 3. Run classifiers in priority order.
		$signals = array();
		$scores  = array(
			'homepage'        => 0,
			'service'         => 0,
			'location'        => 0,
			'about'           => 0,
			'contact'         => 0,
			'faq'             => 0,
			'blog_post'       => 0,
			'product'         => 0,
			'product_service' => 0,
			'general'         => 0,
		);

		// --- Slug scoring ---
		$slug_rules = array(
			'service'         => array( 'service', 'services', 'repair', 'repairs', 'install', 'installation', 'maintenance', 'consulting', 'consulting-services' ),
			'location'        => array( 'location', 'locations', 'area', 'areas', 'city', 'region', 'near-me', 'local' ),
			'about'           => array( 'about', 'about-us', 'our-story', 'company', 'who-we-are', 'team', 'our-team', 'history' ),
			'contact'         => array( 'contact', 'contact-us', 'get-in-touch', 'reach-us', 'reach-out' ),
			'faq'             => array( 'faq', 'faqs', 'frequently-asked', 'questions', 'help' ),
			'blog_post'       => array( 'blog', 'news', 'article', 'articles', 'post', 'update' ),
			'product'         => array( 'product', 'products', 'shop', 'store', 'item', 'buy' ),
			'product_service' => array( 'model', 'part', 'parts', 'component', 'spec', 'series' ),
		);

		foreach ( $slug_rules as $intent => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( strpos( $slug, $kw ) !== false ) {
					$scores[ $intent ] += 3;
					$signals[]          = "Slug contains '{$kw}'";
				}
			}
		}

		// --- Title scoring ---
		$title_lower = strtolower( $title );
		$title_rules = array(
			'service'         => array( 'service', 'services', 'repair', 'installation', 'maintenance', 'consulting', 'support' ),
			'location'        => array( 'location', 'locations', 'area', 'near', 'local', 'serving' ),
			'about'           => array( 'about', 'our story', 'company', 'who we are', 'team', 'history', 'overview' ),
			'contact'         => array( 'contact', 'get in touch', 'reach us', 'talk to us', 'call us' ),
			'faq'             => array( 'faq', 'frequently asked', 'questions', 'help' ),
			'blog_post'       => array( 'how to', 'guide', 'tips', 'tutorial', 'what is', 'why ', 'best ' ),
			'product'         => array( 'product', 'buy', 'shop', 'order', 'price', 'pricing' ),
			'product_service' => array( 'model', 'series', 'spec', 'part number', 'unit' ),
		);

		foreach ( $title_rules as $intent => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( strpos( $title_lower, $kw ) !== false ) {
					$scores[ $intent ] += 4;
					$signals[]          = "Title contains '{$kw}'";
				}
			}
		}

		// --- Heading scoring ---
		$heading_text = strtolower( implode( ' ', $headings ) );
		$heading_rules = array(
			'service'         => array( 'service', 'repair', 'installation', 'maintenance', 'our work', 'what we do', 'get a quote', 'request a quote', 'contact us' ),
			'location'        => array( 'serving', 'service area', 'our location', 'find us', 'directions', 'near you' ),
			'about'           => array( 'our mission', 'our story', 'who we are', 'meet the team', 'our values', 'company history' ),
			'contact'         => array( 'contact', 'get in touch', 'send a message', 'call', 'email us' ),
			'faq'             => array( 'frequently asked', 'common questions', 'faq', 'you asked', 'have questions' ),
			'blog_post'       => array( 'introduction', 'conclusion', 'step ', 'method', 'overview' ),
			'product'         => array( 'features', 'specifications', 'add to cart', 'in stock', 'buy now' ),
			'product_service' => array( 'compatible with', 'model number', 'technical specs', 'part number', 'serial number' ),
		);

		foreach ( $heading_rules as $intent => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( strpos( $heading_text, $kw ) !== false ) {
					$scores[ $intent ] += 3;
					$signals[]          = "Heading contains '{$kw}'";
				}
			}
		}

		// --- Content keyword scoring ---
		$content_lower = strtolower( substr( $content, 0, 2000 ) ); // First 2000 chars.
		$content_rules = array(
			'service'         => array( 'repair', 'service', 'maintenance', 'install', 'quote', 'estimate', 'technician', 'specialist', 'call us', 'schedule' ),
			'location'        => array( 'located in', 'serving', 'city', 'state', 'zip code', 'address', 'directions', 'near', 'local' ),
			'about'           => array( 'founded', 'established', 'our team', 'our mission', 'we believe', 'years of experience', 'family owned', 'locally owned' ),
			'contact'         => array( 'phone', 'email', 'address', 'hours', 'monday', 'form', 'send us', 'reach' ),
			'faq'             => array( 'question', 'answer', 'how do i', 'what is', 'can i', 'do you', 'is it' ),
			'blog_post'       => array( 'published', 'author', 'written by', 'read more', 'in this article', 'in this post', 'share', 'comments' ),
			'product'         => array( 'price', 'add to cart', 'buy now', 'in stock', 'shipping', 'sku', 'quantity', 'reviews' ),
			'product_service' => array( 'model number', 'serial', 'compatible', 'technical', 'specification', 'part number', 'oem', 'manufacturer' ),
		);

		foreach ( $content_rules as $intent => $keywords ) {
			$hits = 0;
			foreach ( $keywords as $kw ) {
				if ( strpos( $content_lower, $kw ) !== false ) {
					$hits++;
				}
			}
			if ( $hits >= 3 ) {
				$scores[ $intent ] += $hits * 2;
				$signals[]          = "Content matches {$hits} {$intent} keywords";
			} elseif ( $hits >= 2 ) {
				$scores[ $intent ] += $hits;
				$signals[]          = "Content matches {$hits} {$intent} keywords";
			}
			// Single keyword hit no longer scores — prevents false positives from boilerplate text.
		}

		// --- WooCommerce product page detection ---
		if ( function_exists( 'is_product' ) && $post->post_type === 'product' ) {
			$scores['product'] += 20;
			$signals[]          = 'WooCommerce product post type';
		}

		// --- Model/part number detection in title ---
		if ( preg_match( '/\b[A-Z]{1,5}[-\s]?\d{3,6}\b/', $title ) ) {
			$scores['product_service'] += 5;
			$signals[]                 = 'Model/part number detected in title';
		}

		// --- Find the winner ---
		arsort( $scores );
		$top_intent = array_key_first( $scores );
		$top_score  = $scores[ $top_intent ];

		// If nothing scored, fall back to general.
		if ( $top_score === 0 ) {
			return self::build_result( 'general', array( 'No strong intent signals detected' ), 'low' );
		}

		// Determine confidence.
		$second_score = array_values( $scores )[1] ?? 0;
		$gap          = $top_score - $second_score;

		if ( $gap >= 6 || $top_score >= 10 ) {
			$confidence = 'high';
		} elseif ( $gap >= 3 || $top_score >= 5 ) {
			$confidence = 'medium';
		} else {
			$confidence = 'low';
		}

		return self::build_result( $top_intent, array_unique( $signals ), $confidence );
	}

	/**
	 * Check WordPress-level context for definitive classification.
	 *
	 * @param WP_Post|null $post
	 * @return array|null {intent, signals} or null if no definitive match.
	 */
	private static function check_wp_context( $post ) {

		// Front page check.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $post && $front_page_id && (int) $post->ID === $front_page_id ) {
			return array(
				'intent'  => 'homepage',
				'signals' => array( 'Set as static front page in WordPress settings' ),
			);
		}

		if ( ! $post ) {
			if ( get_option( 'show_on_front' ) === 'posts' ) {
				return array(
					'intent'  => 'homepage',
					'signals' => array( 'Blog index set as front page' ),
				);
			}
			return null;
		}

		// WooCommerce product.
		if ( $post->post_type === 'product' ) {
			return array(
				'intent'  => 'product',
				'signals' => array( 'WooCommerce product post type' ),
			);
		}

		// Standard post type = blog post.
		if ( $post->post_type === 'post' ) {
			return array(
				'intent'  => 'blog_post',
				'signals' => array( 'WordPress post type = post' ),
			);
		}

		return null;
	}

	/**
	 * Extract heading text from post content.
	 *
	 * @param string $content Post content HTML.
	 * @return array List of heading text strings.
	 */
	private static function extract_headings( $content ) {
		$headings = array();

		preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $heading ) {
				$headings[] = wp_strip_all_tags( $heading );
			}
		}

		return $headings;
	}

	/**
	 * Build the classification result array.
	 *
	 * @param string $intent     Intent slug.
	 * @param array  $signals    What triggered the classification.
	 * @param string $confidence high|medium|low
	 * @return array
	 */
	private static function build_result( $intent, array $signals, $confidence ) {
		$schema = self::$intent_schema[ $intent ] ?? self::$intent_schema['general'];

		return array(
			'intent'       => $intent,
			'label'        => $schema['label'],
			'confidence'   => $confidence,
			'signals'      => $signals,
			'expected'     => $schema['expected'],
			'recommended'  => $schema['recommended'],
			'not_required' => $schema['not_required'],
		);
	}

	/**
	 * Get all intent definitions (for reference/UI).
	 *
	 * @return array
	 */
	public static function get_all_intents() {
		return self::$intent_schema;
	}

	/**
	 * Get the schema definition for a specific intent.
	 *
	 * @param string $intent Intent slug.
	 * @return array
	 */
	public static function get_intent_schema( $intent ) {
		return self::$intent_schema[ $intent ] ?? self::$intent_schema['general'];
	}
}