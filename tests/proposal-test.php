<?php
/**
 * The proposal: auto-draft, edit, regenerate, token, both signing paths.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/proposal-test.php
 *
 * The parts worth testing here are not the happy path — that is visible on screen — but the places
 * where a mistake is silent and expensive: a token that outlives its expiry, a signed document that
 * can still be edited, a gallery that will serve another customer's photograph over a public link,
 * and an upload pretending to be a PNG.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Proposal\Proposal;
use Paumalu\SiteSurvey\Proposal\ProposalBuilder;
use Paumalu\SiteSurvey\Proposal\Signature;
use Paumalu\SiteSurvey\Rest\Controller;
use Paumalu\SiteSurvey\Setup\Capabilities;

$passed = 0;
$failed = 0;

$check = function ( string $label, $actual, $expected ) use ( &$passed, &$failed ): void {
	if ( wp_json_encode( $actual ) === wp_json_encode( $expected ) ) {
		++$passed;
		printf( "[ ok ] %s\n", $label );
		return;
	}

	++$failed;
	printf(
		"[FAIL] %s\n         expected: %s\n         actual:   %s\n",
		$label,
		wp_json_encode( $expected ),
		wp_json_encode( $actual )
	);
};

$user = function ( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );

	if ( $existing ) {
		return (int) $existing->ID;
	}

	return (int) wp_insert_user(
		[
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		]
	);
};

$call = function ( int $as_user, string $method, string $route, array $params = [] ): array {
	wp_set_current_user( $as_user );

	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	return [ $response->get_status(), $response->get_data() ];
};

/**
 * POST a document as a JSON body.
 *
 * The proposal save endpoint reads get_json_params() rather than individual params, because the
 * proposal *is* the body — a nested document, not a flat set of arguments. So the test has to send
 * it the way the app does, rather than setting params and getting a 400 that no real client would
 * ever see.
 */
$post_json = function ( int $as_user, string $route, array $document ): array {
	wp_set_current_user( $as_user );

	$request = new WP_REST_Request( 'POST', $route );

	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $document ) );

	$response = rest_do_request( $request );

	return [ $response->get_status(), $response->get_data() ];
};

$mail                = [];
$force_mail_failure  = false;

add_filter(
	'pre_wp_mail',
	function ( $short_circuit, array $atts ) use ( &$mail, &$force_mail_failure ) {
		$mail[] = $atts;
		return ! $force_mail_failure;
	},
	10,
	2
);

$drain = function () use ( &$mail ): array {
	$captured = $mail;
	$mail     = [];

	return $captured;
};

$png = function ( int $width = 300, int $height = 120 ): string {
	$image = imagecreatetruecolor( $width, $height );

	imagesavealpha( $image, true );
	imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
	imageline( $image, 10, 90, 280, 30, imagecolorallocate( $image, 18, 69, 95 ) );

	ob_start();
	imagepng( $image );
	$bytes = (string) ob_get_clean();

	imagedestroy( $image );

	return 'data:image/png;base64,' . base64_encode( $bytes );
};

$attach = function ( int $survey_id, string $caption ): int {
	$path  = wp_tempnam( 'pe-proposal' ) . '.jpg';
	$image = imagecreatetruecolor( 240, 180 );

	imagejpeg( $image, $path, 80 );
	imagedestroy( $image );

	$id = (int) wp_insert_attachment(
		[
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $caption,
			'post_excerpt'   => $caption,
			'post_status'    => 'inherit',
			'post_parent'    => $survey_id,
		],
		$path,
		$survey_id
	);

	return $id;
};

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	echo "GD is not available in this PHP build; cannot generate test images.\n";
	exit( 1 );
}

$tech     = $user( 'harness_prop_tech', Capabilities::TECHNICIAN_ROLE );
$reviewer = $user( 'harness_prop_josh', 'editor' );

update_option(
	\Paumalu\SiteSurvey\Admin\SettingsPage::OPTION,
	array_merge(
		\Paumalu\SiteSurvey\Admin\SettingsPage::defaults(),
		[
			'notify_emails'     => 'harness_prop_josh@example.test',
			'token_expiry_days' => '60',
		]
	)
);

$ns = '/' . Controller::API_NAMESPACE;

$document = [
	'customer' => [
		'name'    => 'Malia Kahananui',
		'address' => '59-720 Kamehameha Hwy, Haleiwa',
		'email'   => 'malia@example.test',
	],
	'panels'   => [
		[
			'id'    => 'panel-main',
			'label' => 'Main Panel',
			'items' => [
				'lci_neutral_bar'        => [ 'status' => 'fail', 'severity' => 'immediate' ],
				'lc_enclosure_condition' => [ 'status' => 'pass' ],
			],
		],
	],
];

// ------------------------------------------- 0. a survey nobody has typed into.

// Found on production, not here: a survey with no stored data at all gets its document from
// blank_document(), whose empty maps are (object) [] so they encode as {} rather than []. The
// builder subscripted one of those as an array and fatalled, so opening the proposal screen for a
// freshly created survey was a 500. Cheap to guard, and this is the first thing a reviewer would
// have hit had they clicked through before the technician started filling anything in.
$bare_id = (int) wp_insert_post(
	[
		'post_type'   => 'pe_site_survey',
		'post_title'  => 'Untouched',
		'post_status' => Statuses::ACCEPTED,
		'post_author' => $tech,
	]
);

$bare = ProposalBuilder::draft( $bare_id );

$check( 'a survey with no saved data still drafts', is_array( $bare ), true );
$check( 'with nothing in it', array_sum( array_map( 'count', $bare['groups'] ) ), 0 );
$check( 'and saving that draft does not fatal', is_wp_error( Proposal::save( $bare_id, $bare ) ), false );

wp_delete_post( $bare_id, true );

// -------------------------------------------------------- 1. the auto-draft.

[ , $created ] = $call( $tech, 'POST', $ns . '/surveys' );
$survey_id     = (int) $created['id'];

$call( $tech, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $document ] );
$call( $tech, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$drain();

[ $status, $state ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id . '/proposal' );
$check( 'a reviewer can open the proposal', $status, 200 );
$check( 'opening it drafts but does not persist', $state['saved'] ?? null, false );
$check( 'the draft starts in draft status', $state['proposal']['status'] ?? '', Proposal::DRAFT );

$immediate = $state['proposal']['groups']['immediate'] ?? [];
$check( 'the failed item lands in Immediate Hazards', count( $immediate ), 1 );
$check(
	'and is worded for the customer, not the technician',
	str_contains( strtolower( $immediate[0]['text'] ?? '' ), 'neutral bar' ),
	true
);
$check(
	'with the panel it belongs to named',
	str_contains( $immediate[0]['text'] ?? '', 'Main Panel' ),
	true
);
$check( 'a passing item is not in the proposal at all', count( $state['proposal']['groups']['recommended'] ?? [] ), 0 );

// The technician's own judgment beats the catalog default: they were standing in front of it.
$downgraded = $document;
$downgraded['panels'][0]['items']['lci_neutral_bar'] = [ 'status' => 'fail', 'severity' => 'optional' ];

$call( $tech, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $downgraded ] );

[ , $state ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id . '/proposal' );
$check( 'a severity the technician set overrides the catalog default', count( $state['proposal']['groups']['optional'] ?? [] ), 1 );
$check( 'and it leaves the Immediate bucket', count( $state['proposal']['groups']['immediate'] ?? [] ), 0 );

$call( $tech, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $document ] );

// ----------------------------------------------------------- 2. permissions.

[ $status ] = $call( $tech, 'GET', $ns . '/surveys/' . $survey_id . '/proposal' );
$check( 'the technician who wrote the survey cannot open its proposal', $status, 403 );

[ $status ] = $call( 0, 'GET', $ns . '/surveys/' . $survey_id . '/proposal' );
$check( 'nor can a logged-out stranger', $status, 401 );

// ---------------------------------------------------------- 3. reviewer edits.

[ , $state ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id . '/proposal' );
$edited      = $state['proposal'];

$edited['intro']                       = 'Aloha Malia — here is what we found at the house.';
$edited['groups']['immediate'][0]['text'] = 'The main panel needs its neutral bar corrected.';
$edited['groups']['recommended'][]     = [
	'key'    => '',
	'panel'  => '',
	'source' => 'custom',
	'text'   => 'Label the panel directory so the next person can find the right breaker.',
	'photos' => [],
];
// An emptied box is how somebody on a phone deletes a line before finding the remove button.
$edited['groups']['optional'][] = [ 'source' => 'custom', 'text' => '   ' ];

[ $status, $saved ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $edited );
$check( 'the reviewer can save an edited proposal', $status, 200 );
$check( 'the saved proposal persists', $saved['saved'] ?? null, true );
$check( 'the reworded line is kept', $saved['proposal']['groups']['immediate'][0]['text'], 'The main panel needs its neutral bar corrected.' );
$check( 'a custom line is kept', count( $saved['proposal']['groups']['recommended'] ), 1 );
$check( 'an emptied line is dropped', count( $saved['proposal']['groups']['optional'] ), 0 );

// Status and timestamps are the server's business, never the client's.
$forged                = $saved['proposal'];
$forged['status']      = Proposal::SIGNED;
$forged['signed_at']   = '2020-01-01 00:00:00';
$forged['signature']   = [ 'name' => 'Not The Customer' ];

[ , $saved ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $forged );
$check( 'a client cannot set the proposal status', $saved['proposal']['status'], Proposal::DRAFT );
$check( 'nor forge a signature', $saved['proposal']['signature'] ?? [], [] );

// ------------------------------------------------------ 4. photo ownership.

$mine    = $attach( $survey_id, 'Corroded neutral bar' );
$other   = (int) wp_insert_post(
	[
		'post_type'   => 'pe_site_survey',
		'post_title'  => 'Another customer',
		'post_status' => 'draft',
		'post_author' => $tech,
	]
);
$theirs  = $attach( $other, 'Somebody else’s house' );

$gallery           = $saved['proposal'];
$gallery['photos'] = [
	[ 'id' => $mine, 'caption' => 'The bar as we found it' ],
	[ 'id' => $theirs, 'caption' => 'Should never appear' ],
	[ 'id' => 999999, 'caption' => 'Nor should this' ],
];

[ , $saved ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $gallery );
$check( 'only this survey’s own photo survives the gallery', count( $saved['proposal']['photos'] ), 1 );
$check( 'and it is the right one', $saved['proposal']['photos'][0]['id'], $mine );

// Four is the cap, and it is enforced server-side rather than only in the picker.
$extras            = [ [ 'id' => $mine ] ];
$extras[]          = [ 'id' => $attach( $survey_id, 'Second' ) ];
$extras[]          = [ 'id' => $attach( $survey_id, 'Third' ) ];
$extras[]          = [ 'id' => $attach( $survey_id, 'Fourth' ) ];
$extras[]          = [ 'id' => $attach( $survey_id, 'Fifth' ) ];
$gallery['photos'] = $extras;

[ , $saved ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $gallery );
$check( 'the gallery is capped at four', count( $saved['proposal']['photos'] ), Proposal::MAX_PHOTOS );

$gallery['photos'] = [ [ 'id' => $mine ], [ 'id' => $mine ] ];
[ , $saved ]       = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $gallery );
$check( 'the same photo cannot be added twice', count( $saved['proposal']['photos'] ), 1 );

// ----------------------------------------------------------- 5. regenerate.

// A finding added after the draft was written must arrive without trampling the reworded copy.
$grown = $document;
$grown['panels'][0]['items']['lc_rust_moisture'] = [ 'status' => 'fail', 'severity' => 'recommended' ];

$call( $tech, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $grown ] );

[ $status, $after ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/proposal/regenerate' );
$check( 'regenerating succeeds', $status, 200 );
$check( 'it reports what it added', $after['added_count'] ?? null, 1 );
$check(
	'the reviewer’s wording is not overwritten',
	$after['proposal']['groups']['immediate'][0]['text'],
	'The main panel needs its neutral bar corrected.'
);
$check( 'the custom line survives', count( $after['proposal']['groups']['recommended'] ), 2 );

// A line the reviewer deleted must stay deleted, or Refresh becomes a button nobody dares press.
$pruned  = $after['proposal'];
$deleted = null;

foreach ( $pruned['groups']['recommended'] as $index => $line ) {
	if ( 'lc_rust_moisture' === ( $line['key'] ?? '' ) ) {
		$deleted = $index;
		break;
	}
}

$check( 'the regenerated line is findable by its catalog key', null !== $deleted, true );
array_splice( $pruned['groups']['recommended'], (int) $deleted, 1 );

$post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $pruned );
[ , $after ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/proposal/regenerate' );
$check( 'a deleted line is not resurrected', $after['added_count'] ?? null, 0 );
$check(
	'and it is remembered as dismissed',
	in_array( 'lc_rust_moisture|panel-main', $after['proposal']['dismissed'] ?? [], true ),
	true
);

// Moving a line between buckets must not read as a deletion, or re-prioritising anything would
// quietly stop it ever regenerating again.
$moved = $after['proposal'];
$line  = array_shift( $moved['groups']['immediate'] );
$moved['groups']['optional'][] = $line;

[ , $moved_back ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $moved );
$check(
	're-prioritising a line is not a deletion',
	in_array( ProposalBuilder::line_id( $line ), $moved_back['proposal']['dismissed'] ?? [], true ),
	false
);

// And putting a dismissed line back by hand clears the dismissal.
$restored                              = $moved_back['proposal'];
$restored['groups']['recommended'][]   = [
	'key'    => 'lc_rust_moisture',
	'panel'  => 'panel-main',
	'source' => 'item',
	'text'   => 'Rust and moisture in the main panel enclosure.',
	'photos' => [],
];

[ , $restored_state ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $restored );
$check(
	'restoring a dismissed line clears the dismissal',
	in_array( 'lc_rust_moisture|panel-main', $restored_state['proposal']['dismissed'] ?? [], true ),
	false
);

// The case above deletes from a proposal that has already been saved once. The common case is the
// opposite and needs its own survey: Josh opens the screen, deletes a line out of the generated
// draft he has never saved, and saves for the first time. There is no stored version to diff
// against at that moment, so a dismissal worked out only from the stored copy would find nothing —
// and the line would come back on the next Refresh. Caught by the browser run, not by the unit
// tests, because the unit tests happened to save twice.
[ , $fresh_created ] = $call( $tech, 'POST', $ns . '/surveys' );
$fresh_id            = (int) $fresh_created['id'];

$two_findings = $document;
$two_findings['panels'][0]['items']['lc_rust_moisture'] = [ 'status' => 'fail', 'severity' => 'recommended' ];

$call( $tech, 'PATCH', $ns . '/surveys/' . $fresh_id, [ 'data' => $two_findings ] );
$call( $tech, 'POST', $ns . '/surveys/' . $fresh_id . '/submit' );
$drain();

[ , $first_look ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $fresh_id . '/proposal' );
$check( 'the untouched draft holds both findings', $first_look['saved'] ?? null, false );

$first_edit = $first_look['proposal'];
$first_edit['groups']['recommended'] = [];

[ , $first_save ] = $post_json( $reviewer, $ns . '/surveys/' . $fresh_id . '/proposal', $first_edit );
$check(
	'a line deleted before the first save is still recorded as dismissed',
	in_array( 'lc_rust_moisture|panel-main', $first_save['proposal']['dismissed'] ?? [], true ),
	true
);

[ , $first_refresh ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $fresh_id . '/proposal/regenerate' );
$check( 'and Refresh does not bring it back', $first_refresh['added_count'] ?? null, 0 );
$check(
	'so the bucket he emptied stays empty',
	count( $first_refresh['proposal']['groups']['recommended'] ?? [] ),
	0
);

// ---------------------------------------------------------------- 6. send.

[ $status, $body ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/proposal/send' );
$check( 'a proposal cannot be sent before the survey is accepted', $status, 409 );
$check( 'and says so specifically', $body['code'] ?? '', 'pe_not_accepted' );

$call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/accept' );
$drain();

[ $status, $sent ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/proposal/send' );
$check( 'an accepted survey’s proposal sends', $status, 200 );
$check( 'it goes to the customer’s address', $sent['sent_to'] ?? '', 'malia@example.test' );
$check( 'the proposal is now marked sent', $sent['proposal']['status'] ?? '', Proposal::SENT );
$check( 'a link is live', $sent['link']['active'] ?? null, true );
$check( 'but the token itself is never handed back to the app', isset( $sent['link']['token'] ), false );

$outbound = $drain();
$check( 'one email left the building', count( $outbound ), 1 );
$check( 'addressed to the customer', (array) ( $outbound[0]['to'] ?? [] ), [ 'malia@example.test' ] );

$has_from_header = static function ( array $mail ): bool {
	foreach ( (array) ( $mail['headers'] ?? [] ) as $header ) {
		if ( str_starts_with( (string) $header, 'From:' ) ) {
			return true;
		}
	}

	return false;
};

$check( 'no From header when no from-address is configured', $has_from_header( $outbound[0] ), false );

// A second, throwaway survey just to prove the from-address setting reaches the actual send —
// the primary survey above is needed intact for the token/signature checks that follow it.
update_option(
	\Paumalu\SiteSurvey\Admin\SettingsPage::OPTION,
	array_merge(
		\Paumalu\SiteSurvey\Admin\SettingsPage::defaults(),
		[
			'notify_emails' => 'harness_prop_josh@example.test',
			'from_email'    => 'jobs@paumaluelectric.test',
		]
	)
);

$from_id = (int) wp_insert_post(
	[
		'post_type'   => 'pe_site_survey',
		'post_title'  => 'From-address check',
		'post_status' => Statuses::DRAFT,
		'post_author' => $tech,
	]
);

Paumalu\SiteSurvey\Data\SurveyRepository::save(
	$from_id,
	array_merge(
		$document,
		[ 'customer' => [ 'name' => 'From Test', 'address' => '1 Test Way, Haleiwa', 'email' => 'fromcheck@example.test' ] ]
	)
);

$call( $tech, 'POST', $ns . '/surveys/' . $from_id . '/submit' );
$call( $reviewer, 'POST', $ns . '/surveys/' . $from_id . '/accept' );
Proposal::save( $from_id, ProposalBuilder::draft( $from_id ) );
$drain();

$call( $reviewer, 'POST', $ns . '/surveys/' . $from_id . '/proposal/send' );
$from_outbound = $drain();

$check( 'the from-address email went out', count( $from_outbound ), 1 );
$check(
	'a From header is set when a from-address is configured',
	$has_from_header( $from_outbound[0] ?? [] ),
	true
);

update_option(
	\Paumalu\SiteSurvey\Admin\SettingsPage::OPTION,
	array_merge(
		\Paumalu\SiteSurvey\Admin\SettingsPage::defaults(),
		[
			'notify_emails'     => 'harness_prop_josh@example.test',
			'token_expiry_days' => '60',
		]
	)
);

// --------------------------------------------------------- 6b. email log + resend.

// A third, throwaway survey — resending against $survey_id above would mint it a fresh token and
// break the token/expiry checks below, which depend on the exact token already captured in $token.
$log_id = (int) wp_insert_post(
	[
		'post_type'   => 'pe_site_survey',
		'post_title'  => 'Email log check',
		'post_status' => Statuses::DRAFT,
		'post_author' => $tech,
	]
);

Paumalu\SiteSurvey\Data\SurveyRepository::save(
	$log_id,
	array_merge(
		$document,
		[ 'customer' => [ 'name' => 'Log Test', 'address' => '2 Test Way, Haleiwa', 'email' => 'wrong@example.test' ] ]
	)
);

$call( $tech, 'POST', $ns . '/surveys/' . $log_id . '/submit' );
$call( $reviewer, 'POST', $ns . '/surveys/' . $log_id . '/accept' );
Proposal::save( $log_id, ProposalBuilder::draft( $log_id ) );
$drain();

$force_mail_failure                = true;
[ $fail_status, $fail_body ]       = $call( $reviewer, 'POST', $ns . '/surveys/' . $log_id . '/proposal/send' );
$force_mail_failure                = false;
$drain();

$check( 'a send attempt that fails to hand off is reported to the reviewer', $fail_status, 500 );
$check( 'and says so specifically', $fail_body['code'] ?? '', 'pe_mail_failed' );
$check(
	'the dangling token from the failed attempt is revoked',
	get_post_meta( $log_id, Meta::PROPOSAL_TOKEN, true ),
	''
);

$log_after_failure = Paumalu\SiteSurvey\Proposal\ProposalMailer::log( $log_id );
$check( 'the failed attempt is recorded in the log', count( $log_after_failure ), 1 );
$check( 'addressed to the address on file', $log_after_failure[0]['to'] ?? '', 'wrong@example.test' );
$check( 'and marked as not successful', $log_after_failure[0]['success'] ?? null, false );

// Corrected and resent — the email override is how Josh fixes a mistyped address without having
// to leave this screen to go edit the customer's saved record first.
[ $resend_status, $resend_body ] = $call(
	$reviewer,
	'POST',
	$ns . '/surveys/' . $log_id . '/proposal/send',
	[ 'email' => 'right@example.test' ]
);
$drain();

$check( 'the corrected address sends successfully', $resend_status, 200 );
$check( 'and goes to the corrected address', $resend_body['sent_to'] ?? '', 'right@example.test' );

$log_after_resend = $resend_body['email_log'] ?? [];
$check( 'both attempts are now on the log', count( $log_after_resend ), 2 );
$check( 'most recent attempt first', $log_after_resend[0]['to'] ?? '', 'right@example.test' );
$check( 'and it is marked successful', $log_after_resend[0]['success'] ?? null, true );
$check( 'the earlier failed attempt is still there beneath it', $log_after_resend[1]['success'] ?? null, false );

[ , $log_via_get ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $log_id . '/proposal' );
$check(
	'the log is also visible from a plain GET of the proposal, not just the send response',
	count( $log_via_get['email_log'] ?? [] ),
	2
);

preg_match( '#/proposal/([a-f0-9]{40})/#', (string) ( $outbound[0]['message'] ?? '' ), $found );
$token = $found[1] ?? '';
$check( 'the email carries a 40-character token', 40, strlen( $token ) );

$stored_hash = (string) get_post_meta( $survey_id, Meta::PROPOSAL_TOKEN, true );
$check( 'the database holds a hash, not the token', $stored_hash === $token, false );
$check( 'and the hash is the SHA-256 of it', $stored_hash, hash( 'sha256', $token ) );

// ------------------------------------------------------------- 7. the token.

$check( 'the real token resolves to the survey', Proposal::find_by_token( $token ), $survey_id );
$check( 'a token of the wrong shape resolves to nothing', Proposal::find_by_token( 'nope' ), null );
$check(
	'a well-formed token that was never issued resolves to nothing',
	Proposal::find_by_token( str_repeat( 'a', 40 ) ),
	null
);

// Expiry is a real boundary, not a display hint.
$was_expiring = (int) get_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES, true );
update_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES, time() - 60 );
$check( 'an expired token resolves to nothing', Proposal::find_by_token( $token ), null );
update_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES, $was_expiring );
$check( 'and works again once it is back in date', Proposal::find_by_token( $token ), $survey_id );

// ------------------------------------------------------------ 8. signatures.

$bad = [
	'a JPEG data URL'          => 'data:image/jpeg;base64,' . base64_encode( 'nope' ),
	'a bare string'            => 'i-agree',
	'invalid base64'           => 'data:image/png;base64,!!!!not base64!!!!',
	// A GIF carrying a PNG-ish name is exactly what the magic-byte check is for.
	'a GIF wearing a PNG label' => 'data:image/png;base64,' . base64_encode( "GIF89a\x01\x00\x01\x00\x00\xff\x00," ),
	// Correct magic bytes, nothing behind them: this is the one only getimagesize catches.
	'PNG magic with no image'  => 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( 'A', 64 ) ),
];

foreach ( $bad as $label => $payload ) {
	$result = Signature::record( $survey_id, [ 'name' => 'Malia Kahananui', 'image' => $payload, 'via' => 'link' ] );
	$check( 'a signature refuses ' . $label, is_wp_error( $result ), true );
	$check( '  and it left the proposal unsigned', Proposal::get( $survey_id )['status'], Proposal::SENT );
}

$result = Signature::record( $survey_id, [ 'name' => '   ', 'image' => $png(), 'via' => 'link' ] );
$check( 'a drawn mark with no typed name is refused', is_wp_error( $result ) ? $result->get_error_code() : '', 'pe_name_required' );

// The whole point of the fallback: no canvas, still a signature.
$typed = Signature::record( $survey_id, [ 'name' => 'Malia Kahananui', 'image' => '', 'via' => 'link' ] );
$check( 'a typed name alone is a valid signature', is_wp_error( $typed ), false );
$check( 'recorded as typed rather than drawn', $typed['signature']['method'] ?? '', 'typed' );
$check( 'with no attachment behind it', $typed['signature']['attachment_id'] ?? null, 0 );
$check( 'the IP is recorded as evidence', '' !== ( $typed['signature']['ip'] ?? '' ), true );
$check( 'the proposal is signed', $typed['status'] ?? '', Proposal::SIGNED );

// The link stays live after signing, and that is deliberate. The page posts back to itself, so
// revoking here sent the customer straight to a dead URL — the last thing they saw after approving
// the work was "This link is no longer valid". It is also their only copy of what they agreed to,
// and signing exposes nothing that holding the link did not already expose.
$check(
	'signing leaves the link live, so the customer keeps their receipt',
	Proposal::find_by_token( $token ),
	$survey_id
);

$signed_mail = $drain();
$check( 'signing notifies the company', count( $signed_mail ) >= 1, true );

// ------------------------------------------------- 9. a signature is a record.

$again = Signature::record( $survey_id, [ 'name' => 'Someone Else', 'image' => '', 'via' => 'onsite' ] );
$check( 'a second signature is refused', is_wp_error( $again ) ? $again->get_error_code() : '', 'pe_already_signed' );
$check( 'the first signer is still the signer', Proposal::get( $survey_id )['signature']['name'], 'Malia Kahananui' );

$rewrite          = Proposal::get( $survey_id );
$rewrite['intro'] = 'Quietly changed after the fact.';

$blocked = Proposal::save( $survey_id, $rewrite );
$check( 'a signed proposal cannot be edited', is_wp_error( $blocked ) ? $blocked->get_error_code() : '', 'pe_proposal_signed' );

[ $status, $body ] = $post_json( $reviewer, $ns . '/surveys/' . $survey_id . '/proposal', $rewrite );
$check( 'and the REST route refuses it too', $status, 409 );
$check( 'the stored intro is untouched', Proposal::get( $survey_id )['intro'], 'Aloha Malia — here is what we found at the house.' );

$declined = Signature::decline( $survey_id, 'Changed my mind' );
$check( 'a signed proposal cannot then be declined', is_wp_error( $declined ), true );

// ------------------------------------------------------- 10. on-site signing.

$second = (int) $call( $tech, 'POST', $ns . '/surveys' )[1]['id'];
$call( $tech, 'PATCH', $ns . '/surveys/' . $second, [ 'data' => $document ] );
$call( $tech, 'POST', $ns . '/surveys/' . $second . '/submit' );
$call( $reviewer, 'POST', $ns . '/surveys/' . $second . '/accept' );
$drain();

// Nothing to sign until there is a saved, sent proposal — a signature against a draft is a
// signature on a document nobody has.
[ $status, $body ] = $call( $tech, 'POST', $ns . '/surveys/' . $second . '/proposal/sign', [ 'name' => 'Malia Kahananui' ] );
$check( 'a draft proposal cannot be signed on site', $status, 409 );
$check( 'and says why', $body['code'] ?? '', 'pe_proposal_not_sent' );

[ , $state ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $second . '/proposal' );
$post_json( $reviewer, $ns . '/surveys/' . $second . '/proposal', $state['proposal'] );
$call( $reviewer, 'POST', $ns . '/surveys/' . $second . '/proposal/send' );
$drain();

// The tablet is the technician's, so this is the one proposal route they can reach.
[ $status, $body ] = $call(
	$tech,
	'POST',
	$ns . '/surveys/' . $second . '/proposal/sign',
	[ 'name' => 'Malia Kahananui', 'image' => $png() ]
);
$check( 'the technician can take a signature on site', $status, 200 );
$check( 'it is recorded as drawn', $body['proposal']['signature']['method'] ?? '', 'drawn' );
$check( 'and as having happened on site', $body['proposal']['signature']['via'] ?? '', 'onsite' );

$signature_id = (int) ( $body['proposal']['signature']['attachment_id'] ?? 0 );
$check( 'the drawn mark is stored as an attachment', $signature_id > 0, true );
$check( 'flagged as a signature', (string) get_post_meta( $signature_id, Meta::SIGNATURE_IMAGE, true ) !== '', true );

// The flag exists so the mark cannot end up in a customer-facing photo gallery.
[ , $state ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $second . '/proposal' );
$ids         = array_map( 'intval', array_column( $state['photos'], 'id' ) );
$check( 'a signature never appears in the photo picker', in_array( $signature_id, $ids, true ), false );

// ----------------------------------------------------------------- cleanup.

foreach ( [ $survey_id, $second, $other ] as $id ) {
	wp_delete_post( $id, true );
}

printf( "\n%d passed, %d failed\n", $passed, $failed );
