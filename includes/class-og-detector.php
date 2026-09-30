<?php
/**
 * Open Graph Detector
 *
 * Scans posts and pages for Open Graph meta tag coverage.
 * Checks og:title, og:description, og:image, og:type, og:url, and og:site_name.
 * Detects source from Rank Math, Yoast SEO, AIOSEO, or post defaults.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_OG_Detector {

	/**
	 * Scan a single post for Open Graph coverage.
	 *
	 * @param WP_Post|int $post
	 * @return array {
	 *   has_og_title:       bool,
	 *   has_og_description: bool,
	 *   has_og_image:       bool,
	 *   has_og_type:        bool,
	 *   has_og_url:         bool,
	 *   og_title:           string,
	 *   og_description:     string,
	 *   og_image:           string,
	 *   og_type:            string,
	 *   source:             string,
	 *   missing:            string[],
	 *   signals:            string[],
	 * }
	 */
	public static function scan( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			return self::empty_result();
		}

		$og_title       = '';
		$og_description = '';
		$og_image       = '';
		$og_type        = '';
		$source         = '';
		$signals        = array();

		// ── Rank Math ────────────────────────────────────────────────────────────
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_title = get_post_meta( $post->ID, 'rank_math_facebook_title', true );
			$rm_desc  = get_post_meta( $post->ID, 'rank_math_facebook_description', true );
			$rm_image = get_post_meta( $post->ID, 'rank_math_facebook_image', true );

			// Rank Math falls back to its generic SEO title/description for OG when no OG-specific value is set.
			if ( empty( $rm_title ) ) {
				$rm_title = get_post_meta( $post->ID, 'rank_math_title', true );
			}
			if ( empty( $rm_desc ) ) {
				$rm_desc = get_post_meta( $post->ID, 'rank_math_description', true );
			}

			if ( $rm_title || $rm_desc || $rm_image ) {
				$og_title       = $rm_title ?: $og_title;
				$og_description = $rm_desc  ?: $og_description;
				$og_image       = $rm_image ?: $og_image;
				$source         = 'Rank Math';
				$signals[]      = 'Rank Math Open Graph settings detected';
			}
		}

		// ── Yoast SEO ────────────────────────────────────────────────────────────
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-title', true );
			$yoast_desc  = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-description', true );
			$yoast_image = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-image', true );

			// Yoast falls back to its generic SEO title/description for OG.
			if ( empty( $yoast_title ) ) {
				$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
			}
			if ( empty( $yoast_desc ) ) {
				$yoast_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			}

			if ( $yoast_title || $yoast_desc || $yoast_image ) {
				$og_title       = $og_title       ?: $yoast_title;
				$og_description = $og_description ?: $yoast_desc;
				$og_image       = $og_image       ?: $yoast_image;
				$source         = $source ?: 'Yoast SEO';
				$signals[]      = 'Yoast SEO Open Graph settings detected';
			}
		}

		// ── AIOSEO ───────────────────────────────────────────────────────────────
		if ( class_exists( 'AIOSEO\Plugin\AIOSEO' ) || defined( 'AIOSEO_VERSION' ) ) {
			$aioseo_meta = get_post_meta( $post->ID, '_aioseo_og_title', true );
			$aioseo_desc = get_post_meta( $post->ID, '_aioseo_og_description', true );
			$aioseo_img  = get_post_meta( $post->ID, '_aioseo_og_image_custom_url', true );

			if ( $aioseo_meta || $aioseo_desc || $aioseo_img ) {
				$og_title       = $og_title       ?: $aioseo_meta;
				$og_description = $og_description ?: $aioseo_desc;
				$og_image       = $og_image       ?: $aioseo_img;
				$source         = $source ?: 'AIOSEO';
				$signals[]      = 'AIOSEO Open Graph settings detected';
			}
		}

		// ── WordPress defaults ───────────────────────────────────────────────────
		// Title falls back to post title; description to excerpt; image to featured image.
		if ( empty( $og_title ) ) {
			$og_title  = get_the_title( $post );
			$signals[] = 'og:title falls back to post title — no SEO plugin override';
		}
		if ( empty( $og_description ) && ! empty( $post->post_excerpt ) ) {
			$og_description = $post->post_excerpt;
			$signals[]      = 'og:description falls back to post excerpt';
		}
		if ( empty( $og_image ) ) {
			$thumb = get_the_post_thumbnail_url( $post->ID, 'large' );
			if ( $thumb ) {
				$og_image  = $thumb;
				$signals[] = 'og:image falls back to featured image';
			}
		}
		// Products: a gallery image counts when there is no featured image —
		// mirrors the same fallback in TWTAEO_OG_Writer::output_meta().
		if ( empty( $og_image ) && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$wc_product = wc_get_product( $post->ID );
			$gallery    = $wc_product ? $wc_product->get_gallery_image_ids() : array();
			if ( ! empty( $gallery ) ) {
				$g_url = wp_get_attachment_image_url( $gallery[0], 'large' );
				if ( $g_url ) {
					$og_image  = $g_url;
					$signals[] = 'og:image falls back to product gallery image';
				}
			}
		}

		// ── AEO saved overrides ──────────────────────────────────────────────────
		// Values saved through the AEO Open Graph editor (modal / bulk job) take
		// precedence — these are what TWTAEO_OG_Writer actually outputs. Without
		// this, filling an OG title/description here would still show as "missing"
		// in the coverage table no matter how often you rescan.
		$saved_title = get_post_meta( $post->ID, TWTAEO_OG_Writer::META_TITLE, true );
		if ( ! empty( $saved_title ) ) {
			$og_title  = $saved_title;
			$signals[] = 'og:title set via AEO Open Graph editor';
		}

		$saved_desc = get_post_meta( $post->ID, TWTAEO_OG_Writer::META_DESC, true );
		if ( ! empty( $saved_desc ) ) {
			$og_description = $saved_desc;
			$signals[]      = 'og:description set via AEO Open Graph editor';
		}

		$saved_image = get_post_meta( $post->ID, TWTAEO_OG_Writer::META_IMAGE, true );
		if ( ! empty( $saved_image ) ) {
			$og_image  = $saved_image;
			$signals[] = 'og:image set via AEO Open Graph editor';
		}

		if ( empty( $source ) ) {
			$source = 'WordPress defaults';
		}

		// ── og:type ──────────────────────────────────────────────────────────────
		if ( $post->post_type === 'product' ) {
			$og_type = 'product';
		} elseif ( $post->post_type === 'post' ) {
			$og_type = 'article';
		} else {
			$og_type = 'website';
		}

		// ── Build result ─────────────────────────────────────────────────────────
		$has_title = ! empty( $og_title );
		$has_desc  = ! empty( $og_description );
		$has_image = ! empty( $og_image );
		$has_type  = ! empty( $og_type );
		$has_url   = true; // WordPress always outputs the permalink as og:url via most plugins.

		$missing = array();
		if ( ! $has_title ) $missing[] = 'og:title';
		if ( ! $has_desc )  $missing[] = 'og:description';
		if ( ! $has_image ) $missing[] = 'og:image';

		// Descriptions that stop mid-sentence (older versions cut AI output at
		// the last whole word; other plugins and hand edits do it too). Both
		// the og:description and the page's meta description are checked.
		$cut_off = array();
		if ( class_exists( 'TWTAEO_AI_Description' ) ) {
			if ( $has_desc && TWTAEO_AI_Description::looks_cut_off( $og_description ) ) {
				$cut_off[] = 'og:description';
			}
			$meta_desc = TWTAEO_AI_Description::get_existing_description( $post->ID );
			if ( '' !== $meta_desc && $meta_desc !== $og_description && false === strpos( $meta_desc, '%' ) && TWTAEO_AI_Description::looks_cut_off( $meta_desc ) ) {
				$cut_off[] = 'meta description';
			}
		}

		return array(
			'cut_off'            => $cut_off,
			'has_og_title'       => $has_title,
			'has_og_description' => $has_desc,
			'has_og_image'       => $has_image,
			'has_og_type'        => $has_type,
			'has_og_url'         => $has_url,
			'og_title'           => $og_title,
			'og_description'     => $og_description,
			'og_image'           => $og_image,
			'og_type'            => $og_type,
			'source'             => $source,
			'missing'            => $missing,
			'signals'            => $signals,
		);
	}

	/**
	 * How many posts one scan block covers. Scanning the whole catalogue in one
	 * page load times out on large stores, so the screen walks the catalogue in
	 * blocks of this size instead of stopping at the first one.
	 */
	const SCAN_BLOCK = 200;

	/**
	 * The post types this detector covers.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$post_types = array( 'page', 'post' );
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}
		return $post_types;
	}

	/**
	 * How many published items are in the catalogue, regardless of any scan cap.
	 *
	 * @return int
	 */
	public static function total_items() {
		$catalogue = 0;
		foreach ( self::post_types() as $type ) {
			$counts     = wp_count_posts( $type );
			$catalogue += (int) ( $counts->publish ?? 0 );
		}
		return $catalogue;
	}

	/**
	 * Items matched by the last scan_all() call across all blocks — what the
	 * pager needs when a search narrows the catalogue.
	 *
	 * @var int
	 */
	private static $last_found = 0;

	/**
	 * How many items the last scan_all() query matched in total.
	 *
	 * @return int
	 */
	public static function last_found() {
		return self::$last_found;
	}

	/**
	 * Scan one block of published pages and posts, newest first.
	 *
	 * @param int    $block  1-based block number; block N covers items
	 *                       ((N-1)*SCAN_BLOCK)+1 through N*SCAN_BLOCK.
	 * @param string $search Optional term matched against title, content and
	 *                       product SKU, across the whole catalogue. Blocks
	 *                       then page through the MATCHES, newest first.
	 * @return array[] Each entry: { post, og_data }
	 */
	public static function scan_all( $block = 1, $search = '' ) {
		$args = array(
			'post_type'      => self::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => self::SCAN_BLOCK,
			'paged'          => max( 1, (int) $block ),
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		);

		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$args['s'] = $search;
			// SKU matching rides along; on non-product types the extra OR
			// simply never matches.
			add_filter( 'posts_search', array( 'TWTAEO_WooCommerce_Detector', 'search_sku' ), 10, 2 );
		}

		$query = new WP_Query( $args );

		if ( '' !== $search ) {
			remove_filter( 'posts_search', array( 'TWTAEO_WooCommerce_Detector', 'search_sku' ), 10 );
		}

		self::$last_found = (int) $query->found_posts;
		$posts            = $query->posts;

		$results = array();

		foreach ( $posts as $post ) {
			$results[] = array(
				'post'    => $post,
				'og_data' => self::scan( $post ),
			);
		}

		return $results;
	}

	/**
	 * Get summary counts for one scanned block.
	 *
	 * @param array[]|null $all A block already returned by scan_all(), so the
	 *                          caller does not pay for a second scan. Null scans
	 *                          the first block.
	 * @return array { total, scanned, truncated, complete, missing_image, missing_description, needs_work }
	 */
	public static function get_summary( $all = null ) {
		if ( null === $all ) {
			$all = self::scan_all();
		}
		$complete        = 0;
		$missing_image   = 0;
		$missing_desc    = 0;
		$missing_text    = 0;
		$needs_work      = 0;

		foreach ( $all as $item ) {
			$og = $item['og_data'];
			if ( empty( $og['missing'] ) ) {
				$complete++;
			} else {
				$needs_work++;
				if ( ! $og['has_og_image'] )       $missing_image++;
				if ( ! $og['has_og_description'] ) $missing_desc++;
				if ( ! $og['has_og_title'] || ! $og['has_og_description'] ) $missing_text++;
			}
		}

		// One block covers at most SCAN_BLOCK posts; the site may hold far more.
		// Counted from the database so the UI can say "200 of 1,400 scanned"
		// instead of presenting the block as the whole story — a store with
		// 1,400 products reading "200 missing image" would rightly call that
		// number wrong.
		$catalogue = self::total_items();

		return array(
			'total'               => $catalogue,
			'scanned'             => count( $all ),
			'truncated'           => $catalogue > count( $all ),
			'complete'            => $complete,
			'missing_image'       => $missing_image,
			'missing_description' => $missing_desc,
			'missing_text'        => $missing_text,
			'needs_work'          => $needs_work,
		);
	}

	/**
	 * Option holding the last full-site census: every block scanned, counts
	 * aggregated. The summary cards read this — a single block's counts on a
	 * 1,000-page site say almost nothing about the site.
	 */
	const CENSUS_OPTION = 'twtaeo_og_census';

	/**
	 * Scan one block and return its counts, for the site-wide census walk.
	 *
	 * @param int $block 1-based block number.
	 * @return array { scanned, complete, missing_image, missing_text, needs_work, tw_explicit }
	 */
	public static function census_block( $block ) {
		$items  = self::scan_all( $block );
		$counts = array(
			'scanned'       => count( $items ),
			'complete'      => 0,
			'missing_image' => 0,
			'missing_text'  => 0,
			'needs_work'    => 0,
			'tw_explicit'   => 0,
		);
		foreach ( $items as $item ) {
			$og = $item['og_data'];
			if ( empty( $og['missing'] ) ) {
				$counts['complete']++;
			} else {
				$counts['needs_work']++;
				if ( ! $og['has_og_image'] ) {
					$counts['missing_image']++;
				}
				if ( ! $og['has_og_title'] || ! $og['has_og_description'] ) {
					$counts['missing_text']++;
				}
			}
			if ( class_exists( 'TWTAEO_Twitter_Writer' ) ) {
				$tw = TWTAEO_Twitter_Writer::get( $item['post']->ID );
				if ( ! empty( $tw['tw_card'] ) ) {
					$counts['tw_explicit']++;
				}
			}
		}
		return $counts;
	}

	/**
	 * The stored census, or an empty array when none has completed yet.
	 *
	 * @return array { totals: array, total_items: int, completed_at: int } | array{}
	 */
	public static function get_census() {
		$census = get_option( self::CENSUS_OPTION, array() );
		return ( is_array( $census ) && ! empty( $census['completed_at'] ) ) ? $census : array();
	}

	/**
	 * Detect the active OG source at the site level.
	 *
	 * @return string Human-readable source name.
	 */
	public static function detect_site_source() {
		if ( defined( 'RANK_MATH_VERSION' ) ) return 'Rank Math';
		if ( defined( 'WPSEO_VERSION' ) )     return 'Yoast SEO';
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\Plugin\AIOSEO' ) ) return 'AIOSEO';
		return 'Unknown / Theme';
	}

	/**
	 * Return an empty scan result.
	 */
	private static function empty_result() {
		return array(
			'has_og_title'       => false,
			'has_og_description' => false,
			'has_og_image'       => false,
			'has_og_type'        => false,
			'has_og_url'         => false,
			'og_title'           => '',
			'og_description'     => '',
			'og_image'           => '',
			'og_type'            => '',
			'source'             => '',
			'missing'            => array( 'og:title', 'og:description', 'og:image' ),
			'signals'            => array(),
			'cut_off'            => array(),
		);
	}
}
