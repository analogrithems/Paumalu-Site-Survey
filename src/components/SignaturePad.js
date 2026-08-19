/**
 * On-site signing, from the technician's tablet with the customer standing there.
 *
 * Deliberately a sibling of assets/signature.js rather than a shared module: that one has to run as
 * a plain script on a page with no build step and no React, this one lives in the bundle. They agree
 * on what they produce — a PNG data URL — and on the rule that a typed name alone is still a
 * signature, which is enforced on the server for both.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

export default function SignaturePad( { onSign, busy } ) {
	const canvasRef = useRef( null );
	const drawing = useRef( false );
	const marked = useRef( false );

	const [ name, setName ] = useState( '' );
	const [ hint, setHint ] = useState( '' );

	// The backing store has to be sized in device pixels or the stroke is a blurry smear on exactly
	// the retina tablet this is meant for. Re-run on resize, which on a tablet means rotation.
	const fit = useCallback( () => {
		const canvas = canvasRef.current;

		if ( ! canvas ) {
			return;
		}

		const ratio = window.devicePixelRatio || 1;
		const width = canvas.getBoundingClientRect().width;

		canvas.width = width * ratio;
		canvas.height = 180 * ratio;

		const ctx = canvas.getContext( '2d' );

		ctx.scale( ratio, ratio );
		ctx.lineWidth = 2.2;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.strokeStyle = '#12455f';
	}, [] );

	useEffect( () => {
		fit();

		window.addEventListener( 'resize', fit );
		return () => window.removeEventListener( 'resize', fit );
	}, [ fit ] );

	const point = ( event ) => {
		const rect = canvasRef.current.getBoundingClientRect();

		return { x: event.clientX - rect.left, y: event.clientY - rect.top };
	};

	const start = ( event ) => {
		// Pointer capture keeps the stroke alive when a finger slides past the edge of the box,
		// which otherwise ends the signature halfway through a surname.
		event.currentTarget.setPointerCapture( event.pointerId );
		drawing.current = true;
		marked.current = true;

		const ctx = canvasRef.current.getContext( '2d' );
		const { x, y } = point( event );

		ctx.beginPath();
		ctx.moveTo( x, y );
	};

	const move = ( event ) => {
		if ( ! drawing.current ) {
			return;
		}

		const ctx = canvasRef.current.getContext( '2d' );
		const { x, y } = point( event );

		ctx.lineTo( x, y );
		ctx.stroke();
	};

	const end = () => {
		drawing.current = false;
	};

	const clear = () => {
		const canvas = canvasRef.current;

		canvas.getContext( '2d' ).clearRect( 0, 0, canvas.width, canvas.height );
		marked.current = false;
	};

	const submit = () => {
		if ( ! name.trim() ) {
			setHint( 'Type the name of the person approving the work.' );
			return;
		}

		setHint( '' );
		onSign( name.trim(), marked.current ? canvasRef.current.toDataURL( 'image/png' ) : '' );
	};

	return (
		<div className="pe-sign">
			<label className="pe-field">
				<span className="pe-field__label">{ 'Full name of the person approving' }</span>
				<input
					type="text"
					value={ name }
					autoComplete="off"
					onChange={ ( event ) => setName( event.target.value ) }
				/>
			</label>

			<p className="pe-field__label">{ 'Signature' }</p>
			{ /* touch-action: none comes from the stylesheet — without it the first stroke scrolls the
			     page instead of drawing. */ }
			<canvas
				ref={ canvasRef }
				className="pe-sign__pad"
				onPointerDown={ start }
				onPointerMove={ move }
				onPointerUp={ end }
				onPointerCancel={ end }
			/>

			<div className="pe-sign__tools">
				<button type="button" className="pe-btn pe-btn--quiet" onClick={ clear }>
					{ 'Clear' }
				</button>
				<button
					type="button"
					className="pe-btn pe-btn--primary"
					onClick={ submit }
					disabled={ busy }
				>
					{ busy ? 'Recording…' : 'Approve this scope of work' }
				</button>
			</div>

			{ hint && <p className="pe-error">{ hint }</p> }

			<p className="pe-sign__terms">
				{
					'Approving records the name above, the date and time, and this device — the same as a signature on paper. It approves the scope of the work described, not a price.'
				}
			</p>
		</div>
	);
}
