/**
 * Debounced autosave with a synchronous local mirror.
 *
 * The ordering matters. Every edit is written to localStorage immediately and synchronously, then
 * the network save is debounced behind it. If the tab is killed, the phone dies, or the signal
 * drops in a crawlspace, the local copy is already on disk — the only work at risk is whatever
 * happened in the last few milliseconds, not the last fifty minutes.
 *
 * The local entry is deleted on a confirmed server save, so its mere existence on load means
 * "there are edits the server never received" — a more reliable signal than comparing clocks
 * between a phone and a server.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { api, boot, OfflineError } from '../api';

const storageKey = ( id ) => `pe-survey-draft-${ id }`;

export function readLocalDraft( id ) {
	try {
		const raw = window.localStorage.getItem( storageKey( id ) );
		return raw ? JSON.parse( raw ) : null;
	} catch {
		return null;
	}
}

export function clearLocalDraft( id ) {
	try {
		window.localStorage.removeItem( storageKey( id ) );
	} catch {
		// A full or disabled localStorage must never break the form.
	}
}

function writeLocalDraft( id, doc ) {
	try {
		window.localStorage.setItem(
			storageKey( id ),
			JSON.stringify( { doc, at: Date.now(), user: boot.user?.id } )
		);
	} catch {
		// Ditto — the network save is still the source of truth.
	}
}

export function useAutosave( id, doc, { enabled = true, delay = 1500 } = {} ) {
	const [ status, setStatus ] = useState( 'idle' );
	const [ savedAt, setSavedAt ] = useState( null );

	const timer = useRef( null );
	const controller = useRef( null );
	const latest = useRef( doc );
	const dirty = useRef( false );
	const skipFirst = useRef( true );

	latest.current = doc;

	const push = useCallback(
		async ( { keepalive = false } = {} ) => {
			if ( ! id || ! latest.current || ! dirty.current ) {
				return;
			}

			controller.current?.abort();
			controller.current = keepalive ? null : new AbortController();

			setStatus( 'saving' );

			try {
				await api.saveSurvey( id, latest.current, controller.current?.signal );
				dirty.current = false;
				clearLocalDraft( id );
				setStatus( 'saved' );
				setSavedAt( Date.now() );
			} catch ( error ) {
				if ( error.name === 'AbortError' ) {
					return;
				}

				setStatus( error instanceof OfflineError ? 'offline' : 'error' );
			}
		},
		[ id ]
	);

	useEffect( () => {
		if ( ! enabled || ! id || ! doc ) {
			return undefined;
		}

		// The first run is the document arriving from the server, not an edit.
		if ( skipFirst.current ) {
			skipFirst.current = false;
			return undefined;
		}

		dirty.current = true;
		writeLocalDraft( id, doc );
		setStatus( 'pending' );

		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( push, delay );

		return () => window.clearTimeout( timer.current );
	}, [ id, doc, enabled, delay, push ] );

	// A phone backgrounded mid-inspection fires visibilitychange, not unload. This is the event
	// that actually catches someone switching to take a photo or answer a call.
	useEffect( () => {
		const flush = () => {
			if ( document.visibilityState === 'hidden' && dirty.current ) {
				window.clearTimeout( timer.current );
				push( { keepalive: true } );
			}
		};

		document.addEventListener( 'visibilitychange', flush );
		window.addEventListener( 'pagehide', flush );

		return () => {
			document.removeEventListener( 'visibilitychange', flush );
			window.removeEventListener( 'pagehide', flush );
		};
	}, [ push ] );

	const saveNow = useCallback( () => {
		window.clearTimeout( timer.current );
		dirty.current = true;
		return push();
	}, [ push ] );

	return { status, savedAt, saveNow, hasUnsaved: () => dirty.current };
}
