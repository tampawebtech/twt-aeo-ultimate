/* global wp, twtAeoCrawlerFeed */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		const mountEl  = document.getElementById( 'twt-aeo-dataviews-feed' );
		const legacyEl = document.getElementById( 'twt-aeo-legacy-feed' );

		if ( ! mountEl ) return;

		// Progressive enhancement: require DataViews + wp.element.
		if (
			! window.wp ||
			! window.wp.element ||
			! window.wp.dataViews ||
			! window.wp.dataViews.DataViews ||
			! window.wp.dataViews.filterSortAndPaginate
		) {
			return; // Legacy PHP table remains visible.
		}

		const { DataViews, filterSortAndPaginate } = window.wp.dataViews;
		const { createElement: el, useState, useMemo } = window.wp.element;
		const { __ } = window.wp.i18n;

		const feed          = window.twtAeoCrawlerFeed || {};
		const rawData       = feed.entries || [];
		const companyColors = feed.companyColors || {};

		// ── Field definitions ─────────────────────────────────────────────────

		const uniqueCompanies = [ ...new Set( rawData.map( ( i ) => i.company ).filter( Boolean ) ) ]
			.sort()
			.map( ( c ) => ( { value: c, label: c } ) );

		const fields = [
			{
				id:            'time',
				label:         __( 'Time', 'twt-aeo-ultimate' ),
				getValue:      ( { item } ) => item.time,
				render:        ( { item } ) =>
					el( 'span', { title: item.datetime, style: { fontSize: '12px', color: '#646970' } },
						item.age + ' ' + __( 'ago', 'twt-aeo-ultimate' )
					),
				enableSorting: true,
			},
			{
				id:       'bot',
				label:    __( 'Bot', 'twt-aeo-ultimate' ),
				getValue: ( { item } ) => item.bot,
				render:   ( { item } ) => {
					const color = companyColors[ item.company ] || '#888888';
					return el(
						'span',
						{ style: { fontFamily: 'monospace', fontSize: '12px' } },
						el( 'span', {
							'aria-hidden': 'true',
							style: {
								display:      'inline-block',
								width:        '7px',
								height:       '7px',
								borderRadius: '50%',
								background:   color,
								marginRight:  '5px',
								verticalAlign:'middle',
								flexShrink:   '0',
							},
						} ),
						item.bot || '—'
					);
				},
				enableSorting: true,
			},
			{
				id:       'company',
				label:    __( 'Company', 'twt-aeo-ultimate' ),
				getValue: ( { item } ) => item.company,
				render:   ( { item } ) =>
					el( 'span', { style: { fontSize: '12px', color: '#646970' } }, item.company || '—' ),
				enableSorting: true,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
				elements: uniqueCompanies,
			},
			{
				id:            'page',
				label:         __( 'Page', 'twt-aeo-ultimate' ),
				getValue:      ( { item } ) => item.title || item.url,
				render:        ( { item } ) => {
					if ( ! item.url ) {
						return el( 'span', {}, item.title || '—' );
					}
					return el(
						'span',
						{},
						el( 'a', {
							href:   item.url,
							target: '_blank',
							rel:    'noopener noreferrer',
							style:  { color: '#2271b1', textDecoration: 'none', fontSize: '13px', fontWeight: '500' },
						}, item.title || item.url ),
						item.path
							? el( 'span', { style: { fontSize: '11px', color: '#999', marginLeft: '6px' } }, item.path )
							: null
					);
				},
				enableSorting: false,
			},
		];

		// ── App component ─────────────────────────────────────────────────────

		function CrawlerFeedApp() {
			const [ view, setView ] = useState( {
				type:    'table',
				search:  '',
				filters: [],
				sort:    { field: 'time', direction: 'desc' },
				page:    1,
				perPage: 25,
				fields:  [ 'time', 'bot', 'company', 'page' ],
			} );

			const { data, paginationInfo } = useMemo(
				() => filterSortAndPaginate( rawData, view, fields ),
				// eslint-disable-next-line react-hooks/exhaustive-deps
				[ view ]
			);

			return el( DataViews, {
				data,
				fields,
				view,
				onChangeView:   setView,
				getItemId:      ( item ) => String( item.id ),
				paginationInfo,
				defaultLayouts: { table: {} },
			} );
		}

		// ── Mount ─────────────────────────────────────────────────────────────

		try {
			if ( wp.element.createRoot ) {
				wp.element.createRoot( mountEl ).render( el( CrawlerFeedApp, null ) );
			} else {
				wp.element.render( el( CrawlerFeedApp, null ), mountEl );
			}

			// Progressive enhancement: swap visibility now that React has mounted.
			mountEl.style.display = '';
			if ( legacyEl ) {
				legacyEl.style.display = 'none';
			}
		} catch ( e ) {
			// If mounting fails, leave the legacy PHP table in place.
			// eslint-disable-next-line no-console
			console.warn( '[TWT AEO] DataViews mount failed, using legacy table.', e );
		}
	} );
} )();
