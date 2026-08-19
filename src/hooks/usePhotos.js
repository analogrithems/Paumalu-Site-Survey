/**
 * Photo state for one survey: what is stored, what is still going up, and what failed.
 *
 * Two sources of truth are kept deliberately in step. The survey document holds attachment ids
 * inside each answer — that is what versions with the survey and what the proposal reads. The
 * attachments themselves carry the item key and panel id in meta, which is what lets the server
 * clean up after a delete that happened while the phone was offline. The document is authoritative;
 * the meta is an index.
 */

import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import { api, OfflineError } from '../api';
import { prepareImage } from '../photos';

const PhotoContext = createContext( null );

export const PhotoProvider = PhotoContext.Provider;

export function usePhotoContext() {
	return useContext( PhotoContext );
}

function slotKey( itemKey, panelId ) {
	return `${ panelId || '' }|${ itemKey }`;
}

let tempCounter = 0;

/**
 * @param {number}   surveyId
 * @param {Object}   stored    Photo map from the survey response, keyed by attachment id.
 * @param {Function} onAttach  Called with (itemKey, panelId, attachmentId) once a photo lands.
 * @param {Function} onDetach  Called with (itemKey, panelId, attachmentId) once one is removed.
 */
export function usePhotos( surveyId, stored, { onAttach, onDetach, enabled = true } = {} ) {
	const [ photos, setPhotos ] = useState( () => ( { ...( stored || {} ) } ) );
	const [ pending, setPending ] = useState( [] );
	const previews = useRef( new Map() );

	// Replace the whole map when a different survey loads, but never on a re-render of the same one:
	// the server copy is a snapshot from load time and would otherwise resurrect deleted photos.
	const loadedFor = useRef( surveyId );

	if ( loadedFor.current !== surveyId ) {
		loadedFor.current = surveyId;
		previews.current.forEach( ( url ) => URL.revokeObjectURL( url ) );
		previews.current.clear();
		setPhotos( { ...( stored || {} ) } );
		setPending( [] );
	}

	const releasePreview = useCallback( ( tempId ) => {
		const url = previews.current.get( tempId );

		if ( url ) {
			URL.revokeObjectURL( url );
			previews.current.delete( tempId );
		}
	}, [] );

	const add = useCallback(
		async ( file, itemKey, panelId ) => {
			const tempId = `pending-${ ++tempCounter }`;

			// The thumbnail appears the instant the file is picked, from a local object URL. Waiting
			// for a round trip before showing anything makes the app feel broken on a slow link.
			const preview = URL.createObjectURL( file );
			previews.current.set( tempId, preview );

			setPending( ( prev ) => [
				...prev,
				{ tempId, itemKey, panelId, preview, progress: 0, error: null },
			] );

			const patch = ( changes ) =>
				setPending( ( prev ) =>
					prev.map( ( entry ) => ( entry.tempId === tempId ? { ...entry, ...changes } : entry ) )
				);

			try {
				const prepared = await prepareImage( file );

				const photo = await api.uploadPhoto(
					surveyId,
					{ ...prepared, itemKey, panelId },
					{ onProgress: ( ratio ) => patch( { progress: ratio } ) }
				);

				setPhotos( ( prev ) => ( { ...prev, [ photo.id ]: photo } ) );
				onAttach?.( itemKey, panelId, photo.id );

				setPending( ( prev ) => prev.filter( ( entry ) => entry.tempId !== tempId ) );
				releasePreview( tempId );

				return photo;
			} catch ( error ) {
				// The failed entry stays on screen with its preview intact so the technician can
				// retry it. Dropping it would lose the only copy of a photo they have already moved
				// on from taking.
				patch( {
					error:
						error instanceof OfflineError
							? 'No signal — tap to retry.'
							: error.message || 'Upload failed.',
					file,
					progress: 0,
				} );

				return null;
			}
		},
		[ surveyId, onAttach, releasePreview ]
	);

	const retry = useCallback(
		( tempId ) => {
			const entry = pending.find( ( item ) => item.tempId === tempId );

			if ( ! entry?.file ) {
				return;
			}

			setPending( ( prev ) => prev.filter( ( item ) => item.tempId !== tempId ) );
			releasePreview( tempId );
			add( entry.file, entry.itemKey, entry.panelId );
		},
		[ pending, add, releasePreview ]
	);

	const discard = useCallback(
		( tempId ) => {
			setPending( ( prev ) => prev.filter( ( item ) => item.tempId !== tempId ) );
			releasePreview( tempId );
		},
		[ releasePreview ]
	);

	const remove = useCallback(
		async ( id ) => {
			const photo = photos[ id ];

			// Optimistic: the row disappears immediately and the answer stops referencing it, so an
			// autosave firing mid-delete writes the document we want either way.
			setPhotos( ( prev ) => {
				const next = { ...prev };
				delete next[ id ];
				return next;
			} );

			onDetach?.( photo?.item_key, photo?.panel_id, id );

			try {
				await api.deletePhoto( id );
			} catch ( error ) {
				// Put it back rather than pretend. A photo that looks deleted but is not would leave
				// a customer's proposal carrying an image somebody meant to pull.
				if ( photo ) {
					setPhotos( ( prev ) => ( { ...prev, [ id ]: photo } ) );
					onAttach?.( photo.item_key, photo.panel_id, id );
				}

				throw error;
			}
		},
		[ photos, onAttach, onDetach ]
	);

	const setCaption = useCallback(
		async ( id, caption ) => {
			setPhotos( ( prev ) =>
				prev[ id ] ? { ...prev, [ id ]: { ...prev[ id ], caption } } : prev
			);

			await api.updatePhoto( id, { caption } );
		},
		[]
	);

	const setFeatured = useCallback( async ( id, featured ) => {
		const updated = await api.updatePhoto( id, { featured } );

		setPhotos( ( prev ) => ( { ...prev, [ id ]: updated } ) );

		return updated;
	}, [] );

	// Group once per change rather than filtering the whole map inside every item row — a survey with
	// two panels renders well over a hundred rows.
	const bySlot = useMemo( () => {
		const map = new Map();

		Object.values( photos ).forEach( ( photo ) => {
			const key = slotKey( photo.item_key, photo.panel_id );
			map.set( key, [ ...( map.get( key ) || [] ), photo ] );
		} );

		return map;
	}, [ photos ] );

	const pendingBySlot = useMemo( () => {
		const map = new Map();

		pending.forEach( ( entry ) => {
			const key = slotKey( entry.itemKey, entry.panelId );
			map.set( key, [ ...( map.get( key ) || [] ), entry ] );
		} );

		return map;
	}, [ pending ] );

	return useMemo(
		() => ( {
			enabled,
			photos,
			photosFor: ( itemKey, panelId ) => bySlot.get( slotKey( itemKey, panelId ) ) || [],
			pendingFor: ( itemKey, panelId ) => pendingBySlot.get( slotKey( itemKey, panelId ) ) || [],
			add,
			retry,
			discard,
			remove,
			setCaption,
			setFeatured,
		} ),
		[ enabled, photos, bySlot, pendingBySlot, add, retry, discard, remove, setCaption, setFeatured ]
	);
}
