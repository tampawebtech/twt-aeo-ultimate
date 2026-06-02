<?php
/**
 * Company Schema Writer
 *
 * Outputs Organization JSON-LD schema. Defers to Yoast / Rank Math when
 * either plugin is already configured as the site's knowledge graph entity
 * to prevent duplicate Organization blocks.
 *
 * Also exposes get_publisher_ref() for use by other schema writers (e.g.
 * Article schema) that need to embed a publisher reference.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Company_Schema_Writer {

	public static function register_hooks() {
		// Priority 5: after wp_head opens but before Yoast (10) and author schema (20).
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 5 );
	}

	public static function output_schema() {
		$company = TWTAEO_Company_Profile::get();

		if ( empty( $company['output_org_schema'] ) ) {
			return;
		}

		// Defer if the active SEO plugin already handles Organization schema.
		if ( TWTAEO_Company_Profile::seo_plugin_owns_org_schema() ) {
			return;
		}

		if ( empty( $company['name'] ) ) {
			return;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $company['name'],
			'url'      => $company['url'] ?: home_url(),
		);

		if ( ! empty( $company['legal_name'] ) ) {
			$schema['legalName'] = $company['legal_name'];
		}
		if ( ! empty( $company['description'] ) ) {
			$schema['description'] = $company['description'];
		}
		if ( ! empty( $company['founding_year'] ) ) {
			$schema['foundingDate'] = $company['founding_year'];
		}
		if ( ! empty( $company['email'] ) ) {
			$schema['email'] = $company['email'];
		}
		if ( ! empty( $company['phone'] ) ) {
			$schema['telephone'] = $company['phone'];
		}
		if ( ! empty( $company['logo_url'] ) ) {
			$schema['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $company['logo_url'],
			);
		}

		$same_as = TWTAEO_Company_Profile::get_same_as( $company );
		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		echo "\n<!-- TWT AEO Organization Schema -->\n";
		echo '<script type="application/ld+json">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG );
		echo "\n</script>\n";
	}

	/**
	 * Return a compact Organization reference array for embedding inside other
	 * schema blocks (e.g. Article.publisher). Returns null when no company name
	 * is configured.
	 */
	public static function get_publisher_ref() {
		$company = TWTAEO_Company_Profile::get();

		if ( empty( $company['name'] ) ) {
			return null;
		}

		$ref = array(
			'@type' => 'Organization',
			'name'  => $company['name'],
			'url'   => $company['url'] ?: home_url(),
		);

		if ( ! empty( $company['logo_url'] ) ) {
			$ref['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $company['logo_url'],
			);
		}

		return $ref;
	}
}
