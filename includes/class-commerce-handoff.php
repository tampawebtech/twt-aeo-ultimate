<?php
/**
 * Handing a surface to AEO Ultimate for WooCommerce.
 *
 * ── What this is ─────────────────────────────────────────────────────────────
 *
 * AEO Ultimate for WooCommerce is a commerce-focused plugin forked from this one.
 * Because it is a fork, the two do not merely overlap — they publish **the same
 * `@id`s**: both address the site identity at `home_url( '/#organization' )` and
 * `home_url( '/#website' )`, both address the page at `{url}#webpage`, and both
 * attach their graph to `wp_head` at priority 1. Run together, a store hands search
 * engines two documents describing one entity, plus two `/llms.txt` writers, two
 * `/sitemap.xml` writers and two BreadcrumbLists.
 *
 * This class lets that be resolved *by the merchant*, in one place, instead of by
 * whichever plugin happened to register a rewrite rule first.
 *
 * ── It is inert unless three things are all true ─────────────────────────────
 *
 * 1. AEO Ultimate for WooCommerce is installed **and has actually booted** (it
 *    requires WooCommerce and does nothing without it);
 * 2. the merchant chose it as the owner of that specific surface; and
 * 3. the merchant ticked its confirmation box authorising the suppression.
 *
 * All three live on that side and are read through `aeowc_claims_surface()`. When
 * the function is absent — which is the case on every install that does not have
 * that plugin — `stands_down()` returns false and nothing here changes. There is no
 * setting, no option and no admin screen in this plugin for any of it; a merchant
 * running this plugin alone cannot reach any of this code.
 *
 * ⚠️ **Timing.** Every caller runs on `init` or later, while the commerce plugin
 * defines these functions during its `plugins_loaded` bootstrap. `function_exists()`
 * is therefore a settled answer by the time we ask, not a race.
 *
 * ── Schema is dropped per node, never per graph ──────────────────────────────
 *
 * `drop_superseded()` removes a node only when the other plugin publishes one at
 * **that exact `@id`** on the same request. That is what keeps the rest of this
 * plugin's graph valid: Article, Service, FAQ and Reviews nodes reference the
 * WebPage by `@id`, and those references keep resolving because the replacement
 * carries the same identifier. Consumers merge every JSON-LD block in a document,
 * so two scripts are read as one graph.
 *
 * Never widen this to "drop the graph when the commerce plugin is active". This
 * plugin publishes Article, Service, News, Author and PR nodes that it does not,
 * and those would vanish with nothing to replace them.
 *
 * @package TWT_AEO_Ultimate
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Commerce_Handoff {

	/**
	 * Whether the commerce plugin is present, booted, and has been handed a surface.
	 *
	 * @param string $surface Surface key in the commerce plugin's vocabulary:
	 *                        schema_identity, schema_breadcrumb, schema_product,
	 *                        schema_local, meta_description, llms_txt, sitemap.
	 * @return bool
	 */
	public static function stands_down( $surface ) {
		if ( ! function_exists( 'aeowc_claims_surface' ) ) {
			return false;
		}
		return (bool) aeowc_claims_surface( $surface );
	}

	/**
	 * Whether the commerce plugin publishes a node at this exact `@id` right now.
	 *
	 * @param string $id Absolute `@id` including fragment.
	 * @return bool
	 */
	public static function superseded( $id ) {
		if ( ! function_exists( 'aeowc_publishes_id' ) ) {
			return false;
		}
		return (bool) aeowc_publishes_id( $id );
	}

	/**
	 * The `@type`s each surface covers.
	 *
	 * 🛑 **A matching `@id` alone is not enough to drop a node, and this is why.**
	 * The commerce plugin publishes `#product`, `#breadcrumb` and a local `Store` at
	 * `@id`s this plugin also uses. Without a type bound, a merchant who handed over
	 * *site identity* would silently lose their Product node too — a surface they
	 * were never asked about. Both tests must pass: the right kind of node, and a
	 * confirmed replacement for that exact identifier.
	 *
	 * WebPage subtypes are listed explicitly; a node declaring `ItemPage` is a
	 * WebPage, and matching only the base term would leave it behind.
	 */
	const TYPES = array(
		'schema_identity'   => array(
			'Organization',
			'OnlineStore',
			'WebSite',
			'WebPage',
			'ItemPage',
			'CollectionPage',
			'ProfilePage',
			'AboutPage',
			'ContactPage',
			'SearchResultsPage',
		),
		'schema_breadcrumb' => array( 'BreadcrumbList' ),
		'schema_product'    => array( 'Product', 'ProductGroup', 'IndividualProduct', 'SomeProducts' ),
		'schema_local'      => array( 'LocalBusiness', 'Store' ),
	);

	/**
	 * Whether the commerce plugin publishes a node of any of these types right now.
	 *
	 * For this plugin's **anonymous** blocks — the Local Pack schema carries no
	 * `@id`, so there is no identifier to match and a replacement can only be
	 * confirmed by kind. Weaker than `superseded()`; prefer that wherever an `@id`
	 * exists.
	 *
	 * @param string[]|string $types One or more schema.org types.
	 * @return bool
	 */
	public static function superseded_type( $types ) {
		if ( ! function_exists( 'aeowc_publishes_type' ) ) {
			return false;
		}
		return (bool) aeowc_publishes_type( $types );
	}

	/**
	 * Remove nodes the commerce plugin is republishing at the same `@id`.
	 *
	 * Anything it does not republish stays, including every anonymous node — a node
	 * with no `@id` cannot be matched to a replacement, so it can never be shown to
	 * be redundant and is always kept.
	 *
	 * @param array  $nodes   Graph nodes, `@id`-keyed or a plain list.
	 * @param string $surface The surface being handed over.
	 * @return array
	 */
	public static function drop_superseded( array $nodes, $surface ) {
		if ( empty( self::TYPES[ $surface ] ) || ! self::stands_down( $surface ) ) {
			return $nodes;
		}

		$covered = self::TYPES[ $surface ];

		foreach ( $nodes as $key => $node ) {
			if ( empty( $node['@id'] ) || empty( $node['@type'] ) ) {
				continue;
			}
			if ( ! array_intersect( (array) $node['@type'], $covered ) ) {
				continue;
			}
			if ( self::superseded( $node['@id'] ) ) {
				unset( $nodes[ $key ] );
			}
		}

		return $nodes;
	}
}
