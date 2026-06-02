<?php
/**
 * PR Bridge — Post Editor Metabox
 *
 * Renders the PR Bridge panel in the post editor sidebar.
 * When press release intent is detected, shows the Professionalize button.
 * Also injects the review modal HTML into the footer.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_PR_Metabox {

	const NONCE_TRANSFORM = 'twtaeo_pr_transform_nonce';
	const NONCE_SAVE      = 'twtaeo_pr_save_nonce';
	const NONCE_SUBMIT    = 'twtaeo_pr_submit_nonce';

	public static function register_hooks() {
		add_action( 'add_meta_boxes',         array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'admin_footer',           array( __CLASS__, 'render_modal' ) );
		add_action( 'admin_enqueue_scripts',  array( __CLASS__, 'enqueue' ) );
	}

	public static function add_meta_boxes() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'twt-aeo-pr-bridge',
				__( 'PR Bridge AI', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'twt-aeo-pr-bridge',
			TWTAEO_PLUGIN_URL . 'admin/assets/css/pr-bridge.css',
			array(),
			TWTAEO_VERSION
		);

		wp_enqueue_script(
			'twt-aeo-pr-bridge',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/pr-bridge.js',
			array( 'jquery', 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);

		wp_localize_script( 'twt-aeo-pr-bridge', 'twtAeoPR', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonceTransform' => wp_create_nonce( self::NONCE_TRANSFORM ),
			'nonceSave'     => wp_create_nonce( self::NONCE_SAVE ),
			'nonceSubmit'   => wp_create_nonce( self::NONCE_SUBMIT ),
			'postId'        => isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) );
		wp_set_script_translations( 'twt-aeo-pr-bridge', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
	}

	public static function render( $post ) {
		if ( empty( $post->ID ) ) {
			echo '<p style="color:#6b7280;font-size:12px;">'
				. esc_html__( 'Save this post first to enable PR Bridge.', 'twt-aeo-ultimate' )
				. '</p>';
			return;
		}

		$intent       = TWTAEO_PR_Detector::get_intent( $post );
		$formatted    = TWTAEO_PR_Transformer::get( $post->ID );
		$submissions  = TWTAEO_PR_Distributor::get_submissions( $post->ID );
		$tiers        = TWTAEO_PR_Distributor::get_tiers();
		$settings     = get_option( 'twtaeo_settings', array() );
		$has_claude   = ! empty( trim( $settings['api_claude'] ?? '' ) );

		?>
		<div class="twt-aeo-pr-metabox" id="twt-aeo-pr-metabox">

			<?php if ( $intent['detected'] ) : ?>
			<div class="twt-aeo-pr-detected">
				<span class="dashicons dashicons-megaphone"></span>
				<div>
					<strong><?php esc_html_e( 'Press release detected', 'twt-aeo-ultimate' ); ?></strong>
					<span class="twt-aeo-pr-method">
						<?php echo esc_html( ucfirst( $intent['method'] ) ); ?>
					</span>
				</div>
			</div>
			<?php else : ?>
			<div class="twt-aeo-pr-undetected">
				<span class="dashicons dashicons-info-outline"></span>
				<span><?php esc_html_e( 'No press release signals detected.', 'twt-aeo-ultimate' ); ?></span>
			</div>
			<?php endif; ?>

			<!-- Professionalize section -->
			<div class="twt-aeo-pr-section">
				<div class="twt-aeo-pr-section__title"><?php esc_html_e( 'AP-Style Formatting', 'twt-aeo-ultimate' ); ?></div>

				<?php if ( $formatted ) : ?>
				<div class="twt-aeo-pr-saved">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'Press release formatted', 'twt-aeo-ultimate' ); ?>
				</div>
				<div class="twt-aeo-pr-actions">
					<button type="button" class="button" id="twt-aeo-pr-review-btn">
						<?php esc_html_e( 'Review / Edit', 'twt-aeo-ultimate' ); ?>
					</button>
					<button type="button" class="button twt-aeo-pr-danger" id="twt-aeo-pr-delete-btn"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Delete', 'twt-aeo-ultimate' ); ?>
					</button>
				</div>
				<?php elseif ( $has_claude ) : ?>
				<button type="button" class="button button-primary twt-aeo-pr-transform-btn" id="twt-aeo-pr-transform-btn"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<span class="dashicons dashicons-edit"></span>
					<?php esc_html_e( 'Professionalize with Claude', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php else : ?>
				<div class="twt-aeo-pr-setup-notice">
					<span class="dashicons dashicons-admin-network"></span>
					<div>
						<strong><?php esc_html_e( 'API key required', 'twt-aeo-ultimate' ); ?></strong>
						<span><?php esc_html_e( 'Add your Claude API key to enable AI formatting.', 'twt-aeo-ultimate' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) ); ?>" class="twt-aeo-pr-setup-link">
							<?php esc_html_e( 'Go to Settings →', 'twt-aeo-ultimate' ); ?>
						</a>
					</div>
				</div>
				<?php endif; ?>
			</div>

			<!-- Distribution section — only shown when a formatted PR exists -->
			<?php if ( $formatted ) : ?>
			<div class="twt-aeo-pr-section">
				<div class="twt-aeo-pr-section__title"><?php esc_html_e( 'Distribute', 'twt-aeo-ultimate' ); ?></div>

				<select id="twt-aeo-pr-tier" class="twt-aeo-pr-select">
					<option value=""><?php esc_html_e( '— Select distribution tier —', 'twt-aeo-ultimate' ); ?></option>
					<?php foreach ( $tiers as $tier ) : ?>
					<option
						value="<?php echo esc_attr( $tier['slug'] ); ?>"
						<?php disabled( ! $tier['configured'] && empty( $tier['contact'] ) ); ?>
					>
						<?php echo esc_html( $tier['label'] ); ?>
						<?php if ( ! $tier['configured'] && empty( $tier['contact'] ) ) : ?>
							<?php esc_html_e( '(key needed)', 'twt-aeo-ultimate' ); ?>
						<?php elseif ( ! empty( $tier['contact'] ) ) : ?>
							<?php esc_html_e( '(contact for access)', 'twt-aeo-ultimate' ); ?>
						<?php endif; ?>
					</option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="button button-primary" id="twt-aeo-pr-submit-btn"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
					<?php esc_html_e( 'Submit to Wire', 'twt-aeo-ultimate' ); ?>
				</button>

				<div id="twt-aeo-pr-submit-status" class="twt-aeo-pr-status" style="display:none;"></div>
			</div>
			<?php endif; ?>

			<!-- Submission history -->
			<?php if ( ! empty( $submissions ) ) : ?>
			<div class="twt-aeo-pr-section">
				<div class="twt-aeo-pr-section__title"><?php esc_html_e( 'Distribution History', 'twt-aeo-ultimate' ); ?></div>
				<ul class="twt-aeo-pr-history">
					<?php foreach ( array_reverse( $submissions ) as $sub ) : ?>
					<li class="twt-aeo-pr-history__item <?php echo $sub['success'] ? 'twt-aeo-pr-history__item--ok' : 'twt-aeo-pr-history__item--err'; ?>">
						<strong><?php echo esc_html( $sub['provider'] ); ?></strong>
						<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['submitted_at'] ) ) ); ?></span>
						<?php if ( $sub['success'] && $sub['url'] ) : ?>
						<a href="<?php echo esc_url( $sub['url'] ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?>
						</a>
						<?php elseif ( ! $sub['success'] ) : ?>
						<span class="twt-aeo-pr-history__err"><?php echo esc_html( $sub['message'] ); ?></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Inject the review modal into admin_footer.
	 * Only rendered on post edit screens.
	 */
	public static function render_modal() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post' ), true ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$formatted = $post_id ? TWTAEO_PR_Transformer::get( $post_id ) : '';
		$post      = $post_id ? get_post( $post_id ) : null;
		$original  = $post ? wp_strip_all_tags( $post->post_content ) : '';

		?>
		<div id="twt-aeo-pr-modal" class="twt-aeo-pr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="twt-aeo-pr-modal-title">
			<div class="twt-aeo-pr-modal__overlay"></div>
			<div class="twt-aeo-pr-modal__box">

				<div class="twt-aeo-pr-modal__header">
					<h2 id="twt-aeo-pr-modal-title" class="twt-aeo-pr-modal__title">
						<span class="dashicons dashicons-megaphone"></span>
						<?php esc_html_e( 'PR Bridge — Review & Edit', 'twt-aeo-ultimate' ); ?>
					</h2>
					<button type="button" class="twt-aeo-pr-modal__close" id="twt-aeo-pr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'twt-aeo-ultimate' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div class="twt-aeo-pr-modal__body">

					<div class="twt-aeo-pr-modal__col">
						<div class="twt-aeo-pr-modal__col-head">
							<?php esc_html_e( 'Original Post', 'twt-aeo-ultimate' ); ?>
						</div>
						<textarea id="twt-aeo-pr-original" class="twt-aeo-pr-modal__textarea" readonly><?php echo esc_textarea( $original ); ?></textarea>
					</div>

					<div class="twt-aeo-pr-modal__col">
						<div class="twt-aeo-pr-modal__col-head">
							<?php esc_html_e( 'AP-Style Press Release', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-pr-modal__hint"><?php esc_html_e( '(editable)', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<div class="twt-aeo-pr-modal__spinner" id="twt-aeo-pr-modal-spinner" style="display:none;">
							<span class="twt-aeo-cg-spinner"></span>
							<?php esc_html_e( 'Claude is writing your press release…', 'twt-aeo-ultimate' ); ?>
						</div>
						<textarea id="twt-aeo-pr-formatted" class="twt-aeo-pr-modal__textarea"><?php echo esc_textarea( $formatted ); ?></textarea>
					</div>

				</div>

				<div class="twt-aeo-pr-modal__footer">
					<div class="twt-aeo-pr-modal__footer-left">
						<span id="twt-aeo-pr-modal-status" class="twt-aeo-pr-modal__save-status"></span>
					</div>
					<div class="twt-aeo-pr-modal__footer-right">
						<button type="button" class="button" id="twt-aeo-pr-modal-discard">
							<?php esc_html_e( 'Discard', 'twt-aeo-ultimate' ); ?>
						</button>
						<button type="button" class="button button-primary" id="twt-aeo-pr-modal-save"
							data-post-id="<?php echo esc_attr( $post_id ); ?>">
							<?php esc_html_e( 'Save as Press Release', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
