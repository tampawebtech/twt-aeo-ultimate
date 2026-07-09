<?php
/**
 * Author Entity Page
 *
 * Shows all site authors with E-E-A-T completeness scores, certification
 * previews, social profile status, and schema output preview.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Author_Entity {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$authors      = TWTAEO_Author_Meta::get_all_authors();
		$total        = count( $authors );
		$avg_score    = $total ? (int) round( array_sum( array_column( array_column( $authors, 'completeness' ), 'score' ) ) / $total ) : 0;
		$with_certs   = count( array_filter( $authors, function( $a ) { return ! empty( $a['certifications'] ); } ) );
		$with_socials = count( array_filter( $authors, function( $a ) { return ! empty( TWTAEO_Author_Meta::get_same_as( $a['id'] ) ); } ) );

		$seo_plugin_handles = self::seo_plugin_handles_person();

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Author Entity', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub" style="margin-left:auto;">
						<?php esc_html_e( 'E-E-A-T profiles, certifications, social identity and schema', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

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
			<!-- Schema deferral notice -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-card" style="border-left:4px solid var(--aeo-accent);padding:16px 20px;display:flex;align-items:center;gap:12px;">
					<span class="dashicons dashicons-plugins-checked" style="color:var(--aeo-accent);font-size:22px;width:22px;height:22px;flex-shrink:0;"></span>
					<p style="margin:0;font-size:13px;color:var(--aeo-text);">
						<?php
						printf(
							/* translators: %s: name of the active SEO plugin handling Person schema. */
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

								<!-- Avatar + Name row -->
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

								<!-- Completeness bar -->
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

								<!-- Quick stats -->
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

								<!-- Certifications preview -->
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

								<!-- Social sameAs preview -->
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

								<!-- Schema preview toggle -->
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

								<!-- Schema JSON preview (hidden by default) -->
								<div class="twt-aeo-schema-preview" id="twt-aeo-schema-<?php echo esc_attr( $author['id'] ); ?>" style="display:none;">
									<?php
									$schema = TWTAEO_Author_Schema_Writer::build_schema( $author['id'] );
									if ( $schema ) :
										?>
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

		</div>
		<?php
	}

	/**
	 * Get a short label from a URL (e.g. "linkedin.com" → "LinkedIn").
	 *
	 * @param string $url
	 * @return string
	 */
	private static function get_domain_label( $url ) {
		$host    = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
		$host    = preg_replace( '/^www\./', '', $host );
		$domain  = explode( '.', $host )[0];

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

	/**
	 * Return the name of the SEO plugin handling Person schema, or false.
	 *
	 * @return string|false
	 */
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
