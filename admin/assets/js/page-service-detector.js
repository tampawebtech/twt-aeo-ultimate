/* WP.org compliance: extracted from admin/pages/class-page-service-detector.php inline script block */
/* global twtAeoServiceDetector, ajaxurl */
jQuery( document ).ready( function ( $ ) {
	'use strict';

	var d = window.twtAeoServiceDetector || {};
	var i = d.i18n || {};
	var nonce = d.nonce || '';

	$( '#twt-aeo-schema-modal' ).dialog( {
		autoOpen:  false,
		modal:     true,
		width:     620,
		resizable: false,
		buttons: [
			{
				text:    i.saveSchema || 'Save Schema',
				'class': 'button button-primary',
				click:   function () { saveSchema(); }
			},
			{
				text:    i.cancel || 'Cancel',
				'class': 'button',
				click:   function () { $( this ).dialog( 'close' ); }
			}
		]
	} );

	$( document ).on( 'click', '.twt-aeo-generate-schema', function () {
		var postId    = $( this ).data( 'post-id' );
		var postTitle = $( this ).data( 'post-title' );

		$( '#twt-aeo-modal-post-id' ).val( postId );
		$( '#twt-aeo-modal-message' ).hide();

		$( '#twt-aeo-field-name' ).val( postTitle );
		$( '#twt-aeo-field-description, #twt-aeo-field-service-type, #twt-aeo-field-area-served, #twt-aeo-field-provider-name, #twt-aeo-field-phone' ).val( '' );

		$.post( ajaxurl, {
			action:  'twtaeo_get_service_prefill',
			nonce:   nonce,
			post_id: postId
		}, function ( response ) {
			if ( response.success ) {
				var data = response.data;
				if ( data.name )          $( '#twt-aeo-field-name' ).val( data.name );
				if ( data.description )   $( '#twt-aeo-field-description' ).val( data.description );
				if ( data.service_type )  $( '#twt-aeo-field-service-type' ).val( data.service_type );
				if ( data.area_served )   $( '#twt-aeo-field-area-served' ).val( data.area_served );
				if ( data.provider_name ) $( '#twt-aeo-field-provider-name' ).val( data.provider_name );
				if ( data.phone )         $( '#twt-aeo-field-phone' ).val( data.phone );
			}
		} );

		$( '#twt-aeo-schema-modal' )
			.dialog( 'option', 'title', ( i.generateSchemaTitle || 'Generate Service Schema' ) + ': ' + postTitle )
			.dialog( 'open' );
	} );

	function saveSchema() {
		var postId = $( '#twt-aeo-modal-post-id' ).val();
		var name   = $( '#twt-aeo-field-name' ).val().trim();

		if ( ! name ) {
			showMessage( i.nameRequired || 'Service Name is required.', 'error' );
			return;
		}

		$.post( ajaxurl, {
			action:        'twtaeo_save_service_schema',
			nonce:         nonce,
			post_id:       postId,
			name:          name,
			description:   $( '#twt-aeo-field-description' ).val(),
			service_type:  $( '#twt-aeo-field-service-type' ).val(),
			area_served:   $( '#twt-aeo-field-area-served' ).val(),
			provider_name: $( '#twt-aeo-field-provider-name' ).val(),
			phone:         $( '#twt-aeo-field-phone' ).val()
		}, function ( response ) {
			if ( response.success ) {
				showMessage( i.schemaSaved || 'Schema saved. It will now appear on the frontend.', 'success' );
				setTimeout( function () {
					$( '#twt-aeo-schema-modal' ).dialog( 'close' );
					location.reload();
				}, 1200 );
			} else {
				showMessage( response.data || ( i.saveFailed || 'Save failed. Please try again.' ), 'error' );
			}
		} );
	}

	$( document ).on( 'click', '.twt-aeo-delete-schema', function () {
		if ( ! confirm( i.deleteConfirm || 'Remove the TWT-generated Service schema from this page?' ) ) {
			return;
		}
		var postId = $( this ).data( 'post-id' );
		$.post( ajaxurl, {
			action:  'twtaeo_delete_service_schema',
			nonce:   nonce,
			post_id: postId
		}, function ( response ) {
			if ( response.success ) location.reload();
		} );
	} );

	function showMessage( msg, type ) {
		var $m = $( '#twt-aeo-modal-message' );
		$m.text( msg )
		  .css( 'background', type === 'success' ? '#ecfdf5' : '#fef2f2' )
		  .css( 'color',      type === 'success' ? '#065f46' : '#991b1b' )
		  .css( 'border', '1px solid ' + ( type === 'success' ? '#a7f3d0' : '#fecaca' ) )
		  .show();
	}
} );
