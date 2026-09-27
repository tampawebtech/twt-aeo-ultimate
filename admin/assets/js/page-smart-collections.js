/**
 * Smart Collections — the build modal.
 *
 * Drafting is a round-trip that writes nothing; creating is a normal form POST,
 * because it makes a live category and that is a full-reload moment.
 *
 * Rows are built with jQuery element creation rather than HTML concatenation —
 * product names and AI-written summaries are untrusted strings, and one of them
 * containing a quote or an angle bracket must not be able to reshape the page.
 */
( function ( $ ) {
	'use strict';

	var $modal, $form, $loading, $error, $dismiss, $products;

	function show() {
		$modal.show();
		$( 'body' ).addClass( 'twt-aeo-modal-open' );
	}

	function close() {
		$modal.hide();
		$( 'body' ).removeClass( 'twt-aeo-modal-open' );
	}

	function reset() {
		$error.hide().find( 'p' ).text( '' );
		$form.hide();
		$dismiss.hide();
		$products.empty();
		$loading.show();
	}

	function fail( message ) {
		$loading.hide();
		$form.hide();
		$error.show().find( 'p' ).text( message );
		$dismiss.show();
	}

	/** One product row: checkbox, name, price, stock warning. */
	function productRow( product, checked ) {
		var $label = $( '<label>' ).addClass( 'twt-aeo-modal__product' );

		$label.append(
			$( '<input>' ).attr( {
				type: 'checkbox',
				name: 'build_products[]',
				value: product.id
			} ).prop( 'checked', checked )
		);

		$label.append( $( '<span>' ).addClass( 'twt-aeo-modal__product-name' ).text( product.name ) );

		if ( product.price > 0 ) {
			$label.append(
				$( '<span>' ).addClass( 'twt-aeo-modal__product-price' ).text( product.price.toFixed( 2 ) )
			);
		}

		if ( 'outofstock' === product.stock ) {
			$label.append(
				$( '<span>' )
					.addClass( 'twt-aeo-modal__product-stock' )
					.text( twtaeoCollections.i18n.outOfStock )
			);
		}

		return $label;
	}

	function populate( data, meta ) {
		$loading.hide();

		$( '#twt-aeo-build-title' ).val( data.title );
		$( '#twt-aeo-build-summary' ).val( data.summary );
		$( '#twt-aeo-build-query' ).val( meta.query );
		$( '#twt-aeo-build-archetype' ).val( meta.archetype );
		$( '#twt-aeo-build-impressions' ).val( meta.impressions );
		$( '#twt-aeo-build-clicks' ).val( meta.clicks );

		var chosen = data.product_ids || [];

		$.each( data.candidates || [], function ( _, product ) {
			$products.append( productRow( product, -1 !== $.inArray( product.id, chosen ) ) );
		} );

		$form.show();
	}

	$( function () {
		$modal    = $( '#twt-aeo-build-modal' );
		if ( ! $modal.length ) {
			return;
		}
		$form     = $( '#twt-aeo-build-form' );
		$loading  = $( '#twt-aeo-build-loading' );
		$error    = $( '#twt-aeo-build-error' );
		$dismiss  = $( '#twt-aeo-build-dismiss' );
		$products = $( '#twt-aeo-build-products' );

		$( document ).on( 'click', '.twt-aeo-build-trigger', function () {
			var meta = {
				query: $( this ).data( 'query' ),
				archetype: $( this ).data( 'archetype' ),
				impressions: $( this ).data( 'impressions' ),
				clicks: $( this ).data( 'clicks' )
			};

			$( '#twt-aeo-build-querytext' ).text( meta.query );
			reset();
			show();

			$.post( ajaxurl, {
				action: 'twtaeo_collection_draft',
				nonce: twtaeoCollections.nonce,
				query: meta.query,
				archetype: meta.archetype
			} ).done( function ( response ) {
				if ( response && response.success ) {
					populate( response.data, meta );
				} else {
					fail(
						response && response.data && response.data.message
							? response.data.message
							: twtaeoCollections.i18n.genericError
					);
				}
			} ).fail( function () {
				fail( twtaeoCollections.i18n.genericError );
			} );
		} );

		$( document ).on( 'click', '.twt-aeo-modal__close', close );

		$modal.on( 'click', function ( event ) {
			if ( event.target === this ) {
				close();
			}
		} );

		$( document ).on( 'keyup', function ( event ) {
			if ( 27 === event.keyCode && $modal.is( ':visible' ) ) {
				close();
			}
		} );

		// A build with too few products is refused server-side anyway; catching it
		// here saves the merchant a page load to be told so.
		$form.on( 'submit', function () {
			if ( $products.find( 'input:checked' ).length < twtaeoCollections.minItems ) {
				window.alert( twtaeoCollections.i18n.tooFew );
				return false;
			}
			return true;
		} );
	} );
} )( jQuery );
