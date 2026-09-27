<?php
/**
 * PDF Tables — rebuild the tables a PDF's plain text scrambles.
 *
 * A PDF has no tables, only text placed on a page. Read as plain text, a
 * spec-sheet grid becomes a row label, a string of ● ○ ― marks and, far
 * away, the column headings and the key that say what the marks mean.
 * Copied onto a web page that is unreadable.
 *
 * This reads where each piece of text sits and puts the table back together:
 *
 *  - Mark tables ("Standard and Optional Equipment"): a label, then one mark
 *    per column. The marks are translated through the page's own key
 *    ("●: Standard  ○: Option  -: N/A") and the column headings rebuilt
 *    ("i-100H" over "S ST" → "i-100H", "i-100H S", "i-100H ST").
 *  - Spec tables: a label on the left ("Torque S1/S6 (40%)", often with a
 *    translation under it) and one or more values to the right.
 *
 * Anything that does not clearly have a table's shape is left alone: a page
 * of prose read as a table would be worse than the plain text.
 *
 * Pure PHP on smalot/pdfparser's positioned text, so it runs anywhere the
 * extractor does.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_PDF_Tables {

	/** Characters used as table marks. Dashes are all read as the same "not available" mark. */
	const MARKS = '●○◎△▲▼◆◇■□✓✔✗×✕―–—\-';

	/** Rows closer than this (points) are the same row. */
	const ROW_TOLERANCE = 2.2;

	/** A label line up to this far from a row's marks still belongs to it (wrapped labels). */
	const LABEL_REACH = 9.0;

	/** Fewest rows a region needs to be read as a table. */
	const MIN_ROWS = 3;

	private function __construct() {}

	/**
	 * Whether a page's plain text looks like it holds a table worth rebuilding.
	 * Cheap: decides whether the positioned read is worth doing at all.
	 *
	 * @param string $text Plain text of the page.
	 * @return bool
	 */
	public static function worth_trying( $text ) {
		$text = (string) $text;
		// A mark table: many marks on the page.
		if ( preg_match_all( '/[●○◎△▲▼◆◇□✓✔✗×✕―]/u', $text ) >= 12 ) {
			return true;
		}
		// A spec table: many short lines that are a number and a unit.
		$values = preg_match_all( '/^\s*[\d.,\/x× ~\-]+\s*(?:mm|cm|m|in|"|rpm|min-1|kW|W|hp|HP|Nm|N·m|kg|lb|lbs|V|A|Hz|bar|psi|MPa|°|%)\b/mu', $text );

		return $values >= 4;
	}

	/**
	 * Rebuild a page's text with its tables as Markdown tables.
	 *
	 * @param string $text   Plain text of the page (from getText()).
	 * @param array  $pieces From getDataTm(): [ [ matrix(6) ], text ].
	 * @return string|null The page text with tables rebuilt, or null when none was found.
	 */
	public static function rebuild( $text, array $pieces ) {
		$items = self::items( $pieces );
		if ( count( $items ) < 6 ) {
			return null;
		}

		$tables = self::mark_tables( $items );
		if ( ! $tables ) {
			$tables = self::spec_tables( $items, (string) $text );
		}
		if ( ! $tables ) {
			return null;
		}

		return self::splice( (string) $text, $items, $tables );
	}

	/* ─────────────────────────── positioned text ─────────────────────────── */

	/**
	 * Positioned pieces as { i, x, y, text }, in the order the page draws them.
	 */
	private static function items( array $pieces ) {
		$out = array();
		foreach ( $pieces as $i => $piece ) {
			if ( ! isset( $piece[0][4], $piece[0][5], $piece[1] ) ) {
				continue;
			}
			$text = self::clean( (string) $piece[1] );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'i'    => $i,
				'x'    => (float) $piece[0][4],
				'y'    => (float) $piece[0][5],
				'text' => $text,
				'raw'  => (string) $piece[1],
			);
		}

		return $out;
	}

	/** Collapse whitespace, including full-width and no-break spaces. */
	private static function clean( $text ) {
		$text = preg_replace( '/[\s\x{00A0}\x{3000}]+/u', ' ', (string) $text );

		return trim( (string) $text );
	}

	/** The marks in a piece, when the piece is nothing but marks; else null. */
	private static function marks_of( $text ) {
		if ( ! preg_match( '/^[' . self::MARKS . '\s]+$/u', $text ) ) {
			return null;
		}
		preg_match_all( '/[' . self::MARKS . ']/u', $text, $m );
		$marks = array();
		foreach ( $m[0] as $mark ) {
			$marks[] = self::norm_mark( $mark );
		}

		return $marks ? $marks : null;
	}

	private static function norm_mark( $mark ) {
		return in_array( $mark, array( '-', '–', '—', '―' ), true ) ? '―' : $mark;
	}

	/* ─────────────────────────── mark tables ─────────────────────────── */

	/**
	 * @return array[] { rows: string[][], header: string[], used: int[] (item keys), first: int (item key) }
	 */
	private static function mark_tables( array $items ) {
		// Rows of marks: pieces that are only marks, grouped by height. One
		// piece may hold the whole row ("● ● ○"), or each mark its own piece.
		$mark_items = array();
		foreach ( $items as $k => $item ) {
			$marks = self::marks_of( $item['text'] );
			if ( null !== $marks ) {
				$mark_items[ $k ] = $marks;
			}
		}
		if ( count( $mark_items ) < self::MIN_ROWS ) {
			return array();
		}

		$legend = self::legend( $items );

		// Group mark pieces into rows (same height, near each other).
		$rows = array();
		foreach ( $mark_items as $k => $marks ) {
			$placed = false;
			foreach ( $rows as &$row ) {
				if ( abs( $row['y'] - $items[ $k ]['y'] ) <= self::ROW_TOLERANCE && abs( $row['x_end'] - $items[ $k ]['x'] ) < 120 ) {
					$row['parts'][ $k ] = $items[ $k ]['x'];
					$row['x_end']       = max( $row['x_end'], $items[ $k ]['x'] );
					$row['x']           = min( $row['x'], $items[ $k ]['x'] );
					$placed             = true;
					break;
				}
			}
			unset( $row );
			if ( ! $placed ) {
				$rows[] = array(
					'y'     => $items[ $k ]['y'],
					'x'     => $items[ $k ]['x'],
					'x_end' => $items[ $k ]['x'],
					'parts' => array( $k => $items[ $k ]['x'] ),
				);
			}
		}
		foreach ( $rows as &$row ) {
			asort( $row['parts'] );
			$row['marks'] = array();
			foreach ( array_keys( $row['parts'] ) as $k ) {
				$row['marks'] = array_merge( $row['marks'], $mark_items[ $k ] );
			}
		}
		unset( $row );

		// Rows whose marks start at the same place form one table; a page may
		// have two side by side.
		$blocks = array();
		foreach ( $rows as $row ) {
			if ( count( $row['marks'] ) < 2 ) {
				continue;
			}
			$found = false;
			foreach ( $blocks as &$block ) {
				if ( abs( $block['x'] - $row['x'] ) <= 12 ) {
					$block['rows'][] = $row;
					$found           = true;
					break;
				}
			}
			unset( $block );
			if ( ! $found ) {
				$blocks[] = array(
					'x'    => $row['x'],
					'rows' => array( $row ),
				);
			}
		}
		usort(
			$blocks,
			static function ( $a, $b ) {
				return $a['x'] <=> $b['x'];
			}
		);

		$tables = array();
		$left   = -INF;
		foreach ( $blocks as $block ) {
			$table = self::mark_table( $items, $block, $left, $legend );
			// The next table's labels start to the right of this one's marks.
			$left = $block['x'] + 30;
			if ( $table ) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	/**
	 * One mark table from a block of mark rows.
	 */
	private static function mark_table( array $items, array $block, $left, array $legend ) {
		// The column count is what most rows agree on; rows that disagree are
		// not part of this table.
		$counts = array_count_values(
			array_map(
				static function ( $r ) {
					return count( $r['marks'] );
				},
				$block['rows']
			)
		);
		arsort( $counts );
		$cols = (int) key( $counts );
		$rows = array_values(
			array_filter(
				$block['rows'],
				static function ( $r ) use ( $cols ) {
					return count( $r['marks'] ) === $cols;
				}
			)
		);
		if ( count( $rows ) < self::MIN_ROWS ) {
			return null;
		}
		// Dashes alone are how many tables print an empty cell; a mark table
		// needs real marks (● ○ ✓) to be read as one.
		$real = 0;
		foreach ( $rows as $row ) {
			foreach ( $row['marks'] as $mark ) {
				$real += '―' !== $mark ? 1 : 0;
			}
		}
		if ( $real < 2 * self::MIN_ROWS ) {
			return null;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['y'] <=> $a['y'];
			}
		);
		$top    = $rows[0]['y'];
		$bottom = end( $rows )['y'];
		$mark_x = $block['x'];

		// Labels: text left of the marks, within the table's height.
		$labels = array();
		foreach ( $items as $k => $item ) {
			if ( $item['x'] <= $left || $item['x'] >= $mark_x - 2 || $item['y'] > $top + self::LABEL_REACH || $item['y'] < $bottom - self::LABEL_REACH ) {
				continue;
			}
			if ( null !== self::marks_of( $item['text'] ) || self::is_legend( $item['text'] ) ) {
				continue;
			}
			$labels[ $k ] = $item;
		}
		if ( ! $labels ) {
			return null;
		}

		// The item column is where most labels start; anything clearly left
		// of it is a group name ("Machine", "Safety equipment").
		$starts = array();
		foreach ( $labels as $item ) {
			$key            = (string) round( $item['x'] / 4 );
			$starts[ $key ] = isset( $starts[ $key ] ) ? $starts[ $key ] + 1 : 1;
		}
		arsort( $starts );
		$item_x = 4 * (float) key( $starts );

		$row_ys = array_map(
			static function ( $r ) {
				return $r['y'];
			},
			$rows
		);
		$nearest = static function ( $y ) use ( $row_ys ) {
			$best = null;
			foreach ( $row_ys as $i => $ry ) {
				$d = abs( $ry - $y );
				if ( $d <= self::LABEL_REACH && ( null === $best || $d < $best[1] ) ) {
					$best = array( $i, $d );
				}
			}
			return null === $best ? null : $best[0];
		};

		$names  = array_fill( 0, count( $rows ), array() );
		$groups = array_fill( 0, count( $rows ), array() );
		$used   = array();
		$lefts  = array();
		foreach ( $labels as $k => $item ) {
			if ( $item['x'] < $item_x - 8 ) {
				// Footnote markers ("*1") beside a row are not group names.
				if ( ! preg_match( '/^\*\d*$/', $item['text'] ) ) {
					$lefts[ $k ] = $item;
				}
				continue;
			}
			$r = $nearest( $item['y'] );
			if ( null === $r ) {
				continue;
			}
			$names[ $r ][] = $item;
			$used[]        = $k;
		}

		// A group name printed over several lines ("Coolant/", "Chip",
		// "disposal") is one name, and it starts at the row level with its
		// first line.
		uasort(
			$lefts,
			static function ( $a, $b ) {
				return $b['y'] <=> $a['y'];
			}
		);
		$clusters = array();
		foreach ( $lefts as $k => $item ) {
			$last = count( $clusters ) - 1;
			if ( $last >= 0 && abs( end( $clusters[ $last ] )['x'] - $item['x'] ) <= 6 && end( $clusters[ $last ] )['y'] - $item['y'] <= self::LABEL_REACH ) {
				$clusters[ $last ][ $k ] = $item;
			} else {
				$clusters[] = array( $k => $item );
			}
		}
		foreach ( $clusters as $cluster ) {
			$r = $nearest( reset( $cluster )['y'] );
			if ( null === $r ) {
				continue;
			}
			$groups[ $r ] = array_merge( $groups[ $r ], array_values( $cluster ) );
			foreach ( array_keys( $cluster ) as $k ) {
				$used[] = $k;
			}
		}

		// A group name the PDF printed as one piece with the first label on its
		// row ("Machine Main spindle…"): the first word is the group.
		foreach ( $rows as $r => $row ) {
			if ( ! $names[ $r ] && 1 === count( $groups[ $r ] ) && abs( $groups[ $r ][0]['y'] - $row['y'] ) < 1.5 ) {
				$words = explode( ' ', $groups[ $r ][0]['text'], 2 );
				if ( 2 === count( $words ) ) {
					$names[ $r ][]           = array_merge( $groups[ $r ][0], array( 'text' => $words[1] ) );
					$groups[ $r ][0]['text'] = $words[0];
				}
			}
		}

		$header = self::mark_header( $items, $mark_x, $top, $cols, $used );

		// Group names carry down until the next one.
		$has_groups = (bool) array_filter( $groups );
		$group      = '';
		$out_rows   = array();
		foreach ( $rows as $r => $row ) {
			if ( $groups[ $r ] ) {
				$group = self::join_lines( $groups[ $r ] );
			}
			$name = self::join_lines( $names[ $r ] );
			if ( '' === $name ) {
				continue;
			}
			$cells = array();
			foreach ( $row['marks'] as $mark ) {
				$cells[] = isset( $legend[ $mark ] ) ? $legend[ $mark ] : $mark;
			}
			$out_rows[] = array_merge( $has_groups ? array( $group ) : array(), array( $name ), $cells );
			foreach ( array_keys( $row['parts'] ) as $k ) {
				$used[] = $k;
			}
		}
		if ( count( $out_rows ) < self::MIN_ROWS ) {
			return null;
		}

		foreach ( $items as $k => $item ) {
			if ( self::is_legend( $item['text'] ) ) {
				$used[] = $k;
			}
		}

		return array(
			'header' => array_merge( $has_groups ? array( 'Group' ) : array(), array( 'Item' ), $header ),
			'rows'   => $out_rows,
			'used'   => array_values( array_unique( $used ) ),
			'note'   => self::legend_note( $legend ),
		);
	}

	/**
	 * Column headings over the marks, rebuilt from the lines the PDF prints
	 * them on: "i-100H i-200H" over "S ST S ST" across six columns reads as
	 * two groups of three, the first of each unmarked — "i-100H",
	 * "i-100H S", "i-100H ST", "i-200H", … When the lines cannot be fitted,
	 * the columns are numbered.
	 *
	 * @param int[] $used Item keys used, added to.
	 * @return string[]
	 */
	private static function mark_header( array $items, $mark_x, $top, $cols, array &$used ) {
		$lines = array();
		foreach ( $items as $k => $item ) {
			if ( $item['y'] <= $top + 3 || $item['y'] > $top + 40 || $item['x'] < $mark_x - 25 || $item['x'] > $mark_x + 90 ) {
				continue;
			}
			if ( self::is_legend( $item['text'] ) || null !== self::marks_of( $item['text'] ) ) {
				continue;
			}
			$lines[ $k ] = $item;
		}
		uasort(
			$lines,
			static function ( $a, $b ) {
				return $b['y'] <=> $a['y'];
			}
		);
		$tokens = array();
		foreach ( $lines as $item ) {
			$tokens[] = preg_split( '/\s+/', $item['text'] );
		}

		$names = null;
		if ( 1 === count( $tokens ) && count( $tokens[0] ) === $cols ) {
			$names = $tokens[0];
		} elseif ( 2 === count( $tokens ) ) {
			list( $top_line, $sub ) = $tokens;
			$g                      = count( $top_line );
			if ( count( $sub ) === $cols && $g >= 1 && 0 === $cols % $g ) {
				$per   = $cols / $g;
				$names = array();
				foreach ( $sub as $i => $s ) {
					$names[] = $top_line[ (int) floor( $i / $per ) ] . ' ' . $s;
				}
			} elseif ( $g >= 1 && 0 === $cols % $g && 0 === count( $sub ) % $g ) {
				$per  = $cols / $g;
				$each = count( $sub ) / $g;
				if ( $each < $per ) {
					$names = array();
					for ( $grp = 0; $grp < $g; $grp++ ) {
						// The unmarked columns come first: the base model, then its variants.
						for ( $c = 0; $c < $per; $c++ ) {
							$v       = $c - ( $per - $each );
							$names[] = $v >= 0 ? $top_line[ $grp ] . ' ' . $sub[ $grp * $each + $v ] : $top_line[ $grp ];
						}
					}
				}
			} elseif ( count( $top_line ) === $cols ) {
				$names = $top_line;
			}
		}

		if ( null === $names ) {
			$names = array();
			for ( $c = 1; $c <= $cols; $c++ ) {
				/* translators: %d: column number. */
				$names[] = sprintf( __( 'Column %d', 'twt-aeo-ultimate' ), $c );
			}
			return $names;
		}
		foreach ( array_keys( $lines ) as $k ) {
			$used[] = $k;
		}

		return $names;
	}

	/**
	 * The page's key for its marks: "●: Standard", "○: Option", "-: N/A".
	 *
	 * @return array mark => meaning
	 */
	private static function legend( array $items ) {
		$map = array();
		foreach ( $items as $item ) {
			if ( preg_match_all( '/([' . self::MARKS . '])\s*[:：=]\s*([^' . self::MARKS . ':：=]{1,30})/u', $item['text'], $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $pair ) {
					$meaning = trim( $pair[2], " \t,;" );
					if ( '' !== $meaning ) {
						$map[ self::norm_mark( $pair[1] ) ] = $meaning;
					}
				}
			}
		}

		return $map;
	}

	private static function is_legend( $text ) {
		return (bool) preg_match( '/^\s*[' . self::MARKS . ']\s*[:：=]\s*\S/u', $text );
	}

	/** A one-line key under a table whose marks could not be translated. */
	private static function legend_note( array $legend ) {
		return $legend ? '' : __( 'Marks as printed in the document.', 'twt-aeo-ultimate' );
	}

	/* ─────────────────────────── spec tables ─────────────────────────── */

	/**
	 * Label-and-value tables: labels share a left edge; each row has one or
	 * more short values to the right, level with the label (or with the middle
	 * of a label printed on two lines, such as English over Italian).
	 */
	private static function spec_tables( array $items, $text ) {
		// Candidate label columns: left edges shared by several pieces.
		$starts = array();
		foreach ( $items as $k => $item ) {
			$starts[ (string) round( $item['x'] / 3 ) ][] = $k;
		}

		$tables = array();
		$taken  = array();
		foreach ( $starts as $keys ) {
			if ( count( $keys ) < 2 * self::MIN_ROWS ) {
				continue;
			}
			$label_x = $items[ $keys[0] ]['x'];

			// Values: short pieces to the right that hold a number or a
			// one-word value, level with some label.
			$values = array();
			foreach ( $items as $k => $item ) {
				if ( isset( $taken[ $k ] ) || in_array( $k, $keys, true ) || $item['x'] < $label_x + 60 || $item['x'] > $label_x + 520 || mb_strlen( $item['text'] ) > 40 ) {
					continue;
				}
				$values[ $k ] = $item;
			}
			if ( count( $values ) < self::MIN_ROWS ) {
				continue;
			}

			// Rows: values at the same height.
			$rows = array();
			foreach ( $values as $k => $item ) {
				$placed = false;
				foreach ( $rows as &$row ) {
					if ( abs( $row['y'] - $item['y'] ) <= self::ROW_TOLERANCE ) {
						$row['values'][ $k ] = $item['x'];
						$placed              = true;
						break;
					}
				}
				unset( $row );
				if ( ! $placed ) {
					$rows[] = array(
						'y'      => $item['y'],
						'values' => array( $k => $item['x'] ),
					);
				}
			}
			usort(
				$rows,
				static function ( $a, $b ) {
					return $b['y'] <=> $a['y'];
				}
			);

			// Each row takes the label lines closest to it — a label printed on
			// two lines (English over Italian) sits either side of its values.
			$labels = array();
			foreach ( $keys as $k ) {
				$labels[ $k ] = $items[ $k ];
			}
			$found = array();
			foreach ( $rows as $row ) {
				$mine = array();
				foreach ( $labels as $k => $item ) {
					if ( abs( $item['y'] - $row['y'] ) <= self::LABEL_REACH && mb_strlen( $item['text'] ) <= 60 ) {
						$mine[ $k ] = $item;
					}
				}
				if ( ! $mine ) {
					continue;
				}
				// A label line belongs to one row only.
				foreach ( array_keys( $mine ) as $k ) {
					unset( $labels[ $k ] );
				}
				asort( $row['values'] );
				$cells = self::value_cells( $items, array_keys( $row['values'] ) );
				$label = self::join_lines( $mine, ' / ' );
				if ( ! self::looks_like_spec( $label, $cells ) ) {
					continue;
				}
				$found[] = array(
					'y'      => $row['y'],
					'label'  => $label,
					'cells'  => $cells,
					'used'   => array_merge( array_keys( $mine ), array_keys( $row['values'] ) ),
					'first'  => reset( $mine )['text'],
				);
			}

			// A table is one unbroken run of rows. A big gap ends it: what
			// follows is another part of the page (an icon grid, a chart).
			$run = self::longest_run( $found );
			if ( count( $run ) < self::MIN_ROWS + 1 ) {
				continue;
			}
			// A spec table is mostly figures. Rows of words beside words are a
			// layout this cannot read with confidence.
			$figures = 0;
			foreach ( $run as $r ) {
				$figures += preg_match( '/\d/', implode( ' ', $r['cells'] ) ) ? 1 : 0;
			}
			if ( $figures < 0.7 * count( $run ) ) {
				continue;
			}
			// Labels that start mid-phrase ("machining", "(Y axis)") mean the
			// label column was not read whole — values would end up beside
			// half a label.
			$fragments = 0;
			foreach ( $run as $r ) {
				$fragments += preg_match( '/^[\p{Ll}(\[]/u', $r['label'] ) ? 1 : 0;
			}
			if ( $fragments >= 2 || $fragments >= 0.2 * count( $run ) ) {
				continue;
			}

			// When the plain text already pairs most labels with their values
			// on one line, it reads fine as it is: leave the page alone rather
			// than risk moving a value to the wrong row.
			$paired = 0;
			foreach ( $run as $r ) {
				if ( self::paired_in_text( $text, $r['first'], $r['cells'][0] ) ) {
					++$paired;
				}
			}
			if ( $paired >= count( $run ) / 2 ) {
				continue;
			}

			$out  = array();
			$used = array();
			foreach ( $run as $r ) {
				$out[] = array( $r['label'], implode( '; ', $r['cells'] ) );
				$used  = array_merge( $used, $r['used'] );
			}

			// The table's name: the label-column line just above its first row.
			$first_y = $run[0]['y'];
			$title   = '';
			foreach ( $keys as $k ) {
				// A name ("ES789", "Standard Servomotors"), not a row of figures.
				$name = $items[ $k ]['text'];
				if ( $items[ $k ]['y'] > $first_y + self::LABEL_REACH && $items[ $k ]['y'] <= $first_y + 30 && ! in_array( $k, $used, true ) && mb_strlen( $name ) <= 40 && preg_match( '/\p{L}\p{L}/u', $name ) && ! preg_match( '/\d[\d.,]*\s*(?:mm|in|kg|lb|kW|rpm|Nm|\()/u', $name ) ) {
					$title  = $items[ $k ]['text'];
					$used[] = $k;
				}
			}

			foreach ( $used as $k ) {
				$taken[ $k ] = true;
			}
			$tables[] = array(
				'header' => array( '' !== $title ? $title : __( 'Specification', 'twt-aeo-ultimate' ), __( 'Value', 'twt-aeo-ultimate' ) ),
				'rows'   => $out,
				'used'   => $used,
				'note'   => '',
			);
		}

		return $tables;
	}

	/**
	 * A row's values as cells. A unit printed as its own piece ("22", "kW")
	 * joins its number; a symbol split into pieces ("57.3 N", ".", "m") is
	 * put back together.
	 *
	 * @param int[] $keys Item keys, left to right.
	 * @return string[]
	 */
	private static function value_cells( array $items, array $keys ) {
		$cells = array();
		foreach ( $keys as $k ) {
			$text = $items[ $k ]['text'];
			$last = count( $cells ) - 1;
			if ( $last >= 0 && preg_match( '/^(?:[.·•]|mm|cm|m|in|rpm|min-1|kW|W|hp|Nm|m\)?|kg|lbs?\)?|V|A|Hz|bar|psi|MPa|%|°|\(|\))$/u', $text ) ) {
				$glue          = preg_match( '/^[.·•]$/u', $text ) || preg_match( '/[.·•]$/u', $cells[ $last ] ) ? '' : ' ';
				$cells[ $last ] .= $glue . ( '.' === $text ? '•' : $text );
				continue;
			}
			if ( $last >= 0 && preg_match( '/[.·•]$/u', $cells[ $last ] ) && preg_match( '/^[a-z]/', $text ) ) {
				$cells[ $last ] .= $text;
				continue;
			}
			$cells[] = $text;
		}

		return $cells;
	}

	/**
	 * Whether a label and its values look like a row of a spec table: the
	 * label is mostly words, the values mostly figures. Chart axes, captions
	 * and a list of model names do not pass.
	 */
	private static function looks_like_spec( $label, array $cells ) {
		if ( ! $cells || ! preg_match( '/\p{L}{3,}/u', $label ) ) {
			return false;
		}
		// A sentence is a caption or a description, not a spec label.
		// Judged on the first language only: "Max. A-axis torque / Coppia
		// max. asse A" is one label printed twice.
		$first = explode( ' / ', $label )[0];
		$words = preg_split( '/\s+/', trim( $first ) );
		$small = preg_match_all( '/\b(?:for|of|the|a|an|and|or|that|to|with|is|are|ideal|suitable)\b/', $first );
		if ( count( $words ) > 10 || $small >= 3 ) {
			return false;
		}
		// A lone heading in capitals ("CAPACITY", "TRAVEL") beside several
		// values is a section name next to other rows' figures.
		if ( 1 === count( $words ) && count( $cells ) >= 2 && preg_match( '/^[\p{Lu}]{3,}$/u', $label ) ) {
			return false;
		}
		$words = static function ( $s ) {
			$letters = preg_match_all( '/\p{L}/u', $s );
			$digits  = preg_match_all( '/\d/', $s );
			return ( $letters + $digits ) ? $letters / ( $letters + $digits ) : 1.0;
		};
		$value = implode( ' ', $cells );
		// A one-word value ("Liquid", "Asynchronous") is fine beside a worded label.
		if ( ! preg_match( '/\d/', $value ) ) {
			return 1 === count( $cells ) && (bool) preg_match( '/^[\p{L}][\p{L}\/ -]{1,24}$/u', $value ) && $words( $label ) >= 0.85;
		}

		return $words( $label ) - $words( $value ) >= 0.25;
	}

	/**
	 * The longest stretch of rows with no big vertical gap between them.
	 *
	 * @param array[] $rows Top to bottom, each with a 'y'.
	 * @return array[]
	 */
	private static function longest_run( array $rows ) {
		if ( count( $rows ) < 2 ) {
			return $rows;
		}
		$gaps = array();
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$gaps[] = $rows[ $i - 1 ]['y'] - $rows[ $i ]['y'];
		}
		$sorted = $gaps;
		sort( $sorted );
		$typical = $sorted[ (int) floor( count( $sorted ) / 2 ) ];
		$limit   = max( 3 * $typical, 30 );

		$best    = array();
		$current = array( $rows[0] );
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			if ( $gaps[ $i - 1 ] > $limit ) {
				if ( count( $current ) > count( $best ) ) {
					$best = $current;
				}
				$current = array();
			}
			$current[] = $rows[ $i ];
		}

		return count( $current ) > count( $best ) ? $current : $best;
	}

	/** Whether the plain text has the label and its first value on one line. */
	private static function paired_in_text( $text, $label, $value ) {
		$label = trim( (string) $label );
		$value = trim( (string) $value );
		if ( '' === $label || '' === $value ) {
			return false;
		}
		foreach ( preg_split( '/\n/', (string) $text ) as $line ) {
			$at = strpos( $line, $label );
			if ( false !== $at && false !== strpos( $line, $value, $at + strlen( $label ) ) ) {
				return true;
			}
		}

		return false;
	}

	/* ─────────────────────────── output ─────────────────────────── */

	/**
	 * The page text with each table's pieces taken out and the table put in
	 * where its first piece was. Pieces are matched in drawing order, which
	 * is the order getText() writes them.
	 */
	private static function splice( $text, array $items, array $tables ) {
		$owner = array();
		foreach ( $tables as $t => $table ) {
			foreach ( $table['used'] as $k ) {
				if ( ! isset( $owner[ $k ] ) ) {
					$owner[ $k ] = $t;
				}
			}
		}

		$out    = '';
		$cursor = 0;
		$placed = array();
		foreach ( $items as $k => $item ) {
			$needle = trim( $item['raw'] );
			if ( '' === $needle ) {
				continue;
			}
			$pos = strpos( $text, $needle, $cursor );
			if ( false === $pos ) {
				continue;
			}
			if ( ! isset( $owner[ $k ] ) ) {
				continue;
			}
			$out   .= substr( $text, $cursor, $pos - $cursor );
			$cursor = $pos + strlen( $needle );
			$t      = $owner[ $k ];
			if ( ! isset( $placed[ $t ] ) ) {
				$out         .= "\n\n" . self::markdown( $tables[ $t ] ) . "\n\n";
				$placed[ $t ] = true;
			}
		}
		$out .= substr( $text, $cursor );

		// A table whose pieces could not be found in the text still belongs on the page.
		foreach ( $tables as $t => $table ) {
			if ( ! isset( $placed[ $t ] ) ) {
				$out .= "\n\n" . self::markdown( $table );
			}
		}

		// Tidy what the removed pieces left behind.
		$out = preg_replace( '/[ \t]+\n/', "\n", $out );
		$out = preg_replace( '/\n{3,}/', "\n\n", (string) $out );

		return trim( (string) $out );
	}

	/** A table as Markdown, one line per row. */
	public static function markdown( array $table ) {
		$cell = static function ( $v ) {
			return str_replace( array( '|', "\n" ), array( '/', ' ' ), trim( (string) $v ) );
		};
		$lines   = array();
		$lines[] = '| ' . implode( ' | ', array_map( $cell, $table['header'] ) ) . ' |';
		$lines[] = '|' . str_repeat( ' --- |', count( $table['header'] ) );
		foreach ( $table['rows'] as $row ) {
			$row     = array_pad( $row, count( $table['header'] ), '' );
			$lines[] = '| ' . implode( ' | ', array_map( $cell, $row ) ) . ' |';
		}
		if ( '' !== $table['note'] ) {
			$lines[] = '';
			$lines[] = $table['note'];
		}

		return implode( "\n", $lines );
	}

	/** Label lines top to bottom (then left to right), joined. */
	private static function join_lines( array $pieces, $glue = ' ' ) {
		usort(
			$pieces,
			static function ( $a, $b ) {
				// Within 3.5 points is one line (a raised "*1" included), read left to right.
				return abs( $a['y'] - $b['y'] ) > 3.5 ? $b['y'] <=> $a['y'] : $a['x'] <=> $b['x'];
			}
		);
		$text = '';
		foreach ( $pieces as $p ) {
			if ( '' === $text ) {
				$text = $p['text'];
			} elseif ( '/' === substr( $text, -1 ) ) {
				// "indexing/" + "C-axis": one phrase broken across lines.
				$text .= $p['text'];
			} elseif ( preg_match( '/^[\p{Ll}(\[]/u', $p['text'] ) ) {
				// "Rapid" + "traverse", "Milling spindle" + "(HSK-T63…)": the
				// same label carrying on, not a second language.
				$text .= ' ' . $p['text'];
			} else {
				$text .= $glue . $p['text'];
			}
		}

		return trim( $text );
	}
}
