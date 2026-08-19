<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Proposal;

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telling the company what the customer did.
 *
 * A signature is the moment the job becomes real work, and it happens when nobody is watching — a
 * homeowner opening an email at nine at night. Without this it would sit unnoticed until somebody
 * thought to check the list table.
 *
 * Plain text and internal, like the rest of the workflow mail. The customer-facing template lives in
 * {@see ProposalMailer}.
 */
final class Notifications {

	public function register(): void {
		add_action( 'pe_proposal_signed', [ $this, 'on_signed' ], 10, 3 );
		add_action( 'pe_proposal_declined', [ $this, 'on_declined' ], 10, 2 );
		add_action( 'pe_proposal_viewed', [ $this, 'on_viewed' ] );
	}

	public function on_signed( int $survey_id, string $name, string $via ): void {
		$this->notify(
			sprintf(
				/* translators: %s: customer name. */
				__( 'Proposal signed: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: 1: signer name, 2: 'the emailed link' or 'on site'. */
					__( '%1$s approved the scope of work %2$s.', 'paumalu-site-survey' ),
					$name,
					'onsite' === $via
						? __( 'on site', 'paumalu-site-survey' )
						: __( 'from the emailed link', 'paumalu-site-survey' )
				),
				'',
				$this->address_line( $survey_id ),
				'',
				__( 'This work is ready to schedule.', 'paumalu-site-survey' ),
				admin_url( 'post.php?post=' . $survey_id . '&action=edit' ),
			]
		);
	}

	public function on_declined( int $survey_id, string $reason ): void {
		$lines = [
			sprintf(
				/* translators: %s: customer name. */
				__( '%s declined the proposed work.', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			'',
			$this->address_line( $survey_id ),
		];

		if ( '' !== trim( $reason ) ) {
			$lines[] = '';
			$lines[] = __( 'They said:', 'paumalu-site-survey' );
			$lines[] = $reason;
		}

		$this->notify(
			sprintf(
				/* translators: %s: customer name. */
				__( 'Proposal declined: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			$lines
		);
	}

	/**
	 * A first open is worth knowing about — it is the difference between "they are thinking about it"
	 * and "it went to spam" — but it is not worth an email per refresh, which is why
	 * {@see Proposal::mark_viewed()} only ever fires this once.
	 */
	public function on_viewed( int $survey_id ): void {
		$this->notify(
			sprintf(
				/* translators: %s: customer name. */
				__( 'Proposal opened: %s', 'paumalu-site-survey' ),
				$this->customer( $survey_id )
			),
			[
				sprintf(
					/* translators: %s: customer name. */
					__( '%s has opened their action plan for the first time.', 'paumalu-site-survey' ),
					$this->customer( $survey_id )
				),
				'',
				$this->address_line( $survey_id ),
			]
		);
	}

	private function address_line( int $survey_id ): string {
		return sprintf(
			/* translators: %s: service address. */
			__( 'Address: %s', 'paumalu-site-survey' ),
			(string) get_post_meta( $survey_id, Meta::SERVICE_ADDRESS, true )
		);
	}

	private function customer( int $survey_id ): string {
		$name = (string) get_post_meta( $survey_id, Meta::CUSTOMER_NAME, true );

		return '' !== $name ? $name : __( 'unnamed customer', 'paumalu-site-survey' );
	}

	/**
	 * @param list<string> $lines
	 */
	private function notify( string $subject, array $lines ): void {
		$recipients = $this->recipients();

		if ( [] === $recipients ) {
			return;
		}

		$company = SettingsPage::value( 'company_name' );

		wp_mail(
			$recipients,
			'' !== $company ? sprintf( '[%s] %s', $company, $subject ) : $subject,
			implode( "\n", $lines ) . "\n"
		);
	}

	/**
	 * @return list<string>
	 */
	private function recipients(): array {
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
}
