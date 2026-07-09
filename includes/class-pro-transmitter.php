<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Pro_Transmitter {

	const TELEMETRY_ENDPOINT = 'https://tampawebtech.com/wp-json/twt-aeo/v1/token-telemetry';

	// ── Token telemetry ───────────────────────────────────────────────────────

	public static function telemetry_enabled() {
		$s = get_option( 'twtaeo_settings', array() );
		return ! empty( $s['token_telemetry'] );
	}

	/**
	 * Fire-and-forget anonymous token usage ping.
	 * Payload: provider, model, token counts only — no content, titles, or URLs.
	 *
	 * @param string $provider  'claude' | 'openai' | 'perplexity'
	 * @param string $model     Model slug.
	 * @param array  $usage     Raw usage object from the provider's API response.
	 */
	public static function maybe_send_token_telemetry( $provider, $model, $usage ) {
		if ( ! self::telemetry_enabled() || empty( $usage ) ) {
			return;
		}

		if ( $provider === 'claude' ) {
			$payload = array(
				'provider'              => 'claude',
				'model'                 => $model,
				'plugin_version'        => TWTAEO_VERSION,
				'input_tokens'          => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens'         => (int) ( $usage['output_tokens'] ?? 0 ),
				'cache_creation_tokens' => (int) ( $usage['cache_creation_input_tokens'] ?? 0 ),
				'cache_read_tokens'     => (int) ( $usage['cache_read_input_tokens'] ?? 0 ),
			);
		} else {
			// OpenAI and Perplexity use prompt_tokens / completion_tokens.
			$payload = array(
				'provider'              => $provider,
				'model'                 => $model,
				'plugin_version'        => TWTAEO_VERSION,
				'input_tokens'          => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'output_tokens'         => (int) ( $usage['completion_tokens'] ?? 0 ),
				'cache_creation_tokens' => 0,
				'cache_read_tokens'     => 0,
			);
		}

		wp_remote_post( self::TELEMETRY_ENDPOINT, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
	}

	// ── Pro dashboard sync ────────────────────────────────────────────────────

	public static function init() {
		// Send a plugin_status snapshot once daily
		add_action( 'twtaeo_pro_daily_sync', array( __CLASS__, 'send_plugin_status' ) );
		add_action( 'twtaeo_pro_daily_sync', array( __CLASS__, 'send_google_snapshots' ) );
		add_action( 'twtaeo_pro_daily_sync', array( __CLASS__, 'send_bing_snapshot' ) );
		if ( ! wp_next_scheduled( 'twtaeo_pro_daily_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'twtaeo_pro_daily_sync' );
		}

		// Content change tracking
		add_action( 'pre_post_update',        array( __CLASS__, 'capture_pre_update_word_count' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_send_content_change' ),     10, 3 );
	}

	public static function is_connected() {
		$s = get_option( 'twtaeo_settings', array() );
		return ! empty( $s['pro_enabled'] )
			&& TWTAEO_Key_Resolver::get( 'pro_url' ) !== ''
			&& TWTAEO_Key_Resolver::get( 'pro_key' ) !== '';
	}

	/**
	 * Transmit a typed payload to the Agency Hub.
	 *
	 * @return bool True only when the Hub confirms receipt (HTTP 200). Callers that
	 *              purge local data after sending must gate the purge on this.
	 */
	public static function send( $type, $data ) {
		if ( ! self::is_connected() ) return false;

		$response = wp_remote_post( esc_url_raw( TWTAEO_Key_Resolver::get( 'pro_url' ) ), array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-TWT-API-Key'  => TWTAEO_Key_Resolver::get( 'pro_key' ),
			),
			'body' => wp_json_encode( array(
				'type' => $type,
				'data' => $data,
			) ),
		) );

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			update_option( 'twtaeo_pro_last_sync', current_time( 'mysql' ) );
			return true;
		}

		return false;
	}

	public static function send_plugin_status() {
		self::send( 'plugin_status', array(
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => TWTAEO_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'active_modules' => self::get_active_modules(),
		) );
	}

	// Call this from schema writers when schema is added
	public static function send_schema_added( $schema_type, $post_id = null ) {
		self::record_schema_deploy( $post_id, $schema_type );
		self::send( 'schema_added', array(
			'schema_type' => $schema_type,
			'post_id'     => $post_id,
			'post_url'    => $post_id ? get_permalink( $post_id ) : home_url(),
			'post_title'  => $post_id ? get_the_title( $post_id ) : '',
		) );
	}

	// Call this from schema writers when schema is updated
	public static function send_schema_changed( $schema_type, $post_id = null, $changes = array() ) {
		self::record_schema_deploy( $post_id, $schema_type );
		self::send( 'schema_changed', array(
			'schema_type' => $schema_type,
			'post_id'     => $post_id,
			'post_url'    => $post_id ? get_permalink( $post_id ) : home_url(),
			'post_title'  => $post_id ? get_the_title( $post_id ) : '',
			'changes'     => $changes,
		) );
	}

	// Record when a schema type was deployed to a post, so the Data Handshake can
	// report deployment timestamps. Stored as [ schema_type => mysql_datetime ].
	private static function record_schema_deploy( $post_id, $schema_type ) {
		if ( ! $post_id || ! $schema_type ) {
			return;
		}
		$deployed = get_post_meta( $post_id, '_twtaeo_schema_deployed', true );
		if ( ! is_array( $deployed ) ) {
			$deployed = array();
		}
		$deployed[ $schema_type ] = current_time( 'mysql' );
		update_post_meta( $post_id, '_twtaeo_schema_deployed', $deployed );
	}

	// Record when Open Graph optimization was first activated on a post, mirroring
	// schema activation logging. First-write wins so it captures the activation moment.
	public static function record_og_deploy( $post_id ) {
		if ( ! $post_id ) {
			return;
		}
		if ( get_post_meta( $post_id, '_twtaeo_og_deployed', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_twtaeo_og_deployed', current_time( 'mysql' ) );
	}

	// ── Press release distribution ───────────────────────────────────────────

	// Call from PR Distributor after a successful wire service submission.
	public static function send_press_release_distribution( $post_id, $tier, $provider, $distribution_url = '' ) {
		self::send( 'press_release_distribution', array(
			'post_id'          => $post_id,
			'post_title'       => get_the_title( $post_id ),
			'post_url'         => get_permalink( $post_id ),
			'tier'             => $tier,
			'provider'         => $provider,
			'distribution_url' => $distribution_url,
			'distributed_at'   => current_time( 'mysql' ),
		) );
	}

	// ── Daily Google Analytics + Search Console snapshot push ─────────────────

	public static function send_google_snapshots() {
		if ( ! self::is_connected() ) return;
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) ) return;
		if ( ! TWTAEO_Google_OAuth::is_connected() ) return;

		$config = TWTAEO_Google_OAuth::get_config();

		// GA4
		if ( ! empty( $config['ga4_property_id'] ) ) {
			$totals = TWTAEO_Google_OAuth::ga4_run_report( $config['ga4_property_id'], array(
				'dateRanges' => array( array( 'startDate' => '30daysAgo', 'endDate' => 'yesterday' ) ),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'totalUsers' ),
					array( 'name' => 'screenPageViews' ),
					array( 'name' => 'bounceRate' ),
					array( 'name' => 'averageSessionDuration' ),
				),
			) );

			if ( ! is_wp_error( $totals ) ) {
				$raw = array();
				$rows = $totals['rows'] ?? array();
				if ( ! empty( $rows[0]['metricValues'] ) && ! empty( $totals['metricHeaders'] ) ) {
					foreach ( $totals['metricHeaders'] as $i => $header ) {
						$raw[ $header['name'] ] = $rows[0]['metricValues'][ $i ]['value'] ?? '0';
					}
				}

				$snapshot = array(
					'sessions'      => (float) ( $raw['sessions'] ?? 0 ),
					'pageviews'     => (float) ( $raw['screenPageViews'] ?? 0 ),
					'users'         => (float) ( $raw['totalUsers'] ?? 0 ),
					'bounce_rate'   => round( (float) ( $raw['bounceRate'] ?? 0 ) * 100, 2 ),
					'avg_duration'  => round( (float) ( $raw['averageSessionDuration'] ?? 0 ), 1 ),
					'snapshot_date' => gmdate( 'Y-m-d' ),
				);

				// Referral source breakdown: AI platforms + PR wire services + Bing organic
				$referrals = self::fetch_source_referrals( $config['ga4_property_id'] );
				if ( ! is_wp_error( $referrals ) ) {
					$snapshot['ai_referrals']             = $referrals['ai'];
					$snapshot['pr_referrals']             = $referrals['pr'];
					$snapshot['social_referrals']         = $referrals['social'];
					$snapshot['bing_sessions']            = $referrals['bing'];
					$snapshot['ai_referral_sessions']     = array_sum( $referrals['ai'] );
					$snapshot['pr_referral_sessions']     = array_sum( $referrals['pr'] );
					$snapshot['social_referral_sessions'] = array_sum( $referrals['social'] );
				}

				self::send_analytics_snapshot( $snapshot );
			}
		}

		// GSC
		if ( ! empty( $config['gsc_site_url'] ) ) {
			$gsc = TWTAEO_Google_OAuth::gsc_search_analytics( $config['gsc_site_url'], array(
				'startDate' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'endDate'   => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			) );

			if ( ! is_wp_error( $gsc ) ) {
				$row = $gsc['rows'][0] ?? array();
				self::send_gsc_snapshot( array(
					'clicks'        => (float) ( $row['clicks']      ?? 0 ),
					'impressions'   => (float) ( $row['impressions']  ?? 0 ),
					'ctr'           => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
					'avg_position'  => round( (float) ( $row['position'] ?? 0 ), 1 ),
					'snapshot_date' => gmdate( 'Y-m-d' ),
				) );
			}
		}
	}

	// ── Daily Bing Webmaster Tools snapshot push ──────────────────────────────

	/**
	 * Aggregate Bing Webmaster query stats into a totals snapshot and push it to
	 * the hub. Sums clicks/impressions across the returned queries and computes an
	 * impression-weighted average position. Only runs when Bing is connected.
	 */
	public static function send_bing_snapshot() {
		if ( ! self::is_connected() ) {
			return;
		}
		if ( ! class_exists( 'TWTAEO_Bing_Webmaster' ) || ! TWTAEO_Bing_Webmaster::is_connected() ) {
			return;
		}

		$stats = TWTAEO_Bing_Webmaster::get_query_stats();
		if ( is_wp_error( $stats ) ) {
			return;
		}

		$rows = isset( $stats['d'] ) && is_array( $stats['d'] ) ? $stats['d'] : array();

		$clicks      = 0;
		$impressions = 0;
		$position_w  = 0.0;

		foreach ( $rows as $row ) {
			$c = (int) ( $row['Clicks'] ?? 0 );
			$i = (int) ( $row['Impressions'] ?? 0 );
			$clicks      += $c;
			$impressions += $i;
			$position_w  += (float) ( $row['AvgImpressionPosition'] ?? 0 ) * $i;
		}

		self::send( 'bing', array(
			'clicks'        => $clicks,
			'impressions'   => $impressions,
			'ctr'           => $impressions > 0 ? round( $clicks / $impressions * 100, 2 ) : 0,
			'avg_position'  => $impressions > 0 ? round( $position_w / $impressions, 1 ) : 0,
			'snapshot_date' => gmdate( 'Y-m-d' ),
		) );
	}

	// Query GA4 for sessions by source — identifies AI platform and PR wire referrals, plus Bing organic.
	private static function fetch_source_referrals( $property_id ) {
		$result = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => array( array( 'startDate' => '30daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'sessionSource' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 100,
		) );

		if ( is_wp_error( $result ) ) return $result;

		$ai_patterns     = array( 'claude.ai', 'perplexity.ai', 'chatgpt.com', 'you.com', 'copilot.microsoft.com', 'bard.google.com', 'gemini.google.com' );
		$pr_patterns     = array( 'einpresswire.com', 'prnewswire.com', 'businesswire.com', 'prweb.com', 'easyprwire.com', 'accesswire.com', 'globe-newswire.com' );
		$social_patterns = array( 'facebook.com', 'fb.com', 'instagram.com', 'l.instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'pinterest.com', 'youtube.com', 'reddit.com', 'tiktok.com', 'threads.net' );

		$ai     = array();
		$pr     = array();
		$social = array();
		$bing   = 0;

		foreach ( $result['rows'] ?? array() as $row ) {
			$source   = strtolower( trim( $row['dimensionValues'][0]['value'] ?? '' ) );
			$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( ! $sessions ) continue;

			foreach ( $ai_patterns as $pattern ) {
				if ( strpos( $source, $pattern ) !== false ) {
					$ai[ $source ] = $sessions;
					break;
				}
			}
			foreach ( $pr_patterns as $pattern ) {
				if ( strpos( $source, $pattern ) !== false ) {
					$pr[ $source ] = $sessions;
					break;
				}
			}
			foreach ( $social_patterns as $pattern ) {
				if ( strpos( $source, $pattern ) !== false ) {
					$social[ $source ] = $sessions;
					break;
				}
			}
			if ( strpos( $source, 'bing' ) !== false ) {
				$bing += $sessions;
			}
		}

		return array( 'ai' => $ai, 'pr' => $pr, 'social' => $social, 'bing' => $bing );
	}

	// ── Content change tracking ───────────────────────────────────────────────

	// Stash the pre-save word count so the after-save delta is meaningful.
	public static function capture_pre_update_word_count( $post_id, $data ) {
		if ( get_post_status( $post_id ) !== 'publish' ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		set_transient(
			'twtaeo_wc_before_' . $post_id,
			str_word_count( wp_strip_all_tags( $post->post_content ) ),
			60
		);
	}

	public static function maybe_send_content_change( $new_status, $old_status, $post ) {
		if ( $new_status !== 'publish' ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		$change_type = ( $old_status === 'publish' ) ? 'updated' : 'created';
		$wc_after    = str_word_count( wp_strip_all_tags( $post->post_content ) );
		$wc_before   = (int) get_transient( 'twtaeo_wc_before_' . $post->ID );
		delete_transient( 'twtaeo_wc_before_' . $post->ID );

		self::send_content_change( $post->ID, $post->post_type, $change_type, $wc_before, $wc_after );
	}

	public static function send_content_change( $post_id, $post_type, $change_type, $wc_before = 0, $wc_after = 0 ) {
		self::send( 'content_change', array(
			'post_id'           => $post_id,
			'post_type'         => $post_type,
			'change_type'       => $change_type,
			'title'             => get_the_title( $post_id ),
			'url'               => get_permalink( $post_id ),
			'word_count_before' => $wc_before,
			'word_count_after'  => $wc_after,
			'changed_at'        => current_time( 'mysql' ),
		) );
	}

	// Call from Index Status to transmit the de-indexed URL list to the pro dashboard.
	// Returns send()'s confirmation so callers can gate retry logic on receipt.
	public static function send_deindex_report( $deindexed_items ) {
		return self::send( 'deindex_report', array(
			'site_url'    => home_url(),
			'site_name'   => get_bloginfo( 'name' ),
			'items'       => $deindexed_items,
			'reported_at' => current_time( 'mysql' ),
		) );
	}

	// Call from AI bot logger when a known AI crawler visits
	public static function send_ai_bot_event( $bot_name, $url_accessed, $bot_company = '' ) {
		$post_id            = url_to_postid( $url_accessed );
		$days_since_publish = null;
		$post_type          = null;
		$post_title         = null;

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_status === 'publish' ) {
				$days_since_publish = (int) floor( ( time() - strtotime( $post->post_date ) ) / DAY_IN_SECONDS );
				$post_type          = $post->post_type;
				$post_title         = $post->post_title;
			}
		}

		self::send( 'ai_bot_event', array(
			'site_url'           => home_url(),
			'site_name'          => get_bloginfo( 'name' ),
			'bot'                => $bot_name,
			'company'            => $bot_company,
			'url'                => $url_accessed,
			'accessed_at'        => current_time( 'mysql' ),
			'post_id'            => $post_id ?: null,
			'post_type'          => $post_type,
			'post_title'         => $post_title,
			'days_since_publish' => $days_since_publish,
		) );
	}

	// Call when a GSC snapshot is captured
	public static function send_gsc_snapshot( $data ) {
		self::send( 'gsc', $data );
	}

	// Call from Baseline Metrics with a chunk of beyond-cap pages (ranks 51–200).
	public static function send_page_baseline( $pages, $period ) {
		self::send( 'page_baseline', array(
			'site_url'    => home_url(),
			'site_name'   => get_bloginfo( 'name' ),
			'period'      => $period,
			'page_count'  => count( (array) $pages ),
			'pages'       => array_values( (array) $pages ),
			'captured_at' => current_time( 'mysql' ),
		) );
	}

	// Call when an analytics snapshot is captured
	public static function send_analytics_snapshot( $data ) {
		self::send( 'analytics', $data );
	}

	// Call from IndexNow after URL submission
	public static function send_indexnow_event( $urls, $results ) {
		$engine = ! empty( $results[0]['engine'] ) ? $results[0]['engine'] : 'api.indexnow.org';
		$status = ! empty( $results[0]['status'] ) ? (int) $results[0]['status'] : 0;
		self::send( 'indexnow_submitted', array(
			'site_url'     => home_url(),
			'site_name'    => get_bloginfo( 'name' ),
			'urls'         => array_values( (array) $urls ),
			'engine'       => $engine,
			'status'       => $status,
			'submitted_at' => current_time( 'mysql' ),
		) );
	}

	// Call from Schema Conflict Detector when conflicts are found
	public static function send_schema_conflict( $conflict ) {
		self::send( 'schema_conflict', array(
			'site_url'      => home_url(),
			'site_name'     => get_bloginfo( 'name' ),
			'conflict_type' => $conflict['type'] ?? '',
			'plugin'        => $conflict['plugin'] ?? '',
			'plugin_label'  => $conflict['plugin_label'] ?? '',
			'suppressed'    => ! empty( $conflict['suppressed'] ),
			'detected_at'   => current_time( 'mysql' ),
		) );
	}

	public static function send_micro_conversion( $event_type, $page_url, $device = 'unknown' ) {
		self::send( 'micro_conversion', array(
			'site_url'   => home_url(),
			'site_name'  => get_bloginfo( 'name' ),
			'event'      => $event_type,
			'page'       => $page_url,
			'device'     => $device,
			'tracked_at' => current_time( 'mysql' ),
		) );
	}

	private static function get_active_modules() {
		$modules = get_option( 'twtaeo_modules', array() );
		return array_keys( array_filter( $modules ) );
	}
}
