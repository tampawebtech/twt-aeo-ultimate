<?php
/**
 * Modules Page
 *
 * Renders the module toggle grid, styled like Rank Math's modules screen.
 * Modules with a 'requires' key are hidden when that dependency is not active.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Modules {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$modules = $GLOBALS['twtaeo_plugin']->modules;
		$all     = $modules->get_all();

		// Filter out modules whose dependencies are not met.
		$all = array_filter( $all, array( __CLASS__, 'module_requirements_met' ) );

		$free_modules = array_filter( $all, fn( $m ) => $m['phase'] === 'free' );

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Modules', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Enable or disable features. Each module only runs when you need it.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Free Modules', 'twt-aeo-ultimate' ); ?></h2>
				<div class="twt-aeo-module-grid">
					<?php foreach ( $free_modules as $slug => $module ) : ?>
						<?php self::render_module_card( $slug, $module, $modules->is_active( $slug ) ); ?>
					<?php endforeach; ?>
				</div>
			</section>


			<!-- Pro Plugins -->
			<section class="twt-aeo-section">
				<h2 class="twt-aeo-section__title">
					<?php esc_html_e( 'Pro Plugins', 'twt-aeo-ultimate' ); ?>
					<span class="twt-aeo-badge twt-aeo-badge--pro"><?php esc_html_e( 'Standalone', 'twt-aeo-ultimate' ); ?></span>
				</h2>
				<div class="twt-aeo-promo-grid">

					<!-- TWT Content Generator -->
					<div class="twt-aeo-promo-card">
						<div class="twt-aeo-promo-card__badge">
							<span class="twt-aeo-promo-badge twt-aeo-promo-badge--pro-plugin"><?php esc_html_e( 'Available', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-promo-card__head">
							<div class="twt-aeo-promo-card__icon twt-aeo-promo-card__icon--cg">
								<span class="dashicons dashicons-edit-large"></span>
							</div>
							<div>
								<h3 class="twt-aeo-promo-card__title"><?php esc_html_e( 'TWT Content Generator', 'twt-aeo-ultimate' ); ?></h3>
								<p class="twt-aeo-promo-card__tagline"><?php esc_html_e( 'AI-powered content — three models, one workflow', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>
						<ul class="twt-aeo-promo-card__features">
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Generate with Claude, ChatGPT & Perplexity simultaneously', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Hub & sub-page architecture for topic clusters', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'AEO semantic elements baked into every prompt', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Tone, audience, intent, format & UVP controls', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Save drafts directly from the generator — no copy-paste', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Inherits your AEO API keys automatically', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<div class="twt-aeo-promo-card__foot">
							<a class="button twt-aeo-promo-cta twt-aeo-promo-cta--pro-plugin" href="<?php echo esc_url( 'https://tampawebtech.com/content-generator/' ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Learn More', 'twt-aeo-ultimate' ); ?> &rarr;
							</a>
						</div>
					</div>

					<!-- TWT Agency -->
					<div class="twt-aeo-promo-card">
						<div class="twt-aeo-promo-card__badge">
							<span class="twt-aeo-promo-badge twt-aeo-promo-badge--pro-plugin"><?php esc_html_e( 'Available', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-promo-card__head">
							<div class="twt-aeo-promo-card__icon twt-aeo-promo-card__icon--aeo-pro">
								<span class="dashicons dashicons-chart-line"></span>
							</div>
							<div>
								<h3 class="twt-aeo-promo-card__title"><?php esc_html_e( 'TWT Agency', 'twt-aeo-ultimate' ); ?></h3>
								<p class="twt-aeo-promo-card__tagline"><?php esc_html_e( 'Every client site on one dashboard — monitor, alert, fix, and prove your value', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>
						<ul class="twt-aeo-promo-card__features">
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Connect unlimited client sites — this plugin reports in automatically', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Live Wire activity stream & AI crawler analytics across your whole portfolio', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'De-index & anomaly alerts in Slack with one-click fixes pushed back to the site', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'AI remediation plans — approve once and they apply on the client site', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'White-label monthly reports, shareable links & a branded client portal', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Before/after schema-impact reports that prove ROI to clients', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<div class="twt-aeo-promo-card__foot">
							<a class="button twt-aeo-promo-cta twt-aeo-promo-cta--pro-plugin" href="<?php echo esc_url( 'https://tampawebtech.com/twt-agency/' ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Learn More', 'twt-aeo-ultimate' ); ?> &rarr;
							</a>
						</div>
					</div>

				</div>
			</section>

		</div>

		<?php
	}

	/**
	 * Check whether a module's requirements are met.
	 * Currently supports 'woocommerce' as a required dependency.
	 *
	 * @param array $module
	 * @return bool
	 */
	private static function module_requirements_met( $module ) {
		$requires = $module['requires'] ?? '';

		if ( ! $requires ) {
			return true;
		}

		switch ( $requires ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' );
			default:
				return true;
		}
	}

	/**
	 * Render a single module card with toggle.
	 *
	 * @param string $slug     Module slug.
	 * @param array  $module   Module definition.
	 * @param bool   $active   Whether the module is currently active.
	 * @param bool   $locked   Whether the module is locked (pro).
	 */
	private static function render_module_card( $slug, $module, $active, $locked = false ) {
		$card_class = 'twt-aeo-module-card';
		if ( $active ) {
			$card_class .= ' twt-aeo-module-card--active';
		}
		if ( $locked ) {
			$card_class .= ' twt-aeo-module-card--locked';
		}
		?>
		<div class="<?php echo esc_attr( $card_class ); ?>" data-module="<?php echo esc_attr( $slug ); ?>">
			<div class="twt-aeo-module-card__header">
				<div class="twt-aeo-module-card__icon">
					<span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span>
				</div>
				<div class="twt-aeo-module-card__toggle-wrap">
					<?php if ( $locked ) : ?>
						<span class="twt-aeo-lock-badge"><?php esc_html_e( 'PRO', 'twt-aeo-ultimate' ); ?></span>
					<?php else : ?>
						<label class="twt-aeo-toggle" aria-label="<?php echo esc_attr( $module['title'] ); ?>">
							<input
								type="checkbox"
								class="twt-aeo-toggle__input js-module-toggle"
								data-slug="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $active ); ?>
							/>
							<span class="twt-aeo-toggle__track"></span>
							<span class="twt-aeo-toggle__thumb"></span>
						</label>
					<?php endif; ?>
				</div>
			</div>
			<div class="twt-aeo-module-card__body">
				<h3 class="twt-aeo-module-card__title"><?php echo esc_html( $module['title'] ); ?></h3>
				<p class="twt-aeo-module-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
				<?php if ( ! empty( $module['menu'] ) && ! $locked ) : ?>
					<p class="twt-aeo-module-card__location">
						<span class="dashicons dashicons-admin-links"></span>
						<?php
						printf(
							/* translators: %s: native WP section name */
							esc_html__( 'Settings appear under: %s', 'twt-aeo-ultimate' ),
							'<strong>' . esc_html( ucfirst( $module['menu']['parent'] ) ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}