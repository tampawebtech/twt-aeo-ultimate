<?php
/**
 * Twitter / X Card Writer
 *
 * Stores twitter:card and twitter:creator per-post and outputs
 * twitter:* meta tags via wp_head. twitter:title, twitter:description,
 * and twitter:image are intentionally omitted — platforms fall back
 * to og:* tags automatically.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Twitter_Writer {

	const META_CARD    = '_twtaeo_tw_card';
	const META_CREATOR = '_twtaeo_tw_creator';
	const OPTION_SITE  = 'twtaeo_twitter_site_handle';

	public static function register_hooks() {
		// Priority 2 — just after OG (priority 1).
		add_action( 'wp_head', array( __CLASS__, 'output_meta' ), 2 );
	}

	/**
	 * Output twitter:card, twitter:site, twitter:creator on singular posts.
	 * Skipped when Rank Math or Yoast is active (they manage Twitter Cards).
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

		$saved   = self::get( $post_id );
		$card    = $saved['tw_card'] ?: 'summary_large_image';
		$creator = $saved['tw_creator'] ?: '';
		$site    = self::get_site_handle();

		// Downgrade to summary if no image is available.
		if ( $card === 'summary_large_image' ) {
			$og_image = get_post_meta( $post_id, TWTAEO_OG_Writer::META_IMAGE, true );
			$fallback = get_the_post_thumbnail_url( $post_id, 'large' );
			if ( ! $og_image && ! $fallback ) {
				$card = 'summary';
			}
		}

		echo "\n<!-- TWT AEO Twitter Card -->\n";
		echo '<meta name="twitter:card" content="' . esc_attr( $card ) . '">' . "\n";

		if ( $site ) {
			echo '<meta name="twitter:site" content="' . esc_attr( self::normalize_handle( $site ) ) . '">' . "\n";
		}
		if ( $creator ) {
			echo '<meta name="twitter:creator" content="' . esc_attr( self::normalize_handle( $creator ) ) . '">' . "\n";
		}
	}

	/**
	 * Save Twitter Card fields to postmeta.
	 *
	 * @param int   $post_id
	 * @param array $fields { tw_card, tw_creator }
	 * @return bool
	 */
	public static function save( $post_id, array $fields ) {
		if ( ! get_post( $post_id ) ) {
			return false;
		}

		$allowed_cards = array( 'summary', 'summary_large_image' );
		$card = sanitize_key( $fields['tw_card'] ?? 'summary_large_image' );
		if ( ! in_array( $card, $allowed_cards, true ) ) {
			$card = 'summary_large_image';
		}

		update_post_meta( $post_id, self::META_CARD,    $card );
		update_post_meta( $post_id, self::META_CREATOR, sanitize_text_field( $fields['tw_creator'] ?? '' ) );

		return true;
	}

	/**
	 * Get saved Twitter Card data for a post.
	 *
	 * @param int $post_id
	 * @return array { tw_card, tw_creator }
	 */
	public static function get( $post_id ) {
		return array(
			'tw_card'    => get_post_meta( $post_id, self::META_CARD,    true ),
			'tw_creator' => get_post_meta( $post_id, self::META_CREATOR, true ),
		);
	}

	/**
	 * Get the site-level twitter:site handle.
	 *
	 * @return string
	 */
	public static function get_site_handle() {
		return get_option( self::OPTION_SITE, '' );
	}

	/**
	 * Save the site-level twitter:site handle.
	 *
	 * @param string $handle
	 */
	public static function save_site_handle( $handle ) {
		update_option( self::OPTION_SITE, sanitize_text_field( $handle ) );
	}

	/**
	 * Get the post author's Twitter handle from author meta (if the
	 * author-entity module has stored it) or the native WP twitter field.
	 *
	 * @param int $post_id
	 * @return string  Raw handle, possibly without leading @.
	 */
	public static function get_author_twitter( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		if ( class_exists( 'TWTAEO_Author_Meta' ) ) {
			$data    = TWTAEO_Author_Meta::get_author_data( (int) $post->post_author );
			$twitter = $data['social']['twitter'] ?? '';
			if ( $twitter ) {
				return $twitter;
			}
		}

		// Native WP twitter field (set via Edit User).
		return get_user_meta( (int) $post->post_author, 'twitter', true ) ?: '';
	}

	/**
	 * Ensure handle starts with @.
	 *
	 * @param string $handle
	 * @return string
	 */
	public static function normalize_handle( $handle ) {
		$handle = trim( $handle );
		if ( $handle && $handle[0] !== '@' ) {
			$handle = '@' . $handle;
		}
		return $handle;
	}
}
