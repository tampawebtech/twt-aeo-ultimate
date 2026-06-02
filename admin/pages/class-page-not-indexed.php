<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Page_Not_Indexed {

	const PREVIEW_LIMIT = 15;

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$scan_results   = TWTAEO_Index_Status::get_results();
		$not_indexed    = array();

		foreach ( $scan_results as $post_id => $result ) {
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
		$preview     = array_slice( $not_indexed, 0, self::PREVIEW_LIMIT );

		$settings      = get_option( 'twtaeo_settings', array() );
		$pro_connected = ! empty( $settings['pro_enabled'] ) && ! empty( $settings['pro_url'] );
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
					if ( $total_count > self::PREVIEW_LIMIT ) {
						printf(
							// translators: %1$d: preview limit. %2$d: total not-indexed pages. %3$s: link or text to view more.
							esc_html__( 'Showing %1$d of %2$d not-indexed pages. %3$s for the complete list with detailed analysis.', 'twt-aeo-ultimate' ),
							absint( self::PREVIEW_LIMIT ),
							absint( $total_count ),
							$pro_connected
								? '<a href="' . esc_url( $settings['pro_url'] ) . '" target="_blank">' . esc_html__( 'View TWT Agency', 'twt-aeo-ultimate' ) . '</a>'
								: esc_html__( 'Connect TWT Agency', 'twt-aeo-ultimate' )
						);
					} else {
						printf(
							// translators: %d: number of published pages not in Google.
							esc_html__( '%d page(s) are published but not appearing in Google search results.', 'twt-aeo-ultimate' ),
							absint( $total_count )
						);
					}
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
						<?php foreach ( $preview as $item ) :
							$flags      = $item['heuristics']['flags'] ?? array();
							$word_count = $item['heuristics']['data']['word_count'] ?? null;
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
											<span class="twt-aeo-tag twt-aeo-tag--missing" title="<?php echo esc_attr( $flag['detail'] ); ?>">
												<?php echo esc_html( $flag['label'] ); ?>
											</span>
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

			<?php if ( $pro_connected || $total_count > self::PREVIEW_LIMIT ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
					<div>
						<strong><?php esc_html_e( 'Want the full picture?', 'twt-aeo-ultimate' ); ?></strong>
						<p style="margin:4px 0 0;color:#646970;font-size:13px;">
							<?php esc_html_e( 'TWT Agency shows all not-indexed pages with AI-powered analysis — including why each page is excluded, heuristic flags, GSC reasons, and a recommended fix for each URL.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
					<?php if ( $pro_connected ) : ?>
					<a href="<?php echo esc_url( rtrim( $settings['pro_url'], '/' ) . '/wp-admin/admin.php?page=twt-pro-not-indexed' ); ?>" target="_blank" class="twt-aeo-btn twt-aeo-btn--primary" style="white-space:nowrap;">
						<?php esc_html_e( 'More Stats in TWT Agency →', 'twt-aeo-ultimate' ); ?>
					</a>
					<?php else : ?>
					<span style="font-size:12px;color:#646970;"><?php esc_html_e( 'Connect TWT Agency in Settings to unlock full analysis.', 'twt-aeo-ultimate' ); ?></span>
					<?php endif; ?>
				</div>
			</section>
			<?php endif; ?>

			<?php endif; ?>

		</div>
		<?php
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
