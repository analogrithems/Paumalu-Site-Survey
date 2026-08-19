/**
 * Signature pad for the customer proposal page.
 *
 * No dependencies and no build step: this file is served as-is to a homeowner's phone, and pulling a
 * library in for what amounts to "draw a line between two points" would cost more bytes than the rest
 * of the page put together.
 *
 * Progressive enhancement is the reason the pad starts hidden in the markup rather than being hidden
 * here. If this script fails to load — bad signal in a valley on the North Shore is not hypothetical
 * — the customer still gets a working form with a typed name, and the signature is simply absent.
 * A pad that renders but cannot capture would be worse than no pad.
 */
( function () {
	'use strict';

	var wrap = document.getElementById( 'pe-pad-wrap' );
	var canvas = document.getElementById( 'pe-pad' );
	var field = document.getElementById( 'pe-signature-data' );

	if ( ! wrap || ! canvas || ! field || ! canvas.getContext ) {
		return;
	}

	var form = canvas.closest( 'form' );
	var clear = document.getElementById( 'pe-pad-clear' );
	var ctx = canvas.getContext( '2d' );
	var drawing = false;
	var dirty = false;
	var last = null;

	wrap.hidden = false;

	/**
	 * Match the backing store to the element's real pixel size.
	 *
	 * Without this the canvas renders at its attribute size and is stretched by CSS, which on a phone
	 * at 3x turns a signature into a blurred smear. Re-run on resize because rotating the phone
	 * changes the element width.
	 */
	function fit() {
		var ratio = window.devicePixelRatio || 1;
		var rect = canvas.getBoundingClientRect();

		if ( ! rect.width ) {
			return;
		}

		// Resizing a canvas clears it, so anything already drawn has to be carried across.
		var previous = dirty ? canvas.toDataURL( 'image/png' ) : null;

		canvas.width = Math.round( rect.width * ratio );
		canvas.height = Math.round( rect.height * ratio );

		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.lineWidth = 2;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.strokeStyle = '#12455f';

		if ( previous ) {
			var image = new Image();
			image.onload = function () {
				ctx.drawImage( image, 0, 0, rect.width, rect.height );
			};
			image.src = previous;
		}
	}

	function point( event ) {
		var rect = canvas.getBoundingClientRect();

		return {
			x: event.clientX - rect.left,
			y: event.clientY - rect.top,
		};
	}

	function start( event ) {
		drawing = true;
		last = point( event );

		// Capture so a stroke that wanders off the canvas still ends cleanly on pointerup.
		if ( canvas.setPointerCapture && event.pointerId !== undefined ) {
			canvas.setPointerCapture( event.pointerId );
		}
	}

	function move( event ) {
		if ( ! drawing ) {
			return;
		}

		// Touch-action:none in the stylesheet stops the page scrolling under the finger; this stops
		// the browser treating the drag as a text selection on desktop.
		event.preventDefault();

		var next = point( event );

		ctx.beginPath();
		ctx.moveTo( last.x, last.y );
		ctx.lineTo( next.x, next.y );
		ctx.stroke();

		last = next;
		dirty = true;
	}

	function end() {
		drawing = false;
		last = null;
	}

	canvas.addEventListener( 'pointerdown', start );
	canvas.addEventListener( 'pointermove', move );
	canvas.addEventListener( 'pointerup', end );
	canvas.addEventListener( 'pointercancel', end );
	canvas.addEventListener( 'pointerleave', end );

	if ( clear ) {
		clear.addEventListener( 'click', function () {
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			dirty = false;
			field.value = '';
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! dirty ) {
				event.preventDefault();

				// Deliberately not a blocking alert(): the customer is on a phone and an alert is a
				// modal they have to dismiss before they can see what it was about.
				wrap.classList.add( 'is-missing' );
				canvas.scrollIntoView( { behavior: 'smooth', block: 'center' } );

				return;
			}

			wrap.classList.remove( 'is-missing' );
			field.value = canvas.toDataURL( 'image/png' );
		} );
	}

	window.addEventListener( 'resize', fit );
	fit();
} )();
