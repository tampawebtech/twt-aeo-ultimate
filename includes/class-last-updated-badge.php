<?php
/**
 * Last Updated Badge
 *
 * Prepends a visible "Last Updated: [date]" line to single posts so AI models
 * and readers have an explicit freshness signal at the top of the content.
 *
 * Only shown when the post was modified after its published date.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Last_Updated_Badge {

	public static function register_hooks() {
		add_filter( 'the_content', array( __CLASS__, 'prepend_badge' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Enqueue the badge stylesheet on single posts, where the badge appears.
	 */
	public static function enqueue_style() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		wp_enqueue_style(
			'twt-aeo-last-updated-badge',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/last-updated-badge.css',
			array(),
			TWTAEO_VERSION
		);
	}

	/**
	 * Prepend "Last Updated" badge to single post content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function prepend_badge( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$published = get_the_time( 'U' );
		$modified  = get_the_modified_time( 'U' );

		if ( ! $modified || $modified <= $published ) {
			return $content;
		}

		$date_label = get_the_modified_date( get_option( 'date_format' ) );
		$datetime   = get_the_modified_date( 'c' );

		$badge = sprintf(
			'<p class="twt-aeo-last-updated"><time datetime="%s">Last Updated: %s</time></p>',
			esc_attr( $datetime ),
			esc_html( $date_label )
		);

		return $badge . $content;
	}

}
