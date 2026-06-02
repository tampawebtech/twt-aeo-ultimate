<?php
/**
 * Author Box Detector Page
 *
 * Shows site-wide author box detection status and author schema presence.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Author_Box {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$scan = TWTAEO_Author_Box_Detector::scan_site();

		$has_box      = $scan['has_author_box'];
		$source       = $scan['source'];
		$schema       = $scan['author_schema_present'];
		$patterns     = $scan['patterns_found'];
		$posts_checked = $scan['post_count_checked'];
		$author       = $scan['author_info'];

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Author Box', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub" style="margin-left:auto;">
						<?php esc_html_e( 'Site-wide author box and author schema status', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-summary-grid">

					<div class="twt-aeo-summary-card <?php echo $has_box ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
						<div class="twt-aeo-summary-card__number">
							<?php echo $has_box ? '✓' : '✗'; ?>
						</div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Author Box', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $schema ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
						<div class="twt-aeo-summary-card__number">
							<?php echo $schema ? '✓' : '✗'; ?>
						</div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Author Schema', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $author['has_bio'] ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--alert'; ?>">
						<div class="twt-aeo-summary-card__number">
							<?php echo $author['has_bio'] ? '✓' : '✗'; ?>
						</div>
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
								<td>
									<span class="twt-aeo-badge twt-aeo-badge--page"><?php echo esc_html( $posts_checked ); ?></span>
								</td>
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

		</div>
		<?php
	}
}