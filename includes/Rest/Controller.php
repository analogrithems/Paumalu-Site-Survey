<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for the plugin's REST controllers.
 *
 * Authentication is WordPress's own cookie scheme plus the X-WP-Nonce header — the technician app is
 * served from the same origin as wp-login, so there is no reason to introduce tokens. Application
 * passwords keep working for scripted access without any extra work here.
 */
abstract class Controller {

	public const API_NAMESPACE = 'paumalu/v1';

	abstract public function register_routes(): void;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Resolve a survey by id, rejecting anything that is not one of ours.
	 *
	 * Checking the post type matters: without it, passing the id of any other post would hand back
	 * an object the capability checks were never designed for.
	 */
	protected function get_survey( int $post_id ): \WP_Post|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || SurveyPostType::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'pe_survey_not_found',
				__( 'That survey does not exist.', 'paumalu-site-survey' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * Can the current user open this survey in the app at all?
	 *
	 * Deliberately keyed to edit rather than read: every route here is part of an editing surface,
	 * and 'edit_site_survey' maps through map_meta_cap to 'edit_others_site_surveys' for a survey
	 * the user does not own — which is exactly the wall technicians must hit.
	 */
	protected function can_edit_survey( int $post_id ): bool|\WP_Error {
		$post = $this->get_survey( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_site_survey', $post_id ) ) {
			return $this->forbidden();
		}

		return true;
	}

	protected function forbidden( string $message = '' ): \WP_Error {
		return new \WP_Error(
			'pe_forbidden',
			'' !== $message ? $message : __( 'You are not allowed to do that.', 'paumalu-site-survey' ),
			[ 'status' => is_user_logged_in() ? 403 : 401 ]
		);
	}

	/**
	 * Gate for routes that need nothing more than "is a technician or better".
	 */
	public function require_survey_access(): bool|\WP_Error {
		return current_user_can( 'edit_site_surveys' ) ? true : $this->forbidden();
	}
}
