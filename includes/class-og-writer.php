<?php
/**
 * Open Graph Writer
 *
 * Stores user-defined Open Graph values in postmeta and outputs them
 * via wp_head on the frontend. Only outputs when the post has saved
 * OG data from our modal and no major SEO plugin is already handling it.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_OG_Writer {

	const META_TITLE     = '_twtaeo_og_title';
	const META_DESC      = '_twtaeo_og_description';
	const META_TYPE      = '_twtaeo_og_type';
	const META_IMAGE     = '_twtaeo_og_image';
	const META_IMAGE_ALT = '_twtaeo_og_image_alt';

	public static function register_hooks() {
		// Priority 1 — before SEO plugins (which typically run at 10).
		add_action( 'wp_head', array( __CLASS__, 'output_meta' ), 1 );
	}

	/**
	 * Output OG meta tags on the frontend.
	 * Skipped if Rank Math or Yoast is active — they manage OG themselves.
	 */
	public static function output_meta() {
		if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$title     = get_post_meta( $post_id, self::META_TITLE, true );
		$desc      = get_post_meta( $post_id, self::META_DESC,  true );
		$type      = get_post_meta( $post_id, self::META_TYPE,  true );
		$image     = get_post_meta( $post_id, self::META_IMAGE, true );
		$image_alt = get_post_meta( $post_id, self::META_IMAGE_ALT, true );

		if ( empty( $title ) && empty( $desc ) && empty( $type ) && empty( $image ) ) {
			return;
		}

		// Fallbacks.
		if ( empty( $title ) ) $title = get_the_title( $post_id );
		if ( empty( $type )  ) $type  = 'website';
		if ( empty( $image ) ) {
			$resolved = self::effective_image( $post_id );
			if ( $resolved ) {
				$image = $resolved['url'];
				if ( empty( $image_alt ) && $resolved['id'] ) {
					$image_alt = self::image_label( $resolved['id'] );
				}
			}
		}

		$url       = get_permalink( $post_id );
		$site_name = get_bloginfo( 'name' );

		echo "\n<!-- TWT AEO Open Graph -->\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
		echo '<meta property="og:type"      content="' . esc_attr( $type )      . '">' . "\n";
		echo '<meta property="og:title"     content="' . esc_attr( $title )     . '">' . "\n";
		echo '<meta property="og:url"       content="' . esc_url( $url )        . '">' . "\n";

		if ( ! empty( $desc ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
			if ( ! empty( $image_alt ) ) {
				echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ) . '">' . "\n";
			}
		}
	}

	/**
	 * The image this page will actually share, walking the whole fallback
	 * chain: saved og:image → featured image → first WooCommerce gallery
	 * image. One chain, used by the OG output, the Twitter Card downgrade
	 * decision, and the Social Graph screen — three surfaces disagreeing
	 * about "has an image" is how a merchant stops trusting all of them.
	 *
	 * @param int $post_id
	 * @return array|null { url: string, id: int } — id is 0 for a saved URL
	 *                    with no known attachment. Null when no image exists.
	 */
	public static function effective_image( $post_id ) {
		$saved = get_post_meta( $post_id, self::META_IMAGE, true );
		if ( ! empty( $saved ) ) {
			return array( 'url' => $saved, 'id' => 0 );
		}
		$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $thumb ) {
			return array( 'url' => $thumb, 'id' => (int) get_post_thumbnail_id( $post_id ) );
		}
		if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			$gallery = $product ? $product->get_gallery_image_ids() : array();
			if ( ! empty( $gallery ) ) {
				$url = wp_get_attachment_image_url( $gallery[0], 'large' );
				if ( $url ) {
					return array( 'url' => $url, 'id' => (int) $gallery[0] );
				}
			}
		}
		return null;
	}

	/**
	 * Save OG fields to postmeta.
	 *
	 * @param int   $post_id
	 * @param array $fields { og_title, og_description, og_type }
	 * @return bool
	 */
	public static function save( $post_id, array $fields ) {
		if ( ! get_post( $post_id ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( $fields['og_title'] ?? '' ) );
		update_post_meta( $post_id, self::META_DESC,  sanitize_textarea_field( $fields['og_description'] ?? '' ) );
		update_post_meta( $post_id, self::META_TYPE,  sanitize_key( $fields['og_type'] ?? 'website' ) );
		update_post_meta( $post_id, self::META_IMAGE, esc_url_raw( $fields['og_image'] ?? '' ) );
		update_post_meta( $post_id, self::META_IMAGE_ALT, sanitize_text_field( $fields['og_image_alt'] ?? '' ) );

		// Log OG activation timestamp (same mechanism as schema activation) when
		// real OG content is provided.
		$has_content = ! empty( $fields['og_title'] ) || ! empty( $fields['og_description'] ) || ! empty( $fields['og_image'] );
		if ( $has_content && class_exists( 'TWTAEO_Pro_Transmitter' ) ) {
			TWTAEO_Pro_Transmitter::record_og_deploy( $post_id );
		}

		return true;
	}

	/**
	 * Get saved OG data for a post.
	 *
	 * @param int $post_id
	 * @return array { og_title, og_description, og_type }
	 */
	public static function get( $post_id ) {
		return array(
			'og_title'       => get_post_meta( $post_id, self::META_TITLE, true ),
			'og_description' => get_post_meta( $post_id, self::META_DESC,  true ),
			'og_type'        => get_post_meta( $post_id, self::META_TYPE,  true ),
			'og_image'       => get_post_meta( $post_id, self::META_IMAGE, true ),
			'og_image_alt'   => get_post_meta( $post_id, self::META_IMAGE_ALT, true ),
		);
	}

	// ── Auto-fill helpers ────────────────────────────────────────────────────

	/**
	 * Automatic OG values for a post that has nothing saved yet: the post
	 * title, the featured image (or first attached image), and that image's
	 * label when it's a real description rather than a generic camera name.
	 *
	 * @param int $post_id
	 * @return array { title, image_url, image_alt }
	 */
	public static function auto_defaults( $post_id ) {
		$defaults = array(
			'title'     => get_the_title( $post_id ),
			'image_url' => '',
			'image_alt' => '',
		);

		$att_id = get_post_thumbnail_id( $post_id );
		if ( ! $att_id ) {
			$media  = get_attached_media( 'image', $post_id );
			$first  = $media ? reset( $media ) : null;
			$att_id = $first ? (int) $first->ID : 0;
		}

		if ( $att_id ) {
			$defaults['image_url'] = (string) wp_get_attachment_image_url( $att_id, 'large' );
			$defaults['image_alt'] = self::image_label( $att_id );
		}

		return $defaults;
	}

	/**
	 * Best human label for an attachment: alt text first, then the media
	 * title — but only when it's descriptive, never a generic camera/export
	 * name like "IMG_4302" or "Screenshot 2026-06-01".
	 *
	 * @param int $att_id Attachment ID.
	 * @return string Label, or '' when nothing descriptive exists.
	 */
	public static function image_label( $att_id ) {
		if ( ! $att_id ) {
			return '';
		}

		$candidates = array(
			trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) ),
			trim( (string) get_the_title( $att_id ) ),
		);

		foreach ( $candidates as $label ) {
			if ( $label !== '' && ! self::is_generic_image_label( $label ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Whether a label is a generic camera/export/filename artifact rather
	 * than a real description.
	 *
	 * @param string $label
	 * @return bool
	 */
	public static function is_generic_image_label( $label ) {
		$label = strtolower( trim( (string) $label ) );

		if ( strlen( $label ) < 4 ) {
			return true;
		}

		// Filename with an image extension.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|bmp|tiff?|heic)$/', $label ) ) {
			return true;
		}

		// Camera/device exports and platform defaults, optionally followed by
		// numbers, dates, dimensions, or copy markers: IMG_4302, DSC01234,
		// Screenshot 2026-06-01 at 9.41.12, photo-2-scaled, unnamed (1)…
		if ( preg_match(
			'/^(img|image|dsc[fn]?|dcim|pxl|gopr|mvimg|vid|screen[\s_-]?shot|screenshot|photo|picture|pic|untitled|unnamed|capture|snapshot|scan|file|frame|clipboard|pasted[\s_-]?image|whatsapp[\s_-]?image|placeholder|default|thumbnail|temp)([\s_\-.()\d:at]+|copy|scaled|edited|final|e\d+)*$/',
			$label
		) ) {
			return true;
		}

		// Nothing but digits, dates, dimensions, and separators.
		if ( preg_match( '/^[\d\s_\-.()x×:]+$/', $label ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a SEO plugin is managing OG output.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active() {
		return defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}
}
