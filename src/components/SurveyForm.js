import { useCallback, useEffect, useMemo, useState } from 'react';
import { api } from '../api';
import { clearLocalDraft, readLocalDraft, useAutosave } from '../hooks/useAutosave';
import { PhotoProvider, usePhotos } from '../hooks/usePhotos';
import ItemRow from './ItemRow';
import NoteThread from './NoteThread';
import PanelEditor from './PanelEditor';
import ReviewPanel from './ReviewPanel';
import SaveIndicator from './SaveIndicator';

const MISSING_LABELS = {
	'customer.name': 'Customer name',
	'customer.address': 'Service address',
	answers: 'At least one answered item',
};

function newPanelId() {
	return `panel-${ Date.now().toString( 36 ) }`;
}

export default function SurveyForm( { route, catalog, navigate } ) {
	const [ survey, setSurvey ] = useState( null );
	const [ doc, setDoc ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ step, setStep ] = useState( 0 );
	const [ activePanel, setActivePanel ] = useState( 0 );
	const [ recovery, setRecovery ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ missing, setMissing ] = useState( [] );
	const [ notes, setNotes ] = useState( [] );
	const [ changes, setChanges ] = useState( [] );

	const readOnly = survey ? ! survey.can.edit : false;
	const { status: saveStatus, savedAt, saveNow } = useAutosave( survey?.id, doc, {
		enabled: !! survey && ! readOnly,
	} );

	// ----------------------------------------------------------------- load.

	useEffect( () => {
		let cancelled = false;

		async function load() {
			try {
				if ( route.name === 'new' ) {
					const created = await api.createSurvey();

					if ( cancelled ) {
						return;
					}

					navigate( { name: 'edit', id: created.id }, { replace: true } );
					setSurvey( created );
					setDoc( created.data );
					return;
				}

				const loaded = await api.getSurvey( route.id );

				if ( cancelled ) {
					return;
				}

				setSurvey( loaded );
				setDoc( loaded.data );
				setNotes( loaded.notes || [] );

				// Only reviewers are sent a diff, so an absent one is normal rather than empty.
				setChanges( loaded.changes || [] );

				// A local draft only survives if a save never confirmed. Offer it rather than
				// silently overwriting either copy.
				const local = readLocalDraft( loaded.id );

				if ( local?.doc ) {
					setRecovery( local );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setError( err );
				}
			}
		}

		load();

		return () => {
			cancelled = true;
		};
	}, [ route.name, route.id, navigate ] );

	// ------------------------------------------------------------- updaters.

	const setCustomer = ( field, value ) =>
		setDoc( ( prev ) => ( { ...prev, customer: { ...prev.customer, [ field ]: value } } ) );

	const setSite = ( field, value ) =>
		setDoc( ( prev ) => ( { ...prev, site: { ...prev.site, [ field ]: value } } ) );

	const setInspection = ( field, value ) =>
		setDoc( ( prev ) => ( { ...prev, inspection: { ...prev.inspection, [ field ]: value } } ) );

	const setSummary = ( field, value ) =>
		setDoc( ( prev ) => ( { ...prev, summary: { ...prev.summary, [ field ]: value } } ) );

	const setAnswer = ( sectionKey, itemKey, answer ) =>
		setDoc( ( prev ) => ( {
			...prev,
			sections: {
				...prev.sections,
				[ sectionKey ]: {
					items: { ...( prev.sections?.[ sectionKey ]?.items || {} ), [ itemKey ]: answer },
				},
			},
		} ) );

	const setUpgrade = ( key, patch ) =>
		setDoc( ( prev ) => ( {
			...prev,
			upgrades: {
				...prev.upgrades,
				[ key ]: { ...( prev.upgrades?.[ key ] || {} ), ...patch },
			},
		} ) );

	const mutatePanel = ( index, mutator ) =>
		setDoc( ( prev ) => {
			const panels = [ ...( prev.panels || [] ) ];
			panels[ index ] = mutator( panels[ index ] || {} );
			return { ...prev, panels };
		} );

	const setPanelField = ( index, field, value ) =>
		mutatePanel( index, ( panel ) => ( { ...panel, [ field ]: value } ) );

	const setPanelAnswer = ( index, itemKey, answer ) =>
		mutatePanel( index, ( panel ) => ( {
			...panel,
			items: { ...( panel.items || {} ), [ itemKey ]: answer },
		} ) );

	const setReading = ( index, key, value ) =>
		mutatePanel( index, ( panel ) => ( {
			...panel,
			readings: { ...( panel.readings || {} ), [ key ]: value },
		} ) );

	const addPanel = () => {
		setDoc( ( prev ) => {
			const panels = [ ...( prev.panels || [] ) ];
			panels.push( {
				id: newPanelId(),
				label: `Subpanel ${ panels.length }`,
				location: '',
				brand: '',
				model: '',
				amps: '',
				items: {},
				readings: {},
			} );
			return { ...prev, panels };
		} );
		setActivePanel( ( prev ) => prev + 1 );
	};

	const removePanel = ( index ) => {
		setDoc( ( prev ) => ( {
			...prev,
			panels: ( prev.panels || [] ).filter( ( _, i ) => i !== index ),
		} ) );
		setActivePanel( 0 );
	};

	// ---------------------------------------------------------------- photos.

	// A photo knows its item key and panel id but not where that item lives in the document, so the
	// catalog is what tells us which section to write into.
	const sectionForItem = useMemo( () => {
		const index = new Map();

		catalog.sections.forEach( ( section ) => {
			section.items.forEach( ( item ) => index.set( item.key, section ) );
		} );

		return index;
	}, [ catalog ] );

	const blankAnswer = { status: '', severity: '', note: '', photos: [] };

	const mutatePhotoIds = useCallback(
		( itemKey, panelId, mutate ) =>
			setDoc( ( prev ) => {
				const section = sectionForItem.get( itemKey );

				if ( ! section ) {
					return prev;
				}

				if ( section.scope === 'panel' ) {
					const index = ( prev.panels || [] ).findIndex( ( panel ) => panel.id === panelId );

					if ( index < 0 ) {
						return prev;
					}

					const panels = [ ...prev.panels ];
					const panel = panels[ index ];
					const answer = panel.items?.[ itemKey ] || blankAnswer;

					panels[ index ] = {
						...panel,
						items: {
							...( panel.items || {} ),
							[ itemKey ]: { ...answer, photos: mutate( answer.photos || [] ) },
						},
					};

					return { ...prev, panels };
				}

				const items = prev.sections?.[ section.key ]?.items || {};
				const answer = items[ itemKey ] || blankAnswer;

				return {
					...prev,
					sections: {
						...prev.sections,
						[ section.key ]: {
							items: {
								...items,
								[ itemKey ]: { ...answer, photos: mutate( answer.photos || [] ) },
							},
						},
					},
				};
			} ),
		[ sectionForItem ]
	);

	const onPhotoAttached = useCallback(
		( itemKey, panelId, id ) =>
			mutatePhotoIds( itemKey, panelId, ( ids ) =>
				ids.includes( id ) ? ids : [ ...ids, id ]
			),
		[ mutatePhotoIds ]
	);

	const onPhotoDetached = useCallback(
		( itemKey, panelId, id ) =>
			mutatePhotoIds( itemKey, panelId, ( ids ) => ids.filter( ( value ) => value !== id ) ),
		[ mutatePhotoIds ]
	);

	const photoStore = usePhotos( survey?.id, survey?.photos, {
		onAttach: onPhotoAttached,
		onDetach: onPhotoDetached,
		enabled: !! survey && ! readOnly,
	} );

	// ---------------------------------------------------------------- steps.

	const surveySections = useMemo(
		() => catalog.sections.filter( ( section ) => section.scope === 'survey' ),
		[ catalog ]
	);

	const panelSections = useMemo(
		() => catalog.sections.filter( ( section ) => section.scope === 'panel' ),
		[ catalog ]
	);

	const steps = useMemo(
		() => [
			{ key: 'customer', label: 'Customer & site' },
			{ key: 'panels', label: 'Panels' },
			...surveySections.map( ( section ) => ( {
				key: section.key,
				label: section.label,
				section,
			} ) ),
			{ key: 'upgrades', label: 'Upgrade opportunities' },
			{ key: 'summary', label: 'Summary' },
			{ key: 'notes', label: notes.length ? `Notes (${ notes.length })` : 'Notes' },
		],
		[ surveySections, notes.length ]
	);

	const current = steps[ Math.min( step, steps.length - 1 ) ];

	// --------------------------------------------------------------- submit.

	const submit = useCallback( async () => {
		setSubmitting( true );
		setMissing( [] );

		try {
			await saveNow();
			const updated = await api.submitSurvey( survey.id );
			setSurvey( updated );
			navigate( { name: 'list', id: 0 } );
		} catch ( err ) {
			if ( err.status === 400 && err.data?.missing ) {
				setMissing( err.data.missing );
			} else {
				setError( err );
			}
		} finally {
			setSubmitting( false );
		}
	}, [ saveNow, survey, navigate ] );

	// --------------------------------------------------------------- review.

	// A decision changes the status, appends to the thread and resets the diff, so the response
	// carries all three rather than making the screen refetch the whole survey and its photos.
	const onDecided = useCallback( ( result ) => {
		setSurvey( ( prev ) => ( {
			...prev,
			status: result.status,
			status_label: result.status_label,
			dirty_since_review: '',
			can: {
				...prev.can,
				accept: result.status === 'pending',
				request_changes: result.status === 'pending' || result.status === 'pe_accepted',
			},
		} ) );
		setNotes( result.notes || [] );
		setChanges( result.changes || [] );
	}, [] );

	const onNoteAdded = useCallback(
		( note ) => setNotes( ( prev ) => [ ...prev, note ] ),
		[]
	);

	// The most recent thing the reviewer said, surfaced for a technician who has just been handed
	// the survey back. Buried three taps into a Notes step, it would go unread.
	const lastReviewerNote = useMemo(
		() => [ ...notes ].reverse().find( ( note ) => note.author.is_reviewer ),
		[ notes ]
	);

	const notesStepIndex = steps.findIndex( ( item ) => item.key === 'notes' );

	// ---------------------------------------------------------------- views.

	if ( error ) {
		return (
			<div className="pe-state pe-state--error">
				<h1>{ 'Something went wrong' }</h1>
				<p>{ error.message }</p>
				<button type="button" className="pe-btn" onClick={ () => navigate( { name: 'list', id: 0 } ) }>
					{ 'Back to surveys' }
				</button>
			</div>
		);
	}

	if ( ! survey || ! doc ) {
		return (
			<div className="pe-state">
				<span className="pe-spinner" aria-hidden="true" />
				<p>{ 'Loading survey…' }</p>
			</div>
		);
	}

	return (
		<PhotoProvider value={ photoStore }>
		<div className="pe-form">
			{ recovery && (
				<div className="pe-banner pe-banner--warn" role="alert">
					<p>
						{ 'This device has unsaved changes from ' }
						{ new Date( recovery.at ).toLocaleString() }
						{ ' that never reached the server.' }
					</p>
					<div className="pe-banner__actions">
						<button
							type="button"
							className="pe-btn pe-btn--primary"
							onClick={ () => {
								setDoc( recovery.doc );
								setRecovery( null );
							} }
						>
							{ 'Restore them' }
						</button>
						<button
							type="button"
							className="pe-btn"
							onClick={ () => {
								clearLocalDraft( survey.id );
								setRecovery( null );
							} }
						>
							{ 'Discard' }
						</button>
					</div>
				</div>
			) }

			{ /* The technician's side of a request-changes: say what was asked, right where they land,
			     rather than leaving them to find the Notes step. */ }
			{ survey.status === 'pe_changes_req' && ! survey.can.review && lastReviewerNote && (
				<div className="pe-banner pe-banner--warn">
					<p>
						<strong>{ 'Changes requested' }</strong>
						{ ` by ${ lastReviewerNote.author.name }:` }
					</p>
					<p className="pe-note__body">{ lastReviewerNote.content }</p>
					<div className="pe-banner__actions">
						<button
							type="button"
							className="pe-btn"
							onClick={ () => setStep( notesStepIndex ) }
						>
							{ 'Open notes' }
						</button>
					</div>
				</div>
			) }

			{ /* Read-only for a technician: they cannot open the proposal editor, but they should
			     still know the customer said no and why, in case it changes something about a
			     follow-up call. A reviewer decides separately whether this needs closing out. */ }
			{ survey.proposal?.status === 'declined' && ! survey.can.review && (
				<div className="pe-banner pe-banner--warn">
					<p>
						<strong>{ 'The customer declined this proposal.' }</strong>
					</p>
					{ survey.proposal.decline_note && (
						<p className="pe-note__body">{ `“${ survey.proposal.decline_note }”` }</p>
					) }
				</div>
			) }

			{ survey.proposal?.status === 'closed' && ! survey.can.review && (
				<div className="pe-banner pe-banner--ok" role="status">
					<p>
						<strong>{ 'This proposal is closed.' }</strong>
						{ ' The customer was not interested — no follow-up needed.' }
					</p>
				</div>
			) }

			<div className="pe-form__head">
				<div>
					<h1>{ doc.customer?.name || 'New survey' }</h1>
					<p className="pe-muted">{ survey.status_label }</p>
				</div>
				<SaveIndicator status={ saveStatus } savedAt={ savedAt } readOnly={ readOnly } />
			</div>

			{ /* Kept outside the step navigation on purpose: Josh decides after reading several
			     sections, and having to navigate back to a "decision" tab to act on what he just
			     read is how surveys sit in the queue for a week. */ }
			{ route.name === 'review' && survey.can.review && (
				<ReviewPanel
					survey={ survey }
					changes={ changes }
					notes={ notes }
					onDecided={ onDecided }
					navigate={ navigate }
					onOpenNotes={ () => setStep( notesStepIndex ) }
				/>
			) }

			<nav className="pe-steps" aria-label="Sections">
				{ steps.map( ( item, index ) => (
					<button
						key={ item.key }
						type="button"
						className={ `pe-steps__btn ${ index === step ? 'is-active' : '' }` }
						onClick={ () => setStep( index ) }
					>
						{ item.label }
					</button>
				) ) }
			</nav>

			<div className="pe-step">
				{ current.key === 'customer' && (
					<section className="pe-section">
						<h2 className="pe-section__title">{ 'Customer & site' }</h2>
						<div className="pe-grid">
							<label className="pe-field">
								<span className="pe-field__label">{ 'Customer name' }</span>
								<input
									type="text"
									autoComplete="name"
									value={ doc.customer?.name || '' }
									onChange={ ( e ) => setCustomer( 'name', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Email' }</span>
								<input
									type="email"
									inputMode="email"
									autoComplete="email"
									value={ doc.customer?.email || '' }
									onChange={ ( e ) => setCustomer( 'email', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Phone' }</span>
								<input
									type="tel"
									inputMode="tel"
									autoComplete="tel"
									value={ doc.customer?.phone || '' }
									onChange={ ( e ) => setCustomer( 'phone', e.target.value ) }
								/>
							</label>
							<label className="pe-field pe-field--wide">
								<span className="pe-field__label">{ 'Service address' }</span>
								<textarea
									rows={ 2 }
									autoComplete="street-address"
									value={ doc.customer?.address || '' }
									onChange={ ( e ) => setCustomer( 'address', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Inspection date' }</span>
								<input
									type="date"
									value={ doc.inspection?.date || '' }
									onChange={ ( e ) => setInspection( 'date', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Year built' }</span>
								<input
									type="text"
									inputMode="numeric"
									value={ doc.site?.year_built || '' }
									onChange={ ( e ) => setSite( 'year_built', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Service size (A)' }</span>
								<input
									type="text"
									inputMode="numeric"
									value={ doc.site?.service_amps || '' }
									onChange={ ( e ) => setSite( 'service_amps', e.target.value ) }
								/>
							</label>
							<label className="pe-field">
								<span className="pe-field__label">{ 'Meter number' }</span>
								<input
									type="text"
									value={ doc.site?.meter_no || '' }
									onChange={ ( e ) => setSite( 'meter_no', e.target.value ) }
								/>
							</label>
						</div>
					</section>
				) }

				{ current.key === 'panels' && (
					<PanelEditor
						panels={ doc.panels || [] }
						activeIndex={ activePanel }
						sections={ panelSections }
						onSelect={ setActivePanel }
						onAdd={ addPanel }
						onRemove={ removePanel }
						onPanelChange={ ( field, value ) => setPanelField( activePanel, field, value ) }
						onAnswerChange={ ( itemKey, answer ) => setPanelAnswer( activePanel, itemKey, answer ) }
						onReadingChange={ ( key, value ) => setReading( activePanel, key, value ) }
					/>
				) }

				{ current.section && (
					<section className="pe-section">
						<h2 className="pe-section__title">{ current.section.label }</h2>
						{ current.section.polarity === 'hazard' && (
							<p className="pe-section__hint">
								{ 'Mark anything you found on site as “Present”.' }
							</p>
						) }
						{ current.section.items.map( ( item ) => (
							<ItemRow
								key={ item.key }
								item={ item }
								polarity={ current.section.polarity }
								answer={ doc.sections?.[ current.section.key ]?.items?.[ item.key ] }
								onChange={ ( answer ) => setAnswer( current.section.key, item.key, answer ) }
							/>
						) ) }
					</section>
				) }

				{ current.key === 'upgrades' && (
					<section className="pe-section">
						<h2 className="pe-section__title">{ 'Upgrade opportunities' }</h2>
						<p className="pe-section__hint">
							{ 'Things worth quoting even though nothing is wrong today.' }
						</p>
						{ catalog.upgrades.map( ( upgrade ) => {
							const chosen = !! doc.upgrades?.[ upgrade.key ]?.interested;

							return (
								<div key={ upgrade.key } className={ `pe-item ${ chosen ? 'is-chosen' : '' }` }>
									<label className="pe-check">
										<input
											type="checkbox"
											checked={ chosen }
											onChange={ ( e ) =>
												setUpgrade( upgrade.key, { interested: e.target.checked } )
											}
										/>
										<span>{ upgrade.label }</span>
									</label>
									{ chosen && (
										<label className="pe-field">
											<span className="pe-field__label">{ 'Note' }</span>
											<textarea
												rows={ 2 }
												value={ doc.upgrades?.[ upgrade.key ]?.note || '' }
												onChange={ ( e ) =>
													setUpgrade( upgrade.key, { note: e.target.value } )
												}
											/>
										</label>
									) }
								</div>
							);
						} ) }
					</section>
				) }

				{ current.key === 'summary' && (
					<section className="pe-section">
						<h2 className="pe-section__title">{ 'Inspection summary' }</h2>
						<label className="pe-field">
							<span className="pe-field__label">{ 'Overall condition' }</span>
							<select
								value={ doc.summary?.overall || '' }
								onChange={ ( e ) => setSummary( 'overall', e.target.value ) }
							>
								<option value="">{ '—' }</option>
								{ Object.entries( catalog.summary.conditions ).map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>
										{ label }
									</option>
								) ) }
							</select>
						</label>
						<label className="pe-field">
							<span className="pe-field__label">{ 'Recommended timeframe' }</span>
							<select
								value={ doc.summary?.timeframe || '' }
								onChange={ ( e ) => setSummary( 'timeframe', e.target.value ) }
							>
								<option value="">{ '—' }</option>
								{ Object.entries( catalog.summary.timeframes ).map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>
										{ label }
									</option>
								) ) }
							</select>
						</label>
						{ [
							[ 'immediate', 'Immediate concerns' ],
							[ 'maintenance', 'Recommended maintenance' ],
							[ 'upgrades', 'Upgrade notes' ],
						].map( ( [ field, label ] ) => (
							<label key={ field } className="pe-field">
								<span className="pe-field__label">{ label }</span>
								<textarea
									rows={ 3 }
									value={ doc.summary?.[ field ] || '' }
									onChange={ ( e ) => setSummary( field, e.target.value ) }
								/>
							</label>
						) ) }

						{ missing.length > 0 && (
							<div className="pe-banner pe-banner--error" role="alert">
								<p>{ 'Before this can go for review, please add:' }</p>
								<ul>
									{ missing.map( ( key ) => (
										<li key={ key }>{ MISSING_LABELS[ key ] || key }</li>
									) ) }
								</ul>
							</div>
						) }

						{ survey.can.submit && (
							<button
								type="button"
								className="pe-btn pe-btn--primary pe-btn--block"
								onClick={ submit }
								disabled={ submitting }
							>
								{ submitting ? 'Submitting…' : 'Submit for review' }
							</button>
						) }
					</section>
				) }

				{ current.key === 'notes' && (
					<NoteThread surveyId={ survey.id } notes={ notes } onAdded={ onNoteAdded } />
				) }
			</div>

			<div className="pe-nav">
				<button
					type="button"
					className="pe-btn"
					disabled={ step === 0 }
					onClick={ () => setStep( ( s ) => Math.max( 0, s - 1 ) ) }
				>
					{ '← Back' }
				</button>
				<span className="pe-nav__count">
					{ step + 1 } / { steps.length }
				</span>
				<button
					type="button"
					className="pe-btn"
					disabled={ step >= steps.length - 1 }
					onClick={ () => setStep( ( s ) => Math.min( steps.length - 1, s + 1 ) ) }
				>
					{ 'Next →' }
				</button>
			</div>
		</div>
		</PhotoProvider>
	);
}
