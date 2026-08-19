<?php
/**
 * The review round trip: submit, diff, notes, request changes, resubmit, accept.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/review-test.php
 *
 * Dispatches through rest_do_request() so permission callbacks and the status transition table are
 * exercised for real. Mail is intercepted rather than sent, so the notification wiring is asserted
 * on without wp-env's MailHog being involved.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Rest\Controller;
use Paumalu\SiteSurvey\Review\Notes;
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
 * Capture outbound mail.
 *
 * pre_wp_mail short-circuits before any transport is chosen, so nothing reaches the MTA and the test
 * does not depend on wp-env's MailHog being up.
 */
$mail = [];

add_filter(
	'pre_wp_mail',
	function ( $short_circuit, array $atts ) use ( &$mail ) {
		$mail[] = $atts;
		return true;
	},
	10,
	2
);

$drain = function () use ( &$mail ): array {
	$captured = $mail;
	$mail     = [];

	return $captured;
};

$tech_a   = $user( 'harness_tech_a', Capabilities::TECHNICIAN_ROLE );
$tech_b   = $user( 'harness_tech_b', Capabilities::TECHNICIAN_ROLE );
$reviewer = $user( 'harness_josh', 'editor' );

// Point notifications at a known address rather than whatever the site is configured with.
update_option(
	\Paumalu\SiteSurvey\Admin\SettingsPage::OPTION,
	array_merge(
		\Paumalu\SiteSurvey\Admin\SettingsPage::defaults(),
		[ 'notify_emails' => 'harness_josh@example.test' ]
	)
);

$ns = '/' . Controller::API_NAMESPACE;

$complete_document = [
	'customer' => [
		'name'    => 'Malia Kahananui',
		'address' => '59-720 Kamehameha Hwy, Haleiwa',
	],
	'panels'   => [
		[
			'id'    => 'panel-main',
			'label' => 'Main Panel',
			'items' => [
				'lc_enclosure_condition' => [ 'status' => 'pass' ],
				'lci_neutral_bar'         => [ 'status' => 'fail', 'severity' => 'immediate' ],
			],
		],
	],
];

// ------------------------------------------------------- 1. setup a survey.

[ , $created ] = $call( $tech_a, 'POST', $ns . '/surveys' );
$survey_id     = (int) $created['id'];

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $complete_document ] );

$drain();
[ $status, $body ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$check( 'a complete survey submits', $status, 200 );
$check( 'it lands in Ready for Review', $body['status'] ?? '', Statuses::PENDING );

$sent = $drain();
$check( 'submitting emails the reviewer', count( $sent ), 1 );
$check( 'the submission email goes to the configured address', $sent[0]['to'] ?? [], [ 'harness_josh@example.test' ] );
$check(
	'the submission email links to the review screen',
	str_contains( (string) ( $sent[0]['message'] ?? '' ), '/survey/' . $survey_id . '/review/' ),
	true
);

// ------------------------------------------------- 2. transition gatekeeping.

[ $status ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/accept' );
$check( 'a technician cannot accept their own survey', $status, 403 );

[ $status ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/request-changes', [ 'note' => 'looks fine' ] );
$check( 'a technician cannot request changes', $status, 403 );

[ $status ] = $call( $tech_b, 'POST', $ns . '/surveys/' . $survey_id . '/accept' );
$check( 'another technician is refused before the capability check', $status, 403 );

[ $status ] = $call( $reviewer, 'POST', $ns . '/surveys/9999999/accept' );
$check( 'accepting a survey that does not exist is a 404', $status, 404 );

[ $status, $body ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/request-changes', [ 'note' => '   ' ] );
$check( 'requesting changes without saying why is refused', $status, 400 );
$check( 'the refusal is the note requirement, not the transition', $body['code'] ?? '', 'pe_note_required' );
$check( 'the refused request left the status alone', get_post_status( $survey_id ), Statuses::PENDING );

// -------------------------------------------------------------- 3. the diff.

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'a freshly submitted survey has no changes', $body['changes'] ?? null, [] );

// The author edits under the reviewer, which is the case Aaron chose to allow.
$edited                                              = $complete_document;
$edited['panels'][0]['items']['lci_neutral_bar']      = [ 'status' => 'pass' ];
$edited['panels'][0]['items']['lci_overheating']      = [ 'status' => 'fail', 'severity' => 'immediate' ];
$edited['panels'][0]['label']                        = 'Main Panel (garage)';

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $edited ] );

$check( 'the edit flags the survey as dirty', '' !== (string) get_post_meta( $survey_id, Meta::DIRTY_SINCE_REVIEW, true ), true );

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$changes    = $body['changes'] ?? [];

$check( 'the reviewer sees both changed items', count( $changes ), 2 );

$by_key = array_column( $changes, null, 'key' );

$check( 'a fail flipped to pass is reported with both states', [ $by_key['lci_neutral_bar']['from'], $by_key['lci_neutral_bar']['to'] ], [ 'Fail', 'Pass' ] );
$check( 'a newly answered item reads as previously unanswered', $by_key['lci_overheating']['from'] ?? '', 'Unanswered' );
$check( 'each change carries a human label', '' !== ( $by_key['lci_neutral_bar']['label'] ?? '' ), true );
$check( 'the change is attributed to its panel by name', $by_key['lci_neutral_bar']['panel'] ?? '', 'Main Panel (garage)' );
$check( 'a moved status is marked as one', $by_key['lci_neutral_bar']['status_changed'] ?? null, true );

// An edit that leaves the status alone must describe itself, or the row reads "Fail → Fail".
$reworded                                                  = $edited;
$reworded['panels'][0]['items']['lci_overheating']['note'] = 'Scorching on the A-phase lug.';

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $reworded ] );

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$by_key     = array_column( $body['changes'] ?? [], null, 'key' );

$check( 'a note added without moving the status still reports a change', isset( $by_key['lci_overheating'] ), true );
$check( 'and says what actually changed', in_array( 'Note added', $by_key['lci_overheating']['detail'] ?? [], true ), true );

// Drop a photo id to prove photo-only edits surface too.
$reworded['panels'][0]['items']['lci_overheating']['photos'] = [ 4242 ];
$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $reworded ] );

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$by_key     = array_column( $body['changes'] ?? [], null, 'key' );

$check( 'a photo change is described in words', in_array( '1 photo added', $by_key['lci_overheating']['detail'] ?? [], true ), true );

// Put the document back before the rest of the run depends on it.
$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $edited ] );

[ , $body ] = $call( $tech_a, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'the diff is withheld from a non-reviewer', isset( $body['changes'] ), false );

// --------------------------------------------------------------- 4. notes.

$drain();
[ $status, $note ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/notes', [ 'content' => 'Was the meter base bonded?' ] );
$check( 'a reviewer can add a note', $status, 201 );
$check( 'the note comes back with its text', $note['content'] ?? '', 'Was the meter base bonded?' );
$check( 'the note is attributed to a reviewer', $note['author']['is_reviewer'] ?? null, true );

$sent = $drain();
$check( 'a note emails the technician', $sent[0]['to'] ?? [], [ 'harness_tech_a@example.test' ] );

[ $status, $note ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/notes', [ 'content' => "Yes — bonded at the meter.\nPhoto added." ] );
$check( 'the technician can answer in the same thread', $status, 201 );
$check( 'the answer is not attributed to a reviewer', $note['author']['is_reviewer'] ?? null, false );
$check( 'line breaks survive a note', str_contains( (string) ( $note['content'] ?? '' ), "\n" ), true );

[ $status ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/notes', [ 'content' => '   ' ] );
$check( 'an empty note is refused', $status, 400 );

[ $status, $body ] = $call( $tech_b, 'GET', $ns . '/surveys/' . $survey_id . '/notes' );
$check( "another technician cannot read someone else's thread", $status, 403 );

[ $status, $body ] = $call( $tech_a, 'GET', $ns . '/surveys/' . $survey_id . '/notes' );
$check( 'the thread reads back in order', array_column( $body, 'content' )[0] ?? '', 'Was the meter base bonded?' );
$check( 'the thread has both notes', count( $body ), 2 );

[ , $body ] = $call( $tech_a, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'the survey payload carries the thread', count( $body['notes'] ?? [] ), 2 );
$check( 'the list payload carries a note count', $body['note_count'] ?? 0, 2 );

// Notes must not leak into any other comment query — this is the cost of reusing the comments table.
$check( 'notes are hidden from a default comment query', count( get_comments( [ 'post_id' => $survey_id ] ) ), 0 );
$check( 'notes are still reachable when asked for by type', count( get_comments( [ 'post_id' => $survey_id, 'type' => Notes::TYPE ] ) ), 2 );

// ----------------------------------------------------- 5. request changes.

$drain();
[ $status, $body ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/request-changes', [ 'note' => 'Re-check the neutral bar — the photo shows two conductors.' ] );
$check( 'a reviewer can send it back', $status, 200 );
$check( 'the survey moves to Changes Requested', $body['status'] ?? '', Statuses::CHANGES_REQUEST );
$check( 'the explanation joins the thread', count( $body['notes'] ?? [] ), 3 );
$check( 'the explanation is tagged as a status change', end( $body['notes'] )['event'] ?? '', 'changes_requested' );
$check( 'sending it back clears the dirty flag', (string) get_post_meta( $survey_id, Meta::DIRTY_SINCE_REVIEW, true ), '' );

$sent = $drain();
$check( 'request-changes emails the technician exactly once', count( $sent ), 1 );
$check( 'that email carries the reason', str_contains( (string) ( $sent[0]['message'] ?? '' ), 'Re-check the neutral bar' ), true );

[ $status ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/accept' );
$check( 'a returned survey cannot be accepted without resubmission', $status, 409 );

// ------------------------------------------------------------ 6. resubmit.

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $complete_document ] );

[ $status, $body ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$check( 'the technician can resubmit after fixing it', $status, 200 );
$check( 'it is back in the review queue', $body['status'] ?? '', Statuses::PENDING );

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'resubmission resets the diff against the new snapshot', $body['changes'] ?? null, [] );

// -------------------------------------------------------------- 7. accept.

$drain();
[ $status, $body ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/accept', [ 'note' => 'Good work — building the proposal.' ] );
$check( 'a reviewer can accept', $status, 200 );
$check( 'the survey is Accepted', $body['status'] ?? '', Statuses::ACCEPTED );
$check( 'acceptance stamps a time', '' !== (string) get_post_meta( $survey_id, Meta::ACCEPTED_AT, true ), true );
$check( 'acceptance records who did it', (int) get_post_meta( $survey_id, Meta::REVIEWED_BY, true ), $reviewer );

$sent = $drain();
$check( 'acceptance emails the technician', count( $sent ), 1 );

// The accepted document becomes the new baseline, so drift is measured from what was signed off on.
$after_accept                                         = $complete_document;
$after_accept['panels'][0]['items']['lci_neutral_bar'] = [ 'status' => 'na' ];

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => $after_accept ] );

[ , $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'an edit after acceptance is flagged', '' !== ( $body['dirty_since_review'] ?? '' ), true );
$check( 'and is diffed against the accepted version', count( $body['changes'] ?? [] ), 1 );

[ $status ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/accept' );
$check( 'an accepted survey cannot be accepted twice', $status, 409 );

[ $status, $body ] = $call( $reviewer, 'POST', $ns . '/surveys/' . $survey_id . '/request-changes', [ 'note' => 'Reopening — customer added a subpanel.' ] );
$check( 'an accepted survey can still be reopened', $status, 200 );
$check( 'reopening returns it to Changes Requested', $body['status'] ?? '', Statuses::CHANGES_REQUEST );

// --------------------------------------------------------------- cleanup.

wp_delete_post( $survey_id, true );
$check( 'deleting a survey takes its notes with it', count( get_comments( [ 'post_id' => $survey_id, 'type' => Notes::TYPE ] ) ), 0 );

printf( "\n%d passed, %d failed\n", $passed, $failed );
