<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Proposal;

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\Media\PhotoService;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage, lifecycle and access control for the customer-facing proposal.
 *
 * The proposal has its own lifecycle in meta rather than more post statuses: a survey's status
 * describes where it is in *Josh's* workflow, and a proposal's describes where it is in the
 * *customer's*. Conflating them would mean a survey that is simultaneously accepted and declined.
 */
final class Proposal {

	public const DRAFT    = 'draft';
	public const SENT     = 'sent';
	public const VIEWED   = 'viewed';
	public const SIGNED   = 'signed';
	public const DECLINED = 'declined';

	public const IMMEDIATE   = 'immediate';
	public const RECOMMENDED = 'recommended';
	public const OPTIONAL    = 'optional';

	/** Past four photographs a customer stops looking, and the print layout stops fitting a page. */
	public const MAX_PHOTOS = 4;

	private const TOKEN_BYTES = 20;

	/**
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return [
			self::DRAFT    => __( 'Draft', 'paumalu-site-survey' ),
			self::SENT     => __( 'Sent', 'paumalu-site-survey' ),
			self::VIEWED   => __( 'Viewed', 'paumalu-site-survey' ),
			self::SIGNED   => __( 'Signed', 'paumalu-site-survey' ),
			self::DECLINED => __( 'Declined', 'paumalu-site-survey' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function group_labels(): array {
		return [
			self::IMMEDIATE   => __( 'Immediate Hazards', 'paumalu-site-survey' ),
			self::RECOMMENDED => __( 'Recommended Maintenance', 'paumalu-site-survey' ),
			self::OPTIONAL    => __( 'Optional Upgrades', 'paumalu-site-survey' ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		return [
			'status'       => self::DRAFT,
			'intro'        => '',
			'groups'       => [
				self::IMMEDIATE   => [],
				self::RECOMMENDED => [],
				self::OPTIONAL    => [],
			],
			'photos'       => [],
			'generated_at' => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get( int $survey_id ): array {
		$raw = get_post_meta( $survey_id, Meta::PROPOSAL, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::blank();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? array_merge( self::blank(), $decoded ) : self::blank();
	}

	public static function exists( int $survey_id ): bool {
		return '' !== (string) get_post_meta( $survey_id, Meta::PROPOSAL, true );
	}

	/**
	 * Persist an edited proposal.
	 *
	 * Refuses once signed. A signed document is a record of what somebody put their name to, and a
	 * record that can be edited after the fact is not a record — if the scope needs to change after a
	 * signature, that is a new proposal, not a quiet rewrite of the old one.
	 *
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save( int $survey_id, array $incoming ): array|\WP_Error {
		$current = self::get( $survey_id );

		if ( self::SIGNED === $current['status'] ) {
			return new \WP_Error(
				'pe_proposal_signed',
				__( 'This proposal has been signed and can no longer be edited.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		$clean = self::sanitize( $incoming, $current, $survey_id );

		SurveyRepository::store_json( $survey_id, Meta::PROPOSAL, $clean );

		return $clean;
	}

	/**
	 * Validate a proposal document.
	 *
	 * The status, the timestamps and the signature block are never taken from the client — they are
	 * carried over from what is already stored. Everything the reviewer legitimately edits is content;
	 * anything that represents something having *happened* is set by the server method that made it
	 * happen.
	 *
	 * @param array<string, mixed> $incoming
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $incoming, array $current, int $survey_id ): array {
		$clean = $current;

		$clean['intro'] = sanitize_textarea_field( (string) ( $incoming['intro'] ?? $current['intro'] ) );

		$groups = [];

		foreach ( array_keys( self::group_labels() ) as $bucket ) {
			$lines = (array) ( $incoming['groups'][ $bucket ] ?? [] );

			foreach ( $lines as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}

				$text = sanitize_textarea_field( (string) ( $line['text'] ?? '' ) );

				// An empty line is a deletion expressed by clearing the box, which is what somebody
				// editing on a phone will do before they find the remove button.
				if ( '' === trim( $text ) ) {
					continue;
				}

				$groups[ $bucket ][] = [
					'key'    => sanitize_key( (string) ( $line['key'] ?? '' ) ),
					'panel'  => sanitize_text_field( (string) ( $line['panel'] ?? '' ) ),
					'source' => in_array( $line['source'] ?? '', [ 'item', 'upgrade', 'custom' ], true )
						? (string) $line['source']
						: 'custom',
					'text'   => $text,
					'photos' => array_values(
						array_filter( array_map( 'intval', (array) ( $line['photos'] ?? [] ) ) )
					),
				];
			}

			$groups[ $bucket ] = $groups[ $bucket ] ?? [];
		}

		$clean['groups'] = $groups;
		$clean['photos'] = self::sanitize_photos( (array) ( $incoming['photos'] ?? [] ), $survey_id );

		return $clean;
	}

	/**
	 * Photos must belong to this survey.
	 *
	 * Without the parent check a reviewer could put any attachment id in the gallery — including one
	 * from another customer's survey, which would then be served to a stranger over a public link.
	 *
	 * @param array<int, mixed> $incoming
	 * @return list<array<string, mixed>>
	 */
	private static function sanitize_photos( array $incoming, int $survey_id ): array {
		$owned  = PhotoService::for_survey( $survey_id );
		$chosen = [];
		$seen   = [];

		foreach ( $incoming as $photo ) {
			if ( count( $chosen ) >= self::MAX_PHOTOS ) {
				break;
			}

			$id = (int) ( is_array( $photo ) ? ( $photo['id'] ?? 0 ) : $photo );

			if ( ! isset( $owned[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$chosen[] = [
				'id'      => $id,
				'caption' => sanitize_text_field(
					(string) ( is_array( $photo ) ? ( $photo['caption'] ?? '' ) : '' )
				),
			];
		}

		return $chosen;
	}

	// ------------------------------------------------------------------ lifecycle.

	/**
	 * Mint a share token and mark the proposal sent.
	 *
	 * The token is returned once, in plaintext, and never stored that way. What goes in the database
	 * is a SHA-256 hash, so a database read — a backup on a laptop, a leaked dump — does not hand
	 * somebody every customer's proposal. This is the same reasoning as storing password hashes, and
	 * it costs nothing here because the token is only ever compared, never displayed again.
	 *
	 * @return array{token: string, url: string, expires: string}
	 */
	public static function mint_token( int $survey_id ): array {
		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$days  = max( 1, (int) SettingsPage::value( 'token_expiry_days' ) );
		$until = time() + ( $days * DAY_IN_SECONDS );

		update_post_meta( $survey_id, Meta::PROPOSAL_TOKEN, hash( 'sha256', $token ) );
		update_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES, $until );

		return [
			'token'   => $token,
			'url'     => self::url( $token ),
			'expires' => gmdate( 'c', $until ),
		];
	}

	public static function url( string $token ): string {
		return home_url( '/proposal/' . $token . '/' );
	}

	/**
	 * Resolve a survey from a plaintext token.
	 *
	 * Looks up by hash rather than scanning and comparing in PHP: the database compares the hash of
	 * the presented token against the stored hash, so there is no secret-dependent branch in our code
	 * to time. An expired token resolves to nothing at all — the page cannot distinguish "expired"
	 * from "never existed", which is the right amount of information to give an anonymous caller.
	 */
	public static function find_by_token( string $token ): ?int {
		$token = trim( $token );

		// Cheap shape check first, so a garbage path never reaches the database at all. This is the
		// route an anonymous internet caller can hit, so it is also the one worth keeping quiet.
		if ( ! preg_match( '/^[a-f0-9]{' . ( self::TOKEN_BYTES * 2 ) . '}$/', $token ) ) {
			return null;
		}

		$found = get_posts(
			[
				'post_type'        => SurveyPostType::POST_TYPE,
				// Explicitly, not 'any': WP_Query's `any` drops statuses flagged
				// exclude_from_search, which is exactly the accepted surveys whose proposals
				// have been sent. See Statuses::all().
				'post_status'      => Statuses::all(),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => [
					[
						'key'   => Meta::PROPOSAL_TOKEN,
						'value' => hash( 'sha256', $token ),
					],
				],
			]
		);

		if ( [] === $found ) {
			return null;
		}

		$survey_id = (int) $found[0];
		$expires   = (int) get_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES, true );

		if ( $expires > 0 && time() > $expires ) {
			return null;
		}

		return $survey_id;
	}

	public static function revoke_token( int $survey_id ): void {
		delete_post_meta( $survey_id, Meta::PROPOSAL_TOKEN );
		delete_post_meta( $survey_id, Meta::PROPOSAL_EXPIRES );
	}

	/**
	 * Move the proposal's status, recording when.
	 *
	 * @return array<string, mixed>
	 */
	public static function set_status( int $survey_id, string $status ): array {
		$proposal = self::get( $survey_id );

		$proposal['status'] = $status;
		$proposal[ $status . '_at' ] = current_time( 'mysql' );

		SurveyRepository::store_json( $survey_id, Meta::PROPOSAL, $proposal );

		return $proposal;
	}

	/**
	 * Record that the customer opened the link.
	 *
	 * Only ever moves sent → viewed. A customer re-reading a proposal they already signed must not
	 * knock it back down the funnel, and Josh wants to know when they *first* looked, not last.
	 */
	public static function mark_viewed( int $survey_id ): void {
		if ( self::SENT !== self::get( $survey_id )['status'] ) {
			return;
		}

		self::set_status( $survey_id, self::VIEWED );

		/**
		 * Fires the first time a customer opens their proposal.
		 *
		 * @param int $survey_id Survey id.
		 */
		do_action( 'pe_proposal_viewed', $survey_id );
	}
}
