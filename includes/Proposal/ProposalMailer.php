<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Proposal;

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one email in this plugin that goes to a customer.
 *
 * Everything else is internal plain text. This one is read by a homeowner who has met a technician
 * once, so it is short, says who it is from, and has exactly one thing to click. It is deliberately
 * not the proposal itself: a summary in the email and the detail behind a link means the record of
 * what was approved lives in one place, and that place can record having been read and signed.
 */
final class ProposalMailer {

	private const LOG_KEEP = 20;

	/**
	 * @return array{sent_to: string, expires: string}|\WP_Error
	 */
	public static function send( int $survey_id, string $override = '' ): array|\WP_Error {
		$doc   = SurveyRepository::get( $survey_id );
		$email = '' !== trim( $override )
			? sanitize_email( trim( $override ) )
			: sanitize_email( (string) ( $doc['customer']['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return new \WP_Error(
				'pe_no_customer_email',
				__( 'This survey has no valid customer email address. Add one before sending.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$link     = Proposal::mint_token( $survey_id );
		$proposal = Proposal::get( $survey_id );
		$company  = SettingsPage::value( 'company_name' );
		$customer = trim( (string) ( $doc['customer']['name'] ?? '' ) );

		$subject = sprintf(
			/* translators: %s: company name. */
			__( 'Your electrical inspection results from %s', 'paumalu-site-survey' ),
			'' !== $company ? $company : __( 'Paumalu Electric', 'paumalu-site-survey' )
		);

		// wp_mail() only reports whether PHPMailer accepted the message for handoff, not whether the
		// recipient's server took it — there is no bounce feedback loop here. This hook is the one
		// extra signal available: it fires when the mail transport itself refused the attempt (bad
		// address, connection refused, SMTP rejection at RCPT TO if SMTP is configured).
		$mail_error = '';
		$capture    = static function ( \WP_Error $error ) use ( &$mail_error ): void {
			$mail_error = $error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			$email,
			$subject,
			self::body( $customer, $proposal, $link['url'], $link['expires'] ),
			[
				'Content-Type: text/html; charset=UTF-8',
				// A homeowner replying to this should reach a person, not a no-reply mailbox on a
				// server. Whoever is on the notification list is who they get.
				...self::reply_to(),
				...self::from(),
			]
		);

		remove_action( 'wp_mail_failed', $capture );

		self::record( $survey_id, $email, $sent, $mail_error );

		if ( ! $sent ) {
			// The token was minted before the send. Revoking it on failure keeps a live link from
			// existing for a proposal that nobody has actually been told about.
			Proposal::revoke_token( $survey_id );

			return new \WP_Error(
				'pe_mail_failed',
				__( 'The proposal could not be emailed. Check the site mail configuration and try again.', 'paumalu-site-survey' ),
				[ 'status' => 500 ]
			);
		}

		Proposal::set_status( $survey_id, Proposal::SENT );
		update_post_meta( $survey_id, Meta::PROPOSAL_SENT_TO, $email );

		/**
		 * Fires after a proposal has been emailed to a customer.
		 *
		 * @param int    $survey_id Survey id.
		 * @param string $email     Address it went to.
		 */
		do_action( 'pe_proposal_sent', $survey_id, $email );

		return [
			'sent_to' => $email,
			'expires' => $link['expires'],
		];
	}

	/**
	 * Every attempt to email this proposal, oldest first — a wrong address is only fixable if Josh
	 * can see what was actually sent and where, then correct it and send again.
	 */
	private static function record( int $survey_id, string $email, bool $success, string $error ): void {
		$raw     = get_post_meta( $survey_id, Meta::PROPOSAL_EMAIL_LOG, true );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : [];
		$entries = is_array( $decoded ) ? $decoded : [];

		$entries[] = [
			'to'      => $email,
			'at'      => time(),
			'success' => $success,
			'error'   => $error,
		];

		SurveyRepository::store_json(
			$survey_id,
			Meta::PROPOSAL_EMAIL_LOG,
			array_slice( $entries, -self::LOG_KEEP )
		);
	}

	/**
	 * @return list<array{to: string, at: int, success: bool, error: string}>
	 */
	public static function log( int $survey_id ): array {
		$raw     = get_post_meta( $survey_id, Meta::PROPOSAL_EMAIL_LOG, true );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : [];
		$entries = is_array( $decoded ) ? $decoded : [];

		return array_reverse( $entries );
	}

	/**
	 * @return list<string>
	 */
	private static function reply_to(): array {
		$emails = array_filter(
			array_map( 'trim', explode( ',', SettingsPage::value( 'notify_emails' ) ) ),
			static fn( string $email ): bool => false !== is_email( $email )
		);

		return [] === $emails ? [] : [ 'Reply-To: ' . reset( $emails ) ];
	}

	/**
	 * @return list<string>
	 */
	private static function from(): array {
		$email = SettingsPage::value( 'from_email' );

		if ( '' === $email ) {
			return [];
		}

		$name = SettingsPage::value( 'company_name' );

		return [ 'From: ' . ( '' !== $name ? $name . ' <' . $email . '>' : $email ) ];
	}

	/**
	 * @param array<string, mixed> $proposal
	 */
	private static function body( string $customer, array $proposal, string $url, string $expires ): string {
		$company   = SettingsPage::value( 'company_name' );
		$phone     = SettingsPage::value( 'phone' );
		$license   = SettingsPage::value( 'license_number' );
		$immediate = count( (array) ( $proposal['groups'][ Proposal::IMMEDIATE ] ?? [] ) );
		$total     = 0;

		foreach ( (array) ( $proposal['groups'] ?? [] ) as $lines ) {
			$total += count( (array) $lines );
		}

		$greeting = '' !== $customer
			? sprintf(
				/* translators: %s: customer's name. */
				__( 'Hello %s,', 'paumalu-site-survey' ),
				$customer
			)
			: __( 'Hello,', 'paumalu-site-survey' );

		$summary = sprintf(
			/* translators: %d: total number of recommended items. */
			_n(
				'We have finished your electrical inspection and put together an action plan with %d recommended item.',
				'We have finished your electrical inspection and put together an action plan with %d recommended items.',
				$total,
				'paumalu-site-survey'
			),
			$total
		);

		// Named separately rather than folded into the summary: if something on the property is
		// genuinely unsafe, that fact should not be a clause in the middle of a sentence.
		$urgent = $immediate > 0
			? '<p style="margin:0 0 16px;padding:12px 14px;background:#fdf2f2;border-left:4px solid #c0392b;color:#7b241c;">'
				. esc_html(
					sprintf(
						/* translators: %d: number of immediate-priority findings. */
						_n(
							'%d of these is a safety concern we recommend addressing right away.',
							'%d of these are safety concerns we recommend addressing right away.',
							$immediate,
							'paumalu-site-survey'
						),
						$immediate
					)
				)
				. '</p>'
			: '';

		$expiry_note = '';

		if ( '' !== $expires ) {
			$stamp = strtotime( $expires );

			if ( false !== $stamp ) {
				$expiry_note = '<p style="margin:0 0 8px;color:#6b7c85;font-size:13px;">'
					. esc_html(
						sprintf(
							/* translators: %s: expiry date. */
							__( 'This link works until %s.', 'paumalu-site-survey' ),
							wp_date( 'F j, Y', $stamp )
						)
					)
					. '</p>';
			}
		}

		$footer = array_filter(
			[
				'' !== $company ? esc_html( $company ) : '',
				'' !== $phone ? esc_html( $phone ) : '',
				'' !== $license
					? esc_html(
						sprintf(
							/* translators: %s: contractor license number. */
							__( 'License %s', 'paumalu-site-survey' ),
							$license
						)
					)
					: '',
			]
		);

		return '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f4f6f7;'
			. 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
			. 'color:#1d2d35;line-height:1.5;">'
			. '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;">'
			. '<p style="margin:0 0 16px;">' . esc_html( $greeting ) . '</p>'
			. '<p style="margin:0 0 16px;">' . esc_html( $summary ) . '</p>'
			. $urgent
			. '<p style="margin:24px 0;text-align:center;">'
			. '<a href="' . esc_url( $url ) . '" '
			. 'style="display:inline-block;padding:14px 28px;background:#12455f;color:#fff;'
			. 'text-decoration:none;border-radius:6px;font-weight:600;">'
			. esc_html__( 'View your action plan', 'paumalu-site-survey' )
			. '</a></p>'
			. $expiry_note
			. '<p style="margin:16px 0 0;color:#6b7c85;font-size:13px;">'
			. esc_html__( 'You can review the findings, see the photos we took, and approve the work from that page. Reply to this email if you have any questions.', 'paumalu-site-survey' )
			. '</p>'
			. '<hr style="border:0;border-top:1px solid #e3e9ec;margin:24px 0 12px;">'
			. '<p style="margin:0;color:#6b7c85;font-size:12px;">' . implode( ' &middot; ', $footer ) . '</p>'
			. '</div></body></html>';
	}
}
