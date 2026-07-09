<?php
/**
 * Admin Page: Local Pack
 *
 * Tabbed UI for the Local Pack module:
 *   Tab 1 — Business Profile  (name, subtype picker, NAP, geo, hours, area served)
 *   Tab 2 — NAP Audit         (Google KG / GBP OAuth / Bing Maps checks + comparison table)
 *   Tab 3 — Output Settings   (where to inject the LocalBusiness schema)
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Local_Pack {

	const NONCE_SAVE = 'twtaeo_local_pack_save';
	const NONCE_NAP  = 'twtaeo_local_pack_nap';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		// ── Handle GBP OAuth callback ─────────────────────────────────────────
		if ( isset( $_GET['twtaeo_gbp_callback'], $_GET['code'], $_GET['state'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$result = TWTAEO_Local_Pack_NAP::handle_gbp_callback(
				sanitize_text_field( wp_unslash( $_GET['code'] ) ),
				sanitize_text_field( wp_unslash( $_GET['state'] ) )
			);
			$gbp_notice = is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', 'Google Business Profile connected successfully.' );
		}

		// ── Handle GBP disconnect ─────────────────────────────────────────────
		if ( isset( $_GET['twtaeo_gbp_disconnect'] ) ) {
			check_admin_referer( 'twtaeo_gbp_disconnect' );
			TWTAEO_Local_Pack_NAP::disconnect_gbp();
			$gbp_notice = array( 'success', 'Google Business Profile disconnected.' );
		}

		// ── Handle settings save ──────────────────────────────────────────────
		$saved = false;
		if ( isset( $_POST['twtaeo_local_pack_save'] ) ) {
			check_admin_referer( self::NONCE_SAVE );
			TWTAEO_Local_Pack::save_settings( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) );
			$saved = true;
		}

		$settings    = TWTAEO_Local_Pack::get_settings();
		$subtypes    = TWTAEO_Local_Pack::get_subtypes();
		$active_tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? 'profile' ) );
		$gbp_connected = TWTAEO_Local_Pack_NAP::is_gbp_connected();

		$gbp_oauth_url = TWTAEO_Local_Pack_NAP::get_gbp_oauth_url();
		$gbp_disconnect_url = wp_nonce_url(
			add_query_arg( array( 'page' => 'twt-aeo-local-pack', 'twtaeo_gbp_disconnect' => '1' ), admin_url( 'admin.php' ) ),
			'twtaeo_gbp_disconnect'
		);

		// Ensure WP media uploader is available.
		wp_enqueue_media();

		$days = array(
			__( 'Monday', 'twt-aeo-ultimate' ),
			__( 'Tuesday', 'twt-aeo-ultimate' ),
			__( 'Wednesday', 'twt-aeo-ultimate' ),
			__( 'Thursday', 'twt-aeo-ultimate' ),
			__( 'Friday', 'twt-aeo-ultimate' ),
			__( 'Saturday', 'twt-aeo-ultimate' ),
			__( 'Sunday', 'twt-aeo-ultimate' ),
		);
		$day_abbr = array(
			_x( 'Mon', 'Monday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Tue', 'Tuesday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Wed', 'Wednesday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Thu', 'Thursday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Fri', 'Friday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Sat', 'Saturday abbreviation', 'twt-aeo-ultimate' ),
			_x( 'Sun', 'Sunday abbreviation', 'twt-aeo-ultimate' ),
		);

		// Enqueue JS with subtype data and AJAX config.
		wp_enqueue_script(
			'twt-aeo-local-pack',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/local-pack.js',
			array( 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-local-pack', 'twtAeoLocalPack', array(
			'subtypes'     => $subtypes,
			'nonce'        => wp_create_nonce( self::NONCE_NAP ),
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'currentType'  => $settings['business_type'] ?? 'LocalBusiness',
			'currentLabel' => $subtypes[ $settings['business_type'] ?? 'LocalBusiness' ] ?? 'Local Business (Generic)',
			'days'         => $days,
			'dayAbbr'      => $day_abbr,
		) );
		wp_set_script_translations( 'twt-aeo-local-pack', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'Local Pack', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Build your LocalBusiness schema, check NAP consistency on Google and Bing, and configure where your schema outputs.', 'twt-aeo-ultimate' ); ?></p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'twt-aeo-ultimate' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $gbp_notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $gbp_notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $gbp_notice[1] ); ?></p></div>
			<?php endif; ?>

			<nav class="twt-aeo-tabs">
				<?php
				$tabs = array(
					'profile' => array( 'label' => 'Business Profile', 'icon' => 'dashicons-location' ),
					'nap'     => array( 'label' => 'NAP Audit',        'icon' => 'dashicons-phone' ),
					'output'  => array( 'label' => 'Output Settings',  'icon' => 'dashicons-admin-settings' ),
					'entity'  => array( 'label' => 'Entity Validation','icon' => 'dashicons-shield' ),
				);
				foreach ( $tabs as $slug => $tab ) :
					$url = add_query_arg( array( 'page' => 'twt-aeo-local-pack', 'tab' => $slug ), admin_url( 'admin.php' ) );
					$cls = ( $active_tab === $slug ) ? 'twt-aeo-tab twt-aeo-tab--active' : 'twt-aeo-tab';
				?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" style="margin-top:0;">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<?php /* ── TAB: Business Profile ── */ ?>
				<?php if ( 'profile' === $active_tab ) : ?>

					<div style="max-width:800px;margin-top:20px;display:flex;justify-content:flex-end;">
						<button type="button" id="lp_autofill_btn" class="button">
							&#11023; <?php esc_html_e( 'Auto-Fill from Site', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
					<div id="lp_autofill_notice" style="display:none;max-width:800px;margin-top:8px;" class="notice notice-info is-dismissible"><p></p></div>

					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:12px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Business Identity', 'twt-aeo-ultimate' ); ?></h2>

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="lp_business_name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?> <span style="color:red">*</span></label></th>
								<td><input type="text" id="lp_business_name" name="business_name" value="<?php echo esc_attr( $settings['business_name'] ?? '' ); ?>" class="regular-text" required></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Business Type', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<div class="lp-subtype-wrapper" style="position:relative;max-width:400px;">
										<input
											type="text"
											id="lp_business_type_search"
											class="regular-text"
											placeholder="<?php esc_attr_e( 'Type to search (e.g. plumber, restaurant)…', 'twt-aeo-ultimate' ); ?>"
											autocomplete="off"
											style="width:100%;"
										>
										<input type="hidden" id="lp_business_type" name="business_type" value="<?php echo esc_attr( $settings['business_type'] ?? 'LocalBusiness' ); ?>">
										<div id="lp_subtype_dropdown" style="display:none;position:absolute;z-index:999;background:#fff;border:1px solid #c3c4c7;border-radius:3px;width:100%;max-height:220px;overflow-y:auto;box-shadow:0 3px 8px rgba(0,0,0,.1);"></div>
									</div>
									<p class="description"><?php esc_html_e( 'Narrows the schema @type to the most specific matching schema.org subtype.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="lp_phone"><?php esc_html_e( 'Phone', 'twt-aeo-ultimate' ); ?></label></th>
								<td><input type="text" id="lp_phone" name="phone" value="<?php echo esc_attr( $settings['phone'] ?? '' ); ?>" class="regular-text" placeholder="+1-555-555-5555"></td>
							</tr>
							<tr>
								<th><label for="lp_email"><?php esc_html_e( 'Email', 'twt-aeo-ultimate' ); ?></label></th>
								<td><input type="email" id="lp_email" name="email" value="<?php echo esc_attr( $settings['email'] ?? '' ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th><label for="lp_business_url"><?php esc_html_e( 'Website URL', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="url" id="lp_business_url" name="business_url" value="<?php echo esc_attr( $settings['business_url'] ?? home_url( '/' ) ); ?>" class="regular-text">
									<p class="description"><?php esc_html_e( 'Defaults to your homepage if left blank.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="lp_description"><?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?></label></th>
								<td><textarea id="lp_description" name="description" rows="3" class="regular-text"><?php echo esc_textarea( $settings['description'] ?? '' ); ?></textarea></td>
							</tr>
							<tr>
								<th><label for="lp_price_range"><?php esc_html_e( 'Price Range', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<select id="lp_price_range" name="price_range">
										<option value=""><?php esc_html_e( '— None —', 'twt-aeo-ultimate' ); ?></option>
										<?php foreach ( array( '$', '$$', '$$$', '$$$$' ) as $p ) : ?>
											<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $settings['price_range'] ?? '', $p ); ?>><?php echo esc_html( $p ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
					</div>

					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:16px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Address', 'twt-aeo-ultimate' ); ?></h2>

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="lp_street"><?php esc_html_e( 'Street Address', 'twt-aeo-ultimate' ); ?></label></th>
								<td><input type="text" id="lp_street" name="street_address" value="<?php echo esc_attr( $settings['street_address'] ?? '' ); ?>" class="regular-text" placeholder="123 Main St"></td>
							</tr>
							<tr>
								<th><label for="lp_city"><?php esc_html_e( 'City', 'twt-aeo-ultimate' ); ?></label></th>
								<td><input type="text" id="lp_city" name="city" value="<?php echo esc_attr( $settings['city'] ?? '' ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th></th>
								<td style="display:flex;gap:12px;">
									<input type="text" name="state" value="<?php echo esc_attr( $settings['state'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'State', 'twt-aeo-ultimate' ); ?>" style="width:80px;">
									<input type="text" name="zip" value="<?php echo esc_attr( $settings['zip'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'ZIP', 'twt-aeo-ultimate' ); ?>" style="width:100px;">
									<input type="text" name="country" value="<?php echo esc_attr( $settings['country'] ?? 'US' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Country', 'twt-aeo-ultimate' ); ?>" style="width:60px;">
								</td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Coordinates', 'twt-aeo-ultimate' ); ?></label></th>
								<td style="display:flex;gap:12px;align-items:center;">
									<input type="text" name="latitude"  value="<?php echo esc_attr( $settings['latitude']  ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude', 'twt-aeo-ultimate' ); ?>" style="width:130px;">
									<input type="text" name="longitude" value="<?php echo esc_attr( $settings['longitude'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'twt-aeo-ultimate' ); ?>" style="width:130px;">
									<span class="description"><?php esc_html_e( 'Used to localize Bing NAP checks and populate geo schema.', 'twt-aeo-ultimate' ); ?></span>
								</td>
							</tr>
							<tr>
								<th><label for="lp_maps_url"><?php esc_html_e( 'Google Maps URL', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="url" id="lp_maps_url" name="maps_url" value="<?php echo esc_attr( $settings['maps_url'] ?? '' ); ?>" class="regular-text" placeholder="https://maps.google.com/maps?cid=...">
									<p class="description"><?php esc_html_e( 'Output as hasMap in schema. Find it in your GBP → Share → Copy link.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:16px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Opening Hours', 'twt-aeo-ultimate' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Each row maps one or more days to an open/close time. Add multiple rows for split shifts or weekend-only hours.', 'twt-aeo-ultimate' ); ?></p>

						<div id="lp_hours_rows" style="margin-top:14px;">
							<?php
							$saved_hours = $settings['hours'] ?? array();
							if ( empty( $saved_hours ) ) {
								$saved_hours = array(
									array( 'days' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ), 'opens' => '09:00', 'closes' => '17:00' ),
								);
							}
							foreach ( $saved_hours as $i => $group ) :
								$group_days = (array) ( $group['days'] ?? array() );
							?>
								<div class="lp-hours-row" style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
									<div style="display:flex;gap:6px;flex-wrap:wrap;">
										<?php foreach ( $days as $di => $day ) : ?>
											<label style="display:inline-flex;align-items:center;gap:3px;font-size:12px;white-space:nowrap;">
												<input type="checkbox" name="hours[<?php echo absint( $i ); ?>][days][]" value="<?php echo esc_attr( $day ); ?>" <?php checked( in_array( $day, $group_days, true ) ); ?>>
												<?php echo esc_html( $day_abbr[ $di ] ); ?>
											</label>
										<?php endforeach; ?>
									</div>
									<input type="time" name="hours[<?php echo absint( $i ); ?>][opens]"  value="<?php echo esc_attr( $group['opens']  ?? '09:00' ); ?>" style="width:110px;">
									<span style="font-size:12px;color:#666;"><?php esc_html_e( 'to', 'twt-aeo-ultimate' ); ?></span>
									<input type="time" name="hours[<?php echo absint( $i ); ?>][closes]" value="<?php echo esc_attr( $group['closes'] ?? '17:00' ); ?>" style="width:110px;">
									<button type="button" class="button lp-remove-hours" style="color:#a00;"><?php esc_html_e( '✕ Remove', 'twt-aeo-ultimate' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>

						<button type="button" id="lp_add_hours" class="button" style="margin-top:6px;"><?php esc_html_e( '+ Add Hours Row', 'twt-aeo-ultimate' ); ?></button>
						<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Leave "closes" as 00:00 for businesses open past midnight.', 'twt-aeo-ultimate' ); ?></p>
					</div>

					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:16px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Images & Service Area', 'twt-aeo-ultimate' ); ?></h2>

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="lp_logo_url"><?php esc_html_e( 'Logo', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<?php
									$logo_url = $settings['logo_url'] ?? '';
									?>
									<div class="lp-media-field" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
										<?php if ( $logo_url ) : ?>
											<img src="<?php echo esc_url( $logo_url ); ?>" id="lp_logo_preview" style="max-height:60px;max-width:180px;object-fit:contain;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
										<?php else : ?>
											<img id="lp_logo_preview" src="" style="display:none;max-height:60px;max-width:180px;object-fit:contain;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
										<?php endif; ?>
										<div style="display:flex;flex-direction:column;gap:6px;">
											<div style="display:flex;gap:8px;align-items:center;">
												<button type="button" class="button lp-media-select" data-target="lp_logo_url" data-preview="lp_logo_preview">
													<?php esc_html_e( 'Select from Media Library', 'twt-aeo-ultimate' ); ?>
												</button>
												<button type="button" class="button-link lp-media-remove" data-target="lp_logo_url" data-preview="lp_logo_preview" style="color:#a00;<?php echo $logo_url ? '' : 'display:none;'; ?>">
													<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
												</button>
											</div>
											<input type="url" id="lp_logo_url" name="logo_url" value="<?php echo esc_attr( $logo_url ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Or paste an image URL…', 'twt-aeo-ultimate' ); ?>" style="width:360px;">
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="lp_image_url"><?php esc_html_e( 'Photo', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<?php
									$image_url = $settings['image_url'] ?? '';
									?>
									<div class="lp-media-field" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
										<?php if ( $image_url ) : ?>
											<img src="<?php echo esc_url( $image_url ); ?>" id="lp_image_preview" style="max-height:60px;max-width:180px;object-fit:contain;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
										<?php else : ?>
											<img id="lp_image_preview" src="" style="display:none;max-height:60px;max-width:180px;object-fit:contain;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
										<?php endif; ?>
										<div style="display:flex;flex-direction:column;gap:6px;">
											<div style="display:flex;gap:8px;align-items:center;">
												<button type="button" class="button lp-media-select" data-target="lp_image_url" data-preview="lp_image_preview">
													<?php esc_html_e( 'Select from Media Library', 'twt-aeo-ultimate' ); ?>
												</button>
												<button type="button" class="button-link lp-media-remove" data-target="lp_image_url" data-preview="lp_image_preview" style="color:#a00;<?php echo $image_url ? '' : 'display:none;'; ?>">
													<?php esc_html_e( 'Remove', 'twt-aeo-ultimate' ); ?>
												</button>
											</div>
											<input type="url" id="lp_image_url" name="image_url" value="<?php echo esc_attr( $image_url ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Or paste an image URL…', 'twt-aeo-ultimate' ); ?>" style="width:360px;">
										</div>
									</div>
									<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Used as the image property in LocalBusiness schema (e.g. a photo of your storefront).', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="lp_area_served"><?php esc_html_e( 'Area Served', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" id="lp_area_served" name="area_served" value="<?php echo esc_attr( $settings['area_served'] ?? '' ); ?>" class="regular-text" placeholder="Tampa, St. Petersburg, Clearwater">
									<p class="description"><?php esc_html_e( 'Comma-separated cities, regions, or states. Output as areaServed in schema.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

				<?php /* ── TAB: NAP Audit ── */ ?>
				<?php elseif ( 'nap' === $active_tab ) : ?>

					<!-- Google section -->
					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:20px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Google NAP Check', 'twt-aeo-ultimate' ); ?></h2>

						<p style="margin-bottom:16px;"><?php esc_html_e( 'Choose how to check your NAP against Google:', 'twt-aeo-ultimate' ); ?></p>

						<!-- Method toggle -->
						<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
							<label class="lp-method-card <?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'kg' ) ? 'lp-method-active' : ''; ?>" style="flex:1;min-width:240px;border:2px solid <?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'kg' ) ? '#2271b1' : '#c3c4c7'; ?>;border-radius:6px;padding:16px;cursor:pointer;">
								<input type="radio" name="google_nap_method" value="kg" <?php checked( $settings['google_nap_method'] ?? 'kg', 'kg' ); ?> style="margin-right:8px;" id="lp_method_kg" class="lp-method-radio">
								<label for="lp_method_kg" style="font-weight:600;cursor:pointer;"><?php esc_html_e( 'Knowledge Graph API', 'twt-aeo-ultimate' ); ?></label>
								<p style="margin:8px 0 0;font-size:12px;color:#666;line-height:1.5;"><?php esc_html_e( 'Searches Google\'s public Knowledge Graph entity database for your business. Free to use — requires a Google Cloud API key. No account login needed. Returns what Google\'s knowledge base currently says about your business entity.', 'twt-aeo-ultimate' ); ?></p>
							</label>

							<label class="lp-method-card <?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'gbp' ) ? 'lp-method-active' : ''; ?>" style="flex:1;min-width:240px;border:2px solid <?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'gbp' ) ? '#2271b1' : '#c3c4c7'; ?>;border-radius:6px;padding:16px;cursor:pointer;">
								<input type="radio" name="google_nap_method" value="gbp" <?php checked( $settings['google_nap_method'] ?? 'kg', 'gbp' ); ?> style="margin-right:8px;" id="lp_method_gbp" class="lp-method-radio">
								<label for="lp_method_gbp" style="font-weight:600;cursor:pointer;"><?php esc_html_e( 'Google Business Profile (OAuth)', 'twt-aeo-ultimate' ); ?></label>
								<p style="margin:8px 0 0;font-size:12px;color:#666;line-height:1.5;"><?php esc_html_e( 'Connects directly to your Google Business Profile account via OAuth. Returns your authoritative, live GBP listing data — the exact NAP Google uses to populate the Local Pack. Requires signing in with the Google account that manages your GBP.', 'twt-aeo-ultimate' ); ?></p>
							</label>
						</div>

						<!-- KG API key section -->
						<div id="lp_kg_section" style="<?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'kg' ) ? '' : 'display:none;'; ?>">
							<table class="form-table" role="presentation" style="margin-bottom:0;">
								<tr>
									<th style="width:180px;"><label for="lp_kg_key"><?php esc_html_e( 'Knowledge Graph API Key', 'twt-aeo-ultimate' ); ?></label></th>
									<td>
										<input type="text" id="lp_kg_key" name="google_kg_api_key" value="<?php echo esc_attr( $settings['google_kg_api_key'] ?? '' ); ?>" class="regular-text" placeholder="AIzaSy...">
										<details style="margin-top:8px;">
											<summary style="cursor:pointer;font-size:12px;color:#2271b1;"><?php esc_html_e( 'How to get a free API key', 'twt-aeo-ultimate' ); ?></summary>
											<ol style="font-size:12px;color:#555;margin:8px 0 0 16px;line-height:1.8;">
												<li><?php echo wp_kses_post( __( 'Go to <strong>console.cloud.google.com</strong> and create or select a project.', 'twt-aeo-ultimate' ) ); ?></li>
												<li><?php echo wp_kses_post( __( 'Search for <strong>Knowledge Graph Search API</strong> and enable it.', 'twt-aeo-ultimate' ) ); ?></li>
												<li><?php echo wp_kses_post( __( 'Go to <strong>APIs & Services → Credentials → Create Credentials → API Key</strong>.', 'twt-aeo-ultimate' ) ); ?></li>
												<li><?php esc_html_e( 'Copy the key and paste it above. The API is free with generous limits.', 'twt-aeo-ultimate' ); ?></li>
											</ol>
										</details>
									</td>
								</tr>
							</table>
							<div style="margin-top:12px;">
								<button type="button" id="lp_nap_check_kg" class="button button-primary"><?php esc_html_e( 'Check Google Knowledge Graph', 'twt-aeo-ultimate' ); ?></button>
							</div>
						</div>

						<!-- GBP OAuth section -->
						<div id="lp_gbp_section" style="<?php echo ( ( $settings['google_nap_method'] ?? 'kg' ) === 'gbp' ) ? '' : 'display:none;'; ?>">
							<?php if ( $gbp_connected ) : ?>
								<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
									<span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Google Business Profile connected', 'twt-aeo-ultimate' ); ?></span>
									<a href="<?php echo esc_url( $gbp_disconnect_url ); ?>" class="button button-small"><?php esc_html_e( 'Disconnect', 'twt-aeo-ultimate' ); ?></a>
								</div>
								<button type="button" id="lp_nap_check_gbp" class="button button-primary"><?php esc_html_e( 'Check Google Business Profile', 'twt-aeo-ultimate' ); ?></button>
							<?php else : ?>
								<?php if ( ! TWTAEO_Google_OAuth::get_credentials()['client_id'] ?? '' ) : ?>
									<div class="notice notice-warning inline"><p>
										<?php echo wp_kses_post( sprintf(
											/* translators: link to Command Center */
											__( 'Google OAuth credentials are not configured. Set up your Client ID and Secret in the <a href="%s">Command Center</a> first.', 'twt-aeo-ultimate' ),
											esc_url( admin_url( 'admin.php?page=twt-aeo-command-center' ) )
										) ); ?>
									</p></div>
								<?php elseif ( ! is_wp_error( $gbp_oauth_url ) ) : ?>
									<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Authorize access to your Google Business Profile. This uses the same Google OAuth app you configured in the Command Center but requests the additional Business Profile permission.', 'twt-aeo-ultimate' ); ?></p>
									<a href="<?php echo esc_url( $gbp_oauth_url ); ?>" class="button button-primary"><?php esc_html_e( 'Connect Google Business Profile', 'twt-aeo-ultimate' ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<!-- Google NAP results -->
						<div id="lp_google_nap_result" style="margin-top:16px;display:none;"></div>
					</div>

					<!-- Bing section -->
					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:16px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Bing NAP Check', 'twt-aeo-ultimate' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Uses the Bing Maps Local Search API to find your business listing and compare NAP. Requires a free Bing Maps key (no Azure account needed).', 'twt-aeo-ultimate' ); ?></p>

						<table class="form-table" role="presentation" style="margin-bottom:0;">
							<tr>
								<th style="width:180px;"><label for="lp_bing_key"><?php esc_html_e( 'Bing Maps API Key', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" id="lp_bing_key" name="bing_api_key" value="<?php echo esc_attr( $settings['bing_api_key'] ?? '' ); ?>" class="regular-text" placeholder="Av3kD...">
									<details style="margin-top:8px;">
										<summary style="cursor:pointer;font-size:12px;color:#2271b1;"><?php esc_html_e( 'How to get a free Bing Maps API key', 'twt-aeo-ultimate' ); ?></summary>
										<ol style="font-size:12px;color:#555;margin:8px 0 0 16px;line-height:1.8;">
											<li><?php echo wp_kses_post( __( 'Go to <strong>bingmapsportal.com</strong> and sign in with a Microsoft account (free to create).', 'twt-aeo-ultimate' ) ); ?></li>
											<li><?php echo wp_kses_post( __( 'Click <strong>My Keys</strong> in the top menu, then <strong>Create a new key</strong>.', 'twt-aeo-ultimate' ) ); ?></li>
											<li><?php esc_html_e( 'Application name: your site name. Key type: Basic. Application type: Dev/Test.', 'twt-aeo-ultimate' ); ?></li>
											<li><?php esc_html_e( 'Click Create, copy your key, and paste it above.', 'twt-aeo-ultimate' ); ?></li>
											<li><?php esc_html_e( 'Free tier includes 125,000 transactions/year — more than enough.', 'twt-aeo-ultimate' ); ?></li>
										</ol>
									</details>
								</td>
							</tr>
						</table>

						<div style="margin-top:12px;">
							<button type="button" id="lp_nap_check_bing" class="button button-primary"><?php esc_html_e( 'Check Bing Maps', 'twt-aeo-ultimate' ); ?></button>
						</div>

						<div id="lp_bing_nap_result" style="margin-top:16px;display:none;"></div>
					</div>

				<?php /* ── TAB: Output Settings ── */ ?>
				<?php elseif ( 'output' === $active_tab ) : ?>

					<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:20px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Schema Output', 'twt-aeo-ultimate' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Choose which pages the LocalBusiness JSON-LD is injected on.', 'twt-aeo-ultimate' ); ?></p>

						<table class="form-table" role="presentation">
							<tr>
								<th><?php esc_html_e( 'Output On', 'twt-aeo-ultimate' ); ?></th>
								<td>
									<?php $output_on = $settings['output_on'] ?? 'front_page'; ?>
									<?php foreach ( array(
										'front_page' => 'Front page only (recommended)',
										'all'        => 'Every page on the site',
										'specific'   => 'Specific page IDs only',
									) as $val => $label ) : ?>
										<label style="display:block;margin-bottom:6px;">
											<input type="radio" name="output_on" value="<?php echo esc_attr( $val ); ?>" <?php checked( $output_on, $val ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr id="lp_output_pages_row" style="<?php echo ( 'specific' === $output_on ) ? '' : 'display:none;'; ?>">
								<th><label for="lp_output_pages"><?php esc_html_e( 'Page IDs', 'twt-aeo-ultimate' ); ?></label></th>
								<td>
									<input type="text" id="lp_output_pages" name="output_pages" value="<?php echo esc_attr( $settings['output_pages'] ?? '' ); ?>" class="regular-text" placeholder="1, 24, 56">
									<p class="description"><?php esc_html_e( 'Comma-separated post/page IDs.', 'twt-aeo-ultimate' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<?php if ( ! empty( $settings['business_name'] ) ) : ?>
						<div class="twt-aeo-card" style="max-width:800px;padding:24px;margin-top:16px;">
							<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;"><?php esc_html_e( 'Schema Preview', 'twt-aeo-ultimate' ); ?></h2>
							<pre style="background:#f6f7f7;padding:16px;overflow:auto;font-size:12px;max-height:400px;border-radius:3px;"><?php
								echo esc_html( wp_json_encode( TWTAEO_Local_Pack::build_schema( $settings ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
							?></pre>
						</div>
					<?php endif; ?>

				<?php /* ── TAB: Entity Validation ── */ ?>
				<?php elseif ( 'entity' === $active_tab ) : ?>

					<?php
					$business_type    = $settings['business_type'] ?? 'LocalBusiness';
					$industry_group   = self::get_industry_group( $business_type );
					$directories      = self::get_directories_for_type( $business_type );
					$type_label       = TWTAEO_Local_Pack::get_subtypes()[ $business_type ] ?? $business_type;
					$show_universal   = ( 'generic' !== $industry_group );
					$universal_dirs   = self::get_universal_directories();
					?>

					<div class="twt-aeo-card" style="max-width:860px;padding:24px;margin-top:20px;">
						<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
							<?php esc_html_e( 'Entity Validation Directories', 'twt-aeo-ultimate' ); ?>
						</h2>
						<p style="margin-top:0;color:#3c434a;">
							<?php
							$profile_url = add_query_arg( array( 'page' => 'twt-aeo-local-pack', 'tab' => 'profile' ), admin_url( 'admin.php' ) );
							$type_html   = '<strong>' . esc_html( $type_label ) . '</strong>'
								. '&nbsp;<a href="' . esc_url( $profile_url ) . '" style="font-size:12px;font-weight:normal;">'
								. esc_html__( 'Edit', 'twt-aeo-ultimate' )
								. '</a>';
							printf(
								/* translators: %s business type label with edit link */
								wp_kses_post( __( 'Based on your business type (%s), these are the top 5 free directories where a listing builds trusted entity signals for AI search engines. Consistent NAP data across authoritative directories tells AI assistants — and Google\'s Knowledge Graph — that your business is real, established, and worth surfacing in answers.', 'twt-aeo-ultimate' ) ),
								wp_kses( $type_html, array( 'strong' => array(), 'a' => array( 'href' => array(), 'style' => array() ) ) )
							);
							?>
						</p>
					</div>

					<?php
					// Reusable closure for rendering a single directory card.
					$dir_card = function( $dir, $i, $accent = '#2271b1' ) {
						?>
						<div class="twt-aeo-card" style="padding:20px;display:flex;flex-direction:column;gap:10px;">
							<div style="display:flex;align-items:center;gap:10px;">
								<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:<?php echo esc_attr( $accent ); ?>;color:#fff;border-radius:50%;font-size:13px;font-weight:700;flex-shrink:0;"><?php echo esc_html( $i + 1 ); ?></span>
								<strong style="font-size:14px;"><?php echo esc_html( $dir['name'] ); ?></strong>
							</div>
							<p style="margin:0;font-size:13px;color:#3c434a;line-height:1.5;"><?php echo esc_html( $dir['why'] ); ?></p>
							<div style="margin-top:auto;padding-top:8px;">
								<a href="<?php echo esc_url( $dir['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="font-size:12px;">
									<?php esc_html_e( 'Claim / List Your Business', 'twt-aeo-ultimate' ); ?> &rarr;
								</a>
							</div>
						</div>
						<?php
					};
					?>

					<div style="max-width:860px;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px;">
						<?php foreach ( $directories as $i => $dir ) : ?>
							<?php $dir_card( $dir, $i ); ?>
						<?php endforeach; ?>
					</div>

					<?php if ( $show_universal ) : ?>

						<div class="twt-aeo-card" style="max-width:860px;padding:20px 24px;margin-top:28px;border-left:4px solid #1d6b21;">
							<h3 style="margin:0 0 4px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
								<?php esc_html_e( 'Essential Local Listings — Every Brick-and-Mortar Business', 'twt-aeo-ultimate' ); ?>
							</h3>
							<p style="margin:0;font-size:13px;color:#3c434a;">
								<?php esc_html_e( 'Regardless of your industry, these five platforms form the foundation of local entity validation. AI engines cross-reference them to confirm your business exists and that your NAP is consistent.', 'twt-aeo-ultimate' ); ?>
							</p>
						</div>

						<div style="max-width:860px;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px;">
							<?php foreach ( $universal_dirs as $i => $dir ) : ?>
								<?php $dir_card( $dir, $i, '#1d6b21' ); ?>
							<?php endforeach; ?>
						</div>

					<?php endif; ?>

					<?php /* ── Review Sites ── */ ?>
					<?php
					$review_sites = self::get_review_sites_for_group( $industry_group );
					if ( ! empty( $review_sites ) ) :
						$importance_cfg = array(
							'critical' => array( 'label' => __( 'Must Have', 'twt-aeo-ultimate' ),  'bg' => '#fee2e2', 'color' => '#991b1b' ),
							'high'     => array( 'label' => __( 'High Value', 'twt-aeo-ultimate' ), 'bg' => '#dbeafe', 'color' => '#1e40af' ),
							'medium'   => array( 'label' => __( 'Recommended', 'twt-aeo-ultimate' ),'bg' => '#f0fdf4', 'color' => '#166534' ),
							'low'      => array( 'label' => __( 'Optional', 'twt-aeo-ultimate' ),   'bg' => '#f1f5f9', 'color' => '#475569' ),
						);
					?>

					<div class="twt-aeo-card" style="max-width:860px;padding:20px 24px;margin-top:28px;border-left:4px solid #f59e0b;">
						<div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;">
							<h3 style="margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#666;">
								&#9733; <?php esc_html_e( 'Customer Review Platforms', 'twt-aeo-ultimate' ); ?>
							</h3>
							<span style="font-size:12px;color:#6b7280;">
								<?php
								printf(
									/* translators: %s: business type label */
									esc_html__( 'Best review sites for a %s', 'twt-aeo-ultimate' ),
									'<strong>' . esc_html( $type_label ) . '</strong>'
								);
								?>
							</span>
						</div>
						<p style="margin:8px 0 0;font-size:13px;color:#3c434a;">
							<?php esc_html_e( 'Reviews on these platforms feed star ratings into AI search results and Google\'s Knowledge Panel. Consistent, high-quality reviews across these sites directly improve your Local Pack ranking and AEO visibility.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>

					<div style="max-width:860px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-top:16px;">
						<?php foreach ( $review_sites as $site ) :
							$imp = $importance_cfg[ $site['importance'] ] ?? $importance_cfg['low'];
						?>
						<div class="twt-aeo-card" style="padding:16px 18px;display:flex;flex-direction:column;gap:8px;">
							<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
								<div style="display:flex;align-items:center;gap:8px;">
									<span style="font-size:18px;line-height:1;"><?php echo esc_html( $site['icon'] ); ?></span>
									<strong style="font-size:13px;color:#1e293b;"><?php echo esc_html( $site['name'] ); ?></strong>
								</div>
								<span style="flex-shrink:0;background:<?php echo esc_attr( $imp['bg'] ); ?>;color:<?php echo esc_attr( $imp['color'] ); ?>;border-radius:4px;font-size:10px;font-weight:700;padding:2px 8px;white-space:nowrap;">
									<?php echo esc_html( $imp['label'] ); ?>
								</span>
							</div>
							<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.5;"><?php echo esc_html( $site['why'] ); ?></p>
							<a href="<?php echo esc_url( $site['url'] ); ?>"
							   target="_blank"
							   rel="noopener noreferrer"
							   style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#2563eb;text-decoration:none;margin-top:auto;">
								<?php esc_html_e( 'Set Up Profile', 'twt-aeo-ultimate' ); ?> <span style="font-size:10px;">&#8599;</span>
							</a>
						</div>
						<?php endforeach; ?>
					</div>
					<p style="max-width:860px;margin:8px 0 0;font-size:11px;color:#94a3b8;">
						<?php esc_html_e( 'Links open the business/pro sign-up page in a new tab. Google Business Profile always applies — claim it first if you haven\'t already.', 'twt-aeo-ultimate' ); ?>
					</p>

					<?php endif; ?>

				<?php endif; ?>

				<!-- Save button (not shown on NAP tab — NAP checks don't need form save, but API keys do) -->
				<?php if ( 'profile' === $active_tab || 'output' === $active_tab || 'nap' === $active_tab ) : ?>
					<p style="margin-top:16px;">
						<input type="submit" name="twtaeo_local_pack_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'twt-aeo-ultimate' ); ?>">
					</p>
				<?php endif; ?>
			</form>
		</div>

		<?php
		ob_start();
		?>
		// Show/hide output_pages row based on radio selection
		document.querySelectorAll('input[name="output_on"]').forEach(function(radio) {
			radio.addEventListener('change', function() {
				var row = document.getElementById('lp_output_pages_row');
				if (row) row.style.display = (this.value === 'specific') ? '' : 'none';
			});
		});

		// Highlight selected Google method card border
		document.querySelectorAll('.lp-method-radio').forEach(function(radio) {
			radio.addEventListener('change', function() {
				document.querySelectorAll('.lp-method-card').forEach(function(card) {
					card.style.borderColor = '#c3c4c7';
				});
				this.closest('.lp-method-card').style.borderColor = '#2271b1';
				document.getElementById('lp_kg_section').style.display  = (document.getElementById('lp_method_kg').checked)  ? '' : 'none';
				document.getElementById('lp_gbp_section').style.display = (document.getElementById('lp_method_gbp').checked) ? '' : 'none';
			});
		});
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	/**
	 * Return the top 5 free directories for a given schema.org business type.
	 * Falls back to universal directories when no industry-specific set is found.
	 *
	 * @param string $type schema.org subtype slug (e.g. 'Plumber', 'Restaurant').
	 * @return array
	 */
	/**
	 * Return the industry group slug for a given schema.org business type.
	 *
	 * @param string $type
	 * @return string Group slug, or 'generic' if no match.
	 */
	public static function get_industry_group( $type ) {
		$industry_map = array(
			'home_services'   => array(
				'Plumber', 'Electrician', 'HVACBusiness', 'Locksmith',
				'RoofingContractor', 'PaintingContractor', 'GeneralContractor',
				'Carpenter', 'Landscaper', 'LandscapingBusiness', 'MovingCompany',
				'CleaningService', 'Contractor', 'HomeAndConstructionBusiness',
			),
			'restaurant_food' => array(
				'Restaurant', 'FastFoodRestaurant', 'CafeOrCoffeeShop', 'Bakery',
				'BarOrPub', 'Winery', 'Brewery', 'Distillery', 'FoodEstablishment',
				'IceCreamShop', 'FoodTruck',
			),
			'legal'           => array( 'LegalService', 'Lawyer', 'Notary', 'Attorney' ),
			'healthcare'      => array(
				'MedicalBusiness', 'Physician', 'Dentist', 'Optician', 'Optometrist',
				'Chiropractor', 'PhysicalTherapist', 'Veterinary', 'VeterinaryCare',
				'Hospital', 'MedicalClinic', 'Pharmacy', 'Psychiatrist',
				'MentalHealthClinic', 'Midwife', 'Dermatologist', 'EmergencyService',
			),
			'real_estate'     => array( 'RealEstateAgent', 'RealEstateBroker' ),
			'automotive'      => array(
				'AutoDealer', 'AutoRepair', 'AutoBodyShop', 'CarWash',
				'GasStation', 'MotorcycleDealer', 'AutoPartsStore',
			),
			'beauty_wellness' => array(
				'BeautySalon', 'HairSalon', 'NailSalon', 'DaySpa',
				'HealthClub', 'ExerciseGym', 'MassageTherapist', 'TattooParlor',
			),
			'financial'       => array(
				'FinancialService', 'AccountingService', 'TaxPreparationService',
				'InsuranceAgency', 'BankOrCreditUnion', 'MortgageBroker',
			),
			'education'       => array(
				'School', 'EducationalOrganization', 'CollegeOrUniversity',
				'Preschool', 'ElementarySchool', 'HighSchool', 'ChildCare',
				'Library', 'TutoringService',
			),
			'lodging'         => array(
				'Hotel', 'Motel', 'BedAndBreakfast', 'Hostel',
				'LodgingBusiness', 'CampingPitch', 'Campground',
			),
			'pet_services'    => array( 'AnimalShelter', 'PetStore', 'Kennel' ),
		);

		foreach ( $industry_map as $group => $types ) {
			if ( in_array( $type, $types, true ) ) {
				return $group;
			}
		}
		return 'generic';
	}

	/**
	 * Return the five universal local directories shown for every brick-and-mortar business.
	 *
	 * @return array
	 */
	public static function get_universal_directories() {
		return array(
			array(
				'name' => 'Google Business Profile',
				'url'  => 'https://business.google.com/',
				'why'  => 'The single most important free listing for any local business. Your GBP data feeds Google\'s Knowledge Panel, Maps, and is the primary NAP source used by AI engines to verify your entity.',
			),
			array(
				'name' => 'Bing Places for Business',
				'url'  => 'https://www.bingplaces.com/',
				'why'  => 'Bing Places powers Microsoft\'s Bing Maps and Copilot AI answers. As Copilot gains market share in AI search, a verified Bing Places listing is increasingly critical for AI entity coverage.',
			),
			array(
				'name' => 'Yelp for Business',
				'url'  => 'https://biz.yelp.com/',
				'why'  => 'Yelp is a primary data source for Apple Maps and Siri. When users ask voice assistants for local recommendations, Yelp listings and reviews are the dominant data source returned.',
			),
			array(
				'name' => 'Apple Maps Connect',
				'url'  => 'https://mapsconnect.apple.com/',
				'why'  => 'Apple Maps powers Siri and Spotlight search on all Apple devices. Claiming your listing here ensures AI assistants on iPhones and Macs can surface your business in local queries.',
			),
			array(
				'name' => 'Better Business Bureau',
				'url'  => 'https://www.bbb.org/start-with-trust/',
				'why'  => 'BBB accreditation is a recognized trust signal for AI engines evaluating business credibility. It is one of the few directories that AI systems treat as an E-E-A-T verification source.',
			),
		);
	}

	public static function get_directories_for_type( $type ) {

		$group = self::get_industry_group( $type );

		$all = array(

			'home_services' => array(
				array(
					'name' => 'Angi (Angie\'s List)',
					'url'  => 'https://www.angi.com/pro/signup',
					'why'  => 'The dominant marketplace for home service pros. Angi profiles surface in Google\'s Local Services Ads and are indexed by AI answer engines as authoritative contractor data.',
				),
				array(
					'name' => 'Thumbtack',
					'url'  => 'https://www.thumbtack.com/pro',
					'why'  => 'High-intent lead platform with rich structured markup. Thumbtack profiles rank independently and pass strong entity signals — NAP, services, and reviews — to Google and AI engines.',
				),
				array(
					'name' => 'HomeAdvisor (Angi Ads)',
					'url'  => 'https://pro.homeadvisor.com/',
					'why'  => 'Owned by the same parent as Angi. A separate listing doubles your citation footprint and reinforces NAP consistency across the entire Angi network.',
				),
				array(
					'name' => 'Houzz',
					'url'  => 'https://www.houzz.com/for-professionals',
					'why'  => 'Essential for renovation, design, and home improvement pros. Houzz profiles rank strongly for branded searches and carry rich structured data that AI engines treat as trusted entity signals.',
				),
				array(
					'name' => 'BuildZoom',
					'url'  => 'https://www.buildzoom.com/contractor-signup',
					'why'  => 'Aggregates contractor license data from public records. Being listed here with accurate data helps AI assistants verify credentials when answering "is this contractor licensed?"',
				),
			),

			'restaurant_food' => array(
				array(
					'name' => 'Yelp for Business',
					'url'  => 'https://biz.yelp.com/',
					'why'  => 'Yelp is a primary data source for Apple Maps and Siri. AI assistants that pull from Apple\'s ecosystem will surface your Yelp data when recommending local restaurants.',
				),
				array(
					'name' => 'TripAdvisor',
					'url'  => 'https://www.tripadvisor.com/Owners',
					'why'  => 'One of the most-cited sources in AI travel and dining recommendations. A complete TripAdvisor listing with photos and reviews strongly influences AI-generated restaurant suggestions.',
				),
				array(
					'name' => 'OpenTable',
					'url'  => 'https://restaurant.opentable.com/',
					'why'  => 'OpenTable reservation data is used by Google to show booking actions directly in search results. AI assistants increasingly surface OpenTable-listed restaurants for "book a table" queries.',
				),
				array(
					'name' => 'Zomato',
					'url'  => 'https://www.zomato.com/business',
					'why'  => 'A globally-indexed food directory crawled by major AI engines. Consistent menu and NAP data here reinforces your entity\'s completeness in the Knowledge Graph.',
				),
				array(
					'name' => 'Foursquare for Business',
					'url'  => 'https://business.foursquare.com/',
					'why'  => 'Foursquare powers location data for dozens of apps and AI platforms. Claiming your listing here extends your entity\'s reach to services that rely on Foursquare\'s Places API.',
				),
			),

			'legal' => array(
				array(
					'name' => 'Avvo',
					'url'  => 'https://www.avvo.com/for-lawyers',
					'why'  => 'Avvo profiles are heavily indexed and appear in AI answers to "find a lawyer near me" queries. Its structured data includes practice areas, bar status, and peer endorsements.',
				),
				array(
					'name' => 'FindLaw Attorney Directory',
					'url'  => 'https://lawyers.findlaw.com/',
					'why'  => 'Operated by Thomson Reuters and trusted as an authoritative legal source. AI engines frequently cite FindLaw when answering legal questions, boosting associated attorney entities.',
				),
				array(
					'name' => 'Justia Lawyer Directory',
					'url'  => 'https://lawyers.justia.com/',
					'why'  => 'Justia is heavily crawled by Google and AI systems. Its attorney profiles link to bar registration, case law authored by the attorney, and firm details — strong E-E-A-T signals.',
				),
				array(
					'name' => 'Martindale-Hubbell',
					'url'  => 'https://www.martindale.com/attorneys/claim-your-profile/',
					'why'  => 'Over 150 years old and widely recognized as the legal industry\'s authority. An AV Preeminent rating here carries significant E-E-A-T weight for AI credibility evaluation.',
				),
				array(
					'name' => 'Super Lawyers',
					'url'  => 'https://www.superlawyers.com/lawyers/',
					'why'  => 'A peer-reviewed rating service that AI engines use to validate attorney expertise and trustworthiness. Being listed signals recognized authority in your practice area.',
				),
			),

			'healthcare' => array(
				array(
					'name' => 'Healthgrades',
					'url'  => 'https://www.healthgrades.com/office/claim-profile',
					'why'  => 'The most-visited healthcare directory in the US. Healthgrades profiles appear in AI health queries and supply Google\'s Knowledge Panel with physician specialties, education, and hospital affiliations.',
				),
				array(
					'name' => 'WebMD Doctor Finder',
					'url'  => 'https://doctor.webmd.com/physician/claim',
					'why'  => 'WebMD is a top-cited source in AI medical answers. A verified provider profile here ties your entity to medically-authoritative content, boosting E-E-A-T scores.',
				),
				array(
					'name' => 'Vitals',
					'url'  => 'https://www.vitals.com/about/claim_your_profile',
					'why'  => 'Vitals aggregates board certifications, malpractice history, and patient reviews. AI engines treat it as a secondary verification source for healthcare practitioner entities.',
				),
				array(
					'name' => 'Zocdoc',
					'url'  => 'https://www.zocdoc.com/about/for-providers/',
					'why'  => 'Zocdoc surfaces directly in Google\'s booking experience for healthcare providers. AI assistants recommend Zocdoc-listed providers for appointment booking queries.',
				),
				array(
					'name' => 'RateMDs',
					'url'  => 'https://www.ratemds.com/signup/doctor/',
					'why'  => 'An international healthcare review platform indexed across multiple AI training datasets. Consistent specialty and location data here broadens your entity\'s global citation footprint.',
				),
			),

			'real_estate' => array(
				array(
					'name' => 'Zillow Premier Agent',
					'url'  => 'https://www.zillow.com/agent-resources/agent-toolkit/',
					'why'  => 'Zillow is the most-visited real estate site in the US. AI assistants asked about agents in a specific area routinely surface Zillow profiles with transaction history and reviews.',
				),
				array(
					'name' => 'Realtor.com Agent Profile',
					'url'  => 'https://www.realtor.com/realestateagents/claim/',
					'why'  => 'Operated by the National Association of Realtors and syndicated across hundreds of broker sites. A complete profile here appears in AI real estate queries as an authoritative entity.',
				),
				array(
					'name' => 'Homes.com Agent Directory',
					'url'  => 'https://agent.homes.com/',
					'why'  => 'Homes.com is rapidly expanding its market share. Its structured agent profiles are indexed by AI engines as a growing citation source for real estate entity validation.',
				),
				array(
					'name' => 'Trulia',
					'url'  => 'https://www.trulia.com/real_estate/agent-profile/',
					'why'  => 'Owned by Zillow Group — a Trulia listing reinforces your NAP and agent data across both platforms simultaneously, multiplying your citation value from a single source.',
				),
				array(
					'name' => 'LoopNet (Commercial)',
					'url'  => 'https://www.loopnet.com/for-brokers/',
					'why'  => 'The leading commercial real estate marketplace. If you handle any commercial transactions, a LoopNet profile expands your entity into a different but high-authority index.',
				),
			),

			'automotive' => array(
				array(
					'name' => 'Cars.com Dealer/Shop Profile',
					'url'  => 'https://www.cars.com/dealers/advertising/',
					'why'  => 'Cars.com is a primary source for AI car-buying recommendations. Dealer and repair shop profiles here feed structured data directly into Google\'s automotive knowledge graph.',
				),
				array(
					'name' => 'RepairPal',
					'url'  => 'https://repairpal.com/certified/apply',
					'why'  => 'RepairPal certification appears in AI answers to "find a trusted mechanic near me." Its cost estimator data makes it a frequently-cited source in AI automotive advice.',
				),
				array(
					'name' => 'CarGurus',
					'url'  => 'https://www.cargurus.com/Cars/dealer-signup.action',
					'why'  => 'One of the fastest-growing auto marketplaces, heavily indexed by Google. A dealer profile here captures high-intent buyers and reinforces entity trust for auto-related AI queries.',
				),
				array(
					'name' => 'CARFAX Service Shop',
					'url'  => 'https://www.carfax.com/service/',
					'why'  => 'CARFAX is a trusted automotive authority. Being listed as a verified service shop associates your entity with CARFAX\'s credibility when AI engines answer "where can I service my car?"',
				),
			),

			'beauty_wellness' => array(
				array(
					'name' => 'StyleSeat',
					'url'  => 'https://www.styleseat.com/beauty-professionals/',
					'why'  => 'The leading booking platform for beauty pros. StyleSeat profiles surface in AI "book a haircut near me" queries and pass structured appointment data to Google\'s booking graph.',
				),
				array(
					'name' => 'Vagaro',
					'url'  => 'https://www.vagaro.com/pro',
					'why'  => 'Vagaro combines scheduling, reviews, and marketing in one platform. Its structured business data is indexed by Google and AI engines as a verified beauty and wellness entity.',
				),
				array(
					'name' => 'Fresha',
					'url'  => 'https://www.fresha.com/for-business',
					'why'  => 'A commission-free booking platform growing rapidly in AI indexing. Fresha\'s structured salon profiles appear in Google\'s appointment booking features and voice search results.',
				),
				array(
					'name' => 'Booksy',
					'url'  => 'https://booksy.com/pro/',
					'why'  => 'Booksy is integrated with Google\'s Reserve with Google feature. When AI recommends a salon and the user wants to book, Booksy listings enable direct booking — a strong AEO advantage.',
				),
				array(
					'name' => 'Mindbody',
					'url'  => 'https://www.mindbodyonline.com/business',
					'why'  => 'The dominant platform for fitness studios and spas. Mindbody listings flow into Google\'s fitness and wellness knowledge graph, making them a foundational entity citation.',
				),
			),

			'financial' => array(
				array(
					'name' => 'NAPFA Advisor Directory',
					'url'  => 'https://www.napfa.org/find-an-advisor',
					'why'  => 'The National Association of Personal Financial Advisors is a trusted fiduciary authority. AI engines cite NAPFA listings when recommending fee-only financial advisors, signaling E-E-A-T.',
				),
				array(
					'name' => 'CFP Board Professional Search',
					'url'  => 'https://www.cfp.net/for-cfp-professionals',
					'why'  => 'CFP certification is the gold standard in financial planning. Being searchable in the CFP Board\'s directory ties your entity to a regulatory body AI engines recognize as authoritative.',
				),
				array(
					'name' => 'NerdWallet Advisor Match',
					'url'  => 'https://www.nerdwallet.com/partners/advisor-match',
					'why'  => 'NerdWallet is one of the most-cited financial sources in AI answers. Advisor profiles here appear in AI financial planning recommendations, especially for "find a financial advisor" queries.',
				),
				array(
					'name' => 'SmartAsset Financial Advisor',
					'url'  => 'https://smartasset.com/financial-advisor/list-your-practice',
					'why'  => 'SmartAsset matches users with local advisors and is heavily indexed for financial queries. Its structured data feeds AI systems that recommend advisors based on specialty and location.',
				),
				array(
					'name' => 'XY Planning Network',
					'url'  => 'https://www.xyplanningnetwork.com/join/',
					'why'  => 'A vetted directory of fee-only fiduciary advisors. AI engines treating financial advice queries favor advisors listed in credentialed networks like XYPN over uncredentialed listings.',
				),
			),

			'education' => array(
				array(
					'name' => 'GreatSchools',
					'url'  => 'https://www.greatschools.org/gk/claim-school-profile/',
					'why'  => 'GreatSchools is cited in Google\'s school Knowledge Panels and AI educational queries. A complete profile here gives AI engines structured data on programs, grades served, and ratings.',
				),
				array(
					'name' => 'Niche',
					'url'  => 'https://colleges.niche.com/claim/',
					'why'  => 'Niche.com powers rankings cited by AI when answering "best schools near me." A verified profile adds tuition, acceptance rates, and program data that AI engines surface directly.',
				),
				array(
					'name' => 'Tutor.com / Wyzant',
					'url'  => 'https://www.wyzant.com/tutors/jobs',
					'why'  => 'Wyzant is the largest tutor marketplace and is indexed by AI assistants for "find a tutor" queries. A profile here extends your entity into the tutoring and supplemental education graph.',
				),
				array(
					'name' => 'Classgap',
					'url'  => 'https://www.classgap.com/en/teacher',
					'why'  => 'An international online tutoring directory indexed across multiple languages. Listing here broadens your entity\'s footprint beyond English-language AI training data.',
				),
			),

			'lodging' => array(
				array(
					'name' => 'TripAdvisor for Hotels',
					'url'  => 'https://www.tripadvisor.com/Owners',
					'why'  => 'TripAdvisor is the single most-cited travel source in AI recommendations. A complete listing with photos, amenities, and reviews is essential for appearing in AI-generated hotel suggestions.',
				),
				array(
					'name' => 'Booking.com Partner Hub',
					'url'  => 'https://partner.booking.com/',
					'why'  => 'Booking.com\'s structured property data feeds Google\'s hotel graph. AI assistants asking "hotels near X" pull Booking.com availability and reviews as a primary data source.',
				),
				array(
					'name' => 'Expedia Partner Solutions',
					'url'  => 'https://welcome.expediagroup.com/en/solutions',
					'why'  => 'Expedia powers Vrbo, Hotels.com, and dozens of OTAs. One listing here distributes your entity data across the entire Expedia network, multiplying your AI citation coverage.',
				),
				array(
					'name' => 'Google Hotel Center',
					'url'  => 'https://www.google.com/hotelprices/contact/',
					'why'  => 'Connecting directly to Google\'s Hotel Center ensures your pricing and availability appear in Google\'s AI-powered hotel search and within Gemini travel planning answers.',
				),
				array(
					'name' => 'Airbnb / Vrbo (Vacation Rentals)',
					'url'  => 'https://www.airbnb.com/host/homes',
					'why'  => 'For non-hotel accommodations, Airbnb and Vrbo are the primary entity sources for AI travel queries. Listing here is required for AI tools to recognize and recommend your property.',
				),
			),

			'pet_services' => array(
				array(
					'name' => 'Rover.com',
					'url'  => 'https://www.rover.com/sitter-signup/',
					'why'  => 'Rover is the dominant platform for pet sitting and dog walking. AI assistants answering "pet sitter near me" routinely surface Rover profiles as authoritative local pet-care entities.',
				),
				array(
					'name' => 'Wag! for Businesses',
					'url'  => 'https://wagwalking.com/partner',
					'why'  => 'Wag! is the second-largest pet services platform and is indexed heavily for dog walking and grooming queries. A profile here reinforces your pet-care entity across two major platforms.',
				),
				array(
					'name' => 'PetMD Veterinarian Directory',
					'url'  => 'https://www.petmd.com/find-a-vet',
					'why'  => 'PetMD is a medically-authoritative pet health source cited by AI in animal health queries. Veterinary and animal shelter listings here inherit PetMD\'s E-E-A-T credibility.',
				),
				array(
					'name' => 'Yelp for Business',
					'url'  => 'https://biz.yelp.com/',
					'why'  => 'Yelp powers Apple Maps and Siri for local service queries. Pet businesses with strong Yelp reviews are surfaced by AI voice assistants when users ask Siri for nearby groomers or vets.',
				),
				array(
					'name' => 'BringFido',
					'url'  => 'https://www.bringfido.com/add/',
					'why'  => 'A niche but highly-indexed directory for pet-friendly businesses. AI travel and lifestyle assistants cite BringFido when recommending pet-friendly services, hotels, and activities.',
				),
			),

		);

		return $all[ $group ] ?? self::get_universal_directories();
	}

	/**
	 * Return the recommended customer review platforms for a given industry group.
	 * Each entry: name, icon (emoji), importance (critical|high|medium|low), why, url.
	 *
	 * @param string $group  Industry group slug from get_industry_group().
	 * @return array
	 */
	public static function get_review_sites_for_group( $group ) {
		$map = array(

			'home_services' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Star rating appears in the Local Pack — first thing homeowners see before clicking.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Angi',                    'icon' => '🔧', 'importance' => 'high',     'why' => 'Angi reviews feed directly into Google Local Services Ads quality scores.',     'url' => 'https://pro.angi.com' ),
				array( 'name' => 'Thumbtack',               'icon' => '📌', 'importance' => 'high',     'why' => 'Profiles with 10+ reviews rank significantly higher in Thumbtack search.',       'url' => 'https://www.thumbtack.com/pro' ),
				array( 'name' => 'Houzz',                   'icon' => '🏠', 'importance' => 'high',     'why' => 'Essential for remodeling and design — Houzz reviews appear in AI home queries.',  'url' => 'https://www.houzz.com/for-pros' ),
				array( 'name' => 'Yelp',                    'icon' => '🍽', 'importance' => 'medium',   'why' => 'Powers Apple Maps — homeowners on iPhones asking Siri will see your Yelp rating.', 'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Nextdoor',                'icon' => '🏘', 'importance' => 'medium',   'why' => 'Word-of-mouth at neighborhood scale — high-trust referrals from nearby residents.', 'url' => 'https://business.nextdoor.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Community groups drive local referrals; Facebook reviews appear in branded searches.', 'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'why' => 'BBB accreditation is a trust signal AI engines use to evaluate business credibility.', 'url' => 'https://www.bbb.org/businesses' ),
			),

			'restaurant_food' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Star rating drives click-through on every "restaurants near me" query.',                  'url' => 'https://business.google.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🍽', 'importance' => 'critical', 'why' => 'Diners check Yelp before deciding where to eat — also powers Apple Maps and Siri.',     'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'TripAdvisor',             'icon' => '✈', 'importance' => 'high',     'why' => 'Most-cited travel source in AI recommendations — essential for tourist traffic.',          'url' => 'https://www.tripadvisor.com/owners' ),
				array( 'name' => 'OpenTable',               'icon' => '🍷', 'importance' => 'high',     'why' => 'Verified diner reviews and reservation data appear in Google\'s dining action cards.',     'url' => 'https://restaurant.opentable.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Social check-ins and reviews reach a local audience through friends\' feeds.',            'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Zomato',                  'icon' => '🥘', 'importance' => 'medium',   'why' => 'Globally indexed food directory — boosts entity completeness in the Knowledge Graph.',    'url' => 'https://www.zomato.com/business' ),
			),

			'legal' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Most clients search "lawyer near me" on Google — your star rating is the first filter.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Avvo',                    'icon' => '⚖', 'importance' => 'critical', 'why' => 'Avvo ratings appear directly in AI answers to "find a lawyer" queries.',                 'url' => 'https://www.avvo.com/attorneys' ),
				array( 'name' => 'Martindale-Hubbell',      'icon' => '🏛', 'importance' => 'high',     'why' => 'Peer-rated by other attorneys — a high trust signal for AI and cautious clients.',        'url' => 'https://www.martindale.com' ),
				array( 'name' => 'FindLaw',                 'icon' => '🔍', 'importance' => 'high',     'why' => 'Thomson Reuters-backed directory AI engines cite as authoritative legal content.',         'url' => 'https://lawyer.findlaw.com' ),
				array( 'name' => 'Super Lawyers',           'icon' => '🏆', 'importance' => 'medium',   'why' => 'Peer-reviewed rating AI systems use to validate attorney expertise and authority.',        'url' => 'https://www.superlawyers.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Community referrals and social proof for consumer-facing practices.',                     'url' => 'https://www.facebook.com/business' ),
			),

			'healthcare' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Patients read Google reviews before booking — rating directly drives appointment volume.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Healthgrades',            'icon' => '🩺', 'importance' => 'critical', 'why' => 'Top healthcare review platform — supplies Google\'s Knowledge Panel with provider data.',   'url' => 'https://www.healthgrades.com/pro' ),
				array( 'name' => 'Zocdoc',                  'icon' => '📅', 'importance' => 'high',     'why' => 'Booking + reviews in one — AI assistants surface Zocdoc for appointment queries.',         'url' => 'https://www.zocdoc.com/about/providers' ),
				array( 'name' => 'WebMD',                   'icon' => '💊', 'importance' => 'high',     'why' => 'High-authority health source AI systems use to validate provider E-E-A-T.',               'url' => 'https://doctor.webmd.com' ),
				array( 'name' => 'Vitals',                  'icon' => '❤', 'importance' => 'medium',   'why' => 'Aggregates certifications and patient reviews — secondary AI verification source.',        'url' => 'https://www.vitals.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Community trust — especially effective for local clinics and wellness practices.',         'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'RateMDs',                 'icon' => '📋', 'importance' => 'low',      'why' => 'International healthcare directory that broadens your global citation footprint.',          'url' => 'https://www.ratemds.com' ),
			),

			'real_estate' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Buyers and sellers search for local agents on Google first — ratings are front and center.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Zillow',                  'icon' => '🏠', 'importance' => 'critical', 'why' => 'Most visited real estate platform in the US — agent reviews directly affect lead flow.',     'url' => 'https://www.zillow.com/advertise' ),
				array( 'name' => 'Realtor.com',             'icon' => '🔑', 'importance' => 'high',     'why' => 'NAR-affiliated — AI property search queries frequently surface Realtor.com agent profiles.', 'url' => 'https://www.realtor.com/products' ),
				array( 'name' => 'Homes.com',               'icon' => '🏡', 'importance' => 'high',     'why' => 'Rapidly growing platform — agent reviews here are indexed by AI as an emerging source.',    'url' => 'https://agent.homes.com' ),
				array( 'name' => 'Trulia',                  'icon' => '🏘', 'importance' => 'medium',   'why' => 'Zillow-owned — a Trulia review reinforces your data across the full Zillow Group network.',  'url' => 'https://www.trulia.com/agents' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Neighborhood groups drive referrals — community trust is critical in real estate.',          'url' => 'https://www.facebook.com/business' ),
			),

			'automotive' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Auto service and dealer searches return Google ratings prominently in the Local Pack.',    'url' => 'https://business.google.com' ),
				array( 'name' => 'DealerRater',             'icon' => '🚗', 'importance' => 'critical', 'why' => 'Leading dealer review platform — feeds Cars.com and directly influences purchase decisions.', 'url' => 'https://www.dealerrater.com/dealer-resources' ),
				array( 'name' => 'Cars.com',                'icon' => '🔑', 'importance' => 'high',     'why' => 'Major auto marketplace where buyers research dealers — reviews influence AI car queries.',   'url' => 'https://dealer.cars.com' ),
				array( 'name' => 'RepairPal',               'icon' => '🔧', 'importance' => 'high',     'why' => 'Certified shop badge appears in AI "trusted mechanic near me" answers.',                    'url' => 'https://repairpal.com/certified/apply' ),
				array( 'name' => 'Yelp',                    'icon' => '🔍', 'importance' => 'medium',   'why' => 'Especially useful for service centers — powers Apple Maps and Siri for repair queries.',    'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Auto enthusiast groups and local communities drive word-of-mouth for shops and dealers.',   'url' => 'https://www.facebook.com/business' ),
			),

			'beauty_wellness' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => '"Salon near me" queries return Local Pack results — your star rating is the primary filter.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Yelp',                    'icon' => '💅', 'importance' => 'critical', 'why' => 'Powers Apple Maps — iPhone users asking Siri for a salon see Yelp reviews first.',           'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'StyleSeat',               'icon' => '✂', 'importance' => 'high',     'why' => 'StyleSeat profiles surface in AI "book a haircut near me" queries with direct booking.',     'url' => 'https://www.styleseat.com/beauty-professionals' ),
				array( 'name' => 'Booksy',                  'icon' => '📱', 'importance' => 'high',     'why' => 'Integrated with Google\'s Reserve with Google — enables direct AI-to-booking conversion.',   'url' => 'https://booksy.com/pro' ),
				array( 'name' => 'Vagaro',                  'icon' => '🌿', 'importance' => 'medium',   'why' => 'Combined reviews and scheduling — indexed by Google as a verified wellness entity.',          'url' => 'https://www.vagaro.com/pro' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Before and after photos with reviews drive referrals and local discovery.',                  'url' => 'https://www.facebook.com/business' ),
			),

			'financial' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Clients researching local advisors check Google reviews as the first trust signal.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Trustpilot',              'icon' => '✅', 'importance' => 'high',     'why' => 'Most credible consumer trust badge — especially important in regulated financial services.', 'url' => 'https://business.trustpilot.com' ),
				array( 'name' => 'WalletHub',               'icon' => '💳', 'importance' => 'high',     'why' => 'Consumer finance comparison site — reviews here surface in AI financial product queries.', 'url' => 'https://wallethub.com/banks' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'high',     'why' => 'BBB rating is a mandatory trust signal in financial services — clients check it before engaging.', 'url' => 'https://www.bbb.org/businesses' ),
				array( 'name' => 'LinkedIn',                'icon' => '💼', 'importance' => 'medium',   'why' => 'Professional recommendations from clients and peers build E-E-A-T credibility for advisors.', 'url' => 'https://business.linkedin.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Community trust and word-of-mouth referrals in local groups.', 'url' => 'https://www.facebook.com/business' ),
			),

			'education' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Parents searching for local schools, tutors, or childcare check Google reviews first.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'GreatSchools',            'icon' => '🏫', 'importance' => 'critical', 'why' => 'Feeds Google\'s school Knowledge Panels — AI educational queries cite GreatSchools ratings.', 'url' => 'https://www.greatschools.org/gk/claim-school-profile' ),
				array( 'name' => 'Niche',                   'icon' => '📊', 'importance' => 'high',     'why' => 'Niche.com rankings are cited by AI for "best schools near me" — verified reviews boost rank.', 'url' => 'https://colleges.niche.com/claim' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Parent community groups drive enrollment referrals and social proof for schools.', 'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Yelp',                    'icon' => '🍎', 'importance' => 'medium',   'why' => 'Used by parents researching childcare centers and tutoring services locally.', 'url' => 'https://biz.yelp.com' ),
			),

			'lodging' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Google\'s hotel search surface your rating prominently — directly affects booking click-through.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'TripAdvisor',             'icon' => '✈', 'importance' => 'critical', 'why' => 'Most-cited travel source in AI recommendations — essential for any accommodation business.', 'url' => 'https://www.tripadvisor.com/owners' ),
				array( 'name' => 'Booking.com',             'icon' => '🏨', 'importance' => 'high',     'why' => 'Guest reviews feed Google\'s hotel graph — AI travel assistants pull Booking.com ratings.', 'url' => 'https://partner.booking.com' ),
				array( 'name' => 'Expedia',                 'icon' => '🌍', 'importance' => 'high',     'why' => 'Distributes reviews across Hotels.com, Vrbo, and 200+ booking sites simultaneously.', 'url' => 'https://welcome.expediagroup.com' ),
				array( 'name' => 'Yelp',                    'icon' => '📍', 'importance' => 'medium',   'why' => 'Bed and breakfast and boutique hotel reviews on Yelp surface in Apple Maps for travelers.', 'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Travel photo sharing and check-ins amplify reach and social proof for accommodations.', 'url' => 'https://www.facebook.com/business' ),
			),

			'pet_services' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => '"Pet groomer near me" returns Local Pack — your Google rating is the primary decision factor.', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🐾', 'importance' => 'critical', 'why' => 'Powers Apple Maps for pet services — Siri routes iPhone users to Yelp-rated groomers and vets.', 'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Rover',                   'icon' => '🐕', 'importance' => 'high',     'why' => 'Rover profiles surface in AI "pet sitter near me" answers as authoritative local entities.', 'url' => 'https://www.rover.com/sitter-signup' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Pet owner community groups drive local referrals and word-of-mouth for pet services.', 'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'BringFido',               'icon' => '🦴', 'importance' => 'medium',   'why' => 'Niche pet-friendly directory cited by AI travel assistants recommending pet services.', 'url' => 'https://www.bringfido.com/add' ),
			),

		);

		// Fallback for generic / unknown groups.
		$generic = array(
			array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'why' => 'Essential for any local business — star rating is the first thing customers see in search.', 'url' => 'https://business.google.com' ),
			array( 'name' => 'Trustpilot',              'icon' => '✅', 'importance' => 'high',     'why' => 'Most recognized consumer trust badge — builds confidence for any business type.', 'url' => 'https://business.trustpilot.com' ),
			array( 'name' => 'Yelp',                    'icon' => '🍽', 'importance' => 'high',     'why' => 'Strong local discovery and trust signals — also powers Apple Maps and Siri.', 'url' => 'https://biz.yelp.com' ),
			array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'why' => 'Social proof and community engagement drive local referrals.', 'url' => 'https://www.facebook.com/business' ),
			array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'why' => 'Credibility and accreditation recognized by AI engines as an E-E-A-T signal.', 'url' => 'https://www.bbb.org/businesses' ),
		);

		return $map[ $group ] ?? $generic;
	}
}
