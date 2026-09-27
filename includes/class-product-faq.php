<?php
/**
 * Product FAQ assembler — one FAQPage node per product page.
 *
 * ── Why this class exists ─────────────────────────────────────────────────────
 * Three separate things can answer questions on a product page: the FAQs the
 * merchant wrote or approved in the product metabox, the geo Q&As the Local
 * Store module computes, and (from here on) whatever else contributes through
 * the `twtaeo_product_faq_pairs` filter. Before this class, two of them emitted
 * their own FAQPage node with different `@id` fragments — `#faqpage` from the
 * stored product FAQs and `#local-faq` from the Local Store module. The graph
 * keys by top-level `@id`, so both survived and the page published **two**
 * FAQPage nodes. Google expects one per page.
 *
 * So sources no longer emit. They contribute pairs; this class assembles them,
 * de-duplicates, and emits exactly one node.
 *
 * ── Why the fragment is #product-faq and not #faqpage ─────────────────────────
 * TWTAEO_Knowledge_Graph::link_main_entity() resolves the page's primary entity
 * by walking `#article`, `#faqpage`, `#product-group`, `#product`, … in order.
 * A node addressed `#faqpage` therefore *outranks the Product* and the WebPage's
 * `mainEntity` ends up pointing at the FAQ rather than at the thing for sale —
 * which is what the stored product FAQs were doing. A product page is a product
 * page that happens to answer questions. The Local Store module worked this out
 * first (see TWTAEO_Local_Storefront::kg_nodes()); this follows its lead.
 *
 * ── Visibility is a policy requirement, not a preference ──────────────────────
 * Marked-up FAQ content that no visitor can see is a structured-data policy
 * violation. The stored product FAQs render through a WooCommerce product tab,
 * which is body output — and `wp_head` runs whether or not the body does. With
 * WooCommerce "Coming soon" mode on (the default for every new store) the tab
 * never renders while the schema still ships. Every emit path here goes through
 * output_visible().
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Product_FAQ {

	/**
	 * `@id` fragment for the single assembled node. Deliberately not `#faqpage`
	 * — see the class header.
	 */
	const NODE_FRAGMENT = '#product-faq';

	public static function register_hooks() {
		// After TWTAEO_Custom_Schema_Writer (10) and TWTAEO_Local_Storefront (26),
		// both of which now stand down on product pages so this is the only
		// FAQPage contributor here.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 27, 2 );

		// Standalone fallback for pages that do not fold into the unified graph.
		add_action( 'wp_head', array( __CLASS__, 'output' ), 25 );
	}

	// ── Gates ─────────────────────────────────────────────────────────────────

	/**
	 * Whether this request is a single product page.
	 *
	 * Uses get_queried_object() rather than is_product(): in block themes
	 * is_product() is unreliable during head rendering, which is exactly when
	 * the graph is built.
	 *
	 * @return bool
	 */
	public static function is_product_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$object = get_queried_object();
		return ( $object instanceof WP_Post ) && 'product' === $object->post_type;
	}

	/**
	 * Whether FAQ markup may be published on this request.
	 *
	 * The one case that actually ships is WooCommerce "Coming soon" mode, where
	 * `wp_head` runs and the product template does not. TWTAEO_Store_Location
	 * owns that decision for the whole plugin; it exempts store managers and
	 * admin-side callers, who are looking at the real page.
	 *
	 * NOTE ON THE REMAINING ASSUMPTION: the merchant-authored FAQs render in a
	 * `woocommerce_product_tabs` tab, so this class assumes the theme calls
	 * woocommerce_output_product_data_tabs() — true of every standard theme and
	 * of the WooCommerce templates, but not something that can be verified at
	 * `wp_head` time, since the body has not run yet. The Local Store module
	 * hit the same wall and answered it with an explicit placement setting. If
	 * a theme is found that drops the tabs, that is the fix to copy.
	 *
	 * @return bool
	 */
	public static function output_visible() {
		if ( ! class_exists( 'TWTAEO_Store_Location' ) ) {
			return true;
		}
		return ! TWTAEO_Store_Location::output_suppressed();
	}

	// ── Collection ────────────────────────────────────────────────────────────

	/**
	 * Assemble the de-duplicated Q&A list for a product.
	 *
	 * Order is priority order: whatever the merchant wrote or approved wins over
	 * anything generated, because a merchant edit is a decision and a generated
	 * answer is a default.
	 *
	 * No cap is applied. A cap here would silently drop answers the page is
	 * visibly rendering, which would put the schema and the page out of step —
	 * the exact mismatch this class exists to prevent. If the computed generator
	 * later produces enough pairs to need a limit, the limit belongs where the
	 * pairs are generated and it must be surfaced to the merchant.
	 *
	 * @param int $product_id
	 * @return array List of array{question:string,answer:string}.
	 */
	public static function collect( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return array();
		}

		$pairs = array();

		// 1. Merchant-authored / approved, from the product metabox.
		if ( class_exists( 'TWTAEO_Product_FAQ_Generator' ) ) {
			$pairs = array_merge( $pairs, TWTAEO_Product_FAQ_Generator::get_faqs( $product_id ) );
		}

		// 2. Geo Q&As, only when the Local Store module is actually rendering
		//    them on this same page. faq_is_visible() carries that decision,
		//    including its own coming-soon check.
		if ( class_exists( 'TWTAEO_Local_FAQ' ) && class_exists( 'TWTAEO_Local_Storefront' )
			&& TWTAEO_Local_Storefront::faq_is_visible() ) {
			$pairs = array_merge( $pairs, TWTAEO_Local_FAQ::build( $product_id ) );
		}

		/**
		 * Filter the assembled product Q&A pairs before de-duplication.
		 *
		 * Contribute here rather than emitting a FAQPage node directly, so the
		 * page keeps carrying exactly one. Anything added must also be rendered
		 * visibly on the page.
		 *
		 * @param array $pairs      List of array{question:string,answer:string}.
		 * @param int   $product_id
		 */
		$pairs = apply_filters( 'twtaeo_product_faq_pairs', $pairs, $product_id );

		return self::dedupe( is_array( $pairs ) ? $pairs : array() );
	}

	/**
	 * Drop blank rows and repeated questions, keeping the first of each.
	 *
	 * A merchant who writes "Can I collect this in store?" and a Local Store
	 * module that computes the same question should not put it on the page
	 * twice, so questions are compared on their words alone — case, punctuation
	 * and entity encoding removed.
	 *
	 * @param array $pairs
	 * @return array
	 */
	private static function dedupe( array $pairs ) {
		$seen  = array();
		$clean = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$question = trim( (string) ( $pair['question'] ?? '' ) );
			$answer   = trim( (string) ( $pair['answer'] ?? '' ) );
			if ( '' === $question || '' === $answer ) {
				continue;
			}
			$key = self::normalize( $question );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $clean;
	}

	/**
	 * Reduce a question to its comparable words.
	 *
	 * @param string $question
	 * @return string
	 */
	private static function normalize( $question ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $question ), ENT_QUOTES, 'UTF-8' );
		$text = strtolower( $text );
		// Keep letters, digits and whitespace; everything else is punctuation
		// noise for comparison purposes. The `u` flag keeps non-ASCII words
		// intact so accented questions still compare correctly.
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	// ── Schema ────────────────────────────────────────────────────────────────

	/**
	 * Build the single FAQPage node, or null when there is nothing to publish.
	 *
	 * @param int    $product_id
	 * @param string $url Canonical URL of the page the FAQs are on.
	 * @return array|null
	 */
	public static function faq_node( $product_id, $url ) {
		$pairs = self::collect( $product_id );
		if ( empty( $pairs ) ) {
			return null;
		}

		$entities = array();
		foreach ( $pairs as $pair ) {
			// WordPress and WooCommerce hand back HTML entities; JSON-LD wants
			// the characters themselves.
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => html_entity_decode( $pair['question'], ENT_QUOTES, 'UTF-8' ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => html_entity_decode( wp_strip_all_tags( $pair['answer'] ), ENT_QUOTES, 'UTF-8' ),
				),
			);
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . self::NODE_FRAGMENT,
			'mainEntity' => $entities,
		);
	}

	/**
	 * Contribute the node to the unified @graph.
	 *
	 * @param array $nodes
	 * @param array $context
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		if ( ! self::is_product_page() || ! self::output_visible() ) {
			return $nodes;
		}

		$url  = ! empty( $context['url'] ) ? $context['url'] : get_permalink();
		$node = self::faq_node( (int) ( $context['post_id'] ?? 0 ), $url );
		if ( $node ) {
			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Emit the node on its own for pages that do not fold into the unified
	 * graph. When the graph folds, kg_nodes() has already contributed it.
	 */
	public static function output() {
		if ( is_admin() ) {
			return;
		}
		if ( class_exists( 'TWTAEO_Knowledge_Graph' ) && TWTAEO_Knowledge_Graph::is_folding() ) {
			return;
		}
		if ( ! self::is_product_page() || ! self::output_visible() ) {
			return;
		}

		$node = self::faq_node( (int) get_the_ID(), get_permalink() );
		if ( ! $node ) {
			return;
		}
		$node = array_merge( array( '@context' => 'https://schema.org' ), $node );

		// JSON-LD output. esc_html() would corrupt the JSON, so safety comes from
		// the HEX_* flags: <, >, &, ' and " are all encoded as \uXXXX, so the value
		// cannot break out of the script element or carry HTML/JS into the page.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-inert JSON-LD; see note above.
		echo "\n<!-- AEO Ultimate Product FAQ -->\n"
			. '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			. "\n" . '</script>' . "\n";
	}
}
