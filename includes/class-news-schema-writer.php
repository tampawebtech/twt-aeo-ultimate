<?php
/**
 * News Schema Writer
 *
 * Outputs a NewsArticle JSON-LD block via wp_head on singular posts that are
 * included in the news sitemap. The author node is built from the full Author
 * Entity system (Person @id, name, url, sameAs) and the publisher node comes
 * from TWTAEO_Company_Schema_Writer when available.
 *
 * NewsArticle is opt-in: it is only emitted on posts an editor has marked as a
 * news article via the news metabox. Output is suppressed when:
 *   - The post type is not enabled in News settings.
 *   - The post is not marked as a news article.
 *   - schema_enabled is off in News settings.
 *   - Rank Math or Yoast already declares a NewsArticle schema for this post.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_News_Schema_Writer {

	public static function register_hooks() {
		// Priority 12 — after Company schema (5) but before Author/Service (20).
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 12 );
		// Fold the NewsArticle node into the Knowledge Graph when active.
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

		$post_id  = get_the_ID();
		$settings = TWTAEO_News_Sitemap::get_settings();

		// Schema must be enabled in settings.
		if ( empty( $settings['schema_enabled'] ) ) {
			return;
		}

		// Post type must be in the news-enabled list.
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
		if ( ! in_array( get_post_type( $post_id ), $post_types, true ) ) {
			return;
		}

		// Opt-in: only posts explicitly marked as news get NewsArticle schema.
		if ( ! TWTAEO_News_Meta::is_included( $post_id ) ) {
			return;
		}

		// Defer if an SEO plugin already outputs NewsArticle for this post.
		if ( self::seo_plugin_handles_news_article( $post_id ) ) {
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

	// ── Knowledge Graph contribution ──────────────────────────────────────────

	/**
	 * Contribute the NewsArticle (+ author Person) nodes to the unified @graph,
	 * applying the same opt-in / deferral rules as output_schema().
	 *
	 * @param array $nodes
	 * @param array $context
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		$post_id = (int) ( $context['post_id'] ?? 0 );
		if ( ! $post_id || ! is_singular() ) {
			return $nodes;
		}

		$settings = TWTAEO_News_Sitemap::get_settings();
		if ( empty( $settings['schema_enabled'] ) ) {
			return $nodes;
		}
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
		if ( ! in_array( get_post_type( $post_id ), $post_types, true ) ) {
			return $nodes;
		}
		if ( ! TWTAEO_News_Meta::is_included( $post_id ) ) {
			return $nodes;
		}
		if ( self::seo_plugin_handles_news_article( $post_id ) ) {
			return $nodes;
		}

		$schema = self::build_schema( $post_id );
		if ( ! $schema ) {
			return $nodes;
		}

		// Reuse the Article writer's node transform ( @id, author-as-node, publisher ref ).
		if ( class_exists( 'TWTAEO_Article_Schema_Writer' ) ) {
			return array_merge( $nodes, TWTAEO_Article_Schema_Writer::article_graph_nodes( $schema, $context ) );
		}

		unset( $schema['@context'] );
		$schema['@id'] = ( $context['url'] ?? $schema['url'] ) . '#article';
		return array_merge( $nodes, array( $schema ) );
	}

	// ── Schema builder ────────────────────────────────────────────────────────

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
			'@type'         => 'NewsArticle',
			'headline'      => $headline,
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_post_time( 'c', true, $post_id ),
			'dateModified'  => get_post_modified_time( 'c', true, $post_id ),
		);

		// Article section.
		$section = TWTAEO_News_Meta::get_section( $post_id );
		if ( $section !== '' ) {
			$schema['articleSection'] = $section;
		}

		// Keywords.
		$keywords = TWTAEO_News_Meta::get_keywords( $post_id );
		if ( ! empty( $keywords ) ) {
			$schema['keywords'] = $keywords;
		}

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

		// Language + translation links (site-locale fallback; workTranslation
		// only when a multilingual plugin reports published translations).
		if ( class_exists( 'TWTAEO_Multilingual' ) ) {
			$schema = TWTAEO_Multilingual::decorate_article( $schema, $post_id, 'NewsArticle' );
		}

		// Declared content provenance (empty = human default, nothing emitted).
		if ( class_exists( 'TWTAEO_Content_Provenance' ) ) {
			$source = TWTAEO_Content_Provenance::get_url( $post_id );
			if ( '' !== $source ) {
				$schema['digitalSourceType'] = $source;
			}
		}

		// Author — full Person node from Author Entity if available.
		$author_id   = (int) $post->post_author;
		$author_node = self::build_author_node( $author_id );
		if ( $author_node ) {
			$schema['author'] = $author_node;
		}

		// Publisher — from Company Schema Writer if available.
		$publisher = self::get_publisher_ref();
		if ( $publisher ) {
			$schema['publisher'] = $publisher;
		}

		return $schema;
	}

	// ── Author node ───────────────────────────────────────────────────────────

	/**
	 * Build an embeddable Person author node (no @context) from the Author
	 * Entity system, with a basic WP-user fallback. Shared with the Article
	 * schema writer.
	 *
	 * @param int $user_id
	 * @return array|null
	 */
	public static function build_author_node( $user_id ) {
		if ( ! $user_id ) {
			return null;
		}

		// Use the full Author Entity system when available.
		if ( class_exists( 'TWTAEO_Author_Meta' ) ) {
			$data   = TWTAEO_Author_Meta::get_author_data( $user_id );
			$same_as = TWTAEO_Author_Meta::get_same_as( $user_id );

			if ( empty( $data['name'] ) ) {
				return null;
			}

			$person = array(
				'@type' => 'Person',
				'@id'   => $data['profile_url'] . '#person',
				'name'  => $data['name'],
				'url'   => $data['profile_url'],
			);

			if ( ! empty( $data['job_title'] ) ) {
				$person['jobTitle'] = $data['job_title'];
			}

			if ( ! empty( $same_as ) ) {
				$person['sameAs'] = $same_as;
			}

			return $person;
		}

		// Fallback: basic WP user data.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		return array(
			'@type' => 'Person',
			'name'  => $user->display_name,
			'url'   => get_author_posts_url( $user_id ),
		);
	}

	// ── Publisher ref ─────────────────────────────────────────────────────────

	/**
	 * Build the publisher Organization reference, with a site-info fallback.
	 * Shared with the Article schema writer.
	 *
	 * @return array
	 */
	public static function get_publisher_ref() {
		if ( class_exists( 'TWTAEO_Company_Schema_Writer' ) ) {
			$ref = TWTAEO_Company_Schema_Writer::get_publisher_ref();
			if ( ! empty( $ref ) ) {
				return $ref;
			}
		}

		// Fallback: minimal Organization from site info.
		return array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url(),
		);
	}

	// ── SEO plugin deferral ───────────────────────────────────────────────────

	private static function seo_plugin_handles_news_article( $post_id ) {
		// Rank Math: check if article type is set to NewsArticle.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$article_type = get_post_meta( $post_id, 'rank_math_schema_NewsArticle', true );
			if ( ! empty( $article_type ) ) {
				return true;
			}
		}

		// Yoast: check if article type is explicitly set to NewsArticle.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$article_type = get_post_meta( $post_id, '_yoast_wpseo_schema_article_type', true );
			if ( 'NewsArticle' === $article_type ) {
				return true;
			}
		}

		// SASWP per-post custom schema already carries a NewsArticle/Article here.
		if ( class_exists( 'TWTAEO_SEO_Compatibility' )
			&& ( TWTAEO_SEO_Compatibility::saswp_post_has_type( $post_id, 'NewsArticle' )
				|| TWTAEO_SEO_Compatibility::saswp_post_has_type( $post_id, 'Article' ) ) ) {
			return true;
		}

		return false;
	}
}
