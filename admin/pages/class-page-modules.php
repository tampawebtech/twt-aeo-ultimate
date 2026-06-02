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
					<div class="twt-aeo-promo-card twt-aeo-promo-card--soon">
						<div class="twt-aeo-promo-card__badge">
							<span class="twt-aeo-promo-badge twt-aeo-promo-badge--soon"><?php esc_html_e( 'Coming Soon', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-promo-card__head">
							<div class="twt-aeo-promo-card__icon twt-aeo-promo-card__icon--soon">
								<span class="dashicons dashicons-edit-large"></span>
							</div>
							<div>
								<h3 class="twt-aeo-promo-card__title"><?php esc_html_e( 'TWT Content Generator', 'twt-aeo-ultimate' ); ?></h3>
								<p class="twt-aeo-promo-card__tagline"><?php esc_html_e( 'AI-powered content — three models, one workflow', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>
						<ul class="twt-aeo-promo-card__features">
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Generate with Claude, ChatGPT & Perplexity simultaneously', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Hub & sub-page architecture for topic clusters', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'AEO semantic elements baked into every prompt', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Tone, audience, intent, format & UVP controls', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Save drafts directly from the generator — no copy-paste', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Inherits your AEO API keys automatically', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<div class="twt-aeo-promo-card__foot">
							<span class="button twt-aeo-promo-cta twt-aeo-promo-cta--muted" aria-disabled="true">
								<?php esc_html_e( 'Coming Soon', 'twt-aeo-ultimate' ); ?>
							</span>
						</div>
					</div>

					<!-- TWT AEO Pro -->
					<div class="twt-aeo-promo-card twt-aeo-promo-card--soon">
						<div class="twt-aeo-promo-card__badge">
							<span class="twt-aeo-promo-badge twt-aeo-promo-badge--soon"><?php esc_html_e( 'Coming Soon', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-promo-card__head">
							<div class="twt-aeo-promo-card__icon twt-aeo-promo-card__icon--soon">
								<span class="dashicons dashicons-chart-line"></span>
							</div>
							<div>
								<h3 class="twt-aeo-promo-card__title"><?php esc_html_e( 'TWT AEO Pro', 'twt-aeo-ultimate' ); ?></h3>
								<p class="twt-aeo-promo-card__tagline"><?php esc_html_e( 'The full AEO toolkit — every pro module unlocked', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>
						<ul class="twt-aeo-promo-card__features">
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Unlock all Pro Modules with a single license', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Advanced schema types — FAQ, HowTo, Product & more', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Full E-E-A-T author system with Person schema & hover cards', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'AI-ready compliance tools + isitagentready.com sync', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Reviews CPT with structured data & platform recommendations', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check twt-aeo-promo-check--muted">✓</span> <?php esc_html_e( 'Priority support & automatic updates', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<div class="twt-aeo-promo-card__foot">
							<span class="button twt-aeo-promo-cta twt-aeo-promo-cta--muted" aria-disabled="true">
								<?php esc_html_e( 'Coming Soon', 'twt-aeo-ultimate' ); ?>
							</span>
						</div>
					</div>

				</div>
			</section>

		</div>

		<style>
		/* ── Pro Plugins promo grid ──────────────────────────────────────────────── */
		.twt-aeo-promo-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
		}
		@media (max-width: 900px) {
			.twt-aeo-promo-grid { grid-template-columns: 1fr; }
		}
		.twt-aeo-promo-card {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 10px;
			padding: 24px;
			display: flex;
			flex-direction: column;
			gap: 18px;
			box-shadow: 0 1px 4px rgba(0,0,0,.06);
			position: relative;
			overflow: hidden;
		}
		.twt-aeo-promo-card::before {
			content: '';
			position: absolute;
			top: 0; left: 0; right: 0;
			height: 3px;
			background: linear-gradient(90deg, #1e40af, #3b82f6);
		}
		.twt-aeo-promo-card--installed::before {
			background: linear-gradient(90deg, #15803d, #22c55e);
		}
		.twt-aeo-promo-card--aeo-pro::before {
			background: linear-gradient(90deg, #6d28d9, #8b5cf6);
		}
		.twt-aeo-promo-card--soon::before {
			background: linear-gradient(90deg, #6b7280, #9ca3af);
		}
		.twt-aeo-promo-card--soon {
			opacity: .78;
		}
		.twt-aeo-promo-card__badge {
			position: absolute;
			top: 14px; right: 16px;
		}
		.twt-aeo-promo-badge {
			font-size: 11px;
			font-weight: 700;
			padding: 2px 9px;
			border-radius: 20px;
			text-transform: uppercase;
			letter-spacing: .04em;
		}
		.twt-aeo-promo-badge--new        { background: #eff6ff; color: #1d4ed8; }
		.twt-aeo-promo-badge--installed  { background: #f0fdf4; color: #15803d; }
		.twt-aeo-promo-badge--soon       { background: #f3f4f6; color: #6b7280; }
		.twt-aeo-promo-badge--pro-plugin { background: #faf5ff; color: #7c3aed; }
		.twt-aeo-promo-card__head {
			display: flex;
			align-items: flex-start;
			gap: 14px;
			margin-top: 4px;
		}
		.twt-aeo-promo-card__icon {
			width: 44px;
			height: 44px;
			border-radius: 10px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}
		.twt-aeo-promo-card__icon--cg      { background: #eff6ff; color: #1d4ed8; }
		.twt-aeo-promo-card__icon--aeo-pro { background: #faf5ff; color: #7c3aed; }
		.twt-aeo-promo-card__icon--soon    { background: #f3f4f6; color: #9ca3af; }
		.twt-aeo-promo-card__icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
		.twt-aeo-promo-card__title {
			font-size: 16px;
			font-weight: 700;
			color: #111827;
			margin: 0 0 3px;
		}
		.twt-aeo-promo-card__tagline {
			font-size: 13px;
			color: #6b7280;
			margin: 0;
		}
		.twt-aeo-promo-card__features {
			list-style: none;
			margin: 0;
			padding: 0;
			display: flex;
			flex-direction: column;
			gap: 8px;
			flex: 1;
		}
		.twt-aeo-promo-card__features li {
			display: flex;
			align-items: flex-start;
			gap: 8px;
			font-size: 13px;
			color: #374151;
			line-height: 1.45;
		}
		.twt-aeo-promo-check { color: #16a34a; font-weight: 700; flex-shrink: 0; }
		.twt-aeo-promo-check--muted { color: #9ca3af; font-weight: 400; }
		.twt-aeo-promo-card__pricing {
			display: flex;
			gap: 20px;
			padding: 14px 16px;
			background: #f9fafb;
			border-radius: 7px;
			border: 1px solid #f3f4f6;
		}
		.twt-aeo-promo-price {
			display: flex;
			flex-direction: column;
			gap: 2px;
		}
		.twt-aeo-promo-price__amount {
			font-size: 22px;
			font-weight: 800;
			color: #111827;
			line-height: 1;
		}
		.twt-aeo-promo-price__label {
			font-size: 11px;
			color: #6b7280;
		}
		.twt-aeo-promo-card__foot { margin-top: auto; }
		.twt-aeo-promo-cta { width: 100%; text-align: center; justify-content: center; height: 38px; line-height: 36px; padding: 0 !important; display: block !important; }
		.twt-aeo-promo-cta--muted       { color: #6b7280 !important; border-color: #d1d5db !important; }
		.twt-aeo-promo-cta--pro-plugin  { background: #7c3aed !important; border-color: #6d28d9 !important; color: #fff !important; }
		.twt-aeo-promo-cta--pro-plugin:hover { background: #6d28d9 !important; }
		</style>
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