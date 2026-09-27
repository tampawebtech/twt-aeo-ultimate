<?php
/**
 * Document Library — uploads, the processing queue, and the site-page index.
 *
 * An upload is stored in a locked folder, then processed in the background:
 * text extracted, split into passages by TWTAEO_Chunker, stored in the
 * Content Index. The uploaded file is then deleted. Client documents can be
 * confidential, and the safest copy of a file is the one no longer on the
 * server; only the passages the gap search needs are kept.
 *
 * The site's own published posts and pages are indexed the same way (from
 * their stored content, not a web fetch), so a keyword search can show a
 * document's passages beside the site's.
 *
 * All work runs in ticks gated and budgeted by TWTAEO_Host_Profile. Ticks are
 * driven two ways: a WP-Cron event, and the Documents screen polling while it
 * is open, because WP-Cron does not fire on sites with no traffic.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Doc_Library {

	const CRON_HOOK   = 'twtaeo_cge_docs_tick';
	const AJAX_TICK   = 'twtaeo_cge_docs_tick';
	const NONCE       = 'twtaeo_cge_docs';
	const OPTION_SITE = 'twtaeo_cge_site_index';

	/** Folder under uploads/ for files waiting to be processed. */
	const STORAGE_DIR = 'twtaeo-docs';

	/** A document stuck mid-extraction this long was killed by the host. */
	const STUCK_AFTER = 600;

	/** Upper bound on passages kept per document. */
	const MAX_CHUNKS_PER_DOC = 4000;

	/** Passages written per step of a document's indexing. */
	const INDEX_BATCH = 100;

	private function __construct() {}

	/** Unfinished uploads are forgotten after this long. */
	const PIECES_TTL = 2 * DAY_IN_SECONDS;

	public static function register_hooks() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
		add_action( 'wp_ajax_' . self::AJAX_TICK, array( __CLASS__, 'ajax_tick' ) );
		add_action( 'wp_ajax_twtaeo_cge_up_start', array( __CLASS__, 'ajax_upload_start' ) );
		add_action( 'wp_ajax_twtaeo_cge_up_piece', array( __CLASS__, 'ajax_upload_piece' ) );
		add_action( 'wp_ajax_twtaeo_cge_up_finish', array( __CLASS__, 'ajax_upload_finish' ) );
		add_action( 'wp_ajax_twtaeo_cge_ai_consent', array( __CLASS__, 'ajax_ai_consent' ) );

		// Keep the site index current once it has been built.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 30, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_remove_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_remove_post' ) );
	}

	/* ─────────────────────────── upload ─────────────────────────── */

	/**
	 * Accept one uploaded file from $_FILES and queue it.
	 *
	 * @param array $file  An entry from $_FILES.
	 * @param bool  $ai_ok The owner allowed an AI provider to read the first pages to label it.
	 * @return int|WP_Error Document ID.
	 */
	public static function handle_upload( array $file, $ai_ok = false ) {
		$gate = TWTAEO_Doc_Requirements::can_upload();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'twtaeo_doc_upload', self::upload_error_message( (int) $file['error'] ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		$types = TWTAEO_Doc_Requirements::types();
		if ( ! isset( $types[ $ext ] ) ) {
			return new WP_Error( 'twtaeo_doc_type', __( 'Only PDF, Word (.docx), .txt and .md files can be uploaded.', 'twt-aeo-ultimate' ) );
		}
		if ( ! $types[ $ext ]['ok'] ) {
			return new WP_Error( 'twtaeo_doc_server', $types[ $ext ]['reason'] );
		}

		$max = TWTAEO_Doc_Requirements::max_upload_bytes();
		if ( isset( $file['size'] ) && (int) $file['size'] > $max ) {
			return new WP_Error( 'twtaeo_doc_size', TWTAEO_Doc_Requirements::too_big_message( (int) $file['size'] ) );
		}

		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// wp_handle_upload does the is_uploaded_file() and type checks; point it
		// at the locked folder and give the file a name nobody can guess.
		$redirect = static function ( $uploads ) use ( $dir ) {
			$uploads['path']   = $dir;
			$uploads['url']    = '';
			$uploads['subdir'] = '';
			return $uploads;
		};
		add_filter( 'upload_dir', $redirect );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file['name'] = wp_generate_password( 24, false ) . '.' . $ext;
		$moved        = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'pdf'  => 'application/pdf',
					'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'txt'  => 'text/plain',
					'md'   => 'text/plain',
				),
			)
		);
		remove_filter( 'upload_dir', $redirect );

		if ( empty( $moved['file'] ) ) {
			return new WP_Error(
				'twtaeo_doc_upload',
				! empty( $moved['error'] ) ? (string) $moved['error'] : __( 'The upload could not be saved.', 'twt-aeo-ultimate' )
			);
		}

		return self::queue_file( $moved['file'], $name, $ext, $ai_ok );
	}

	/* ─────────────────────── resumable upload ─────────────────────── */

	/*
	 * The browser sends a file in pieces (TWTAEO_Doc_Requirements::piece_bytes)
	 * to a ".part" file in the locked folder. A dropped connection, a restart
	 * or a power cut loses at most one piece: choosing the same file again
	 * resumes from the bytes the server already has, because the upload is
	 * keyed by user + file name + size + last-modified time.
	 */

	private static function ajax_guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) ), 403 );
		}
	}

	/** The AI-label switch: remembered the moment it is flipped, not only at the next upload. */
	public static function ajax_ai_consent() {
		self::ajax_guard();
		$on = ! empty( $_POST['on'] ) && TWTAEO_Doc_Profile::ai_available(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		update_user_meta( get_current_user_id(), TWTAEO_Doc_Profile::CONSENT_META, $on ? 'yes' : 'no' );
		wp_send_json_success( array( 'on' => $on ) );
	}

	/** An upload's key: one per user and file, stable across restarts. */
	private static function upload_key( $name, $size, $modified ) {
		return md5( get_current_user_id() . '|' . $name . '|' . (int) $size . '|' . (int) $modified );
	}

	private static function part_path( $key ) {
		$dir = self::storage_dir();

		return is_wp_error( $dir ) ? $dir : trailingslashit( $dir ) . $key . '.part';
	}

	private static function part_size( $path ) {
		clearstatcache( true, $path );

		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/** Start (or resume) an upload: validate, then report how much the server already has. */
	public static function ajax_upload_start() {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$name     = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : '';
		$size     = isset( $_POST['size'] ) ? absint( $_POST['size'] ) : 0;
		$modified = isset( $_POST['modified'] ) ? absint( $_POST['modified'] ) : 0;
		$ai_ok    = ! empty( $_POST['ai_ok'] );
		// phpcs:enable

		$gate = TWTAEO_Doc_Requirements::can_upload();
		if ( is_wp_error( $gate ) ) {
			wp_send_json_error( array( 'message' => $gate->get_error_message() ) );
		}
		$ext   = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$types = TWTAEO_Doc_Requirements::types();
		if ( ! isset( $types[ $ext ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PDF, Word (.docx), .txt and .md files can be uploaded.', 'twt-aeo-ultimate' ) ) );
		}
		if ( ! $types[ $ext ]['ok'] ) {
			wp_send_json_error( array( 'message' => $types[ $ext ]['reason'] ) );
		}
		if ( $size < 1 ) {
			wp_send_json_error( array( 'message' => __( 'This file is empty.', 'twt-aeo-ultimate' ) ) );
		}
		if ( $size > TWTAEO_Doc_Requirements::max_document_bytes() ) {
			wp_send_json_error( array( 'message' => TWTAEO_Doc_Requirements::too_big_to_read_message( $size ) ) );
		}

		$key  = self::upload_key( $name, $size, $modified );
		$path = self::part_path( $key );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( array( 'message' => $path->get_error_message() ) );
		}
		set_transient(
			'twtaeo_cge_up_' . $key,
			array(
				'name' => $name,
				'ext'  => $ext,
				'size'  => $size,
				'user'  => get_current_user_id(),
				'ai_ok' => $ai_ok,
			),
			self::PIECES_TTL
		);
		$have = min( self::part_size( $path ), $size );

		wp_send_json_success(
			array(
				'key'   => $key,
				'have'  => $have,
				'piece' => TWTAEO_Doc_Requirements::piece_bytes(),
			)
		);
	}

	/** Append one piece at the offset the server expects. */
	public static function ajax_upload_piece() {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$key    = isset( $_POST['key'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_key( wp_unslash( $_POST['key'] ) ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$tmp    = isset( $_FILES['piece']['tmp_name'] ) ? (string) $_FILES['piece']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked with is_uploaded_file().
		// phpcs:enable

		$meta = get_transient( 'twtaeo_cge_up_' . $key );
		if ( 32 !== strlen( $key ) || ! is_array( $meta ) || (int) $meta['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'This upload expired. Choose the file again.', 'twt-aeo-ultimate' ), 'restart' => true ) );
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'A piece of the upload did not arrive. Retrying…', 'twt-aeo-ultimate' ) ) );
		}

		$path = self::part_path( $key );
		$have = self::part_size( $path );
		// A retried piece the server already stored, or one sent early: tell
		// the browser where to continue instead of writing out of order.
		if ( $offset !== $have ) {
			wp_send_json_success( array( 'have' => $have ) );
		}

		$piece = (int) filesize( $tmp );
		if ( $piece < 1 || $piece > TWTAEO_Doc_Requirements::piece_bytes() || $have + $piece > (int) $meta['size'] ) {
			wp_send_json_error( array( 'message' => __( 'The upload got out of step. Choose the file again.', 'twt-aeo-ultimate' ), 'restart' => true ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- appending binary pieces to our own file in our own locked folder.
		$in  = fopen( $tmp, 'rb' );
		$out = fopen( $path, 'ab' );
		if ( ! $in || ! $out ) {
			wp_send_json_error( array( 'message' => __( 'The server could not save the upload. Ask your host to check the folder permissions on wp-content/uploads.', 'twt-aeo-ultimate' ) ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		// phpcs:enable

		wp_send_json_success( array( 'have' => self::part_size( $path ) ) );
	}

	/** All pieces are in: check the file, then queue it like any upload. */
	public static function ajax_upload_finish() {
		self::ajax_guard();
		$key  = isset( $_POST['key'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_key( wp_unslash( $_POST['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$meta = get_transient( 'twtaeo_cge_up_' . $key );
		if ( 32 !== strlen( $key ) || ! is_array( $meta ) || (int) $meta['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'This upload expired. Choose the file again.', 'twt-aeo-ultimate' ), 'restart' => true ) );
		}

		$path = self::part_path( $key );
		if ( self::part_size( $path ) !== (int) $meta['size'] ) {
			wp_send_json_error( array( 'message' => __( 'The upload is incomplete. Choose the file again to finish it.', 'twt-aeo-ultimate' ), 'restart' => true ) );
		}

		// Same content check wp_handle_upload would make: the bytes must be
		// the type the name claims.
		$mimes = array(
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'txt'  => 'text/plain',
			'md'   => 'text/plain',
		);
		$check = wp_check_filetype_and_ext( $path, $meta['name'], $mimes );
		if ( empty( $check['ext'] ) && 'md' !== $meta['ext'] && 'txt' !== $meta['ext'] ) {
			self::remove_file( $path );
			delete_transient( 'twtaeo_cge_up_' . $key );
			wp_send_json_error( array( 'message' => __( 'This file is not the type its name says. Export it again and upload that.', 'twt-aeo-ultimate' ), 'restart' => true ) );
		}

		$final = trailingslashit( dirname( $path ) ) . wp_generate_password( 24, false ) . '.' . $meta['ext'];
		if ( ! @rename( $path, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- our own folder.
			wp_send_json_error( array( 'message' => __( 'The server could not save the upload.', 'twt-aeo-ultimate' ) ) );
		}
		delete_transient( 'twtaeo_cge_up_' . $key );

		wp_send_json_success( array( 'doc' => self::queue_file( $final, $meta['name'], $meta['ext'], ! empty( $meta['ai_ok'] ) ) ) );
	}

	/**
	 * Record a stored file as a queued document and schedule processing.
	 *
	 * @param bool $ai_ok Consent to an AI label, remembered as this user's default.
	 * @return int Document ID.
	 */
	private static function queue_file( $path, $name, $ext, $ai_ok = false ) {
		global $wpdb;
		TWTAEO_Content_Index::maybe_install();
		$now   = current_time( 'mysql', true );
		$ai_ok = $ai_ok && TWTAEO_Doc_Profile::ai_available();
		update_user_meta( get_current_user_id(), TWTAEO_Doc_Profile::CONSENT_META, $ai_ok ? 'yes' : 'no' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->insert(
			TWTAEO_Content_Index::docs_table(),
			array(
				'title'       => self::title_from_filename( $name ),
				'filename'    => $name,
				'ext'         => $ext,
				'bytes'       => (int) filesize( $path ),
				'status'      => 'queued',
				'stored_path' => $path,
				'ai_ok'       => $ai_ok ? 1 : 0,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		self::schedule();

		return (int) $wpdb->insert_id;
	}

	/** Delete pieces of uploads nobody came back to finish. */
	private static function prune_abandoned_parts() {
		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		foreach ( (array) glob( trailingslashit( $dir ) . '*.part' ) as $part ) {
			if ( is_file( $part ) && filemtime( $part ) < time() - self::PIECES_TTL ) {
				self::remove_file( $part );
			}
		}
	}

	/**
	 * The locked storage folder, created on first use with deny rules for
	 * Apache and IIS and an index.php for everything else. Files live here only
	 * until they are processed, under random names.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'twtaeo_doc_storage', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::STORAGE_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'twtaeo_doc_storage', __( 'Uploaded documents cannot be saved because the WordPress uploads folder is not writable. Ask your host to check the folder permissions on wp-content/uploads.', 'twt-aeo-ultimate' ) );
		}

		$guards = array(
			'.htaccess'  => "# Documents waiting to be processed. Never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $guard => $contents ) {
			$path = trailingslashit( $dir ) . $guard;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard files in our own folder.
			}
		}

		return $dir;
	}

	/* ─────────────────────────── documents ─────────────────────────── */

	/**
	 * Every document, newest first. Extracted text is left out.
	 *
	 * @return array[]
	 */
	public static function documents() {
		global $wpdb;
		TWTAEO_Content_Index::maybe_install();
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		return (array) $wpdb->get_results( "SELECT id, title, filename, ext, bytes, pages, status, error, chunk_count, kind, answer_mode, jurisdiction, edition, citation, summary, profile_source, profile_status, profile_note, ai_ok, doc_date, law_status, law_note, law_url, law_checked_at, created_at, updated_at FROM {$table} ORDER BY id DESC", ARRAY_A );
	}

	/**
	 * Documents read before tables could be rebuilt: their passages hold
	 * table marks (● ○) but no rebuilt table. The file is gone, so uploading
	 * it again is the only way to get the tables back as tables.
	 *
	 * @return int[] Document IDs.
	 */
	public static function scrambled_tables() {
		global $wpdb;
		$table = TWTAEO_Content_Index::chunks_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT source_id FROM {$table} WHERE source_type = %s GROUP BY source_id
				HAVING SUM( content LIKE %s OR content LIKE %s ) >= 2 AND SUM( content LIKE %s ) = 0",
				TWTAEO_Content_Index::SOURCE_DOC,
				'%● ● ●%',
				'%○ ○ ○%',
				'%| --- |%'
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Titles for a set of document IDs.
	 *
	 * @param int[] $ids
	 * @return array id => title
	 */
	public static function titles( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table        = TWTAEO_Content_Index::docs_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A );

		return wp_list_pluck( (array) $rows, 'title', 'id' );
	}

	/** Delete a document, its passages, and its file if it is still waiting. */
	public static function delete_document( $doc_id ) {
		global $wpdb;
		$doc_id = absint( $doc_id );
		$table  = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$path = (string) $wpdb->get_var( $wpdb->prepare( "SELECT stored_path FROM {$table} WHERE id = %d", $doc_id ) );

		self::remove_file( $path );
		TWTAEO_Content_Index::delete_source( TWTAEO_Content_Index::SOURCE_DOC, $doc_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->delete( $table, array( 'id' => $doc_id ), array( '%d' ) );
	}

	/* ─────────────────────────── site index ─────────────────────────── */

	/** Site-index progress. */
	public static function site_state() {
		return wp_parse_args(
			(array) get_option( self::OPTION_SITE, array() ),
			array(
				'status'      => 'idle', // idle | running | done.
				'last_id'     => 0,
				'indexed'     => 0,
				'total'       => 0,
				'started_at'  => 0,
				'finished_at' => 0,
			)
		);
	}

	/** Start (or restart) indexing every published post and page. */
	public static function start_site_index() {
		TWTAEO_Content_Index::maybe_install();
		TWTAEO_Content_Index::delete_type( TWTAEO_Content_Index::SOURCE_POST );

		update_option(
			self::OPTION_SITE,
			array(
				'status'      => 'running',
				'last_id'     => 0,
				'indexed'     => 0,
				'total'       => self::count_indexable_posts(),
				'started_at'  => time(),
				'finished_at' => 0,
			),
			false
		);

		self::schedule();
	}

	/** Post types the site index covers: public, minus attachments. */
	public static function indexable_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		/**
		 * Filters the post types indexed for the Content Gap Engine.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return array_values( (array) apply_filters( 'twtaeo_cge_indexable_types', array_values( $types ) ) );
	}

	/**
	 * Index one post, or drop it from the index when it should not be there.
	 *
	 * @param int $post_id
	 * @return int Passages stored.
	 */
	public static function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::indexable_types(), true ) || post_password_required( $post ) ) {
			TWTAEO_Content_Index::delete_source( TWTAEO_Content_Index::SOURCE_POST, (int) $post_id );
			return 0;
		}

		$parts = array( '# ' . wp_strip_all_tags( get_the_title( $post ) ) );
		// A WooCommerce short description is often the only copy a product
		// has; for posts, a hand-written excerpt is content too.
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$parts[] = TWTAEO_HTML_To_Markdown::convert( wpautop( $post->post_excerpt ) );
		}
		$parts[] = TWTAEO_HTML_To_Markdown::convert( self::post_html( $post ) );
		$parts[] = self::product_facts( $post );

		$chunks = TWTAEO_Chunker::chunk( implode( "\n\n", array_filter( $parts, 'strlen' ) ) );

		return TWTAEO_Content_Index::replace_source( TWTAEO_Content_Index::SOURCE_POST, (int) $post->ID, $chunks );
	}

	/**
	 * A WooCommerce product's SKU (and its variations' SKUs) and attributes,
	 * as a "Product details" section. The SKU is usually the model number a
	 * customer searches for, and attributes (dimensions, voltage, material)
	 * answer the spec questions — none of it lives in the description.
	 *
	 * @return string Markdown, or '' for anything that is not a product.
	 */
	private static function product_facts( WP_Post $post ) {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return '';
		}

		$lines = array();
		$skus  = array_filter( array( (string) $product->get_sku() ) );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && '' !== (string) $child->get_sku() ) {
					$skus[] = (string) $child->get_sku();
				}
			}
		}
		if ( $skus ) {
			/* translators: %s: comma-separated SKUs. */
			$lines[] = sprintf( __( 'SKU: %s', 'twt-aeo-ultimate' ), implode( ', ', array_unique( $skus ) ) );
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $post->ID, $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_filter( array_map( 'strval', (array) $values ) );
			if ( $values ) {
				$lines[] = wc_attribute_label( $attribute->get_name(), $product ) . ': ' . implode( ', ', $values );
			}
		}

		return $lines ? '## ' . __( 'Product details', 'twt-aeo-ultimate' ) . "\n\n" . implode( "\n", $lines ) : '';
	}

	/**
	 * A post's stored content as HTML, without running the_content filters.
	 *
	 * Those filters can fire shortcodes with side effects (forms, counters,
	 * remote calls) inside a background tick. Blocks are rendered; shortcode
	 * tags are removed but their inner text kept, because page builders such as
	 * Divi wrap every paragraph in one.
	 */
	private static function post_html( WP_Post $post ) {
		$html = function_exists( 'do_blocks' ) ? do_blocks( $post->post_content ) : $post->post_content;
		$html = preg_replace( '/\[\/?[A-Za-z0-9_\-]+(?:\s[^\]]*)?\]/', ' ', (string) $html );

		return wpautop( (string) $html );
	}

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Only once an index exists: saving a post must not start indexing a
		// site whose owner never asked for it.
		if ( 'idle' === self::site_state()['status'] ) {
			return;
		}
		if ( true !== TWTAEO_Host_Profile::can_run() ) {
			return;
		}
		self::index_post( $post_id );
	}

	public static function on_remove_post( $post_id ) {
		if ( 'idle' === self::site_state()['status'] ) {
			return;
		}
		TWTAEO_Content_Index::delete_source( TWTAEO_Content_Index::SOURCE_POST, (int) $post_id );
	}

	/* ─────────────────────────── the work loop ─────────────────────────── */

	/** Queue a background tick soon, once. */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	public static function cron_tick() {
		$status = self::process();
		if ( ! empty( $status['pending'] ) ) {
			self::schedule();
		}
	}

	/** The Documents screen polls this while work is pending. */
	public static function ajax_tick() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'twt-aeo-ultimate' ), 403 );
		}

		wp_send_json_success( self::process() );
	}

	/**
	 * One budgeted tick: finish queued documents first, then continue the site
	 * index. Stops at the host's time or memory budget and resumes next tick.
	 *
	 * @return array { pending: bool, error: string, docs: array, site: array }
	 */
	public static function process() {
		$gate = TWTAEO_Host_Profile::can_run();
		if ( is_wp_error( $gate ) ) {
			return array(
				'pending' => false,
				'error'   => $gate->get_error_message(),
			);
		}

		// Two browser tabs polling, or cron firing mid-poll, must not process
		// the same document twice.
		if ( get_transient( 'twtaeo_cge_docs_lock' ) ) {
			return self::status();
		}
		set_transient( 'twtaeo_cge_docs_lock', 1, 120 );

		TWTAEO_Content_Index::maybe_install();
		TWTAEO_Host_Profile::budget_start();
		self::fail_stuck_documents();
		self::prune_abandoned_parts();

		try {
			while ( ! TWTAEO_Host_Profile::should_stop() ) {
				$doc = self::next_queued();
				if ( ! $doc ) {
					break;
				}
				if ( 'indexing' === $doc['status'] ) {
					self::continue_indexing( (int) $doc['id'] );
				} else {
					self::process_document( $doc );
				}
			}

			// One AI label per tick at most: each is a network request.
			if ( ! TWTAEO_Host_Profile::should_stop() ) {
				// Same for a law or code's online check.
				if ( ! TWTAEO_Doc_Profile::process_pending() && ! TWTAEO_Host_Profile::should_stop() ) {
					TWTAEO_Law_Check::process_pending();
				}
			}

			if ( ! TWTAEO_Host_Profile::should_stop() && 'running' === self::site_state()['status'] ) {
				self::index_site_batch();
			}

			TWTAEO_Host_Profile::record_success();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::log_exception( $e, array( 'context' => 'cge_docs_tick' ) );
			}
			TWTAEO_Host_Profile::record_failure( 'docs tick: ' . $e->getMessage() );
		}

		delete_transient( 'twtaeo_cge_docs_lock' );

		return self::status();
	}

	/** What the Documents screen needs to redraw. */
	public static function status() {
		$docs    = self::documents();
		$site    = self::site_state();
		$pending = 'running' === $site['status'];
		foreach ( $docs as $doc ) {
			if ( in_array( $doc['status'], array( 'queued', 'extracting', 'indexing' ), true ) || ( 'ready' === $doc['status'] && ( 'pending' === $doc['profile_status'] || in_array( $doc['law_status'], array( 'pending', 'checking' ), true ) ) ) ) {
				$pending = true;
				break;
			}
		}

		return array(
			'pending' => $pending,
			'error'   => '',
			'docs'    => array_map(
				static function ( $doc ) {
					return array(
						'id'     => (int) $doc['id'],
						'status' => $doc['status'],
						'chunks' => (int) $doc['chunk_count'],
					);
				},
				$docs
			),
			'site'    => array(
				'status'  => $site['status'],
				'indexed' => (int) $site['indexed'],
				'total'   => (int) $site['total'],
			),
		);
	}

	private static function next_queued() {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// Finish a half-indexed document before starting a new one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		return $wpdb->get_row( "SELECT id, ext, stored_path, status FROM {$table} WHERE status IN ('indexing','queued') ORDER BY (status = 'indexing') DESC, id ASC LIMIT 1", ARRAY_A );
	}

	/**
	 * Extract, chunk, store, delete the file. Extraction is one step that
	 * cannot be paused, so the status is set first: if the host kills the
	 * request mid-parse, the document is found "extracting" next tick and
	 * failed with an explanation instead of retried forever.
	 */
	private static function process_document( array $doc ) {
		$id = (int) $doc['id'];
		self::update_doc( $id, array( 'status' => 'extracting' ) );

		$result = TWTAEO_Doc_Extractor::extract( (string) $doc['stored_path'], (string) $doc['ext'] );

		// The file's job is done either way.
		self::remove_file( (string) $doc['stored_path'] );

		if ( is_wp_error( $result ) ) {
			self::update_doc(
				$id,
				array(
					'status'      => 'failed',
					'error'       => $result->get_error_message(),
					'stored_path' => '',
				)
			);
			return;
		}

		// The text is saved before any passage is written, so indexing can
		// stop at a tick's budget — or a host restart — and pick up where it
		// left off, without reading the file again.
		TWTAEO_Content_Index::delete_source( TWTAEO_Content_Index::SOURCE_DOC, $id );
		self::update_doc(
			$id,
			array(
				'status'      => 'indexing',
				'markdown'    => $result['markdown'],
				'error'       => isset( $result['note'] ) ? (string) $result['note'] : '',
				'pages'       => (int) $result['pages'],
				'chunk_count' => 0,
				'stored_path' => '',
			)
		);

		self::continue_indexing( $id );
	}

	/**
	 * Write a document's passages in batches from where the last tick stopped.
	 * The passage count stored so far is the resume point; the split is
	 * deterministic, so re-cutting the saved text lines up with it.
	 */
	private static function continue_indexing( $id ) {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT markdown, chunk_count, error FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $doc ) {
			return;
		}

		$chunks = TWTAEO_Chunker::chunk( (string) $doc['markdown'] );
		$capped = count( $chunks ) > self::MAX_CHUNKS_PER_DOC;
		if ( $capped ) {
			$chunks = array_slice( $chunks, 0, self::MAX_CHUNKS_PER_DOC );
		}

		$done = (int) $doc['chunk_count'];
		while ( $done < count( $chunks ) ) {
			$batch = array_slice( $chunks, $done, self::INDEX_BATCH );
			$done += count( $batch );
			TWTAEO_Content_Index::append_chunks( TWTAEO_Content_Index::SOURCE_DOC, $id, $batch );
			self::update_doc( $id, array( 'chunk_count' => $done ) );
			if ( $done < count( $chunks ) && TWTAEO_Host_Profile::should_stop() ) {
				return; // Budget spent; the next tick resumes here.
			}
		}

		$note = (string) $doc['error'];
		if ( $capped ) {
			/* translators: %d: passage limit. */
			$note = sprintf( __( 'Very long document: only the first %d passages were kept. Split it into parts to search all of it.', 'twt-aeo-ultimate' ), self::MAX_CHUNKS_PER_DOC );
		}
		self::update_doc(
			$id,
			array(
				'status'   => $done ? 'ready' : 'failed',
				'error'    => $done ? $note : __( 'No passages could be made from this file\'s text.', 'twt-aeo-ultimate' ),
				'markdown' => null,
			)
		);

		if ( $done ) {
			TWTAEO_Doc_Profile::after_indexing( $id );
		}
	}

	/**
	 * A document left in "extracting" for longer than any tick can run was
	 * killed by the host — almost always a memory or time limit on a big PDF.
	 */
	private static function fail_stuck_documents() {
		global $wpdb;
		$table  = TWTAEO_Content_Index::docs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$stuck = $wpdb->get_results( $wpdb->prepare( "SELECT id, stored_path FROM {$table} WHERE status = 'extracting' AND updated_at < %s", $cutoff ), ARRAY_A );

		foreach ( (array) $stuck as $doc ) {
			self::remove_file( (string) $doc['stored_path'] );
			self::update_doc(
				(int) $doc['id'],
				array(
					'status'      => 'failed',
					'error'       => __( 'Your server ran out of memory or time while reading this file — it is too large to read in one go on this hosting plan.', 'twt-aeo-ultimate' ) . ' ' . TWTAEO_Doc_Requirements::split_instructions(),
					'stored_path' => '',
				)
			);
			TWTAEO_Host_Profile::record_failure( 'document extraction killed' );
		}
	}

	private static function index_site_batch() {
		global $wpdb;
		$state = self::site_state();
		$types = self::indexable_types();
		if ( empty( $types ) ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			update_option( self::OPTION_SITE, $state, false );
			return;
		}

		$batch        = TWTAEO_Host_Profile::batch_size( TWTAEO_Host_Profile::COST_CHUNK_PAGE );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$values       = array_merge( array( (int) $state['last_id'] ), $types, array( $batch ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one %s per post type, built above with its values.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d", $values ) );

		if ( empty( $ids ) ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			update_option( self::OPTION_SITE, $state, false );
			return;
		}

		foreach ( $ids as $post_id ) {
			if ( TWTAEO_Host_Profile::should_stop() ) {
				break;
			}
			self::index_post( (int) $post_id );
			$state['last_id'] = (int) $post_id;
			++$state['indexed'];
		}

		update_option( self::OPTION_SITE, $state, false );
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	private static function count_indexable_posts() {
		$total = 0;
		foreach ( self::indexable_types() as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		return $total;
	}

	private static function update_doc( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->update( TWTAEO_Content_Index::docs_table(), $fields, array( 'id' => (int) $id ) );
	}

	/** Delete a stored file, but only one inside our own folder. */
	private static function remove_file( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}
		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		$real_dir  = realpath( $dir );
		$real_path = realpath( $path );
		if ( $real_dir && $real_path && 0 === strpos( $real_path, $real_dir . DIRECTORY_SEPARATOR ) ) {
			wp_delete_file( $real_path );
		}
	}

	private static function title_from_filename( $name ) {
		$title = preg_replace( '/[_\-]+/', ' ', pathinfo( (string) $name, PATHINFO_FILENAME ) );
		$title = trim( preg_replace( '/\s+/', ' ', (string) $title ) );

		return '' !== $title ? $title : __( 'Untitled document', 'twt-aeo-ultimate' );
	}

	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return TWTAEO_Doc_Requirements::too_big_message();
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Try again.', 'twt-aeo-ultimate' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a file to upload.', 'twt-aeo-ultimate' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Your server could not save the upload (no writable temporary folder). Ask your host to check PHP\'s upload_tmp_dir.', 'twt-aeo-ultimate' );
			default:
				return __( 'The upload failed. Try again.', 'twt-aeo-ultimate' );
		}
	}
}
