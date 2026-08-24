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
 * The project's docs and GitHub link, in the standard Help dropdown at the top right of the Site
 * Surveys and Settings screens — not a left-nav item. A left-nav row for "Editor Guide" reads as a
 * page of its own and outranks the actual admin screens; a Help tab is where every other
 * well-behaved plugin puts reference links, and it doesn't compete with the menu for space.
 *
 * Applies to any wp-admin screen whose post type is this plugin's — the Site Surveys list table and
 * the Settings page both qualify, and nothing else does, without needing to hardcode either
 * screen's hook suffix.
 */
final class HelpTab {

	public function register(): void {
		add_action( 'current_screen', [ $this, 'add_tab' ] );
	}

	public function add_tab( \WP_Screen $screen ): void {
		if ( SurveyPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$content = $this->content();

		if ( '' === $content ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'      => 'pe-site-survey-help',
				'title'   => __( 'Guides', 'paumalu-site-survey' ),
				'content' => $content,
			]
		);
	}

	/**
	 * Same role split the left-nav version had: every reviewer (editor or administrator) gets the
	 * editor guide and the GitHub link, only administrators also get the administrator guide.
	 */
	private function content(): string {
		$rows = [];

		if ( current_user_can( Capabilities::REVIEW ) ) {
			$rows[] = $this->link( Links::EDITOR_GUIDE, __( 'Editor Guide', 'paumalu-site-survey' ) );
			$rows[] = $this->link( Links::GITHUB, __( 'GitHub Project', 'paumalu-site-survey' ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$rows[] = $this->link( Links::ADMINISTRATOR_GUIDE, __( 'Administrator Guide', 'paumalu-site-survey' ) );
		}

		return implode( '', $rows );
	}

	private function link( string $url, string $label ): string {
		return sprintf(
			'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
