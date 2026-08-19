<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Review;

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Frontend\Router;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow email: the handoffs that would otherwise need a phone call.
 *
 * Three moments matter — a survey arriving for review, a survey being sent back, and a survey being
 * accepted. Everything else in the thread is a note somebody will see next time they open the app.
 *
 * Plain text, deliberately. These go to a manager's phone between site visits and to a technician's
 * phone in a truck; an HTML template buys nothing here and gives spam filters something to dislike.
 * The customer-facing proposal email is a different problem and gets its own template.
 */
final class Notifications {

	public function register(): void {
		add_action( 'pe_survey_submitted', [ $this, 'on_submitted' ] );
		add_action( 'pe_survey_changes_requested', [ $this, 'on_changes_requested' ], 10, 2 );
		add_action( 'pe_survey_accepted', [ $this, 'on_accepted' ] );
		add_action( 'pe_survey_note_added', [ $this, 'on_note_added' ], 10, 4 );
	}

	public function on_submitted( int $survey_id ): void {
		$recipients = $this->reviewer_emails();

		if ( [] === $recipients ) {
			return;
		}

		$post   = get_post( $survey_id );
		$author = get_userdata( (int) $post->post_author );
		$counts = get_post_meta( $survey_id, Meta::FAIL_COUNTS, true );
		$counts = is_array( $counts ) ? $counts : [];

		$this->send(
			$recipients,
			sprintf(
				/* translators: %s: customer name. */
				__( 'Site survey ready for review: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: 1: technician name, 2: customer name. */
					__( '%1$s has submitted a site survey for %2$s.', 'paumalu-site-survey' ),
					$author ? $author->display_name : __( 'A technician', 'paumalu-site-survey' ),
					$this->customer( $survey_id )
				),
				'',
				sprintf(
					/* translators: %s: service address. */
					__( 'Address: %s', 'paumalu-site-survey' ),
					(string) get_post_meta( $survey_id, Meta::SERVICE_ADDRESS, true )
				),
				sprintf(
					/* translators: 1: immediate count, 2: recommended count, 3: optional count. */
					__( 'Findings: %1$d immediate, %2$d recommended, %3$d optional.', 'paumalu-site-survey' ),
					(int) ( $counts['immediate'] ?? 0 ),
					(int) ( $counts['recommended'] ?? 0 ),
					(int) ( $counts['optional'] ?? 0 )
				),
				'',
				__( 'Review it here:', 'paumalu-site-survey' ),
				Router::url( $survey_id . '/review/' ),
			]
		);
	}

	public function on_changes_requested( int $survey_id, string $note ): void {
		$this->notify_author(
			$survey_id,
			sprintf(
				/* translators: %s: customer name. */
				__( 'Changes requested on your site survey: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: %s: reviewer name. */
					__( '%s has sent your survey back with notes.', 'paumalu-site-survey' ),
					$this->actor()
				),
				'',
				$note,
				'',
				__( 'Open it here:', 'paumalu-site-survey' ),
				Router::url( $survey_id . '/' ),
			]
		);
	}

	public function on_accepted( int $survey_id ): void {
		$this->notify_author(
			$survey_id,
			sprintf(
				/* translators: %s: customer name. */
				__( 'Site survey accepted: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: 1: reviewer name, 2: customer name. */
					__( '%1$s has accepted your survey for %2$s. Nothing further is needed from you.', 'paumalu-site-survey' ),
					$this->actor(),
					$this->customer( $survey_id )
				),
				'',
				Router::url( $survey_id . '/' ),
			]
		);
	}

	/**
	 * A plain note emails the other side of the conversation.
	 *
	 * Notes attached to a status change are skipped: request_changes() and accept() already send a
	 * message carrying the same text, and nobody wants the same note twice.
	 */
	public function on_note_added( int $note_id, int $survey_id, int $user_id, string $event ): void {
		if ( '' !== $event ) {
			return;
		}

		$comment = get_comment( $note_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$post   = get_post( $survey_id );
		$author = (int) $post->post_author;

		// A reviewer's note goes to the technician; the technician's answer goes to the reviewers.
		$recipients = $user_id === $author ? $this->reviewer_emails() : $this->author_email( $survey_id );

		if ( [] === $recipients ) {
			return;
		}

		$this->send(
			$recipients,
			sprintf(
				/* translators: %s: customer name. */
				__( 'New note on site survey: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: %s: note author name. */
					__( 'From %s:', 'paumalu-site-survey' ),
					$comment->comment_author
				),
				'',
				$comment->comment_content,
				'',
				Router::url( $survey_id . ( $user_id === $author ? '/review/' : '/' ) ),
			]
		);
	}

	// --------------------------------------------------------------- helpers.

	/**
	 * @param list<string> $lines
	 */
	private function notify_author( int $survey_id, string $subject, array $lines ): void {
		$recipients = $this->author_email( $survey_id );

		if ( [] !== $recipients ) {
			$this->send( $recipients, $subject, $lines );
		}
	}

	/**
	 * @return list<string>
	 */
	private function author_email( int $survey_id ): array {
		$post = get_post( $survey_id );
		$user = $post ? get_userdata( (int) $post->post_author ) : null;

		return $user && is_email( $user->user_email ) ? [ $user->user_email ] : [];
	}

	/**
	 * Who hears about a submission.
	 *
	 * The settings field is the source of truth so Aaron can point this at whoever is covering
	 * review that week. If it has been emptied, fall back to every account that actually holds the
	 * review capability rather than to the site admin address — the point is to reach a person who
	 * can act on it.
	 *
	 * @return list<string>
	 */
	private function reviewer_emails(): array {
		$configured = array_filter(
			array_map( 'trim', explode( ',', SettingsPage::value( 'notify_emails' ) ) ),
			static fn( string $email ): bool => false !== is_email( $email )
		);

		if ( [] !== $configured ) {
			return array_values( $configured );
		}

		$emails = [];

		foreach ( get_users( [ 'fields' => [ 'user_email', 'ID' ] ] ) as $user ) {
			if ( user_can( (int) $user->ID, Capabilities::REVIEW ) && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	private function customer( int $survey_id ): string {
		$name = (string) get_post_meta( $survey_id, Meta::CUSTOMER_NAME, true );

		return '' !== $name ? $name : __( 'unnamed customer', 'paumalu-site-survey' );
	}

	private function actor(): string {
		$user = wp_get_current_user();

		return $user && $user->ID ? $user->display_name : __( 'A reviewer', 'paumalu-site-survey' );
	}

	/**
	 * @param list<string> $to
	 * @param list<string> $lines
	 */
	private function send( array $to, string $subject, array $lines ): void {
		$company = SettingsPage::value( 'company_name' );

		wp_mail(
			$to,
			'' !== $company ? sprintf( '[%s] %s', $company, $subject ) : $subject,
			implode( "\n", $lines ) . "\n"
		);
	}
}
