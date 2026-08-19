/**
 * The reviewer's controls: what moved under them, and the two decisions they can make.
 *
 * Sits above the form rather than replacing it. Josh needs to read the actual answers to decide
 * anything, so the review screen is the survey plus this — not a separate summary that would drift
 * out of step with the document it describes.
 */

import { useState } from 'react';
import { api } from '../api';

function ChangeList( { changes } ) {
	return (
		<ul className="pe-changes">
			{ changes.map( ( change, index ) => (
				<li key={ `${ change.key }-${ index }` } className="pe-changes__row">
					<span className="pe-changes__label">
						{ change.label }
						{ !! change.panel && (
							<span className="pe-changes__where">{ change.panel }</span>
						) }
						{ ! change.panel && !! change.section && (
							<span className="pe-changes__where">{ change.section }</span>
						) }
					</span>
					{ /* A status that did not move is not worth an arrow. "Fail → Fail" beside an edit
					     that touched the note or the photos reads as a broken diff, not as an edit. */ }
					<span className="pe-changes__move">
						{ change.status_changed ? (
							<>
								{ change.from }
								{ ' → ' }
								<strong>{ change.to }</strong>
							</>
						) : (
							<em className="pe-changes__detail">
								{ change.detail?.length ? change.detail.join( ', ' ) : 'Edited' }
							</em>
						) }
					</span>
					{ change.status_changed && change.detail?.length > 0 && (
						<span className="pe-changes__detail">{ change.detail.join( ', ' ) }</span>
					) }
				</li>
			) ) }
		</ul>
	);
}

export default function ReviewPanel( { survey, changes, onDecided, navigate } ) {
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ showChanges, setShowChanges ] = useState( false );

	const decide = async ( action ) => {
		setError( null );

		// Sending a survey back with no explanation gives the technician nothing to act on, so the
		// button stays inert rather than firing a request the server would reject anyway.
		if ( action === 'changes' && ! note.trim() ) {
			setError( { message: 'Add a note explaining what needs to change.' } );
			return;
		}

		setBusy( action );

		try {
			const result =
				action === 'accept'
					? await api.acceptSurvey( survey.id, note.trim() )
					: await api.requestChanges( survey.id, note.trim() );

			setNote( '' );
			onDecided( result );
		} catch ( err ) {
			setError( err );
		} finally {
			setBusy( '' );
		}
	};

	const canAccept = survey.can?.accept;
	const canReturn = survey.can?.request_changes;

	// Accepting is the moment the work turns into something to sell, so the way to the proposal sits
	// right where the acceptance happened. Left to be found from the surveys list later, it is a step
	// that quietly does not get taken.
	const accepted = survey.status === 'pe_accepted';

	return (
		<div className="pe-review">
			{ accepted && (
				<div className="pe-banner pe-banner--ok">
					<p>
						<strong>{ 'This survey has been accepted.' }</strong>
						{ ' The customer\'s action plan is built from it.' }
					</p>
					<div className="pe-banner__actions">
						<button
							type="button"
							className="pe-btn pe-btn--primary"
							onClick={ () => navigate( { name: 'proposal', id: survey.id } ) }
						>
							{ 'Build the proposal' }
						</button>
					</div>
				</div>
			) }

			{ changes.length > 0 && (
				<div className="pe-banner pe-banner--warn" role="alert">
					<p>
						<strong>
							{ changes.length === 1
								? '1 item has changed'
								: `${ changes.length } items have changed` }
						</strong>
						{ ' since this was submitted for review.' }
					</p>
					<div className="pe-banner__actions">
						<button
							type="button"
							className="pe-btn"
							aria-expanded={ showChanges }
							onClick={ () => setShowChanges( ( open ) => ! open ) }
						>
							{ showChanges ? 'Hide changes' : 'Show changes' }
						</button>
					</div>
					{ showChanges && <ChangeList changes={ changes } /> }
				</div>
			) }

			{ ( canAccept || canReturn ) && (
				<section className="pe-review__decide">
					<h2 className="pe-section__title">{ 'Review decision' }</h2>

					<label className="pe-field">
						<span className="pe-field__label">
							{ canReturn
								? 'Note to the technician (required to send back)'
								: 'Note to the technician' }
						</span>
						<textarea
							rows={ 3 }
							value={ note }
							placeholder="What needs to change, or anything worth recording."
							onChange={ ( event ) => setNote( event.target.value ) }
						/>
					</label>

					{ error && <p className="pe-error">{ error.message }</p> }

					<div className="pe-review__actions">
						{ canReturn && (
							<button
								type="button"
								className="pe-btn"
								onClick={ () => decide( 'changes' ) }
								disabled={ !! busy }
							>
								{ busy === 'changes' ? 'Sending…' : 'Request changes' }
							</button>
						) }
						{ canAccept && (
							<button
								type="button"
								className="pe-btn pe-btn--primary"
								onClick={ () => decide( 'accept' ) }
								disabled={ !! busy }
							>
								{ busy === 'accept' ? 'Accepting…' : 'Accept survey' }
							</button>
						) }
					</div>
				</section>
			) }
		</div>
	);
}
