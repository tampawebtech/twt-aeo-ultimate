<?php
/**
 * Law Check — has a law or code changed since the owner's copy was printed?
 *
 * The uploaded document is always the answer. This only warns when it may be
 * out of date, and always gives a link to check it:
 *
 *  1. A grounded web search (the site's retrieval provider: Gemini with
 *     Google Search, or Perplexity) asks whether the law or code named by the
 *     document's citation was amended, repealed or replaced after the
 *     document's date. It reports THAT something changed — the amending act or
 *     the newer edition — never what the law now says.
 *  2. When the search cannot be run or gives no clear answer, the document's
 *     own date decides: over a year old, the owner is told to check it.
 *
 * Only the citation, place, date and section headings go out — public law —
 * never the document's text. The official link the search names is loaded
 * here before it is shown, so a made-up address is never offered; without
 * one, the owner gets a web-search link for the citation.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Law_Check {

	/** A document older than this is flagged when no search can say more. */
	const STALE_AFTER_DAYS = 365;

	/** Checks are repeated this often: laws keep changing after the first check. */
	const RECHECK_AFTER_DAYS = 30;

	/** A check still "checking" after this long died with its request. */
	const STUCK_AFTER = 600;

	/** Kinds this applies to. */
	const KINDS = array( 'law', 'code' );

	private function __construct() {}

	/* ─────────────────────────── the document's date ─────────────────────────── */

	/**
	 * The date in an edition line, as precise as it is printed:
	 * "2021 edition" → "2021", "revised March 2024" → "2024-03",
	 * "effective July 1, 2025" → "2025-07-01". Empty when there is none.
	 *
	 * @param string $edition
	 * @return string
	 */
	public static function date_of( $edition ) {
		$text = str_replace( '.', '', (string) $edition );
		$year = '(?:19|20)\d{2}';

		if ( preg_match( '/\b([A-Z][a-z]{2,8}) (\d{1,2}),? (' . $year . ')\b/', $text, $m ) || preg_match( '/\b(\d{1,2})\/(\d{1,2})\/(' . $year . '|\d{2})\b/', $text, $m ) ) {
			$time = strtotime( $m[0] );
			if ( $time ) {
				return gmdate( 'Y-m-d', $time );
			}
		}
		if ( preg_match( '/\b([A-Z][a-z]{2,8}) (' . $year . ')\b/', $text, $m ) ) {
			$time = strtotime( '1 ' . $m[1] . ' ' . $m[2] );
			if ( $time ) {
				return gmdate( 'Y-m', $time );
			}
		}
		if ( preg_match( '/\b(' . $year . ')\b/', $text, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/** A stored date as a reader sees it: "2021", "March 2024", "July 1, 2025". */
	public static function format_date( $date ) {
		$date = (string) $date;
		if ( 4 === strlen( $date ) ) {
			return $date;
		}
		if ( 7 === strlen( $date ) ) {
			return date_i18n( 'F Y', strtotime( $date . '-01' ) );
		}

		return '' !== $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '';
	}

	/** Days since the document's date, counted from the end of what is printed (a "2021 edition" is 2021 throughout). */
	public static function age_days( $date ) {
		$date = (string) $date;
		if ( 4 === strlen( $date ) ) {
			$end = strtotime( $date . '-12-31' );
		} elseif ( 7 === strlen( $date ) ) {
			$end = strtotime( gmdate( 'Y-m-t', strtotime( $date . '-01' ) ) );
		} else {
			$end = strtotime( $date );
		}

		return $end ? (int) floor( ( time() - $end ) / DAY_IN_SECONDS ) : null;
	}

	/* ─────────────────────────── when to check ─────────────────────────── */

	/** Whether a grounded search can be run: the retrieval provider has a key. */
	public static function available() {
		return '' !== TWTAEO_Key_Resolver::get( TWTAEO_AI_Client::retrieval_provider() );
	}

	public static function provider_name() {
		return 'perplexity' === TWTAEO_AI_Client::retrieval_provider() ? 'Perplexity' : 'Google (Gemini with Google Search)';
	}

	/**
	 * After a document is (re)labelled: a law or code is queued for a check,
	 * anything else loses any check it had.
	 */
	public static function after_label( $doc_id ) {
		$doc = self::row( $doc_id );
		if ( ! $doc ) {
			return;
		}
		if ( ! in_array( $doc['kind'], self::KINDS, true ) ) {
			if ( '' !== $doc['law_status'] ) {
				self::update(
					$doc_id,
					array(
						'law_status' => '',
						'law_note'   => '',
						'law_url'    => '',
					)
				);
			}
			return;
		}
		if ( self::available() ) {
			self::update( $doc_id, array( 'law_status' => 'pending' ) );
			TWTAEO_Doc_Library::schedule();
		}
	}

	/** The owner's "Check again". */
	public static function request( $doc_id ) {
		self::update( $doc_id, array( 'law_status' => 'pending' ) );
		TWTAEO_Doc_Library::schedule();
	}

	/**
	 * Queue checks that are due again, and fail those whose request died.
	 * Cheap: called when the Documents screen loads.
	 */
	public static function maintain() {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		$stuck = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET law_status = 'failed', law_note = %s WHERE law_status = 'checking' AND law_checked_at < %s", __( 'The online check took too long and was stopped.', 'twt-aeo-ultimate' ), $stuck ) );

		if ( ! self::available() ) {
			return;
		}
		$due = gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_AFTER_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$queued = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET law_status = 'pending' WHERE status = 'ready' AND kind IN ('law','code') AND ( law_status = '' OR ( law_status IN ('current','changed','unsure','failed') AND law_checked_at < %s ) )", $due ) );
		if ( $queued ) {
			TWTAEO_Doc_Library::schedule();
		}
	}

	/**
	 * Run one waiting check. Called from the document tick, one per call:
	 * each is a web search.
	 *
	 * @return bool Whether a document was handled.
	 */
	public static function process_pending() {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$id = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE law_status = 'pending' AND status = 'ready' ORDER BY id ASC LIMIT 1" );
		if ( ! $id ) {
			return false;
		}
		if ( ! self::available() ) {
			self::update( $id, array( 'law_status' => '' ) );
			return true;
		}

		// Marked first: if the host kills the request, maintain() finds it.
		self::update(
			$id,
			array(
				'law_status'     => 'checking',
				'law_checked_at' => current_time( 'mysql', true ),
			)
		);

		$result = self::search( $id );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::warning( 'Law check failed: ' . $result->get_error_message(), array( 'doc' => $id ) );
			}
			$result = array(
				'law_status' => 'failed',
				'law_note'   => self::clip( $result->get_error_message(), 500 ),
				'law_url'    => '',
			);
		}
		$result['law_checked_at'] = current_time( 'mysql', true );
		self::update( $id, $result );

		return true;
	}

	/* ─────────────────────────── the grounded search ─────────────────────────── */

	/**
	 * Ask the retrieval provider, with live web search, whether the law changed.
	 *
	 * @return array|WP_Error Fields for the documents table.
	 */
	private static function search( $doc_id ) {
		$doc = self::row( $doc_id );
		if ( ! $doc ) {
			return new WP_Error( 'twtaeo_doc_missing', __( 'Document not found.', 'twt-aeo-ultimate' ) );
		}
		$name = '' !== $doc['citation'] ? $doc['citation'] : $doc['title'];
		$when = '' !== $doc['doc_date']
			? 'The owner\'s copy is dated ' . self::format_date( $doc['doc_date'] ) . ( '' !== $doc['edition'] ? ' (' . $doc['edition'] . ')' : '' ) . '.'
			: 'The owner\'s copy has no date printed on it.';

		$prompt = "Search the web for the current official status of this law or code.\n\n"
			. 'Law or code: ' . $name . "\n"
			. ( '' !== $doc['jurisdiction'] ? 'Applies to: ' . $doc['jurisdiction'] . "\n" : '' )
			. ( $doc['sections'] ? 'Sections in the owner\'s copy: ' . implode( '; ', $doc['sections'] ) . "\n" : '' )
			. $when . "\n\n"
			. 'Has it (or any of those sections) been amended, repealed, renumbered, or replaced by a newer edition AFTER that date? '
			. "Report only whether and when it changed and what changed it (the bill, act or new edition). Do not state, summarize or explain what the law says.\n\n"
			. "Reply with JSON only:\n"
			. '{"status": "changed" | "current" | "unsure", '
			. '"changed_on": "the date the change took effect, as published, or empty", '
			. '"changed_by": "the bill, act, chapter law or edition that changed it, under 20 words, or empty", '
			. '"latest_version": "the date or edition of the current version, or empty", '
			. '"official_url": "the official government or code publisher page for this law or code, or empty"}' . "\n"
			. 'Use "unsure" if you cannot find clear evidence either way. If the owner\'s copy has no date, use "unsure" and fill in latest_version.';

		$raw = TWTAEO_AI_Client::complete(
			TWTAEO_AI_Client::retrieval_provider(),
			$prompt,
			array(
				'grounding'   => true,
				'max_tokens'  => 700,
				'temperature' => 0,
				'timeout'     => 40,
				'system'      => 'You check whether laws and building codes have been changed, using live web search. Rely only on sources you find, preferring official government and code-publisher sites. Never guess, never invent URLs, and never say what a law requires.',
			)
		);
		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'unsure';
		if ( ! in_array( $status, array( 'changed', 'current', 'unsure' ), true ) ) {
			$status = 'unsure';
		}
		// With no date on the copy there is nothing to be "current" against.
		if ( '' === $doc['doc_date'] && 'current' === $status ) {
			$status = 'unsure';
		}

		$note = wp_json_encode(
			array(
				'changed_on' => self::clip( isset( $data['changed_on'] ) ? $data['changed_on'] : '', 60 ),
				'changed_by' => self::clip( isset( $data['changed_by'] ) ? $data['changed_by'] : '', 160 ),
				'latest'     => self::clip( isset( $data['latest_version'] ) ? $data['latest_version'] : '', 80 ),
			)
		);

		return array(
			'law_status' => $status,
			'law_note'   => $note,
			'law_url'    => self::verified_url( isset( $data['official_url'] ) ? (string) $data['official_url'] : '' ),
		);
	}

	/** A link only if it is a real web address that loads. */
	private static function verified_url( $url ) {
		$url = esc_url_raw( trim( $url ), array( 'https', 'http' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 3,
				'limit_response_size' => 65536,
				'user-agent'          => 'Mozilla/5.0 (compatible; TWT-AEO-Ultimate; +' . home_url() . ')',
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// 403 and 405: many government sites turn away automated requests
		// but the page is there for a person.
		return ( $code >= 200 && $code < 400 ) || in_array( $code, array( 403, 405 ), true ) ? substr( $url, 0, 500 ) : '';
	}

	/* ─────────────────────────── what the owner sees ─────────────────────────── */

	/**
	 * The notice for a law or code document: whether it may be out of date,
	 * and a link to check it. Null for other documents.
	 *
	 * @param array $doc A documents-table row with the law_* and doc_date fields.
	 * @return array|null { level: ok|warn|info, text, link_url, link_label }
	 */
	public static function notice( array $doc ) {
		if ( ! in_array( $doc['kind'], self::KINDS, true ) ) {
			return null;
		}

		$date   = self::format_date( $doc['doc_date'] );
		$note   = json_decode( (string) $doc['law_note'], true );
		$note   = is_array( $note ) ? $note : array();
		$url    = (string) $doc['law_url'];
		$search = self::search_link( $doc );
		$link   = '' !== $url ? $url : $search;
		$label  = '' !== $url ? __( 'Check the official source', 'twt-aeo-ultimate' ) : __( 'Search for the current version', 'twt-aeo-ultimate' );
		$status = (string) $doc['law_status'];

		if ( in_array( $status, array( 'pending', 'checking' ), true ) ) {
			return array(
				'level'      => 'info',
				'text'       => __( 'Checking online whether this law or code has changed since your copy…', 'twt-aeo-ultimate' ),
				'link_url'   => $search,
				'link_label' => $label,
			);
		}

		if ( 'changed' === $status ) {
			$what = array_filter( array( isset( $note['changed_by'] ) ? $note['changed_by'] : '', ! empty( $note['changed_on'] ) ? sprintf( /* translators: %s: date as printed in the document. */ __( 'effective %s', 'twt-aeo-ultimate' ), $note['changed_on'] ) : '' ) );
			return array(
				'level'      => 'warn',
				'text'       => ( '' !== $date
					/* translators: %s: the document's date. */
					? sprintf( __( 'It looks like this law or code has changed since your copy (%s).', 'twt-aeo-ultimate' ), $date )
					: __( 'It looks like this law or code has changed since your copy was published.', 'twt-aeo-ultimate' ) )
					. ( $what ? ' ' . sprintf( /* translators: %s: amending act and date. */ __( 'Changed by: %s.', 'twt-aeo-ultimate' ), implode( ', ', $what ) ) : '' )
					. ' ' . __( 'The passages below are from your copy — check them against the current version before publishing.', 'twt-aeo-ultimate' ),
				'link_url'   => $link,
				'link_label' => $label,
			);
		}

		if ( 'current' === $status ) {
			return array(
				'level'      => 'ok',
				'text'       => sprintf(
					/* translators: 1: the document's date, 2: time since the check. */
					__( 'No change found since your copy (%1$s). Checked online %2$s ago.', 'twt-aeo-ultimate' ),
					$date,
					human_time_diff( strtotime( $doc['law_checked_at'] . ' UTC' ), time() )
				),
				'link_url'   => $link,
				'link_label' => $label,
			);
		}

		// No usable search result: fall back to the document's own date.
		$why = '';
		if ( 'unsure' === $status ) {
			$why = ! empty( $note['latest'] )
				/* translators: %s: date or edition of the current version. */
				? sprintf( __( 'The online check could not confirm it; the current version appears to be dated %s.', 'twt-aeo-ultimate' ), $note['latest'] )
				: __( 'The online check could not confirm whether it has changed.', 'twt-aeo-ultimate' );
		} elseif ( 'failed' === $status ) {
			$why = __( 'The online check did not work this time.', 'twt-aeo-ultimate' );
		} elseif ( ! self::available() ) {
			$why = __( 'Add a Gemini or Perplexity key under Settings to have laws and codes checked online automatically.', 'twt-aeo-ultimate' );
		}

		$age = '' !== (string) $doc['doc_date'] ? self::age_days( $doc['doc_date'] ) : null;
		if ( null === $age ) {
			$text  = __( 'No date was found in this document, so there is no telling how current it is. Add its edition or effective date under Edit label, and check it against the current version before publishing.', 'twt-aeo-ultimate' );
			$level = 'warn';
		} elseif ( $age > self::STALE_AFTER_DAYS ) {
			/* translators: %s: the document's date. */
			$text  = sprintf( __( 'Your copy is dated %s — over a year ago. Laws and codes change; this may not be the current version. Check it before publishing.', 'twt-aeo-ultimate' ), $date );
			$level = 'warn';
		} else {
			/* translators: %s: the document's date. */
			$text  = sprintf( __( 'Your copy is dated %s, within the last year. Laws can still change at any time — check it before publishing.', 'twt-aeo-ultimate' ), $date );
			$level = 'info';
		}

		return array(
			'level'      => $level,
			'text'       => trim( $text . ' ' . $why ),
			'link_url'   => $link,
			'link_label' => $label,
		);
	}

	/** A web search for the law's current version, for when no official page was found. */
	public static function search_link( array $doc ) {
		$name = '' !== (string) $doc['citation'] ? $doc['citation'] : $doc['title'];
		$place = '' !== (string) $doc['jurisdiction'] && false === stripos( $name, (string) $doc['jurisdiction'] ) ? ' ' . $doc['jurisdiction'] : '';
		$q     = $name . $place . ' current version';

		return 'https://www.google.com/search?q=' . rawurlencode( $q );
	}

	/** Echo a notice as a small bordered box. */
	public static function render_notice( array $doc, $compact = false ) {
		$n = self::notice( $doc );
		if ( ! $n ) {
			return;
		}
		$colors = array(
			'ok'   => array( '#00a32a', '#edfaef' ),
			'warn' => array( '#dba617', '#fcf9e8' ),
			'info' => array( '#72aee6', '#f0f6fc' ),
		);
		$c      = $colors[ $n['level'] ];
		?>
		<div style="margin:<?php echo $compact ? '0 0 6px' : '6px 0 0'; ?>;padding:6px 10px;border-left:3px solid <?php echo esc_attr( $c[0] ); ?>;background:<?php echo esc_attr( $c[1] ); ?>;font-size:12px;max-width:760px;">
			<?php echo esc_html( ( 'warn' === $n['level'] ? '⚠ ' : ( 'ok' === $n['level'] ? '✓ ' : '' ) ) . $n['text'] ); ?>
			<a href="<?php echo esc_url( $n['link_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $n['link_label'] ); ?> ↗</a>
		</div>
		<?php
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	private static function row( $doc_id ) {
		global $wpdb;
		$docs  = TWTAEO_Content_Index::docs_table();
		$table = TWTAEO_Content_Index::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, kind, jurisdiction, edition, citation, doc_date, law_status FROM {$docs} WHERE id = %d", (int) $doc_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}

		// Section headings (public law) make the search specific. Not the text.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$paths    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT heading_path FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY chunk_index ASC LIMIT 60", TWTAEO_Content_Index::SOURCE_DOC, (int) $doc_id ) );
		$sections = array();
		foreach ( (array) $paths as $path ) {
			$parts = array_map( 'trim', explode( '›', (string) $path ) );
			$last  = end( $parts );
			if ( '' !== $last && ! preg_match( '/^Page \d+$/', $last ) && ! in_array( $last, $sections, true ) ) {
				$sections[] = self::clip( $last, 80 );
			}
		}
		$row['sections'] = array_slice( $sections, 0, 12 );

		return $row;
	}

	private static function update( $doc_id, array $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->update( TWTAEO_Content_Index::docs_table(), $fields, array( 'id' => (int) $doc_id ) );
	}

	private static function clip( $value, $max ) {
		$value = sanitize_text_field( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
