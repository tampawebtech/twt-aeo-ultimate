/* WP.org compliance: extracted from admin/pages/class-page-faq-detector.php inline <script> */
/* global twtAeoFaqDetector, ajaxurl */
( function ( $ ) {
	'use strict';

	var d = window.twtAeoFaqDetector || {};
	var i = d.i18n || {};

	$( '#twt-aeo-faq-gen-all' ).on( 'click', function () {
		var btn    = $( this );
		var nonce  = btn.data( 'nonce' );
		var status = $( '#twt-aeo-faq-gen-all-status' );

		var ids = [];
		$( '.twt-aeo-faq-gen-one' ).each( function () {
			ids.push( $( this ).data( 'post-id' ) );
		} );

		if ( ! ids.length ) {
			status.css( 'color', '#16a34a' ).text( i.nothingToGenerate || 'Nothing to generate.' );
			return;
		}

		btn.prop( 'disabled', true );
		var done = 0, skipped = 0, total = ids.length;

		function processNext() {
			if ( ! ids.length ) {
				btn.prop( 'disabled', false );
				status.css( 'color', '#16a34a' ).text(
					( i.done || 'Done' ) + ' — ' +
					done + ' ' + ( i.saved || 'saved' ) +
					( skipped ? ', ' + skipped + ' ' + ( i.skipped || 'skipped' ) : '' )
				);
				return;
			}

			var postId = ids.shift();
			status.css( 'color', '' ).text(
				( i.processing || 'Processing' ) + ' ' +
				( total - ids.length ) + ' / ' + total + '…'
			);

			$.post( ajaxurl, {
				action:  'twtaeo_faq_generate_batch',
				nonce:   nonce,
				post_id: postId
			}, function ( r ) {
				if ( r.success ) {
					if ( r.data.skipped ) {
						skipped++;
					} else {
						done++;
						var rowBtn    = $( '.twt-aeo-faq-gen-one[data-post-id="' + postId + '"]' );
						var rowResult = $( '.twt-aeo-faq-gen-one-result[data-post-id="' + postId + '"]' );
						rowBtn.text( i.regenerate || 'Regenerate' );
						rowResult.css( 'color', '#16a34a' ).text( ' ✓ ' + r.data.qa_count + ' Q&A saved' );
					}
				} else {
					skipped++;
				}
				processNext();
			} ).fail( function () {
				skipped++;
				processNext();
			} );
		}

		processNext();
	} );

	$( document ).on( 'click', '.twt-aeo-faq-gen-one', function () {
		var btn    = $( this );
		var postId = btn.data( 'post-id' );
		var nonce  = btn.data( 'nonce' );
		var result = $( '.twt-aeo-faq-gen-one-result[data-post-id="' + postId + '"]' );

		btn.prop( 'disabled', true ).text( i.saving || 'Saving…' );
		result.text( '' ).removeAttr( 'style' );

		$.post( ajaxurl, {
			action:  'twtaeo_faq_generate_one',
			nonce:   nonce,
			post_id: postId
		}, function ( r ) {
			if ( r.success ) {
				btn.prop( 'disabled', false ).text( i.regenerate || 'Regenerate' );
				result.css( 'color', '#16a34a' ).text( ' ✓ ' + r.data.qa_count + ' Q&A saved' );
			} else {
				btn.prop( 'disabled', false ).text( i.addFaqSchema || 'Add FAQ Schema' );
				result.css( 'color', '#dc2626' ).text( ' ✗ ' + ( ( r.data && r.data.message ) || 'Error' ) );
			}
		} ).fail( function () {
			btn.prop( 'disabled', false ).text( i.addFaqSchema || 'Add FAQ Schema' );
			result.css( 'color', '#dc2626' ).text( ' ✗ Request failed' );
		} );
	} );
} )( jQuery );
