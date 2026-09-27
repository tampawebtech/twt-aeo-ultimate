<?php
/**
 * Output Control — switching off anything this plugin writes that another plugin
 * is already writing.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * Most of what this plugin publishes could be switched off: llms.txt and the
 * semantic breadcrumb have settings, the Organization block has
 * `output_org_schema`, and the sitemap generator, Local Pack and Reviews each sit
 * behind a module.
 *
 * ⚠️ **Seven things did not.** Read against `TWTAEO_Plugin::run()`, the Knowledge
 * Graph spine, the Article writer, the Service writer, the Contact writer, the AI
 * meta description, the `/sitemap.xml` endpoint inside AI-Ready and the AI crawler
 * logger all register **outside every module check**. A merchant running Yoast or
 * Rank Math alongside this plugin therefore had two Organizations, two WebPages and
 * two meta descriptions on every page and **no setting anywhere to stop it** — the
 * only lever was deactivating the plugin. That is what this class fixes, and it is
 * a general fix: it applies to any competing plugin, not only to AEO Ultimate for
 * WooCommerce.
 *
 * ── Three rules ──────────────────────────────────────────────────────────────
 *
 * 1. **Everything defaults to on.** An absent key means "write it", so updating to
 *    2.13.0 changes the output of **no** existing install. Switching something off
 *    is always a merchant's deliberate act. Detecting a conflict and silently
 *    standing down would remove schema from stores that were working, on an
 *    automatic update, which is a far worse failure than a duplicate.
 *
 * 2. 🛑 **This class owns no setting that already has an owner.** `llms.txt` and the
 *    semantic breadcrumb already store the merchant's answer in `twtaeo_ai_ready`,
 *    so those keys `delegate` to it and are read, never copied. A second flag that
 *    can disagree with the first is the bug this shape exists to prevent — the
 *    merchant would switch one off and the other would keep publishing.
 *
 * 3. **`should_write()` is the only question a writer asks.** It folds the
 *    merchant's switch together with the `TWTAEO_Commerce_Handoff` hand-over, so
 *    there is exactly one answer rather than two conditions each writer has to
 *    remember to check in the right order.
 *
 * ── What it deliberately does not do ─────────────────────────────────────────
 *
 * It never switches anything off by itself, and it never recommends deactivating
 * another plugin. It reports what is doubled and lets the merchant decide.
 *
 * @package TWT_AEO_Ultimate
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Output_Control {

	/** Where the merchant's switches live. Absent key = on. */
	const OPTION_KEY = 'twtaeo_output_control';

	/**
	 * Everything this plugin writes that another plugin might also write.
	 *
	 * - `types`    schema.org types, used to work out who else is publishing.
	 * - `delegate` reads an existing setting instead of storing a second flag.
	 *              `null` means this class stores the switch itself.
	 * - `writer`   the class that actually emits, so a reader can check the claim.
	 * - `gated`    a note where part of this output already had a switch.
	 *
	 * @return array<string,array>
	 */
	public static function outputs() {
		return array(

			'schema_identity' => array(
				'label'    => __( 'Site identity schema', 'twt-aeo-ultimate' ),
				'what'     => __( 'The Organization, WebSite and WebPage nodes — who the site is, and what this page is.', 'twt-aeo-ultimate' ),
				'types'    => array( 'Organization', 'WebSite', 'WebPage' ),
				'delegate' => null,
				'writer'   => 'TWTAEO_Knowledge_Graph',
			),

			'schema_article' => array(
				'label'    => __( 'Article schema', 'twt-aeo-ultimate' ),
				'what'     => __( 'The Article or NewsArticle node on posts, with its author and publisher.', 'twt-aeo-ultimate' ),
				'types'    => array( 'Article', 'BlogPosting', 'NewsArticle' ),
				'delegate' => null,
				'writer'   => 'TWTAEO_Article_Schema_Writer',
			),

			'schema_service' => array(
				'label'    => __( 'Service schema', 'twt-aeo-ultimate' ),
				'what'     => __( 'The Service node on pages describing something you offer.', 'twt-aeo-ultimate' ),
				'types'    => array( 'Service' ),
				'delegate' => null,
				'writer'   => 'TWTAEO_Service_Schema_Writer',
			),

			'schema_contact' => array(
				'label'    => __( 'Contact page schema', 'twt-aeo-ultimate' ),
				'what'     => __( 'The ContactPage node and the contact details attached to your Organization.', 'twt-aeo-ultimate' ),
				'types'    => array( 'ContactPage' ),
				'delegate' => null,
				'writer'   => 'TWTAEO_Contact_Schema_Writer',
			),

			// ⚠️ Delegated. The switch is `semantic_breadcrumbs` on the AI-Ready
			// screen and has been since long before this class existed.
			'schema_breadcrumb' => array(
				'label'    => __( 'Breadcrumb schema', 'twt-aeo-ultimate' ),
				'what'     => __( 'The BreadcrumbList trail describing where this page sits in the site.', 'twt-aeo-ultimate' ),
				'types'    => array( 'BreadcrumbList' ),
				'delegate' => array( 'ai_ready', 'semantic_breadcrumbs' ),
				'writer'   => 'TWTAEO_AI_Ready::output_semantic_breadcrumbs',
			),

			// ⚠️ Delegated, same reasoning.
			'llms_txt' => array(
				'label'    => __( 'llms.txt', 'twt-aeo-ultimate' ),
				'what'     => __( 'The /llms.txt document that tells AI assistants what this site contains.', 'twt-aeo-ultimate' ),
				'types'    => array(),
				'delegate' => array( 'ai_ready', 'llms_txt' ),
				'writer'   => 'TWTAEO_AI_Ready',
			),

			'sitemap' => array(
				'label'    => __( 'XML sitemap at /sitemap.xml', 'twt-aeo-ultimate' ),
				'what'     => __( 'The virtual /sitemap.xml endpoint, which serves or redirects to a sitemap.', 'twt-aeo-ultimate' ),
				'types'    => array(),
				'delegate' => null,
				'writer'   => 'TWTAEO_AI_Ready',
				'gated'    => __( 'The full sitemap generator is separate and already has its own module switch; this controls the /sitemap.xml address itself.', 'twt-aeo-ultimate' ),
			),

			'meta_description' => array(
				'label'    => __( 'Meta description', 'twt-aeo-ultimate' ),
				'what'     => __( 'The description tag, including the AI-written descriptions this plugin generates.', 'twt-aeo-ultimate' ),
				'types'    => array(),
				'delegate' => null,
				'writer'   => 'TWTAEO_AI_Description::output_meta_tag',
				'gated'    => __( 'This already steps aside automatically for Yoast, Rank Math, All in One SEO, SEOPress and The SEO Framework. The switch is for anything else that writes one.', 'twt-aeo-ultimate' ),
			),

			'crawler_log' => array(
				'label'    => __( 'AI crawler log', 'twt-aeo-ultimate' ),
				'what'     => __( 'Recording which AI crawlers requested pages on this site.', 'twt-aeo-ultimate' ),
				'types'    => array(),
				'delegate' => null,
				'writer'   => 'TWTAEO_AI_Crawler_Logger',
			),
		);
	}

	// ── The merchant's switch ────────────────────────────────────────────────

	/** Whether a delegated key's real setting lives elsewhere. */
	public static function is_delegated( $key ) {
		$o = self::outputs();
		return ! empty( $o[ $key ]['delegate'] );
	}

	/**
	 * Whether the merchant has this output switched on.
	 *
	 * Delegated keys are read from the setting that already owns them, never from
	 * a copy — see rule 2 in the class docblock.
	 */
	public static function enabled( $key ) {
		$outputs = self::outputs();
		if ( ! isset( $outputs[ $key ] ) ) {
			return true; // Unknown key: never silence something by mistyping it.
		}

		$delegate = $outputs[ $key ]['delegate'];
		if ( $delegate ) {
			list( $owner, $setting ) = $delegate;
			if ( 'ai_ready' === $owner && class_exists( 'TWTAEO_AI_Ready' ) ) {
				$s = TWTAEO_AI_Ready::get_settings();
				return ! empty( $s[ $setting ] );
			}
			return true;
		}

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) || ! array_key_exists( $key, $saved ) ) {
			return true; // Absent means on. See rule 1.
		}
		return ! empty( $saved[ $key ] );
	}

	/**
	 * Store a switch. Delegated keys are refused rather than silently written,
	 * because writing them here would create the second flag rule 2 forbids.
	 *
	 * @return bool Whether it was stored.
	 */
	public static function set( $key, $on ) {
		$outputs = self::outputs();
		if ( ! isset( $outputs[ $key ] ) || self::is_delegated( $key ) ) {
			return false;
		}

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved[ $key ] = $on ? 1 : 0;
		update_option( self::OPTION_KEY, $saved, false );
		return true;
	}

	/**
	 * 🛑 **The single question every writer asks.**
	 *
	 * Two things can stop this plugin writing something: the merchant switched it
	 * off here, or the merchant handed that surface to AEO Ultimate for WooCommerce
	 * on its ownership screen. Folding both into one call is deliberate — a writer
	 * checking only one of them would honour half the merchant's instructions, and
	 * which half would depend on which writer.
	 */
	public static function should_write( $key ) {
		if ( ! self::enabled( $key ) ) {
			return false;
		}
		if ( class_exists( 'TWTAEO_Commerce_Handoff' ) && TWTAEO_Commerce_Handoff::stands_down( $key ) ) {
			return false;
		}
		return true;
	}

	// ── Applying the decision to the graph ───────────────────────────────────

	/**
	 * Remove from a built graph everything the merchant has switched off or handed
	 * over. The one place graph surgery happens.
	 *
	 * 🛑 **The two cases are not the same and must not be merged.**
	 *
	 * - **Handed over** to the commerce fork: a node is removed only when that
	 *   plugin publishes one at the **same `@id`** on this request. References into
	 *   it stay valid because the replacement carries the identical identifier, so
	 *   nothing is stripped. This is safe only because that plugin is a fork of this
	 *   one and the identifiers match.
	 * - **Switched off** by the merchant: there is no such guarantee. Yoast
	 *   addresses its WebPage by the bare URL, not `{url}#webpage`, so removing ours
	 *   would leave every `isPartOf` and `mainEntityOfPage` pointing at an `@id`
	 *   nothing on the page defines. So here the node goes **and every reference to
	 *   it is stripped with it**.
	 *
	 * @param array $nodes `@id`-keyed graph map.
	 * @return array
	 */
	public static function filter_graph( array $nodes ) {
		$outputs = self::outputs();
		$orphans = array();

		foreach ( $outputs as $key => $spec ) {
			if ( empty( $spec['types'] ) ) {
				continue; // Not a schema surface.
			}

			// Case 1 — handed over. Same-@id replacement required; no stripping.
			if ( class_exists( 'TWTAEO_Commerce_Handoff' ) ) {
				$nodes = TWTAEO_Commerce_Handoff::drop_superseded( $nodes, $key );
			}

			// Case 2 — switched off here. Unconditional, so references must go too.
			if ( self::enabled( $key ) ) {
				continue;
			}

			foreach ( $nodes as $id => $node ) {
				if ( empty( $node['@type'] ) ) {
					continue;
				}
				if ( ! array_intersect( (array) $node['@type'], $spec['types'] ) ) {
					continue;
				}
				if ( ! empty( $node['@id'] ) ) {
					$orphans[] = (string) $node['@id'];
				}
				unset( $nodes[ $id ] );
			}
		}

		return $orphans ? self::strip_refs_to( $nodes, $orphans ) : $nodes;
	}

	/**
	 * Remove every `{"@id": …}` reference pointing at a node that no longer exists.
	 *
	 * Handles the three shapes a reference takes in this graph: a bare reference
	 * object, a list of them, and a scalar property whose value is a reference. A
	 * property left empty by the removal is dropped rather than published as `[]`.
	 *
	 * @param array    $nodes
	 * @param string[] $gone `@id`s that were removed.
	 * @return array
	 */
	private static function strip_refs_to( array $nodes, array $gone ) {
		$gone = array_flip( $gone );

		foreach ( $nodes as $id => $node ) {
			foreach ( $node as $prop => $value ) {
				if ( '@id' === $prop || ! is_array( $value ) ) {
					continue;
				}

				// A single reference: { "@id": "…" }
				if ( isset( $value['@id'] ) ) {
					if ( isset( $gone[ (string) $value['@id'] ] ) ) {
						unset( $node[ $prop ] );
					}
					continue;
				}

				// A list that may contain references.
				$kept = array();
				foreach ( $value as $item ) {
					if ( is_array( $item ) && isset( $item['@id'] ) && count( $item ) === 1
						&& isset( $gone[ (string) $item['@id'] ] ) ) {
						continue;
					}
					$kept[] = $item;
				}
				if ( count( $kept ) !== count( $value ) ) {
					if ( $kept ) {
						$node[ $prop ] = array_values( $kept );
					} else {
						unset( $node[ $prop ] );
					}
				}
			}
			$nodes[ $id ] = $node;
		}

		return $nodes;
	}

	// ── Who else is writing it ───────────────────────────────────────────────

	/**
	 * Other active plugins publishing the same thing, by name.
	 *
	 * ⚠️ **Detection is by each plugin's own constant and its declared output, never
	 * by reading the rendered page.** So this answers "which installed plugin says it
	 * writes this", which is honest but coarser than a measurement — a plugin can
	 * declare a type it only emits in some contexts. The admin says so rather than
	 * presenting it as a reading of the page.
	 *
	 * @return string[] Plugin names.
	 */
	public static function competing_writers( $key ) {
		$outputs = self::outputs();
		if ( ! isset( $outputs[ $key ] ) ) {
			return array();
		}

		$names = array();

		// The commerce fork. It publishes the same @ids this plugin does, so it is
		// worth naming first and separately — and it is the one competitor that can
		// be resolved without switching anything off here, via its ownership screen.
		if ( defined( 'AEOWC_VERSION' ) && in_array( $key, self::commerce_writes(), true ) ) {
			$names[] = __( 'AEO Ultimate for WooCommerce', 'twt-aeo-ultimate' );
		}

		if ( class_exists( 'TWTAEO_SEO_Compatibility' ) ) {
			$types = $outputs[ $key ]['types'];
			foreach ( TWTAEO_SEO_Compatibility::detect_active_plugins() as $plugin ) {
				if ( $types && self::plugin_declares( $plugin, $types ) ) {
					$names[] = $plugin['name'];
					continue;
				}
				// Non-schema surfaces are not in the compatibility map's type lists,
				// so they are matched by what each plugin is known to do.
				if ( ! $types && in_array( $key, self::seo_plugin_writes( $plugin['slug'] ), true ) ) {
					$names[] = $plugin['name'];
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/** Whether a detected plugin declares any of these types. */
	private static function plugin_declares( array $plugin, array $types ) {
		$declared = isset( $plugin['schema_output']['always'] )
			? (array) $plugin['schema_output']['always']
			: array();

		if ( ! empty( $plugin['schema_output']['conditional'] ) ) {
			$declared = array_merge( $declared, array_keys( (array) $plugin['schema_output']['conditional'] ) );
		}

		return (bool) array_intersect( $types, $declared );
	}

	/**
	 * Non-schema surfaces each SEO plugin writes.
	 *
	 * Deliberately conservative: every one of these ships an XML sitemap and a
	 * meta description, which is checkable and true. llms.txt is claimed only for
	 * Rank Math, and only when its module is on — the rest do not offer one, and
	 * saying they do would send a merchant looking for a file that is not there.
	 */
	private static function seo_plugin_writes( $slug ) {
		$writes = array( 'sitemap', 'meta_description' );

		if ( 'rank-math' === $slug ) {
			$modules = (array) get_option( 'rank_math_modules', array() );
			if ( in_array( 'llms-txt', $modules, true ) ) {
				$writes[] = 'llms_txt';
			}
		}

		return $writes;
	}

	/** Keys the commerce fork also writes. */
	private static function commerce_writes() {
		return array(
			'schema_identity',
			'schema_breadcrumb',
			'llms_txt',
			'sitemap',
			'meta_description',
			'crawler_log',
		);
	}

	/**
	 * Everything currently doubled: switched on here, and written by something else.
	 *
	 * @return array<string,string[]> key => competing plugin names.
	 */
	public static function conflicts() {
		$out = array();
		foreach ( array_keys( self::outputs() ) as $key ) {
			if ( ! self::should_write( $key ) ) {
				continue; // Already resolved, by switch or by hand-over.
			}
			$others = self::competing_writers( $key );
			if ( $others ) {
				$out[ $key ] = $others;
			}
		}
		return $out;
	}

	/**
	 * How a key is currently resolved, for the admin.
	 *
	 * @return array{state:string,summary:string,others:string[]}
	 */
	public static function status( $key ) {
		$others = self::competing_writers( $key );

		if ( class_exists( 'TWTAEO_Commerce_Handoff' ) && TWTAEO_Commerce_Handoff::stands_down( $key ) ) {
			return array(
				'state'   => 'handed_over',
				'summary' => __( 'Handed to AEO Ultimate for WooCommerce on its ownership screen. This plugin is not writing it.', 'twt-aeo-ultimate' ),
				'others'  => $others,
			);
		}

		if ( ! self::enabled( $key ) ) {
			return array(
				'state'   => 'off',
				'summary' => $others
					? sprintf(
						/* translators: %s: comma-separated plugin names. */
						__( 'Switched off here. Written by %s.', 'twt-aeo-ultimate' ),
						implode( ', ', $others )
					)
					: __( 'Switched off here, and nothing else is writing it.', 'twt-aeo-ultimate' ),
				'others'  => $others,
			);
		}

		if ( $others ) {
			return array(
				'state'   => 'conflict',
				'summary' => sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'Both this plugin and %s are writing this.', 'twt-aeo-ultimate' ),
					implode( ', ', $others )
				),
				'others'  => $others,
			);
		}

		return array(
			'state'   => 'ours',
			'summary' => __( 'Written by this plugin. Nothing else is competing for it.', 'twt-aeo-ultimate' ),
			'others'  => array(),
		);
	}
}
