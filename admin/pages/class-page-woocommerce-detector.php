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

			<?php if ( $show_tabs ) : ?>
			<nav class="twt-aeo-tabs">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'products' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === 'products' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-cart"></span>
					<?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php if ( $gmc_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'gmc' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === 'gmc' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-google"></span>
					<?php esc_html_e( 'Google Merchant Center', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $bmc_active ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'twt-aeo-woocommerce', 'tab' => 'bmc' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === 'bmc' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Bing Merchant Center', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>
			</nav>
			<?php endif; ?>

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

	// ── Products Tab ─────────────────────────────────────────────────────────

	private static function render_products_tab() {
		$results      = TWTAEO_WooCommerce_Detector::scan_all();
		$summary      = TWTAEO_WooCommerce_Detector::get_summary();
		$wc_nonce     = wp_create_nonce( self::NONCE );
		$schema_nonce = wp_create_nonce( TWTAEO_Custom_Schema_Writer::NONCE_ACTION );
		?>

		<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
			<p style="margin:0;color:#50575e;">
				<?php
				printf(
					// translators: %1$d: total products. %2$d: products missing schema.
					esc_html__( '%1$d products — %2$d missing schema', 'twt-aeo-ultimate' ),
					absint( $summary['total'] ),
					absint( $summary['needs_schema'] )
				); ?>
			</p>
			<?php if ( $summary['needs_schema'] > 0 ) : ?>
			<button type="button" id="twt-aeo-wc-gen-all" class="button button-primary">
				<?php esc_html_e( 'Generate Schema for All', 'twt-aeo-ultimate' ); ?>
			</button>
			<?php endif; ?>
		</div>

		<div id="twt-aeo-wc-gen-all-result" style="display:none;margin:0 0 16px;" class="notice"></div>

		<!-- Summary Cards -->
		<section class="twt-aeo-section">
			<div class="twt-aeo-summary-grid">
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['total'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Total Products', 'twt-aeo-ultimate' ); ?></div>
				</div>
				<div class="twt-aeo-summary-card <?php echo $summary['needs_schema'] > 0 ? 'twt-aeo-summary-card--alert' : 'twt-aeo-summary-card--good'; ?>">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['needs_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Missing Schema', 'twt-aeo-ultimate' ); ?></div>
				</div>
				<div class="twt-aeo-summary-card twt-aeo-summary-card--good">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['has_schema'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Schema Present', 'twt-aeo-ultimate' ); ?></div>
				</div>
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['product_intent'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Physical Products', 'twt-aeo-ultimate' ); ?></div>
				</div>
				<div class="twt-aeo-summary-card">
					<div class="twt-aeo-summary-card__number"><?php echo esc_html( $summary['service_intent'] ); ?></div>
					<div class="twt-aeo-summary-card__label"><?php esc_html_e( 'Service Products', 'twt-aeo-ultimate' ); ?></div>
				</div>
			</div>
		</section>

		<!-- Products Table -->
		<section class="twt-aeo-section">
			<h2 class="twt-aeo-section__title"><?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( empty( $results ) ) : ?>
				<div class="twt-aeo-card">
					<p class="twt-aeo-empty">
						<?php esc_html_e( 'No published products found. Add products in WooCommerce to see them here.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			<?php else : ?>
			<div class="twt-aeo-page-table-wrap">
				<table class="twt-aeo-page-table twt-aeo-wc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Type / Intent', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Missing Info', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Schema', 'twt-aeo-ultimate' ); ?></th>
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
					?>
						<tr class="twt-aeo-page-row <?php echo esc_attr( $row_cls ); ?>"
							data-post-id="<?php echo esc_attr( $post->ID ); ?>">

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
			<?php endif; ?>
		</section>

		<!-- Schema Dialog -->
		<div id="twt-aeo-wc-dialog" style="display:none;padding:4px 0;">
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
					var price = '';
					var cur   = d.currency;
					var avail = d.availability;
					if (existing.offers) {
						price = existing.offers.price !== undefined ? existing.offers.price : d.price;
						cur   = existing.offers.priceCurrency || d.currency;
						avail = existing.offers.availability  || d.availability;
					} else {
						price = d.price;
					}
					var vals = {
						name:         existing.name        || d.name,
						description:  existing.description || d.description,
						price:        price,
						currency:     cur,
						sku:          existing.sku         || d.sku,
						availability: avail,
						image:        existing.image       || d.image_url,
					};
					$('#twt-aeo-wc-dialog-form').html(productFormHtml(vals));
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

			function productFormHtml(v) {
				return '<table class="form-table"><tbody>' +
					frow('Name',        '<input type="text" id="wc-f-name" class="large-text" value="' + esc(v.name) + '">') +
					frow('Description', '<textarea id="wc-f-desc" rows="3" class="large-text">' + esc(v.description) + '</textarea>') +
					frow('Price',       '<input type="text" id="wc-f-price" style="width:120px;" value="' + esc(v.price) + '"> ' +
						'<input type="text" id="wc-f-currency" style="width:70px;" placeholder="USD" value="' + esc(v.currency) + '">') +
					frow('SKU',         '<input type="text" id="wc-f-sku" style="width:180px;" value="' + esc(v.sku) + '">') +
					frow('Availability','<select id="wc-f-avail">' +
						fopt('https://schema.org/InStock',   'In Stock',   v.availability) +
						fopt('https://schema.org/OutOfStock','Out of Stock',v.availability) +
						fopt('https://schema.org/PreOrder',  'Pre-Order',  v.availability) +
					'</select>') +
					frow('Image URL', '<input type="url" id="wc-f-image" class="large-text" value="' + esc(v.image) + '">') +
					'</tbody></table>';
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

				if (type === 'Product') {
					var schema = { '@context': 'https://schema.org', '@type': 'Product', 'name': name, 'url': d.url };
					var desc  = $('#wc-f-desc').val().trim();
					var price = $('#wc-f-price').val().trim();
					var cur   = $('#wc-f-currency').val().trim() || d.currency;
					var sku   = $('#wc-f-sku').val().trim();
					var avail = $('#wc-f-avail').val();
					var img   = $('#wc-f-image').val().trim();
					if (desc)  schema.description = desc;
					if (img)   schema.image = img;
					if (sku)   schema.sku = sku;
					if (price) schema.offers = { '@type': 'Offer', price: price, priceCurrency: cur, availability: avail, url: d.url };
					schema.seller = { '@type': 'Organization', name: d.site_name, url: d.site_url };
					return schema;
				}

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

			function doDelete() {
				if (!confirm('Delete this schema? This cannot be undone.')) return;
				$.post(ajaxurl, {
					action: 'twtaeo_delete_custom_schema', nonce: schemaNonce,
					post_id: dlgPostId, schema_type: dlgType,
				}, function(resp) {
					if (resp.success) { updateRow(dlgPostId, dlgType, false); $dlg.dialog('close'); }
					else showMsg(resp.data || 'Delete failed.', 'error');
				});
			}

			$('#twt-aeo-wc-gen-all').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('Generating…');
				var $msg = $('#twt-aeo-wc-gen-all-result').hide().removeClass('notice-success notice-error');
				$.post(ajaxurl, { action: 'twtaeo_wc_generate_all', nonce: wcNonce }, function(resp) {
					$btn.prop('disabled', false).text('Generate Schema for All');
					if (resp.success) {
						var d = resp.data;
						var text = d.saved + ' schema' + (d.saved !== 1 ? 's' : '') + ' generated';
						if (d.skipped) text += ', ' + d.skipped + ' skipped (already have schema)';
						if (d.errors && d.errors.length) text += '. Errors: ' + esc(d.errors.join(', '));
						$msg.addClass('notice-success').html('<p>' + text + '. <a href="">Reload to see updated statuses.</a></p>').show();
					} else {
						$msg.addClass('notice-error').html('<p>' + esc(resp.data || 'Generation failed.') + '</p>').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Generate Schema for All');
					$msg.addClass('notice-error').html('<p>Request failed.</p>').show();
				});
			});

			function showMsg(text, type) {
				var $msg = $('#twt-aeo-wc-dialog-msg');
				var isOk = type === 'success';
				$msg.removeClass('notice-success notice-error')
					.css({ background: isOk ? '#edfaef' : '#fce8e8', 'border-left': '3px solid ' + (isOk ? '#16a34a' : '#d63638') })
					.text(text).show();
			}

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

		$score_data = TWTAEO_GMC_Sync_Engine::get_integrity_score();
		$score      = $score_data['score'];
		$label      = $score_data['label'];
		$counts     = $score_data['counts'];
		$has_data   = $score_data['synced'] ?? false;

		if ( $score >= 90 )      $score_color = '#16a34a';
		elseif ( $score >= 70 )  $score_color = '#0ea5e9';
		elseif ( $score >= 50 )  $score_color = '#f59e0b';
		else                     $score_color = '#d63638';

		$rows       = TWTAEO_GMC_Sync_Engine::get_product_snapshots();
		$rejections = TWTAEO_GMC_Sync_Engine::get_rejection_log();
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
					<?php wp_nonce_field( TWTAEO_GMC_Sync_Engine::NONCE_SAVE, 'twt_gmc_save_nonce' ); ?>
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
						<tr class="twt-aeo-page-row <?php echo ( ! $snap['matched'] || $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['rejection_codes'] ) ) ? 'twt-aeo-page-row--issues' : 'twt-aeo-page-row--ok'; ?>">

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
								<?php elseif ( $snap['price_mismatch'] || $snap['avail_mismatch'] || $snap['schema_discrepancy'] ) : ?>
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

			// Sync now.
			$('#twt-gmc-sync-btn').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('Syncing…');
				var $msg = $('#twt-gmc-sync-msg');
				$.post(ajaxurl, {
					action: 'twtaeo_gmc_sync',
					nonce:  gmcNonce,
				}, function(resp) {
					$btn.prop('disabled', false).text('Sync Now');
					if (resp.success) {
						var d = resp.data;
						$msg.css('color','#16a34a')
							.text('Sync complete. ' + d.matched + ' matched, ' + d.rejected + ' rejected, ' + d.price_mismatch + ' price mismatches.')
							.show();
						setTimeout(function() { location.reload(); }, 1500);
					} else {
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Sync Now');
					$msg.css('color','#d63638').text('Request failed.').show();
				});
			});

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

		$score_data = TWTAEO_BMC_Sync_Engine::get_integrity_score();
		$score      = $score_data['score'];
		$label      = $score_data['label'];
		$counts     = $score_data['counts'];
		$has_data   = $score_data['synced'] ?? false;

		if ( $score >= 90 )      $score_color = '#16a34a';
		elseif ( $score >= 70 )  $score_color = '#0ea5e9';
		elseif ( $score >= 50 )  $score_color = '#f59e0b';
		else                     $score_color = '#d63638';

		$rows       = TWTAEO_BMC_Sync_Engine::get_product_snapshots();
		$rejections = TWTAEO_BMC_Sync_Engine::get_rejection_log();
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
					<?php wp_nonce_field( TWTAEO_BMC_Sync_Engine::NONCE_SAVE, 'twt_bmc_save_nonce' ); ?>
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
						$has_issue = ! $snap['matched'] || $snap['price_mismatch'] || $snap['avail_mismatch'] || ! empty( $snap['rejection_codes'] );
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
								<?php elseif ( $snap['price_mismatch'] || $snap['avail_mismatch'] || $snap['schema_discrepancy'] ) : ?>
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

			$('#twt-bmc-sync-btn').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('Syncing…');
				var $msg = $('#twt-bmc-sync-msg');
				$.post(ajaxurl, {
					action: 'twtaeo_bmc_sync',
					nonce:  bmcNonce,
				}, function(resp) {
					$btn.prop('disabled', false).text('Sync Now');
					if (resp.success) {
						var d = resp.data;
						$msg.css('color','#16a34a')
							.text('Sync complete. ' + d.matched + ' matched, ' + d.rejected + ' rejected, ' + d.price_mismatch + ' price mismatches.')
							.show();
						setTimeout(function() { location.reload(); }, 1500);
					} else {
						$msg.css('color','#d63638').text(resp.data || 'Sync failed.').show();
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('Sync Now');
					$msg.css('color','#d63638').text('Request failed.').show();
				});
			});

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
