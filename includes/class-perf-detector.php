<?php
/**
 * Performance Anti-Pattern Detector
 *
 * Scans post content for the performance problems that AI page-builders (Claude
 * Chat, GPT, etc.) tend to paste in: inline/external scripts dropped straight
 * into the body, inline CSS blocks, base64-embedded images, and iframes. It does
 * NOT try to detect "was this AI" — it detects the symptoms, which is what
 * actually matters and is engine-agnostic.
 *
 * Pure on-server regex over raw post_content (what the user pasted), so it is
 * cheap enough to scan the whole site in one request. It flags candidates;
 * pairing a flagged page with a PageSpeed audit (Page Speed tab) confirms the
 * real-world cost. Nothing is ever modified — detect and report only.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Perf_Detector {

	/** Inline JS over this many bytes is treated as critical, not just a warning. */
	const INLINE_JS_CRITICAL_BYTES = 2000;

	/**
	 * Find performance anti-patterns in a chunk of HTML.
	 *
	 * @param string $html Raw content (post_content).
	 * @return array List of flags: { type, label, count, severity, detail }.
	 */
	public static function analyze( $html ) {
		$html  = (string) $html;
		$flags = array();
		if ( '' === trim( $html ) ) {
			return $flags;
		}

		// External scripts pasted into content (CDN libs, widgets).
		if ( preg_match_all( '#<scr[i]pt\b[^>]*\bsrc\s*=\s*["\'][^"\']+["\'][^>]*>#i', $html, $m ) ) {
			$flags[] = array(
				'type'     => 'external_script',
				'label'    => __( 'External script in content', 'twt-aeo-ultimate' ),
				'count'    => count( $m[0] ),
				'severity' => 'critical',
				'detail'   => __( 'An external script include was pasted into the page body — it can block rendering and is often a duplicate of something the theme already loads.', 'twt-aeo-ultimate' ),
			);
		}

		// Inline script blocks (excluding the external ones above).
		if ( preg_match_all( '#<scr[i]pt\b(?![^>]*\bsrc\s*=)[^>]*>(.*?)</scr[i]pt>#is', $html, $m ) ) {
			$bytes = 0;
			foreach ( $m[1] as $code ) {
				$bytes += strlen( $code );
			}
			$flags[] = array(
				'type'     => 'inline_script',
				'label'    => __( 'Inline JavaScript', 'twt-aeo-ultimate' ),
				'count'    => count( $m[0] ),
				'severity' => $bytes > self::INLINE_JS_CRITICAL_BYTES ? 'critical' : 'warning',
				'detail'   => sprintf(
					/* translators: 1: number of script blocks, 2: byte size */
					__( '%1$d inline script block(s), ~%2$s of JS — render-blocking and uncacheable.', 'twt-aeo-ultimate' ),
					count( $m[0] ),
					size_format( $bytes )
				),
			);
		}

		// Inline style blocks.
		if ( preg_match_all( '#<sty[l]e\b[^>]*>(.*?)</sty[l]e>#is', $html, $m ) ) {
			$flags[] = array(
				'type'     => 'inline_style',
				'label'    => __( 'Inline CSS block', 'twt-aeo-ultimate' ),
				'count'    => count( $m[0] ),
				'severity' => 'notice',
				'detail'   => __( 'An inline style block in content bloats the HTML and can\'t be cached separately.', 'twt-aeo-ultimate' ),
			);
		}

		// Base64-embedded images.
		if ( preg_match_all( '#<img\b[^>]*\bsrc\s*=\s*["\']data:image/[^"\']+["\']#i', $html, $m ) ) {
			$flags[] = array(
				'type'     => 'base64_image',
				'label'    => __( 'Base64 inline image', 'twt-aeo-ultimate' ),
				'count'    => count( $m[0] ),
				'severity' => 'warning',
				'detail'   => __( 'Image data is embedded in the HTML instead of the media library — it can\'t be lazy-loaded, cached, or served as WebP.', 'twt-aeo-ultimate' ),
			);
		}

		// Iframes (embeds, third-party widgets).
		if ( preg_match_all( '#<iframe\b#i', $html, $m ) ) {
			$flags[] = array(
				'type'     => 'iframe',
				'label'    => __( 'Embedded iframe', 'twt-aeo-ultimate' ),
				'count'    => count( $m[0] ),
				'severity' => 'notice',
				'detail'   => __( 'Iframes load a whole extra document; add loading="lazy" or defer non-critical embeds.', 'twt-aeo-ultimate' ),
			);
		}

		return $flags;
	}

	/**
	 * Scan recent published posts/pages and return those with anti-patterns.
	 *
	 * @param int $limit Most-recently-modified posts to scan.
	 * @return array { scanned:int, offenders:array }
	 */
	public static function scan_all( $limit = 300 ) {
		$query = new WP_Query( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $limit ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );

		$offenders = array();
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$flags = self::analyze( $post->post_content );
			if ( empty( $flags ) ) {
				continue;
			}
			$offenders[] = array(
				'post_id'  => (int) $post_id,
				'title'    => get_the_title( $post_id ),
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'url'      => get_permalink( $post_id ),
				'flags'    => $flags,
			);
		}

		return array(
			'scanned'   => count( $query->posts ),
			'offenders' => $offenders,
		);
	}
}
