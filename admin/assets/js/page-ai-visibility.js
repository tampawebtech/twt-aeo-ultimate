/**
 * AI Visibility board — the client half.
 *
 * Everything here drives markup rendered by class-page-ai-visibility.php and
 * talks to the AJAX handlers in class-visibility.php. The run loop advances
 * ONE check per request: the server records each check as it lands, so a
 * closed tab loses nothing and Resume picks up at the cursor.
 *
 * No libraries beyond jQuery. Guarded on the localized object.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.twtAeoVisibility === 'undefined' ) {
		return;
	}

	var cfg     = window.twtAeoVisibility;
	var strings = cfg.strings || {};
	var actions = cfg.actions || {};
	var engines = cfg.engines || {};

	var $wrap = $( '#twt-aeo-vis' );
	if ( ! $wrap.length ) {
		return;
	}

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce  = cfg.nonce;
		return $.post( cfg.ajaxUrl, data );
	}

	function setStatus( $el, text, tone ) {
		$el.removeClass( 'is-ok is-err' ).text( text || '' );
		if ( 'ok' === tone ) {
			$el.addClass( 'is-ok' );
		} else if ( 'err' === tone ) {
			$el.addClass( 'is-err' );
		}
	}

	function errorOf( resp ) {
		if ( resp && resp.data && resp.data.error ) {
			return resp.data.error;
		}
		if ( resp && resp.error ) {
			return resp.error;
		}
		return strings.error || 'Something went wrong.';
	}

	/* ─────────────────────────── allocation totals ─────────────────────────── */

	var $allocForm = $( '#twt-aeo-vis-alloc-form' );
	var LEVELS     = [ 'company', 'brand', 'collection', 'type', 'product', 'post' ];
	var FIELD_OF   = {
		company:    'company',
		brand:      'per_brand',
		collection: 'per_collection',
		type:       'per_type',
		product:    'per_product',
		post:       'per_post'
	};
	// Colour per level, read off the legend the PHP rendered, so the palette
	// lives in exactly one place.
	var levelColors = {};
	$( '#twt-aeo-vis-bar .twt-aeo-vis__bar-legend > span' ).each( function () {
		var level  = $( this ).attr( 'data-level' );
		var swatch = $( this ).find( '.twt-aeo-vis__swatch' );
		if ( level && swatch.length ) {
			levelColors[ level ] = swatch.css( 'background-color' );
		}
	} );

	function allocValue( field ) {
		var v = parseInt( $allocForm.find( '[name="' + field + '"]' ).val(), 10 );
		return isNaN( v ) || v < 0 ? 0 : v;
	}

	function checkedEngines() {
		return $allocForm.find( 'input[name="engine[]"]:checked:not(:disabled)' )
			.map( function () { return this.value; } ).get();
	}

	/**
	 * Mirror of TWTAEO_Visibility_Questions::allocation_totals — questions per
	 * level from the counts printed as data-attributes, times engines = checks.
	 */
	function computeTotals() {
		var counts = {
			brand:      parseInt( $allocForm.attr( 'data-brands' ), 10 ) || 0,
			collection: parseInt( $allocForm.attr( 'data-collections' ), 10 ) || 0,
			type:       parseInt( $allocForm.attr( 'data-types' ), 10 ) || 0,
			product:    parseInt( $allocForm.attr( 'data-products' ), 10 ) || 0,
			post:       parseInt( $allocForm.attr( 'data-posts' ), 10 ) || 0
		};
		var byLevel = {
			company:    allocValue( 'company' ),
			brand:      allocValue( 'per_brand' ) * counts.brand,
			collection: allocValue( 'per_collection' ) * counts.collection,
			type:       allocValue( 'per_type' ) * counts.type,
			product:    allocValue( 'per_product' ) * counts.product,
			post:       allocValue( 'per_post' ) * counts.post
		};
		var questions = 0;
		$.each( byLevel, function ( k, v ) { questions += v; } );
		var nEngines = checkedEngines().length;
		return {
			byLevel:   byLevel,
			questions: questions,
			engines:   nEngines,
			checks:    questions * nEngines
		};
	}

	function redrawBar( byLevel ) {
		var svg = document.querySelector( '#twt-aeo-vis-bar svg' );
		if ( ! svg ) {
			return;
		}
		var W = 600, H = svg.viewBox && svg.viewBox.baseVal ? svg.viewBox.baseVal.height : 18;
		var total = 0;
		$.each( byLevel, function ( k, v ) { total += v; } );
		$( svg ).find( 'rect' ).remove();
		var ns = 'http://www.w3.org/2000/svg';
		function rect( x, w, fill, level ) {
			var r = document.createElementNS( ns, 'rect' );
			r.setAttribute( 'x', String( x ) );
			r.setAttribute( 'y', '0' );
			r.setAttribute( 'width', String( w ) );
			r.setAttribute( 'height', String( H ) );
			r.setAttribute( 'fill', fill );
			if ( level ) {
				r.setAttribute( 'data-level', level );
			}
			svg.appendChild( r );
		}
		if ( 0 === total ) {
			rect( 0, W, '#e3e5e7', '' );
		} else {
			var x = 0;
			$.each( LEVELS, function ( i, level ) {
				var v = byLevel[ level ] || 0;
				if ( v <= 0 ) {
					return;
				}
				var w = ( v / total ) * W;
				rect( x, Math.max( w, 1 ), levelColors[ level ] || '#8c9196', level );
				x += w;
			} );
		}
		$.each( LEVELS, function ( i, level ) {
			$( '#twt-aeo-vis-bar [data-level-n="' + level + '"]' ).text( byLevel[ level ] || 0 );
		} );
	}

	function fmt( n ) {
		try {
			return n.toLocaleString();
		} catch ( e ) {
			return String( n );
		}
	}

	function refreshTotals() {
		var t = computeTotals();
		var $arith = $( '#twt-aeo-vis-arith' );
		$arith.find( '[data-q]' ).text( fmt( t.questions ) );
		$arith.find( '[data-e]' ).text( t.engines );
		$arith.find( '[data-c]' ).text( fmt( t.checks ) );
		var cap = parseInt( $allocForm.attr( 'data-cap' ), 10 ) || 0;
		var $days = $arith.find( '[data-days]' );
		if ( cap > 0 ) {
			var days = Math.max( 1, Math.ceil( t.checks / cap ) );
			$days.text( days + ' ' + ( 1 === days ? ( strings.day || 'day' ) : ( strings.days || 'days' ) ) );
		} else {
			$days.text( strings.capZero || '—' );
		}
		// The Run card repeats the arithmetic — keep it honest too.
		$( '#twt-aeo-vis-run [data-run-c]' ).text( fmt( t.checks ) );
		$( '#twt-aeo-vis-run [data-run-q]' ).text( fmt( t.questions ) );
		$( '#twt-aeo-vis-run [data-run-e]' ).text( t.engines );
		redrawBar( t.byLevel );
	}

	$allocForm.on( 'input change', '.twt-aeo-vis__alloc-input, input[name="engine[]"]', refreshTotals );

	$allocForm.on( 'click', '.twt-aeo-vis__preset', function () {
		var preset;
		try {
			preset = JSON.parse( $( this ).attr( 'data-preset' ) || '{}' );
		} catch ( e ) {
			preset = {};
		}
		$.each( FIELD_OF, function ( level, field ) {
			if ( typeof preset[ field ] !== 'undefined' ) {
				$allocForm.find( '[name="' + field + '"]' ).val( preset[ field ] );
			}
		} );
		refreshTotals();
	} );

	/* ─────────────────────────── save allocation ──────────────────────────── */

	function allocData() {
		var data = {};
		$.each( FIELD_OF, function ( level, field ) {
			data[ field ] = allocValue( field );
		} );
		data.engine = checkedEngines();
		return data;
	}

	$allocForm.on( 'submit', function ( e ) {
		e.preventDefault();
		var $status = $( '#twt-aeo-vis-save-status' );
		var data    = allocData();
		data.daily_cap       = $allocForm.find( '[name="daily_cap"]' ).val();
		data.x_handle        = $allocForm.find( '[name="x_handle"]' ).val();
		data.alternate_hosts = $allocForm.find( '[name="alternate_hosts"]' ).val();
		setStatus( $status, strings.saving || 'Saving…' );
		post( actions.save, data ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatus( $status, ( resp.data && resp.data.message ) || 'Saved.', 'ok' );
			} else {
				setStatus( $status, errorOf( resp ), 'err' );
			}
		} ).fail( function () {
			setStatus( $status, strings.error || 'Something went wrong.', 'err' );
		} );
	} );

	/* ──────────────────────── brand & company tracking ─────────────────────── */

	var $brandForm = $( '#twt-aeo-vis-brands-form' );
	var $brandRows = $( '#twt-aeo-vis-brand-rows' );

	/* Row names carry their index. Rewrite them after any add or remove so the
	   posted array has no holes — PHP would otherwise read brand_items[2] with
	   no [0] and [1] as a sparse map, and the row order the merchant sees would
	   stop matching the order that is stored. */
	function reindexBrands() {
		$brandRows.find( '[data-brand-row]' ).each( function ( i ) {
			$( this ).find( '[data-name]' ).each( function () {
				var field = $( this ).attr( 'data-name' );
				$( this ).attr( 'name', 'brand_items[' + i + '][' + field + ']' );
			} );
		} );
	}

	$( '#twt-aeo-vis-brand-add' ).on( 'click', function () {
		var tpl = $( '#twt-aeo-vis-brand-tpl' ).html() || '';
		if ( ! tpl ) {
			return;
		}
		$brandRows.append( tpl.replace( /__i__/g, String( $brandRows.find( '[data-brand-row]' ).length ) ) );
		reindexBrands();
		$brandRows.find( '[data-brand-row]' ).last().find( 'input[data-name="label"]' ).trigger( 'focus' );
	} );

	$brandRows.on( 'click', '[data-brand-remove]', function () {
		$( this ).closest( '[data-brand-row]' ).remove();
		reindexBrands();
	} );

	function showRejects( rows ) {
		var $box = $( '#twt-aeo-vis-brand-rejects' );
		$box.empty();
		if ( ! rows || ! rows.length ) {
			$box.prop( 'hidden', true );
			return;
		}
		$( '<p>' ).text( strings.notSaved || 'Not saved — these would not match anything:' ).appendTo( $box );
		var $ul = $( '<ul>' ).appendTo( $box );
		$.each( rows, function ( _i, r ) {
			$( '<li>' )
				.append( $( '<code>' ).text( ( r.owner ? r.owner + ': ' : '' ) + r.url ) )
				.append( document.createTextNode( ' — ' + r.reason ) )
				.appendTo( $ul );
		} );
		$box.prop( 'hidden', false );
	}

	$brandForm.on( 'submit', function ( e ) {
		e.preventDefault();
		reindexBrands();
		var $status = $( '#twt-aeo-vis-brands-status' );
		setStatus( $status, strings.saving || 'Saving…' );
		var data = $( this ).serialize()
			+ '&action=' + encodeURIComponent( actions.saveBrands )
			+ '&nonce=' + encodeURIComponent( cfg.nonce );
		$.post( cfg.ajaxUrl, data ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatus( $status, ( resp.data && resp.data.message ) || 'Saved.', 'ok' );
				showRejects( resp.data && resp.data.rejected );
			} else {
				setStatus( $status, errorOf( resp ), 'err' );
			}
		} ).fail( function () {
			setStatus( $status, strings.error || 'Something went wrong.', 'err' );
		} );
	} );

	/* ────────────────────────────── questions ─────────────────────────────── */

	var $qStatus = $( '#twt-aeo-vis-q-status' );
	var $qList   = $( '#twt-aeo-vis-q-list' );

	$( '#twt-aeo-vis-preview' ).on( 'click', function () {
		var $btn = $( this ).prop( 'disabled', true );
		setStatus( $qStatus, strings.building || 'Building…' );
		post( actions.preview, allocData() ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && typeof resp.data.html === 'string' ) {
				$qList.html( resp.data.html );
				var msg = ( strings.enabledOf || '%1$s of %2$s questions enabled.' )
					.replace( '%1$s', fmt( resp.data.enabled || 0 ) )
					.replace( '%2$s', fmt( resp.data.total || 0 ) );
				setStatus( $qStatus, msg, 'ok' );
			} else {
				setStatus( $qStatus, errorOf( resp ), 'err' );
			}
		} ).fail( function () {
			setStatus( $qStatus, strings.error || 'Something went wrong.', 'err' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '#twt-aeo-vis-polish' ).on( 'click', function () {
		var $btn    = $( this ).prop( 'disabled', true );
		var offset  = 0;
		var changed = 0;
		function polishStep() {
			setStatus( $qStatus, strings.polishing || 'Asking your AI provider to rephrase the post questions…' );
			post( actions.polish, { offset: offset } ).done( function ( resp ) {
				if ( resp && resp.success && resp.data ) {
					changed += resp.data.changed || 0;
					if ( typeof resp.data.html === 'string' ) {
						$qList.html( resp.data.html );
					}
					if ( resp.data.remaining > 0 && resp.data.processed > 0 ) {
						offset = resp.data.next_offset || ( offset + resp.data.processed );
						polishStep();
						return;
					}
					var msg = ( strings.polished || '%1$s questions rephrased — saved with source “ai”.' ).replace( '%1$s', fmt( changed ) );
					setStatus( $qStatus, msg, 'ok' );
				} else {
					setStatus( $qStatus, errorOf( resp ), 'err' );
				}
				$btn.prop( 'disabled', false );
			} ).fail( function () {
				setStatus( $qStatus, strings.error || 'Something went wrong.', 'err' );
				$btn.prop( 'disabled', false );
			} );
		}
		polishStep();
	} );

	$( '#twt-aeo-vis-reset-q' ).on( 'click', function () {
		var $btn = $( this ).prop( 'disabled', true );
		setStatus( $qStatus, strings.loading || 'Loading…' );
		post( actions.resetQ, {} ).done( function () {
			window.location.reload();
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setStatus( $qStatus, strings.error || 'Something went wrong.', 'err' );
		} );
	} );

	// Summary line ↔ the per-level boxes.
	$qList.on( 'click', '.twt-aeo-vis__q-toggle', function () {
		var $btn    = $( this );
		var $levels = $qList.find( '.twt-aeo-vis__qlevels' );
		var open    = $levels.prop( 'hidden' );
		$levels.prop( 'hidden', ! open );
		$btn.text( open ? $btn.attr( 'data-close' ) : $btn.attr( 'data-open' ) );
	} );

	// Per-level Show/Hide. While a level is collapsed its ticks travel as the
	// hidden inputs PHP rendered; showing it hands control to the checkboxes,
	// hiding it writes the current ticks back into hidden inputs. Either way a
	// save sees every level's state.
	$qList.on( 'click', '.twt-aeo-vis__qlevel-toggle', function () {
		var $btn    = $( this );
		var $level  = $btn.closest( '.twt-aeo-vis__qlevel' );
		var $list   = $level.find( '.twt-aeo-vis__qlist' );
		var $hidden = $level.find( '.twt-aeo-vis__qlevel-hidden' );
		var show    = $list.prop( 'hidden' );
		if ( show ) {
			$hidden.empty();
			$list.find( 'input[type="checkbox"]' ).prop( 'disabled', false );
			$list.prop( 'hidden', false );
			$btn.text( $btn.attr( 'data-hide' ) );
		} else {
			$list.find( 'input[type="checkbox"]:checked' ).each( function () {
				$( '<input>', { type: 'hidden', name: this.name, value: 'on' } ).appendTo( $hidden );
			} );
			$list.find( 'input[type="checkbox"]' ).prop( 'disabled', true );
			$list.prop( 'hidden', true );
			$btn.text( $btn.attr( 'data-show' ) );
		}
	} );

	$( '#twt-aeo-vis-q-form' ).on( 'submit', function ( e ) {
		e.preventDefault();
		setStatus( $qStatus, strings.saving || 'Saving…' );
		var data = $( this ).serialize()
			+ '&action=' + encodeURIComponent( actions.saveQuestions )
			+ '&nonce=' + encodeURIComponent( cfg.nonce );
		$.post( cfg.ajaxUrl, data ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatus( $qStatus, ( resp.data && resp.data.message ) || 'Saved.', 'ok' );
			} else {
				setStatus( $qStatus, errorOf( resp ), 'err' );
			}
		} ).fail( function () {
			setStatus( $qStatus, strings.error || 'Something went wrong.', 'err' );
		} );
	} );

	/* ─────────────────────────────── the run ──────────────────────────────── */

	var running   = false;
	var stopping  = false;
	var runId     = '';
	var netFails  = 0;
	var NET_LIMIT = 10;
	var $progress = $( '#twt-aeo-vis-progress' );
	var $runMsg   = $( '#twt-aeo-vis-run-msg' );
	var $start    = $( '#twt-aeo-vis-start' );
	var $stop     = $( '#twt-aeo-vis-stop' );
	var $resume   = $( '#twt-aeo-vis-resume' );
	var $persona  = $( '#twt-aeo-vis-persona' );

	// "Use buyer personas": saved at once, then the page reloads so the editor,
	// the picker and the Local Pack field all appear or go away together.
	$( '#twt-aeo-vis-personas-on' ).on( 'change', function () {
		var $box    = $( this );
		var $status = $( '#twt-aeo-vis-personas-on-status' );
		$box.prop( 'disabled', true );
		setStatus( $status, strings.saving || 'Saving…' );
		post( actions.personasToggle, { on: $box.is( ':checked' ) ? '1' : '0' } ).done( function ( resp ) {
			if ( resp && resp.success ) {
				window.location.reload();
			} else {
				setStatus( $status, errorOf( resp ), 'err' );
				$box.prop( 'disabled', false ).prop( 'checked', ! $box.is( ':checked' ) );
			}
		} ).fail( function () {
			setStatus( $status, strings.error || 'Something went wrong.', 'err' );
			$box.prop( 'disabled', false ).prop( 'checked', ! $box.is( ':checked' ) );
		} );
	} );

	// Keep the "Ask as" picker in step with the persona editor (persona-tags.js).
	$( document ).on( 'twtaeo:personas', function ( _e, list ) {
		var current = $persona.val();
		$persona.find( 'option' ).not( ':first' ).remove();
		( list || [] ).forEach( function ( p ) {
			if ( p && p.id && p.label ) {
				$( '<option></option>' ).val( p.id ).text( p.label ).appendTo( $persona );
			}
		} );
		$persona.val( $persona.find( 'option[value="' + current + '"]' ).length ? current : '' );
	} );

	function engineLabel( id ) {
		return engines[ id ] || id;
	}

	function showRunMsg( text ) {
		if ( text ) {
			$runMsg.text( text ).prop( 'hidden', false );
		} else {
			$runMsg.text( '' ).prop( 'hidden', true );
		}
	}

	function runUi( on ) {
		running = on;
		$start.prop( 'disabled', on );
		$resume.prop( 'disabled', on );
		$stop.prop( 'hidden', ! on ).prop( 'disabled', false );
	}

	function progressLine( cursor, total, check ) {
		var line = cursor + '/' + total;
		if ( check ) {
			line += ' · ' + ( strings.last || 'last:' ) + ' ' + engineLabel( check.engine )
				+ ' · ' + ( check.verdict || 'unavailable' );
			if ( check.error ) {
				line += ' — ' + check.error;
			}
		}
		$progress.text( line );
	}

	function finishRun( reloadPage, message ) {
		runUi( false );
		stopping = false;
		if ( message ) {
			showRunMsg( message );
		}
		if ( reloadPage ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'run', runId );
			window.location.href = url.toString();
		}
	}

	function stepLoop() {
		if ( ! running ) {
			return;
		}
		if ( stopping ) {
			post( actions.stop, { run_id: runId } ).always( function () {
				finishRun( true, null );
			} );
			return;
		}
		post( actions.step, { run_id: runId } ).done( function ( resp ) {
			netFails = 0;
			resp = resp || {};
			if ( resp.busy ) {
				// The background worker holds the lock — wait, don't double-step.
				$progress.text( strings.background || 'The background worker is on it — waiting…' );
				window.setTimeout( stepLoop, 5000 );
				return;
			}
			if ( resp.done ) {
				if ( typeof resp.cursor !== 'undefined' ) {
					progressLine( resp.cursor, resp.total, resp.check );
				}
				$progress.text( strings.done || 'Run complete.' );
				finishRun( true, null );
				return;
			}
			if ( false === resp.ok ) {
				// Paused at the cap, or an engine lost its key: the run is saved —
				// stop the loop and say why. Anything else unexpected also ends here.
				finishRun( false, resp.error || ( strings.error || 'Something went wrong.' ) );
				return;
			}
			progressLine( resp.cursor, resp.total, resp.check );
			stepLoop();
		} ).fail( function () {
			netFails++;
			if ( netFails >= NET_LIMIT ) {
				finishRun( false, strings.gaveUp || 'Too many network failures — the run is saved; resume it any time.' );
				return;
			}
			$progress.text( strings.retrying || 'Retrying…' );
			window.setTimeout( stepLoop, 2000 );
		} );
	}

	$start.on( 'click', function () {
		if ( running ) {
			return;
		}
		showRunMsg( null );
		var data = allocData();
		data.persona = $persona.val() || '';
		data.journey = $( '#twt-aeo-vis-journey' ).val() || '0';
		runUi( true );
		$progress.text( strings.starting || 'Starting…' );
		post( actions.start, data ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && resp.data.run_id ) {
				runId    = resp.data.run_id;
				netFails = 0;
				if ( resp.data.personas_suggested ) {
					showRunMsg( strings.personasSuggested || 'Buyer personas were suggested from your site — pick one under “Ask as” for your next run.' );
				}
				progressLine( resp.data.cursor || 0, resp.data.total || 0, null );
				stepLoop();
			} else {
				finishRun( false, errorOf( resp ) );
				$progress.text( '' );
			}
		} ).fail( function () {
			finishRun( false, strings.error || 'Something went wrong.' );
			$progress.text( '' );
		} );
	} );

	$resume.on( 'click', function () {
		if ( running ) {
			return;
		}
		showRunMsg( null );
		runId    = $( this ).attr( 'data-run-id' ) || '';
		netFails = 0;
		if ( ! runId ) {
			return;
		}
		runUi( true );
		progressLine( $( this ).attr( 'data-cursor' ) || 0, $( this ).attr( 'data-total' ) || 0, null );
		stepLoop();
	} );

	$stop.on( 'click', function () {
		if ( ! running ) {
			return;
		}
		stopping = true;
		$( this ).prop( 'disabled', true );
		$progress.text( strings.stopping || 'Stopping…' );
	} );

	// Leaving mid-run: the server has every recorded check; nothing to flush.

	/* ─────────────────────────── sample data ──────────────────────────────── */

	$wrap.on( 'click', '#twt-aeo-vis-demo', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( strings.loading || 'Loading…' );
		post( actions.demo, {} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				var url = new URL( window.location.href );
				if ( resp.data && resp.data.run_id ) {
					url.searchParams.set( 'run', resp.data.run_id );
				}
				window.location.href = url.toString();
			} else {
				$btn.prop( 'disabled', false );
				window.alert( errorOf( resp ) );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			window.alert( strings.error || 'Something went wrong.' );
		} );
	} );

	$wrap.on( 'click', '#twt-aeo-vis-undemo', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( strings.removing || 'Removing…' );
		post( actions.undemo, {} ).done( function () {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'run' );
			window.location.href = url.toString();
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			window.alert( strings.error || 'Something went wrong.' );
		} );
	} );

	/* ──────────────────────── run / scope navigation ──────────────────────── */

	$wrap.on( 'change', '.twt-aeo-vis__navselect', function () {
		var param = $( this ).attr( 'data-param' );
		if ( ! param ) {
			return;
		}
		var url = new URL( window.location.href );
		if ( '' === this.value ) {
			url.searchParams.delete( param );
		} else {
			url.searchParams.set( param, this.value );
		}
		window.location.href = url.toString();
	} );

	/* ─────────────────────────── answer drawers ───────────────────────────── */

	$wrap.on( 'click', '.twt-aeo-vis__cell', function () {
		var key     = $( this ).attr( 'data-drawer' );
		var $drawer = $wrap.find( '.twt-aeo-vis__drawer[data-drawer-for="' + key + '"]' );
		if ( ! $drawer.length ) {
			return;
		}
		var open = $drawer.prop( 'hidden' );
		$drawer.prop( 'hidden', ! open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
	} );

	refreshTotals();
} )( jQuery );
