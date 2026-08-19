<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Rest;

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\Data\SurveyRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the question catalog to the field app.
 *
 * Takes a version so a survey written against v1 keeps rendering the wording it was answered under
 * after the punch list is revised.
 */
final class CatalogController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/catalog',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_catalog' ],
					'permission_callback' => [ $this, 'require_survey_access' ],
					'args'                => [
						'version' => [
							'type'              => 'integer',
							'default'           => Catalog::CURRENT_VERSION,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	public function get_catalog( \WP_REST_Request $request ): \WP_REST_Response {
		$version = (int) $request->get_param( 'version' );
		$catalog = Catalog::get( $version );

		$response = rest_ensure_response(
			[
				'version'    => $version,
				'sections'   => $catalog['sections'],
				'upgrades'   => array_values( $catalog['upgrades'] ),
				'summary'    => $catalog['summary'],
				'statuses'   => SurveyRepository::STATUSES,
				'severities' => SurveyRepository::SEVERITIES,
			]
		);

		// The catalog is a static asset in everything but name; let the browser hold it so a tech
		// moving between surveys in the field is not refetching it over LTE each time.
		$response->header( 'Cache-Control', 'private, max-age=3600' );

		return $response;
	}
}
