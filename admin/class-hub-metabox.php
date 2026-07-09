<?php
/**
 * Hub Structure Metabox
 *
 * Appears in the right column of the Hub CPT editor. Shows:
 *   — Parent hub link (when editing a sub-page)
 *   — List of connected sub-pages with their draft/published status
 *   — "Add Sub-page" button
 *   — Copy-to-clipboard helper for [twtaeo_hub_nav] / [twtaeo_hub_breadcrumb]
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Hub_Metabox {

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the metabox stylesheet and copy-to-clipboard script on the
	 * Hub CPT editor only.
	 */
	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'twtaeo_hub' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'twt-aeo-hub-metabox',
			TWTAEO_PLUGIN_URL . 'admin/assets/css/hub-metabox.css',
			array(),
			TWTAEO_VERSION
		);
		wp_enqueue_script(
			'twt-aeo-hub-metabox',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/hub-metabox.js',
			array(),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-hub-metabox', 'twtAeoHubMetabox', array(
			'i18n' => array(
				'copied' => __( 'Copied!', 'twt-aeo-ultimate' ),
			),
		) );
	}

	public static function add() {
		add_meta_box(
			'twt-aeo-hub-structure',
			__( 'Hub Structure', 'twt-aeo-ultimate' ),
			array( __CLASS__, 'render' ),
			'twtaeo_hub',
			'side',
			'high'
		);
	}

	public static function render( $post ) {
		$is_sub   = $post->post_parent > 0;
		$children = get_posts( array(
			'post_type'      => 'twtaeo_hub',
			'post_status'    => array( 'publish', 'draft' ),
			'post_parent'    => $post->ID,
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );

		?>
		<div class="twt-hub-mb">

			<?php if ( $is_sub ) : ?>
				<?php $parent_post = get_post( $post->post_parent ); ?>
				<?php if ( $parent_post ) : ?>
				<p class="twt-hub-mb__parent">
					<span class="twt-hub-mb__label"><?php esc_html_e( 'Part of Hub', 'twt-aeo-ultimate' ); ?></span>
					<a href="<?php echo esc_url( get_edit_post_link( $parent_post->ID ) ); ?>">
						<?php echo esc_html( $parent_post->post_title ); ?>
					</a>
				</p>
				<?php endif; ?>
			<?php else : ?>

				<p class="twt-hub-mb__label twt-hub-mb__label--section">
					<?php esc_html_e( 'Sub-pages', 'twt-aeo-ultimate' ); ?>
				</p>

				<?php if ( $children ) : ?>
				<ul class="twt-hub-mb__children">
					<?php foreach ( $children as $child ) : ?>
					<li>
						<a href="<?php echo esc_url( get_edit_post_link( $child->ID ) ); ?>">
							<?php echo esc_html( $child->post_title ); ?>
						</a>
						<?php if ( 'draft' === $child->post_status ) : ?>
						<span class="twt-hub-mb__draft"><?php esc_html_e( 'Draft', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php else : ?>
				<p class="twt-hub-mb__empty">
					<?php esc_html_e( 'No sub-pages yet.', 'twt-aeo-ultimate' ); ?>
				</p>
				<?php endif; ?>

				<?php if ( $post->ID ) : ?>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=twtaeo_hub&post_parent=' . $post->ID ) ); ?>"
				   class="button twt-hub-mb__add-sub">
					<?php esc_html_e( '+ Add Sub-page', 'twt-aeo-ultimate' ); ?>
				</a>
				<?php endif; ?>

			<?php endif; ?>

			<hr class="twt-hub-mb__divider">

			<p class="twt-hub-mb__label twt-hub-mb__label--section">
				<?php esc_html_e( 'Shortcodes', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( ! $is_sub ) : ?>
			<div class="twt-hub-mb__sc-row">
				<code class="twt-hub-mb__sc">[twtaeo_hub_nav]</code>
				<button type="button" class="button button-small twt-hub-copy-sc"
				        data-sc="[twtaeo_hub_nav]">
					<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
				</button>
			</div>
			<p class="twt-hub-mb__hint">
				<?php esc_html_e( 'Paste into hub content — auto-lists sub-pages.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php else : ?>
			<div class="twt-hub-mb__sc-row">
				<code class="twt-hub-mb__sc">[twtaeo_hub_breadcrumb]</code>
				<button type="button" class="button button-small twt-hub-copy-sc"
				        data-sc="[twtaeo_hub_breadcrumb]">
					<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
				</button>
			</div>
			<p class="twt-hub-mb__hint">
				<?php esc_html_e( 'Paste at the top — links back to the parent hub.', 'twt-aeo-ultimate' ); ?>
			</p>
			<?php endif; ?>

		</div>

		<?php
	}
}
