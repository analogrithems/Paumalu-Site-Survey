<?php
/**
 * Round-trip checks for SurveyRepository.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/repository-test.php
 *
 * Deliberately not PHPUnit — there is no Composer in this project, and the value here is catching a
 * sanitizer that silently eats a technician's answers, which needs a real database and a real
 * catalog rather than mocks.
 *
 * Note: `wp eval-file` runs this inside a function scope, so counters are held in closure-bound
 * references rather than globals.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Setup\Capabilities;

$passed = 0;
$failed = 0;

$check = function ( string $label, $actual, $expected ) use ( &$passed, &$failed ): void {
	$ok = wp_json_encode( $actual ) === wp_json_encode( $expected );

	if ( $ok ) {
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

// ---------------------------------------------------------------- fixtures.

$tech = get_user_by( 'login', 'harness_tech' );

if ( ! $tech ) {
	$tech = get_user_by(
		'id',
		wp_insert_user(
			[
				'user_login' => 'harness_tech',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'harness_tech@example.test',
				'role'       => Capabilities::TECHNICIAN_ROLE,
			]
		)
	);
}

$post_id = SurveyRepository::create( (int) $tech->ID );

if ( is_wp_error( $post_id ) ) {
	echo 'could not create survey: ' . $post_id->get_error_message() . "\n";
	return;
}

// ------------------------------------------------------------ 1. creation.

$check( 'create() returns a draft', get_post_status( $post_id ), Statuses::DRAFT );
$check( 'create() stamps the catalog version', (int) get_post_meta( $post_id, Meta::SCHEMA_VERSION, true ), Catalog::CURRENT_VERSION );
$check( 'create() seeds one main panel', count( SurveyRepository::get( $post_id )['panels'] ), 1 );

// ------------------------------------------------- 2. a realistic save.

$doc = [
	'customer'   => [
		'name'    => '  Jane Kealoha  ',
		'email'   => 'jane@example.test',
		'phone'   => '808-555-0134',
		'address' => "59-123 Pupukea Rd\nHaleiwa, HI 96712",
	],
	'site'       => [ 'year_built' => '1978', 'service_amps' => '100', 'meter_no' => 'M-4471' ],
	'inspection' => [ 'date' => '2026-08-18' ],
	'sections'   => [
		'service_equipment' => [
			'items' => [
				// Fail with no severity supplied — must inherit the catalog default (immediate).
				'svc_meter_enclosure' => [ 'status' => 'fail', 'note' => 'Rusted through at the base.' ],
			],
		],
		'safety_hazards'    => [
			'items' => [
				'unknown_bogus_key' => [ 'status' => 'fail' ],
			],
		],
		'load_center_interior' => [
			// Panel-scoped item smuggled into a survey section — must be dropped.
			'items' => [ 'lc_enclosure_condition' => [ 'status' => 'fail' ] ],
		],
	],
	'panels'     => [
		[
			'id'       => 'panel-main',
			'label'    => 'Main Panel',
			'location' => 'Garage, north wall',
			'brand'    => 'Federal Pacific',
			'amps'     => '100',
			'items'    => [
				'lc_enclosure_condition' => [ 'status' => 'fail', 'severity' => 'immediate', 'note' => 'Stab-lok.', 'photos' => [ 0, 12, '13', -4 ] ],
				// Survey-scoped item smuggled into a panel — must be dropped.
				'svc_meter_enclosure'    => [ 'status' => 'fail' ],
			],
			'readings' => [
				'l1_n'    => '121.4',
				'l2_n'    => 120.8,
				'l1_l2'   => 9999,      // out of range, dropped.
				'load_l1' => 'abc',     // not numeric, dropped.
				'bogus'   => 5,         // not a defined reading, dropped.
			],
		],
		[
			'id'    => 'sub-a',
			'label' => 'Subpanel A',
			'items' => [
				'lc_enclosure_condition' => [ 'status' => 'pass' ],
			],
		],
	],
	'upgrades'   => [
		'up_surge_protection' => [ 'interested' => true, 'note' => 'Quote it.' ],
		'up_ev_charger'       => [ 'interested' => false ],
		'up_not_a_real_thing' => [ 'interested' => true ],
	],
	'summary'    => [
		'overall'   => 'poor',
		'immediate' => 'Panel replacement.',
		'timeframe' => 'not_a_timeframe',
	],
];

$saved = SurveyRepository::save( $post_id, $doc );

$check( 'text fields are trimmed', $saved['customer']['name'], 'Jane Kealoha' );
$check( 'unknown item key dropped', isset( $saved['sections']['safety_hazards'] ), false );
$check( 'panel-scoped item rejected from a survey section', isset( $saved['sections']['load_center_interior'] ), false );
$check( 'survey-scoped item rejected from a panel', isset( $saved['panels'][0]['items']['svc_meter_enclosure'] ), false );
$check( 'failure inherits catalog default severity', $saved['sections']['service_equipment']['items']['svc_meter_enclosure']['severity'], 'immediate' );
$check( 'photo ids coerced, zero and negative dropped', $saved['panels'][0]['items']['lc_enclosure_condition']['photos'], [ 12, 13 ] );
$check( 'in-range readings kept as floats', [ $saved['panels'][0]['readings']['l1_n'], $saved['panels'][0]['readings']['l2_n'] ], [ 121.4, 120.8 ] );
$check( 'out-of-range reading dropped', isset( $saved['panels'][0]['readings']['l1_l2'] ), false );
$check( 'non-numeric reading dropped', isset( $saved['panels'][0]['readings']['load_l1'] ), false );
$check( 'undefined reading key dropped', isset( $saved['panels'][0]['readings']['bogus'] ), false );
$check( 'both panels survive', array_column( $saved['panels'], 'id' ), [ 'panel-main', 'sub-a' ] );
$check( 'unknown upgrade dropped', array_keys( $saved['upgrades'] ), [ 'up_surge_protection', 'up_ev_charger' ] );
$check( 'valid summary condition kept', $saved['summary']['overall'], 'poor' );
$check( 'invalid timeframe blanked', $saved['summary']['timeframe'], '' );

// -------------------------------------------- 3. the JSON meta round-trip.

// update_post_meta() unslashes, which eats JSON's own escapes unless the value is pre-slashed.
// Left unguarded this silently rewrites a technician's line breaks as the letter "n".
$reloaded = SurveyRepository::get( $post_id );

$check( 'line breaks survive the JSON meta round-trip', $reloaded['customer']['address'], "59-123 Pupukea Rd\nHaleiwa, HI 96712" );
$check( 'reloaded document matches what save() returned', $reloaded['panels'][0]['items']['lc_enclosure_condition']['note'], 'Stab-lok.' );

// ------------------------------------------------------- 4. derived meta.

$check( 'customer name mirrored to meta', get_post_meta( $post_id, Meta::CUSTOMER_NAME, true ), 'Jane Kealoha' );
$check( 'inspection date mirrored to meta', get_post_meta( $post_id, Meta::INSPECTION_DATE, true ), '2026-08-18' );
$check( 'overall condition mirrored to meta', get_post_meta( $post_id, Meta::OVERALL_CONDITION, true ), 'poor' );
$check( 'post retitled from customer + date', get_post_field( 'post_title', $post_id ), 'Jane Kealoha — Aug 18, 2026' );

// Two immediate failures (svc_meter_enclosure, lc_enclosure_condition) and one interested upgrade.
$check(
	'failure counts bucket correctly',
	get_post_meta( $post_id, Meta::FAIL_COUNTS, true ),
	[ 'immediate' => 2, 'recommended' => 0, 'optional' => 1 ]
);

// ---------------------------------------- 5. the dirty flag and its edges.

$check( 'draft edits do not raise the dirty flag', get_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, true ), '' );

// Simulate submission.
SurveyRepository::store_json( $post_id, Meta::REVIEW_SNAPSHOT, SurveyRepository::get( $post_id ) );
update_post_meta( $post_id, Meta::SUBMITTED_AT, current_time( 'mysql' ) );
wp_update_post( [ 'ID' => $post_id, 'post_status' => Statuses::PENDING ] );

// Re-saving identical data must NOT mark the survey dirty. This is the round-trip trap: blank
// objects encode as {} but decode as [], so a naive comparison flags every no-op autosave.
SurveyRepository::save( $post_id, $saved );
$check( 'identical re-save does not raise the dirty flag', get_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, true ), '' );

// A real change must.
$changed = $saved;
$changed['panels'][1]['items']['lc_enclosure_condition'] = [ 'status' => 'fail', 'severity' => 'recommended', 'note' => 'Cover missing.', 'photos' => [] ];
SurveyRepository::save( $post_id, $changed );

$check( 'a real post-submission edit raises the dirty flag', '' !== (string) get_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, true ), true );

$diff = SurveyRepository::diff_since_submission( $post_id );

$check( 'diff reports exactly one changed answer', count( $diff ), 1 );
$check(
	'diff identifies the item, panel and transition',
	array_intersect_key( $diff[0] ?? [], array_flip( [ 'key', 'panel', 'from', 'to' ] ) ),
	[ 'key' => 'lc_enclosure_condition', 'panel' => 'sub-a', 'from' => 'pass', 'to' => 'fail' ]
);
$check( 'diff carries the whole answer, before and after', ( $diff[0]['after']['note'] ?? '' ), 'Cover missing.' );

// An edit that leaves the status alone still has to register. This is the case a status-only diff
// misses entirely, and it is the common one: rewording a note or deleting a photo after submitting.
$note_only                                                  = $changed;
$note_only['panels'][1]['items']['lc_enclosure_condition'] = [ 'status' => 'fail', 'severity' => 'recommended', 'note' => 'Cover missing and hinge bent.', 'photos' => [] ];
SurveyRepository::save( $post_id, $note_only );

$diff = SurveyRepository::diff_since_submission( $post_id );

$check( 'a note-only edit is still reported', count( $diff ), 1 );
$check( 'and is visible as a difference between the two answers', $diff[0]['before']['note'] !== $diff[0]['after']['note'], true );
$check( 'even though the status did not move', [ $diff[0]['from'], $diff[0]['to'] ], [ 'pass', 'fail' ] );

// --------------------------------------------------------------- cleanup.

wp_delete_post( $post_id, true );

printf( "\n%d passed, %d failed\n", $passed, $failed );
