/**
 * Video help: "?" beside the screen title → a dialog with the screen's tutorial(s).
 * The YouTube player is created only when Play is pressed (youtube-nocookie.com),
 * and removed when the dialog closes, so nothing plays or loads in the background.
 * Data: window.twtAeoVideoHelp, from TWTAEO_Video_Help::enqueue().
 */
( function () {
	'use strict';

	var data = window.twtAeoVideoHelp;
	if ( ! data || ! data.videos || ! data.videos.length ) {
		return;
	}
	var t = data.i18n;

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	// A Play button that swaps itself for the player — the only point at which
	// anything is requested from YouTube.
	function mountPlay( frame, v ) {
		frame.classList.remove( 'is-playing' );
		frame.textContent = '';
		var play = el( 'button', { type: 'button', 'class': 'button button-primary twt-aeo-video-help__play' }, '▶ ' + t.play );
		play.addEventListener( 'click', function () {
			frame.textContent = '';
			frame.appendChild( el( 'iframe', {
				src: 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( v.id ) + '?autoplay=1&rel=0',
				title: v.title,
				allow: 'autoplay; encrypted-media; picture-in-picture; fullscreen',
				allowfullscreen: '',
				referrerpolicy: 'strict-origin-when-cross-origin'
			} ) );
			frame.classList.add( 'is-playing' );
		} );
		frame.appendChild( play );
	}

	function init() {
		var h1 = document.querySelector( '#wpbody-content .wrap h1' ) || document.querySelector( '#wpbody-content h1' );
		if ( ! h1 || document.getElementById( 'twt-aeo-video-help-btn' ) ) {
			return;
		}

		var btn = el( 'button', {
			type: 'button',
			id: 'twt-aeo-video-help-btn',
			'class': 'twt-aeo-video-help-btn',
			'aria-haspopup': 'dialog',
			'aria-label': t.button,
			title: t.button
		}, '?' );
		h1.appendChild( btn );

		var dialog = el( 'dialog', { id: 'twt-aeo-video-help', 'class': 'twt-aeo-video-help', 'aria-labelledby': 'twt-aeo-video-help-title' } );
		var head = el( 'div', { 'class': 'twt-aeo-video-help__head' } );
		head.appendChild( el( 'h2', { id: 'twt-aeo-video-help-title' }, t.heading ) );
		var close = el( 'button', { type: 'button', 'class': 'twt-aeo-video-help__close', 'aria-label': t.close }, '×' );
		head.appendChild( close );
		dialog.appendChild( head );

		data.videos.forEach( function ( v ) {
			var item = el( 'section', { 'class': 'twt-aeo-video-help__item' } );
			item.appendChild( el( 'h3', {}, v.title ) );
			item.appendChild( el( 'p', {}, v.about ) );

			var frame = el( 'div', { 'class': 'twt-aeo-video-help__frame' } );
			frame.setAttribute( 'data-video', v.id );
			mountPlay( frame, v );
			item.appendChild( frame );

			var links = el( 'p', { 'class': 'twt-aeo-video-help__links' } );
			var guide = el( 'a', { href: v.guide, target: '_blank', rel: 'noopener' }, t.guide );
			guide.appendChild( el( 'span', { 'class': 'screen-reader-text' }, ' ' + t.newTab ) );
			var yt = el( 'a', { href: v.watch, target: '_blank', rel: 'noopener' }, t.youtube );
			yt.appendChild( el( 'span', { 'class': 'screen-reader-text' }, ' ' + t.newTab ) );
			links.appendChild( guide );
			links.appendChild( document.createTextNode( ' · ' ) );
			links.appendChild( yt );
			item.appendChild( links );

			dialog.appendChild( item );
		} );

		dialog.appendChild( el( 'p', { 'class': 'twt-aeo-video-help__privacy' }, t.privacy ) );
		document.body.appendChild( dialog );

		// Closing removes every player and puts the Play buttons back, so a video
		// never keeps playing (or loading) behind a closed dialog.
		function reset() {
			var frames = dialog.querySelectorAll( '.twt-aeo-video-help__frame' );
			data.videos.forEach( function ( v, i ) {
				if ( frames[ i ] && frames[ i ].classList.contains( 'is-playing' ) ) {
					mountPlay( frames[ i ], v );
				}
			} );
		}

		btn.addEventListener( 'click', function () {
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}
		} );
		close.addEventListener( 'click', function () {
			dialog.close ? dialog.close() : dialog.removeAttribute( 'open' );
		} );
		dialog.addEventListener( 'close', function () {
			reset();
			btn.focus();
		} );
		// A click on the backdrop (outside the panel) closes it.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
