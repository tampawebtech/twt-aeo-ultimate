/* global wp, twtAeoAbilities */
/**
 * TWT AEO — WordPress 7.0 Client-Side Abilities + Command Palette registration.
 *
 * Exposes four site-management operations to the WordPress Command Palette
 * (core/commands store, Ctrl+K / ⌘K) and, where available, to the WP 7.0
 * Client-Side Abilities API so the built-in AI assistant can invoke them via
 * voice or text prompt.
 *
 * Degrades cleanly on WP < 6.3 (no Command Palette) and on WP < 7.0
 * (no wp.abilities global).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! window.wp || ! window.wp.data ) return;

		const cfg      = window.twtAeoAbilities || {};
		const ajaxUrl  = cfg.ajaxUrl  || '';
		const nonces   = cfg.nonces   || {};
		const adminUrls = cfg.adminUrls || {};

		// ── Utility: authenticated fetch to admin-ajax.php ────────────────────
		function ajaxPost( action, nonce ) {
			const body = new URLSearchParams( { action, nonce } );
			return fetch( ajaxUrl, {
				method:      'POST',
				body,
				credentials: 'same-origin',
			} ).then( function ( r ) { return r.json(); } );
		}

		// ── Utility: surface result in WP notices or browser console ──────────
		function notify( message, type ) {
			type = type || 'success';
			if ( window.wp.data.dispatch( 'core/notices' ) ) {
				wp.data.dispatch( 'core/notices' ).createNotice( type, message, {
					id:            'twt-aeo-ability-notice',
					isDismissible: true,
				} );
				return;
			}
			// Fallback for non-Gutenberg screens.
			const bar = document.getElementById( 'twt-aeo-ability-snack' );
			if ( bar ) {
				bar.textContent = message;
				bar.className   = 'twt-aeo-snack twt-aeo-snack--' + type;
				bar.hidden      = false;
				clearTimeout( bar._tid );
				bar._tid = setTimeout( function () { bar.hidden = true; }, 6000 );
			}
		}

		// Lightweight snack bar injected for non-Gutenberg admin screens.
		( function () {
			if ( document.getElementById( 'twt-aeo-ability-snack' ) ) return;
			const el  = document.createElement( 'div' );
			el.id     = 'twt-aeo-ability-snack';
			el.hidden = true;
			el.setAttribute( 'role', 'status' );
			el.setAttribute( 'aria-live', 'polite' );
			el.style.cssText = [
				'position:fixed', 'bottom:24px', 'left:50%',
				'transform:translateX(-50%)', 'z-index:99999',
				'background:#1d2327', 'color:#fff', 'font-size:13px',
				'padding:10px 20px', 'border-radius:4px',
				'box-shadow:0 2px 8px rgba(0,0,0,.35)',
				'max-width:480px', 'text-align:center',
			].join( ';' );
			document.body.appendChild( el );
		} )();

		// ── Ability definitions ───────────────────────────────────────────────

		var abilities = [
			{
				id:          'twt-aeo/schema-conflict-scan',
				label:       'AEO: Run Schema Conflict Scan',
				description: 'Scans the homepage for duplicate or conflicting structured-data blocks from multiple SEO plugins and reports conflicts.',
				keywords:    [ 'schema', 'conflict', 'scan', 'structured data', 'json-ld', 'aeo', 'seo' ],
				enabled:     true,
				invoke: function ( ctx ) {
					ctx && ctx.close && ctx.close();
					notify( 'Running schema conflict scan…', 'info' );
					ajaxPost( 'twtaeo_schema_conflict_scan', nonces.schemaConflict )
						.then( function ( res ) {
							if ( res.success ) {
								var count = ( res.data && res.data.conflicts )
									? res.data.conflicts.length
									: 0;
								notify(
									count
										? 'Schema scan complete — ' + count + ' conflict' + ( count !== 1 ? 's' : '' ) + ' found. Visit AEO → Schema Conflicts for details.'
										: 'Schema scan complete — no conflicts detected.',
									count ? 'warning' : 'success'
								);
							} else {
								notify( 'Schema scan failed: ' + ( res.data || 'unknown error' ), 'error' );
							}
						} )
						.catch( function () {
							notify( 'Schema scan request failed. Check your connection.', 'error' );
						} );
				},
			},

			{
				id:          'twt-aeo/indexnow-submit-all',
				label:       'AEO: Submit All URLs to IndexNow',
				description: 'Pushes every published post and page URL to Bing IndexNow for near-instant indexing.',
				keywords:    [ 'indexnow', 'bing', 'index', 'submit', 'urls', 'publish', 'aeo' ],
				enabled:     !! cfg.indexNowEnabled,
				invoke: function ( ctx ) {
					ctx && ctx.close && ctx.close();
					notify( 'Submitting all URLs to IndexNow…', 'info' );
					ajaxPost( 'twtaeo_indexnow_submit_all', nonces.indexNow )
						.then( function ( res ) {
							if ( res.success ) {
								var count = ( res.data && res.data.submitted ) || ( res.data && res.data.count ) || '?';
								notify(
									'IndexNow: ' + count + ' URL' + ( count !== 1 ? 's' : '' ) + ' submitted successfully.',
									'success'
								);
							} else {
								notify( 'IndexNow submission failed: ' + ( res.data || 'unknown error' ), 'error' );
							}
						} )
						.catch( function () {
							notify( 'IndexNow request failed. Check your connection.', 'error' );
						} );
				},
			},

			{
				id:          'twt-aeo/open-ai-crawler-watch',
				label:       'AEO: Open AI Crawler Watch',
				description: 'Opens the live feed of AI bot visits to this site.',
				keywords:    [ 'crawler', 'bot', 'ai', 'watch', 'feed', 'gptbot', 'claudebot', 'aeo' ],
				enabled:     true,
				invoke: function ( ctx ) {
					ctx && ctx.close && ctx.close();
					window.location.href = adminUrls.crawlerWatch;
				},
			},

			{
				id:          'twt-aeo/run-crawlability-audit',
				label:       'AEO: Run Crawlability Audit',
				description: 'Opens the crawlability audit page to test how each AI bot sees this site.',
				keywords:    [ 'crawlability', 'audit', 'crawler', 'bot', 'test', 'robots', 'aeo' ],
				enabled:     true,
				invoke: function ( ctx ) {
					ctx && ctx.close && ctx.close();
					window.location.href = adminUrls.crawlabilityAudit;
				},
			},
		];

		var activeAbilities = abilities.filter( function ( a ) { return a.enabled; } );

		// ── 1. WordPress Command Palette (core/commands, WP 6.3+) ─────────────
		var commandsStore = window.wp.commands && window.wp.commands.store;
		if ( commandsStore ) {
			var commandsDispatch = wp.data.dispatch( commandsStore );
			if ( commandsDispatch && commandsDispatch.registerCommand ) {
				activeAbilities.forEach( function ( ability ) {
					try {
						commandsDispatch.registerCommand( {
							name:     ability.id,
							label:    ability.label,
							callback: ability.invoke,
						} );
					} catch ( e ) {
						// Ignore — store may not be fully initialised on this screen.
					}
				} );
			}
		}

		// ── 2. WP 7.0 Client-Side Abilities API (forward-compatible shim) ────
		// wp.abilities is expected to be available in WordPress 7.0+. We register
		// each ability with full semantic metadata so the core AI assistant can
		// surface them as invokable actions via voice or text prompts.
		if ( window.wp.abilities && window.wp.abilities.register ) {
			activeAbilities.forEach( function ( ability ) {
				try {
					wp.abilities.register( {
						id:          ability.id,
						label:       ability.label,
						description: ability.description,
						keywords:    ability.keywords,
						invoke:      ability.invoke,
					} );
				} catch ( e ) {
					// Future API may differ — degrade silently.
				}
			} );
		}
	} );
} )();
