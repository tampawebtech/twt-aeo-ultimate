/* global twtAeoLocalPack, wp */
( function () {
	'use strict';

	var __ = wp.i18n.__;


	if ( typeof twtAeoLocalPack === 'undefined' ) return;

	var subtypesObj = twtAeoLocalPack.subtypes;
	var subtypes    = Object.keys( subtypesObj ).map( function ( key ) {
		return { value: key, label: subtypesObj[ key ] };
	} );

	// ── Predictive business-type picker ──────────────────────────────────────

	var searchInput = document.getElementById( 'lp_business_type_search' );
	var hiddenInput = document.getElementById( 'lp_business_type' );
	var dropdown    = document.getElementById( 'lp_subtype_dropdown' );

	if ( searchInput && hiddenInput && dropdown ) {
		// Pre-fill the visible input with the currently saved label.
		searchInput.value = twtAeoLocalPack.currentLabel || '';

		searchInput.addEventListener( 'input', function () {
			var q = this.value.toLowerCase().trim();
			renderDropdown( q );
		} );

		searchInput.addEventListener( 'focus', function () {
			var q = this.value.toLowerCase().trim();
			if ( q.length === 0 ) {
				renderDropdown( '' );  // Show all on focus if empty
			}
		} );

		function renderDropdown( query ) {
			var matches;
			if ( query.length === 0 ) {
				matches = subtypes.slice( 0, 12 );
			} else {
				matches = subtypes.filter( function ( t ) {
					return t.label.toLowerCase().indexOf( query ) !== -1 ||
						   t.value.toLowerCase().indexOf( query ) !== -1;
				} ).slice( 0, 12 );
			}

			dropdown.innerHTML = '';

			if ( matches.length === 0 ) {
				dropdown.style.display = 'none';
				return;
			}

			matches.forEach( function ( type ) {
				var item = document.createElement( 'div' );
				item.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f1;';
				item.textContent    = type.label;
				item.dataset.value  = type.value;

				item.addEventListener( 'mouseenter', function () {
					this.style.background = '#f0f6fc';
				} );
				item.addEventListener( 'mouseleave', function () {
					this.style.background = '';
				} );
				item.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault(); // Prevent blur on input before click fires
					searchInput.value  = type.label;
					hiddenInput.value  = type.value;
					dropdown.style.display = 'none';
				} );

				dropdown.appendChild( item );
			} );

			dropdown.style.display = 'block';
		}

		searchInput.addEventListener( 'blur', function () {
			// Short delay so mousedown on a dropdown item fires first.
			setTimeout( function () {
				dropdown.style.display = 'none';
				// If the typed text doesn't match any label, restore the previous valid value.
				var currentLabel = subtypesObj[ hiddenInput.value ] || '';
				if ( searchInput.value.trim() !== currentLabel ) {
					searchInput.value = currentLabel;
				}
			}, 180 );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '.lp-subtype-wrapper' ) ) {
				dropdown.style.display = 'none';
			}
		} );
	}

	// ── Opening hours rows ────────────────────────────────────────────────────

	var hoursContainer = document.getElementById( 'lp_hours_rows' );
	var addHoursBtn    = document.getElementById( 'lp_add_hours' );
	var days    = twtAeoLocalPack.days;
	var dayAbbr = twtAeoLocalPack.dayAbbr;

	if ( hoursContainer ) {
		// Remove-row handler (delegated).
		hoursContainer.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'lp-remove-hours' ) ) {
				var row = e.target.closest( '.lp-hours-row' );
				if ( row ) {
					row.remove();
					reindexHours();
				}
			}
		} );
	}

	if ( addHoursBtn && hoursContainer ) {
		addHoursBtn.addEventListener( 'click', function () {
			var idx  = hoursContainer.querySelectorAll( '.lp-hours-row' ).length;
			var row  = document.createElement( 'div' );
			row.className  = 'lp-hours-row';
			row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;';

			var dayCheckboxes = '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
			days.forEach( function ( day, di ) {
				dayCheckboxes += '<label style="display:inline-flex;align-items:center;gap:3px;font-size:12px;white-space:nowrap;">'
					+ '<input type="checkbox" name="hours[' + idx + '][days][]" value="' + day + '">'
					+ dayAbbr[ di ] + '</label>';
			} );
			dayCheckboxes += '</div>';

			row.innerHTML = dayCheckboxes
				+ '<input type="time" name="hours[' + idx + '][opens]"  value="09:00" style="width:110px;">'
				+ '<span style="font-size:12px;color:#666;">to</span>'
				+ '<input type="time" name="hours[' + idx + '][closes]" value="17:00" style="width:110px;">'
				+ '<button type="button" class="button lp-remove-hours" style="color:#a00;">' + __( '✕ Remove', 'twt-aeo-ultimate' ) + '</button>';

			hoursContainer.appendChild( row );
		} );
	}

	function reindexHours() {
		if ( ! hoursContainer ) return;
		var rows = hoursContainer.querySelectorAll( '.lp-hours-row' );
		rows.forEach( function ( row, i ) {
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.name = input.name.replace( /hours\[\d+\]/, 'hours[' + i + ']' );
			} );
		} );
	}

	// ── NAP Results renderer ──────────────────────────────────────────────────

	function renderNapResult( containerEl, data ) {
		if ( ! data || ! data.rows ) {
			containerEl.innerHTML = '<p style="color:#a00;">' + escHtml( data.error || 'Unknown error.' ) + '</p>';
			containerEl.style.display = 'block';
			return;
		}

		var html = '<h3 style="margin:0 0 10px;font-size:13px;">Results from ' + escHtml( data.source ) + '</h3>'
			+ '<table class="wp-list-table widefat fixed striped">'
			+ '<thead><tr><th>Field</th><th>Your Site</th><th>' + escHtml( data.source ) + '</th><th>Status</th></tr></thead><tbody>';

		data.rows.forEach( function ( row ) {
			var statusHtml;
			if ( row.match === null ) {
				statusHtml = '<span style="color:#888;">— No data</span>';
			} else if ( row.match ) {
				statusHtml = '<span style="color:#00a32a;font-weight:600;">&#10003; Match</span>';
			} else {
				statusHtml = '<span style="color:#d63638;font-weight:600;">&#10007; Mismatch</span>';
			}

			html += '<tr>'
				+ '<td><strong>' + escHtml( row.field ) + '</strong></td>'
				+ '<td>' + escHtml( row.stored || '—' ) + '</td>'
				+ '<td>' + escHtml( row.external || '—' ) + '</td>'
				+ '<td>' + statusHtml + '</td>'
				+ '</tr>';
		} );

		html += '</tbody></table>';
		containerEl.innerHTML = html;
		containerEl.style.display = 'block';
	}

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( str || '' ) );
		return d.innerHTML;
	}

	// ── Google Knowledge Graph check ──────────────────────────────────────────

	var checkKgBtn    = document.getElementById( 'lp_nap_check_kg' );
	var googleResult  = document.getElementById( 'lp_google_nap_result' );

	if ( checkKgBtn && googleResult ) {
		checkKgBtn.addEventListener( 'click', function () {
			checkKgBtn.disabled    = true;
			checkKgBtn.textContent = __( 'Checking…', 'twt-aeo-ultimate' );
			googleResult.style.display = 'none';

			var formData = new FormData();
			formData.append( 'action', 'twtaeo_local_pack_nap_google' );
			formData.append( 'nonce',  twtAeoLocalPack.nonce );
			formData.append( 'method', 'kg' );

			var kgKeyInput = document.getElementById( 'lp_kg_key' );
			if ( kgKeyInput ) formData.append( 'kg_key', kgKeyInput.value );

			fetch( twtAeoLocalPack.ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						renderNapResult( googleResult, res.data );
					} else {
						googleResult.innerHTML = '<p style="color:#a00;">' + escHtml( res.data || 'Error.' ) + '</p>';
						googleResult.style.display = 'block';
					}
				} )
				.catch( function () {
					googleResult.innerHTML = '<p style="color:#a00;">Request failed.</p>';
					googleResult.style.display = 'block';
				} )
				.finally( function () {
					checkKgBtn.disabled    = false;
					checkKgBtn.textContent = __( 'Check Google Knowledge Graph', 'twt-aeo-ultimate' );
				} );
		} );
	}

	// ── Google Business Profile check ─────────────────────────────────────────

	var checkGbpBtn = document.getElementById( 'lp_nap_check_gbp' );

	if ( checkGbpBtn && googleResult ) {
		checkGbpBtn.addEventListener( 'click', function () {
			checkGbpBtn.disabled    = true;
			checkGbpBtn.textContent = __( 'Checking…', 'twt-aeo-ultimate' );
			googleResult.style.display = 'none';

			var formData = new FormData();
			formData.append( 'action', 'twtaeo_local_pack_nap_google' );
			formData.append( 'nonce',  twtAeoLocalPack.nonce );
			formData.append( 'method', 'gbp' );

			fetch( twtAeoLocalPack.ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						renderNapResult( googleResult, res.data );
					} else {
						googleResult.innerHTML = '<p style="color:#a00;">' + escHtml( res.data || 'Error.' ) + '</p>';
						googleResult.style.display = 'block';
					}
				} )
				.catch( function () {
					googleResult.innerHTML = '<p style="color:#a00;">Request failed.</p>';
					googleResult.style.display = 'block';
				} )
				.finally( function () {
					checkGbpBtn.disabled    = false;
					checkGbpBtn.textContent = __( 'Check Google Business Profile', 'twt-aeo-ultimate' );
				} );
		} );
	}

	// ── Bing check ────────────────────────────────────────────────────────────

	var checkBingBtn = document.getElementById( 'lp_nap_check_bing' );
	var bingResult   = document.getElementById( 'lp_bing_nap_result' );

	if ( checkBingBtn && bingResult ) {
		checkBingBtn.addEventListener( 'click', function () {
			checkBingBtn.disabled    = true;
			checkBingBtn.textContent = __( 'Checking…', 'twt-aeo-ultimate' );
			bingResult.style.display = 'none';

			var formData = new FormData();
			formData.append( 'action', 'twtaeo_local_pack_nap_bing' );
			formData.append( 'nonce',  twtAeoLocalPack.nonce );

			var bingKeyInput = document.getElementById( 'lp_bing_key' );
			if ( bingKeyInput ) formData.append( 'bing_key', bingKeyInput.value );

			fetch( twtAeoLocalPack.ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						renderNapResult( bingResult, res.data );
					} else {
						bingResult.innerHTML = '<p style="color:#a00;">' + escHtml( res.data || 'Error.' ) + '</p>';
						bingResult.style.display = 'block';
					}
				} )
				.catch( function () {
					bingResult.innerHTML = '<p style="color:#a00;">Request failed.</p>';
					bingResult.style.display = 'block';
				} )
				.finally( function () {
					checkBingBtn.disabled    = false;
					checkBingBtn.textContent = __( 'Check Bing Maps', 'twt-aeo-ultimate' );
				} );
		} );
	}

	// ── Media library pickers (Logo + Photo) ─────────────────────────────────

	document.querySelectorAll( '.lp-media-select' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var targetId  = btn.dataset.target;
			var previewId = btn.dataset.preview;
			var urlInput  = document.getElementById( targetId );
			var preview   = document.getElementById( previewId );
			var removeBtn = btn.parentNode.querySelector( '.lp-media-remove' );

			if ( typeof wp === 'undefined' || ! wp.media ) return;

			var frame = wp.media( {
				title:    __( 'Select Image', 'twt-aeo-ultimate' ),
				button:   { text: __( 'Use this image', 'twt-aeo-ultimate' ) },
				multiple: false,
				library:  { type: 'image' },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.full
					? attachment.sizes.full.url
					: attachment.url;

				if ( urlInput )  urlInput.value  = url;
				if ( preview ) {
					preview.src          = url;
					preview.style.display = '';
				}
				if ( removeBtn ) removeBtn.style.display = '';
			} );

			frame.open();
		} );
	} );

	document.querySelectorAll( '.lp-media-remove' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var targetId  = btn.dataset.target;
			var previewId = btn.dataset.preview;
			var urlInput  = document.getElementById( targetId );
			var preview   = document.getElementById( previewId );

			if ( urlInput )  urlInput.value  = '';
			if ( preview ) {
				preview.src          = '';
				preview.style.display = 'none';
			}
			btn.style.display = 'none';
		} );
	} );

	// Keep Remove button visible when user manually types a URL into the field.
	[ 'lp_logo_url', 'lp_image_url' ].forEach( function ( id ) {
		var input = document.getElementById( id );
		if ( ! input ) return;
		input.addEventListener( 'input', function () {
			var previewId = id === 'lp_logo_url' ? 'lp_logo_preview' : 'lp_image_preview';
			var preview   = document.getElementById( previewId );
			var removeBtn = input.closest( 'div' ).querySelector( '.lp-media-remove' );
			var url       = input.value.trim();

			if ( preview ) {
				if ( url ) {
					preview.src          = url;
					preview.style.display = '';
				} else {
					preview.src          = '';
					preview.style.display = 'none';
				}
			}
			if ( removeBtn ) {
				removeBtn.style.display = url ? '' : 'none';
			}
		} );
	} );

	// ── Auto-fill from site ───────────────────────────────────────────────────

	var autofillBtn    = document.getElementById( 'lp_autofill_btn' );
	var autofillNotice = document.getElementById( 'lp_autofill_notice' );

	// Map returned field keys to form inputs.
	var fieldMap = {
		business_name:  document.getElementById( 'lp_business_name' ),
		phone:          document.getElementById( 'lp_phone' ),
		email:          document.getElementById( 'lp_email' ),
		business_url:   document.getElementById( 'lp_business_url' ),
		description:    document.getElementById( 'lp_description' ),
		street_address: document.querySelector( 'input[name="street_address"]' ),
		city:           document.getElementById( 'lp_city' ),
		state:          document.querySelector( 'input[name="state"]' ),
		zip:            document.querySelector( 'input[name="zip"]' ),
		country:        document.querySelector( 'input[name="country"]' ),
		logo_url:       document.querySelector( 'input[name="logo_url"]' ),
	};

	if ( autofillBtn ) {
		autofillBtn.addEventListener( 'click', function () {
			autofillBtn.disabled    = true;
			autofillBtn.textContent = __( 'Detecting…', 'twt-aeo-ultimate' );

			var formData = new FormData();
			formData.append( 'action', 'twtaeo_local_pack_autofill' );
			formData.append( 'nonce',  twtAeoLocalPack.nonce );

			fetch( twtAeoLocalPack.ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res.success ) {
						showAutofillNotice( 'error', res.data || __( 'Auto-fill failed.', 'twt-aeo-ultimate' ) );
						return;
					}

					var data      = res.data;
					var filled    = [];
					var skipped   = [];
					var sources   = {};

					Object.keys( data ).forEach( function ( key ) {
						var entry  = data[ key ];
						var value  = entry.value;
						var source = entry.source;
						var el     = fieldMap[ key ];

						if ( ! el || ! value ) return;

						if ( el.value && el.value.trim() ) {
							// Field already has content — skip.
							skipped.push( key );
							return;
						}

						el.value = value;
						filled.push( key );

						if ( source ) {
							sources[ source ] = ( sources[ source ] || 0 ) + 1;
						}

						// Special handling: business_type sets both inputs.
						if ( key === 'business_type' ) {
							var hidden = document.getElementById( 'lp_business_type' );
							var search = document.getElementById( 'lp_business_type_search' );
							if ( hidden ) hidden.value = value;
							if ( search && twtAeoLocalPack.subtypes[ value ] ) {
								search.value = twtAeoLocalPack.subtypes[ value ];
							}
						}
					} );

					if ( filled.length === 0 ) {
						showAutofillNotice( 'info', __( 'All fields already have values — nothing was overwritten.', 'twt-aeo-ultimate' ) );
						return;
					}

					var sourceList = Object.keys( sources ).join( ', ' );
					var msg = filled.length + ' field(s) filled from: ' + sourceList + '.';
					if ( skipped.length ) {
						msg += ' ' + skipped.length + ' field(s) skipped (already had values).';
					}
					showAutofillNotice( 'success', msg );
				} )
				.catch( function () {
					showAutofillNotice( 'error', __( 'Request failed. Please try again.', 'twt-aeo-ultimate' ) );
				} )
				.finally( function () {
					autofillBtn.disabled    = false;
					autofillBtn.innerHTML   = '&#11023; Auto-Fill from Site';
				} );
		} );
	}

	function showAutofillNotice( type, message ) {
		if ( ! autofillNotice ) return;
		autofillNotice.className = 'notice notice-' + type + ' is-dismissible';
		autofillNotice.querySelector( 'p' ).textContent = message;
		autofillNotice.style.display = 'block';
	}

} )();
