<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Setup;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-update from GitHub Releases, in place of the manual rsync deploy this plugin used before.
 *
 * Wraps the vendored Plugin Update Checker library rather than hand-rolling the
 * `pre_set_site_transient_update_plugins` / `plugins_api` plumbing — that plumbing has enough sharp
 * edges (version parsing, transient caching, the Plugins-page "update available" row, the changelog
 * popup) that a widely used, MIT-licensed library beats new code doing the same job for the first
 * time on a site with real customer data behind it.
 *
 * Only loaded for admin requests and the WP-Cron run that resolves the `wp_update_plugins` event —
 * that cron event is itself only *scheduled* from `admin_init`, so between the two this never touches
 * the technician's front-end request path, which is the one this plugin is built to keep fast.
 *
 * Updates still require a human to click "Update Now" on the Plugins page, same as any other plugin —
 * this does not enable WordPress's unattended background auto-updates.
 */
final class Updates {

	private const SLUG = 'paumalu-site-survey';

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_init_checker' ], 1 );
	}

	public function maybe_init_checker(): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		require_once \Paumalu\SiteSurvey\PLUGIN_DIR . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker(
			Links::GITHUB,
			\Paumalu\SiteSurvey\PLUGIN_FILE,
			self::SLUG
		);

		// Releases carry a purpose-built zip (built the same way the old rsync deploy was: through
		// .distignore) rather than GitHub's auto-generated "Source code" archive, which would ship
		// src/, tests/, node_modules-adjacent tooling files, and docs/ straight onto the production
		// server.
		$checker->getVcsApi()->enableReleaseAssets( '/\.zip$/i' );
	}
}
