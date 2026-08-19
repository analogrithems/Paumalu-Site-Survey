/**
 * End-to-end walkthrough of the technician app in a phone viewport.
 *
 * Run with wp-env up:
 *   node tests/e2e.mjs
 *
 * Uses the locally installed Chrome rather than a downloaded Playwright build, so this works
 * without a separate browser install step.
 */

import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.PE_BASE || 'http://localhost:8888';
const USER = process.env.PE_USER || 'fieldtech';
const PASS = process.env.PE_PASS || 'fieldpass123';
const REVIEWER = process.env.PE_REVIEWER || 'josh';
const REVIEWER_PASS = process.env.PE_REVIEWER_PASS || 'joshpass123';
const SHOTS = new URL( './screenshots/', import.meta.url ).pathname;

mkdirSync( SHOTS, { recursive: true } );

let passed = 0;
let failed = 0;

function check( label, actual, expected ) {
	const ok = JSON.stringify( actual ) === JSON.stringify( expected );

	if ( ok ) {
		passed++;
		console.log( `[ ok ] ${ label }` );
	} else {
		failed++;
		console.log( `[FAIL] ${ label }\n         expected: ${ JSON.stringify( expected ) }\n         actual:   ${ JSON.stringify( actual ) }` );
	}
}

const browser = await chromium.launch( { channel: 'chrome' } );
const context = await browser.newContext( {
	...devices[ 'iPhone 13' ],
	// The app is same-origin with wp-login, so cookies just work.
} );
const page = await context.newPage();

const consoleErrors = [];
const pageErrors = [];

page.on( 'console', ( msg ) => {
	if ( msg.type() === 'error' ) {
		consoleErrors.push( msg.text() );
	}
} );
page.on( 'pageerror', ( err ) => pageErrors.push( err.message ) );

// ------------------------------------------------------------------ log in.

await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );

// ---------------------------------------------------------- the survey list.

await page.goto( `${ BASE }/survey/`, { waitUntil: 'networkidle' } );

check( 'app mounts', await page.locator( '.pe-shell' ).count(), 1 );
check( 'the list heading renders', await page.locator( '.pe-list__head h1' ).innerText(), 'My surveys' );

await page.screenshot( { path: `${ SHOTS }01-list.png` } );

// ---------------------------------------------------- create and fill a survey.

await page.click( 'button:has-text("New survey")' );
await page.waitForSelector( '.pe-form', { timeout: 15000 } );

check( 'creating a survey navigates to its own URL', /\/survey\/\d+\/$/.test( page.url() ), true );

const surveyId = parseInt( page.url().match( /\/survey\/(\d+)\// )[ 1 ], 10 );

// Customer & site step.
await page.fill( '.pe-field:has-text("Customer name") input', 'Jane Kealoha' );
await page.fill( '.pe-field:has-text("Service address") textarea', '59-123 Pupukea Rd\nHaleiwa, HI 96712' );

check( 'save indicator reacts to typing', await page.locator( '.pe-save' ).innerText(), 'Saving…' );

// Wait for the debounced autosave to confirm.
await page.waitForSelector( '.pe-save.is-saved', { timeout: 10000 } );
check( 'autosave confirms', ( await page.locator( '.pe-save' ).innerText() ).startsWith( 'Saved' ), true );

await page.screenshot( { path: `${ SHOTS }02-customer.png` } );

// ------------------------------------------------------- answer punch-list items.

await page.click( '.pe-steps__btn:has-text("Service Equipment")' );
await page.waitForSelector( '.pe-item' );

const firstItem = page.locator( '.pe-item' ).first();
await firstItem.locator( '.pe-choice__btn.is-fail' ).click();

check( 'failing an item reveals severity and note', await firstItem.locator( '.pe-item__detail' ).count(), 1 );
check(
	'severity defaults to the catalog value',
	await firstItem.locator( 'select' ).inputValue(),
	'immediate'
);

await firstItem.locator( 'textarea' ).fill( 'Meter enclosure rusted through at the base.' );

// ------------------------------------------------------------------- a photo.

// Drawn in the page rather than shipped as a fixture, so the bytes are a genuine 2400x1600 JPEG —
// big enough that the client-side downscale has something real to do.
const jpegBase64 = await page.evaluate( () => {
	const canvas = document.createElement( 'canvas' );
	canvas.width = 2400;
	canvas.height = 1600;

	const context = canvas.getContext( '2d' );
	context.fillStyle = '#0b3d5c';
	context.fillRect( 0, 0, 2400, 1600 );
	context.fillStyle = '#e8c974';
	context.fillRect( 200, 200, 900, 600 );

	return canvas.toDataURL( 'image/jpeg', 0.92 ).split( ',' )[ 1 ];
} );

await firstItem.locator( 'input[type="file"]' ).setInputFiles( {
	name: 'meter-base.jpg',
	mimeType: 'image/jpeg',
	buffer: Buffer.from( jpegBase64, 'base64' ),
} );

await firstItem.locator( '.pe-photo:not(.is-pending) .pe-photo__thumb' ).waitFor( { timeout: 20000 } );

check( 'the photo settles as a stored thumbnail', await firstItem.locator( '.pe-photo__thumb' ).count(), 1 );

await firstItem.locator( '.pe-photo__caption' ).fill( 'Corroded neutral bar in main panel' );

// The caption commits on blur, not per keystroke — so wait for the PATCH itself rather than for the
// click, or the assertion below races the request it is meant to be checking.
await Promise.all( [
	page.waitForResponse(
		( response ) =>
			/\/photos\/\d+$/.test( response.url() ) && response.request().method() === 'PATCH'
	),
	firstItem.locator( '.pe-item__label' ).click(),
] );

const stored = await page.evaluate( async ( id ) => {
	const response = await fetch(
		`${ window.paumaluSurvey.restRoot }/surveys/${ id }/photos`,
		{ credentials: 'same-origin', headers: { 'X-WP-Nonce': window.paumaluSurvey.nonce } }
	);

	return response.json();
}, surveyId );

check( 'exactly one photo is stored against the survey', stored.length, 1 );
check( 'the caption reached the server', stored[ 0 ].caption, 'Corroded neutral bar in main panel' );

const dimensions = await page.evaluate(
	( url ) =>
		new Promise( ( resolve ) => {
			const image = new Image();
			image.onload = () => resolve( { width: image.naturalWidth, height: image.naturalHeight } );
			image.onerror = () => resolve( { width: 0, height: 0 } );
			image.src = url;
		} ),
	stored[ 0 ].url
);

check(
	'the 2400px original was downscaled to a 1600px long edge before upload',
	Math.max( dimensions.width, dimensions.height ),
	1600
);

check(
	'and its aspect ratio survived',
	Math.round( ( dimensions.width / dimensions.height ) * 100 ),
	150
);

await page.screenshot( { path: `${ SHOTS }03-failed-item.png` } );

// -------------------------------------------------------------- hazard polarity.

await page.click( '.pe-steps__btn:has-text("Safety Hazards")' );
await page.waitForSelector( '.pe-section__hint' );

check(
	'the hazard section inverts its wording',
	await page.locator( '.pe-item' ).first().locator( '.pe-choice__btn' ).first().innerText(),
	'Not present'
);

await page.screenshot( { path: `${ SHOTS }04-hazards.png` } );

// --------------------------------------------------------------- panel repeater.

await page.click( '.pe-steps__btn:has-text("Panels")' );
await page.waitForSelector( '.pe-panels' );

check( 'one panel to start', await page.locator( '.pe-panels__tabs .pe-tab' ).count() - 1, 1 );

await page.click( '.pe-tab--add' );
check( 'a subpanel can be added', await page.locator( '.pe-panels__tabs .pe-tab' ).count() - 1, 2 );

// Readings live on the panel.
const reading = page.locator( '.pe-readings input' ).first();
await reading.fill( '121.4' );
check( 'readings accept decimals', await reading.inputValue(), '121.4' );

await page.screenshot( { path: `${ SHOTS }05-panels.png` } );

// -------------------------------------------------------------------- submit.

await page.click( '.pe-steps__btn:has-text("Summary")' );
await page.waitForSelector( 'button:has-text("Submit for review")' );
await page.screenshot( { path: `${ SHOTS }06-summary.png` } );

await page.click( 'button:has-text("Submit for review")' );
await page.waitForSelector( '.pe-list', { timeout: 15000 } );

check( 'submitting returns to the list', /\/survey\/$/.test( page.url() ), true );
check(
	'the survey now reads as ready for review',
	await page.locator( `.pe-card:has-text("Jane Kealoha") .pe-pill` ).first().innerText(),
	'Ready for Review'
);

await page.screenshot( { path: `${ SHOTS }07-submitted.png` } );

// ------------------------------------------------------ persistence on reload.

await page.goto( `${ BASE }/survey/${ surveyId }/`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.pe-form' );

check(
	'the address survives a reload with its line break intact',
	await page.locator( '.pe-field:has-text("Service address") textarea' ).inputValue(),
	'59-123 Pupukea Rd\nHaleiwa, HI 96712'
);

// ----------------------------------------------------- the photo, after reload.

await page.click( '.pe-steps__btn:has-text("Service Equipment")' );
await page.waitForSelector( '.pe-item' );

const reloadedItem = page.locator( '.pe-item.is-failed' ).first();

check(
	'the photo is still attached to its item after a reload',
	await reloadedItem.locator( '.pe-photo__thumb' ).count(),
	1
);

check(
	'and still carries its caption',
	await reloadedItem.locator( '.pe-photo__caption' ).inputValue(),
	'Corroded neutral bar in main panel'
);

await page.screenshot( { path: `${ SHOTS }08-photo-persisted.png` } );

// Removing it has to clear both the attachment and the id held in the answer.
// The row disappears optimistically, so its absence proves nothing about the server. Wait on the
// DELETE itself.
const [ deleteResponse ] = await Promise.all( [
	page.waitForResponse(
		( response ) =>
			/\/photos\/\d+$/.test( response.url() ) && response.request().method() === 'DELETE'
	),
	reloadedItem.locator( '.pe-photo__remove' ).click(),
] );

check( 'the delete is accepted', deleteResponse.status(), 200 );
await reloadedItem.locator( '.pe-photo' ).waitFor( { state: 'detached', timeout: 15000 } );

const afterDelete = await page.evaluate( async ( { id, photoId } ) => {
	const response = await fetch(
		`${ window.paumaluSurvey.restRoot }/surveys/${ id }`,
		{ credentials: 'same-origin', headers: { 'X-WP-Nonce': window.paumaluSurvey.nonce } }
	);

	const body = await response.json();

	return {
		photos: Object.keys( body.photos || {} ).length,
		referenced: JSON.stringify( body.data.sections ).includes( `"photos":[${ photoId }]` ),
	};
}, { id: surveyId, photoId: stored[ 0 ].id } );

check( 'deleting a photo removes the attachment', afterDelete.photos, 0 );
check( 'and clears the id out of the answer', afterDelete.referenced, false );

// ---------------------------------------------------------- the review round trip.

// A second context rather than logging this one out: the point of the exercise is that two people
// are looking at the same survey, and reusing one session would hide any place where the app leans
// on state left behind by the technician.
const reviewerContext = await browser.newContext( { ...devices[ 'iPhone 13' ] } );
const josh = await reviewerContext.newPage();

josh.on( 'console', ( msg ) => msg.type() === 'error' && consoleErrors.push( msg.text() ) );
josh.on( 'pageerror', ( err ) => pageErrors.push( err.message ) );

await josh.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await josh.fill( '#user_login', REVIEWER );
await josh.fill( '#user_pass', REVIEWER_PASS );
await Promise.all( [
	josh.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	josh.click( '#wp-submit' ),
] );

await josh.goto( `${ BASE }/survey/${ surveyId }/review/`, { waitUntil: 'networkidle' } );
await josh.waitForSelector( '.pe-form' );

check( 'the reviewer is not bounced off the review route', /\/review\/$/.test( josh.url() ), true );
check( 'the review controls render', await josh.locator( '.pe-review__decide' ).count(), 1 );

// The technician deleted a photo after submitting, so the diff must already have something in it.
check( 'the diff banner reports the post-submission edit', await josh.locator( '.pe-changes, .pe-review .pe-banner--warn' ).count() > 0, true );

await josh.click( 'button:has-text("Show changes")' );
await josh.waitForSelector( '.pe-changes__row' );
check( 'the changed item is named, not just counted', ( await josh.locator( '.pe-changes__label' ).first().innerText() ).length > 3, true );

await josh.screenshot( { path: `${ SHOTS }09-review.png` } );

// Requesting changes without a reason must not reach the server at all.
await josh.click( 'button:has-text("Request changes")' );
check( 'sending it back empty-handed is blocked in the UI', await josh.locator( '.pe-review__decide .pe-error' ).count(), 1 );

await josh.fill( '.pe-review__decide textarea', 'Re-take the panel photo — the label is unreadable.' );

const [ changesResponse ] = await Promise.all( [
	josh.waitForResponse( ( r ) => r.url().includes( '/request-changes' ) ),
	josh.click( 'button:has-text("Request changes")' ),
] );

check( 'request changes is accepted', changesResponse.status(), 200 );
await josh.waitForSelector( '.pe-notes__list, .pe-form__head:has-text("Changes Requested")', { timeout: 15000 } );
check( 'the status updates without a reload', await josh.locator( '.pe-form__head .pe-muted' ).innerText(), 'Changes Requested' );

// ------------------------------------------------ the technician's side of it.

await page.goto( `${ BASE }/survey/${ surveyId }/`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.pe-form' );

check(
	'the technician lands on the reason, not on a status pill',
	await page.locator( '.pe-banner--warn:has-text("Changes requested")' ).count(),
	1
);
check(
	'and the reason is the reviewer\'s actual words',
	( await page.locator( '.pe-banner--warn .pe-note__body' ).innerText() ).includes( 'label is unreadable' ),
	true
);

await page.screenshot( { path: `${ SHOTS }10-changes-requested.png` } );

// Answer in the thread, then resubmit.
await page.click( '.pe-banner--warn button:has-text("Open notes")' );
await page.waitForSelector( '.pe-notes' );

check( 'the thread carries the reviewer note', await page.locator( '.pe-note' ).count(), 1 );
check( 'the reviewer is badged as one', await page.locator( '.pe-note__badge' ).count(), 1 );

await page.fill( '.pe-notes__compose textarea', 'Re-shot it — new photo uploaded.' );

const [ noteResponse ] = await Promise.all( [
	page.waitForResponse( ( r ) => r.url().includes( '/notes' ) && r.request().method() === 'POST' ),
	page.click( 'button:has-text("Add note")' ),
] );

check( 'the technician can answer in the thread', noteResponse.status(), 201 );
await page.waitForSelector( '.pe-note.is-mine' );
check( 'the answer renders on the technician\'s side', await page.locator( '.pe-note' ).count(), 2 );
check( 'the compose box empties once the server has it', await page.locator( '.pe-notes__compose textarea' ).inputValue(), '' );

await page.screenshot( { path: `${ SHOTS }11-notes.png` } );

await page.click( '.pe-steps__btn:has-text("Summary")' );
await page.waitForSelector( 'button:has-text("Submit for review")' );
await page.click( 'button:has-text("Submit for review")' );
await page.waitForSelector( '.pe-list', { timeout: 15000 } );

check(
	'a returned survey can be resubmitted',
	await page.locator( `.pe-card:has-text("Jane Kealoha") .pe-pill` ).first().innerText(),
	'Ready for Review'
);

// ------------------------------------------------------------------- accept.

await josh.goto( `${ BASE }/survey/${ surveyId }/review/`, { waitUntil: 'networkidle' } );
await josh.waitForSelector( '.pe-review__decide' );

check( 'resubmission clears the diff banner', await josh.locator( '.pe-changes__row' ).count(), 0 );

const [ acceptResponse ] = await Promise.all( [
	josh.waitForResponse( ( r ) => r.url().includes( '/accept' ) ),
	josh.click( 'button:has-text("Accept survey")' ),
] );

check( 'accept is accepted', acceptResponse.status(), 200 );
await josh.waitForSelector( '.pe-form__head:has-text("Accepted")', { timeout: 15000 } );
check( 'the survey reads as accepted', await josh.locator( '.pe-form__head .pe-muted' ).innerText(), 'Accepted' );
check( 'an accepted survey offers no second accept', await josh.locator( 'button:has-text("Accept survey")' ).count(), 0 );

await josh.screenshot( { path: `${ SHOTS }12-accepted.png` } );

// ------------------------------------------------------------ console health.

// Snapshotted here rather than at the end of the file: the permission probe below deliberately
// provokes two 403s, and the browser logs those to the console no matter how the app handles them.
// Asserting after that point would mean either a permanently failing check or a filter loose enough
// to hide a real one.
check( 'no uncaught page errors', pageErrors, [] );
check( 'no console errors', consoleErrors.filter( ( t ) => ! t.includes( 'favicon' ) ), [] );

// ---------------------------------------------- the boundary, one more time.

const techProbe = await page.evaluate( async ( id ) => {
	const post = async ( path ) => {
		const response = await fetch( `${ window.paumaluSurvey.restRoot }${ path }`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.paumaluSurvey.nonce },
			body: JSON.stringify( { note: 'let me through' } ),
		} );

		return response.status;
	};

	return {
		accept: await post( `/surveys/${ id }/accept` ),
		changes: await post( `/surveys/${ id }/request-changes` ),
	};
}, surveyId );

check( 'a technician calling accept directly is refused', techProbe.accept, 403 );
check( 'and so is request-changes', techProbe.changes, 403 );

await browser.close();

console.log( `\n${ passed } passed, ${ failed } failed` );
console.log( `screenshots in ${ SHOTS }` );

process.exit( failed > 0 ? 1 : 0 );
