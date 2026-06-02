<?php
/**
 * Service Schema Writer
 *
 * Stores user-generated Service schema in postmeta and outputs it via wp_head
 * as a single @graph block using Linked Data (Knowledge Graph) architecture.
 *
 * The Service node always references the site's canonical Organization @id
 * rather than embedding a nested object, so search engines can join the graph
 * across script blocks from Yoast, Rank Math, and our own output.
 *
 * Provider @id detection priority:
 *   1. TWT Custom Schema Writer — saved Organization/LocalBusiness on this page or homepage
 *   2. Rank Math — reads knowledgegraph_type / knowledgegraph_name from options
 *   3. Yoast SEO — uses their standard /#organization @id convention
 *   4. Fallback — no external entity found; a minimal Organization node is added
 *                 to our own @graph so the reference is never dangling
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Service_Schema_Writer {

	/**
	 * Postmeta key for the stored schema fields.
	 */
	const META_KEY = '_twtaeo_service_schema';

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 20 );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Output the Service @graph JSON-LD on the frontend.
	 * Provider detection runs at output time so the reference is always
	 * based on whichever SEO plugin is currently active.
	 */
	public static function output_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$stored = self::get( $post_id );
		if ( ! $stored ) {
			return;
		}

		$graph = self::build_graph( $post_id, $stored );
		if ( empty( $graph ) ) {
			return;
		}

		$output = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo "\n<!-- TWT AEO Service Schema -->\n";
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
		echo "\n" . '</script>' . "\n";
	}

	/**
	 * Save schema fields for a post.
	 * We store only the service-specific fields; provider detection happens
	 * fresh at output time via detect_provider().
	 *
	 * @param int   $post_id
	 * @param array $fields  Form fields from the modal.
	 * @return bool
	 */
	public static function save( $post_id, $fields ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$schema = self::build_stored_fields( $post_id, $fields );
		if ( empty( $schema ) ) {
			return false;
		}

		update_post_meta(
			$post_id,
			self::META_KEY,
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		return true;
	}

	/**
	 * Delete stored schema for a post.
	 *
	 * @param int $post_id
	 */
	public static function delete( $post_id ) {
		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Return stored schema decoded as an array, or null if nothing saved.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $raw ) ) {
			return null;
		}
		return json_decode( $raw, true );
	}

	/**
	 * Return pre-fill data for the modal, merging existing stored values
	 * with live defaults from the active SEO plugin and WP author data.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_prefill( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$existing = self::get( $post_id );
		$provider = self::detect_provider( $post_id );

		// Phone: prefer what was saved, then Rank Math global setting.
		$phone = $existing['_provider_phone'] ?? '';
		if ( ! $phone && defined( 'RANK_MATH_VERSION' ) ) {
			$rm    = get_option( 'rank_math_titles', array() );
			$phone = $rm['phone'] ?? '';
		}

		// Provider name: handle both new (_provider_name) and old (provider.name) storage.
		$provider_name = $existing['_provider_name']
			?? $existing['provider']['name']
			?? $provider['name'];

		return array(
			'post_id'         => $post_id,
			'name'            => $existing['name']        ?? get_the_title( $post ),
			'description'     => $existing['description'] ?? wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '' ),
			'service_type'    => $existing['serviceType'] ?? '',
			'area_served'     => $existing['areaServed']  ?? '',
			'provider_name'   => $provider_name,
			'phone'           => $phone,
			'provider_id'     => $provider['id'],
			'provider_source' => $provider['source'],
			'has_existing'    => ( $existing !== null ),
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Build the @graph array for wp_head output.
	 *
	 * When no external plugin owns an Organization entity, a minimal one is
	 * included here so the Service provider reference is never dangling.
	 *
	 * @param int   $post_id
	 * @param array $stored  Decoded stored schema fields.
	 * @return array
	 */
	private static function build_graph( $post_id, $stored ) {
		$provider = self::detect_provider( $post_id );
		$page_url = get_permalink( $post_id );
		$graph    = array();

		// Include a minimal Organization node only when no external plugin owns one.
		if ( $provider['source'] === 'fallback' ) {
			$org = array(
				'@type' => 'Organization',
				'@id'   => $provider['id'],
				'name'  => $provider['name'],
				'url'   => home_url( '/' ),
			);
			if ( ! empty( $provider['phone'] ) ) {
				$org['telephone'] = $provider['phone'];
			}
			$graph[] = $org;
		}

		// Service node — reference provider by @id, link back to page via mainEntityOfPage.
		$service = array(
			'@type'            => 'Service',
			'@id'              => $page_url . '#service',
			'url'              => $page_url,
			'mainEntityOfPage' => array( '@id' => $page_url ),
			'provider'         => array( '@id' => $provider['id'] ),
		);

		foreach ( array( 'name', 'description', 'serviceType', 'areaServed' ) as $key ) {
			if ( ! empty( $stored[ $key ] ) ) {
				$service[ $key ] = $stored[ $key ];
			}
		}

		$graph[] = $service;
		return $graph;
	}

	/**
	 * Build the fields array to store in postmeta.
	 *
	 * We do NOT bake the provider @id into storage — detect_provider() runs
	 * fresh at output time, so switching SEO plugins automatically updates
	 * the reference without needing to re-save every schema.
	 *
	 * @param int   $post_id
	 * @param array $fields
	 * @return array
	 */
	private static function build_stored_fields( $post_id, $fields ) {
		$url    = get_permalink( $post_id );
		$schema = array(
			'@type' => 'Service',
			'@id'   => $url . '#service',
			'url'   => esc_url_raw( $url ),
		);

		if ( ! empty( $fields['name'] ) ) {
			$schema['name'] = sanitize_text_field( $fields['name'] );
		}
		if ( ! empty( $fields['description'] ) ) {
			$schema['description'] = sanitize_textarea_field( $fields['description'] );
		}
		if ( ! empty( $fields['service_type'] ) ) {
			$schema['serviceType'] = sanitize_text_field( $fields['service_type'] );
		}
		if ( ! empty( $fields['area_served'] ) ) {
			$schema['areaServed'] = sanitize_text_field( $fields['area_served'] );
		}

		// Keep provider name/phone for fallback Organization node — not baked into the graph.
		$schema['_provider_name'] = ! empty( $fields['provider_name'] )
			? sanitize_text_field( $fields['provider_name'] )
			: get_bloginfo( 'name' );

		if ( ! empty( $fields['phone'] ) ) {
			$schema['_provider_phone'] = sanitize_text_field( $fields['phone'] );
		}

		return $schema;
	}

	/**
	 * Detect the canonical Organization @id to use as the Service provider reference.
	 *
	 * Returns an array with:
	 *   id     — the @id string to use in provider: { @id: ... }
	 *   source — 'twt' | 'rank_math' | 'yoast' | 'fallback'
	 *   name   — business name for fallback Org node
	 *   phone  — phone for fallback Org node (may be empty)
	 *
	 * @param int $post_id
	 * @return array
	 */
	private static function detect_provider( $post_id ) {
		$org_types  = array( 'Organization', 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness' );
		$default_id = home_url() . '/#organization';

		// ── 1. TWT Custom Schema Writer ────────────────────────────────────────
		// Check the current page, then the homepage, for a saved Org/LocalBusiness.
		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' ) ) {
			$candidates = array_unique( array_filter( array(
				(int) $post_id,
				(int) get_option( 'page_on_front' ),
			) ) );

			foreach ( $candidates as $pid ) {
				foreach ( $org_types as $type ) {
					$saved = TWTAEO_Custom_Schema_Writer::get_by_type( $pid, $type );
					if ( $saved ) {
						return array(
							'id'     => $saved['@id'] ?? $default_id,
							'source' => 'twt',
							'name'   => $saved['name'] ?? get_bloginfo( 'name' ),
							'phone'  => $saved['telephone'] ?? '',
						);
					}
				}
			}
		}

		// ── 2. Rank Math ───────────────────────────────────────────────────────
		// Rank Math uses home_url() . '/#organization' or '/#person'.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm      = get_option( 'rank_math_titles', array() );
			$kg_type = $rm['knowledgegraph_type'] ?? 'organization';
			$frag    = ( $kg_type === 'person' ) ? '/#person' : '/#organization';

			return array(
				'id'     => home_url() . $frag,
				'source' => 'rank_math',
				'name'   => $rm['knowledgegraph_name'] ?? get_bloginfo( 'name' ),
				'phone'  => $rm['phone'] ?? '',
			);
		}

		// ── 3. Yoast SEO ───────────────────────────────────────────────────────
		// Yoast's canonical Organization @id is home_url() . '/#organization'.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_titles', array() );

			return array(
				'id'     => $default_id,
				'source' => 'yoast',
				'name'   => $wpseo['company_name'] ?? get_bloginfo( 'name' ),
				'phone'  => '',
			);
		}

		// ── 4. Fallback ────────────────────────────────────────────────────────
		// No external plugin owns an Organization entity. build_graph() will add
		// a minimal Organization node to our @graph so the reference is valid.
		$stored = self::get( $post_id );

		return array(
			'id'     => $default_id,
			'source' => 'fallback',
			'name'   => $stored['_provider_name'] ?? get_bloginfo( 'name' ),
			'phone'  => $stored['_provider_phone'] ?? '',
		);
	}
}
