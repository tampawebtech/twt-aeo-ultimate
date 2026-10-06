<?php
/**
 * Settings → MCPs — switch on the owner's Claude connector and hand out the setup.
 *
 * Turns TWTAEO_Owner_MCP on or off, creates a WordPress Application Password
 * for it, and shows that password exactly once inside a ready-to-paste Claude
 * Code command and a Claude Desktop / Cowork config. The password is never
 * stored by this page; WordPress keeps only its hash.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Claude {

	const NONCE_ACTION = 'twtaeo_claude_mcp';
	const NONCE_NAME   = 'twtaeo_claude_mcp_nonce';

	/** The MCPs tab of the Settings page (TWTAEO_Page_Settings checks manage_options). */
	public static function render_content() {

		$notice         = '';
		$error          = '';
		$confirm_revert = '';
		$password = '';
		$user     = wp_get_current_user();

		if ( ! empty( $_POST[ self::NONCE_NAME ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION )
		) {
			if ( isset( $_POST['twtaeo_mcp_enable'] ) ) {
				$on = '1' === sanitize_key( wp_unslash( $_POST['twtaeo_mcp_enable'] ) );
				update_option( TWTAEO_Owner_MCP::OPTION_ENABLED, $on );
				$notice = $on
					? __( 'The Claude connector is on.', 'twt-aeo-ultimate' )
					: __( 'The Claude connector is off. Claude can no longer read this site\'s AEO data.', 'twt-aeo-ultimate' );
			} elseif ( isset( $_POST['twtaeo_mcp_writes'] ) ) {
				$on = '1' === sanitize_key( wp_unslash( $_POST['twtaeo_mcp_writes'] ) );
				update_option( TWTAEO_Owner_MCP::OPTION_WRITES, $on );
				$notice = $on
					? __( 'Claude can now make the changes listed below, each one only after you approve its preview. Start a new Claude session to see the new tools.', 'twt-aeo-ultimate' )
					: __( 'Changes are off. Claude can read your AEO data but not change anything.', 'twt-aeo-ultimate' );
			} elseif ( ! empty( $_POST['twtaeo_mcp_revert'] ) ) {
				$change = sanitize_key( wp_unslash( $_POST['twtaeo_mcp_revert'] ) );
				$force  = ! empty( $_POST['twtaeo_mcp_force'] );
				$result = TWTAEO_Owner_Actions::revert( $change, $force );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					if ( 'twtaeo_revert_changed' === $result->get_error_code() ) {
						$confirm_revert = $change;
					}
				} else {
					$notice = __( 'Change reverted. The revert is in the log too, so you can undo it.', 'twt-aeo-ultimate' );
				}
			} elseif ( ! empty( $_POST['twtaeo_mcp_create_password'] ) ) {
				$created = WP_Application_Passwords::create_new_application_password(
					$user->ID,
					array(
						/* translators: %s: today's date. */
						'name' => sprintf( __( 'Claude (AEO Ultimate) %s', 'twt-aeo-ultimate' ), wp_date( 'Y-m-d' ) ),
					)
				);
				if ( is_wp_error( $created ) ) {
					$error = $created->get_error_message();
				} else {
					$password = $created[0];
				}
			}
		}

		$enabled   = TWTAEO_Owner_MCP::is_enabled();
		$writes    = TWTAEO_Owner_MCP::writes_allowed();
		$log       = TWTAEO_Owner_Actions::get_log();
		$can_pw    = wp_is_application_passwords_available_for_user( $user );
		$endpoint  = TWTAEO_Owner_MCP::endpoint_url();
		$last_used = get_option( TWTAEO_Owner_MCP::OPTION_LAST_USED );
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name      = 'aeo-ultimate-' . trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );

		$auth = '';
		if ( $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication header, not obfuscation.
			$auth = 'Basic ' . base64_encode( $user->user_login . ':' . $password );
		}
		$shown_auth = $auth ? $auth : 'Basic YOUR-CONNECTION-PASSWORD';

		$code_cmd = sprintf(
			'claude mcp add --transport http --scope user %1$s %2$s --header "Authorization: %3$s"',
			$name,
			$endpoint,
			$shown_auth
		);

		$remote_args = array( '-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${AEO_AUTH}' );
		if ( 0 === strpos( $endpoint, 'http://' ) ) {
			$remote_args[] = '--allow-http';
		}
		$desktop_json = wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'command' => 'npx',
						'args'    => $remote_args,
						'env'     => array( 'AEO_AUTH' => $shown_auth ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		?>
		<div class="twt-aeo-mcps">

			<?php if ( $notice ) : ?>
			<div class="notice notice-success inline" style="margin:0 0 14px;"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
			<div class="notice notice-error inline" style="margin:0 0 14px;">
				<p><?php echo esc_html( $error ); ?></p>
				<?php if ( $confirm_revert ) : ?>
				<form method="post" style="margin:0 0 10px;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="twtaeo_mcp_force" value="1">
					<button type="submit" name="twtaeo_mcp_revert" value="<?php echo esc_attr( $confirm_revert ); ?>" class="button"><?php esc_html_e( 'Revert anyway', 'twt-aeo-ultimate' ); ?></button>
				</form>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( '1. Turn on the connector', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<p>
						<?php esc_html_e( 'Ask Claude about your AI citations, which pages AI crawlers visit, Google indexing, schema, what each page is missing and which questions you have no content for. Claude can only read this data unless you also allow changes below.', 'twt-aeo-ultimate' ); ?>
					</p>
					<form method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<p>
							<strong><?php esc_html_e( 'Status:', 'twt-aeo-ultimate' ); ?></strong>
							<?php if ( $enabled ) : ?>
								<span style="color:#16a34a;font-weight:600;"><?php esc_html_e( 'On', 'twt-aeo-ultimate' ); ?></span>
								<button type="submit" name="twtaeo_mcp_enable" value="0" class="button" style="margin-left:10px;"><?php esc_html_e( 'Turn off', 'twt-aeo-ultimate' ); ?></button>
							<?php else : ?>
								<span style="font-weight:600;"><?php esc_html_e( 'Off', 'twt-aeo-ultimate' ); ?></span>
								<button type="submit" name="twtaeo_mcp_enable" value="1" class="button button-primary" style="margin-left:10px;"><?php esc_html_e( 'Turn on', 'twt-aeo-ultimate' ); ?></button>
							<?php endif; ?>
						</p>
					</form>
					<?php if ( is_array( $last_used ) && ! empty( $last_used['time'] ) ) : ?>
					<p class="twt-aeo-card__note">
						<?php
						printf(
							/* translators: 1: time ago, e.g. "5 mins", 2: app name such as "claude-code". */
							esc_html__( 'Last connected %1$s ago from %2$s.', 'twt-aeo-ultimate' ),
							esc_html( human_time_diff( (int) $last_used['time'] ) ),
							esc_html( ! empty( $last_used['client'] ) ? $last_used['client'] : __( 'an unnamed app', 'twt-aeo-ultimate' ) )
						);
						?>
					</p>
					<?php endif; ?>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( '2. Create a connection password', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<?php if ( ! $can_pw ) : ?>
						<div class="notice notice-warning inline" style="margin:0;">
							<p><?php esc_html_e( 'WordPress Application Passwords are not available for your account. They need the site to run on HTTPS, and a security plugin may have switched them off.', 'twt-aeo-ultimate' ); ?></p>
						</div>
					<?php elseif ( $password ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 14px;">
							<p><strong><?php esc_html_e( 'Copy the setup below now. This password is shown only once; if you leave this page, create a new one.', 'twt-aeo-ultimate' ); ?></strong></p>
						</div>
						<p class="twt-aeo-card__note"><?php esc_html_e( 'It is already filled into the steps below. Anyone who has it can read this site\'s AEO data, so keep it private.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<p>
							<?php esc_html_e( 'Claude signs in with a WordPress Application Password tied to your account. It works only for apps like Claude, not for logging in to WordPress, and you can revoke it at any time.', 'twt-aeo-ultimate' ); ?>
						</p>
						<form method="post">
							<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
							<button type="submit" name="twtaeo_mcp_create_password" value="1" class="button button-primary"><?php esc_html_e( 'Create connection password', 'twt-aeo-ultimate' ); ?></button>
						</form>
					<?php endif; ?>
					<p class="twt-aeo-card__note" style="margin-top:12px;">
						<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'See or revoke your Application Passwords', 'twt-aeo-ultimate' ); ?></a>
					</p>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( '3. Connect Claude', 'twt-aeo-ultimate' ); ?></h2>

				<div class="twt-aeo-card">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Claude Code', 'twt-aeo-ultimate' ); ?></h3>
					<p><?php esc_html_e( 'Run this once in a terminal. It adds AEO Ultimate to Claude Code for all your projects.', 'twt-aeo-ultimate' ); ?></p>
					<?php self::copy_box( 'twt-aeo-claude-code', $code_cmd, 3 ); ?>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'Then ask, for example: "Which AI engines cite my site, and which questions am I missing?"', 'twt-aeo-ultimate' ); ?></p>
				</div>

				<div class="twt-aeo-card">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Claude Desktop and Cowork', 'twt-aeo-ultimate' ); ?></h3>
					<p>
						<?php esc_html_e( 'In Claude Desktop, open Settings → Developer → Edit Config, add this to claude_desktop_config.json (merge it into any "mcpServers" already there) and restart Claude. Needs Node.js installed.', 'twt-aeo-ultimate' ); ?>
					</p>
					<?php self::copy_box( 'twt-aeo-claude-desktop', (string) $desktop_json, 12 ); ?>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'A one-click Claude Desktop installer is on the way.', 'twt-aeo-ultimate' ); ?></p>
				</div>

				<div class="twt-aeo-card">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Claude on the web and mobile', 'twt-aeo-ultimate' ); ?></h3>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to app.aeoultimate.app */
								esc_html__( 'Create a free account at %s (any email address works) and add this site. Paste this site\'s connection key into the site\'s Claude section there:', 'twt-aeo-ultimate' ),
								'<a href="https://app.aeoultimate.app" target="_blank" rel="noopener noreferrer">app.aeoultimate.app</a>'
							),
							array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
						);
						?>
					</p>
					<?php self::copy_box( 'twt-aeo-claude-key', '' !== $auth ? substr( $auth, 6 ) : __( 'Click "Create connection password" above to see this site\'s connection key. It is shown only once.', 'twt-aeo-ultimate' ), 1 ); ?>
					<p><?php esc_html_e( 'Then in Claude open Settings → Connectors → Add custom connector, paste this URL and sign in with your aeoultimate.app account. The URL is the same for everyone; the connection key is what links this site to your account.', 'twt-aeo-ultimate' ); ?></p>
					<?php self::copy_box( 'twt-aeo-claude-hosted', 'https://mcp.aeoultimate.app/mcp', 1 ); ?>
					<p class="twt-aeo-card__note"><?php esc_html_e( 'One account connects all your sites to Claude on the web, desktop and mobile. One site is free; connecting a second site starts a 14-day trial of the multi-site plan. Your key is stored encrypted, and this site keeps its own rules: admin only, changes only when allowed below, each one previewed first.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What Claude can read', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<ul style="margin:0;list-style:disc;padding-left:20px;">
						<?php foreach ( TWTAEO_Abilities::definitions() as $def ) : ?>
						<li style="margin-bottom:8px;"><strong><?php echo esc_html( $def['label'] ); ?></strong> — <?php echo esc_html( $def['description'] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="twt-aeo-card__note" style="margin-top:12px;">
						<?php esc_html_e( 'Never shared: API keys, settings, visitor IP addresses or the full text of AI answers. Nothing is sent anywhere until you connect Claude, and then only to the Claude app you connected.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What Claude can change', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<form method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<p>
							<strong><?php esc_html_e( 'Allow changes:', 'twt-aeo-ultimate' ); ?></strong>
							<?php if ( $writes ) : ?>
								<span style="color:#16a34a;font-weight:600;"><?php esc_html_e( 'On', 'twt-aeo-ultimate' ); ?></span>
								<button type="submit" name="twtaeo_mcp_writes" value="0" class="button" style="margin-left:10px;"><?php esc_html_e( 'Turn off', 'twt-aeo-ultimate' ); ?></button>
							<?php else : ?>
								<span style="font-weight:600;"><?php esc_html_e( 'Off', 'twt-aeo-ultimate' ); ?></span>
								<button type="submit" name="twtaeo_mcp_writes" value="1" class="button" style="margin-left:10px;"><?php esc_html_e( 'Allow changes', 'twt-aeo-ultimate' ); ?></button>
							<?php endif; ?>
						</p>
					</form>
					<p><?php esc_html_e( 'Every change works in two steps: Claude first shows you exactly what it will do, and makes the change only after you say yes. One page at a time, never in bulk.', 'twt-aeo-ultimate' ); ?></p>
					<ul style="margin:0;list-style:disc;padding-left:20px;">
						<?php foreach ( TWTAEO_Owner_Actions::definitions() as $def ) : ?>
						<li style="margin-bottom:8px;"><strong><?php echo esc_html( $def['label'] ); ?></strong> — <?php echo esc_html( $def['description'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</section>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Changes made by Claude', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<?php if ( ! $log ) : ?>
						<p class="twt-aeo-card__note" style="margin:0;"><?php esc_html_e( 'None yet.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'When', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'What', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Where', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Before → after', 'twt-aeo-ultimate' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $log as $row ) : ?>
								<tr>
									<td>
										<?php
										/* translators: %s: time ago, e.g. "5 mins". */
										echo esc_html( sprintf( __( '%s ago', 'twt-aeo-ultimate' ), human_time_diff( (int) $row['time'] ) ) );
										$who = get_userdata( (int) $row['user'] );
										if ( $who ) {
											echo '<br><span class="twt-aeo-card__note">' . esc_html( $who->display_name ) . '</span>';
										}
										?>
									</td>
									<td><?php echo esc_html( $row['summary'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['post_id'] ) && get_edit_post_link( (int) $row['post_id'] ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'], 'raw' ) ); ?>"><?php echo esc_html( $row['target'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['target'] ); ?>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( '' !== $row['before'] || '' !== $row['after'] ) : ?>
											<span style="color:#b91c1c;"><?php echo esc_html( '' !== $row['before'] ? $row['before'] : __( '(none)', 'twt-aeo-ultimate' ) ); ?></span>
											<br>→ <span style="color:#15803d;"><?php echo esc_html( $row['after'] ); ?></span>
										<?php endif; ?>
									</td>
									<td style="white-space:nowrap;">
										<?php
										if ( ! empty( $row['reverted'] ) ) {
											$by = get_userdata( (int) $row['reverted']['user'] );
											printf(
												/* translators: 1: time ago, 2: user name. */
												esc_html__( 'Reverted %1$s ago by %2$s', 'twt-aeo-ultimate' ),
												esc_html( human_time_diff( (int) $row['reverted']['time'] ) ),
												esc_html( $by ? $by->display_name : __( 'a deleted user', 'twt-aeo-ultimate' ) )
											);
										} elseif ( ! empty( $row['snapshot']['kind'] ) && ! empty( $row['id'] ) ) {
											?>
											<form method="post" style="margin:0;">
												<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
												<button type="submit" name="twtaeo_mcp_revert" value="<?php echo esc_attr( $row['id'] ); ?>" class="button button-small"><?php esc_html_e( 'Revert', 'twt-aeo-ultimate' ); ?></button>
											</form>
											<?php
										} elseif ( in_array( $row['action'], array( 'run-ai-visibility-check', 'recheck-google-index' ), true ) ) {
											echo '<span class="twt-aeo-card__note">' . esc_html__( 'Cannot be undone', 'twt-aeo-ultimate' ) . '</span>';
										}
										?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>

		</div>
		<?php

		ob_start();
		?>
		( function() {
			document.querySelectorAll( '.twt-aeo-copy-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var ta = document.getElementById( btn.getAttribute( 'data-target' ) );
					if ( ! ta ) { return; }
					var done = function() {
						var old = btn.textContent;
						btn.textContent = btn.getAttribute( 'data-done' );
						setTimeout( function() { btn.textContent = old; }, 2000 );
					};
					ta.select();
					ta.setSelectionRange( 0, ta.value.length );
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
			} );
		} )();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	/** A read-only textarea with a Copy button. */
	private static function copy_box( $id, $text, $rows ) {
		?>
		<textarea id="<?php echo esc_attr( $id ); ?>" readonly rows="<?php echo (int) $rows; ?>"
			style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;white-space:pre;overflow:auto;"><?php echo esc_textarea( $text ); ?></textarea>
		<p style="margin:6px 0 0;">
			<button type="button" class="button twt-aeo-copy-btn"
				data-target="<?php echo esc_attr( $id ); ?>"
				data-done="<?php esc_attr_e( 'Copied!', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?></button>
		</p>
		<?php
	}
}
