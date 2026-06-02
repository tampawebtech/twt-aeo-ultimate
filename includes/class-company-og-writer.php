<?php
/**
 * Company OG Writer
 *
 * Outputs the three OG/meta tags that other SEO plugins routinely miss:
 *
 *   fb:app_id          — sitewide, unlocks Meta Domain Insights & AI Ad Attribution
 *   article:publisher  — links the post to the company's Facebook Page
 *   article:author     — links the post to the individual author's Facebook profile
 *
 * Runs at priority 1 so it fires before Yoast / Rank Math (priority 10).
 * fb:app_id and the article:* tags are output regardless of which SEO plugin
 * is active because those plugins consistently leave these fields empty.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Company_OG_Writer {

	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_meta' ), 1 );
	}

	public static function output_meta() {
		$company = TWTAEO_Company_Profile::get();

		// ── fb:app_id — sitewide ─────────────────────────────────────────────
		if ( ! empty( $company['output_fb_app_id'] ) && ! empty( $company['fb_app_id'] ) ) {
			echo '<meta property="fb:app_id" content="' . esc_attr( $company['fb_app_id'] ) . '">' . "\n";
		}

		// article:publisher and article:author are only meaningful on singular posts.
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		echo "\n<!-- TWT AEO Publisher / Author OG -->\n";

		// ── article:publisher ─────────────────────────────────────────────────
		// Points to the company's Facebook Page — establishes the publisher entity
		// that Meta / Facebook uses for Domain Insights and structured attribution.
		if ( ! empty( $company['output_article_publisher'] ) && ! empty( $company['social_facebook'] ) ) {
			echo '<meta property="article:publisher" content="' . esc_url( $company['social_facebook'] ) . '">' . "\n";
		}

		// ── article:author ────────────────────────────────────────────────────
		// Points to the individual author's Facebook profile URL (not their name),
		// creating the Person ↔ Article relationship that powers E-E-A-T attribution
		// in Meta's AI Ad systems and structured search surfaces.
		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( $author_id && class_exists( 'TWTAEO_Author_Meta' ) ) {
			$social = TWTAEO_Author_Meta::get_social( $author_id );
			if ( ! empty( $social['facebook'] ) ) {
				echo '<meta property="article:author" content="' . esc_url( $social['facebook'] ) . '">' . "\n";
			}
		}
	}
}
