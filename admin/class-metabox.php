<?php
/**
 * AEO Metabox
 *
 * Shows AEO status (intent, schema present/missing) inline
 * in the post editor for pages and posts.
 *
 * Also surfaces FAQ and Service detector results when those modules are active.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Metabox {

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_meta_boxes() {
		$post_types = TWTAEO_Scan_Store::get_scannable_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'twt-aeo-status',
				__( 'AEO Status', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Reuse the dashboard CSS.
		wp_enqueue_style(
			'twt-aeo-admin',
			TWTAEO_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			TWTAEO_VERSION
		);

		// WP 7.0 iframed-editor schema watcher.
		// Reads live block state via wp.data (iframe-safe) and annotates the
		// AEO metabox sidebar with real-time FAQ / Service block detection —
		// without touching the editor iframe DOM via document.querySelector.
		$post_id      = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scan         = $post_id ? TWTAEO_Scan_Store::get_scan( $post_id ) : null;
		$evaluation   = $scan['evaluation'] ?? array();
		$present      = $evaluation['present'] ?? array();
		$modules      = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->modules : null;

		wp_enqueue_script(
			'twt-aeo-editor-watch',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/editor-schema-watch.js',
			array( 'wp-data' ),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-editor-watch', 'twtAeoEditorWatch', array(
			'hasFaqSchema'   => in_array( 'FAQPage', $present, true ),
			'hasServiceSchema' => in_array( 'Service', $present, true ),
			'faqModuleOn'    => $modules && $modules->is_active( 'faq-detector' ),
			'svcModuleOn'    => $modules && $modules->is_active( 'service-detector' ),
		) );

		// One-click schema-generate button handler (delegated; no-ops when the
		// button is not rendered). Reuses the dashboard's AJAX endpoints.
		wp_enqueue_script(
			'twt-aeo-metabox-gen',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/metabox-gen.js',
			array(),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-metabox-gen', 'twtAeoMetaboxGen', array(
			'generating'    => __( 'Generating…', 'twt-aeo-ultimate' ),
			'added'         => __( '✓ Added — reload to refresh status.', 'twt-aeo-ultimate' ),
			'failed'        => __( 'Could not add schema.', 'twt-aeo-ultimate' ),
			'requestFailed' => __( 'Request failed.', 'twt-aeo-ultimate' ),
		) );
	}

	public static function render( $post ) {
		// Guard: new unsaved posts have no ID yet.
		if ( empty( $post->ID ) ) {
			echo '<div class="twt-aeo-metabox"><p style="color:#6b7280;font-size:12px;">'
				. esc_html__( 'Save this page to run an AEO scan.', 'twt-aeo-ultimate' )
				. '</p></div>';
			return;
		}

		$scan = TWTAEO_Scan_Store::get_scan( $post->ID );

		// If not scanned yet, run a quick classify from postmeta only (no HTTP).
		if ( ! $scan ) {
			self::render_unscanned( $post );
			return;
		}

		$intent      = $scan['intent'] ?? array();
		$evaluation  = $scan['evaluation'] ?? array();
		$present     = $evaluation['present'] ?? array();
		$missing     = $evaluation['missing'] ?? array();
		$recommended = $evaluation['recommended'] ?? array();
		$confidence  = $intent['confidence'] ?? 'low';
		$label       = $intent['label'] ?? 'Unknown';
		$scanned_at  = $scan['scanned_at'] ?? '';

		$conf_colors = array(
			'high'   => '#16a34a',
			'medium' => '#d97706',
			'low'    => '#6b7280',
		);
		$conf_color = $conf_colors[ $confidence ] ?? '#6b7280';

		$issues = count( $missing );

		// Run detector scans if modules are active.
		$modules      = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->modules : null;
		$faq_scan     = null;
		$service_scan = null;

		if ( $modules && $modules->is_active( 'faq-detector' ) ) {
			$faq_scan = TWTAEO_FAQ_Detector::scan( $post );
		}
		if ( $modules && $modules->is_active( 'service-detector' ) ) {
			$service_scan = TWTAEO_Service_Detector::scan( $post );
		}

		?>
		<div class="twt-aeo-metabox">

			<div class="twt-aeo-metabox__intent">
				<span class="twt-aeo-metabox__label"><?php echo esc_html( $label ); ?></span>
				<span class="twt-aeo-metabox__confidence" style="color:<?php echo esc_attr( $conf_color ); ?>">
					<?php echo esc_html( ucfirst( $confidence ) ); ?>
				</span>
			</div>

			<?php if ( $issues > 0 ) : ?>
			<div class="twt-aeo-metabox__alert">
				<span class="dashicons dashicons-warning"></span>
				<?php
				printf(
					esc_html(
						// translators: %d: number of missing schema types.
						_n( '%d schema type missing', '%d schema types missing', $issues, 'twt-aeo-ultimate' )
					),
					absint( $issues )
				);
				?>
			</div>
			<?php else : ?>
			<div class="twt-aeo-metabox__ok">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'All expected schema present', 'twt-aeo-ultimate' ); ?>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $present ) ) : ?>
			<div class="twt-aeo-metabox__section">
				<div class="twt-aeo-metabox__section-title"><?php esc_html_e( 'Present', 'twt-aeo-ultimate' ); ?></div>
				<div class="twt-aeo-metabox__tags">
					<?php foreach ( $present as $type ) : ?>
						<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present"><?php echo esc_html( $type ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $missing ) ) : ?>
			<div class="twt-aeo-metabox__section">
				<div class="twt-aeo-metabox__section-title"><?php esc_html_e( 'Missing', 'twt-aeo-ultimate' ); ?></div>
				<div class="twt-aeo-metabox__tags">
					<?php foreach ( $missing as $type ) : ?>
						<?php self::tag_with_fix( $type, 'twt-aeo-metabox__tag--missing' ); ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $recommended ) ) : ?>
			<div class="twt-aeo-metabox__section">
				<div class="twt-aeo-metabox__section-title"><?php esc_html_e( 'Recommended', 'twt-aeo-ultimate' ); ?></div>
				<div class="twt-aeo-metabox__tags">
					<?php foreach ( $recommended as $type ) : ?>
						<?php self::tag_with_fix( $type, 'twt-aeo-metabox__tag--warn' ); ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php // ── FAQ Detector results ────────────────────────────────── ?>
			<?php if ( $faq_scan && $faq_scan['has_faq_content'] ) : ?>
			<div class="twt-aeo-metabox__section" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08);">
				<div class="twt-aeo-metabox__section-title">
					<?php esc_html_e( 'FAQ Content', 'twt-aeo-ultimate' ); ?>
				</div>
				<?php if ( $faq_scan['has_faq_schema'] ) : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present">
						✓ <?php esc_html_e( 'FAQPage Schema Present', 'twt-aeo-ultimate' ); ?>
					</span>
				<?php else : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--missing">
						<?php esc_html_e( 'FAQPage Schema Missing', 'twt-aeo-ultimate' ); ?>
					</span>
					<?php self::gen_button( 'twtaeo_faq_generate_one', TWTAEO_FAQ_Detector::NONCE_GENERATE, __( 'Add FAQ Schema', 'twt-aeo-ultimate' ), $post->ID ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php // ── Service Detector results ─────────────────────────────── ?>
			<?php if ( $service_scan && $service_scan['has_service_content'] ) : ?>
			<div class="twt-aeo-metabox__section" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08);">
				<div class="twt-aeo-metabox__section-title">
					<?php esc_html_e( 'Service Content', 'twt-aeo-ultimate' ); ?>
				</div>
				<?php if ( $service_scan['has_service_schema'] ) : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present">
						✓ <?php esc_html_e( 'Service Schema Present', 'twt-aeo-ultimate' ); ?>
					</span>
				<?php else : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--missing">
						<?php esc_html_e( 'Service Schema Missing', 'twt-aeo-ultimate' ); ?>
					</span>
					<?php self::gen_button( 'twtaeo_service_generate_one', 'twtaeo_service_schema_nonce', __( 'Add Service Schema', 'twt-aeo-ultimate' ), $post->ID ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $scanned_at ) : ?>
			<div class="twt-aeo-metabox__footer">
				<?php
				printf(
					// translators: %s: human-readable time difference (e.g. "5 minutes").
					esc_html__( 'Last scanned %s ago', 'twt-aeo-ultimate' ),
					esc_html( human_time_diff( strtotime( $scanned_at ), current_time( 'timestamp' ) ) )
				);
				?>
				· <a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo' ) ); ?>" class="twt-aeo-metabox__link">
					<?php esc_html_e( 'View dashboard', 'twt-aeo-ultimate' ); ?>
				</a>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * One-click "Add FAQ Schema" button — reuses the FAQ detector's generator
	 * (extracts the page's Q&A and writes FAQPage JSON-LD to the custom store).
	 * Admins only, since the AJAX endpoint requires manage_options.
	 *
	 * @param int $post_id
	 */
	private static function gen_button( $action, $nonce_action, $label, $post_id ) {
		if ( ! current_user_can( 'manage_twtaeo' ) ) {
			return;
		}
		?>
		<div style="margin-top:6px;">
			<button type="button" class="button button-small twt-aeo-gen-btn"
					data-action="<?php echo esc_attr( $action ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>"
					data-post="<?php echo esc_attr( $post_id ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
			<span class="twt-aeo-gen-msg" style="display:block;font-size:11px;margin-top:4px;"></span>
		</div>
		<?php
	}

	/**
	 * Admin URL of the existing tool that handles a given (site-wide or entity)
	 * schema type, for a "Fix" deep-link. Empty when no tool applies (e.g. WebPage
	 * is emitted by the SEO plugin, not us).
	 *
	 * @param string $type
	 * @return string
	 */
	private static function fix_link_for_type( $type ) {
		$map = array(
			'Organization'  => 'twt-aeo-schema-detector', // Contact/Organization schema tool.
			'ContactPage'   => 'twt-aeo-schema-detector',
			'PostalAddress' => 'twt-aeo-local-pack',
			'LocalBusiness' => 'twt-aeo-local-pack',
			'Person'        => 'twt-aeo-eeat',
		);
		return empty( $map[ $type ] ) ? '' : admin_url( 'admin.php?page=' . $map[ $type ] );
	}

	/** Render a missing/recommended tag, with a "Fix" deep-link when a tool handles it. */
	private static function tag_with_fix( $type, $tag_class ) {
		$link = self::fix_link_for_type( $type );
		echo '<span class="twt-aeo-metabox__tag ' . esc_attr( $tag_class ) . '">' . esc_html( $type ) . '</span>';
		if ( $link && current_user_can( 'manage_twtaeo' ) ) {
			echo ' <a href="' . esc_url( $link ) . '" class="twt-aeo-metabox__link" style="font-size:11px;">' . esc_html__( 'Fix', 'twt-aeo-ultimate' ) . '</a>';
		}
	}

	private static function render_unscanned( $post ) {
		// Run a lightweight classify from postmeta only — no HTTP fetch.
		$intent = TWTAEO_Page_Intent::classify( $post );

		// Run detector scans if modules are active.
		$modules      = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->modules : null;
		$faq_scan     = null;
		$service_scan = null;

		if ( $modules && $modules->is_active( 'faq-detector' ) ) {
			$faq_scan = TWTAEO_FAQ_Detector::scan( $post );
		}
		if ( $modules && $modules->is_active( 'service-detector' ) ) {
			$service_scan = TWTAEO_Service_Detector::scan( $post );
		}
		?>
		<div class="twt-aeo-metabox">
			<div class="twt-aeo-metabox__intent">
				<span class="twt-aeo-metabox__label"><?php echo esc_html( $intent['label'] ); ?></span>
				<span class="twt-aeo-metabox__confidence" style="color:#6b7280;">
					<?php echo esc_html( ucfirst( $intent['confidence'] ) ); ?>
				</span>
			</div>
			<div class="twt-aeo-metabox__notice">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Not scanned yet. Save this page to run an AEO scan.', 'twt-aeo-ultimate' ); ?>
			</div>

			<?php if ( $faq_scan && $faq_scan['has_faq_content'] ) : ?>
			<div class="twt-aeo-metabox__section" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08);">
				<div class="twt-aeo-metabox__section-title"><?php esc_html_e( 'FAQ Content', 'twt-aeo-ultimate' ); ?></div>
				<?php if ( $faq_scan['has_faq_schema'] ) : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present">✓ <?php esc_html_e( 'FAQPage Schema Present', 'twt-aeo-ultimate' ); ?></span>
				<?php else : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--missing"><?php esc_html_e( 'FAQPage Schema Missing', 'twt-aeo-ultimate' ); ?></span><?php self::gen_button( 'twtaeo_faq_generate_one', TWTAEO_FAQ_Detector::NONCE_GENERATE, __( 'Add FAQ Schema', 'twt-aeo-ultimate' ), $post->ID ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $service_scan && $service_scan['has_service_content'] ) : ?>
			<div class="twt-aeo-metabox__section" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08);">
				<div class="twt-aeo-metabox__section-title"><?php esc_html_e( 'Service Content', 'twt-aeo-ultimate' ); ?></div>
				<?php if ( $service_scan['has_service_schema'] ) : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--present">✓ <?php esc_html_e( 'Service Schema Present', 'twt-aeo-ultimate' ); ?></span>
				<?php else : ?>
					<span class="twt-aeo-metabox__tag twt-aeo-metabox__tag--missing"><?php esc_html_e( 'Service Schema Missing', 'twt-aeo-ultimate' ); ?></span><?php self::gen_button( 'twtaeo_service_generate_one', 'twtaeo_service_schema_nonce', __( 'Add Service Schema', 'twt-aeo-ultimate' ), $post->ID ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}
}