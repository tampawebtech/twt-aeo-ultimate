<?php
/**
 * Dashboard Page
 *
 * Shows site-wide AEO summary with per-page scan results.
 * Scans are triggered automatically on save_post.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Dashboard {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		$seo_plugin = TWTAEO_SEO_Compatibility::get_plugin_name();
		$conflict   = TWTAEO_SEO_Compatibility::detect_conflicts();
		$aeo_gaps   = TWTAEO_SEO_Compatibility::get_aeo_gaps();
		$summary    = TWTAEO_Scan_Store::get_site_summary();
		$settings   = get_option( 'twtaeo_settings', array() );
		$mode       = $settings['display_mode'] ?? 'simple';

		$per_page    = 25;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$total_posts = TWTAEO_Scan_Store::get_total_post_count();
		$total_pages = max( 1, (int) ceil( $total_posts / $per_page ) );
		$paged       = min( $paged, $total_pages );
		$all_pages   = TWTAEO_Scan_Store::get_all_pages_with_status( $paged, $per_page );

		// Build the list of post IDs scanned interactively by Scan All. Anything
		// beyond this cap is handed to the background scanner when the
		// foreground pass finishes.
		$all_post_ids = get_posts( array(
			'post_type'      => TWTAEO_Scan_Store::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => TWTAEO_Background_Scan::FOREGROUND_CAP,
			'fields'         => 'ids',
		) );
		$bg_remaining = max( 0, $total_posts - count( $all_post_ids ) );
		$bg_state     = TWTAEO_Background_Scan::get_state();
		$bg_running   = TWTAEO_Background_Scan::is_running( $bg_state );

		// AEO Score rollup — reads stored per-page scores; scoring itself
		// happens at scan time.
		$score_summary = class_exists( 'TWTAEO_Aeo_Score' ) ? TWTAEO_Aeo_Score::site_summary() : null;
		if ( $score_summary ) {
			TWTAEO_Aeo_Score::maybe_record_snapshot( $score_summary );
		}
		$score_history = class_exists( 'TWTAEO_Aeo_Score' ) ? TWTAEO_Aeo_Score::get_history() : array();

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Score', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div class="twt-aeo-header__meta">
						<span class="twt-aeo-badge <?php echo $conflict ? 'twt-aeo-badge--conflict' : 'twt-aeo-badge--plugin'; ?>">
							<?php echo esc_html( $seo_plugin ); ?>
						</span>
						<span class="twt-aeo-badge twt-aeo-badge--page">
							<?php echo esc_html( $summary['scanned'] ); ?> / <?php echo esc_html( $summary['total'] ); ?> pages scanned
						</span>
						<?php if ( $mode === 'professional' ) : ?>
						<span class="twt-aeo-badge" style="background:rgba(245,158,11,.2);color:#fbbf24;">
							<?php esc_html_e( 'Professional Mode', 'twt-aeo-ultimate' ); ?>
						</span>
						<?php endif; ?>
						<button
							type="button"
							id="twt-aeo-scan-all-btn"
							class="twt-aeo-btn twt-aeo-btn--primary"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'twtaeo_scan_nonce' ) ); ?>"
							data-ids="<?php echo esc_attr( wp_json_encode( $all_post_ids ) ); ?>"
							data-remaining="<?php echo esc_attr( $bg_remaining ); ?>"
						>
							<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;margin-right:4px;font-size:14px;width:14px;height:14px;"></span>
							<?php esc_html_e( 'Scan All Pages', 'twt-aeo-ultimate' ); ?>
						</button>
						<?php if ( class_exists( 'TWTAEO_Pro_Transmitter' ) && TWTAEO_Pro_Transmitter::is_connected() ) :
							$last_handshake = class_exists( 'TWTAEO_Data_Handshake' ) ? TWTAEO_Data_Handshake::get_last_handshake() : array();
						?>
						<button
							type="button"
							id="twt-aeo-handshake-btn"
							class="twt-aeo-btn"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'twtaeo_handshake_nonce' ) ); ?>"
							title="<?php esc_attr_e( 'Bundle baseline, schema & performance data, send it to the Agency Hub, then purge transmitted local logs.', 'twt-aeo-ultimate' ); ?>"
						>
							<span class="dashicons dashicons-cloud-upload" style="vertical-align:middle;margin-top:-2px;margin-right:4px;font-size:14px;width:14px;height:14px;"></span>
							<?php esc_html_e( 'Sync Now', 'twt-aeo-ultimate' ); ?>
						</button>
						<span id="twt-aeo-handshake-status" class="twt-aeo-handshake-status" style="margin-left:8px;font-size:12px;opacity:.8;">
							<?php
							if ( ! empty( $last_handshake['transmitted_at'] ) ) {
								printf(
									/* translators: %s: human-readable time difference */
									esc_html__( 'Last sync: %s ago', 'twt-aeo-ultimate' ),
									esc_html( human_time_diff( strtotime( $last_handshake['transmitted_at'] ), current_time( 'timestamp' ) ) )
								);
							}
							?>
						</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Scan All Progress Bar (hidden until scan starts; shown on load when a background scan is running) -->
			<div id="twt-aeo-scan-progress" style="display:none;margin:0 0 24px;"
				data-bg-running="<?php echo $bg_running ? '1' : '0'; ?>"
				data-bg-done="<?php echo esc_attr( (int) $bg_state['done'] ); ?>"
				data-bg-total="<?php echo esc_attr( (int) $bg_state['total'] ); ?>">
				<div class="twt-aeo-progress-bar-wrap">
					<div class="twt-aeo-progress-bar">
						<div class="twt-aeo-progress-bar__fill" id="twt-aeo-progress-fill" style="width:0%"></div>
					</div>
					<span class="twt-aeo-progress-label" id="twt-aeo-progress-label">
						<?php esc_html_e( 'Scanning…', 'twt-aeo-ultimate' ); ?>
					</span>
				</div>
			</div>

			<?php
			// ── AI Meta Descriptions panel ──────────────────────────────────────
			$ai_providers = TWTAEO_AI_Description::available_providers();
			$ai_enabled   = TWTAEO_AI_Description::is_enabled();
			$ai_provider  = TWTAEO_AI_Description::get_provider();
			$ai_labels    = array( 'claude' => 'Claude', 'openai' => 'OpenAI (ChatGPT)', 'gemini' => 'Gemini' );
			$ai_job       = TWTAEO_AI_Description::job_payload();
			?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card" style="border-left:3px solid #2271b1;">
					<h2 style="margin:0 0 6px;font-size:15px;display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-superhero" style="color:#2271b1;"></span>
						<?php esc_html_e( 'AI Meta Descriptions', 'twt-aeo-ultimate' ); ?>
					</h2>
					<p style="margin:0 0 14px;color:#50575e;font-size:13px;max-width:760px;line-height:1.55;">
						<?php esc_html_e( 'Turn this on and the plugin will automatically write a meta description for a post the first time you save it (existing descriptions are never overwritten). You can also generate one on demand from each post\'s editor, or fill in everything that\'s missing in bulk below. Pick which AI does the writing.', 'twt-aeo-ultimate' ); ?>
					</p>

					<?php if ( empty( $ai_providers ) ) : ?>
						<p style="margin:0;font-size:13px;color:#b32d2e;">
							<?php
							printf(
								wp_kses(
									/* translators: %s: Settings page URL. */
									__( 'No AI provider is configured yet. Add a Claude, OpenAI, or Gemini key under <a href="%s">TWT AEO &rsaquo; Settings</a> to enable this.', 'twt-aeo-ultimate' ),
									array( 'a' => array( 'href' => array() ) )
								),
								esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) )
							);
							?>
						</p>
					<?php else : ?>
						<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;"
							id="twt-aeo-aidesc"
							data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_AI_Description::NONCE ) ); ?>"
							data-running="<?php echo $ai_job['running'] ? '1' : '0'; ?>">

							<label class="twt-aeo-toggle" style="display:inline-flex;align-items:center;gap:10px;">
								<input type="checkbox" id="twt-aeo-aidesc-enabled" <?php checked( $ai_enabled ); ?> />
								<span class="twt-aeo-toggle__slider"></span>
								<span style="font-size:13px;font-weight:600;"><?php esc_html_e( 'Auto-generate on save', 'twt-aeo-ultimate' ); ?></span>
							</label>

							<label style="font-size:13px;display:inline-flex;align-items:center;gap:8px;">
								<?php esc_html_e( 'AI provider', 'twt-aeo-ultimate' ); ?>
								<select id="twt-aeo-aidesc-provider">
									<?php foreach ( $ai_providers as $slug ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ai_provider, $slug ); ?>>
											<?php echo esc_html( $ai_labels[ $slug ] ?? ucfirst( $slug ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>

							<span style="flex:1;min-width:40px;"></span>

							<button type="button" class="button button-primary" id="twt-aeo-aidesc-bulk">
								<?php esc_html_e( 'Generate all missing', 'twt-aeo-ultimate' ); ?>
							</button>
							<button type="button" class="button" id="twt-aeo-aidesc-stop" style="display:none;">
								<?php esc_html_e( 'Stop', 'twt-aeo-ultimate' ); ?>
							</button>
							<span id="twt-aeo-aidesc-status" style="font-size:12px;color:#646970;"></span>

							<p id="twt-aeo-aidesc-note" style="flex-basis:100%;margin:2px 0 0;font-size:13px;color:#646970;line-height:1.55;"></p>
						</div>

						<div class="notice notice-warning inline" style="margin:14px 0 0;padding:8px 12px;">
							<p style="margin:0;font-size:13px;line-height:1.55;">
								<?php esc_html_e( 'Usage & cost: each description is one API call to your chosen provider, billed to your own account based on token usage. Generating a single post from its editor costs a fraction of a cent; the bulk run processes every post that is missing a description, so it scales with your site size. Before a bulk run you will be shown the exact number of posts and asked to confirm. Bulk generation runs in the background — you can safely leave this page once it starts.', 'twt-aeo-ultimate' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( $conflict ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-conflict-banner">
					<div class="twt-aeo-conflict-banner__icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="twt-aeo-conflict-banner__body">
						<h2 class="twt-aeo-conflict-banner__title">
							<?php esc_html_e( 'SEO Plugin Conflict Detected', 'twt-aeo-ultimate' ); ?>
						</h2>
						<p class="twt-aeo-conflict-banner__message">
							<?php echo esc_html( $conflict['message'] ); ?>
						</p>
						<p class="twt-aeo-conflict-banner__action">
							<?php esc_html_e( 'Recommendation: Choose one SEO plugin and deactivate the other. Having both active creates duplicate and conflicting structured data that harms AI visibility.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php if ( ! empty( $conflict['duplicate_types'] ) ) : ?>
						<div class="twt-aeo-conflict-banner__types">
							<strong><?php esc_html_e( 'Duplicated schema types:', 'twt-aeo-ultimate' ); ?></strong>
							<div class="twt-aeo-tag-list" style="margin-top:6px;">
								<?php foreach ( $conflict['duplicate_types'] as $type ) : ?>
									<span class="twt-aeo-tag twt-aeo-tag--conflict"><?php echo esc_html( $type ); ?></span>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</section>
			<?php endif; ?>

			<!-- Site Summary Cards -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-summary-grid">

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Total Pages', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['scanned'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Scanned', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $summary['missing_schema'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['missing_schema'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing Schema', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $summary['no_scan'] > 0 ? 'twt-aeo-summary-card--warn' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['no_scan'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Not Yet Scanned', 'twt-aeo-ultimate' ); ?></div>
					</div>

				</div>
			</section>

			<?php if ( $score_summary ) : ?>
			<!-- AEO Score -->
			<section class="twt-aeo-section" id="twt-aeo-score-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'AEO Score', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card" style="display:flex;flex-wrap:wrap;gap:28px;align-items:flex-start;">

					<?php
					$site_score = $score_summary['site_score'];
					$ring_color = static function ( $s ) {
						return $s >= 80 ? '#16a34a' : ( $s >= 50 ? '#d97706' : '#dc2626' );
					};
					?>
					<div style="text-align:center;min-width:140px;">
						<?php
						if ( null !== $site_score ) :
							$c = $ring_color( $site_score );
							/* translators: %d: site-wide AEO score out of 100. */
							$ring_label = sprintf( __( 'Site AEO score %d out of 100', 'twt-aeo-ultimate' ), $site_score );
						?>
						<svg viewBox="0 0 80 80" width="110" height="110" role="img" aria-label="<?php echo esc_attr( $ring_label ); ?>">
							<circle cx="40" cy="40" r="34" fill="none" stroke="#e5e7eb" stroke-width="8"/>
							<circle cx="40" cy="40" r="34" fill="none" stroke="<?php echo esc_attr( $c ); ?>" stroke-width="8" stroke-linecap="round"
								stroke-dasharray="<?php echo esc_attr( round( 213.6 * $site_score / 100, 1 ) ); ?> 213.6" transform="rotate(-90 40 40)"/>
							<text x="40" y="48" text-anchor="middle" font-size="24" font-weight="700" fill="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $site_score ); ?></text>
						</svg>
						<p style="margin:6px 0 0;font-size:12px;color:#646970;">
							<?php
							printf(
								/* translators: 1: average page score, 2: site checklist percentage. */
								esc_html__( '70%% pages (avg %1$d) + 30%% site setup (%2$d%%)', 'twt-aeo-ultimate' ),
								(int) $score_summary['avg_page'],
								(int) $score_summary['checklist_pct']
							);
							?>
							<br/>
							<?php
							printf(
								/* translators: %d: number of scored pages. */
								esc_html__( '%d pages scored', 'twt-aeo-ultimate' ),
								(int) $score_summary['scored_pages']
							);
							?>
						</p>
						<?php else : ?>
						<p class="twt-aeo-muted" style="max-width:140px;"><?php esc_html_e( 'Run "Scan All Pages" to compute your first AEO Score.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $score_summary['categories'] ) ) : ?>
					<div style="flex:1;min-width:260px;">
						<p style="margin:0 0 8px;font-weight:600;font-size:13px;"><?php esc_html_e( 'Where points are lost (site-wide)', 'twt-aeo-ultimate' ); ?></p>
						<?php foreach ( $score_summary['categories'] as $cat ) : $pc = (int) $cat['pct']; ?>
						<div style="display:flex;align-items:center;gap:10px;margin-bottom:7px;">
							<span style="width:150px;font-size:12px;color:#50575e;flex-shrink:0;"><?php echo esc_html( $cat['label'] ); ?></span>
							<span style="flex:1;background:#e5e7eb;border-radius:4px;height:10px;overflow:hidden;display:block;">
								<span style="display:block;height:100%;width:<?php echo esc_attr( $pc ); ?>%;background:<?php echo esc_attr( $ring_color( $pc ) ); ?>;"></span>
							</span>
							<span style="width:38px;font-size:12px;color:#50575e;text-align:right;"><?php echo esc_html( $pc ); ?>%</span>
						</div>
						<?php endforeach; ?>
						<p style="margin:10px 0 0;font-size:12px;color:#646970;">
							<?php esc_html_e( 'Click any page\'s score ring below for its line-by-line breakdown — every point named, every gap linked to its fix.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
					<?php endif; ?>

					<div style="min-width:240px;">
						<p style="margin:0 0 8px;font-weight:600;font-size:13px;"><?php esc_html_e( 'Score over time', 'twt-aeo-ultimate' ); ?></p>
						<?php if ( count( $score_history ) >= 2 ) :
							$w = 240; $h = 70; $n = count( $score_history );
							$pts = array();
							foreach ( array_values( $score_history ) as $i => $snap ) {
								$x     = round( $i * ( $w - 10 ) / max( 1, $n - 1 ) + 5, 1 );
								$y     = round( $h - 5 - ( $h - 15 ) * max( 0, min( 100, (int) $snap['score'] ) ) / 100, 1 );
								$pts[] = $x . ',' . $y;
							}
							$last_snap = end( $score_history );
						?>
						<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" width="<?php echo (int) $w; ?>" height="<?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'AEO score history', 'twt-aeo-ultimate' ); ?>">
							<polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
							<?php $last_xy = explode( ',', end( $pts ) ); ?>
							<circle cx="<?php echo esc_attr( $last_xy[0] ); ?>" cy="<?php echo esc_attr( $last_xy[1] ); ?>" r="3" fill="#2271b1"/>
						</svg>
						<p style="margin:4px 0 0;font-size:11px;color:#646970;">
							<?php echo esc_html( $score_history[0]['date'] ); ?> → <?php echo esc_html( $last_snap['date'] ); ?>
						</p>
						<?php else : ?>
						<p class="twt-aeo-muted" style="font-size:12px;"><?php esc_html_e( 'History starts today — one point is saved per day, so the trend line appears from tomorrow.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $score_summary['checklist'] ) ) : ?>
					<div style="flex-basis:100%;border-top:1px solid #f0f0f1;padding-top:14px;">
						<p style="margin:0 0 8px;font-weight:600;font-size:13px;"><?php esc_html_e( 'Site setup (30% of the score)', 'twt-aeo-ultimate' ); ?></p>
						<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:8px 24px;">
							<?php foreach ( $score_summary['checklist'] as $item ) :
								$full = $item['pts'] >= $item['max'];
							?>
							<div style="font-size:12px;display:flex;gap:8px;align-items:baseline;">
								<span style="color:<?php echo $full ? '#16a34a' : '#dc2626'; ?>;font-weight:700;flex-shrink:0;"><?php echo $full ? '✓' : '✕'; ?></span>
								<span>
									<strong><?php echo esc_html( $item['label'] ); ?></strong>
									<span style="color:#646970;">(<?php echo esc_html( $item['pts'] . '/' . $item['max'] ); ?>)</span>
									— <?php echo esc_html( $item['why'] ); ?>
									<?php if ( ! $full && ! empty( $item['fix'] ) ) : ?>
										<a href="<?php echo esc_url( $item['fix'] ); ?>" class="twt-aeo-link"><?php echo esc_html( $item['fix_label'] ); ?></a>
									<?php endif; ?>
								</span>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

				</div>
			</section>
			<?php endif; ?>

			<!-- Page-by-Page Results -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
					<?php esc_html_e( 'Page AEO Status', 'twt-aeo-ultimate' ); ?>
					<button type="button" id="twt-aeo-autofill-btn" class="twt-aeo-btn twt-aeo-btn--primary"
						data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_Custom_Schema_Writer::NONCE_ACTION ) ); ?>"
						title="<?php esc_attr_e( 'Create every missing schema that can be built from data the site already has — titles, authors, company profile, business info, detected FAQs, WooCommerce products. Types needing facts only you know (event dates, prices outside WooCommerce) are reported, never guessed.', 'twt-aeo-ultimate' ); ?>">
						⚡ <?php esc_html_e( 'Auto-Fill Missing Schema', 'twt-aeo-ultimate' ); ?>
					</button>
					<span id="twt-aeo-autofill-status" style="font-size:12px;font-weight:400;color:#646970;"></span>
				</h2>
				<div id="twt-aeo-autofill-summary" class="twt-aeo-card" style="display:none;margin-bottom:14px;font-size:13px;"></div>

				<?php if ( empty( $all_pages ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty"><?php esc_html_e( 'No published pages found. Create and save a page to trigger a scan.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				<?php else : ?>
				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Score', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Intent', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Confidence', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Schema Present', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Last Scanned', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $all_pages as $item ) :
							$post  = $item['post'];
							$scan  = $item['scan'];
							$intent     = $scan['intent'] ?? null;
							$evaluation = $scan['evaluation'] ?? null;
							$schema     = $scan['schema'] ?? null;
							$missing    = $evaluation['missing'] ?? array();
							$present    = $evaluation['present'] ?? array();
							$confidence = $intent['confidence'] ?? null;
							$conf_class = $confidence === 'high' ? 'good' : ( $confidence === 'medium' ? 'warn' : 'neutral' );
						?>
							<tr class="twt-aeo-page-row <?php echo ! $scan ? 'twt-aeo-page-row--unscanned' : ( ! empty( $missing ) ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok' ); ?>">

								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $post ) ); ?>
									</a>
									<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
								</td>

								<td class="twt-aeo-score-cell" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
									<?php
									$page_score = class_exists( 'TWTAEO_Aeo_Score' )
										? get_post_meta( $post->ID, TWTAEO_Aeo_Score::META_SCORE, true )
										: '';
									if ( '' !== $page_score && false !== $page_score ) :
										$ps  = (int) $page_score;
										$psc = $ps >= 80 ? '#16a34a' : ( $ps >= 50 ? '#d97706' : '#dc2626' );
									?>
									<button type="button" class="twt-aeo-score-ring" data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
										title="<?php esc_attr_e( 'See the full score breakdown', 'twt-aeo-ultimate' ); ?>"
										style="background:none;border:0;padding:0;cursor:pointer;">
										<svg viewBox="0 0 32 32" width="34" height="34" aria-hidden="true">
											<circle cx="16" cy="16" r="12" fill="none" stroke="#e5e7eb" stroke-width="3.5"/>
											<circle cx="16" cy="16" r="12" fill="none" stroke="<?php echo esc_attr( $psc ); ?>" stroke-width="3.5" stroke-linecap="round"
												stroke-dasharray="<?php echo esc_attr( round( 75.4 * $ps / 100, 1 ) ); ?> 75.4" transform="rotate(-90 16 16)"/>
											<text x="16" y="20" text-anchor="middle" font-size="10" font-weight="700" fill="<?php echo esc_attr( $psc ); ?>"><?php echo esc_html( $ps ); ?></text>
										</svg>
									</button>
									<?php else : ?>
									<span class="twt-aeo-muted">—</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $intent ) : ?>
										<span class="twt-aeo-intent-pill"><?php echo esc_html( $intent['label'] ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-muted"><?php esc_html_e( '—', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $confidence ) : ?>
										<span class="twt-aeo-confidence twt-aeo-confidence--<?php echo esc_attr( $conf_class ); ?>">
											<?php echo esc_html( ucfirst( $confidence ) ); ?>
										</span>
									<?php else : ?>
										<span class="twt-aeo-muted">—</span>
									<?php endif; ?>
								</td>

								<td>
									<?php
									$saved_present = TWTAEO_Custom_Schema_Writer::get_all( $post->ID );
									// Saved schemas whose type is neither detected-present nor
									// intent-recommended would otherwise be invisible here — and an
									// invisible saved schema is an undeletable one (there is no other
									// UI that reaches it). List them with their ✏ so they can always
									// be edited or removed.
									$extra_saved = array_diff( array_keys( $saved_present ), $present, $missing );
									?>
									<?php if ( ! empty( $present ) || ! empty( $extra_saved ) ) : ?>
										<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">
											<?php foreach ( $present as $type ) : ?>
												<span class="twt-aeo-missing-item">
													<span class="twt-aeo-tag twt-aeo-tag--present"><?php echo esc_html( $type ); ?></span>
													<?php
													// Generator-owned nodes (WooCommerce Product/ProductGroup) are
													// richer than the modal form — hand-editing would clobber
													// offers/shipping/identifiers, so no ✏ for them here.
													$generator_owned = 'product' === $post->post_type
														&& in_array( $type, array( 'Product', 'ProductGroup' ), true );
													if ( isset( $saved_present[ $type ] ) && ! $generator_owned ) :
													?>
													<button type="button"
														class="twt-aeo-schema-create-btn"
														data-post-id="<?php echo esc_attr( $post->ID ); ?>"
														data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
														data-schema-type="<?php echo esc_attr( $type ); ?>"
														data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
														data-saved="1"
														title="<?php esc_attr_e( 'Edit schema', 'twt-aeo-ultimate' ); ?>">✏</button>
													<?php endif; ?>
												</span>
											<?php endforeach; ?>
											<?php foreach ( $extra_saved as $type ) : ?>
												<span class="twt-aeo-missing-item">
													<span class="twt-aeo-tag twt-aeo-tag--present twt-aeo-tag--saved"
														title="<?php esc_attr_e( 'Saved schema — publishing, but not one of this page\'s recommended types', 'twt-aeo-ultimate' ); ?>"><?php echo esc_html( $type ); ?></span>
													<button type="button"
														class="twt-aeo-schema-create-btn"
														data-post-id="<?php echo esc_attr( $post->ID ); ?>"
														data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
														data-schema-type="<?php echo esc_attr( $type ); ?>"
														data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
														data-saved="1"
														title="<?php esc_attr_e( 'Edit schema', 'twt-aeo-ultimate' ); ?>">✏</button>
												</span>
											<?php endforeach; ?>
										</div>
									<?php elseif ( $scan ) : ?>
										<span class="twt-aeo-muted"><?php esc_html_e( 'None detected', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-muted">—</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( ! empty( $missing ) ) : ?>
										<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">
											<?php
											$saved_schemas = TWTAEO_Custom_Schema_Writer::get_all( $post->ID );
											foreach ( $missing as $type ) :
												$is_saved = isset( $saved_schemas[ $type ] );
											?>
											<span class="twt-aeo-missing-item">
												<span class="twt-aeo-tag twt-aeo-tag--missing<?php echo $is_saved ? ' twt-aeo-tag--saved' : ''; ?>"><?php echo esc_html( $type ); ?><?php if ( $is_saved ) echo ' ✓'; ?></span>
												<button type="button"
													class="twt-aeo-schema-create-btn"
													data-post-id="<?php echo esc_attr( $post->ID ); ?>"
													data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
													data-schema-type="<?php echo esc_attr( $type ); ?>"
													data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
													title="<?php echo $is_saved ? esc_attr__( 'Edit schema', 'twt-aeo-ultimate' ) : esc_attr__( 'Create schema', 'twt-aeo-ultimate' ); ?>">
													<?php echo $is_saved ? '✏' : '+'; ?>
												</button>
											</span>
											<?php endforeach; ?>
										</div>
									<?php elseif ( $scan ) : ?>
										<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'All present', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-muted">—</span>
									<?php endif; ?>
								</td>

								<td class="twt-aeo-muted">
									<?php if ( $scan ) : ?>
										<?php echo esc_html( human_time_diff( strtotime( $scan['scanned_at'] ), current_time( 'timestamp' ) ) ); ?> ago
									<?php else : ?>
										<span class="twt-aeo-badge twt-aeo-badge--warn"><?php esc_html_e( 'Not scanned', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
								</td>

								<td>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link">
										<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
									</a>
									·
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link">
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</a>
									·
									<a href="#" class="twt-aeo-link twt-aeo-scan-now"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'twtaeo_scan_nonce' ) ); ?>">
										<?php esc_html_e( 'Scan Now', 'twt-aeo-ultimate' ); ?>
									</a>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
				<div class="twt-aeo-pagination">
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) ) );
					?>
				</div>
				<?php endif; ?>

				<?php endif; ?>

				<?php if ( $summary['no_scan'] > 0 ) : ?>
				<p class="twt-aeo-hint">
					<span class="dashicons dashicons-info-outline"></span>
					<?php
					printf(
						// translators: %d: number of unscanned pages.
						esc_html__( '%d page(s) have not been scanned yet. Use "Scan All Pages" above or open and save each page.', 'twt-aeo-ultimate' ),
						absint( $summary['no_scan'] )
					);
					?>
				</p>
				<?php endif; ?>
			</section>

			<?php if ( ! empty( $aeo_gaps ) && $mode === 'professional' ) : ?>
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
					<?php esc_html_e( 'AEO Gaps in Your SEO Plugin', 'twt-aeo-ultimate' ); ?>
				</h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'These are things your SEO plugin does not handle — areas where TWT AEO Ultimate adds value.', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<?php foreach ( $aeo_gaps as $gap ) : ?>
							<li class="twt-aeo-checklist__item twt-aeo-checklist__item--warn">
								<span class="dashicons dashicons-info-outline"></span>
								<?php echo esc_html( $gap ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</section>
			<?php elseif ( ! empty( $aeo_gaps ) ) : ?>
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
					<?php esc_html_e( 'What Your SEO Plugin Doesn\'t Cover', 'twt-aeo-ultimate' ); ?>
				</h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php
						printf(
							// translators: %s: installed SEO plugin name (e.g. 'Yoast SEO').
							esc_html__( '%s is installed but leaves some AEO gaps. TWT AEO Ultimate fills these in. Switch to Professional Mode in Settings to see full details.', 'twt-aeo-ultimate' ),
							esc_html( $seo_plugin )
						);
						?>
					</p>
					<p style="margin:0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) ); ?>" class="twt-aeo-link">
							<?php esc_html_e( '→ Go to Settings', 'twt-aeo-ultimate' ); ?>
						</a>
					</p>
				</div>
			</section>
			<?php endif; ?>

		</div>

		<!-- Schema dialog (jQuery UI) -->
		<div id="twt-aeo-schema-dialog" style="display:none;">
			<p class="twt-aeo-dialog-desc"></p>
			<div id="twt-aeo-dialog-form"></div>
			<p id="twt-aeo-dialog-msg" style="display:none;padding:8px 12px;border-radius:4px;margin-top:8px;"></p>
		</div>

		<!-- AEO Score breakdown dialog (jQuery UI) -->
		<div id="twt-aeo-score-dialog" style="display:none;">
			<div id="twt-aeo-score-dialog-body"></div>
		</div>

		<?php
		ob_start();
		?>
		var twtAeoSchemas = <?php echo wp_json_encode( array(
			'nonce'     => wp_create_nonce( TWTAEO_Custom_Schema_Writer::NONCE_ACTION ),
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'siteName'  => get_bloginfo( 'name' ),
			'siteUrl'   => home_url(),
			'aiEnabled' => class_exists( 'TWTAEO_AI_Description' )
				&& TWTAEO_AI_Description::is_enabled()
				&& class_exists( 'TWTAEO_Key_Resolver' )
				&& '' !== (string) TWTAEO_Key_Resolver::get( TWTAEO_AI_Description::get_provider() ),
		) ); ?>;
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
		ob_start();
		?>
		jQuery(document).ready(function($) {

			// ── Field configuration per schema type ─────────────────────────
			var schemaConfig = {

				Person: {
					desc: 'Add a Person schema to identify an author or individual associated with this page.',
					fields: [
						{ id:'name',     label:'Full Name',    type:'text',     required:true,  ph:'Jane Smith' },
						{ id:'jobTitle', label:'Job Title',    type:'text',     ph:'Web Developer' },
						{ id:'email',    label:'Email',        type:'text',     ph:'jane@example.com' },
						{ id:'url',      label:'Profile URL',  type:'text',     ph:'https://yoursite.com/about' },
						{ id:'linkedin', label:'LinkedIn URL', type:'text',     ph:'https://linkedin.com/in/yourname' },
						{ id:'twitter',  label:'Twitter / X',  type:'text',     ph:'https://twitter.com/yourhandle' },
					],
					toJson: function(v, ctx) {
						var s = { '@context':'https://schema.org', '@type':'Person', name:v.name, url:v.url || ctx.siteUrl };
						if (v.jobTitle) s.jobTitle = v.jobTitle;
						if (v.email)    s.email    = v.email;
						var sa = [];
						if (v.linkedin) sa.push(v.linkedin);
						if (v.twitter)  sa.push(v.twitter);
						if (sa.length)  s.sameAs = sa;
						return s;
					},
					fromJson: function(j) {
						var v = { name:j.name||'', jobTitle:j.jobTitle||'', email:j.email||'', url:j.url||'' };
						(j.sameAs||[]).forEach(function(u){
							if (u.indexOf('linkedin')>-1)  v.linkedin=u;
							else if (u.indexOf('twitter')>-1||u.indexOf('x.com')>-1) v.twitter=u;
						});
						return v;
					}
				},

				Article: {
					desc: 'Mark this page as a published article so AI engines know the author, date, and publisher.',
					fields: [
						{ id:'headline',      label:'Headline',       type:'text',     required:true, ph:'Your article title' },
						{ id:'author',        label:'Author Name',    type:'text',     required:true, ph:'Jane Smith' },
						{ id:'description',   label:'Description',    type:'textarea', ph:'A short summary of the article…' },
						{ id:'datePublished', label:'Date Published', type:'date' },
						{ id:'dateModified',  label:'Date Modified',  type:'date' },
						{ id:'publisher',     label:'Publisher Name', type:'text',     ph:'My Website' },
					],
					toJson: function(v, ctx) {
						var s = {
							'@context':'https://schema.org', '@type':'Article',
							headline: v.headline,
							author:   { '@type':'Person', name:v.author||'Unknown' },
							datePublished: v.datePublished || '',
							dateModified:  v.dateModified  || v.datePublished || '',
							url: ctx.postUrl,
							publisher: { '@type':'Organization', name:v.publisher||ctx.siteName, logo:{'@type':'ImageObject', url:ctx.siteUrl+'/logo.png'} }
						};
						if (v.description) s.description = v.description;
						return s;
					},
					fromJson: function(j) {
						return {
							headline:      j.headline||'',
							author:        (j.author&&j.author.name)||'',
							description:   j.description||'',
							datePublished: j.datePublished||'',
							dateModified:  j.dateModified||'',
							publisher:     (j.publisher&&j.publisher.name)||''
						};
					}
				},

				Organization: {
					desc: 'Describe your organization — used by AI to understand who owns this site.',
					fields: [
						{ id:'name',        label:'Organization Name', type:'text',     required:true, ph:'Acme Corp' },
						{ id:'description', label:'Description',       type:'textarea', ph:'What your organization does…' },
						{ id:'telephone',   label:'Phone',             type:'text',     ph:'+1-813-555-0100' },
						{ id:'street',      label:'Street Address',    type:'text',     ph:'123 Main St' },
						{ id:'city',        label:'City',              type:'text',     ph:'Tampa' },
						{ id:'state',       label:'State / Region',    type:'text',     ph:'FL' },
						{ id:'zip',         label:'ZIP / Postal',      type:'text',     ph:'33601' },
						{ id:'country',     label:'Country',           type:'text',     ph:'US' },
						{ id:'facebook',    label:'Facebook URL',      type:'text',     ph:'https://facebook.com/yourpage' },
						{ id:'linkedin',    label:'LinkedIn URL',      type:'text',     ph:'https://linkedin.com/company/yourco' },
					],
					toJson: function(v, ctx) {
						var s = { '@context':'https://schema.org', '@type':'Organization', name:v.name, url:ctx.siteUrl };
						if (v.description) s.description = v.description;
						if (v.telephone)   s.telephone   = v.telephone;
						if (v.street||v.city) {
							s.address = { '@type':'PostalAddress' };
							if (v.street)  s.address.streetAddress   = v.street;
							if (v.city)    s.address.addressLocality  = v.city;
							if (v.state)   s.address.addressRegion    = v.state;
							if (v.zip)     s.address.postalCode       = v.zip;
							if (v.country) s.address.addressCountry   = v.country;
						}
						var sa = [];
						if (v.facebook) sa.push(v.facebook);
						if (v.linkedin) sa.push(v.linkedin);
						if (sa.length)  s.sameAs = sa;
						return s;
					},
					fromJson: function(j) {
						var v = { name:j.name||'', description:j.description||'', telephone:j.telephone||'' };
						var a = j.address||{};
						v.street  = a.streetAddress||''; v.city  = a.addressLocality||'';
						v.state   = a.addressRegion||'';  v.zip   = a.postalCode||'';
						v.country = a.addressCountry||'';
						(j.sameAs||[]).forEach(function(u){
							if (u.indexOf('facebook')>-1) v.facebook=u;
							else if (u.indexOf('linkedin')>-1) v.linkedin=u;
						});
						return v;
					}
				},

				LocalBusiness: {
					desc: 'Identify this page as a local business location — helps AI show your hours, address, and contact info.',
					fields: [
						{ id:'name',         label:'Business Name',   type:'text',     required:true, ph:'Acme Plumbing' },
						{ id:'description',  label:'Description',     type:'textarea', ph:'What your business does…' },
						{ id:'telephone',    label:'Phone',           type:'text',     ph:'+1-813-555-0100' },
						{ id:'priceRange',   label:'Price Range',     type:'text',     ph:'$$ or $10–$50' },
						{ id:'openingHours', label:'Opening Hours',   type:'text',     ph:'Mo-Fr 09:00-17:00' },
						{ id:'street',       label:'Street Address',  type:'text',     ph:'123 Main St' },
						{ id:'city',         label:'City',            type:'text',     ph:'Tampa' },
						{ id:'state',        label:'State / Region',  type:'text',     ph:'FL' },
						{ id:'zip',          label:'ZIP / Postal',    type:'text',     ph:'33601' },
						{ id:'country',      label:'Country',         type:'text',     ph:'US' },
					],
					toJson: function(v, ctx) {
						var s = { '@context':'https://schema.org', '@type':'LocalBusiness', name:v.name, url:ctx.siteUrl };
						if (v.description)  s.description  = v.description;
						if (v.telephone)    s.telephone    = v.telephone;
						if (v.priceRange)   s.priceRange   = v.priceRange;
						if (v.openingHours) s.openingHours = v.openingHours;
						if (v.street||v.city) {
							s.address = { '@type':'PostalAddress' };
							if (v.street)  s.address.streetAddress   = v.street;
							if (v.city)    s.address.addressLocality  = v.city;
							if (v.state)   s.address.addressRegion    = v.state;
							if (v.zip)     s.address.postalCode       = v.zip;
							if (v.country) s.address.addressCountry   = v.country;
						}
						return s;
					},
					fromJson: function(j) {
						var v = { name:j.name||'', description:j.description||'', telephone:j.telephone||'', priceRange:j.priceRange||'', openingHours:j.openingHours||'' };
						var a = j.address||{};
						v.street=a.streetAddress||''; v.city=a.addressLocality||'';
						v.state=a.addressRegion||'';  v.zip=a.postalCode||''; v.country=a.addressCountry||'';
						return v;
					}
				},

				Service: {
					desc: 'Describe a service offered on this page — name, type, area served, and provider.',
					fields: [
						{ id:'name',         label:'Service Name',   type:'text',     required:true, ph:'CNC Spindle Repair' },
						{ id:'description',  label:'Description',    type:'textarea', ph:'Describe what this service includes…' },
						{ id:'serviceType',  label:'Service Type',   type:'text',     ph:'Repair, Installation, Consulting' },
						{ id:'areaServed',   label:'Area Served',    type:'text',     ph:'Florida, United States' },
						{ id:'provider',     label:'Business Name',  type:'text',     ph:'Acme Corp' },
						{ id:'telephone',    label:'Phone',          type:'text',     ph:'+1-813-555-0100' },
					],
					toJson: function(v, ctx) {
						var s = {
							'@context':'https://schema.org', '@type':'Service',
							name: v.name,
							provider: { '@type':'Organization', name:v.provider||ctx.siteName }
						};
						if (v.description) s.description = v.description;
						if (v.serviceType) s.serviceType = v.serviceType;
						if (v.areaServed)  s.areaServed  = v.areaServed;
						if (v.telephone)   s.provider.telephone = v.telephone;
						return s;
					},
					fromJson: function(j) {
						return {
							name:        j.name||'',
							description: j.description||'',
							serviceType: j.serviceType||'',
							areaServed:  j.areaServed||'',
							provider:    (j.provider&&j.provider.name)||'',
							telephone:   (j.provider&&j.provider.telephone)||''
						};
					}
				},

				Product: {
					desc: 'Mark this page as a product listing with pricing and availability for AI and rich results.',
					fields: [
						{ id:'name',         label:'Product Name',   type:'text',     required:true, ph:'Widget Pro 3000' },
						{ id:'description',  label:'Description',    type:'textarea', ph:'What does this product do?' },
						{ id:'brand',        label:'Brand',          type:'text',     ph:'Acme' },
						{ id:'price',        label:'Price',          type:'text',     ph:'49.99' },
						{ id:'currency',     label:'Currency',       type:'text',     ph:'USD' },
						{ id:'availability', label:'Availability',   type:'select',   options:['InStock','OutOfStock','PreOrder','Discontinued'] },
					],
					toJson: function(v, ctx) {
						var s = {
							'@context':'https://schema.org', '@type':'Product',
							name: v.name,
							offers: { '@type':'Offer', priceCurrency:v.currency||'USD', availability:'https://schema.org/'+(v.availability||'InStock') }
						};
						if (v.description) s.description  = v.description;
						if (v.brand)       s.brand        = { '@type':'Brand', name:v.brand };
						if (v.price)       s.offers.price = v.price;
						return s;
					},
					fromJson: function(j) {
						var o = j.offers||{};
						var av = (o.availability||'').replace('https://schema.org/','');
						return {
							name:         j.name||'',
							description:  j.description||'',
							brand:        (j.brand&&j.brand.name)||'',
							price:        o.price||'',
							currency:     o.priceCurrency||'USD',
							availability: av||'InStock'
						};
					}
				},

				WebPage: {
					desc: 'Explicitly mark this page as a WebPage with a title and description for AI crawlers.',
					fields: [
						{ id:'name',        label:'Page Title',   type:'text',     required:true, ph:'About Us' },
						{ id:'description', label:'Description',  type:'textarea', ph:'What this page is about…' },
						{ id:'url',         label:'URL',          type:'text',     ph:'https://yoursite.com/about' },
					],
					toJson: function(v, ctx) {
						return { '@context':'https://schema.org', '@type':'WebPage', name:v.name, description:v.description||'', url:v.url||ctx.postUrl };
					},
					fromJson: function(j) {
						return { name:j.name||'', description:j.description||'', url:j.url||'' };
					}
				},

				WebSite: {
					desc: 'Identify the site itself — its name, URL, and tagline — so AI engines anchor every page to one site entity.',
					fields: [
						{ id:'name',          label:'Site Name',      type:'text',     required:true, ph:'My Website' },
						{ id:'alternateName', label:'Alternate Name', type:'text',     ph:'Short or abbreviated name' },
						{ id:'description',   label:'Description',    type:'textarea', ph:'What this site is about…' },
						{ id:'url',           label:'Site URL',       type:'text',     ph:'https://yoursite.com' },
					],
					toJson: function(v, ctx) {
						var s = { '@context':'https://schema.org', '@type':'WebSite', name:v.name, url:v.url||ctx.siteUrl };
						if (v.alternateName) s.alternateName = v.alternateName;
						if (v.description)   s.description   = v.description;
						return s;
					},
					fromJson: function(j) {
						return { name:j.name||'', alternateName:j.alternateName||'', description:j.description||'', url:j.url||'' };
					}
				},

				PostalAddress: {
					desc: 'Publish the address for this location so AI engines can place it on the map.',
					fields: [
						{ id:'street',  label:'Street Address', type:'text', ph:'123 Main St' },
						{ id:'city',    label:'City',           type:'text', required:true, ph:'Tampa' },
						{ id:'state',   label:'State / Region', type:'text', ph:'FL' },
						{ id:'zip',     label:'ZIP / Postal',   type:'text', ph:'33601' },
						{ id:'country', label:'Country',        type:'text', ph:'US' },
					],
					toJson: function(v) {
						var s = { '@context':'https://schema.org', '@type':'PostalAddress', addressLocality:v.city };
						if (v.street)  s.streetAddress  = v.street;
						if (v.state)   s.addressRegion  = v.state;
						if (v.zip)     s.postalCode     = v.zip;
						if (v.country) s.addressCountry = v.country;
						return s;
					},
					fromJson: function(j) {
						return { street:j.streetAddress||'', city:j.addressLocality||'', state:j.addressRegion||'', zip:j.postalCode||'', country:j.addressCountry||'' };
					}
				},

				Review: {
					desc: 'Publish a review of the thing this page covers — rating, reviewer, and verdict, quotable by answer engines.',
					fields: [
						{ id:'item',   label:'What is reviewed',  type:'text',     required:true, ph:'Product or service name' },
						{ id:'rating', label:'Rating (out of 5)', type:'select',   options:['5','4.5','4','3.5','3','2.5','2','1.5','1'] },
						{ id:'author', label:'Reviewer Name',     type:'text',     required:true, ph:'Jane Smith' },
						{ id:'body',   label:'Review Text',       type:'textarea', ph:'The verdict in a paragraph or two…' },
						{ id:'date',   label:'Review Date',       type:'date' },
					],
					toJson: function(v, ctx) {
						var s = {
							'@context':'https://schema.org', '@type':'Review',
							itemReviewed: { '@type':'Thing', name:v.item },
							reviewRating: { '@type':'Rating', ratingValue:v.rating||'5', bestRating:'5' },
							author: { '@type':'Person', name:v.author }
						};
						if (v.body) s.reviewBody     = v.body;
						if (v.date) s.datePublished  = v.date;
						return s;
					},
					fromJson: function(j) {
						return {
							item:   (j.itemReviewed&&j.itemReviewed.name)||'',
							rating: (j.reviewRating&&String(j.reviewRating.ratingValue))||'5',
							author: (j.author&&j.author.name)||'',
							body:   j.reviewBody||'',
							date:   j.datePublished||''
						};
					}
				},

				HowTo: {
					desc: 'Turn this page\'s instructions into HowTo schema — steps an AI assistant can walk a user through.',
					fields: [
						{ id:'name',        label:'What it teaches', type:'text',     required:true, ph:'How to descale a coffee machine' },
						{ id:'description', label:'Description',     type:'textarea', ph:'One-line summary of the task…' },
						{ id:'steps',       label:'Steps (one per line)', type:'textarea', required:true, ph:'Unplug the machine\nEmpty the tank\nFill with descaler…' },
					],
					toJson: function(v, ctx) {
						var steps = (v.steps||'').split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
						var s = {
							'@context':'https://schema.org', '@type':'HowTo',
							name: v.name,
							step: steps.map(function(t, i){ return { '@type':'HowToStep', position:i+1, text:t }; })
						};
						if (v.description) s.description = v.description;
						return s;
					},
					fromJson: function(j) {
						return {
							name:        j.name||'',
							description: j.description||'',
							steps:       (j.step||[]).map(function(st){ return st.text||''; }).join('\n')
						};
					}
				},

				Event: {
					desc: 'Describe an event on this page — date, location, and organizer so AI can surface it.',
					fields: [
						{ id:'name',        label:'Event Name',     type:'text',     required:true, ph:'Annual Conference 2025' },
						{ id:'description', label:'Description',    type:'textarea', ph:'What is this event about?' },
						{ id:'startDate',   label:'Start Date',     type:'date',     required:true },
						{ id:'endDate',     label:'End Date',       type:'date' },
						{ id:'venue',       label:'Venue Name',     type:'text',     ph:'Tampa Convention Center' },
						{ id:'street',      label:'Street Address', type:'text',     ph:'333 S Franklin St' },
						{ id:'city',        label:'City',           type:'text',     ph:'Tampa' },
						{ id:'state',       label:'State / Region', type:'text',     ph:'FL' },
						{ id:'country',     label:'Country',        type:'text',     ph:'US' },
						{ id:'organizer',   label:'Organizer Name', type:'text',     ph:'Acme Corp' },
					],
					toJson: function(v, ctx) {
						var s = {
							'@context':'https://schema.org', '@type':'Event',
							name: v.name, startDate: v.startDate,
							location: { '@type':'Place', name:v.venue||v.city||'TBD', address:{ '@type':'PostalAddress' } }
						};
						if (v.description) s.description = v.description;
						if (v.endDate)     s.endDate     = v.endDate;
						if (v.street)  s.location.address.streetAddress   = v.street;
						if (v.city)    s.location.address.addressLocality  = v.city;
						if (v.state)   s.location.address.addressRegion    = v.state;
						if (v.country) s.location.address.addressCountry   = v.country;
						if (v.organizer) s.organizer = { '@type':'Organization', name:v.organizer };
						return s;
					},
					fromJson: function(j) {
						var loc = j.location||{}, a = loc.address||{};
						return {
							name:        j.name||'',
							description: j.description||'',
							startDate:   j.startDate||'',
							endDate:     j.endDate||'',
							venue:       loc.name||'',
							street:      a.streetAddress||'',
							city:        a.addressLocality||'',
							state:       a.addressRegion||'',
							country:     a.addressCountry||'',
							organizer:   (j.organizer&&j.organizer.name)||''
						};
					}
				},

				FAQPage: {
					desc: 'Add FAQ schema so AI engines can extract questions and answers directly from this page.',
					fields: [], // special: repeatable Q&A pairs
					isFaq: true,
					toJson: function(v) {
						var items = (v.pairs||[]).filter(function(p){ return p.q && p.a; }).map(function(p){
							return { '@type':'Question', name:p.q, acceptedAnswer:{ '@type':'Answer', text:p.a } };
						});
						return { '@context':'https://schema.org', '@type':'FAQPage', mainEntity:items };
					},
					fromJson: function(j) {
						var pairs = (j.mainEntity||[]).map(function(q){
							return { q:q.name||'', a:(q.acceptedAnswer&&q.acceptedAnswer.text)||'' };
						});
						if (!pairs.length) pairs = [{ q:'', a:'' }, { q:'', a:'' }];
						return { pairs:pairs };
					}
				},

				BreadcrumbList: {
					desc: 'Define the breadcrumb path for this page to help AI understand site structure.',
					fields: [], // special: repeatable crumbs
					isBreadcrumb: true,
					toJson: function(v) {
						var items = (v.crumbs||[]).filter(function(c){ return c.name; }).map(function(c, i){
							return { '@type':'ListItem', position:i+1, name:c.name, item:c.url||'' };
						});
						return { '@context':'https://schema.org', '@type':'BreadcrumbList', itemListElement:items };
					},
					fromJson: function(j) {
						var crumbs = (j.itemListElement||[]).map(function(c){
							return { name:c.name||'', url:c.item||'' };
						});
						if (!crumbs.length) crumbs = [{ name:'Home', url:twtAeoSchemas.siteUrl }, { name:'', url:'' }];
						return { crumbs:crumbs };
					}
				}
			};

			// ── Dialog setup ────────────────────────────────────────────────
			var modalPostId    = null;
			var modalType      = null;
			var modalHasSchema = false;

			var $dlg = $('#twt-aeo-schema-dialog');

			$dlg.dialog({
				autoOpen:  false,
				modal:     true,
				width:     720,
				minWidth:  560,
				maxHeight: Math.floor( window.innerHeight * 0.88 ),
				resizable: false,
				closeText: 'Close',
				buttons: [
					{
						id:    'twt-aeo-dlg-save',
						text:  'Save Schema',
						'class': 'button button-primary',
						click: saveSchema
					},
					{
						id:    'twt-aeo-dlg-delete',
						text:  'Delete',
						'class': 'button button-link-delete',
						style: 'float:left',
						click: deleteSchema
					},
					{
						text:  'Cancel',
						'class': 'button',
						click: function(){ $dlg.dialog('close'); }
					}
				],
				open: function() {
					// Hide Delete until we know a schema exists.
					$('#twt-aeo-dlg-delete').hide();
				},
				close: function() {
					modalPostId = null; modalType = null; modalHasSchema = false;
					$('#twt-aeo-dialog-msg').hide();
				}
			});

			// ── Build form ──────────────────────────────────────────────────
			function buildForm( type, vals ) {
				var cfg = schemaConfig[type];
				if (!cfg) return;
				var $form = $('#twt-aeo-dialog-form').empty();
				$('.twt-aeo-dialog-desc').text(cfg.desc);

				if (cfg.isFaq) {
					buildFaqForm($form, vals);
					return;
				}
				if (cfg.isBreadcrumb) {
					buildBreadcrumbForm($form, vals);
					return;
				}

				var rows = cfg.fields.map(function(f) {
					var req   = f.required ? ' <span style="color:red">*</span>' : '';
					var input = '';
					if (f.type === 'textarea') {
						input = '<textarea id="twt-f-' + f.id + '" class="large-text" rows="3" placeholder="' + esc(f.ph||'') + '">' + esc(vals[f.id]||'') + '</textarea>';
						if (twtAeoSchemas.aiEnabled) {
							input += '<p style="margin:4px 0 0;font-size:11px;"><a href="#" class="twt-aeo-link twt-aeo-ai-fill" data-target="twt-f-' + f.id + '">✨ Generate with AI from page content (1 API call)</a></p>';
						}
					} else if (f.type === 'select') {
						var opts = (f.options||[]).map(function(o){
							return '<option value="' + esc(o) + '"' + (vals[f.id]===o?' selected':'') + '>' + esc(o) + '</option>';
						}).join('');
						input = '<select id="twt-f-' + f.id + '" class="regular-text">' + opts + '</select>';
					} else {
						input = '<input type="' + f.type + '" id="twt-f-' + f.id + '" class="regular-text" placeholder="' + esc(f.ph||'') + '" value="' + esc(vals[f.id]||'') + '" />';
					}
					return '<tr><th scope="row"><label for="twt-f-' + f.id + '">' + esc(f.label) + req + '</label></th><td>' + input + '</td></tr>';
				}).join('');

				$form.html('<table class="form-table" style="margin-top:0"><tbody>' + rows + '</tbody></table>');
			}

			function buildFaqForm($form, vals) {
				var pairs = (vals&&vals.pairs)||[{ q:'', a:'' }, { q:'', a:'' }];
				var html  = '<div id="twt-faq-pairs">';
				pairs.forEach(function(p, i){ html += faqPairHtml(i, p.q, p.a); });
				html += '</div>';
				html += '<p style="margin-top:8px"><button type="button" class="button" id="twt-faq-add">+ Add Q&amp;A Pair</button></p>';
				$form.html(html);

				$form.on('click', '#twt-faq-add', function(){
					var i = $('#twt-faq-pairs .twt-faq-pair').length;
					$('#twt-faq-pairs').append(faqPairHtml(i,'',''));
				});
				$form.on('click', '.twt-faq-remove', function(){
					if ($('#twt-faq-pairs .twt-faq-pair').length > 1) $(this).closest('.twt-faq-pair').remove();
				});
			}

			function faqPairHtml(i, q, a) {
				return '<div class="twt-faq-pair" style="border:1px solid #ddd;border-radius:4px;padding:10px;margin-bottom:8px;position:relative">'
					+ '<span class="twt-faq-num" style="font-weight:600;font-size:12px;color:#646970">Q&amp;A ' + (i+1) + '</span>'
					+ '<button type="button" class="button button-small twt-faq-remove" style="float:right;padding:0 6px">✕</button>'
					+ '<table class="form-table" style="margin-top:6px"><tbody>'
					+ '<tr><th><label>Question</label></th><td><input type="text" class="twt-faq-q large-text" placeholder="What is…?" value="' + esc(q) + '" /></td></tr>'
					+ '<tr><th><label>Answer</label></th><td><textarea class="twt-faq-a large-text" rows="2" placeholder="The answer is…">' + esc(a) + '</textarea></td></tr>'
					+ '</tbody></table></div>';
			}

			function buildBreadcrumbForm($form, vals) {
				var crumbs = (vals&&vals.crumbs)||[{ name:'Home', url:twtAeoSchemas.siteUrl }, { name:'', url:'' }];
				var html   = '<p style="color:#646970;font-size:13px;margin-top:0">List each breadcrumb level in order.</p><div id="twt-crumb-list">';
				crumbs.forEach(function(c, i){ html += crumbHtml(i, c.name, c.url); });
				html += '</div>';
				html += '<p style="margin-top:8px"><button type="button" class="button" id="twt-crumb-add">+ Add Level</button></p>';
				$form.html(html);

				$form.on('click', '#twt-crumb-add', function(){
					var i = $('#twt-crumb-list .twt-crumb-row').length;
					$('#twt-crumb-list').append(crumbHtml(i,'',''));
				});
				$form.on('click', '.twt-crumb-remove', function(){
					if ($('#twt-crumb-list .twt-crumb-row').length > 1) $(this).closest('.twt-crumb-row').remove();
				});
			}

			function crumbHtml(i, name, url) {
				return '<div class="twt-crumb-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px">'
					+ '<span style="min-width:20px;color:#646970;font-size:12px">' + (i+1) + '</span>'
					+ '<input type="text" class="twt-crumb-name regular-text" placeholder="Label" value="' + esc(name) + '" style="flex:1" />'
					+ '<input type="text" class="twt-crumb-url regular-text" placeholder="URL" value="' + esc(url) + '" style="flex:2" />'
					+ '<button type="button" class="button button-small twt-crumb-remove" style="padding:0 6px">✕</button>'
					+ '</div>';
			}

			// ── Collect field values ────────────────────────────────────────
			function collectValues(type) {
				var cfg = schemaConfig[type];
				if (!cfg) return {};

				if (cfg.isFaq) {
					var pairs = [];
					$('#twt-faq-pairs .twt-faq-pair').each(function(){
						pairs.push({ q:$(this).find('.twt-faq-q').val().trim(), a:$(this).find('.twt-faq-a').val().trim() });
					});
					return { pairs:pairs };
				}

				if (cfg.isBreadcrumb) {
					var crumbs = [];
					$('#twt-crumb-list .twt-crumb-row').each(function(){
						crumbs.push({ name:$(this).find('.twt-crumb-name').val().trim(), url:$(this).find('.twt-crumb-url').val().trim() });
					});
					return { crumbs:crumbs };
				}

				var vals = {};
				cfg.fields.forEach(function(f){
					vals[f.id] = $('#twt-f-' + f.id).val().trim();
				});
				return vals;
			}

			// ── Validate required fields ────────────────────────────────────
			function validate(type, vals) {
				var cfg = schemaConfig[type];
				if (!cfg) return null;
				if (cfg.isFaq) {
					if (!(vals.pairs||[]).some(function(p){ return p.q && p.a; })) return 'At least one complete Q&A pair is required.';
					return null;
				}
				if (cfg.isBreadcrumb) {
					if (!(vals.crumbs||[]).some(function(c){ return c.name; })) return 'At least one breadcrumb with a label is required.';
					return null;
				}
				for (var i=0; i<cfg.fields.length; i++) {
					var f = cfg.fields[i];
					if (f.required && !vals[f.id]) return f.label + ' is required.';
				}
				return null;
			}

			// ── AI summary for a description field ──────────────────────────
			$(document).on('click', '.twt-aeo-ai-fill', function(e) {
				e.preventDefault();
				var $link = $(this);
				if ($link.data('busy')) { return; }
				var target = $link.data('target');
				var orig   = $link.text();
				$link.data('busy', 1).text('Generating…');
				$.post(twtAeoSchemas.ajaxurl, {
					action:  'twtaeo_ai_schema_summary',
					nonce:   twtAeoSchemas.nonce,
					post_id: modalPostId
				}, function(r) {
					$link.removeData('busy').text(orig);
					if (r.success && r.data && r.data.text) {
						$('#' + target).val(r.data.text);
					} else {
						alert((r.data && r.data.message) || r.data || 'Could not generate a summary.');
					}
				}).fail(function() {
					$link.removeData('busy').text(orig);
					alert('Request failed.');
				});
			});

			// ── Open dialog ─────────────────────────────────────────────────
			$(document).on('click', '.twt-aeo-schema-create-btn', function() {
				modalPostId = $(this).data('post-id');
				modalType   = $(this).data('schema-type');
				var title   = $(this).data('post-title');
				var cfg     = schemaConfig[modalType];

				// WooCommerce products get a generated Product node — the
				// detector derives offers, identifiers, shipping and returns
				// from the product itself, same as the WooCommerce tab. Pages
				// that only *read* as products (product intent, no Woo CPT)
				// fall through to the manual Product form below instead, and
				// an edit (✏) on an already-saved node always opens the editor.
				if ( modalType === 'Product' && $(this).data('post-type') === 'product' && !$(this).data('saved') ) {
					var $pbtn = $(this);
					if ( $pbtn.data('twt-generating') ) { return; }
					$pbtn.data('twt-generating', 1).text('…');
					$.post(twtAeoSchemas.ajaxurl, {
						action:  'twtaeo_dashboard_generate_product',
						nonce:   twtAeoSchemas.nonce,
						post_id: modalPostId,
						schema_type: modalType
					}, function(r) {
						$pbtn.removeData('twt-generating');
						if (r.success) {
							updateRowTag(modalPostId, modalType, true);
						} else {
							$pbtn.text('+');
							alert((r.data && r.data.message) || r.data || 'Could not generate schema.');
						}
					}).fail(function(){
						$pbtn.removeData('twt-generating').text('+');
						alert('Request failed.');
					});
					return;
				}

				if (!cfg) { alert('No form defined for schema type: ' + modalType); return; }

				// Show empty form + open dialog immediately.
				var emptyVals = cfg.isFaq ? { pairs:[{q:'',a:''},{q:'',a:''}] } : cfg.isBreadcrumb ? { crumbs:[{name:'Home',url:twtAeoSchemas.siteUrl},{name:'',url:''}] } : {};
				buildForm(modalType, emptyVals);
				$('#twt-aeo-dialog-msg').hide();
				$('#twt-aeo-dlg-delete').hide();
				$dlg.dialog('option', 'title', modalType + ' Schema — ' + title);
				$dlg.dialog('open');

				// Fetch existing schema + author/post prefill in parallel.
				var reqExisting = $.post(twtAeoSchemas.ajaxurl, {
					action:      'twtaeo_get_custom_schema',
					nonce:       twtAeoSchemas.nonce,
					post_id:     modalPostId,
					schema_type: modalType
				});
				var reqPrefill = $.post(twtAeoSchemas.ajaxurl, {
					action:  'twtaeo_get_schema_prefill',
					nonce:   twtAeoSchemas.nonce,
					post_id: modalPostId
				});

				$.when(reqExisting, reqPrefill).done(function(existRes, prefillRes) {
					var existing = existRes[0];
					var prefill  = prefillRes[0].success ? prefillRes[0].data : {};

					if (existing.success && existing.data.exists) {
						// Edit mode — populate from saved JSON.
						var json = {};
						try { json = JSON.parse(existing.data.json); } catch(e) {}
						buildForm(modalType, cfg.fromJson(json));
						modalHasSchema = true;
						$('#twt-aeo-dlg-delete').show();
					} else {
						// Create mode — pre-fill from author/post data.
						buildForm(modalType, buildPrefillVals(modalType, prefill));
					}
				});
			});

			// ── Build prefill values from post/author data ──────────────────
			function buildPrefillVals(type, d) {
				var a = d.author || {};
				switch (type) {
					case 'Person':
						return {
							name:     a.name     || '',
							jobTitle: a.job_title|| '',
							email:    a.email    || '',
							url:      a.url      || a.website || '',
							linkedin: a.linkedin || '',
							twitter:  a.twitter  || '',
						};
					case 'Article':
						return {
							headline:      d.post_title     || '',
							author:        a.name           || '',
							datePublished: d.post_date      || '',
							dateModified:  d.post_modified  || d.post_date || '',
							publisher:     d.site_name      || '',
							description:   '',
						};
					case 'Organization':
						return { name: d.site_name || '', description: d.site_description || '' };
					case 'LocalBusiness':
						return { name: d.site_name || '', description: d.site_description || '' };
					case 'Service':
						return { name: d.post_title || '', provider: d.site_name || '' };
					case 'Product':
						return { name: d.post_title || '' };
					case 'WebPage':
						return { name: d.post_title || '', url: d.post_url || '' };
					case 'WebSite':
						return { name: d.site_name || '', description: d.site_description || '', url: d.site_url || '' };
					case 'Review':
						return { item: d.post_title || '', rating: '5', date: d.post_modified || d.post_date || '' };
					case 'HowTo':
						return { name: d.post_title || '' };
					case 'Event':
						return { name: d.post_title || '', startDate: d.post_date || '', organizer: d.site_name || '' };
					case 'BreadcrumbList':
						return { crumbs: [{ name: 'Home', url: d.site_url || '' }, { name: d.post_title || '', url: d.post_url || '' }] };
					default:
						return {};
				}
			}

			// ── Save ────────────────────────────────────────────────────────
			function saveSchema() {
				var cfg = schemaConfig[modalType];
				if (!cfg || !modalPostId) return;

				var vals  = collectValues(modalType);
				var error = validate(modalType, vals);
				if (error) { showMsg(error, 'error'); return; }

				var ctx = { siteName:twtAeoSchemas.siteName, siteUrl:twtAeoSchemas.siteUrl, postUrl:'' };
				var json = JSON.stringify(cfg.toJson(vals, ctx), null, 2);

				$('#twt-aeo-dlg-save').prop('disabled', true).text('Saving…');
				$.post(twtAeoSchemas.ajaxurl, {
					action:      'twtaeo_save_custom_schema',
					nonce:       twtAeoSchemas.nonce,
					post_id:     modalPostId,
					schema_type: modalType,
					json:        json
				}, function(res) {
					$('#twt-aeo-dlg-save').prop('disabled', false).text('Save Schema');
					if (res.success) {
						updateRowTag(modalPostId, modalType, true);
						modalHasSchema = true;
						$dlg.dialog('close');
					} else {
						showMsg(res.data || 'Save failed.', 'error');
					}
				}).fail(function(){ $('#twt-aeo-dlg-save').prop('disabled', false).text('Save Schema'); showMsg('Request failed.', 'error'); });
			}

			// ── Delete ───────────────────────────────────────────────────────
			function deleteSchema() {
				if (!confirm('Remove the ' + modalType + ' schema from this page?')) return;
				$('#twt-aeo-dlg-delete').prop('disabled', true);
				$.post(twtAeoSchemas.ajaxurl, {
					action:      'twtaeo_delete_custom_schema',
					nonce:       twtAeoSchemas.nonce,
					post_id:     modalPostId,
					schema_type: modalType
				}, function(res) {
					$('#twt-aeo-dlg-delete').prop('disabled', false);
					if (res.success) {
						updateRowTag(modalPostId, modalType, false);
						$dlg.dialog('close');
					} else {
						showMsg(res.data || 'Delete failed.', 'error');
					}
				}).fail(function(){ $('#twt-aeo-dlg-delete').prop('disabled', false); showMsg('Request failed.', 'error'); });
			}

			// ── Helpers ─────────────────────────────────────────────────────
			function showMsg(msg, type) {
				var $m = $('#twt-aeo-dialog-msg');
				$m.text(msg)
				  .css('background', type==='success' ? '#ecfdf5' : '#fef2f2')
				  .css('color',      type==='success' ? '#065f46' : '#991b1b')
				  .css('border',     '1px solid ' + (type==='success' ? '#a7f3d0' : '#fecaca'))
				  .show();
			}

			function esc(s) {
				return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			function updateRowTag(postId, type, isSaved) {
				$('.twt-aeo-schema-create-btn[data-post-id="'+postId+'"][data-schema-type="'+type+'"]').each(function(){
					var $btn = $(this), $tag = $btn.siblings('.twt-aeo-tag');
					if (isSaved) {
						$tag.addClass('twt-aeo-tag--saved').text(type+' ✓');
						$btn.text('✏').attr('title','Edit schema');
					} else {
						$tag.removeClass('twt-aeo-tag--saved').text(type);
						$btn.text('+').attr('title','Create schema');
					}
				});
			}

			// ── Per-row Scan Now ────────────────────────────────────────────
			$(document).on('click', '.twt-aeo-scan-now', function(e) {
				e.preventDefault();
				var $link  = $(this);
				var postId = $link.data('post-id');
				var nonce  = $link.data('nonce');
				var $row   = $link.closest('tr');

				$link.text('Scanning…').css('opacity', '0.5').off('click');

				$.post(ajaxurl, {
					action:  'twtaeo_scan_page',
					post_id: postId,
					nonce:   nonce
				}, function(response) {
					if ( response.success ) {
						var d = response.data;

						$row.find('.twt-aeo-intent-pill').text( d.intent );

						var confClass = d.confidence === 'high' ? 'good' : ( d.confidence === 'medium' ? 'warn' : 'neutral' );
						$row.find('.twt-aeo-confidence')
							.removeClass('twt-aeo-confidence--good twt-aeo-confidence--warn twt-aeo-confidence--neutral')
							.addClass('twt-aeo-confidence--' + confClass)
							.text( d.confidence.charAt(0).toUpperCase() + d.confidence.slice(1) );

						var $missingCell  = $row.find('td').eq(5);
						var rowPostId     = $link.data('post-id');
						var rowTitle      = $row.find('.twt-aeo-page-row__title a').text().trim();
						var rowPostType   = $row.find('.twt-aeo-page-row__type').text().trim();

						if ( d.missing.length ) {
							var tags = d.missing.map(function(t){
								return '<span class="twt-aeo-missing-item">'
									+ '<span class="twt-aeo-tag twt-aeo-tag--missing">' + $('<span>').text(t).html() + '</span>'
									+ '<button type="button" class="twt-aeo-schema-create-btn"'
									+ ' data-post-id="' + rowPostId + '"'
									+ ' data-post-type="' + $('<span>').text(rowPostType).html() + '"'
									+ ' data-schema-type="' + $('<span>').text(t).html() + '"'
									+ ' data-post-title="' + $('<span>').text(rowTitle).html() + '"'
									+ ' title="Create schema">+</button>'
									+ '</span>';
							}).join('');
							$missingCell.html('<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">' + tags + '</div>');
							$row.removeClass('twt-aeo-page-row--ok twt-aeo-page-row--unscanned').addClass('twt-aeo-page-row--issues');
						} else {
							$missingCell.html('<span class="twt-aeo-status-ok">✓ All present</span>');
							$row.removeClass('twt-aeo-page-row--issues twt-aeo-page-row--unscanned').addClass('twt-aeo-page-row--ok');
						}

						var $presentCell = $row.find('td').eq(4);
						if ( d.present.length ) {
							var ptags = d.present.map(function(t){ return '<span class="twt-aeo-tag twt-aeo-tag--present">' + $('<span>').text(t).html() + '</span>'; }).join('');
							$presentCell.html('<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">' + ptags + '</div>');
						}

						$row.find('td').eq(6).text('Just now');

						if (typeof d.score === 'number' && window.twtAeoRenderScoreRing) {
							window.twtAeoRenderScoreRing($row.find('.twt-aeo-score-cell'), d.score, rowPostId, rowTitle);
						}
						$link.text('Scan Now').css('opacity', '1');
						$link.data('nonce', nonce);

					} else {
						$link.text('Error').css('opacity', '1');
					}
				});
			});

			// ── Scan All Pages ──────────────────────────────────────────────
			// ── Background scan polling ─────────────────────────────────────
			// Posts beyond the foreground cap are scanned server-side; this
			// watches the state and paints progress. Without it the handoff
			// was invisible — the bar stopped at the foreground block and the
			// rest of the site scanned with no feedback at all.
			function pollBgScan( nonce ) {
				$.post( ajaxurl, {
					action: 'twtaeo_bg_scan_status',
					nonce:  nonce
				}, function( res ) {
					if ( ! res.success ) {
						setTimeout(function(){ pollBgScan( nonce ); }, 10000);
						return;
					}
					var s = res.data;
					if ( s.status === 'done' ) {
						$('#twt-aeo-progress-fill').css('width', '100%');
						$('#twt-aeo-progress-label').text(
							'Background scan complete — ' + s.done + ' pages scanned'
							+ ( s.errors > 0 ? ' (' + s.errors + ' errors)' : '' ) + ' — reloading…'
						);
						setTimeout(function(){ location.reload(); }, 1500);
						return;
					}
					if ( s.running ) {
						var pct = s.total > 0 ? Math.round( (s.done / s.total) * 100 ) : 0;
						$('#twt-aeo-progress-fill').css('width', pct + '%');
						$('#twt-aeo-progress-label').text(
							'Background scan… ' + s.done + ' / ' + s.total + ' remaining pages (you can leave this page)'
						);
					}
					setTimeout(function(){ pollBgScan( nonce ); }, 5000);
				}).fail(function(){
					setTimeout(function(){ pollBgScan( nonce ); }, 10000);
				});
			}

			// Resume the progress display when a background scan was already
			// running when this page loaded.
			(function() {
				var $prog = $('#twt-aeo-scan-progress');
				if ( String($prog.data('bg-running')) !== '1' ) { return; }
				var done  = parseInt( $prog.data('bg-done'), 10 )  || 0;
				var total = parseInt( $prog.data('bg-total'), 10 ) || 0;
				var pct   = total > 0 ? Math.round( (done / total) * 100 ) : 0;
				$('#twt-aeo-scan-all-btn').prop('disabled', true).css('opacity', '0.6');
				$('#twt-aeo-progress-fill').css('width', pct + '%');
				$('#twt-aeo-progress-label').text('Background scan… ' + done + ' / ' + total + ' remaining pages');
				$prog.show();
				pollBgScan( $('#twt-aeo-scan-all-btn').data('nonce') );
			})();

			$('#twt-aeo-scan-all-btn').on('click', function() {
				var $btn      = $(this);
				var nonce     = $btn.data('nonce');
				var ids       = $btn.data('ids');
				var total     = ids.length;
				// The label and bar always speak in SITE totals — the foreground
				// pass covers only the newest block, but presenting its size as
				// the total made "…/ 200" read as the whole site on large ones.
				var remaining = parseInt( $btn.data('remaining'), 10 ) || 0;
				var siteTotal = total + remaining;

				if ( total === 0 ) { return; }

				$btn.prop('disabled', true).css('opacity', '0.6');
				$('#twt-aeo-scan-progress').show();

				var done   = 0;
				var errors = 0;

				function updateProgress() {
					var pct = Math.round( (done / siteTotal) * 100 );
					$('#twt-aeo-progress-fill').css('width', pct + '%');
					$('#twt-aeo-progress-label').text(
						'Scanning… ' + done + ' / ' + siteTotal
						+ ( remaining > 0 ? ' (newest ' + total + ' first, then ' + remaining + ' in the background)' : '' )
					);
				}

				function scanNext( index ) {
					if ( index >= total ) {
						// ⚠️ The foreground pass covers only the FOREGROUND_CAP most
						// recent posts. Everything beyond it is handed to the
						// server-side background scanner here — without this call
						// "Scan All Pages" on a large site scanned exactly 200 and
						// silently stopped.
						if ( remaining > 0 ) {
							$('#twt-aeo-progress-label').text(
								done + ' pages scanned — continuing ' + remaining + ' more in the background…'
							);
							$.post( ajaxurl, { action: 'twtaeo_bg_scan_start', nonce: nonce }, function( res ) {
								if ( res && res.success ) {
									pollBgScan( nonce );
								} else {
									setTimeout(function(){ location.reload(); }, 1500 );
								}
							}).fail(function(){
								setTimeout(function(){ location.reload(); }, 1500 );
							});
							return;
						}
						$('#twt-aeo-progress-label').text(
							done + ' pages scanned' + ( errors > 0 ? ' (' + errors + ' errors)' : '' ) + ' — reloading…'
						);
						setTimeout(function(){ location.reload(); }, 1200 );
						return;
					}

					$.post( ajaxurl, {
						action:  'twtaeo_scan_all',
						post_id: ids[ index ],
						nonce:   nonce
					}, function( response ) {
						if ( ! response.success ) { errors++; }
						done++;
						updateProgress();
						scanNext( index + 1 );
					}).fail(function(){
						errors++;
						done++;
						updateProgress();
						scanNext( index + 1 );
					});
				}

				updateProgress();
				scanNext(0);
			});

			// ── Data Handshake: Sync Now ─────────────────────────────────────
			$('#twt-aeo-handshake-btn').on('click', function() {
				var $btn    = $(this);
				var $status = $('#twt-aeo-handshake-status');
				var nonce   = $btn.data('nonce');

				if ( $btn.prop('disabled') ) { return; }
				$btn.prop('disabled', true);
				$status.css('opacity', 1).text('Syncing…');

				$.post( ajaxurl, {
					action: 'twtaeo_data_handshake',
					nonce:  nonce
				}, function( response ) {
					if ( response && response.success ) {
						var c = ( response.data && response.data.counts ) || {};
						$status.text(
							'Synced — ' +
							( c.baseline_pages || 0 ) + ' baseline, ' +
							( c.schema_deployments || 0 ) + ' schema, ' +
							( c.crawler_entries || 0 ) + ' crawler hits sent' +
							( c.crawler_purged ? ' (' + c.crawler_purged + ' purged)' : '' )
						);
					} else {
						$status.text( ( response && response.data && response.data.message ) || 'Sync failed.' );
					}
				}).fail(function(){
					$status.text('Sync failed — network error.');
				}).always(function(){
					$btn.prop('disabled', false);
				});
			});

		}); // end document.ready
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );

		// ── AI Meta Descriptions panel JS ───────────────────────────────────────
		ob_start();
		?>
		jQuery(function($){
			var $wrap = $('#twt-aeo-aidesc');
			if (!$wrap.length) { return; }
			var nonce   = $wrap.data('nonce');
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function saveSettings(){
				$.post(ajaxurl, {
					action:   'twtaeo_ai_desc_settings',
					nonce:    nonce,
					enabled:  $('#twt-aeo-aidesc-enabled').is(':checked') ? 1 : 0,
					provider: $('#twt-aeo-aidesc-provider').val()
				});
			}
			$('#twt-aeo-aidesc-enabled, #twt-aeo-aidesc-provider').on('change', saveSettings);

			var $btn    = $('#twt-aeo-aidesc-bulk');
			var $stop   = $('#twt-aeo-aidesc-stop');
			var $status = $('#twt-aeo-aidesc-status');
			var $note   = $('#twt-aeo-aidesc-note');
			var polling = null;

			// Per-provider model + speed/limitation note, shown under the picker.
			var providerInfo = <?php echo wp_json_encode( TWTAEO_AI_Description::provider_info() ); ?>;
			function updateNote(){
				var info = providerInfo[$('#twt-aeo-aidesc-provider').val()];
				if (info){ $note.text(info.model + ' — ' + info.note); }
				else { $note.text(''); }
			}
			$('#twt-aeo-aidesc-provider').on('change', updateNote);
			updateNote();

			function renderState(s){
				if (!s){ return; }
				if (s.running || s.status === 'running'){
					$btn.prop('disabled', true);
					$stop.show().prop('disabled', false);
					$status.css('color', '#646970').text(
						(s.done || 0) + ' / ' + (s.total || 0) + ' — '
						+ <?php echo wp_json_encode( __( 'generating in background (you can leave this page)…', 'twt-aeo-ultimate' ) ); ?>
					);
				} else if (s.status === 'error'){
					$btn.prop('disabled', false);
					$stop.hide();
					$status.html('<span style="color:#b32d2e;">'
						+ <?php echo wp_json_encode( __( 'Stopped: ', 'twt-aeo-ultimate' ) ); ?> + (s.last_error || 'error')
						+ '</span> (' + (s.created || 0) + ' ' + <?php echo wp_json_encode( __( 'created', 'twt-aeo-ultimate' ) ); ?> + ')');
				} else if (s.status === 'stopped'){
					$btn.prop('disabled', false);
					$stop.hide();
					$status.css('color', '#646970').text(
						(s.created || 0) + ' ' + <?php echo wp_json_encode( __( 'created — stopped.', 'twt-aeo-ultimate' ) ); ?>
					);
				} else if (s.status === 'done'){
					$btn.prop('disabled', false);
					$stop.hide();
					$status.css('color', '#1a6629').text((s.created || 0) + ' ' + <?php echo wp_json_encode( __( 'descriptions created.', 'twt-aeo-ultimate' ) ); ?>);
				} else {
					$btn.prop('disabled', false);
					$stop.hide();
				}
			}

			function poll(){
				$.post(ajaxurl, { action:'twtaeo_ai_desc_status', nonce:nonce }, function(res){
					if (!res.success){ return; }
					renderState(res.data);
					if (res.data.status !== 'running'){ clearInterval(polling); polling = null; }
				});
			}
			function startPolling(){
				if (polling){ return; }
				poll();
				polling = setInterval(poll, 4000);
			}

			$btn.on('click', function(){
				$btn.prop('disabled', true);
				$status.css('color', '#646970').text(<?php echo wp_json_encode( __( 'Finding posts…', 'twt-aeo-ultimate' ) ); ?>);
				$.post(ajaxurl, { action:'twtaeo_ai_desc_bulk', nonce:nonce }, function(res){
					if (!res.success){ $status.text(res.data || 'Error'); $btn.prop('disabled', false); return; }
					var count = res.data.count || 0;
					if (!count){
						$status.text(<?php echo wp_json_encode( __( 'All posts already have descriptions.', 'twt-aeo-ultimate' ) ); ?>);
						$btn.prop('disabled', false);
						return;
					}

					// Cost gate — confirm the number of billed API calls before running.
					var providerLabel = $('#twt-aeo-aidesc-provider option:selected').text();
					var confirmMsg = count + <?php echo wp_json_encode( ' ' . __( 'posts are missing a description.', 'twt-aeo-ultimate' ) ); ?>
						+ '\n\n' + <?php echo wp_json_encode( __( 'This will make one API call per post', 'twt-aeo-ultimate' ) ); ?>
						+ ' (' + count + ') ' + <?php echo wp_json_encode( __( 'using', 'twt-aeo-ultimate' ) ); ?> + ' ' + providerLabel + ', '
						+ <?php echo wp_json_encode( __( 'billed to your account. It runs in the background, so you can leave this page. Continue?', 'twt-aeo-ultimate' ) ); ?>;
					if (!window.confirm(confirmMsg)){
						$status.text(<?php echo wp_json_encode( __( 'Cancelled.', 'twt-aeo-ultimate' ) ); ?>);
						$btn.prop('disabled', false);
						return;
					}

					var fd = new FormData();
					fd.append('action',   'twtaeo_ai_desc_start');
					fd.append('nonce',    nonce);
					fd.append('provider', $('#twt-aeo-aidesc-provider').val());
					fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(r){
							if (r.success){ renderState(r.data); startPolling(); }
							else { $status.text(r.data || 'Error'); $btn.prop('disabled', false); }
						})
						.catch(function(){ $status.text(<?php echo wp_json_encode( __( 'Network error.', 'twt-aeo-ultimate' ) ); ?>); $btn.prop('disabled', false); });
				});
			});

			$stop.on('click', function(){
				$stop.prop('disabled', true);
				$.post(ajaxurl, { action:'twtaeo_ai_desc_stop', nonce:nonce }, function(res){
					if (res && res.success){
						renderState(res.data);
						if (res.data.status !== 'running'){ clearInterval(polling); polling = null; }
					} else {
						$stop.prop('disabled', false);
					}
				}).fail(function(){ $stop.prop('disabled', false); });
			});

			// Resume the live view if a job is already running when the page loads.
			if (String($wrap.data('running')) === '1'){ startPolling(); }
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
		ob_start();
		?>
		jQuery(document).ready(function($) {

			// ── AEO Score: ring rendering + breakdown dialog ────────────────
			function ringColor(s) { return s >= 80 ? '#16a34a' : (s >= 50 ? '#d97706' : '#dc2626'); }

			function esc(s) {
				return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			// Redraws a row's score cell — also called by Scan Now with the fresh score.
			window.twtAeoRenderScoreRing = function($cell, score, postId, title) {
				var c = ringColor(score), dash = Math.round(75.4 * score / 100 * 10) / 10;
				$cell.html(
					'<button type="button" class="twt-aeo-score-ring" data-post-id="' + postId + '"'
					+ ' data-post-title="' + esc(title||'') + '"'
					+ ' title="See the full score breakdown" style="background:none;border:0;padding:0;cursor:pointer;">'
					+ '<svg viewBox="0 0 32 32" width="34" height="34" aria-hidden="true">'
					+ '<circle cx="16" cy="16" r="12" fill="none" stroke="#e5e7eb" stroke-width="3.5"/>'
					+ '<circle cx="16" cy="16" r="12" fill="none" stroke="' + c + '" stroke-width="3.5" stroke-linecap="round"'
					+ ' stroke-dasharray="' + dash + ' 75.4" transform="rotate(-90 16 16)"/>'
					+ '<text x="16" y="20" text-anchor="middle" font-size="10" font-weight="700" fill="' + c + '">' + score + '</text>'
					+ '</svg></button>'
				);
			};

			var $scoreDlg = $('#twt-aeo-score-dialog');
			$scoreDlg.dialog({
				autoOpen: false,
				modal: true,
				width: 640,
				maxHeight: 560,
				buttons: [ { text: 'Close', click: function(){ $scoreDlg.dialog('close'); } } ]
			});

			function breakdownHtml(b) {
				var h = '<p style="margin:0 0 4px;font-size:13px;color:#50575e;">'
					+ 'Scored as <strong>' + esc(b.intent || 'General Page') + '</strong> — pages are only measured against surfaces their intent calls for. '
					+ 'The score is the sum of the lines below; nothing is hidden.</p>';
				(b.categories || []).forEach(function(cat) {
					var pct = cat.max > 0 ? Math.round(100 * cat.earned / cat.max) : 0;
					h += '<h4 style="margin:14px 0 4px;font-size:13px;">' + esc(cat.label)
						+ ' <span style="color:' + ringColor(pct) + ';">' + cat.earned + ' / ' + cat.max + '</span></h4>';
					h += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
					(cat.items || []).forEach(function(it) {
						var full = it.pts >= it.max;
						var fixHtml = '';
						if (!full) {
							// Company/Author moved to tabs of the E-E-A-T page; rewrite
							// links stored in breakdowns from before the move.
							if (it.fix) {
								it.fix = it.fix
									.replace('page=twt-aeo-company', 'page=twt-aeo-eeat&tab=company')
									.replace('page=twt-aeo-author',  'page=twt-aeo-eeat&tab=author');
							}
							if (it.create) {
								// Created from this page's own dashboard row — trigger it
								// directly instead of linking back to the page we're on.
								fixHtml = ' <a href="#" class="twt-aeo-link twt-aeo-score-create" data-type="' + esc(it.create) + '">' + esc(it.fix_label || 'Create it now') + '</a>';
							} else if (it.fix && it.fix.indexOf('page=twt-aeo') > -1 && (it.fix.indexOf('#missing-') > -1 || /page=twt-aeo\/?$/.test(it.fix))) {
								// Legacy breakdown (stored before create-links existed):
								// the fix pointed at this same dashboard — dead link, show text.
								fixHtml = ' <span style="color:#646970;">' + esc(it.fix_label || '') + '</span>';
							} else if (it.fix) {
								fixHtml = ' <a href="' + esc(it.fix) + '" class="twt-aeo-link">' + esc(it.fix_label || 'Fix') + '</a>';
							}
						}
						h += '<tr style="border-top:1px solid #f0f0f1;">'
							+ '<td style="padding:5px 8px 5px 0;width:18px;color:' + (full ? '#16a34a' : '#dc2626') + ';font-weight:700;">' + (full ? '✓' : '✕') + '</td>'
							+ '<td style="padding:5px 8px 5px 0;white-space:nowrap;vertical-align:top;"><strong>' + esc(it.label) + '</strong><br/><span style="color:#646970;">' + it.pts + ' / ' + it.max + '</span></td>'
							+ '<td style="padding:5px 0;color:#50575e;">' + esc(it.why) + fixHtml
							+ '</td></tr>';
					});
					h += '</table>';
				});
				return h;
			}

			// "Create it now" inside the breakdown: close this dialog and open the
			// create modal for that type. A synthesized trigger is used because
			// recommended types have no + button on the row and the row itself
			// may be on another table page — the modal needs neither.
			var scoreDialogPostId = 0;
			var scoreDialogTitle  = '';
			$(document).on('click', '.twt-aeo-score-create', function(e) {
				e.preventDefault();
				var type = $(this).data('type');
				$scoreDlg.dialog('close');
				var $tmp = $('<button type="button" class="twt-aeo-schema-create-btn" style="display:none"></button>')
					.attr('data-post-id', scoreDialogPostId)
					.attr('data-schema-type', type)
					.attr('data-post-title', scoreDialogTitle)
					.appendTo('body');
				$tmp.trigger('click');
				$tmp.remove();
			});

			// ── Bulk schema autofill ─────────────────────────────────────────
			$('#twt-aeo-autofill-btn').on('click', function() {
				var $btn    = $(this);
				var $status = $('#twt-aeo-autofill-status');
				var created = {}, needs = {}, pages = 0;

				$btn.prop('disabled', true);
				$status.text('Filling…');

				function fmtCounts(obj) {
					return Object.keys(obj).sort().map(function(k){ return obj[k] + ' ' + k; }).join(', ');
				}

				function batch(offset) {
					$.post(twtAeoSchemas.ajaxurl, {
						action: 'twtaeo_autofill_schemas',
						nonce:  twtAeoSchemas.nonce,
						offset: offset
					}, function(r) {
						if (!r.success) {
							$btn.prop('disabled', false);
							$status.text((r.data && r.data.message) || r.data || 'Autofill failed.');
							return;
						}
						var d = r.data;
						Object.keys(d.created).forEach(function(k){ created[k] = (created[k]||0) + d.created[k]; });
						Object.keys(d.needs_data).forEach(function(k){ needs[k] = (needs[k]||0) + d.needs_data[k]; });
						pages += d.pages_changed;

						if (!d.done) {
							$status.text('Filling… ' + Math.min(d.next_offset, d.total) + ' of ' + d.total + ' pages checked');
							batch(d.next_offset);
							return;
						}

						$btn.prop('disabled', false);
						$status.text('');
						var createdTotal = Object.keys(created).reduce(function(s,k){ return s + created[k]; }, 0);
						var html = '';
						if (createdTotal) {
							html += '<p style="margin:0 0 6px;"><strong>✓ Created ' + createdTotal + ' schema' + (createdTotal===1?'':'s') + ' across ' + pages + ' page' + (pages===1?'':'s') + ':</strong> ' + fmtCounts(created) + '.</p>';
						} else {
							html += '<p style="margin:0 0 6px;"><strong>Nothing to fill</strong> — every auto-fillable schema already exists on the scanned pages.</p>';
						}
						if (Object.keys(needs).length) {
							html += '<p style="margin:0 0 6px;color:#646970;">Left for you (needs facts only you know — dates, prices, addresses not on file): ' + fmtCounts(needs) + '. These still show + buttons on their rows.</p>';
						}
						html += '<p style="margin:0;"><a href="#" onclick="location.reload();return false;" class="twt-aeo-link">Reload to see updated rows and scores</a></p>';
						$('#twt-aeo-autofill-summary').html(html).show();
					}).fail(function(){
						$btn.prop('disabled', false);
						$status.text('Request failed — anything already created is saved; run again to continue.');
					});
				}

				batch(0);
			});

			$(document).on('click', '.twt-aeo-score-ring', function() {
				var postId = $(this).data('post-id');
				var title  = $(this).data('post-title') || '';
				scoreDialogPostId = postId;
				scoreDialogTitle  = title;
				$('#twt-aeo-score-dialog-body').html('<p class="twt-aeo-muted">Loading…</p>');
				$scoreDlg.dialog('option', 'title', 'AEO Score — ' + title);
				$scoreDlg.dialog('open');
				$.post(twtAeoSchemas.ajaxurl, {
					action:  'twtaeo_get_page_score',
					nonce:   twtAeoSchemas.nonce,
					post_id: postId
				}, function(r) {
					if (r.success) {
						$('#twt-aeo-score-dialog-body').html(breakdownHtml(r.data));
					} else {
						$('#twt-aeo-score-dialog-body').html('<p class="twt-aeo-muted">' + esc((r.data && r.data.message) || r.data || 'Could not load the breakdown.') + '</p>');
					}
				}).fail(function(){
					$('#twt-aeo-score-dialog-body').html('<p class="twt-aeo-muted">Request failed.</p>');
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}