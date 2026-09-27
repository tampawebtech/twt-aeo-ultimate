<?php
/**
 * AEO Score
 *
 * A per-page citability score with the receipts shown. Every point is a named
 * line item — what it checks, why an answer engine cares, and where to fix it.
 * The score is the sum of the visible lines; there is no hidden weighting.
 *
 * Scoring is intent-aware: a page is only measured against surfaces its
 * classified intent actually calls for (a blog post is never docked for
 * missing Product schema). Each intent therefore has a different maximum;
 * scores are normalized to 100 so pages stay comparable.
 *
 * Scores are computed at scan time (TWTAEO_Scan_Store::scan_and_store) and
 * stored in postmeta, so dashboard rendering only ever reads.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Aeo_Score {

	/** Full breakdown (serialized array). */
	const META_BREAKDOWN = '_twtaeo_aeo_score';

	/** Bare 0-100 integer, duplicated for cheap SQL aggregation. */
	const META_SCORE = '_twtaeo_aeo_score_num';

	/** Site-score history option: [ ['date','score','pages'], … ]. */
	const HISTORY_OPTION = 'twtaeo_score_history';

	/** Bump when the rubric changes so stale breakdowns can be told apart. */
	const RUBRIC_VERSION = 1;

	/** Max history snapshots kept (~4 months of daily points). */
	const HISTORY_CAP = 120;

	/** Transient key for the site_summary() rollup. */
	const SUMMARY_CACHE = 'twtaeo_score_summary';

	// ── Per-page scoring ──────────────────────────────────────────────────────

	/**
	 * Compute and persist the score for a post. Called from scan_and_store.
	 *
	 * @param WP_Post $post
	 * @param array   $scan Result array from scan_and_store (intent/evaluation).
	 * @return array The stored breakdown.
	 */
	public static function score_and_store( $post, $scan ) {
		$breakdown = self::compute( $post, $scan );
		update_post_meta( $post->ID, self::META_BREAKDOWN, $breakdown );
		update_post_meta( $post->ID, self::META_SCORE, $breakdown['score'] );
		delete_transient( self::SUMMARY_CACHE );
		return $breakdown;
	}

	/**
	 * Stored breakdown for a post, or null if never scored.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get_breakdown( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_BREAKDOWN, true );
		return is_array( $stored ) && isset( $stored['score'] ) ? $stored : null;
	}

	/**
	 * Compute the full scored breakdown for a post.
	 *
	 * @param WP_Post $post
	 * @param array   $scan
	 * @return array {score,earned,max,categories,version,computed_at}
	 */
	public static function compute( $post, $scan ) {
		$evaluation = $scan['evaluation'] ?? array();
		$intent     = $scan['intent'] ?? array();
		$heur       = class_exists( 'TWTAEO_Index_Heuristics' ) ? TWTAEO_Index_Heuristics::analyze( $post->ID ) : null;
		$flags      = array();
		foreach ( ( $heur['flags'] ?? array() ) as $f ) {
			$flags[ $f['type'] ] = $f;
		}

		$categories   = array();
		$categories[] = self::cat_core_schema( $post, $evaluation );
		$categories[] = self::cat_answer_surfaces( $post, $evaluation );
		$categories[] = self::cat_trust( $post, $intent );
		$categories[] = self::cat_social( $post );
		$categories[] = self::cat_discoverability( $post, $flags );
		$categories[] = self::cat_content( $post, $flags );

		$earned = 0.0;
		$max    = 0.0;
		foreach ( $categories as &$cat ) {
			$cat_earned = 0.0;
			$cat_max    = 0.0;
			foreach ( $cat['items'] as $item ) {
				$cat_earned += $item['pts'];
				$cat_max    += $item['max'];
			}
			$cat['earned'] = round( $cat_earned, 1 );
			$cat['max']    = round( $cat_max, 1 );
			$earned       += $cat_earned;
			$max          += $cat_max;
		}
		unset( $cat );

		return array(
			'score'       => $max > 0 ? (int) round( 100 * $earned / $max ) : 0,
			'earned'      => round( $earned, 1 ),
			'max'         => round( $max, 1 ),
			'intent'      => $intent['label'] ?? '',
			'categories'  => $categories,
			'version'     => self::RUBRIC_VERSION,
			'computed_at' => current_time( 'mysql' ),
		);
	}

	// ── Categories ────────────────────────────────────────────────────────────

	/**
	 * Core schema: the types this page's intent EXPECTS. Weight 40, split
	 * evenly across expected types.
	 */
	private static function cat_core_schema( $post, $evaluation ) {
		$present  = $evaluation['present'] ?? array();
		$missing  = $evaluation['missing'] ?? array();
		$expected = array_merge( $present, $missing );
		$items    = array();
		$share    = $expected ? round( 40 / count( $expected ), 1 ) : 0;

		foreach ( $expected as $type ) {
			$has  = in_array( $type, $present, true );
			$item = array(
				'id'    => 'schema_' . strtolower( $type ),
				'label' => $type . ' schema',
				'pts'   => $has ? $share : 0,
				'max'   => $share,
				'why'   => sprintf(
					/* translators: %s: schema type name. */
					__( 'Your page reads as this kind of content, so answer engines look for a %s node to quote it with confidence. No node, no structured citation.', 'twt-aeo-ultimate' ),
					$type
				),
			);
			$items[] = array_merge( $item, $has ? array( 'fix' => '', 'fix_label' => '' ) : self::schema_fix( $type ) );
		}

		return array(
			'id'    => 'core_schema',
			'label' => __( 'Core schema', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	/**
	 * The right fix affordance for a missing schema type. FAQ and Service have
	 * detector screens that generate from page content — link there. Everything
	 * else is created from the page's own dashboard row, so instead of a link
	 * back to the page the user is already on, emit `create` and let the score
	 * dialog trigger the row's + button directly.
	 *
	 * @param string $type
	 * @return array {fix, fix_label, create?}
	 */
	private static function schema_fix( $type ) {
		if ( 'FAQPage' === $type ) {
			return array(
				'fix'       => admin_url( 'admin.php?page=twt-aeo-schema-detector&tab=faq' ),
				'fix_label' => __( 'Generate from your page\'s Q&A content (Schema Detector → FAQ)', 'twt-aeo-ultimate' ),
			);
		}
		if ( 'Service' === $type ) {
			return array(
				'fix'       => admin_url( 'admin.php?page=twt-aeo-schema-detector&tab=service' ),
				'fix_label' => __( 'Generate it on the Schema Detector\'s Service tab', 'twt-aeo-ultimate' ),
			);
		}
		return array(
			'fix'       => '',
			'fix_label' => __( 'Create it now', 'twt-aeo-ultimate' ),
			'create'    => $type,
		);
	}

	/**
	 * Answer surfaces: the RECOMMENDED types (FAQ, Review, BreadcrumbList, …).
	 * Weight 15, split evenly.
	 */
	private static function cat_answer_surfaces( $post, $evaluation ) {
		$recommended_missing = $evaluation['recommended'] ?? array();
		$intent              = $evaluation['intent'] ?? array();
		$all_recommended     = $intent['recommended'] ?? array();
		$items               = array();
		$share               = $all_recommended ? round( 15 / count( $all_recommended ), 1 ) : 0;

		foreach ( $all_recommended as $type ) {
			$has  = ! in_array( $type, $recommended_missing, true );
			$item = array(
				'id'    => 'surface_' . strtolower( $type ),
				'label' => $type . ' schema',
				'pts'   => $has ? $share : 0,
				'max'   => $share,
				'why'   => sprintf(
					/* translators: %s: schema type name. */
					__( 'Recommended for this page type: a %s node gives engines an extra quotable surface beyond the core answer.', 'twt-aeo-ultimate' ),
					$type
				),
			);
			$items[] = array_merge( $item, $has ? array( 'fix' => '', 'fix_label' => '' ) : self::schema_fix( $type ) );
		}

		return array(
			'id'    => 'answer_surfaces',
			'label' => __( 'Answer surfaces', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	/**
	 * Trust & authorship. Company profile always applies (8); the author check
	 * applies only where authorship is a ranking surface — posts (7).
	 */
	private static function cat_trust( $post, $intent ) {
		$items = array();

		// Company profile completeness: name, logo, description, contact, social.
		$company = class_exists( 'TWTAEO_Company_Profile' ) ? TWTAEO_Company_Profile::get() : array();
		$checks  = array(
			! empty( $company['name'] ),
			! empty( $company['logo_url'] ) || ! empty( $company['logo_id'] ),
			! empty( $company['description'] ),
			! empty( $company['phone'] ) || ! empty( $company['email'] ),
			! empty( $company['social_facebook'] ) || ! empty( $company['social_twitter'] )
				|| ! empty( $company['social_linkedin'] ) || ! empty( $company['social_instagram'] )
				|| ! empty( $company['social_youtube'] ) || ! empty( $company['social_wikipedia'] ),
		);
		$filled  = count( array_filter( $checks ) );
		$items[] = array(
			'id'    => 'company_profile',
			'label' => __( 'Company profile', 'twt-aeo-ultimate' ),
			'pts'   => round( 8 * $filled / count( $checks ), 1 ),
			'max'   => 8.0,
			'why'   => sprintf(
				/* translators: %1$d: filled fields, %2$d: total fields. */
				__( '%1$d of %2$d identity surfaces filled (name, logo, description, contact, social). Engines cite entities they can verify; an anonymous publisher is a risky citation.', 'twt-aeo-ultimate' ),
				$filled,
				count( $checks )
			),
			'fix'   => $filled === count( $checks ) ? '' : admin_url( 'admin.php?page=twt-aeo-eeat&tab=company' ),
			'fix_label' => $filled === count( $checks ) ? '' : __( 'Complete the Company profile', 'twt-aeo-ultimate' ),
		);

		// Author completeness — only for authored content (posts / blog intent).
		$authored = ( 'post' === $post->post_type ) || ( ( $intent['intent'] ?? '' ) === 'blog_post' );
		if ( $authored ) {
			$author = class_exists( 'TWTAEO_Author_Meta' )
				? TWTAEO_Author_Meta::get_author_data( (int) $post->post_author )
				: array();
			$a_checks = array(
				! empty( $author['bio'] ),
				! empty( $author['job_title'] ) || ! empty( $author['credentials'] ) || ! empty( $author['expertise'] ),
				! empty( array_filter( (array) ( $author['social'] ?? array() ) ) ) || ! empty( $author['url'] ),
			);
			$a_filled = count( array_filter( $a_checks ) );
			$items[]  = array(
				'id'    => 'author_profile',
				'label' => sprintf(
					/* translators: %s: author display name. */
					__( 'Author profile (%s)', 'twt-aeo-ultimate' ),
					$author['name'] ?? __( 'unknown', 'twt-aeo-ultimate' )
				),
				'pts'   => round( 7 * $a_filled / count( $a_checks ), 1 ),
				'max'   => 7.0,
				'why'   => sprintf(
					/* translators: %1$d: filled checks, %2$d: total checks. */
					__( '%1$d of %2$d authorship signals present (bio, expertise/credentials, links). E-E-A-T is per-author: a byline engines cannot verify weakens every article it signs.', 'twt-aeo-ultimate' ),
					$a_filled,
					count( $a_checks )
				),
				'fix'   => $a_filled === count( $a_checks ) ? '' : admin_url( 'admin.php?page=twt-aeo-eeat&tab=author' ),
				'fix_label' => $a_filled === count( $a_checks ) ? '' : __( 'Complete this author\'s profile', 'twt-aeo-ultimate' ),
			);
		}

		return array(
			'id'    => 'trust',
			'label' => __( 'Trust & authorship', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	/**
	 * Social / Open Graph. Weight 10: title 3, description 4, image 3.
	 * You can argue OG is not an AEO surface; engines consuming pages for
	 * answers read it anyway, so it scores.
	 */
	private static function cat_social( $post ) {
		$og = class_exists( 'TWTAEO_OG_Detector' ) ? TWTAEO_OG_Detector::scan( $post ) : array();

		$has_title = ! empty( $og['og_title'] ) || ! empty( get_the_title( $post ) );
		$has_desc  = ! empty( $og['og_description'] );
		$has_image = ! empty( $og['og_image'] );
		if ( ! $has_image && class_exists( 'TWTAEO_OG_Writer' ) && method_exists( 'TWTAEO_OG_Writer', 'effective_image' ) ) {
			$eff       = TWTAEO_OG_Writer::effective_image( $post->ID );
			$has_image = ! empty( $eff['url'] ) || ! empty( $eff['id'] );
		}

		$social_url = admin_url( 'admin.php?page=twt-aeo-social-graph' );
		$items      = array(
			array(
				'id'    => 'og_title',
				'label' => __( 'Share title (og:title)', 'twt-aeo-ultimate' ),
				'pts'   => $has_title ? 3.0 : 0,
				'max'   => 3.0,
				'why'   => __( 'The title engines and link previews use when this page is surfaced outside your site.', 'twt-aeo-ultimate' ),
				'fix'   => $has_title ? '' : $social_url,
				'fix_label' => $has_title ? '' : __( 'Set it on the Social Graph screen', 'twt-aeo-ultimate' ),
			),
			array(
				'id'    => 'og_description',
				'label' => __( 'Share description (og:description)', 'twt-aeo-ultimate' ),
				'pts'   => $has_desc ? 4.0 : 0,
				'max'   => 4.0,
				'why'   => __( 'A one-paragraph summary engines can lift verbatim. Without it they guess — or skip the page.', 'twt-aeo-ultimate' ),
				'fix'   => $has_desc ? '' : $social_url,
				'fix_label' => $has_desc ? '' : __( 'Generate it on the Social Graph screen', 'twt-aeo-ultimate' ),
			),
			array(
				'id'    => 'og_image',
				'label' => __( 'Share image (og:image)', 'twt-aeo-ultimate' ),
				'pts'   => $has_image ? 3.0 : 0,
				'max'   => 3.0,
				'why'   => __( 'The image shown wherever this page is cited or shared. No image means a text-only citation competing against rich ones.', 'twt-aeo-ultimate' ),
				'fix'   => $has_image ? '' : $social_url,
				'fix_label' => $has_image ? '' : __( 'Add an image on the Social Graph screen', 'twt-aeo-ultimate' ),
			),
		);

		return array(
			'id'    => 'social',
			'label' => __( 'Social & Open Graph', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	/**
	 * Discoverability. Weight 10: indexable 6, canonical 4. A perfect page
	 * engines are told not to index scores zero in practice.
	 */
	private static function cat_discoverability( $post, $flags ) {
		$noindexed  = isset( $flags['noindex'] );
		$canon_off  = isset( $flags['canonical_mismatch'] );
		$edit_url   = get_edit_post_link( $post->ID, 'raw' );

		$items = array(
			array(
				'id'    => 'indexable',
				'label' => __( 'Indexable (no noindex)', 'twt-aeo-ultimate' ),
				'pts'   => $noindexed ? 0 : 6.0,
				'max'   => 6.0,
				'why'   => $noindexed
					? __( 'This page is marked noindex — engines are told to ignore it. Everything else on this list is moot until this is cleared.', 'twt-aeo-ultimate' )
					: __( 'Engines are allowed to index this page.', 'twt-aeo-ultimate' ),
				'fix'   => $noindexed ? $edit_url : '',
				'fix_label' => $noindexed ? __( 'Clear noindex in the editor / SEO plugin', 'twt-aeo-ultimate' ) : '',
			),
			array(
				'id'    => 'canonical',
				'label' => __( 'Canonical points here', 'twt-aeo-ultimate' ),
				'pts'   => $canon_off ? 0 : 4.0,
				'max'   => 4.0,
				'why'   => $canon_off
					? __( 'The canonical URL points at a different page, so engines credit that page instead of this one.', 'twt-aeo-ultimate' )
					: __( 'This page is its own canonical — citations credit this URL.', 'twt-aeo-ultimate' ),
				'fix'   => $canon_off ? $edit_url : '',
				'fix_label' => $canon_off ? __( 'Review the canonical override', 'twt-aeo-ultimate' ) : '',
			),
		);

		return array(
			'id'    => 'discoverability',
			'label' => __( 'Discoverability', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	/**
	 * Content signals. Weight 10: meta description 3, headings 2, depth 2,
	 * keyword density 3.
	 */
	private static function cat_content( $post, $flags ) {
		$no_meta  = isset( $flags['missing_meta_desc'] );
		$no_heads = isset( $flags['no_heading_hierarchy'] );
		$thin     = isset( $flags['word_count'] );
		$stuffed  = isset( $flags['keyword_density'] );
		$edit_url = get_edit_post_link( $post->ID, 'raw' );

		$items = array(
			array(
				'id'    => 'meta_description',
				'label' => __( 'Meta description', 'twt-aeo-ultimate' ),
				'pts'   => $no_meta ? 0 : 3.0,
				'max'   => 3.0,
				'why'   => __( 'The page\'s own one-line answer to "what is this?" — the cheapest quotable text you can give an engine.', 'twt-aeo-ultimate' ),
				'fix'   => $no_meta ? admin_url( 'admin.php?page=twt-aeo' ) : '',
				'fix_label' => $no_meta ? __( 'Generate one from the Dashboard\'s AI Meta Descriptions panel', 'twt-aeo-ultimate' ) : '',
			),
			array(
				'id'    => 'headings',
				'label' => __( 'Heading structure', 'twt-aeo-ultimate' ),
				'pts'   => $no_heads ? 0 : 2.0,
				'max'   => 2.0,
				'why'   => __( 'H2/H3 sections are how engines find the PART of a page that answers a question. One unbroken block gives them nothing to anchor a citation to.', 'twt-aeo-ultimate' ),
				'fix'   => $no_heads ? $edit_url : '',
				'fix_label' => $no_heads ? __( 'Add subheadings in the editor', 'twt-aeo-ultimate' ) : '',
			),
			array(
				'id'    => 'depth',
				'label' => __( 'Content depth', 'twt-aeo-ultimate' ),
				'pts'   => $thin ? 0 : 2.0,
				'max'   => 2.0,
				'why'   => __( 'Thin pages rarely survive an engine\'s answer-selection pass — there is not enough there to be the source.', 'twt-aeo-ultimate' ),
				'fix'   => $thin ? $edit_url : '',
				'fix_label' => $thin ? __( 'Expand the content', 'twt-aeo-ultimate' ) : '',
			),
			array(
				'id'    => 'keyword_density',
				'label' => __( 'Natural keyword use', 'twt-aeo-ultimate' ),
				'pts'   => $stuffed ? 0 : 3.0,
				'max'   => 3.0,
				'why'   => __( 'Over-repeated keywords read as spam to both classic ranking and LLM answer selection.', 'twt-aeo-ultimate' ),
				'fix'   => $stuffed ? $edit_url : '',
				'fix_label' => $stuffed ? __( 'Vary the phrasing', 'twt-aeo-ultimate' ) : '',
			),
		);

		return array(
			'id'    => 'content',
			'label' => __( 'Content signals', 'twt-aeo-ultimate' ),
			'items' => $items,
		);
	}

	// ── Site rollup ───────────────────────────────────────────────────────────

	/**
	 * Site-level checks that belong to no single page. Returned in the same
	 * item shape as page categories.
	 */
	public static function site_checklist() {
		$items = array();

		// Company profile (same completeness math as the page item).
		$company = class_exists( 'TWTAEO_Company_Profile' ) ? TWTAEO_Company_Profile::get() : array();
		$checks  = array(
			! empty( $company['name'] ),
			! empty( $company['logo_url'] ) || ! empty( $company['logo_id'] ),
			! empty( $company['description'] ),
			! empty( $company['phone'] ) || ! empty( $company['email'] ),
			! empty( $company['social_facebook'] ) || ! empty( $company['social_twitter'] )
				|| ! empty( $company['social_linkedin'] ) || ! empty( $company['social_instagram'] )
				|| ! empty( $company['social_youtube'] ) || ! empty( $company['social_wikipedia'] ),
		);
		$filled  = count( array_filter( $checks ) );
		$items[] = array(
			'id'        => 'site_company',
			'label'     => __( 'Company profile', 'twt-aeo-ultimate' ),
			'pts'       => round( 25 * $filled / count( $checks ), 1 ),
			'max'       => 25.0,
			'why'       => __( 'The publisher entity behind every page. Engines answer "who says so?" before they cite.', 'twt-aeo-ultimate' ),
			'fix'       => $filled === count( $checks ) ? '' : admin_url( 'admin.php?page=twt-aeo-eeat&tab=company' ),
			'fix_label' => $filled === count( $checks ) ? '' : __( 'Complete the Company profile', 'twt-aeo-ultimate' ),
		);

		// All publishing authors have usable profiles.
		$authors    = get_users( array( 'has_published_posts' => array( 'post' ), 'fields' => array( 'ID', 'display_name' ) ) );
		$incomplete = array();
		if ( class_exists( 'TWTAEO_Author_Meta' ) ) {
			foreach ( $authors as $author ) {
				$data = TWTAEO_Author_Meta::get_author_data( (int) $author->ID );
				$ok   = ! empty( $data['bio'] )
					&& ( ! empty( $data['job_title'] ) || ! empty( $data['credentials'] ) || ! empty( $data['expertise'] ) );
				if ( ! $ok ) {
					$incomplete[] = $author->display_name;
				}
			}
		}
		$total_authors = max( 1, count( $authors ) );
		$complete      = $total_authors - count( $incomplete );
		$items[]       = array(
			'id'        => 'site_authors',
			'label'     => __( 'Author profiles', 'twt-aeo-ultimate' ),
			'pts'       => round( 25 * $complete / $total_authors, 1 ),
			'max'       => 25.0,
			'why'       => $incomplete
				? sprintf(
					/* translators: %s: comma-separated author names. */
					__( 'Incomplete: %s. One filled-out author does not cover the others — E-E-A-T is judged per byline.', 'twt-aeo-ultimate' ),
					implode( ', ', array_slice( $incomplete, 0, 5 ) ) . ( count( $incomplete ) > 5 ? '…' : '' )
				)
				: __( 'Every publishing author has a bio and expertise on record.', 'twt-aeo-ultimate' ),
			'fix'       => $incomplete ? admin_url( 'admin.php?page=twt-aeo-eeat&tab=author' ) : '',
			'fix_label' => $incomplete ? __( 'Fill in the missing author profiles', 'twt-aeo-ultimate' ) : '',
		);

		// IndexNow — tells engines about changes instead of waiting to be crawled.
		$active_modules = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		$indexnow_on    = in_array( 'index-now', (array) $active_modules, true )
			&& class_exists( 'TWTAEO_Index_Now' )
			&& ! empty( get_option( TWTAEO_Index_Now::OPTION_KEY, '' ) );
		$items[]        = array(
			'id'        => 'site_indexnow',
			'label'     => __( 'IndexNow', 'twt-aeo-ultimate' ),
			'pts'       => $indexnow_on ? 15.0 : 0,
			'max'       => 15.0,
			'why'       => __( 'Pushes every change to Bing and friends immediately — the engines several AI assistants read from. Without it you wait to be recrawled.', 'twt-aeo-ultimate' ),
			'fix'       => $indexnow_on ? '' : admin_url( 'admin.php?page=twt-aeo-index-now' ),
			'fix_label' => $indexnow_on ? '' : __( 'Enable IndexNow', 'twt-aeo-ultimate' ),
		);

		// XML sitemap surface.
		$sitemap_on = in_array( 'sitemap', (array) $active_modules, true );
		$items[]    = array(
			'id'        => 'site_sitemap',
			'label'     => __( 'Sitemap', 'twt-aeo-ultimate' ),
			'pts'       => $sitemap_on ? 10.0 : 0,
			'max'       => 10.0,
			'why'       => __( 'The index of what exists to crawl. Pages not discoverable through a sitemap depend on being linked into.', 'twt-aeo-ultimate' ),
			'fix'       => $sitemap_on ? '' : admin_url( 'admin.php?page=twt-aeo-modules' ),
			'fix_label' => $sitemap_on ? '' : __( 'Enable the Sitemap module', 'twt-aeo-ultimate' ),
		);

		// Knowledge Graph module — the unified @graph is the citability backbone.
		$kg_on   = in_array( 'schema-advisor', (array) $active_modules, true );
		$items[] = array(
			'id'        => 'site_kg',
			'label'     => __( 'Schema modules active', 'twt-aeo-ultimate' ),
			'pts'       => $kg_on ? 15.0 : 0,
			'max'       => 15.0,
			'why'       => __( 'The schema writers are what publish everything the page scores above measure. Off means nothing reaches the page.', 'twt-aeo-ultimate' ),
			'fix'       => $kg_on ? '' : admin_url( 'admin.php?page=twt-aeo-modules' ),
			'fix_label' => $kg_on ? '' : __( 'Review the Modules screen', 'twt-aeo-ultimate' ),
		);

		// Merchant sync — only a surface when WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			$gmc = class_exists( 'TWTAEO_Google_Merchant_Center' )
				&& method_exists( 'TWTAEO_Google_Merchant_Center', 'is_connected' )
				&& TWTAEO_Google_Merchant_Center::is_connected();
			$bmc = class_exists( 'TWTAEO_Bing_Merchant_Center' )
				&& method_exists( 'TWTAEO_Bing_Merchant_Center', 'is_connected' )
				&& TWTAEO_Bing_Merchant_Center::is_connected();
			$items[] = array(
				'id'        => 'site_merchant',
				'label'     => __( 'Merchant Center sync', 'twt-aeo-ultimate' ),
				'pts'       => ( $gmc || $bmc ) ? 10.0 : 0,
				'max'       => 10.0,
				'why'       => __( 'Product data fed to Google/Bing Merchant Center is what shopping-capable AI answers draw prices and availability from.', 'twt-aeo-ultimate' ),
				'fix'       => ( $gmc || $bmc ) ? '' : admin_url( 'admin.php?page=twt-aeo-woocommerce' ),
				'fix_label' => ( $gmc || $bmc ) ? '' : __( 'Connect a Merchant Center', 'twt-aeo-ultimate' ),
			);
		}

		return $items;
	}

	/**
	 * Site summary: average page score, counts, category rollup, checklist.
	 *
	 * The average reads the bare numeric meta (cheap SQL); the category rollup
	 * unserializes stored breakdowns, capped so a huge catalogue cannot blow
	 * up an admin page load.
	 *
	 * @return array
	 */
	public static function site_summary() {
		global $wpdb;

		$cached = get_transient( self::SUMMARY_CACHE );
		if ( is_array( $cached ) && isset( $cached['checklist'] ) ) {
			return $cached;
		}

		// Aggregates over postmeta that WP_Query cannot express; the result is
		// cached in the SUMMARY_CACHE transient and invalidated on every rescore.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avg_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT AVG(CAST(pm.meta_value AS UNSIGNED)) AS avg_score, COUNT(*) AS scored
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
			 WHERE pm.meta_key = %s",
			self::META_SCORE
		) );

		$avg    = $avg_row && null !== $avg_row->avg_score ? (int) round( (float) $avg_row->avg_score ) : null;
		$scored = $avg_row ? (int) $avg_row->scored : 0;

		// Category rollup from stored breakdowns (capped at 500 most recent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
			 WHERE pm.meta_key = %s
			 ORDER BY p.post_date DESC
			 LIMIT 500",
			self::META_BREAKDOWN
		) );

		$cats = array();
		foreach ( $rows as $raw ) {
			$b = maybe_unserialize( $raw );
			if ( ! is_array( $b ) || empty( $b['categories'] ) ) {
				continue;
			}
			foreach ( $b['categories'] as $cat ) {
				if ( ! isset( $cats[ $cat['id'] ] ) ) {
					$cats[ $cat['id'] ] = array( 'label' => $cat['label'], 'earned' => 0.0, 'max' => 0.0 );
				}
				$cats[ $cat['id'] ]['earned'] += (float) $cat['earned'];
				$cats[ $cat['id'] ]['max']    += (float) $cat['max'];
			}
		}
		foreach ( $cats as &$cat ) {
			$cat['pct'] = $cat['max'] > 0 ? (int) round( 100 * $cat['earned'] / $cat['max'] ) : 0;
		}
		unset( $cat );

		$checklist       = self::site_checklist();
		$check_earned    = 0.0;
		$check_max       = 0.0;
		foreach ( $checklist as $item ) {
			$check_earned += $item['pts'];
			$check_max    += $item['max'];
		}
		$check_pct = $check_max > 0 ? (int) round( 100 * $check_earned / $check_max ) : 0;

		// Site score: 70% average page score, 30% site checklist. Declared in
		// the UI, not hidden here.
		$site_score = null;
		if ( null !== $avg ) {
			$site_score = (int) round( 0.7 * $avg + 0.3 * $check_pct );
		}

		$summary = array(
			'site_score'    => $site_score,
			'avg_page'      => $avg,
			'scored_pages'  => $scored,
			'categories'    => $cats,
			'checklist'     => $checklist,
			'checklist_pct' => $check_pct,
		);

		set_transient( self::SUMMARY_CACHE, $summary, 5 * MINUTE_IN_SECONDS );

		return $summary;
	}

	// ── History ───────────────────────────────────────────────────────────────

	/**
	 * Record at most one site-score snapshot per day. Cheap to call on every
	 * dashboard render.
	 *
	 * @param array $summary Result of site_summary().
	 */
	public static function maybe_record_snapshot( $summary ) {
		if ( null === ( $summary['site_score'] ?? null ) ) {
			return;
		}
		$history = get_option( self::HISTORY_OPTION, array() );
		$today   = current_time( 'Y-m-d' );
		$last    = end( $history );
		if ( $last && ( $last['date'] ?? '' ) === $today ) {
			// Same-day rescan: keep the freshest number for today.
			$history[ key( $history ) ] = array( 'date' => $today, 'score' => $summary['site_score'], 'pages' => $summary['scored_pages'] );
		} else {
			$history[] = array( 'date' => $today, 'score' => $summary['site_score'], 'pages' => $summary['scored_pages'] );
		}
		if ( count( $history ) > self::HISTORY_CAP ) {
			$history = array_slice( $history, -self::HISTORY_CAP );
		}
		update_option( self::HISTORY_OPTION, array_values( $history ), false );
	}

	/**
	 * Stored history snapshots, oldest first.
	 *
	 * @return array
	 */
	public static function get_history() {
		$history = get_option( self::HISTORY_OPTION, array() );
		return is_array( $history ) ? $history : array();
	}
}
