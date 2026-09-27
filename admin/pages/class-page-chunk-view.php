<?php
/**
 * Chunk View Page
 *
 * Shows how a page fragments when an AI engine indexes it, and whether each
 * fragment can stand on its own once retrieved away from the rest. Engine lives
 * in TWTAEO_Chunk_View; splitting and scoring in TWTAEO_Chunker.
 *
 * Runs with no API key configured — everything on this screen is computed
 * locally, which is why it works on any host that can run the plugin at all.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Chunk_View {

	/**
	 * @param bool $embedded True when drawn as a tab of the RAG Engine screen,
	 *                       which supplies the wrap and page title.
	 */
	public static function render( $embedded = false ) {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$result = null;
		$target = '';

		// Reset the circuit breaker after the operator has dealt with the cause.
		if ( isset( $_POST['twtaeo_cv_reset_breaker'] ) && check_admin_referer( 'twtaeo_cv_reset' ) ) {
			TWTAEO_Host_Profile::reset_breaker();
			TWTAEO_Host_Profile::probe();
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Indexing re-enabled and the host re-measured.', 'twt-aeo-ultimate' ) .
				'</p></div>';
		}

		// Re-measure on demand — useful straight after a hosting plan change.
		if ( isset( $_POST['twtaeo_cv_reprobe'] ) && check_admin_referer( 'twtaeo_cv_reprobe' ) ) {
			TWTAEO_Host_Profile::probe();
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Host re-measured.', 'twt-aeo-ultimate' ) .
				'</p></div>';
		}

		if ( isset( $_POST['twtaeo_cv_clear'] ) && check_admin_referer( 'twtaeo_cv_clear' ) ) {
			TWTAEO_Chunk_View::clear_cache();
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Saved analyses cleared.', 'twt-aeo-ultimate' ) .
				'</p></div>';
		}

		// Run an analysis.
		if ( isset( $_POST['twtaeo_cv_analyze'] ) && check_admin_referer( 'twtaeo_cv_analyze' ) ) {
			$post_id = isset( $_POST['twtaeo_cv_post'] ) ? absint( $_POST['twtaeo_cv_post'] ) : 0;
			$url     = isset( $_POST['twtaeo_cv_url'] ) ? esc_url_raw( wp_unslash( $_POST['twtaeo_cv_url'] ) ) : '';

			if ( $post_id ) {
				$target = get_permalink( $post_id );
				$result = TWTAEO_Chunk_View::analyze_post( $post_id );
			} elseif ( $url ) {
				$target = $url;
				$result = TWTAEO_Chunk_View::analyze_url( $url );
			} else {
				$result = new WP_Error(
					'twtaeo_cv_input',
					__( 'Choose a page or enter a URL to analyse.', 'twt-aeo-ultimate' )
				);
			}
		}

		// Reopen a saved analysis from the history table.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		if ( null === $result && isset( $_GET['view'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$saved = TWTAEO_Chunk_View::get_cached_result( absint( wp_unslash( $_GET['view'] ) ) );
			if ( is_array( $saved ) ) {
				$result = $saved;
				$target = isset( $saved['url'] ) ? (string) $saved['url'] : '';
			}
		}

		$profile = TWTAEO_Host_Profile::get();
		?>
		<div class="<?php echo $embedded ? '' : 'wrap '; ?>twtaeo-chunk-view">
			<?php if ( ! $embedded ) : ?>
				<h1><?php esc_html_e( 'Chunk View', 'twt-aeo-ultimate' ); ?></h1>
			<?php endif; ?>

			<p class="description" style="max-width:760px;font-size:14px;">
				<?php
				esc_html_e(
					'AI engines do not retrieve whole pages — they retrieve passages. This shows how your page is cut into those passages, and flags any that cannot be understood on their own once separated from the rest of the page.',
					'twt-aeo-ultimate'
				);
				?>
			</p>

			<?php self::render_host_panel( $profile ); ?>

			<?php if ( TWTAEO_Host_Profile::is_tripped() ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Indexing is paused.', 'twt-aeo-ultimate' ); ?></strong>
						<?php esc_html_e( 'Your host could not finish the last few attempts, so the engine stopped rather than risk taking the site down.', 'twt-aeo-ultimate' ); ?>
					</p>
					<form method="post">
						<?php wp_nonce_field( 'twtaeo_cv_reset' ); ?>
						<p>
							<button type="submit" name="twtaeo_cv_reset_breaker" value="1" class="button button-primary">
								<?php esc_html_e( 'Re-enable and re-measure', 'twt-aeo-ultimate' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<?php self::render_form(); ?>

			<?php
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} elseif ( is_array( $result ) ) {
				self::render_result( $result, $target );
			}
			?>

			<?php self::render_history(); ?>
		</div>
		<?php
	}

	/* ─────────────────────────── panels ─────────────────────────── */

	/**
	 * What we measured about this host, stated plainly. Users on cheap hosting
	 * deserve to know why the tool behaves as it does before it behaves that way.
	 *
	 * @param array $profile Host profile.
	 */
	private static function render_host_panel( array $profile ) {
		$usable = ! empty( $profile['usable'] );
		?>
		<div class="card" style="max-width:none;padding:12px 16px;margin:16px 0;">
			<h2 class="title" style="margin-top:0;font-size:14px;">
				<?php esc_html_e( 'Measured host capacity', 'twt-aeo-ultimate' ); ?>
			</h2>

			<p class="description" style="margin-bottom:8px;">
				<?php
				esc_html_e(
					'Batch sizes come from a real timed benchmark on this server, not from its reported settings. Work stops at a safe point rather than pushing past what the host allows.',
					'twt-aeo-ultimate'
				);
				?>
			</p>

			<table class="widefat striped" style="max-width:720px;">
				<tbody>
				<?php foreach ( TWTAEO_Host_Profile::summary() as $label => $value ) : ?>
					<tr>
						<td style="width:200px;"><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( (string) $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! $usable && ! empty( $profile['reason'] ) ) : ?>
				<p style="color:#b32d2e;margin-top:10px;">
					<strong><?php echo esc_html( $profile['reason'] ); ?></strong>
				</p>
			<?php endif; ?>

			<?php if ( empty( $profile['has_shell'] ) ) : ?>
				<p class="description" style="margin-top:10px;">
					<?php
					esc_html_e(
						'This host has no shell access, so scanned documents cannot be OCR-processed here. Digital PDFs, Word files and text will still work when the document vault ships.',
						'twt-aeo-ultimate'
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" style="margin-top:10px;">
				<?php wp_nonce_field( 'twtaeo_cv_reprobe' ); ?>
				<button type="submit" name="twtaeo_cv_reprobe" value="1" class="button">
					<?php esc_html_e( 'Re-measure this host', 'twt-aeo-ultimate' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/** The picker: a published page, or any URL. */
	private static function render_form() {
		$posts = TWTAEO_Chunk_View::candidate_posts();
		?>
		<?php // The result prints below the host panel and this form — land on it. ?>
		<form method="post" action="#twtaeo-cv-result" style="margin:16px 0;">
			<?php wp_nonce_field( 'twtaeo_cv_analyze' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="twtaeo_cv_post"><?php esc_html_e( 'Page on this site', 'twt-aeo-ultimate' ); ?></label>
					</th>
					<td>
						<select name="twtaeo_cv_post" id="twtaeo_cv_post" style="min-width:420px;max-width:100%;">
							<option value="0"><?php esc_html_e( '— Select a page —', 'twt-aeo-ultimate' ); ?></option>
							<?php foreach ( $posts as $post ) : ?>
								<option value="<?php echo esc_attr( $post->ID ); ?>">
									<?php echo esc_html( $post->post_title ? $post->post_title : '#' . $post->ID ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="twtaeo_cv_url"><?php esc_html_e( 'Or any URL', 'twt-aeo-ultimate' ); ?></label>
					</th>
					<td>
						<input type="url" name="twtaeo_cv_url" id="twtaeo_cv_url" class="regular-text"
							placeholder="https://example.com/page" />
						<p class="description">
							<?php esc_html_e( 'Works on competitor pages too — useful for seeing why their passages get cited.', 'twt-aeo-ultimate' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" name="twtaeo_cv_analyze" value="1" class="button button-primary">
					<?php esc_html_e( 'Show me the chunks', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * The analysis itself.
	 *
	 * @param array  $result Analysis result.
	 * @param string $target The URL analysed.
	 */
	private static function render_result( array $result, $target ) {
		$band = TWTAEO_Chunk_View::band( $result['page_score'] );
		?>
		<hr />
		<h2 id="twtaeo-cv-result"><?php esc_html_e( 'Result', 'twt-aeo-ultimate' ); ?></h2>

		<p>
			<code><?php echo esc_html( $target ? $target : $result['url'] ); ?></code>
			<?php if ( ! empty( $result['analyzed_at'] ) ) : ?>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time difference. */
							__( 'analysed %s ago', 'twt-aeo-ultimate' ),
							human_time_diff( (int) $result['analyzed_at'], time() )
						)
					);
					?>
				</span>
			<?php endif; ?>
		</p>

		<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">
			<?php
			self::stat( __( 'Chunks', 'twt-aeo-ultimate' ), number_format_i18n( $result['chunk_count'] ) );
			self::stat( __( 'Readable text', 'twt-aeo-ultimate' ), size_format( $result['text_chars'] ) );
			self::stat( __( 'Self-sufficiency', 'twt-aeo-ultimate' ), $result['page_score'] . '/100', $band['color'] );
			self::stat( __( 'Verdict', 'twt-aeo-ultimate' ), $band['label'], $band['color'] );
			?>
		</div>

		<?php if ( ! empty( $result['issue_totals'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Most common problems on this page:', 'twt-aeo-ultimate' ); ?></strong>
			</p>
			<ul style="list-style:disc;margin-left:20px;">
				<?php foreach ( $result['issue_totals'] as $code => $count ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: issue description, 2: number of chunks affected. */
							esc_html__( '%1$s — %2$d chunks', 'twt-aeo-ultimate' ),
							esc_html( self::issue_label( $code ) ),
							(int) $count
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'How this page is cut up', 'twt-aeo-ultimate' ); ?></h3>

		<?php if ( empty( $result['chunks'] ) && ! empty( $result['post_id'] ) ) : ?>
			<?php // Saved before the chunks were kept alongside the score. ?>
			<form method="post">
				<?php wp_nonce_field( 'twtaeo_cv_analyze' ); ?>
				<input type="hidden" name="twtaeo_cv_post" value="<?php echo esc_attr( (int) $result['post_id'] ); ?>" />
				<p>
					<?php esc_html_e( 'The passages for this analysis were not saved. Analyse the page again to see them.', 'twt-aeo-ultimate' ); ?>
					<button type="submit" name="twtaeo_cv_analyze" value="1" class="button">
						<?php esc_html_e( 'Analyse again', 'twt-aeo-ultimate' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>

		<?php foreach ( $result['chunks'] as $chunk ) : ?>
			<?php $chunk_band = TWTAEO_Chunk_View::band( $chunk['score'] ); ?>
			<div class="card" style="max-width:none;margin:0 0 12px;padding:12px 16px;border-left:4px solid <?php echo esc_attr( $chunk_band['color'] ); ?>;">
				<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
					<strong>
						<?php
						printf(
							/* translators: %d: chunk number. */
							esc_html__( 'Chunk %d', 'twt-aeo-ultimate' ),
							(int) $chunk['index'] + 1
						);
						?>
					</strong>
					<span style="color:<?php echo esc_attr( $chunk_band['color'] ); ?>;font-weight:600;">
						<?php echo esc_html( $chunk['score'] . '/100 · ' . $chunk_band['label'] ); ?>
					</span>
				</div>

				<p class="description" style="margin:4px 0 8px;">
					<?php if ( ! empty( $chunk['heading_path'] ) ) : ?>
						<?php echo esc_html( $chunk['heading_path'] ); ?> ·
					<?php else : ?>
						<em><?php esc_html_e( 'no heading', 'twt-aeo-ultimate' ); ?></em> ·
					<?php endif; ?>
					<?php
					printf(
						/* translators: %d: estimated token count. */
						esc_html__( '~%d tokens', 'twt-aeo-ultimate' ),
						(int) $chunk['tokens']
					);
					?>
				</p>

				<?php if ( ! empty( $chunk['issues'] ) ) : ?>
					<ul style="margin:0 0 8px;">
						<?php foreach ( $chunk['issues'] as $issue ) : ?>
							<li style="color:<?php echo 'error' === $issue['level'] ? '#b32d2e' : '#996800'; ?>;">
								<?php echo esc_html( $issue['label'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $chunk['overlap'] ) ) : ?>
					<pre style="white-space:pre-wrap;background:#f0f0f1;color:#787c82;padding:8px 10px;margin:0;font-size:12px;font-style:italic;border-bottom:1px dashed #c3c4c7;" title="<?php esc_attr_e( 'Carried over from the previous chunk so a fact spanning the boundary is not lost.', 'twt-aeo-ultimate' ); ?>"><?php
						echo esc_html( $chunk['overlap'] );
					?></pre>
				<?php endif; ?>

				<pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;margin:0;font-size:12px;max-height:240px;overflow:auto;"><?php
					echo esc_html( $chunk['content'] );
				?></pre>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/** Previously analysed pages. */
	private static function render_history() {
		$cached = TWTAEO_Chunk_View::get_cached();
		if ( empty( $cached ) ) {
			return;
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Recently analysed', 'twt-aeo-ultimate' ); ?></h2>

		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Page', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Chunks', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Self-sufficiency', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Analysed', 'twt-aeo-ultimate' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $cached as $post_id => $row ) : ?>
				<?php $band = TWTAEO_Chunk_View::band( $row['page_score'] ); ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( get_the_title( $post_id ) ? get_the_title( $post_id ) : $row['url'] ); ?>
						</a>
					</td>
					<td><?php echo esc_html( number_format_i18n( $row['chunk_count'] ) ); ?></td>
					<td style="color:<?php echo esc_attr( $band['color'] ); ?>;">
						<?php echo esc_html( $row['page_score'] . '/100' ); ?>
					</td>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time difference. */
								__( '%s ago', 'twt-aeo-ultimate' ),
								human_time_diff( (int) $row['analyzed_at'], time() )
							)
						);
						?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'view' => (int) $post_id ), TWTAEO_Page_RAG_Engine::url( 'chunks' ) ) . '#twtaeo-cv-result' ); ?>">
							<?php esc_html_e( 'View chunks', 'twt-aeo-ultimate' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top:12px;">
			<?php wp_nonce_field( 'twtaeo_cv_clear' ); ?>
			<button type="submit" name="twtaeo_cv_clear" value="1" class="button">
				<?php esc_html_e( 'Clear saved analyses', 'twt-aeo-ultimate' ); ?>
			</button>
		</form>
		<?php
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * One headline figure.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $color Optional accent colour.
	 */
	private static function stat( $label, $value, $color = '#1d2327' ) {
		?>
		<div style="border:1px solid #dcdcde;background:#fff;padding:10px 16px;min-width:140px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#646970;">
				<?php echo esc_html( $label ); ?>
			</div>
			<div style="font-size:20px;font-weight:600;color:<?php echo esc_attr( $color ); ?>;">
				<?php echo esc_html( $value ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Plain-language label for an issue code.
	 *
	 * @param string $code Issue code from TWTAEO_Chunker.
	 * @return string
	 */
	private static function issue_label( $code ) {
		$labels = array(
			'dangling_opener'    => __( 'Opens with a pronoun that has no antecedent', 'twt-aeo-ultimate' ),
			'backreference'      => __( 'Refers to content outside the chunk', 'twt-aeo-ultimate' ),
			'no_heading'         => __( 'Sits under no heading', 'twt-aeo-ultimate' ),
			'too_short'          => __( 'Too thin to answer a query alone', 'twt-aeo-ultimate' ),
			'too_long'           => __( 'Covers too many ideas to match any strongly', 'twt-aeo-ultimate' ),
			'not_prose'          => __( 'Not prose — links, buttons or image captions', 'twt-aeo-ultimate' ),
			'link_heavy'         => __( 'Link-heavy relative to explanatory text', 'twt-aeo-ultimate' ),
			'unanchored_figure'  => __( 'Figures with no named subject', 'twt-aeo-ultimate' ),
			'empty'              => __( 'Empty chunk', 'twt-aeo-ultimate' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}
}
