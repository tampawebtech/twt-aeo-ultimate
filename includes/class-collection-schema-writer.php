<?php
/**
 * Smart Collections — schema output and take-over.
 *
 * Publishes a mapped collection as a CollectionPage carrying an ItemList, either
 * on the product category archive it is mapped to or wherever the merchant places
 * the shortcode.
 *
 * ── Why this class takes over instead of contributing ────────────────────────
 *
 * Every SEO plugin already emits *something* on a term archive — Yoast and Rank
 * Math both publish a CollectionPage, usually with nothing inside it but the
 * term name. That node is not wrong, it is just empty: it says a page listing
 * things exists, and nothing about what the things are or who they are for.
 *
 * Replacing it is the point of the module, so this is a take-over surface and it
 * follows the pattern the rest of the plugin uses for those (see
 * TWTAEO_Local_Schema_Writer): opt-in and off by default, competitors detected and
 * named in the UI, and — when the merchant switches take-over on — their node
 * actually suppressed rather than ours merely added alongside. Two CollectionPage
 * nodes on one archive is worse than either one alone, and it is the single most
 * likely thing to fail a Marketplace review.
 *
 * ── The @id fragment is load-bearing ─────────────────────────────────────────
 *
 * The node is addressed `#collection`, and `#collection` is registered **last**
 * in TWTAEO_Knowledge_Graph::link_main_entity()'s priority walk. That ordering is
 * deliberate: a shortcode collection dropped onto a product page must never
 * become that page's mainEntity ahead of `#product`. An FAQPage addressed
 * `#faqpage` demoted a Product exactly this way once already.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Collection_Schema_Writer {

	const SHORTCODE = 'twtaeo_smart_collection';

	/** Resolved collection for this request; false until computed. */
	private static $current = false;

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );

		// Priority 30 — after the WooCommerce detector (20) and the Local writer
		// (25), so the ItemList lands in a graph whose product nodes already exist.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 30, 2 );

		// Standalone block for pages whose graph does not fold. A category archive
		// on a store with no Company Profile carries only this one real node, which
		// is below TWTAEO_Knowledge_Graph::FOLD_THRESHOLD — without this the
		// collection would silently publish nothing on exactly those stores.
		add_action( 'wp_head', array( __CLASS__, 'output_standalone' ), 11 );

		if ( self::takeover_active() ) {
			self::register_suppression();
		}
	}

	// ── Ownership ─────────────────────────────────────────────────────────────

	/**
	 * Other plugins that publish collection schema on term archives.
	 *
	 * Detected by their own constants and settings, exactly as
	 * TWTAEO_Local_Schema_Writer::competing_owners() does — never by guessing from
	 * rendered output.
	 *
	 * @return array<string,array{label:string,suppressible:bool}>
	 */
	public static function competing_owners() {
		$owners = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$owners['yoast'] = array(
				'label'        => __( 'Yoast SEO', 'twt-aeo-ultimate' ),
				'suppressible' => true,
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$owners['rank-math'] = array(
				'label'        => __( 'Rank Math', 'twt-aeo-ultimate' ),
				'suppressible' => true,
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$owners['aioseo'] = array(
				'label'        => __( 'All in One SEO', 'twt-aeo-ultimate' ),
				'suppressible' => true,
			);
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			// SEOPress exposes no filter over its archive graph that we can rely on
			// across versions, so this one has to be switched off in SEOPress itself.
			// Saying so is better than suppressing it unreliably and leaving the
			// merchant with an intermittent duplicate.
			$owners['seopress'] = array(
				'label'        => __( 'SEOPress', 'twt-aeo-ultimate' ),
				'suppressible' => false,
			);
		}

		return $owners;
	}

	/** Competitors we cannot switch off from here. */
	public static function unsuppressable_owners() {
		return array_filter(
			self::competing_owners(),
			static function ( $o ) {
				return empty( $o['suppressible'] );
			}
		);
	}

	/** True when the merchant has handed this surface to us. */
	public static function takeover_active() {
		if ( ! TWTAEO_Smart_Collections::is_publishing() ) {
			return false;
		}
		$s = TWTAEO_Smart_Collections::settings();
		return ! empty( $s['takeover'] );
	}

	/**
	 * Strip other plugins' CollectionPage / ItemList nodes on pages where we own
	 * the collection. Only on those pages — a term archive with no collection
	 * mapped to it is still entirely theirs.
	 */
	private static function register_suppression() {
		add_filter(
			'wpseo_schema_graph',
			static function ( $graph ) {
				if ( ! self::current_collection() || ! is_array( $graph ) ) {
					return $graph;
				}
				return array_values(
					array_filter(
						$graph,
						static function ( $piece ) {
							return ! self::is_collection_node( $piece );
						}
					)
				);
			},
			99
		);

		add_filter(
			'rank_math/json_ld',
			static function ( $data ) {
				if ( ! self::current_collection() || ! is_array( $data ) ) {
					return $data;
				}
				foreach ( $data as $key => $piece ) {
					if ( self::is_collection_node( $piece ) ) {
						unset( $data[ $key ] );
					}
				}
				return $data;
			},
			99
		);

		add_filter(
			'aioseo_schema_output',
			static function ( $graphs ) {
				if ( ! self::current_collection() || ! is_array( $graphs ) ) {
					return $graphs;
				}
				return array_values(
					array_filter(
						$graphs,
						static function ( $piece ) {
							return ! self::is_collection_node( $piece );
						}
					)
				);
			},
			99
		);
	}

	/** Whether a foreign schema fragment is the node we are taking over. */
	private static function is_collection_node( $piece ) {
		if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
			return false;
		}
		$types = (array) $piece['@type'];
		return (bool) array_intersect( $types, array( 'CollectionPage', 'ItemList' ) );
	}

	// ── Resolving the collection for this request ─────────────────────────────

	/**
	 * The collection this request should publish, or null.
	 *
	 * Two ways in: the request is a product category archive mapped to a
	 * collection, or the singular post being viewed contains the shortcode.
	 *
	 * get_queried_object() rather than is_product_category(), because conditional
	 * tags are unreliable during head rendering in block themes — the same reason
	 * the WooCommerce detector reads the queried object.
	 *
	 * @return array|null
	 */
	public static function current_collection() {
		if ( false !== self::$current ) {
			return self::$current;
		}
		self::$current = null;

		if ( is_admin() || ! TWTAEO_Smart_Collections::is_publishing() ) {
			return null;
		}

		// Coming-soon mode still runs wp_head while the template never renders, so
		// schema can outlive the body it describes. A CollectionPage over a holding
		// screen is a structured-data policy violation, not a cosmetic mismatch.
		if ( class_exists( 'TWTAEO_Store_Location' ) && TWTAEO_Store_Location::output_suppressed() ) {
			return null;
		}

		$queried = get_queried_object();

		if ( $queried instanceof WP_Term && 'product_cat' === $queried->taxonomy ) {
			self::$current = TWTAEO_Smart_Collections::get_for_term( $queried->term_id );
			return self::$current;
		}

		if ( $queried instanceof WP_Post ) {
			self::$current = self::collection_in_content( $queried );
		}

		return self::$current;
	}

	/**
	 * The collection referenced by a shortcode in a post's content.
	 *
	 * Read at head time from the stored content, because the shortcode itself does
	 * not run until `the_content` — long after wp_head. Reading the content is also
	 * what makes the output safe: the schema is only emitted because the body
	 * demonstrably contains the thing it describes.
	 *
	 * @param WP_Post $post
	 * @return array|null
	 */
	private static function collection_in_content( WP_Post $post ) {
		if ( ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return null;
		}

		$pattern = get_shortcode_regex( array( self::SHORTCODE ) );
		if ( ! preg_match_all( '/' . $pattern . '/', $post->post_content, $matches ) ) {
			return null;
		}

		foreach ( (array) $matches[3] as $attr_string ) {
			$attrs = shortcode_parse_atts( $attr_string );
			$id    = is_array( $attrs ) && ! empty( $attrs['id'] ) ? sanitize_key( $attrs['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$collection = TWTAEO_Smart_Collections::get( $id );
			// One collection per page. A page carrying two would need two
			// CollectionPage nodes and only one can be the page's mainEntity, so
			// the first wins and the rest render visibly without schema.
			if ( $collection && ! empty( $collection['active'] ) ) {
				return $collection;
			}
		}

		return null;
	}

	// ── Nodes ─────────────────────────────────────────────────────────────────

	/**
	 * Contribute the ItemList to the unified @graph, and the collection's summary
	 * to the WebPage node.
	 *
	 * The summary is contributed as the WebPage's `description` rather than set
	 * directly: merge_node() only fills keys that are not already present, so a
	 * description written by something with a better claim to it stays put.
	 *
	 * @param array $nodes   Nodes contributed so far.
	 * @param array $context Graph context.
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		$collection = self::current_collection();
		if ( ! $collection ) {
			return $nodes;
		}

		$url  = isset( $context['url'] ) ? $context['url'] : '';
		$node = self::build_item_list( $collection, $url );
		if ( ! $node ) {
			return $nodes;
		}

		$nodes[] = $node;

		$summary = TWTAEO_Smart_Collections::effective_summary( $collection );
		if ( '' !== $summary && ! empty( $context['webpage_id'] ) ) {
			$nodes[] = array(
				'@id'         => $context['webpage_id'],
				'description' => $summary,
			);
		}

		return $nodes;
	}

	/**
	 * Build the ItemList node.
	 *
	 * Items are ListItem references carrying a URL and a name — **not** nested
	 * Product entities. A category listing 24 products would otherwise duplicate
	 * 24 full Product nodes that already exist in the graph of their own pages,
	 * which is precisely the entity duplication the @graph is built to prevent.
	 *
	 * @param array  $collection
	 * @param string $url Canonical URL of the page carrying the collection.
	 * @return array|null
	 */
	public static function build_item_list( array $collection, $url ) {
		if ( '' === $url ) {
			return null;
		}

		$facts = TWTAEO_Smart_Collections::resolve_items( $collection );
		if ( 0 === $facts['count'] ) {
			return null; // Never publish an empty list.
		}

		$elements = array();
		$position = 1;
		foreach ( $facts['items'] as $item ) {
			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $item['url'],
				'name'     => $item['name'],
			);
			$position++;
		}

		$node = array(
			'@type'           => 'ItemList',
			'@id'             => $url . '#collection',
			'name'            => html_entity_decode( $collection['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'numberOfItems'   => $facts['count'],
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'itemListElement' => $elements,
		);

		$summary = TWTAEO_Smart_Collections::effective_summary( $collection );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}

		return $node;
	}

	/**
	 * Emit the collection as its own block when the page graph did not fold.
	 *
	 * Mirrors what the other writers do. The node is identical either way, so a
	 * consumer sees the same data whichever path ran.
	 */
	public static function output_standalone() {
		if ( ! class_exists( 'TWTAEO_Knowledge_Graph' ) || TWTAEO_Knowledge_Graph::is_folding() ) {
			return;
		}

		$collection = self::current_collection();
		if ( ! $collection ) {
			return;
		}

		$url  = TWTAEO_Knowledge_Graph::current_url();
		$list = self::build_item_list( $collection, $url );
		if ( ! $list ) {
			return;
		}

		$page = array(
			'@type'      => 'CollectionPage',
			'@id'        => $url . '#collectionpage',
			'url'        => $url,
			'name'       => html_entity_decode( wp_get_document_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'mainEntity' => array( '@id' => $list['@id'] ),
		);

		$summary = TWTAEO_Smart_Collections::effective_summary( $collection );
		if ( '' !== $summary ) {
			$page['description'] = $summary;
		}

		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $page, $list ),
		);

		echo "\n<!-- AEO Ultimate: Smart Collection -->\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	/**
	 * Render a collection as visible content.
	 *
	 * The visible list is not decoration. Marked-up content with no rendered
	 * counterpart is a structured-data policy violation, so the shortcode is what
	 * earns the schema its right to exist on a page that is not a category archive.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'title' => 'yes',
			),
			$atts,
			self::SHORTCODE
		);

		$id         = sanitize_key( $atts['id'] );
		$collection = $id ? TWTAEO_Smart_Collections::get( $id ) : null;

		if ( ! $collection || empty( $collection['active'] ) ) {
			return '';
		}
		if ( class_exists( 'TWTAEO_Store_Location' ) && TWTAEO_Store_Location::output_suppressed() ) {
			return '';
		}

		$facts = TWTAEO_Smart_Collections::resolve_items( $collection );
		if ( 0 === $facts['count'] ) {
			return '';
		}

		$summary = TWTAEO_Smart_Collections::effective_summary( $collection );

		ob_start();
		?>
		<div class="twt-aeo-collection" id="twt-aeo-collection-<?php echo esc_attr( $collection['id'] ); ?>">
			<?php if ( 'no' !== $atts['title'] ) : ?>
				<h2 class="twt-aeo-collection__title"><?php echo esc_html( $collection['title'] ); ?></h2>
			<?php endif; ?>

			<?php if ( '' !== $summary ) : ?>
				<p class="twt-aeo-collection__summary"><?php echo esc_html( $summary ); ?></p>
			<?php endif; ?>

			<ul class="twt-aeo-collection__items">
				<?php foreach ( $facts['items'] as $item ) : ?>
					<li class="twt-aeo-collection__item">
						<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
						<?php if ( $item['price'] > 0 && function_exists( 'wc_price' ) ) : ?>
							<span class="twt-aeo-collection__price"><?php echo wp_kses_post( wc_price( $item['price'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( 'outofstock' === $item['stock'] ) : ?>
							<span class="twt-aeo-collection__stock"><?php esc_html_e( '(out of stock)', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}
}
