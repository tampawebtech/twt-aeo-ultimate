<?php
/**
 * Open Graph Detector Page
 *
 * Shows Open Graph meta tag coverage across all published pages and posts.
 * Includes a per-page modal editor for og:title, og:description, and og:type.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_OG_Detector {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$all_results   = TWTAEO_OG_Detector::scan_all();
		$summary       = TWTAEO_OG_Detector::get_summary();
		$site_source   = TWTAEO_OG_Detector::detect_site_source();
		$seo_managed   = TWTAEO_OG_Writer::seo_plugin_active();
		$og_nonce      = wp_create_nonce( 'twtaeo_og_nonce' );

		// Pagination.
		$per_page    = 25;
		$total       = count( $all_results );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$current_page = isset( $_GET['paged'] ) ? max( 1, min( $total_pages, absint( wp_unslash( $_GET['paged'] ) ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$results     = array_slice( $all_results, ( $current_page - 1 ) * $per_page, $per_page );

		// Enqueue WordPress media library.
		wp_enqueue_media();

		?>
		<style>
		/* ── OG Modal overlay ── */
		#twt-aeo-og-modal-overlay {
			position: fixed;
			top: 0; left: 0; right: 0; bottom: 0;
			background: rgba(0,0,0,.65);
			z-index: 100000;
			padding: 20px;
			box-sizing: border-box;
			overflow-y: auto;
		}
		.twt-aeo-modal {
			background: #fff;
			border-radius: 6px;
			width: 100%;
			max-width: 620px;
			margin: 40px auto;
			box-shadow: 0 12px 48px rgba(0,0,0,.35);
			display: flex;
			flex-direction: column;
		}
		.twt-aeo-modal__header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 16px 20px;
			border-bottom: 1px solid #e0e0e0;
			position: sticky;
			top: 0;
			background: #fff;
			z-index: 1;
		}
		.twt-aeo-modal__title {
			margin: 0;
			font-size: 15px;
			font-weight: 600;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.twt-aeo-modal__close {
			background: none;
			border: none;
			font-size: 22px;
			line-height: 1;
			cursor: pointer;
			color: #666;
			padding: 0 4px;
		}
		.twt-aeo-modal__close:hover { color: #000; }
		.twt-aeo-modal__body {
			padding: 20px;
			flex: 1;
		}
		.twt-aeo-modal__footer {
			padding: 14px 20px;
			border-top: 1px solid #e0e0e0;
			display: flex;
			align-items: center;
			gap: 10px;
			background: #f9f9f9;
			border-radius: 0 0 6px 6px;
			position: sticky;
			bottom: 0;
		}
		.twt-aeo-modal__field { margin-bottom: 16px; }
		.twt-aeo-modal__label {
			display: flex;
			align-items: center;
			justify-content: space-between;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .04em;
			color: #444;
			margin-bottom: 5px;
		}
		.twt-aeo-modal__input,
		.twt-aeo-modal__textarea,
		.twt-aeo-modal__select {
			width: 100%;
			box-sizing: border-box;
			border: 1px solid #c3c4c7;
			border-radius: 3px;
			padding: 7px 10px;
			font-size: 13px;
			line-height: 1.4;
		}
		.twt-aeo-modal__input:focus,
		.twt-aeo-modal__textarea:focus,
		.twt-aeo-modal__select:focus {
			border-color: #2271b1;
			box-shadow: 0 0 0 1px #2271b1;
			outline: none;
		}
		.twt-aeo-modal__hint {
			display: block;
			font-size: 11px;
			color: #888;
			margin-top: 4px;
		}
		.twt-aeo-modal__char-count { font-weight: normal; font-size: 11px; color: #888; }
		.twt-aeo-modal__char-count.is-over { color: #d63638; font-weight: 600; }
		.twt-aeo-modal__status { font-size: 12px; }
		.twt-aeo-modal__status.is-success { color: #00a32a; }
		.twt-aeo-modal__status.is-error   { color: #d63638; }

		/* ── Image picker ── */
		.twt-aeo-og-image-picker { display: flex; gap: 14px; align-items: flex-start; }
		.twt-aeo-og-image-picker__thumb {
			width: 160px; height: 90px; flex-shrink: 0;
			border: 2px dashed #c3c4c7; border-radius: 4px;
			cursor: pointer; overflow: hidden;
			display: flex; align-items: center; justify-content: center;
			background: #f9f9f9;
		}
		.twt-aeo-og-image-picker__thumb:hover { border-color: #2271b1; }
		.twt-aeo-og-image-picker__thumb img { width: 100%; height: 100%; object-fit: cover; }
		.twt-aeo-og-image-picker__placeholder {
			display: flex; flex-direction: column; align-items: center;
			gap: 4px; color: #aaa; font-size: 11px;
		}
		.twt-aeo-og-image-picker__actions { display: flex; flex-direction: column; gap: 8px; }

		/* ── Social card preview ── */
		.twt-aeo-og-preview {
			border: 1px solid #e0e0e0; border-radius: 4px;
			overflow: hidden; margin-bottom: 16px;
		}
		.twt-aeo-og-preview__inner { display: flex; }
		.twt-aeo-og-preview__image-wrap {
			width: 120px; flex-shrink: 0; background: #f0f0f0;
			display: flex; align-items: center; justify-content: center; min-height: 80px;
		}
		.twt-aeo-og-preview__image-wrap img { width: 100%; height: 80px; object-fit: cover; }
		.twt-aeo-og-preview__placeholder { color: #bbb; font-size: 24px; }
		.twt-aeo-og-preview__text { padding: 10px 12px; flex: 1; }
		.twt-aeo-og-preview__site { font-size: 10px; color: #888; text-transform: uppercase; margin-bottom: 3px; }
		.twt-aeo-og-preview__title { font-size: 13px; font-weight: 600; line-height: 1.3; margin-bottom: 3px; }
		.twt-aeo-og-preview__desc  { font-size: 11px; color: #555; line-height: 1.4; }

		/* ── Buttons ── */
		.twt-aeo-btn {
			display: inline-flex; align-items: center; gap: 5px;
			padding: 6px 12px; border-radius: 3px; font-size: 13px;
			cursor: pointer; border: 1px solid transparent; line-height: 1.4;
		}
		.twt-aeo-btn--primary { background: #2271b1; color: #fff; border-color: #2271b1; }
		.twt-aeo-btn--primary:hover { background: #135e96; }
		.twt-aeo-btn--ghost { background: #fff; color: #2271b1; border-color: #c3c4c7; }
		.twt-aeo-btn--ghost:hover { border-color: #2271b1; }
		.twt-aeo-btn--sm { padding: 4px 8px; font-size: 12px; }
		.twt-aeo-btn:disabled { opacity: .6; cursor: not-allowed; }

		/* ── Logo badge ── */
		.twt-aeo-logo {
			display: inline-block; background: #2271b1; color: #fff;
			font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 3px;
		}

		/* ── Badges / tags ── */
		.twt-aeo-badge {
			display: inline-block; font-size: 11px; font-weight: 600;
			padding: 2px 7px; border-radius: 10px; white-space: nowrap;
		}
		.twt-aeo-badge--warn { background: #fcf0e3; color: #b35900; }
		.twt-aeo-og-status-good { background: #edfaef; color: #006505; }
		.twt-aeo-og-source-badge--ours { background: #e8f0fe; color: #1a56b0; }
		.twt-aeo-tag--missing { display: inline-block; font-size: 11px; color: #d63638; font-weight: 600; }
		.twt-aeo-og-type-pill {
			display: inline-block; font-size: 11px; font-weight: 600;
			padding: 2px 7px; border-radius: 10px; background: #f0f0f1; color: #444;
		}

		/* ── Table ── */
		.twt-aeo-page-table-wrap { overflow-x: auto; }
		.twt-aeo-og-table { width: 100%; border-collapse: collapse; font-size: 13px; }
		.twt-aeo-og-table th, .twt-aeo-og-table td {
			padding: 8px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: middle;
		}
		.twt-aeo-og-table thead th { background: #f6f7f7; font-size: 12px; font-weight: 600; }
		.twt-aeo-og-table thead th:hover { background: #eef0f1; }
		.twt-aeo-page-row--issues td:first-child { border-left: 3px solid #d63638; }
		.twt-aeo-page-row--ok td:first-child    { border-left: 3px solid #00a32a; }
		.twt-aeo-og-field-ok { display: inline-flex; align-items: center; gap: 4px; color: #00a32a; font-size: 12px; }
		.twt-aeo-og-field-text { color: #333; }
		.twt-aeo-og-actions { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
		.twt-aeo-link { font-size: 12px; color: #2271b1; }

		/* ── Summary cards ── */
		.twt-aeo-og-summary-grid { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
		.twt-aeo-summary-card {
			flex: 1; min-width: 140px; background: #fff;
			border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px;
			display: flex; flex-direction: column; align-items: center; gap: 4px;
		}
		.twt-aeo-summary-card__number { font-size: 28px; font-weight: 700; }
		.twt-aeo-summary-card__label  { font-size: 12px; color: #666; text-align: center; }
		.twt-aeo-summary-card--alert  { border-color: #f6b73c; }
		.twt-aeo-summary-card--good   { border-color: #00a32a; }

		/* ── Notice ── */
		.twt-aeo-og-notice {
			background: #e8f0fe; border-left: 4px solid #2271b1;
			padding: 10px 14px; font-size: 13px; border-radius: 0 3px 3px 0;
			margin-bottom: 16px; display: flex; gap: 8px; align-items: flex-start;
		}

		/* ── Sections ── */
		.twt-aeo-section { margin-bottom: 24px; }
		.twt-aeo-section__title { font-size: 14px; margin: 0 0 10px; display: flex; align-items: center; gap: 6px; }
		.twt-aeo-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px; }
		.twt-aeo-card__note { margin-top: 0; color: #555; font-size: 13px; }
		.twt-aeo-checklist { margin: 0; padding: 0; list-style: none; }
		.twt-aeo-checklist__item { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13px; }

		/* ── Pagination ── */
		.twt-og-pagination { display: flex; align-items: center; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
		.twt-og-pagination a, .twt-og-pagination span {
			display: inline-block; padding: 5px 10px; border: 1px solid #c3c4c7;
			border-radius: 3px; font-size: 13px; text-decoration: none; color: #2271b1;
		}
		.twt-og-pagination span.current {
			background: #2271b1; color: #fff; border-color: #2271b1; font-weight: 600;
		}
		.twt-og-pagination a:hover { border-color: #2271b1; background: #f0f6fc; }
		</style>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Open Graph', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div style="margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
						<span class="twt-aeo-badge twt-aeo-badge--source">
							<span class="dashicons dashicons-admin-plugins" style="font-size:12px;width:12px;height:12px;margin-right:4px;vertical-align:middle;"></span>
							<?php echo esc_html( $site_source ); ?>
						</span>
						<p class="twt-aeo-header__sub" style="margin:0;">
							<?php
							printf(
								// translators: %1$d: total pages scanned. %2$d: pages needing attention.
								esc_html__( '%1$d pages scanned — %2$d need attention', 'twt-aeo-ultimate' ),
								absint( $summary['total'] ),
								absint( $summary['needs_work'] )
							);
							?>
						</p>
					</div>
				</div>
			</div>

			<?php if ( $seo_managed ) : ?>
			<div class="twt-aeo-og-notice">
				<span class="dashicons dashicons-info-outline"></span>
				<?php
				printf(
					// translators: %1$s: SEO plugin managing OG output. %2$s: same plugin name.
					esc_html__( '%1$s is handling Open Graph output. Data you save here is stored and visible in this dashboard. To avoid duplicate tags, TWT AEO will not output OG meta while %2$s is active.', 'twt-aeo-ultimate' ),
					esc_html( $site_source ),
					esc_html( $site_source )
				);
				?>
			</div>
			<?php endif; ?>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-summary-grid twt-aeo-og-summary-grid">

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-admin-page"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Pages Scanned', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-yes-alt" style="color:var(--aeo-success);"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['complete'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Full Coverage', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $summary['missing_image'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-format-image" style="color:<?php echo $summary['missing_image'] > 0 ? 'var(--aeo-danger)' : 'var(--aeo-success)'; ?>;"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['missing_image'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing OG Image', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $summary['missing_description'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__icon"><span class="dashicons dashicons-editor-quote" style="color:<?php echo $summary['missing_description'] > 0 ? 'var(--aeo-danger)' : 'var(--aeo-success)'; ?>;"></span></div>
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['missing_description'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing Description', 'twt-aeo-ultimate' ); ?></div>
					</div>

				</div>
			</section>

			<!-- Page Table -->
			<section class="twt-aeo-section">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
					<h2 class="twt-aeo-section__title" style="margin:0;">
						<span class="dashicons dashicons-share"></span>
						<?php esc_html_e( 'Page Coverage', 'twt-aeo-ultimate' ); ?>
					</h2>
					<a href="<?php echo esc_url( add_query_arg( 'page', 'twt-aeo-og-detector', admin_url( 'admin.php' ) ) ); ?>" class="button">
						&#8635; <?php esc_html_e( 'Rescan All', 'twt-aeo-ultimate' ); ?>
					</a>
				</div>

				<?php if ( empty( $all_results ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty"><?php esc_html_e( 'No published pages or posts found.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				<?php else : ?>
				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table twt-aeo-og-table" id="twt-og-table">
						<thead>
							<tr>
								<th data-sort="title" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="image" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'og:image', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_title" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'og:title', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_desc" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'og:description', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="og_type" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'og:type', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="source" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Source', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th data-sort="status" style="cursor:pointer;user-select:none;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?> <span class="twt-og-sort-icon">↕</span></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $results as $item ) :
							$post      = $item['post'];
							$og        = $item['og_data'];
							$saved     = TWTAEO_OG_Writer::get( $post->ID );
							$has_saved = ! empty( $saved['og_title'] ) || ! empty( $saved['og_description'] ) || ! empty( $saved['og_type'] );
							$row_cls   = empty( $og['missing'] ) ? 'twt-aeo-page-row--ok' : 'twt-aeo-page-row--issues';

							// Default og:type for the modal.
							$default_type = 'website';
							if ( $post->post_type === 'post' )    $default_type = 'article';
							if ( $post->post_type === 'product' ) $default_type = 'product';

							$modal_type  = $saved['og_type']        ?: $default_type;
							$modal_title = $saved['og_title']       ?: '';
							$modal_desc  = $saved['og_description'] ?: '';
							$modal_image = $saved['og_image']       ?: '';
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>"
								data-post-id="<?php echo esc_attr( $post->ID ); ?>"
								data-sort-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
								data-sort-image="<?php echo $og['has_og_image'] ? '1' : '0'; ?>"
								data-sort-og_title="<?php echo $og['has_og_title'] ? '1' : '0'; ?>"
								data-sort-og_desc="<?php echo $og['has_og_description'] ? '1' : '0'; ?>"
								data-sort-og_type="<?php echo esc_attr( $og['og_type'] ?: '' ); ?>"
								data-sort-source="<?php echo esc_attr( $og['source'] ); ?>"
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
										<img src="<?php echo esc_url( $og['og_image'] ); ?>" alt="" class="twt-aeo-og-thumb" style="width:150px;height:auto;max-height:100px;object-fit:cover;display:block;border-radius:3px;">
									<?php else : ?>
										<span class="twt-aeo-og-no-image">
											<span class="dashicons dashicons-format-image"></span>
										</span>
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
									<span class="twt-aeo-og-type-pill twt-aeo-og-type-pill--<?php echo esc_attr( $og['og_type'] ?: 'website' ); ?>">
										<?php echo esc_html( $og['og_type'] ?: '—' ); ?>
									</span>
								</td>

								<td>
									<?php if ( $has_saved ) : ?>
										<span class="twt-aeo-badge twt-aeo-og-source-badge twt-aeo-og-source-badge--ours">
											<span class="dashicons dashicons-edit" style="font-size:10px;width:10px;height:10px;margin-right:3px;vertical-align:middle;"></span>
											<?php esc_html_e( 'AEO', 'twt-aeo-ultimate' ); ?>
										</span>
									<?php else : ?>
										<span class="twt-aeo-badge" style="background:rgba(99,102,241,.08);color:#4f46e5;">
											<?php echo esc_html( $og['source'] ); ?>
										</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( empty( $og['missing'] ) ) : ?>
										<span class="twt-aeo-badge twt-aeo-og-status-good"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-badge twt-aeo-badge--warn">
											<?php echo esc_html( count( $og['missing'] ) . ' ' . _n( 'issue', 'issues', count( $og['missing'] ), 'twt-aeo-ultimate' ) ); ?>
										</span>
									<?php endif; ?>
								</td>

								<td class="twt-aeo-og-actions">
									<button
										class="twt-aeo-btn twt-aeo-btn--primary twt-aeo-btn--sm js-og-edit"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
										data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
										data-og-title="<?php echo esc_attr( $modal_title ); ?>"
										data-og-description="<?php echo esc_attr( $modal_desc ); ?>"
										data-og-type="<?php echo esc_attr( $modal_type ); ?>"
										data-og-image="<?php echo esc_attr( $modal_image ); ?>"
									>
										<span class="dashicons dashicons-edit"></span>
										<?php esc_html_e( 'Edit OG', 'twt-aeo-ultimate' ); ?>
									</button>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php if ( $total_pages > 1 ) : ?>
					<div class="twt-og-pagination">
						<?php
						$base_url = add_query_arg( 'page', 'twt-aeo-og-detector', admin_url( 'admin.php' ) );
						if ( $current_page > 1 ) :
							?>
							<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'twt-aeo-ultimate' ); ?></a>
						<?php endif; ?>
						<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
							<?php if ( $p === $current_page ) : ?>
								<span class="current"><?php echo esc_html( $p ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url ) ); ?>"><?php echo esc_html( $p ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>
						<?php if ( $current_page < $total_pages ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'twt-aeo-ultimate' ); ?> &raquo;</a>
						<?php endif; ?>
						<span style="font-size:12px;color:#666;margin-left:6px;">
							<?php
						// translators: %1$d: first item on page. %2$d: last item on page. %3$d: total items.
						printf( esc_html__( '%1$d–%2$d of %3$d', 'twt-aeo-ultimate' ), absint( ( $current_page - 1 ) * $per_page + 1 ), absint( min( $current_page * $per_page, $total ) ), absint( $total ) ); ?>
						</span>
					</div>
				<?php endif; ?>
				</div>
				<?php endif; ?>
			</section>

			<!-- What This Checks -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What Open Graph Controls', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'When someone shares a URL on Facebook, LinkedIn, or Slack, these tags determine what the card looks like.', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'og:image — the thumbnail. Use 1200×630px for best results across all platforms.', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'og:title — the headline. Keep it under 60 characters so it doesn\'t get cut off.', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'og:description — shown below the title. Aim for 120–160 characters.', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'og:type — tells platforms whether this is a website, article, or product.', 'twt-aeo-ultimate' ); ?>
						</li>
					</ul>
				</div>
			</section>

		</div><!-- .twt-aeo-wrap -->

		<!-- ── OG Edit Modal ── -->
		<div id="twt-aeo-og-modal-overlay" style="display:none;" aria-hidden="true">
			<div class="twt-aeo-modal" role="dialog" aria-modal="true" aria-labelledby="og-modal-title">

				<div class="twt-aeo-modal__header">
					<h2 class="twt-aeo-modal__title" id="og-modal-title">
						<span class="twt-aeo-logo" style="font-size:10px;padding:2px 6px;">OG</span>
						<?php esc_html_e( 'Edit Open Graph', 'twt-aeo-ultimate' ); ?>
					</h2>
					<button class="twt-aeo-modal__close js-og-modal-close" aria-label="Close">&times;</button>
				</div>

				<div class="twt-aeo-modal__body">
					<input type="hidden" id="og-post-id">
					<input type="hidden" id="og-image-url">

					<!-- OG Image picker -->
					<div class="twt-aeo-modal__field" style="margin-bottom:20px;">
						<label class="twt-aeo-modal__label"><?php esc_html_e( 'og:image', 'twt-aeo-ultimate' ); ?></label>
						<div class="twt-aeo-og-image-picker">
							<div class="twt-aeo-og-image-picker__thumb js-og-image-select" id="og-image-thumb" title="<?php esc_attr_e( 'Click to select image', 'twt-aeo-ultimate' ); ?>">
								<img id="og-image-preview-img" src="" alt="" style="display:none;">
								<div class="twt-aeo-og-image-picker__placeholder" id="og-image-placeholder">
									<span class="dashicons dashicons-format-image"></span>
									<span><?php esc_html_e( 'Click to select image', 'twt-aeo-ultimate' ); ?></span>
								</div>
							</div>
							<div class="twt-aeo-og-image-picker__actions">
								<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost js-og-image-select">
									<span class="dashicons dashicons-admin-media"></span>
									<?php esc_html_e( 'Select from Media Library', 'twt-aeo-ultimate' ); ?>
								</button>
								<button type="button" class="twt-aeo-btn twt-aeo-btn--ghost js-og-image-remove" style="display:none;">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
								</button>
							</div>
						</div>
						<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Recommended: 1200×630px. Falls back to featured image if not set.', 'twt-aeo-ultimate' ); ?></span>
					</div>

					<!-- Social card live preview -->
					<div class="twt-aeo-og-preview">
						<div class="twt-aeo-og-preview__inner">
							<div class="twt-aeo-og-preview__image-wrap">
								<img id="og-preview-image" src="" alt="" style="display:none;">
								<div id="og-preview-image-placeholder" class="twt-aeo-og-preview__placeholder">
									<span class="dashicons dashicons-format-image"></span>
								</div>
							</div>
							<div class="twt-aeo-og-preview__text">
								<div class="twt-aeo-og-preview__site"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
								<div class="twt-aeo-og-preview__title" id="og-preview-title"><?php esc_html_e( 'Page title', 'twt-aeo-ultimate' ); ?></div>
								<div class="twt-aeo-og-preview__desc" id="og-preview-desc"><?php esc_html_e( 'Description will appear here…', 'twt-aeo-ultimate' ); ?></div>
							</div>
						</div>
					</div>

					<!-- Fields -->
					<div class="twt-aeo-modal__fields">

						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="og-title">
								<?php esc_html_e( 'og:title', 'twt-aeo-ultimate' ); ?>
								<span class="twt-aeo-modal__char-count" id="og-title-count">0 / 60</span>
							</label>
							<input
								type="text"
								id="og-title"
								class="twt-aeo-modal__input"
								placeholder="<?php esc_attr_e( 'Auto-filled from page title', 'twt-aeo-ultimate' ); ?>"
								maxlength="120"
							>
							<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Auto-filled from page title. Customize for social sharing. Keep under 60 characters.', 'twt-aeo-ultimate' ); ?></span>
						</div>

						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="og-description">
								<?php esc_html_e( 'og:description', 'twt-aeo-ultimate' ); ?>
								<span class="twt-aeo-modal__char-count" id="og-desc-count">0 / 160</span>
							</label>
							<textarea
								id="og-description"
								class="twt-aeo-modal__textarea"
								rows="3"
								placeholder="<?php esc_attr_e( 'Write a compelling description for social shares…', 'twt-aeo-ultimate' ); ?>"
								maxlength="300"
							></textarea>
							<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Recommended: 120–160 characters.', 'twt-aeo-ultimate' ); ?></span>
						</div>

						<div class="twt-aeo-modal__field">
							<label class="twt-aeo-modal__label" for="og-type"><?php esc_html_e( 'og:type', 'twt-aeo-ultimate' ); ?></label>
							<select id="og-type" class="twt-aeo-modal__select">
								<option value="website"><?php esc_html_e( 'website — standard page', 'twt-aeo-ultimate' ); ?></option>
								<option value="article"><?php esc_html_e( 'article — blog post or news', 'twt-aeo-ultimate' ); ?></option>
								<option value="product"><?php esc_html_e( 'product — WooCommerce or shop item', 'twt-aeo-ultimate' ); ?></option>
							</select>
							<span class="twt-aeo-modal__hint"><?php esc_html_e( 'Auto-selected based on page type. Products and posts are set automatically.', 'twt-aeo-ultimate' ); ?></span>
						</div>

					</div>
				</div><!-- .twt-aeo-modal__body -->

				<div class="twt-aeo-modal__footer">
					<button id="og-save-btn" class="twt-aeo-btn twt-aeo-btn--primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save', 'twt-aeo-ultimate' ); ?>
					</button>
					<button class="twt-aeo-btn twt-aeo-btn--ghost js-og-modal-close"><?php esc_html_e( 'Cancel', 'twt-aeo-ultimate' ); ?></button>
					<span id="og-save-status" class="twt-aeo-modal__status"></span>
				</div>

			</div>
		</div>
		<!-- ── /OG Edit Modal ── -->

		<?php
		ob_start();
		?>
		(function($) {
			'use strict';

			var nonce      = <?php echo wp_json_encode( $og_nonce ); ?>;
			var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var mediaFrame = null;

			// ── Image helpers ─────────────────────────────────────────────────────

			function setImage( url ) {
				if ( url ) {
					$('#og-image-url').val( url );
					$('#og-image-preview-img').attr('src', url).show();
					$('#og-image-placeholder').hide();
					$('.js-og-image-remove').show();
					$('#og-preview-image').attr('src', url).show();
					$('#og-preview-image-placeholder').hide();
				} else {
					$('#og-image-url').val('');
					$('#og-image-preview-img').attr('src','').hide();
					$('#og-image-placeholder').show();
					$('.js-og-image-remove').hide();
					$('#og-preview-image').attr('src','').hide();
					$('#og-preview-image-placeholder').show();
				}
			}

			// ── Helpers ──────────────────────────────────────────────────────────

			function openModal( data ) {
				var $overlay = $('#twt-aeo-og-modal-overlay');
				var postId    = data.postId;
				var postTitle = data.postTitle;
				var postType  = data.postType;

				// Auto-select type based on post type if no saved value.
				var defaultType = 'website';
				if ( postType === 'post' )    defaultType = 'article';
				if ( postType === 'product' ) defaultType = 'product';

				$('#og-post-id').val( postId );
				$('#og-title').val( data.ogTitle || postTitle );
				$('#og-description').val( data.ogDescription || '' );
				$('#og-type').val( data.ogType || defaultType );
				setImage( data.ogImage || '' );

				updatePreview();
				updateCounts();

				$('#og-save-status').text('').removeClass('is-success is-error');
				$overlay.removeAttr('aria-hidden').show();
				$('#og-title').focus();
			}

			function closeModal() {
				$('#twt-aeo-og-modal-overlay').attr('aria-hidden','true').hide();
			}

			function updatePreview() {
				var title = $('#og-title').val() || '';
				var desc  = $('#og-description').val() || '';

				$('#og-preview-title').text( title || '—' );
				$('#og-preview-desc').text( desc || '' );
			}

			function updateCounts() {
				var titleLen = $('#og-title').val().length;
				var descLen  = $('#og-description').val().length;

				$('#og-title-count')
					.text( titleLen + ' / 60' )
					.toggleClass('is-over', titleLen > 60 );

				$('#og-desc-count')
					.text( descLen + ' / 160' )
					.toggleClass('is-over', descLen > 160 );
			}

			// ── Open modal ───────────────────────────────────────────────────────

			$(document).on('click', '.js-og-edit', function() {
				var $btn = $(this);
				openModal({
					postId:        $btn.data('post-id'),
					postTitle:     $btn.data('post-title'),
					postType:      $btn.data('post-type'),
					ogTitle:       $btn.data('og-title'),
					ogDescription: $btn.data('og-description'),
					ogType:        $btn.data('og-type'),
					ogImage:       $btn.data('og-image'),
				});
			});

			// ── Close modal ──────────────────────────────────────────────────────

			$(document).on('click', '.js-og-modal-close', closeModal);

			$(document).on('click', '#twt-aeo-og-modal-overlay', function(e) {
				if ( $(e.target).is('#twt-aeo-og-modal-overlay') ) closeModal();
			});

			$(document).on('keydown', function(e) {
				if ( e.key === 'Escape' ) closeModal();
			});

			// ── Media library ────────────────────────────────────────────────────

			$(document).on('click', '.js-og-image-select', function(e) {
				e.preventDefault();
				e.stopPropagation();

				if ( mediaFrame ) {
					mediaFrame.open();
					return;
				}

				mediaFrame = wp.media({
					title:    '<?php esc_html_e( 'Select Open Graph Image', 'twt-aeo-ultimate' ); ?>',
					button:   { text: '<?php esc_html_e( 'Use this image', 'twt-aeo-ultimate' ); ?>' },
					multiple: false,
					library:  { type: 'image' },
				});

				mediaFrame.on('select', function() {
					var attachment = mediaFrame.state().get('selection').first().toJSON();
					setImage( attachment.url );
				});

				mediaFrame.open();
			});

			$(document).on('click', '.js-og-image-remove', function(e) {
				e.preventDefault();
				e.stopPropagation();
				setImage('');
			});

			// ── Live preview ─────────────────────────────────────────────────────

			$(document).on('input', '#og-title, #og-description', function() {
				updatePreview();
				updateCounts();
			});

			// ── Save ─────────────────────────────────────────────────────────────

			$(document).on('click', '#og-save-btn', function() {
				var $btn    = $(this);
				var postId  = $('#og-post-id').val();
				var title   = $('#og-title').val();
				var desc    = $('#og-description').val();
				var type    = $('#og-type').val();
				var image   = $('#og-image-url').val();
				var $status = $('#og-save-status');

				$btn.prop('disabled', true).text('<?php esc_attr_e( 'Saving…', 'twt-aeo-ultimate' ); ?>');
				$status.text('').removeClass('is-success is-error');

				$.ajax({
					url:  ajaxUrl,
					type: 'POST',
					data: {
						action:         'twtaeo_save_og_data',
						nonce:          nonce,
						post_id:        postId,
						og_title:       title,
						og_description: desc,
						og_type:        type,
						og_image:       image,
					},
					success: function(response) {
						if ( response.success ) {
							$status.text('<?php esc_attr_e( 'Saved!', 'twt-aeo-ultimate' ); ?>').addClass('is-success');

							// Update row data attributes for next open.
							var $row = $('tr[data-post-id="' + postId + '"]');
							var $editBtn = $row.find('.js-og-edit');
							$editBtn.data('og-title', title);
							$editBtn.data('og-description', desc);
							$editBtn.data('og-type', type);
							$editBtn.data('og-image', image);

							// Update image cell thumbnail.
							if ( image ) {
								$row.find('.twt-aeo-og-image-cell').html(
									'<img src="' + image + '" alt="" class="twt-aeo-og-thumb" style="width:150px;height:auto;max-height:100px;object-fit:cover;display:block;border-radius:3px;">'
								);
							}

							// Update source badge to AEO.
							$row.find('.twt-aeo-badge:not(.twt-aeo-badge--warn):not(.twt-aeo-og-status-good)').replaceWith(
								'<span class="twt-aeo-badge twt-aeo-og-source-badge twt-aeo-og-source-badge--ours"><span class="dashicons dashicons-edit" style="font-size:10px;width:10px;height:10px;margin-right:3px;vertical-align:middle;"></span><?php esc_html_e( 'AEO', 'twt-aeo-ultimate' ); ?></span>'
							);

							// Update og:type pill.
							$row.find('.twt-aeo-og-type-pill')
								.attr('class', 'twt-aeo-og-type-pill twt-aeo-og-type-pill--' + type )
								.text( type );

							// Update og:title cell.
							if ( title ) {
								var trimmedTitle = title.length > 30 ? title.substring(0,30) + '…' : title;
								$row.find('td:nth-child(3)').html(
									'<span class="twt-aeo-og-field-ok" title="' + $('<div>').text(title).html() + '"><span class="dashicons dashicons-yes-alt"></span><span class="twt-aeo-og-field-text">' + $('<div>').text(trimmedTitle).html() + '</span></span>'
								);
							}

							// Update og:description cell.
							if ( desc ) {
								var words = desc.split(' ').slice(0,8).join(' ') + (desc.split(' ').length > 8 ? '…' : '');
								$row.find('td:nth-child(4)').html(
									'<span class="twt-aeo-og-field-ok" title="' + $('<div>').text(desc).html() + '"><span class="dashicons dashicons-yes-alt"></span><span class="twt-aeo-og-field-text">' + $('<div>').text(words).html() + '</span></span>'
								);
							}

							// Mark row as OK if title and desc are set.
							if ( title && desc ) {
								$row.removeClass('twt-aeo-page-row--issues').addClass('twt-aeo-page-row--ok');
								$row.find('.twt-aeo-badge--warn').replaceWith(
									'<span class="twt-aeo-badge twt-aeo-og-status-good"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>'
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

		})(jQuery);

		// ── Sortable columns ──────────────────────────────────────────────────
		(function() {
			var table   = document.getElementById('twt-og-table');
			if ( ! table ) return;

			var sortCol = null;
			var sortDir = 1; // 1 = asc, -1 = desc

			table.querySelectorAll('thead th[data-sort]').forEach(function(th) {
				th.addEventListener('click', function() {
					var key = th.dataset.sort;

					if ( sortCol === key ) {
						sortDir *= -1;
					} else {
						sortCol = key;
						sortDir = 1;
					}

					// Reset all icons.
					table.querySelectorAll('.twt-og-sort-icon').forEach(function(el) {
						el.textContent = '↕';
						el.style.color = '#999';
					});
					th.querySelector('.twt-og-sort-icon').textContent = sortDir === 1 ? '↑' : '↓';
					th.querySelector('.twt-og-sort-icon').style.color = '#2271b1';

					var tbody = table.querySelector('tbody');
					var rows  = Array.from( tbody.querySelectorAll('tr') );

					rows.sort(function(a, b) {
						var attr = 'data-sort-' + key;
						var aVal = a.getAttribute(attr) || '';
						var bVal = b.getAttribute(attr) || '';

						// Numeric sort for 0/1 fields; alpha otherwise.
						var aNum = parseFloat(aVal);
						var bNum = parseFloat(bVal);
						if ( ! isNaN(aNum) && ! isNaN(bNum) ) {
							return ( aNum - bNum ) * sortDir;
						}
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
