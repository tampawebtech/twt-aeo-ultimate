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

					<!-- AEO Ultimate for WooCommerce -->
					<div class="twt-aeo-promo-card">
						<div class="twt-aeo-promo-card__badge">
							<span class="twt-aeo-promo-badge twt-aeo-promo-badge--pro-plugin"><?php esc_html_e( 'Available', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-promo-card__head">
							<div class="twt-aeo-promo-card__icon twt-aeo-promo-card__icon--aeo-pro">
								<span class="dashicons dashicons-cart"></span>
							</div>
							<div>
								<h3 class="twt-aeo-promo-card__title"><?php esc_html_e( 'AEO Ultimate for WooCommerce', 'twt-aeo-ultimate' ); ?></h3>
								<p class="twt-aeo-promo-card__tagline"><?php esc_html_e( 'Be the store AI assistants name when shoppers ask what to buy, and let AI agents check out', 'twt-aeo-ultimate' ); ?></p>
							</div>
						</div>
						<ul class="twt-aeo-promo-card__features">
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'One connected Product schema graph built from live WooCommerce data: displayed prices, stock from variations, shipping zones', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'Google and Bing Merchant Center parity checks', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'See which AI crawlers read your store and whether AI engines cite it', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'AI agents check out through Google\'s UCP and the ACP used by ChatGPT', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'WooCommerce calculates every total; agents pay through your own Stripe account', 'twt-aeo-ultimate' ); ?></li>
							<li><span class="twt-aeo-promo-check">✓</span> <?php esc_html_e( 'HPOS and checkout-blocks compatible, no telemetry, 12 video tutorials', 'twt-aeo-ultimate' ); ?></li>
						</ul>
						<div class="twt-aeo-promo-card__foot">
							<a class="button twt-aeo-promo-cta twt-aeo-promo-cta--pro-plugin" href="<?php echo esc_url( 'https://tampawebtech.com/aeo-ultimate-for-woocommerce/' ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Learn More', 'twt-aeo-ultimate' ); ?> &rarr;
							</a>
							<a class="button" href="<?php echo esc_url( 'https://aeoultimate.com/docs/woocommerce/' ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Documentation', 'twt-aeo-ultimate' ); ?>
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