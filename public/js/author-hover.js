/* global twtAeoHover, wp */
/**
 * TWT AEO — Author Hover Cards
 *
 * Renders lightweight author preview cards when users hover or focus
 * on author links across a post page.
 *
 * Selectors watched:
 *   a[rel="author"], .author-name a, .byline a,
 *   .entry-author a, .post-author a, [data-author-id]
 *
 * Accessibility: works with keyboard focus, hides on Escape.
 */
( function () {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	if ( typeof twtAeoHover === 'undefined' || ! twtAeoHover.authors ) {
		return;
	}

	var SELECTORS = [
		'a[rel="author"]',
		'.author-name a',
		'.byline a',
		'.entry-author a',
		'.entry-author-name a',
		'.post-author a',
		'.vcard a',
		'[data-author-id]',
	].join( ',' );

	var card    = null;
	var hideTimer = null;
	var currentAuthorId = null;

	// ── Card creation ────────────────────────────────────────────────────────

	function createCard() {
		card = document.createElement( 'div' );
		card.className = 'twt-aeo-hover-card';
		card.setAttribute( 'role', 'tooltip' );
		card.setAttribute( 'aria-live', 'polite' );

		// Keep card visible when the user moves onto it.
		card.addEventListener( 'mouseenter', function () {
			clearTimeout( hideTimer );
		} );
		card.addEventListener( 'mouseleave', hideCard );
		card.addEventListener( 'focusin', function () {
			clearTimeout( hideTimer );
		} );
		card.addEventListener( 'focusout', hideCard );

		document.body.appendChild( card );
	}

	function buildCardHTML( author ) {
		var html = '';

		// Header.
		html += '<div class="twt-aeo-hover-card__header">';
		if ( author.avatarUrl ) {
			html += '<img class="twt-aeo-hover-card__avatar" src="' + esc( author.avatarUrl ) + '" alt="' + esc( author.name ) + '" width="52" height="52" loading="lazy">';
		}
		html += '<div class="twt-aeo-hover-card__meta">';
		html += '<span class="twt-aeo-hover-card__name">' + esc( author.name ) + '</span>';
		if ( author.jobTitle ) {
			html += '<span class="twt-aeo-hover-card__title">' + esc( author.jobTitle ) + '</span>';
		}
		if ( author.credentials ) {
			html += '<span class="twt-aeo-hover-card__credentials">' + esc( author.credentials ) + '</span>';
		}
		html += '</div></div>';

		// Body.
		html += '<div class="twt-aeo-hover-card__body">';

		if ( author.bio ) {
			html += '<p class="twt-aeo-hover-card__bio">' + esc( author.bio ) + '</p>';
		}

		// Signals.
		var signals = '';
		if ( author.yearsExperience ) {
			signals += '<span class="twt-aeo-hover-signal twt-aeo-hover-signal--exp">' + esc( sprintf( __( '%s yrs exp', 'twt-aeo-ultimate' ), author.yearsExperience ) ) + '</span>';
		}
		if ( author.certCount ) {
			signals += '<span class="twt-aeo-hover-signal twt-aeo-hover-signal--certs">' + esc( _n( '%s cert', '%s certs', author.certCount, 'twt-aeo-ultimate' ).replace( '%s', author.certCount ) ) + '</span>';
		}
		if ( signals ) {
			html += '<div class="twt-aeo-hover-card__signals">' + signals + '</div>';
		}

		// Expertise tags.
		if ( author.expertise ) {
			var tags  = author.expertise.split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean );
			if ( tags.length ) {
				html += '<div class="twt-aeo-hover-card__expertise">';
				tags.slice( 0, 4 ).forEach( function ( tag ) {
					html += '<span class="twt-aeo-hover-tag">' + esc( tag ) + '</span>';
				} );
				html += '</div>';
			}
		}

		// Certifications mini-list.
		if ( author.certNames && author.certNames.length ) {
			html += '<div class="twt-aeo-hover-card__certs"><strong>' + __( 'Certifications', 'twt-aeo-ultimate' ) + '</strong><ul>';
			author.certNames.forEach( function ( name ) {
				html += '<li>' + esc( name ) + '</li>';
			} );
			if ( author.certCount > author.certNames.length ) {
				html += '<li>+ ' + ( author.certCount - author.certNames.length ) + ' more</li>';
			}
			html += '</ul></div>';
		}

		// Social chips.
		if ( author.social && author.social.length ) {
			html += '<div class="twt-aeo-hover-card__social">';
			author.social.slice( 0, 5 ).forEach( function ( url ) {
				var label = getDomainLabel( url );
				html += '<a class="twt-aeo-hover-social-chip" href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + esc( label ) + '</a>';
			} );
			html += '</div>';
		}

		html += '</div>'; // body

		// Footer.
		if ( author.profileUrl ) {
			html += '<div class="twt-aeo-hover-card__footer">';
			html += '<a class="twt-aeo-hover-card__profile-link" href="' + esc( author.profileUrl ) + '">' + esc( twtAeoHover.viewProfileText || 'View Full Profile' ) + '</a>';
			html += '</div>';
		}

		return html;
	}

	// ── Position ─────────────────────────────────────────────────────────────

	function positionCard( anchor ) {
		var rect    = anchor.getBoundingClientRect();
		var cardW   = 320;
		var cardH   = card.offsetHeight;
		var vw      = window.innerWidth;
		var vh      = window.innerHeight;
		var gap     = 8;

		var top  = rect.bottom + gap;
		var left = rect.left;

		// Flip above if not enough space below.
		if ( top + cardH > vh - gap && rect.top - cardH - gap > 0 ) {
			top = rect.top - cardH - gap;
		}

		// Clamp horizontally.
		if ( left + cardW > vw - gap ) {
			left = vw - cardW - gap;
		}
		if ( left < gap ) {
			left = gap;
		}

		card.style.top  = top + 'px';
		card.style.left = left + 'px';
	}

	// ── Show / hide ──────────────────────────────────────────────────────────

	function showCard( anchor, authorId ) {
		var author = twtAeoHover.authors[ authorId ];
		if ( ! author ) {
			return;
		}

		clearTimeout( hideTimer );

		if ( ! card ) {
			createCard();
		}

		// Only re-render if author changed.
		if ( currentAuthorId !== authorId ) {
			card.innerHTML = buildCardHTML( author );
			currentAuthorId = authorId;
		}

		// Position before making visible so we can measure height.
		card.style.display = 'block';
		positionCard( anchor );
		card.classList.add( 'is-visible' );
	}

	function hideCard() {
		hideTimer = setTimeout( function () {
			if ( card ) {
				card.classList.remove( 'is-visible' );
			}
		}, 200 );
	}

	// ── Event delegation ─────────────────────────────────────────────────────

	function getAuthorId( el ) {
		// data-author-id attribute.
		if ( el.dataset && el.dataset.authorId ) {
			return el.dataset.authorId;
		}

		// Single author site — use first key from twtAeoHover.authors.
		var keys = Object.keys( twtAeoHover.authors );
		if ( keys.length === 1 ) {
			return keys[0];
		}

		return null;
	}

	function onEnter( e ) {
		var anchor = e.target.closest( SELECTORS );
		if ( ! anchor ) {
			return;
		}
		var authorId = getAuthorId( anchor );
		if ( authorId ) {
			showCard( anchor, String( authorId ) );
		}
	}

	function onLeave( e ) {
		var anchor = e.target.closest( SELECTORS );
		if ( anchor ) {
			hideCard();
		}
	}

	function onKeyDown( e ) {
		if ( e.key === 'Escape' && card ) {
			card.classList.remove( 'is-visible' );
		}
	}

	document.addEventListener( 'mouseover',  onEnter );
	document.addEventListener( 'mouseout',   onLeave );
	document.addEventListener( 'focusin',    onEnter );
	document.addEventListener( 'focusout',   onLeave );
	document.addEventListener( 'keydown',    onKeyDown );

	// ── Helpers ──────────────────────────────────────────────────────────────

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function getDomainLabel( url ) {
		try {
			var host   = new URL( url ).hostname.replace( /^www\./, '' );
			var domain = host.split( '.' )[0].toLowerCase();
			var map    = { linkedin: 'LinkedIn', facebook: 'Facebook', twitter: 'Twitter', x: 'X', github: 'GitHub', youtube: 'YouTube', instagram: 'Instagram' };
			return map[ domain ] || ( domain.charAt(0).toUpperCase() + domain.slice(1) );
		} catch ( err ) {
			return url;
		}
	}

} )();
