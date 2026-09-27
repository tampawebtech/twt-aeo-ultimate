<?php
/**
 * Contact Detector
 *
 * Scans pages for contact-oriented content and validates entity schema.
 * Checks for Organization, PostalAddress, ContactPoint, and LocalBusiness schema.
 * Flags missing or incomplete entity data and entity consolidation issues.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Contact_Detector {

	/**
	 * Postmeta key for storing the contact scan result.
	 */
	const META_CONTACT_SCAN = '_twtaeo_contact_scan';

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Fires on save_post — rescans the post.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}
		if ( $post->post_status !== 'publish' ) {
			return;
		}

		$result = self::scan( $post );
		update_post_meta( $post_id, self::META_CONTACT_SCAN, $result );
	}

	/**
	 * Scan a single post for contact content and entity schema.
	 *
	 * @param WP_Post|int $post
	 * @return array {
	 *   has_contact_content: bool,
	 *   method: string,
	 *   has_organization: bool,
	 *   has_postal_address: bool,
	 *   has_contact_point: bool,
	 *   has_local_business: bool,
	 *   entity_consolidated: bool,
	 *   missing: string[],
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

		$signals     = array();
		$has_contact = false;
		$method      = '';

		// ── Content detection ────────────────────────────────────────────────

		// Strategy 1: Page intent classifier.
		$intent = TWTAEO_Page_Intent::classify( $post );
		if ( $intent['intent'] === 'contact' ) {
			$has_contact = true;
			$method      = 'intent';
			$signals[]   = 'Page intent classified as: Contact Page';
		}

		// Strategy 2: Slug contains contact keywords.
		if ( ! $has_contact ) {
			$contact_slugs = array( 'contact', 'contact-us', 'get-in-touch', 'reach-us', 'reach-out' );
			$slug          = $post->post_name ?? '';
			foreach ( $contact_slugs as $kw ) {
				if ( strpos( $slug, $kw ) !== false ) {
					$has_contact = true;
					$method      = 'slug';
					$signals[]   = "Slug contains contact keyword: {$kw}";
					break;
				}
			}
		}

		// Strategy 3: Content keywords.
		if ( ! $has_contact ) {
			$hits = self::count_contact_keywords( $post->post_content ?? '' );
			if ( $hits >= 2 ) {
				$has_contact = true;
				$method      = 'keywords';
				$signals[]   = "Found {$hits} contact-related keywords in content";
			}
		}

		if ( ! $has_contact ) {
			return self::empty_result();
		}

		// ── Schema validation ─────────────────────────────────────────────────

		$schema_data = self::detect_contact_schema( $post );

		$has_organization  = $schema_data['has_organization'];
		$has_postal        = $schema_data['has_postal_address'];
		$has_contact_point = $schema_data['has_contact_point'];
		$has_local_biz     = $schema_data['has_local_business'];
		$entity_id         = $schema_data['entity_id'];
		$entity_count      = $schema_data['entity_count'];

		// Check entity consolidation — multiple Organization entities is a problem.
		$entity_consolidated = ( $entity_count <= 1 );

		if ( $has_organization ) $signals[] = 'Organization schema present';
		if ( $has_postal )       $signals[] = 'PostalAddress schema present';
		if ( $has_contact_point ) $signals[] = 'ContactPoint schema present';
		if ( $has_local_biz )    $signals[] = 'LocalBusiness schema present';
		if ( ! $entity_consolidated ) $signals[] = 'Multiple Organization entities detected — consolidation needed';

		// Build missing list.
		$missing = array();
		if ( ! $has_organization && ! $has_local_biz ) $missing[] = 'Organization';
		if ( ! $has_postal )        $missing[] = 'PostalAddress';
		if ( ! $has_contact_point ) $missing[] = 'ContactPoint';
		if ( ! $entity_consolidated ) $missing[] = 'Entity Consolidation';

		return array(
			'has_contact_content'  => true,
			'method'               => $method,
			'has_organization'     => $has_organization,
			'has_postal_address'   => $has_postal,
			'has_contact_point'    => $has_contact_point,
			'has_local_business'   => $has_local_biz,
			'entity_consolidated'  => $entity_consolidated,
			'entity_id'            => $entity_id,
			'missing'              => $missing,
			'signals'              => $signals,
		);
	}

	/**
	 * How many posts one scan block covers; the screen pages through blocks
	 * instead of stopping at the first one.
	 */
	const SCAN_BLOCK = 200;

	/**
	 * How many published items the scan can see in total, regardless of blocks.
	 *
	 * @return int
	 */
	public static function total_items() {
		$total = 0;
		foreach ( array( 'page', 'post' ) as $type ) {
			$counts = wp_count_posts( $type );
			$total += (int) ( $counts->publish ?? 0 );
		}
		return $total;
	}

	/**
	 * Scan one block of published pages/posts, newest first, and return those
	 * with contact content.
	 *
	 * @param int $block 1-based block number; block N covers items
	 *                   ((N-1)*SCAN_BLOCK)+1 through N*SCAN_BLOCK.
	 * @return array[] Each entry: { post, contact_data }
	 */
	public static function scan_all( $block = 1 ) {
		$posts = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => self::SCAN_BLOCK,
			'paged'          => max( 1, (int) $block ),
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		) );

		$results = array();

		foreach ( $posts as $post ) {
			$contact_data = self::scan( $post );
			if ( $contact_data['has_contact_content'] ) {
				$results[] = array(
					'post'         => $post,
					'contact_data' => $contact_data,
				);
			}
		}

		return $results;
	}

	/**
	 * Get summary counts.
	 *
	 * @param array[]|null $all A block already returned by scan_all(), so the
	 *                          caller does not pay for a second scan. Null scans
	 *                          the first block.
	 * @return array { total, complete, needs_work }
	 */
	public static function get_summary( $all = null ) {
		if ( null === $all ) {
			$all = self::scan_all();
		}
		$complete  = 0;
		$needs     = 0;

		foreach ( $all as $item ) {
			if ( empty( $item['contact_data']['missing'] ) ) {
				$complete++;
			} else {
				$needs++;
			}
		}

		return array(
			'total'      => count( $all ),
			'complete'   => $complete,
			'needs_work' => $needs,
		);
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Count contact-related keywords in content.
	 *
	 * @param string $content
	 * @return int
	 */
	private static function count_contact_keywords( $content ) {
		$keywords = array(
			'contact', 'contact us', 'get in touch', 'reach us',
			'phone', 'email', 'address', 'location', 'office',
			'call us', 'send us', 'directions', 'map',
			'business hours', 'hours of operation',
		);

		$plain    = strtolower( wp_strip_all_tags( $content ) );
		$hits     = 0;

		foreach ( $keywords as $kw ) {
			if ( strpos( $plain, $kw ) !== false ) {
				$hits++;
			}
		}

		return $hits;
	}

	/**
	 * Detect contact-related schema from all available sources.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private static function detect_contact_schema( WP_Post $post ) {
		$has_organization  = false;
		$has_postal        = false;
		$has_contact_point = false;
		$has_local_biz     = false;
		$entity_id         = '';
		$entity_count      = 0;

		$org_types     = array( 'Organization', 'Corporation', 'NGO', 'EducationalOrganization' );
		$local_types   = array( 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'MedicalBusiness', 'FoodEstablishment' );

		// Strategy 1: Stored scan postmeta.
		$stored = get_post_meta( $post->ID, TWTAEO_Scan_Store::META_SCHEMA, true );
		if ( is_array( $stored ) ) {
			$types = $stored['schema_types'] ?? array();
			foreach ( $types as $type ) {
				if ( in_array( $type, $org_types, true ) )   { $has_organization = true; $entity_count++; }
				if ( $type === 'PostalAddress' )               { $has_postal = true; }
				if ( $type === 'ContactPoint' )                { $has_contact_point = true; }
				if ( in_array( $type, $local_types, true ) )  { $has_local_biz = true; $entity_count++; }
			}
		}

		// Strategy 2: Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$meta = get_post_meta( $post->ID );
			foreach ( $meta as $key => $values ) {
				if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
					foreach ( $values as $value ) {
						$data = maybe_unserialize( $value );
						if ( is_array( $data ) && isset( $data['@type'] ) ) {
							$found = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
							foreach ( $found as $t ) {
								if ( in_array( $t, $org_types, true ) )  { $has_organization = true; $entity_count++; }
								if ( $t === 'PostalAddress' )             { $has_postal = true; }
								if ( $t === 'ContactPoint' )              { $has_contact_point = true; }
								if ( in_array( $t, $local_types, true ) ) { $has_local_biz = true; }
							}
							if ( isset( $data['@id'] ) && empty( $entity_id ) ) {
								$entity_id = $data['@id'];
							}
						}
					}
				}
			}

			// Check Rank Math global settings for Organization/ContactPoint.
			$rm_titles = get_option( 'rank_math_titles', array() );
			if ( ! empty( $rm_titles['knowledgegraph_type'] ) ) {
				$has_organization = true;
			}
			if ( ! empty( $rm_titles['phone'] ) || ! empty( $rm_titles['email'] ) ) {
				$has_contact_point = true;
			}
			if ( ! empty( $rm_titles['local_address'] ) ) {
				$has_postal = true;
			}
		}

		// Strategy 3: Yoast.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_social', array() );
			if ( ! empty( $wpseo['company_name'] ) || ! empty( $wpseo['person_name'] ) ) {
				$has_organization = true;
			}

			$wpseo_titles = get_option( 'wpseo_titles', array() );
			if ( ! empty( $wpseo_titles['company_or_person'] ) ) {
				$has_organization = true;
			}
		}

		// Strategy 4: SASWP.
		$saswp = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
		if ( ! empty( $saswp ) ) {
			$data = json_decode( $saswp, true );
			if ( is_array( $data ) ) {
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					$types = isset( $item['@type'] )
						? ( is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] ) )
						: array();
					foreach ( $types as $t ) {
						if ( in_array( $t, $org_types, true ) )  { $has_organization = true; $entity_count++; }
						if ( $t === 'PostalAddress' )             { $has_postal = true; }
						if ( $t === 'ContactPoint' )              { $has_contact_point = true; }
						if ( in_array( $t, $local_types, true ) ) { $has_local_biz = true; }
					}
					if ( isset( $item['address'] ) )      { $has_postal = true; }
					if ( isset( $item['telephone'] ) || isset( $item['email'] ) ) { $has_contact_point = true; }
				}
			}
		}

		// Strategy 5: Inline JSON-LD in post content.
		$content = $post->post_content ?? '';
		foreach ( array_merge( $org_types, $local_types ) as $type ) {
			if ( strpos( $content, '"' . $type . '"' ) !== false ) {
				if ( in_array( $type, $org_types, true ) ) $has_organization = true;
				if ( in_array( $type, $local_types, true ) ) $has_local_biz = true;
			}
		}
		if ( strpos( $content, '"PostalAddress"' ) !== false ) $has_postal = true;
		if ( strpos( $content, '"ContactPoint"' ) !== false )  $has_contact_point = true;

		return array(
			'has_organization'  => $has_organization,
			'has_postal_address' => $has_postal,
			'has_contact_point' => $has_contact_point,
			'has_local_business' => $has_local_biz,
			'entity_id'         => $entity_id,
			'entity_count'      => $entity_count,
		);
	}

	/**
	 * Return an empty/default scan result.
	 */
	private static function empty_result() {
		return array(
			'has_contact_content'  => false,
			'method'               => '',
			'has_organization'     => false,
			'has_postal_address'   => false,
			'has_contact_point'    => false,
			'has_local_business'   => false,
			'entity_consolidated'  => true,
			'entity_id'            => '',
			'missing'              => array(),
			'signals'              => array(),
		);
	}
}