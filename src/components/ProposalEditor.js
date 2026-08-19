/**
 * Josh's proposal builder.
 *
 * The auto-draft does the transcription; this screen exists for the judgment. Wording gets softened
 * or sharpened, a finding gets moved out of Immediate because he knows the house, an item nobody
 * needs to pay to fix gets deleted. The generated draft is a starting point and the UI is arranged to
 * make editing it the obvious thing to do rather than an afterthought.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../api';
import SignaturePad from './SignaturePad';

const GROUPS = [
	{ key: 'immediate', label: 'Immediate Hazards', hint: 'Safety concerns to correct right away.' },
	{ key: 'recommended', label: 'Recommended Maintenance', hint: 'Should be addressed, not urgent.' },
	{ key: 'optional', label: 'Optional Upgrades', hint: 'Improvements and opportunities.' },
];

const MAX_PHOTOS = 4;

function Line( { line, index, group, total, onChange, onRemove, onMove, onReorder } ) {
	return (
		<li className="pe-pline">
			<textarea
				className="pe-pline__text"
				rows={ 3 }
				value={ line.text }
				onChange={ ( event ) => onChange( group, index, event.target.value ) }
				aria-label="Proposal wording"
			/>
			<div className="pe-pline__tools">
				<select
					className="pe-pline__move"
					value={ group }
					onChange={ ( event ) => onMove( group, index, event.target.value ) }
					aria-label="Priority"
				>
					{ GROUPS.map( ( option ) => (
						<option key={ option.key } value={ option.key }>
							{ option.label }
						</option>
					) ) }
				</select>

				{ /* Up/down buttons rather than drag-and-drop. Josh may well be doing this on a tablet,
				     and drag ordering on touch is fiddly enough that it becomes a reason not to bother
				     reordering at all. */ }
				<button
					type="button"
					className="pe-pline__btn"
					disabled={ index === 0 }
					onClick={ () => onReorder( group, index, -1 ) }
					aria-label="Move up"
				>
					{ '↑' }
				</button>
				<button
					type="button"
					className="pe-pline__btn"
					disabled={ index === total - 1 }
					onClick={ () => onReorder( group, index, 1 ) }
					aria-label="Move down"
				>
					{ '↓' }
				</button>
				<button
					type="button"
					className="pe-pline__btn pe-pline__btn--remove"
					onClick={ () => onRemove( group, index ) }
					aria-label="Remove from proposal"
				>
					{ 'Remove' }
				</button>
			</div>
		</li>
	);
}

export default function ProposalEditor( { route, navigate } ) {
	const surveyId = route.id;

	const [ state, setState ] = useState( null );
	const [ survey, setSurvey ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ saved, setSaved ] = useState( false );
	const [ sentTo, setSentTo ] = useState( '' );

	const dirty = useRef( false );

	// The survey comes along for the customer's name and its status. Both are needed before the
	// first click rather than after it: sending is refused server-side until the survey is accepted,
	// and finding that out from a failed request is a worse way to learn it than not being offered
	// the button.
	useEffect( () => {
		let cancelled = false;

		Promise.all( [ api.getProposal( surveyId ), api.getSurvey( surveyId ) ] )
			.then( ( [ proposalState, loaded ] ) => {
				if ( cancelled ) {
					return;
				}

				setState( proposalState );
				setSurvey( loaded );
			} )
			.catch( ( err ) => ! cancelled && setError( err ) );

		return () => {
			cancelled = true;
		};
	}, [ surveyId ] );

	// Leaving with unsaved wording is a real loss — this is hand-written customer copy, not form
	// fields that can be re-derived — so the browser gets to ask before the tab goes.
	useEffect( () => {
		const warn = ( event ) => {
			if ( ! dirty.current ) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [] );

	const mutate = useCallback( ( updater ) => {
		dirty.current = true;
		setSaved( false );
		setState( ( current ) => {
			const next = structuredClone( current );
			updater( next.proposal );
			return next;
		} );
	}, [] );

	const setText = ( group, index, text ) =>
		mutate( ( proposal ) => {
			proposal.groups[ group ][ index ].text = text;
		} );

	const removeLine = ( group, index ) =>
		mutate( ( proposal ) => {
			proposal.groups[ group ].splice( index, 1 );
		} );

	const moveLine = ( from, index, to ) => {
		if ( from === to ) {
			return;
		}

		mutate( ( proposal ) => {
			const [ line ] = proposal.groups[ from ].splice( index, 1 );
			proposal.groups[ to ].push( line );
		} );
	};

	const reorder = ( group, index, delta ) =>
		mutate( ( proposal ) => {
			const lines = proposal.groups[ group ];
			const target = index + delta;

			if ( target < 0 || target >= lines.length ) {
				return;
			}

			[ lines[ index ], lines[ target ] ] = [ lines[ target ], lines[ index ] ];
		} );

	const addCustom = ( group ) =>
		mutate( ( proposal ) => {
			proposal.groups[ group ].push( {
				key: '',
				panel: '',
				source: 'custom',
				text: '',
				photos: [],
			} );
		} );

	const togglePhoto = ( photo ) =>
		mutate( ( proposal ) => {
			const at = proposal.photos.findIndex( ( item ) => item.id === photo.id );

			if ( at >= 0 ) {
				proposal.photos.splice( at, 1 );
				return;
			}

			if ( proposal.photos.length >= MAX_PHOTOS ) {
				return;
			}

			proposal.photos.push( { id: photo.id, caption: photo.caption || '' } );
		} );

	const setCaption = ( id, caption ) =>
		mutate( ( proposal ) => {
			const photo = proposal.photos.find( ( item ) => item.id === id );

			if ( photo ) {
				photo.caption = caption;
			}
		} );

	const run = async ( action, work ) => {
		setError( null );
		setBusy( action );

		try {
			return await work();
		} catch ( err ) {
			setError( err );
			return null;
		} finally {
			setBusy( '' );
		}
	};

	const save = () =>
		run( 'save', async () => {
			const next = await api.saveProposal( surveyId, state.proposal );

			dirty.current = false;
			setState( next );
			setSaved( true );

			return next;
		} );

	const regenerate = () =>
		run( 'regen', async () => {
			const next = await api.regenerateProposal( surveyId );

			dirty.current = false;
			setState( next );

			return next;
		} );

	const send = async () => {
		// Sending an older version than the one on screen is the kind of mistake that is invisible
		// until the customer replies about something Josh thought he had deleted.
		if ( dirty.current ) {
			const stored = await save();

			if ( ! stored ) {
				return;
			}
		}

		await run( 'send', async () => {
			const result = await api.sendProposal( surveyId );

			setState( result );
			setSentTo( result.sent_to || '' );

			return result;
		} );
	};

	/**
	 * Signing from the technician's tablet, customer present.
	 *
	 * Saves first for the same reason sending does, and more sharply: what gets signed is what is
	 * stored, and a customer approving wording that is still sitting unsaved in a textarea would be
	 * approving a document nobody has.
	 */
	const signHere = async ( name, image ) => {
		if ( dirty.current ) {
			const stored = await save();

			if ( ! stored ) {
				return;
			}
		}

		await run( 'sign', async () => {
			const result = await api.signOnsite( surveyId, name, image );

			setState( result );

			return result;
		} );
	};

	if ( error && ! state ) {
		return (
			<div className="pe-state pe-state--error">
				<h1>{ 'Could not load the proposal' }</h1>
				<p>{ error.message }</p>
			</div>
		);
	}

	if ( ! state || ! survey ) {
		return (
			<div className="pe-state">
				<span className="pe-spinner" aria-hidden="true" />
				<p>{ 'Building the draft…' }</p>
			</div>
		);
	}

	const { proposal, photos, link } = state;
	const isSigned = proposal.status === 'signed';
	const isAccepted = survey.status === 'pe_accepted';
	const count = GROUPS.reduce( ( sum, g ) => sum + ( proposal.groups[ g.key ]?.length || 0 ), 0 );

	return (
		<div className="pe-proposal">
			<div className="pe-proposal__head">
				<button
					type="button"
					className="pe-btn pe-btn--quiet"
					onClick={ () => navigate( { name: 'review', id: surveyId } ) }
				>
					{ '← Back to survey' }
				</button>
				<span className={ `pe-pill pe-pill--${ proposal.status }` }>
					{ proposal.status }
				</span>
			</div>

			<h1 className="pe-proposal__title">{ 'Customer action plan' }</h1>
			<p className="pe-muted">
				{ survey.customer?.name || 'Unnamed customer' }
				{ !! survey.customer?.address && ` — ${ survey.customer.address }` }
			</p>

			{ /* The survey is the source of every line below it, so a proposal for a survey still in
			     review is a draft of a document that may be about to change. Worth saying plainly
			     rather than letting Josh discover it when Refresh rewrites his wording. */ }
			{ ! isAccepted && (
				<div className="pe-banner pe-banner--warn">
					<p>
						<strong>{ 'This survey has not been accepted yet.' }</strong>
						{ ' You can draft the proposal now, but it cannot be sent to the customer until the survey is accepted.' }
					</p>
				</div>
			) }

			{ isSigned && (
				<div className="pe-banner pe-banner--ok" role="status">
					<p>
						<strong>{ `Signed by ${ proposal.signature?.name || 'the customer' }` }</strong>
						{ proposal.signature?.via === 'onsite' ? ' on site.' : ' from the emailed link.' }
					</p>
					{ /* Locked rather than hidden: Josh should still be able to read exactly what was
					     agreed to, he just cannot change it after the fact. */ }
					<p className="pe-banner__note">
						{ 'This proposal is locked because it has been signed.' }
					</p>
				</div>
			) }

			{ error && <p className="pe-error">{ error.message }</p> }

			{ sentTo && (
				<div className="pe-banner pe-banner--ok" role="status">
					<p>{ `Sent to ${ sentTo }.` }</p>
				</div>
			) }

			<fieldset className="pe-proposal__body" disabled={ isSigned }>
				<label className="pe-field">
					<span className="pe-field__label">{ 'Opening note to the customer' }</span>
					<textarea
						rows={ 4 }
						value={ proposal.intro }
						onChange={ ( event ) =>
							mutate( ( draft ) => {
								draft.intro = event.target.value;
							} )
						}
					/>
				</label>

				{ GROUPS.map( ( group ) => {
					const lines = proposal.groups[ group.key ] || [];

					return (
						<section key={ group.key } className={ `pe-pgroup pe-pgroup--${ group.key }` }>
							<h2 className="pe-pgroup__title">
								<span className="pe-dot" aria-hidden="true" />
								{ group.label }
								<span className="pe-pgroup__count">{ lines.length }</span>
							</h2>
							<p className="pe-pgroup__hint">{ group.hint }</p>

							{ lines.length === 0 ? (
								<p className="pe-pgroup__empty">{ 'Nothing in this section.' }</p>
							) : (
								<ul className="pe-plines">
									{ lines.map( ( line, index ) => (
										<Line
											key={ `${ group.key }-${ index }` }
											line={ line }
											index={ index }
											group={ group.key }
											total={ lines.length }
											onChange={ setText }
											onRemove={ removeLine }
											onMove={ moveLine }
											onReorder={ reorder }
										/>
									) ) }
								</ul>
							) }

							<button
								type="button"
								className="pe-btn pe-btn--quiet"
								onClick={ () => addCustom( group.key ) }
							>
								{ '+ Add an item' }
							</button>
						</section>
					);
				} ) }

				<section className="pe-pgroup">
					<h2 className="pe-pgroup__title">
						{ 'Photos' }
						<span className="pe-pgroup__count">
							{ `${ proposal.photos.length } / ${ MAX_PHOTOS }` }
						</span>
					</h2>
					<p className="pe-pgroup__hint">
						{ 'Pick up to four. Photographs of the actual problem do more than any wording.' }
					</p>

					{ photos.length === 0 ? (
						<p className="pe-pgroup__empty">{ 'No photos were attached to this survey.' }</p>
					) : (
						<div className="pe-pshots">
							{ photos.map( ( photo ) => {
								const chosen = proposal.photos.find( ( item ) => item.id === photo.id );

								return (
									<div
										key={ photo.id }
										className={ `pe-pshot${ chosen ? ' is-chosen' : '' }` }
									>
										<button
											type="button"
											className="pe-pshot__pick"
											onClick={ () => togglePhoto( photo ) }
											aria-pressed={ !! chosen }
										>
											<img src={ photo.thumb } alt={ photo.caption || '' } />
										</button>
										{ chosen && (
											<input
												type="text"
												className="pe-pshot__caption"
												value={ chosen.caption }
												placeholder="Caption for the customer"
												onChange={ ( event ) =>
													setCaption( photo.id, event.target.value )
												}
											/>
										) }
									</div>
								);
							} ) }
						</div>
					) }
				</section>
			</fieldset>

			{ ! isSigned && (
				<div className="pe-proposal__actions">
					<button
						type="button"
						className="pe-btn"
						onClick={ regenerate }
						disabled={ !! busy }
						title="Pull in any findings added to the survey since this draft was made"
					>
						{ busy === 'regen' ? 'Refreshing…' : 'Refresh from survey' }
					</button>

					<button
						type="button"
						className="pe-btn"
						onClick={ save }
						disabled={ !! busy || count === 0 }
					>
						{ busy === 'save' ? 'Saving…' : saved ? 'Saved' : 'Save draft' }
					</button>

					<button
						type="button"
						className="pe-btn pe-btn--primary"
						onClick={ send }
						disabled={ !! busy || count === 0 || ! isAccepted }
						title={
							isAccepted ? '' : 'Accept the survey before sending its proposal.'
						}
					>
						{ busy === 'send'
							? 'Sending…'
							: link?.active
								? 'Resend to customer'
								: 'Send to customer' }
					</button>
				</div>
			) }

			{ link?.active && ! isSigned && (
				<p className="pe-proposal__link">
					{ `A live link is out with the customer, valid until ${ new Date(
						link.expires
					).toLocaleDateString() }.` }
				</p>
			) }

			{ /* Only once there is something saved to sign. Offering the pad against an unsaved draft
			     invites a signature on a document that does not exist yet. */ }
			{ state.saved && isAccepted && ! isSigned && (
				<section className="pe-pgroup pe-proposal__onsite">
					<h2 className="pe-pgroup__title">{ 'Sign on site' }</h2>
					<p className="pe-pgroup__hint">
						{
							'If the customer is standing here, hand them the tablet. Anything unsaved is saved first, so what they see above is what they are approving.'
						}
					</p>
					<SignaturePad onSign={ signHere } busy={ busy === 'sign' } />
				</section>
			) }
		</div>
	);
}
