<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\Media\PhotoService;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;
use Paumalu\SiteSurvey\Review\Notes;
use Paumalu\SiteSurvey\Review\Workflow;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Survey CRUD and the submit-for-review transition.
 *
 * Ownership is enforced twice on purpose: the list query is scoped to the current author, and every
 * single-survey route re-checks 'edit_site_survey' against the post. A query-level filter alone
 * would leak the moment someone guessed an id.
 */
final class SurveyController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/surveys',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'require_survey_access' ],
					'args'                => [
						'status'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'page'     => [
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
						'per_page' => [
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'require_survey_access' ],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)',
			[
				'args' => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'check_single' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'check_single' ],
					'args'                => [
						'data' => [
							'type'     => 'object',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'check_delete' ],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/submit',
			[
				'args' => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'submit_item' ],
					'permission_callback' => [ $this, 'check_single' ],
				],
			]
		);
	}

	// ------------------------------------------------------------ permissions.

	public function check_single( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->can_edit_survey( (int) $request->get_param( 'id' ) );
	}

	public function check_delete( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->get_survey( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'delete_site_survey', $post_id ) ) {
			return $this->forbidden();
		}

		// A technician can bin their own draft, but not something already in front of a reviewer.
		if ( Statuses::DRAFT !== $post->post_status && ! current_user_can( Capabilities::REVIEW ) ) {
			return $this->forbidden( __( 'A survey that has been submitted can only be deleted by a reviewer.', 'paumalu-site-survey' ) );
		}

		return true;
	}

	// ---------------------------------------------------------------- routes.

	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = min( max( (int) $request->get_param( 'per_page' ), 1 ), 100 );

		$args = [
			'post_type'      => SurveyPostType::POST_TYPE,
			'post_status'    => array_keys( Statuses::labels() ),
			'posts_per_page' => $per_page,
			'paged'          => max( (int) $request->get_param( 'page' ), 1 ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		// Reviewers see the whole queue; everyone else sees only what they wrote.
		if ( ! current_user_can( 'edit_others_site_surveys' ) ) {
			$args['author'] = get_current_user_id();
		}

		$status = (string) $request->get_param( 'status' );

		if ( '' !== $status && Statuses::is_survey_status( $status ) ) {
			$args['post_status'] = $status;
		}

		$query = new \WP_Query( $args );
		$items = array_map( fn( \WP_Post $post ): array => $this->prepare_item( $post, false ), $query->posts );

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = SurveyRepository::create( get_current_user_id() );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$data = $request->get_param( 'data' );

		if ( is_array( $data ) ) {
			SurveyRepository::save( $post_id, $data );
		}

		$post = get_post( $post_id );

		return new \WP_REST_Response( $this->prepare_item( $post ), 201 );
	}

	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return rest_ensure_response( $this->prepare_item( $post ) );
	}

	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = $request->get_param( 'data' );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'pe_invalid_payload',
				__( 'Expected a survey document under "data".', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		SurveyRepository::save( (int) $post->ID, $data );

		return rest_ensure_response( $this->prepare_item( get_post( $post->ID ) ) );
	}

	public function delete_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Trash rather than force-delete: a survey binned by mistake in the field is recoverable,
		// and its photos stay attached.
		$trashed = wp_trash_post( (int) $post->ID );

		if ( ! $trashed ) {
			return new \WP_Error(
				'pe_delete_failed',
				__( 'That survey could not be deleted.', 'paumalu-site-survey' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [ 'deleted' => true, 'id' => (int) $post->ID ] );
	}

	/**
	 * Move a survey to Ready for Review and snapshot it for the reviewer.
	 */
	public function submit_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$post_id = (int) $post->ID;

		if ( ! Statuses::can_transition( $post->post_status, Statuses::PENDING, $post_id ) ) {
			return new \WP_Error(
				'pe_invalid_transition',
				__( 'This survey cannot be submitted from its current state.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		$missing = $this->incomplete_fields( $post_id );

		if ( [] !== $missing ) {
			return new \WP_Error(
				'pe_survey_incomplete',
				__( 'This survey is missing information needed for review.', 'paumalu-site-survey' ),
				[ 'status' => 400, 'missing' => $missing ]
			);
		}

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => Statuses::PENDING,
			]
		);

		// Freeze what the reviewer is being handed. Aaron chose to keep surveys editable by their
		// author after submission, so this snapshot is what the diff banner compares against.
		SurveyRepository::store_json( $post_id, Meta::REVIEW_SNAPSHOT, SurveyRepository::get( $post_id ) );
		update_post_meta( $post_id, Meta::SUBMITTED_AT, current_time( 'mysql' ) );
		delete_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW );

		/**
		 * Fires once a survey has been submitted for review.
		 *
		 * @param int $post_id Survey id.
		 */
		do_action( 'pe_survey_submitted', $post_id );

		return rest_ensure_response( $this->prepare_item( get_post( $post_id ) ) );
	}

	// --------------------------------------------------------------- helpers.

	/**
	 * The minimum a survey needs before it is worth a reviewer's time.
	 *
	 * Kept deliberately thin — a punch list with legitimately unanswered items is normal, and
	 * blocking submission on completeness would strand technicians in the field.
	 *
	 * @return list<string>
	 */
	private function incomplete_fields( int $post_id ): array {
		$doc     = SurveyRepository::get( $post_id );
		$missing = [];

		if ( '' === trim( (string) ( $doc['customer']['name'] ?? '' ) ) ) {
			$missing[] = 'customer.name';
		}

		if ( '' === trim( (string) ( $doc['customer']['address'] ?? '' ) ) ) {
			$missing[] = 'customer.address';
		}

		$answered = 0;

		foreach ( SurveyRepository::iterate_answers( $doc ) as [ $answer ] ) {
			if ( '' !== (string) ( $answer['status'] ?? '' ) ) {
				++$answered;
			}
		}

		if ( 0 === $answered ) {
			$missing[] = 'answers';
		}

		return $missing;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function prepare_item( \WP_Post $post, bool $with_data = true ): array {
		$post_id = (int) $post->ID;
		$author  = get_userdata( (int) $post->post_author );
		$counts  = get_post_meta( $post_id, Meta::FAIL_COUNTS, true );

		$item = [
			'id'           => $post_id,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'status_label' => Statuses::labels()[ $post->post_status ] ?? $post->post_status,
			'author'       => [
				'id'   => (int) $post->post_author,
				'name' => $author ? $author->display_name : '',
			],
			'customer'     => [
				'name'    => (string) get_post_meta( $post_id, Meta::CUSTOMER_NAME, true ),
				'address' => (string) get_post_meta( $post_id, Meta::SERVICE_ADDRESS, true ),
			],
			'inspection_date' => (string) get_post_meta( $post_id, Meta::INSPECTION_DATE, true ),
			'fail_counts'  => is_array( $counts ) ? $counts : [ 'immediate' => 0, 'recommended' => 0, 'optional' => 0 ],
			'catalog_version' => SurveyRepository::catalog_version( $post_id ),
			'submitted_at' => (string) get_post_meta( $post_id, Meta::SUBMITTED_AT, true ),
			'dirty_since_review' => (string) get_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, true ),
			'modified'     => get_post_modified_time( 'c', true, $post ),
			'created'      => get_post_time( 'c', true, $post ),
			'note_count'   => Notes::count( $post_id ),
			'can'          => [
				'edit'   => current_user_can( 'edit_site_survey', $post_id ),
				'delete' => current_user_can( 'delete_site_survey', $post_id ),
				'review' => current_user_can( Capabilities::REVIEW ),
				'submit' => Statuses::can_transition( $post->post_status, Statuses::PENDING, $post_id ),
				'accept' => Statuses::can_transition( $post->post_status, Statuses::ACCEPTED, $post_id ),
				'request_changes' => Statuses::can_transition( $post->post_status, Statuses::CHANGES_REQUEST, $post_id ),
			],
		];

		if ( $with_data ) {
			$item['data'] = SurveyRepository::get( $post_id );

			// Answers store attachment ids only. Sending the resolved photos alongside means the app
			// never has to fetch them separately, and an id whose attachment has since been deleted
			// simply finds nothing here rather than rendering a broken image.
			$item['photos'] = (object) PhotoService::for_survey( $post_id );

			// The thread belongs to both sides, so it ships to both. The diff does not: it only means
			// anything against a submission snapshot, and computing it walks the whole document.
			$item['notes'] = Notes::for_survey( $post_id );

			if ( current_user_can( Capabilities::REVIEW ) ) {
				$item['changes'] = Workflow::describe_changes( $post_id );
			}
		}

		return $item;
	}
}
