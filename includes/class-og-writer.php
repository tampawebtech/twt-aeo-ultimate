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

	const META_TITLE = '_twtaeo_og_title';
	const META_DESC  = '_twtaeo_og_description';
	const META_TYPE  = '_twtaeo_og_type';
	const META_IMAGE = '_twtaeo_og_image';

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

		$title = get_post_meta( $post_id, self::META_TITLE, true );
		$desc  = get_post_meta( $post_id, self::META_DESC,  true );
		$type  = get_post_meta( $post_id, self::META_TYPE,  true );
		$image = get_post_meta( $post_id, self::META_IMAGE, true );

		if ( empty( $title ) && empty( $desc ) && empty( $type ) && empty( $image ) ) {
			return;
		}

		// Fallbacks.
		if ( empty( $title ) ) $title = get_the_title( $post_id );
		if ( empty( $type )  ) $type  = 'website';
		if ( empty( $image ) ) $image = get_the_post_thumbnail_url( $post_id, 'large' );

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
		}
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
		);
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
