/**
 * Photo evidence for a single punch-list answer.
 *
 * A vertical list rather than a thumbnail grid: every caption is visible without a tap, which is
 * what makes technicians actually write them. A caption is the difference between a proposal that
 * shows a customer a photo of a panel and one that tells them "corroded neutral bar in main panel" —
 * and that sentence is doing most of the selling.
 *
 * The file input carries no capture attribute on purpose. Forcing the camera would block picking a
 * shot taken ten minutes ago before the app was open, which is how this actually gets used.
 */

import { useRef, useState } from 'react';
import { usePhotoContext } from '../hooks/usePhotos';

const MAX_PER_ITEM = 6;

function CaptionField( { photo, onSave, disabled } ) {
	const [ value, setValue ] = useState( photo.caption || '' );
	const [ saving, setSaving ] = useState( false );

	// Committed on blur rather than debounced per keystroke: a caption is written once, and a PATCH
	// per character on a phone connection is waste that competes with the autosave that matters.
	const commit = async () => {
		if ( value === ( photo.caption || '' ) ) {
			return;
		}

		setSaving( true );

		try {
			await onSave( photo.id, value );
		} finally {
			setSaving( false );
		}
	};

	return (
		<input
			type="text"
			className="pe-photo__caption"
			value={ value }
			disabled={ disabled }
			maxLength={ 140 }
			placeholder="Describe what this shows"
			aria-label="Photo caption"
			data-saving={ saving ? 'yes' : 'no' }
			onChange={ ( event ) => setValue( event.target.value ) }
			onBlur={ commit }
		/>
	);
}

export default function PhotoField( { itemKey, panelId = '' } ) {
	const context = usePhotoContext();
	const inputRef = useRef( null );
	const [ error, setError ] = useState( null );

	if ( ! context ) {
		return null;
	}

	const { enabled, photosFor, pendingFor, add, retry, discard, remove, setCaption } = context;
	const photos = photosFor( itemKey, panelId );
	const pending = pendingFor( itemKey, panelId );
	const total = photos.length + pending.length;
	const full = total >= MAX_PER_ITEM;

	const choose = async ( event ) => {
		const files = Array.from( event.target.files || [] );

		// Reset immediately so picking the same file twice in a row still fires a change event.
		event.target.value = '';
		setError( null );

		for ( const file of files.slice( 0, MAX_PER_ITEM - total ) ) {
			await add( file, itemKey, panelId );
		}
	};

	const drop = async ( id ) => {
		setError( null );

		try {
			await remove( id );
		} catch ( err ) {
			setError( err.message || 'That photo could not be removed.' );
		}
	};

	return (
		<div className="pe-photos">
			<span className="pe-field__label">
				{ 'Photos' }
				{ total > 0 && (
					<span className="pe-photos__count">
						{ ' ' }
						{ total } / { MAX_PER_ITEM }
					</span>
				) }
			</span>

			{ ( photos.length > 0 || pending.length > 0 ) && (
				<ul className="pe-photos__list">
					{ photos.map( ( photo ) => (
						<li key={ photo.id } className="pe-photo">
							<img
								className="pe-photo__thumb"
								src={ photo.thumb }
								alt={ photo.caption || 'Inspection photo' }
								width="64"
								height="64"
								loading="lazy"
							/>
							<CaptionField photo={ photo } onSave={ setCaption } disabled={ ! enabled } />
							{ enabled && (
								<button
									type="button"
									className="pe-photo__remove"
									aria-label="Remove photo"
									onClick={ () => drop( photo.id ) }
								>
									{ '×' }
								</button>
							) }
						</li>
					) ) }

					{ pending.map( ( entry ) => (
						<li
							key={ entry.tempId }
							className={ `pe-photo is-pending ${ entry.error ? 'is-error' : '' }` }
						>
							<img
								className="pe-photo__thumb"
								src={ entry.preview }
								alt=""
								width="64"
								height="64"
							/>
							{ entry.error ? (
								<>
									<button
										type="button"
										className="pe-photo__retry"
										onClick={ () => retry( entry.tempId ) }
									>
										{ entry.error }
									</button>
									<button
										type="button"
										className="pe-photo__remove"
										aria-label="Discard photo"
										onClick={ () => discard( entry.tempId ) }
									>
										{ '×' }
									</button>
								</>
							) : (
								<span className="pe-photo__progress" role="status">
									<span
										className="pe-photo__bar"
										style={ { width: `${ Math.round( entry.progress * 100 ) }%` } }
									/>
									<span className="pe-visually-hidden">{ 'Uploading' }</span>
								</span>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ enabled && (
				<>
					<input
						ref={ inputRef }
						type="file"
						accept="image/*"
						multiple
						className="pe-visually-hidden"
						onChange={ choose }
					/>
					<button
						type="button"
						className="pe-btn pe-photos__add"
						disabled={ full }
						onClick={ () => inputRef.current?.click() }
					>
						{ full ? `Photo limit reached (${ MAX_PER_ITEM })` : '+ Add photo' }
					</button>
				</>
			) }

			{ error && <p className="pe-error">{ error }</p> }
		</div>
	);
}
