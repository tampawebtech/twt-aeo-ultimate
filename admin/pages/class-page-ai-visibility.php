<?php
/**
 * Admin Page: AI Visibility — the board.
 *
 * Ported from the Shopify app's `app/routes/app.visibility.tsx` +
 * `VisibilityCharts.tsx` (2026-08-18). Top to bottom: header + the honesty
 * banner · Observed (AI referrals from GA4, Search Console, Bing) · Engines ·
 * Allocation (stacked bar + the arithmetic line) · Questions (collapsed
 * list) · Run (one check per request, driven by page-ai-visibility.js) ·
 * Results (headline, per-engine chips, trend sparklines, share-of-voice
 * donuts two by two, the question table with answer drawers) · footnote.
 *
 * Every number on this page says what it is a share OF. Presence and
 * citations, never "rank". Sample runs are labelled everywhere, never enter
 * a trend or a delta, and are removable with one click.
 *
 * All SVG is rendered here in PHP (donut ring paths, stacked bar, sparkline
 * polyline, verdict dots) — no chart library, nothing external.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Page_AI_Visibility {

	/** Our slice, and the accent every "us" mark on the page shares. */
	const OURS_COLOR = '#0ea5e9';
	/** Competitor domains, handed out in order of share. */
	const COMPETITOR_COLORS = array( '#1f8f6f', '#d9a21b', '#7b57c9', '#d0603f', '#c2417f', '#5c5f62' );
	/** The "everyone else" slice. */
	const OTHER_COLOR = '#e3e5e7';
	/** One colour per question level, shared by the allocation bar and the table. */
	const LEVEL_COLOR = array(
		'company'    => '#0ea5e9',
		'brand'      => '#c2417f',
		'collection' => '#1f8f6f',
		'type'       => '#b47a12',
		'product'    => '#7b57c9',
		'post'       => '#d0603f',
	);
	/** Verdict dot colours. */
	const VERDICT_COLOR = array(
		'cited'       => '#1f8f6f',
		'named'       => '#d9a21b',
		'absent'      => '#8c9196',
		'unavailable' => '#ffffff',
	);
	/** Company families whose fix is the policies half of the Company profile. */
	const COMPANY_TRUST_FAMILIES = array( 'returns', 'shipping', 'shipping_policy', 'ships_to', 'policy_refund', 'policy_shipping', 'refund', 'delivery' );
	/** Product families whose fix is the product itself (its options, price, stock). */
	const PRODUCT_CATALOG_FAMILIES = array( 'variant', 'price', 'where', 'availability', 'stock' );
	/** Brand families answered by publishing content rather than by registering a property. */
	const BRAND_CONTENT_FAMILIES = array(
		'brand_what', 'brand_good', 'brand_alternatives', 'brand_reviews', 'brand_features',
		'brand_pricing', 'brand_for_whom', 'brand_compare', 'brand_support', 'brand_start',
		'brand_problems', 'brand_worth', 'brand_free', 'brand_latest',
	);

	private function __construct() {}

	/* ═══════════════════════════════ render ═══════════════════════════════ */

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$d = self::gather();
		?>
		<div class="wrap twt-aeo-wrap twt-aeo-vis" id="twt-aeo-vis" data-page-url="<?php echo esc_url( self::page_url() ); ?>">

			<?php self::render_header( $d ); ?>
			<?php self::render_observed( $d ); ?>
			<?php self::render_engines( $d ); ?>
			<?php self::render_brands( $d ); ?>
			<?php self::render_personas( $d ); ?>
			<?php self::render_allocation( $d ); ?>
			<?php self::render_questions( $d ); ?>
			<?php self::render_run( $d ); ?>
			<?php self::render_results( $d ); ?>

			<section class="twt-aeo-vis__footnote">
				<h2><?php esc_html_e( 'What each engine means', 'twt-aeo-ultimate' ); ?></h2>
				<p>
					<?php esc_html_e( 'Each engine column is that assistant’s API with web search, asked the same question on your key. Cited means the answer linked to something you own — your domain, or a profile registered under “What counts as you”; the surface is shown alongside, so a citation that only ever lands on your LinkedIn page still reads as one. Named means it mentioned you without a link; absent means neither; unavailable means no answer was recorded (a refused or errored call), which is never counted as absent. The shares are of answered checks. This board reports presence and citations — it does not track positions, because assistants do not have them.', 'twt-aeo-ultimate' ); ?>
				</p>
			</section>
		</div>
		<?php
	}

	/* ═══════════════════════════════ data ═══════════════════════════════ */

	/**
	 * Everything the board needs, read once. Defensive about shapes so a
	 * missing optional layer (no GA4, no runs) renders its empty state rather
	 * than a notice.
	 */
	private static function gather() {
		$settings = TWTAEO_Visibility_Store::get_settings();
		$settings = is_array( $settings ) ? $settings : array();
		$alloc    = isset( $settings['allocation'] ) && is_array( $settings['allocation'] ) ? $settings['allocation'] : array();
		$alloc    = TWTAEO_Visibility_Questions::clamp_allocation( $alloc );

		$engines = TWTAEO_Visibility_Engines::availability();
		$engines = is_array( $engines ) ? $engines : array();
		$avail   = array();
		foreach ( $engines as $row ) {
			if ( is_array( $row ) && isset( $row['engine'] ) ) {
				$avail[ (string) $row['engine'] ] = $row;
			}
		}
		$available_ids = array();
		foreach ( TWTAEO_Visibility_Types::ENGINES_V1 as $id ) {
			if ( ! empty( $avail[ $id ]['available'] ) ) {
				$available_ids[] = $id;
			}
		}

		$counts = TWTAEO_Visibility_Inputs::catalog_counts();
		$counts = is_array( $counts ) ? $counts : array();
		foreach ( array( 'brands', 'collections', 'types', 'products', 'posts' ) as $k ) {
			$counts[ $k ] = isset( $counts[ $k ] ) ? (int) $counts[ $k ] : 0;
		}
		$totals = TWTAEO_Visibility_Questions::allocation_totals( $alloc, $counts );

		$budget = TWTAEO_Visibility_Budget::check();
		$budget = is_array( $budget ) ? $budget : array();
		$cap    = isset( $budget['cap'] ) ? (int) $budget['cap'] : TWTAEO_Visibility_Types::DEFAULT_DAILY_CAP;
		$used   = isset( $budget['used'] ) ? (int) $budget['used'] : 0;

		$identity = TWTAEO_Visibility_Inputs::identity();
		$identity = is_array( $identity ) ? $identity : array();
		$hosts    = isset( $identity['hosts'] ) && is_array( $identity['hosts'] ) ? $identity['hosts'] : array();
		if ( empty( $hosts ) ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $home ) {
				$hosts[] = strtolower( preg_replace( '/^www\./', '', $home ) );
			}
		}
		if ( ! empty( $settings['alternate_hosts'] ) && is_array( $settings['alternate_hosts'] ) ) {
			$hosts = array_values( array_unique( array_merge( $hosts, $settings['alternate_hosts'] ) ) );
		}

		$saved_questions = TWTAEO_Visibility_Store::load_questions();
		$saved_questions = is_array( $saved_questions ) && ! empty( $saved_questions ) ? array_values( $saved_questions ) : null;

		$summaries = TWTAEO_Visibility_Store::list_runs();
		$summaries = is_array( $summaries ) ? array_values( array_filter( $summaries, 'is_array' ) ) : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$run_param = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : '';
		$selected_id = '';
		foreach ( $summaries as $s ) {
			if ( isset( $s['id'] ) && (string) $s['id'] === $run_param ) {
				$selected_id = $run_param;
				break;
			}
		}
		if ( '' === $selected_id && ! empty( $summaries ) && isset( $summaries[0]['id'] ) ) {
			$selected_id = (string) $summaries[0]['id'];
		}
		$selected = '' !== $selected_id ? TWTAEO_Visibility_Store::get_run( $selected_id ) : null;
		$selected = is_array( $selected ) ? $selected : null;

		$scope_options = TWTAEO_Visibility_Inputs::scope_options();
		$scope_options = is_array( $scope_options ) ? $scope_options : array();
		$has_all       = false;
		foreach ( $scope_options as $o ) {
			if ( is_array( $o ) && isset( $o['value'] ) && '' === (string) $o['value'] ) {
				$has_all = true;
			}
		}
		if ( ! $has_all ) {
			array_unshift( $scope_options, array( 'value' => '', 'label' => __( 'All (every level)', 'twt-aeo-ultimate' ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope_param = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
		$scope       = '';
		foreach ( $scope_options as $o ) {
			if ( is_array( $o ) && isset( $o['value'] ) && (string) $o['value'] === $scope_param ) {
				$scope = $scope_param;
				break;
			}
		}

		// Owned profiles the plugin already knows about: Company profile fills
		// these (and imports Yoast / Rank Math), so most sites arrive pre-filled
		// without typing anything.
		$company_auto = array();
		if ( class_exists( 'TWTAEO_Visibility_Inputs' ) && method_exists( 'TWTAEO_Visibility_Inputs', 'company_urls' ) ) {
			$company_auto = (array) TWTAEO_Visibility_Inputs::company_urls( array(), isset( $settings['x_handle'] ) ? $settings['x_handle'] : '' );
		}

		// Observed layer — each call is independent and may be "not connected".
		$observed = array( 'referrals' => null, 'gsc' => null, 'bing' => null );
		if ( class_exists( 'TWTAEO_Visibility_Observed' ) ) {
			$r = TWTAEO_Visibility_Observed::ai_referrals( 28 );
			$observed['referrals'] = is_array( $r ) ? $r : null;
			$g = TWTAEO_Visibility_Observed::gsc_summary();
			$observed['gsc'] = is_array( $g ) ? $g : null;
			$b = TWTAEO_Visibility_Observed::bing_summary();
			$observed['bing'] = is_array( $b ) ? $b : null;
		}

		// Which scopes the SELECTED run actually asked about. The dropdown lists
		// every category/brand that exists, but a run only covers as many as its
		// allocation reached — picking one of the rest used to give a silently
		// blank board that reads as broken rather than as "never asked".
		$covered = array();
		if ( $selected && isset( $selected['questions'] ) && is_array( $selected['questions'] ) ) {
			foreach ( $selected['questions'] as $q ) {
				if ( ! is_array( $q ) ) {
					continue;
				}
				$lvl = isset( $q['level'] ) ? (string) $q['level'] : '';
				$sid = isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '';
				$covered[ $lvl ] = true;                    // 'products' / 'posts' style values
				if ( '' !== $sid ) {
					$covered[ $lvl . ':' . $sid ] = true;   // 'collection:133', 'brand:brand-x'
				}
			}
		}
		foreach ( $scope_options as $i => $o ) {
			if ( ! is_array( $o ) || ! isset( $o['value'] ) ) {
				continue;
			}
			$scope_options[ $i ]['covered'] = self::scope_is_covered( (string) $o['value'], $covered );
		}

		return array(
			'settings'        => $settings,
			'alloc'           => $alloc,
			'engines'         => $avail,
			'available_ids'   => $available_ids,
			'counts'          => $counts,
			'totals'          => $totals,
			'cap'             => $cap,
			'cap_used'        => $used,
			'hosts'           => $hosts,
			'site_name'       => isset( $identity['name'] ) ? (string) $identity['name'] : get_bloginfo( 'name' ),
			// Split so the page can show what it found for you separately from
			// what you typed: an auto-detected profile is reassuring, a typed one
			// is editable, and merging them hides which is which.
			'company_auto'    => $company_auto,
			'company_extra'   => isset( $settings['company_urls'] ) && is_array( $settings['company_urls'] ) ? $settings['company_urls'] : array(),
			'brand_items'     => isset( $settings['brand_items'] ) && is_array( $settings['brand_items'] ) ? $settings['brand_items'] : array(),
			'personas'        => isset( $settings['personas'] ) && is_array( $settings['personas'] ) ? $settings['personas'] : array(),
			'personas_on'     => ! isset( $settings['personas_on'] ) || ! empty( $settings['personas_on'] ),
			'saved_questions' => $saved_questions,
			'summaries'       => $summaries,
			'selected'        => $selected,
			'scope_options'   => $scope_options,
			'scope'           => $scope,
			'observed'        => $observed,
		);
	}

	/* ═══════════════════════════════ sections ═══════════════════════════════ */

	private static function render_header( array $d ) {
		?>
		<div class="twt-aeo-header twt-aeo-vis__header">
			<div class="twt-aeo-header__inner">
				<div>
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'AI visibility', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub"><?php esc_html_e( 'Are the assistants sending shoppers to you?', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div class="twt-aeo-header__meta">
					<span class="twt-aeo-badge twt-aeo-badge--plugin"><?php esc_html_e( 'Free — your own keys', 'twt-aeo-ultimate' ); ?></span>
					<?php if ( ! empty( $d['hosts'] ) ) : ?>
						<span class="twt-aeo-badge twt-aeo-badge--page"><?php echo esc_html( implode( ', ', array_slice( $d['hosts'], 0, 2 ) ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="twt-aeo-vis__honesty" role="note">
			<strong><?php esc_html_e( 'What these checks are — and are not.', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'These checks call the assistants’ APIs with web search on your own keys. They approximate what the consumer apps say — those personalise and change daily — and are the closest reproducible measure. A question you’re not named for is a finding with a fix, not a failure.', 'twt-aeo-ultimate' ); ?>
		</div>
		<p class="twt-aeo-vis__lede">
			<?php esc_html_e( 'Asks ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat the questions people ask about your company, your brands, your categories, your products and your posts, then records per engine whether you were cited (a link to something you own), named without a link, or absent — and who was cited instead. A citation of a profile you own counts as a citation; the board shows which surface it landed on, so you can still tell your own pages from your LinkedIn. Where we already know the answer (your policies, where you ship, your variants and prices) it also checks whether what the engine said was true. Every check is one model call with one web search on your key, so your provider bills it at their rate — this page shows counts, never dollars. Nothing runs until you press Start, and a run pauses at your daily cap rather than failing.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
	}

	/* ── Observed ── */

	private static function render_observed( array $d ) {
		$ref  = $d['observed']['referrals'];
		$gsc  = $d['observed']['gsc'];
		$bing = $d['observed']['bing'];
		$cc   = self::admin_page( 'twt-aeo-command-center' );
		?>
		<section class="twt-aeo-vis__section twt-aeo-vis__observed">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'AI referrals — who is sending visitors', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Observed · last 28 days', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-vis__observed-grid">
				<div class="twt-aeo-card twt-aeo-vis__observed-main">
					<?php if ( is_array( $ref ) && ! empty( $ref['connected'] ) && empty( $ref['error'] ) ) : ?>
						<?php
						$by    = isset( $ref['by_assistant'] ) && is_array( $ref['by_assistant'] ) ? $ref['by_assistant'] : array();
						$total = isset( $ref['total_sessions'] ) ? (int) $ref['total_sessions'] : 0;
						?>
						<div class="twt-aeo-vis__chips">
							<?php if ( empty( $by ) ) : ?>
								<span class="twt-aeo-vis__muted"><?php esc_html_e( 'No sessions from an AI assistant in this window yet.', 'twt-aeo-ultimate' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $by as $key => $row ) :
								if ( ! is_array( $row ) ) {
									continue;
								}
								$label    = isset( $row['label'] ) ? (string) $row['label'] : (string) $key;
								$sessions = isset( $row['sessions'] ) ? (int) $row['sessions'] : 0;
								$users    = isset( $row['users'] ) ? (int) $row['users'] : 0;
								?>
								<span class="twt-aeo-vis__chip twt-aeo-vis__chip--ref" title="<?php echo esc_attr( self::landing_pages_title( $row ) ); ?>">
									<strong><?php echo esc_html( $label ); ?></strong>
									<span class="twt-aeo-vis__num"><?php echo esc_html( number_format_i18n( $sessions ) ); ?></span>
									<?php esc_html_e( 'sessions', 'twt-aeo-ultimate' ); ?>
									<?php if ( $users ) : ?>
										<span class="twt-aeo-vis__muted">(<?php echo esc_html( number_format_i18n( $users ) ); ?> <?php esc_html_e( 'users', 'twt-aeo-ultimate' ); ?>)</span>
									<?php endif; ?>
								</span>
							<?php endforeach; ?>
						</div>
						<p class="twt-aeo-vis__observed-total">
							<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
							<?php esc_html_e( 'sessions from AI assistants', 'twt-aeo-ultimate' ); ?>
							<?php if ( ! empty( $ref['property'] ) ) : ?>
								<span class="twt-aeo-vis__muted">· GA4 <?php echo esc_html( (string) $ref['property'] ); ?></span>
							<?php endif; ?>
						</p>
						<p class="twt-aeo-vis__muted twt-aeo-vis__explainer">
							<?php esc_html_e( 'Google Analytics can tell us an AI assistant sent the visit; it cannot tell us which answer or which citation. That is what the probes below are for.', 'twt-aeo-ultimate' ); ?>
						</p>
					<?php else : ?>
						<p class="twt-aeo-vis__observed-empty">
							<strong><?php esc_html_e( 'Google Analytics is not connected.', 'twt-aeo-ultimate' ); ?></strong>
							<?php esc_html_e( 'Connect it once and this strip shows how many sessions each assistant (ChatGPT, Perplexity, Gemini, Claude, Copilot…) sent you.', 'twt-aeo-ultimate' ); ?>
							<?php if ( is_array( $ref ) && ! empty( $ref['error'] ) ) : ?>
								<span class="twt-aeo-vis__muted"><?php echo esc_html( (string) $ref['error'] ); ?></span>
							<?php endif; ?>
						</p>
						<a class="button button-secondary" href="<?php echo esc_url( $cc ); ?>"><?php esc_html_e( 'Connect Google in the Command Center', 'twt-aeo-ultimate' ); ?></a>
						<p class="twt-aeo-vis__muted twt-aeo-vis__explainer">
							<?php esc_html_e( 'Google Analytics can tell us an AI assistant sent the visit; it cannot tell us which answer or which citation. That is what the probes below are for.', 'twt-aeo-ultimate' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<?php self::render_observed_side( __( 'Search Console — top queries', 'twt-aeo-ultimate' ), $gsc, $cc, __( 'Connect Search Console', 'twt-aeo-ultimate' ) ); ?>
				<?php self::render_observed_side( __( 'Bing Webmaster', 'twt-aeo-ultimate' ), $bing, $cc, __( 'Connect Bing Webmaster', 'twt-aeo-ultimate' ) ); ?>
			</div>
		</section>
		<?php
	}

	/** Hover text for a referral chip: its landing pages. */
	private static function landing_pages_title( array $row ) {
		$pages = isset( $row['landing_pages'] ) && is_array( $row['landing_pages'] ) ? $row['landing_pages'] : array();
		$bits  = array();
		foreach ( array_slice( $pages, 0, 6 ) as $p ) {
			if ( is_array( $p ) && isset( $p[0] ) ) {
				$bits[] = (string) $p[0] . ( isset( $p[1] ) ? ' (' . (int) $p[1] . ')' : '' );
			} elseif ( is_array( $p ) && isset( $p['path'] ) ) {
				$bits[] = (string) $p['path'] . ( isset( $p['sessions'] ) ? ' (' . (int) $p['sessions'] . ')' : '' );
			}
		}
		/* translators: %s: comma-separated list of landing pages. */
		return $bits ? sprintf( __( 'Landing pages: %s', 'twt-aeo-ultimate' ), implode( ', ', $bits ) ) : '';
	}

	/**
	 * A compact card for Search Console / Bing. The Observed class decides the
	 * shape; we render whatever it hands us: a `connected` flag, an optional
	 * `error`, and any of `queries` / `rows` / `top` (lists of [query|label,
	 * clicks, impressions] or assoc rows), plus scalar totals.
	 */
	private static function render_observed_side( $title, $data, $connect_url, $connect_label ) {
		?>
		<div class="twt-aeo-card twt-aeo-vis__observed-side">
			<h3 class="twt-aeo-card__title"><?php echo esc_html( $title ); ?></h3>
			<?php if ( ! is_array( $data ) || empty( $data['connected'] ) ) : ?>
				<p class="twt-aeo-card__note"><?php esc_html_e( 'Not connected.', 'twt-aeo-ultimate' ); ?>
					<?php if ( is_array( $data ) && ! empty( $data['error'] ) ) : ?>
						<?php echo esc_html( (string) $data['error'] ); ?>
					<?php endif; ?>
				</p>
				<a class="twt-aeo-link" href="<?php echo esc_url( $connect_url ); ?>"><?php echo esc_html( $connect_label ); ?> →</a>
			<?php else : ?>
				<?php
				$list = array();
				foreach ( array( 'queries', 'rows', 'top', 'top_queries', 'pages' ) as $k ) {
					if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) && ! empty( $data[ $k ] ) ) {
						$list = $data[ $k ];
						break;
					}
				}
				$scalars = array();
				foreach ( array( 'clicks', 'impressions', 'total_clicks', 'total_impressions', 'ctr', 'position', 'citations', 'ai_citations' ) as $k ) {
					if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
						$scalars[ $k ] = $data[ $k ];
					}
				}
				?>
				<?php if ( $scalars ) : ?>
					<p class="twt-aeo-vis__muted twt-aeo-vis__scalars">
						<?php foreach ( $scalars as $k => $v ) : ?>
							<span><?php echo esc_html( str_replace( '_', ' ', $k ) ); ?> <strong><?php echo esc_html( is_numeric( $v ) ? number_format_i18n( (float) $v, ( 'ctr' === $k || 'position' === $k ) ? 1 : 0 ) : (string) $v ); ?></strong></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<?php if ( $list ) : ?>
					<ol class="twt-aeo-vis__querylist">
						<?php foreach ( array_slice( $list, 0, 5 ) as $row ) :
							$label = '';
							$n1    = null;
							$n2    = null;
							if ( is_array( $row ) ) {
								$label = isset( $row['query'] ) ? $row['query'] : ( isset( $row['label'] ) ? $row['label'] : ( isset( $row['page'] ) ? $row['page'] : ( isset( $row[0] ) ? $row[0] : '' ) ) );
								$n1    = isset( $row['clicks'] ) ? $row['clicks'] : ( isset( $row[1] ) ? $row[1] : null );
								$n2    = isset( $row['impressions'] ) ? $row['impressions'] : ( isset( $row[2] ) ? $row[2] : null );
							} elseif ( is_scalar( $row ) ) {
								$label = (string) $row;
							}
							if ( '' === (string) $label ) {
								continue;
							}
							?>
							<li>
								<span class="twt-aeo-vis__q"><?php echo esc_html( (string) $label ); ?></span>
								<?php if ( null !== $n1 ) : ?><span class="twt-aeo-vis__num"><?php echo esc_html( number_format_i18n( (float) $n1 ) ); ?></span><?php endif; ?>
								<?php if ( null !== $n2 ) : ?><span class="twt-aeo-vis__muted"><?php echo esc_html( number_format_i18n( (float) $n2 ) ); ?> <?php esc_html_e( 'impr.', 'twt-aeo-ultimate' ); ?></span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php elseif ( ! $scalars ) : ?>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'Connected — no rows in this window yet.', 'twt-aeo-ultimate' ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $data['url'] ) ) : ?>
					<a class="twt-aeo-link" href="<?php echo esc_url( (string) $data['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open report ↗', 'twt-aeo-ultimate' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ── Engines ── */

	private static function render_engines( array $d ) {
		$settings_url = self::admin_page( 'twt-aeo-settings' );
		?>
		<section class="twt-aeo-vis__section">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Engines', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'All on your own keys', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-card">
				<ul class="twt-aeo-vis__engines">
					<?php foreach ( TWTAEO_Visibility_Types::ENGINES as $id => $meta ) :
						$v1        = in_array( $id, TWTAEO_Visibility_Types::ENGINES_V1, true );
						$row       = isset( $d['engines'][ $id ] ) ? $d['engines'][ $id ] : array();
						$available = $v1 && ! empty( $row['available'] );
						$reason    = isset( $row['reason'] ) ? (string) $row['reason'] : '';
						$model     = isset( TWTAEO_Visibility_Types::MODELS[ $id ] ) ? TWTAEO_Visibility_Types::MODELS[ $id ] : '';
						?>
						<li class="twt-aeo-vis__engine<?php echo $v1 ? '' : ' twt-aeo-vis__engine--soon'; ?>">
							<span class="twt-aeo-vis__engine-name"><?php echo esc_html( $meta['label'] ); ?></span>
							<?php if ( ! $v1 ) : ?>
								<span class="twt-aeo-vis__pill twt-aeo-vis__pill--soon"><?php esc_html_e( 'coming soon', 'twt-aeo-ultimate' ); ?></span>
							<?php elseif ( $available ) : ?>
								<span class="twt-aeo-vis__pill twt-aeo-vis__pill--ok"><?php esc_html_e( 'Key on file', 'twt-aeo-ultimate' ); ?></span>
							<?php else : ?>
								<span class="twt-aeo-vis__pill twt-aeo-vis__pill--off"><?php esc_html_e( 'No key', 'twt-aeo-ultimate' ); ?></span>
								<span class="twt-aeo-vis__muted">
									<?php echo $reason ? esc_html( $reason ) . ' — ' : ''; ?>
									<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'add it in Settings', 'twt-aeo-ultimate' ); ?></a>
								</span>
							<?php endif; ?>
							<?php if ( 'perplexity' === $id ) : ?>
								<span class="twt-aeo-vis__muted"><?php esc_html_e( 'optional', 'twt-aeo-ultimate' ); ?></span>
							<?php endif; ?>
							<?php if ( $model && $v1 ) : ?>
								<code class="twt-aeo-vis__model" title="<?php esc_attr_e( 'The consumer app’s default model — what a shopper’s phone runs', 'twt-aeo-ultimate' ); ?>"><?php echo esc_html( $model ); ?></code>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'A key on file means we can ask that engine. Whether that key’s tier allows web search is only known once a check runs — an engine that refuses is recorded as unavailable for that check, never as absent.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
		</section>
		<?php
	}

	/* ── Brand & company tracking ── */

	/**
	 * Who counts as "us" when an answer cites somebody.
	 *
	 * The verdict used to ask only "is this our domain?", which scored a
	 * citation of our own LinkedIn page as somebody else's — and filed it under
	 * competitors in share of voice. Everything registered here is read as us.
	 */
	private static function render_brands( array $d ) {
		// The Company profile is rendered inside the E-E-A-T page (see class-page-eeat.php).
		$company_url = self::admin_page( 'twt-aeo-eeat' );
		$auto        = isset( $d['company_auto'] ) ? (array) $d['company_auto'] : array();
		$extra       = isset( $d['company_extra'] ) ? (array) $d['company_extra'] : array();
		$items       = isset( $d['brand_items'] ) ? (array) $d['brand_items'] : array();
		$hosts       = isset( $d['hosts'] ) ? (array) $d['hosts'] : array();
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-brands">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'What counts as you', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Company items and brand items', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-card">
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'Being cited on a profile you own is still being cited. Register those profiles here and an answer that links your LinkedIn page counts as a citation instead of counting as a competitor. The board still shows which surface was cited, so you can tell “my own domain got picked up” from “only my profiles did”.', 'twt-aeo-ultimate' ); ?>
				</p>

				<form id="twt-aeo-vis-brands-form">

					<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Company', 'twt-aeo-ultimate' ); ?></h3>

					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'Your own hosts, always counted as you:', 'twt-aeo-ultimate' ); ?>
						<?php foreach ( $hosts as $h ) : ?>
							<code class="twt-aeo-vis__model"><?php echo esc_html( (string) $h ); ?></code>
						<?php endforeach; ?>
					</p>

					<?php if ( ! empty( $auto ) ) : ?>
						<p class="twt-aeo-vis__muted">
							<?php esc_html_e( 'Found on your Company profile and counted automatically:', 'twt-aeo-ultimate' ); ?>
							<?php foreach ( $auto as $u ) : ?>
								<code class="twt-aeo-vis__model"><?php echo esc_html( (string) $u ); ?></code>
							<?php endforeach; ?>
							<a href="<?php echo esc_url( $company_url ); ?>"><?php esc_html_e( 'edit them', 'twt-aeo-ultimate' ); ?></a>
						</p>
					<?php else : ?>
						<p class="twt-aeo-vis__muted">
							<?php esc_html_e( 'No social profiles are on file yet.', 'twt-aeo-ultimate' ); ?>
							<a href="<?php echo esc_url( $company_url ); ?>"><?php esc_html_e( 'Add them to your Company profile', 'twt-aeo-ultimate' ); ?></a>
							<?php esc_html_e( '— they feed your schema as well, and they will be counted here automatically.', 'twt-aeo-ultimate' ); ?>
						</p>
					<?php endif; ?>

					<label class="twt-aeo-vis__field">
						<span><?php esc_html_e( 'Other sites and profiles the company owns (one per line)', 'twt-aeo-ultimate' ); ?></span>
						<textarea name="company_urls" rows="3" class="large-text code" placeholder="https://yourotherdomain.com&#10;https://wordpress.org/plugins/your-plugin/&#10;https://github.com/your-org"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $extra ) ) ); ?></textarea>
						<span class="twt-aeo-vis__muted"><?php esc_html_e( 'A domain of your own goes in whole — every page under it counts. On a shared platform (LinkedIn, X, YouTube, GitHub…) paste the full page address instead: your page there is yours, the platform is not, and the bare domain would count every citation on it as yours.', 'twt-aeo-ultimate' ); ?></span>
					</label>

					<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Brands', 'twt-aeo-ultimate' ); ?></h3>
					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'A product or sub-brand you want tracked in its own right. Give it a phrase to watch for and whatever it owns — its own website, its pages on other platforms, or both. A channel the brand does not have is simply left blank; it is never reported as a gap.', 'twt-aeo-ultimate' ); ?>
					</p>

					<div id="twt-aeo-vis-brand-rows">
						<?php foreach ( $items as $i => $item ) : ?>
							<?php self::render_brand_row( (int) $i, is_array( $item ) ? $item : array() ); ?>
						<?php endforeach; ?>
					</div>

					<script type="text/template" id="twt-aeo-vis-brand-tpl">
						<?php self::render_brand_row( 0, array(), true ); ?>
					</script>

					<div class="twt-aeo-vis__actions">
						<button type="button" class="button" id="twt-aeo-vis-brand-add"><?php esc_html_e( 'Add a brand', 'twt-aeo-ultimate' ); ?></button>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save tracking', 'twt-aeo-ultimate' ); ?></button>
						<span class="twt-aeo-vis__status" id="twt-aeo-vis-brands-status" aria-live="polite"></span>
					</div>
					<div class="twt-aeo-vis__rejects" id="twt-aeo-vis-brand-rejects" hidden></div>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * One brand row. `$tpl` renders the blank template JS clones, with `__i__`
	 * where the index goes.
	 *
	 * @param int   $index Row index.
	 * @param array $item  Stored item.
	 * @param bool  $tpl   Template mode.
	 */
	private static function render_brand_row( $index, array $item, $tpl = false ) {
		$i       = $tpl ? '__i__' : (string) (int) $index;
		$label   = isset( $item['label'] ) ? (string) $item['label'] : '';
		$phrases = isset( $item['phrases'] ) && is_array( $item['phrases'] ) ? $item['phrases'] : array();
		$urls    = isset( $item['urls'] ) && is_array( $item['urls'] ) ? $item['urls'] : array();
		$on      = ! isset( $item['enabled'] ) || ! empty( $item['enabled'] );
		?>
		<div class="twt-aeo-vis__brand-row" data-brand-row>
			<div class="twt-aeo-vis__brand-grid">
				<label class="twt-aeo-vis__field">
					<span><?php esc_html_e( 'Name', 'twt-aeo-ultimate' ); ?></span>
					<input type="text" data-name="label" name="brand_items[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'AEO Ultimate', 'twt-aeo-ultimate' ); ?>" class="regular-text">
				</label>
				<label class="twt-aeo-vis__field">
					<span><?php esc_html_e( 'Phrases to watch for (one per line)', 'twt-aeo-ultimate' ); ?></span>
					<textarea data-name="phrases" name="brand_items[<?php echo esc_attr( $i ); ?>][phrases]" rows="3" class="large-text code" placeholder="&quot;AEO Ultimate&quot;&#10;AEO Ultimate plugin"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $phrases ) ) ); ?></textarea>
					<span class="twt-aeo-vis__muted"><?php esc_html_e( 'In quotes = that exact phrase. Without quotes = every word of three letters or more, in any order — looser, so it also catches “the Ultimate AEO plugin”. Whole words either way: “Ace” never matches “Aceto”.', 'twt-aeo-ultimate' ); ?></span>
				</label>
				<label class="twt-aeo-vis__field">
					<span><?php esc_html_e( 'Website and profiles this brand owns (one per line)', 'twt-aeo-ultimate' ); ?></span>
					<textarea data-name="urls" name="brand_items[<?php echo esc_attr( $i ); ?>][urls]" rows="3" class="large-text code" placeholder="https://aeoultimate.com&#10;https://www.linkedin.com/company/aeo-ultimate"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $urls ) ) ); ?></textarea>
					<span class="twt-aeo-vis__muted"><?php esc_html_e( 'A brand with its own website goes in whole. Its pages on shared platforms need the full address.', 'twt-aeo-ultimate' ); ?></span>
				</label>
			</div>
			<div class="twt-aeo-vis__brand-foot">
				<label>
					<input type="checkbox" data-name="enabled" name="brand_items[<?php echo esc_attr( $i ); ?>][enabled]"<?php checked( $on ); ?>>
					<?php esc_html_e( 'Track this brand', 'twt-aeo-ultimate' ); ?>
				</label>
				<button type="button" class="button-link twt-aeo-vis__brand-remove" data-brand-remove><?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ── Personas ── */

	private static function render_personas( array $d ) {
		$personas   = isset( $d['personas'] ) ? (array) $d['personas'] : array();
		$on         = ! empty( $d['personas_on'] );
		$local_pack = TWTAEO_Visibility_Personas::module_active( 'local-pack' );
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-personas">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Who is asking', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Buyer personas', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-card">
				<label class="twt-aeo-vis__switch">
					<input type="checkbox" id="twt-aeo-vis-personas-on" <?php checked( $on ); ?>>
					<strong><?php esc_html_e( 'Use buyer personas', 'twt-aeo-ultimate' ); ?></strong>
					<span class="twt-aeo-vis__status" id="twt-aeo-vis-personas-on-status" aria-live="polite"></span>
				</label>
				<?php if ( ! $on ) : ?>
					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'Off: every run asks as nobody in particular, and no personas are suggested. Follow-up questions are still recorded. Your saved personas are kept for when you switch this back on.', 'twt-aeo-ultimate' ); ?>
					</p>
				<?php else : ?>
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'The consumer assistants know things about the person asking, and their answers change with it. A run can be asked as one of your buyers — the persona goes to the assistant as background about the user, the way the apps do it, and the question itself is sent exactly as written. Each answer also comes back with the follow-up questions that engine expects this person to ask next.', 'twt-aeo-ultimate' ); ?>
				</p>
				<?php if ( empty( $personas ) ) : ?>
					<div class="twt-aeo-vis__notice">
						<strong><?php esc_html_e( 'Add your buyer personas to see how answers change for different customers.', 'twt-aeo-ultimate' ); ?></strong>
						<?php esc_html_e( 'Runs still work without them — they ask as nobody in particular, and follow-ups are recorded either way. Type your own, or let the plugin suggest some from your main pages.', 'twt-aeo-ultimate' ); ?>
					</div>
				<?php endif; ?>
				<?php self::render_persona_editor( $personas, true ); ?>
				<?php if ( $local_pack ) : ?>
					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'The same list is on', 'twt-aeo-ultimate' ); ?>
						<a href="<?php echo esc_url( self::admin_page( 'twt-aeo-local-pack' ) ); ?>"><?php esc_html_e( 'Local Pack → Business Profile', 'twt-aeo-ultimate' ); ?></a>
						<?php esc_html_e( '— edit it in either place.', 'twt-aeo-ultimate' ); ?>
					</p>
				<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The persona tag input, shared with the Local Pack page (driven by
	 * persona-tags.js). With `$own_save` the editor saves itself over AJAX;
	 * without it the hidden `twtaeo_personas` field rides the surrounding form.
	 *
	 * @param array $personas Persona[].
	 * @param bool  $own_save Render a Save button.
	 */
	public static function render_persona_editor( array $personas, $own_save ) {
		?>
		<div class="twt-aeo-personas" data-personas data-own-save="<?php echo $own_save ? '1' : '0'; ?>">
			<div class="twt-aeo-personas__box" data-persona-box>
				<?php foreach ( $personas as $p ) :
					if ( ! is_array( $p ) || empty( $p['label'] ) ) {
						continue;
					}
					$site = isset( $p['source'] ) && 'site' === $p['source'];
					?>
					<span class="twt-aeo-personas__tag<?php echo $site ? ' is-site' : ''; ?>" data-persona-tag data-label="<?php echo esc_attr( (string) $p['label'] ); ?>" data-source="<?php echo $site ? 'site' : 'owner'; ?>">
						<button type="button" class="twt-aeo-personas__text" data-persona-edit title="<?php esc_attr_e( 'Click to edit', 'twt-aeo-ultimate' ); ?>"><?php echo esc_html( (string) $p['label'] ); ?></button>
						<?php if ( $site ) : ?><span class="twt-aeo-personas__src"><?php esc_html_e( 'suggested from your site', 'twt-aeo-ultimate' ); ?></span><?php endif; ?>
						<?php /* translators: %s: persona label. */ ?>
						<button type="button" class="twt-aeo-personas__remove" data-persona-remove aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'twt-aeo-ultimate' ), (string) $p['label'] ) ); ?>">&times;</button>
					</span>
				<?php endforeach; ?>
				<input type="text" class="twt-aeo-personas__input" data-persona-input aria-label="<?php esc_attr_e( 'Add a buyer persona', 'twt-aeo-ultimate' ); ?>" placeholder="<?php esc_attr_e( 'Type a persona and press Enter — e.g. machine shop owner', 'twt-aeo-ultimate' ); ?>">
			</div>
			<input type="hidden" name="twtaeo_personas" data-persona-json value="<?php echo esc_attr( wp_json_encode( array_values( $personas ) ) ); ?>">
			<p class="description"><?php esc_html_e( 'Enter or a comma adds one; paste a comma-separated list to add several. Click a persona to edit it. Up to 10 — each run is asked as one of them.', 'twt-aeo-ultimate' ); ?></p>
			<div class="twt-aeo-personas__actions">
				<?php if ( $own_save ) : ?>
					<button type="button" class="button button-primary" data-persona-save><?php esc_html_e( 'Save personas', 'twt-aeo-ultimate' ); ?></button>
				<?php endif; ?>
				<button type="button" class="button" data-persona-suggest><?php esc_html_e( 'Suggest personas from my site', 'twt-aeo-ultimate' ); ?></button>
				<span class="twt-aeo-personas__status" data-persona-status aria-live="polite"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * "Search from": Automatic (the site's own location, and where it came
	 * from), No location, or a country with a region (a list for the
	 * English-first markets, typed elsewhere) and a typed city.
	 */
	private static function render_location_pick() {
		if ( ! class_exists( 'TWTAEO_Visibility_Location' ) ) {
			return;
		}
		$default   = TWTAEO_Visibility_Location::site_default();
		$countries = TWTAEO_Visibility_Location::countries();
		$sources   = array(
			'local_pack' => __( 'your Local Pack address', 'twt-aeo-ultimate' ),
			'store'      => __( 'your store country', 'twt-aeo-ultimate' ),
			'language'   => __( 'your site language', 'twt-aeo-ultimate' ),
		);
		// Region lists for the markets that get one, keyed by country code.
		$regions = array();
		foreach ( array_keys( TWTAEO_Visibility_Location::REGIONS ) as $cc ) {
			$regions[ $cc ] = array_values( TWTAEO_Visibility_Location::regions( $cc ) );
		}
		if ( $default && ! isset( $regions[ $default['country'] ] ) ) {
			$list = array_values( TWTAEO_Visibility_Location::regions( $default['country'] ) );
			if ( $list ) {
				$regions[ $default['country'] ] = $list;
			}
		}
		// English-first markets first, then every country.
		$first = array( 'US', 'CA', 'GB', 'AU', 'NZ', 'IE', 'IN', 'SG', 'ZA' );
		?>
		<div class="twt-aeo-vis__persona-pick twt-aeo-vis__location-pick" data-regions="<?php echo esc_attr( wp_json_encode( $regions ) ); ?>">
			<label for="twt-aeo-vis-loc-country"><?php esc_html_e( 'Search from', 'twt-aeo-ultimate' ); ?></label>
			<select id="twt-aeo-vis-loc-country">
				<?php if ( $default ) : ?>
					<option value="auto">
						<?php
						/* translators: 1: a place, 2: where it came from, e.g. "your Local Pack address". */
						echo esc_html( sprintf( __( 'Automatic: %1$s (%2$s)', 'twt-aeo-ultimate' ), TWTAEO_Visibility_Location::label( $default ), isset( $sources[ $default['source'] ] ) ? $sources[ $default['source'] ] : '' ) );
						?>
					</option>
				<?php endif; ?>
				<option value="none"><?php esc_html_e( 'No location', 'twt-aeo-ultimate' ); ?></option>
				<optgroup label="<?php esc_attr_e( 'English-speaking markets', 'twt-aeo-ultimate' ); ?>">
					<?php foreach ( $first as $cc ) : ?>
						<?php if ( isset( $countries[ $cc ] ) ) : ?>
							<option value="<?php echo esc_attr( $cc ); ?>"><?php echo esc_html( $countries[ $cc ] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'All countries', 'twt-aeo-ultimate' ); ?>">
					<?php foreach ( $countries as $cc => $name ) : ?>
						<option value="<?php echo esc_attr( $cc ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			</select>
			<span class="twt-aeo-vis__location-detail" hidden>
				<select id="twt-aeo-vis-loc-region-select" aria-label="<?php esc_attr_e( 'State or region', 'twt-aeo-ultimate' ); ?>" hidden></select>
				<input type="text" id="twt-aeo-vis-loc-region-text" placeholder="<?php esc_attr_e( 'State or region (optional)', 'twt-aeo-ultimate' ); ?>" aria-label="<?php esc_attr_e( 'State or region', 'twt-aeo-ultimate' ); ?>" hidden>
				<input type="text" id="twt-aeo-vis-loc-city" placeholder="<?php esc_attr_e( 'City (optional)', 'twt-aeo-ultimate' ); ?>" aria-label="<?php esc_attr_e( 'City', 'twt-aeo-ultimate' ); ?>">
			</span>
			<span class="twt-aeo-vis__muted"><?php esc_html_e( 'The consumer apps know roughly where the person asking is, and search results change with it. Without a location, a check searches from wherever the provider\'s servers are. Automatic uses your business\'s own location; one location per run, so to see several markets or branches, run once for each. Claude, ChatGPT, Perplexity, DeepSeek and Muse search from the location; Gemini, Grok and Le Chat have no search location in their APIs, so they are only told where the user is.', 'twt-aeo-ultimate' ); ?></span>
		</div>
		<?php
	}

	/** A run's (or summary's) persona id; '' for the baseline. */
	private static function persona_key( $run ) {
		return is_array( $run ) && isset( $run['persona']['id'] ) ? (string) $run['persona']['id'] : '';
	}

	/** A run's (or summary's) location; null when it had none. */
	private static function run_location( $run ) {
		if ( ! is_array( $run ) ) {
			return null;
		}
		if ( ! empty( $run['location'] ) && is_array( $run['location'] ) ) {
			return $run['location'];
		}
		return ! empty( $run['allocation']['location'] ) && is_array( $run['allocation']['location'] ) ? $run['allocation']['location'] : null;
	}

	/** "Tampa, Florida, United States"; '' when the run had no location. */
	private static function location_label( $run ) {
		return class_exists( 'TWTAEO_Visibility_Location' ) ? TWTAEO_Visibility_Location::label( self::run_location( $run ) ) : '';
	}

	/** What two runs must share to be compared: persona and location. */
	private static function compare_key( $run ) {
		$loc = class_exists( 'TWTAEO_Visibility_Location' ) ? TWTAEO_Visibility_Location::key( self::run_location( $run ) ) : '';
		return self::persona_key( $run ) . '#' . $loc;
	}

	/** A run's persona label; '' for the baseline. */
	private static function persona_label( $run ) {
		return is_array( $run ) && isset( $run['persona']['label'] ) ? (string) $run['persona']['label'] : '';
	}

	/* ── Allocation ── */

	private static function render_allocation( array $d ) {
		$alloc  = $d['alloc'];
		$counts = $d['counts'];
		$totals = $d['totals'];
		$cap    = (int) $d['cap'];
		$checks = isset( $totals['checks'] ) ? (int) $totals['checks'] : 0;
		$days   = $cap > 0 ? max( 1, (int) ceil( $checks / $cap ) ) : 0;
		$fields = array(
			'company'        => array( __( 'Company', 'twt-aeo-ultimate' ), '' ),
			'per_brand'      => array( __( 'Per brand', 'twt-aeo-ultimate' ), (string) ( isset( $counts['brands'] ) ? $counts['brands'] : 0 ) ),
			'per_collection' => array( __( 'Per category', 'twt-aeo-ultimate' ), (string) $counts['collections'] ),
			'per_type'       => array( __( 'Per product type', 'twt-aeo-ultimate' ), (string) $counts['types'] ),
			'per_product'    => array( __( 'Per product', 'twt-aeo-ultimate' ), (string) $counts['products'] ),
			'per_post'       => array( __( 'Per post', 'twt-aeo-ultimate' ), (string) $counts['posts'] ),
		);
		$settings = $d['settings'];
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-allocation">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Allocation — how many questions, where', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'You see the size of a run before anything is spent', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<form class="twt-aeo-card twt-aeo-vis__alloc" id="twt-aeo-vis-alloc-form"
				data-brands="<?php echo esc_attr( isset( $counts['brands'] ) ? (int) $counts['brands'] : 0 ); ?>"
				data-collections="<?php echo esc_attr( $counts['collections'] ); ?>"
				data-types="<?php echo esc_attr( $counts['types'] ); ?>"
				data-products="<?php echo esc_attr( $counts['products'] ); ?>"
				data-posts="<?php echo esc_attr( $counts['posts'] ); ?>"
				data-cap="<?php echo esc_attr( $cap ); ?>"
				data-cap-used="<?php echo esc_attr( (int) $d['cap_used'] ); ?>">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'Questions per item at each level. The bar shows where a run’s questions go; the line under it is the arithmetic.', 'twt-aeo-ultimate' ); ?></p>

				<div class="twt-aeo-vis__presets">
					<?php foreach ( TWTAEO_Visibility_Types::PRESETS as $name => $p ) : ?>
						<button type="button" class="button twt-aeo-vis__preset" data-preset="<?php echo esc_attr( wp_json_encode( $p ) ); ?>">
							<?php echo esc_html( ucfirst( $name ) ); ?>
							<span class="twt-aeo-vis__muted"><?php echo esc_html( $p['company'] . '·' . $p['per_brand'] . '·' . $p['per_collection'] . '·' . $p['per_type'] . '·' . $p['per_product'] . '·' . $p['per_post'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="twt-aeo-vis__alloc-fields">
					<?php foreach ( $fields as $key => $meta ) : ?>
						<label class="twt-aeo-vis__field" style="--level:<?php echo esc_attr( self::level_color_for_field( $key ) ); ?>">
							<span class="twt-aeo-vis__field-label"><?php echo esc_html( $meta[0] ); ?>
								<?php if ( '' !== $meta[1] ) : ?><span class="twt-aeo-vis__muted">(<?php echo esc_html( $meta[1] ); ?>)</span><?php endif; ?>
							</span>
							<input type="number" name="<?php echo esc_attr( $key ); ?>" min="0"
								max="<?php echo esc_attr( TWTAEO_Visibility_Types::ALLOCATION_MAX[ $key ] ); ?>"
								value="<?php echo esc_attr( (int) $alloc[ $key ] ); ?>" class="small-text twt-aeo-vis__alloc-input">
						</label>
					<?php endforeach; ?>
				</div>

				<div class="twt-aeo-vis__engine-ticks">
					<?php foreach ( TWTAEO_Visibility_Types::ENGINES_V1 as $id ) :
						$on        = in_array( $id, $alloc['engines'], true );
						$available = in_array( $id, $d['available_ids'], true );
						?>
						<label class="twt-aeo-vis__tick<?php echo $available ? '' : ' is-disabled'; ?>">
							<input type="checkbox" name="engine[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $on && $available ); ?> <?php disabled( ! $available ); ?> data-available="<?php echo $available ? '1' : '0'; ?>">
							<?php echo esc_html( TWTAEO_Visibility_Types::ENGINES[ $id ]['label'] ); ?>
							<?php if ( ! $available ) : ?><span class="twt-aeo-vis__muted">(<?php esc_html_e( 'no key', 'twt-aeo-ultimate' ); ?>)</span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="twt-aeo-vis__bar" id="twt-aeo-vis-bar">
					<?php self::echo_svg( self::svg_allocation_bar( $totals['by_level'] ) ); ?>
				</div>
				<p class="twt-aeo-vis__arith" id="twt-aeo-vis-arith">
					<strong>
						<?php
						printf(
							/* translators: 1: questions, 2: engines, 3: checks */
							esc_html__( '%1$s questions × %2$s engines = %3$s checks', 'twt-aeo-ultimate' ),
							'<span data-q>' . esc_html( number_format_i18n( (int) $totals['questions'] ) ) . '</span>',
							'<span data-e>' . esc_html( count( $alloc['engines'] ) ) . '</span>',
							'<span data-c>' . esc_html( number_format_i18n( $checks ) ) . '</span>'
						);
						?>
					</strong>
					· <?php esc_html_e( 'today’s cap', 'twt-aeo-ultimate' ); ?> <?php echo esc_html( number_format_i18n( $cap ) ); ?>
					(<?php echo esc_html( number_format_i18n( (int) $d['cap_used'] ) ); ?> <?php esc_html_e( 'used', 'twt-aeo-ultimate' ); ?>)
					· <?php esc_html_e( 'fits in', 'twt-aeo-ultimate' ); ?> <span data-days>
					<?php /* translators: %d: number of days this run needs at the current daily cap. */ ?>
					<?php echo $days ? esc_html( sprintf( _n( '%d day', '%d days', $days, 'twt-aeo-ultimate' ), $days ) ) : esc_html__( '— (cap is 0)', 'twt-aeo-ultimate' ); ?></span>
				</p>

				<details class="twt-aeo-vis__more">
					<summary><?php esc_html_e( 'Cap, X handle and alternate hosts', 'twt-aeo-ultimate' ); ?></summary>
					<div class="twt-aeo-vis__more-grid">
						<label><?php esc_html_e( 'Daily cap (checks per day)', 'twt-aeo-ultimate' ); ?>
							<input type="number" name="daily_cap" min="0" value="<?php echo esc_attr( isset( $settings['daily_cap'] ) ? (int) $settings['daily_cap'] : $cap ); ?>" class="small-text">
						</label>
						<label><?php esc_html_e( 'X (Twitter) handle', 'twt-aeo-ultimate' ); ?>
							<input type="text" name="x_handle" value="<?php echo esc_attr( isset( $settings['x_handle'] ) ? (string) $settings['x_handle'] : '' ); ?>" placeholder="@yourstore" class="regular-text">
						</label>
						<label><?php esc_html_e( 'Alternate hosts that are also you (comma-separated)', 'twt-aeo-ultimate' ); ?>
							<input type="text" name="alternate_hosts" value="<?php echo esc_attr( isset( $settings['alternate_hosts'] ) && is_array( $settings['alternate_hosts'] ) ? implode( ', ', $settings['alternate_hosts'] ) : '' ); ?>" placeholder="shop.example.com, example.co.uk" class="regular-text">
						</label>
					</div>
				</details>

				<div class="twt-aeo-vis__actions">
					<button type="submit" class="button button-primary" id="twt-aeo-vis-save"><?php esc_html_e( 'Save allocation', 'twt-aeo-ultimate' ); ?></button>
					<span class="twt-aeo-vis__status" id="twt-aeo-vis-save-status" aria-live="polite"></span>
				</div>
			</form>
		</section>
		<?php
	}

	private static function level_color_for_field( $field ) {
		$map = array(
			'company'        => 'company',
			'per_brand'      => 'brand',
			'per_collection' => 'collection',
			'per_type'       => 'type',
			'per_product'    => 'product',
			'per_post'       => 'post',
		);
		return self::LEVEL_COLOR[ $map[ $field ] ];
	}

	/* ── Questions ── */

	private static function render_questions( array $d ) {
		$saved = $d['saved_questions'];
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-questions">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Questions', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Nothing is asked that you have not seen here first', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'Questions are filled from templates using data we already hold — site name, policies, categories, product types, products and their variants, posts — so runs stay comparable week to week and engine to engine. Building the list reads your catalogue and spends nothing. Untick anything you do not want asked.', 'twt-aeo-ultimate' ); ?></p>
				<div class="twt-aeo-vis__actions">
					<button type="button" class="button" id="twt-aeo-vis-preview"><?php echo $saved ? esc_html__( 'Rebuild from templates', 'twt-aeo-ultimate' ) : esc_html__( 'Build the question list', 'twt-aeo-ultimate' ); ?></button>
					<?php if ( $saved ) : ?>
						<button type="button" class="button" id="twt-aeo-vis-polish" title="<?php esc_attr_e( 'Post titles dropped into templates can read clumsily. This sends the post questions to your enrichment AI provider (the one behind meta descriptions) and keeps the natural rewrites — a few cheap calls, nothing web-searched, nothing run.', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Polish awkward questions with AI', 'twt-aeo-ultimate' ); ?></button>
						<button type="button" class="button-link" id="twt-aeo-vis-reset-q"><?php esc_html_e( 'Reset to templates', 'twt-aeo-ultimate' ); ?></button>
					<?php endif; ?>
					<span class="twt-aeo-vis__status" id="twt-aeo-vis-q-status" aria-live="polite"></span>
				</div>
				<form id="twt-aeo-vis-q-form" class="twt-aeo-vis__qform">
					<div id="twt-aeo-vis-q-list">
						<?php if ( $saved ) : ?>
							<?php self::echo_html( self::render_question_list( $saved ) ); ?>
						<?php else : ?>
							<p class="twt-aeo-vis__muted"><?php esc_html_e( 'No question set yet — build one from the templates above. A run without a saved set uses the templates for the saved allocation.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</div>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * The collapsed question list (summary line + per-level boxes). Returned as
	 * a string so the preview AJAX can hand the same markup back to the page.
	 * Public because TWTAEO_Visibility::ajax_preview calls it.
	 *
	 * @param array $questions Question[].
	 * @return string HTML.
	 */
	public static function render_question_list( array $questions ) {
		$grouped = array();
		foreach ( TWTAEO_Visibility_Types::LEVELS as $level ) {
			$grouped[ $level ] = array();
		}
		$enabled = 0;
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['level'], $grouped[ $q['level'] ] ) ) {
				continue;
			}
			$grouped[ $q['level'] ][] = $q;
			if ( ! empty( $q['enabled'] ) ) {
				$enabled++;
			}
		}
		$total = count( $questions );
		ob_start();
		if ( 0 === $total ) {
			?>
			<div class="twt-aeo-vis__notice twt-aeo-vis__notice--info">
				<strong><?php esc_html_e( 'No questions for this allocation.', 'twt-aeo-ultimate' ); ?></strong>
				<?php esc_html_e( 'Raise a number in the allocation above, or add categories, product types, products or posts for the templates to fill from.', 'twt-aeo-ultimate' ); ?>
			</div>
			<?php
			return ob_get_clean();
		}
		$parts = array();
		foreach ( $grouped as $level => $list ) {
			if ( $list ) {
				$parts[] = count( $list ) . ' ' . strtolower( TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] );
			}
		}
		?>
		<input type="hidden" name="questions" value="<?php echo esc_attr( wp_json_encode( array_values( $questions ) ) ); ?>">
		<div class="twt-aeo-vis__qsummary">
			<span>
				<?php /* translators: %d: number of questions. */ ?>
				<strong><?php echo esc_html( sprintf( _n( '%d question', '%d questions', $total, 'twt-aeo-ultimate' ), $total ) ); ?></strong>
				— <?php echo esc_html( implode( ' · ', $parts ) ); ?>
				<?php /* translators: %d: number of questions ticked to be asked. */ ?>
				· <?php echo $enabled === $total ? esc_html__( 'all enabled', 'twt-aeo-ultimate' ) : esc_html( sprintf( __( '%d enabled', 'twt-aeo-ultimate' ), $enabled ) ); ?>
			</span>
			<button type="button" class="button-link twt-aeo-vis__q-toggle" data-open="<?php esc_attr_e( 'Expand to review', 'twt-aeo-ultimate' ); ?>" data-close="<?php esc_attr_e( 'Hide the list', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Expand to review', 'twt-aeo-ultimate' ); ?></button>
		</div>
		<div class="twt-aeo-vis__qlevels" hidden>
			<?php foreach ( $grouped as $level => $list ) :
				if ( ! $list ) {
					continue;
				}
				?>
				<div class="twt-aeo-vis__qlevel" data-level="<?php echo esc_attr( $level ); ?>">
					<div class="twt-aeo-vis__qlevel-head">
						<span class="twt-aeo-vis__swatch" style="background:<?php echo esc_attr( self::LEVEL_COLOR[ $level ] ); ?>"></span>
						<strong><?php echo esc_html( TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] ); ?> —
						<?php /* translators: %d: number of questions. */ ?>
						<?php echo esc_html( sprintf( _n( '%d question', '%d questions', count( $list ), 'twt-aeo-ultimate' ), count( $list ) ) ); ?></strong>
						<button type="button" class="button-link twt-aeo-vis__qlevel-toggle" data-show="<?php esc_attr_e( 'Show', 'twt-aeo-ultimate' ); ?>" data-hide="<?php esc_attr_e( 'Hide', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Show', 'twt-aeo-ultimate' ); ?></button>
					</div>
					<?php /* Collapsed: the ticks travel as hidden inputs so a save keeps them. */ ?>
					<div class="twt-aeo-vis__qlevel-hidden">
						<?php foreach ( $list as $q ) : ?>
							<?php if ( ! empty( $q['enabled'] ) ) : ?>
								<input type="hidden" name="q_<?php echo esc_attr( $q['id'] ); ?>" value="on">
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<ul class="twt-aeo-vis__qlist" hidden>
						<?php foreach ( $list as $q ) :
							$truth = isset( $q['truth'] ) && is_array( $q['truth'] ) && ! empty( $q['truth']['statement'] ) ? (string) $q['truth']['statement'] : '';
							?>
							<li>
								<label>
									<input type="checkbox" name="q_<?php echo esc_attr( $q['id'] ); ?>" value="on" <?php checked( ! empty( $q['enabled'] ) ); ?> disabled>
									<span><?php echo esc_html( (string) $q['text'] ); ?></span>
								</label>
								<span class="twt-aeo-vis__muted twt-aeo-vis__qmeta">
									<?php echo esc_html( isset( $q['family'] ) ? (string) $q['family'] : '' ); ?>
									<?php if ( 'company' !== $level && ! empty( $q['scope_label'] ) ) : ?> · <?php echo esc_html( (string) $q['scope_label'] ); ?><?php endif; ?>
									<?php if ( $truth ) : ?> · <?php esc_html_e( 'we know the answer:', 'twt-aeo-ultimate' ); ?> <?php echo esc_html( $truth ); ?><?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
			<div class="twt-aeo-vis__actions">
				<button type="submit" class="button button-primary" id="twt-aeo-vis-save-q"><?php esc_html_e( 'Save question set', 'twt-aeo-ultimate' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Run ── */

	private static function render_run( array $d ) {
		$selected   = $d['selected'];
		$resumable  = null;
		if ( $selected && isset( $selected['status'] ) && in_array( $selected['status'], array( 'running', 'paused' ), true ) ) {
			$queue  = isset( $selected['queue'] ) && is_array( $selected['queue'] ) ? count( $selected['queue'] ) : 0;
			$cursor = isset( $selected['cursor'] ) ? (int) $selected['cursor'] : 0;
			if ( $cursor < $queue && empty( $selected['demo'] ) ) {
				$resumable = array( 'id' => (string) $selected['id'], 'cursor' => $cursor, 'total' => $queue, 'date' => self::fmt_date( isset( $selected['started_at'] ) ? $selected['started_at'] : '' ) );
			}
		}
		$no_keys = empty( $d['available_ids'] );
		$totals  = $d['totals'];
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-run">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Run', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'One check at a time, here or in the background', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note">
					<?php
					printf(
						/* translators: 1: checks, 2: questions, 3: engines, 4: runs kept */
						esc_html__( 'Runs %1$s checks (%2$s questions × %3$s engines), one at a time. The last %4$d runs are kept.', 'twt-aeo-ultimate' ),
						'<span data-run-c>' . esc_html( number_format_i18n( (int) $totals['checks'] ) ) . '</span>',
						'<span data-run-q>' . esc_html( number_format_i18n( (int) $totals['questions'] ) ) . '</span>',
						'<span data-run-e>' . esc_html( count( $d['alloc']['engines'] ) ) . '</span>',
						(int) TWTAEO_Visibility_Types::RUNS_KEPT
					);
					?>
				</p>
				<?php if ( $no_keys ) : ?>
					<div class="twt-aeo-vis__notice twt-aeo-vis__notice--warn">
						<strong><?php esc_html_e( 'Add an AI key first.', 'twt-aeo-ultimate' ); ?></strong>
						<?php esc_html_e( 'Every check runs on your own key.', 'twt-aeo-ultimate' ); ?>
						<a href="<?php echo esc_url( self::admin_page( 'twt-aeo-settings' ) ); ?>"><?php esc_html_e( 'Add a Claude, OpenAI, Gemini or Perplexity key in Settings', 'twt-aeo-ultimate' ); ?></a>
						<?php esc_html_e( 'and come back.', 'twt-aeo-ultimate' ); ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $d['personas_on'] ) ) : ?>
				<div class="twt-aeo-vis__persona-pick">
					<label for="twt-aeo-vis-persona"><?php esc_html_e( 'Ask as', 'twt-aeo-ultimate' ); ?></label>
					<select id="twt-aeo-vis-persona">
						<option value=""><?php esc_html_e( 'Nobody in particular (baseline)', 'twt-aeo-ultimate' ); ?></option>
						<?php foreach ( (array) ( isset( $d['personas'] ) ? $d['personas'] : array() ) as $p ) : ?>
							<option value="<?php echo esc_attr( (string) $p['id'] ); ?>"><?php echo esc_html( (string) $p['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="twt-aeo-vis__muted"><?php esc_html_e( 'One persona per run, so its numbers compare like with like. To see each buyer, run once per persona — each run costs the same number of checks.', 'twt-aeo-ultimate' ); ?></span>
				</div>
				<?php endif; ?>
				<?php self::render_location_pick(); ?>
				<div class="twt-aeo-vis__persona-pick">
					<label for="twt-aeo-vis-journey"><?php esc_html_e( 'Follow the conversation', 'twt-aeo-ultimate' ); ?></label>
					<select id="twt-aeo-vis-journey">
						<option value="0"><?php esc_html_e( 'Off — first answers only', 'twt-aeo-ultimate' ); ?></option>
						<option value="1"><?php esc_html_e( '1 follow-up turn', 'twt-aeo-ultimate' ); ?></option>
						<option value="2"><?php esc_html_e( '2 follow-up turns', 'twt-aeo-ultimate' ); ?></option>
					</select>
					<span class="twt-aeo-vis__muted"><?php esc_html_e( 'After each answer, ask the engine the top follow-up question it predicted, in the same conversation — the way a buyer keeps going in the app — and record whether you are cited at that step too. Each turn is another searched check on your key and counts toward your daily cap, so one turn can up to double a run. Follow-up turns are shown beside the first answers and never mixed into the numbers above.', 'twt-aeo-ultimate' ); ?></span>
				</div>
				<div class="twt-aeo-vis__actions twt-aeo-vis__run-controls">
					<button type="button" class="button button-primary" id="twt-aeo-vis-start" <?php disabled( $no_keys ); ?>><?php esc_html_e( 'Start run', 'twt-aeo-ultimate' ); ?></button>
					<button type="button" class="button" id="twt-aeo-vis-stop" hidden><?php esc_html_e( 'Stop', 'twt-aeo-ultimate' ); ?></button>
					<?php if ( $resumable ) : ?>
						<button type="button" class="button" id="twt-aeo-vis-resume" data-run-id="<?php echo esc_attr( $resumable['id'] ); ?>" data-cursor="<?php echo esc_attr( $resumable['cursor'] ); ?>" data-total="<?php echo esc_attr( $resumable['total'] ); ?>">
							<?php
							printf(
								/* translators: 1: date, 2: cursor, 3: total */
								esc_html__( 'Resume the %1$s run (%2$d/%3$d)', 'twt-aeo-ultimate' ),
								esc_html( $resumable['date'] ),
								(int) $resumable['cursor'],
								(int) $resumable['total']
							);
							?>
						</button>
					<?php endif; ?>
					<span class="twt-aeo-vis__progress" id="twt-aeo-vis-progress" aria-live="polite"></span>
				</div>
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'You can close this page mid-run — a background worker picks the run up from where it stopped and finishes it. It rides WordPress cron, so progress between visits depends on your site getting traffic; keeping this page open is always the fastest way through a run.', 'twt-aeo-ultimate' ); ?>
				</p>
				<div class="twt-aeo-vis__notice" id="twt-aeo-vis-run-msg" hidden></div>
			</div>
		</section>
		<?php
	}

	/* ── Results ── */

	private static function render_results( array $d ) {
		$summaries = $d['summaries'];
		$selected  = $d['selected'];
		?>
		<section class="twt-aeo-vis__section" id="twt-aeo-vis-results">
			<div class="twt-aeo-vis__section-head">
				<h2><?php esc_html_e( 'Results', 'twt-aeo-ultimate' ); ?></h2>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Presence and citations, per engine, per question', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<?php if ( empty( $summaries ) || ! $selected ) : ?>
				<div class="twt-aeo-card twt-aeo-vis__empty">
					<p><?php esc_html_e( 'No runs yet. Results for each run appear here: cited and named share per engine, who was cited instead, and every question with what each engine said.', 'twt-aeo-ultimate' ); ?></p>
					<p><button type="button" class="button" id="twt-aeo-vis-demo"><?php esc_html_e( 'Load sample data', 'twt-aeo-ultimate' ); ?></button></p>
					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'Sample data is fabricated from your real categories, products and posts so you can see the board before a key is spent. It is labelled as sample everywhere, kept out of trends, and removed with one click. Your own-site citations in it carry the tags the assistants really add —', 'twt-aeo-ultimate' ); ?>
						<code>?utm_source=chatgpt.com</code>, <code>?utm_source=perplexity</code>
						<?php esc_html_e( '— which is what an AI referral looks like in your analytics.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			<?php else : ?>
				<?php self::render_results_board( $d ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_results_board( array $d ) {
		$summaries = $d['summaries'];
		$selected  = $d['selected'];
		$hosts     = $d['hosts'];
		$is_demo   = ! empty( $selected['demo'] );
		$summary   = TWTAEO_Visibility_Verdict::summarize_run( $selected );
		$summary   = is_array( $summary ) ? $summary : array();
		$checks    = isset( $selected['checks'] ) && is_array( $selected['checks'] ) ? $selected['checks'] : array();
		$questions = isset( $selected['questions'] ) && is_array( $selected['questions'] ) ? $selected['questions'] : array();
		$queue     = isset( $selected['queue'] ) && is_array( $selected['queue'] ) ? $selected['queue'] : array();

		// Engines in this run: its allocation, else whatever the checks hold.
		$run_engines = array();
		if ( isset( $selected['allocation']['engines'] ) && is_array( $selected['allocation']['engines'] ) ) {
			$run_engines = array_values( array_filter( $selected['allocation']['engines'], 'is_string' ) );
		}
		if ( empty( $run_engines ) ) {
			foreach ( $checks as $c ) {
				if ( is_array( $c ) && isset( $c['engine'] ) && ! in_array( $c['engine'], $run_engines, true ) ) {
					$run_engines[] = (string) $c['engine'];
				}
			}
		}

		// A run is only ever compared with runs asked as the same persona AND
		// from the same place (the baseline and "no location" count as their
		// own). Mixing them would show a persona's or a market's difference as
		// a gain or a loss over time.
		$compare_key    = self::compare_key( $selected );
		$persona_label  = self::persona_label( $selected );
		$location_label = self::location_label( $selected );

		// Previous REAL run for the delta; a sample never counts, and a sample never shows one.
		$previous = null;
		if ( ! $is_demo ) {
			$seen = false;
			foreach ( $summaries as $s ) {
				if ( $seen && empty( $s['demo'] ) && self::compare_key( $s ) === $compare_key ) {
					$previous = $s;
					break;
				}
				if ( isset( $s['id'] ) && (string) $s['id'] === (string) $selected['id'] ) {
					$seen = true;
				}
			}
		}

		$per_engine = self::engine_stats( $checks, $run_engines );
		$cited_pct  = isset( $summary['cited_pct'] ) ? (int) $summary['cited_pct'] : 0;
		$named_pct  = isset( $summary['named_pct'] ) ? (int) $summary['named_pct'] : 0;
		$wrong      = isset( $summary['wrong'] ) ? (int) $summary['wrong'] : 0;
		$n_checks   = isset( $summary['checks'] ) ? (int) $summary['checks'] : count( $checks );
		$status     = isset( $selected['status'] ) ? (string) $selected['status'] : '';

		// Trend: real runs only, oldest first.
		$trend = array();
		foreach ( array_reverse( $summaries ) as $s ) {
			if ( empty( $s['demo'] ) && isset( $s['by_engine'] ) && is_array( $s['by_engine'] ) && self::compare_key( $s ) === $compare_key ) {
				$trend[] = $s;
			}
		}

		// Share of voice for the chosen scope.
		$scope          = $d['scope'];
		$scope_q        = self::filter_questions_for_scope( $questions, $scope );
		$scope_ids      = array();
		foreach ( $scope_q as $q ) {
			$scope_ids[ (string) $q['id'] ] = true;
		}
		$scope_checks = array();
		foreach ( $checks as $c ) {
			if ( is_array( $c ) && isset( $c['question_id'] ) && isset( $scope_ids[ (string) $c['question_id'] ] ) ) {
				$scope_checks[] = $c;
			}
		}
		$voice = array();
		foreach ( $run_engines as $e ) {
			$v = TWTAEO_Visibility_Verdict::share_of_voice( $scope_checks, $questions, array( 'engine' => $e ), $hosts );
			$voice[] = is_array( $v ) ? $v : array( 'engine' => $e, 'checks' => 0, 'answered' => 0, 'unavailable' => 0, 'with_citations' => 0, 'our_cited_pct' => 0, 'slices' => array() );
		}
		$items_label = self::scope_items_label( $scope, $scope_q, $d['counts'] );

		// Question table rows: wrong first.
		$check_index = array();
		foreach ( $checks as $c ) {
			if ( is_array( $c ) && isset( $c['question_id'], $c['engine'] ) ) {
				$check_index[ (string) $c['question_id'] . '|' . (string) $c['engine'] ] = $c;
			}
		}

		// Journey mode: later turns per question × engine, in turn order, plus
		// their own tally — kept apart from the headline numbers above.
		$journey       = isset( $selected['journey'] ) && is_array( $selected['journey'] ) ? $selected['journey'] : array();
		$journey_index = array();
		$journey_tally = array( 'answered' => 0, 'cited' => 0, 'named' => 0 );
		foreach ( $journey as $jc ) {
			if ( ! is_array( $jc ) || ! isset( $jc['question_id'], $jc['engine'] ) ) {
				continue;
			}
			$journey_index[ (string) $jc['question_id'] . '|' . (string) $jc['engine'] ][] = $jc;
			if ( 'unavailable' !== $jc['verdict'] ) {
				++$journey_tally['answered'];
				$journey_tally['cited'] += 'cited' === $jc['verdict'] ? 1 : 0;
				$journey_tally['named'] += in_array( $jc['verdict'], array( 'cited', 'named' ), true ) ? 1 : 0;
			}
		}
		foreach ( $journey_index as &$turns ) {
			usort(
				$turns,
				static function ( $a, $b ) {
					return (int) $a['turn'] <=> (int) $b['turn'];
				}
			);
		}
		unset( $turns );
		$rows = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$has_wrong = false;
			foreach ( $run_engines as $e ) {
				$k = (string) $q['id'] . '|' . $e;
				if ( isset( $check_index[ $k ]['accuracy'] ) && 'wrong' === $check_index[ $k ]['accuracy'] ) {
					$has_wrong = true;
					break;
				}
			}
			$rows[] = array( $has_wrong ? 0 : 1, $q );
		}
		usort( $rows, function ( $a, $b ) {
			return $a[0] - $b[0];
		} );
		?>
		<?php if ( $is_demo ) : ?>
			<div class="twt-aeo-vis__notice twt-aeo-vis__notice--warn twt-aeo-vis__sample">
				<strong><?php esc_html_e( 'Sample data — not real answers.', 'twt-aeo-ultimate' ); ?></strong>
				<?php esc_html_e( 'Everything below is fabricated from your catalogue to show what a run looks like. No assistant was asked anything. It never enters a trend. Remove it before reading anything into the numbers.', 'twt-aeo-ultimate' ); ?>
				<button type="button" class="button button-small" id="twt-aeo-vis-undemo"><?php esc_html_e( 'Remove sample data', 'twt-aeo-ultimate' ); ?></button>
			</div>
		<?php endif; ?>

		<?php if ( count( $summaries ) > 1 ) : ?>
			<div class="twt-aeo-vis__nav">
				<label for="twt-aeo-vis-run-select"><?php esc_html_e( 'Run', 'twt-aeo-ultimate' ); ?></label>
				<select id="twt-aeo-vis-run-select" class="twt-aeo-vis__navselect" data-param="run">
					<?php foreach ( $summaries as $s ) :
						if ( ! isset( $s['id'] ) ) {
							continue;
						}
						$label = ( ! empty( $s['demo'] ) ? __( 'SAMPLE', 'twt-aeo-ultimate' ) . ' — ' : '' )
							. self::fmt_date( isset( $s['started_at'] ) ? $s['started_at'] : '', true )
							/* translators: %d: number of checks recorded in that run. */
							. ' — ' . sprintf( _n( '%d check', '%d checks', isset( $s['checks'] ) ? (int) $s['checks'] : 0, 'twt-aeo-ultimate' ), isset( $s['checks'] ) ? (int) $s['checks'] : 0 )
							/* translators: %d: follow-up checks asked after the first answers (journey mode). */
							. ( ! empty( $s['turns'] ) ? ' + ' . sprintf( _n( '%d follow-up turn', '%d follow-up turns', (int) $s['turns'], 'twt-aeo-ultimate' ), (int) $s['turns'] ) : '' )
							. ' · ' . ( isset( $s['status'] ) ? (string) $s['status'] : '' )
							/* translators: %s: persona the run was asked as. */
							. ( '' !== self::persona_label( $s ) ? ' · ' . sprintf( __( 'as %s', 'twt-aeo-ultimate' ), self::persona_label( $s ) ) : '' )
							/* translators: %s: where the run searched from, e.g. "Tampa, Florida, United States". */
							. ( '' !== self::location_label( $s ) ? ' · ' . sprintf( __( 'from %s', 'twt-aeo-ultimate' ), self::location_label( $s ) ) : '' );
						?>
						<option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( (string) $s['id'], (string) $selected['id'] ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( ! $is_demo ) :
			$export = function ( $which ) {
				return wp_nonce_url(
					add_query_arg(
						array(
							'action' => TWTAEO_Visibility_Types::EXPORT_ACTION,
							'run'    => $which,
						),
						admin_url( 'admin-post.php' )
					),
					TWTAEO_Visibility_Types::EXPORT_ACTION
				);
			};
			?>
			<div class="twt-aeo-vis__export">
				<strong><?php esc_html_e( 'Export CSV', 'twt-aeo-ultimate' ); ?></strong>
				<a class="button button-small" href="<?php echo esc_url( $export( (string) $selected['id'] ) ); ?>"><?php esc_html_e( 'This run', 'twt-aeo-ultimate' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( $export( 'all' ) ); ?>"><?php esc_html_e( 'All kept runs', 'twt-aeo-ultimate' ); ?></a>
				<span class="twt-aeo-vis__muted"><?php esc_html_e( 'One row per question asked, each followed by the follow-up questions that engine predicted — with the persona, the verdict, the full answer and pattern columns (brand and subject position, model numbers, place, hiring or how-to). Every row names the site and business type, so exports from several sites stack into one sheet. Follow-ups are each engine’s prediction, not questions real users typed.', 'twt-aeo-ultimate' ); ?></span>
			</div>
		<?php endif; ?>

		<?php /* Headline */ ?>
		<div class="twt-aeo-vis__headline<?php echo $is_demo ? ' is-sample' : ''; ?>">
			<div class="twt-aeo-vis__stat">
				<span class="twt-aeo-vis__stat-label"><?php esc_html_e( 'Cited', 'twt-aeo-ultimate' ); ?></span>
				<span class="twt-aeo-vis__stat-value"><?php echo esc_html( $cited_pct ); ?>%</span>
				<span class="twt-aeo-vis__stat-sub">
					<?php /* translators: %s: number of answered checks in this run. */ ?>
					<?php echo esc_html( sprintf( __( 'of answered checks (%s in this run)', 'twt-aeo-ultimate' ), number_format_i18n( $n_checks ) ) ); ?>
					<?php if ( $previous ) : ?> · <?php echo esc_html( self::delta( $cited_pct, isset( $previous['cited_pct'] ) ? (int) $previous['cited_pct'] : 0 ) ); ?> <?php esc_html_e( 'vs previous run', 'twt-aeo-ultimate' ); ?><?php endif; ?>
				</span>
			</div>
			<div class="twt-aeo-vis__stat">
				<span class="twt-aeo-vis__stat-label"><?php esc_html_e( 'Named', 'twt-aeo-ultimate' ); ?></span>
				<span class="twt-aeo-vis__stat-value"><?php echo esc_html( $named_pct ); ?>%</span>
				<span class="twt-aeo-vis__stat-sub">
					<?php esc_html_e( 'of answered checks, cited included', 'twt-aeo-ultimate' ); ?>
					<?php if ( $previous ) : ?> · <?php echo esc_html( self::delta( $named_pct, isset( $previous['named_pct'] ) ? (int) $previous['named_pct'] : 0 ) ); ?> <?php esc_html_e( 'vs previous run', 'twt-aeo-ultimate' ); ?><?php endif; ?>
				</span>
			</div>
			<div class="twt-aeo-vis__stat<?php echo $wrong ? ' is-bad' : ''; ?>">
				<span class="twt-aeo-vis__stat-label"><?php esc_html_e( 'Wrong answers', 'twt-aeo-ultimate' ); ?></span>
				<span class="twt-aeo-vis__stat-value"><?php echo esc_html( $wrong ); ?></span>
				<span class="twt-aeo-vis__stat-sub">
					<?php esc_html_e( 'contradict a fact you publish', 'twt-aeo-ultimate' ); ?>
					<?php if ( $previous ) :
						$dw = $wrong - ( isset( $previous['wrong'] ) ? (int) $previous['wrong'] : 0 );
						?> · <?php echo esc_html( ( $dw >= 0 ? '+' : '' ) . $dw ); ?> <?php esc_html_e( 'vs previous run', 'twt-aeo-ultimate' ); ?><?php endif; ?>
				</span>
			</div>
		</div>
		<div class="twt-aeo-vis__chips">
			<?php foreach ( $per_engine as $s ) :
				$tone = 0 === $s['answered'] ? 'none' : ( $s['cited_pct'] > 0 ? 'ok' : 'warn' );
				?>
				<span class="twt-aeo-vis__chip twt-aeo-vis__chip--<?php echo esc_attr( $tone ); ?>">
					<strong><?php echo esc_html( self::engine_label( $s['engine'] ) ); ?></strong>
					<?php if ( 0 === $s['answered'] ) : ?>
						<?php esc_html_e( 'no answers', 'twt-aeo-ultimate' ); ?>
					<?php else : ?>
						<?php /* translators: 1: cited percentage, 2: named percentage. */ ?>
						<?php echo esc_html( sprintf( __( 'cited %1$d%% · named %2$d%%', 'twt-aeo-ultimate' ), $s['cited_pct'], $s['named_pct'] ) ); ?>
						<?php if ( ! empty( $s['no_sources'] ) ) : ?>
							<?php /* translators: 1: answers with no sources, 2: answers. */ ?>
							<br /><span class="twt-aeo-vis__muted" title="<?php esc_attr_e( 'Answers that linked to no one. Not cited there means no one was cited in your place, not that a competitor won.', 'twt-aeo-ultimate' ); ?>"><?php echo esc_html( sprintf( _n( '%1$d of %2$d answer gave no sources', '%1$d of %2$d answers gave no sources', (int) $s['no_sources'], 'twt-aeo-ultimate' ), (int) $s['no_sources'], (int) $s['answered'] ) ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
					<?php /* translators: %d: number of answers that contradicted a fact you publish. */ ?>
					<?php if ( $s['wrong'] ) : ?> · <?php echo esc_html( sprintf( _n( '%d wrong', '%d wrong', $s['wrong'], 'twt-aeo-ultimate' ), $s['wrong'] ) ); ?><?php endif; ?>
					<?php /* translators: %d: number of checks where no answer was recorded. */ ?>
					<?php if ( $s['unavailable'] ) : ?> · <?php echo esc_html( sprintf( __( '%d unavailable', 'twt-aeo-ultimate' ), $s['unavailable'] ) ); ?><?php endif; ?>
				</span>
			<?php endforeach; ?>
		</div>
		<p class="twt-aeo-vis__muted">
			<?php /* translators: 1: run start date, 2: run status. */ ?>
			<?php echo esc_html( sprintf( __( 'Run started %1$s · %2$s', 'twt-aeo-ultimate' ), self::fmt_date( isset( $selected['started_at'] ) ? $selected['started_at'] : '', true ), $status ) ); ?>
			<?php if ( '' !== $persona_label ) : ?>
				<?php /* translators: %s: persona the run was asked as. */ ?>
				· <strong><?php echo esc_html( sprintf( __( 'asked as %s', 'twt-aeo-ultimate' ), $persona_label ) ); ?></strong>
			<?php else : ?>
				· <?php esc_html_e( 'asked as nobody in particular', 'twt-aeo-ultimate' ); ?>
			<?php endif; ?>
			<?php if ( '' !== $location_label ) : ?>
				<?php /* translators: %s: where the run searched from. */ ?>
				· <strong><?php echo esc_html( sprintf( __( 'searched from %s', 'twt-aeo-ultimate' ), $location_label ) ); ?></strong>
				<?php
				$approx = array();
				foreach ( $run_engines as $re ) {
					if ( ! in_array( $re, TWTAEO_Visibility_Location::SEARCH_ENGINES, true ) && isset( TWTAEO_Visibility_Types::ENGINES[ $re ]['label'] ) ) {
						$approx[] = TWTAEO_Visibility_Types::ENGINES[ $re ]['label'];
					}
				}
				if ( $approx ) :
					?>
					<?php /* translators: %s: engine names. */ ?>
					(<?php echo esc_html( sprintf( __( 'approximate for %s: their APIs take no search location, so only the assistant was told where the user is', 'twt-aeo-ultimate' ), implode( ', ', $approx ) ) ); ?>)
				<?php endif; ?>
			<?php else : ?>
				· <?php esc_html_e( 'no search location', 'twt-aeo-ultimate' ); ?>
			<?php endif; ?>
			<?php if ( 'done' !== $status ) : ?>
				<?php /* translators: 1: checks completed so far, 2: total checks queued. */ ?>
				(<?php echo esc_html( sprintf( __( '%1$d of %2$d checks', 'twt-aeo-ultimate' ), isset( $selected['cursor'] ) ? (int) $selected['cursor'] : 0, count( $queue ) ) ); ?>)
			<?php endif; ?>
			<?php if ( ! empty( $selected['message'] ) ) : ?> · <?php echo esc_html( rtrim( (string) $selected['message'], '. ' ) ); ?><?php endif; ?>.
			<?php esc_html_e( 'Per-engine shares are of that engine’s answered checks; unavailable checks are counted separately.', 'twt-aeo-ultimate' ); ?>
			<?php esc_html_e( 'The trend and “vs previous run” compare only runs asked as the same persona from the same location.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( ! empty( $journey ) ) : ?>
			<div class="twt-aeo-vis__notice twt-aeo-vis__journey-tally">
				<strong><?php esc_html_e( 'Follow-up turns', 'twt-aeo-ultimate' ); ?></strong> —
				<?php
				$ja = (int) $journey_tally['answered'];
				echo esc_html(
					sprintf(
						/* translators: 1: follow-up turn checks, 2: cited percent, 3: named percent. */
						_n(
							'%1$s check further into the conversation: cited %2$s%%, named %3$s%% of those answered. Kept out of the numbers above so this run still compares with runs that stopped at the first answer. Open a cell below to read each conversation.',
							'%1$s checks further into the conversation: cited %2$s%%, named %3$s%% of those answered. Kept out of the numbers above so this run still compares with runs that stopped at the first answer. Open a cell below to read each conversation.',
							count( $journey ),
							'twt-aeo-ultimate'
						),
						number_format_i18n( count( $journey ) ),
						$ja ? (int) round( $journey_tally['cited'] / $ja * 100 ) : 0,
						$ja ? (int) round( $journey_tally['named'] / $ja * 100 ) : 0
					)
				);
				?>
			</div>
		<?php endif; ?>

		<?php /* Trend */ ?>
		<div class="twt-aeo-vis__block">
			<?php /* translators: %d: number of past runs shown in the trend. */ ?>
			<h3><?php echo esc_html( sprintf( __( 'Trend — cited %% of answered checks, per engine, across your last %d runs', 'twt-aeo-ultimate' ), count( $trend ) ) ); ?></h3>
			<?php if ( count( $trend ) >= 2 ) : ?>
				<div class="twt-aeo-vis__sparks">
					<?php foreach ( TWTAEO_Visibility_Types::ENGINES_V1 as $e ) :
						$points = array();
						$any    = false;
						foreach ( $trend as $t ) {
							$v = isset( $t['by_engine'][ $e ] ) && null !== $t['by_engine'][ $e ] ? (float) $t['by_engine'][ $e ] : null;
							if ( null !== $v ) {
								$any = true;
							}
							$points[] = array( 'label' => self::fmt_date( isset( $t['started_at'] ) ? $t['started_at'] : '' ), 'value' => $v );
						}
						if ( ! $any ) {
							continue;
						}
						$last = end( $points );
						?>
						<div class="twt-aeo-vis__spark">
							<span class="twt-aeo-vis__spark-label"><?php echo esc_html( self::engine_label( $e ) ); ?></span>
							<?php self::echo_svg( self::svg_sparkline( $points, self::OURS_COLOR ) ); ?>
							<span class="twt-aeo-vis__muted"><?php esc_html_e( 'latest', 'twt-aeo-ultimate' ); ?> <?php echo null === $last['value'] ? '—' : esc_html( (int) $last['value'] ) . '%'; ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="twt-aeo-vis__muted"><?php esc_html_e( 'Trend lines appear once you have two real runs. Sample runs never enter the trend.', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>
		</div>

		<?php /* Share of voice */ ?>
		<div class="twt-aeo-vis__block">
			<h3><?php esc_html_e( 'Who gets cited instead — share of voice from this run', 'twt-aeo-ultimate' ); ?></h3>
			<p class="twt-aeo-vis__muted"><?php esc_html_e( 'From the same answers, never a second query: every citation’s domain, per engine, for the scope you pick. Your slice is the blue one; the centre number is your cited share of that engine’s checks in this scope.', 'twt-aeo-ultimate' ); ?></p>
			<div class="twt-aeo-vis__nav">
				<label for="twt-aeo-vis-scope-select"><?php esc_html_e( 'Scope', 'twt-aeo-ultimate' ); ?></label>
				<select id="twt-aeo-vis-scope-select" class="twt-aeo-vis__navselect" data-param="scope">
					<?php foreach ( $d['scope_options'] as $o ) :
						if ( ! is_array( $o ) || ! isset( $o['value'] ) ) {
							continue;
						}
						?>
						<?php
						$o_label = isset( $o['label'] ) ? (string) $o['label'] : (string) $o['value'];
						if ( isset( $o['covered'] ) && ! $o['covered'] ) {
							/* translators: %s: scope name, e.g. "Category: Shoes". */
							$o_label = sprintf( __( '%s — not in this run', 'twt-aeo-ultimate' ), $o_label );
						}
						?>
						<option value="<?php echo esc_attr( (string) $o['value'] ); ?>" <?php selected( (string) $o['value'], $scope ); ?>><?php echo esc_html( $o_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php if ( empty( $scope_checks ) ) : ?>
				<p class="twt-aeo-vis__empty">
					<?php esc_html_e( 'This run asked nothing in that scope, so there is nothing to chart — which is not the same as “you were not cited there”.', 'twt-aeo-ultimate' ); ?>
					<?php esc_html_e( 'The dropdown lists everything you have; a run covers as many as its allocation reached. Raise that level in Allocation and run again, or pick a scope that is not marked “not in this run”.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>
			<div class="twt-aeo-vis__donuts">
				<?php foreach ( $voice as $v ) :
					$e      = isset( $v['engine'] ) ? (string) $v['engine'] : '';
					$n      = isset( $v['checks'] ) ? (int) $v['checks'] : 0;
					$slices = isset( $v['slices'] ) && is_array( $v['slices'] ) ? $v['slices'] : array();
					?>
					<?php if ( 0 === $n ) : ?>
						<div class="twt-aeo-vis__donut-card twt-aeo-vis__donut-card--empty">
							<?php /* translators: %s: engine name. */ ?>
							<strong><?php echo esc_html( sprintf( __( 'No results for %s', 'twt-aeo-ultimate' ), self::engine_label( $e ) ) ); ?></strong>
							<span class="twt-aeo-vis__muted"><?php esc_html_e( '0 checks in this scope', 'twt-aeo-ultimate' ); ?> · <?php echo esc_html( $items_label ); ?></span>
						</div>
					<?php else : ?>
						<div class="twt-aeo-vis__donut-card">
							<strong class="twt-aeo-vis__donut-title"><?php echo esc_html( self::engine_label( $e ) ); ?></strong>
							<div class="twt-aeo-vis__donut-row">
								<?php self::echo_svg( self::svg_donut( $slices, ( isset( $v['our_cited_pct'] ) ? (int) $v['our_cited_pct'] : 0 ) . '%', __( 'cited', 'twt-aeo-ultimate' ), 150 ) ); ?>
								<ul class="twt-aeo-vis__legend">
									<?php foreach ( self::donut_legend( $slices ) as $s ) : ?>
										<li>
											<span class="twt-aeo-vis__swatch" style="background:<?php echo esc_attr( $s['color'] ); ?>"></span>
											<?php if ( $s['ours'] ) : ?>
												<strong><?php echo esc_html( $s['label'] ); ?> (<?php esc_html_e( 'you', 'twt-aeo-ultimate' ); ?>)</strong>
											<?php else : ?>
												<?php echo esc_html( $s['label'] ); ?>
											<?php endif; ?>
											— <?php echo esc_html( $s['count'] ); ?>
										</li>
									<?php endforeach; ?>
									<?php if ( empty( $slices ) ) : ?>
										<li class="twt-aeo-vis__muted"><?php esc_html_e( 'No citations in these answers.', 'twt-aeo-ultimate' ); ?></li>
									<?php endif; ?>
								</ul>
							</div>
							<span class="twt-aeo-vis__muted">
								<?php /* translators: 1: number of checks, 2: how many of them carried citations. */ ?>
								<?php
								$v_unavail = isset( $v['unavailable'] ) ? (int) $v['unavailable'] : 0;
								$v_answered = isset( $v['answered'] ) ? (int) $v['answered'] : $n;
								if ( $v_unavail > 0 ) {
									printf(
										/* translators: 1: answered checks, 2: total checks, 3: checks containing citations, 4: unavailable checks. */
										esc_html__( '%1$d of %2$d answered · %3$d with citations · %4$d unavailable, not counted against you', 'twt-aeo-ultimate' ),
										(int) $v_answered,
										(int) $n,
										isset( $v['with_citations'] ) ? (int) $v['with_citations'] : 0,
										(int) $v_unavail
									);
								} else {
									printf(
										/* translators: 1: number of checks, 2: checks containing citations. */
										esc_html__( '%1$d checks · %2$d with citations', 'twt-aeo-ultimate' ),
										(int) $n,
										isset( $v['with_citations'] ) ? (int) $v['with_citations'] : 0
									);
								}
								?>
								· <?php echo esc_html( $items_label ); ?>
							</span>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>

		<?php /* Question table */ ?>
		<div class="twt-aeo-vis__block">
			<h3><?php esc_html_e( 'Every question, every engine', 'twt-aeo-ultimate' ); ?></h3>
			<p class="twt-aeo-vis__legend-line">
				<?php foreach ( array( 'cited', 'named', 'absent', 'unavailable' ) as $v ) : ?>
					<span><?php self::echo_svg( self::svg_verdict_dot( $v, $v ) ); ?> <?php echo esc_html( $v ); ?></span>
				<?php endforeach; ?>
				<span class="twt-aeo-vis__muted">· <?php esc_html_e( 'second mark, when we know the answer: ✓ correct, ✗ wrong, — not stated. Click a cell to read what the engine said. Rows with a wrong answer come first.', 'twt-aeo-ultimate' ); ?></span>
			</p>
			<div class="twt-aeo-vis__table-wrap">
				<table class="twt-aeo-vis__table widefat">
					<thead>
						<tr>
							<th class="twt-aeo-vis__th-q"><?php esc_html_e( 'Question', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Level / scope', 'twt-aeo-ultimate' ); ?></th>
							<?php foreach ( $run_engines as $e ) : ?>
								<th class="twt-aeo-vis__th-e"><?php echo esc_html( self::engine_label( $e ) ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'What to fix', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $pair ) :
							$q     = $pair[1];
							$qid   = (string) $q['id'];
							$level = isset( $q['level'] ) ? (string) $q['level'] : '';
							$truth = isset( $q['truth'] ) && is_array( $q['truth'] ) && ! empty( $q['truth']['statement'] ) ? (string) $q['truth']['statement'] : '';
							$fix   = self::fix_link( $q );
							$cols  = 3 + count( $run_engines );
							?>
							<tr class="twt-aeo-vis__row<?php echo 0 === $pair[0] ? ' is-wrong' : ''; ?>" data-qid="<?php echo esc_attr( $qid ); ?>">
								<td class="twt-aeo-vis__td-q">
									<span><?php echo esc_html( isset( $q['text'] ) ? (string) $q['text'] : '' ); ?></span>
									<?php if ( $truth ) : ?><span class="twt-aeo-vis__muted twt-aeo-vis__truth"><?php esc_html_e( 'we know:', 'twt-aeo-ultimate' ); ?> <?php echo esc_html( $truth ); ?></span><?php endif; ?>
								</td>
								<td class="twt-aeo-vis__td-level">
									<span class="twt-aeo-vis__swatch twt-aeo-vis__swatch--sm" style="background:<?php echo esc_attr( isset( self::LEVEL_COLOR[ $level ] ) ? self::LEVEL_COLOR[ $level ] : '#8c9196' ); ?>"></span>
									<?php echo esc_html( isset( TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] ) ? TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] : $level ); ?>
									<?php if ( 'company' !== $level && ! empty( $q['scope_label'] ) ) : ?><span class="twt-aeo-vis__muted"> · <?php echo esc_html( (string) $q['scope_label'] ); ?></span><?php endif; ?>
								</td>
								<?php foreach ( $run_engines as $e ) :
									$k = $qid . '|' . $e;
									$c = isset( $check_index[ $k ] ) ? $check_index[ $k ] : null;
									if ( ! $c ) {
										echo '<td class="twt-aeo-vis__td-e"><span class="twt-aeo-vis__muted">—</span></td>';
										continue;
									}
									$verdict  = isset( $c['verdict'] ) && isset( self::VERDICT_COLOR[ $c['verdict'] ] ) ? (string) $c['verdict'] : 'unavailable';
									$surface  = isset( $c['citation_surface'] ) ? (string) $c['citation_surface'] : '';
									$accuracy = isset( $c['accuracy'] ) ? (string) $c['accuracy'] : 'n/a';
									$acc      = '';
									if ( $truth && 'n/a' !== $accuracy ) {
										$acc = 'correct' === $accuracy ? '✓' : ( 'wrong' === $accuracy ? '✗' : '—' );
									}
									/* translators: %s: accuracy verdict — correct, wrong or not stated. */
									$title = self::verdict_text( $verdict, $surface ) . ( $acc ? ' · ' . sprintf( __( 'accuracy: %s', 'twt-aeo-ultimate' ), str_replace( '_', ' ', $accuracy ) ) : '' );
									?>
									<td class="twt-aeo-vis__td-e">
										<button type="button" class="twt-aeo-vis__cell" data-drawer="<?php echo esc_attr( $qid . '|' . $e ); ?>" title="<?php echo esc_attr( $title ); ?>" aria-expanded="false">
											<?php self::echo_svg( self::svg_verdict_dot( $verdict, self::verdict_text( $verdict, $surface ) ) ); ?>
											<?php if ( $acc ) : ?><span class="twt-aeo-vis__acc twt-aeo-vis__acc--<?php echo esc_attr( sanitize_key( $accuracy ) ); ?>"><?php echo esc_html( $acc ); ?></span><?php endif; ?>
										</button>
									</td>
								<?php endforeach; ?>
								<td class="twt-aeo-vis__td-fix">
									<?php if ( $fix['href'] ) : ?>
										<a href="<?php echo esc_url( $fix['href'] ); ?>"><?php echo esc_html( $fix['label'] ); ?></a>
									<?php else : ?>
										<span class="twt-aeo-vis__muted"><?php echo esc_html( $fix['label'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php
							// Journey mode: one row per follow-up turn under the question.
							// Each engine asked ITS OWN top follow-up, so the words differ
							// per column — shown in full when they agree, per dot otherwise.
							$by_turn = array();
							foreach ( $run_engines as $e ) {
								foreach ( isset( $journey_index[ $qid . '|' . $e ] ) ? $journey_index[ $qid . '|' . $e ] : array() as $jc ) {
									$by_turn[ (int) $jc['turn'] ][ $e ] = $jc;
								}
							}
							ksort( $by_turn );
							foreach ( $by_turn as $t => $turn_checks ) :
								$texts = array_values( array_unique( array_filter( array_map(
									static function ( $jc ) {
										return (string) $jc['asked'];
									},
									$turn_checks
								) ) ) );
								?>
								<tr class="twt-aeo-vis__row twt-aeo-vis__row--followup" data-qid="<?php echo esc_attr( $qid ); ?>">
									<td class="twt-aeo-vis__td-q">
										<span class="twt-aeo-vis__followup-tag">
											<?php /* translators: %d: conversation turn number (2, 3). */ ?>
											<?php echo esc_html( sprintf( __( '↳ Follow-up · turn %d', 'twt-aeo-ultimate' ), (int) $t ) ); ?>
										</span>
										<?php if ( 1 === count( $texts ) ) : ?>
											<span><?php echo esc_html( $texts[0] ); ?></span>
										<?php else : ?>
											<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Each engine asked its own next question — hover a dot to see it, click to read the conversation.', 'twt-aeo-ultimate' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="twt-aeo-vis__td-level">
										<span class="twt-aeo-vis__muted"><?php esc_html_e( 'Follow-up', 'twt-aeo-ultimate' ); ?></span>
									</td>
									<?php foreach ( $run_engines as $e ) :
										if ( ! isset( $turn_checks[ $e ] ) ) {
											echo '<td class="twt-aeo-vis__td-e"><span class="twt-aeo-vis__muted">—</span></td>';
											continue;
										}
										$jc  = $turn_checks[ $e ];
										$jv  = isset( self::VERDICT_COLOR[ $jc['verdict'] ] ) ? (string) $jc['verdict'] : 'unavailable';
										$jvt = self::verdict_text( $jv, isset( $jc['citation_surface'] ) ? (string) $jc['citation_surface'] : '' );
										?>
										<td class="twt-aeo-vis__td-e">
											<?php /* translators: 1: turn number, 2: the follow-up question asked, 3: verdict. */ ?>
											<button type="button" class="twt-aeo-vis__cell" data-drawer="<?php echo esc_attr( $qid . '|' . $e ); ?>" title="<?php echo esc_attr( sprintf( __( 'Turn %1$d: “%2$s” — %3$s', 'twt-aeo-ultimate' ), (int) $t, (string) $jc['asked'], $jvt ) ); ?>" aria-expanded="false">
												<?php self::echo_svg( self::svg_verdict_dot( $jv, $jvt ) ); ?>
											</button>
										</td>
									<?php endforeach; ?>
									<td class="twt-aeo-vis__td-fix"></td>
								</tr>
							<?php endforeach; ?>
							<?php foreach ( $run_engines as $e ) :
								$k = $qid . '|' . $e;
								if ( ! isset( $check_index[ $k ] ) ) {
									continue;
								}
								$c        = $check_index[ $k ];
								$verdict  = isset( $c['verdict'] ) && isset( self::VERDICT_COLOR[ $c['verdict'] ] ) ? (string) $c['verdict'] : 'unavailable';
								$accuracy = isset( $c['accuracy'] ) ? (string) $c['accuracy'] : 'n/a';
								$urls     = isset( $c['cited_urls'] ) && is_array( $c['cited_urls'] ) ? $c['cited_urls'] : array();
								$ours     = isset( $c['our_urls'] ) && is_array( $c['our_urls'] ) ? $c['our_urls'] : array();
								$latency  = isset( $c['latency_ms'] ) ? round( ( (int) $c['latency_ms'] ) / 1000, 1 ) : null;
								?>
								<tr class="twt-aeo-vis__drawer" data-drawer-for="<?php echo esc_attr( $k ); ?>" hidden>
									<td colspan="<?php echo esc_attr( $cols ); ?>">
										<div class="twt-aeo-vis__drawer-box">
											<strong>
												<?php echo esc_html( self::engine_label( $e ) ); ?> — <?php echo esc_html( self::verdict_text( $verdict, isset( $c['citation_surface'] ) ? (string) $c['citation_surface'] : '' ) ); ?>
												<?php /* translators: %s: accuracy verdict — correct, wrong or not stated. */ ?>
												<?php if ( $truth && 'n/a' !== $accuracy ) : ?> · <?php echo esc_html( sprintf( __( 'accuracy: %s', 'twt-aeo-ultimate' ), str_replace( '_', ' ', $accuracy ) ) ); ?><?php endif; ?>
												<?php if ( null !== $latency ) : ?> · <?php echo esc_html( $latency ); ?>s<?php endif; ?>
												<?php if ( ! empty( $c['model'] ) ) : ?> · <code><?php echo esc_html( (string) $c['model'] ); ?></code><?php endif; ?>
												<?php if ( ! empty( $c['tools'] ) ) : ?> · <?php echo esc_html( is_array( $c['tools'] ) ? implode( ', ', array_map( 'strval', $c['tools'] ) ) : (string) $c['tools'] ); ?><?php endif; ?>
											</strong>
											<p class="twt-aeo-vis__excerpt"><?php echo esc_html( ! empty( $c['excerpt'] ) ? (string) $c['excerpt'] : __( '(no answer text recorded)', 'twt-aeo-ultimate' ) ); ?></p>
											<?php if ( ! empty( $c['mentions'] ) && is_array( $c['mentions'] ) ) : ?>
												<p class="twt-aeo-vis__muted"><?php esc_html_e( 'Where the answer named you:', 'twt-aeo-ultimate' ); ?></p>
												<ul class="twt-aeo-vis__cited">
													<?php foreach ( array_slice( $c['mentions'], 0, 4 ) as $mention ) :
														if ( ! is_array( $mention ) || empty( $mention['snippet'] ) ) {
															continue;
														}
														$mention_name = isset( $mention['name'] ) ? (string) $mention['name'] : '';
														$snippet_html = esc_html( '…' . (string) $mention['snippet'] . '…' );
														if ( '' !== $mention_name ) {
															$marked = preg_replace(
																'/' . preg_quote( esc_html( $mention_name ), '/' ) . '/iu',
																'<mark>$0</mark>',
																$snippet_html,
																1
															);
															if ( null !== $marked ) {
																$snippet_html = $marked;
															}
														}
														?>
														<li><em><?php echo wp_kses( $snippet_html, array( 'mark' => array() ) ); ?></em></li>
													<?php endforeach; ?>
												</ul>
											<?php elseif ( in_array( $verdict, array( 'cited', 'named' ), true ) ) : ?>
												<p class="twt-aeo-vis__muted"><?php esc_html_e( 'No prose mention of your name recorded in this answer.', 'twt-aeo-ultimate' ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $c['error'] ) ) : ?><p class="twt-aeo-vis__error"><?php echo esc_html( (string) $c['error'] ); ?></p><?php endif; ?>
											<?php if ( $urls ) : ?>
												<p class="twt-aeo-vis__muted"><?php esc_html_e( 'Cited:', 'twt-aeo-ultimate' ); ?></p>
												<ul class="twt-aeo-vis__cited">
													<?php foreach ( array_slice( $urls, 0, 12 ) as $u ) :
														$u = (string) $u;
														?>
														<li><a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $u ); ?></a><?php if ( in_array( $u, $ours, true ) ) : ?> <strong>(<?php esc_html_e( 'you', 'twt-aeo-ultimate' ); ?>)</strong><?php endif; ?></li>
													<?php endforeach; ?>
													<?php /* translators: %d: number of cited URLs not shown. */ ?>
													<?php if ( count( $urls ) > 12 ) : ?><li class="twt-aeo-vis__muted"><?php echo esc_html( sprintf( __( 'and %d more', 'twt-aeo-ultimate' ), count( $urls ) - 12 ) ); ?></li><?php endif; ?>
												</ul>
											<?php elseif ( 'unavailable' !== $verdict ) : ?>
												<p class="twt-aeo-vis__muted"><strong><?php esc_html_e( 'Gave no sources.', 'twt-aeo-ultimate' ); ?></strong> <?php esc_html_e( 'This answer links to no one, so no one was cited in your place.', 'twt-aeo-ultimate' ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $c['follow_ups'] ) && is_array( $c['follow_ups'] ) ) : ?>
												<?php /* translators: %s: engine name. */ ?>
												<p class="twt-aeo-vis__muted"><?php echo esc_html( sprintf( __( 'What they’re likely to ask next, according to %s:', 'twt-aeo-ultimate' ), self::engine_label( $e ) ) ); ?></p>
												<ul class="twt-aeo-vis__followups">
													<?php foreach ( $c['follow_ups'] as $fq ) : ?>
														<li><?php echo esc_html( (string) $fq ); ?></li>
													<?php endforeach; ?>
												</ul>
											<?php elseif ( ! empty( $c['follow_ups_error'] ) && 'unavailable' !== $verdict ) : ?>
												<?php /* translators: %s: why no follow-up questions were recorded. */ ?>
												<p class="twt-aeo-vis__muted"><?php echo esc_html( sprintf( __( 'No follow-up questions recorded: %s', 'twt-aeo-ultimate' ), (string) $c['follow_ups_error'] ) ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $journey_index[ $k ] ) ) : ?>
												<div class="twt-aeo-vis__journey">
													<p class="twt-aeo-vis__muted"><strong><?php esc_html_e( 'The conversation continued', 'twt-aeo-ultimate' ); ?></strong> — <?php esc_html_e( 'each turn asked the engine’s own top follow-up, with the answers before it as history.', 'twt-aeo-ultimate' ); ?></p>
													<?php foreach ( $journey_index[ $k ] as $jc ) :
														$jv    = isset( $jc['verdict'] ) && isset( self::VERDICT_COLOR[ $jc['verdict'] ] ) ? (string) $jc['verdict'] : 'unavailable';
														$jours = isset( $jc['our_urls'] ) ? (array) $jc['our_urls'] : array();
														?>
														<div class="twt-aeo-vis__turn">
															<?php self::echo_svg( self::svg_verdict_dot( $jv, self::verdict_text( $jv ) ) ); ?>
															<?php /* translators: 1: turn number, 2: the follow-up question asked. */ ?>
															<strong><?php echo esc_html( sprintf( __( 'Turn %1$d: “%2$s”', 'twt-aeo-ultimate' ), (int) $jc['turn'], (string) $jc['asked'] ) ); ?></strong>
															— <?php echo esc_html( self::verdict_text( $jv, isset( $jc['citation_surface'] ) ? (string) $jc['citation_surface'] : '' ) ); ?>
															<?php if ( ! empty( $jc['error'] ) ) : ?>
																<p class="twt-aeo-vis__error"><?php echo esc_html( (string) $jc['error'] ); ?></p>
															<?php else : ?>
																<p class="twt-aeo-vis__excerpt"><?php echo esc_html( ! empty( $jc['excerpt'] ) ? (string) $jc['excerpt'] : __( '(no answer text recorded)', 'twt-aeo-ultimate' ) ); ?></p>
																<?php if ( $jours ) : ?>
																	<p class="twt-aeo-vis__muted"><?php esc_html_e( 'Cited you:', 'twt-aeo-ultimate' ); ?>
																		<?php foreach ( array_slice( $jours, 0, 3 ) as $u ) : ?>
																			<a href="<?php echo esc_url( (string) $u ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $u ); ?></a>
																		<?php endforeach; ?>
																	</p>
																<?php elseif ( ! empty( $jc['domains'] ) ) : ?>
																	<?php /* translators: %s: cited domains. */ ?>
																	<p class="twt-aeo-vis__muted"><?php echo esc_html( sprintf( __( 'Cited instead: %s', 'twt-aeo-ultimate' ), implode( ', ', array_slice( array_map( 'strval', (array) $jc['domains'] ), 0, 5 ) ) ) ); ?></p>
																<?php elseif ( self::gave_no_sources( $jc ) ) : ?>
																	<p class="twt-aeo-vis__muted"><?php esc_html_e( 'Gave no sources: no one was cited in your place.', 'twt-aeo-ultimate' ); ?></p>
																<?php endif; ?>
															<?php endif; ?>
														</div>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>
											<p class="twt-aeo-vis__muted twt-aeo-vis__drawer-foot"><?php esc_html_e( 'API approximates the app — the consumer assistant personalises and changes daily; this is the closest reproducible measure.', 'twt-aeo-ultimate' ); ?></p>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php self::render_brand_rollup( $d ); ?>
			<?php self::render_brand_trend( $trend ); ?>
			<?php self::render_correlation( $voice, $d['observed'], $scope ); ?>
			<?php self::render_briefs( $scope_q, $scope_checks, $hosts ); ?>
		</div>
		<?php
	}

	/**
	 * Where the citations landed, and how each tracked brand did.
	 *
	 * The site-wide headline counts an owned-profile citation as a citation,
	 * which is the honest reading — you were cited. This split is what stops
	 * that from papering over the gap underneath it: a business cited only ever
	 * through LinkedIn has a very different job in front of it from one whose
	 * own pages are being quoted, and both read as "cited" up top.
	 */
	private static function render_brand_rollup( array $d ) {
		$run    = isset( $d['selected'] ) && is_array( $d['selected'] ) ? $d['selected'] : null;
		$checks = ( $run && isset( $run['checks'] ) && is_array( $run['checks'] ) ) ? $run['checks'] : array();
		if ( empty( $checks ) ) {
			return;
		}

		$site    = 0;
		$profile = 0;
		$both    = 0;
		$brands  = array();
		foreach ( $checks as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$surface = isset( $c['citation_surface'] ) ? (string) $c['citation_surface'] : '';
			if ( 'site' === $surface ) {
				$site++;
			} elseif ( 'profile' === $surface ) {
				$profile++;
			} elseif ( 'both' === $surface ) {
				$both++;
			}
			$hits = isset( $c['brand_hits'] ) && is_array( $c['brand_hits'] ) ? $c['brand_hits'] : array();
			foreach ( $hits as $hit ) {
				if ( ! is_array( $hit ) || empty( $hit['label'] ) ) {
					continue;
				}
				$label = (string) $hit['label'];
				if ( ! isset( $brands[ $label ] ) ) {
					$brands[ $label ] = array( 'cited' => 0, 'named' => 0, 'checks' => 0 );
				}
				$brands[ $label ]['checks']++;
				$v = isset( $hit['verdict'] ) ? (string) $hit['verdict'] : 'absent';
				if ( 'cited' === $v ) {
					$brands[ $label ]['cited']++;
					$brands[ $label ]['named']++;
				} elseif ( 'named' === $v ) {
					$brands[ $label ]['named']++;
				}
			}
		}

		$owned_total = $site + $profile + $both;
		if ( 0 === $owned_total && empty( $brands ) ) {
			return;
		}
		?>
		<div class="twt-aeo-vis__rollup">
			<?php if ( $owned_total > 0 ) : ?>
				<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Where your citations landed', 'twt-aeo-ultimate' ); ?></h3>
				<ul class="twt-aeo-vis__surfaces">
					<li><strong><?php echo esc_html( number_format_i18n( $site ) ); ?></strong> <?php esc_html_e( 'on a site you own', 'twt-aeo-ultimate' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( $profile ) ); ?></strong> <?php esc_html_e( 'on your page on someone else’s platform', 'twt-aeo-ultimate' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( $both ) ); ?></strong> <?php esc_html_e( 'on both', 'twt-aeo-ultimate' ); ?></li>
				</ul>
				<?php if ( 0 === $site && 0 === $both && $profile > 0 ) : ?>
					<p class="twt-aeo-vis__muted">
						<?php esc_html_e( 'Every citation this run landed on your page on somebody else’s platform rather than on a site you own. That still counts — but your own pages are not what the assistants are reaching for yet.', 'twt-aeo-ultimate' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $brands ) ) : ?>
				<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'By brand', 'twt-aeo-ultimate' ); ?></h3>
				<table class="twt-aeo-vis__brand-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Brand', 'twt-aeo-ultimate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Cited', 'twt-aeo-ultimate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Named', 'twt-aeo-ultimate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Checks', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $brands as $label => $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $label ); ?></td>
								<td><?php echo esc_html( $row['checks'] ? number_format_i18n( (int) round( ( $row['cited'] / $row['checks'] ) * 100 ) ) . '%' : '—' ); ?></td>
								<td><?php echo esc_html( $row['checks'] ? number_format_i18n( (int) round( ( $row['named'] / $row['checks'] ) * 100 ) ) . '%' : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['checks'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'Named counts cited answers too, so it is never lower than cited. A brand with no owned profiles can still be named — it simply cannot be cited until it has somewhere to be cited.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Questions listed before the rest are folded into a counted line. */
	const MAX_BRIEFS = 15;

	/** Runs needed before a brand line means anything. */
	const MIN_TREND_POINTS = 2;

	/**
	 * Is each brand getting cited more, or less?
	 *
	 * Reads `by_brand` off stored run summaries, so it costs one option read
	 * rather than reloading twelve runs of checks. A brand only appears from the
	 * run it was first tracked in — earlier runs genuinely have no data for it,
	 * and back-filling a flat zero would invent a decline that never happened.
	 *
	 * @param array $trend Run summaries, oldest first, demo runs already excluded.
	 */
	private static function render_brand_trend( array $trend ) {
		$series = array();
		foreach ( $trend as $s ) {
			$by = isset( $s['by_brand'] ) && is_array( $s['by_brand'] ) ? $s['by_brand'] : array();
			foreach ( $by as $label => $pct ) {
				$series[ (string) $label ][] = (int) $pct;
			}
		}
		if ( empty( $series ) ) {
			return;
		}
		$plottable = array();
		foreach ( $series as $label => $points ) {
			if ( count( $points ) >= self::MIN_TREND_POINTS ) {
				$plottable[ $label ] = $points;
			}
		}
		?>
		<div class="twt-aeo-vis__brand-trend">
			<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Each brand over time', 'twt-aeo-ultimate' ); ?></h3>
			<?php if ( empty( $plottable ) ) : ?>
				<p class="twt-aeo-vis__muted">
					<?php esc_html_e( 'One run so far. A second real run gives each brand a line to compare against — a single point is a reading, not a trend.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php else : ?>
				<ul class="twt-aeo-vis__brand-trends">
					<?php foreach ( $plottable as $label => $points ) :
						$first = (int) reset( $points );
						$last  = (int) end( $points );
						$delta = $last - $first;
						?>
						<li>
							<span class="twt-aeo-vis__brand-trend-name"><?php echo esc_html( (string) $label ); ?></span>
							<?php self::echo_svg( self::svg_sparkline( $points, self::LEVEL_COLOR['brand'], 160, 36, 100 ) ); ?>
							<span class="twt-aeo-vis__brand-trend-now"><?php echo esc_html( number_format_i18n( $last ) . '%' ); ?></span>
							<span class="twt-aeo-vis__muted">
								<?php
								if ( 0 === $delta ) {
									esc_html_e( 'level', 'twt-aeo-ultimate' );
								} else {
									printf(
										/* translators: %s: signed percentage-point change since the first run this brand was tracked in. */
										esc_html__( '%s pts since first tracked', 'twt-aeo-ultimate' ),
										esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) )
									);
								}
								?>
								<?php
								printf(
									/* translators: %s: number of runs this brand has data for. */
									esc_html__( '· %s runs', 'twt-aeo-ultimate' ),
									esc_html( number_format_i18n( count( $points ) ) )
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What we asked for, next to what actually arrived.
	 *
	 * The probe layer and the observed layer have sat in separate panels never
	 * speaking to each other, which leaves the board's central claim untested:
	 * citations are supposed to bring people. Putting the two numbers on one row
	 * is the only place this module checks itself against reality.
	 *
	 * Deliberately NOT presented as cause and effect. The columns measure
	 * different things over different windows — a sample of questions asked
	 * today, against 28 days of real sessions — so the honest reading is
	 * directional, and the two mismatches are the interesting part:
	 * cited-but-no-visits, and visits-but-not-cited (which means people are
	 * arriving through that assistant for questions your set never asks).
	 *
	 * @param array  $voice    Share of voice per engine, already scope-filtered.
	 * @param array  $observed The observed layer.
	 * @param string $scope    Current scope, '' for all.
	 */
	private static function render_correlation( array $voice, $observed, $scope ) {
		$referrals = is_array( $observed ) && isset( $observed['referrals'] ) ? $observed['referrals'] : null;
		if ( ! is_array( $referrals ) || empty( $referrals['connected'] ) || ! empty( $referrals['error'] ) ) {
			return;   // GA4 not connected: the observed half does not exist, so say nothing.
		}
		$by = isset( $referrals['by_assistant'] ) && is_array( $referrals['by_assistant'] ) ? $referrals['by_assistant'] : array();
		if ( empty( $voice ) ) {
			return;
		}
		?>
		<div class="twt-aeo-vis__correlate">
			<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Cited here, visited from there', 'twt-aeo-ultimate' ); ?></h3>
			<table class="twt-aeo-vis__brand-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Engine', 'twt-aeo-ultimate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cited (this run)', 'twt-aeo-ultimate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sessions (28 days)', 'twt-aeo-ultimate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reading', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $voice as $v ) :
						$e   = isset( $v['engine'] ) ? (string) $v['engine'] : '';
						$pct = isset( $v['our_cited_pct'] ) ? (int) $v['our_cited_pct'] : 0;
						$ans = isset( $v['answered'] ) ? (int) $v['answered'] : 0;
						$ses = isset( $by[ $e ]['sessions'] ) ? (int) $by[ $e ]['sessions'] : 0;

						if ( 0 === $ans ) {
							$reading = __( 'Not asked in this run', 'twt-aeo-ultimate' );
						} elseif ( $pct > 0 && $ses > 0 ) {
							$reading = __( 'Cited, and people arrive', 'twt-aeo-ultimate' );
						} elseif ( $pct > 0 ) {
							$reading = __( 'Cited, but nobody clicks through yet', 'twt-aeo-ultimate' );
						} elseif ( $ses > 0 ) {
							$reading = __( 'People arrive anyway — for questions you are not asking', 'twt-aeo-ultimate' );
						} else {
							$reading = __( 'Neither', 'twt-aeo-ultimate' );
						}
						?>
						<tr>
							<td><?php echo esc_html( self::engine_label( $e ) ); ?></td>
							<td><?php echo 0 === $ans ? '&mdash;' : esc_html( number_format_i18n( $pct ) . '%' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $ses ) ); ?></td>
							<td class="twt-aeo-vis__muted"><?php echo esc_html( $reading ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="twt-aeo-vis__muted">
				<?php esc_html_e( 'These two columns are not cause and effect. The left is a sample of questions asked on your keys in this run; the right is 28 days of real sessions your analytics attributed to that assistant, for your whole site.', 'twt-aeo-ultimate' ); ?>
				<?php if ( '' !== (string) $scope ) : ?>
					<strong><?php esc_html_e( 'You have a scope selected, so the left column is narrowed and the right is not — compare them at “All”.', 'twt-aeo-ultimate' ); ?></strong>
				<?php endif; ?>
				<?php esc_html_e( 'Sessions arriving from an assistant that never cites you in this run usually means people are asking things your question set does not cover.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The questions you are losing, and who is winning them.
	 *
	 * Everything else on this board measures. This is the part that tells you
	 * what to do about it, and it needs no extra API call — the question, the
	 * engines that skipped you and the URLs they cited instead are all already
	 * on the check.
	 *
	 * The important split is between "somebody else was cited here" and "nobody
	 * was". The first is a proven opportunity: the answer exists, it is just not
	 * yours. The second means the assistants answered from memory without
	 * sourcing anyone, which is a far weaker signal and must not be dressed up
	 * as the same thing.
	 *
	 * @param array $questions Questions in the current scope.
	 * @param array $checks    Checks in the current scope.
	 * @param array $hosts     Our hosts.
	 */
	private static function render_briefs( array $questions, array $checks, array $hosts ) {
		$by_qid = array();
		foreach ( $checks as $c ) {
			if ( is_array( $c ) && isset( $c['question_id'] ) ) {
				$by_qid[ (string) $c['question_id'] ][] = $c;
			}
		}

		$rows = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$mine = isset( $by_qid[ (string) $q['id'] ] ) ? $by_qid[ (string) $q['id'] ] : array();
			if ( empty( $mine ) ) {
				continue;
			}
			$absent   = array();
			$answered = 0;
			$rivals   = array();
			foreach ( $mine as $c ) {
				$verdict = isset( $c['verdict'] ) ? (string) $c['verdict'] : '';
				if ( 'unavailable' === $verdict ) {
					continue;   // an errored call is not evidence of absence
				}
				$answered++;
				if ( 'absent' !== $verdict ) {
					continue;
				}
				$absent[] = isset( $c['engine'] ) ? (string) $c['engine'] : '';

				// Who WAS cited on the answer that skipped us.
				$owned = array();
				foreach ( (array) ( isset( $c['owned_domains'] ) && is_array( $c['owned_domains'] ) ? $c['owned_domains'] : array() ) as $od ) {
					$owned[ TWTAEO_Visibility_Verdict::normalize_host( $od ) ] = true;
				}
				foreach ( (array) ( isset( $c['cited_urls'] ) && is_array( $c['cited_urls'] ) ? $c['cited_urls'] : array() ) as $u ) {
					$host = TWTAEO_Visibility_Verdict::host_of( $u );
					if ( '' === $host
						|| TWTAEO_Visibility_Verdict::is_our_host( $host, $hosts )
						|| isset( $owned[ TWTAEO_Visibility_Verdict::normalize_host( $host ) ] ) ) {
						continue;
					}
					if ( ! isset( $rivals[ $host ] ) ) {
						$rivals[ $host ] = array( 'count' => 0, 'url' => (string) $u );
					}
					$rivals[ $host ]['count']++;
				}
			}
			if ( empty( $absent ) ) {
				continue;
			}
			uasort(
				$rivals,
				function ( $a, $b ) {
					return $b['count'] - $a['count'];
				}
			);
			$rows[] = array(
				'q'        => $q,
				'absent'   => $absent,
				'answered' => $answered,
				'rivals'   => $rivals,
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		// A question rivals were cited on is a proven opportunity; sort those
		// first, then by how many engines skipped you.
		usort(
			$rows,
			function ( $a, $b ) {
				$a_has = empty( $a['rivals'] ) ? 0 : 1;
				$b_has = empty( $b['rivals'] ) ? 0 : 1;
				if ( $a_has !== $b_has ) {
					return $b_has - $a_has;
				}
				if ( count( $a['absent'] ) !== count( $b['absent'] ) ) {
					return count( $b['absent'] ) - count( $a['absent'] );
				}
				return strcmp( (string) $a['q']['text'], (string) $b['q']['text'] );
			}
		);

		$total    = count( $rows );
		$shown    = array_slice( $rows, 0, self::MAX_BRIEFS );
		$with_riv = 0;
		foreach ( $rows as $r ) {
			if ( ! empty( $r['rivals'] ) ) {
				$with_riv++;
			}
		}
		?>
		<div class="twt-aeo-vis__briefs">
			<h3 class="twt-aeo-vis__sub"><?php esc_html_e( 'Questions you are not answering', 'twt-aeo-ultimate' ); ?></h3>
			<p class="twt-aeo-vis__muted">
				<?php
				printf(
					/* translators: 1: questions where you were absent, 2: how many of those cited somebody else. */
					esc_html__( 'You were absent from %1$s questions in this scope. On %2$s of them the assistant cited somebody else — those are the ones worth writing for, because the answer already exists and it simply is not yours.', 'twt-aeo-ultimate' ),
					esc_html( number_format_i18n( $total ) ),
					esc_html( number_format_i18n( $with_riv ) )
				);
				?>
			</p>
			<ul class="twt-aeo-vis__brief-list">
				<?php foreach ( $shown as $r ) :
					$q   = $r['q'];
					$fix = self::fix_link( $q );
					?>
					<li class="twt-aeo-vis__brief">
						<p class="twt-aeo-vis__brief-q"><?php echo esc_html( (string) $q['text'] ); ?></p>
						<p class="twt-aeo-vis__muted">
							<?php
							$labels = array();
							foreach ( $r['absent'] as $e ) {
								$labels[] = self::engine_label( $e );
							}
							printf(
								/* translators: 1: engine names, 2: number of engines that answered. */
								esc_html__( 'Absent on %1$s (of %2$s that answered)', 'twt-aeo-ultimate' ),
								esc_html( implode( ', ', $labels ) ),
								esc_html( number_format_i18n( (int) $r['answered'] ) )
							);
							?>
						</p>
						<?php if ( ! empty( $r['rivals'] ) ) : ?>
							<p class="twt-aeo-vis__brief-rivals">
								<?php esc_html_e( 'Cited instead:', 'twt-aeo-ultimate' ); ?>
								<?php $i = 0; foreach ( $r['rivals'] as $host => $info ) : if ( $i++ >= 3 ) { break; } ?>
									<a href="<?php echo esc_url( $info['url'] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $host ); ?></a><?php echo $i < min( 3, count( $r['rivals'] ) ) ? ', ' : ''; ?>
								<?php endforeach; ?>
							</p>
						<?php else : ?>
							<p class="twt-aeo-vis__muted">
								<?php esc_html_e( 'No sources cited at all — the assistants answered from memory, so there is no page to outrank here, only an entity to be better known as.', 'twt-aeo-ultimate' ); ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $fix['href'] ) ) : ?>
							<p><a class="twt-aeo-vis__brief-fix" href="<?php echo esc_url( $fix['href'] ); ?>"><?php echo esc_html( $fix['label'] ); ?> &rarr;</a></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $total > count( $shown ) ) : ?>
				<p class="twt-aeo-vis__muted">
					<?php
					printf(
						/* translators: %s: number of further questions not listed. */
						esc_html__( 'and %s more not listed here — the table above carries every one of them.', 'twt-aeo-ultimate' ),
						esc_html( number_format_i18n( $total - count( $shown ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ═══════════════════════════════ helpers ═══════════════════════════════ */

	/** Per-engine stats for the chips (port of engineStats). */
	/**
	 * Whether an answer gave no sources at all: it was answered, did not cite
	 * you, and cites nobody else either. "Not cited" then means no one was
	 * cited in your place — the engine linked to no one — rather than a
	 * competitor winning the answer.
	 *
	 * @param array $c A check.
	 * @return bool
	 */
	public static function gave_no_sources( $c ) {
		if ( ! is_array( $c ) || empty( $c['verdict'] ) || ! in_array( $c['verdict'], array( 'absent', 'named' ), true ) || ! empty( $c['error'] ) ) {
			return false;
		}
		return empty( $c['domains'] ) && empty( $c['cited_urls'] );
	}

	private static function engine_stats( array $checks, array $engines ) {
		$out = array();
		foreach ( $engines as $engine ) {
			$mine = array();
			foreach ( $checks as $c ) {
				if ( is_array( $c ) && isset( $c['engine'] ) && (string) $c['engine'] === (string) $engine ) {
					$mine[] = $c;
				}
			}
			$unavailable = 0;
			$cited       = 0;
			$named       = 0;
			$wrong       = 0;
			$no_sources  = 0;
			foreach ( $mine as $c ) {
				$v = isset( $c['verdict'] ) ? $c['verdict'] : '';
				if ( self::gave_no_sources( $c ) ) {
					$no_sources++;
				}
				if ( 'unavailable' === $v ) {
					$unavailable++;
				} elseif ( 'cited' === $v ) {
					$cited++;
				} elseif ( 'named' === $v ) {
					$named++;
				}
				if ( isset( $c['accuracy'] ) && 'wrong' === $c['accuracy'] ) {
					$wrong++;
				}
			}
			$answered = count( $mine ) - $unavailable;
			$out[]    = array(
				'engine'      => (string) $engine,
				'checks'      => count( $mine ),
				'answered'    => $answered,
				'unavailable' => $unavailable,
				'wrong'       => $wrong,
				'no_sources'  => $no_sources,
				'cited_pct'   => $answered ? (int) round( ( $cited / $answered ) * 100 ) : 0,
				'named_pct'   => $answered ? (int) round( ( ( $cited + $named ) / $answered ) * 100 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Did the selected run ask anything in this scope?
	 *
	 * @param string $value   Scope value from the dropdown.
	 * @param array  $covered Keys built in gather(): level, and level:scope_id.
	 * @return bool
	 */
	private static function scope_is_covered( $value, array $covered ) {
		if ( '' === $value ) {
			return true;
		}
		if ( 'products' === $value ) {
			return isset( $covered['collection'] ) || isset( $covered['type'] ) || isset( $covered['product'] );
		}
		if ( 'posts' === $value ) {
			return isset( $covered['post'] );
		}
		if ( 0 === strpos( $value, 'postcat:' ) ) {
			// Post categories are matched per post, not by scope_id — the run
			// either has post questions or it does not.
			return isset( $covered['post'] );
		}
		return isset( $covered[ $value ] );
	}

	/**
	 * Questions in a scope. '' = all; 'products' = collection/type/product;
	 * 'posts' = post; 'collection:ID' / 'type:NAME' / 'brand:SCOPE_ID' = that
	 * level + scope_id; 'postcat:ID' = post questions whose post is in that
	 * category.
	 *
	 * Every value MUST be either a bare keyword handled by a case below or
	 * "kind:arg" whose kind has a case. An unknown kind keeps NOTHING, which
	 * shows up as a board with no charts rather than as an error.
	 */
	private static function filter_questions_for_scope( array $questions, $scope ) {
		$scope = (string) $scope;
		$out   = array();
		$kind  = $scope;
		$arg   = '';
		if ( false !== strpos( $scope, ':' ) ) {
			list( $kind, $arg ) = explode( ':', $scope, 2 );
		}
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$level = isset( $q['level'] ) ? (string) $q['level'] : '';
			$sid   = isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '';
			$keep  = false;
			switch ( $kind ) {
				case '':
					$keep = true;
					break;
				case 'products':
					$keep = in_array( $level, array( 'collection', 'type', 'product' ), true );
					break;
				case 'posts':
					$keep = 'post' === $level;
					break;
				case 'collection':
					$keep = 'collection' === $level && $sid === $arg;
					break;
				case 'type':
					$keep = 'type' === $level && $sid === $arg;
					break;
				case 'brand':
					$keep = 'brand' === $level && $sid === $arg;
					break;
				case 'postcat':
					$keep = 'post' === $level && ctype_digit( $sid ) && ctype_digit( $arg ) && has_category( (int) $arg, (int) $sid );
					break;
			}
			if ( $keep ) {
				$out[] = $q;
			}
		}
		return $out;
	}

	/** "12 categories · 4 types · 80 products · 20 posts in scope" or the picked scope's count. */
	private static function scope_items_label( $scope, array $scope_q, array $counts ) {
		$scope = (string) $scope;
		if ( '' === $scope ) {
			return sprintf(
				/* translators: 1-4: counts */
				__( '%1$d categories · %2$d types · %3$d products · %4$d posts in scope', 'twt-aeo-ultimate' ),
				(int) $counts['collections'],
				(int) $counts['types'],
				(int) $counts['products'],
				(int) $counts['posts']
			);
		}
		$ids = array();
		foreach ( $scope_q as $q ) {
			$ids[ (string) $q['scope_id'] ] = true;
		}
		$n = count( $ids );
		if ( 'products' === $scope ) {
			/* translators: %d: number of catalogue items the scope covers. */
			return sprintf( __( '%d catalogue items in scope', 'twt-aeo-ultimate' ), $n );
		}
		if ( 'posts' === $scope || 0 === strpos( $scope, 'postcat:' ) ) {
			/* translators: %d: number of posts the scope covers. */
			return sprintf( _n( '%d post in scope', '%d posts in scope', $n, 'twt-aeo-ultimate' ), $n );
		}
		/* translators: %d: number of items the scope covers. */
		return sprintf( _n( '%d item in scope', '%d items in scope', $n, 'twt-aeo-ultimate' ), $n );
	}

	/** Where to go to fix a finding for this question. */
	private static function fix_link( array $q ) {
		$level  = isset( $q['level'] ) ? (string) $q['level'] : '';
		$family = isset( $q['family'] ) ? (string) $q['family'] : '';
		$sid    = isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '';
		$company = add_query_arg( array( 'page' => 'twt-aeo-eeat', 'tab' => 'company' ), admin_url( 'admin.php' ) );
		$faq     = add_query_arg( array( 'page' => 'twt-aeo-schema-detector', 'tab' => 'faq' ), admin_url( 'admin.php' ) );
		if ( 'company' === $level ) {
			return array(
				'href'  => $company,
				'label' => in_array( $family, self::COMPANY_TRUST_FAMILIES, true ) ? __( 'Company profile — policies', 'twt-aeo-ultimate' ) : __( 'Company profile', 'twt-aeo-ultimate' ),
			);
		}
		if ( 'brand' === $level ) {
			// Content-shaped questions ("what do reviews say", "alternatives to X")
			// are answered by publishing something; the rest are answered by
			// registering what the brand owns so its citations are counted.
			if ( in_array( $family, self::BRAND_CONTENT_FAMILIES, true ) ) {
				return array( 'href' => $faq, 'label' => __( 'FAQ content', 'twt-aeo-ultimate' ) );
			}
			return array(
				'href'  => self::page_url() . '#twt-aeo-vis-brands',
				'label' => __( 'What counts as you', 'twt-aeo-ultimate' ),
			);
		}
		if ( 'collection' === $level || 'type' === $level ) {
			$modules = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
			if ( class_exists( 'WooCommerce' ) && is_array( $modules ) && in_array( 'smart-collections', $modules, true ) ) {
				return array( 'href' => self::admin_page( 'twt-aeo-collections' ), 'label' => __( 'Smart Collections', 'twt-aeo-ultimate' ) );
			}
			return array( 'href' => self::admin_page( 'twt-aeo-command-center' ), 'label' => __( 'Command Center', 'twt-aeo-ultimate' ) );
		}
		if ( 'product' === $level ) {
			if ( in_array( $family, self::PRODUCT_CATALOG_FAMILIES, true ) ) {
				$edit = ctype_digit( $sid ) ? get_edit_post_link( (int) $sid, 'raw' ) : '';
				if ( $edit ) {
					return array( 'href' => $edit, 'label' => __( 'Edit product', 'twt-aeo-ultimate' ) );
				}
			}
			return array( 'href' => $faq, 'label' => __( 'Product FAQs', 'twt-aeo-ultimate' ) );
		}
		if ( 'post' === $level ) {
			$edit = ctype_digit( $sid ) ? get_edit_post_link( (int) $sid, 'raw' ) : '';
			if ( $edit ) {
				return array( 'href' => $edit, 'label' => __( 'Edit post', 'twt-aeo-ultimate' ) );
			}
		}
		return array( 'href' => '', 'label' => '—' );
	}

	private static function verdict_text( $verdict, $surface = '' ) {
		switch ( $verdict ) {
			case 'cited':
				if ( 'profile' === $surface ) {
					return __( 'Cited — linked to your page on another platform, not to a site you own', 'twt-aeo-ultimate' );
				}
				if ( 'both' === $surface ) {
					return __( 'Cited — linked to a site you own and to your page on another platform', 'twt-aeo-ultimate' );
				}
				return __( 'Cited — linked to a site you own', 'twt-aeo-ultimate' );
			case 'named':
				return __( 'Named — mentioned, no link', 'twt-aeo-ultimate' );
			case 'absent':
				return __( 'Absent — not mentioned', 'twt-aeo-ultimate' );
			default:
				return __( 'Unavailable — no answer recorded', 'twt-aeo-ultimate' );
		}
	}

	private static function engine_label( $engine ) {
		return isset( TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] ) ? TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] : (string) $engine;
	}

	private static function delta( $now, $before ) {
		$d = (int) $now - (int) $before;
		if ( 0 === $d ) {
			return __( 'no change', 'twt-aeo-ultimate' );
		}
		return ( $d > 0 ? '+' : '' ) . $d . ' ' . __( 'pts', 'twt-aeo-ultimate' );
	}

	/** A started_at that may be a MySQL datetime, ISO string or unix int. */
	private static function fmt_date( $value, $with_time = false ) {
		if ( is_numeric( $value ) ) {
			$ts = (int) $value;
		} else {
			$ts = $value ? strtotime( (string) $value ) : false;
		}
		if ( ! $ts ) {
			return '—';
		}
		$format = $with_time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' );
		return wp_date( $format, $ts );
	}

	private static function admin_page( $slug ) {
		return add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
	}

	private static function page_url() {
		return self::admin_page( TWTAEO_Visibility_Types::PAGE_SLUG );
	}

	/** Echo SVG we built ourselves (every data value inside it is already escaped). */
	private static function echo_svg( $svg ) {
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by svg_* with escaped values.
	}

	/** Echo HTML produced by one of our own render_* methods (already escaped). */
	private static function echo_html( $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produced by render_question_list with escaped values.
	}

	/* ═══════════════════════════════ SVG ═══════════════════════════════ */

	private static function n( $v ) {
		return esc_attr( rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' ) );
	}

	/** SVG arc path for a ring segment from $start to $end degrees (clockwise from 12). */
	private static function ring_path( $cx, $cy, $ro, $ri, $start, $end ) {
		$rad   = function ( $deg ) {
			return ( $deg - 90 ) * M_PI / 180;
		};
		$large = ( $end - $start ) > 180 ? 1 : 0;
		$ox1   = $cx + $ro * cos( $rad( $start ) );
		$oy1   = $cy + $ro * sin( $rad( $start ) );
		$ox2   = $cx + $ro * cos( $rad( $end ) );
		$oy2   = $cy + $ro * sin( $rad( $end ) );
		$ix1   = $cx + $ri * cos( $rad( $end ) );
		$iy1   = $cy + $ri * sin( $rad( $end ) );
		$ix2   = $cx + $ri * cos( $rad( $start ) );
		$iy2   = $cy + $ri * sin( $rad( $start ) );
		return 'M ' . self::n( $ox1 ) . ' ' . self::n( $oy1 )
			. ' A ' . self::n( $ro ) . ' ' . self::n( $ro ) . ' 0 ' . $large . ' 1 ' . self::n( $ox2 ) . ' ' . self::n( $oy2 )
			. ' L ' . self::n( $ix1 ) . ' ' . self::n( $iy1 )
			. ' A ' . self::n( $ri ) . ' ' . self::n( $ri ) . ' 0 ' . $large . ' 0 ' . self::n( $ix2 ) . ' ' . self::n( $iy2 )
			. ' Z';
	}

	/** The drawn slices: ours (accent), up to $max_named competitors (hues), then "other". */
	private static function donut_slices( array $slices, $max_named = 6 ) {
		$ours   = array();
		$others = array();
		foreach ( $slices as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$row = array(
				'label' => isset( $s['domain'] ) ? (string) $s['domain'] : '',
				'count' => isset( $s['count'] ) ? (int) $s['count'] : 0,
				'ours'  => ! empty( $s['ours'] ),
			);
			if ( $row['ours'] ) {
				$ours[] = $row;
			} else {
				$others[] = $row;
			}
		}
		$named = array_slice( $others, 0, $max_named );
		$rest  = array_slice( $others, $max_named );
		$rest_count = 0;
		foreach ( $rest as $r ) {
			$rest_count += $r['count'];
		}
		$drawn = array();
		foreach ( $ours as $s ) {
			$drawn[] = array( 'label' => $s['label'], 'count' => $s['count'], 'color' => self::OURS_COLOR, 'ours' => true );
		}
		foreach ( $named as $i => $s ) {
			$drawn[] = array( 'label' => $s['label'], 'count' => $s['count'], 'color' => self::COMPETITOR_COLORS[ min( $i, count( self::COMPETITOR_COLORS ) - 1 ) ], 'ours' => false );
		}
		if ( $rest_count > 0 ) {
			/* translators: %d: number of remaining cited domains grouped as "other". */
			$drawn[] = array( 'label' => sprintf( __( 'other (%d domains)', 'twt-aeo-ultimate' ), count( $rest ) ), 'count' => $rest_count, 'color' => self::OTHER_COLOR, 'ours' => false );
		}
		return array_values( array_filter( $drawn, function ( $s ) {
			return $s['count'] > 0;
		} ) );
	}

	/** Legend rows beside a donut — same colours, same order. */
	private static function donut_legend( array $slices ) {
		return self::donut_slices( $slices );
	}

	/** Share-of-voice donut. A single slice draws a full ring; no slices draw an empty grey ring. */
	private static function svg_donut( array $slices, $center_label, $center_sub = '', $size = 150 ) {
		$cx    = $size / 2;
		$cy    = $size / 2;
		$ro    = $size / 2 - 2;
		$ri    = $ro * 0.62;
		$drawn = self::donut_slices( $slices );
		$total = 0;
		foreach ( $drawn as $s ) {
			$total += $s['count'];
		}
		$svg = '<svg class="twt-aeo-vis__donut" viewBox="0 0 ' . self::n( $size ) . ' ' . self::n( $size ) . '" width="' . self::n( $size ) . '" height="' . self::n( $size ) . '" role="img" aria-label="' . esc_attr__( 'Share of voice', 'twt-aeo-ultimate' ) . '">';
		if ( 0 === $total ) {
			$svg .= '<circle cx="' . self::n( $cx ) . '" cy="' . self::n( $cy ) . '" r="' . self::n( ( $ro + $ri ) / 2 ) . '" fill="none" stroke="' . esc_attr( self::OTHER_COLOR ) . '" stroke-width="' . self::n( $ro - $ri ) . '"/>';
		} elseif ( 1 === count( $drawn ) ) {
			$svg .= '<circle cx="' . self::n( $cx ) . '" cy="' . self::n( $cy ) . '" r="' . self::n( ( $ro + $ri ) / 2 ) . '" fill="none" stroke="' . esc_attr( $drawn[0]['color'] ) . '" stroke-width="' . self::n( $ro - $ri ) . '"><title>' . esc_html( $drawn[0]['label'] . ': ' . $drawn[0]['count'] . ' (100%)' ) . '</title></circle>';
		} else {
			$angle = 0;
			foreach ( $drawn as $s ) {
				$start  = $angle;
				$angle += ( $s['count'] / $total ) * 360;
				$end    = min( $angle, $start + 359.99 );
				$svg   .= '<path d="' . self::ring_path( $cx, $cy, $ro, $ri, $start, $end ) . '" fill="' . esc_attr( $s['color'] ) . '" stroke="#ffffff" stroke-width="1.5"><title>' . esc_html( $s['label'] . ': ' . $s['count'] . ' (' . round( ( $s['count'] / $total ) * 100 ) . '%)' ) . '</title></path>';
			}
		}
		$svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $center_sub ? $cy - 2 : $cy + 6 ) . '" text-anchor="middle" font-size="' . self::n( $size * 0.16 ) . '" font-weight="700" fill="#1e293b">' . esc_html( $center_label ) . '</text>';
		if ( $center_sub ) {
			$svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $cy + $size * 0.12 ) . '" text-anchor="middle" font-size="' . self::n( $size * 0.075 ) . '" fill="#64748b">' . esc_html( $center_sub ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/** One horizontal stacked bar, five level segments, labels under it. */
	private static function svg_allocation_bar( array $by_level, $height = 18 ) {
		$width = 600;
		$total = 0;
		foreach ( TWTAEO_Visibility_Types::LEVELS as $level ) {
			$total += isset( $by_level[ $level ] ) ? max( 0, (int) $by_level[ $level ] ) : 0;
		}
		$svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Questions per level', 'twt-aeo-ultimate' ) . '">';
		if ( 0 === $total ) {
			$svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" rx="4" fill="' . esc_attr( self::OTHER_COLOR ) . '"/>';
		} else {
			$x = 0;
			foreach ( TWTAEO_Visibility_Types::LEVELS as $level ) {
				$v = isset( $by_level[ $level ] ) ? max( 0, (int) $by_level[ $level ] ) : 0;
				if ( $v <= 0 ) {
					continue;
				}
				$w    = ( $v / $total ) * $width;
				$svg .= '<rect data-level="' . esc_attr( $level ) . '" x="' . self::n( $x ) . '" y="0" width="' . self::n( max( $w, 1 ) ) . '" height="' . $height . '" fill="' . esc_attr( self::LEVEL_COLOR[ $level ] ) . '"><title>' . esc_html( TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] . ': ' . $v . ' ' . __( 'questions', 'twt-aeo-ultimate' ) . ' (' . round( ( $v / $total ) * 100 ) . '%)' ) . '</title></rect>';
				$x   += $w;
			}
		}
		$svg .= '</svg><div class="twt-aeo-vis__bar-legend">';
		foreach ( TWTAEO_Visibility_Types::LEVELS as $level ) {
			$v    = isset( $by_level[ $level ] ) ? max( 0, (int) $by_level[ $level ] ) : 0;
			$svg .= '<span data-level="' . esc_attr( $level ) . '"><i class="twt-aeo-vis__swatch" style="background:' . esc_attr( self::LEVEL_COLOR[ $level ] ) . '"></i>' . esc_html( TWTAEO_Visibility_Types::LEVEL_LABEL[ $level ] ) . ' — <b data-level-n="' . esc_attr( $level ) . '">' . esc_html( $v ) . '</b></span>';
		}
		$svg .= '</div>';
		return $svg;
	}

	/** A small line over a handful of runs; null points break the line. */
	private static function svg_sparkline( array $points, $color, $width = 200, $height = 44, $max = 100 ) {
		$pad = 4;
		$n   = count( $points );
		$x_of = function ( $i ) use ( $n, $width, $pad ) {
			return $n <= 1 ? $width / 2 : $pad + ( $i / ( $n - 1 ) ) * ( $width - $pad * 2 );
		};
		$y_of = function ( $v ) use ( $height, $pad, $max ) {
			return $height - $pad - ( max( 0, min( $max, $v ) ) / $max ) * ( $height - $pad * 2 );
		};
		$segments = array();
		$current  = array();
		foreach ( $points as $i => $p ) {
			if ( ! isset( $p['value'] ) || null === $p['value'] ) {
				if ( $current ) {
					$segments[] = $current;
				}
				$current = array();
				continue;
			}
			$current[] = array( 'x' => $x_of( $i ), 'y' => $y_of( (float) $p['value'] ), 'label' => isset( $p['label'] ) ? (string) $p['label'] : '', 'value' => (float) $p['value'] );
		}
		if ( $current ) {
			$segments[] = $current;
		}
		$svg  = '<svg class="twt-aeo-vis__sparkline" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" role="img" aria-label="' . esc_attr__( 'Trend', 'twt-aeo-ultimate' ) . '">';
		$svg .= '<line x1="' . $pad . '" y1="' . ( $height - $pad ) . '" x2="' . ( $width - $pad ) . '" y2="' . ( $height - $pad ) . '" stroke="#d9d8d3" stroke-width="1"/>';
		foreach ( $segments as $seg ) {
			if ( count( $seg ) > 1 ) {
				$pts = array();
				foreach ( $seg as $p ) {
					$pts[] = self::n( $p['x'] ) . ',' . self::n( $p['y'] );
				}
				$svg .= '<polyline points="' . implode( ' ', $pts ) . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
			}
			foreach ( $seg as $p ) {
				$svg .= '<circle cx="' . self::n( $p['x'] ) . '" cy="' . self::n( $p['y'] ) . '" r="2.5" fill="' . esc_attr( $color ) . '"><title>' . esc_html( $p['label'] . ': ' . (int) $p['value'] . '%' ) . '</title></circle>';
			}
		}
		$svg .= '</svg>';
		return $svg;
	}

	/** Verdict dot: filled for cited/named/absent, hollow for unavailable. */
	private static function svg_verdict_dot( $verdict, $title ) {
		$hollow = 'unavailable' === $verdict;
		$color  = isset( self::VERDICT_COLOR[ $verdict ] ) ? self::VERDICT_COLOR[ $verdict ] : self::VERDICT_COLOR['absent'];
		return '<svg class="twt-aeo-vis__dot" viewBox="0 0 16 16" width="16" height="16" role="img" aria-label="' . esc_attr( $title ) . '"><title>' . esc_html( $title ) . '</title><circle cx="8" cy="8" r="6" fill="' . ( $hollow ? '#ffffff' : esc_attr( $color ) ) . '" stroke="' . ( $hollow ? '#8c9196' : esc_attr( $color ) ) . '" stroke-width="1.5"/></svg>';
	}
}
