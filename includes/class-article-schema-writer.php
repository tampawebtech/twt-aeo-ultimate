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
		// Switched off on the Schema Conflicts screen, or handed to AEO Ultimate for
		// WooCommerce — either way register nothing, rather than emitting the node
		// and filtering it back out of the graph afterwards.
		if ( ! TWTAEO_Output_Control::should_write( 'schema_article' ) ) {
			return;
		}
		// Priority 12 — same slot the News writer uses (they never both fire).
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 12 );
		// When the Knowledge Graph is active, contribute the Article node to its
		// unified @graph instead of printing a standalone block.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 10, 2 );
	}

	// ── Output ────────────────────────────────────────────────────────────────

	public static function output_schema() {
		// The Knowledge Graph module folds this node into its unified @graph.
		if ( class_exists( 'TWTAEO_Knowledge_Graph' ) && TWTAEO_Knowledge_Graph::is_folding() ) {
			return;
		}
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

		// SASWP per-post custom schema already carries an Article on this post —
		// publishing a second one puts two Article entities on one URL.
		if ( self::saswp_handles_article( $post_id ) ) {
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

		// Language + translation links (falls back to the site locale on
		// single-language sites; workTranslation only appears when a
		// multilingual plugin reports published translations).
		if ( class_exists( 'TWTAEO_Multilingual' ) ) {
			$schema = TWTAEO_Multilingual::decorate_article( $schema, $post_id, 'Article' );
		}

		// Declared content provenance (empty = human default, nothing emitted).
		if ( class_exists( 'TWTAEO_Content_Provenance' ) ) {
			$source = TWTAEO_Content_Provenance::get_url( $post_id );
			if ( '' !== $source ) {
				$schema['digitalSourceType'] = $source;
			}
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

	// ── Knowledge Graph contribution ──────────────────────────────────────────

	/**
	 * Contribute the Article (+ author Person) nodes to the unified @graph.
	 * Applies the same deferrals as output_schema() so behaviour is identical —
	 * only the delivery (one @graph vs a separate block) changes.
	 *
	 * @param array $nodes   Nodes collected so far.
	 * @param array $context { post_id, url, webpage_id, org_id, ... }.
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		$post_id = (int) ( $context['post_id'] ?? 0 );
		if ( ! $post_id || ! is_singular() ) {
			return $nodes;
		}

		$post_types = apply_filters( 'twtaeo_article_schema_post_types', array( 'post' ) );
		if ( ! in_array( get_post_type( $post_id ), (array) $post_types, true ) ) {
			return $nodes;
		}
		if ( class_exists( 'TWTAEO_News_Meta' ) && TWTAEO_News_Meta::is_included( $post_id ) ) {
			return $nodes; // NewsArticle owns this post.
		}
		if ( self::seo_plugin_handles_article() ) {
			return $nodes;
		}
		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' )
			&& TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, 'Article' ) ) {
			return $nodes; // A user-authored Article schema is emitted by the custom writer.
		}
		if ( self::saswp_handles_article( $post_id ) ) {
			return $nodes; // SASWP custom schema carries the Article for this post.
		}

		$schema = self::build_schema( $post_id );
		if ( ! $schema ) {
			return $nodes;
		}

		return array_merge( $nodes, self::article_graph_nodes( $schema, $context ) );
	}

	/**
	 * Turn a standalone Article/NewsArticle schema into graph nodes: strip the
	 * @context, anchor it with a canonical @id ({url}#article — Article and
	 * NewsArticle share the slot and never both fire), promote the author Person
	 * to its own node referenced by @id, and reference the spine Organization as
	 * publisher. Shared with TWTAEO_News_Schema_Writer.
	 *
	 * @param array $schema  A build_schema() result (with @context).
	 * @param array $context Graph context.
	 * @return array
	 */
	public static function article_graph_nodes( $schema, $context ) {
		unset( $schema['@context'] );

		$url           = $context['url'] ?? ( $schema['url'] ?? home_url( '/' ) );
		$schema['@id'] = $url . '#article';

		if ( ! empty( $context['webpage_id'] ) ) {
			$schema['isPartOf']         = array( '@id' => $context['webpage_id'] );
			$schema['mainEntityOfPage'] = array( '@id' => $context['webpage_id'] );
		}

		$out = array();

		// Author → its own Person node, referenced by @id (dedupes with the Author
		// writer, which may contribute the same Person).
		if ( ! empty( $schema['author'] ) && is_array( $schema['author'] ) && ! empty( $schema['author']['@id'] ) ) {
			$out[]            = $schema['author'];
			$schema['author'] = array( '@id' => $schema['author']['@id'] );
		}

		// Publisher → the spine Organization by reference (no embedded duplicate).
		if ( ! empty( $context['org_id'] ) ) {
			$schema['publisher'] = array( '@id' => $context['org_id'] );
		}

		$out[] = $schema;
		return $out;
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

	/**
	 * Whether SASWP's per-post custom schema publishes an Article (or Article
	 * subtype) on this post. Its custom markup prints straight into the page,
	 * so writing ours too would put two Article entities on one URL.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function saswp_handles_article( $post_id ) {
		if ( ! class_exists( 'TWTAEO_SEO_Compatibility' ) ) {
			return false;
		}
		return TWTAEO_SEO_Compatibility::saswp_post_has_type( $post_id, 'Article' )
			|| TWTAEO_SEO_Compatibility::saswp_post_has_type( $post_id, 'BlogPosting' )
			|| TWTAEO_SEO_Compatibility::saswp_post_has_type( $post_id, 'NewsArticle' );
	}
}
