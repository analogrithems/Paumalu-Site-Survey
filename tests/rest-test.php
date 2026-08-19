<?php
/**
 * Endpoint and permission-boundary checks for the paumalu/v1 REST API.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/rest-test.php
 *
 * Dispatches through rest_do_request() rather than over HTTP, so routes, permission callbacks and
 * capability mapping are all exercised for real without needing nonces or a live cookie jar.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\PostType\Statuses;
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

/**
 * Dispatch a request as a given user and hand back [ status, data ].
 */
$call = function ( int $as_user, string $method, string $route, array $params = [] ): array {
	wp_set_current_user( $as_user );

	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	return [ $response->get_status(), $response->get_data() ];
};

$tech_a   = $user( 'harness_tech_a', Capabilities::TECHNICIAN_ROLE );
$tech_b   = $user( 'harness_tech_b', Capabilities::TECHNICIAN_ROLE );
$reviewer = $user( 'harness_josh', 'editor' );

$ns = '/' . Controller::API_NAMESPACE;

// ------------------------------------------------------------- 1. catalog.

[ $status, $body ] = $call( 0, 'GET', $ns . '/catalog' );
$check( 'catalog rejects anonymous callers', $status, 401 );

[ $status, $body ] = $call( $tech_a, 'GET', $ns . '/catalog' );
$check( 'catalog readable by a technician', $status, 200 );
$check( 'catalog ships 11 sections', count( $body['sections'] ), 11 );
$check( 'catalog ships 9 upgrades', count( $body['upgrades'] ), 9 );

$item_total = array_sum( array_map( fn( array $s ): int => count( $s['items'] ), $body['sections'] ) );
$check( 'catalog ships all 71 punch-list items', $item_total, 71 );

// -------------------------------------------------------------- 2. create.

[ $status, $survey ] = $call( $tech_a, 'POST', $ns . '/surveys' );
$check( 'technician can create a survey', $status, 201 );

$survey_id = (int) ( $survey['id'] ?? 0 );

$check( 'new survey starts as a draft', $survey['status'] ?? '', Statuses::DRAFT );
$check( 'new survey is owned by its creator', $survey['author']['id'] ?? 0, $tech_a );

// ------------------------------------------- 3. the ownership boundary.

[ $status ] = $call( $tech_a, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'author can read their own survey', $status, 200 );

[ $status, $body ] = $call( $tech_b, 'GET', $ns . '/surveys/' . $survey_id );
$check( "another technician is refused another's survey", $status, 403 );

[ $status ] = $call( $tech_b, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => [ 'customer' => [ 'name' => 'Hijacked' ] ] ] );
$check( 'another technician cannot patch it either', $status, 403 );

[ $status ] = $call( $tech_b, 'DELETE', $ns . '/surveys/' . $survey_id );
$check( 'another technician cannot delete it', $status, 403 );

[ $status, $list ] = $call( $tech_b, 'GET', $ns . '/surveys' );
$check( "the list is scoped to the caller's own surveys", in_array( $survey_id, array_column( $list, 'id' ), true ), false );

[ $status, $list ] = $call( $tech_a, 'GET', $ns . '/surveys' );
$check( 'the author sees their own survey in the list', in_array( $survey_id, array_column( $list, 'id' ), true ), true );

[ $status, $list ] = $call( $reviewer, 'GET', $ns . '/surveys' );
$check( "a reviewer sees other people's surveys", in_array( $survey_id, array_column( $list, 'id' ), true ), true );

$check( 'list responses omit the full document', isset( $list[0]['data'] ), false );

// ------------------------------------------------- 4. a bogus target id.

$plain_post = wp_insert_post( [ 'post_title' => 'not a survey', 'post_type' => 'post', 'post_status' => 'draft' ] );

[ $status ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $plain_post );
$check( 'a non-survey post id is a 404, not a leak', $status, 404 );

wp_delete_post( (int) $plain_post, true );

// --------------------------------------------------------------- 5. patch.

[ $status, $body ] = $call(
	$tech_a,
	'PATCH',
	$ns . '/surveys/' . $survey_id,
	[
		'data' => [
			'customer' => [ 'name' => 'Jane Kealoha', 'address' => "59-123 Pupukea Rd\nHaleiwa, HI 96712" ],
			'panels'   => [
				[
					'id'    => 'panel-main',
					'label' => 'Main Panel',
					'items' => [ 'lc_enclosure_condition' => [ 'status' => 'fail', 'severity' => 'immediate', 'note' => 'Stab-lok panel.' ] ],
				],
			],
		],
	]
);

$check( 'author can patch their survey', $status, 200 );
$check( 'patched answers come back in the response', $body['data']['panels'][0]['items']['lc_enclosure_condition']['status'] ?? '', 'fail' );
$check( 'patch derives the failure counts', $body['fail_counts']['immediate'] ?? -1, 1 );
$check( 'line breaks survive the REST round-trip', $body['data']['customer']['address'] ?? '', "59-123 Pupukea Rd\nHaleiwa, HI 96712" );

[ $status ] = $call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => 'not-an-object' ] );
$check( 'a malformed patch body is rejected', $status, 400 );

// -------------------------------------------------------------- 6. submit.

// Strip the address back out to prove the completeness gate bites.
$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => [ 'customer' => [ 'name' => 'Jane Kealoha' ] ] ] );

[ $status, $body ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$check( 'submitting an incomplete survey is refused', $status, 400 );
$check( 'the refusal names the missing fields', $body['data']['missing'] ?? [], [ 'customer.address', 'answers' ] );

// Restore a complete document.
$call(
	$tech_a,
	'PATCH',
	$ns . '/surveys/' . $survey_id,
	[
		'data' => [
			'customer' => [ 'name' => 'Jane Kealoha', 'address' => '59-123 Pupukea Rd' ],
			'panels'   => [
				[
					'id'    => 'panel-main',
					'label' => 'Main Panel',
					'items' => [ 'lc_enclosure_condition' => [ 'status' => 'fail', 'severity' => 'immediate' ] ],
				],
			],
		],
	]
);

[ $status, $body ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$check( 'a complete survey submits', $status, 200 );
$check( 'submission moves it to Ready for Review', $body['status'] ?? '', Statuses::PENDING );
$check( 'submission stamps submitted_at', '' !== (string) get_post_meta( $survey_id, Meta::SUBMITTED_AT, true ), true );
$check( 'submission snapshots the document', '' !== (string) get_post_meta( $survey_id, Meta::REVIEW_SNAPSHOT, true ), true );

[ $status ] = $call( $tech_a, 'POST', $ns . '/surveys/' . $survey_id . '/submit' );
$check( 'resubmitting from pending is a conflict', $status, 409 );

// ------------------------------------- 7. post-submission edit and delete.

[ $status ] = $call( $tech_a, 'DELETE', $ns . '/surveys/' . $survey_id );
$check( 'author cannot delete a survey already under review', $status, 403 );

$call( $tech_a, 'PATCH', $ns . '/surveys/' . $survey_id, [ 'data' => [ 'customer' => [ 'name' => 'Jane K.', 'address' => '59-123 Pupukea Rd' ] ] ] );
$check( 'author can still edit after submitting', '' !== (string) get_post_meta( $survey_id, Meta::DIRTY_SINCE_REVIEW, true ), true );

[ $status, $body ] = $call( $reviewer, 'GET', $ns . '/surveys/' . $survey_id );
$check( 'the reviewer is told the survey moved under them', '' !== ( $body['dirty_since_review'] ?? '' ), true );

// --------------------------------------------------------------- cleanup.

wp_delete_post( $survey_id, true );

printf( "\n%d passed, %d failed\n", $passed, $failed );
