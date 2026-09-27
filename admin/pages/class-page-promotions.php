<?php
/**
 * Promotions & Price Rules admin page.
 *
 * Four tabs, in the order the decisions actually have to be made: what is running
 * on this store, what conditional prices we may publish, what the coupons look like
 * and why some cannot be used, and finally what gets sent to Merchant Center.
 *
 * The Merchant Center tab is the only place in this plugin that writes to a
 * merchant's external account, so it is deliberately the last tab, it never
 * pre-selects anything, and its button says what it will do.
 *
 * Every form posts to itself with a nonce and a capability check.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Promotions {

	const PAGE_SLUG = 'twt-aeo-promotions';

	/** How many blank quantity-tier rows the editor offers. */
	const TIER_ROWS = 6;

	/** Detected-pricing scan. */
	const NONCE_SCAN  = 'twtaeo_promotions_scan';
	const SCAN_RESULT = 'twtaeo_promotions_scan_result';
	const SCAN_BATCH  = 25;

	private static function tabs() {
		return array(
			'overview' => __( 'Overview', 'twt-aeo-ultimate' ),
			'rules'    => __( 'Price Rules', 'twt-aeo-ultimate' ),
			'coupons'  => __( 'Coupons', 'twt-aeo-ultimate' ),
			'merchant' => __( 'Merchant Center', 'twt-aeo-ultimate' ),
		);
	}

	private static function tab_url( $tab ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Saves run before output so each tab renders what was just stored.
		$notice = self::maybe_save_rules();
		if ( '' === $notice ) {
			$notice = self::maybe_save_promotion_settings();
		}
		if ( '' === $notice ) {
			$notice = self::maybe_scan();
		}
		$push = self::maybe_push();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
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

			<?php TWTAEO_Page_WooCommerce_Detector::render_commerce_tabs( 'promotions' ); ?>
			<h2 style="margin-top:0;"><?php esc_html_e( 'Promotions & Price Rules', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:840px;color:#50575e;">
				<?php esc_html_e( 'Discounts are the hardest thing on a store to describe honestly to a search engine, because most of them happen in the cart and the product page never shows them. This module publishes only the conditional prices that can be stated without contradicting the page — quantity breaks and member pricing — and sends coupon codes to Merchant Center, which is the channel Google actually built for them. It is off until you switch it on.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php self::render_integrity_banner(); ?>

			<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
						class="nav-tab <?php echo ( $tab === $slug ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( '' !== $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			}

			switch ( $tab ) {
				case 'rules':
					self::render_rules_tab();
					break;
				case 'coupons':
					self::render_coupons_tab();
					break;
				case 'merchant':
					self::render_merchant_tab( $push );
					break;
				default:
					self::render_overview_tab();
			}
			?>
		</div>
		<?php
	}

	// ── Banner ────────────────────────────────────────────────────────────────

	/**
	 * Name anything that can change a displayed price without a coupon code.
	 *
	 * This is the take-over pattern applied to a surface nobody else marks up but
	 * plenty of plugins *control*: if something is rewriting prices that we cannot
	 * read, the price in our schema and in the Merchant Center feed may not be the
	 * price the shopper is charged. Saying nothing would read as reassurance.
	 */
	private static function render_integrity_banner() {
		if ( ! class_exists( 'TWTAEO_Coupon_Rules' ) ) {
			return;
		}

		$dynamic      = TWTAEO_Coupon_Rules::dynamic_pricing_owners();
		$unrecognised = TWTAEO_Coupon_Rules::unrecognised_pricing_plugins();
		$readable     = class_exists( 'TWTAEO_Dynamic_Pricing' ) && TWTAEO_Dynamic_Pricing::is_available();

		if ( empty( $dynamic ) && empty( $unrecognised ) ) {
			return;
		}

		// A readable engine is not a warning. Once we can ask it what it charges,
		// its prices are an asset — the danger is only what we cannot see.
		$level = ( $readable && empty( $unrecognised ) ) ? 'info' : 'warning';
		?>
		<div class="notice notice-<?php echo esc_attr( $level ); ?>" style="max-width:840px;">
			<p style="margin:10px 0;">
				<?php if ( $readable ) : ?>
					<strong><?php
					printf(
						/* translators: %s: plugin name. */
						esc_html__( '%s is setting prices on this store, and we can read what it charges.', 'twt-aeo-ultimate' ),
						esc_html( implode( ', ', $dynamic ) )
					);
					?></strong><br>
					<?php esc_html_e( 'Where it charges less than your catalogue price for a single unit, that lower figure is what your product pages already show — so that is what gets published and what the Merchant Center comparison uses. Run a scan on the Price Rules tab after changing your discount rules.', 'twt-aeo-ultimate' ); ?>
				<?php elseif ( ! empty( $dynamic ) ) : ?>
					<strong><?php esc_html_e( 'Something on this store can change a price without a coupon code.', 'twt-aeo-ultimate' ); ?></strong><br>
					<?php
					printf(
						/* translators: %s: comma-separated plugin names. */
						esc_html__( 'Detected: %s. It is not answering the filter we use to read real prices back, so we cannot tell what it charges.', 'twt-aeo-ultimate' ),
						esc_html( implode( ', ', $dynamic ) )
					);
					?>
				<?php endif; ?>

				<?php if ( ! empty( $unrecognised ) ) : ?>
					<br><br><strong><?php esc_html_e( 'Something else is active that we do not recognise.', 'twt-aeo-ultimate' ); ?></strong><br>
					<?php
					printf(
						/* translators: %s: comma-separated plugin folder names. */
						esc_html__( '%s. We cannot read what these do to your prices, so check that the price on a product page matches the price in your Merchant Center feed — if they disagree, the feed is disapproved for a price mismatch and the page\'s structured data is wrong for the same reason.', 'twt-aeo-ultimate' ),
						esc_html( implode( ', ', $unrecognised ) )
					);
					?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// ── Overview ──────────────────────────────────────────────────────────────

	private static function render_overview_tab() {
		$detected     = TWTAEO_Coupon_Rules::detected_plugins();
		$rules        = TWTAEO_Coupon_Rules::all_rules();
		$promotable   = array_filter( $rules, function ( $r ) { return $r['promotable']; } );
		$enforcement  = TWTAEO_Price_Tiers::enforcement();
		$enabled      = TWTAEO_Price_Tiers::is_enabled();
		?>
		<h2><?php esc_html_e( 'What is running on this store', 'twt-aeo-ultimate' ); ?></h2>

		<?php if ( empty( $detected ) ) : ?>
			<p><?php esc_html_e( 'No coupon or discount plugin detected. WooCommerce\'s own coupons are read directly, so everything on this page still works.', 'twt-aeo-ultimate' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:840px;">
				<thead><tr>
					<th><?php esc_html_e( 'Plugin', 'twt-aeo-ultimate' ); ?></th>
					<th><?php esc_html_e( 'What we read from it', 'twt-aeo-ultimate' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $detected as $def ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $def['label'] ); ?></strong></td>
						<td><?php echo esc_html( $def['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Coupons', 'twt-aeo-ultimate' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: usable count, 2: total count. */
				esc_html__( '%1$d of %2$d published coupons can be described honestly. The Coupons tab lists every one that cannot, with the reason.', 'twt-aeo-ultimate' ),
				count( $promotable ),
				count( $rules )
			);
			?>
		</p>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Conditional pricing', 'twt-aeo-ultimate' ); ?></h2>
		<p>
			<?php if ( ! $enabled ) : ?>
				<?php esc_html_e( 'This module is off. Nothing it does changes what your site publishes.', 'twt-aeo-ultimate' ); ?>
			<?php elseif ( ! $enforcement['ok'] ) : ?>
				<strong><?php esc_html_e( 'Quantity tiers are switched on but nothing on this store applies them, so none are published.', 'twt-aeo-ultimate' ); ?></strong>
				<?php esc_html_e( 'WooCommerce has no built-in quantity-break pricing. See the Price Rules tab.', 'twt-aeo-ultimate' ); ?>
			<?php elseif ( 'read' === $enforcement['level'] ) : ?>
				<?php
				printf(
					/* translators: %s: plugin name. */
					esc_html__( 'Tier prices are read back from %s, so what is published is what your checkout charges rather than anything typed by hand.', 'twt-aeo-ultimate' ),
					esc_html( TWTAEO_Dynamic_Pricing::engine_label() )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Conditional prices are being published on product pages, each carrying the condition that qualifies it.', 'twt-aeo-ultimate' ); ?>
			<?php endif; ?>
		</p>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'What this module will never do', 'twt-aeo-ultimate' ); ?></h2>
		<ul style="max-width:840px;list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( 'Change the price in your product schema. A coupon is applied in the cart; the price on the page is the price we publish.', 'twt-aeo-ultimate' ); ?></li>
			<li><?php esc_html_e( 'Write a discount code into your product schema. There is no schema.org property for a promo code on an offer — discountCode belongs to Order, not Offer, so publishing it would validate as nothing at all.', 'twt-aeo-ultimate' ); ?></li>
			<li><?php esc_html_e( 'Overwrite priceValidUntil with a coupon\'s expiry. Your price does not become stale because a coupon ended.', 'twt-aeo-ultimate' ); ?></li>
			<li><?php esc_html_e( 'Send anything to Merchant Center on its own. Every push is a button you press, naming the coupons you ticked.', 'twt-aeo-ultimate' ); ?></li>
		</ul>
		<?php
	}

	// ── Price Rules ───────────────────────────────────────────────────────────

	private static function render_rules_tab() {
		$s           = TWTAEO_Price_Tiers::get();
		$enforcement = TWTAEO_Price_Tiers::enforcement();
		$roles       = TWTAEO_Price_Tiers::available_roles();
		$tiers       = $s['store_tiers'];
		$member_map  = array();
		foreach ( $s['member_tiers'] as $row ) {
			$member_map[ $row['role'] ] = $row;
		}
		?>
		<form method="post">
			<?php wp_nonce_field( TWTAEO_Price_Tiers::NONCE_SAVE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Module', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="twtaeo_tiers[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>>
							<?php esc_html_e( 'Publish conditional pricing and on-page discount content', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'While this is off, nothing on this tab changes a single byte of what your site publishes.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Quantity breaks', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:840px;">
				<?php esc_html_e( 'A price that applies when a shopper buys at least a certain number. This is one of only two conditional prices that can be published safely, because the condition travels with the price in the markup and a crawler cannot mistake it for the shelf price.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( 'read' === $enforcement['level'] ) : ?>
				<div class="notice notice-success inline" style="max-width:840px;">
					<p>
						<strong><?php
						printf(
							/* translators: %s: plugin name. */
							esc_html__( 'Tier prices are read back from %s.', 'twt-aeo-ultimate' ),
							esc_html( TWTAEO_Dynamic_Pricing::engine_label() )
						);
						?></strong><br>
						<?php esc_html_e( 'It answers a documented filter asking what it will charge for a product at a given quantity, so a published tier is its own answer rather than anything typed here. That makes the price enforced by construction — the thing that charges it is what told us. Prices read this way override the tiers below.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			<?php elseif ( ! $enforcement['ok'] ) : ?>
				<div class="notice notice-error inline" style="max-width:840px;">
					<p>
						<strong><?php esc_html_e( 'Nothing on this store can apply a quantity break.', 'twt-aeo-ultimate' ); ?></strong><br>
						<?php esc_html_e( 'WooCommerce has no built-in quantity-break pricing, and no plugin that provides it was detected. Publishing a tier price the checkout will not honour is a false statement about a price, so no tiers are published while this is the case.', 'twt-aeo-ultimate' ); ?>
					</p>
					<p>
						<label>
							<input type="checkbox" name="twtaeo_tiers[enforcement_confirmed]" value="1" <?php checked( $s['enforcement_confirmed'], 1 ); ?>>
							<?php esc_html_e( 'I have set these prices up myself (custom code or a plugin not listed here), and the checkout charges them.', 'twt-aeo-ultimate' ); ?>
						</label>
					</p>
				</div>
			<?php else : ?>
				<p>
					<?php if ( 'detected' === $enforcement['level'] ) : ?>
						<?php
						printf(
							/* translators: %s: plugin name. */
							esc_html__( 'Applied by: %s.', 'twt-aeo-ultimate' ),
							esc_html( implode( ', ', $enforcement['owners'] ) )
						);
						?>
						<?php esc_html_e( 'It is present but is not answering the filter we use to read real prices back, so the tiers below are taken at your word and must match what you configured there.', 'twt-aeo-ultimate' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'You have confirmed these prices are applied at checkout by something we cannot see. They will be published as typed.', 'twt-aeo-ultimate' ); ?>
						<label style="display:block;margin-top:6px;">
							<input type="checkbox" name="twtaeo_tiers[enforcement_confirmed]" value="1" <?php checked( $s['enforcement_confirmed'], 1 ); ?>>
							<?php esc_html_e( 'Keep this confirmation.', 'twt-aeo-ultimate' ); ?>
						</label>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish quantity breaks', 'twt-aeo-ultimate' ); ?></th>
					<td><label>
						<input type="checkbox" name="twtaeo_tiers[output_quantity_tiers]" value="1" <?php checked( $s['output_quantity_tiers'], 1 ); ?>>
						<?php esc_html_e( 'Show the table on product pages and mark it up', 'twt-aeo-ultimate' ); ?>
					</label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Table placement', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<?php self::placement_select( 'twtaeo_tiers[tier_table_placement]', $s['tier_table_placement'] ); ?>
						<p class="description"><?php esc_html_e( 'Choose the middle option if you have added [twtaeo_price_tiers] to your template, or if your pricing plugin already renders its own quantity table and you do not want a second one. Schema is emitted whenever this is not "off", so choosing it when nothing is actually on the page would leave marked-up prices a visitor cannot see.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Minimum cart total', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<input type="number" step="0.01" min="0" name="twtaeo_tiers[transaction_volume]" value="<?php echo esc_attr( $s['transaction_volume'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Optional. If your quantity breaks also require a minimum order value, state it here and it travels with each tier. Leave at 0 if there is none.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Store-wide tiers', 'twt-aeo-ultimate' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Applied to every product unless a product overrides them in its own AEO panel. Leave a row blank to skip it. A tier must start at 2 or more — a tier starting at 1 is just the price.', 'twt-aeo-ultimate' ); ?></p>
			<table class="widefat striped" style="max-width:720px;margin:10px 0 24px;">
				<thead><tr>
					<th style="width:22%"><?php esc_html_e( 'From qty', 'twt-aeo-ultimate' ); ?></th>
					<th style="width:22%"><?php esc_html_e( 'To qty', 'twt-aeo-ultimate' ); ?></th>
					<th style="width:28%"><?php esc_html_e( 'Adjustment', 'twt-aeo-ultimate' ); ?></th>
					<th style="width:28%"><?php esc_html_e( 'Value', 'twt-aeo-ultimate' ); ?></th>
				</tr></thead>
				<tbody>
				<?php for ( $i = 0; $i < self::TIER_ROWS; $i++ ) :
					$row = isset( $tiers[ $i ] ) ? $tiers[ $i ] : array( 'min' => '', 'max' => '', 'type' => 'percent', 'value' => '' );
					?>
					<tr>
						<td><input type="number" min="2" step="1" style="width:100%"
							name="twtaeo_tiers[store_tiers][<?php echo (int) $i; ?>][min]"
							value="<?php echo esc_attr( $row['min'] ); ?>"></td>
						<td><input type="number" min="0" step="1" style="width:100%"
							name="twtaeo_tiers[store_tiers][<?php echo (int) $i; ?>][max]"
							value="<?php echo esc_attr( $row['max'] ); ?>"
							placeholder="<?php esc_attr_e( 'no limit', 'twt-aeo-ultimate' ); ?>"></td>
						<td><?php self::adjust_select( 'twtaeo_tiers[store_tiers][' . $i . '][type]', $row['type'] ); ?></td>
						<td><input type="number" min="0" step="0.01" style="width:100%"
							name="twtaeo_tiers[store_tiers][<?php echo (int) $i; ?>][value]"
							value="<?php echo esc_attr( $row['value'] ); ?>"></td>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Member pricing', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:840px;">
				<?php esc_html_e( 'The second safe conditional price: a price available to a membership tier. Google reads this as a pair — a member programme declared on your organisation, and a price on each product valid for one of its tiers. Both halves are published together or neither is.', 'twt-aeo-ultimate' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish member pricing', 'twt-aeo-ultimate' ); ?></th>
					<td><label>
						<input type="checkbox" name="twtaeo_tiers[output_member_tiers]" value="1" <?php checked( $s['output_member_tiers'], 1 ); ?>>
						<?php esc_html_e( 'Declare a member programme and mark up member prices', 'twt-aeo-ultimate' ); ?>
					</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="twt-aeo-member-name"><?php esc_html_e( 'Programme name', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<input type="text" id="twt-aeo-member-name" class="regular-text"
							name="twtaeo_tiers[member_program_name]"
							value="<?php echo esc_attr( $s['member_program_name'] ); ?>"
							placeholder="<?php esc_attr_e( 'Trade Account', 'twt-aeo-ultimate' ); ?>">
					</td>
				</tr>
			</table>

			<?php if ( empty( $roles ) ) : ?>
				<p><?php esc_html_e( 'This site has no customer roles that could act as a membership tier.', 'twt-aeo-ultimate' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:840px;margin:10px 0 24px;">
					<thead><tr>
						<th style="width:26%"><?php esc_html_e( 'Role', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:26%"><?php esc_html_e( 'Tier name shown publicly', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:24%"><?php esc_html_e( 'Adjustment', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:24%"><?php esc_html_e( 'Value', 'twt-aeo-ultimate' ); ?></th>
					</tr></thead>
					<tbody>
					<?php $r = 0; foreach ( $roles as $role => $label ) :
						$row = isset( $member_map[ $role ] ) ? $member_map[ $role ] : array( 'name' => '', 'type' => 'percent', 'value' => '' );
						?>
						<tr>
							<td>
								<?php echo esc_html( $label ); ?>
								<input type="hidden" name="twtaeo_tiers[member_tiers][<?php echo (int) $r; ?>][role]" value="<?php echo esc_attr( $role ); ?>">
							</td>
							<td><input type="text" style="width:100%"
								name="twtaeo_tiers[member_tiers][<?php echo (int) $r; ?>][name]"
								value="<?php echo esc_attr( $row['name'] ); ?>"
								placeholder="<?php echo esc_attr( $label ); ?>"></td>
							<td><?php self::adjust_select( 'twtaeo_tiers[member_tiers][' . $r . '][type]', $row['type'] ); ?></td>
							<td><input type="number" min="0" step="0.01" style="width:100%"
								name="twtaeo_tiers[member_tiers][<?php echo (int) $r; ?>][value]"
								value="<?php echo esc_attr( $row['value'] ); ?>"
								placeholder="<?php esc_attr_e( '0 = no tier', 'twt-aeo-ultimate' ); ?>"></td>
						</tr>
					<?php $r++; endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'On-page discount codes', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:840px;">
				<?php esc_html_e( 'Codes cannot go in the offer markup, but they can be written on the page as a visible question and answer, which is then marked up as part of the product\'s FAQ. That is the only honest way a code reaches structured data.', 'twt-aeo-ultimate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show applicable codes', 'twt-aeo-ultimate' ); ?></th>
					<td><label>
						<input type="checkbox" name="twtaeo_tiers[output_coupon_note]" value="1" <?php checked( $s['output_coupon_note'], 1 ); ?>>
						<?php esc_html_e( 'List the coupons that apply to each product, on that product\'s page', 'twt-aeo-ultimate' ); ?>
					</label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Note placement', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<?php self::placement_select( 'twtaeo_tiers[coupon_note_placement]', $s['coupon_note_placement'] ); ?>
						<p class="description"><?php esc_html_e( 'The shortcode is [twtaeo_coupon_note].', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Maximum codes per product', 'twt-aeo-ultimate' ); ?></th>
					<td><input type="number" min="1" max="10" step="1" class="small-text"
						name="twtaeo_tiers[coupon_note_limit]" value="<?php echo esc_attr( $s['coupon_note_limit'] ); ?>"></td>
				</tr>
			</table>

			<?php submit_button( __( 'Save price rules', 'twt-aeo-ultimate' ), 'primary', 'twtaeo_tiers_submit' ); ?>
		</form>

		<?php if ( TWTAEO_Dynamic_Pricing::is_available() ) : ?>
			<h2 style="margin-top:28px;"><?php esc_html_e( 'Read real tier prices from your pricing plugin', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:840px;">
				<?php esc_html_e( 'Scanning asks your pricing plugin what it charges at a ladder of quantities and stores the answer against each product, so product pages publish real prices without running its rule engine on every page view. Saving a product rescans it. Run this after changing your discount rules.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php $scan = get_transient( self::SCAN_RESULT ); ?>
			<?php if ( is_array( $scan ) ) : ?>
				<div class="notice notice-info inline" style="max-width:840px;">
					<p>
						<?php
						printf(
							/* translators: 1: scanned, 2: total, 3: with tiers, 4: engine-priced. */
							esc_html__( 'Scanned %1$d of %2$d products. %3$d have quantity breaks. On %4$d your pricing plugin charges less than the catalogue price for a single unit, so that lower figure is what gets published — it is what your product pages already show.', 'twt-aeo-ultimate' ),
							(int) $scan['done'],
							(int) $scan['total'],
							(int) $scan['with_tiers'],
							(int) $scan['engine_priced']
						);
						?>
						<?php if ( ! empty( $scan['next'] ) ) : ?>
							<br><strong><?php esc_html_e( 'More to go — run it again to continue.', 'twt-aeo-ultimate' ); ?></strong>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_SCAN ); ?>
				<input type="hidden" name="twtaeo_scan_offset" value="<?php echo esc_attr( is_array( $scan ) ? (int) $scan['next'] : 0 ); ?>">
				<?php submit_button(
					( is_array( $scan ) && ! empty( $scan['next'] ) )
						? __( 'Continue scanning', 'twt-aeo-ultimate' )
						: __( 'Scan the catalogue', 'twt-aeo-ultimate' ),
					'secondary',
					'twtaeo_scan_submit'
				); ?>
			</form>

			<?php self::render_refresh_status(); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * State of the automatic weekly refresh.
	 *
	 * ⚠️ This panel exists to show the merchant a number we would rather not have
	 * to admit: how long our published prices can lag their pricing rules. A
	 * discount rule is not a product, so ending one fires no product hook and
	 * nothing else in the plugin notices — the weekly sweep is the only thing that
	 * does. Its interval is therefore the worst case window in which structured
	 * data can contradict the checkout, and stating it plainly is the same rule
	 * every score on these screens follows: say what it cannot tell you.
	 */
	private static function render_refresh_status() {
		if ( ! class_exists( 'TWTAEO_Pricing_Refresh' ) ) {
			return;
		}

		$running    = TWTAEO_Pricing_Refresh::should_run();
		$state      = TWTAEO_Pricing_Refresh::get_state();
		$next       = TWTAEO_Pricing_Refresh::next_run();
		$staleness  = TWTAEO_Pricing_Refresh::staleness();
		?>
		<h3 style="margin-top:24px;"><?php esc_html_e( 'Automatic weekly refresh', 'twt-aeo-ultimate' ); ?></h3>
		<p style="max-width:840px;">
			<?php esc_html_e( 'Ending or editing a discount rule does not touch any product, so nothing here notices on its own. Once a week this rescans the catalogue in small batches and rewrites each stored price in place. Between sweeps, a promotion that ends and does not come back leaves the old, lower price published — so if you end a promotion for good, scan above rather than waiting.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( ! $running ) : ?>
			<p style="max-width:840px;color:#50575e;">
				<?php esc_html_e( 'Not running: quantity tier output is switched off, so nothing reads these stored prices and refreshing them would spend your server\'s time for no result.', 'twt-aeo-ultimate' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:840px;">
				<tbody>
					<tr>
						<th scope="row" style="width:230px;"><?php esc_html_e( 'Last complete sweep', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<?php if ( empty( $state['finished_at'] ) ) : ?>
								<?php esc_html_e( 'Never — the first sweep has not finished yet.', 'twt-aeo-ultimate' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: human time difference, 2: number scanned. */
									esc_html__( '%1$s ago — %2$d products', 'twt-aeo-ultimate' ),
									esc_html( human_time_diff( (int) $state['finished_at'] ) ),
									(int) $state['scanned']
								);
								?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Published prices could be as old as', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<?php if ( null === $staleness ) : ?>
								<?php esc_html_e( 'Unknown until the first sweep completes.', 'twt-aeo-ultimate' ); ?>
							<?php else : ?>
								<strong><?php echo esc_html( human_time_diff( time() - $staleness ) ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next sweep', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<?php if ( ! empty( $state['running'] ) ) : ?>
								<?php
								printf(
									/* translators: 1: products done, 2: total. */
									esc_html__( 'Running now — %1$d of %2$d done.', 'twt-aeo-ultimate' ),
									(int) $state['scanned'],
									(int) $state['total']
								);
								?>
							<?php elseif ( $next ) : ?>
								<?php echo esc_html( human_time_diff( time(), $next ) ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Not scheduled.', 'twt-aeo-ultimate' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $state['last_error'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last result', 'twt-aeo-ultimate' ); ?></th>
							<td><?php echo esc_html( $state['last_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function placement_select( $name, $current ) {
		$options = array(
			'auto'      => __( 'Place it for me on product pages', 'twt-aeo-ultimate' ),
			// Covers two cases that are the same promise: the merchant placed the
			// shortcode, or their pricing plugin already renders its own table. Either
			// way they are asserting a visible counterpart exists, which is what the
			// markup needs. Without this, a merchant using Flycart's own table would
			// have to choose between two tables and no schema at all.
			'shortcode' => __( 'Something else on the page already shows it', 'twt-aeo-ultimate' ),
			'off'       => __( 'Do not show it, and do not mark it up', 'twt-aeo-ultimate' ),
		);
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function adjust_select( $name, $current ) {
		$options = array(
			'percent' => __( '% off', 'twt-aeo-ultimate' ),
			'fixed'   => __( 'Amount off each', 'twt-aeo-ultimate' ),
			'price'   => __( 'Fixed price each', 'twt-aeo-ultimate' ),
		);
		echo '<select name="' . esc_attr( $name ) . '" style="width:100%">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	// ── Coupons ───────────────────────────────────────────────────────────────

	private static function render_coupons_tab() {
		$rules = TWTAEO_Coupon_Rules::all_rules( true );
		?>
		<h2><?php esc_html_e( 'Every published coupon', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:840px;">
			<?php esc_html_e( 'Read from WooCommerce directly, which means coupons created by any of the coupon plugins appear here too. A coupon that cannot be described honestly is listed with the reason rather than quietly dropped.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( empty( $rules ) ) : ?>
			<p><?php esc_html_e( 'No published coupons.', 'twt-aeo-ultimate' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Code', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Discount', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Applies to', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Ends', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Usable', 'twt-aeo-ultimate' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rules as $rule ) : ?>
				<tr>
					<td><code><?php echo esc_html( strtoupper( $rule['code'] ) ); ?></code></td>
					<td><?php echo esc_html( TWTAEO_Coupon_Rules::discount_label( $rule ) ); ?></td>
					<td><?php echo esc_html( $rule['scope'] ); ?></td>
					<td>
						<?php if ( $rule['date_to'] ) : ?>
							<?php echo esc_html( $rule['date_to'] ); ?>
							<?php if ( ! empty( $rule['schedule_managed'] ) ) : ?>
								<br><span style="color:#8a6d1b;" title="<?php esc_attr_e( 'A scheduling plugin has taken over this coupon\'s expiry. The date shown is the one stored on the coupon; check it matches the schedule before sending it as a promotion.', 'twt-aeo-ultimate' ); ?>">
									<?php esc_html_e( 'also scheduled', 'twt-aeo-ultimate' ); ?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $rule['promotable'] ) : ?>
							<span style="color:#1a7f37;">&#10003; <?php esc_html_e( 'Yes', 'twt-aeo-ultimate' ); ?></span>
						<?php else : ?>
							<span style="color:#8a6d1b;"><?php echo esc_html( $rule['skip_reason'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ── Merchant Center ───────────────────────────────────────────────────────

	private static function render_merchant_tab( $push ) {
		$connected = class_exists( 'TWTAEO_Google_Merchant_Center' ) && TWTAEO_Google_Merchant_Center::is_connected();
		$settings  = TWTAEO_Promotions_Feed::get_settings();
		$rules     = TWTAEO_Coupon_Rules::all_rules();
		$log       = TWTAEO_Promotions_Feed::push_log();
		?>
		<h2><?php esc_html_e( 'Google Merchant Center promotions', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:840px;">
			<?php esc_html_e( 'This is where a discount code belongs. Google reads promotions from Merchant Center, not from the page, and shows them alongside your products in Shopping results.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( is_array( $push ) ) : ?>
			<div class="notice notice-<?php echo $push['failed'] ? 'warning' : 'success'; ?>">
				<p><strong><?php
					printf(
						/* translators: 1: sent count, 2: failed count. */
						esc_html__( '%1$d sent, %2$d not sent.', 'twt-aeo-ultimate' ),
						(int) $push['sent'],
						(int) $push['failed']
					);
				?></strong></p>
				<?php foreach ( $push['results'] as $result ) : ?>
					<p style="margin:2px 0;">
						<code><?php echo esc_html( strtoupper( $result['code'] ) ); ?></code>
						&mdash; <?php echo esc_html( $result['message'] ); ?>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $connected ) : ?>
			<div class="notice notice-info inline" style="max-width:840px;">
				<p>
					<?php esc_html_e( 'Not connected to Google Merchant Center. Connect it on the Products & Feeds page first — the same connection is used here.', 'twt-aeo-ultimate' ); ?>
					<?php // Named the screen but left the merchant to find it. The slug is twt-aeo-woocommerce and must stay — the GMC/BMC OAuth redirect URIs point at it. ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'gmc' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( 'Open Products &amp; Feeds', 'twt-aeo-ultimate' ); ?> &rarr;
					</a>
				</p>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( TWTAEO_Promotions_Feed::NONCE_SETTINGS ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Coupons with no expiry', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<input type="number" min="0" max="365" step="1" class="small-text"
							name="twtaeo_promo[default_duration_days]"
							value="<?php echo esc_attr( $settings['default_duration_days'] ); ?>">
						<?php esc_html_e( 'days', 'twt-aeo-ultimate' ); ?>
						<p class="description"><?php esc_html_e( 'Merchant Center requires an end date on every promotion. A coupon with no expiry has none to send, so state how long such a coupon should run as a promotion. Leave at 0 to skip those coupons entirely — a promotion that outlives what you intended is worse than one that was never created.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redeemable', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label><input type="checkbox" name="twtaeo_promo[redeem_online]" value="1" <?php checked( $settings['redeem_online'], 1 ); ?>>
							<?php esc_html_e( 'Online', 'twt-aeo-ultimate' ); ?></label><br>
						<label>
							<input type="checkbox" name="twtaeo_promo[redeem_in_store]" value="1"
								<?php checked( $settings['redeem_in_store'], 1 ); ?>
								<?php disabled( ! TWTAEO_Promotions_Feed::has_physical_store() ); ?>>
							<?php esc_html_e( 'In store', 'twt-aeo-ultimate' ); ?>
						</label>
						<?php if ( ! TWTAEO_Promotions_Feed::has_physical_store() ) : ?>
							<p class="description"><?php esc_html_e( 'Available once the Local Store module is publishing a real shop address. Claiming in-store redemption without a store is a false statement about the business.', 'twt-aeo-ultimate' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save promotion settings', 'twt-aeo-ultimate' ), 'secondary', 'twtaeo_promo_submit' ); ?>
		</form>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Send promotions', 'twt-aeo-ultimate' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( TWTAEO_Promotions_Feed::NONCE_PUSH ); ?>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:32px;"></th>
					<th><?php esc_html_e( 'Code', 'twt-aeo-ultimate' ); ?></th>
					<th><?php esc_html_e( 'Will be sent as', 'twt-aeo-ultimate' ); ?></th>
					<th><?php esc_html_e( 'Last sent', 'twt-aeo-ultimate' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rules as $rule ) :
					$eligible = TWTAEO_Promotions_Feed::eligibility( $rule );
					$sent     = isset( $log[ $rule['id'] ] ) ? $log[ $rule['id'] ] : null;
					?>
					<tr>
						<td>
							<?php if ( $eligible['ok'] && $connected ) : ?>
								<input type="checkbox" name="twtaeo_push[]" value="<?php echo (int) $rule['id']; ?>">
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( strtoupper( $rule['code'] ) ); ?></code></td>
						<td>
							<?php if ( $eligible['ok'] ) : ?>
								<?php echo esc_html( TWTAEO_Promotions_Feed::long_title( $rule ) ); ?>
							<?php else : ?>
								<span style="color:#8a6d1b;"><?php echo esc_html( $eligible['reason'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $sent ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $sent['pushed_at'] ) ); ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<?php esc_html_e( 'Sending publishes these promotions on your Google Merchant Center account. Nothing is sent unless you tick it here.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php submit_button(
				__( 'Send the ticked promotions to Merchant Center', 'twt-aeo-ultimate' ),
				'primary',
				'twtaeo_push_submit',
				true,
				$connected ? array() : array( 'disabled' => 'disabled' )
			); ?>
		</form>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Bing / Microsoft Merchant Center', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:840px;">
			<?php esc_html_e( 'Microsoft\'s Shopping Content API has no promotions endpoint — its product records only reference a promotion defined in a separate feed you upload. So this is a file to download and upload there, not something we can send for you.', 'twt-aeo-ultimate' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url(
				admin_url( 'admin-post.php?action=twtaeo_promotions_bing_feed' ),
				'twtaeo_bing_feed'
			) ); ?>"><?php esc_html_e( 'Download promotions feed (TSV)', 'twt-aeo-ultimate' ); ?></a>
		</p>
		<?php
	}

	// ── Save handlers ─────────────────────────────────────────────────────────

	private static function maybe_save_rules() {
		if ( ! isset( $_POST['twtaeo_tiers_submit'] ) ) {
			return '';
		}
		check_admin_referer( TWTAEO_Price_Tiers::NONCE_SAVE );
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every field is sanitized field-by-field in TWTAEO_Price_Tiers::save().
		$raw = isset( $_POST['twtaeo_tiers'] ) ? (array) wp_unslash( $_POST['twtaeo_tiers'] ) : array();
		TWTAEO_Price_Tiers::save( $raw );

		return __( 'Price rules saved.', 'twt-aeo-ultimate' );
	}

	private static function maybe_save_promotion_settings() {
		if ( ! isset( $_POST['twtaeo_promo_submit'] ) ) {
			return '';
		}
		check_admin_referer( TWTAEO_Promotions_Feed::NONCE_SETTINGS );
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every field is sanitized field-by-field in TWTAEO_Promotions_Feed::save_settings().
		$raw = isset( $_POST['twtaeo_promo'] ) ? (array) wp_unslash( $_POST['twtaeo_promo'] ) : array();
		TWTAEO_Promotions_Feed::save_settings( $raw );

		return __( 'Promotion settings saved.', 'twt-aeo-ultimate' );
	}

	/**
	 * Run one bounded batch of detected-price discovery.
	 *
	 * Bounded and resumable rather than a single sweep: each product costs a ladder
	 * of calls into someone else's rule engine, and an unbounded loop over a large
	 * catalogue is a timeout with nothing saved.
	 *
	 * @return string Notice text, or ''.
	 */
	private static function maybe_scan() {
		if ( ! isset( $_POST['twtaeo_scan_submit'] ) ) {
			return '';
		}
		check_admin_referer( self::NONCE_SCAN );
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$offset = isset( $_POST['twtaeo_scan_offset'] ) ? max( 0, (int) $_POST['twtaeo_scan_offset'] ) : 0;

		$batch    = TWTAEO_Dynamic_Pricing::scan_batch( self::SCAN_BATCH, $offset );
		$previous = get_transient( self::SCAN_RESULT );
		$previous = is_array( $previous ) && $offset > 0 ? $previous : array( 'with_tiers' => 0, 'engine_priced' => 0 );

		set_transient( self::SCAN_RESULT, array(
			'done'       => $offset + $batch['scanned'],
			'total'      => $batch['total'],
			'with_tiers' => (int) $previous['with_tiers'] + $batch['with_tiers'],
			'engine_priced' => (int) $previous['engine_priced'] + $batch['engine_priced'],
			'next'       => $batch['next'],
		), HOUR_IN_SECONDS );

		return __( 'Scan complete for this batch.', 'twt-aeo-ultimate' );
	}

	/**
	 * Handle the push.
	 *
	 * @return array|null Push results, or null when no push was submitted.
	 */
	private static function maybe_push() {
		if ( ! isset( $_POST['twtaeo_push_submit'] ) ) {
			return null;
		}
		check_admin_referer( TWTAEO_Promotions_Feed::NONCE_PUSH );
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$ids = isset( $_POST['twtaeo_push'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['twtaeo_push'] ) ) : array();
		if ( empty( $ids ) ) {
			return array( 'sent' => 0, 'failed' => 0, 'results' => array() );
		}

		return TWTAEO_Promotions_Feed::push( $ids );
	}

	/**
	 * Stream the Bing promotions feed as a download.
	 *
	 * Registered on admin_post so headers are sent before any admin HTML.
	 */
	public static function download_bing_feed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		check_admin_referer( 'twtaeo_bing_feed' );

		$tsv = TWTAEO_Promotions_Feed::bing_feed_tsv();

		if ( '' === $tsv ) {
			wp_die( esc_html__( 'No coupon on this store can currently be expressed as a promotion. The Merchant Center tab lists the reason for each.', 'twt-aeo-ultimate' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/tab-separated-values; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="twt-aeo-promotions.tsv"' );
		header( 'Content-Length: ' . strlen( $tsv ) );

		echo $tsv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TSV body, cells sanitised in tsv_cell().
		exit;
	}
}
