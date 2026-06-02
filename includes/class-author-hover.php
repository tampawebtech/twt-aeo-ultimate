<?php
/**
 * Author Hover Cards
 *
 * Registers frontend assets and injects a preloaded author data object so
 * JavaScript can render hover cards without any AJAX latency.
 *
 * Activation gate: the author-hover-cards module must be active.
 *
 * Attaches hover cards to elements matching:
 *   .author-name a, .byline a, .entry-author-name a,
 *   [data-author-id], a[rel="author"]
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Author_Hover {

	public static function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue CSS/JS and inject author data as a JS object.
	 */
	public static function enqueue_assets() {
		// Only on posts and author archives.
		if ( ! is_singular( 'post' ) && ! is_author() ) {
			return;
		}

		wp_enqueue_style(
			'twt-aeo-author-hover',
			TWTAEO_PLUGIN_URL . 'public/css/author-hover.css',
			array(),
			TWTAEO_VERSION
		);

		wp_enqueue_script(
			'twt-aeo-author-hover',
			TWTAEO_PLUGIN_URL . 'public/js/author-hover.js',
			array( 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);
		wp_set_script_translations( 'twt-aeo-author-hover', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );

		// Build the data payload for all authors on this page.
		$authors_data = array();
		$users        = self::get_page_author_ids();

		foreach ( $users as $user_id ) {
			$data  = TWTAEO_Author_Meta::get_author_data( $user_id );
			$certs = $data['certifications'];

			// Trim to what the card needs.
			$authors_data[ $user_id ] = array(
				'id'               => $user_id,
				'name'             => $data['name'],
				'jobTitle'         => $data['job_title'],
				'credentials'      => $data['credentials'],
				'bio'              => wp_trim_words( $data['bio'], 25 ),
				'yearsExperience'  => $data['years_experience'],
				'expertise'        => $data['expertise'],
				'avatarUrl'        => $data['avatar_url'],
				'profileUrl'       => $data['profile_url'],
				'certCount'        => count( $certs ),
				'certNames'        => wp_list_pluck( array_slice( $certs, 0, 2 ), 'name' ),
				'social'           => TWTAEO_Author_Meta::get_same_as( $user_id ),
			);
		}

		wp_localize_script( 'twt-aeo-author-hover', 'twtAeoHover', array(
			'authors'        => $authors_data,
			'viewProfileText' => __( 'View Full Profile', 'twt-aeo-ultimate' ),
		) );
	}

	/**
	 * Get author user IDs relevant to the current page.
	 *
	 * @return int[]
	 */
	private static function get_page_author_ids() {
		if ( is_author() ) {
			$author_id = get_queried_object_id();
			return $author_id ? array( $author_id ) : array();
		}

		if ( is_singular( 'post' ) ) {
			$author_id = (int) get_the_author_meta( 'ID' );
			return $author_id ? array( $author_id ) : array();
		}

		return array();
	}
}
