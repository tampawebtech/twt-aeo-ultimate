<?php
/**
 * Admin Page: Sitemap Generator
 *
 * Settings UI for the Sitemap Generator module. Allows admins to configure
 * which post types are included, their change frequency and priority,
 * and which post IDs to exclude. Warns when a conflicting SEO plugin
 * already provides a sitemap.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Sitemap {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$notice   = '';
		$conflict = TWTAEO_Sitemap_Generator::has_conflict();

		// Handle settings save.
		if ( isset( $_POST['twtaeo_sitemap_save'] ) ) {
			check_admin_referer( TWTAEO_Sitemap_Generator::NONCE_SETTINGS );
			TWTAEO_Sitemap_Generator::save_settings( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) );
			TWTAEO_Sitemap_Generator::save_ai_txt_settings( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) );
			$notice = 'saved';
		}

		// Handle manual cache flush.
		if ( isset( $_GET['twtaeo_flush_sitemap'] ) ) {
			check_admin_referer( TWTAEO_Sitemap_Generator::NONCE_FLUSH, 'twtaeo_flush_nonce' );
			TWTAEO_Sitemap_Generator::flush_cache();
			TWTAEO_Sitemap_Generator::schedule_rewrite_flush();
			$notice = 'flushed';
		}

		$settings     = TWTAEO_Sitemap_Generator::get_settings();
		$ai_settings  = TWTAEO_Sitemap_Generator::get_ai_txt_settings();
		$all_types    = TWTAEO_Sitemap_Generator::get_all_public_post_types();
		$sitemap_url  = TWTAEO_Sitemap_Generator::get_url();
		$fallback_url = TWTAEO_Sitemap_Generator::get_fallback_url();
		$llms_url     = TWTAEO_Sitemap_Generator::get_llms_url();
		$ai_txt_url   = TWTAEO_Sitemap_Generator::get_ai_txt_url();
		$crawlers     = TWTAEO_Sitemap_Generator::get_known_crawlers();

		$flush_url = wp_nonce_url(
			add_query_arg(
				array( 'page' => 'twt-aeo-sitemap', 'twtaeo_flush_sitemap' => '1' ),
				admin_url( 'admin.php' )
			),
			TWTAEO_Sitemap_Generator::NONCE_FLUSH,
			'twtaeo_flush_nonce'
		);

		$changefreq_options = array(
			'always'  => 'Always',
			'hourly'  => 'Hourly',
			'daily'   => 'Daily',
			'weekly'  => 'Weekly',
			'monthly' => 'Monthly',
			'yearly'  => 'Yearly',
			'never'   => 'Never',
		);

		$priority_options = array(
			'1.0' => '1.0 — Highest',
			'0.9' => '0.9',
			'0.8' => '0.8',
			'0.7' => '0.7',
			'0.6' => '0.6',
			'0.5' => '0.5 — Default',
			'0.4' => '0.4',
			'0.3' => '0.3',
			'0.2' => '0.2',
			'0.1' => '0.1 — Lowest',
		);

		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'Sitemap Generator', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Generates and serves an XML sitemap at /aeo-sitemap.xml. Helps search engines discover and prioritize your content.', 'twt-aeo-ultimate' ); ?></p>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved. Sitemap cache cleared and rewrite rules queued for refresh.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php elseif ( 'flushed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Sitemap cache cleared and rewrite rules refreshed.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $conflict ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Conflict detected:', 'twt-aeo-ultimate' ); ?></strong>
						<?php
						printf(
							/* translators: %1$s and %2$s: SEO plugin name */
							esc_html__( '%1$s is active and already provides an XML sitemap. Running two sitemaps can confuse search engines. Consider disabling the %2$s sitemap or this module.', 'twt-aeo-ultimate' ),
							'<strong>' . esc_html( $conflict ) . '</strong>',
							esc_html( $conflict )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Sitemap URL card -->
			<div class="twt-aeo-card" style="max-width:780px;margin-top:20px;padding:20px;">
				<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Sitemap URL', 'twt-aeo-ultimate' ); ?></h2>

				<p style="margin:0 0 8px;">
					<code style="font-size:13px;"><?php echo esc_url( $sitemap_url ); ?></code>
					<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" class="button button-small" style="margin-left:10px;"><?php esc_html_e( 'View Sitemap', 'twt-aeo-ultimate' ); ?></a>
					<a href="<?php echo esc_url( $flush_url ); ?>" class="button button-small" style="margin-left:6px;"><?php esc_html_e( 'Flush Cache', 'twt-aeo-ultimate' ); ?></a>
				</p>

				<p class="description" style="margin:0;">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: "Settings → Permalinks" link, 2: "query string fallback" link. */
							esc_html__( 'If the pretty URL returns a 404, go to %1$s and click Save to flush rewrite rules. Alternatively, use the %2$s.', 'twt-aeo-ultimate' ),
							'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks', 'twt-aeo-ultimate' ) . '</a>',
							'<a href="' . esc_url( $fallback_url ) . '" target="_blank">' . esc_html__( 'query string fallback', 'twt-aeo-ultimate' ) . '</a>'
						)
					);
					?>
				</p>

				<hr style="margin:16px 0;">

				<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'AI Crawler Handshake (llms-full.txt)', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 8px;">
					<code style="font-size:13px;"><?php echo esc_url( $llms_url ); ?></code>
					<a href="<?php echo esc_url( $llms_url ); ?>" target="_blank" class="button button-small" style="margin-left:10px;"><?php esc_html_e( 'View llms-full.txt', 'twt-aeo-ultimate' ); ?></a>
				</p>
				<p class="description" style="margin:0;">
					<?php esc_html_e( 'A curated Markdown document for AI crawlers (ChatGPT, Perplexity, Claude, etc.). Follows the llmstxt.org spec: site header, indexed links by content type, then full page content. Flushed together with the XML sitemap cache.', 'twt-aeo-ultimate' ); ?>
				</p>

				<hr style="margin:16px 0;">

				<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'AI Permissions (ai.txt)', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 8px;">
					<code style="font-size:13px;"><?php echo esc_url( $ai_txt_url ); ?></code>
					<a href="<?php echo esc_url( $ai_txt_url ); ?>" target="_blank" class="button button-small" style="margin-left:10px;"><?php esc_html_e( 'View ai.txt', 'twt-aeo-ultimate' ); ?></a>
				</p>
				<p class="description" style="margin:0;">
					<?php esc_html_e( 'Declares per-crawler AI permissions in a robots.txt-style format. Controls which AI systems may index or train on your content. Configure below.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>

			<!-- Settings form -->
			<form method="post" style="margin-top:20px;">
				<?php wp_nonce_field( TWTAEO_Sitemap_Generator::NONCE_SETTINGS ); ?>

				<!-- Post types -->
				<div class="twt-aeo-card" style="max-width:780px;padding:20px;">
					<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Post Types', 'twt-aeo-ultimate' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Select which post types to include and configure their change frequency and priority hints for search engines.', 'twt-aeo-ultimate' ); ?></p>

					<table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
						<thead>
							<tr>
								<th style="width:36px;padding-left:12px;"></th>
								<th><?php esc_html_e( 'Post Type', 'twt-aeo-ultimate' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Change Frequency', 'twt-aeo-ultimate' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Priority', 'twt-aeo-ultimate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $all_types as $slug => $obj ) :
								$saved_type = $settings['post_types'][ $slug ] ?? array();
								$is_enabled = isset( $saved_type['enabled'] ) ? (bool) $saved_type['enabled'] : true;
								$saved_freq = $saved_type['changefreq'] ?? ( 'post' === $slug ? 'weekly' : 'monthly' );
								$saved_pri  = number_format( (float) ( $saved_type['priority'] ?? ( 'page' === $slug ? '0.8' : '0.6' ) ), 1 );
								$label      = isset( $obj->labels->name ) ? $obj->labels->name : ucfirst( $slug );
							?>
								<tr>
									<td style="padding-left:12px;">
										<input
											type="checkbox"
											name="post_types[<?php echo esc_attr( $slug ); ?>][enabled]"
											value="1"
											<?php checked( $is_enabled ); ?>
										>
									</td>
									<td>
										<strong><?php echo esc_html( $label ); ?></strong>
										<code style="margin-left:6px;font-size:11px;color:#666;"><?php echo esc_html( $slug ); ?></code>
									</td>
									<td>
										<select name="post_types[<?php echo esc_attr( $slug ); ?>][changefreq]">
											<?php foreach ( $changefreq_options as $val => $opt_label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_freq, $val ); ?>>
													<?php echo esc_html( $opt_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="post_types[<?php echo esc_attr( $slug ); ?>][priority]">
											<?php foreach ( $priority_options as $val => $opt_label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_pri, $val ); ?>>
													<?php echo esc_html( $opt_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Exclude IDs -->
				<div class="twt-aeo-card" style="max-width:780px;margin-top:16px;padding:20px;">
					<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Exclude Posts / Pages', 'twt-aeo-ultimate' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Enter post IDs to exclude from the sitemap, separated by commas. Find a post ID in the URL when editing it (e.g. ?post=42).', 'twt-aeo-ultimate' ); ?></p>
					<input
						type="text"
						name="exclude_ids"
						value="<?php echo esc_attr( $settings['exclude_ids'] ?? '' ); ?>"
						class="regular-text"
						placeholder="e.g. 12, 45, 78"
						style="margin-top:10px;"
					>
				</div>

				<!-- ai.txt Permissions -->
				<div class="twt-aeo-card" style="max-width:780px;margin-top:16px;padding:20px;">
					<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'AI Permissions (ai.txt)', 'twt-aeo-ultimate' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Controls what AI systems are permitted to do with your content, per media type and action. The default stance is opt-out for commercial AI training (EU Article 4 / TDMRep compliant).', 'twt-aeo-ultimate' ); ?></p>

					<?php
					$media_types = array(
						'text'   => __( 'Text Content', 'twt-aeo-ultimate' ),
						'images' => __( 'Images', 'twt-aeo-ultimate' ),
						'audio'  => __( 'Audio', 'twt-aeo-ultimate' ),
						'code'   => __( 'Code', 'twt-aeo-ultimate' ),
					);
					$action_cols = array(
						'train'     => array(
							'label' => __( 'Allow Training', 'twt-aeo-ultimate' ),
							'note'  => __( 'Use content to train AI/ML models', 'twt-aeo-ultimate' ),
						),
						'summarize' => array(
							'label' => __( 'Allow Summarising', 'twt-aeo-ultimate' ),
							'note'  => __( 'Summarise content in AI responses', 'twt-aeo-ultimate' ),
						),
						'index'     => array(
							'label' => __( 'Allow Indexing', 'twt-aeo-ultimate' ),
							'note'  => __( 'Index content for retrieval / search', 'twt-aeo-ultimate' ),
						),
					);
					?>

					<table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
						<thead>
							<tr>
								<th style="width:160px;"><?php esc_html_e( 'Media Type', 'twt-aeo-ultimate' ); ?></th>
								<?php foreach ( $action_cols as $action_key => $action ) : ?>
									<th style="text-align:center;">
										<?php echo esc_html( $action['label'] ); ?>
										<br><span style="font-weight:400;font-size:11px;color:#888;"><?php echo esc_html( $action['note'] ); ?></span>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $media_types as $type_key => $type_label ) :
								$type_config = $ai_settings['media'][ $type_key ] ?? array();
							?>
								<tr>
									<td><strong><?php echo esc_html( $type_label ); ?></strong></td>
									<?php foreach ( $action_cols as $action_key => $action ) :
										$is_checked = ! empty( $type_config[ $action_key ] ) && '1' === (string) $type_config[ $action_key ];
									?>
										<td style="text-align:center;">
											<input
												type="checkbox"
												name="ai_txt_media[<?php echo esc_attr( $type_key ); ?>][<?php echo esc_attr( $action_key ); ?>]"
												value="1"
												<?php checked( $is_checked ); ?>
											>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<table style="margin-top:16px;border-collapse:collapse;">
						<tr>
							<td style="padding:0 12px 0 0;vertical-align:middle;">
								<label for="ai_txt_contact" style="font-weight:600;"><?php esc_html_e( 'Licensing Contact Email', 'twt-aeo-ultimate' ); ?></label>
								<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Optional. Added as a comment to ai.txt for AI licensing inquiries.', 'twt-aeo-ultimate' ); ?></p>
							</td>
							<td style="vertical-align:middle;">
								<input
									type="email"
									id="ai_txt_contact"
									name="ai_txt_contact"
									value="<?php echo esc_attr( $ai_settings['contact'] ?? '' ); ?>"
									class="regular-text"
									placeholder="licensing@yoursite.com"
								>
							</td>
						</tr>
					</table>
				</div>

				<p style="margin-top:16px;">
					<input
						type="submit"
						name="twtaeo_sitemap_save"
						class="button button-primary"
						value="<?php esc_attr_e( 'Save Settings', 'twt-aeo-ultimate' ); ?>"
					>
				</p>
			</form>
		</div>
		<?php
	}
}
