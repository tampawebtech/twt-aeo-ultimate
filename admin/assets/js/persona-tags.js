/**
 * Buyer persona tag input — shared by the AI Visibility and Local Pack pages.
 *
 * Markup comes from TWTAEO_Page_AI_Visibility::render_persona_editor(). The
 * hidden `twtaeo_personas` field always holds the list as JSON rows
 * `{label, source}`: on Local Pack it rides the page's own form, on AI
 * Visibility the Save button posts it. "Suggest" asks the server to read the
 * site's main pages and saves what comes back, so both pages see it.
 *
 * Fires `twtaeo:personas` on document with the saved list, so the run picker
 * on the AI Visibility page can follow along without a reload.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.twtAeoPersonas === 'undefined' ) {
		return;
	}

	var cfg     = window.twtAeoPersonas;
	var strings = cfg.strings || {};
	var MAX     = parseInt( cfg.max, 10 ) || 10;

	function post( action, data ) {
		data        = data || {};
		data.action = action;
		data.nonce  = cfg.nonce;
		return $.post( cfg.ajaxUrl, data );
	}

	function errorOf( resp ) {
		if ( resp && resp.data && resp.data.error ) {
			return resp.data.error;
		}
		return strings.error || 'Something went wrong.';
	}

	function clean( label ) {
		return String( label || '' ).replace( /\s+/g, ' ' ).replace( /^[\s"'.,;:]+|[\s"'.,;:]+$/g, '' ).slice( 0, 80 );
	}

	$( '[data-personas]' ).each( function () {
		var $root   = $( this );
		var $box    = $root.find( '[data-persona-box]' );
		var $input  = $root.find( '[data-persona-input]' );
		var $json   = $root.find( '[data-persona-json]' );
		var $status = $root.find( '[data-persona-status]' );

		function status( text, tone ) {
			$status.removeClass( 'is-ok is-err' ).text( text || '' );
			if ( tone ) {
				$status.addClass( 'is-' + tone );
			}
		}

		function rows() {
			return $box.find( '[data-persona-tag]' ).map( function () {
				return { label: $( this ).attr( 'data-label' ), source: $( this ).attr( 'data-source' ) || 'owner' };
			} ).get();
		}

		function has( label ) {
			var key = label.toLowerCase();
			return rows().some( function ( r ) {
				return String( r.label ).toLowerCase() === key;
			} );
		}

		function sync() {
			$json.val( JSON.stringify( rows() ) );
			$input.prop( 'disabled', rows().length >= MAX )
				.attr( 'placeholder', rows().length >= MAX ? ( strings.full || 'That’s the maximum.' ) : $input.data( 'placeholder' ) );
		}

		function tag( label, source ) {
			var $t = $( '<span class="twt-aeo-personas__tag" data-persona-tag></span>' )
				.attr( 'data-label', label )
				.attr( 'data-source', source );
			$( '<button type="button" class="twt-aeo-personas__text" data-persona-edit></button>' )
				.attr( 'title', strings.edit || 'Click to edit' )
				.text( label )
				.appendTo( $t );
			if ( 'site' === source ) {
				$t.addClass( 'is-site' );
				$( '<span class="twt-aeo-personas__src"></span>' ).text( strings.fromSite || 'suggested from your site' ).appendTo( $t );
			}
			$( '<button type="button" class="twt-aeo-personas__remove" data-persona-remove>&times;</button>' )
				.attr( 'aria-label', ( strings.remove || 'Remove %s' ).replace( '%s', label ) )
				.appendTo( $t );
			return $t;
		}

		function add( text, source ) {
			var added = 0;
			String( text || '' ).split( /[,\n]+/ ).forEach( function ( part ) {
				var label = clean( part );
				if ( ! label || has( label ) || rows().length >= MAX ) {
					return;
				}
				tag( label, source || 'owner' ).insertBefore( $input );
				added++;
			} );
			sync();
			return added;
		}

		function render( list ) {
			$box.find( '[data-persona-tag]' ).remove();
			( list || [] ).forEach( function ( p ) {
				if ( p && p.label ) {
					tag( p.label, 'site' === p.source ? 'site' : 'owner' ).insertBefore( $input );
				}
			} );
			sync();
			$( document ).trigger( 'twtaeo:personas', [ list || [] ] );
		}

		$input.data( 'placeholder', $input.attr( 'placeholder' ) );

		$input.on( 'keydown', function ( e ) {
			// Enter must never submit the surrounding form (Local Pack).
			if ( 'Enter' === e.key || ',' === e.key ) {
				e.preventDefault();
				add( $input.val() );
				$input.val( '' );
			} else if ( 'Backspace' === e.key && '' === $input.val() ) {
				$box.find( '[data-persona-tag]' ).last().remove();
				sync();
			}
		} );

		$input.on( 'paste', function () {
			window.setTimeout( function () {
				if ( /[,\n]/.test( $input.val() ) ) {
					add( $input.val() );
					$input.val( '' );
				}
			}, 0 );
		} );

		// Leaving the field with text still in it keeps that text as a persona.
		$input.on( 'blur', function () {
			if ( clean( $input.val() ) ) {
				add( $input.val() );
				$input.val( '' );
			}
		} );

		$box.on( 'click', function ( e ) {
			if ( e.target === $box[ 0 ] ) {
				$input.trigger( 'focus' );
			}
		} );

		$box.on( 'click', '[data-persona-remove]', function () {
			$( this ).closest( '[data-persona-tag]' ).remove();
			sync();
			$input.trigger( 'focus' );
		} );

		// Editing puts the text back in the input; re-entered, it is the owner's own.
		$box.on( 'click', '[data-persona-edit]', function () {
			var $t = $( this ).closest( '[data-persona-tag]' );
			if ( clean( $input.val() ) ) {
				add( $input.val() );
			}
			$input.val( $t.attr( 'data-label' ) );
			$t.remove();
			sync();
			$input.trigger( 'focus' );
		} );

		$root.on( 'click', '[data-persona-save]', function () {
			var $btn = $( this );
			if ( clean( $input.val() ) ) {
				add( $input.val() );
				$input.val( '' );
			}
			$btn.prop( 'disabled', true );
			status( strings.saving || 'Saving…' );
			post( cfg.actions.save, { personas: $json.val() } ).done( function ( resp ) {
				if ( resp && resp.success ) {
					render( resp.data.personas );
					status( resp.data.message, 'ok' );
				} else {
					status( errorOf( resp ), 'err' );
				}
			} ).fail( function () {
				status( strings.error || 'Something went wrong.', 'err' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$root.on( 'click', '[data-persona-suggest]', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );
			status( strings.reading || 'Reading your main pages…' );
			// Send the list as it stands on screen, unsaved edits included, so
			// the suggestions are added to it rather than to the stored copy.
			post( cfg.actions.suggest, { personas: $json.val() } ).done( function ( resp ) {
				if ( resp && resp.success ) {
					render( resp.data.personas );
					status( resp.data.message, 'ok' );
				} else {
					status( errorOf( resp ), 'err' );
				}
			} ).fail( function () {
				status( strings.error || 'Something went wrong.', 'err' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		sync();
	} );
}( jQuery ) );
