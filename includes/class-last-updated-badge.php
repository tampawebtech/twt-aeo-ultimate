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
		add_action( 'wp_head', array( __CLASS__, 'output_styles' ) );
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

	public static function output_styles() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		echo '<style>.twt-aeo-last-updated{font-size:.875em;color:#666;margin-bottom:1em;}</style>' . "\n";
	}
}
