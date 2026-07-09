<?php
/**
 * AI Ready Admin Page
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_AI_Ready {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$saved   = false;
		$flushed = false;

		if ( isset( $_POST[ TWTAEO_AI_Ready::NONCE_NAME ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside handle_save()
			$result  = self::handle_save();
			$saved   = $result['saved'];
			$flushed = $result['flushed'];
		}

		$s     = TWTAEO_AI_Ready::get_settings();
		$score = TWTAEO_AI_Ready::get_readiness_score();

		// One-time new OAuth client credentials notice — read transient here so it
		// shows at the top of the page, not buried in the Security section.
		$new_creds = null;
		if ( isset( $_GET['twtaeo_client_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$new_creds = get_transient( 'twtaeo_new_client_creds' );
			if ( $new_creds ) {
				delete_transient( 'twtaeo_new_client_creds' );
			}
		}

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'AI Ready', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-badge twt-aeo-badge--beta" style="font-size:11px;font-weight:600;letter-spacing:.05em;vertical-align:middle;margin-left:8px;"><?php esc_html_e( 'Beta', 'twt-aeo-ultimate' ); ?></span>
					</h1>
					<div class="twt-aeo-header__meta">
						<span class="twt-aeo-badge twt-aeo-badge--plugin">
							<?php echo esc_html( $score['enabled'] ); ?> / <?php echo esc_html( $score['total'] ); ?> <?php esc_html_e( 'features active', 'twt-aeo-ultimate' ); ?>
						</span>
					</div>
				</div>
				<p class="twt-aeo-header__sub">
					<?php esc_html_e( 'Make your site readable and accessible to AI agents, crawlers, and language models. Maximize your Agent Readiness Score.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>

			<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php esc_html_e( 'AI Ready settings saved.', 'twt-aeo-ultimate' ); ?>
					<?php if ( $flushed ) : ?>
					<?php esc_html_e( 'Rewrite rules updated — new endpoints are now live.', 'twt-aeo-ultimate' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $new_creds ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><strong><?php esc_html_e( 'OAuth client created — save these credentials now. The secret will not be shown again.', 'twt-aeo-ultimate' ); ?></strong></p>
				<table style="border-collapse:collapse;margin-top:6px;">
					<tr>
						<td style="padding:4px 12px 4px 0;font-size:13px;color:#555;"><?php esc_html_e( 'Client Name', 'twt-aeo-ultimate' ); ?></td>
						<td><code><?php echo esc_html( $new_creds['name'] ); ?></code></td>
					</tr>
					<tr>
						<td style="padding:4px 12px 4px 0;font-size:13px;color:#555;"><?php esc_html_e( 'Client ID', 'twt-aeo-ultimate' ); ?></td>
						<td>
							<code id="twt-aeo-new-client-id"><?php echo esc_html( $new_creds['id'] ); ?></code>
							<button type="button" class="button button-small twt-aeo-copy-btn" data-target="twt-aeo-new-client-id" style="margin-left:6px;"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 12px 4px 0;font-size:13px;color:#555;"><?php esc_html_e( 'Client Secret', 'twt-aeo-ultimate' ); ?></td>
						<td>
							<code id="twt-aeo-new-client-secret"><?php echo esc_html( $new_creds['secret'] ); ?></code>
							<button type="button" class="button button-small twt-aeo-copy-btn" data-target="twt-aeo-new-client-secret" style="margin-left:6px;"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
						</td>
					</tr>
				</table>
				<p style="margin-top:8px;font-size:12px;color:#666;">
					<?php
					printf(
						/* translators: token endpoint URL */
						esc_html__( 'Token endpoint: %s — POST grant_type=client_credentials with Basic auth or body params.', 'twt-aeo-ultimate' ),
						'<code>' . esc_html( rest_url( 'aeo/v1/oauth/token' ) ) . '</code>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php
			// ── Server environment check ──────────────────────────────────────────
			$mem_bytes    = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
			$mem_critical = $mem_bytes > 0 && $mem_bytes <= wp_convert_hr_to_bytes( '128M' );
			$mem_advisory = $mem_bytes > 0 && ! $mem_critical && $mem_bytes < wp_convert_hr_to_bytes( '512M' );
			$mem_label    = ini_get( 'memory_limit' );

			global $wpdb;
			$mysql_ver        = $wpdb->db_version();
			$mysql_advisory   = version_compare( $mysql_ver, '8.0', '<' );

			$php_ver          = PHP_VERSION;
			$php_advisory     = version_compare( $php_ver, '8.0', '<' );

			if ( $mem_critical || $mem_advisory || $mysql_advisory || $php_advisory ) :
			?>
			<div class="notice <?php echo $mem_critical ? 'notice-error' : 'notice-warning'; ?> is-dismissible" style="margin-top:12px;">
				<p><strong><?php esc_html_e( 'Server Environment', 'twt-aeo-ultimate' ); ?></strong></p>
				<ul style="margin:.4em 0 .4em 1.5em;list-style:disc;font-size:13px;">
					<?php if ( $mem_critical ) : ?>
					<li style="color:#7f1d1d;font-weight:600;">
						<?php
						printf(
							/* translators: memory limit value */
							esc_html__( 'Critical: PHP memory_limit is %s. WP 7.0 AI features combined with active AEO agent querying will cause silent memory exhaustion on this host. Contact your host or add `define(\'WP_MEMORY_LIMIT\', \'256M\');` to wp-config.php. Minimum recommended: 256 MB; ideal: 512 MB.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( $mem_label ) . '</code>'
						);
						?>
					</li>
					<?php elseif ( $mem_advisory ) : ?>
					<li>
						<?php
						printf(
							/* translators: memory limit value */
							esc_html__( 'Advisory: PHP memory_limit is %s. WP 7.0 AI features work best with 512 MB or more. Add `define(\'WP_MEMORY_LIMIT\', \'512M\');` to wp-config.php if your host allows it.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( $mem_label ) . '</code>'
						);
						?>
					</li>
					<?php endif; ?>
					<?php if ( $mysql_advisory ) : ?>
					<li>
						<?php
						printf(
							/* translators: MySQL version */
							esc_html__( 'MySQL %s detected. This plugin requires MySQL 8.0 or higher. Please upgrade your database server.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( $mysql_ver ) . '</code>'
						);
						?>
					</li>
					<?php endif; ?>
					<?php if ( $php_advisory ) : ?>
					<li>
						<?php
						printf(
							/* translators: PHP version */
							esc_html__( 'PHP %s detected. PHP 8.0 or higher is recommended for optimal performance with WP 7.0 AI features.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( $php_ver ) . '</code>'
						);
						?>
					</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>

			<div class="notice notice-info" style="margin-top:12px;">
				<p>
					<strong><?php esc_html_e( 'Before running your agent readiness scan:', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'After saving settings or updating the plugin, go to', 'twt-aeo-ultimate' ); ?>
					<strong><?php esc_html_e( 'Settings → Permalinks → Save Changes', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'to flush rewrite rules, then clear any page cache. Once done, test your site at', 'twt-aeo-ultimate' ); ?>
					<a href="https://isitagentready.com/" target="_blank" rel="noopener">isitagentready.com</a>.
				</p>
			</div>

			<?php
			$wk      = TWTAEO_AI_Ready::get_wellknown_status();
			$seo     = TWTAEO_AI_Ready::detect_seo_plugins();
			$has_rm  = in_array( 'rankmath', $seo, true );
			$has_yoast = in_array( 'yoast', $seo, true );
			$llms_conflicts = TWTAEO_AI_Ready::detect_llms_txt_conflict();
			$physical_llms  = TWTAEO_AI_Ready::has_physical_llms_txt();
			?>

			<?php if ( $has_rm || $has_yoast ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><strong>
					<?php
					$names = array();
					if ( $has_rm )    $names[] = 'Rank Math';
					if ( $has_yoast ) $names[] = 'Yoast SEO';
					echo esc_html( implode( ' &amp; ', $names ) ) . ' ' . esc_html__( 'detected — compatibility notes below.', 'twt-aeo-ultimate' );
					?>
				</strong></p>
				<ul style="margin:.4em 0 .4em 1.5em;list-style:disc;font-size:13px;">
					<li><?php esc_html_e( 'robots.txt — if your SEO plugin writes a physical robots.txt file, the Content-Signal directive cannot be added automatically. Use the "Inject into robots.txt" button on the Content-Signal card below.', 'twt-aeo-ultimate' ); ?></li>
					<?php if ( $has_rm ) : ?>
					<li><?php esc_html_e( 'Rank Math (v1.0.219+) includes its own llms.txt generator. If both are active, only one can serve /llms.txt — disable the duplicate in Rank Math → Modules, or disable this plugin\'s llms.txt below.', 'twt-aeo-ultimate' ); ?></li>
					<?php endif; ?>
					<?php if ( ! empty( $llms_conflicts ) ) : ?>
					<li style="color:#92400e;font-weight:600;">
						<?php
						printf(
							/* translators: plugin name */
							esc_html__( 'Conflict detected: %s is generating llms.txt. This plugin\'s rewrite rule may be ignored because a physical file exists. Disable one or the other.', 'twt-aeo-ultimate' ),
							esc_html( implode( ', ', $llms_conflicts ) )
						);
						?>
					</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( $physical_llms && empty( $llms_conflicts ) ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Physical llms.txt found on disk.', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'A file at /llms.txt already exists on your server. The web server will serve that file directly, bypassing this plugin\'s dynamic generator. Delete the physical file via FTP/cPanel if you want this plugin to control /llms.txt.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php
			// ── /.well-known/ diagnostics ──────────────────────────────────────
			$wk_has_errors   = $wk['dir_exists'] && ( ! $wk['dir_ok'] || $wk['has_bad_htaccess'] );
			$wk_cant_create  = ! $wk['dir_exists'] && ! $wk['abspath_writable'];
			$wk_needs_files  = $wk['can_write'] && (
				( ! $wk['catalog_exists'] ) || // always generated
				( ! empty( $s['agent_skills_index'] ) && ! $wk['skills_exists'] ) ||
				( ! empty( $s['mcp_integration'] )   && ! $wk['mcp_card_exists'] )
			);
			?>

			<?php if ( $wk_has_errors || $wk_cant_create ) : ?>
			<div class="notice notice-error">
				<p><strong>
					<?php
					if ( $wk_cant_create ) {
						esc_html_e( 'Cannot create /.well-known/ directory — file permission error.', 'twt-aeo-ultimate' );
					} else {
						esc_html_e( '/.well-known/ directory issue detected.', 'twt-aeo-ultimate' );
					}
					?>
				</strong></p>
				<ul style="margin:.4em 0 .4em 1.5em;list-style:disc;font-size:13px;">
					<?php if ( $wk_cant_create ) : ?>
					<li>
						<?php
						printf(
							/* translators: path */
							esc_html__( 'PHP cannot write to %s. In cPanel File Manager, right-click your public_html (or www) root folder → Change Permissions → 755. The plugin will create .well-known/ automatically on the next Save.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( TWTAEO_AI_Ready::get_wellknown_base_path() ) . '</code>'
						);
						?>
					</li>
					<?php endif; ?>
					<?php if ( $wk['dir_exists'] && ! $wk['dir_writable'] ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: permissions, 2: path */
							esc_html__( 'Permissions on %2$s are %1$s — needs 755. In cPanel: right-click .well-known/ → Change Permissions → 755.', 'twt-aeo-ultimate' ),
							'<code>' . esc_html( $wk['dir_perms'] ) . '</code>',
							'<code>.well-known/</code>'
						);
						?>
					</li>
					<?php endif; ?>
					<?php if ( $wk['dir_exists'] && ! $wk['dir_readable'] ) : ?>
					<li><?php esc_html_e( '/.well-known/ is not readable by the web server. Set it to 755 in cPanel File Manager.', 'twt-aeo-ultimate' ); ?></li>
					<?php endif; ?>
					<?php if ( $wk['has_bad_htaccess'] ) : ?>
					<li>
						<?php esc_html_e( 'A .htaccess inside .well-known/ is blocking all access. Delete it:', 'twt-aeo-ultimate' ); ?>
						<code><?php echo esc_html( TWTAEO_AI_Ready::get_wellknown_base_path() . '/.well-known/.htaccess' ); ?></code>
					</li>
					<?php endif; ?>
				</ul>
				<p style="margin-top:.5em;font-size:13px;">
					<?php esc_html_e( 'Features affected: API Catalog, Agent Skills Index, MCP Server Card, OAuth Discovery. After fixing permissions, click Save Changes to generate all files.', 'twt-aeo-ultimate' ); ?>
				</p>

				<details class="twt-aeo-air-manual" style="margin-top:.6em;font-size:13px;">
					<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Can’t change permissions? Create the files manually', 'twt-aeo-ultimate' ); ?></summary>
					<div style="margin-top:.6em;">
						<p><?php esc_html_e( 'If your host will not let WordPress write to the site root, you can create each file below by hand. In cPanel File Manager (or over SFTP/FTP), starting from your WordPress root folder — the one that contains wp-config.php:', 'twt-aeo-ultimate' ); ?></p>
						<ol style="margin:.4em 0 .8em 1.5em;list-style:decimal;">
							<li><?php esc_html_e( 'Create a folder named .well-known in your WordPress root if it does not already exist, and set its permissions to 755.', 'twt-aeo-ultimate' ); ?></li>
							<li><?php esc_html_e( 'For each file below, create it at the exact path shown — creating any sub-folder it names (such as mcp/ or agent-skills/) first — then paste the contents exactly and save.', 'twt-aeo-ultimate' ); ?></li>
							<li><?php esc_html_e( 'Set each file’s permissions to 644 so the web server can read it. The api-catalog and oauth files have no extension — that is correct, do not add .txt or .json.', 'twt-aeo-ultimate' ); ?></li>
						</ol>
						<?php foreach ( TWTAEO_AI_Ready::get_wellknown_manifest() as $manual_file ) : ?>
							<p style="margin:.9em 0 .2em;">
								<strong><?php echo esc_html( $manual_file['label'] ); ?></strong><br>
								<?php esc_html_e( 'Path (relative to your WordPress root):', 'twt-aeo-ultimate' ); ?>
								<code><?php echo esc_html( $manual_file['rel'] ); ?></code>
							</p>
							<textarea readonly rows="6" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;" onclick="this.select();"><?php echo esc_textarea( $manual_file['contents'] ); ?></textarea>
						<?php endforeach; ?>
						<p style="margin-top:.6em;">
							<?php esc_html_e( 'Each file is also served at its own URL by this plugin, so after creating it you can confirm it by opening the matching /.well-known/ address in your browser. If the URL already loads correctly, the dynamic version is working and the physical file is only needed for hosts that serve /.well-known/ straight from disk.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
				</details>
			</div>
			<?php elseif ( $wk_needs_files ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( '/.well-known/ files need to be generated.', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'Directory is accessible but one or more feature files are missing. Click Save Changes to generate them now.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( TWTAEO_AI_Ready::NONCE_ACTION, TWTAEO_AI_Ready::NONCE_NAME ); ?>

				<!-- ── Section 1: Zero-Footprint ─────────────────────────────── -->
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title">
						<span class="dashicons dashicons-yes-alt" style="color:var(--aeo-success);font-size:16px;width:16px;height:16px;"></span>
						<?php esc_html_e( 'Zero-Footprint Features', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-air-badge twt-aeo-air-badge--green"><?php esc_html_e( 'Always On by Default', 'twt-aeo-ultimate' ); ?></span>
					</h2>
					<p class="twt-aeo-air-section-desc"><?php esc_html_e( 'These features carry virtually no security risk. They serve AI-optimized versions of your existing content and broadcast your data-use preferences — no interactive access is granted.', 'twt-aeo-ultimate' ); ?></p>

					<div class="twt-aeo-air-feature-list">

						<!-- Markdown Negotiation -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'Markdown Content Negotiation', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-benchmark twt-aeo-air-benchmark--blue"><?php esc_html_e( 'Content Score', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'When an AI agent requests your pages with an Accept: text/markdown header, the plugin intercepts that request and returns a clean, stripped Markdown version of your content instead of HTML. This reduces noise, token usage, and improves comprehension by AI models.', 'twt-aeo-ultimate' ); ?></p>
									<details class="twt-aeo-air-why">
										<summary><?php esc_html_e( 'Why this matters', 'twt-aeo-ultimate' ); ?></summary>
										<div class="twt-aeo-air-why__body">
											<p><?php esc_html_e( 'HTML is roughly 80% structural noise — navigation, footers, scripts, and ads. By serving clean Markdown, you ensure AI agents capture your brand voice accurately and cite your facts without being confused by website code.', 'twt-aeo-ultimate' ); ?></p>
											<p><?php esc_html_e( 'Cloudflare\'s Agent Readiness benchmark grades your Content Score on how efficiently your content reaches an LLM. Reducing token waste directly improves how models like Claude, Gemini, and GPT-4o read and represent your site.', 'twt-aeo-ultimate' ); ?></p>
										</div>
									</details>
									<?php if ( ! empty( $s['markdown_negotiation'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-info-outline"></span>
										<?php esc_html_e( 'Active: sends Vary: Accept with all frontend pages.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Markdown Negotiation', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[markdown_negotiation]" value="1" <?php checked( ! empty( $s['markdown_negotiation'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
							<div class="twt-aeo-air-feature-card__sub">
								<label class="twt-aeo-air-sub-toggle">
									<input type="checkbox" name="twtaeo_air[url_fallback]" value="1" <?php checked( ! empty( $s['url_fallback'] ) ); ?> />
									<span><?php esc_html_e( 'Also allow URL fallback', 'twt-aeo-ultimate' ); ?> <code>?aeo_format=markdown</code></span>
								</label>
								<p class="twt-aeo-air-sub-desc"><?php esc_html_e( 'Useful for CDN environments where Accept headers are stripped. Agents can append ?aeo_format=markdown to any post URL to request clean Markdown.', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>

						<!-- llms.txt -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'llms.txt Generator', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-benchmark twt-aeo-air-benchmark--purple"><?php esc_html_e( 'Discoverability Score', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Generates a dynamic machine-readable "cheat sheet" for AI models at /llms.txt. It includes your site name, tagline, and a structured list of your most recent pages with links and excerpts — giving AI tools an instant overview of your site without crawling every page.', 'twt-aeo-ultimate' ); ?></p>
									<details class="twt-aeo-air-why">
										<summary><?php esc_html_e( 'Why this matters', 'twt-aeo-ultimate' ); ?></summary>
										<div class="twt-aeo-air-why__body">
											<p><?php esc_html_e( 'Traditional sitemaps are built for link-crawlers. llms.txt is a "Fast-Pass" menu built for context — it lets AI agents map your entire site without wasting tokens parsing navigation menus, footers, or pagination.', 'twt-aeo-ultimate' ); ?></p>
											<p><?php esc_html_e( 'Cloudflare\'s Agent Readiness benchmark grades your Discoverability Score based on how easily an agent can orient itself on your site. This is the single highest-impact, lowest-risk setting you can enable.', 'twt-aeo-ultimate' ); ?></p>
										</div>
									</details>
									<?php if ( ! empty( $s['llms_txt'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_url( home_url( '/llms.txt' ) ); ?></a>
									</p>
									<?php if ( $physical_llms ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-danger);">
										<span class="dashicons dashicons-warning"></span>
										<?php
										if ( ! empty( $llms_conflicts ) ) {
											printf(
												/* translators: plugin name */
												esc_html__( 'Conflict: %s is generating a physical /llms.txt file. Delete it from that plugin or disable it here — only one can serve this path.', 'twt-aeo-ultimate' ),
												esc_html( implode( ', ', $llms_conflicts ) )
											);
										} else {
											esc_html_e( 'A physical /llms.txt file exists on disk. The web server serves it directly, bypassing this plugin. Delete it via FTP/cPanel if you want this plugin to control the output.', 'twt-aeo-ultimate' );
										}
										?>
									</p>
									<?php endif; ?>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle llms.txt', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[llms_txt]" value="1" <?php checked( ! empty( $s['llms_txt'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- Agent Skills Index -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Agent Skills Index', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Publishes a discovery index at /.well-known/agent-skills/index.json listing every AI capability this site exposes. AI agents read this file to understand what tools and APIs are available without crawling. Each skill entry includes a name, type, description, URL, and SHA-256 integrity hash.', 'twt-aeo-ultimate' ); ?></p>
									<?php if ( ! empty( $s['agent_skills_index'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/agent-skills/index.json' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/agent-skills/index.json' ) ); ?></a>
									</p>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Agent Skills Index', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[agent_skills_index]" value="1" <?php checked( ! empty( $s['agent_skills_index'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- Content-Signal Headers -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'Content-Signal Headers & robots.txt', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-benchmark twt-aeo-air-benchmark--teal"><?php esc_html_e( 'Signals Score', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Broadcasts your AI data-use preferences two ways: as an HTTP response header on every page, and as per-bot directives appended to robots.txt. AI readiness tools check both locations — this covers both.', 'twt-aeo-ultimate' ); ?></p>
									<details class="twt-aeo-air-why">
										<summary><?php esc_html_e( 'Why this matters', 'twt-aeo-ultimate' ); ?></summary>
										<div class="twt-aeo-air-why__body">
											<p><?php esc_html_e( 'There is a critical difference between Training and Search. "AI Training" means an AI company uses your content to build their model — a permanent use of your IP. "AI Search" means an agent reads your content to answer a user\'s question — a transient use that drives traffic back to you.', 'twt-aeo-ultimate' ); ?></p>
											<p><?php esc_html_e( 'Content-Signal lets you say yes to one and no to the other. Most SEOs are not aware these are separate permissions. Cloudflare\'s Signals Score measures whether your preferences are machine-readable and correctly declared.', 'twt-aeo-ultimate' ); ?></p>
										</div>
									</details>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/robots.txt' ) ); ?></a>
									</p>
								</div>
								<span class="twt-aeo-air-badge twt-aeo-air-badge--blue"><?php esc_html_e( 'Always Active', 'twt-aeo-ultimate' ); ?></span>
							</div>
							<div class="twt-aeo-air-signal-grid">
								<div class="twt-aeo-air-signal-row">
									<div class="twt-aeo-air-signal-row__label">
										<strong><?php esc_html_e( 'AI Training', 'twt-aeo-ultimate' ); ?></strong>
										<span><?php esc_html_e( 'May AI providers use this content to train their models?', 'twt-aeo-ultimate' ); ?></span>
									</div>
									<div class="twt-aeo-air-signal-row__options">
										<?php foreach ( array( 'no' => 'Do not train (recommended)', 'yes' => 'Allow training', 'unspecified' => 'No preference' ) as $val => $label ) : ?>
										<label class="twt-aeo-air-signal-option">
											<input type="radio" name="twtaeo_air[content_signal_ai_train]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['content_signal_ai_train'], $val ); ?> />
											<span><?php echo esc_html( $label ); ?></span>
										</label>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="twt-aeo-air-signal-row">
									<div class="twt-aeo-air-signal-row__label">
										<strong><?php esc_html_e( 'AI Search Indexing', 'twt-aeo-ultimate' ); ?></strong>
										<span><?php esc_html_e( 'May AI search engines index and cite this content?', 'twt-aeo-ultimate' ); ?></span>
									</div>
									<div class="twt-aeo-air-signal-row__options">
										<?php foreach ( array( 'yes' => 'Allow indexing (recommended)', 'no' => 'Do not index', 'unspecified' => 'No preference' ) as $val => $label ) : ?>
										<label class="twt-aeo-air-signal-option">
											<input type="radio" name="twtaeo_air[content_signal_search]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['content_signal_search'], $val ); ?> />
											<span><?php echo esc_html( $label ); ?></span>
										</label>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="twt-aeo-air-signal-row">
									<div class="twt-aeo-air-signal-row__label">
										<strong><?php esc_html_e( 'AI Input / RAG', 'twt-aeo-ultimate' ); ?></strong>
										<span><?php esc_html_e( 'May AI systems use this content as input context for retrieval-augmented generation?', 'twt-aeo-ultimate' ); ?></span>
									</div>
									<div class="twt-aeo-air-signal-row__options">
										<?php foreach ( array( 'no' => 'Do not use as input (recommended)', 'yes' => 'Allow as input', 'unspecified' => 'No preference' ) as $val => $label ) : ?>
										<label class="twt-aeo-air-signal-option">
											<input type="radio" name="twtaeo_air[content_signal_ai_input]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['content_signal_ai_input'], $val ); ?> />
											<span><?php echo esc_html( $label ); ?></span>
										</label>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
							<?php
							$parts = array();
							if ( $s['content_signal_ai_train'] !== 'unspecified' ) $parts[] = 'ai-train=' . $s['content_signal_ai_train'];
							if ( $s['content_signal_search'] !== 'unspecified' ) $parts[] = 'search=' . $s['content_signal_search'];
							if ( $s['content_signal_ai_input'] !== 'unspecified' ) $parts[] = 'ai-input=' . $s['content_signal_ai_input'];
							if ( ! empty( $parts ) ) : ?>
							<p class="twt-aeo-air-feature-card__endpoint" style="margin-top:12px;">
								<span class="dashicons dashicons-info-outline"></span>
								<?php esc_html_e( 'Output directive:', 'twt-aeo-ultimate' ); ?>
								<code>Content-Signal: <?php echo esc_html( implode( ', ', $parts ) ); ?></code>
							</p>
							<?php endif; ?>
							<?php
							$sitemap_url = TWTAEO_AI_Ready::get_sitemap_url();
							?>
							<p class="twt-aeo-air-feature-card__endpoint" style="margin-top:12px;">
								<?php if ( $sitemap_url ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:var(--aeo-success);"></span>
								<?php esc_html_e( 'Sitemap detected — will be added to robots.txt:', 'twt-aeo-ultimate' ); ?>
								<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $sitemap_url ); ?></code></a>
								<?php else : ?>
								<span class="dashicons dashicons-info-outline" style="color:var(--aeo-warning);"></span>
								<?php
								printf(
									/* translators: URL */
									esc_html__( 'No sitemap detected — a fallback will be served at %s and referenced from robots.txt.', 'twt-aeo-ultimate' ),
									'<a href="' . esc_url( home_url( '/sitemap.xml' ) ) . '" target="_blank" rel="noopener"><code>' . esc_html( home_url( '/sitemap.xml' ) ) . '</code></a>'
								);
								?>
								<?php endif; ?>
							</p>
							<div class="twt-aeo-air-feature-card__sub">
								<label class="twt-aeo-air-sub-toggle">
									<input type="checkbox" name="twtaeo_air[robots_bot_rules]" value="1" <?php checked( ! empty( $s['robots_bot_rules'] ) ); ?> />
									<span><?php esc_html_e( 'Also write per-bot Allow/Disallow rules to robots.txt', 'twt-aeo-ultimate' ); ?></span>
								</label>
								<p class="twt-aeo-air-sub-desc">
									<?php esc_html_e( 'Adds explicit User-agent entries for known AI crawlers (GPTBot, ClaudeBot, etc.) matching your signal choices above.', 'twt-aeo-ultimate' ); ?>
									<strong style="color:var(--aeo-warning);display:block;margin-top:4px;"><?php esc_html_e( 'Leave off if Yoast, Rank Math, or another SEO plugin already manages AI bot access — duplicate rules cause conflicts.', 'twt-aeo-ultimate' ); ?></strong>
								</p>
							</div>
							<?php
							$robots_physical = TWTAEO_AI_Ready::has_physical_robots();
							$robots_writable = TWTAEO_AI_Ready::physical_robots_is_writable();
							$robots_signal   = $robots_physical && TWTAEO_AI_Ready::get_physical_robots_has_signal();
							$seo_names       = TWTAEO_AI_Ready::seo_plugin_names( $seo );
							$seo_label       = ! empty( $seo_names ) ? implode( ' / ', $seo_names ) : '';
							$robots_nonce    = wp_create_nonce( TWTAEO_AI_Ready::NONCE_INJECT );
							?>
							<div class="twt-aeo-air-robots-notice" id="twt-aeo-robots-notice">
								<?php if ( ! $robots_physical ) : ?>
								<span class="twt-aeo-air-robots-notice__icon dashicons dashicons-yes-alt" style="color:var(--aeo-success);"></span>
								<div class="twt-aeo-air-robots-notice__body">
									<strong><?php esc_html_e( 'robots.txt is served dynamically — fully tracked.', 'twt-aeo-ultimate' ); ?></strong>
									<p>
										<?php if ( $seo_label ) : ?>
										<?php
										printf(
											/* translators: SEO plugin name(s) */
											esc_html__( 'WordPress builds robots.txt on each request, so this plugin\'s AI directives and Content-Signal are applied automatically alongside %s, and AI-crawler hits are logged. No action needed.', 'twt-aeo-ultimate' ),
											'<strong>' . esc_html( $seo_label ) . '</strong>'
										);
										?>
										<?php else : ?>
										<?php esc_html_e( 'WordPress builds robots.txt on each request, so this plugin\'s AI directives and Content-Signal are applied automatically and AI-crawler hits are logged. No action needed.', 'twt-aeo-ultimate' ); ?>
										<?php endif; ?>
									</p>
								</div>
								<?php elseif ( $robots_writable ) : ?>
								<span class="twt-aeo-air-robots-notice__icon dashicons dashicons-warning" style="color:var(--aeo-warning);"></span>
								<div class="twt-aeo-air-robots-notice__body">
									<strong><?php esc_html_e( 'Physical robots.txt detected — served as a static file.', 'twt-aeo-ultimate' ); ?></strong>
									<p><?php esc_html_e( 'A robots.txt file on disk is served directly by the web server. That freezes its content and prevents AI-crawler hits from being tracked.', 'twt-aeo-ultimate' ); ?></p>
									<p style="margin-top:6px;">
										<strong><?php esc_html_e( 'Recommended: remove it.', 'twt-aeo-ultimate' ); ?></strong>
										<?php if ( $seo_label ) : ?>
										<?php
										printf(
											/* translators: SEO plugin name(s) */
											esc_html__( 'WordPress will then serve robots.txt dynamically and %s will keep managing its rules through WordPress — no content is lost, and hits become trackable.', 'twt-aeo-ultimate' ),
											'<strong>' . esc_html( $seo_label ) . '</strong>'
										);
										?>
										<?php else : ?>
										<?php esc_html_e( 'WordPress and this plugin will then serve a clean dynamic robots.txt with AI directives and Content-Signal, and hits become trackable.', 'twt-aeo-ultimate' ); ?>
										<?php endif; ?>
										<?php if ( ! $robots_signal ) : ?>
										<br><?php esc_html_e( 'Prefer to keep the file? Use Inject instead to write Content-Signal into it — but hits to a static file stay untracked.', 'twt-aeo-ultimate' ); ?>
										<?php endif; ?>
									</p>
								</div>
								<?php else : ?>
								<span class="twt-aeo-air-robots-notice__icon dashicons dashicons-warning" style="color:var(--aeo-warning);"></span>
								<div class="twt-aeo-air-robots-notice__body">
									<strong><?php esc_html_e( 'Physical robots.txt detected — not writable.', 'twt-aeo-ultimate' ); ?></strong>
									<p><?php esc_html_e( 'A robots.txt file on disk is served directly by the web server, and the web server does not have permission to delete it.', 'twt-aeo-ultimate' ); ?></p>
									<p style="margin-top:6px;">
										<strong><?php esc_html_e( 'Recommended:', 'twt-aeo-ultimate' ); ?></strong>
										<?php esc_html_e( 'Delete robots.txt manually via FTP or your host file manager so it is served dynamically (best for tracking), or click Inject to at least write Content-Signal into the existing file.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php $robots_snippet = TWTAEO_AI_Ready::get_robots_signal_snippet(); ?>
									<?php if ( '' !== $robots_snippet ) : ?>
									<details style="margin-top:6px;">
										<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Can’t delete it either? Add this to robots.txt by hand', 'twt-aeo-ultimate' ); ?></summary>
										<div style="margin-top:6px;">
											<p><?php esc_html_e( 'Open robots.txt in your host file manager and append the lines below. If a "User-agent: *" or "Sitemap:" line already exists, merge into it rather than duplicating it:', 'twt-aeo-ultimate' ); ?></p>
											<textarea readonly rows="4" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;" onclick="this.select();"><?php echo esc_textarea( $robots_snippet ); ?></textarea>
										</div>
									</details>
									<?php endif; ?>
								</div>
								<?php endif; ?>

								<?php if ( $robots_physical ) : ?>
								<div class="twt-aeo-air-robots-notice__actions">
									<?php if ( $robots_writable ) : ?>
									<button type="button" id="twt-aeo-remove-robots" class="button button-primary"
										data-nonce="<?php echo esc_attr( $robots_nonce ); ?>">
										<?php esc_html_e( 'Remove physical robots.txt (serve dynamically)', 'twt-aeo-ultimate' ); ?>
									</button>
									<?php endif; ?>
									<button type="button" id="twt-aeo-inject-robots" class="button"
										data-nonce="<?php echo esc_attr( $robots_nonce ); ?>">
										<?php esc_html_e( 'Inject into robots.txt', 'twt-aeo-ultimate' ); ?>
									</button>
									<?php if ( $has_yoast ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpseo_tools&tool=file-editor' ) ); ?>" class="button" target="_blank" rel="noopener">
										<?php esc_html_e( 'Open Yoast File Editor', 'twt-aeo-ultimate' ); ?>
									</a>
									<?php endif; ?>
									<?php if ( $has_rm ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=rank-math-general' ) ); ?>" class="button" target="_blank" rel="noopener">
										<?php esc_html_e( 'Open Rank Math Settings', 'twt-aeo-ultimate' ); ?>
									</a>
									<?php endif; ?>
									<span id="twt-aeo-inject-status" style="margin-left:10px;"></span>
								</div>
								<?php endif; ?>
							</div>
						</div>

						<!-- Semantic Breadcrumbs -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Semantic Breadcrumbs', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Injects a BreadcrumbList JSON-LD block into every non-homepage page. This gives AI agents an explicit map of where the current page sits within your site hierarchy — improving machine traversal, navigation inference, and citation accuracy.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Semantic Breadcrumbs', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[semantic_breadcrumbs]" value="1" <?php checked( ! empty( $s['semantic_breadcrumbs'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- Link Headers (RFC 8288) -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'Link Headers (RFC 8288)', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-badge twt-aeo-air-badge--green"><?php esc_html_e( 'Always On', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Adds RFC 8288 Link response headers pointing AI agents to your site\'s discovery resources (sitemap, llms.txt, API catalog, and more). Agents that cannot crawl your entire site use these headers to orient themselves instantly from any page, including your homepage.', 'twt-aeo-ultimate' ); ?></p>
									<?php
									$link_headers = array();
									$sitemap_lh   = TWTAEO_AI_Ready::get_sitemap_url() ?: home_url( '/sitemap.xml' );
									$link_headers[] = 'Link: &lt;' . esc_html( $sitemap_lh ) . '&gt;; rel="sitemap"';
									if ( ! empty( $s['llms_txt'] ) ) {
										$link_headers[] = 'Link: &lt;' . esc_html( home_url( '/llms.txt' ) ) . '&gt;; rel="describedby"; type="text/plain"';
									}
									$link_headers[] = 'Link: &lt;' . esc_html( home_url( '/.well-known/api-catalog' ) ) . '&gt;; rel="api-catalog"';
									if ( ! empty( $s['agent_skills_index'] ) ) {
										$link_headers[] = 'Link: &lt;' . esc_html( home_url( '/.well-known/agent-skills/index.json' ) ) . '&gt;; rel="https://isitagentready.com/rel/agent-skills"';
									}
									if ( ! empty( $s['mcp_integration'] ) ) {
										$link_headers[] = 'Link: &lt;' . esc_html( home_url( '/.well-known/mcp/server-card.json' ) ) . '&gt;; rel="mcp-server-card"';
									}
									?>
									<p class="twt-aeo-air-feature-card__endpoint" style="margin-top:8px;display:block;">
										<span class="dashicons dashicons-info-outline"></span>
										<?php esc_html_e( 'Currently sending:', 'twt-aeo-ultimate' ); ?>
										<?php foreach ( $link_headers as $lh ) : ?>
										<br><code style="font-size:11px;"><?php echo wp_kses( $lh, array() ); ?></code>
										<?php endforeach; ?>
									</p>
								</div>
							</div>
						</div>

						<!-- Vary Header -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Vary: Accept Header', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Instructs CDNs (Cloudflare, WP Rocket, etc.) to cache separate versions of each page based on the Accept header. Without this, a CDN might serve a human a cached Markdown page, or serve an AI a cached HTML page. Enable this whenever Markdown Negotiation is active.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Vary Header', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[vary_header]" value="1" <?php checked( ! empty( $s['vary_header'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

					</div><!-- /.twt-aeo-air-feature-list -->
				</section>

				<!-- ── Section 2: Advanced Features ──────────────────────────── -->
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title">
						<span class="dashicons dashicons-controls-play" style="color:var(--aeo-warning);font-size:16px;width:16px;height:16px;"></span>
						<?php esc_html_e( 'Advanced Agent Interaction', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-air-badge twt-aeo-air-badge--yellow"><?php esc_html_e( 'Toggle Required', 'twt-aeo-ultimate' ); ?></span>
					</h2>
					<p class="twt-aeo-air-section-desc"><?php esc_html_e( 'These features allow AI agents to query your site programmatically. They are off by default. Enable them only when you want AI tools to be able to search or interact with your content via API.', 'twt-aeo-ultimate' ); ?></p>

					<div class="twt-aeo-air-security-callout">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong><?php esc_html_e( 'Security Notice — Read Before Enabling', 'twt-aeo-ultimate' ); ?></strong>
							<?php esc_html_e( 'High-level capabilities such as MCP and OAuth allow AI agents to interact with your site — not just read it. Enable IP Whitelisting under Security & Rate Limiting before turning on any interactive features. Never grant an AI agent admin-level or write permissions.', 'twt-aeo-ultimate' ); ?>
						</div>
					</div>

					<div class="twt-aeo-air-feature-list">

						<!-- Agent Search API -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Agent Search API', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Creates a dedicated REST endpoint that AI agents can use to search your site content. Restricted to GET requests only. Returns post title, excerpt, and permalink — no private data is ever exposed. Rate limiting applies automatically.', 'twt-aeo-ultimate' ); ?></p>
									<?php if ( ! empty( $s['agent_search_api'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<code>GET <?php echo esc_html( rest_url( 'aeo/v1/search?q={query}' ) ); ?></code>
									</p>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Agent Search API', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[agent_search_api]" value="1" <?php checked( ! empty( $s['agent_search_api'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- API Catalog -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'API Catalog (RFC 9727)', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-benchmark twt-aeo-air-benchmark--orange"><?php esc_html_e( 'Capabilities Score', 'twt-aeo-ultimate' ); ?></span>
										<span class="twt-aeo-air-badge twt-aeo-air-badge--green"><?php esc_html_e( 'Always On', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Publishes a machine-readable directory of available site APIs at the RFC 9727 standard endpoint. The WordPress REST API is always listed. Enable the toggle to also include AEO-specific endpoints (Agent Search, MCP) in the catalog.', 'twt-aeo-ultimate' ); ?></p>
									<details class="twt-aeo-air-why">
										<summary><?php esc_html_e( 'Why this matters', 'twt-aeo-ultimate' ); ?></summary>
										<div class="twt-aeo-air-why__body">
											<p><?php esc_html_e( 'Most websites are passive — agents can read them, but cannot interact with them. An API Catalog standardizes your site\'s functional endpoints (Search, Contact, Services) into a format agents can use to perform actions, not just read text.', 'twt-aeo-ultimate' ); ?></p>
											<p><?php esc_html_e( 'Cloudflare\'s Capabilities Score measures how well your site can be acted upon by an AI agent. A site with a published API catalog signals to agents that it is a first-class participant in the agentic web — not just a document to be scraped.', 'twt-aeo-ultimate' ); ?></p>
										</div>
									</details>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/api-catalog' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/api-catalog' ) ); ?></a>
									</p>
									<?php if ( $wk['catalog_exists'] ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-success);">
										<span class="dashicons dashicons-yes-alt"></span>
										<?php esc_html_e( 'Physical file written and ready.', 'twt-aeo-ultimate' ); ?>
										<?php if ( $wk['catalog_perms'] && $wk['catalog_perms'] !== '0644' ) : ?>
										<strong style="color:var(--aeo-warning);">
											<?php
							// translators: %s: file permissions string (e.g. '0755').
							printf( esc_html__( 'File permissions are %s — set to 644 in cPanel for web server access.', 'twt-aeo-ultimate' ), esc_html( $wk['catalog_perms'] ) ); ?>
										</strong>
										<?php endif; ?>
									</p>
									<?php else : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-warning);">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'Physical file not yet written — save settings to generate it, or the dynamic handler will respond.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Include AEO endpoints in catalog', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[api_catalog]" value="1" <?php checked( ! empty( $s['api_catalog'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- MCP Integration -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'MCP Integration (WebMCP)', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Exposes a Model Context Protocol (MCP) tool manifest endpoint. This allows advanced AI agents (like Claude) to discover and use your site\'s tools as if they were built-in browser functions. The manifest describes available tools, their parameters, and how to call them.', 'twt-aeo-ultimate' ); ?></p>
									<?php if ( ! empty( $s['mcp_integration'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<code>GET <?php echo esc_html( rest_url( 'aeo/v1/mcp' ) ); ?></code>
									</p>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/mcp/server-card.json' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/mcp/server-card.json' ) ); ?></a>
									</p>
									<?php if ( $wk['mcp_card_exists'] ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-success);">
										<span class="dashicons dashicons-yes-alt"></span>
										<?php esc_html_e( 'MCP Server Card written and ready.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php else : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-warning);">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'Server card not yet written — save settings to generate it.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php endif; ?>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle MCP Integration', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[mcp_integration]" value="1" <?php checked( ! empty( $s['mcp_integration'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

					</div><!-- /.twt-aeo-air-feature-list -->
				</section>

				<!-- ── Section 3: Security ────────────────────────────────────── -->
				<section class="twt-aeo-section" id="twt-aeo-rate-limiting">
					<h2 class="twt-aeo-section__title">
						<span class="dashicons dashicons-shield" style="color:var(--aeo-danger);font-size:16px;width:16px;height:16px;"></span>
						<?php esc_html_e( 'Security & Rate Limiting', 'twt-aeo-ultimate' ); ?>
						<span class="twt-aeo-air-badge twt-aeo-air-badge--red"><?php esc_html_e( 'Security Sensitive', 'twt-aeo-ultimate' ); ?></span>
					</h2>
					<p class="twt-aeo-air-section-desc"><?php esc_html_e( 'These settings protect your agent-facing endpoints from abuse. Rate limiting and IP validation are recommended whenever any Advanced feature is enabled.', 'twt-aeo-ultimate' ); ?></p>

					<div class="twt-aeo-air-feature-list">

						<!-- Rate Limiting -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Rate Limiting', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Prevents "agent crawl storms" — situations where automated bots hammer your REST endpoints and exhaust server resources. Uses WordPress transients to track and throttle requests per IP per hour. Once the limit is reached, the endpoint returns a 429 Too Many Requests response.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Rate Limiting', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[rate_limit_enabled]" value="1" id="twt-air-rate-limit" <?php checked( ! empty( $s['rate_limit_enabled'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
							<div class="twt-aeo-air-feature-card__sub twt-aeo-air-dependent" data-depends="twt-air-rate-limit" <?php echo empty( $s['rate_limit_enabled'] ) ? 'style="display:none;"' : ''; ?>>
								<label class="twt-aeo-air-number-row">
									<span><?php esc_html_e( 'Global maximum requests per IP per hour:', 'twt-aeo-ultimate' ); ?></span>
									<input type="number" name="twtaeo_air[rate_limit_max]" value="<?php echo esc_attr( $s['rate_limit_max'] ); ?>" min="1" max="10000" class="small-text" />
								</label>
							</div>

							<!-- Per-bot controls -->
							<?php
							$known_bots    = TWTAEO_AI_Ready::get_known_bots();
							$bot_rules_map = $s['bot_rules'] ?? array();
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
								'Huawei'       => '#cf0a2c',
								'Webz.io'      => '#64748b',
								'iAsk.ai'      => '#0ea5e9',
							);
							?>
							<div style="margin-top:20px;border-top:1px solid #e0e0e0;padding-top:16px;">
								<p style="margin:0 0 4px;font-weight:600;font-size:13px;"><?php esc_html_e( 'Per-Bot Controls', 'twt-aeo-ultimate' ); ?></p>
								<p class="description" style="margin:0 0 12px;"><?php esc_html_e( 'Block specific crawlers outright or cap their rate below the global limit. "Block" writes Disallow to robots.txt and returns 403 on any request. A per-bot limit overrides the global cap for that crawler only.', 'twt-aeo-ultimate' ); ?></p>
								<table class="wp-list-table widefat fixed striped" style="table-layout:fixed;">
									<colgroup>
										<col style="width:34%;">
										<col style="width:18%;">
										<col style="width:22%;">
										<col style="width:26%;">
									</colgroup>
									<thead>
										<tr>
											<th style="padding:8px 12px;font-size:12px;"><?php esc_html_e( 'Bot', 'twt-aeo-ultimate' ); ?></th>
											<th style="padding:8px 12px;font-size:12px;"><?php esc_html_e( 'Company', 'twt-aeo-ultimate' ); ?></th>
											<th style="padding:8px 12px;font-size:12px;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
											<th style="padding:8px 12px;font-size:12px;"><?php esc_html_e( 'Rate limit / hr', 'twt-aeo-ultimate' ); ?></th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $known_bots as $bot_slug => $bot_info ) :
										$rule       = $bot_rules_map[ $bot_slug ] ?? array();
										$status     = $rule['status'] ?? 'allow';
										$bot_rl     = $rule['rate_limit'] ?? '';
										$color      = $company_colors[ $bot_info['company'] ] ?? '#888';
										$field_base = 'twtaeo_air[bot_rules][' . esc_attr( $bot_slug ) . ']';
									?>
										<tr>
											<td style="padding:8px 12px;vertical-align:middle;">
												<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;margin-right:5px;vertical-align:middle;flex-shrink:0;"></span>
												<strong style="font-family:monospace;font-size:12px;"><?php echo esc_html( $bot_slug ); ?></strong>
												<br><span style="font-size:11px;color:#888;"><?php echo esc_html( $bot_info['desc'] ); ?></span>
											</td>
											<td style="padding:8px 12px;vertical-align:middle;font-size:12px;color:#555;">
												<?php echo esc_html( $bot_info['company'] ); ?>
											</td>
											<td style="padding:8px 12px;vertical-align:middle;">
												<select name="<?php echo esc_attr( $field_base ); ?>[status]" style="font-size:12px;<?php echo $status === 'block' ? 'color:#b32d2e;font-weight:600;' : ''; ?>">
													<option value="allow" <?php selected( $status, 'allow' ); ?>><?php esc_html_e( 'Allow', 'twt-aeo-ultimate' ); ?></option>
													<option value="block" <?php selected( $status, 'block' ); ?> style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Block', 'twt-aeo-ultimate' ); ?></option>
												</select>
											</td>
											<td style="padding:8px 12px;vertical-align:middle;">
												<input
													type="number"
													name="<?php echo esc_attr( $field_base ); ?>[rate_limit]"
													value="<?php echo esc_attr( $bot_rl ); ?>"
													min="1" max="10000"
													class="small-text"
													placeholder="<?php echo esc_attr( $s['rate_limit_max'] ); ?>"
													style="width:60px;font-size:12px;"
												>
												<span style="font-size:11px;color:#888;margin-left:4px;"><?php esc_html_e( '(blank = global)', 'twt-aeo-ultimate' ); ?></span>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>

						<!-- IP Whitelist -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'IP Whitelist', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Restricts access to AEO agent endpoints to a known list of IP addresses. Useful for locking down your APIs to specific AI provider IP ranges (OpenAI, Anthropic, Google publish these) or your own testing infrastructure. All other IPs receive a 403 Forbidden response.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle IP Whitelist', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[ip_whitelist_enabled]" value="1" id="twt-air-ip-whitelist" <?php checked( ! empty( $s['ip_whitelist_enabled'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
							<div class="twt-aeo-air-feature-card__sub twt-aeo-air-dependent" data-depends="twt-air-ip-whitelist" <?php echo empty( $s['ip_whitelist_enabled'] ) ? 'style="display:none;"' : ''; ?>>
								<label>
									<span class="twt-aeo-air-sub-label"><?php esc_html_e( 'Allowed IPs (one per line):', 'twt-aeo-ultimate' ); ?></span>
									<textarea name="twtaeo_air[ip_whitelist]" rows="5" class="large-text code" placeholder="203.0.113.0&#10;2001:db8::"><?php echo esc_textarea( $s['ip_whitelist'] ); ?></textarea>
								</label>
							</div>
						</div>

						<!-- User-Agent Verification -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'User-Agent Verification', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Cross-references the User-Agent string of incoming requests against a list of known legitimate AI bots (ClaudeBot, GPTBot, Googlebot, PerplexityBot, etc.). Requests from unknown agents receive a 403 response. Note: User-Agent spoofing is trivial — use IP Whitelist for stronger protection.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle User-Agent Verification', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[user_agent_verification]" value="1" <?php checked( ! empty( $s['user_agent_verification'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

						<!-- Web Bot Auth / Identity Verification -->
						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title">
										<?php esc_html_e( 'Web Bot Auth (JWKS Directory)', 'twt-aeo-ultimate' ); ?>
										<span class="twt-aeo-air-badge twt-aeo-air-badge--green"><?php esc_html_e( 'Always On', 'twt-aeo-ultimate' ); ?></span>
									</h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Publishes this site\'s public signing key at /.well-known/http-message-signatures-directory as a JWKS (JSON Web Key Set). Receiving sites can use this key to verify that requests originated here — a requirement for Web Bot Auth compliance and agent-to-agent trust.', 'twt-aeo-ultimate' ); ?></p>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/http-message-signatures-directory' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/http-message-signatures-directory' ) ); ?></a>
									</p>
									<?php
									$key_data = TWTAEO_AI_Ready::get_or_create_signing_key();
									if ( $key_data && ! empty( $key_data['jwk']['kid'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="margin-top:4px;">
										<span class="dashicons dashicons-yes-alt" style="color:var(--aeo-success);"></span>
										<?php
										printf(
											/* translators: key ID */
											esc_html__( 'EC P-256 signing key active. Key ID: %s', 'twt-aeo-ultimate' ),
											'<code>' . esc_html( $key_data['jwk']['kid'] ) . '</code>'
										);
										?>
									</p>
									<?php elseif ( ! function_exists( 'openssl_pkey_new' ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-warning);margin-top:4px;">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'OpenSSL PHP extension not available — key generation skipped. Contact your host to enable the openssl extension.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- Path Diagnostic -->
						<div class="twt-aeo-air-feature-card" id="twt-aeo-path-diagnostic-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Identity Verification — Path Diagnostic', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'Test whether the /.well-known/http-message-signatures-directory path is publicly reachable. If you\'re getting 403 or 404 errors, this tool detects your server type and gives targeted fix instructions.', 'twt-aeo-ultimate' ); ?></p>
								</div>
								<button type="button" id="twt-aeo-run-diagnostic" class="button button-secondary"
									data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_AI_Ready::NONCE_DIAGNOSE ) ); ?>">
									<?php esc_html_e( 'Run Path Diagnostic', 'twt-aeo-ultimate' ); ?>
								</button>
							</div>

							<div id="twt-aeo-diagnostic-spinner" style="display:none;margin-top:12px;">
								<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
								<span style="font-size:13px;color:var(--aeo-text-muted);"><?php esc_html_e( 'Running diagnostic…', 'twt-aeo-ultimate' ); ?></span>
							</div>

							<div id="twt-aeo-diagnostic-results" style="display:none;margin-top:16px;">
								<div id="twt-aeo-diag-badges" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center;"></div>
								<div id="twt-aeo-diag-instructions"></div>
								<div id="twt-aeo-apache-fix-wrap" style="display:none;margin-top:12px;">
									<button type="button" id="twt-aeo-apply-apache-fix" class="button button-primary"
										data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_AI_Ready::NONCE_DIAGNOSE ) ); ?>">
										<?php esc_html_e( 'Apply Apache Fix', 'twt-aeo-ultimate' ); ?>
									</button>
									<span id="twt-aeo-apache-fix-status" style="margin-left:10px;font-size:13px;"></span>
								</div>
							</div>
						</div>

						<!-- OAuth / OIDC Discovery -->
						<?php
						$oauth_mode    = $s['oauth_mode'] ?? 'builtin';
						$oauth_clients = TWTAEO_OAuth_Server::get_clients();
						?>

						<div class="twt-aeo-air-feature-card">
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'OAuth / OIDC Discovery Metadata', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc">
										<?php esc_html_e( 'Publishes OAuth 2.0 Authorization Server Metadata (RFC 8414) at /.well-known/oauth-authorization-server so AI agents can discover how to authenticate before calling protected APIs. Use the Built-in server for a zero-config client credentials flow, or point to your own OAuth provider.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php if ( ! empty( $s['oauth_discovery'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/oauth-authorization-server' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></a>
									</p>
									<?php if ( $wk['oauth_exists'] ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-success);">
										<span class="dashicons dashicons-yes-alt"></span>
										<?php esc_html_e( 'Physical file written and ready.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php else : ?>
									<p class="twt-aeo-air-feature-card__endpoint" style="color:var(--aeo-warning);">
										<span class="dashicons dashicons-warning"></span>
										<?php esc_html_e( 'File not yet written — save settings to generate it.', 'twt-aeo-ultimate' ); ?>
									</p>
									<?php endif; ?>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle OAuth Discovery', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[oauth_discovery]" value="1" id="twt-air-oauth-discovery" <?php checked( ! empty( $s['oauth_discovery'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>

							<div class="twt-aeo-air-feature-card__sub twt-aeo-air-dependent" data-depends="twt-air-oauth-discovery" <?php echo empty( $s['oauth_discovery'] ) ? 'style="display:none;"' : ''; ?>>

								<!-- Mode selector -->
								<div style="display:flex;gap:20px;margin-bottom:16px;">
									<label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;">
										<input type="radio" name="twtaeo_air[oauth_mode]" value="builtin" id="twt-air-oauth-builtin" <?php checked( $oauth_mode, 'builtin' ); ?> />
										<?php esc_html_e( 'Built-in OAuth server (recommended)', 'twt-aeo-ultimate' ); ?>
									</label>
									<label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;">
										<input type="radio" name="twtaeo_air[oauth_mode]" value="external" id="twt-air-oauth-external" <?php checked( $oauth_mode, 'external' ); ?> />
										<?php esc_html_e( 'External OAuth provider', 'twt-aeo-ultimate' ); ?>
									</label>
								</div>

								<!-- Built-in mode panel -->
								<div id="twt-aeo-oauth-builtin-panel" <?php echo $oauth_mode !== 'builtin' ? 'style="display:none;"' : ''; ?>>
									<div class="twt-aeo-air-oauth-info-row">
										<span class="twt-aeo-air-oauth-info-label"><?php esc_html_e( 'Token Endpoint', 'twt-aeo-ultimate' ); ?></span>
										<code id="twt-aeo-token-ep"><?php echo esc_html( rest_url( 'aeo/v1/oauth/token' ) ); ?></code>
										<button type="button" class="button button-small twt-aeo-copy-btn" data-target="twt-aeo-token-ep"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
									</div>
									<div class="twt-aeo-air-oauth-info-row">
										<span class="twt-aeo-air-oauth-info-label"><?php esc_html_e( 'JWKS URI', 'twt-aeo-ultimate' ); ?></span>
										<code id="twt-aeo-jwks-ep"><?php echo esc_html( rest_url( 'aeo/v1/oauth/jwks' ) ); ?></code>
										<button type="button" class="button button-small twt-aeo-copy-btn" data-target="twt-aeo-jwks-ep"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
									</div>
									<div class="twt-aeo-air-oauth-info-row">
										<span class="twt-aeo-air-oauth-info-label"><?php esc_html_e( 'Grant Type', 'twt-aeo-ultimate' ); ?></span>
										<code>client_credentials</code>
									</div>

									<!-- Client list -->
									<div style="margin-top:20px;">
										<strong style="font-size:13px;"><?php esc_html_e( 'OAuth Clients', 'twt-aeo-ultimate' ); ?></strong>
										<?php if ( ! empty( $oauth_clients ) ) : ?>
										<table class="twt-aeo-oauth-client-table">
											<thead>
												<tr>
													<th><?php esc_html_e( 'Name', 'twt-aeo-ultimate' ); ?></th>
													<th><?php esc_html_e( 'Client ID', 'twt-aeo-ultimate' ); ?></th>
													<th><?php esc_html_e( 'Scopes', 'twt-aeo-ultimate' ); ?></th>
													<th><?php esc_html_e( 'Created', 'twt-aeo-ultimate' ); ?></th>
													<th></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $oauth_clients as $cid => $client ) : ?>
												<tr>
													<td><?php echo esc_html( $client['name'] ); ?></td>
													<td><code><?php echo esc_html( $cid ); ?></code></td>
													<td><?php echo esc_html( implode( ', ', $client['scopes'] ?? array() ) ); ?></td>
													<td><?php echo esc_html( isset( $client['created_at'] ) ? date_i18n( get_option( 'date_format' ), $client['created_at'] ) : '—' ); ?></td>
													<td>
														<button type="button" class="button button-small twt-aeo-revoke-client"
															data-client-id="<?php echo esc_attr( $cid ); ?>"
															data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_OAuth_Server::NONCE_REVOKE ) ); ?>"
															data-confirm="<?php esc_attr_e( 'Revoke this client? All its tokens will stop working immediately.', 'twt-aeo-ultimate' ); ?>">
															<?php esc_html_e( 'Revoke', 'twt-aeo-ultimate' ); ?>
														</button>
													</td>
												</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
										<?php else : ?>
										<p style="margin:10px 0 0;font-size:13px;color:var(--aeo-text-muted);"><?php esc_html_e( 'No clients yet. Create one below.', 'twt-aeo-ultimate' ); ?></p>
										<?php endif; ?>
									</div>

									<!-- Create client form -->
									<div class="twt-aeo-oauth-create-form">
										<strong style="font-size:13px;display:block;margin-bottom:10px;"><?php esc_html_e( 'Add New Client', 'twt-aeo-ultimate' ); ?></strong>
										<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
											<div>
												<label style="display:block;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Client Name', 'twt-aeo-ultimate' ); ?></label>
												<input type="text" id="twt-aeo-new-client-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. My AI Agent', 'twt-aeo-ultimate' ); ?>" style="width:220px;" />
											</div>
											<div>
												<label style="display:block;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Scopes', 'twt-aeo-ultimate' ); ?></label>
												<label style="font-size:13px;margin-right:12px;">
													<input type="checkbox" class="twt-aeo-new-client-scope" value="read" checked /> <?php esc_html_e( 'read', 'twt-aeo-ultimate' ); ?>
												</label>
												<label style="font-size:13px;">
													<input type="checkbox" class="twt-aeo-new-client-scope" value="search" /> <?php esc_html_e( 'search', 'twt-aeo-ultimate' ); ?>
												</label>
											</div>
											<button type="button" class="button button-primary" id="twt-aeo-create-client-btn"
												data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_OAuth_Server::NONCE_CREATE ) ); ?>">
												<?php esc_html_e( 'Create Client', 'twt-aeo-ultimate' ); ?>
											</button>
										</div>
										<p style="margin:8px 0 0;font-size:12px;color:var(--aeo-text-muted);"><?php esc_html_e( 'The client secret is shown once immediately after creation — copy it right away.', 'twt-aeo-ultimate' ); ?></p>
									</div>
								</div>

								<!-- External mode panel -->
								<div id="twt-aeo-oauth-external-panel" <?php echo $oauth_mode !== 'external' ? 'style="display:none;"' : ''; ?>>
									<table class="form-table" style="margin:0;">
										<tr>
											<th style="width:220px;padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'Issuer URL', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="url" name="twtaeo_air[oauth_issuer]" value="<?php echo esc_attr( $s['oauth_issuer'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url() ); ?>" />
												<p class="description"><?php esc_html_e( 'The issuer identifier. Defaults to your site URL if left blank.', 'twt-aeo-ultimate' ); ?></p>
											</td>
										</tr>
										<tr>
											<th style="padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'Authorization Endpoint', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="url" name="twtaeo_air[oauth_auth_endpoint]" value="<?php echo esc_attr( $s['oauth_auth_endpoint'] ); ?>" class="regular-text" placeholder="https://example.com/oauth/authorize" />
												<p class="description"><?php esc_html_e( 'Where agents redirect users to obtain authorization codes. Leave blank for client_credentials-only flows.', 'twt-aeo-ultimate' ); ?></p>
											</td>
										</tr>
										<tr>
											<th style="padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'Token Endpoint', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="url" name="twtaeo_air[oauth_token_endpoint]" value="<?php echo esc_attr( $s['oauth_token_endpoint'] ); ?>" class="regular-text" placeholder="https://example.com/oauth/token" />
											</td>
										</tr>
										<tr>
											<th style="padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'JWKS URI', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="url" name="twtaeo_air[oauth_jwks_uri]" value="<?php echo esc_attr( $s['oauth_jwks_uri'] ); ?>" class="regular-text" placeholder="https://example.com/oauth/jwks" />
											</td>
										</tr>
										<tr>
											<th style="padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'Grant Types', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="text" name="twtaeo_air[oauth_grant_types]" value="<?php echo esc_attr( $s['oauth_grant_types'] ); ?>" class="regular-text" placeholder="authorization_code,client_credentials" />
											</td>
										</tr>
										<tr>
											<th style="padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'Scopes', 'twt-aeo-ultimate' ); ?></th>
											<td style="padding:8px 0;">
												<input type="text" name="twtaeo_air[oauth_scopes]" value="<?php echo esc_attr( $s['oauth_scopes'] ); ?>" class="regular-text" placeholder="read,write" />
											</td>
										</tr>
									</table>
								</div>

								<!-- OIDC sub-toggle (both modes) -->
								<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--aeo-border);">
									<label class="twt-aeo-air-sub-toggle">
										<input type="checkbox" name="twtaeo_air[oauth_oidc]" value="1" id="twt-air-oauth-oidc" <?php checked( ! empty( $s['oauth_oidc'] ) ); ?> />
										<span><?php esc_html_e( 'Also publish OpenID Connect Discovery', 'twt-aeo-ultimate' ); ?> <code>/.well-known/openid-configuration</code></span>
									</label>
									<p class="twt-aeo-air-sub-desc"><?php esc_html_e( 'Writes a second physical file for OIDC Discovery 1.0. Required for OpenID Connect flows.', 'twt-aeo-ultimate' ); ?></p>
									<div class="twt-aeo-air-dependent" data-depends="twt-air-oauth-oidc" <?php echo empty( $s['oauth_oidc'] ) ? 'style="display:none;"' : ''; ?>>
										<?php if ( $oauth_mode === 'external' ) : ?>
										<table class="form-table" style="margin:8px 0 0;">
											<tr>
												<th style="width:220px;padding:8px 10px 8px 0;font-size:13px;"><?php esc_html_e( 'UserInfo Endpoint', 'twt-aeo-ultimate' ); ?></th>
												<td style="padding:8px 0;">
													<input type="url" name="twtaeo_air[oauth_userinfo_endpoint]" value="<?php echo esc_attr( $s['oauth_userinfo_endpoint'] ); ?>" class="regular-text" placeholder="https://example.com/oauth/userinfo" />
												</td>
											</tr>
										</table>
										<?php endif; ?>
										<?php if ( ! empty( $s['oauth_discovery'] ) && ! empty( $s['oauth_oidc'] ) ) : ?>
										<p class="twt-aeo-air-feature-card__endpoint" style="margin-top:8px;<?php echo $wk['oidc_exists'] ? 'color:var(--aeo-success);' : 'color:var(--aeo-warning);'; ?>">
											<span class="dashicons <?php echo $wk['oidc_exists'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
											<?php echo $wk['oidc_exists'] ? esc_html__( 'openid-configuration file written.', 'twt-aeo-ultimate' ) : esc_html__( 'openid-configuration file not yet written — save to generate.', 'twt-aeo-ultimate' ); ?>
										</p>
										<?php endif; ?>
									</div>
								</div>

							</div>
						</div>

						<!-- Protected Resource Metadata (OIDC) — danger zone -->
						<div class="twt-aeo-air-feature-card twt-aeo-air-feature-card--danger">
							<div class="twt-aeo-air-danger-banner">
								<span class="dashicons dashicons-warning"></span>
								<strong><?php esc_html_e( 'Security Warning', 'twt-aeo-ultimate' ); ?></strong>
								<?php esc_html_e( 'This feature enables AI agents to request access to private or gated content on your site via OAuth/OIDC. Read carefully before enabling.', 'twt-aeo-ultimate' ); ?>
							</div>
							<div class="twt-aeo-air-feature-card__header">
								<div class="twt-aeo-air-feature-card__info">
									<h3 class="twt-aeo-air-feature-card__title"><?php esc_html_e( 'Protected Resource Metadata (RFC 9728 / OIDC)', 'twt-aeo-ultimate' ); ?></h3>
									<p class="twt-aeo-air-feature-card__desc"><?php esc_html_e( 'For sites with private areas (WooCommerce, Membership plugins), this publishes OAuth metadata at /.well-known/oauth-protected-resource describing how AI agents should request access securely. The plugin only exposes read-only scope metadata. The OAuth flow itself must be configured separately by an expert.', 'twt-aeo-ultimate' ); ?></p>
									<p class="twt-aeo-air-feature-card__desc" style="color:var(--aeo-danger);"><strong><?php esc_html_e( 'Recommendation: Leave off unless you have a specific OAuth/OIDC integration configured and understand the implications. Never grant admin or write permissions to an AI agent.', 'twt-aeo-ultimate' ); ?></strong></p>
									<?php if ( ! empty( $s['protected_resource_meta'] ) ) : ?>
									<p class="twt-aeo-air-feature-card__endpoint">
										<span class="dashicons dashicons-admin-links"></span>
										<a href="<?php echo esc_url( home_url( '/.well-known/oauth-protected-resource' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></a>
									</p>
									<?php endif; ?>
								</div>
								<label class="twt-aeo-toggle" title="<?php esc_attr_e( 'Toggle Protected Resource Metadata', 'twt-aeo-ultimate' ); ?>">
									<input type="checkbox" class="twt-aeo-toggle__input" name="twtaeo_air[protected_resource_meta]" value="1" <?php checked( ! empty( $s['protected_resource_meta'] ) ); ?> />
									<span class="twt-aeo-toggle__track"></span>
									<span class="twt-aeo-toggle__thumb"></span>
								</label>
							</div>
						</div>

					</div><!-- /.twt-aeo-air-feature-list -->
				</section>

				<?php submit_button( __( 'Save AI Ready Settings', 'twt-aeo-ultimate' ) ); ?>

			</form>

			<?php
			ob_start();
			?>
			(function() {
				var adminPostUrl = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;

				// Revoke client buttons.
				document.addEventListener( 'click', function( e ) {
					var btn = e.target.closest( '.twt-aeo-revoke-client' );
					if ( ! btn ) return;
					if ( ! confirm( btn.dataset.confirm ) ) return;
					var body = new URLSearchParams();
					body.set( 'action', 'twtaeo_oauth_revoke_client' );
					body.set( '_wpnonce', btn.dataset.nonce );
					body.set( 'client_id', btn.dataset.clientId );
					fetch( adminPostUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function() { location.reload(); } );
				} );

				// Create client button.
				var createBtn = document.getElementById( 'twt-aeo-create-client-btn' );
				if ( createBtn ) {
					createBtn.addEventListener( 'click', function() {
						var name = document.getElementById( 'twt-aeo-new-client-name' ).value.trim();
						if ( ! name ) {
							alert( <?php echo wp_json_encode( __( 'Please enter a client name.', 'twt-aeo-ultimate' ) ); ?> );
							return;
						}
						var scopes = Array.from( document.querySelectorAll( '.twt-aeo-new-client-scope:checked' ) )
							.map( function( cb ) { return cb.value; } );
						var body = new URLSearchParams();
						body.set( 'action', 'twtaeo_oauth_create_client' );
						body.set( '_wpnonce', createBtn.dataset.nonce );
						body.set( 'client_name', name );
						scopes.forEach( function( s ) { body.append( 'client_scopes[]', s ); } );
						fetch( adminPostUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
							.then( function( r ) { return r.url; } )
							.then( function( url ) { location.href = url; } );
					} );
				}
			})();
			<?php
			$js = ob_get_clean();
			wp_add_inline_script( 'twt-aeo-admin', $js );
			?>
		</div>
		<?php
	}

	private static function handle_save() {
		// Two independent gates, both required, neither able to satisfy the other:
		// capability first (who you are), then nonce (that this specific request came
		// from our form). Kept inside the handler — not relying solely on the caller —
		// so the save can never run for an under-privileged or forged request.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'saved' => false, 'flushed' => false );
		}
		if ( ! check_admin_referer( TWTAEO_AI_Ready::NONCE_ACTION, TWTAEO_AI_Ready::NONCE_NAME ) ) {
			return array( 'saved' => false, 'flushed' => false );
		}

		$posted  = isset( $_POST['twtaeo_air'] ) ? map_deep( wp_unslash( $_POST['twtaeo_air'] ), 'sanitize_text_field' ) : array();
		$updated = array();

		// Booleans.
		$bool_keys = array(
			'markdown_negotiation', 'url_fallback', 'llms_txt', 'agent_skills_index',
			'semantic_breadcrumbs', 'vary_header', 'agent_search_api', 'api_catalog',
			'mcp_integration', 'rate_limit_enabled', 'ip_whitelist_enabled',
			'user_agent_verification', 'identity_verification', 'protected_resource_meta',
			'robots_bot_rules', 'oauth_discovery', 'oauth_oidc',
		);
		foreach ( $bool_keys as $key ) {
			$updated[ $key ] = ! empty( $posted[ $key ] ) ? 1 : 0;
		}

		// Enums.
		$valid_ai_train = array( 'no', 'yes', 'unspecified' );
		$updated['content_signal_ai_train'] = in_array( $posted['content_signal_ai_train'] ?? '', $valid_ai_train, true )
			? $posted['content_signal_ai_train']
			: 'no';

		$valid_search = array( 'yes', 'no', 'unspecified' );
		$updated['content_signal_search'] = in_array( $posted['content_signal_search'] ?? '', $valid_search, true )
			? $posted['content_signal_search']
			: 'yes';

		$valid_ai_input = array( 'no', 'yes', 'unspecified' );
		$updated['content_signal_ai_input'] = in_array( $posted['content_signal_ai_input'] ?? '', $valid_ai_input, true )
			? $posted['content_signal_ai_input']
			: 'no';

		// Integer.
		$updated['rate_limit_max'] = max( 1, min( 10000, (int) ( $posted['rate_limit_max'] ?? 60 ) ) );

		// Per-bot rules.
		$bot_rules_raw = $posted['bot_rules'] ?? array();
		$known_bots    = TWTAEO_AI_Ready::get_known_bots();
		$bot_rules     = array();
		foreach ( array_keys( $known_bots ) as $slug ) {
			$rule   = $bot_rules_raw[ $slug ] ?? array();
			$status = ( ( $rule['status'] ?? 'allow' ) === 'block' ) ? 'block' : 'allow';
			$rl_raw = trim( $rule['rate_limit'] ?? '' );
			$bot_rules[ $slug ] = array(
				'status'     => $status,
				'rate_limit' => ( $rl_raw !== '' ) ? max( 1, min( 10000, (int) $rl_raw ) ) : '',
			);
		}
		$updated['bot_rules'] = $bot_rules;

		// Textarea.
		$updated['ip_whitelist'] = sanitize_textarea_field( $posted['ip_whitelist'] ?? '' );

		// OAuth mode.
		$updated['oauth_mode'] = ( ( $posted['oauth_mode'] ?? 'builtin' ) === 'external' ) ? 'external' : 'builtin';

		// OAuth / OIDC Discovery — URL fields and text fields.
		foreach ( array( 'oauth_issuer', 'oauth_auth_endpoint', 'oauth_token_endpoint', 'oauth_jwks_uri', 'oauth_userinfo_endpoint' ) as $url_key ) {
			$raw = $posted[ $url_key ] ?? '';
			$updated[ $url_key ] = $raw ? esc_url_raw( trim( $raw ) ) : '';
		}
		$updated['oauth_grant_types'] = sanitize_text_field( $posted['oauth_grant_types'] ?? 'authorization_code,client_credentials' );
		$updated['oauth_scopes']      = sanitize_text_field( $posted['oauth_scopes'] ?? 'read,write' );

		update_option( TWTAEO_AI_Ready::OPTION_KEY, $updated );

		// Write or delete physical files for features that live under /.well-known/.
		if ( ! empty( $updated['api_catalog'] ) ) {
			TWTAEO_AI_Ready::write_api_catalog_file();
		} else {
			TWTAEO_AI_Ready::delete_api_catalog_file();
		}

		if ( ! empty( $updated['agent_skills_index'] ) ) {
			TWTAEO_AI_Ready::write_agent_skills_index();
		} else {
			TWTAEO_AI_Ready::delete_agent_skills_index();
		}

		if ( ! empty( $updated['oauth_discovery'] ) ) {
			TWTAEO_AI_Ready::write_oauth_discovery_files();
		} else {
			TWTAEO_AI_Ready::delete_oauth_discovery_files();
		}

		if ( ! empty( $updated['mcp_integration'] ) ) {
			TWTAEO_AI_Ready::write_mcp_server_card_file();
		} else {
			TWTAEO_AI_Ready::delete_mcp_server_card_file();
		}

		// Always flush on save so rewrite rules stay in sync with settings.
		// Also clear the startup-flush flag so on_init re-fires it if WordPress
		// needs to regenerate its rule cache on the next non-admin request.
		delete_option( 'twtaeo_air_rules_flushed' );
		TWTAEO_AI_Ready::flush_rewrites();

		return array( 'saved' => true, 'flushed' => true );
	}
}
