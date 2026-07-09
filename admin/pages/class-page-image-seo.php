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
		foreach ( $rows as $r ) {
			$total_imgs   += $r['summary']['total'];
			$missing_imgs += $r['summary']['missing'];
		}

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
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

			<div style="display:flex;align-items:center;gap:12px;margin:0 0 16px;">
				<p style="margin:0;color:#50575e;">
					<?php
					printf(
						/* translators: %1$d: pages with images, %2$d: images missing alt text. */
						esc_html__( '%1$d pages with images — %2$d images missing alt text', 'twt-aeo-ultimate' ),
						count( $rows ),
						absint( $missing_imgs )
					); ?>
				</p>
			</div>

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
							$row_cls  = $summary['missing'] > 0 ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
							$edit_url = get_edit_post_link( $post->ID, 'raw' );
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
								</td>
								<td><span class="twt-aeo-intent-pill"><?php echo esc_html( ucfirst( $post->post_type ) ); ?></span></td>
								<td><?php echo esc_html( $summary['total'] ); ?></td>
								<td class="twt-aeo-img-cov-cell">
									<?php echo wp_kses_post( TWTAEO_Product_Enricher::alt_badge_html( $summary ) ); ?>
								</td>
								<td class="twt-aeo-img-actions-cell" style="white-space:nowrap;">
									<button type="button" class="button button-primary twt-aeo-img-gen-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
										<?php esc_html_e( 'Generate alt (AI)', 'twt-aeo-ultimate' ); ?>
									</button>
									<button type="button" class="button twt-aeo-img-edit-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>">
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</button>
									<span class="twt-aeo-img-status" style="margin-left:6px;font-size:12px;color:#6b7280;"></span>
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
