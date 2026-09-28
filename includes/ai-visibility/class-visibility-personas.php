<?php
/**
 * AI Visibility — buyer personas.
 *
 * A persona is WHO is asking: "machine shop owner", "manufacturing engineer",
 * "CNC machinist who does the purchasing". A run is asked as one persona (or as
 * none, the baseline), and the persona reaches the engine as system context
 * beside the shopper prompt — the question itself is never touched. See
 * TWTAEO_Visibility_Engines::system_prompt().
 *
 * One list per site, kept in the AI Visibility settings. The Local Pack page
 * (when that module is on) and the AI Visibility page both edit this same
 * list, so switching Local Pack off moves the field, never the data.
 *
 * When the owner has not written any, personas can be suggested from the
 * site's own words: the pages in its main menu, read as their meta title and
 * meta description (or their first paragraph when no SEO plugin set one),
 * handed to the site's cheap-tier AI provider. Suggested personas are saved
 * with source `site` and labelled as such until the owner edits them, so a
 * persona the AI guessed is never passed off as one the owner wrote.
 *
 * No UI here — the pages call this class.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Personas {

	/** Set once personas were suggested automatically, or the owner saved the list (even empty). Never auto-fill after that. */
	const OPTION_AUTO_DONE = 'twtaeo_visibility_personas_auto';

	/** Menu pages read for a suggestion. */
	const MAX_PAGES = 10;

	/** Characters kept per page description. */
	const MAX_DESC = 400;

	/** Shortest site text worth sending — below this the AI would be guessing. */
	const MIN_SIGNAL = 120;

	const MAX_LABEL = 80;

	/**
	 * A starting customer per Local Pack industry group, for when no AI provider
	 * can be reached. Groups come from TWTAEO_Page_Local_Pack::get_industry_group();
	 * 'generic' has none on purpose — a guess would be invented.
	 */
	const GROUP_DEFAULTS = array(
		'home_services'   => array( 'homeowner', 'property manager', 'landlord' ),
		'restaurant_food' => array( 'local diner', 'event planner', 'visitor to the area' ),
		'legal'           => array( 'individual needing legal help', 'small business owner' ),
		'healthcare'      => array( 'patient', 'parent of a patient', 'caregiver' ),
		'real_estate'     => array( 'home buyer', 'home seller', 'property investor' ),
		'automotive'      => array( 'car owner', 'fleet manager' ),
		'beauty_wellness' => array( 'new client', 'regular client' ),
		'financial'       => array( 'small business owner', 'individual taxpayer', 'retiree' ),
		'education'       => array( 'parent', 'student' ),
		'lodging'         => array( 'leisure traveler', 'business traveler', 'event guest' ),
		'pet_services'    => array( 'pet owner' ),
	);

	/** Meta-description keys, in priority order (same list as TWTAEO_Index_Heuristics). */
	const DESC_KEYS = array( '_twtaeo_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseop_description', 'seopress_titles_desc' );

	/** Meta-title keys. Templated values ("%%title%%", "{title}") are skipped. */
	const TITLE_KEYS = array( '_yoast_wpseo_title', 'rank_math_title', '_aioseop_title', 'seopress_titles_title' );

	private function __construct() {}

	/* ─────────────────────────── the list ─────────────────────────── */

	/**
	 * Clean a raw list into Persona[]. Accepts strings, `{label, source}` rows,
	 * or comma/newline text. Ids are slugs of the label, so a run that recorded
	 * "engineer" still matches the persona after a page reload.
	 *
	 * @param mixed $raw
	 * @return array Persona[] [ id, label, source ].
	 */
	public static function clean( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $raw );
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $row ) {
			$source = 'owner';
			if ( is_array( $row ) ) {
				$label  = isset( $row['label'] ) ? $row['label'] : '';
				$source = isset( $row['source'] ) && 'site' === $row['source'] ? 'site' : 'owner';
			} else {
				$label = $row;
			}
			$label = self::clean_label( $label );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_title( $label );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array( 'id' => $id, 'label' => $label, 'source' => $source );
			if ( count( $out ) >= TWTAEO_Visibility_Types::PERSONAS_MAX ) {
				break;
			}
		}
		return $out;
	}

	/** One label: plain text, single-spaced, no trailing punctuation, capped. */
	public static function clean_label( $label ) {
		$label = sanitize_text_field( wp_strip_all_tags( (string) $label ) );
		$label = trim( (string) preg_replace( '/\s+/u', ' ', $label ), " \t\"'.,;:" );
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $label, 0, self::MAX_LABEL ) );
		}
		return trim( substr( $label, 0, self::MAX_LABEL ) );
	}

	/**
	 * Whether the site uses buyer personas at all. Off hides the editors and
	 * the run picker and never suggests any — for professionals who want every
	 * run asked as nobody in particular. Follow-ups are recorded either way.
	 */
	public static function enabled() {
		$settings = TWTAEO_Visibility_Store::get_settings();
		return ! isset( $settings['personas_on'] ) || (bool) $settings['personas_on'];
	}

	/** @return array Persona[] */
	public static function all() {
		$settings = TWTAEO_Visibility_Store::get_settings();
		return isset( $settings['personas'] ) && is_array( $settings['personas'] ) ? $settings['personas'] : array();
	}

	/** The saved persona with this id, or null. */
	public static function find( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return null;
		}
		foreach ( self::all() as $p ) {
			if ( $id === (string) $p['id'] ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Save the owner's list. Any save — an empty one too — is a decision, so
	 * nothing is ever auto-filled over it afterwards.
	 *
	 * @param mixed $raw
	 * @return array The saved Persona[].
	 */
	public static function save( $raw ) {
		$saved = TWTAEO_Visibility_Store::save_settings( array( 'personas' => self::clean( $raw ) ) );
		update_option( self::OPTION_AUTO_DONE, time(), false );
		return isset( $saved['personas'] ) ? $saved['personas'] : array();
	}

	/**
	 * First run on a site with no personas: suggest some from the site and save
	 * them as `site`. Tried once — an owner who later clears the list meant it.
	 * The run that triggered this still runs as the baseline; the personas are
	 * there to pick from next time.
	 *
	 * @return array The Persona[] now saved.
	 */
	public static function maybe_autofill() {
		$current = self::all();
		if ( ! self::enabled() || ! empty( $current ) || get_option( self::OPTION_AUTO_DONE ) ) {
			return $current;
		}
		update_option( self::OPTION_AUTO_DONE, time(), false );
		$suggested = self::suggest();
		if ( is_wp_error( $suggested ) || empty( $suggested ) ) {
			return $current;
		}
		$rows = array();
		foreach ( $suggested as $label ) {
			$rows[] = array( 'label' => $label, 'source' => 'site' );
		}
		$saved = TWTAEO_Visibility_Store::save_settings( array( 'personas' => self::clean( $rows ) ) );
		return isset( $saved['personas'] ) ? $saved['personas'] : array();
	}

	/* ─────────────────────────── suggestions ───────────────────────── */

	/**
	 * Buyer personas suggested from the site's own pages. Falls back to the
	 * Local Pack business type's defaults when no AI provider answers.
	 *
	 * @return string[]|WP_Error Labels, not yet saved.
	 */
	public static function suggest() {
		$signals = self::site_signals();
		$error   = null;

		if ( strlen( $signals ) >= self::MIN_SIGNAL && class_exists( 'TWTAEO_AI_Client' ) ) {
			$labels = self::ask_ai( $signals );
			if ( ! is_wp_error( $labels ) && ! empty( $labels ) ) {
				return $labels;
			}
			$error = is_wp_error( $labels ) ? $labels : null;
		}

		$fallback = self::business_type_defaults();
		if ( ! empty( $fallback ) ) {
			return $fallback;
		}
		if ( strlen( $signals ) < self::MIN_SIGNAL ) {
			return new WP_Error( 'thin_site', __( 'There isn’t enough text on your main pages to suggest personas from. Add a few by hand — the people who research, choose or buy what you sell.', 'twt-aeo-ultimate' ) );
		}
		return $error ? $error : new WP_Error( 'no_suggestion', __( 'No personas could be suggested. Add a few by hand.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * One cheap-tier call: the site's words in, 3–5 buyer roles out.
	 *
	 * @param string $signals
	 * @return string[]|WP_Error
	 */
	private static function ask_ai( $signals ) {
		$prompt = implode(
			"\n",
			array(
				'Below is what a business says about itself on its own website: its name, tagline and main pages.',
				'Name the 3 to 5 kinds of buyer most likely to ask an AI assistant about what it offers: the people who research it, choose it or buy it.',
				'Each persona is a short role of 2 to 6 words, lowercase, with no personal or company names — for example "machine shop owner" or "manufacturing engineer".',
				'Reply with only this JSON: {"personas":["..."]}',
				'',
				$signals,
			)
		);
		try {
			$raw = TWTAEO_AI_Client::complete(
				self::provider(),
				$prompt,
				array(
					'max_tokens'  => 600,
					'temperature' => 0.3,
					'json'        => true,
					'timeout'     => 30,
					'system'      => 'You identify the customers of a business from its website. You reply with only JSON.',
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_failed', $e->getMessage() );
		}
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$decoded = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$list = isset( $decoded['personas'] ) && is_array( $decoded['personas'] ) ? $decoded['personas'] : array();
		$out  = array();
		foreach ( $list as $label ) {
			if ( ! is_string( $label ) ) {
				continue;
			}
			$label = self::clean_label( $label );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $label ) : strlen( $label );
			if ( $len >= 3 && $len <= 60 && ! in_array( $label, $out, true ) ) {
				$out[] = $label;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The site's cheap-tier provider when it has a key; otherwise the first
	 * provider that does. A site set up for AI Visibility with only a Gemini
	 * key would otherwise fail here, asking a provider it never configured.
	 * '' lets TWTAEO_AI_Client fall back to the WordPress AI Client.
	 */
	private static function provider() {
		$preferred = TWTAEO_AI_Client::enrich_provider();
		foreach ( array_unique( array( $preferred, 'claude', 'openai', 'gemini' ) ) as $p ) {
			if ( '' !== TWTAEO_Key_Resolver::get( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	/** Defaults for the Local Pack business type's group; [] for generic or unset. */
	public static function business_type_defaults() {
		$type = self::business_type();
		if ( '' === $type || ! class_exists( 'TWTAEO_Page_Local_Pack' ) || ! method_exists( 'TWTAEO_Page_Local_Pack', 'get_industry_group' ) ) {
			return array();
		}
		$group = (string) TWTAEO_Page_Local_Pack::get_industry_group( $type );
		return isset( self::GROUP_DEFAULTS[ $group ] ) ? self::GROUP_DEFAULTS[ $group ] : array();
	}

	/** The Local Pack schema.org type, or '' when unset or still the generic default. */
	private static function business_type() {
		if ( ! class_exists( 'TWTAEO_Local_Pack' ) ) {
			return '';
		}
		$settings = TWTAEO_Local_Pack::get_settings();
		$type     = isset( $settings['business_type'] ) ? (string) $settings['business_type'] : '';
		return in_array( $type, array( '', 'LocalBusiness', 'Organization' ), true ) ? '' : $type;
	}

	/* ─────────────────────────── site signals ───────────────────────── */

	/**
	 * What the site says about itself, as plain text: name, tagline, business
	 * type, then one line per main-menu page — its meta title and meta
	 * description, or its first paragraph when no SEO plugin set one. Pages
	 * come from the classic primary menu, else the block theme's navigation,
	 * else the most recent published pages. Public for tests.
	 *
	 * @return string
	 */
	public static function site_signals() {
		$lines = array();
		$name  = trim( (string) get_bloginfo( 'name' ) );
		$tag   = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $name ) {
			$lines[] = 'Business: ' . $name;
		}
		if ( '' !== $tag ) {
			$lines[] = 'Tagline: ' . $tag;
		}
		$type = self::business_type();
		if ( '' !== $type && class_exists( 'TWTAEO_Local_Pack' ) ) {
			$types   = TWTAEO_Local_Pack::get_subtypes();
			$lines[] = 'Business type: ' . ( isset( $types[ $type ] ) ? $types[ $type ] : $type );
		}

		$pages = array();
		foreach ( self::menu_targets() as $target ) {
			$line = self::describe_target( $target );
			if ( '' !== $line && ! in_array( $line, $pages, true ) ) {
				$pages[] = $line;
			}
			if ( count( $pages ) >= self::MAX_PAGES ) {
				break;
			}
		}
		if ( ! empty( $pages ) ) {
			$lines[] = '';
			$lines[] = 'Main pages:';
			foreach ( $pages as $p ) {
				$lines[] = '- ' . $p;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Menu entries as [ kind, id, label ] — kind post|term|label. The front page
	 * comes first when there is one: it is usually the best summary of all.
	 *
	 * @return array
	 */
	private static function menu_targets() {
		$targets = array();
		$front   = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$targets[] = array( 'post', $front, '' );
		}

		$items = self::classic_menu_items();
		if ( empty( $items ) ) {
			$items = self::navigation_block_items();
		}
		foreach ( $items as $item ) {
			$targets[] = $item;
		}

		if ( count( $targets ) <= 1 ) {
			$recent = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'numberposts'      => self::MAX_PAGES,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
				)
			);
			foreach ( (array) $recent as $p ) {
				$targets[] = array( 'post', (int) $p->ID, '' );
			}
		}
		return $targets;
	}

	/** Top-level items of the site's main classic menu. */
	private static function classic_menu_items() {
		if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}
		$locations = (array) get_nav_menu_locations();
		$menu_id   = 0;
		foreach ( array( 'primary', 'main', 'main-menu', 'primary-menu', 'header', 'header-menu', 'menu-1' ) as $loc ) {
			if ( ! empty( $locations[ $loc ] ) ) {
				$menu_id = (int) $locations[ $loc ];
				break;
			}
		}
		if ( ! $menu_id ) {
			foreach ( $locations as $id ) {
				if ( (int) $id > 0 ) {
					$menu_id = (int) $id;
					break;
				}
			}
		}
		if ( ! $menu_id ) {
			return array();
		}
		$out = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( ! is_object( $item ) || ! empty( $item->menu_item_parent ) ) {
				continue;
			}
			$label = isset( $item->title ) ? (string) $item->title : '';
			if ( 'post_type' === $item->type && (int) $item->object_id > 0 ) {
				$out[] = array( 'post', (int) $item->object_id, $label );
			} elseif ( 'taxonomy' === $item->type && (int) $item->object_id > 0 ) {
				$out[] = array( 'term', (int) $item->object_id, $label );
			} elseif ( '' !== $label ) {
				$out[] = array( 'label', 0, $label );
			}
		}
		return $out;
	}

	/** Links in the newest wp_navigation post (block themes keep menus there). */
	private static function navigation_block_items() {
		$nav = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		if ( empty( $nav ) || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$out  = array();
		$walk = function ( array $blocks ) use ( &$walk, &$out ) {
			foreach ( $blocks as $b ) {
				$name  = isset( $b['blockName'] ) ? (string) $b['blockName'] : '';
				$attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : array();
				if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
					$label = isset( $attrs['label'] ) ? wp_strip_all_tags( (string) $attrs['label'] ) : '';
					$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
					$kind  = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
					if ( $id > 0 && 'taxonomy' === $kind ) {
						$out[] = array( 'term', $id, $label );
					} elseif ( $id > 0 ) {
						$out[] = array( 'post', $id, $label );
					} elseif ( '' !== $label ) {
						$out[] = array( 'label', 0, $label );
					}
				}
				if ( ! empty( $b['innerBlocks'] ) && 'core/navigation-submenu' !== $name ) {
					$walk( $b['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( (string) $nav[0]->post_content ) );
		return $out;
	}

	/** "Title — description" for one menu target, or ''. */
	private static function describe_target( array $target ) {
		list( $kind, $id, $label ) = $target;
		$title = '';
		$desc  = '';
		if ( 'post' === $kind ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
				return '';
			}
			$title = self::meta_value( $id, self::TITLE_KEYS );
			if ( '' === $title ) {
				$title = '' !== $label ? $label : get_the_title( $post );
			}
			$desc = self::meta_value( $id, self::DESC_KEYS );
			if ( '' === $desc ) {
				$desc = self::first_paragraph( (string) $post->post_content );
			}
		} elseif ( 'term' === $kind ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}
			$title = '' !== $label ? $label : (string) $term->name;
			$desc  = self::cut( wp_strip_all_tags( (string) $term->description ) );
		} else {
			$title = $label;
		}
		$title = trim( wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );
		$desc  = trim( html_entity_decode( (string) $desc, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $title && '' === $desc ) {
			return '';
		}
		return '' === $desc ? $title : $title . ' — ' . $desc;
	}

	/** First non-templated value among these meta keys. */
	private static function meta_value( $post_id, array $keys ) {
		foreach ( $keys as $k ) {
			$v = trim( (string) get_post_meta( $post_id, $k, true ) );
			// Yoast/SEOPress %%var%%, Rank Math %var%, AIOSEO #var — a template, not words.
			if ( '' !== $v && ! preg_match( '/%%|%[a-z_]+%|\{[a-z_]+\}|#[a-z_]+/i', $v ) ) {
				return self::cut( wp_strip_all_tags( $v ) );
			}
		}
		return '';
	}

	/** The first paragraph with some substance, else the opening words. */
	private static function first_paragraph( $content ) {
		$content = strip_shortcodes( $content );
		if ( preg_match_all( '#<p[^>]*>(.*?)</p>#is', $content, $m ) ) {
			foreach ( $m[1] as $p ) {
				$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $p ) ) );
				$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
				if ( $len >= 40 ) {
					return self::cut( $text );
				}
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) );
		return '' === $text ? '' : self::cut( wp_trim_words( $text, 60, '…' ) );
	}

	private static function cut( $text ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::MAX_DESC ) {
			return rtrim( mb_substr( $text, 0, self::MAX_DESC - 1 ) ) . '…';
		}
		return strlen( $text ) > self::MAX_DESC ? rtrim( substr( $text, 0, self::MAX_DESC - 1 ) ) . '…' : $text;
	}

	/* ─────────────────────────── modules ───────────────────────── */

	/** Whether a plugin module is switched on. */
	public static function module_active( $slug ) {
		if ( ! class_exists( 'TWTAEO_Module_Loader' ) ) {
			return false;
		}
		return in_array( (string) $slug, (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true );
	}
}
