/* WP.org compliance: extracted from class-page-local-pack.php inline <script> */
( function () {
	'use strict';

	// Show/hide output_pages row based on radio selection.
	document.querySelectorAll( 'input[name="output_on"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			var row = document.getElementById( 'lp_output_pages_row' );
			if ( row ) row.style.display = ( this.value === 'specific' ) ? '' : 'none';
		} );
	} );

	// Highlight selected Google method card border.
	document.querySelectorAll( '.lp-method-radio' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			document.querySelectorAll( '.lp-method-card' ).forEach( function ( card ) {
				card.style.borderColor = '#c3c4c7';
			} );
			this.closest( '.lp-method-card' ).style.borderColor = '#2271b1';
			document.getElementById( 'lp_kg_section' ).style.display  = document.getElementById( 'lp_method_kg' ).checked  ? '' : 'none';
			document.getElementById( 'lp_gbp_section' ).style.display = document.getElementById( 'lp_method_gbp' ).checked ? '' : 'none';
		} );
	} );
} )();
