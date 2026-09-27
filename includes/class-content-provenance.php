<?php
/**
 * Content Provenance (digitalSourceType)
 *
 * Lets an author declare how a post's content was produced — human-written,
 * AI-assisted, AI-generated, or automated — and publishes that declaration as
 * schema.org digitalSourceType (IPTC Digital Source Type vocabulary) on the
 * post's Article/NewsArticle node.
 *
 * Human-created is the default and publishes NOTHING: consumers (including
 * Google, per its 2026 forum/Q&A documentation) treat an absent
 * digitalSourceType as human-generated, so an explicit "human" value would
 * add bytes without adding information. Only non-default declarations emit.
 *
 * This is a trust signal aimed at answer engines: content that honestly
 * declares its provenance is easier for an AI system to weigh, and a site
 * that labels its AI-assisted pages makes its human-written pages more
 * credible by contrast.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Content_Provenance {

	const META_KEY = '_twtaeo_digital_source';
	const NONCE    = 'twtaeo_provenance_nonce';

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ) );
	}

	/**
	 * Selectable provenance values. Keys are stored in postmeta; 'url' is the
	 * schema.org IPTCDigitalSourceEnumeration member emitted as
	 * digitalSourceType. The default (human) key maps to no URL — absence IS
	 * the declaration.
	 *
	 * @return array
	 */
	public static function options() {
		return array(
			''             => array(
				'label' => __( 'Human-created (default)', 'twt-aeo-ultimate' ),
				'url'   => '',
			),
			'ai-assisted'  => array(
				'label' => __( 'Human-created with AI assistance', 'twt-aeo-ultimate' ),
				'url'   => 'https://schema.org/CompositeWithTrainedAlgorithmicMediaDigitalSource',
			),
			'ai-generated' => array(
				'label' => __( 'AI-generated (human-reviewed)', 'twt-aeo-ultimate' ),
				'url'   => 'https://schema.org/TrainedAlgorithmicMediaDigitalSource',
			),
			'automated'    => array(
				'label' => __( 'Automated (no AI model, e.g. data feed)', 'twt-aeo-ultimate' ),
				'url'   => 'https://schema.org/AlgorithmicMediaDigitalSource',
			),
		);
	}

	/**
	 * The digitalSourceType URL to publish for a post, or '' for the human
	 * default (emit nothing).
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function get_url( $post_id ) {
		$key     = (string) get_post_meta( (int) $post_id, self::META_KEY, true );
		$options = self::options();
		return isset( $options[ $key ] ) ? $options[ $key ]['url'] : '';
	}

	// ── Editor UI ─────────────────────────────────────────────────────────────

	public static function add_meta_box() {
		$post_types = class_exists( 'TWTAEO_Scan_Store' )
			? TWTAEO_Scan_Store::get_scannable_post_types()
			: array( 'post', 'page' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'twt-aeo-provenance',
				__( 'Content Provenance', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'low'
			);
		}
	}

	public static function render( $post ) {
		$current = (string) get_post_meta( $post->ID, self::META_KEY, true );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p style="margin-top:0;">
			<label for="twtaeo_digital_source" style="font-weight:600;">
				<?php esc_html_e( 'How was this content created?', 'twt-aeo-ultimate' ); ?>
			</label>
		</p>
		<select name="twtaeo_digital_source" id="twtaeo_digital_source" style="width:100%;">
			<?php foreach ( self::options() as $key => $opt ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
					<?php echo esc_html( $opt['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description" style="margin-top:6px;">
			<?php esc_html_e( 'Published as digitalSourceType in this page\'s schema so AI systems and search engines know the content\'s origin. Human-created publishes nothing — absence is the human default.', 'twt-aeo-ultimate' ); ?>
		</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE )
		) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$key = sanitize_key( wp_unslash( $_POST['twtaeo_digital_source'] ?? '' ) );
		if ( ! array_key_exists( $key, self::options() ) ) {
			$key = '';
		}

		if ( '' === $key ) {
			delete_post_meta( $post_id, self::META_KEY ); // Human default — store nothing.
		} else {
			update_post_meta( $post_id, self::META_KEY, $key );
		}
	}
}
