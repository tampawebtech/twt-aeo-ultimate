<?php
/**
 * Funnel Audit — Money Page Funnel & Citability Auditor engine.
 *
 * AI engines cite informational posts far more often than money pages,
 * which leaks conversions. This engine connects four first-party signals:
 *
 *  1. Money-page identification — WooCommerce products, EDD downloads,
 *     service/contact intents from the Page Intent Classifier, pages with
 *     lead-gen forms, plus a per-post manual override.
 *  2. Internal link graph — parses post_content for internal links to
 *     compute click depth from the homepage/nav, inbound link counts, and
 *     anchor-text quality.
 *  3. On-page checks — stored schema types from the Scan Store, and whether
 *     informational pages carry a contextual "bridge" link to a money page.
 *  4. AI attention — AI Crawler Watch hits (bots reading the page) and AI
 *     Referral counts (people arriving from ChatGPT/Perplexity/etc.).
 *
 * The output is a per-page table with recommended actions, rebuilt weekly
 * by cron and on demand.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Funnel_Audit {

	const CRON_HOOK     = 'twtaeo_funnel_audit_build';
	const OPTION_AUDIT  = 'twtaeo_funnel_audit';
	const META_OVERRIDE = '_twtaeo_money_page'; // 'yes' | 'no' | '' (auto).

	/**
	 * Most pages a build will parse.
	 */
	const MAX_POSTS = 400;

	/**
	 * Anchor texts that pass no context to the target.
	 *
	 * @var string[]
	 */
	private static $generic_anchors = array(
		'click here',
		'here',
		'read more',
		'learn more',
		'more',
		'this',
		'link',
		'this page',
		'check it out',
		'view',
		'see more',
	);

	/**
	 * Register cron + schedule.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'build' ) );

		if ( self::is_module_active() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( strtotime( 'tomorrow 05:00' ), 'weekly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove the schedule (deactivation).
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Whether the funnel-audit module is switched on.
	 *
	 * @return bool
	 */
	public static function is_module_active() {
		return in_array( 'funnel-audit', (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true );
	}

	/**
	 * Stored audit.
	 *
	 * @return array Empty when never built.
	 */
	public static function get_audit() {
		return (array) get_option( self::OPTION_AUDIT, array() );
	}

	/**
	 * Set or clear the manual money-page override for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   'yes', 'no', or '' to return to automatic.
	 */
	public static function set_override( $post_id, $value ) {
		if ( in_array( $value, array( 'yes', 'no' ), true ) ) {
			update_post_meta( $post_id, self::META_OVERRIDE, $value );
		} else {
			delete_post_meta( $post_id, self::META_OVERRIDE );
		}
	}

	/**
	 * Build the audit and store it.
	 *
	 * @return array The stored audit.
	 */
	public static function build() {
		if ( ! self::is_module_active() ) {
			return array();
		}

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$post_types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}
		if ( post_type_exists( 'download' ) ) {
			$post_types[] = 'download';
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_POSTS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		// ------------------------------------------------------------------
		// Pass 1: permalink lookup + classification.
		// ------------------------------------------------------------------
		$by_path = array(); // normalized path => post ID.
		$pages   = array(); // post ID => working row.

		$front_page_id = (int) get_option( 'page_on_front' );

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( ! $permalink ) {
				continue;
			}
			$path             = self::normalize_path( $permalink );
			$by_path[ $path ] = $post->ID;

			$pages[ $post->ID ] = array(
				'id'        => $post->ID,
				'url'       => $permalink,
				'path'      => $path,
				'title'     => get_the_title( $post ),
				'post_type' => $post->post_type,
				'class'     => self::classify( $post, $front_page_id ),
				'intent'    => (string) get_post_meta( $post->ID, '_twtaeo_intent', true ),
				'schema'    => self::schema_types_for( $post->ID ),
				'outbound'  => array(), // post IDs this page links to.
				'inbound'   => 0,
				'generic'   => 0,       // inbound links with generic anchors.
				'depth'     => null,
				'has_cta'   => false,
			);
		}

		// ------------------------------------------------------------------
		// Pass 2: link extraction.
		// ------------------------------------------------------------------
		foreach ( $posts as $post ) {
			if ( ! isset( $pages[ $post->ID ] ) ) {
				continue;
			}

			$content = (string) $post->post_content;
			$links   = self::extract_links( $content );

			foreach ( $links as $link ) {
				$target_path = self::normalize_internal( $link['href'] );
				if ( null === $target_path || ! isset( $by_path[ $target_path ] ) ) {
					continue;
				}
				$target_id = $by_path[ $target_path ];
				if ( $target_id === $post->ID ) {
					continue;
				}

				if ( ! in_array( $target_id, $pages[ $post->ID ]['outbound'], true ) ) {
					$pages[ $post->ID ]['outbound'][] = $target_id;
				}
				$pages[ $target_id ]['inbound']++;
				if ( self::is_generic_anchor( $link['anchor'] ) ) {
					$pages[ $target_id ]['generic']++;
				}
			}

			$pages[ $post->ID ]['has_cta'] = self::has_cta( $content );
		}

		// ------------------------------------------------------------------
		// Pass 3: click depth (BFS from homepage + nav menus = depth 1).
		// Structural archive pages (shop, posts page) also count as depth 1 —
		// themes link them from every header even when no nav menu exists.
		// ------------------------------------------------------------------
		$queue = array();

		foreach ( self::nav_menu_post_ids() as $nav_id ) {
			if ( isset( $pages[ $nav_id ] ) && null === $pages[ $nav_id ]['depth'] ) {
				$pages[ $nav_id ]['depth'] = 1;
				$queue[]                   = $nav_id;
			}
		}

		$shop_page_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$posts_page_id = (int) get_option( 'page_for_posts' );

		foreach ( array( $shop_page_id, $posts_page_id ) as $archive_id ) {
			if ( $archive_id > 0 && isset( $pages[ $archive_id ] ) && null === $pages[ $archive_id ]['depth'] ) {
				$pages[ $archive_id ]['depth'] = 1;
				$queue[]                       = $archive_id;
			}
		}

		if ( $front_page_id && isset( $pages[ $front_page_id ] ) ) {
			$pages[ $front_page_id ]['depth'] = 0;
			array_unshift( $queue, $front_page_id );
		}

		while ( $queue ) {
			$current = array_shift( $queue );
			$depth   = $pages[ $current ]['depth'];
			foreach ( $pages[ $current ]['outbound'] as $next ) {
				if ( isset( $pages[ $next ] ) && null === $pages[ $next ]['depth'] ) {
					$pages[ $next ]['depth'] = $depth + 1;
					$queue[]                 = $next;
				}
			}
		}

		// Products are reachable through the shop/category archives even with
		// zero content links; posts through the blog index (or the front page
		// when the site shows latest posts there). Model that as archive + 1
		// so they don't all read as orphans — the inbound-link advice still
		// tells the real contextual-linking story.
		$shop_depth  = ( $shop_page_id && isset( $pages[ $shop_page_id ] ) && null !== $pages[ $shop_page_id ]['depth'] ) ? $pages[ $shop_page_id ]['depth'] : 1;
		$posts_depth = ( $posts_page_id && isset( $pages[ $posts_page_id ] ) && null !== $pages[ $posts_page_id ]['depth'] ) ? $pages[ $posts_page_id ]['depth'] : ( $front_page_id ? null : 0 );

		foreach ( $pages as $id => $row ) {
			if ( null !== $row['depth'] ) {
				continue;
			}
			if ( 'product' === $row['post_type'] && $shop_page_id ) {
				$pages[ $id ]['depth'] = $shop_depth + 1;
			} elseif ( 'post' === $row['post_type'] && null !== $posts_depth ) {
				$pages[ $id ]['depth'] = $posts_depth + 1;
			}
		}

		// ------------------------------------------------------------------
		// Pass 4: AI attention + actions.
		// ------------------------------------------------------------------
		$referrals = class_exists( 'TWTAEO_AI_Referrals' ) ? TWTAEO_AI_Referrals::get_counts( 30 ) : array();
		$crawls    = self::crawl_counts( 30 );
		$money_ids = array();

		foreach ( $pages as $id => $row ) {
			if ( 'money' === $row['class'] ) {
				$money_ids[] = $id;
			}
		}

		$rows = array();

		foreach ( $pages as $id => $row ) {
			$row['referrals'] = isset( $referrals[ $row['path'] ] ) ? (int) $referrals[ $row['path'] ]['total'] : 0;
			$row['crawls']    = isset( $crawls[ $row['path'] ] ) ? (int) $crawls[ $row['path'] ] : 0;
			$row['bridge']    = (bool) array_intersect( $row['outbound'], $money_ids );
			$row['actions']   = self::actions_for( $row, $money_ids, $pages );

			unset( $row['outbound'] );
			$rows[] = $row;
		}

		// Sort: money pages first, then by AI attention, then inbound links.
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['class'] !== $b['class'] ) {
					if ( 'money' === $a['class'] ) {
						return -1;
					}
					if ( 'money' === $b['class'] ) {
						return 1;
					}
				}
				$attention_a = $a['referrals'] + $a['crawls'];
				$attention_b = $b['referrals'] + $b['crawls'];
				if ( $attention_a !== $attention_b ) {
					return $attention_b - $attention_a;
				}
				return $b['inbound'] - $a['inbound'];
			}
		);

		$audit = array(
			'built_at' => current_time( 'mysql' ),
			'scanned'  => count( $rows ),
			'rows'     => array_slice( $rows, 0, 100 ),
		);

		update_option( self::OPTION_AUDIT, $audit, false );

		return $audit;
	}

	// ── Classification ───────────────────────────────────────────────────────

	/**
	 * Classify a post: money | info | home.
	 *
	 * @param WP_Post $post          Post.
	 * @param int     $front_page_id Static front page ID.
	 * @return string
	 */
	private static function classify( $post, $front_page_id ) {
		if ( $front_page_id && $post->ID === $front_page_id ) {
			return 'home';
		}

		$override = get_post_meta( $post->ID, self::META_OVERRIDE, true );
		if ( 'yes' === $override ) {
			return 'money';
		}
		if ( 'no' === $override ) {
			return 'info';
		}

		if ( in_array( $post->post_type, array( 'product', 'download' ), true ) ) {
			return 'money';
		}

		$intent = (string) get_post_meta( $post->ID, '_twtaeo_intent', true );
		if ( in_array( $intent, array( 'service', 'product', 'product_service', 'contact', 'location' ), true ) ) {
			return 'money';
		}

		// Lead-gen forms make a page a conversion target.
		if ( self::has_form( (string) $post->post_content ) ) {
			return 'money';
		}

		return 'info';
	}

	/**
	 * Whether content contains a lead-gen form.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function has_form( $content ) {
		return (bool) preg_match(
			'/\[(?:gravityform|wpforms|contact-form-7|fluentform|formidable|ninja_form)|wp:(?:wpforms|gravityforms|contact-form-7|jetpack\/contact-form)|<form[\s>]/i',
			$content
		);
	}

	/**
	 * Whether content contains any call-to-action marker.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function has_cta( $content ) {
		if ( self::has_form( $content ) ) {
			return true;
		}
		return (bool) preg_match(
			'/wp:buttons?|class="[^"]*(?:btn|button|cta)[^"]*"|href="[^"]*\/(?:checkout|cart|contact|pricing|book|quote|demo|signup|sign-up|get-started)/i',
			$content
		);
	}

	// ── Link graph helpers ───────────────────────────────────────────────────

	/**
	 * Extract links (href + anchor text) from HTML content.
	 *
	 * @param string $content HTML.
	 * @return array<array{href:string,anchor:string}>
	 */
	private static function extract_links( $content ) {
		$links = array();
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/is', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$links[] = array(
					'href'   => html_entity_decode( $match[1] ),
					'anchor' => strtolower( trim( wp_strip_all_tags( $match[2] ) ) ),
				);
			}
		}
		return $links;
	}

	/**
	 * Normalize an internal href to a comparable path, or null when external.
	 *
	 * @param string $href Href value.
	 * @return string|null
	 */
	private static function normalize_internal( $href ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
			return self::normalize_path( home_url( $href ) );
		}

		$host = wp_parse_url( $href, PHP_URL_HOST );
		if ( ! $host || strtolower( $host ) !== strtolower( (string) $home_host ) ) {
			return null;
		}

		return self::normalize_path( $href );
	}

	/**
	 * Path portion of a URL, normalized with a trailing slash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = $path ? $path : '/';
		return trailingslashit( strtolower( $path ) );
	}

	/**
	 * Whether an anchor text passes no context.
	 *
	 * @param string $anchor Lowercased anchor text.
	 * @return bool
	 */
	private static function is_generic_anchor( $anchor ) {
		return '' === $anchor || in_array( $anchor, self::$generic_anchors, true );
	}

	/**
	 * Post IDs linked from any nav menu.
	 *
	 * @return int[]
	 */
	private static function nav_menu_post_ids() {
		$ids   = array();
		$menus = wp_get_nav_menus();

		foreach ( (array) $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			foreach ( (array) $items as $item ) {
				if ( ! empty( $item->object_id ) && 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	// ── Signals ──────────────────────────────────────────────────────────────

	/**
	 * Schema types stored for a post by the Scan Store.
	 *
	 * @param int $post_id Post ID.
	 * @return string Comma-separated types, or ''.
	 */
	private static function schema_types_for( $post_id ) {
		$schema = get_post_meta( $post_id, '_twtaeo_schema', true );

		// Scan Store shape: { page_title, schema_types: string[], source }.
		if ( is_array( $schema ) && ! empty( $schema['schema_types'] ) && is_array( $schema['schema_types'] ) ) {
			return implode( ', ', array_unique( array_filter( array_map( 'strval', $schema['schema_types'] ) ) ) );
		}

		// Tolerate older shapes: a plain list of type strings.
		if ( is_array( $schema ) && $schema ) {
			$flat = array();
			foreach ( $schema as $entry ) {
				if ( is_string( $entry ) ) {
					$flat[] = $entry;
				}
			}
			return implode( ', ', array_unique( array_filter( $flat ) ) );
		}

		return '';
	}

	/**
	 * AI crawler hits per path over the last N days, from the AI Crawler Watch log.
	 *
	 * @param int $days Window in days.
	 * @return array<string,int> path => hits.
	 */
	private static function crawl_counts( $days = 30 ) {
		if ( ! class_exists( 'TWTAEO_AI_Crawler_Logger' ) ) {
			return array();
		}

		$out    = array();
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		foreach ( (array) get_option( TWTAEO_AI_Crawler_Logger::OPTION_LOG, array() ) as $entry ) {
			if ( empty( $entry['time'] ) || $entry['time'] < $cutoff || empty( $entry['url'] ) ) {
				continue;
			}
			$path = self::normalize_path( $entry['url'] );
			$out[ $path ] = ( isset( $out[ $path ] ) ? $out[ $path ] : 0 ) + 1;
		}

		return $out;
	}

	// ── Recommendations ──────────────────────────────────────────────────────

	/**
	 * Action recommendations for one page row.
	 *
	 * @param array $row       Working row.
	 * @param int[] $money_ids All money page IDs.
	 * @param array $pages     All working rows keyed by ID.
	 * @return string[]
	 */
	private static function actions_for( $row, $money_ids, $pages ) {
		$actions   = array();
		$attention = $row['referrals'] + $row['crawls'];

		if ( 'money' === $row['class'] ) {
			if ( null === $row['depth'] ) {
				$actions[] = __( 'Orphaned — not reachable from the homepage or navigation. Add it to the main nav or link it from key pages.', 'twt-aeo-ultimate' );
			} elseif ( $row['depth'] >= 3 ) {
				$actions[] = sprintf(
					/* translators: %d: click depth. */
					__( 'Buried at click depth %d — add to the main navigation or link from your top informational guides.', 'twt-aeo-ultimate' ),
					$row['depth']
				);
			}

			if ( $row['inbound'] < 3 ) {
				$actions[] = sprintf(
					/* translators: %d: inbound link count. */
					_n( 'Only %d inbound internal link — link to this page from informational posts.', 'Only %d inbound internal links — link to this page from informational posts.', $row['inbound'], 'twt-aeo-ultimate' ),
					$row['inbound']
				);
			} elseif ( $row['inbound'] > 0 && ( $row['generic'] / max( 1, $row['inbound'] ) ) > 0.5 ) {
				$actions[] = __( 'Most inbound anchors are generic ("click here") — rewrite them with descriptive commercial anchor text.', 'twt-aeo-ultimate' );
			}

			if ( '' === $row['schema'] ) {
				$actions[] = __( 'No schema detected — a money page needs Product, Service or Offer schema for AI engines to understand what is sold.', 'twt-aeo-ultimate' );
			} elseif ( ! preg_match( '/\b(Product|Service|Offer|SoftwareApplication|Course|Event)\b/', $row['schema'] ) ) {
				$actions[] = sprintf(
					/* translators: %s: detected schema types. */
					__( 'Schema present (%s) but no Product/Service/Offer node — AI engines cannot tell what this page sells.', 'twt-aeo-ultimate' ),
					$row['schema']
				);
			}
		}

		if ( 'info' === $row['class'] && $attention > 0 && ! $row['bridge'] && ! empty( $money_ids ) ) {
			// Suggest the money page with the most inbound links as the bridge target.
			$best       = null;
			$best_score = -1;
			foreach ( $money_ids as $money_id ) {
				if ( isset( $pages[ $money_id ] ) && $pages[ $money_id ]['inbound'] > $best_score ) {
					$best_score = $pages[ $money_id ]['inbound'];
					$best       = $pages[ $money_id ];
				}
			}
			$actions[] = sprintf(
				/* translators: 1: AI attention count, 2: suggested money page title. */
				__( 'AI engines touched this page %1$d times in 30 days but it has no link to any money page — add a contextual CTA bridge (e.g. to "%2$s").', 'twt-aeo-ultimate' ),
				$attention,
				$best ? $best['title'] : ''
			);
		}

		if ( 'info' === $row['class'] && $attention > 0 && $row['bridge'] && ! $row['has_cta'] ) {
			$actions[] = __( 'Links to a money page but has no visible CTA — add a button or form so AI-referred visitors have a next step.', 'twt-aeo-ultimate' );
		}

		return $actions;
	}
}
