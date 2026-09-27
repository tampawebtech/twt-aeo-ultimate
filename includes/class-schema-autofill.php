<?php
/**
 * Schema Autofill
 *
 * One-run bulk fill for every missing schema type that can be built from data
 * the site already has — post titles, permalinks, author profiles, the company
 * profile, Local Pack business info, detected FAQ pairs, WooCommerce product
 * data. Types that need facts only the owner knows (event dates, review
 * verdicts, a price on a non-WooCommerce page) are never guessed; they are
 * counted and reported by name instead. Guessed data is worse than a named
 * gap.
 *
 * Existing saved schemas are never overwritten.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Schema_Autofill {

	/** Pages per AJAX batch — each changed page is also rescanned, so keep modest. */
	const BATCH = 25;

	/**
	 * Process one batch of scanned pages.
	 *
	 * @param int $offset Offset into the scanned-pages list.
	 * @return array {processed, pages_changed, created, needs_data, done, next_offset, total}
	 */
	public static function run_batch( $offset ) {
		$base_args = array(
			'post_type'      => TWTAEO_Scan_Store::get_scannable_post_types(),
			'post_status'    => 'publish',
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => TWTAEO_Scan_Store::META_LAST_SCAN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'compare' => 'EXISTS',
				),
			),
		);

		$total_query = new WP_Query( array_merge( $base_args, array(
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) ) );
		$total       = (int) $total_query->found_posts;

		$posts = get_posts( array_merge( $base_args, array(
			'posts_per_page' => self::BATCH,
			'offset'         => (int) $offset,
		) ) );

		$created       = array();
		$needs_data    = array();
		$pages_changed = 0;

		foreach ( $posts as $post ) {
			$scan = TWTAEO_Scan_Store::get_scan( $post->ID );
			$eval = $scan['evaluation'] ?? array();

			$candidates = array_unique( array_merge(
				(array) ( $eval['missing'] ?? array() ),
				(array) ( $eval['recommended'] ?? array() )
			) );

			$changed = false;
			foreach ( $candidates as $type ) {
				// Never overwrite something the owner already wrote.
				if ( TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, $type ) ) {
					continue;
				}

				$result = self::fill( $type, $post );
				if ( 'created' === $result ) {
					$created[ $type ] = ( $created[ $type ] ?? 0 ) + 1;
					$changed          = true;
				} elseif ( 'needs_data' === $result ) {
					$needs_data[ $type ] = ( $needs_data[ $type ] ?? 0 ) + 1;
				}
			}

			if ( $changed ) {
				// Refresh missing/present lists and the AEO Score.
				TWTAEO_Scan_Store::scan_and_store( $post );
				$pages_changed++;
			}
		}

		$processed = count( $posts );

		return array(
			'processed'     => $processed,
			'pages_changed' => $pages_changed,
			'created'       => $created,
			'needs_data'    => $needs_data,
			'done'          => ( $offset + $processed ) >= $total || 0 === $processed,
			'next_offset'   => $offset + $processed,
			'total'         => $total,
		);
	}

	/**
	 * Try to build and save one schema type for one post.
	 *
	 * @param string  $type
	 * @param WP_Post $post
	 * @return string 'created' | 'needs_data' | 'skipped'
	 */
	private static function fill( $type, $post ) {
		switch ( $type ) {

			case 'WebPage':
				return self::save_json( $post->ID, $type, array(
					'@context'    => 'https://schema.org',
					'@type'       => 'WebPage',
					'name'        => self::title( $post ),
					'description' => self::description( $post ),
					'url'         => get_permalink( $post ),
				) );

			case 'WebSite':
				return self::save_json( $post->ID, $type, array_filter( array(
					'@context'    => 'https://schema.org',
					'@type'       => 'WebSite',
					'name'        => get_bloginfo( 'name' ),
					'url'         => home_url( '/' ),
					'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
				) ) );

			case 'BreadcrumbList':
				return self::save_json( $post->ID, $type, array(
					'@context'        => 'https://schema.org',
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'twt-aeo-ultimate' ), 'item' => home_url( '/' ) ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => self::title( $post ), 'item' => get_permalink( $post ) ),
					),
				) );

			case 'Organization':
				$schema = self::organization_node();
				return $schema ? self::save_json( $post->ID, $type, $schema ) : 'needs_data';

			case 'Person':
				$author = class_exists( 'TWTAEO_Author_Meta' )
					? TWTAEO_Author_Meta::get_author_data( (int) $post->post_author )
					: array();
				if ( empty( $author['name'] ) ) {
					return 'needs_data';
				}
				$sameas = array_values( array_filter( (array) ( $author['social'] ?? array() ) ) );
				return self::save_json( $post->ID, $type, array_filter( array(
					'@context' => 'https://schema.org',
					'@type'    => 'Person',
					'name'     => $author['name'],
					'jobTitle' => $author['job_title'] ?? '',
					'url'      => $author['url'] ?: ( $author['profile_url'] ?? '' ),
					'sameAs'   => $sameas ?: null,
				) ) );

			case 'Article':
				$author  = class_exists( 'TWTAEO_Author_Meta' )
					? TWTAEO_Author_Meta::get_author_data( (int) $post->post_author )
					: array();
				$company = class_exists( 'TWTAEO_Company_Profile' ) ? TWTAEO_Company_Profile::get() : array();
				$node    = array(
					'@context'      => 'https://schema.org',
					'@type'         => 'Article',
					'headline'      => self::title( $post ),
					'author'        => array( '@type' => 'Person', 'name' => $author['name'] ?? get_the_author_meta( 'display_name', $post->post_author ) ),
					'datePublished' => get_the_date( 'c', $post ),
					'dateModified'  => get_post_modified_time( 'c', true, $post ),
					'url'           => get_permalink( $post ),
					'publisher'     => array_filter( array(
						'@type' => 'Organization',
						'name'  => $company['name'] ?? get_bloginfo( 'name' ),
						'logo'  => ! empty( $company['logo_url'] )
							? array( '@type' => 'ImageObject', 'url' => $company['logo_url'] )
							: null,
					) ),
				);
				$desc = self::description( $post );
				if ( $desc ) {
					$node['description'] = $desc;
				}
				return self::save_json( $post->ID, $type, $node );

			case 'FAQPage':
				if ( ! class_exists( 'TWTAEO_FAQ_Detector' )
					|| ! method_exists( 'TWTAEO_FAQ_Detector', 'extract_qa_pairs' ) ) {
					return 'needs_data';
				}
				$pairs = TWTAEO_FAQ_Detector::extract_qa_pairs( $post );
				if ( empty( $pairs ) ) {
					return 'needs_data'; // No Q&A-shaped content to build from.
				}
				$schema = TWTAEO_FAQ_Detector::build_faqpage_schema( $post->ID, $pairs );
				return self::save_json( $post->ID, $type, $schema );

			case 'Service':
				if ( class_exists( 'TWTAEO_Service_Schema_Writer' )
					&& method_exists( 'TWTAEO_Service_Schema_Writer', 'get_prefill' ) ) {
					$prefill = TWTAEO_Service_Schema_Writer::get_prefill( $post->ID );
					if ( ! empty( $prefill['name'] ) ) {
						TWTAEO_Service_Schema_Writer::save( $post->ID, array(
							'name'          => $prefill['name'],
							'description'   => $prefill['description'] ?? '',
							'service_type'  => $prefill['service_type'] ?? '',
							'area_served'   => $prefill['area_served'] ?? '',
							'provider_name' => $prefill['provider_name'] ?? '',
							'phone'         => $prefill['phone'] ?? '',
						) );
						return 'created';
					}
				}
				// Fall back to a minimal Service node from the page itself.
				return self::save_json( $post->ID, $type, array(
					'@context' => 'https://schema.org',
					'@type'    => 'Service',
					'name'     => self::title( $post ),
					'provider' => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
				) );

			case 'LocalBusiness':
				$lp      = class_exists( 'TWTAEO_Local_Pack' ) ? TWTAEO_Local_Pack::get_settings() : array();
				$company = class_exists( 'TWTAEO_Company_Profile' ) ? TWTAEO_Company_Profile::get() : array();
				$name    = $lp['business_name'] ?? '';
				$name    = $name ?: ( $company['name'] ?? '' );
				$phone   = $lp['phone'] ?? '';
				$phone   = $phone ?: ( $company['phone'] ?? '' );
				$address = self::postal_address_node();
				if ( ! $name || ( ! $phone && ! $address ) ) {
					return 'needs_data'; // A LocalBusiness nobody can reach or find is not one.
				}
				return self::save_json( $post->ID, $type, array_filter( array(
					'@context'  => 'https://schema.org',
					'@type'     => 'LocalBusiness',
					'name'      => $name,
					'url'       => home_url( '/' ),
					'telephone' => $phone ?: null,
					'address'   => $address,
				) ) );

			case 'PostalAddress':
				$address = self::postal_address_node();
				return $address
					? self::save_json( $post->ID, $type, array_merge( array( '@context' => 'https://schema.org' ), $address ) )
					: 'needs_data';

			case 'Product':
				if ( 'product' === $post->post_type
					&& class_exists( 'TWTAEO_WooCommerce_Detector' )
					&& TWTAEO_WooCommerce_Detector::is_woocommerce_active() ) {
					$item        = TWTAEO_WooCommerce_Detector::scan( $post );
					$recommended = $item['recommended_schema'] ?? 'Product';
					$schema      = TWTAEO_Plugin::build_wc_auto_schema( $post, $recommended );
					return $schema ? self::save_json( $post->ID, $recommended, $schema ) : 'needs_data';
				}
				// A price on a non-WooCommerce page is a fact we will not invent.
				return 'needs_data';

			default:
				// Event dates, review verdicts, HowTo steps, … — owner facts.
				return 'needs_data';
		}
	}

	// ── Shared builders ───────────────────────────────────────────────────────

	/**
	 * Organization node from the company profile. Null when there is no name
	 * to anchor it.
	 *
	 * @return array|null
	 */
	private static function organization_node() {
		$company = class_exists( 'TWTAEO_Company_Profile' ) ? TWTAEO_Company_Profile::get() : array();
		$name    = $company['name'] ?? get_bloginfo( 'name' );
		if ( ! $name ) {
			return null;
		}

		$sameas = array();
		foreach ( array( 'social_facebook', 'social_twitter', 'social_linkedin', 'social_instagram', 'social_youtube', 'social_wikipedia', 'social_pinterest' ) as $key ) {
			if ( ! empty( $company[ $key ] ) ) {
				$sameas[] = $company[ $key ];
			}
		}

		// The admin fallback email only ships when it is a publishable address.
		$email    = $company['email'] ?? '';
		$email_ok = $email && ( class_exists( 'TWTAEO_Knowledge_Graph' )
			? TWTAEO_Knowledge_Graph::is_public_email( $email )
			: is_email( $email ) );

		return array_filter( array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Organization',
			'name'        => $name,
			'url'         => $company['url'] ?? home_url( '/' ),
			'logo'        => $company['logo_url'] ?? null,
			'description' => $company['description'] ?? null,
			'telephone'   => $company['phone'] ?? null,
			'email'       => $email_ok ? $email : null,
			'sameAs'      => $sameas ?: null,
		) );
	}

	/**
	 * PostalAddress node from Local Pack business info. Null when no usable
	 * address is on file.
	 *
	 * @return array|null
	 */
	private static function postal_address_node() {
		$lp = class_exists( 'TWTAEO_Local_Pack' ) ? TWTAEO_Local_Pack::get_settings() : array();
		if ( empty( $lp['street_address'] ) && empty( $lp['city'] ) ) {
			return null;
		}
		return array_filter( array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $lp['street_address'] ?? null,
			'addressLocality' => $lp['city'] ?? null,
			'addressRegion'   => $lp['state'] ?? null,
			'postalCode'      => $lp['zip'] ?? null,
			'addressCountry'  => $lp['country'] ?? null,
		) );
	}

	private static function title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Best available short description: meta description (any SEO plugin's or
	 * ours), then the excerpt. Empty string when neither exists.
	 */
	private static function description( $post ) {
		$meta = get_post_meta( $post->ID, '_twtaeo_meta_description', true )
			?: get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			?: get_post_meta( $post->ID, 'rank_math_description', true )
			?: get_post_meta( $post->ID, '_aioseop_description', true )
			?: get_post_meta( $post->ID, 'seopress_titles_desc', true )
			?: '';
		if ( $meta ) {
			return $meta;
		}
		return $post->post_excerpt ? wp_trim_words( $post->post_excerpt, 40 ) : '';
	}

	/**
	 * Save a node via the custom schema writer.
	 *
	 * @return string 'created' on success, 'skipped' on failure.
	 */
	private static function save_json( $post_id, $type, array $schema ) {
		$result = TWTAEO_Custom_Schema_Writer::save(
			$post_id,
			$type,
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		return is_wp_error( $result ) ? 'skipped' : 'created';
	}
}
