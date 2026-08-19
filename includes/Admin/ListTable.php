<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Admin;

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Triage view for reviewers: scan what has come in, see how bad each one is, click through to the
 * review app. The review itself happens on the front end, not here.
 */
final class ListTable {

	public function register(): void {
		add_filter( 'manage_' . SurveyPostType::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . SurveyPostType::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-' . SurveyPostType::POST_TYPE . '_sortable_columns', [ $this, 'sortable' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_sorting' ] );
		add_action( 'admin_head', [ $this, 'styles' ] );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return [
			'cb'          => $columns['cb'] ?? '',
			'title'       => __( 'Survey', 'paumalu-site-survey' ),
			'pe_customer' => __( 'Customer', 'paumalu-site-survey' ),
			'pe_address'  => __( 'Address', 'paumalu-site-survey' ),
			'author'      => __( 'Technician', 'paumalu-site-survey' ),
			'pe_status'   => __( 'Status', 'paumalu-site-survey' ),
			'pe_findings' => __( 'Findings', 'paumalu-site-survey' ),
			'pe_date'     => __( 'Inspected', 'paumalu-site-survey' ),
		];
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'pe_customer':
				echo esc_html( (string) get_post_meta( $post_id, Meta::CUSTOMER_NAME, true ) ?: '—' );
				break;

			case 'pe_address':
				echo esc_html( (string) get_post_meta( $post_id, Meta::SERVICE_ADDRESS, true ) ?: '—' );
				break;

			case 'pe_status':
				$status = (string) get_post_status( $post_id );
				$label  = Statuses::labels()[ $status ] ?? $status;

				printf(
					'<span class="pe-pill pe-pill--%s">%s</span>',
					esc_attr( str_replace( '_', '-', $status ) ),
					esc_html( $label )
				);

				if ( get_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, true ) ) {
					echo ' <span class="pe-dirty" title="' . esc_attr__( 'Edited after submission', 'paumalu-site-survey' ) . '">●</span>';
				}
				break;

			case 'pe_findings':
				$this->render_findings( $post_id );
				break;

			case 'pe_date':
				$date = (string) get_post_meta( $post_id, Meta::INSPECTION_DATE, true );
				echo esc_html( '' !== $date ? mysql2date( 'M j, Y', $date ) : '—' );
				break;
		}
	}

	private function render_findings( int $post_id ): void {
		$counts = get_post_meta( $post_id, Meta::FAIL_COUNTS, true );
		$counts = is_array( $counts ) ? $counts : [];

		$buckets = [
			'immediate'   => [ '#d63638', __( 'Immediate hazards', 'paumalu-site-survey' ) ],
			'recommended' => [ '#dba617', __( 'Recommended maintenance', 'paumalu-site-survey' ) ],
			'optional'    => [ '#2271b1', __( 'Optional upgrades', 'paumalu-site-survey' ) ],
		];

		$out = [];

		foreach ( $buckets as $key => [$color, $label] ) {
			$count = absint( $counts[ $key ] ?? 0 );

			if ( 0 === $count ) {
				continue;
			}

			$out[] = sprintf(
				'<span class="pe-count" style="color:%s" title="%s">%d</span>',
				esc_attr( $color ),
				esc_attr( $label ),
				$count
			);
		}

		echo $out ? wp_kses_post( implode( ' ', $out ) ) : '—';
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function sortable( array $columns ): array {
		$columns['pe_customer'] = 'pe_customer';
		$columns['pe_date']     = 'pe_date';

		return $columns;
	}

	public function apply_sorting( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$map = [
			'pe_customer' => Meta::CUSTOMER_NAME,
			'pe_date'     => Meta::INSPECTION_DATE,
		];

		$orderby = (string) $query->get( 'orderby' );

		if ( isset( $map[ $orderby ] ) ) {
			$query->set( 'meta_key', $map[ $orderby ] );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	public function styles(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || SurveyPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			.pe-pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; background:#f0f0f1; }
			.pe-pill--pending { background:#fcf3d7; }
			.pe-pill--pe-changes-req { background:#fce7e7; }
			.pe-pill--pe-accepted { background:#e3f2e3; }
			.pe-dirty { color:#d63638; }
			.pe-count { font-weight:700; margin-right:6px; }
		</style>
		<?php
	}
}
