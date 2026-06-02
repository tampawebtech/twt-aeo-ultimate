<?php
/**
 * Custom Schema Writer
 *
 * Stores and outputs custom JSON-LD schemas created via the dashboard modal.
 * Schemas are stored per-post in postmeta, keyed by @type.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Custom_Schema_Writer {

	const META_KEY    = '_twtaeo_custom_schemas';
	const NONCE_ACTION = 'twtaeo_custom_schema_nonce';

	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schemas' ), 25 );
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	public static function get_all( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function get_by_type( $post_id, $type ) {
		$all = self::get_all( $post_id );
		return $all[ $type ] ?? null;
	}

	public static function save( $post_id, $type, $json_string ) {
		$decoded = json_decode( $json_string, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
		}
		$all         = self::get_all( $post_id );
		$all[ $type ] = $decoded;
		return update_post_meta( $post_id, self::META_KEY, wp_json_encode( $all ) );
	}

	public static function delete( $post_id, $type ) {
		$all = self::get_all( $post_id );
		unset( $all[ $type ] );
		if ( empty( $all ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, wp_json_encode( $all ) );
		}
	}

	// ── Frontend output ───────────────────────────────────────────────────────

	public static function output_schemas() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_the_ID();
		$all     = self::get_all( $post_id );
		foreach ( $all as $schema ) {
			if ( empty( $schema ) ) {
				continue;
			}
			if ( ! isset( $schema['@context'] ) ) {
				$schema['@context'] = 'https://schema.org';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- TWT AEO Custom Schema -->\n"
				. '<script type="application/ld+json">' . "\n"
				. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
				. "\n" . '</script>' . "\n";
		}
	}

	// ── Templates ─────────────────────────────────────────────────────────────

	public static function get_template( $type, $post_id = 0 ) {
		$post       = $post_id ? get_post( $post_id ) : null;
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();
		$post_url   = $post ? get_permalink( $post ) : $site_url;
		$post_title = $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '';
		$post_date     = $post ? get_the_date( 'c', $post ) : gmdate( 'c' );
		$post_modified = $post ? get_post_modified_time( 'c', true, $post ) : gmdate( 'c' );
		$tagline    = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );

		$map = array(

			'Person' => array(
				'@context' => 'https://schema.org',
				'@type'    => 'Person',
				'name'     => 'Author Full Name',
				'url'      => $post_url,
				'jobTitle' => 'Your Job Title',
				'email'    => 'email@example.com',
				'sameAs'   => array(
					'https://linkedin.com/in/yourname',
					'https://twitter.com/yourhandle',
				),
			),

			'Article' => array(
				'@context'      => 'https://schema.org',
				'@type'         => 'Article',
				'headline'      => $post_title ?: 'Article Headline',
				'author'        => array( '@type' => 'Person', 'name' => 'Author Name' ),
				'datePublished' => $post_date,
				'dateModified'  => $post_modified,
				'publisher'     => array(
					'@type' => 'Organization',
					'name'  => $site_name,
					'logo'  => array( '@type' => 'ImageObject', 'url' => $site_url . '/logo.png' ),
				),
				'description'   => $post_title ? 'Description of ' . $post_title : 'Article description',
				'url'           => $post_url,
			),

			'Organization' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Organization',
				'name'        => $site_name,
				'url'         => $site_url,
				'logo'        => $site_url . '/logo.png',
				'description' => $tagline ?: 'Organization description',
				'telephone'   => '+1-555-555-5555',
				'address'     => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => '123 Main St',
					'addressLocality' => 'City',
					'addressRegion'   => 'State',
					'postalCode'      => '12345',
					'addressCountry'  => 'US',
				),
				'sameAs'      => array(
					'https://facebook.com/yourpage',
					'https://linkedin.com/company/yourcompany',
				),
			),

			'LocalBusiness' => array(
				'@context'     => 'https://schema.org',
				'@type'        => 'LocalBusiness',
				'name'         => $site_name,
				'url'          => $site_url,
				'telephone'    => '+1-555-555-5555',
				'address'      => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => '123 Main St',
					'addressLocality' => 'City',
					'addressRegion'   => 'State',
					'postalCode'      => '12345',
					'addressCountry'  => 'US',
				),
				'openingHours' => 'Mo-Fr 09:00-17:00',
				'priceRange'   => '$$',
				'description'  => $tagline ?: 'Business description',
			),

			'FAQPage' => array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type'          => 'Question',
						'name'           => 'Question 1?',
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Answer to question 1.' ),
					),
					array(
						'@type'          => 'Question',
						'name'           => 'Question 2?',
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Answer to question 2.' ),
					),
				),
			),

			'Service' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Service',
				'name'        => $post_title ?: 'Service Name',
				'description' => 'Service description',
				'provider'    => array( '@type' => 'Organization', 'name' => $site_name ),
				'areaServed'  => 'City, State',
				'serviceType' => 'Type of Service',
			),

			'Product' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Product',
				'name'        => $post_title ?: 'Product Name',
				'description' => 'Product description',
				'image'       => $site_url . '/product.jpg',
				'brand'       => array( '@type' => 'Brand', 'name' => $site_name ),
				'offers'      => array(
					'@type'         => 'Offer',
					'price'         => '99.99',
					'priceCurrency' => 'USD',
					'availability'  => 'https://schema.org/InStock',
				),
			),

			'WebPage' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'WebPage',
				'name'        => $post_title ?: 'Page Title',
				'description' => 'Page description',
				'url'         => $post_url,
			),

			'BreadcrumbList' => array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site_url ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => $post_title ?: 'Page', 'item' => $post_url ),
				),
			),

			'Event' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Event',
				'name'        => $post_title ?: 'Event Name',
				'startDate'   => $post_date,
				'endDate'     => $post_date,
				'location'    => array(
					'@type'   => 'Place',
					'name'    => 'Venue Name',
					'address' => array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => '123 Main St',
						'addressLocality' => 'City',
						'addressRegion'   => 'State',
						'addressCountry'  => 'US',
					),
				),
				'description' => 'Event description',
				'organizer'   => array( '@type' => 'Organization', 'name' => $site_name ),
			),
		);

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}

		return array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);
	}
}
