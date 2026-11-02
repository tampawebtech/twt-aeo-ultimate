<?php
/**
 * Google Reviews page: connect, read, reply.
 *
 * Plain forms that post and redirect back, so a reply never depends on
 * JavaScript working on the site. See TWTAEO_GBP_Reviews for the sync and alerts.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Google_Reviews {

	const NONCE    = 'twtaeo_gbp_reviews';
	const PER_PAGE = 25;

	// ── POST handling (admin_init, before any output) ────────────────────────

	public static function maybe_handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'twt-aeo-google-reviews' !== ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) {
			return;
		}
		if ( empty( $_POST['twtaeo_gbp_action'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['twtaeo_gbp_action'] ) );
		$id     = absint( $_POST['review_id'] ?? 0 );
		$text   = sanitize_textarea_field( wp_unslash( $_POST['reply'] ?? '' ) );
		$back   = wp_get_referer() ? wp_get_referer() : TWTAEO_GBP_Reviews::page_url();

		switch ( $action ) {
			case 'connect':
				$go_url = TWTAEO_Google_Hub::start( array( 'business_profile' ), TWTAEO_GBP_Reviews::page_url() );
				if ( is_wp_error( $go_url ) ) {
					/* translators: %s: error message. */
					self::say( 'error', sprintf( __( 'Could not start connecting Google: %s', 'twt-aeo-ultimate' ), $go_url->get_error_message() ) );
					break;
				}
				wp_redirect( $go_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- aeoultimate.app sign-in page, an external host by design.
				exit;

			case 'sync':
				$result = TWTAEO_GBP_Reviews::sync();
				if ( is_wp_error( $result ) ) {
					self::say( 'error', $result->get_error_message() );
				} else {
					self::say( 'success', __( 'Reviews are up to date.', 'twt-aeo-ultimate' ) );
				}
				break;

			case 'reply':
				$result = TWTAEO_GBP_Reviews::post_reply( $id, $text );
				if ( is_wp_error( $result ) ) {
					// Keep what they wrote.
					TWTAEO_GBP_Reviews::save_draft( $id, $text );
					self::say( 'error', $result->get_error_message() );
				} else {
					self::say( 'success', __( 'Your reply is posted on Google.', 'twt-aeo-ultimate' ) );
				}
				break;

			case 'delete_reply':
				$result = TWTAEO_GBP_Reviews::delete_reply( $id );
				if ( is_wp_error( $result ) ) {
					self::say( 'error', $result->get_error_message() );
				} else {
					self::say( 'success', __( 'Your reply was removed from Google.', 'twt-aeo-ultimate' ) );
				}
				break;

			case 'draft':
				$result = TWTAEO_GBP_Reviews::generate_draft( $id );
				if ( is_wp_error( $result ) ) {
					self::say( 'error', $result->get_error_message() );
				} else {
					self::say( 'info', __( 'Draft ready. Nothing has been posted: read it, edit it, then click Post reply.', 'twt-aeo-ultimate' ) );
				}
				break;

			case 'dismiss':
				TWTAEO_GBP_Reviews::set_dismissed( $id, true );
				break;

			case 'undismiss':
				TWTAEO_GBP_Reviews::set_dismissed( $id, false );
				break;

			case 'settings':
				$locations = isset( $_POST['locations'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['locations'] ) ) : array();
				TWTAEO_GBP_Reviews::save_settings(
					sanitize_text_field( wp_unslash( $_POST['recipients'] ?? '' ) ),
					sanitize_key( wp_unslash( $_POST['email_mode'] ?? 'all' ) ),
					$locations
				);
				self::say( 'success', __( 'Settings saved.', 'twt-aeo-ultimate' ) );
				break;
		}

		wp_safe_redirect( $back );
		exit;
	}

	private static function say( $type, $message ) {
		TWTAEO_Google_Hub::notice( $type, $message );
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}
		TWTAEO_GBP_Reviews::maybe_create_table();
		$connected = TWTAEO_Local_Pack_NAP::is_gbp_connected();

		// First visit after connecting: fill the list now rather than an hour from now.
		if ( $connected && empty( TWTAEO_GBP_Reviews::get_state()['last_sync'] ) && empty( TWTAEO_GBP_Reviews::get_state()['last_error'] ) ) {
			TWTAEO_GBP_Reviews::sync();
		}
		?>
		<div class="wrap twt-aeo-page">
			<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
				<span class="dashicons dashicons-star-filled" style="font-size:24px;color:#f59e0b;"></span>
				<?php esc_html_e( 'Google Reviews', 'twt-aeo-ultimate' ); ?>
			</h1>
			<p style="color:#646970;margin-top:0;margin-bottom:20px;">
				<?php esc_html_e( 'See new Google reviews here and reply from WordPress, even if Google\'s emails go to someone else. AI assistants read these reviews, so an answered review helps twice.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php
			if ( $connected ) {
				self::render_inbox();
			} else {
				self::render_connect();
			}
			?>
		</div>
		<?php
	}

	private static function action_form_open( $action, $review_id = 0 ) {
		?>
		<form method="post" action="" style="display:inline;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="twtaeo_gbp_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php if ( $review_id ) : ?>
				<input type="hidden" name="review_id" value="<?php echo esc_attr( $review_id ); ?>" />
			<?php endif; ?>
		<?php
	}

	private static function render_connect() {
		$reconnect = TWTAEO_Google_Hub::needs_reconnect();
		?>
		<div class="twt-aeo-card" style="max-width:720px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Connect your Google Business Profile', 'twt-aeo-ultimate' ); ?></h2>
			<?php if ( $reconnect ) : ?>
				<p style="color:#b32d2e;"><?php esc_html_e( 'Google access was removed, so new reviews have stopped coming in. Connect again to resume them.', 'twt-aeo-ultimate' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Sign in with the Google account that owns or manages your Business Profile. You must be an owner or manager of the profile to read its reviews and reply to them.', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>
			<?php if ( TWTAEO_Google_Hub::is_enabled() ) : ?>
				<?php self::action_form_open( 'connect' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect with Google', 'twt-aeo-ultimate' ); ?></button>
				</form>
				<p class="description" style="margin-top:12px;"><?php esc_html_e( 'The connection runs through aeoultimate.app, which keeps only the encrypted connection; your reviews come straight from Google to this site. Replies are only ever posted when you click send.', 'twt-aeo-ultimate' ); ?></p>
				<p class="description"><?php esc_html_e( 'Google is still reviewing this app, so you may see a "Google hasn\'t verified this app" screen. To continue, click Advanced, then Go to the app.', 'twt-aeo-ultimate' ); ?></p>
			<?php else : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-local-pack' ) ); ?>"><?php esc_html_e( 'Connect on the Local Pack page', 'twt-aeo-ultimate' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_inbox() {
		$state  = TWTAEO_GBP_Reviews::get_state();
		$totals = isset( $state['totals'] ) && is_array( $state['totals'] ) ? $state['totals'] : array();
		$sum    = array_sum( array_column( $totals, 'total' ) );
		$avg    = 0.0;
		if ( $sum > 0 ) {
			foreach ( $totals as $t ) {
				$avg += (float) $t['average'] * (int) $t['total'];
			}
			$avg /= $sum;
		}
		$counts = TWTAEO_GBP_Reviews::get_counts();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = sanitize_key( wp_unslash( $_GET['show'] ?? 'waiting' ) );
		if ( ! in_array( $filter, array( 'waiting', 'replied', 'all' ), true ) ) {
			$filter = 'waiting';
		}
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows  = TWTAEO_GBP_Reviews::get_reviews( $filter, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$total = TWTAEO_GBP_Reviews::count_reviews( $filter );
		?>
		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'The last check for reviews failed:', 'twt-aeo-ultimate' ); ?></strong> <?php echo esc_html( $state['last_error'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $state['email_failed'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site could not send the review alert email. New reviews still show here, and the email will be tried again. Check your site\'s email setup.', 'twt-aeo-ultimate' ); ?></p></div>
		<?php endif; ?>

		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 20px;align-items:stretch;">
			<?php
			$stats = array(
				array( __( 'Waiting for a reply', 'twt-aeo-ultimate' ), $counts['waiting'], $counts['waiting'] ? '#d97706' : '#10b981' ),
				array( __( 'Average rating', 'twt-aeo-ultimate' ), $sum ? number_format_i18n( $avg, 1 ) : '—', '#6366f1' ),
				array( __( 'Total on Google', 'twt-aeo-ultimate' ), number_format_i18n( $sum ), '#64748b' ),
			);
			foreach ( $stats as $stat ) :
				?>
				<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 20px;min-width:130px;text-align:center;">
					<div style="font-size:1.8em;font-weight:700;color:<?php echo esc_attr( $stat[2] ); ?>;"><?php echo esc_html( $stat[1] ); ?></div>
					<div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $stat[0] ); ?></div>
				</div>
			<?php endforeach; ?>
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 20px;min-width:180px;">
				<div style="font-size:11px;color:#64748b;margin-bottom:6px;">
					<?php
					echo esc_html( empty( $state['last_sync'] )
						? __( 'Not checked yet', 'twt-aeo-ultimate' )
						/* translators: %s: how long ago, e.g. "5 mins". */
						: sprintf( __( 'Last checked %s ago. Checks every hour.', 'twt-aeo-ultimate' ), human_time_diff( (int) $state['last_sync'] ) ) );
					?>
				</div>
				<?php self::action_form_open( 'sync' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Check now', 'twt-aeo-ultimate' ); ?></button>
				</form>
			</div>
		</div>

		<nav class="twt-aeo-tabs">
			<?php
			$tabs = array(
				'waiting' => __( 'Waiting for a reply', 'twt-aeo-ultimate' ),
				'replied' => __( 'Replied', 'twt-aeo-ultimate' ),
				'all'     => __( 'All', 'twt-aeo-ultimate' ),
			);
			foreach ( $tabs as $slug => $label ) :
				?>
				<a href="<?php echo esc_url( TWTAEO_GBP_Reviews::page_url( array( 'show' => $slug ) ) ); ?>" class="twt-aeo-tab <?php echo $filter === $slug ? 'twt-aeo-tab--active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( 'waiting' === $slug && $counts['waiting'] > 0 ) : ?>
						<span class="twt-aeo-count twt-aeo-count--alert" style="margin-left:2px;"><?php echo esc_html( $counts['waiting'] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! $rows ) : ?>
			<div class="twt-aeo-card" style="margin-top:16px;">
				<p style="margin:0;">
					<?php
					echo esc_html( 'waiting' === $filter
						? __( 'Nothing waiting. New reviews appear here within the hour, and older ones are under All.', 'twt-aeo-ultimate' )
						: __( 'No reviews to show here yet.', 'twt-aeo-ultimate' ) );
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php foreach ( $rows as $row ) : ?>
			<?php self::render_review( $row ); ?>
		<?php endforeach; ?>

		<?php
		if ( $total > self::PER_PAGE ) {
			echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;margin:12px 0;">';
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%', TWTAEO_GBP_Reviews::page_url( array( 'show' => $filter ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => (int) ceil( $total / self::PER_PAGE ),
			) ) );
			echo '</div></div>';
		}
		self::render_settings();
	}

	private static function render_review( $row ) {
		$rating   = (int) $row->rating;
		$low      = $rating <= TWTAEO_GBP_Reviews::LOW_RATING;
		$replying = '' === (string) $row->reply;
		?>
		<div class="twt-aeo-card" style="margin-top:16px;<?php echo $low && $replying && ! $row->dismissed ? 'border-left:4px solid #d97706;' : ''; ?>">
			<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
				<div>
					<span style="color:#f59e0b;font-size:16px;letter-spacing:1px;" aria-hidden="true"><?php echo esc_html( str_repeat( '★', $rating ) ); ?><span style="color:#cbd5e1;"><?php echo esc_html( str_repeat( '★', max( 0, 5 - $rating ) ) ); ?></span></span>
					<span class="screen-reader-text">
						<?php
						/* translators: %d: star rating. */
						echo esc_html( sprintf( __( '%d of 5 stars', 'twt-aeo-ultimate' ), $rating ) );
						?>
					</span>
					<strong style="margin-left:6px;"><?php echo esc_html( $row->reviewer ? $row->reviewer : __( 'A Google user', 'twt-aeo-ultimate' ) ); ?></strong>
				</div>
				<div style="font-size:12px;color:#64748b;">
					<?php echo esc_html( $row->location_title ); ?> · <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->create_time . ' UTC' ) ) ); ?>
				</div>
			</div>
			<p style="white-space:pre-line;margin:10px 0;">
				<?php
				if ( $row->comment ) {
					echo esc_html( $row->comment );
				} else {
					echo '<em style="color:#64748b;">' . esc_html__( 'Rating only, no written comment.', 'twt-aeo-ultimate' ) . '</em>';
				}
				?>
			</p>

			<?php if ( ! $replying ) : ?>
				<div style="background:#f8fafc;border-left:3px solid #2271b1;padding:10px 14px;margin-top:10px;">
					<div style="font-size:12px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Your reply', 'twt-aeo-ultimate' ); ?></div>
					<div style="white-space:pre-line;"><?php echo esc_html( $row->reply ); ?></div>
				</div>
			<?php endif; ?>

			<details style="margin-top:10px;" <?php echo ( $replying && ( $row->draft || ( $low && ! $row->dismissed ) ) ) ? 'open' : ''; ?>>
				<summary style="cursor:pointer;color:#2271b1;"><?php echo esc_html( $replying ? __( 'Write a reply', 'twt-aeo-ultimate' ) : __( 'Edit your reply', 'twt-aeo-ultimate' ) ); ?></summary>
				<?php self::action_form_open( 'reply', (int) $row->id ); ?>
					<textarea name="reply" rows="4" class="large-text" style="margin-top:8px;" maxlength="<?php echo esc_attr( TWTAEO_GBP_Reviews::REPLY_MAX_BYTES ); ?>"><?php echo esc_textarea( $replying ? $row->draft : $row->reply ); ?></textarea>
					<p style="margin:6px 0 0;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Post reply on Google', 'twt-aeo-ultimate' ); ?></button>
					</p>
				</form>
				<p style="margin:8px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
					<?php self::action_form_open( 'draft', (int) $row->id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Draft with AI', 'twt-aeo-ultimate' ); ?></button>
					</form>
					<?php if ( ! $replying ) : ?>
						<?php self::action_form_open( 'delete_reply', (int) $row->id ); ?>
							<button type="submit" class="button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove your reply from Google?', 'twt-aeo-ultimate' ) ); ?>');"><?php esc_html_e( 'Remove reply', 'twt-aeo-ultimate' ); ?></button>
						</form>
					<?php elseif ( $row->dismissed ) : ?>
						<?php self::action_form_open( 'undismiss', (int) $row->id ); ?>
							<button type="submit" class="button-link"><?php esc_html_e( 'Move back to Waiting', 'twt-aeo-ultimate' ); ?></button>
						</form>
					<?php else : ?>
						<?php self::action_form_open( 'dismiss', (int) $row->id ); ?>
							<button type="submit" class="button-link"><?php esc_html_e( 'No reply needed', 'twt-aeo-ultimate' ); ?></button>
						</form>
					<?php endif; ?>
				</p>
				<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Draft with AI sends this review\'s text to the AI provider you set up in Settings. Drafts are never posted until you click Post reply.', 'twt-aeo-ultimate' ); ?></p>
			</details>
		</div>
		<?php
	}

	private static function render_settings() {
		$settings  = TWTAEO_GBP_Reviews::get_settings();
		$locations = TWTAEO_GBP_Reviews::list_locations();
		?>
		<details class="twt-aeo-card" style="margin-top:24px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Alerts and locations', 'twt-aeo-ultimate' ); ?></summary>
			<?php self::action_form_open( 'settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="twtaeo-gbp-recipients"><?php esc_html_e( 'Send alerts to', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<input type="text" id="twtaeo-gbp-recipients" name="recipients" class="large-text" value="<?php echo esc_attr( $settings['recipients'] ); ?>" />
							<p class="description"><?php esc_html_e( 'One or more email addresses, separated by commas. These don\'t need to be the Google account that owns the profile.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="twtaeo-gbp-mode"><?php esc_html_e( 'Email me about', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<select id="twtaeo-gbp-mode" name="email_mode">
								<option value="all" <?php selected( $settings['email_mode'], 'all' ); ?>><?php esc_html_e( 'Every new review', 'twt-aeo-ultimate' ); ?></option>
								<option value="low" <?php selected( $settings['email_mode'], 'low' ); ?>><?php esc_html_e( 'Only 3 stars or lower', 'twt-aeo-ultimate' ); ?></option>
								<option value="off" <?php selected( $settings['email_mode'], 'off' ); ?>><?php esc_html_e( 'Nothing: alerts in WordPress only', 'twt-aeo-ultimate' ); ?></option>
							</select>
						</td>
					</tr>
					<?php if ( ! is_wp_error( $locations ) && count( $locations ) > 1 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Locations', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<?php foreach ( $locations as $location ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="locations[]" value="<?php echo esc_attr( $location['key'] ); ?>" <?php checked( ! $settings['locations'] || in_array( $location['key'], $settings['locations'], true ) ); ?> />
									<?php echo esc_html( $location['title'] ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Save', 'twt-aeo-ultimate' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}
}
