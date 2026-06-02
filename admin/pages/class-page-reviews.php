<?php
/**
 * Admin Page — Reviews
 *
 * Three tabs:
 *   Reviews  — list all reviews with approve/reject/delete actions
 *   Settings — form options, context override, auto-approve, schema toggle
 *   Shortcodes — reference for embedding reviews on front end
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Reviews {

	const NONCE_SETTINGS  = 'twtaeo_reviews_save';
	const NONCE_ACTION    = 'twtaeo_reviews_action';
	const OPTION_INDUSTRY = 'twtaeo_reviews_industry';

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render() {
		self::maybe_handle_action();
		self::maybe_handle_settings();

		$tab      = sanitize_key( wp_unslash( $_GET['twtaeo_tab'] ?? 'reviews' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = TWTAEO_Reviews_Schema_Writer::get_settings();
		$context  = TWTAEO_Reviews_Schema_Writer::detect_context();
		$label    = TWTAEO_Reviews_Schema_Writer::get_context_label( $context );
		$industry = sanitize_key( get_option( self::OPTION_INDUSTRY, '' ) );

		$aggregate = TWTAEO_Reviews_CPT::get_aggregate( -1 );
		?>
		<div class="wrap twt-aeo-page">
			<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
				<span class="dashicons dashicons-star-filled" style="font-size:24px;color:#f59e0b;"></span>
				<?php esc_html_e( 'Reviews', 'twt-aeo-ultimate' ); ?>
			</h1>
			<p style="color:#646970;margin-top:0;margin-bottom:20px;">
				<?php esc_html_e( 'Manage customer reviews and schema output. Reviews power your AggregateRating and Review schema in search results.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php /* ── Stats banner ── */ ?>
			<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
				<?php
				$all       = TWTAEO_Reviews_CPT::get_all( 'any' );
				$pending   = array_filter( $all, fn( $p ) => $p->post_status === 'draft' );
				$approved  = array_filter( $all, fn( $p ) => $p->post_status === 'publish' );
				$stats = array(
					array( 'label' => __( 'Total', 'twt-aeo-ultimate' ),   'value' => count( $all ),      'color' => '#64748b' ),
					array( 'label' => __( 'Approved', 'twt-aeo-ultimate' ), 'value' => count( $approved ), 'color' => '#10b981' ),
					array( 'label' => __( 'Pending', 'twt-aeo-ultimate' ),  'value' => count( $pending ),  'color' => '#f59e0b' ),
					array( 'label' => __( 'Avg Rating', 'twt-aeo-ultimate' ), 'value' => $aggregate ? number_format( $aggregate['average'], 1 ) . ' / 5' : '—', 'color' => '#6366f1' ),
				);
				foreach ( $stats as $stat ) : ?>
				<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 20px;min-width:110px;text-align:center;">
					<div style="font-size:1.8em;font-weight:700;color:<?php echo esc_attr( $stat['color'] ); ?>;"><?php echo esc_html( $stat['value'] ); ?></div>
					<div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $stat['label'] ); ?></div>
				</div>
				<?php endforeach; ?>

				<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 20px;min-width:150px;">
					<div style="font-size:11px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Schema Context', 'twt-aeo-ultimate' ); ?></div>
					<div style="font-weight:600;color:#1e293b;">
						<?php echo esc_html( $label ); ?>
						<?php if ( ! empty( $settings['context_override'] ) ) : ?>
							<span style="font-size:10px;color:#94a3b8;font-weight:400;"> (<?php esc_html_e( 'override', 'twt-aeo-ultimate' ); ?>)</span>
						<?php else : ?>
							<span style="font-size:10px;color:#94a3b8;font-weight:400;"> (<?php esc_html_e( 'auto-detected', 'twt-aeo-ultimate' ); ?>)</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<nav class="twt-aeo-tabs">
				<?php
				$review_tabs = array(
					'reviews'    => array( 'label' => __( 'Reviews',    'twt-aeo-ultimate' ), 'icon' => 'dashicons-star-filled' ),
					'settings'   => array( 'label' => __( 'Settings',   'twt-aeo-ultimate' ), 'icon' => 'dashicons-admin-settings' ),
					'shortcodes' => array( 'label' => __( 'Shortcodes', 'twt-aeo-ultimate' ), 'icon' => 'dashicons-shortcode' ),
				);
				foreach ( $review_tabs as $slug => $info ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'twtaeo_tab', $slug, menu_page_url( 'twt-aeo-reviews', false ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === $slug ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span>
					<?php echo esc_html( $info['label'] ); ?>
					<?php if ( $slug === 'reviews' && count( $pending ) > 0 ) : ?>
						<span class="twt-aeo-count twt-aeo-count--alert" style="margin-left:2px;">
							<?php echo esc_html( count( $pending ) ); ?>
						</span>
					<?php endif; ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'settings':
					self::render_settings_tab( $settings, $industry );
					break;
				case 'shortcodes':
					self::render_shortcodes_tab();
					break;
				default:
					self::render_reviews_tab( $industry );
			}
			?>
		</div>
		<?php
	}

	// ── Reviews Tab ───────────────────────────────────────────────────────────

	private static function render_reviews_tab( string $industry = '' ) {
		$filter  = sanitize_key( wp_unslash( $_GET['review_status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reviews = TWTAEO_Reviews_CPT::get_all( 'any' );

		if ( $filter === 'pending' ) {
			$reviews = array_filter( $reviews, fn( $p ) => $p->post_status === 'draft' );
		} elseif ( $filter === 'approved' ) {
			$reviews = array_filter( $reviews, fn( $p ) => $p->post_status === 'publish' );
		}

		$base_url    = menu_page_url( 'twt-aeo-reviews', false );
		$reviews_url = add_query_arg( 'twtaeo_tab', 'reviews', $base_url );
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;">

			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
				<div style="display:flex;gap:6px;">
					<?php foreach ( array(
						'all'      => __( 'All', 'twt-aeo-ultimate' ),
						'pending'  => __( 'Pending', 'twt-aeo-ultimate' ),
						'approved' => __( 'Approved', 'twt-aeo-ultimate' ),
					) as $f => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'review_status', $f, $reviews_url ) ); ?>"
					   style="padding:4px 12px;border-radius:4px;font-size:12px;text-decoration:none;
					          background:<?php echo $filter === $f ? '#2563eb' : '#f1f5f9'; ?>;
					          color:<?php echo $filter === $f ? '#fff' : '#1e293b'; ?>;">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php endforeach; ?>
				</div>

				<a href="<?php echo esc_url( add_query_arg( array( 'twtaeo_tab' => 'shortcodes' ), $base_url ) ); ?>"
				   style="font-size:12px;color:#64748b;text-decoration:none;">
					<?php esc_html_e( '+ Embed on your site', 'twt-aeo-ultimate' ); ?> &rarr;
				</a>
			</div>

			<?php if ( empty( $reviews ) ) : ?>
			<div style="text-align:center;padding:48px 0;color:#64748b;">
				<p style="font-size:32px;margin:0 0 10px;">&#9733;</p>
				<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No reviews found.', 'twt-aeo-ultimate' ); ?></p>
				<p style="margin:6px 0 0;font-size:12px;color:#94a3b8;"><?php esc_html_e( 'Add the [twtaeo_review_form] shortcode to any page to start collecting reviews.', 'twt-aeo-ultimate' ); ?></p>
			</div>
			<?php else : ?>
			<table class="widefat striped" style="border-radius:4px;overflow:hidden;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reviewer', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:70px;text-align:center;"><?php esc_html_e( 'Rating', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Review', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Source', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Date', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:90px;text-align:center;"><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:130px;"><?php esc_html_e( 'Actions', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $reviews as $review ) :
					$rating  = (int) get_post_meta( $review->ID, TWTAEO_Reviews_CPT::META_RATING, true );
					$rating  = max( 1, min( 5, $rating ) );
					$source  = get_post_meta( $review->ID, TWTAEO_Reviews_CPT::META_SOURCE, true ) ?: 'internal';
					$email   = get_post_meta( $review->ID, TWTAEO_Reviews_CPT::META_EMAIL, true );
					$is_pub  = $review->post_status === 'publish';
					$nonce   = wp_create_nonce( self::NONCE_ACTION );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $review->post_title ); ?></strong>
						<?php if ( $email ) : ?>
						<div style="font-size:11px;color:#64748b;"><?php echo esc_html( $email ); ?></div>
						<?php endif; ?>
					</td>
					<td style="text-align:center;">
						<span title="<?php
						// translators: %d: star rating from 1 to 5.
						echo esc_attr( sprintf( __( '%d out of 5', 'twt-aeo-ultimate' ), absint( $rating ) ) ); ?>"
						      style="color:#f59e0b;font-size:15px;letter-spacing:-1px;">
							<?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?>
						</span>
					</td>
					<td>
						<p style="margin:0;font-size:13px;color:#1e293b;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
							<?php echo esc_html( wp_strip_all_tags( $review->post_content ) ); ?>
						</p>
					</td>
					<td>
						<span style="display:inline-block;background:#f1f5f9;border-radius:3px;padding:2px 6px;font-size:11px;color:#475569;text-transform:capitalize;">
							<?php echo esc_html( $source ); ?>
						</span>
					</td>
					<td style="font-size:12px;color:#64748b;white-space:nowrap;">
						<?php echo esc_html( get_the_date( 'M j, Y', $review->ID ) ); ?>
					</td>
					<td style="text-align:center;">
						<?php if ( $is_pub ) : ?>
						<span style="display:inline-block;background:#d1fae5;color:#065f46;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:600;">
							<?php esc_html_e( 'Approved', 'twt-aeo-ultimate' ); ?>
						</span>
						<?php else : ?>
						<span style="display:inline-block;background:#fef3c7;color:#92400e;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:600;">
							<?php esc_html_e( 'Pending', 'twt-aeo-ultimate' ); ?>
						</span>
						<?php endif; ?>
					</td>
					<td>
						<div style="display:flex;gap:6px;flex-wrap:wrap;">
							<?php if ( ! $is_pub ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array(
								'twtaeo_tab'        => 'reviews',
								'twt_review_action'  => 'approve',
								'twt_review_id'      => $review->ID,
								'_wpnonce'           => $nonce,
							), $base_url ) ); ?>" class="button button-small" style="background:#d1fae5;border-color:#a7f3d0;color:#065f46;">
								<?php esc_html_e( 'Approve', 'twt-aeo-ultimate' ); ?>
							</a>
							<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( array(
								'twtaeo_tab'        => 'reviews',
								'twt_review_action'  => 'unapprove',
								'twt_review_id'      => $review->ID,
								'_wpnonce'           => $nonce,
							), $base_url ) ); ?>" class="button button-small">
								<?php esc_html_e( 'Unapprove', 'twt-aeo-ultimate' ); ?>
							</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( add_query_arg( array(
								'twtaeo_tab'        => 'reviews',
								'twt_review_action'  => 'delete',
								'twt_review_id'      => $review->ID,
								'_wpnonce'           => $nonce,
							), $base_url ) ); ?>" class="button button-small"
							   style="color:#dc2626;border-color:#fca5a5;"
							   onclick="return confirm('<?php esc_attr_e( 'Delete this review? This cannot be undone.', 'twt-aeo-ultimate' ); ?>')">
								<?php esc_html_e( 'Delete', 'twt-aeo-ultimate' ); ?>
							</a>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<?php self::render_platforms_panel( $industry ); ?>
		<?php
	}

	// ── Settings Tab ─────────────────────────────────────────────────────────

	private static function render_settings_tab( array $settings, string $industry = '' ) {
		$platforms    = self::get_review_platforms();
		$industry_map = array(
			''                => __( '— Select your business type —', 'twt-aeo-ultimate' ),
			'home-services'   => __( 'Home Services', 'twt-aeo-ultimate' ),
			'retail'          => __( 'Retail', 'twt-aeo-ultimate' ),
			'industrial-b2b'  => __( 'Industrial / B2B', 'twt-aeo-ultimate' ),
			'healthcare'      => __( 'Healthcare', 'twt-aeo-ultimate' ),
			'legal'           => __( 'Legal', 'twt-aeo-ultimate' ),
			'real-estate'     => __( 'Real Estate', 'twt-aeo-ultimate' ),
			'restaurant'      => __( 'Restaurant / Food & Drink', 'twt-aeo-ultimate' ),
			'automotive'      => __( 'Automotive', 'twt-aeo-ultimate' ),
			'financial'       => __( 'Financial Services', 'twt-aeo-ultimate' ),
			'general'         => __( 'General / Other', 'twt-aeo-ultimate' ),
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_SETTINGS, '_twtaeo_reviews_nonce' ); ?>
			<input type="hidden" name="twtaeo_reviews_action" value="save_settings">

			<?php /* ── Business category ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Business Category', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 20px;color:#646970;font-size:13px;">
					<?php esc_html_e( 'Select your industry to see which review platforms matter most for your business. Recommendations appear on the Reviews tab.', 'twt-aeo-ultimate' ); ?>
				</p>
				<select name="twtaeo_reviews_industry" style="max-width:360px;">
					<?php foreach ( $industry_map as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $industry, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php /* ── Form settings ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Review Form', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 20px;color:#646970;font-size:13px;"><?php esc_html_e( 'Controls the [twtaeo_review_form] shortcode behavior.', 'twt-aeo-ultimate' ); ?></p>

				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Show Form', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" name="twtaeo_reviews[show_form]" value="1" <?php checked( ! empty( $settings['show_form'] ) ); ?>>
								<span><?php esc_html_e( 'Enable the front-end review submission form', 'twt-aeo-ultimate' ); ?></span>
							</label>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Auto-Approve', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" name="twtaeo_reviews[auto_approve]" value="1" <?php checked( ! empty( $settings['auto_approve'] ) ); ?>>
								<span><?php esc_html_e( 'Automatically publish new reviews without moderation', 'twt-aeo-ultimate' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Leave unchecked to hold new reviews for manual approval in the Reviews tab.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Require Email', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" name="twtaeo_reviews[require_email]" value="1" <?php checked( ! empty( $settings['require_email'] ) ); ?>>
								<span><?php esc_html_e( 'Make email address a required field', 'twt-aeo-ultimate' ); ?></span>
							</label>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<label for="twtaeo_rv_form_title"><?php esc_html_e( 'Form Title', 'twt-aeo-ultimate' ); ?></label>
						</th>
						<td style="padding:8px 0;">
							<input type="text" id="twtaeo_rv_form_title"
							       name="twtaeo_reviews[form_title]"
							       value="<?php echo esc_attr( $settings['form_title'] ); ?>"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'Leave a Review', 'twt-aeo-ultimate' ); ?>">
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<label for="twtaeo_rv_submit"><?php esc_html_e( 'Submit Button Label', 'twt-aeo-ultimate' ); ?></label>
						</th>
						<td style="padding:8px 0;">
							<input type="text" id="twtaeo_rv_submit"
							       name="twtaeo_reviews[submit_label]"
							       value="<?php echo esc_attr( $settings['submit_label'] ); ?>"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'Submit Review', 'twt-aeo-ultimate' ); ?>">
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<label for="twtaeo_rv_success"><?php esc_html_e( 'Success Message', 'twt-aeo-ultimate' ); ?></label>
						</th>
						<td style="padding:8px 0;">
							<input type="text" id="twtaeo_rv_success"
							       name="twtaeo_reviews[success_message]"
							       value="<?php echo esc_attr( $settings['success_message'] ); ?>"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'Thank you! Your review is pending approval.', 'twt-aeo-ultimate' ); ?>">
						</td>
					</tr>
				</table>
			</div>

			<?php /* ── Schema context ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Schema Context', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 20px;color:#646970;font-size:13px;">
					<?php esc_html_e( 'The Reviews module auto-detects the right schema type from your active modules. Use the override below only if the auto-detection is wrong for your site.', 'twt-aeo-ultimate' ); ?>
				</p>

				<?php
				$auto_context = TWTAEO_Reviews_Schema_Writer::detect_context();
				$override     = $settings['context_override'] ?? '';
				?>
				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Auto-Detected Context', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<code><?php echo esc_html( $auto_context ); ?></code>
							&nbsp;
							<span style="color:#64748b;font-size:13px;">(<?php echo esc_html( TWTAEO_Reviews_Schema_Writer::get_context_label( $auto_context ) ); ?>)</span>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<label for="twtaeo_rv_context"><?php esc_html_e( 'Context Override', 'twt-aeo-ultimate' ); ?></label>
						</th>
						<td style="padding:8px 0;">
							<select id="twtaeo_rv_context" name="twtaeo_reviews[context_override]">
								<option value="" <?php selected( $override, '' ); ?>><?php esc_html_e( 'Auto-detect (recommended)', 'twt-aeo-ultimate' ); ?></option>
								<option value="general" <?php selected( $override, 'general' ); ?>><?php esc_html_e( 'Organization (general)', 'twt-aeo-ultimate' ); ?></option>
								<option value="local"   <?php selected( $override, 'local' ); ?>><?php esc_html_e( 'Local Business', 'twt-aeo-ultimate' ); ?></option>
								<option value="product" <?php selected( $override, 'product' ); ?>><?php esc_html_e( 'WooCommerce Product', 'twt-aeo-ultimate' ); ?></option>
								<option value="service" <?php selected( $override, 'service' ); ?>><?php esc_html_e( 'Service Page', 'twt-aeo-ultimate' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Overrides the auto-detected context. Auto-detect checks which modules are active: Local Pack → LocalBusiness, WooCommerce → Product, Service Detector → Service, fallback → Organization.', 'twt-aeo-ultimate' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div style="margin-top:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px 20px;">
					<h3 style="margin:0 0 12px;font-size:13px;color:#1e293b;"><?php esc_html_e( 'Schema Output by Context', 'twt-aeo-ultimate' ); ?></h3>
					<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
						<?php foreach ( array(
							'local'   => array( 'icon' => '🏢', 'type' => 'LocalBusiness', 'note' => __( 'Address from Local Pack', 'twt-aeo-ultimate' ) ),
							'product' => array( 'icon' => '🛒', 'type' => 'Product',        'note' => __( 'Per-product page', 'twt-aeo-ultimate' ) ),
							'service' => array( 'icon' => '⚙️', 'type' => 'Service',        'note' => __( 'Per-service page', 'twt-aeo-ultimate' ) ),
							'general' => array( 'icon' => '🌐', 'type' => 'Organization',   'note' => __( 'Front page / home only', 'twt-aeo-ultimate' ) ),
						) as $ctx => $info ) : ?>
						<div style="background:#fff;border:1px solid <?php echo $auto_context === $ctx ? '#6366f1' : '#e2e8f0'; ?>;border-radius:6px;padding:10px 12px;">
							<div style="font-size:18px;margin-bottom:4px;"><?php echo esc_html( $info['icon'] ); ?></div>
							<div style="font-weight:600;font-size:12px;color:#1e293b;"><?php echo esc_html( $info['type'] ); ?></div>
							<div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $info['note'] ); ?></div>
							<?php if ( $auto_context === $ctx ) : ?>
							<div style="font-size:10px;color:#6366f1;margin-top:4px;font-weight:600;"><?php esc_html_e( '← Active', 'twt-aeo-ultimate' ); ?></div>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// ── Shortcodes Tab ────────────────────────────────────────────────────────

	private static function render_shortcodes_tab() {
		$shortcodes = array(
			array(
				'tag'     => '[twtaeo_reviews]',
				'desc'    => __( 'Displays a list of approved reviews.', 'twt-aeo-ultimate' ),
				'attrs'   => array(
					'post_id'     => __( 'Scope to a post/product ID. -1 = all reviews. Default: -1', 'twt-aeo-ultimate' ),
					'limit'       => __( 'Max reviews to show. Default: 10', 'twt-aeo-ultimate' ),
					'show_rating' => __( 'Show star rating per review. Default: true', 'twt-aeo-ultimate' ),
					'show_date'   => __( 'Show review date. Default: true', 'twt-aeo-ultimate' ),
				),
				'example' => '[twtaeo_reviews limit="5" show_date="false"]',
			),
			array(
				'tag'     => '[twtaeo_review_form]',
				'desc'    => __( 'Displays the review submission form. Controlled by the Show Form setting.', 'twt-aeo-ultimate' ),
				'attrs'   => array(
					'post_id' => __( 'Associate submitted review with a specific post/product ID. Defaults to current post.', 'twt-aeo-ultimate' ),
				),
				'example' => '[twtaeo_review_form post_id="42"]',
			),
			array(
				'tag'     => '[twtaeo_aggregate_rating]',
				'desc'    => __( 'Displays an average star rating bar with review count.', 'twt-aeo-ultimate' ),
				'attrs'   => array(
					'post_id' => __( 'Scope to a post/product ID. -1 = aggregate across all reviews. Default: -1', 'twt-aeo-ultimate' ),
				),
				'example' => '[twtaeo_aggregate_rating]',
			),
		);
		?>
		<div style="display:flex;flex-direction:column;gap:20px;">
			<?php foreach ( $shortcodes as $sc ) : ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;">
				<h2 style="margin:0 0 6px;font-size:16px;font-family:monospace;"><?php echo esc_html( $sc['tag'] ); ?></h2>
				<p style="margin:0 0 16px;color:#64748b;font-size:13px;"><?php echo esc_html( $sc['desc'] ); ?></p>

				<?php if ( ! empty( $sc['attrs'] ) ) : ?>
				<h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;"><?php esc_html_e( 'Attributes', 'twt-aeo-ultimate' ); ?></h4>
				<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
					<?php foreach ( $sc['attrs'] as $attr => $desc ) : ?>
					<tr style="border-top:1px solid #f1f5f9;">
						<td style="padding:6px 12px 6px 0;width:180px;white-space:nowrap;">
							<code style="background:#f6f7f7;padding:2px 6px;border-radius:3px;"><?php echo esc_html( $attr ); ?></code>
						</td>
						<td style="padding:6px 0;color:#475569;"><?php echo esc_html( $desc ); ?></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>

				<h4 style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;"><?php esc_html_e( 'Example', 'twt-aeo-ultimate' ); ?></h4>
				<div style="background:#1e1e1e;color:#d4d4d4;padding:10px 14px;border-radius:4px;font-family:monospace;font-size:13px;">
					<?php echo esc_html( $sc['example'] ); ?>
				</div>
			</div>
			<?php endforeach; ?>

			<div style="background:#f0f6fc;border:1px solid #c3d9ef;border-radius:6px;padding:16px 20px;">
				<strong style="font-size:13px;"><?php esc_html_e( 'Tip: Combine shortcodes on a Reviews page', 'twt-aeo-ultimate' ); ?></strong>
				<pre style="margin:10px 0 0;background:#fff;border:1px solid #ddd;padding:12px;border-radius:4px;font-size:12px;overflow-x:auto;"><?php echo esc_html( "[twtaeo_aggregate_rating]\n[twtaeo_reviews limit=\"10\"]\n[twtaeo_review_form]" ); ?></pre>
			</div>
		</div>
		<?php
	}

	// ── Action handling ───────────────────────────────────────────────────────

	private static function maybe_handle_action() {
		$action = sanitize_key( wp_unslash( $_GET['twt_review_action'] ?? '' ) );
		if ( ! $action ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$review_id = absint( wp_unslash( $_GET['twt_review_id'] ?? 0 ) );
		if ( ! $review_id ) {
			return;
		}

		switch ( $action ) {
			case 'approve':
				wp_update_post( array( 'ID' => $review_id, 'post_status' => 'publish' ) );
				break;
			case 'unapprove':
				wp_update_post( array( 'ID' => $review_id, 'post_status' => 'draft' ) );
				break;
			case 'delete':
				wp_delete_post( $review_id, true );
				break;
		}

		// Redirect clean — remove action/nonce params.
		$redirect = add_query_arg(
			array( 'twtaeo_tab' => 'reviews', 'twt_review_action' => false, 'twt_review_id' => false, '_wpnonce' => false ),
			menu_page_url( 'twt-aeo-reviews', false )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function maybe_handle_settings() {
		if ( empty( $_POST['twtaeo_reviews_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- quick bail before nonce check below
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_twtaeo_reviews_nonce'] ?? '' ) ), self::NONCE_SETTINGS ) ) {
			return;
		}

		if ( 'save_settings' === sanitize_key( wp_unslash( $_POST['twtaeo_reviews_action'] ) ) ) {
			$raw = isset( $_POST['twtaeo_reviews'] ) ? map_deep( (array) wp_unslash( $_POST['twtaeo_reviews'] ), 'sanitize_text_field' ) : array();
			TWTAEO_Reviews_Schema_Writer::save_settings( $raw );

			$industry = sanitize_key( wp_unslash( $_POST['twtaeo_reviews_industry'] ?? '' ) );
			$valid    = array_keys( self::get_review_platforms() );
			update_option( self::OPTION_INDUSTRY, in_array( $industry, $valid, true ) ? $industry : '' );

			add_settings_error( 'twtaeo_reviews', 'saved', __( 'Review settings saved.', 'twt-aeo-ultimate' ), 'updated' );
		}

		settings_errors( 'twtaeo_reviews' );
	}

	// ── Review Platform Data ──────────────────────────────────────────────────

	/**
	 * Return the review platform recommendation matrix keyed by industry slug.
	 * Each platform: name, icon (emoji), importance (critical|high|medium|low), note, url.
	 *
	 * @return array
	 */
	private static function get_review_platforms() {
		return array(
			'home-services' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'First result when homeowners search for local contractors', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Angi (HomeAdvisor)',       'icon' => '🔧', 'importance' => 'high',     'note' => 'Top lead-gen directory for home service pros',           'url' => 'https://pro.angi.com' ),
				array( 'name' => 'Thumbtack',                'icon' => '📌', 'importance' => 'high',     'note' => 'Connects contractors with customers by zip code',        'url' => 'https://www.thumbtack.com/pro' ),
				array( 'name' => 'Houzz',                    'icon' => '🏠', 'importance' => 'high',     'note' => 'Essential for remodeling and interior design pros',      'url' => 'https://www.houzz.com/for-pros' ),
				array( 'name' => 'Yelp',                     'icon' => '🍽', 'importance' => 'medium',   'note' => 'Strong local trust signal, especially for trades',      'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Nextdoor',                 'icon' => '🏘', 'importance' => 'medium',   'note' => 'Neighborhood-level referrals and recommendations',       'url' => 'https://business.nextdoor.com' ),
				array( 'name' => 'Facebook',                 'icon' => '👍', 'importance' => 'medium',   'note' => 'Social proof and community trust within local groups',   'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',   'icon' => '🏅', 'importance' => 'medium',   'note' => 'Credibility badge for cautious buyers',                  'url' => 'https://www.bbb.org/businesses' ),
			),
			'retail' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Drives in-store visits and product discovery',      'url' => 'https://business.google.com' ),
				array( 'name' => 'Trustpilot',              'icon' => '✅', 'importance' => 'high',     'note' => 'Most recognized trust badge for ecommerce shoppers', 'url' => 'https://business.trustpilot.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🛍', 'importance' => 'high',     'note' => 'Strong for local retail discovery',                   'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Social commerce and community sharing',               'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'note' => 'Builds consumer confidence for first-time buyers',    'url' => 'https://www.bbb.org/businesses' ),
				array( 'name' => 'Sitejabber',              'icon' => '🔍', 'importance' => 'low',      'note' => 'Online business review aggregator',                   'url' => 'https://www.sitejabber.com/businesses' ),
			),
			'industrial-b2b' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Still the starting point for B2B buyers doing local research', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Clutch',                  'icon' => '🤝', 'importance' => 'critical', 'note' => '#1 B2B service review platform — cited in RFPs',              'url' => 'https://clutch.co/get-listed' ),
				array( 'name' => 'G2',                      'icon' => '💡', 'importance' => 'high',     'note' => 'Dominates software and SaaS B2B reviews',                    'url' => 'https://sell.g2.com' ),
				array( 'name' => 'Capterra',                'icon' => '📊', 'importance' => 'high',     'note' => 'Key platform for decision-makers evaluating vendors',          'url' => 'https://www.capterra.com/vendors' ),
				array( 'name' => 'TrustRadius',             'icon' => '🔬', 'importance' => 'medium',   'note' => 'Deep-dive B2B reviews trusted by enterprise buyers',           'url' => 'https://www.trustradius.com/vendor' ),
				array( 'name' => 'LinkedIn',                'icon' => '💼', 'importance' => 'medium',   'note' => 'Professional credibility and peer recommendations',            'url' => 'https://business.linkedin.com' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'note' => 'Trust signal for procurement and compliance teams',            'url' => 'https://www.bbb.org/businesses' ),
			),
			'healthcare' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Most patients start provider searches on Google',       'url' => 'https://business.google.com' ),
				array( 'name' => 'Healthgrades',            'icon' => '🩺', 'importance' => 'critical', 'note' => 'Top platform used by patients to rate and find doctors', 'url' => 'https://www.healthgrades.com/pro' ),
				array( 'name' => 'Zocdoc',                  'icon' => '📅', 'importance' => 'high',     'note' => 'Combines online booking with patient ratings',           'url' => 'https://www.zocdoc.com/about/providers' ),
				array( 'name' => 'WebMD',                   'icon' => '💊', 'importance' => 'high',     'note' => 'High-authority health directory with provider reviews',   'url' => 'https://doctor.webmd.com/physician-finder/home' ),
				array( 'name' => 'Vitals',                  'icon' => '❤', 'importance' => 'medium',   'note' => 'Patient review aggregator with wide reach',              'url' => 'https://www.vitals.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Community referrals and social proof for practices',     'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'RateMDs',                 'icon' => '📋', 'importance' => 'low',      'note' => 'Peer-rated physician directory',                         'url' => 'https://www.ratemds.com' ),
			),
			'legal' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'First stop for clients searching for local lawyers',      'url' => 'https://business.google.com' ),
				array( 'name' => 'Avvo',                    'icon' => '⚖', 'importance' => 'critical', 'note' => 'Largest online legal directory — rated by both clients and peers', 'url' => 'https://www.avvo.com/attorneys' ),
				array( 'name' => 'Martindale-Hubbell',      'icon' => '🏛', 'importance' => 'high',     'note' => 'Prestigious peer-rated directory, 150+ year history',     'url' => 'https://www.martindale.com' ),
				array( 'name' => 'FindLaw',                 'icon' => '🔍', 'importance' => 'high',     'note' => 'High-traffic attorney directory owned by Thomson Reuters', 'url' => 'https://lawyer.findlaw.com' ),
				array( 'name' => 'Justia',                  'icon' => '📜', 'importance' => 'medium',   'note' => 'Free attorney profiles indexed well by Google',           'url' => 'https://www.justia.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Community referrals for consumer-facing practices',       'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'note' => 'Credibility for consumer and small-business law clients',  'url' => 'https://www.bbb.org/businesses' ),
			),
			'real-estate' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Local search for buyers, sellers, and renters',        'url' => 'https://business.google.com' ),
				array( 'name' => 'Zillow',                  'icon' => '🏠', 'importance' => 'critical', 'note' => 'Most visited real estate platform in the US',          'url' => 'https://www.zillow.com/advertise' ),
				array( 'name' => 'Realtor.com',             'icon' => '🔑', 'importance' => 'high',     'note' => 'NAR-affiliated — high buyer trust and traffic',         'url' => 'https://www.realtor.com/products' ),
				array( 'name' => 'Yelp',                    'icon' => '⭐', 'importance' => 'medium',   'note' => 'Local trust signal for agencies and brokerages',        'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Community referrals in neighborhood groups',            'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Trulia',                  'icon' => '🏘', 'importance' => 'medium',   'note' => 'Zillow-owned — additional exposure for listings',       'url' => 'https://www.trulia.com/agents' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'low',      'note' => 'Credibility signal for larger brokerages',             'url' => 'https://www.bbb.org/businesses' ),
			),
			'restaurant' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Diners check hours, photos, and reviews before visiting', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🍽', 'importance' => 'critical', 'note' => 'Still the #1 platform diners use to choose restaurants',   'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'TripAdvisor',             'icon' => '✈', 'importance' => 'high',     'note' => 'Essential for capturing tourist and visitor traffic',       'url' => 'https://www.tripadvisor.com/owners' ),
				array( 'name' => 'OpenTable',               'icon' => '🍷', 'importance' => 'high',     'note' => 'Reservation platform with verified diner reviews',          'url' => 'https://restaurant.opentable.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Community events and social proof for local dining',        'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Grubhub / DoorDash',      'icon' => '🚚', 'importance' => 'medium',   'note' => 'Delivery platforms with customer ratings',                  'url' => 'https://restaurant.grubhub.com' ),
			),
			'automotive' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Most car buyers and service customers start on Google',    'url' => 'https://business.google.com' ),
				array( 'name' => 'DealerRater',             'icon' => '🚗', 'importance' => 'critical', 'note' => 'Leading automotive dealer review platform',                'url' => 'https://www.dealerrater.com/dealer-resources' ),
				array( 'name' => 'Cars.com',                'icon' => '🔑', 'importance' => 'high',     'note' => 'Major car marketplace with dealer reviews',                'url' => 'https://dealer.cars.com' ),
				array( 'name' => 'Edmunds',                 'icon' => '🔧', 'importance' => 'high',     'note' => 'Trusted automotive research platform with dealer ratings',  'url' => 'https://dealer.edmunds.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🔍', 'importance' => 'medium',   'note' => 'Especially useful for service centers and repair shops',   'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Automotive groups and community-level word of mouth',       'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'note' => 'Trust signal for consumer protection-minded buyers',        'url' => 'https://www.bbb.org/businesses' ),
			),
			'financial' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'First research stop for local financial advisors and firms', 'url' => 'https://business.google.com' ),
				array( 'name' => 'Trustpilot',              'icon' => '✅', 'importance' => 'high',     'note' => 'Most credible trust badge for fintech and financial services', 'url' => 'https://business.trustpilot.com' ),
				array( 'name' => 'WalletHub',               'icon' => '💳', 'importance' => 'high',     'note' => 'Consumer finance comparison site with business reviews',      'url' => 'https://wallethub.com/banks' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'high',     'note' => 'Critical trust signal in regulated financial industries',      'url' => 'https://www.bbb.org/businesses' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Community trust and word-of-mouth referrals',                  'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'LinkedIn',                'icon' => '💼', 'importance' => 'medium',   'note' => 'Professional credibility for B2B financial services',          'url' => 'https://business.linkedin.com' ),
			),
			'general' => array(
				array( 'name' => 'Google Business Profile', 'icon' => '⭐', 'importance' => 'critical', 'note' => 'Essential for any local or online business',             'url' => 'https://business.google.com' ),
				array( 'name' => 'Trustpilot',              'icon' => '✅', 'importance' => 'high',     'note' => 'Universal trust badge recognized by most consumers',     'url' => 'https://business.trustpilot.com' ),
				array( 'name' => 'Yelp',                    'icon' => '🍽', 'importance' => 'high',     'note' => 'Strong local discovery and trust signals',               'url' => 'https://biz.yelp.com' ),
				array( 'name' => 'Facebook',                'icon' => '👍', 'importance' => 'medium',   'note' => 'Social proof and community engagement',                  'url' => 'https://www.facebook.com/business' ),
				array( 'name' => 'Better Business Bureau',  'icon' => '🏅', 'importance' => 'medium',   'note' => 'Credibility and accreditation for any business type',    'url' => 'https://www.bbb.org/businesses' ),
			),
		);
	}

	// ── Review Platforms Panel ────────────────────────────────────────────────

	private static function render_platforms_panel( string $industry ) {
		$settings_url = add_query_arg( 'twtaeo_tab', 'settings', menu_page_url( 'twt-aeo-reviews', false ) );

		if ( ! $industry ) {
			?>
			<div style="margin-top:24px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:24px 28px;display:flex;align-items:center;gap:16px;">
				<span style="font-size:28px;">📍</span>
				<div>
					<strong style="font-size:14px;color:#1e293b;"><?php esc_html_e( 'Where should you be getting reviews?', 'twt-aeo-ultimate' ); ?></strong>
					<p style="margin:4px 0 0;font-size:13px;color:#64748b;">
						<?php esc_html_e( 'Set your business category in', 'twt-aeo-ultimate' ); ?>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'twt-aeo-ultimate' ); ?></a>
						<?php esc_html_e( 'to see a personalized list of the top review platforms for your industry.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		$all_platforms = self::get_review_platforms();
		$platforms     = $all_platforms[ $industry ] ?? array();
		if ( empty( $platforms ) ) {
			return;
		}

		$importance_config = array(
			'critical' => array( 'label' => __( 'Must Have', 'twt-aeo-ultimate' ),        'bg' => '#fee2e2', 'color' => '#991b1b' ),
			'high'     => array( 'label' => __( 'High Value', 'twt-aeo-ultimate' ),        'bg' => '#dbeafe', 'color' => '#1e40af' ),
			'medium'   => array( 'label' => __( 'Recommended', 'twt-aeo-ultimate' ),       'bg' => '#f0fdf4', 'color' => '#166534' ),
			'low'      => array( 'label' => __( 'Optional', 'twt-aeo-ultimate' ),          'bg' => '#f1f5f9', 'color' => '#475569' ),
		);

		// Industry name for heading.
		$industry_labels = array(
			'home-services'  => __( 'Home Services', 'twt-aeo-ultimate' ),
			'retail'         => __( 'Retail', 'twt-aeo-ultimate' ),
			'industrial-b2b' => __( 'Industrial / B2B', 'twt-aeo-ultimate' ),
			'healthcare'     => __( 'Healthcare', 'twt-aeo-ultimate' ),
			'legal'          => __( 'Legal', 'twt-aeo-ultimate' ),
			'real-estate'    => __( 'Real Estate', 'twt-aeo-ultimate' ),
			'restaurant'     => __( 'Restaurant / Food & Drink', 'twt-aeo-ultimate' ),
			'automotive'     => __( 'Automotive', 'twt-aeo-ultimate' ),
			'financial'      => __( 'Financial Services', 'twt-aeo-ultimate' ),
			'general'        => __( 'General / Other', 'twt-aeo-ultimate' ),
		);
		$industry_name = $industry_labels[ $industry ] ?? ucfirst( $industry );
		?>
		<div style="margin-top:28px;">
			<div style="display:flex;align-items:baseline;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
				<h2 style="margin:0;font-size:15px;color:#1e293b;">
					📍 <?php esc_html_e( 'Where to Get More Reviews', 'twt-aeo-ultimate' ); ?>
				</h2>
				<span style="font-size:12px;color:#64748b;">
					<?php
					printf(
						/* translators: %s: industry name */
						esc_html__( 'Recommended platforms for %s businesses', 'twt-aeo-ultimate' ),
						'<strong>' . esc_html( $industry_name ) . '</strong>'
					);
					?>
					&mdash;
					<a href="<?php echo esc_url( $settings_url ); ?>" style="font-size:12px;">
						<?php esc_html_e( 'Change category', 'twt-aeo-ultimate' ); ?>
					</a>
				</span>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
				<?php foreach ( $platforms as $platform ) :
					$imp = $importance_config[ $platform['importance'] ] ?? $importance_config['low'];
				?>
				<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;display:flex;flex-direction:column;gap:8px;">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
						<div style="display:flex;align-items:center;gap:8px;">
							<span style="font-size:20px;line-height:1;"><?php echo esc_html( $platform['icon'] ); ?></span>
							<strong style="font-size:13px;color:#1e293b;"><?php echo esc_html( $platform['name'] ); ?></strong>
						</div>
						<span style="flex-shrink:0;background:<?php echo esc_attr( $imp['bg'] ); ?>;color:<?php echo esc_attr( $imp['color'] ); ?>;border-radius:4px;font-size:10px;font-weight:700;padding:2px 8px;white-space:nowrap;">
							<?php echo esc_html( $imp['label'] ); ?>
						</span>
					</div>
					<p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;"><?php echo esc_html( $platform['note'] ); ?></p>
					<a href="<?php echo esc_url( $platform['url'] ); ?>"
					   target="_blank"
					   rel="noopener noreferrer"
					   style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#2563eb;text-decoration:none;margin-top:auto;">
						<?php esc_html_e( 'Set Up Profile', 'twt-aeo-ultimate' ); ?>
						<span style="font-size:10px;">↗</span>
					</a>
				</div>
				<?php endforeach; ?>
			</div>

			<p style="margin:10px 0 0;font-size:11px;color:#94a3b8;">
				<?php esc_html_e( 'Links open the business/pro sign-up page for each platform in a new tab.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php
	}
}
