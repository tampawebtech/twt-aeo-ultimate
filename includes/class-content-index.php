<?php
/**
 * Content Index — the passage store behind the Content Gap Engine.
 *
 * One table of passages from two sources: uploaded documents ("doc") and the
 * site's own posts and pages ("post"). Keeping both in one table is the point:
 * a gap is a question one side answers and the other does not, and that is a
 * single query when they sit side by side.
 *
 * Search is keyword-only here (FULLTEXT where the database supports it, a LIKE
 * scan where it does not). The `embedding` column is reserved for the vector
 * rerank in the next phase, so adding it will not need a table rebuild.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Content_Index {

	/** Bump when the schema changes; dbDelta brings existing tables up to date. */
	const DB_VERSION = 3;

	const OPTION_DB_VERSION = 'twtaeo_cge_db_version';

	/** Passage sources. */
	const SOURCE_DOC  = 'doc';
	const SOURCE_POST = 'post';

	/** Rows per multi-row INSERT — well under max_allowed_packet on any host. */
	const INSERT_BATCH = 25;

	/** Longest search query accepted. */
	const MAX_QUERY_CHARS = 200;

	/** Words too common to decide a match on their own. */
	const STOPWORDS = array(
		'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'for', 'by', 'with', 'from', 'as', 'is', 'are', 'was', 'be',
		'it', 'its', 'this', 'that', 'these', 'those', 'my', 'your', 'our', 'i', 'we', 'you', 'me', 'do', 'does', 'did', 'can',
		'should', 'would', 'will', 'how', 'what', 'which', 'who', 'when', 'where', 'why', 'best', 'near', 'vs', 'about', 'there',
	);

	private function __construct() {}

	public static function docs_table() {
		global $wpdb;
		return $wpdb->prefix . 'twtaeo_cge_docs';
	}

	public static function chunks_table() {
		global $wpdb;
		return $wpdb->prefix . 'twtaeo_cge_chunks';
	}

	/* ─────────────────────────── schema ─────────────────────────── */

	/**
	 * Create or upgrade the tables when the stored version is behind.
	 * Cheap to call: one autoloaded-option read when nothing is due.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$docs     = self::docs_table();
		$chunks   = self::chunks_table();
		$profile  = TWTAEO_Host_Profile::get();
		$fulltext = ! empty( $profile['has_fulltext'] ) ? ",\n  FULLTEXT KEY ft_text (heading_path,content)" : '';

		dbDelta(
			"CREATE TABLE {$docs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  filename varchar(255) NOT NULL DEFAULT '',
  ext varchar(10) NOT NULL DEFAULT '',
  bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  pages int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'queued',
  error text NULL,
  stored_path varchar(255) NOT NULL DEFAULT '',
  markdown longtext NULL,
  chunk_count int(10) unsigned NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT '',
  answer_mode varchar(10) NOT NULL DEFAULT '',
  jurisdiction varchar(120) NOT NULL DEFAULT '',
  edition varchar(120) NOT NULL DEFAULT '',
  citation varchar(255) NOT NULL DEFAULT '',
  summary varchar(500) NOT NULL DEFAULT '',
  profile_source varchar(10) NOT NULL DEFAULT '',
  profile_status varchar(10) NOT NULL DEFAULT '',
  profile_note varchar(255) NOT NULL DEFAULT '',
  ai_ok tinyint(1) unsigned NOT NULL DEFAULT 0,
  doc_date varchar(10) NOT NULL DEFAULT '',
  law_status varchar(10) NOT NULL DEFAULT '',
  law_note varchar(500) NOT NULL DEFAULT '',
  law_url varchar(500) NOT NULL DEFAULT '',
  law_checked_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$chunks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_type varchar(10) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  chunk_index int(10) unsigned NOT NULL DEFAULT 0,
  heading_path varchar(500) NOT NULL DEFAULT '',
  content text NOT NULL,
  tokens int(10) unsigned NOT NULL DEFAULT 0,
  content_hash char(32) NOT NULL DEFAULT '',
  embedding mediumblob NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY source (source_type,source_id){$fulltext}
) {$charset};"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/** Whether the chunks table carries the FULLTEXT index. */
	public static function has_fulltext_index() {
		static $has = null;
		if ( null !== $has ) {
			return $has;
		}
		global $wpdb;
		$table = self::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$has = (bool) $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'ft_text'" );

		return $has;
	}

	/* ─────────────────────────── writes ─────────────────────────── */

	/**
	 * Replace every passage for one source.
	 *
	 * @param string  $type   SOURCE_DOC | SOURCE_POST.
	 * @param int     $id     Document or post ID.
	 * @param array[] $chunks From TWTAEO_Chunker::chunk().
	 * @return int Passages stored.
	 */
	public static function replace_source( $type, $id, array $chunks ) {
		self::delete_source( $type, $id );

		return self::append_chunks( $type, $id, $chunks );
	}

	/**
	 * Add passages for a source without removing any — for documents indexed
	 * a batch at a time across several ticks.
	 *
	 * @param string  $type
	 * @param int     $id
	 * @param array[] $chunks From TWTAEO_Chunker::chunk().
	 * @return int Passages stored.
	 */
	public static function append_chunks( $type, $id, array $chunks ) {
		global $wpdb;

		$table  = self::chunks_table();
		$now    = current_time( 'mysql', true );
		$stored = 0;

		foreach ( array_chunk( $chunks, self::INSERT_BATCH ) as $batch ) {
			$rows   = array();
			$values = array();
			foreach ( $batch as $chunk ) {
				$content = trim( (string) $chunk['content'] );
				if ( '' === $content ) {
					continue;
				}
				$heading  = function_exists( 'mb_substr' ) ? mb_substr( (string) $chunk['heading_path'], 0, 500 ) : substr( (string) $chunk['heading_path'], 0, 500 );
				$rows[]   = '(%s, %d, %d, %s, %s, %d, %s, %s)';
				$values[] = $type;
				$values[] = (int) $id;
				$values[] = (int) $chunk['index'];
				$values[] = $heading;
				$values[] = $content;
				$values[] = (int) $chunk['tokens'];
				$values[] = md5( $content );
				$values[] = $now;
			}
			if ( empty( $rows ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one placeholder group per row, built above.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source_type, source_id, chunk_index, heading_path, content, tokens, content_hash, created_at) VALUES " . implode( ',', $rows ), $values ) );
			$stored += count( $rows );
		}

		return $stored;
	}

	/** Remove every passage for one source. */
	public static function delete_source( $type, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->delete(
			self::chunks_table(),
			array(
				'source_type' => $type,
				'source_id'   => (int) $id,
			),
			array( '%s', '%d' )
		);
	}

	/** Remove every passage of one source type. */
	public static function delete_type( $type ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->delete( self::chunks_table(), array( 'source_type' => $type ), array( '%s' ) );
	}

	/* ─────────────────────────── reads ─────────────────────────── */

	/**
	 * Passage and source counts per type.
	 *
	 * @return array { doc: { sources, passages }, post: { sources, passages } }
	 */
	public static function counts() {
		global $wpdb;
		$table = self::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$rows = $wpdb->get_results( "SELECT source_type, COUNT(DISTINCT source_id) AS sources, COUNT(*) AS passages FROM {$table} GROUP BY source_type", ARRAY_A );

		$out = array(
			self::SOURCE_DOC  => array( 'sources' => 0, 'passages' => 0 ),
			self::SOURCE_POST => array( 'sources' => 0, 'passages' => 0 ),
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['source_type'] ] ) ) {
				$out[ $row['source_type'] ] = array(
					'sources'  => (int) $row['sources'],
					'passages' => (int) $row['passages'],
				);
			}
		}

		return $out;
	}

	/**
	 * Keyword search over one source type.
	 *
	 * FULLTEXT natural-language mode when the index exists; otherwise, or when
	 * it finds nothing (InnoDB ignores words under three letters and its
	 * stopwords), a LIKE scan requiring every term. Results are ranked by how
	 * often the terms occur, with a boost for terms in the heading.
	 *
	 * @param string $query
	 * @param string $type      SOURCE_DOC | SOURCE_POST.
	 * @param int    $limit
	 * @param int    $source_id Optional: only this document or post.
	 * @return array[] id, source_id, chunk_index, heading_path, content, score, matched (share of terms found, 0–1).
	 */
	public static function search( $query, $type, $limit = 20, $source_id = 0 ) {
		global $wpdb;

		$query = self::clean_query( $query );
		$terms = self::terms( $query );
		if ( empty( $terms ) ) {
			return array();
		}

		$table     = self::chunks_table();
		$limit     = max( 1, min( 100, (int) $limit ) );
		$rows      = array();
		$source_id = absint( $source_id );
		$only      = $source_id ? $wpdb->prepare( ' AND source_id = %d', $source_id ) : '';

		if ( self::has_fulltext_index() ) {
			// The database splits "ES-989" into "es" and "989", then ranks by
			// whatever common word is left ("bearings"). Adding the joined
			// form ("es989") and the bare number gives the rare, specific
			// token the weight it deserves, whichever way the text spells it.
			$ft_query = $query . ' ' . implode( ' ', $terms );
			foreach ( $terms as $term ) {
				if ( preg_match( '/[a-z]/', $term ) && preg_match( '/(\d{3,})/', $term, $digits ) ) {
					$ft_query .= ' ' . $digits[1];
				}
			}
			// Multi-line statement: a one-line ignore would cover only its first line.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours; $only was prepared above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, source_id, chunk_index, heading_path, content,
						MATCH(heading_path, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS ft_score
					FROM {$table}
					WHERE source_type = %s AND MATCH(heading_path, content) AGAINST (%s IN NATURAL LANGUAGE MODE){$only}
					ORDER BY ft_score DESC
					LIMIT %d",
					$ft_query,
					$type,
					$ft_query,
					$limit * 3
				),
				ARRAY_A
			);
			// phpcs:enable
		}

		if ( empty( $rows ) ) {
			$where  = array();
			$values = array( $type );
			foreach ( $terms as $term ) {
				$like     = '%' . $wpdb->esc_like( $term ) . '%';
				$where[]  = '(content LIKE %s OR heading_path LIKE %s)';
				$values[] = $like;
				$values[] = $like;
			}
			$values[] = $limit * 3;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- two placeholders per term, built above with their values.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_id, chunk_index, heading_path, content FROM {$table} WHERE source_type = %s{$only} AND " . implode( ' AND ', $where ) . ' LIMIT %d', $values ), ARRAY_A );
		}

		foreach ( $rows as &$row ) {
			$row['score']   = self::score( $row, $terms );
			$row['matched'] = self::matched_share( $row, $terms );
		}
		unset( $row );

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * A window of the passage around the first matching term, HTML-escaped,
	 * with every term wrapped in <mark>.
	 *
	 * @param string $content
	 * @param string $query
	 * @param int    $width Characters of context.
	 * @return string Safe HTML.
	 */
	public static function snippet( $content, $query, $width = 320 ) {
		$content = (string) $content;
		$terms   = self::terms( self::clean_query( $query ) );

		$first = false;
		foreach ( $terms as $term ) {
			$pos = stripos( $content, $term );
			if ( false !== $pos && ( false === $first || $pos < $first ) ) {
				$first = $pos;
			}
		}

		$start = false === $first ? 0 : max( 0, $first - (int) ( $width / 3 ) );
		// Start on a word boundary.
		if ( $start > 0 ) {
			$space = strpos( $content, ' ', $start );
			$start = false === $space ? $start : $space + 1;
		}
		$excerpt = function_exists( 'mb_strcut' ) ? mb_strcut( $content, $start, $width, 'UTF-8' ) : substr( $content, $start, $width );
		$prefix  = $start > 0 ? '… ' : '';
		$suffix  = strlen( $content ) > $start + strlen( $excerpt ) ? ' …' : '';

		$html = esc_html( $prefix . $excerpt . $suffix );
		foreach ( $terms as $term ) {
			$html = preg_replace( '/(' . preg_quote( esc_html( $term ), '/' ) . ')/i', '<mark>$1</mark>', $html );
		}

		return (string) $html;
	}

	/* ─────────────────────────── internals ─────────────────────────── */

	private static function clean_query( $query ) {
		$query = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $query ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, self::MAX_QUERY_CHARS ) : substr( $query, 0, self::MAX_QUERY_CHARS );
	}

	/**
	 * Join the spellings of a model number: "ES 989", "ES-989" and "ES_989"
	 * all become "ES989", in queries and in the text they are matched against.
	 * Only short letter prefixes followed by three or more digits are joined,
	 * so "at 10,000 rpm" or "page 2" are left alone.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function join_model_numbers( $text ) {
		return (string) preg_replace_callback(
			// Not before ".8": "NEC 210.8" is a code section, not model NEC210.
			'/\b([a-z]{1,4})[\s\-_]+(\d{3,}[a-z]{0,3})\b(?!\.\d)/i',
			static function ( $m ) {
				return in_array( strtolower( $m[1] ), self::STOPWORDS, true ) ? $m[0] : $m[1] . $m[2];
			},
			(string) $text
		);
	}

	/**
	 * The terms a query is matched on, for callers that need to size a
	 * threshold by how many there are.
	 *
	 * @param string $query
	 * @return string[]
	 */
	public static function query_terms( $query ) {
		return self::terms( self::clean_query( $query ) );
	}

	/**
	 * Search terms: words of two or more characters, stripped of FULLTEXT
	 * operators and common words, lightly stemmed, at most eight.
	 *
	 * Terms are matched as substrings, so stemming "bearings" to "bearing"
	 * and "regreased" to "regreas" lets one term match every form of the word.
	 *
	 * @return string[]
	 */
	private static function terms( $query ) {
		$words = preg_split( '/[^\p{L}\p{N}\-\.]+/u', strtolower( self::join_model_numbers( (string) $query ) ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		$stop  = array();
		foreach ( (array) $words as $word ) {
			$word = trim( $word, '-.' );
			if ( preg_match( '/\d/', $word ) && preg_match( '/[a-z]/', $word ) ) {
				$word = str_replace( '-', '', $word );
			}
			if ( strlen( $word ) < 2 || in_array( $word, $out, true ) ) {
				continue;
			}
			// Search Console queries and AI Visibility questions are full
			// sentences; "how", "the" and "for" would decide whether a page
			// "covers" them. Kept aside in case they are all there is.
			if ( in_array( $word, self::STOPWORDS, true ) ) {
				$stop[] = $word;
				continue;
			}
			$word = self::stem( $word );
			if ( ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
		}

		return array_slice( $out ? $out : $stop, 0, 8 );
	}

	/**
	 * Strip the commonest English endings so a substring match catches the
	 * other forms of a word. Deliberately crude: short words and numbers are
	 * left alone, and nothing is stemmed below four characters.
	 */
	private static function stem( $word ) {
		// "ss" is not a plural: wordpress, across, process.
		if ( strlen( $word ) < 5 || preg_match( '/\d/', $word ) || 'ss' === substr( $word, -2 ) ) {
			return $word;
		}
		// No "-ing": in parts and service language it is usually a noun
		// (bearing, housing, coupling), and "bear" would match "beard".
		foreach ( array( 'ies', 'ed', 'es', 's' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( substr( $word, -$len ) === $suffix && strlen( $word ) - $len >= 4 ) {
				return 'ies' === $suffix ? substr( $word, 0, -$len ) . 'y' : substr( $word, 0, -$len );
			}
		}

		return $word;
	}

	/**
	 * Text as terms are matched against it: model numbers joined, and each
	 * hyphenated model number also present without its hyphen ("i-450H"
	 * matches the term "i450h").
	 */
	private static function match_text( $text ) {
		$text = self::join_model_numbers( $text );

		return $text . ' ' . preg_replace( '/\b([a-z0-9]+)-([a-z0-9]+)\b/i', '$1$2', $text );
	}

	/**
	 * Share of the given terms (0–1) found in a passage, matched the way
	 * search() matches them — for callers weighing some terms over others.
	 *
	 * @param array    $row   A passage: heading_path, content.
	 * @param string[] $terms From query_terms().
	 * @return float
	 */
	public static function terms_share( array $row, array $terms ) {
		return self::matched_share( $row, $terms );
	}

	/**
	 * Whether every term occurs within $window words of the others — so a
	 * passage is about "accurate spindles", not "accurate" in one paragraph
	 * and "spindle" in the next. A term in the passage's heading counts as
	 * present throughout.
	 *
	 * @param array    $row    A passage: heading_path, content.
	 * @param string[] $terms  From query_terms().
	 * @param int      $window Words.
	 * @return bool
	 */
	public static function terms_near( array $row, array $terms, $window ) {
		$heading = strtolower( self::match_text( (string) $row['heading_path'] ) );
		$need    = array();
		foreach ( $terms as $term ) {
			if ( false === strpos( $heading, $term ) ) {
				$need[] = $term;
			}
		}
		if ( count( $need ) < 2 ) {
			return true;
		}

		// Positions of each term, by word. A hyphenated model number is also
		// tried without its hyphen, as match_text() does.
		$words = preg_split( '/[^\p{L}\p{N}\-]+/u', strtolower( self::join_model_numbers( (string) $row['content'] ) ), -1, PREG_SPLIT_NO_EMPTY );
		$hits  = array();
		foreach ( (array) $words as $pos => $word ) {
			$bare = str_replace( '-', '', $word );
			foreach ( $need as $i => $term ) {
				if ( false !== strpos( $word, $term ) || false !== strpos( $bare, $term ) ) {
					$hits[] = array( $pos, $i );
				}
			}
		}

		// Smallest stretch of words holding every term (two pointers).
		$count = array();
		$have  = 0;
		$left  = 0;
		$total = count( $hits );
		for ( $right = 0; $right < $total; $right++ ) {
			$t = $hits[ $right ][1];
			$count[ $t ] = isset( $count[ $t ] ) ? $count[ $t ] + 1 : 1;
			if ( 1 === $count[ $t ] ) {
				++$have;
			}
			while ( $have === count( $need ) ) {
				if ( $hits[ $right ][0] - $hits[ $left ][0] <= $window ) {
					return true;
				}
				$l = $hits[ $left ][1];
				--$count[ $l ];
				if ( 0 === $count[ $l ] ) {
					--$have;
				}
				++$left;
			}
		}

		return false;
	}

	/** Share of the search terms (0–1) that appear in the passage or its heading. */
	private static function matched_share( array $row, array $terms ) {
		$text  = strtolower( self::match_text( $row['heading_path'] . ' ' . $row['content'] ) );
		$found = 0;
		foreach ( $terms as $term ) {
			if ( false !== strpos( $text, $term ) ) {
				++$found;
			}
		}

		return round( $found / max( 1, count( $terms ) ), 3 );
	}

	/** Term frequency, with heading hits counting triple. */
	private static function score( array $row, array $terms ) {
		$content = strtolower( self::match_text( (string) $row['content'] ) );
		$heading = strtolower( self::match_text( (string) $row['heading_path'] ) );
		$score   = 0.0;
		$matched = 0;
		foreach ( $terms as $term ) {
			$in_body = substr_count( $content, $term );
			$in_head = substr_count( $heading, $term );
			if ( $in_body || $in_head ) {
				++$matched;
			}
			$score += $in_body + 3 * $in_head;
		}

		// A passage matching every term beats one repeating a single term.
		return round( $score * ( $matched / max( 1, count( $terms ) ) ), 3 );
	}
}
