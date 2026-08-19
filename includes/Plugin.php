<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?self $instance = null;

	public static function boot(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		( new PostType\SurveyPostType() )->register();
		( new PostType\Statuses() )->register();
		( new Media\MediaRestrictions() )->register();
		( new Media\PhotoService() )->register();
		( new Review\Notes() )->register();
		( new Review\Notifications() )->register();
		( new Setup\AdminLockout() )->register();
		( new Admin\SettingsPage() )->register();
		( new Admin\ListTable() )->register();
		( new Rest\CatalogController() )->register();
		( new Rest\SurveyController() )->register();
		( new Rest\PhotoController() )->register();
		( new Rest\ReviewController() )->register();
		( new Rest\ProposalController() )->register();
		( new Proposal\Notifications() )->register();
		( new Frontend\Router() )->register();
		( new Frontend\ProposalRouter() )->register();
		// Registered after both routers because it decides what to do by asking them whether they
		// claimed the request.
		( new Frontend\Landing() )->register();
	}
}
