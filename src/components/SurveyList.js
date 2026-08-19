import { useCallback, useEffect, useState } from 'react';
import { api, boot } from '../api';

const STATUS_CLASS = {
	draft: 'is-draft',
	pending: 'is-pending',
	pe_changes_req: 'is-changes',
	pe_accepted: 'is-accepted',
};

function FindingDots( { counts } ) {
	const total = ( counts?.immediate || 0 ) + ( counts?.recommended || 0 ) + ( counts?.optional || 0 );

	if ( ! total ) {
		return null;
	}

	return (
		<span className="pe-findings">
			{ !! counts.immediate && (
				<span className="pe-findings__item is-immediate">
					{ counts.immediate } <span className="pe-visually-hidden">{ 'immediate' }</span>
				</span>
			) }
			{ !! counts.recommended && (
				<span className="pe-findings__item is-recommended">
					{ counts.recommended } <span className="pe-visually-hidden">{ 'recommended' }</span>
				</span>
			) }
			{ !! counts.optional && (
				<span className="pe-findings__item is-optional">
					{ counts.optional } <span className="pe-visually-hidden">{ 'optional' }</span>
				</span>
			) }
		</span>
	);
}

export default function SurveyList( { navigate } ) {
	const [ surveys, setSurveys ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ creating, setCreating ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		api
			.listSurveys( { per_page: 50 } )
			.then( ( data ) => ! cancelled && setSurveys( data ) )
			.catch( ( err ) => ! cancelled && setError( err ) );

		return () => {
			cancelled = true;
		};
	}, [] );

	const startSurvey = useCallback( async () => {
		setCreating( true );

		try {
			const survey = await api.createSurvey();
			navigate( { name: 'edit', id: survey.id } );
		} catch ( err ) {
			setError( err );
			setCreating( false );
		}
	}, [ navigate ] );

	return (
		<div className="pe-list">
			<div className="pe-list__head">
				<h1>{ boot.user?.isReviewer ? 'All surveys' : 'My surveys' }</h1>
				<button
					type="button"
					className="pe-btn pe-btn--primary"
					onClick={ startSurvey }
					disabled={ creating }
				>
					{ creating ? 'Starting…' : 'New survey' }
				</button>
			</div>

			{ error && <p className="pe-error">{ error.message }</p> }

			{ ! surveys && ! error && <p className="pe-muted">{ 'Loading…' }</p> }

			{ surveys && surveys.length === 0 && (
				<div className="pe-empty">
					<p>{ 'No surveys yet.' }</p>
					<p className="pe-muted">{ 'Tap “New survey” to start an inspection.' }</p>
				</div>
			) }

			{ surveys && surveys.length > 0 && (
				<ul className="pe-cards">
					{ surveys.map( ( survey ) => (
						<li key={ survey.id }>
							<button
								type="button"
								className="pe-card"
								onClick={ () =>
									navigate( {
										name: survey.can.review && survey.status !== 'draft' ? 'review' : 'edit',
										id: survey.id,
									} )
								}
							>
								<span className="pe-card__title">
									{ survey.customer.name || 'Untitled survey' }
								</span>
								{ !! survey.customer.address && (
									<span className="pe-card__sub">{ survey.customer.address }</span>
								) }
								<span className="pe-card__meta">
									<span className={ `pe-pill ${ STATUS_CLASS[ survey.status ] || '' }` }>
										{ survey.status_label }
									</span>
									{ !! survey.dirty_since_review && (
										<span className="pe-pill is-dirty">{ 'Edited' }</span>
									) }
									<FindingDots counts={ survey.fail_counts } />
								</span>
								{ boot.user?.isReviewer && (
									<span className="pe-card__author">{ survey.author.name }</span>
								) }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
