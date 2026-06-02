<?php
/**
 * Service Detector Page
 *
 * Shows all pages/posts/products with service content and whether
 * Service schema is present. Includes a modal for generating Service
 * schema on pages where Yoast or no SEO plugin covers it.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Service_Detector {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$include_posts = isset( $_GET['include_posts'] ) && '1' === sanitize_key( wp_unslash( $_GET['include_posts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$results       = TWTAEO_Service_Detector::scan_all( $include_posts );
		$summary       = TWTAEO_Service_Detector::get_summary( $include_posts );

		$base_url    = admin_url( 'admin.php?page=twt-aeo-service-detector' );
		$toggle_url  = $include_posts ? $base_url : add_query_arg( 'include_posts', '1', $base_url );
		$toggle_text = $include_posts
			? __( 'Showing Pages + Posts — Show Pages Only', 'twt-aeo-ultimate' )
			: __( 'Show Blog Posts Too', 'twt-aeo-ultimate' );

		// Enqueue jQuery UI dialog for the modal.
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Service Detector', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
						<p class="twt-aeo-header__sub" style="margin:0;">
							<?php
							printf(
								// translators: %1$d: count of pages/posts. %2$s: 'pages' or 'pages/posts'. %3$d: count missing schema.
								esc_html__( '%1$d %2$s with service content — %3$d missing Service schema', 'twt-aeo-ultimate' ),
								absint( $summary['total_with_service'] ),
								$include_posts ? esc_html__( 'pages/posts', 'twt-aeo-ultimate' ) : esc_html__( 'pages', 'twt-aeo-ultimate' ),
								absint( $summary['needs_schema'] )
							);
							?>
						</p>
						<a href="<?php echo esc_url( $toggle_url ); ?>" class="button <?php echo $include_posts ? 'button-primary' : ''; ?>">
							<?php echo esc_html( $toggle_text ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
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

			<!-- Detection Methods -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Detection Methods', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'This detector identifies service-oriented content using four strategies, in order:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Page intent classifier — pages classified as Service Page or Product-Service Page', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'URL slug — contains service, repair, install, maintenance, consulting', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Content keywords — service-related terms in headings and first 300 words (3+ required)', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'WooCommerce products — only products whose title, slug, or content contains service keywords (not all products)', 'twt-aeo-ultimate' ); ?>
						</li>
					</ul>
				</div>
			</section>

			<!-- Results Table -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
				<?php echo $include_posts
					? esc_html__( 'Pages &amp; Posts with Service Content', 'twt-aeo-ultimate' )
					: esc_html__( 'Pages with Service Content', 'twt-aeo-ultimate' );
				?>
			</h2>

				<?php if ( empty( $results ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty">
							<?php esc_html_e( 'No pages with service content were detected. Pages with service-related slugs, keywords, or intent classification will appear here.', 'twt-aeo-ultimate' ); ?>
						</p>
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
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $post ) ); ?>
									</a>
									<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
									<?php if ( $has_written ) : ?>
										<span class="twt-aeo-badge" style="background:rgba(99,102,241,.1);color:#6366f1;margin-left:4px;">
											<?php esc_html_e( 'TWT Schema', 'twt-aeo-ultimate' ); ?>
										</span>
									<?php endif; ?>
								</td>

								<td>
									<span class="twt-aeo-intent-pill"><?php echo esc_html( $method_label ); ?></span>
								</td>

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
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link">
										<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
									</a>
									·
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link">
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</a>
									·
									<button
										type="button"
										class="button button-small twt-aeo-generate-schema"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
									>
										<?php echo $has_written ? esc_html__( 'Edit Schema', 'twt-aeo-ultimate' ) : esc_html__( 'Generate Schema', 'twt-aeo-ultimate' ); ?>
									</button>
									<?php if ( $has_written ) : ?>
									<button
										type="button"
										class="button button-small twt-aeo-delete-schema"
										data-post-id="<?php echo esc_attr( $post->ID ); ?>"
										style="margin-left:4px;color:#b32d2e;"
									>
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

		</div>

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

			// Initialise the dialog.
			$('#twt-aeo-schema-modal').dialog({
				autoOpen:  false,
				modal:     true,
				width:     620,
				resizable: false,
				buttons: [
					{
						text: '<?php echo esc_js( __( 'Save Schema', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button button-primary',
						click: function() {
							saveSchema();
						}
					},
					{
						text: '<?php echo esc_js( __( 'Cancel', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button',
						click: function() {
							$(this).dialog('close');
						}
					}
				]
			});

			// Open modal and pre-fill fields.
			$(document).on('click', '.twt-aeo-generate-schema', function() {
				var postId    = $(this).data('post-id');
				var postTitle = $(this).data('post-title');

				$('#twt-aeo-modal-post-id').val(postId);
				$('#twt-aeo-modal-message').hide();

				// Reset fields first.
				$('#twt-aeo-field-name').val(postTitle);
				$('#twt-aeo-field-description, #twt-aeo-field-service-type, #twt-aeo-field-area-served, #twt-aeo-field-provider-name, #twt-aeo-field-phone').val('');

				// Fetch pre-fill data via AJAX.
				$.post(ajaxurl, {
					action:  'twtaeo_get_service_prefill',
					nonce:   nonce,
					post_id: postId
				}, function(response) {
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

				$('#twt-aeo-schema-modal')
					.dialog('option', 'title', '<?php echo esc_js( __( 'Generate Service Schema', 'twt-aeo-ultimate' ) ); ?>: ' + postTitle)
					.dialog('open');
			});

			// Save schema via AJAX.
			function saveSchema() {
				var postId = $('#twt-aeo-modal-post-id').val();
				var name   = $('#twt-aeo-field-name').val().trim();

				if (!name) {
					showMessage('<?php echo esc_js( __( 'Service Name is required.', 'twt-aeo-ultimate' ) ); ?>', 'error');
					return;
				}

				$.post(ajaxurl, {
					action:        'twtaeo_save_service_schema',
					nonce:         nonce,
					post_id:       postId,
					name:          name,
					description:   $('#twt-aeo-field-description').val(),
					service_type:  $('#twt-aeo-field-service-type').val(),
					area_served:   $('#twt-aeo-field-area-served').val(),
					provider_name: $('#twt-aeo-field-provider-name').val(),
					phone:         $('#twt-aeo-field-phone').val()
				}, function(response) {
					if (response.success) {
						showMessage('<?php echo esc_js( __( 'Schema saved. It will now appear on the frontend.', 'twt-aeo-ultimate' ) ); ?>', 'success');
						setTimeout(function() {
							$('#twt-aeo-schema-modal').dialog('close');
							location.reload();
						}, 1200);
					} else {
						showMessage(response.data || '<?php echo esc_js( __( 'Save failed. Please try again.', 'twt-aeo-ultimate' ) ); ?>', 'error');
					}
				});
			}

			// Delete schema.
			$(document).on('click', '.twt-aeo-delete-schema', function() {
				if (!confirm('<?php echo esc_js( __( 'Remove the TWT-generated Service schema from this page?', 'twt-aeo-ultimate' ) ); ?>')) {
					return;
				}
				var postId = $(this).data('post-id');
				$.post(ajaxurl, {
					action:  'twtaeo_delete_service_schema',
					nonce:   nonce,
					post_id: postId
				}, function(response) {
					if (response.success) {
						location.reload();
					}
				});
			});

			function showMessage(msg, type) {
				var $m = $('#twt-aeo-modal-message');
				$m.text(msg)
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
}