/**
 * Technician app entry point.
 */

import { createRoot } from 'react-dom/client';
import App from './components/App';
import './style.scss';

const container = document.getElementById( 'pe-survey-app' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
