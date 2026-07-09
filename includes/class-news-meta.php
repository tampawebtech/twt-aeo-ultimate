<?php
/**
 * News Meta
 *
 * Registers a side metabox on enabled post types so editors can set:
 *   - Include toggle   (opt-in: adds NewsArticle schema + news sitemap inclusion)
 *   - Article section  (used in NewsArticle schema + news sitemap keywords)
 *   - News keywords    (comma-separated; output in NewsArticle and news sitemap)
 *
 * NewsArticle is opt-in by design: most posts are evergreen, not news, so the
 * NewsArticle type is only emitted on posts an editor explicitly marks as news.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_News_Meta {

	const META_SECTION  = '_twtaeo_news_section';
	const META_KEYWORDS = '_twtaeo_news_keywords';
	const META_INCLUDE  = '_twtaeo_news_include';
	const NONCE_ACTION  = 'twtaeo_news_meta_save';
	const NONCE_FIELD   = '_twtaeo_news_meta_nonce';

	// Article sections aligned with common Google News categories.
	private static $sections = array(
		''              => '— None —',
		'Arts'          => 'Arts',
		'Business'      => 'Business',
		'Entertainment' => 'Entertainment',
		'Health'        => 'Health',
		'Lifestyle'     => 'Lifestyle',
		'Politics'      => 'Politics',
		'Science'       => 'Science',
		'Sports'        => 'Sports',
		'Technology'    => 'Technology',
		'Travel'        => 'Travel',
		'World'         => 'World',
	);

	// ── Hook registration ─────────────────────────────────────────────────────

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post',      array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	// ── Metabox ───────────────────────────────────────────────────────────────

	public static function add_metabox() {
		$settings   = TWTAEO_News_Sitemap::get_settings();
		$post_types = $settings['post_types'] ?? array( 'post' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'twtaeo_news_meta',
				__( 'Google News', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render_metabox' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public static function render_metabox( WP_Post $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$section  = get_post_meta( $post->ID, self::META_SECTION,  true );
		$keywords = get_post_meta( $post->ID, self::META_KEYWORDS, true );
		$include  = (bool) get_post_meta( $post->ID, self::META_INCLUDE, true );
		?>
		<p style="margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #f0f0f1;">
			<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600;">
				<input type="checkbox" name="twtaeo_news_include" value="1" <?php checked( $include ); ?>>
				<span><?php esc_html_e( 'Mark as Google News article', 'twt-aeo-ultimate' ); ?></span>
			</label>
			<span style="font-size:11px;color:#888;display:block;margin-top:2px;">
				<?php esc_html_e( 'Adds NewsArticle schema and includes this post in the news sitemap (while it is within Google\'s 48-hour window). Leave off for evergreen content.', 'twt-aeo-ultimate' ); ?>
			</span>
		</p>

		<p style="margin:0 0 10px;">
			<label for="twtaeo_news_section" style="display:block;font-weight:600;margin-bottom:4px;">
				<?php esc_html_e( 'Article Section', 'twt-aeo-ultimate' ); ?>
			</label>
			<select id="twtaeo_news_section" name="twtaeo_news_section" style="width:100%;">
				<?php foreach ( self::$sections as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $section, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p style="margin:0 0 10px;">
			<label for="twtaeo_news_keywords" style="display:block;font-weight:600;margin-bottom:4px;">
				<?php esc_html_e( 'News Keywords', 'twt-aeo-ultimate' ); ?>
			</label>
			<input type="text" id="twtaeo_news_keywords" name="twtaeo_news_keywords"
			       value="<?php echo esc_attr( $keywords ); ?>" style="width:100%;"
			       placeholder="<?php esc_attr_e( 'keyword1, keyword2', 'twt-aeo-ultimate' ); ?>">
			<span style="font-size:11px;color:#888;"><?php esc_html_e( 'Comma-separated. Used in NewsArticle schema.', 'twt-aeo-ultimate' ); ?></span>
		</p>
		<?php
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	public static function save_meta( $post_id, WP_Post $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$section  = sanitize_text_field( wp_unslash( $_POST['twtaeo_news_section']  ?? '' ) );
		$keywords = sanitize_text_field( wp_unslash( $_POST['twtaeo_news_keywords'] ?? '' ) );
		$include  = ! empty( $_POST['twtaeo_news_include'] ) ? '1' : '';

		update_post_meta( $post_id, self::META_SECTION,  $section );
		update_post_meta( $post_id, self::META_KEYWORDS, $keywords );
		update_post_meta( $post_id, self::META_INCLUDE,  $include );
	}

	// ── Accessors ─────────────────────────────────────────────────────────────

	public static function get_section( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_SECTION, true );
	}

	public static function get_keywords( $post_id ) {
		$raw = (string) get_post_meta( $post_id, self::META_KEYWORDS, true );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Whether a post is opted in as a Google News article. NewsArticle schema
	 * and news-sitemap inclusion are both gated on this (opt-in by design).
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_included( $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_INCLUDE, true );
	}
}
