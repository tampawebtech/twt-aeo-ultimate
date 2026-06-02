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

		// Build the list of all post IDs for Scan All.
		$all_post_ids = get_posts( array(
			'post_type'      => TWTAEO_Scan_Store::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
		) );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Dashboard', 'twt-aeo-ultimate' ); ?>
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
						>
							<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;margin-right:4px;font-size:14px;width:14px;height:14px;"></span>
							<?php esc_html_e( 'Scan All Pages', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Scan All Progress Bar (hidden until scan starts) -->
			<div id="twt-aeo-scan-progress" style="display:none;margin:0 0 24px;">
				<div class="twt-aeo-progress-bar-wrap">
					<div class="twt-aeo-progress-bar">
						<div class="twt-aeo-progress-bar__fill" id="twt-aeo-progress-fill" style="width:0%"></div>
					</div>
					<span class="twt-aeo-progress-label" id="twt-aeo-progress-label">
						<?php esc_html_e( 'Scanning…', 'twt-aeo-ultimate' ); ?>
					</span>
				</div>
			</div>

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

			<!-- Page-by-Page Results -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
					<?php esc_html_e( 'Page AEO Status', 'twt-aeo-ultimate' ); ?>
				</h2>

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
									<?php if ( ! empty( $present ) ) : ?>
										<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">
											<?php foreach ( $present as $type ) : ?>
												<span class="twt-aeo-tag twt-aeo-tag--present"><?php echo esc_html( $type ); ?></span>
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

		<?php
		ob_start();
		?>
		var twtAeoSchemas = <?php echo wp_json_encode( array(
			'nonce'    => wp_create_nonce( TWTAEO_Custom_Schema_Writer::NONCE_ACTION ),
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'siteName' => get_bloginfo( 'name' ),
			'siteUrl'  => home_url(),
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

			// ── Open dialog ─────────────────────────────────────────────────
			$(document).on('click', '.twt-aeo-schema-create-btn', function() {
				modalPostId = $(this).data('post-id');
				modalType   = $(this).data('schema-type');
				var title   = $(this).data('post-title');
				var cfg     = schemaConfig[modalType];

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
						showMsg('Schema saved — it will appear on the frontend of this page.', 'success');
						updateRowTag(modalPostId, modalType, true);
						modalHasSchema = true;
						$('#twt-aeo-dlg-delete').show();
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

						var $missingCell  = $row.find('td').eq(4);
						var rowPostId     = $link.data('post-id');
						var rowTitle      = $row.find('.twt-aeo-page-row__title a').text().trim();

						if ( d.missing.length ) {
							var tags = d.missing.map(function(t){
								return '<span class="twt-aeo-missing-item">'
									+ '<span class="twt-aeo-tag twt-aeo-tag--missing">' + $('<span>').text(t).html() + '</span>'
									+ '<button type="button" class="twt-aeo-schema-create-btn"'
									+ ' data-post-id="' + rowPostId + '"'
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

						var $presentCell = $row.find('td').eq(3);
						if ( d.present.length ) {
							var ptags = d.present.map(function(t){ return '<span class="twt-aeo-tag twt-aeo-tag--present">' + $('<span>').text(t).html() + '</span>'; }).join('');
							$presentCell.html('<div class="twt-aeo-tag-list twt-aeo-tag-list--compact">' + ptags + '</div>');
						}

						$row.find('td').eq(5).text('Just now');
						$link.text('Scan Now').css('opacity', '1');
						$link.data('nonce', nonce);

					} else {
						$link.text('Error').css('opacity', '1');
					}
				});
			});

			// ── Scan All Pages ──────────────────────────────────────────────
			$('#twt-aeo-scan-all-btn').on('click', function() {
				var $btn    = $(this);
				var nonce   = $btn.data('nonce');
				var ids     = $btn.data('ids');
				var total   = ids.length;

				if ( total === 0 ) { return; }

				$btn.prop('disabled', true).css('opacity', '0.6');
				$('#twt-aeo-scan-progress').show();

				var done   = 0;
				var errors = 0;

				function updateProgress() {
					var pct = Math.round( (done / total) * 100 );
					$('#twt-aeo-progress-fill').css('width', pct + '%');
					$('#twt-aeo-progress-label').text( 'Scanning… ' + done + ' / ' + total );
				}

				function scanNext( index ) {
					if ( index >= total ) {
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

		}); // end document.ready
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}