/**
 * One punch-list item: the three-state control, plus severity and a note once it fails.
 *
 * The Safety Hazards section is polarity-inverted — there, ticking the box on the paper form meant
 * "I found this", so a stored 'fail' has to read as "Present". Same three stored values either way,
 * so the action plan can treat every failure identically regardless of how it was worded.
 */

import PhotoField from './PhotoField';

const LABELS = {
	normal: { pass: 'Pass', fail: 'Fail', na: 'N/A' },
	hazard: { pass: 'Not present', fail: 'Present', na: 'N/A' },
};

const SEVERITY_LABELS = {
	immediate: 'Immediate hazard',
	recommended: 'Recommended',
	optional: 'Optional upgrade',
};

export default function ItemRow( { item, polarity = 'normal', panelId = '', answer, onChange } ) {
	const current = answer?.status || '';
	const labels = LABELS[ polarity ] || LABELS.normal;

	const setStatus = ( status ) => {
		const next = status === current ? '' : status;

		onChange( {
			...answer,
			status: next,
			// Seed the severity from the catalog's default so a failure always lands in a bucket
			// even if the technician never opens the severity control.
			severity: next === 'fail' ? answer?.severity || item.severity : '',
		} );
	};

	const failed = current === 'fail';

	return (
		<div className={ `pe-item ${ failed ? 'is-failed' : '' }` }>
			<p className="pe-item__label" id={ `label-${ item.key }` }>
				{ item.label }
			</p>

			<div className="pe-choice" role="group" aria-labelledby={ `label-${ item.key }` }>
				{ [ 'pass', 'fail', 'na' ].map( ( value ) => (
					<button
						key={ value }
						type="button"
						className={ `pe-choice__btn is-${ value } ${ current === value ? 'is-active' : '' }` }
						aria-pressed={ current === value }
						onClick={ () => setStatus( value ) }
					>
						{ labels[ value ] }
					</button>
				) ) }
			</div>

			{ failed && (
				<div className="pe-item__detail">
					<label className="pe-field">
						<span className="pe-field__label">{ 'Priority' }</span>
						<select
							value={ answer?.severity || item.severity }
							onChange={ ( event ) =>
								onChange( { ...answer, severity: event.target.value } )
							}
						>
							{ Object.entries( SEVERITY_LABELS ).map( ( [ value, label ] ) => (
								<option key={ value } value={ value }>
									{ label }
								</option>
							) ) }
						</select>
					</label>

					<label className="pe-field">
						<span className="pe-field__label">{ 'Note' }</span>
						<textarea
							rows={ 2 }
							value={ answer?.note || '' }
							placeholder="What did you see?"
							onChange={ ( event ) =>
								onChange( { ...answer, note: event.target.value } )
							}
						/>
					</label>

					{ /* Photos hang off failures only. A photo of something that passed is a photo
					     nobody will ever look at, and the proposal has no place to put it. */ }
					<PhotoField itemKey={ item.key } panelId={ panelId } />
				</div>
			) }
		</div>
	);
}
