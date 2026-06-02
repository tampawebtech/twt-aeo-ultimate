/* global twtAeoCgSubtopics, twtAeoCgNonce, twtAeoCgAjaxUrl, jQuery, wp */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var $postSelect    = $( '#twt-aeo-cg-post' );
	var $industrySelect = $( '#twt-aeo-cg-industry' );
	var $subtopicSelect = $( '#twt-aeo-cg-subtopic' );
	var $generateBtn   = $( '#twt-aeo-cg-generate' );
	var $results       = $( '#twt-aeo-cg-results' );
	var $titlePreview  = $( '#twt-aeo-cg-title-preview' );
	var $titleText     = $( '#twt-aeo-cg-title-text' );

	// New-post create fields.
	var $newFields     = $( '#twt-aeo-cg-new-fields' );
	var $newTitle      = $( '#twt-aeo-cg-new-title' );
	var $hubParentRow  = $( '#twt-aeo-cg-hub-parent-row' );
	var $hubParent     = $( '#twt-aeo-cg-hub-parent' );

	// Prompt option fields.
	var $intent       = $( '#twt-aeo-cg-intent' );
	var $tone         = $( '#twt-aeo-cg-tone' );
	var $audience     = $( '#twt-aeo-cg-audience' );
	var $pov          = $( '#twt-aeo-cg-pov' );
	var $length       = $( '#twt-aeo-cg-length' );
	var $format       = $( '#twt-aeo-cg-format' );
	var $focus        = $( '#twt-aeo-cg-focus' );
	var $cta          = $( '#twt-aeo-cg-cta' );
	var $readingLevel = $( '#twt-aeo-cg-reading-level' );
	var $notes        = $( '#twt-aeo-cg-notes' );

	var providers = [ 'claude', 'openai', 'perplexity' ];

	// ── Helpers ───────────────────────────────────────────────────────────────

	function isCreateMode() {
		var v = $postSelect.val();
		return !! ( v && v.indexOf( '__new:' ) === 0 );
	}

	function getCreateType() {
		return isCreateMode() ? $postSelect.val().replace( '__new:', '' ) : '';
	}

	var typeLabels = {
		'post':        'Post',
		'page':        'Page',
		'twt_hub':     'Hub',
		'twt_hub_sub': 'Hub Sub-page',
	};

	// ── Industry → subtopic cascade ──────────────────────────────────────────

	$industrySelect.on( 'change', function () {
		var slug = $( this ).val();
		$subtopicSelect.empty();

		if ( ! slug || ! twtAeoCgSubtopics[ slug ] ) {
			$subtopicSelect
				.append( '<option value="">' + subtopicPlaceholder( false ) + '</option>' )
				.prop( 'disabled', true );
			updateGenerateState();
			return;
		}

		$subtopicSelect.append( '<option value="">' + subtopicPlaceholder( true ) + '</option>' );

		$.each( twtAeoCgSubtopics[ slug ], function ( i, subtopic ) {
			$subtopicSelect.append(
				$( '<option>' ).val( subtopic.slug ).text( subtopic.label )
			);
		} );

		$subtopicSelect.prop( 'disabled', false );
		updateGenerateState();
	} );

	$subtopicSelect.on( 'change', updateGenerateState );

	// ── Post selector — handles both existing and create-new ─────────────────

	$postSelect.on( 'change', function () {
		var val    = $( this ).val();
		var create = val && val.indexOf( '__new:' ) === 0;
		var ctype  = create ? val.replace( '__new:', '' ) : '';

		if ( create ) {
			$newFields.show();
			$hubParentRow.toggle( ctype === 'twt_hub_sub' );
			$titlePreview.hide();
			$newTitle.focus();
		} else {
			$newFields.hide();
			$hubParentRow.hide();
			var title = $postSelect.find( ':selected' ).text().trim();
			if ( title && val ) {
				$titleText.text( title );
				$titlePreview.show();
			} else {
				$titlePreview.hide();
			}
		}

		updateGenerateState();
	} );

	$newTitle.on( 'input', function () {
		var title = $( this ).val().trim();
		if ( title ) {
			$titleText.text( title );
			$titlePreview.show();
		} else {
			$titlePreview.hide();
		}
		updateGenerateState();
	} );

	$hubParent.on( 'change', updateGenerateState );

	function updateGenerateState() {
		var postVal   = $postSelect.val();
		var ctype     = getCreateType();
		var postReady;

		if ( isCreateMode() ) {
			var titleOk  = !! $newTitle.val().trim();
			var parentOk = ctype !== 'twt_hub_sub' || !! $hubParent.val();
			postReady = titleOk && parentOk;
		} else {
			postReady = !! postVal;
		}

		var ready = postReady && $industrySelect.val() && $subtopicSelect.val();
		$generateBtn.prop( 'disabled', ! ready );
	}

	function subtopicPlaceholder( hasIndustry ) {
		return hasIndustry
			? __( '— Select content type —', 'twt-aeo-ultimate' )
			: __( '— Select industry first —', 'twt-aeo-ultimate' );
	}

	// ── Generate ─────────────────────────────────────────────────────────────

	$generateBtn.on( 'click', function () {
		var postVal    = $postSelect.val();
		var industry   = $industrySelect.val();
		var subtopic   = $subtopicSelect.val();
		var createMode = isCreateMode();
		var ctype      = getCreateType();
		var cTitle     = createMode ? $newTitle.val().trim() : '';
		var cParent    = ( createMode && ctype === 'twt_hub_sub' ) ? $hubParent.val() : '';

		if ( ! postVal || ! industry || ! subtopic ) {
			return;
		}
		if ( createMode && ! cTitle ) {
			return;
		}

		$generateBtn.prop( 'disabled', true ).text( __( 'Generating…', 'twt-aeo-ultimate' ) );
		$results.show();

		$.each( providers, function ( i, provider ) {
			panelLoading( provider );
		} );

		$.ajax( {
			url:  twtAeoCgAjaxUrl,
			type: 'POST',
			data: {
				action:           'twtaeo_generate_content',
				nonce:            twtAeoCgNonce,
				post_id:          createMode ? 0 : postVal,
				create_type:      ctype,
				create_title:     cTitle,
				create_parent:    cParent,
				industry:         industry,
				subtopic:         subtopic,
				intent:           $intent.val(),
				tone:             $tone.val(),
				audience:         $audience.val(),
				pov:              $pov.val(),
				length:           $length.val(),
				format:           $format.val(),
				focus:            $focus.val(),
				cta:              $cta.val(),
				reading_level:    $readingLevel.val(),
				notes:            $notes.val(),
				semantic_elements: ( function () {
					var checked = [];
					$( 'input[name="twt-aeo-cg-semantic[]"]:checked' ).each( function () {
						checked.push( $( this ).val() );
					} );
					return checked.join( ',' );
				}() ),
			},
			timeout: 90000,
			success: function ( response ) {
				if ( ! response.success ) {
					$.each( providers, function ( i, provider ) {
						panelError( provider, response.data || __( 'An error occurred.', 'twt-aeo-ultimate' ) );
					} );
					return;
				}

				var data = response.data;
				var meta = data.__meta || {};

				$.each( providers, function ( i, provider ) {
					if ( ! data[ provider ] ) {
						panelHide( provider );
						return;
					}

					var panel = data[ provider ];
					$( '#twt-aeo-cg-label-' + provider ).text( panel.label );

					if ( panel.error ) {
						panelError( provider, panel.error );
					} else {
						panelSuccess( provider, panel.content, meta );
					}
				} );
			},
			error: function ( xhr, status ) {
				var msg = status === 'timeout'
					? __( 'Request timed out. AI APIs can be slow — please try again.', 'twt-aeo-ultimate' )
					: __( 'Server error. Please try again.', 'twt-aeo-ultimate' );
				$.each( providers, function ( i, provider ) {
					panelError( provider, msg );
				} );
			},
			complete: function () {
				$generateBtn.prop( 'disabled', false ).text( __( 'Generate Content', 'twt-aeo-ultimate' ) );
			},
		} );
	} );

	// ── Panel state helpers ───────────────────────────────────────────────────

	function panelLoading( provider ) {
		var $panel = $( '#twt-aeo-cg-panel-' + provider );
		$panel.find( '.twt-aeo-cg-panel__spinner' ).show();
		$panel.find( '.twt-aeo-cg-panel__error' ).hide();
		$panel.find( '.twt-aeo-cg-panel__textarea' ).hide().val( '' );
		$panel.find( '.twt-aeo-cg-panel__foot' ).hide().empty();
		$panel.show();
	}

	function panelSuccess( provider, content, meta ) {
		var $panel = $( '#twt-aeo-cg-panel-' + provider );
		$panel.find( '.twt-aeo-cg-panel__spinner' ).hide();
		$panel.find( '.twt-aeo-cg-panel__error' ).hide();
		$panel.find( '.twt-aeo-cg-panel__textarea' ).val( content ).show();

		var $foot = $panel.find( '.twt-aeo-cg-panel__foot' );
		$foot.empty();

		// Copy button (always present).
		$foot.append(
			$( '<button class="button twt-aeo-cg-copy">' )
				.attr( 'data-target', 'twt-aeo-cg-content-' + provider )
				.text( __( 'Copy', 'twt-aeo-ultimate' ) )
		);

		// Save-as-Draft button (only in create mode).
		if ( meta && meta.is_new ) {
			var label = typeLabels[ meta.create_type ] || 'Draft';
			$foot.append(
				$( '<button class="button button-primary twt-aeo-cg-save">' )
					.attr( 'data-provider',      provider )
					.attr( 'data-create-type',   meta.create_type )
					.attr( 'data-create-title',  meta.create_title )
					.attr( 'data-create-parent', meta.create_parent || '' )
					.text( __( 'Save as ', 'twt-aeo-ultimate' ) + label + ' Draft' )
			);
		}

		$foot.show();
	}

	function panelError( provider, message ) {
		var $panel = $( '#twt-aeo-cg-panel-' + provider );
		$panel.find( '.twt-aeo-cg-panel__spinner' ).hide();
		$panel.find( '.twt-aeo-cg-panel__textarea' ).hide();
		$panel.find( '.twt-aeo-cg-panel__foot' ).hide();
		$panel.find( '.twt-aeo-cg-panel__error' ).text( message ).show();
	}

	function panelHide( provider ) {
		$( '#twt-aeo-cg-panel-' + provider ).hide();
	}

	// ── Copy to clipboard ─────────────────────────────────────────────────────

	$( document ).on( 'click', '.twt-aeo-cg-copy', function () {
		var $btn      = $( this );
		var targetId  = $btn.data( 'target' );
		var $textarea = $( '#' + targetId );

		if ( ! $textarea.length ) {
			return;
		}

		var text = $textarea.val();

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				flashCopied( $btn );
			} );
		} else {
			$textarea[ 0 ].select();
			document.execCommand( 'copy' );
			flashCopied( $btn );
		}
	} );

	function flashCopied( $btn ) {
		var original = $btn.text();
		$btn.text( __( 'Copied!', 'twt-aeo-ultimate' ) );
		setTimeout( function () {
			$btn.text( original );
		}, 2000 );
	}

	// ── Save as Draft ─────────────────────────────────────────────────────────

	$( document ).on( 'click', '.twt-aeo-cg-save', function () {
		var $btn         = $( this );
		var provider     = $btn.data( 'provider' );
		var createType   = $btn.data( 'create-type' );
		var createTitle  = $btn.data( 'create-title' );
		var createParent = $btn.data( 'create-parent' ) || '';
		var content      = $( '#twt-aeo-cg-content-' + provider ).val();

		$btn.prop( 'disabled', true ).text( __( 'Saving…', 'twt-aeo-ultimate' ) );

		$.ajax( {
			url:  twtAeoCgAjaxUrl,
			type: 'POST',
			data: {
				action:        'twtaeo_save_content_draft',
				nonce:         twtAeoCgNonce,
				create_type:   createType,
				create_title:  createTitle,
				create_parent: createParent,
				post_content:  content,
			},
			success: function ( response ) {
				if ( response.success ) {
					$btn.replaceWith(
						$( '<a class="button button-primary twt-aeo-cg-saved" target="_blank" rel="noopener">' )
							.attr( 'href', response.data.edit_url )
							.text( __( '✓ Edit Draft', 'twt-aeo-ultimate' ) )
					);
				} else {
					$btn.prop( 'disabled', false )
					    .text( __( 'Save failed — retry', 'twt-aeo-ultimate' ) );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false )
				    .text( __( 'Save failed — retry', 'twt-aeo-ultimate' ) );
			},
		} );
	} );

} )( jQuery );
