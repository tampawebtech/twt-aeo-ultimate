<?php
/**
 * FAQ Detector Page
 *
 * Shows all pages with FAQ content and whether FAQPage schema is present.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_FAQ_Detector {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$results = TWTAEO_FAQ_Detector::scan_all();
		$summary = TWTAEO_FAQ_Detector::get_summary();

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'FAQ Detector', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub" style="margin-left:auto;">
						<?php
						printf(
							// translators: %1$d: count of pages with FAQ content. %2$d: count missing FAQPage schema.
							esc_html__( '%1$d pages with FAQ content found — %2$d missing FAQPage schema', 'twt-aeo-ultimate' ),
							absint( $summary['total_with_faq'] ),
							absint( $summary['needs_schema'] )
						);
						?>
					</p>
				</div>
			</div>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-summary-grid">

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total_with_faq'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Pages with FAQ Content', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $summary['needs_schema'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['needs_schema'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing FAQPage Schema', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['has_schema'] ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'FAQPage Schema Present', 'twt-aeo-ultimate' ); ?></div>
					</div>

				</div>
			</section>

			<!-- What This Detects -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Detection Methods', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'This detector looks for FAQ-style content using six strategies, in order:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Gutenberg FAQ blocks (core, Rank Math, Yoast)', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Accordion blocks (GenerateBlocks, Kadence, Stackable, Otter, and others)', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Yoast FAQ block in post content', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'H3/H4 heading followed by paragraph — classic Q&A layout (2+ pairs required)', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Question-word headings: What, How, Why, Can, Is, Are, Do, Does, Will, Should (2+ required)', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Numbered Q&A paragraphs: Q1:, Q2:, Q: prefix pattern (2+ required)', 'twt-aeo-ultimate' ); ?>
						</li>
					</ul>
				</div>
			</section>

			<!-- Results Table -->
			<?php $generate_nonce = wp_create_nonce( TWTAEO_FAQ_Detector::NONCE_GENERATE ); ?>
			<section class="twt-aeo-section">
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
					<h2 class="twt-aeo-section__title" style="margin:0;"><?php esc_html_e( 'Pages with FAQ Content', 'twt-aeo-ultimate' ); ?></h2>
					<?php if ( $summary['needs_schema'] > 0 ) : ?>
					<button type="button" id="twt-aeo-faq-gen-all"
						data-nonce="<?php echo esc_attr( $generate_nonce ); ?>"
						class="button button-primary">
						<?php
						printf(
							// translators: %d: number of pages missing FAQPage schema.
							esc_html__( 'Generate All (%d missing)', 'twt-aeo-ultimate' ),
							absint( $summary['needs_schema'] )
						);
						?>
					</button>
					<span id="twt-aeo-faq-gen-all-status" style="font-size:13px;"></span>
					<?php endif; ?>
				</div>

				<?php if ( empty( $results ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty">
							<?php esc_html_e( 'No pages with FAQ-style content were detected. Once you add FAQ blocks, accordion sections, or Q&A-style headings to a page, it will appear here.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
				<?php else : ?>
				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Detection Method', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Q&A Pairs', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'FAQPage Schema', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $results as $item ) :
							$post     = $item['post'];
							$faq      = $item['faq_data'];
							$row_cls  = $faq['needs_schema'] ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
							$method_labels = array(
								'block'            => 'FAQ Block',
								'accordion'        => 'Accordion Block',
								'yoast-faq-block'  => 'Yoast FAQ Block',
								'heading-qa'       => 'Heading Q&A',
								'question-headings' => 'Question Headings',
								'numbered-qa'      => 'Numbered Q&A',
							);
							$method_label = $method_labels[ $faq['method'] ] ?? $faq['method'];
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>">

								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $post ) ); ?>
									</a>
									<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
								</td>

								<td>
									<span class="twt-aeo-intent-pill"><?php echo esc_html( $method_label ); ?></span>
								</td>

								<td>
									<?php if ( $faq['qa_count'] > 0 ) : ?>
										<span class="twt-aeo-badge twt-aeo-badge--page"><?php echo esc_html( $faq['qa_count'] ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-muted">—</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $faq['has_faq_schema'] ) : ?>
										<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( $faq['needs_schema'] ) : ?>
										<span class="twt-aeo-badge twt-aeo-badge--warn"><?php esc_html_e( 'Add FAQPage schema', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>
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
									<?php if ( $faq['needs_schema'] ) : ?>
									·
									<button type="button"
										class="twt-aeo-link twt-aeo-faq-gen-one"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-nonce="<?php echo esc_attr( $generate_nonce ); ?>"
										style="background:none;border:none;cursor:pointer;padding:0;">
										<?php esc_html_e( 'Add FAQ Schema', 'twt-aeo-ultimate' ); ?>
									</button>
									<span class="twt-aeo-faq-gen-one-result" data-post-id="<?php echo esc_attr( $post->ID ); ?>"></span>
									<?php endif; ?>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<!-- FAQ Schema Guidance -->
			<?php if ( $summary['needs_schema'] > 0 ) : ?>
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'How to Add FAQPage Schema', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'FAQPage schema tells AI systems and search engines that this page directly answers questions. Here are the easiest ways to add it:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<?php if ( defined( 'RANK_MATH_VERSION' ) ) : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Rank Math: Add the Rank Math FAQ block to your page — it generates FAQPage schema automatically.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php elseif ( defined( 'WPSEO_VERSION' ) ) : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Yoast SEO: Use the Yoast FAQ block inside the Gutenberg editor — it generates FAQPage schema automatically.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php else : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Use a plugin like Rank Math or Yoast SEO to add FAQPage schema through their FAQ block, or add the JSON-LD manually in your theme.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php endif; ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Once added, save the page — TWT AEO Ultimate will re-scan and update the status here.', 'twt-aeo-ultimate' ); ?>
						</li>
					</ul>
				</div>
			</section>
			<?php endif; ?>

		</div>

		<?php
		ob_start();
		?>
		(function($){
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			$('#twt-aeo-faq-gen-all').on('click', function(){
			var btn    = $(this);
			var nonce  = btn.data('nonce');
			var status = $('#twt-aeo-faq-gen-all-status');

			// Collect post IDs for all rows that still need schema.
			var ids = [];
			$('.twt-aeo-faq-gen-one').each(function(){
				ids.push( $(this).data('post-id') );
			});

			if ( ! ids.length ) {
				status.css('color','#16a34a').text('<?php echo esc_js( __( 'Nothing to generate.', 'twt-aeo-ultimate' ) ); ?>');
				return;
			}

			btn.prop('disabled', true);
			var done = 0, skipped = 0, total = ids.length;

			function processNext() {
				if ( ! ids.length ) {
					btn.prop('disabled', false);
					status.css('color','#16a34a').text(
						'<?php echo esc_js( __( 'Done', 'twt-aeo-ultimate' ) ); ?> — ' +
						done + ' <?php echo esc_js( __( 'saved', 'twt-aeo-ultimate' ) ); ?>' +
						( skipped ? ', ' + skipped + ' <?php echo esc_js( __( 'skipped', 'twt-aeo-ultimate' ) ); ?>' : '' )
					);
					return;
				}

				var postId = ids.shift();
				status.css('color','').text(
					'<?php echo esc_js( __( 'Processing', 'twt-aeo-ultimate' ) ); ?> ' +
					( total - ids.length ) + ' / ' + total + '…'
				);

				$.post( ajaxurl, {
					action:  'twtaeo_faq_generate_batch',
					nonce:   nonce,
					post_id: postId
				}, function(r){
					if ( r.success ) {
						if ( r.data.skipped ) {
							skipped++;
						} else {
							done++;
							// Update the row's inline button and result span.
							var rowBtn    = $('.twt-aeo-faq-gen-one[data-post-id="' + postId + '"]');
							var rowResult = $('.twt-aeo-faq-gen-one-result[data-post-id="' + postId + '"]');
							rowBtn.text('<?php echo esc_js( __( 'Regenerate', 'twt-aeo-ultimate' ) ); ?>');
							rowResult.css('color','#16a34a').text(' ✓ ' + r.data.qa_count + ' Q&A saved');
						}
					} else {
						skipped++;
					}
					processNext();
				}).fail(function(){
					skipped++;
					processNext();
				});
			}

			processNext();
		});

		$(document).on('click', '.twt-aeo-faq-gen-one', function(){
				var btn    = $(this);
				var postId = btn.data('post-id');
				var nonce  = btn.data('nonce');
				var result = $('.twt-aeo-faq-gen-one-result[data-post-id="' + postId + '"]');

				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>');
				result.text('').removeAttr('style');

				$.post( ajaxurl, {
					action:  'twtaeo_faq_generate_one',
					nonce:   nonce,
					post_id: postId
				}, function(r){
					if ( r.success ) {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Regenerate', 'twt-aeo-ultimate' ) ); ?>');
						result.css('color','#16a34a').text(' ✓ ' + r.data.qa_count + ' Q&A saved');
					} else {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add FAQ Schema', 'twt-aeo-ultimate' ) ); ?>');
						result.css('color','#dc2626').text(' ✗ ' + (r.data.message || 'Error'));
					}
				}).fail(function(){
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add FAQ Schema', 'twt-aeo-ultimate' ) ); ?>');
					result.css('color','#dc2626').text(' ✗ Request failed');
				});
			});
		})(jQuery);
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}