/* WP.org compliance: extracted from admin/pages/class-page-eeat.php inline <script> */
/* global twtAeoEeat, ajaxurl */
jQuery( document ).ready( function ( $ ) {
	'use strict';

	var cfg   = window.twtAeoEeat || {};
	var i18n  = cfg.i18n  || {};
	var nonce = cfg.nonce || '';
	var authorFields = cfg.authorFields || [];

	// ── Create Page ───────────────────────────────────────────────────────────
	$( document ).on( 'click', '.twt-aeo-create-page', function () {
		var $btn    = $( this );
		var title   = $btn.data( 'title' );
		var slug    = $btn.data( 'slug' );
		var content = $btn.data( 'content' );

		$btn.prop( 'disabled', true ).text( i18n.creating || 'Creating…' );

		$.post( ajaxurl, {
			action:       'twtaeo_create_eeat_page',
			nonce:        nonce,
			page_title:   title,
			page_slug:    slug,
			page_content: content
		}, function ( r ) {
			if ( r.success && r.data.edit_url ) {
				window.open( r.data.edit_url, '_blank' );
				$btn.text( i18n.pageCreated || 'Page Created ✓' )
				    .removeClass( 'button-primary' )
				    .prop( 'disabled', true );
			} else {
				$btn.prop( 'disabled', false ).text( i18n.createPage || 'Create Page' );
				alert( r.data || ( i18n.failedCreate || 'Failed to create page.' ) );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( i18n.createPage || 'Create Page' );
			alert( i18n.requestFailed || 'Request failed. Please try again.' );
		} );
	} );

	// ── sameAs modal ──────────────────────────────────────────────────────────
	$( '#twt-aeo-sameas-modal' ).dialog( {
		autoOpen:  false,
		modal:     true,
		width:     560,
		resizable: false,
		buttons: [
			{
				text:    i18n.saveLinks || 'Save Links',
				'class': 'button button-primary',
				click:   function () { saveSameAs(); }
			},
			{
				text:    i18n.cancel || 'Cancel',
				'class': 'button',
				click:   function () { $( this ).dialog( 'close' ); }
			}
		]
	} );

	$( document ).on( 'click', '.twt-aeo-open-sameas', function () {
		$( '#twt-sameas-message' ).hide();
		$.post( ajaxurl, { action: 'twtaeo_get_sameas', nonce: nonce }, function ( r ) {
			if ( r.success ) {
				var d = r.data;
				$( '#twt-sameas-linkedin' ).val( d.linkedin  || '' );
				$( '#twt-sameas-facebook' ).val( d.facebook  || '' );
				$( '#twt-sameas-twitter'  ).val( d.twitter   || '' );
				$( '#twt-sameas-instagram').val( d.instagram || '' );
				$( '#twt-sameas-youtube'  ).val( d.youtube   || '' );
				$( '#twt-sameas-google'   ).val( d.google    || '' );
			}
		} );
		$( '#twt-aeo-sameas-modal' ).dialog( 'open' );
	} );

	function saveSameAs() {
		$.post( ajaxurl, {
			action:    'twtaeo_save_sameas',
			nonce:     nonce,
			linkedin:  $( '#twt-sameas-linkedin'  ).val(),
			facebook:  $( '#twt-sameas-facebook'  ).val(),
			twitter:   $( '#twt-sameas-twitter'   ).val(),
			instagram: $( '#twt-sameas-instagram' ).val(),
			youtube:   $( '#twt-sameas-youtube'   ).val(),
			google:    $( '#twt-sameas-google'    ).val(),
		}, function ( r ) {
			if ( r.success ) {
				$( '#twt-sameas-message' )
					.text( i18n.linksSaved || 'Links saved. Rescanning…' )
					.css( { background: '#ecfdf5', color: '#065f46', border: '1px solid #a7f3d0' } )
					.show();
				setTimeout( function () {
					$( '#twt-aeo-sameas-modal' ).dialog( 'close' );
					location.reload();
				}, 1000 );
			} else {
				$( '#twt-sameas-message' )
					.text( r.data || ( i18n.saveFailed || 'Save failed.' ) )
					.css( { background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' } )
					.show();
			}
		} );
	}

	// ── Author edit modal ─────────────────────────────────────────────────────
	$( '#twt-aeo-author-modal' ).dialog( {
		autoOpen:  false,
		modal:     true,
		width:     600,
		resizable: false,
		buttons: [
			{
				text:    i18n.save || 'Save',
				'class': 'button button-primary',
				click:   function () { saveAuthor(); }
			},
			{
				text:    i18n.cancel || 'Cancel',
				'class': 'button',
				click:   function () { $( this ).dialog( 'close' ); }
			}
		]
	} );

	$( document ).on( 'click', '.twt-aeo-edit-author', function () {
		var userId   = $( this ).data( 'user-id' );
		var userName = $( this ).data( 'user-name' );

		$( '#twt-author-user-id' ).val( userId );
		$( '#twt-author-message' ).hide();

		$.post( ajaxurl, {
			action:  'twtaeo_get_author_meta',
			nonce:   nonce,
			user_id: userId
		}, function ( r ) {
			if ( r.success ) {
				var d = r.data;
				$( '#twt-author-bio' ).val( d.bio || '' );
				authorFields.forEach( function ( key ) {
					$( '#twt-author-' + key ).val( d[ key ] || '' );
				} );
			}
		} );

		$( '#twt-aeo-author-modal' )
			.dialog( 'option', 'title', ( i18n.editAuthorSignals || 'Edit Author Signals' ) + ': ' + userName )
			.dialog( 'open' );
	} );

	function saveAuthor() {
		var data = {
			action:  'twtaeo_save_author_meta',
			nonce:   nonce,
			user_id: $( '#twt-author-user-id' ).val(),
			bio:     $( '#twt-author-bio' ).val(),
		};
		authorFields.forEach( function ( key ) {
			data[ key ] = $( '#twt-author-' + key ).val();
		} );

		$.post( ajaxurl, data, function ( r ) {
			if ( r.success ) {
				$( '#twt-author-message' )
					.text( i18n.savedSuccessfully || 'Saved successfully.' )
					.css( { background: '#ecfdf5', color: '#065f46', border: '1px solid #a7f3d0' } )
					.show();
				setTimeout( function () {
					$( '#twt-aeo-author-modal' ).dialog( 'close' );
					location.reload();
				}, 1000 );
			} else {
				$( '#twt-author-message' )
					.text( r.data || ( i18n.saveFailed || 'Save failed.' ) )
					.css( { background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' } )
					.show();
			}
		} );
	}
} );
