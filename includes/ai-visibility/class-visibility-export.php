<?php
/**
 * AI Visibility — CSV export for analysts.
 *
 * One file, one row per question asked and, under it, one row per follow-up
 * question that engine predicted (`row_type` question | follow_up). Question
 * rows carry the verdict, where it cited and the full answer; follow-up rows
 * carry the question they followed. Both carry pattern columns worked out
 * here, so a spreadsheet can pivot on them without an AI: does the text name
 * the subject or a brand (and at which word), a model number, a place, the
 * business itself; is it a hiring question or a how/what one.
 *
 * Every row carries the site, business type and plugin version, so files from
 * many client sites stack into one sheet and can be split by industry.
 *
 * Follow-ups are labelled `engine prediction` in every row: they are what each
 * engine expects the buyer to ask next, not questions real users typed.
 *
 * Sample-data runs are never exported — they are invented.
 *
 * No UI here; TWTAEO_Visibility::export_download() streams the result.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Export {

	/** Turn of a first answer; journey mode's later turns carry 2, 3. */
	const TURN = 1;

	const FOLLOW_UP_SOURCE = 'engine prediction';

	/** A later turn's question: the engine's own top follow-up, asked back to it. */
	const JOURNEY_SOURCE = 'engine follow-up, asked';

	private function __construct() {}

	/* ─────────────────────────── runs ─────────────────────────── */

	/**
	 * The real runs to export: one by id, or every kept run for 'all'.
	 *
	 * @param string $which Run id or 'all'.
	 * @return array Run[] oldest first.
	 */
	public static function runs( $which ) {
		$which = (string) $which;
		$ids   = array();
		if ( 'all' === $which ) {
			foreach ( (array) TWTAEO_Visibility_Store::list_runs() as $s ) {
				if ( is_array( $s ) && ! empty( $s['id'] ) && empty( $s['demo'] ) ) {
					$ids[] = (string) $s['id'];
				}
			}
			$ids = array_reverse( $ids );
		} elseif ( '' !== $which ) {
			$ids[] = $which;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$run = TWTAEO_Visibility_Store::get_run( $id );
			if ( is_array( $run ) && empty( $run['demo'] ) && ! empty( $run['checks'] ) ) {
				$out[] = $run;
			}
		}
		return $out;
	}

	/* ─────────────────────────── rows ─────────────────────────── */

	/** Column order. Question-only columns are blank on follow-up rows. */
	const COLUMNS = array( 'site_host', 'site_name', 'business_type', 'plugin_version', 'run_id', 'run_started', 'run_status', 'persona', 'search_country', 'search_region', 'search_city', 'search_location', 'turn', 'row_type', 'question_id', 'question_level', 'question_about', 'question_family', 'engine', 'model', 'checked_at', 'text', 'source', 'follow_up_rank', 'follow_up_of', 'verdict', 'cited_you', 'citation_surface', 'accuracy', 'your_urls_cited', 'cited_domains', 'other_domains', 'sources_given', 'follow_up_count', 'answer_text', 'answer_is_excerpt', 'error', 'word_count', 'intent', 'mentions_subject', 'subject_word_position', 'mentions_brand', 'brand_word_position', 'mentions_your_business', 'model_numbers', 'mentions_place' );

	/**
	 * Every row, header first: each check as a question row, followed by its
	 * follow-up rows — then, in journey mode, the later turns of that same
	 * conversation (turn 2, 3), each with its own follow-ups.
	 *
	 * @param array $runs Run[].
	 * @return array[]
	 */
	public static function rows( array $runs ) {
		$site = self::site();
		$ctx  = self::tag_context();
		$rows = array( self::COLUMNS );
		foreach ( $runs as $run ) {
			$questions = self::questions_by_id( $run );
			$common    = array(
				'site_host'      => $site['host'],
				'site_name'      => $site['name'],
				'business_type'  => $site['business_type'],
				'plugin_version' => $site['version'],
				'run_id'         => (string) $run['id'],
				'run_started'    => (string) $run['started_at'],
				'run_status'     => (string) $run['status'],
				'persona'        => self::persona( $run ),
				// Where the run searched from (blank: no location).
				'search_country' => isset( $run['allocation']['location']['country'] ) ? (string) $run['allocation']['location']['country'] : '',
				'search_region'  => isset( $run['allocation']['location']['region'] ) ? (string) $run['allocation']['location']['region'] : '',
				'search_city'    => isset( $run['allocation']['location']['city'] ) ? (string) $run['allocation']['location']['city'] : '',
			);

			$later = array();
			foreach ( isset( $run['journey'] ) ? (array) $run['journey'] : array() as $jc ) {
				$later[ $jc['question_id'] . '|' . $jc['engine'] ][ (int) $jc['turn'] ] = $jc;
			}

			foreach ( (array) $run['checks'] as $c ) {
				$q    = isset( $questions[ $c['question_id'] ] ) ? $questions[ $c['question_id'] ] : array();
				$text = isset( $q['text'] ) ? (string) $q['text'] : '';
				foreach ( self::check_rows( $common, $q, $c, $text, isset( $q['source'] ) ? (string) $q['source'] : '', '', $site, $ctx ) as $row ) {
					$rows[] = $row;
				}

				$turns = isset( $later[ $c['question_id'] . '|' . $c['engine'] ] ) ? $later[ $c['question_id'] . '|' . $c['engine'] ] : array();
				ksort( $turns );
				$before = $text;
				foreach ( $turns as $jc ) {
					foreach ( self::check_rows( $common, $q, $jc, (string) $jc['asked'], self::JOURNEY_SOURCE, $before, $site, $ctx ) as $row ) {
						$rows[] = $row;
					}
					$before = (string) $jc['asked'];
				}
			}
		}
		return $rows;
	}

	/**
	 * One asked question (any turn) as a question row plus its follow-up rows.
	 *
	 * @param array  $common       Site and run columns.
	 * @param array  $q            The run's question this conversation started from.
	 * @param array  $c            The check.
	 * @param string $text         What was asked at this turn.
	 * @param string $source       Where the asked text came from.
	 * @param string $follow_up_of The question asked the turn before ('' on turn 1).
	 * @param array  $site
	 * @param array  $ctx
	 * @return array[]
	 */
	private static function check_rows( array $common, array $q, array $c, $text, $source, $follow_up_of, array $site, array $ctx ) {
		$about = isset( $q['scope_label'] ) ? (string) $q['scope_label'] : '';
		$full  = isset( $c['answer'] ) ? (string) $c['answer'] : '';
		$check = $common + array(
			'turn'            => isset( $c['turn'] ) ? max( 1, (int) $c['turn'] ) : self::TURN,
			'question_id'     => (string) $c['question_id'],
			'question_level'  => self::level_label( isset( $q['level'] ) ? $q['level'] : '' ),
			'question_about'  => $about,
			'question_family' => isset( $q['family'] ) ? (string) $q['family'] : '',
			'engine'          => self::engine_label( $c['engine'] ),
			'model'           => (string) $c['model'],
			'checked_at'      => (string) $c['at'],
			// search = the engine's search was given the location; told = only the
			// assistant was told it (no search location in that API, or refused).
			'search_location' => false !== strpos( (string) ( isset( $c['tools'] ) ? $c['tools'] : '' ), 'user_location' ) ? 'search' : ( '' !== $common['search_country'] ? 'told' : '' ),
		);

		$out   = array();
		$out[] = self::ordered(
			$check + array(
				'row_type'          => 'question',
				'text'              => $text,
				'source'            => $source,
				'follow_up_of'      => $follow_up_of,
				'verdict'           => (string) $c['verdict'],
				'cited_you'         => 'cited' === $c['verdict'] ? 'yes' : 'no',
				'citation_surface'  => (string) $c['citation_surface'],
				'accuracy'          => (string) $c['accuracy'],
				'your_urls_cited'   => implode( ' | ', (array) $c['our_urls'] ),
				'cited_domains'     => implode( ' | ', (array) $c['domains'] ),
				'other_domains'     => implode( ' | ', self::other_domains( $c, $site['hosts'] ) ),
				// no = answered without linking to anyone (not a competitor winning).
				'sources_given'     => 'unavailable' === (string) $c['verdict'] ? '' : ( empty( $c['domains'] ) && empty( $c['cited_urls'] ) ? 'no' : 'yes' ),
				'follow_up_count'   => count( (array) $c['follow_ups'] ),
				'answer_text'       => '' !== $full ? $full : (string) $c['excerpt'],
				'answer_is_excerpt' => '' !== $full ? 'no' : 'yes',
				'error'             => null === $c['error'] ? '' : (string) $c['error'],
			) + self::tag( $text, $about, $ctx )
		);

		$rank = 0;
		foreach ( (array) $c['follow_ups'] as $fq ) {
			$fq = (string) $fq;
			if ( '' === $fq ) {
				continue;
			}
			++$rank;
			$out[] = self::ordered(
				$check + array(
					'row_type'       => 'follow_up',
					'text'           => $fq,
					'source'         => self::FOLLOW_UP_SOURCE,
					'follow_up_rank' => $rank,
					'follow_up_of'   => $text,
				) + self::tag( $fq, $about, $ctx )
			);
		}
		return $out;
	}

	/** A keyed row in COLUMNS order, blanks where a column does not apply. */
	private static function ordered( array $row ) {
		$out = array();
		foreach ( self::COLUMNS as $col ) {
			$out[] = array_key_exists( $col, $row ) ? $row[ $col ] : '';
		}
		return $out;
	}

	/* ─────────────────────────── pattern tags ─────────────────────────── */

	/**
	 * What the site knows to look for: its tracked brands, its own names, and
	 * the places it serves.
	 *
	 * @return array { brands[], own[], places[] }
	 */
	public static function tag_context() {
		$identity = TWTAEO_Visibility_Inputs::identity();
		$brands   = array();
		foreach ( (array) ( isset( $identity['brands'] ) ? $identity['brands'] : array() ) as $b ) {
			$brands[] = (string) $b;
		}
		foreach ( (array) ( isset( $identity['brand_items'] ) ? $identity['brand_items'] : array() ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['label'] ) ) {
				$brands[] = (string) $item['label'];
			}
		}

		$own = array();
		if ( ! empty( $identity['name'] ) ) {
			$own[] = (string) $identity['name'];
		}
		foreach ( (array) ( isset( $identity['hosts'] ) ? $identity['hosts'] : array() ) as $h ) {
			$labels = explode( '.', preg_replace( '/^www\./', '', (string) $h ) );
			if ( count( $labels ) > 1 && strlen( $labels[0] ) >= 4 ) {
				$own[] = str_replace( '-', ' ', $labels[0] );
			}
		}

		$places = array();
		if ( class_exists( 'TWTAEO_Local_Pack' ) ) {
			$lp = (array) TWTAEO_Local_Pack::get_settings();
			foreach ( array( 'city', 'state' ) as $k ) {
				if ( ! empty( $lp[ $k ] ) ) {
					$places[] = (string) $lp[ $k ];
				}
			}
			if ( ! empty( $lp['area_served'] ) ) {
				foreach ( explode( ',', (string) $lp['area_served'] ) as $a ) {
					$places[] = trim( $a );
				}
			}
		}

		return array(
			'brands' => self::uniq_terms( $brands ),
			'own'    => self::uniq_terms( $own ),
			'places' => self::uniq_terms( $places ),
		);
	}

	/**
	 * Pattern columns for one follow-up. Public for tests.
	 *
	 * @param string $text    The follow-up question.
	 * @param string $subject What the original question was about ("HSD", a category, a product).
	 * @param array  $ctx     From tag_context().
	 * @return array
	 */
	public static function tag( $text, $subject, array $ctx ) {
		$words = self::words( $text );

		$subject_pos = '' !== trim( $subject ) ? self::position( $words, $subject ) : 0;

		$brand     = '';
		$brand_pos = 0;
		foreach ( (array) $ctx['brands'] as $b ) {
			$pos = self::position( $words, $b );
			if ( $pos > 0 && ( 0 === $brand_pos || $pos < $brand_pos ) ) {
				$brand     = $b;
				$brand_pos = $pos;
			}
		}

		$own = false;
		foreach ( (array) $ctx['own'] as $n ) {
			if ( self::position( $words, $n ) > 0 ) {
				$own = true;
				break;
			}
		}

		$place = '';
		if ( preg_match( '/\bnear\s+me\b|\bnearby\b|\blocal(?:ly)?\b/i', $text, $m ) ) {
			$place = strtolower( $m[0] );
		}
		foreach ( (array) $ctx['places'] as $p ) {
			if ( self::position( $words, $p ) > 0 ) {
				$place = $p;
				break;
			}
		}

		// The RAG engine's own classifier, so both screens agree: hire | place |
		// repair | brand mean the text is looking for a business, not an answer.
		$intent = '';
		if ( class_exists( 'TWTAEO_RAG_Signals' ) && method_exists( 'TWTAEO_RAG_Signals', 'hiring_intent' ) ) {
			$reason = (string) TWTAEO_RAG_Signals::hiring_intent( $text );
			$intent = '' === $reason ? 'question' : $reason;
		}
		$ids = array();
		if ( class_exists( 'TWTAEO_RAG_Signals' ) && method_exists( 'TWTAEO_RAG_Signals', 'identifiers' ) ) {
			$ids = (array) TWTAEO_RAG_Signals::identifiers( $text );
		}

		return array(
			'word_count'             => count( $words ),
			'intent'                 => $intent,
			'mentions_subject'       => $subject_pos > 0 ? 'yes' : 'no',
			'subject_word_position'  => $subject_pos > 0 ? $subject_pos : '',
			'mentions_brand'         => $brand,
			'brand_word_position'    => $brand_pos > 0 ? $brand_pos : '',
			'mentions_your_business' => $own ? 'yes' : 'no',
			'model_numbers'          => implode( ' | ', array_map( 'strval', $ids ) ),
			'mentions_place'         => $place,
		);
	}

	/** Lower-case words, punctuation dropped. */
	private static function words( $text ) {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array();
	}

	/** 1-based word position where the phrase starts, 0 when absent. Whole words only. */
	private static function position( array $words, $phrase ) {
		$needle = self::words( $phrase );
		$n      = count( $needle );
		if ( 0 === $n ) {
			return 0;
		}
		$last = count( $words ) - $n;
		for ( $i = 0; $i <= $last; $i++ ) {
			if ( array_slice( $words, $i, $n ) === $needle ) {
				return $i + 1;
			}
		}
		return 0;
	}

	private static function uniq_terms( array $terms ) {
		$out  = array();
		$seen = array();
		foreach ( $terms as $t ) {
			$t = trim( wp_strip_all_tags( (string) $t ) );
			$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t );
			if ( strlen( $t ) >= 2 && ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = true;
				$out[]      = $t;
			}
		}
		return $out;
	}

	/* ─────────────────────────── CSV ─────────────────────────── */

	/**
	 * Rows to CSV text: UTF-8 with a BOM so Excel reads accents right, CRLF
	 * line ends, every cell quoted. Public for tests.
	 *
	 * @param array[] $rows
	 * @return string
	 */
	public static function to_csv( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = implode( ',', array_map( array( __CLASS__, 'cell' ), (array) $row ) );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One quoted cell. Text that a spreadsheet would run as a formula (it came
	 * from an AI answer, so it is untrusted) gets a leading apostrophe — the
	 * standard CSV-injection guard. Numbers pass as they are.
	 *
	 * @param mixed $v
	 * @return string
	 */
	public static function cell( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		$v = (string) $v;
		if ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) {
			$v = "'" . $v;
		}
		return '"' . str_replace( '"', '""', $v ) . '"';
	}

	/** twt-aeo-example-com-ai-visibility-all-runs-2026-09-28.csv */
	public static function filename( $which ) {
		$host = sanitize_title( self::site()['host'] );
		$tail = 'all' === $which ? 'all-runs-' . gmdate( 'Y-m-d' ) : 'run-' . substr( sanitize_title( (string) $which ), 0, 8 );
		return 'twt-aeo-' . $host . '-ai-visibility-' . $tail . '.csv';
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/** Site columns, worked out once per export. */
	private static function site() {
		static $site = null;
		if ( null !== $site ) {
			return $site;
		}
		$identity = TWTAEO_Visibility_Inputs::identity();
		$hosts    = isset( $identity['hosts'] ) ? (array) $identity['hosts'] : array();
		$type     = '';
		if ( class_exists( 'TWTAEO_Local_Pack' ) ) {
			$lp  = (array) TWTAEO_Local_Pack::get_settings();
			$raw = isset( $lp['business_type'] ) ? (string) $lp['business_type'] : '';
			if ( '' !== $raw && ! in_array( $raw, array( 'LocalBusiness', 'Organization' ), true ) ) {
				$labels = TWTAEO_Local_Pack::get_subtypes();
				$type   = isset( $labels[ $raw ] ) ? $labels[ $raw ] : $raw;
			}
		}
		$site = array(
			'host'          => isset( $hosts[0] ) ? (string) $hosts[0] : (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'hosts'         => $hosts,
			'name'          => isset( $identity['name'] ) ? (string) $identity['name'] : '',
			'business_type' => $type,
			'version'       => defined( 'TWTAEO_VERSION' ) ? TWTAEO_VERSION : '',
		);
		return $site;
	}

	private static function questions_by_id( array $run ) {
		$out = array();
		foreach ( (array) $run['questions'] as $q ) {
			if ( is_array( $q ) && isset( $q['id'] ) ) {
				$out[ (string) $q['id'] ] = $q;
			}
		}
		return $out;
	}

	private static function persona( array $run ) {
		return isset( $run['persona']['label'] ) ? (string) $run['persona']['label'] : '';
	}

	private static function level_label( $level ) {
		$labels = TWTAEO_Visibility_Types::LEVEL_LABEL;
		return isset( $labels[ $level ] ) ? $labels[ $level ] : (string) $level;
	}

	private static function engine_label( $engine ) {
		$engines = TWTAEO_Visibility_Types::ENGINES;
		return isset( $engines[ $engine ]['label'] ) ? $engines[ $engine ]['label'] : (string) $engine;
	}

	/** Cited domains that are neither the site nor a profile it owns. */
	private static function other_domains( array $c, array $hosts ) {
		$mine = array_map( 'strtolower', array_merge( $hosts, (array) $c['owned_domains'] ) );
		$mine = array_map(
			static function ( $h ) {
				return preg_replace( '/^www\./', '', $h );
			},
			$mine
		);
		$out  = array();
		foreach ( (array) $c['domains'] as $d ) {
			$d = preg_replace( '/^www\./', '', strtolower( (string) $d ) );
			if ( '' !== $d && ! in_array( $d, $mine, true ) && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}
}
