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

		// output_meta() steps aside for Yoast and Rank Math, which then write their
		// own tags from the page. These hand them what was chosen here, so
		// installing either doesn't silently replace a hand-picked share title,
		// description or image.
		add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'fill_yoast_title' ) );
		add_filter( 'wpseo_opengraph_desc', array( __CLASS__, 'fill_yoast_description' ) );
		add_filter( 'wpseo_add_opengraph_images', array( __CLASS__, 'fill_yoast_image' ) );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( __CLASS__, 'fill_rank_math_title' ) );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( __CLASS__, 'fill_rank_math_description' ) );
		add_action( 'rank_math/opengraph/facebook/add_images', array( __CLASS__, 'fill_rank_math_image' ) );
	}

	/** @param string $title Rank Math's og:title. */
	public static function fill_rank_math_title( $title ) {
		$ours = self::ours_unless_theirs( self::META_TITLE, array( 'rank_math_facebook_title' ) );
		return '' !== $ours ? $ours : $title;
	}

	/** @param string $desc Rank Math's og:description. */
	public static function fill_rank_math_description( $desc ) {
		$ours = self::ours_unless_theirs( self::META_DESC, array( 'rank_math_facebook_description' ) );
		return '' !== $ours ? $ours : $desc;
	}

	/**
	 * Rank Math, like Yoast, runs this before adding its own images.
	 *
	 * @param object $images Rank Math's OpenGraph Image object.
	 */
	public static function fill_rank_math_image( $images ) {
		$ours = self::ours_unless_theirs( self::META_IMAGE, array( 'rank_math_facebook_image', 'rank_math_facebook_image_id' ) );
		if ( '' !== $ours && is_object( $images ) && method_exists( $images, 'add_image_by_url' ) ) {
			$images->add_image_by_url( $ours );
		}
	}

	/**
	 * Our saved value for the current page, unless the merchant also set one in
	 * the SEO plugin itself — what they typed there wins.
	 *
	 * @param string   $ours_key  Our postmeta key.
	 * @param string[] $their_keys The SEO plugin's postmeta keys for the same field.
	 * @return string Empty when there is nothing of ours to hand over.
	 */
	private static function ours_unless_theirs( $ours_key, array $their_keys ) {
		if ( ! is_singular() ) {
			return '';
		}
		$post_id = get_queried_object_id();
		foreach ( $their_keys as $key ) {
			if ( '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				return '';
			}
		}
		return (string) get_post_meta( $post_id, $ours_key, true );
	}

	/** @param string $title Yoast's og:title. */
	public static function fill_yoast_title( $title ) {
		$ours = self::ours_unless_theirs( self::META_TITLE, array( '_yoast_wpseo_opengraph-title' ) );
		return '' !== $ours ? $ours : $title;
	}

	/** @param string $desc Yoast's og:description. */
	public static function fill_yoast_description( $desc ) {
		$ours = self::ours_unless_theirs( self::META_DESC, array( '_yoast_wpseo_opengraph-description' ) );
		return '' !== $ours ? $ours : $desc;
	}

	/**
	 * Yoast runs this before adding its own images, so ours goes first. Yoast
	 * still appends the featured or first content image after it; sharing sites
	 * use the first og:image.
	 *
	 * @param object $images Yoast's Images container.
	 * @return object
	 */
	public static function fill_yoast_image( $images ) {
		$ours = self::ours_unless_theirs( self::META_IMAGE, array( '_yoast_wpseo_opengraph-image', '_yoast_wpseo_opengraph-image-id' ) );
		if ( '' !== $ours && is_object( $images ) && method_exists( $images, 'add_image_by_url' ) ) {
			$images->add_image_by_url( $ours );
		}
		return $images;
	}

	/**
	 * Output OG meta tags on the frontend.
	 * Skipped if Rank Math or Yoast is active — they manage OG themselves.
	 */
	public static function output_meta() {
		if ( TWTAEO_SEO_Compatibility::rank_math_writes_head() || defined( 'WPSEO_VERSION' ) ) {
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
	 * chain: saved og:image → featured image → first image in the content →
	 * first WooCommerce gallery image. One chain, used by the OG output, the
	 * Twitter Card downgrade decision, and the Social Graph screen — three
	 * surfaces disagreeing about "has an image" is how a merchant stops
	 * trusting all of them.
	 *
	 * The content step is there for the draft written somewhere else and
	 * pasted in: images in the body, no featured image, nothing saved here.
	 * Sharing that page with no image at all is worse than sharing the picture
	 * it already leads with. It only parses the body once both earlier steps
	 * have come up empty, and parse_content_images() returns immediately when
	 * the content holds no <img> at all, so the common path costs nothing.
	 *
	 * @param int         $post_id
	 * @param string|null $content Body to read the content step from. Defaults
	 *                             to the saved post. Pass the editor's current
	 *                             content to answer for an unsaved draft.
	 * @return array|null { url: string, id: int, source: string } — source is
	 *                    one of og|featured|content|gallery. id is 0 for a URL
	 *                    with no known attachment. Null when no image exists.
	 */
	public static function effective_image( $post_id, $content = null ) {
		$saved = get_post_meta( $post_id, self::META_IMAGE, true );
		if ( ! empty( $saved ) ) {
			return array( 'url' => $saved, 'id' => 0, 'source' => 'og' );
		}
		$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $thumb ) {
			return array(
				'url'    => $thumb,
				'id'     => (int) get_post_thumbnail_id( $post_id ),
				'source' => 'featured',
			);
		}
		$from_content = self::first_content_image( $post_id, $content );
		if ( $from_content ) {
			return $from_content;
		}
		if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			$gallery = $product ? $product->get_gallery_image_ids() : array();
			if ( ! empty( $gallery ) ) {
				$url = wp_get_attachment_image_url( $gallery[0], 'large' );
				if ( $url ) {
					return array( 'url' => $url, 'id' => (int) $gallery[0], 'source' => 'gallery' );
				}
			}
		}
		return null;
	}

	/**
	 * The first usable <img> in a post's body.
	 *
	 * Only absolute http(s) sources count: a relative src renders fine in the
	 * page but is not a shareable og:image, and emitting one is worse than
	 * emitting none because the scraper resolves it against its own host.
	 *
	 * @param int         $post_id
	 * @param string|null $content Defaults to the saved post's content.
	 * @return array|null { url: string, id: int, source: 'content' }
	 */
	public static function first_content_image( $post_id, $content = null ) {
		if ( ! class_exists( 'TWTAEO_Image_Optimizer' ) ) {
			return null;
		}
		if ( null === $content ) {
			$post    = get_post( $post_id );
			$content = $post ? $post->post_content : '';
		}
		foreach ( TWTAEO_Image_Optimizer::parse_content_images( (string) $content ) as $img ) {
			$src = isset( $img['src'] ) ? trim( (string) $img['src'] ) : '';
			if ( '' === $src || 0 !== strpos( $src, 'http' ) ) {
				continue;
			}
			return array( 'url' => $src, 'id' => (int) $img['id'], 'source' => 'content' );
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
		return TWTAEO_SEO_Compatibility::rank_math_writes_head() || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}
}
