<?php
/**
 * Setup Wizard Page
 *
 * Three-mode onboarding: Autopilot, Basics, Expert (progressive multi-step).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Setup_Wizard {

	const OPTION_COMPLETE = 'twtaeo_setup_complete';
	const NONCE_ACTION    = 'twtaeo_wizard_save';
	const NONCE_NAME      = 'twtaeo_wizard_nonce';
	const NONCE_AUTOPILOT = 'twtaeo_wizard_autopilot';

	const BASICS_STEPS = 2;
	const EXPERT_STEPS = 5;

	// ── Entry point ───────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;

		// Handle form POST: save data and redirect to the next step.
		if ( isset( $_POST[ self::NONCE_NAME ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
			if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				self::handle_post( $mode, $step );
			}
		}

		?>
		<div class="twt-aeo-wizard">
		<?php
		if ( 'complete' === $mode ) {
			self::render_complete();
		} elseif ( 'autopilot' === $mode ) {
			self::render_autopilot();
		} elseif ( 'basics' === $mode ) {
			self::render_basics( max( 1, min( $step, self::BASICS_STEPS ) ) );
		} elseif ( 'expert' === $mode ) {
			self::render_expert( max( 1, min( $step, self::EXPERT_STEPS ) ) );
		} else {
			self::render_mode_select();
		}
		?>
		</div>
		<?php
	}

	// ── POST handler ──────────────────────────────────────────────────────────

	private static function handle_post( string $mode, int $step ) {
		if ( 'basics' === $mode ) {
			self::save_basics_step( $step );
			$next = $step + 1;
			if ( $next > self::BASICS_STEPS ) {
				update_option( self::OPTION_COMPLETE, time() );
				wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=complete' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=basics&step=' . $next ) );
			}
			exit;
		}

		if ( 'expert' === $mode ) {
			self::save_expert_step( $step );
			$next = $step + 1;
			if ( $next > self::EXPERT_STEPS ) {
				update_option( self::OPTION_COMPLETE, time() );
				wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=complete' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=expert&step=' . $next ) );
			}
			exit;
		}
	}

	// ── Mode Selection ────────────────────────────────────────────────────────

	private static function render_mode_select() {
		?>
		<div class="twt-aeo-wizard__intro">
			<div class="twt-aeo-wizard__logo-mark">AEO</div>
			<h1 class="twt-aeo-wizard__title"><?php esc_html_e( 'Welcome to TWT AEO Ultimate', 'twt-aeo-ultimate' ); ?></h1>
			<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'Choose your setup experience to get started optimizing for AI search.', 'twt-aeo-ultimate' ); ?></p>
		</div>

		<div class="twt-aeo-wizard__modes">

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=autopilot' ) ); ?>"
			   class="twt-aeo-wizard__mode-card twt-aeo-wizard__mode-card--autopilot">
				<div class="twt-aeo-wizard__mode-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					</svg>
				</div>
				<div class="twt-aeo-wizard__mode-label">
					<span class="twt-aeo-wizard__mode-badge"><?php esc_html_e( 'Recommended', 'twt-aeo-ultimate' ); ?></span>
					<h2><?php esc_html_e( 'Autopilot', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'Let AEO configure itself using your existing site data. Review what will run, then click Go.', 'twt-aeo-ultimate' ); ?></p>
					<ul class="twt-aeo-wizard__mode-features">
						<li><?php esc_html_e( 'FAQ schema detection', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Company info auto-import', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Social profiles from your profile', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Author box pre-fill', 'twt-aeo-ultimate' ); ?></li>
					</ul>
				</div>
				<span class="twt-aeo-wizard__mode-cta"><?php esc_html_e( 'Get started →', 'twt-aeo-ultimate' ); ?></span>
			</a>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=basics&step=1' ) ); ?>"
			   class="twt-aeo-wizard__mode-card twt-aeo-wizard__mode-card--basics">
				<div class="twt-aeo-wizard__mode-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
						<path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 7.76a6 6 0 0 0 0 8.49" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
				</div>
				<div class="twt-aeo-wizard__mode-label">
					<h2><?php esc_html_e( 'The Basics', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'Enter your business info, set up social profiles, and connect analytics tools. Two quick steps.', 'twt-aeo-ultimate' ); ?></p>
					<ul class="twt-aeo-wizard__mode-features">
						<li><?php esc_html_e( 'Business name & contact info', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Social profile URLs', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Google Analytics setup', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'IndexNow / Webmaster Tools', 'twt-aeo-ultimate' ); ?></li>
					</ul>
				</div>
				<span class="twt-aeo-wizard__mode-cta"><?php esc_html_e( 'Start basics →', 'twt-aeo-ultimate' ); ?></span>
			</a>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=expert&step=1' ) ); ?>"
			   class="twt-aeo-wizard__mode-card twt-aeo-wizard__mode-card--expert">
				<div class="twt-aeo-wizard__mode-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					</svg>
				</div>
				<div class="twt-aeo-wizard__mode-label">
					<h2><?php esc_html_e( 'Expert', 'twt-aeo-ultimate' ); ?></h2>
					<p><?php esc_html_e( 'Full control over every setting. Configure schema, author profiles, modules, and API keys step by step.', 'twt-aeo-ultimate' ); ?></p>
					<ul class="twt-aeo-wizard__mode-features">
						<li><?php esc_html_e( 'Complete company identity', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'All social platforms', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Author schema & credentials', 'twt-aeo-ultimate' ); ?></li>
						<li><?php esc_html_e( 'Module selection & API keys', 'twt-aeo-ultimate' ); ?></li>
					</ul>
				</div>
				<span class="twt-aeo-wizard__mode-cta"><?php esc_html_e( 'Configure all settings →', 'twt-aeo-ultimate' ); ?></span>
			</a>

		</div>

		<p class="twt-aeo-wizard__skip">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo' ) ); ?>">
				<?php esc_html_e( 'Skip — go to Dashboard', 'twt-aeo-ultimate' ); ?>
			</a>
		</p>
		<?php
	}

	// ── Autopilot ─────────────────────────────────────────────────────────────

	private static function render_autopilot() {
		$has_yoast    = defined( 'WPSEO_VERSION' );
		$has_rankmath = defined( 'RANK_MATH_VERSION' );
		$has_seo      = $has_yoast || $has_rankmath;

		$company      = TWTAEO_Company_Profile::get();
		$user         = wp_get_current_user();
		$has_bio      = ! empty( get_user_meta( $user->ID, 'description', true ) );
		$has_twitter  = ! empty( get_user_meta( $user->ID, 'twitter', true ) );

		$tasks = array(
			array(
				'id'   => 'faq_schema',
				'icon' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2zm0 12v-1m0-3a2 2 0 1 1 0-4 2 2 0 0 1 0 4z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
				'title' => __( 'FAQ Schema Detection', 'twt-aeo-ultimate' ),
				'desc'  => $has_seo
					? __( 'An SEO plugin is active — FAQ detection will run alongside it without conflicts.', 'twt-aeo-ultimate' )
					: __( 'Enable FAQ schema detection to automatically identify and mark up Q&A content on your site.', 'twt-aeo-ultimate' ),
				'skip'  => false,
			),
			array(
				'id'   => 'company_info',
				'icon' => '<svg viewBox="0 0 20 20" fill="none"><rect x="2" y="7" width="16" height="11" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M6 7V5a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
				'title' => __( 'Company Information', 'twt-aeo-ultimate' ),
				'desc'  => $has_yoast
					? __( 'Yoast SEO detected — will sync company name, logo, and social profiles from Yoast.', 'twt-aeo-ultimate' )
					: ( $has_rankmath
						? __( 'Rank Math detected — will sync company data from Rank Math settings.', 'twt-aeo-ultimate' )
						: sprintf(
							/* translators: %s: site name */
							__( 'Will import "%s" company name, URL, and contact details from WordPress settings.', 'twt-aeo-ultimate' ),
							esc_html( get_bloginfo( 'name' ) )
						)
					),
				'skip'  => false,
			),
			array(
				'id'   => 'social_profiles',
				'icon' => '<svg viewBox="0 0 20 20" fill="none"><path d="M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM2.5 17c0-3.314 3.134-6 7-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="15" cy="15" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M18 12l-2.5 3-1.5-1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'title' => __( 'Social Profiles', 'twt-aeo-ultimate' ),
				'desc'  => $has_twitter
					? __( 'Twitter/X profile found — will map your user profile social links to the company profile.', 'twt-aeo-ultimate' )
					: __( 'Will check your WordPress user profile for social links and map them to the company profile.', 'twt-aeo-ultimate' ),
				'skip'  => false,
			),
			array(
				'id'   => 'author_box',
				'icon' => '<svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="6" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M4 17c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
				'title' => __( 'Author Box', 'twt-aeo-ultimate' ),
				'desc'  => $has_bio
					? __( 'Profile bio found — will enable author schema and pre-fill your author box from your WordPress profile.', 'twt-aeo-ultimate' )
					: __( 'Will enable the author entity module. Add a bio to your WordPress profile to complete your author box.', 'twt-aeo-ultimate' ),
				'skip'  => false,
			),
		);
		?>
		<div class="twt-aeo-wizard__intro">
			<div class="twt-aeo-wizard__logo-mark">AEO</div>
			<h1 class="twt-aeo-wizard__title"><?php esc_html_e( 'Autopilot Setup', 'twt-aeo-ultimate' ); ?></h1>
			<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'These tasks will run automatically in the background. Review them, then click Launch.', 'twt-aeo-ultimate' ); ?></p>
		</div>

		<div class="twt-aeo-wizard__task-list" id="twt-aeo-task-list">
			<?php foreach ( $tasks as $task ) : ?>
			<div class="twt-aeo-wizard__task" data-task="<?php echo esc_attr( $task['id'] ); ?>">
				<div class="twt-aeo-wizard__task-icon"><?php echo wp_kses( $task['icon'], array( 'svg' => array( 'viewbox' => true, 'fill' => true, 'xmlns' => true ), 'path' => array( 'd' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'fill' => true ), 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'stroke' => true, 'stroke-width' => true ), 'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'stroke' => true, 'stroke-width' => true ) ) ); ?></div>
				<div class="twt-aeo-wizard__task-body">
					<strong><?php echo esc_html( $task['title'] ); ?></strong>
					<p><?php echo esc_html( $task['desc'] ); ?></p>
				</div>
				<div class="twt-aeo-wizard__task-status">
					<span class="twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--pending"><?php esc_html_e( 'Pending', 'twt-aeo-ultimate' ); ?></span>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<div class="twt-aeo-wizard__footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-setup-wizard' ) ); ?>"
			   class="twt-aeo-wizard__btn twt-aeo-wizard__btn--back">
				<?php esc_html_e( '← Back', 'twt-aeo-ultimate' ); ?>
			</a>
			<button type="button" id="twt-aeo-autopilot-launch"
			        class="twt-aeo-wizard__btn twt-aeo-wizard__btn--primary">
				<?php esc_html_e( 'Launch Autopilot', 'twt-aeo-ultimate' ); ?>
			</button>
		</div>

		<input type="hidden" id="twt-aeo-autopilot-nonce"
		       value="<?php echo esc_attr( wp_create_nonce( self::NONCE_AUTOPILOT ) ); ?>">
		<input type="hidden" id="twt-aeo-complete-url"
		       value="<?php echo esc_attr( admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=complete' ) ); ?>">
		<?php
	}

	// ── Basics ────────────────────────────────────────────────────────────────

	private static function render_basics( int $step ) {
		$company  = TWTAEO_Company_Profile::get();
		$settings = get_option( 'twtaeo_settings', array() );
		$active   = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );

		$back_url = ( 1 === $step )
			? admin_url( 'admin.php?page=twt-aeo-setup-wizard' )
			: admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=basics&step=' . ( $step - 1 ) );

		$btn_label = ( $step < self::BASICS_STEPS ) ? __( 'Next →', 'twt-aeo-ultimate' ) : __( 'Finish Setup →', 'twt-aeo-ultimate' );

		?>
		<div class="twt-aeo-wizard__step-header">
			<?php self::render_progress( 'basics', $step ); ?>
		</div>

		<form method="post" class="twt-aeo-wizard__form" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<?php if ( 1 === $step ) : ?>

			<div class="twt-aeo-wizard__intro twt-aeo-wizard__intro--compact">
				<h1 class="twt-aeo-wizard__title"><?php esc_html_e( 'Business Information', 'twt-aeo-ultimate' ); ?></h1>
				<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'Enter your business details and key social profiles.', 'twt-aeo-ultimate' ); ?></p>
			</div>

			<div class="twt-aeo-wizard__fields">
				<div class="twt-aeo-wizard__field-row">
					<div class="twt-aeo-wizard__field">
						<label for="wz_name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?></label>
						<input type="text" id="wz_name" name="company[name]"
						       value="<?php echo esc_attr( $company['name'] ); ?>"
						       placeholder="<?php esc_attr_e( 'Your Business Name', 'twt-aeo-ultimate' ); ?>">
					</div>
					<div class="twt-aeo-wizard__field">
						<label for="wz_email"><?php esc_html_e( 'Contact Email', 'twt-aeo-ultimate' ); ?></label>
						<input type="email" id="wz_email" name="company[email]"
						       value="<?php echo esc_attr( $company['email'] ); ?>">
					</div>
				</div>
				<div class="twt-aeo-wizard__field-row">
					<div class="twt-aeo-wizard__field">
						<label for="wz_phone"><?php esc_html_e( 'Phone Number', 'twt-aeo-ultimate' ); ?></label>
						<input type="text" id="wz_phone" name="company[phone]"
						       value="<?php echo esc_attr( $company['phone'] ); ?>"
						       placeholder="+1 (555) 000-0000">
					</div>
					<div class="twt-aeo-wizard__field">
						<label for="wz_description"><?php esc_html_e( 'Short Description', 'twt-aeo-ultimate' ); ?></label>
						<input type="text" id="wz_description" name="company[description]"
						       value="<?php echo esc_attr( $company['description'] ); ?>"
						       placeholder="<?php esc_attr_e( 'What your business does', 'twt-aeo-ultimate' ); ?>">
					</div>
				</div>

				<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'Social Profiles', 'twt-aeo-ultimate' ); ?></div>

				<?php
				$socials = array(
					'social_facebook'  => array( 'label' => __( 'Facebook', 'twt-aeo-ultimate' ),   'ph' => 'https://facebook.com/yourpage' ),
					'social_twitter'   => array( 'label' => __( 'Twitter / X', 'twt-aeo-ultimate' ), 'ph' => 'https://x.com/yourhandle' ),
					'social_linkedin'  => array( 'label' => __( 'LinkedIn', 'twt-aeo-ultimate' ),    'ph' => 'https://linkedin.com/company/yourco' ),
					'social_instagram' => array( 'label' => __( 'Instagram', 'twt-aeo-ultimate' ),   'ph' => 'https://instagram.com/yourhandle' ),
				);
				$social_keys = array_keys( $socials );
				$pairs       = array_chunk( $social_keys, 2 );
				foreach ( $pairs as $pair ) :
				?>
				<div class="twt-aeo-wizard__field-row">
					<?php foreach ( $pair as $key ) :
						$info = $socials[ $key ]; ?>
					<div class="twt-aeo-wizard__field">
						<label for="wz_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $info['label'] ); ?></label>
						<input type="url" id="wz_<?php echo esc_attr( $key ); ?>"
						       name="company[<?php echo esc_attr( $key ); ?>]"
						       value="<?php echo esc_attr( $company[ $key ] ?? '' ); ?>"
						       placeholder="<?php echo esc_attr( $info['ph'] ); ?>">
					</div>
					<?php endforeach; ?>
				</div>
				<?php endforeach; ?>
			</div>

			<?php elseif ( 2 === $step ) : ?>

			<div class="twt-aeo-wizard__intro twt-aeo-wizard__intro--compact">
				<h1 class="twt-aeo-wizard__title"><?php esc_html_e( 'Analytics & Webmaster Tools', 'twt-aeo-ultimate' ); ?></h1>
				<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'Connect your tracking and search engine tools.', 'twt-aeo-ultimate' ); ?></p>
			</div>

			<div class="twt-aeo-wizard__fields">
				<div class="twt-aeo-wizard__field">
					<label for="wz_ga_id"><?php esc_html_e( 'Google Analytics 4 — Measurement ID', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="wz_ga_id" name="settings[ga_tracking_id]"
					       value="<?php echo esc_attr( $settings['ga_tracking_id'] ?? '' ); ?>"
					       placeholder="G-XXXXXXXXXX" style="max-width:260px;">
					<p class="twt-aeo-wizard__hint">
						<?php esc_html_e( 'Paste your GA4 Measurement ID. You\'ll find it in Analytics → Admin → Data Streams.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>

				<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'Search Engine Ping', 'twt-aeo-ultimate' ); ?></div>

				<div class="twt-aeo-wizard__toggle-field">
					<label class="twt-aeo-wizard__toggle">
						<input type="checkbox" name="modules[index-now]" value="1"
						       <?php checked( in_array( 'index-now', $active, true ) ); ?>>
						<span class="twt-aeo-wizard__toggle-slider"></span>
					</label>
					<div class="twt-aeo-wizard__toggle-body">
						<strong><?php esc_html_e( 'IndexNow', 'twt-aeo-ultimate' ); ?></strong>
						<p><?php esc_html_e( 'Automatically ping Bing, Yandex, and other IndexNow-compatible engines when you publish or update content.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				</div>

				<div class="twt-aeo-wizard__toggle-field">
					<label class="twt-aeo-wizard__toggle">
						<input type="checkbox" name="modules[sitemap]" value="1"
						       <?php checked( in_array( 'sitemap', $active, true ) ); ?>>
						<span class="twt-aeo-wizard__toggle-slider"></span>
					</label>
					<div class="twt-aeo-wizard__toggle-body">
						<strong><?php esc_html_e( 'XML Sitemap Generator', 'twt-aeo-ultimate' ); ?></strong>
						<p><?php esc_html_e( 'Generate and serve an XML sitemap at /sitemap.xml for search engine crawlers.', 'twt-aeo-ultimate' ); ?></p>
					</div>
				</div>
			</div>

			<?php endif; ?>

			<div class="twt-aeo-wizard__footer">
				<a href="<?php echo esc_url( $back_url ); ?>"
				   class="twt-aeo-wizard__btn twt-aeo-wizard__btn--back">
					<?php esc_html_e( '← Back', 'twt-aeo-ultimate' ); ?>
				</a>
				<button type="submit" class="twt-aeo-wizard__btn twt-aeo-wizard__btn--primary">
					<?php echo esc_html( $btn_label ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	// ── Expert ────────────────────────────────────────────────────────────────

	private static function render_expert( int $step ) {
		$company  = TWTAEO_Company_Profile::get();
		$settings = get_option( 'twtaeo_settings', array() );
		$active   = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		$user     = wp_get_current_user();

		$step_titles = array(
			1 => __( 'Company Identity', 'twt-aeo-ultimate' ),
			2 => __( 'Social Profiles', 'twt-aeo-ultimate' ),
			3 => __( 'Author Information', 'twt-aeo-ultimate' ),
			4 => __( 'Modules', 'twt-aeo-ultimate' ),
			5 => __( 'API Keys', 'twt-aeo-ultimate' ),
		);

		$back_url = ( 1 === $step )
			? admin_url( 'admin.php?page=twt-aeo-setup-wizard' )
			: admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=expert&step=' . ( $step - 1 ) );

		$btn_label = ( $step < self::EXPERT_STEPS ) ? __( 'Next →', 'twt-aeo-ultimate' ) : __( 'Finish Setup →', 'twt-aeo-ultimate' );
		?>
		<div class="twt-aeo-wizard__step-header">
			<?php self::render_progress( 'expert', $step ); ?>
		</div>

		<div class="twt-aeo-wizard__intro twt-aeo-wizard__intro--compact">
			<h1 class="twt-aeo-wizard__title"><?php echo esc_html( $step_titles[ $step ] ); ?></h1>
		</div>

		<form method="post" class="twt-aeo-wizard__form" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<?php
			switch ( $step ) {
				case 1: self::render_expert_company_identity( $company ); break;
				case 2: self::render_expert_social_profiles( $company ); break;
				case 3: self::render_expert_author_info( $user ); break;
				case 4: self::render_expert_modules( $active ); break;
				case 5: self::render_expert_api_keys( $settings, $active ); break;
			}
			?>

			<div class="twt-aeo-wizard__footer">
				<a href="<?php echo esc_url( $back_url ); ?>"
				   class="twt-aeo-wizard__btn twt-aeo-wizard__btn--back">
					<?php esc_html_e( '← Back', 'twt-aeo-ultimate' ); ?>
				</a>
				<button type="submit" class="twt-aeo-wizard__btn twt-aeo-wizard__btn--primary">
					<?php echo esc_html( $btn_label ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	private static function render_expert_company_identity( array $company ) {
		?>
		<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'Define your organization\'s core identity for structured data and schema output.', 'twt-aeo-ultimate' ); ?></p>
		<div class="twt-aeo-wizard__fields">
			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e1_name"><?php esc_html_e( 'Business Name', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e1_name" name="company[name]"
					       value="<?php echo esc_attr( $company['name'] ); ?>">
				</div>
				<div class="twt-aeo-wizard__field">
					<label for="e1_legal_name"><?php esc_html_e( 'Legal Name', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e1_legal_name" name="company[legal_name]"
					       value="<?php echo esc_attr( $company['legal_name'] ); ?>"
					       placeholder="<?php esc_attr_e( 'Registered legal entity name', 'twt-aeo-ultimate' ); ?>">
				</div>
			</div>
			<div class="twt-aeo-wizard__field">
				<label for="e1_description"><?php esc_html_e( 'Description', 'twt-aeo-ultimate' ); ?></label>
				<textarea id="e1_description" name="company[description]" rows="3"><?php echo esc_textarea( $company['description'] ); ?></textarea>
			</div>
			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e1_url"><?php esc_html_e( 'Website URL', 'twt-aeo-ultimate' ); ?></label>
					<input type="url" id="e1_url" name="company[url]"
					       value="<?php echo esc_attr( $company['url'] ); ?>">
				</div>
				<div class="twt-aeo-wizard__field">
					<label for="e1_founding_year"><?php esc_html_e( 'Year Founded', 'twt-aeo-ultimate' ); ?></label>
					<input type="number" id="e1_founding_year" name="company[founding_year]"
					       value="<?php echo esc_attr( $company['founding_year'] ); ?>"
					       min="1800" max="<?php echo esc_attr( (string) gmdate( 'Y' ) ); ?>"
					       placeholder="<?php echo esc_attr( (string) gmdate( 'Y' ) ); ?>"
					       style="max-width:120px;">
				</div>
			</div>
			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e1_email"><?php esc_html_e( 'Contact Email', 'twt-aeo-ultimate' ); ?></label>
					<input type="email" id="e1_email" name="company[email]"
					       value="<?php echo esc_attr( $company['email'] ); ?>">
				</div>
				<div class="twt-aeo-wizard__field">
					<label for="e1_phone"><?php esc_html_e( 'Phone Number', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e1_phone" name="company[phone]"
					       value="<?php echo esc_attr( $company['phone'] ); ?>"
					       placeholder="+1 (555) 000-0000">
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_expert_social_profiles( array $company ) {
		$socials = array(
			'social_facebook'  => __( 'Facebook', 'twt-aeo-ultimate' ),
			'social_twitter'   => __( 'Twitter / X', 'twt-aeo-ultimate' ),
			'social_linkedin'  => __( 'LinkedIn', 'twt-aeo-ultimate' ),
			'social_instagram' => __( 'Instagram', 'twt-aeo-ultimate' ),
			'social_youtube'   => __( 'YouTube', 'twt-aeo-ultimate' ),
			'social_pinterest' => __( 'Pinterest', 'twt-aeo-ultimate' ),
			'social_wikipedia' => __( 'Wikipedia', 'twt-aeo-ultimate' ),
		);
		?>
		<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'These URLs populate your Organization schema\'s sameAs property and Open Graph tags.', 'twt-aeo-ultimate' ); ?></p>
		<div class="twt-aeo-wizard__fields">
			<?php
			$pairs = array_chunk( array_keys( $socials ), 2 );
			foreach ( $pairs as $pair ) :
			?>
			<div class="twt-aeo-wizard__field-row">
				<?php foreach ( $pair as $key ) : ?>
				<div class="twt-aeo-wizard__field">
					<label for="e2_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $socials[ $key ] ); ?></label>
					<input type="url" id="e2_<?php echo esc_attr( $key ); ?>"
					       name="company[<?php echo esc_attr( $key ); ?>]"
					       value="<?php echo esc_attr( $company[ $key ] ?? '' ); ?>"
					       placeholder="https://">
				</div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>

			<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'Meta / Facebook', 'twt-aeo-ultimate' ); ?></div>
			<div class="twt-aeo-wizard__field" style="max-width:400px;">
				<label for="e2_fb_app_id"><?php esc_html_e( 'Facebook App ID', 'twt-aeo-ultimate' ); ?></label>
				<input type="text" id="e2_fb_app_id" name="company[fb_app_id]"
				       value="<?php echo esc_attr( $company['fb_app_id'] ?? '' ); ?>"
				       placeholder="123456789012345">
				<p class="twt-aeo-wizard__hint"><?php esc_html_e( 'Used to output the fb:app_id meta tag for Facebook Insights and pixel attribution.', 'twt-aeo-ultimate' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_expert_author_info( WP_User $user ) {
		$job_title   = get_user_meta( $user->ID, 'twtaeo_job_title', true );
		$credentials = get_user_meta( $user->ID, 'twtaeo_credentials', true );
		$expertise   = get_user_meta( $user->ID, 'twtaeo_expertise', true );
		$years_exp   = get_user_meta( $user->ID, 'twtaeo_years_experience', true );
		$social_raw  = get_user_meta( $user->ID, 'twtaeo_social', true );
		$social      = is_array( $social_raw ) ? $social_raw : array();
		?>
		<p class="twt-aeo-wizard__subtitle">
			<?php printf(
				/* translators: %s: user display name */
				esc_html__( 'Setting up author information for %s. This data feeds the Author schema and E-E-A-T signals.', 'twt-aeo-ultimate' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>'
			); ?>
		</p>
		<div class="twt-aeo-wizard__fields">
			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e3_job_title"><?php esc_html_e( 'Job Title', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e3_job_title" name="author[job_title]"
					       value="<?php echo esc_attr( $job_title ); ?>"
					       placeholder="<?php esc_attr_e( 'e.g. Founder, Lead Developer', 'twt-aeo-ultimate' ); ?>">
				</div>
				<div class="twt-aeo-wizard__field">
					<label for="e3_credentials"><?php esc_html_e( 'Credentials', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e3_credentials" name="author[credentials]"
					       value="<?php echo esc_attr( $credentials ); ?>"
					       placeholder="<?php esc_attr_e( 'e.g. MBA, PhD, CISSP', 'twt-aeo-ultimate' ); ?>">
				</div>
			</div>
			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e3_expertise"><?php esc_html_e( 'Areas of Expertise', 'twt-aeo-ultimate' ); ?></label>
					<input type="text" id="e3_expertise" name="author[expertise]"
					       value="<?php echo esc_attr( $expertise ); ?>"
					       placeholder="<?php esc_attr_e( 'SEO, WordPress, Marketing (comma-separated)', 'twt-aeo-ultimate' ); ?>">
				</div>
				<div class="twt-aeo-wizard__field" style="max-width:160px;">
					<label for="e3_years"><?php esc_html_e( 'Years of Experience', 'twt-aeo-ultimate' ); ?></label>
					<input type="number" id="e3_years" name="author[years_experience]"
					       value="<?php echo esc_attr( $years_exp ); ?>"
					       min="0" max="80" placeholder="0">
				</div>
			</div>

			<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'Author Social Links', 'twt-aeo-ultimate' ); ?></div>

			<?php
			$author_socials = array(
				'linkedin'   => __( 'LinkedIn', 'twt-aeo-ultimate' ),
				'twitter'    => __( 'Twitter / X', 'twt-aeo-ultimate' ),
				'github'     => __( 'GitHub', 'twt-aeo-ultimate' ),
				'youtube'    => __( 'YouTube', 'twt-aeo-ultimate' ),
			);
			$pairs = array_chunk( array_keys( $author_socials ), 2 );
			foreach ( $pairs as $pair ) :
			?>
			<div class="twt-aeo-wizard__field-row">
				<?php foreach ( $pair as $net ) : ?>
				<div class="twt-aeo-wizard__field">
					<label for="e3_social_<?php echo esc_attr( $net ); ?>"><?php echo esc_html( $author_socials[ $net ] ); ?></label>
					<input type="url" id="e3_social_<?php echo esc_attr( $net ); ?>"
					       name="author[social][<?php echo esc_attr( $net ); ?>]"
					       value="<?php echo esc_attr( $social[ $net ] ?? '' ); ?>"
					       placeholder="https://">
				</div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_expert_modules( array $active ) {
		$modules = array(
			'faq-detector'        => array(
				'title' => __( 'FAQ Schema Detection', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Finds Q&A content and flags missing FAQPage schema.', 'twt-aeo-ultimate' ),
			),
			'service-detector'    => array(
				'title' => __( 'Service Schema Detection', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Identifies service pages and flags missing Service schema.', 'twt-aeo-ultimate' ),
			),
			'contact-detector'    => array(
				'title' => __( 'Contact Schema Detection', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Finds contact forms and flags missing ContactPoint schema.', 'twt-aeo-ultimate' ),
			),
			'author-entity'       => array(
				'title' => __( 'Author Entity', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Outputs Person schema with job title, credentials, and social links.', 'twt-aeo-ultimate' ),
			),
			'social-graph'        => array(
				'title' => __( 'Social Graph (Open Graph)', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Generates Open Graph and Twitter Card meta tags for every page.', 'twt-aeo-ultimate' ),
			),
			'index-now'           => array(
				'title' => __( 'IndexNow', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Pings Bing, Yandex, and others when content is published or updated.', 'twt-aeo-ultimate' ),
			),
			'local-pack'          => array(
				'title' => __( 'Local Pack', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Adds LocalBusiness schema with NAP data for map-pack visibility.', 'twt-aeo-ultimate' ),
			),
			'sitemap'             => array(
				'title' => __( 'XML Sitemap', 'twt-aeo-ultimate' ),
				'desc'  => __( 'Generates a /sitemap.xml for search engine crawlers.', 'twt-aeo-ultimate' ),
			),
		);
		?>
		<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'Enable the modules you need. You can change these at any time from the Modules page.', 'twt-aeo-ultimate' ); ?></p>
		<div class="twt-aeo-wizard__fields">
			<div class="twt-aeo-wizard__module-grid">
				<?php foreach ( $modules as $slug => $info ) : ?>
				<label class="twt-aeo-wizard__module-item <?php echo in_array( $slug, $active, true ) ? 'twt-aeo-wizard__module-item--on' : ''; ?>">
					<input type="checkbox" name="modules[<?php echo esc_attr( $slug ); ?>]" value="1"
					       <?php checked( in_array( $slug, $active, true ) ); ?>>
					<div class="twt-aeo-wizard__module-item-body">
						<strong><?php echo esc_html( $info['title'] ); ?></strong>
						<span><?php echo esc_html( $info['desc'] ); ?></span>
					</div>
					<div class="twt-aeo-wizard__module-check">
						<svg viewBox="0 0 16 16" fill="none"><path d="M3 8l4 4 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</div>
				</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_expert_api_keys( array $settings, array $active ) {
		// WordPress 7.0+ ships the Connectors API — API keys for AI providers
		// are managed centrally at Settings > Connectors rather than per-plugin.
		$wp7_connectors  = function_exists( 'wp_is_connector_registered' );
		$wp7_claude_key  = $wp7_connectors ? trim( (string) get_option( 'connectors_ai_anthropic_api_key', '' ) ) : '';
		$wp7_openai_key  = $wp7_connectors ? trim( (string) get_option( 'connectors_ai_openai_api_key', '' ) ) : '';
		?>
		<p class="twt-aeo-wizard__subtitle"><?php esc_html_e( 'API keys are optional. Add only the services you use.', 'twt-aeo-ultimate' ); ?></p>
		<div class="twt-aeo-wizard__fields">
			<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'Analytics', 'twt-aeo-ultimate' ); ?></div>
			<div class="twt-aeo-wizard__field" style="max-width:400px;">
				<label for="e5_ga_id"><?php esc_html_e( 'Google Analytics 4 — Measurement ID', 'twt-aeo-ultimate' ); ?></label>
				<input type="text" id="e5_ga_id" name="settings[ga_tracking_id]"
				       value="<?php echo esc_attr( $settings['ga_tracking_id'] ?? '' ); ?>"
				       placeholder="G-XXXXXXXXXX">
			</div>

			<div class="twt-aeo-wizard__section-label"><?php esc_html_e( 'AI Content Generation (Optional)', 'twt-aeo-ultimate' ); ?></div>

			<?php if ( $wp7_connectors ) : ?>
			<div class="twt-aeo-wizard__wp7-notice">
				<div class="twt-aeo-wizard__wp7-notice-icon">
					<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5"/>
						<path d="M10 9v5M10 6.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
					</svg>
				</div>
				<div>
					<strong><?php esc_html_e( 'WordPress 7 detected', 'twt-aeo-ultimate' ); ?></strong>
					<p>
						<?php printf(
							wp_kses(
								/* translators: %s: URL to Settings > Connectors */
								__( 'AI provider keys (Claude, OpenAI) are now managed centrally at <a href="%s">Settings &rsaquo; Connectors</a>. Keys configured there take priority. You can still enter keys below as a fallback for older setups.', 'twt-aeo-ultimate' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'options-general.php?page=connectors' ) )
						); ?>
					</p>
				</div>
			</div>
			<?php else : ?>
			<p class="twt-aeo-wizard__hint"><?php esc_html_e( 'Used by the Content Generator module. Only providers with a key will generate content.', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>

			<div class="twt-aeo-wizard__field-row">
				<div class="twt-aeo-wizard__field">
					<label for="e5_claude">
						<?php esc_html_e( 'Claude (Anthropic)', 'twt-aeo-ultimate' ); ?>
						<?php if ( $wp7_claude_key ) : ?>
						<span class="twt-aeo-wizard__key-source"><?php esc_html_e( '— set via Connectors', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</label>
					<input type="password" id="e5_claude" name="settings[api_claude]"
					       value="<?php echo esc_attr( $settings['api_claude'] ?? '' ); ?>"
					       placeholder="<?php echo $wp7_claude_key ? esc_attr__( '— managed via Settings › Connectors —', 'twt-aeo-ultimate' ) : 'sk-ant-…'; ?>"
					       <?php echo $wp7_claude_key ? 'disabled aria-disabled="true"' : ''; ?>
					       autocomplete="new-password">
				</div>
				<div class="twt-aeo-wizard__field">
					<label for="e5_openai">
						<?php esc_html_e( 'OpenAI (ChatGPT)', 'twt-aeo-ultimate' ); ?>
						<?php if ( $wp7_openai_key ) : ?>
						<span class="twt-aeo-wizard__key-source"><?php esc_html_e( '— set via Connectors', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</label>
					<input type="password" id="e5_openai" name="settings[api_openai]"
					       value="<?php echo esc_attr( $settings['api_openai'] ?? '' ); ?>"
					       placeholder="<?php echo $wp7_openai_key ? esc_attr__( '— managed via Settings › Connectors —', 'twt-aeo-ultimate' ) : 'sk-…'; ?>"
					       <?php echo $wp7_openai_key ? 'disabled aria-disabled="true"' : ''; ?>
					       autocomplete="new-password">
				</div>
			</div>
		</div>
		<?php
	}

	// ── Complete ──────────────────────────────────────────────────────────────

	private static function render_complete() {
		?>
		<div class="twt-aeo-wizard__complete">
			<div class="twt-aeo-wizard__complete-icon">
				<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2.5"/>
					<path d="M18 32l10 10 18-18" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<h1 class="twt-aeo-wizard__title"><?php esc_html_e( 'You\'re all set!', 'twt-aeo-ultimate' ); ?></h1>
			<p class="twt-aeo-wizard__subtitle">
				<?php esc_html_e( 'TWT AEO Ultimate is configured and running. Here\'s where to go next.', 'twt-aeo-ultimate' ); ?>
			</p>

			<div class="twt-aeo-wizard__next-steps">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo' ) ); ?>"
				   class="twt-aeo-wizard__next-step">
					<strong><?php esc_html_e( 'Dashboard', 'twt-aeo-ultimate' ); ?></strong>
					<span><?php esc_html_e( 'See your AEO scan results and schema coverage at a glance.', 'twt-aeo-ultimate' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-eeat' ) ); ?>"
				   class="twt-aeo-wizard__next-step">
					<strong><?php esc_html_e( 'E-E-A-T Scorecard', 'twt-aeo-ultimate' ); ?></strong>
					<span><?php esc_html_e( 'Review your Experience, Expertise, Authority, and Trustworthiness signals.', 'twt-aeo-ultimate' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-modules' ) ); ?>"
				   class="twt-aeo-wizard__next-step">
					<strong><?php esc_html_e( 'Modules', 'twt-aeo-ultimate' ); ?></strong>
					<span><?php esc_html_e( 'Enable or disable features any time from the Modules page.', 'twt-aeo-ultimate' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-ai-ready' ) ); ?>"
				   class="twt-aeo-wizard__next-step">
					<strong><?php esc_html_e( 'AI Ready Audit', 'twt-aeo-ultimate' ); ?></strong>
					<span><?php esc_html_e( 'Check robots.txt, schema completeness, and AI crawler access.', 'twt-aeo-ultimate' ); ?></span>
				</a>
			</div>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo' ) ); ?>"
			   class="twt-aeo-wizard__btn twt-aeo-wizard__btn--primary" style="margin-top:32px;">
				<?php esc_html_e( 'Go to Dashboard →', 'twt-aeo-ultimate' ); ?>
			</a>
		</div>
		<?php
	}

	// ── Progress indicator ────────────────────────────────────────────────────

	private static function render_progress( string $mode, int $current ) {
		$total  = ( 'basics' === $mode ) ? self::BASICS_STEPS : self::EXPERT_STEPS;
		$labels = ( 'basics' === $mode )
			? array(
				1 => __( 'Business Info', 'twt-aeo-ultimate' ),
				2 => __( 'Analytics', 'twt-aeo-ultimate' ),
			)
			: array(
				1 => __( 'Identity', 'twt-aeo-ultimate' ),
				2 => __( 'Social', 'twt-aeo-ultimate' ),
				3 => __( 'Author', 'twt-aeo-ultimate' ),
				4 => __( 'Modules', 'twt-aeo-ultimate' ),
				5 => __( 'API Keys', 'twt-aeo-ultimate' ),
			);
		?>
		<div class="twt-aeo-wizard__progress">
			<div class="twt-aeo-wizard__logo-mark twt-aeo-wizard__logo-mark--sm">AEO</div>
			<div class="twt-aeo-wizard__steps">
				<?php for ( $i = 1; $i <= $total; $i++ ) : ?>
				<div class="twt-aeo-wizard__step
					<?php echo $i < $current ? 'twt-aeo-wizard__step--done' : ''; ?>
					<?php echo $i === $current ? 'twt-aeo-wizard__step--active' : ''; ?>
					<?php echo $i > $current ? 'twt-aeo-wizard__step--upcoming' : ''; ?>">
					<div class="twt-aeo-wizard__step-dot">
						<?php if ( $i < $current ) : ?>
						<svg viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<?php else : ?>
						<span><?php echo esc_html( (string) $i ); ?></span>
						<?php endif; ?>
					</div>
					<span class="twt-aeo-wizard__step-label"><?php echo esc_html( $labels[ $i ] ); ?></span>
				</div>
				<?php if ( $i < $total ) : ?>
				<div class="twt-aeo-wizard__step-connector <?php echo $i < $current ? 'twt-aeo-wizard__step-connector--done' : ''; ?>"></div>
				<?php endif; ?>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	// ── Save: Basics ──────────────────────────────────────────────────────────

	private static function save_basics_step( int $step ) {
		if ( 1 === $step ) {
			self::save_company_posted();
		} elseif ( 2 === $step ) {
			self::save_ga_tracking_id();
			self::save_modules_posted( array( 'index-now', 'sitemap' ) );
		}
	}

	// ── Save: Expert ──────────────────────────────────────────────────────────

	private static function save_expert_step( int $step ) {
		switch ( $step ) {
			case 1:
			case 2:
				self::save_company_posted();
				break;
			case 3:
				self::save_author_posted();
				break;
			case 4:
				self::save_modules_posted( array(
					'faq-detector', 'service-detector', 'contact-detector',
					'author-entity', 'social-graph', 'index-now', 'local-pack', 'sitemap',
				) );
				break;
			case 5:
				self::save_ga_tracking_id();
				self::save_api_keys_posted();
				break;
		}
	}

	// ── Shared save helpers ───────────────────────────────────────────────────
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in render() before any save helper is called.

	private static function save_company_posted() {
		if ( empty( $_POST['company'] ) || ! is_array( $_POST['company'] ) ) {
			return;
		}

		$current = TWTAEO_Company_Profile::get();
		$posted  = map_deep( wp_unslash( $_POST['company'] ), 'sanitize_text_field' );

		$url_fields = array( 'url', 'logo_url', 'social_facebook', 'social_twitter', 'social_linkedin', 'social_instagram', 'social_youtube', 'social_wikipedia', 'social_pinterest' );

		$merged = $current;
		foreach ( $posted as $key => $value ) {
			if ( in_array( $key, $url_fields, true ) ) {
				$merged[ $key ] = esc_url_raw( (string) $value );
			} elseif ( 'description' === $key ) {
				$merged[ $key ] = sanitize_textarea_field( (string) $value );
			} elseif ( 'logo_id' === $key ) {
				$merged[ $key ] = absint( $value );
			} elseif ( 'founding_year' === $key ) {
				$merged[ $key ] = absint( $value );
			} else {
				$merged[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		update_option( TWTAEO_Company_Profile::OPTION_KEY, $merged );
	}

	private static function save_author_posted() {
		if ( empty( $_POST['author'] ) || ! is_array( $_POST['author'] ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$posted = map_deep( wp_unslash( $_POST['author'] ), 'sanitize_text_field' );

		if ( isset( $posted['job_title'] ) ) {
			update_user_meta( $user_id, 'twtaeo_job_title', sanitize_text_field( $posted['job_title'] ) );
		}
		if ( isset( $posted['credentials'] ) ) {
			update_user_meta( $user_id, 'twtaeo_credentials', sanitize_text_field( $posted['credentials'] ) );
		}
		if ( isset( $posted['expertise'] ) ) {
			update_user_meta( $user_id, 'twtaeo_expertise', sanitize_text_field( $posted['expertise'] ) );
		}
		if ( isset( $posted['years_experience'] ) ) {
			update_user_meta( $user_id, 'twtaeo_years_experience', absint( $posted['years_experience'] ) );
		}
		if ( ! empty( $posted['social'] ) && is_array( $posted['social'] ) ) {
			$existing = get_user_meta( $user_id, 'twtaeo_social', true );
			$existing = is_array( $existing ) ? $existing : array();
			foreach ( $posted['social'] as $net => $url ) {
				$existing[ sanitize_key( $net ) ] = esc_url_raw( (string) $url );
			}
			update_user_meta( $user_id, 'twtaeo_social', $existing );
		}
	}

	private static function save_modules_posted( array $slugs ) {
		$active = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );

		foreach ( $slugs as $slug ) {
			$checked = ! empty( $_POST['modules'][ $slug ] );
			if ( $checked && ! in_array( $slug, $active, true ) ) {
				$active[] = $slug;
			} elseif ( ! $checked ) {
				$active = array_values( array_diff( $active, array( $slug ) ) );
			}
		}

		update_option( TWTAEO_Module_Loader::OPTION_KEY, $active );
	}

	private static function save_ga_tracking_id() {
		if ( ! isset( $_POST['settings']['ga_tracking_id'] ) ) {
			return;
		}
		$id       = sanitize_text_field( wp_unslash( $_POST['settings']['ga_tracking_id'] ) );
		$settings = get_option( 'twtaeo_settings', array() );
		$settings['ga_tracking_id'] = $id;
		update_option( 'twtaeo_settings', $settings );
	}

	private static function save_api_keys_posted() {
		if ( empty( $_POST['settings'] ) || ! is_array( $_POST['settings'] ) ) {
			return;
		}
		$settings = get_option( 'twtaeo_settings', array() );
		$key_fields = array( 'api_claude', 'api_openai', 'api_perplexity' );
		foreach ( $key_fields as $field ) {
			if ( isset( $_POST['settings'][ $field ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST['settings'][ $field ] ) );
				if ( '' !== $val ) {
					$settings[ $field ] = $val;
				}
			}
		}
		update_option( 'twtaeo_settings', $settings );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// ── AJAX: Autopilot ───────────────────────────────────────────────────────

	public static function ajax_autopilot() {
		check_ajax_referer( self::NONCE_AUTOPILOT, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'twt-aeo-ultimate' ) ) );
		}

		$results = array();
		$active  = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		$changed = false;

		// 1. FAQ schema detection.
		if ( ! in_array( 'faq-detector', $active, true ) ) {
			$active[]  = 'faq-detector';
			$changed   = true;
		}
		$results['faq_schema'] = 'done';

		// 2. Company info import.
		$company = TWTAEO_Company_Profile::get();
		$updated = false;

		// Sync from Yoast if available.
		if ( class_exists( 'WPSEO_Options' ) ) {
			$yoast = method_exists( 'WPSEO_Options', 'get_all' ) ? WPSEO_Options::get_all() : array();
			if ( ! empty( $yoast['company_name'] ) && empty( $company['name'] ) ) {
				$company['name'] = sanitize_text_field( $yoast['company_name'] );
				$updated = true;
			}
			if ( ! empty( $yoast['company_logo'] ) && empty( $company['logo_url'] ) ) {
				$company['logo_url'] = esc_url_raw( $yoast['company_logo'] );
				$updated = true;
			}
		}

		// Sync from Rank Math if available.
		if ( function_exists( 'rank_math' ) ) {
			$rm_name = get_option( 'rank_math_knowledgegraph_name', '' );
			if ( $rm_name && empty( $company['name'] ) ) {
				$company['name'] = sanitize_text_field( $rm_name );
				$updated = true;
			}
		}

		if ( $updated ) {
			update_option( TWTAEO_Company_Profile::OPTION_KEY, $company );
		}
		$results['company_info'] = 'done';

		// 3. Social profiles from current user.
		$user           = wp_get_current_user();
		$user_twitter   = get_user_meta( $user->ID, 'twitter', true );
		$user_url       = $user->user_url;
		$social_updated = false;

		if ( $user_twitter && empty( $company['social_twitter'] ) ) {
			$url = ( strpos( $user_twitter, 'http' ) === 0 ) ? $user_twitter : 'https://x.com/' . ltrim( $user_twitter, '@' );
			$company['social_twitter'] = esc_url_raw( $url );
			$social_updated = true;
		}

		if ( $social_updated ) {
			update_option( TWTAEO_Company_Profile::OPTION_KEY, $company );
		}
		$results['social_profiles'] = 'done';

		// 4. Enable author entity module.
		if ( ! in_array( 'author-entity', $active, true ) ) {
			$active[] = 'author-entity';
			$changed  = true;
		}

		// Pre-fill author box from WP profile if job title is empty.
		$job_title = get_user_meta( $user->ID, 'twtaeo_job_title', true );
		if ( empty( $job_title ) ) {
			// Use role as a fallback placeholder.
			$role = ! empty( $user->roles ) ? ucfirst( reset( $user->roles ) ) : '';
			if ( $role ) {
				update_user_meta( $user->ID, 'twtaeo_job_title', sanitize_text_field( $role ) );
			}
		}
		$results['author_box'] = 'done';

		if ( $changed ) {
			update_option( TWTAEO_Module_Loader::OPTION_KEY, array_values( array_unique( $active ) ) );
		}

		update_option( self::OPTION_COMPLETE, time() );

		wp_send_json_success( array(
			'results'  => $results,
			'redirect' => admin_url( 'admin.php?page=twt-aeo-setup-wizard&mode=complete' ),
		) );
	}
}
