<?php
/**
 * Image SEO Page
 *
 * Lists posts/pages that contain images and lets the user fill missing alt text
 * via AI vision (per row) or manually through an edit modal. WooCommerce
 * products are excluded — they are handled on the WooCommerce page.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Image_SEO {

	const NONCE = 'twtaeo_img_nonce';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$rows  = TWTAEO_Image_Optimizer::scan();
		$nonce = wp_create_nonce( self::NONCE );

		$total_imgs   = 0;
		$missing_imgs = 0;
		$no_image     = 0;
		foreach ( $rows as $r ) {
			$total_imgs   += $r['summary']['total'];
			$missing_imgs += $r['summary']['missing'];
			if ( 0 === $r['summary']['total'] ) {
				$no_image++;
			}
		}

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		// "Add an image" opens the native WordPress media modal and sets the
		// chosen attachment as the page's featured image.
		wp_enqueue_media();
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Image SEO', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<div style="display:flex;align-items:center;gap:12px;margin:0 0 16px;flex-wrap:wrap;">
				<p style="margin:0;color:#50575e;">
					<?php
					printf(
						/* translators: %1$d: pages scanned, %2$d: images missing alt text, %3$d: pages with no images at all. */
						esc_html__( '%1$d pages scanned — %2$d images missing alt text, %3$d pages have no images at all', 'twt-aeo-ultimate' ),
						count( $rows ),
						absint( $missing_imgs ),
						absint( $no_image )
					); ?>
				</p>
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<p style="margin:0;color:#646970;font-size:12px;">
					<?php
					printf(
						/* translators: %s: link to the WooCommerce products tab. */
						esc_html__( 'Product images are audited on the %s tab.', 'twt-aeo-ultimate' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=twt-aeo-woocommerce' ) ) . '">' . esc_html__( 'WooCommerce', 'twt-aeo-ultimate' ) . '</a>'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link assembled from escaped parts.
					?>
				</p>
				<?php endif; ?>
			</div>

			<?php if ( $missing_imgs > 0 ) : ?>
			<!-- Bulk AI alt text -->
			<div class="twt-aeo-card" style="border-left:3px solid #2271b1;margin-bottom:16px;">
				<p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;max-width:820px;">
					<strong><?php esc_html_e( 'Fill all missing alt text with AI', 'twt-aeo-ultimate' ); ?></strong> —
					<?php esc_html_e( 'runs AI vision over every page listed below that still has images without alt text, instead of clicking each row one by one. Images that already have alt text are never touched.', 'twt-aeo-ultimate' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
					<button type="button" class="button button-primary" id="twt-aeo-img-gen-bulk">
						<?php
						printf(
							/* translators: %1$d: images missing alt text. %2$d: pages affected. */
							esc_html__( 'Generate all missing alt text (%1$d images across %2$d pages)', 'twt-aeo-ultimate' ),
							absint( $missing_imgs ),
							absint( count( array_filter( $rows, function( $r ) { return $r['summary']['missing'] > 0; } ) ) )
						);
						?>
					</button>
					<button type="button" class="button" id="twt-aeo-img-gen-bulk-stop" style="display:none;">
						<?php esc_html_e( 'Stop', 'twt-aeo-ultimate' ); ?>
					</button>
					<span id="twt-aeo-img-gen-bulk-status" style="font-size:12px;color:#646970;"></span>
				</div>
				<p style="margin:10px 0 0;font-size:13px;color:#646970;line-height:1.55;">
					<?php esc_html_e( 'This uses AI: one billed API call per image that is missing alt text. You will be asked to confirm the count before anything runs, and you can stop at any time — pages already processed keep their new alt text.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<section class="twt-aeo-section">
				<?php if ( empty( $rows ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty"><?php esc_html_e( 'No published posts or pages with images were found.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				<?php else : ?>
				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Type', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Images', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Alt Coverage', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $r ) :
							$post     = $r['post'];
							$summary  = $r['summary'];
							$row_cls  = ( $summary['missing'] > 0 || 0 === $summary['total'] ) ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
							$edit_url = get_edit_post_link( $post->ID, 'raw' );
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-missing="<?php echo esc_attr( $summary['missing'] ); ?>">
								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
								</td>
								<td><span class="twt-aeo-intent-pill"><?php echo esc_html( ucfirst( $post->post_type ) ); ?></span></td>
								<td><?php echo esc_html( $summary['total'] ); ?></td>
								<td class="twt-aeo-img-cov-cell">
									<?php echo wp_kses_post( TWTAEO_Product_Enricher::alt_badge_html( $summary ) ); ?>
								</td>
								<td class="twt-aeo-img-actions-cell" style="white-space:nowrap;">
									<?php if ( 0 === $summary['total'] ) : ?>
										<button type="button" class="button button-primary twt-aeo-img-add-btn"
											data-post-id="<?php echo esc_attr( $post->ID ); ?>"
											data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>">
											<?php esc_html_e( 'Add an image', 'twt-aeo-ultimate' ); ?>
										</button>
										<span class="twt-aeo-img-status" style="margin-left:6px;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Less data = fewer citations. An image plus its description is content an answer engine can use; this page gives it neither.', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<button type="button" class="button button-primary twt-aeo-img-gen-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
											<?php esc_html_e( 'Generate alt (AI)', 'twt-aeo-ultimate' ); ?>
										</button>
										<button type="button" class="button twt-aeo-img-edit-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>">
											<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
										</button>
										<span class="twt-aeo-img-status" style="margin-left:6px;font-size:12px;color:#6b7280;"></span>
									<?php endif; ?>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link" style="margin-left:6px;"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<!-- Edit dialog -->
			<div id="twt-aeo-img-dialog" style="display:none;">
				<div id="twt-aeo-img-dialog-body"></div>
				<div id="twt-aeo-img-dialog-msg" style="display:none;padding:8px 12px;margin-top:8px;"></div>
			</div>

		</div><!-- .twt-aeo-wrap -->

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			var ajaxurl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var imgNonce = <?php echo wp_json_encode( $nonce ); ?>;
			var dlgPostId = null;

			var $dlg = $('#twt-aeo-img-dialog');
			$dlg.dialog({
				autoOpen: false, modal: true, width: 640,
				maxHeight: Math.floor(window.innerHeight * 0.85), title: '',
				buttons: [
					{ id: 'twt-aeo-img-save', text: 'Save Alt Text', 'class': 'button button-primary', click: doSave },
					{ text: 'Cancel', 'class': 'button', click: function() { $dlg.dialog('close'); } }
				],
				open: function() { $('.ui-dialog').css('max-width', 'calc(100vw - 40px)'); }
			});

			function esc(s) {
				return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}
			function setStatus($row, text, color) {
				$row.find('.twt-aeo-img-status').css('color', color || '#6b7280').text(text);
			}

			// Bulk AI generation — sequential, one billed call per page, stoppable.
			var bulkStopped = false;
			$('#twt-aeo-img-gen-bulk').on('click', function() {
				var $btn    = $(this);
				var $stop   = $('#twt-aeo-img-gen-bulk-stop');
				var $status = $('#twt-aeo-img-gen-bulk-status');

				var ids = [], imgCount = 0;
				$('tr.twt-aeo-page-row').each(function() {
					var m = parseInt($(this).attr('data-missing'), 10) || 0;
					if ( m > 0 ) {
						ids.push( $(this).data('post-id') );
						imgCount += m;
					}
				});

				if ( ! ids.length ) {
					$status.css('color', '#16a34a').text('<?php echo esc_js( __( 'Nothing to generate — all images have alt text.', 'twt-aeo-ultimate' ) ); ?>');
					return;
				}

				var confirmMsg = '<?php
					/* translators: %1$d: number of images missing alt text. %2$d: number of pages they are on. */
					echo esc_js( __( 'This will run AI vision over %1$d image(s) across %2$d page(s) — one billed AI call per image. Continue?', 'twt-aeo-ultimate' ) );
				?>'
					.replace('%1$d', imgCount).replace('%2$d', ids.length);
				if ( ! window.confirm(confirmMsg) ) { return; }

				bulkStopped = false;
				$btn.prop('disabled', true);
				$stop.show();
				var done = 0, filled = 0, failed = 0, total = ids.length;

				function finish(stopped) {
					$btn.prop('disabled', false);
					$stop.hide();
					$status.css('color', failed ? '#d63638' : '#16a34a').text(
						( stopped ? '<?php echo esc_js( __( 'Stopped', 'twt-aeo-ultimate' ) ); ?>' : '<?php echo esc_js( __( 'Done', 'twt-aeo-ultimate' ) ); ?>' ) +
						' — ' + '<?php /* translators: 1: number of images given alt text, 2: number of pages processed. */ echo esc_js( __( 'images filled: %1$d, pages: %2$d', 'twt-aeo-ultimate' ) ); ?>'.split('%1$d').join(filled).split('%2$d').join(done) +
						( failed ? ', ' + '<?php /* translators: %d: number of pages that failed. */ echo esc_js( __( 'failed: %d', 'twt-aeo-ultimate' ) ); ?>'.split('%d').join(failed) : '' )
					);
				}

				function processNext() {
					if ( bulkStopped ) { finish(true); return; }
					if ( ! ids.length ) { finish(false); return; }

					var postId = ids.shift();
					var $row   = $('tr[data-post-id="' + postId + '"]');
					$status.css('color', '#646970').text(
						'<?php echo esc_js( __( 'Processing page', 'twt-aeo-ultimate' ) ); ?> ' + ( total - ids.length ) + ' / ' + total + '…'
					);
					setStatus($row, 'Generating…', '#6b7280');

					$.post(ajaxurl, { action: 'twtaeo_img_generate_alt', nonce: imgNonce, post_id: postId }, function(resp) {
						if ( resp.success ) {
							done++;
							filled += parseInt(resp.data.filled, 10) || 0;
							if ( resp.data.cov_html ) { $row.find('.twt-aeo-img-cov-cell').html(resp.data.cov_html); }
							$row.attr('data-missing', '0').data('missing', 0);
							setStatus($row, 'Filled ' + resp.data.filled + ' image(s).', '#16a34a');
						} else {
							failed++;
							setStatus($row, resp.data || 'Failed.', '#d63638');
						}
						processNext();
					}).fail(function() {
						failed++;
						setStatus($row, 'Request failed.', '#d63638');
						processNext();
					});
				}

				processNext();
			});

			$('#twt-aeo-img-gen-bulk-stop').on('click', function() {
				bulkStopped = true;
				$(this).prop('disabled', true).text('<?php echo esc_js( __( 'Stopping…', 'twt-aeo-ultimate' ) ); ?>');
				var self = this;
				setTimeout(function(){ $(self).prop('disabled', false).text('<?php echo esc_js( __( 'Stop', 'twt-aeo-ultimate' ) ); ?>'); }, 1500);
			});

			// Per-row AI generation.
			$(document).on('click', '.twt-aeo-img-gen-btn', function() {
				var $btn = $(this), postId = $btn.data('post-id'), $row = $btn.closest('tr');
				$btn.prop('disabled', true);
				setStatus($row, 'Generating…', '#6b7280');
				$.post(ajaxurl, { action: 'twtaeo_img_generate_alt', nonce: imgNonce, post_id: postId }, function(resp) {
					$btn.prop('disabled', false);
					if (!resp.success) { setStatus($row, resp.data || 'Failed.', '#d63638'); return; }
					var d = resp.data;
					if (d.cov_html) { $row.find('.twt-aeo-img-cov-cell').html(d.cov_html); }
					setStatus($row, 'Filled ' + d.filled + ' image(s)' + (d.skipped ? ', ' + d.skipped + ' already set' : '') + '.', '#16a34a');
				}).fail(function() { $btn.prop('disabled', false); setStatus($row, 'Request failed.', '#d63638'); });
			});

			// Manual edit modal.
			$(document).on('click', '.twt-aeo-img-edit-btn', function() {
				dlgPostId = $(this).data('post-id');
				var title = $(this).data('title');
				$('#twt-aeo-img-dialog-msg').hide();
				$('#twt-aeo-img-dialog-body').html('<p style="padding:12px 0;">Loading…</p>');
				$dlg.dialog('option', 'title', 'Image alt text — ' + title).dialog('open');
				$.post(ajaxurl, { action: 'twtaeo_img_get_post_images', nonce: imgNonce, post_id: dlgPostId }, function(resp) {
					if (!resp.success) { $('#twt-aeo-img-dialog-body').html('<p style="color:#d63638;">' + esc(resp.data) + '</p>'); return; }
					var imgs = resp.data.images || [];
					if (!imgs.length) { $('#twt-aeo-img-dialog-body').html('<p>No images found.</p>'); return; }
					var html = '';
					imgs.forEach(function(im) {
						html += '<div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f0f0f1;">' +
							'<img src="' + esc(im.thumb) + '" alt="" style="width:64px;height:64px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;flex-shrink:0;">' +
							'<div style="flex:1;min-width:0;">' +
								'<div style="font-size:11px;color:#6b7280;margin-bottom:4px;">' + esc(im.context) + '</div>' +
								'<input type="text" class="large-text twt-aeo-img-alt" data-key="' + esc(im.key) + '" value="' + esc(im.alt) + '" placeholder="Describe this image…">' +
							'</div>' +
						'</div>';
					});
					$('#twt-aeo-img-dialog-body').html(html);
				}).fail(function() { $('#twt-aeo-img-dialog-body').html('<p style="color:#d63638;">Request failed.</p>'); });
			});

			// "Add an image" — native WordPress media modal; the chosen image
			// becomes the page's featured image.
			var mediaFrame = null, mediaPostId = null, mediaTitle = '';
			$(document).on('click', '.twt-aeo-img-add-btn', function() {
				mediaPostId = $(this).data('post-id');
				mediaTitle  = $(this).data('title');
				if ( ! window.wp || ! wp.media ) {
					window.alert('<?php echo esc_js( __( 'The WordPress media library could not be loaded on this screen.', 'twt-aeo-ultimate' ) ); ?>');
					return;
				}
				if ( ! mediaFrame ) {
					mediaFrame = wp.media({
						title: '<?php echo esc_js( __( 'Choose an image for this page', 'twt-aeo-ultimate' ) ); ?>',
						library: { type: 'image' },
						multiple: false,
						button: { text: '<?php echo esc_js( __( 'Use this image', 'twt-aeo-ultimate' ) ); ?>' }
					});
					mediaFrame.on('select', function() {
						var att  = mediaFrame.state().get('selection').first().toJSON();
						var $row = $('tr[data-post-id="' + mediaPostId + '"]');
						setStatus($row, '<?php echo esc_js( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>', '#6b7280');
						$.post(ajaxurl, {
							action: 'twtaeo_img_set_featured',
							nonce: imgNonce,
							post_id: mediaPostId,
							attachment_id: att.id
						}, function(resp) {
							if (!resp.success) { setStatus($row, resp.data || 'Failed.', '#d63638'); return; }
							var d = resp.data;
							$row.find('td').eq(2).text(d.total);
							if (d.cov_html) { $row.find('.twt-aeo-img-cov-cell').html(d.cov_html); }
							$row.attr('data-missing', d.missing).data('missing', d.missing);
							// The page has an image now — swap "Add an image" for the
							// standard Generate / Edit actions, keeping the View link.
							var $cell = $row.find('.twt-aeo-img-actions-cell');
							var $view = $cell.find('a.twt-aeo-link').last().detach();
							$cell.html(
								'<button type="button" class="button button-primary twt-aeo-img-gen-btn" data-post-id="' + mediaPostId + '"><?php echo esc_js( __( 'Generate alt (AI)', 'twt-aeo-ultimate' ) ); ?></button> ' +
								'<button type="button" class="button twt-aeo-img-edit-btn" data-post-id="' + mediaPostId + '" data-title="' + esc(mediaTitle) + '"><?php echo esc_js( __( 'Edit', 'twt-aeo-ultimate' ) ); ?></button>' +
								'<span class="twt-aeo-img-status" style="margin-left:6px;font-size:12px;color:#16a34a;"><?php echo esc_js( __( 'Featured image set.', 'twt-aeo-ultimate' ) ); ?></span>'
							);
							if ($view.length) { $cell.append($view.css('margin-left', '6px')); }
						}).fail(function() { setStatus($row, 'Request failed.', '#d63638'); });
					});
				}
				mediaFrame.open();
			});

			function doSave() {
				var data = { action: 'twtaeo_img_save_alt', nonce: imgNonce, post_id: dlgPostId };
				$('#twt-aeo-img-dialog-body .twt-aeo-img-alt').each(function() {
					data['alts[' + $(this).data('key') + ']'] = $(this).val();
				});
				var $btn = $('#twt-aeo-img-save').prop('disabled', true).text('Saving…');
				$.post(ajaxurl, data, function(resp) {
					$btn.prop('disabled', false).text('Save Alt Text');
					var $msg = $('#twt-aeo-img-dialog-msg');
					if (!resp.success) {
						$msg.css({ background:'#fce8e8','border-left':'3px solid #d63638' }).text(resp.data || 'Save failed.').show();
						return;
					}
					$msg.css({ background:'#edfaef','border-left':'3px solid #16a34a' }).text('Saved.').show();
					var $row = $('tr[data-post-id="' + dlgPostId + '"]');
					if (resp.data.cov_html) { $row.find('.twt-aeo-img-cov-cell').html(resp.data.cov_html); }
					setTimeout(function() { $dlg.dialog('close'); }, 700);
				}).fail(function() { $btn.prop('disabled', false).text('Save Alt Text'); });
			}
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
