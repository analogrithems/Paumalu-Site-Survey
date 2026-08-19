<?php
/**
 * Plugin Name: Paumalu test mail log
 * Description: Captures outbound mail so the end-to-end run can read a link that is deliberately never shown in the browser.
 *
 * Local only, and not part of the plugin. It lives in tests/mu/, which .wp-env.json mounts as
 * wp-content/mu-plugins, so it exists in the Docker environment and nowhere else — it is excluded
 * from the deployable folder by .distignore along with the rest of tests/.
 *
 * Why it has to exist: the proposal token is shown exactly once, at the moment it is minted, and
 * `ProposalController::state()` deliberately never returns it — not even to the reviewer who just
 * sent it. That is the right design, and it means the only place the customer's URL appears is in
 * the email. Without reading the mail, an end-to-end test can exercise on-site signing but not the
 * emailed-link path, which is the one most customers will actually use.
 *
 * It also short-circuits delivery. There is no MTA in the container, so every wp_mail() would
 * otherwise spend its time failing.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

const PE_TEST_MAIL_OPTION = 'pe_test_mail_log';
const PE_TEST_MAIL_KEEP   = 50;

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$log = get_option( PE_TEST_MAIL_OPTION, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$log[] = [
			// wp_mail() accepts a string or an array here, and the difference has bitten this suite
			// before, so normalise once rather than at every call site.
			'to'      => array_values( (array) ( $atts['to'] ?? [] ) ),
			'subject' => (string) ( $atts['subject'] ?? '' ),
			'message' => (string) ( $atts['message'] ?? '' ),
			'headers' => (array) ( $atts['headers'] ?? [] ),
			'at'      => time(),
		];

		update_option( PE_TEST_MAIL_OPTION, array_slice( $log, -PE_TEST_MAIL_KEEP ), false );

		// true, not false. Any non-null return short-circuits wp_mail(), but the value becomes
		// wp_mail()'s own return — so false means "handled, and delivery failed", which is exactly
		// what the app is right to treat as an error. Capturing mail must not look like losing it.
		return true;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'paumalu-test/v1',
			'/mail',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function (): WP_REST_Response {
						$log = get_option( PE_TEST_MAIL_OPTION, [] );

						return rest_ensure_response( is_array( $log ) ? array_values( $log ) : [] );
					},
				],
				[
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function (): WP_REST_Response {
						delete_option( PE_TEST_MAIL_OPTION );

						return rest_ensure_response( [ 'cleared' => true ] );
					},
				],
			]
		);
	}
);
