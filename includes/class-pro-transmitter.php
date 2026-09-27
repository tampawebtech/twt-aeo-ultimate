<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Pro_Transmitter {

	const TELEMETRY_ENDPOINT = 'https://tampawebtech.com/wp-json/twt-aeo/v1/token-telemetry';

	/** Deferred single event that ships a finished AI Visibility run to the Hub. */
	const VISIBILITY_SEND_HOOK = 'twtaeo_pro_send_visibility_run';

	/**
	 * Payload ceilings for a visibility run. A Deep allocation on a large
	 * catalogue is a few thousand checks; excerpts are the bulk of that weight,
	 * so they are the first thing dropped -- and their absence is declared, so the
	 * Hub never reads an empty excerpt as "the engine said nothing".
	 */
	const VISIBILITY_MAX_BYTES  = 1500000;
	const VISIBILITY_MAX_CHECKS = 4000;

	/**
	 * How many scored pages travel with the daily AEO Score snapshot.
	 *
	 * The Hub's use for per-page scores is "which pages are dragging this client
	 * down" — a job the worst pages answer and a 5,000-page catalogue does not.
	 * The site summary is computed from every scored page regardless, so the cap
	 * costs no accuracy in the headline number.
	 */
	const SCORE_MAX_PAGES = 250;

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
		add_action( 'twtaeo_pro_daily_sync', array( __CLASS__, 'send_aeo_score' ) );
		if ( ! wp_next_scheduled( 'twtaeo_pro_daily_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'twtaeo_pro_daily_sync' );
		}

		// Content change tracking
		add_action( 'pre_post_update',        array( __CLASS__, 'capture_pre_update_word_count' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_send_content_change' ),     10, 3 );

		// AI Visibility runs. The run finishes inside an AJAX step (or the cron
		// worker), so the transmit is deferred to its own single event rather than
		// made the board wait on a 10-second POST to finish the last check.
		add_action( 'twtaeo_visibility_run_finished', array( __CLASS__, 'queue_visibility_run' ), 10, 2 );
		add_action( self::VISIBILITY_SEND_HOOK,       array( __CLASS__, 'send_visibility_run' ),  10, 1 );
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

	// -- AEO Score -------------------------------------------------------------

	/**
	 * Daily AEO Score snapshot: the site's number, how it was earned, and the
	 * pages holding it back.
	 *
	 * Sent as the score, not as raw signals: the rubric that produced it lives
	 * here and changes with this plugin, so recomputing it on the Hub would drift
	 * the moment the two versions differ. `rubric_version` travels with the
	 * snapshot so the Hub can tell a real movement from a change in how the
	 * number is calculated.
	 *
	 * @return bool True when the Hub confirmed receipt.
	 */
	public static function send_aeo_score() {
		if ( ! self::is_connected() || ! class_exists( 'TWTAEO_AEO_Score' ) ) {
			return false;
		}

		$summary = TWTAEO_AEO_Score::site_summary();
		if ( ! is_array( $summary ) || null === ( $summary['site_score'] ?? null ) ) {
			return false; // Nothing scanned yet; a null score is not a zero.
		}

		return self::send( 'aeo_score', array(
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => TWTAEO_VERSION,
			'rubric_version' => (int) TWTAEO_AEO_Score::RUBRIC_VERSION,
			'scored_at'      => current_time( 'mysql' ),
			'summary'        => array(
				'site_score'    => (int) $summary['site_score'],
				'avg_page'      => isset( $summary['avg_page'] ) ? (int) $summary['avg_page'] : null,
				'scored_pages'  => (int) ( $summary['scored_pages'] ?? 0 ),
				'checklist_pct' => (int) ( $summary['checklist_pct'] ?? 0 ),
				'categories'    => self::score_categories( $summary ),
			),
			'pages' => self::worst_scored_pages(),
		) );
	}

	/**
	 * Category rollup, trimmed to what a cross-client view can use: the label and
	 * the percentage. The raw earned/max are a per-site artefact of how many pages
	 * were sampled and do not add up across clients.
	 *
	 * @param array $summary
	 * @return array
	 */
	private static function score_categories( array $summary ) {
		$out = array();
		foreach ( (array) ( $summary['categories'] ?? array() ) as $id => $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'label' => (string) ( $cat['label'] ?? $id ),
				'pct'   => (int) ( $cat['pct'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * The lowest-scoring published pages, worst first.
	 *
	 * @return array
	 */
	private static function worst_scored_pages() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one daily transmit; the postmeta aggregate has no WP_Query equivalent.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, CAST(pm.meta_value AS UNSIGNED) AS score
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
			 WHERE pm.meta_key = %s
			 ORDER BY score ASC, p.ID ASC
			 LIMIT %d",
			TWTAEO_AEO_Score::META_SCORE,
			self::SCORE_MAX_PAGES
		) );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'url'       => get_permalink( $row->ID ),
				'title'     => (string) $row->post_title,
				'post_type' => (string) $row->post_type,
				'score'     => (int) $row->score,
				'gaps'      => self::score_gaps( (int) $row->ID ),
			);
		}
		return $out;
	}

	/**
	 * The named gaps on one page — the line items it did not earn.
	 *
	 * Only the labels travel. The fix links in a stored breakdown are admin URLs
	 * on THIS site, useless in an agency dashboard, and the point of shipping the
	 * gaps at all is so the agency can see what to bill for.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	private static function score_gaps( $post_id ) {
		$breakdown = TWTAEO_AEO_Score::get_breakdown( $post_id );
		if ( ! is_array( $breakdown ) || empty( $breakdown['categories'] ) ) {
			return array();
		}

		$gaps = array();
		foreach ( (array) $breakdown['categories'] as $cat ) {
			foreach ( (array) ( $cat['items'] ?? array() ) as $item ) {
				// A gap is a line item that earned nothing. Partial credit is not
				// listed: "you have some of this" is not an instruction.
				if ( ! is_array( $item ) || (float) ( $item['max'] ?? 0 ) <= 0 || (float) ( $item['pts'] ?? 0 ) > 0 ) {
					continue;
				}
				$label = trim( (string) ( $item['label'] ?? '' ) );
				if ( '' !== $label && ! in_array( $label, $gaps, true ) ) {
					$gaps[] = $label;
				}
			}
			if ( count( $gaps ) >= 12 ) {
				break;
			}
		}
		return array_slice( $gaps, 0, 12 );
	}

	// -- AI Visibility runs ----------------------------------------------------

	/**
	 * A visibility run finished -- queue it for transmission.
	 *
	 * Only the id is carried through the event. The run is re-read at send time
	 * from the tables, which are the source of truth and cost nothing to query,
	 * so a run edited or pruned between finishing and sending is never shipped in
	 * a state the site no longer holds.
	 *
	 * @param string $run_id
	 * @param array  $run    The finished run (used only to skip sample data early).
	 */
	public static function queue_visibility_run( $run_id, $run = array() ) {
		if ( ! self::is_connected() ) {
			return;
		}
		// Sample data is invented from the site's own catalogue to demonstrate the
		// board. Shipping it would put fabricated citations in an agency's client
		// reporting, so it never leaves the site.
		if ( ! empty( $run['demo'] ) ) {
			return;
		}
		$run_id = (string) $run_id;
		if ( '' === $run_id || wp_next_scheduled( self::VISIBILITY_SEND_HOOK, array( $run_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::VISIBILITY_SEND_HOOK, array( $run_id ) );
	}

	/**
	 * Ship one finished visibility run to the Hub.
	 *
	 * Sends the run's own verdicts rather than a re-probe: the Hub gets every
	 * engine this site holds a key for, the full cited/named/absent/unavailable
	 * vocabulary, and the cited domains per check -- which is what a cross-client
	 * share of voice needs and what a boolean "appeared" cannot express.
	 *
	 * @param string $run_id
	 * @return bool True when the Hub confirmed receipt.
	 */
	public static function send_visibility_run( $run_id ) {
		if ( ! self::is_connected() || ! class_exists( 'TWTAEO_Visibility_Store' ) ) {
			return false;
		}

		$run = TWTAEO_Visibility_Store::get_run( (string) $run_id );
		if ( ! is_array( $run ) || empty( $run['id'] ) || ! empty( $run['demo'] ) ) {
			return false;
		}

		$payload = self::build_visibility_payload( $run );
		if ( empty( $payload['checks'] ) ) {
			return false; // A run that recorded nothing is not evidence of anything.
		}

		return self::send( 'ai_visibility', $payload );
	}

	/**
	 * Assemble the wire payload for a run.
	 *
	 * Questions are limited to the ones actually checked. A run only asks as far
	 * as its allocation reached, and an unasked question is not an absent one --
	 * shipping the whole planned set would let the Hub report silence as failure.
	 * `questions_planned` carries the coverage denominator instead.
	 *
	 * @param array $run
	 * @return array
	 */
	private static function build_visibility_payload( array $run ) {
		$settings = TWTAEO_Visibility_Store::get_settings();
		$checks   = isset( $run['checks'] ) && is_array( $run['checks'] ) ? $run['checks'] : array();

		$truncated = false;
		if ( count( $checks ) > self::VISIBILITY_MAX_CHECKS ) {
			$checks    = array_slice( $checks, 0, self::VISIBILITY_MAX_CHECKS );
			$truncated = true;
		}

		$asked = array();
		$wire  = array();
		foreach ( $checks as $c ) {
			if ( ! is_array( $c ) || empty( $c['question_id'] ) ) {
				continue;
			}
			$asked[ (string) $c['question_id'] ] = true;
			$wire[] = array(
				'question_id'      => (string) $c['question_id'],
				'engine'           => (string) ( $c['engine'] ?? '' ),
				'checked_at'       => (string) ( $c['at'] ?? '' ),
				'verdict'          => (string) ( $c['verdict'] ?? 'unavailable' ),
				'accuracy'         => (string) ( $c['accuracy'] ?? 'n/a' ),
				'citation_surface' => (string) ( $c['citation_surface'] ?? '' ),
				'cited_urls'       => array_values( (array) ( $c['cited_urls'] ?? array() ) ),
				'our_urls'         => array_values( (array) ( $c['our_urls'] ?? array() ) ),
				'domains'          => array_values( (array) ( $c['domains'] ?? array() ) ),
				'owned_domains'    => array_values( (array) ( $c['owned_domains'] ?? array() ) ),
				'brand_hits'       => array_values( (array) ( $c['brand_hits'] ?? array() ) ),
				'excerpt'          => (string) ( $c['excerpt'] ?? '' ),
				'model'            => (string) ( $c['model'] ?? '' ),
				'error'            => isset( $c['error'] ) && '' !== (string) $c['error'] ? (string) $c['error'] : null,
			);
		}

		$questions = array();
		foreach ( (array) ( $run['questions'] ?? array() ) as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) || ! isset( $asked[ (string) $q['id'] ] ) ) {
				continue;
			}
			$questions[] = array(
				'id'          => (string) $q['id'],
				'level'       => (string) ( $q['level'] ?? '' ),
				'scope_id'    => (string) ( $q['scope_id'] ?? '' ),
				'scope_label' => (string) ( $q['scope_label'] ?? '' ),
				'family'      => (string) ( $q['family'] ?? '' ),
				'text'        => (string) ( $q['text'] ?? '' ),
				'source'      => (string) ( $q['source'] ?? '' ),
			);
		}

		$payload = array(
			'site_url'          => home_url(),
			'site_name'         => get_bloginfo( 'name' ),
			'plugin_version'    => TWTAEO_VERSION,
			'run'               => TWTAEO_Visibility_Verdict::summarize_run( $run ),
			'allocation'        => (array) ( $run['allocation'] ?? array() ),
			'questions_planned' => count( (array) ( $run['questions'] ?? array() ) ),
			'questions'         => $questions,
			'checks'            => $wire,
			'identity'          => self::visibility_identity( $settings ),
			'excerpts_omitted'  => false,
			'truncated'         => $truncated,
		);

		// Weight check. Excerpts go first because they are the bulk and the least
		// structural; everything the Hub aggregates on survives.
		if ( strlen( (string) wp_json_encode( $payload ) ) > self::VISIBILITY_MAX_BYTES ) {
			foreach ( array_keys( $payload['checks'] ) as $i ) {
				$payload['checks'][ $i ]['excerpt'] = '';
			}
			$payload['excerpts_omitted'] = true;
		}

		return $payload;
	}

	/**
	 * What counts as this site, so the Hub can read a citation the same way the
	 * site does -- an owned LinkedIn or YouTube page is us, not a competitor.
	 *
	 * @param array $settings Visibility settings.
	 * @return array
	 */
	private static function visibility_identity( array $settings ) {
		$brands = array();
		foreach ( (array) ( $settings['brand_items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['enabled'] ) ) {
				continue;
			}
			$brands[] = array(
				'label'   => (string) ( $item['label'] ?? '' ),
				'phrases' => array_values( (array) ( $item['phrases'] ?? array() ) ),
				'urls'    => array_values( (array) ( $item['urls'] ?? array() ) ),
			);
		}

		return array(
			'site_host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'alternate_hosts' => array_values( (array) ( $settings['alternate_hosts'] ?? array() ) ),
			'company_urls'    => array_values( (array) ( $settings['company_urls'] ?? array() ) ),
			'brand_items'     => $brands,
		);
	}
}
