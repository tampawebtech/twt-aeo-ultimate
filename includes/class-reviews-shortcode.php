<?php
/**
 * Reviews Shortcodes
 *
 * Registers three shortcodes:
 *   [twtaeo_reviews]           — display approved reviews list
 *   [twtaeo_review_form]       — display the submission form
 *   [twtaeo_aggregate_rating]  — display the star rating summary bar
 *
 * Enqueues frontend CSS/JS on pages where shortcodes are used.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Reviews_Shortcode {

	const AJAX_ACTION = 'twtaeo_submit_review';

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public static function register_hooks() {
		add_shortcode( 'twtaeo_reviews',          array( __CLASS__, 'render_reviews' ) );
		add_shortcode( 'twtaeo_review_form',      array( __CLASS__, 'render_form' ) );
		add_shortcode( 'twtaeo_aggregate_rating', array( __CLASS__, 'render_aggregate' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( 'TWTAEO_Reviews_CPT', 'handle_submit' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION,        array( 'TWTAEO_Reviews_CPT', 'handle_submit' ) );
	}

	// ── Asset registration ────────────────────────────────────────────────────

	public static function register_assets() {
		wp_register_style(
			'twt-aeo-reviews',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/reviews.css',
			array(),
			TWTAEO_VERSION
		);
		wp_register_script(
			'twt-aeo-reviews',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/reviews.js',
			array( 'jquery', 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);
		wp_set_script_translations( 'twt-aeo-reviews', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
	}

	private static function enqueue_assets() {
		if ( ! wp_style_is( 'twt-aeo-reviews', 'enqueued' ) ) {
			wp_enqueue_style( 'twt-aeo-reviews' );
		}
		if ( ! wp_script_is( 'twt-aeo-reviews', 'enqueued' ) ) {
			wp_enqueue_script( 'twt-aeo-reviews' );
			wp_localize_script( 'twt-aeo-reviews', 'twtAeoReviews', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( TWTAEO_Reviews_CPT::NONCE_SUBMIT ),
				'postId'  => get_the_ID() ?: 0,
			) );
		}
	}

	// ── [twtaeo_reviews] ────────────────────────────────────────────────────

	/**
	 * @param array $atts  post_id (int), limit (int), show_rating (bool), show_date (bool)
	 */
	public static function render_reviews( $atts ) {
		$atts = shortcode_atts( array(
			'post_id'     => -1,
			'limit'       => 10,
			'show_rating' => true,
			'show_date'   => true,
		), $atts, 'twtaeo_reviews' );

		self::enqueue_assets();

		$post_id  = (int) $atts['post_id'];
		$limit    = max( 1, (int) $atts['limit'] );
		$reviews  = TWTAEO_Reviews_CPT::get_approved( $post_id, $limit );

		if ( empty( $reviews ) ) {
			return '<p class="twt-reviews-empty">' . esc_html__( 'No reviews yet. Be the first to leave one!', 'twt-aeo-ultimate' ) . '</p>';
		}

		ob_start();
		?>
		<div class="twt-reviews-list">
			<?php foreach ( $reviews as $review ) :
				$rating  = (int) get_post_meta( $review->ID, TWTAEO_Reviews_CPT::META_RATING, true );
				$rating  = max( 1, min( 5, $rating ) );
				$date    = get_post_time( 'F j, Y', false, $review->ID );
			?>
			<div class="twt-review-item">
				<div class="twt-review-header">
					<span class="twt-review-author"><?php echo esc_html( $review->post_title ); ?></span>
					<?php if ( $atts['show_rating'] ) : ?>
					<?php // translators: %d: star rating (1–5). ?>
					<span class="twt-review-stars" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'twt-aeo-ultimate' ), $rating ) ); ?>">
						<?php echo wp_kses( self::render_stars( $rating ), array( 'span' => array( 'class' => array() ) ) ); ?>
					</span>
					<?php endif; ?>
					<?php if ( $atts['show_date'] ) : ?>
					<span class="twt-review-date"><?php echo esc_html( $date ); ?></span>
					<?php endif; ?>
				</div>
				<div class="twt-review-body"><?php echo wp_kses_post( wpautop( $review->post_content ) ); ?></div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── [twtaeo_review_form] ────────────────────────────────────────────────

	public static function render_form( $atts ) {
		$atts = shortcode_atts( array(
			'post_id' => 0,
		), $atts, 'twtaeo_review_form' );

		$settings = TWTAEO_Reviews_Schema_Writer::get_settings();
		if ( empty( $settings['show_form'] ) ) {
			return '';
		}

		self::enqueue_assets();

		$post_id        = (int) $atts['post_id'] ?: ( get_the_ID() ?: 0 );
		$title          = $settings['form_title']   ?: __( 'Leave a Review', 'twt-aeo-ultimate' );
		$submit_label   = $settings['submit_label'] ?: __( 'Submit Review', 'twt-aeo-ultimate' );
		$require_email  = ! empty( $settings['require_email'] );

		ob_start();
		?>
		<div class="twt-review-form-wrap">
			<h3 class="twt-review-form-title"><?php echo esc_html( $title ); ?></h3>

			<div class="twt-review-form-notice" style="display:none;" role="alert" aria-live="polite"></div>

			<form class="twt-review-form" novalidate>
				<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">

				<div class="twt-form-row">
					<label for="twt-reviewer-name"><?php esc_html_e( 'Your Name', 'twt-aeo-ultimate' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="twt-reviewer-name" name="reviewer_name" required autocomplete="name" placeholder="<?php esc_attr_e( 'Jane Smith', 'twt-aeo-ultimate' ); ?>">
				</div>

				<?php if ( $require_email ) : ?>
				<div class="twt-form-row">
					<label for="twt-reviewer-email"><?php esc_html_e( 'Email Address', 'twt-aeo-ultimate' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" id="twt-reviewer-email" name="reviewer_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'jane@example.com', 'twt-aeo-ultimate' ); ?>">
				</div>
				<?php else : ?>
				<div class="twt-form-row">
					<label for="twt-reviewer-email"><?php esc_html_e( 'Email Address', 'twt-aeo-ultimate' ); ?> <span class="twt-optional"><?php esc_html_e( '(optional)', 'twt-aeo-ultimate' ); ?></span></label>
					<input type="email" id="twt-reviewer-email" name="reviewer_email" autocomplete="email" placeholder="<?php esc_attr_e( 'jane@example.com', 'twt-aeo-ultimate' ); ?>">
				</div>
				<?php endif; ?>

				<div class="twt-form-row">
					<label><?php esc_html_e( 'Rating', 'twt-aeo-ultimate' ); ?> <span aria-hidden="true">*</span></label>
					<div class="twt-star-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Select a star rating', 'twt-aeo-ultimate' ); ?>">
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<input type="radio" id="twt-star-<?php echo absint( $i ); ?>" name="rating" value="<?php echo absint( $i ); ?>" <?php checked( $i, 5 ); ?>>
						<?php // translators: %d: star rating number (1–5). ?>
						<label for="twt-star-<?php echo absint( $i ); ?>" title="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'twt-aeo-ultimate' ), $i ) ); ?>">&#9733;</label>
						<?php endfor; ?>
					</div>
				</div>

				<div class="twt-form-row">
					<label for="twt-review-content"><?php esc_html_e( 'Your Review', 'twt-aeo-ultimate' ); ?> <span aria-hidden="true">*</span></label>
					<textarea id="twt-review-content" name="review_content" rows="5" required placeholder="<?php esc_attr_e( 'Share your experience…', 'twt-aeo-ultimate' ); ?>"></textarea>
				</div>

				<div class="twt-form-row">
					<button type="submit" class="twt-review-submit">
						<span class="twt-submit-label"><?php echo esc_html( $submit_label ); ?></span>
						<span class="twt-submit-spinner" style="display:none;"></span>
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── [twtaeo_aggregate_rating] ───────────────────────────────────────────

	public static function render_aggregate( $atts ) {
		$atts = shortcode_atts( array(
			'post_id' => -1,
		), $atts, 'twtaeo_aggregate_rating' );

		$post_id   = (int) $atts['post_id'];
		$aggregate = TWTAEO_Reviews_CPT::get_aggregate( $post_id );

		if ( ! $aggregate ) {
			return '';
		}

		self::enqueue_assets();

		$avg   = $aggregate['average'];
		$count = $aggregate['count'];
		$pct   = ( $avg / 5 ) * 100;

		ob_start();
		?>
		<div class="twt-aggregate-rating" itemscope itemtype="https://schema.org/AggregateRating">
			<div class="twt-aggregate-score">
				<span class="twt-aggregate-number" itemprop="ratingValue"><?php echo esc_html( number_format( $avg, 1 ) ); ?></span>
				<span class="twt-aggregate-max">/ 5</span>
			</div>
			<?php // translators: %.1f: average rating (e.g. 4.2). ?>
			<div class="twt-aggregate-bar-wrap" aria-label="<?php echo esc_attr( sprintf( __( '%.1f out of 5', 'twt-aeo-ultimate' ), $avg ) ); ?>">
				<div class="twt-aggregate-bar-bg">
					<div class="twt-aggregate-bar-fill" style="width:<?php echo esc_attr( round( $pct ) ); ?>%;"></div>
				</div>
				<div class="twt-aggregate-stars"><?php echo wp_kses( self::render_stars( $avg ), array( 'span' => array( 'class' => array() ) ) ); ?></div>
			</div>
			<div class="twt-aggregate-count">
				<span itemprop="reviewCount"><?php echo esc_html( $count ); ?></span>
				<?php echo esc_html( _n( 'review', 'reviews', $count, 'twt-aeo-ultimate' ) ); ?>
			</div>
			<meta itemprop="bestRating" content="5">
			<meta itemprop="worstRating" content="1">
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private static function render_stars( $rating ) {
		$rating = (float) $rating;
		$html   = '<span class="twt-stars">';
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $i <= $rating ) {
				$html .= '<span class="twt-star twt-star-full">&#9733;</span>';
			} elseif ( $i - 0.5 <= $rating ) {
				$html .= '<span class="twt-star twt-star-half">&#9734;</span>';
			} else {
				$html .= '<span class="twt-star twt-star-empty">&#9734;</span>';
			}
		}
		$html .= '</span>';
		return $html;
	}
}
