<?php
/**
 * Knowledge Graph engine.
 *
 * Emits a single, unified `@graph` per page instead of a bag of loose JSON-LD
 * blocks. The backbone is an identity spine — WebSite → publisher → Organization,
 * and a WebPage node that is part of the WebSite and points (`about`) at the
 * primary entity — all cross-referenced by canonical `@id` hashes so an AI
 * crawler receives one woven web of data.
 *
 * The Organization is built from the Company Profile, so its external grounding
 * (`sameAs`, including Wikipedia) is carried automatically. When this module is
 * active it is the site's identity source, so the standalone Organization writer
 * defers to it (see TWTAEO_Company_Schema_Writer).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Knowledge_Graph {

	/**
	 * Filter each per-type schema writer contributes its node(s) to. When the page
	 * folds, writers hang their nodes here instead of emitting their own <script>
	 * block, so the whole page ships one woven @graph.
	 *
	 *   add_filter( 'twtaeo_kg_nodes', callback, 10, 2 );
	 *   callback( array $nodes, array $context ) : array $nodes
	 *
	 * $context = { post_id, url, webpage_id, org_id, has_org, is_front }.
	 */
	const NODE_FILTER = 'twtaeo_kg_nodes';

	/**
	 * Minimum number of non-spine schema nodes (real blocks the plugin would emit
	 * separately) a page must carry before the graph folds them into one. Below
	 * this, the graph stays out of the way and the individual writers emit as usual.
	 */
	const FOLD_THRESHOLD = 2;

	/** Per-request fold decision (null until computed). @var bool|null */
	private static $folding = null;

	/** Cached candidate graph from the fold decision, reused by output(). */
	private static $graph = null;

	public static function register_hooks() {
		// Priority 1 — decide and, if folding, emit the graph before the writers run.
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
	}

	/**
	 * True when this page will fold its schema into a single @graph. The per-type
	 * writers call this to know whether to suppress their own block. The decision
	 * is computed once and cached for the rest of the request.
	 */
	public static function is_folding() {
		if ( null === self::$folding ) {
			self::decide();
		}
		return self::$folding;
	}

	/**
	 * Build the candidate graph once and decide whether to fold, based on how many
	 * non-spine nodes it carries. WebSite + WebPage are pure plumbing and never
	 * count, so a page whose only real schema is the Organization is left alone.
	 */
	private static function decide() {
		if ( is_admin() ) {
			self::$folding = false;
			self::$graph   = null;
			return;
		}

		$graph       = self::build_graph();
		self::$graph = $graph;

		$count = 0;
		foreach ( $graph['@graph'] as $node ) {
			$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( in_array( 'WebSite', $types, true ) || in_array( 'WebPage', $types, true ) ) {
				continue;
			}
			$count++;
		}

		self::$folding = ( $count >= self::FOLD_THRESHOLD );
	}

	/**
	 * The `@id`s this request will actually publish.
	 *
	 * ⚠️ Read this, not `is_folding()`, before suppressing another writer's node.
	 * They are not the same question: on a search page this graph can fold while
	 * `webpage_node()` returns null, so a suppression gated on `is_folding()`
	 * could remove a node and put nothing in its place.
	 *
	 * @return string[]
	 */
	public static function published_ids() {
		if ( null === self::$folding ) {
			self::decide();
		}
		if ( ! self::$folding || empty( self::$graph['@graph'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( self::$graph['@graph'] as $node ) {
			if ( ! empty( $node['@id'] ) ) {
				$ids[] = (string) $node['@id'];
			}
		}
		return $ids;
	}

	/**
	 * The `@type`s this request will actually publish, flattened.
	 *
	 * @return string[]
	 */
	public static function published_types() {
		if ( null === self::$folding ) {
			self::decide();
		}
		if ( ! self::$folding || empty( self::$graph['@graph'] ) ) {
			return array();
		}

		$types = array();
		foreach ( self::$graph['@graph'] as $node ) {
			foreach ( (array) ( isset( $node['@type'] ) ? $node['@type'] : array() ) as $type ) {
				if ( is_string( $type ) ) {
					$types[] = $type;
				}
			}
		}
		return array_values( array_unique( $types ) );
	}

	// ── Canonical @ids ───────────────────────────────────────────────────────────

	public static function website_id() {
		return home_url( '/#website' );
	}
	public static function organization_id() {
		return home_url( '/#organization' );
	}
	public static function current_url() {
		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}
		if ( is_singular() ) {
			return get_permalink();
		}
		global $wp;
		return home_url( add_query_arg( array(), $wp->request ? trailingslashit( $wp->request ) : '' ) );
	}

	// ── Graph builder ────────────────────────────────────────────────────────────

	/**
	 * Build the unified page graph.
	 *
	 * @return array{'@context':string,'@graph':array}
	 */
	public static function build_graph() {
		// The graph is assembled as an @id-keyed map so that a node contributed by
		// more than one source (e.g. the spine Organization + the Reviews writer's
		// aggregateRating, or the Contact writer's address) is merged into one node
		// rather than duplicated. merge_node() enforces "existing identity wins,
		// new keys fill gaps".
		$nodes = array();

		$org = self::organization_node();
		if ( $org ) {
			self::merge_node( $nodes, $org );
		}
		self::merge_node( $nodes, self::website_node( (bool) $org ) );

		// Centrally-defined entities (features #3/#4): every entity node is emitted
		// globally so that any about/mentions @id reference resolves in-graph.
		$entities_available = class_exists( 'TWTAEO_KG_Entities' );
		if ( $entities_available ) {
			foreach ( TWTAEO_KG_Entities::build_entity_nodes() as $node ) {
				self::merge_node( $nodes, $node );
			}
		}

		// Resolve this URL's mapping once — drives the WebPage about/mentions and
		// the synthesized topic node (feature #2).
		$refs = null;
		if ( $entities_available ) {
			$mapping = TWTAEO_KG_Entities::get_mapping_for_url( self::current_url() );
			if ( $mapping ) {
				$refs = TWTAEO_KG_Entities::refs_for_mapping( $mapping, self::current_url() );
				if ( ! empty( $refs['topic_node'] ) ) {
					self::merge_node( $nodes, $refs['topic_node'] );
				}
			}
		}

		$page = self::webpage_node( (bool) $org, $refs );
		if ( $page ) {
			self::merge_node( $nodes, $page );
		}

		// ── Fold the per-type writers in as nodes of this same @graph ──────────────
		// Article / News / FAQ / Service / Product / Contact / Author / Reviews each
		// return their node(s) via the filter instead of printing a separate block.
		$url     = self::current_url();
		$context = array(
			'post_id'    => is_singular() ? (int) get_the_ID() : 0,
			'url'        => $url,
			'webpage_id' => $page ? $page['@id'] : '',
			'org_id'     => $org ? self::organization_id() : '',
			'has_org'    => (bool) $org,
			'is_front'   => ( is_front_page() || is_home() ),
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- NODE_FILTER is the constant 'twtaeo_kg_nodes', already plugin-prefixed.
		$contributed = apply_filters( self::NODE_FILTER, array(), $context );
		if ( is_array( $contributed ) ) {
			foreach ( $contributed as $node ) {
				self::merge_node( $nodes, $node );
			}
		}

		// Weave the page's primary content entity into the WebPage node.
		if ( $page ) {
			self::link_main_entity( $nodes, $page['@id'], $url );
		}

		// ── Remove anything the merchant switched off or handed over ──────────────
		//
		// Applied last, after link_main_entity() has resolved every reference: that
		// helper writes into $nodes[ $page['@id'] ], so removing the WebPage before it
		// runs would silently drop the mainEntity link on a page that keeps its graph.
		//
		// One call for both cases — a switch on the Schema Conflicts screen and a
		// hand-over to AEO Ultimate for WooCommerce — because a writer honouring only
		// one of them would follow half the merchant's instructions. It never removes
		// a whole graph: anything this plugin publishes that nothing else does
		// (Article, News, Service, Author, PR) is kept unless switched off by name.
		// See TWTAEO_Output_Control::filter_graph() for why the two cases strip
		// references differently.
		$nodes = TWTAEO_Output_Control::filter_graph( $nodes );

		// Product and local schema have no switch of their own here — they belong to
		// modules that already gate them — but they can still be handed over.
		foreach ( array( 'schema_product', 'schema_local' ) as $surface ) {
			$nodes = TWTAEO_Commerce_Handoff::drop_superseded( $nodes, $surface );
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);
	}

	/**
	 * Merge a node into the @id-keyed graph map.
	 *
	 * Identified nodes ( with an @id ) are keyed by that @id: the first writer to
	 * declare it owns its identity and @type; later contributors only fill in keys
	 * that are not already set (so the Reviews writer can add `aggregateRating` to
	 * the spine Organization without overwriting its name or sameAs). Anonymous
	 * nodes are appended untouched.
	 *
	 * @param array $nodes Graph map, passed by reference.
	 * @param mixed $node  A schema node array.
	 */
	private static function merge_node( array &$nodes, $node ) {
		if ( ! is_array( $node ) || ( empty( $node['@type'] ) && empty( $node['@id'] ) ) ) {
			return;
		}
		$id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
		if ( '' === $id ) {
			$nodes[] = $node;
			return;
		}
		if ( ! isset( $nodes[ $id ] ) ) {
			$nodes[ $id ] = $node;
			return;
		}
		foreach ( $node as $key => $value ) {
			if ( '@id' === $key || '@type' === $key ) {
				continue; // Never reassign identity or type of an existing node.
			}
			if ( ! array_key_exists( $key, $nodes[ $id ] ) ) {
				$nodes[ $id ][ $key ] = $value;
			}
		}
	}

	/**
	 * Point the WebPage at its primary content entity via `mainEntity`, and —
	 * where the vocabulary allows it — give that entity an `isPartOf`
	 * back-reference. The first entity present (by the canonical hash on this
	 * URL) in priority order wins.
	 *
	 * `isPartOf` has domain CreativeWork, so it is only valid on the WebPage
	 * subtypes and Article here. Product / ProductGroup / Service are not
	 * CreativeWorks; for those, `mainEntity` alone carries the relationship.
	 *
	 * @param array  $nodes      Graph map, passed by reference.
	 * @param string $webpage_id The WebPage node @id.
	 * @param string $url        The current page URL (anchor for content @ids).
	 */
	private static function link_main_entity( array &$nodes, $webpage_id, $url ) {
		if ( ! isset( $nodes[ $webpage_id ] ) ) {
			return;
		}
		// `#collection` is last on purpose. A Smart Collection rendered by shortcode
		// onto a product page must not become that page's mainEntity ahead of
		// `#product` — an FAQPage addressed `#faqpage` demoted a Product exactly
		// this way once. On a category archive nothing above it exists, so it wins
		// there, which is the only place it should.
		$creative_work = array( '#article', '#faqpage', '#contactpage' );
		foreach ( array( '#article', '#faqpage', '#product-group', '#product', '#service', '#contactpage', '#collection' ) as $suffix ) {
			$id = $url . $suffix;
			if ( ! isset( $nodes[ $id ] ) ) {
				continue;
			}
			if ( empty( $nodes[ $webpage_id ]['mainEntity'] ) ) {
				$nodes[ $webpage_id ]['mainEntity'] = array( '@id' => $id );
			}
			if ( in_array( $suffix, $creative_work, true ) && empty( $nodes[ $id ]['isPartOf'] ) ) {
				$nodes[ $id ]['isPartOf'] = array( '@id' => $webpage_id );
			}
			return;
		}
	}

	/** Organization node from the Company Profile, with sameAs grounding. */
	private static function organization_node() {
		if ( ! class_exists( 'TWTAEO_Company_Profile' ) ) {
			return null;
		}
		$c = TWTAEO_Company_Profile::get();
		if ( empty( $c['name'] ) ) {
			return null;
		}

		// Type as OnlineStore (a subtype of Organization) when WooCommerce is
		// active, so crawlers and AI read the entity as a store, not a generic org.
		$org_type = class_exists( 'WooCommerce' ) ? array( 'Organization', 'OnlineStore' ) : 'Organization';

		$node = array(
			'@type' => $org_type,
			'@id'   => self::organization_id(),
			'name'  => $c['name'],
			'url'   => ! empty( $c['url'] ) ? $c['url'] : home_url( '/' ),
		);
		if ( ! empty( $c['legal_name'] ) ) {
			$node['legalName'] = $c['legal_name'];
		}
		if ( ! empty( $c['description'] ) ) {
			$node['description'] = $c['description'];
		}
		if ( ! empty( $c['founding_year'] ) ) {
			$node['foundingDate'] = $c['founding_year'];
		}
		if ( ! empty( $c['email'] ) && self::is_public_email( $c['email'] ) ) {
			$node['email'] = $c['email'];
		}
		if ( ! empty( $c['phone'] ) ) {
			$node['telephone'] = $c['phone'];
		}
		if ( ! empty( $c['logo_url'] ) ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'@id'   => home_url( '/#logo' ),
				'url'   => $c['logo_url'],
			);
			$node['image'] = array( '@id' => home_url( '/#logo' ) );
		}
		$same_as = TWTAEO_Company_Profile::get_same_as( $c );
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = array_values( $same_as );
		}
		return $node;
	}

	/**
	 * Whether an email is safe to publish in schema — filters out the WordPress
	 * dev/admin defaults and non-routable local/test domains so a placeholder
	 * like dev-email@wpengine.local never ships as a public contact point.
	 *
	 * Public so that scores judge an email by the same rule that decides whether
	 * it ships — a placeholder must never count as a contact signal.
	 *
	 * @param string $email
	 * @return bool
	 */
	public static function is_public_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$domain = substr( strrchr( $email, '@' ), 1 );
		foreach ( array( '.local', '.test', '.invalid', '.example', 'example.com', 'example.org', 'wpengine.local' ) as $bad ) {
			if ( $domain === $bad || substr( $domain, -strlen( $bad ) ) === $bad ) {
				return false;
			}
		}
		return true;
	}

	/** WebSite node, publisher pointing to the Organization. */
	private static function website_node( $has_org ) {
		$node = array(
			'@type'      => 'WebSite',
			'@id'        => self::website_id(),
			'url'        => home_url( '/' ),
			'name'       => get_bloginfo( 'name' ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		$tagline = get_bloginfo( 'description' );
		if ( $tagline ) {
			$node['description'] = $tagline;
		}
		if ( $has_org ) {
			$node['publisher'] = array( '@id' => self::organization_id() );
		}
		$node['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		);
		return $node;
	}

	/**
	 * WebPage node tying the current page into the graph.
	 *
	 * @param bool       $has_org True when an Organization node is in the graph.
	 * @param array|null $refs    Resolved about/mentions refs for this URL, from
	 *                            TWTAEO_KG_Entities::refs_for_mapping(), or null.
	 */
	private static function webpage_node( $has_org, $refs = null ) {
		// Archives are spine-only, with one exception: a product category the
		// merchant has mapped to a Smart Collection. That archive is publishing a
		// CollectionPage with an ItemList inside it, and an ItemList with no page
		// node to hang off is an orphan — so the collection is what earns the
		// archive a WebPage, and nothing else does.
		$is_collection_archive = self::is_collection_archive();

		if ( ! ( is_singular() || is_front_page() || is_home() || $is_collection_archive ) ) {
			return null; // Other archives/search: spine only for now.
		}
		$url = self::current_url();

		// On multilingual sites the page's own language, not the site default —
		// a translated page claiming the default locale misdescribes itself to
		// every consumer. Site-wide locale remains the single-language answer.
		$in_language = ( is_singular() && class_exists( 'TWTAEO_Multilingual' ) )
			? TWTAEO_Multilingual::post_language( get_the_ID() )
			: get_bloginfo( 'language' );

		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' ),
			'isPartOf'   => array( '@id' => self::website_id() ),
			'inLanguage' => $in_language,
		);

		if ( is_front_page() || is_home() || $is_collection_archive ) {
			$node['@type'] = array( 'WebPage', 'CollectionPage' );
		}

		// A central mapping (features #2/#3) sets the page's primary subject and
		// the entities it mentions. Absent a mapping, the page defaults to being
		// about the Organization so the spine still declares a subject.
		$about    = ! empty( $refs['about'] ) ? $refs['about'] : array();
		$mentions = ! empty( $refs['mentions'] ) ? $refs['mentions'] : array();

		if ( ! empty( $about ) ) {
			$node['about'] = ( count( $about ) === 1 ) ? $about[0] : $about;
		} elseif ( $has_org ) {
			$node['about'] = array( '@id' => self::organization_id() );
		}

		if ( ! empty( $mentions ) ) {
			$node['mentions'] = $mentions;
		}

		if ( is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$node['datePublished'] = get_post_time( 'c', true, $post );
				$node['dateModified']  = get_post_modified_time( 'c', true, $post );
			}
		}
		return $node;
	}

	/**
	 * Whether this request is a product category archive the merchant has mapped
	 * to a Smart Collection.
	 *
	 * ⚠ This delegates to TWTAEO_Collection_Schema_Writer::current_collection()
	 * rather than re-deriving the answer, and that is not tidiness — it is a bug
	 * fix. Typing the WebPage `CollectionPage` here and emitting the ItemList over
	 * there are two halves of one claim, so they must be decided once. When they
	 * were decided separately, coming-soon mode (on by default for every new
	 * WooCommerce store) suppressed the ItemList while this still typed the page a
	 * CollectionPage — declaring a page that lists products over a holding screen
	 * that lists nothing.
	 *
	 * Reads the queried object rather than is_product_category(), because
	 * conditional tags are unreliable during head rendering in block themes.
	 *
	 * @return bool
	 */
	private static function is_collection_archive() {
		if ( ! class_exists( 'TWTAEO_Collection_Schema_Writer' ) ) {
			return false;
		}
		$queried = get_queried_object();
		if ( ! ( $queried instanceof WP_Term ) || 'product_cat' !== $queried->taxonomy ) {
			return false;
		}
		return (bool) TWTAEO_Collection_Schema_Writer::current_collection();
	}

	// ── Output ───────────────────────────────────────────────────────────────────

	public static function output() {
		if ( is_admin() ) {
			return;
		}
		// Only emit when this page folds (2+ blocks). Otherwise stay silent and let
		// the individual writers output their own blocks as they always have.
		if ( ! self::is_folding() ) {
			return;
		}
		$graph = self::$graph;
		if ( empty( $graph['@graph'] ) ) {
			return;
		}
		echo "\n<!-- TWT AEO Knowledge Graph -->\n";
		echo '<script type="application/ld+json">' . "\n";
		// JSON-LD is HTML-inert via the JSON_HEX_* flags (see Company writer note).
		//
		// Deliberately NOT pretty-printed: this payload is machine-read, and the
		// indentation roughly doubles it — measured 59.3 KB pretty vs 28.7 KB
		// compact on a 12-variant product.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-inert JSON-LD.
		echo wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		echo "\n</script>\n";
	}
}
