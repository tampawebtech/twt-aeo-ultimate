<?php
/**
 * Bot View — schema visibility audit engine.
 *
 * Fetches a page the way a no-JavaScript AI crawler does (GPTBot, ClaudeBot,
 * PerplexityBot and most AI agents never execute JS), then reports:
 *
 *  - whether bots receive the same page a browser does (firewall blocks,
 *    challenge pages, cloaked/reduced content),
 *  - which structured data is actually visible in the raw HTML,
 *  - which schema/content only exists after JavaScript runs (tag-manager-
 *    injected JSON-LD, review widgets, social embeds, client-rendered
 *    mount points, AJAX, lazy-loaded images),
 *  - which of that data is recoverable server-side because it already sits
 *    in the HTML as JSON (hydration state, dataLayer pushes, variation data)
 *    and could be re-emitted as static schema.
 *
 * Also runs a weekly site-wide scan over representative URLs and stores a
 * compact summary for the Bot View screen.
 *
 * Pure PHP — no headless browser required.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Bot_View {

	/**
	 * User agent used for the bot-view fetch (mirrors a real AI crawler).
	 */
	const BOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

	/**
	 * Ordinary browser user agent, used for the comparison fetch that exposes
	 * firewalls and challenge pages serving bots something different.
	 */
	const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/**
	 * Weekly site-wide scan.
	 */
	const CRON_HOOK        = 'twtaeo_bot_view_site_scan';
	const OPTION_SITE_SCAN = 'twtaeo_bot_view_site_scan_results';

	/**
	 * Review/UGC widgets that render entirely client-side. Their content never
	 * reaches a no-JS crawler.
	 *
	 * @var array<string,string> substring => human label.
	 */
	private static $widget_fingerprints = array(
		'judge.me'         => 'Judge.me reviews',
		'yotpo'            => 'Yotpo reviews',
		'stamped.io'       => 'Stamped reviews',
		'loox.io'          => 'Loox reviews',
		'okendo'           => 'Okendo reviews',
		'trustpilot'       => 'Trustpilot widget',
		'reviews.io'       => 'Reviews.io widget',
		'bazaarvoice'      => 'Bazaarvoice reviews',
		'trustindex'       => 'Trustindex widget',
		'shopperapproved'  => 'Shopper Approved reviews',
		'elfsight'         => 'Elfsight widget',
		'tagembed'         => 'Tagembed widget',
		'sociablekit'      => 'SociableKIT widget',
		'powr.io'          => 'POWR widget',
		'disqus.com/embed' => 'Disqus comments',
	);

	/**
	 * Text fragments that identify a bot-challenge page (served with HTTP 200
	 * more often than not).
	 *
	 * @var string[]
	 */
	private static $challenge_fingerprints = array(
		'just a moment',
		'checking your browser',
		'cf-browser-verification',
		'_cf_chl_opt',
		'challenge-platform',
		'attention required! | cloudflare',
		'px-captcha',
		'datadome',
		'are you a robot',
		'verify you are human',
	);

	/**
	 * Register cron hooks and (when the module is active) the weekly schedule.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_site_scan' ) );

		if ( self::is_module_active() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( strtotime( 'tomorrow 04:00' ), 'weekly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled scan (deactivation).
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Whether the bot-view module is switched on.
	 *
	 * @return bool
	 */
	public static function is_module_active() {
		return in_array( 'bot-view', (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true );
	}

	/**
	 * Fetch a URL with a given user agent.
	 *
	 * @param string $url        URL to fetch.
	 * @param string $user_agent User agent (defaults to the bot UA).
	 * @return array|WP_Error { code: int, body: string }
	 */
	public static function fetch( $url, $user_agent = null ) {
		$url = esc_url_raw( $url );

		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'twtaeo_bot_view_url', __( 'Enter a valid http(s) URL.', 'twt-aeo-ultimate' ) );
		}

		/**
		 * Whether to allow auditing private/loopback hosts (blocked by
		 * wp_safe_remote_get by default). Enabled automatically when this
		 * site itself runs on a local development host.
		 *
		 * @param bool $allow Allow private hosts.
		 */
		$allow_private = apply_filters( 'twtaeo_bot_view_allow_private_hosts', self::site_is_local() );

		if ( $allow_private ) {
			add_filter( 'http_request_host_is_external', '__return_true' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => (int) apply_filters( 'twtaeo_bot_view_timeout', 15 ),
				'redirection' => 3,
				'user-agent'  => $user_agent ? $user_agent : self::BOT_UA,
				'headers'     => array(
					'Accept'             => 'text/html,application/xhtml+xml',
					// Lets this site's own AI Crawler Watch tell a self-audit
					// from a real crawler visit (external sites just ignore it).
					'X-TWTAEO-Bot-View'  => '1',
				),
			)
		);

		if ( $allow_private ) {
			remove_filter( 'http_request_host_is_external', '__return_true' );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Full audit: fetch as bot AND as a browser, compare the two, then analyse
	 * what the bot actually received.
	 *
	 * @param string $url URL to audit.
	 * @return array|WP_Error Report (analyze() shape plus 'meta').
	 */
	public static function audit( $url ) {
		$bot = self::fetch( $url, self::BOT_UA );
		if ( is_wp_error( $bot ) ) {
			return $bot;
		}

		$browser  = self::fetch( $url, self::BROWSER_UA );
		$findings = array();

		$bot_size     = strlen( $bot['body'] );
		$browser_size = is_wp_error( $browser ) ? 0 : strlen( $browser['body'] );
		$browser_code = is_wp_error( $browser ) ? 0 : $browser['code'];

		// --- Firewall: bot refused while browsers get the page. -------------
		if ( ! is_wp_error( $browser ) && $browser_code < 400 && $bot['code'] >= 400 ) {
			$findings[] = array(
				'id'     => 'firewall-block',
				'level'  => 'critical',
				'title'  => sprintf(
					/* translators: 1: HTTP code for bots, 2: HTTP code for browsers. */
					__( 'Firewall blocks AI crawlers (bots get HTTP %1$d, browsers HTTP %2$d)', 'twt-aeo-ultimate' ),
					$bot['code'],
					$browser_code
				),
				'detail' => __( 'A security layer refuses this page to the GPTBot user agent while serving it normally to browsers. AI crawlers cannot index anything here. Allow-list the AI crawler user agents in your firewall or CDN bot settings.', 'twt-aeo-ultimate' ),
			);
		}

		// --- Challenge page served to bots (usually with HTTP 200). ---------
		$bot_lower     = strtolower( substr( $bot['body'], 0, 200000 ) );
		$browser_lower = is_wp_error( $browser ) ? '' : strtolower( substr( $browser['body'], 0, 200000 ) );
		foreach ( self::$challenge_fingerprints as $needle ) {
			if ( false !== strpos( $bot_lower, $needle ) && ( '' === $browser_lower || false === strpos( $browser_lower, $needle ) ) ) {
				$findings[] = array(
					'id'     => 'challenge-page',
					'level'  => 'critical',
					'title'  => __( 'Bot-challenge page served to AI crawlers', 'twt-aeo-ultimate' ),
					'detail' => sprintf(
						/* translators: %s: matched fingerprint text. */
						__( 'The response sent to the bot contains a verification/challenge page ("%s") instead of your content — often with HTTP 200, so it never shows up as an error. Crawlers index the challenge text, not your page. Check Cloudflare Bot Fight Mode or similar protections.', 'twt-aeo-ultimate' ),
						$needle
					),
				);
				break;
			}
		}

		// --- Same status, but the bot copy is drastically smaller. ----------
		if ( ! is_wp_error( $browser ) && $bot['code'] < 400 && $browser_code < 400 && $browser_size > 20000 && $bot_size < ( $browser_size * 0.3 ) ) {
			$findings[] = array(
				'id'     => 'bot-smaller-page',
				'level'  => 'warn',
				'title'  => sprintf(
					/* translators: 1: bot page size in KB, 2: browser page size in KB. */
					__( 'Bots receive a much smaller page (%1$d KB vs %2$d KB for browsers)', 'twt-aeo-ultimate' ),
					(int) ( $bot_size / 1024 ),
					(int) ( $browser_size / 1024 )
				),
				'detail' => __( 'Something between your server and the crawler serves bots reduced content. Compare the two responses — user-agent-based caching, ad/bot middleware, or a misconfigured CDN rule are the usual causes.', 'twt-aeo-ultimate' ),
			);
		}

		if ( empty( $findings ) && ! is_wp_error( $browser ) && $bot['code'] < 400 ) {
			$findings[] = array(
				'level'  => 'good',
				'title'  => __( 'Bots receive the same page as browsers', 'twt-aeo-ultimate' ),
				'detail' => sprintf(
					/* translators: 1: bot HTTP code, 2: bot page size in KB. */
					__( 'The GPTBot user agent got HTTP %1$d and %2$d KB of HTML, in line with what a browser receives — no firewall or challenge interference.', 'twt-aeo-ultimate' ),
					$bot['code'],
					(int) ( $bot_size / 1024 )
				),
			);
		}

		// Even a blocked bot response is worth analysing if it carried HTML.
		$report             = self::analyze( $bot['body'], $url );
		$report['findings'] = array_merge( $findings, $report['findings'] );
		$report['meta']     = array(
			'bot_code'     => $bot['code'],
			'bot_size'     => $bot_size,
			'browser_code' => $browser_code,
			'browser_size' => $browser_size,
		);

		return $report;
	}

	/**
	 * Analyse raw (pre-JavaScript) HTML.
	 *
	 * @param string $html Raw HTML as served to the bot.
	 * @param string $url  Source URL (used for context hints only).
	 * @return array {
	 *     findings:     array<array{level:string,title:string,detail:string}>,
	 *     recovered:    array<array{source:string,keys:string[]}>,
	 *     schema_types: string[],
	 * }
	 */
	public static function analyze( $html, $url = '' ) {
		$findings  = array();
		$recovered = array();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$is_product_page = (bool) preg_match( '#/product[s]?/|[?&]product=#i', $url );

		// ------------------------------------------------------------------
		// 1. Indexability + static JSON-LD.
		// ------------------------------------------------------------------
		$robots_meta = $xpath->query( '//meta[@name="robots"]/@content' );
		if ( $robots_meta && $robots_meta->length && false !== stripos( $robots_meta->item( 0 )->nodeValue, 'noindex' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$findings[] = array(
				'id'     => 'noindex',
				'level'  => 'critical',
				'title'  => __( 'Page is set to noindex in the copy bots receive', 'twt-aeo-ultimate' ),
				'detail' => __( 'The robots meta tag tells crawlers not to index this page. If that is intentional, everything else here is moot; if not, find what sets it (SEO plugin setting, membership plugin, staging flag).', 'twt-aeo-ultimate' ),
			);
		}

		$ld_nodes     = self::extract_ldjson( $xpath );
		$schema_types = $ld_nodes['types'];

		if ( empty( $schema_types ) ) {
			$findings[] = array(
				'id'     => 'no-static-schema',
				'level'  => 'critical',
				'title'  => __( 'No JSON-LD in the raw HTML', 'twt-aeo-ultimate' ),
				'detail' => __( 'AI crawlers that do not run JavaScript see zero structured data on this page. If schema is added by a tag manager or theme JavaScript, it is invisible to them — it must be rendered server-side.', 'twt-aeo-ultimate' ),
			);
		} else {
			$findings[] = array(
				'level'  => 'good',
				'title'  => sprintf(
					/* translators: %s: list of schema.org types. */
					__( 'Static JSON-LD found: %s', 'twt-aeo-ultimate' ),
					implode( ', ', $schema_types )
				),
				'detail' => __( 'These nodes are served in the raw HTML, so every crawler and AI agent can read them without executing JavaScript.', 'twt-aeo-ultimate' ),
			);
		}

		// Product node completeness.
		if ( $ld_nodes['product'] ) {
			$missing = self::product_missing_props( $ld_nodes['product'] );
			if ( $missing ) {
				$findings[] = array(
					'id'     => 'product-schema-gaps',
					'level'  => 'warn',
					'title'  => sprintf(
						/* translators: %s: list of missing schema properties. */
						__( 'Product schema is missing: %s', 'twt-aeo-ultimate' ),
						implode( ', ', $missing )
					),
					'detail' => __( 'These properties help AI shopping agents ground and recommend the product.', 'twt-aeo-ultimate' ),
				);
			} else {
				$findings[] = array(
					'level'  => 'good',
					'title'  => __( 'Product schema looks complete', 'twt-aeo-ultimate' ),
					'detail' => __( 'Name, image, description, SKU, brand, offer and rating are all present in the static markup.', 'twt-aeo-ultimate' ),
				);
			}
		} elseif ( $is_product_page ) {
			$findings[] = array(
				'id'     => 'no-product-schema',
				'level'  => 'critical',
				'title'  => __( 'No static Product schema on a product page', 'twt-aeo-ultimate' ),
				'detail' => __( 'This looks like a product URL but no Product node is present in the raw HTML.', 'twt-aeo-ultimate' ),
			);
		}

		// ------------------------------------------------------------------
		// 2. Tag-manager-injected schema.
		// ------------------------------------------------------------------
		$has_gtm = ( false !== stripos( $html, 'googletagmanager.com/gtm.js' ) ) || preg_match( '/GTM-[A-Z0-9]{4,}/', $html );
		if ( $has_gtm ) {
			$findings[] = array(
				'id'     => 'gtm-schema',
				'level'  => empty( $schema_types ) ? 'critical' : 'info',
				'title'  => __( 'Google Tag Manager detected', 'twt-aeo-ultimate' ),
				'detail' => empty( $schema_types )
					? __( 'GTM is present and no static JSON-LD was found. If your schema is injected through a GTM tag, no-JS crawlers never see it — it must be moved server-side.', 'twt-aeo-ultimate' )
					: __( 'GTM is present. Make sure schema is rendered server-side (as it is here) rather than through GTM tags, which no-JS crawlers cannot see.', 'twt-aeo-ultimate' ),
			);
		}

		// ------------------------------------------------------------------
		// 3. Recoverable JSON sitting in the HTML (no browser needed).
		// ------------------------------------------------------------------
		$scripts = self::inline_scripts( $xpath );

		foreach ( array(
			'__NEXT_DATA__'      => 'Next.js hydration data',
			'__NUXT__'           => 'Nuxt hydration data',
			'__INITIAL_STATE__'  => 'App initial state',
			'__PRELOADED_STATE_' => 'Preloaded store state',
		) as $marker => $label ) {
			// The marker may live in an id attribute (Next.js) rather than the
			// script body, so detect against the whole document.
			if ( false === strpos( $html, $marker ) ) {
				continue;
			}
			$keys = array();
			foreach ( $scripts as $script ) {
				if ( false !== strpos( $script, $marker ) ) {
					$keys = self::sample_json_keys( $script );
					break;
				}
			}
			if ( ! $keys ) {
				$id_node = $xpath->query( '//script[@id="' . $marker . '"]' );
				if ( $id_node && $id_node->length ) {
					$keys = self::sample_json_keys( $id_node->item( 0 )->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}
			$recovered[] = array(
				'source' => $label,
				'keys'   => $keys,
			);
		}

		// dataLayer pushes.
		$datalayer_keys = array();
		foreach ( $scripts as $script ) {
			if ( preg_match_all( '/dataLayer\.push\s*\(\s*\{(.{0,2000}?)\}\s*\)/s', $script, $m ) ) {
				foreach ( $m[1] as $blob ) {
					$datalayer_keys = array_merge( $datalayer_keys, self::sample_json_keys( $blob ) );
				}
			}
		}
		$datalayer_keys = array_values( array_unique( $datalayer_keys ) );
		if ( $datalayer_keys ) {
			$recovered[] = array(
				'source' => 'dataLayer (analytics) pushes',
				'keys'   => $datalayer_keys,
			);
		}

		// WooCommerce variation data (attribute on the variation form).
		$variation_nodes = $xpath->query( '//*[@data-product_variations]' );
		if ( $variation_nodes && $variation_nodes->length ) {
			$recovered[] = array(
				'source' => 'WooCommerce variation form data (data-product_variations)',
				'keys'   => array( 'variations', 'attributes', 'prices', 'availability' ),
			);
		}

		// Generic product-shaped JS objects.
		$product_keys = array( 'price', 'sku', 'gtin', 'currency', 'availability', 'rating', 'review_count', 'reviews' );
		foreach ( $scripts as $script ) {
			$hits = 0;
			foreach ( $product_keys as $key ) {
				if ( preg_match( '/[\'"]?' . preg_quote( $key, '/' ) . '[\'"]?\s*:/', $script ) ) {
					$hits++;
				}
			}
			if ( $hits >= 3 ) {
				$recovered[] = array(
					'source' => 'Inline script with product data object',
					'keys'   => array_values( array_intersect( $product_keys, self::sample_json_keys( $script ) ) ),
				);
				break;
			}
		}

		if ( $recovered ) {
			$findings[] = array(
				'level'  => 'info',
				'title'  => sprintf(
					/* translators: %d: number of recoverable data sources. */
					_n( '%d machine-readable data source found inside scripts', '%d machine-readable data sources found inside scripts', count( $recovered ), 'twt-aeo-ultimate' ),
					count( $recovered )
				),
				'detail' => __( 'This data ships in the HTML as JSON but is meant for JavaScript, so crawlers ignore it. Anything listed below that is missing from your schema can be lifted into server-rendered JSON-LD.', 'twt-aeo-ultimate' ),
			);
		}

		// ------------------------------------------------------------------
		// 4. Client-side-only content (NOT recoverable from the HTML).
		// ------------------------------------------------------------------
		foreach ( self::$widget_fingerprints as $needle => $label ) {
			if ( false !== stripos( $html, $needle ) ) {
				$has_rating = $ld_nodes['product'] && ! empty( $ld_nodes['product']['aggregateRating'] );
				$findings[] = array(
					'id'     => 'client-side-reviews',
					'level'  => $has_rating ? 'info' : 'warn',
					'title'  => sprintf(
						/* translators: %s: widget name. */
						__( '%s renders client-side', 'twt-aeo-ultimate' ),
						$label
					),
					'detail' => $has_rating
						? __( 'The widget content itself is invisible to no-JS crawlers, but an aggregateRating is present in your static schema, which covers the essentials.', 'twt-aeo-ultimate' )
						: __( 'Its stars and review text never appear in the raw HTML — to crawlers this page has no reviews. The compliant fix: render the reviews (or a representative sample) as visible HTML on this page, with matching Review schema carrying author, datePublished and a link to the original source. Google requires marked-up reviews to be first-party and visible on the marked-up page — never add aggregateRating alone for reviews a crawler cannot see. The Reviews module in this plugin publishes compliant first-party review schema.', 'twt-aeo-ultimate' ),
				);
			}
		}

		// Social embeds — do they ship crawlable fallback text?
		$findings = array_merge( $findings, self::social_embed_findings( $xpath, $html ) );

		// Empty SPA mount points.
		$mounts = $xpath->query( '//div[@id="root" or @id="app" or @id="__next" or @id="___gatsby"]' );
		if ( $mounts ) {
			foreach ( $mounts as $mount ) {
				if ( strlen( trim( $mount->textContent ) ) < 50 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$findings[] = array(
						'id'     => 'empty-mount',
						'level'  => 'critical',
						'title'  => __( 'Empty JavaScript mount point detected', 'twt-aeo-ultimate' ),
						'detail' => sprintf(
							/* translators: %s: element id. */
							__( 'The container #%s is nearly empty in the raw HTML — the page content is rendered entirely by JavaScript, so no-JS crawlers see a blank page. Server-side rendering or static schema is the only fix.', 'twt-aeo-ultimate' ),
							$mount->getAttribute( 'id' )
						),
					);
					break;
				}
			}
		}

		// JS-lazy-loaded images with no real src.
		$lazy_imgs = $xpath->query( '//img[(@data-src or @data-lazy-src or @data-original) and (not(@src) or starts-with(@src, "data:"))]' );
		if ( $lazy_imgs && $lazy_imgs->length >= 3 ) {
			$findings[] = array(
				'id'     => 'js-lazy-images',
				'level'  => 'warn',
				'title'  => sprintf(
					/* translators: %d: number of images. */
					__( '%d images load only via JavaScript', 'twt-aeo-ultimate' ),
					$lazy_imgs->length
				),
				'detail' => __( 'These images have their real URL in a data-src attribute and no usable src, so no-JS crawlers and image search never see them. Modern browsers lazy-load natively — use a real src with loading="lazy" instead of a JS lazyload library.', 'twt-aeo-ultimate' ),
			);
		}

		// External iframes (excluding video players, reported separately).
		$iframe_count = 0;
		$iframes      = $xpath->query( '//iframe[@src]' );
		if ( $iframes ) {
			foreach ( $iframes as $iframe ) {
				$src = $iframe->getAttribute( 'src' );
				if ( preg_match( '#^https?://#i', $src ) && ! preg_match( '#youtube\.com|youtube-nocookie\.com|youtu\.be|vimeo\.com#i', $src ) ) {
					$iframe_count++;
				}
			}
		}
		if ( $iframe_count ) {
			$findings[] = array(
				'level'  => 'info',
				'title'  => sprintf(
					/* translators: %d: number of iframes. */
					_n( '%d external iframe on the page', '%d external iframes on the page', $iframe_count, 'twt-aeo-ultimate' ),
					$iframe_count
				),
				'detail' => __( 'Iframe content belongs to the framed site, not this page — crawlers do not credit it to you. Anything important inside an iframe should also exist as text or schema on the page itself.', 'twt-aeo-ultimate' ),
			);
		}

		// AJAX-loaded content hints.
		$ajax_hits = 0;
		foreach ( $scripts as $script ) {
			if ( preg_match( '/(fetch|axios|ajax)\s*\(.{0,200}?(admin-ajax\.php|\/wp-json\/)/s', $script ) ) {
				$ajax_hits++;
			}
		}
		if ( $ajax_hits ) {
			$findings[] = array(
				'level'  => 'info',
				'title'  => __( 'Content loaded by AJAX after page load', 'twt-aeo-ultimate' ),
				'detail' => __( 'Scripts on this page fetch additional content from the REST API or admin-ajax after load. Whatever they load (tabs, reviews, related items) is invisible to no-JS crawlers — keep anything important in the initial HTML or schema.', 'twt-aeo-ultimate' ),
			);
		}

		// ------------------------------------------------------------------
		// 5. Basic metadata + text visibility.
		// ------------------------------------------------------------------
		$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
		$og_image  = $xpath->query( '//meta[@property="og:image"]/@content' );
		if ( ( ! $meta_desc || ! $meta_desc->length ) || ( ! $og_image || ! $og_image->length ) ) {
			$missing_meta = array();
			if ( ! $meta_desc || ! $meta_desc->length ) {
				$missing_meta[] = 'meta description';
			}
			if ( ! $og_image || ! $og_image->length ) {
				$missing_meta[] = 'og:image';
			}
			$findings[] = array(
				'id'     => 'missing-metadata',
				'level'  => 'warn',
				'title'  => sprintf(
					/* translators: %s: list of missing meta tags. */
					__( 'Missing basic metadata: %s', 'twt-aeo-ultimate' ),
					implode( ', ', $missing_meta )
				),
				'detail' => __( 'AI agents and link previews fall back on these when schema is absent or incomplete.', 'twt-aeo-ultimate' ),
			);
		}

		// Visible text only — inline scripts/styles don't count as readable content.
		$body_text  = '';
		$text_nodes = $xpath->query( '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]' );
		if ( $text_nodes ) {
			foreach ( $text_nodes as $text_node ) {
				$body_text .= $text_node->nodeValue . ' '; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
		$body_text = preg_replace( '/\s+/', ' ', trim( $body_text ) );
		if ( strlen( $html ) > 50000 && strlen( $body_text ) < 800 ) {
			$findings[] = array(
				'id'     => 'no-readable-text',
				'level'  => 'critical',
				'title'  => __( 'Almost no readable text in the raw HTML', 'twt-aeo-ultimate' ),
				'detail' => sprintf(
					/* translators: 1: visible characters, 2: page size in KB. */
					__( 'Only %1$d characters of visible text in a %2$d KB page — the content is built by JavaScript. No-JS crawlers effectively see an empty page.', 'twt-aeo-ultimate' ),
					strlen( $body_text ),
					(int) ( strlen( $html ) / 1024 )
				),
			);
		}

		/**
		 * Filter the Bot View audit report.
		 *
		 * @param array  $report Report.
		 * @param string $html   Raw HTML.
		 * @param string $url    Audited URL.
		 */
		return apply_filters(
			'twtaeo_bot_view_report',
			array(
				'findings'     => $findings,
				'recovered'    => $recovered,
				'schema_types' => $schema_types,
			),
			$html,
			$url
		);
	}

	// ── Site-wide scheduled scan ─────────────────────────────────────────────

	/**
	 * Representative URLs to scan: homepage plus the most recently modified
	 * public posts, pages and products.
	 *
	 * @return string[]
	 */
	public static function scan_urls() {
		$urls = array( home_url( '/' ) );

		$post_types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		// Transactional/utility pages (cart, checkout, account and their
		// children) are meant to be thin — auditing them is pure noise.
		// Index Status already knows how to spot them (WooCommerce + EDD).
		$excluded_pages = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount', 'refund_returns' ) as $wc_page ) {
				$page_id = wc_get_page_id( $wc_page );
				if ( $page_id > 0 ) {
					$excluded_pages[] = $page_id;
				}
			}
		}
		if ( function_exists( 'edd_get_option' ) ) {
			foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page' ) as $edd_page ) {
				$page_id = (int) edd_get_option( $edd_page, 0 );
				if ( $page_id > 0 ) {
					$excluded_pages[] = $page_id;
				}
			}
		}

		foreach ( $post_types as $type ) {
			$recent = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => ( 'page' === $type ) ? 6 : 2,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			$taken = 0;
			foreach ( $recent as $post_id ) {
				if ( 'page' === $type ) {
					if ( $taken >= 3 ) {
						break;
					}
					$post = get_post( $post_id );
					if ( in_array( $post_id, $excluded_pages, true ) || ( $post && in_array( (int) $post->post_parent, $excluded_pages, true ) ) ) {
						continue;
					}
				}
				$permalink = get_permalink( $post_id );
				if ( $permalink && ! in_array( $permalink, $urls, true ) ) {
					$urls[] = $permalink;
					$taken++;
				}
			}
		}

		/**
		 * Filter the URLs included in the weekly Bot View site scan.
		 *
		 * @param string[] $urls URLs to scan (capped at 10 after the filter).
		 */
		return array_slice( apply_filters( 'twtaeo_bot_view_scan_urls', $urls ), 0, 10 );
	}

	/**
	 * Run the site-wide scan and store a compact summary.
	 *
	 * @return array The stored summary.
	 */
	public static function run_site_scan() {
		if ( ! self::is_module_active() ) {
			return array();
		}

		// Up to 10 pages × 2 fetches — make sure PHP doesn't cut the run short.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$previous = get_option( self::OPTION_SITE_SCAN, array() );
		$pages    = array();
		$totals   = array(
			'critical' => 0,
			'warn'     => 0,
			'info'     => 0,
			'good'     => 0,
		);

		foreach ( self::scan_urls() as $url ) {
			$report = self::audit( $url );

			if ( is_wp_error( $report ) ) {
				$pages[] = array(
					'url'      => $url,
					'error'    => $report->get_error_message(),
					'critical' => 0,
					'warn'     => 0,
					'issues'   => array(),
				);
				continue;
			}

			$counts = array(
				'critical' => 0,
				'warn'     => 0,
				'info'     => 0,
				'good'     => 0,
			);
			$issues = array();

			foreach ( $report['findings'] as $finding ) {
				if ( isset( $counts[ $finding['level'] ] ) ) {
					$counts[ $finding['level'] ]++;
					$totals[ $finding['level'] ]++;
				}
				if ( in_array( $finding['level'], array( 'critical', 'warn' ), true ) && count( $issues ) < 10 ) {
					// Full finding, not just the title — the Bot View screen
					// renders these as expandable details with a fix link.
					$issues[] = array(
						'id'     => isset( $finding['id'] ) ? $finding['id'] : '',
						'level'  => $finding['level'],
						'title'  => $finding['title'],
						'detail' => isset( $finding['detail'] ) ? $finding['detail'] : '',
					);
				}
			}

			$pages[] = array(
				'url'      => $url,
				'critical' => $counts['critical'],
				'warn'     => $counts['warn'],
				'issues'   => $issues,
			);
		}

		// Regression note: pages whose critical count grew since the last scan.
		$regressions   = array();
		$previous_urls = array();
		if ( ! empty( $previous['pages'] ) ) {
			foreach ( $previous['pages'] as $prev_page ) {
				$previous_urls[ $prev_page['url'] ] = (int) $prev_page['critical'];
			}
		}
		foreach ( $pages as $page ) {
			if ( isset( $previous_urls[ $page['url'] ] ) && $page['critical'] > $previous_urls[ $page['url'] ] ) {
				$regressions[] = $page['url'];
			}
		}

		$summary = array(
			'scanned_at'  => current_time( 'mysql' ),
			'pages'       => $pages,
			'totals'      => $totals,
			'regressions' => $regressions,
		);

		update_option( self::OPTION_SITE_SCAN, $summary, false );

		return $summary;
	}

	/**
	 * Stored site-scan summary.
	 *
	 * @return array Empty array when no scan has run yet.
	 */
	public static function get_site_scan() {
		return (array) get_option( self::OPTION_SITE_SCAN, array() );
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Findings for social embeds: which ship crawlable fallback text, which
	 * are invisible shells.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $html  Raw HTML.
	 * @return array
	 */
	private static function social_embed_findings( $xpath, $html ) {
		$findings = array();

		// Platforms whose official embed is a blockquote upgraded by JS —
		// whatever text is in the blockquote IS what crawlers see.
		$blockquote_embeds = array(
			'twitter-tweet'   => __( 'X/Twitter embed', 'twt-aeo-ultimate' ),
			'instagram-media' => __( 'Instagram embed', 'twt-aeo-ultimate' ),
			'tiktok-embed'    => __( 'TikTok embed', 'twt-aeo-ultimate' ),
		);

		foreach ( $blockquote_embeds as $class => $label ) {
			$nodes = $xpath->query( '//blockquote[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' );
			if ( ! $nodes || ! $nodes->length ) {
				continue;
			}

			$total_text = 0;
			foreach ( $nodes as $node ) {
				$total_text += strlen( preg_replace( '/\s+/', ' ', trim( $node->textContent ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			$avg_text = (int) ( $total_text / $nodes->length );

			if ( $avg_text >= 60 ) {
				$findings[] = array(
					'level'  => 'good',
					'title'  => sprintf(
						/* translators: 1: embed type, 2: number of embeds. */
						_n( '%1$s ships crawlable fallback text (%2$d embed)', '%1$s ships crawlable fallback text (%2$d embeds)', $nodes->length, 'twt-aeo-ultimate' ),
						$label,
						$nodes->length
					),
					'detail' => __( 'The embed includes its text content in a blockquote that JavaScript later upgrades to the interactive card — crawlers read the blockquote text, so the post content is visible to them.', 'twt-aeo-ultimate' ),
				);
			} else {
				$findings[] = array(
					'id'     => 'embed-no-fallback',
					'level'  => 'warn',
					'title'  => sprintf(
						/* translators: 1: embed type, 2: number of embeds. */
						_n( '%1$s has no crawlable text (%2$d embed)', '%1$s has no crawlable text (%2$d embeds)', $nodes->length, 'twt-aeo-ultimate' ),
						$label,
						$nodes->length
					),
					'detail' => __( 'The embed blockquote is essentially empty in the raw HTML — crawlers see a bare link, not the post content or caption. Paste the post text or a summary next to the embed so the content exists on your page.', 'twt-aeo-ultimate' ),
				);
			}
		}

		// Facebook embeds render into empty divs (or via the JS SDK).
		$fb_nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " fb-post ") or contains(concat(" ", normalize-space(@class), " "), " fb-video ") or contains(concat(" ", normalize-space(@class), " "), " fb-page ")]' );
		if ( ( $fb_nodes && $fb_nodes->length ) || false !== stripos( $html, 'connect.facebook.net' ) ) {
			$count = $fb_nodes ? max( 1, $fb_nodes->length ) : 1;
			$findings[] = array(
				'id'     => 'embed-no-fallback',
				'level'  => 'warn',
				'title'  => sprintf(
					/* translators: %d: number of embeds. */
					_n( 'Facebook embed renders client-side (%d embed)', 'Facebook embeds render client-side (%d embeds)', $count, 'twt-aeo-ultimate' ),
					$count
				),
				'detail' => __( 'Facebook embeds are empty containers filled by the Facebook SDK — no fallback text exists in the raw HTML, so crawlers see nothing. Quote the post text next to the embed if the content matters for search or AI.', 'twt-aeo-ultimate' ),
			);
		}

		// Video embeds: fine visually, but the video content itself needs schema.
		$video_iframes = $xpath->query( '//iframe[contains(@src, "youtube.com") or contains(@src, "youtube-nocookie.com") or contains(@src, "vimeo.com")]' );
		if ( $video_iframes && $video_iframes->length ) {
			$findings[] = array(
				'level'  => 'info',
				'title'  => sprintf(
					/* translators: %d: number of video embeds. */
					_n( '%d video embed on the page', '%d video embeds on the page', $video_iframes->length, 'twt-aeo-ultimate' ),
					$video_iframes->length
				),
				'detail' => __( 'Crawlers see that a player iframe exists but know nothing about the video. Add VideoObject schema (name, description, thumbnailUrl, uploadDate) so the video content is machine-readable.', 'twt-aeo-ultimate' ),
			);
		}

		return $findings;
	}

	/**
	 * Whether this WordPress install itself runs on a local development host.
	 *
	 * @return bool
	 */
	private static function site_is_local() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		if ( 'localhost' === $host || preg_match( '/\.(local|test|localhost)$/', $host ) ) {
			return true;
		}
		$ip = gethostbyname( $host );
		return $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Pull JSON-LD nodes out of the document.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array { types: string[], product: array|null }
	 */
	private static function extract_ldjson( $xpath ) {
		$types   = array();
		$product = null;
		$nodes   = $xpath->query( '//script[@type="application/ld+json"]' );

		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$decoded = json_decode( trim( $node->textContent ), true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				$queue = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
				foreach ( $queue as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['@type'] ) ) {
						continue;
					}
					foreach ( (array) $entry['@type'] as $type ) {
						if ( is_string( $type ) && ! in_array( $type, $types, true ) ) {
							$types[] = $type;
						}
					}
					if ( in_array( 'Product', (array) $entry['@type'], true ) && null === $product ) {
						$product = $entry;
					}
				}
			}
		}

		return array(
			'types'   => $types,
			'product' => $product,
		);
	}

	/**
	 * Which recommended Product properties are absent.
	 *
	 * @param array $product Product node.
	 * @return string[]
	 */
	private static function product_missing_props( $product ) {
		$missing = array();

		foreach ( array( 'name', 'image', 'description', 'sku', 'brand', 'aggregateRating' ) as $prop ) {
			if ( empty( $product[ $prop ] ) ) {
				$missing[] = $prop;
			}
		}

		if ( empty( $product['offers'] ) ) {
			$missing[] = 'offers';
		} else {
			$offer = isset( $product['offers'][0] ) ? $product['offers'][0] : $product['offers'];
			if ( is_array( $offer ) ) {
				if ( ! isset( $offer['price'] ) && ! isset( $offer['lowPrice'] ) ) {
					$missing[] = 'offers.price';
				}
				if ( empty( $offer['availability'] ) ) {
					$missing[] = 'offers.availability';
				}
			}
		}

		return $missing;
	}

	/**
	 * All inline script bodies.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return string[]
	 */
	private static function inline_scripts( $xpath ) {
		$out = array();
		// JSON-LD blocks are already-visible schema, not hidden data — skip them.
		$nodes = $xpath->query( '//script[not(@src) and not(@type="application/ld+json")]' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$text = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Sample the object keys present in a JS/JSON blob (tolerates unquoted keys).
	 *
	 * @param string $blob Script text.
	 * @return string[] Up to 12 distinct keys.
	 */
	private static function sample_json_keys( $blob ) {
		$keys = array();
		if ( preg_match_all( '/[\'"]?([a-zA-Z_][a-zA-Z0-9_]{2,30})[\'"]?\s*:/', $blob, $m ) ) {
			foreach ( $m[1] as $key ) {
				if ( ! in_array( $key, $keys, true ) && ! in_array( $key, array( 'function', 'return', 'window', 'document', 'https', 'http' ), true ) ) {
					$keys[] = $key;
				}
				if ( count( $keys ) >= 12 ) {
					break;
				}
			}
		}
		return $keys;
	}
}
