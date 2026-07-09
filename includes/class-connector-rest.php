<?php
/**
 * Connector REST API
 *
 * Inbound command surface for the TWT Agency hub — implements the
 * twt-connector/v1 contract documented in the hub's Connector API class.
 * This is what makes the "Detect & Correct" Slack buttons work: the hub
 * POSTs back to the client site to trigger fixes remotely.
 *
 * Authentication: the X-TWT-API-Key header must match the same pro key this
 * site uses when pushing snapshots to the hub (resolved via the Key Resolver,
 * so wp-config constants and env vars are honoured). The routes only exist
 * while the Agency connection is configured and enabled.
 *
 * Implemented routes:
 *   POST /twt-connector/v1/indexnow      → force IndexNow submission
 *   GET  /twt-connector/v1/schema-check  → live noindex/robots/schema inspection
 *   POST /twt-connector/v1/remediate     → apply an approved AI remediation
 *                                          instruction set to one post
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Connector_Rest {

	const ROUTE_NAMESPACE = 'twt-connector/v1';
	const MAX_URLS        = 100;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// No Agency connection — the command surface shouldn't exist at all.
		if ( ! class_exists( 'TWTAEO_Pro_Transmitter' ) || ! TWTAEO_Pro_Transmitter::is_connected() ) {
			return;
		}

		register_rest_route( self::ROUTE_NAMESPACE, '/indexnow', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_indexnow' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );

		register_rest_route( self::ROUTE_NAMESPACE, '/schema-check', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_schema_check' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
			'args'                => array(
				'url' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( self::ROUTE_NAMESPACE, '/remediate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_remediate' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );
	}

	/**
	 * Constant-time comparison of X-TWT-API-Key against the configured pro key.
	 */
	public static function check_api_key( WP_REST_Request $request ) {
		$given = (string) $request->get_header( 'X-TWT-API-Key' );
		$key   = TWTAEO_Key_Resolver::get( 'pro_key' );

		if ( $key === '' || $given === '' || ! hash_equals( $key, $given ) ) {
			return new WP_Error( 'rest_forbidden', 'Invalid connector API key.', array( 'status' => 403 ) );
		}

		return true;
	}

	// ── POST /indexnow ────────────────────────────────────────────────────────

	/**
	 * Force-submit the given URLs to IndexNow. Only URLs on this site's host
	 * are accepted.
	 */
	public static function handle_indexnow( WP_REST_Request $request ) {
		$urls = array_filter( array_map( 'esc_url_raw', (array) $request->get_param( 'urls' ) ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$urls = array_values( array_filter( $urls, function ( $url ) use ( $host ) {
			return wp_parse_url( $url, PHP_URL_HOST ) === $host;
		} ) );

		if ( empty( $urls ) ) {
			return new WP_Error( 'no_urls', 'No valid URLs for this site provided.', array( 'status' => 400 ) );
		}

		$urls    = array_slice( $urls, 0, self::MAX_URLS );
		$results = TWTAEO_Index_Now::submit( $urls );

		return rest_ensure_response( array(
			'ok'        => true,
			'submitted' => count( $urls ),
			'results'   => $results,
		) );
	}

	// ── GET /schema-check ─────────────────────────────────────────────────────

	/**
	 * Live inspection of one page on this site: noindex signals, robots.txt
	 * blocking, JSON-LD schema types, and duplicate-type conflicts.
	 */
	public static function handle_schema_check( WP_REST_Request $request ) {
		$url  = esc_url_raw( (string) $request->get_param( 'url' ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Same-host only — this endpoint must never become an SSRF proxy.
		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return new WP_Error( 'bad_url', 'URL must belong to this site.', array( 'status' => 400 ) );
		}

		$response = wp_remote_get( $url, array(
			'timeout'    => 15,
			'user-agent' => 'TWT-AEO-Connector/' . TWTAEO_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$html         = wp_remote_retrieve_body( $response );
		$robots_hdr   = (string) wp_remote_retrieve_header( $response, 'x-robots-tag' );
		$schema_types = self::extract_schema_types( $html );

		return rest_ensure_response( array(
			'ok'               => true,
			'url'              => $url,
			'http_status'      => (int) wp_remote_retrieve_response_code( $response ),
			'noindex'          => self::detect_noindex( $html, $robots_hdr ),
			'robots_blocked'   => self::robots_blocked( $url ),
			'schema_types'     => $schema_types['types'],
			'schema_conflicts' => $schema_types['conflicts'],
			'checked_at'       => gmdate( 'c' ),
		) );
	}

	// ── POST /remediate ───────────────────────────────────────────────────────

	/**
	 * Apply an agency-approved remediation instruction set to one post.
	 *
	 * Every mutation is reversible or additive: the content append goes
	 * through wp_update_post (a WP revision is created first), the meta
	 * description writes the active SEO plugin's own field, and FAQPage
	 * schema lands in the Custom Schema store where it can be edited or
	 * deleted from the dashboard. Each application is logged to postmeta.
	 */
	public static function handle_remediate( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$url  = esc_url_raw( (string) ( $body['url'] ?? '' ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return new WP_Error( 'bad_url', 'URL must belong to this site.', array( 'status' => 400 ) );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return new WP_Error( 'post_not_found', 'No published post found for this URL.', array( 'status' => 404 ) );
		}

		$applied = array();

		// 1. Content append — additive, with a WP revision as the rollback path.
		$fragment = wp_kses_post( (string) ( $body['content_append_html'] ?? '' ) );
		if ( trim( $fragment ) !== '' ) {
			$post   = get_post( $post_id );
			$result = wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $post->post_content . "\n\n<!-- TWT AEO Remediation " . gmdate( 'Y-m-d' ) . " -->\n\n" . $fragment,
			), true );
			$applied['content'] = is_wp_error( $result )
				? 'failed: ' . $result->get_error_message()
				: 'appended (revision saved)';
		} else {
			$applied['content'] = 'no change requested';
		}

		// 2. Meta description — written to the active SEO plugin's own field.
		$meta_desc = sanitize_text_field( (string) ( $body['meta_description'] ?? '' ) );
		if ( $meta_desc !== '' ) {
			$meta_key = self::seo_description_key();
			if ( $meta_key ) {
				update_post_meta( $post_id, $meta_key, $meta_desc );
				$applied['meta_description'] = 'applied via ' . $meta_key;
			} else {
				$applied['meta_description'] = 'skipped — no supported SEO plugin active';
			}
		} else {
			$applied['meta_description'] = 'no change requested';
		}

		// 3. FAQPage schema — stored in the editable Custom Schema store.
		$faqs     = array();
		foreach ( array_slice( (array) ( $body['faqs'] ?? array() ), 0, 8 ) as $faq ) {
			$q = sanitize_text_field( $faq['question'] ?? '' );
			$a = sanitize_textarea_field( $faq['answer'] ?? '' );
			if ( $q !== '' && $a !== '' ) {
				$faqs[] = array(
					'@type'          => 'Question',
					'name'           => $q,
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ),
				);
			}
		}
		if ( $faqs ) {
			$schema = array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $faqs,
			);
			$saved = TWTAEO_Custom_Schema_Writer::save( $post_id, 'FAQPage', wp_json_encode( $schema ) );
			$applied['faq_schema'] = is_wp_error( $saved )
				? 'failed: ' . $saved->get_error_message()
				: count( $faqs ) . ' question(s) added';
		} else {
			$applied['faq_schema'] = 'no change requested';
		}

		// 4. IndexNow resubmission.
		if ( ! empty( $body['resubmit_indexnow'] ) ) {
			TWTAEO_Index_Now::submit( array( $url ) );
			$applied['indexnow'] = 'resubmitted';
		} else {
			$applied['indexnow'] = 'no change requested';
		}

		// Audit trail on the post itself, capped at the last 10 applications.
		$log   = get_post_meta( $post_id, '_twtaeo_remediation_log', true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'applied' => $applied, 'at' => time() );
		update_post_meta( $post_id, '_twtaeo_remediation_log', array_slice( $log, -10 ) );

		return rest_ensure_response( array(
			'ok'      => true,
			'post_id' => $post_id,
			'applied' => $applied,
		) );
	}

	/** Meta-description field of whichever supported SEO plugin is active. */
	private static function seo_description_key() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_metadesc';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rank_math_description';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return '_seopress_titles_desc';
		}
		if ( function_exists( 'aioseo' ) ) {
			return '_aioseop_description';
		}
		return '';
	}

	/**
	 * Noindex via robots meta tag, X-Robots-Tag header, or the sitewide
	 * "discourage search engines" setting.
	 */
	private static function detect_noindex( $html, $robots_header ) {
		if ( '0' === get_option( 'blog_public', '1' ) ) {
			return true;
		}
		if ( stripos( $robots_header, 'noindex' ) !== false ) {
			return true;
		}

		// Robots meta tag — tolerate either attribute order.
		if ( preg_match_all( '/<meta\b[^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				if ( preg_match( '/\bname\s*=\s*["\'](?:robots|googlebot)["\']/i', $tag )
					&& preg_match( '/\bcontent\s*=\s*["\'][^"\']*noindex/i', $tag ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether robots.txt blocks the URL's path for User-agent: * (basic
	 * prefix/wildcard matching — enough to flag obvious misconfigurations).
	 */
	private static function robots_blocked( $url ) {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$path     = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
		$in_star  = false;
		$disallow = array();
		$allow    = array();

		foreach ( preg_split( '/\r\n|\r|\n/', wp_remote_retrieve_body( $response ) ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^user-agent\s*:\s*(.+)$/i', $line, $m ) ) {
				$in_star = ( trim( $m[1] ) === '*' );
			} elseif ( $in_star && preg_match( '/^(dis)?allow\s*:\s*(.*)$/i', $line, $m ) ) {
				$rule = trim( $m[2] );
				if ( $rule === '' ) {
					continue;
				}
				if ( strtolower( $m[1] ) === 'dis' ) {
					$disallow[] = $rule;
				} else {
					$allow[] = $rule;
				}
			}
		}

		foreach ( $allow as $rule ) {
			if ( self::rule_matches( $rule, $path ) ) {
				return false;
			}
		}
		foreach ( $disallow as $rule ) {
			if ( self::rule_matches( $rule, $path ) ) {
				return true;
			}
		}

		return false;
	}

	private static function rule_matches( $rule, $path ) {
		$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '#';
		if ( substr( $rule, -1 ) === '$' ) {
			$pattern = '#^' . str_replace( '\*', '.*', preg_quote( substr( $rule, 0, -1 ), '#' ) ) . '$#';
		}
		return (bool) preg_match( $pattern, $path );
	}

	/**
	 * Collect JSON-LD @type values from the page; types declared in more than
	 * one script block are reported as conflicts (likely duplicate emitters).
	 *
	 * @return array { types: string[], conflicts: array[] }
	 */
	private static function extract_schema_types( $html ) {
		$types_per_block = array();

		if ( preg_match_all( '#<scr[i]pt[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</scr[i]pt>#is', $html, $matches ) ) {
			foreach ( $matches[1] as $i => $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				$types_per_block[ $i ] = self::collect_types( $data );
			}
		}

		$all    = array();
		$blocks = array(); // type => block count
		foreach ( $types_per_block as $types ) {
			foreach ( array_unique( $types ) as $type ) {
				$all[]            = $type;
				$blocks[ $type ]  = ( $blocks[ $type ] ?? 0 ) + 1;
			}
		}

		$conflicts = array();
		foreach ( $blocks as $type => $count ) {
			if ( $count > 1 ) {
				$conflicts[] = array(
					'type'   => $type,
					'count'  => $count,
					'detail' => sprintf( '%s schema is emitted by %d separate JSON-LD blocks.', $type, $count ),
				);
			}
		}

		return array(
			'types'     => array_values( array_unique( $all ) ),
			'conflicts' => $conflicts,
		);
	}

	/** Recursively gather @type values (handles @graph and nested entities). */
	private static function collect_types( $data ) {
		$types = array();

		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $type ) {
				if ( is_string( $type ) ) {
					$types[] = $type;
				}
			}
		}
		foreach ( array( '@graph', 'mainEntity', 'itemListElement' ) as $nest ) {
			if ( ! empty( $data[ $nest ] ) && is_array( $data[ $nest ] ) ) {
				foreach ( (array) $data[ $nest ] as $child ) {
					if ( is_array( $child ) ) {
						$types = array_merge( $types, self::collect_types( $child ) );
					}
				}
			}
		}

		return $types;
	}
}
