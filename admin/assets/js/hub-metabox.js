/* WP.org compliance: extracted from admin/class-hub-metabox.php inline <script> */
/* global twtAeoHubMetabox */
( function () {
	'use strict';

	var i18n = ( window.twtAeoHubMetabox && window.twtAeoHubMetabox.i18n ) || {};

	document.querySelectorAll( '.twt-hub-copy-sc' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var sc   = btn.getAttribute( 'data-sc' );
			var orig = btn.textContent;
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( sc ).then( function () {
					btn.textContent = i18n.copied || 'Copied!';
					setTimeout( function () { btn.textContent = orig; }, 1800 );
				} );
			}
		} );
	} );
} )();
