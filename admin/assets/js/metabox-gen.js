/* Delegated click handler for the AEO metabox one-click schema-generate button.
   Extracted from admin/class-metabox.php so all JS loads via wp_enqueue_script. */
(function () {
	'use strict';
	var i18n = window.twtAeoMetaboxGen || {};
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.twt-aeo-gen-btn' ) : null;
		if ( ! btn ) { return; }
		e.preventDefault();
		var msg = btn.parentNode.querySelector( '.twt-aeo-gen-msg' );
		btn.disabled = true;
		if ( msg ) { msg.style.color = '#6b7280'; msg.textContent = i18n.generating || 'Generating…'; }
		var body = new URLSearchParams();
		body.append( 'action', btn.getAttribute( 'data-action' ) );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		body.append( 'post_id', btn.getAttribute( 'data-post' ) );
		fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				btn.disabled = false;
				if ( j && j.success ) {
					if ( msg ) { msg.style.color = '#166534'; msg.textContent = i18n.added || '✓ Added — reload to refresh status.'; }
					btn.style.display = 'none';
				} else {
					if ( msg ) { msg.style.color = '#991b1b'; msg.textContent = '✗ ' + ( ( j && j.data && j.data.message ) ? j.data.message : ( i18n.failed || 'Could not add schema.' ) ); }
				}
			} )
			.catch( function () {
				btn.disabled = false;
				if ( msg ) { msg.style.color = '#991b1b'; msg.textContent = '✗ ' + ( i18n.requestFailed || 'Request failed.' ); }
			} );
	} );
})();
