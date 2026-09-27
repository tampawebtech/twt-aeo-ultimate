<?php
/**
 * Social Graph Manager Page
 *
 * Unified Open Graph + Twitter/X Card dashboard.
 * Shows coverage across all published pages and posts.
 * Per-page modal editor handles shared fields (image, title, description)
 * plus OG-specific (og:type) and Twitter-specific (twitter:card, twitter:creator)
 * fields in one place.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Social_Graph {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		// Pagination first, because it decides which scan block to load: the
		// table pages 25 rows at a time across the WHOLE catalogue, and the
		// detector scans in blocks of SCAN_BLOCK — so page 9 loads block 2
		// rather than stopping at the first 200 items.
		// A search term narrows the walk to matches (title, content, product
		// SKU) site-wide; the blocks then page through the matches.
		$per_page     = 25;
		$block_size   = TWTAEO_OG_Detector::SCAN_BLOCK;
		$s_term       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$row_offset   = ( $current_page - 1 ) * $per_page;
		$block        = (int) floor( $row_offset / $block_size ) + 1;

		$all_results = TWTAEO_OG_Detector::scan_all( $block, $s_term );
		$total       = ( '' !== $s_term ) ? TWTAEO_OG_Detector::last_found() : TWTAEO_OG_Detector::total_items();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $current_page > $total_pages ) {
			// Requested page fell off the end (usually a stale page number after
			// a narrowing search) — land on the last real page instead.
			$current_page = $total_pages;
			$row_offset   = ( $current_page - 1 ) * $per_page;
			$block        = (int) floor( $row_offset / $block_size ) + 1;
			$all_results  = TWTAEO_OG_Detector::scan_all( $block, $s_term );
		}
		$summary = TWTAEO_OG_Detector::get_summary( $all_results );
		$site_source = TWTAEO_OG_Detector::detect_site_source();
		$seo_managed = TWTAEO_OG_Writer::seo_plugin_active();
		$sg_nonce    = wp_create_nonce( 'twtaeo_social_graph_nonce' );
		$site_handle    = TWTAEO_Twitter_Writer::get_site_handle();
		$company        = TWTAEO_Company_Profile::get();
		$linkedin_url   = $company['social_linkedin'] ?? '';
		$ai_nonce       = wp_create_nonce( TWTAEO_AI_Description::NONCE );
		$ai_providers   = TWTAEO_AI_Description::available_providers();
		$ai_provider    = TWTAEO_AI_Description::get_provider();
		$ai_job         = TWTAEO_AI_Description::job_payload();
		// AI image generation needs a real OpenAI key (the WP AI Client text
		// fallback does not cover images), plus the capability to add media.
		$ai_image_ready = ( '' !== TWTAEO_Key_Resolver::get( 'openai' ) ) && current_user_can( 'upload_files' );

		// Twitter Cards: the writer outputs a card on EVERY singular page (default
		// summary_large_image) unless Rank Math or Yoast manages them — so the
		// honest number is effective coverage, not just explicit per-post picks.
		// Counting only explicit picks showed "0" to a merchant whose every page
		// already carries a live card.
		$tw_managed_elsewhere = defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' );
		$tw_configured        = 0;
		foreach ( $all_results as $item ) {
			$tw = TWTAEO_Twitter_Writer::get( $item['post']->ID );
			if ( ! empty( $tw['tw_card'] ) ) {
				$tw_configured++;
			}
		}
		$tw_live = $tw_managed_elsewhere ? null : count( $all_results );

		// The summary cards describe the WHOLE SITE when a census exists — a
		// single block's counts on a 955-page site read as nonsense ("200
		// missing, 200 scanned, 200 cards" is the block size three times, and
		// it was read exactly that way). Until the first census runs, the cards
		// fall back to the current block and say so.
		$census         = TWTAEO_OG_Detector::get_census();
		$cards_sitewide = ! empty( $census );
		if ( $cards_sitewide ) {
			$ct             = $census['totals'];
			$card_scanned   = (int) $ct['scanned'];
			$card_complete  = (int) $ct['complete'];
			$card_miss_txt  = (int) $ct['missing_text'];
			$card_miss_img  = (int) $ct['missing_image'];
			$tw_configured  = (int) $ct['tw_explicit'];
			$tw_live        = $tw_managed_elsewhere ? null : $card_scanned;
			$card_caption   = sprintf(
				/* translators: 1: pages checked, 2: human time diff since the census. */
				__( 'Site-wide census: all %1$d pages checked %2$s ago. Rescan All re-checks every page.', 'twt-aeo-ultimate' ),
				$card_scanned,
				human_time_diff( $census['completed_at'] )
			);
		} else {
			$card_scanned  = (int) $summary['scanned'];
			$card_complete = (int) $summary['complete'];
			$card_miss_txt = (int) $summary['missing_text'];
			$card_miss_img = (int) $summary['missing_image'];
			$card_caption  = $summary['truncated']
				? __( 'These cards cover only the newest block so far — run Rescan All to check every page on the site.', 'twt-aeo-ultimate' )
				: '';
		}

		// Slice the current 25-row page out of the loaded scan block.
		$results = array_slice( $all_results, $row_offset % $block_size, $per_page );

		wp_enqueue_media();

		?>

		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Social Graph', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div style="margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
						<span class="twt-aeo-badge" style="background:rgba(99,102,241,.08);color:#4f46e5;">
							<span class="dashicons dashicons-admin-plugins" style="font-size:12px;width:12px;height:12px;margin-right:4px;vertical-align:middle;"></span>
							<?php echo esc_html( $site_source ); ?>
						</span>
						<p class="twt-aeo-header__sub" style="margin:0;">
							<?php
							if ( ! empty( $summary['truncated'] ) ) {
								printf(
									// translators: %1$d: pages scanned. %2$d: total published pages. %3$d: pages needing attention.
									esc_html__( '%1$d of %2$d pages scanned (most recent first) — %3$d of those need attention', 'twt-aeo-ultimate' ),
									absint( $summary['scanned'] ),
									absint( $summary['total'] ),
									absint( $summary['needs_work'] )
								);
							} else {
								printf(
									// translators: %1$d: total pages scanned. %2$d: pages needing attention.
									esc_html__( '%1$d pages scanned — %2$d need attention', 'twt-aeo-ultimate' ),
									absint( $summary['scanned'] ),
									absint( $summary['needs_work'] )
								);
							}
							?>
						</p>
					</div>
				</div>
			</div>

			<?php if ( isset( $_GET['rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI confirmation, no state change. ?>
			<div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
				<p>
					<?php
					if ( $cards_sitewide ) {
						printf(
							// translators: %1$d: total pages checked site-wide. %2$d: pages needing attention.
							esc_html__( 'Site-wide rescan complete — all %1$d pages checked, %2$d need attention.', 'twt-aeo-ultimate' ),
							absint( $card_scanned ),
							absint( $census['totals']['needs_work'] )
						);
					} else {
						printf(
							// translators: %1$d: total pages scanned. %2$d: pages needing attention.
							esc_html__( 'Rescan complete — %1$d pages scanned, %2$d need attention.', 'twt-aeo-ultimate' ),
							absint( $summary['scanned'] ),
							absint( $summary['needs_work'] )
						);
					}
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $seo_managed ) : ?>
			<div class="twt-aeo-og-notice">
				<span class="dashicons dashicons-info-outline"></span>
				<?php
				printf(
					// translators: %1$s: SEO plugin managing OG/Twitter output. %2$s: same plugin name.
					esc_html__( '%1$s is handling Open Graph and Twitter Card output. Data you save here is stored and visible in this dashboard. TWT AEO will not output duplicate meta tags while %2$s is active.', 'twt-aeo-ultimate' ),
					esc_html( $site_source ),
					esc_html( $site_source )
				);
				?>
			</div>
			<?php endif; ?>

			<!-- Bulk OG descriptions -->
			<div class="twt-aeo-card" id="twt-aeo-og-bulk"
				style="border-left:3px solid #2271b1;margin-bottom:16px;"
				data-sgnonce="<?php echo esc_attr( $sg_nonce ); ?>"
				data-ainonce="<?php echo esc_attr( $ai_nonce ); ?>"
				data-running="<?php echo $ai_job['running'] ? '1' : '0'; ?>">
				<p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;max-width:820px;">
					<strong><?php esc_html_e( 'Fill missing social descriptions', 'twt-aeo-ultimate' ); ?></strong> —
					<?php esc_html_e( 'reuse the meta descriptions you already have (free and instant), or ask AI to write punchier, share-friendly copy for posts that are missing an og:description.', 'twt-aeo-ultimate' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
					<button type="button" class="button" id="twt-aeo-og-from-meta">
						<?php esc_html_e( 'Use existing meta descriptions', 'twt-aeo-ultimate' ); ?>
					</button>
					<?php if ( ! empty( $ai_providers ) ) : ?>
						<span style="color:#b0b5bb;"><?php esc_html_e( 'or', 'twt-aeo-ultimate' ); ?></span>
						<label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;">
							<?php esc_html_e( 'AI', 'twt-aeo-ultimate' ); ?>
							<select id="twt-aeo-og-provider">
								<?php foreach ( $ai_providers as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ai_provider, $slug ); ?>>
										<?php echo esc_html( ucfirst( $slug ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="button" class="button button-primary" id="twt-aeo-og-ai">
							<?php esc_html_e( 'Generate social-friendly with AI', 'twt-aeo-ultimate' ); ?>
						</button>
						<button type="button" class="button" id="twt-aeo-og-stop" style="display:none;">
							<?php esc_html_e( 'Stop', 'twt-aeo-ultimate' ); ?>
						</button>
					<?php endif; ?>
					<span id="twt-aeo-og-bulk-status" style="font-size:12px;color:#646970;"></span>
				</div>
				<?php if ( ! empty( $ai_providers ) ) : ?>
				<p style="margin:10px 0 0;font-size:13px;color:#646970;line-height:1.55;">
					<?php esc_html_e( 'The AI option makes one billed API call per post and runs in the background — you can leave this page once it starts, and stop it at any time. You will be asked to confirm the count first.', 'twt-aeo-ultimate' ); ?>
				</p>
				<p id="twt-aeo-og-note" style="margin:6px 0 0;font-size:13px;color:#646970;line-height:1.55;"></p>
				<?php endif; ?>
			</div>

			<!-- Twitter Site Handle (global setting) -->
			<div class="twt-aeo-site-handle-card">
				<span class="dashicons dashicons-twitter" style="font-size:20px;color:#1da1f2;flex-shrink:0;"></span>
				<label class="twt-aeo-site-handle-card__label" for="twt-aeo-site-handle">
					<?php esc_html_e( 'X / Twitter @site handle', 'twt-aeo-ultimate' ); ?>
				</label>
				<input
					type="text"
					id="twt-aeo-site-handle"
					class="twt-aeo-site-handle-card__input"
					value="<?php echo esc_attr( $site_handle ); ?>"
					placeholder="@yourbrand"
				>
				<button type="button" id="twt-aeo-save-site-handle" class="twt-aeo-btn twt-aeo-btn--ghost twt-aeo-btn--sm">
					<?php esc_html_e( 'Save', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-site-handle-status" class="twt-aeo-modal__status"></span>
				<span class="twt-aeo-site-handle-card__hint">
					<?php esc_html_e( 'Outputs as twitter:site on every post. Used by X to attribute shares to your brand account.', 'twt-aeo-ultimate' ); ?>
				</span>
			</div>

			<!-- LinkedIn Company Page (global setting) -->
			<div class="twt-aeo-site-handle-card">
				<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#0a66c2" aria-hidden="true" style="flex-shrink:0;">
					<path d="M20.447 20.452H16.89v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a1.98 1.98 0 0 1-1.98-1.98c0-1.093.887-1.98 1.98-1.98 1.094 0 1.98.887 1.98 1.98a1.98 1.98 0 0 1-1.98 1.98zm1.971 13.019H3.366V9h3.942v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
				</svg>
				<label class="twt-aeo-site-handle-card__label" for="twt-aeo-linkedin-company">
					<?php esc_html_e( 'LinkedIn company page URL', 'twt-aeo-ultimate' ); ?>
				</label>
				<input
					type="url"
					id="twt-aeo-linkedin-company"
					class="twt-aeo-site-handle-card__input"
					style="width:280px;"
					value="<?php echo esc_attr( $linkedin_url ); ?>"
					placeholder="https://www.linkedin.com/company/yourcompany"
				>
				<button type="button" id="twt-aeo-save-linkedin-company" class="twt-aeo-btn twt-aeo-btn--ghost twt-aeo-btn--sm">
					<?php esc_html_e( 'Save', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-linkedin-status" class="twt-aeo-modal__status"></span>
				<span class="twt-aeo-site-handle-card__hint">
					<?php esc_html_e( 'LinkedIn reads your Open Graph tags automatically. This URL is added to your Organisation schema sameAs and synced with Company Profile.', 'twt-aeo-ultimate' ); ?>
				</span>
			</div>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
				<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:10px;">
					<span id="twt-aeo-og-rescan-status" style="font-size:12px;color:#646970;"></span>
					<button type="button" id="twt-aeo-og-rescan" class="button">
						&#8635; <?php esc_html_e( 'Rescan All', 'twt-aeo-ultimate' ); ?>
					</button>
				</div>
				<div class="twt-aeo-summary-grid twt-aeo-summary-grid--compact">

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-admin-page"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $card_scanned ); ?></div>
						<div class="twt-aeo-summary-card__label">
							<?php
							if ( $cards_sitewide ) {
								esc_html_e( 'Pages Scanned (site-wide)', 'twt-aeo-ultimate' );
							} elseif ( ! empty( $summary['truncated'] ) ) {
								printf(
									/* translators: %d: total published pages on the site. */
									esc_html__( 'Pages Scanned (of %d)', 'twt-aeo-ultimate' ),
									absint( $summary['total'] )
								);
							} else {
								esc_html_e( 'Pages Scanned', 'twt-aeo-ultimate' );
							}
							?>
						</div>
					</div>

					<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-yes-alt" style="color:var(--aeo-success);"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $card_complete ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Full OG Coverage', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $card_miss_txt > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-editor-alignleft"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $card_miss_txt ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing Title/Desc', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $card_miss_img > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-format-image"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $card_miss_img ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing OG Image', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-twitter" style="color:#1da1f2;"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo null === $tw_live ? '&mdash;' : esc_html( $tw_live ); ?></div>
						<div class="twt-aeo-summary-card__label">
							<?php
							if ( null === $tw_live ) {
								/* translators: shown when an SEO plugin outputs Twitter Cards instead of this plugin. */
								echo esc_html( sprintf( __( 'Twitter Cards (via %s)', 'twt-aeo-ultimate' ), $site_source ) );
							} elseif ( $tw_configured > 0 ) {
								printf(
									/* translators: %d: pages with an explicitly chosen card type. */
									esc_html__( 'Twitter Cards Live (%d customised)', 'twt-aeo-ultimate' ),
									absint( $tw_configured )
								);
							} else {
								esc_html_e( 'Twitter Cards Live', 'twt-aeo-ultimate' );
							}
							?>
						</div>
					</div>

				</div>
				<?php if ( '' !== $card_caption ) : ?>
				<p style="margin:8px 0 0;font-size:12px;color:#646970;">
					<?php echo esc_html( $card_caption ); ?>
				</p>
				<?php endif; ?>
			</section>

			<!-- Page Table -->
			<?php
			$base_url = add_query_arg( 'page', 'twt-aeo-social-graph', admin_url( 'admin.php' ) );
			if ( '' !== $s_term ) {
				$base_url = add_query_arg( 's', $s_term, $base_url );
			}
			$render_pager = static function () use ( $total_pages, $current_page, $base_url, $per_page, $total ) {
				if ( $total_pages <= 1 ) {
					return;
				}
				?>
				<div class="twt-og-pagination">
					<?php if ( $current_page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'twt-aeo-ultimate' ); ?></a>
					<?php endif; ?>
					<?php
					// A windowed pager: a 3,000-item site would otherwise print
					// 120 page links here.
					$window = array( 1, $total_pages );
					for ( $p = $current_page - 2; $p <= $current_page + 2; $p++ ) {
						$window[] = $p;
					}
					$window = array_values( array_unique( array_filter( $window, static function ( $p ) use ( $total_pages ) {
						return $p >= 1 && $p <= $total_pages;
					} ) ) );
					sort( $window );
					$prev = 0;
					foreach ( $window as $p ) :
						if ( $p > $prev + 1 ) : ?>
							<span style="color:#8c8f94;">&hellip;</span>
						<?php endif;
						$prev = $p;
						if ( $p === $current_page ) : ?>
							<span class="current"><?php echo esc_html( $p ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url ) ); ?>"><?php echo esc_html( $p ); ?></a>
						<?php endif;
					endforeach; ?>
					<?php if ( $current_page < $total_pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'twt-aeo-ultimate' ); ?> &raquo;</a>
					<?php endif; ?>
					<span style="font-size:12px;color:#666;margin-left:6px;">
						<?php
						// translators: %1$d: first item on page. %2$d: last item on page. %3$d: total items.
						printf( esc_html__( '%1$d–%2$d of %3$d', 'twt-aeo-ultimate' ), absint( ( $current_page - 1 ) * $per_page + 1 ), absint( min( $current_page * $per_page, $total ) ), absint( $total ) ); ?>
					</span>
				</div>
				<?php
			};
			?>
			<section class="twt-aeo-section">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
					<h2 class="twt-aeo-section__title" style="margin:0;">
						<span class="dashicons dashicons-share"></span>
						<?php esc_html_e( 'Page Coverage', 'twt-aeo-ultimate' ); ?>
					</h2>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;align-items:center;gap:6px;margin:0;">
						<input type="hidden" name="page" value="twt-aeo-social-graph">
						<label class="screen-reader-text" for="twt-aeo-sg-search"><?php esc_html_e( 'Search pages and products', 'twt-aeo-ultimate' ); ?></label>
						<input type="search" id="twt-aeo-sg-search" name="s" value="<?php echo esc_attr( $s_term ); ?>"
							placeholder="<?php esc_attr_e( 'Search by title or SKU…', 'twt-aeo-ultimate' ); ?>"
							class="regular-text" autocomplete="off">
						<button type="submit" class="button"><?php esc_html_e( 'Search', 'twt-aeo-ultimate' ); ?></button>
						<?php if ( '' !== $s_term ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'page', 'twt-aeo-social-graph', admin_url( 'admin.php' ) ) ); ?>" class="button-link">
								<?php esc_html_e( 'Clear', 'twt-aeo-ultimate' ); ?>
							</a>
						<?php endif; ?>
					</form>
				</div>

				<?php if ( '' !== $s_term ) : ?>
				<p style="margin:0 0 8px;font-size:13px;color:#50575e;">
					<?php
					printf(
						// translators: %1$d: matching items. %2$s: the search term.
						esc_html( _n( '%1$d item matches “%2$s” — searched across every page, post and product.', '%1$d items match “%2$s” — searched across every page, post and product.', $total, 'twt-aeo-ultimate' ) ),
						absint( $total ),
						esc_html( $s_term )
					);
					?>
				</p>
				<?php endif; ?>

				<?php if ( empty( $all_results ) ) : ?>
					<div class="twt-aeo-card">
						<p><?php echo '' !== $s_term
							? esc_html__( 'Nothing matches that search. It looked at every page, post and product title, content and SKU.', 'twt-aeo-ultimate' )
							: esc_html__( 'No published pages or posts found.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				<?php else : ?>
				<?php $render_pager(); ?>
				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table twt-aeo-og-table" id="twt-sg-table">
						<thead>
							<tr>
								<th data-sort="title" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="image" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Image', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_title" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_desc" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_type" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'og:type', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="tw_card" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Twitter Card', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="status" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $results as $item ) :
							$post      = $item['post'];
							$og        = $item['og_data'];
							$saved_og  = TWTAEO_OG_Writer::get( $post->ID );
							$saved_tw  = TWTAEO_Twitter_Writer::get( $post->ID );
							$has_saved = ! empty( $saved_og['og_title'] ) || ! empty( $saved_og['og_description'] ) || ! empty( $saved_og['og_type'] );
							$row_cls   = empty( $og['missing'] ) ? 'twt-aeo-page-row--ok' : 'twt-aeo-page-row--issues';

							// Defaults for modal.
							$default_type = 'website';
							if ( $post->post_type === 'post' )    $default_type = 'article';
							if ( $post->post_type === 'product' ) $default_type = 'product';

							// Auto-fill: page title, featured/attached image, and its
							// label (when descriptive) seed the modal for unsaved fields.
							$auto = TWTAEO_OG_Writer::auto_defaults( $post->ID );

							$modal_type     = $saved_og['og_type']        ?: $default_type;
							$modal_title    = $saved_og['og_title']       ?: $auto['title'];
							$modal_desc     = $saved_og['og_description'] ?: '';
							$modal_image    = $saved_og['og_image']       ?: $auto['image_url'];
							// Only auto-label when the image itself is the automatic one —
							// a saved custom image keeps whatever label was saved with it.
							$modal_image_alt = $saved_og['og_image']
								? ( $saved_og['og_image_alt'] ?: '' )
								: $auto['image_alt'];
							$modal_tw_card  = $saved_tw['tw_card']        ?: 'summary_large_image';
							$modal_tw_creator = $saved_tw['tw_creator']  ?: '';
							$author_twitter = TWTAEO_Twitter_Writer::get_author_twitter( $post->ID );
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>"
								data-post-id="<?php echo esc_attr( $post->ID ); ?>"
								data-sort-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
								data-sort-image="<?php echo $og['has_og_image'] ? '1' : '0'; ?>"
								data-sort-og_title="<?php echo $og['has_og_title'] ? '1' : '0'; ?>"
								data-sort-og_desc="<?php echo $og['has_og_description'] ? '1' : '0'; ?>"
								data-sort-og_type="<?php echo esc_attr( $og['og_type'] ?: '' ); ?>"
								data-sort-tw_card="<?php echo esc_attr( $saved_tw['tw_card'] ?: '' ); ?>"
								data-sort-status="<?php echo empty( $og['missing'] ) ? '0' : '1'; ?>"
							>

								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $post ) ); ?>
									</a>
									<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
								</td>

								<td class="twt-aeo-og-image-cell">
									<?php if ( $og['has_og_image'] ) : ?>
										<img src="<?php echo esc_url( $og['og_image'] ); ?>" alt="" style="width:90px;height:auto;max-height:60px;object-fit:cover;display:block;border-radius:3px;">
									<?php else : ?>
										<span class="twt-aeo-tag twt-aeo-tag--missing"><span class="dashicons dashicons-format-image"></span></span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $og['has_og_title'] ) : ?>
										<span class="twt-aeo-og-field-ok" title="<?php echo esc_attr( $og['og_title'] ); ?>">
											<span class="dashicons dashicons-yes-alt"></span>
											<span class="twt-aeo-og-field-text"><?php echo esc_html( wp_trim_words( $og['og_title'], 6, '…' ) ); ?></span>
										</span>
									<?php else : ?>
										<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $og['has_og_description'] ) : ?>
										<span class="twt-aeo-og-field-ok" title="<?php echo esc_attr( $og['og_description'] ); ?>">
											<span class="dashicons dashicons-yes-alt"></span>
											<span class="twt-aeo-og-field-text"><?php echo esc_html( wp_trim_words( $og['og_description'], 8, '…' ) ); ?></span>
										</span>
									<?php else : ?>
										<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
								</td>

								<td>
									<span class="twt-aeo-og-type-pill">
										<?php echo esc_html( $og['og_type'] ?: '—' ); ?>
									</span>
								</td>

								<td>
									<?php if ( $saved_tw['tw_card'] ) : ?>
										<span class="twt-aeo-badge twt-aeo-badge--tw">
											<?php echo esc_html( $saved_tw['tw_card'] === 'summary_large_image' ? 'large image' : 'summary' ); ?>
										</span>
									<?php elseif ( $tw_managed_elsewhere ) : ?>
										<span class="twt-aeo-badge twt-aeo-badge--auto"><?php echo esc_html( sprintf( /* translators: %s: SEO plugin name. */ __( 'via %s', 'twt-aeo-ultimate' ), $site_source ) ); ?></span>
									<?php else : ?>
										<?php
										// The card every page gets by default — the writer
										// publishes summary_large_image, downgrading only when
										// the page has no image at all to attach.
										$eff_large = (bool) TWTAEO_OG_Writer::effective_image( $post->ID );
										?>
										<span class="twt-aeo-badge twt-aeo-badge--auto" title="<?php esc_attr_e( 'Applied automatically — edit the page to override.', 'twt-aeo-ultimate' ); ?>">
											<?php echo esc_html( $eff_large ? __( 'large image · default', 'twt-aeo-ultimate' ) : __( 'summary · default', 'twt-aeo-ultimate' ) ); ?>
										</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( empty( $og['missing'] ) ) : ?>
										<span class="twt-aeo-badge twt-aeo-badge--good"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-badge twt-aeo-badge--warn">
											<?php echo esc_html( count( $og['missing'] ) . ' ' . _n( 'issue', 'issues', count( $og['missing'] ), 'twt-aeo-ultimate' ) ); ?>
										</span>
									<?php endif; ?>
									<?php if ( $saved_tw['tw_creator'] ) : ?>
										<br><span style="font-size:11px;color:#1da1f2;margin-top:2px;display:inline-block;" title="<?php echo esc_attr( $saved_tw['tw_creator'] ); ?>">
											<?php echo esc_html( TWTAEO_Twitter_Writer::normalize_handle( $saved_tw['tw_creator'] ) ); ?>
										</span>
									<?php endif; ?>
								</td>

								<td class="twt-aeo-og-actions">
									<button
										class="twt-aeo-btn twt-aeo-btn--primary twt-aeo-btn--sm js-sg-edit"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
										data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
										data-og-title="<?php echo esc_attr( $modal_title ); ?>"
										data-og-description="<?php echo esc_attr( $modal_desc ); ?>"
										data-meta-description="<?php echo esc_attr( TWTAEO_AI_Description::get_existing_description( $post->ID ) ); ?>"
										data-og-type="<?php echo esc_attr( $modal_type ); ?>"
										data-og-image="<?php echo esc_attr( $modal_image ); ?>"
										data-og-image-alt="<?php echo esc_attr( $modal_image_alt ); ?>"
										data-tw-card="<?php echo esc_attr( $modal_tw_card ); ?>"
										data-tw-creator="<?php echo esc_attr( $modal_tw_creator ); ?>"
										data-author-twitter="<?php echo esc_attr( $author_twitter ); ?>"
									>
										<span class="dashicons dashicons-edit"></span>
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</button>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

				</div>
				<?php $render_pager(); ?>
				<?php endif; ?>
			</section>

			<!-- What This Manages -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What Social Graph Controls', 'twt-aeo-ultimate' ); ?></h2>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note" style="font-weight:600;margin-bottom:8px;">Open Graph — Facebook, LinkedIn, Slack</p>
						<ul class="twt-aeo-checklist">
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'og:image — thumbnail shown when shared (1200×630px)', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'og:title — headline, keep under 60 characters', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'og:description — shown below title, 120–160 characters', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'og:type — website, article, or product', 'twt-aeo-ultimate' ); ?></li>
						</ul>
					</div>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note" style="font-weight:600;margin-bottom:8px;">Twitter / X Card</p>
						<ul class="twt-aeo-checklist">
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'twitter:card — summary or summary_large_image layout', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'twitter:creator — @handle of the post author, pulled from Author Entity schema', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'twitter:site — your brand @handle, set globally above', 'twt-aeo-ultimate' ); ?></li>
							<li class="twt-aeo-checklist__item"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Title, description, image — inherited from Open Graph (X falls back automatically)', 'twt-aeo-ultimate' ); ?></li>
						</ul>
					</div>
				</div>
			</section>

		</div><!-- .twt-aeo-wrap -->

		<!-- ── Social Graph Edit Modal ── -->
		<div id="twt-aeo-sg-modal-overlay" style="display:none;" aria-hidden="true">
			<div class="twt-aeo-modal" role="dialog" aria-modal="true" aria-labelledby="sg-modal-title">

				<div class="twt-aeo-modal__header">
					<h2 class="twt-aeo-modal__title" id="sg-modal-title">
						<span class="twt-aeo-logo" style="font-size:10px;padding:2px 6px;">SG</span>
						<span id="sg-modal-post-name"><?php esc_html_e( 'Edit Social Graph', 'twt-aeo-ultimate' ); ?></span>
					</h2>
					<button class="twt-aeo-modal__close js-sg-modal-close" aria-label="Close">&times;</button>
				</div>

				<div class="twt-aeo-modal__body">
					<input type="hidden" id="sg-post-id">
					<input type="hidden" id="sg-image-url">

					<!-- Image picker (Shared: og:image / twitter:image) -->
					<div class="twt-aeo-modal__field" style="margin-bottom:20px;">
						<label class="twt-aeo-modal__label"><?php esc_html_e( 'Social Image', 'twt-aeo-ultimate' ); ?></label>
						<div class="twt-aeo-og-image-picker">
							<div class="twt-aeo-og-image-picker__thumb js-sg-image-select" id="sg-image-thumb" title="<?php esc_attr_e( 'Click to select image', 'twt-aeo-ultimate' ); ?>">
								<img id="sg-image-preview-img" src="" alt="" style="display:none;">
								<div class="twt-aeo-og-image-picker__placeholder" id="sg-image-placeholder">
									<span class="dashicons dashicons-format-image"></span>
									<span><?php esc_html_e( 'Click to select', 'twt-aeo-ultimate' ); ?></span>
								</div>
							</div>
							<div class="twt-aeo-og-image-picker__actions">
								<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost js-sg-image-select">
									<span class="dashicons dashicons-admin-media"></span>
									<?php esc_html_e( 'Select from Media Library', 'twt-aeo-ultimate' ); ?>
								</button>
								<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost js-sg-image-remove" style="display:none;">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
								</button>
							</div>
						</div>

						<?php if ( $ai_image_ready ) : ?>
						<!-- AI image generation (OpenAI) -->
						<div class="twt-aeo-ai-img" style="margin-top:12px;padding:12px;border:1px solid #e2e4e7;border-radius:6px;background:#fafafa;">
							<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
								<label style="font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
									<?php esc_html_e( 'AI image style', 'twt-aeo-ultimate' ); ?>
									<select id="sg-ai-img-style" class="twt-aeo-modal__select" style="width:auto;min-width:170px;">
										<?php foreach ( TWTAEO_AI_Description::image_styles() as $style_key => $style_info ) : ?>
											<option value="<?php echo esc_attr( $style_key ); ?>"><?php echo esc_html( $style_info['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost twt-aeo-btn--sm" id="sg-ai-img-btn">
									<span class="dashicons dashicons-art"></span>
									<?php esc_html_e( 'Generate with AI', 'twt-aeo-ultimate' ); ?>
								</button>
								<span id="sg-ai-img-status" style="font-size:11px;color:#646970;"></span>
							</div>
							<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:8px;">
								<span style="font-size:11px;color:#646970;"><?php esc_html_e( 'Base it on:', 'twt-aeo-ultimate' ); ?></span>
								<label style="font-size:12px;display:inline-flex;align-items:center;gap:5px;">
									<input type="checkbox" id="sg-ai-img-title" checked> <?php esc_html_e( 'Post title', 'twt-aeo-ultimate' ); ?>
								</label>
								<label style="font-size:12px;display:inline-flex;align-items:center;gap:5px;">
									<input type="checkbox" id="sg-ai-img-desc" checked> <?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?>
								</label>
								<label style="font-size:12px;display:inline-flex;align-items:center;gap:5px;">
									<input type="checkbox" id="sg-ai-img-brand"> <?php esc_html_e( 'Brand name', 'twt-aeo-ultimate' ); ?>
								</label>
							</div>
							<p style="margin:8px 0 0;font-size:11px;color:#8a8f94;line-height:1.5;">
								<?php esc_html_e( 'Generates a 1.91:1 landscape image with OpenAI, adds it to your Media Library, and sets it as the social image above. One billed API call — image generation costs more than text and can take 10–30 seconds. "Description" uses the social description, falling back to the meta description.', 'twt-aeo-ultimate' ); ?>
							</p>
						</div>
						<?php endif; ?>

						<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Shared by OG and Twitter. Recommended: 1200×630px. Falls back to featured image.', 'twt-aeo-ultimate' ); ?></span>
					</div>

					<!-- Image label (og:image:alt) -->
					<div class="twt-aeo-modal__field" style="margin-bottom:20px;">
						<label class="twt-aeo-modal__label" for="sg-image-alt"><?php esc_html_e( 'Image Label (og:image:alt)', 'twt-aeo-ultimate' ); ?></label>
						<input type="text" id="sg-image-alt" class="twt-aeo-modal__input" placeholder="<?php esc_attr_e( 'Describe what the image shows…', 'twt-aeo-ultimate' ); ?>" maxlength="200">
						<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Auto-filled from the image\'s alt text or media title when it\'s descriptive (generic camera names like IMG_4302 are skipped). Selecting a new image refreshes this.', 'twt-aeo-ultimate' ); ?></span>
					</div>

					<!-- Live preview -->
					<div class="twt-aeo-og-preview">
						<div class="twt-aeo-og-preview__inner">
							<div class="twt-aeo-og-preview__image-wrap">
								<img id="sg-preview-image" src="" alt="" style="display:none;">
								<div id="sg-preview-image-placeholder" class="twt-aeo-og-preview__placeholder">
									<span class="dashicons dashicons-format-image"></span>
								</div>
							</div>
							<div class="twt-aeo-og-preview__text">
								<div class="twt-aeo-og-preview__site"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
								<div class="twt-aeo-og-preview__title" id="sg-preview-title"><?php esc_html_e( 'Page title', 'twt-aeo-ultimate' ); ?></div>
								<div class="twt-aeo-og-preview__desc"  id="sg-preview-desc"><?php esc_html_e( 'Description will appear here…', 'twt-aeo-ultimate' ); ?></div>
							</div>
						</div>
					</div>

					<!-- Title (Shared) -->
					<div class="twt-aeo-modal__field">
						<label class="twt-aeo-modal__label" for="sg-title">
							<?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-modal__char-count" id="sg-title-count">0 / 60</span>
						</label>
						<input type="text" id="sg-title" class="twt-aeo-modal__input" placeholder="<?php esc_attr_e( 'Auto-filled from page title', 'twt-aeo-ultimate' ); ?>" maxlength="120">
						<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Used by og:title. X falls back to this automatically. Keep under 60 characters.', 'twt-aeo-ultimate' ); ?></span>
					</div>

					<!-- Description (Shared) -->
					<div class="twt-aeo-modal__field">
						<label class="twt-aeo-modal__label" for="sg-description">
							<?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-modal__char-count" id="sg-desc-count">0 / 160</span>
						</label>
						<textarea id="sg-description" class="twt-aeo-modal__textarea" rows="3" placeholder="<?php esc_attr_e( 'Write a compelling description for social shares…', 'twt-aeo-ultimate' ); ?>" maxlength="300"></textarea>
						<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;">
							<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost twt-aeo-btn--sm" id="sg-use-meta" style="display:none;">
								<?php esc_html_e( 'Use meta description', 'twt-aeo-ultimate' ); ?>
							</button>
							<?php if ( ! empty( $ai_providers ) ) : ?>
							<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost twt-aeo-btn--sm" id="sg-gen-ai">
								<?php esc_html_e( 'Generate with AI', 'twt-aeo-ultimate' ); ?>
							</button>
							<?php endif; ?>
							<span id="sg-desc-ai-status" style="font-size:11px;color:#646970;"></span>
						</div>
						<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Used by og:description. X inherits this. Recommended: 120–160 characters.', 'twt-aeo-ultimate' ); ?></span>
					</div>

					<!-- Open Graph section -->
					<div class="twt-aeo-modal__section">
						<p class="twt-aeo-modal__section-title">
							<span class="dashicons dashicons-share" style="font-size:14px;width:14px;height:14px;"></span>
							<?php esc_html_e( 'Open Graph', 'twt-aeo-ultimate' ); ?>
						</p>
						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="sg-og-type"><?php esc_html_e( 'og:type', 'twt-aeo-ultimate' ); ?></label>
							<select id="sg-og-type" class="twt-aeo-modal__select">
								<option value="website"><?php esc_html_e( 'website — standard page', 'twt-aeo-ultimate' ); ?></option>
								<option value="article"><?php esc_html_e( 'article — blog post or news', 'twt-aeo-ultimate' ); ?></option>
								<option value="product"><?php esc_html_e( 'product — WooCommerce or shop item', 'twt-aeo-ultimate' ); ?></option>
							</select>
							<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Auto-selected by post type. Posts → article, Products → product, Pages → website.', 'twt-aeo-ultimate' ); ?></span>
						</div>
					</div>

					<!-- LinkedIn section -->
					<div class="twt-aeo-modal__section">
						<p class="twt-aeo-modal__section-title">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#0a66c2" aria-hidden="true" style="flex-shrink:0;vertical-align:-2px;">
								<path d="M20.447 20.452H16.89v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a1.98 1.98 0 0 1-1.98-1.98c0-1.093.887-1.98 1.98-1.98 1.094 0 1.98.887 1.98 1.98a1.98 1.98 0 0 1-1.98 1.98zm1.971 13.019H3.366V9h3.942v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
							</svg>
							<?php esc_html_e( 'LinkedIn', 'twt-aeo-ultimate' ); ?>
						</p>
						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label"><?php esc_html_e( 'Company Page', 'twt-aeo-ultimate' ); ?></label>
							<div id="sg-linkedin-company-display"></div>
							<span class="twt-aeo-modal__hint">
								<?php esc_html_e( 'LinkedIn reads your og:title, og:description, and og:image set above — no extra tags needed. This URL is your global setting used for Organisation schema.', 'twt-aeo-ultimate' ); ?>
							</span>
						</div>
					</div>

					<!-- Twitter / X section -->
					<div class="twt-aeo-modal__section">
						<p class="twt-aeo-modal__section-title">
							<span class="dashicons dashicons-twitter" style="font-size:14px;width:14px;height:14px;color:#1da1f2;"></span>
							<?php esc_html_e( 'Twitter / X Card', 'twt-aeo-ultimate' ); ?>
						</p>

						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="sg-tw-card"><?php esc_html_e( 'twitter:card', 'twt-aeo-ultimate' ); ?></label>
							<select id="sg-tw-card" class="twt-aeo-modal__select">
								<option value="summary_large_image"><?php esc_html_e( 'summary_large_image — full-width image card (recommended)', 'twt-aeo-ultimate' ); ?></option>
								<option value="summary"><?php esc_html_e( 'summary — small square thumbnail card', 'twt-aeo-ultimate' ); ?></option>
							</select>
							<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Controls how the link preview looks when shared on X. summary_large_image gets more clicks.', 'twt-aeo-ultimate' ); ?></span>
						</div>

						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="sg-tw-creator">
								<?php esc_html_e( 'twitter:creator', 'twt-aeo-ultimate' ); ?>
								<span id="sg-autofill-chip" class="twt-aeo-autofill-chip" style="display:none;">
									<span class="dashicons dashicons-admin-users" style="font-size:10px;width:10px;height:10px;"></span>
									<?php esc_html_e( 'Fill from author', 'twt-aeo-ultimate' ); ?>
								</span>
							</label>
							<input type="text" id="sg-tw-creator" class="twt-aeo-modal__input" placeholder="@authorhandle">
							<span class="twt-aeo-modal__hint" id="sg-tw-creator-hint"><?php esc_html_e( 'Author\'s X @handle. Attributes the post to them when shared.', 'twt-aeo-ultimate' ); ?></span>
						</div>
					</div>

				</div><!-- .twt-aeo-modal__body -->

				<div class="twt-aeo-modal__footer">
					<button id="sg-save-btn" class="twt-aeo-btn twt-aeo-btn--primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save', 'twt-aeo-ultimate' ); ?>
					</button>
					<button class="twt-aeo-btn twt-aeo-btn--ghost js-sg-modal-close"><?php esc_html_e( 'Cancel', 'twt-aeo-ultimate' ); ?></button>
					<span id="sg-save-status" class="twt-aeo-modal__status"></span>
				</div>

			</div>
		</div>
		<!-- ── /Social Graph Edit Modal ── -->

		<?php
		ob_start();
		?>
		(function($) {
			'use strict';

			var nonce         = <?php echo wp_json_encode( $sg_nonce ); ?>;
			var aiNonce       = <?php echo wp_json_encode( $ai_nonce ); ?>;
			var ajaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var linkedinUrl   = <?php echo wp_json_encode( $linkedin_url ); ?>;
			var linkedinSettingsUrl = <?php echo wp_json_encode( admin_url( 'admin.php?page=twt-aeo-social-graph' ) ); ?>;
			var mediaFrame    = null;
			var currentMeta   = '';

			// ── Image helpers ──────────────────────────────────────────────────────

			// Mirrors TWTAEO_OG_Writer::is_generic_image_label() — camera/export
			// names and bare numbers don't make useful og:image:alt text.
			function isGenericImageLabel( label ) {
				label = String( label || '' ).trim().toLowerCase();
				if ( label.length < 4 ) { return true; }
				if ( /\.(jpe?g|png|gif|webp|avif|bmp|tiff?|heic)$/.test( label ) ) { return true; }
				if ( /^(img|image|dsc[fn]?|dcim|pxl|gopr|mvimg|vid|screen[\s_-]?shot|screenshot|photo|picture|pic|untitled|unnamed|capture|snapshot|scan|file|frame|clipboard|pasted[\s_-]?image|whatsapp[\s_-]?image|placeholder|default|thumbnail|temp)([\s_\-.()\d:at]+|copy|scaled|edited|final|e\d+)*$/.test( label ) ) { return true; }
				if ( /^[\d\s_\-.()x×:]+$/.test( label ) ) { return true; }
				return false;
			}

			function setImage( url ) {
				if ( url ) {
					$('#sg-image-url').val( url );
					$('#sg-image-preview-img').attr('src', url).show();
					$('#sg-image-placeholder').hide();
					$('.js-sg-image-remove').show();
					$('#sg-preview-image').attr('src', url).show();
					$('#sg-preview-image-placeholder').hide();
				} else {
					$('#sg-image-url').val('');
					$('#sg-image-preview-img').attr('src','').hide();
					$('#sg-image-placeholder').show();
					$('.js-sg-image-remove').hide();
					$('#sg-preview-image').attr('src','').hide();
					$('#sg-preview-image-placeholder').show();
				}
			}

			// ── Modal open/close ────────────────────────────────────────────────────

			function openModal( data ) {
				var defaultType = 'website';
				if ( data.postType === 'post' )    defaultType = 'article';
				if ( data.postType === 'product' ) defaultType = 'product';

				$('#sg-post-id').val( data.postId );
				$('#sg-modal-post-name').text( data.postTitle );
				$('#sg-title').val( data.ogTitle || '' );
				$('#sg-description').val( data.ogDescription || '' );

				// Offer "Use meta description" only when one exists and differs.
				currentMeta = data.metaDescription || '';
				$('#sg-use-meta').toggle( !!currentMeta );
				$('#sg-desc-ai-status').text('').css('color', '#646970');
				$('#sg-og-type').val( data.ogType || defaultType );
				$('#sg-tw-card').val( data.twCard || 'summary_large_image' );
				$('#sg-tw-creator').val( data.twCreator || '' );
				setImage( data.ogImage || '' );
				$('#sg-image-alt').val( data.ogImageAlt || '' );

				// Populate LinkedIn company URL display.
				var $liDisplay = $('#sg-linkedin-company-display');
				if ( linkedinUrl ) {
					$liDisplay.html(
						'<a href="' + $('<div>').text(linkedinUrl).html() + '" target="_blank" rel="noopener" style="font-size:13px;color:#0a66c2;word-break:break-all;">' +
						$('<div>').text(linkedinUrl).html() + '</a>'
					);
				} else {
					$liDisplay.html(
						'<span style="font-size:13px;color:#6b7280;"><?php echo esc_js( __( 'Not set.', 'twt-aeo-ultimate' ) ); ?> <a href="' + linkedinSettingsUrl + '" style="color:#2271b1;"><?php echo esc_js( __( 'Add your LinkedIn company URL ↑', 'twt-aeo-ultimate' ) ); ?></a></span>'
					);
				}

				// Show auto-fill chip if author has a Twitter handle.
				if ( data.authorTwitter ) {
					$('#sg-autofill-chip').show().data('handle', data.authorTwitter);
					$('#sg-tw-creator-hint').text(
						'<?php esc_html_e( 'Author\'s X @handle. Click "Fill from author" to use their handle from Author Entity schema.', 'twt-aeo-ultimate' ); ?>'
					);
				} else {
					$('#sg-autofill-chip').hide();
					$('#sg-tw-creator-hint').text(
						'<?php esc_html_e( 'Author\'s X @handle. Attributes the post to them when shared. Add a Twitter handle via Author Entity to auto-fill.', 'twt-aeo-ultimate' ); ?>'
					);
				}

				updatePreview();
				updateCounts();
				$('#sg-save-status').text('').removeClass('is-success is-error');
				$('#twt-aeo-sg-modal-overlay').removeAttr('aria-hidden').show();
				$('#sg-title').focus();
			}

			function closeModal() {
				$('#twt-aeo-sg-modal-overlay').attr('aria-hidden','true').hide();
			}

			function updatePreview() {
				$('#sg-preview-title').text( $('#sg-title').val() || '—' );
				$('#sg-preview-desc').text( $('#sg-description').val() || '' );
			}

			function updateCounts() {
				var titleLen = $('#sg-title').val().length;
				var descLen  = $('#sg-description').val().length;
				$('#sg-title-count').text( titleLen + ' / 60' ).toggleClass('is-over', titleLen > 60 );
				$('#sg-desc-count').text( descLen + ' / 160' ).toggleClass('is-over', descLen > 160 );
			}

			// ── Open modal ─────────────────────────────────────────────────────────

			$(document).on('click', '.js-sg-edit', function() {
				var $btn = $(this);
				openModal({
					postId:        $btn.data('post-id'),
					postTitle:     $btn.data('post-title'),
					postType:      $btn.data('post-type'),
					ogTitle:       $btn.data('og-title'),
					ogDescription: $btn.data('og-description'),
					metaDescription: $btn.data('meta-description'),
					ogType:        $btn.data('og-type'),
					ogImage:       $btn.data('og-image'),
					ogImageAlt:    $btn.data('og-image-alt'),
					twCard:        $btn.data('tw-card'),
					twCreator:     $btn.data('tw-creator'),
					authorTwitter: $btn.data('author-twitter'),
				});
			});

			// ── Close modal ─────────────────────────────────────────────────────────

			$(document).on('click', '.js-sg-modal-close', closeModal);
			$(document).on('click', '#twt-aeo-sg-modal-overlay', function(e) {
				if ( $(e.target).is('#twt-aeo-sg-modal-overlay') ) closeModal();
			});
			$(document).on('keydown', function(e) {
				if ( e.key === 'Escape' ) closeModal();
			});

			// ── Author auto-fill chip ──────────────────────────────────────────────

			$(document).on('click', '#sg-autofill-chip', function() {
				var handle = $(this).data('handle') || '';
				if ( handle ) {
					$('#sg-tw-creator').val( handle );
				}
			});

			// ── Media library ──────────────────────────────────────────────────────

			$(document).on('click', '.js-sg-image-select', function(e) {
				e.preventDefault();
				e.stopPropagation();
				if ( mediaFrame ) { mediaFrame.open(); return; }
				mediaFrame = wp.media({
					title:    '<?php esc_html_e( 'Select Social Image', 'twt-aeo-ultimate' ); ?>',
					button:   { text: '<?php esc_html_e( 'Use this image', 'twt-aeo-ultimate' ); ?>' },
					multiple: false,
					library:  { type: 'image' },
				});
				mediaFrame.on('select', function() {
					var attachment = mediaFrame.state().get('selection').first().toJSON();
					setImage( attachment.url );
					// Refresh the label from the new image's alt/title — but only
					// with a real description, never a generic camera/export name.
					var label = attachment.alt || attachment.title || '';
					$('#sg-image-alt').val( isGenericImageLabel( label ) ? '' : label );
				});
				mediaFrame.open();
			});

			$(document).on('click', '.js-sg-image-remove', function(e) {
				e.preventDefault(); e.stopPropagation();
				setImage('');
			});

			// ── Live preview ───────────────────────────────────────────────────────

			$(document).on('input', '#sg-title, #sg-description', function() {
				updatePreview();
				updateCounts();
			});

			// ── Save ───────────────────────────────────────────────────────────────

			$(document).on('click', '#sg-save-btn', function() {
				var $btn    = $(this);
				var postId  = $('#sg-post-id').val();
				var $status = $('#sg-save-status');

				$btn.prop('disabled', true).text('<?php esc_attr_e( 'Saving…', 'twt-aeo-ultimate' ); ?>');
				$status.text('').removeClass('is-success is-error');

				$.ajax({
					url:  ajaxUrl,
					type: 'POST',
					data: {
						action:         'twtaeo_save_social_graph',
						nonce:          nonce,
						post_id:        postId,
						og_title:       $('#sg-title').val(),
						og_description: $('#sg-description').val(),
						og_type:        $('#sg-og-type').val(),
						og_image:       $('#sg-image-url').val(),
						og_image_alt:   $('#sg-image-alt').val(),
						tw_card:        $('#sg-tw-card').val(),
						tw_creator:     $('#sg-tw-creator').val(),
					},
					success: function(response) {
						if ( response.success ) {
							$status.text('<?php esc_attr_e( 'Saved!', 'twt-aeo-ultimate' ); ?>').addClass('is-success');

							// Update row data attributes for next open.
							var $row    = $('tr[data-post-id="' + postId + '"]');
							var $editBtn = $row.find('.js-sg-edit');
							$editBtn.data('og-title',       $('#sg-title').val());
							$editBtn.data('og-description', $('#sg-description').val());
							$editBtn.data('og-type',        $('#sg-og-type').val());
							$editBtn.data('og-image',       $('#sg-image-url').val());
							$editBtn.data('og-image-alt',   $('#sg-image-alt').val());
							$editBtn.data('tw-card',        $('#sg-tw-card').val());
							$editBtn.data('tw-creator',     $('#sg-tw-creator').val());

							// Update Twitter card badge.
							var card = $('#sg-tw-card').val();
							$row.find('.twt-aeo-badge--tw, .twt-aeo-badge--auto').replaceWith(
								card
									? '<span class="twt-aeo-badge twt-aeo-badge--tw">' + ( card === 'summary_large_image' ? 'large image' : 'summary' ) + '</span>'
									: '<span class="twt-aeo-badge twt-aeo-badge--auto"><?php esc_html_e( 'auto', 'twt-aeo-ultimate' ); ?></span>'
							);

							// Update image cell.
							var img = $('#sg-image-url').val();
							if ( img ) {
								$row.find('.twt-aeo-og-image-cell').html(
									'<img src="' + img + '" alt="" style="width:90px;height:auto;max-height:60px;object-fit:cover;display:block;border-radius:3px;">'
								);
							}

							// Update og:type pill.
							$row.find('.twt-aeo-og-type-pill').text( $('#sg-og-type').val() );

							// Update title cell.
							var title = $('#sg-title').val();
							if ( title ) {
								var trimmed = title.length > 30 ? title.substring(0,30) + '…' : title;
								$row.find('td:nth-child(3)').html(
									'<span class="twt-aeo-og-field-ok" title="' + $('<div>').text(title).html() + '">' +
									'<span class="dashicons dashicons-yes-alt"></span>' +
									'<span class="twt-aeo-og-field-text">' + $('<div>').text(trimmed).html() + '</span></span>'
								);
							}

							// Update description cell.
							var desc = $('#sg-description').val();
							if ( desc ) {
								var words = desc.split(' ').slice(0,8).join(' ') + (desc.split(' ').length > 8 ? '…' : '');
								$row.find('td:nth-child(4)').html(
									'<span class="twt-aeo-og-field-ok" title="' + $('<div>').text(desc).html() + '">' +
									'<span class="dashicons dashicons-yes-alt"></span>' +
									'<span class="twt-aeo-og-field-text">' + $('<div>').text(words).html() + '</span></span>'
								);
							}

							// Mark row ok if title + desc both set.
							if ( title && desc ) {
								$row.removeClass('twt-aeo-page-row--issues').addClass('twt-aeo-page-row--ok');
								$row.find('.twt-aeo-badge--warn').replaceWith(
									'<span class="twt-aeo-badge twt-aeo-badge--good"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>'
								);
							}

							setTimeout( closeModal, 900 );
						} else {
							$status.text( response.data || '<?php esc_attr_e( 'Error saving.', 'twt-aeo-ultimate' ); ?>' ).addClass('is-error');
						}
					},
					error: function() {
						$status.text('<?php esc_attr_e( 'Request failed.', 'twt-aeo-ultimate' ); ?>').addClass('is-error');
					},
					complete: function() {
						$btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span><?php esc_attr_e( 'Save', 'twt-aeo-ultimate' ); ?>');
					}
				});
			});

			// ── Site handle save ─────────────────────────────────────────────────

			$('#twt-aeo-save-site-handle').on('click', function() {
				var $btn    = $(this);
				var handle  = $('#twt-aeo-site-handle').val();
				var $status = $('#twt-aeo-site-handle-status');

				$btn.prop('disabled', true);
				$status.text('').removeClass('is-success is-error');

				$.ajax({
					url:  ajaxUrl,
					type: 'POST',
					data: {
						action:  'twtaeo_save_twitter_site',
						nonce:   nonce,
						handle:  handle,
					},
					success: function(response) {
						if ( response.success ) {
							$status.text('<?php esc_attr_e( 'Saved!', 'twt-aeo-ultimate' ); ?>').addClass('is-success');
						} else {
							$status.text( response.data || 'Error' ).addClass('is-error');
						}
					},
					error: function() {
						$status.text('Request failed.').addClass('is-error');
					},
					complete: function() {
						$btn.prop('disabled', false);
					}
				});
			});

			// ── LinkedIn company save ────────────────────────────────────────────────

			$('#twt-aeo-save-linkedin-company').on('click', function() {
				var $btn    = $(this);
				var url     = $('#twt-aeo-linkedin-company').val();
				var $status = $('#twt-aeo-linkedin-status');

				$btn.prop('disabled', true);
				$status.text('').removeClass('is-success is-error');

				$.ajax({
					url:  ajaxUrl,
					type: 'POST',
					data: {
						action: 'twtaeo_save_linkedin_company',
						nonce:  nonce,
						url:    url,
					},
					success: function(response) {
						if ( response.success ) {
							$status.text('<?php esc_attr_e( 'Saved!', 'twt-aeo-ultimate' ); ?>').addClass('is-success');
						} else {
							$status.text( response.data || 'Error' ).addClass('is-error');
						}
					},
					error: function() {
						$status.text('Request failed.').addClass('is-error');
					},
					complete: function() {
						$btn.prop('disabled', false);
					}
				});
			});

			// ── Modal: description helpers ───────────────────────────────────────────

			$(document).on('click', '#sg-use-meta', function() {
				if ( currentMeta ) {
					$('#sg-description').val( currentMeta );
					updatePreview();
					updateCounts();
				}
			});

			$(document).on('click', '#sg-gen-ai', function() {
				var $btn = $(this), $st = $('#sg-desc-ai-status');
				$btn.prop('disabled', true);
				$st.css('color', '#646970').text('<?php echo esc_js( __( 'Generating…', 'twt-aeo-ultimate' ) ); ?>');
				$.post(ajaxUrl, { action:'twtaeo_ai_desc_social', nonce:aiNonce, post_id:$('#sg-post-id').val() }, function(res){
					if ( res.success ) {
						$('#sg-description').val( res.data.description );
						updatePreview(); updateCounts();
						$st.css('color', '#1a6629').text('<?php echo esc_js( __( 'Done — review and Save.', 'twt-aeo-ultimate' ) ); ?>');
					} else {
						$st.css('color', '#b32d2e').text( res.data || 'Error' );
					}
				}).fail(function(){
					$st.css('color', '#b32d2e').text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>');
				}).always(function(){ $btn.prop('disabled', false); });
			});

			// ── Modal: AI image generation (OpenAI) ──────────────────────────────────

			$(document).on('click', '#sg-ai-img-btn', function() {
				var $btn = $(this), $st = $('#sg-ai-img-status');
				var postId = $('#sg-post-id').val();
				if ( ! postId ) { return; }
				if ( ! window.confirm('<?php echo esc_js( __( 'Generate a social image with OpenAI? This makes one billed API call and can take up to 30 seconds.', 'twt-aeo-ultimate' ) ); ?>') ) { return; }

				$btn.prop('disabled', true);
				$st.css('color', '#646970').text('<?php echo esc_js( __( 'Generating image… this can take up to 30s.', 'twt-aeo-ultimate' ) ); ?>');

				$.ajax({
					url: ajaxUrl, type: 'POST', timeout: 150000,
					data: {
						action:          'twtaeo_ai_og_image',
						nonce:           aiNonce,
						post_id:         postId,
						style:           $('#sg-ai-img-style').val(),
						use_title:       $('#sg-ai-img-title').is(':checked') ? 1 : 0,
						use_description: $('#sg-ai-img-desc').is(':checked') ? 1 : 0,
						use_brand:       $('#sg-ai-img-brand').is(':checked') ? 1 : 0
					},
					success: function(res) {
						if ( res.success ) {
							setImage( res.data.url );
							if ( res.data.alt && ! $('#sg-image-alt').val() ) { $('#sg-image-alt').val( res.data.alt ); }
							$st.css('color', '#1a6629').text('<?php echo esc_js( __( 'Image added — review and Save.', 'twt-aeo-ultimate' ) ); ?>');

							// It's already in the Media Library — offer to reuse it as
							// the post's featured image.
							if ( res.data.attachment_id && window.confirm('<?php echo esc_js( __( 'Also set this image as the post\'s featured image?', 'twt-aeo-ultimate' ) ); ?>') ) {
								$.post(ajaxUrl, {
									action: 'twtaeo_set_featured',
									nonce: aiNonce,
									post_id: postId,
									attachment_id: res.data.attachment_id
								}, function(r2) {
									if ( r2 && r2.success ) {
										$st.css('color', '#1a6629').text('<?php echo esc_js( __( 'Image added and set as featured — review and Save.', 'twt-aeo-ultimate' ) ); ?>');
									} else {
										$st.css('color', '#b32d2e').text( ( r2 && r2.data ) || '<?php echo esc_js( __( 'Could not set featured image.', 'twt-aeo-ultimate' ) ); ?>' );
									}
								});
							}
						} else {
							$st.css('color', '#b32d2e').text( res.data || 'Error' );
						}
					},
					error: function() {
						$st.css('color', '#b32d2e').text('<?php echo esc_js( __( 'Request failed or timed out.', 'twt-aeo-ultimate' ) ); ?>');
					},
					complete: function() { $btn.prop('disabled', false); }
				});
			});

			// ── Bulk OG descriptions ─────────────────────────────────────────────────

			var $bulk = $('#twt-aeo-og-bulk');
			if ( $bulk.length ) {
				var sgNonce  = $bulk.data('sgnonce');
				var aiN      = $bulk.data('ainonce');
				var $bstatus = $('#twt-aeo-og-bulk-status');
				var $ogStop  = $('#twt-aeo-og-stop');
				var $ogNote  = $('#twt-aeo-og-note');
				var ogPoll   = null;

				// Per-provider model + speed/limitation note under the picker.
				var ogProviderInfo = <?php echo wp_json_encode( TWTAEO_AI_Description::provider_info() ); ?>;
				function ogUpdateNote(){
					var info = ogProviderInfo[$('#twt-aeo-og-provider').val()];
					if ( info ) { $ogNote.text(info.model + ' — ' + info.note); }
					else { $ogNote.text(''); }
				}
				$('#twt-aeo-og-provider').on('change', ogUpdateNote);
				ogUpdateNote();

				$('#twt-aeo-og-from-meta').on('click', function() {
					var $b = $(this);
					$b.prop('disabled', true);
					$bstatus.css('color', '#646970').text('<?php echo esc_js( __( 'Filling from existing meta…', 'twt-aeo-ultimate' ) ); ?>');
					$.post(ajaxUrl, { action:'twtaeo_og_fill_from_meta', nonce:sgNonce }, function(res){
						if ( res.success ) {
							$bstatus.css('color', '#1a6629').text(
								res.data.filled + ' <?php echo esc_js( __( 'filled', 'twt-aeo-ultimate' ) ); ?>'
								+ ( res.data.skipped ? ', ' + res.data.skipped + ' <?php echo esc_js( __( 'had no meta', 'twt-aeo-ultimate' ) ); ?>' : '' )
								+ '. <?php echo esc_js( __( 'Reloading…', 'twt-aeo-ultimate' ) ); ?>'
							);
							setTimeout(function(){ location.reload(); }, 1200);
						} else {
							$bstatus.css('color', '#b32d2e').text( res.data || 'Error' );
							$b.prop('disabled', false);
						}
					}).fail(function(){
						$bstatus.css('color', '#b32d2e').text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>');
						$b.prop('disabled', false);
					});
				});

				// Rescan All: walk every scan block server-side, one AJAX call per
			// block, with live progress — then reload so the cards show the
			// finished site-wide census.
			$('#twt-aeo-og-rescan').on('click', function(){
				var $b = $(this).prop('disabled', true);
				var $s = $('#twt-aeo-og-rescan-status');
				function step(block){
					$.post(ajaxUrl, { action:'twtaeo_og_census_block', nonce:sgNonce, block:block }, function(res){
						if ( ! res.success ) { $s.css('color','#b32d2e').text(res.data || 'Error'); $b.prop('disabled', false); return; }
						var st = res.data;
						$s.css('color','#646970').text(
							'<?php echo esc_js( __( 'Scanning…', 'twt-aeo-ultimate' ) ); ?> '
							+ (st.totals && st.totals.scanned ? st.totals.scanned : 0) + ' / ' + (st.total_items || 0)
						);
						if ( st.completed_at ) {
							window.location = '<?php echo esc_url_raw( add_query_arg( array( 'page' => 'twt-aeo-social-graph', 'rescanned' => 1 ), admin_url( 'admin.php' ) ) ); ?>';
						} else {
							step( (st.blocks_done || block) + 1 );
						}
					}).fail(function(){ $s.css('color','#b32d2e').text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>'); $b.prop('disabled', false); });
				}
				step(1);
			});

			function ogRender(s){
					if ( !s ) { return; }
					if ( s.running || s.status === 'running' ) {
						$('#twt-aeo-og-ai').prop('disabled', true);
						$ogStop.show().prop('disabled', false);
						$bstatus.css('color', '#646970').text(
							(s.done||0) + ' / ' + (s.total||0) + ' — <?php echo esc_js( __( 'generating in background (you can leave this page)…', 'twt-aeo-ultimate' ) ); ?>'
						);
					} else if ( s.status === 'error' ) {
						$('#twt-aeo-og-ai').prop('disabled', false);
						$ogStop.hide();
						$bstatus.css('color', '#b32d2e').text('<?php echo esc_js( __( 'Stopped: ', 'twt-aeo-ultimate' ) ); ?>' + (s.last_error||'error') + ' (' + (s.created||0) + ')');
					} else if ( s.status === 'stopped' ) {
						$('#twt-aeo-og-ai').prop('disabled', false);
						$ogStop.hide();
						$bstatus.css('color', '#646970').text((s.created||0) + ' <?php echo esc_js( __( 'created — stopped.', 'twt-aeo-ultimate' ) ); ?>');
					} else if ( s.status === 'done' ) {
						$('#twt-aeo-og-ai').prop('disabled', false);
						$ogStop.hide();
						// Name the skips — "0 created" with the skip count hidden reads
						// as a silent failure to someone staring at rows still missing.
						$bstatus.css('color', (s.created||0) > 0 ? '#1a6629' : '#996800').text(
							(s.created||0) + ' <?php echo esc_js( __( 'created', 'twt-aeo-ultimate' ) ); ?>'
							+ ( (s.errors||0) ? ', ' + s.errors + ' <?php echo esc_js( __( 'skipped (not enough content to summarise)', 'twt-aeo-ultimate' ) ); ?>' : '' )
							+ '. <?php echo esc_js( __( 'Reload to see them.', 'twt-aeo-ultimate' ) ); ?>'
						);
					} else {
						$('#twt-aeo-og-ai').prop('disabled', false);
						$ogStop.hide();
					}
				}
				function ogPollOnce(){
					$.post(ajaxUrl, { action:'twtaeo_ai_desc_status', nonce:aiN }, function(res){
						if ( !res.success ) { return; }
						ogRender(res.data);
						if ( res.data.status !== 'running' ) { clearInterval(ogPoll); ogPoll = null; }
					});
				}
				function ogStartPolling(){ if (ogPoll) { return; } ogPollOnce(); ogPoll = setInterval(ogPollOnce, 4000); }

				$('#twt-aeo-og-ai').on('click', function() {
					var $b = $(this);
					$b.prop('disabled', true);
					$bstatus.css('color', '#646970').text('<?php echo esc_js( __( 'Finding posts…', 'twt-aeo-ultimate' ) ); ?>');
					var provider = $('#twt-aeo-og-provider').val();
					var fd = new FormData();
					fd.append('action','twtaeo_ai_desc_start'); fd.append('nonce', aiN);
					fd.append('mode','og'); fd.append('provider', provider);
					if ( ! window.confirm('<?php echo esc_js( __( 'Generate social descriptions for every page, post and product missing one? This makes one billed API call per item and runs in the background.', 'twt-aeo-ultimate' ) ); ?>') ) {
						$bstatus.text('<?php echo esc_js( __( 'Cancelled.', 'twt-aeo-ultimate' ) ); ?>'); $b.prop('disabled', false); return;
					}
					fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(r){
							if ( ! r.success ) { $bstatus.css('color','#b32d2e').text(r.data||'Error'); $b.prop('disabled', false); return; }
							// An idle reply with nothing queued means there was nothing
							// to generate — say so instead of leaving "Finding posts…".
							if ( r.data.status !== 'running' && ! (r.data.total > 0) ) {
								$bstatus.css('color', '#646970').text('<?php echo esc_js( __( 'Nothing to generate — every page, post and product already has a social description.', 'twt-aeo-ultimate' ) ); ?>');
								$b.prop('disabled', false);
								return;
							}
							ogRender(r.data); ogStartPolling();
						})
						.catch(function(){ $bstatus.css('color','#b32d2e').text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>'); $b.prop('disabled', false); });
				});

				$ogStop.on('click', function() {
					$ogStop.prop('disabled', true);
					$.post(ajaxUrl, { action:'twtaeo_ai_desc_stop', nonce:aiN }, function(res){
						if ( res && res.success ) {
							ogRender(res.data);
							if ( res.data.status !== 'running' ) { clearInterval(ogPoll); ogPoll = null; }
						} else {
							$ogStop.prop('disabled', false);
						}
					}).fail(function(){ $ogStop.prop('disabled', false); });
				});

				// Resume live view if a background job is already running.
				if ( String($bulk.data('running')) === '1' ) { ogStartPolling(); }
			}

		})(jQuery);

		// ── Sortable columns ────────────────────────────────────────────────────
		(function() {
			var table = document.getElementById('twt-sg-table');
			if ( ! table ) return;
			var sortCol = null, sortDir = 1;
			table.querySelectorAll('thead th[data-sort]').forEach(function(th) {
				th.addEventListener('click', function() {
					var key = th.dataset.sort;
					if ( sortCol === key ) { sortDir *= -1; } else { sortCol = key; sortDir = 1; }
					table.querySelectorAll('.twt-og-sort-icon').forEach(function(el) { el.textContent = '↕'; el.style.color = '#999'; });
					th.querySelector('.twt-og-sort-icon').textContent = sortDir === 1 ? '↑' : '↓';
					th.querySelector('.twt-og-sort-icon').style.color = '#2271b1';
					var tbody = table.querySelector('tbody');
					var rows  = Array.from( tbody.querySelectorAll('tr') );
					rows.sort(function(a, b) {
						var attr = 'data-sort-' + key;
						var aVal = a.getAttribute(attr) || '', bVal = b.getAttribute(attr) || '';
						var aNum = parseFloat(aVal), bNum = parseFloat(bVal);
						if ( ! isNaN(aNum) && ! isNaN(bNum) ) return ( aNum - bNum ) * sortDir;
						return aVal.localeCompare(bVal) * sortDir;
					});
					rows.forEach(function(row) { tbody.appendChild(row); });
				});
			});
		})();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
