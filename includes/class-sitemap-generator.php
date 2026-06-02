<?php
/**
 * Sitemap Generator
 *
 * Generates and serves an XML sitemap at /aeo-sitemap.xml.
 * Configurable per post type (change frequency, priority, include/exclude).
 * Caches output in a transient; invalidates on post save/delete.
 * Detects active Yoast / Rank Math / AIOSEO sitemaps and warns on conflict.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Sitemap_Generator {

	const OPTION_SETTINGS  = 'twtaeo_sitemap_settings';
	const OPTION_AI_TXT    = 'twtaeo_ai_txt_settings';
	const TRANSIENT_CACHE  = 'twtaeo_sitemap_xml';
	const TRANSIENT_LLMS   = 'twtaeo_llms_txt';
	const TRANSIENT_AI_TXT = 'twtaeo_ai_txt';
	const TRANSIENT_FLUSH  = 'twtaeo_sitemap_flush';
	const CACHE_TTL        = HOUR_IN_SECONDS;
	const SITEMAP_SLUG     = 'aeo-sitemap.xml';
	const LLMS_SLUG        = 'llms-full.txt';
	const AI_TXT_SLUG      = 'ai.txt';
	const QUERY_VAR        = 'twtaeo_sitemap';
	const LLMS_QUERY_VAR   = 'twtaeo_llms';
	const AI_TXT_QUERY_VAR = 'twtaeo_ai_txt';
	const NONCE_SETTINGS   = 'twtaeo_sitemap_save';
	const NONCE_FLUSH      = 'twtaeo_sitemap_flush';

	// ── Hook registration ─────────────────────────────────────────────────────

	public static function register_hooks() {
		// register_hooks itself is called on init, so add_rewrite_rule must be
		// called directly here — not re-added to init (which has already fired).
		self::add_rewrite_rule();
		self::add_llms_rewrite_rule();
		self::add_ai_txt_rewrite_rule();
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_var' ) );
		// Priority 1: run before Yoast SEO (priority 10) and WP core sitemaps.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		add_action( 'save_post',         array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_post',       array( __CLASS__, 'flush_cache' ) );
		add_action( 'admin_init',        array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
	}

	// ── Rewrite rule ──────────────────────────────────────────────────────────

	public static function add_rewrite_rule() {
		add_rewrite_rule(
			'^' . preg_quote( self::SITEMAP_SLUG, '/' ) . '$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function add_llms_rewrite_rule() {
		add_rewrite_rule(
			'^' . preg_quote( self::LLMS_SLUG, '/' ) . '$',
			'index.php?' . self::LLMS_QUERY_VAR . '=1',
			'top'
		);
	}

	public static function add_ai_txt_rewrite_rule() {
		add_rewrite_rule(
			'^' . preg_quote( self::AI_TXT_SLUG, '/' ) . '$',
			'index.php?' . self::AI_TXT_QUERY_VAR . '=1',
			'top'
		);
	}

	public static function add_query_var( array $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LLMS_QUERY_VAR;
		$vars[] = self::AI_TXT_QUERY_VAR;
		return $vars;
	}

	// ── Serving ───────────────────────────────────────────────────────────────

	public static function maybe_serve() {
		// Check via registered query vars (pretty URLs after rewrite rule flush).
		if ( get_query_var( self::QUERY_VAR ) ) {
			self::serve();
			return;
		}

		if ( get_query_var( self::LLMS_QUERY_VAR ) ) {
			self::serve_llms();
			return;
		}

		if ( get_query_var( self::AI_TXT_QUERY_VAR ) ) {
			self::serve_ai_txt();
			return;
		}

		// Fallback: raw GET params — work even if query vars weren't registered
		// before parse_request ran.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			self::serve();
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::LLMS_QUERY_VAR ] ) ) {
			self::serve_llms();
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::AI_TXT_QUERY_VAR ] ) ) {
			self::serve_ai_txt();
			return;
		}

		// Fallback: match pretty URL directly against REQUEST_URI — works even
		// when rewrite rules haven't been flushed to the database yet.
		$path = trim( wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		if ( $path === self::SITEMAP_SLUG ) {
			self::serve();
		} elseif ( $path === self::LLMS_SLUG ) {
			self::serve_llms();
		} elseif ( $path === self::AI_TXT_SLUG ) {
			self::serve_ai_txt();
		}
	}

	public static function serve() {
		$xml = get_transient( self::TRANSIENT_CACHE );

		if ( false === $xml ) {
			$xml = self::generate();
			set_transient( self::TRANSIENT_CACHE, $xml, self::CACHE_TTL );
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $xml;
		exit;
	}

	public static function serve_llms() {
		$content = get_transient( self::TRANSIENT_LLMS );

		if ( false === $content ) {
			$content = self::generate_llms();
			set_transient( self::TRANSIENT_LLMS, $content, self::CACHE_TTL );
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo wp_kses_post( $content );
		exit;
	}

	// ── Generation ────────────────────────────────────────────────────────────

	public static function generate() {
		$settings   = self::get_settings();
		$post_types = self::get_enabled_post_types( $settings );
		$exclude    = self::parse_exclude_ids( $settings['exclude_ids'] ?? '' );

		$urls = array();

		foreach ( $post_types as $post_type => $config ) {
			$query_args = array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			);

			if ( ! empty( $exclude ) ) {
				$query_args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}

			$ids = get_posts( $query_args );

			foreach ( $ids as $id ) {
				$urls[] = array(
					'loc'        => get_permalink( $id ),
					'lastmod'    => get_post_modified_time( 'c', true, $id ),
					'changefreq' => $config['changefreq'],
					'priority'   => $config['priority'],
				);
			}
		}

		return self::build_xml( $urls );
	}

	private static function build_xml( array $urls ) {
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<!-- Generated by TWT AEO Ultimate ' . TWTAEO_VERSION . ' -->' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $entry ) {
			$out .= "\t<url>\n";
			$out .= "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				$out .= "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
			}
			$out .= "\t\t<changefreq>" . esc_html( $entry['changefreq'] ) . "</changefreq>\n";
			$out .= "\t\t<priority>" . esc_html( $entry['priority'] ) . "</priority>\n";
			$out .= "\t</url>\n";
		}

		$out .= '</urlset>';
		return $out;
	}

	// ── Cache ─────────────────────────────────────────────────────────────────

	public static function flush_cache() {
		delete_transient( self::TRANSIENT_CACHE );
		delete_transient( self::TRANSIENT_LLMS );
		delete_transient( self::TRANSIENT_AI_TXT );
	}

	public static function schedule_rewrite_flush() {
		set_transient( self::TRANSIENT_FLUSH, 1, MINUTE_IN_SECONDS * 5 );
	}

	public static function maybe_flush_rewrite_rules() {
		// Flush once if our rule is not yet saved in the rewrite_rules DB option.
		// This fires on admin_init after register_hooks has already called
		// add_rewrite_rule(), so the rule is in extra_rules_top and will be
		// included when WordPress regenerates and saves the rules.
		$rules          = get_option( 'rewrite_rules', array() );
		$pattern        = '^' . preg_quote( self::SITEMAP_SLUG,  '/' ) . '$';
		$llms_pattern   = '^' . preg_quote( self::LLMS_SLUG,     '/' ) . '$';
		$ai_txt_pattern = '^' . preg_quote( self::AI_TXT_SLUG,   '/' ) . '$';

		if ( ! isset( $rules[ $pattern ] ) || ! isset( $rules[ $llms_pattern ] ) || ! isset( $rules[ $ai_txt_pattern ] ) ) {
			flush_rewrite_rules( false );
			return;
		}

		// Also honour explicit flush requests (e.g. from the Flush Cache button).
		if ( get_transient( self::TRANSIENT_FLUSH ) ) {
			delete_transient( self::TRANSIENT_FLUSH );
			flush_rewrite_rules( false );
		}
	}

	// ── URL helpers ───────────────────────────────────────────────────────────

	public static function get_url() {
		return home_url( '/' . self::SITEMAP_SLUG );
	}

	public static function get_fallback_url() {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	public static function get_llms_url() {
		return home_url( '/' . self::LLMS_SLUG );
	}

	public static function get_ai_txt_url() {
		return home_url( '/' . self::AI_TXT_SLUG );
	}

	// ── ai.txt serving & generation ───────────────────────────────────────────

	public static function serve_ai_txt() {
		$content = get_transient( self::TRANSIENT_AI_TXT );

		if ( false === $content ) {
			$content = self::generate_ai_txt();
			set_transient( self::TRANSIENT_AI_TXT, $content, self::CACHE_TTL );
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo wp_kses_post( $content );
		exit;
	}

	/**
	 * Return the known AI crawler definitions.
	 * Each entry: agent (User-agent string), company, purpose (training|retrieval|both).
	 *
	 * @return array
	 */
	public static function get_known_crawlers() {
		return array(
			'GPTBot'             => array( 'company' => 'OpenAI',       'purpose' => 'training'  ),
			'OAI-SearchBot'      => array( 'company' => 'OpenAI',       'purpose' => 'retrieval' ),
			'ChatGPT-User'       => array( 'company' => 'OpenAI',       'purpose' => 'retrieval' ),
			'ClaudeBot'          => array( 'company' => 'Anthropic',    'purpose' => 'training'  ),
			'anthropic-ai'       => array( 'company' => 'Anthropic',    'purpose' => 'training'  ),
			'Google-Extended'    => array( 'company' => 'Google',       'purpose' => 'training'  ),
			'Gemini'             => array( 'company' => 'Google',       'purpose' => 'retrieval' ),
			'PerplexityBot'      => array( 'company' => 'Perplexity',   'purpose' => 'retrieval' ),
			'Bytespider'         => array( 'company' => 'ByteDance',    'purpose' => 'training'  ),
			'CCBot'              => array( 'company' => 'Common Crawl', 'purpose' => 'training'  ),
			'Meta-ExternalAgent' => array( 'company' => 'Meta',         'purpose' => 'training'  ),
			'FacebookBot'        => array( 'company' => 'Meta',         'purpose' => 'retrieval' ),
			'cohere-ai'          => array( 'company' => 'Cohere',       'purpose' => 'training'  ),
			'Applebot-Extended'  => array( 'company' => 'Apple',        'purpose' => 'training'  ),
			'Amazonbot'          => array( 'company' => 'Amazon',       'purpose' => 'retrieval' ),
			'Diffbot'            => array( 'company' => 'Diffbot',      'purpose' => 'both'      ),
			'YouBot'             => array( 'company' => 'You.com',      'purpose' => 'retrieval' ),
			'ImagesiftBot'       => array( 'company' => 'Imagesift',    'purpose' => 'training'  ),
		);
	}

	/**
	 * Return the default ai.txt settings.
	 * Defaults: training disallowed for all types; indexing/summarize allowed for text and code.
	 *
	 * @return array
	 */
	public static function get_ai_txt_settings() {
		$defaults = array(
			'media'   => array(
				'text'   => array( 'train' => '0', 'summarize' => '1', 'index' => '1' ),
				'images' => array( 'train' => '0', 'summarize' => '0', 'index' => '1' ),
				'audio'  => array( 'train' => '0', 'summarize' => '0', 'index' => '0' ),
				'code'   => array( 'train' => '0', 'summarize' => '1', 'index' => '1' ),
			),
			'contact' => '',
		);
		$saved = get_option( self::OPTION_AI_TXT, array() );
		if ( ! empty( $saved['media'] ) && is_array( $saved['media'] ) ) {
			foreach ( $defaults['media'] as $type => $actions ) {
				if ( isset( $saved['media'][ $type ] ) ) {
					$defaults['media'][ $type ] = array_merge( $actions, $saved['media'][ $type ] );
				}
			}
		}
		if ( isset( $saved['contact'] ) ) {
			$defaults['contact'] = $saved['contact'];
		}
		return $defaults;
	}

	/**
	 * Save ai.txt settings from raw POST data.
	 *
	 * @param array $raw
	 */
	public static function save_ai_txt_settings( array $raw ) {
		$media_types   = array( 'text', 'images', 'audio', 'code' );
		$action_keys   = array( 'train', 'summarize', 'index' );
		$raw_media     = isset( $raw['ai_txt_media'] ) && is_array( $raw['ai_txt_media'] ) ? $raw['ai_txt_media'] : array();

		$media = array();
		foreach ( $media_types as $type ) {
			foreach ( $action_keys as $action ) {
				$media[ $type ][ $action ] = ! empty( $raw_media[ $type ][ $action ] ) ? '1' : '0';
			}
		}

		$settings = array(
			'media'   => $media,
			'contact' => sanitize_email( $raw['ai_txt_contact'] ?? '' ),
		);

		update_option( self::OPTION_AI_TXT, $settings );
		delete_transient( self::TRANSIENT_AI_TXT );
		self::schedule_rewrite_flush();
	}

	/**
	 * Generate the ai.txt file content using Media Type + Action block format.
	 * Default stance: opt-out for commercial AI training (EU Article 4 / TDMRep).
	 *
	 * @return string
	 */
	public static function generate_ai_txt() {
		$settings = self::get_ai_txt_settings();
		$media    = $settings['media'];
		$contact  = $settings['contact'];
		$site_url = home_url( '/' );
		$owner    = get_bloginfo( 'name' );
		$date     = gmdate( 'Y-m-d' );

		$out  = '# ai.txt — AI Text and Data Mining Permissions' . "\n";
		$out .= '# Site: ' . $site_url . "\n";
		$out .= '# Owner: ' . $owner . "\n";
		$out .= '# Last Updated: ' . $date . "\n";
		$out .= '# Generated by TWT AEO Ultimate ' . TWTAEO_VERSION . "\n";
		$out .= '# Default stance: commercial AI training disallowed unless explicitly enabled.' . "\n";
		$out .= "\n";

		$type_labels   = array( 'text' => 'Text', 'images' => 'Images', 'audio' => 'Audio', 'code' => 'Code' );
		$action_labels = array( 'train' => 'Train', 'summarize' => 'Summarize', 'index' => 'Index' );

		foreach ( $type_labels as $type_key => $type_label ) {
			$out .= 'Type: ' . $type_label . "\n";
			$type_config = $media[ $type_key ] ?? array();
			foreach ( $action_labels as $action_key => $action_label ) {
				$allowed = ! empty( $type_config[ $action_key ] ) && '1' === (string) $type_config[ $action_key ];
				$out    .= ( $allowed ? 'Allow' : 'Disallow' ) . ' Action: ' . $action_label . "\n";
			}
			$out .= "\n";
		}

		if ( $contact ) {
			$out .= '# Contact for AI licensing inquiries: ' . $contact . "\n";
		}

		return rtrim( $out ) . "\n";
	}

	// ── LLMs.txt generation ───────────────────────────────────────────────────

	public static function generate_llms() {
		$settings   = self::get_settings();
		$post_types = self::get_enabled_post_types( $settings );
		$exclude    = self::parse_exclude_ids( $settings['exclude_ids'] ?? '' );

		$sections = array();

		foreach ( $post_types as $post_type => $config ) {
			$args = array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => 'menu_order date',
				'order'                  => 'ASC',
			);

			if ( ! empty( $exclude ) ) {
				$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}

			$posts = get_posts( $args );

			if ( ! empty( $posts ) ) {
				$sections[ $post_type ] = $posts;
			}
		}

		return self::build_llms_markdown( $sections );
	}

	private static function build_llms_markdown( array $sections ) {
		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );
		$site_url  = home_url( '/' );

		$out  = '# ' . $site_name . "\n\n";
		if ( $tagline ) {
			$out .= '> ' . $tagline . "\n\n";
		}
		$out .= '> Source: ' . $site_url . "\n\n";
		$out .= '---' . "\n\n";

		// Index: one section per post type, each entry as a Markdown link.
		foreach ( $sections as $post_type => $posts ) {
			$type_obj = get_post_type_object( $post_type );
			$label    = ( $type_obj && isset( $type_obj->labels->name ) ) ? $type_obj->labels->name : ucfirst( $post_type );

			$out .= '## ' . $label . "\n\n";

			foreach ( $posts as $post ) {
				$url     = get_permalink( $post->ID );
				$excerpt = self::get_post_excerpt( $post );
				$out    .= '- [' . $post->post_title . '](' . $url . ')';
				if ( $excerpt ) {
					$out .= ': ' . $excerpt;
				}
				$out .= "\n";
			}

			$out .= "\n";
		}

		$out .= '---' . "\n\n";

		// Full content: one section per post.
		foreach ( $sections as $posts ) {
			foreach ( $posts as $post ) {
				$url     = get_permalink( $post->ID );
				$lastmod = get_post_modified_time( 'Y-m-d', true, $post->ID );

				$out .= '# ' . $post->post_title . "\n\n";
				$out .= 'URL: ' . $url . "\n";
				$out .= 'Last modified: ' . $lastmod . "\n\n";

				$rendered = apply_filters( 'the_content', $post->post_content );
				$out     .= self::html_to_markdown( $rendered );
				$out     .= "\n\n---\n\n";
			}
		}

		return rtrim( $out ) . "\n";
	}

	private static function get_post_excerpt( $post ) {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		$text  = wp_strip_all_tags( $post->post_content );
		$text  = preg_replace( '/\s+/', ' ', trim( $text ) );
		$words = explode( ' ', $text );

		if ( count( $words ) <= 30 ) {
			return $text;
		}

		return implode( ' ', array_slice( $words, 0, 30 ) ) . '…';
	}

	private static function html_to_markdown( $html ) {
		// Strip scripts, styles, and Gutenberg block comments.
		$html = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $html );
		$html = preg_replace( '/<!--\s*wp:[^>]*?-->/s', '', $html );
		$html = preg_replace( '/<!--\s*\/wp:[^>]*?-->/s', '', $html );

		// Headings.
		for ( $i = 6; $i >= 1; $i-- ) {
			$hashes = str_repeat( '#', $i );
			$html   = preg_replace( '@<h' . $i . '[^>]*?>(.*?)</h' . $i . '>@si', "\n\n" . $hashes . ' $1' . "\n", $html );
		}

		// Block elements → double newlines.
		$html = preg_replace( '@<p[^>]*?>@i', "\n\n", $html );
		$html = preg_replace( '@</p>@i', '', $html );
		$html = preg_replace( '@<br\s*/?>@i', "\n", $html );
		$html = preg_replace( '@</(div|section|article|header|footer|main|aside|blockquote|figure|figcaption)>@i', "\n", $html );

		// Inline formatting.
		$html = preg_replace( '@<(strong|b)[^>]*?>(.*?)</\1>@si', '**$2**', $html );
		$html = preg_replace( '@<(em|i)[^>]*?>(.*?)</\1>@si', '*$2*', $html );
		$html = preg_replace( '@<code[^>]*?>(.*?)</code>@si', '`$1`', $html );
		$html = preg_replace( '@<pre[^>]*?>(.*?)</pre>@si', "\n```\n$1\n```\n", $html );

		// Blockquotes.
		$html = preg_replace( '@<blockquote[^>]*?>(.*?)</blockquote>@si', "\n> $1\n", $html );

		// Links.
		$html = preg_replace_callback(
			'@<a[^>]+href=["\']([^"\']+)["\'][^>]*?>(.*?)</a>@si',
			function ( $m ) {
				$text = trim( wp_strip_all_tags( $m[2] ) );
				if ( '' === $text ) {
					return '';
				}
				return '[' . $text . '](' . $m[1] . ')';
			},
			$html
		);

		// List items (before stripping ul/ol/li tags).
		$html = preg_replace( '@<li[^>]*?>(.*?)</li>@si', "\n- $1", $html );

		// Strip all remaining tags.
		$html = wp_strip_all_tags( $html );

		// Decode HTML entities.
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse excessive blank lines.
		$html = preg_replace( '/\n{3,}/', "\n\n", trim( $html ) );

		return $html;
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge( self::get_default_settings(), $saved );
	}

	public static function save_settings( array $raw ) {
		$all_types  = self::get_all_public_post_types();
		$post_types = array();

		foreach ( array_keys( $all_types ) as $slug ) {
			$post_types[ $slug ] = array(
				'enabled'    => ! empty( $raw['post_types'][ $slug ]['enabled'] ) ? '1' : '0',
				'changefreq' => self::sanitize_changefreq( $raw['post_types'][ $slug ]['changefreq'] ?? 'weekly' ),
				'priority'   => self::sanitize_priority( $raw['post_types'][ $slug ]['priority'] ?? '0.6' ),
			);
		}

		$settings = array(
			'post_types'  => $post_types,
			'exclude_ids' => sanitize_text_field( $raw['exclude_ids'] ?? '' ),
		);

		update_option( self::OPTION_SETTINGS, $settings );
		self::flush_cache();
		self::schedule_rewrite_flush();

		return $settings;
	}

	private static function get_default_settings() {
		return array(
			'post_types'  => array(
				'post' => array( 'enabled' => '1', 'changefreq' => 'weekly',  'priority' => '0.6' ),
				'page' => array( 'enabled' => '1', 'changefreq' => 'monthly', 'priority' => '0.8' ),
			),
			'exclude_ids' => '',
		);
	}

	// ── Post type helpers ─────────────────────────────────────────────────────

	public static function get_all_public_post_types() {
		$result = array();

		// Built-in: only post and page.
		foreach ( get_post_types( array( 'public' => true, '_builtin' => true ), 'objects' ) as $slug => $obj ) {
			if ( in_array( $slug, array( 'post', 'page' ), true ) ) {
				$result[ $slug ] = $obj;
			}
		}

		// Custom post types (exclude attachment).
		foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $slug => $obj ) {
			if ( 'attachment' !== $slug ) {
				$result[ $slug ] = $obj;
			}
		}

		return $result;
	}

	private static function get_enabled_post_types( array $settings ) {
		$result    = array();
		$all_types = self::get_all_public_post_types();
		$saved     = $settings['post_types'] ?? array();

		foreach ( array_keys( $all_types ) as $slug ) {
			$config  = $saved[ $slug ] ?? array();
			$enabled = isset( $config['enabled'] ) ? (bool) $config['enabled'] : true;

			if ( ! $enabled ) {
				continue;
			}

			$default_freq = ( 'post' === $slug ) ? 'weekly' : 'monthly';
			$default_pri  = ( 'page' === $slug ) ? '0.8' : '0.6';

			$result[ $slug ] = array(
				'changefreq' => self::sanitize_changefreq( $config['changefreq'] ?? $default_freq ),
				'priority'   => self::sanitize_priority( $config['priority']   ?? $default_pri ),
			);
		}

		return $result;
	}

	private static function parse_exclude_ids( $raw ) {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	private static function sanitize_changefreq( $value ) {
		$allowed = array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );
		return in_array( $value, $allowed, true ) ? $value : 'weekly';
	}

	private static function sanitize_priority( $value ) {
		$value = max( 0.0, min( 1.0, (float) $value ) );
		return number_format( $value, 1 );
	}

	// ── Conflict detection ────────────────────────────────────────────────────

	/**
	 * Returns the name of an active SEO plugin that already provides a sitemap,
	 * or false if no conflict is found.
	 *
	 * @return string|false
	 */
	public static function has_conflict() {
		if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Sitemaps' ) ) {
			return 'Yoast SEO';
		}

		if ( defined( 'RANK_MATH_VERSION' ) && class_exists( 'RankMath\\Sitemap\\Sitemap' ) ) {
			return 'Rank Math';
		}

		if ( defined( 'AIOSEO_VERSION' ) && class_exists( 'AIOSEO\\Plugin\\Common\\Sitemap\\Sitemap' ) ) {
			return 'All in One SEO';
		}

		return false;
	}

	// ── Stats ─────────────────────────────────────────────────────────────────

	public static function get_url_count() {
		$settings   = self::get_settings();
		$post_types = array_keys( self::get_enabled_post_types( $settings ) );
		$exclude    = self::parse_exclude_ids( $settings['exclude_ids'] ?? '' );
		$total      = 0;

		foreach ( $post_types as $post_type ) {
			$args = array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);
			if ( ! empty( $exclude ) ) {
				$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}
			$total += count( get_posts( $args ) );
		}

		return $total;
	}
}
