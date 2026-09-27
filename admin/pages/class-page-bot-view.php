<?php
/**
 * Bot View Page
 *
 * Fetches any URL the way a no-JavaScript AI crawler does (and once more as a
 * browser, to expose firewalls and challenge pages), then reports which
 * structured data and content is visible, which is JavaScript-locked, and
 * which is recoverable server-side. Also shows the weekly site-wide scan.
 * Engine lives in TWTAEO_Bot_View.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Bot_View {

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$url    = '';
		$report = null;
		$error  = '';

		// Manual site-wide scan.
		if ( isset( $_POST['twtaeo_bot_view_site_scan'] ) && check_admin_referer( 'twtaeo_bot_view_site_scan' ) ) {
			TWTAEO_Bot_View::run_site_scan();
		}

		// Single-URL audit.
		if ( isset( $_POST['twtaeo_bot_view_url'] ) && check_admin_referer( 'twtaeo_bot_view' ) ) {
			$url    = esc_url_raw( wp_unslash( $_POST['twtaeo_bot_view_url'] ) );
			$result = TWTAEO_Bot_View::audit( $url );

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$report = $result;
			}
		}

		$site_scan = TWTAEO_Bot_View::get_site_scan();

		$levels = array(
			'good'     => array( '#00753a', __( 'Good', 'twt-aeo-ultimate' ) ),
			'info'     => array( '#2271b1', __( 'Info', 'twt-aeo-ultimate' ) ),
			'warn'     => array( '#996800', __( 'Warning', 'twt-aeo-ultimate' ) ),
			'critical' => array( '#d63638', __( 'Critical', 'twt-aeo-ultimate' ) ),
		);
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Bot View', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'See any page the way AI crawlers do — raw HTML, no JavaScript. Google runs JavaScript; GPTBot, ClaudeBot, PerplexityBot and most AI agents do not. Every audit also fetches the page as a normal browser and compares the two, exposing firewalls and challenge pages that silently blind AI crawlers.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<form method="post">
						<?php wp_nonce_field( 'twtaeo_bot_view' ); ?>
						<label class="screen-reader-text" for="twtaeo-bot-view-url"><?php esc_html_e( 'URL to audit', 'twt-aeo-ultimate' ); ?></label>
						<input
							type="url"
							id="twtaeo-bot-view-url"
							name="twtaeo_bot_view_url"
							class="regular-text"
							style="width:480px;max-width:100%"
							value="<?php echo esc_attr( $url ? $url : home_url( '/' ) ); ?>"
							placeholder="https://example.com/page/"
							required
						/>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'View as AI crawler', 'twt-aeo-ultimate' ); ?></button>
					</form>
				</div>
			</div>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<div class="twt-aeo-section">
					<h2>
						<?php
						printf(
							/* translators: %s: audited URL. */
							esc_html__( 'Report for %s', 'twt-aeo-ultimate' ),
							esc_html( $url )
						);
						?>
					</h2>

					<?php if ( ! empty( $report['meta'] ) ) : ?>
						<p class="description" style="margin-top:-4px">
							<?php
							printf(
								/* translators: 1: bot HTTP code, 2: bot KB, 3: browser HTTP code, 4: browser KB. */
								esc_html__( 'As AI crawler: HTTP %1$d, %2$d KB — as browser: HTTP %3$d, %4$d KB', 'twt-aeo-ultimate' ),
								(int) $report['meta']['bot_code'],
								(int) ( $report['meta']['bot_size'] / 1024 ),
								(int) $report['meta']['browser_code'],
								(int) ( $report['meta']['browser_size'] / 1024 )
							);
							?>
						</p>
					<?php endif; ?>

					<div class="twt-aeo-card" style="padding:0;">
						<table class="widefat striped">
							<tbody>
							<?php foreach ( $report['findings'] as $finding ) : ?>
								<?php $level = isset( $levels[ $finding['level'] ] ) ? $finding['level'] : 'info'; ?>
								<tr>
									<td style="width:90px;vertical-align:top">
										<strong style="color:<?php echo esc_attr( $levels[ $level ][0] ); ?>"><?php echo esc_html( $levels[ $level ][1] ); ?></strong>
									</td>
									<td>
										<strong><?php echo esc_html( $finding['title'] ); ?></strong>
										<p style="margin:4px 0 0"><?php echo esc_html( $finding['detail'] ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<?php if ( ! empty( $report['recovered'] ) ) : ?>
					<div class="twt-aeo-section">
						<h3><?php esc_html_e( 'Data hidden inside scripts (recoverable server-side)', 'twt-aeo-ultimate' ); ?></h3>
						<div class="twt-aeo-card" style="padding:0;">
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Source', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Fields detected', 'twt-aeo-ultimate' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $report['recovered'] as $item ) : ?>
									<tr>
										<td><?php echo esc_html( $item['source'] ); ?></td>
										<td><code><?php echo esc_html( implode( ', ', $item['keys'] ) ); ?></code></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p class="description">
							<?php esc_html_e( 'These values already ship in the page HTML as JSON for JavaScript to use — crawlers simply ignore script contents. Any of them missing from your static schema are safe candidates to re-emit as server-rendered JSON-LD, since the data source is your own site.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="twt-aeo-section" id="twtaeo-site-scan">
				<h2><?php esc_html_e( 'Site-wide scan', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<?php if ( empty( $site_scan['pages'] ) ) : ?>
						<p><?php esc_html_e( 'Runs automatically once a week over your homepage and your most recently updated posts, pages and products. No scan has completed yet.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<p>
							<?php
							printf(
								/* translators: 1: date/time, 2: pages scanned, 3: critical count, 4: warning count. */
								esc_html__( 'Last scan %1$s — %2$d pages: %3$d critical, %4$d warnings.', 'twt-aeo-ultimate' ),
								esc_html( $site_scan['scanned_at'] ),
								count( $site_scan['pages'] ),
								(int) $site_scan['totals']['critical'],
								(int) $site_scan['totals']['warn']
							);
							?>
							<?php if ( ! empty( $site_scan['regressions'] ) ) : ?>
								<strong style="color:#d63638">
									<?php
									printf(
										/* translators: %d: number of pages. */
										esc_html( _n( '%d page got worse since the previous scan.', '%d pages got worse since the previous scan.', count( $site_scan['regressions'] ), 'twt-aeo-ultimate' ) ),
										count( $site_scan['regressions'] )
									);
									?>
								</strong>
							<?php endif; ?>
						</p>

						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:80px"><?php esc_html_e( 'Critical', 'twt-aeo-ultimate' ); ?></th>
									<th style="width:80px"><?php esc_html_e( 'Warnings', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Findings', 'twt-aeo-ultimate' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $site_scan['pages'] as $page ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $page['url'], PHP_URL_PATH ) ? wp_parse_url( $page['url'], PHP_URL_PATH ) : $page['url'] ); ?></a></td>
									<td><strong style="color:<?php echo $page['critical'] ? '#d63638' : '#00753a'; ?>"><?php echo (int) $page['critical']; ?></strong></td>
									<td><strong style="color:<?php echo $page['warn'] ? '#996800' : '#00753a'; ?>"><?php echo (int) $page['warn']; ?></strong></td>
									<td>
										<?php
										if ( ! empty( $page['error'] ) ) {
											echo esc_html( $page['error'] );
										} elseif ( empty( $page['issues'] ) ) {
											echo '<span style="color:#00753a">' . esc_html__( 'No critical or warning findings.', 'twt-aeo-ultimate' ) . '</span>';
										} else {
											foreach ( $page['issues'] as $issue ) {
												// Scans stored before 2.21.x kept only the titles.
												if ( ! is_array( $issue ) ) {
													echo '<div>' . esc_html( $issue ) . '</div>';
													continue;
												}
												$fix = self::fix_link( $issue['id'], $page['url'] );
												?>
												<details style="margin:2px 0">
													<summary style="cursor:pointer">
														<strong style="color:<?php echo 'critical' === $issue['level'] ? '#d63638' : '#996800'; ?>">
															<?php echo 'critical' === $issue['level'] ? esc_html__( 'Critical:', 'twt-aeo-ultimate' ) : esc_html__( 'Warning:', 'twt-aeo-ultimate' ); ?>
														</strong>
														<?php echo esc_html( $issue['title'] ); ?>
													</summary>
													<div style="padding:6px 0 4px 16px">
														<?php if ( '' !== $issue['detail'] ) : ?>
															<p style="margin:0 0 6px;max-width:640px"><?php echo esc_html( $issue['detail'] ); ?></p>
														<?php endif; ?>
														<?php if ( $fix ) : ?>
															<a class="button button-small" href="<?php echo esc_url( $fix['url'] ); ?>"><?php echo esc_html( $fix['label'] ); ?></a>
														<?php endif; ?>
													</div>
												</details>
												<?php
											}
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<form method="post" style="margin-top:12px">
						<?php wp_nonce_field( 'twtaeo_bot_view_site_scan' ); ?>
						<button type="submit" name="twtaeo_bot_view_site_scan" value="1" class="button">
							<?php esc_html_e( 'Run site scan now', 'twt-aeo-ultimate' ); ?>
						</button>
						<span class="description" style="margin-left:8px"><?php esc_html_e( 'Fetches up to 10 pages twice each — takes a minute.', 'twt-aeo-ultimate' ); ?></span>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Where to go to fix a site-scan finding, keyed by finding id.
	 *
	 * @param string $issue_id Finding id from TWTAEO_Bot_View.
	 * @param string $page_url URL of the audited page.
	 * @return array{url:string,label:string}|null Null when the fix lives outside wp-admin
	 *                                             (firewall, CDN, theme) — the finding's
	 *                                             detail text says where.
	 */
	private static function fix_link( $issue_id, $page_url ) {
		switch ( $issue_id ) {
			case 'noindex':
				$post_id = url_to_postid( $page_url );
				$edit    = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
				return $edit ? array(
					'url'   => $edit,
					'label' => __( 'Edit this page', 'twt-aeo-ultimate' ),
				) : null;

			case 'no-static-schema':
			case 'gtm-schema':
				return array(
					'url'   => admin_url( 'admin.php?page=twt-aeo-schema-detector' ),
					'label' => __( 'Fix on the Schema Detector', 'twt-aeo-ultimate' ),
				);

			case 'product-schema-gaps':
			case 'no-product-schema':
				$post_id = url_to_postid( $page_url );
				$edit    = ( $post_id && 'product' === get_post_type( $post_id ) ) ? get_edit_post_link( $post_id, 'raw' ) : '';
				return $edit ? array(
					'url'   => $edit,
					'label' => __( 'Edit this product', 'twt-aeo-ultimate' ),
				) : array(
					'url'   => admin_url( 'admin.php?page=twt-aeo-woocommerce' ),
					'label' => __( 'Open WooCommerce AEO', 'twt-aeo-ultimate' ),
				);

			case 'client-side-reviews':
				return array(
					'url'   => admin_url( 'admin.php?page=twt-aeo-reviews' ),
					'label' => __( 'Open the Reviews module', 'twt-aeo-ultimate' ),
				);

			case 'missing-metadata':
				return array(
					'url'   => admin_url( 'admin.php?page=twt-aeo-classic' ),
					'label' => __( 'Generate metadata on AEO Score', 'twt-aeo-ultimate' ),
				);
		}
		return null;
	}
}
