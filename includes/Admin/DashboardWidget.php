<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Admin;

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Frontend\Router;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;
use Paumalu\SiteSurvey\Proposal\Proposal;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "What needs my attention today" for reviewers: the wp-admin dashboard, not the survey list table,
 * is where Josh already lands every morning — the list table only shows up if he goes looking.
 *
 * Two queues, because they need two different actions from him: surveys awaiting a first look, and
 * signed proposals that have cleared review but still need a truck on the calendar. The proposal's
 * own status never reflects "on the calendar" — a signature does not un-sign when the job is
 * scheduled — so that state lives in its own meta key, cleared by the "Mark scheduled" action below.
 */
final class DashboardWidget {

	private const WIDGET_ID = 'pe_site_survey_queue';
	private const ACTION    = 'pe_mark_scheduled';
	private const NONCE     = 'pe_mark_scheduled_nonce';

	/** Rows beyond this are a sign the queue needs triage in the list table, not a longer widget. */
	private const MAX_ROWS = 8;

	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_mark_scheduled' ] );
	}

	public function add_widget(): void {
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Site Survey Queue', 'paumalu-site-survey' ),
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( isset( $_GET['pe_scheduled'] ) ) {
			echo '<p class="notice notice-success" style="padding:8px 12px;">' .
				esc_html__( 'Marked as scheduled.', 'paumalu-site-survey' ) .
				'</p>';
		}

		$this->render_section(
			__( 'Awaiting review', 'paumalu-site-survey' ),
			$this->pending_surveys(),
			'review'
		);

		$this->render_section(
			__( 'Signed — needs scheduling', 'paumalu-site-survey' ),
			$this->unscheduled_signed_surveys(),
			'proposal'
		);
	}

	/**
	 * @param list<\WP_Post> $surveys
	 */
	private function render_section( string $title, array $surveys, string $route ): void {
		echo '<p><strong>' . esc_html( $title ) . '</strong></p>';

		if ( ! $surveys ) {
			echo '<p style="color:#646970;">' . esc_html__( 'Nothing here.', 'paumalu-site-survey' ) . '</p>';

			return;
		}

		echo '<ul style="margin:0 0 16px;">';

		foreach ( $surveys as $survey ) {
			$name = get_post_meta( $survey->ID, Meta::CUSTOMER_NAME, true );
			$name = '' !== $name ? (string) $name : __( 'Untitled survey', 'paumalu-site-survey' );

			echo '<li style="margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">';
			echo '<a href="' . esc_url( Router::url( $survey->ID . '/' . $route . '/' ) ) . '">' . esc_html( $name ) . '</a>';

			if ( 'proposal' === $route ) {
				echo '&nbsp;' . $this->mark_scheduled_link( $survey->ID );
			}

			echo '</li>';
		}

		echo '</ul>';
	}

	private function mark_scheduled_link( int $survey_id ): string {
		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'    => self::ACTION,
					'survey_id' => $survey_id,
				],
				admin_url( 'admin-post.php' )
			),
			self::NONCE . $survey_id
		);

		return '<a href="' . esc_url( $url ) . '" class="button button-small">' .
			esc_html__( 'Mark scheduled', 'paumalu-site-survey' ) .
			'</a>';
	}

	public function handle_mark_scheduled(): void {
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			wp_die( esc_html__( 'Your account does not have access to site surveys.', 'paumalu-site-survey' ), '', [ 'response' => 403 ] );
		}

		$survey_id = isset( $_GET['survey_id'] ) ? absint( $_GET['survey_id'] ) : 0;

		check_admin_referer( self::NONCE . $survey_id );

		if ( $survey_id && SurveyPostType::POST_TYPE === get_post_type( $survey_id ) ) {
			update_post_meta( $survey_id, Meta::SCHEDULED_AT, time() );
		}

		wp_safe_redirect( admin_url( 'index.php?pe_scheduled=1' ) );
		exit;
	}

	/**
	 * @return list<\WP_Post>
	 */
	private function pending_surveys(): array {
		return get_posts(
			[
				'post_type'      => SurveyPostType::POST_TYPE,
				'post_status'    => Statuses::PENDING,
				'posts_per_page' => self::MAX_ROWS,
				'orderby'        => 'modified',
				'order'          => 'ASC',
			]
		);
	}

	/**
	 * Signed proposals only exist on accepted surveys, but a WP_Query cannot see inside the proposal's
	 * JSON blob — so this asks for every accepted survey without a scheduled-at timestamp yet, then
	 * filters to signed ones in PHP. Fine at this volume; if the accepted queue ever gets large enough
	 * for that to matter, the fix is a flat `_pe_proposal_status` meta column, not a bigger query here.
	 *
	 * @return list<\WP_Post>
	 */
	private function unscheduled_signed_surveys(): array {
		$candidates = get_posts(
			[
				'post_type'      => SurveyPostType::POST_TYPE,
				'post_status'    => Statuses::ACCEPTED,
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'meta_query'     => [
					[
						'key'     => Meta::SCHEDULED_AT,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$signed = array_values(
			array_filter(
				$candidates,
				static fn ( \WP_Post $post ) => Proposal::SIGNED === Proposal::get( $post->ID )['status']
			)
		);

		return array_slice( $signed, 0, self::MAX_ROWS );
	}
}
