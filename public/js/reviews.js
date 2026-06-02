/* global twtAeoReviews, jQuery, wp */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;


	$( document ).on( 'submit', '.twt-review-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $wrap   = $form.closest( '.twt-review-form-wrap' );
		var $notice = $wrap.find( '.twt-review-form-notice' );
		var $btn    = $form.find( '.twt-review-submit' );
		var $label  = $btn.find( '.twt-submit-label' );
		var $spin   = $btn.find( '.twt-submit-spinner' );

		$notice.hide().removeClass( 'is-success is-error' );
		$btn.prop( 'disabled', true );
		$label.css( 'opacity', 0.6 );
		$spin.show();

		var data = {
			action:         twtAeoReviews.action,
			nonce:          twtAeoReviews.nonce,
			post_id:        $form.find( '[name="post_id"]' ).val() || twtAeoReviews.postId,
			reviewer_name:  $form.find( '[name="reviewer_name"]' ).val(),
			reviewer_email: $form.find( '[name="reviewer_email"]' ).val(),
			rating:         $form.find( '[name="rating"]:checked' ).val() || 5,
			review_content: $form.find( '[name="review_content"]' ).val(),
		};

		$.post( twtAeoReviews.ajaxUrl, data )
			.done( function ( response ) {
				if ( response.success ) {
					var msg = ( response.data && response.data.message )
						? response.data.message
						: __( 'Thank you for your review!', 'twt-aeo-ultimate' );
					$notice.addClass( 'is-success' ).text( msg ).show();
					$form[0].reset();
					// Reset star picker to 5
					$form.find( '#twt-star-5' ).prop( 'checked', true );
				} else {
					var err = ( response.data )
						? response.data
						: __( 'An error occurred. Please try again.', 'twt-aeo-ultimate' );
					$notice.addClass( 'is-error' ).text( err ).show();
				}
			} )
			.fail( function () {
				$notice.addClass( 'is-error' ).text( __( 'Connection error. Please try again.', 'twt-aeo-ultimate' ) ).show();
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				$label.css( 'opacity', '' );
				$spin.hide();
				$notice[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			} );
	} );

} )( jQuery );
