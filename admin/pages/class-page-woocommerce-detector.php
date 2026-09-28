<?php
/**
 * WooCommerce Detector Page
 *
 * Tab 1 — Products: schema status, missing info, per-row / bulk schema generation.
 * Tab 2 — Google Merchant Center: GMC sync, integrity score, rejection log.
 * Tab 3 — Bing Merchant Center: BMC sync, integrity score, rejection log.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_WooCommerce_Detector {

	const NONCE     = 'twtaeo_wc_nonce';
	const NONCE_GMC = 'twtaeo_gmc_nonce';
	const NONCE_BMC = 'twtaeo_bmc_nonce';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		if ( ! TWTAEO_WooCommerce_Detector::is_woocommerce_active() ) {
			echo '<div class="wrap twt-aeo-wrap"><div class="twt-aeo-card"><p class="twt-aeo-empty">'
				. esc_html__( 'WooCommerce is not active. Please install and activate WooCommerce to use this module.', 'twt-aeo-ultimate' )
				. '</p></div></div>';
			return;
		}

		// Handle OAuth callbacks — check which tab the redirect is returning to.
		$returning_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $returning_tab === 'bmc' ) {
				$result = TWTAEO_Bing_Merchant_Center::handle_callback(
					sanitize_text_field( wp_unslash( $_GET['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					sanitize_text_field( wp_unslash( $_GET['state'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				$success_msg = __( 'Connected to Bing Merchant Center.', 'twt-aeo-ultimate' );
				$clean_url   = admin_url( 'admin.php?page=twt-aeo-woocommerce&tab=bmc' );
			} else {
				$result = TWTAEO_Google_Merchant_Center::handle_callback(
					sanitize_text_field( wp_unslash( $_GET['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					sanitize_text_field( wp_unslash( $_GET['state'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				$success_msg = __( 'Connected to Google Merchant Center.', 'twt-aeo-ultimate' );
				$clean_url   = admin_url( 'admin.php?page=twt-aeo-woocommerce&tab=gmc' );
			}
			if ( is_wp_error( $result ) ) {
				add_action( 'admin_notices', function() use ( $result ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				} );
			} else {
				add_action( 'admin_notices', function() use ( $success_msg ) {
					echo '<div class="notice notice-success"><p>' . esc_html( $success_msg ) . '</p></div>';
				} );
			}
			wp_safe_redirect( $clean_url );
			exit;
		}

		// Resolve active modules and tab.
		$modules    = $GLOBALS['twtaeo_plugin']->get_modules() ?? null;
		$gmc_active = $modules ? $modules->is_active( 'woocommerce-gmc' ) : false;
		$bmc_active = $modules ? $modules->is_active( 'woocommerce-bmc' ) : false;
		$show_tabs  = $gmc_active || $bmc_active;

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $tab === 'gmc' && ! $gmc_active ) {
			$tab = 'products';
		}
		if ( $tab === 'bmc' && ! $bmc_active ) {
			$tab = 'products';
		}

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'WooCommerce', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<?php self::render_commerce_tabs( $tab ); ?>

			<?php if ( $tab === 'gmc' ) : ?>
				<?php self::render_gmc_tab(); ?>
			<?php elseif ( $tab === 'bmc' ) : ?>
				<?php self::render_bmc_tab(); ?>
			<?php else : ?>
				<?php self::render_products_tab(); ?>
			<?php endif; ?>

		</div><!-- .twt-aeo-wrap -->
		<?php
	}

	/**
	 * The shared commerce tab bar: Products / GMC / BMC render in-page on this
	 * screen; Smart Collections and Promotions are their own admin pages (they
	 * carry sub-tabs of their own, so their `tab` param cannot nest inside this
	 * page's) — hidden from the sidebar and reached only through this bar, so
	 * the merchant experiences one tabbed WooCommerce screen.
	 *
	 * @param string $active One of products|gmc|bmc|collections|promotions.
	 */
	public static function render_commerce_tabs( $active ) {
		$modules = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->get_modules() : null;

		$wc_url = static function ( $tab ) {
			return add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => $tab ), admin_url( 'admin.php' ) );
		};

		// Every commerce feature is always a tab. A module that is off renders as
		// a dimmed tab pointing at Modules — hiding it would hide the gap, and a
		// merchant who never learns Promotions exists never publishes the data.
		$tabs = array(
			'products' => array(
				'label'  => __( 'Products', 'twt-aeo-ultimate' ),
				'icon'   => 'dashicons-cart',
				'url'    => $wc_url( 'products' ),
				'active' => true,
			),
			'gmc' => array(
				'label'  => __( 'Google Merchant Center', 'twt-aeo-ultimate' ),
				'icon'   => 'dashicons-google',
				'url'    => $wc_url( 'gmc' ),
				'active' => $modules ? $modules->is_active( 'woocommerce-gmc' ) : false,
			),
			'bmc' => array(
				'label'  => __( 'Bing Merchant Center', 'twt-aeo-ultimate' ),
				'icon'   => 'dashicons-search',
				'url'    => $wc_url( 'bmc' ),
				'active' => $modules ? $modules->is_active( 'woocommerce-bmc' ) : false,
			),
			'collections' => array(
				'label'  => __( 'Smart Collections', 'twt-aeo-ultimate' ),
				'icon'   => 'dashicons-screenoptions',
				'url'    => admin_url( 'admin.php?page=twt-aeo-collections' ),
				'active' => $modules ? $modules->is_active( 'smart-collections' ) : false,
			),
			'promotions' => array(
				'label'  => __( 'Promotions', 'twt-aeo-ultimate' ),
				'icon'   => 'dashicons-tag',
				'url'    => admin_url( 'admin.php?page=' . TWTAEO_Page_Promotions::PAGE_SLUG ),
				'active' => $modules ? $modules->is_active( 'promotions' ) : false,
			),
		);

		$modules_url = admin_url( 'admin.php?page=twt-aeo-modules' );
		?>
		<nav class="twt-aeo-tabs">
			<?php foreach ( $tabs as $slug => $t ) : ?>
				<?php if ( $t['active'] ) : ?>
				<a href="<?php echo esc_url( $t['url'] ); ?>"
				   class="twt-aeo-tab <?php echo $slug === $active ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons <?php echo esc_attr( $t['icon'] ); ?>"></span>
					<?php echo esc_html( $t['label'] ); ?>
				</a>
				<?php else : ?>
				<a href="<?php echo esc_url( $modules_url ); ?>"
				   class="twt-aeo-tab twt-aeo-tab--off"
				   title="<?php esc_attr_e( 'This module is off — click to enable it on the Modules page.', 'twt-aeo-ultimate' ); ?>">
					<span class="dashicons <?php echo esc_attr( $t['icon'] ); ?>"></span>
					<?php echo esc_html( $t['label'] ); ?>
					<span class="twt-aeo-tab__off-chip"><?php esc_html_e( 'Off', 'twt-aeo-ultimate' ); ?></span>
				</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	// ── Products Tab ─────────────────────────────────────────────────────────

	private static function render_products_tab() {
		// Merchant's answer to "who publishes Product schema?" — saved before any
		// other output so the card below renders the state that was just chosen.
		if ( isset( $_POST['twtaeo_wc_takeover'] )
			&& check_admin_referer( 'twtaeo_wc_takeover', 'twtaeo_wc_takeover_nonce' ) ) {
			$choice = sanitize_key( wp_unslash( $_POST['twtaeo_wc_takeover'] ) );
			if ( in_array( $choice, array( 'aeo', 'both' ), true ) ) {
				update_option( TWTAEO_WooCommerce_Detector::OPTION_SCHEMA_TAKEOVER, $choice );
			}
		}

		// The scan walks the catalogue DISPLAY_CAP products at a time — scanning
		// everything in one page load times out on large stores, so pagination
		// is how every product past the first page gets seen at all. A search
		// term narrows the walk to matches (title, content, SKU) across the
		// WHOLE catalogue — the product being hunted is almost never on the
		// page already on screen.
		$per_page = TWTAEO_WooCommerce_Detector::DISPLAY_CAP;
		$s_term   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$results = TWTAEO_WooCommerce_Detector::scan_all( $paged, $s_term );
		$total   = ( '' !== $s_term )
			? TWTAEO_WooCommerce_Detector::last_found()
			: TWTAEO_WooCommerce_Detector::total_products();

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $total_pages ) {
			// The requested page fell off the end (usually a search with fewer
			// matches than the page number carried over) — reload the last one.
			$paged   = $total_pages;
			$results = TWTAEO_WooCommerce_Detector::scan_all( $paged, $s_term );
		}
		$first_item = ( $paged - 1 ) * $per_page + 1;

		$summary      = TWTAEO_WooCommerce_Detector::get_summary( $results );
		$last_item    = $first_item + count( $results ) - 1;
		$wc_nonce     = wp_create_nonce( self::NONCE );
		$schema_nonce = wp_create_nonce( TWTAEO_Custom_Schema_Writer::NONCE_ACTION );
		$ship         = TWTAEO_WooCommerce_Detector::get_shipping_defaults();
		$ret          = TWTAEO_WooCommerce_Detector::get_return_defaults();

		$page_url = static function ( $p ) use ( $s_term ) {
			$args = array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'products', 'paged' => (int) $p );
			if ( '' !== $s_term ) {
				$args['s'] = $s_term;
			}
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		};

		$render_pager = static function () use ( $paged, $total_pages, $page_url, $first_item, $last_item, $total ) {
			if ( $total_pages <= 1 ) {
				return;
			}
			?>
			<div class="twt-og-pagination">
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( $page_url( $paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'twt-aeo-ultimate' ); ?></a>
				<?php endif; ?>
				<?php
				$window = array( 1, $total_pages );
				for ( $p = $paged - 2; $p <= $paged + 2; $p++ ) {
					$window[] = $p;
				}
				$window = array_values( array_unique( array_filter( $window, static function ( $p ) use ( $total_pages ) {
					return $p >= 1 && $p <= $total_pages;
				} ) ) );
				sort( $window );
				$prev = 0;
				foreach ( $window as $p ) :
					if ( $p > $prev + 1 ) : ?>
						<span style="color:#8c8f94;">&hellip;</span>
					<?php endif;
					$prev = $p;
					if ( $p === $paged ) : ?>
						<span class="current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $page_url( $p ) ); ?>"><?php echo esc_html( $p ); ?></a>
					<?php endif;
				endforeach; ?>
				<?php if ( $paged < $total_pages ) : ?>
					<a href="<?php echo esc_url( $page_url( $paged + 1 ) ); ?>"><?php esc_html_e( 'Next', 'twt-aeo-ultimate' ); ?> &raquo;</a>
				<?php endif; ?>
				<span style="font-size:12px;color:#666;margin-left:6px;">
					<?php
					// translators: %1$d: first item on page. %2$d: last item on page. %3$d: total items.
					printf( esc_html__( '%1$d–%2$d of %3$d', 'twt-aeo-ultimate' ), absint( $first_item ), absint( max( $first_item, $last_item ) ), absint( $total ) ); ?>
				</span>
			</div>
			<?php
		};
		?>

		<?php if ( isset( $_GET['rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI confirmation, no state change. ?>
		<div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
			<p>
				<?php
				printf(
					// translators: %1$d: products scanned on this page. %2$d: products missing schema among them.
					esc_html__( 'Rescan complete — %1$d products scanned, %2$d missing schema.', 'twt-aeo-ultimate' ),
					absint( $summary['scanned'] ),
					absint( $summary['needs_schema'] )
				);
				?>
			</p>
		</div>
		<?php endif; ?>

		<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
			<p style="margin:0;color:#50575e;">
				<?php
				// $total is search-aware: matches when a term is set, the whole
				// catalogue otherwise. $summary['total'] is always the catalogue
				// and would misreport a narrowed view.
				if ( $total_pages > 1 ) {
					printf(
						// translators: %1$d: first product shown. %2$d: last product shown. %3$d: total in view. %4$d: products missing schema on this page.
						esc_html__( 'Products %1$d–%2$d of %3$d (newest first) — %4$d on this page missing schema', 'twt-aeo-ultimate' ),
						absint( $first_item ),
						absint( $last_item ),
						absint( $total ),
						absint( $summary['needs_schema'] )
					);
				} else {
					printf(
						// translators: %1$d: total products in view. %2$d: products missing schema.
						esc_html__( '%1$d products — %2$d missing schema', 'twt-aeo-ultimate' ),
						absint( $total ),
						absint( $summary['needs_schema'] )
					);
				}
				?>
			</p>
			<a href="<?php echo esc_url( add_query_arg( 'rescanned', time(), $page_url( $paged ) ) ); ?>" class="button">
				<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;line-height:1.4;vertical-align:middle;margin-right:2px;"></span>
				<?php esc_html_e( 'Scan Again', 'twt-aeo-ultimate' ); ?>
			</a>
			<?php // The bulk generator walks the WHOLE catalogue, so it must stay
			// reachable even when the current page happens to be clean — only a
			// fully-scanned, fully-clean catalogue can hide it. ?>
			<?php if ( $summary['needs_schema'] > 0 || $summary['truncated'] ) : ?>
			<button type="button" id="twt-aeo-wc-gen-all" class="button button-primary">
				<?php esc_html_e( 'Generate Schema for All', 'twt-aeo-ultimate' ); ?>
			</button>
			<?php endif; ?>
			<button type="button" id="twt-aeo-wc-alt-all" class="button">
				&#10024; <?php esc_html_e( 'Fill All Missing Alt Text (AI)', 'twt-aeo-ultimate' ); ?>
			</button>
			<button type="button" id="twt-aeo-wc-alt-all-stop" class="button" style="display:none;">
				<?php esc_html_e( 'Stop', 'twt-aeo-ultimate' ); ?>
			</button>
			<span id="twt-aeo-wc-alt-all-status" style="font-size:12px;color:#646970;"></span>
		</div>

		<div id="twt-aeo-wc-gen-all-result" style="display:none;margin:0 0 16px;" class="notice"></div>

		<?php
		// Who publishes Product schema on product pages? WooCommerce ships a
		// basic block of its own; ours is the connected @graph. Two Product
		// blocks on one page is a real conflict (rich-results warnings), but
		// removing another plugin's output without asking is not our call.
		$takeover = get_option( TWTAEO_WooCommerce_Detector::OPTION_SCHEMA_TAKEOVER, '' );
		?>
		<?php if ( 'aeo' === $takeover ) : ?>
		<div class="twt-aeo-card" style="border-left:3px solid #16a34a;margin-bottom:16px;padding:12px 16px;">
			<form method="post" style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<?php wp_nonce_field( 'twtaeo_wc_takeover', 'twtaeo_wc_takeover_nonce' ); ?>
				<p style="margin:0;font-size:13px;color:#50575e;">
					<span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'AEO publishes the only Product schema on product pages; WooCommerce’s built-in block is suppressed so the two never compete.', 'twt-aeo-ultimate' ); ?>
				</p>
				<button type="submit" name="twtaeo_wc_takeover" value="both" class="button-link" style="font-size:12px;">
					<?php esc_html_e( 'Restore WooCommerce’s built-in block', 'twt-aeo-ultimate' ); ?>
				</button>
			</form>
		</div>
		<?php else : ?>
		<div class="twt-aeo-card" style="border-left:3px solid <?php echo 'both' === $takeover ? '#dba617' : '#2271b1'; ?>;margin-bottom:16px;">
			<p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;max-width:820px;">
				<strong><?php esc_html_e( 'Who publishes your Product schema?', 'twt-aeo-ultimate' ); ?></strong>
				<?php esc_html_e( 'WooCommerce ships a basic Product block of its own — classic themes publish it on every product page; some block themes don’t. Where it appears alongside AEO’s connected graph (variants, shipping, returns, identifiers, reviews), search engines see two competing descriptions of the same product and may warn about it — a conflicted signal cites worse than a clear one. Letting AEO be the only Product schema suppresses WooCommerce’s block where it exists and changes nothing where it doesn’t. Keep both if another tool depends on WooCommerce’s block.', 'twt-aeo-ultimate' ); ?>
			</p>
			<form method="post" style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<?php wp_nonce_field( 'twtaeo_wc_takeover', 'twtaeo_wc_takeover_nonce' ); ?>
				<button type="submit" name="twtaeo_wc_takeover" value="aeo" class="button button-primary">
					<?php esc_html_e( 'Make AEO the only Product schema (recommended)', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php if ( 'both' !== $takeover ) : ?>
				<button type="submit" name="twtaeo_wc_takeover" value="both" class="button">
					<?php esc_html_e( 'Keep both', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php endif; ?>
			</form>
		</div>
		<?php endif; ?>

		<!-- Summary Cards -->
		<?php
		// Every card except "Total Products" counts only what THIS page scanned.
		// On a paginated catalogue the labels must say so — "0 Missing Schema"
		// beside "949 Total Products" would otherwise read as a site-wide
		// verdict the scan never made.
		$scoped = $summary['truncated'];
		?>
		<section class="twt-aeo-section">
			<div class="twt-aeo-summary-grid">
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Total Products', 'twt-aeo-ultimate' ); ?></div>
				</div>
				<div class="twt-aeo-summary-card <?php echo $summary['needs_schema'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['needs_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php echo esc_html( $scoped ? __( 'Missing Schema (this page)', 'twt-aeo-ultimate' ) : __( 'Missing Schema', 'twt-aeo-ultimate' ) ); ?></div>
				</div>
				<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['has_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php echo esc_html( $scoped ? __( 'Schema Present (this page)', 'twt-aeo-ultimate' ) : __( 'Schema Present', 'twt-aeo-ultimate' ) ); ?></div>
				</div>
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['product_intent'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php echo esc_html( $scoped ? __( 'Physical Products (this page)', 'twt-aeo-ultimate' ) : __( 'Physical Products', 'twt-aeo-ultimate' ) ); ?></div>
				</div>
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['service_intent'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php echo esc_html( $scoped ? __( 'Service Products (this page)', 'twt-aeo-ultimate' ) : __( 'Service Products', 'twt-aeo-ultimate' ) ); ?></div>
				</div>
			</div>
			<?php if ( $scoped ) : ?>
			<p style="margin:8px 0 0;font-size:12px;color:#646970;">
				<?php
				printf(
					// translators: %1$d: products scanned on this page. %2$d: catalogue total.
					esc_html__( 'These cards describe the %1$d products scanned on this page. Page through below to cover all %2$d — or use "Generate Schema for All", which always walks the whole catalogue.', 'twt-aeo-ultimate' ),
					absint( $summary['scanned'] ),
					absint( $summary['total'] )
				);
				?>
			</p>
			<?php endif; ?>
		</section>

		<!-- Site-wide Shipping & Returns Defaults -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-card" style="padding:0;">
				<details id="twt-aeo-wc-logistics">
					<summary style="cursor:pointer;padding:16px 20px;font-weight:600;font-size:15px;list-style:none;">
						<span class="dashicons dashicons-cart" style="vertical-align:middle;margin-right:6px;color:#6b7280;"></span>
						<?php esc_html_e( 'Shipping & Returns Defaults', 'twt-aeo-ultimate' ); ?>
						<span style="font-weight:400;color:#6b7280;font-size:13px;margin-left:8px;">
							<?php esc_html_e( 'Applied to every product’s schema', 'twt-aeo-ultimate' ); ?>
						</span>
					</summary>
					<div style="padding:0 20px 20px;border-top:1px solid #f0f0f1;">
						<form id="twt-aeo-wc-logistics-form" style="max-width:640px;">
							<table class="form-table" style="margin:0;">
								<tbody>
									<tr>
										<th scope="row" style="width:200px;"><?php esc_html_e( 'Include shipping details', 'twt-aeo-ultimate' ); ?></th>
										<td><label><input type="checkbox" name="ship_enabled" value="1" <?php checked( ! empty( $ship['enabled'] ) ); ?>> <?php esc_html_e( 'Add OfferShippingDetails to product schema', 'twt-aeo-ultimate' ); ?></label></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Ships to (country code)', 'twt-aeo-ultimate' ); ?></th>
										<td><input type="text" name="ship_country" style="width:80px;" maxlength="2" value="<?php echo esc_attr( $ship['country'] ); ?>" placeholder="US"></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Shipping rate', 'twt-aeo-ultimate' ); ?></th>
										<td>
											<select name="ship_rate_type" id="twt-aeo-ship-rate-type">
												<option value="flat" <?php selected( $ship['rate_type'], 'flat' ); ?>><?php esc_html_e( 'Flat rate', 'twt-aeo-ultimate' ); ?></option>
												<option value="free" <?php selected( $ship['rate_type'], 'free' ); ?>><?php esc_html_e( 'Free shipping', 'twt-aeo-ultimate' ); ?></option>
											</select>
											<input type="text" name="ship_rate" style="width:90px;margin-left:8px;" value="<?php echo esc_attr( $ship['rate'] ); ?>" placeholder="5.00">
											<input type="text" name="ship_currency" style="width:70px;" maxlength="3" value="<?php echo esc_attr( $ship['currency'] ); ?>" placeholder="USD">
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Handling time (days)', 'twt-aeo-ultimate' ); ?></th>
										<td>
											<?php esc_html_e( 'min', 'twt-aeo-ultimate' ); ?> <input type="number" name="ship_handling_min" style="width:70px;" min="0" value="<?php echo esc_attr( $ship['handling_min'] ); ?>">
											<?php esc_html_e( 'max', 'twt-aeo-ultimate' ); ?> <input type="number" name="ship_handling_max" style="width:70px;" min="0" value="<?php echo esc_attr( $ship['handling_max'] ); ?>">
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Transit time (days)', 'twt-aeo-ultimate' ); ?></th>
										<td>
											<?php esc_html_e( 'min', 'twt-aeo-ultimate' ); ?> <input type="number" name="ship_transit_min" style="width:70px;" min="0" value="<?php echo esc_attr( $ship['transit_min'] ); ?>">
											<?php esc_html_e( 'max', 'twt-aeo-ultimate' ); ?> <input type="number" name="ship_transit_max" style="width:70px;" min="0" value="<?php echo esc_attr( $ship['transit_max'] ); ?>">
										</td>
									</tr>
									<tr><td colspan="2"><hr style="margin:4px 0;border:0;border-top:1px solid #f0f0f1;"></td></tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Include return policy', 'twt-aeo-ultimate' ); ?></th>
										<td><label><input type="checkbox" name="ret_enabled" value="1" <?php checked( ! empty( $ret['enabled'] ) ); ?>> <?php esc_html_e( 'Add hasMerchantReturnPolicy to product schema', 'twt-aeo-ultimate' ); ?></label></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Applies to (country code)', 'twt-aeo-ultimate' ); ?></th>
										<td><input type="text" name="ret_country" style="width:80px;" maxlength="2" value="<?php echo esc_attr( $ret['country'] ); ?>" placeholder="US"></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Return window (days)', 'twt-aeo-ultimate' ); ?></th>
										<td><input type="number" name="ret_days" style="width:80px;" min="0" value="<?php echo esc_attr( $ret['days'] ); ?>"></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Return fees', 'twt-aeo-ultimate' ); ?></th>
										<td>
											<select name="ret_fees">
												<option value="free" <?php selected( $ret['fees'], 'free' ); ?>><?php esc_html_e( 'Free returns', 'twt-aeo-ultimate' ); ?></option>
												<option value="paid" <?php selected( $ret['fees'], 'paid' ); ?>><?php esc_html_e( 'Customer pays return shipping', 'twt-aeo-ultimate' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Return method', 'twt-aeo-ultimate' ); ?></th>
										<td>
											<select name="ret_method">
												<option value="https://schema.org/ReturnByMail" <?php selected( $ret['method'], 'https://schema.org/ReturnByMail' ); ?>><?php esc_html_e( 'Return by mail', 'twt-aeo-ultimate' ); ?></option>
												<option value="https://schema.org/ReturnInStore" <?php selected( $ret['method'], 'https://schema.org/ReturnInStore' ); ?>><?php esc_html_e( 'Return in store', 'twt-aeo-ultimate' ); ?></option>
												<option value="https://schema.org/ReturnAtKiosk" <?php selected( $ret['method'], 'https://schema.org/ReturnAtKiosk' ); ?>><?php esc_html_e( 'Return at kiosk', 'twt-aeo-ultimate' ); ?></option>
											</select>
										</td>
									</tr>
								</tbody>
							</table>
							<div style="margin-top:12px;display:flex;align-items:center;gap:12px;">
								<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Defaults', 'twt-aeo-ultimate' ); ?></button>
								<span id="twt-aeo-wc-logistics-msg" style="display:none;font-size:13px;color:#16a34a;"></span>
							</div>
						</form>
					</div>
				</details>
			</div>
		</section>

		<!-- Products Table -->
		<section class="twt-aeo-section">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
				<h2 class="twt-aeo-section__title" style="margin:0;"><?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;align-items:center;gap:6px;margin:0;">
					<input type="hidden" name="page" value="twt-aeo-woocommerce">
					<input type="hidden" name="tab" value="products">
					<label class="screen-reader-text" for="twt-aeo-wc-search"><?php esc_html_e( 'Search products', 'twt-aeo-ultimate' ); ?></label>
					<input type="search" id="twt-aeo-wc-search" name="s" value="<?php echo esc_attr( $s_term ); ?>"
						placeholder="<?php esc_attr_e( 'Search by product name or SKU…', 'twt-aeo-ultimate' ); ?>"
						class="regular-text" autocomplete="off">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'twt-aeo-ultimate' ); ?></button>
					<?php if ( '' !== $s_term ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'products' ), admin_url( 'admin.php' ) ) ); ?>" class="button-link">
							<?php esc_html_e( 'Clear', 'twt-aeo-ultimate' ); ?>
						</a>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( '' !== $s_term ) : ?>
			<p style="margin:0 0 8px;font-size:13px;color:#50575e;">
				<?php
				printf(
					// translators: %1$d: matching products. %2$s: the search term.
					esc_html( _n( '%1$d product matches “%2$s” — searched across the whole catalogue.', '%1$d products match “%2$s” — searched across the whole catalogue.', $total, 'twt-aeo-ultimate' ) ),
					absint( $total ),
					esc_html( $s_term )
				);
				?>
			</p>
			<?php endif; ?>

			<?php if ( empty( $results ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty">
						<?php echo '' !== $s_term
							? esc_html__( 'No products match that search. It looked at every product’s name, content and SKU.', 'twt-aeo-ultimate' )
							: esc_html__( 'No published products found. Add products in WooCommerce to see them here.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			<?php else : ?>
			<?php $render_pager(); ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table twt-aeo-wc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Type / Intent', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Missing Info', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Schema', 'twt-aeo-ultimate' ); ?></th>
							<th title="<?php esc_attr_e( 'Coverage of AI-generated image alt text', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Image Alt', 'twt-aeo-ultimate' ); ?></th>
							<th title="<?php esc_attr_e( 'How rich the product schema is for rich results & AI agents', 'twt-aeo-ultimate' ); ?>"><?php esc_html_e( 'Optimization', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results as $item ) :
						$post       = $item['post'];
						$wc         = $item['wc_data'];
						$row_cls    = $wc['needs_schema'] ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok';
						$wc_product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
						$edit_url   = get_edit_post_link( $post->ID, 'raw' );
						$missing    = self::get_product_missing( $wc_product, $edit_url );
						$has_custom = (bool) TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, $wc['recommended_schema'] );
						$alt_sum    = TWTAEO_Product_Enricher::image_alt_summary( $post->ID );
					?>
						<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>"
							data-post-id="<?php echo esc_attr( $post->ID ); ?>"
							data-alt-missing="<?php echo esc_attr( $alt_sum['missing'] ); ?>">

							<td class="twt-aeo-page-row__title">
								<a href="<?php echo esc_url( $edit_url ); ?>">
									<?php echo esc_html( get_the_title( $post ) ); ?>
								</a>
							</td>

							<td>
								<span class="twt-aeo-intent-pill" style="margin-bottom:4px;display:inline-block;">
									<?php echo esc_html( ucfirst( $wc['product_type'] ) ); ?>
								</span><br>
								<span class="twt-aeo-intent-pill <?php echo $wc['intent'] === 'service' ? 'twt-aeo-intent-pill--service' : ''; ?>">
									<?php echo esc_html( ucfirst( $wc['intent'] ) ); ?>
								</span>
							</td>

							<td class="twt-aeo-wc-missing-cell">
								<?php if ( empty( $missing ) ) : ?>
									<span class="twt-aeo-muted">—</span>
								<?php else : ?>
									<ul class="twt-aeo-wc-missing-list">
									<?php foreach ( $missing as $m ) : ?>
										<li class="twt-aeo-wc-missing-item">
											<span class="dashicons dashicons-warning" style="color:#d63638;font-size:14px;vertical-align:middle;margin-right:3px;"></span>
											<strong><?php echo esc_html( $m['field'] ); ?>:</strong>
											<?php echo esc_html( $m['message'] ); ?>
											<a href="<?php echo esc_url( $m['fix_url'] ); ?>" class="twt-aeo-link" style="margin-left:4px;">
												<?php esc_html_e( 'Fix →', 'twt-aeo-ultimate' ); ?>
											</a>
										</li>
									<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>

							<td class="twt-aeo-wc-schema-cell">
								<?php if ( $has_custom ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">
										<?php echo esc_html( $wc['recommended_schema'] ); ?> ✓
									</span>
								<?php elseif ( ! $wc['needs_schema'] ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">
										<?php echo esc_html( $wc['schema_source'] ); ?>
									</span>
								<?php else : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn">
										<?php
								// translators: %s: recommended schema type name.
								echo esc_html( sprintf( __( 'Add %s', 'twt-aeo-ultimate' ), $wc['recommended_schema'] ) ); ?>
									</span>
								<?php endif; ?>
							</td>

							<td class="twt-aeo-wc-alt-cell">
								<?php echo wp_kses_post( TWTAEO_Product_Enricher::alt_badge_html( $alt_sum ) ); ?>
							</td>

							<td class="twt-aeo-wc-opt-cell">
								<?php
								if ( $wc['recommended_schema'] !== 'Product' ) {
									echo '<span class="twt-aeo-muted">&mdash;</span>';
								} else {
									echo wp_kses_post( TWTAEO_WooCommerce_Detector::optimization_badge_html(
										TWTAEO_WooCommerce_Detector::schema_optimization( $post->ID )
									) );
								}
								?>
							</td>

							<td class="twt-aeo-wc-actions-cell">
								<button type="button"
									class="button twt-aeo-wc-schema-btn <?php echo $has_custom ? 'button-secondary' : 'button-primary'; ?>"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									data-recommended="<?php echo esc_attr( $wc['recommended_schema'] ); ?>"
									data-has-custom="<?php echo $has_custom ? '1' : '0'; ?>">
									<?php echo $has_custom
										? esc_html__( 'Edit Schema', 'twt-aeo-ultimate' )
										: esc_html__( 'Generate Schema', 'twt-aeo-ultimate' ); ?>
								</button>
								<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="twt-aeo-link" style="margin-left:6px;">
									<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
								</a>
							</td>

						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php $render_pager(); ?>
			<?php endif; ?>
		</section>

		<!-- Schema Dialog -->
		<div id="twt-aeo-wc-dialog" style="display:none;">
			<p class="twt-aeo-dialog-desc" id="twt-aeo-wc-dialog-desc" style="display:none;"></p>
			<div id="twt-aeo-wc-dialog-form"></div>
			<div id="twt-aeo-wc-dialog-msg" style="display:none;padding:8px 12px;"></div>
		</div>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {

			var ajaxurl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var wcNonce     = <?php echo wp_json_encode( $wc_nonce ); ?>;
			var schemaNonce = <?php echo wp_json_encode( $schema_nonce ); ?>;

			var dlgPostId   = null;
			var dlgType     = null;
			var dlgProdData = {};

			var $dlg = $('#twt-aeo-wc-dialog');

			$dlg.dialog({
				autoOpen:  false,
				modal:     true,
				width:     720,
				minWidth:  560,
				maxHeight: Math.floor(window.innerHeight * 0.88),
				title:     '',
				buttons:   [
					{
						id:    'twt-aeo-wc-dlg-delete',
						text:  'Delete',
						'class': 'button button-link-delete',
						style: 'float:left;margin-right:auto;',
						click: doDelete,
					},
					{
						id:    'twt-aeo-wc-dlg-save',
						text:  'Save Schema',
						'class': 'button button-primary',
						click: doSave,
					},
					{
						text:  'Cancel',
						'class': 'button',
						click: function() { $dlg.dialog('close'); },
					},
				],
				open: function() {
					$('.ui-dialog').css('max-width', 'calc(100vw - 40px)');
				},
			});

			$(document).on('click', '.twt-aeo-wc-schema-btn', function() {
				var $btn      = $(this);
				dlgPostId     = $btn.data('post-id');
				dlgType       = $btn.data('recommended');
				var hasCustom = $btn.data('has-custom') === '1' || $btn.data('has-custom') === 1;

				$dlg.dialog('option', 'title', (hasCustom ? 'Edit ' : 'Generate ') + dlgType + ' Schema');
				$('#twt-aeo-wc-dialog-desc').hide().empty();
				$('#twt-aeo-wc-dialog-form').html('<p style="padding:12px 0;">Loading…</p>');
				$('#twt-aeo-wc-dialog-msg').hide();
				$('#twt-aeo-wc-dlg-delete').toggle(hasCustom);
				$dlg.dialog('open');

				$.post(ajaxurl, {
					action:  'twtaeo_wc_get_product_data',
					nonce:   wcNonce,
					post_id: dlgPostId,
				}, function(resp) {
					if (!resp.success) {
						$('#twt-aeo-wc-dialog-form').html('<p style="color:#d63638;">' + esc(resp.data) + '</p>');
						return;
					}

					dlgProdData = resp.data;
					var d = dlgProdData;

					if (d.missing && d.missing.length) {
						var parts = d.missing.map(function(m) {
							return '<a href="' + esc(m.fix_url) + '" target="_blank" style="color:inherit;">' +
								esc(m.field) + '</a>: ' + esc(m.message);
						});
						$('#twt-aeo-wc-dialog-desc')
							.html('<span class="dashicons dashicons-warning" style="color:#d63638;vertical-align:middle;margin-right:4px;"></span>' +
								'<strong>Missing info:</strong> ' + parts.join(' &nbsp;&middot;&nbsp; '))
							.show();
					}

					var existing = {};
					if (hasCustom && d.has_existing && d.existing_json) {
						try { existing = JSON.parse(d.existing_json); } catch(e) {}
					}

					buildForm(dlgType, existing, d);
				}).fail(function() {
					$('#twt-aeo-wc-dialog-form').html('<p style="color:#d63638;">Request failed.</p>');
				});
			});

			function buildForm(type, existing, d) {
				if (type === 'Product') {
					var price = d.price, cur = d.currency, avail = d.availability;
					if (existing.offers) {
						price = existing.offers.price !== undefined ? existing.offers.price : d.price;
						cur   = existing.offers.priceCurrency || d.currency;
						avail = existing.offers.availability  || d.availability;
					}
					var attrs = d.attributes  || {};
					var ids   = d.identifiers || {};
					var gtin  = existing.gtin14 || existing.gtin13 || existing.gtin12 ||
						existing.gtin8 || existing.gtin || ids.gtin || '';
					var additional = (existing.additionalProperty && existing.additionalProperty.length)
						? existing.additionalProperty.map(function(p){ return { name: p.name || '', value: p.value || '' }; })
						: (attrs.additional || []);
					var vals = {
						name:          existing.name        || d.name,
						description:   existing.description || d.description,
						price:         price,
						currency:      cur,
						sku:           existing.sku         || d.sku,
						availability:  avail,
						image:         existing.image       || d.image_url,
						brand:         (existing.brand && existing.brand.name) || attrs.brand || '',
						color:         existing.color    || attrs.color    || '',
						material:      existing.material || attrs.material || '',
						size:          existing.size     || attrs.size     || '',
						gtin:          gtin,
						mpn:           existing.mpn || ids.mpn || '',
						itemCondition: existing.itemCondition || d.item_condition || 'https://schema.org/NewCondition',
						additional:    additional
					};
					$('#twt-aeo-wc-dialog-form').html(productFormHtml(vals, d));
					initProductForm(vals, d);
				} else {
					var vals = {
						name:        existing.name        || d.name,
						description: existing.description || d.description,
						serviceType: existing.serviceType || '',
						areaServed:  existing.areaServed  || '',
						image:       existing.image       || d.image_url,
					};
					$('#twt-aeo-wc-dialog-form').html(serviceFormHtml(vals));
				}
			}

			function productFormHtml(v, d) {
				var rel = (d && d.relationships) || {};
				var rev = (d && d.reviews) || {};

				// Core fields.
				var core = '<table class="form-table"><tbody>' +
					frow('Name',        '<input type="text" id="wc-f-name" class="large-text" value="' + esc(v.name) + '">') +
					frow('Description',
						'<textarea id="wc-f-desc" rows="3" class="large-text">' + esc(v.description) + '</textarea>' +
						'<div style="margin-top:4px;">' +
							'<button type="button" class="button button-small" id="wc-ai-describe">&#10024; Enhance with AI</button>' +
							'<label style="margin-left:10px;font-size:12px;color:#3c434a;"><input type="checkbox" id="wc-f-update-product-desc"> Also save as the product description</label>' +
							'<div id="wc-ai-describe-status" style="margin-top:3px;font-size:12px;color:#6b7280;"></div>' +
						'</div>') +
					frow('Price',       '<input type="text" id="wc-f-price" style="width:120px;" value="' + esc(v.price) + '"> ' +
						'<input type="text" id="wc-f-currency" style="width:70px;" placeholder="USD" value="' + esc(v.currency) + '">') +
					frow('SKU',         '<input type="text" id="wc-f-sku" style="width:180px;" value="' + esc(v.sku) + '">') +
					frow('Availability','<select id="wc-f-avail">' +
						fopt('https://schema.org/InStock',   'In Stock',    v.availability) +
						fopt('https://schema.org/OutOfStock','Out of Stock', v.availability) +
						fopt('https://schema.org/PreOrder',  'Pre-Order',   v.availability) +
					'</select>') +
					frow('Image URL', '<input type="url" id="wc-f-image" class="large-text" value="' + esc(v.image) + '">') +
					'</tbody></table>';

				// Identifiers & attributes.
				var hasImages = !!(d && d.image_alt && d.image_alt.total);
				var attrs = '<table class="form-table"><tbody>' +
					'<tr><td colspan="2" style="padding:0 0 8px;">' +
						'<button type="button" class="button button-small" id="wc-ai-extract">&#10024; Extract from description (AI)</button> ' +
						'<button type="button" class="button button-small" id="wc-ai-vision"' + (hasImages ? '' : ' disabled title="Add a product image to analyze"') + '>&#10024; Analyze image (AI)</button>' +
						'<div id="wc-ai-extract-status" style="margin-top:6px;font-size:12px;color:#6b7280;"></div>' +
					'</td></tr>' +
					frow('Brand',
						'<input type="text" id="wc-f-brand" class="regular-text" value="' + esc(v.brand) + '"> ' +
						'<button type="button" class="button button-small" id="wc-ai-sameas">&#10024; Find authority links</button>' +
						'<div id="wc-sameas-status" style="margin-top:4px;font-size:12px;color:#6b7280;"></div>' +
						'<div id="wc-sameas-list" style="margin-top:2px;"></div>') +
					frow('Color',    '<input type="text" id="wc-f-color" class="regular-text" value="' + esc(v.color) + '">') +
					frow('Material', '<input type="text" id="wc-f-material" class="regular-text" value="' + esc(v.material) + '">') +
					frow('Size',     '<input type="text" id="wc-f-size" style="width:140px;" value="' + esc(v.size) + '">') +
					frow('GTIN',     '<input type="text" id="wc-f-gtin" style="width:200px;" placeholder="UPC / EAN / ISBN" value="' + esc(v.gtin) + '">') +
					frow('MPN',      '<input type="text" id="wc-f-mpn" style="width:200px;" value="' + esc(v.mpn) + '">') +
					frow('Condition','<select id="wc-f-condition">' +
						fopt('https://schema.org/NewCondition',         'New',         v.itemCondition) +
						fopt('https://schema.org/UsedCondition',        'Used',        v.itemCondition) +
						fopt('https://schema.org/RefurbishedCondition', 'Refurbished', v.itemCondition) +
						fopt('https://schema.org/DamagedCondition',     'Damaged',     v.itemCondition) +
					'</select>') +
					frow('Custom properties',
						'<div id="wc-f-additional"></div>' +
						'<button type="button" class="button button-small" id="wc-add-prop" style="margin-top:6px;">+ Add property</button> ' +
						'<button type="button" class="button button-small" id="wc-ai-standardize" style="margin-top:6px;">&#10024; Standardize (AI)</button>' +
						'<span id="wc-ai-standardize-status" style="margin-left:8px;font-size:12px;color:#6b7280;"></span>' +
						'<p class="description" style="margin:4px 0 0;">Water Resistance: 50m, Style: Mid-Century, etc. “Standardize” maps these to schema fields where possible.</p>') +
					'</tbody></table>';

				// Relationships.
				var relSummary = '';
				if (rel.is_variant_of && rel.is_variant_of.hasVariant) {
					relSummary += '<p style="margin:0 0 8px;"><span class="dashicons dashicons-screenoptions" style="vertical-align:middle;color:#6b7280;"></span> ' +
						'<strong>Variations:</strong> ' + rel.is_variant_of.hasVariant.length +
						' variant(s) auto-included as a ProductGroup.</p>';
				}
				if (rel.related && rel.related.length) {
					relSummary += '<p style="margin:0 0 8px;"><span class="dashicons dashicons-randomize" style="vertical-align:middle;color:#6b7280;"></span> ' +
						'<strong>Related:</strong> ' + rel.related.length + ' product(s) auto-included from cross-sells.</p>';
				}
				if (!relSummary) {
					relSummary = '<p style="margin:0 0 8px;color:#6b7280;">No variations or cross-sells detected.</p>';
				}
				var relManual =
					pickerHtml('similar', 'Similar products (isSimilarTo)') +
					pickerHtml('accessory', 'Accessory / spare part for (isAccessoryOrSparePartFor)');

				// Reviews.
				var revHtml;
				if (rev.count || (rev.aggregate && rev.aggregate.ratingValue)) {
					var summary = [];
					if (rev.aggregate && rev.aggregate.ratingValue) {
						summary.push('aggregate rating ' + esc(rev.aggregate.ratingValue) + '/5 (' + (rev.aggregate.reviewCount || 0) + ')');
					}
					if (rev.count) { summary.push(rev.count + ' individual review(s)'); }
					revHtml = '<label><input type="checkbox" id="wc-f-include-reviews" checked> Include ' + summary.join(' + ') + '</label>';
				} else {
					revHtml = '<p style="margin:0;color:#6b7280;">No reviews found for this product.</p>' +
						'<input type="hidden" id="wc-f-include-reviews" value="0">';
				}

				// Image alt text.
				var img = (d && d.image_alt) || { total: 0, missing: 0 };
				var altHtml;
				if (!img.total) {
					altHtml = '<p style="margin:0;color:#6b7280;">No images on this product.</p>';
				} else {
					altHtml = '<button type="button" class="button button-small" id="wc-ai-alt"' + (img.missing ? '' : ' disabled') + '>&#10024; Fill missing alt text (AI)</button>' +
						'<span id="wc-ai-alt-status" style="margin-left:8px;font-size:12px;color:#6b7280;">' +
						(img.missing ? (img.missing + ' of ' + img.total + ' image(s) missing alt text') : ('All ' + img.total + ' image(s) have alt text')) +
						'</span>' +
						'<p class="description" style="margin:6px 0 0;">Uses image vision to write alt text directly to images — only fills empty ones.</p>';
				}

				var logisticsNote = '<p style="margin:0;color:#6b7280;font-size:13px;">' +
					'Shipping &amp; return policy are applied from your ' +
					'<a href="#" id="wc-open-logistics">site-wide defaults</a>.</p>';

				// Competitive gaps (advisory).
				var compHtml =
					'<button type="button" class="button button-small" id="wc-ai-competitive">&#10024; Analyze competitors (AI)</button>' +
					'<span id="wc-ai-competitive-status" style="margin-left:8px;font-size:12px;color:#6b7280;"></span>' +
					'<div id="wc-competitive-list" style="margin-top:6px;"></div>' +
					'<p class="description" style="margin:4px 0 0;">Flags attributes similar products commonly list that yours is missing.</p>';

				// Google Product Category → schema.org CategoryCode.
				var gcat    = (d && d.ai_google_category) || null;
				var gcatMsg = gcat ? ('Current: ' + esc(gcat.path || gcat.code) + ' (#' + esc(gcat.code) + ')') : '';
				var gcatHtml =
					'<button type="button" class="button button-small" id="wc-ai-gcat">&#10024; Resolve Google category (AI)</button>' +
					'<span id="wc-ai-gcat-status" style="margin-left:8px;font-size:12px;color:' + (gcat ? '#16a34a' : '#6b7280') + ';">' + gcatMsg + '</span>' +
					'<p class="description" style="margin:4px 0 0;">Maps this product to Google&rsquo;s official Product Taxonomy and adds it to your schema as a <code>CategoryCode</code> — so Google Shopping and AI shopping agents read the same category. Saved automatically.</p>';

				return core +
					detailsSection('Identifiers &amp; Attributes', attrs, true) +
					detailsSection('Relationships', relSummary + relManual, false) +
					detailsSection('Reviews', revHtml, false) +
					detailsSection('Competitive Gaps', compHtml, false) +
					detailsSection('Google Product Category', gcatHtml, false) +
					detailsSection('Image Alt Text', altHtml, true) +
					detailsSection('Shipping &amp; Returns', logisticsNote, false);
			}

			function detailsSection(title, inner, open) {
				return '<details ' + (open ? 'open' : '') + ' style="margin-top:10px;border:1px solid #e5e7eb;border-radius:6px;">' +
					'<summary style="cursor:pointer;padding:10px 14px;font-weight:600;list-style:none;">' + title + '</summary>' +
					'<div style="padding:4px 14px 14px;">' + inner + '</div></details>';
			}

			function pickerHtml(key, label) {
				return '<div style="margin-top:10px;">' +
					'<label style="display:block;font-weight:600;margin-bottom:4px;">' + esc(label) + '</label>' +
					'<input type="text" class="wc-picker-search regular-text" data-key="' + key + '" placeholder="Search products…" autocomplete="off">' +
					'<div class="wc-picker-results" data-key="' + key + '" style="display:none;position:relative;"></div>' +
					'<div class="wc-picker-chips" id="wc-' + key + '-chips" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;"></div>' +
					'</div>';
			}

			// ── Product form wiring ───────────────────────────────────────
			function initProductForm(v, d) {
				// Custom property rows.
				var rows = v.additional && v.additional.length ? v.additional : [{ name: '', value: '' }];
				rows.forEach(function(r){ addPropRow(r.name, r.value); });

				$('#wc-add-prop').on('click', function(){ addPropRow('', ''); });
				$('#wc-f-additional').on('click', '.wc-prop-remove', function(){ $(this).closest('.wc-prop-row').remove(); });

				$('#wc-open-logistics').on('click', function(e){
					e.preventDefault();
					$dlg.dialog('close');
					var $log = $('#twt-aeo-wc-logistics');
					if ($log.length) { $log.prop('open', true); $('html,body').animate({ scrollTop: $log.offset().top - 40 }, 300); }
				});

				// Pre-fill existing manual picks.
				var rel = (d && d.relationships) || {};
				(rel.similar   || []).forEach(function(it){ addChip('similar', it); });
				(rel.accessory || []).forEach(function(it){ addChip('accessory', it); });

				// AI attribute extraction.
				if (d && d.ai_attributes && d.ai_attributes.provider) {
					$('#wc-ai-extract-status').text('Last analyzed via ' + d.ai_attributes.provider);
				}
				$('#wc-ai-extract').on('click', doAiExtract);
				$('#wc-ai-vision').on('click', doAiVision);
				$('#wc-ai-describe').on('click', doAiDescribe);
				$('#wc-ai-sameas').on('click', doAiSameas);
				$('#wc-ai-standardize').on('click', doAiStandardize);
				$('#wc-ai-competitive').on('click', doAiCompetitive);
				$('#wc-ai-gcat').on('click', doAiGoogleCategory);
				$('#wc-ai-alt').on('click', doAiAlt);

				// Pre-fill saved brand authority links.
				(d && d.brand_sameas || []).forEach(function(u){ addSameasRow(u, true); });

				bindPickers();
			}

			function addSameasRow(url, checked) {
				if (!url) return;
				var $list = $('#wc-sameas-list');
				var exists = false;
				$list.find('input.wc-sameas-cb').each(function(){ if ($(this).val() === url) exists = true; });
				if (exists) return;
				$list.append(
					'<label style="display:block;font-size:12px;margin:2px 0;">' +
						'<input type="checkbox" class="wc-sameas-cb" value="' + esc(url) + '"' + (checked ? ' checked' : '') + '> ' +
						esc(url) +
					'</label>'
				);
			}

			function doAiSameas() {
				var brand = $('#wc-f-brand').val().trim();
				var $st   = $('#wc-sameas-status');
				if (!brand) { $st.css('color', '#d63638').text('Enter a brand name first.'); return; }
				var $btn = $('#wc-ai-sameas').prop('disabled', true);
				$st.css('color', '#6b7280').text('Searching the web…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_sameas', nonce: wcNonce, post_id: dlgPostId, brand: brand }, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Lookup failed.'); return; }
					var urls = resp.data.urls || [];
					if (!urls.length) { $st.css('color', '#6b7280').text('No authority links found for that brand.'); return; }
					urls.forEach(function(u){ addSameasRow(u, true); });
					$st.css('color', '#16a34a').text('Found ' + urls.length + ' link(s) via ' + (resp.data.provider || 'AI') + ' — uncheck any to exclude.');
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiStandardize() {
				var rows = [];
				$('#wc-f-additional .wc-prop-row').each(function(){
					var n = $(this).find('.wc-prop-name').val(), v = $(this).find('.wc-prop-value').val();
					if (n && v) rows.push({ name: n, value: v });
				});
				var $st = $('#wc-ai-standardize-status');
				if (!rows.length) { $st.css('color', '#d63638').text('Add some custom properties first.'); return; }
				var data = { action: 'twtaeo_wc_ai_standardize', nonce: wcNonce };
				rows.forEach(function(r, i){ data['additional[' + i + '][name]'] = r.name; data['additional[' + i + '][value]'] = r.value; });
				var $btn = $('#wc-ai-standardize').prop('disabled', true);
				$st.css('color', '#6b7280').text('Standardizing…');
				$.post(ajaxurl, data, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Failed.'); return; }
					var d = resp.data.data || {};
					var moved = 0;
					moved += fillIfEmpty('#wc-f-brand', d.brand);
					moved += fillIfEmpty('#wc-f-color', d.color);
					moved += fillIfEmpty('#wc-f-material', d.material);
					moved += fillIfEmpty('#wc-f-size', d.size);
					moved += fillIfEmpty('#wc-f-gtin', d.gtin);
					moved += fillIfEmpty('#wc-f-mpn', d.mpn);
					$('#wc-f-additional').empty();
					(d.additional || []).forEach(function(r){ addPropRow(r.name, r.value); });
					$st.css('color', '#16a34a').text('Standardized — ' + moved + ' promoted to schema field(s).');
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiGoogleCategory() {
				var $btn = $('#wc-ai-gcat').prop('disabled', true);
				var $st  = $('#wc-ai-gcat-status').css('color', '#6b7280').text('Matching Google’s taxonomy…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_google_category', nonce: wcNonce, post_id: dlgPostId }, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Lookup failed.'); return; }
					var c = resp.data.category || {};
					$st.css('color', '#16a34a').text('Matched: ' + (c.path || c.code) + ' (#' + c.code + ') via ' + (resp.data.provider || 'AI') + ' — saved to schema.');
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiCompetitive() {
				var $btn = $('#wc-ai-competitive').prop('disabled', true);
				var $st  = $('#wc-ai-competitive-status').css('color', '#6b7280').text('Researching competitors…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_competitive', nonce: wcNonce, post_id: dlgPostId }, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Failed.'); return; }
					var recs = resp.data.recommended || [];
					var $list = $('#wc-competitive-list').empty();
					if (!recs.length) { $st.css('color', '#16a34a').text('No obvious gaps found.'); return; }
					recs.forEach(function(r){
						$list.append('<div style="margin:3px 0;font-size:12px;">' +
							'<a href="#" class="wc-comp-add" data-field="' + esc(r.field) + '">+ ' + esc(r.field) + '</a>' +
							(r.reason ? ' <span style="color:#6b7280;">— ' + esc(r.reason) + '</span>' : '') +
						'</div>');
					});
					$st.css('color', '#16a34a').text('Found ' + recs.length + ' gap(s) via ' + (resp.data.provider || 'AI') + ' — click + to add as a property.');
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiDescribe() {
				var $btn = $('#wc-ai-describe').prop('disabled', true);
				var $st  = $('#wc-ai-describe-status').css('color', '#6b7280').text('Enhancing…');
				$.post(ajaxurl, {
					action: 'twtaeo_wc_ai_describe', nonce: wcNonce, post_id: dlgPostId,
					current: $('#wc-f-desc').val()
				}, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Enhancement failed.'); return; }
					$('#wc-f-desc').val(resp.data.text);
					$st.css('color', '#16a34a').text('Rewritten via ' + (resp.data.provider || 'AI') + ' — review before saving.');
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiVision() {
				var $btn = $('#wc-ai-vision').prop('disabled', true);
				var $st  = $('#wc-ai-extract-status').css('color', '#6b7280').text('Analyzing image…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_vision', nonce: wcNonce, post_id: dlgPostId }, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Image analysis failed.'); return; }
					var n = applyAiAttributes(resp.data.data || {});
					$st.css('color', '#16a34a').text('Filled ' + n + ' field(s) from image via ' + (resp.data.provider || 'AI') + ' · ' + (resp.data.time_diff || 'just now'));
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiAlt() {
				var $btn = $('#wc-ai-alt').prop('disabled', true);
				var $st  = $('#wc-ai-alt-status').css('color', '#6b7280').text('Generating alt text…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_alt', nonce: wcNonce, post_id: dlgPostId }, function(resp){
					if (!resp.success) { $btn.prop('disabled', false); $st.css('color', '#d63638').text(resp.data || 'Failed.'); return; }
					var d = resp.data;
					$st.css('color', '#16a34a').text('Filled ' + d.filled + ' image(s)' + (d.skipped ? ', ' + d.skipped + ' already had alt' : '') + '.');
					if (d.alt_html) {
						$('tr[data-post-id="' + dlgPostId + '"] .twt-aeo-wc-alt-cell').html(d.alt_html);
					}
					// Button stays disabled — no images left missing alt.
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			function doAiExtract() {
				var $btn = $('#wc-ai-extract').prop('disabled', true);
				var $st  = $('#wc-ai-extract-status').css('color', '#6b7280').text('Analyzing…');
				$.post(ajaxurl, { action: 'twtaeo_wc_ai_extract', nonce: wcNonce, post_id: dlgPostId }, function(resp){
					$btn.prop('disabled', false);
					if (!resp.success) { $st.css('color', '#d63638').text(resp.data || 'Extraction failed.'); return; }
					var n = applyAiAttributes(resp.data.data || {});
					$st.css('color', '#16a34a').text('Filled ' + n + ' field(s) via ' + (resp.data.provider || 'AI') + ' · ' + (resp.data.time_diff || 'just now'));
				}).fail(function(){ $btn.prop('disabled', false); $st.css('color', '#d63638').text('Request failed.'); });
			}

			// Non-destructive: only fill empty fields; never overwrite merchant input.
			function applyAiAttributes(data) {
				var filled = 0;
				filled += fillIfEmpty('#wc-f-color', data.color);
				filled += fillIfEmpty('#wc-f-material', data.material);
				filled += fillIfEmpty('#wc-f-size', data.size);

				var existing = {};
				$('#wc-f-additional .wc-prop-name').each(function(){
					var nm = $(this).val().trim().toLowerCase();
					if (nm) existing[nm] = true;
				});
				(data.additional || []).forEach(function(row){
					if (!row || !row.name || !row.value) return;
					var key = String(row.name).trim().toLowerCase();
					if (existing[key]) return;
					addPropRow(row.name, row.value);
					existing[key] = true;
					filled++;
				});
				return filled;
			}

			function fillIfEmpty(sel, val) {
				if (!val) return 0;
				var $f = $(sel);
				if (!$f.length || $f.val().trim()) return 0;
				$f.val(val);
				if (!$f.next('.wc-ai-badge').length) {
					$f.after('<span class="wc-ai-badge" style="margin-left:6px;font-size:10px;color:#7c3aed;background:#f3e8ff;border-radius:8px;padding:1px 6px;vertical-align:middle;">AI</span>');
				}
				return 1;
			}

			function addPropRow(name, value) {
				$('#wc-f-additional').append(
					'<div class="wc-prop-row" style="display:flex;gap:6px;margin-bottom:4px;">' +
						'<input type="text" class="wc-prop-name" placeholder="Name" style="flex:1;" value="' + esc(name) + '">' +
						'<input type="text" class="wc-prop-value" placeholder="Value" style="flex:1;" value="' + esc(value) + '">' +
						'<button type="button" class="button button-small wc-prop-remove" title="Remove">&times;</button>' +
					'</div>'
				);
			}

			function addChip(key, item) {
				var $chips = $('#wc-' + key + '-chips');
				if ($chips.find('.wc-chip[data-id="' + item.id + '"]').length) return;
				$chips.append(
					'<span class="wc-chip" data-id="' + esc(item.id) + '" ' +
						'style="display:inline-flex;align-items:center;gap:4px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:12px;padding:2px 8px;font-size:12px;">' +
						esc(item.text) +
						' <a href="#" class="wc-chip-remove" style="text-decoration:none;color:#787c82;">&times;</a></span>'
				);
			}

			var pickersBound = false;
			function bindPickers() {
				if (pickersBound) return; // delegated on the persistent form — bind once.
				pickersBound = true;
				var timer = null;
				$('#twt-aeo-wc-dialog-form').on('keyup', '.wc-picker-search', function(){
					var $input   = $(this);
					var key      = $input.data('key');
					var term     = $input.val().trim();
					var $results = $('.wc-picker-results[data-key="' + key + '"]');
					clearTimeout(timer);
					if (term.length < 2) { $results.hide().empty(); return; }
					timer = setTimeout(function(){
						$.post(ajaxurl, { action: 'twtaeo_wc_search_products', nonce: wcNonce, term: term }, function(resp){
							if (!resp.success || !resp.data.length) { $results.hide().empty(); return; }
							var html = '<div style="border:1px solid #dcdcde;border-radius:4px;max-height:180px;overflow:auto;background:#fff;position:absolute;z-index:10;width:100%;box-shadow:0 2px 6px rgba(0,0,0,.1);">';
							resp.data.forEach(function(it){
								html += '<a href="#" class="wc-picker-pick" data-id="' + esc(it.id) + '" data-text="' + esc(it.text) + '" ' +
									'style="display:block;padding:6px 10px;text-decoration:none;color:#1d2327;border-bottom:1px solid #f0f0f1;">' + esc(it.text) + '</a>';
							});
							html += '</div>';
							$results.html(html).show();
						});
					}, 300);
				});

				$('#twt-aeo-wc-dialog-form').on('click', '.wc-picker-pick', function(e){
					e.preventDefault();
					var key      = $(this).closest('.wc-picker-results').data('key');
					addChip(key, { id: $(this).data('id'), text: $(this).data('text') });
					$('.wc-picker-results[data-key="' + key + '"]').hide().empty();
					$('.wc-picker-search[data-key="' + key + '"]').val('');
				});

				$('#twt-aeo-wc-dialog-form').on('click', '.wc-chip-remove', function(e){
					e.preventDefault();
					$(this).closest('.wc-chip').remove();
				});

				// Competitive "gap" → add as an empty custom property row.
				$('#twt-aeo-wc-dialog-form').on('click', '.wc-comp-add', function(e){
					e.preventDefault();
					addPropRow($(this).data('field'), '');
					$(this).css({ color: '#6b7280', 'text-decoration': 'none', 'pointer-events': 'none' });
				});
			}

			function serviceFormHtml(v) {
				return '<table class="form-table"><tbody>' +
					frow('Name',         '<input type="text" id="wc-f-name" class="large-text" value="' + esc(v.name) + '">') +
					frow('Description',  '<textarea id="wc-f-desc" rows="3" class="large-text">' + esc(v.description) + '</textarea>') +
					frow('Service Type', '<input type="text" id="wc-f-service-type" class="large-text" placeholder="e.g. Lawn Care, IT Support" value="' + esc(v.serviceType) + '">') +
					frow('Area Served',  '<input type="text" id="wc-f-area" class="large-text" placeholder="e.g. Tampa, FL" value="' + esc(v.areaServed) + '">') +
					frow('Image URL',    '<input type="url" id="wc-f-image" class="large-text" value="' + esc(v.image) + '">') +
					'</tbody></table>';
			}

			function frow(label, ctrl) {
				return '<tr><th scope="row">' + label + '</th><td>' + ctrl + '</td></tr>';
			}
			function fopt(val, label, current) {
				return '<option value="' + esc(val) + '"' + (val === current ? ' selected' : '') + '>' + esc(label) + '</option>';
			}

			function collectSchema(type, d) {
				var name = $('#wc-f-name').val().trim();
				if (!name) return null;

				if (type === 'Service') {
					var schema = { '@context': 'https://schema.org', '@type': 'Service', 'name': name, 'url': d.url, 'provider': { '@type': 'Organization', name: d.site_name, url: d.site_url } };
					var desc = $('#wc-f-desc').val().trim();
					var st   = $('#wc-f-service-type').val().trim();
					var area = $('#wc-f-area').val().trim();
					var img  = $('#wc-f-image').val().trim();
					if (desc) schema.description = desc;
					if (st)   schema.serviceType = st;
					if (area) schema.areaServed = area;
					if (img)  schema.image = img;
					return schema;
				}

				return null;
			}

			function doSave() {
				if (dlgType === 'Product') { doSaveProduct(); return; }

				var schema = collectSchema(dlgType, dlgProdData);
				if (!schema) { showMsg('Name is required.', 'error'); return; }
				var $btn = $('#twt-aeo-wc-dlg-save').prop('disabled', true).text('Saving…');
				$.post(ajaxurl, {
					action: 'twtaeo_save_custom_schema', nonce: schemaNonce,
					post_id: dlgPostId, schema_type: dlgType, json: JSON.stringify(schema, null, 2),
				}, function(resp) {
					$btn.prop('disabled', false).text('Save Schema');
					if (resp.success) {
						showMsg('Schema saved.', 'success');
						updateRow(dlgPostId, dlgType, true);
						setTimeout(function() { $dlg.dialog('close'); }, 800);
					} else { showMsg(resp.data || 'Save failed.', 'error'); }
				}).fail(function() { $btn.prop('disabled', false).text('Save Schema'); showMsg('Request failed.', 'error'); });
			}

			// Product save: PHP assembles the rich schema from these field values.
			function doSaveProduct() {
				var name = $('#wc-f-name').val().trim();
				if (!name) { showMsg('Name is required.', 'error'); return; }

				var data = {
					action:         'twtaeo_wc_save_schema',
					nonce:          wcNonce,
					post_id:        dlgPostId,
					name:           name,
					description:    $('#wc-f-desc').val(),
					price:          $('#wc-f-price').val().trim(),
					currency:       $('#wc-f-currency').val().trim(),
					sku:            $('#wc-f-sku').val().trim(),
					availability:   $('#wc-f-avail').val(),
					image:          $('#wc-f-image').val().trim(),
					brand:          $('#wc-f-brand').val().trim(),
					color:          $('#wc-f-color').val().trim(),
					material:       $('#wc-f-material').val().trim(),
					size:           $('#wc-f-size').val().trim(),
					gtin:           $('#wc-f-gtin').val().trim(),
					mpn:            $('#wc-f-mpn').val().trim(),
					item_condition: $('#wc-f-condition').val(),
					update_product_desc: $('#wc-f-update-product-desc').is(':checked') ? 1 : 0,
					include_reviews: $('#wc-f-include-reviews').is(':checkbox')
						? ($('#wc-f-include-reviews').is(':checked') ? 1 : 0)
						: ($('#wc-f-include-reviews').val() || 0)
				};

				$('#wc-f-additional .wc-prop-row').each(function(i){
					data['additional[' + i + '][name]']  = $(this).find('.wc-prop-name').val();
					data['additional[' + i + '][value]'] = $(this).find('.wc-prop-value').val();
				});
				$('#wc-similar-chips .wc-chip').each(function(i){
					data['is_similar_to[' + i + ']'] = $(this).data('id');
				});
				$('#wc-accessory-chips .wc-chip').each(function(i){
					data['is_accessory_for[' + i + ']'] = $(this).data('id');
				});
				$('#wc-sameas-list input.wc-sameas-cb:checked').each(function(i){
					data['same_as[' + i + ']'] = $(this).val();
				});

				var $btn = $('#twt-aeo-wc-dlg-save').prop('disabled', true).text('Saving…');
				$.post(ajaxurl, data, function(resp) {
					$btn.prop('disabled', false).text('Save Schema');
					if (resp.success) {
						showMsg('Schema saved.', 'success');
						updateRow(dlgPostId, 'Product', true);
						if (resp.data && resp.data.optimization_html) {
							$('tr[data-post-id="' + dlgPostId + '"] .twt-aeo-wc-opt-cell').html(resp.data.optimization_html);
						}
						setTimeout(function() { $dlg.dialog('close'); }, 800);
					} else { showMsg(resp.data || 'Save failed.', 'error'); }
				}).fail(function() { $btn.prop('disabled', false).text('Save Schema'); showMsg('Request failed.', 'error'); });
			}

			function doDelete() {
				if (!confirm('Delete this schema? This cannot be undone.')) return;
				$.post(ajaxurl, {
					action: 'twtaeo_delete_custom_schema', nonce: schemaNonce,
					post_id: dlgPostId, schema_type: dlgType,
				}, function(resp) {
					if (resp.success) {
						updateRow(dlgPostId, dlgType, false);
						if (dlgType === 'Product') {
							$('tr[data-post-id="' + dlgPostId + '"] .twt-aeo-wc-opt-cell')
								.html('<span class="twt-aeo-badge" style="background:rgba(156,163,175,.12);color:#6b7280;">Not set</span>');
						}
						$dlg.dialog('close');
					}
					else showMsg(resp.data || 'Delete failed.', 'error');
				});
			}

			$('#twt-aeo-wc-gen-all').on('click', function() {
				var $btn = $(this).prop('disabled', true);
				var $msg = $('#twt-aeo-wc-gen-all-result').hide().removeClass('notice-success notice-error');
				var tally = { saved: 0, skipped: 0, errors: [] };

				function finish(ok, html) {
					$btn.prop('disabled', false).text('Generate Schema for All');
					$msg.addClass(ok ? 'notice-success' : 'notice-error').html('<p>' + html + '</p>').show();
				}

				// Batched: one GENERATE_BATCH page of the catalogue per request,
				// cursor-driven until the server reports an empty page — so the
				// whole catalogue is covered, not just the first 200.
				function runBatch(offset) {
					$.post(ajaxurl, {
						action: 'twtaeo_wc_generate_all',
						nonce:  wcNonce,
						offset: offset
					}, function(resp) {
						if (!resp.success) {
							finish(false, esc(resp.data || 'Generation failed.'));
							return;
						}
						var d = resp.data;
						tally.saved   += d.saved;
						tally.skipped += d.skipped;
						if (d.errors && d.errors.length) tally.errors = tally.errors.concat(d.errors);

						if (!d.done) {
							$btn.text('Generating… ' + d.next + ' of ' + d.total);
							runBatch(d.next);
							return;
						}

						var text = tally.saved + ' schema' + (tally.saved !== 1 ? 's' : '') + ' generated';
						if (tally.skipped) text += ', ' + tally.skipped + ' already had schema';
						text += ' across ' + d.total + ' product' + (d.total !== 1 ? 's' : '');
						if (tally.errors.length) {
							text += '. Could not generate for: ' + esc(tally.errors.join(', ')) + ' — open each product to see what is missing.';
						}
						finish(true, text + '. <a href="">Reload to see updated statuses.</a>');
					}).fail(function() {
						// Report what actually completed. Claiming nothing happened
						// would be as wrong as the silent truncation this replaced.
						finish(false, 'Request failed after ' + tally.saved + ' generated. Press again to resume.');
					});
				}

				$btn.text('Generating…');
				runBatch(0);
			});

			// Bulk AI alt text — sequential, one billed call per product, stoppable.
			var altBulkStopped = false;
			$('#twt-aeo-wc-alt-all').on('click', function() {
				var $btn    = $(this);
				var $stop   = $('#twt-aeo-wc-alt-all-stop');
				var $status = $('#twt-aeo-wc-alt-all-status');

				var ids = [], imgCount = 0;
				$('tr.twt-aeo-page-row').each(function() {
					var m = parseInt($(this).attr('data-alt-missing'), 10) || 0;
					if ( m > 0 ) {
						ids.push( $(this).data('post-id') );
						imgCount += m;
					}
				});

				if ( ! ids.length ) {
					$status.css('color', '#16a34a').text('<?php echo esc_js( __( 'All product images already have alt text.', 'twt-aeo-ultimate' ) ); ?>');
					return;
				}

				var confirmMsg = '<?php
					/* translators: %1$d: number of product images missing alt text. %2$d: number of products they belong to. */
					echo esc_js( __( 'This will run AI vision over %1$d image(s) across %2$d product(s) — one billed AI call per image. Continue?', 'twt-aeo-ultimate' ) );
				?>'
					.replace('%1$d', imgCount).replace('%2$d', ids.length);
				if ( ! window.confirm(confirmMsg) ) { return; }

				altBulkStopped = false;
				$btn.prop('disabled', true);
				$stop.show();
				var done = 0, filled = 0, failed = 0, total = ids.length;

				function finish(stopped) {
					$btn.prop('disabled', false);
					$stop.hide();
					$status.css('color', failed ? '#d63638' : '#16a34a').text(
						( stopped ? '<?php echo esc_js( __( 'Stopped', 'twt-aeo-ultimate' ) ); ?>' : '<?php echo esc_js( __( 'Done', 'twt-aeo-ultimate' ) ); ?>' ) +
						' — ' + filled + ' <?php echo esc_js( __( 'image(s) filled across', 'twt-aeo-ultimate' ) ); ?> ' + done + ' <?php echo esc_js( __( 'product(s)', 'twt-aeo-ultimate' ) ); ?>' +
						( failed ? ', ' + failed + ' <?php echo esc_js( __( 'failed', 'twt-aeo-ultimate' ) ); ?>' : '' )
					);
				}

				function processNext() {
					if ( altBulkStopped ) { finish(true); return; }
					if ( ! ids.length ) { finish(false); return; }

					var postId = ids.shift();
					var $row   = $('tr[data-post-id="' + postId + '"]');
					$status.css('color', '#646970').text(
						'<?php echo esc_js( __( 'Processing product', 'twt-aeo-ultimate' ) ); ?> ' + ( total - ids.length ) + ' / ' + total + '…'
					);

					$.post(ajaxurl, { action: 'twtaeo_wc_ai_alt', nonce: wcNonce, post_id: postId }, function(resp) {
						if ( resp.success ) {
							done++;
							filled += parseInt(resp.data.filled, 10) || 0;
							if ( resp.data.alt_html ) { $row.find('.twt-aeo-wc-alt-cell').html(resp.data.alt_html); }
							$row.attr('data-alt-missing', '0');
						} else {
							failed++;
						}
						processNext();
					}).fail(function() {
						failed++;
						processNext();
					});
				}

				processNext();
			});

			$('#twt-aeo-wc-alt-all-stop').on('click', function() {
				altBulkStopped = true;
			});

			function showMsg(text, type) {
				var $msg = $('#twt-aeo-wc-dialog-msg');
				var isOk = type === 'success';
				$msg.removeClass('notice-success notice-error')
					.css({ background: isOk ? '#edfaef' : '#fce8e8', 'border-left': '3px solid ' + (isOk ? '#16a34a' : '#d63638') })
					.text(text).show();
			}

			// Site-wide shipping & returns defaults.
			$('#twt-aeo-wc-logistics-form').on('submit', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $msg  = $('#twt-aeo-wc-logistics-msg');
				var $btn  = $form.find('[type=submit]').prop('disabled', true).text('Saving…');
				var data  = { action: 'twtaeo_wc_save_shipping_settings', nonce: wcNonce };
				data.ship_enabled      = $form.find('[name=ship_enabled]').is(':checked') ? 1 : 0;
				data.ship_country      = $form.find('[name=ship_country]').val();
				data.ship_rate_type    = $form.find('[name=ship_rate_type]').val();
				data.ship_rate         = $form.find('[name=ship_rate]').val();
				data.ship_currency     = $form.find('[name=ship_currency]').val();
				data.ship_handling_min = $form.find('[name=ship_handling_min]').val();
				data.ship_handling_max = $form.find('[name=ship_handling_max]').val();
				data.ship_transit_min  = $form.find('[name=ship_transit_min]').val();
				data.ship_transit_max  = $form.find('[name=ship_transit_max]').val();
				data.ret_enabled       = $form.find('[name=ret_enabled]').is(':checked') ? 1 : 0;
				data.ret_country       = $form.find('[name=ret_country]').val();
				data.ret_days          = $form.find('[name=ret_days]').val();
				data.ret_fees          = $form.find('[name=ret_fees]').val();
				data.ret_method        = $form.find('[name=ret_method]').val();
				$.post(ajaxurl, data, function(resp) {
					$btn.prop('disabled', false).text('Save Defaults');
					if (resp.success) {
						$msg.css('color', '#16a34a').text('Defaults saved.').show();
						setTimeout(function() { $msg.hide(); }, 3000);
					} else {
						$msg.css('color', '#d63638').text(resp.data || 'Save failed.').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Save Defaults');
					$msg.css('color', '#d63638').text('Request failed.').show();
				});
			});

			function updateRow(postId, type, hasSaved) {
				var $row = $('tr[data-post-id="' + postId + '"]');
				var $btn = $row.find('.twt-aeo-wc-schema-btn');
				if (hasSaved) {
					$btn.text('Edit Schema').removeClass('button-primary').addClass('button-secondary').data('has-custom', '1');
					$row.find('.twt-aeo-wc-schema-cell').html('<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">' + esc(type) + ' ✓</span>');
					$row.removeClass('twt-aeo-page-row--issues').addClass('twt-aeo-page-row--ok');
				} else {
					$btn.text('Generate Schema').removeClass('button-secondary').addClass('button-primary').data('has-custom', '0');
					$row.find('.twt-aeo-wc-schema-cell').html('<span class="twt-aeo-badge twt-aeo-badge--warn">Add ' + esc(type) + '</span>');
					$row.removeClass('twt-aeo-page-row--ok').addClass('twt-aeo-page-row--issues');
				}
			}

			function esc(str) {
				return String(str == null ? '' : str)
					.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

		}); // document.ready
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	// ── Merchant Center Tab ──────────────────────────────────────────────────

	private static function render_gmc_tab() {
		$is_connected   = TWTAEO_Google_Merchant_Center::is_connected();
		$creds          = TWTAEO_Google_Merchant_Center::get_credentials();
		$merchant_id    = TWTAEO_Google_Merchant_Center::get_merchant_id();
		$last_sync      = get_option( TWTAEO_GMC_Sync_Engine::OPTION_LAST, 0 );
		$gmc_nonce      = wp_create_nonce( self::NONCE_GMC );
		$redirect_uri   = TWTAEO_Google_Merchant_Center::get_redirect_uri();

		// One catalog pass shared by the score, the table, and the rejection log.
		$rows       = TWTAEO_GMC_Sync_Engine::get_product_snapshots();
		$score_data = TWTAEO_GMC_Sync_Engine::get_integrity_score( $rows );
		$rejections = TWTAEO_GMC_Sync_Engine::get_rejection_log( $rows );

		$score      = $score_data['score'];
		$label      = $score_data['label'];
		$counts     = $score_data['counts'];
		$has_data   = $score_data['synced'] ?? false;

		if ( $score >= 90 )      $score_color = '#16a34a';
		elseif ( $score >= 70 )  $score_color = '#0ea5e9';
		elseif ( $score >= 50 )  $score_color = '#f59e0b';
		else                     $score_color = '#d63638';
		?>

		<!-- ── Integrity Score ─────────────────────────────────────────── -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-card" style="padding:24px;">
				<div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;">

					<!-- Score Ring -->
					<div style="flex-shrink:0;text-align:center;">
						<svg width="100" height="100" viewBox="0 0 100 100" style="transform:rotate(-90deg);">
							<circle cx="50" cy="50" r="42" fill="none" stroke="#e5e7eb" stroke-width="10"/>
							<circle cx="50" cy="50" r="42" fill="none"
								stroke="<?php echo esc_attr( $score_color ); ?>"
								stroke-width="10"
								stroke-dasharray="<?php echo esc_attr( round( 2 * M_PI * 42, 1 ) ); ?>"
								stroke-dashoffset="<?php echo esc_attr( round( ( 1 - $score / 100 ) * 2 * M_PI * 42, 1 ) ); ?>"
								stroke-linecap="round"/>
						</svg>
						<div style="margin-top:-68px;font-size:26px;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>;"><?php echo esc_html( $has_data ? $score : '—' ); ?></div>
						<div style="margin-top:42px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;"><?php echo esc_html( $has_data ? $label : 'Not Synced' ); ?></div>
					</div>

					<!-- Score label + counts -->
					<div style="flex:1;min-width:220px;">
						<h2 style="margin:0 0 4px;font-size:18px;">E-Commerce Integrity Score</h2>
						<p style="margin:0 0 16px;color:#6b7280;font-size:13px;">
							<?php esc_html_e( 'Measures alignment between your WooCommerce catalog and Google Merchant Center feed data.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php if ( $has_data ) : ?>
						<div style="display:flex;gap:16px;flex-wrap:wrap;">
							<?php self::score_pill( $counts['matched'] ?? 0,        'Matched',        '#16a34a' ); ?>
							<?php self::score_pill( $counts['unmatched'] ?? 0,       'Unmatched',      '#6b7280' ); ?>
							<?php self::score_pill( $counts['rejected'] ?? 0,        'Rejected',       '#d63638' ); ?>
							<?php self::score_pill( $counts['price_mismatch'] ?? 0,  'Price Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['avail_mismatch'] ?? 0,  'Avail Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['category_mismatch'] ?? 0, 'Category Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['schema_issues'] ?? 0,   'Schema Issues',  '#6366f1' ); ?>
						</div>
						<?php else : ?>
						<p style="color:#6b7280;font-size:13px;font-style:italic;"><?php esc_html_e( 'Run a sync to calculate your score.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</div>

					<!-- Sync button -->
					<div style="flex-shrink:0;text-align:right;">
						<?php if ( $last_sync ) : ?>
						<p style="margin:0 0 8px;font-size:12px;color:#6b7280;">
							<?php
							// translators: %s: human-readable time difference since last sync.
							printf( esc_html__( 'Last synced %s', 'twt-aeo-ultimate' ), esc_html( human_time_diff( $last_sync ) . ' ago' ) ); ?>
						</p>
						<?php endif; ?>
						<?php if ( $is_connected ) : ?>
						<button type="button" id="twt-gmc-sync-btn" class="button button-primary">
							<?php esc_html_e( 'Sync Now', 'twt-aeo-ultimate' ); ?>
						</button>
						<div id="twt-gmc-sync-msg" style="display:none;margin-top:8px;font-size:12px;"></div>
						<?php endif; ?>
					</div>

				</div>
			</div>
		</section>

		<!-- ── Connection & Settings ───────────────────────────────────── -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Connection', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card" style="padding:24px;">

				<?php if ( $is_connected ) : ?>
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
					<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#16a34a;"></span>
					<strong style="color:#16a34a;"><?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></strong>
					<span style="color:#6b7280;font-size:13px;"><?php
					// translators: %s: Google Merchant Center merchant ID.
					printf( esc_html__( 'Merchant ID: %s', 'twt-aeo-ultimate' ), esc_html( $merchant_id ) ); ?></span>
					<button type="button" id="twt-gmc-disconnect-btn" class="button button-link-delete" style="margin-left:auto;"><?php esc_html_e( 'Disconnect', 'twt-aeo-ultimate' ); ?></button>
				</div>
				<?php else : ?>
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
					<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#d63638;"></span>
					<strong style="color:#d63638;"><?php esc_html_e( 'Not Connected', 'twt-aeo-ultimate' ); ?></strong>
				</div>
				<?php endif; ?>

				<form id="twt-gmc-settings-form" style="max-width:560px;">
					<?php wp_nonce_field( TWTAEO_GMC_Sync_Engine::NONCE_SAVE, 'twtaeo_gmc_save_nonce' ); ?>
					<table class="form-table" style="margin:0;">
						<tbody>
							<tr>
								<th scope="row" style="width:160px;"><label><?php esc_html_e( 'Google Client ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" name="gmc_client_id" class="regular-text"
										value="<?php echo esc_attr( $creds['client_id'] ?? '' ); ?>"
										placeholder="<?php esc_attr_e( '123456789-abc…', 'twt-aeo-ultimate' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Google Client Secret', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="password" name="gmc_client_secret" class="regular-text"
										value="<?php echo esc_attr( $creds['client_secret'] ?? '' ); ?>"
										placeholder="GOCSPX-…">
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Merchant Center ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" name="gmc_merchant_id" class="regular-text"
										value="<?php echo esc_attr( $merchant_id ); ?>"
										placeholder="<?php esc_attr_e( '123456789', 'twt-aeo-ultimate' ); ?>">
									<p class="description"><?php esc_html_e( 'Find this in Google Merchant Center → Settings → Account information.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Redirect URI', 'twt-aeo-ultimate' ); ?></th>
								<td>
									<code style="display:inline-block;padding:4px 8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:12px;word-break:break-all;"><?php echo esc_html( $redirect_uri ); ?></code>
									<p class="description"><?php esc_html_e( 'Add this exact URL to Authorised redirect URIs in your Google Cloud Console OAuth app.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Settings', 'twt-aeo-ultimate' ); ?></button>
						<?php
						$oauth_url = TWTAEO_Google_Merchant_Center::get_oauth_url();
						if ( $oauth_url ) : ?>
						<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary">
							<?php echo $is_connected
								? esc_html__( 'Re-authorise Google', 'twt-aeo-ultimate' )
								: esc_html__( 'Connect to Google Merchant Center', 'twt-aeo-ultimate' ); ?>
						</a>
						<?php endif; ?>
						<span id="twt-gmc-save-msg" style="display:none;font-size:13px;color:#16a34a;"></span>
					</div>
				</form>

			</div>
		</section>

		<?php if ( $is_connected && ! empty( $rows ) ) : ?>

		<!-- ── Product Feed Overview ──────────────────────────────────── -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Product Feed', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( ! $has_data ) : ?>
			<div class="twt-aeo-card">
				<p class="twt-aeo-empty"><?php esc_html_e( 'No sync data yet. Click "Sync Now" above to fetch your Merchant Center feed.', 'twt-aeo-ultimate' ); ?></p>
			</div>
			<?php else : ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table" style="font-size:13px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'GTIN / MPN / SKU', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Price', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Availability', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Sync Latency', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) :
						$snap = $row['snap'];
						if ( ! $snap ) {
							continue;
						}
					?>
						<tr class="twt-aeo-page-row <?php echo ( ! $snap['matched'] || $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['category_mismatch'] ) || ! empty( $snap['rejection_codes'] ) ) ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok'; ?>">

							<td>
								<a href="<?php echo esc_url( $row['edit_url'] ); ?>" style="font-weight:500;">
									<?php echo esc_html( $row['title'] ); ?>
								</a>
							</td>

							<td style="font-size:12px;color:#50575e;">
								<?php if ( $snap['gtin_local'] ) : ?>
								<div><strong>GTIN:</strong> <?php echo esc_html( $snap['gtin_local'] ); ?>
									<?php if ( $snap['matched'] && $snap['gtin_local'] !== $snap['gtin_gmc'] ) : ?>
										<span style="color:#d63638;" title="Mismatch with GMC"> ✗</span>
									<?php elseif ( $snap['matched'] ) : ?>
										<span style="color:#16a34a;"> ✓</span>
									<?php endif; ?>
								</div>
								<?php endif; ?>
								<?php if ( $snap['mpn_local'] ) : ?>
								<div><strong>MPN:</strong> <?php echo esc_html( $snap['mpn_local'] ); ?></div>
								<?php endif; ?>
								<?php if ( $snap['sku_local'] ) : ?>
								<div><strong>SKU:</strong> <?php echo esc_html( $snap['sku_local'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! $snap['gtin_local'] && ! $snap['mpn_local'] && ! $snap['sku_local'] ) : ?>
								<span class="twt-aeo-muted">—</span>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;">
								<div style="color:#111;">
									<?php echo esc_html( $snap['currency_local'] . ' ' . $snap['price_local'] ); ?>
									<?php if ( $snap['sale_price'] ) : ?>
									<span style="color:#16a34a;font-size:11px;"> (sale: <?php echo esc_html( $snap['sale_price'] ); ?>)</span>
									<?php endif; ?>
								</div>
								<?php if ( $snap['matched'] && $snap['price_gmc'] ) : ?>
								<div style="color:<?php echo $snap['price_mismatch'] ? '#d63638' : '#6b7280'; ?>;">
									GMC: <?php echo esc_html( $snap['currency_gmc'] . ' ' . $snap['price_gmc'] ); ?>
									<?php echo $snap['price_mismatch'] ? ' <strong>⚠ Mismatch</strong>' : ' ✓'; ?>
								</div>
								<?php elseif ( ! $snap['matched'] ) : ?>
								<div style="color:#6b7280;font-size:11px;"><?php esc_html_e( 'Not in GMC feed', 'twt-aeo-ultimate' ); ?></div>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;">
								<div><?php echo esc_html( str_replace( '_', ' ', ucfirst( $snap['avail_local'] ) ) ); ?></div>
								<?php if ( $snap['matched'] ) : ?>
								<div style="color:<?php echo $snap['avail_mismatch'] ? '#d63638' : '#6b7280'; ?>;">
									GMC: <?php echo esc_html( ucfirst( $snap['avail_gmc'] ) ); ?>
									<?php echo $snap['avail_mismatch'] ? ' <strong>⚠ Mismatch</strong>' : ' ✓'; ?>
								</div>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;color:#50575e;">
								<?php if ( $snap['matched'] && $snap['sync_latency'] > 0 ) :
									$latency = $snap['sync_latency'];
									if ( $latency < 3600 ) {
										$latency_label = round( $latency / 60 ) . 'm';
									} elseif ( $latency < 86400 ) {
										$latency_label = round( $latency / 3600 ) . 'h';
									} else {
										$latency_label = round( $latency / 86400 ) . 'd';
									}
								?>
								<span style="<?php echo $latency > 86400 ? 'color:#f59e0b;' : ''; ?>"><?php echo esc_html( $latency_label ); ?></span>
								<?php elseif ( $snap['matched'] ) : ?>
								<span style="color:#16a34a;">In sync</span>
								<?php else : ?>
								<span class="twt-aeo-muted">—</span>
								<?php endif; ?>
							</td>

							<td>
								<?php if ( ! $snap['matched'] ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(107,114,128,.1);color:#6b7280;">Not Found</span>
								<?php elseif ( ! empty( $snap['rejection_codes'] ) ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn"><?php echo esc_html( count( $snap['rejection_codes'] ) ); ?> Rejection<?php echo count( $snap['rejection_codes'] ) > 1 ? 's' : ''; ?></span>
								<?php elseif ( $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['category_mismatch'] ) || $snap['schema_discrepancy'] ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn">Data Gap</span>
								<?php else : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">In Sync ✓</span>
								<?php endif; ?>
							</td>

						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</section>

		<?php endif; // is_connected && rows ?>

		<!-- ── Rejection Log ─────────────────────────────────────────── -->
		<?php if ( $is_connected && ! empty( $rejections ) ) : ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php esc_html_e( 'Rejection Log', 'twt-aeo-ultimate' ); ?>
				<span style="font-weight:400;font-size:14px;color:#d63638;margin-left:8px;"><?php echo esc_html( count( $rejections ) ); ?> product<?php echo count( $rejections ) > 1 ? 's' : ''; ?></span>
			</h2>
			<?php foreach ( $rejections as $rej ) : ?>
			<div class="twt-aeo-card" style="margin-bottom:12px;padding:16px 20px;border-left:3px solid #d63638;">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
					<strong><a href="<?php echo esc_url( $rej['edit_url'] ); ?>"><?php echo esc_html( $rej['title'] ); ?></a></strong>
					<span style="font-size:12px;color:#6b7280;"><?php
					// translators: %d: number of rejection errors for this product.
					printf( esc_html__( '%d error(s)', 'twt-aeo-ultimate' ), absint( count( $rej['rejection_codes'] ) ) ); ?></span>
				</div>
				<ul style="margin:0;padding:0 0 0 16px;">
					<?php foreach ( $rej['rejection_codes'] as $err ) : ?>
					<li style="font-size:13px;margin-bottom:4px;">
						<strong style="font-family:monospace;color:#d63638;"><?php echo esc_html( $err['code'] ); ?></strong>
						— <?php echo esc_html( $err['description'] ); ?>
						<?php if ( $err['resolution'] ) : ?>
						<span style="color:#6b7280;"> (<?php echo esc_html( $err['resolution'] ); ?>)</span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var gmcNonce = <?php echo wp_json_encode( $gmc_nonce ); ?>;

			// Save settings.
			$('#twt-gmc-settings-form').on('submit', function(e) {
				e.preventDefault();
				var $msg = $('#twt-gmc-save-msg');
				var $btn = $(this).find('[type=submit]').prop('disabled', true).text('Saving…');
				$.post(ajaxurl, {
					action:            'twtaeo_gmc_save_settings',
					nonce:             gmcNonce,
					gmc_client_id:     $(this).find('[name=gmc_client_id]').val().trim(),
					gmc_client_secret: $(this).find('[name=gmc_client_secret]').val().trim(),
					gmc_merchant_id:   $(this).find('[name=gmc_merchant_id]').val().trim(),
				}, function(resp) {
					$btn.prop('disabled', false).text('Save Settings');
					if (resp.success) {
						$msg.text('Settings saved.').css('color','#16a34a').show();
						setTimeout(function() { $msg.hide(); }, 3000);
					} else {
						$msg.text(resp.data || 'Save failed.').css('color','#d63638').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Save Settings');
					$msg.text('Request failed.').css('color','#d63638').show();
				});
			});

			// Sync now — queued in the background; this poll loop tracks progress.
			function gmcSyncDone(d, $msg) {
				$msg.css('color','#16a34a')
					.text('Sync complete. ' + (d.matched || 0) + ' matched, ' + (d.rejected || 0) + ' rejected, ' + (d.price_mismatch || 0) + ' price mismatches.')
					.show();
				setTimeout(function() { location.reload(); }, 1500);
			}
			function gmcPollSync($btn, $msg) {
				$.post(ajaxurl, {
					action:   'twtaeo_msync_status',
					provider: 'gmc',
					nonce:    gmcNonce,
				}, function(resp) {
					if (!resp.success) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
						return;
					}
					var s = resp.data;
					if (s.status === 'done') {
						gmcSyncDone(s.summary || {}, $msg);
					} else if (s.status === 'failed') {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(s.error || 'Sync failed.').show();
					} else if (!s.running) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text('Sync stalled — please try again.').show();
					} else {
						$msg.css('color','#6b7280').text(
							s.status === 'fetching'
								? 'Fetching feed… ' + s.feed_items + ' items'
								: 'Comparing products… ' + s.diff_done + ' / ' + s.diff_total
						).show();
						setTimeout(function() { gmcPollSync($btn, $msg); }, 4000);
					}
				}).fail(function() {
					setTimeout(function() { gmcPollSync($btn, $msg); }, 8000);
				});
			}
			$('#twt-gmc-sync-btn').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('Syncing…');
				var $msg = $('#twt-gmc-sync-msg');
				$.post(ajaxurl, {
					action: 'twtaeo_gmc_sync',
					nonce:  gmcNonce,
				}, function(resp) {
					if (!resp.success) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
						return;
					}
					if (resp.data.queued) {
						gmcPollSync($btn, $msg);
					} else {
						gmcSyncDone(resp.data.summary || {}, $msg);
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Sync Now');
					$msg.css('color','#d63638').text('Request failed.').show();
				});
			});
			// Resume progress display if a sync was already running when the page loaded.
			if ($('#twt-gmc-sync-btn').length) {
				$.post(ajaxurl, { action: 'twtaeo_msync_status', provider: 'gmc', nonce: gmcNonce }, function(resp) {
					if (resp.success && resp.data.running) {
						var $btn = $('#twt-gmc-sync-btn').prop('disabled', true).text('Syncing…');
						gmcPollSync($btn, $('#twt-gmc-sync-msg'));
					}
				});
			}

			// Disconnect.
			$('#twt-gmc-disconnect-btn').on('click', function() {
				if (!confirm('Disconnect from Google Merchant Center?')) return;
				var $btn = $(this).prop('disabled', true).text('Disconnecting…');
				$.post(ajaxurl, {
					action: 'twtaeo_gmc_disconnect',
					nonce:  gmcNonce,
				}, function(resp) {
					if (resp.success) {
						location.reload();
					} else {
						$btn.prop('disabled', false).text('Disconnect');
						alert(resp.data || 'Failed to disconnect.');
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Disconnect');
					alert('Request failed.');
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	// ── Bing Merchant Center Tab ─────────────────────────────────────────────

	private static function render_bmc_tab() {
		$is_connected   = TWTAEO_Bing_Merchant_Center::is_connected();
		$creds          = TWTAEO_Bing_Merchant_Center::get_credentials();
		$store_id       = TWTAEO_Bing_Merchant_Center::get_store_id();
		$last_sync      = get_option( TWTAEO_BMC_Sync_Engine::OPTION_LAST, 0 );
		$bmc_nonce      = wp_create_nonce( self::NONCE_BMC );
		$redirect_uri   = TWTAEO_Bing_Merchant_Center::get_redirect_uri();

		// One catalog pass shared by the score, the table, and the rejection log.
		$rows       = TWTAEO_BMC_Sync_Engine::get_product_snapshots();
		$score_data = TWTAEO_BMC_Sync_Engine::get_integrity_score( $rows );
		$rejections = TWTAEO_BMC_Sync_Engine::get_rejection_log( $rows );

		$score      = $score_data['score'];
		$label      = $score_data['label'];
		$counts     = $score_data['counts'];
		$has_data   = $score_data['synced'] ?? false;

		if ( $score >= 90 )      $score_color = '#16a34a';
		elseif ( $score >= 70 )  $score_color = '#0ea5e9';
		elseif ( $score >= 50 )  $score_color = '#f59e0b';
		else                     $score_color = '#d63638';
		?>

		<!-- ── Integrity Score ─────────────────────────────────────────── -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-card" style="padding:24px;">
				<div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;">

					<div style="flex-shrink:0;text-align:center;">
						<svg width="100" height="100" viewBox="0 0 100 100" style="transform:rotate(-90deg);">
							<circle cx="50" cy="50" r="42" fill="none" stroke="#e5e7eb" stroke-width="10"/>
							<circle cx="50" cy="50" r="42" fill="none"
								stroke="<?php echo esc_attr( $score_color ); ?>"
								stroke-width="10"
								stroke-dasharray="<?php echo esc_attr( round( 2 * M_PI * 42, 1 ) ); ?>"
								stroke-dashoffset="<?php echo esc_attr( round( ( 1 - $score / 100 ) * 2 * M_PI * 42, 1 ) ); ?>"
								stroke-linecap="round"/>
						</svg>
						<div style="margin-top:-68px;font-size:26px;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>;"><?php echo esc_html( $has_data ? $score : '—' ); ?></div>
						<div style="margin-top:42px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;"><?php echo esc_html( $has_data ? $label : 'Not Synced' ); ?></div>
					</div>

					<div style="flex:1;min-width:220px;">
						<h2 style="margin:0 0 4px;font-size:18px;">Bing E-Commerce Integrity Score</h2>
						<p style="margin:0 0 16px;color:#6b7280;font-size:13px;">
							<?php esc_html_e( 'Measures alignment between your WooCommerce catalog and Bing Merchant Center feed data.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php if ( $has_data ) : ?>
						<div style="display:flex;gap:16px;flex-wrap:wrap;">
							<?php self::score_pill( $counts['matched'] ?? 0,        'Matched',        '#16a34a' ); ?>
							<?php self::score_pill( $counts['unmatched'] ?? 0,       'Unmatched',      '#6b7280' ); ?>
							<?php self::score_pill( $counts['rejected'] ?? 0,        'Rejected',       '#d63638' ); ?>
							<?php self::score_pill( $counts['price_mismatch'] ?? 0,  'Price Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['avail_mismatch'] ?? 0,  'Avail Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['category_mismatch'] ?? 0, 'Category Mismatch', '#f59e0b' ); ?>
							<?php self::score_pill( $counts['schema_issues'] ?? 0,   'Schema Issues',  '#6366f1' ); ?>
						</div>
						<?php else : ?>
						<p style="color:#6b7280;font-size:13px;font-style:italic;"><?php esc_html_e( 'Run a sync to calculate your score.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</div>

					<div style="flex-shrink:0;text-align:right;">
						<?php if ( $last_sync ) : ?>
						<p style="margin:0 0 8px;font-size:12px;color:#6b7280;">
							<?php
							// translators: %s: human-readable time difference since last sync.
							printf( esc_html__( 'Last synced %s', 'twt-aeo-ultimate' ), esc_html( human_time_diff( $last_sync ) . ' ago' ) ); ?>
						</p>
						<?php endif; ?>
						<?php if ( $is_connected ) : ?>
						<button type="button" id="twt-bmc-sync-btn" class="button button-primary">
							<?php esc_html_e( 'Sync Now', 'twt-aeo-ultimate' ); ?>
						</button>
						<div id="twt-bmc-sync-msg" style="display:none;margin-top:8px;font-size:12px;"></div>
						<?php endif; ?>
					</div>

				</div>
			</div>
		</section>

		<!-- ── Connection & Settings ───────────────────────────────────── -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Connection', 'twt-aeo-ultimate' ); ?></h2>
			<div class="twt-aeo-card" style="padding:24px;">

				<?php if ( $is_connected ) : ?>
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
					<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#16a34a;"></span>
					<strong style="color:#16a34a;"><?php esc_html_e( 'Connected', 'twt-aeo-ultimate' ); ?></strong>
					<span style="color:#6b7280;font-size:13px;"><?php
					// translators: %s: Bing Merchant Center store ID.
					printf( esc_html__( 'Store ID: %s', 'twt-aeo-ultimate' ), esc_html( $store_id ) ); ?></span>
					<button type="button" id="twt-bmc-disconnect-btn" class="button button-link-delete" style="margin-left:auto;"><?php esc_html_e( 'Disconnect', 'twt-aeo-ultimate' ); ?></button>
				</div>
				<?php else : ?>
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
					<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#d63638;"></span>
					<strong style="color:#d63638;"><?php esc_html_e( 'Not Connected', 'twt-aeo-ultimate' ); ?></strong>
				</div>
				<?php endif; ?>

				<form id="twt-bmc-settings-form" style="max-width:560px;">
					<?php wp_nonce_field( TWTAEO_BMC_Sync_Engine::NONCE_SAVE, 'twtaeo_bmc_save_nonce' ); ?>
					<table class="form-table" style="margin:0;">
						<tbody>
							<tr>
								<th scope="row" style="width:180px;"><label><?php esc_html_e( 'Azure Client ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" name="bmc_client_id" class="regular-text"
										value="<?php echo esc_attr( $creds['client_id'] ?? '' ); ?>"
										placeholder="<?php esc_attr_e( 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'twt-aeo-ultimate' ); ?>">
									<p class="description"><?php esc_html_e( 'Application (client) ID from Azure portal → App registrations.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Azure Client Secret', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="password" name="bmc_client_secret" class="regular-text"
										value="<?php echo esc_attr( $creds['client_secret'] ?? '' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Developer Token', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" name="bmc_developer_token" class="regular-text"
										value="<?php echo esc_attr( $creds['developer_token'] ?? '' ); ?>"
										placeholder="BBD37VB98">
									<p class="description"><?php esc_html_e( 'Found in Microsoft Advertising UI → Tools → API access → Developer token.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Merchant Center Store ID', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" name="bmc_store_id" class="regular-text"
										value="<?php echo esc_attr( $store_id ); ?>"
										placeholder="<?php esc_attr_e( '123456', 'twt-aeo-ultimate' ); ?>">
									<p class="description"><?php esc_html_e( 'Found in Bing Merchant Center → Store settings → Store ID.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Redirect URI', 'twt-aeo-ultimate' ); ?></th>
								<td>
									<code style="display:inline-block;padding:4px 8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:12px;word-break:break-all;"><?php echo esc_html( $redirect_uri ); ?></code>
									<p class="description"><?php esc_html_e( 'Add this exact URL to Redirect URIs in your Azure app registration.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Settings', 'twt-aeo-ultimate' ); ?></button>
						<?php
						$oauth_url = TWTAEO_Bing_Merchant_Center::get_oauth_url();
						if ( $oauth_url ) : ?>
						<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary">
							<?php echo $is_connected
								? esc_html__( 'Re-authorise Microsoft', 'twt-aeo-ultimate' )
								: esc_html__( 'Connect to Bing Merchant Center', 'twt-aeo-ultimate' ); ?>
						</a>
						<?php endif; ?>
						<span id="twt-bmc-save-msg" style="display:none;font-size:13px;color:#16a34a;"></span>
					</div>
				</form>

			</div>
		</section>

		<?php if ( $is_connected && ! empty( $rows ) ) : ?>

		<!-- ── Product Feed Overview ──────────────────────────────────── -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Product Feed', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( ! $has_data ) : ?>
			<div class="twt-aeo-card">
				<p class="twt-aeo-empty"><?php esc_html_e( 'No sync data yet. Click "Sync Now" above to fetch your Bing Merchant Center feed.', 'twt-aeo-ultimate' ); ?></p>
			</div>
			<?php else : ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table" style="font-size:13px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'GTIN / MPN / SKU', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Price', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Availability', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Sync Latency', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) :
						$snap = $row['snap'];
						if ( ! $snap ) continue;
						$has_issue = ! $snap['matched'] || $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['category_mismatch'] ) || ! empty( $snap['rejection_codes'] );
					?>
						<tr class="twt-aeo-page-row <?php echo $has_issue ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok'; ?>">

							<td>
								<a href="<?php echo esc_url( $row['edit_url'] ); ?>" style="font-weight:500;">
									<?php echo esc_html( $row['title'] ); ?>
								</a>
							</td>

							<td style="font-size:12px;color:#50575e;">
								<?php if ( $snap['gtin_local'] ) : ?>
								<div><strong>GTIN:</strong> <?php echo esc_html( $snap['gtin_local'] ); ?>
									<?php if ( $snap['matched'] && $snap['gtin_local'] !== $snap['gtin_bmc'] ) : ?>
										<span style="color:#d63638;" title="Mismatch with Bing"> ✗</span>
									<?php elseif ( $snap['matched'] ) : ?>
										<span style="color:#16a34a;"> ✓</span>
									<?php endif; ?>
								</div>
								<?php endif; ?>
								<?php if ( $snap['mpn_local'] ) : ?>
								<div><strong>MPN:</strong> <?php echo esc_html( $snap['mpn_local'] ); ?></div>
								<?php endif; ?>
								<?php if ( $snap['sku_local'] ) : ?>
								<div><strong>SKU:</strong> <?php echo esc_html( $snap['sku_local'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! $snap['gtin_local'] && ! $snap['mpn_local'] && ! $snap['sku_local'] ) : ?>
								<span class="twt-aeo-muted">—</span>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;">
								<div style="color:#111;">
									<?php echo esc_html( $snap['currency_local'] . ' ' . $snap['price_local'] ); ?>
									<?php if ( $snap['sale_price'] ) : ?>
									<span style="color:#16a34a;font-size:11px;"> (sale: <?php echo esc_html( $snap['sale_price'] ); ?>)</span>
									<?php endif; ?>
								</div>
								<?php if ( $snap['matched'] && $snap['price_bmc'] ) : ?>
								<div style="color:<?php echo $snap['price_mismatch'] ? '#d63638' : '#6b7280'; ?>;">
									Bing: <?php echo esc_html( $snap['currency_bmc'] . ' ' . $snap['price_bmc'] ); ?>
									<?php echo $snap['price_mismatch'] ? ' <strong>⚠ Mismatch</strong>' : ' ✓'; ?>
								</div>
								<?php elseif ( ! $snap['matched'] ) : ?>
								<div style="color:#6b7280;font-size:11px;"><?php esc_html_e( 'Not in Bing feed', 'twt-aeo-ultimate' ); ?></div>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;">
								<div><?php echo esc_html( str_replace( '_', ' ', ucfirst( $snap['avail_local'] ) ) ); ?></div>
								<?php if ( $snap['matched'] ) : ?>
								<div style="color:<?php echo $snap['avail_mismatch'] ? '#d63638' : '#6b7280'; ?>;">
									Bing: <?php echo esc_html( ucfirst( $snap['avail_bmc'] ) ); ?>
									<?php echo $snap['avail_mismatch'] ? ' <strong>⚠ Mismatch</strong>' : ' ✓'; ?>
								</div>
								<?php endif; ?>
							</td>

							<td style="font-size:12px;color:#50575e;">
								<?php if ( $snap['matched'] && $snap['sync_latency'] > 0 ) :
									$latency = $snap['sync_latency'];
									if ( $latency < 3600 ) {
										$latency_label = round( $latency / 60 ) . 'm';
									} elseif ( $latency < 86400 ) {
										$latency_label = round( $latency / 3600 ) . 'h';
									} else {
										$latency_label = round( $latency / 86400 ) . 'd';
									}
								?>
								<span style="<?php echo $latency > 86400 ? 'color:#f59e0b;' : ''; ?>"><?php echo esc_html( $latency_label ); ?></span>
								<?php elseif ( $snap['matched'] ) : ?>
								<span style="color:#16a34a;">In sync</span>
								<?php else : ?>
								<span class="twt-aeo-muted">—</span>
								<?php endif; ?>
							</td>

							<td>
								<?php if ( ! $snap['matched'] ) : ?>
									<span class="twt-aeo-badge" style="background:rgba(107,114,128,.1);color:#6b7280;">Not Found</span>
								<?php elseif ( ! empty( $snap['rejection_codes'] ) ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn"><?php echo esc_html( count( $snap['rejection_codes'] ) ); ?> Rejection<?php echo count( $snap['rejection_codes'] ) > 1 ? 's' : ''; ?></span>
								<?php elseif ( $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['category_mismatch'] ) || $snap['schema_discrepancy'] ) : ?>
									<span class="twt-aeo-badge twt-aeo-badge--warn">Data Gap</span>
								<?php else : ?>
									<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">In Sync ✓</span>
								<?php endif; ?>
							</td>

						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</section>

		<?php endif; // is_connected && rows ?>

		<!-- ── Rejection Log ─────────────────────────────────────────── -->
		<?php if ( $is_connected && ! empty( $rejections ) ) : ?>
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title">
				<?php esc_html_e( 'Rejection Log', 'twt-aeo-ultimate' ); ?>
				<span style="font-weight:400;font-size:14px;color:#d63638;margin-left:8px;"><?php echo esc_html( count( $rejections ) ); ?> product<?php echo count( $rejections ) > 1 ? 's' : ''; ?></span>
			</h2>
			<?php foreach ( $rejections as $rej ) : ?>
			<div class="twt-aeo-card" style="margin-bottom:12px;padding:16px 20px;border-left:3px solid #d63638;">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
					<strong><a href="<?php echo esc_url( $rej['edit_url'] ); ?>"><?php echo esc_html( $rej['title'] ); ?></a></strong>
					<span style="font-size:12px;color:#6b7280;"><?php
					// translators: %d: number of rejection errors for this product.
					printf( esc_html__( '%d error(s)', 'twt-aeo-ultimate' ), absint( count( $rej['rejection_codes'] ) ) ); ?></span>
				</div>
				<ul style="margin:0;padding:0 0 0 16px;">
					<?php foreach ( $rej['rejection_codes'] as $err ) : ?>
					<li style="font-size:13px;margin-bottom:4px;">
						<strong style="font-family:monospace;color:#d63638;"><?php echo esc_html( $err['code'] ); ?></strong>
						— <?php echo esc_html( $err['description'] ); ?>
						<?php if ( $err['resolution'] ) : ?>
						<span style="color:#6b7280;"> (<?php echo esc_html( $err['resolution'] ); ?>)</span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var bmcNonce = <?php echo wp_json_encode( $bmc_nonce ); ?>;

			$('#twt-bmc-settings-form').on('submit', function(e) {
				e.preventDefault();
				var $msg = $('#twt-bmc-save-msg');
				var $btn = $(this).find('[type=submit]').prop('disabled', true).text('Saving…');
				$.post(ajaxurl, {
					action:              'twtaeo_bmc_save_settings',
					nonce:               bmcNonce,
					bmc_client_id:       $(this).find('[name=bmc_client_id]').val().trim(),
					bmc_client_secret:   $(this).find('[name=bmc_client_secret]').val().trim(),
					bmc_developer_token: $(this).find('[name=bmc_developer_token]').val().trim(),
					bmc_store_id:        $(this).find('[name=bmc_store_id]').val().trim(),
				}, function(resp) {
					$btn.prop('disabled', false).text('Save Settings');
					if (resp.success) {
						$msg.text('Settings saved.').css('color','#16a34a').show();
						setTimeout(function() { $msg.hide(); }, 3000);
					} else {
						$msg.text(resp.data || 'Save failed.').css('color','#d63638').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Save Settings');
					$msg.text('Request failed.').css('color','#d63638').show();
				});
			});

			// Sync now — queued in the background; this poll loop tracks progress.
			function bmcSyncDone(d, $msg) {
				$msg.css('color','#16a34a')
					.text('Sync complete. ' + (d.matched || 0) + ' matched, ' + (d.rejected || 0) + ' rejected, ' + (d.price_mismatch || 0) + ' price mismatches.')
					.show();
				setTimeout(function() { location.reload(); }, 1500);
			}
			function bmcPollSync($btn, $msg) {
				$.post(ajaxurl, {
					action:   'twtaeo_msync_status',
					provider: 'bmc',
					nonce:    bmcNonce,
				}, function(resp) {
					if (!resp.success) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
						return;
					}
					var s = resp.data;
					if (s.status === 'done') {
						bmcSyncDone(s.summary || {}, $msg);
					} else if (s.status === 'failed') {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(s.error || 'Sync failed.').show();
					} else if (!s.running) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text('Sync stalled — please try again.').show();
					} else {
						$msg.css('color','#6b7280').text(
							s.status === 'fetching'
								? 'Fetching feed… ' + s.feed_items + ' items'
								: 'Comparing products… ' + s.diff_done + ' / ' + s.diff_total
						).show();
						setTimeout(function() { bmcPollSync($btn, $msg); }, 4000);
					}
				}).fail(function() {
					setTimeout(function() { bmcPollSync($btn, $msg); }, 8000);
				});
			}
			$('#twt-bmc-sync-btn').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('Syncing…');
				var $msg = $('#twt-bmc-sync-msg');
				$.post(ajaxurl, {
					action: 'twtaeo_bmc_sync',
					nonce:  bmcNonce,
				}, function(resp) {
					if (!resp.success) {
						$btn.prop('disabled', false).text('Sync Now');
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
						return;
					}
					if (resp.data.queued) {
						bmcPollSync($btn, $msg);
					} else {
						bmcSyncDone(resp.data.summary || {}, $msg);
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Sync Now');
					$msg.css('color','#d63638').text('Request failed.').show();
				});
			});
			// Resume progress display if a sync was already running when the page loaded.
			if ($('#twt-bmc-sync-btn').length) {
				$.post(ajaxurl, { action: 'twtaeo_msync_status', provider: 'bmc', nonce: bmcNonce }, function(resp) {
					if (resp.success && resp.data.running) {
						var $btn = $('#twt-bmc-sync-btn').prop('disabled', true).text('Syncing…');
						bmcPollSync($btn, $('#twt-bmc-sync-msg'));
					}
				});
			}

			$('#twt-bmc-disconnect-btn').on('click', function() {
				if (!confirm('Disconnect from Bing Merchant Center?')) return;
				var $btn = $(this).prop('disabled', true).text('Disconnecting…');
				$.post(ajaxurl, {
					action: 'twtaeo_bmc_disconnect',
					nonce:  bmcNonce,
				}, function(resp) {
					if (resp.success) {
						location.reload();
					} else {
						$btn.prop('disabled', false).text('Disconnect');
						alert(resp.data || 'Failed to disconnect.');
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Disconnect');
					alert('Request failed.');
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	private static function score_pill( $value, $label, $color ) {
		echo '<div style="text-align:center;">';
		echo '<div style="font-size:22px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( $value ) . '</div>';
		echo '<div style="font-size:11px;color:#6b7280;white-space:nowrap;">' . esc_html( $label ) . '</div>';
		echo '</div>';
	}

	/**
	 * Check a WooCommerce product for missing fields.
	 *
	 * @param WC_Product|null $wc_product
	 * @param string          $edit_url
	 * @return array[]
	 */
	private static function get_product_missing( $wc_product, $edit_url ) {
		$missing = array();
		if ( ! $wc_product ) {
			return $missing;
		}

		if ( $wc_product->get_price() === '' || $wc_product->get_price() === false ) {
			$missing[] = array(
				'field'   => 'Price',
				'message' => 'No price set — required for Product schema offers.',
				'fix_url' => $edit_url,
			);
		}

		if ( ! trim( wp_strip_all_tags( $wc_product->get_short_description() ) ) ) {
			$missing[] = array(
				'field'   => 'Short Description',
				'message' => 'Empty — add one for richer search results.',
				'fix_url' => $edit_url,
			);
		}

		if ( ! $wc_product->get_image_id() ) {
			$missing[] = array(
				'field'   => 'Product Image',
				'message' => 'No image — required for visual rich results.',
				'fix_url' => $edit_url,
			);
		}

		return $missing;
	}
}
