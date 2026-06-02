<?php
/**
 * Admin Page: Company Profile
 *
 * Publisher-level E-E-A-T settings. Stores the Organization entity used for:
 *   - article:publisher OG tag (Facebook / Meta structured attribution)
 *   - Organization JSON-LD schema
 *   - fb:app_id sitewide (Meta Domain Insights + AI Ad Attribution)
 *
 * Auto-detects and syncs data from Yoast SEO or Rank Math so users don't
 * start from scratch. Only fills fields that are still empty — existing
 * values are never overwritten by a sync.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Company {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$notice = self::handle_post();

		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'Company Profile', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description" style="max-width:720px;"><?php esc_html_e( 'Your Publisher entity. Drives article:publisher attribution, Organization JSON-LD schema, and the fb:app_id tag that unlocks Meta Domain Insights and AI Ad Attribution. Correctly splits Publisher (company) from Author (individual) for maximum E-E-A-T signal.', 'twt-aeo-ultimate' ); ?></p>
			<?php self::render_content( $notice ); ?>
		</div>
		<?php
	}

	/**
	 * Process any POSTed form data and return a notice key ('saved', 'synced', or '').
	 */
	public static function handle_post(): string {
		$notice = '';

		if ( isset( $_POST['twtaeo_company_sync'] ) ) {
			check_admin_referer( TWTAEO_Company_Profile::NONCE_SYNC );
			TWTAEO_Company_Profile::sync_from_seo_plugin();
			$notice = 'synced';
		}

		if ( isset( $_POST['twtaeo_company_save'] ) ) {
			check_admin_referer( TWTAEO_Company_Profile::NONCE_SAVE );
			TWTAEO_Company_Profile::save( map_deep( wp_unslash( $_POST['twtaeo_company'] ?? array() ), 'sanitize_text_field' ) );
			$notice = 'saved';
		}

		return $notice;
	}

	/**
	 * Render the inner page content (notices + form). Safe to call from other pages.
	 */
	public static function render_content( string $notice = '' ): void {
		$source      = TWTAEO_Company_Profile::detect_source();
		$c           = TWTAEO_Company_Profile::get();
		$sourced     = $source ? TWTAEO_Company_Profile::read_from_seo_plugin() : array();
		$owns_schema = TWTAEO_Company_Profile::seo_plugin_owns_org_schema();

		$source_labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
		);
		$source_label = $source ? ( $source_labels[ $source ] ?? $source ) : '';

		$social_fields = array(
			'social_facebook'  => array( 'label' => 'Facebook Page',  'placeholder' => 'https://facebook.com/yourpage',       'note' => 'Used for article:publisher' ),
			'social_twitter'   => array( 'label' => 'X / Twitter',    'placeholder' => 'https://x.com/yourhandle',             'note' => '' ),
			'social_linkedin'  => array( 'label' => 'LinkedIn',       'placeholder' => 'https://linkedin.com/company/yourco',  'note' => '' ),
			'social_instagram' => array( 'label' => 'Instagram',      'placeholder' => 'https://instagram.com/yourhandle',     'note' => '' ),
			'social_youtube'   => array( 'label' => 'YouTube',        'placeholder' => 'https://youtube.com/@yourchannel',     'note' => '' ),
			'social_wikipedia' => array( 'label' => 'Wikipedia',      'placeholder' => 'https://en.wikipedia.org/wiki/YourCo', 'note' => '' ),
			'social_pinterest' => array( 'label' => 'Pinterest',      'placeholder' => 'https://pinterest.com/yourhandle',     'note' => '' ),
		);

		if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Company profile saved.', 'twt-aeo-ultimate' ); ?></p></div>
		<?php elseif ( 'synced' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php
				// translators: %s: source plugin name (e.g. 'Yoast SEO').
				printf( esc_html__( 'Synced from %s. Empty fields have been filled — your existing values were not changed.', 'twt-aeo-ultimate' ), esc_html( $source_label ) ); ?></p></div>
		<?php endif;

		if ( $source ) : ?>
		<div class="twt-aeo-card" style="max-width:780px;margin-top:20px;padding:18px 24px;border-left:4px solid <?php echo $owns_schema ? '#f59e0b' : '#10b981'; ?>;">
			<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
				<div style="flex:1;min-width:0;">
					<strong style="font-size:13px;">
						<?php
						printf(
							// translators: %s: source plugin name (e.g. 'Yoast SEO').
							esc_html__( '%s detected', 'twt-aeo-ultimate' ),
							esc_html( $source_label )
						); ?>
					</strong>
					<?php if ( $owns_schema ) : ?>
						<p style="margin:4px 0 0;font-size:12px;color:#646970;">
							<?php
						printf(
							// translators: %1$s: source plugin name. %2$s: source plugin name.
							esc_html__( '%1$s is configured as your site\'s Organization entity and will output its own Organization schema. We\'ll skip our duplicate block but still output article:publisher and fb:app_id — fields %2$s typically leaves empty.', 'twt-aeo-ultimate' ),
							esc_html( $source_label ),
							esc_html( $source_label )
						); ?>
						</p>
					<?php else : ?>
						<p style="margin:4px 0 0;font-size:12px;color:#646970;">
							<?php
						printf(
							// translators: %s: source plugin name (e.g. 'Yoast SEO').
							esc_html__( '%s social data is available. Click Sync to pre-fill any empty fields below — existing values will not be overwritten.', 'twt-aeo-ultimate' ),
							esc_html( $source_label )
						); ?>
						</p>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $sourced ) ) : ?>
				<form method="post" style="flex-shrink:0;">
					<?php wp_nonce_field( TWTAEO_Company_Profile::NONCE_SYNC ); ?>
					<button type="submit" name="twtaeo_company_sync" value="1" class="button button-secondary">
						<?php
						// translators: %s: source plugin name (e.g. 'Yoast SEO').
						printf( esc_html__( 'Sync from %s', 'twt-aeo-ultimate' ), esc_html( $source_label ) ); ?>
					</button>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $sourced ) ) : ?>
			<details style="margin-top:12px;">
				<summary style="font-size:12px;color:#646970;cursor:pointer;"><?php esc_html_e( 'Preview available data', 'twt-aeo-ultimate' ); ?></summary>
				<ul style="margin:8px 0 0 16px;font-size:12px;color:#646970;">
					<?php foreach ( $sourced as $key => $val ) : ?>
						<li><code><?php echo esc_html( $key ); ?></code>: <?php echo esc_html( $val ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<form method="post" style="margin-top:24px;">
			<?php wp_nonce_field( TWTAEO_Company_Profile::NONCE_SAVE ); ?>

			<?php /* ── Company Identity ── */ ?>
			<div class="twt-aeo-card" style="max-width:780px;padding:24px;">
				<h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;"><?php esc_html_e( 'Company Identity', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="margin:0 0 20px;"><?php esc_html_e( 'Core publisher data used in Organization schema and as the structured article:publisher entity.', 'twt-aeo-ultimate' ); ?></p>

				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;"><label for="co-name"><?php esc_html_e( 'Publisher Name', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<input type="text" id="co-name" name="twtaeo_company[name]" value="<?php echo esc_attr( $c['name'] ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'The trading name shown in search results and schema (e.g. "Acme Corp").', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="co-legal-name"><?php esc_html_e( 'Legal Name', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<input type="text" id="co-legal-name" name="twtaeo_company[legal_name]" value="<?php echo esc_attr( $c['legal_name'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional — e.g. Acme Corporation LLC', 'twt-aeo-ultimate' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Logo', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
								<?php if ( ! empty( $c['logo_url'] ) ) : ?>
									<img src="<?php echo esc_url( $c['logo_url'] ); ?>" alt="" style="max-height:60px;max-width:200px;border:1px solid #ddd;border-radius:3px;padding:4px;background:#fff;">
								<?php endif; ?>
								<div>
									<input type="hidden" id="co-logo-id"  name="twtaeo_company[logo_id]"  value="<?php echo esc_attr( $c['logo_id'] ); ?>">
									<input type="text"   id="co-logo-url" name="twtaeo_company[logo_url]" value="<?php echo esc_attr( $c['logo_url'] ); ?>" class="regular-text" placeholder="https://example.com/logo.png" style="margin-bottom:6px;display:block;">
									<button type="button" class="button" id="twt-aeo-logo-pick"><?php esc_html_e( 'Choose from Media Library', 'twt-aeo-ultimate' ); ?></button>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="co-url"><?php esc_html_e( 'Website URL', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="url" id="co-url" name="twtaeo_company[url]" value="<?php echo esc_attr( $c['url'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="co-desc"><?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?></label></th>
						<td><textarea id="co-desc" name="twtaeo_company[description]" rows="3" class="large-text"><?php echo esc_textarea( $c['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="co-year"><?php esc_html_e( 'Founding Year', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="co-year" name="twtaeo_company[founding_year]" value="<?php echo esc_attr( $c['founding_year'] ); ?>" class="small-text" placeholder="2010" maxlength="4"></td>
					</tr>
					<tr>
						<th><label for="co-email"><?php esc_html_e( 'Contact Email', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="email" id="co-email" name="twtaeo_company[email]" value="<?php echo esc_attr( $c['email'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="co-phone"><?php esc_html_e( 'Phone', 'twt-aeo-ultimate' ); ?></label></th>
						<td><input type="text" id="co-phone" name="twtaeo_company[phone]" value="<?php echo esc_attr( $c['phone'] ); ?>" class="regular-text" placeholder="+1-800-555-0100"></td>
					</tr>
				</table>
			</div>

			<?php /* ── Social Profiles ── */ ?>
			<div class="twt-aeo-card" style="max-width:780px;margin-top:16px;padding:24px;">
				<h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;"><?php esc_html_e( 'Social Profiles', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="margin:0 0 16px;"><?php esc_html_e( 'Used for Organization sameAs links in schema and — critically — the Facebook Page URL drives article:publisher attribution for Meta\'s AI and ad attribution systems.', 'twt-aeo-ultimate' ); ?></p>

				<table class="form-table" style="margin:0;">
					<?php foreach ( $social_fields as $field_key => $field ) : ?>
					<tr>
						<th style="width:200px;"><label for="co-<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<input type="url" id="co-<?php echo esc_attr( $field_key ); ?>" name="twtaeo_company[<?php echo esc_attr( $field_key ); ?>]" value="<?php echo esc_attr( $c[ $field_key ] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>">
							<?php if ( ! empty( $field['note'] ) ) : ?>
								<span class="description" style="margin-left:8px;color:#10b981;font-weight:500;">&#x2022; <?php echo esc_html( $field['note'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<?php /* ── Meta Business Sync ── */ ?>
			<div class="twt-aeo-card" style="max-width:780px;margin-top:16px;padding:24px;">
				<h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;"><?php esc_html_e( 'Meta Business Sync', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="margin:0 0 16px;">
					<?php esc_html_e( 'The fb:app_id tag is output sitewide and enables Meta\'s Domain Insights dashboard and AI-powered Ad Attribution — features most SEO plugins omit. Find your App ID at', 'twt-aeo-ultimate' ); ?>
					<a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a>.
				</p>

				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;"><label for="co-fb-app-id"><?php esc_html_e( 'Facebook App ID', 'twt-aeo-ultimate' ); ?></label></th>
						<td>
							<input type="text" id="co-fb-app-id" name="twtaeo_company[fb_app_id]" value="<?php echo esc_attr( $c['fb_app_id'] ); ?>" class="regular-text" placeholder="1234567890" inputmode="numeric" pattern="[0-9]*">
							<p class="description"><?php esc_html_e( 'Numbers only. Outputs as &lt;meta property="fb:app_id"&gt; on every page.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php /* ── Output Controls ── */ ?>
			<div class="twt-aeo-card" style="max-width:780px;margin-top:16px;padding:24px;">
				<h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;"><?php esc_html_e( 'Output Controls', 'twt-aeo-ultimate' ); ?></h2>
				<p class="description" style="margin:0 0 16px;"><?php esc_html_e( 'Toggle which structured signals this profile outputs. Disable any item if another plugin handles it.', 'twt-aeo-ultimate' ); ?></p>

				<table class="form-table" style="margin:0;">
					<tr>
						<th><?php esc_html_e( 'Organization Schema', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="twtaeo_company[output_org_schema]" value="1" <?php checked( ! empty( $c['output_org_schema'] ) ); ?>>
								<?php esc_html_e( 'Output Organization JSON-LD schema', 'twt-aeo-ultimate' ); ?>
							</label>
							<?php if ( $owns_schema ) : ?>
								<p class="description" style="color:#f59e0b;">&#9888; <?php
								// translators: %s: source plugin name (e.g. 'Yoast SEO').
								printf( esc_html__( '%s is already outputting Organization schema. This block will be skipped automatically to prevent duplicates.', 'twt-aeo-ultimate' ), esc_html( $source_label ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'article:publisher', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="twtaeo_company[output_article_publisher]" value="1" <?php checked( ! empty( $c['output_article_publisher'] ) ); ?>>
								<?php esc_html_e( 'Output article:publisher and article:author OG tags on posts', 'twt-aeo-ultimate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Runs at priority 1 — fires before Yoast / Rank Math to fill the gap they leave on these fields.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'fb:app_id', 'twt-aeo-ultimate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="twtaeo_company[output_fb_app_id]" value="1" <?php checked( ! empty( $c['output_fb_app_id'] ) ); ?>>
								<?php esc_html_e( 'Output fb:app_id on every page', 'twt-aeo-ultimate' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<p style="margin-top:16px;">
				<input type="submit" name="twtaeo_company_save" class="button button-primary" value="<?php esc_attr_e( 'Save Company Profile', 'twt-aeo-ultimate' ); ?>">
			</p>
		</form>

		<?php /* ── Publisher vs. Author Split — How It Works ── */ ?>
		<div class="twt-aeo-card" style="max-width:780px;margin-top:24px;padding:24px;background:#f9f9f9;">
			<h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;"><?php esc_html_e( 'How the Publisher ↔ Author Split Works', 'twt-aeo-ultimate' ); ?></h2>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:13px;">
				<div>
					<strong style="display:block;margin-bottom:6px;color:#1d2327;"><?php esc_html_e( 'article:publisher', 'twt-aeo-ultimate' ); ?></strong>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Set to your company\'s Facebook Page URL (from Social Profiles above). Tells Meta and Facebook\'s graph which business entity published this content — required for Domain Insights and Ad Attribution.', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div>
					<strong style="display:block;margin-bottom:6px;color:#1d2327;"><?php esc_html_e( 'article:author', 'twt-aeo-ultimate' ); ?></strong>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Set to the individual post author\'s Facebook profile URL (configured per-user under Users → Edit Profile → Social Profiles). This is the Person entity — separate from the Publisher.', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div>
					<strong style="display:block;margin-bottom:6px;color:#1d2327;"><?php esc_html_e( 'Organization schema', 'twt-aeo-ultimate' ); ?></strong>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'JSON-LD block on every page identifying the publishing entity with name, logo, social links (sameAs), and contact info. AI crawlers and LLMs use this to reliably identify who is behind the content.', 'twt-aeo-ultimate' ); ?></p>
				</div>
				<div>
					<strong style="display:block;margin-bottom:6px;color:#1d2327;"><?php esc_html_e( 'fb:app_id', 'twt-aeo-ultimate' ); ?></strong>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Sitewide meta tag that associates your site with a Facebook App. Enables the Meta Domain Insights dashboard and is the key signal Meta\'s AI Ad Attribution systems use to credit your organic reach — commonly missed by other plugins.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			</div>
		</div>

		<?php
		ob_start();
		?>
		(function () {
			var btn = document.getElementById( 'twt-aeo-logo-pick' );
			if ( ! btn || typeof wp === 'undefined' || ! wp.media ) return;

			var frame;
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = wp.media( {
					title:    '<?php echo esc_js( __( 'Choose Company Logo', 'twt-aeo-ultimate' ) ); ?>',
					button:   { text: '<?php echo esc_js( __( 'Use as logo', 'twt-aeo-ultimate' ) ); ?>' },
					multiple: false,
					library:  { type: 'image' },
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					document.getElementById( 'co-logo-url' ).value = att.url;
					document.getElementById( 'co-logo-id' ).value  = att.id;
				} );
				frame.open();
			} );
		}());
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
