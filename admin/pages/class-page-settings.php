<?php
/**
 * Settings Page
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Settings {

	const NONCE_ACTION = 'twtaeo_settings_save';
	const NONCE_NAME   = 'twtaeo_nonce';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		if ( ! empty( $_POST[ self::NONCE_NAME ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			self::handle_save();
		}

		$settings = get_option( 'twtaeo_settings', array() );

		// Decrypt secret fields for display (stored encrypted at rest).
		foreach ( array( 'api_claude', 'api_openai', 'api_gemini', 'api_perplexity', 'api_xai', 'api_mistral', 'api_deepseek', 'api_meta', 'api_ein_presswire', 'api_easypwire' ) as $secret_field ) {
			if ( ! empty( $settings[ $secret_field ] ) ) {
				$settings[ $secret_field ] = TWTAEO_Crypt::decrypt( (string) $settings[ $secret_field ] );
			}
		}

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Settings', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<?php
				// WP 7.0+ AI Client detection. When present, Claude and OpenAI
				// requests are routed through the user's configured connection;
				// this plugin never reads the connector API keys.
				$ai_client = function_exists( 'wp_ai_client_prompt' );
				?>
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'AI API Keys', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<?php if ( $ai_client ) : ?>
						<div class="notice notice-info inline" style="margin:0 0 14px;padding:8px 12px;">
							<p style="margin:0;"><?php printf(
								wp_kses(
									/* translators: %s: URL to Settings > Connectors */
									__( '<strong>WordPress 7 detected:</strong> leave the Claude and OpenAI fields blank to generate content using the AI providers you connected at <a href="%s">Settings &rsaquo; Connectors</a>. A key entered below overrides the connected provider for that service.', 'twt-aeo-ultimate' ),
									array( 'strong' => array(), 'a' => array( 'href' => array() ) )
								),
								esc_url( admin_url( 'options-general.php?page=connectors' ) )
							); ?></p>
						</div>
						<?php endif; ?>
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Used by the plugin\'s AI features — meta descriptions, PR Bridge, WooCommerce product enrichment, and image analysis. Each key is optional — only providers with a key are used.', 'twt-aeo-ultimate' ); ?>
						</p>
						<div class="twt-aeo-api-keys">

							<?php
							// Helper: render a protected-key badge or normal password input.
							// $slug        = TWTAEO_Key_Resolver slug
							// $field_name  = HTML input name
							// $db_value    = current value from DB (shown only when not protected)
							// $placeholder = input placeholder text
							// $hint        = label text shown to the right
							$render_key_field = function( $slug, $field_name, $db_value, $placeholder, $hint ) {
								$protected = TWTAEO_Key_Resolver::is_protected( $slug );
								$const_name = TWTAEO_Key_Resolver::constant_name( $slug );

								if ( $protected ) {
									// Key defined via PHP constant or env var — show badge, disable field.
									echo '<input type="password" name="' . esc_attr( $field_name ) . '" value="" ';
									echo 'placeholder="' . esc_attr__( '— protected via server constant —', 'twt-aeo-ultimate' ) . '" ';
									echo 'autocomplete="off" class="regular-text" disabled aria-disabled="true" />';
									echo '<span class="twt-aeo-api-key-row__hint" style="color:#1a6629;font-weight:600;">&#128274; ';
									echo esc_html( $const_name );
									echo '</span>';
								} else {
									// Standard DB-backed input.
									echo '<input type="password" name="' . esc_attr( $field_name ) . '" ';
									echo 'value="' . esc_attr( $db_value ) . '" ';
									echo 'placeholder="' . esc_attr( $placeholder ) . '" ';
									echo 'autocomplete="off" class="regular-text" />';
									echo '<span class="twt-aeo-api-key-row__hint">' . esc_html( $hint ) . '</span>';
								}
							};
							?>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot twt-aeo-api-dot--claude"></span>
									<?php esc_html_e( 'Claude (Anthropic)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'claude', 'twtaeo_settings[api_claude]', $settings['api_claude'] ?? '', 'sk-ant-…', __( 'Descriptions & PR', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot twt-aeo-api-dot--openai"></span>
									<?php esc_html_e( 'OpenAI (ChatGPT)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'openai', 'twtaeo_settings[api_openai]', $settings['api_openai'] ?? '', 'sk-…', __( 'Descriptions & images', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#1a73e8;"></span>
									<?php esc_html_e( 'Gemini (Google)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'gemini', 'twtaeo_settings[api_gemini]', $settings['api_gemini'] ?? '', 'AIza…', __( 'Summaries & meta', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot twt-aeo-api-dot--perplexity"></span>
									<?php esc_html_e( 'Perplexity', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'perplexity', 'twtaeo_settings[api_perplexity]', $settings['api_perplexity'] ?? '', 'pplx-…', __( 'Brand & gap research', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#000;"></span>
									<?php esc_html_e( 'Grok (xAI)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'xai', 'twtaeo_settings[api_xai]', $settings['api_xai'] ?? '', 'xai-…', __( 'AI Visibility checks', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#ff7000;"></span>
									<?php esc_html_e( 'Mistral (Le Chat)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'mistral', 'twtaeo_settings[api_mistral]', $settings['api_mistral'] ?? '', '…', __( 'AI Visibility checks', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#4d6bfe;"></span>
									<?php esc_html_e( 'DeepSeek', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'deepseek', 'twtaeo_settings[api_deepseek]', $settings['api_deepseek'] ?? '', 'sk-…', __( 'AI Visibility checks', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#0866ff;"></span>
									<?php esc_html_e( 'Muse (Meta AI)', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'meta', 'twtaeo_settings[api_meta]', $settings['api_meta'] ?? '', '…', __( 'AI Visibility checks', 'twt-aeo-ultimate' ) ); ?>
							</div>

						</div>
					</div>
				</section>

				<?php
				$enrich_provider    = $settings['ai_enrich_provider'] ?? '';
				$retrieval_provider = $settings['ai_retrieval_provider'] ?? 'gemini';
				$inherited          = $settings['ai_desc_provider'] ?? 'claude';
				?>
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'AI Enrichment', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Which providers power on-demand product enrichment (extract attributes from descriptions, resolve brand authority links). Uses the API keys above.', 'twt-aeo-ultimate' ); ?>
						</p>
						<table class="form-table" style="margin-top:4px;">
							<tr>
								<th style="width:220px;">
									<label for="twtaeo_ai_enrich_provider"><?php esc_html_e( 'Attribute extraction', 'twt-aeo-ultimate' ); ?></label>
								</th>
								<td>
									<select id="twtaeo_ai_enrich_provider" name="twtaeo_settings[ai_enrich_provider]">
										<option value="" <?php selected( $enrich_provider, '' ); ?>>
											<?php
											/* translators: %s: inherited provider name. */
											echo esc_html( sprintf( __( 'Inherit meta-description provider (%s)', 'twt-aeo-ultimate' ), ucfirst( $inherited ) ) ); ?>
										</option>
										<option value="claude" <?php selected( $enrich_provider, 'claude' ); ?>>Claude</option>
										<option value="openai" <?php selected( $enrich_provider, 'openai' ); ?>>OpenAI</option>
										<option value="gemini" <?php selected( $enrich_provider, 'gemini' ); ?>>Gemini</option>
									</select>
									<p class="description"><?php esc_html_e( 'Parses product text into structured attributes (color, material, dimensions, specs).', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="twtaeo_ai_retrieval_provider"><?php esc_html_e( 'Live retrieval', 'twt-aeo-ultimate' ); ?></label>
								</th>
								<td>
									<select id="twtaeo_ai_retrieval_provider" name="twtaeo_settings[ai_retrieval_provider]">
										<option value="gemini" <?php selected( $retrieval_provider, 'gemini' ); ?>><?php esc_html_e( 'Gemini (Google Search grounding)', 'twt-aeo-ultimate' ); ?></option>
										<option value="perplexity" <?php selected( $retrieval_provider, 'perplexity' ); ?>><?php esc_html_e( 'Perplexity', 'twt-aeo-ultimate' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Used for web-grounded lookups such as brand sameAs authority links.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</section>

				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'AI Models', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Which model each provider uses for meta descriptions, enrichment and extraction. Stronger models write better but cost more per request. AI Visibility checks are not affected — they always use each app\'s own default model, so results match what your customers see.', 'twt-aeo-ultimate' ); ?>
						</p>
						<table class="form-table" style="margin-top:4px;">
							<?php
							$provider_labels = array(
								'claude' => __( 'Claude (Anthropic)', 'twt-aeo-ultimate' ),
								'openai' => __( 'OpenAI (ChatGPT)', 'twt-aeo-ultimate' ),
								'gemini' => __( 'Gemini (Google)', 'twt-aeo-ultimate' ),
							);
							foreach ( TWTAEO_AI_Models::choices() as $provider => $models ) :
								$current = TWTAEO_AI_Models::get( $provider );
								$listed  = isset( $models[ $current ] );
								$field   = 'twtaeo_ai_model_' . $provider;
								?>
								<tr>
									<th style="width:220px;">
										<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $provider_labels[ $provider ] ); ?></label>
									</th>
									<td>
										<select id="<?php echo esc_attr( $field ); ?>" name="twtaeo_settings[ai_model_<?php echo esc_attr( $provider ); ?>]">
											<?php foreach ( $models as $id => $label ) : ?>
												<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
											<?php if ( ! $listed ) : ?>
												<option value="<?php echo esc_attr( $current ); ?>" selected>
													<?php
													/* translators: %s: model ID. */
													echo esc_html( sprintf( __( '%s (your current model)', 'twt-aeo-ultimate' ), $current ) );
													?>
												</option>
											<?php endif; ?>
										</select>
										<p class="description">
											<label for="<?php echo esc_attr( $field ); ?>_custom"><?php esc_html_e( 'Or enter any model ID (for models released after this plugin version):', 'twt-aeo-ultimate' ); ?></label><br />
											<input type="text" id="<?php echo esc_attr( $field ); ?>_custom" name="twtaeo_settings[ai_model_<?php echo esc_attr( $provider ); ?>_custom]" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( TWTAEO_AI_Models::DEFAULTS[ $provider ] ); ?>" />
										</p>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
				</section>

				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Usage Telemetry', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Help us publish real-world AI cost estimates by sharing anonymous token counts from your content generations. This data is aggregated across all plugin users — no content, titles, URLs, or site information is ever collected.', 'twt-aeo-ultimate' ); ?>
						</p>
						<table class="form-table" style="margin-top:4px;">
							<tr>
								<th style="width:200px;">
									<label for="twtaeo_token_telemetry"><?php esc_html_e( 'Share token usage', 'twt-aeo-ultimate' ); ?></label>
								</th>
								<td>
									<label class="twt-aeo-toggle">
										<input type="hidden" name="twtaeo_settings[token_telemetry]" value="0">
										<input type="checkbox" id="twtaeo_token_telemetry" name="twtaeo_settings[token_telemetry]" value="1" <?php checked( ! empty( $settings['token_telemetry'] ) ); ?> />
										<span class="twt-aeo-toggle__slider"></span>
									</label>
									<span style="margin-left:10px;vertical-align:middle;"><?php esc_html_e( 'Opt in to anonymous token telemetry', 'twt-aeo-ultimate' ); ?></span>
								</td>
							</tr>
						</table>
						<div style="margin-top:14px;display:flex;gap:24px;flex-wrap:wrap;">
							<div style="flex:1;min-width:180px;">
								<p style="margin:0 0 6px;font-weight:600;font-size:13px;color:#1a6629;">&#10003; <?php esc_html_e( 'What we collect', 'twt-aeo-ultimate' ); ?></p>
								<ul style="margin:0;padding-left:18px;font-size:13px;color:#3c434a;">
									<li><?php esc_html_e( 'Provider (Claude / OpenAI / Perplexity)', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'Model name', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'Input and output token counts', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'Cache hit / miss token counts (Claude)', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'Plugin version', 'twt-aeo-ultimate' ); ?></li>
								</ul>
							</div>
							<div style="flex:1;min-width:180px;">
								<p style="margin:0 0 6px;font-weight:600;font-size:13px;color:#dc2626;">&#10007; <?php esc_html_e( 'What we never collect', 'twt-aeo-ultimate' ); ?></p>
								<ul style="margin:0;padding-left:18px;font-size:13px;color:#3c434a;">
									<li><?php esc_html_e( 'Page titles or content', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'Site URL or name', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'API keys', 'twt-aeo-ultimate' ); ?></li>
									<li><?php esc_html_e( 'User or account information', 'twt-aeo-ultimate' ); ?></li>
								</ul>
							</div>
						</div>
					</div>
				</section>

				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Wire Service API Keys', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php esc_html_e( 'Used by PR Bridge AI to distribute press releases. Add a key for each wire service you have an account with.', 'twt-aeo-ultimate' ); ?>
						</p>
						<div class="twt-aeo-api-keys">

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#d97706;"></span>
									<?php esc_html_e( 'EIN Presswire', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'ein_presswire', 'twtaeo_settings[api_ein_presswire]', $settings['api_ein_presswire'] ?? '', __( 'EIN Presswire Bearer token', 'twt-aeo-ultimate' ), __( 'SMBs & agencies', 'twt-aeo-ultimate' ) ); ?>
							</div>

							<div class="twt-aeo-api-key-row">
								<label class="twt-aeo-api-key-row__label">
									<span class="twt-aeo-api-dot" style="background:#059669;"></span>
									<?php esc_html_e( 'EasyPRWire', 'twt-aeo-ultimate' ); ?>
								</label>
								<?php $render_key_field( 'easypwire', 'twtaeo_settings[api_easypwire]', $settings['api_easypwire'] ?? '', __( 'EasyPRWire API key', 'twt-aeo-ultimate' ), __( 'Niche & local', 'twt-aeo-ultimate' ) ); ?>
							</div>

						</div>
					</div>
				</section>

				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'TWT Agency', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card">
						<p class="twt-aeo-card__note">
							<?php
							printf(
								wp_kses(
									// translators: %s: URL to learn more about TWT Agency.
									__( 'Connect this site to <strong>TWT Agency</strong> so your SEO professional can monitor changes and generate monthly reports. Your professional will provide the Dashboard URL and API Key. <a href="%s" target="_blank">Learn more.</a>', 'twt-aeo-ultimate' ),
									array( 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array() ) )
								),
								esc_url( 'https://tampawebtech.com/twt-agency/' )
							); ?>
						</p>

						<table class="form-table" style="margin-top:8px;">
							<tr>
								<th style="width:200px;">
									<label for="twtaeo_pro_enabled"><?php esc_html_e( 'Enable Connection', 'twt-aeo-ultimate' ); ?></label>
								</th>
								<td>
									<label class="twt-aeo-toggle">
										<input type="hidden" name="twtaeo_settings[pro_enabled]" value="0">
										<input type="checkbox" id="twtaeo_pro_enabled" name="twtaeo_settings[pro_enabled]" value="1" <?php checked( ! empty( $settings['pro_enabled'] ) ); ?> />
										<span class="twt-aeo-toggle__slider"></span>
									</label>
									<span style="margin-left:10px;vertical-align:middle;"><?php esc_html_e( 'Send data to TWT Agency', 'twt-aeo-ultimate' ); ?></span>
								</td>
							</tr>
							<tr>
								<th><label for="twtaeo_pro_url"><?php esc_html_e( 'Dashboard URL', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="url" id="twtaeo_pro_url" name="twtaeo_settings[pro_url]"
										class="regular-text"
										value="<?php echo esc_attr( $settings['pro_url'] ?? '' ); ?>"
										placeholder="https://yourprofessional.com/wp-json/twt-agency/v1/ingest" />
								</td>
							</tr>
							<tr>
								<th><label for="twtaeo_pro_key"><?php esc_html_e( 'API Key', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<?php if ( TWTAEO_Key_Resolver::is_protected( 'pro_key' ) ) : ?>
									<input type="password" id="twtaeo_pro_key" name="twtaeo_settings[pro_key]"
										class="regular-text"
										value=""
										placeholder="<?php esc_attr_e( '— protected via server constant —', 'twt-aeo-ultimate' ); ?>"
										autocomplete="off" disabled aria-disabled="true" />
									<span style="color:#1a6629;font-weight:600;font-size:12px;margin-left:6px;">&#128274; <?php echo esc_html( TWTAEO_Key_Resolver::constant_name( 'pro_key' ) ); ?></span>
									<?php else : ?>
									<input type="password" id="twtaeo_pro_key" name="twtaeo_settings[pro_key]"
										class="regular-text"
										value="<?php echo esc_attr( $settings['pro_key'] ?? '' ); ?>"
										autocomplete="off"
										placeholder="<?php esc_attr_e( 'Provided by your SEO professional', 'twt-aeo-ultimate' ); ?>" />
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<?php if ( ! empty( $settings['pro_enabled'] ) && ! empty( $settings['pro_url'] ) && ! empty( $settings['pro_key'] ) ) : ?>
						<p style="margin-top:12px;">
							<span style="color:#1a6629;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></span>
							&mdash; <?php
							// translators: %s: date and time of last sync.
							printf( esc_html__( 'Last sync: %s', 'twt-aeo-ultimate' ), esc_html( get_option( 'twtaeo_pro_last_sync', __( 'Never', 'twt-aeo-ultimate' ) ) ) ); ?>
						</p>
						<?php endif; ?>

						<?php if ( empty( $settings['pro_enabled'] ) || empty( $settings['pro_url'] ) ) : ?>
						<div style="margin-top:16px;padding:14px 18px;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:0 4px 4px 0;">
							<strong><?php esc_html_e( 'Don\'t have a TWT Agency account yet?', 'twt-aeo-ultimate' ); ?></strong><br>
							<a href="https://tampawebtech.com/twt-agency/" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more about TWT Agency &rarr;', 'twt-aeo-ultimate' ); ?></a>
						</div>
						<?php endif; ?>
					</div>
				</section>

				<?php /* ── Agency Security Advisory ─────────────────────────────────────── */ ?>
				<section class="twt-aeo-section">
					<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Agency Security: Protecting Client API Keys', 'twt-aeo-ultimate' ); ?></h2>
					<div class="twt-aeo-card" style="border-left:4px solid #b32d2e;">
						<p style="margin:0 0 12px;font-size:13px;color:#3c434a;">
							<?php esc_html_e( 'Keys entered in the form above are stored in the WordPress database. Anyone with database access (hosting panel, phpMyAdmin, staging sync, DB backup) can read them. For agency-managed client sites, define keys as PHP constants or server environment variables instead — they are never written to the database, never logged, and take priority over any database value.', 'twt-aeo-ultimate' ); ?>
						</p>
						<p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Add to wp-config.php (above "That\'s all, stop editing"):', 'twt-aeo-ultimate' ); ?></p>
						<pre style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:14px 16px;font-size:12px;line-height:1.7;overflow-x:auto;margin:0 0 12px;color:#1d2327;"><?php echo esc_html(
"define( 'TWTAEO_CLAUDE_KEY',      'sk-ant-api03-…' );
define( 'TWTAEO_OPENAI_KEY',       'sk-proj-…' );
define( 'TWTAEO_PERPLEXITY_KEY',   'pplx-…' );
define( 'TWTAEO_PRO_KEY',          'your-pro-dashboard-key' );
// define( 'TWTAEO_EIN_KEY',        'ein-bearer-token' );
// define( 'TWTAEO_EASYPWIRE_KEY',  'easypwire-api-key' );"
						); ?></pre>
						<p style="margin:0;font-size:12px;color:#646970;">
							<?php esc_html_e( 'Alternatively, set the same names as server environment variables (LSAPI_CHILDREN, Nginx fastcgi_param, or Docker ENV). On WordPress 7.0 you can instead leave the Claude and OpenAI fields blank and connect those providers at Settings → Connectors; the plugin then generates through the WordPress AI Client without ever handling the key.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>
				</section>

				<?php submit_button( __( 'Save Settings', 'twt-aeo-ultimate' ) ); ?>
			</form>

		</div>
		<?php
	}

	private static function handle_save() {
		if ( ! check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME ) ) {
			return;
		}

		$posted   = isset( $_POST['twtaeo_settings'] ) ? map_deep( wp_unslash( $_POST['twtaeo_settings'] ), 'sanitize_text_field' ) : array();
		$existing = get_option( 'twtaeo_settings', array() );

		// API keys — store trimmed value only when the key is not protected via
		// PHP constant or env var (protected keys are never stored in the DB).
		$slug_to_field = array(
			'claude'        => 'api_claude',
			'openai'        => 'api_openai',
			'gemini'        => 'api_gemini',
			'perplexity'    => 'api_perplexity',
			'xai'           => 'api_xai',
			'mistral'       => 'api_mistral',
			'deepseek'      => 'api_deepseek',
			'meta'          => 'api_meta',
			'ein_presswire' => 'api_ein_presswire',
			'easypwire'     => 'api_easypwire',
		);
		foreach ( $slug_to_field as $slug => $field ) {
			if ( TWTAEO_Key_Resolver::is_protected( $slug ) ) {
				continue; // Key lives in a constant/env — never write it to DB.
			}
			$value = trim( $posted[ $field ] ?? '' );
			if ( $value !== '' ) {
				$existing[ $field ] = TWTAEO_Crypt::encrypt( $value );
			}
		}

		// AI enrichment providers (allow-listed).
		$enrich = $posted['ai_enrich_provider'] ?? '';
		$existing['ai_enrich_provider'] = in_array( $enrich, array( 'claude', 'openai', 'gemini' ), true ) ? $enrich : '';
		$retrieval = $posted['ai_retrieval_provider'] ?? 'gemini';
		$existing['ai_retrieval_provider'] = in_array( $retrieval, array( 'gemini', 'perplexity' ), true ) ? $retrieval : 'gemini';

		// Model per provider. A typed ID wins over the dropdown; an empty or
		// malformed value falls back to the default rather than breaking calls.
		foreach ( TWTAEO_AI_Models::PROVIDERS as $provider ) {
			$field  = TWTAEO_AI_Models::SETTING_PREFIX . $provider;
			$custom = TWTAEO_AI_Models::sanitize_id( $posted[ $field . '_custom' ] ?? '' );
			$picked = TWTAEO_AI_Models::sanitize_id( $posted[ $field ] ?? '' );
			if ( '' !== $custom || '' !== $picked ) {
				$existing[ $field ] = '' !== $custom ? $custom : $picked;
			}
		}
		// Anyone who has saved this form has chosen; the legacy pin must never
		// run over their choice.
		$existing[ TWTAEO_AI_Models::SETTING_PINNED ] = 1;

		// Telemetry opt-in
		$existing['token_telemetry'] = ! empty( $posted['token_telemetry'] ) ? 1 : 0;

		// Pro Dashboard connection
		$existing['pro_enabled'] = ! empty( $posted['pro_enabled'] ) ? 1 : 0;
		if ( ! TWTAEO_Key_Resolver::is_protected( 'pro_url' ) ) {
			$existing['pro_url'] = esc_url_raw( trim( $posted['pro_url'] ?? '' ) );
		}
		if ( ! TWTAEO_Key_Resolver::is_protected( 'pro_key' ) ) {
			$pro_key = trim( $posted['pro_key'] ?? '' );
			if ( $pro_key !== '' ) {
				$existing['pro_key'] = $pro_key;
			}
		}

		update_option( 'twtaeo_settings', $existing );

		add_settings_error(
			'twtaeo_messages',
			'twtaeo_saved',
			__( 'Settings saved.', 'twt-aeo-ultimate' ),
			'updated'
		);

		settings_errors( 'twtaeo_messages' );
	}
}