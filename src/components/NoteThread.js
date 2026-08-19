/**
 * The conversation between a technician and their reviewer.
 *
 * Rendered on both sides of the workflow with the same component and the same data — the only
 * difference is which side of the thread your own notes sit on. A reviewer asking "was the meter base
 * bonded?" is useless if the technician cannot see the question and answer it, and this is the
 * cheapest place to make that round trip happen without a phone call.
 */

import { useState } from 'react';
import { api, boot } from '../api';

const EVENT_LABELS = {
	changes_requested: 'Changes requested',
	accepted: 'Accepted',
};

function when( iso ) {
	const date = new Date( iso );

	return Number.isNaN( date.getTime() ) ? '' : date.toLocaleString();
}

export default function NoteThread( { surveyId, notes, onAdded } ) {
	const [ draft, setDraft ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState( null );

	const send = async () => {
		const content = draft.trim();

		if ( ! content || sending ) {
			return;
		}

		setSending( true );
		setError( null );

		try {
			const note = await api.addNote( surveyId, content );

			// Only clear the box once the server has it. Losing a paragraph somebody typed on a phone
			// because the connection dropped mid-send is not a recoverable experience.
			setDraft( '' );
			onAdded( note );
		} catch ( err ) {
			setError( err );
		} finally {
			setSending( false );
		}
	};

	return (
		<section className="pe-notes">
			<h2 className="pe-section__title">{ 'Notes' }</h2>

			{ notes.length === 0 && (
				<p className="pe-muted">{ 'No notes yet.' }</p>
			) }

			{ notes.length > 0 && (
				<ol className="pe-notes__list">
					{ notes.map( ( note ) => (
						<li
							key={ note.id }
							className={ `pe-note ${ note.author.id === boot.user?.id ? 'is-mine' : '' } ${
								note.event ? 'is-event' : ''
							}` }
						>
							<div className="pe-note__head">
								<span className="pe-note__author">
									{ note.author.name }
									{ note.author.is_reviewer && (
										<span className="pe-note__badge">{ 'Reviewer' }</span>
									) }
								</span>
								<span className="pe-note__time">{ when( note.created ) }</span>
							</div>
							{ !! note.event && (
								<span className="pe-note__event">
									{ EVENT_LABELS[ note.event ] || note.event }
								</span>
							) }
							{ /* Notes are stored through wp_kses_post and arrive as plain text, so line
							     breaks are the only formatting to preserve — CSS does that without
							     opening the door to injected markup. */ }
							<p className="pe-note__body">{ note.content }</p>
						</li>
					) ) }
				</ol>
			) }

			<div className="pe-notes__compose">
				<label className="pe-field">
					<span className="pe-visually-hidden">{ 'Add a note' }</span>
					<textarea
						rows={ 3 }
						value={ draft }
						placeholder="Add a note…"
						onChange={ ( event ) => setDraft( event.target.value ) }
					/>
				</label>
				{ error && <p className="pe-error">{ error.message }</p> }
				<button
					type="button"
					className="pe-btn pe-btn--primary"
					onClick={ send }
					disabled={ sending || ! draft.trim() }
				>
					{ sending ? 'Sending…' : 'Add note' }
				</button>
			</div>
		</section>
	);
}
