<?php
/**
 * Contact Schema Writer
 *
 * Stores user-generated entity/contact schema in postmeta and outputs it via
 * wp_head as a single @graph block: an Organization (or LocalBusiness) node
 * carrying PostalAddress + ContactPoint, plus a ContactPage node whose
 * mainEntity references that organization by @id.
 *
 * The Organization always uses the site's canonical @id ( home_url() . '/#organization' )
 * so the entity stays consolidated with whatever Yoast / Rank Math / Local Pack
 * already emits — which is exactly what the Contact Detector's "entity
 * consolidation" check looks for.
 *
 * Business defaults are pulled from the central Local Pack profile so the
 * Contact tab and the Local Pack page stay in sync.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Contact_Schema_Writer {

	/**
	 * Postmeta key for the stored schema fields.
	 */
	const META_KEY = '_twtaeo_contact_schema';

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 20 );
	}

	// ── Public API ──────────────────────────────────────────────────────────

	/**
	 * Output the contact @graph JSON-LD on the frontend.
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

		echo "\n<!-- TWT AEO Contact Schema -->\n";
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
		echo "\n" . '</script>' . "\n";
	}

	/**
	 * Save schema fields for a post.
	 *
	 * @param int   $post_id
	 * @param array $fields  Form fields from the modal / top panel.
	 * @return bool
	 */
	public static function save( $post_id, $fields ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$schema = self::build_stored_fields( $fields );
		if ( empty( $schema['name'] ) ) {
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
	 * Return pre-fill data for the modal, merging existing stored values with
	 * the central Local Pack profile and sensible WordPress defaults.
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
		$lp       = class_exists( 'TWTAEO_Local_Pack' ) ? TWTAEO_Local_Pack::get_settings() : array();

		$pick = function ( $key, $lp_key = null ) use ( $existing, $lp ) {
			$lp_key = $lp_key ?: $key;
			if ( is_array( $existing ) && ! empty( $existing[ $key ] ) ) {
				return $existing[ $key ];
			}
			return $lp[ $lp_key ] ?? '';
		};

		return array(
			'post_id'        => $post_id,
			'name'           => $existing['name'] ?? ( $lp['business_name'] ?? get_bloginfo( 'name' ) ),
			'business_type'  => $existing['business_type'] ?? ( $lp['business_type'] ?? 'Organization' ),
			'phone'          => $pick( 'phone' ),
			'email'          => $existing['email'] ?? ( $lp['email'] ?? get_bloginfo( 'admin_email' ) ),
			'contact_type'   => $existing['contact_type'] ?? 'customer service',
			'street_address' => $pick( 'street_address' ),
			'city'           => $pick( 'city' ),
			'state'          => $pick( 'state' ),
			'zip'            => $pick( 'zip' ),
			'country'        => $pick( 'country' ) ?: 'US',
			'has_existing'   => ( $existing !== null ),
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Build the @graph array for wp_head output.
	 *
	 * @param int   $post_id
	 * @param array $stored  Decoded stored schema fields.
	 * @return array
	 */
	private static function build_graph( $post_id, $stored ) {
		$org_id   = home_url() . '/#organization';
		$page_url = get_permalink( $post_id );

		$type = ! empty( $stored['business_type'] ) ? $stored['business_type'] : 'Organization';
		$org  = array(
			'@type' => $type,
			'@id'   => $org_id,
			'name'  => $stored['name'],
			'url'   => home_url( '/' ),
		);

		if ( ! empty( $stored['phone'] ) ) {
			$org['telephone'] = $stored['phone'];
		}
		if ( ! empty( $stored['email'] ) ) {
			$org['email'] = $stored['email'];
		}

		// PostalAddress.
		$addr = array( '@type' => 'PostalAddress' );
		if ( ! empty( $stored['street_address'] ) ) $addr['streetAddress']   = $stored['street_address'];
		if ( ! empty( $stored['city'] ) )           $addr['addressLocality'] = $stored['city'];
		if ( ! empty( $stored['state'] ) )          $addr['addressRegion']   = $stored['state'];
		if ( ! empty( $stored['zip'] ) )            $addr['postalCode']      = $stored['zip'];
		if ( ! empty( $stored['country'] ) )        $addr['addressCountry']  = $stored['country'];
		if ( count( $addr ) > 1 ) {
			$org['address'] = $addr;
		}

		// ContactPoint — needs at least a phone or email to be meaningful.
		if ( ! empty( $stored['phone'] ) || ! empty( $stored['email'] ) ) {
			$cp = array(
				'@type'       => 'ContactPoint',
				'contactType' => ! empty( $stored['contact_type'] ) ? $stored['contact_type'] : 'customer service',
			);
			if ( ! empty( $stored['phone'] ) ) $cp['telephone'] = $stored['phone'];
			if ( ! empty( $stored['email'] ) ) $cp['email']     = $stored['email'];
			$org['contactPoint'] = $cp;
		}

		$contact_page = array(
			'@type'      => 'ContactPage',
			'@id'        => $page_url . '#contactpage',
			'url'        => $page_url,
			'mainEntity' => array( '@id' => $org_id ),
		);

		return array( $org, $contact_page );
	}

	/**
	 * Build the sanitized fields array to store in postmeta.
	 *
	 * @param array $fields
	 * @return array
	 */
	private static function build_stored_fields( $fields ) {
		return array(
			'name'           => sanitize_text_field( $fields['name']           ?? '' ),
			'business_type'  => sanitize_text_field( $fields['business_type']  ?? 'Organization' ),
			'phone'          => sanitize_text_field( $fields['phone']          ?? '' ),
			'email'          => sanitize_email(      $fields['email']          ?? '' ),
			'contact_type'   => sanitize_text_field( $fields['contact_type']   ?? 'customer service' ),
			'street_address' => sanitize_text_field( $fields['street_address'] ?? '' ),
			'city'           => sanitize_text_field( $fields['city']           ?? '' ),
			'state'          => sanitize_text_field( $fields['state']          ?? '' ),
			'zip'            => sanitize_text_field( $fields['zip']            ?? '' ),
			'country'        => sanitize_text_field( $fields['country']        ?? '' ),
		);
	}
}
