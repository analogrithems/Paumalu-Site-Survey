<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A front door for the one hostname this plugin lives on.
 *
 * resi.paumaluelectric.com exists to run the survey app, but its root is a public URL on the open
 * internet: a customer mistypes it, a technician taps the logo, a link gets shared. Left alone that
 * lands on whatever the theme prints for an empty blog — a "Nothing here" page under the company's
 * name, which reads as a broken website rather than as a tool that is working fine.
 *
 * So the root says what this is and points onward: the public site for anybody who wanted the
 * company, and the survey app for anybody who works here.
 */
final class Landing {

	/** Paumalu Electric's actual public website. */
	public const PUBLIC_SITE = 'https://paumaluelectric.wordpress.com/';

	public function register(): void {
		add_filter( 'template_include', [ $this, 'load_template' ], 20 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
	}

	/**
	 * Whether this request should get the signpost.
	 *
	 * Two guards, and both matter:
	 *
	 * The app's rewrites resolve to `index.php` with only a custom query var set, so WordPress sees
	 * no post query and decides the request is the blog home — `is_front_page()` is true on
	 * /survey/ and on /proposal/{token}/ as well as on the actual root. Checking that neither
	 * router claimed the request is what separates the three.
	 *
	 * The `show_on_front` check is the exit: the day somebody assigns a real page in Settings →
	 * Reading, they meant it, and this stops overriding them without needing to be uninstalled.
	 */
	private function applies(): bool {
		if ( '' !== Router::current_route() || '' !== ProposalRouter::current_token() ) {
			return false;
		}

		if ( ! is_front_page() || 'posts' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		/**
		 * Filters whether the plugin serves its internal-tool landing page at the site root.
		 *
		 * @param bool $enabled Whether to take over the front page.
		 */
		return (bool) apply_filters( 'pe_survey_landing_enabled', true );
	}

	public function load_template( string $template ): string {
		if ( ! $this->applies() ) {
			return $template;
		}

		return \Paumalu\SiteSurvey\PLUGIN_DIR . 'templates/landing.php';
	}

	/**
	 * Keep the signpost out of search results.
	 *
	 * Somebody searching for the company should find the company, not the door to its internal
	 * tooling. Indexing this page could only ever put it in front of the wrong person.
	 *
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public function robots( array $robots ): array {
		if ( ! $this->applies() ) {
			return $robots;
		}

		return wp_robots_no_robots( $robots );
	}
}
