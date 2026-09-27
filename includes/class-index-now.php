<?php
/**
 * IndexNow
 *
 * Generates and hosts the IndexNow key file, submits URLs to participating
 * search engines on publish, and maintains a rolling submission log.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Index_Now {

	const OPTION_KEY      = 'twtaeo_indexnow_key';
	const OPTION_SETTINGS = 'twtaeo_indexnow_settings';
	const OPTION_LOG      = 'twtaeo_indexnow_log';
	const LOG_LIMIT       = 50;

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_key_file' ), 1 );
		add_action( 'save_post',         array( __CLASS__, 'on_save_post' ), 10, 2 );
	}

	// ── Key management ────────────────────────────────────────────────────────

	public static function get_key() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( ! $key ) {
			$key = self::generate_key();
			update_option( self::OPTION_KEY, $key );
		}
		return $key;
	}

	public static function generate_key() {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function regenerate_key() {
		$key = self::generate_key();
		update_option( self::OPTION_KEY, $key );
		return $key;
	}

	// ── Key file serving ──────────────────────────────────────────────────────

	public static function maybe_serve_key_file() {
		$key  = self::get_key();
		$path = trim( wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		if ( $path === $key . '.txt' ) {
			// WordPress has already called status_header( 404 ) during the main
			// query (no post matches this URL), and that runs before
			// template_redirect. Reset to 200 — IndexNow requires the key file to
			// return HTTP 200 or search engines reject it.
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $key );
			exit;
		}
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'auto_submit' => true,
				'post_types'  => array( 'post', 'page' ),
				'engines'     => array( 'api.indexnow.org' ),
			)
		);
	}

	public static function save_settings( $post_data ) {
		$settings = array(
			'auto_submit' => ! empty( $post_data['auto_submit'] ),
			'post_types'  => array_map( 'sanitize_key', (array) ( $post_data['post_types'] ?? array() ) ),
			'engines'     => array_filter( array_map( 'sanitize_text_field', (array) ( $post_data['engines'] ?? array() ) ) ),
		);
		if ( empty( $settings['engines'] ) ) {
			$settings['engines'] = array( 'api.indexnow.org' );
		}
		update_option( self::OPTION_SETTINGS, $settings );
		return $settings;
	}

	// ── Auto-submit on publish ─────────────────────────────────────────────────

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['auto_submit'] ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( $url ) {
			self::submit( array( $url ) );
		}
	}

	// ── Submission ────────────────────────────────────────────────────────────

	/**
	 * Submit one or more URLs to all configured IndexNow engines.
	 *
	 * @param string[] $urls Absolute URLs to submit.
	 * @return array[]       Result per engine: engine, status, message.
	 */
	public static function submit( array $urls ) {
		$settings = self::get_settings();
		$key      = self::get_key();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$key_loc  = home_url( '/' . $key . '.txt' );

		$skip = self::non_public_reason( (string) $host );
		if ( '' !== $skip ) {
			// Logged, so the IndexNow screen shows why nothing went out rather
			// than an empty log that looks like the feature is broken.
			$result = array(
				'engine'  => 'none',
				'status'  => 0,
				'message' => $skip,
			);
			self::append_log( array( 'timestamp' => time(), 'urls' => $urls ) + $result );
			return array( $result );
		}

		$body = wp_json_encode( array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => $key_loc,
			'urlList'     => array_values( $urls ),
		) );

		$engines = ! empty( $settings['engines'] ) ? $settings['engines'] : array( 'api.indexnow.org' );
		$results = array();

		foreach ( $engines as $engine ) {
			$endpoint = 'https://' . $engine . '/IndexNow';
			$response = wp_remote_post( $endpoint, array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => $body,
				'timeout' => 10,
			) );

			if ( is_wp_error( $response ) ) {
				$status  = 0;
				$message = $response->get_error_message();
			} else {
				$status  = (int) wp_remote_retrieve_response_code( $response );
				$message = wp_remote_retrieve_response_message( $response );
			}

			$results[] = array(
				'engine'  => $engine,
				'status'  => $status,
				'message' => $message,
			);

			self::append_log( array(
				'timestamp' => time(),
				'urls'      => $urls,
				'engine'    => $engine,
				'status'    => $status,
				'message'   => $message,
			) );
		}

		if ( class_exists( 'TWTAEO_Pro_Transmitter' ) ) {
			TWTAEO_Pro_Transmitter::send_indexnow_event( $urls, $results );
		}

		return $results;
	}

	/**
	 * Why this site must not ping search engines, or '' when it may.
	 *
	 * A local or staging copy of a site submits URLs no engine can crawl — or,
	 * worse for staging on a public host, URLs it should never index. Local by
	 * Flywheel, wp-env and most staging hosts set WP_ENVIRONMENT_TYPE; the host
	 * check catches the copies that do not.
	 *
	 * @param string $host The site's host name.
	 * @return string
	 */
	private static function non_public_reason( $host ) {
		$host = strtolower( $host );
		$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		$reason = '';
		if ( in_array( $env, array( 'local', 'development', 'staging' ), true ) ) {
			/* translators: %s: WordPress environment type, e.g. local or staging. */
			$reason = sprintf( __( 'Skipped: this is a %s site, so its URLs are not sent to search engines.', 'twt-aeo-ultimate' ), $env );
		} elseif ( 'localhost' === $host
			|| (bool) preg_match( '/\.(local|test|localhost|invalid|example|internal|lan)$/', $host )
			|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) {
			/* translators: %s: site host name. */
			$reason = sprintf( __( 'Skipped: %s is not a public address, so search engines could not crawl these URLs.', 'twt-aeo-ultimate' ), $host );
		}

		/**
		 * Filters whether IndexNow skips a non-public site. Return '' to submit anyway.
		 *
		 * @param string $reason Why submission is skipped; '' when it is not.
		 * @param string $host   The site's host name.
		 */
		return (string) apply_filters( 'twtaeo_indexnow_skip_reason', $reason, $host );
	}

	// ── Log ───────────────────────────────────────────────────────────────────

	private static function append_log( $entry ) {
		$log = get_option( self::OPTION_LOG, array() );
		array_unshift( $log, $entry );
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_LIMIT ) );
	}

	public static function get_log() {
		return get_option( self::OPTION_LOG, array() );
	}

	public static function clear_log() {
		update_option( self::OPTION_LOG, array() );
	}

	// ── Conflict detection ────────────────────────────────────────────────────

	public static function detect_conflicts() {
		$conflicts = array();

		// Rank Math IndexNow module.
		if ( class_exists( 'RankMath' ) ) {
			$rm_modules = (array) get_option( 'rank_math_modules', array() );
			if ( in_array( 'index-now', $rm_modules, true ) ) {
				$conflicts[] = 'Rank Math';
			}
		}

		// Yoast SEO IndexNow.
		if ( class_exists( 'WPSEO_Options' ) ) {
			$yoast = (array) get_option( 'wpseo', array() );
			if ( ! empty( $yoast['enable_index_now'] ) ) {
				$conflicts[] = 'Yoast SEO';
			}
		}

		// All in One SEO IndexNow.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$raw  = get_option( 'aioseo_options', '{}' );
			$opts = json_decode( $raw, true );
			if ( ! empty( $opts['searchAppearance']['advanced']['indexNowEnabled'] ) ) {
				$conflicts[] = 'All in One SEO';
			}
		}

		return $conflicts;
	}

	// ── Key file URL helpers ──────────────────────────────────────────────────

	public static function get_key_file_url() {
		return home_url( '/' . self::get_key() . '.txt' );
	}

	public static function verify_key_file() {
		$url      = self::get_key_file_url();
		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( wp_remote_retrieve_body( $response ) );
		$key  = self::get_key();

		if ( 200 === $code && $body === $key ) {
			return array( 'ok' => true, 'message' => 'Key file is accessible and returns the correct key.' );
		}

		if ( 200 === $code ) {
			return array( 'ok' => false, 'message' => sprintf( 'Key file returned HTTP 200 but the body did not match. Got: %s', esc_html( substr( $body, 0, 80 ) ) ) );
		}

		return array( 'ok' => false, 'message' => sprintf( 'Key file returned HTTP %d.', $code ) );
	}
}
