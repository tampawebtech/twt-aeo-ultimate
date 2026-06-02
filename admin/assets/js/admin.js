/* global twtAeo, jQuery */
( function ( $ ) {
	'use strict';

	/**
	 * Module toggle handler.
	 * Sends AJAX request when a module toggle is switched.
	 */
	$( document ).on( 'change', '.js-module-toggle', function () {
		var $input = $( this );
		var $card  = $input.closest( '.twt-aeo-module-card' );
		var slug   = $input.data( 'slug' );
		var active = $input.is( ':checked' );

		$card.addClass( 'is-saving' );

		$.ajax( {
			url:  twtAeo.ajaxUrl,
			type: 'POST',
			data: {
				action: 'twtaeo_toggle_module',
				nonce:  twtAeo.nonce,
				slug:   slug,
				active: active ? 1 : 0,
			},
			success: function ( response ) {
				if ( response.success ) {
					$card.removeClass( 'is-saving' );
					if ( active ) {
						$card.addClass( 'twt-aeo-module-card--active' );
					} else {
						$card.removeClass( 'twt-aeo-module-card--active' );
					}
					// Briefly show the new state, then reload so the sidebar menu updates.
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					// Revert on failure.
					$input.prop( 'checked', ! active );
					$card.removeClass( 'is-saving' );
				}
			},
			error: function () {
				$input.prop( 'checked', ! active );
				$card.removeClass( 'is-saving' );
			},
		} );
	} );

	/**
	 * Author Entity — schema preview toggle.
	 */
	$( document ).on( 'click', '.twt-aeo-schema-toggle', function () {
		var userId  = $( this ).data( 'user' );
		var $panel  = $( '#twt-aeo-schema-' + userId );
		var $btn    = $( this );

		if ( $panel.is( ':visible' ) ) {
			$panel.slideUp( 150 );
			$btn.text( '<\/> ' + ( twtAeo.schemaPreviewText || 'Schema Preview' ) );
		} else {
			$panel.slideDown( 150 );
			$btn.text( '▲ ' + ( twtAeo.hidePreviewText || 'Hide Preview' ) );
		}
	} );

} )( jQuery );