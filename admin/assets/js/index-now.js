/* global twtAeoIndexNow, wp */
( function () {
	'use strict';

	var __ = wp.i18n.__;


	if ( typeof twtAeoIndexNow === 'undefined' ) return;

	// ── Copy key to clipboard ─────────────────────────────────────────────────

	var copyBtn = document.getElementById( 'twt-indexnow-copy' );
	var keyEl   = document.getElementById( 'twt-indexnow-key' );

	if ( copyBtn && keyEl ) {
		copyBtn.addEventListener( 'click', function () {
			navigator.clipboard.writeText( keyEl.textContent.trim() ).then( function () {
				copyBtn.textContent = __( 'Copied!', 'twt-aeo-ultimate' );
				setTimeout( function () { copyBtn.textContent = __( 'Copy', 'twt-aeo-ultimate' ); }, 2000 );
			} );
		} );
	}

	// ── Verify key file ───────────────────────────────────────────────────────

	var verifyBtn    = document.getElementById( 'twt-indexnow-verify' );
	var verifyResult = document.getElementById( 'twt-indexnow-verify-result' );

	if ( verifyBtn && verifyResult ) {
		verifyBtn.addEventListener( 'click', function () {
			verifyBtn.disabled    = true;
			verifyBtn.textContent = __( 'Verifying…', 'twt-aeo-ultimate' );
			verifyResult.style.display = 'none';

			var fd = new FormData();
			fd.append( 'action', 'twtaeo_indexnow_verify_key' );
			fd.append( 'nonce',  twtAeoIndexNow.nonce );

			fetch( twtAeoIndexNow.ajaxUrl, { method: 'POST', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					var ok  = res.success && res.data && res.data.ok;
					var msg = ( res.data && res.data.message ) ? res.data.message : ( res.data || 'Unknown error.' );
					verifyResult.innerHTML    = '<span style="color:' + ( ok ? '#00a32a' : '#d63638' ) + ';font-weight:600;">'
						+ ( ok ? '✓ ' : '✗ ' ) + escHtml( msg ) + '</span>';
					verifyResult.style.display = 'block';
				} )
				.catch( function () {
					verifyResult.innerHTML    = '<span style="color:#d63638;">Request failed.</span>';
					verifyResult.style.display = 'block';
				} )
				.finally( function () {
					verifyBtn.disabled    = false;
					verifyBtn.textContent = __( 'Verify Key File', 'twt-aeo-ultimate' );
				} );
		} );
	}

	// ── Manual URL submission ─────────────────────────────────────────────────

	var submitBtn    = document.getElementById( 'twt-indexnow-submit' );
	var submitAllBtn = document.getElementById( 'twt-indexnow-submit-all' );
	var urlsTextarea = document.getElementById( 'twt-indexnow-urls' );
	var statusEl     = document.getElementById( 'twt-indexnow-submit-status' );
	var resultEl     = document.getElementById( 'twt-indexnow-submit-result' );

	function doSubmit( action, extraData ) {
		if ( statusEl )  statusEl.textContent  = __( 'Submitting…', 'twt-aeo-ultimate' );
		if ( resultEl )  resultEl.style.display = 'none';
		if ( submitBtn )    submitBtn.disabled    = true;
		if ( submitAllBtn ) submitAllBtn.disabled = true;

		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce',  twtAeoIndexNow.nonce );
		if ( extraData ) {
			Object.keys( extraData ).forEach( function ( k ) {
				fd.append( k, extraData[ k ] );
			} );
		}

		fetch( twtAeoIndexNow.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( statusEl ) statusEl.textContent = '';
				if ( ! res.success ) {
					showResult( false, [ { engine: '—', status: 0, message: res.data || __( 'Error.', 'twt-aeo-ultimate' ) } ] );
					return;
				}
				showResult( true, res.data );
			} )
			.catch( function () {
				if ( statusEl ) statusEl.textContent = '';
				showResult( false, [ { engine: '—', status: 0, message: __( 'Request failed.', 'twt-aeo-ultimate' ) } ] );
			} )
			.finally( function () {
				if ( submitBtn )    submitBtn.disabled    = false;
				if ( submitAllBtn ) submitAllBtn.disabled = false;
			} );
	}

	function showResult( success, rows ) {
		if ( ! resultEl ) return;

		var html = '<table class="wp-list-table widefat fixed striped" style="font-size:12px;">'
			+ '<thead><tr><th>Engine</th><th style="width:80px;">Status</th><th>Message</th></tr></thead><tbody>';

		rows.forEach( function ( row ) {
			var ok = row.status >= 200 && row.status < 300;
			html += '<tr>'
				+ '<td>' + escHtml( row.engine ) + '</td>'
				+ '<td style="color:' + ( ok ? '#00a32a' : '#d63638' ) + ';font-weight:600;">' + ( row.status || 'ERR' ) + '</td>'
				+ '<td>' + escHtml( row.message ) + '</td>'
				+ '</tr>';
		} );

		html += '</tbody></table>';
		resultEl.innerHTML    = html;
		resultEl.style.display = 'block';
	}

	if ( submitBtn && urlsTextarea ) {
		submitBtn.addEventListener( 'click', function () {
			var raw  = urlsTextarea.value.trim();
			var urls = raw.split( /\s+/ ).filter( function ( u ) { return u.length > 0; } );
			if ( urls.length === 0 ) {
				if ( statusEl ) statusEl.textContent = __( 'Please enter at least one URL.', 'twt-aeo-ultimate' );
				return;
			}
			doSubmit( 'twtaeo_indexnow_submit', { urls: urls.join( '\n' ) } );
		} );
	}

	if ( submitAllBtn ) {
		submitAllBtn.addEventListener( 'click', function () {
			if ( ! confirm( __( 'Submit all published URLs to IndexNow?', 'twt-aeo-ultimate' ) ) ) return;
			doSubmit( 'twtaeo_indexnow_submit_all' );
		} );
	}

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( str || '' ) );
		return d.innerHTML;
	}

} )();
