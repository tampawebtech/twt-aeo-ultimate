<?php
/**
 * Author Box Detector
 *
 * Scans the site for author box presence using common theme class patterns.
 * Also checks whether author schema is present on post pages.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Author_Box_Detector {

	/**
	 * Common author box CSS class and HTML patterns used by themes and plugins.
	 */
	private static $author_box_patterns = array(
		// Generic class patterns.
		'author-bio',
		'author-box',
		'author-info',
		'author-card',
		'author-profile',
		'author-section',
		'post-author',
		'entry-author',
		'about-author',
		'author-description',
		'author-widget',
		'author-avatar',
		// Theme-specific patterns.
		'jeg_author_box',        // JEG / Jannah
		'tdb_author_box',        // Newspaper theme
		'td-author-box',         // Newspaper theme
		'mvp-author-box',        // MVPThemes
		'author-block',          // GeneratePress / various
		'post-bio',              // Various
		'author_bio',            // Various
		'theauthor',             // Various
		'byline',                // Various
		'vcard',                 // Microformat author
		// Plugin-specific patterns.
		'wp-biographia',         // WP Biographia plugin
		'simple-author-box',     // Simple Author Box plugin
		'molongui-author-box',   // Molongui Author Box plugin
		'bwp-author',            // Better WordPress Author plugin
		'author-box-plugin',     // Generic plugin class
	);

	/**
	 * Scan the site for author box presence.
	 * Samples the most recent posts to find author box patterns.
	 *
	 * @return array {
	 *   has_author_box: bool,
	 *   source: string,
	 *   patterns_found: string[],
	 *   author_schema_present: bool,
	 *   post_count_checked: int,
	 *   author_info: array
	 * }
	 */
	public static function scan_site() {
		$patterns_found = array();
		$source         = 'none';
		$has_author_box = false;
		$posts_checked  = 0;
		$author_schema  = false;

		// Get a sample of recent published posts.
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		foreach ( $posts as $post ) {
			$posts_checked++;

			// Check post content for author box patterns.
			$content = $post->post_content ?? '';
			foreach ( self::$author_box_patterns as $pattern ) {
				if ( stripos( $content, $pattern ) !== false ) {
					if ( ! in_array( $pattern, $patterns_found, true ) ) {
						$patterns_found[] = $pattern;
					}
					$has_author_box = true;
				}
			}

			// Check postmeta for author box plugin data.
			$meta = get_post_meta( $post->ID );
			foreach ( $meta as $key => $values ) {
				foreach ( self::$author_box_patterns as $pattern ) {
					if ( stripos( $key, $pattern ) !== false ) {
						if ( ! in_array( $pattern, $patterns_found, true ) ) {
							$patterns_found[] = $pattern;
						}
						$has_author_box = true;
					}
				}
			}
		}

		// Check active plugins for known author box plugins.
		$source = self::detect_source();

		if ( $source !== 'none' ) {
			$has_author_box = true;
		}

		// Check theme for author box support.
		if ( ! $has_author_box ) {
			$theme_check = self::check_theme_support();
			if ( $theme_check ) {
				$has_author_box = true;
				$source         = 'theme';
			}
		}

		// Check author schema on a sample post.
		$author_schema = self::check_author_schema();

		// Get site author info.
		$author_info = self::get_author_info();

		return array(
			'has_author_box'       => $has_author_box,
			'source'               => $source,
			'patterns_found'       => $patterns_found,
			'author_schema_present' => $author_schema,
			'post_count_checked'   => $posts_checked,
			'author_info'          => $author_info,
		);
	}

	/**
	 * Detect which plugin or theme is providing the author box.
	 *
	 * @return string
	 */
	private static function detect_source() {
		// Known author box plugins.
		$plugins = array(
			'simple-author-box/simple-author-box.php'         => 'Simple Author Box',
			'molongui-authorship/molongui-authorship.php'     => 'Molongui Authorship',
			'wp-biographia/wp-biographia.php'                 => 'WP Biographia',
			'co-authors-plus/co-authors-plus.php'             => 'Co-Authors Plus',
			'author-bio-box/author-bio-box.php'               => 'Author Bio Box',
			'fanciest-author-box/fanciest-author-box.php'     => 'Fanciest Author Box',
		);

		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $plugins as $plugin_file => $plugin_name ) {
			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				return 'plugin: ' . $plugin_name;
			}
		}

		return 'none';
	}

	/**
	 * Check if the active theme has built-in author box support.
	 *
	 * @return bool
	 */
	private static function check_theme_support() {
		$theme = wp_get_theme();
		$theme_name = strtolower( $theme->get( 'Name' ) );
		$template   = strtolower( get_template() );

		// Themes known to include author boxes.
		$themes_with_author_box = array(
			'generatepress', 'astra', 'kadence', 'oceanwp', 'neve',
			'newspaper', 'jannah', 'soledad', 'sahifa', 'the7',
			'enfold', 'avada', 'divi', 'extra', 'genesis',
			'twentytwenty', 'twentytwentyone', 'twentytwentytwo',
			'twentytwentythree', 'twentytwentyfour', 'twentytwentyfive',
		);

		foreach ( $themes_with_author_box as $known_theme ) {
			if ( strpos( $theme_name, $known_theme ) !== false || strpos( $template, $known_theme ) !== false ) {
				return true;
			}
		}

		// Check theme functions for author-related hooks or functions.
		$theme_functions = get_template_directory() . '/functions.php';
		if ( file_exists( $theme_functions ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$functions_content = $wp_filesystem ? $wp_filesystem->get_contents( $theme_functions ) : '';
			if ( stripos( $functions_content, 'author' ) !== false &&
			     stripos( $functions_content, 'bio' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether author schema (Person or Author) is present on a sample post.
	 *
	 * @return bool
	 */
	private static function check_author_schema() {
		// Check Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_titles = get_option( 'rank_math_titles', array() );
			if ( ! empty( $rm_titles['knowledgegraph_type'] ) && $rm_titles['knowledgegraph_type'] === 'person' ) {
				return true;
			}
		}

		// Check Yoast.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_social', array() );
			if ( ! empty( $wpseo['person_name'] ) ) {
				return true;
			}
		}

		// Check SASWP.
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 3,
		) );

		foreach ( $posts as $post ) {
			$saswp = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
			if ( ! empty( $saswp ) && stripos( $saswp, 'Person' ) !== false ) {
				return true;
			}

			// Check post content for inline author schema.
			if ( stripos( $post->post_content, '"Person"' ) !== false ||
			     stripos( $post->post_content, '"author"' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get basic author info for the primary site user.
	 *
	 * @return array
	 */
	private static function get_author_info() {
		$users = get_users( array(
			'role__in'   => array( 'administrator', 'editor', 'author' ),
			'number'     => 1,
			'orderby'    => 'post_count',
			'order'      => 'DESC',
		) );

		if ( empty( $users ) ) {
			return array(
				'user_id'     => 0,
				'name'        => '',
				'bio'         => '',
				'has_bio'     => false,
				'has_avatar'  => false,
			);
		}

		$user = $users[0];

		return array(
			'user_id'    => $user->ID,
			'name'       => $user->display_name,
			'bio'        => get_user_meta( $user->ID, 'description', true ),
			'has_bio'    => ! empty( get_user_meta( $user->ID, 'description', true ) ),
			'has_avatar' => ( get_avatar_url( $user->ID ) !== false ),
		);
	}
}