/**
 * Client-side image preparation.
 *
 * Every photo is decoded, redrawn onto a canvas at a sane size and re-encoded as JPEG before it ever
 * touches the network. That one step solves four separate problems:
 *
 *   1. An iPhone shooting HEIC produces a file WordPress cannot thumbnail. The canvas hands back
 *      JPEG regardless of what went in.
 *   2. A 4MB original becomes roughly 250KB, which is the difference between a photo uploading in a
 *      garage on one bar and a technician giving up.
 *   3. Canvas re-encoding carries no metadata, so EXIF — including the GPS coordinates of a
 *      customer's home — is stripped without us having to parse anything.
 *   4. Orientation is baked into the pixels, so the image is the right way up everywhere, including
 *      in the printed proposal.
 *
 * Point 4 is the one that bites: EXIF orientation must be applied during the decode, because once
 * pixels are on the canvas the tag is gone and a sideways photo stays sideways forever.
 */

const MAX_EDGE = 1600;
const QUALITY = 0.82;

/**
 * Decode to a bitmap with EXIF orientation already applied.
 *
 * createImageBitmap with imageOrientation is the direct route, but Safari only grew support for the
 * options argument recently and throws on older versions. The <img> fallback gets the same result
 * because browsers have honoured EXIF orientation on regular image elements for years.
 */
async function decode( file ) {
	if ( typeof createImageBitmap === 'function' ) {
		try {
			return await createImageBitmap( file, { imageOrientation: 'from-image' } );
		} catch {
			// Fall through — either the options argument or the format was unsupported.
		}
	}

	const url = URL.createObjectURL( file );

	try {
		const image = new Image();

		await new Promise( ( resolve, reject ) => {
			image.onload = resolve;
			image.onerror = () => reject( new Error( 'decode-failed' ) );
			image.src = url;
		} );

		// decode() resolves once the pixels are actually ready; without it, Safari will happily draw
		// a blank frame from an image that has loaded but not decoded.
		if ( typeof image.decode === 'function' ) {
			await image.decode().catch( () => {} );
		}

		return image;
	} finally {
		URL.revokeObjectURL( url );
	}
}

function scaled( width, height ) {
	const longest = Math.max( width, height );

	if ( longest <= MAX_EDGE ) {
		return { width, height };
	}

	const ratio = MAX_EDGE / longest;

	return {
		width: Math.round( width * ratio ),
		height: Math.round( height * ratio ),
	};
}

function toBlob( canvas ) {
	return new Promise( ( resolve, reject ) => {
		canvas.toBlob(
			( blob ) => ( blob ? resolve( blob ) : reject( new Error( 'encode-failed' ) ) ),
			'image/jpeg',
			QUALITY
		);
	} );
}

function jpegName( original ) {
	const base = ( original || 'photo' ).replace( /\.[^.]+$/, '' ).slice( 0, 60 );

	return `${ base || 'photo' }.jpg`;
}

/**
 * @param {File} file A file straight off an <input type="file">.
 * @return {Promise<{ blob: Blob, filename: string, width: number, height: number }>}
 */
export async function prepareImage( file ) {
	const source = await decode( file );
	const width = source.width || source.naturalWidth;
	const height = source.height || source.naturalHeight;

	if ( ! width || ! height ) {
		throw new Error( 'decode-failed' );
	}

	const size = scaled( width, height );
	const canvas = document.createElement( 'canvas' );

	canvas.width = size.width;
	canvas.height = size.height;

	const context = canvas.getContext( '2d' );

	// A JPEG has no alpha channel, so any transparency in a PNG would otherwise encode as black.
	context.fillStyle = '#fff';
	context.fillRect( 0, 0, size.width, size.height );
	context.drawImage( source, 0, 0, size.width, size.height );

	if ( typeof source.close === 'function' ) {
		source.close();
	}

	const blob = await toBlob( canvas );

	return {
		blob,
		filename: jpegName( file.name ),
		width: size.width,
		height: size.height,
	};
}
