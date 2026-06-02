/* WP.org compliance: extracted from admin/pages/class-page-woocommerce-detector.php inline <script> blocks */
/* global twtAeoWC, ajaxurl */
( function ( $ ) {
	'use strict';

	if ( typeof twtAeoWC === 'undefined' ) return;

	var wcNonce     = twtAeoWC.wcNonce     || '';
	var schemaNonce = twtAeoWC.schemaNonce || '';
	var gmcNonce    = twtAeoWC.gmcNonce    || '';
	var bmcNonce    = twtAeoWC.bmcNonce    || '';
	var i18n        = twtAeoWC.i18n        || {};

	/* ── Section 1: WC Product Schema Modal ─────────────────────────── */
	if ( typeof $.fn.dialog !== 'undefined' ) {
		$( '#twt-aeo-wc-schema-modal' ).dialog( {
			autoOpen:  false,
			modal:     true,
			width:     680,
			resizable: false,
			buttons: [
				{
					text:    i18n.saveSchema || 'Save Schema',
					'class': 'button button-primary',
					click: function () { saveWcSchema(); }
				},
				{
					text:    i18n.cancel || 'Cancel',
					'class': 'button',
					click: function () { $( this ).dialog( 'close' ); }
				}
			]
		} );
	}

	$( document ).on( 'click', '.twt-aeo-wc-generate-schema', function () {
		var postId    = $( this ).data( 'post-id' );
		var postTitle = $( this ).data( 'post-title' );
		$( '#twt-aeo-wc-modal-post-id' ).val( postId );
		$( '#twt-aeo-wc-modal-message' ).hide();
		$.post( ajaxurl, {
			action:  'twtaeo_wc_get_product_data',
			nonce:   wcNonce,
			post_id: postId
		}, function ( r ) {
			if ( r.success ) {
				var d = r.data;
				$( '#twt-aeo-wc-field-name' ).val( d.name || '' );
				$( '#twt-aeo-wc-field-description' ).val( d.description || '' );
				$( '#twt-aeo-wc-field-price' ).val( d.price || '' );
				$( '#twt-aeo-wc-field-currency' ).val( d.currency || 'USD' );
				$( '#twt-aeo-wc-field-sku' ).val( d.sku || '' );
				$( '#twt-aeo-wc-field-availability' ).val( d.availability || '' );
				$( '#twt-aeo-wc-field-image' ).val( d.image_url || '' );
				$( '#twt-aeo-wc-schema-modal' )
					.dialog( 'option', 'title', ( i18n.generateSchemaFor || 'Generate Schema for' ) + ': ' + postTitle )
					.dialog( 'open' );
			}
		} );
	} );

	function saveWcSchema() {
		var postId = $( '#twt-aeo-wc-modal-post-id' ).val();
		$.post( ajaxurl, {
			action:       'twtaeo_wc_generate_all',
			nonce:        wcNonce,
			post_id:      postId,
			name:         $( '#twt-aeo-wc-field-name' ).val(),
			description:  $( '#twt-aeo-wc-field-description' ).val(),
			price:        $( '#twt-aeo-wc-field-price' ).val(),
			currency:     $( '#twt-aeo-wc-field-currency' ).val(),
			sku:          $( '#twt-aeo-wc-field-sku' ).val(),
			availability: $( '#twt-aeo-wc-field-availability' ).val(),
			image_url:    $( '#twt-aeo-wc-field-image' ).val(),
		}, function ( r ) {
			if ( r.success ) {
				$( '#twt-aeo-wc-modal-message' )
					.text( i18n.schemaSaved || 'Schema saved.' )
					.css( { background: '#ecfdf5', color: '#065f46', border: '1px solid #a7f3d0' } )
					.show();
				setTimeout( function () {
					$( '#twt-aeo-wc-schema-modal' ).dialog( 'close' );
					location.reload();
				}, 1200 );
			} else {
				$( '#twt-aeo-wc-modal-message' )
					.text( r.data || ( i18n.saveFailed || 'Save failed.' ) )
					.css( { background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' } )
					.show();
			}
		} );
	}

	/* ── Section 2: Google Merchant Center ──────────────────────────── */
	$( '#twt-aeo-gmc-sync-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( i18n.syncing || 'Syncing…' );
		$.post( ajaxurl, { action: 'twtaeo_gmc_sync', nonce: gmcNonce }, function ( r ) {
			$btn.prop( 'disabled', false ).text( i18n.syncProducts || 'Sync Products' );
			if ( r.success ) {
				location.reload();
			} else {
				alert( r.data || ( i18n.syncFailed || 'Sync failed.' ) );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( i18n.syncProducts || 'Sync Products' );
			alert( i18n.requestFailed || 'Request failed.' );
		} );
	} );

	/* ── Section 3: Bing Merchant Center ────────────────────────────── */
	$( '#twt-aeo-bmc-sync-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( i18n.syncing || 'Syncing…' );
		$.post( ajaxurl, { action: 'twtaeo_bmc_sync', nonce: bmcNonce }, function ( r ) {
			$btn.prop( 'disabled', false ).text( i18n.syncProducts || 'Sync Products' );
			if ( r.success ) {
				location.reload();
			} else {
				alert( r.data || ( i18n.syncFailed || 'Sync failed.' ) );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( i18n.syncProducts || 'Sync Products' );
			alert( i18n.requestFailed || 'Request failed.' );
		} );
	} );
} )( jQuery );
