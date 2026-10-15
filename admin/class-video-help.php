<?php
/**
 * Video help: a "?" beside the title of each screen that has a tutorial.
 *
 * Pressing it opens a small panel with what the screen is for, a link to the
 * written guide, and a Play button. The YouTube player is created only when
 * Play is pressed, from youtube-nocookie.com, so nothing is requested from
 * YouTube while an admin merely works on the screen. Disclosed in
 * external-services.txt. No video is bundled; the plugin carries only video ids.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Video_Help {

	const DOCS = 'https://aeoultimate.com/docs/wordpress/';

	/**
	 * Every tutorial, in series order: YouTube id, guide slug, title, one-line summary.
	 *
	 * @return array<string,array>
	 */
	public static function videos() {
		return array(
			'eeat'            => array(
				'id'    => '8woBCxQgb64',
				'guide' => 'eeat',
				'title' => __( 'E-E-A-T', 'twt-aeo-ultimate' ),
				'about' => __( 'The experience, expertise and trust signals search engines and AI assistants look for, and how to raise your score.', 'twt-aeo-ultimate' ),
			),
			'local-pack'      => array(
				'id'    => 'eSpCnSOk5gA',
				'guide' => 'local-pack',
				'title' => __( 'Local Pack', 'twt-aeo-ultimate' ),
				'about' => __( 'LocalBusiness schema and the local directories that help "near me" answers name your business.', 'twt-aeo-ultimate' ),
			),
			'ai-visibility'   => array(
				'id'    => '5qW_KhGG8ww',
				'guide' => 'ai-visibility',
				'title' => __( 'AI Visibility', 'twt-aeo-ultimate' ),
				'about' => __( 'Whether ChatGPT, Gemini, Perplexity and the other engines cite your site when asked your questions.', 'twt-aeo-ultimate' ),
			),
			'social-graph'    => array(
				'id'    => 'AufE9J52TQY',
				'guide' => 'social-graph',
				'title' => __( 'Social Graph', 'twt-aeo-ultimate' ),
				'about' => __( 'Open Graph tags and share images, so every page looks right when it is shared or previewed.', 'twt-aeo-ultimate' ),
			),
			'schema-detector' => array(
				'id'    => 'iVo7UVONlAo',
				'guide' => 'schema-detector',
				'title' => __( 'Schema Detector', 'twt-aeo-ultimate' ),
				'about' => __( 'Finding the pages that need FAQ, Service or contact schema, and adding it in one click.', 'twt-aeo-ultimate' ),
			),
			'ai-ready'        => array(
				'id'    => 'n2O9DQFQvS4',
				'guide' => 'ai-ready',
				'title' => __( 'AI Ready', 'twt-aeo-ultimate' ),
				'about' => __( 'Markdown versions of your pages, llms.txt, and content signals that let AI assistants cite you.', 'twt-aeo-ultimate' ),
			),
			'rag-engine'      => array(
				'id'    => 'fJ3YPi5DGy0',
				'guide' => 'rag-engine',
				'title' => __( 'RAG Engine', 'twt-aeo-ultimate' ),
				'about' => __( 'Your site the way AI retrieval sees it, and the answers your documents hold that your pages are missing.', 'twt-aeo-ultimate' ),
			),
			'command-center'  => array(
				'id'    => 'XG88HXu4TPk',
				'guide' => 'command-center',
				'title' => __( 'Command Center', 'twt-aeo-ultimate' ),
				'about' => __( 'Google Analytics, Search Console and Bing in one place, with AI crawler access, page speed and index status.', 'twt-aeo-ultimate' ),
			),
		);
	}

	/**
	 * Admin page slug => tutorials for that screen, most relevant first.
	 *
	 * @return array<string,string[]>
	 */
	public static function screens() {
		$screens = array(
			'twt-aeo-eeat'            => array( 'eeat' ),
			'twt-aeo-local-pack'      => array( 'local-pack' ),
			'twt-aeo-ai-visibility'   => array( 'ai-visibility' ),
			'twt-aeo-social-graph'    => array( 'social-graph' ),
			'twt-aeo-schema-detector' => array( 'schema-detector' ),
			'twt-aeo-ai-ready'        => array( 'ai-ready' ),
			'twt-aeo-rag'             => array( 'rag-engine' ),
			'twt-aeo-command-center'  => array( 'command-center' ),
		);
		/**
		 * Filter the screen => tutorial map. Return an empty array to remove every "?".
		 *
		 * @param array $screens
		 */
		return (array) apply_filters( 'twtaeo_video_help_screens', $screens );
	}

	/**
	 * Enqueue on screens that have a tutorial. Called from TWTAEO_Admin_Assets::enqueue().
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screens = self::screens();
		if ( '' === $page || empty( $screens[ $page ] ) ) {
			return;
		}

		$all    = self::videos();
		$videos = array();
		foreach ( (array) $screens[ $page ] as $key ) {
			if ( isset( $all[ $key ] ) ) {
				$v        = $all[ $key ];
				$videos[] = array(
					'id'    => $v['id'],
					'title' => $v['title'],
					'about' => $v['about'],
					'guide' => self::DOCS . $v['guide'] . '/',
					'watch' => 'https://www.youtube.com/watch?v=' . rawurlencode( $v['id'] ),
				);
			}
		}
		if ( ! $videos ) {
			return;
		}

		wp_enqueue_script(
			'twt-aeo-video-help',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/video-help.js',
			array(),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script(
			'twt-aeo-video-help',
			'twtAeoVideoHelp',
			array(
				'videos' => $videos,
				'i18n'   => array(
					'button'  => __( 'Video help for this screen', 'twt-aeo-ultimate' ),
					'heading' => _n( 'Tutorial for this screen', 'Tutorials for this screen', count( $videos ), 'twt-aeo-ultimate' ),
					'play'    => __( 'Play video', 'twt-aeo-ultimate' ),
					'guide'   => __( 'Read the guide', 'twt-aeo-ultimate' ),
					'youtube' => __( 'Open on YouTube', 'twt-aeo-ultimate' ),
					'close'   => __( 'Close', 'twt-aeo-ultimate' ),
					'privacy' => __( 'The video plays from YouTube (youtube-nocookie.com). Nothing is loaded from YouTube until you press Play.', 'twt-aeo-ultimate' ),
					'newTab'  => __( '(opens in a new tab)', 'twt-aeo-ultimate' ),
				),
			)
		);
	}
}
