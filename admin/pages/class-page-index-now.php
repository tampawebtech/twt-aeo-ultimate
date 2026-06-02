<?php
/**
 * Admin Page: IndexNow
 *
 * Three stacked cards:
 *   1. Key & Settings  — key display, verify, regenerate, engine selection, auto-submit
 *   2. Manual Submit   — textarea of URLs, AJAX submit
 *   3. Submission Log  — rolling table of the last 50 submissions
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Index_Now {

	const NONCE_SAVE = 'twtaeo_indexnow_save';
	const NONCE_AJAX = 'twtaeo_indexnow_ajax';

	/**
	 * Handle POST/GET form actions and redirect to $return_url.
	 * Call this before any HTML output.
	 */
	public static function handle_posts( $return_url ) {
		if ( isset( $_POST['twtaeo_indexnow_save'] ) ) {
			check_admin_referer( self::NONCE_SAVE );
			TWTAEO_Index_Now::save_settings( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) );
			wp_safe_redirect( add_query_arg( 'twt_indexnow_saved', '1', $return_url ) );
			exit;
		}

		if ( isset( $_GET['twt_indexnow_clear_log'] ) ) {
			check_admin_referer( 'twtaeo_indexnow_clear_log' );
			TWTAEO_Index_Now::clear_log();
			wp_safe_redirect( $return_url );
			exit;
		}

		if ( isset( $_POST['twtaeo_indexnow_regen'] ) ) {
			check_admin_referer( 'twtaeo_indexnow_regen' );
			TWTAEO_Index_Now::regenerate_key();
			wp_safe_redirect( add_query_arg( 'twt_regen', '1', $return_url ) );
			exit;
		}
	}

	/**
	 * Render the three IndexNow cards — usable standalone or embedded as a section.
	 *
	 * @param string $return_url URL to redirect to after form actions (clear log, regen, save).
	 */
	public static function render_section( $return_url = '' ) {
		if ( empty( $return_url ) ) {
			$return_url = add_query_arg( 'page', 'twt-aeo-index-now', admin_url( 'admin.php' ) );
		}

		$settings   = TWTAEO_Index_Now::get_settings();
		$key        = TWTAEO_Index_Now::get_key();
		$key_url    = TWTAEO_Index_Now::get_key_file_url();
		$conflicts  = TWTAEO_Index_Now::detect_conflicts();
		$log        = TWTAEO_Index_Now::get_log();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		$engines_available = array(
			'api.indexnow.org'        => array(
				'label'   => 'api.indexnow.org',
				'note'    => 'Recommended — distributes to all participating engines simultaneously.',
				'reaches' => '',
			),
			'www.bing.com'            => array(
				'label'   => 'Bing / Microsoft Copilot',
				'note'    => 'Direct submission to Bing. Also powers Microsoft Copilot AI answers.',
				'reaches' => 'www.bing.com',
			),
			'yandex.com'              => array(
				'label'   => 'Yandex',
				'note'    => 'Direct submission to Yandex. Dominant in Russia and Eastern Europe.',
				'reaches' => 'yandex.com',
			),
			'search.seznam.cz'        => array(
				'label'   => 'Seznam.cz',
				'note'    => 'Direct submission to Seznam. Leading search engine in Czech Republic.',
				'reaches' => 'search.seznam.cz',
			),
			'searchadvisor.naver.com' => array(
				'label'   => 'Naver',
				'note'    => 'Direct submission to Naver. Dominant search engine in South Korea.',
				'reaches' => 'searchadvisor.naver.com',
			),
			'indexnow.yep.com'        => array(
				'label'   => 'Yep (Ahrefs)',
				'note'    => 'Direct submission to Yep, Ahrefs\' privacy-focused search engine.',
				'reaches' => 'indexnow.yep.com',
			),
		);

		$clear_log_url = wp_nonce_url(
			add_query_arg( 'twt_indexnow_clear_log', '1', $return_url ),
			'twtaeo_indexnow_clear_log'
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved    = isset( $_GET['twt_indexnow_saved'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$regenned = isset( $_GET['twt_regen'] );

		wp_enqueue_script(
			'twt-aeo-indexnow',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/index-now.js',
			array( 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-indexnow', 'twtAeoIndexNow', array(
			'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
		wp_set_script_translations( 'twt-aeo-indexnow', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
		?>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'twt-aeo-ultimate' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $regenned ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'API key regenerated. Your old key file URL is no longer valid — search engines will re-verify on the next submission.', 'twt-aeo-ultimate' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $conflicts ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s plugin name(s) */
						esc_html__( 'IndexNow is already active in %s. Disable it there first to avoid double-submissions.', 'twt-aeo-ultimate' ),
						'<strong>' . esc_html( implode( ', ', $conflicts ) ) . '</strong>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" style="margin-top:16px;">
			<?php wp_nonce_field( self::NONCE_SAVE ); ?>

			<!-- ── Card 1: Key & Settings ── -->
			<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:0;">
				<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
					<?php esc_html_e( 'API Key', 'twt-aeo-ultimate' ); ?>
				</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Your Key', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
								<code id="twt-indexnow-key" style="font-size:14px;padding:6px 10px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:3px;user-select:all;"><?php echo esc_html( $key ); ?></code>
								<button type="button" id="twt-indexnow-copy" class="button" style="font-size:12px;"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
							</div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Key File URL', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
								<code style="font-size:13px;padding:5px 8px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:3px;"><?php echo esc_html( $key_url ); ?></code>
								<button type="button" id="twt-indexnow-verify" class="button" style="font-size:12px;"><?php esc_html_e( 'Verify Key File', 'twt-aeo-ultimate' ); ?></button>
								<a href="<?php echo esc_url( $key_url ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="font-size:12px;"><?php esc_html_e( 'Open', 'twt-aeo-ultimate' ); ?></a>
							</div>
							<div id="twt-indexnow-verify-result" style="display:none;margin-top:8px;font-size:13px;"></div>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'This file is served automatically by the plugin — no upload required. Search engines fetch it to confirm you own the site.', 'twt-aeo-ultimate' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Regenerate Key', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:10px;">
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'twtaeo_indexnow_regen' ); ?>
									<input type="submit" name="twtaeo_indexnow_regen" class="button" value="<?php esc_attr_e( 'Generate New Key', 'twt-aeo-ultimate' ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'This will invalidate your current key. Are you sure?', 'twt-aeo-ultimate' ) ); ?>')">
								</form>
							</div>
							<p class="description"><?php esc_html_e( 'Only do this if your key has been compromised. Search engines will re-verify automatically on the next submission.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 style="font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;margin-bottom:0;">
					<?php esc_html_e( 'Submission Settings', 'twt-aeo-ultimate' ); ?>
				</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Engines', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<?php foreach ( $engines_available as $host => $engine ) : ?>
								<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;cursor:pointer;">
									<input type="checkbox" name="engines[]" value="<?php echo esc_attr( $host ); ?>"
										<?php checked( in_array( $host, (array) $settings['engines'], true ) ); ?>
										style="margin-top:3px;flex-shrink:0;"
									>
									<span>
										<strong style="font-size:13px;"><?php echo esc_html( $engine['label'] ); ?></strong>
										<code style="font-size:11px;color:#666;margin-left:4px;"><?php echo esc_html( $engine['reaches'] ?: $host ); ?></code>
										<br>
										<span style="font-size:12px;color:#666;"><?php echo esc_html( $engine['note'] ); ?></span>
									</span>
								</label>
								<?php if ( 'api.indexnow.org' === $host ) : ?>
									<div style="border-bottom:1px solid #f0f0f1;margin:4px 0 12px;"></div>
									<p style="font-size:12px;color:#999;margin:0 0 10px;font-style:italic;">
										<?php esc_html_e( 'Or submit directly to individual engines — redundant if api.indexnow.org is already checked above.', 'twt-aeo-ultimate' ); ?>
									</p>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto-Submit on Publish', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_submit" value="1" <?php checked( $settings['auto_submit'] ); ?>>
								<?php esc_html_e( 'Automatically ping IndexNow when a post is published or updated', 'twt-aeo-ultimate' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Post Types to Watch', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<div style="display:flex;flex-wrap:wrap;gap:10px 20px;">
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display:inline-flex;align-items:center;gap:5px;">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?>>
										<?php echo esc_html( $pt->labels->singular_name ); ?>
										<span style="color:#999;font-size:11px;">(<?php echo esc_html( $pt->name ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
				</table>

				<p style="margin-top:8px;">
					<input type="submit" name="twtaeo_indexnow_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'twt-aeo-ultimate' ); ?>">
				</p>
			</div>

		</form>

		<!-- ── Card 2: Manual Submit ── -->
		<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:20px;">
			<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
				<?php esc_html_e( 'Manual URL Submission', 'twt-aeo-ultimate' ); ?>
			</h2>
			<p style="margin-top:0;font-size:13px;color:#3c434a;">
				<?php esc_html_e( 'Enter one URL per line. Useful for bulk submission after a site migration or when auto-submit is disabled.', 'twt-aeo-ultimate' ); ?>
			</p>
			<textarea id="twt-indexnow-urls" rows="6" style="width:100%;font-family:monospace;font-size:13px;" placeholder="https://example.com/page-1&#10;https://example.com/page-2"></textarea>
			<div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
				<button type="button" id="twt-indexnow-submit" class="button button-primary">
					<?php esc_html_e( 'Submit URLs', 'twt-aeo-ultimate' ); ?>
				</button>
				<button type="button" id="twt-indexnow-submit-all" class="button">
					<?php esc_html_e( 'Submit All Published URLs', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twt-indexnow-submit-status" style="font-size:13px;"></span>
			</div>
			<div id="twt-indexnow-submit-result" style="display:none;margin-top:14px;"></div>
		</div>

		<!-- ── Card 3: Submission Log ── -->
		<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:20px;">
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
				<h2 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
					<?php esc_html_e( 'Submission Log', 'twt-aeo-ultimate' ); ?>
					<?php // translators: %d: number of log entries displayed. ?>
					<span style="font-weight:normal;text-transform:none;letter-spacing:0;color:#999;font-size:12px;"><?php printf( esc_html__( '(last %d)', 'twt-aeo-ultimate' ), absint( TWTAEO_Index_Now::LOG_LIMIT ) ); ?></span>
				</h2>
				<?php if ( $log ) : ?>
					<a href="<?php echo esc_url( $clear_log_url ); ?>" class="button" style="font-size:12px;" onclick="return confirm('<?php echo esc_js( __( 'Clear the submission log?', 'twt-aeo-ultimate' ) ); ?>')">
						<?php esc_html_e( 'Clear Log', 'twt-aeo-ultimate' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( ! $log ) : ?>
				<p style="color:#999;font-size:13px;margin:0;"><?php esc_html_e( 'No submissions yet.', 'twt-aeo-ultimate' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="font-size:12px;">
					<thead>
						<tr>
							<th style="width:140px;"><?php esc_html_e( 'Time', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Engine', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'URLs Submitted', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<?php
							$status      = (int) $entry['status'];
							$ok          = ( $status >= 200 && $status < 300 );
							$status_html = $ok
								? '<span style="color:#00a32a;font-weight:600;">' . esc_html( $status ) . '</span>'
								: '<span style="color:#d63638;font-weight:600;">' . ( $status ?: 'ERR' ) . '</span>';
							$url_count   = count( (array) $entry['urls'] );
							$url_preview = implode( ', ', array_slice( (array) $entry['urls'], 0, 2 ) );
							if ( $url_count > 2 ) {
								$url_preview .= ' +' . ( $url_count - 2 ) . ' more';
							}
							?>
							<tr>
								<td><?php echo esc_html( wp_date( 'M j, g:i a', $entry['timestamp'] ) ); ?></td>
								<td><?php echo esc_html( $entry['engine'] ); ?></td>
								<td><?php echo $status_html; // phpcs:ignore ?></td>
								<td title="<?php echo esc_attr( implode( "\n", (array) $entry['urls'] ) ); ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:default;">
									<?php echo esc_html( $url_preview ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$return_url = add_query_arg( 'page', 'twt-aeo-index-now', admin_url( 'admin.php' ) );
		self::handle_posts( $return_url );
		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'IndexNow', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Instantly notify Bing, Yandex, and other IndexNow-compatible search engines when your content is published or updated — no waiting for the next crawl.', 'twt-aeo-ultimate' ); ?></p>

			<?php self::render_section( $return_url ); ?>
		</div>
		<?php
	}
}
