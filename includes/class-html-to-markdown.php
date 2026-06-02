<?php
/**
 * HTML to Markdown Converter
 *
 * Pure-PHP conversion for AI-readable content. No external dependencies.
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
			'/<(script|style|nav|header|footer|aside|form|noscript)[^>]*>.*?<\/\1>/is',
			'',
			$html
		);

		// Headings.
		$html = preg_replace_callback(
			'/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
			function ( $m ) {
				return "\n\n" . str_repeat( '#', (int) $m[1] ) . ' ' . trim( wp_strip_all_tags( $m[2] ) ) . "\n\n";
			},
			$html
		);

		// Blockquotes.
		$html = preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			function ( $m ) {
				$inner = trim( wp_strip_all_tags( $m[1] ) );
				return "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";
			},
			$html
		);

		// Bold.
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $html );

		// Italic.
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $html );

		// Code.
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );

		// Hyperlinks — only keep if href is meaningful.
		$html = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $m ) {
				$href = trim( $m[1] );
				$text = trim( wp_strip_all_tags( $m[2] ) );
				if ( ! $href || ! $text || $href === '#' ) {
					return $text;
				}
				return '[' . $text . '](' . $href . ')';
			},
			$html
		);

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
				$i    = 1;
				$list = preg_replace_callback(
					'/<li[^>]*>(.*?)<\/li>/is',
					function ( $li ) use ( &$i ) {
						return "\n" . $i++ . '. ' . trim( wp_strip_all_tags( $li[1] ) );
					},
					$m[1]
				);
				return "\n\n" . ltrim( $list ) . "\n\n";
			},
			$html
		);

		// Unordered list items.
		$html = preg_replace_callback(
			'/<ul[^>]*>(.*?)<\/ul>/is',
			function ( $m ) {
				$list = preg_replace_callback(
					'/<li[^>]*>(.*?)<\/li>/is',
					function ( $li ) {
						return "\n- " . trim( wp_strip_all_tags( $li[1] ) );
					},
					$m[1]
				);
				return "\n\n" . ltrim( $list ) . "\n\n";
			},
			$html
		);

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*>/i', "\n\n---\n\n", $html );

		// Line breaks.
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Paragraphs and divs → double newlines.
		$html = preg_replace( '/<\/?(p|div|section|article)[^>]*>/i', "\n\n", $html );

		// Strip any remaining tags.
		$html = wp_strip_all_tags( $html );

		// Decode HTML entities.
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse excessive blank lines.
		$html = preg_replace( '/\n{3,}/', "\n\n", trim( $html ) );

		return $html;
	}
}
