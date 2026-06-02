<?php
/**
 * Reviews Schema Writer
 *
 * Outputs context-aware review schema via wp_head at priority 18.
 * Context is auto-detected from active modules:
 *
 *   local-pack active       → AggregateRating on LocalBusiness
 *   woocommerce + product   → AggregateRating on Product (per-page)
 *   service-detector active → Review + AggregateRating on Service pages
 *   default                 → AggregateRating on Organization
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Reviews_Schema_Writer {

	const OPTION_SETTINGS = 'twtaeo_reviews_settings';

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 18 );
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::get_defaults() );
	}

	public static function save_settings( array $raw ) {
		$settings = array(
			'auto_approve'      => ! empty( $raw['auto_approve'] )      ? '1' : '',
			'require_email'     => ! empty( $raw['require_email'] )     ? '1' : '',
			'show_form'         => ! empty( $raw['show_form'] )         ? '1' : '',
			'context_override'  => in_array( $raw['context_override'] ?? '', array( '', 'local', 'product', 'service', 'general' ), true )
				? sanitize_key( $raw['context_override'] )
				: '',
			'form_title'        => sanitize_text_field( $raw['form_title']    ?? '' ),
			'submit_label'      => sanitize_text_field( $raw['submit_label']  ?? '' ),
			'success_message'   => sanitize_text_field( $raw['success_message'] ?? '' ),
		);
		update_option( self::OPTION_SETTINGS, $settings );
		return $settings;
	}

	private static function get_defaults() {
		return array(
			'auto_approve'     => '',
			'require_email'    => '',
			'show_form'        => '1',
			'context_override' => '',
			'form_title'       => 'Leave a Review',
			'submit_label'     => 'Submit Review',
			'success_message'  => '',
		);
	}

	// ── Context detection ─────────────────────────────────────────────────────

	public static function detect_context() {
		$settings  = self::get_settings();
		$override  = $settings['context_override'] ?? '';
		if ( $override ) {
			return $override;
		}

		$active = get_option( 'twtaeo_active_modules', array() );

		if ( in_array( 'local-pack', $active, true ) ) {
			return 'local';
		}
		if ( class_exists( 'WooCommerce' ) && in_array( 'woocommerce-detector', $active, true ) ) {
			return 'product';
		}
		if ( in_array( 'service-detector', $active, true ) ) {
			return 'service';
		}

		return 'general';
	}

	// ── Schema output ─────────────────────────────────────────────────────────

	public static function output_schema() {
		$context = self::detect_context();
		$schema  = null;

		switch ( $context ) {
			case 'local':
				$schema = self::build_local_schema();
				break;

			case 'product':
				if ( is_singular( 'product' ) ) {
					$schema = self::build_product_schema( get_the_ID() );
				}
				break;

			case 'service':
				if ( is_singular() ) {
					$schema = self::build_service_schema( get_the_ID() );
				}
				break;

			default:
				if ( is_front_page() || is_home() ) {
					$schema = self::build_general_schema();
				}
				break;
		}

		if ( ! $schema ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n" . '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. "\n" . '</script>' . "\n";
	}

	// ── Schema builders ───────────────────────────────────────────────────────

	private static function build_local_schema() {
		$aggregate = TWTAEO_Reviews_CPT::get_aggregate( -1 );
		if ( ! $aggregate ) {
			return null;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'LocalBusiness',
			'name'            => get_bloginfo( 'name' ),
			'url'             => home_url(),
			'aggregateRating' => self::build_aggregate_node( $aggregate ),
		);

		// Pull address from Local Pack settings if available.
		if ( class_exists( 'TWTAEO_Local_Pack' ) ) {
			$lp = TWTAEO_Local_Pack::get_settings();
			if ( ! empty( $lp['street_address'] ) ) {
				$schema['address'] = array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $lp['street_address'] ?? '',
					'addressLocality' => $lp['city']           ?? '',
					'addressRegion'   => $lp['state']          ?? '',
					'postalCode'      => $lp['zip']            ?? '',
					'addressCountry'  => $lp['country']        ?? 'US',
				);
			}
		}

		$reviews = TWTAEO_Reviews_CPT::get_approved( -1, 5 );
		if ( ! empty( $reviews ) ) {
			$schema['review'] = self::build_review_nodes( $reviews );
		}

		return $schema;
	}

	private static function build_product_schema( $post_id ) {
		// Prefer WooCommerce's own review data for product pages.
		$aggregate = TWTAEO_Reviews_CPT::get_wc_aggregate( $post_id );

		// Fall back to our internal reviews scoped to this product.
		if ( ! $aggregate ) {
			$aggregate = TWTAEO_Reviews_CPT::get_aggregate( $post_id );
		}

		if ( ! $aggregate ) {
			return null;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'Product',
			'name'            => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'url'             => get_permalink( $post_id ),
			'aggregateRating' => self::build_aggregate_node( $aggregate ),
		);

		// Individual review nodes from our internal store.
		$reviews = TWTAEO_Reviews_CPT::get_approved( $post_id, 5 );
		if ( ! empty( $reviews ) ) {
			$schema['review'] = self::build_review_nodes( $reviews );
		}

		return $schema;
	}

	private static function build_service_schema( $post_id ) {
		// Only output on pages where a service schema post meta exists or service detector fired.
		$aggregate = TWTAEO_Reviews_CPT::get_aggregate( $post_id );

		// Fall back to site-level reviews if no page-specific ones.
		if ( ! $aggregate ) {
			$aggregate = TWTAEO_Reviews_CPT::get_aggregate( 0 );
		}

		if ( ! $aggregate ) {
			return null;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'Service',
			'name'            => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'url'             => get_permalink( $post_id ),
			'aggregateRating' => self::build_aggregate_node( $aggregate ),
		);

		$reviews = TWTAEO_Reviews_CPT::get_approved( $post_id, 5 );
		if ( empty( $reviews ) ) {
			$reviews = TWTAEO_Reviews_CPT::get_approved( 0, 5 );
		}
		if ( ! empty( $reviews ) ) {
			$schema['review'] = self::build_review_nodes( $reviews );
		}

		return $schema;
	}

	private static function build_general_schema() {
		$aggregate = TWTAEO_Reviews_CPT::get_aggregate( -1 );
		if ( ! $aggregate ) {
			return null;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'Organization',
			'name'            => get_bloginfo( 'name' ),
			'url'             => home_url(),
			'aggregateRating' => self::build_aggregate_node( $aggregate ),
		);

		$reviews = TWTAEO_Reviews_CPT::get_approved( -1, 5 );
		if ( ! empty( $reviews ) ) {
			$schema['review'] = self::build_review_nodes( $reviews );
		}

		return $schema;
	}

	// ── Node builders ─────────────────────────────────────────────────────────

	private static function build_aggregate_node( array $aggregate ) {
		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $aggregate['average'],
			'reviewCount' => $aggregate['count'],
			'bestRating'  => 5,
			'worstRating' => 1,
		);
	}

	private static function build_review_nodes( array $reviews ) {
		$nodes = array();
		foreach ( $reviews as $review ) {
			$rating = (int) get_post_meta( $review->ID, TWTAEO_Reviews_CPT::META_RATING, true );
			$node   = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_the_title( $review->ID ),
				),
				'reviewBody'   => wp_strip_all_tags( $review->post_content ),
				'datePublished' => get_post_time( 'Y-m-d', true, $review->ID ),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => max( 1, min( 5, $rating ) ),
					'bestRating'  => 5,
					'worstRating' => 1,
				),
			);
			$nodes[] = $node;
		}
		return count( $nodes ) === 1 ? $nodes[0] : $nodes;
	}

	// ── Public utility ────────────────────────────────────────────────────────

	public static function get_context_label( $context ) {
		$labels = array(
			'local'   => 'Local Business',
			'product' => 'WooCommerce Product',
			'service' => 'Service Page',
			'general' => 'Organization',
		);
		return $labels[ $context ] ?? ucfirst( $context );
	}
}
