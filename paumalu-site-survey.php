<?php
/**
 * Plugin Name:       Paumalu Site Survey
 * Plugin URI:        https://github.com/analogrithems/Paumalu-Site-Survey
 * Description:       Mobile-first residential electrical service inspections for field technicians, with editor review and a customer-facing action plan.
 * Version:           0.9.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Analogrithems
 * Author URI:        https://github.com/analogrithems
 * Text Domain:       paumalu-site-survey
 * License:           GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Paumalu\SiteSurvey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Tracks the build order in the plan: 0.6.x adds the proposal — auto-drafted action plan, the
// reviewer's editor, the tokenized customer page and both signing paths — on top of foundation +
// catalog + field app + photos + review. 0.7.x adds the role-based documentation, its links from
// the app itself, and the reviewer dashboard queue. 0.8.0 adds GitHub-Release self-updates, a
// configurable proposal from-address, and the proposal send log with resend. 0.9.0 splits a
// customer decline into DECLINED (needs a look) and CLOSED (read, nothing more to do) so "maybe
// resubmit later" and "not interested" stop looking identical in the queue.
const VERSION     = '0.9.0';
const PLUGIN_FILE = __FILE__;

define( 'Paumalu\SiteSurvey\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'Paumalu\SiteSurvey\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$path     = PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, [ Setup\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Setup\Activator::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
