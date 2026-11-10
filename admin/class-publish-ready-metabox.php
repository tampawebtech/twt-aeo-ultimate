<?php
/**
 * "Finish this page": the Publish Ready panel in the post editor.
 *
 * Registers the classic meta box and enqueues the one script that draws both
 * surfaces. In the block editor the meta box is marked back-compat so Gutenberg
 * hides it, and the same script registers a native sidebar instead: two places
 * showing the same fields would be two places to disagree.
 *
 * The panel is drawn by JavaScript from the REST payload, and the first paint
 * is handed over inline so opening the editor costs no round trip.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Publish_Ready_Metabox {

	const BOX_ID = 'twt-aeo-publish-ready';

	private function __construct() {}

	public static function register_hooks() {
		// Priority 9, ahead of AEO Status at the default 10. Within one context
		// and priority, boxes stack in registration order, and the box an author
		// acts on belongs above the one they only read.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_meta_boxes() {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return;
		}

		foreach ( TWTAEO_Scan_Store::get_scannable_post_types() as $post_type ) {
			add_meta_box(
				self::BOX_ID,
				__( 'Finish this page', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'high'
			);
			// Deliberately NOT flagged __back_compat_meta_box. That flag makes
			// Gutenberg drop the box entirely, which left the sidebar as the
			// only way in: if it failed to mount for any reason, a browser
			// without the editor packages, another plugin breaking the bundle,
			// an author who could not find it, there was nothing to fall back
			// to and the panel simply did not exist. The box now always
			// renders, and the script hides it once the sidebar is really
			// mounted, so the duplicate goes away only when the replacement
			// is confirmed working.
		}
	}

	/**
	 * The container the script draws into. Nothing is rendered server side
	 * beyond this, so there is one renderer rather than one per surface.
	 *
	 * @param WP_Post $post
	 */
	public static function render( $post ) {
		if ( empty( $post->ID ) ) {
			echo '<p class="twtaeo-pr__hint">' . esc_html__( 'Save a draft to see what this page still needs.', 'twt-aeo-ultimate' ) . '</p>';
			return;
		}
		?>
		<div class="twtaeo-pr" data-twtaeo-pr-root="1">
			<p class="twtaeo-pr__hint"><?php esc_html_e( 'Loading…', 'twt-aeo-ultimate' ); ?></p>
		</div>
		<noscript>
			<p class="twtaeo-pr__hint">
				<?php esc_html_e( 'This panel needs JavaScript. The same fields are on the AEO Score, Social Graph and Image SEO screens.', 'twt-aeo-ultimate' ); ?>
			</p>
		</noscript>
		<?php
	}

	/**
	 * @param string $hook
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! TWTAEO_Publish_Ready::supports( $post ) ) {
			return;
		}

		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		// The share and featured image pickers are wp.media.
		wp_enqueue_media();

		wp_enqueue_style(
			'twt-aeo-publish-ready',
			TWTAEO_PLUGIN_URL . 'admin/assets/css/publish-ready.css',
			array(),
			self::asset_version( 'admin/assets/css/publish-ready.css' )
		);

		wp_enqueue_script(
			'twt-aeo-publish-ready',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/publish-ready.js',
			// wp-plugins / wp-edit-post are only used when the block editor is
			// the one loading: the script checks before registering a sidebar.
			array( 'wp-api-fetch', 'wp-element', 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-data', 'wp-blocks', 'wp-components', 'wp-i18n' ),
			self::asset_version( 'admin/assets/js/publish-ready.js' ),
			true
		);

		$fields = TWTAEO_Publish_Ready::fields( $post );

		wp_localize_script(
			'twt-aeo-publish-ready',
			'twtAeoPublishReady',
			array(
				'postId'  => (int) $post->ID,
				'root'    => '/aeo/v1/publish-ready',
				'initial' => array(
					'post_id'             => (int) $post->ID,
					'fields'              => $fields,
					'summary'             => TWTAEO_Publish_Ready::summary( $fields ),
					'notice'              => '',
					'images'              => self::image_rows( $post ),
					'ask_before_spending' => TWTAEO_Publish_Ready::asks_before_spending(),
				),
				'states'  => array(
					'filled'    => TWTAEO_Publish_Ready::FILLED,
					'missing'   => TWTAEO_Publish_Ready::MISSING,
					'templated' => TWTAEO_Publish_Ready::TEMPLATED,
					'inherited' => TWTAEO_Publish_Ready::INHERITED,
					'unknown'   => TWTAEO_Publish_Ready::UNKNOWN,
				),
				'i18n'    => self::strings(),
			)
		);

		wp_set_script_translations( 'twt-aeo-publish-ready', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
	}

	/**
	 * Cache-busting version for one of this panel's assets.
	 *
	 * The plugin version alone is not enough: it only moves on a release, so
	 * every change to these two files between releases is served from the
	 * browser's cache and looks like the change did nothing. The file's own
	 * modification time moves whenever the file does, and on a released copy
	 * it is stable for everyone who installed that release.
	 *
	 * @param string $relative Path inside the plugin directory.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		$path = TWTAEO_PLUGIN_DIR . $relative;
		$time = file_exists( $path ) ? filemtime( $path ) : 0;

		return $time ? TWTAEO_VERSION . '.' . $time : TWTAEO_VERSION;
	}

	/**
	 * Every string the panel shows. Kept here rather than in the script so the
	 * POT file picks them up and a translator never has to touch JavaScript.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
			'title'            => __( 'Finish this page', 'twt-aeo-ultimate' ),
			'allDone'          => __( 'Nothing outstanding on this page.', 'twt-aeo-ultimate' ),
			/* translators: %d: number of fields still outstanding. */
			'outstanding'      => __( '%d still to do', 'twt-aeo-ultimate' ),
			'stateFilled'      => __( 'done', 'twt-aeo-ultimate' ),
			'stateMissing'     => __( 'missing', 'twt-aeo-ultimate' ),
			'stateTemplated'   => __( 'from a template', 'twt-aeo-ultimate' ),
			'stateInherited'   => __( 'inherited', 'twt-aeo-ultimate' ),
			'stateUnknown'     => __( 'cannot tell', 'twt-aeo-ultimate' ),
			// "Generate" on its own did not read as the AI action, and it is the
			// one button here that spends money. The rest of the plugin says
			// "Generate with AI", so this says it too.
			'generate'         => __( 'Generate with AI', 'twt-aeo-ultimate' ),
			'regenerate'       => __( 'Rewrite with AI', 'twt-aeo-ultimate' ),
			'noImageProvider'  => __( 'AI images need an OpenAI key.', 'twt-aeo-ultimate' ),
			'save'             => __( 'Save', 'twt-aeo-ultimate' ),
			'edit'             => __( 'Edit', 'twt-aeo-ultimate' ),
			'cancel'           => __( 'Cancel', 'twt-aeo-ultimate' ),
			'choose'           => __( 'Choose image', 'twt-aeo-ultimate' ),
			'change'           => __( 'Change', 'twt-aeo-ultimate' ),
			'remove'           => __( 'Remove', 'twt-aeo-ultimate' ),
			'useFirstInBody'   => __( 'Use first image in content', 'twt-aeo-ultimate' ),
			'fixAlt'           => __( 'Add alt text', 'twt-aeo-ultimate' ),
			'saveFirst'        => __( 'Save the page first. Alt text is written into the page content, so it cannot be changed while there are unsaved edits.', 'twt-aeo-ultimate' ),
			'altPlaceholder'   => __( 'Describe this image', 'twt-aeo-ultimate' ),
			'working'          => __( 'Working…', 'twt-aeo-ultimate' ),
			'requestFailed'    => __( 'That did not go through. Try again.', 'twt-aeo-ultimate' ),
			'noProvider'       => __( 'Add an AI key under TWT AEO → Settings to generate this.', 'twt-aeo-ultimate' ),
			'mediaTitle'       => __( 'Choose an image', 'twt-aeo-ultimate' ),
			'mediaButton'      => __( 'Use this image', 'twt-aeo-ultimate' ),
			'templatedWarning' => __( 'Your SEO plugin builds this from a pattern. Writing one here replaces the pattern for this page only.', 'twt-aeo-ultimate' ),
			'unknownWarning'   => __( 'This plugin cannot read where your SEO plugin keeps this field, so it may already be set.', 'twt-aeo-ultimate' ),
			'costOne'          => __( 'Makes 1 AI call on your own API key.', 'twt-aeo-ultimate' ),
			/* translators: %d: number of AI calls the action will make, each billed to the site owner's own API key. */
			'costMany'         => __( 'Makes %d AI calls on your own API key.', 'twt-aeo-ultimate' ),
			'confirmRun'       => __( 'Run it', 'twt-aeo-ultimate' ),
			'dontAsk'          => __( 'Do not ask me again', 'twt-aeo-ultimate' ),
			'askingOff'        => __( 'AI calls run without asking.', 'twt-aeo-ultimate' ),
			'askingOn'         => __( 'Ask me again', 'twt-aeo-ultimate' ),
			'sidebarName'      => __( 'Finish this page', 'twt-aeo-ultimate' ),
		);
	}

	/**
	 * @param WP_Post $post
	 * @return array[]
	 */
	private static function image_rows( WP_Post $post ) {
		if ( ! class_exists( 'TWTAEO_Image_Optimizer' ) ) {
			return array();
		}

		$rows = array();
		foreach ( TWTAEO_Image_Optimizer::collect( $post ) as $image ) {
			$rows[] = array(
				'key'     => $image['key'],
				'context' => $image['context'],
				'src'     => $image['src'],
				'alt'     => $image['alt'],
			);
		}

		return $rows;
	}
}
