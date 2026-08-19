/**
 * Client-side routing over the same paths the server already understands.
 *
 * Every route here has a real rewrite rule behind it, so a deep link, a refresh or a browser back
 * button all work — which matters when a technician's phone reloads the tab after a call comes in.
 */

import { useCallback, useEffect, useState } from 'react';
import { boot } from './api';

function basePath() {
	try {
		return new URL( boot.baseUrl ).pathname;
	} catch {
		return '/survey/';
	}
}

export function parseLocation() {
	const base = basePath();
	const path = window.location.pathname;
	const rest = path.startsWith( base ) ? path.slice( base.length ) : '';
	const clean = rest.replace( /^\/+|\/+$/g, '' );

	if ( clean === '' ) {
		return { name: 'list', id: 0 };
	}

	if ( clean === 'new' ) {
		return { name: 'new', id: 0 };
	}

	const match = clean.match( /^(\d+)(?:\/(review|proposal))?$/ );

	if ( match ) {
		return { name: match[ 2 ] || 'edit', id: parseInt( match[ 1 ], 10 ) };
	}

	return { name: 'list', id: 0 };
}

export function pathFor( route ) {
	const base = basePath();

	switch ( route.name ) {
		case 'new':
			return `${ base }new/`;
		case 'edit':
			return `${ base }${ route.id }/`;
		case 'review':
			return `${ base }${ route.id }/review/`;
		case 'proposal':
			return `${ base }${ route.id }/proposal/`;
		default:
			return base;
	}
}

export function useRouter() {
	const [ route, setRoute ] = useState( () => parseLocation() );

	useEffect( () => {
		const onPop = () => setRoute( parseLocation() );

		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	const navigate = useCallback( ( next, { replace = false } = {} ) => {
		const path = pathFor( next );

		if ( replace ) {
			window.history.replaceState( {}, '', path );
		} else {
			window.history.pushState( {}, '', path );
		}

		setRoute( next );
		window.scrollTo( 0, 0 );
	}, [] );

	return [ route, navigate ];
}
