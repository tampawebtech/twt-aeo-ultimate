<?php
/**
 * Logger
 *
 * Centralised, lightweight error/event logging for the whole plugin. Entries
 * are kept in a capped ring buffer stored in a single (non-autoloaded) option,
 * so there are no log files and no write-permission issues on customer sites.
 * The Diagnostics page surfaces these entries for copy-paste support.
 *
 * A plugin-scoped shutdown handler captures PHP fatal errors that originate in
 * THIS plugin's own files — fatals from other plugins or the theme are ignored,
 * so the log stays signal-rich.
 *
 * Usage:
 *   TWTAEO_Logger::error( 'Something failed', array( 'post_id' => 12 ) );
 *   $ref = TWTAEO_Logger::log_exception( $e );  // returns a short reference code
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Logger {

	/**
	 * Option key for the log ring buffer.
	 */
	const OPTION_KEY = 'twtaeo_log';

	/**
	 * Maximum number of entries retained. Oldest are dropped first.
	 */
	const MAX_ENTRIES = 200;

	// Severity levels.
	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	/**
	 * Register the fatal-error catcher. Call as early as possible during load.
	 */
	public static function init() {
		register_shutdown_function( array( __CLASS__, 'catch_fatal' ) );
	}

	// ── Public logging API ──────────────────────────────────────────────────────

	/**
	 * Record an error-level entry.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context.
	 */
	public static function error( $message, array $context = array() ) {
		self::log( self::ERROR, $message, $context );
	}

	/**
	 * Record a warning-level entry.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context.
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( self::WARNING, $message, $context );
	}

	/**
	 * Record an info-level entry.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context.
	 */
	public static function info( $message, array $context = array() ) {
		self::log( self::INFO, $message, $context );
	}

	/**
	 * Log a caught Throwable and return a short reference code. Show the code to
	 * the user ("Error reference: ABC12345") so they can quote it back and you can
	 * match it to the entry on the Diagnostics page.
	 *
	 * @param Throwable $e       The caught exception/error.
	 * @param array     $context Optional extra context.
	 * @return string Reference code.
	 */
	public static function log_exception( $e, array $context = array() ) {
		$ref = strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );

		$context['ref']   = $ref;
		$context['file']  = self::relative_path( $e->getFile() );
		$context['line']  = $e->getLine();
		$context['trace'] = $e->getTraceAsString();

		self::log( self::ERROR, get_class( $e ) . ': ' . $e->getMessage(), $context );

		return $ref;
	}

	/**
	 * Write an entry into the ring buffer.
	 *
	 * @param string $level   One of the level constants.
	 * @param string $message Message text.
	 * @param array  $context Structured context.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => in_array( $level, array( self::ERROR, self::WARNING, self::INFO ), true ) ? $level : self::INFO,
			'message' => self::redact( is_scalar( $message ) ? (string) $message : wp_json_encode( $message ) ),
			'context' => self::sanitize_context( $context ),
		);

		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = $entry;

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		// Non-autoloaded — keeps the log out of the always-loaded options cache.
		update_option( self::OPTION_KEY, $log, false );
	}

	// ── Accessors ─────────────────────────────────────────────────────────────

	/**
	 * Get log entries, most recent first.
	 *
	 * @param int $limit Maximum entries to return (0 = all).
	 * @return array
	 */
	public static function get_entries( $limit = 0 ) {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		$log = array_reverse( $log );

		if ( $limit > 0 ) {
			$log = array_slice( $log, 0, $limit );
		}

		return $log;
	}

	/**
	 * Number of stored entries.
	 *
	 * @return int
	 */
	public static function count() {
		$log = get_option( self::OPTION_KEY, array() );
		return is_array( $log ) ? count( $log ) : 0;
	}

	/**
	 * Delete all stored entries.
	 */
	public static function clear() {
		delete_option( self::OPTION_KEY );
	}

	// ── Fatal-error catcher ─────────────────────────────────────────────────────

	/**
	 * Shutdown handler: records a fatal error if the last error was fatal and
	 * originated inside this plugin's directory.
	 */
	public static function catch_fatal() {
		$err = error_get_last();
		if ( empty( $err ) || empty( $err['type'] ) ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( $err['type'], $fatal_types, true ) ) {
			return;
		}

		// Only log fatals that originate in this plugin's files.
		$plugin_dir = wp_normalize_path( TWTAEO_PLUGIN_DIR );
		$err_file   = isset( $err['file'] ) ? wp_normalize_path( $err['file'] ) : '';
		if ( '' === $err_file || 0 !== strpos( $err_file, $plugin_dir ) ) {
			return;
		}

		self::log( self::ERROR, 'PHP Fatal: ' . ( $err['message'] ?? '' ), array(
			'file' => self::relative_path( $err['file'] ),
			'line' => $err['line'] ?? 0,
		) );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Strip the absolute plugin path from a filename for compact, portable logs.
	 *
	 * @param string $file Absolute path.
	 * @return string Path relative to the plugin root.
	 */
	private static function relative_path( $file ) {
		$plugin_dir = wp_normalize_path( TWTAEO_PLUGIN_DIR );
		$file       = wp_normalize_path( (string) $file );
		return str_replace( $plugin_dir, '', $file );
	}

	/**
	 * Coerce a context array into safe, storable scalars.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	private static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_string( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = self::redact( wp_json_encode( $value ) );
			}
		}

		return $clean;
	}

	/**
	 * Best-effort redaction of credentials (keys, tokens, passwords) so they
	 * never reach the stored log or the copy-pasted support report.
	 *
	 * @param string $string Input string.
	 * @return string
	 */
	private static function redact( $string ) {
		$string = (string) $string;

		// key=value / key: value pairs (URLs, JSON, messages).
		$string = preg_replace(
			'/(?<![\w-])(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|password|passwd|pwd|auth|token|signature)([=:]\s*["\']?)[^\s"\'&]+/i',
			'$1$2[redacted]',
			$string
		);

		// Bearer tokens.
		$string = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $string );

		return null === $string ? '' : $string;
	}
}
