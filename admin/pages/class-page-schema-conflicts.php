<?php
/**
 * Schema Conflict Detector — Admin Page
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Schema_Conflicts {

	const NONCE_SUPPRESS = 'twtaeo_schema_suppress';

	/**
	 * Count postmeta rows left in the DB for each SEO plugin.
	 *
	 * @return array  { plugin_slug => [ 'rows' => int, 'active' => bool ] }
	 */
	private static function get_leftover_counts() {
		$cache_key = 'twtaeo_schema_leftover_counts';
		$cached    = wp_cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = array(
			'yoast' => array(
				'rows'   => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta}
					 WHERE meta_key IN ('_yoast_wpseo_schema_page_type','_yoast_wpseo_schema_article_type')"
				),
				'active' => defined( 'WPSEO_VERSION' ),
			),
			'rank_math' => array(
				'rows'   => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank_math_schema_%'"
				),
				'active' => defined( 'RANK_MATH_VERSION' ),
			),
			'aioseo' => array(
				'rows'   => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta}
					 WHERE meta_key LIKE '_aioseo_schema_%' OR meta_key = 'aioseo_schema'"
				),
				'active' => defined( 'AIOSEO_VERSION' ),
			),
			'saswp' => array(
				'rows'   => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'saswp_%'"
				),
				'active' => defined( 'SASWP_VERSION' ),
			),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_set( $cache_key, $result );
		return $result;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Schema Conflict Detector', 'twt-aeo-ultimate' ); ?>
					</h1>
					<div class="twt-aeo-header__meta">
						<span class="twt-aeo-badge twt-aeo-badge--plugin">
							<?php esc_html_e( 'Beta', 'twt-aeo-ultimate' ); ?>
						</span>
					</div>
				</div>
				<p class="twt-aeo-header__sub">
					<?php esc_html_e( 'Detects when Yoast SEO, Rank Math, All in One SEO, or Schema & Structured Data for WP & AMP is outputting the same schema types as your AEO schemas. Compare field-level quality and choose whether to supplement or suppress competitor output.', 'twt-aeo-ultimate' ); ?>
				</p>
			</div>

			<?php self::render_output_control(); ?>

			<?php self::render_tab_content(); ?>

		</div>
		<?php
	}

	// ── What this plugin writes ──────────────────────────────────────────────

	const NONCE_OUTPUT = 'twtaeo_output_control';

	/**
	 * Save the merchant's output switches.
	 *
	 * Unchecked checkboxes are not submitted, so the form posts an explicit list of
	 * every key it rendered (`known[]`) and anything absent from `on[]` is off.
	 * Reading only the ticked boxes would silently switch off any key this form did
	 * not happen to render — a delegated one, or a new one added later.
	 */
	public static function handle_output_control_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		check_admin_referer( self::NONCE_OUTPUT );

		$known = isset( $_POST['known'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['known'] ) ) : array();
		$on    = isset( $_POST['on'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['on'] ) ) : array();

		foreach ( $known as $key ) {
			TWTAEO_Output_Control::set( $key, in_array( $key, $on, true ) );
		}

		wp_safe_redirect( add_query_arg( 'twtaeo_saved', '1', wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	/**
	 * The panel listing everything this plugin writes, who else writes it, and a
	 * switch for each.
	 */
	public static function render_output_control() {
		$outputs = TWTAEO_Output_Control::outputs();

		if ( isset( $_GET['twtaeo_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Saved.', 'twt-aeo-ultimate' ) . '</p></div>';
		}
		?>
		<div class="twt-aeo-card" style="margin-bottom:20px;padding:18px;background:#fff;border:1px solid #e2e4e7;border-radius:6px;">
			<h2 style="font-size:16px;font-weight:700;margin:0 0 6px;">
				<?php esc_html_e( 'What this plugin writes', 'twt-aeo-ultimate' ); ?>
			</h2>
			<p style="margin:0 0 4px;color:#50575e;">
				<?php esc_html_e( 'Everything below is switched on by default. If another plugin already writes one of these, you can switch this plugin’s version off here — nothing is turned off for you.', 'twt-aeo-ultimate' ); ?>
			</p>
			<p style="margin:0 0 14px;color:#787c82;font-size:12px;">
				<?php esc_html_e( 'Detected from each plugin’s own settings and the schema types it says it writes, not by reading your rendered pages — so a plugin listed here may only write it on some page types.', 'twt-aeo-ultimate' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="twtaeo_output_control">
				<?php wp_nonce_field( self::NONCE_OUTPUT ); ?>
				<table class="widefat striped" style="border:0;">
					<thead>
						<tr>
							<th style="width:34px;"></th>
							<th><?php esc_html_e( 'Output', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $outputs as $key => $spec ) : ?>
						<?php
						$status     = TWTAEO_Output_Control::status( $key );
						$delegated  = TWTAEO_Output_Control::is_delegated( $key );
						$handed     = 'handed_over' === $status['state'];
						// A delegated switch lives on its own screen and a handed-over
						// one is decided in the other plugin. Rendering an editable box
						// for either would be a second control that cannot win — the
						// two-flags-that-disagree failure. Show it, disabled, and say why.
						$disabled   = $delegated || $handed;
						$colour     = 'conflict' === $status['state'] ? '#b32d2e'
							: ( 'off' === $status['state'] || $handed ? '#787c82' : '#2271b1' );
						?>
						<tr>
							<td style="vertical-align:top;padding-top:12px;">
								<?php if ( ! $disabled ) : ?>
									<input type="hidden" name="known[]" value="<?php echo esc_attr( $key ); ?>">
								<?php endif; ?>
								<input type="checkbox"
									id="twtaeo-oc-<?php echo esc_attr( $key ); ?>"
									name="on[]"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( TWTAEO_Output_Control::enabled( $key ) ); ?>
									<?php disabled( $disabled ); ?>>
							</td>
							<td style="vertical-align:top;">
								<label for="twtaeo-oc-<?php echo esc_attr( $key ); ?>" style="font-weight:600;">
									<?php echo esc_html( $spec['label'] ); ?>
								</label>
								<p style="margin:2px 0 0;color:#50575e;"><?php echo esc_html( $spec['what'] ); ?></p>
								<?php if ( ! empty( $spec['gated'] ) ) : ?>
									<p style="margin:4px 0 0;color:#787c82;font-size:12px;"><?php echo esc_html( $spec['gated'] ); ?></p>
								<?php endif; ?>
							</td>
							<td style="vertical-align:top;color:<?php echo esc_attr( $colour ); ?>;">
								<?php echo esc_html( $status['summary'] ); ?>
								<?php if ( $delegated ) : ?>
									<p style="margin:4px 0 0;color:#787c82;font-size:12px;">
										<?php
										printf(
											/* translators: %s: settings screen name. */
											esc_html__( 'This one is switched on the %s screen, so it is shown here but changed there.', 'twt-aeo-ultimate' ),
											'<a href="' . esc_url( admin_url( 'admin.php?page=twt-aeo-ai-ready' ) ) . '">' . esc_html__( 'AI Ready', 'twt-aeo-ultimate' ) . '</a>'
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin:14px 0 0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'twt-aeo-ultimate' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the inner content of the conflicts page — usable standalone or embedded as a tab.
	 */
	public static function render_tab_content() {
		$suppressions    = TWTAEO_Schema_Conflict_Detector::get_suppressions();
		$leftover_counts = self::get_leftover_counts();

		$orphaned = array_filter( $leftover_counts, fn( $v ) => $v['rows'] > 0 && ! $v['active'] );
		$labels   = array( 'yoast' => 'Yoast SEO', 'rank_math' => 'Rank Math', 'aioseo' => 'All in One SEO', 'saswp' => 'Schema & Structured Data for WP & AMP' );
		?>

		<!-- Active suppressions notice -->
		<?php
		$active_count = count( array_filter( $suppressions, fn( $s ) => ! empty( $s['active'] ) ) );
		if ( $active_count > 0 ) :
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php
				// translators: %d: number of active schema suppressions.
				printf( esc_html__( '%d active suppression(s) applied.', 'twt-aeo-ultimate' ), absint( $active_count ) ); ?></strong>
				<?php esc_html_e( 'The suppressed schema types are being removed from their respective plugin\'s output on the frontend. Run a scan to review.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- Orphaned data notice (deactivated plugins with leftover postmeta) -->
		<?php if ( ! empty( $orphaned ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Leftover schema data detected from deactivated plugin(s):', 'twt-aeo-ultimate' ); ?></strong>
				<?php
				$parts = array();
				foreach ( $orphaned as $slug => $info ) {
					$parts[] = sprintf(
						'<strong>%s</strong> — %d row(s)',
						esc_html( $labels[ $slug ] ?? $slug ),
						$info['rows']
					);
				}
				echo wp_kses_post( implode( ', ', $parts ) );
				?>
				&mdash; <?php esc_html_e( 'Use the Delete panel below to clean up.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- Scan controls -->
		<div class="twt-aeo-sc__controls">
			<button type="button" id="twt-aeo-sc-scan" class="button button-primary button-large"
				data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_Schema_Conflict_Detector::NONCE ) ); ?>">
				<span class="dashicons dashicons-search" style="vertical-align:middle;margin:-2px 4px 0 0;"></span>
				<?php esc_html_e( 'Scan Schema Output', 'twt-aeo-ultimate' ); ?>
			</button>
			<span id="twt-aeo-sc-spinner" style="display:none;margin-left:12px;">
				<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>
				<span style="font-size:13px;color:var(--aeo-text-muted,#6b7280);">
					<?php esc_html_e( 'Fetching homepage and parsing schema…', 'twt-aeo-ultimate' ); ?>
				</span>
			</span>
			<span id="twt-aeo-sc-scanned-at" style="font-size:12px;color:var(--aeo-text-muted,#6b7280);margin-left:14px;"></span>
		</div>

		<!-- Results area — populated by JS -->
		<div id="twt-aeo-sc-results" style="display:none;margin-top:24px;">

			<!-- Plugin detection summary -->
			<div id="twt-aeo-sc-summary" style="margin-bottom:20px;"></div>

			<!-- Conflict cards -->
			<div id="twt-aeo-sc-conflicts"></div>

			<!-- Competitor-only schemas -->
			<div id="twt-aeo-sc-their-only" style="margin-top:28px;"></div>

			<!-- Our-only schemas (no conflict) -->
			<div id="twt-aeo-sc-our-only" style="margin-top:28px;"></div>

		</div>

		<!-- Suppress nonce for AJAX calls from JS -->
		<span id="twt-aeo-sc-suppress-nonce"
			  data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_Schema_Conflict_Detector::NONCE ) ); ?>"
			  style="display:none;"></span>

		<!-- Delete SEO Plugin Schema Data -->
		<div class="twt-aeo-sc__delete-panel">
			<h2 class="twt-aeo-sc__delete-panel__title">
				<span class="dashicons dashicons-trash" style="font-size:18px;margin-right:6px;vertical-align:-3px;color:#b91c1c;"></span>
				<?php esc_html_e( 'Delete SEO Plugin Schema Data', 'twt-aeo-ultimate' ); ?>
			</h2>
			<p class="twt-aeo-sc__delete-panel__desc">
				<?php esc_html_e( 'Permanently remove schema postmeta written by a competitor plugin from your database. This does not uninstall the plugin — it only clears the stored schema records it has saved to posts. Use this when you want a clean slate and are relying solely on AEO schemas.', 'twt-aeo-ultimate' ); ?>
			</p>
			<div class="twt-aeo-sc__delete-grid">
				<?php
				$delete_plugins = array(
					'yoast'     => array( 'label' => 'Yoast SEO',                          'keys' => '_yoast_wpseo_schema_page_type, _yoast_wpseo_schema_article_type' ),
					'rank_math' => array( 'label' => 'Rank Math',                          'keys' => 'rank_math_schema_*' ),
					'aioseo'    => array( 'label' => 'All in One SEO',                     'keys' => '_aioseo_schema_*, aioseo_schema' ),
					'saswp'     => array( 'label' => 'Schema & Structured Data for WP & AMP', 'keys' => 'saswp_*' ),
				);
				foreach ( $delete_plugins as $slug => $info ) :
					$count  = $leftover_counts[ $slug ]['rows'];
					$active = $leftover_counts[ $slug ]['active'];
				?>
				<div class="twt-aeo-sc__delete-card<?php echo $count === 0 ? ' twt-aeo-sc__delete-card--empty' : ''; ?>">
					<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
						<span class="twt-aeo-sc-plugin-badge twt-aeo-sc-plugin-badge--<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( $info['label'] ); ?>
						</span>
						<?php if ( $active ) : ?>
							<span class="twt-aeo-sc__plugin-status twt-aeo-sc__plugin-status--active">
								&#9679; <?php esc_html_e( 'Active', 'twt-aeo-ultimate' ); ?>
							</span>
						<?php else : ?>
							<span class="twt-aeo-sc__plugin-status twt-aeo-sc__plugin-status--inactive">
								&#9679; <?php esc_html_e( 'Inactive', 'twt-aeo-ultimate' ); ?>
							</span>
						<?php endif; ?>
					</div>
					<p class="twt-aeo-sc__delete-card__row-count">
						<?php if ( $count > 0 ) : ?>
							<strong style="color:#b91c1c;"><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
							<?php esc_html_e( 'row(s) found in database', 'twt-aeo-ultimate' ); ?>
						<?php else : ?>
							<span style="color:#6b7280;">&#10003; <?php esc_html_e( 'No data found', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</p>
					<p class="twt-aeo-sc__delete-card__keys">
						<?php esc_html_e( 'Keys:', 'twt-aeo-ultimate' ); ?>
						<code><?php echo esc_html( $info['keys'] ); ?></code>
					</p>
					<button type="button"
						class="button twt-aeo-sc-delete-btn"
						data-plugin="<?php echo esc_attr( $slug ); ?>"
						data-label="<?php echo esc_attr( $info['label'] ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( TWTAEO_Schema_Conflict_Detector::NONCE ) ); ?>"
						<?php disabled( $count === 0 ); ?>>
						<?php esc_html_e( 'Delete Data', 'twt-aeo-ultimate' ); ?>
					</button>
					<span class="twt-aeo-sc__delete-status" style="display:none;margin-left:10px;font-size:13px;"></span>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		ob_start();
		?>
		(function($){
			// ── Scan ──────────────────────────────────────────────────────────
			$('#twt-aeo-sc-scan').on('click', function(){
				var $btn     = $(this);
				var $spinner = $('#twt-aeo-sc-spinner');
				var $results = $('#twt-aeo-sc-results');
				var nonce    = $btn.data('nonce');

				$btn.prop('disabled', true);
				$spinner.show();
				$results.hide();

				$.post(twtAeo.ajaxUrl, {
					action: 'twtaeo_schema_conflict_scan',
					nonce:  nonce,
				}, function(response){
					$spinner.hide();
					$btn.prop('disabled', false);

					if (!response.success) {
						$('#twt-aeo-sc-summary').html(
							'<div class="notice notice-error inline"><p>' +
							escHtml(response.data || '<?php echo esc_js( __( 'Scan failed.', 'twt-aeo-ultimate' ) ); ?>') +
							'</p></div>'
						);
						$results.show();
						return;
					}

					renderResults(response.data);
					$('#twt-aeo-sc-scanned-at').text(
						'<?php echo esc_js( __( 'Scanned:', 'twt-aeo-ultimate' ) ); ?> ' +
						new Date().toLocaleTimeString()
					);
					$results.show();
				}).fail(function(){
					$spinner.hide();
					$btn.prop('disabled', false);
					$('#twt-aeo-sc-summary').html(
						'<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Request failed. Check your connection.', 'twt-aeo-ultimate' ) ); ?></p></div>'
					);
					$results.show();
				});
			});

			// ── Render ────────────────────────────────────────────────────────
			function renderResults(d) {
				// Summary bar
				var pluginBadges = '';
				$.each(d.plugins_detected, function(i, p){
					if (p === 'twt' || p === 'other') return;
					pluginBadges += '<span class="twt-aeo-sc-plugin-badge twt-aeo-sc-plugin-badge--' + p + '">' +
						pluginLabel(p) + '</span> ';
				});
				var summaryHtml = '<div class="twt-aeo-sc__summary-bar">';
				summaryHtml += '<span style="font-size:13px;font-weight:600;margin-right:10px;"><?php echo esc_js( __( 'Plugins detected:', 'twt-aeo-ultimate' ) ); ?></span>';
				summaryHtml += pluginBadges || '<em style="color:#6b7280;font-size:13px;"><?php echo esc_js( __( 'None (no SEO plugin active)', 'twt-aeo-ultimate' ) ); ?></em>';
				summaryHtml += '<span style="margin:0 16px;color:#d1d5db;">|</span>';
				summaryHtml += '<span style="font-size:13px;color:#6b7280;">';
				summaryHtml += escHtml(d.scanned_url);
				summaryHtml += '</span></div>';
				$('#twt-aeo-sc-summary').html(summaryHtml);

				// Conflict cards
				var $conflicts = $('#twt-aeo-sc-conflicts');
				$conflicts.empty();
				if (d.conflicts && d.conflicts.length) {
					var heading = '<h2 style="font-size:16px;font-weight:700;margin:0 0 12px;display:flex;align-items:center;gap:8px;">' +
						'<span style="background:#fee2e2;color:#991b1b;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;">' +
						d.conflicts.length + '</span>' +
						'<?php echo esc_js( __( 'Schema Conflicts', 'twt-aeo-ultimate' ) ); ?></h2>';
					$conflicts.append(heading);
					$.each(d.conflicts, function(i, c){
						$conflicts.append(buildConflictCard(c));
					});
				} else {
					$conflicts.html(
						'<div class="twt-aeo-sc__empty-state twt-aeo-sc__empty-state--success">' +
						'<span class="dashicons dashicons-yes-alt" style="font-size:32px;color:#22c55e;display:block;margin-bottom:8px;"></span>' +
						'<strong><?php echo esc_js( __( 'No conflicts detected.', 'twt-aeo-ultimate' ) ); ?></strong>' +
						'<p><?php echo esc_js( __( 'No competitor plugin is outputting the same schema types as your AEO schemas.', 'twt-aeo-ultimate' ) ); ?></p>' +
						'</div>'
					);
				}

				// Their-only
				var $theirOnly = $('#twt-aeo-sc-their-only');
				$theirOnly.empty();
				if (d.their_only && d.their_only.length) {
					var thHtml = '<h2 style="font-size:15px;font-weight:700;margin:0 0 10px;color:#6b7280;">' +
						'<?php echo esc_js( __( 'Competitor-only Schema (no AEO version yet)', 'twt-aeo-ultimate' ) ); ?></h2>' +
						'<div class="twt-aeo-sc__minor-grid">';
					$.each(d.their_only, function(i, t){
						thHtml += '<div class="twt-aeo-sc__minor-card">' +
							'<span class="twt-aeo-sc-type-badge">' + escHtml(t.type) + '</span>' +
							'<span class="twt-aeo-sc-plugin-badge twt-aeo-sc-plugin-badge--' + t.plugin + '" style="margin-left:8px;">' + escHtml(t.plugin_label) + '</span>' +
							'<span style="font-size:12px;color:#6b7280;margin-left:8px;">' + t.fields.length + ' <?php echo esc_js( __( 'fields', 'twt-aeo-ultimate' ) ); ?></span>' +
							'</div>';
					});
					thHtml += '</div>';
					$theirOnly.html(thHtml);
				}

				// Our-only
				var $ourOnly = $('#twt-aeo-sc-our-only');
				$ourOnly.empty();
				if (d.our_only && d.our_only.length) {
					var ouHtml = '<h2 style="font-size:15px;font-weight:700;margin:0 0 10px;color:#6b7280;">' +
						'<?php echo esc_js( __( 'AEO-only Schema (no competitor overlap)', 'twt-aeo-ultimate' ) ); ?></h2>' +
						'<div class="twt-aeo-sc__minor-grid">';
					$.each(d.our_only, function(i, t){
						ouHtml += '<div class="twt-aeo-sc__minor-card twt-aeo-sc__minor-card--ours">' +
							'<span class="twt-aeo-sc-type-badge twt-aeo-sc-type-badge--ours">' + escHtml(t.type) + '</span>' +
							'<span style="font-size:12px;color:#6b7280;margin-left:8px;">' + escHtml(t.post_title) + '</span>' +
							'<span style="font-size:12px;color:#6b7280;margin-left:8px;">&mdash; ' + t.fields.length + ' <?php echo esc_js( __( 'fields', 'twt-aeo-ultimate' ) ); ?></span>' +
							'</div>';
					});
					ouHtml += '</div>';
					$ourOnly.html(ouHtml);
				}
			}

			function buildConflictCard(c) {
				var suppressed = c.suppressed;

				// Field lists
				var theirFieldsHtml = '';
				$.each(c.their_fields, function(i, f){
					var extra = (c.missing_in_them.indexOf(f) === -1) ? '' :
						' <span style="font-size:10px;color:#6b7280;"><?php echo esc_js( __( '(we also have this)', 'twt-aeo-ultimate' ) ); ?></span>';
					theirFieldsHtml += '<li class="twt-aeo-sc__field-item">' + escHtml(f) + extra + '</li>';
				});

				var ourFieldsHtml = '';
				$.each(c.our_fields, function(i, f){
					var isAdvantage = c.missing_in_them.indexOf(f) !== -1;
					ourFieldsHtml += '<li class="twt-aeo-sc__field-item' + (isAdvantage ? ' twt-aeo-sc__field-item--advantage' : '') + '">' +
						(isAdvantage ? '<span class="twt-aeo-sc__field-plus">+</span> ' : '') +
						escHtml(f) +
						(isAdvantage ? ' <span style="font-size:10px;color:#166534;"><?php echo esc_js( __( '(we have, they don\'t)', 'twt-aeo-ultimate' ) ); ?></span>' : '') +
						'</li>';
				});

				// Advantage summary
				var advCount = c.missing_in_them.length;
				var advBadge = advCount > 0
					? '<span class="twt-aeo-sc__advantage-badge">+' + advCount + ' <?php echo esc_js( __( 'richer fields', 'twt-aeo-ultimate' ) ); ?></span>'
					: '';

				// Suppress toggle
				var suppressHtml = '';
				if (c.can_suppress) {
					suppressHtml = '<div class="twt-aeo-sc__action-bar">' +
						'<label class="twt-aeo-sc__suppress-toggle" title="<?php echo esc_js( __( 'Suppress this plugin\'s schema type on the frontend', 'twt-aeo-ultimate' ) ); ?>">' +
						'<input type="checkbox" class="twt-aeo-sc-suppress-chk"' +
							' data-plugin="' + escHtml(c.plugin) + '"' +
							' data-type="' + escHtml(c.type) + '"' +
							(suppressed ? ' checked' : '') + ' />' +
						'<span class="twt-aeo-sc__suppress-label">' +
						(suppressed
							? '&#10003; <?php echo esc_js( __( 'Suppressing', 'twt-aeo-ultimate' ) ); ?> ' + escHtml(c.plugin_label) + '\'<?php echo esc_js( __( 's output', 'twt-aeo-ultimate' ) ); ?>'
							: '<?php echo esc_js( __( 'Suppress', 'twt-aeo-ultimate' ) ); ?> ' + escHtml(c.plugin_label) + '\'<?php echo esc_js( __( 's version', 'twt-aeo-ultimate' ) ); ?>') +
						'</span>' +
						'<span class="twt-aeo-sc__suppress-status" style="margin-left:8px;font-size:12px;"></span>' +
						'</label>' +
						'<span style="font-size:12px;color:#6b7280;margin-left:auto;">' +
						'<?php echo esc_js( __( 'Supplement (coexist): your @graph block outputs alongside theirs — search engines merge them.', 'twt-aeo-ultimate' ) ); ?>' +
						'</span>' +
						'</div>';
				}

				return $('<div class="twt-aeo-sc__conflict-card' + (suppressed ? ' twt-aeo-sc__conflict-card--suppressed' : '') + '">' +
					'<div class="twt-aeo-sc__conflict-header">' +
						'<span class="twt-aeo-sc-type-badge twt-aeo-sc-type-badge--conflict">' + escHtml(c.type) + '</span>' +
						'<span class="twt-aeo-sc-plugin-badge twt-aeo-sc-plugin-badge--' + c.plugin + '">' + escHtml(c.plugin_label) + '</span>' +
						'<span class="dashicons dashicons-arrow-right-alt2" style="color:#9ca3af;margin:0 4px;line-height:22px;"></span>' +
						'<span class="twt-aeo-sc-plugin-badge twt-aeo-sc-plugin-badge--twt">TWT AEO</span>' +
						advBadge +
						(suppressed ? '<span class="twt-aeo-sc__suppressed-pill"><?php echo esc_js( __( 'Suppressed', 'twt-aeo-ultimate' ) ); ?></span>' : '') +
					'</div>' +

					'<div class="twt-aeo-sc__diff-grid">' +
						'<div class="twt-aeo-sc__diff-col">' +
							'<div class="twt-aeo-sc__diff-col-header twt-aeo-sc__diff-col-header--theirs">' +
								escHtml(c.plugin_label) + ' &mdash; ' + c.their_fields.length + ' <?php echo esc_js( __( 'semantic fields', 'twt-aeo-ultimate' ) ); ?>' +
							'</div>' +
							'<ul class="twt-aeo-sc__field-list">' + theirFieldsHtml + '</ul>' +
						'</div>' +
						'<div class="twt-aeo-sc__diff-col">' +
							'<div class="twt-aeo-sc__diff-col-header twt-aeo-sc__diff-col-header--ours">' +
								'TWT AEO &mdash; ' + c.our_fields.length + ' <?php echo esc_js( __( 'semantic fields', 'twt-aeo-ultimate' ) ); ?>' +
							'</div>' +
							'<ul class="twt-aeo-sc__field-list">' + ourFieldsHtml + '</ul>' +
						'</div>' +
					'</div>' +

					suppressHtml +
				'</div>');
			}

			// ── Suppress toggle handler ───────────────────────────────────────
			$(document).on('change', '.twt-aeo-sc-suppress-chk', function(){
				var $chk    = $(this);
				var $label  = $chk.closest('.twt-aeo-sc__suppress-toggle');
				var $status = $label.find('.twt-aeo-sc__suppress-status');
				var plugin  = $chk.data('plugin');
				var type    = $chk.data('type');
				var active  = $chk.is(':checked');
				var nonce   = $('#twt-aeo-sc-suppress-nonce').data('nonce');

				$chk.prop('disabled', true);
				$status.text('<?php echo esc_js( __( 'Saving…', 'twt-aeo-ultimate' ) ); ?>').css('color', '#6b7280');

				$.post(twtAeo.ajaxUrl, {
					action: 'twtaeo_schema_conflict_suppress',
					nonce:  nonce,
					plugin: plugin,
					type:   type,
					active: active ? 1 : 0,
				}, function(response){
					$chk.prop('disabled', false);
					if (response.success) {
						var $card = $chk.closest('.twt-aeo-sc__conflict-card');
						$card.toggleClass('twt-aeo-sc__conflict-card--suppressed', active);
						$card.find('.twt-aeo-sc__suppressed-pill').remove();
						if (active) {
							$card.find('.twt-aeo-sc__conflict-header').append(
								'<span class="twt-aeo-sc__suppressed-pill"><?php echo esc_js( __( 'Suppressed', 'twt-aeo-ultimate' ) ); ?></span>'
							);
						}
						$label.find('.twt-aeo-sc__suppress-label').text(
							active
								? '✓ <?php echo esc_js( __( 'Suppressing their output', 'twt-aeo-ultimate' ) ); ?>'
								: '<?php echo esc_js( __( 'Suppress their version', 'twt-aeo-ultimate' ) ); ?>'
						);
						$status.text(active ? '<?php echo esc_js( __( 'Applied — takes effect on next frontend load.', 'twt-aeo-ultimate' ) ); ?>' : '<?php echo esc_js( __( 'Removed.', 'twt-aeo-ultimate' ) ); ?>')
							.css('color', active ? '#166534' : '#6b7280');
					} else {
						$chk.prop('checked', !active);
						$status.text('<?php echo esc_js( __( 'Save failed.', 'twt-aeo-ultimate' ) ); ?>').css('color', '#cc0000');
					}
				}).fail(function(){
					$chk.prop('disabled', false).prop('checked', !active);
					$status.text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>').css('color', '#cc0000');
				});
			});

			// ── Delete handler ────────────────────────────────────────────────
			$(document).on('click', '.twt-aeo-sc-delete-btn', function(){
				var $btn   = $(this);
				var plugin = $btn.data('plugin');
				var label  = $btn.data('label');
				var nonce  = $btn.data('nonce');
				var $status = $btn.siblings('.twt-aeo-sc__delete-status');

				if (!confirm('<?php echo esc_js( __( 'This will permanently delete all schema data saved by', 'twt-aeo-ultimate' ) ); ?> ' + label + '<?php echo esc_js( __( ' from your database. Are you sure?', 'twt-aeo-ultimate' ) ); ?>')) {
					return;
				}

				$btn.prop('disabled', true);
				$status.text('<?php echo esc_js( __( 'Deleting…', 'twt-aeo-ultimate' ) ); ?>').css('color','#6b7280').show();

				$.post(twtAeo.ajaxUrl, {
					action: 'twtaeo_seo_schema_delete',
					nonce:  nonce,
					plugin: plugin,
				}, function(response){
					$btn.prop('disabled', false);
					if (response.success) {
						var rows = response.data.rows_deleted;
						$status.text(rows + ' <?php echo esc_js( __( 'record(s) deleted.', 'twt-aeo-ultimate' ) ); ?>').css('color','#166534');
					} else {
						$status.text('<?php echo esc_js( __( 'Delete failed.', 'twt-aeo-ultimate' ) ); ?>').css('color','#cc0000');
					}
				}).fail(function(){
					$btn.prop('disabled', false);
					$status.text('<?php echo esc_js( __( 'Request failed.', 'twt-aeo-ultimate' ) ); ?>').css('color','#cc0000');
				});
			});

			// ── Helpers ───────────────────────────────────────────────────────
			function pluginLabel(p){
				var m = { yoast:'Yoast SEO', rank_math:'Rank Math', aioseo:'All in One SEO', saswp:'Schema & Structured Data', other:'Unknown Plugin', twt:'TWT AEO' };
				return m[p] || p;
			}
			function escHtml(str){
				return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

		}(jQuery));
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
		?>

		<?php
	}
}
