/**
 * REST client for paumalu/v1.
 *
 * Plain fetch rather than @wordpress/api-fetch: autosave needs explicit control over aborting an
 * in-flight request when the next keystroke lands, and over distinguishing "the server said no"
 * from "the phone lost signal" — the second is recoverable and must not surface as a scary error
 * to somebody standing in an attic.
 */

export const boot = window.paumaluSurvey || {};

export class ApiError extends Error {
	constructor( message, status, data ) {
		super( message );
		this.name = 'ApiError';
		this.status = status;
		this.data = data;
	}
}

export class OfflineError extends Error {
	constructor() {
		super( 'offline' );
		this.name = 'OfflineError';
	}
}

async function request( path, { method = 'GET', body, signal } = {} ) {
	let response;

	try {
		response = await fetch( `${ boot.restRoot }${ path }`, {
			method,
			credentials: 'same-origin',
			signal,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': boot.nonce,
			},
			body: body === undefined ? undefined : JSON.stringify( body ),
		} );
	} catch ( error ) {
		// AbortError is us cancelling a superseded autosave; let the caller ignore it.
		if ( error.name === 'AbortError' ) {
			throw error;
		}

		throw new OfflineError();
	}

	if ( response.status === 204 ) {
		return null;
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new ApiError(
			payload?.message || 'Request failed.',
			response.status,
			payload?.data || null
		);
	}

	return payload;
}

/**
 * Multipart upload with progress.
 *
 * XHR rather than fetch purely for upload.onprogress — fetch still cannot report how much of a
 * request body has gone out. On a good connection nobody notices; on one bar in a crawlspace, a bar
 * that is visibly moving is the difference between waiting and force-quitting the browser.
 *
 * Note the absent Content-Type header: the browser has to set it itself so it can append the
 * multipart boundary, and providing one silently breaks the parse on the server.
 */
function upload( path, formData, { signal, onProgress } = {} ) {
	return new Promise( ( resolve, reject ) => {
		const xhr = new XMLHttpRequest();

		xhr.open( 'POST', `${ boot.restRoot }${ path }` );
		xhr.withCredentials = true;
		xhr.setRequestHeader( 'X-WP-Nonce', boot.nonce );

		if ( onProgress ) {
			xhr.upload.onprogress = ( event ) => {
				if ( event.lengthComputable ) {
					onProgress( event.loaded / event.total );
				}
			};
		}

		xhr.onload = () => {
			let payload = null;

			try {
				payload = JSON.parse( xhr.responseText );
			} catch {
				payload = null;
			}

			if ( xhr.status >= 200 && xhr.status < 300 ) {
				resolve( payload );
				return;
			}

			reject(
				new ApiError( payload?.message || 'Upload failed.', xhr.status, payload?.data || null )
			);
		};

		// A network-level failure and a cancelled request are different things to the caller: the
		// first is worth retrying, the second was deliberate.
		xhr.onerror = () => reject( new OfflineError() );
		xhr.ontimeout = () => reject( new OfflineError() );
		xhr.onabort = () => {
			const error = new Error( 'aborted' );
			error.name = 'AbortError';
			reject( error );
		};

		if ( signal ) {
			signal.addEventListener( 'abort', () => xhr.abort(), { once: true } );
		}

		xhr.send( formData );
	} );
}

export const api = {
	catalog: ( version ) =>
		request( `/catalog?version=${ encodeURIComponent( version || boot.catalogVersion ) }` ),

	listSurveys: ( params = {} ) => {
		const query = new URLSearchParams( params ).toString();
		return request( `/surveys${ query ? `?${ query }` : '' }` );
	},

	createSurvey: ( data ) =>
		request( '/surveys', { method: 'POST', body: data ? { data } : {} } ),

	getSurvey: ( id ) => request( `/surveys/${ id }` ),

	saveSurvey: ( id, data, signal ) =>
		request( `/surveys/${ id }`, { method: 'PATCH', body: { data }, signal } ),

	submitSurvey: ( id ) => request( `/surveys/${ id }/submit`, { method: 'POST' } ),

	deleteSurvey: ( id ) => request( `/surveys/${ id }`, { method: 'DELETE' } ),

	listPhotos: ( surveyId ) => request( `/surveys/${ surveyId }/photos` ),

	uploadPhoto: ( surveyId, { blob, filename, itemKey, panelId, caption }, options = {} ) => {
		const form = new FormData();

		form.append( 'file', blob, filename );
		form.append( 'item_key', itemKey || '' );
		form.append( 'panel_id', panelId || '' );
		form.append( 'caption', caption || '' );

		return upload( `/surveys/${ surveyId }/photos`, form, options );
	},

	updatePhoto: ( id, patch ) => request( `/photos/${ id }`, { method: 'PATCH', body: patch } ),

	deletePhoto: ( id ) => request( `/photos/${ id }`, { method: 'DELETE' } ),

	listNotes: ( surveyId ) => request( `/surveys/${ surveyId }/notes` ),

	addNote: ( surveyId, content ) =>
		request( `/surveys/${ surveyId }/notes`, { method: 'POST', body: { content } } ),

	requestChanges: ( surveyId, note ) =>
		request( `/surveys/${ surveyId }/request-changes`, { method: 'POST', body: { note } } ),

	acceptSurvey: ( surveyId, note = '' ) =>
		request( `/surveys/${ surveyId }/accept`, { method: 'POST', body: { note } } ),

	getProposal: ( surveyId ) => request( `/surveys/${ surveyId }/proposal` ),

	saveProposal: ( surveyId, proposal, signal ) =>
		request( `/surveys/${ surveyId }/proposal`, { method: 'POST', body: proposal, signal } ),

	regenerateProposal: ( surveyId ) =>
		request( `/surveys/${ surveyId }/proposal/regenerate`, { method: 'POST' } ),

	sendProposal: ( surveyId, email = '' ) =>
		request( `/surveys/${ surveyId }/proposal/send`, { method: 'POST', body: { email } } ),

	closeProposal: ( surveyId ) =>
		request( `/surveys/${ surveyId }/proposal/close`, { method: 'POST' } ),

	signOnsite: ( surveyId, name, image ) =>
		request( `/surveys/${ surveyId }/proposal/sign`, {
			method: 'POST',
			body: { name, image },
		} ),
};
