<?php
/**
 * Article Schema Writer
 *
 * Outputs an Article JSON-LD block via wp_head on singular blog posts that are
 * NOT marked as Google News articles (those get NewsArticle from the News
 * Schema Writer instead). Fills the gap on sites with no SEO plugin — when an
 * SEO plugin is active it already emits Article schema on posts, so this writer
 * defers to avoid duplicate markup.
 *
 * Author and publisher nodes are reused from TWTAEO_News_Schema_Writer so the
 * @id references stay consistent across both article types.
 *
 * Output is suppressed when:
 *   - The post type is not in the article list (default: 'post').
 *   - The post is marked as a Google News article (NewsArticle handles it).
 *   - Any supported SEO plugin is active (it already outputs Article on posts).
 *   - A custom Article schema already exists for this post.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Article_Schema_Writer {

	public static function register_hooks() {
		// Priority 12 — same slot the News writer uses (they never both fire).
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 12 );
	}

	// ── Output ────────────────────────────────────────────────────────────────

	public static function output_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();

		// Post types that should carry Article schema (default: blog posts).
		$post_types = apply_filters( 'twtaeo_article_schema_post_types', array( 'post' ) );
		if ( ! in_array( get_post_type( $post_id ), (array) $post_types, true ) ) {
			return;
		}

		// Posts marked as news are emitted as NewsArticle by the News writer.
		if ( class_exists( 'TWTAEO_News_Meta' ) && TWTAEO_News_Meta::is_included( $post_id ) ) {
			return;
		}

		// Defer to any active SEO plugin — they already output Article on posts.
		if ( self::seo_plugin_handles_article() ) {
			return;
		}

		// Don't duplicate a manually-created custom Article schema.
		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' )
			&& TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, 'Article' ) ) {
			return;
		}

		$schema = self::build_schema( $post_id );
		if ( ! $schema ) {
			return;
		}

		// JSON-LD output. esc_html() would corrupt the JSON, so safety comes from the
		// HEX_* flags: <, >, &, ' and " are all encoded as \uXXXX, so the value cannot
		// break out of the script element or carry HTML/JS into the page.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-inert JSON-LD; see note above.
		echo "\n" . '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			. "\n" . '</script>' . "\n";
	}

	// ── Schema builder ──────────────────────────────────────────────────────────

	public static function build_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$headline = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $headline ) {
			return null;
		}

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => $headline,
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_post_time( 'c', true, $post_id ),
			'dateModified'  => get_post_modified_time( 'c', true, $post_id ),
		);

		// Featured image.
		$image_url = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image_url ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image_url,
			);
		}

		// Description from excerpt.
		$excerpt = get_the_excerpt( $post_id );
		if ( $excerpt ) {
			$schema['description'] = wp_strip_all_tags( $excerpt );
		}

		// Author — reuse the News writer's Person node builder for @id consistency.
		if ( class_exists( 'TWTAEO_News_Schema_Writer' ) ) {
			$author_node = TWTAEO_News_Schema_Writer::build_author_node( (int) $post->post_author );
			if ( $author_node ) {
				$schema['author'] = $author_node;
			}

			$publisher = TWTAEO_News_Schema_Writer::get_publisher_ref();
			if ( $publisher ) {
				$schema['publisher'] = $publisher;
			}
		}

		return $schema;
	}

	// ── SEO plugin deferral ───────────────────────────────────────────────────

	/**
	 * Whether a supported SEO plugin is active. They all output Article schema
	 * on posts by default, so we defer to avoid duplicate article markup.
	 *
	 * @return bool
	 */
	private static function seo_plugin_handles_article() {
		return defined( 'RANK_MATH_VERSION' )
			|| defined( 'WPSEO_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}
}
