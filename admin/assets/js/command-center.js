/* global twtAeoCC, jQuery, wp */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;


	// ── Copy Redirect URI ──────────────────────────────────────────────────

	$( document ).on( 'click', '.twt-aeo-cc__copy-btn', function () {
		var $btn    = $( this );
		var targetId = $btn.data( 'copy-target' );
		var text    = $( '#' + targetId ).text().trim();

		if ( ! navigator.clipboard ) {
			// Fallback for older browsers.
			var $tmp = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
			document.execCommand( 'copy' );
			$tmp.remove();
			$btn.text( twtAeoCC.copiedText || 'Copied!' ).addClass( 'copied' );
			setTimeout( function () {
				$btn.text( twtAeoCC.copyText || 'Copy' ).removeClass( 'copied' );
			}, 2000 );
			return;
		}

		navigator.clipboard.writeText( text ).then( function () {
			$btn.text( twtAeoCC.copiedText || 'Copied!' ).addClass( 'copied' );
			setTimeout( function () {
				$btn.text( twtAeoCC.copyText || 'Copy' ).removeClass( 'copied' );
			}, 2000 );
		} );
	} );

	// ── Disconnect Buttons (Google & Bing) ────────────────────────────────

	$( document ).on( 'click', '.twt-aeo-cc__disconnect-btn', function () {
		var $btn    = $( this );
		var service = $btn.data( 'service' );
		var nonce   = $btn.data( 'nonce' );
		var label   = service === 'google' ? 'Google' : 'Bing Webmaster Tools';

		if ( ! confirm( ( twtAeoCC.disconnectConfirm || 'Disconnect {service}?' ).replace( '{service}', label ) ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( twtAeoCC.disconnectingText || 'Disconnecting…' );

		$.post( twtAeoCC.ajaxUrl, {
			action : 'twtaeo_cc_disconnect',
			service: service,
			nonce  : nonce,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				window.location.href = response.data.redirect || window.location.href;
			} else {
				alert( response.data || __( 'Disconnect failed.', 'twt-aeo-ultimate' ) );
				$btn.prop( 'disabled', false ).text( twtAeoCC.disconnectText || 'Disconnect' );
			}
		} )
		.fail( function () {
			alert( __( 'Request failed. Please try again.', 'twt-aeo-ultimate' ) );
			$btn.prop( 'disabled', false ).text( twtAeoCC.disconnectText || 'Disconnect' );
		} );
	} );

} )( jQuery );
