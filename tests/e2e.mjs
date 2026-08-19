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
const CUSTOMER_EMAIL = 'jane.kealoha@example.test';
const SHOTS = new URL( './screenshots/', import.meta.url ).pathname;

/**
 * Read the mail the site tried to send.
 *
 * Backed by tests/mu/pe-mail-log.php, which .wp-env.json mounts as an mu-plugin. Needed because the
 * proposal token is shown once and never returned to any browser — the emailed link is the only
 * place the customer's URL exists, so without reading the mail this suite could not follow the path
 * most customers take.
 */
async function mailbox() {
	const response = await fetch( `${ BASE }/wp-json/paumalu-test/v1/mail` );

	if ( ! response.ok ) {
		throw new Error(
			`mail log unavailable (${ response.status }) — is tests/mu mounted? try: npx wp-env start`
		);
	}

	return response.json();
}

const clearMail = () => fetch( `${ BASE }/wp-json/paumalu-test/v1/mail`, { method: 'DELETE' } );

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
// The proposal cannot be emailed without this, so it belongs in the happy path rather than being
// patched in at the point of sending.
await page.fill( '.pe-form input[type="email"]', CUSTOMER_EMAIL );
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

// Then take a better one. This is what actually happens on site — the first shot is blurred or the
// flash blew out the panel — and it leaves the survey with a photo for the proposal gallery to use,
// which is the part of the customer document that does the most work.
await reloadedItem.locator( 'input[type="file"]' ).setInputFiles( {
	name: 'meter-base-retake.jpg',
	mimeType: 'image/jpeg',
	buffer: Buffer.from( jpegBase64, 'base64' ),
} );

await reloadedItem.locator( '.pe-photo:not(.is-pending) .pe-photo__thumb' ).waitFor( { timeout: 20000 } );
await reloadedItem.locator( '.pe-photo__caption' ).fill( 'Corrosion at the base of the meter enclosure' );

await Promise.all( [
	page.waitForResponse(
		( response ) =>
			/\/photos\/\d+$/.test( response.url() ) && response.request().method() === 'PATCH'
	),
	reloadedItem.locator( '.pe-item__label' ).click(),
] );

check( 'the retake is stored', await reloadedItem.locator( '.pe-photo__thumb' ).count(), 1 );

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

// ------------------------------------------------------------ build the proposal.

await josh.click( 'button:has-text("Build the proposal")' );
await josh.waitForSelector( '.pe-proposal', { timeout: 15000 } );

check( 'accepting offers a route to the proposal', /\/survey\/\d+\/proposal\/$/.test( josh.url() ), true );
check(
	'an accepted survey draws no "not accepted yet" warning',
	await josh.locator( '.pe-banner--warn' ).count(),
	0
);
check(
	'the customer is named on the proposal',
	( await josh.locator( '.pe-proposal .pe-muted' ).first().innerText() ).includes( 'Jane Kealoha' ),
	true
);

const immediate = josh.locator( '.pe-pgroup--immediate .pe-pline' );

check( 'the failed items auto-draft into Immediate Hazards', await immediate.count() > 0, true );

// Kept so the regenerate assertion below can prove this exact wording does not come back.
const dropped = await immediate.first().locator( '.pe-pline__text' ).inputValue();

check( 'auto-drafted wording is customer-facing, not the punch-list label', dropped.length > 20, true );

await josh.fill(
	'.pe-proposal .pe-field:has-text("Opening note") textarea',
	'Aloha Jane — here is what we found at the house, in the order we would tackle it.'
);

await josh.screenshot( { path: `${ SHOTS }13-proposal-draft.png` } );

// --------------------------------------------- a deleted line stays deleted.

// This is the regression guard for the bug where Refresh quietly undid the reviewer's editorial
// decisions: he removes a line, pulls in a later finding, and the removed line is back in the
// document he then sends to the customer.
//
// Josh writes his own line first. Partly because that is what he actually does — the auto-draft is a
// starting point — and partly because a proposal with nothing in it cannot be saved, which is
// correct behaviour but would leave nothing to refresh against.
const CUSTOM_LINE = 'Tidy the low-voltage runs in the garage while we are on site.';

await josh.click( '.pe-pgroup--recommended button:has-text("+ Add an item")' );
await josh.fill( '.pe-pgroup--recommended .pe-pline__text >> nth=-1', CUSTOM_LINE );

const beforeRemove = await immediate.count();

await immediate.first().locator( 'button:has-text("Remove")' ).click();

check( 'removing a line drops it from the group', await immediate.count(), beforeRemove - 1 );

await josh.click( 'button:has-text("Save draft")' );
await josh.waitForSelector( 'button:has-text("Saved")', { timeout: 15000 } );

const [ regenResponse ] = await Promise.all( [
	josh.waitForResponse( ( r ) => r.url().includes( '/proposal/regenerate' ) ),
	josh.click( 'button:has-text("Refresh from survey")' ),
] );

check( 'refreshing succeeds', regenResponse.status(), 200 );
await josh.waitForSelector( 'button:has-text("Refresh from survey"):not([disabled])', { timeout: 15000 } );

// .value, not textContent: React drives these textareas through the value property, so the element's
// text content is empty and allTextContents() would report every line as blank — and a test that
// compares blanks passes for the wrong reason.
const afterRefresh = await josh.$$eval( '.pe-pline__text', ( nodes ) => nodes.map( ( n ) => n.value ) );

check( 'refreshing does not resurrect a line the reviewer deleted', afterRefresh.includes( dropped ), false );
check( 'refreshing keeps the reviewer\'s own wording', afterRefresh.includes( CUSTOM_LINE ), true );
check( 'refreshing does not duplicate what is already there', await immediate.count(), beforeRemove - 1 );

// ------------------------------------------------------------------- photos.

check( 'the survey photo is offered to the gallery', await josh.locator( '.pe-pshot__pick' ).count(), 1 );

// The draft picks the photos attached to failed items, so the gallery arrives populated rather than
// as an empty box Josh has to think about.
check( 'the draft has already chosen it', await josh.locator( '.pe-pshot.is-chosen' ).count(), 1 );
check(
	'the technician’s caption comes along rather than starting blank',
	await josh.locator( '.pe-pshot__caption' ).inputValue(),
	'Corrosion at the base of the meter enclosure'
);

// Off and on again, because the picker is a toggle and half a toggle is not a tested toggle.
await josh.locator( '.pe-pshot__pick' ).first().click();

check( 'unpicking a photo drops it from the gallery', await josh.locator( '.pe-pshot.is-chosen' ).count(), 0 );
check( 'and takes its caption box with it', await josh.locator( '.pe-pshot__caption' ).count(), 0 );

await josh.locator( '.pe-pshot__pick' ).first().click();

check( 'picking it again restores it', await josh.locator( '.pe-pshot.is-chosen' ).count(), 1 );

await josh.fill( '.pe-pshot__caption', 'Corrosion where the meter enclosure meets the wall.' );

await josh.click( 'button:has-text("Save draft")' );
await josh.waitForSelector( 'button:has-text("Saved")', { timeout: 15000 } );

check(
	'a saved proposal offers the on-site pad',
	await josh.locator( '.pe-proposal__onsite .pe-sign' ).count(),
	1
);

await josh.screenshot( { path: `${ SHOTS }14-proposal-saved.png` } );

// -------------------------------------------------------- send to the customer.

await clearMail();

const [ sendResponse ] = await Promise.all( [
	josh.waitForResponse( ( r ) => r.url().includes( '/proposal/send' ) ),
	josh.click( 'button:has-text("Send to customer")' ),
] );

check( 'sending the proposal succeeds', sendResponse.status(), 200 );
await josh.waitForSelector( '.pe-banner--ok:has-text("Sent to")', { timeout: 15000 } );
check(
	'the reviewer is told where it went',
	await josh.locator( '.pe-banner--ok:has-text("Sent to")' ).innerText(),
	`Sent to ${ CUSTOMER_EMAIL }.`
);

const mail = await mailbox();
const toCustomer = mail.filter( ( item ) => item.to.includes( CUSTOMER_EMAIL ) );

check( 'exactly one email goes to the customer', toCustomer.length, 1 );

const tokenUrl = ( toCustomer[ 0 ]?.message || '' ).match(
	/https?:\/\/[^"'\s<]+\/proposal\/[a-f0-9]{40}\/?/
)?.[ 0 ];

check( 'the email carries a 40-character token link', !! tokenUrl, true );

// The reviewer's own screen must not be able to recover the token after minting it — the link is a
// bearer credential, and a screen that can reproduce it is a second place it can leak from.
check(
	'the token is not echoed back into the reviewer UI',
	/\/proposal\/[a-f0-9]{40}/.test( await josh.content() ),
	false
);

// --------------------------------------------- the customer opens the link.

// A fresh context with no cookies: this is a homeowner on their own phone, not a logged-in staff
// member, and the page has to work with no session at all.
const customerContext = await browser.newContext( { ...devices[ 'iPhone 13' ] } );
const customer = await customerContext.newPage();

const landed = await customer.goto( tokenUrl, { waitUntil: 'domcontentloaded' } );

check( 'the emailed link resolves', landed.status(), 200 );
check(
	'and is never cached, since the URL is the secret',
	/no-store/.test( landed.headers()[ 'cache-control' ] || '' ),
	true
);

const customerBody = await customer.locator( 'body' ).innerText();

check( 'the customer sees the opening note', customerBody.includes( 'Aloha Jane' ), true );
check( 'the customer does not see the deleted line', customerBody.includes( dropped ), false );
check( 'the plan is grouped by priority', await customer.locator( '.pp-group' ).count() > 0, true );
check(
	'the page asks search engines to stay away',
	await customer.locator( 'meta[name="robots"][content*="noindex"]' ).count(),
	1
);
check( 'there is no price anywhere on the page', /\$\s?\d/.test( customerBody ), false );

await customer.screenshot( { path: `${ SHOTS }15-customer-proposal.png`, fullPage: true } );

// --------------------------------------------------- the customer signs it.

// The pad is hidden until the script runs, so its being visible is also the check that progressive
// enhancement worked rather than the customer being left with a form they cannot complete.
await customer.waitForSelector( '#pe-pad-wrap:not([hidden])', { timeout: 10000 } );

await customer.fill( '.pp-form input[name="pe_name"]', 'Jane Kealoha' );

// A typed name is a valid signature on its own — see includes/Proposal/Signature.php — so an
// untouched pad must not block submission. Checked without actually completing the real sign
// (the intercepted submit event is prevented after signature.js's own listener has already run),
// so the drawn-pad happy path below still has an unsigned document to sign.
const typedOnlyValue = await customer.evaluate( () => new Promise( ( resolve ) => {
	const form = document.querySelector( 'input[name="pe_action"][value="sign"]' ).closest( 'form' );

	form.addEventListener(
		'submit',
		( event ) => {
			event.preventDefault();
			resolve( document.getElementById( 'pe-signature-data' ).value );
		},
		{ once: true }
	);

	form.requestSubmit();
} ) );

check(
	'an untouched pad leaves the signature field empty rather than blocking the typed name',
	typedOnlyValue,
	''
);

// The pad sits below the fold on a phone, and mouse coordinates are viewport-relative — without
// this the strokes land on whatever happens to be at those coordinates instead.
await customer.locator( '#pe-pad' ).scrollIntoViewIfNeeded();

const pad = await customer.locator( '#pe-pad' ).boundingBox();

await customer.mouse.move( pad.x + 30, pad.y + pad.height / 2 );
await customer.mouse.down();
await customer.mouse.move( pad.x + 90, pad.y + 30, { steps: 8 } );
await customer.mouse.move( pad.x + 160, pad.y + pad.height - 30, { steps: 8 } );
await customer.mouse.move( pad.x + 230, pad.y + 40, { steps: 8 } );
await customer.mouse.up();

await Promise.all( [
	customer.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	customer.click( '.pp-form button[type="submit"]' ),
] );

const signedBody = await customer.locator( 'body' ).innerText();

check(
	'signing is not rejected',
	await customer.locator( '.pp-flash--bad' ).count() === 0 ||
		( await customer.locator( '.pp-flash--bad' ).innerText() ),
	true
);

check( 'signing is confirmed by name', signedBody.includes( 'Jane Kealoha' ), true );
check( 'the signing form is gone afterwards', await customer.locator( '.pp-form' ).count(), 0 );
check( 'the drawn mark is stored and shown back', await customer.locator( '.pp-signed__mark' ).count(), 1 );

await customer.screenshot( { path: `${ SHOTS }16-signed.png`, fullPage: true } );

// A second visit must show the same signed document rather than offering to sign again.
await customer.goto( tokenUrl, { waitUntil: 'domcontentloaded' } );
await customer.waitForSelector( '.pp-signed' );

check( 'revisiting a signed proposal cannot re-sign it', await customer.locator( '.pp-form' ).count(), 0 );

await customerContext.close();

// ------------------------------------------- and it is locked for the reviewer.

await josh.reload( { waitUntil: 'networkidle' } );
await josh.waitForSelector( '.pe-proposal', { timeout: 15000 } );

check( 'the reviewer sees it was signed', await josh.locator( '.pe-banner--ok:has-text("Signed by")' ).count(), 1 );
check( 'a signed proposal cannot be sent again', await josh.locator( 'button:has-text("customer")' ).count(), 0 );
check( 'a signed proposal cannot be edited', await josh.locator( '.pe-proposal__body[disabled]' ).count(), 1 );
check( 'and the on-site pad is withdrawn', await josh.locator( '.pe-proposal__onsite' ).count(), 0 );

await josh.screenshot( { path: `${ SHOTS }17-proposal-locked.png` } );

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
