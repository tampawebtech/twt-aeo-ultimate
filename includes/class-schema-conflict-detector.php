<?php
/**
 * Schema Conflict Detector
 *
 * Fetches the site homepage via loopback, parses every JSON-LD block,
 * attributes each block to its source plugin, and compares that output
 * against any high-fidelity schemas we have stored.
 *
 * When both a competitor plugin and TWT AEO output the same @type,
 * a conflict is raised with a field-level diff so the user can decide
 * whether to Supplement (coexist) or Suppress the competitor's version.
 *
 * Suppression is applied at init time via plugin-specific filters:
 *   Yoast        — wpseo_schema_graph
 *   Rank Math    — rank_math/json_ld
 *   All in One SEO — aioseo_schema_output
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Schema_Conflict_Detector {

	const NONCE          = 'twtaeo_schema_conflict';
	const OPTION_SUPPRESS = 'twtaeo_schema_suppress';

	/**
	 * Schema @types we produce — only these can create a meaningful conflict.
	 */
	private static $our_types = array(
		'Service', 'FAQPage', 'HowTo', 'Product', 'LocalBusiness',
		'Organization', 'Person', 'ProfessionalService',
	);

	/**
	 * Structural fields excluded from the semantic field diff
	 * (not meaningful to compare, present in every block).
	 */
	private static $skip_fields = array(
		'@context', '@id', '@type', 'url', 'mainEntityOfPage',
		'@graph', 'inLanguage',
	);

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register suppression filters at init time.
	 * Called from class-plugin.php run().
	 */
	public static function register_hooks() {
		$suppressed = self::get_suppressions();
		if ( empty( $suppressed ) ) {
			return;
		}
		foreach ( $suppressed as $entry ) {
			if ( empty( $entry['active'] ) ) {
				continue;
			}
			self::register_suppression_filter( $entry['plugin'], $entry['type'] );
		}
	}

	/**
	 * Perform a full schema conflict scan.
	 * Should only be called from an AJAX handler — NOT during page render.
	 *
	 * @param bool $transmit Send conflicts to the agency dashboard (false for read-only callers).
	 * @return array|WP_Error
	 */
	public static function scan( $transmit = true ) {
		$html = self::fetch_homepage_html();
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$blocks           = self::parse_attributed_blocks( $html );
		$competitor_types = self::index_competitor_types( $blocks );
		$our_stored       = self::get_our_stored_schemas();
		$suppressions     = self::get_suppressions();

		$conflicts  = array();
		$their_only = array();
		$our_only   = array();

		// ── Conflicts: same type from both a competitor and us ────────────────
		foreach ( $competitor_types as $type => $competitor_info ) {
			$our_schema = self::find_our_schema( $type, $our_stored );

			if ( $our_schema ) {
				$their_fields = self::extract_semantic_fields( $competitor_info['schema'] );
				$our_fields   = self::extract_semantic_fields( $our_schema );
				$missing_ours = array_values( array_diff( array_keys( $our_fields ), array_keys( $their_fields ) ) );
				$missing_them = array_values( array_diff( array_keys( $their_fields ), array_keys( $our_fields ) ) );
				$suppressed   = self::is_suppressed( $suppressions, $competitor_info['plugin'], $type );

				$conflicts[] = array(
					'type'            => $type,
					'plugin'          => $competitor_info['plugin'],
					'plugin_label'    => self::plugin_label( $competitor_info['plugin'] ),
					'their_fields'    => array_keys( $their_fields ),
					'our_fields'      => array_keys( $our_fields ),
					'missing_in_them' => $missing_ours,
					'missing_in_us'   => $missing_them,
					'suppressed'      => $suppressed,
					'can_suppress'    => in_array( $competitor_info['plugin'], array( 'yoast', 'rank_math', 'aioseo', 'saswp' ), true ),
				);
			} else {
				$their_only[] = array(
					'type'         => $type,
					'plugin'       => $competitor_info['plugin'],
					'plugin_label' => self::plugin_label( $competitor_info['plugin'] ),
					'fields'       => array_keys( self::extract_semantic_fields( $competitor_info['schema'] ) ),
				);
			}
		}

		// ── Our-only types: we have them but no competitor does ───────────────
		foreach ( $our_stored as $entry ) {
			$type = $entry['type'];
			if ( ! isset( $competitor_types[ $type ] ) ) {
				$our_only[] = array(
					'type'       => $type,
					'post_title' => $entry['post_title'],
					'fields'     => array_keys( self::extract_semantic_fields( $entry['schema'] ) ),
				);
			}
		}

		if ( $transmit && ! empty( $conflicts ) && class_exists( 'TWTAEO_Pro_Transmitter' ) ) {
			foreach ( $conflicts as $conflict ) {
				TWTAEO_Pro_Transmitter::send_schema_conflict( $conflict );
			}
		}

		return array(
			'scanned_url'       => get_home_url(),
			'plugins_detected'  => array_values( array_unique( array_column( $blocks, 'plugin' ) ) ),
			'conflicts'         => $conflicts,
			'their_only'        => $their_only,
			'our_only'          => $our_only,
		);
	}

	/**
	 * Save or update a suppression entry.
	 *
	 * @param string $plugin  'yoast' | 'rank_math'
	 * @param string $type    Schema @type e.g. 'Service'
	 * @param bool   $active
	 */
	public static function save_suppression( $plugin, $type, $active ) {
		$all = self::get_suppressions();
		$found = false;

		foreach ( $all as &$entry ) {
			if ( $entry['plugin'] === $plugin && $entry['type'] === $type ) {
				$entry['active'] = $active;
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$all[] = array( 'plugin' => $plugin, 'type' => $type, 'active' => $active );
		}

		update_option( self::OPTION_SUPPRESS, $all );
	}

	/**
	 * Return all saved suppression entries.
	 *
	 * @return array[]
	 */
	public static function get_suppressions() {
		return (array) get_option( self::OPTION_SUPPRESS, array() );
	}

	// ── Suppression filter registration ───────────────────────────────────────

	private static function register_suppression_filter( $plugin, $type ) {
		if ( $plugin === 'yoast' ) {
			// wpseo_schema_graph — filters the final @graph array (Yoast 11+).
			add_filter( 'wpseo_schema_graph', function( $graph ) use ( $type ) {
				return array_values( array_filter( $graph, function( $node ) use ( $type ) {
					$types = (array) ( $node['@type'] ?? array() );
					return ! in_array( $type, $types, true );
				} ) );
			}, 20 );
		}

		if ( $plugin === 'rank_math' ) {
			// rank_math/json_ld — filters the schema data array (Rank Math 1.x+).
			add_filter( 'rank_math/json_ld', function( $data ) use ( $type ) {
				foreach ( $data as $key => $item ) {
					$types = (array) ( $item['@type'] ?? array() );
					if ( in_array( $type, $types, true ) ) {
						unset( $data[ $key ] );
					}
				}
				return $data;
			}, 20 );
		}

		if ( $plugin === 'aioseo' ) {
			// aioseo_schema_output — filters AIOSEO's JSON-LD graph array (AIOSEO 4.x+).
			add_filter( 'aioseo_schema_output', function( $graphs ) use ( $type ) {
				foreach ( $graphs as $key => $graph ) {
					$types = (array) ( $graph['@type'] ?? array() );
					if ( in_array( $type, $types, true ) ) {
						unset( $graphs[ $key ] );
					}
				}
				return array_values( $graphs );
			}, 20 );
		}

		if ( $plugin === 'saswp' ) {
			// saswp_schema_output — filters SASWP's schema array before JSON-LD output.
			add_filter( 'saswp_schema_output', function( $schemas ) use ( $type ) {
				foreach ( $schemas as $key => $schema ) {
					$types = (array) ( $schema['@type'] ?? array() );
					if ( in_array( $type, $types, true ) ) {
						unset( $schemas[ $key ] );
					}
				}
				return array_values( $schemas );
			}, 20 );
		}
	}

	// ── Homepage fetch ─────────────────────────────────────────────────────────

	private static function fetch_homepage_html() {
		$response = wp_remote_get( get_home_url(), array(
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'TWT-AEO-SchemaScanner/1.0',
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				'Could not fetch homepage: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'bad_status', 'Homepage returned HTTP ' . $code );
		}

		return wp_remote_retrieve_body( $response );
	}

	// ── JSON-LD parsing & attribution ─────────────────────────────────────────

	/**
	 * Extract all JSON-LD blocks from HTML, capturing the HTML comment
	 * immediately before each JSON-LD script tag for source attribution.
	 *
	 * @param string $html
	 * @return array[]  Each: { plugin, schema[] }
	 */
	private static function parse_attributed_blocks( $html ) {
		// Capture optional HTML comment immediately before each JSON-LD script.
		preg_match_all(
			'/(<!--[^>]*?-->\s*)?<scr[i]pt[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/scr[i]pt>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		);

		$blocks = array();

		foreach ( $matches as $match ) {
			$comment = trim( $match[1] ?? '' );
			$json    = trim( $match[2] );
			$data    = json_decode( $json, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$plugin = self::attribute_block( $comment, $data );
			$nodes  = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );

			foreach ( $nodes as $node ) {
				if ( ! isset( $node['@type'] ) ) {
					continue;
				}
				$blocks[] = array(
					'plugin' => $plugin,
					'schema' => $node,
				);
			}
		}

		return $blocks;
	}

	/**
	 * Attribute a JSON-LD block to its source plugin.
	 *
	 * Priority:
	 *   1. HTML comment text (most reliable for our own output)
	 *   2. Yoast @id pattern (/#/schema/)
	 *   3. Active plugin detection as fallback
	 *
	 * @param string $comment  HTML comment before the script tag
	 * @param array  $data     Decoded JSON-LD
	 * @return string  'twt' | 'yoast' | 'rank_math' | 'other'
	 */
	private static function attribute_block( $comment, $data ) {
		// Our own output always has a comment.
		if ( stripos( $comment, 'TWT AEO' ) !== false ) {
			return 'twt';
		}

		// Yoast SEO comment.
		if ( stripos( $comment, 'Yoast' ) !== false || stripos( $comment, 'wpseo' ) !== false ) {
			return 'yoast';
		}

		// Yoast @id pattern: all their @ids contain /#/schema/.
		$graph = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
		foreach ( $graph as $node ) {
			if ( isset( $node['@id'] ) && strpos( $node['@id'], '/#/schema/' ) !== false ) {
				return 'yoast';
			}
		}

		// Rank Math comment.
		if ( stripos( $comment, 'Rank Math' ) !== false || stripos( $comment, 'rank_math' ) !== false ) {
			return 'rank_math';
		}

		// All in One SEO comment.
		if ( stripos( $comment, 'All in One SEO' ) !== false || stripos( $comment, 'aioseo' ) !== false ) {
			return 'aioseo';
		}

		// Schema & Structured Data for WP & AMP (SASWP) comment.
		if ( stripos( $comment, 'saswp' ) !== false || stripos( $comment, 'schema-and-structured-data' ) !== false ) {
			return 'saswp';
		}

		// Active plugin fallback — unknown blocks attributed to the active SEO plugin.
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rank_math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SASWP_VERSION' ) ) {
			return 'saswp';
		}

		return 'other';
	}

	/**
	 * Build a map of { type => { plugin, schema } } for competitor plugins only.
	 * TWT blocks are excluded — those are ours, not conflicts.
	 *
	 * @param array[] $blocks
	 * @return array
	 */
	private static function index_competitor_types( $blocks ) {
		$index = array();

		foreach ( $blocks as $block ) {
			if ( $block['plugin'] === 'twt' ) {
				continue;
			}

			$types = (array) ( $block['schema']['@type'] ?? array() );
			foreach ( $types as $type ) {
				if ( in_array( $type, self::$our_types, true ) && ! isset( $index[ $type ] ) ) {
					$index[ $type ] = array(
						'plugin' => $block['plugin'],
						'schema' => $block['schema'],
					);
				}
			}
		}

		return $index;
	}

	// ── Our stored schema lookup ──────────────────────────────────────────────

	/**
	 * Return all TWT AEO custom schemas from postmeta, across all posts.
	 *
	 * @return array[]  Each: { type, post_id, post_title, schema }
	 */
	private static function get_our_stored_schemas() {
		global $wpdb;

		$cache_key = 'twtaeo_our_stored_schemas';
		$rows      = wp_cache_get( $cache_key );
		if ( false === $rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				TWTAEO_Custom_Schema_Writer::META_KEY
			), ARRAY_A );
			wp_cache_set( $cache_key, $rows, '', 300 );
		}

		$results = array();

		foreach ( $rows as $row ) {
			$all = json_decode( $row['meta_value'], true );
			if ( ! is_array( $all ) ) {
				continue;
			}

			$post_title = get_the_title( (int) $row['post_id'] );

			foreach ( $all as $type => $schema ) {
				if ( is_array( $schema ) ) {
					$results[] = array(
						'type'       => $type,
						'post_id'    => (int) $row['post_id'],
						'post_title' => $post_title,
						'schema'     => $schema,
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Find the first stored schema matching a given @type.
	 *
	 * @param string  $type
	 * @param array[] $stored
	 * @return array|null
	 */
	private static function find_our_schema( $type, $stored ) {
		foreach ( $stored as $entry ) {
			if ( $entry['type'] === $type ) {
				return $entry['schema'];
			}
		}
		return null;
	}

	// ── Field diff helpers ────────────────────────────────────────────────────

	/**
	 * Extract meaningful semantic fields from a schema node,
	 * stripping structural/boilerplate keys.
	 *
	 * @param array $schema
	 * @return array  Associative: field => value
	 */
	private static function extract_semantic_fields( $schema ) {
		$fields = array();
		foreach ( $schema as $key => $value ) {
			if ( in_array( $key, self::$skip_fields, true ) ) {
				continue;
			}
			if ( $value === '' || $value === null || $value === array() ) {
				continue;
			}
			$fields[ $key ] = $value;
		}
		return $fields;
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private static function is_suppressed( $suppressions, $plugin, $type ) {
		foreach ( $suppressions as $entry ) {
			if ( $entry['plugin'] === $plugin && $entry['type'] === $type && ! empty( $entry['active'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function plugin_label( $plugin ) {
		$map = array(
			'yoast'     => 'Yoast SEO',
			'rank_math' => 'Rank Math',
			'aioseo'    => 'All in One SEO',
			'saswp'     => 'Schema & Structured Data for WP & AMP',
			'other'     => 'Unknown Plugin',
			'twt'       => 'TWT AEO',
		);
		return $map[ $plugin ] ?? ucfirst( $plugin );
	}

	/**
	 * Delete all schema-related postmeta written by a given SEO plugin.
	 *
	 * @param string $plugin  'yoast' | 'rank_math' | 'aioseo'
	 * @return int  Number of rows deleted, or -1 for unknown plugin.
	 */
	public static function delete_plugin_schema( $plugin ) {
		global $wpdb;

		switch ( $plugin ) {
			case 'yoast':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows  = (int) $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
					'_yoast_wpseo_schema_page_type',
					'_yoast_wpseo_schema_article_type'
				) );
				// Also clear Yoast's global schema graph option.
				delete_option( 'wpseo_titles' );
				return $rows;

			case 'rank_math':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->query(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank_math_schema_%'"
				);

			case 'aioseo':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->query(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_aioseo_schema_%' OR meta_key = 'aioseo_schema'"
				);

			case 'saswp':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->query(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'saswp_%'"
				);

			default:
				return -1;
		}
	}
}
