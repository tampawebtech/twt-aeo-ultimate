<?php
/**
 * Document Profile — what an uploaded document is, and how it may be quoted.
 *
 * Every document gets a label: its kind (statute, building code, safety data
 * sheet, manual…), the place it applies to, its edition, the name to cite it
 * by, and an answer mode:
 *
 *  - "quote"   — the law, a code, a safety data sheet or a contract. Its text
 *                is published exactly as written, with its citation. Nothing
 *                here rewords or interprets it.
 *  - "explain" — manuals, spec sheets, and anything that explains a subject
 *                (including the owner's own write-ups about a law). It may be
 *                put in plain words, credited to the document.
 *
 * The label is guessed three ways, each able to overrule the one before:
 * PHP reads the passages for tell-tale patterns (§, "shall", SECTION 9,
 * R302.1); an AI provider reads a sample of the first pages when the owner
 * ticked the consent box at upload; and the owner can edit any of it.
 *
 * The AI only labels. It never answers questions or adds knowledge of its
 * own, and it sees a sample of the document, never the whole file.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Doc_Profile {

	const MODE_QUOTE   = 'quote';
	const MODE_EXPLAIN = 'explain';

	/** Characters of the document PHP reads for its guess. */
	const PHP_SAMPLE_CHARS = 40000;

	/** Characters of the document the AI is shown. About the first few pages. */
	const AI_SAMPLE_CHARS = 8000;

	/** Remembers each user's last answer to the consent box. */
	const CONSENT_META = 'twtaeo_cge_ai_consent';

	const STATES = array(
		'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'District of Columbia',
		'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine',
		'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
		'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon',
		'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia',
		'Washington', 'West Virginia', 'Wisconsin', 'Wyoming',
	);

	private function __construct() {}

	/**
	 * Document kinds, each with the answer mode it starts with.
	 *
	 * @return array slug => { label, mode }
	 */
	public static function kinds() {
		return array(
			'law'       => array(
				'label' => __( 'Law or regulation', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_QUOTE,
			),
			'code'      => array(
				'label' => __( 'Building, electrical or safety code', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_QUOTE,
			),
			'sds'       => array(
				'label' => __( 'Safety data sheet', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_QUOTE,
			),
			'contract'  => array(
				'label' => __( 'Contract, form or agreement', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_QUOTE,
			),
			'manual'    => array(
				'label' => __( 'Manual or guide', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_EXPLAIN,
			),
			'spec'      => array(
				'label' => __( 'Spec sheet or catalog', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_EXPLAIN,
			),
			'explainer' => array(
				'label' => __( 'Explanation or article', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_EXPLAIN,
			),
			'other'     => array(
				'label' => __( 'Other', 'twt-aeo-ultimate' ),
				'mode'  => self::MODE_EXPLAIN,
			),
		);
	}

	public static function kind_label( $kind ) {
		$kinds = self::kinds();

		return isset( $kinds[ $kind ] ) ? $kinds[ $kind ]['label'] : __( 'Not labelled yet', 'twt-aeo-ultimate' );
	}

	public static function mode_label( $mode ) {
		return self::MODE_QUOTE === $mode
			? __( 'Quote exactly', 'twt-aeo-ultimate' )
			: __( 'Explain from this document', 'twt-aeo-ultimate' );
	}

	/* ─────────────────────────── reading profiles ─────────────────────────── */

	/**
	 * Profiles for a set of documents.
	 *
	 * @param int[] $ids
	 * @return array id => { title, kind, answer_mode, jurisdiction, edition, citation, cite_as }
	 */
	public static function profiles( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table        = TWTAEO_Content_Index::docs_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, kind, answer_mode, jurisdiction, edition, citation, doc_date, law_status, law_note, law_url, law_checked_at FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['answer_mode']       = self::MODE_QUOTE === $row['answer_mode'] ? self::MODE_QUOTE : self::MODE_EXPLAIN;
			$row['cite_as']           = '' !== $row['citation'] ? $row['citation'] : $row['title'];
			$out[ (int) $row['id'] ] = $row;
		}

		return $out;
	}

	/**
	 * The one-line label shown under a document's name, e.g.
	 * "Law or regulation · Florida · effective July 1, 2025 · Quote exactly".
	 */
	public static function summary_line( array $doc ) {
		if ( '' === (string) $doc['kind'] ) {
			return '';
		}
		$parts = array( self::kind_label( $doc['kind'] ) );
		foreach ( array( 'jurisdiction', 'edition' ) as $field ) {
			if ( '' !== (string) $doc[ $field ] ) {
				$parts[] = $doc[ $field ];
			}
		}
		$parts[] = self::mode_label( $doc['answer_mode'] );

		return implode( ' · ', $parts );
	}

	/* ─────────────────────────── copy text ─────────────────────────── */

	/**
	 * What the Copy button puts on the clipboard for a passage.
	 *
	 * Quote mode: the passage in quotation marks, then its citation — the
	 * wording is the point, so nothing is trimmed or reworded. Explain mode:
	 * the passage, then the document it came from, so an explanation is always
	 * credited to the owner's own source.
	 *
	 * @param array      $hit     A Content Index passage.
	 * @param array|null $profile From profiles().
	 * @param string     $title   Document title, when there is no profile.
	 * @return string
	 */
	public static function copy_text( array $hit, $profile, $title ) {
		$content = trim( (string) $hit['content'] );
		$cite    = $profile ? $profile['cite_as'] : $title;
		$where   = self::location( (string) $hit['heading_path'], $cite );
		$source  = $cite . ( '' !== $where ? ', ' . $where : '' );

		if ( $profile && self::MODE_QUOTE === $profile['answer_mode'] ) {
			$context = array();
			foreach ( array( 'jurisdiction', 'edition' ) as $field ) {
				// Skip what the citation already says ("Florida Statutes").
				if ( '' !== $profile[ $field ] && false === stripos( $cite, $profile[ $field ] ) ) {
					$context[] = $profile[ $field ];
				}
			}

			return '“' . $content . '”' . "\n\n— " . $source . ( $context ? ' (' . implode( ', ', $context ) . ')' : '' );
		}

		/* translators: %s: document name and section. */
		return $content . "\n\n" . sprintf( __( 'Source: %s', 'twt-aeo-ultimate' ), $source );
	}

	/* ─────────────────────────── tables ─────────────────────────── */

	/** Whether a passage holds a table rebuilt from its document. */
	public static function has_table( array $hit ) {
		foreach ( preg_split( '/\n{2,}/', trim( (string) $hit['content'] ) ) as $block ) {
			if ( TWTAEO_Chunker::is_table( $block ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The passage as HTML for the clipboard: pasted into the block editor
	 * (or most page builders) a table arrives as a table, not as pipes.
	 * The same source line as copy_text() follows it.
	 *
	 * @param array      $hit
	 * @param array|null $profile
	 * @param string     $title
	 * @return string Safe HTML.
	 */
	public static function copy_html( array $hit, $profile, $title ) {
		$plain = self::copy_text( $hit, $profile, $title );
		// The source line is the last paragraph of the plain copy.
		$parts  = preg_split( '/\n{2,}/', $plain );
		$source = array_pop( $parts );

		$body = self::markdown_html( trim( (string) $hit['content'] ) );
		if ( $profile && self::MODE_QUOTE === $profile['answer_mode'] ) {
			$body = '<blockquote>' . $body . '</blockquote>';
		}

		return $body . '<p><em>' . esc_html( $source ) . '</em></p>';
	}

	/**
	 * A short HTML preview of a table passage: the header and the rows that
	 * mention the search, or the first rows when none do.
	 *
	 * @param array  $hit
	 * @param string $query
	 * @param int    $max_rows
	 * @return string Safe HTML.
	 */
	public static function table_preview( array $hit, $query, $max_rows = 6 ) {
		$terms = TWTAEO_Content_Index::query_terms( $query );
		$out   = '';
		foreach ( preg_split( '/\n{2,}/', trim( (string) $hit['content'] ) ) as $block ) {
			if ( ! TWTAEO_Chunker::is_table( $block ) ) {
				continue;
			}
			$lines = explode( "\n", trim( $block ) );
			$rows  = array_slice( $lines, 2 );

			// The rows that match the most search words, shown in table order.
			$scores = array();
			foreach ( $rows as $i => $row ) {
				$low = strtolower( $row );
				$hit = 0;
				foreach ( $terms as $term ) {
					$hit += false !== strpos( $low, $term ) ? 1 : 0;
				}
				if ( $hit ) {
					$scores[ $i ] = $hit;
				}
			}
			if ( $scores ) {
				arsort( $scores );
				$keep = array_slice( array_keys( $scores ), 0, $max_rows );
				sort( $keep );
				$show = array();
				foreach ( $keep as $i ) {
					$show[] = $rows[ $i ];
				}
			} else {
				$show = array_slice( $rows, 0, $max_rows );
			}
			$out  .= self::table_html( array_merge( array( $lines[0], $lines[1] ), $show ), $terms );
			$more  = count( $rows ) - count( $show );
			if ( $more > 0 ) {
				$out .= '<p class="description" style="margin:2px 0 6px;">' . esc_html(
					sprintf(
						/* translators: 1: rows shown, 2: rows in the table. */
						__( 'Showing %1$d of %2$d rows. Copy takes the whole table.', 'twt-aeo-ultimate' ),
						count( $show ),
						count( $rows )
					)
				) . '</p>';
			}
		}

		return $out;
	}

	/** Markdown paragraphs and tables as HTML. */
	private static function markdown_html( $markdown ) {
		$html = '';
		foreach ( preg_split( '/\n{2,}/', (string) $markdown ) as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			$html .= TWTAEO_Chunker::is_table( $block )
				? self::table_html( explode( "\n", $block ) )
				: '<p>' . nl2br( esc_html( $block ) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Markdown table lines (header, separator, rows) as an HTML table.
	 *
	 * @param string[] $lines
	 * @param string[] $mark  Terms to wrap in <mark>, for previews.
	 * @return string
	 */
	private static function table_html( array $lines, array $mark = array() ) {
		$cells = static function ( $line ) {
			$line = trim( $line );
			$line = preg_replace( '/^\||\|$/', '', $line );
			return array_map( 'trim', explode( '|', (string) $line ) );
		};
		$fmt = static function ( $text ) use ( $mark ) {
			$html = esc_html( $text );
			foreach ( $mark as $term ) {
				$html = preg_replace( '/(' . preg_quote( esc_html( $term ), '/' ) . ')/i', '<mark>$1</mark>', $html );
			}
			return $html;
		};

		$html = '<table><thead><tr>';
		foreach ( $cells( $lines[0] ) as $cell ) {
			$html .= '<th>' . $fmt( $cell ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( array_slice( $lines, 2 ) as $line ) {
			$html .= '<tr>';
			foreach ( $cells( $line ) as $cell ) {
				$html .= '<td>' . $fmt( $cell ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * A heading trail as a citation location: "Chapter 475 › 475.278 › Page 3"
	 * reads "Chapter 475, 475.278, page 3".
	 */
	private static function location( $heading_path, $cite = '' ) {
		$parts = array_filter( array_map( 'trim', explode( '›', $heading_path ) ), 'strlen' );
		// The document's own title heading repeats the citation; drop it.
		$parts = array_filter(
			$parts,
			static function ( $part ) use ( $cite ) {
				return '' === $cite || ( false === stripos( $part, $cite ) && false === stripos( $cite, $part ) );
			}
		);
		$parts = array_map(
			static function ( $part ) {
				return preg_replace( '/^Page (\d+)$/', 'page $1', $part );
			},
			$parts
		);

		return implode( ', ', $parts );
	}

	/* ─────────────────────────── legal questions ─────────────────────────── */

	/** Words that make a search a legal question on their own. */
	const LEGAL_STRONG = '/\b(laws?|legal(?:ly)?|illegal|statutes?|statutory|ordinances?|regulations?|regulated|zoning|landlords?|tenants?|evict\w*|liab(?:le|ility)|lawful|unlawful|lawsuit|sue)\b/i';

	/** Words that make it one only when a law or code document answers it. */
	const LEGAL_WEAK = '/\b(required?|requirements?|permits?|permitted|allowed|disclos\w+|licen[cs]\w*|rights?|setbacks?|code|contracts?|penalt\w+|fines?)\b/i';

	/**
	 * Whether a search or question asks what the law or a code says.
	 *
	 * @param string  $query
	 * @param array[] $profiles Profiles of the documents answering it.
	 * @return bool
	 */
	public static function is_legal_question( $query, array $profiles ) {
		if ( preg_match( self::LEGAL_STRONG, (string) $query ) ) {
			return true;
		}
		if ( ! preg_match( self::LEGAL_WEAK, (string) $query ) ) {
			return false;
		}
		foreach ( $profiles as $profile ) {
			if ( in_array( $profile['kind'], array( 'law', 'code', 'contract' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/* ─────────────────────────── places ─────────────────────────── */

	/**
	 * The US states a text names, lowercase.
	 *
	 * @return string[]
	 */
	public static function states_in( $text ) {
		$text  = ' ' . strtolower( (string) $text ) . ' ';
		$found = array();
		foreach ( self::STATES as $state ) {
			$needle = strtolower( $state );
			if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $text ) ) {
				// "West Virginia" also contains "Virginia"; keep the longer one.
				if ( 'virginia' === $needle && ! preg_match( '/(?<!west )\bvirginia\b/', $text ) ) {
					continue;
				}
				if ( 'washington' === $needle && preg_match( '/\bwashington,? d\.?c\b/', $text ) && ! preg_match( '/\bwashington(?!,? d\.?c)\b/', $text ) ) {
					continue;
				}
				$found[] = $needle;
			}
		}

		return $found;
	}

	/* ─────────────────────────── the PHP guess ─────────────────────────── */

	/**
	 * Label a document from its passages alone. Nothing leaves the server.
	 *
	 * @param string $title
	 * @param string $text
	 * @return array { kind, answer_mode, jurisdiction, edition, citation }
	 */
	public static function guess( $title, $text ) {
		$text  = (string) $text;
		$all   = $title . "\n" . $text;
		$words = max( 1, str_word_count( $text ) );

		$count = static function ( $pattern ) use ( $all ) {
			return (int) preg_match_all( $pattern, $all );
		};

		$scores = array(
			'law'      => 3 * $count( '/§/u' )
				+ 3 * $count( '/\b(?:U\.S\.C|C\.F\.R|Stat\.|Statutes|Revised Statutes|Administrative Code|Code of Federal Regulations|Public Law)\b/' )
				+ 2 * $count( '/\b(?:ordinance|enacted|subsection|paragraph \([a-z0-9]\)|pursuant to|legislature)\b/i' )
				+ min( 10, (int) floor( $count( '/\bshall\b/i' ) / 3 ) ),
			'code'     => 4 * $count( '/\b(?:International|Uniform|National)\s+(?:Residential|Building|Fire|Plumbing|Mechanical|Electrical|Energy Conservation|Existing Building)\s+Code\b/i' )
				+ 3 * $count( '/\b(?:IRC|IBC|NEC|NFPA\s*\d+|IFC|IPC|IMC|IECC)\b/' )
				+ 2 * $count( '/\b[RENMPG]\d{3,4}\.\d+(?:\.\d+)*\b/' )
				+ 2 * $count( '/\b(?:building code|code official|building official|approved by the)\b/i' ),
			'sds'      => 8 * $count( '/\bsafety data sheet\b|\bmaterial safety\b/i' )
				+ 3 * $count( '/\bSECTION\s+\d{1,2}\s*[:.\-]\s*(?:Identification|Hazards?|Composition|First[- ]aid|Fire[- ]fighting|Accidental|Handling|Exposure|Physical|Stability|Toxicolog|Ecolog|Disposal|Transport|Regulatory)/i' )
				+ 2 * $count( '/\b(?:GHS|CAS(?: No\.?| number)?|pictogram|signal word)\b/i' ),
			'contract' => 3 * $count( '/\b(?:hereinafter|hereby|in witness whereof|the parties|this agreement|addendum|indemnif\w+)\b/i' )
				+ 2 * $count( '/\b(?:signature|signed|initials|date signed)\b/i' ),
			'manual'   => 5 * $count( '/\b(?:owner\'?s manual|user(?:\'s)? (?:guide|manual)|installation (?:guide|manual|instructions)|service manual|operating instructions|assembly instructions)\b/i' )
				+ 2 * $count( '/\b(?:troubleshooting|maintenance|step \d+|do not|caution|warning|warranty|reset|install\w*)\b/i' ) / 3,
			'spec'     => 5 * $count( '/\b(?:spec(?:ification)? sheet|data ?sheet|technical data|specifications)\b/i' )
				+ $count( '/\b\d+(?:\.\d+)?\s?(?:mm|cm|in|lbs?|kg|V|A|W|kW|Hz|rpm|psi|BTU|°[CF])\b/' ) / 4,
		);

		$legal = max( $scores['law'], $scores['code'] );
		arsort( $scores );
		$kind = (string) key( $scores );
		if ( reset( $scores ) < 4 ) {
			$kind = 'other';
		}

		// A lawyer's article or a realtor's guide cites the law too — but it
		// talks to the reader. Many "you"s mean it explains the law, it is not
		// the law.
		if ( $legal >= 3 && in_array( $kind, array( 'law', 'code', 'other' ), true ) ) {
			$you_per_thousand = 1000 * $count( '/\b(?:you|your)\b/i' ) / $words;
			if ( $you_per_thousand > 8 ) {
				$kind = 'explainer';
			}
		}

		$kinds = self::kinds();

		return array(
			'kind'         => $kind,
			'answer_mode'  => $kinds[ $kind ]['mode'],
			'jurisdiction' => self::guess_place( $all ),
			'edition'      => self::guess_edition( $all ),
			'citation'     => self::guess_citation( $kind, $all ),
		);
	}

	/**
	 * The place a document applies to: the state it names far more than any
	 * other, with a county or city it names repeatedly.
	 */
	private static function guess_place( $text ) {
		$counts = array();
		foreach ( self::STATES as $state ) {
			$n = (int) preg_match_all( '/\b' . preg_quote( $state, '/' ) . '\b/', $text );
			if ( 'Virginia' === $state ) {
				$n -= (int) preg_match_all( '/\bWest Virginia\b/', $text );
			}
			if ( $n > 0 ) {
				$counts[ $state ] = $n;
			}
		}
		arsort( $counts );
		$state = '';
		$top   = reset( $counts );
		$next  = count( $counts ) > 1 ? array_values( $counts )[1] : 0;
		if ( $top >= 2 && $top >= 2 * $next ) {
			$state = (string) key( $counts );
		}

		$local = '';
		if ( preg_match_all( '/\b((?:[A-Z][a-z]+ ){0,2}[A-Z][a-z]+) County\b|\b(?:City|Town|Village) of ((?:[A-Z][a-z]+ ){0,2}[A-Z][a-z]+)\b/', $text, $m, PREG_SET_ORDER ) ) {
			$places = array();
			foreach ( $m as $match ) {
				$name            = ! empty( $match[1] ) ? $match[1] . ' County' : 'City of ' . $match[2];
				$places[ $name ] = isset( $places[ $name ] ) ? $places[ $name ] + 1 : 1;
			}
			arsort( $places );
			if ( reset( $places ) >= 3 ) {
				$local = (string) key( $places );
			}
		}

		return trim( $local . ( '' !== $local && '' !== $state ? ', ' : '' ) . $state );
	}

	/** "2021 edition", "effective July 1, 2025" or "revised March 2024", as printed. */
	private static function guess_edition( $text ) {
		$date = '(?:[A-Z][a-z]{2,8}\.? \d{1,2},? \d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}|[A-Z][a-z]{2,8} \d{4})';
		if ( preg_match( '/\b((?:19|20)\d{2}) Edition\b/i', $text, $m ) ) {
			return $m[1] . ' ' . __( 'edition', 'twt-aeo-ultimate' );
		}
		if ( preg_match( '/\b(?:effective(?: date)?:?|takes? effect(?: on)?)\s+(' . $date . ')/i', $text, $m ) ) {
			/* translators: %s: date as printed in the document. */
			return sprintf( __( 'effective %s', 'twt-aeo-ultimate' ), $m[1] );
		}
		if ( preg_match( '/\b(?:revised|revision date|rev\. date|last updated)\s*:?\s+(' . $date . ')/i', $text, $m ) ) {
			/* translators: %s: date as printed in the document. */
			return sprintf( __( 'revised %s', 'twt-aeo-ultimate' ), $m[1] );
		}
		if ( preg_match( '/\b((?:19|20)\d{2}) (?:International|Uniform|National|Florida|California) [A-Z][a-z]+(?: [A-Z][a-z]+)? Code\b/', $text, $m ) ) {
			return $m[1] . ' ' . __( 'edition', 'twt-aeo-ultimate' );
		}

		return '';
	}

	/** The name a law or code is cited by, when the text states it. */
	private static function guess_citation( $kind, $text ) {
		if ( 'code' === $kind && preg_match( '/\b(?:(?:19|20)\d{2} )?(?:International|Uniform|National|Florida|California|[A-Z][a-z]+ State) (?:Residential|Building|Fire|Plumbing|Mechanical|Electrical|Energy Conservation|Existing Building) Code\b/', $text, $m ) ) {
			return $m[0];
		}
		if ( 'law' === $kind && preg_match( '/\b(?:' . implode( '|', array_map( 'preg_quote', self::STATES ) ) . ') (?:Revised |Consolidated |General )?(?:Statutes|Code|Administrative Code|Laws)(?:,? (?:Chapter|Title|Section|§) ?[0-9][0-9A-Za-z.\-]*)?/u', $text, $m ) ) {
			return $m[0];
		}

		return '';
	}

	/* ─────────────────────────── pipeline ─────────────────────────── */

	/**
	 * Once a document is indexed: label it from its text, and queue the AI
	 * label when the owner allowed it. An owner-edited label is never touched.
	 */
	public static function after_indexing( $doc_id ) {
		$doc = self::row( $doc_id );
		if ( ! $doc || 'owner' === $doc['profile_source'] ) {
			return;
		}

		$fields = self::guess( $doc['title'], self::sample( $doc_id, self::PHP_SAMPLE_CHARS ) );
		if ( '' === $fields['citation'] ) {
			$fields['citation'] = self::printed_title( $doc_id );
		}
		$fields['profile_source'] = 'php';
		if ( (int) $doc['ai_ok'] && self::ai_available() ) {
			$fields['profile_status'] = 'pending';
		}
		self::update( $doc_id, $fields );
		TWTAEO_Law_Check::after_label( $doc_id );
	}

	/**
	 * Label documents uploaded before labels existed, from their passages.
	 * Cheap enough for a page load: one small query per unlabelled document.
	 */
	public static function backfill( $limit = 20 ) {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'ready' AND kind = '' LIMIT %d", (int) $limit ) );
		foreach ( (array) $ids as $id ) {
			self::after_indexing( (int) $id );
		}
	}

	/** Owner's edit. From here on, neither guess overwrites it. */
	public static function save_owner_label( $doc_id, array $input ) {
		$kinds = self::kinds();
		$kind  = isset( $input['kind'], $kinds[ $input['kind'] ] ) ? $input['kind'] : 'other';
		$mode  = isset( $input['answer_mode'] ) && self::MODE_QUOTE === $input['answer_mode'] ? self::MODE_QUOTE : self::MODE_EXPLAIN;

		self::update(
			$doc_id,
			array(
				'kind'           => $kind,
				'answer_mode'    => $mode,
				'jurisdiction'   => self::clip( isset( $input['jurisdiction'] ) ? $input['jurisdiction'] : '', 120 ),
				'edition'        => self::clip( isset( $input['edition'] ) ? $input['edition'] : '', 120 ),
				'citation'       => self::clip( isset( $input['citation'] ) ? $input['citation'] : '', 255 ),
				'profile_source' => 'owner',
				'profile_status' => '',
				'profile_note'   => '',
			)
		);
		TWTAEO_Law_Check::after_label( $doc_id );
	}

	/** The owner asked the AI to (re)label a document: that is their consent. */
	public static function request_ai_label( $doc_id ) {
		self::update(
			$doc_id,
			array(
				'ai_ok'          => 1,
				'profile_status' => 'pending',
				'profile_note'   => '',
			)
		);
		TWTAEO_Doc_Library::schedule();
	}

	/** Whether any document is waiting for its AI label. */
	public static function has_pending() {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		return (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE profile_status = 'pending' AND status = 'ready' LIMIT 1" );
	}

	/**
	 * Label one waiting document with AI. Called from the document tick, one
	 * document per call: each is a network request.
	 *
	 * @return bool Whether a document was handled.
	 */
	public static function process_pending() {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$id = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE profile_status = 'pending' AND status = 'ready' ORDER BY id ASC LIMIT 1" );
		if ( ! $id ) {
			return false;
		}
		if ( ! self::ai_available() ) {
			self::update(
				$id,
				array(
					'profile_status' => 'failed',
					'profile_note'   => __( 'No AI provider key is set, so this was labelled from its text only.', 'twt-aeo-ultimate' ),
				)
			);
			return true;
		}

		$result = self::ai_label( $id );
		$doc    = self::row( $id );
		if ( ! $doc ) {
			return true;
		}
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::warning( 'Document AI label failed: ' . $result->get_error_message(), array( 'doc' => $id ) );
			}
			self::update(
				$id,
				array(
					'profile_status' => 'failed',
					'profile_note'   => self::clip( $result->get_error_message(), 255 ),
				)
			);
			return true;
		}

		// The owner saved a label while the AI was reading (which clears
		// "pending"): theirs stands.
		if ( 'pending' !== $doc['profile_status'] ) {
			return true;
		}

		$result['profile_source'] = 'ai';
		$result['profile_status'] = 'done';
		$result['profile_note']   = '';
		self::update( $id, $result );
		TWTAEO_Law_Check::after_label( $id );

		return true;
	}

	/* ─────────────────────────── the AI label ─────────────────────────── */

	/** Whether an AI provider can be called for labels. */
	public static function ai_available() {
		$provider = TWTAEO_AI_Client::enrich_provider();
		if ( '' !== TWTAEO_Key_Resolver::get( $provider ) ) {
			return true;
		}

		return in_array( $provider, array( 'claude', 'openai' ), true ) && TWTAEO_Key_Resolver::ai_client_available();
	}

	/** The provider's name as the consent box shows it. */
	public static function provider_name() {
		$names = array(
			'claude' => 'Anthropic (Claude)',
			'openai' => 'OpenAI',
			'gemini' => 'Google (Gemini)',
		);
		$p     = TWTAEO_AI_Client::enrich_provider();

		return isset( $names[ $p ] ) ? $names[ $p ] : ucfirst( $p );
	}

	/**
	 * Ask the AI what the document is, from its first pages.
	 *
	 * @return array|WP_Error Fields for the documents table.
	 */
	private static function ai_label( $doc_id ) {
		$doc = self::row( $doc_id );
		if ( ! $doc ) {
			return new WP_Error( 'twtaeo_doc_missing', __( 'Document not found.', 'twt-aeo-ultimate' ) );
		}
		$sample = self::sample( $doc_id, self::AI_SAMPLE_CHARS );
		if ( '' === trim( $sample ) ) {
			return new WP_Error( 'twtaeo_doc_empty', __( 'This document has no text to label.', 'twt-aeo-ultimate' ) );
		}

		$kinds  = array_keys( self::kinds() );
		$system = 'You label documents for a search tool used by a business website. Describe only what the text itself shows. '
			. 'Do not interpret the document, explain what it means, give advice, or add anything from your own knowledge. '
			. 'If a field is not stated in the text, return an empty string for it.';
		$prompt = "Label this document. Reply with JSON only, with these keys:\n"
			. '- "kind": one of ' . implode( ', ', $kinds ) . ".\n"
			. "  law = the text of a statute, ordinance, regulation or administrative rule.\n"
			. "  code = the text of a building, electrical, fire, plumbing or safety code.\n"
			. "  sds = a safety data sheet.\n"
			. "  contract = a contract, agreement, disclosure form or other form to fill in.\n"
			. "  manual = an owner's, user, installation, assembly or service manual or guide.\n"
			. "  spec = a spec sheet, data sheet or product catalog.\n"
			. "  explainer = writing that explains, summarizes or comments on a subject, including articles or guides ABOUT a law or code.\n"
			. "  other = anything else.\n"
			. '- "is_the_law_itself": true only if the text IS the law or code (its official wording), false if it is writing about it.' . "\n"
			. '- "jurisdiction": the state, county or city it applies to, as named in the text (e.g. "Orange County, Florida"). Empty if none.' . "\n"
			. '- "edition": the edition, version, effective date or revision date as printed (e.g. "2021 edition", "effective July 1, 2025"). Empty if none.' . "\n"
			. '- "citation": the official name to cite it by, as printed (e.g. "Florida Statutes Chapter 475", "2021 International Residential Code", "Samsung RF28R7351SR User Manual"). Empty if unclear.' . "\n"
			. '- "summary": one plain sentence, under 25 words, saying what the document covers. No interpretation.' . "\n\n"
			. 'File name: ' . $doc['filename'] . "\n"
			. "First pages of the document:\n---\n" . $sample . "\n---";

		$raw = TWTAEO_AI_Client::complete(
			'',
			$prompt,
			array(
				'system'      => $system,
				'json'        => true,
				'max_tokens'  => 500,
				'temperature' => 0,
				'timeout'     => 45,
			)
		);
		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$kind = isset( $data['kind'] ) ? sanitize_key( (string) $data['kind'] ) : 'other';
		if ( ! in_array( $kind, $kinds, true ) ) {
			$kind = 'other';
		}
		// Law or code wording the AI says is not the official text is
		// someone explaining it — which may be reworded, but not quoted as law.
		if ( in_array( $kind, array( 'law', 'code' ), true ) && isset( $data['is_the_law_itself'] ) && false === $data['is_the_law_itself'] ) {
			$kind = 'explainer';
		}
		$all = self::kinds();

		return array(
			'kind'         => $kind,
			'answer_mode'  => $all[ $kind ]['mode'],
			'jurisdiction' => self::clip( isset( $data['jurisdiction'] ) ? $data['jurisdiction'] : '', 120 ),
			'edition'      => self::clip( isset( $data['edition'] ) ? $data['edition'] : '', 120 ),
			'citation'     => self::clip( isset( $data['citation'] ) ? $data['citation'] : '', 255 ),
			'summary'      => self::clip( isset( $data['summary'] ) ? $data['summary'] : '', 500 ),
		);
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * The start of a document, rebuilt from its first passages (the file and
	 * its full text are gone by the time it is labelled).
	 */
	private static function sample( $doc_id, $chars ) {
		global $wpdb;
		$table = TWTAEO_Content_Index::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT heading_path, content FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY chunk_index ASC LIMIT 200", TWTAEO_Content_Index::SOURCE_DOC, (int) $doc_id ), ARRAY_A );

		$out  = '';
		$last = null;
		foreach ( (array) $rows as $row ) {
			if ( $row['heading_path'] !== $last && '' !== $row['heading_path'] ) {
				$out .= "\n## " . $row['heading_path'] . "\n";
			}
			$last = $row['heading_path'];
			$out .= $row['content'] . "\n";
			if ( strlen( $out ) >= $chars ) {
				break;
			}
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $out, 0, $chars, 'UTF-8' ) : substr( $out, 0, $chars );
	}

	/**
	 * The title printed at the top of the document — the heading nearly every
	 * passage sits under ("NetLink AX5400 Router User Guide") — which names it
	 * better than its file name. Empty when there is no such heading.
	 */
	private static function printed_title( $doc_id ) {
		global $wpdb;
		$table = TWTAEO_Content_Index::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		$paths = $wpdb->get_col( $wpdb->prepare( "SELECT heading_path FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY chunk_index ASC LIMIT 200", TWTAEO_Content_Index::SOURCE_DOC, (int) $doc_id ) );
		if ( ! $paths ) {
			return '';
		}
		$first = trim( (string) explode( '›', (string) $paths[0] )[0] );
		if ( strlen( $first ) < 8 || strlen( $first ) > 150 || preg_match( '/^(?:page|section|chapter|part|step)\b/i', $first ) ) {
			return '';
		}
		$under = 0;
		foreach ( $paths as $path ) {
			$under += 0 === strpos( (string) $path, $first ) ? 1 : 0;
		}

		return $under >= 0.8 * count( $paths ) ? self::clip( $first, 255 ) : '';
	}

	private static function row( $doc_id ) {
		global $wpdb;
		$table = TWTAEO_Content_Index::docs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is ours.
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, title, filename, ai_ok, profile_source, profile_status FROM {$table} WHERE id = %d", (int) $doc_id ), ARRAY_A );
	}

	private static function update( $doc_id, array $fields ) {
		global $wpdb;
		// The document's date, for how old a law or code is, follows its edition.
		if ( array_key_exists( 'edition', $fields ) ) {
			$fields['doc_date'] = TWTAEO_Law_Check::date_of( $fields['edition'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->update( TWTAEO_Content_Index::docs_table(), $fields, array( 'id' => (int) $doc_id ) );
	}

	private static function clip( $value, $max ) {
		$value = sanitize_text_field( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
