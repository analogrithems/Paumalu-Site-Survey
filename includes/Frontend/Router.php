<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Frontend;

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\Rest\Controller;
use Paumalu\SiteSurvey\Setup\Capabilities;
use Paumalu\SiteSurvey\Setup\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the technician app on its own front-end routes.
 *
 * Rewrite rules plus template_include rather than a shortcode on a page: the app needs the whole
 * document — viewport meta, no theme chrome, no stray widgets over a form somebody is filling in on
 * a phone in a crawlspace — and a shortcode cannot guarantee any of that.
 */
final class Router {

	/** Base path segment. Changing this requires a rewrite flush. */
	public const BASE = 'survey';

	public const QUERY_ROUTE = 'pe_survey_route';
	public const QUERY_ID    = 'pe_survey_id';

	public function register(): void {
		add_action( 'init', [ self::class, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );
		add_filter( 'template_include', [ $this, 'load_template' ] );
		add_action( 'admin_notices', [ $this, 'permalink_notice' ] );
	}

	/**
	 * Rewrite rules do nothing under plain permalinks, which is the default on a fresh install.
	 *
	 * Without this the technician app simply 404s with no clue why, and the fix is one click in a
	 * screen nobody would think to visit.
	 */
	public function permalink_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || '' !== (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Paumalu Site Survey:', 'paumalu-site-survey' ),
			esc_html__( 'the technician app needs pretty permalinks. While permalinks are set to “Plain”, the /survey/ pages will not load.', 'paumalu-site-survey' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Fix permalinks', 'paumalu-site-survey' )
		);
	}

	public static function add_rules(): void {
		$base = self::BASE;

		add_rewrite_rule( "^{$base}/?$", 'index.php?' . self::QUERY_ROUTE . '=list', 'top' );
		add_rewrite_rule( "^{$base}/new/?$", 'index.php?' . self::QUERY_ROUTE . '=new', 'top' );
		add_rewrite_rule( "^{$base}/(\d+)/?$", 'index.php?' . self::QUERY_ROUTE . '=edit&' . self::QUERY_ID . '=$matches[1]', 'top' );
		add_rewrite_rule( "^{$base}/(\d+)/review/?$", 'index.php?' . self::QUERY_ROUTE . '=review&' . self::QUERY_ID . '=$matches[1]', 'top' );
		add_rewrite_rule( "^{$base}/(\d+)/proposal/?$", 'index.php?' . self::QUERY_ROUTE . '=proposal&' . self::QUERY_ID . '=$matches[1]', 'top' );
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_ROUTE;
		$vars[] = self::QUERY_ID;

		return $vars;
	}

	public static function current_route(): string {
		return (string) get_query_var( self::QUERY_ROUTE );
	}

	public static function current_id(): int {
		return (int) get_query_var( self::QUERY_ID );
	}

	public static function url( string $path = '' ): string {
		return home_url( '/' . self::BASE . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Auth gate and cache suppression, before anything renders.
	 */
	public function handle_request(): void {
		$route = self::current_route();

		if ( '' === $route ) {
			return;
		}

		global $wp_query;

		// The rewrite resolves to no post, so WordPress would otherwise call this a 404.
		$wp_query->is_404 = false;
		status_header( 200 );

		$this->prevent_caching();
		$this->clean_head();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( [] ) ) ) );
			exit;
		}

		if ( ! current_user_can( 'edit_site_surveys' ) ) {
			wp_die(
				esc_html__( 'Your account does not have access to site surveys.', 'paumalu-site-survey' ),
				esc_html__( 'Access denied', 'paumalu-site-survey' ),
				[ 'response' => 403 ]
			);
		}

		// Both of the reviewer's screens sit behind the same capability. A technician who follows a
		// link to one is sent to the survey itself rather than shown a wall — they own the document,
		// they just do not get to decide on it or write the customer's copy.
		if ( in_array( $route, [ 'review', 'proposal' ], true ) && ! current_user_can( Capabilities::REVIEW ) ) {
			wp_safe_redirect( self::url( self::current_id() . '/' ) );
			exit;
		}
	}

	/**
	 * WP Super Cache is active on the production host. Every route here is per-user and
	 * authenticated, so a cached copy would serve one technician's survey to the next one.
	 */
	private function prevent_caching(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		nocache_headers();
	}

	/**
	 * Strip theme and core output the app must not inherit.
	 *
	 * Two of these are correctness rather than weight: the block theme emits its own viewport meta,
	 * which would land after ours and drop viewport-fit=cover, and title-tag support emits a second
	 * <title>. The rest is payload — this app renders no blocks and no emoji, and it is loaded over
	 * LTE in a garage, so the block library and global stylesheets are pure delay.
	 */
	private function clean_head(): void {
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

		add_filter( 'show_admin_bar', '__return_false' );
		add_filter( 'emoji_svg_url', '__return_false' );

		// Run at a negative priority so this lands ahead of everything else on wp_head, including
		// the block-template callbacks core attaches during template_include — i.e. after this
		// method has already been called from template_redirect.
		add_action(
			'wp_head',
			static function (): void {
				self::strip_head_callbacks(
					[
						// Correctness: both would emit a second <title> and a second viewport meta,
						// and the later viewport would override ours, losing viewport-fit=cover.
						'_wp_render_title_tag',
						'_block_template_render_title_tag',
						'_block_template_viewport_meta_tag',
						// Payload with no purpose in an authenticated, noindex field app.
						'feed_links',
						'feed_links_extra',
						'rsd_link',
						'wlwmanifest_link',
						'wp_shortlink_wp_head',
						'wp_generator',
						'adjacent_posts_rel_link_wp_head',
						'wp_oembed_add_discovery_links',
						'wp_oembed_add_host_js',
						'rest_output_link_wp_head',
						'print_emoji_detection_script',
						'wp_enqueue_emoji_styles',
						'rel_canonical',
					]
				);
			},
			-100
		);

		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				wp_dequeue_style( 'wp-block-library' );
				wp_dequeue_style( 'wp-block-library-theme' );
				wp_dequeue_style( 'global-styles' );
				wp_dequeue_style( 'classic-theme-styles' );
			},
			100
		);
	}

	/**
	 * Remove wp_head callbacks by function name, whatever priority they were registered at.
	 *
	 * Matching on the name rather than the (name, priority) pair on purpose: core has moved these
	 * between priorities across releases and swaps some of them out entirely under a block theme,
	 * so a hardcoded priority is a silent no-op waiting to happen on the next WordPress update.
	 *
	 * @param list<string> $names
	 */
	private static function strip_head_callbacks( array $names ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_head'] ) ) {
			return;
		}

		foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_string( $callback['function'] ) && in_array( $callback['function'], $names, true ) ) {
					remove_action( 'wp_head', $callback['function'], $priority );
				}
			}
		}
	}

	public function load_template( string $template ): string {
		if ( '' === self::current_route() ) {
			return $template;
		}

		$this->enqueue_assets();

		return \Paumalu\SiteSurvey\PLUGIN_DIR . 'templates/app.php';
	}

	private function enqueue_assets(): void {
		$asset_file = \Paumalu\SiteSurvey\PLUGIN_DIR . 'build/index.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'paumalu-site-survey',
			\Paumalu\SiteSurvey\PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// wp-scripts emits styles imported from the entry point as style-index.css, and the RTL
		// variant beside it; wp_style_add_data() lets WordPress pick the right one per locale.
		if ( is_readable( \Paumalu\SiteSurvey\PLUGIN_DIR . 'build/style-index.css' ) ) {
			wp_enqueue_style(
				'paumalu-site-survey',
				\Paumalu\SiteSurvey\PLUGIN_URL . 'build/style-index.css',
				[],
				$asset['version']
			);

			wp_style_add_data( 'paumalu-site-survey', 'rtl', 'replace' );
		}

		wp_add_inline_script(
			'paumalu-site-survey',
			'window.paumaluSurvey = ' . wp_json_encode( $this->bootstrap_data() ) . ';',
			'before'
		);

		wp_set_script_translations( 'paumalu-site-survey', 'paumalu-site-survey' );
	}

	/**
	 * Everything the app needs to render its first frame without a round trip.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap_data(): array {
		$user = wp_get_current_user();

		return [
			'route'      => self::current_route(),
			'surveyId'   => self::current_id(),
			'restRoot'   => esc_url_raw( rest_url( Controller::API_NAMESPACE ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'baseUrl'    => self::url(),
			'logoutUrl'  => wp_logout_url( home_url() ),
			'adminUrl'   => current_user_can( Capabilities::REVIEW ) ? admin_url( 'edit.php?post_type=pe_site_survey' ) : '',
			'githubUrl'  => Links::GITHUB,
			'docsUrl'    => Links::TECHNICIAN_GUIDE,
			'catalogVersion' => Catalog::CURRENT_VERSION,
			'statusLabels'   => Statuses::labels(),
			'user'       => [
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'isReviewer' => current_user_can( Capabilities::REVIEW ),
			],
		];
	}
}
