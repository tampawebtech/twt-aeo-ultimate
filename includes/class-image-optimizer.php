<?php
/**
 * Image Optimizer (Image SEO)
 *
 * Scans posts and pages (not WooCommerce products — those are handled by the
 * WooCommerce module) for images missing alt text, and fills it via AI vision
 * or manual entry. Writes both the Media Library attachment alt and the empty
 * inline alt="" in the post content so the live page reflects it.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Image_Optimizer {

	const SCAN_LIMIT  = 200;
	const ALT_MAX     = 125;
	const MAX_PER_RUN = 12;

	/**
	 * Post types scanned (products excluded — handled by the WooCommerce module).
	 *
	 * @return string[]
	 */
	public static function post_types() {
		return apply_filters( 'twtaeo_image_seo_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Scan posts/pages that contain images. Worst coverage first.
	 *
	 * @param int $limit
	 * @return array[] Each: { post, summary:{total,missing} }
	 */
	public static function scan( $limit = self::SCAN_LIMIT ) {
		$posts = get_posts( array(
			'post_type'      => self::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$rows = array();
		foreach ( $posts as $post ) {
			$images = self::collect( $post );
			// Pages with NO images at all are a finding, not a page to skip:
			// there is nothing to describe and nothing for a social share or an
			// AI answer to show. They were silently omitted here, so a store
			// whose pages lack images saw an empty screen and concluded the
			// scanner was broken. They rank below alt-text gaps (no_images
			// flag), because a missing image cannot be fixed from this screen.
			$rows[] = array(
				'post'    => $post,
				'summary' => self::summarize( $images ),
			);
		}

		// Worst alt coverage first; image-less pages after those, since their
		// fix (adding an image) lives in the editor, not here.
		usort( $rows, function( $a, $b ) {
			$a_empty = ( 0 === $a['summary']['total'] );
			$b_empty = ( 0 === $b['summary']['total'] );
			if ( $a_empty !== $b_empty ) {
				return $a_empty ? 1 : -1;
			}
			return $b['summary']['missing'] - $a['summary']['missing'];
		} );

		return $rows;
	}

	/**
	 * Summary counts for a post's images.
	 *
	 * @param int|WP_Post $post
	 * @return array { total, missing }
	 */
	public static function image_summary( $post ) {
		$post = get_post( $post );
		return $post ? self::summarize( self::collect( $post ) ) : array( 'total' => 0, 'missing' => 0 );
	}

	/**
	 * Collect a post's images (featured first, then in-content), each with its
	 * current effective alt text.
	 *
	 * @param WP_Post $post
	 * @return array[] Each: { key, context, id, src, alt, cindex }
	 */
	public static function collect( WP_Post $post ) {
		$out = array();

		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$src = wp_get_attachment_url( $thumb_id );
			if ( $src ) {
				$out[] = array(
					'key'     => 'featured',
					'context' => 'featured',
					'id'      => (int) $thumb_id,
					'src'     => $src,
					'alt'     => trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ),
					'cindex'  => -1,
				);
			}
		}

		$cindex = -1;
		foreach ( self::parse_content_images( $post->post_content ) as $img ) {
			$cindex++;
			$out[] = array(
				'key'     => 'c' . $cindex,
				'context' => 'content',
				'id'      => (int) $img['id'],
				'src'     => $img['src'],
				'alt'     => trim( (string) $img['alt'] ),
				'cindex'  => $cindex,
			);
		}

		return $out;
	}

	/**
	 * Parse <img> tags from post content in document order.
	 *
	 * @param string $content
	 * @return array[] Each: { tag, id, src, alt }
	 */
	public static function parse_content_images( $content ) {
		$out = array();
		if ( ! $content || strpos( $content, '<img' ) === false ) {
			return $out;
		}
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
			return $out;
		}
		foreach ( $matches[0] as $tag ) {
			$src = '';
			if ( preg_match( '/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
				$src = $m[2] !== '' ? $m[2] : ( $m[3] ?? '' );
			}
			$alt = '';
			if ( preg_match( '/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
				$alt = isset( $m[2] ) && $m[2] !== '' ? $m[2] : ( $m[3] ?? '' );
			}
			$id = 0;
			if ( preg_match( '/wp-image-(\d+)/', $tag, $m ) ) {
				$id = (int) $m[1];
			}
			$out[] = array( 'tag' => $tag, 'id' => $id, 'src' => $src, 'alt' => html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) );
		}
		return $out;
	}

	/**
	 * Generate alt text via AI vision for a post's images that are missing it.
	 *
	 * @param int         $post_id
	 * @param string|null $provider
	 * @return array|WP_Error { filled, skipped, total }
	 */
	public static function generate_alt( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'bad_post', __( 'Post not found.', 'twt-aeo-ultimate' ) );
		}

		$images = self::collect( $post );
		if ( empty( $images ) ) {
			return new WP_Error( 'no_images', __( 'This page has no images.', 'twt-aeo-ultimate' ) );
		}

		$provider   = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$title      = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$filled     = 0;
		$skipped    = 0;
		$processed  = 0;
		$last_error = null;
		$content_updates = array(); // cindex => alt

		foreach ( $images as $img ) {
			if ( '' !== $img['alt'] ) {
				$skipped++;
				continue;
			}
			if ( $processed >= self::MAX_PER_RUN || ! $img['src'] ) {
				continue;
			}
			$processed++;

			$alt = self::ai_alt_for_url( $img['src'], $title, $provider );
			if ( is_wp_error( $alt ) ) {
				$last_error = $alt;
				continue;
			}
			if ( '' === $alt ) {
				continue;
			}

			if ( $img['id'] ) {
				update_post_meta( $img['id'], '_wp_attachment_image_alt', $alt );
			}
			if ( 'content' === $img['context'] ) {
				$content_updates[ $img['cindex'] ] = $alt;
			}
			$filled++;
		}

		if ( ! empty( $content_updates ) ) {
			self::apply_content_alts( $post, $content_updates );
		}

		if ( 0 === $filled && $last_error ) {
			return $last_error;
		}

		return array(
			'filled'  => $filled,
			'skipped' => $skipped,
			'total'   => count( $images ),
		);
	}

	/**
	 * Save manually-entered alt text from the modal.
	 *
	 * @param int   $post_id
	 * @param array $map  key => alt ( 'featured' | 'c{n}' ).
	 * @return array|WP_Error { saved, total }
	 */
	public static function save_manual( $post_id, $map ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'bad_post', __( 'Post not found.', 'twt-aeo-ultimate' ) );
		}
		$images = self::collect( $post );
		$by_key = array();
		foreach ( $images as $img ) {
			$by_key[ $img['key'] ] = $img;
		}

		$saved           = 0;
		$content_updates = array();
		foreach ( (array) $map as $key => $alt ) {
			if ( ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$img = $by_key[ $key ];
			$alt = self::clean_alt( $alt );

			if ( $img['id'] ) {
				update_post_meta( $img['id'], '_wp_attachment_image_alt', $alt );
			}
			if ( 'content' === $img['context'] ) {
				$content_updates[ $img['cindex'] ] = $alt;
			}
			$saved++;
		}

		if ( ! empty( $content_updates ) ) {
			self::apply_content_alts( $post, $content_updates );
		}

		return array( 'saved' => $saved, 'total' => count( $images ) );
	}

	/**
	 * Apply a hub-supplied list of alts to a post's images. Used by the agency
	 * connector's image_alt directive; mirrors the local save path.
	 *
	 * @param int   $post_id
	 * @param array $images  [ { attachment_id?, cindex?, alt } ]
	 * @return int  Number of images written.
	 */
	public static function apply_alt_directive( $post_id, array $images ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}
		$applied         = 0;
		$content_updates = array();

		foreach ( $images as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$alt  = self::clean_alt( $img['alt'] ?? '' );
			$att  = isset( $img['attachment_id'] ) ? absint( $img['attachment_id'] ) : 0;
			$cidx = ( isset( $img['cindex'] ) && '' !== $img['cindex'] && null !== $img['cindex'] ) ? (int) $img['cindex'] : null;

			if ( $att ) {
				update_post_meta( $att, '_wp_attachment_image_alt', $alt );
				$applied++;
			}
			if ( null !== $cidx ) {
				$content_updates[ $cidx ] = $alt;
				if ( ! $att ) {
					$applied++;
				}
			}
		}

		if ( ! empty( $content_updates ) ) {
			self::apply_content_alts( $post, $content_updates );
		}

		return $applied;
	}

	// ── Internals ────────────────────────────────────────────────────────────────

	private static function summarize( $images ) {
		$missing = 0;
		foreach ( $images as $img ) {
			if ( '' === trim( (string) $img['alt'] ) ) {
				$missing++;
			}
		}
		return array( 'total' => count( $images ), 'missing' => $missing );
	}

	/**
	 * Rewrite the alt attribute of the Nth content <img> for each given index.
	 *
	 * @param WP_Post $post
	 * @param array   $updates  cindex => alt
	 */
	private static function apply_content_alts( WP_Post $post, $updates ) {
		$i = -1;
		$new = preg_replace_callback( '/<img\b[^>]*>/i', function( $m ) use ( &$i, $updates ) {
			$i++;
			if ( array_key_exists( $i, $updates ) ) {
				return self::set_tag_alt( $m[0], $updates[ $i ] );
			}
			return $m[0];
		}, $post->post_content );

		if ( $new !== null && $new !== $post->post_content ) {
			wp_update_post( array(
				'ID'           => $post->ID,
				'post_content' => $new,
			) );
		}
	}

	/**
	 * Set (or insert) the alt attribute on a single <img> tag.
	 *
	 * @param string $tag
	 * @param string $alt
	 * @return string
	 */
	private static function set_tag_alt( $tag, $alt ) {
		$attr = esc_attr( $alt );
		if ( preg_match( '/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag ) ) {
			return preg_replace( '/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', 'alt="' . $attr . '"', $tag, 1 );
		}
		// No alt attribute present — insert one right after <img.
		return preg_replace( '/<img\b/i', '<img alt="' . $attr . '"', $tag, 1 );
	}

	/**
	 * Generate alt text for one image URL via AI vision.
	 *
	 * @param string $url
	 * @param string $title
	 * @param string $provider
	 * @return string|WP_Error
	 */
	private static function ai_alt_for_url( $url, $title, $provider ) {
		$prompt = "Write descriptive alt text for this image, max " . self::ALT_MAX . " characters. "
			. "It appears on the page titled \"$title\". Describe the visible subject for accessibility and search. "
			. "Do not start with \"image of\" or \"photo of\".";

		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'images'      => array( $url ),
			'max_tokens'  => 120,
			'temperature' => 0.3,
			'system'      => 'You write concise, factual alt text for images on web pages. Describe only what is '
				. 'visible. Return plain text with no quotes, labels, or markdown.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		return self::clean_alt( $raw );
	}

	private static function clean_alt( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = trim( $text, "\"' \t\n\r" );
		if ( mb_strlen( $text ) > self::ALT_MAX ) {
			$text = rtrim( mb_substr( $text, 0, self::ALT_MAX ) );
		}
		return $text;
	}

	/**
	 * Build the per-image list for the manual-edit modal.
	 *
	 * @param int $post_id
	 * @return array[]
	 */
	public static function modal_images( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$out = array();
		foreach ( self::collect( $post ) as $img ) {
			$thumb = $img['id'] ? wp_get_attachment_image_url( $img['id'], 'thumbnail' ) : $img['src'];
			$out[] = array(
				'key'     => $img['key'],
				'context' => $img['context'] === 'featured' ? 'Featured' : 'In content',
				'src'     => $img['src'],
				'thumb'   => $thumb ?: $img['src'],
				'alt'     => $img['alt'],
			);
		}
		return $out;
	}
}
