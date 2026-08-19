<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Admin;

use Paumalu\SiteSurvey\PostType\SurveyPostType;
use Paumalu\SiteSurvey\Setup\Capabilities;
use Paumalu\SiteSurvey\Setup\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain links to the project's GitHub repo and role guides under Site Surveys in wp-admin.
 *
 * These aren't real admin pages, so add_submenu_page() is the wrong tool — it always registers a
 * page callback and routes the click through admin.php?page=. Writing directly into $submenu with
 * a full URL as the slug is what core itself falls back to rendering as a plain external link (see
 * wp-admin/menu-header.php), and it's the standard way plugins add an outbound link to their own
 * menu. Capability still gates visibility the normal way: core checks current_user_can() against
 * each row's capability field before printing it.
 */
final class DocsMenu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_links' ], 100 );
	}

	public function add_links(): void {
		global $submenu;

		$parent = 'edit.php?post_type=' . SurveyPostType::POST_TYPE;

		if ( ! isset( $submenu[ $parent ] ) ) {
			return;
		}

		// Every reviewer (editor or administrator) gets the editor guide; only administrators also
		// get the administrator guide. No separate branching needed — capability_type does the
		// splitting for us, since only administrators hold manage_options.
		$submenu[ $parent ][] = [
			__( 'Editor Guide', 'paumalu-site-survey' ),
			Capabilities::REVIEW,
			Links::EDITOR_GUIDE,
		];

		$submenu[ $parent ][] = [
			__( 'Administrator Guide', 'paumalu-site-survey' ),
			'manage_options',
			Links::ADMINISTRATOR_GUIDE,
		];

		$submenu[ $parent ][] = [
			__( 'GitHub Project', 'paumalu-site-survey' ),
			Capabilities::REVIEW,
			Links::GITHUB,
		];
	}
}
