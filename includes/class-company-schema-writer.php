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
		// When the Knowledge Graph module is active it is the identity source and
		// emits the Organization inside its unified @graph — defer to avoid a
		// duplicate Organization node.
		if ( class_exists( 'TWTAEO_Knowledge_Graph' ) && TWTAEO_Knowledge_Graph::is_folding() ) {
			return;
		}

		// Switched off on the Schema Conflicts screen. This is the merchant saying so
		// directly, so no replacement check applies — unlike the hand-over below.
		if ( ! TWTAEO_Output_Control::enabled( 'schema_identity' ) ) {
			return;
		}

		// Handed to AEO Ultimate for WooCommerce — but only stand down once that
		// plugin is confirmed to be publishing an Organization at the shared @id on
		// *this* request.
		//
		// ⚠️ The second test is not belt-and-braces. This block emits precisely when
		// no graph is folding, and the other plugin's own answer is empty in that
		// state too, so a choice-only check would delete the store's Organization on
		// exactly the pages where nothing replaces it.
		if ( TWTAEO_Commerce_Handoff::stands_down( 'schema_identity' )
			&& TWTAEO_Commerce_Handoff::superseded( home_url( '/#organization' ) ) ) {
			return;
		}

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
		// JSON-LD output. esc_html() would corrupt the JSON, so safety comes from the
		// HEX_* flags: <, >, &, ' and " are all encoded as \uXXXX, so the value cannot
		// break out of the script element or carry HTML/JS into the page.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-inert JSON-LD; see note above.
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
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
