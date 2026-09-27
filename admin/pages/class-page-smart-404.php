<?php
/**
 * Admin Page: Smart 404 Rescue
 *
 * Control surface for the Smart 404 module: review and revert auto-created 301s,
 * undo auto-healed internal links, act on external-equity recommendations, and
 * extend the hostile-probe baseline. All state lives in the module's bounded
 * options — this page only reads and mutates them.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Smart_404 {

	const NONCE = 'twtaeo_s404';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$notice = self::handle_actions();

		$redirects = TWTAEO_Smart_404::get_redirects();
		$heals     = TWTAEO_Smart_404::get_heal_log();
		$probes    = TWTAEO_Smart_404::get_custom_probes();
		$lost      = class_exists( 'TWTAEO_Lost_Pages' ) ? TWTAEO_Lost_Pages::get_results() : array();
		$next_scan = wp_next_scheduled( TWTAEO_Smart_404::CRON_HOOK );

		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'Smart 404 Rescue', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Turns 404s into fixes instead of database bloat. Hacker/bot probes are ignored; genuine visitor 404s are matched to the closest page and broken internal links are healed at the source. The GSC scan recovers renamed and moved pages with permanent 301s.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p style="margin:12px 0;">
				<strong><?php echo count( $redirects ); ?></strong> <?php esc_html_e( 'active 301s', 'twt-aeo-ultimate' ); ?> &nbsp;·&nbsp;
				<strong><?php echo count( $heals ); ?></strong> <?php esc_html_e( 'healed links', 'twt-aeo-ultimate' ); ?>
				<?php if ( $next_scan ) : ?>
					&nbsp;·&nbsp;
					<?php
					/* translators: %s: human-readable time until the next scheduled scan. */
					echo esc_html( sprintf( __( 'next GSC scan %s from now', 'twt-aeo-ultimate' ), human_time_diff( time(), $next_scan ) ) );
					?>
				<?php endif; ?>
			</p>

			<?php
			self::render_lost_pages( $lost );
			self::render_redirects( $redirects );
			self::render_heals( $heals );
			self::render_probes( $probes );
			?>
		</div>
		<?php
	}

	// ── Action handling ──────────────────────────────────────────────────────────

	private static function handle_actions() {
		if ( ! isset( $_POST['s404_action'] ) ) {
			return '';
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['s404_action'] ) );

		switch ( $action ) {
			case 'undo_heal':
				$post_id  = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
				$bad_path = sanitize_text_field( wp_unslash( $_POST['bad_path'] ?? '' ) );
				return TWTAEO_Smart_404::undo_heal( $post_id, $bad_path )
					? __( 'Link restored and heal reverted.', 'twt-aeo-ultimate' )
					: __( 'Nothing to undo.', 'twt-aeo-ultimate' );

			case 'delete_redirect':
				$key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
				TWTAEO_Smart_404::delete_redirect( $key );
				return __( 'Redirect removed.', 'twt-aeo-ultimate' );

			case 'manual_redirect':
				$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
				$to   = esc_url_raw( wp_unslash( $_POST['to'] ?? '' ) );
				TWTAEO_Smart_404::add_manual_redirect( $from, $to );
				return __( 'Redirect created.', 'twt-aeo-ultimate' );

			case 'gsc_scan':
				if ( ! class_exists( 'TWTAEO_Lost_Pages' ) ) {
					return __( 'Lost Page Recovery unavailable.', 'twt-aeo-ultimate' );
				}
				$res = TWTAEO_Lost_Pages::run_gsc_scan();
				if ( is_wp_error( $res ) ) {
					return $res->get_error_message();
				}
				return sprintf(
					/* translators: 1: 404s scanned, 2: auto-created 301s, 3: items to review. */
					__( 'Scan complete: %1$d dead URLs, %2$d redirects created, %3$d to review.', 'twt-aeo-ultimate' ),
					(int) $res['scanned'], (int) $res['auto'], (int) $res['review']
				);

			case 'import_scan':
				if ( ! class_exists( 'TWTAEO_Lost_Pages' ) ) {
					return __( 'Lost Page Recovery unavailable.', 'twt-aeo-ultimate' );
				}
				$urls = TWTAEO_Lost_Pages::parse_url_list( sanitize_textarea_field( wp_unslash( $_POST['export'] ?? '' ) ) );
				if ( empty( $urls ) ) {
					return __( 'No URLs found in that paste.', 'twt-aeo-ultimate' );
				}
				$res = TWTAEO_Lost_Pages::scan_urls( $urls, 'import' );
				return sprintf(
					/* translators: 1: 404s scanned, 2: auto-created 301s, 3: items to review. */
					__( 'Import scan: %1$d dead URLs, %2$d redirects created, %3$d to review.', 'twt-aeo-ultimate' ),
					(int) $res['scanned'], (int) $res['auto'], (int) $res['review']
				);

			case 'approve_target':
				$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
				$to   = esc_url_raw( wp_unslash( $_POST['to'] ?? '' ) );
				TWTAEO_Smart_404::add_manual_redirect( $from, $to );
				return __( 'Redirect created.', 'twt-aeo-ultimate' );

			case 'save_probes':
				$raw   = sanitize_textarea_field( wp_unslash( $_POST['probes'] ?? '' ) );
				$lines = preg_split( '/[\r\n]+/', (string) $raw );
				TWTAEO_Smart_404::save_custom_probes( is_array( $lines ) ? $lines : array() );
				return __( 'Probe patterns saved.', 'twt-aeo-ultimate' );
		}

		return '';
	}

	// ── Sections ─────────────────────────────────────────────────────────────────

	private static function render_redirects( $redirects ) {
		echo '<h2>' . esc_html__( '301 Redirects', 'twt-aeo-ultimate' ) . '</h2>';
		if ( empty( $redirects ) ) {
			echo '<p class="twt-aeo-muted">' . esc_html__( 'No redirects yet. The GSC scan creates these for renamed and moved pages.', 'twt-aeo-ultimate' ) . '</p>';
			return;
		}
		$labels = array( 'gsc' => 'GSC', 'manual' => __( 'Manual', 'twt-aeo-ultimate' ), 'ai' => 'AI' );
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'From', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'To', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'Source', 'twt-aeo-ultimate' ) . '</th>'
			. '<th></th></tr></thead><tbody>';
		foreach ( $redirects as $key => $r ) {
			$src = $r['source'] ?? 'manual';
			echo '<tr>';
			echo '<td><code>' . esc_html( $r['from'] ?? $key ) . '</code></td>';
			echo '<td><a href="' . esc_url( $r['to'] ?? '' ) . '" target="_blank" rel="noopener">' . esc_html( $r['to'] ?? '' ) . '</a></td>';
			echo '<td>' . esc_html( $labels[ $src ] ?? $src ) . '</td>';
			echo '<td>';
			self::action_form( 'delete_redirect', array( 'key' => $key ), __( 'Remove', 'twt-aeo-ultimate' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_lost_pages( $lost ) {
		$connected = class_exists( 'TWTAEO_Google_OAuth' )
			&& ! empty( TWTAEO_Google_OAuth::get_config()['gsc_site_url'] );

		echo '<h2>' . esc_html__( 'Lost Page Recovery', 'twt-aeo-ultimate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Finds pages that Google still knows but that now 404 — renamed or moved content — and creates permanent 301s to the closest live page. Redirects apply to crawlers too, so search equity transfers.', 'twt-aeo-ultimate' ) . '</p>';

		// Scan controls.
		echo '<p style="margin:10px 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">';
		if ( $connected ) {
			echo '<form method="post" style="display:inline;">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="s404_action" value="gsc_scan">';
			echo '<button type="submit" class="button button-primary">' . esc_html__( '&#128269; Scan Search Console for lost pages', 'twt-aeo-ultimate' ) . '</button>';
			echo '</form>';
			echo '<span class="twt-aeo-muted" style="font-size:12px;">' . esc_html__( 'Also runs automatically once a week.', 'twt-aeo-ultimate' ) . '</span>';
		} else {
			echo '<span class="twt-aeo-muted">' . esc_html__( 'Connect Google Search Console under TWT AEO → Settings to enable automatic scanning.', 'twt-aeo-ultimate' ) . '</span>';
		}
		echo '</p>';

		// Manual GSC export paste (works without a live connection).
		echo '<details style="margin:8px 0 14px;"><summary style="cursor:pointer;">' . esc_html__( 'Or paste a GSC “Not found (404)” export', 'twt-aeo-ultimate' ) . '</summary>';
		echo '<form method="post" style="margin-top:8px;">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="s404_action" value="import_scan">';
		echo '<textarea name="export" rows="5" class="large-text code" placeholder="' . esc_attr__( 'Paste the Table.csv rows or a list of URLs, one per line…', 'twt-aeo-ultimate' ) . '"></textarea>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Scan pasted URLs', 'twt-aeo-ultimate' ) . '</button></p>';
		echo '</form></details>';

		if ( empty( $lost ) ) {
			return;
		}

		// Last scan summary.
		echo '<p style="font-size:13px;color:#50575e;">' . esc_html( sprintf(
			/* translators: 1: dead count, 2: auto count, 3: relative time. */
			__( 'Last scan: %1$d dead URLs, %2$d auto-redirected, %3$s ago.', 'twt-aeo-ultimate' ),
			(int) ( $lost['scanned'] ?? 0 ), (int) ( $lost['auto'] ?? 0 ),
			human_time_diff( (int) ( $lost['time'] ?? time() ) )
		) ) . '</p>';

		// Review table — matches to approve, and deletes to point by hand.
		$review = (array) ( $lost['review_list'] ?? array() );
		if ( empty( $review ) ) {
			return;
		}
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Lost URL', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'Suggested target', 'twt-aeo-ultimate' ) . '</th>'
			. '<th></th></tr></thead><tbody>';
		foreach ( $review as $row ) {
			$from = $row['from'] ?? '';
			$to   = $row['to'] ?? '';
			echo '<tr>';
			echo '<td><code>' . esc_html( self::shorten( $from ) ) . '</code></td>';
			echo '<td>';
			echo '<form method="post" style="display:flex;gap:6px;align-items:center;">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="s404_action" value="approve_target">';
			echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '">';
			echo '<input type="url" name="to" class="regular-text" value="' . esc_attr( $to ) . '" placeholder="' . esc_attr__( 'https://… (no confident match — enter a target)', 'twt-aeo-ultimate' ) . '" required>';
			if ( '' !== $to && ! empty( $row['score'] ) ) {
				echo '<span class="twt-aeo-muted" style="font-size:12px;">' . esc_html( (int) $row['score'] ) . '%</span>';
			}
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create 301', 'twt-aeo-ultimate' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '<td class="twt-aeo-muted" style="font-size:12px;">' . ( '' === $to ? esc_html__( 'likely deleted', 'twt-aeo-ultimate' ) : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_heals( $heals ) {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Healed Internal Links', 'twt-aeo-ultimate' ) . '</h2>';
		if ( empty( $heals ) ) {
			echo '<p class="twt-aeo-muted">' . esc_html__( 'No links healed yet. Typo\'d internal links are corrected at the source automatically.', 'twt-aeo-ultimate' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'On page', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'Was', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'Now', 'twt-aeo-ultimate' ) . '</th>'
			. '<th>' . esc_html__( 'When', 'twt-aeo-ultimate' ) . '</th>'
			. '<th></th></tr></thead><tbody>';
		foreach ( $heals as $h ) {
			$title = get_the_title( (int) ( $h['post_id'] ?? 0 ) );
			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( (int) ( $h['post_id'] ?? 0 ) ) ) . '">' . esc_html( $title ?: ( '#' . (int) ( $h['post_id'] ?? 0 ) ) ) . '</a></td>';
			echo '<td><code>' . esc_html( $h['from'] ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $h['to'] ?? '' ) . '</code></td>';
			echo '<td style="font-size:12px;">' . esc_html( $h['time'] ? human_time_diff( (int) $h['time'] ) . ' ' . __( 'ago', 'twt-aeo-ultimate' ) : '' ) . '</td>';
			echo '<td>';
			self::action_form( 'undo_heal', array(
				'post_id'  => (int) ( $h['post_id'] ?? 0 ),
				'bad_path' => $h['bad_path'] ?? '',
			), __( 'Undo', 'twt-aeo-ultimate' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_probes( $probes ) {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Hostile-Probe Patterns', 'twt-aeo-ultimate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A bundled baseline already blocks common attack paths (wp-login, .env, /.git, shells, and more). Add extra substrings here — one per line — to skip the rescue pipeline for any path that contains them.', 'twt-aeo-ultimate' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="s404_action" value="save_probes">';
		echo '<textarea name="probes" rows="6" class="large-text code" placeholder="' . esc_attr__( 'e.g. /old-scanner-path', 'twt-aeo-ultimate' ) . '">' . esc_textarea( implode( "\n", $probes ) ) . '</textarea>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Save patterns', 'twt-aeo-ultimate' ) . '</button></p>';
		echo '</form>';
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/** Render a single-button POST action form with hidden fields. */
	private static function action_form( $action, array $fields, $label ) {
		echo '<form method="post" style="display:inline;">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="s404_action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/** Trim a URL for compact display. */
	private static function shorten( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$out  = $host . $path;
		return strlen( $out ) > 48 ? substr( $out, 0, 47 ) . '…' : $out;
	}
}
