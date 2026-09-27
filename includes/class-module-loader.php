<?php
/**
 * Module Loader
 *
 * Registers all available modules and manages their active/inactive state.
 * Each module is stored as a definition array. Toggle state is saved in wp_options.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Module_Loader {

	const OPTION_KEY = 'twtaeo_active_modules';

	/**
	 * Ledger of every module slug this install has ever registered. Lets an
	 * upgrade tell "new module, never seen" (enable it if its default is on)
	 * apart from "user turned it off" (leave it off). Introduced 2.17.0.
	 */
	const KNOWN_KEY = 'twtaeo_known_modules';

	/**
	 * All registered modules.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Currently active module slugs.
	 *
	 * @var array
	 */
	private $active = array();

	public function __construct() {
		$this->active = get_option( self::OPTION_KEY, array() );
		$this->register_modules();
		$this->adopt_new_modules();
	}

	/**
	 * Enable default-on modules this install has never seen. The first-activation
	 * seeding above only runs when twtaeo_active_modules is absent, so a site
	 * upgrading from an older version would otherwise never get a module added
	 * later (ai-visibility was invisible on every upgraded site). Deliberate
	 * offs survive: once a slug is in the ledger it is never re-enabled here.
	 */
	private function adopt_new_modules() {
		$registered = array_keys( $this->modules );
		$known      = get_option( self::KNOWN_KEY, false );
		if ( ! is_array( $known ) ) {
			// Ledger predates nothing but itself: every module except the ones
			// shipped alongside it existed before 2.17.0 and may carry a
			// deliberate off, so only the truly-new slugs count as unseen.
			$known = array_diff( $registered, array( 'ai-visibility' ) );
		}

		$changed = false;
		foreach ( array_diff( $registered, $known ) as $slug ) {
			if ( ! empty( $this->modules[ $slug ]['default'] ) && ! in_array( $slug, $this->active, true ) ) {
				$this->active[] = $slug;
				$changed        = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION_KEY, $this->active );
		}
		if ( array_values( array_diff( $registered, $known ) ) !== array() ) {
			update_option( self::KNOWN_KEY, $registered );
		}
	}

	/**
	 * Register all available modules.
	 * Add new modules here as they are built.
	 */
	private function register_modules() {
		$this->modules = array(

			'schema-advisor' => array(
				'slug'        => 'schema-advisor',
				'title'       => 'Schema Advisor',
				'description' => 'Detects schema on each page and evaluates it based on page intent. Flags missing or incorrect schema without false warnings.',
				'icon'        => 'dashicons-editor-code',
				'phase'       => 'free',
				'default'     => true,
			),

			'seo-compatibility' => array(
				'slug'        => 'seo-compatibility',
				'title'       => 'SEO Plugin Compatibility',
				'description' => 'Detects your active SEO plugin and checks what schema it is already handling so we never duplicate or conflict.',
				'icon'        => 'dashicons-plugins-checked',
				'phase'       => 'free',
				'default'     => true,
			),

			'page-intent' => array(
				'slug'        => 'page-intent',
				'title'       => 'Page Intent Classifier',
				'description' => 'Classifies each page as homepage, service page, blog post, location page, or product page so advice is always relevant.',
				'icon'        => 'dashicons-category',
				'phase'       => 'free',
				'default'     => true,
			),

			'author-schema' => array(
				'slug'        => 'author-schema',
				'title'       => 'Author Schema',
				'description' => 'Detects whether your theme displays author information front-facing and adds author schema only when it is appropriate and missing.',
				'icon'        => 'dashicons-admin-users',
				'phase'       => 'free',
				'default'     => false,
				'menu'        => array(
					'parent' => 'appearance',
					'title'  => 'Author Schema',
				),
			),

			'social-graph' => array(
				'slug'        => 'social-graph',
				'title'       => 'Social Graph',
				'description' => 'Unified Open Graph and Twitter/X Card manager. Scans every page for OG coverage and lets you edit og:title, og:description, og:image, og:type, twitter:card, and twitter:creator in one modal. Generate share-friendly og:descriptions and og:images with AI — one page at a time or as a bulk background job — or reuse the meta descriptions you already have. Auto-fills twitter:creator from Author Entity schema and sets a global twitter:site handle for the whole site.',
				'icon'        => 'dashicons-share',
				'phase'       => 'free',
				'default'     => false,
			),

			'faq-detector' => array(
				'slug'        => 'faq-detector',
				'title'       => 'FAQ Detector',
				'description' => 'Scans pages for accordion blocks, FAQ sections, and Q&A content. If found without FAQPage schema, flags it and can add the schema automatically.',
				'icon'        => 'dashicons-editor-help',
				'phase'       => 'free',
				'default'     => false,
			),

			'service-detector' => array(
				'slug'        => 'service-detector',
				'title'       => 'Service Detector',
				'description' => 'Scans pages, posts, and WooCommerce products for service-oriented content. Flags pages missing Service schema and rescans automatically on save.',
				'icon'        => 'dashicons-hammer',
				'phase'       => 'free',
				'default'     => false,
			),

			'author-box' => array(
				'slug'        => 'author-box',
				'title'       => 'Author Box',
				'description' => 'Detects whether your site has an author box and author schema. Shows author bio and avatar status and recommends fixes when missing.',
				'icon'        => 'dashicons-id-alt',
				'phase'       => 'free',
				'default'     => true,
			),

			'contact-detector' => array(
				'slug'        => 'contact-detector',
				'title'       => 'Contact Detector',
				'description' => 'Scans contact pages for Organization, PostalAddress, and ContactPoint schema. Flags missing entity data and consolidation issues.',
				'icon'        => 'dashicons-location-alt',
				'phase'       => 'free',
				'default'     => true,
			),

			'woocommerce-detector' => array(
				'slug'        => 'woocommerce-detector',
				'title'       => 'WooCommerce',
				'description' => 'Scans WooCommerce products for schema presence. Classifies products as physical or service, flags missing Product or Service schema, and rescans on save.',
				'icon'        => 'dashicons-cart',
				'phase'       => 'free',
				'default'     => false,
				'requires'    => 'woocommerce',
			),

			'woocommerce-gmc' => array(
				'slug'        => 'woocommerce-gmc',
				'title'       => 'Google Merchant Center Sync',
				'description' => 'Compares your WooCommerce catalog against your live Google Merchant Center feed. Tracks price and availability mismatches, GTIN/MPN/SKU gaps, feed sync latency, and surfaces rejection error codes directly from the Merchant API.',
				'icon'        => 'dashicons-update-alt',
				'phase'       => 'free',
				'default'     => false,
				'requires'    => 'woocommerce',
			),

			'woocommerce-bmc' => array(
				'slug'        => 'woocommerce-bmc',
				'title'       => 'Bing Merchant Center Sync',
				'description' => 'Compares your WooCommerce catalog against your live Bing Merchant Center feed via the Microsoft Advertising Shopping Content API. Tracks price and availability mismatches, GTIN/MPN/SKU gaps, sync latency, and surfaces item-level disapproval codes.',
				'icon'        => 'dashicons-update-alt',
				'phase'       => 'free',
				'default'     => false,
				'requires'    => 'woocommerce',
			),

			'smart-collections' => array(
				'slug'        => 'smart-collections',
				'title'       => 'Smart Collections',
				'description' => 'Turns the categories you already built — Starter Kits, Pro Setups, Under $500 — into published Collections: a CollectionPage with an ItemList, a written explanation of who the group is for, and an entry in llms.txt. Aimed at the shopper who is still asking what to buy rather than which one. Reads your Search Console queries to suggest which categories qualify, takes over the empty collection schema your SEO plugin writes on those archives, and creates no pages or categories of its own. Off until you switch it on.',
				'icon'        => 'dashicons-screenoptions',
				'phase'       => 'free',
				'default'     => false,
				'requires'    => 'woocommerce',
			),

			'promotions' => array(
				'slug'        => 'promotions',
				'title'       => 'Promotions & Price Rules',
				'description' => 'Describes your discounts honestly. Publishes the two conditional prices that can be stated without contradicting the page — quantity breaks and member pricing — as UnitPriceSpecification entries carrying their own conditions, and sends coupon codes to Google Merchant Center, which is the channel Google built for them. Reads WooCommerce coupons directly, so coupons made by Advanced Coupons, Smart Coupons and the rest are covered, and names any plugin that can change a price without a code so a feed price mismatch never comes as a surprise. Never writes a discount into your offer price. Off until you switch it on.',
				'icon'        => 'dashicons-tag',
				'phase'       => 'free',
				'default'     => false,
				'requires'    => 'woocommerce',
			),

			'author-entity' => array(
				'slug'        => 'author-entity',
				'title'       => 'Author Entity',
				'description' => 'Manage author E-E-A-T profiles, certifications, and social sameAs profiles. Outputs Person schema with EducationalOccupationalCredential and sameAs. Adds AEO fields to Users → Profile.',
				'icon'        => 'dashicons-id',
				'phase'       => 'free',
				'default'     => true,
			),

			'author-hover-cards' => array(
				'slug'        => 'author-hover-cards',
				'title'       => 'Author Hover Cards',
				'description' => 'Shows a lightweight author preview card when visitors hover over author links on posts. Displays bio, job title, certifications, expertise tags, and social profiles.',
				'icon'        => 'dashicons-id-alt',
				'phase'       => 'free',
				'default'     => false,
			),


			'local-pack' => array(
				'slug'        => 'local-pack',
				'title'       => 'Local Pack',
				'description' => 'Build your LocalBusiness schema with a predictive business-type picker, opening hours, service area, and geo coordinates. Audit NAP consistency against Google Knowledge Graph, Google Business Profile, and Bing Maps.',
				'icon'        => 'dashicons-location',
				'phase'       => 'free',
				'default'     => false,
			),

			'sitemap' => array(
				'slug'        => 'sitemap',
				'title'       => 'Sitemap Generator',
				'description' => 'Generates and serves an XML sitemap at /aeo-sitemap.xml. Configurable per post type with change frequency and priority. Cache invalidates automatically on content changes. Warns when Yoast, Rank Math, or AIOSEO already provides a sitemap.',
				'icon'        => 'dashicons-media-document',
				'phase'       => 'free',
				'default'     => false,
			),

			'pr-bridge' => array(
				'slug'        => 'pr-bridge',
				'title'       => 'PR Bridge AI',
				'description' => 'Detects press release intent in posts, transforms them into AP-style copy using Claude, and distributes to wire services (EIN Presswire, EasyPRWire). Includes a Media Report for tracking distribution status.',
				'icon'        => 'dashicons-megaphone',
				'phase'       => 'free',
				'default'     => false,
			),

			'index-now' => array(
				'slug'        => 'index-now',
				'title'       => 'IndexNow',
				'description' => 'Instantly notifies Bing, Yandex, and all other IndexNow-compatible search engines when your content is published or updated. Generates and hosts your verification key automatically — no file upload required.',
				'icon'        => 'dashicons-update',
				'phase'       => 'free',
				'default'     => false,
			),

			'news' => array(
				'slug'        => 'news',
				'title'       => 'Google News',
				'description' => 'Generates a Google News sitemap at /news-sitemap.xml, outputs NewsArticle JSON-LD with full Author Entity integration, and adds a per-post metabox for article section, keywords, and sitemap exclusion.',
				'icon'        => 'dashicons-rss',
				'phase'       => 'free',
				'default'     => false,
			),

			'last-updated-badge' => array(
				'slug'        => 'last-updated-badge',
				'title'       => 'Last Updated Badge',
				'description' => 'Prepends a visible "Last Updated: [date]" line to single blog posts so AI models and readers always see an explicit freshness signal. Only shown when the post has been modified after its original publish date.',
				'icon'        => 'dashicons-calendar-alt',
				'phase'       => 'free',
				'default'     => false,
			),

			'reviews' => array(
				'slug'        => 'reviews',
				'title'       => 'Reviews',
				'description' => 'Collect and manage customer reviews. Outputs context-aware AggregateRating and Review schema — automatically scoped to LocalBusiness, Product, Service, or Organization based on your active modules. Includes frontend shortcodes for review display and submission.',
				'icon'        => 'dashicons-star-filled',
				'phase'       => 'free',
				'default'     => false,
			),

			'smart-404' => array(
				'slug'        => 'smart-404',
				'title'       => 'Smart 404 Rescue',
				'description' => 'Turns 404s into fixes instead of database bloat. Skips hacker/bot probe traffic outright, then for genuine visitor 404s finds the closest published page by typo-distance, auto-corrects broken internal links at their source (with an undo log), and redirects the visitor. Recovers renamed/moved pages by matching your Search Console 404s to live content and creating permanent 301s. No per-404 logging.',
				'icon'        => 'dashicons-controls-repeat',
				'phase'       => 'free',
				'default'     => false,
			),

			'ai-rate-limiter' => array(
				'slug'        => 'ai-rate-limiter',
				'title'       => 'AI Prompt Rate Limiter',
				'description' => 'Intercepts WordPress AI Client requests before they reach external LLM providers (OpenAI, Anthropic, etc.). Blocks explicitly denied bots and enforces per-bot volumetric rate limits to protect the site\'s API budget.',
				'icon'        => 'dashicons-shield',
				'phase'       => 'free',
				'default'     => false,
			),

			'ai-visibility' => array(
				'slug'        => 'ai-visibility',
				'title'       => 'AI Visibility — citation engine',
				'description' => 'Asks ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat the questions shoppers ask, on your own keys, and shows whether your site was cited, named or absent — and who was cited instead.',
				'icon'        => 'dashicons-visibility',
				'phase'       => 'free',
				'default'     => true,
			),

			'bot-view' => array(
				'slug'        => 'bot-view',
				'title'       => 'Bot View',
				'description' => 'Fetches any page exactly the way no-JavaScript AI crawlers (GPTBot, ClaudeBot, PerplexityBot) do and reports which schema and content they can see, which is JavaScript-locked (tag-manager schema, review widgets, client-rendered apps), and which data hiding inside scripts could be lifted into server-rendered schema.',
				'icon'        => 'dashicons-welcome-view-site',
				'phase'       => 'free',
				'default'     => true,
			),

			'chunk-view' => array(
				'slug'        => 'chunk-view',
				'title'       => 'RAG Engine',
				'description' => 'AI engines do not read whole pages — they retrieve passages (retrieval-augmented generation). Chunk View shows how each page is split into those passages and flags the ones that fall apart on their own. Upload your spec sheets, manuals and FAQs as Documents, then compare them with the Google and Bing searches you nearly rank for and the questions AI engines answered without citing you, to see exactly which answers your site is missing. Documents are processed on your own server; AI labelling and the online law check are optional.',
				'icon'        => 'dashicons-editor-ol',
				'phase'       => 'free',
				'default'     => false,
			),

			'funnel-audit' => array(
				'slug'        => 'funnel-audit',
				'title'       => 'Funnel Audit',
				'description' => 'The Money Page Funnel & Citability Auditor. AI engines cite informational posts, not money pages — this audit connects AI attention (crawler hits + referrals from ChatGPT, Perplexity, Claude, Gemini) with your internal link graph to find money pages that are buried or orphaned, generic anchor text, missing schema on conversion pages, and cited posts with no CTA bridge to a money page.',
				'icon'        => 'dashicons-filter',
				'phase'       => 'free',
				'default'     => true,
			),

		);

		// On first activation, enable default modules.
		if ( false === get_option( self::OPTION_KEY ) ) {
			$defaults = array();
			foreach ( $this->modules as $slug => $module ) {
				if ( ! empty( $module['default'] ) ) {
					$defaults[] = $slug;
				}
			}
			update_option( self::OPTION_KEY, $defaults );
			$this->active = $defaults;
		}
	}

	/**
	 * Get all registered module definitions.
	 *
	 * @return array
	 */
	public function get_all() {
		return $this->modules;
	}

	/**
	 * Get only active module slugs.
	 *
	 * @return array
	 */
	public function get_active() {
		return $this->active;
	}

	/**
	 * Check if a specific module is active.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function is_active( $slug ) {
		return in_array( $slug, $this->active, true );
	}

	/**
	 * Toggle a module on or off and persist the state.
	 *
	 * @param string $slug   Module slug.
	 * @param bool   $active Whether to activate or deactivate.
	 */
	public function toggle( $slug, $active ) {
		if ( ! isset( $this->modules[ $slug ] ) ) {
			return;
		}

		if ( $active && ! in_array( $slug, $this->active, true ) ) {
			$this->active[] = $slug;
		} elseif ( ! $active ) {
			$this->active = array_values(
				array_filter( $this->active, function( $s ) use ( $slug ) {
					return $s !== $slug;
				} )
			);
		}

		update_option( self::OPTION_KEY, $this->active );
	}
}