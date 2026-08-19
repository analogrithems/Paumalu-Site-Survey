<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\Media\PhotoService;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Proposal\Proposal;
use Paumalu\SiteSurvey\Proposal\ProposalBuilder;
use Paumalu\SiteSurvey\Proposal\ProposalMailer;
use Paumalu\SiteSurvey\Proposal\Signature;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposal endpoints.
 *
 * Everything here is reviewer-only except the on-site signing route, which needs to work from the
 * technician's tablet with the customer standing there — so it is gated on being able to edit the
 * survey rather than on the review capability.
 */
final class ProposalController extends Controller {

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
			'/surveys/(?P<id>\d+)/proposal',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_proposal' ],
					'permission_callback' => [ $this, 'check_reviewer' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_proposal' ],
					'permission_callback' => [ $this, 'check_reviewer' ],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/proposal/regenerate',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'regenerate' ],
					'permission_callback' => [ $this, 'check_reviewer' ],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/proposal/send',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send' ],
					'permission_callback' => [ $this, 'check_sender' ],
					'args'                => [
						'email' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		// On-site signing. The tablet is already authenticated as the technician, so this needs no
		// token — but it goes through exactly the same Signature::record() as the public route, so
		// the two paths cannot drift apart in what they validate or what they record.
		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/proposal/sign',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'sign_onsite' ],
					'permission_callback' => [ $this, 'check_single' ],
					'args'                => [
						'name'  => [
							'type'     => 'string',
							'required' => true,
						],
						// Optional for the same reason it is optional on the public page: the typed
						// name with intent is what makes this a signature, and requiring the canvas
						// here would leave the two signing paths disagreeing about what counts as one.
						'image' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		// On-site signing. The tablet is already authenticated as the technician, so this needs no
		// token — but it goes through exactly the same Signature::record() as the public route, so
		// the two paths cannot drift apart in what they validate or what they record.
		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/proposal/sign',
			[
				'args' => $id_arg,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'sign_onsite' ],
					'permission_callback' => [ $this, 'check_single' ],
					'args'                => [
						'name'  => [
							'type'     => 'string',
							'required' => true,
						],
						// Optional for the same reason it is optional on the public page: the
							// typed name with intent is what makes this a signature, and requiring the
							// canvas here would leave the two signing paths disagreeing about what
							// counts as one.
							'image' => [
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

	public function check_reviewer( \WP_REST_Request $request ): bool|\WP_Error {
		$allowed = $this->can_edit_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			return $this->forbidden( __( 'Only a reviewer can build a proposal.', 'paumalu-site-survey' ) );
		}

		return true;
	}

	/**
	 * Sending is a separate capability from reviewing.
	 *
	 * They travel together today — both land on editor — but emailing a customer is the one action
	 * here that reaches outside the company, and it is worth being able to withhold it from somebody
	 * who is otherwise trusted to review.
	 */
	public function check_sender( \WP_REST_Request $request ): bool|\WP_Error {
		$allowed = $this->check_reviewer( $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( Capabilities::SEND_PROPOSAL ) ) {
			return $this->forbidden( __( 'You are not allowed to send proposals to customers.', 'paumalu-site-survey' ) );
		}

		return true;
	}

	// ---------------------------------------------------------------- routes.

	/**
	 * Fetch the proposal, drafting one on first read.
	 *
	 * Generating on read rather than making Josh press "create" means the screen he lands on already
	 * has the findings in it. The draft is not persisted until he saves, so opening the screen out of
	 * curiosity does not commit anything.
	 */
	public function get_proposal( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$survey_id = (int) $post->ID;

		$proposal = Proposal::exists( $survey_id )
			? Proposal::get( $survey_id )
			: ProposalBuilder::draft( $survey_id );

		return rest_ensure_response( $this->state( $survey_id, $proposal ) );
	}

	public function save_proposal( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'pe_invalid_body',
				__( 'The proposal could not be read.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$saved = Proposal::save( (int) $post->ID, $body );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( $this->state( (int) $post->ID, $saved ) );
	}

	public function regenerate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$survey_id = (int) $post->ID;
		$merged    = ProposalBuilder::regenerate( $survey_id, Proposal::get( $survey_id ) );
		$saved     = Proposal::save( $survey_id, $merged );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// added_count does not survive sanitize(), which is deliberate — it describes what just
		// happened rather than being part of the document — so it is put back on the response only.
		$state                  = $this->state( $survey_id, $saved );
		$state['added_count']   = (int) ( $merged['added_count'] ?? 0 );

		return rest_ensure_response( $state );
	}

	/**
	 * Mint a link and email it to the customer.
	 */
	public function send( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$survey_id = (int) $post->ID;

		if ( Statuses::ACCEPTED !== $post->post_status ) {
			return new \WP_Error(
				'pe_not_accepted',
				__( 'Accept the survey before sending its proposal to the customer.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! Proposal::exists( $survey_id ) ) {
			return new \WP_Error(
				'pe_no_proposal',
				__( 'Build and save the proposal before sending it.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		$sent = ProposalMailer::send( $survey_id, (string) $request->get_param( 'email' ) );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return rest_ensure_response( $this->state( $survey_id, Proposal::get( $survey_id ) ) + $sent );
	}

	public function sign_onsite( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$signed = Signature::record(
			(int) $post->ID,
			[
				'name'  => (string) $request->get_param( 'name' ),
				'image' => (string) $request->get_param( 'image' ),
				'via'   => 'onsite',
			]
		);

		if ( is_wp_error( $signed ) ) {
			return $signed;
		}

		return rest_ensure_response( $this->state( (int) $post->ID, $signed ) );
	}

	// --------------------------------------------------------------- helpers.

	/**
	 * @param array<string, mixed> $proposal
	 * @return array<string, mixed>
	 */
	private function state( int $survey_id, array $proposal ): array {
		$expires = (int) get_post_meta( $survey_id, \Paumalu\SiteSurvey\Data\Meta::PROPOSAL_EXPIRES, true );

		return [
			'id'       => $survey_id,
			'proposal' => $proposal,
			'saved'    => Proposal::exists( $survey_id ),
			// The gallery picker needs the whole library for this survey, not just the chosen four.
			'photos'   => array_values( PhotoService::for_survey( $survey_id ) ),
			'link'     => [
				// Never the token itself: it is shown once, at the moment it is minted, and after
				// that not even the reviewer's own screen can recover it.
				'active'  => '' !== (string) get_post_meta( $survey_id, \Paumalu\SiteSurvey\Data\Meta::PROPOSAL_TOKEN, true ),
				'expires' => $expires > 0 ? gmdate( 'c', $expires ) : '',
			],
		];
	}
}
