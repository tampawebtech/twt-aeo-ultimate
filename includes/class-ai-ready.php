<?php
/**
 * AI Ready Module
 *
 * Handles all frontend output and REST endpoints for the AI Ready feature set:
 * Markdown negotiation, llms.txt, Content-Signal headers, Semantic Breadcrumbs,
 * Agent Search API, API Catalog (RFC 9727), MCP manifest, rate limiting, and
 * OAuth Protected Resource Metadata (RFC 9728).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_AI_Ready {

	const OPTION_KEY    = 'twtaeo_ai_ready';
	const NONCE_ACTION  = 'twtaeo_ai_ready_save';
	const NONCE_NAME    = 'twtaeo_ai_ready_nonce';
	const NONCE_INJECT  = 'twtaeo_inject_robots';
	const NONCE_DIAGNOSE = 'twtaeo_diagnose_server';

	// ── Site root path helper ────────────────────────────────────────────────

	/**
	 * Returns the absolute filesystem path to the site root with no trailing
	 * separator.
	 *
	 * RFC 8615 mandates that /.well-known/ URIs resolve from the origin root,
	 * and llms.txt must live at the document root. Neither can be placed in the
	 * uploads directory or the plugin directory. ABSPATH is the correct WordPress
	 * constant for the WordPress installation root and is the only reliable way
	 * to locate the origin root on a standard WordPress install.
	 *
	 * All references to ABSPATH in this class are centralised here so the path
	 * strategy can be updated in one place if needed.
	 *
	 * @return string Absolute path to site root, no trailing slash.
	 */
	private static function get_site_root() {
		return rtrim( ABSPATH, '/\\' ); // ABSPATH is the only reliable way to locate the origin root — see docblock above.
	}

	/**
	 * Public accessor for the site root path — used by the admin page to
	 * display the path in diagnostic UI.
	 */
	public static function get_wellknown_base_path() {
		return self::get_site_root();
	}

	// ── Defaults ─────────────────────────────────────────────────────────────

	public static function defaults() {
		return array(
			// Zero-footprint.
			'markdown_negotiation'    => 1,
			'url_fallback'            => 1,
			'llms_txt'                => 1,
			'agent_skills_index'      => 1,
			'content_signal_ai_train' => 'no',
			'content_signal_search'   => 'yes',
			'content_signal_ai_input' => 'no',
			'robots_bot_rules'        => 0,
			'semantic_breadcrumbs'    => 1,
			'vary_header'             => 1,
			// Advanced.
			'agent_search_api'        => 0,
			'api_catalog'             => 0,
			'mcp_integration'         => 0,
			// Security.
			'rate_limit_enabled'      => 1,
			'rate_limit_max'          => 60,
			'bot_rules'               => array(),
			'ip_whitelist_enabled'    => 0,
			'ip_whitelist'            => '',
			'user_agent_verification' => 0,
			'identity_verification'   => 0,
			'protected_resource_meta' => 0,
			// OAuth / OIDC Discovery.
			'oauth_discovery'         => 0,
			'oauth_mode'              => 'builtin', // 'builtin' | 'external'
			'oauth_issuer'            => '',
			'oauth_auth_endpoint'     => '',
			'oauth_token_endpoint'    => '',
			'oauth_jwks_uri'          => '',
			'oauth_userinfo_endpoint' => '',
			'oauth_grant_types'       => 'authorization_code,client_credentials',
			'oauth_scopes'            => 'read,write',
			'oauth_oidc'              => 0,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	// ── Known bot registry ────────────────────────────────────────────────────

	public static function get_known_bots() {
		return array(
			// OpenAI
			'GPTBot'               => array( 'company' => 'OpenAI',       'desc' => 'Training crawler' ),
			'ChatGPT-User'         => array( 'company' => 'OpenAI',       'desc' => 'Browsing agent' ),
			'OAI-SearchBot'        => array( 'company' => 'OpenAI',       'desc' => 'Search indexer' ),
			// Anthropic
			'ClaudeBot'            => array( 'company' => 'Anthropic',    'desc' => 'Claude.ai search' ),
			'Claude-Web'           => array( 'company' => 'Anthropic',    'desc' => 'Claude web browsing' ),
			'anthropic-ai'         => array( 'company' => 'Anthropic',    'desc' => 'Training crawler' ),
			// Google
			'Google-Extended'        => array( 'company' => 'Google', 'desc' => 'Gemini AI training' ),
			'Googlebot-Extended'     => array( 'company' => 'Google', 'desc' => 'AI search indexer' ),
			'Googlebot'              => array( 'company' => 'Google', 'desc' => 'Search Core — standard index & Discover' ),
			'GoogleOther'            => array( 'company' => 'Google', 'desc' => 'AI content feeder — Gemini training & R&D' ),
			'Google-Agent'           => array( 'company' => 'Google', 'desc' => 'Gemini live browser agent (Project Mariner)' ),
			'Google-InspectionTool'  => array( 'company' => 'Google', 'desc' => 'Search Console URL Inspection tool' ),
			'Google-NotebookLM'      => array( 'company' => 'Google', 'desc' => 'NotebookLM research assistant' ),
			// Meta
			'Meta-ExternalAgent'   => array( 'company' => 'Meta',         'desc' => 'Meta AI agent (2024+)' ),
			'Meta-ExternalFetcher' => array( 'company' => 'Meta',         'desc' => 'Meta AI fetcher (2024+)' ),
			'FacebookBot'          => array( 'company' => 'Meta',         'desc' => 'Meta AI training' ),
			// Apple
			'Applebot'             => array( 'company' => 'Apple',        'desc' => 'Siri / Apple Intelligence' ),
			'Applebot-Extended'    => array( 'company' => 'Apple',        'desc' => 'Apple AI training' ),
			// Perplexity
			'PerplexityBot'        => array( 'company' => 'Perplexity',   'desc' => 'AI search indexer' ),
			// You.com
			'YouBot'               => array( 'company' => 'You.com',      'desc' => 'AI search indexer' ),
			// ByteDance
			'Bytespider'           => array( 'company' => 'ByteDance',    'desc' => 'TikTok AI / training' ),
			// Common Crawl
			'CCBot'                => array( 'company' => 'Common Crawl', 'desc' => 'LLM training datasets' ),
			// Amazon
			'AmazonBot'            => array( 'company' => 'Amazon',       'desc' => 'Alexa AI / training' ),
			// Diffbot
			'Diffbot'              => array( 'company' => 'Diffbot',      'desc' => 'AI knowledge graph' ),
			// Cohere
			'cohere-ai'            => array( 'company' => 'Cohere',       'desc' => 'AI training crawler' ),
			// Huawei
			'PetalBot'             => array( 'company' => 'Huawei',       'desc' => 'AI indexer' ),
			// Webz.io
			'Omgilibot'            => array( 'company' => 'Webz.io',      'desc' => 'Training dataset collector' ),
			// iAsk
			'iaskspider'           => array( 'company' => 'iAsk.ai',      'desc' => 'AI search' ),
		);
	}

	public static function identify_bot( $ua ) {
		if ( empty( $ua ) ) {
			return null;
		}
		$ua_lower = strtolower( $ua );
		foreach ( self::get_known_bots() as $slug => $info ) {
			if ( strpos( $ua_lower, strtolower( $slug ) ) !== false ) {
				return $slug;
			}
		}
		return null;
	}

	// ── Hook registration ─────────────────────────────────────────────────────

	public static function on_init() {
		$s = self::get_settings();

		// Rewrite rules — must be called on init.
		// Trailing /? makes each rule match with or without a trailing slash so
		// WordPress's "add trailing slash" permalink setting doesn't 301-redirect
		// these file-like URLs to a slash version that then 404s.
		if ( ! empty( $s['llms_txt'] ) ) {
			add_rewrite_rule( '^llms\.txt/?$', 'index.php?twtaeo_llms_txt=1', 'top' );
		}

		// These .well-known endpoints are always registered — their content is safe
		// to expose publicly (public keys, catalog of public APIs).
		add_rewrite_rule( '^\.well-known/http-message-signatures-directory/?$', 'index.php?twtaeo_wellknown=signatures-directory', 'top' );
		add_rewrite_rule( '^\.well-known/api-catalog/?$', 'index.php?twtaeo_wellknown=api-catalog', 'top' );

		// Serve SKILL.md files for each agent-skill slug via WP rewrites — no
		// physical files are written to disk for these (DB-driven virtual serving).
		add_rewrite_rule(
			'^\.well-known/agent-skills/([^/]+)/SKILL\.md/?$',
			'index.php?twtaeo_wellknown=agent-skill&twtaeo_skill=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/agent-skills/index\.json/?$',
			'index.php?twtaeo_wellknown=agent-skills-index',
			'top'
		);

		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_wellknown' ) );

		$needs_wellknown = ! empty( $s['mcp_integration'] )
			|| ! empty( $s['protected_resource_meta'] )
			|| ! empty( $s['oauth_discovery'] );

		if ( $needs_wellknown ) {
			add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$', 'index.php?twtaeo_wellknown=oauth-protected-resource', 'top' );
			add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?twtaeo_wellknown=oauth-authorization-server', 'top' );
			add_rewrite_rule( '^\.well-known/openid-configuration/?$', 'index.php?twtaeo_wellknown=openid-configuration', 'top' );
			add_rewrite_rule( '^\.well-known/mcp/server-card\.json/?$', 'index.php?twtaeo_wellknown=mcp-server-card', 'top' );
		}

		// Prevent WordPress from appending a trailing slash to file-like URLs
		// (e.g. /llms.txt → /llms.txt/) when the permalink structure requires slashes.
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_txt_trailing_slash' ), 10, 2 );

		// Auto-clear flush flag on plugin version change so .htaccess is regenerated
		// on the first page load after any plugin update.
		if ( get_option( 'twtaeo_air_version', '' ) !== TWTAEO_VERSION ) {
			update_option( 'twtaeo_air_version', TWTAEO_VERSION, false );
			delete_option( 'twtaeo_air_rules_flushed' );

			// Remove the .htaccess we mistakenly wrote inside .well-known/ in v1.0.2.
			// Apache denies the entire directory when it cannot read a .htaccess there.
			$bad_htaccess = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . '.htaccess';
			if ( file_exists( $bad_htaccess ) ) {
				wp_delete_file( $bad_htaccess );
			}
		}

		// One-time flush: fires at shutdown on the very first request after activation
		// (or if the flag was cleared after a settings change). Runs at shutdown so it
		// doesn't block the current response.
		if ( ! get_option( 'twtaeo_air_rules_flushed' ) ) {
			update_option( 'twtaeo_air_rules_flushed', '1', false );
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}

		// Inject explicit .well-known pass-through into .htaccess so the rewrite fires
		// even when a physical .well-known/ directory exists on disk (bypasses !-d check).
		add_filter( 'mod_rewrite_rules', array( __CLASS__, 'inject_wellknown_htaccess_rules' ) );

		// Auto-repair: regenerate the physical api-catalog file if missing — always,
		// since the endpoint is always on (the catalog lists the site's public APIs).
		$catalog_path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'api-catalog';
		if ( ! file_exists( $catalog_path ) ) {
			add_action( 'shutdown', array( __CLASS__, 'write_api_catalog_file' ) );
		}

		// Auto-repair: if Agent Skills Index is enabled but the file is missing,
		// regenerate it silently at shutdown.
		if ( ! empty( $s['agent_skills_index'] ) ) {
			$skills_path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'agent-skills' . DIRECTORY_SEPARATOR . 'index.json';
			if ( ! file_exists( $skills_path ) ) {
				add_action( 'shutdown', array( __CLASS__, 'write_agent_skills_index' ) );
			}
		}

		// Auto-repair: if OAuth Discovery is enabled but the physical file is missing,
		// regenerate it silently at shutdown.
		if ( ! empty( $s['oauth_discovery'] ) ) {
			$oauth_path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'oauth-authorization-server';
			if ( ! file_exists( $oauth_path ) ) {
				add_action( 'shutdown', array( __CLASS__, 'write_oauth_discovery_files' ) );
			}
		}

		// Auto-repair: if MCP integration is enabled but the server card file is missing,
		// regenerate it silently at shutdown.
		if ( ! empty( $s['mcp_integration'] ) ) {
			$card_path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . 'server-card.json';
			if ( ! file_exists( $card_path ) ) {
				add_action( 'shutdown', array( __CLASS__, 'write_mcp_server_card_file' ) );
			}
		}

		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Vary header fires on every frontend page.
		if ( ! empty( $s['vary_header'] ) || ! empty( $s['markdown_negotiation'] ) ) {
			add_action( 'send_headers', array( __CLASS__, 'send_vary_header' ) );
		}

		// Content-Signal fires on every frontend page (even if both are 'unspecified',
		// the handler will simply output nothing).
		add_action( 'send_headers', array( __CLASS__, 'send_content_signal_headers' ) );

		// RFC 8288 Link headers for agent discovery — always on.
		add_action( 'send_headers', array( __CLASS__, 'send_link_headers' ) );

		// robots.txt — always run so we can add the Sitemap: directive plus any signals.
		add_filter( 'robots_txt', array( __CLASS__, 'append_robots_txt' ), 10, 1 );

		// Keep the dynamic robots.txt out of any full-page cache (host cache / CDN)
		// so AI-crawler hits to it run through PHP and get logged. Plugin-agnostic:
		// do_robots fires for any virtual robots.txt (WP core, Yoast, Rank Math,
		// AIOSEO, SEOPress). No effect when a physical robots.txt is served off disk.
		add_action( 'do_robots', array( __CLASS__, 'nocache_robots' ), 0 );

		// Per-bot block & rate-limit enforcement for regular page requests.
		// Priority 1: before Yoast, WP core, and any other template_redirect handlers.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_enforce_bot_rules' ), 1 );

		// /sitemap.xml — register a virtual endpoint that redirects to the real sitemap
		// (or serves a fallback) unless a physical sitemap.xml already exists on disk.
		if ( ! file_exists( self::get_site_root() . DIRECTORY_SEPARATOR . 'sitemap.xml' ) ) {
			add_rewrite_rule( '^sitemap\.xml/?$', 'index.php?twtaeo_sitemap=1', 'top' );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_sitemap_xml' ) );
		}

		if ( ! empty( $s['markdown_negotiation'] ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_markdown' ), 1 );
		}

		if ( ! empty( $s['llms_txt'] ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_llms_txt' ) );
		}

		if ( ! empty( $s['semantic_breadcrumbs'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'output_semantic_breadcrumbs' ) );
		}



		// WebMCP: browser-side navigator.modelContext.provideContext() injection.
		if ( ! empty( $s['mcp_integration'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_webmcp_script' ), 99 );
		}
	}

	// ── .htaccess pass-through ────────────────────────────────────────────────

	public static function inject_wellknown_htaccess_rules( $rules ) {
		// These rules sit BEFORE the standard WordPress "RewriteCond !-d" block so
		// .well-known paths reach PHP even when the directory exists on disk.
		//
		// Each rule has its own !-f condition: if a physical file already exists
		// (written by write_api_catalog_file / write_agent_skills_index), the server
		// serves it directly and the rewrite never fires. The PHP fallback only
		// activates when the physical file is absent.
		$extra  = "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/api-catalog$ /index.php?twtaeo_wellknown=api-catalog [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/oauth-protected-resource$ /index.php?twtaeo_wellknown=oauth-protected-resource [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/http-message-signatures-directory$ /index.php?twtaeo_wellknown=signatures-directory [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/oauth-authorization-server$ /index.php?twtaeo_wellknown=oauth-authorization-server [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/openid-configuration$ /index.php?twtaeo_wellknown=openid-configuration [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/mcp/server-card\.json$ /index.php?twtaeo_wellknown=mcp-server-card [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/agent-skills/([^/]+)/SKILL\.md$ /index.php?twtaeo_wellknown=agent-skill&twtaeo_skill=$1 [QSA,L]\n";
		$extra .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$extra .= "RewriteRule ^\.well-known/agent-skills/index\.json$ /index.php?twtaeo_wellknown=agent-skills-index [QSA,L]\n";
		return $extra . $rules;
	}

	// ── Trailing-slash redirect prevention ───────────────────────────────────

	public static function prevent_txt_trailing_slash( $redirect_url, $requested_url ) {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		// Block canonical redirects for our file-like virtual URLs.
		$no_slash = array( 'llms.txt', 'sitemap.xml', 'robots.txt' );
		foreach ( $no_slash as $file ) {
			if ( substr( $path, -strlen( $file ) ) === $file ) {
				return false;
			}
		}
		// Also block for anything under /.well-known/
		if ( strpos( $path, '/.well-known/' ) !== false ) {
			return false;
		}
		return $redirect_url;
	}

	// ── Query vars ────────────────────────────────────────────────────────────

	public static function add_query_vars( $vars ) {
		$vars[] = 'twtaeo_llms_txt';
		$vars[] = 'twtaeo_wellknown';
		$vars[] = 'twtaeo_skill';
		$vars[] = 'twtaeo_sitemap';
		// Public Markdown content-negotiation flag (?aeo_format=markdown). Registered
		// as a query var so it is read via get_query_var() rather than the raw $_GET
		// superglobal — see maybe_serve_markdown().
		$vars[] = 'aeo_format';
		return $vars;
	}

	// ── Vary: Accept header ───────────────────────────────────────────────────

	public static function send_vary_header() {
		if ( ! headers_sent() ) {
			header( 'Vary: Accept', false );
		}
	}

	// ── Content-Signal headers ────────────────────────────────────────────────

	public static function send_content_signal_headers() {
		if ( headers_sent() ) {
			return;
		}
		$s     = self::get_settings();
		$parts = array();

		if ( $s['content_signal_ai_train'] !== 'unspecified' ) {
			$parts[] = 'ai-train=' . $s['content_signal_ai_train'];
		}
		if ( $s['content_signal_search'] !== 'unspecified' ) {
			$parts[] = 'search=' . $s['content_signal_search'];
		}
		if ( $s['content_signal_ai_input'] !== 'unspecified' ) {
			$parts[] = 'ai-input=' . $s['content_signal_ai_input'];
		}

		if ( ! empty( $parts ) ) {
			header( 'Content-Signal: ' . implode( ', ', $parts ) );
		}
	}

	// ── RFC 8288 Link headers ─────────────────────────────────────────────────

	public static function send_link_headers() {
		if ( headers_sent() ) {
			return;
		}

		$s     = self::get_settings();
		$links = array();

		// Sitemap — always useful; crawlers and agents use this to enumerate pages.
		$sitemap = self::get_sitemap_url();
		if ( ! $sitemap ) {
			$sitemap = home_url( '/sitemap.xml' );
		}
		$links[] = '<' . esc_url_raw( $sitemap ) . '>; rel="sitemap"';

		// llms.txt — machine-readable site overview (rel="describedby" per RFC 8288).
		if ( ! empty( $s['llms_txt'] ) ) {
			$links[] = '<' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"; type="text/plain"';
		}

		// API Catalog (RFC 9727 — IANA registered "api-catalog" relation; always on).
		$links[] = '<' . esc_url_raw( home_url( '/.well-known/api-catalog' ) ) . '>; rel="api-catalog"';

		// Agent Skills Index.
		if ( ! empty( $s['agent_skills_index'] ) ) {
			$links[] = '<' . esc_url_raw( home_url( '/.well-known/agent-skills/index.json' ) ) . '>; rel="https://isitagentready.com/rel/agent-skills"';
		}

		// MCP server card.
		if ( ! empty( $s['mcp_integration'] ) ) {
			$links[] = '<' . esc_url_raw( home_url( '/.well-known/mcp/server-card.json' ) ) . '>; rel="mcp-server-card"';
		}

		// Web Bot Auth JWKS directory — always present (endpoint is always on).
		$links[] = '<' . esc_url_raw( home_url( '/.well-known/http-message-signatures-directory' ) ) . '>; rel="https://datatracker.ietf.org/doc/html/draft-ietf-webbotauth"';

		// OAuth/OIDC discovery (RFC 9728).
		if ( ! empty( $s['oauth_discovery'] ) ) {
			$links[] = '<' . esc_url_raw( home_url( '/.well-known/oauth-authorization-server' ) ) . '>; rel="oauth-authorization-server"';
		}

		foreach ( $links as $link ) {
			// false = don't replace; RFC 8288 allows multiple Link headers.
			header( 'Link: ' . $link, false );
		}
	}

	// ── robots.txt AI directives ─────────────────────────────────────────────

	public static function append_robots_txt( $output ) {
		$s = self::get_settings();

		// Training bots — crawlers that use content to train AI models.
		$training_bots = array(
			'GPTBot',            // OpenAI training
			'Google-Extended',   // Google Gemini training
			'CCBot',             // Common Crawl (widely used for LLM training)
			'anthropic-ai',      // Anthropic training crawler
			'Omgilibot',         // Webz.io / used for training datasets
			'FacebookBot',       // Meta AI training
			'Applebot-Extended', // Apple AI training
		);

		// Search/indexing bots — crawlers that power AI search and citations.
		$search_bots = array(
			'ClaudeBot',     // Anthropic — Claude.ai search
			'PerplexityBot', // Perplexity AI search
			'YouBot',        // You.com AI search
		);

		// Build the Content-Signal directive value (draft-romm-aipref-contentsignals).
		$parts = array();
		if ( $s['content_signal_ai_train'] !== 'unspecified' ) {
			$parts[] = 'ai-train=' . $s['content_signal_ai_train'];
		}
		if ( $s['content_signal_search'] !== 'unspecified' ) {
			$parts[] = 'search=' . $s['content_signal_search'];
		}
		if ( $s['content_signal_ai_input'] !== 'unspecified' ) {
			$parts[] = 'ai-input=' . $s['content_signal_ai_input'];
		}

		$lines = array( '', '# AEO Content Signals (https://contentsignals.org/)' );

		// The Content-Signal directive goes under User-agent: * as a global preference
		// declaration. It does not block or allow any crawlers on its own.
		if ( ! empty( $parts ) ) {
			$lines[] = '';
			$lines[] = 'User-agent: *';
			$lines[] = 'Content-Signal: ' . implode( ', ', $parts );
		}

		// Per-bot Disallow/Allow rules — only written when the user has explicitly
		// opted in. Off by default to avoid conflicting with Yoast / Rank Math / etc.
		if ( ! empty( $s['robots_bot_rules'] ) ) {
			if ( $s['content_signal_ai_train'] !== 'unspecified' ) {
				$directive = $s['content_signal_ai_train'] === 'no' ? 'Disallow: /' : 'Allow: /';
				foreach ( $training_bots as $bot ) {
					$lines[] = '';
					$lines[] = 'User-agent: ' . $bot;
					$lines[] = $directive;
				}
			}

			if ( $s['content_signal_search'] !== 'unspecified' ) {
				$directive = $s['content_signal_search'] === 'no' ? 'Disallow: /' : 'Allow: /';
				foreach ( $search_bots as $bot ) {
					$lines[] = '';
					$lines[] = 'User-agent: ' . $bot;
					$lines[] = $directive;
				}
			}
		}

		// Per-bot explicit blocks — always written regardless of robots_bot_rules setting.
		// These mirror the PHP-level 403 so well-behaved bots get the hint first.
		$bot_rules_map = $s['bot_rules'] ?? array();
		foreach ( $bot_rules_map as $bot_slug => $rule ) {
			if ( ( $rule['status'] ?? 'allow' ) === 'block' ) {
				$lines[] = '';
				$lines[] = 'User-agent: ' . $bot_slug;
				$lines[] = 'Disallow: /';
			}
		}

		// Sitemap: directive — only add if robots.txt doesn't already have one.
		if ( stripos( $output, 'Sitemap:' ) === false && stripos( implode( "\n", $lines ), 'Sitemap:' ) === false ) {
			$sitemap_url = self::get_sitemap_url();
			if ( ! $sitemap_url ) {
				// Our virtual endpoint will serve or redirect at /sitemap.xml.
				$sitemap_url = home_url( '/sitemap.xml' );
			}
			$lines[] = '';
			$lines[] = 'Sitemap: ' . $sitemap_url;
		}

		// Only output the block when there is something to add.
		$block = implode( "\n", $lines );
		if ( trim( $block ) === trim( '# AEO Content Signals (https://contentsignals.org/)' ) ) {
			return $output;
		}

		return $output . $block . "\n";
	}

	// ── Sitemap detection & fallback ──────────────────────────────────────────

	public static function get_sitemap_url() {
		// 1. Physical files — served directly by the web server, most authoritative.
		foreach ( array( 'sitemap.xml', 'sitemap_index.xml', 'wp-sitemap.xml' ) as $file ) {
			if ( file_exists( self::get_site_root() . DIRECTORY_SEPARATOR . $file ) ) {
				return home_url( '/' . $file );
			}
		}

		// 2. SEO plugins that generate a dynamic sitemap (no physical file on disk).
		$seo = self::detect_seo_plugins();
		if ( in_array( 'rankmath', $seo, true ) || in_array( 'yoast', $seo, true ) ) {
			return home_url( '/sitemap_index.xml' );
		}

		// 3. WordPress core sitemaps (built-in since WP 5.5).
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled() ) {
				return home_url( '/wp-sitemap.xml' );
			}
		}

		return null;
	}

	public static function maybe_serve_sitemap_xml() {
		if ( ! get_query_var( 'twtaeo_sitemap' ) ) {
			return;
		}

		$existing = self::get_sitemap_url();

		if ( $existing ) {
			// Serve a sitemapindex document pointing to the real sitemap.
			// Checkers like isitagentready.com don't follow 301 redirects, so we
			// serve valid XML directly with a 200 rather than redirecting.
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			echo "\t<sitemap>\n";
			echo "\t\t<loc>" . esc_url( $existing ) . "</loc>\n";
			echo "\t\t<lastmod>" . esc_html( current_time( 'Y-m-d' ) ) . "</lastmod>\n";
			echo "\t</sitemap>\n";
			echo '</sitemapindex>';
			exit;
		}

		// No sitemap found anywhere — generate a basic one from published content.
		self::serve_fallback_sitemap();
	}

	private static function serve_fallback_sitemap() {
		$urls = array(
			array(
				'loc'        => home_url( '/' ),
				'lastmod'    => current_time( 'Y-m-d' ),
				'changefreq' => 'daily',
				'priority'   => '1.0',
			),
		);

		$query = new WP_Query( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		foreach ( $query->posts as $post_id ) {
			$urls[] = array(
				'loc'     => get_permalink( $post_id ),
				'lastmod' => get_the_modified_date( 'Y-m-d', $post_id ),
			);
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		// Static XML literals served as application/xml. The dynamic <loc>/<lastmod>/etc.
		// values in the loop below are individually escaped (esc_url(), esc_html()), so
		// the document is safe; these two lines contain no variables.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static XML literal; dynamic values escaped below.
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $entry ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n"; // phpcs:ignore
			if ( ! empty( $entry['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n"; // phpcs:ignore
			}
			if ( ! empty( $entry['changefreq'] ) ) {
				echo "\t\t<changefreq>" . esc_html( $entry['changefreq'] ) . "</changefreq>\n"; // phpcs:ignore
			}
			if ( ! empty( $entry['priority'] ) ) {
				echo "\t\t<priority>" . esc_html( $entry['priority'] ) . "</priority>\n"; // phpcs:ignore
			}
			echo "\t</url>\n";
		}

		echo '</urlset>';
		exit;
	}

	// ── Markdown content negotiation ──────────────────────────────────────────

	public static function maybe_serve_markdown() {
		// Public, read-only content negotiation on `template_redirect`. This runs for
		// anonymous front-end requests (AI agents and crawlers): nothing here writes
		// data, changes state, or performs a privileged action, so there is no privilege
		// boundary and no nonce applies (a nonce cannot be issued to an unauthenticated
		// bot, which would defeat the feature). The two untrusted inputs only select an
		// output *format* for content that is already publicly visible:
		//   • the Accept header, sanitized below; and
		//   • the `aeo_format` query value, registered as a query var (see
		//     add_query_vars()) so it is read through get_query_var() rather than the
		//     raw $_GET superglobal, then sanitized and compared to a fixed literal.
		$s          = self::get_settings();
		$accept     = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		$via_accept = strpos( $accept, 'text/markdown' ) !== false;
		$format     = sanitize_key( get_query_var( 'aeo_format' ) );
		$via_param  = ! empty( $s['url_fallback'] ) && 'markdown' === $format;

		if ( ! $via_accept && ! $via_param ) {
			return;
		}

		// Singular posts/pages — convert post content directly.
		if ( is_singular() ) {
			global $post;
			if ( ! $post ) {
				return;
			}

			// Render through WordPress's own the_content filter (shortcodes,
			// blocks, embeds). Hook name held in a variable — it is core's, not
			// ours to prefix.
			$core_filter = 'the_content';
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hook, not ours to prefix.
			$content     = apply_filters( $core_filter, $post->post_content );
			$markdown    = TWTAEO_HTML_To_Markdown::convert( $content );
			$title       = get_the_title( $post );
			$url         = get_permalink( $post );

			$output  = '# ' . $title . "\n\n";
			$output .= 'Source: ' . $url . "\n\n";
			$output .= $markdown;

			self::output_markdown_response( $output );
		}

		// Homepage — either a static front page (caught above as singular) or the
		// blog index. Produce a structured post listing so agents can orient themselves.
		if ( is_home() || is_front_page() ) {
			$site_name = get_bloginfo( 'name' );
			$tagline   = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$output  = '# ' . $site_name . "\n\n";
			if ( $tagline ) {
				$output .= '> ' . $tagline . "\n\n";
			}
			$output .= 'URL: ' . home_url( '/' ) . "\n\n";
			$output .= "## Recent Posts\n\n";

			$query = new WP_Query( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			) );

			while ( $query->have_posts() ) {
				$query->the_post();
				$output .= '### [' . get_the_title() . '](' . get_permalink() . ")\n\n";
				$excerpt = get_the_excerpt();
				if ( $excerpt ) {
					$output .= wp_strip_all_tags( $excerpt ) . "\n\n";
				}
			}
			wp_reset_postdata();

			self::output_markdown_response( $output );
		}
	}

	private static function output_markdown_response( $output ) {
		// Rough token estimate: ~4 UTF-8 characters per token (English average).
		$tokens = (int) ceil( mb_strlen( $output ) / 4 );

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Markdown-Tokens: ' . absint( $tokens ) );
		// Raw Markdown output — not HTML; escaping would corrupt the document.
		// $output is built entirely from post content already stored in the DB.
		echo wp_kses_post( $output );
		exit;
	}

	// ── llms.txt ──────────────────────────────────────────────────────────────

	public static function maybe_serve_llms_txt() {
		if ( ! get_query_var( 'twtaeo_llms_txt' ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$tagline   = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$output = '# ' . $site_name . "\n\n";

		// Blockquote description — required by the llms.txt spec as the first thing
		// after the H1. Set your tagline under Settings → General for a real description.
		$description = $tagline ?: ( $site_name . ' — ' . home_url() );
		$output .= '> ' . $description . "\n\n";

		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( ! empty( $posts ) ) {
			$output .= "## Pages\n\n";
			foreach ( $posts as $p ) {
				$title   = html_entity_decode( get_the_title( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$excerpt = self::clean_llms_excerpt( $p, $title );
				$output .= '- [' . $title . '](' . get_permalink( $p ) . ')';
				if ( $excerpt ) {
					$output .= ': ' . $excerpt;
				}
				$output .= "\n";
			}
		}

		// WooCommerce: point AI shopping agents at the shop and product categories,
		// and declare that the store publishes structured product data.
		if ( class_exists( 'WooCommerce' ) ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
			$cats    = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 50,
			) );
			$has_cats = is_array( $cats ) && ! empty( $cats ) && ! is_wp_error( $cats );

			if ( ( $shop_id && $shop_id > 0 ) || $has_cats ) {
				$output .= "\n## Products\n\n";
				$output .= "This store publishes Schema.org Product structured data (JSON-LD) for AI shopping agents.\n";
				if ( $shop_id && $shop_id > 0 ) {
					$output .= '- [Shop](' . get_permalink( $shop_id ) . ")\n";
				}
				if ( $has_cats ) {
					foreach ( $cats as $t ) {
						$link = get_term_link( $t );
						if ( ! is_wp_error( $link ) ) {
							$output .= '- [' . html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '](' . $link . ")\n";
						}
					}
				}
			}
		}

		status_header( 200 );
		// Discovery files must run through PHP on every request so AI-crawler hits
		// are logged. Without this, a full-page cache (host cache like Nexcess
		// NxAccel, or a CDN) serves the file directly and the hit is never seen.
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo wp_kses_post( $output );
		exit;
	}

	private static function clean_llms_excerpt( WP_Post $p, $title = '' ) {
		// Prefer the manually written excerpt; fall back to post content.
		$text = $p->post_excerpt
			? $p->post_excerpt
			: strip_shortcodes( $p->post_content );

		// Strip HTML, decode entities, collapse whitespace.
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		// Remove the page title from the very start — content often echoes it.
		if ( $title && strncasecmp( $text, $title, mb_strlen( $title ) ) === 0 ) {
			$text = ltrim( mb_substr( $text, mb_strlen( $title ) ) );
		}

		// Strip leading breadcrumb / category label patterns (e.g. "AEO Research — Cross-Engine Analysis").
		$text = preg_replace( '/^[^.!?]{1,80}(?:—|–)[^.!?]{1,80}(?:\s|$)/', '', $text );
		$text = trim( $text );

		// Truncate to 200 characters at a word boundary.
		if ( mb_strlen( $text ) > 200 ) {
			$text = mb_substr( $text, 0, 200 );
			$last = mb_strrpos( $text, ' ' );
			if ( $last > 100 ) {
				$text = mb_substr( $text, 0, $last );
			}
		}

		// Remove trailing ellipsis / "Read more" artifacts.
		$text = rtrim( preg_replace( '/[\s.]*(\.\.\.|…|\[…\]|Read more)\s*$/i', '', $text ), ' .,—–' );

		return $text;
	}

	// ── Semantic Breadcrumbs (JSON-LD) ────────────────────────────────────────

	public static function output_semantic_breadcrumbs() {
		if ( is_front_page() || is_home() ) {
			return;
		}

		$items    = array();
		$position = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'name'     => get_bloginfo( 'name' ),
			'item'     => home_url( '/' ),
		);

		if ( is_singular() ) {
			global $post;

			if ( $post->post_parent ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $position++,
						'name'     => get_the_title( $ancestor_id ),
						'item'     => get_permalink( $ancestor_id ),
					);
				}
			}

			if ( $post->post_type === 'post' ) {
				$cats = get_the_category( $post->ID );
				if ( ! empty( $cats ) ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $position++,
						'name'     => $cats[0]->name,
						'item'     => get_category_link( $cats[0]->term_id ),
					);
				}
			}

			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => get_the_title(),
				'item'     => get_permalink(),
			);

		} elseif ( is_category() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => single_cat_title( '', false ),
				'item'     => get_category_link( get_queried_object_id() ),
			);
		} elseif ( is_archive() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => get_the_archive_title(),
				'item'     => get_permalink(),
			);
		}

		if ( count( $items ) < 2 ) {
			return;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);

		echo '<script type="application/ld+json">' // phpcs:ignore
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. '</script>' . "\n";
	}

	// ── REST API ──────────────────────────────────────────────────────────────

	public static function register_rest_routes() {
		$s = self::get_settings();

		if ( ! empty( $s['agent_search_api'] ) ) {
			register_rest_route( 'aeo/v1', '/search', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Search query',
					),
				),
			) );
		}

		if ( ! empty( $s['mcp_integration'] ) ) {
			register_rest_route( 'aeo/v1', '/mcp', array(
				'methods'             => 'GET, POST',
				'callback'            => array( __CLASS__, 'rest_mcp_endpoint' ),
				// Share the same gate as /search so MCP tool calls honour the
				// configured bot-block rules, rate limits, IP whitelist, UA
				// verification, and OAuth bearer auth instead of bypassing them.
				// This callback returns true when no restrictions are configured,
				// so the MCP server stays publicly reachable by default.
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
			) );
		}
	}

	public static function maybe_enforce_bot_rules() {
		if ( is_admin() ) {
			return;
		}

		$s = self::get_settings();

		// Skip entirely if rate limiting is off and no per-bot rules are configured.
		$bot_rules_map = $s['bot_rules'] ?? array();
		if ( empty( $s['rate_limit_enabled'] ) && empty( $bot_rules_map ) ) {
			return;
		}

		$ua      = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		$bot_key = self::identify_bot( $ua );

		if ( ! $bot_key ) {
			return;
		}

		$bot_rule = $bot_rules_map[ $bot_key ] ?? array();
		$status   = $bot_rule['status'] ?? 'allow';

		// Hard block — always enforced regardless of rate limiting setting.
		if ( $status === 'block' ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html( 'Access denied.' );
			exit;
		}

		// Per-bot rate limiting for regular page requests.
		if ( ! empty( $s['rate_limit_enabled'] ) ) {
			$global_max = max( 1, (int) $s['rate_limit_max'] );
			$bot_rl     = ( isset( $bot_rule['rate_limit'] ) && $bot_rule['rate_limit'] !== '' )
				? max( 1, (int) $bot_rule['rate_limit'] )
				: $global_max;

			$ip     = self::get_client_ip();
			$rl_key = 'twtaeo_rl_bot_' . sanitize_key( $bot_key ) . '_' . md5( $ip ) . '_' . gmdate( 'YmdH' );
			$count  = (int) get_transient( $rl_key );

			if ( $count >= $bot_rl ) {
				status_header( 429 );
				header( 'Content-Type: text/plain; charset=UTF-8' );
				header( 'Retry-After: 3600' );
				echo esc_html( 'Rate limit exceeded. Try again next hour.' );
				exit;
			}

			set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );
		}
	}

	public static function rest_permission_check( WP_REST_Request $request ) {
		$s = self::get_settings();

		// Check for a valid Bearer token issued by our built-in OAuth server.
		// Authenticated OAuth clients bypass IP whitelist and UA checks;
		// rate limiting is applied per client_id instead of per IP.
		$bearer     = self::extract_bearer_token( $request );
		$token_data = null;
		if ( $bearer && class_exists( 'TWTAEO_OAuth_Server' ) ) {
			$token_data = TWTAEO_OAuth_Server::verify_bearer( $bearer );
		}

		// Per-bot rules — check before global rate limiting so a blocked bot
		// gets a 403 even if it hasn't yet hit the rate limit.
		$bot_rules_map = $s['bot_rules'] ?? array();
		$ua            = $request->get_header( 'user-agent' ) ?? '';
		$bot_key       = self::identify_bot( $ua );

		if ( $bot_key ) {
			$bot_rule = $bot_rules_map[ $bot_key ] ?? array();
			if ( ( $bot_rule['status'] ?? 'allow' ) === 'block' ) {
				return new WP_Error( 'forbidden', 'Access denied.', array( 'status' => 403 ) );
			}
		}

		// Rate limiting — per-bot override takes precedence over the global max.
		if ( ! empty( $s['rate_limit_enabled'] ) ) {
			$rl_key = $token_data
				? 'twtaeo_rl_c_' . md5( $token_data['client_id'] ) . '_' . gmdate( 'YmdH' )
				: 'twtaeo_rl_' . md5( self::get_client_ip() ) . '_' . gmdate( 'YmdH' );

			$count      = (int) get_transient( $rl_key );
			$global_max = max( 1, (int) $s['rate_limit_max'] );
			$max        = $global_max;

			if ( $bot_key && isset( $bot_rules_map[ $bot_key ]['rate_limit'] ) && $bot_rules_map[ $bot_key ]['rate_limit'] !== '' ) {
				$max = max( 1, (int) $bot_rules_map[ $bot_key ]['rate_limit'] );
			}

			if ( $count >= $max ) {
				return new WP_Error(
					'rate_limited',
					'Rate limit exceeded. Try again next hour.',
					array( 'status' => 429 )
				);
			}

			set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );
		}

		// Authenticated OAuth clients skip IP whitelist and UA verification.
		if ( $token_data ) {
			return true;
		}

		// Optional IP whitelist.
		if ( ! empty( $s['ip_whitelist_enabled'] ) && ! empty( $s['ip_whitelist'] ) ) {
			$ip      = self::get_client_ip();
			$allowed = array_filter( array_map( 'trim', explode( "\n", $s['ip_whitelist'] ) ) );
			if ( ! in_array( $ip, $allowed, true ) ) {
				return new WP_Error( 'forbidden', 'Access denied.', array( 'status' => 403 ) );
			}
		}

		// Optional user-agent verification.
		if ( ! empty( $s['user_agent_verification'] ) ) {
			$ua = $request->get_header( 'user-agent' ) ?? '';
			if ( ! self::is_known_ai_bot( $ua ) ) {
				return new WP_Error( 'forbidden', 'Unrecognised user agent.', array( 'status' => 403 ) );
			}
		}

		return true;
	}

	private static function extract_bearer_token( WP_REST_Request $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && strncasecmp( $auth, 'Bearer ', 7 ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		return null;
	}

	public static function rest_search( WP_REST_Request $request ) {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			's'              => $request->get_param( 'q' ),
		) );

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'title'   => get_the_title( $post ),
				'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'url'     => get_permalink( $post ),
			);
		}

		return rest_ensure_response( $results );
	}

	// ── MCP JSON-RPC 2.0 endpoint (MCP spec 2025-03-26) ─────────────────────

	public static function rest_mcp_endpoint( WP_REST_Request $request ) {
		// CORS — MCP clients (Claude Desktop, browser agents) are cross-origin.
		if ( ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id' );
		}

		// GET → server card for discovery (human/tool-readable, not JSON-RPC).
		if ( $request->get_method() === 'GET' ) {
			return rest_ensure_response( self::build_mcp_server_card() );
		}

		// POST → JSON-RPC 2.0 dispatch.
		$body = $request->get_json_params();

		if ( empty( $body ) || ( $body['jsonrpc'] ?? '' ) !== '2.0' || ! isset( $body['method'] ) ) {
			return rest_ensure_response( self::mcp_jsonrpc_error( null, -32600, 'Invalid Request: not a valid JSON-RPC 2.0 object.' ) );
		}

		$id     = array_key_exists( 'id', $body ) ? $body['id'] : null;
		$method = $body['method'];
		$params = $body['params'] ?? array();

		switch ( $method ) {
			case 'initialize':
				$response = rest_ensure_response( self::mcp_handle_initialize( $id, $params ) );
				// Return a session ID header so clients can use it in subsequent requests.
				$response->header( 'Mcp-Session-Id', wp_generate_uuid4() );
				return $response;

			case 'notifications/initialized':
				// Client notification — no response body required by the spec.
				return new WP_REST_Response( null, 202 );

			case 'ping':
				return rest_ensure_response( self::mcp_jsonrpc_ok( $id, new stdClass() ) );

			case 'tools/list':
				return rest_ensure_response( self::mcp_handle_tools_list( $id ) );

			case 'tools/call':
				return rest_ensure_response( self::mcp_handle_tools_call( $id, $params ) );

			default:
				return rest_ensure_response( self::mcp_jsonrpc_error( $id, -32601, 'Method not found: ' . sanitize_text_field( $method ) ) );
		}
	}

	private static function mcp_handle_initialize( $id, $params ) {
		// Negotiate the highest mutually supported protocol version.
		$supported = array( '2025-03-26', '2024-11-05' );
		$requested = $params['protocolVersion'] ?? '2025-03-26';
		$version   = in_array( $requested, $supported, true ) ? $requested : '2025-03-26';

		$tools = self::mcp_build_tools();

		return self::mcp_jsonrpc_ok( $id, array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools' => ! empty( $tools ) ? new stdClass() : null,
			),
			'serverInfo'      => array(
				'name'    => get_bloginfo( 'name' ) . ' MCP Server',
				'version' => '1.0',
			),
			'instructions'    => sprintf(
				'This is the MCP server for %s (%s). Call tools/list to discover available tools.',
				get_bloginfo( 'name' ),
				home_url( '/' )
			),
		) );
	}

	private static function mcp_handle_tools_list( $id ) {
		return self::mcp_jsonrpc_ok( $id, array(
			'tools' => self::mcp_build_tools(),
		) );
	}

	private static function mcp_handle_tools_call( $id, $params ) {
		$name      = sanitize_text_field( $params['name'] ?? '' );
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();

		switch ( $name ) {
			case 'get_site_info':
				return self::mcp_call_get_site_info( $id );
			case 'search':
				return self::mcp_call_search( $id, $arguments );
			case 'get_page_content':
				return self::mcp_call_get_page_content( $id, $arguments );
			default:
				return self::mcp_jsonrpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
	}

	private static function mcp_build_tools() {
		$s     = self::get_settings();
		$site  = get_bloginfo( 'name' );
		$tools = array();

		// get_site_info — always available; helps agents orient themselves.
		$tools[] = array(
			'name'        => 'get_site_info',
			'description' => 'Returns the name, description, URL, and language of ' . $site . '.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		);

		// search — only when the Agent Search API REST endpoint is active.
		if ( ! empty( $s['agent_search_api'] ) ) {
			$tools[] = array(
				'name'        => 'search',
				'description' => 'Search published posts and pages on ' . $site . ' by keyword. Returns up to 10 results with title, URL, and excerpt.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'q' => array(
							'type'        => 'string',
							'description' => 'Search query — keywords or a phrase.',
						),
					),
					'required' => array( 'q' ),
				),
			);
		}

		// get_page_content — resolve any published URL to its full Markdown content.
		$tools[] = array(
			'name'        => 'get_page_content',
			'description' => 'Fetches the full Markdown content of a specific published page or post on ' . $site . ' given its URL.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'The full URL of a published post or page on this site.',
					),
				),
				'required' => array( 'url' ),
			),
		);

		return $tools;
	}

	private static function mcp_call_get_site_info( $id ) {
		$text = wp_json_encode( array(
			'name'        => get_bloginfo( 'name' ),
			'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'         => home_url( '/' ),
			'language'    => get_bloginfo( 'language' ),
		), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		return self::mcp_jsonrpc_ok( $id, array(
			'content' => array( array( 'type' => 'text', 'text' => $text ) ),
			'isError' => false,
		) );
	}

	private static function mcp_call_search( $id, $args ) {
		$q = sanitize_text_field( $args['q'] ?? '' );
		if ( $q === '' ) {
			return self::mcp_tool_error( $id, 'The "q" argument is required and must not be empty.' );
		}

		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			's'              => $q,
		) );

		if ( empty( $posts ) ) {
			return self::mcp_jsonrpc_ok( $id, array(
				'content' => array( array( 'type' => 'text', 'text' => 'No results found for: ' . $q ) ),
				'isError' => false,
			) );
		}

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			);
		}

		return self::mcp_jsonrpc_ok( $id, array(
			'content' => array( array(
				'type' => 'text',
				'text' => wp_json_encode( $results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
			) ),
			'isError' => false,
		) );
	}

	private static function mcp_call_get_page_content( $id, $args ) {
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( $url === '' ) {
			return self::mcp_tool_error( $id, 'The "url" argument is required.' );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return self::mcp_tool_error( $id, 'No published content found at: ' . $url );
		}

		$post    = get_post( $post_id );
		// Core the_content filter, applied via a variable hook name (core's, not ours).
		$core_filter = 'the_content';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hook, not ours to prefix.
		$content     = apply_filters( $core_filter, $post->post_content );
		$md          = class_exists( 'TWTAEO_HTML_To_Markdown' )
			? TWTAEO_HTML_To_Markdown::convert( $content )
			: wp_strip_all_tags( $content );

		$text  = '# ' . get_the_title( $post ) . "\n\n";
		$text .= 'URL: ' . get_permalink( $post ) . "\n";
		$text .= 'Published: ' . get_the_date( 'Y-m-d', $post ) . "\n";
		$text .= 'Modified: ' . get_the_modified_date( 'Y-m-d', $post ) . "\n\n";
		$text .= $md;

		return self::mcp_jsonrpc_ok( $id, array(
			'content' => array( array( 'type' => 'text', 'text' => $text ) ),
			'isError' => false,
		) );
	}

	// ── MCP JSON-RPC helpers ──────────────────────────────────────────────────

	private static function mcp_jsonrpc_ok( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private static function mcp_jsonrpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		);
	}

	private static function mcp_tool_error( $id, $message ) {
		return self::mcp_jsonrpc_ok( $id, array(
			'content' => array( array( 'type' => 'text', 'text' => $message ) ),
			'isError' => true,
		) );
	}

	// ── WebMCP — browser-side tool provider ──────────────────────────────────

	public static function enqueue_webmcp_script() {
		$s    = self::get_settings();
		$site = get_bloginfo( 'name' );

		// Build the tools array in PHP so we can conditionally include each tool.
		$tools = array();

		// Always expose a site-info tool — zero risk, helps agents orient.
		$tools[] = array(
			'name'        => 'get_site_info',
			'description' => 'Returns the site name, tagline, and home URL for ' . $site . '.',
			'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			'_handler'    => 'getSiteInfo',
		);

		// Search tool — only when the Agent Search API REST endpoint is active.
		if ( ! empty( $s['agent_search_api'] ) ) {
			$tools[] = array(
				'name'        => 'search_site',
				'description' => 'Search published content on ' . $site . ' by keyword. Returns titles, excerpts, and URLs.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'q' => array( 'type' => 'string', 'description' => 'Search keyword(s)' ),
					),
					'required'   => array( 'q' ),
				),
				'_handler'    => 'searchSite',
			);
		}

		// Recent posts tool — always available.
		$tools[] = array(
			'name'        => 'get_recent_posts',
			'description' => 'Returns the most recent published posts and pages on ' . $site . '.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'count' => array( 'type' => 'integer', 'description' => 'Number of posts to return (1-20)', 'default' => 5 ),
				),
			),
			'_handler'    => 'getRecentPosts',
		);

		$search_url = rest_url( 'aeo/v1/search' );
		$posts_url  = rest_url( 'wp/v2/posts' );
		$pages_url  = rest_url( 'wp/v2/pages' );
		$site_name  = get_bloginfo( 'name' );
		$tagline    = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$home_url   = home_url();

		wp_register_script( 'twt-aeo-webmcp', false, array(), TWTAEO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion

		// Hand the tool definitions to the script as data via wp_add_inline_script()
		// rather than echoing JSON into the page — there is no raw echo to escape. The
		// HEX_* flags make the value HTML-inert (<, >, &, ' and " become \uXXXX), and
		// _handler is kept so the JS can do a named lookup (not index-based).
		wp_add_inline_script(
			'twt-aeo-webmcp',
			'window.twtAeoMcpTools = ' . wp_json_encode( $tools, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);

		ob_start();
		?>
(function () {
	'use strict';

	if ( ! navigator.modelContext || typeof navigator.modelContext.provideContext !== 'function' ) {
		return;
	}

	var searchUrl     = '<?php echo esc_js( $search_url ); ?>';
	var postsUrl      = '<?php echo esc_js( $posts_url ); ?>';
	var pagesUrl      = '<?php echo esc_js( $pages_url ); ?>';
	var siteNameVal   = '<?php echo esc_js( $site_name ); ?>';
	var taglineVal    = '<?php echo esc_js( $tagline ); ?>';
	var homeUrlVal    = '<?php echo esc_js( $home_url ); ?>';

	var handlers = {
		getSiteInfo: function () {
			return Promise.resolve( { name: siteNameVal, description: taglineVal, url: homeUrlVal } );
		},
		searchSite: function ( input ) {
			var q = encodeURIComponent( ( input && input.q ) || '' );
			return fetch( searchUrl + '?q=' + q )
				.then( function ( r ) { return r.json(); } );
		},
		getRecentPosts: function ( input ) {
			var n = Math.min( 20, Math.max( 1, ( input && input.count ) ? parseInt( input.count, 10 ) : 5 ) );
			var both = [
				fetch( postsUrl + '?per_page=' + n + '&_fields=title,link,excerpt,date' ).then( function ( r ) { return r.json(); } ),
				fetch( pagesUrl + '?per_page=' + n + '&_fields=title,link,excerpt,date' ).then( function ( r ) { return r.json(); } ),
			];
			return Promise.all( both ).then( function ( results ) {
				return [].concat( results[0] || [], results[1] || [] )
					.sort( function ( a, b ) { return ( b.date || '' ).localeCompare( a.date || '' ); } )
					.slice( 0, n )
					.map( function ( p ) {
						return { title: ( p.title && p.title.rendered ) || '', url: p.link, excerpt: ( p.excerpt && p.excerpt.rendered ) || '' };
					} );
			} );
		},
	};

	var toolDefs = window.twtAeoMcpTools || [];

	var toolsWithExecute = toolDefs.map( function ( def ) {
		var handler = handlers[ def._handler ] || function () { return Promise.resolve( {} ); };
		var t = { name: def.name, description: def.description, inputSchema: def.inputSchema, execute: handler };
		return t;
	} );

	try {
		navigator.modelContext.provideContext( { tools: toolsWithExecute } );
	} catch ( e ) {
		// Silently ignore — not all AI agents support WebMCP yet.
	}
}());
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-webmcp', $js );
		wp_enqueue_script( 'twt-aeo-webmcp' );
	}

	// ── .well-known endpoints ─────────────────────────────────────────────────

	public static function maybe_serve_wellknown() {
		$which = get_query_var( 'twtaeo_wellknown' );
		if ( ! $which ) {
			return;
		}

		$s = self::get_settings();

		switch ( $which ) {
			case 'api-catalog':
				self::serve_api_catalog( $s );
				break;

			case 'oauth-protected-resource':
				if ( empty( $s['protected_resource_meta'] ) ) {
					status_header( 404 );
					exit;
				}
				self::serve_protected_resource_metadata();
				break;

			case 'signatures-directory':
				self::serve_signatures_directory();
				break;

			case 'oauth-authorization-server':
			case 'openid-configuration':
				if ( empty( $s['oauth_discovery'] ) ) {
					status_header( 404 );
					exit;
				}
				if ( $which === 'openid-configuration' && empty( $s['oauth_oidc'] ) ) {
					status_header( 404 );
					exit;
				}
				self::serve_oauth_discovery( $s, $which === 'openid-configuration' );
				break;

			case 'mcp-server-card':
				if ( empty( $s['mcp_integration'] ) ) {
					status_header( 404 );
					exit;
				}
				self::output_json( self::build_mcp_server_card() );
				break;

			case 'agent-skill':
				$slug = sanitize_key( get_query_var( 'twtaeo_skill' ) );
				self::serve_agent_skill_md( $slug, $s );
				break;

			case 'agent-skills-index':
				if ( empty( $s['agent_skills_index'] ) ) {
					status_header( 404 );
					exit;
				}
				self::serve_agent_skills_index_json( $s );
				break;
		}
	}

	/**
	 * Serve a SKILL.md file for a given agent-skill slug.
	 * Content is generated on-the-fly from settings — no filesystem writes needed.
	 */
	private static function serve_agent_skill_md( $slug, $s ) {
		$map = array(
			'content-signals' => function() use ( $s ) { return self::skill_md_content_signals( $s ); },
			'llms-txt'        => function() { return self::skill_md_llms_txt(); },
			'agent-search'    => function() { return self::skill_md_agent_search(); },
			'api-catalog'     => function() { return self::skill_md_api_catalog(); },
			'mcp'             => function() { return self::skill_md_mcp(); },
			'mcp-server-card' => function() { return self::skill_md_mcp_server_card(); },
			'oauth-discovery' => function() use ( $s ) { return self::skill_md_oauth_discovery( $s ); },
		);

		// Guard: skill must be known and its parent feature must be enabled.
		$enabled = array(
			'content-signals' => ( $s['content_signal_ai_train'] !== 'unspecified'
				|| $s['content_signal_search'] !== 'unspecified'
				|| $s['content_signal_ai_input'] !== 'unspecified' ),
			'llms-txt'        => ! empty( $s['llms_txt'] ),
			'agent-search'    => ! empty( $s['agent_search_api'] ),
			'api-catalog'     => ! empty( $s['api_catalog'] ),
			'mcp'             => ! empty( $s['mcp_integration'] ),
			'mcp-server-card' => ! empty( $s['mcp_integration'] ),
			'oauth-discovery' => ! empty( $s['oauth_discovery'] ),
		);

		if ( ! isset( $map[ $slug ] ) || empty( $enabled[ $slug ] ) ) {
			status_header( 404 );
			exit;
		}

		$md = $map[ $slug ]();
		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_kses_post( $md );
		exit;
	}

	/**
	 * Serve the agent-skills index.json on-the-fly from settings.
	 * This is the virtual equivalent of the physical index.json file.
	 */
	private static function serve_agent_skills_index_json( $s ) {
		$skills = self::build_agent_skills_list( $s );
		$index  = array(
			'skills' => $skills,
		);
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $index, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); // phpcs:ignore
		exit;
	}

	private static function serve_api_catalog( $s ) {
		status_header( 200 );
		header( 'Content-Type: application/linkset+json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		// Dedicated JSON API response (application/linkset+json), not an HTML document.
		// The body is wp_json_encode() output; HEX_TAG keeps it inert even if a client
		// mis-sniffs it as markup. esc_* HTML escapers would corrupt the JSON, so they
		// are intentionally not used here.
		echo wp_json_encode( array( 'linkset' => self::build_api_linkset( $s ) ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API response, see note above.
		exit;
	}

	// ── MCP Server Card (SEP-1649) ────────────────────────────────────────────

	private static function build_mcp_server_card() {
		$tools       = self::mcp_build_tools();
		$endpoint    = rest_url( 'aeo/v1/mcp' );
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			// Core identity (MCP spec 2025-03-26 server card convention).
			'name'            => get_bloginfo( 'name' ) . ' MCP Server',
			'version'         => '1.0',
			'protocolVersion' => '2025-03-26',
			'description'     => $description ?: ( get_bloginfo( 'name' ) . ' — ' . home_url( '/' ) ),
			'url'             => $endpoint,
			// Transport array — clients use this to know how to connect.
			'transport'       => array(
				array( 'type' => 'http', 'url' => $endpoint ),
			),
			// Capabilities summary — mirrors what initialize returns.
			'capabilities'    => array(
				'tools'     => ! empty( $tools ) ? new stdClass() : null,
				'resources' => null,
				'prompts'   => null,
			),
			// Tool index for discovery without a full initialize handshake.
			'tools'           => array_map( function ( $t ) {
				return array( 'name' => $t['name'], 'description' => $t['description'] );
			}, $tools ),
			// Contact for attribution and abuse reporting.
			'contact'         => array( 'url' => home_url( '/' ) ),
		);
	}

	/**
	 * Lazily initialise and return the WP_Filesystem instance, or null when the
	 * filesystem is not directly writable. These discovery-file helpers run during
	 * settings saves, AJAX, and init hooks where we cannot prompt for FTP
	 * credentials, so we only proceed on the 'direct' method; on other hosts the
	 * feature degrades gracefully (the caller reports "not writable", as before).
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function fs() {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return null;
		}
		if ( ! WP_Filesystem() || ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return null;
		}
		return $wp_filesystem;
	}

	/** File mode for written discovery files (0644), guarded for early calls. */
	private static function fs_file_mode() {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	}

	/**
	 * Ensure a writable directory under .well-known/ exists, correcting restrictive
	 * permissions when possible, and return the WP_Filesystem instance to write with.
	 *
	 * @param string $dir Absolute directory path.
	 * @return WP_Filesystem_Base|null Instance when $dir is writable, else null.
	 */
	private static function ensure_writable_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return null;
		}
		// Attempt to correct restrictive permissions (e.g. 750 → 755) so the web
		// server process can read files in this directory.
		if ( $fs->is_dir( $dir ) && ! $fs->is_writable( $dir ) ) {
			$fs->chmod( $dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 );
		}
		return $fs->is_writable( $dir ) ? $fs : null;
	}

	public static function write_mcp_server_card_file() {
		$wk  = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known';
		$dir = $wk . DIRECTORY_SEPARATOR . 'mcp';

		if ( ! is_dir( $wk ) ) {
			wp_mkdir_p( $wk );
		}

		// Remove any bad .htaccess in .well-known/ that would block the directory.
		$bad_htaccess = $wk . DIRECTORY_SEPARATOR . '.htaccess';
		if ( file_exists( $bad_htaccess ) ) {
			wp_delete_file( $bad_htaccess );
		}

		$fs = self::ensure_writable_dir( $dir );
		if ( ! $fs ) {
			return false;
		}

		$json     = wp_json_encode( self::build_mcp_server_card(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$filepath = $dir . DIRECTORY_SEPARATOR . 'server-card.json';

		return $fs->put_contents( $filepath, $json, self::fs_file_mode() );
	}

	public static function delete_mcp_server_card_file() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . 'server-card.json';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	// ── API Catalog — physical file helpers ───────────────────────────────────

	private static function build_api_linkset( $s ) {
		$linkset = array();

		// WordPress REST API — always present on every WordPress site.
		$linkset[] = array(
			'anchor'       => rest_url( 'wp/v2' ),
			'service-desc' => array(
				array( 'href' => rest_url(), 'type' => 'application/json' ),
			),
			'service-doc'  => array(
				array( 'href' => 'https://developer.wordpress.org/rest-api/' ),
			),
			'status'       => array(
				array( 'href' => rest_url() ),
			),
		);

		if ( ! empty( $s['agent_search_api'] ) ) {
			$linkset[] = array(
				'anchor'       => rest_url( 'aeo/v1/search' ),
				'service-desc' => array(
					array( 'href' => rest_url( 'aeo/v1' ), 'type' => 'application/json' ),
				),
				'service-doc'  => array(
					array( 'href' => rest_url( 'aeo/v1/search' ) ),
				),
				'status'       => array(
					array( 'href' => rest_url( 'aeo/v1/search' ) . '?q=test' ),
				),
			);
		}

		if ( ! empty( $s['mcp_integration'] ) ) {
			$linkset[] = array(
				'anchor'       => rest_url( 'aeo/v1/mcp' ),
				'service-desc' => array(
					array( 'href' => rest_url( 'aeo/v1/mcp' ), 'type' => 'application/json' ),
				),
				'service-doc'  => array(
					array( 'href' => rest_url( 'aeo/v1/mcp' ) ),
				),
				'status'       => array(
					array( 'href' => rest_url( 'aeo/v1/mcp' ) ),
				),
			);
		}

		return $linkset;
	}

	public static function write_api_catalog_file() {
		$s   = self::get_settings();
		$dir = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known';

		$fs = self::ensure_writable_dir( $dir );
		if ( ! $fs ) {
			return false;
		}

		// Remove any .htaccess we may have previously written inside .well-known/ —
		// Apache/LiteSpeed denies access to the entire directory when it can't read it.
		$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			wp_delete_file( $htaccess );
		}

		$json     = wp_json_encode(
			array( 'linkset' => self::build_api_linkset( $s ) ),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
		$filepath = $dir . DIRECTORY_SEPARATOR . 'api-catalog';

		// FS_CHMOD_FILE keeps the file world-readable so the web server can serve it.
		return $fs->put_contents( $filepath, $json, self::fs_file_mode() );
	}

	// Returns a status array for the .well-known/ directory and known files,
	// used by the admin page to show diagnostic notices.
	public static function get_wellknown_status() {
		$abspath      = self::get_site_root();
		$dir          = $abspath . DIRECTORY_SEPARATOR . '.well-known';
		$catalog_path = $dir . DIRECTORY_SEPARATOR . 'api-catalog';
		$skills_path  = $dir . DIRECTORY_SEPARATOR . 'agent-skills' . DIRECTORY_SEPARATOR . 'index.json';
		$oauth_path   = $dir . DIRECTORY_SEPARATOR . 'oauth-authorization-server';
		$oidc_path    = $dir . DIRECTORY_SEPARATOR . 'openid-configuration';
		$card_path    = $dir . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . 'server-card.json';
		$htaccess     = $dir . DIRECTORY_SEPARATOR . '.htaccess';

		$fs               = self::fs();
		$dir_exists       = is_dir( $dir );
		$dir_perms        = $dir_exists ? substr( sprintf( '%o', fileperms( $dir ) ), -4 ) : null;
		$dir_readable     = $dir_exists && is_readable( $dir );
		$dir_writable     = $dir_exists && $fs && $fs->is_writable( $dir );
		$dir_ok           = $dir_readable && $dir_writable;
		$abspath_writable = $fs && $fs->is_writable( $abspath );

		// can_write: PHP can write files under .well-known/ either because the
		// directory exists and is writable, or because ABSPATH is writable so
		// wp_mkdir_p() can create the directory on the next save.
		$can_write = $dir_writable || ( ! $dir_exists && $abspath_writable );

		return array(
			'dir_exists'        => $dir_exists,
			'dir_perms'         => $dir_perms,
			'dir_readable'      => $dir_readable,
			'dir_writable'      => $dir_writable,
			'dir_ok'            => $dir_ok,
			'abspath_writable'  => $abspath_writable,
			'can_write'         => $can_write,
			'has_bad_htaccess'  => file_exists( $htaccess ),
			'catalog_exists'    => file_exists( $catalog_path ),
			'catalog_perms'     => file_exists( $catalog_path ) ? substr( sprintf( '%o', fileperms( $catalog_path ) ), -4 ) : null,
			'skills_exists'     => file_exists( $skills_path ),
			'oauth_exists'      => file_exists( $oauth_path ),
			'oidc_exists'       => file_exists( $oidc_path ),
			'mcp_card_exists'   => file_exists( $card_path ),
		);
	}

	/**
	 * Build the list of physical discovery files the plugin would write for the
	 * currently-enabled features, each with the exact JSON content and target path.
	 *
	 * Used by the admin page to show copy-and-paste instructions when the host's
	 * filesystem is not writable and the plugin cannot create the files itself.
	 * The same content is also served dynamically at each file's URL, so the values
	 * here match what an agent receives either way.
	 *
	 * @return array[] Each entry: { label, rel (path under site root), abs (full path), url, contents }.
	 */
	public static function get_wellknown_manifest() {
		$s    = self::get_settings();
		$root = self::get_site_root();
		$ds   = DIRECTORY_SEPARATOR;
		$flag = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

		$files = array();

		// API Catalog — always generated (the endpoint is always on).
		$files[] = array(
			'label'    => 'API Catalog',
			'rel'      => '.well-known/api-catalog',
			'abs'      => $root . $ds . '.well-known' . $ds . 'api-catalog',
			'url'      => home_url( '/.well-known/api-catalog' ),
			'contents' => wp_json_encode( array( 'linkset' => self::build_api_linkset( $s ) ), $flag ),
		);

		if ( ! empty( $s['agent_skills_index'] ) ) {
			$files[] = array(
				'label'    => 'Agent Skills Index',
				'rel'      => '.well-known/agent-skills/index.json',
				'abs'      => $root . $ds . '.well-known' . $ds . 'agent-skills' . $ds . 'index.json',
				'url'      => home_url( '/.well-known/agent-skills/index.json' ),
				'contents' => wp_json_encode(
					array(
						'skills' => self::build_agent_skills_list( $s ),
					),
					$flag
				),
			);
		}

		if ( ! empty( $s['mcp_integration'] ) ) {
			$files[] = array(
				'label'    => 'MCP Server Card',
				'rel'      => '.well-known/mcp/server-card.json',
				'abs'      => $root . $ds . '.well-known' . $ds . 'mcp' . $ds . 'server-card.json',
				'url'      => home_url( '/.well-known/mcp/server-card.json' ),
				'contents' => wp_json_encode( self::build_mcp_server_card(), $flag ),
			);
		}

		if ( ! empty( $s['oauth_discovery'] ) ) {
			$files[] = array(
				'label'    => 'OAuth Authorization Server Metadata',
				'rel'      => '.well-known/oauth-authorization-server',
				'abs'      => $root . $ds . '.well-known' . $ds . 'oauth-authorization-server',
				'url'      => home_url( '/.well-known/oauth-authorization-server' ),
				'contents' => wp_json_encode( self::build_oauth_discovery_doc( $s, false ), $flag ),
			);

			if ( ! empty( $s['oauth_oidc'] ) ) {
				$files[] = array(
					'label'    => 'OpenID Connect Discovery',
					'rel'      => '.well-known/openid-configuration',
					'abs'      => $root . $ds . '.well-known' . $ds . 'openid-configuration',
					'url'      => home_url( '/.well-known/openid-configuration' ),
					'contents' => wp_json_encode( self::build_oauth_discovery_doc( $s, true ), $flag ),
				);
			}
		}

		return $files;
	}

	public static function delete_api_catalog_file() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'api-catalog';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	// ── OAuth / OIDC Discovery (RFC 8414 + OIDC Discovery 1.0) ───────────────

	private static function build_oauth_discovery_doc( $s, $oidc = false ) {
		// Built-in mode: auto-populate with our own REST endpoints.
		if ( ( $s['oauth_mode'] ?? 'builtin' ) === 'builtin' ) {
			$doc = array(
				'issuer'                                  => home_url(),
				'token_endpoint'                          => rest_url( 'aeo/v1/oauth/token' ),
				'jwks_uri'                                => rest_url( 'aeo/v1/oauth/jwks' ),
				'grant_types_supported'                   => array( 'client_credentials' ),
				'scopes_supported'                        => array( 'read', 'search' ),
				'response_types_supported'                => array( 'token' ),
				'token_endpoint_auth_methods_supported'   => array( 'client_secret_basic', 'client_secret_post' ),
			);
			if ( $oidc ) {
				$doc['subject_types_supported']                  = array( 'public' );
				$doc['id_token_signing_alg_values_supported']    = array( 'RS256' );
			}
			return $doc;
		}

		// External mode: use the manually configured values.
		$issuer = ! empty( $s['oauth_issuer'] ) ? esc_url_raw( $s['oauth_issuer'] ) : home_url();
		$doc    = array( 'issuer' => $issuer );

		if ( ! empty( $s['oauth_auth_endpoint'] ) ) {
			$doc['authorization_endpoint'] = esc_url_raw( $s['oauth_auth_endpoint'] );
		}
		if ( ! empty( $s['oauth_token_endpoint'] ) ) {
			$doc['token_endpoint'] = esc_url_raw( $s['oauth_token_endpoint'] );
		}
		if ( ! empty( $s['oauth_jwks_uri'] ) ) {
			$doc['jwks_uri'] = esc_url_raw( $s['oauth_jwks_uri'] );
		}
		if ( $oidc && ! empty( $s['oauth_userinfo_endpoint'] ) ) {
			$doc['userinfo_endpoint'] = esc_url_raw( $s['oauth_userinfo_endpoint'] );
		}

		$grant_types = array_values( array_filter( array_map( 'trim', explode( ',', $s['oauth_grant_types'] ?? '' ) ) ) );
		if ( ! empty( $grant_types ) ) {
			$doc['grant_types_supported'] = $grant_types;
		}

		$scopes = array_values( array_filter( array_map( 'trim', explode( ',', $s['oauth_scopes'] ?? '' ) ) ) );
		if ( ! empty( $scopes ) ) {
			$doc['scopes_supported'] = $scopes;
		}

		$doc['response_types_supported']              = array( 'code' );
		$doc['token_endpoint_auth_methods_supported'] = array( 'client_secret_basic', 'client_secret_post' );

		if ( $oidc ) {
			$doc['subject_types_supported']               = array( 'public' );
			$doc['id_token_signing_alg_values_supported'] = array( 'RS256' );
		}

		return $doc;
	}

	private static function serve_oauth_discovery( $s, $oidc = false ) {
		self::output_json( self::build_oauth_discovery_doc( $s, $oidc ) );
	}

	public static function write_oauth_discovery_files() {
		$s   = self::get_settings();
		$dir = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known';

		$fs = self::ensure_writable_dir( $dir );
		if ( ! $fs ) {
			return false;
		}

		// Clean up the old bad .htaccess if present.
		$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			wp_delete_file( $htaccess );
		}

		$json = wp_json_encode( self::build_oauth_discovery_doc( $s, false ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$path = $dir . DIRECTORY_SEPARATOR . 'oauth-authorization-server';
		$ok   = $fs->put_contents( $path, $json, self::fs_file_mode() );

		if ( ! empty( $s['oauth_oidc'] ) ) {
			$oidc_json = wp_json_encode( self::build_oauth_discovery_doc( $s, true ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			$oidc_path = $dir . DIRECTORY_SEPARATOR . 'openid-configuration';
			$fs->put_contents( $oidc_path, $oidc_json, self::fs_file_mode() );
		}

		return $ok;
	}

	public static function delete_oauth_discovery_files() {
		$dir = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known';
		foreach ( array( 'oauth-authorization-server', 'openid-configuration' ) as $name ) {
			$path = $dir . DIRECTORY_SEPARATOR . $name;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	private static function serve_protected_resource_metadata() {
		self::output_json( array(
			'resource'                 => home_url(),
			'authorization_servers'    => array(),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'read' ),
			'resource_documentation'   => home_url( '/.well-known/api-catalog' ),
		) );
	}

	// ── Web Bot Auth — JWKS generation & serving ─────────────────────────────

	public static function get_or_create_signing_key() {
		$stored = get_option( 'twtaeo_signing_key', array() );

		if ( ! empty( $stored['jwk'] ) && ! empty( $stored['private_pem'] ) ) {
			return $stored;
		}

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}

		$key = openssl_pkey_new( array(
			'curve_name'       => 'prime256v1', // NIST P-256
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		) );

		if ( ! $key ) {
			return null;
		}

		openssl_pkey_export( $key, $private_pem );
		$details = openssl_pkey_get_details( $key );

		if ( ! $details || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return null;
		}

		$b64url = function ( $bin ) {
			return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding required by the spec, not obfuscation.
		};

		$x   = $b64url( $details['ec']['x'] );
		$y   = $b64url( $details['ec']['y'] );
		$kid = substr( hash( 'sha256', $x . $y ), 0, 12 );

		$jwk = array(
			'kty' => 'EC',
			'crv' => 'P-256',
			'kid' => $kid,
			'use' => 'sig',
			'alg' => 'ES256',
			'x'   => $x,
			'y'   => $y,
		);

		$stored = array(
			'private_pem' => $private_pem,
			'jwk'         => $jwk,
		);

		update_option( 'twtaeo_signing_key', $stored, false );
		return $stored;
	}

	private static function serve_signatures_directory() {
		$key_data = self::get_or_create_signing_key();

		$doc = array(
			'operator'                     => get_bloginfo( 'name' ),
			'site_url'                     => home_url(),
			'signing_algorithms_supported' => array( 'ecdsa-p256-sha256' ),
			'keys'                         => array(),
		);

		if ( $key_data && ! empty( $key_data['jwk'] ) ) {
			$doc['keys'][] = $key_data['jwk'];
		}

		self::output_json( $doc );
	}

	private static function output_json( $data ) {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); // phpcs:ignore
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function get_client_ip() {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	private static function is_known_ai_bot( $ua ) {
		return self::identify_bot( $ua ) !== null;
	}

	// ── Rewrite flush helper (called from admin save) ─────────────────────────

	public static function flush_rewrites() {
		flush_rewrite_rules();
	}

	// ── Readiness score (used in admin page) ──────────────────────────────────

	public static function get_readiness_score() {
		$s = self::get_settings();

		$checks = array(
			(bool) $s['markdown_negotiation'],
			(bool) $s['llms_txt'],
			(bool) $s['agent_skills_index'],
			( $s['content_signal_ai_train'] !== 'unspecified' || $s['content_signal_search'] !== 'unspecified' ),
			(bool) $s['semantic_breadcrumbs'],
			(bool) $s['agent_search_api'],
			(bool) $s['api_catalog'],
			(bool) $s['mcp_integration'],
			(bool) $s['rate_limit_enabled'],
			(bool) $s['oauth_discovery'],
		);

		return array(
			'enabled' => count( array_filter( $checks ) ),
			'total'   => count( $checks ),
		);
	}

	// ── Agent Skills Discovery ────────────────────────────────────────────────

	/**
	 * Build the list of active skills from settings.
	 * SHA-256 hashes are computed from generated content so consumers can
	 * verify integrity without reading physical files.
	 * SKILL.md files are now served virtually via WP rewrites — no filesystem
	 * writes are performed here.
	 *
	 * @param array $s Settings array.
	 * @return array
	 */
	private static function build_agent_skills_list( $s ) {
		$skills = array();

		$has_signal = $s['content_signal_ai_train'] !== 'unspecified'
			|| $s['content_signal_search'] !== 'unspecified'
			|| $s['content_signal_ai_input'] !== 'unspecified';

		if ( $has_signal ) {
			$md       = self::skill_md_content_signals( $s );
			$skills[] = array(
				'name'        => 'Content-Signal Declarations',
				'type'        => 'content-policy',
				'description' => 'AI data-use preferences declared via Content-Signal in robots.txt and HTTP headers.',
				'url'         => home_url( '/.well-known/agent-skills/content-signals/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);
		}

		if ( ! empty( $s['llms_txt'] ) ) {
			$md       = self::skill_md_llms_txt();
			$skills[] = array(
				'name'        => 'LLMs.txt Discovery',
				'type'        => 'discovery',
				'description' => 'Machine-readable site overview at /llms.txt for AI language models.',
				'url'         => home_url( '/.well-known/agent-skills/llms-txt/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);
		}

		if ( ! empty( $s['agent_search_api'] ) ) {
			$md       = self::skill_md_agent_search();
			$skills[] = array(
				'name'        => 'Agent Search API',
				'type'        => 'api',
				'description' => 'REST endpoint for AI agents to search site content by keyword.',
				'url'         => home_url( '/.well-known/agent-skills/agent-search/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);
		}

		if ( ! empty( $s['api_catalog'] ) ) {
			$md       = self::skill_md_api_catalog();
			$skills[] = array(
				'name'        => 'API Catalog (RFC 9727)',
				'type'        => 'discovery',
				'description' => 'Machine-readable API directory at /.well-known/api-catalog per RFC 9727.',
				'url'         => home_url( '/.well-known/agent-skills/api-catalog/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);
		}

		if ( ! empty( $s['mcp_integration'] ) ) {
			$md       = self::skill_md_mcp();
			$skills[] = array(
				'name'        => 'MCP Tool Manifest',
				'type'        => 'mcp',
				'description' => 'Model Context Protocol manifest listing available tools for AI agents.',
				'url'         => home_url( '/.well-known/agent-skills/mcp/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);

			$card_md  = self::skill_md_mcp_server_card();
			$skills[] = array(
				'name'        => 'MCP Server Card',
				'type'        => 'mcp-server-card',
				'description' => 'MCP Server Card (SEP-1649) at /.well-known/mcp/server-card.json for agent discovery.',
				'url'         => home_url( '/.well-known/agent-skills/mcp-server-card/SKILL.md' ),
				'sha256'      => hash( 'sha256', $card_md ),
			);
		}

		if ( ! empty( $s['oauth_discovery'] ) ) {
			$md       = self::skill_md_oauth_discovery( $s );
			$skills[] = array(
				'name'        => 'OAuth / OIDC Discovery',
				'type'        => 'auth-discovery',
				'description' => ! empty( $s['oauth_oidc'] )
					? 'OAuth 2.0 (RFC 8414) and OpenID Connect discovery metadata for agent authentication.'
					: 'OAuth 2.0 Authorization Server Metadata (RFC 8414) for agent authentication.',
				'url'         => home_url( '/.well-known/agent-skills/oauth-discovery/SKILL.md' ),
				'sha256'      => hash( 'sha256', $md ),
			);
		}

		return $skills;
	}

	/**
	 * Write the agent-skills index.json to ABSPATH/.well-known/agent-skills/.
	 *
	 * SKILL.md files are no longer written here — they are served on-the-fly
	 * via WordPress rewrite rules by serve_agent_skill_md(). Only the JSON
	 * index is written so that static servers (nginx, CDNs) can discover the
	 * skill list without routing through PHP.
	 */
	public static function write_agent_skills_index() {
		$s   = self::get_settings();
		$wk  = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known';
		$dir = $wk . DIRECTORY_SEPARATOR . 'agent-skills';

		if ( ! is_dir( $wk ) ) {
			wp_mkdir_p( $wk );
		}

		// Remove any legacy .htaccess that blocks the directory.
		$bad_htaccess = $wk . DIRECTORY_SEPARATOR . '.htaccess';
		if ( file_exists( $bad_htaccess ) ) {
			wp_delete_file( $bad_htaccess );
		}

		$fs = self::ensure_writable_dir( $dir );
		if ( ! $fs ) {
			return false;
		}

		$index = array(
			'skills' => self::build_agent_skills_list( $s ),
		);

		$json = wp_json_encode( $index, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return $fs->put_contents( $dir . DIRECTORY_SEPARATOR . 'index.json', $json, self::fs_file_mode() );
	}

	public static function delete_agent_skills_index() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'agent-skills' . DIRECTORY_SEPARATOR . 'index.json';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	// Checks whether the bad .htaccess we wrote in v1.0.2 is still present.
	public static function has_blocking_htaccess() {
		return file_exists( self::get_site_root() . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . '.htaccess' );
	}

	private static function skill_md_content_signals( $s ) {
		$parts = array();
		if ( $s['content_signal_ai_train'] !== 'unspecified' ) $parts[] = 'ai-train=' . $s['content_signal_ai_train'];
		if ( $s['content_signal_search'] !== 'unspecified' )   $parts[] = 'search=' . $s['content_signal_search'];
		if ( $s['content_signal_ai_input'] !== 'unspecified' ) $parts[] = 'ai-input=' . $s['content_signal_ai_input'];
		$directive = 'Content-Signal: ' . implode( ', ', $parts );

		return "# Content-Signal Declarations\n\n" .
			"**Type**: content-policy  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"This site publishes AI data-use preferences via the Content-Signal draft specification " .
			"(draft-romm-aipref-contentsignals). Preferences are declared in robots.txt and as HTTP response headers.\n\n" .
			"## Current Declaration\n\n" .
			"```\n{$directive}\n```\n\n" .
			"## Parameters\n\n" .
			"- `ai-train`: Whether AI providers may use content for model training.\n" .
			"- `search`: Whether AI search engines may index and cite content.\n" .
			"- `ai-input`: Whether AI systems may use content as RAG/context input.\n\n" .
			"## Discovery\n\n" .
			"- **robots.txt**: `" . home_url( '/robots.txt' ) . "`\n" .
			"- **HTTP header**: `Content-Signal` on every page response.\n";
	}

	private static function skill_md_llms_txt() {
		return "# LLMs.txt Discovery\n\n" .
			"**Type**: discovery  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"This site publishes a dynamic `/llms.txt` file - a machine-readable overview for AI language models. " .
			"It includes the site name, description, and a structured list of recent pages with titles, " .
			"excerpts, and links.\n\n" .
			"## Endpoint\n\n" .
			"```\nGET " . home_url( '/llms.txt' ) . "\n```\n\n" .
			"## Format\n\nPlain text / Markdown. No authentication required.\n";
	}

	private static function skill_md_agent_search() {
		return "# Agent Search API\n\n" .
			"**Type**: api  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"A REST endpoint that allows AI agents to search this site's published content by keyword. " .
			"Returns post titles, excerpts, and permalinks. Read-only. Rate-limited.\n\n" .
			"## Endpoint\n\n" .
			"```\nGET " . rest_url( 'aeo/v1/search' ) . "?q={query}\n```\n\n" .
			"## Parameters\n\n" .
			"| Name | Type   | Required | Description          |\n" .
			"|------|--------|----------|----------------------|\n" .
			"| q    | string | Yes      | Search keyword(s)    |\n\n" .
			"## Response\n\n" .
			"```json\n[{\"title\": \"...\", \"excerpt\": \"...\", \"url\": \"...\"}]\n```\n\n" .
			"## Authentication\n\nNone required. Public endpoint.\n";
	}

	private static function skill_md_api_catalog() {
		return "# API Catalog (RFC 9727)\n\n" .
			"**Type**: discovery  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"This site publishes a machine-readable API catalog at the RFC 9727 standard endpoint. " .
			"The catalog lists all available APIs in application/linkset+json format (RFC 9264).\n\n" .
			"## Endpoint\n\n" .
			"```\nGET " . home_url( '/.well-known/api-catalog' ) . "\n```\n\n" .
			"## Response Content-Type\n\n`application/linkset+json`\n";
	}

	private static function skill_md_mcp() {
		return "# MCP Tool Manifest\n\n" .
			"**Type**: mcp  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"This site exposes a Model Context Protocol (MCP) tool manifest. " .
			"AI agents can fetch this manifest to discover available tools and how to invoke them.\n\n" .
			"## Endpoint\n\n" .
			"```\nGET " . rest_url( 'aeo/v1/mcp' ) . "\n```\n\n" .
			"## Format\n\nJSON. Returns tool name, description, input schema, and invocation URL.\n";
	}

	private static function skill_md_mcp_server_card() {
		return "# MCP Server Card\n\n" .
			"**Type**: mcp-server-card  \n" .
			"**Version**: 1.0.0  \n" .
			"**Spec**: SEP-1649  \n\n" .
			"## Description\n\n" .
			"This site publishes an MCP Server Card for agent discovery per SEP-1649. " .
			"The card advertises the server name, version, transport endpoint, and supported capabilities " .
			"so AI agents can automatically detect and connect to this site's MCP server.\n\n" .
			"## Endpoint\n\n" .
			"```\nGET " . home_url( '/.well-known/mcp/server-card.json' ) . "\n```\n\n" .
			"## Response\n\n" .
			"```json\n" .
			"{\n" .
			"  \"serverInfo\": { \"name\": \"" . get_bloginfo( 'name' ) . " MCP Server\", \"version\": \"1.0\" },\n" .
			"  \"transport\": { \"endpoint\": \"" . rest_url( 'aeo/v1/mcp' ) . "\" },\n" .
			"  \"capabilities\": { \"tools\": true, \"resources\": false, \"prompts\": false }\n" .
			"}\n```\n\n" .
			"## Standards\n\n" .
			"- SEP-1649 — MCP Server Card specification\n";
	}

	private static function skill_md_oauth_discovery( $s ) {
		$builtin = ( $s['oauth_mode'] ?? 'builtin' ) === 'builtin';
		$oidc    = ! empty( $s['oauth_oidc'] );
		$issuer  = home_url();

		$out = "# OAuth / OIDC Discovery\n\n" .
			"**Type**: auth-discovery  \n" .
			"**Version**: 1.0.0  \n\n" .
			"## Description\n\n" .
			"This site publishes OAuth 2.0 Authorization Server Metadata (RFC 8414)" .
			( $oidc ? " and OpenID Connect Discovery 1.0 metadata" : "" ) .
			" so AI agents can programmatically discover how to authenticate before accessing protected APIs.\n\n" .
			"## Issuer\n\n`" . $issuer . "`\n\n" .
			"## Discovery Endpoints\n\n" .
			"```\nGET " . home_url( '/.well-known/oauth-authorization-server' ) . "\n```\n";

		if ( $oidc ) {
			$out .= "```\nGET " . home_url( '/.well-known/openid-configuration' ) . "\n```\n";
		}

		if ( $builtin ) {
			$out .= "\n## Token Endpoint\n\n" .
				"```\nPOST " . rest_url( 'aeo/v1/oauth/token' ) . "\n```\n\n" .
				"**Grant type**: `client_credentials`  \n" .
				"**Auth methods**: `client_secret_basic`, `client_secret_post`  \n" .
				"**Scopes**: `read`, `search`\n\n" .
				"### Example request\n\n" .
				"```\nPOST " . rest_url( 'aeo/v1/oauth/token' ) . "\n" .
				"Content-Type: application/x-www-form-urlencoded\n\n" .
				"grant_type=client_credentials&client_id={client_id}&client_secret={client_secret}\n```\n";
		} else {
			$grant_types = array_values( array_filter( array_map( 'trim', explode( ',', $s['oauth_grant_types'] ?? '' ) ) ) );
			if ( ! empty( $grant_types ) ) {
				$out .= "\n## Grant Types Supported\n\n" . implode( ', ', $grant_types ) . "\n";
			}
		}

		$out .= "\n## Standards\n\n" .
			"- [RFC 8414](https://www.rfc-editor.org/rfc/rfc8414) — OAuth 2.0 Authorization Server Metadata\n";
		if ( $oidc ) {
			$out .= "- [OIDC Discovery 1.0](http://openid.net/specs/openid-connect-discovery-1_0.html)\n";
		}

		return $out;
	}

	// ── Server diagnostic ────────────────────────────────────────────────────

	/**
	 * Detect the server environment and test the .well-known/http-message-signatures-directory
	 * path. Returns a structured result with targeted fix instructions.
	 *
	 * @return array {
	 *   server: 'nginx'|'apache'|'unknown',
	 *   cloudflare: bool,
	 *   path_ok: bool,
	 *   status_code: int,
	 *   test_url: string,
	 *   instructions: array[],
	 *   can_auto_fix: bool
	 * }
	 */
	public static function diagnose_server() {
		// ── Server detection ──────────────────────────────────────────────────
		$software = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) );

		if ( strpos( $software, 'nginx' ) !== false ) {
			$server = 'nginx';
		} elseif ( strpos( $software, 'apache' ) !== false || strpos( $software, 'litespeed' ) !== false ) {
			$server = 'apache';
		} else {
			$server = 'unknown';
		}

		// ── Cloudflare detection ──────────────────────────────────────────────
		$cloudflare = isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		// ── Path test ─────────────────────────────────────────────────────────
		$test_url = home_url( '/.well-known/http-message-signatures-directory' );
		$response = wp_remote_get( $test_url, array(
			'timeout'    => 8,
			'sslverify'  => false,
			'user-agent' => 'twt-aeo-ultimate-Diagnostic/' . TWTAEO_VERSION,
			'headers'    => array( 'Accept' => 'application/json' ),
		) );

		$status_code = 0;
		$path_ok     = false;

		if ( ! is_wp_error( $response ) ) {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$path_ok     = ( $status_code === 200 );
		}

		// ── Build targeted instructions ───────────────────────────────────────
		$instructions = array();

		if ( ! $path_ok ) {
			switch ( $server ) {
				case 'nginx':
					$instructions[] = array(
						'type'  => 'server',
						'icon'  => 'dashicons-editor-code',
						'title' => 'Nginx Configuration Required',
						'body'  => 'Your server is running Nginx. Virtual paths starting with a dot (.) are often blocked by default. Add this location block to your Nginx server configuration file (e.g. /etc/nginx/sites-available/your-site.conf), then reload Nginx.',
						'code'  => "location ~ /\\.well-known/ {\n    allow all;\n    try_files \$uri \$uri/ /index.php?\$args;\n}",
						'note'  => 'After saving the config: sudo systemctl reload nginx   (or: sudo nginx -s reload)',
					);
					break;

				case 'apache':
					$instructions[] = array(
						'type'  => 'server',
						'icon'  => 'dashicons-hammer',
						'title' => 'Apache — Auto-Fix Available',
						'body'  => 'Your server is running Apache. The plugin will inject a pass-through RewriteRule into your .htaccess file to prevent WordPress\'s standard redirect block from intercepting .well-known paths. Click "Apply Apache Fix" below.',
						'code'  => null,
						'note'  => null,
					);
					break;

				default:
					$instructions[] = array(
						'type'  => 'server',
						'icon'  => 'dashicons-info-outline',
						'title' => 'Server Type Not Detected',
						'body'  => 'Could not determine your server type from SERVER_SOFTWARE. If you are running Nginx, add a location ~ /\\.well-known/ block in your config. If Apache or LiteSpeed, use the .htaccess approach — save AI Ready Settings and click "Flush Rewrites" to regenerate .htaccess with the pass-through rules.',
						'code'  => null,
						'note'  => null,
					);
					break;
			}

			if ( $cloudflare ) {
				$instructions[] = array(
					'type'  => 'cloudflare',
					'icon'  => 'dashicons-cloud',
					'title' => 'Cloudflare WAF — Additional Step May Be Needed',
					'body'  => 'You are behind Cloudflare. If the path still returns an error after fixing your server configuration, your Cloudflare WAF may be blocking the request before it reaches your server. In your Cloudflare dashboard go to Security → WAF → Custom Rules and create a rule to skip managed rules for this path.',
					'code'  => '(http.request.uri.path contains "/.well-known/")',
					'note'  => 'Rule action: Skip → All managed rules. This bypasses WAF inspection for .well-known only and does not disable other Cloudflare protections.',
				);
			}
		}

		return array(
			'server'       => $server,
			'cloudflare'   => $cloudflare,
			'path_ok'      => $path_ok,
			'status_code'  => $status_code,
			'test_url'     => $test_url,
			'instructions' => $instructions,
			'can_auto_fix' => ( $server === 'apache' && ! $path_ok ),
		);
	}

	/**
	 * Force-regenerate .htaccess so the mod_rewrite_rules filter injects the
	 * .well-known pass-through rules. Returns status for the AJAX response.
	 *
	 * @return array { success: bool, message: string }
	 */
	public static function apply_wellknown_apache_fix() {
		$htaccess = self::get_site_root() . DIRECTORY_SEPARATOR . '.htaccess';
		$fs       = self::fs();

		// Ensure WordPress can write .htaccess — it needs to exist and be writable,
		// or the directory needs to be writable so WP can create it.
		if ( file_exists( $htaccess ) && ! ( $fs && $fs->is_writable( $htaccess ) ) ) {
			return array(
				'success' => false,
				'message' => '.htaccess exists but is not writable by PHP. Set it to 644 in cPanel File Manager, then try again.',
			);
		}

		if ( ! file_exists( $htaccess ) && ! ( $fs && $fs->is_writable( self::get_site_root() ) ) ) {
			return array(
				'success' => false,
				'message' => 'The site root is not writable — WordPress cannot create .htaccess. Set your document root to 755 in cPanel.',
			);
		}

		// Clear the startup-flush flag so on_init does not interfere with our explicit flush.
		delete_option( 'twtaeo_air_rules_flushed' );

		// Hard flush — regenerates .htaccess with current filters applied.
		// Our inject_wellknown_htaccess_rules filter is always registered on init,
		// so it will be included in the newly written file.
		flush_rewrite_rules( true );

		// Verify the rules landed in .htaccess.
		$contents = ( $fs && $fs->exists( $htaccess ) ) ? (string) $fs->get_contents( $htaccess ) : '';

		if ( strpos( $contents, '.well-known' ) !== false ) {
			return array(
				'success' => true,
				'message' => '.htaccess updated — .well-known pass-through rules written successfully. Run the diagnostic again to confirm the path is now reachable.',
			);
		}

		// Rules did not land — this can happen if the mod_rewrite_rules filter
		// was not active when flush_rewrite_rules fired (e.g. WP_REWRITE not loaded).
		// Offer manual fallback copy.
		$manual  = "<IfModule mod_rewrite.c>\n";
		$manual .= "RewriteEngine On\n";
		$manual .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$manual .= "RewriteRule ^\.well-known/http-message-signatures-directory$ /index.php?twtaeo_wellknown=signatures-directory [QSA,L]\n";
		$manual .= "</IfModule>";

		return array(
			'success' => false,
			'message' => '.htaccess was not updated automatically. Paste this block manually at the top of your .htaccess file (above the # BEGIN WordPress line):',
			'manual'  => $manual,
		);
	}

	// ── SEO plugin detection ──────────────────────────────────────────────────

	// Returns an array of active SEO plugin slugs that may conflict with AI Ready.
	public static function detect_seo_plugins() {
		$found = array();
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$found[] = 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$found[] = 'yoast';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$found[] = 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_activation' ) ) {
			$found[] = 'seopress';
		}
		return $found;
	}

	/**
	 * Map SEO-plugin slugs (from detect_seo_plugins) to display names.
	 *
	 * @param array $slugs Slugs to translate.
	 * @return string[] Human-readable plugin names.
	 */
	public static function seo_plugin_names( $slugs ) {
		$map = array(
			'rankmath' => 'Rank Math',
			'yoast'    => 'Yoast SEO',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
		);
		$names = array();
		foreach ( (array) $slugs as $slug ) {
			if ( isset( $map[ $slug ] ) ) {
				$names[] = $map[ $slug ];
			}
		}
		return $names;
	}

	/**
	 * Whether a physical robots.txt exists and the web server can delete/rewrite it.
	 *
	 * @return bool True only when the file exists AND is writable.
	 */
	public static function physical_robots_is_writable() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . 'robots.txt';
		$fs   = self::fs();
		return file_exists( $path ) && $fs && $fs->is_writable( $path );
	}

	// Checks whether a physical llms.txt exists on disk, which would prevent the
	// rewrite rule from firing (Apache/Nginx serves the file directly, bypassing PHP).
	public static function has_physical_llms_txt() {
		return file_exists( self::get_site_root() . DIRECTORY_SEPARATOR . 'llms.txt' );
	}

	// Returns the name(s) of any active SEO plugin known to generate llms.txt.
	public static function detect_llms_txt_conflict() {
		$seo = self::detect_seo_plugins();
		$conflicts = array();
		// Rank Math 1.0.219+ generates llms.txt when its module is active.
		if ( in_array( 'rankmath', $seo, true ) && defined( 'RANK_MATH_VERSION' ) ) {
			if ( version_compare( RANK_MATH_VERSION, '1.0.219', '>=' ) ) {
				$conflicts[] = 'Rank Math';
			}
		}
		return $conflicts;
	}

	// ── Physical robots.txt helpers ───────────────────────────────────────────

	/**
	 * Send no-cache headers while WordPress generates a dynamic robots.txt, so a
	 * full-page cache (host cache / CDN) doesn't serve it without invoking PHP —
	 * which would hide AI-crawler hits from the logger. Hooked on do_robots at
	 * priority 0 so it runs before output, regardless of which plugin builds the body.
	 */
	public static function nocache_robots() {
		nocache_headers();
	}

	public static function has_physical_robots() {
		return file_exists( self::get_site_root() . DIRECTORY_SEPARATOR . 'robots.txt' );
	}

	public static function get_physical_robots_has_signal() {
		if ( ! self::has_physical_robots() ) {
			return false;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$contents = $fs->get_contents( self::get_site_root() . DIRECTORY_SEPARATOR . 'robots.txt' );
		return $contents !== false && stripos( $contents, 'Content-Signal:' ) !== false;
	}

	/**
	 * Build the robots.txt block a site owner should add by hand when the physical
	 * file cannot be written automatically — the same Content-Signal and Sitemap
	 * directives inject_into_physical_robots() would write. Returns '' when there is
	 * nothing to add (all signals "unspecified" and no sitemap).
	 *
	 * @return string
	 */
	public static function get_robots_signal_snippet() {
		$s     = self::get_settings();
		$parts = array();
		if ( $s['content_signal_ai_train'] !== 'unspecified' ) {
			$parts[] = 'ai-train=' . $s['content_signal_ai_train'];
		}
		if ( $s['content_signal_search'] !== 'unspecified' ) {
			$parts[] = 'search=' . $s['content_signal_search'];
		}
		if ( $s['content_signal_ai_input'] !== 'unspecified' ) {
			$parts[] = 'ai-input=' . $s['content_signal_ai_input'];
		}

		$sitemap_url = self::get_sitemap_url();
		if ( ! $sitemap_url ) {
			$sitemap_url = home_url( '/sitemap.xml' );
		}

		$lines = array();
		if ( ! empty( $parts ) ) {
			$lines[] = 'User-agent: *';
			$lines[] = 'Content-Signal: ' . implode( ', ', $parts );
			$lines[] = '';
		}
		$lines[] = 'Sitemap: ' . $sitemap_url;

		return implode( "\n", $lines );
	}

	public static function inject_into_physical_robots() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . 'robots.txt';

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'no_physical_file', 'No physical robots.txt found.' );
		}

		$fs = self::fs();
		if ( ! $fs || ! $fs->is_writable( $path ) ) {
			return new WP_Error( 'not_writable', 'robots.txt is not writable by the web server.' );
		}

		$s     = self::get_settings();
		$parts = array();
		if ( $s['content_signal_ai_train'] !== 'unspecified' ) {
			$parts[] = 'ai-train=' . $s['content_signal_ai_train'];
		}
		if ( $s['content_signal_search'] !== 'unspecified' ) {
			$parts[] = 'search=' . $s['content_signal_search'];
		}
		if ( $s['content_signal_ai_input'] !== 'unspecified' ) {
			$parts[] = 'ai-input=' . $s['content_signal_ai_input'];
		}

		$sitemap_url = self::get_sitemap_url();
		if ( ! $sitemap_url ) {
			$sitemap_url = home_url( '/sitemap.xml' );
		}

		$contents = $fs->get_contents( $path );

		if ( $contents === false ) {
			return new WP_Error( 'read_failed', 'Could not read robots.txt.' );
		}

		// Strip stale Yoast / RankMath / SEOPress tagged blocks left behind
		// after those plugins are deleted so AI checkers don't see orphaned directives.
		$contents = preg_replace(
			'/# -{3,}\r?\n# START YOAST BLOCK\r?\n# -{3,}\r?\n.*?# -{3,}\r?\n# END YOAST BLOCK\r?\n?/s',
			'',
			$contents
		);
		$contents = preg_replace(
			'/# -{3,}\r?\n# START RANK MATH\r?\n# -{3,}\r?\n.*?# -{3,}\r?\n# END RANK MATH\r?\n?/si',
			'',
			$contents
		);
		$contents = preg_replace(
			'/# -{3,}\r?\n# START SEOPRESS\r?\n# -{3,}\r?\n.*?# -{3,}\r?\n# END SEOPRESS\r?\n?/si',
			'',
			$contents
		);
		// Collapse blank lines left behind after block removal.
		$contents = preg_replace( '/\n{3,}/', "\n\n", ltrim( $contents ) );

		$has_signal  = stripos( $contents, 'Content-Signal:' ) !== false;
		$has_sitemap = stripos( $contents, 'Sitemap:' ) !== false;

		// Nothing to do.
		if ( empty( $parts ) && $has_sitemap ) {
			return new WP_Error( 'no_signal', 'All Content-Signal values are set to "unspecified" and Sitemap: is already present — nothing to inject.' );
		}

		// Update or append Content-Signal.
		if ( ! empty( $parts ) ) {
			$directive = 'Content-Signal: ' . implode( ', ', $parts );
			if ( $has_signal ) {
				$contents = preg_replace( '/^Content-Signal:.*$/im', $directive, $contents );
			} else {
				$contents = rtrim( $contents ) . "\n\nUser-agent: *\n" . $directive . "\n";
			}
		}

		// Append Sitemap: directive if missing.
		if ( ! $has_sitemap ) {
			$contents = rtrim( $contents ) . "\n\nSitemap: " . $sitemap_url . "\n";
		}

		if ( ! $fs->put_contents( $path, $contents, self::fs_file_mode() ) ) {
			return new WP_Error( 'write_failed', 'Could not write to robots.txt.' );
		}

		return true;
	}

	/**
	 * Delete the physical robots.txt so WordPress serves it dynamically again.
	 *
	 * A physical file (often left behind by Yoast/Rank Math after removal) is
	 * served straight off disk by the web server, freezing its content and
	 * bypassing the robots_txt filter and AI-crawler logging.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_physical_robots() {
		$path = self::get_site_root() . DIRECTORY_SEPARATOR . 'robots.txt';

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'no_physical_file', 'No physical robots.txt found — WordPress is already serving it dynamically.' );
		}

		$fs = self::fs();
		if ( ! $fs || ! $fs->is_writable( $path ) ) {
			return new WP_Error( 'not_writable', 'robots.txt exists but the web server cannot delete it. Remove it manually via FTP or your host file manager.' );
		}

		wp_delete_file( $path );

		if ( file_exists( $path ) ) {
			return new WP_Error( 'delete_failed', 'Could not delete robots.txt. Remove it manually via FTP or your host file manager.' );
		}

		return true;
	}
}
