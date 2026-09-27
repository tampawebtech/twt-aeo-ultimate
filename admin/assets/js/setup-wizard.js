/* global twtAeoWizard */
( function () {
	'use strict';

	// ── Module card toggle (visual state without JS reliance on :has) ─────────

	document.querySelectorAll( '.twt-aeo-wizard__module-item' ).forEach( function ( item ) {
		var checkbox = item.querySelector( 'input[type="checkbox"]' );
		if ( ! checkbox ) return;

		function syncState() {
			if ( checkbox.checked ) {
				item.classList.add( 'twt-aeo-wizard__module-item--on' );
			} else {
				item.classList.remove( 'twt-aeo-wizard__module-item--on' );
			}
		}

		syncState();
		checkbox.addEventListener( 'change', syncState );
	} );

	// ── Autopilot launch ──────────────────────────────────────────────────────

	var launchBtn  = document.getElementById( 'twt-aeo-autopilot-launch' );
	var nonceInput = document.getElementById( 'twt-aeo-autopilot-nonce' );
	var completeUrl = document.getElementById( 'twt-aeo-complete-url' );

	if ( ! launchBtn || ! nonceInput || ! completeUrl ) return;

	launchBtn.addEventListener( 'click', function () {
		launchBtn.disabled    = true;
		launchBtn.textContent = twtAeoWizard.runningText || 'Running…';

		var taskEls = document.querySelectorAll( '.twt-aeo-wizard__task[data-task]' );

		// Set all pending badges to "Running…"
		taskEls.forEach( function ( el ) {
			var badge = el.querySelector( '.twt-aeo-wizard__status-badge' );
			if ( badge ) {
				badge.className = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--running';
				badge.textContent = twtAeoWizard.runningText || 'Running…';
			}
		} );

		var body = new FormData();
		body.append( 'action', 'twtaeo_wizard_autopilot' );
		body.append( 'nonce',  nonceInput.value );

		fetch( twtAeoWizard.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success && data.data && data.data.results ) {
					var results = data.data.results;

					taskEls.forEach( function ( el ) {
						var taskId = el.getAttribute( 'data-task' );
						var badge  = el.querySelector( '.twt-aeo-wizard__status-badge' );
						if ( ! badge ) return;

						if ( results[ taskId ] === 'done' ) {
							badge.className  = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--done';
							badge.textContent = twtAeoWizard.doneText || 'Done';
						} else if ( results[ taskId ] === 'queued' ) {
							// Background half: heavy no-AI work continues on a
							// cron event; the completion screen reports on it.
							badge.className  = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--done';
							badge.textContent = twtAeoWizard.queuedText || 'Running in background';
						} else if ( results[ taskId ] === 'skipped' ) {
							// Another plugin owns this surface — skipping is the
							// correct outcome, not a failure.
							badge.className  = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--pending';
							badge.textContent = twtAeoWizard.skippedText || 'Skipped — handled by another plugin';
						} else {
							badge.className  = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--error';
							badge.textContent = twtAeoWizard.errorText || 'Error';
						}
					} );

					// Brief pause so user sees the Done badges, then redirect.
					setTimeout( function () {
						window.location.href = data.data.redirect || completeUrl.value;
					}, 900 );
				} else {
					onAutopilotError( taskEls );
				}
			} )
			.catch( function () {
				onAutopilotError( taskEls );
			} );
	} );

	function onAutopilotError( taskEls ) {
		taskEls.forEach( function ( el ) {
			var badge = el.querySelector( '.twt-aeo-wizard__status-badge--running' );
			if ( badge ) {
				badge.className  = 'twt-aeo-wizard__status-badge twt-aeo-wizard__status-badge--error';
				badge.textContent = twtAeoWizard.errorText || 'Error';
			}
		} );

		if ( launchBtn ) {
			launchBtn.disabled    = false;
			launchBtn.textContent = twtAeoWizard.retryText || 'Retry';
		}
	}
} )();
