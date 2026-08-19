<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\Media\PhotoService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photo upload, captioning and removal.
 *
 * The upload route is the only multipart endpoint in the plugin. Everything else the app does is
 * JSON, but sending an image as base64 inside a JSON body inflates it by a third — over LTE in a
 * crawlspace that is the difference between a photo landing and a technician giving up.
 */
final class PhotoController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/surveys/(?P<id>\d+)/photos',
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
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_survey' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'check_upload' ],
					'args'                => [
						'item_key' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'panel_id' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'caption'  => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/photos/(?P<id>\d+)',
			[
				'args' => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'check_photo' ],
					'args'                => [
						'caption'  => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'featured' => [
							'type' => 'boolean',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'check_photo' ],
				],
			]
		);
	}

	// ------------------------------------------------------------ permissions.

	public function check_survey( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->can_edit_survey( (int) $request->get_param( 'id' ) );
	}

	public function check_upload( \WP_REST_Request $request ): bool|\WP_Error {
		$allowed = $this->can_edit_survey( (int) $request->get_param( 'id' ) );

		if ( true !== $allowed ) {
			return $allowed;
		}

		// Editing a survey and being allowed to put files on the server are separate grants, and a
		// role could plausibly have the first without the second.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden( __( 'Your account cannot upload files.', 'paumalu-site-survey' ) );
		}

		return true;
	}

	/**
	 * A photo inherits its permissions from the survey it hangs off.
	 *
	 * Resolving through post_parent rather than checking the attachment's own author is what stops a
	 * technician from touching a photo that happens to be theirs but now belongs to somebody else's
	 * survey — and, more usefully, lets a reviewer caption photos they did not take.
	 */
	public function check_photo( \WP_REST_Request $request ): bool|\WP_Error {
		$survey = $this->photo_survey( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $survey ) ) {
			return $survey;
		}

		return $this->can_edit_survey( $survey );
	}

	private function photo_survey( int $attachment_id ): int|\WP_Error {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->photo_not_found();
		}

		$survey = $this->get_survey( (int) $attachment->post_parent );

		// An attachment parented to something that is not a survey is not ours to hand out, and
		// saying so precisely would confirm the id exists.
		if ( is_wp_error( $survey ) ) {
			return $this->photo_not_found();
		}

		return (int) $survey->ID;
	}

	private function photo_not_found(): \WP_Error {
		return new \WP_Error(
			'pe_photo_not_found',
			__( 'That photo does not exist.', 'paumalu-site-survey' ),
			[ 'status' => 404 ]
		);
	}

	// ---------------------------------------------------------------- routes.

	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$photos = PhotoService::for_survey( (int) $request->get_param( 'id' ) );

		return rest_ensure_response( array_values( $photos ) );
	}

	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) ) {
			return new \WP_Error(
				'pe_no_file',
				__( 'Send the image as a multipart field named "file".', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$attachment_id = PhotoService::attach(
			(int) $request->get_param( 'id' ),
			$file,
			[
				'item_key' => (string) $request->get_param( 'item_key' ),
				'panel_id' => (string) $request->get_param( 'panel_id' ),
				'caption'  => (string) $request->get_param( 'caption' ),
			]
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return new \WP_REST_Response( PhotoService::prepare( get_post( $attachment_id ) ), 201 );
	}

	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$attachment_id = (int) $request->get_param( 'id' );
		$survey_id     = $this->photo_survey( $attachment_id );

		if ( is_wp_error( $survey_id ) ) {
			return $survey_id;
		}

		if ( null !== $request->get_param( 'caption' ) ) {
			$caption = (string) $request->get_param( 'caption' );

			wp_update_post(
				[
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				]
			);

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );
		}

		if ( null !== $request->get_param( 'featured' ) ) {
			$result = PhotoService::set_featured(
				$attachment_id,
				$survey_id,
				(bool) $request->get_param( 'featured' )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return rest_ensure_response( PhotoService::prepare( get_post( $attachment_id ) ) );
	}

	public function delete_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$attachment_id = (int) $request->get_param( 'id' );

		if ( ! PhotoService::detach( $attachment_id ) ) {
			return new \WP_Error(
				'pe_photo_delete_failed',
				__( 'That photo could not be deleted.', 'paumalu-site-survey' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [ 'deleted' => true, 'id' => $attachment_id ] );
	}
}
