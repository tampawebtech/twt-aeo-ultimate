<?php
/**
 * Meta Inventory Scanner
 *
 * A lightweight, read-only scan of which structured-data and Open Graph signals
 * are live on a page. Reuses the existing schema and OG detectors and flattens
 * the result into a compact key-value status map suitable for transmission.
 *
 * Shape:
 *   [
 *     'schema' => [ 'FAQPage' => true, 'Product' => true, 'Article' => true ],
 *     'og'     => [ 'og:title' => true, 'og:image' => false, ... ],
 *   ]
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Meta_Inventory {

	/**
	 * Scan one post for its live schema types and Open Graph tag coverage.
	 *
	 * @param int|WP_Post $post
	 * @return array { schema: array<string,bool>, og: array<string,bool> }  Empty if invalid.
	 */
	public static function scan( $post ) {
		$post = is_int( $post ) ? get_post( $post ) : $post;
		if ( ! $post ) {
			return array();
		}

		return array(
			'schema' => self::schema_map( $post ),
			'og'     => self::og_map( $post ),
		);
	}

	/**
	 * Map of live schema types → true.
	 */
	private static function schema_map( $post ) {
		$plugin = $GLOBALS['twtaeo_plugin'] ?? null;

		$types = ( $plugin && method_exists( $plugin, 'detect_schema_types' ) )
			? (array) $plugin->detect_schema_types( $post )
			: array();

		// Fall back to the last stored scan if the live detector returned nothing.
		if ( empty( $types ) ) {
			$stored = get_post_meta( $post->ID, TWTAEO_Scan_Store::META_SCHEMA, true );
			if ( is_array( $stored ) ) {
				$types = $stored;
			}
		}

		$map = array();
		foreach ( $types as $type ) {
			$type = is_string( $type ) ? trim( $type ) : '';
			if ( $type !== '' ) {
				$map[ $type ] = true;
			}
		}

		return $map;
	}

	/**
	 * Map of Open Graph tags → live (bool).
	 */
	private static function og_map( $post ) {
		$scan = class_exists( 'TWTAEO_OG_Detector' ) ? TWTAEO_OG_Detector::scan( $post ) : array();

		return array(
			'og:title'       => ! empty( $scan['has_og_title'] ),
			'og:description' => ! empty( $scan['has_og_description'] ),
			'og:image'       => ! empty( $scan['has_og_image'] ),
			'og:type'        => ! empty( $scan['has_og_type'] ),
			'og:url'         => ! empty( $scan['has_og_url'] ),
			'og:site_name'   => self::has_site_name( $post ),
		);
	}

	/**
	 * og:site_name is emitted by every major SEO plugin and by our own OG writer
	 * whenever it outputs tags.
	 */
	private static function has_site_name( $post ) {
		if ( class_exists( 'TWTAEO_OG_Writer' ) ) {
			if ( TWTAEO_OG_Writer::seo_plugin_active() ) {
				return true;
			}
			$title = get_post_meta( $post->ID, TWTAEO_OG_Writer::META_TITLE, true );
			$image = get_post_meta( $post->ID, TWTAEO_OG_Writer::META_IMAGE, true );
			return ! empty( $title ) || ! empty( $image );
		}
		return false;
	}
}
