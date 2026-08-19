/**
 * Persistent save state.
 *
 * Shown at all times rather than as a transient toast: the question a technician actually has in
 * the field is "is my work safe right now", and a toast that faded twenty seconds ago cannot
 * answer it. "Offline" is worded as reassurance, not an error, because the data is already on the
 * device and will go up on its own.
 */

const STATES = {
	idle: { label: 'All changes saved', className: 'is-saved' },
	pending: { label: 'Saving…', className: 'is-pending' },
	saving: { label: 'Saving…', className: 'is-pending' },
	saved: { label: 'Saved', className: 'is-saved' },
	offline: { label: 'Saved on this device — will sync', className: 'is-offline' },
	error: { label: 'Could not save', className: 'is-error' },
};

export default function SaveIndicator( { status, savedAt, readOnly } ) {
	if ( readOnly ) {
		return <span className="pe-save is-readonly">{ 'Read only' }</span>;
	}

	const state = STATES[ status ] || STATES.idle;

	return (
		<span className={ `pe-save ${ state.className }` } role="status" aria-live="polite">
			{ state.label }
			{ status === 'saved' && savedAt && (
				<span className="pe-save__time">
					{ ' ' }
					{ new Date( savedAt ).toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } ) }
				</span>
			) }
		</span>
	);
}
