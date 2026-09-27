<?php
/**
 * Documents Page — upload documents and search them beside the site.
 *
 * Content Gap Engine, phase 2a. Upload PDFs, Word files and text; they are
 * split into passages exactly as Chunk View splits pages. A keyword search
 * then shows what the documents say next to what the site says, so a topic the
 * documents cover and the site does not is visible at a glance.
 *
 * Each document carries a label (TWTAEO_Doc_Profile) saying what it is and
 * how it may be quoted. Nothing here calls an outside service unless the owner
 * ticks the consent box, which lets an AI provider read the first pages to
 * label the document. Engine: TWTAEO_Doc_Library, TWTAEO_Doc_Extractor,
 * TWTAEO_Content_Index, TWTAEO_Doc_Profile.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Documents {

	/** Pre-RAG-Engine slug, kept so old links redirect to the Documents tab. */
	const SLUG = 'twt-aeo-documents';

	/** Per-user notice carried across the post/redirect/get. */
	const NOTICE_TRANSIENT = 'twtaeo_cge_docs_notice_';

	public static function register_hooks() {
		add_action( 'admin_post_twtaeo_cge_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_twtaeo_cge_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_twtaeo_cge_index_site', array( __CLASS__, 'handle_index_site' ) );
		add_action( 'admin_post_twtaeo_cge_label', array( __CLASS__, 'handle_label' ) );
		add_action( 'admin_post_twtaeo_cge_ai_label', array( __CLASS__, 'handle_ai_label' ) );
		add_action( 'admin_post_twtaeo_cge_law_check', array( __CLASS__, 'handle_law_check' ) );
	}

	private static function cap() {
		return class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
	}

	/* ─────────────────────────── actions ─────────────────────────── */

	public static function handle_upload() {
		// A request bigger than post_max_size arrives with $_POST and $_FILES
		// both emptied by PHP — including the nonce, so WordPress would say
		// "the link you followed has expired". Say what actually happened.
		// No action is taken here, so there is nothing for a nonce to protect.
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( empty( $_POST ) && empty( $_FILES ) && $length > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( current_user_can( self::cap() ) ) {
				self::notice( 'error', TWTAEO_Doc_Requirements::too_big_message( $length ) );
				self::back();
			}
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}

		check_admin_referer( 'twtaeo_cge_upload' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array is validated by TWTAEO_Doc_Library / wp_handle_upload.
		$files = isset( $_FILES['twtaeo_cge_files'] ) ? self::normalize_files( $_FILES['twtaeo_cge_files'] ) : array();
		if ( empty( $files ) ) {
			self::notice( 'error', __( 'Choose a file to upload.', 'twt-aeo-ultimate' ) );
			self::back();
		}

		$queued = 0;
		$errors = array();
		$ai_ok  = ! empty( $_POST['ai_ok'] );
		foreach ( $files as $file ) {
			$result = TWTAEO_Doc_Library::handle_upload( $file, $ai_ok );
			if ( is_wp_error( $result ) ) {
				$name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
				$errors[] = ( '' !== $name ? $name . ': ' : '' ) . $result->get_error_message();
			} else {
				++$queued;
			}
		}

		if ( $queued ) {
			self::notice(
				'success',
				sprintf(
					/* translators: %d: number of files. */
					_n( '%d document uploaded. It is being read now — this page updates as it finishes.', '%d documents uploaded. They are being read now — this page updates as they finish.', $queued, 'twt-aeo-ultimate' ),
					$queued
				),
				$errors
			);
		} else {
			self::notice( 'error', __( 'Nothing was uploaded.', 'twt-aeo-ultimate' ), $errors );
		}
		self::back();
	}

	public static function handle_delete() {
		check_admin_referer( 'twtaeo_cge_delete' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		$doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;
		if ( $doc_id ) {
			TWTAEO_Doc_Library::delete_document( $doc_id );
			self::notice( 'success', __( 'Document deleted, with all of its passages.', 'twt-aeo-ultimate' ) );
		}
		self::back();
	}

	/** The owner's own label for a document. */
	public static function handle_label() {
		check_admin_referer( 'twtaeo_cge_label' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		$doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;
		if ( $doc_id ) {
			$input = array();
			foreach ( array( 'kind', 'answer_mode', 'jurisdiction', 'edition', 'citation' ) as $field ) {
				$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			}
			TWTAEO_Doc_Profile::save_owner_label( $doc_id, $input );
			self::notice( 'success', __( 'Label saved. It is used for every passage from this document.', 'twt-aeo-ultimate' ) );
		}
		self::back();
	}

	/** Clicking "Let AI label it" is the owner's consent for that document. */
	public static function handle_ai_label() {
		check_admin_referer( 'twtaeo_cge_ai_label' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		$doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;
		if ( $doc_id && TWTAEO_Doc_Profile::ai_available() ) {
			TWTAEO_Doc_Profile::request_ai_label( $doc_id );
			self::notice( 'success', __( 'The AI is reading the first pages to label this document. This page updates when it is done.', 'twt-aeo-ultimate' ) );
		}
		self::back();
	}

	/** "Check again": look the law or code up online now. */
	public static function handle_law_check() {
		check_admin_referer( 'twtaeo_cge_law_check' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		$doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;
		if ( $doc_id && TWTAEO_Law_Check::available() ) {
			TWTAEO_Law_Check::request( $doc_id );
			self::notice( 'success', __( 'Checking online whether this law or code has changed. This page updates when it is done.', 'twt-aeo-ultimate' ) );
		}
		self::back();
	}

	public static function handle_index_site() {
		check_admin_referer( 'twtaeo_cge_index_site' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'twt-aeo-ultimate' ) );
		}
		$gate = TWTAEO_Host_Profile::can_run();
		if ( is_wp_error( $gate ) ) {
			self::notice( 'error', $gate->get_error_message() );
		} else {
			TWTAEO_Doc_Library::start_site_index();
			self::notice( 'success', __( 'Indexing your published pages and posts. This page updates as it goes.', 'twt-aeo-ultimate' ) );
		}
		self::back();
	}

	/* ─────────────────────────── render ─────────────────────────── */

	/**
	 * Drawn as the Documents tab of the RAG Engine screen, which supplies the
	 * wrap and page title.
	 */
	public static function render() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		TWTAEO_Content_Index::maybe_install();
		TWTAEO_Doc_Profile::backfill();
		TWTAEO_Law_Check::maintain();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$docs  = TWTAEO_Doc_Library::documents();
		$site  = TWTAEO_Doc_Library::site_state();
		?>
		<div class="twtaeo-documents">
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Upload the documents your business works from — spec sheets, manuals, catalogs, FAQs, proposals — and search them beside your own site. When a search finds answers in your documents but not on your pages, that is content your site is missing.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php self::render_notice(); ?>
			<?php self::render_requirements(); ?>

			<div id="twtaeo-cge-status" class="notice notice-info inline" style="display:none;margin:12px 0;"><p></p></div>

			<?php
			self::render_upload();
			self::render_documents( $docs );
			self::render_site_index( $site );
			self::render_search( $query, $docs, $site );
			?>
		</div>
		<?php
	}

	private static function render_requirements() {
		$checks = TWTAEO_Doc_Requirements::checks();
		$failed = array_filter(
			$checks,
			static function ( $c ) {
				return ! $c['ok'];
			}
		);
		$blocking = array_filter(
			$failed,
			static function ( $c ) {
				return 'error' === $c['level'];
			}
		);
		?>
		<details class="card" style="max-width:none;margin:16px 0;padding:10px 16px;" <?php echo $blocking ? 'open' : ''; ?>>
			<summary style="cursor:pointer;font-weight:600;">
				<?php
				if ( empty( $failed ) ) {
					esc_html_e( '✓ Your server can read every supported document type', 'twt-aeo-ultimate' );
				} elseif ( $blocking ) {
					esc_html_e( '⚠ Your server is missing something documents need — see below', 'twt-aeo-ultimate' );
				} else {
					esc_html_e( 'Server check: works, with notes', 'twt-aeo-ultimate' );
				}
				?>
			</summary>
			<table class="widefat striped" style="margin-top:10px;">
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td style="width:28px;font-size:16px;">
							<?php echo $check['ok'] ? '<span style="color:#00a32a;">✓</span>' : ( 'error' === $check['level'] ? '<span style="color:#d63638;">✕</span>' : '<span style="color:#dba617;">!</span>' ); ?>
						</td>
						<td style="width:240px;"><strong><?php echo esc_html( $check['label'] ); ?></strong>
							<?php if ( '' !== $check['detail'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $check['detail'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $check['ok'] ? esc_html__( 'OK', 'twt-aeo-ultimate' ) : esc_html( $check['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	private static function render_upload() {
		$types  = TWTAEO_Doc_Requirements::types();
		$usable = array_filter(
			$types,
			static function ( $t ) {
				return $t['ok'];
			}
		);
		$gate   = TWTAEO_Doc_Requirements::can_upload();
		$accept = implode( ',', array_map( static function ( $ext ) { return '.' . $ext; }, array_keys( $usable ) ) );
		?>
		<div class="card" style="max-width:none;padding:12px 16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Upload documents', 'twt-aeo-ultimate' ); ?></h2>

			<?php if ( is_wp_error( $gate ) ) : ?>
				<p style="color:#d63638;"><?php echo esc_html( $gate->get_error_message() ); ?></p>
			<?php elseif ( empty( $usable ) ) : ?>
				<p style="color:#d63638;"><?php esc_html_e( 'This server cannot read any supported file type. See the server check above.', 'twt-aeo-ultimate' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="twtaeo-cge-upload">
					<?php wp_nonce_field( 'twtaeo_cge_upload' ); ?>
					<input type="hidden" name="action" value="twtaeo_cge_upload" />
					<p>
						<input type="file" name="twtaeo_cge_files[]" multiple required accept="<?php echo esc_attr( $accept ); ?>" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload', 'twt-aeo-ultimate' ); ?></button>
					</p>
					<?php if ( TWTAEO_Doc_Profile::ai_available() ) : ?>
						<style>
							.twtaeo-switch{display:flex;gap:10px;align-items:flex-start;cursor:pointer}
							.twtaeo-switch input{position:absolute;opacity:0;width:1px;height:1px}
							.twtaeo-switch__track{flex:0 0 auto;position:relative;width:44px;height:24px;border-radius:12px;background:#d63638;transition:background .15s;margin-top:1px}
							.twtaeo-switch__track::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:transform .15s}
							.twtaeo-switch input:checked + .twtaeo-switch__track{background:#00a32a}
							.twtaeo-switch input:checked + .twtaeo-switch__track::after{transform:translateX(20px)}
							.twtaeo-switch input:focus-visible + .twtaeo-switch__track{outline:2px solid #2271b1;outline-offset:2px}
							.twtaeo-switch__state{font-weight:600;margin-right:4px}
							.twtaeo-switch__on,.twtaeo-switch input:checked ~ .twtaeo-switch__text .twtaeo-switch__off{display:none}
							.twtaeo-switch input:checked ~ .twtaeo-switch__text .twtaeo-switch__on{display:inline;color:#00a32a}
							.twtaeo-switch__off{color:#d63638}
						</style>
						<p>
							<label class="twtaeo-switch">
								<input type="checkbox" role="switch" name="ai_ok" value="1" id="twtaeo-cge-ai-ok" <?php checked( 'yes', get_user_meta( get_current_user_id(), TWTAEO_Doc_Profile::CONSENT_META, true ) ); ?> />
								<span class="twtaeo-switch__track" aria-hidden="true"></span>
								<span class="twtaeo-switch__text">
									<span class="twtaeo-switch__state twtaeo-switch__off"><?php esc_html_e( 'AI labelling off.', 'twt-aeo-ultimate' ); ?></span>
									<span class="twtaeo-switch__state twtaeo-switch__on"><?php esc_html_e( 'AI labelling on.', 'twt-aeo-ultimate' ); ?></span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: AI provider. */
											__( 'Let AI read the first few pages to label each document — what kind it is, the state or city it applies to, its edition, and what to cite it as. Only those pages are sent, once, to %s.', 'twt-aeo-ultimate' ),
											TWTAEO_Doc_Profile::provider_name()
										)
									);
									?>
								</span>
							</label>
							<span class="description" style="display:block;margin:4px 0 0 54px;"><?php esc_html_e( 'Leave it off for confidential documents: they are then labelled on your server from their text, and you can correct any label yourself. Your choice is remembered.', 'twt-aeo-ultimate' ); ?></span>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: list of file types, 2: maximum size. */
								__( 'Accepted: %1$s. Up to %2$s each.', 'twt-aeo-ultimate' ),
								implode( ', ', wp_list_pluck( $usable, 'label' ) ),
								size_format( TWTAEO_Doc_Requirements::max_document_bytes() )
							)
						);
						?>
					</p>
				</form>
				<?php foreach ( $types as $type ) : ?>
					<?php if ( ! $type['ok'] ) : ?>
						<p class="description" style="color:#996800;">
							<?php
							/* translators: 1: file type, 2: reason. */
							echo esc_html( sprintf( __( '%1$s files cannot be read on this server: %2$s', 'twt-aeo-ultimate' ), $type['label'], $type['reason'] ) );
							?>
						</p>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<p style="margin-bottom:0;">
				<span class="dashicons dashicons-lock" style="color:#646970;"></span>
				<?php esc_html_e( 'Private: documents are processed on your own server. Your document\'s text is never sent to an outside service unless you tick the AI box above, and then only the first few pages. For laws and codes, only their name, sections and date are looked up online to see whether they have changed. The uploaded file is deleted as soon as its text has been read; only the searchable passages are kept, and deleting a document removes those too.', 'twt-aeo-ultimate' ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_documents( array $docs ) {
		$labels = array(
			'queued'     => __( 'Waiting', 'twt-aeo-ultimate' ),
			'extracting' => __( 'Reading…', 'twt-aeo-ultimate' ),
			'indexing'   => __( 'Indexing…', 'twt-aeo-ultimate' ),
			'ready'      => __( 'Ready', 'twt-aeo-ultimate' ),
			'failed'     => __( 'Could not read', 'twt-aeo-ultimate' ),
		);
		?>
		<h2><?php esc_html_e( 'Your documents', 'twt-aeo-ultimate' ); ?></h2>
		<?php if ( empty( $docs ) ) : ?>
			<p class="description"><?php esc_html_e( 'No documents yet.', 'twt-aeo-ultimate' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Document', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:70px;"><?php esc_html_e( 'Type', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Size', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:60px;"><?php esc_html_e( 'Pages', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Passages', 'twt-aeo-ultimate' ); ?></th>
				<th><?php esc_html_e( 'Status', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Uploaded', 'twt-aeo-ultimate' ); ?></th>
				<th style="width:80px;"></th>
			</tr>
			</thead>
			<tbody>
			<?php $scrambled = TWTAEO_Doc_Library::scrambled_tables(); ?>
			<?php foreach ( $docs as $doc ) : ?>
				<?php
				$status = isset( $labels[ $doc['status'] ] ) ? $labels[ $doc['status'] ] : $doc['status'];
				$color  = 'failed' === $doc['status'] ? '#d63638' : ( 'ready' === $doc['status'] ? '#00a32a' : '#646970' );
				?>
				<tr data-doc="<?php echo esc_attr( (int) $doc['id'] ); ?>" data-status="<?php echo esc_attr( $doc['status'] ); ?>">
					<td>
						<strong><?php echo esc_html( $doc['title'] ); ?></strong><br /><span class="description"><?php echo esc_html( $doc['filename'] ); ?></span>
						<?php if ( 'ready' === $doc['status'] ) : ?>
							<?php self::render_label( $doc ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( strtoupper( $doc['ext'] ) ); ?></td>
					<td><?php echo esc_html( size_format( (int) $doc['bytes'] ) ); ?></td>
					<td><?php echo $doc['pages'] ? esc_html( number_format_i18n( (int) $doc['pages'] ) ) : '—'; ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $doc['chunk_count'] ) ); ?></td>
					<td>
						<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $status ); ?></span>
						<?php if ( '' !== (string) $doc['error'] ) : ?>
							<br /><span class="description"><?php echo esc_html( $doc['error'] ); ?></span>
						<?php endif; ?>
						<?php if ( in_array( (int) $doc['id'], $scrambled, true ) ) : ?>
							<br /><span class="description" style="color:#996800;"><?php esc_html_e( 'Its tables were read before tables could be rebuilt, so they copy as scrambled marks. Delete it and upload it again to get them as real tables.', 'twt-aeo-ultimate' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( human_time_diff( strtotime( $doc['created_at'] . ' UTC' ), time() ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="twtaeo-cge-delete">
							<?php wp_nonce_field( 'twtaeo_cge_delete' ); ?>
							<input type="hidden" name="action" value="twtaeo_cge_delete" />
							<input type="hidden" name="doc_id" value="<?php echo esc_attr( (int) $doc['id'] ); ?>" />
							<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'twt-aeo-ultimate' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_site_index( array $site ) {
		$counts = TWTAEO_Content_Index::counts();
		?>
		<div class="card" style="max-width:none;padding:12px 16px;margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Your site', 'twt-aeo-ultimate' ); ?></h2>
			<p id="twtaeo-cge-site-line">
				<?php
				if ( 'running' === $site['status'] ) {
					echo esc_html(
						sprintf(
							/* translators: 1: posts done, 2: posts total. */
							__( 'Indexing… %1$s of %2$s published pages and posts.', 'twt-aeo-ultimate' ),
							number_format_i18n( (int) $site['indexed'] ),
							number_format_i18n( (int) $site['total'] )
						)
					);
				} elseif ( 'done' === $site['status'] ) {
					echo esc_html(
						sprintf(
							/* translators: 1: pages count, 2: passage count, 3: time ago. */
							__( '%1$s pages and posts indexed as %2$s passages, %3$s ago. Pages you publish or update are re-indexed automatically.', 'twt-aeo-ultimate' ),
							number_format_i18n( $counts['post']['sources'] ),
							number_format_i18n( $counts['post']['passages'] ),
							human_time_diff( (int) $site['finished_at'], time() )
						)
					);
				} else {
					esc_html_e( 'Your pages have not been indexed yet. Index them so searches can compare your documents with your site.', 'twt-aeo-ultimate' );
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<?php wp_nonce_field( 'twtaeo_cge_index_site' ); ?>
				<input type="hidden" name="action" value="twtaeo_cge_index_site" />
				<button type="submit" class="button <?php echo 'idle' === $site['status'] ? 'button-primary' : ''; ?>" <?php disabled( 'running', $site['status'] ); ?>>
					<?php 'idle' === $site['status'] ? esc_html_e( 'Index my pages', 'twt-aeo-ultimate' ) : esc_html_e( 'Rebuild page index', 'twt-aeo-ultimate' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	private static function render_search( $query, array $docs, array $site ) {
		$ready = array_filter(
			$docs,
			static function ( $d ) {
				return 'ready' === $d['status'];
			}
		);
		?>
		<h2 style="margin-top:28px;"><?php esc_html_e( 'Search documents and site', 'twt-aeo-ultimate' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( TWTAEO_Page_RAG_Engine::SLUG ); ?>" />
			<input type="hidden" name="tab" value="documents" />
			<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" class="regular-text" style="width:420px;max-width:100%;" placeholder="<?php esc_attr_e( 'e.g. bearing load rating', 'twt-aeo-ultimate' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'twt-aeo-ultimate' ); ?></button>
		</form>
		<?php

		if ( '' === $query ) {
			echo '<p class="description">' . esc_html__( 'Try a product, a spec, or a question a customer asks. Results from your documents and your site appear side by side.', 'twt-aeo-ultimate' ) . '</p>';
			return;
		}
		if ( empty( $ready ) ) {
			echo '<p>' . esc_html__( 'No documents are ready to search yet.', 'twt-aeo-ultimate' ) . '</p>';
		}

		$doc_hits  = TWTAEO_Content_Index::search( $query, TWTAEO_Content_Index::SOURCE_DOC, 20 );
		$site_hits = TWTAEO_Content_Index::search( $query, TWTAEO_Content_Index::SOURCE_POST, 20 );
		$titles    = TWTAEO_Doc_Library::titles( wp_list_pluck( $doc_hits, 'source_id' ) );
		$profiles  = TWTAEO_Doc_Profile::profiles( wp_list_pluck( $doc_hits, 'source_id' ) );

		if ( $doc_hits && ! $site_hits && 'done' === $site['status'] ) {
			?>
			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'Possible content gap:', 'twt-aeo-ultimate' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of passages. */
						_n( 'your documents have %d passage about this and your site has none.', 'your documents have %d passages about this and your site has none.', count( $doc_hits ), 'twt-aeo-ultimate' ),
						count( $doc_hits )
					)
				);
				?>
				</p>
			</div>
			<?php
		} elseif ( 'done' !== $site['status'] ) {
			echo '<p class="description">' . esc_html__( 'Index your pages (above) to see your site\'s results beside your documents.', 'twt-aeo-ultimate' ) . '</p>';
		}
		?>
		<div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:12px;">
			<div style="flex:1 1 420px;min-width:0;">
				<h3>
					<?php
					/* translators: %d: result count. */
					echo esc_html( sprintf( __( 'In your documents (%d)', 'twt-aeo-ultimate' ), count( $doc_hits ) ) );
					?>
				</h3>
				<?php if ( empty( $doc_hits ) ) : ?>
					<p class="description"><?php esc_html_e( 'No matching passages.', 'twt-aeo-ultimate' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $doc_hits as $hit ) : ?>
					<?php self::render_passage( 'twtaeo-doc-hit-' . (int) $hit['id'], $hit, $query, $profiles, $titles ); ?>
				<?php endforeach; ?>
			</div>
			<div style="flex:1 1 420px;min-width:0;">
				<h3>
					<?php
					/* translators: %d: result count. */
					echo esc_html( sprintf( __( 'On your site (%d)', 'twt-aeo-ultimate' ), count( $site_hits ) ) );
					?>
				</h3>
				<?php if ( empty( $site_hits ) ) : ?>
					<p class="description"><?php esc_html_e( 'No matching passages.', 'twt-aeo-ultimate' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $site_hits as $hit ) : ?>
					<?php $post_id = (int) $hit['source_id']; ?>
					<div class="card" style="max-width:none;margin:0 0 10px;padding:10px 14px;border-left:4px solid #00a32a;">
						<p style="margin:0 0 6px;">
							<strong><?php echo esc_html( get_the_title( $post_id ) ); ?></strong>
							<?php if ( '' !== $hit['heading_path'] ) : ?>
								<span class="description"> — <?php echo esc_html( $hit['heading_path'] ); ?></span>
							<?php endif; ?>
						</p>
						<p style="margin:0 0 6px;"><?php echo wp_kses( TWTAEO_Content_Index::snippet( $hit['content'], $query ), array( 'mark' => array() ) ); ?></p>
						<p style="margin:0;">
							<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'twt-aeo-ultimate' ); ?></a>
							<?php $edit = get_edit_post_link( $post_id ); ?>
							<?php if ( $edit ) : ?>
								| <a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit page', 'twt-aeo-ultimate' ); ?></a>
							<?php endif; ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── labels and passages ─────────────────────────── */

	/**
	 * A document's label under its name, with a small form to correct it and,
	 * when an AI key is set, a button to have the AI label it.
	 */
	private static function render_label( array $doc ) {
		$sources = array(
			'php'   => __( 'guessed from the text', 'twt-aeo-ultimate' ),
			'ai'    => __( 'labelled by AI', 'twt-aeo-ultimate' ),
			'owner' => __( 'set by you', 'twt-aeo-ultimate' ),
		);
		$line    = TWTAEO_Doc_Profile::summary_line( $doc );
		$quote   = TWTAEO_Doc_Profile::MODE_QUOTE === $doc['answer_mode'];
		?>
		<div style="margin-top:6px;">
			<?php if ( '' !== $line ) : ?>
				<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:<?php echo esc_attr( $quote ? '#fcf0f1' : '#f0f6fc' ); ?>;color:<?php echo esc_attr( $quote ? '#8a2424' : '#135e96' ); ?>;"><?php echo esc_html( $line ); ?></span>
				<?php if ( isset( $sources[ $doc['profile_source'] ] ) ) : ?>
					<span class="description" style="font-size:12px;"><?php echo esc_html( $sources[ $doc['profile_source'] ] ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( 'pending' === $doc['profile_status'] ) : ?>
				<span class="description" style="font-size:12px;"><?php esc_html_e( 'AI is reading the first pages…', 'twt-aeo-ultimate' ); ?></span>
			<?php elseif ( 'failed' === $doc['profile_status'] && '' !== $doc['profile_note'] ) : ?>
				<br /><span class="description" style="font-size:12px;color:#996800;">
					<?php
					/* translators: %s: error message. */
					echo esc_html( sprintf( __( 'AI label not added: %s', 'twt-aeo-ultimate' ), $doc['profile_note'] ) );
					?>
				</span>
			<?php endif; ?>
			<?php if ( '' !== (string) $doc['summary'] ) : ?>
				<br /><span class="description" style="font-size:12px;"><?php echo esc_html( $doc['summary'] ); ?></span>
			<?php endif; ?>
			<?php TWTAEO_Law_Check::render_notice( $doc ); ?>
			<?php if ( in_array( $doc['kind'], TWTAEO_Law_Check::KINDS, true ) && TWTAEO_Law_Check::available() && ! in_array( $doc['law_status'], array( 'pending', 'checking' ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:4px 0 0;">
					<?php wp_nonce_field( 'twtaeo_cge_law_check' ); ?>
					<input type="hidden" name="action" value="twtaeo_cge_law_check" />
					<input type="hidden" name="doc_id" value="<?php echo esc_attr( (int) $doc['id'] ); ?>" />
					<button type="submit" class="button-link" style="font-size:12px;"><?php esc_html_e( 'Check online again', 'twt-aeo-ultimate' ); ?></button>
				</form>
			<?php endif; ?>

			<details style="margin-top:4px;">
				<summary style="cursor:pointer;font-size:12px;color:#2271b1;"><?php esc_html_e( 'Edit label', 'twt-aeo-ultimate' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;max-width:520px;">
					<?php wp_nonce_field( 'twtaeo_cge_label' ); ?>
					<input type="hidden" name="action" value="twtaeo_cge_label" />
					<input type="hidden" name="doc_id" value="<?php echo esc_attr( (int) $doc['id'] ); ?>" />
					<p style="margin:0 0 8px;">
						<label><strong><?php esc_html_e( 'What it is', 'twt-aeo-ultimate' ); ?></strong><br />
							<select name="kind" class="twtaeo-cge-kind">
								<?php foreach ( TWTAEO_Doc_Profile::kinds() as $slug => $kind ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" data-mode="<?php echo esc_attr( $kind['mode'] ); ?>" <?php selected( $doc['kind'], $slug ); ?>><?php echo esc_html( $kind['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<fieldset style="margin:0 0 8px;">
						<strong><?php esc_html_e( 'How passages are used', 'twt-aeo-ultimate' ); ?></strong><br />
						<label><input type="radio" name="answer_mode" value="quote" <?php checked( $quote ); ?> /> <?php esc_html_e( 'Quote exactly — word for word, with its citation (laws, codes, safety data, contracts)', 'twt-aeo-ultimate' ); ?></label><br />
						<label><input type="radio" name="answer_mode" value="explain" <?php checked( ! $quote ); ?> /> <?php esc_html_e( 'Explain from this document — may be put in your own words, credited to the document', 'twt-aeo-ultimate' ); ?></label>
					</fieldset>
					<p style="margin:0 0 8px;">
						<label><strong><?php esc_html_e( 'Applies to (state, county or city)', 'twt-aeo-ultimate' ); ?></strong><br />
							<input type="text" name="jurisdiction" value="<?php echo esc_attr( $doc['jurisdiction'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Orange County, Florida', 'twt-aeo-ultimate' ); ?>" />
						</label>
					</p>
					<p style="margin:0 0 8px;">
						<label><strong><?php esc_html_e( 'Edition or effective date', 'twt-aeo-ultimate' ); ?></strong><br />
							<input type="text" name="edition" value="<?php echo esc_attr( $doc['edition'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 2021 edition, effective July 1, 2025', 'twt-aeo-ultimate' ); ?>" />
						</label>
					</p>
					<p style="margin:0 0 8px;">
						<label><strong><?php esc_html_e( 'Cite it as', 'twt-aeo-ultimate' ); ?></strong><br />
							<input type="text" name="citation" value="<?php echo esc_attr( $doc['citation'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $doc['title'] ); ?>" />
						</label>
						<br /><span class="description"><?php esc_html_e( 'Goes under every passage you copy from this document. Left empty, the document name is used.', 'twt-aeo-ultimate' ); ?></span>
					</p>
					<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Save label', 'twt-aeo-ultimate' ); ?></button>
				</form>
				<?php if ( TWTAEO_Doc_Profile::ai_available() && 'pending' !== $doc['profile_status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
						<?php wp_nonce_field( 'twtaeo_cge_ai_label' ); ?>
						<input type="hidden" name="action" value="twtaeo_cge_ai_label" />
						<input type="hidden" name="doc_id" value="<?php echo esc_attr( (int) $doc['id'] ); ?>" />
						<button type="submit" class="button button-small"><?php 'ai' === $doc['profile_source'] ? esc_html_e( 'Ask AI to label it again', 'twt-aeo-ultimate' ) : esc_html_e( 'Let AI label it', 'twt-aeo-ultimate' ); ?></button>
						<span class="description" style="font-size:12px;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: AI provider. */
									__( 'Sends the first few pages to %s. Replaces the label above.', 'twt-aeo-ultimate' ),
									TWTAEO_Doc_Profile::provider_name()
								)
							);
							?>
						</span>
					</form>
				<?php endif; ?>
			</details>
		</div>
		<?php
	}

	/**
	 * One document passage ready to publish: where it is from, how it may be
	 * used, and a Copy button whose text follows the document's answer mode —
	 * word for word with its citation, or the passage credited to its source.
	 * Shared by the Documents search and the RAG Engine opportunity cards.
	 *
	 * @param string  $dom_id   Unique element ID for the copy source.
	 * @param array   $hit      A Content Index passage.
	 * @param string  $query    What to highlight.
	 * @param array[] $profiles From TWTAEO_Doc_Profile::profiles().
	 * @param array   $titles   Document id => title.
	 * @param string  $edit_url Optional Edit page link, placed beside Copy.
	 */
	public static function render_passage( $dom_id, array $hit, $query, array $profiles, array $titles, $edit_url = '' ) {
		$doc_id  = (int) $hit['source_id'];
		$profile = isset( $profiles[ $doc_id ] ) ? $profiles[ $doc_id ] : null;
		$title   = isset( $titles[ $doc_id ] ) ? $titles[ $doc_id ] : __( 'Document', 'twt-aeo-ultimate' );
		$quote   = $profile && TWTAEO_Doc_Profile::MODE_QUOTE === $profile['answer_mode'];
		$sds     = ( $profile && 'sds' === $profile['kind'] ) || preg_match( '/safety data sheet|\bm?sds\b/i', $title . ' ' . $hit['heading_path'] . ' ' . $hit['content'] );
		?>
		<div style="background:#f6f7f7;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr( $quote ? '#b32d2e' : '#2271b1' ); ?>;border-radius:4px;padding:8px 10px;margin:0 0 8px;">
			<p class="description" style="margin:0 0 4px;">
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php echo '' !== $hit['heading_path'] ? ' — ' . esc_html( $hit['heading_path'] ) : ''; ?>
				<?php if ( $profile && '' !== (string) $profile['kind'] ) : ?>
					<span style="display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;font-size:11px;background:<?php echo esc_attr( $quote ? '#fcf0f1' : '#f0f6fc' ); ?>;color:<?php echo esc_attr( $quote ? '#8a2424' : '#135e96' ); ?>;"><?php echo esc_html( TWTAEO_Doc_Profile::mode_label( $profile['answer_mode'] ) ); ?></span>
				<?php endif; ?>
			</p>
			<?php
			if ( $profile ) {
				TWTAEO_Law_Check::render_notice( $profile, true );
			}
			?>
			<?php $table = TWTAEO_Doc_Profile::has_table( $hit ); ?>
			<?php
			static $styled = false;
			if ( $table && ! $styled ) :
				$styled = true;
				?>
				<style>.twtaeo-table-preview table{border-collapse:collapse;width:100%;font-size:12px}.twtaeo-table-preview th,.twtaeo-table-preview td{border:1px solid #dcdcde;padding:3px 6px;text-align:left;vertical-align:top}.twtaeo-table-preview th{background:#f0f0f1;position:sticky;top:0}</style>
			<?php endif; ?>
			<?php if ( $table ) : ?>
				<div class="twtaeo-table-preview" style="max-height:260px;overflow:auto;margin:0 0 6px;background:#fff;border:1px solid #dcdcde;">
					<?php echo wp_kses( TWTAEO_Doc_Profile::table_preview( $hit, $query ), self::table_tags() ); ?>
				</div>
			<?php else : ?>
				<p style="margin:0 0 6px;"><?php echo wp_kses( TWTAEO_Content_Index::snippet( $hit['content'], $query, 420 ), array( 'mark' => array() ) ); ?></p>
			<?php endif; ?>
			<?php if ( $sds ) : ?>
				<p class="description" style="margin:0 0 6px;color:#996800;"><?php esc_html_e( 'Safety data: publish hazard, first-aid and physical values exactly as printed, and link to the SDS itself — reworded safety information can end up wrong.', 'twt-aeo-ultimate' ); ?></p>
			<?php elseif ( $quote ) : ?>
				<p class="description" style="margin:0 0 6px;color:#8a2424;"><?php esc_html_e( 'Publish this word for word, with the citation the Copy button adds. Rewording it changes what it says.', 'twt-aeo-ultimate' ); ?></p>
			<?php endif; ?>
			<textarea id="<?php echo esc_attr( $dom_id ); ?>" readonly hidden><?php echo esc_textarea( TWTAEO_Doc_Profile::copy_text( $hit, $profile, $title ) ); ?></textarea>
			<?php if ( $table ) : ?>
				<?php // The table as HTML: pasted into the editor it arrives as a table block. ?>
				<div id="<?php echo esc_attr( $dom_id . '-html' ); ?>" hidden><?php echo wp_kses( TWTAEO_Doc_Profile::copy_html( $hit, $profile, $title ), self::table_tags() ); ?></div>
			<?php endif; ?>
			<button type="button" class="button button-primary button-small twtaeo-copy" data-copy="<?php echo esc_attr( $dom_id ); ?>"<?php echo $table ? ' data-copy-html="' . esc_attr( $dom_id . '-html' ) . '"' : ''; ?>>
				<?php
				if ( $table ) {
					esc_html_e( '1. Copy table + source', 'twt-aeo-ultimate' );
				} elseif ( $quote ) {
					esc_html_e( '1. Copy exact text + citation', 'twt-aeo-ultimate' );
				} else {
					esc_html_e( '1. Copy passage + source', 'twt-aeo-ultimate' );
				}
				?>
			</button>
			<?php if ( '' !== $edit_url ) : ?>
				<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '2. Edit page and paste →', 'twt-aeo-ultimate' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}


	/** Tags a rebuilt table's preview and copy may contain. */
	private static function table_tags() {
		return array(
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(),
			'td'         => array(),
			'p'          => array( 'class' => true, 'style' => true ),
			'em'         => array(),
			'br'         => array(),
			'mark'       => array(),
			'blockquote' => array(),
		);
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/** Turn PHP's multi-file $_FILES shape into one array per file. */
	private static function normalize_files( $files ) {
		$out = array();
		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return $out;
		}
		if ( ! is_array( $files['name'] ) ) {
			return array( $files );
		}
		foreach ( array_keys( $files['name'] ) as $i ) {
			if ( '' === (string) $files['name'][ $i ] ) {
				continue;
			}
			$out[] = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
		}

		return $out;
	}

	private static function notice( $type, $message, array $details = array() ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			),
			120
		);
	}

	private static function render_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
			<?php if ( ! empty( $notice['details'] ) ) : ?>
				<ul style="list-style:disc;margin-left:20px;">
					<?php foreach ( $notice['details'] as $detail ) : ?>
						<li><?php echo esc_html( $detail ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function back() {
		wp_safe_redirect( TWTAEO_Page_RAG_Engine::url( 'documents' ) );
		exit;
	}
}
