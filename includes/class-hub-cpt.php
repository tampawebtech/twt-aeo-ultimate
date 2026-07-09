<?php
/**
 * Hub CPT & Shortcodes
 *
 * Registers the `twtaeo_hub` custom post type (hierarchical, top-level sidebar menu)
 * and the two shortcodes that auto-link hubs to their sub-pages:
 *
 *   [twtaeo_hub_nav]        — on a hub page, renders a nav list of all published sub-pages.
 *   [twtaeo_hub_breadcrumb] — on a sub-page, renders a back-link to the parent hub.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Hub_CPT {

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'twtaeo_hub_nav',        array( __CLASS__, 'render_nav' ) );
		add_shortcode( 'twtaeo_hub_breadcrumb', array( __CLASS__, 'render_breadcrumb' ) );
	}

	/**
	 * Register the front-end stylesheet. Enqueued on demand by the shortcodes.
	 */
	public static function register_assets() {
		wp_register_style(
			'twt-aeo-hub',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/hub.css',
			array(),
			TWTAEO_VERSION
		);
	}

	// ── CPT ──────────────────────────────────────────────────────────────────

	public static function register_post_type() {
		if ( post_type_exists( 'twtaeo_hub' ) ) {
			return;
		}
		register_post_type(
			'twtaeo_hub',
			array(
				'labels' => array(
					'name'               => __( 'Hubs',              'twt-aeo-ultimate' ),
					'singular_name'      => __( 'Hub',               'twt-aeo-ultimate' ),
					'add_new'            => __( 'Add New Hub',        'twt-aeo-ultimate' ),
					'add_new_item'       => __( 'Add New Hub',        'twt-aeo-ultimate' ),
					'edit_item'          => __( 'Edit Hub',           'twt-aeo-ultimate' ),
					'new_item'           => __( 'New Hub',            'twt-aeo-ultimate' ),
					'view_item'          => __( 'View Hub',           'twt-aeo-ultimate' ),
					'search_items'       => __( 'Search Hubs',        'twt-aeo-ultimate' ),
					'not_found'          => __( 'No hubs found.',     'twt-aeo-ultimate' ),
					'not_found_in_trash' => __( 'No hubs in trash.',  'twt-aeo-ultimate' ),
					'all_items'          => __( 'All Hubs',           'twt-aeo-ultimate' ),
					'menu_name'          => __( 'Hubs',               'twt-aeo-ultimate' ),
					'parent_item_colon'  => __( 'Parent Hub:',        'twt-aeo-ultimate' ),
				),
				'public'             => true,
				'hierarchical'       => true,
				'has_archive'        => false,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-networking',
				'menu_position'      => 22,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'revisions' ),
				'show_in_rest'       => true,
				'rewrite'            => array( 'slug' => 'hub', 'with_front' => false ),
				'capability_type'    => 'page',
			)
		);
	}

	// ── [twtaeo_hub_nav] ─────────────────────────────────────────────────────────

	public static function render_nav( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$children = get_posts( array(
			'post_type'      => 'twtaeo_hub',
			'post_status'    => 'publish',
			'post_parent'    => $post_id,
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );

		if ( empty( $children ) ) {
			return '';
		}

		$html  = '<nav class="twt-hub-nav" aria-label="' . esc_attr__( 'Hub Contents', 'twt-aeo-ultimate' ) . '">';
		$html .= '<p class="twt-hub-nav__label">' . esc_html__( 'In This Hub', 'twt-aeo-ultimate' ) . '</p>';
		$html .= '<ul class="twt-hub-nav__list">';

		foreach ( $children as $child ) {
			$html .= '<li class="twt-hub-nav__item">';
			$html .= '<a class="twt-hub-nav__link" href="' . esc_url( get_permalink( $child->ID ) ) . '">';
			$html .= esc_html( $child->post_title );
			$html .= '</a></li>';
		}

		$html .= '</ul></nav>';

		wp_enqueue_style( 'twt-aeo-hub' );

		return $html;
	}

	// ── [twtaeo_hub_breadcrumb] ──────────────────────────────────────────────────

	public static function render_breadcrumb( $atts ) {
		global $post;

		if ( ! $post || ! $post->post_parent ) {
			return '';
		}

		$parent = get_post( $post->post_parent );
		if ( ! $parent || get_post_status( $parent->ID ) !== 'publish' ) {
			return '';
		}

		$html  = '<nav class="twt-hub-breadcrumb" aria-label="' . esc_attr__( 'Hub Navigation', 'twt-aeo-ultimate' ) . '">';
		$html .= '<a class="twt-hub-breadcrumb__link" href="' . esc_url( get_permalink( $parent->ID ) ) . '">';
		$html .= '&#8592; ' . esc_html( $parent->post_title );
		$html .= '</a></nav>';

		wp_enqueue_style( 'twt-aeo-hub' );

		return $html;
	}
}
