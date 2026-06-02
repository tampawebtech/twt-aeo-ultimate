<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Merged Author page — combines Author Box and Author Entity into one tabbed view.
 */
class TWTAEO_Page_Author {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		global $twtaeo_plugin;
		$modules       = $twtaeo_plugin ? $twtaeo_plugin->modules : null;
		$box_active    = $modules ? $modules->is_active( 'author-box' )    : true;
		$entity_active = $modules ? $modules->is_active( 'author-entity' ) : true;

		$default_tab = $box_active ? 'author-box' : 'author-entity';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab  = isset( $_GET['tab'] ) && in_array( sanitize_key( wp_unslash( $_GET['tab'] ) ), array( 'author-box', 'author-entity' ), true )
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: $default_tab;

		$base_url = admin_url( 'admin.php?page=twt-aeo-author' );
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Author', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<nav class="twt-aeo-tabs">
				<?php if ( $box_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'author-box', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo $active_tab === 'author-box' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-id"></span>
					<?php esc_html_e( 'Author Box', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $entity_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'author-entity', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo $active_tab === 'author-entity' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-users"></span>
					<?php esc_html_e( 'Author Entity', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
			</nav>

			<?php if ( $active_tab === 'author-box' && $box_active ) : ?>
				<?php self::render_author_box(); ?>
			<?php elseif ( $active_tab === 'author-entity' && $entity_active ) : ?>
				<?php self::render_author_entity(); ?>
			<?php endif; ?>

		</div>
		<?php
	}

	// ── Author Box tab ───────────────────────────────────────────────────────

	private static function render_author_box() {
		$scan          = TWTAEO_Author_Box_Detector::scan_site();
		$has_box       = $scan['has_author_box'];
		$source        = $scan['source'];
		$schema        = $scan['author_schema_present'];
		$patterns      = $scan['patterns_found'];
		$posts_checked = $scan['post_count_checked'];
		$author        = $scan['author_info'];
		?>

		<!-- Summary Cards -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-summary-grid">

				<div class="twt-aeo-summary-card <?php echo $has_box ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo $has_box ? '✓' : '✗'; ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Author Box', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $schema ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo $schema ? '✓' : '✗'; ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Author Schema', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $author['has_bio'] ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo $author['has_bio'] ? '✓' : '✗'; ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Author Bio', 'twt-aeo-ultimate' ); ?></div>
				</div>

			</div>
		</section>

		<!-- Author Box Status -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Author Box Detection', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<table class="twt-aeo-page-table" style="margin-top:0;">
					<tbody>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Author Box Detected', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( $has_box ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Yes', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Not detected', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Source', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( $source !== 'none' ) : ?>
									<span class="twt-aeo-intent-pill"><?php echo esc_html( ucfirst( $source ) ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-muted"><?php esc_html_e( 'Unknown / None', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Author Schema', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( $schema ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Posts Checked', 'twt-aeo-ultimate' ); ?></strong></td>
							<td><span class="twt-aeo-badge twt-aeo-badge--page"><?php echo esc_html( $posts_checked ); ?></span></td>
						</tr>

						<?php if ( ! empty( $patterns ) ) : ?>
						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Patterns Found', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<div class="twt-aeo-metabox__tags">
									<?php foreach ( $patterns as $pattern ) : ?>
										<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present"><?php echo esc_html( $pattern ); ?></span>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<?php endif; ?>

					</tbody>
				</table>
			</div>
		</section>

		<!-- Author Info -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Primary Author', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<table class="twt-aeo-page-table" style="margin-top:0;">
					<tbody>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Name', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( ! empty( $author['name'] ) ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php echo esc_html( $author['name'] ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-tag twt-aeo-tag--missing"><?php esc_html_e( 'Not set', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Bio', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( $author['has_bio'] ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-author-missing">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'Not present —', 'twt-aeo-ultimate' ); ?>
										<?php
										$profile_url = $author['user_id']
											? admin_url( 'user-edit.php?user_id=' . $author['user_id'] . '#description' )
											: admin_url( 'profile.php#description' );
										?>
										<a href="<?php echo esc_url( $profile_url ); ?>" class="twt-aeo-author-fix-link">
											<?php esc_html_e( 'add bio in Users → Profile', 'twt-aeo-ultimate' ); ?>
										</a>
									</span>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="twt-aeo-page-row">
							<td><strong><?php esc_html_e( 'Avatar', 'twt-aeo-ultimate' ); ?></strong></td>
							<td>
								<?php if ( $author['has_avatar'] ) : ?>
									<span class="twt-aeo-status-ok">✓ <?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></span>
								<?php else : ?>
									<span class="twt-aeo-author-missing">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'Not set —', 'twt-aeo-ultimate' ); ?>
										<a href="https://gravatar.com" target="_blank" rel="noopener" class="twt-aeo-author-fix-link">
											<?php esc_html_e( 'set a Gravatar at gravatar.com', 'twt-aeo-ultimate' ); ?>
										</a>
									</span>
								<?php endif; ?>
							</td>
						</tr>

					</tbody>
				</table>
			</div>
		</section>

		<!-- Recommendations -->
		<?php if ( ! $has_box || ! $schema || ! $author['has_bio'] ) : ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Recommendations', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<ul class="twt-aeo-checklist">

					<?php if ( ! $author['has_bio'] ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
						<span class="dashicons dashicons-warning" style="color:#d97706;"></span>
						<?php esc_html_e( 'Add an author bio — go to Users → Profile and fill in the Biographical Info field.', 'twt-aeo-ultimate' ); ?>
					</li>
					<?php endif; ?>

					<?php if ( ! $has_box ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
						<span class="dashicons dashicons-warning" style="color:#d97706;"></span>
						<?php esc_html_e( 'No author box detected. Consider enabling one in your theme settings or installing a plugin like Simple Author Box.', 'twt-aeo-ultimate' ); ?>
					</li>
					<?php endif; ?>

					<?php if ( ! $schema ) : ?>
					<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
						<span class="dashicons dashicons-warning" style="color:#d97706;"></span>
						<?php if ( defined( 'RANK_MATH_VERSION' ) ) : ?>
							<?php esc_html_e( 'Author schema missing. In Rank Math → Titles & Meta → Global Meta, set the Knowledge Graph type to Person and fill in your name and profile links.', 'twt-aeo-ultimate' ); ?>
						<?php elseif ( defined( 'WPSEO_VERSION' ) ) : ?>
							<?php esc_html_e( 'Author schema missing. In Yoast SEO → Social, set your personal info to enable Person schema.', 'twt-aeo-ultimate' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Author schema missing. Use Rank Math or Yoast SEO to add Person schema, or add it manually via JSON-LD.', 'twt-aeo-ultimate' ); ?>
						<?php endif; ?>
					</li>
					<?php endif; ?>

				</ul>
			</div>
		</section>
		<?php endif; ?>
		<?php
	}

	// ── Author Entity tab ────────────────────────────────────────────────────

	private static function render_author_entity() {
		$authors      = TWTAEO_Author_Meta::get_all_authors();
		$total        = count( $authors );
		$avg_score    = $total ? (int) round( array_sum( array_column( array_column( $authors, 'completeness' ), 'score' ) ) / $total ) : 0;
		$with_certs   = count( array_filter( $authors, function( $a ) { return ! empty( $a['certifications'] ); } ) );
		$with_socials = count( array_filter( $authors, function( $a ) { return ! empty( TWTAEO_Author_Meta::get_same_as( $a['id'] ) ); } ) );

		$seo_plugin_handles = self::seo_plugin_handles_person();
		?>

		<!-- Summary Cards -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-summary-grid" style="grid-template-columns:repeat(4,1fr);">

				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $total ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Total Authors', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $avg_score >= 70 ? 'twt-aeo-summary-card--good' : ( $avg_score >= 40 ? 'twt-aeo-summary-card--warn' : 'twt-aeo-summary-card--alert' ); ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $avg_score ); ?>%</div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Avg. Completeness', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $with_certs > 0 ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $with_certs ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'With Certifications', 'twt-aeo-ultimate' ); ?></div>
				</div>

				<div class="twt-aeo-summary-card <?php echo $with_socials > 0 ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $with_socials ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'With Social Profiles', 'twt-aeo-ultimate' ); ?></div>
				</div>

			</div>
		</section>

		<?php if ( $seo_plugin_handles ) : ?>
		<section class="twt-aeo-section">
			<div class="twt-aeo-card" style="border-left:4px solid var(--aeo-accent);padding:16px 20px;display:flex;align-items:center;gap:12px;">
				<span class="dashicons dashicons-plugins-checked" style="color:var(--aeo-accent);font-size:22px;width:22px;height:22px;flex-shrink:0;"></span>
				<p style="margin:0;font-size:13px;color:var(--aeo-text);">
					<?php
					printf(
						// translators: %s: name of the active SEO plugin handling Person schema.
						esc_html__( '%s is handling Person schema. The TWT AEO Author Schema Writer will not output a duplicate. Certifications and social profiles are stored and ready if you switch away from this plugin.', 'twt-aeo-ultimate' ),
						'<strong>' . esc_html( $seo_plugin_handles ) . '</strong>'
					);
					?>
				</p>
			</div>
		</section>
		<?php endif; ?>

		<!-- Author Cards -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<span class="dashicons dashicons-admin-users"></span>
				<?php esc_html_e( 'Author Profiles', 'twt-aeo-ultimate' ); ?>
			</h2>

			<?php if ( empty( $authors ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty"><?php esc_html_e( 'No authors found on this site.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php else : ?>
				<div class="twt-aeo-author-grid">
					<?php foreach ( $authors as $author ) :
						$c     = $author['completeness'];
						$score = $c['score'];
						$color = $score >= 70 ? 'var(--aeo-success)' : ( $score >= 40 ? 'var(--aeo-warning)' : 'var(--aeo-danger)' );
						$certs = $author['certifications'];
						$social_urls = TWTAEO_Author_Meta::get_same_as( $author['id'] );
					?>
						<div class="twt-aeo-author-card">

							<div class="twt-aeo-author-card__header">
								<img src="<?php echo esc_url( $author['avatar_url'] ); ?>"
									alt="<?php echo esc_attr( $author['name'] ); ?>"
									class="twt-aeo-author-card__avatar"
									width="48" height="48">
								<div class="twt-aeo-author-card__info">
									<strong class="twt-aeo-author-card__name"><?php echo esc_html( $author['name'] ); ?></strong>
									<?php if ( ! empty( $author['job_title'] ) ) : ?>
										<span class="twt-aeo-author-card__title"><?php echo esc_html( $author['job_title'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $author['credentials'] ) ) : ?>
										<span class="twt-aeo-author-card__credentials"><?php echo esc_html( $author['credentials'] ); ?></span>
									<?php endif; ?>
								</div>
							</div>

							<div class="twt-aeo-author-card__score-wrap">
								<div class="twt-aeo-author-card__score-label">
									<span><?php esc_html_e( 'Entity Completeness', 'twt-aeo-ultimate' ); ?></span>
									<strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $score ); ?>%</strong>
								</div>
								<div class="twt-aeo-progress-track">
									<div class="twt-aeo-progress-fill" style="width:<?php echo esc_attr( $score ); ?>%;background:<?php echo esc_attr( $color ); ?>;"></div>
								</div>
								<?php if ( ! empty( $c['missing'] ) ) : ?>
								<p class="twt-aeo-author-card__missing">
									<?php esc_html_e( 'Missing:', 'twt-aeo-ultimate' ); ?>
									<?php echo esc_html( implode( ', ', $c['missing'] ) ); ?>
								</p>
								<?php endif; ?>
							</div>

							<div class="twt-aeo-author-card__stats">
								<div class="twt-aeo-author-stat <?php echo $author['bio'] ? 'twt-aeo-author-stat--pass' : 'twt-aeo-author-stat--fail'; ?>">
									<span class="dashicons <?php echo $author['bio'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
									<?php esc_html_e( 'Bio', 'twt-aeo-ultimate' ); ?>
								</div>
								<div class="twt-aeo-author-stat <?php echo ! empty( $author['job_title'] ) ? 'twt-aeo-author-stat--pass' : 'twt-aeo-author-stat--fail'; ?>">
									<span class="dashicons <?php echo ! empty( $author['job_title'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
									<?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?>
								</div>
								<div class="twt-aeo-author-stat <?php echo ! empty( $certs ) ? 'twt-aeo-author-stat--pass' : 'twt-aeo-author-stat--fail'; ?>">
									<span class="dashicons <?php echo ! empty( $certs ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
									<?php
								// translators: %d: number of certifications.
								echo esc_html( sprintf( _n( '%d Cert', '%d Certs', count( $certs ), 'twt-aeo-ultimate' ), absint( count( $certs ) ) ) ); ?>
								</div>
								<div class="twt-aeo-author-stat <?php echo ! empty( $social_urls ) ? 'twt-aeo-author-stat--pass' : 'twt-aeo-author-stat--fail'; ?>">
									<span class="dashicons <?php echo ! empty( $social_urls ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
									<?php
								// translators: %d: number of social profile links.
								echo esc_html( sprintf( _n( '%d Social', '%d Socials', count( $social_urls ), 'twt-aeo-ultimate' ), absint( count( $social_urls ) ) ) ); ?>
								</div>
							</div>

							<?php if ( ! empty( $certs ) ) : ?>
							<div class="twt-aeo-author-card__certs">
								<p class="twt-aeo-author-card__section-title"><?php esc_html_e( 'Certifications', 'twt-aeo-ultimate' ); ?></p>
								<ul class="twt-aeo-cert-list">
									<?php foreach ( array_slice( $certs, 0, 3 ) as $cert ) : ?>
									<li class="twt-aeo-cert-list__item">
										<span class="dashicons dashicons-awards"></span>
										<span>
											<strong><?php echo esc_html( $cert['name'] ); ?></strong>
											<?php if ( ! empty( $cert['organization'] ) ) : ?>
												<span class="twt-aeo-muted"> — <?php echo esc_html( $cert['organization'] ); ?></span>
											<?php endif; ?>
										</span>
									</li>
									<?php endforeach; ?>
									<?php if ( count( $certs ) > 3 ) : ?>
									<li class="twt-aeo-cert-list__item twt-aeo-muted">
										+ <?php echo esc_html( count( $certs ) - 3 ); ?> <?php esc_html_e( 'more', 'twt-aeo-ultimate' ); ?>
									</li>
									<?php endif; ?>
								</ul>
							</div>
							<?php endif; ?>

							<?php if ( ! empty( $social_urls ) ) : ?>
							<div class="twt-aeo-author-card__socials">
								<p class="twt-aeo-author-card__section-title"><?php esc_html_e( 'sameAs Profiles', 'twt-aeo-ultimate' ); ?></p>
								<div class="twt-aeo-author-card__social-list">
									<?php foreach ( $social_urls as $url ) : ?>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"
										class="twt-aeo-social-chip"
										title="<?php echo esc_attr( $url ); ?>">
										<?php echo esc_html( self::get_domain_label( $url ) ); ?>
									</a>
									<?php endforeach; ?>
								</div>
							</div>
							<?php endif; ?>

							<div class="twt-aeo-author-card__footer">
								<a href="<?php echo esc_url( $author['edit_url'] ); ?>" class="twt-aeo-btn twt-aeo-btn--primary">
									<span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
									<?php esc_html_e( 'Edit Profile', 'twt-aeo-ultimate' ); ?>
								</a>
								<button type="button" class="twt-aeo-btn twt-aeo-schema-toggle"
									data-user="<?php echo esc_attr( $author['id'] ); ?>">
									&lt;/&gt; <?php esc_html_e( 'Schema Preview', 'twt-aeo-ultimate' ); ?>
								</button>
							</div>

							<div class="twt-aeo-schema-preview" id="twt-aeo-schema-<?php echo esc_attr( $author['id'] ); ?>" style="display:none;">
								<?php
								$schema = TWTAEO_Author_Schema_Writer::build_schema( $author['id'] );
								if ( $schema ) : ?>
								<pre class="twt-aeo-schema-pre"><?php echo esc_html( wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
								<?php else : ?>
								<p class="twt-aeo-muted" style="padding:12px;"><?php esc_html_e( 'No schema data yet — edit the profile to add information.', 'twt-aeo-ultimate' ); ?></p>
								<?php endif; ?>
							</div>

						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<!-- E-E-A-T Explanation -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Why Author Entities Matter', 'twt-aeo-ultimate' ); ?>
			</h2>
			<div class="twt-aeo-card-grid">
				<div class="twt-aeo-card">
					<h3 class="twt-aeo-card__title"><?php esc_html_e( 'Certifications', 'twt-aeo-ultimate' ); ?></h3>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'EducationalOccupationalCredential schema helps AI and search engines validate topical authority and real-world expertise.', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div class="twt-aeo-card">
					<h3 class="twt-aeo-card__title"><?php esc_html_e( 'sameAs Profiles', 'twt-aeo-ultimate' ); ?></h3>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'Linking social profiles via sameAs establishes entity consistency across the web — a key signal for Google Knowledge Panels and AI citation.', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div class="twt-aeo-card">
					<h3 class="twt-aeo-card__title"><?php esc_html_e( 'Completeness Score', 'twt-aeo-ultimate' ); ?></h3>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'A fully completed author profile strengthens E-E-A-T signals, improves AI retrieval confidence, and helps establish topical authority clusters.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render inner content for embedding in other pages (e.g. the E-E-A-T tabs).
	 * Uses 'sub' query param for the Author Box / Author Entity sub-tab.
	 *
	 * @param string $base_url The URL to build sub-tab links from.
	 */
	public static function render_content( string $base_url = '' ): void {
		global $twtaeo_plugin;
		$modules       = $twtaeo_plugin ? $twtaeo_plugin->modules : null;
		$box_active    = $modules ? $modules->is_active( 'author-box' )    : true;
		$entity_active = $modules ? $modules->is_active( 'author-entity' ) : true;

		$default_sub = $box_active ? 'author-box' : 'author-entity';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_sub  = isset( $_GET['sub'] ) && in_array( sanitize_key( wp_unslash( $_GET['sub'] ) ), array( 'author-box', 'author-entity' ), true )
			? sanitize_key( wp_unslash( $_GET['sub'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: $default_sub;

		?>
		<nav class="twt-aeo-tabs">
			<?php if ( $box_active ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'sub', 'author-box', $base_url ) ); ?>"
			   class="twt-aeo-tab <?php echo 'author-box' === $active_sub ? 'twt-aeo-tab--active' : ''; ?>">
				<span class="dashicons dashicons-id"></span>
				<?php esc_html_e( 'Author Box', 'twt-aeo-ultimate' ); ?>
			</a>
			<?php endif; ?>
			<?php if ( $entity_active ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'sub', 'author-entity', $base_url ) ); ?>"
			   class="twt-aeo-tab <?php echo 'author-entity' === $active_sub ? 'twt-aeo-tab--active' : ''; ?>">
				<span class="dashicons dashicons-admin-users"></span>
				<?php esc_html_e( 'Author Entity', 'twt-aeo-ultimate' ); ?>
			</a>
			<?php endif; ?>
		</nav>

		<?php if ( 'author-box' === $active_sub && $box_active ) : ?>
			<?php self::render_author_box(); ?>
		<?php elseif ( 'author-entity' === $active_sub && $entity_active ) : ?>
			<?php self::render_author_entity(); ?>
		<?php endif; ?>
		<?php
	}

	private static function get_domain_label( $url ) {
		$host   = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
		$host   = preg_replace( '/^www\./', '', $host );
		$domain = explode( '.', $host )[0];

		$labels = array(
			'linkedin'  => 'LinkedIn',
			'facebook'  => 'Facebook',
			'twitter'   => 'Twitter',
			'x'         => 'X',
			'github'    => 'GitHub',
			'youtube'   => 'YouTube',
			'instagram' => 'Instagram',
		);

		return $labels[ strtolower( $domain ) ] ?? ucfirst( $domain );
	}

	private static function seo_plugin_handles_person() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm = get_option( 'rank_math_titles', array() );
			if ( isset( $rm['knowledgegraph_type'] ) && $rm['knowledgegraph_type'] === 'person' ) {
				return 'Rank Math';
			}
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_social', array() );
			if ( ! empty( $wpseo['person_name'] ) ) {
				return 'Yoast SEO';
			}
		}
		return false;
	}
}
