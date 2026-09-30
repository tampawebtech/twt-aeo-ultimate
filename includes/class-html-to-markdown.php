<?php
/**
 * HTML to Markdown Converter
 *
 * Pure-PHP conversion for AI-readable content. No external dependencies.
 *
 * Built for what real pages contain, not just clean post HTML: Custom HTML
 * blocks and page builders arrive deeply indented, full of wrapper divs and
 * whitespace-only lines. Two things in that source would otherwise change
 * what an agent reads:
 *  - leading indentation of four spaces or more makes a Markdown line a
 *    code block, so every line is left-trimmed (except inside <pre>);
 *  - tables flattened to loose cells lose which value belongs to which
 *    column, so tables become Markdown tables.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_HTML_To_Markdown {

	public static function convert( $html ) {
		// Strip non-content elements before any conversion.
		$html = preg_replace(
			'/<(script|style|nav|header|footer|aside|form|noscript|svg|template)[^>]*>.*?<\/\1>/is',
			'',
			(string) $html
		);

		// <pre> keeps its exact text: set aside behind placeholders so neither
		// the tag stripping nor the indentation trim below touches it.
		$blocks = array();
		$html   = preg_replace_callback(
			'/<pre[^>]*>(.*?)<\/pre>/is',
			function ( $m ) use ( &$blocks ) {
				// Plain tag removal: wp_strip_all_tags() trims, which would eat the
				// first line's indentation.
				$code     = html_entity_decode( (string) preg_replace( '/<[^>]*>/', '', $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$code     = trim( $code, "\r\n" );
				$key      = "\x1ATWTAEOPRE" . count( $blocks ) . "\x1A";
				$blocks[ $key ] = "```\n" . rtrim( $code ) . "\n```";
				return "\n\n" . $key . "\n\n";
			},
			$html
		);

		// Hyperlinks — only keep if href is meaningful. Converted before tables
		// and headings, which flatten their contents to text: a link inside a
		// heading ("<h3><a>HSD ES Series Repair</a></h3>") or a table cell would
		// otherwise be lost, and it is often the most useful thing there.
		$html = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $m ) {
				$href = self::absolute_url( trim( $m[1] ) );
				// A card link can wrap a heading and paragraphs: flatten it to
				// one line so the link stays a link.
				$text = self::inline_text( $m[2] );
				if ( ! $href || ! $text || '#' === $href[0] ) {
					return $text;
				}
				return '[' . $text . '](' . $href . ')';
			},
			$html
		);

		// Tables, before anything else rewrites the cells.
		$html = preg_replace_callback(
			'/<table[^>]*>(.*?)<\/table>/is',
			function ( $m ) {
				return "\n\n" . self::table( $m[1] ) . "\n\n";
			},
			$html
		);

		// Headings.
		$html = preg_replace_callback(
			'/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
			function ( $m ) {
				return "\n\n" . str_repeat( '#', (int) $m[1] ) . ' ' . self::inline_text( $m[2] ) . "\n\n";
			},
			$html
		);

		// Blockquotes.
		$html = preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			function ( $m ) {
				$inner = trim( (string) preg_replace( '/[ \t]*\n\s*/', "\n", wp_strip_all_tags( $m[1] ) ) );
				return "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";
			},
			$html
		);

		// Bold.
		$html = preg_replace( '/<(strong|b)(?:\s[^>]*)?>(.*?)<\/\1>/is', '**$2**', $html );

		// Italic.
		$html = preg_replace( '/<(em|i)(?:\s[^>]*)?>(.*?)<\/\1>/is', '*$2*', $html );

		// Code.
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );

		// Images — alt text only.
		$html = preg_replace_callback(
			'/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i',
			function ( $m ) {
				$alt = trim( $m[1] );
				return $alt ? '[Image: ' . $alt . ']' : '';
			},
			$html
		);

		// Ordered list items.
		$html = preg_replace_callback(
			'/<ol[^>]*>(.*?)<\/ol>/is',
			function ( $m ) {
				// Items only, joined by single newlines: the source whitespace
				// between <li>s would otherwise make a loose, blank-line list.
				preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $m[1], $items );
				$lines = array();
				foreach ( $items[1] as $i => $item ) {
					$lines[] = ( $i + 1 ) . '. ' . self::inline_text( $item );
				}
				return "\n\n" . implode( "\n", $lines ) . "\n\n";
			},
			$html
		);

		// Unordered list items.
		$html = preg_replace_callback(
			'/<ul[^>]*>(.*?)<\/ul>/is',
			function ( $m ) {
				preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $m[1], $items );
				$lines = array();
				foreach ( $items[1] as $item ) {
					$lines[] = '- ' . self::inline_text( $item );
				}
				return "\n\n" . implode( "\n", $lines ) . "\n\n";
			},
			$html
		);

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*>/i', "\n\n---\n\n", $html );

		// Line breaks.
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Block-level elements → paragraph breaks.
		$html = preg_replace( '/<\/?(p|div|section|article|main|figure|figcaption|details|summary|dl|dt|dd)(?:\s[^>]*)?>/i', "\n\n", $html );

		// Strip any remaining tags.
		$html = wp_strip_all_tags( $html );

		// Decode HTML entities.
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Source indentation and whitespace-only lines: trim every line (a line
		// indented 4+ spaces would read as code), collapse runs of spaces inside
		// a line, then collapse blank lines.
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $html ) );
		foreach ( $lines as &$line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			// Lists typed as characters ("• Deckel<br>• Euro") become real items.
			$line = (string) preg_replace( '/^[\x{2022}\x{25AA}\x{25CF}\x{25E6}]\s*/u', '- ', $line );
		}
		unset( $line );
		$html = preg_replace( '/\n{3,}/', "\n\n", trim( implode( "\n", $lines ) ) );

		return strtr( $html, $blocks );
	}

	/**
	 * A <table> as a Markdown table. The first row is the header (Markdown
	 * requires one); every row is padded to the widest row.
	 *
	 * @param string $inner The table's inner HTML.
	 * @return string
	 */
	private static function table( $inner ) {
		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $inner, $rows );
		$grid = array();
		foreach ( $rows[1] as $row ) {
			preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $row, $cells );
			if ( empty( $cells[1] ) ) {
				continue;
			}
			$grid[] = array_map(
				function ( $cell ) {
					return str_replace( '|', '\|', self::inline_text( $cell ) );
				},
				$cells[1]
			);
		}
		if ( ! $grid ) {
			return '';
		}

		$width = max( array_map( 'count', $grid ) );
		$out   = array();
		foreach ( $grid as $i => $cells ) {
			$cells = array_pad( $cells, $width, '' );
			$out[] = '| ' . implode( ' | ', $cells ) . ' |';
			if ( 0 === $i ) {
				$out[] = '|' . str_repeat( ' --- |', $width );
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Inner HTML as one line of text: every tag becomes a space (so
	 * "<b>AEO Ultimate</b><span>Best for AI</span>" does not run together),
	 * Markdown emphasis already applied is kept, whitespace collapses.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function inline_text( $html ) {
		$text = (string) preg_replace( '/<[^>]*>/', ' ', (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Root-relative links ("/contact/") made absolute: a Markdown copy is read
	 * away from the page, where a relative link leads nowhere.
	 *
	 * @param string $href
	 * @return string
	 */
	private static function absolute_url( $href ) {
		if ( '' === $href || '/' !== $href[0] || 0 === strpos( $href, '//' ) ) {
			return $href;
		}
		$home   = wp_parse_url( home_url() );
		$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' );
		if ( isset( $home['port'] ) ) {
			$origin .= ':' . $home['port'];
		}
		return $origin . $href;
	}
}
