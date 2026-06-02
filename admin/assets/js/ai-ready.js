/* TWT AEO Ultimate — AI Ready page interactions */
/* global twtAeo, jQuery, wp */
(function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;


	// Show/hide dependent sub-sections when their parent toggle changes.
	$( '.twt-aeo-air-dependent' ).each( function () {
		var $sub    = $( this );
		var targetId = $sub.data( 'depends' );
		var $toggle  = $( '#' + targetId );

		if ( ! $toggle.length ) return;

		function syncVisibility() {
			if ( $toggle.is( ':checked' ) ) {
				$sub.slideDown( 150 );
			} else {
				$sub.slideUp( 150 );
			}
		}

		$toggle.on( 'change', syncVisibility );
		syncVisibility();
	} );

	// OAuth mode switcher (Built-in / External radio buttons).
	$( 'input[name="twtaeo_air[oauth_mode]"]' ).on( 'change', function () {
		var mode = $( this ).val();
		$( '#twt-aeo-oauth-builtin-panel' ).toggle( mode === 'builtin' );
		$( '#twt-aeo-oauth-external-panel' ).toggle( mode === 'external' );
	} );

	// Copy-to-clipboard for OAuth credentials.
	$( document ).on( 'click', '.twt-aeo-copy-btn', function () {
		var targetId = $( this ).data( 'target' );
		var text     = $( '#' + targetId ).text();
		var $btn     = $( this );

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				$btn.text( __( 'Copied!', 'twt-aeo-ultimate' ) );
				setTimeout( function () { $btn.text( __( 'Copy', 'twt-aeo-ultimate' ) ); }, 2000 );
			} );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			$btn.text( __( 'Copied!', 'twt-aeo-ultimate' ) );
			setTimeout( function () { $btn.text( __( 'Copy', 'twt-aeo-ultimate' ) ); }, 2000 );
		}
	} );

	// Inject Content-Signal into physical robots.txt.
	$( '#twt-aeo-inject-robots' ).on( 'click', function () {
		var $btn    = $( this );
		var $status = $( '#twt-aeo-inject-status' );
		var nonce   = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true ).text( __( 'Injecting…', 'twt-aeo-ultimate' ) );
		$status.text( '' ).css( 'color', '' );

		$.post( twtAeo.ajaxUrl, {
			action: 'twtaeo_inject_robots',
			nonce:  nonce,
		}, function ( response ) {
			if ( response.success ) {
				$status.text( __( 'Done! Content-Signal directive written.', 'twt-aeo-ultimate' ) ).css( 'color', 'var(--aeo-success, #2a9d5c)' );
				$btn.text( __( 'Inject into robots.txt', 'twt-aeo-ultimate' ) ).prop( 'disabled', false );
				// Update the notice icon/text to reflect success.
				var $notice = $( '#twt-aeo-robots-notice' );
				$notice.find( '.twt-aeo-air-robots-notice__icon' )
					.removeClass( 'dashicons-warning' ).addClass( 'dashicons-yes-alt' )
					.css( 'color', 'var(--aeo-success, #2a9d5c)' );
				$notice.find( 'strong' ).text( __( 'Physical robots.txt detected — Content-Signal present.', 'twt-aeo-ultimate' ) );
			} else {
				$status.text( __( 'Error:', 'twt-aeo-ultimate' ) + ' ' + ( response.data || 'Unknown error.' ) ).css( 'color', '#cc0000' );
				$btn.text( __( 'Inject into robots.txt', 'twt-aeo-ultimate' ) ).prop( 'disabled', false );
			}
		} ).fail( function () {
			$status.text( __( 'Request failed. Check your connection.', 'twt-aeo-ultimate' ) ).css( 'color', '#cc0000' );
			$btn.text( __( 'Inject into robots.txt', 'twt-aeo-ultimate' ) ).prop( 'disabled', false );
		} );
	} );

	// ── Path Diagnostic ──────────────────────────────────────────────────────

	$( '#twt-aeo-run-diagnostic' ).on( 'click', function () {
		var $btn     = $( this );
		var nonce    = $btn.data( 'nonce' );
		var $spinner = $( '#twt-aeo-diagnostic-spinner' );
		var $results = $( '#twt-aeo-diagnostic-results' );

		$btn.prop( 'disabled', true );
		$spinner.show();
		$results.hide();

		$.post( twtAeo.ajaxUrl, {
			action: 'twtaeo_diagnose_server',
			nonce:  nonce,
		}, function ( response ) {
			$spinner.hide();
			$btn.prop( 'disabled', false );

			if ( ! response.success ) {
				$results.show().find( '#twt-aeo-diag-badges' ).html(
					'<span style="color:#cc0000;">Error: ' + ( response.data || 'Diagnostic failed.' ) + '</span>'
				);
				return;
			}

			var d = response.data;
			renderDiagnosticResults( d );
			$results.show();

			// Show Apache fix button only when server is Apache/LiteSpeed and path is failing.
			if ( ! d.path_ok && d.can_auto_fix ) {
				$( '#twt-aeo-apache-fix-wrap' ).show();
			} else {
				$( '#twt-aeo-apache-fix-wrap' ).hide();
			}
		} ).fail( function () {
			$spinner.hide();
			$btn.prop( 'disabled', false );
			$( '#twt-aeo-diagnostic-results' ).show().find( '#twt-aeo-diag-badges' ).html(
				'<span style="color:#cc0000;">Request failed. Check your connection.</span>'
			);
		} );
	} );

	function renderDiagnosticResults( d ) {
		var $badges = $( '#twt-aeo-diag-badges' );
		var $instrs = $( '#twt-aeo-diag-instructions' );

		// Build status badges.
		var serverColor  = { nginx: '#2563eb', apache: '#ea580c', unknown: '#6b7280' };
		var serverLabels = { nginx: 'Nginx', apache: 'Apache / LiteSpeed', unknown: 'Unknown Server' };
		var pathColor    = d.path_ok ? 'var(--aeo-success,#2a9d5c)' : '#dc2626';
		var pathIcon     = d.path_ok ? '✓' : '✗';
		var pathLabel    = d.path_ok ? 'Path OK (' + d.status_code + ')' : 'Path Error (' + d.status_code + ')';

		var badges = [
			'<span style="background:' + ( serverColor[ d.server ] || '#6b7280' ) + ';color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">' +
				( serverLabels[ d.server ] || d.server ) +
			'</span>',
			'<span style="background:' + pathColor + ';color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">' +
				pathIcon + ' ' + pathLabel +
			'</span>',
		];

		if ( d.cloudflare ) {
			badges.push( '<span style="background:#f97316;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">Cloudflare Detected</span>' );
		}

		$badges.html( badges.join( '' ) );

		// Build instruction cards.
		if ( d.path_ok ) {
			$instrs.html( '<p style="color:var(--aeo-success,#2a9d5c);font-weight:600;margin:0;">&#10003; Path is reachable — no action needed.</p>' );
			return;
		}

		if ( ! d.instructions || ! d.instructions.length ) {
			$instrs.html( '<p style="color:#6b7280;margin:0;">No specific instructions available for this server type.</p>' );
			return;
		}

		var html = '';
		for ( var i = 0; i < d.instructions.length; i++ ) {
			var instr = d.instructions[ i ];
			var borderColor = instr.type === 'warning' ? '#f59e0b' : ( instr.type === 'error' ? '#ef4444' : '#3b82f6' );
			html += '<div style="border-left:3px solid ' + borderColor + ';background:#f8f9fa;padding:12px 16px;margin-bottom:12px;border-radius:0 4px 4px 0;">';
			html += '<strong style="display:block;margin-bottom:6px;font-size:13px;">' + instr.icon + ' ' + escHtml( instr.title ) + '</strong>';
			html += '<p style="margin:0 0 8px;font-size:13px;color:var(--aeo-text-muted,#6b7280);">' + escHtml( instr.body ) + '</p>';
			if ( instr.code ) {
				html += '<pre style="background:#1e1e1e;color:#d4d4d4;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;margin:0 0 8px;">' + escHtml( instr.code ) + '</pre>';
			}
			if ( instr.note ) {
				html += '<p style="margin:0;font-size:12px;color:#6b7280;font-style:italic;">' + escHtml( instr.note ) + '</p>';
			}
			html += '</div>';
		}

		$instrs.html( html );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	$( '#twt-aeo-apply-apache-fix' ).on( 'click', function () {
		var $btn    = $( this );
		var $status = $( '#twt-aeo-apache-fix-status' );
		var nonce   = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true ).text( __( 'Applying fix…', 'twt-aeo-ultimate' ) );
		$status.text( '' ).css( 'color', '' );

		$.post( twtAeo.ajaxUrl, {
			action: 'twtaeo_apply_apache_fix',
			nonce:  nonce,
		}, function ( response ) {
			$btn.prop( 'disabled', false ).text( __( 'Apply Apache Fix', 'twt-aeo-ultimate' ) );
			if ( response.success && response.data && response.data.success ) {
				$status.text( response.data.message || 'Fix applied successfully.' )
					.css( 'color', 'var(--aeo-success,#2a9d5c)' );
				// Re-run diagnostic to confirm the path is now OK.
				$( '#twt-aeo-run-diagnostic' ).trigger( 'click' );
			} else {
				var msg = ( response.data && response.data.message ) ? response.data.message : 'Fix failed.';
				$status.text( msg ).css( 'color', '#cc0000' );
				// Show manual snippet if provided.
				if ( response.data && response.data.manual ) {
					$( '#twt-aeo-diag-instructions' ).prepend(
						'<div style="border-left:3px solid #ef4444;background:#fff8f8;padding:12px 16px;margin-bottom:12px;border-radius:0 4px 4px 0;">' +
						'<strong style="display:block;margin-bottom:6px;font-size:13px;">&#9888; .htaccess not writable — add this manually:</strong>' +
						'<pre style="background:#1e1e1e;color:#d4d4d4;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;margin:0;">' +
						escHtml( response.data.manual ) + '</pre></div>'
					);
				}
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( __( 'Apply Apache Fix', 'twt-aeo-ultimate' ) );
			$status.text( __( 'Request failed.', 'twt-aeo-ultimate' ) ).css( 'color', '#cc0000' );
		} );
	} );

}( jQuery ) );
