<?php
/**
 * Dashboard V2 — the "run it or tune it" control panel. (Prototype)
 *
 * Two ideas on one screen:
 *
 *  1. AUTOMATIONS — every site-wide job the plugin can run in bulk, each with
 *     a plain-English explanation of what the system will do, a Run button
 *     for people who trust the system, and a link to the dedicated screen
 *     for people who want manual control.
 *
 *  2. FEATURE SWITCHES — fine-grained on/off control over individual things
 *     the plugin publishes (llms.txt, sitemap endpoint, schema nodes, meta
 *     description, crawler logging, breadcrumbs…), for the SEO professional
 *     who wants some features but not others. Built on TWTAEO_Output_Control
 *     and the AI-Ready settings — delegated switches are written through the
 *     setting that owns them, never duplicated.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Dashboard_V2 {

	// ── Registries ───────────────────────────────────────────────────────────

	/**
	 * Every bulk job the dashboard can start.
	 *
	 * @return array<string,array>
	 */
	private static function automations() {
		$gsc_connected = class_exists( 'TWTAEO_Google_OAuth' ) && method_exists( 'TWTAEO_Google_OAuth', 'is_connected' ) && TWTAEO_Google_OAuth::is_connected();

		return array(

			'schema_rescan' => array(
				'label'     => __( 'Site-wide schema rescan', 'twt-aeo-ultimate' ),
				'what'      => __( 'Re-reads every published post, page and product: classifies its intent (blog post, service page, product…), detects what schema is on it and from which plugin, and re-evaluates what is missing. Runs in the background in batches — safe on large sites.', 'twt-aeo-ultimate' ),
				'available' => class_exists( 'TWTAEO_Background_Scan' ),
				'manual'    => array( 'twt-aeo-schema-detector', __( 'Schema Detector', 'twt-aeo-ultimate' ) ),
			),

			'bot_view_scan' => array(
				'label'     => __( 'Bot View site scan', 'twt-aeo-ultimate' ),
				'what'      => __( 'Fetches your homepage and your most recently updated posts, pages and products exactly the way no-JavaScript AI crawlers do — twice each, once as GPTBot and once as a browser — and reports blocked bots, challenge pages, JavaScript-locked schema and content, and missing metadata.', 'twt-aeo-ultimate' ),
				'available' => class_exists( 'TWTAEO_Bot_View' ) && TWTAEO_Bot_View::is_module_active(),
				'manual'    => array( 'twt-aeo-bot-view', __( 'Bot View', 'twt-aeo-ultimate' ) ),
			),

			'funnel_audit' => array(
				'label'     => __( 'Funnel audit rebuild', 'twt-aeo-ultimate' ),
				'what'      => __( 'Maps your internal link graph, identifies money pages (products, services, lead-gen pages), computes click depth and inbound links, and cross-references AI attention — which pages ChatGPT, Perplexity and Claude crawl and send visitors to — to find money pages that are buried and cited posts with no bridge to conversion.', 'twt-aeo-ultimate' ),
				'available' => class_exists( 'TWTAEO_Funnel_Audit' ) && TWTAEO_Funnel_Audit::is_module_active(),
				'manual'    => array( 'twt-aeo-funnel-audit', __( 'Funnel Audit', 'twt-aeo-ultimate' ) ),
			),

			'index_scan' => array(
				'label'     => __( 'Index status scan', 'twt-aeo-ultimate' ),
				'what'      => __( 'Checks your published pages against Google Search Console to find pages missing from the index, and flags likely causes: thin content, duplicate meta descriptions, heading problems.', 'twt-aeo-ultimate' ),
				'available' => class_exists( 'TWTAEO_Auto_Index_Scan' ) && $gsc_connected,
				'note'      => $gsc_connected ? '' : __( 'Requires the Google Search Console connection (Settings).', 'twt-aeo-ultimate' ),
				'manual'    => array( 'twt-aeo-not-indexed', __( 'Not Indexed', 'twt-aeo-ultimate' ) ),
			),

			'smart_404_scan' => array(
				'label'     => __( 'Smart 404 rescue scan', 'twt-aeo-ultimate' ),
				'what'      => __( 'Matches your Search Console 404s against live content, creates permanent redirects for renamed or moved pages, and auto-corrects broken internal links at their source (with an undo log).', 'twt-aeo-ultimate' ),
				'available' => class_exists( 'TWTAEO_Smart_404' ) && in_array( 'smart-404', (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true ),
				'note'      => in_array( 'smart-404', (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true ) ? '' : __( 'Enable the Smart 404 Rescue module to use this.', 'twt-aeo-ultimate' ),
				'manual'    => array( 'twt-aeo-smart-404', __( 'Smart 404 Rescue', 'twt-aeo-ultimate' ) ),
			),
		);
	}

	/**
	 * Extra publish switches owned by the AI-Ready settings (beyond the
	 * delegated ones Output Control already surfaces).
	 *
	 * @return array<string,array{label:string,what:string}>
	 */
	private static function ai_ready_switches() {
		return array(
			'llms_txt'             => array(
				'label' => __( 'llms.txt', 'twt-aeo-ultimate' ),
				'what'  => __( 'The /llms.txt document that tells AI assistants what this site contains. Turn off if you maintain your own.', 'twt-aeo-ultimate' ),
			),
			'semantic_breadcrumbs' => array(
				'label' => __( 'Breadcrumb schema', 'twt-aeo-ultimate' ),
				'what'  => __( 'The BreadcrumbList trail describing where each page sits in the site.', 'twt-aeo-ultimate' ),
			),
			'markdown_negotiation' => array(
				'label' => __( 'Markdown for AI agents', 'twt-aeo-ultimate' ),
				'what'  => __( 'Serves a clean Markdown version of pages to AI agents that ask for it (content negotiation).', 'twt-aeo-ultimate' ),
			),
			'okf'                  => array(
				'label' => __( 'Open Knowledge File', 'twt-aeo-ultimate' ),
				'what'  => __( 'The machine-readable site knowledge file AI agents can fetch for grounded answers about your business.', 'twt-aeo-ultimate' ),
			),
			'agent_skills_index'   => array(
				'label' => __( 'Agent skills index', 'twt-aeo-ultimate' ),
				'what'  => __( 'The discovery index that advertises this site\'s agent-readable capabilities.', 'twt-aeo-ultimate' ),
			),
		);
	}

	// ── POST handling ────────────────────────────────────────────────────────

	/**
	 * Run one automation. Returns a human status line.
	 *
	 * @param string $key Automation key.
	 * @return string
	 */
	private static function run_automation( $key ) {
		switch ( $key ) {
			case 'schema_rescan':
				TWTAEO_Background_Scan::start();
				return __( 'Schema rescan started — it runs in the background in batches.', 'twt-aeo-ultimate' );
			case 'bot_view_scan':
				$summary = TWTAEO_Bot_View::run_site_scan();
				return sprintf(
					/* translators: 1: pages scanned, 2: critical count. */
					__( 'Bot View scan finished: %1$d pages, %2$d critical findings.', 'twt-aeo-ultimate' ),
					count( isset( $summary['pages'] ) ? $summary['pages'] : array() ),
					isset( $summary['totals']['critical'] ) ? (int) $summary['totals']['critical'] : 0
				);
			case 'funnel_audit':
				$audit = TWTAEO_Funnel_Audit::build();
				return sprintf(
					/* translators: %d: pages scanned. */
					__( 'Funnel audit rebuilt over %d pages.', 'twt-aeo-ultimate' ),
					isset( $audit['scanned'] ) ? (int) $audit['scanned'] : 0
				);
			case 'index_scan':
				TWTAEO_Auto_Index_Scan::start();
				return __( 'Index status scan started — results appear on the Not Indexed screen as batches complete.', 'twt-aeo-ultimate' );
			case 'smart_404_scan':
				TWTAEO_Smart_404::run_scheduled_scan();
				return __( 'Smart 404 rescue scan finished — see the Smart 404 screen for redirects created and links healed.', 'twt-aeo-ultimate' );
		}
		return '';
	}

	/**
	 * Last-run/status line for an automation.
	 *
	 * @param string $key Automation key.
	 * @return string
	 */
	private static function automation_status( $key ) {
		switch ( $key ) {
			case 'schema_rescan':
				if ( class_exists( 'TWTAEO_Background_Scan' ) ) {
					$state = TWTAEO_Background_Scan::get_state();
					if ( ! empty( $state ) && TWTAEO_Background_Scan::is_running( $state ) ) {
						return __( 'Running now…', 'twt-aeo-ultimate' );
					}
				}
				return '';
			case 'bot_view_scan':
				$scan = class_exists( 'TWTAEO_Bot_View' ) ? TWTAEO_Bot_View::get_site_scan() : array();
				return ! empty( $scan['scanned_at'] )
					? sprintf(
						/* translators: 1: date, 2: critical count. */
						__( 'Last run %1$s — %2$d critical findings.', 'twt-aeo-ultimate' ),
						$scan['scanned_at'],
						isset( $scan['totals']['critical'] ) ? (int) $scan['totals']['critical'] : 0
					)
					: __( 'Never run.', 'twt-aeo-ultimate' );
			case 'funnel_audit':
				$audit = class_exists( 'TWTAEO_Funnel_Audit' ) ? TWTAEO_Funnel_Audit::get_audit() : array();
				return ! empty( $audit['built_at'] )
					? sprintf(
						/* translators: 1: date, 2: page count. */
						__( 'Last built %1$s over %2$d pages.', 'twt-aeo-ultimate' ),
						$audit['built_at'],
						(int) $audit['scanned']
					)
					: __( 'Never built.', 'twt-aeo-ultimate' );
			default:
				return '';
		}
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$notice = '';

		// Run an automation.
		if ( isset( $_POST['twtaeo_dash_run'] ) && check_admin_referer( 'twtaeo_dash_run' ) ) {
			$key         = sanitize_key( wp_unslash( $_POST['twtaeo_dash_run'] ) );
			$automations = self::automations();
			if ( isset( $automations[ $key ] ) && $automations[ $key ]['available'] ) {
				if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
					set_time_limit( 300 );
				}
				$notice = self::run_automation( $key );
			}
		}

		// Toggle an Output Control switch.
		if ( isset( $_POST['twtaeo_dash_output'], $_POST['twtaeo_dash_state'] ) && check_admin_referer( 'twtaeo_dash_switch' ) ) {
			$key = sanitize_key( wp_unslash( $_POST['twtaeo_dash_output'] ) );
			$on  = '1' === $_POST['twtaeo_dash_state'];
			if ( class_exists( 'TWTAEO_Output_Control' ) && TWTAEO_Output_Control::set( $key, $on ) ) {
				$notice = $on ? __( 'Switched on.', 'twt-aeo-ultimate' ) : __( 'Switched off.', 'twt-aeo-ultimate' );
			}
		}

		// Toggle an AI-Ready-owned switch (delegated settings are written
		// through their owner, never copied).
		if ( isset( $_POST['twtaeo_dash_airy'], $_POST['twtaeo_dash_state'] ) && check_admin_referer( 'twtaeo_dash_switch' ) ) {
			$key      = sanitize_key( wp_unslash( $_POST['twtaeo_dash_airy'] ) );
			$switches = self::ai_ready_switches();
			if ( isset( $switches[ $key ] ) && class_exists( 'TWTAEO_AI_Ready' ) ) {
				$settings         = TWTAEO_AI_Ready::get_settings();
				$settings[ $key ] = ( '1' === $_POST['twtaeo_dash_state'] ) ? 1 : 0;
				update_option( 'twtaeo_ai_ready', $settings );
				$notice = $settings[ $key ] ? __( 'Switched on.', 'twt-aeo-ultimate' ) : __( 'Switched off.', 'twt-aeo-ultimate' );
			}
		}

		$automations = self::automations();
		$outputs     = class_exists( 'TWTAEO_Output_Control' ) ? TWTAEO_Output_Control::outputs() : array();
		$airy        = class_exists( 'TWTAEO_AI_Ready' ) ? TWTAEO_AI_Ready::get_settings() : array();
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Dashboard', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Run everything from here, or fine-tune each feature on its own screen. Every switch below controls one specific thing this plugin publishes — turn off anything you handle yourself.', 'twt-aeo-ultimate' ); ?>
					</p>
					<p class="twt-aeo-header__sub" style="margin-top:6px">
						<?php
						printf(
							/* translators: %s: link to the AEO Score page. */
							esc_html__( 'Looking for the page-by-page status table, conflict detection or AI meta descriptions? They live on the %s page.', 'twt-aeo-ultimate' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=twt-aeo-classic' ) ) . '" style="color:inherit;text-decoration:underline">' . esc_html__( 'AEO Score', 'twt-aeo-ultimate' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="twt-aeo-section">
				<h2><?php esc_html_e( 'Automations', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="max-width:820px;margin-top:-4px">
					<?php esc_html_e( 'Each of these runs site-wide with sensible defaults. Prefer more control? Every one has a dedicated screen where you can run it manually and adjust the details.', 'twt-aeo-ultimate' ); ?>
				</p>

				<?php foreach ( $automations as $key => $auto ) : ?>
					<div class="twt-aeo-card" style="margin-bottom:12px;display:flex;gap:20px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
						<div style="flex:1;min-width:280px">
							<h3 style="margin:0 0 6px"><?php echo esc_html( $auto['label'] ); ?></h3>
							<p style="margin:0 0 6px"><?php echo esc_html( $auto['what'] ); ?></p>
							<?php $status = self::automation_status( $key ); ?>
							<?php if ( $status ) : ?>
								<p class="description" style="margin:0">
									<?php echo esc_html( $status ); ?>
									<?php
									// The count alone is a dead end — link straight to the findings table.
									if ( 'bot_view_scan' === $key && class_exists( 'TWTAEO_Bot_View' ) ) {
										$bv_scan = TWTAEO_Bot_View::get_site_scan();
										if ( ! empty( $bv_scan['pages'] ) ) {
											echo ' <a href="' . esc_url( admin_url( 'admin.php?page=twt-aeo-bot-view#twtaeo-site-scan' ) ) . '">' . esc_html__( 'View findings', 'twt-aeo-ultimate' ) . '</a>';
										}
									}
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $auto['note'] ) ) : ?>
								<p class="description" style="margin:4px 0 0;color:#996800"><?php echo esc_html( $auto['note'] ); ?></p>
							<?php endif; ?>
						</div>
						<div style="display:flex;gap:8px;align-items:center;padding-top:4px">
							<form method="post">
								<?php wp_nonce_field( 'twtaeo_dash_run' ); ?>
								<button type="submit" name="twtaeo_dash_run" value="<?php echo esc_attr( $key ); ?>" class="button button-primary" <?php disabled( ! $auto['available'] ); ?>>
									<?php esc_html_e( 'Run now', 'twt-aeo-ultimate' ); ?>
								</button>
							</form>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $auto['manual'][0] ) ); ?>">
								<?php
								printf(
									/* translators: %s: screen name. */
									esc_html__( 'Fine-tune: %s', 'twt-aeo-ultimate' ),
									esc_html( $auto['manual'][1] )
								);
								?>
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="twt-aeo-section">
				<h2><?php esc_html_e( 'What this plugin publishes', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="max-width:820px;margin-top:-4px">
					<?php esc_html_e( 'Fine-grained switches — not modules. If you handle any of these yourself (your own sitemap, your own llms.txt, another schema plugin), switch ours off and nothing else changes. Everything defaults to on; absent has always meant on, so flipping a switch is always your deliberate act.', 'twt-aeo-ultimate' ); ?>
				</p>

				<div class="twt-aeo-card" style="padding:0;">
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $outputs as $key => $output ) : ?>
							<?php
							$delegated = ! empty( $output['delegate'] );
							$enabled   = TWTAEO_Output_Control::enabled( $key );
							// Delegated ai_ready keys are toggled through their owner below;
							// skip them here so a switch never appears twice.
							if ( $delegated ) {
								continue;
							}
							?>
							<tr>
								<td style="width:230px;vertical-align:top"><strong><?php echo esc_html( $output['label'] ); ?></strong></td>
								<td>
									<?php echo esc_html( $output['what'] ); ?>
									<?php if ( ! empty( $output['gated'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $output['gated'] ); ?></span>
									<?php endif; ?>
								</td>
								<td style="width:110px;text-align:right">
									<form method="post">
										<?php wp_nonce_field( 'twtaeo_dash_switch' ); ?>
										<input type="hidden" name="twtaeo_dash_output" value="<?php echo esc_attr( $key ); ?>">
										<input type="hidden" name="twtaeo_dash_state" value="<?php echo $enabled ? '0' : '1'; ?>">
										<button type="submit" class="button <?php echo $enabled ? '' : 'button-primary'; ?>">
											<?php echo $enabled ? esc_html__( 'On — turn off', 'twt-aeo-ultimate' ) : esc_html__( 'Off — turn on', 'twt-aeo-ultimate' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>

						<?php foreach ( self::ai_ready_switches() as $key => $switch ) : ?>
							<?php $enabled = ! empty( $airy[ $key ] ); ?>
							<tr>
								<td style="width:230px;vertical-align:top"><strong><?php echo esc_html( $switch['label'] ); ?></strong></td>
								<td><?php echo esc_html( $switch['what'] ); ?></td>
								<td style="width:110px;text-align:right">
									<form method="post">
										<?php wp_nonce_field( 'twtaeo_dash_switch' ); ?>
										<input type="hidden" name="twtaeo_dash_airy" value="<?php echo esc_attr( $key ); ?>">
										<input type="hidden" name="twtaeo_dash_state" value="<?php echo $enabled ? '0' : '1'; ?>">
										<button type="submit" class="button <?php echo $enabled ? '' : 'button-primary'; ?>">
											<?php echo $enabled ? esc_html__( 'On — turn off', 'twt-aeo-ultimate' ) : esc_html__( 'Off — turn on', 'twt-aeo-ultimate' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="description" style="margin-top:8px">
					<?php
					printf(
						/* translators: %s: link to modules page. */
						esc_html__( 'Whole feature areas (Reviews, Local Pack, sitemap generator, IndexNow, Google News…) switch on and off as modules on the %s screen.', 'twt-aeo-ultimate' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=twt-aeo-modules' ) ) . '">' . esc_html__( 'Modules', 'twt-aeo-ultimate' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
