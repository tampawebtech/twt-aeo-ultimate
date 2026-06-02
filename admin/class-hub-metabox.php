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

		<style>
		.twt-hub-mb { font-size: 13px; }
		.twt-hub-mb__label { font-weight: 600; color: #374151; margin: 0 0 6px; }
		.twt-hub-mb__label--section { text-transform: uppercase; font-size: 11px; color: #9ca3af; letter-spacing: .04em; }
		.twt-hub-mb__parent { margin: 0 0 12px; color: #6b7280; }
		.twt-hub-mb__parent a { color: #2563eb; }
		.twt-hub-mb__children { margin: 0 0 10px; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 5px; }
		.twt-hub-mb__children a { color: #2563eb; text-decoration: none; }
		.twt-hub-mb__children a:hover { text-decoration: underline; }
		.twt-hub-mb__draft { font-size: 10px; background: #fef3c7; color: #92400e; padding: 1px 5px; border-radius: 3px; margin-left: 5px; }
		.twt-hub-mb__empty { color: #9ca3af; font-style: italic; margin: 0 0 10px; }
		.twt-hub-mb__add-sub { margin-bottom: 12px !important; }
		.twt-hub-mb__divider { margin: 12px 0; border: none; border-top: 1px solid #e5e7eb; }
		.twt-hub-mb__sc-row { display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
		.twt-hub-mb__sc { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 11px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.twt-hub-mb__hint { font-size: 11px; color: #9ca3af; margin: 0 0 8px; }
		</style>

		<?php
		ob_start();
		?>
		( function () {
			document.querySelectorAll( '.twt-hub-copy-sc' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var sc  = btn.getAttribute( 'data-sc' );
					var orig = btn.textContent;
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( sc ).then( function () {
							btn.textContent = '<?php echo esc_js( __( 'Copied!', 'twt-aeo-ultimate' ) ); ?>';
							setTimeout( function () { btn.textContent = orig; }, 1800 );
						} );
					}
				} );
			} );
		} )();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
