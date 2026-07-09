/* WP.org compliance: extracted from admin/pages/class-page-company.php inline script block */
/* global twtAeoCompany */
( function () {
	'use strict';

	var i18n  = ( window.twtAeoCompany && window.twtAeoCompany.i18n ) || {};
	var btn   = document.getElementById( 'twt-aeo-logo-pick' );

	if ( ! btn || typeof wp === 'undefined' || ! wp.media ) return;

	var frame;
	btn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) { frame.open(); return; }
		frame = wp.media( {
			title:    i18n.chooseLogoTitle || 'Choose Company Logo',
			button:   { text: i18n.useAsLogoText || 'Use as logo' },
			multiple: false,
			library:  { type: 'image' },
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			document.getElementById( 'co-logo-url' ).value = att.url;
			document.getElementById( 'co-logo-id' ).value  = att.id;
		} );
		frame.open();
	} );
} )();
