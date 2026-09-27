<?php
/**
 * Funnel Audit Page
 *
 * The Money Page Funnel & Citability Auditor: shows every scanned page with
 * its money/informational classification, AI attention (crawler hits +
 * referrals from AI engines), click depth, inbound internal links, schema
 * status, and recommended actions. Engine lives in TWTAEO_Funnel_Audit.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Funnel_Audit {

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		// Manual money-page override toggle.
		if ( isset( $_POST['twtaeo_funnel_override'], $_POST['twtaeo_funnel_post'] ) && check_admin_referer( 'twtaeo_funnel_override' ) ) {
			TWTAEO_Funnel_Audit::set_override(
				absint( $_POST['twtaeo_funnel_post'] ),
				sanitize_key( wp_unslash( $_POST['twtaeo_funnel_override'] ) )
			);
			TWTAEO_Funnel_Audit::build();
		}

		// Rebuild button.
		if ( isset( $_POST['twtaeo_funnel_rebuild'] ) && check_admin_referer( 'twtaeo_funnel_rebuild' ) ) {
			TWTAEO_Funnel_Audit::build();
		}

		$audit = TWTAEO_Funnel_Audit::get_audit();

		$class_labels = array(
			'money' => array( '#00753a', __( 'Money Page', 'twt-aeo-ultimate' ) ),
			'info'  => array( '#2271b1', __( 'Informational', 'twt-aeo-ultimate' ) ),
			'home'  => array( '#646970', __( 'Homepage', 'twt-aeo-ultimate' ) ),
		);
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Funnel Audit', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'AI engines cite informational posts, not money pages — and the visitors they send leak away without a path to conversion. This audit connects AI attention (crawler hits + referrals from ChatGPT, Perplexity, Claude, Gemini) with your internal link structure to show which money pages are buried and which cited posts have no bridge to them.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<?php if ( empty( $audit['rows'] ) ) : ?>
						<p><?php esc_html_e( 'No audit yet. The audit rebuilds automatically once a week; run the first one now.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<p>
							<?php
							printf(
								/* translators: 1: date/time, 2: pages scanned. */
								esc_html__( 'Last built %1$s — %2$d pages scanned. AI attention counts cover the last 30 days.', 'twt-aeo-ultimate' ),
								esc_html( $audit['built_at'] ),
								(int) $audit['scanned']
							);
							?>
						</p>
					<?php endif; ?>
					<form method="post">
						<?php wp_nonce_field( 'twtaeo_funnel_rebuild' ); ?>
						<button type="submit" name="twtaeo_funnel_rebuild" value="1" class="button button-primary">
							<?php echo empty( $audit['rows'] ) ? esc_html__( 'Build audit now', 'twt-aeo-ultimate' ) : esc_html__( 'Rebuild audit', 'twt-aeo-ultimate' ); ?>
						</button>
					</form>
				</div>
			</div>

			<?php if ( ! empty( $audit['rows'] ) ) : ?>
				<div class="twt-aeo-section">
					<div class="twt-aeo-card" style="padding:0;overflow-x:auto;">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Type', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:110px"><?php esc_html_e( 'AI attention (30d)', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:80px"><?php esc_html_e( 'Click depth', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:80px"><?php esc_html_e( 'Inbound links', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Schema', 'twt-aeo-ultimate' ); ?></th>
									<th style="min-width:260px"><?php esc_html_e( 'Action needed', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:110px"><?php esc_html_e( 'Override', 'twt-aeo-ultimate' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $audit['rows'] as $row ) : ?>
								<?php
								$class     = isset( $class_labels[ $row['class'] ] ) ? $row['class'] : 'info';
								$attention = (int) $row['referrals'] + (int) $row['crawls'];
								$depth     = null === $row['depth'] ? __( 'Orphan', 'twt-aeo-ultimate' ) : (string) (int) $row['depth'];
								?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><strong><?php echo esc_html( $row['title'] ? $row['title'] : $row['path'] ); ?></strong></a><br>
										<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#646970"><?php echo esc_html( $row['path'] ); ?></a>
									</td>
									<td><strong style="color:<?php echo esc_attr( $class_labels[ $class ][0] ); ?>"><?php echo esc_html( $class_labels[ $class ][1] ); ?></strong></td>
									<td>
										<?php
										if ( $attention ) {
											printf(
												/* translators: 1: crawler hits, 2: referral visits. */
												esc_html__( '%1$d crawls, %2$d visits', 'twt-aeo-ultimate' ),
												(int) $row['crawls'],
												(int) $row['referrals']
											);
										} else {
											echo '<span style="color:#646970">0</span>';
										}
										?>
									</td>
									<td style="text-align:center">
										<strong style="color:<?php echo ( null === $row['depth'] || $row['depth'] >= 3 ) ? '#d63638' : '#1d2327'; ?>"><?php echo esc_html( $depth ); ?></strong>
									</td>
									<td style="text-align:center"><?php echo (int) $row['inbound']; ?></td>
									<td>
										<?php if ( $row['schema'] ) : ?>
											<code style="font-size:11px"><?php echo esc_html( $row['schema'] ); ?></code>
										<?php else : ?>
											<span style="color:#996800"><?php esc_html_e( 'None detected', 'twt-aeo-ultimate' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( empty( $row['actions'] ) ) : ?>
											<span style="color:#00753a">✓ <?php esc_html_e( 'Optimized', 'twt-aeo-ultimate' ); ?></span>
										<?php else : ?>
											<ul style="margin:0 0 0 16px">
												<?php foreach ( $row['actions'] as $action ) : ?>
													<li><?php echo esc_html( $action ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'home' !== $row['class'] ) : ?>
											<form method="post">
												<?php wp_nonce_field( 'twtaeo_funnel_override' ); ?>
												<input type="hidden" name="twtaeo_funnel_post" value="<?php echo esc_attr( $row['id'] ); ?>">
												<button type="submit" name="twtaeo_funnel_override" value="<?php echo 'money' === $row['class'] ? 'no' : 'yes'; ?>" class="button button-small">
													<?php echo 'money' === $row['class'] ? esc_html__( 'Not a money page', 'twt-aeo-ultimate' ) : esc_html__( 'Mark as money page', 'twt-aeo-ultimate' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description">
						<?php esc_html_e( 'Money pages are detected automatically from WooCommerce products, EDD downloads, service/contact/location page intent, and lead-gen forms — use Override where the guess is wrong. AI attention combines AI Crawler Watch hits (bots reading the page) with visits referred from AI engines (detected via referrer and ChatGPT\'s utm_source). Referral counting starts now, so those numbers grow from today.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
