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
					case 'page-speed':
						self::render_page_speed_tab();
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
		return array( 'overview', 'analytics', 'search-console', 'bing', 'crawlability', 'page-speed', 'index-status', 'ai-crawler', 'settings' );
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
			'page-speed'     => array( 'label' => __( 'Page Speed', 'twt-aeo-ultimate' ),        'dot' => false ),
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
						<?php echo wp_kses( self::icon_google(), self::svg_allowed_tags() ); ?>
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
						<?php echo wp_kses( self::icon_search_console(), self::svg_allowed_tags() ); ?>
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
						<?php echo wp_kses( self::icon_bing(), self::svg_allowed_tags() ); ?>
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

		$totals      = self::fetch_ga4_totals( $property_id );
		$top_pages   = self::fetch_ga4_top_pages( $property_id );
		$ai_traffic  = self::fetch_ga4_ai_traffic( $property_id );

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
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Users', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Page Views', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Bounce Rate', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Avg Time', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Events', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Conversions', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Signal', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $top_pages as $row ) :
						$bounce_pct = (float) ( $row['bounce'] ?? 0 ) * 100;
						$bounce_cls = $bounce_pct >= 90 ? ' twt-aeo-cc__cell--bad' : ( $bounce_pct >= 70 ? ' twt-aeo-cc__cell--warn' : '' );
						?>
					<tr>
						<td>
							<a href="<?php echo esc_url( home_url( $row['path'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $row['path'] ); ?>
							</a>
						</td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['sessions'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['users'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['views'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num<?php echo esc_attr( $bounce_cls ); ?>"><?php echo esc_html( number_format( $bounce_pct, 1 ) . '%' ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_duration( $row['duration'] ?? 0 ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['events'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['key'] ) ); ?></td>
						<td>
							<span class="twt-aeo-cc__signal-badge <?php echo esc_attr( $row['signal']['class'] ); ?>">
								<?php echo esc_html( $row['signal']['label'] ); ?>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="twt-aeo-cc__table-note">
				<?php esc_html_e( 'Signal is a quick read of each page: Converting = drove a key event; Needs work = high bounce or very short visits; Likely bots = traffic with almost no engagement; Healthy = engaged visitors. Treat “Likely bots” as a hint — event counts vary by site.', 'twt-aeo-ultimate' ); ?>
			</p>
		</section>
		<?php endif; ?>

		<?php if ( ! is_wp_error( $ai_traffic ) ) : ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title">
				<?php esc_html_e( 'AI Assistant Traffic', 'twt-aeo-ultimate' ); ?>
			</h3>
			<?php
			$ai_sessions  = (int) ( $ai_traffic['totals']['sessions'] ?? 0 );
			$all_sessions = (int) ( $totals['sessions'] ?? 0 );
			$ai_share     = $all_sessions > 0 ? ( $ai_sessions / $all_sessions ) * 100 : 0;
			?>
			<div class="twt-aeo-cc__metrics-bar">
				<?php
				self::render_metric_card( __( 'AI Sessions', 'twt-aeo-ultimate' ),       self::format_number( $ai_sessions ) );
				self::render_metric_card( __( 'AI Users', 'twt-aeo-ultimate' ),          self::format_number( $ai_traffic['totals']['users'] ?? 0 ) );
				self::render_metric_card( __( '% of All Sessions', 'twt-aeo-ultimate' ), number_format( $ai_share, 1 ) . '%' );
				self::render_metric_card( __( 'AI Conversions', 'twt-aeo-ultimate' ),    self::format_number( $ai_traffic['totals']['key'] ?? 0 ) );
				?>
			</div>

			<?php if ( ! empty( $ai_traffic['sources'] ) ) : ?>
			<table class="twt-aeo-cc__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'AI Source', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Sessions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Users', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Page Views', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Conversions', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ai_traffic['sources'] as $src ) : ?>
					<tr>
						<td><?php echo esc_html( $src['source'] ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $src['sessions'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $src['users'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $src['views'] ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $src['key'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<p class="twt-aeo-cc__table-note">
				<?php esc_html_e( 'No AI assistant traffic detected in the last 30 days. Google began tagging this channel on May 13, 2026, so there is no history before then.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php endif; ?>

			<p class="twt-aeo-cc__table-note">
				<?php esc_html_e( 'Source: GA4’s native “AI Assistant” channel (ChatGPT, Gemini, DeepSeek, Copilot, Grok). Forward-only — no data before May 13, 2026. Perplexity and Claude currently land in Referral, AI Overviews count as Organic Search, and most AI traffic arrives without a referrer (counted as Direct), so real AI influence is higher than shown.', 'twt-aeo-ultimate' ); ?>
			</p>
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

		<?php /* ── URL Inspection ── */ ?>
		<?php $inspect_nonce = wp_create_nonce( 'twtaeo_gsc_inspect' ); ?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'URL Inspection', 'twt-aeo-ultimate' ); ?></h3>
			<p class="twt-aeo-cc__table-note" style="margin-top:0;">
				<?php esc_html_e( 'Check live index status, the Google-selected canonical, and rich-result eligibility for any URL on this property. Needs Owner or Full permission. Results are cached for 1 hour.', 'twt-aeo-ultimate' ); ?>
			</p>
			<div style="display:flex;gap:8px;align-items:center;margin:12px 0;flex-wrap:wrap;">
				<input type="url" id="twt-aeo-inspect-url" class="regular-text"
					   value="<?php echo esc_attr( home_url( '/' ) ); ?>"
					   placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
					   style="flex:1;min-width:280px;" />
				<button type="button" id="twt-aeo-inspect-btn" class="button button-primary"
						data-nonce="<?php echo esc_attr( $inspect_nonce ); ?>">
					<?php esc_html_e( 'Inspect URL', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-inspect-spinner" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0;"></span>
				</span>
			</div>
			<div id="twt-aeo-inspect-result" style="display:none;"></div>
		</section>

		<?php
		ob_start();
		?>
		(function($){
			var vmap = {
				PASS:    { bg:'#dcfce7', c:'#166534', label:'<?php echo esc_js( __( 'Pass', 'twt-aeo-ultimate' ) ); ?>' },
				PARTIAL: { bg:'#fef9c3', c:'#854d0e', label:'<?php echo esc_js( __( 'Partial', 'twt-aeo-ultimate' ) ); ?>' },
				FAIL:    { bg:'#fee2e2', c:'#991b1b', label:'<?php echo esc_js( __( 'Fail', 'twt-aeo-ultimate' ) ); ?>' },
				NEUTRAL: { bg:'#f3f4f6', c:'#374151', label:'<?php echo esc_js( __( 'Neutral', 'twt-aeo-ultimate' ) ); ?>' }
			};

			function badge(v){
				var m = vmap[v] || vmap.NEUTRAL;
				return '<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:'+m.bg+';color:'+m.c+';">'+m.label+'</span>';
			}
			function escHtml(s){
				return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}
			function row(label, val){
				return '<tr><th scope="row" style="width:200px;text-align:left;padding:6px 10px;">'+escHtml(label)+'</th><td style="padding:6px 10px;">'+val+'</td></tr>';
			}

			$('#twt-aeo-inspect-btn').on('click', function(){
				var $btn = $(this), $sp = $('#twt-aeo-inspect-spinner'), $out = $('#twt-aeo-inspect-result');
				var url = $.trim($('#twt-aeo-inspect-url').val());
				if (!url){ return; }

				$btn.prop('disabled', true); $sp.show(); $out.hide().empty();

				$.post(twtAeo.ajaxUrl, { action:'twtaeo_gsc_inspect', nonce:$btn.data('nonce'), url:url }, function(resp){
					$sp.hide(); $btn.prop('disabled', false);

					if (!resp.success || !resp.data){
						$out.html('<div class="notice notice-error inline" style="margin:0;"><p>'+escHtml(resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js( __( 'Inspection failed.', 'twt-aeo-ultimate' ) ); ?>')+'</p></div>').show();
						return;
					}

					var d = resp.data, canon = '';
					if (d.canonical_match === true){
						canon = '<span style="color:#166534;font-weight:600;">&#10003; <?php echo esc_js( __( 'Match', 'twt-aeo-ultimate' ) ); ?></span>';
					} else if (d.canonical_match === false){
						canon = '<span style="color:#991b1b;font-weight:600;">&#10007; <?php echo esc_js( __( 'Mismatch', 'twt-aeo-ultimate' ) ); ?></span>'
							+ '<div style="font-size:12px;color:#555;margin-top:4px;"><?php echo esc_js( __( 'Google:', 'twt-aeo-ultimate' ) ); ?> '+escHtml(d.google_canonical||'—')+'<br><?php echo esc_js( __( 'Declared:', 'twt-aeo-ultimate' ) ); ?> '+escHtml(d.user_canonical||'—')+'</div>';
					} else {
						canon = '—';
					}

					var rich = d.rich_types && d.rich_types.length ? escHtml(d.rich_types.join(', ')) : '<?php echo esc_js( __( 'None detected', 'twt-aeo-ultimate' ) ); ?>';
					var richCell = (d.rich_verdict ? badge(d.rich_verdict)+' ' : '') + rich;

					var html = '<div class="twt-aeo-card" style="margin-top:8px;">'
						+ '<p style="margin:0 0 10px;font-size:13px;"><strong><?php echo esc_js( __( 'Overall:', 'twt-aeo-ultimate' ) ); ?></strong> '+badge(d.verdict)+' '
						+ '<span style="color:#6b7280;">'+escHtml(d.coverage)+'</span>'
						+ (d.cached ? ' <span style="font-size:11px;color:#9ca3af;">(<?php echo esc_js( __( 'cached', 'twt-aeo-ultimate' ) ); ?>)</span>' : '')
						+ '</p>'
						+ '<table class="widefat striped" style="margin:0;">'
						+ row('<?php echo esc_js( __( 'Coverage', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.coverage))
						+ row('<?php echo esc_js( __( 'Indexing allowed', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.indexing))
						+ row('<?php echo esc_js( __( 'robots.txt', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.robots))
						+ row('<?php echo esc_js( __( 'Page fetch', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.fetch))
						+ row('<?php echo esc_js( __( 'Crawled as', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.crawled_as))
						+ row('<?php echo esc_js( __( 'Last crawl', 'twt-aeo-ultimate' ) ); ?>', escHtml(d.last_crawl||'—'))
						+ row('<?php echo esc_js( __( 'Canonical', 'twt-aeo-ultimate' ) ); ?>', canon)
						+ row('<?php echo esc_js( __( 'Rich results', 'twt-aeo-ultimate' ) ); ?>', richCell)
						+ (d.mobile_verdict ? row('<?php echo esc_js( __( 'Mobile usability', 'twt-aeo-ultimate' ) ); ?>', badge(d.mobile_verdict)) : '')
						+ '</table>';
					if (d.report_link){
						html += '<p style="margin:10px 0 0;"><a href="'+escHtml(d.report_link)+'" target="_blank" rel="noopener"><?php echo esc_js( __( 'Open full report in Search Console ↗', 'twt-aeo-ultimate' ) ); ?></a></p>';
					}
					html += '</div>';
					$out.html(html).show();
				}).fail(function(){
					$sp.hide(); $btn.prop('disabled', false);
					$out.html('<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Request failed. Check your connection.', 'twt-aeo-ultimate' ) ); ?></p></div>').show();
				});
			});
		}(jQuery));
		<?php
		wp_add_inline_script( 'twt-aeo-command-center', ob_get_clean() );
		?>

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
		$gsc_ai_url = 'https://search.google.com/search-console/performance/search-analytics?resource_id=' . rawurlencode( $site_url );
		?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'AI Search (AI Mode & AI Overviews)', 'twt-aeo-ultimate' ); ?></h3>
			<div class="twt-aeo-cc__callout">
				<p>
					<?php esc_html_e( 'Google launched AI search performance reports (AI Mode and AI Overviews) in Search Console on June 3, 2026, but does not yet expose this data through its API — so it can’t be pulled in here automatically. For now, view your AI Mode / AI Overview impressions directly in Search Console. This panel will show live data once Google adds API support.', 'twt-aeo-ultimate' ); ?>
				</p>
				<a href="<?php echo esc_url( $gsc_ai_url ); ?>" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Open AI report in Search Console ↗', 'twt-aeo-ultimate' ); ?>
				</a>
			</div>
		</section>
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
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'Top Queries', 'twt-aeo-ultimate' ); ?></h3>
			<table class="twt-aeo-cc__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'CTR', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Avg Position', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $stats['rows'], 0, 15 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['query'] ?? '—' ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['impressions'] ?? 0 ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( self::format_number( $row['clicks'] ?? 0 ) ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( number_format( (float) ( $row['ctr'] ?? 0 ) * 100, 1 ) . '%' ); ?></td>
						<td class="twt-aeo-cc__col-num"><?php echo esc_html( isset( $row['position'] ) ? number_format( (float) $row['position'], 1 ) : '—' ); ?></td>
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

		<?php
		// ── AI Citations (dashboard-only) ──
		$bing_creds   = TWTAEO_Bing_Webmaster::get_credentials();
		$bing_site    = $bing_creds['site_url'] ?? '';
		$bing_ai_url  = 'https://www.bing.com/webmasters/aiperformance';
		if ( $bing_site ) {
			$bing_ai_url .= '?siteUrl=' . rawurlencode( $bing_site );
		}
		?>
		<section class="twt-aeo-cc__data-section">
			<h3 class="twt-aeo-cc__section-title"><?php esc_html_e( 'AI Citations (Copilot & Bing AI)', 'twt-aeo-ultimate' ); ?></h3>
			<div class="twt-aeo-cc__callout">
				<p>
					<?php esc_html_e( 'Bing Webmaster Tools added an AI Performance report (public preview, February 2026) showing how often your pages are cited in Copilot and Bing AI answers — total citations and your most-cited pages. Microsoft does not yet expose this through the Webmaster API, so it can’t be pulled in here automatically. For now, view your cited pages directly in Bing Webmaster Tools. This panel will list pages and citation counts once Bing adds API support.', 'twt-aeo-ultimate' ); ?>
				</p>
				<a href="<?php echo esc_url( $bing_ai_url ); ?>" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Open AI Performance in Bing ↗', 'twt-aeo-ultimate' ); ?>
				</a>
			</div>
		</section>

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
		$google_creds   = TWTAEO_Google_OAuth::get_credentials();
		$google_config  = TWTAEO_Google_OAuth::get_config();
		$google_api_key = TWTAEO_Google_OAuth::get_api_key();
		$bing_creds     = TWTAEO_Bing_Webmaster::get_credentials();
		$redirect_uri   = TWTAEO_Google_OAuth::get_redirect_uri();
		$oauth_url      = TWTAEO_Google_OAuth::get_oauth_url();

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
				<?php echo wp_kses( self::icon_google(), self::svg_allowed_tags() ); ?>
				<?php esc_html_e( 'Google Analytics &amp; Search Console', 'twt-aeo-ultimate' ); ?>
				<?php if ( $google_connected ) : ?>
					<span class="twt-aeo-cc__status-badge twt-aeo-cc__status-badge--connected"><?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></span>
				<?php endif; ?>
			</h2>

			<div class="twt-aeo-card">

				<?php /* ── Option A — Service Account (recommended, no OAuth) ── */ ?>
				<?php
				$sa_configured = TWTAEO_Google_Service_Account::is_configured();
				$sa_protected  = TWTAEO_Google_Service_Account::is_protected();
				$sa_email      = TWTAEO_Google_Service_Account::get_client_email();
				?>
				<div class="twt-aeo-cc__setup-step" style="border:2px solid <?php echo $sa_configured ? '#16a34a' : '#2271b1'; ?>;border-radius:8px;padding:16px;margin-bottom:24px;">
					<h3 class="twt-aeo-cc__step-title" style="margin-top:0;">
						<span class="dashicons dashicons-superhero" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Service Account', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-badge" style="background:#dbeafe;color:#1e40af;margin-left:6px;"><?php esc_html_e( 'Recommended', 'twt-aeo-ultimate' ); ?></span>
						<?php if ( $sa_configured ) : ?>
							<span class="twt-aeo-cc__status-badge twt-aeo-cc__status-badge--connected"><?php esc_html_e( 'Active', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</h3>

					<?php if ( $sa_configured ) : ?>
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Google access is authenticated with this service account — no sign-in, no consent screens, tokens never expire.', 'twt-aeo-ultimate' ); ?>
						</p>
						<div class="twt-aeo-cc__redirect-uri">
							<label class="twt-aeo-cc__redirect-label"><?php esc_html_e( 'Make sure this email is added as a user (copy it):', 'twt-aeo-ultimate' ); ?></label>
							<div class="twt-aeo-cc__redirect-row">
								<code class="twt-aeo-cc__redirect-value" id="twt-aeo-sa-email"><?php echo esc_html( $sa_email ); ?></code>
								<button type="button" class="button button-secondary twt-aeo-cc__copy-btn" data-copy-target="twt-aeo-sa-email">
									<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
								</button>
							</div>
						</div>
						<ul style="margin:8px 0 12px;font-size:13px;color:#50575e;list-style:disc;padding-left:20px;">
							<li><?php esc_html_e( 'Search Console → Settings → Users and permissions → Add user → permission: Full', 'twt-aeo-ultimate' ); ?></li>
							<li><?php esc_html_e( 'GA4 → Admin → Property access management → Add user → role: Viewer', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<?php if ( $sa_protected ) : ?>
							<p class="twt-aeo-card__note twt-aeo-card__note--muted"><?php esc_html_e( 'Key is defined in wp-config (TWTAEO_GOOGLE_SA_KEY) and cannot be changed here.', 'twt-aeo-ultimate' ); ?></p>
						<?php else : ?>
							<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'Remove the service-account key from this site?', 'twt-aeo-ultimate' ) ); ?>');" style="margin-top:4px;">
								<?php wp_nonce_field( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ); ?>
								<input type="hidden" name="twtaeo_cc_action" value="remove_google_sa" />
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove key', 'twt-aeo-ultimate' ); ?></button>
							</form>
						<?php endif; ?>
					<?php else : ?>
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Skip the OAuth setup below entirely: paste your agency\'s service-account JSON key once, then add its email address as a user on this site\'s Search Console property (Full) and GA4 property (Viewer). No Google Cloud setup per site, no consent screens, and the connection never expires.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php if ( $sa_protected ) : ?>
							<p class="twt-aeo-card__note twt-aeo-card__note--muted"><?php esc_html_e( 'A wp-config key is defined but could not be parsed — check TWTAEO_GOOGLE_SA_KEY.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
						<form method="post" action="">
							<?php wp_nonce_field( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ); ?>
							<input type="hidden" name="twtaeo_cc_action" value="save_google_sa" />
							<textarea name="sa_key_json" rows="5" class="large-text code" placeholder='{ "type": "service_account", "project_id": "...", "private_key": "...", "client_email": "...@....iam.gserviceaccount.com", ... }' autocomplete="off"></textarea>
							<?php submit_button( __( 'Save Service Account Key', 'twt-aeo-ultimate' ), 'primary', 'submit_google_sa', false, array( 'style' => 'margin-top:8px;' ) ); ?>
						</form>
					<?php endif; ?>
				</div>

				<?php if ( ! $sa_configured ) : ?>
				<p class="twt-aeo-card__note twt-aeo-card__note--muted" style="margin:0 0 16px;">
					<?php esc_html_e( '— or connect with OAuth below (requires a Google Cloud app with this site\'s redirect URI authorized) —', 'twt-aeo-ultimate' ); ?>
				</p>
				<?php endif; ?>

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
							<?php echo wp_kses( self::icon_google(), self::svg_allowed_tags() ); ?>
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
									<?php
									// A dropdown of the properties this Google account can use:
									// a typed address has to match one exactly, and rarely does.
									$gsc_saved   = $google_config['gsc_site_url'] ?? '';
									$gsc_choices = $google_connected ? TWTAEO_Google_OAuth::gsc_property_choices() : null;
									?>
									<?php if ( is_array( $gsc_choices ) && $gsc_choices ) : ?>
										<?php $gsc_selected = TWTAEO_Google_OAuth::gsc_preselect( $gsc_choices, $gsc_saved ); ?>
										<select id="twt-aeo-gsc-url" name="gsc_site_url" class="regular-text">
											<option value=""><?php esc_html_e( '— Choose a property —', 'twt-aeo-ultimate' ); ?></option>
											<?php foreach ( $gsc_choices as $choice ) : ?>
												<option value="<?php echo esc_attr( $choice['url'] ); ?>" <?php selected( $gsc_selected, $choice['url'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'These are the Search Console properties the connected Google account can use. Choose the one for this site — a domain property covers every version of it (www, non-www, http, https).', 'twt-aeo-ultimate' ); ?>
											<?php if ( '' !== (string) $gsc_saved && $gsc_selected !== TWTAEO_Google_OAuth::normalize_gsc_site( $gsc_saved ) ) : ?>
												<br /><strong style="color:#996800;">
													<?php
													echo esc_html(
														'' !== $gsc_selected
															/* translators: 1: saved address, 2: matching property. */
															? sprintf( __( 'Saved address %1$s is not a property on this account; %2$s is selected instead. Click Save to use it.', 'twt-aeo-ultimate' ), $gsc_saved, $gsc_selected )
															/* translators: %s: saved address. */
															: sprintf( __( 'Saved address %s is not a property on this account, which is why Google refuses it. Choose one of these and click Save.', 'twt-aeo-ultimate' ), $gsc_saved )
													);
													?>
												</strong>
											<?php endif; ?>
										</p>
									<?php else : ?>
										<input type="text" id="twt-aeo-gsc-url" name="gsc_site_url"
											   value="<?php echo esc_attr( $gsc_saved ); ?>"
											   class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
											   <?php echo ! $google_connected ? 'disabled' : ''; ?> />
										<?php if ( is_wp_error( $gsc_choices ) ) : ?>
											<p class="description" style="color:#d63638;">
												<?php
												/* translators: %s: Google's error message. */
												echo esc_html( sprintf( __( 'Could not load your Search Console properties from Google: %s', 'twt-aeo-ultimate' ), $gsc_choices->get_error_message() ) );
												?>
											</p>
										<?php elseif ( is_array( $gsc_choices ) ) : ?>
											<p class="description" style="color:#d63638;">
												<?php esc_html_e( 'The Google account connected here has no Search Console properties, so Google refuses every address. Connect the Google account that owns this site in Search Console, or add this account as a user there (Search Console → Settings → Users and permissions).', 'twt-aeo-ultimate' ); ?>
											</p>
										<?php endif; ?>
										<p class="description">
											<?php
											echo wp_kses_post(
												sprintf(
													/* translators: 1: this site's home URL, 2: this site's sc-domain: property name. */
													esc_html__( 'Must match the property in Search Console. URL-prefix property: the exact URL, e.g. %1$s. Domain property (DNS-verified): use %2$s', 'twt-aeo-ultimate' ),
													'<code>' . esc_html( home_url( '/' ) ) . '</code>',
													'<code>sc-domain:' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
												)
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Configuration', 'twt-aeo-ultimate' ), 'secondary', 'submit_google_config', false, $google_connected ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

			</div>
		</section>

		<?php /* ── Additional Google APIs (URL Inspection + Knowledge Graph + PageSpeed) ── */ ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php echo wp_kses( self::icon_google(), self::svg_allowed_tags() ); ?>
				<?php esc_html_e( 'Additional Google APIs', 'twt-aeo-ultimate' ); ?>
				<?php if ( $google_api_key ) : ?>
					<span class="twt-aeo-cc__status-badge twt-aeo-cc__status-badge--connected"><?php esc_html_e( 'API key saved', 'twt-aeo-ultimate' ); ?></span>
				<?php endif; ?>
			</h2>

			<div class="twt-aeo-card">
				<p class="twt-aeo-card__note">
					<?php esc_html_e( 'These power URL Inspection, brand/entity verification, and PageSpeed audits. Because you have already connected Analytics &amp; Search Console, setup is minimal — everything below lives in the same Google Cloud project you used earlier.', 'twt-aeo-ultimate' ); ?>
				</p>

				<?php /* ── URL Inspection — no setup, just a permission check ── */ ?>
				<div class="twt-aeo-cc__setup-step">
					<h3 class="twt-aeo-cc__step-title">
						<span class="dashicons dashicons-search" style="color:#2271b1;"></span>
						<?php esc_html_e( 'URL Inspection', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-badge" style="background:#dcfce7;color:#166534;margin-left:6px;"><?php esc_html_e( 'No setup needed', 'twt-aeo-ultimate' ); ?></span>
					</h3>
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'There is nothing to enable in Google Cloud — URL Inspection is part of the Search Console API you already connected. It just needs a higher permission level than basic stats:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ul style="margin:8px 0 0;font-size:13px;color:#50575e;list-style:disc;padding-left:20px;">
						<li><?php esc_html_e( 'Open Search Console → Settings → Users and permissions.', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Find the account (or service-account email) you connected this plugin with.', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Make sure its permission is Owner or Full — not Restricted. If it is Restricted, raise it to Full.', 'twt-aeo-ultimate' ); ?></li>
					</ul>
				</div>

				<?php /* ── Knowledge Graph + PageSpeed — one shared API key ── */ ?>
				<div class="twt-aeo-cc__setup-step">
					<h3 class="twt-aeo-cc__step-title">
						<span class="dashicons dashicons-admin-network" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Knowledge Graph &amp; PageSpeed API Key', 'twt-aeo-ultimate' ); ?>
					</h3>
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'These two APIs read public Google data, so they use a single free API key — no OAuth, and no billing card required (PageSpeed allows 25,000 checks/day free). Create the key once in the same project:', 'twt-aeo-ultimate' ); ?>
					</p>
					<ol style="margin:8px 0 12px;font-size:13px;color:#50575e;padding-left:20px;">
						<li>
							<?php
							printf(
								/* translators: %s: link to Google Cloud API Library */
								esc_html__( 'In %s, search "Knowledge Graph Search API" and click Enable, then do the same for "PageSpeed Insights API".', 'twt-aeo-ultimate' ),
								'<a href="https://console.cloud.google.com/apis/library" target="_blank" rel="noopener">' . esc_html__( 'APIs &amp; Services → Library', 'twt-aeo-ultimate' ) . '</a>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: link to Google Cloud Credentials page */
								esc_html__( 'Go to %s → Create Credentials → API key, then copy the key.', 'twt-aeo-ultimate' ),
								'<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">' . esc_html__( 'APIs &amp; Services → Credentials', 'twt-aeo-ultimate' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Recommended: edit the key → Restrict key → allow only Knowledge Graph Search API and PageSpeed Insights API.', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Paste the key below and save.', 'twt-aeo-ultimate' ); ?></li>
					</ol>

					<form method="post" action="">
						<?php wp_nonce_field( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ); ?>
						<input type="hidden" name="twtaeo_cc_action" value="save_google_api_key" />

						<table class="form-table twt-aeo-cc__form-table">
							<tr>
								<th scope="row"><label for="twt-aeo-google-api-key"><?php esc_html_e( 'Google API Key', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="password" id="twt-aeo-google-api-key" name="google_api_key"
										   value="<?php echo esc_attr( $google_api_key ); ?>"
										   class="regular-text" autocomplete="off"
										   placeholder="AIza…" />
									<p class="description"><?php esc_html_e( 'Leave empty and save to remove the key.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save API Key', 'twt-aeo-ultimate' ), 'secondary', 'submit_google_api_key', false ); ?>
					</form>
				</div>

			</div>
		</section>

		<?php /* ── Bing Section ── */ ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php echo wp_kses( self::icon_bing(), self::svg_allowed_tags() ); ?>
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

	// ── Page Speed Tab ───────────────────────────────────────────────────────

	private static function render_page_speed_tab() {
		$nonce    = wp_create_nonce( 'twtaeo_psi_check' );
		$has_key  = TWTAEO_Google_OAuth::has_api_key();
		$settings = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) );

		$leak_nonce = wp_create_nonce( 'twtaeo_leak_scan' );
		$ga4_config = TWTAEO_Google_OAuth::get_config();
		$ga4_ready  = TWTAEO_Google_OAuth::is_connected() && ! empty( $ga4_config['ga4_property_id'] );
		$leak_prior = TWTAEO_Traffic_Leak_Scanner::state_payload();
		?>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Traffic Leak Scan', 'twt-aeo-ultimate' ); ?></h2>
			<p class="twt-aeo-cc__table-note" style="margin-top:0;">
				<?php esc_html_e( 'Finds your highest-traffic pages that also bounce hard, then audits each one\'s mobile speed — surfacing pages that may be losing visitors to slow loads. Uses GA4 (last 28 days) + PageSpeed.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php if ( ! $ga4_ready ) : ?>
				<div class="twt-aeo-cc__callout">
					<p><?php esc_html_e( 'Connect Google Analytics and set your GA4 Property ID in Settings to run this scan.', 'twt-aeo-ultimate' ); ?></p>
					<a href="<?php echo esc_url( $settings ); ?>" class="button button-secondary"><?php esc_html_e( 'Configure', 'twt-aeo-ultimate' ); ?></a>
				</div>
			<?php else : ?>
				<div style="display:flex;gap:10px;align-items:center;margin:12px 0;flex-wrap:wrap;">
					<label style="font-size:13px;"><?php esc_html_e( 'Min sessions (28d)', 'twt-aeo-ultimate' ); ?>
						<input type="number" id="twt-aeo-leak-min" value="10" min="1" style="width:80px;margin-left:4px;" />
					</label>
					<button type="button" id="twt-aeo-leak-btn" class="button button-primary" data-nonce="<?php echo esc_attr( $leak_nonce ); ?>">
						<?php esc_html_e( 'Scan for traffic leaks', 'twt-aeo-ultimate' ); ?>
					</button>
					<span id="twt-aeo-leak-progress" style="font-size:13px;color:var(--aeo-text-muted,#6b7280);"></span>
				</div>
				<table class="twt-aeo-cc__table widefat striped" id="twt-aeo-leak-table" style="display:none;">
					<thead><tr>
						<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Sessions', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Bounce', 'twt-aeo-ultimate' ); ?></th>
						<th class="twt-aeo-cc__col-num"><?php esc_html_e( 'Mobile', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'LCP', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Verdict', 'twt-aeo-ultimate' ); ?></th>
					</tr></thead>
					<tbody id="twt-aeo-leak-tbody"></tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Page Speed (PageSpeed Insights)', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( ! $has_key ) : ?>
				<div class="twt-aeo-cc__callout">
					<p><?php
						printf(
							/* translators: %s: link to the Settings tab */
							esc_html__( 'Running without a Google API key (lower daily quota). Add one under %s for the full 25,000/day limit.', 'twt-aeo-ultimate' ),
							'<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings → Additional Google APIs', 'twt-aeo-ultimate' ) . '</a>'
						);
					?></p>
				</div>
			<?php endif; ?>

			<p class="twt-aeo-cc__table-note" style="margin-top:0;">
				<?php esc_html_e( 'Check a page\'s mobile or desktop performance — the Lighthouse score plus the Core Web Vitals that affect both ranking and how cleanly AI crawlers render the page. Results are cached for 6 hours.', 'twt-aeo-ultimate' ); ?>
			</p>

			<div style="display:flex;gap:8px;align-items:center;margin:12px 0;flex-wrap:wrap;">
				<input type="url" id="twt-aeo-psi-url" class="regular-text"
					   value="<?php echo esc_attr( home_url( '/' ) ); ?>"
					   placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
					   style="flex:1;min-width:260px;" />
				<select id="twt-aeo-psi-strategy">
					<option value="mobile"><?php esc_html_e( 'Mobile', 'twt-aeo-ultimate' ); ?></option>
					<option value="desktop"><?php esc_html_e( 'Desktop', 'twt-aeo-ultimate' ); ?></option>
				</select>
				<button type="button" id="twt-aeo-psi-btn" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Analyze', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-psi-spinner" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0;"></span>
					<span style="font-size:12px;color:var(--aeo-text-muted,#6b7280);"><?php esc_html_e( 'Running Lighthouse… up to a minute.', 'twt-aeo-ultimate' ); ?></span>
				</span>
			</div>
			<div id="twt-aeo-psi-result" style="display:none;"></div>
		</section>

		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Code Health', 'twt-aeo-ultimate' ); ?></h2>
			<p class="twt-aeo-cc__table-note" style="margin-top:0;">
				<?php esc_html_e( 'Scans your published pages for performance anti-patterns that page-builders and AI-pasted code often introduce — inline scripts, external scripts in content, base64 images, and iframes. On-server only; nothing is changed.', 'twt-aeo-ultimate' ); ?>
			</p>
			<div style="display:flex;gap:8px;align-items:center;margin:12px 0;flex-wrap:wrap;">
				<button type="button" id="twt-aeo-ch-btn" class="button button-primary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'twtaeo_code_health' ) ); ?>">
					<?php esc_html_e( 'Scan content for performance issues', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-aeo-ch-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
				<span id="twt-aeo-ch-status" style="font-size:13px;color:var(--aeo-text-muted,#6b7280);"></span>
			</div>
			<div id="twt-aeo-ch-results"></div>
		</section>

		<?php
		ob_start();
		?>
		(function($){
			function scoreColor(s){ return s>=90?{bg:'#dcfce7',c:'#166534'}:(s>=50?{bg:'#fef9c3',c:'#854d0e'}:{bg:'#fee2e2',c:'#991b1b'}); }
			function metricColor(sc){ if(sc===null||sc===undefined) return '#374151'; return sc>=0.9?'#166534':(sc>=0.5?'#854d0e':'#991b1b'); }
			function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

			$('#twt-aeo-psi-btn').on('click', function(){
				var $btn=$(this), $sp=$('#twt-aeo-psi-spinner'), $out=$('#twt-aeo-psi-result');
				var url=$.trim($('#twt-aeo-psi-url').val()), strat=$('#twt-aeo-psi-strategy').val();
				if(!url){ return; }
				$btn.prop('disabled',true); $sp.show(); $out.hide().empty();

				$.post(twtAeo.ajaxUrl, { action:'twtaeo_psi_check', nonce:$btn.data('nonce'), url:url, strategy:strat }, function(resp){
					$sp.hide(); $btn.prop('disabled',false);
					if(!resp.success||!resp.data){
						$out.html('<div class="notice notice-error inline" style="margin:0;"><p>'+esc(resp.data&&resp.data.message?resp.data.message:'<?php echo esc_js( __( 'Analysis failed.', 'twt-aeo-ultimate' ) ); ?>')+'</p></div>').show();
						return;
					}
					var d=resp.data;
					var sc=(d.score===null||d.score===undefined)?'—':d.score;
					var col=(d.score===null||d.score===undefined)?{bg:'#f3f4f6',c:'#374151'}:scoreColor(d.score);

					var html='<div class="twt-aeo-card" style="margin-top:8px;">';
					html+='<div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">';
					html+='<span style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;font-size:22px;font-weight:700;background:'+col.bg+';color:'+col.c+';">'+sc+'</span>';
					html+='<div><strong><?php echo esc_js( __( 'Performance score', 'twt-aeo-ultimate' ) ); ?></strong> ('+esc(d.strategy)+')'+(d.cached?' <span style="font-size:11px;color:#9ca3af;">(<?php echo esc_js( __( 'cached', 'twt-aeo-ultimate' ) ); ?>)</span>':'')+'<br><span style="font-size:12px;color:#6b7280;"><?php echo esc_js( __( 'Lab data — simulated mobile load', 'twt-aeo-ultimate' ) ); ?></span></div>';
					html+='</div>';

					var labels={lcp:'LCP',tbt:'TBT',cls:'CLS',fcp:'FCP',si:'<?php echo esc_js( __( 'Speed Index', 'twt-aeo-ultimate' ) ); ?>'};
					html+='<table class="widefat striped" style="margin:0;">';
					$.each(['lcp','tbt','cls','fcp','si'], function(i,k){
						if(d.lab && d.lab[k]){
							html+='<tr><th scope="row" style="width:160px;text-align:left;padding:6px 10px;">'+labels[k]+'</th><td style="padding:6px 10px;font-weight:600;color:'+metricColor(d.lab[k].score)+';">'+esc(d.lab[k].display)+'</td></tr>';
						}
					});
					html+='</table>';

					if(d.field && d.field.verdict){
						html+='<p style="margin:10px 0 0;font-size:13px;"><strong><?php echo esc_js( __( 'Real-world field data (CrUX):', 'twt-aeo-ultimate' ) ); ?></strong> '+esc(d.field.verdict)+'</p>';
					}
					html+='</div>';
					$out.html(html).show();
				}).fail(function(){
					$sp.hide(); $btn.prop('disabled',false);
					$out.html('<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Request failed or timed out. PageSpeed can take up to a minute — try again.', 'twt-aeo-ultimate' ) ); ?></p></div>').show();
				});
			});
		}(jQuery));
		<?php
		wp_add_inline_script( 'twt-aeo-command-center', ob_get_clean() );

		// ── Traffic-leak scan driver ──
		$leak_seed = $ga4_ready ? array_values( $leak_prior['results'] ) : array();
		ob_start();
		?>
		(function($){
			var sevColor={
				critical:{bg:'#fee2e2',c:'#991b1b'},
				warning: {bg:'#fef9c3',c:'#854d0e'},
				ok:      {bg:'#dcfce7',c:'#166534'},
				unknown: {bg:'#f3f4f6',c:'#374151'}
			};
			function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
			function scoreCell(s){ if(s===null||s===undefined) return '—'; var c=s>=90?'#166534':(s>=50?'#854d0e':'#991b1b'); return '<span style="font-weight:600;color:'+c+';">'+s+'</span>'; }
			function renderRow(r){
				var sv=sevColor[r.severity]||sevColor.unknown;
				return '<tr>'+
					'<td><a href="'+esc(r.url)+'" target="_blank" rel="noopener">'+esc(r.path)+'</a></td>'+
					'<td class="twt-aeo-cc__col-num">'+esc(r.sessions)+'</td>'+
					'<td class="twt-aeo-cc__col-num">'+esc(r.bounce)+'%</td>'+
					'<td class="twt-aeo-cc__col-num">'+scoreCell(r.score)+'</td>'+
					'<td>'+esc(r.lcp)+'</td>'+
					'<td><span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:'+sv.bg+';color:'+sv.c+';">'+esc(r.verdict)+'</span></td>'+
					'</tr>';
			}

			var $btn=$('#twt-aeo-leak-btn'), $tb=$('#twt-aeo-leak-tbody'), $prog=$('#twt-aeo-leak-progress'), $table=$('#twt-aeo-leak-table');
			if(!$btn.length){ return; }

			// Seed the table with the last scan, if any.
			var seed=<?php echo wp_json_encode( $leak_seed ); ?>;
			if(seed && seed.length){
				seed.forEach(function(r){ $tb.append(renderRow(r)); });
				$table.show();
				$prog.text('<?php echo esc_js( __( 'Showing your last scan.', 'twt-aeo-ultimate' ) ); ?>');
			}

			$btn.on('click', function(){
				var nonce=$btn.data('nonce');
				var min=parseInt($('#twt-aeo-leak-min').val(),10)||10;
				$btn.prop('disabled',true); $tb.empty(); $table.show();
				$prog.text('<?php echo esc_js( __( 'Finding candidates…', 'twt-aeo-ultimate' ) ); ?>');

				$.post(twtAeo.ajaxUrl, { action:'twtaeo_leak_scan_start', nonce:nonce, min_sessions:min }, function(resp){
					if(!resp.success){ $prog.text('✗ '+esc(resp.data&&resp.data.message?resp.data.message:'')); $btn.prop('disabled',false); return; }
					var total=resp.data.total, runId=resp.data.run_id||'';
					if(!total){ $prog.text('<?php echo esc_js( __( 'No high-traffic, high-bounce pages found.', 'twt-aeo-ultimate' ) ); ?>'); $btn.prop('disabled',false); return; }
					var i=0;
					(function next(){
						if(i>=total){ $prog.text('<?php echo esc_js( __( 'Done — pages audited:', 'twt-aeo-ultimate' ) ); ?> '+total); $btn.prop('disabled',false); return; }
						$prog.text('<?php echo esc_js( __( 'Auditing', 'twt-aeo-ultimate' ) ); ?> '+(i+1)+'/'+total+'…');
						$.post(twtAeo.ajaxUrl, { action:'twtaeo_leak_scan_next', nonce:nonce, run_id:runId, index:i }, function(r){
							if(r.success && r.data && r.data.result){ $tb.append(renderRow(r.data.result)); }
							i++; next();
						}).fail(function(){ i++; next(); });
					})();
				}).fail(function(){ $prog.text('✗ <?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>'); $btn.prop('disabled',false); });
			});
		}(jQuery));
		<?php
		wp_add_inline_script( 'twt-aeo-command-center', ob_get_clean() );

		// ── Code-health scan driver ──
		ob_start();
		?>
		(function($){
			var sev={ critical:{bg:'#fee2e2',c:'#991b1b'}, warning:{bg:'#fef9c3',c:'#854d0e'}, notice:{bg:'#f3f4f6',c:'#374151'} };
			function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

			var $btn=$('#twt-aeo-ch-btn');
			if(!$btn.length){ return; }

			$btn.on('click', function(){
				var $sp=$('#twt-aeo-ch-spinner'), $st=$('#twt-aeo-ch-status'), $out=$('#twt-aeo-ch-results');
				$btn.prop('disabled',true); $sp.show(); $st.text(''); $out.empty();
				$.post(twtAeo.ajaxUrl, { action:'twtaeo_code_health_scan', nonce:$btn.data('nonce') }, function(resp){
					$sp.hide(); $btn.prop('disabled',false);
					if(!resp.success||!resp.data){ $st.text('✗ '+esc(resp.data&&resp.data.message?resp.data.message:'')); return; }
					var d=resp.data, off=d.offenders||[];
					$st.text('<?php echo esc_js( __( 'Scanned', 'twt-aeo-ultimate' ) ); ?> '+d.scanned+' <?php echo esc_js( __( 'pages —', 'twt-aeo-ultimate' ) ); ?> '+off.length+' <?php echo esc_js( __( 'with issues.', 'twt-aeo-ultimate' ) ); ?>');
					if(!off.length){ $out.html('<p style="color:#166534;">&#10003; <?php echo esc_js( __( 'No performance anti-patterns found.', 'twt-aeo-ultimate' ) ); ?></p>'); return; }
					var html='';
					off.forEach(function(o){
						html+='<div class="twt-aeo-card" style="margin-bottom:8px;">';
						html+='<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;"><strong>'+esc(o.title||'<?php echo esc_js( __( '(untitled)', 'twt-aeo-ultimate' ) ); ?>')+'</strong>';
						if(o.edit_url){ html+='<a href="'+esc(o.edit_url)+'" class="button button-small"><?php echo esc_js( __( 'Edit', 'twt-aeo-ultimate' ) ); ?></a>'; }
						html+='</div>';
						(o.flags||[]).forEach(function(f){
							var s=sev[f.severity]||sev.notice;
							html+='<div style="margin:4px 0;font-size:13px;"><span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:'+s.bg+';color:'+s.c+';">'+esc(f.label)+(f.count>1?' ×'+f.count:'')+'</span> <span style="color:#555;">'+esc(f.detail)+'</span></div>';
						});
						html+='</div>';
					});
					$out.html(html);
				}).fail(function(){ $sp.hide(); $btn.prop('disabled',false); $st.text('✗ <?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>'); });
			});
		}(jQuery));
		<?php
		wp_add_inline_script( 'twt-aeo-command-center', ob_get_clean() );
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
		$cache_key = 'twtaeo_ga4_top_pages_v2';
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
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
				array( 'name' => 'eventCount' ),
				array( 'name' => 'keyEvents' ),
				array( 'name' => 'totalUsers' ),
			),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 10,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pages = array();
		foreach ( $result['rows'] ?? array() as $row ) {
			$page = array(
				'path'     => $row['dimensionValues'][0]['value'] ?? '/',
				'sessions' => $row['metricValues'][0]['value']    ?? 0,
				'views'    => $row['metricValues'][1]['value']    ?? 0,
				'bounce'   => $row['metricValues'][2]['value']    ?? 0,
				'duration' => $row['metricValues'][3]['value']    ?? 0,
				'events'   => $row['metricValues'][4]['value']    ?? 0,
				'key'      => $row['metricValues'][5]['value']    ?? 0,
				'users'    => $row['metricValues'][6]['value']    ?? 0,
			);
			$page['signal'] = self::classify_page_signal( $page );
			$pages[]        = $page;
		}

		set_transient( $cache_key, $pages, HOUR_IN_SECONDS );
		return $pages;
	}

	/**
	 * Interpret a GA4 page row into an actionable signal badge.
	 *
	 * Returns label + CSS modifier so a non-analyst can tell at a glance
	 * whether a page is converting, ignored, getting bot traffic, or needs work.
	 * Heuristics are intentionally conservative — event tagging varies by theme,
	 * so "Likely bots" is a soft hint, not a verdict.
	 *
	 * @param array $page Parsed page metrics.
	 * @return array{label:string,class:string}
	 */
	private static function classify_page_signal( $page ) {
		$sessions = (int) ( $page['sessions'] ?? 0 );
		$bounce   = (float) ( $page['bounce'] ?? 0 );   // 0–1
		$duration = (float) ( $page['duration'] ?? 0 ); // seconds
		$events   = (int) ( $page['events'] ?? 0 );
		$key      = (int) ( $page['key'] ?? 0 );

		$events_per_session = $sessions > 0 ? $events / $sessions : 0;

		// Conversions win — this page is doing its job.
		if ( $key > 0 ) {
			return array(
				'label' => __( 'Converting', 'twt-aeo-ultimate' ),
				'class' => 'twt-aeo-cc__sig--good',
			);
		}

		// Traffic but almost no engagement or events: smells like bots.
		if ( $sessions >= 5 && $events_per_session < 3 && $duration < 5 ) {
			return array(
				'label' => __( 'Likely bots', 'twt-aeo-ultimate' ),
				'class' => 'twt-aeo-cc__sig--bots',
			);
		}

		// High bounce and/or short engagement: real visitors leaving fast.
		if ( $bounce >= 0.9 || ( $bounce >= 0.7 && $duration < 10 ) ) {
			return array(
				'label' => __( 'Needs work', 'twt-aeo-ultimate' ),
				'class' => 'twt-aeo-cc__sig--bad',
			);
		}

		return array(
			'label' => __( 'Healthy', 'twt-aeo-ultimate' ),
			'class' => 'twt-aeo-cc__sig--ok',
		);
	}

	/**
	 * AI Assistant traffic from GA4's native "AI Assistant" channel.
	 *
	 * Uses Google's own channel classification (added 2026-05-13): covers
	 * ChatGPT, Gemini, DeepSeek, Copilot, Grok. Broken down by source so each
	 * tool shows separately. Note the data is forward-only (no history before
	 * 2026-05-13), Perplexity/Claude land in Referral not here, and referrer-less
	 * AI traffic (the majority) is invisible in Direct — see the on-screen note.
	 *
	 * @param string $property_id GA4 numeric property ID.
	 * @return array|WP_Error { sources: array, totals: array }
	 */
	private static function fetch_ga4_ai_traffic( $property_id ) {
		$cache_key = 'twtaeo_ga4_ai_traffic';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$result = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges'      => array( array( 'startDate' => '30daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions'      => array( array( 'name' => 'sessionSource' ) ),
			'metrics'         => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'keyEvents' ),
			),
			'dimensionFilter' => array(
				'filter' => array(
					'fieldName'    => 'sessionDefaultChannelGroup',
					'stringFilter' => array(
						'matchType' => 'EXACT',
						'value'     => 'AI Assistant',
					),
				),
			),
			'orderBys'        => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'           => 25,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sources = array();
		$totals  = array( 'sessions' => 0, 'users' => 0, 'views' => 0, 'key' => 0 );

		foreach ( $result['rows'] ?? array() as $row ) {
			$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$users    = (int) ( $row['metricValues'][1]['value'] ?? 0 );
			$views    = (int) ( $row['metricValues'][2]['value'] ?? 0 );
			$key      = (int) ( $row['metricValues'][3]['value'] ?? 0 );

			$sources[] = array(
				'source'   => $row['dimensionValues'][0]['value'] ?? '(unknown)',
				'sessions' => $sessions,
				'users'    => $users,
				'views'    => $views,
				'key'      => $key,
			);

			$totals['sessions'] += $sessions;
			$totals['users']    += $users;
			$totals['views']    += $views;
			$totals['key']      += $key;
		}

		$data = array( 'sources' => $sources, 'totals' => $totals );
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		return $data;
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
		$cache_key = 'twtaeo_bing_stats_v2';
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

		// Bing's GetQueryStats returns one row per query per date. Aggregate by
		// query so the table shows actual search terms (not raw date rows), and
		// use AvgImpressionPosition — it's meaningful even when a query has no
		// clicks, unlike AvgClickPosition which is 0 and renders as a bogus -0.
		$by_query          = array();
		$total_clicks      = 0;
		$total_impressions = 0;
		$pos_weight_sum    = 0.0; // Σ (impression position × impressions).
		$pos_impr_sum      = 0;   // Σ impressions counted toward position.

		foreach ( $raw_rows as $raw ) {
			$query       = trim( (string) ( $raw['Query'] ?? '' ) );
			$clicks      = (int) ( $raw['Clicks'] ?? 0 );
			$impressions = (int) ( $raw['Impressions'] ?? 0 );
			$impr_pos    = isset( $raw['AvgImpressionPosition'] ) ? (float) $raw['AvgImpressionPosition'] : 0.0;

			if ( $query === '' ) {
				$query = __( '(query not provided)', 'twt-aeo-ultimate' );
			}

			if ( ! isset( $by_query[ $query ] ) ) {
				$by_query[ $query ] = array(
					'query'       => $query,
					'clicks'      => 0,
					'impressions' => 0,
					'pos_weight'  => 0.0,
					'pos_impr'    => 0,
				);
			}

			$by_query[ $query ]['clicks']      += $clicks;
			$by_query[ $query ]['impressions'] += $impressions;

			if ( $impr_pos > 0 && $impressions > 0 ) {
				$by_query[ $query ]['pos_weight'] += $impr_pos * $impressions;
				$by_query[ $query ]['pos_impr']   += $impressions;
				$pos_weight_sum                   += $impr_pos * $impressions;
				$pos_impr_sum                     += $impressions;
			}

			$total_clicks      += $clicks;
			$total_impressions += $impressions;
		}

		$rows = array();
		foreach ( $by_query as $q ) {
			$rows[] = array(
				'query'       => $q['query'],
				'clicks'      => $q['clicks'],
				'impressions' => $q['impressions'],
				'ctr'         => $q['impressions'] > 0 ? $q['clicks'] / $q['impressions'] : 0.0,
				'position'    => $q['pos_impr'] > 0 ? $q['pos_weight'] / $q['pos_impr'] : null,
			);
		}

		// Most-seen queries first.
		usort( $rows, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		$stats = array(
			'total_clicks'      => $total_clicks,
			'total_impressions' => $total_impressions,
			'avg_position'      => $pos_impr_sum > 0 ? $pos_weight_sum / $pos_impr_sum : null,
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

	/**
	 * "N days ago" under a Last Crawl date — amber in the watch range, red in
	 * the risk range (indexed pages only; not-indexed rows are already red).
	 *
	 * @param array $gsc Stored GSC result row.
	 */
	private static function render_crawl_age( array $gsc ) {
		$age = TWTAEO_Index_Status::crawl_age_days( $gsc );
		if ( null === $age ) {
			return;
		}
		$risk  = TWTAEO_Index_Status::crawl_risk( $gsc );
		$style = 'risk' === $risk ? 'color:#b91c1c;font-weight:600;' : ( 'watch' === $risk ? 'color:#92400e;font-weight:600;' : '' );
		$tip   = 'risk' === $risk
			? __( 'Google hasn\'t crawled this indexed page in over 130 days. Pages this stale are much more likely to be dropped from the index.', 'twt-aeo-ultimate' )
			: ( 'watch' === $risk ? __( 'Getting close to the ~130-day mark where uncrawled pages start dropping from Google\'s index.', 'twt-aeo-ultimate' ) : '' );
		?>
		<span style="display:block;font-size:11px;<?php echo esc_attr( $style ); ?>" title="<?php echo esc_attr( $tip ); ?>">
			<?php
			/* translators: %d: number of days. */
			echo esc_html( sprintf( _n( '%d day ago', '%d days ago', $age, 'twt-aeo-ultimate' ), $age ) );
			?>
		</span>
		<?php
	}

	private static function render_index_status_tab() {
		$connected = TWTAEO_Google_OAuth::is_connected();
		$config    = TWTAEO_Google_OAuth::get_config();
		$site_url  = $config['gsc_site_url'] ?? '';
		$nonce     = wp_create_nonce( TWTAEO_Index_Status::NONCE );
		$results   = TWTAEO_Index_Status::get_results();
		// Inspect ALL published pages — Google's API has no bulk "not indexed" list,
		// so problem pages are only discovered by inspecting each one. We then show
		// just the ones that need work (below). Cap keeps quota/time sane.
		$scan_urls = TWTAEO_Index_Status::get_scannable_urls( 500 );
		// The daily rolling scan reaches older pages past that cap — list every
		// page that has a stored result so its crawl age is visible here too.
		$listed_ids = array_map( 'intval', wp_list_pluck( $scan_urls, 'post_id' ) );
		$extra_ids  = array_diff( array_map( 'intval', array_keys( $results ) ), $listed_ids );
		if ( $extra_ids ) {
			$scan_urls = array_merge( $scan_urls, TWTAEO_Index_Status::get_urls_for_posts( $extra_ids ) );
		}
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

		// Display view: 'work' (default) shows only pages that need attention;
		// 'all' shows every scanned page. Benign read-only GET filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter; changes nothing, so no nonce needed.
		$view = ( isset( $_GET['twtaeo_index_view'] ) && 'all' === sanitize_key( wp_unslash( $_GET['twtaeo_index_view'] ) ) ) ? 'all' : 'work';

		// Tally from stored results.
		$not_indexed = 0;
		$indexed     = 0;
		$needs_work  = 0;
		$stale_crawl = 0;
		foreach ( $results as $r ) {
			$gsc = $r['gsc'] ?? null;
			if ( ! $gsc ) {
				continue;
			}
			if ( ! empty( $gsc['not_indexed'] ) ) {
				$not_indexed++;
			} else {
				$indexed++;
			}
			$crawl_risk = TWTAEO_Index_Status::crawl_risk( $gsc );
			if ( '' !== $crawl_risk ) {
				$stale_crawl++;
			}
			if ( ! empty( $gsc['not_indexed'] ) || ! empty( $r['heuristics']['flags'] ) || '' !== $crawl_risk ) {
				$needs_work++;
			}
		}
		$total_scanned = $indexed + $not_indexed;

		// Whether a scanned page needs work (not indexed, or has heuristic flags).
		$is_work = static function ( $item ) use ( $results ) {
			$r   = $results[ $item['post_id'] ] ?? null;
			$gsc = $r['gsc'] ?? null;
			if ( ! $gsc ) {
				return false;
			}
			return ! empty( $gsc['not_indexed'] ) || ! empty( $r['heuristics']['flags'] )
				|| '' !== TWTAEO_Index_Status::crawl_risk( $gsc );
		};

		// Worst-first ranking, shared by the scan queue and the display sort.
		$rank = static function ( $item ) use ( $results ) {
			$r     = $results[ $item['post_id'] ] ?? null;
			$gsc   = $r['gsc'] ?? null;
			$flags = $r['heuristics']['flags'] ?? array();
			if ( ! $gsc ) {
				return 3; // Not scanned yet — unknown.
			}
			if ( ! empty( $gsc['not_indexed'] ) ) {
				return 0; // Worst: indexed for nobody.
			}
			$severities = wp_list_pluck( $flags, 'severity' );
			$crawl_risk = TWTAEO_Index_Status::crawl_risk( $gsc );
			if ( in_array( 'critical', $severities, true ) || 'risk' === $crawl_risk ) {
				return 1; // Includes "Google hasn't crawled this in 130+ days".
			}
			if ( in_array( 'warning', $severities, true ) || 'watch' === $crawl_risk ) {
				return 2;
			}
			return 4; // Indexed and clean — bottom.
		};

		$sort_worst_first = static function ( &$list ) use ( $rank, $results ) {
			usort( $list, static function ( $a, $b ) use ( $rank, $results ) {
				$ra = $rank( $a );
				$rb = $rank( $b );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				$fa = count( $results[ $a['post_id'] ]['heuristics']['flags'] ?? array() );
				$fb = count( $results[ $b['post_id'] ]['heuristics']['flags'] ?? array() );
				if ( $fa !== $fb ) {
					return $fb <=> $fa;
				}
				return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
			} );
		};

		// Scan queue: inspect known-problem + never-scanned pages first so the
		// most valuable inspections happen early and discover the not-indexed set.
		$sort_worst_first( $scan_urls );

		// Display list: in 'work' view show only fixable pages. Both views paginate.
		$display = $scan_urls;
		if ( 'work' === $view ) {
			$display = array_values( array_filter( $scan_urls, $is_work ) );
		}
		$sort_worst_first( $display );

		$per_page      = 50;
		$display_total = count( $display );
		$total_pages   = max( 1, (int) ceil( $display_total / $per_page ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination index; changes nothing, so no nonce needed.
		$paged         = isset( $_GET['twtaeo_index_paged'] ) ? max( 1, absint( wp_unslash( $_GET['twtaeo_index_paged'] ) ) ) : 1;
		$paged         = min( $paged, $total_pages );
		$range_start   = $display_total ? ( ( $paged - 1 ) * $per_page + 1 ) : 0;
		$display       = array_slice( $display, ( $paged - 1 ) * $per_page, $per_page );
		$range_end     = ( $paged - 1 ) * $per_page + count( $display );

		$base_url = add_query_arg(
			array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'index-status' ),
			admin_url( 'admin.php' )
		);
		// Pagination links keep the current view; page links add the paged arg.
		$view_url = add_query_arg( 'twtaeo_index_view', $view, $base_url );
		?>

		<?php /* ── View toggle + summary line ── */ ?>
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
			<div style="font-size:13px;color:#50575e;">
				<?php
				if ( 'work' === $view ) {
					printf(
						/* translators: 1: range start, 2: range end, 3: total needing work. */
						esc_html__( 'Showing %1$d–%2$d of %3$d pages that need work.', 'twt-aeo-ultimate' ),
						absint( $range_start ),
						absint( $range_end ),
						absint( $display_total )
					);
				} else {
					printf(
						/* translators: 1: range start, 2: range end, 3: total scanned. */
						esc_html__( 'Showing %1$d–%2$d of %3$d scanned pages.', 'twt-aeo-ultimate' ),
						absint( $range_start ),
						absint( $range_end ),
						absint( $display_total )
					);
				}
				?>
			</div>
			<span style="margin-left:auto;display:inline-flex;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;font-size:12px;">
				<a href="<?php echo esc_url( add_query_arg( 'twtaeo_index_view', 'work', $base_url ) ); ?>"
				   style="padding:5px 12px;text-decoration:none;<?php echo 'work' === $view ? 'background:#2271b1;color:#fff;' : 'background:#fff;color:#2271b1;'; ?>">
					<?php esc_html_e( 'Needs work', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'twtaeo_index_view', 'all', $base_url ) ); ?>"
				   style="padding:5px 12px;text-decoration:none;border-left:1px solid #c3c4c7;<?php echo 'all' === $view ? 'background:#2271b1;color:#fff;' : 'background:#fff;color:#2271b1;'; ?>">
					<?php esc_html_e( 'All scanned', 'twt-aeo-ultimate' ); ?>
				</a>
			</span>
		</div>


		<?php /* ── Summary Bar ── */ ?>
		<div class="twt-aeo-cc__metric-row" style="margin-bottom:24px;gap:16px;flex-wrap:wrap;">
			<div class="twt-aeo-cc__metric" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;"><?php echo esc_html( count( $scan_urls ) ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Total Pages', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fff7ed;border:1px solid #fdba74;border-radius:6px;padding:16px 24px;min-width:120px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#9a3412;"><?php echo esc_html( $needs_work ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Need Work', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#edfaef;border:1px solid #5ec980;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#1a6629;"><?php echo esc_html( $indexed ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Indexed', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#b91c1c;"><?php echo esc_html( $not_indexed ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Not Indexed', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<div class="twt-aeo-cc__metric" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;"
				title="<?php
				/* translators: %d: number of days. */
				echo esc_attr( sprintf( __( 'Indexed pages Google hasn\'t crawled in %d+ days. Pages left uncrawled for about 130 days are much more likely to drop out of the index.', 'twt-aeo-ultimate' ), TWTAEO_Index_Status::CRAWL_WATCH_DAYS ) ); ?>">
				<span class="twt-aeo-cc__metric-value" style="font-size:28px;color:#92400e;"><?php echo esc_html( $stale_crawl ); ?></span>
				<span class="twt-aeo-cc__metric-label"><?php esc_html_e( 'Crawl Going Stale', 'twt-aeo-ultimate' ); ?></span>
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
		<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#1e3a8a;line-height:1.5;">
			<strong><?php esc_html_e( 'Why isn\'t a page indexed?', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'A meta description alone won\'t get a page indexed — it only affects the snippet Google shows. The most common reasons a page stays out of the index are: a "noindex" setting, a canonical URL pointing to a different page, the page missing from your XML sitemap, thin or duplicate content, or no internal links pointing to it. Fix the red Critical items first, then the Warnings.', 'twt-aeo-ultimate' ); ?>
			<br><strong><?php esc_html_e( 'Watch the crawl age too.', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'An indexed page Google hasn\'t crawled in about 130 days is much more likely to be dropped, and after about 190 days Google tends to forget the URL entirely. Infrequent crawling usually means Google sees the page as low priority — link to it from your stronger pages, keep it in your sitemap with an accurate last-modified date, and give it a genuine update (or merge it into a stronger page).', 'twt-aeo-ultimate' ); ?>
		</div>

		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden;">
			<table class="widefat" style="border:none;">
				<thead>
					<tr style="background:#f9fafb;">
						<th style="width:36%;"><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:20%;"><?php esc_html_e( 'Index Status', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:14%;"><?php esc_html_e( 'Last Crawl', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Issues & Fixes', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody id="twt-aeo-index-tbody">
				<?php if ( empty( $display ) ) : ?>
					<tr><td colspan="4" style="padding:18px;text-align:center;color:#646970;font-size:13px;">
						<?php
						if ( ! $total_scanned ) {
							esc_html_e( 'No pages scanned yet. Click “Run Index Scan” to check your pages against Google.', 'twt-aeo-ultimate' );
						} elseif ( 'work' === $view ) {
							esc_html_e( '🎉 No pages need work — every scanned page is indexed and issue-free.', 'twt-aeo-ultimate' );
						} else {
							esc_html_e( 'No scanned pages to show.', 'twt-aeo-ultimate' );
						}
						?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $display as $item ) :
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
								self::render_crawl_age( $gsc );
							} else {
								echo '—';
							}
							?>
						</td>
						<td class="twt-aeo-issues-cell" style="padding:10px 12px;font-size:12px;">
							<?php
							$crawl_risk = TWTAEO_Index_Status::crawl_risk( $gsc );
							if ( '' !== $crawl_risk ) :
								?>
								<span style="display:inline-block;background:<?php echo esc_attr( 'risk' === $crawl_risk ? '#fee2e2' : '#fef3c7' ); ?>;color:<?php echo esc_attr( 'risk' === $crawl_risk ? '#b91c1c' : '#92400e' ); ?>;border-radius:10px;padding:2px 8px;font-size:11px;margin:2px 2px 2px 0;"
									title="<?php esc_attr_e( 'Add links to this page from your stronger pages, make sure it is in your XML sitemap with an accurate last-modified date, and give it a genuine content update — or merge it into a stronger page.', 'twt-aeo-ultimate' ); ?>">
									<?php echo 'risk' === $crawl_risk ? esc_html__( 'Google stopped crawling', 'twt-aeo-ultimate' ) : esc_html__( 'Crawl going stale', 'twt-aeo-ultimate' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( ! $gsc ) : ?>
								<span style="color:#999;">—</span>
							<?php elseif ( empty( $flags ) && '' !== $crawl_risk ) : ?>
								<?php /* Crawl badge above is the only issue. */ ?>
							<?php elseif ( empty( $flags ) ) : ?>
								<span style="color:#1a6629;">&#10003; <?php esc_html_e( 'No issues', 'twt-aeo-ultimate' ); ?></span>
							<?php elseif ( $is_bad ) : ?>
								<?php /* Not-indexed: detailed list with inline fixes. */ ?>
								<div style="padding:8px;background:#fff3cd;border-radius:4px;border:1px solid #fde68a;">
									<?php foreach ( $flags as $flag ) :
										$sev   = $flag['severity'] ?? 'warning';
										$color = 'critical' === $sev ? '#b91c1c' : ( 'notice' === $sev ? '#1e40af' : '#92400e' );
										?>
										<div style="margin-bottom:6px;">
											<strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $flag['label'] ); ?>:</strong>
											<?php echo esc_html( $flag['detail'] ); ?>
											<?php if ( ! empty( $flag['fix'] ) ) : ?>
												<button type="button" class="button button-small twt-aeo-fix-btn"
													data-fix="<?php echo esc_attr( $flag['fix'] ); ?>"
													data-post="<?php echo esc_attr( $item['post_id'] ); ?>"
													data-title="<?php echo esc_attr( $item['title'] ); ?>"
													<?php if ( 'og' === $flag['fix'] && ! empty( $flag['og'] ) ) : ?>
													data-og-title="<?php echo esc_attr( $flag['og']['title'] ?? '' ); ?>"
													data-og-desc="<?php echo esc_attr( $flag['og']['description'] ?? '' ); ?>"
													data-og-image="<?php echo esc_attr( $flag['og']['image'] ?? '' ); ?>"
													data-og-type="<?php echo esc_attr( $flag['og']['type'] ?? 'website' ); ?>"
													<?php endif; ?>
													style="margin-left:4px;vertical-align:baseline;"><?php esc_html_e( 'Fix →', 'twt-aeo-ultimate' ); ?></button>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
									<?php if ( ! empty( $item['edit_url'] ) ) : ?>
									<div style="margin-top:6px;">
										<a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="button button-small">
											<?php esc_html_e( 'Edit page', 'twt-aeo-ultimate' ); ?>
										</a>
									</div>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<?php /* Indexed but flagged: compact badges. */ ?>
								<?php foreach ( $flags as $flag ) :
									$sev = $flag['severity'] ?? 'warning';
									$bg  = 'critical' === $sev ? '#fee2e2' : ( 'notice' === $sev ? '#dbeafe' : '#fef3c7' );
									$fg  = 'critical' === $sev ? '#b91c1c' : ( 'notice' === $sev ? '#1e40af' : '#92400e' );
									?>
									<span style="display:inline-block;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;border-radius:10px;padding:2px 8px;font-size:11px;margin:2px 2px 2px 0;">
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

		<?php /* ── Pagination ── */ ?>
		<?php if ( $total_pages > 1 ) : ?>
		<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:14px;flex-wrap:wrap;">
			<?php
			$prev_disabled = $paged <= 1;
			$next_disabled = $paged >= $total_pages;
			?>
			<a href="<?php echo esc_url( add_query_arg( 'twtaeo_index_paged', max( 1, $paged - 1 ), $view_url ) ); ?>"
			   class="button button-small" <?php echo $prev_disabled ? 'aria-disabled="true" style="pointer-events:none;opacity:.5;"' : ''; ?>>
				&laquo; <?php esc_html_e( 'Prev', 'twt-aeo-ultimate' ); ?>
			</a>
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
				<?php if ( $p === $paged ) : ?>
					<span class="button button-small button-primary" aria-current="page" style="pointer-events:none;"><?php echo esc_html( $p ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'twtaeo_index_paged', $p, $view_url ) ); ?>" class="button button-small"><?php echo esc_html( $p ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>
			<a href="<?php echo esc_url( add_query_arg( 'twtaeo_index_paged', min( $total_pages, $paged + 1 ), $view_url ) ); ?>"
			   class="button button-small" <?php echo $next_disabled ? 'aria-disabled="true" style="pointer-events:none;opacity:.5;"' : ''; ?>>
				<?php esc_html_e( 'Next', 'twt-aeo-ultimate' ); ?> &raquo;
			</a>
		</div>
		<?php endif; ?>

		<?php /* ── Inline Fix Modal ── */ ?>
		<div id="twt-aeo-fix-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);">
			<div style="background:#fff;max-width:560px;margin:6vh auto;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.25);max-height:88vh;overflow:auto;">
				<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
					<h2 id="twt-aeo-fix-title" style="margin:0;font-size:16px;"></h2>
					<button type="button" id="twt-aeo-fix-close" class="button-link" style="font-size:22px;line-height:1;color:#646970;text-decoration:none;cursor:pointer;" aria-label="<?php esc_attr_e( 'Close', 'twt-aeo-ultimate' ); ?>">&times;</button>
				</div>
				<div id="twt-aeo-fix-body" style="padding:20px;"></div>
			</div>
		</div>

		<?php /* ── Quota Note ── */ ?>
		<p style="font-size:12px;color:#999;margin-top:12px;">
			<?php esc_html_e( 'Scanning inspects every published page via the Google Search Console URL Inspection API (one request per page; 2,000/day quota), then shows the pages that need work. Google has no bulk index-status export, so each page must be inspected individually. Results are stored until you re-scan. A daily background check also re-inspects up to 100 pages, oldest-checked first, so crawl ages across the whole site stay current without a manual scan.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php /* ── Inline JS ── */ ?>
		<?php
		ob_start();
		?>
		(function(){
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var siteUrl  = <?php echo wp_json_encode( $site_url ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var urls     = <?php echo wp_json_encode( array_values( $scan_urls ) ); ?>;
			var sgNonce  = <?php echo wp_json_encode( wp_create_nonce( 'twtaeo_social_graph_nonce' ) ); ?>;
			var aiNonce  = <?php echo wp_json_encode( wp_create_nonce( TWTAEO_AI_Description::NONCE ) ); ?>;
			var faqNonce = <?php echo wp_json_encode( wp_create_nonce( TWTAEO_FAQ_Detector::NONCE_GENERATE ) ); ?>;

				// post_id -> edit URL, for rebuilding the advice block after a scan.
				var editMap = {};
				urls.forEach(function(u){ editMap[u.post_id] = u.edit_url; });

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

				// Last crawl (index 2) — date plus age, coloured past the thresholds.
				var crawlCell = row.cells[2];
				crawlCell.textContent = gsc.last_crawl ? gsc.last_crawl.substring(0,10) : '—';
				var crawlTs = gsc.last_crawl ? Date.parse(gsc.last_crawl) : NaN;
				if (!isNaN(crawlTs)) {
					var days = Math.max(0, Math.floor((Date.now() - crawlTs) / 86400000));
					var age  = document.createElement('span');
					age.style.display  = 'block';
					age.style.fontSize = '11px';
					if (!gsc.not_indexed && days >= <?php echo (int) TWTAEO_Index_Status::CRAWL_RISK_DAYS; ?>) {
						age.style.color = '#b91c1c'; age.style.fontWeight = '600';
					} else if (!gsc.not_indexed && days >= <?php echo (int) TWTAEO_Index_Status::CRAWL_WATCH_DAYS; ?>) {
						age.style.color = '#92400e'; age.style.fontWeight = '600';
					}
					age.textContent = <?php /* translators: %d: number of days. */ echo wp_json_encode( __( '%d days ago', 'twt-aeo-ultimate' ) ); ?>.split('%d').join(days);
					crawlCell.appendChild(age);
				}

				// Issues & Fixes (index 3) — same structure the server renders.
				var titleLink = row.cells[0].querySelector('a');
				row.cells[3].innerHTML = buildIssuesHtml(
					flags,
					!!gsc.not_indexed,
					data.post_id,
					titleLink ? titleLink.textContent.trim() : '',
					editMap[data.post_id]
				);
			}

			function esc(s){
				return String(s == null ? '' : s)
					.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			function buildIssuesHtml(flags, notIndexed, postId, title, editUrl){
				if (!flags.length) {
					return '<span style="color:#1a6629;">&#10003; ' + <?php echo wp_json_encode( __( 'No issues', 'twt-aeo-ultimate' ) ); ?> + '</span>';
				}
				if (!notIndexed) {
					return flags.map(function(f){
						var bg = f.severity === 'critical' ? '#fee2e2' : (f.severity === 'notice' ? '#dbeafe' : '#fef3c7');
						var fg = f.severity === 'critical' ? '#b91c1c' : (f.severity === 'notice' ? '#1e40af' : '#92400e');
						return '<span style="display:inline-block;background:' + bg + ';color:' + fg + ';border-radius:10px;padding:2px 8px;font-size:11px;margin:2px 2px 2px 0;">' + esc(f.label) + '</span>';
					}).join('');
				}
				var inner = flags.map(function(f){
					var color = f.severity === 'critical' ? '#b91c1c' : (f.severity === 'notice' ? '#1e40af' : '#92400e');
					var btn = '';
					if (f.fix) {
						var og = f.og || {};
						var ogAttrs = f.fix === 'og'
							? ' data-og-title="' + esc(og.title) + '" data-og-desc="' + esc(og.description) + '" data-og-image="' + esc(og.image) + '" data-og-type="' + esc(og.type || 'website') + '"'
							: '';
						btn = ' <button type="button" class="button button-small twt-aeo-fix-btn" data-fix="' + esc(f.fix) + '" data-post="' + esc(postId) + '" data-title="' + esc(title) + '"' + ogAttrs + ' style="margin-left:4px;vertical-align:baseline;">' + <?php echo wp_json_encode( __( 'Fix →', 'twt-aeo-ultimate' ) ); ?> + '</button>';
					}
					return '<div style="margin-bottom:6px;"><strong style="color:' + color + ';">' + esc(f.label) + ':</strong> ' + esc(f.detail) + btn + '</div>';
				}).join('');
				var edit = editUrl
					? '<div style="margin-top:6px;"><a href="' + esc(editUrl) + '" class="button button-small">' + <?php echo wp_json_encode( __( 'Edit page', 'twt-aeo-ultimate' ) ); ?> + '</a></div>'
					: '';
				return '<div style="padding:8px;background:#fff3cd;border-radius:4px;border:1px solid #fde68a;">' + inner + edit + '</div>';
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
							sendBtn.textContent = <?php /* translators: %d: number of URLs submitted. */ echo wp_json_encode( __( 'URLs sent: %d ✓', 'twt-aeo-ultimate' ) ); ?>.split('%d').join(res.data.count);
						} else {
							sendBtn.textContent = res.data || 'Error';
							sendBtn.disabled = false;
						}
					});
			});
			// ── Inline Fix Modal ──────────────────────────────────────────
			var fixModal = document.getElementById('twt-aeo-fix-modal');
			var fixTitle = document.getElementById('twt-aeo-fix-title');
			var fixBody  = document.getElementById('twt-aeo-fix-body');

			function openModal(heading){ fixTitle.textContent = heading; fixModal.style.display = 'block'; }
			function closeFix(){ fixModal.style.display = 'none'; fixBody.innerHTML = ''; }
			document.getElementById('twt-aeo-fix-close').addEventListener('click', closeFix);
			fixModal.addEventListener('click', function(e){ if (e.target === fixModal) closeFix(); });
			document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && fixModal.style.display === 'block') closeFix(); });

			// Delegate Fix-button clicks so buttons rebuilt after a scan still work.
			document.getElementById('twt-aeo-index-tbody').addEventListener('click', function(e){
				var btn = e.target.closest('.twt-aeo-fix-btn');
				if (!btn) return;
				e.preventDefault();
				var fix = btn.getAttribute('data-fix');
				if (fix === 'og')  openOg(btn);
				if (fix === 'faq') openFaq(btn);
			});

			function markFixed(btn){
				var item = btn.closest('div');
				if (item) item.style.opacity = '0.55';
				btn.textContent = <?php echo wp_json_encode( __( 'Fixed ✓', 'twt-aeo-ultimate' ) ); ?>;
				btn.disabled = true;
			}

			function openOg(btn){
				var post  = btn.getAttribute('data-post');
				var title = btn.getAttribute('data-title') || '';
				openModal(<?php /* translators: %s: page title. */ echo wp_json_encode( __( 'Fix: Open Graph — %s', 'twt-aeo-ultimate' ) ); ?>.split('%s').join(title));
				var t    = btn.getAttribute('data-og-title') || '';
				var d    = btn.getAttribute('data-og-desc')  || '';
				var img  = btn.getAttribute('data-og-image') || '';
				var type = btn.getAttribute('data-og-type')  || 'website';
				fixBody.innerHTML =
					'<label style="display:block;font-weight:600;margin-bottom:4px;">' + <?php echo wp_json_encode( __( 'OG Title', 'twt-aeo-ultimate' ) ); ?> + '</label>' +
					'<input type="text" id="twt-og-title" class="widefat" value="' + esc(t) + '" style="margin-bottom:12px;">' +
					'<label style="display:block;font-weight:600;margin-bottom:4px;">' + <?php echo wp_json_encode( __( 'OG Description', 'twt-aeo-ultimate' ) ); ?> + '</label>' +
					'<textarea id="twt-og-desc" class="widefat" rows="3" style="margin-bottom:6px;">' + esc(d) + '</textarea>' +
					'<button type="button" class="button button-small" id="twt-og-gen-desc">' + <?php echo wp_json_encode( __( 'Generate with AI', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
					'<label style="display:block;font-weight:600;margin:12px 0 4px;">' + <?php echo wp_json_encode( __( 'OG Image', 'twt-aeo-ultimate' ) ); ?> + '</label>' +
					'<input type="text" id="twt-og-image" class="widefat" value="' + esc(img) + '" style="margin-bottom:6px;">' +
					'<div id="twt-og-preview" style="margin-bottom:6px;">' + (img ? '<img src="' + esc(img) + '" style="max-width:100%;border-radius:4px;border:1px solid #e5e7eb;">' : '') + '</div>' +
					'<button type="button" class="button button-small" id="twt-og-gen-img">' + <?php echo wp_json_encode( __( 'Generate image with AI', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
					'<input type="hidden" id="twt-og-type" value="' + esc(type) + '">' +
					'<div style="margin-top:18px;display:flex;gap:8px;align-items:center;">' +
						'<button type="button" class="button button-primary" id="twt-og-save">' + <?php echo wp_json_encode( __( 'Save', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
						'<span id="twt-og-msg" style="color:#646970;"></span>' +
					'</div>';

				var msg = document.getElementById('twt-og-msg');

				document.getElementById('twt-og-gen-desc').addEventListener('click', function(){
					var b = this, o = b.textContent; b.disabled = true; b.textContent = '…'; msg.textContent = '';
					var fd = new FormData();
					fd.append('action', 'twtaeo_ai_desc_social'); fd.append('nonce', aiNonce); fd.append('post_id', post);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success && res.data && res.data.description) { document.getElementById('twt-og-desc').value = res.data.description; }
						else { msg.textContent = (res.data || 'Error'); }
					}).catch(function(){ msg.textContent = 'Network error'; }).finally(function(){ b.disabled = false; b.textContent = o; });
				});

				document.getElementById('twt-og-gen-img').addEventListener('click', function(){
					var b = this, o = b.textContent; b.disabled = true; b.textContent = <?php echo wp_json_encode( __( 'Generating… (~20s)', 'twt-aeo-ultimate' ) ); ?>; msg.textContent = '';
					var fd = new FormData();
					fd.append('action', 'twtaeo_ai_og_image'); fd.append('nonce', aiNonce); fd.append('post_id', post);
					fd.append('style', 'clean'); fd.append('use_title', '1');
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success && res.data && res.data.url) {
							document.getElementById('twt-og-image').value = res.data.url;
							document.getElementById('twt-og-preview').innerHTML = '<img src="' + esc(res.data.url) + '" style="max-width:100%;border-radius:4px;border:1px solid #e5e7eb;">';
						} else { msg.textContent = (res.data || 'Error'); }
					}).catch(function(){ msg.textContent = 'Network error'; }).finally(function(){ b.disabled = false; b.textContent = o; });
				});

				document.getElementById('twt-og-save').addEventListener('click', function(){
					var b = this; b.disabled = true; msg.textContent = <?php echo wp_json_encode( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>;
					var fd = new FormData();
					fd.append('action', 'twtaeo_save_social_graph'); fd.append('nonce', sgNonce); fd.append('post_id', post);
					fd.append('og_title', document.getElementById('twt-og-title').value);
					fd.append('og_description', document.getElementById('twt-og-desc').value);
					fd.append('og_image', document.getElementById('twt-og-image').value);
					fd.append('og_type', document.getElementById('twt-og-type').value);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success) { msg.textContent = <?php echo wp_json_encode( __( 'Saved ✓', 'twt-aeo-ultimate' ) ); ?>; markFixed(btn); setTimeout(closeFix, 800); }
						else { msg.textContent = (res.data || 'Error'); b.disabled = false; }
					}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
				});
			}

			function openFaq(btn){
				var post  = btn.getAttribute('data-post');
				var title = btn.getAttribute('data-title') || '';
				openModal(<?php /* translators: %s: page title. */ echo wp_json_encode( __( 'Fix: FAQ schema — %s', 'twt-aeo-ultimate' ) ); ?>.split('%s').join(title));
				fixBody.innerHTML =
					'<p style="margin-top:0;">' + <?php echo wp_json_encode( __( 'Generate FAQPage schema from the Q&A-style content already on this page. This adds structured data — it does not change your content.', 'twt-aeo-ultimate' ) ); ?> + '</p>' +
					'<div style="display:flex;gap:8px;align-items:center;">' +
						'<button type="button" class="button button-primary" id="twt-faq-gen">' + <?php echo wp_json_encode( __( 'Generate FAQ schema', 'twt-aeo-ultimate' ) ); ?> + '</button>' +
						'<span id="twt-faq-msg" style="color:#646970;"></span>' +
					'</div>';
				document.getElementById('twt-faq-gen').addEventListener('click', function(){
					var b = this; b.disabled = true;
					var msg = document.getElementById('twt-faq-msg'); msg.textContent = <?php echo wp_json_encode( __( 'Generating…', 'twt-aeo-ultimate' ) ); ?>;
					var fd = new FormData();
					fd.append('action', 'twtaeo_faq_generate_one'); fd.append('nonce', faqNonce); fd.append('post_id', post);
					fetch(ajaxUrl, { method:'POST', body:fd }).then(function(r){ return r.json(); }).then(function(res){
						if (res.success) {
							msg.textContent = (res.data && res.data.qa_count ? res.data.qa_count + ' ' : '') + <?php echo wp_json_encode( __( 'Q&A pairs added ✓', 'twt-aeo-ultimate' ) ); ?>;
							markFixed(btn);
						} else {
							msg.textContent = ((res.data && res.data.message) || res.data || 'Error'); b.disabled = false;
						}
					}).catch(function(){ msg.textContent = 'Network error'; b.disabled = false; });
				});
			}
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
				'format'   => ( isset( $entry['format'] ) && 'markdown' === $entry['format'] ) ? 'markdown' : 'html',
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
				<?php
				$robots_is_static = class_exists( 'TWTAEO_AI_Ready' ) && TWTAEO_AI_Ready::has_physical_robots();
				foreach ( $file_hits as $slug => $data ) :
					$meta        = $file_meta[ $slug ];
					$untracked   = ( 'robots.txt' === $slug && $robots_is_static );
					$has_hits    = $data['hits'] > 0;
					$dot_color   = $untracked ? '#f59e0b' : ( $has_hits ? '#10b981' : '#d1d5db' );
					$count_color = $has_hits ? '#1d2327' : '#9ca3af';
				?>
				<div style="border:1px solid #e5e7eb;border-radius:6px;padding:16px 18px;position:relative;">

					<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
						<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $dot_color ); ?>;flex-shrink:0;"></span>
						<code style="font-size:12px;font-weight:600;color:#1d2327;"><?php echo esc_html( $meta['label'] ); ?></code>
					</div>

					<div style="font-size:30px;font-weight:700;line-height:1;color:<?php echo esc_attr( $untracked ? '#9ca3af' : $count_color ); ?>;margin-bottom:2px;">
						<?php echo $untracked ? '&mdash;' : esc_html( number_format_i18n( $data['hits'] ) ); ?>
					</div>
					<div style="font-size:11px;color:#9ca3af;margin-bottom:10px;">
						<?php esc_html_e( 'bot crawls', 'twt-aeo-ultimate' ); ?>
					</div>

					<div style="font-size:11px;color:#6b7280;margin-bottom:8px;line-height:1.4;">
						<?php echo esc_html( $meta['desc'] ); ?>
					</div>

					<div style="font-size:11px;border-top:1px solid #f3f4f6;padding-top:8px;display:flex;justify-content:space-between;align-items:center;gap:4px;flex-wrap:wrap;">
						<span style="color:#9ca3af;">
							<?php if ( $untracked ) : ?>
								<em title="<?php esc_attr_e( 'A physical robots.txt on disk is served by the web server without running PHP, so crawler hits to it cannot be counted.', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Static file — not tracked', 'twt-aeo-ultimate' ); ?></em>
							<?php elseif ( $data['last'] ) : ?>
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
				<?php esc_html_e( 'Note: hits are only recorded when WordPress handles the request in PHP. A physical robots.txt on disk, or a full-page cache in front of WordPress (a host page cache such as Nexcess NxAccel, or a CDN like Cloudflare), serves these files without invoking PHP — so those hits are not captured and the counts may understate real crawler activity. The plugin sends no-cache headers on llms.txt and llms-full.txt to reduce this, though some host caches ignore them.', 'twt-aeo-ultimate' ); ?>
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
					<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No AI crawlers detected yet. Logs will appear here as AI bots visit your site.', 'twt-aeo-ultimate' ); ?></p>
					<p style="margin:8px 0 0;font-size:12px;color:#999;"><?php esc_html_e( 'Monitoring: OpenAI GPTBot, ClaudeBot, Google-Extended, PerplexityBot, and 20+ more.', 'twt-aeo-ultimate' ); ?></p>
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
								<?php if ( isset( $entry['format'] ) && 'markdown' === $entry['format'] ) : ?>
									<span style="font-size:10px;font-weight:600;color:#7c3aed;background:rgba(124,58,237,.1);border-radius:3px;padding:1px 5px;margin-left:6px;"><?php esc_html_e( 'Markdown', 'twt-aeo-ultimate' ); ?></span>
								<?php endif; ?>
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
		<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 18px;border-radius:0 4px 4px 0;font-size:13px;">
			<strong><?php esc_html_e( 'What AI Crawler Watch monitors:', 'twt-aeo-ultimate' ); ?></strong>
			<?php esc_html_e( 'OpenAI GPTBot &amp; ChatGPT-User · Anthropic ClaudeBot · Google-Extended · PerplexityBot · Meta FacebookBot · Apple Applebot-Extended · ByteDance Bytespider · Amazon Amazonbot · Diffbot · Cohere · Common Crawl CCBot · You.com YouBot · and more.', 'twt-aeo-ultimate' ); ?>
			<br><span style="color:#646970;"><?php esc_html_e( 'Logs are stored locally for 30 days.', 'twt-aeo-ultimate' ); ?></span>
		</div>
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
			'save_google_creds'   => self::NONCE_SAVE_GOOGLE,
			'save_google_config'  => self::NONCE_SAVE_GOOGLE,
			'save_google_sa'      => self::NONCE_SAVE_GOOGLE,
			'remove_google_sa'    => self::NONCE_SAVE_GOOGLE,
			'save_google_api_key' => self::NONCE_SAVE_GOOGLE,
			'save_bing_creds'     => self::NONCE_SAVE_BING,
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
				$gsc = TWTAEO_Google_OAuth::save_config(
					sanitize_text_field( wp_unslash( $_POST['ga4_property_id'] ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['gsc_site_url']    ?? '' ) )
				);
				// Bust data cache when config changes.
				self::clear_data_cache();
				// A property the account cannot see is saved, but flagged: Google will refuse it.
				self::set_notice(
					$gsc['refused'] ? 'error' : 'updated',
					trim( __( 'Google configuration saved.', 'twt-aeo-ultimate' ) . ' ' . $gsc['note'] )
				);
				break;

			case 'save_google_sa':
				if ( ! check_admin_referer( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				// Raw JSON — sanitizing would corrupt the embedded private key;
				// save_key() validates structure and the key itself before storing.
				$result = TWTAEO_Google_Service_Account::save_key( wp_unslash( $_POST['sa_key_json'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( is_wp_error( $result ) ) {
					self::set_notice( 'error', $result->get_error_message() );
				} else {
					self::clear_data_cache();
					self::set_notice( 'updated', sprintf(
						/* translators: %s: service account email address. */
						__( 'Service account saved. Now add %s as a Full user in Search Console and a Viewer in GA4.', 'twt-aeo-ultimate' ),
						TWTAEO_Google_Service_Account::get_client_email()
					) );
				}
				break;

			case 'remove_google_sa':
				if ( ! check_admin_referer( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				TWTAEO_Google_Service_Account::delete_key();
				self::clear_data_cache();
				self::set_notice( 'updated', __( 'Service account key removed.', 'twt-aeo-ultimate' ) );
				break;

			case 'save_google_api_key':
				if ( ! check_admin_referer( self::NONCE_SAVE_GOOGLE, '_twtaeo_cc_nonce' ) ) {
					return;
				}
				$api_key = sanitize_text_field( wp_unslash( $_POST['google_api_key'] ?? '' ) );
				TWTAEO_Google_OAuth::save_api_key( $api_key );
				self::set_notice( 'updated', '' !== $api_key
					? __( 'Google API key saved.', 'twt-aeo-ultimate' )
					: __( 'Google API key removed.', 'twt-aeo-ultimate' )
				);
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
		delete_transient( 'twtaeo_ga4_top_pages_v2' );
		delete_transient( 'twtaeo_ga4_ai_traffic' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );
		delete_transient( 'twtaeo_bing_stats_v2' );
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

	/**
	 * Allowlist of SVG tags/attributes for escaping inline icons with wp_kses().
	 *
	 * Keys are lowercase because wp_kses() matches attribute names
	 * case-insensitively; the original casing (e.g. `viewBox`) is preserved on
	 * output, so the icons still render correctly.
	 */
	private static function svg_allowed_tags() {
		return array(
			'svg'  => array(
				'class'       => true,
				'viewbox'     => true,
				'xmlns'       => true,
				'width'       => true,
				'height'      => true,
				'fill'        => true,
				'role'        => true,
				'focusable'   => true,
				'aria-hidden' => true,
			),
			'path' => array(
				'd'         => true,
				'fill'      => true,
				'opacity'   => true,
				'fill-rule' => true,
				'clip-rule' => true,
			),
			'g'      => array( 'fill' => true, 'opacity' => true ),
			'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true ),
			'rect'   => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true ),
		);
	}
}
