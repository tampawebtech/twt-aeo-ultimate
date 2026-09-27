/**
 * Documents screen — keep processing moving while the page is open.
 *
 * WP-Cron only fires on a page load, so a quiet site could leave an upload
 * waiting for its next visitor. While anything is queued, this polls the
 * budgeted tick endpoint, shows progress, and reloads once everything is done
 * so the tables show the final state.
 */
( function () {
	'use strict';

	var cfg = window.twtaeoDocs;
	if ( ! cfg ) {
		return;
	}

	// Confirm before deleting a document.
	document.querySelectorAll( 'form.twtaeo-cge-delete' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( cfg.i18n.confirmDelete ) ) {
				e.preventDefault();
			}
		} );
	} );

	// Uploads travel in pieces. A dropped connection, a restart or a power cut
	// costs at most one piece: the page retries on its own, and choosing the
	// same file again later resumes from what the server already has. Files
	// too large for this server to read are stopped here, with how to split
	// them, before anything is sent.
	var uploadForm = document.getElementById( 'twtaeo-cge-upload' );
	var statusBox  = document.getElementById( 'twtaeo-cge-status' );

	function say( text, kind ) {
		if ( ! statusBox ) {
			return;
		}
		statusBox.className     = 'notice inline notice-' + ( kind || 'info' );
		statusBox.style.display = 'block';
		statusBox.querySelector( 'p' ).textContent = text;
	}

	function fmt( n ) {
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}

	function post( action, fields, blob ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( fields ).forEach( function ( k ) {
			fd.append( k, fields[ k ] );
		} );
		if ( blob ) {
			fd.append( 'piece', blob, 'piece' );
		}
		return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	// Retry network failures with growing pauses; a server "no" is final.
	function withRetry( fn ) {
		var delays = [ 2, 5, 10, 20, 30, 60, 60, 60 ];
		var attempt = 0;
		function run() {
			return fn().catch( function ( err ) {
				if ( err && err.final ) {
					throw err;
				}
				if ( attempt >= delays.length ) {
					throw err;
				}
				var secs = delays[ attempt++ ];
				say( cfg.i18n.retrying.replace( '%s', secs ), 'warning' );
				return wait( secs * 1000 ).then( run );
			} );
		}
		return run();
	}

	function fail( res ) {
		var err   = new Error( ( res && res.data && res.data.message ) || cfg.i18n.failed );
		err.final = true;
		throw err;
	}

	// The consent box: whether an AI provider may read the first pages to
	// label the document. Sent with the upload, stored with the document.
	function aiOk() {
		var box = uploadForm ? uploadForm.querySelector( 'input[name="ai_ok"]' ) : null;
		return box && box.checked ? 1 : 0;
	}

	function uploadOne( file ) {
		return withRetry( function () {
			return post( 'twtaeo_cge_up_start', { name: file.name, size: file.size, modified: file.lastModified || 0, ai_ok: aiOk() } );
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				fail( res );
			}
			var key   = res.data.key;
			var piece = res.data.piece;
			var have  = res.data.have;
			if ( have > 0 && have < file.size ) {
				say( cfg.i18n.resuming.replace( '%1$s', file.name ).replace( '%2$s', fmt( have ) ) );
			}

			function next() {
				if ( have >= file.size ) {
					return withRetry( function () {
						return post( 'twtaeo_cge_up_finish', { key: key } );
					} ).then( function ( done ) {
						if ( ! done || ! done.success ) {
							fail( done );
						}
					} );
				}
				var blob = file.slice( have, Math.min( have + piece, file.size ) );
				return withRetry( function () {
					return post( 'twtaeo_cge_up_piece', { key: key, offset: have }, blob ).then( function ( r ) {
						if ( ! r || ! r.success ) {
							if ( r && r.data && r.data.restart ) {
								fail( r );
							}
							throw new Error( ( r && r.data && r.data.message ) || cfg.i18n.failed );
						}
						return r;
					} );
				} ).then( function ( r ) {
					have = r.data.have;
					say( cfg.i18n.uploading.replace( '%1$s', file.name ).replace( '%2$s', Math.floor( ( have / file.size ) * 100 ) + '%' ) );
					return next();
				} );
			}
			return next();
		} );
	}

	if ( uploadForm ) {
		uploadForm.addEventListener( 'submit', function ( e ) {
			var input = uploadForm.querySelector( 'input[type="file"]' );
			var files = input && input.files ? Array.prototype.slice.call( input.files ) : [];
			var errs  = [];

			files.forEach( function ( f ) {
				if ( cfg.maxFile && f.size > cfg.maxFile ) {
					errs.push( cfg.i18n.tooBig.replace( '%1$s', f.name ).replace( '%2$s', fmt( f.size ) ).replace( '%3$s', fmt( cfg.maxFile ) ) );
				}
			} );
			if ( errs.length ) {
				e.preventDefault();
				say( errs.join( ' ' ) + ' ' + cfg.i18n.split, 'error' );
				if ( statusBox ) {
					statusBox.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
				return;
			}

			// Browsers without Blob.slice/fetch fall back to the plain form.
			if ( ! window.fetch || ! window.FormData || ! window.Promise || ! files.length || ! Blob.prototype.slice ) {
				return;
			}
			e.preventDefault();

			var button = uploadForm.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
			}
			var errors = [];
			files.reduce( function ( chain, file ) {
				return chain.then( function () {
					return uploadOne( file ).catch( function ( err ) {
						errors.push( file.name + ': ' + err.message );
					} );
				} );
			}, Promise.resolve() ).then( function () {
				if ( errors.length ) {
					say( errors.join( ' ' ), 'error' );
					if ( button ) {
						button.disabled = false;
					}
					return;
				}
				say( cfg.i18n.uploaded, 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 800 );
			} );
		} );
	}

	// The AI-label switch is saved as soon as it is flipped, so it stays the
	// way it was left the next time the page opens.
	var aiSwitch = document.getElementById( 'twtaeo-cge-ai-ok' );
	if ( aiSwitch ) {
		aiSwitch.addEventListener( 'change', function () {
			post( 'twtaeo_cge_ai_consent', { on: aiSwitch.checked ? 1 : 0 } ).catch( function () {} );
		} );
	}

	// Label form: choosing a kind picks its usual answer mode, which the
	// owner can still change.
	document.querySelectorAll( 'select.twtaeo-cge-kind' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var opt   = select.options[ select.selectedIndex ];
			var mode  = opt ? opt.getAttribute( 'data-mode' ) : '';
			var radio = mode ? select.form.querySelector( 'input[name="answer_mode"][value="' + mode + '"]' ) : null;
			if ( radio ) {
				radio.checked = true;
			}
		} );
	} );

	// Rich copy without the Clipboard API (plain-HTTP admin screens): select
	// the rendered table itself, so the browser copies it as HTML.
	function copyRich( html ) {
		var box = document.createElement( 'div' );
		box.innerHTML      = html;
		box.style.position = 'fixed';
		box.style.left     = '-9999px';
		document.body.appendChild( box );
		var range = document.createRange();
		range.selectNodeContents( box );
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
		sel.removeAllRanges();
		document.body.removeChild( box );
		return ok;
	}

	// Copy a document passage for pasting into the page editor.
	document.querySelectorAll( 'button.twtaeo-copy' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var src   = document.getElementById( btn.getAttribute( 'data-copy' ) );
			var text  = src ? src.value : '';
			var label = btn.textContent;

			function done( ok ) {
				btn.textContent = ok ? cfg.i18n.copied : cfg.i18n.copyFailed;
				window.setTimeout( function () { btn.textContent = label; }, 2000 );
			}

			// A table goes on the clipboard as HTML too, so the editor pastes a
			// real table; anything that only takes text gets the plain copy.
			var htmlSrc = btn.getAttribute( 'data-copy-html' ) ? document.getElementById( btn.getAttribute( 'data-copy-html' ) ) : null;
			var html    = htmlSrc ? htmlSrc.innerHTML : '';

			if ( html && navigator.clipboard && navigator.clipboard.write && window.ClipboardItem && window.isSecureContext ) {
				navigator.clipboard.write( [ new window.ClipboardItem( {
					'text/html': new Blob( [ html ], { type: 'text/html' } ),
					'text/plain': new Blob( [ text ], { type: 'text/plain' } )
				} ) ] ).then( function () { done( true ); }, function () { done( copyRich( html ) ); } );
				return;
			}
			if ( html ) {
				done( copyRich( html ) );
				return;
			}
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( function () { done( true ); }, function () { done( false ); } );
				return;
			}
			// Plain-HTTP admin screens have no Clipboard API.
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			document.body.removeChild( ta );
			done( ok );
		} );
	} );

	if ( ! cfg.pending ) {
		return;
	}

	var box      = document.getElementById( 'twtaeo-cge-status' );
	var siteLine = document.getElementById( 'twtaeo-cge-site-line' );
	var failures = 0;

	function show( text, isError ) {
		if ( ! box ) {
			return;
		}
		box.className            = 'notice inline ' + ( isError ? 'notice-error' : 'notice-info' );
		box.style.display        = 'block';
		box.querySelector( 'p' ).textContent = text;
	}

	function tick() {
		var fd = new FormData();
		fd.append( 'action', cfg.action );
		fd.append( 'nonce', cfg.nonce );

		fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( ( res && res.data ) || cfg.i18n.failed );
				}
				failures = 0;
				var data = res.data;
				if ( data.error ) {
					show( data.error, true );
					return;
				}
				if ( data.site && 'running' === data.site.status && siteLine ) {
					siteLine.textContent = cfg.i18n.indexing
						.replace( '%1$s', data.site.indexed )
						.replace( '%2$s', data.site.total );
				}
				if ( data.pending ) {
					show( cfg.i18n.working, false );
					window.setTimeout( tick, 2500 );
				} else {
					show( cfg.i18n.done, false );
					window.setTimeout( function () { window.location.reload(); }, 800 );
				}
			} )
			.catch( function ( err ) {
				failures++;
				if ( failures >= 3 ) {
					show( err.message || cfg.i18n.failed, true );
					return;
				}
				window.setTimeout( tick, 5000 );
			} );
	}

	show( cfg.i18n.working, false );
	tick();
}() );
