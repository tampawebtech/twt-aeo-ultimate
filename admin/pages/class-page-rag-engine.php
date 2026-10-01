<?php
/**
 * RAG Engine Page — how AI retrieval sees the site, and what it is missing.
 *
 * One screen, five tabs: an Overview with setup status, Documents (upload and
 * search), Chunk View (how a page is cut into passages), Search Placement
 * (Google + Bing queries the site almost wins) and AI Citations (questions AI
 * engines answered without citing the site). The last two only read data the
 * site already has — Search Console, Bing Webmaster and past AI Visibility
 * runs — and match it against the uploaded documents.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_RAG_Engine {

	const SLUG = 'twt-aeo-rag';

	const TABS = array( 'overview', 'documents', 'chunks', 'placement', 'citations' );

	/** Old standalone screens and the tab each now lives on. */
	const LEGACY_SLUGS = array(
		'twt-aeo-chunk-view' => 'chunks',
		'twt-aeo-documents'  => 'documents',
	);

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy' ) );
		add_action( 'admin_post_twtaeo_rag_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	/**
	 * The URL of a tab.
	 *
	 * @param string $tab  One of TABS.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/** Send bookmarks and 2.23-era links for Chunk View / Documents to their tab. */
	public static function redirect_legacy() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( ! isset( self::LEGACY_SLUGS[ $page ] ) || 'GET' !== $method ) {
			return;
		}
		$keep = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view'] ) ) {
			$keep['view'] = absint( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		wp_safe_redirect( self::url( self::LEGACY_SLUGS[ $page ], $keep ) );
		exit;
	}

	public static function handle_refresh() {
		check_admin_referer( 'twtaeo_rag_refresh' );
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		TWTAEO_RAG_Signals::clear_cache();
		$tab = isset( $_POST['tab'] ) && in_array( $_POST['tab'], self::TABS, true ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'placement';
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/* ─────────────────────────── render ─────────────────────────── */

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'overview';
		}

		$labels = array(
			'overview'  => __( 'Overview', 'twt-aeo-ultimate' ),
			'documents' => __( 'Documents', 'twt-aeo-ultimate' ),
			'chunks'    => __( 'Chunk View', 'twt-aeo-ultimate' ),
			'placement' => __( 'Search Placement', 'twt-aeo-ultimate' ),
			'citations' => __( 'AI Citations', 'twt-aeo-ultimate' ),
		);
		?>
		<div class="wrap twtaeo-rag">
			<h1><?php esc_html_e( 'RAG Engine', 'twt-aeo-ultimate' ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'documents':
					TWTAEO_Page_Documents::render();
					break;
				case 'chunks':
					TWTAEO_Page_Chunk_View::render( true );
					break;
				case 'placement':
					self::render_placement();
					break;
				case 'citations':
					self::render_citations();
					break;
				default:
					self::render_overview();
			}
			?>
		</div>
		<?php
	}

	/* ─────────────────────────── Overview ─────────────────────────── */

	private static function render_overview() {
		$status = TWTAEO_RAG_Signals::status();
		$counts = TWTAEO_Content_Index::counts();
		$site   = TWTAEO_Doc_Library::site_state();
		$run    = $status['visibility']['run'];
		?>
		<p style="max-width:860px;font-size:14px;line-height:1.6;">
			<?php esc_html_e( 'AI answer engines like ChatGPT, Perplexity and Google\'s AI Overviews don\'t read your pages top to bottom — they use retrieval-augmented generation (RAG): they cut the web into short passages, pull back the few passages that best match a question, and write their answer from those. The RAG Engine shows your site the way that process sees it. Chunk View shows how each page is cut into passages and which ones fall apart on their own; Documents holds the spec sheets, manuals and FAQs your business already works from; and Search Placement and AI Citations compare those documents with the searches you nearly rank for and the questions AI engines answered without citing you — so you can see exactly which answers your site is missing, where they belong, and copy them straight into the page.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php
		$missing = array();
		if ( ! $status['google']['ready'] ) {
			$missing[] = __( 'Google Search Console is not connected', 'twt-aeo-ultimate' );
		}
		if ( ! $status['bing']['ready'] ) {
			$missing[] = __( 'Bing Webmaster Tools is not connected', 'twt-aeo-ultimate' );
		}
		if ( ! $run ) {
			$missing[] = __( 'no AI Visibility citation check has been run', 'twt-aeo-ultimate' );
		}
		if ( count( $missing ) === 3 ) :
			?>
			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p>
					<strong><?php esc_html_e( 'The RAG Engine is running on documents alone.', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'You haven\'t connected Google Search Console or Bing Webmaster Tools, and no AI citation check has been run yet. Document search and Chunk View still work, but the RAG Engine can\'t tell you which searches you nearly rank for or which AI answers leave you out until at least one of these is set up — see the checklist below.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Setup', 'twt-aeo-ultimate' ); ?></h2>
		<table class="widefat striped" style="max-width:980px;">
			<tbody>
			<?php
			self::status_row(
				$counts['doc']['sources'] > 0,
				__( 'Documents', 'twt-aeo-ultimate' ),
				$counts['doc']['sources'] > 0
					/* translators: 1: documents, 2: passages. */
					? sprintf( __( '%1$s documents, %2$s passages.', 'twt-aeo-ultimate' ), number_format_i18n( $counts['doc']['sources'] ), number_format_i18n( $counts['doc']['passages'] ) )
					: __( 'Upload the documents your business works from — spec sheets, manuals, FAQs.', 'twt-aeo-ultimate' ),
				self::url( 'documents' ),
				$counts['doc']['sources'] > 0 ? __( 'Manage', 'twt-aeo-ultimate' ) : __( 'Upload documents', 'twt-aeo-ultimate' )
			);
			self::status_row(
				'done' === $site['status'],
				__( 'Your pages', 'twt-aeo-ultimate' ),
				'done' === $site['status']
					/* translators: %s: number of pages. */
					? sprintf( __( '%s pages and posts indexed; updates are picked up automatically.', 'twt-aeo-ultimate' ), number_format_i18n( $counts['post']['sources'] ) )
					: __( 'Index your published pages so they can be compared with your documents.', 'twt-aeo-ultimate' ),
				self::url( 'documents' ),
				'done' === $site['status'] ? __( 'Re-index pages', 'twt-aeo-ultimate' ) : __( 'Index my pages', 'twt-aeo-ultimate' )
			);
			self::status_row(
				$status['google']['ready'],
				__( 'Google Search Console', 'twt-aeo-ultimate' ),
				$status['google']['ready']
					/* translators: %s: Search Console property. */
					? sprintf( __( 'Connected (%s). Feeds Search Placement.', 'twt-aeo-ultimate' ), $status['google']['site_url'] )
					: ( $status['google']['connected']
						? __( 'Google is connected, but no Search Console property is selected.', 'twt-aeo-ultimate' )
						: __( 'Not connected. Search Placement needs it to show the Google searches you nearly rank for.', 'twt-aeo-ultimate' ) ),
				$status['setup_url'],
				$status['google']['ready'] ? '' : __( 'Connect Google', 'twt-aeo-ultimate' )
			);
			self::status_row(
				$status['bing']['ready'],
				__( 'Bing Webmaster Tools', 'twt-aeo-ultimate' ),
				$status['bing']['ready']
					? __( 'Connected. Feeds Search Placement.', 'twt-aeo-ultimate' )
					: __( 'Not connected. Search Placement needs it to show the Bing searches you nearly rank for.', 'twt-aeo-ultimate' ),
				$status['setup_url'],
				$status['bing']['ready'] ? '' : __( 'Connect Bing', 'twt-aeo-ultimate' )
			);
			self::status_row(
				(bool) $run,
				__( 'AI citation check', 'twt-aeo-ultimate' ),
				$run
					/* translators: %s: time since the run. */
					? sprintf( __( 'Last run %s ago. Feeds AI Citations — the RAG Engine reads these results and never re-runs them. A new run shows up there by itself as soon as it has answers; nothing needs rebuilding.', 'twt-aeo-ultimate' ), human_time_diff( strtotime( $run['started_at'] . ' UTC' ), time() ) ) . self::partial_note( $run )
					: ( $status['visibility']['module']
						? __( 'No AI Visibility run yet. Run one to see which AI answers leave you out.', 'twt-aeo-ultimate' )
						: __( 'The AI Visibility module is switched off. Turn it on under Modules, then run a check.', 'twt-aeo-ultimate' ) ),
				$status['visibility']['module'] ? $status['visibility_url'] : $status['modules_url'],
				$run ? '' : ( $status['visibility']['module'] ? __( 'Run a citation check', 'twt-aeo-ultimate' ) : __( 'Open Modules', 'twt-aeo-ultimate' ) )
			);
			?>
			</tbody>
		</table>

		<h2 style="margin-top:24px;"><?php esc_html_e( 'Where to go', 'twt-aeo-ultimate' ); ?></h2>
		<ul style="list-style:disc;margin-left:20px;max-width:860px;">
			<li><strong><?php esc_html_e( 'Documents', 'twt-aeo-ultimate' ); ?></strong> — <?php esc_html_e( 'upload files, index your pages, and search both side by side.', 'twt-aeo-ultimate' ); ?></li>
			<li><strong><?php esc_html_e( 'Chunk View', 'twt-aeo-ultimate' ); ?></strong> — <?php esc_html_e( 'see how any page is cut into passages and which ones don\'t make sense on their own.', 'twt-aeo-ultimate' ); ?></li>
			<li><strong><?php esc_html_e( 'Search Placement', 'twt-aeo-ultimate' ); ?></strong> — <?php esc_html_e( 'Google and Bing searches where you rank just off the top (positions 4–15 by default), with the document passages that answer what the ranking page does not.', 'twt-aeo-ultimate' ); ?></li>
			<li><strong><?php esc_html_e( 'AI Citations', 'twt-aeo-ultimate' ); ?></strong> — <?php esc_html_e( 'questions from your last AI Visibility run where an engine left you out or cited someone else, with the document passages that answer them.', 'twt-aeo-ultimate' ); ?></li>
		</ul>
		<p class="description" style="max-width:860px;">
			<?php esc_html_e( 'Nothing on this screen changes your pages. Every suggestion comes with a Copy button and an Edit page link — paste the text wherever your theme or page builder allows.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
	}

	/**
	 * " Partial: 40 of 120 checks — it paused at the daily limit." when the run
	 * did not finish, so a short list of results is not mistaken for all of them.
	 */
	private static function partial_note( $run ) {
		if ( empty( $run['partial'] ) ) {
			return '';
		}
		$why = array(
			'paused'  => __( 'it paused at the daily spending limit; resume it on the AI Visibility screen', 'twt-aeo-ultimate' ),
			'running' => __( 'it has not finished; open the AI Visibility screen to continue it', 'twt-aeo-ultimate' ),
			'stopped' => __( 'it was stopped before the end', 'twt-aeo-ultimate' ),
		);

		return ' ' . sprintf(
			/* translators: 1: checks done, 2: checks planned, 3: reason. */
			__( 'Partial run: %1$s of %2$s checks — %3$s.', 'twt-aeo-ultimate' ),
			number_format_i18n( (int) $run['checked'] ),
			number_format_i18n( (int) $run['total'] ),
			isset( $why[ $run['status'] ] ) ? $why[ $run['status'] ] : $why['running']
		);
	}

	private static function status_row( $ok, $label, $detail, $url, $action ) {
		?>
		<tr>
			<td style="width:28px;font-size:16px;"><?php echo $ok ? '<span style="color:#00a32a;">✓</span>' : '<span style="color:#dba617;">○</span>'; ?></td>
			<td style="width:200px;"><strong><?php echo esc_html( $label ); ?></strong></td>
			<td><?php echo esc_html( $detail ); ?></td>
			<td style="width:170px;text-align:right;">
				<?php if ( '' !== $action ) : ?>
					<a class="button <?php echo $ok ? '' : 'button-primary'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action ); ?></a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/* ─────────────────────────── Search Placement ─────────────────────────── */

	private static function render_placement() {
		$status = TWTAEO_RAG_Signals::status();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$min = isset( $_GET['min'] ) ? absint( $_GET['min'] ) : TWTAEO_RAG_Signals::POS_MIN;
		$max = isset( $_GET['max'] ) ? absint( $_GET['max'] ) : TWTAEO_RAG_Signals::POS_MAX;
		// phpcs:enable
		$min = max( 1, min( 100, $min ) );
		$max = max( $min, min( 100, $max ) );
		?>
		<p style="max-width:860px;">
			<?php esc_html_e( 'Searches where Google or Bing already ranks you just off the top. With ads and AI Overviews above the results, page one effectively ends around position 3 — so these are the searches where one better answer on the right page can move you up. Each one below is a search your documents answer and the ranking page does not.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php
		if ( ! $status['google']['ready'] && ! $status['bing']['ready'] ) {
			self::setup_notice(
				__( 'Search Placement needs Google Search Console or Bing Webmaster Tools.', 'twt-aeo-ultimate' ),
				__( 'Neither is connected yet, so there is no ranking data to compare with your documents. Connect one (or both) and come back.', 'twt-aeo-ultimate' ),
				$status['setup_url'],
				__( 'Connect Google or Bing', 'twt-aeo-ultimate' )
			);
			return;
		}
		if ( ! self::has_documents_and_pages() ) {
			return;
		}

		$data = TWTAEO_RAG_Signals::placement( $min, $max );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:inline-block;margin:0 16px 12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="placement" />
			<label><?php esc_html_e( 'Positions', 'twt-aeo-ultimate' ); ?>
				<input type="number" name="min" min="1" max="100" value="<?php echo esc_attr( $min ); ?>" style="width:64px;" />
			</label>
			<label><?php esc_html_e( 'to', 'twt-aeo-ultimate' ); ?>
				<input type="number" name="max" min="1" max="100" value="<?php echo esc_attr( $max ); ?>" style="width:64px;" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'twt-aeo-ultimate' ); ?></button>
		</form>
		<?php self::refresh_form( 'placement' ); ?>

		<p class="description">
			<?php
			$sources = array();
			if ( $status['google']['ready'] ) {
				$sources[] = __( 'Google Search Console (last 28 days)', 'twt-aeo-ultimate' );
			}
			if ( $status['bing']['ready'] ) {
				$sources[] = __( 'Bing Webmaster Tools', 'twt-aeo-ultimate' );
			}
			echo esc_html(
				sprintf(
					/* translators: 1: data sources, 2: number of searches. */
					__( 'Data: %1$s. %2$s searches rank in this range.', 'twt-aeo-ultimate' ),
					implode( ' + ', $sources ),
					number_format_i18n( $data['in_band'] )
				)
			);
			if ( ! $status['google']['ready'] ) {
				echo ' ' . esc_html__( 'Connect Google Search Console to add Google searches.', 'twt-aeo-ultimate' );
			} elseif ( ! $status['bing']['ready'] ) {
				echo ' ' . esc_html__( 'Connect Bing Webmaster Tools to add Bing searches.', 'twt-aeo-ultimate' );
			}
			?>
		</p>

		<?php if ( ! empty( $data['hiring'] ) ) : ?>
			<p class="description" style="max-width:860px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of searches. */
						_n(
							'%s search is left out because the person searching is looking for a business to hire — it says "services", "near me" or "buy", names a town or state, asks for a repair without asking a question, or is your business\'s own name. Your documents cannot answer that; your service pages, reviews and local listings can.',
							'%s searches are left out because the people searching are looking for a business to hire — they say "services", "near me" or "buy", name a town or state, ask for a repair without asking a question, or are your business\'s own name. Your documents cannot answer those; your service pages, reviews and local listings can.',
							(int) $data['hiring'],
							'twt-aeo-ultimate'
						),
						number_format_i18n( (int) $data['hiring'] )
					)
				);
				?>
			</p>
		<?php endif; ?>

		<?php foreach ( $data['errors'] as $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( empty( $data['opportunities'] ) ) : ?>
			<p><strong><?php esc_html_e( 'No opportunities found in this range.', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'Either your documents don\'t cover the searches you rank for here, or the ranking pages already answer them. Try a wider position range, or upload more documents.', 'twt-aeo-ultimate' ); ?></p>
			<?php
			return;
		endif;

		self::writing_tip();

		foreach ( $data['opportunities'] as $i => $opp ) {
			$meta = sprintf(
				/* translators: 1: search engine, 2: position, 3: impressions, 4: clicks. */
				__( '%1$s position %2$s · %3$s impressions · %4$s clicks', 'twt-aeo-ultimate' ),
				'google' === $opp['engine'] ? 'Google' : 'Bing',
				number_format_i18n( (float) $opp['position'], 1 ),
				number_format_i18n( $opp['impressions'] ),
				number_format_i18n( $opp['clicks'] )
			);
			self::render_opportunity( 'p' . $i, $opp['query'], $meta, $opp, false );
		}
	}

	/* ─────────────────────────── AI Citations ─────────────────────────── */

	private static function render_citations() {
		$status = TWTAEO_RAG_Signals::status();
		?>
		<p style="max-width:860px;">
			<?php esc_html_e( 'Questions from your most recent AI Visibility run where at least one AI engine answered without citing your site — it named you without a link, left you out, or cited someone else — and your documents contain the answer. This tab reads the results you already have; it never runs new checks.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
		if ( ! $status['visibility']['run'] ) {
			self::setup_notice(
				__( 'No AI citation check has been run yet.', 'twt-aeo-ultimate' ),
				$status['visibility']['module']
					? __( 'Run an AI Visibility check first. It asks ChatGPT, Gemini, Claude, Perplexity, Grok, Le Chat, DeepSeek and Muse the questions your customers ask and records who they cite; this tab then shows which of those answers your documents could win.', 'twt-aeo-ultimate' )
					: __( 'The AI Visibility module is switched off. Turn it on under Modules, run a check, and this tab will show which AI answers your documents could win.', 'twt-aeo-ultimate' ),
				$status['visibility']['module'] ? $status['visibility_url'] : $status['modules_url'],
				$status['visibility']['module'] ? __( 'Go to AI Visibility', 'twt-aeo-ultimate' ) : __( 'Open Modules', 'twt-aeo-ultimate' )
			);
			return;
		}
		if ( ! self::has_documents_and_pages() ) {
			return;
		}

		$data = TWTAEO_RAG_Signals::citations();
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: time since run, 2: questions asked. */
					__( 'From the AI Visibility run %1$s ago: %2$s questions.', 'twt-aeo-ultimate' ),
					human_time_diff( strtotime( $data['run']['started_at'] . ' UTC' ), time() ),
					number_format_i18n( $data['asked'] )
				)
			);
			echo esc_html( self::partial_note( $data['run'] ) );
			?>
			<a href="<?php echo esc_url( $status['visibility_url'] ); ?>"><?php esc_html_e( 'Open AI Visibility', 'twt-aeo-ultimate' ); ?></a>
		</p>

		<?php
		if ( empty( $data['opportunities'] ) ) :
			?>
			<p><strong><?php esc_html_e( 'No opportunities found.', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'Either every engine cited you, or your documents don\'t cover the questions where you were left out. Upload more documents, or run a new AI Visibility check after updating your pages.', 'twt-aeo-ultimate' ); ?></p>
			<?php
		else :
			self::writing_tip();
		endif;

		foreach ( $data['opportunities'] as $i => $opp ) {
			$parts = array();
			if ( $opp['absent'] ) {
				/* translators: %s: engine names. */
				$parts[] = sprintf( __( 'Left out by %s', 'twt-aeo-ultimate' ), self::engine_names( $opp['absent'] ) );
			}
			if ( $opp['named'] ) {
				/* translators: %s: engine names. */
				$parts[] = sprintf( __( 'named without a link by %s', 'twt-aeo-ultimate' ), self::engine_names( $opp['named'] ) );
			}
			if ( $opp['cited'] ) {
				/* translators: %s: engine names. */
				$parts[] = sprintf( __( 'cited by %s', 'twt-aeo-ultimate' ), self::engine_names( $opp['cited'] ) );
			}
			if ( $opp['others'] ) {
				/* translators: %s: domains. */
				$parts[] = sprintf( __( 'engines cited %s instead', 'twt-aeo-ultimate' ), implode( ', ', $opp['others'] ) );
			}
			if ( ! empty( $opp['no_sources'] ) ) {
				/* translators: %s: engine names. */
				$parts[] = sprintf( __( '%s gave no sources', 'twt-aeo-ultimate' ), self::engine_names( $opp['no_sources'] ) );
			}
			self::render_opportunity( 'c' . $i, $opp['question'], implode( ' · ', $parts ), $opp, false, $opp['our_urls'] );
		}

		self::render_next_questions();
	}

	/**
	 * "When asked: Gemini named you, Claude left you out" — for a follow-up
	 * journey mode went on to ask. '' when it was never asked.
	 *
	 * @param array  $asked  { engine: verdict }.
	 * @param string $prefix Joiner put in front when there is something to say.
	 * @return string
	 */
	private static function asked_note( array $asked, $prefix ) {
		if ( ! $asked ) {
			return '';
		}
		$words = array(
			'cited'  => __( 'cited you', 'twt-aeo-ultimate' ),
			'named'  => __( 'named you without a link', 'twt-aeo-ultimate' ),
			'absent' => __( 'left you out', 'twt-aeo-ultimate' ),
		);
		$parts = array();
		foreach ( $asked as $engine => $verdict ) {
			if ( isset( $words[ $verdict ] ) ) {
				$parts[] = self::engine_names( array( $engine ) ) . ' ' . $words[ $verdict ];
			}
		}
		/* translators: %s: what each engine did when the follow-up was asked, e.g. "Gemini named you without a link". */
		return $parts ? $prefix . sprintf( __( 'When asked: %s', 'twt-aeo-ultimate' ), implode( ', ', $parts ) ) : '';
	}

	/** "ChatGPT, Gemini" from engine ids. */
	private static function engine_names( array $ids ) {
		$engine_names = array(
			'chatgpt'    => 'ChatGPT',
			'gemini'     => 'Gemini',
			'claude'     => 'Claude',
			'perplexity' => 'Perplexity',
			'grok'       => 'Grok',
			'mistral'    => 'Le Chat',
			'deepseek'   => 'DeepSeek',
			'meta'       => 'Muse',
		);
		return implode(
			', ',
			array_map(
				static function ( $id ) use ( $engine_names ) {
					return isset( $engine_names[ $id ] ) ? $engine_names[ $id ] : $id;
				},
				$ids
			)
		);
	}

	/**
	 * What the engines expect the buyer to ask next, checked against the
	 * site's pages and documents: document passages to publish where they
	 * exist, and plain content gaps where nothing answers it yet.
	 */
	private static function render_next_questions() {
		$data = TWTAEO_RAG_Signals::next_questions();
		?>
		<h2 style="margin-top:28px;"><?php esc_html_e( 'Next questions', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:860px;">
			<?php esc_html_e( 'With each answer, the AI engines also predict what the buyer is likely to ask next. These are those follow-up questions, checked against your pages and your documents. They are each engine\'s prediction — not questions real people typed — so treat them as a map of where the conversation goes after the first answer.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
		if ( 0 === (int) $data['predicted'] ) {
			?>
			<p class="description"><?php esc_html_e( 'The latest AI Visibility run recorded no follow-up questions. Runs from before follow-ups were added have none — run a new check to see them here.', 'twt-aeo-ultimate' ); ?></p>
			<?php
			return;
		}
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: follow-ups predicted, 2: distinct questions, 3: already answered, 4: looking for a business. */
					__( '%1$s follow-ups predicted, %2$s different questions: %3$s already answered on your pages, %4$s looking for a business to hire (your service pages, reviews and local listings answer those, not documents).', 'twt-aeo-ultimate' ),
					number_format_i18n( (int) $data['predicted'] ),
					number_format_i18n( (int) $data['distinct'] ),
					number_format_i18n( (int) $data['answered'] ),
					number_format_i18n( (int) $data['hiring'] )
				)
			);
			if ( ! empty( $data['business_count'] ) ) {
				/* translators: %s: number of questions. */
				echo ' ' . esc_html( sprintf( _n( '%s asks about your business itself.', '%s ask about your business itself.', (int) $data['business_count'], 'twt-aeo-ultimate' ), number_format_i18n( (int) $data['business_count'] ) ) );
			}
			if ( '' !== $data['persona'] ) {
				/* translators: %s: persona the run was asked as. */
				echo ' ' . esc_html( sprintf( __( 'The run was asked as %s.', 'twt-aeo-ultimate' ), $data['persona'] ) );
			}
			?>
		</p>

		<?php if ( ! empty( $data['business'] ) ) : ?>
			<?php self::render_business_questions( $data['business'], (int) $data['business_count'] ); ?>
		<?php endif; ?>

		<?php if ( $data['opportunities'] ) : ?>
			<h3><?php esc_html_e( 'Your documents answer these', 'twt-aeo-ultimate' ); ?></h3>
			<?php
			foreach ( $data['opportunities'] as $i => $opp ) {
				$meta = sprintf(
					/* translators: 1: engine names, 2: the question it followed. */
					__( 'Predicted by %1$s · after “%2$s”', 'twt-aeo-ultimate' ),
					self::engine_names( $opp['engines'] ),
					$opp['after'][0]
				) . self::asked_note( $opp['asked'], ' · ' );
				self::render_opportunity( 'n' . $i, $opp['question'], $meta, $opp, false );
			}
			?>
		<?php endif; ?>

		<?php if ( $data['gaps'] ) : ?>
			<h3><?php esc_html_e( 'Nothing answers these yet', 'twt-aeo-ultimate' ); ?></h3>
			<p class="description" style="max-width:860px;">
				<?php esc_html_e( 'Neither your pages nor your documents cover these. They are the next thing your buyers are expected to ask — content that answers them gives the engines something of yours to cite at the next step of the conversation.', 'twt-aeo-ultimate' ); ?>
			</p>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Next question', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Predicted by', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'After', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['gaps'] as $gap ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $gap['question'] ); ?>
								<?php if ( $gap['partial'] ) : ?>
									<br /><span class="description">
										<?php /* translators: %s: page title. */ ?>
										<?php echo esc_html( sprintf( __( 'Partly answered on “%s”.', 'twt-aeo-ultimate' ), wp_strip_all_tags( get_the_title( $gap['partial'] ) ) ) ); ?>
										<?php $edit = get_edit_post_link( $gap['partial'] ); ?>
										<?php if ( $edit ) : ?>
											<a href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit page', 'twt-aeo-ultimate' ); ?></a>
										<?php endif; ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::engine_names( $gap['engines'] ) ); ?><?php $note = self::asked_note( $gap['asked'], '' ); ?><?php if ( '' !== $note ) : ?><br /><span class="description"><?php echo esc_html( $note ); ?></span><?php endif; ?></td>
							<td class="description"><?php echo esc_html( $gap['after'][0] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! $data['opportunities'] && ! $data['gaps'] && empty( $data['business'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Every predicted follow-up is either answered on your pages or looking for a business to hire.', 'twt-aeo-ultimate' ); ?></strong></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Follow-ups about the business itself, grouped by topic: turnaround,
	 * warranty, rush service… No document answers these; a page of the
	 * owner's own does, and the engines then have it to cite at that step.
	 *
	 * @param array $groups topic => [ { question, engines, after, asked, page } ].
	 * @param int   $count  Questions in all.
	 */
	private static function render_business_questions( array $groups, $count ) {
		$labels = array(
			'turnaround' => __( 'Turnaround and lead time', 'twt-aeo-ultimate' ),
			'warranty'   => __( 'Warranty', 'twt-aeo-ultimate' ),
			'rush'       => __( 'Rush, emergency and on-site service', 'twt-aeo-ultimate' ),
			'shipping'   => __( 'Shipping, pickup and drop-off', 'twt-aeo-ultimate' ),
			'returns'    => __( 'Returns and cancellations', 'twt-aeo-ultimate' ),
			'trust'      => __( 'Reviews, trust and references', 'twt-aeo-ultimate' ),
			'pricing'    => __( 'Pricing', 'twt-aeo-ultimate' ),
			'brands'     => __( 'What you work on', 'twt-aeo-ultimate' ),
			'contact'    => __( 'Quotes and contact', 'twt-aeo-ultimate' ),
			'other'      => __( 'Other questions about you', 'twt-aeo-ultimate' ),
		);
		uasort(
			$groups,
			static function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);
		?>
		<h3><?php esc_html_e( 'Questions about your business', 'twt-aeo-ultimate' ); ?></h3>
		<p class="description" style="max-width:860px;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of questions. */
					_n(
						'%s predicted follow-up asks about your business itself. No document answers these; your own pages do. A clear page (or FAQ) on the topics buyers ask about most gives every engine something of yours to cite at this step.',
						'%s predicted follow-ups ask about your business itself. No document answers these; your own pages do. A clear page (or FAQ) on the topics buyers ask about most gives every engine something of yours to cite at this step.',
						$count,
						'twt-aeo-ultimate'
					),
					number_format_i18n( $count )
				)
			);
			?>
		</p>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th style="width:220px;"><?php esc_html_e( 'Topic', 'twt-aeo-ultimate' ); ?></th>
					<th><?php esc_html_e( 'What buyers are expected to ask', 'twt-aeo-ultimate' ); ?></th>
					<th style="width:260px;"><?php esc_html_e( 'Closest page you have', 'twt-aeo-ultimate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $topic => $rows ) : ?>
					<?php
					$page = 0;
					foreach ( $rows as $r ) {
						if ( $r['page'] ) {
							$page = (int) $r['page'];
							break;
						}
					}
					$engines = array();
					foreach ( $rows as $r ) {
						$engines = array_merge( $engines, $r['engines'] );
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( isset( $labels[ $topic ] ) ? $labels[ $topic ] : $topic ); ?></strong><br />
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of questions, 2: engine names. */
										_n( '%1$s question · %2$s', '%1$s questions · %2$s', count( $rows ), 'twt-aeo-ultimate' ),
										number_format_i18n( count( $rows ) ),
										self::engine_names( array_values( array_unique( $engines ) ) )
									)
								);
								?>
							</span>
						</td>
						<td>
							<ul style="margin:0 0 0 16px;list-style:disc;">
								<?php foreach ( array_slice( $rows, 0, 4 ) as $r ) : ?>
									<li><?php echo esc_html( $r['question'] ); ?></li>
								<?php endforeach; ?>
								<?php if ( count( $rows ) > 4 ) : ?>
									<?php /* translators: %s: number of further questions. */ ?>
									<li class="description"><?php echo esc_html( sprintf( __( 'and %s more like these', 'twt-aeo-ultimate' ), number_format_i18n( count( $rows ) - 4 ) ) ); ?></li>
								<?php endif; ?>
							</ul>
						</td>
						<td>
							<?php if ( $page ) : ?>
								<?php echo esc_html( wp_strip_all_tags( get_the_title( $page ) ) ); ?>
								<br /><span class="description"><?php esc_html_e( 'your page for this topic: check it answers these', 'twt-aeo-ultimate' ); ?></span>
								<?php $edit = get_edit_post_link( $page ); ?>
								<?php if ( $edit ) : ?>
									· <a href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit page', 'twt-aeo-ultimate' ); ?></a>
								<?php endif; ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'No page for this topic yet. A page (or an FAQ section) that answers these gives the engines something of yours to cite here.', 'twt-aeo-ultimate' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ─────────────────────────── shared pieces ─────────────────────────── */

	/**
	 * One opportunity card: the search or question, the page it belongs on,
	 * how much that page already covers, and the document passages to add —
	 * each with Copy and an Edit page link.
	 */
	private static function render_opportunity( $key, $title, $meta, array $opp, $inferred, array $our_urls = array() ) {
		$labels = array(
			'missing' => array( __( 'does not answer it', 'twt-aeo-ultimate' ), '#d63638' ),
			'partial' => array( __( 'only partly answers it', 'twt-aeo-ultimate' ), '#dba617' ),
			'covered' => array( __( 'already answers it', 'twt-aeo-ultimate' ), '#00a32a' ),
			'unknown' => array( __( 'not matched to a page', 'twt-aeo-ultimate' ), '#646970' ),
		);

		// Search Placement passes a candidate list; AI Citations one page.
		if ( isset( $opp['candidates'] ) ) {
			$candidates = $opp['candidates'];
		} else {
			$candidates = $opp['post_id'] ? array(
				array(
					'post_id'       => (int) $opp['post_id'],
					'reasons'       => array(),
					'more_specific' => false,
					'coverage'      => $opp['coverage'],
				),
			) : array();
		}
		$engine = isset( $opp['engine'] ) ? $opp['engine'] : '';

		// The card's colour follows the first page that still needs the answer.
		$border = $labels['unknown'][1];
		foreach ( $candidates as $c ) {
			if ( 'covered' !== $c['coverage']['state'] ) {
				$border = $labels[ $c['coverage']['state'] ][1];
				break;
			}
		}
		$titles = TWTAEO_Doc_Library::titles( wp_list_pluck( $opp['docs'], 'source_id' ) );
		?>
		<div class="card" style="max-width:none;margin:0 0 14px;padding:12px 16px;border-left:4px solid <?php echo esc_attr( $border ); ?>;">
			<h3 style="margin:0 0 4px;font-size:15px;">“<?php echo esc_html( $title ); ?>”</h3>
			<p class="description" style="margin:0 0 8px;"><?php echo esc_html( $meta ); ?></p>

			<?php
			$lead      = '';
			$publish   = isset( $opp['type'] ) && 'publish' === $opp['type'];
			if ( $publish ) {
				$lead = sprintf(
					/* translators: %s: document title. */
					__( 'People are searching for this document, and you have it: "%s". Publish it — a page with a short summary of what it covers and the PDF attached — so the search has something to land on. Link to it from the page below:', 'twt-aeo-ultimate' ),
					$opp['document']['title']
				);
			} elseif ( 'bing' === $engine ) {
				$lead = count( $candidates ) > 1
					? __( 'Bing does not report which page ranks. These are your likeliest pages, most likely first — Bing favours pages with the search words in their web address:', 'twt-aeo-ultimate' )
					: __( 'Bing does not report which page ranks. Your likeliest page:', 'twt-aeo-ultimate' );
			} elseif ( 'google' === $engine && ! empty( $candidates[0]['more_specific'] ) && isset( $candidates[1] ) ) {
				$lead = sprintf(
					/* translators: 1: page Google ranks, 2: more specific page. */
					__( 'Google ranks "%1$s" for this search, but "%2$s" is the page for this exact model. Add the answer to the model page and make sure "%1$s" links to it:', 'twt-aeo-ultimate' ),
					wp_strip_all_tags( get_the_title( $candidates[1]['post_id'] ) ),
					wp_strip_all_tags( get_the_title( $candidates[0]['post_id'] ) )
				);
			} elseif ( 'google' === $engine ) {
				$lead = __( 'The page Google ranks for this search:', 'twt-aeo-ultimate' );
			}
			?>
			<?php
			if ( isset( $opp['type'] ) && 'restore' === $opp['type'] ) {
				$lead = ''; // The restore note below says it all.
			}
			?>
			<?php if ( '' !== $lead ) : ?>
				<p style="margin:0 0 6px;"><?php echo esc_html( $lead ); ?></p>
			<?php endif; ?>

			<?php
			if ( isset( $opp['type'] ) && 'restore' === $opp['type'] && ! empty( $opp['former'] ) ) :
				$fid      = (int) $opp['former']['post_id'];
				$where    = array(
					'trash'   => __( 'in the Trash', 'twt-aeo-ultimate' ),
					'draft'   => __( 'back to a draft', 'twt-aeo-ultimate' ),
					'pending' => __( 'waiting for review', 'twt-aeo-ultimate' ),
					'private' => __( 'set to private', 'twt-aeo-ultimate' ),
				);
				$status   = (string) $opp['former']['status'];
				$act_url  = 'trash' === $status
					? wp_nonce_url( admin_url( 'post.php?post=' . $fid . '&action=untrash' ), 'untrash-post_' . $fid )
					: (string) get_edit_post_link( $fid, 'raw' );
				$act_text = 'trash' === $status ? __( 'Restore from Trash', 'twt-aeo-ultimate' ) : __( 'Open it to publish', 'twt-aeo-ultimate' );
				?>
				<p style="margin:0 0 8px;padding:6px 10px;background:#edfaef;border-left:3px solid #00a32a;">
					<strong><?php esc_html_e( 'You used to have a page for this.', 'twt-aeo-ultimate' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: page title, 2: where it is now, e.g. "in the Trash". */
							__( '"%1$s" is %2$s, and the search still ranks. Putting the page back at its old address is the quickest way to keep that ranking; a new page starts from nothing. If it was removed on purpose, redirect its old address to the closest page instead.', 'twt-aeo-ultimate' ),
							'' !== $opp['former']['title'] ? $opp['former']['title'] : __( '(no title)', 'twt-aeo-ultimate' ),
							isset( $where[ $status ] ) ? $where[ $status ] : $status
						)
					);
					?>
					<?php if ( '' !== $act_url ) : ?>
						<br /><a href="<?php echo esc_url( $act_url ); ?>"><strong><?php echo esc_html( $act_text ); ?></strong></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $candidates ) : ?>
				<ul style="margin:0 0 8px 18px;list-style:disc;">
					<?php foreach ( $candidates as $c ) : ?>
						<?php
						$pid   = (int) $c['post_id'];
						$edit  = get_edit_post_link( $pid );
						$state = isset( $labels[ $c['coverage']['state'] ] ) ? $labels[ $c['coverage']['state'] ] : $labels['unknown'];
						?>
						<li style="margin-bottom:4px;">
							<strong><?php echo esc_html( get_the_title( $pid ) ); ?></strong>
							<span class="description">(<?php echo esc_html( (string) wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH ) ); ?>)</span>
							— <span style="color:<?php echo esc_attr( $state[1] ); ?>;font-weight:600;"><?php echo esc_html( $state[0] ); ?></span>
							<?php if ( ! empty( $c['reasons'] ) ) : ?>
								<span class="description">· <?php echo esc_html( implode( ', ', $c['reasons'] ) ); ?></span>
							<?php endif; ?>
							<br />
							<a href="<?php echo esc_url( get_permalink( $pid ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
							<?php if ( $edit ) : ?>
								| <a href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener"><strong><?php esc_html_e( 'Edit page', 'twt-aeo-ultimate' ); ?></strong></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( ! empty( $opp['page_url'] ) ) : ?>
				<p style="margin:0 0 8px;"><?php esc_html_e( 'Ranking URL (not a page on this site):', 'twt-aeo-ultimate' ); ?> <a href="<?php echo esc_url( $opp['page_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $opp['page_url'] ); ?></a></p>
			<?php elseif ( ! isset( $opp['type'] ) || 'restore' !== $opp['type'] ) : ?>
				<p style="margin:0 0 8px;color:#646970;"><?php esc_html_e( 'No page on your site matches this yet — add the answer where it fits best, or give it a page of its own.', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $opp['no_page_for'] ) ) : ?>
				<p style="margin:0 0 8px;color:#996800;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: model or part number(s). */
							__( 'No page on your site is named for %s. If it matters to your business, a page of its own — linked from the most relevant page above — will usually outrank a mention inside a broader page.', 'twt-aeo-ultimate' ),
							strtoupper( implode( ', ', $opp['no_page_for'] ) )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( 'bing' === $engine && ! empty( $opp['domain_match'] ) ) : ?>
				<p class="description" style="margin:0 0 8px;">
					<?php esc_html_e( 'Your domain name spells out this search. Bing gives that a lot of weight, so your home page may be the one ranking — consider adding the answer there too.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $our_urls ) : ?>
				<p class="description" style="margin:0 0 8px;">
					<?php esc_html_e( 'Where engines did cite you:', 'twt-aeo-ultimate' ); ?>
					<?php foreach ( array_slice( $our_urls, 0, 3 ) as $u ) : ?>
						<a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $u ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php
			$profiles = TWTAEO_Doc_Profile::profiles( wp_list_pluck( $opp['docs'], 'source_id' ) );

			// Copy, then straight into the editor: the page that still needs
			// the answer (the first candidate, when every one already has it).
			$target = 0;
			foreach ( $candidates as $c ) {
				if ( 'covered' !== $c['coverage']['state'] ) {
					$target = (int) $c['post_id'];
					break;
				}
			}
			if ( ! $target && $candidates ) {
				$target = (int) $candidates[0]['post_id'];
			}
			$edit_url = $target ? (string) get_edit_post_link( $target, 'raw' ) : '';

			if ( TWTAEO_Doc_Profile::is_legal_question( $title, $profiles ) ) {
				$has_law     = false;
				$has_explain = false;
				foreach ( $profiles as $p ) {
					if ( TWTAEO_Doc_Profile::MODE_QUOTE === $p['answer_mode'] ) {
						$has_law = true;
					} else {
						$has_explain = true;
					}
				}
				?>
				<p style="margin:8px 0 6px;padding:6px 10px;background:#fcf9e8;border-left:3px solid #dba617;">
					<strong><?php esc_html_e( 'Legal question.', 'twt-aeo-ultimate' ); ?></strong>
					<?php
					if ( $has_law && ! $has_explain ) {
						esc_html_e( 'Your documents have the law as written but no explanation of it. Publish the text word for word, with its citation.', 'twt-aeo-ultimate' );
					} elseif ( $has_law ) {
						esc_html_e( 'Law and code text is shown word for word with its citation. Any explanation comes from a document you uploaded, and is credited to it.', 'twt-aeo-ultimate' );
					} else {
						esc_html_e( 'The answer below comes from a document you uploaded, and is credited to it. Upload the law or code itself to publish its exact wording beside it.', 'twt-aeo-ultimate' );
					}
					?>
				</p>
				<?php
			}
			?>
			<?php if ( ! empty( $opp['docs'] ) ) : ?>
			<p style="margin:8px 0 4px;font-weight:600;"><?php echo $publish ? esc_html__( 'What the document covers (a start for the summary):', 'twt-aeo-ultimate' ) : esc_html__( 'Your documents answer it — copy, then paste into the page:', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>
			<?php
			foreach ( (array) $opp['docs'] as $n => $hit ) {
				TWTAEO_Page_Documents::render_passage( 'twtaeo-rag-' . $key . '-' . $n, $hit, $title, $profiles, $titles, $edit_url );
			}
			?>
		</div>
		<?php
	}

	/** One writing tip per tab: the SEO side of reusing document text. */
	private static function writing_tip() {
		?>
		<p class="description" style="margin:4px 0 14px;max-width:860px;">
			<?php esc_html_e( 'Tip: passages marked "Explain from this document" are a source, not finished copy. When many sellers publish the same manufacturer text, the page that answers the question in its own words — with your own experience added — usually ranks better than a straight copy. Passages marked "Quote exactly" (laws, codes, safety data, contracts) are the opposite: publish them word for word with their citation.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
	}

	private static function setup_notice( $headline, $body, $url, $action ) {
		?>
		<div class="notice notice-warning inline" style="margin:12px 0;padding:10px 12px;">
			<p><strong><?php echo esc_html( $headline ); ?></strong> <?php echo esc_html( $body ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action ); ?></a></p>
		</div>
		<?php
	}

	/** Both tabs compare documents with pages; say what's missing if either is. */
	private static function has_documents_and_pages() {
		$counts = TWTAEO_Content_Index::counts();
		if ( 0 === $counts['doc']['sources'] ) {
			self::setup_notice(
				__( 'No documents yet.', 'twt-aeo-ultimate' ),
				__( 'Opportunities come from matching your documents against the searches and questions below. Upload some first.', 'twt-aeo-ultimate' ),
				self::url( 'documents' ),
				__( 'Upload documents', 'twt-aeo-ultimate' )
			);
			return false;
		}
		if ( 'done' !== TWTAEO_Doc_Library::site_state()['status'] ) {
			self::setup_notice(
				__( 'Your pages aren\'t indexed yet.', 'twt-aeo-ultimate' ),
				__( 'To tell whether a page already answers a search, the RAG Engine needs your pages indexed.', 'twt-aeo-ultimate' ),
				self::url( 'documents' ),
				__( 'Index my pages', 'twt-aeo-ultimate' )
			);
			return false;
		}

		return true;
	}

	private static function refresh_form( $tab ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<?php wp_nonce_field( 'twtaeo_rag_refresh' ); ?>
			<input type="hidden" name="action" value="twtaeo_rag_refresh" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Refresh data', 'twt-aeo-ultimate' ); ?></button>
			<span class="description"><?php esc_html_e( 'Ranking data is cached for 12 hours.', 'twt-aeo-ultimate' ); ?></span>
		</form>
		<?php
	}
}
