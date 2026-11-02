<?php
/**
 * Lead Attribution Page
 *
 * Which leads (form entries, email clicks, phone taps) AI assistants sent,
 * at what confidence, and the evidence for each. Engine lives in
 * TWTAEO_Lead_Attribution.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Lead_Attribution {

	const SLUG = 'twt-aeo-lead-attribution';

	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		TWTAEO_Lead_Attribution::maybe_create_table();

		if ( isset( $_POST['twtaeo_leads_save'] ) && check_admin_referer( 'twtaeo_leads_settings' ) ) {
			TWTAEO_Lead_Attribution::save_settings(
				isset( $_POST['window_minutes'] ) ? absint( $_POST['window_minutes'] ) : 30,
				! empty( $_POST['email_line'] )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'twt-aeo-ultimate' ) . '</p></div>';
		}

		$days     = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$settings = TWTAEO_Lead_Attribution::get_settings();
		$counts   = TWTAEO_Lead_Attribution::get_counts( $days );
		$leads    = TWTAEO_Lead_Attribution::get_leads( $days, 200 );
		$labels   = TWTAEO_Lead_Attribution::tier_labels();
		$colors   = array(
			'confirmed'     => '#00753a',
			'correlated'    => '#2271b1',
			'self_reported' => '#8a4baf',
			'earlier_ai'    => '#996800',
			'none'          => '#646970',
		);
		$kinds = array(
			'form'        => __( 'Form', 'twt-aeo-ultimate' ),
			'email_click' => __( 'Email click', 'twt-aeo-ultimate' ),
			'phone_tap'   => __( 'Phone tap', 'twt-aeo-ultimate' ),
		);
		$ai_total = $counts['confirmed'] + $counts['correlated'] + $counts['self_reported'];
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Lead Attribution', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Which leads AI assistants sent you. Every form entry, email click and phone tap gets one tier with the evidence behind it, so a lead is never counted twice.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<p>
						<?php
						printf(
							/* translators: 1: number of AI leads, 2: number of days, 3: leads whose earlier visit came from AI. */
							esc_html__( '%1$d leads from AI assistants in the last %2$d days, plus %3$d whose first visit came from one.', 'twt-aeo-ultimate' ),
							(int) $ai_total,
							(int) $days,
							(int) $counts['earlier_ai']
						);
						?>
						<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'days' => $d ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small"<?php echo $d === $days ? ' disabled' : ''; ?>>
								<?php
								/* translators: %d: number of days. */
								printf( esc_html__( '%d days', 'twt-aeo-ultimate' ), (int) $d );
								?>
							</a>
						<?php endforeach; ?>
					</p>
					<div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0;">
						<?php foreach ( $labels as $tier => $label ) : ?>
							<div style="border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr( $colors[ $tier ] ); ?>;padding:10px 14px;min-width:140px;background:#fff;">
								<div style="font-size:22px;font-weight:600;"><?php echo (int) $counts[ $tier ]; ?></div>
								<div><?php echo esc_html( $label ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Confirmed: the visit came from an AI engine\'s link. Correlated: an AI assistant read the landing page shortly before a direct visit arrived there. Self-reported: the visitor named an AI tool in "How did you hear about us?". Earlier AI visit: this visit came another way, but their first visit came from an AI engine.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<h2><?php esc_html_e( 'Recent leads', 'twt-aeo-ultimate' ); ?></h2>
					<?php if ( empty( $leads ) ) : ?>
						<p><?php esc_html_e( 'No leads recorded yet. Gravity Forms, Contact Form 7, WPForms, Forminator and HubSpot entries are recorded when the form accepts them; other forms, and email and phone link clicks, are recorded as the visitor submits or clicks.', 'twt-aeo-ultimate' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'When', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Lead', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Tier', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Evidence', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Landing page', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'First visit', 'twt-aeo-ultimate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $leads as $r ) : ?>
									<tr>
										<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'M j, g:i a' ) ); ?></td>
										<td>
											<?php echo esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] ); ?>
											<?php if ( '' !== $r['form_ref'] ) : ?>
												<br><span class="description"><?php echo esc_html( $r['form_ref'] ); ?></span>
											<?php endif; ?>
											<?php if ( '' !== $r['entry_id'] && 'gravityforms' === $r['via'] && preg_match( '/^GF #(\d+)/', $r['form_ref'], $m ) ) : ?>
												<br><a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $m[1] . '&lid=' . (int) $r['entry_id'] ) ); ?>">
													<?php
													/* translators: %s: entry ID. */
													printf( esc_html__( 'Entry %s', 'twt-aeo-ultimate' ), esc_html( $r['entry_id'] ) );
													?>
												</a>
											<?php endif; ?>
											<?php if ( 'form' === $r['kind'] && ! (int) $r['confirmed'] ) : ?>
												<br><span class="description" title="<?php esc_attr_e( 'Recorded when the visitor pressed submit. This form plugin is not read directly, so a submission it rejected can still appear here.', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'submit attempt', 'twt-aeo-ultimate' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<strong style="color:<?php echo esc_attr( $colors[ $r['tier'] ] ?? '#646970' ); ?>;"><?php echo esc_html( $labels[ $r['tier'] ] ?? $r['tier'] ); ?></strong>
											<?php if ( '' !== $r['engine'] ) : ?>
												<br><?php echo esc_html( $r['engine'] ); ?>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( implode( ' ', $r['evidence'] ) ); ?></td>
										<td>
											<?php $path = (string) wp_parse_url( $r['landing_url'], PHP_URL_PATH ); ?>
											<?php echo esc_html( '' !== $path ? $path : '—' ); ?>
											<?php if ( '' !== $r['landing_ref'] ) : ?>
												<br><span class="description"><?php echo esc_html( $r['landing_ref'] ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php echo esc_html( '' !== $r['first_source'] ? $r['first_source'] : '—' ); ?>
											<?php if ( ! empty( $r['first_at'] ) ) : ?>
												<br><span class="description"><?php echo esc_html( get_date_from_gmt( $r['first_at'], 'M j' ) ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<h2><?php esc_html_e( 'Add "How did you hear about us?" to your form', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'The visitor\'s own answer confirms the other tiers and catches AI visits nothing else can see. Any field whose label or name contains "hear", "find us", "source" or "referral" is read; answers naming ChatGPT, Gemini, Perplexity, Copilot, Claude, DeepSeek, Grok or "AI" count as AI.', 'twt-aeo-ultimate' ); ?></p>
					<p><strong><?php esc_html_e( 'Choices to use (any form plugin):', 'twt-aeo-ultimate' ); ?></strong></p>
					<textarea readonly rows="10" class="large-text code" onclick="this.select()"><?php echo esc_textarea( "Google search\nChatGPT\nGoogle Gemini\nPerplexity\nMicrosoft Copilot\nClaude\nAnother AI tool\nSocial media\nReferral from someone\nOther" ); ?></textarea>
					<p><strong><?php esc_html_e( 'Contact Form 7 tag:', 'twt-aeo-ultimate' ); ?></strong></p>
					<input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( '<label>How did you hear about us? [select how-did-you-hear include_blank "Google search" "ChatGPT" "Google Gemini" "Perplexity" "Microsoft Copilot" "Claude" "Another AI tool" "Social media" "Referral from someone" "Other"]</label>' ); ?>">
					<p class="description"><?php esc_html_e( 'Gravity Forms and WPForms: add a Drop Down field labelled "How did you hear about us?" and paste the choices above.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</div>

			<div class="twt-aeo-section">
				<div class="twt-aeo-card">
					<h2><?php esc_html_e( 'Settings', 'twt-aeo-ultimate' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'twtaeo_leads_settings' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="twtaeo-window"><?php esc_html_e( 'Correlation window', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input id="twtaeo-window" type="number" min="1" max="240" name="window_minutes" value="<?php echo (int) $settings['window_minutes']; ?>" class="small-text"> <?php esc_html_e( 'minutes', 'twt-aeo-ultimate' ); ?>
									<p class="description"><?php esc_html_e( 'How long after an AI assistant reads a page a direct visit to that page still counts as correlated. Shorter is stricter.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Notification email', 'twt-aeo-ultimate' ); ?></th>
								<td>
									<label><input type="checkbox" name="email_line" value="1" <?php checked( $settings['email_line'] ); ?>> <?php esc_html_e( 'Add an "AEO attribution" line to form notification emails sent to you (Gravity Forms, Contact Form 7, WPForms, Forminator). Never added to emails sent to the visitor.', 'twt-aeo-ultimate' ); ?></label>
								</td>
							</tr>
						</table>
						<p><button type="submit" name="twtaeo_leads_save" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'twt-aeo-ultimate' ); ?></button></p>
					</form>
					<p class="description"><?php esc_html_e( 'Stored in your own database: the tier, the engine, page addresses and how the visit arrived. No names, email addresses, IP addresses or browser details. The visitor\'s first and latest visit source is kept in their own browser storage.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
