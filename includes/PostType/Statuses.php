<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\PostType;

use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The survey workflow: draft → pending → accepted, with a path back for revisions.
 *
 * WordPress core only guards transitions into 'publish' and 'future', so it will happily let any
 * user with edit access set a custom status. Every transition must therefore go through
 * {@see self::can_transition()} rather than relying on capability mapping alone.
 */
final class Statuses {

	public const DRAFT           = 'draft';
	public const PENDING         = 'pending';
	public const CHANGES_REQUEST = 'pe_changes_req';
	public const ACCEPTED        = 'pe_accepted';

	public function register(): void {
		add_action( 'init', [ $this, 'register_statuses' ] );
	}

	public function register_statuses(): void {
		register_post_status(
			self::CHANGES_REQUEST,
			[
				'label'                     => _x( 'Changes Requested', 'post status', 'paumalu-site-survey' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of surveys. */
				'label_count'               => _n_noop(
					'Changes Requested <span class="count">(%s)</span>',
					'Changes Requested <span class="count">(%s)</span>',
					'paumalu-site-survey'
				),
			]
		);

		register_post_status(
			self::ACCEPTED,
			[
				'label'                     => _x( 'Accepted', 'post status', 'paumalu-site-survey' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of surveys. */
				'label_count'               => _n_noop(
					'Accepted <span class="count">(%s)</span>',
					'Accepted <span class="count">(%s)</span>',
					'paumalu-site-survey'
				),
			]
		);
	}

	/**
	 * Every status a survey can be in.
	 *
	 * Exists because `'post_status' => 'any'` is a trap here: WP_Query resolves `any` to every status
	 * *except* those registered with `exclude_from_search => true`, which both of the custom ones are.
	 * A query written with `any` therefore silently cannot see an accepted survey — and accepted is
	 * the only state a survey is in by the time its proposal matters. Ask for the list explicitly.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_keys( self::labels() );
	}

	/**
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::DRAFT           => __( 'Draft', 'paumalu-site-survey' ),
			self::PENDING         => __( 'Ready for Review', 'paumalu-site-survey' ),
			self::CHANGES_REQUEST => __( 'Changes Requested', 'paumalu-site-survey' ),
			self::ACCEPTED        => __( 'Accepted', 'paumalu-site-survey' ),
		];
	}

	/**
	 * Allowed transitions, each mapped to the capability required to make it.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function transitions(): array {
		return [
			self::DRAFT           => [
				self::PENDING => 'edit_site_surveys',
			],
			self::PENDING         => [
				self::CHANGES_REQUEST => Capabilities::REVIEW,
				self::ACCEPTED        => Capabilities::REVIEW,
				self::DRAFT           => Capabilities::REVIEW,
			],
			self::CHANGES_REQUEST => [
				self::PENDING => 'edit_site_surveys',
			],
			self::ACCEPTED        => [
				self::CHANGES_REQUEST => Capabilities::REVIEW,
			],
		];
	}

	public static function can_transition( string $from, string $to, int $post_id, ?int $user_id = null ): bool {
		$user_id  = $user_id ?? get_current_user_id();
		$required = self::transitions()[ $from ][ $to ] ?? null;

		if ( null === $required ) {
			return false;
		}

		if ( ! user_can( $user_id, 'edit_site_survey', $post_id ) ) {
			return false;
		}

		return user_can( $user_id, $required );
	}

	public static function is_survey_status( string $status ): bool {
		return array_key_exists( $status, self::labels() );
	}
}
