<?php
/**
 * Diagnostics Page
 *
 * Shows a copy-pasteable System Info report plus the recent plugin error log,
 * so a customer can send everything needed to track down an issue in one paste.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Diagnostics {

	const NONCE_ACTION = 'twtaeo_diagnostics';
	const NONCE_NAME   = 'twtaeo_diagnostics_nonce';

	/**
	 * Render the Diagnostics admin page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$notice = '';
		if ( ! empty( $_POST[ self::NONCE_NAME ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION )
			&& ! empty( $_POST['twtaeo_clear_log'] )
		) {
			TWTAEO_Logger::clear();
			$notice = __( 'Log cleared.', 'twt-aeo-ultimate' );
		}

		$entries = TWTAEO_Logger::get_entries();
		$report  = self::build_report_text( $entries );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Diagnostics', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<?php if ( $notice ) : ?>
			<div class="notice notice-success inline" style="margin:0 0 14px;"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Support Report', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'If you hit a problem, click Copy and paste this to support.', 'twt-aeo-ultimate' ); ?>
					</p>
					<div class="notice notice-warning inline" style="margin:0 0 14px;padding:8px 12px;">
						<p style="margin:0;">
							<?php echo wp_kses(
								__( '<strong>Before you share:</strong> this report lists your server software, WordPress/PHP version numbers, and active plugins with their versions. That information is useful for troubleshooting but can also help an attacker, so only send it to support you trust. It does <strong>not</strong> include passwords, API keys, or your site content.', 'twt-aeo-ultimate' ),
								array( 'strong' => array() )
							); ?>
						</p>
					</div>
					<p>
						<button type="button" id="twt-aeo-copy-report" class="button button-primary">
							<?php esc_html_e( 'Copy report to clipboard', 'twt-aeo-ultimate' ); ?>
						</button>
						<span id="twt-aeo-copy-status" style="margin-left:10px;color:#16a34a;font-weight:600;display:none;">
							<?php esc_html_e( 'Copied!', 'twt-aeo-ultimate' ); ?>
						</span>
					</p>
					<textarea id="twt-aeo-report" readonly rows="16"
						style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;white-space:pre;overflow:auto;"><?php echo esc_textarea( $report ); ?></textarea>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
					<?php
					printf(
						/* translators: %d: number of log entries. */
						esc_html__( 'Recent Log (%d)', 'twt-aeo-ultimate' ),
						count( $entries )
					);
					?>
				</h2>
				<div class="twt-aeo-card">
					<?php if ( empty( $entries ) ) : ?>
						<p class="twt-aeo-empty"><?php esc_html_e( 'No errors logged. That\'s a good thing.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<div class="twt-aeo-page-table-wrap">
							<table class="twt-aeo-page-table widefat striped">
								<thead>
									<tr>
										<th style="width:150px;"><?php esc_html_e( 'Time', 'twt-aeo-ultimate' ); ?></th>
										<th style="width:80px;"><?php esc_html_e( 'Level', 'twt-aeo-ultimate' ); ?></th>
										<th><?php esc_html_e( 'Message', 'twt-aeo-ultimate' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $entries as $entry ) :
										$level = $entry['level'] ?? 'info';
										$color = 'error' === $level ? '#dc2626' : ( 'warning' === $level ? '#d97706' : '#6b7280' );
										?>
										<tr>
											<td style="white-space:nowrap;font-size:12px;color:#6b7280;"><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
											<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;text-transform:uppercase;font-size:11px;"><?php echo esc_html( $level ); ?></span></td>
											<td>
												<div style="font-size:13px;"><?php echo esc_html( $entry['message'] ?? '' ); ?></div>
												<?php if ( ! empty( $entry['context'] ) ) : ?>
												<div style="font-size:11px;color:#6b7280;font-family:Menlo,Consolas,monospace;margin-top:3px;word-break:break-word;">
													<?php echo esc_html( self::format_context( $entry['context'] ) ); ?>
												</div>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<form method="post" style="margin-top:14px;">
							<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
							<button type="submit" name="twtaeo_clear_log" value="1" class="button">
								<?php esc_html_e( 'Clear log', 'twt-aeo-ultimate' ); ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</section>

		</div>
		<?php

		ob_start();
		?>
		( function() {
			var btn = document.getElementById( 'twt-aeo-copy-report' );
			var ta  = document.getElementById( 'twt-aeo-report' );
			var ok  = document.getElementById( 'twt-aeo-copy-status' );
			if ( ! btn || ! ta ) { return; }

			btn.addEventListener( 'click', function() {
				ta.select();
				ta.setSelectionRange( 0, ta.value.length );
				var done = function() {
					if ( ok ) {
						ok.style.display = 'inline';
						setTimeout( function() { ok.style.display = 'none'; }, 2000 );
					}
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( ta.value ).then( done, function() {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} )();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	/**
	 * Build the plaintext support report (system info + log).
	 *
	 * @param array $entries Log entries (most recent first).
	 * @return string
	 */
	private static function build_report_text( array $entries ) {
		$lines = array();

		$lines[] = '=== TWT AEO Ultimate — Support Report ===';
		$lines[] = 'Generated: ' . current_time( 'mysql' );
		$lines[] = '';

		foreach ( self::get_system_info() as $section => $rows ) {
			$lines[] = '[' . $section . ']';
			foreach ( $rows as $label => $value ) {
				$lines[] = '  ' . $label . ': ' . $value;
			}
			$lines[] = '';
		}

		$lines[] = '[Recent Log]';
		if ( empty( $entries ) ) {
			$lines[] = '  (none)';
		} else {
			foreach ( $entries as $entry ) {
				$line = '  ' . ( $entry['time'] ?? '' )
					. ' [' . strtoupper( $entry['level'] ?? 'info' ) . '] '
					. ( $entry['message'] ?? '' );
				if ( ! empty( $entry['context'] ) ) {
					$line .= ' | ' . self::format_context( $entry['context'] );
				}
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Gather environment and plugin diagnostics.
	 *
	 * @return array Section => [ label => value ].
	 */
	private static function get_system_info() {
		global $wpdb;

		$info = array();

		// Plugin.
		$modules = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		$info['Plugin'] = array(
			'Version'        => defined( 'TWTAEO_VERSION' ) ? TWTAEO_VERSION : 'unknown',
			'Active modules' => is_array( $modules ) && $modules ? implode( ', ', $modules ) : '(none)',
		);

		// Document upload requirements (Content Gap Engine) — the same checks,
		// in the same words, as the Documents screen shows the owner.
		if ( class_exists( 'TWTAEO_Doc_Requirements' ) ) {
			$doc_rows = array();
			foreach ( TWTAEO_Doc_Requirements::checks() as $check ) {
				$doc_rows[ $check['label'] ] = $check['ok']
					? 'OK' . ( '' !== $check['detail'] ? ' (' . $check['detail'] . ')' : '' )
					: strtoupper( $check['level'] ) . ': ' . $check['message'];
			}
			$info['Document upload'] = $doc_rows;
		}

		// Environment.
		$info['Environment'] = array(
			'WordPress'           => get_bloginfo( 'version' ),
			'PHP'                 => PHP_VERSION,
			'Database'            => is_object( $wpdb ) ? $wpdb->db_version() : 'unknown',
			'Web server'          => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'WP memory limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'default',
			'PHP memory_limit'    => (string) ini_get( 'memory_limit' ),
			'Max execution time'  => (string) ini_get( 'max_execution_time' ),
			'Upload max size'     => (string) ini_get( 'upload_max_filesize' ),
			'WP_DEBUG'            => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'on' : 'off',
			'Multisite'           => is_multisite() ? 'yes' : 'no',
			'HTTPS'               => is_ssl() ? 'yes' : 'no',
			'Locale'              => get_locale(),
			'Home URL'            => home_url(),
		);

		// Theme.
		$theme = wp_get_theme();
		$info['Theme'] = array(
			'Name'    => $theme->get( 'Name' ),
			'Version' => $theme->get( 'Version' ),
		);

		// SEO plugins detected.
		if ( class_exists( 'TWTAEO_SEO_Compatibility' ) ) {
			$active_seo = TWTAEO_SEO_Compatibility::detect_active_plugins();
			$seo_rows   = array();
			if ( $active_seo ) {
				foreach ( $active_seo as $seo ) {
					$seo_rows[ $seo['name'] ] = $seo['version'];
				}
			} else {
				$seo_rows['Detected'] = '(none)';
			}
			$info['SEO plugins'] = $seo_rows;
		}

		// Active plugins.
		$info['Active plugins'] = self::get_active_plugin_list();

		return $info;
	}

	/**
	 * Build a name => version map of active plugins.
	 *
	 * @return array
	 */
	private static function get_active_plugin_list() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$list = array();
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$list[ $all[ $file ]['Name'] ] = $all[ $file ]['Version'];
			} else {
				$list[ $file ] = '?';
			}
		}

		if ( empty( $list ) ) {
			$list['Detected'] = '(none)';
		}

		return $list;
	}

	/**
	 * Flatten a context array into a compact "key=value" string.
	 *
	 * @param array $context Context map.
	 * @return string
	 */
	private static function format_context( $context ) {
		if ( ! is_array( $context ) ) {
			return (string) $context;
		}

		$parts = array();
		foreach ( $context as $key => $value ) {
			if ( 'trace' === $key ) {
				continue; // Traces are verbose; keep them out of the one-line summary.
			}
			$parts[] = $key . '=' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
		}

		return implode( ' ', $parts );
	}
}
