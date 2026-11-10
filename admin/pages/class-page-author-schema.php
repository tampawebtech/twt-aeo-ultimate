<?php
/**
 * Author Schema module screen — Appearance > Author Schema.
 *
 * The author-schema module publishes a Person node on single posts and a
 * ProfilePage wrapping that Person on author archives. This screen answers the
 * three questions the module raises: is it publishing right now, what does each
 * author's node actually carry, and where do I fill in what is missing.
 *
 * It lives under Appearance because that is where the theme's byline and author
 * box are managed, and the schema only earns its keep when the page shows the
 * author too.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Author_Schema {

	const SLUG = 'twt-aeo-module-author-schema';

	/**
	 * Render the screen.
	 */
	public static function render() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$deferring = class_exists( 'TWTAEO_Author_Schema_Writer' )
			? TWTAEO_Author_Schema_Writer::deferring_to()
			: '';

		$authors = class_exists( 'TWTAEO_Author_Meta' ) ? TWTAEO_Author_Meta::get_all_authors() : array();
		$total   = count( $authors );

		$avg_score  = 0;
		$with_certs = 0;
		$with_same  = 0;
		foreach ( $authors as $author ) {
			$avg_score += (int) ( $author['completeness']['score'] ?? 0 );
			if ( ! empty( $author['certifications'] ) ) {
				$with_certs++;
			}
			if ( ! empty( TWTAEO_Author_Meta::get_same_as( $author['id'] ) ) ) {
				$with_same++;
			}
		}
		$avg_score = $total ? (int) round( $avg_score / $total ) : 0;

		$eeat_url    = admin_url( 'admin.php?page=twt-aeo-eeat&tab=author' );
		$modules_url = admin_url( 'admin.php?page=twt-aeo-modules' );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Author Schema', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub" style="margin-left:auto;">
						<?php esc_html_e( 'Person and ProfilePage schema for your posts and author archives', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<!-- Summary Cards -->
			<section class="twt-aeo-section">
				<div class="twt-aeo-summary-grid" style="grid-template-columns:repeat(4,1fr);">

					<div class="twt-aeo-summary-card <?php echo '' === $deferring ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--warn'; ?>">
						<div class="twt-aeo-summary-card__number"><?php echo '' === $deferring ? '&#10003;' : '&#8213;'; ?></div>
						<div class="twt-aeo-summary-card__label">
							<?php
							echo '' === $deferring
								? esc_html__( 'Publishing', 'twt-aeo-ultimate' )
								: esc_html__( 'Standing down', 'twt-aeo-ultimate' );
							?>
						</div>
					</div>

					<div class="twt-aeo-summary-card">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $total ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Authors', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $avg_score >= 70 ? 'twt-aeo-summary-card--good' : ( $avg_score >= 40 ? 'twt-aeo-summary-card--warn' : 'twt-aeo-summary-card--alert' ); ?>">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $avg_score ); ?>%</div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Avg. completeness', 'twt-aeo-ultimate' ); ?></div>
					</div>

					<div class="twt-aeo-summary-card <?php echo $with_certs > 0 ? 'twt-aeo-summary-card--good' : 'twt-aeo-summary-card--warn'; ?>">
						<div class="twt-aeo-summary-card__number"><?php echo esc_html( $with_certs ); ?></div>
						<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'With credentials', 'twt-aeo-ultimate' ); ?></div>
					</div>

				</div>
			</section>

			<?php if ( '' !== $deferring ) : ?>
			<section class="twt-aeo-section">
				<div class="twt-aeo-card">
					<h2 class="twt-aeo-card__title">
						<?php
						printf(
							/* translators: %s: SEO plugin name, e.g. Rank Math. */
							esc_html__( '%s is already publishing Person schema', 'twt-aeo-ultimate' ),
							esc_html( $deferring )
						);
						?>
					</h2>
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'Two Person nodes on one page compete, so this module stays quiet while that setting is on. Nothing here is lost: switch the person knowledge graph off in that plugin and AEO Ultimate takes over on the next page load, carrying the credentials and social profiles below.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</section>
			<?php endif; ?>

			<!-- What the module publishes -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'What this module publishes', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-card">
					<div class="twt-aeo-page-table-wrap">
						<table class="twt-aeo-page-table" style="margin-top:0;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Where', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Schema', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Built from', 'twt-aeo-ultimate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><?php esc_html_e( 'Single posts', 'twt-aeo-ultimate' ); ?></td>
									<td><code>Person</code></td>
									<td><?php esc_html_e( 'The post author&#8217;s profile', 'twt-aeo-ultimate' ); ?></td>
								</tr>
								<tr>
									<td><?php esc_html_e( 'Author archives', 'twt-aeo-ultimate' ); ?></td>
									<td><code>ProfilePage</code> &rarr; <code>mainEntity: Person</code></td>
									<td><?php esc_html_e( 'The archive owner, plus account and latest-post dates', 'twt-aeo-ultimate' ); ?></td>
								</tr>
								<tr>
									<td><?php esc_html_e( 'Credentials', 'twt-aeo-ultimate' ); ?></td>
									<td><code>hasCredential</code></td>
									<td>
										<?php
										printf(
											/* translators: 1: number of authors with credentials, 2: total authors. */
											esc_html__( '%1$d of %2$d authors have certifications saved', 'twt-aeo-ultimate' ),
											(int) $with_certs,
											(int) $total
										);
										?>
									</td>
								</tr>
								<tr>
									<td><?php esc_html_e( 'Social profiles', 'twt-aeo-ultimate' ); ?></td>
									<td><code>sameAs</code></td>
									<td>
										<?php
										printf(
											/* translators: 1: number of authors with social profiles, 2: total authors. */
											esc_html__( '%1$d of %2$d authors have profile links saved', 'twt-aeo-ultimate' ),
											(int) $with_same,
											(int) $total
										);
										?>
									</td>
								</tr>
								<tr>
									<td><?php esc_html_e( 'Expertise', 'twt-aeo-ultimate' ); ?></td>
									<td><code>knowsAbout</code></td>
									<td><?php esc_html_e( 'The comma-separated expertise field on each profile', 'twt-aeo-ultimate' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
					<p class="twt-aeo-card__note">
						<?php esc_html_e( 'On pages that already carry enough schema of their own, this Person node is folded into the single unified graph instead of printing a second block — same data, one script tag.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</section>

			<?php self::render_theme_card(); ?>

			<!-- Per-author detail -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Authors', 'twt-aeo-ultimate' ); ?></h2>

				<?php if ( empty( $authors ) ) : ?>
					<p class="twt-aeo-empty"><?php esc_html_e( 'No authors found.', 'twt-aeo-ultimate' ); ?></p>
				<?php else : ?>
				<div class="twt-aeo-card">
					<div class="twt-aeo-page-table-wrap">
						<table class="twt-aeo-page-table" style="margin-top:0;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Author', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Job title', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Complete', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Missing from the Person node', 'twt-aeo-ultimate' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $authors as $author ) :
									$score   = (int) ( $author['completeness']['score'] ?? 0 );
									$missing = $author['completeness']['missing'] ?? array();
									$schema  = class_exists( 'TWTAEO_Author_Schema_Writer' )
										? TWTAEO_Author_Schema_Writer::build_schema( $author['id'] )
										: null;
									?>
								<tr>
									<td>
										<strong><?php echo esc_html( $author['name'] ); ?></strong><br />
										<a href="<?php echo esc_url( $author['profile_url'] ); ?>" target="_blank" rel="noopener">
											<?php esc_html_e( 'View archive', 'twt-aeo-ultimate' ); ?>
										</a>
									</td>
									<td>
										<?php
										echo ! empty( $author['job_title'] )
											? esc_html( $author['job_title'] )
											: '<span class="twt-aeo-empty">' . esc_html__( 'Not set', 'twt-aeo-ultimate' ) . '</span>';
										?>
									</td>
									<td><?php echo esc_html( $score ); ?>%</td>
									<td>
										<?php
										echo empty( $missing )
											? '<span class="twt-aeo-status-ok">' . esc_html__( 'Nothing', 'twt-aeo-ultimate' ) . '</span>'
											: esc_html( implode( ', ', $missing ) );
										?>
									</td>
									<td>
										<?php if ( ! empty( $author['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( $author['edit_url'] ); ?>"><?php esc_html_e( 'Edit profile', 'twt-aeo-ultimate' ); ?></a><br />
										<?php endif; ?>
										<a href="<?php echo esc_url( $eeat_url ); ?>"><?php esc_html_e( 'Author details', 'twt-aeo-ultimate' ); ?></a>
									</td>
								</tr>
									<?php if ( $schema ) : ?>
								<tr>
									<td colspan="5">
										<details>
											<summary><?php esc_html_e( 'Schema preview', 'twt-aeo-ultimate' ); ?></summary>
											<pre class="twt-aeo-schema-pre"><?php echo esc_html( wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
										</details>
									</td>
								</tr>
									<?php endif; ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php endif; ?>
			</section>

			<section class="twt-aeo-section">
				<p class="twt-aeo-card__note">
					<a href="<?php echo esc_url( $eeat_url ); ?>"><?php esc_html_e( 'Edit author credentials, expertise and social profiles', 'twt-aeo-ultimate' ); ?></a>
					&nbsp;&middot;&nbsp;
					<a href="<?php echo esc_url( $modules_url ); ?>"><?php esc_html_e( 'Turn this module off', 'twt-aeo-ultimate' ); ?></a>
				</p>
			</section>

		</div>
		<?php
	}

	/**
	 * Theme byline card. Schema describes what a reader can see, so an author
	 * box on the theme is what makes the Person node worth publishing.
	 */
	private static function render_theme_card() {
		if ( ! class_exists( 'TWTAEO_Author_Box_Detector' ) ) {
			return;
		}

		$scan   = TWTAEO_Author_Box_Detector::scan_site();
		$has    = ! empty( $scan['has_author_box'] );
		$source = ! empty( $scan['source'] ) && 'none' !== $scan['source'] ? $scan['source'] : '';
		?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Visible author information', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card">
				<p>
					<?php if ( $has ) : ?>
						<span class="twt-aeo-status-ok"><?php esc_html_e( 'Author information was found on your recent posts.', 'twt-aeo-ultimate' ); ?></span>
						<?php if ( '' !== $source ) : ?>
							<span class="twt-aeo-badge twt-aeo-badge--source"><?php echo esc_html( $source ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'No author box or byline was detected on your recent posts.', 'twt-aeo-ultimate' ); ?>
					<?php endif; ?>
				</p>
				<p class="twt-aeo-card__note">
					<?php
					echo $has
						? esc_html__( 'The Person schema and the visible byline agree, which is what search engines and AI assistants look for.', 'twt-aeo-ultimate' )
						: esc_html__( 'Schema describes what a reader can see. Add a byline or author box in your theme — Appearance then Customize, or the single-post template — so the Person node matches the page.', 'twt-aeo-ultimate' );
					?>
				</p>
			</div>
		</section>
		<?php
	}
}
