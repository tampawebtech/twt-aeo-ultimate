<?php
/**
 * Service Detector
 *
 * Scans pages and posts for service-oriented content and checks whether
 * Service schema is present. Supports Rank Math, Yoast, SASWP, WooCommerce,
 * and inline JSON-LD detection. Hooks into save_post for automatic rescanning.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Service_Detector {

	/**
	 * Postmeta key for storing the service scan result.
	 */
	const META_SERVICE_SCAN = '_twtaeo_service_scan';

	/**
	 * Minimum keyword hits to consider a page service-oriented.
	 */
	const MIN_KEYWORD_HITS = 3;

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Fires on save_post — rescans the post for service content and schema.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_post( $post_id, $post ) {
		// Skip autosaves, revisions, and non-scannable types.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$scannable = self::get_scannable_post_types();
		if ( ! in_array( $post->post_type, $scannable, true ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		$result = self::scan( $post );
		update_post_meta( $post_id, self::META_SERVICE_SCAN, $result );
	}

	/**
	 * Scan a single post for service content and schema.
	 *
	 * @param WP_Post|int $post
	 * @return array {
	 *   has_service_content: bool,
	 *   method: string,
	 *   keyword_hits: int,
	 *   has_service_schema: bool,
	 *   needs_schema: bool,
	 *   signals: string[]
	 * }
	 */
	public static function scan( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			return self::empty_result();
		}

		$signals            = array();
		$has_service        = false;
		$method             = '';
		$keyword_hits       = 0;

		// ── Content detection ────────────────────────────────────────────────

		// Strategy 1: Page intent classifier — service or product_service intent.
		$intent = TWTAEO_Page_Intent::classify( $post );
		if ( in_array( $intent['intent'], array( 'service', 'product_service' ), true ) ) {
			$has_service = true;
			$method      = 'intent';
			$signals[]   = 'Page intent classified as: ' . $intent['label'];
		}

		// Strategy 2: URL slug contains service keywords.
		if ( ! $has_service ) {
			$slug_keywords = array( 'service', 'services', 'repair', 'repairs', 'install', 'installation', 'maintenance', 'consulting' );
			$slug          = $post->post_name ?? '';
			foreach ( $slug_keywords as $kw ) {
				if ( strpos( $slug, $kw ) !== false ) {
					$has_service = true;
					$method      = 'slug';
					$signals[]   = "Slug contains service keyword: {$kw}";
					break;
				}
			}
		}

		// Strategy 3: Service keywords in content (headings + first 300 words).
		if ( ! $has_service ) {
			$keyword_hits = self::count_service_keywords( $post->post_content ?? '' );
			if ( $keyword_hits >= self::MIN_KEYWORD_HITS ) {
				$has_service = true;
				$method      = 'keywords';
				$signals[]   = "Found {$keyword_hits} service-related keywords in content";
			}
		}

		// ── Schema detection ─────────────────────────────────────────────────

		$has_service_schema = self::has_service_schema( $post );

		if ( $has_service_schema ) {
			$signals[] = 'Service schema already present';
		}

		return array(
			'has_service_content' => $has_service,
			'method'              => $method,
			'keyword_hits'        => $keyword_hits,
			'has_service_schema'  => $has_service_schema,
			'needs_schema'        => $has_service && ! $has_service_schema,
			'signals'             => $signals,
		);
	}

	/**
	 * Scan all published scannable posts and return those with service content.
	 *
	 * @param bool $include_posts Whether to include blog posts (default: pages only).
	 * @return array[] Each entry: { post, service_data }
	 */
	public static function scan_all( $include_posts = false ) {
		$posts = get_posts( array(
			'post_type'      => self::get_scannable_post_types( $include_posts ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
		) );

		$results = array();

		foreach ( $posts as $post ) {
			$service_data = self::scan( $post );
			if ( $service_data['has_service_content'] ) {
				$results[] = array(
					'post'         => $post,
					'service_data' => $service_data,
				);
			}
		}

		return $results;
	}

	/**
	 * Get summary counts.
	 *
	 * @param bool $include_posts Whether to include blog posts (default: pages only).
	 * @return array { total_with_service, needs_schema, has_schema }
	 */
	public static function get_summary( $include_posts = false ) {
		$all         = self::scan_all( $include_posts );
		$needs       = 0;
		$has         = 0;

		foreach ( $all as $item ) {
			if ( $item['service_data']['needs_schema'] ) {
				$needs++;
			} else {
				$has++;
			}
		}

		return array(
			'total_with_service' => count( $all ),
			'needs_schema'       => $needs,
			'has_schema'         => $has,
		);
	}

	/**
	 * Post types to scan.
	 *
	 * Defaults to pages only. Pass true to also include blog posts.
	 * WooCommerce products are always included when WooCommerce is active.
	 *
	 * @param bool $include_posts Whether to include the 'post' type.
	 * @return string[]
	 */
	public static function get_scannable_post_types( $include_posts = false ) {
		$types = array( 'page' );
		if ( $include_posts ) {
			$types[] = 'post';
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Count service-related keywords in content.
	 * Checks headings and the first 300 words of body text.
	 *
	 * @param string $content Raw post content HTML.
	 * @return int
	 */
	private static function count_service_keywords( $content ) {
		$keywords = array(
			'service', 'services', 'repair', 'repairs', 'installation', 'maintenance',
			'consulting', 'consultation', 'quote', 'estimate', 'technician', 'technicians',
			'specialist', 'specialists', 'hire', 'contractor', 'contractors',
			'we offer', 'we provide', 'our services', 'contact us', 'request a quote',
			'schedule', 'get a quote', 'free estimate',
		);

		// Extract headings and first 300 words of plain text.
		preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $heading_matches );
		$headings_text = implode( ' ', $heading_matches[1] ?? array() );

		$plain   = wp_strip_all_tags( $content );
		$words   = explode( ' ', $plain );
		$excerpt = implode( ' ', array_slice( $words, 0, 300 ) );

		$haystack = strtolower( $headings_text . ' ' . $excerpt );
		$hits     = 0;

		foreach ( $keywords as $kw ) {
			if ( strpos( $haystack, $kw ) !== false ) {
				$hits++;
			}
		}

		return $hits;
	}

	/**
	 * Check whether the post already has Service schema.
	 *
	 * Checks in order:
	 *   1. Stored scan postmeta — positive confirmation only, never short-circuits.
	 *   2. Rank Math schema postmeta.
	 *   3. Yoast schema postmeta.
	 *   4. Inline JSON-LD in post_content.
	 *   5. SASWP custom schema field.
	 *   6. Broad SASWP catch-all.
	 *   7. WooCommerce product type (Product schema implies service context).
	 *
	 * @param WP_Post $post
	 * @return bool
	 */
	private static function has_service_schema( $post ) {

		$service_types = array( 'Service', 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness' );

		// Strategy 0: TWT AEO generated schema — check our own postmeta first.
		if ( class_exists( 'TWTAEO_Service_Schema_Writer' ) &&
		     get_post_meta( $post->ID, TWTAEO_Service_Schema_Writer::META_KEY, true ) ) {
			return true;
		}

		// Strategy 1: Stored scan — positive confirmation only.
		$stored = get_post_meta( $post->ID, TWTAEO_Scan_Store::META_SCHEMA, true );
		if ( is_array( $stored ) ) {
			$types = $stored['schema_types'] ?? array();
			foreach ( $service_types as $type ) {
				if ( in_array( $type, $types, true ) ) {
					return true;
				}
			}
			// Never return false — stored scan may not include all sources.
		}

		// Strategy 2: Rank Math schema postmeta.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$meta = get_post_meta( $post->ID );
			foreach ( $meta as $key => $values ) {
				if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
					foreach ( $values as $value ) {
						$data = maybe_unserialize( $value );
						if ( is_array( $data ) && isset( $data['@type'] ) ) {
							$found = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
							foreach ( $service_types as $type ) {
								if ( in_array( $type, $found, true ) ) {
									return true;
								}
							}
						}
					}
				}
			}
		}

		// Strategy 3: Yoast schema postmeta.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$page_type = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
			if ( $page_type && in_array( $page_type, $service_types, true ) ) {
				return true;
			}
		}

		// Strategy 4: Inline JSON-LD in post_content.
		$content = $post->post_content ?? '';
		foreach ( $service_types as $type ) {
			if ( strpos( $content, '"' . $type . '"' ) !== false ) {
				return true;
			}
		}

		// Strategy 5: SASWP custom schema field.
		$saswp = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
		if ( ! empty( $saswp ) ) {
			$data = json_decode( $saswp, true );
			if ( is_array( $data ) ) {
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					$found = isset( $item['@type'] )
						? ( is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] ) )
						: array();
					foreach ( $service_types as $type ) {
						if ( in_array( $type, $found, true ) ) {
							return true;
						}
					}
				}
			}
		}

		// Strategy 6: Broad SASWP catch-all.
		$all_meta = get_post_meta( $post->ID );
		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, 'saswp_' ) === 0 ) {
				foreach ( $values as $value ) {
					if ( is_string( $value ) ) {
						foreach ( $service_types as $type ) {
							if ( strpos( $value, '"' . $type . '"' ) !== false ) {
								return true;
							}
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Return an empty/default scan result.
	 */
	private static function empty_result() {
		return array(
			'has_service_content' => false,
			'method'              => '',
			'keyword_hits'        => 0,
			'has_service_schema'  => false,
			'needs_schema'        => false,
			'signals'             => array(),
		);
	}
}