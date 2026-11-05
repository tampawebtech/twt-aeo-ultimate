<?php
/**
 * Company Profile
 *
 * Site-level Publisher entity: name, logo, social profiles, Facebook App ID.
 * Syncs from Yoast / Rank Math when those plugins are active so users don't
 * have to start from scratch.
 *
 * Option key: twtaeo_company
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Company_Profile {

	const OPTION_KEY = 'twtaeo_company';
	const NONCE_SAVE = 'twtaeo_company_save';
	const NONCE_SYNC = 'twtaeo_company_sync';

	// ── Defaults ──────────────────────────────────────────────────────────────

	public static function defaults() {
		return array(
			// Core identity.
			'name'          => get_bloginfo( 'name' ),
			'legal_name'    => '',
			'logo_url'      => '',
			'logo_id'       => 0,
			'url'           => home_url(),
			'description'   => get_bloginfo( 'description' ),
			'founding_year' => '',
			'email'         => get_option( 'admin_email', '' ),
			'phone'         => '',
			// Company licenses (feeds Organization hasCredential).
			'licenses'      => array(),
			// Social profiles (feeds sameAs + article:publisher).
			'social_facebook'  => '',
			'social_twitter'   => '',
			'social_linkedin'  => '',
			'social_instagram' => '',
			'social_youtube'   => '',
			'social_wikipedia' => '',
			'social_pinterest' => '',
			'social_google'    => '',
			// Meta Business.
			'fb_app_id'     => '',
			// Output toggles.
			'output_org_schema'        => 1,
			'output_article_publisher' => 1,
			'output_fb_app_id'         => 1,
		);
	}

	// ── Data access ───────────────────────────────────────────────────────────

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function save( array $raw ) {
		$social_keys = array(
			'social_facebook', 'social_twitter', 'social_linkedin',
			'social_instagram', 'social_youtube', 'social_wikipedia', 'social_pinterest',
			'social_google',
		);

		$data = array(
			'name'          => sanitize_text_field( $raw['name'] ?? '' ),
			'legal_name'    => sanitize_text_field( $raw['legal_name'] ?? '' ),
			'logo_url'      => esc_url_raw( $raw['logo_url'] ?? '' ),
			'logo_id'       => (int) ( $raw['logo_id'] ?? 0 ),
			'url'           => esc_url_raw( $raw['url'] ?? '' ),
			'description'   => sanitize_textarea_field( $raw['description'] ?? '' ),
			'founding_year' => sanitize_text_field( $raw['founding_year'] ?? '' ),
			'email'         => sanitize_email( $raw['email'] ?? '' ),
			'phone'         => sanitize_text_field( $raw['phone'] ?? '' ),
			// Other callers save only the fields they know, so a missing key keeps the list.
			'licenses'      => isset( $raw['licenses'] ) ? self::sanitize_licenses( $raw['licenses'] ) : self::get()['licenses'],
			'fb_app_id'     => preg_replace( '/[^0-9]/', '', $raw['fb_app_id'] ?? '' ),
			// Output toggles.
			'output_org_schema'        => ! empty( $raw['output_org_schema'] ) ? 1 : 0,
			'output_article_publisher' => ! empty( $raw['output_article_publisher'] ) ? 1 : 0,
			'output_fb_app_id'         => ! empty( $raw['output_fb_app_id'] ) ? 1 : 0,
		);

		foreach ( $social_keys as $key ) {
			$data[ $key ] = esc_url_raw( $raw[ $key ] ?? '' );
		}

		update_option( self::OPTION_KEY, $data );
		return $data;
	}

	// ── sameAs builder ────────────────────────────────────────────────────────

	/**
	 * Clean the license rows from the form. A row needs a name or a number.
	 *
	 * @param mixed $rows Rows of name, number, issuer, url, expires.
	 * @return array
	 */
	private static function sanitize_licenses( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$license = array(
				'name'    => sanitize_text_field( $row['name'] ?? '' ),
				'number'  => sanitize_text_field( $row['number'] ?? '' ),
				'issuer'  => sanitize_text_field( $row['issuer'] ?? '' ),
				'url'     => esc_url_raw( $row['url'] ?? '' ),
				'expires' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $row['expires'] ?? '' ) ) ? $row['expires'] : '',
			);
			if ( '' !== $license['name'] || '' !== $license['number'] ) {
				$out[] = $license;
			}
		}
		return $out;
	}

	/**
	 * The company's licenses as schema.org credentials, for Organization hasCredential.
	 *
	 * @param array|null $company Company profile; the saved one when null.
	 * @return array
	 */
	public static function license_credentials( ?array $company = null ) {
		$c   = $company ?? self::get();
		$out = array();
		foreach ( (array) ( $c['licenses'] ?? array() ) as $license ) {
			$credential = array(
				'@type'              => 'EducationalOccupationalCredential',
				'name'               => ! empty( $license['name'] ) ? $license['name'] : 'Professional License',
				'credentialCategory' => 'Professional License',
			);
			if ( ! empty( $license['issuer'] ) ) {
				$credential['recognizedBy'] = array(
					'@type' => 'Organization',
					'name'  => $license['issuer'],
				);
			}
			if ( ! empty( $license['number'] ) ) {
				$credential['identifier'] = $license['number'];
			}
			if ( ! empty( $license['url'] ) ) {
				$credential['url'] = $license['url'];
			}
			if ( ! empty( $license['expires'] ) ) {
				$credential['expires'] = $license['expires'];
			}
			$out[] = $credential;
		}
		return $out;
	}

	public static function get_same_as( ?array $company = null ) {
		$c    = $company ?? self::get();
		$keys = array(
			'social_facebook', 'social_twitter', 'social_linkedin',
			'social_instagram', 'social_youtube', 'social_wikipedia', 'social_pinterest',
			'social_google',
		);
		$urls = array();
		foreach ( $keys as $key ) {
			if ( ! empty( $c[ $key ] ) ) {
				$urls[] = $c[ $key ];
			}
		}
		return $urls;
	}

	// ── SEO plugin detection & sync ───────────────────────────────────────────

	public static function detect_source() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return null;
	}

	/**
	 * Read company/publisher data from the active SEO plugin.
	 * Returns a partial array — only keys that have non-empty values.
	 */
	public static function read_from_seo_plugin() {
		$source = self::detect_source();
		$data   = array();

		if ( 'yoast' === $source ) {
			$social = get_option( 'wpseo_social', array() );
			$titles = get_option( 'wpseo_titles', array() );

			if ( ! empty( $titles['company_name'] ) ) {
				$data['name'] = $titles['company_name'];
			}
			if ( ! empty( $titles['company_logo'] ) ) {
				$data['logo_url'] = $titles['company_logo'];
			}
			if ( ! empty( $titles['company_logo_id'] ) ) {
				$data['logo_id'] = (int) $titles['company_logo_id'];
			}

			$yoast_map = array(
				'facebook_site' => 'social_facebook',
				'instagram_url' => 'social_instagram',
				'linkedin_url'  => 'social_linkedin',
				'youtube_url'   => 'social_youtube',
				'wikipedia_url' => 'social_wikipedia',
				'pinterest_url' => 'social_pinterest',
			);
			foreach ( $yoast_map as $yoast_key => $our_key ) {
				if ( ! empty( $social[ $yoast_key ] ) ) {
					$data[ $our_key ] = $social[ $yoast_key ];
				}
			}

			// Yoast stores twitter as a handle, not a URL.
			if ( ! empty( $social['twitter_site'] ) ) {
				$handle = ltrim( $social['twitter_site'], '@' );
				$data['social_twitter'] = 'https://x.com/' . $handle;
			}

			// Facebook App ID — Yoast stores under facebook_app_id in wpseo_social.
			if ( ! empty( $social['facebook_app_id'] ) ) {
				$data['fb_app_id'] = $social['facebook_app_id'];
			} elseif ( ! empty( $social['fbadminapp'] ) ) {
				$data['fb_app_id'] = $social['fbadminapp'];
			}
		}

		if ( 'rankmath' === $source ) {
			$general = get_option( 'rank_math_general', array() );

			if ( ! empty( $general['knowledgegraph_name'] ) ) {
				$data['name'] = $general['knowledgegraph_name'];
			}
			if ( ! empty( $general['knowledgegraph_logo'] ) ) {
				$data['logo_url'] = $general['knowledgegraph_logo'];
			}

			$rm_map = array(
				'social_url_facebook'  => 'social_facebook',
				'social_url_twitter'   => 'social_twitter',
				'social_url_instagram' => 'social_instagram',
				'social_url_linkedin'  => 'social_linkedin',
				'social_url_youtube'   => 'social_youtube',
				'social_url_wikipedia' => 'social_wikipedia',
				'social_url_pinterest' => 'social_pinterest',
			);
			foreach ( $rm_map as $rm_key => $our_key ) {
				if ( ! empty( $general[ $rm_key ] ) ) {
					$data[ $our_key ] = $general[ $rm_key ];
				}
			}
		}

		return $data;
	}

	/**
	 * Merge SEO plugin data into saved company data, only filling empty fields.
	 */
	public static function sync_from_seo_plugin() {
		$current = get_option( self::OPTION_KEY, array() );
		$sourced = self::read_from_seo_plugin();

		foreach ( $sourced as $key => $value ) {
			if ( empty( $current[ $key ] ) ) {
				$current[ $key ] = $value;
			}
		}

		update_option( self::OPTION_KEY, $current );
		return wp_parse_args( $current, self::defaults() );
	}

	/**
	 * Whether the active SEO plugin is configured as "company" knowledge graph.
	 * When true, we defer Organization schema output to that plugin.
	 */
	public static function seo_plugin_owns_org_schema() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$titles = get_option( 'wpseo_titles', array() );
			return ( $titles['company_or_person'] ?? '' ) === 'company';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			// Rank Math's own option name for its Titles & Meta settings.
			$titles = get_option( 'rank-math-options-titles', array() );
			return ( $titles['knowledgegraph_type'] ?? '' ) === 'company';
		}

		return false;
	}
}
