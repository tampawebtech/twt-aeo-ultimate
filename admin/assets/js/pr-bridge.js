/* global twtAeoPR, jQuery, wp */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;


	var $modal        = $( '#twt-aeo-pr-modal' );
	var $formatted    = $( '#twt-aeo-pr-formatted' );
	var $spinner      = $( '#twt-aeo-pr-modal-spinner' );
	var $modalStatus  = $( '#twt-aeo-pr-modal-status' );
	var $tierSelect   = $( '#twt-aeo-pr-tier' );
	var $submitBtn    = $( '#twt-aeo-pr-submit-btn' );
	var $submitStatus = $( '#twt-aeo-pr-submit-status' );

	// ── Open modal via "Professionalize" button ───────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-transform-btn', function () {
		var postId = $( this ).data( 'post-id' );
		openModal();
		showSpinner( true );
		$formatted.val( '' ).hide();

		$.ajax( {
			url:  twtAeoPR.ajaxUrl,
			type: 'POST',
			data: {
				action:  'twtaeo_pr_transform',
				nonce:   twtAeoPR.nonceTransform,
				post_id: postId,
			},
			timeout: 60000,
			success: function ( response ) {
				if ( response.success ) {
					$formatted.val( response.data.content ).show();
				} else {
					$formatted.val( '' ).show();
					modalStatus( response.data || __( 'Error generating press release.', 'twt-aeo-ultimate' ), 'error' );
				}
			},
			error: function ( xhr, status ) {
				var msg = status === 'timeout'
					? __( 'Request timed out. Please try again.', 'twt-aeo-ultimate' )
					: __( 'Server error. Please try again.', 'twt-aeo-ultimate' );
				modalStatus( msg, 'error' );
				$formatted.show();
			},
			complete: function () {
				showSpinner( false );
			},
		} );
	} );

	// ── Open modal via "Review / Edit" button ────────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-review-btn', function () {
		openModal();
		$spinner.hide();
		$formatted.show();
	} );

	// ── Close modal ───────────────────────────────────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-modal-close, #twt-aeo-pr-modal-discard', closeModal );

	$( document ).on( 'click', '.twt-aeo-pr-modal__overlay', closeModal );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && $modal.is( ':visible' ) ) {
			closeModal();
		}
	} );

	// ── Save formatted press release ─────────────────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-modal-save', function () {
		var postId  = $( this ).data( 'post-id' );
		var content = $formatted.val().trim();

		if ( ! content ) {
			modalStatus( __( 'The press release is empty.', 'twt-aeo-ultimate' ), 'error' );
			return;
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( __( 'Saving…', 'twt-aeo-ultimate' ) );
		modalStatus( '', '' );

		$.ajax( {
			url:  twtAeoPR.ajaxUrl,
			type: 'POST',
			data: {
				action:  'twtaeo_pr_save',
				nonce:   twtAeoPR.nonceSave,
				post_id: postId,
				content: content,
			},
			success: function ( response ) {
				if ( response.success ) {
					modalStatus( __( 'Saved! Reload the page to see distribution options.', 'twt-aeo-ultimate' ), 'ok' );
					setTimeout( function () {
						closeModal();
						window.location.reload();
					}, 1500 );
				} else {
					modalStatus( response.data || __( 'Failed to save.', 'twt-aeo-ultimate' ), 'error' );
				}
			},
			error: function () {
				modalStatus( __( 'Server error. Please try again.', 'twt-aeo-ultimate' ), 'error' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text( __( 'Save as Press Release', 'twt-aeo-ultimate' ) );
			},
		} );
	} );

	// ── Delete formatted PR ───────────────────────────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-delete-btn', function () {
		if ( ! window.confirm( __( 'Delete the saved press release? This cannot be undone.', 'twt-aeo-ultimate' ) ) ) {
			return;
		}
		var postId = $( this ).data( 'post-id' );
		var $btn   = $( this );
		$btn.prop( 'disabled', true );

		$.ajax( {
			url:  twtAeoPR.ajaxUrl,
			type: 'POST',
			data: {
				action:  'twtaeo_pr_delete',
				nonce:   twtAeoPR.nonceSave,
				post_id: postId,
			},
			success: function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					alert( response.data || __( 'Failed to delete.', 'twt-aeo-ultimate' ) );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				alert( __( 'Server error.', 'twt-aeo-ultimate' ) );
				$btn.prop( 'disabled', false );
			},
		} );
	} );

	// ── Tier select enables submit button ────────────────────────────────────

	$tierSelect.on( 'change', function () {
		$submitBtn.prop( 'disabled', ! $( this ).val() );
	} );

	// ── Submit to wire ────────────────────────────────────────────────────────

	$( document ).on( 'click', '#twt-aeo-pr-submit-btn', function () {
		var postId = $( this ).data( 'post-id' );
		var tier   = $tierSelect.val();

		if ( ! tier ) {
			return;
		}

		if ( ! window.confirm( 'Submit to ' + $tierSelect.find( ':selected' ).text().trim() + '? This will distribute your press release to the wire service.' ) ) {
			return;
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( __( 'Submitting…', 'twt-aeo-ultimate' ) );
		$submitStatus.hide().text( '' );

		$.ajax( {
			url:  twtAeoPR.ajaxUrl,
			type: 'POST',
			data: {
				action:  'twtaeo_pr_submit',
				nonce:   twtAeoPR.nonceSubmit,
				post_id: postId,
				tier:    tier,
			},
			timeout: 30000,
			success: function ( response ) {
				if ( response.success ) {
					var msg = response.data.message || __( 'Submitted successfully.', 'twt-aeo-ultimate' );
					if ( response.data.url ) {
						msg += ' <a href="' + response.data.url + '" target="_blank" rel="noopener">View release</a>';
					}
					$submitStatus
						.removeClass( 'twt-aeo-pr-status--err' )
						.addClass( 'twt-aeo-pr-status--ok' )
						.html( msg )
						.show();
					setTimeout( function () { window.location.reload(); }, 3000 );
				} else {
					$submitStatus
						.removeClass( 'twt-aeo-pr-status--ok' )
						.addClass( 'twt-aeo-pr-status--err' )
						.text( response.data || __( 'Submission failed.', 'twt-aeo-ultimate' ) )
						.show();
					$btn.prop( 'disabled', false ).text( __( 'Submit to Wire', 'twt-aeo-ultimate' ) );
				}
			},
			error: function ( xhr, status ) {
				var msg = status === 'timeout' ? __( 'Request timed out.', 'twt-aeo-ultimate' ) : __( 'Server error.', 'twt-aeo-ultimate' );
				$submitStatus
					.addClass( 'twt-aeo-pr-status--err' )
					.text( msg )
					.show();
				$btn.prop( 'disabled', false ).text( __( 'Submit to Wire', 'twt-aeo-ultimate' ) );
			},
		} );
	} );

	// ── Helpers ───────────────────────────────────────────────────────────────

	function openModal() {
		$modal.show();
		$( 'body' ).addClass( 'twt-aeo-modal-open' );
		$modalStatus.text( '' ).attr( 'class', 'twt-aeo-pr-modal__save-status' );
	}

	function closeModal() {
		$modal.hide();
		$( 'body' ).removeClass( 'twt-aeo-modal-open' );
	}

	function showSpinner( show ) {
		if ( show ) {
			$spinner.show();
			$formatted.hide();
		} else {
			$spinner.hide();
		}
	}

	function modalStatus( msg, type ) {
		$modalStatus
			.text( msg )
			.attr( 'class', 'twt-aeo-pr-modal__save-status' + ( type ? ' twt-aeo-pr-modal__save-status--' + type : '' ) );
	}

} )( jQuery );
