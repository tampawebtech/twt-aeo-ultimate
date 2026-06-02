<?php
/**
 * AEO Command Center Page
 *
 * Tabbed dashboard for Google Analytics (GA4), Google Search Console,
 * and Bing Webmaster Tools integrations.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Command_Center {

	const NONCE_SAVE_GOOGLE      = 'twtaeo_cc_save_google';
	const NONCE_SAVE_BING        = 'twtaeo_cc_save_bing';
	const NONCE_DISCONNECT       = 'twtaeo_cc_disconnect';
	const NONCE_CLEAR_CRAWLERLOG = 'twtaeo_cc_clear_crawler_log';

	// ── Entry Point ──────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		// Handle Google OAuth callback.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['code'], $_GET['state'] ) ) {
			self::handle_google_callback(
				sanitize_text_field( wp_unslash( $_GET['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				sanitize_text_field( wp_unslash( $_GET['state'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		// Bust data cache on refresh request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['twtaeo_refresh'] ) ) {
			self::clear_data_cache();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- refresh is a read-only cache bust, no state change
			wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-command-center&twtaeo_tab=' . self::validate_tab( isset( $_GET['twtaeo_tab'] ) ? sanitize_key( wp_unslash( $_GET['twtaeo_tab'] ) ) : '' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = self::validate_tab( isset( $_GET['twtaeo_tab'] ) ? sanitize_key( wp_unslash( $_GET['twtaeo_tab'] ) ) : '' );

		// Handle IndexNow form submissions when on the Bing tab (before any HTML output).
		if ( $active_tab === 'bing' ) {
			$bing_tab_url = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'bing' ), admin_url( 'admin.php' ) );
			TWTAEO_Page_Index_Now::handle_posts( $bing_tab_url );
		}

		$google_connected = TWTAEO_Google_OAuth::is_connected();
		$bing_connected   = TWTAEO_Bing_Webmaster::is_connected();

		?>
		<div class="wrap twt-aeo-wrap twt-aeo-cc">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Command Center', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Connect your analytics and webmaster tools for a unified AEO performance view.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<?php self::render_tab_nav( $active_tab, $google_connected, $bing_connected ); ?>

			<div class="twt-aeo-cc__content">
				<?php
				switch ( $active_tab ) {
					case 'analytics':
						self::render_analytics_tab( $google_connected );
						break;
					case 'search-console':
						self::render_gsc_tab( $google_connected );
						break;
					case 'bing':
						self::render_bing_tab( $bing_connected );
						break;
					case 'crawlability':
						self::render_crawlability_tab();
						break;
					case 'index-status':
						self::render_index_status_tab();
						break;
					case 'ai-crawler':
						self::render_ai_crawler_tab();
						break;
					case 'settings':
						self::render_settings_tab( $google_connected, $bing_connected );
						break;
					default:
						self::render_overview_tab( $google_connected, $bing_connected );
						break;
				}
				?>
			</div>

		</div>
		<?php
	}

	// ── Tab Navigation ───────────────────────────────────────────────────────

	private static function valid_tabs(): array {
		return array( 'overview', 'analytics', 'search-console', 'bing', 'crawlability', 'index-status', 'ai-crawler', 'settings' );
	}

	private static function validate_tab( string $tab ): string {
		$slug = sanitize_key( $tab );
		return in_array( $slug, self::valid_tabs(), true ) ? $slug : 'overview';
	}

	private static function render_tab_nav( $active, $google_connected, $bing_connected ) {
		$tabs = array(
			'overview'       => array( 'label' => __( 'Overview', 'twt-aeo-ultimate' ),          'dot' => false ),
			'analytics'      => array( 'label' => __( 'Google Analytics', 'twt-aeo-ultimate' ),  'dot' => $google_connected ),
			'search-console' => array( 'label' => __( 'Search Console', 'twt-aeo-ultimate' ),    'dot' => $google_connected ),
			'bing'           => array( 'label' => __( 'Bing Webmaster', 'twt-aeo-ultimate' ),    'dot' => $bing_connected ),
			'crawlability'   => array( 'label' => __( 'Crawlability Audit', 'twt-aeo-ultimate' ), 'dot' => false ),
			'index-status'   => array( 'label' => __( 'Index Status', 'twt-aeo-ultimate' ),      'dot' => false ),
			'ai-crawler'     => array( 'label' => __( 'AI Crawler Watch', 'twt-aeo-ultimate' ),  'dot' => false ),
			'settings'       => array( 'label' => __( 'Settings', 'twt-aeo-ultimate' ),          'dot' => false ),
		);
		?>
		<nav class="twt-aeo-cc__tabs" aria-label="<?php esc_attr_e( 'Command Center tabs', 'twt-aeo-ultimate' ); ?>">
			<?php foreach ( $tabs as $slug => $tab ) :
				$url     = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => $slug ), admin_url( 'admin.php' ) );
				$current = ( $active === $slug );
			?>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="twt-aeo-cc__tab<?php echo $current ? ' twt-aeo-cc__tab--active' : ''; ?>"
				   aria-current="<?php echo $current ? 'page' : 'false'; ?>">
					<?php echo esc_html( $tab['label'] ); ?>
					<?php if ( $tab['dot'] ) : ?>
						<span class="twt-aeo-cc__dot twt-aeo-cc__dot--connected" title="<?php esc_attr_e( 'Connected', 'twt-aeo-ultimate' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	// ── Overview Tab ─────────────────────────────────────────────────────────

	private static function render_overview_tab( $google_connected, $bing_connected ) {
		$config      = TWTAEO_Google_OAuth::get_config();
		$ga4_metrics = $google_connected && ! empty( $config['ga4_property_id'] ) ? self::fetch_ga4_totals( $config['ga4_property_id'] ) : null;
		$gsc_metrics = $google_connected && ! empty( $config['gsc_site_url'] ) ? self::fetch_gsc_totals( $config['gsc_site_url'] ) : null;
		$bing_stats  = $bing_connected ? self::fetch_bing_stats() : null;
		?>
		<div class="twt-aeo-cc__overview-grid">

			<?php /* ── GA4 Card ── */ ?>
			<div class="twt-aeo-cc__service-card <?php echo $google_connected ? 'twt-aeo-cc__service-card--connected' : 'twt-aeo-cc__service-card--disconnected'; ?>">
				<div class="twt-aeo-cc__service-header">
					<span class="twt-aeo-cc__service-icon twt-aeo-cc__service-icon--google">
						<?php echo self::icon_google(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div>
						<h3 class="twt-aeo-cc__service-name"><?php esc_html_e( 'Google Analytics', 'twt-aeo-ultimate' ); ?></h3>
						<span class="twt-aeo-cc__status-badge <?php echo $google_connected ? 'twt-aeo-cc__status-badge--connected' : 'twt-aeo-cc__status-badge--disconnected'; ?>">
							<?php echo $google_connected ? esc_html__( 'Connected', 'twt-aeo-ultimate' ) : esc_html__( 'Not connected', 'twt-aeo-ultimate' ); ?>
						</span>
					</div>
				</div>

				<?php if ( $google_connected && ! is_wp_error( $ga4_metrics ) && ! empty( $ga4_metrics ) ) : ?>
					<div class="twt-aeo-cc__metric-row">
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $ga4_metrics['sessions'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Sessions', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $ga4_metrics['totalUsers'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Users', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $ga4_metrics['screenPageViews'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Page Views', 'twt-aeo-ultimate' ); ?></span>
						</div>
					</div>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'analytics' ), admin_url( 'admin.php' ) ) ); ?>" class="twt-aeo-cc__card-link">
						<?php esc_html_e( 'View full report →', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php elseif ( $google_connected && empty( $config['ga4_property_id'] ) ) : ?>
					<p class="twt-aeo-cc__card-note"><?php esc_html_e( 'Connected. Enter your GA4 Property ID in Settings to load data.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-secondary button-small">
						<?php esc_html_e( 'Configure', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php elseif ( $google_connected && is_wp_error( $ga4_metrics ) ) : ?>
					<p class="twt-aeo-cc__card-error"><?php echo esc_html( $ga4_metrics->get_error_message() ); ?></p>
				<?php else : ?>
					<p class="twt-aeo-cc__card-note"><?php esc_html_e( 'Connect your Google account to see Analytics data here.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary button-small">
						<?php esc_html_e( 'Connect Google', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php /* ── GSC Card ── */ ?>
			<div class="twt-aeo-cc__service-card <?php echo $google_connected ? 'twt-aeo-cc__service-card--connected' : 'twt-aeo-cc__service-card--disconnected'; ?>">
				<div class="twt-aeo-cc__service-header">
					<span class="twt-aeo-cc__service-icon twt-aeo-cc__service-icon--gsc">
						<?php echo self::icon_search_console(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div>
						<h3 class="twt-aeo-cc__service-name"><?php esc_html_e( 'Search Console', 'twt-aeo-ultimate' ); ?></h3>
						<span class="twt-aeo-cc__status-badge <?php echo $google_connected ? 'twt-aeo-cc__status-badge--connected' : 'twt-aeo-cc__status-badge--disconnected'; ?>">
							<?php echo $google_connected ? esc_html__( 'Connected', 'twt-aeo-ultimate' ) : esc_html__( 'Not connected', 'twt-aeo-ultimate' ); ?>
						</span>
					</div>
				</div>

				<?php if ( $google_connected && ! is_wp_error( $gsc_metrics ) && ! empty( $gsc_metrics ) ) : ?>
					<div class="twt-aeo-cc__metric-row">
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $gsc_metrics['clicks'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $gsc_metrics['impressions'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( number_format( ( $gsc_metrics['ctr'] ?? 0 ) * 100, 1 ) . '%' ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Avg CTR', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( number_format( $gsc_metrics['position'] ?? 0, 1 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Avg Position', 'twt-aeo-ultimate' ); ?></span>
						</div>
					</div>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'search-console' ), admin_url( 'admin.php' ) ) ); ?>" class="twt-aeo-cc__card-link">
						<?php esc_html_e( 'View full report →', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php elseif ( $google_connected && empty( $config['gsc_site_url'] ) ) : ?>
					<p class="twt-aeo-cc__card-note"><?php esc_html_e( 'Connected. Enter your verified site URL in Settings to load data.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-secondary button-small">
						<?php esc_html_e( 'Configure', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php elseif ( $google_connected && is_wp_error( $gsc_metrics ) ) : ?>
					<p class="twt-aeo-cc__card-error"><?php echo esc_html( $gsc_metrics->get_error_message() ); ?></p>
				<?php else : ?>
					<p class="twt-aeo-cc__card-note"><?php esc_html_e( 'Connect your Google account to see Search Console data here.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary button-small">
						<?php esc_html_e( 'Connect Google', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php /* ── Bing Card ── */ ?>
			<div class="twt-aeo-cc__service-card <?php echo $bing_connected ? 'twt-aeo-cc__service-card--connected' : 'twt-aeo-cc__service-card--disconnected'; ?>">
				<div class="twt-aeo-cc__service-header">
					<span class="twt-aeo-cc__service-icon twt-aeo-cc__service-icon--bing">
						<?php echo self::icon_bing(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div>
						<h3 class="twt-aeo-cc__service-name"><?php esc_html_e( 'Bing Webmaster', 'twt-aeo-ultimate' ); ?></h3>
						<span class="twt-aeo-cc__status-badge <?php echo $bing_connected ? 'twt-aeo-cc__status-badge--connected' : 'twt-aeo-cc__status-badge--disconnected'; ?>">
							<?php echo $bing_connected ? esc_html__( 'Connected', 'twt-aeo-ultimate' ) : esc_html__( 'Not connected', 'twt-aeo-ultimate' ); ?>
						</span>
					</div>
				</div>

				<?php if ( $bing_connected && ! is_wp_error( $bing_stats ) && ! empty( $bing_stats ) ) : ?>
					<div class="twt-aeo-cc__metric-row">
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $bing_stats['total_clicks'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-cc__metric">
							<span class="twt-aeo-cc__metric-value"><?php echo esc_html( self::format_number( $bing_stats['total_impressions'] ?? 0 ) ); ?></span>
							<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></span>
						</div>
					</div>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'bing' ), admin_url( 'admin.php' ) ) ); ?>" class="twt-aeo-cc__card-link">
						<?php esc_html_e( 'View full report →', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php elseif ( $bing_connected && is_wp_error( $bing_stats ) ) : ?>
					<p class="twt-aeo-cc__card-error"><?php echo esc_html( $bing_stats->get_error_message() ); ?></p>
				<?php else : ?>
					<p class="twt-aeo-cc__card-note"><?php esc_html_e( 'Connect Bing Webmaster Tools to monitor your Bing search performance.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary button-small">
						<?php esc_html_e( 'Connect Bing', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php endif; ?>
			</div>

		</div>

		<?php if ( $google_connected || $bing_connected ) : ?>
			<p class="twt-aeo-cc__refresh-note">
				<?php esc_html_e( 'Data is cached for 1 hour.', 'twt-aeo-ultimate' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'overview', 'twtaeo_refresh' => '1' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Refresh now', 'twt-aeo-ultimate' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	// ── Google Analytics Tab ─────────────────────────────────────────────────

	private static function render_analytics_tab( $connected ) {
		if ( ! $connected ) {
			self::render_connect_prompt(
				__( 'Google Analytics', 'twt-aeo-ultimate' ),
				__( 'Connect your Google account in Settings to view GA4 Analytics data.', 'twt-aeo-ultimate' )
			);
			return;
		}

		$config      = TWTAEO_Google_OAuth::get_config();
		$property_id = $config['ga4_property_id'] ?? '';

		if ( ! $property_id ) {
			self::render_configure_prompt(
				__( 'Enter GA4 Property ID', 'twt-aeo-ultimate' ),
				__( 'Go to Settings and enter your GA4 numeric Property ID to load Analytics data.', 'twt-aeo-ultimate' )
			);
			return;
		}

		$totals    = self::fetch_ga4_totals( $property_id );
		$top_pages = self::fetch_ga4_top_pages( $property_id );

		self::render_tab_header(
			__( 'Google Analytics', 'twt-aeo-ultimate' ),
			__( 'Last 30 Days', 'twt-aeo-ultimate' ),
			'analytics'
		);

		if ( is_wp_error( $totals ) ) {
			self::render_api_error( $totals->get_error_message() );
			return;
		}
		?>
		<div class="twt-aeo-cc__metrics-bar">
			<?php
			self::render_metric_card( __( 'Sessions', 'twt-aeo-ultimate' ),   self::format_number( $totals['sessions'] ?? 0 ) );
			self::render_metric_card( __( 'Users', 'twt-aeo-ultimate' ),       self::format_number( $totals['totalUsers'] ?? 0 ) );
			self::render_metric_card( __( 'Page Views', 'twt-aeo-ultimate' ),  self::format_number( $totals['screenPageViews'] ?? 0 ) );
			self::render_metric_card( __( 'Bounce Rate', 'twt-aeo-ultimate' ), number_format( (float) ( $totals['bounceRate'] ?? 0 ) * 100, 1 ) . '%' );
			self::render_metric_card( __( 'Avg Session', 'twt-aeo-ultimate' ), self::format_duration( $totals['averageSessionDuration'] ?? 0 ) );
			?>
		</div>

		<?php if ( ! is_wp_error( $top_pages ) && ! empty( $top_pages ) ) : ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'Top Pages by Sessions', 'twt-aeo-ultimate' ); ?></h3>
			<table class="twt-aeo-cc__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Sessions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Page Views', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top_pages as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( home_url( $row['path'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $row['path'] ); ?>
							</a>
						</td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['sessions'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['views'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php endif; ?>
		<?php
	}

	// ── Search Console Tab ───────────────────────────────────────────────────

	private static function render_gsc_tab( $connected ) {
		if ( ! $connected ) {
			self::render_connect_prompt(
				__( 'Google Search Console', 'twt-aeo-ultimate' ),
				__( 'Connect your Google account in Settings to view Search Console data.', 'twt-aeo-ultimate' )
			);
			return;
		}

		$config   = TWTAEO_Google_OAuth::get_config();
		$site_url = $config['gsc_site_url'] ?? '';

		if ( ! $site_url ) {
			self::render_configure_prompt(
				__( 'Enter Site URL', 'twt-aeo-ultimate' ),
				__( 'Go to Settings and enter your verified site URL to load Search Console data.', 'twt-aeo-ultimate' )
			);
			return;
		}

		$totals      = self::fetch_gsc_totals( $site_url );
		$top_queries = self::fetch_gsc_top_queries( $site_url );

		self::render_tab_header(
			__( 'Search Console', 'twt-aeo-ultimate' ),
			__( 'Last 30 Days', 'twt-aeo-ultimate' ),
			'search-console'
		);

		if ( is_wp_error( $totals ) ) {
			self::render_api_error( $totals->get_error_message() );
			return;
		}
		?>
		<div class="twt-aeo-cc__metrics-bar">
			<?php
			self::render_metric_card( __( 'Total Clicks', 'twt-aeo-ultimate' ),      self::format_number( $totals['clicks'] ?? 0 ) );
			self::render_metric_card( __( 'Impressions', 'twt-aeo-ultimate' ),        self::format_number( $totals['impressions'] ?? 0 ) );
			self::render_metric_card( __( 'Avg CTR', 'twt-aeo-ultimate' ),            number_format( (float) ( $totals['ctr'] ?? 0 ) * 100, 2 ) . '%' );
			self::render_metric_card( __( 'Avg Position', 'twt-aeo-ultimate' ),       number_format( (float) ( $totals['position'] ?? 0 ), 1 ) );
			?>
		</div>

		<?php if ( ! is_wp_error( $top_queries ) && ! empty( $top_queries ) ) : ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'Top Queries', 'twt-aeo-ultimate' ); ?></h3>
			<table class="twt-aeo-cc__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'CTR', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Position', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top_queries as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['query'] ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['clicks'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['impressions'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( number_format( (float) $row['ctr'] * 100, 2 ) . '%' ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( number_format( (float) $row['position'], 1 ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php endif; ?>
		<?php
	}

	// ── Bing Webmaster Tab ───────────────────────────────────────────────────

	private static function render_bing_tab( $connected ) {
		if ( ! $connected ) {
			self::render_connect_prompt(
				__( 'Bing Webmaster Tools', 'twt-aeo-ultimate' ),
				__( 'Add your Bing Webmaster API key in Settings to see Bing search performance.', 'twt-aeo-ultimate' )
			);
		} else {
		$stats           = self::fetch_bing_stats();
		$recommendations = self::fetch_bing_recommendations();

		self::render_tab_header(
			__( 'Bing Webmaster Tools', 'twt-aeo-ultimate' ),
			__( 'Last 6 Months', 'twt-aeo-ultimate' ),
			'bing'
		);

		if ( is_wp_error( $stats ) ) :
			self::render_api_error( $stats->get_error_message() );
		elseif ( ! empty( $stats ) ) :
		?>
		<div class="twt-aeo-cc__metrics-bar">
			<?php
			self::render_metric_card( __( 'Total Clicks', 'twt-aeo-ultimate' ),       self::format_number( $stats['total_clicks'] ?? 0 ) );
			self::render_metric_card( __( 'Total Impressions', 'twt-aeo-ultimate' ),   self::format_number( $stats['total_impressions'] ?? 0 ) );
			self::render_metric_card( __( 'Avg Position', 'twt-aeo-ultimate' ),        isset( $stats['avg_position'] ) ? number_format( (float) $stats['avg_position'], 1 ) : '—' );
			?>
		</div>

		<?php if ( ! empty( $stats['rows'] ) ) : ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'Query Statistics', 'twt-aeo-ultimate' ); ?></h3>
			<table class="twt-aeo-cc__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Avg Click Position', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $stats['rows'], 0, 12 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['date'] ?? '—' ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['impressions'] ?? 0 ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['clicks'] ?? 0 ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( isset( $row['avg_click_position'] ) ? number_format( (float) $row['avg_click_position'], 1 ) : '—' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php endif; ?>

		<?php else : ?>
		<div class="twt-aeo-cc__empty">
			<p><?php esc_html_e( 'No query data available yet. Data may take 48 hours to appear after connecting.', 'twt-aeo-ultimate' ); ?></p>
		</div>
		<?php endif; ?>

		<?php // ── SEO Recommendations ── ?>
		<?php if ( ! is_wp_error( $recommendations ) && ! empty( $recommendations ) ) : ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title">
				<?php esc_html_e( 'SEO Recommendations', 'twt-aeo-ultimate' ); ?>
				<span class="twt-aeo-cc__rec-count"><?php echo count( $recommendations ); ?></span>
			</h3>
			<table class="twt-aeo-cc__table widefat striped twt-aeo-cc__rec-table">
				<thead>
					<tr>
						<th style="width:110px"><?php esc_html_e( 'Severity', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Issue', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:100px"><?php esc_html_e( 'Action', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recommendations as $rec ) :
						$post_id  = url_to_postid( $rec['url'] );
						$edit_url = $post_id ? get_edit_post_link( $post_id ) : '';
					?>
					<tr>
						<td><?php self::render_severity_badge( $rec['severity'] ); ?></td>
						<td>
							<strong><?php echo esc_html( $rec['title'] ); ?></strong>
							<?php if ( ! empty( $rec['suggestion'] ) ) : ?>
								<p class="twt-aeo-cc__rec-suggestion"><?php echo esc_html( $rec['suggestion'] ); ?></p>
							<?php endif; ?>
						</td>
						<td class="twt-aeo-cc__rec-url">
							<a href="<?php echo esc_url( $rec['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $rec['url'] ); ?>">
								<?php echo esc_html( self::shorten_url( $rec['url'] ) ); ?>
							</a>
						</td>
						<td>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit Page', 'twt-aeo-ultimate' ); ?>
								</a>
							<?php else : ?>
								<span class="twt-aeo-cc__rec-no-edit">—</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php elseif ( is_wp_error( $recommendations ) ) : ?>
			<div class="notice notice-warning inline" style="margin:16px 0 0;">
				<p><strong><?php esc_html_e( 'SEO Recommendations unavailable:', 'twt-aeo-ultimate' ); ?></strong>
				<?php echo esc_html( $recommendations->get_error_message() ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		} // end else (connected)

		// ── IndexNow section ─────────────────────────────────────────────────────
		$bing_tab_url = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'bing' ), admin_url( 'admin.php' ) );
		?>
		<div style="border-top:2px solid #e5e7eb;margin:32px 0 24px;padding-top:24px;">
			<h2 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px;">
				<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#f0f7ff;border-radius:6px;font-size:14px;">⚡</span>
				<?php esc_html_e( 'IndexNow', 'twt-aeo-ultimate' ); ?>
			</h2>
			<p style="margin:0 0 16px;font-size:13px;color:#6b7280;">
				<?php esc_html_e( 'Instantly notify Bing, Yandex, and other IndexNow-compatible engines when your content is published or updated — no waiting for the next crawl.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php TWTAEO_Page_Index_Now::render_section( $bing_tab_url ); ?>
		<?php
	}

	/**
	 * Render a colour-coded severity badge matching Bing's severity scale.
	 * 1 = Critical, 2 = Warning, 3 = Notice.
	 */
	private static function render_severity_badge( int $severity ) {
		$map = array(
			1 => array( 'label' => __( 'Critical', 'twt-aeo-ultimate' ), 'class' => 'twt-aeo-cc__sev--critical' ),
			2 => array( 'label' => __( 'Warning',  'twt-aeo-ultimate' ), 'class' => 'twt-aeo-cc__sev--warning' ),
			3 => array( 'label' => __( 'Notice',   'twt-aeo-ultimate' ), 'class' => 'twt-aeo-cc__sev--notice' ),
		);
		$entry = $map[ $severity ] ?? $map[3];
		echo '<span class="twt-aeo-cc__severity-badge ' . esc_attr( $entry['class'] ) . '">' . esc_html( $entry['label'] ) . '</span>';
	}

	// ── Settings Tab ─────────────────────────────────────────────────────────

	private static function render_settings_tab( $google_connected, $bing_connected ) {
		$google_creds  = TWTAEO_Google_OAuth::get_credentials();
		$google_config = TWTAEO_Google_OAuth::get_config();
		$bing_creds    = TWTAEO_Bing_Webmaster::get_credentials();
		$redirect_uri  = TWTAEO_Google_OAuth::get_redirect_uri();
		$oauth_url     = TWTAEO_Google_OAuth::get_oauth_url();

		// Notices from session.
		$notice = get_transient( 'twtaeo_cc_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'twtaeo_cc_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		?>

		<?php /* ── Google Section ── */ ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php echo self::icon_google(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Google Analytics &amp; Search Console', 'twt-aeo-ultimate' ); ?>
				<?php if ( $google_connected ) : ?>
					<span class="twt-aeo-cc__status-badge twt-aeo-cc__status-badge--connected"><?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></span>
				<?php endif; ?>
			</h2>

			<div class="twt-aeo-card">

				<?php /* Step 1 — API Credentials */ ?>
				<div class="twt-aeo-cc__setup-step">
					<h3 class="twt-aeo-cc__step-title">
						<span class="twt-aeo-cc__step-num">1</span>
						<?php esc_html_e( 'Google Cloud API Credentials', 'twt-aeo-ultimate' ); ?>
					</h3>
					<p class="twt-aeo-card__note">
						<?php
						printf(
							/* translators: %s: link to Google Cloud Console */
							esc_html__( 'Create OAuth 2.0 credentials in your %s. Choose "Web application" type and add the Redirect URI below to your authorized redirect URIs.', 'twt-aeo-ultimate' ),
							'<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>'
						);
						?>
					</p>

					<div class="twt-aeo-cc__redirect-uri">
						<label class="twt-aeo-cc__redirect-label"><?php esc_html_e( 'Authorized Redirect URI (copy this exactly):', 'twt-aeo-ultimate' ); ?></label>
						<div class="twt-aeo-cc__redirect-row">
							<code class="twt-aeo-cc__redirect-value" id="twt-aeo-redirect-uri"><?php echo esc_html( $redirect_uri ); ?></code>
							<button type="button" class="button button-secondary twt-aeo-cc__copy-btn" data-copy-target="twt-aeo-redirect-uri">
								<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
							</button>
						</div>
					</div>

					<form method="post" action="">
						<?php wp_nonce_field( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ); ?>
						<input type="hidden" name="twtaeo_cc_action" value="save_google_creds" />

						<table class="form-table twt-aeo-cc__form-table">
							<tr>
								<th scope="row"><label for="twt-aeo-client-id"><?php esc_html_e( 'Client ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" id="twt-aeo-client-id" name="client_id"
										   value="<?php echo esc_attr( $google_creds['client_id'] ?? '' ); ?>"
										   class="regular-text" autocomplete="off" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="twt-aeo-client-secret"><?php esc_html_e( 'Client Secret', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="password" id="twt-aeo-client-secret" name="client_secret"
										   value="<?php echo esc_attr( $google_creds['client_secret'] ?? '' ); ?>"
										   class="regular-text" autocomplete="off" />
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Google Credentials', 'twt-aeo-ultimate' ), 'secondary', 'submit_google_creds', false ); ?>
					</form>
				</div>

				<?php /* Step 2 — OAuth Connect */ ?>
				<div class="twt-aeo-cc__setup-step">
					<h3 class="twt-aeo-cc__step-title">
						<span class="twt-aeo-cc__step-num">2</span>
						<?php esc_html_e( 'Connect Google Account', 'twt-aeo-ultimate' ); ?>
					</h3>

					<?php if ( $google_connected ) : ?>
						<div class="twt-aeo-cc__connected-row">
							<span class="twt-aeo-cc__connected-label">✓ <?php esc_html_e( 'Google account connected.', 'twt-aeo-ultimate' ); ?></span>
							<button type="button" class="button button-link-delete twt-aeo-cc__disconnect-btn"
									data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_DISCONNECT ) ); ?>"
									data-service="google">
								<?php esc_html_e( 'Disconnect', 'twt-aeo-ultimate' ); ?>
							</button>
						</div>
					<?php elseif ( ! empty( $google_creds['client_id'] ) && $oauth_url ) : ?>
						<p class="twt-aeo-card__note"><?php esc_html_e( 'Click below to authorize access to Google Analytics and Search Console.', 'twt-aeo-ultimate' ); ?></p>
						<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary twt-aeo-cc__oauth-btn">
							<?php echo self::icon_google(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Connect Google Account', 'twt-aeo-ultimate' ); ?>
						</a>
					<?php else : ?>
						<p class="twt-aeo-card__note twt-aeo-card__note--muted"><?php esc_html_e( 'Save your Client ID and Client Secret above first.', 'twt-aeo-ultimate' ); ?></p>
					<?php endif; ?>
				</div>

				<?php /* Step 3 — Property Config */ ?>
				<div class="twt-aeo-cc__setup-step <?php echo ! $google_connected ? 'twt-aeo-cc__setup-step--disabled' : ''; ?>">
					<h3 class="twt-aeo-cc__step-title">
						<span class="twt-aeo-cc__step-num">3</span>
						<?php esc_html_e( 'Configure Properties', 'twt-aeo-ultimate' ); ?>
					</h3>

					<form method="post" action="">
						<?php wp_nonce_field( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ); ?>
						<input type="hidden" name="twtaeo_cc_action" value="save_google_config" />

						<table class="form-table twt-aeo-cc__form-table">
							<tr>
								<th scope="row"><label for="twt-aeo-property-id"><?php esc_html_e( 'GA4 Property ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" id="twt-aeo-property-id" name="ga4_property_id"
										   value="<?php echo esc_attr( $google_config['ga4_property_id'] ?? '' ); ?>"
										   class="regular-text" placeholder="e.g. 315498120"
										   <?php echo ! $google_connected ? 'disabled' : ''; ?> />
									<p class="description"><?php esc_html_e( 'Find in GA4: Admin → Property Settings → Property ID (numeric).', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="twt-aeo-gsc-url"><?php esc_html_e( 'Search Console Site URL', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="url" id="twt-aeo-gsc-url" name="gsc_site_url"
										   value="<?php echo esc_attr( $google_config['gsc_site_url'] ?? '' ); ?>"
										   class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
										   <?php echo ! $google_connected ? 'disabled' : ''; ?> />
									<p class="description"><?php esc_html_e( 'The exact URL of your verified property in Google Search Console.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Configuration', 'twt-aeo-ultimate' ), 'secondary', 'submit_google_config', false, $google_connected ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

			</div>
		</section>

		<?php /* ── Bing Section ── */ ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php echo self::icon_bing(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Bing Webmaster Tools', 'twt-aeo-ultimate' ); ?>
				<?php if ( $bing_connected ) : ?>
					<span class="twt-aeo-cc__status-badge twt-aeo-cc__status-badge--connected"><?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></span>
				<?php endif; ?>
			</h2>

			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note">
					<?php
					printf(
						/* translators: %s: link to Bing Webmaster portal */
						esc_html__( 'Get your API key from the %s. Go to Settings → API Access → Generate API Key.', 'twt-aeo-ultimate' ),
						'<a href="https://www.bing.com/webmasters/home" target="_blank" rel="noopener">Bing Webmaster Portal</a>'
					);
					?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE_SAVE_BING, '_twtaeo_cc_nonce' ); ?>
					<input type="hidden" name="twtaeo_cc_action" value="save_bing_creds" />

					<table class="form-table twt-aeo-cc__form-table">
						<tr>
							<th scope="row"><label for="twt-aeo-bing-key"><?php esc_html_e( 'API Key', 'twt-aeo-ultimate' ); ?></label></th>
							<td>
								<input type="password" id="twt-aeo-bing-key" name="bing_api_key"
									   value="<?php echo esc_attr( $bing_creds['api_key'] ?? '' ); ?>"
									   class="regular-text" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="twt-aeo-bing-site"><?php esc_html_e( 'Site URL', 'twt-aeo-ultimate' ); ?></label></th>
							<td>
								<input type="url" id="twt-aeo-bing-site" name="bing_site_url"
									   value="<?php echo esc_attr( $bing_creds['site_url'] ?? '' ); ?>"
									   class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'The exact URL of your verified site in Bing Webmaster Tools.', 'twt-aeo-ultimate' ); ?></p>
							</td>
						</tr>
					</table>

					<div class="twt-aeo-cc__bing-actions">
						<?php submit_button( __( 'Save Bing Settings', 'twt-aeo-ultimate' ), 'secondary', 'submit_bing', false ); ?>
						<?php if ( $bing_connected ) : ?>
							<button type="button" class="button button-link-delete twt-aeo-cc__disconnect-btn"
									data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_DISCONNECT ) ); ?>"
									data-service="bing">
								<?php esc_html_e( 'Disconnect', 'twt-aeo-ultimate' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</form>

				<?php if ( $bing_connected ) :
					$crawl_status = self::fetch_bing_crawl_status();
					if ( is_wp_error( $crawl_status ) ) : ?>
						<div class="notice notice-error inline" style="margin:16px 0 0;">
							<p>
								<strong><?php esc_html_e( 'Crawl Stats — Host Status Error:', 'twt-aeo-ultimate' ); ?></strong>
								<?php echo esc_html( $crawl_status->get_error_message() ); ?>
							</p>
						</div>
					<?php elseif (
						is_array( $crawl_status ) && (
							( ! empty( $crawl_status['host_status'] ) && strtolower( $crawl_status['host_status'] ) !== 'allowed' ) ||
							$crawl_status['crawl_errors'] > 0
						)
					) : ?>
						<div class="notice notice-warning inline" style="margin:16px 0 0;">
							<p>
								<strong><?php esc_html_e( 'Crawl Stats — Host Status Warning:', 'twt-aeo-ultimate' ); ?></strong>
								<?php if ( ! empty( $crawl_status['host_status'] ) && strtolower( $crawl_status['host_status'] ) !== 'allowed' ) : ?>
									<?php
									printf(
										/* translators: %s: host status value from Bing */
										esc_html__( 'Bing reports your host status as "%s".', 'twt-aeo-ultimate' ),
										esc_html( $crawl_status['host_status'] )
									);
									?>
								<?php endif; ?>
								<?php if ( $crawl_status['crawl_errors'] > 0 ) : ?>
									<?php
									printf(
										/* translators: %d: number of crawl errors */
										esc_html( _n( '%d crawl error detected.', '%d crawl errors detected.', $crawl_status['crawl_errors'], 'twt-aeo-ultimate' ) ),
										esc_html( number_format_i18n( $crawl_status['crawl_errors'] ) )
									);
									?>
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

			</div>
		</section>
		<?php
	}

	// ── Crawlability Audit Tab ───────────────────────────────────────────────

	private static function render_crawlability_tab() {
		$nonce = wp_create_nonce( TWTAEO_Crawler_Tester::NONCE );
		?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<span class="dashicons dashicons-visibility" style="font-size:18px;width:18px;height:18px;color:var(--aeo-primary,#2271b1);"></span>
				<?php esc_html_e( 'AI Crawler Visibility Audit', 'twt-aeo-ultimate' ); ?>
			</h2>
			<p style="margin:0 0 16px;color:var(--aeo-text-muted,#6b7280);font-size:13px;">
				<?php esc_html_e( 'Simulates a homepage request for each major AI crawler by spoofing its User-Agent header. A Firewall Block or Rate Limit here means that bot cannot index your site — even if robots.txt allows it.', 'twt-aeo-ultimate' ); ?>
			</p>

			<div style="margin-bottom:20px;display:flex;align-items:center;gap:14px;">
				<button type="button" id="twt-aeo-run-crawler-audit" class="button button-primary"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Run Crawlability Audit', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-crawler-spinner" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>
					<span style="font-size:13px;color:var(--aeo-text-muted,#6b7280);"><?php esc_html_e( 'Testing…', 'twt-aeo-ultimate' ); ?></span>
				</span>
				<span id="twt-aeo-crawler-tested-at" style="font-size:12px;color:var(--aeo-text-muted,#6b7280);"></span>
			</div>

			<div id="twt-aeo-crawler-results" style="display:none;">
				<table class="twt-aeo-cc__table widefat" id="twt-aeo-crawler-table">
					<thead>
						<tr>
							<th style="width:185px;"><?php esc_html_e( 'Bot', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:55px;"><?php esc_html_e( 'HTTP', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:170px;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Transport', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Recommendation / Error Detail', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody id="twt-aeo-crawler-tbody"></tbody>
				</table>

				<p style="margin-top:12px;font-size:12px;color:var(--aeo-text-muted,#6b7280);">
					<?php
					printf(
						/* translators: %s: home URL */
						esc_html__( 'Tests run against: %s', 'twt-aeo-ultimate' ),
						'<code>' . esc_html( get_home_url() ) . '</code>'
					);
					?>
					<?php esc_html_e( '— Results reflect server-side access control only, not robots.txt directives.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
		</section>

		<?php
		ob_start();
		?>
		(function($){
			var labelMap = {
				success: { bg: '#dcfce7', color: '#166534', icon: '&#10003;' },
				warning: { bg: '#fef9c3', color: '#854d0e', icon: '&#9888;' },
				error:   { bg: '#fee2e2', color: '#991b1b', icon: '&#10007;' },
			};

			$('#twt-aeo-run-crawler-audit').on('click', function(){
				var $btn     = $(this);
				var nonce    = $btn.data('nonce');
				var $spinner = $('#twt-aeo-crawler-spinner');
				var $results = $('#twt-aeo-crawler-results');
				var $tbody   = $('#twt-aeo-crawler-tbody');

				$btn.prop('disabled', true);
				$spinner.show();
				$results.hide();
				$tbody.empty();

				$.post(twtAeo.ajaxUrl, {
					action: 'twtaeo_run_crawler_tests',
					nonce:  nonce,
				}, function(response){
					$spinner.hide();
					$btn.prop('disabled', false);

					if (!response.success || !response.data) {
						$tbody.html('<tr><td colspan="5" style="color:#cc0000;"><?php echo esc_js( __( 'Audit failed. Check server logs.', 'twt-aeo-ultimate' ) ); ?></td></tr>');
						$results.show();
						return;
					}

					$.each(response.data, function(i, row){
						var lm = labelMap[row.label] || labelMap.warning;

						// Recommendation / error detail cell.
						var detail = '';
						if (row.error) {
							detail += '<span style="display:block;font-size:12px;font-weight:600;color:#991b1b;margin-bottom:4px;">'
								+ '&#9888; <?php echo esc_js( __( 'Error:', 'twt-aeo-ultimate' ) ); ?> ' + escHtml(row.error) + '</span>';
						}
						if (row.ua_blocked && row.neutral_code) {
							detail += '<span style="display:block;font-size:12px;font-weight:600;color:#9a3412;margin-bottom:4px;">'
								+ '&#128683; <?php echo esc_js( __( 'UA Shuffle: neutral Chrome returned', 'twt-aeo-ultimate' ) ); ?> ' + row.neutral_code + ' — <?php echo esc_js( __( 'blocking is User-Agent specific.', 'twt-aeo-ultimate' ) ); ?>'
								+ '</span>';
						}
						if (row.fix) {
							detail += '<span style="font-size:12px;color:#555;">' + escHtml(row.fix) + '</span>';
						} else if (!row.error) {
							detail += '<span style="font-size:12px;color:#166534;"><?php echo esc_js( __( 'No action needed.', 'twt-aeo-ultimate' ) ); ?></span>';
						}

						var transportBadge = row.transport
							? '<span style="font-size:11px;font-family:monospace;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;padding:1px 5px;color:#374151;">' + escHtml(row.transport) + '</span>'
							: '—';

						var tr = '<tr>' +
							'<td><strong>' + escHtml(row.name) + '</strong></td>' +
							'<td style="font-family:monospace;font-size:13px;">' + (row.code || '—') + '</td>' +
							'<td><span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:' + lm.bg + ';color:' + lm.color + ';">' +
								lm.icon + ' ' + escHtml(row.status) +
							'</span></td>' +
							'<td>' + transportBadge + '</td>' +
							'<td>' + detail + '</td>' +
							'</tr>';
						$tbody.append(tr);
					});

					$('#twt-aeo-crawler-tested-at').text('<?php echo esc_js( __( 'Last tested:', 'twt-aeo-ultimate' ) ); ?> ' + new Date().toLocaleTimeString());
					$results.show();
				}).fail(function(){
					$spinner.hide();
					$btn.prop('disabled', false);
					$tbody.html('<tr><td colspan="5" style="color:#cc0000;"><?php echo esc_js( __( 'Request failed. Check your connection.', 'twt-aeo-ultimate' ) ); ?></td></tr>');
					$results.show();
				});
			});

			function escHtml(str){
				return String(str)
					.replace(/&/g,'&amp;')
					.replace(/</g,'&lt;')
					.replace(/>/g,'&gt;')
					.replace(/"/g,'&quot;');
			}
		}(jQuery));
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-command-center', $js );
	}

	// ── Data Fetching (with Transient Cache) ─────────────────────────────────

	private static function fetch_ga4_totals( $property_id ) {
		$cache_key = 'twtaeo_ga4_totals';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => array( array( 'startDate' => '30daysAgo', 'endDate' => 'yesterday' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$totals = array();
		$rows   = $result['rows'] ?? array();

		if ( ! empty( $rows[0]['metricValues'] ) && ! empty( $result['metricHeaders'] ) ) {
			foreach ( $result['metricHeaders'] as $i => $header ) {
				$totals[ $header['name'] ] = $rows[0]['metricValues'][ $i ]['value'] ?? '0';
			}
		}

		set_transient( $cache_key, $totals, HOUR_IN_SECONDS );
		return $totals;
	}

	private static function fetch_ga4_top_pages( $property_id ) {
		$cache_key = 'twtaeo_ga4_top_pages';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => array( array( 'startDate' => '30daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'screenPageViews' ),
			),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 10,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pages = array();
		foreach ( $result['rows'] ?? array() as $row ) {
			$pages[] = array(
				'path'     => $row['dimensionValues'][0]['value'] ?? '/',
				'sessions' => $row['metricValues'][0]['value']    ?? 0,
				'views'    => $row['metricValues'][1]['value']    ?? 0,
			);
		}

		set_transient( $cache_key, $pages, HOUR_IN_SECONDS );
		return $pages;
	}

	private static function fetch_gsc_totals( $site_url ) {
		$cache_key = 'twtaeo_gsc_totals';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::gsc_search_analytics( $site_url, array(
			'startDate' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'endDate'   => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row    = $result['rows'][0] ?? array();
		$totals = array(
			'clicks'      => $row['clicks']      ?? 0,
			'impressions' => $row['impressions']  ?? 0,
			'ctr'         => $row['ctr']          ?? 0,
			'position'    => $row['position']     ?? 0,
		);

		set_transient( $cache_key, $totals, HOUR_IN_SECONDS );
		return $totals;
	}

	private static function fetch_gsc_top_queries( $site_url ) {
		$cache_key = 'twtaeo_gsc_top_queries';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::gsc_search_analytics( $site_url, array(
			'startDate'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			'dimensions' => array( 'query' ),
			'rowLimit'   => 15,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$queries = array();
		foreach ( $result['rows'] ?? array() as $row ) {
			$queries[] = array(
				'query'       => $row['keys'][0]    ?? '',
				'clicks'      => $row['clicks']     ?? 0,
				'impressions' => $row['impressions'] ?? 0,
				'ctr'         => $row['ctr']        ?? 0,
				'position'    => $row['position']   ?? 0,
			);
		}

		set_transient( $cache_key, $queries, HOUR_IN_SECONDS );
		return $queries;
	}

	private static function fetch_bing_stats() {
		$cache_key = 'twtaeo_bing_stats';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Bing_Webmaster::get_query_stats();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw_rows = $result['d']['results'] ?? $result['d'] ?? array();

		if ( empty( $raw_rows ) || ! is_array( $raw_rows ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$rows               = array();
		$total_clicks       = 0;
		$total_impressions  = 0;
		$position_sum       = 0;
		$position_count     = 0;

		foreach ( $raw_rows as $raw ) {
			$clicks      = (int) ( $raw['Clicks'] ?? 0 );
			$impressions = (int) ( $raw['Impressions'] ?? 0 );
			$click_pos   = isset( $raw['AvgClickPosition'] ) ? (float) $raw['AvgClickPosition'] : null;

			// Convert Bing /Date(timestamp)/ to a readable date.
			$date = '—';
			if ( isset( $raw['Date'] ) && preg_match( '/\d+/', $raw['Date'], $m ) ) {
				$date = gmdate( 'Y-m', (int) ( $m[0] / 1000 ) );
			}

			$rows[] = array(
				'date'               => $date,
				'clicks'             => $clicks,
				'impressions'        => $impressions,
				'avg_click_position' => $click_pos,
			);

			$total_clicks      += $clicks;
			$total_impressions += $impressions;

			if ( $click_pos !== null ) {
				$position_sum   += $click_pos;
				$position_count ++;
			}
		}

		$stats = array(
			'total_clicks'      => $total_clicks,
			'total_impressions' => $total_impressions,
			'avg_position'      => $position_count > 0 ? $position_sum / $position_count : null,
			'rows'              => $rows,
		);

		set_transient( $cache_key, $stats, HOUR_IN_SECONDS );
		return $stats;
	}

	/**
	 * Fetch and normalise Bing SEO recommendations, sorted by severity.
	 *
	 * Each item: [ 'url', 'title', 'suggestion', 'severity' (1-3) ]
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_bing_recommendations() {
		$cache_key = 'twtaeo_bing_suggestions';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Bing_Webmaster::get_seo_suggestions();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Bing may return the list directly under 'd' or nested under 'd.results'.
		$pages = $result['d']['results'] ?? $result['d'] ?? array();

		if ( empty( $pages ) || ! is_array( $pages ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$items = array();

		foreach ( $pages as $page ) {
			$url = $page['PageUrl'] ?? '';
			if ( empty( $url ) ) {
				continue;
			}

			// Bing uses 'PageSuggestions' or 'SuggestionsList' depending on API version.
			$suggestions = $page['PageSuggestions'] ?? $page['SuggestionsList'] ?? array();

			foreach ( $suggestions as $s ) {
				$text     = $s['Text'] ?? $s['Description'] ?? '';
				$severity = (int) ( $s['Severity'] ?? $s['Type'] ?? 3 );

				// Bing severity: 1=Critical, 2=Warning, 3=Notice. Clamp to valid range.
				$severity = max( 1, min( 3, $severity ) );

				$items[] = array(
					'url'        => $url,
					'title'      => self::bing_suggestion_title( $text ),
					'suggestion' => self::bing_suggestion_fix( $text ),
					'severity'   => $severity,
				);
			}
		}

		// Sort: Critical first, then Warning, then Notice.
		usort( $items, function( $a, $b ) {
			return $a['severity'] <=> $b['severity'];
		} );

		set_transient( $cache_key, $items, HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * Fetch Bing crawl stats and return a normalised host-status summary.
	 *
	 * Returns:
	 *   WP_Error – API / network failure
	 *   array    – [ 'crawl_errors' => int, 'host_status' => string, 'blocked' => int ]
	 */
	private static function fetch_bing_crawl_status() {
		$cache_key = 'twtaeo_bing_crawl_status';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Bing_Webmaster::get_crawl_stats();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Bing wraps all responses in 'd'; stats may live directly there or under 'CrawlStats'.
		$data  = $result['d'] ?? array();
		$stats = $data['CrawlStats'] ?? $data;

		$status = array(
			'crawl_errors' => (int) ( $stats['CrawlErrors'] ?? 0 ),
			'host_status'  => isset( $stats['HostStatus'] ) ? (string) $stats['HostStatus'] : '',
			'blocked'      => (int) ( $stats['BlockedByRobotsTxt'] ?? 0 ),
		);

		set_transient( $cache_key, $status, HOUR_IN_SECONDS );
		return $status;
	}

	/**
	 * Extract a short human-readable title from Bing's suggestion text.
	 * Returns the first sentence or up to 80 characters.
	 */
	private static function bing_suggestion_title( string $text ): string {
		$text = trim( $text );
		$pos  = strpos( $text, '. ' );
		if ( $pos !== false && $pos <= 80 ) {
			return substr( $text, 0, $pos + 1 );
		}
		return strlen( $text ) > 80 ? substr( $text, 0, 77 ) . '…' : $text;
	}

	/**
	 * Extract the fix suggestion from Bing's suggestion text (everything after the first sentence).
	 */
	private static function bing_suggestion_fix( string $text ): string {
		$text = trim( $text );
		$pos  = strpos( $text, '. ' );
		if ( $pos !== false && $pos <= 80 ) {
			return trim( substr( $text, $pos + 2 ) );
		}
		return '';
	}

	/**
	 * Shorten a URL for display: strip scheme and limit to 60 chars.
	 */
	private static function shorten_url( string $url ): string {
		$short = preg_replace( '#^https?://#', '', $url );
		return strlen( $short ) > 60 ? substr( $short, 0, 57 ) . '…' : $short;
	}

	// ── Index Status Tab ────────────────────────────────────────────────────

	private static function render_index_status_tab() {
		$connected = TWTAEO_Google_OAuth::is_connected();
		$config    = TWTAEO_Google_OAuth::get_config();
		$site_url  = $config['gsc_site_url'] ?? '';
		$nonce     = wp_create_nonce( TWTAEO_Index_Status::NONCE );
		$results   = TWTAEO_Index_Status::get_results();
		$urls      = TWTAEO_Index_Status::get_scannable_urls( 50 );
		$pro_connected = TWTAEO_Pro_Transmitter::is_connected();

		if ( ! $connected ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 20px;">
				<p><?php esc_html_e( 'Index Status requires a Google Search Console connection. Connect Google in the Settings tab first.', 'twt-aeo-ultimate' ); ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small" style="margin-left:8px;"><?php esc_html_e( 'Go to Settings', 'twt-aeo-ultimate' ); ?></a>
				</p>
			</div>
		<?php return; endif;

		if ( ! $site_url ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 20px;">
				<p><?php esc_html_e( 'Enter your GSC Site URL in Settings → Google Configuration to enable Index Status scans.', 'twt-aeo-ultimate' ); ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small" style="margin-left:8px;"><?php esc_html_e( 'Configure', 'twt-aeo-ultimate' ); ?></a>
				</p>
			</div>
		<?php return; endif;

		// Count results summary.
		$total_scanned  = count( $results );
		$not_indexed    = 0;
		$indexed        = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['gsc']['not_indexed'] ) ) { $not_indexed++; } else { $indexed++; }
		}
		?>

		<?php /* ── Summary Bar ── */ ?>
		<div class="twt-aeo-cc__metric-row" style="margin-bottom:24px;gap:16px;flex-wrap:wrap;">
			<div class="twt-aeo-cc__metric" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;"><?php echo esc_html( count( $urls ) ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Pages to Scan', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#edfaef;border:1px solid #5ec980;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#1a6629;"><?php echo esc_html( $indexed ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Indexed', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#b91c1c;"><?php echo esc_html( $not_indexed ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Not Indexed', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div style="display:flex;flex-direction:column;justify-content:center;gap:8px;margin-left:auto;">
				<button id="twt-aeo-index-scan-btn" class="button button-primary">
					<?php echo $total_scanned ? esc_html__( 'Re-scan All', 'twt-aeo-ultimate' ) : esc_html__( 'Run Index Scan', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php if ( $not_indexed && $pro_connected ) : ?>
				<button id="twt-aeo-index-send-btn" class="button">
					<?php
					// translators: %d: number of de-indexed pages to send to TWT Agency.
					printf( esc_html__( 'Send %d De-indexed to TWT Agency', 'twt-aeo-ultimate' ), absint( $not_indexed ) ); ?>
				</button>
				<?php endif; ?>
				<?php if ( $total_scanned ) : ?>
				<button id="twt-aeo-index-clear-btn" class="button" style="color:#b32d2e;border-color:#b32d2e;">
					<?php esc_html_e( 'Clear Results', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<?php /* ── Progress Bar ── */ ?>
		<div id="twt-aeo-index-progress" style="display:none;margin-bottom:20px;">
			<div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
				<div id="twt-aeo-index-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s;"></div>
			</div>
			<p id="twt-aeo-index-progress-label" style="font-size:12px;color:#646970;margin:6px 0 0;"></p>
		</div>

		<?php /* ── Results Table ── */ ?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden;">
			<table class="widefat" style="border:none;">
				<thead>
					<tr style="background:#f9fafb;">
						<th style="width:36%;"><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:20%;"><?php esc_html_e( 'Index Status', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:14%;"><?php esc_html_e( 'Last Crawl', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Issues Found', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody id="twt-aeo-index-tbody">
				<?php foreach ( $urls as $item ) :
					$r      = $results[ $item['post_id'] ] ?? null;
					$gsc    = $r['gsc']        ?? null;
					$heur   = $r['heuristics'] ?? null;
					$flags  = $heur['flags']   ?? array();
					$is_bad = $gsc && ! empty( $gsc['not_indexed'] );
				?>
					<tr id="twt-aeo-row-<?php echo esc_attr( $item['post_id'] ); ?>"
					    style="border-bottom:1px solid #f0f0f1;<?php echo $is_bad ? 'background:#fff8f8;' : ''; ?>">
						<td style="padding:10px 12px;">
							<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"
							   style="font-weight:600;color:#2271b1;text-decoration:none;font-size:13px;">
								<?php echo esc_html( $item['title'] ); ?>
							</a>
							<span style="display:block;font-size:11px;color:#999;margin-top:2px;"><?php echo esc_html( wp_parse_url( $item['url'], PHP_URL_PATH ) ?: '/' ); ?></span>
							<?php if ( $is_bad && ! empty( $flags ) ) : ?>
							<div style="margin-top:8px;padding:8px;background:#fff3cd;border-radius:4px;font-size:12px;border:1px solid #fde68a;">
								<?php foreach ( $flags as $flag ) : ?>
									<div style="margin-bottom:4px;">
										<strong><?php echo esc_html( $flag['label'] ); ?>:</strong>
										<?php echo esc_html( $flag['detail'] ); ?>
									</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</td>
						<td style="padding:10px 12px;">
							<?php if ( ! $gsc ) : ?>
								<span style="color:#999;font-size:12px;"><?php esc_html_e( 'Not scanned', 'twt-aeo-ultimate' ); ?></span>
							<?php elseif ( $gsc['not_indexed'] ) : ?>
								<span style="background:#fee2e2;color:#b91c1c;border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;">
									&#10008; <?php echo esc_html( $gsc['coverage_state'] ?: __( 'Not Indexed', 'twt-aeo-ultimate' ) ); ?>
								</span>
							<?php else : ?>
								<span style="background:#d1fae5;color:#065f46;border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;">
									&#10003; <?php esc_html_e( 'Indexed', 'twt-aeo-ultimate' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td style="padding:10px 12px;font-size:12px;color:#646970;">
							<?php
							if ( $gsc && $gsc['last_crawl'] ) {
								echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $gsc['last_crawl'] ) ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td style="padding:10px 12px;font-size:12px;">
							<?php if ( ! $gsc ) : ?>
								<span style="color:#999;">—</span>
							<?php elseif ( empty( $flags ) ) : ?>
								<span style="color:#1a6629;">&#10003; <?php esc_html_e( 'No issues', 'twt-aeo-ultimate' ); ?></span>
							<?php else : ?>
								<?php foreach ( $flags as $flag ) : ?>
									<span style="display:inline-block;background:#fef3c7;color:#92400e;border-radius:10px;padding:2px 8px;font-size:11px;margin:2px 2px 2px 0;">
										<?php echo esc_html( $flag['label'] ); ?>
									</span>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php /* ── Quota Note ── */ ?>
		<p style="font-size:12px;color:#999;margin-top:12px;">
			<?php esc_html_e( 'Uses the Google Search Console URL Inspection API (2,000 requests/day quota). Scanning 50 URLs consumes 50 quota units. Results are cached until you re-scan.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php /* ── Inline JS ── */ ?>
		<?php
		ob_start();
		?>
		(function(){
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var siteUrl  = <?php echo wp_json_encode( $site_url ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var urls     = <?php echo wp_json_encode( array_values( $urls ) ); ?>;

			// ── Scan ──────────────────────────────────────────────────────
			document.getElementById('twt-aeo-index-scan-btn').addEventListener('click', function() {
				this.disabled = true;
				this.textContent = <?php echo wp_json_encode( __( 'Scanning…', 'twt-aeo-ultimate' ) ); ?>;
				var progress = document.getElementById('twt-aeo-index-progress');
				var bar      = document.getElementById('twt-aeo-index-progress-bar');
				var label    = document.getElementById('twt-aeo-index-progress-label');
				progress.style.display = 'block';

				var i = 0;
				function next() {
					if ( i >= urls.length ) {
						label.textContent = <?php echo wp_json_encode( __( 'Scan complete. Reload to see updated totals.', 'twt-aeo-ultimate' ) ); ?>;
						setTimeout(function(){ location.reload(); }, 1200 );
						return;
					}
					var item = urls[i];
					var pct  = Math.round( (i / urls.length) * 100 );
					bar.style.width   = pct + '%';
					label.textContent = (i+1) + ' / ' + urls.length + ' — ' + item.title;

					var fd = new FormData();
					fd.append('action',   'twtaeo_index_scan_url');
					fd.append('nonce',    nonce);
					fd.append('post_id',  item.post_id);
					fd.append('url',      item.url);
					fd.append('site_url', siteUrl);

					fetch(ajaxUrl, { method:'POST', body:fd })
						.then(function(r){ return r.json(); })
						.then(function(res){
							if (res.success) updateRow(res.data);
						})
						.catch(function(){})
						.finally(function(){
							i++;
							setTimeout(next, 300); // 300ms between requests to respect API rate limits
						});
				}
				next();
			});

			function updateRow(data) {
				var row = document.getElementById('twt-aeo-row-' + data.post_id);
				if (!row) return;
				var gsc  = data.gsc  || {};
				var heur = data.heuristics || {};
				var flags = (heur.flags || []);

				// Status cell (index 1)
				var statusCell = row.cells[1];
				if (gsc.not_indexed) {
					row.style.background = '#fff8f8';
					statusCell.innerHTML = '<span style="background:#fee2e2;color:#b91c1c;border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;">&#10008; ' + (gsc.coverage_state || 'Not Indexed') + '</span>';
				} else {
					row.style.background = '';
					statusCell.innerHTML = '<span style="background:#d1fae5;color:#065f46;border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;">&#10003; Indexed</span>';
				}

				// Last crawl (index 2)
				row.cells[2].textContent = gsc.last_crawl ? gsc.last_crawl.substring(0,10) : '—';

				// Issues (index 3)
				var issueCell = row.cells[3];
				if (!flags.length) {
					issueCell.innerHTML = '<span style="color:#1a6629;">&#10003; No issues</span>';
				} else {
					issueCell.innerHTML = flags.map(function(f){
						return '<span style="display:inline-block;background:#fef3c7;color:#92400e;border-radius:10px;padding:2px 8px;font-size:11px;margin:2px 2px 2px 0;">' + f.label + '</span>';
					}).join('');
				}

				// Show heuristic detail block in title cell if not indexed
				if (gsc.not_indexed && flags.length) {
					var titleCell = row.cells[0];
					var existing  = titleCell.querySelector('.twt-flag-detail');
					if (existing) existing.remove();
					var detail = document.createElement('div');
					detail.className = 'twt-flag-detail';
					detail.style.cssText = 'margin-top:8px;padding:8px;background:#fff3cd;border-radius:4px;font-size:12px;border:1px solid #fde68a;';
					detail.innerHTML = flags.map(function(f){
						return '<div style="margin-bottom:4px;"><strong>' + f.label + ':</strong> ' + f.detail + '</div>';
					}).join('');
					titleCell.appendChild(detail);
				}
			}

			// ── Clear ─────────────────────────────────────────────────────
			var clearBtn = document.getElementById('twt-aeo-index-clear-btn');
			if (clearBtn) clearBtn.addEventListener('click', function(){
				if (!confirm(<?php echo wp_json_encode( __( 'Clear all scan results?', 'twt-aeo-ultimate' ) ); ?>)) return;
				var fd = new FormData();
				fd.append('action', 'twtaeo_index_clear');
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method:'POST', body:fd }).then(function(){ location.reload(); });
			});

			// ── Send to Pro ───────────────────────────────────────────────
			var sendBtn = document.getElementById('twt-aeo-index-send-btn');
			if (sendBtn) sendBtn.addEventListener('click', function(){
				this.disabled = true;
				this.textContent = <?php echo wp_json_encode( __( 'Sending…', 'twt-aeo-ultimate' ) ); ?>;
				var fd = new FormData();
				fd.append('action', 'twtaeo_index_send_to_pro');
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method:'POST', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (res.success) {
							sendBtn.textContent = res.data.count + <?php echo wp_json_encode( __( ' URLs sent ✓', 'twt-aeo-ultimate' ) ); ?>;
						} else {
							sendBtn.textContent = res.data || 'Error';
							sendBtn.disabled = false;
						}
					});
			});
		})();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-command-center', $js );
	}

	// ── AI Crawler Watch Tab ────────────────────────────────────────────────

	private static function render_ai_crawler_tab() {
		$log   = TWTAEO_AI_Crawler_Logger::get_log( 50 );
		$stats = TWTAEO_AI_Crawler_Logger::get_stats();
		$nonce = wp_create_nonce( self::NONCE_CLEAR_CRAWLERLOG );

		// Bot company logo colour map (inline dots).
		$company_colors = array(
			'OpenAI'       => '#10a37f',
			'Anthropic'    => '#c96b3e',
			'Google'       => '#4285f4',
			'Perplexity'   => '#6366f1',
			'Meta'         => '#1877f2',
			'Apple'        => '#555555',
			'ByteDance'    => '#fe2c55',
			'Common Crawl' => '#f59e0b',
			'Amazon'       => '#ff9900',
			'Diffbot'      => '#7c3aed',
			'Cohere'       => '#39d353',
			'You.com'      => '#1e40af',
		);

		// Prepare log entries as structured data for the DataViews component.
		$feed_entries = array();
		foreach ( $log as $i => $entry ) {
			$url  = $entry['url'] ?? '';
			$path = $url ? ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' ) : '';
			$feed_entries[] = array(
				'id'       => $i,
				'time'     => $entry['time'] ?? 0,
				'age'      => human_time_diff( $entry['time'] ?? time(), time() ),
				'datetime' => date_i18n(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$entry['time'] ?? 0
				),
				'bot'      => $entry['bot'] ?? '',
				'company'  => $entry['company'] ?? '',
				'url'      => $url,
				'title'    => $entry['title'] ?? '',
				'path'     => $path,
			);
		}

		// Inline the feed data so the DataViews component can access it synchronously.
		// Scripts are footer-printed, so this runs before the component script.
		ob_start();
		?>
		window.twtAeoCrawlerFeed = <?php echo wp_json_encode( array(
			'entries'       => $feed_entries,
			'companyColors' => $company_colors,
			'isPro'         => TWTAEO_Pro_Transmitter::is_connected(),
		) ); ?>;
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-command-center', $js );
		?>
		<?php /* ── Summary Stats ── */ ?>
		<div class="twt-aeo-cc__metric-row" style="margin-bottom:28px;gap:16px;flex-wrap:wrap;">
			<div class="twt-aeo-cc__metric" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:140px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:32px;"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Total Crawls (30 days)', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:140px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:32px;"><?php echo esc_html( count( $stats['by_bot'] ) ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Unique Bots', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:180px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:18px;line-height:1.4;">
					<?php echo $stats['last_visit'] ? esc_html( human_time_diff( $stats['last_visit'], time() ) . ' ago' ) : '—'; ?>
				</span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Last AI Crawl', 'twt-aeo-ultimate' ); ?></span>
			</div>
		</div>

		<?php /* ── Discovery File Hits ── */ ?>
		<?php
		$file_hits = TWTAEO_AI_Crawler_Logger::get_file_hits();

		$file_meta = array(
			'robots.txt'    => array(
				'icon'  => '🤖',
				'label' => 'robots.txt',
				'desc'  => 'Crawl permissions — advisory rules bots are expected to follow.',
				'note'  => '',
			),
			'llms.txt'      => array(
				'icon'  => '🗂️',
				'label' => 'llms.txt',
				'desc'  => 'AI agent index — who you are and how to access your content.',
				'note'  => 'AI Ready → llms.txt',
			),
			'llms-full.txt' => array(
				'icon'  => '📄',
				'label' => 'llms-full.txt',
				'desc'  => 'Full Markdown handshake — your entire site as clean text for AI ingestion.',
				'note'  => 'Sitemap module',
			),
		);
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:28px;">
			<div style="display:flex;align-items:baseline;gap:10px;margin-bottom:4px;">
				<h3 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;">
					<?php esc_html_e( 'AI Discovery File Hits', 'twt-aeo-ultimate' ); ?>
				</h3>
				<span style="font-size:11px;color:#999;"><?php esc_html_e( '30-day window', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<p style="margin:0 0 18px;font-size:12px;color:#888;"><?php esc_html_e( 'How many AI crawlers fetched your machine-readable discovery files. A rising count means bots are actively using your structured signals.', 'twt-aeo-ultimate' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
				<?php foreach ( $file_hits as $slug => $data ) :
					$meta       = $file_meta[ $slug ];
					$has_hits   = $data['hits'] > 0;
					$dot_color  = $has_hits ? '#10b981' : '#d1d5db';
					$count_color = $has_hits ? '#1d2327' : '#9ca3af';
				?>
				<div style="border:1px solid #e5e7eb;border-radius:6px;padding:16px 18px;position:relative;">

					<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
						<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $dot_color ); ?>;flex-shrink:0;"></span>
						<code style="font-size:12px;font-weight:600;color:#1d2327;"><?php echo esc_html( $meta['label'] ); ?></code>
					</div>

					<div style="font-size:30px;font-weight:700;line-height:1;color:<?php echo esc_attr( $count_color ); ?>;margin-bottom:2px;">
						<?php echo esc_html( number_format_i18n( $data['hits'] ) ); ?>
					</div>
					<div style="font-size:11px;color:#9ca3af;margin-bottom:10px;">
						<?php esc_html_e( 'bot crawls', 'twt-aeo-ultimate' ); ?>
					</div>

					<div style="font-size:11px;color:#6b7280;margin-bottom:8px;line-height:1.4;">
						<?php echo esc_html( $meta['desc'] ); ?>
					</div>

					<div style="font-size:11px;border-top:1px solid #f3f4f6;padding-top:8px;display:flex;justify-content:space-between;align-items:center;gap:4px;flex-wrap:wrap;">
						<span style="color:#9ca3af;">
							<?php if ( $data['last'] ) : ?>
								<?php printf(
									/* translators: %s: human-readable time ago */
									esc_html__( 'Last: %s ago', 'twt-aeo-ultimate' ),
									esc_html( human_time_diff( $data['last'], time() ) )
								); ?>
							<?php else : ?>
								<em><?php esc_html_e( 'Not yet recorded', 'twt-aeo-ultimate' ); ?></em>
							<?php endif; ?>
						</span>
						<?php if ( ! empty( $meta['note'] ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-ai-ready' ) ); ?>"
							   style="font-size:10px;color:#9ca3af;text-decoration:none;white-space:nowrap;"
							   title="<?php echo esc_attr( $meta['note'] ); ?>">
								<?php echo esc_html( $meta['note'] ); ?> &rarr;
							</a>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<p style="margin:12px 0 0;font-size:11px;color:#aaa;">
				<?php esc_html_e( 'Note: hits are only recorded when WordPress handles the request in PHP. If a physical robots.txt file exists on disk, the web server serves it directly and those hits are not captured here.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;">

			<?php /* ── Bot Breakdown ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
					<h3 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;">
						<?php esc_html_e( 'Bot Breakdown', 'twt-aeo-ultimate' ); ?>
					</h3>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-ai-ready#twt-aeo-rate-limiting' ) ); ?>"
					   style="font-size:12px;color:#2271b1;text-decoration:none;white-space:nowrap;"
					   title="<?php esc_attr_e( 'Block or rate-limit specific crawlers', 'twt-aeo-ultimate' ); ?>">
						<?php esc_html_e( 'Manage bot controls', 'twt-aeo-ultimate' ); ?> &rarr;
					</a>
				</div>
				<?php if ( empty( $stats['by_bot'] ) ) : ?>
					<p style="color:#646970;margin:0;"><?php esc_html_e( 'No AI crawls recorded yet.', 'twt-aeo-ultimate' ); ?></p>
				<?php else : ?>
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr style="border-bottom:2px solid #eee;">
							<th style="text-align:left;padding:6px 8px 6px 0;font-size:12px;color:#646970;font-weight:600;"><?php esc_html_e( 'Bot', 'twt-aeo-ultimate' ); ?></th>
							<th style="text-align:left;padding:6px 8px;font-size:12px;color:#646970;font-weight:600;"><?php esc_html_e( 'Company', 'twt-aeo-ultimate' ); ?></th>
							<th style="text-align:right;padding:6px 0 6px 8px;font-size:12px;color:#646970;font-weight:600;"><?php esc_html_e( 'Visits', 'twt-aeo-ultimate' ); ?></th>
							<th style="text-align:right;padding:6px 0;font-size:12px;color:#646970;font-weight:600;"><?php esc_html_e( 'Last Seen', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $stats['by_bot'] as $bot => $info ) :
						$color = $company_colors[ $info['company'] ] ?? '#888';
					?>
						<tr style="border-bottom:1px solid #f0f0f0;">
							<td style="padding:7px 8px 7px 0;font-size:13px;font-family:monospace;color:#1d2327;">
								<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;margin-right:6px;vertical-align:middle;"></span>
								<?php echo esc_html( $bot ); ?>
							</td>
							<td style="padding:7px 8px;font-size:13px;color:#646970;"><?php echo esc_html( $info['company'] ); ?></td>
							<td style="padding:7px 0 7px 8px;text-align:right;font-size:13px;font-weight:600;"><?php echo esc_html( number_format_i18n( $info['count'] ) ); ?></td>
							<td style="padding:7px 0;text-align:right;font-size:12px;color:#646970;"><?php echo $info['last'] ? esc_html( human_time_diff( $info['last'], time() ) . ' ago' ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>

				<p style="margin:14px 0 0;padding-top:12px;border-top:1px solid #f0f0f0;font-size:11px;color:#aaa;line-height:1.5;">
					<strong style="color:#888;">Note:</strong>
					<?php esc_html_e( 'Google does not publish a dedicated AI bot. The Google bots tracked here (Googlebot, GoogleOther, Google-Agent, Google-InspectionTool, Google-NotebookLM) are standard Google crawlers whose purpose is inferred from their documented behavior — not from an official AI-specific declaration by Google.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>

			<?php /* ── Top Pages Crawled ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;">
				<h3 style="margin:0 0 16px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;">
					<?php esc_html_e( 'Most Crawled Pages', 'twt-aeo-ultimate' ); ?>
				</h3>
				<?php if ( empty( $stats['top_pages'] ) ) : ?>
					<p style="color:#646970;margin:0;"><?php esc_html_e( 'No pages recorded yet.', 'twt-aeo-ultimate' ); ?></p>
				<?php else : ?>
				<ol style="margin:0;padding-left:18px;">
					<?php foreach ( $stats['top_pages'] as $page ) : ?>
					<li style="margin-bottom:10px;">
						<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener"
						   style="font-size:13px;color:#2271b1;text-decoration:none;font-weight:500;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px;"
						   title="<?php echo esc_attr( $page['url'] ); ?>">
							<?php echo esc_html( $page['title'] ?: $page['url'] ); ?>
						</a>
						<span style="font-size:12px;color:#646970;">
							<?php
								// translators: %s: number of crawls (formatted integer).
								printf( esc_html( _n( '%s crawl', '%s crawls', $page['count'], 'twt-aeo-ultimate' ) ), esc_html( number_format_i18n( $page['count'] ) ) ); ?>
						</span>
					</li>
					<?php endforeach; ?>
				</ol>
				<?php endif; ?>
			</div>

		</div>

		<?php /* ── Live Feed ── */ ?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:24px;">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
				<h3 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;">
					<?php
					if ( TWTAEO_Pro_Transmitter::is_connected() ) {
						esc_html_e( 'Live Feed — Last 48 Hours', 'twt-aeo-ultimate' );
					} else {
						esc_html_e( 'Live Feed — Last 50 Crawls (30 Days)', 'twt-aeo-ultimate' );
					}
					?>
				</h3>
				<?php if ( ! empty( $log ) ) : ?>
				<form method="post" action="" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all crawler log entries?', 'twt-aeo-ultimate' ) ); ?>');">
					<input type="hidden" name="twtaeo_cc_action" value="clear_crawler_log">
					<input type="hidden" name="_twtaeo_cc_nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;">
						<?php esc_html_e( 'Clear Log', 'twt-aeo-ultimate' ); ?>
					</button>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( empty( $log ) ) : ?>
				<div style="text-align:center;padding:40px 0;color:#646970;">
					<p style="font-size:32px;margin:0 0 8px;">🤖</p>
					<?php if ( TWTAEO_Pro_Transmitter::is_connected() ) : ?>
						<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No AI crawlers in the last 48 hours. Historical visits are stored in TWT Agency.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No AI crawlers detected yet. Logs will appear here as AI bots visit your site.', 'twt-aeo-ultimate' ); ?></p>
						<p style="margin:8px 0 0;font-size:12px;color:#999;"><?php esc_html_e( 'Monitoring: OpenAI GPTBot, ClaudeBot, Google-Extended, PerplexityBot, and 20+ more.', 'twt-aeo-ultimate' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>

			<!-- DataViews mount (WP 7.0+). JS hides the legacy table once mounted. -->
			<div id="twt-aeo-dataviews-feed" style="display:none;" aria-live="polite"></div>

			<!-- Legacy table — shown by default, hidden by JS when DataViews mounts. -->
			<table id="twt-aeo-legacy-feed" class="widefat striped" style="border-radius:4px;overflow:hidden;">
				<thead>
					<tr>
						<th style="width:130px;"><?php esc_html_e( 'Time', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:160px;"><?php esc_html_e( 'Bot', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Company', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $entry ) :
					$color = $company_colors[ $entry['company'] ?? '' ] ?? '#888';
					$age   = human_time_diff( $entry['time'] ?? time(), time() );
					$dt    = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ?? 0 );
				?>
					<tr>
						<td style="font-size:12px;color:#646970;" title="<?php echo esc_attr( $dt ); ?>"><?php echo esc_html( $age . ' ago' ); ?></td>
						<td style="font-family:monospace;font-size:12px;">
							<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;margin-right:5px;vertical-align:middle;"></span>
							<?php echo esc_html( $entry['bot'] ?? '—' ); ?>
						</td>
						<td style="font-size:12px;color:#646970;"><?php echo esc_html( $entry['company'] ?? '—' ); ?></td>
						<td style="font-size:13px;">
							<?php if ( ! empty( $entry['url'] ) ) : ?>
								<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:none;">
									<?php echo esc_html( $entry['title'] ?: $entry['url'] ); ?>
								</a>
								<span style="font-size:11px;color:#999;margin-left:6px;"><?php echo esc_html( wp_parse_url( $entry['url'], PHP_URL_PATH ) ?: '/' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $entry['title'] ?? '—' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<?php /* ── Storage / About ── */ ?>
		<?php if ( TWTAEO_Pro_Transmitter::is_connected() ) : ?>
		<div style="background:#edfaef;border-left:4px solid #5ec980;padding:14px 18px;border-radius:0 4px 4px 0;font-size:13px;">
			<strong style="color:#1a6629;">&#10003; <?php esc_html_e( 'Syncing to TWT Agency', 'twt-aeo-ultimate' ); ?></strong> &mdash;
			<?php esc_html_e( 'Crawler visits are recorded in TWT Agency for permanent reporting. This site keeps only the last 48 hours locally to reduce database bloat.', 'twt-aeo-ultimate' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Manage connection →', 'twt-aeo-ultimate' ); ?></a>
		</div>
		<?php else : ?>
		<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 18px;border-radius:0 4px 4px 0;font-size:13px;">
			<strong><?php esc_html_e( 'What AI Crawler Watch monitors:', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'OpenAI GPTBot &amp; ChatGPT-User · Anthropic ClaudeBot · Google-Extended · PerplexityBot · Meta FacebookBot · Apple Applebot-Extended · ByteDance Bytespider · Amazon Amazonbot · Diffbot · Cohere · Common Crawl CCBot · You.com YouBot · and more.', 'twt-aeo-ultimate' ); ?>
			<br><span style="color:#646970;"><?php esc_html_e( 'Logs are stored locally for 30 days. Connect to TWT Agency for permanent long-term reporting.', 'twt-aeo-ultimate' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) ); ?>"><?php esc_html_e( 'Set up connection →', 'twt-aeo-ultimate' ); ?></a></span>
		</div>
		<?php endif; ?>
		<?php
	}

	// ── POST Handlers ────────────────────────────────────────────────────────

	public static function maybe_handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) !== 'twt-aeo-command-center' ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['twtaeo_cc_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$action    = sanitize_key( wp_unslash( $_POST['twtaeo_cc_action'] ) );
		$nonce_val = sanitize_key( wp_unslash( $_POST['_twtaeo_cc_nonce'] ?? '' ) );

		$nonce_map = array(
			'save_google_creds'  => self::NONCE_SAVE_GOOGLE,
			'save_google_config' => self::NONCE_SAVE_GOOGLE,
			'save_bing_creds'    => self::NONCE_SAVE_BING,
			'clear_crawler_log'  => self::NONCE_CLEAR_CRAWLERLOG,
		);

		if ( ! isset( $nonce_map[ $action ] ) || ! wp_verify_nonce( $nonce_val, $nonce_map[ $action ] ) ) {
			return;
		}

		self::handle_post( $action );
	}

	private static function handle_post( $action ) {
		switch ( $action ) {
			case 'save_google_creds':
				if ( ! check_admin_referer( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				TWTAEO_Google_OAuth::save_credentials(
					sanitize_text_field( wp_unslash( $_POST['client_id']     ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) )
				);
				self::set_notice( 'updated', __( 'Google credentials saved.', 'twt-aeo-ultimate' ) );
				break;

			case 'save_google_config':
				if ( ! check_admin_referer( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				TWTAEO_Google_OAuth::save_config(
					sanitize_text_field( wp_unslash( $_POST['ga4_property_id'] ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['gsc_site_url']    ?? '' ) )
				);
				// Bust data cache when config changes.
				self::clear_data_cache();
				self::set_notice( 'updated', __( 'Google configuration saved.', 'twt-aeo-ultimate' ) );
				break;

			case 'save_bing_creds':
				if ( ! check_admin_referer( self::NONCE_SAVE_BING, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				TWTAEO_Bing_Webmaster::save_credentials(
					sanitize_text_field( wp_unslash( $_POST['bing_api_key']  ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['bing_site_url'] ?? '' ) )
				);
				self::set_notice( 'updated', __( 'Bing settings saved.', 'twt-aeo-ultimate' ) );
				break;

			case 'clear_crawler_log':
				if ( ! check_admin_referer( self::NONCE_CLEAR_CRAWLERLOG, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				TWTAEO_AI_Crawler_Logger::clear_log();
				self::set_notice( 'updated', __( 'Crawler log cleared.', 'twt-aeo-ultimate' ) );
				wp_safe_redirect( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'ai-crawler' ), admin_url( 'admin.php' ) ) );
				exit;
		}

		// PRG: redirect to settings tab to show notice.
		wp_safe_redirect( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_google_callback( $code, $state ) {
		$result = TWTAEO_Google_OAuth::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			self::set_notice( 'error', $result->get_error_message() );
		} else {
			self::set_notice( 'updated', __( 'Google account connected successfully.', 'twt-aeo-ultimate' ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Utility / Partials ───────────────────────────────────────────────────

	private static function clear_data_cache() {
		delete_transient( 'twtaeo_ga4_totals' );
		delete_transient( 'twtaeo_ga4_top_pages' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );
		delete_transient( 'twtaeo_bing_stats' );
	}

	private static function set_notice( $type, $message ) {
		set_transient( 'twtaeo_cc_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	private static function render_tab_header( $title, $date_range, $tab ) {
		$refresh_url = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => $tab, 'twtaeo_refresh' => '1' ), admin_url( 'admin.php' ) );
		?>
		<div class="twt-aeo-cc__tab-header">
			<div>
				<h2 class="twt-aeo-cc__tab-title"><?php echo esc_html( $title ); ?></h2>
				<span class="twt-aeo-cc__date-range"><?php echo esc_html( $date_range ); ?></span>
			</div>
			<a href="<?php echo esc_url( $refresh_url ); ?>" class="twt-aeo-cc__refresh-link" title="<?php esc_attr_e( 'Refresh data', 'twt-aeo-ultimate' ); ?>">
				↻ <?php esc_html_e( 'Refresh', 'twt-aeo-ultimate' ); ?>
			</a>
		</div>
		<?php
	}

	private static function render_metric_card( $label, $value ) {
		?>
		<div class="twt-aeo-cc__metric-card">
			<span class="twt-aeo-cc__metric-card-value"><?php echo esc_html( $value ); ?></span>
			<span class="twt-aeo-cc__metric-card-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	private static function render_connect_prompt( $service, $message ) {
		$url = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) );
		?>
		<div class="twt-aeo-cc__prompt">
			<p class="twt-aeo-cc__prompt-message"><?php echo esc_html( $message ); ?></p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
				<?php
				// translators: %s: service name (e.g. 'Google Analytics').
				printf( esc_html__( 'Set up %s', 'twt-aeo-ultimate' ), esc_html( $service ) ); ?>
			</a>
		</div>
		<?php
	}

	private static function render_configure_prompt( $title, $message ) {
		$url = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) );
		?>
		<div class="twt-aeo-cc__prompt">
			<strong class="twt-aeo-cc__prompt-title"><?php echo esc_html( $title ); ?></strong>
			<p class="twt-aeo-cc__prompt-message"><?php echo esc_html( $message ); ?></p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Go to Settings', 'twt-aeo-ultimate' ); ?>
			</a>
		</div>
		<?php
	}

	private static function render_api_error( $message ) {
		?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	// ── Number Formatters ────────────────────────────────────────────────────

	private static function format_number( $n ) {
		$n = (float) $n;
		if ( $n >= 1000000 ) {
			return number_format( $n / 1000000, 1 ) . 'M';
		}
		if ( $n >= 1000 ) {
			return number_format( $n / 1000, 1 ) . 'K';
		}
		return number_format( $n );
	}

	private static function format_duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		$m = floor( $seconds / 60 );
		$s = $seconds % 60;
		return $m . 'm ' . str_pad( $s, 2, '0', STR_PAD_LEFT ) . 's';
	}

	// ── SVG Icons ────────────────────────────────────────────────────────────

	private static function icon_google() {
		return '<svg class="twt-aeo-cc__icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>';
	}

	private static function icon_search_console() {
		return '<svg class="twt-aeo-cc__icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="#4285F4"/></svg>';
	}

	private static function icon_bing() {
		return '<svg class="twt-aeo-cc__icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M5 3v16.497l4.5 2.503 8-4.886-3.5-2.114L9 16.497V3H5z" fill="#008373"/><path d="M9 16.497l2.5-1-3.5-2.114V16.5l1 -.003z" fill="#00b294" opacity=".7"/></svg>';
	}
}
