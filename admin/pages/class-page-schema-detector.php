<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Merged Schema Detector page — FAQ, Service, and Contact tabs.
 */
class TWTAEO_Page_Schema_Detector {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		global $twtaeo_plugin;
		$modules         = $twtaeo_plugin ? $twtaeo_plugin->modules : null;
		$faq_active      = $modules ? $modules->is_active( 'faq-detector' )     : true;
		$service_active  = $modules ? $modules->is_active( 'service-detector' ) : true;
		$contact_active  = $modules ? $modules->is_active( 'contact-detector' ) : true;

		$valid_tabs  = array();
		if ( $faq_active )     $valid_tabs[] = 'faq';
		if ( $service_active ) $valid_tabs[] = 'service';
		if ( $contact_active ) $valid_tabs[] = 'contact';
		$valid_tabs[] = 'conflicts';

		$default_tab = $valid_tabs[0] ?? 'faq';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab  = ( isset( $_GET['tab'] ) && in_array( sanitize_key( wp_unslash( $_GET['tab'] ) ), $valid_tabs, true ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['tab'] ) )
			: $default_tab;

		$base_url = admin_url( 'admin.php?page=twt-aeo-schema-detector' );

		// Service and Contact tabs need jQuery UI dialog.
		if ( $active_tab === 'service' || $active_tab === 'contact' ) {
			wp_enqueue_script( 'jquery-ui-dialog' );
			wp_enqueue_style( 'wp-jquery-ui-dialog' );
		}
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Schema Detector', 'twt-aeo-ultimate' ); ?>
					</h1>
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $active_tab, 'rescanned' => time() ), $base_url ) ); ?>"
					   class="button" style="margin-left:auto;">
						&#8635; <?php esc_html_e( 'Rescan', 'twt-aeo-ultimate' ); ?>
					</a>
				</div>
			</div>

			<?php if ( isset( $_GET['rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI confirmation, no state change. ?>
			<div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
				<p><?php esc_html_e( 'Rescan complete — schema detection refreshed.', 'twt-aeo-ultimate' ); ?></p>
			</div>
			<?php endif; ?>

			<nav class="twt-aeo-tabs">
				<?php if ( $faq_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'faq', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo $active_tab === 'faq' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-editor-help"></span>
					<?php esc_html_e( 'FAQ', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $service_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'service', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo $active_tab === 'service' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-tools"></span>
					<?php esc_html_e( 'Service', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $contact_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'contact', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo $active_tab === 'contact' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-phone"></span>
					<?php esc_html_e( 'Contact', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'conflicts', $base_url ) ); ?>"
				   class="twt-aeo-tab twt-aeo-tab--conflicts <?php echo $active_tab === 'conflicts' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Conflicts', 'twt-aeo-ultimate' ); ?>
				</a>
			</nav>

			<?php
			if ( $active_tab === 'faq' && $faq_active ) {
				self::render_faq();
			} elseif ( $active_tab === 'service' && $service_active ) {
				self::render_service( $base_url );
			} elseif ( $active_tab === 'contact' && $contact_active ) {
				self::render_contact();
			} elseif ( $active_tab === 'conflicts' ) {
				TWTAEO_Page_Schema_Conflicts::render_tab_content();
			}
			?>

		</div>
		<?php
	}

	// ── FAQ tab ──────────────────────────────────────────────────────────────

	private static function render_faq() {
		$results = TWTAEO_FAQ_Detector::scan_all();
		$summary = TWTAEO_FAQ_Detector::get_summary();
		?>

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

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Detection Methods', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'This detector looks for FAQ-style content using six strategies, in order:', 'twt-aeo-ultimate' ); ?></p>
				<ul class="twt-aeo-checklist">
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Gutenberg FAQ blocks (core, Rank Math, Yoast)', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Accordion blocks (GenerateBlocks, Kadence, Stackable, Otter, and others)', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Yoast FAQ block in post content', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'H3/H4 heading followed by paragraph — classic Q&A layout (2+ pairs required)', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Question-word headings: What, How, Why, Can, Is, Are, Do, Does, Will, Should (2+ required)', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Numbered Q&A paragraphs: Q1:, Q2:, Q: prefix pattern (2+ required)', 'twt-aeo-ultimate' ); ?></li>
				</ul>
			</div>
		</section>

		<?php $generate_nonce = wp_create_nonce( TWTAEO_FAQ_Detector::NONCE_GENERATE ); ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Pages with FAQ Content', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( empty( $results ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty"><?php esc_html_e( 'No pages with FAQ-style content were detected. Once you add FAQ blocks, accordion sections, or Q&A-style headings to a page, it will appear here.', 'twt-aeo-ultimate' ); ?></p>
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
						$post    = $item['post'];
						$faq     = $item['faq_data'];
						$row_cls = $faq['needs_schema'] ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
						$method_labels = array(
							'block'             => 'FAQ Block',
							'accordion'         => 'Accordion Block',
							'yoast-faq-block'   => 'Yoast FAQ Block',
							'heading-qa'        => 'Heading Q&A',
							'question-headings' => 'Question Headings',
							'numbered-qa'       => 'Numbered Q&A',
						);
						$method_label = $method_labels[ $faq['method'] ] ?? $faq['method'];
					?>
						<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>">
							<td class="twt-aeo-page-row__title">
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
								<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
							</td>
							<td><span class="twt-aeo-intent-pill"><?php echo esc_html( $method_label ); ?></span></td>
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
								<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								·
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link"><?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?></a>
								<?php if ( $faq['needs_schema'] ) : ?>
								·
								<button type="button" class="twt-aeo-link twt-aeo-faq-gen-one"
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

		<?php if ( $summary['needs_schema'] > 0 ) : ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'How to Add FAQPage Schema', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'FAQPage schema tells AI systems and search engines that this page directly answers questions. Here are the easiest ways to add it:', 'twt-aeo-ultimate' ); ?></p>
				<ul class="twt-aeo-checklist">
					<?php if ( defined( 'RANK_MATH_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Rank Math: Add the Rank Math FAQ block to your page — it generates FAQPage schema automatically.', 'twt-aeo-ultimate' ); ?></li>
					<?php elseif ( defined( 'WPSEO_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Yoast SEO: Use the Yoast FAQ block inside the Gutenberg editor — it generates FAQPage schema automatically.', 'twt-aeo-ultimate' ); ?></li>
					<?php elseif ( defined( 'SASWP_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Schema & Structured Data for WP & AMP: Open the post editor, go to the Schema tab in the sidebar, add a FAQPage schema type, and enter your questions and answers manually.', 'twt-aeo-ultimate' ); ?></li>
					<?php else : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Use a plugin like Rank Math, Yoast SEO, or Schema & Structured Data for WP & AMP to add FAQPage schema, or add the JSON-LD manually in your theme.', 'twt-aeo-ultimate' ); ?></li>
					<?php endif; ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Once added, save the page — TWT AEO Ultimate will re-scan and update the status here.', 'twt-aeo-ultimate' ); ?></li>
				</ul>
			</div>
		</section>
		<?php endif; ?>

		<?php
		ob_start();
		?>
		(function($){
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			$(document).on('click', '.twt-aeo-faq-gen-one', function(){
				var btn    = $(this);
				var postId = btn.data('post-id');
				var nonce  = btn.data('nonce');
				var result = $('.twt-aeo-faq-gen-one-result[data-post-id="' + postId + '"]');
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>');
				result.text('').removeAttr('style');
				$.post(ajaxurl, { action: 'twtaeo_faq_generate_one', nonce: nonce, post_id: postId }, function(r){
					if (r.success) {
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

	// ── Service tab ──────────────────────────────────────────────────────────

	private static function render_service( $base_url ) {
		$include_posts = isset( $_GET['include_posts'] ) && '1' === sanitize_key( wp_unslash( $_GET['include_posts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$results       = TWTAEO_Service_Detector::scan_all( $include_posts );
		$summary       = TWTAEO_Service_Detector::get_summary( $include_posts );

		$tab_url     = add_query_arg( 'tab', 'service', $base_url );
		$toggle_url  = $include_posts ? $tab_url : add_query_arg( 'include_posts', '1', $tab_url );
		$toggle_text = $include_posts
			? __( 'Showing Pages + Posts — Show Pages Only', 'twt-aeo-ultimate' )
			: __( 'Show Blog Posts Too', 'twt-aeo-ultimate' );
		?>

		<section class="twt-aeo-section">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
				<p class="twt-aeo-header__sub" style="margin:0;">
					<?php
				printf(
					// translators: %1$d: count of pages/posts. %2$s: 'pages' or 'pages/posts'. %3$d: count missing schema.
					esc_html__( '%1$d %2$s with service content — %3$d missing Service schema', 'twt-aeo-ultimate' ),
					absint( $summary['total_with_service'] ),
					$include_posts ? esc_html__( 'pages/posts', 'twt-aeo-ultimate' ) : esc_html__( 'pages', 'twt-aeo-ultimate' ),
					absint( $summary['needs_schema'] )
				); ?>
				</p>
				<a href="<?php echo esc_url( $toggle_url ); ?>" class="button <?php echo $include_posts ? 'button-primary' : ''; ?>">
					<?php echo esc_html( $toggle_text ); ?>
				</a>
			</div>
			<div class="twt-aeo-summary-grid">

				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total_with_service'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Pages with Service Content', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $summary['needs_schema'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['needs_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing Service Schema', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['has_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Service Schema Present', 'twt-aeo-ultimate' ); ?></div>
				</div>

			</div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Detection Methods', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'This detector identifies service-oriented content using four strategies, in order:', 'twt-aeo-ultimate' ); ?></p>
				<ul class="twt-aeo-checklist">
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Page intent classifier — pages classified as Service Page or Product-Service Page', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'URL slug — contains service, repair, install, maintenance, consulting', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Content keywords — service-related terms in headings and first 300 words (3+ required)', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'WooCommerce products — only products whose title, slug, or content contains service keywords (not all products)', 'twt-aeo-ultimate' ); ?></li>
				</ul>
			</div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php echo $include_posts
					? esc_html__( 'Pages & Posts with Service Content', 'twt-aeo-ultimate' )
					: esc_html__( 'Pages with Service Content', 'twt-aeo-ultimate' );
				?>
			</h2>

			<?php if ( empty( $results ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty"><?php esc_html_e( 'No pages with service content were detected. Pages with service-related slugs, keywords, or intent classification will appear here.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php else : ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Detection Method', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Keyword Hits', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Service Schema', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results as $item ) :
						$post        = $item['post'];
						$service     = $item['service_data'];
						$row_cls     = $service['needs_schema'] ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
						$has_written = (bool) get_post_meta( $post->ID, TWTAEO_Service_Schema_Writer::META_KEY, true );
						$method_labels = array(
							'intent'      => 'Page Intent',
							'slug'        => 'URL Slug',
							'keywords'    => 'Content Keywords',
							'woocommerce' => 'WooCommerce Product',
						);
						$method_label = $method_labels[ $service['method'] ] ?? $service['method'];
					?>
						<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>" id="twt-aeo-row-<?php echo esc_attr( $post->ID ); ?>">
							<td class="twt-aeo-page-row__title">
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
								<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
								<?php if ( $has_written ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(99,102,241,.1);color:#6366f1;margin-left:4px;"><?php esc_html_e( 'TWT Schema', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="twt-aeo-intent-pill"><?php echo esc_html( $method_label ); ?></span></td>
							<td>
								<?php if ( $service['keyword_hits'] > 0 ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--page"><?php echo esc_html( $service['keyword_hits'] ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $service['has_service_schema'] ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $service['needs_schema'] ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn"><?php esc_html_e( 'Add Service schema', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								·
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link"><?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?></a>
								·
								<button type="button" class="button button-small twt-aeo-generate-schema"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>">
									<?php echo $has_written ? esc_html__( 'Edit Schema', 'twt-aeo-ultimate' ) : esc_html__( 'Generate Schema', 'twt-aeo-ultimate' ); ?>
								</button>
								<?php if ( $has_written ) : ?>
								<button type="button" class="button button-small twt-aeo-delete-schema"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									style="margin-left:4px;color:#b32d2e;">
									<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
								</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</section>

		<!-- Service Schema Modal -->
		<div id="twt-aeo-schema-modal" title="<?php esc_attr_e( 'Generate Service Schema', 'twt-aeo-ultimate' ); ?>" style="display:none;">
			<p style="margin-top:0;color:#646970;font-size:13px;">
				<?php esc_html_e( 'Fill in the fields below. The schema will be output as JSON-LD on the frontend and will not conflict with your SEO plugin.', 'twt-aeo-ultimate' ); ?>
			</p>
			<input type="hidden" id="twt-aeo-modal-post-id" value="" />
			<table class="form-table" style="margin-top:0;">
				<tbody>
					<tr>
						<th scope="row"><label for="twt-aeo-field-name"><?php esc_html_e( 'Service Name', 'twt-aeo-ultimate' ); ?> <span style="color:red;">*</span></label></th>
						<td><input type="text" id="twt-aeo-field-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. CNC Spindle Repair', 'twt-aeo-ultimate' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-field-description"><?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?></label></th>
						<td><textarea id="twt-aeo-field-description" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Describe what this service offers...', 'twt-aeo-ultimate' ); ?>"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-field-service-type"><?php esc_html_e( 'Service Type', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-field-service-type" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Repair, Installation, Consulting', 'twt-aeo-ultimate' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-field-area-served"><?php esc_html_e( 'Area Served', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-field-area-served" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Florida, United States', 'twt-aeo-ultimate' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-field-provider-name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-field-provider-name" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-field-phone"><?php esc_html_e( 'Phone Number', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-field-phone" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. +1-813-555-0100', 'twt-aeo-ultimate' ); ?>" /></td>
					</tr>
				</tbody>
			</table>
			<p id="twt-aeo-modal-message" style="display:none;padding:8px 12px;border-radius:4px;margin-top:12px;"></p>
		</div>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'twtaeo_service_schema_nonce' ) ); ?>';

			$('#twt-aeo-schema-modal').dialog({
				autoOpen:  false,
				modal:     true,
				width:     620,
				resizable: false,
				buttons: [
					{
						text: '<?php echo esc_js( __( 'Save Schema', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button button-primary',
						click: function() { saveSchema(); }
					},
					{
						text: '<?php echo esc_js( __( 'Cancel', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button',
						click: function() { $(this).dialog('close'); }
					}
				]
			});

			$(document).on('click', '.twt-aeo-generate-schema', function() {
				var postId    = $(this).data('post-id');
				var postTitle = $(this).data('post-title');
				$('#twt-aeo-modal-post-id').val(postId);
				$('#twt-aeo-modal-message').hide();
				$('#twt-aeo-field-name').val(postTitle);
				$('#twt-aeo-field-description, #twt-aeo-field-service-type, #twt-aeo-field-area-served, #twt-aeo-field-provider-name, #twt-aeo-field-phone').val('');
				$.post(ajaxurl, { action: 'twtaeo_get_service_prefill', nonce: nonce, post_id: postId }, function(response) {
					if (response.success) {
						var d = response.data;
						if (d.name)          $('#twt-aeo-field-name').val(d.name);
						if (d.description)   $('#twt-aeo-field-description').val(d.description);
						if (d.service_type)  $('#twt-aeo-field-service-type').val(d.service_type);
						if (d.area_served)   $('#twt-aeo-field-area-served').val(d.area_served);
						if (d.provider_name) $('#twt-aeo-field-provider-name').val(d.provider_name);
						if (d.phone)         $('#twt-aeo-field-phone').val(d.phone);
					}
				});
				$('#twt-aeo-schema-modal').dialog('option', 'title', '<?php echo esc_js( __( 'Generate Service Schema', 'twt-aeo-ultimate' ) ); ?>: ' + postTitle).dialog('open');
			});

			function saveSchema() {
				var postId = $('#twt-aeo-modal-post-id').val();
				var name   = $('#twt-aeo-field-name').val().trim();
				if (!name) { showMessage('<?php echo esc_js( __( 'Service Name is required.', 'twt-aeo-ultimate' ) ); ?>', 'error'); return; }
				$.post(ajaxurl, {
					action: 'twtaeo_save_service_schema', nonce: nonce, post_id: postId,
					name: name, description: $('#twt-aeo-field-description').val(),
					service_type: $('#twt-aeo-field-service-type').val(), area_served: $('#twt-aeo-field-area-served').val(),
					provider_name: $('#twt-aeo-field-provider-name').val(), phone: $('#twt-aeo-field-phone').val()
				}, function(response) {
					if (response.success) {
						showMessage('<?php echo esc_js( __( 'Schema saved. It will now appear on the frontend.', 'twt-aeo-ultimate' ) ); ?>', 'success');
						setTimeout(function() { $('#twt-aeo-schema-modal').dialog('close'); location.reload(); }, 1200);
					} else {
						showMessage(response.data || '<?php echo esc_js( __( 'Save failed. Please try again.', 'twt-aeo-ultimate' ) ); ?>', 'error');
					}
				});
			}

			$(document).on('click', '.twt-aeo-delete-schema', function() {
				if (!confirm('<?php echo esc_js( __( 'Remove the TWT-generated Service schema from this page?', 'twt-aeo-ultimate' ) ); ?>')) return;
				var postId = $(this).data('post-id');
				$.post(ajaxurl, { action: 'twtaeo_delete_service_schema', nonce: nonce, post_id: postId }, function(response) {
					if (response.success) location.reload();
				});
			});

			function showMessage(msg, type) {
				$('#twt-aeo-modal-message').text(msg)
					.css('background', type === 'success' ? '#ecfdf5' : '#fef2f2')
					.css('color', type === 'success' ? '#065f46' : '#991b1b')
					.css('border', '1px solid ' + (type === 'success' ? '#a7f3d0' : '#fecaca'))
					.show();
			}
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	// ── Contact tab ──────────────────────────────────────────────────────────

	private static function render_contact() {
		$results = TWTAEO_Contact_Detector::scan_all();
		$summary = TWTAEO_Contact_Detector::get_summary();

		// Business profile defaults for the top panel (shared with Local Pack).
		$lp = class_exists( 'TWTAEO_Local_Pack' ) ? TWTAEO_Local_Pack::get_settings() : array();
		$bp = array(
			'name'           => $lp['business_name']  ?? get_bloginfo( 'name' ),
			'phone'          => $lp['phone']          ?? '',
			'email'          => $lp['email']          ?? get_bloginfo( 'admin_email' ),
			'street_address' => $lp['street_address'] ?? '',
			'city'           => $lp['city']           ?? '',
			'state'          => $lp['state']          ?? '',
			'zip'            => $lp['zip']             ?? '',
			'country'        => $lp['country']        ?? 'US',
		);
		?>

		<section class="twt-aeo-section">
			<div class="twt-aeo-summary-grid">

				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Contact Pages', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $summary['needs_work'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['needs_work'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Need Attention', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['complete'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Complete', 'twt-aeo-ultimate' ); ?></div>
				</div>

			</div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Your Business Info', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note">
					<?php esc_html_e( 'Fill this in once. Use "Create from this info" on any contact page below to add complete Organization, PostalAddress, and ContactPoint schema instantly — no editor needed. Saving also updates your central Local Pack business profile.', 'twt-aeo-ultimate' ); ?>
				</p>
				<table class="form-table" style="margin-top:8px;">
					<tbody>
						<tr>
							<th scope="row"><label for="twt-aeo-bp-name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?> <span style="color:red;">*</span></label></th>
							<td><input type="text" id="twt-aeo-bp-name" class="regular-text" value="<?php echo esc_attr( $bp['name'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="twt-aeo-bp-business-type"><?php esc_html_e( 'Entity Type', 'twt-aeo-ultimate' ); ?></label></th>
							<td>
								<select id="twt-aeo-bp-business-type" class="regular-text">
									<option value="Organization"><?php esc_html_e( 'Organization', 'twt-aeo-ultimate' ); ?></option>
									<option value="LocalBusiness"><?php esc_html_e( 'LocalBusiness', 'twt-aeo-ultimate' ); ?></option>
									<option value="Corporation"><?php esc_html_e( 'Corporation', 'twt-aeo-ultimate' ); ?></option>
									<option value="ProfessionalService"><?php esc_html_e( 'ProfessionalService', 'twt-aeo-ultimate' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="twt-aeo-bp-phone"><?php esc_html_e( 'Phone', 'twt-aeo-ultimate' ); ?></label></th>
							<td><input type="text" id="twt-aeo-bp-phone" class="regular-text" value="<?php echo esc_attr( $bp['phone'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. +1-813-555-0100', 'twt-aeo-ultimate' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="twt-aeo-bp-email"><?php esc_html_e( 'Email', 'twt-aeo-ultimate' ); ?></label></th>
							<td><input type="email" id="twt-aeo-bp-email" class="regular-text" value="<?php echo esc_attr( $bp['email'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="twt-aeo-bp-street"><?php esc_html_e( 'Street Address', 'twt-aeo-ultimate' ); ?></label></th>
							<td><input type="text" id="twt-aeo-bp-street" class="regular-text" value="<?php echo esc_attr( $bp['street_address'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'City / State / ZIP / Country', 'twt-aeo-ultimate' ); ?></label></th>
							<td>
								<input type="text" id="twt-aeo-bp-city"    style="width:30%;" value="<?php echo esc_attr( $bp['city'] ); ?>"    placeholder="<?php esc_attr_e( 'City', 'twt-aeo-ultimate' ); ?>" />
								<input type="text" id="twt-aeo-bp-state"   style="width:14%;" value="<?php echo esc_attr( $bp['state'] ); ?>"   placeholder="<?php esc_attr_e( 'State', 'twt-aeo-ultimate' ); ?>" />
								<input type="text" id="twt-aeo-bp-zip"     style="width:18%;" value="<?php echo esc_attr( $bp['zip'] ); ?>"     placeholder="<?php esc_attr_e( 'ZIP', 'twt-aeo-ultimate' ); ?>" />
								<input type="text" id="twt-aeo-bp-country" style="width:14%;" value="<?php echo esc_attr( $bp['country'] ); ?>" placeholder="<?php esc_attr_e( 'Country', 'twt-aeo-ultimate' ); ?>" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What This Checks', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note"><?php esc_html_e( 'For each contact page, the detector validates:', 'twt-aeo-ultimate' ); ?></p>
				<ul class="twt-aeo-checklist">
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Organization or LocalBusiness schema — confirms your business entity is defined', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'PostalAddress — street, city, state, zip, country', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'ContactPoint — phone number and/or email address', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Entity consolidation — only one Organization entity across the page', 'twt-aeo-ultimate' ); ?></li>
				</ul>
			</div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Contact Pages', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( empty( $results ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty"><?php esc_html_e( 'No contact pages detected. Pages with "contact" in the slug, title, or content will appear here.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php else : ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Organization', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Address', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Contact Point', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Entity', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results as $item ) :
						$post        = $item['post'];
						$contact     = $item['contact_data'];
						$row_cls     = empty( $contact['missing'] ) ? 'twt-aeo-page-row--ok' : 'twt-aeo-page-row--issues';
						$has_written = (bool) TWTAEO_Contact_Schema_Writer::get( $post->ID );
					?>
						<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>" id="twt-aeo-contact-row-<?php echo esc_attr( $post->ID ); ?>">
							<td class="twt-aeo-page-row__title">
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
								<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
								<?php if ( $has_written ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(99,102,241,.1);color:#6366f1;margin-left:4px;"><?php esc_html_e( 'TWT Schema', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $contact['has_organization'] || $contact['has_local_business'] ) : ?>
									<span class="twt-aeo-status-ok">✓</span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $contact['has_postal_address'] ) : ?>
									<span class="twt-aeo-status-ok">✓</span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $contact['has_contact_point'] ) : ?>
									<span class="twt-aeo-status-ok">✓</span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $contact['entity_consolidated'] ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Consolidated', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Duplicate', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( empty( $contact['missing'] ) ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;"><?php esc_html_e( 'Good', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn">
										<?php echo esc_html( count( $contact['missing'] ) . ' ' . _n( 'issue', 'issues', count( $contact['missing'] ), 'twt-aeo-ultimate' ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								·
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link"><?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?></a>
								<br />
								<button type="button" class="button button-small button-primary twt-aeo-contact-create"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									style="margin-top:4px;">
									<?php echo $has_written ? esc_html__( 'Recreate from info', 'twt-aeo-ultimate' ) : esc_html__( 'Create from this info', 'twt-aeo-ultimate' ); ?>
								</button>
								<button type="button" class="button button-small twt-aeo-contact-edit"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
									style="margin-top:4px;">
									<?php esc_html_e( 'Edit in modal', 'twt-aeo-ultimate' ); ?>
								</button>
								<?php if ( $has_written ) : ?>
								<button type="button" class="button button-small twt-aeo-contact-delete"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									style="margin-top:4px;color:#b32d2e;">
									<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
								</button>
								<?php endif; ?>
								<div class="twt-aeo-contact-row-msg" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="margin-top:4px;font-size:12px;"></div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</section>

		<?php if ( $summary['needs_work'] > 0 ) : ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'How to Fix Contact Page Schema', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<ul class="twt-aeo-checklist">
					<?php if ( defined( 'RANK_MATH_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Rank Math: Go to Rank Math → Titles & Meta → Local SEO and fill in your business name, address, phone, and email.', 'twt-aeo-ultimate' ); ?></li>
					<?php elseif ( defined( 'WPSEO_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Yoast SEO: Go to Yoast → Company Info and fill in your organization name, logo, and contact details.', 'twt-aeo-ultimate' ); ?></li>
					<?php elseif ( defined( 'SASWP_VERSION' ) ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Schema & Structured Data for WP & AMP: Open your contact page, go to the Schema tab in the post editor sidebar, add an Organization or LocalBusiness schema type, and fill in your address, phone, and email fields.', 'twt-aeo-ultimate' ); ?></li>
					<?php else : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Use Schema & Structured Data for WP & AMP, Rank Math, or Yoast SEO to add Organization, PostalAddress, and ContactPoint schema to your contact page.', 'twt-aeo-ultimate' ); ?></li>
					<?php endif; ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'For entity consolidation — make sure all schema on the page uses the same @id value for your Organization so AI systems recognize them as the same entity.', 'twt-aeo-ultimate' ); ?></li>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Once saved, TWT AEO Ultimate will automatically rescan and update the status here.', 'twt-aeo-ultimate' ); ?></li>
				</ul>
			</div>
		</section>
		<?php endif; ?>

		<!-- Contact Schema Modal -->
		<div id="twt-aeo-contact-modal" title="<?php esc_attr_e( 'Edit Contact Schema', 'twt-aeo-ultimate' ); ?>" style="display:none;">
			<p style="margin-top:0;color:#646970;font-size:13px;">
				<?php esc_html_e( 'These fields are pre-filled from your business info. Saving writes Organization, PostalAddress, and ContactPoint schema to this page and updates your Local Pack profile.', 'twt-aeo-ultimate' ); ?>
			</p>
			<input type="hidden" id="twt-aeo-cm-post-id" value="" />
			<table class="form-table" style="margin-top:0;">
				<tbody>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?> <span style="color:red;">*</span></label></th>
						<td><input type="text" id="twt-aeo-cm-name" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-business-type"><?php esc_html_e( 'Entity Type', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<select id="twt-aeo-cm-business-type" class="regular-text">
								<option value="Organization"><?php esc_html_e( 'Organization', 'twt-aeo-ultimate' ); ?></option>
								<option value="LocalBusiness"><?php esc_html_e( 'LocalBusiness', 'twt-aeo-ultimate' ); ?></option>
								<option value="Corporation"><?php esc_html_e( 'Corporation', 'twt-aeo-ultimate' ); ?></option>
								<option value="ProfessionalService"><?php esc_html_e( 'ProfessionalService', 'twt-aeo-ultimate' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-phone"><?php esc_html_e( 'Phone', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-cm-phone" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-email"><?php esc_html_e( 'Email', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="email" id="twt-aeo-cm-email" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-contact-type"><?php esc_html_e( 'Contact Type', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-cm-contact-type" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. customer service, sales, support', 'twt-aeo-ultimate' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="twt-aeo-cm-street"><?php esc_html_e( 'Street Address', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="twt-aeo-cm-street" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'City / State / ZIP / Country', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<input type="text" id="twt-aeo-cm-city"    style="width:30%;" placeholder="<?php esc_attr_e( 'City', 'twt-aeo-ultimate' ); ?>" />
							<input type="text" id="twt-aeo-cm-state"   style="width:14%;" placeholder="<?php esc_attr_e( 'State', 'twt-aeo-ultimate' ); ?>" />
							<input type="text" id="twt-aeo-cm-zip"     style="width:18%;" placeholder="<?php esc_attr_e( 'ZIP', 'twt-aeo-ultimate' ); ?>" />
							<input type="text" id="twt-aeo-cm-country" style="width:14%;" placeholder="<?php esc_attr_e( 'Country', 'twt-aeo-ultimate' ); ?>" />
						</td>
					</tr>
				</tbody>
			</table>
			<p id="twt-aeo-cm-message" style="display:none;padding:8px 12px;border-radius:4px;margin-top:12px;"></p>
		</div>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'twtaeo_contact_schema_nonce' ) ); ?>';

			// Collect the shared "Your Business Info" panel values.
			function panelData() {
				return {
					name:           $('#twt-aeo-bp-name').val(),
					business_type:  $('#twt-aeo-bp-business-type').val(),
					phone:          $('#twt-aeo-bp-phone').val(),
					email:          $('#twt-aeo-bp-email').val(),
					contact_type:   'customer service',
					street_address: $('#twt-aeo-bp-street').val(),
					city:           $('#twt-aeo-bp-city').val(),
					state:          $('#twt-aeo-bp-state').val(),
					zip:            $('#twt-aeo-bp-zip').val(),
					country:        $('#twt-aeo-bp-country').val()
				};
			}

			function rowMsg(postId, msg, ok) {
				$('.twt-aeo-contact-row-msg[data-post-id="' + postId + '"]')
					.css('color', ok ? '#16a34a' : '#dc2626').text(msg);
			}

			// "Create from this info" — save straight from the top panel, no modal.
			$(document).on('click', '.twt-aeo-contact-create', function() {
				var btn    = $(this);
				var postId = btn.data('post-id');
				var data   = panelData();
				if (!data.name) { rowMsg(postId, '<?php echo esc_js( __( 'Business Name is required (top panel).', 'twt-aeo-ultimate' ) ); ?>', false); return; }
				data.action  = 'twtaeo_save_contact_schema';
				data.nonce   = nonce;
				data.post_id = postId;
				btn.prop('disabled', true);
				rowMsg(postId, '<?php echo esc_js( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>', true);
				$.post(ajaxurl, data, function(r) {
					if (r.success) {
						rowMsg(postId, '<?php echo esc_js( __( '✓ Schema added — reloading…', 'twt-aeo-ultimate' ) ); ?>', true);
						setTimeout(function() { location.reload(); }, 900);
					} else {
						btn.prop('disabled', false);
						rowMsg(postId, '✗ ' + (r.data || '<?php echo esc_js( __( 'Save failed.', 'twt-aeo-ultimate' ) ); ?>'), false);
					}
				}).fail(function() {
					btn.prop('disabled', false);
					rowMsg(postId, '<?php echo esc_js( __( '✗ Request failed.', 'twt-aeo-ultimate' ) ); ?>', false);
				});
			});

			// Modal dialog.
			$('#twt-aeo-contact-modal').dialog({
				autoOpen:  false,
				modal:     true,
				width:     620,
				resizable: false,
				buttons: [
					{
						text: '<?php echo esc_js( __( 'Save Schema', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button button-primary',
						click: function() { saveContactSchema(); }
					},
					{
						text: '<?php echo esc_js( __( 'Cancel', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button',
						click: function() { $(this).dialog('close'); }
					}
				]
			});

			$(document).on('click', '.twt-aeo-contact-edit', function() {
				var postId    = $(this).data('post-id');
				var postTitle = $(this).data('post-title');
				$('#twt-aeo-cm-post-id').val(postId);
				$('#twt-aeo-cm-message').hide();

				// Seed from the top panel immediately, then let the server merge saved/Local Pack values.
				var p = panelData();
				$('#twt-aeo-cm-name').val(p.name);
				$('#twt-aeo-cm-business-type').val(p.business_type);
				$('#twt-aeo-cm-phone').val(p.phone);
				$('#twt-aeo-cm-email').val(p.email);
				$('#twt-aeo-cm-contact-type').val(p.contact_type);
				$('#twt-aeo-cm-street').val(p.street_address);
				$('#twt-aeo-cm-city').val(p.city);
				$('#twt-aeo-cm-state').val(p.state);
				$('#twt-aeo-cm-zip').val(p.zip);
				$('#twt-aeo-cm-country').val(p.country);

				$.post(ajaxurl, { action: 'twtaeo_get_contact_prefill', nonce: nonce, post_id: postId }, function(r) {
					if (r.success) {
						var d = r.data;
						if (d.name)           $('#twt-aeo-cm-name').val(d.name);
						if (d.business_type)  $('#twt-aeo-cm-business-type').val(d.business_type);
						if (d.phone)          $('#twt-aeo-cm-phone').val(d.phone);
						if (d.email)          $('#twt-aeo-cm-email').val(d.email);
						if (d.contact_type)   $('#twt-aeo-cm-contact-type').val(d.contact_type);
						if (d.street_address) $('#twt-aeo-cm-street').val(d.street_address);
						if (d.city)           $('#twt-aeo-cm-city').val(d.city);
						if (d.state)          $('#twt-aeo-cm-state').val(d.state);
						if (d.zip)            $('#twt-aeo-cm-zip').val(d.zip);
						if (d.country)        $('#twt-aeo-cm-country').val(d.country);
					}
				});

				$('#twt-aeo-contact-modal')
					.dialog('option', 'title', '<?php echo esc_js( __( 'Edit Contact Schema', 'twt-aeo-ultimate' ) ); ?>: ' + postTitle)
					.dialog('open');
			});

			function saveContactSchema() {
				var name = $('#twt-aeo-cm-name').val().trim();
				if (!name) { showCmMessage('<?php echo esc_js( __( 'Business Name is required.', 'twt-aeo-ultimate' ) ); ?>', 'error'); return; }
				$.post(ajaxurl, {
					action: 'twtaeo_save_contact_schema', nonce: nonce,
					post_id:        $('#twt-aeo-cm-post-id').val(),
					name:           name,
					business_type:  $('#twt-aeo-cm-business-type').val(),
					phone:          $('#twt-aeo-cm-phone').val(),
					email:          $('#twt-aeo-cm-email').val(),
					contact_type:   $('#twt-aeo-cm-contact-type').val(),
					street_address: $('#twt-aeo-cm-street').val(),
					city:           $('#twt-aeo-cm-city').val(),
					state:          $('#twt-aeo-cm-state').val(),
					zip:            $('#twt-aeo-cm-zip').val(),
					country:        $('#twt-aeo-cm-country').val()
				}, function(r) {
					if (r.success) {
						showCmMessage('<?php echo esc_js( __( 'Schema saved. It will now appear on the frontend.', 'twt-aeo-ultimate' ) ); ?>', 'success');
						setTimeout(function() { $('#twt-aeo-contact-modal').dialog('close'); location.reload(); }, 1200);
					} else {
						showCmMessage(r.data || '<?php echo esc_js( __( 'Save failed. Please try again.', 'twt-aeo-ultimate' ) ); ?>', 'error');
					}
				});
			}

			$(document).on('click', '.twt-aeo-contact-delete', function() {
				if (!confirm('<?php echo esc_js( __( 'Remove the TWT-generated contact schema from this page?', 'twt-aeo-ultimate' ) ); ?>')) return;
				var postId = $(this).data('post-id');
				$.post(ajaxurl, { action: 'twtaeo_delete_contact_schema', nonce: nonce, post_id: postId }, function(r) {
					if (r.success) location.reload();
				});
			});

			function showCmMessage(msg, type) {
				$('#twt-aeo-cm-message').text(msg)
					.css('background', type === 'success' ? '#ecfdf5' : '#fef2f2')
					.css('color', type === 'success' ? '#065f46' : '#991b1b')
					.css('border', '1px solid ' + (type === 'success' ? '#a7f3d0' : '#fecaca'))
					.show();
			}
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
		?>
		<?php
	}
}
