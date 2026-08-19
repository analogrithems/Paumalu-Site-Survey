<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Review;

use Paumalu\SiteSurvey\PostType\SurveyPostType;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two-way note thread between a technician and their reviewer.
 *
 * Stored as comments on the survey post rather than in a bespoke table: WordPress already gives us
 * threading, authorship, timestamps, indexed lookup by post, and deletion that cascades when a survey
 * is removed. A custom table would be a schema migration and a dbDelta call to reimplement all of it.
 *
 * The cost of that reuse is that notes are now sitting in the same table as public blog comments, so
 * everything here is about keeping the two apart — see {@see self::hide_from_other_queries()}.
 */
final class Notes {

	/** Comment type. Anything that is not 'comment' is invisible to the front-end comment template. */
	public const TYPE = 'pe_note';

	public function register(): void {
		add_action( 'pre_get_comments', [ self::class, 'hide_from_other_queries' ] );
		add_filter( 'comment_notification_recipients', [ self::class, 'suppress_core_notifications' ], 10, 2 );
		add_filter( 'comment_moderation_recipients', [ self::class, 'suppress_core_notifications' ], 10, 2 );
	}

	/**
	 * Keep survey notes out of every comment query that did not explicitly ask for them.
	 *
	 * Without this the wp-admin Comments screen lists a technician's private note about a customer's
	 * panel alongside blog comments, and any theme that calls get_comments() could render one. The
	 * default WP_Comment_Query type is empty, meaning "every type", so exclusion has to be opt-out
	 * rather than relying on the type being unrecognised.
	 */
	public static function hide_from_other_queries( \WP_Comment_Query $query ): void {
		$vars = $query->query_vars;

		// The plugin's own lookups ask for the type by name; leave those alone.
		if ( self::TYPE === ( $vars['type'] ?? '' ) || in_array( self::TYPE, (array) ( $vars['type__in'] ?? [] ), true ) ) {
			return;
		}

		$excluded = (array) ( $vars['type__not_in'] ?? [] );

		if ( ! in_array( self::TYPE, $excluded, true ) ) {
			$excluded[] = self::TYPE;
		}

		$query->query_vars['type__not_in'] = $excluded;
	}

	/**
	 * Notes generate their own targeted email, so core's must not fire as well.
	 *
	 * @param list<string> $emails
	 * @return list<string>
	 */
	public static function suppress_core_notifications( array $emails, int $comment_id ): array {
		$comment = get_comment( $comment_id );

		return $comment && self::TYPE === $comment->comment_type ? [] : $emails;
	}

	/**
	 * Add a note to a survey.
	 *
	 * wp_insert_comment() rather than wp_new_comment(): the latter runs the note through comment
	 * moderation, the disallowed-keyword list and any spam plugin installed on the site. A field note
	 * reading "found a dead short behind the meter" has no business in a moderation queue, and a
	 * technician would never find out it had been held there.
	 *
	 * @return int|\WP_Error The new note id.
	 */
	public static function add( int $survey_id, string $content, int $user_id = 0, string $event = '' ): int|\WP_Error {
		$content = trim( $content );

		if ( '' === $content ) {
			return new \WP_Error(
				'pe_note_empty',
				__( 'A note cannot be empty.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $survey_id );

		if ( ! $post instanceof \WP_Post || SurveyPostType::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'pe_survey_not_found',
				__( 'That survey does not exist.', 'paumalu-site-survey' ),
				[ 'status' => 404 ]
			);
		}

		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$user    = get_userdata( $user_id );

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $survey_id,
				'comment_type'         => self::TYPE,
				'comment_content'      => wp_kses_post( $content ),
				'comment_author'       => $user ? $user->display_name : '',
				'comment_author_email' => $user ? $user->user_email : '',
				'user_id'              => $user_id,
				// Approved on insert, always. Notes are written by authenticated staff, and leaving
				// them unapproved would park them in the site's moderation bubble.
				'comment_approved'     => 1,
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', 1 ),
			]
		);

		if ( ! $comment_id ) {
			return new \WP_Error(
				'pe_note_failed',
				__( 'That note could not be saved.', 'paumalu-site-survey' ),
				[ 'status' => 500 ]
			);
		}

		// Notes attached to a status change are rendered differently — "requested changes" reads very
		// differently from a passing remark, and the thread should show which is which.
		if ( '' !== $event ) {
			add_comment_meta( $comment_id, '_pe_note_event', sanitize_key( $event ) );
		}

		/**
		 * Fires after a note has been added to a survey.
		 *
		 * @param int    $comment_id Note id.
		 * @param int    $survey_id  Survey id.
		 * @param int    $user_id    Author of the note.
		 * @param string $event      Status change this note accompanied, if any.
		 */
		do_action( 'pe_survey_note_added', (int) $comment_id, $survey_id, $user_id, $event );

		return (int) $comment_id;
	}

	/**
	 * Every note on a survey, oldest first — a conversation reads downward.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function for_survey( int $survey_id ): array {
		$comments = get_comments(
			[
				'post_id' => $survey_id,
				'type'    => self::TYPE,
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			]
		);

		return array_map( [ self::class, 'prepare' ], $comments );
	}

	public static function count( int $survey_id ): int {
		return (int) get_comments(
			[
				'post_id' => $survey_id,
				'type'    => self::TYPE,
				'status'  => 'approve',
				'count'   => true,
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function prepare( \WP_Comment $comment ): array {
		$user_id = (int) $comment->user_id;

		return [
			'id'      => (int) $comment->comment_ID,
			'content' => $comment->comment_content,
			'event'   => (string) get_comment_meta( (int) $comment->comment_ID, '_pe_note_event', true ),
			'author'  => [
				'id'   => $user_id,
				'name' => $comment->comment_author,
				// Drives which side of the thread the note renders on. Resolved from the capability
				// now rather than stored at write time, so a role change does not leave old notes
				// mislabelled.
				'is_reviewer' => $user_id > 0 && user_can( $user_id, Capabilities::REVIEW ),
			],
			'created' => mysql2date( 'c', $comment->comment_date_gmt, false ),
		];
	}
}
