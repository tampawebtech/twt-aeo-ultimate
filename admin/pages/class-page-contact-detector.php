<?php
/**
 * Contact Detector Page
 *
 * Shows all contact pages with their entity schema completeness.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Contact_Detector {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$results = TWTAEO_Contact_Detector::scan_all();
		$summary = TWTAEO_Contact_Detector::get_summary();

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Contact Detector', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub" style="margin-left:auto;">
						<?php
						printf(
							// translators: %1$d: number of contact pages. %2$d: pages needing attention.
							esc_html__( '%1$d contact pages found — %2$d need attention', 'twt-aeo-ultimate' ),
							absint( $summary['total'] ),
							absint( $summary['needs_work'] )
						);
						?>
					</p>
				</div>
			</div>

			<!-- Summary Cards -->
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

			<!-- What This Checks -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What This Checks', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'For each contact page, the detector validates:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul class="twt-aeo-checklist">
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Organization or LocalBusiness schema — confirms your business entity is defined', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'PostalAddress — street, city, state, zip, country', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'ContactPoint — phone number and/or email address', 'twt-aeo-ultimate' ); ?>
						</li>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Entity consolidation — only one Organization entity across the page', 'twt-aeo-ultimate' ); ?>
						</li>
					</ul>
				</div>
			</section>

			<!-- Results Table -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Contact Pages', 'twt-aeo-ultimate' ); ?></h2>

				<?php if ( empty( $results ) ) : ?>
					<div class="twt-aeo-card">
						<p class="twt-aeo-empty">
							<?php esc_html_e( 'No contact pages detected. Pages with "contact" in the slug, title, or content will appear here.', 'twt-aeo-ultimate' ); ?>
						</p>
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
							$post    = $item['post'];
							$contact = $item['contact_data'];
							$row_cls = empty( $contact['missing'] ) ? 'twt-aeo-page-row--ok' : 'twt-aeo-page-row--issues';
						?>
							<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>">

								<td class="twt-aeo-page-row__title">
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $post ) ); ?>
									</a>
									<span class="twt-aeo-page-row__type"><?php echo esc_html( $post->post_type ); ?></span>
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

								<td>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link">
										<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
									</a>
									·
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="twt-aeo-link">
										<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?>
									</a>
								</td>

							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<!-- Guidance -->
			<?php if ( $summary['needs_work'] > 0 ) : ?>
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'How to Fix Contact Page Schema', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<ul class="twt-aeo-checklist">

						<?php if ( defined( 'RANK_MATH_VERSION' ) ) : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Rank Math: Go to Rank Math → Titles & Meta → Local SEO and fill in your business name, address, phone, and email.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php elseif ( defined( 'WPSEO_VERSION' ) ) : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Yoast SEO: Go to Yoast → Company Info and fill in your organization name, logo, and contact details.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php else : ?>
						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Use Schema & Structured Data For WP or Rank Math to add Organization, PostalAddress, and ContactPoint schema to your contact page.', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php endif; ?>

						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'For entity consolidation — make sure all schema on the page uses the same @id value for your Organization so AI systems recognize them as the same entity.', 'twt-aeo-ultimate' ); ?>
						</li>

						<li class="twt-aeo-checklist__item twt-aeo-checklist__item--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Once saved, TWT AEO Ultimate will automatically rescan and update the status here.', 'twt-aeo-ultimate' ); ?>
						</li>

					</ul>
				</div>
			</section>
			<?php endif; ?>

		</div>
		<?php
	}
}