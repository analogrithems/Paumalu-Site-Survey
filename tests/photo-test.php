<?php
/**
 * Photo upload, captioning, permission and cleanup checks.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/photo-test.php
 *
 * Real JPEGs are generated with GD and pushed through the actual REST routes, so mime sniffing,
 * attachment metadata generation and the document pruning on delete are all exercised for real.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\Media\PhotoService;
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

$ns = '/' . Controller::API_NAMESPACE;

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
 * A throwaway JPEG on disk, shaped like a $_FILES entry.
 *
 * Each call writes a fresh file because the sideload path renames rather than copies — reusing one
 * would make the second upload fail for a reason that has nothing to do with what is being tested.
 */
$jpeg = function ( int $width = 640, int $height = 480 ): array {
	$path = wp_tempnam( 'pe-photo' ) . '.jpg';
	$image = imagecreatetruecolor( $width, $height );

	imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocate( $image, 20, 61, 92 ) );
	imagejpeg( $image, $path, 85 );
	imagedestroy( $image );

	return [
		'name'     => 'panel.jpg',
		'type'     => 'image/jpeg',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) filesize( $path ),
	];
};

$upload = function ( int $as_user, int $survey_id, array $file, array $params = [] ) use ( $ns ): array {
	wp_set_current_user( $as_user );

	$request = new WP_REST_Request( 'POST', $ns . '/surveys/' . $survey_id . '/photos' );
	$request->set_file_params( [ 'file' => $file ] );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	return [ $response->get_status(), $response->get_data() ];
};

// ------------------------------------------------------------------- setup.

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	echo "GD is not available in this PHP build; cannot generate test images.\n";
	exit( 1 );
}

$tech_a   = $user( 'pe_test_tech_a', Capabilities::TECHNICIAN_ROLE );
$tech_b   = $user( 'pe_test_tech_b', Capabilities::TECHNICIAN_ROLE );
$reviewer = $user( 'pe_test_reviewer', 'editor' );

$catalog     = Catalog::items( Catalog::CURRENT_VERSION );
$survey_item = null;
$panel_item  = null;

foreach ( $catalog as $key => $item ) {
	if ( 'survey' === $item['scope'] && null === $survey_item ) {
		$survey_item = [ 'key' => $key, 'section' => $item['section'] ];
	}

	if ( 'panel' === $item['scope'] && null === $panel_item ) {
		$panel_item = [ 'key' => $key, 'section' => $item['section'] ];
	}
}

wp_set_current_user( $tech_a );
$survey_id = SurveyRepository::create( $tech_a );

// ------------------------------------------------ 1. a photo on a survey item.

[ $status, $photo ] = $upload(
	$tech_a,
	$survey_id,
	$jpeg( 1200, 900 ),
	[
		'item_key' => $survey_item['key'],
		'caption'  => 'Corroded neutral bar in main panel',
	]
);

$check( 'uploading a photo returns 201', $status, 201 );
$check( 'the photo is parented to the survey', $photo['survey'] ?? 0, $survey_id );
$check( 'the caption round-trips', $photo['caption'] ?? '', 'Corroded neutral bar in main panel' );
$check( 'the photo remembers its item', $photo['item_key'] ?? '', $survey_item['key'] );
$check( 'a survey-scoped photo has no panel', $photo['panel_id'] ?? 'x', '' );
$check( 'a thumbnail was generated', (string) ( $photo['thumb'] ?? '' ) !== (string) ( $photo['url'] ?? '' ), true );

$photo_id = (int) $photo['id'];

$check(
	'the caption doubles as alt text',
	(string) get_post_meta( $photo_id, '_wp_attachment_image_alt', true ),
	'Corroded neutral bar in main panel'
);

$check( 'the photo is not in the proposal gallery by default', $photo['featured'] ?? true, false );

// ------------------------------------------- 2. the survey response carries it.

[ , $body ] = $call( $tech_a, 'GET', $ns . '/surveys/' . $survey_id );

// The map is cast to an object so an empty one serializes as {} rather than [], which is what the
// client spreads into state. In-process the cast survives as a stdClass, hence the unwrap here.
$response_photos = (array) ( $body['photos'] ?? [] );

$check( 'the survey response includes the photo', isset( $response_photos[ $photo_id ] ), true );
$check( 'the response photo carries a usable URL', 0 === strpos( (string) ( $response_photos[ $photo_id ]['url'] ?? '' ), 'http' ), true );

// ------------------------------------------------- 3. a photo on a panel item.

[ $status, $panel_photo ] = $upload(
	$tech_a,
	$survey_id,
	$jpeg( 800, 600 ),
	[
		'item_key' => $panel_item['key'],
		'panel_id' => 'panel-main',
		'caption'  => 'Double-lugged neutral',
	]
);

$check( 'a panel photo uploads', $status, 201 );
$check( 'a panel photo remembers its panel', $panel_photo['panel_id'] ?? '', 'panel-main' );

$panel_photo_id = (int) $panel_photo['id'];

// ------------------------------------------------------- 4. rubbish is refused.

$text = wp_tempnam( 'pe-fake' ) . '.jpg';
file_put_contents( $text, "<?php echo 'not a photo'; ?>\n" );

[ $status, $error ] = $upload(
	$tech_a,
	$survey_id,
	[
		'name'     => 'sneaky.jpg',
		'type'     => 'image/jpeg',
		'tmp_name' => $text,
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) filesize( $text ),
	],
	[ 'item_key' => $survey_item['key'] ]
);

$check( 'a non-image wearing a .jpg suffix is rejected', $status, 400 );
$check( 'and rejected for the right reason', $error['code'] ?? '', 'pe_upload_not_an_image' );

@unlink( $text );

$oversized         = $jpeg( 200, 150 );
$oversized['size'] = PhotoService::MAX_BYTES + 1;

[ $status, $error ] = $upload( $tech_a, $survey_id, $oversized, [ 'item_key' => $survey_item['key'] ] );

$check( 'an oversized file is refused before it is stored', $status, 413 );
$check( 'and refused for the right reason', $error['code'] ?? '', 'pe_upload_too_large' );

@unlink( $oversized['tmp_name'] );

// -------------------------------------------------- 5. the permission boundary.

[ $status ] = $upload( $tech_b, $survey_id, $jpeg( 200, 150 ), [ 'item_key' => $survey_item['key'] ] );
$check( "another technician cannot upload to someone else's survey", $status, 403 );

[ $status ] = $call( $tech_b, 'PATCH', $ns . '/photos/' . $photo_id, [ 'caption' => 'mine now' ] );
$check( "another technician cannot recaption someone else's photo", $status, 403 );

[ $status ] = $call( $tech_b, 'DELETE', $ns . '/photos/' . $photo_id );
$check( "another technician cannot delete someone else's photo", $status, 403 );

[ $status ] = $call( $reviewer, 'PATCH', $ns . '/photos/' . $photo_id, [ 'caption' => 'Corroded neutral bar, main panel' ] );
$check( 'a reviewer can recaption a photo they did not take', $status, 200 );

$check(
	'recaptioning updates the alt text too',
	(string) get_post_meta( $photo_id, '_wp_attachment_image_alt', true ),
	'Corroded neutral bar, main panel'
);

// An attachment hanging off something that is not a survey must not be reachable here at all.
$other_post  = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Unrelated', 'post_status' => 'draft' ] );
$other_photo = wp_insert_post(
	[
		'post_type'   => 'attachment',
		'post_parent' => $other_post,
		'post_status' => 'inherit',
		'post_title'  => 'Unrelated image',
	]
);

[ $status ] = $call( $reviewer, 'DELETE', $ns . '/photos/' . $other_photo );
$check( 'an attachment outside a survey is not found', $status, 404 );

wp_delete_post( $other_photo, true );
wp_delete_post( $other_post, true );

// ---------------------------------------------- 6. the proposal gallery cap.

$gallery = [];

for ( $i = 0; $i < PhotoService::MAX_FEATURED; $i++ ) {
	[ , $extra ] = $upload( $tech_a, $survey_id, $jpeg( 200, 150 ), [ 'item_key' => $panel_item['key'], 'panel_id' => 'panel-2' ] );
	$gallery[]   = (int) $extra['id'];
}

foreach ( array_slice( $gallery, 0, PhotoService::MAX_FEATURED ) as $id ) {
	$call( $reviewer, 'PATCH', $ns . '/photos/' . $id, [ 'featured' => true ] );
}

$check(
	'the gallery fills to its cap',
	count( PhotoService::featured_ids( $survey_id ) ),
	PhotoService::MAX_FEATURED
);

[ $status, $error ] = $call( $reviewer, 'PATCH', $ns . '/photos/' . $photo_id, [ 'featured' => true ] );
$check( 'one photo past the cap is a conflict', $status, 409 );
$check( 'and says so', $error['code'] ?? '', 'pe_featured_limit' );

$call( $reviewer, 'PATCH', $ns . '/photos/' . $gallery[0], [ 'featured' => false ] );
[ $status ] = $call( $reviewer, 'PATCH', $ns . '/photos/' . $photo_id, [ 'featured' => true ] );
$check( 'making room lets the next one in', $status, 200 );

// -------------------------------------------------- 7. the per-item ceiling.

$slot = [];

for ( $i = 0; $i < PhotoService::MAX_PER_ITEM; $i++ ) {
	[ $status, $extra ] = $upload( $tech_a, $survey_id, $jpeg( 160, 120 ), [ 'item_key' => $panel_item['key'], 'panel_id' => 'panel-3' ] );

	if ( 201 === $status ) {
		$slot[] = (int) $extra['id'];
	}
}

$check( 'an item fills to its photo ceiling', count( $slot ), PhotoService::MAX_PER_ITEM );

[ $status, $error ] = $upload( $tech_a, $survey_id, $jpeg( 160, 120 ), [ 'item_key' => $panel_item['key'], 'panel_id' => 'panel-3' ] );
$check( 'one photo past the ceiling is a conflict', $status, 409 );
$check( 'and says so', $error['code'] ?? '', 'pe_photo_limit' );

// -------------------------------- 8. deleting prunes the answer document.

$call(
	$tech_a,
	'PATCH',
	$ns . '/surveys/' . $survey_id,
	[
		'data' => [
			'customer' => [ 'name' => 'Jane Kealoha', 'address' => "59-123 Pupukea Rd\nHaleiwa, HI 96712" ],
			'sections' => [
				$survey_item['section'] => [
					'items' => [
						$survey_item['key'] => [
							'status' => 'fail',
							'note'   => 'Rusted through at the base.',
							'photos' => [ $photo_id ],
						],
					],
				],
			],
			'panels'   => [
				[
					'id'    => 'panel-main',
					'label' => 'Main Panel',
					'items' => [
						$panel_item['key'] => [
							'status' => 'fail',
							'photos' => [ $panel_photo_id ],
						],
					],
				],
			],
		],
	]
);

$doc = SurveyRepository::get( $survey_id );

$check(
	'the answer holds the photo id',
	$doc['sections'][ $survey_item['section'] ]['items'][ $survey_item['key'] ]['photos'] ?? [],
	[ $photo_id ]
);

$check(
	'the panel answer holds its photo id',
	$doc['panels'][0]['items'][ $panel_item['key'] ]['photos'] ?? [],
	[ $panel_photo_id ]
);

[ $status ] = $call( $tech_a, 'DELETE', $ns . '/photos/' . $photo_id );
$check( 'the author can delete their own photo', $status, 200 );
$check( 'the attachment is gone', get_post( $photo_id ), null );

$doc = SurveyRepository::get( $survey_id );

$check(
	'deleting a photo prunes it from the survey-scoped answer',
	$doc['sections'][ $survey_item['section'] ]['items'][ $survey_item['key'] ]['photos'] ?? null,
	[]
);

$check(
	'and leaves the rest of the answer alone',
	$doc['sections'][ $survey_item['section'] ]['items'][ $survey_item['key'] ]['note'] ?? '',
	'Rusted through at the base.'
);

$call( $tech_a, 'DELETE', $ns . '/photos/' . $panel_photo_id );
$doc = SurveyRepository::get( $survey_id );

$check(
	'deleting a panel photo prunes it from the panel answer',
	$doc['panels'][0]['items'][ $panel_item['key'] ]['photos'] ?? null,
	[]
);

$check(
	'the multi-line address survived every photo write',
	$doc['customer']['address'] ?? '',
	"59-123 Pupukea Rd\nHaleiwa, HI 96712"
);

// ------------------------------------- 9. deleting the survey takes them with it.

$remaining = array_keys( PhotoService::for_survey( $survey_id ) );

$check( 'the survey still has its other photos', count( $remaining ) > 0, true );

wp_trash_post( $survey_id );

$check(
	'trashing a survey leaves its photos alone so it can be untrashed',
	count( PhotoService::for_survey( $survey_id ) ),
	count( $remaining )
);

wp_untrash_post( $survey_id );
wp_delete_post( $survey_id, true );

$survivors = array_filter( $remaining, static fn( $id ): bool => null !== get_post( (int) $id ) );

$check( 'deleting a survey for good takes its photos with it', count( $survivors ), 0 );

printf( "\n%d passed, %d failed\n", $passed, $failed );
