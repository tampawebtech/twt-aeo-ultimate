<?php
/**
 * Multilingual Awareness
 *
 * Static helpers that make the schema output language-correct on translated
 * sites. Without a multilingual plugin every method falls back to the
 * site-wide locale, so behaviour on single-language sites is unchanged.
 *
 * Supported: Polylang (pll_* functions) and WPML (wpml_* filters). Detection
 * is per-call and function_exists()-guarded — no hard dependency on either.
 *
 * Why this matters for AEO: a WPML/Polylang site publishes every translation
 * with the default site language in its schema, and nothing links a page to
 * its translations. An AI model reading the French page is told it is the
 * English site — the exact "brand flattening" failure international E-E-A-T
 * work tries to prevent. inLanguage per post + workTranslation links fix both.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Multilingual {

	/**
	 * Which multilingual plugin is active.
	 *
	 * @return string 'polylang' | 'wpml' | ''
	 */
	public static function plugin() {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return 'polylang';
		}
		// WPML core (not just the sitepress-multilingual-cms constant — the
		// filters below need the full stack loaded).
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		return '';
	}

	/**
	 * BCP-47 language tag for a post (e.g. "fr-FR"), falling back to the
	 * site-wide language when no multilingual plugin is active or the post
	 * has no assigned language.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function post_language( $post_id ) {
		$post_id = (int) $post_id;
		$locale  = '';

		switch ( self::plugin() ) {
			case 'polylang':
				$locale = (string) pll_get_post_language( $post_id, 'locale' );
				break;

			case 'wpml':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter, not a hook this plugin defines.
				$details = apply_filters( 'wpml_post_language_details', null, $post_id );
				if ( is_array( $details ) && ! empty( $details['locale'] ) ) {
					$locale = (string) $details['locale'];
				}
				break;
		}

		if ( '' === $locale ) {
			return get_bloginfo( 'language' ); // Already hyphenated (en-US).
		}

		return str_replace( '_', '-', $locale );
	}

	/**
	 * Published translations of a post, excluding the post itself.
	 *
	 * @param int $post_id
	 * @return array[] Each: { post_id: int, url: string, locale: string }
	 */
	public static function post_translations( $post_id ) {
		$post_id = (int) $post_id;
		$ids     = array();

		switch ( self::plugin() ) {
			case 'polylang':
				if ( function_exists( 'pll_get_post_translations' ) ) {
					$ids = array_map( 'intval', (array) pll_get_post_translations( $post_id ) );
				}
				break;

			case 'wpml':
				$type = 'post_' . get_post_type( $post_id );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter, not a hook this plugin defines.
				$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type );
				if ( $trid ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter, not a hook this plugin defines.
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $type );
					foreach ( (array) $translations as $t ) {
						if ( ! empty( $t->element_id ) ) {
							$ids[] = (int) $t->element_id;
						}
					}
				}
				break;
		}

		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( $id === $post_id || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( ! $url ) {
				continue;
			}
			$out[] = array(
				'post_id' => $id,
				'url'     => $url,
				'locale'  => self::post_language( $id ),
			);
		}

		return $out;
	}

	/**
	 * Decorate an Article/NewsArticle schema array with inLanguage and
	 * workTranslation links to its published translations. Safe to call on
	 * single-language sites — inLanguage falls back to the site locale and
	 * workTranslation is simply not added.
	 *
	 * @param array  $schema  The built schema (may be standalone or a graph node).
	 * @param int    $post_id
	 * @param string $type    The @type to use for translation stubs (Article|NewsArticle).
	 * @return array
	 */
	public static function decorate_article( array $schema, $post_id, $type = 'Article' ) {
		$schema['inLanguage'] = self::post_language( $post_id );

		$translations = self::post_translations( $post_id );
		if ( ! empty( $translations ) ) {
			$works = array();
			foreach ( $translations as $t ) {
				// #article matches the @id convention article_graph_nodes() uses,
				// so when the translation's own page publishes its node the two
				// reference the same entity.
				$works[] = array(
					'@type'      => $type,
					'@id'        => $t['url'] . '#article',
					'url'        => $t['url'],
					'inLanguage' => $t['locale'],
				);
			}
			$schema['workTranslation'] = count( $works ) === 1 ? $works[0] : $works;
		}

		return $schema;
	}
}
