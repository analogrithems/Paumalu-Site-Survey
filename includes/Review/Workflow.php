<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Review;

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\PostType\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reviewer's two decisions: hand it back, or accept it.
 *
 * Both live here rather than in the REST controller so the transition, the note and the meta
 * bookkeeping happen as one unit. A survey that changed status without recording who did it, or that
 * requested changes without saying what they were, is a support call.
 */
final class Workflow {

	/**
	 * Send a survey back to its author with an explanation.
	 *
	 * The note is required, and it is deliberately not optional-with-a-default. "Changes requested"
	 * with no body tells a technician standing in a driveway nothing about what to go back and do,
	 * and the whole point of this step is the round trip.
	 */
	public static function request_changes( int $survey_id, string $note ): array|\WP_Error {
		$note = trim( $note );

		if ( '' === $note ) {
			return new \WP_Error(
				'pe_note_required',
				__( 'Tell the technician what needs to change before sending it back.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$moved = self::transition( $survey_id, Statuses::CHANGES_REQUEST );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$added = Notes::add( $survey_id, $note, get_current_user_id(), 'changes_requested' );

		if ( is_wp_error( $added ) ) {
			return $added;
		}

		// The reviewer has handed it back, so "edited since I looked at it" no longer means anything
		// until it is resubmitted — and submit_item() takes a fresh snapshot at that point.
		delete_post_meta( $survey_id, Meta::DIRTY_SINCE_REVIEW );

		/**
		 * Fires when a reviewer sends a survey back for changes.
		 *
		 * @param int    $survey_id Survey id.
		 * @param string $note      The reviewer's explanation.
		 */
		do_action( 'pe_survey_changes_requested', $survey_id, $note );

		return [ 'note_id' => $added ];
	}

	/**
	 * Accept a survey, freezing the version that was accepted.
	 *
	 * Re-snapshotting here matters because Aaron chose to keep surveys editable by their author
	 * indefinitely. Without it the "changed since review" flag would keep comparing against whatever
	 * was submitted originally, so an edit made after acceptance — the one case where the drift
	 * actually costs something, because a proposal has already been built from the document — would
	 * be measured from the wrong baseline.
	 */
	public static function accept( int $survey_id, string $note = '' ): array|\WP_Error {
		$moved = self::transition( $survey_id, Statuses::ACCEPTED );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$note_id = 0;

		if ( '' !== trim( $note ) ) {
			$added = Notes::add( $survey_id, $note, get_current_user_id(), 'accepted' );

			if ( is_wp_error( $added ) ) {
				return $added;
			}

			$note_id = $added;
		}

		SurveyRepository::store_json( $survey_id, Meta::REVIEW_SNAPSHOT, SurveyRepository::get( $survey_id ) );
		delete_post_meta( $survey_id, Meta::DIRTY_SINCE_REVIEW );
		update_post_meta( $survey_id, Meta::ACCEPTED_AT, current_time( 'mysql' ) );

		/**
		 * Fires when a survey is accepted and is ready for a proposal.
		 *
		 * @param int $survey_id Survey id.
		 */
		do_action( 'pe_survey_accepted', $survey_id );

		return [ 'note_id' => $note_id ];
	}

	/**
	 * Move a survey, refusing anything the transition table does not allow.
	 */
	private static function transition( int $survey_id, string $to ): true|\WP_Error {
		$from = (string) get_post_status( $survey_id );

		if ( ! Statuses::can_transition( $from, $to, $survey_id ) ) {
			return new \WP_Error(
				'pe_invalid_transition',
				sprintf(
					/* translators: 1: current status label, 2: requested status label. */
					__( 'A survey cannot move from %1$s to %2$s.', 'paumalu-site-survey' ),
					Statuses::labels()[ $from ] ?? $from,
					Statuses::labels()[ $to ] ?? $to
				),
				[ 'status' => 409 ]
			);
		}

		$updated = wp_update_post(
			[
				'ID'          => $survey_id,
				'post_status' => $to,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta( $survey_id, Meta::REVIEWED_AT, current_time( 'mysql' ) );
		update_post_meta( $survey_id, Meta::REVIEWED_BY, get_current_user_id() );

		return true;
	}

	/**
	 * The diff, rendered in words a reviewer can act on.
	 *
	 * SurveyRepository::diff_since_submission() returns raw keys and status codes, which is the right
	 * shape to store but useless in a banner. Resolving the labels server-side keeps the panel-id
	 * lookup — which needs the live document — out of the client.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function describe_changes( int $survey_id ): array {
		$changes = SurveyRepository::diff_since_submission( $survey_id );

		if ( [] === $changes ) {
			return [];
		}

		$items  = Catalog::items( SurveyRepository::catalog_version( $survey_id ) );
		$doc    = SurveyRepository::get( $survey_id );
		$panels = [];

		foreach ( (array) ( $doc['panels'] ?? [] ) as $panel ) {
			$panels[ (string) ( $panel['id'] ?? '' ) ] = (string) ( $panel['label'] ?? '' );
		}

		$statuses = self::status_labels();

		return array_map(
			static function ( array $change ) use ( $items, $panels, $statuses ): array {
				$item  = $items[ $change['key'] ] ?? null;
				$panel = $change['panel'];

				return [
					'key'     => $change['key'],
					// An item key with no catalog entry is a survey written against an older version
					// whose item has since been retired. Show the key rather than an empty row.
					'label'   => $item['label'] ?? $change['key'],
					'section' => $item['section_label'] ?? '',
					'panel'   => null !== $panel ? ( $panels[ $panel ] ?? $panel ) : null,
					'from'    => $statuses[ $change['from'] ] ?? $statuses[''],
					'to'      => $statuses[ $change['to'] ] ?? $statuses[''],
					// True when the status itself moved. When it did not, the row would otherwise
					// read "Fail → Fail", which looks like a bug in the diff rather than an edit to
					// something else on the same answer.
					'status_changed' => $change['from'] !== $change['to'],
					'detail'  => self::describe_answer_edit( $change['before'], $change['after'] ),
				];
			},
			$changes
		);
	}

	/**
	 * What moved on an answer besides its status.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return list<string>
	 */
	private static function describe_answer_edit( array $before, array $after ): array {
		$detail = [];

		if ( ( $before['severity'] ?? '' ) !== ( $after['severity'] ?? '' ) ) {
			$severities = [
				''            => __( 'none', 'paumalu-site-survey' ),
				'immediate'   => __( 'Immediate', 'paumalu-site-survey' ),
				'recommended' => __( 'Recommended', 'paumalu-site-survey' ),
				'optional'    => __( 'Optional', 'paumalu-site-survey' ),
			];

			$detail[] = sprintf(
				/* translators: 1: previous priority, 2: new priority. */
				__( 'Priority %1$s → %2$s', 'paumalu-site-survey' ),
				$severities[ $before['severity'] ?? '' ] ?? $severities[''],
				$severities[ $after['severity'] ?? '' ] ?? $severities['']
			);
		}

		if ( trim( (string) ( $before['note'] ?? '' ) ) !== trim( (string) ( $after['note'] ?? '' ) ) ) {
			$detail[] = '' === trim( (string) ( $before['note'] ?? '' ) )
				? __( 'Note added', 'paumalu-site-survey' )
				: __( 'Note edited', 'paumalu-site-survey' );
		}

		$was = count( (array) ( $before['photos'] ?? [] ) );
		$now = count( (array) ( $after['photos'] ?? [] ) );

		if ( $was !== $now ) {
			$detail[] = $now > $was
				? sprintf(
					/* translators: %d: number of photos added. */
					_n( '%d photo added', '%d photos added', $now - $was, 'paumalu-site-survey' ),
					$now - $was
				)
				: sprintf(
					/* translators: %d: number of photos removed. */
					_n( '%d photo removed', '%d photos removed', $was - $now, 'paumalu-site-survey' ),
					$was - $now
				);
		}

		return $detail;
	}

	/**
	 * @return array<string, string>
	 */
	private static function status_labels(): array {
		return [
			''     => __( 'Unanswered', 'paumalu-site-survey' ),
			'pass' => __( 'Pass', 'paumalu-site-survey' ),
			'fail' => __( 'Fail', 'paumalu-site-survey' ),
			'na'   => __( 'N/A', 'paumalu-site-survey' ),
		];
	}
}
