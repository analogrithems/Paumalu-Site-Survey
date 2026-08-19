import { useEffect, useState } from 'react';
import { api, boot } from '../api';
import { useRouter } from '../routes';
import SurveyList from './SurveyList';
import SurveyForm from './SurveyForm';
import ProposalEditor from './ProposalEditor';

export default function App() {
	const [ route, navigate ] = useRouter();
	const [ catalog, setCatalog ] = useState( null );
	const [ error, setError ] = useState( null );

	// The catalog is the same for every survey in a session, so it is fetched once here rather than
	// on each form mount.
	useEffect( () => {
		let cancelled = false;

		api
			.catalog()
			.then( ( data ) => ! cancelled && setCatalog( data ) )
			.catch( ( err ) => ! cancelled && setError( err ) );

		return () => {
			cancelled = true;
		};
	}, [] );

	if ( error ) {
		return (
			<div className="pe-state pe-state--error">
				<h1>{ 'Could not load the inspection form' }</h1>
				<p>{ error.message }</p>
				<button type="button" onClick={ () => window.location.reload() }>
					{ 'Try again' }
				</button>
			</div>
		);
	}

	if ( ! catalog ) {
		return (
			<div className="pe-state">
				<span className="pe-spinner" aria-hidden="true" />
				<p>{ 'Loading…' }</p>
			</div>
		);
	}

	return (
		<div className="pe-shell">
			<header className="pe-topbar">
				<button
					type="button"
					className="pe-topbar__brand"
					onClick={ () => navigate( { name: 'list', id: 0 } ) }
				>
					{ 'Site Surveys' }
				</button>
				<span className="pe-topbar__user">{ boot.user?.name }</span>
			</header>

			<main className="pe-main">
				{ route.name === 'list' && (
					<SurveyList navigate={ navigate } />
				) }
				{ ( route.name === 'edit' || route.name === 'new' || route.name === 'review' ) && (
					<SurveyForm
						route={ route }
						catalog={ catalog }
						navigate={ navigate }
					/>
				) }
				{ /* Its own screen rather than another step inside the survey: this is a different
				     document with a different audience, written after the survey is finished. */ }
				{ route.name === 'proposal' && (
					<ProposalEditor route={ route } navigate={ navigate } />
				) }
			</main>
		</div>
	);
}
