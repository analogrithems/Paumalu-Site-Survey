<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Review\Notes;
use Paumalu\SiteSurvey\Review\Workflow;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reviewer's endpoints, plus the note thread both sides share.
 *
 * Notes are readable and writable by anyone who can edit the survey — which includes its author, and
 * is the whole point: a reviewer asking "was the meter base bonded?" is worthless if the technician
 * cannot answer. The two status transitions are gated on the review capability on top of that.
 */
final class ReviewController extends Controller {

	public function register_routes(): void {
		$id_arg = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/notes',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_notes' ],
					'permission_callback' => [ $this, 'check_single' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_note' ],
					'permission_callback' => [ $this, 'check_single' ],
					'args'                => [
						'content' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/request-changes',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'request_changes' ],
					'permission_callback' => [ $this, 'check_reviewer' ],
					'args'                => [
						'note' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/accept',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'accept' ],
					'permission_callback' => [ $this, 'check_reviewer' ],
					'args'                => [
						'note' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);
	}

	// ------------------------------------------------------------ permissions.

	public function check_single( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->can_edit_survey( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Both walls, in this order.
	 *
	 * The edit check runs first so a stranger probing ids gets the same 404 they would get anywhere
	 * else in the API, rather than a 403 that confirms the survey exists.
	 */
	public function check_reviewer( \WP_REST_Request $request ): bool|\WP_Error {
		$allowed = $this->can_edit_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			return $this->forbidden( __( 'Only a reviewer can approve or return a survey.', 'paumalu-site-survey' ) );
		}

		return true;
	}

	// ---------------------------------------------------------------- routes.

	public function get_notes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return rest_ensure_response( Notes::for_survey( (int) $post->ID ) );
	}

	public function add_note( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$note_id = Notes::add( (int) $post->ID, (string) $request->get_param( 'content' ) );

		if ( is_wp_error( $note_id ) ) {
			return $note_id;
		}

		$comment = get_comment( $note_id );

		return new \WP_REST_Response( Notes::prepare( $comment ), 201 );
	}

	public function request_changes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Workflow::request_changes( (int) $post->ID, (string) $request->get_param( 'note' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->review_state( (int) $post->ID ) );
	}

	public function accept( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Workflow::accept( (int) $post->ID, (string) $request->get_param( 'note' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->review_state( (int) $post->ID ) );
	}

	// --------------------------------------------------------------- helpers.

	/**
	 * @return array<string, mixed>
	 */
	private function review_state( int $post_id ): array {
		$post = get_post( $post_id );

		return [
			'id'     => $post_id,
			'status' => $post->post_status,
			'status_label' => Statuses::labels()[ $post->post_status ] ?? $post->post_status,
			'notes'  => Notes::for_survey( $post_id ),
			'changes' => Workflow::describe_changes( $post_id ),
		];
	}
}
