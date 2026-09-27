<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_AI_Crawler_Logger {

	const OPTION_LOG   = 'twtaeo_crawler_log';
	const MAX_ENTRIES  = 500;
	const CRON_HOOK    = 'twtaeo_crawler_log_prune';
	const NOTICE_TRANS = 'twtaeo_crawler_notice';

	// Known AI and LLM crawlers: pattern => human-readable company name.
	private static $bots = array(
		'GPTBot'             => 'OpenAI',
		'ChatGPT-User'       => 'OpenAI',
		'OAI-SearchBot'      => 'OpenAI',
		'Claude-Web'         => 'Anthropic',
		'ClaudeBot'          => 'Anthropic',
		'anthropic-ai'       => 'Anthropic',
		'Google-Extended'    => 'Google',
		'Googlebot-Extended' => 'Google',
		'Googlebot'          => 'Google',
		'GoogleOther'        => 'Google',
		'Google-Agent'       => 'Google',
		'Google-InspectionTool' => 'Google',
		'Google-NotebookLM'  => 'Google',
		'PerplexityBot'      => 'Perplexity',
		'YouBot'             => 'You.com',
		'Applebot-Extended'  => 'Apple',
		'Bytespider'         => 'ByteDance',
		'FacebookBot'        => 'Meta',
		'meta-externalagent' => 'Meta',
		'CCBot'              => 'Common Crawl',
		'Amazonbot'          => 'Amazon',
		'Diffbot'            => 'Diffbot',
		'cohere-ai'          => 'Cohere',
		'PetalBot'           => 'Huawei',
		'Timpibot'           => 'Timpi',
		'ImagesiftBot'       => 'Imagesift',
		'omgilibot'          => 'Webz.io',
		'omgili'             => 'Webz.io',
		'AI2Bot'             => 'Allen AI',
		'Kangaroo Bot'       => 'Kangaroo',
	);

	// ── Registration ─────────────────────────────────────────────────────────

	public static function register_hooks() {
		// ⚠️ Only the *recording* is switched off. The notice and the daily prune stay
		// registered on purpose: a merchant who stops logging still has whatever was
		// logged already, and it should keep ageing out of the table rather than
		// sitting there forever because the switch that stopped new rows also
		// stopped the cleanup.
		if ( TWTAEO_Output_Control::should_write( 'crawler_log' ) ) {
			add_action( 'wp', array( __CLASS__, 'detect_and_log' ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_admin_notice' ) );

		add_action( self::CRON_HOOK, array( __CLASS__, 'prune_old_entries' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	// ── Detection ────────────────────────────────────────────────────────────

	public static function detect_and_log() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( $ua === '' ) return;

		// Bot View's self-audits impersonate crawler user agents — never log them as real visits.
		if ( isset( $_SERVER['HTTP_X_TWTAEO_BOT_VIEW'] ) ) return;

		$bot_key     = null;
		$bot_company = null;

		foreach ( self::$bots as $pattern => $company ) {
			if ( stripos( $ua, $pattern ) !== false ) {
				$bot_key     = $pattern;
				$bot_company = $company;
				break;
			}
		}

		if ( ! $bot_key ) return;

		// Resolve page title from the current WP query.
		$obj   = get_queried_object();
		if ( $obj instanceof WP_Post ) {
			$title = get_the_title( $obj );
		} elseif ( $obj instanceof WP_Term ) {
			$title = $obj->name;
		} elseif ( $obj instanceof WP_Post_Type ) {
			$title = $obj->labels->name ?? $obj->name;
		} else {
			$title = get_bloginfo( 'name' );
		}

		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$url   = esc_url_raw( home_url( $uri ) );
		$entry = array(
			'bot'     => $bot_key,
			'company' => $bot_company,
			'url'     => $url,
			'title'   => sanitize_text_field( $title ),
			'ua'      => sanitize_text_field( substr( $ua, 0, 200 ) ),
			'time'    => time(),
		);

		// Prepend to local log.
		$log = get_option( self::OPTION_LOG, array() );
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPTION_LOG, $log, false );

		// Transient for admin notice (1 hour TTL so it shows on next admin load).
		set_transient( self::NOTICE_TRANS, $entry, HOUR_IN_SECONDS );

		// Ping the pro dashboard.
		if ( class_exists( 'TWTAEO_Pro_Transmitter' ) ) {
			TWTAEO_Pro_Transmitter::send_ai_bot_event( $bot_key, $url, $bot_company );
		}
	}

	// ── Admin Notice ─────────────────────────────────────────────────────────

	public static function maybe_show_admin_notice() {
		$entry = get_transient( self::NOTICE_TRANS );
		if ( ! $entry ) return;
		delete_transient( self::NOTICE_TRANS );

		$page     = esc_html( $entry['title'] ?? $entry['url'] );
		$bot      = esc_html( $entry['bot'] ?? 'AI Bot' );
		$company  = esc_html( $entry['company'] ?? '' );
		$tab_url  = esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'ai-crawler' ), admin_url( 'admin.php' ) ) );

		echo '<div class="notice notice-info is-dismissible">';
		echo '<p>';
		printf(
			/* translators: 1: bot name / company, 2: page name, 3: link */
			esc_html__( 'AI Crawler Watch: %1$s crawled "%2$s". %3$s', 'twt-aeo-ultimate' ),
			'<strong>' . ( $company ? esc_html( "$company ($bot)" ) : esc_html( $bot ) ) . '</strong>',
			esc_html( $page ),
			'<a href="' . esc_url( $tab_url ) . '">' . esc_html__( 'View AI Crawler Watch →', 'twt-aeo-ultimate' ) . '</a>'
		);
		echo '</p></div>';
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	public static function prune_old_entries() {
		$log = get_option( self::OPTION_LOG, array() );

		// When connected to the pro dashboard, the permanent record lives there —
		// keep only 48 hours locally so the live feed still shows recent activity
		// without accumulating long-term bloat on the client site.
		// Standalone installs (no pro) keep 30 days.
		$retention = ( class_exists( 'TWTAEO_Pro_Transmitter' ) && TWTAEO_Pro_Transmitter::is_connected() )
			? 2 * DAY_IN_SECONDS
			: 30 * DAY_IN_SECONDS;

		$threshold = time() - $retention;
		$log       = array_values( array_filter( $log, function( $e ) use ( $threshold ) {
			return isset( $e['time'] ) && $e['time'] >= $threshold;
		} ) );
		update_option( self::OPTION_LOG, $log, false );
	}

	// ── Data Accessors ───────────────────────────────────────────────────────

	public static function get_log( $limit = 50 ) {
		$log = get_option( self::OPTION_LOG, array() );
		return array_slice( $log, 0, $limit );
	}

	public static function get_stats() {
		$log    = get_option( self::OPTION_LOG, array() );
		$by_bot = array();
		$by_url = array();

		foreach ( $log as $entry ) {
			$bot = $entry['bot'] ?? 'Unknown';
			$url = $entry['url'] ?? '';

			if ( ! isset( $by_bot[ $bot ] ) ) {
				$by_bot[ $bot ] = array( 'count' => 0, 'company' => $entry['company'] ?? '', 'last' => 0 );
			}
			$by_bot[ $bot ]['count']++;
			if ( ( $entry['time'] ?? 0 ) > $by_bot[ $bot ]['last'] ) {
				$by_bot[ $bot ]['last'] = $entry['time'];
			}

			if ( $url ) {
				if ( ! isset( $by_url[ $url ] ) ) {
					$by_url[ $url ] = array( 'count' => 0, 'title' => $entry['title'] ?? $url, 'url' => $url );
				}
				$by_url[ $url ]['count']++;
			}
		}

		uasort( $by_bot, function( $a, $b ) { return $b['count'] - $a['count']; } );
		usort( $by_url, function( $a, $b ) { return $b['count'] - $a['count']; } );

		return array(
			'total'      => count( $log ),
			'by_bot'     => $by_bot,
			'top_pages'  => array_slice( $by_url, 0, 10 ),
			'last_visit' => isset( $log[0]['time'] ) ? $log[0]['time'] : null,
		);
	}

	public static function clear_log() {
		delete_option( self::OPTION_LOG );
	}

	/**
	 * Count AI bot hits against the three machine-readable discovery files
	 * over the past 30 days (regardless of local retention setting).
	 *
	 * Returns array keyed by display slug:
	 *   hits  (int)        — number of bot requests logged
	 *   last  (int|null)   — Unix timestamp of most recent hit, or null
	 *   url   (string)     — the canonical URL for the file
	 */
	public static function get_file_hits() {
		$log    = get_option( self::OPTION_LOG, array() );
		$cutoff = time() - ( 30 * DAY_IN_SECONDS );

		$targets = array(
			'robots.txt'    => '/robots.txt',
			'llms.txt'      => '/llms.txt',
			'llms-full.txt' => '/llms-full.txt',
		);

		$results = array();
		foreach ( $targets as $slug => $path ) {
			$results[ $slug ] = array(
				'hits' => 0,
				'last' => null,
				'url'  => home_url( $path ),
			);
		}

		foreach ( $log as $entry ) {
			if ( empty( $entry['time'] ) || $entry['time'] < $cutoff ) {
				continue;
			}

			$entry_path = wp_parse_url( $entry['url'] ?? '', PHP_URL_PATH );
			// Normalise: strip trailing slash, lower-case.
			$entry_path = rtrim( strtolower( $entry_path ), '/' );

			foreach ( $targets as $slug => $path ) {
				if ( $entry_path === strtolower( $path ) ) {
					$results[ $slug ]['hits']++;
					if ( $results[ $slug ]['last'] === null || $entry['time'] > $results[ $slug ]['last'] ) {
						$results[ $slug ]['last'] = $entry['time'];
					}
				}
			}
		}

		return $results;
	}
}
