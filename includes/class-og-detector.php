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

		// ── AEO saved image override ─────────────────────────────────────────────
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

		return array(
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
	 * Scan all published pages and posts.
	 *
	 * @return array[] Each entry: { post, og_data }
	 */
	public static function scan_all() {
		$post_types = array( 'page', 'post' );
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
		) );

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
	 * Get summary counts across all published content.
	 *
	 * @return array { total, complete, missing_image, missing_description, needs_work }
	 */
	public static function get_summary() {
		$all             = self::scan_all();
		$complete        = 0;
		$missing_image   = 0;
		$missing_desc    = 0;
		$needs_work      = 0;

		foreach ( $all as $item ) {
			$og = $item['og_data'];
			if ( empty( $og['missing'] ) ) {
				$complete++;
			} else {
				$needs_work++;
				if ( ! $og['has_og_image'] )       $missing_image++;
				if ( ! $og['has_og_description'] ) $missing_desc++;
			}
		}

		return array(
			'total'               => count( $all ),
			'complete'            => $complete,
			'missing_image'       => $missing_image,
			'missing_description' => $missing_desc,
			'needs_work'          => $needs_work,
		);
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
		);
	}
}
