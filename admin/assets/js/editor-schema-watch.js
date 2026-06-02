/* global wp, twtAeoEditorWatch */
/**
 * TWT AEO — WP 7.0 iframed-editor schema watcher.
 *
 * WordPress 7.0 renders the block editor inside an <iframe>. Scripts running
 * in the outer admin document (including metabox sidebar scripts) cannot reach
 * block elements via document.querySelector. The correct approach is to read
 * live editor state through wp.data.select('core/block-editor'), whose Redux
 * store is shared across the iframe boundary.
 *
 * This script subscribes to the block-editor store and annotates the AEO
 * Status metabox in the sidebar with real-time information about FAQ and
 * Service blocks present in the live editor — without touching the iframe DOM.
 */
( function () {
	'use strict';

	// Only run on post edit screens.
	if ( ! window.wp || ! window.wp.data ) return;

	var select = wp.data.select( 'core/block-editor' );
	if ( ! select ) return;

	var cfg           = window.twtAeoEditorWatch || {};
	var savedHasFaq   = !! cfg.hasFaqSchema;
	var savedHasSvc   = !! cfg.hasServiceSchema;
	var faqModuleOn   = !! cfg.faqModuleOn;
	var svcModuleOn   = !! cfg.svcModuleOn;

	// Known block names that generate (or signal the need for) FAQPage schema.
	var FAQ_BLOCK_NAMES = [
		'yoast/faq-block',
		'rank-math/faq-block',
		'core/details',            // WP 6.5+ expandable section (common Q&A pattern)
		'ub/content-toggle',       // Ultimate Blocks FAQ
		'kadence-blocks/accordion',
		'generateblocks/accordion',
	];

	// Block names commonly found on service pages — used as a supplementary
	// signal (service pages are classified by intent, not a single block type).
	var SERVICE_BLOCK_NAMES = [
		'rank-math/howto-step',
		'wp-schema-pro/service',
	];

	// ── Helpers ───────────────────────────────────────────────────────────────

	// Flatten nested block tree into a single array of block objects.
	function flattenBlocks( blocks ) {
		var flat = [];
		( blocks || [] ).forEach( function ( block ) {
			flat.push( block );
			if ( block.innerBlocks && block.innerBlocks.length ) {
				flat = flat.concat( flattenBlocks( block.innerBlocks ) );
			}
		} );
		return flat;
	}

	function hasAnyBlock( flatList, names ) {
		return flatList.some( function ( b ) {
			return names.indexOf( b.name ) !== -1;
		} );
	}

	// Inject (or update) a live-status annotation div inside the metabox.
	function setLiveNote( id, message, type ) {
		var metabox = document.getElementById( 'twt-aeo-status' );
		if ( ! metabox ) return;

		var note = document.getElementById( id );
		if ( ! note ) {
			note     = document.createElement( 'div' );
			note.id  = id;
			note.style.cssText = [
				'margin-top:8px',
				'padding:6px 8px',
				'border-radius:3px',
				'font-size:11px',
				'line-height:1.4',
			].join( ';' );
			var inner = metabox.querySelector( '.twt-aeo-metabox' );
			if ( inner ) {
				inner.appendChild( note );
			}
		}

		var styles = {
			info:    { bg: '#f0f6fc', border: '#2271b1', color: '#1d4ed8' },
			success: { bg: '#f0fdf4', border: '#16a34a', color: '#15803d' },
			warn:    { bg: '#fffbeb', border: '#d97706', color: '#92400e' },
		};
		var s = styles[ type ] || styles.info;
		note.style.background   = s.bg;
		note.style.borderLeft   = '3px solid ' + s.border;
		note.style.color        = s.color;
		note.style.display      = '';
		note.textContent        = message;
	}

	function removeLiveNote( id ) {
		var note = document.getElementById( id );
		if ( note ) note.style.display = 'none';
	}

	// ── Subscriber ────────────────────────────────────────────────────────────

	var prevFaqBlocks  = null;
	var prevSvcBlocks  = null;

	var unsubscribe = wp.data.subscribe( function () {
		var blocks = select.getBlocks();
		if ( ! blocks ) return;

		var flat         = flattenBlocks( blocks );
		var hasFaqBlock  = hasAnyBlock( flat, FAQ_BLOCK_NAMES );
		var hasSvcBlock  = hasAnyBlock( flat, SERVICE_BLOCK_NAMES );

		// Skip re-renders when nothing changed.
		if ( hasFaqBlock === prevFaqBlocks && hasSvcBlock === prevSvcBlocks ) return;
		prevFaqBlocks = hasFaqBlock;
		prevSvcBlocks = hasSvcBlock;

		// ── FAQ annotation ─────────────────────────────────────────────────
		if ( faqModuleOn ) {
			if ( hasFaqBlock && ! savedHasFaq ) {
				setLiveNote(
					'twt-aeo-faq-live',
					'Live editor: FAQ block detected — FAQPage schema will be added on next save.',
					'info'
				);
			} else if ( hasFaqBlock && savedHasFaq ) {
				setLiveNote(
					'twt-aeo-faq-live',
					'Live editor: FAQ block present — FAQPage schema is active.',
					'success'
				);
			} else {
				removeLiveNote( 'twt-aeo-faq-live' );
			}
		}

		// ── Service annotation ─────────────────────────────────────────────
		if ( svcModuleOn ) {
			if ( hasSvcBlock && ! savedHasSvc ) {
				setLiveNote(
					'twt-aeo-svc-live',
					'Live editor: Service block detected — Service schema will be added on next save.',
					'info'
				);
			} else {
				removeLiveNote( 'twt-aeo-svc-live' );
			}
		}
	} );

	// Clean up if the editor is unmounted (e.g., navigate away).
	window.addEventListener( 'beforeunload', unsubscribe );
} )();
