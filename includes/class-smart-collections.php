<?php
/**
 * Smart Categories & Intent Hubs — the data model.
 *
 * A flat catalogue answers "what do you sell". It does not answer "what should a
 * beginning photographer buy", which is the question a shopper three steps from
 * the checkout actually types. That shopper is not looking for a camera; they are
 * looking for a camera *and* a lens *and* a tripod, chosen by someone who knows
 * which ones go together.
 *
 * Merchants already express that grouping — as a category called "Starter Kit",
 * "Pro Setup", "Under $500". WooCommerce treats those exactly like every other
 * category, and so does every SEO plugin: a term archive, a title, a breadcrumb.
 * This module reads the merchant's own intent-shaped categories and republishes
 * them as **Collections** — a CollectionPage with an ItemList, a written summary
 * of who the group is for, and an entry in llms.txt.
 *
 * ── Two deliberate limits ────────────────────────────────────────────────────
 *
 * **This module creates no pages and no taxonomy terms.** It maps to categories
 * the merchant already built, or renders through a shortcode on a page they
 * already wrote. Auto-generating collection landing pages from search queries is
 * the doorway-page pattern Google's spam policies name directly, and the same
 * reasoning that put four hard constraints on TWTAEO_Local_Pages applies here with
 * a sharper edge — a location page describes somewhere real, a generated
 * "Best Cameras Under $500" page describes nothing at all until a human agrees
 * it does. Discovery therefore *suggests*; the merchant maps.
 *
 * **Every fact in a summary is read from the catalogue.** Product counts, price
 * floors and ceilings, stock — all real, all recomputed. Nothing is estimated,
 * per the score rules in CLAUDE.md. The AI writer is handed those facts and asked
 * only to phrase them.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Smart_Collections {

	const OPTION_KEY      = 'twtaeo_smart_collections';
	const OPTION_SETTINGS = 'twtaeo_smart_collections_settings';
	const NONCE_SAVE      = 'twtaeo_collection_save';
	const NONCE_DELETE    = 'twtaeo_collection_delete';
	const NONCE_SUMMARY   = 'twtaeo_collection_summary';

	/** Hard ceiling on products listed in one collection's ItemList. */
	const MAX_ITEMS = 60;

	/** Discovery scan cache lifetime. */
	const CANDIDATE_TTL = HOUR_IN_SECONDS * 6;

	// ── Archetypes ────────────────────────────────────────────────────────────

	/**
	 * The intent archetypes a collection can express.
	 *
	 * `keywords` is what makes discovery work: they are matched against the
	 * merchant's existing category names and slugs to find the categories that are
	 * *already* intent-shaped. A category called "Cameras" is a catalogue division;
	 * a category called "Starter Kits" is an answer to a question, and only the
	 * second one belongs here.
	 *
	 * `intent` ties each archetype to the funnel stage TWTAEO_Buyer_Intent already
	 * classifies queries into, so discovery can rank a suggestion by whether the
	 * store actually ranks for that kind of query.
	 *
	 * @return array<string,array>
	 */
	public static function archetypes() {
		return array(

			// ── Skill & experience tiers ──────────────────────────────────────
			'skill-starter'   => array(
				'group'    => __( 'Skill & Experience', 'twt-aeo-ultimate' ),
				'label'    => __( 'Starter Kit / Beginner', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone buying into this category for the first time', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'starter', 'starter kit', 'beginner', 'beginners', 'entry level', 'entry-level', 'novice', 'getting started', 'first time', 'basics', 'essentials', '101' ),
			),
			'skill-mid'       => array(
				'group'    => __( 'Skill & Experience', 'twt-aeo-ultimate' ),
				'label'    => __( 'Mid-Level / Enthusiast', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone upgrading from their first setup', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'enthusiast', 'intermediate', 'mid level', 'mid-level', 'mid tier', 'step up', 'prosumer' ),
			),
			'skill-pro'       => array(
				'group'    => __( 'Skill & Experience', 'twt-aeo-ultimate' ),
				'label'    => __( 'Pro / Commercial / Industrial', 'twt-aeo-ultimate' ),
				'audience' => __( 'a professional buying tools they earn a living with', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'pro', 'professional', 'commercial', 'industrial', 'heavy duty', 'heavy-duty', 'trade', 'contractor', 'workshop grade' ),
			),

			// ── Budget & value tiers ──────────────────────────────────────────
			'budget-entry'    => array(
				'group'    => __( 'Budget & Value', 'twt-aeo-ultimate' ),
				'label'    => __( 'Budget / Under a threshold', 'twt-aeo-ultimate' ),
				'audience' => __( 'a shopper working to a fixed budget', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'budget', 'cheap', 'affordable', 'under', 'value range', 'entry price' ),
			),
			'budget-value'    => array(
				'group'    => __( 'Budget & Value', 'twt-aeo-ultimate' ),
				'label'    => __( 'Best Value / Price-to-Performance', 'twt-aeo-ultimate' ),
				'audience' => __( 'a shopper weighing what they get for the money', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'best value', 'value', 'price to performance', 'bang for', 'most popular', 'bestseller', 'best seller' ),
			),
			'budget-premium'  => array(
				'group'    => __( 'Budget & Value', 'twt-aeo-ultimate' ),
				'label'    => __( 'Premium / Executive / Luxury', 'twt-aeo-ultimate' ),
				'audience' => __( 'a shopper who has decided price is not the constraint', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'premium', 'luxury', 'executive', 'flagship', 'top of the range', 'ultimate', 'elite' ),
			),

			// ── Use case & environment ────────────────────────────────────────
			'use-indoor'      => array(
				'group'    => __( 'Use Case & Environment', 'twt-aeo-ultimate' ),
				'label'    => __( 'Indoor / Home', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone buying for use at home', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'indoor', 'home', 'household', 'apartment', 'studio' ),
			),
			'use-outdoor'     => array(
				'group'    => __( 'Use Case & Environment', 'twt-aeo-ultimate' ),
				'label'    => __( 'Outdoor / Field / Jobsite', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone working outdoors or on site', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'outdoor', 'field', 'jobsite', 'job site', 'site work', 'garden', 'yard' ),
			),
			'use-portable'    => array(
				'group'    => __( 'Use Case & Environment', 'twt-aeo-ultimate' ),
				'label'    => __( 'Compact / Travel / Portable', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone who has to carry this with them', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'compact', 'travel', 'portable', 'lightweight', 'packable', 'on the go' ),
			),
			'use-seasonal'    => array(
				'group'    => __( 'Use Case & Environment', 'twt-aeo-ultimate' ),
				'label'    => __( 'Seasonal / All-Weather', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone buying for a particular season or climate', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'winter', 'summer', 'spring', 'autumn', 'fall', 'seasonal', 'all weather', 'all-weather', 'cold weather', 'wet weather' ),
			),

			// ── Persona & profession ──────────────────────────────────────────
			'persona-student' => array(
				'group'    => __( 'Persona & Profession', 'twt-aeo-ultimate' ),
				'label'    => __( 'Apprentice / Student', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone learning the trade', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'apprentice', 'student', 'school', 'training', 'first year' ),
			),
			'persona-diy'     => array(
				'group'    => __( 'Persona & Profession', 'twt-aeo-ultimate' ),
				'label'    => __( 'DIY / Weekend Warrior', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone doing this themselves at weekends', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'diy', 'weekend', 'hobby', 'hobbyist', 'homeowner', 'do it yourself' ),
			),
			'persona-pro'     => array(
				'group'    => __( 'Persona & Profession', 'twt-aeo-ultimate' ),
				'label'    => __( 'Professional / Tradesperson', 'twt-aeo-ultimate' ),
				'audience' => __( 'a working tradesperson', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'tradesperson', 'tradesman', 'electrician', 'plumber', 'carpenter', 'installer', 'technician' ),
			),
			'persona-enterprise' => array(
				'group'    => __( 'Persona & Profession', 'twt-aeo-ultimate' ),
				'label'    => __( 'Commercial / Enterprise', 'twt-aeo-ultimate' ),
				'audience' => __( 'a business buying at scale', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'enterprise', 'business', 'fleet', 'bulk', 'wholesale', 'volume' ),
			),

			// ── Problem / solution & workflow ─────────────────────────────────
			'problem-emergency' => array(
				'group'    => __( 'Problem & Workflow', 'twt-aeo-ultimate' ),
				'label'    => __( 'Emergency / Quick Fix', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone with a problem happening right now', 'twt-aeo-ultimate' ),
				'intent'   => 'transactional',
				'keywords' => array( 'emergency', 'quick fix', 'urgent', 'repair', 'backup', 'replacement part' ),
			),
			'problem-complete'  => array(
				'group'    => __( 'Problem & Workflow', 'twt-aeo-ultimate' ),
				'label'    => __( 'Complete Setup / All-in-One', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone who wants everything needed in one order', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'complete', 'all in one', 'all-in-one', 'full setup', 'everything you need', 'bundle', 'kit', 'package' ),
			),
			'problem-maintenance' => array(
				'group'    => __( 'Problem & Workflow', 'twt-aeo-ultimate' ),
				'label'    => __( 'Maintenance & Care', 'twt-aeo-ultimate' ),
				'audience' => __( 'an existing owner keeping their kit working', 'twt-aeo-ultimate' ),
				'intent'   => 'informational',
				'keywords' => array( 'maintenance', 'care', 'cleaning', 'servicing', 'consumables', 'spares', 'upkeep' ),
			),
			'problem-upgrade'   => array(
				'group'    => __( 'Problem & Workflow', 'twt-aeo-ultimate' ),
				'label'    => __( 'Upgrade / Expansion', 'twt-aeo-ultimate' ),
				'audience' => __( 'an existing owner adding to what they have', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'upgrade', 'expansion', 'add on', 'add-on', 'extend', 'next step' ),
			),

			// ── Ecosystem & compatibility ─────────────────────────────────────
			'eco-starter-pack'  => array(
				'group'    => __( 'Ecosystem & Compatibility', 'twt-aeo-ultimate' ),
				'label'    => __( 'System Starter Pack', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone buying into a platform or battery system', 'twt-aeo-ultimate' ),
				'intent'   => 'commercial',
				'keywords' => array( 'system', 'platform', 'ecosystem', 'starter pack', 'battery system' ),
			),
			'eco-accessories'   => array(
				'group'    => __( 'Ecosystem & Compatibility', 'twt-aeo-ultimate' ),
				'label'    => __( 'Cross-Compatible Accessories', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone matching accessories to kit they already own', 'twt-aeo-ultimate' ),
				'intent'   => 'transactional',
				'keywords' => array( 'accessories', 'compatible', 'fits', 'attachments', 'adapters' ),
			),
			'eco-oem'           => array(
				'group'    => __( 'Ecosystem & Compatibility', 'twt-aeo-ultimate' ),
				'label'    => __( 'OEM Replacement & Upgrades', 'twt-aeo-ultimate' ),
				'audience' => __( 'someone replacing a part with the correct equivalent', 'twt-aeo-ultimate' ),
				'intent'   => 'transactional',
				'keywords' => array( 'oem', 'genuine', 'replacement', 'original equipment', 'factory' ),
			),

			// ── Merchant-defined ──────────────────────────────────────────────
			'other'             => array(
				'group'    => __( 'Custom', 'twt-aeo-ultimate' ),
				'label'    => __( 'Other (your own intent phrases)', 'twt-aeo-ultimate' ),
				'audience' => '',
				'intent'   => 'commercial',
				'keywords' => array(),
			),
		);
	}

	/** Archetypes grouped by their `group` label, for an optgroup dropdown. */
	public static function archetypes_grouped() {
		$grouped = array();
		foreach ( self::archetypes() as $key => $a ) {
			$grouped[ $a['group'] ][ $key ] = $a['label'];
		}
		return $grouped;
	}

	/**
	 * Keywords for an archetype, including the merchant's own phrases when the
	 * archetype is `other` (or when they have added extras to a preset).
	 *
	 * @param string $key    Archetype key.
	 * @param string $custom Comma-separated merchant phrases.
	 * @return string[]
	 */
	public static function keywords_for( $key, $custom = '' ) {
		$archetypes = self::archetypes();
		$keywords   = isset( $archetypes[ $key ]['keywords'] ) ? $archetypes[ $key ]['keywords'] : array();

		foreach ( explode( ',', (string) $custom ) as $phrase ) {
			$phrase = trim( strtolower( $phrase ) );
			if ( '' !== $phrase ) {
				$keywords[] = $phrase;
			}
		}

		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Whether a keyword appears in a piece of text as a whole word.
	 *
	 * Word boundaries are not a nicety here. A plain substring test matches "pro"
	 * inside "Promotions", "Products" and "Approved" — on the 191-product test
	 * catalogue that turned 3 genuinely intent-shaped categories into 29
	 * suggestions, which is a list a merchant learns to ignore. A suggestion
	 * engine that cries wolf is worse than no suggestion engine.
	 *
	 * @param string $haystack Lower-cased text to search.
	 * @param string $keyword  Lower-cased keyword or phrase.
	 * @return bool
	 */
	private static function keyword_hit( $haystack, $keyword ) {
		if ( '' === $keyword ) {
			return false;
		}
		return 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/u', $haystack );
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function settings_defaults() {
		return array(
			// Master switch. The module can be active while this is off — activating
			// a module must never be the same act as changing what a site publishes.
			'publish'          => 0,

			// Take over the collection schema on a mapped category, suppressing
			// whatever the merchant's SEO plugin emits there. Off by default: two
			// plugins writing one node is worse than either alone, and which one
			// wins is the merchant's decision, not ours.
			'takeover'         => 0,

			// Append collections to llms.txt.
			'llms_txt'         => 1,

			// Exclude out-of-stock products from a collection's ItemList.
			'exclude_oos'      => 0,

			// Allow the AI writer to phrase summaries. Costs money, so: off.
			'ai_summaries'     => 0,
			'ai_daily_cap'     => 20,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::settings_defaults() );
	}

	public static function save_settings( array $raw ) {
		$d     = self::settings_defaults();
		$clean = array(
			'publish'      => empty( $raw['publish'] ) ? 0 : 1,
			'takeover'     => empty( $raw['takeover'] ) ? 0 : 1,
			'llms_txt'     => empty( $raw['llms_txt'] ) ? 0 : 1,
			'exclude_oos'  => empty( $raw['exclude_oos'] ) ? 0 : 1,
			'ai_summaries' => empty( $raw['ai_summaries'] ) ? 0 : 1,
			'ai_daily_cap' => isset( $raw['ai_daily_cap'] ) ? max( 0, min( 200, (int) $raw['ai_daily_cap'] ) ) : $d['ai_daily_cap'],
		);
		update_option( self::OPTION_SETTINGS, $clean );
		self::flush_caches();
		return $clean;
	}

	/**
	 * Whether anything at all should be published.
	 *
	 * Two gates checked here: WooCommerce is present, and the merchant switched
	 * publishing on. The module gate is enforced one level up — the hooks are only
	 * registered when `smart-collections` is active (`class-plugin.php`) — so this
	 * method is never reached on the front end with the module off. Anything asking
	 * this question from the **admin** side must check the module itself, because
	 * there the hooks are irrelevant; use module_active() below.
	 *
	 * The module being active is not consent to change the site's output.
	 */
	public static function is_publishing() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		$s = self::settings();
		return ! empty( $s['publish'] );
	}

	/**
	 * Whether the module is switched on.
	 *
	 * Reads `twtaeo_active_modules` directly rather than going through
	 * TWTAEO_Module_Loader::is_active(), which answers from a list cached at
	 * construction and is therefore stale for the whole request in which a module
	 * is toggled — the same reason TWTAEO_Page_Citations::module_active() does.
	 * Do not "tidy" this back to the loader.
	 */
	public static function module_active() {
		$active = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		return is_array( $active ) && in_array( 'smart-collections', $active, true );
	}

	/**
	 * The real publishing state of one collection, and what to do about it.
	 *
	 * A collection's own `active` flag is only one of several gates, so reading it
	 * alone and calling the result "Live" was a lie the admin table told: with
	 * Settings → publish off, every row still showed a green tick while the site
	 * published nothing. This is the single place that question gets answered, so
	 * the table, the edit form and anything added later cannot drift apart.
	 *
	 * Gates are tested outermost first, because that is the order the merchant has
	 * to fix them in — telling someone to switch a collection on while the module
	 * is off sends them to the wrong screen.
	 *
	 * @param array      $collection
	 * @param array|null $facts Pre-resolved resolve_items() output, to avoid
	 *                          resolving twice when the caller already has it.
	 * @return array{live:bool,state:string,label:string,detail:string,fix_url:string,fix_label:string,caveat:string}
	 */
	public static function publish_status( array $collection, $facts = null ) {
		$status = static function ( $state, $live, $label, $detail, $fix_url = '', $fix_label = '' ) {
			return array(
				'live'      => (bool) $live,
				'state'     => $state,
				'label'     => $label,
				'detail'    => $detail,
				'fix_url'   => $fix_url,
				'fix_label' => $fix_label,
				'caveat'    => '',
			);
		};

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $status(
				'no_woo',
				false,
				__( 'No', 'twt-aeo-ultimate' ),
				__( 'WooCommerce is not active.', 'twt-aeo-ultimate' )
			);
		}

		if ( ! self::module_active() ) {
			return $status(
				'module_off',
				false,
				__( 'No', 'twt-aeo-ultimate' ),
				__( 'The Smart Collections module is switched off.', 'twt-aeo-ultimate' ),
				admin_url( 'admin.php?page=twt-aeo-modules' ),
				__( 'Turn the module on', 'twt-aeo-ultimate' )
			);
		}

		$settings = self::settings();
		if ( empty( $settings['publish'] ) ) {
			return $status(
				'publish_off',
				false,
				__( 'No', 'twt-aeo-ultimate' ),
				__( 'Publishing is off for every collection, so nothing is written to your pages.', 'twt-aeo-ultimate' ),
				admin_url( 'admin.php?page=twt-aeo-collections&tab=settings' ),
				__( 'Switch publishing on', 'twt-aeo-ultimate' )
			);
		}

		if ( empty( $collection['active'] ) ) {
			return $status(
				'switched_off',
				false,
				__( 'No', 'twt-aeo-ultimate' ),
				__( 'Publishing is on, but this collection is switched off.', 'twt-aeo-ultimate' ),
				admin_url( 'admin.php?page=twt-aeo-collections&edit=' . rawurlencode( $collection['id'] ) ),
				__( 'Switch it on', 'twt-aeo-ultimate' )
			);
		}

		if ( 'category' === $collection['source'] ) {
			$term = $collection['term_id'] ? get_term( (int) $collection['term_id'], 'product_cat' ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				return $status(
					'no_term',
					false,
					__( 'No', 'twt-aeo-ultimate' ),
					__( 'The category this collection publishes on no longer exists.', 'twt-aeo-ultimate' ),
					admin_url( 'admin.php?page=twt-aeo-collections&edit=' . rawurlencode( $collection['id'] ) ),
					__( 'Map it to a category', 'twt-aeo-ultimate' )
				);
			}
		}

		$facts = is_array( $facts ) ? $facts : self::resolve_items( $collection );
		if ( 0 === (int) $facts['count'] ) {
			// build_item_list() returns null on an empty list rather than publishing
			// an ItemList with no items, so this genuinely emits nothing.
			return $status(
				'empty',
				false,
				__( 'No', 'twt-aeo-ultimate' ),
				__( 'This collection resolves to no products, and an empty list is never published.', 'twt-aeo-ultimate' ),
				admin_url( 'admin.php?page=twt-aeo-collections&edit=' . rawurlencode( $collection['id'] ) ),
				__( 'Check what it resolves to', 'twt-aeo-ultimate' )
			);
		}

		if ( 'shortcode' === $collection['source'] && ! self::shortcode_is_placed( $collection['id'] ) ) {
			// The writer only publishes on a page whose stored content carries the
			// shortcode, so an unplaced collection publishes nowhere.
			return $status(
				'unplaced',
				false,
				__( 'Not yet', 'twt-aeo-ultimate' ),
				__( 'This collection is switched on, but its shortcode is not on any page or post yet, so there is nowhere to publish it.', 'twt-aeo-ultimate' )
			);
		}

		$out = $status(
			'live',
			true,
			__( 'Live', 'twt-aeo-ultimate' ),
			__( 'Publishing CollectionPage and ItemList schema.', 'twt-aeo-ultimate' )
		);

		// Not a gate the merchant got wrong — but on a store nobody can see, "Live"
		// needs saying with the qualification attached. Store managers are exempt
		// from the suppression, so the merchant's own view is not evidence.
		if ( function_exists( 'get_option' ) && 'yes' === get_option( 'woocommerce_coming_soon' ) ) {
			$out['caveat'] = __( 'Your store is in coming-soon mode, so visitors and crawlers see a holding screen and this schema is withheld from them until you launch.', 'twt-aeo-ultimate' );
		}

		return $out;
	}

	/**
	 * Whether a shortcode collection is actually placed on a post or page.
	 *
	 * One query for every shortcode collection on the screen rather than one per
	 * row: the ids are parsed out of the matched content and cached for the
	 * request. Admin-only — nothing on the front end asks this.
	 *
	 * @param string $id Collection id.
	 * @return bool
	 */
	public static function shortcode_is_placed( $id ) {
		static $placed = null;

		if ( null === $placed ) {
			global $wpdb;
			$placed = array();

			$like = '%' . $wpdb->esc_like( '[' . TWTAEO_Collection_Schema_Writer::SHORTCODE ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- no core API for a reverse shortcode lookup; result is cached in $placed for the request.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_content FROM {$wpdb->posts}
					 WHERE post_content LIKE %s
					   AND post_status NOT IN ( 'trash', 'auto-draft' )",
					$like
				)
			);

			$pattern = get_shortcode_regex( array( TWTAEO_Collection_Schema_Writer::SHORTCODE ) );
			foreach ( (array) $rows as $content ) {
				if ( ! preg_match_all( '/' . $pattern . '/', (string) $content, $matches ) ) {
					continue;
				}
				foreach ( (array) $matches[3] as $attr_string ) {
					$attrs = shortcode_parse_atts( $attr_string );
					if ( is_array( $attrs ) && ! empty( $attrs['id'] ) ) {
						$placed[ sanitize_key( $attrs['id'] ) ] = true;
					}
				}
			}
		}

		return isset( $placed[ sanitize_key( $id ) ] );
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	/** @return array<string,array> All collections, keyed by id. */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Only the collections the merchant has switched on. */
	public static function get_published() {
		return array_filter(
			self::get_all(),
			static function ( $c ) {
				return ! empty( $c['active'] );
			}
		);
	}

	public static function get( $id ) {
		$all = self::get_all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** The collection mapped to a product category term, if any. */
	public static function get_for_term( $term_id ) {
		$term_id = (int) $term_id;
		foreach ( self::get_published() as $c ) {
			if ( 'category' === $c['source'] && (int) $c['term_id'] === $term_id ) {
				return $c;
			}
		}
		return null;
	}

	public static function record_defaults() {
		return array(
			'id'           => '',
			'title'        => '',
			'archetype'    => 'skill-starter',
			'custom_terms' => '',
			'source'       => 'category',   // category | shortcode
			'term_id'      => 0,
			'target_query' => '',
			'summary'      => '',
			'summary_src'  => '',           // manual | ai | computed
			'products'     => array(),      // pinned ids; empty means "derive from term"
			'active'       => 0,
			'created'      => 0,
			'modified'     => 0,

			// Set only when this plugin created the category itself. A product_cat
			// term has no draft state — it is live the moment it exists — so the one
			// safety net we can offer is an exact undo, and an exact undo needs to
			// know which term we made and which products we put in it. Without
			// `assigned_products` an undo would strip categories the merchant added
			// afterwards.
			'created_term' => 0,
			'assigned_products' => array(),

			// Why this collection exists. For a generated one: the real query, with
			// its real Search Console figures and the date they were read. Recorded
			// so the answer to "why is this page here" is never a guess.
			'evidence'     => array(),
		);
	}

	/**
	 * Sanitize and persist one collection. Returns the stored record.
	 *
	 * @param array $raw Raw (unslashed) input.
	 * @return array|WP_Error
	 */
	public static function save( array $raw ) {
		$all        = self::get_all();
		$archetypes = self::archetypes();

		$id = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$id = 'col_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		}

		$existing = isset( $all[ $id ] ) ? $all[ $id ] : self::record_defaults();

		$title = sanitize_text_field( $raw['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'twtaeo_no_title', __( 'A collection needs a title — it becomes the name of the CollectionPage.', 'twt-aeo-ultimate' ) );
		}

		$archetype = sanitize_key( $raw['archetype'] ?? '' );
		if ( ! isset( $archetypes[ $archetype ] ) ) {
			$archetype = 'skill-starter';
		}

		$source = ( 'shortcode' === ( $raw['source'] ?? '' ) ) ? 'shortcode' : 'category';

		$term_id = (int) ( $raw['term_id'] ?? 0 );
		if ( 'category' === $source ) {
			$term = $term_id ? get_term( $term_id, 'product_cat' ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error(
					'twtaeo_no_term',
					__( 'Pick an existing product category to map this collection to. This module does not create categories.', 'twt-aeo-ultimate' )
				);
			}
			// One collection per term: two CollectionPage nodes on one archive is
			// the double-emit failure this module exists to avoid.
			foreach ( $all as $other_id => $other ) {
				if ( $other_id !== $id && 'category' === $other['source'] && (int) $other['term_id'] === $term_id ) {
					return new WP_Error(
						'twtaeo_term_taken',
						sprintf(
							/* translators: %s: existing collection title. */
							__( 'That category is already mapped to the collection "%s". Edit that one instead — a category can only publish one collection.', 'twt-aeo-ultimate' ),
							$other['title']
						)
					);
				}
			}
		} else {
			$term_id = 0;
		}

		$products = array();
		foreach ( (array) ( $raw['products'] ?? array() ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				$products[] = $pid;
			}
		}
		$products = array_slice( array_values( array_unique( $products ) ), 0, self::MAX_ITEMS );

		$summary = sanitize_textarea_field( $raw['summary'] ?? '' );
		// A merchant edit always wins over whatever wrote the previous summary —
		// same rule as the location-page generator: never overwrite human work.
		$summary_src = $existing['summary_src'];
		if ( $summary !== $existing['summary'] ) {
			$summary_src = ( '' === $summary ) ? '' : 'manual';
		}

		$record = array(
			'id'           => $id,
			'title'        => $title,
			'archetype'    => $archetype,
			'custom_terms' => sanitize_text_field( $raw['custom_terms'] ?? '' ),
			'source'       => $source,
			'term_id'      => $term_id,
			'target_query' => sanitize_text_field( $raw['target_query'] ?? '' ),
			'summary'      => $summary,
			'summary_src'  => $summary_src,
			'products'     => $products,
			'active'       => empty( $raw['active'] ) ? 0 : 1,
			'created'      => $existing['created'] ? (int) $existing['created'] : time(),
			'modified'     => time(),

			// Provenance is carried forward, never re-derived from the form — a
			// merchant editing a title must not be able to erase the record of what
			// this plugin created on their behalf, or the undo stops being exact.
			'created_term'      => ! empty( $existing['created_term'] ) ? 1 : 0,
			'assigned_products' => isset( $existing['assigned_products'] ) ? (array) $existing['assigned_products'] : array(),
			'evidence'          => isset( $existing['evidence'] ) ? (array) $existing['evidence'] : array(),
		);

		$all[ $id ] = $record;
		update_option( self::OPTION_KEY, $all );
		self::flush_caches();

		return $record;
	}

	public static function delete( $id ) {
		$all = self::get_all();
		$id  = sanitize_key( $id );
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all );
		self::flush_caches();
		return true;
	}

	/** Store a generated summary without touching anything else. */
	public static function set_summary( $id, $summary, $source ) {
		$all = self::get_all();
		$id  = sanitize_key( $id );
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$all[ $id ]['summary']     = sanitize_textarea_field( $summary );
		$all[ $id ]['summary_src'] = in_array( $source, array( 'ai', 'computed', 'manual' ), true ) ? $source : 'computed';
		$all[ $id ]['modified']    = time();
		update_option( self::OPTION_KEY, $all );
		self::flush_caches();
		return true;
	}

	private static function flush_caches() {
		delete_transient( 'twtaeo_collection_candidates' );
		foreach ( array_keys( self::get_all() ) as $id ) {
			delete_transient( 'twtaeo_collection_items_' . $id );
		}
	}

	// ── Resolving products ────────────────────────────────────────────────────

	/**
	 * The products in a collection, with the facts a summary and an ItemList need.
	 *
	 * Pinned products win; otherwise the mapped category's own products are used,
	 * in the merchant's catalogue order. Availability comes from
	 * TWTAEO_Sitemap::resolved_stock_status() and **never** from
	 * $product->get_stock_status() — a variable product's parent status reads
	 * `outofstock` while every variation is in stock on 77% of the test catalogue,
	 * so trusting the parent here would empty most collections the moment
	 * "exclude out of stock" was switched on.
	 *
	 * @param array $collection
	 * @param bool  $force Skip the cache.
	 * @return array{items:array,count:int,min:float,max:float,currency:string,in_stock:int}
	 */
	public static function resolve_items( array $collection, $force = false ) {
		$cache_key = 'twtaeo_collection_items_' . $collection['id'];
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$empty = array(
			'items'    => array(),
			'count'    => 0,
			'min'      => 0.0,
			'max'      => 0.0,
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'in_stock' => 0,
		);

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $empty;
		}

		$ids = $collection['products'];
		if ( empty( $ids ) && 'category' === $collection['source'] && $collection['term_id'] ) {
			$ids = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'posts_per_page'   => self::MAX_ITEMS,
					'fields'           => 'ids',
					'orderby'          => 'menu_order title',
					'order'            => 'ASC',
					'suppress_filters' => false,
					'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => (int) $collection['term_id'],
						),
					),
				)
			);
		}

		if ( empty( $ids ) ) {
			set_transient( $cache_key, $empty, HOUR_IN_SECONDS );
			return $empty;
		}

		$settings = self::settings();
		$items    = array();
		$prices   = array();
		$in_stock = 0;

		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || 'publish' !== get_post_status( $pid ) ) {
				continue;
			}
			// Hidden products are hidden on purpose. Listing one in a collection
			// would publish a URL the merchant deliberately kept out of the catalogue.
			if ( 'visible' !== $product->get_catalog_visibility() && 'catalog' !== $product->get_catalog_visibility() ) {
				continue;
			}

			$stock = class_exists( 'TWTAEO_Sitemap' )
				? TWTAEO_Sitemap::resolved_stock_status( $product )
				: $product->get_stock_status();

			if ( ! empty( $settings['exclude_oos'] ) && 'outofstock' === $stock ) {
				continue;
			}
			if ( 'instock' === $stock ) {
				$in_stock++;
			}

			// wc_get_price_to_display(), never get_price() — the figure quoted in a
			// collection has to match the storefront's tax display, same rule the
			// Product schema and the Merchant Center feed follow.
			$price = (float) wc_get_price_to_display( $product );
			if ( $price > 0 ) {
				$prices[] = $price;
			}

			$items[] = array(
				'id'    => (int) $pid,
				'name'  => html_entity_decode( $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => get_permalink( $pid ),
				'price' => $price,
				'stock' => $stock,
			);

			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		$resolved = array(
			'items'    => $items,
			'count'    => count( $items ),
			'min'      => $prices ? min( $prices ) : 0.0,
			'max'      => $prices ? max( $prices ) : 0.0,
			'currency' => $empty['currency'],
			'in_stock' => $in_stock,
		);

		set_transient( $cache_key, $resolved, HOUR_IN_SECONDS );
		return $resolved;
	}

	// ── Summaries ─────────────────────────────────────────────────────────────

	/**
	 * A summary built only from what the catalogue actually says.
	 *
	 * No API call, no cost, no invention. This is what a collection publishes
	 * unless the merchant writes their own or pays for the AI to phrase it, and it
	 * is deliberately the default: a sentence of real numbers is more use to an
	 * answer engine than a fluent sentence of guesses.
	 *
	 * @param array $collection
	 * @return string
	 */
	public static function computed_summary( array $collection ) {
		$facts      = self::resolve_items( $collection );
		$archetypes = self::archetypes();
		$archetype  = isset( $archetypes[ $collection['archetype'] ] ) ? $archetypes[ $collection['archetype'] ] : null;

		if ( 0 === $facts['count'] ) {
			return '';
		}

		$parts = array();

		$where = '';
		if ( 'category' === $collection['source'] && $collection['term_id'] ) {
			$term = get_term( (int) $collection['term_id'], 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$where = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		if ( $where ) {
			$parts[] = sprintf(
				/* translators: 1: collection title, 2: product count, 3: category name. */
				_n( '%1$s groups %2$d product from the %3$s category.', '%1$s groups %2$d products from the %3$s category.', $facts['count'], 'twt-aeo-ultimate' ),
				$collection['title'],
				$facts['count'],
				$where
			);
		} else {
			$parts[] = sprintf(
				/* translators: 1: collection title, 2: product count. */
				_n( '%1$s groups %2$d product.', '%1$s groups %2$d products.', $facts['count'], 'twt-aeo-ultimate' ),
				$collection['title'],
				$facts['count']
			);
		}

		if ( $archetype && '' !== $archetype['audience'] && 'other' !== $collection['archetype'] ) {
			$parts[] = sprintf(
				/* translators: %s: who the collection is for, e.g. "someone buying into this category for the first time". */
				__( 'It is put together for %s.', 'twt-aeo-ultimate' ),
				$archetype['audience']
			);
		}

		if ( $facts['max'] > 0 && function_exists( 'wc_price' ) ) {
			$low  = html_entity_decode( wp_strip_all_tags( wc_price( $facts['min'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$high = html_entity_decode( wp_strip_all_tags( wc_price( $facts['max'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $facts['min'] === $facts['max'] ) {
				/* translators: %s: a price. */
				$parts[] = sprintf( __( 'Items are priced at %s.', 'twt-aeo-ultimate' ), $low );
			} else {
				/* translators: 1: lowest price, 2: highest price. */
				$parts[] = sprintf( __( 'Prices run from %1$s to %2$s.', 'twt-aeo-ultimate' ), $low, $high );
			}
		}

		if ( $facts['in_stock'] === $facts['count'] ) {
			$parts[] = __( 'Everything in it is currently in stock.', 'twt-aeo-ultimate' );
		} elseif ( $facts['in_stock'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: in-stock count, 2: total count. */
				__( '%1$d of the %2$d are currently in stock.', 'twt-aeo-ultimate' ),
				$facts['in_stock'],
				$facts['count']
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Ask the AI to phrase the same facts more naturally.
	 *
	 * The facts are computed first and handed over in the prompt; the model is
	 * asked to phrase, not to research. It is given no licence to add a claim,
	 * because a collection summary that invents a compatibility or a use case is
	 * a product claim the merchant did not make.
	 *
	 * Never runs in a bulk context, and never without the merchant switching AI
	 * summaries on — both cost rules from CLAUDE.md.
	 *
	 * @param array $collection
	 * @return string|WP_Error
	 */
	public static function ai_summary( array $collection ) {
		$settings = self::settings();
		if ( empty( $settings['ai_summaries'] ) ) {
			return new WP_Error( 'twtaeo_ai_off', __( 'AI summaries are switched off. Turn them on in this module\'s settings first.', 'twt-aeo-ultimate' ) );
		}
		if ( class_exists( 'TWTAEO_AI_Description' ) && TWTAEO_AI_Description::is_bulk_context() ) {
			return new WP_Error( 'twtaeo_bulk', __( 'Refusing to call the AI provider during an import, cron or bulk edit.', 'twt-aeo-ultimate' ) );
		}
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			return new WP_Error( 'twtaeo_no_client', __( 'The AI client is unavailable.', 'twt-aeo-ultimate' ) );
		}
		if ( ! self::consume_ai_budget( (int) $settings['ai_daily_cap'] ) ) {
			return new WP_Error(
				'twtaeo_capped',
				sprintf(
					/* translators: %d: the configured daily cap. */
					__( 'Daily AI summary cap of %d reached. It resets tomorrow.', 'twt-aeo-ultimate' ),
					(int) $settings['ai_daily_cap']
				)
			);
		}

		$facts      = self::resolve_items( $collection );
		$archetypes = self::archetypes();
		$archetype  = isset( $archetypes[ $collection['archetype'] ] ) ? $archetypes[ $collection['archetype'] ] : null;

		if ( 0 === $facts['count'] ) {
			return new WP_Error( 'twtaeo_empty', __( 'This collection resolves to no products, so there is nothing to describe.', 'twt-aeo-ultimate' ) );
		}

		$lines = array();
		foreach ( array_slice( $facts['items'], 0, 20 ) as $item ) {
			$lines[] = '- ' . $item['name']
				. ( $item['price'] > 0 ? ' (' . html_entity_decode( wp_strip_all_tags( wc_price( $item['price'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ')' : '' );
		}

		$prompt  = "Write a 2-3 sentence description of a product collection for an online store.\n\n";
		$prompt .= 'Collection name: ' . $collection['title'] . "\n";
		if ( $archetype ) {
			$prompt .= 'Intended for: ' . wp_strip_all_tags( $archetype['audience'] ? $archetype['audience'] : $archetype['label'] ) . "\n";
		}
		if ( '' !== $collection['custom_terms'] ) {
			$prompt .= 'Merchant intent phrases: ' . $collection['custom_terms'] . "\n";
		}
		if ( '' !== $collection['target_query'] ) {
			$prompt .= 'It should read as an answer to the question: ' . $collection['target_query'] . "\n";
		}
		$prompt .= 'Products in it:' . "\n" . implode( "\n", $lines ) . "\n\n";
		$prompt .= "Rules: state only what the list above supports. Do not invent compatibility, specifications, awards or claims. ";
		$prompt .= "Do not use marketing superlatives. Explain who the group is for and what buying it together achieves. ";
		$prompt .= 'Plain prose, no headings, no bullet points, no quotation marks.';

		$provider = TWTAEO_AI_Client::enrich_provider();
		$result   = TWTAEO_AI_Client::complete( $provider, $prompt, array( 'max_tokens' => 300 ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = trim( wp_strip_all_tags( is_string( $result ) ? $result : '' ) );
		if ( '' === $text ) {
			return new WP_Error( 'twtaeo_empty_reply', __( 'The AI provider returned nothing usable.', 'twt-aeo-ultimate' ) );
		}

		return $text;
	}

	/**
	 * Spend one unit of today's AI budget. Returns false when the cap is used up.
	 *
	 * @param int $cap Daily cap; 0 means "no AI at all".
	 */
	private static function consume_ai_budget( $cap ) {
		if ( $cap <= 0 ) {
			return false;
		}
		$key   = 'twtaeo_collection_ai_' . gmdate( 'Ymd' );
		$spent = (int) get_transient( $key );
		if ( $spent >= $cap ) {
			return false;
		}
		set_transient( $key, $spent + 1, DAY_IN_SECONDS );
		return true;
	}

	/** The text a collection publishes: merchant's own, else AI's, else computed. */
	public static function effective_summary( array $collection ) {
		if ( '' !== trim( (string) $collection['summary'] ) ) {
			return $collection['summary'];
		}
		return self::computed_summary( $collection );
	}

	// ── Building a collection from a query ────────────────────────────────────

	/**
	 * Minimum products a generated collection must resolve to.
	 *
	 * Two products is not a collection, it is a pair. Below this the build is
	 * refused rather than shipped thin — a near-empty generated category is
	 * exactly what a doorway page looks like from the outside.
	 */
	const MIN_BUILD_ITEMS = 3;

	/**
	 * Candidate products for a search query, chosen deterministically.
	 *
	 * This runs **before** the AI and exists to bound it. A model cannot be handed
	 * a 5,000-product catalogue in a prompt, and a model asked to name products
	 * from memory will invent SKUs the store does not sell. So the catalogue is
	 * searched here, in the database, and the model only ever picks from what comes
	 * back.
	 *
	 * The query is searched twice: whole, then with its intent words stripped.
	 * "best camera for beginners" finds nothing as a phrase; "camera" finds the
	 * cameras, which is what the collection is actually made of.
	 *
	 * @param string $query
	 * @param int    $limit
	 * @return array[] Each: id, name, price, stock, sku.
	 */
	public static function suggest_products( $query, $limit = 40 ) {
		$query = trim( (string) $query );
		if ( '' === $query || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$ids = self::search_product_ids( $query, $limit );

		if ( count( $ids ) < self::MIN_BUILD_ITEMS ) {
			$stripped = self::strip_intent_words( $query );
			if ( '' !== $stripped && $stripped !== strtolower( $query ) ) {
				$ids = array_unique( array_merge( $ids, self::search_product_ids( $stripped, $limit ) ) );
			}
		}

		$settings = self::settings();
		$out      = array();

		foreach ( array_slice( $ids, 0, $limit ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$visibility = $product->get_catalog_visibility();
			if ( 'visible' !== $visibility && 'catalog' !== $visibility ) {
				continue;
			}

			$stock = class_exists( 'TWTAEO_Sitemap' )
				? TWTAEO_Sitemap::resolved_stock_status( $product )
				: $product->get_stock_status();

			if ( ! empty( $settings['exclude_oos'] ) && 'outofstock' === $stock ) {
				continue;
			}

			$out[] = array(
				'id'    => (int) $pid,
				'name'  => html_entity_decode( $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'price' => (float) wc_get_price_to_display( $product ),
				'stock' => $stock,
				'sku'   => $product->get_sku(),
			);
		}

		return $out;
	}

	/** Product IDs matching a search string, newest relevance order. */
	private static function search_product_ids( $search, $limit ) {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				's'                => $search,
				'suppress_filters' => false,
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Drop intent modifiers and stopwords, leaving the thing being bought.
	 *
	 * @param string $query
	 * @return string
	 */
	private static function strip_intent_words( $query ) {
		$drop = array( 'best', 'top', 'good', 'for', 'the', 'a', 'an', 'and', 'or', 'to', 'with', 'my', 'in', 'on', 'of', 'vs', 'versus', 'under', 'over', 'cheap', 'buy' );

		foreach ( self::archetypes() as $archetype ) {
			foreach ( $archetype['keywords'] as $keyword ) {
				foreach ( explode( ' ', $keyword ) as $word ) {
					$drop[] = $word;
				}
			}
		}
		$drop = array_unique( $drop );

		$words = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$kept  = array();
		foreach ( (array) $words as $word ) {
			// Bare numbers are price thresholds ("under 500"), not product nouns.
			if ( in_array( $word, $drop, true ) || is_numeric( $word ) ) {
				continue;
			}
			$kept[] = $word;
		}

		return implode( ' ', $kept );
	}

	/**
	 * Ask the AI to draft a collection for a query, choosing from real products.
	 *
	 * Returns a *proposal*. Nothing is written — the merchant sees it, edits it,
	 * and only then commits. That review step is what separates this from scaled
	 * content generation, and it is why there is no bulk equivalent of this method
	 * and must never be one.
	 *
	 * The response shape is validated rather than trusted: product IDs are
	 * intersected against the candidates we supplied, so a model that invents an ID
	 * simply loses it.
	 *
	 * @param string $query
	 * @param string $archetype
	 * @return array{title:string,summary:string,product_ids:int[],candidates:array}|WP_Error
	 */
	public static function ai_draft( $query, $archetype = '' ) {
		$settings = self::settings();
		if ( empty( $settings['ai_summaries'] ) ) {
			return new WP_Error( 'twtaeo_ai_off', __( 'AI is switched off for this module. Turn it on under Settings first.', 'twt-aeo-ultimate' ) );
		}
		if ( class_exists( 'TWTAEO_AI_Description' ) && TWTAEO_AI_Description::is_bulk_context() ) {
			return new WP_Error( 'twtaeo_bulk', __( 'Refusing to call the AI provider during an import, cron or bulk edit.', 'twt-aeo-ultimate' ) );
		}
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			return new WP_Error( 'twtaeo_no_client', __( 'The AI client is unavailable.', 'twt-aeo-ultimate' ) );
		}

		$candidates = self::suggest_products( $query );
		if ( count( $candidates ) < self::MIN_BUILD_ITEMS ) {
			return new WP_Error(
				'twtaeo_thin',
				sprintf(
					/* translators: 1: number of matching products found, 2: the minimum required. */
					__( 'Only %1$d products in your catalogue match that query, and a collection needs at least %2$d. This is a gap in your catalogue, not something a page can fix.', 'twt-aeo-ultimate' ),
					count( $candidates ),
					self::MIN_BUILD_ITEMS
				)
			);
		}

		if ( ! self::consume_ai_budget( (int) $settings['ai_daily_cap'] ) ) {
			return new WP_Error(
				'twtaeo_capped',
				sprintf(
					/* translators: %d: the configured daily cap. */
					__( 'Daily AI cap of %d reached. It resets tomorrow.', 'twt-aeo-ultimate' ),
					(int) $settings['ai_daily_cap']
				)
			);
		}

		$archetypes = self::archetypes();
		$audience   = isset( $archetypes[ $archetype ]['audience'] ) ? $archetypes[ $archetype ]['audience'] : '';

		$lines = array();
		foreach ( $candidates as $c ) {
			$lines[] = $c['id'] . ' | ' . $c['name'] . ( $c['price'] > 0 ? ' | ' . number_format( $c['price'], 2 ) : '' );
		}

		$prompt  = "A shopper searched for: \"" . $query . "\"\n\n";
		$prompt .= "Below are products from this store, one per line, as: ID | NAME | PRICE.\n";
		$prompt .= implode( "\n", $lines ) . "\n\n";
		$prompt .= "Choose the products that together answer that search, and describe the group.\n";
		if ( '' !== $audience ) {
			$prompt .= 'The group is intended for ' . wp_strip_all_tags( $audience ) . ".\n";
		}
		$prompt .= "\nRules:\n";
		$prompt .= "- Only use IDs from the list above. Never invent an ID or a product name.\n";
		$prompt .= "- Choose between " . self::MIN_BUILD_ITEMS . " and 12 products. If fewer than " . self::MIN_BUILD_ITEMS . " genuinely fit, return an empty list rather than padding it.\n";
		$prompt .= "- The summary is 2-3 sentences of plain prose. State only what the list supports. No invented specifications, compatibility or claims. No marketing superlatives.\n";
		$prompt .= "- The title names the group as a shopper would describe it. It is not the search query repeated back.\n\n";
		$prompt .= 'Reply with JSON only: {"title": "...", "summary": "...", "product_ids": [1, 2, 3]}';

		$response = TWTAEO_AI_Client::complete(
			TWTAEO_AI_Client::enrich_provider(),
			$prompt,
			array(
				'max_tokens' => 700,
				'json'       => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = TWTAEO_AI_Client::extract_json( is_string( $response ) ? $response : '' );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded ) ) {
			return new WP_Error( 'twtaeo_bad_json', __( 'The AI provider did not return usable JSON. Try again, or build the collection by hand.', 'twt-aeo-ultimate' ) );
		}

		// Shape validation. Nothing here trusts the model: IDs are intersected with
		// what we offered, and anything it invented is dropped on the floor.
		$allowed  = wp_list_pluck( $candidates, 'id' );
		$chosen   = array();
		foreach ( (array) ( $decoded['product_ids'] ?? array() ) as $pid ) {
			$pid = (int) $pid;
			if ( in_array( $pid, $allowed, true ) && ! in_array( $pid, $chosen, true ) ) {
				$chosen[] = $pid;
			}
		}
		$chosen = array_slice( $chosen, 0, self::MAX_ITEMS );

		if ( count( $chosen ) < self::MIN_BUILD_ITEMS ) {
			return new WP_Error(
				'twtaeo_thin_pick',
				__( 'The AI could not find enough products that genuinely fit that search. Rather than pad the group out, it returned nothing — pick the products yourself, or leave this query alone.', 'twt-aeo-ultimate' )
			);
		}

		$title = sanitize_text_field( (string) ( $decoded['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = ucwords( $query );
		}

		return array(
			'title'       => $title,
			'summary'     => sanitize_textarea_field( wp_strip_all_tags( (string) ( $decoded['summary'] ?? '' ) ) ),
			'product_ids' => $chosen,
			'candidates'  => $candidates,
		);
	}

	/**
	 * Create a category from a reviewed draft, and map a collection to it.
	 *
	 * ⚠ **This publishes a live URL.** A `product_cat` term has no draft state, so
	 * unlike TWTAEO_Local_Pages there is no "created as a draft" safety net here —
	 * the term exists, appears in shop navigation and enters the sitemap the moment
	 * this runs. The owner chose that trade deliberately (2026-07-28). Three things
	 * blunt it, and none should be removed:
	 *
	 * 1. Products are **appended** to the new term (`$append = true`), never moved.
	 *    Nothing leaves the category it is already in.
	 * 2. The collection is created **inactive**, so no CollectionPage or ItemList is
	 *    published until the merchant switches it live.
	 * 3. Because we created the term we can undo it exactly — see delete_with_term().
	 *
	 * Called one collection at a time, from a merchant clicking a button, after
	 * reviewing the draft. There is deliberately no bulk version: one at a time is
	 * the line between a landing page and scaled content generation.
	 *
	 * @param array $args title, summary, product_ids, archetype, target_query, evidence.
	 * @return array|WP_Error The stored collection.
	 */
	public static function create_from_query( array $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'twtaeo_cap', __( 'You do not have permission to create categories.', 'twt-aeo-ultimate' ) );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'twtaeo_no_wc', __( 'WooCommerce is not active.', 'twt-aeo-ultimate' ) );
		}

		$title = sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'twtaeo_no_title', __( 'Give the collection a title before creating it.', 'twt-aeo-ultimate' ) );
		}

		$product_ids = array();
		foreach ( (array) ( $args['product_ids'] ?? array() ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && 'product' === get_post_type( $pid ) && ! in_array( $pid, $product_ids, true ) ) {
				$product_ids[] = $pid;
			}
		}
		$product_ids = array_slice( $product_ids, 0, self::MAX_ITEMS );

		if ( count( $product_ids ) < self::MIN_BUILD_ITEMS ) {
			return new WP_Error(
				'twtaeo_thin',
				sprintf(
					/* translators: %d: minimum number of products. */
					__( 'A collection needs at least %d products. Add more before creating the category.', 'twt-aeo-ultimate' ),
					self::MIN_BUILD_ITEMS
				)
			);
		}

		// Reuse an existing category of the same name rather than creating a near
		// duplicate — but then it is *theirs*, so created_term stays 0 and the undo
		// will not offer to delete it.
		$existing_term = get_term_by( 'name', $title, 'product_cat' );
		$created_term  = 0;

		if ( $existing_term && ! is_wp_error( $existing_term ) ) {
			$term_id = (int) $existing_term->term_id;
		} else {
			$inserted = wp_insert_term( $title, 'product_cat' );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
			$term_id      = (int) $inserted['term_id'];
			$created_term = 1;
		}

		if ( self::get_for_term( $term_id ) ) {
			return new WP_Error(
				'twtaeo_term_taken',
				__( 'That category already publishes a collection. Edit the existing one instead.', 'twt-aeo-ultimate' )
			);
		}

		// Append, never replace. $append = true is what keeps every product in the
		// categories it already belonged to.
		$assigned = array();
		foreach ( $product_ids as $pid ) {
			$result = wp_set_object_terms( $pid, array( $term_id ), 'product_cat', true );
			if ( ! is_wp_error( $result ) ) {
				$assigned[] = $pid;
			}
		}
		clean_term_cache( array( $term_id ), 'product_cat' );

		$evidence = array();
		if ( ! empty( $args['evidence'] ) && is_array( $args['evidence'] ) ) {
			$evidence = array(
				'query'       => sanitize_text_field( $args['evidence']['query'] ?? '' ),
				'impressions' => (int) ( $args['evidence']['impressions'] ?? 0 ),
				'clicks'      => (int) ( $args['evidence']['clicks'] ?? 0 ),
				'source'      => sanitize_text_field( $args['evidence']['source'] ?? 'search-console' ),
				'recorded'    => time(),
			);
		}

		$saved = self::save(
			array(
				'title'        => $title,
				'archetype'    => sanitize_key( $args['archetype'] ?? 'skill-starter' ),
				'source'       => 'category',
				'term_id'      => $term_id,
				'target_query' => sanitize_text_field( $args['target_query'] ?? '' ),
				'summary'      => sanitize_textarea_field( $args['summary'] ?? '' ),
				// Inactive on purpose. Creating the grouping and publishing schema
				// about it are two decisions, and the merchant has only made the first.
				'active'       => 0,
				'products'     => $product_ids,
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// save() carries provenance forward from the existing record and will not
		// accept it from form input, so it is written directly here — this is the
		// one place that legitimately sets it.
		$all = self::get_all();
		$all[ $saved['id'] ]['created_term']      = $created_term;
		$all[ $saved['id'] ]['assigned_products'] = $assigned;
		$all[ $saved['id'] ]['evidence']          = $evidence;
		update_option( self::OPTION_KEY, $all );
		self::flush_caches();

		return $all[ $saved['id'] ];
	}

	/**
	 * Undo a generated collection: delete the category we made and detach the
	 * products we put in it.
	 *
	 * Only the products recorded in `assigned_products` are detached, and only a
	 * term recorded in `created_term` is deleted. Anything the merchant added
	 * afterwards survives — an undo that removes more than it created is not an
	 * undo, it is a second mistake.
	 *
	 * @param string $id Collection id.
	 * @return array{deleted_term:bool,detached:int}|WP_Error
	 */
	public static function delete_with_term( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'twtaeo_cap', __( 'You do not have permission to delete categories.', 'twt-aeo-ultimate' ) );
		}

		$collection = self::get( $id );
		if ( ! $collection ) {
			return new WP_Error( 'twtaeo_missing', __( 'That collection no longer exists.', 'twt-aeo-ultimate' ) );
		}
		if ( empty( $collection['created_term'] ) ) {
			return new WP_Error(
				'twtaeo_not_ours',
				__( 'That category was not created by this plugin, so it is not ours to delete. Deleting the collection leaves it untouched.', 'twt-aeo-ultimate' )
			);
		}

		$term_id  = (int) $collection['term_id'];
		$detached = 0;

		foreach ( (array) $collection['assigned_products'] as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && wp_remove_object_terms( $pid, array( $term_id ), 'product_cat' ) === true ) {
				$detached++;
			}
		}

		$deleted = ( true === wp_delete_term( $term_id, 'product_cat' ) );
		clean_term_cache( array( $term_id ), 'product_cat' );

		self::delete( $id );

		return array(
			'deleted_term' => $deleted,
			'detached'     => $detached,
		);
	}

	// ── Discovery ─────────────────────────────────────────────────────────────

	/**
	 * Find the merchant's categories that are *already* intent-shaped.
	 *
	 * This is the whole discovery story for v1 and it is deliberately modest: it
	 * reads product_cat names, slugs and descriptions and reports which archetype
	 * each one matches. Nothing is created, nothing is changed — the merchant maps
	 * what they agree with.
	 *
	 * Two sources named in the original brief are absent on purpose:
	 * WordPress core keeps **no** history of internal `?s=` searches, so there is
	 * nothing to read (logging them would be a new feature with its own privacy
	 * disclosure), and Google Site Kit exposes no supported way for another plugin
	 * to borrow its Search Console credentials — we have our own OAuth instead,
	 * which is what query_candidates() uses.
	 *
	 * @param bool $force Skip the cache.
	 * @return array[] Each: term_id, name, count, archetype, matched.
	 */
	public static function category_candidates( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'twtaeo_collection_candidates' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$found = array();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 300,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $found;
		}

		$mapped = array();
		foreach ( self::get_all() as $c ) {
			if ( 'category' === $c['source'] ) {
				$mapped[ (int) $c['term_id'] ] = true;
			}
		}

		foreach ( $terms as $term ) {
			if ( isset( $mapped[ $term->term_id ] ) ) {
				continue; // Already a collection.
			}

			// Name and slug only. The description is prose and will mention "complete"
			// or "professional" incidentally; what makes a category intent-shaped is
			// what the merchant chose to *call* it.
			$haystack = strtolower(
				html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' . str_replace( '-', ' ', $term->slug )
			);

			foreach ( self::archetypes() as $key => $archetype ) {
				$matched = array();
				foreach ( $archetype['keywords'] as $keyword ) {
					if ( self::keyword_hit( $haystack, $keyword ) ) {
						$matched[] = $keyword;
					}
				}
				if ( empty( $matched ) ) {
					continue;
				}
				$found[] = array(
					'term_id'   => (int) $term->term_id,
					'name'      => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'count'     => (int) $term->count,
					'archetype' => $key,
					'label'     => $archetype['label'],
					'matched'   => $matched,
				);
				break; // First archetype wins; a term is one kind of thing.
			}
		}

		// A category with one product is not a collection.
		$found = array_values(
			array_filter(
				$found,
				static function ( $row ) {
					return $row['count'] > 1;
				}
			)
		);

		usort(
			$found,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		set_transient( 'twtaeo_collection_candidates', $found, self::CANDIDATE_TTL );
		return $found;
	}

	/**
	 * Search Console queries that look like intent questions, classified.
	 *
	 * Uses the plugin's existing Google OAuth connection — the same one the
	 * Command Center reads — and the existing deterministic buyer-intent
	 * classifier. Returns nothing at all when Search Console is not connected;
	 * an empty list is the honest answer, not a reason to guess.
	 *
	 * @param int $limit Max rows to return.
	 * @return array[] Each: query, clicks, impressions, intent, archetype.
	 */
	public static function query_candidates( $limit = 40 ) {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return array();
		}

		$config   = get_option( 'twtaeo_google_config', array() );
		$site_url = is_array( $config ) && ! empty( $config['gsc_site_url'] ) ? $config['gsc_site_url'] : '';
		if ( '' === $site_url ) {
			return array();
		}

		$cache_key = 'twtaeo_collection_queries';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}

		$rows = TWTAEO_Google_OAuth::gsc_search_analytics(
			$site_url,
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
				'endDate'    => gmdate( 'Y-m-d' ),
				'dimensions' => array( 'query' ),
				'rowLimit'   => 500,
			)
		);

		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$archetypes = self::archetypes();
		$out        = array();

		// The API returns the whole response body; the rows live under `rows`.
		foreach ( (array) ( $rows['rows'] ?? array() ) as $row ) {
			$query = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $query ) {
				continue;
			}
			$haystack = strtolower( $query );

			$archetype = '';
			$label     = '';
			foreach ( $archetypes as $key => $a ) {
				foreach ( $a['keywords'] as $keyword ) {
					if ( self::keyword_hit( $haystack, $keyword ) ) {
						$archetype = $key;
						$label     = $a['label'];
						break 2;
					}
				}
			}
			if ( '' === $archetype ) {
				continue; // Not an intent-shaped query.
			}

			$intent = class_exists( 'TWTAEO_Buyer_Intent' ) && method_exists( 'TWTAEO_Buyer_Intent', 'classify' )
				? TWTAEO_Buyer_Intent::classify( $query )
				: '';

			$out[] = array(
				'query'       => $query,
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'intent'      => $intent,
				'archetype'   => $archetype,
				'label'       => $label,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);

		set_transient( $cache_key, $out, self::CANDIDATE_TTL );
		return array_slice( $out, 0, $limit );
	}
}
