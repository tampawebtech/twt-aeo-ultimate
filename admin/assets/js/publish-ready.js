/**
 * "Finish this page" — the Publish Ready panel.
 *
 * One renderer, two homes. In the classic editor it draws into the meta box; in
 * the block editor the meta box is hidden as back-compat and this registers a
 * native sidebar, then draws the very same DOM into it. Two renderers would be
 * two sets of rules about what "missing" looks like.
 *
 * Every node is built with createElement and textContent. Field values come
 * from post meta an author typed and from other plugins' storage, so none of it
 * goes near innerHTML.
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! cfg || ! wp || ! wp.apiFetch ) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var i18n = cfg.i18n || {};
	var S = cfg.states || {};
	var state = cfg.initial || { fields: [], images: [], summary: { outstanding: 0 } };
	var busy = false;
	var containers = [];
	var openEditor = null; // Field id whose inline editor is open.
	var pendingConfirm = null; // { field, calls } awaiting the spend confirmation.

	/* ─────────────────────────── helpers ─────────────────────────── */

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null && text !== '' ) {
			node.textContent = String( text );
		}
		return node;
	}

	function button( label, variant, onClick ) {
		var b = el( 'button', 'button button-small' + ( variant ? ' ' + variant : '' ), label );
		b.type = 'button';
		b.addEventListener( 'click', onClick );
		if ( busy ) {
			b.disabled = true;
		}
		return b;
	}

	function sprintf1( template, value ) {
		return String( template || '' ).replace( '%d', value ).replace( '%s', value );
	}

	function stateLabel( fieldState ) {
		switch ( fieldState ) {
			case S.filled:
				return i18n.stateFilled;
			case S.templated:
				return i18n.stateTemplated;
			case S.inherited:
				return i18n.stateInherited;
			case S.unknown:
				return i18n.stateUnknown;
			default:
				return i18n.stateMissing;
		}
	}

	function imagesMissingAlt() {
		return ( state.images || [] ).filter( function ( img ) {
			return ! img.alt || ! img.alt.trim();
		} );
	}

	/* ─────────────────────────── requests ─────────────────────────── */

	function request( route, body ) {
		busy = true;
		renderAll();

		return apiFetch( {
			path: cfg.root + '/' + route,
			method: 'POST',
			data: Object.assign( { post_id: cfg.postId }, body || {} )
		} ).then( function ( data ) {
			busy = false;
			state = data;
			openEditor = null;
			pendingConfirm = null;
			if ( data && typeof data.content === 'string' ) {
				adoptContent( data.content );
			}
			renderAll();
			return data;
		} ).catch( function ( err ) {
			busy = false;
			pendingConfirm = null;
			state.notice = ( err && err.message ) ? err.message : i18n.requestFailed;
			renderAll();
		} );
	}

	/** The cost sentence for an action, singular or plural. */
	function costText( calls ) {
		return calls > 1 ? sprintf1( i18n.costMany, calls ) : i18n.costOne;
	}

	/**
	 * Ask before spending, unless this person has said not to.
	 *
	 * This used to be window.confirm(). Two problems with that: a browser modal
	 * is a heavy interruption for an action that costs one call, and there is
	 * nowhere in it to say "stop asking me". It also blocks the whole page,
	 * which makes the panel untestable through automation. The confirm is now
	 * a row inside the panel, so it can carry a checkbox and cannot freeze
	 * anything.
	 */
	/**
	 * Alt text is the one field written back into post_content, so it cannot be
	 * touched while the editor holds unsaved changes. Refusing here, before
	 * anything is sent, gives a better message than the server's and spends
	 * nothing; the server checks again regardless.
	 */
	function writesContent( field ) {
		return 'image_alt' === field;
	}

	function blockedByUnsaved( field ) {
		if ( ! writesContent( field ) || ! isDirty() ) {
			return false;
		}
		pendingConfirm = null;
		openEditor = null;
		state.notice = i18n.saveFirst;
		renderAll();
		return true;
	}

	/** The body claim sent with anything that writes into post_content. */
	function withContent( body, field ) {
		if ( ! writesContent( field ) ) {
			return body;
		}
		var live = currentContent();
		if ( null !== live ) {
			body.content = live;
		}
		return body;
	}

	function generate( field, calls ) {
		if ( blockedByUnsaved( field ) ) {
			return;
		}
		if ( state.ask_before_spending === false ) {
			request( 'generate', withContent( { field: field }, field ) );
			return;
		}
		pendingConfirm = { field: field, calls: calls || 1 };
		renderAll();
	}

	function save( field, value ) {
		request( 'save', { field: field, value: value } );
	}

	/* ───────────────────── block editor plumbing ───────────────────── */

	/**
	 * PluginSidebar moved from @wordpress/edit-post to @wordpress/editor, and
	 * the old home is deprecated rather than gone. Prefer the new one and fall
	 * back, so this keeps working either side of that move.
	 */
	function editorPackage() {
		if ( wp.editor && wp.editor.PluginSidebar ) {
			return wp.editor;
		}
		if ( wp.editPost && wp.editPost.PluginSidebar ) {
			return wp.editPost;
		}
		return null;
	}

	function isBlockEditor() {
		return !! ( wp.data && wp.data.select( 'core/block-editor' ) && wp.plugins && editorPackage() );
	}

	/** Whether the editor holds changes that are not in the database yet. */
	function isDirty() {
		if ( ! isBlockEditor() ) {
			return false;
		}
		try {
			return !! wp.data.select( 'core/editor' ).isEditedPostDirty();
		} catch ( e ) {
			// Cannot tell, so assume the worst and protect the content.
			return true;
		}
	}

	/**
	 * Take on a body the server rewrote. Writing alt text edits post_content,
	 * and if the editor kept its old copy the author's next save would quietly
	 * undo the change. Blocks are re-parsed rather than the content string
	 * being assigned, so the block list and the content stay in step.
	 *
	 * This refuses to run while the editor is dirty, and that guard is the
	 * whole point of it. Resetting blocks replaces everything the author has
	 * in front of them with the server's copy, so doing it over unsaved work
	 * destroys that work: an image inserted but not yet saved simply vanished,
	 * along with every other unsaved change. The write paths that reach here
	 * are already gated on a matching body, so this is the second lock on the
	 * same door rather than the first.
	 */
	function adoptContent( content ) {
		if ( ! isBlockEditor() || ! wp.blocks || ! wp.blocks.parse ) {
			return;
		}
		if ( isDirty() ) {
			return;
		}
		try {
			wp.data.dispatch( 'core/block-editor' ).resetBlocks( wp.blocks.parse( content ) );
		} catch ( e ) {
			// Nothing to do but leave the editor as it is: the value is stored,
			// and a reload will show it.
		}
	}

	function currentContent() {
		if ( ! isBlockEditor() ) {
			return null;
		}
		try {
			return wp.data.select( 'core/editor' ).getEditedPostContent();
		} catch ( e ) {
			return null;
		}
	}

	/* ─────────────────────────── rendering ─────────────────────────── */

	function renderHeader() {
		var outstanding = ( state.summary && state.summary.outstanding ) || 0;
		var wrap = el( 'div', 'twtaeo-pr__head' );

		wrap.appendChild(
			el(
				'span',
				'twtaeo-pr__count' + ( outstanding ? '' : ' is-clear' ),
				outstanding ? sprintf1( i18n.outstanding, outstanding ) : i18n.allDone
			)
		);

		return wrap;
	}

	function renderNotice() {
		if ( ! state.notice ) {
			return null;
		}
		return el( 'p', 'twtaeo-pr__notice', state.notice );
	}

	function renderTextEditor( field ) {
		var box = el( 'div', 'twtaeo-pr__editor' );

		if ( field.state === S.templated ) {
			box.appendChild( el( 'p', 'twtaeo-pr__warn', i18n.templatedWarning ) );
		} else if ( field.state === S.unknown ) {
			box.appendChild( el( 'p', 'twtaeo-pr__warn', i18n.unknownWarning ) );
		}

		var input = document.createElement( field.id === 'seo_title' ? 'input' : 'textarea' );
		if ( field.id === 'seo_title' ) {
			input.type = 'text';
		} else {
			input.rows = 3;
		}
		input.className = 'twtaeo-pr__input';
		input.value = field.value || '';
		box.appendChild( input );

		var counter = el( 'span', 'twtaeo-pr__counter' );
		function updateCounter() {
			counter.textContent = String( input.value.length );
		}
		updateCounter();
		input.addEventListener( 'input', updateCounter );

		var row = el( 'div', 'twtaeo-pr__actions' );
		row.appendChild( counter );
		row.appendChild(
			button( i18n.save, 'button-primary', function () {
				save( field.id, input.value );
			} )
		);
		row.appendChild(
			button( i18n.cancel, '', function () {
				openEditor = null;
				renderAll();
			} )
		);
		box.appendChild( row );

		return box;
	}

	/**
	 * The spend confirmation, shown in the row whose button was pressed.
	 * States the cost, offers to remember the answer, and never blocks the page.
	 */
	function renderCostConfirm() {
		var box = el( 'div', 'twtaeo-pr__confirm' );

		box.appendChild( el( 'p', 'twtaeo-pr__confirm-text', costText( pendingConfirm.calls ) ) );

		var label = el( 'label', 'twtaeo-pr__remember' );
		var check = document.createElement( 'input' );
		check.type = 'checkbox';
		label.appendChild( check );
		label.appendChild( el( 'span', '', i18n.dontAsk ) );
		box.appendChild( label );

		var actions = el( 'div', 'twtaeo-pr__actions' );
		actions.appendChild(
			button( i18n.confirmRun, 'button-primary', function () {
				var field = pendingConfirm.field;
				var body = withContent( { field: field }, field );
				if ( check.checked ) {
					body.remember = true;
				}
				request( 'generate', body );
			} )
		);
		actions.appendChild(
			button( i18n.cancel, '', function () {
				pendingConfirm = null;
				renderAll();
			} )
		);
		box.appendChild( actions );

		return box;
	}

	/**
	 * A way back. Someone who ticked "do not ask again" and then regretted it
	 * must not have to hunt through Settings for the switch; it belongs where
	 * they turned it off.
	 */
	function renderAskingFooter() {
		if ( state.ask_before_spending !== false ) {
			return null;
		}

		var foot = el( 'div', 'twtaeo-pr__foot' );
		foot.appendChild( el( 'span', '', i18n.askingOff + ' ' ) );

		var link = el( 'button', 'twtaeo-pr__linkbtn', i18n.askingOn );
		link.type = 'button';
		link.addEventListener( 'click', function () {
			save( 'cost_confirm', true );
		} );
		foot.appendChild( link );

		return foot;
	}

	function renderAltEditor() {
		var box = el( 'div', 'twtaeo-pr__editor' );
		var map = {};

		( state.images || [] ).forEach( function ( img ) {
			if ( img.alt && img.alt.trim() ) {
				return;
			}
			var row = el( 'div', 'twtaeo-pr__alt-row' );

			if ( img.src ) {
				var thumb = document.createElement( 'img' );
				thumb.className = 'twtaeo-pr__thumb';
				thumb.src = img.src;
				thumb.alt = '';
				row.appendChild( thumb );
			}

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'twtaeo-pr__input';
			input.placeholder = i18n.altPlaceholder;
			input.addEventListener( 'input', function () {
				map[ img.key ] = input.value;
			} );
			row.appendChild( input );

			box.appendChild( row );
		} );

		var actions = el( 'div', 'twtaeo-pr__actions' );
		actions.appendChild(
			button( i18n.save, 'button-primary', function () {
				var filled = {};
				Object.keys( map ).forEach( function ( key ) {
					if ( map[ key ] && map[ key ].trim() ) {
						filled[ key ] = map[ key ].trim();
					}
				} );
				if ( ! Object.keys( filled ).length ) {
					openEditor = null;
					renderAll();
					return;
				}
				if ( blockedByUnsaved( 'image_alt' ) ) {
					return;
				}
				request( 'save', withContent( { field: 'image_alt', value: filled }, 'image_alt' ) );
			} )
		);
		actions.appendChild(
			button( i18n.cancel, '', function () {
				openEditor = null;
				renderAll();
			} )
		);
		box.appendChild( actions );

		return box;
	}

	function pickImage( onPick ) {
		if ( ! wp.media ) {
			return;
		}
		var frame = wp.media( {
			title: i18n.mediaTitle,
			button: { text: i18n.mediaButton },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first();
			if ( attachment ) {
				onPick( attachment.id );
			}
		} );
		frame.open();
	}

	function renderFieldActions( field ) {
		var actions = el( 'div', 'twtaeo-pr__actions' );

		if ( field.id === 'seo_title' || field.id === 'meta_description' ) {
			actions.appendChild(
				button( i18n.edit, '', function () {
					openEditor = field.id;
					renderAll();
				} )
			);
			if ( field.can_generate ) {
				actions.appendChild(
					button(
						field.state === S.filled ? i18n.regenerate : i18n.generate,
						'',
						function () {
							generate( field.id, field.generate_cost );
						}
					)
				);
			} else {
				actions.appendChild( el( 'span', 'twtaeo-pr__hint', i18n.noProvider ) );
			}
		}

		if ( field.id === 'share_image' ) {
			actions.appendChild(
				button( field.value ? i18n.change : i18n.choose, '', function () {
					pickImage( function ( id ) {
						save( 'share_image', id );
					} );
				} )
			);
			// Drawing a share image is an OpenAI-only capability, so this
			// button appears only where it can actually work.
			if ( field.can_generate ) {
				actions.appendChild(
					button( i18n.generate, '', function () {
						generate( 'share_image', field.generate_cost );
					} )
				);
			}
			if ( field.state === S.filled ) {
				actions.appendChild(
					button( i18n.remove, '', function () {
						save( 'share_image', '' );
					} )
				);
			}
		}

		if ( field.id === 'featured_image' ) {
			actions.appendChild(
				button( field.value ? i18n.change : i18n.choose, '', function () {
					pickImage( function ( id ) {
						save( 'featured_image', id );
					} );
				} )
			);
			if ( field.detail ) {
				actions.appendChild(
					button( i18n.useFirstInBody, '', function () {
						save( 'featured_image', 'first-content-image' );
					} )
				);
			}
		}

		if ( field.id === 'image_alt' && field.state !== S.filled ) {
			// Both of these write into post_content, so while the editor is
			// dirty they would be refused. Say so instead of offering them.
			if ( isDirty() ) {
				actions.appendChild( el( 'span', 'twtaeo-pr__hint', i18n.saveFirst ) );
				return actions;
			}

			actions.appendChild(
				button( i18n.fixAlt, '', function () {
					openEditor = field.id;
					renderAll();
				} )
			);
			if ( field.can_generate ) {
				actions.appendChild(
					button( i18n.generate, '', function () {
						generate( 'image_alt', imagesMissingAlt().length || 1 );
					} )
				);
			}
		}

		return actions;
	}

	function renderField( field ) {
		var row = el( 'div', 'twtaeo-pr__row is-' + field.state );

		var head = el( 'div', 'twtaeo-pr__row-head' );
		head.appendChild( el( 'span', 'twtaeo-pr__label', field.label ) );
		head.appendChild( el( 'span', 'twtaeo-pr__state', stateLabel( field.state ) ) );
		row.appendChild( head );

		var note = field.source || field.detail;
		if ( field.source && field.detail ) {
			note = field.source + ' · ' + field.detail;
		}
		if ( note ) {
			row.appendChild( el( 'p', 'twtaeo-pr__note', note ) );
		}

		if ( field.value && ( field.id === 'seo_title' || field.id === 'meta_description' ) ) {
			row.appendChild( el( 'p', 'twtaeo-pr__value', field.value ) );
		}

		if ( field.value && ( field.id === 'share_image' || field.id === 'featured_image' ) ) {
			var preview = document.createElement( 'img' );
			preview.className = 'twtaeo-pr__preview';
			preview.src = field.value;
			preview.alt = '';
			row.appendChild( preview );
		}

		if ( pendingConfirm && pendingConfirm.field === field.id ) {
			row.appendChild( renderCostConfirm() );
		} else if ( openEditor === field.id ) {
			row.appendChild( field.id === 'image_alt' ? renderAltEditor() : renderTextEditor( field ) );
		} else {
			row.appendChild( renderFieldActions( field ) );
		}

		return row;
	}

	function renderInto( container ) {
		container.textContent = '';
		container.appendChild( renderHeader() );

		var notice = renderNotice();
		if ( notice ) {
			container.appendChild( notice );
		}

		( state.fields || [] ).forEach( function ( field ) {
			container.appendChild( renderField( field ) );
		} );

		if ( busy ) {
			container.appendChild( el( 'p', 'twtaeo-pr__hint', i18n.working ) );
		}

		var foot = renderAskingFooter();
		if ( foot ) {
			container.appendChild( foot );
		}
	}

	function renderAll() {
		containers.forEach( function ( container ) {
			if ( container && container.isConnected !== false ) {
				renderInto( container );
			}
		} );
	}

	/* ─────────────────────────── start up ─────────────────────────── */

	function mountMetaBox() {
		var root = document.querySelector( '[data-twtaeo-pr-root]' );
		if ( root ) {
			containers.push( root );
		}
	}

	/**
	 * Hide the classic meta box, but only once the sidebar is really mounted.
	 * The box is the fallback: if anything stops the sidebar registering, it
	 * stays on screen rather than leaving the author with no panel at all.
	 */
	function hideMetaBox() {
		var box = document.getElementById( 'twt-aeo-publish-ready' );
		if ( box && box.style ) {
			box.style.display = 'none';
		}
	}

	function mountSidebar() {
		var pkg = editorPackage();
		if ( ! pkg ) {
			return false;
		}

		var registerPlugin = wp.plugins.registerPlugin;
		var PluginSidebar = pkg.PluginSidebar;
		var PluginSidebarMoreMenuItem = pkg.PluginSidebarMoreMenuItem;
		var createElement = wp.element.createElement;
		var useRef = wp.element.useRef;
		var useEffect = wp.element.useEffect;
		var Fragment = wp.element.Fragment;

		if ( ! registerPlugin || ! PluginSidebar || ! useRef ) {
			return false;
		}

		// A single div the DOM renderer owns, so the sidebar shows exactly what
		// the meta box shows without the markup existing twice.
		function Panel() {
			var ref = useRef( null );

			useEffect( function () {
				if ( ! ref.current ) {
					return;
				}
				containers.push( ref.current );
				renderInto( ref.current );

				return function () {
					containers = containers.filter( function ( c ) {
						return c !== ref.current;
					} );
				};
			}, [] );

			// The meta box sits inside .inside, which WordPress pads for us.
			// A plugin sidebar pads nothing, so the panel brings its own there.
			return createElement( 'div', { className: 'twtaeo-pr twtaeo-pr--sidebar', ref: ref } );
		}

		function render() {
			var sidebar = createElement(
				PluginSidebar,
				{ name: 'twt-aeo-publish-ready-sidebar', title: i18n.sidebarName },
				createElement( Panel )
			);

			// The menu item is how the sidebar is reachable from the three-dot
			// menu. If this WordPress does not export it, the sidebar is still
			// registered and still opens from the toolbar.
			if ( ! PluginSidebarMoreMenuItem ) {
				return sidebar;
			}

			return createElement(
				Fragment,
				null,
				createElement(
					PluginSidebarMoreMenuItem,
					{ target: 'twt-aeo-publish-ready-sidebar' },
					i18n.sidebarName
				),
				sidebar
			);
		}

		// A throw here, from a duplicate plugin name or a package mismatch,
		// must not take the meta-box fallback down with it.
		try {
			registerPlugin( 'twt-aeo-publish-ready', { render: render } );
		} catch ( e ) {
			return false;
		}

		return true;
	}

	/**
	 * Re-read state as the author types, so the alt-text count and the share
	 * image follow a body that has just been pasted in rather than waiting for
	 * a save. Debounced, and skipped while a request is in flight.
	 */
	function watchContent() {
		if ( ! isBlockEditor() || ! wp.data.subscribe ) {
			return;
		}

		var last = currentContent();
		var timer = null;

		// getEditedPostContent() serializes every block, so it is far too
		// expensive to call on each store tick: a subscribe handler fires many
		// times per keystroke. The tick only arms a timer, and the content is
		// serialized once when the author pauses.
		function check() {
			timer = null;
			if ( busy || openEditor ) {
				return; // Never redraw underneath someone who is typing.
			}

			var now = currentContent();
			if ( now === null || now === last ) {
				return;
			}
			last = now;

			apiFetch( {
				path: cfg.root + '/state',
				method: 'POST',
				data: { post_id: cfg.postId, content: now }
			} ).then( function ( data ) {
				if ( busy || openEditor ) {
					return;
				}
				state = data;
				renderAll();
			} ).catch( function () {
				// A failed refresh leaves the last good state on screen.
			} );
		}

		wp.data.subscribe( function () {
			if ( busy || timer ) {
				return;
			}
			timer = window.setTimeout( check, 900 );
		} );
	}

	function start() {
		mountMetaBox();

		if ( isBlockEditor() && mountSidebar() ) {
			// Only now is it safe to drop the fallback.
			hideMetaBox();
			watchContent();
		}

		renderAll();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.wp, window.twtAeoPublishReady );
