<?php
/**
 * Document Requirements — what this server can read, in words an owner can act on.
 *
 * Document upload leans on PHP extensions that most hosts ship and some do not
 * (mbstring and iconv for PDFs, zip for Word files). Failing mid-upload with a
 * class-not-found fatal would tell the owner nothing, so every requirement is
 * checked up front, reported on the Documents screen and in Diagnostics, and
 * the upload form only offers the file types this server can handle.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Doc_Requirements {

	/** Largest file accepted regardless of what PHP allows. */
	const MAX_UPLOAD_BYTES = 20971520; // 20 MB.

	/**
	 * Largest document on any server. Uploads travel in small pieces, so the
	 * host's upload limit no longer caps this; the PDF reader's memory does —
	 * it holds the whole file while reading.
	 */
	const MAX_DOCUMENT_BYTES = 52428800; // 50 MB.

	/** Bytes per upload piece: under every host's upload limit, and small enough that a dropped connection loses little. */
	const PIECE_BYTES = 1048576; // 1 MB.

	/** File types and the checks each one depends on. */
	const TYPES = array(
		'pdf'  => array( 'label' => 'PDF', 'needs' => array( 'mbstring', 'iconv', 'zlib' ) ),
		'docx' => array( 'label' => 'Word (.docx)', 'needs' => array( 'zip' ) ),
		'txt'  => array( 'label' => 'Text (.txt)', 'needs' => array() ),
		'md'   => array( 'label' => 'Markdown (.md)', 'needs' => array() ),
	);

	private function __construct() {}

	/**
	 * Every requirement, checked now.
	 *
	 * Level "error" blocks something (a file type, or uploading at all);
	 * "warning" means it works, only worse.
	 *
	 * @return array[] id, label, ok, level, message (empty when ok), detail.
	 */
	public static function checks() {
		$upload_bytes = self::max_upload_bytes();
		$upload_dir   = self::storage_parent_writable();
		$profile      = class_exists( 'TWTAEO_Host_Profile' ) ? TWTAEO_Host_Profile::get() : array();

		$checks = array(
			array(
				'id'      => 'mbstring',
				'label'   => __( 'PHP mbstring extension', 'twt-aeo-ultimate' ),
				'ok'      => extension_loaded( 'mbstring' ),
				'level'   => 'error',
				'message' => __( 'PDF reading needs PHP\'s mbstring extension. Ask your host to enable it — most control panels have a "PHP extensions" or "Select PHP version" page. Word and text files still work.', 'twt-aeo-ultimate' ),
			),
			array(
				'id'      => 'iconv',
				'label'   => __( 'PHP iconv extension', 'twt-aeo-ultimate' ),
				'ok'      => function_exists( 'iconv' ),
				'level'   => 'error',
				'message' => __( 'PDF reading needs PHP\'s iconv extension to decode the text inside PDFs. Ask your host to enable it. Word and text files still work.', 'twt-aeo-ultimate' ),
			),
			array(
				'id'      => 'zlib',
				'label'   => __( 'PHP zlib extension', 'twt-aeo-ultimate' ),
				'ok'      => function_exists( 'gzuncompress' ),
				'level'   => 'error',
				'message' => __( 'Almost every PDF is compressed, and unpacking it needs PHP\'s zlib extension. Ask your host to enable it. Word and text files still work.', 'twt-aeo-ultimate' ),
			),
			array(
				'id'      => 'zip',
				'label'   => __( 'PHP zip extension', 'twt-aeo-ultimate' ),
				'ok'      => class_exists( 'ZipArchive' ),
				'level'   => 'error',
				'message' => __( 'Word (.docx) files are zip archives, and opening them needs PHP\'s zip extension. Ask your host to enable it. PDFs and text files still work.', 'twt-aeo-ultimate' ),
			),
			array(
				'id'      => 'memory',
				'label'   => __( 'PHP memory', 'twt-aeo-ultimate' ),
				'ok'      => ! empty( $profile['usable'] ),
				'level'   => 'error',
				'message' => ! empty( $profile['reason'] )
					? $profile['reason'] . ' ' . __( 'Ask your host to raise memory_limit, or add define( \'WP_MEMORY_LIMIT\', \'256M\' ); to wp-config.php.', 'twt-aeo-ultimate' )
					: __( 'This server cannot run document processing. See the host panel on the Chunk View screen.', 'twt-aeo-ultimate' ),
				'detail'  => isset( $profile['memory_raw'] ) ? (string) $profile['memory_raw'] : '',
			),
			array(
				'id'      => 'storage',
				'label'   => __( 'Uploads folder writable', 'twt-aeo-ultimate' ),
				'ok'      => $upload_dir,
				'level'   => 'error',
				'message' => __( 'Uploaded documents cannot be saved because the WordPress uploads folder is not writable. Ask your host to check the folder permissions on wp-content/uploads.', 'twt-aeo-ultimate' ),
			),
			array(
				'id'      => 'upload_size',
				'label'   => __( 'Largest upload', 'twt-aeo-ultimate' ),
				'ok'      => true, // Uploads travel in pieces; this is shown for reference.
				'level'   => 'warning',
				'message' => sprintf(
					/* translators: %s: size such as "2 MB". */
					__( 'Your server accepts uploads up to %s (PHP upload_max_filesize / post_max_size). Long PDFs can be larger than that — ask your host to raise both limits if an upload is refused.', 'twt-aeo-ultimate' ),
					size_format( $upload_bytes )
				),
				'detail'  => sprintf(
					/* translators: 1: host upload limit, 2: largest readable document. */
					__( 'Host limit %1$s per upload — documents are sent in pieces, so files up to %2$s still work', 'twt-aeo-ultimate' ),
					size_format( $upload_bytes ),
					size_format( self::max_document_bytes() )
				),
			),
			array(
				'id'      => 'fulltext',
				'label'   => __( 'Database full-text search', 'twt-aeo-ultimate' ),
				'ok'      => ! empty( $profile['has_fulltext'] ),
				'level'   => 'warning',
				'message' => __( 'Your database version cannot build a full-text index. Search still works, just more slowly once you have many documents. MySQL 5.6+ or MariaDB 10.0.5+ removes this limit.', 'twt-aeo-ultimate' ),
			),
		);

		foreach ( $checks as &$check ) {
			if ( ! isset( $check['detail'] ) ) {
				$check['detail'] = '';
			}
		}
		unset( $check );

		return $checks;
	}

	/**
	 * Whether uploading is possible at all (the blocking non-type checks pass).
	 *
	 * @return true|WP_Error
	 */
	public static function can_upload() {
		foreach ( self::checks() as $check ) {
			if ( ! $check['ok'] && in_array( $check['id'], array( 'memory', 'storage' ), true ) ) {
				return new WP_Error( 'twtaeo_docs_' . $check['id'], $check['message'] );
			}
		}

		return true;
	}

	/**
	 * File types this server can read, with the reason for any it cannot.
	 *
	 * @return array ext => [ label, ok, reason ].
	 */
	public static function types() {
		$by_id = array();
		foreach ( self::checks() as $check ) {
			$by_id[ $check['id'] ] = $check;
		}

		$out = array();
		foreach ( self::TYPES as $ext => $type ) {
			$reason = '';
			foreach ( $type['needs'] as $need ) {
				if ( isset( $by_id[ $need ] ) && ! $by_id[ $need ]['ok'] ) {
					$reason = $by_id[ $need ]['message'];
					break;
				}
			}
			$out[ $ext ] = array(
				'label'  => $type['label'],
				'ok'     => '' === $reason,
				'reason' => $reason,
			);
		}

		return $out;
	}

	/**
	 * The largest document this server can read in one go. The PDF reader needs
	 * several times the file's size in memory, so the limit is a sixth of PHP's
	 * memory_limit, capped at 50 MB.
	 *
	 * @return int Bytes.
	 */
	public static function max_document_bytes() {
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
		if ( $memory <= 0 ) {
			return self::MAX_DOCUMENT_BYTES; // Unlimited.
		}

		return (int) min( self::MAX_DOCUMENT_BYTES, max( 4 * MB_IN_BYTES, floor( $memory / 6 ) ) );
	}

	/** Bytes per upload piece, never above what this host accepts in one request. */
	public static function piece_bytes() {
		$php = self::max_upload_bytes();

		return (int) max( 65536, min( self::PIECE_BYTES, $php > 0 ? (int) floor( $php / 2 ) : self::PIECE_BYTES ) );
	}

	/**
	 * What to tell someone whose document is too big for this server to read.
	 *
	 * @param int $bytes Size of the file.
	 * @return string
	 */
	public static function too_big_to_read_message( $bytes ) {
		return sprintf(
			/* translators: 1: file size, 2: limit. */
			__( 'This file is %1$s. Your server can read documents up to %2$s in one go — the PDF reader holds the whole file in memory, and this server\'s PHP memory limit sets the ceiling.', 'twt-aeo-ultimate' ),
			size_format( $bytes, 1 ),
			size_format( self::max_document_bytes() )
		) . ' ' . self::split_instructions();
	}

	/**
	 * What to tell someone whose file is too big: the limit, and how to split
	 * a PDF with tools they already have.
	 *
	 * @param int $bytes Size of the file, or 0 when unknown.
	 * @return string
	 */
	public static function too_big_message( $bytes = 0 ) {
		$limit = self::max_upload_bytes();
		$ours  = $limit >= self::MAX_UPLOAD_BYTES;

		$first = $bytes > 0
			? sprintf(
				/* translators: 1: file size, 2: limit. */
				__( 'This file is %1$s, over the %2$s limit.', 'twt-aeo-ultimate' ),
				size_format( $bytes, 1 ),
				size_format( $limit )
			)
			: sprintf(
				/* translators: %s: limit. */
				__( 'This file is over the %s limit.', 'twt-aeo-ultimate' ),
				size_format( $limit )
			);

		$why = $ours
			? __( 'Files over 20 MB break the upload limits on most hosting plans.', 'twt-aeo-ultimate' )
			: __( 'That is the most your hosting accepts in one upload (PHP upload_max_filesize / post_max_size).', 'twt-aeo-ultimate' );

		return $first . ' ' . $why . ' ' . self::split_instructions();
	}

	/** How to split a PDF into smaller files. */
	public static function split_instructions() {
		return __( 'Split it into parts and upload them separately: in Adobe Acrobat use Organize Pages → Split; on a Mac, open it in Preview and drag page ranges into new documents; or use a free splitter such as iLovePDF or Smallpdf. Splitting by chapter keeps related passages together.', 'twt-aeo-ultimate' );
	}

	/** PHP's post_max_size in bytes — the most one request can carry in total. */
	public static function post_max_bytes() {
		return function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) : 0;
	}

	/**
	 * The effective upload ceiling: the smaller of PHP's limits and ours.
	 *
	 * @return int Bytes.
	 */
	public static function max_upload_bytes() {
		$php = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;

		return $php > 0 ? min( $php, self::MAX_UPLOAD_BYTES ) : self::MAX_UPLOAD_BYTES;
	}

	/** Whether the uploads base directory accepts new folders and files. */
	private static function storage_parent_writable() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		return wp_is_writable( $uploads['basedir'] );
	}
}
