<?php
/**
 * Document Extractor — turn an uploaded file into Markdown the chunker can cut.
 *
 * PDF text comes from the bundled smalot/pdfparser (pure PHP, no server
 * binaries). Each page becomes a "## Page N" section, so every passage carries
 * the page it came from — the thing a reader needs to go and check it. Word
 * files are read straight from their XML, keeping Heading 1–6 as Markdown
 * headings. Text and Markdown pass through.
 *
 * Every failure comes back as a WP_Error with a sentence the owner can act on:
 * a scanned PDF with no text layer, a password-protected file, a file that is
 * not what its extension claims.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Doc_Extractor {

	/** Fewer characters than this per page means mostly images or diagrams. */
	const MIN_CHARS_PER_PAGE = 20;

	/**
	 * Below this much text in the whole file there is nothing to work with —
	 * a scan. Diagram-heavy guides (assembly, wiring) sit above it: a few
	 * words per page, and those few words are still worth searching.
	 */
	const MIN_TOTAL_CHARS = 200;

	/** Longest line that can be a running header or footer. */
	const MAX_RUNNING_LINE = 70;

	/** Most of a document's text that header/footer stripping may remove. */
	const MAX_RUNNING_SHARE = 0.4;

	private function __construct() {}

	/**
	 * Extract a file's text as Markdown.
	 *
	 * @param string $path Absolute path.
	 * @param string $ext  pdf | docx | txt | md.
	 * @return array|WP_Error { markdown, pages }
	 */
	public static function extract( $path, $ext ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'twtaeo_doc_missing', __( 'The uploaded file could not be read back from the server.', 'twt-aeo-ultimate' ) );
		}

		$types = TWTAEO_Doc_Requirements::types();
		if ( ! isset( $types[ $ext ] ) ) {
			return new WP_Error( 'twtaeo_doc_type', __( 'This file type is not supported. Upload a PDF, Word (.docx), .txt or .md file.', 'twt-aeo-ultimate' ) );
		}
		if ( ! $types[ $ext ]['ok'] ) {
			return new WP_Error( 'twtaeo_doc_server', $types[ $ext ]['reason'] );
		}

		try {
			switch ( $ext ) {
				case 'pdf':
					return self::from_pdf( $path );
				case 'docx':
					return self::from_docx( $path );
				default:
					return self::from_text( $path );
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::log_exception( $e, array( 'context' => 'doc_extract', 'ext' => $ext ) );
			}

			return new WP_Error(
				'twtaeo_doc_parse',
				__( 'The file could not be read. It may be damaged, or saved in a format this reader does not understand. Try re-saving or exporting it as a new PDF, then upload that.', 'twt-aeo-ultimate' )
			);
		}
	}

	/* ─────────────────────────── PDF ─────────────────────────── */

	private static function from_pdf( $path ) {
		self::load_pdf_library();

		// Header check before handing bytes to the parser: a renamed file
		// should say so, not surface as a parse failure.
		$head = (string) file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file we just stored.
		if ( false === strpos( $head, '%PDF-' ) ) {
			return new WP_Error( 'twtaeo_doc_not_pdf', __( 'This file has a .pdf name but is not a PDF inside. Export it as a PDF again and upload that.', 'twt-aeo-ultimate' ) );
		}

		$config = new \Smalot\PdfParser\Config();
		$config->setRetainImageContent( false ); // Images are dead weight here and the biggest memory cost.

		$parser = new \Smalot\PdfParser\Parser( array(), $config );

		try {
			$document = $parser->parseFile( $path );
		} catch ( \Exception $e ) {
			if ( false !== stripos( $e->getMessage(), 'secured' ) || false !== stripos( $e->getMessage(), 'encrypt' ) ) {
				return new WP_Error( 'twtaeo_doc_encrypted', __( 'This PDF is password-protected or encrypted, so its text cannot be read. Save an unprotected copy (in most PDF apps: Print → Save as PDF) and upload that.', 'twt-aeo-ultimate' ) );
			}
			throw $e;
		}

		$pages = $document->getPages();
		$texts = array();
		$chars = 0;
		foreach ( $pages as $i => $page ) {
			$text = $page->getText();
			// Tables come out of a PDF's plain text scrambled — marks far from
			// their rows, values far from their labels. Where a page holds one,
			// rebuild it from where each piece of text sits.
			if ( TWTAEO_PDF_Tables::worth_trying( $text ) ) {
				try {
					$rebuilt = TWTAEO_PDF_Tables::rebuild( $text, $page->getDataTm() );
					if ( null !== $rebuilt ) {
						$text = $rebuilt;
					}
				} catch ( \Throwable $e ) {
					// The plain text is still there to fall back on.
					if ( class_exists( 'TWTAEO_Logger' ) ) {
						TWTAEO_Logger::log_exception( $e, array( 'context' => 'pdf_tables', 'page' => $i + 1 ) );
					}
				}
			}
			$texts[ $i ] = self::tidy( $text );
			// Counted before running lines are stripped: repeated labels
			// ("Step 3", "Fig. 3") are noise for search, but any printed text
			// at all proves the PDF has a text layer and is not a scan.
			$chars += strlen( $texts[ $i ] );
		}
		$texts = self::strip_running_lines( $texts );

		$page_count = count( $pages );
		if ( $chars < self::MIN_TOTAL_CHARS ) {
			return new WP_Error(
				'twtaeo_doc_scanned',
				__( 'This PDF has no readable text — it looks like a scan or a set of images. Run it through OCR first (Adobe Acrobat "Recognize Text", or Google Drive: open with Google Docs), then upload the result.', 'twt-aeo-ultimate' )
			);
		}

		$note = '';
		if ( $chars < max( 1, $page_count ) * self::MIN_CHARS_PER_PAGE ) {
			$note = __( 'Mostly images or diagrams: only the text printed on the pages was read. Labels inside drawings are not text and cannot be searched.', 'twt-aeo-ultimate' );
		}

		return array(
			'markdown' => self::pages_to_markdown( $texts ),
			'pages'    => $page_count,
			'note'     => $note,
		);
	}

	/**
	 * Register an autoloader for the bundled parser. Another plugin may already
	 * have loaded its own copy; if so, that copy is used rather than redeclared.
	 */
	private static function load_pdf_library() {
		static $registered = false;
		if ( $registered || class_exists( '\\Smalot\\PdfParser\\Parser', false ) ) {
			$registered = true;
			return;
		}
		$registered = true;

		$base = TWTAEO_PLUGIN_DIR . 'includes/vendor/smalot-pdfparser/src/';
		spl_autoload_register(
			static function ( $class ) use ( $base ) {
				if ( 0 !== strpos( $class, 'Smalot\\PdfParser\\' ) ) {
					return;
				}
				$file = $base . str_replace( '\\', '/', $class ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/* ─────────────────────────── DOCX ─────────────────────────── */

	private static function from_docx( $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'twtaeo_doc_not_docx', __( 'This file has a .docx name but is not a Word document inside. Open it in Word and use Save As → Word Document (.docx).', 'twt-aeo-ultimate' ) );
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml || '' === $xml ) {
			return new WP_Error( 'twtaeo_doc_not_docx', __( 'This file has a .docx name but is not a Word document inside. Open it in Word and use Save As → Word Document (.docx).', 'twt-aeo-ultimate' ) );
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// LIBXML_NONET: never fetch anything a document references.
		$loaded = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return new WP_Error( 'twtaeo_doc_parse', __( 'This Word document is damaged and could not be read. Open it in Word, save a new copy, and upload that.', 'twt-aeo-ultimate' ) );
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );

		$blocks = array();
		foreach ( $xpath->query( '//w:body//w:p' ) as $p ) {
			$text = '';
			foreach ( $xpath->query( './/w:t|.//w:tab|.//w:br', $p ) as $node ) {
				$text .= 'w:t' === $node->nodeName ? $node->textContent : ( 'w:tab' === $node->nodeName ? "\t" : "\n" );
			}
			$text = self::tidy( $text );
			if ( '' === $text ) {
				continue;
			}

			$style = $xpath->query( './w:pPr/w:pStyle/@w:val', $p );
			$level = 0;
			if ( $style->length && preg_match( '/^(?:Heading|Titre|berschrift)?\s*([1-6])$/i', (string) $style->item( 0 )->nodeValue, $m ) ) {
				$level = (int) $m[1];
			} elseif ( $style->length && 'title' === strtolower( (string) $style->item( 0 )->nodeValue ) ) {
				$level = 1;
			}

			$blocks[] = $level ? str_repeat( '#', $level ) . ' ' . $text : $text;
		}

		if ( empty( $blocks ) ) {
			return new WP_Error( 'twtaeo_doc_empty', __( 'This Word document has no text in it.', 'twt-aeo-ultimate' ) );
		}

		return array(
			'markdown' => implode( "\n\n", $blocks ),
			'pages'    => 0,
		);
	}

	/* ─────────────────────────── text ─────────────────────────── */

	private static function from_text( $path ) {
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file we just stored.

		if ( false !== strpos( $text, "\0" ) ) {
			return new WP_Error( 'twtaeo_doc_binary', __( 'This file is not plain text. Upload .txt or .md files saved as plain text, or upload the original PDF or Word file instead.', 'twt-aeo-ultimate' ) );
		}

		// Strip a UTF-8 BOM; convert legacy Windows-1252 text rather than store mojibake.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( ! self::is_utf8( $text ) && function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( '' === $text ) {
			return new WP_Error( 'twtaeo_doc_empty', __( 'This file is empty.', 'twt-aeo-ultimate' ) );
		}

		return array(
			'markdown' => $text,
			'pages'    => 0,
		);
	}

	/**
	 * Build the document's Markdown from its page texts.
	 *
	 * Every passage keeps the page it came from. When the document has its own
	 * numbered sections — the 16 sections of a safety data sheet ("SECTION 9:
	 * Physical and chemical properties"), a manual's chapters, "4.2 Wiring" —
	 * those become the headings, with the page underneath, so a passage reads
	 * "SECTION 9: Physical and chemical properties › Page 4" instead of
	 * "Page 4". Sections run across pages the way they do in the document.
	 *
	 * @param string[] $texts Page texts, keyed by page index.
	 * @return string
	 */
	private static function pages_to_markdown( array $texts ) {
		$is_heading = self::section_heading_test( $texts );
		$page_label = static function ( $i ) {
			/* translators: %d: page number. */
			return sprintf( __( 'Page %d', 'twt-aeo-ultimate' ), $i + 1 );
		};

		$out = array();
		foreach ( $texts as $i => $text ) {
			if ( '' === $text ) {
				continue;
			}
			if ( ! $is_heading ) {
				$out[] = '## ' . $page_label( $i ) . "\n\n" . $text;
				continue;
			}

			$block = array( '### ' . $page_label( $i ) );
			foreach ( explode( "\n", $text ) as $line ) {
				if ( $is_heading( $line ) ) {
					$block[] = '## ' . trim( $line );
					// Keep the page under the new section too.
					$block[] = '### ' . $page_label( $i );
					continue;
				}
				$block[] = $line;
			}
			$out[] = implode( "\n\n", $block );
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Decide whether this document has numbered section headings, and return a
	 * test for them — or null. A pattern only counts when it recurs, so a
	 * stray "2 Year warranty" line does not become a heading.
	 *
	 * @param string[] $texts
	 * @return callable|null
	 */
	private static function section_heading_test( array $texts ) {
		$named    = '/^(?:SECTION|Section|CHAPTER|Chapter|PART|Part)\s+\d{1,3}\b[\s:.\-–]*\S.{0,80}$/u';
		$numbered = '/^\d{1,2}(?:\.\d{1,2}){0,2}\.?\s+\p{Lu}[^.!?]{2,70}$/u';

		$named_hits    = 0;
		$numbered_hits = 0;
		foreach ( $texts as $text ) {
			foreach ( explode( "\n", $text ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || strlen( $line ) > 90 ) {
					continue;
				}
				if ( preg_match( $named, $line ) ) {
					++$named_hits;
				} elseif ( preg_match( $numbered, $line ) && self::mostly_words( $line ) ) {
					++$numbered_hits;
				}
			}
		}

		$use_named    = $named_hits >= 2;
		$use_numbered = ! $use_named && $numbered_hits >= 3;
		if ( ! $use_named && ! $use_numbered ) {
			return null;
		}

		return static function ( $line ) use ( $use_named, $named, $numbered ) {
			$line = trim( (string) $line );
			if ( '' === $line || strlen( $line ) > 90 ) {
				return false;
			}
			return $use_named
				? (bool) preg_match( $named, $line )
				: ( (bool) preg_match( $numbered, $line ) && self::mostly_words( $line ) );
		};
	}

	/** A heading is words, not a row of a spec table ("3 12 kW 24000 rpm"). */
	private static function mostly_words( $line ) {
		$letters = preg_match_all( '/\p{L}/u', $line );
		$digits  = preg_match_all( '/\d/', $line );

		return $letters >= 6 && $digits <= $letters / 3;
	}

	/**
	 * Remove running headers and footers: lines at the top or bottom of a page
	 * that recur on most pages ("Page 3 of 9 · Acme Spec Sheet", a copyright
	 * line, a confidentiality notice). Left in, they land in every passage and
	 * match every search for the company's own name.
	 *
	 * Lines are compared with digits masked, so "Page 2 of 9" and "Page 7 of 9"
	 * count as the same line. Only the first and last few lines of each page are
	 * considered, and only in documents long enough for repetition to mean
	 * something.
	 *
	 * @param string[] $texts Page texts, keyed by page index.
	 * @return string[]
	 */
	private static function strip_running_lines( array $texts ) {
		$count = count( $texts );
		if ( $count < 3 ) {
			return $texts;
		}

		$edge  = 3; // Lines examined at each end of a page.
		$seen  = array();
		$lines = array();
		foreach ( $texts as $i => $text ) {
			$lines[ $i ] = explode( "\n", $text );
			$total       = count( $lines[ $i ] );
			$keys        = array();
			foreach ( $lines[ $i ] as $n => $line ) {
				// A rebuilt table's rows can open every page of a spec sheet;
				// they are content, not a running header.
				if ( ( $n < $edge || $n >= $total - $edge ) && 0 !== strpos( ltrim( $line ), '|' ) ) {
					$keys[ self::line_key( $line ) ] = true;
				}
			}
			foreach ( array_keys( $keys ) as $key ) {
				if ( '' !== $key ) {
					$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
				}
			}
		}

		$threshold = max( 3, (int) ceil( $count * 0.6 ) );
		$running   = array();
		foreach ( $seen as $key => $pages ) {
			if ( $pages >= $threshold ) {
				$running[ $key ] = true;
			}
		}
		if ( empty( $running ) ) {
			return $texts;
		}

		$stripped = array();
		foreach ( $lines as $i => $page_lines ) {
			$total = count( $page_lines );
			$kept  = array();
			foreach ( $page_lines as $n => $line ) {
				$at_edge = $n < $edge || $n >= $total - $edge;
				if ( $at_edge && isset( $running[ self::line_key( $line ) ] ) ) {
					continue;
				}
				$kept[] = $line;
			}
			$stripped[ $i ] = trim( implode( "\n", $kept ) );
		}

		// Headers and footers are a small share of any real document. If this
		// would remove more than that, the repeats are content (short pages
		// built from the same sentences) — keep everything.
		$before = strlen( implode( '', $texts ) );
		$after  = strlen( implode( '', $stripped ) );
		if ( $before > 0 && ( $before - $after ) / $before > self::MAX_RUNNING_SHARE ) {
			return $texts;
		}

		return $stripped;
	}

	/** Comparison key for a header/footer line: lowercased, digits masked. */
	private static function line_key( $line ) {
		$key = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $line ) ) );
		// Headers and footers are short. A full sentence that repeats with a
		// different number ("Blinking code 4 means…") is content, not a footer.
		if ( strlen( $key ) > self::MAX_RUNNING_LINE ) {
			return '';
		}
		$key = preg_replace( '/\d+/', '#', $key );

		// A bare page number, or a line of a few characters, is too weak to
		// call a running line on repetition alone — except page numbers,
		// which are exactly what we want gone.
		if ( strlen( $key ) < 4 && ! preg_match( '/^#$|^- ?# ?-$/', $key ) ) {
			return '';
		}

		return $key;
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/** Collapse PDF spacing noise while keeping paragraph breaks. */
	private static function tidy( $text ) {
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), (string) $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( (string) $text );
	}

	private static function is_utf8( $text ) {
		return function_exists( 'mb_check_encoding' )
			? mb_check_encoding( $text, 'UTF-8' )
			: (bool) preg_match( '//u', $text );
	}
}
