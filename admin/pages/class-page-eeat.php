<?php
/**
 * E-E-A-T Detector Page
 *
 * Shows a site-wide E-E-A-T scorecard with four sections.
 * Includes Author Info and Company Info tabs, a Create Page button (opens
 * editor in new tab), and a modal for sameAs links.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_EEAT {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$tab        = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = in_array( $tab, array( 'eeat', 'author', 'company' ), true ) ? $tab : 'eeat';

		$base_url = admin_url( 'admin.php?page=twt-aeo-eeat' );

		// Handle rescan request (eeat tab only).
		if ( isset( $_POST['twtaeo_eeat_rescan'] ) && check_admin_referer( 'twtaeo_eeat_rescan' ) ) {
			TWTAEO_EEAT_Detector::clear_cache();
		}

		// Handle company form submission before any HTML output.
		$company_notice = '';
		if ( 'company' === $active_tab && class_exists( 'TWTAEO_Page_Company' ) ) {
			$company_notice = TWTAEO_Page_Company::handle_post();
		}

		// Load scan data only when the E-E-A-T tab is active.
		$scan = $total = $score_color = $grade = $sections = null;
		if ( 'eeat' === $active_tab ) {
			$scan  = TWTAEO_EEAT_Detector::get_or_scan();
			$total = $scan['total_score'] ?? 0;

			if ( $total >= 80 )     { $score_color = '#16a34a'; $grade = 'A'; }
			elseif ( $total >= 60 ) { $score_color = '#d97706'; $grade = 'B'; }
			elseif ( $total >= 40 ) { $score_color = '#ea580c'; $grade = 'C'; }
			else                    { $score_color = '#dc2626'; $grade = 'D'; }

			$sections = array(
				'experience'        => 'Experience',
				'expertise'         => 'Expertise',
				'authoritativeness' => 'Authoritativeness',
				'trustworthiness'   => 'Trustworthiness',
			);
		}

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		?>
		<div class="wrap twt-aeo-wrap">

			<!-- Header -->
			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'E-E-A-T', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div class="twt-aeo-header__meta">
						<?php if ( 'eeat' === $active_tab && ! empty( $scan['scanned_at'] ) ) : ?>
						<span class="twt-aeo-badge twt-aeo-badge--page">
							<?php
						printf(
							// translators: %s: human-readable time difference (e.g. '5 minutes').
							esc_html__( 'Last scanned %s ago', 'twt-aeo-ultimate' ),
							esc_html( human_time_diff( strtotime( $scan['scanned_at'] ), current_time( 'timestamp' ) ) )
						); ?>
						</span>
						<?php endif; ?>
						<?php if ( 'eeat' === $active_tab ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'twtaeo_eeat_rescan' ); ?>
							<button type="submit" name="twtaeo_eeat_rescan" value="1" class="twt-aeo-btn twt-aeo-btn--primary">
								<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;margin-right:4px;font-size:14px;width:14px;height:14px;"></span>
								<?php esc_html_e( 'Rescan', 'twt-aeo-ultimate' ); ?>
							</button>
						</form>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<nav class="twt-aeo-tabs">
				<a href="<?php echo esc_url( $base_url ); ?>"
				   class="twt-aeo-tab <?php echo 'eeat' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-chart-bar"></span>
					<?php esc_html_e( 'E-E-A-T', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'author', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo 'author' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-users"></span>
					<?php esc_html_e( 'Author Info', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'company', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo 'company' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-building"></span>
					<?php esc_html_e( 'Company Info', 'twt-aeo-ultimate' ); ?>
				</a>
			</nav>

			<?php if ( 'author' === $active_tab ) : ?>

				<?php if ( class_exists( 'TWTAEO_Page_Author' ) ) :
					TWTAEO_Page_Author::render_content( add_query_arg( 'tab', 'author', $base_url ) );
				endif; ?>

			<?php elseif ( 'company' === $active_tab ) : ?>

				<?php if ( class_exists( 'TWTAEO_Page_Company' ) ) :
					TWTAEO_Page_Company::render_content( $company_notice );
				endif; ?>

			<?php else : ?>

				<!-- Overall Score -->
				<section class="twt-aeo-section">
					<div class="twt-aeo-summary-grid">

						<div class="twt-aeo-summary-card" style="text-align:center;">
							<div class="twt-aeo-summary-card__number" style="font-size:48px;color:<?php echo esc_attr( $score_color ); ?>;">
								<?php echo esc_html( $grade ); ?>
							</div>
							<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Overall Grade', 'twt-aeo-ultimate' ); ?></div>
						</div>

						<div class="twt-aeo-summary-card" style="text-align:center;">
							<div class="twt-aeo-summary-card__number" style="color:<?php echo esc_attr( $score_color ); ?>;">
								<?php echo esc_html( $total ); ?><span style="font-size:18px;color:#6b7280;">/100</span>
							</div>
							<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'E-E-A-T Score', 'twt-aeo-ultimate' ); ?></div>
						</div>

						<?php
						foreach ( $sections as $key => $label ) :
							$s   = $scan[ $key ] ?? array( 'score' => 0, 'max' => 25 );
							$pct = $s['max'] > 0 ? round( ( $s['score'] / $s['max'] ) * 100 ) : 0;
							$c   = $pct >= 80 ? '#16a34a' : ( $pct >= 50 ? '#d97706' : '#dc2626' );
						?>
						<div class="twt-aeo-summary-card" style="text-align:center;">
							<div class="twt-aeo-summary-card__number" style="color:<?php echo esc_attr( $c ); ?>;">
								<?php echo esc_html( $s['score'] ); ?><span style="font-size:14px;color:#6b7280;">/<?php echo esc_html( $s['max'] ); ?></span>
							</div>
							<div class="twt-aeo-summary-card__label"><?php echo esc_html( $label ); ?></div>
						</div>
						<?php endforeach; ?>

					</div>
				</section>

				<!-- Section Scorecards -->
				<?php foreach ( $sections as $key => $label ) :
					$s       = $scan[ $key ] ?? array( 'score' => 0, 'max' => 25, 'signals' => array(), 'missing' => array() );
					$signals = $s['signals'] ?? array();
					$missing = $s['missing'] ?? array();
				?>
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title">
						<?php echo esc_html( $label ); ?>
						<span style="font-size:13px;font-weight:400;color:#6b7280;margin-left:8px;">
							<?php echo esc_html( $s['score'] . '/' . $s['max'] ); ?> pts
						</span>
					</h2>
					<div class="twt-aeo-card">

						<?php if ( ! empty( $signals ) ) : ?>
						<ul class="twt-aeo-checklist">
							<?php foreach ( $signals as $signal ) : ?>
							<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
								<span class="dashicons dashicons-yes-alt" style="color:#16a34a;"></span>
								<?php echo esc_html( $signal['label'] ); ?>
								<span style="margin-left:auto;color:#16a34a;font-size:11px;font-weight:600;">+<?php echo esc_html( $signal['points'] ); ?>pts</span>
							</li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>

						<?php if ( ! empty( $missing ) ) : ?>
						<ul class="twt-aeo-checklist" style="margin-top:<?php echo empty( $signals ) ? '0' : '12px'; ?>;">
							<?php foreach ( $missing as $item ) : ?>
							<li class="twt-aeo-checklist__item" style="display:flex;align-items:flex-start;gap:8px;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.06);">
								<span class="dashicons dashicons-warning" style="color:#d97706;flex-shrink:0;margin-top:1px;"></span>
								<div style="flex:1;">
									<strong style="font-size:13px;"><?php echo esc_html( $item['label'] ); ?></strong>
									<span style="margin-left:8px;color:#dc2626;font-size:11px;font-weight:600;">-<?php echo esc_html( $item['points'] ); ?>pts</span>
									<p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php echo esc_html( $item['fix'] ); ?></p>
								</div>
								<?php if ( ! empty( $item['action'] ) ) : ?>
								<div style="flex-shrink:0;">
									<?php if ( $item['action']['type'] === 'url' ) : ?>
										<a href="<?php echo esc_url( $item['action']['url'] ); ?>"
										   class="button button-primary button-small"
										   <?php if ( ! empty( $item['action']['new_tab'] ) ) echo 'target="_blank" rel="noopener"'; ?>>
											<?php echo esc_html( $item['action']['label'] ); ?>
										</a>
									<?php elseif ( $item['action']['type'] === 'create_page' ) : ?>
									<button
										type="button"
										class="button button-primary button-small twt-aeo-create-page"
										data-title="<?php echo esc_attr( $item['action']['title'] ); ?>"
										data-slug="<?php echo esc_attr( $item['action']['slug'] ); ?>"
										data-content="<?php echo esc_attr( $item['action']['content'] ); ?>"
									>
										<?php esc_html_e( 'Create Page', 'twt-aeo-ultimate' ); ?>
									</button>
									<?php elseif ( $item['action']['type'] === 'modal' && $item['action']['modal'] === 'sameas' ) : ?>
									<button type="button" class="button button-primary button-small twt-aeo-open-sameas">
										<?php esc_html_e( 'Add Links', 'twt-aeo-ultimate' ); ?>
									</button>
									<?php endif; ?>
								</div>
								<?php endif; ?>
							</li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>

						<?php if ( empty( $signals ) && empty( $missing ) ) : ?>
						<p class="twt-aeo-empty"><?php esc_html_e( 'No signals detected for this section.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>

					</div>
				</section>
				<?php endforeach; ?>

				<!-- Author Metadata Section -->
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Author Metadata', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Author completeness directly impacts E-E-A-T signals. Fill in each author\'s credentials, expertise, and profile links.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php
						$authors = class_exists( 'TWTAEO_Author_Meta' ) ? TWTAEO_Author_Meta::get_all_authors() : array();
						if ( empty( $authors ) ) : ?>
						<p class="twt-aeo-empty"><?php esc_html_e( 'No authors found.', 'twt-aeo-ultimate' ); ?></p>
						<?php else : ?>
						<div class="twt-aeo-page-table-wrap">
							<table class="twt-aeo-page-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Author', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Role', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Bio', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Job Title', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Credentials', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Expertise', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Completeness', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $authors as $item ) :
									$user  = $item['user'];
									$data  = $item['data'];
									$comp  = $item['completeness'];
									$pct   = $comp['score'];
									$color = $pct >= 80 ? '#16a34a' : ( $pct >= 50 ? '#d97706' : '#dc2626' );
									$roles = array_keys( $user->roles ?? array() );
									$role  = ucfirst( $roles[0] ?? 'Author' );
								?>
								<tr class="twt-aeo-page-row <?php echo $pct < 50 ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok'; ?>">
									<td class="twt-aeo-page-row__title">
										<?php echo get_avatar( $user->ID, 24, '', '', array( 'style' => 'border-radius:50%;vertical-align:middle;margin-right:6px;' ) ); ?>
										<strong><?php echo esc_html( $user->display_name ); ?></strong>
									</td>
									<td><span class="twt-aeo-intent-pill"><?php echo esc_html( $role ); ?></span></td>
									<td>
										<?php if ( ! empty( $data['bio'] ) ) : ?>
											<span class="twt-aeo-status-ok">✓</span>
										<?php else : ?>
											<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $data['job_title'] ) ) : ?>
											<span class="twt-aeo-status-ok">✓ <?php echo esc_html( $data['job_title'] ); ?></span>
										<?php else : ?>
											<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $data['credentials'] ) ) : ?>
											<span class="twt-aeo-status-ok">✓ <?php echo esc_html( $data['credentials'] ); ?></span>
										<?php else : ?>
											<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $data['expertise'] ) ) : ?>
											<span class="twt-aeo-status-ok">✓ <?php echo esc_html( $data['expertise'] ); ?></span>
										<?php else : ?>
											<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"
											   class="twt-aeo-tag twt-aeo-tag--missing"
											   style="text-decoration:none;"
											   title="<?php esc_attr_e( 'Edit profile to add expertise', 'twt-aeo-ultimate' ); ?>">
												<?php esc_html_e( 'Missing — Edit', 'twt-aeo-ultimate' ); ?> ↗
											</a>
										<?php endif; ?>
									</td>
									<td>
										<span style="font-weight:600;color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $pct ); ?>%</span>
									</td>
									<td style="white-space:nowrap;">
										<button
											type="button"
											class="button button-small twt-aeo-edit-author"
											data-user-id="<?php echo esc_attr( $user->ID ); ?>"
											data-user-name="<?php echo esc_attr( $user->display_name ); ?>"
										>
											<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
										</button>
										<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"
										   target="_blank"
										   class="button button-small"
										   style="margin-left:4px;">
											<?php esc_html_e( 'Profile ↗', 'twt-aeo-ultimate' ); ?>
										</a>
									</td>
								</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php endif; ?>
					</div>
				</section>

			<?php endif; // end eeat tab ?>

		</div><!-- .twt-aeo-wrap -->

		<?php if ( 'eeat' === $active_tab ) : ?>

		<!-- Author Edit Modal -->
		<div id="twt-aeo-author-modal" title="<?php esc_attr_e( 'Edit Author Signals', 'twt-aeo-ultimate' ); ?>" style="display:none;">
			<input type="hidden" id="twt-author-user-id" value="" />
			<table class="form-table" style="margin-top:0;">
				<tbody>
					<tr>
						<th><label for="twt-author-bio"><?php esc_html_e( 'Bio', 'twt-aeo-ultimate' ); ?></label></th>
						<td><textarea id="twt-author-bio" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'A short professional bio...', 'twt-aeo-ultimate' ); ?>"></textarea></td>
					</tr>
					<?php if ( class_exists( 'TWTAEO_Author_Meta' ) ) :
						foreach ( TWTAEO_Author_Meta::get_fields() as $field ) : ?>
					<tr>
						<th><label for="twt-author-<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td><input type="<?php echo esc_attr( $field['type'] ); ?>" id="twt-author-<?php echo esc_attr( $field['key'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" /></td>
					</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p id="twt-author-message" style="display:none;padding:8px 12px;border-radius:4px;margin-top:12px;"></p>
		</div>

		<!-- sameAs Modal -->
		<div id="twt-aeo-sameas-modal" title="<?php esc_attr_e( 'Add sameAs Links', 'twt-aeo-ultimate' ); ?>" style="display:none;">
			<p style="margin-top:0;color:#646970;font-size:13px;">
				<?php esc_html_e( 'sameAs links tell AI systems and search engines that these profiles belong to your organisation. Add any that apply.', 'twt-aeo-ultimate' ); ?>
			</p>
			<table class="form-table" style="margin-top:0;">
				<tbody>
					<tr><th><label><?php esc_html_e( 'LinkedIn', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-linkedin" class="regular-text" placeholder="https://linkedin.com/company/yourcompany" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Facebook', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-facebook" class="regular-text" placeholder="https://facebook.com/yourpage" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Twitter / X', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-twitter" class="regular-text" placeholder="https://twitter.com/yourhandle" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Instagram', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-instagram" class="regular-text" placeholder="https://instagram.com/yourprofile" /></td></tr>
					<tr><th><label><?php esc_html_e( 'YouTube', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-youtube" class="regular-text" placeholder="https://youtube.com/@yourchannel" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Google Business', 'twt-aeo-ultimate' ); ?></label></th><td><input type="url" id="twt-sameas-google" class="regular-text" placeholder="https://g.page/yourbusiness" /></td></tr>
				</tbody>
			</table>
			<p id="twt-sameas-message" style="display:none;padding:8px 12px;border-radius:4px;margin-top:12px;"></p>
		</div>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {

			var nonce = '<?php echo esc_js( wp_create_nonce( 'twtaeo_eeat_nonce' ) ); ?>';

			// ── Create Page → opens editor in a NEW TAB ─────────────────────
			$(document).on('click', '.twt-aeo-create-page', function() {
				var $btn    = $(this);
				var title   = $btn.data('title');
				var slug    = $btn.data('slug');
				var content = $btn.data('content');

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Creating…', 'twt-aeo-ultimate' ) ); ?>');

				$.post(ajaxurl, {
					action:       'twtaeo_create_eeat_page',
					nonce:        nonce,
					page_title:   title,
					page_slug:    slug,
					page_content: content
				}, function(r) {
					if ( r.success && r.data.edit_url ) {
						window.open( r.data.edit_url, '_blank' );
						$btn.text('<?php echo esc_js( __( 'Page Created ✓', 'twt-aeo-ultimate' ) ); ?>')
						    .removeClass('button-primary')
						    .prop('disabled', true);
					} else {
						$btn.prop('disabled', false)
						    .text('<?php echo esc_js( __( 'Create Page', 'twt-aeo-ultimate' ) ); ?>');
						alert( r.data || '<?php echo esc_js( __( 'Failed to create page.', 'twt-aeo-ultimate' ) ); ?>' );
					}
				}).fail(function() {
					$btn.prop('disabled', false)
					    .text('<?php echo esc_js( __( 'Create Page', 'twt-aeo-ultimate' ) ); ?>');
					alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'twt-aeo-ultimate' ) ); ?>');
				});
			});

			// ── sameAs modal ────────────────────────────────────────────────
			$('#twt-aeo-sameas-modal').dialog({
				autoOpen:  false,
				modal:     true,
				width:     560,
				resizable: false,
				buttons: [
					{
						text: '<?php echo esc_js( __( 'Save Links', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button button-primary',
						click: function() { saveSameAs(); }
					},
					{
						text: '<?php echo esc_js( __( 'Cancel', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button',
						click: function() { $(this).dialog('close'); }
					}
				]
			});

			$(document).on('click', '.twt-aeo-open-sameas', function() {
				$('#twt-sameas-message').hide();
				$.post(ajaxurl, { action: 'twtaeo_get_sameas', nonce: nonce }, function(r) {
					if ( r.success ) {
						var d = r.data;
						$('#twt-sameas-linkedin').val(d.linkedin  || '');
						$('#twt-sameas-facebook').val(d.facebook  || '');
						$('#twt-sameas-twitter').val(d.twitter   || '');
						$('#twt-sameas-instagram').val(d.instagram || '');
						$('#twt-sameas-youtube').val(d.youtube   || '');
						$('#twt-sameas-google').val(d.google    || '');
					}
				});
				$('#twt-aeo-sameas-modal').dialog('open');
			});

			function saveSameAs() {
				$.post(ajaxurl, {
					action:    'twtaeo_save_sameas',
					nonce:     nonce,
					linkedin:  $('#twt-sameas-linkedin').val(),
					facebook:  $('#twt-sameas-facebook').val(),
					twitter:   $('#twt-sameas-twitter').val(),
					instagram: $('#twt-sameas-instagram').val(),
					youtube:   $('#twt-sameas-youtube').val(),
					google:    $('#twt-sameas-google').val(),
				}, function(r) {
					if ( r.success ) {
						$('#twt-sameas-message')
							.text('<?php echo esc_js( __( 'Links saved. Rescanning…', 'twt-aeo-ultimate' ) ); ?>')
							.css({ background:'#ecfdf5', color:'#065f46', border:'1px solid #a7f3d0' })
							.show();
						setTimeout(function() {
							$('#twt-aeo-sameas-modal').dialog('close');
							location.reload();
						}, 1000);
					} else {
						$('#twt-sameas-message')
							.text( r.data || '<?php echo esc_js( __( 'Save failed.', 'twt-aeo-ultimate' ) ); ?>' )
							.css({ background:'#fef2f2', color:'#991b1b', border:'1px solid #fecaca' })
							.show();
					}
				});
			}

			// ── Author edit modal ───────────────────────────────────────────
			$('#twt-aeo-author-modal').dialog({
				autoOpen:  false,
				modal:     true,
				width:     600,
				resizable: false,
				buttons: [
					{
						text: '<?php echo esc_js( __( 'Save', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button button-primary',
						click: function() { saveAuthor(); }
					},
					{
						text: '<?php echo esc_js( __( 'Cancel', 'twt-aeo-ultimate' ) ); ?>',
						'class': 'button',
						click: function() { $(this).dialog('close'); }
					}
				]
			});

			$(document).on('click', '.twt-aeo-edit-author', function() {
				var userId   = $(this).data('user-id');
				var userName = $(this).data('user-name');

				$('#twt-author-user-id').val(userId);
				$('#twt-author-message').hide();

				$.post(ajaxurl, {
					action:  'twtaeo_get_author_meta',
					nonce:   nonce,
					user_id: userId
				}, function(r) {
					if ( r.success ) {
						var d = r.data;
						$('#twt-author-bio').val(d.bio || '');
						<?php if ( class_exists( 'TWTAEO_Author_Meta' ) ) :
							foreach ( TWTAEO_Author_Meta::get_fields() as $field ) : ?>
						$('#twt-author-<?php echo esc_js( $field['key'] ); ?>').val(d['<?php echo esc_js( $field['key'] ); ?>'] || '');
						<?php endforeach; endif; ?>
					}
				});

				$('#twt-aeo-author-modal')
					.dialog('option', 'title', '<?php echo esc_js( __( 'Edit Author Signals', 'twt-aeo-ultimate' ) ); ?>: ' + userName)
					.dialog('open');
			});

			function saveAuthor() {
				var data = {
					action:  'twtaeo_save_author_meta',
					nonce:   nonce,
					user_id: $('#twt-author-user-id').val(),
					bio:     $('#twt-author-bio').val(),
					<?php if ( class_exists( 'TWTAEO_Author_Meta' ) ) :
						foreach ( TWTAEO_Author_Meta::get_fields() as $field ) : ?>
					'<?php echo esc_js( $field['key'] ); ?>': $('#twt-author-<?php echo esc_js( $field['key'] ); ?>').val(),
					<?php endforeach; endif; ?>
				};

				$.post(ajaxurl, data, function(r) {
					if ( r.success ) {
						$('#twt-author-message')
							.text('<?php echo esc_js( __( 'Saved successfully.', 'twt-aeo-ultimate' ) ); ?>')
							.css({ background:'#ecfdf5', color:'#065f46', border:'1px solid #a7f3d0' })
							.show();
						setTimeout(function() {
							$('#twt-aeo-author-modal').dialog('close');
							location.reload();
						}, 1000);
					} else {
						$('#twt-author-message')
							.text( r.data || '<?php echo esc_js( __( 'Save failed.', 'twt-aeo-ultimate' ) ); ?>' )
							.css({ background:'#fef2f2', color:'#991b1b', border:'1px solid #fecaca' })
							.show();
					}
				});
			}

		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );

		endif; // end eeat tab modals + script ?>
		<?php
	}

}
