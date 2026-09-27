<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Page_Not_Indexed {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$scan_results   = TWTAEO_Index_Status::get_results();
		$not_indexed    = array();

		foreach ( $scan_results as $post_id => $result ) {
			// Cart/checkout-style utility pages are noindex by design — skip
			// stored rows too, so sites that already scanned them stay clean.
			if ( TWTAEO_Index_Status::is_excluded_page( $post_id ) ) {
				continue;
			}
			if ( ! empty( $result['gsc']['not_indexed'] ) ) {
				$not_indexed[] = array(
					'post_id'        => (int) $post_id,
					'title'          => get_the_title( (int) $post_id ),
					'url'            => $result['gsc']['url']            ?? get_permalink( (int) $post_id ),
					'coverage_state' => $result['gsc']['coverage_state'] ?? '',
					'last_crawl'     => $result['gsc']['last_crawl']     ?? '',
					'heuristics'     => $result['heuristics']            ?? array(),
					'scanned_at'     => $result['scanned_at']            ?? 0,
				);
			}
		}

		$total_count = count( $not_indexed );

		$gsc_connected = (bool) TWTAEO_Google_OAuth::get_access_token();
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Not Indexed Pages', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div class="twt-aeo-header__meta">
						<?php if ( $total_count > 0 ) : ?>
						<span class="twt-aeo-badge twt-aeo-badge--alert">
							<?php echo esc_html( $total_count ); ?> <?php esc_html_e( 'pages not indexed', 'twt-aeo-ultimate' ); ?>
						</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php if ( ! $gsc_connected ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card" style="border-left:4px solid #f59e0b;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Google Search Console Not Connected', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'Connect Google Search Console to detect pages that are published but not appearing in Google search results. Once connected, run a scan from the Command Center to populate this report.', 'twt-aeo-ultimate' ); ?></p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-command-center' ) ); ?>" class="twt-aeo-btn twt-aeo-btn--primary">
							<?php esc_html_e( '→ Connect in Command Center', 'twt-aeo-ultimate' ); ?>
						</a>
					</p>
				</div>
			</section>
			<?php elseif ( empty( $scan_results ) ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card">
					<h2 style="margin-top:0;"><?php esc_html_e( 'No Scan Data Yet', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'GSC is connected but no URL inspection results are stored. Go to the Command Center and run an index status scan to identify not-indexed pages.', 'twt-aeo-ultimate' ); ?></p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-command-center' ) ); ?>" class="twt-aeo-btn twt-aeo-btn--primary">
							<?php esc_html_e( '→ Run Scan in Command Center', 'twt-aeo-ultimate' ); ?>
						</a>
					</p>
				</div>
			</section>
			<?php elseif ( empty( $not_indexed ) ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card" style="border-left:4px solid #10b981;">
					<h2 style="margin-top:0;">&#x2713; <?php esc_html_e( 'All Scanned Pages Are Indexed', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'Great news — every scanned page is confirmed indexed by Google. Run a fresh scan periodically to catch newly published or de-indexed pages.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</section>
			<?php else : ?>

			<section class="twt-aeo-section">
				<p style="color:#646970;font-size:13px;margin:0 0 12px;">
					<?php
					printf(
						// translators: %d: number of published pages not in Google.
						esc_html__( '%d page(s) are published but not appearing in Google search results.', 'twt-aeo-ultimate' ),
						absint( $total_count )
					);
					?>
				</p>

				<div class="twt-aeo-page-table-wrap">
					<table class="twt-aeo-page-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Google\'s Reason', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Issues Found', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Last Crawled', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $not_indexed as $item ) :
							// Stored scan rows may come from older plugin versions —
							// never assume their shape (a string here is a PHP 8 fatal).
							$flags      = $item['heuristics']['flags'] ?? array();
							$flags      = is_array( $flags ) ? array_filter( $flags, 'is_array' ) : array();
							$word_count = $item['heuristics']['data']['word_count'] ?? null;
							$word_count = is_numeric( $word_count ) ? (int) $word_count : null;
						?>
							<tr class="twt-aeo-page-row twt-aeo-page-row--issues">

								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>">
										<?php echo esc_html( $item['title'] ?: basename( $item['url'] ) ); ?>
									</a>
									<div style="font-size:11px;color:#646970;margin-top:2px;word-break:break-all;">
										<?php echo esc_html( $item['url'] ); ?>
									</div>
								</td>

								<td>
									<span class="twt-aeo-tag twt-aeo-tag--missing" style="white-space:normal;font-size:11px;line-height:1.4;">
										<?php echo esc_html( $item['coverage_state'] ?: __( 'Unknown', 'twt-aeo-ultimate' ) ); ?>
									</span>
									<div style="font-size:11px;color:#646970;margin-top:4px;">
										<?php echo esc_html( self::coverage_tip( $item['coverage_state'] ) ); ?>
									</div>
								</td>

								<td>
									<?php if ( ! empty( $flags ) ) : ?>
									<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">
										<?php foreach ( $flags as $flag ) : ?>
											<span class="twt-aeo-tag twt-aeo-tag--missing" title="<?php echo esc_attr( $flag['detail'] ?? '' ); ?>">
												<?php echo esc_html( $flag['label'] ?? __( 'Issue', 'twt-aeo-ultimate' ) ); ?>
											</span>
											<?php if ( in_array( $flag['fix'] ?? '', array( 'faq', 'alt', 'meta_desc' ), true ) ) : ?>
												<button type="button" class="button button-small twt-aeo-fix-btn"
													data-fix="<?php echo esc_attr( $flag['fix'] ); ?>"
													data-post="<?php echo esc_attr( $item['post_id'] ); ?>"
													data-title="<?php echo esc_attr( $item['title'] ); ?>"
													data-desc="<?php echo esc_attr( $flag['current'] ?? '' ); ?>"
													style="vertical-align:baseline;"><?php esc_html_e( 'Fix →', 'twt-aeo-ultimate' ); ?></button>
											<?php endif; ?>
										<?php endforeach; ?>
									</div>
									<?php elseif ( ! is_null( $word_count ) ) : ?>
										<span class="twt-aeo-status-ok">&#x2713; <?php esc_html_e( 'No heuristic issues', 'twt-aeo-ultimate' ); ?></span>
									<?php else : ?>
										<span class="twt-aeo-muted"><?php esc_html_e( 'Not scanned', 'twt-aeo-ultimate' ); ?></span>
									<?php endif; ?>
									<?php if ( ! is_null( $word_count ) ) : ?>
									<div style="font-size:11px;color:#646970;margin-top:4px;">
										<?php
									// translators: %d: number of words in the page.
									printf( esc_html__( '%d words', 'twt-aeo-ultimate' ), absint( $word_count ) ); ?>
									</div>
									<?php endif; ?>
								</td>

								<td class="twt-aeo-muted" style="font-size:12px;">
									<?php if ( $item['last_crawl'] ) : ?>
										<?php echo esc_html( human_time_diff( strtotime( $item['last_crawl'] ), time() ) . ' ago' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Unknown', 'twt-aeo-ultimate' ); ?>
									<?php endif; ?>
								</td>

								<td>
									<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" class="twt-aeo-link">
										<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
									</a>
									&middot;
									<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>" class="twt-aeo-link">
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</a>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<?php /* ── Inline Fix Modal (FAQ schema + image alt text) ── */ ?>
			<div id="twt-aeo-fix-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);">
				<div style="background:#fff;max-width:560px;margin:6vh auto;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.25);max-height:88vh;overflow:auto;">
					<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
						<h2 id="twt-aeo-fix-title" style="margin:0;font-size:16px;"></h2>
						<button type="button" id="twt-aeo-fix-close" class="button-link" style="font-size:22px;line-height:1;color:#646970;text-decoration:none;cursor:pointer;" aria-label="<?php esc_attr_e( 'Close', 'twt-aeo-ultimate' ); ?>">&times;</button>
					</div>
					<div id="twt-aeo-fix-body" style="padding:20px;"></div>
				</div>
			</div>

			<?php endif; ?>

		</div>
		<?php
		self::print_fix_modal_js();
	}

	/**
	 * Inline JS for the Fix modal — FAQ schema generation and image alt text.
	 * Reuses the same AJAX endpoints as the Command Center and Image SEO pages.
	 */
	private static function print_fix_modal_js() {
		ob_start();
		?>
		(function(){
			var fixModal = document.getElementById('twt-aeo-fix-modal');
			if (!fixModal) return;

			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var faqNonce = <?php echo wp_json_encode( wp_create_nonce( TWTAEO_FAQ_Detector::NONCE_GENERATE ) ); ?>;
			var aiNonce  = <?php echo wp_json_encode( class_exists( 'TWTAEO_AI_Description' ) ? wp_create_nonce( TWTAEO_AI_Description::NONCE ) : '' ); ?>;
			var imgNonce = <?php echo wp_json_encode( wp_create_nonce( TWTAEO_Page_Image_SEO::NONCE ) ); ?>;
			var fixTitle = document.getElementById('twt-aeo-fix-title');
			var fixBody  = document.getElementById('twt-aeo-fix-body');

			function esc(s){
				return String(s == null ? '' : s)
					.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}
			function openModal(heading){ fixTitle.textContent = heading; fixModal.style.display = 'block'; }
			function closeFix(){ fixModal.style.display = 'none'; fixBody.innerHTML = ''; }
			document.getElementById('twt-aeo-fix-close').addEventListener('click', closeFix);
			fixModal.addEventListener('click', function(e){ if (e.target === fixModal) closeFix(); });
			document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && fixModal.style.display === 'block') closeFix(); });

			document.addEventListener('click', function(e){
				var btn = e.target.closest('.twt-aeo-fix-btn');
				if (!btn) return;
				e.preventDefault();
				var fix = btn.getAttribute('data-fix');
				if (fix === 'faq') openFaq(btn);
				if (fix === 'alt') openAlt(btn);
				if (fix === 'meta_desc') openMetaDesc(btn);
			});

			function markFixed(btn){
				btn.textContent = <?php echo wp_json_encode( __( 'Fixed ✓', 'twt-aeo-ultimate' ) ); ?>;
				btn.disabled = true;
			}

			function openFaq(btn){
				var post  = btn.getAttribute('data-post');
				var title = btn.getAttribute('data-title') || '';
				openModal(<?php echo wp_json_encode( __( 'Fix: FAQ schema — ', 'twt-aeo-ultimate' ) ); ?> + title);
				fixBody.innerHTML =
					'<p style="margin-top:0;">' + <?php echo wp_json_encode( __( 'Generate FAQPage schema from the Q&A-style content already on this page. This adds structured data — it does not change your content.', 'twt-aeo-ultimate' ) ); ?> + '</p>' +
					'<div style="display:flex;gap:8px;align-items:center;">' +
						'<button type="button" class="button button-primary" id="twt-faq-gen">' + <?php echo wp_json_encode( __( 'Generate FAQ schema', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
						'<span id="twt-faq-msg" style="color:#646970;"></span>' +
					'</div>';
				document.getElementById('twt-faq-gen').addEventListener('click', function(){
					var b = this; b.disabled = true;
					var msg = document.getElementById('twt-faq-msg'); msg.textContent = <?php echo wp_json_encode( __( 'Generating…', 'twt-aeo-ultimate' ) ); ?>;
					var fd = new FormData();
					fd.append('action', 'twtaeo_faq_generate_one'); fd.append('nonce', faqNonce); fd.append('post_id', post);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success) {
							msg.textContent = (res.data && res.data.qa_count ? res.data.qa_count + ' ' : '') + <?php echo wp_json_encode( __( 'Q&A pairs added ✓', 'twt-aeo-ultimate' ) ); ?>;
							markFixed(btn);
							setTimeout(closeFix, 1200);
						} else {
							msg.textContent = ((res.data && res.data.message) || res.data || 'Error'); b.disabled = false;
						}
					}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
				});
			}

			function openAlt(btn){
				var post  = btn.getAttribute('data-post');
				var title = btn.getAttribute('data-title') || '';
				openModal(<?php echo wp_json_encode( __( 'Fix: Image alt text — ', 'twt-aeo-ultimate' ) ); ?> + title);
				fixBody.innerHTML = '<p style="margin-top:0;color:#646970;">' + <?php echo wp_json_encode( __( 'Loading images…', 'twt-aeo-ultimate' ) ); ?> + '</p>';

				function load(statusText){
					var fd = new FormData();
					fd.append('action', 'twtaeo_img_get_post_images'); fd.append('nonce', imgNonce); fd.append('post_id', post);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (!res.success) { fixBody.innerHTML = '<p style="color:#b91c1c;">' + esc(res.data || 'Error') + '</p>'; return; }
						var imgs = (res.data && res.data.images) || [];
						if (!imgs.length) { fixBody.innerHTML = '<p>' + <?php echo wp_json_encode( __( 'No images found in this content.', 'twt-aeo-ultimate' ) ); ?> + '</p>'; return; }
						var html = '<p style="margin-top:0;">' + <?php echo wp_json_encode( __( 'Fill in descriptive alt text for each image, or let AI describe them for you.', 'twt-aeo-ultimate' ) ); ?> + '</p>';
						imgs.forEach(function(im){
							html += '<div style="display:flex;gap:12px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #f0f0f1;">' +
								'<img src="' + esc(im.thumb) + '" alt="" style="width:56px;height:56px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;flex-shrink:0;">' +
								'<div style="flex:1;min-width:0;">' +
									'<div style="font-size:11px;color:#6b7280;margin-bottom:4px;">' + esc(im.context) + '</div>' +
									'<input type="text" class="widefat twt-aeo-alt-input" data-key="' + esc(im.key) + '" value="' + esc(im.alt) + '" placeholder="' + <?php echo wp_json_encode( esc_attr__( 'Describe this image…', 'twt-aeo-ultimate' ) ); ?> + '">' +
								'</div>' +
							'</div>';
						});
						html += '<div style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
							'<button type="button" class="button" id="twt-alt-gen">' + <?php echo wp_json_encode( __( 'Generate with AI', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
							'<button type="button" class="button button-primary" id="twt-alt-save">' + <?php echo wp_json_encode( __( 'Save alt text', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
							'<span id="twt-alt-msg" style="color:#646970;">' + esc(statusText || '') + '</span>' +
						'</div>';
						fixBody.innerHTML = html;

						var msg = document.getElementById('twt-alt-msg');

						document.getElementById('twt-alt-gen').addEventListener('click', function(){
							var b = this; b.disabled = true;
							msg.textContent = <?php echo wp_json_encode( __( 'Generating with AI vision… this can take a few seconds per image.', 'twt-aeo-ultimate' ) ); ?>;
							var fd2 = new FormData();
							fd2.append('action', 'twtaeo_img_generate_alt'); fd2.append('nonce', imgNonce); fd2.append('post_id', post);
							fetch(ajaxUrl, { method:'POST', body:fd2 }).then(function(r){ return r.json(); }).then(function(res2){
								if (res2.success) {
									markFixed(btn);
									var d = res2.data || {};
									load((d.filled || 0) + ' ' + <?php echo wp_json_encode( __( 'image(s) filled ✓', 'twt-aeo-ultimate' ) ); ?>);
								} else {
									msg.textContent = (res2.data || 'Error'); b.disabled = false;
								}
							}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
						});

						document.getElementById('twt-alt-save').addEventListener('click', function(){
							var b = this; b.disabled = true; msg.textContent = <?php echo wp_json_encode( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>;
							var fd3 = new FormData();
							fd3.append('action', 'twtaeo_img_save_alt'); fd3.append('nonce', imgNonce); fd3.append('post_id', post);
							fixBody.querySelectorAll('.twt-aeo-alt-input').forEach(function(input){
								fd3.append('alts[' + input.getAttribute('data-key') + ']', input.value);
							});
							fetch(ajaxUrl, { method:'POST', body:fd3 }).then(function(r){ return r.json(); }).then(function(res3){
								if (res3.success) { msg.textContent = <?php echo wp_json_encode( __( 'Saved ✓', 'twt-aeo-ultimate' ) ); ?>; markFixed(btn); setTimeout(closeFix, 800); }
								else { msg.textContent = (res3.data || 'Error'); b.disabled = false; }
							}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
						});
					}).catch(function(){ fixBody.innerHTML = '<p style="color:#b91c1c;">Network error</p>'; });
				}
				load();
			}

			function openMetaDesc(btn){
				var post    = btn.getAttribute('data-post');
				var title   = btn.getAttribute('data-title') || '';
				var current = btn.getAttribute('data-desc') || '';
				openModal(<?php echo wp_json_encode( __( 'Fix: duplicate meta description — ', 'twt-aeo-ultimate' ) ); ?> + title);
				fixBody.innerHTML =
					'<p style="margin-top:0;">' + <?php echo wp_json_encode( __( 'This page shares its meta description word-for-word with other pages on the site. Identical descriptions read as templated duplicate content to Google and AI engines. Generate a description that only fits this page — it is written from the page\'s own content and saved immediately.', 'twt-aeo-ultimate' ) ); ?> + '</p>' +
					'<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;font-size:12px;color:#50575e;margin-bottom:14px;"><strong>' + <?php echo wp_json_encode( __( 'Current (shared): ', 'twt-aeo-ultimate' ) ); ?> + '</strong>' + esc(current) + '</div>' +
					'<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
						'<button type="button" class="button button-primary" id="twt-md-gen">' + <?php echo wp_json_encode( __( 'Write a unique description with AI', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
						'<span id="twt-md-msg" style="color:#646970;"></span>' +
					'</div>' +
					'<div id="twt-md-result" style="display:none;background:#f0f9f1;border:1px solid #b8e6bf;border-radius:4px;padding:10px 12px;font-size:12px;margin-top:14px;"></div>';
				document.getElementById('twt-md-gen').addEventListener('click', function(){
					var b = this, msg = document.getElementById('twt-md-msg');
					if (!aiNonce) { msg.textContent = <?php echo wp_json_encode( __( 'AI descriptions are not available — configure a provider under Settings first.', 'twt-aeo-ultimate' ) ); ?>; return; }
					b.disabled = true;
					msg.textContent = <?php echo wp_json_encode( __( 'Writing… this uses your configured AI provider and takes a few seconds.', 'twt-aeo-ultimate' ) ); ?>;
					var fd = new FormData();
					fd.append('action', 'twtaeo_ai_desc_generate'); fd.append('nonce', aiNonce); fd.append('post_id', post);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success && res.data && res.data.description) {
							msg.textContent = <?php echo wp_json_encode( __( 'Saved ✓', 'twt-aeo-ultimate' ) ); ?>;
							var out = document.getElementById('twt-md-result');
							out.style.display = 'block';
							out.innerHTML = '<strong>' + <?php echo wp_json_encode( __( 'New description: ', 'twt-aeo-ultimate' ) ); ?> + '</strong>' + esc(res.data.description);
							markFixed(btn);
						} else {
							msg.textContent = (res.data || 'Error'); b.disabled = false;
						}
					}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
				});
			}
		})();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	/**
	 * One-line plain-language tip per GSC coverage state.
	 */
	private static function coverage_tip( $state ) {
		$tips = array(
			'Crawled - currently not indexed'                             => 'Google visited but chose not to index — usually thin, duplicate, or low-quality content.',
			'Discovered - currently not indexed'                          => 'Google knows this page exists but hasn\'t crawled it yet — often a crawl-budget issue on larger sites.',
			'Duplicate without user-selected canonical'                   => 'Multiple similar pages exist with no canonical tag — Google can\'t tell which is the primary version.',
			'Duplicate, Google chose different canonical than user'       => 'You set a canonical but Google disagrees — the chosen canonical may be stronger or more linked-to.',
			'Excluded by \'noindex\' tag'                                 => 'A noindex directive is telling Google to skip this page — check your SEO plugin meta robots settings.',
		);
		return $tips[ $state ] ?? 'Google excluded this page from its index — check the URL in Google Search Console for details.';
	}
}
