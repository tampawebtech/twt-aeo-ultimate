<?php
/**
 * PR Bridge — Media Report Page
 *
 * Lists all posts that have been submitted to wire services,
 * their distribution status, and links to published releases.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_PR_Bridge {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$distributed = TWTAEO_PR_Distributor::get_all_distributed_posts();
		$settings    = get_option( 'twtaeo_settings', array() );
		$has_claude  = ! empty( trim( $settings['api_claude'] ?? '' ) );
		$has_ein     = ! empty( trim( $settings['api_ein_presswire'] ?? '' ) );
		$has_easy    = ! empty( trim( $settings['api_easypwire'] ?? '' ) );
		$has_wire    = $has_ein || $has_easy;
		$settings_url = admin_url( 'admin.php?page=twt-aeo-settings' );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'PR Bridge — Media Report', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Track every press release distributed through PR Bridge AI.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<?php if ( ! $has_claude || ! $has_wire ) : ?>
			<div class="twt-aeo-pr-page-setup">
				<span class="dashicons dashicons-admin-network twt-aeo-pr-page-setup__icon"></span>
				<div class="twt-aeo-pr-page-setup__body">
					<strong><?php esc_html_e( 'Finish setting up PR Bridge AI', 'twt-aeo-ultimate' ); ?></strong>
					<ul class="twt-aeo-pr-page-setup__list">
						<?php if ( ! $has_claude ) : ?>
						<li>
							<span class="dashicons dashicons-no-alt twt-aeo-pr-page-setup__x"></span>
							<?php esc_html_e( 'Claude API key — needed to transform posts into AP-style copy', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php else : ?>
						<li>
							<span class="dashicons dashicons-yes twt-aeo-pr-page-setup__check"></span>
							<?php esc_html_e( 'Claude API key — configured', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php endif; ?>

						<?php if ( ! $has_wire ) : ?>
						<li>
							<span class="dashicons dashicons-no-alt twt-aeo-pr-page-setup__x"></span>
							<?php esc_html_e( 'Wire service key — needed to distribute (EIN Presswire or EasyPRWire)', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php else : ?>
						<li>
							<span class="dashicons dashicons-yes twt-aeo-pr-page-setup__check"></span>
							<?php esc_html_e( 'Wire service key — configured', 'twt-aeo-ultimate' ); ?>
						</li>
						<?php endif; ?>
					</ul>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary twt-aeo-pr-page-setup__btn">
						<?php esc_html_e( 'Go to Settings', 'twt-aeo-ultimate' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( empty( $distributed ) ) : ?>
			<div class="twt-aeo-card twt-aeo-pr-empty">
				<span class="dashicons dashicons-megaphone twt-aeo-pr-empty__icon"></span>
				<p><?php esc_html_e( 'No press releases distributed yet.', 'twt-aeo-ultimate' ); ?></p>
				<p class="twt-aeo-pr-empty__hint">
					<?php esc_html_e( 'When you submit a post to a wire service via the PR Bridge panel, it will appear here.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
			<?php else : ?>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card" style="padding:0;">
					<table class="twt-aeo-pr-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post / Page', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Provider', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Submitted', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Release Link', 'twt-aeo-ultimate' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $distributed as $post ) : ?>
								<?php $submissions = TWTAEO_PR_Distributor::get_submissions( $post->ID ); ?>
								<?php foreach ( array_reverse( $submissions ) as $sub ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
										<span class="twt-aeo-pr-table__type">
											<?php echo esc_html( $post->post_type ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $sub['provider'] ); ?></td>
									<td>
										<?php
										echo esc_html(
											date_i18n(
												get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
												strtotime( $sub['submitted_at'] )
											)
										);
										?>
									</td>
									<td>
										<?php if ( $sub['success'] ) : ?>
											<span class="twt-aeo-pr-badge twt-aeo-pr-badge--ok">
												<?php esc_html_e( 'Distributed', 'twt-aeo-ultimate' ); ?>
											</span>
										<?php else : ?>
											<span class="twt-aeo-pr-badge twt-aeo-pr-badge--err" title="<?php echo esc_attr( $sub['message'] ); ?>">
												<?php esc_html_e( 'Failed', 'twt-aeo-ultimate' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $sub['url'] ) ) : ?>
											<a href="<?php echo esc_url( $sub['url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'View release', 'twt-aeo-ultimate' ); ?>
												<span class="dashicons dashicons-external" style="font-size:12px;vertical-align:middle;"></span>
											</a>
										<?php else : ?>
											<span style="color:#9ca3af;">—</span>
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Edit Post', 'twt-aeo-ultimate' ); ?>
										</a>
									</td>
								</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="twt-aeo-pr-report-meta">
				<?php
				printf(
					esc_html(
						// translators: %d: number of press releases distributed.
						_n( '%d release distributed', '%d releases distributed', count( $distributed ), 'twt-aeo-ultimate' )
					),
					absint( count( $distributed ) )
				);
				?>
			</div>

			<?php endif; ?>

		</div>
		<?php
	}
}
