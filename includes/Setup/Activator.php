<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Setup;

use Paumalu\SiteSurvey\Frontend\ProposalRouter;
use Paumalu\SiteSurvey\Frontend\Router;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		( new SurveyPostType() )->register_post_type();
		( new Statuses() )->register_statuses();

		// The rules must exist before the flush, or the app's routes 404 until the next permalink save.
		Router::add_rules();
		ProposalRouter::add_rules();

		Roles::install();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
