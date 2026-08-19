<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Media;

use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * upload_files is required for technicians to attach inspection photos, but on its own it also
 * exposes every attachment on the site. Scope the library to a user's own uploads unless they can
 * already see other people's surveys.
 */
final class MediaRestrictions {

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'restrict_query' ] );
		add_filter( 'ajax_query_attachments_args', [ $this, 'restrict_ajax_query' ] );
	}

	public function restrict_query( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( self::sees_all_media() ) {
			return;
		}

		$query->set( 'author', get_current_user_id() );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function restrict_ajax_query( array $args ): array {
		if ( ! self::sees_all_media() ) {
			$args['author'] = get_current_user_id();
		}

		return $args;
	}

	private static function sees_all_media(): bool {
		return current_user_can( 'edit_others_site_surveys' ) || current_user_can( 'edit_others_posts' );
	}
}
