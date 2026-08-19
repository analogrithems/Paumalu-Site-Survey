<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Proposal;

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capturing and storing a customer's signature.
 *
 * Everything here is reachable by an anonymous caller holding a valid token, which makes it the most
 * exposed surface in the plugin. It accepts exactly one thing — a small PNG drawn on a canvas — and
 * is deliberately unforgiving about anything that is not that.
 */
final class Signature {

	/**
	 * A signature is a few strokes of transparent line art. Real ones from the pad land around 6-15KB;
	 * the cap is generous enough that a large tablet cannot trip it and small enough that this is not
	 * an unauthenticated file upload endpoint wearing a disguise.
	 */
	private const MAX_BYTES = 400000;

	/**
	 * Dimension bounds, checked before anything decodes the pixels.
	 *
	 * A pad on the largest tablet anybody will hand a customer is well under 4000px even at 3x. The
	 * ceiling is here so a header claiming an enormous image is refused on the strength of the header,
	 * before imagecreatefromstring() is asked to allocate for it — otherwise the last validation step
	 * would itself be the decompression bomb.
	 */
	private const MAX_DIMENSION = 4000;

	private const SIGNED_VIA = [ 'link', 'onsite' ];

	/**
	 * Record a signature against a proposal.
	 *
	 * @param array{name: string, image: string, via: string} $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function record( int $survey_id, array $input ): array|\WP_Error {
		$proposal = Proposal::get( $survey_id );

		// Signing twice is not an update, it is a second person signing over the first. The first
		// signature is the one that was given in good faith, so it wins.
		if ( Proposal::SIGNED === $proposal['status'] ) {
			return new \WP_Error(
				'pe_already_signed',
				__( 'This proposal has already been signed.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		if ( Proposal::DRAFT === $proposal['status'] ) {
			return new \WP_Error(
				'pe_proposal_not_sent',
				__( 'This proposal is not ready to be signed.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		$name = sanitize_text_field( trim( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			return new \WP_Error(
				'pe_name_required',
				__( 'Please type your name to sign.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$image         = trim( (string) ( $input['image'] ?? '' ) );
		$attachment_id = 0;
		$method        = 'typed';

		// A drawn mark is better evidence, but it is not the thing that makes this a signature. Under
		// the E-SIGN Act what counts is a deliberate act adopting the document, and a typed name
		// submitted with intent, timestamp and IP is exactly that. Requiring the canvas would mean a
		// customer whose phone failed to load one JavaScript file cannot approve the work at all —
		// trading a real loss for a marginal gain in evidentiary weight.
		if ( '' !== $image ) {
			$png = self::decode_png( $image );

			if ( is_wp_error( $png ) ) {
				return $png;
			}

			$stored = self::store_image( $survey_id, $png, $name );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			$attachment_id = $stored;
			$method        = 'drawn';
		}

		$via = in_array( $input['via'] ?? '', self::SIGNED_VIA, true ) ? (string) $input['via'] : 'link';

		$proposal['status']    = Proposal::SIGNED;
		$proposal['signed_at'] = current_time( 'mysql' );
		$proposal['signature'] = [
			'name'          => $name,
			'attachment_id' => $attachment_id,
			// 'drawn' or 'typed'. Recorded because the two are not equally strong evidence, and six
			// months from now nobody will remember which one this was from the row alone.
			'method'        => $method,
			'signed_at'     => current_time( 'mysql' ),
			'via'           => $via,
			// Kept as evidence of who approved the scope and from where. This is the whole reason a
			// typed name alone is not enough: a dispute six months later is settled by the record,
			// not by anybody's memory of the conversation.
			'ip'            => self::client_ip(),
			'user_agent'    => substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
		];

		SurveyRepository::store_json( $survey_id, Meta::PROPOSAL, $proposal );

		// The link deliberately stays live.
		//
		// It used to be revoked here, on the reasoning that a forwarded email should stop working once
		// the document is signed. That reasoning does not survive contact with the redirect: the page
		// posts back to itself, so the customer was sent straight to the URL that had just been killed
		// and the last thing they saw after putting their name to the scope of work was "This link is
		// no longer valid."
		//
		// And the security it bought was close to nothing. Anyone holding the link could already read
		// the document before it was signed; signing changes no exposure. What it did cost was the
		// customer's own receipt — the only copy they have of what they agreed to. A signed proposal is
		// immutable, cannot be signed twice and cannot be declined, so what remains at that URL is a
		// read-only record, which is exactly what they should keep.
		//
		// Resending still mints a fresh token, and minting revokes the old one, so a superseded link
		// dies the moment a replacement is issued.

		/**
		 * Fires when a customer signs a proposal.
		 *
		 * @param int    $survey_id Survey id.
		 * @param string $name      The signer's typed name.
		 * @param string $via       'link' or 'onsite'.
		 */
		do_action( 'pe_proposal_signed', $survey_id, $name, $via );

		return $proposal;
	}

	/**
	 * Record a customer declining, which is information rather than a failure.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function decline( int $survey_id, string $reason = '' ): array|\WP_Error {
		$proposal = Proposal::get( $survey_id );

		if ( Proposal::SIGNED === $proposal['status'] ) {
			return new \WP_Error(
				'pe_already_signed',
				__( 'This proposal has already been signed.', 'paumalu-site-survey' ),
				[ 'status' => 409 ]
			);
		}

		$proposal['status']       = Proposal::DECLINED;
		$proposal['declined_at']  = current_time( 'mysql' );
		$proposal['decline_note'] = sanitize_textarea_field( trim( $reason ) );

		SurveyRepository::store_json( $survey_id, Meta::PROPOSAL, $proposal );

		/**
		 * Fires when a customer declines a proposal.
		 *
		 * @param int    $survey_id Survey id.
		 * @param string $reason    Optional reason the customer gave.
		 */
		do_action( 'pe_proposal_declined', $survey_id, $proposal['decline_note'] );

		return $proposal;
	}

	/**
	 * Turn the canvas data URL into raw PNG bytes, refusing anything else.
	 *
	 * The checks are layered on purpose. The prefix match rejects a data URL claiming another type;
	 * strict base64 decoding rejects padding tricks; the magic-byte check rejects a payload that
	 * merely *claimed* to be a PNG in its header; getimagesizefromstring() plus a dimension bound
	 * rejects an absurd or hostile header cheaply; and a real decode rejects everything left — a file
	 * whose magic bytes were borrowed, a polyglot that also parses as a script, or eight good bytes
	 * followed by nothing at all.
	 *
	 * @return string|\WP_Error
	 */
	private static function decode_png( string $data_url ): string|\WP_Error {
		$invalid = new \WP_Error(
			'pe_bad_signature_image',
			__( 'That signature could not be read. Please try signing again.', 'paumalu-site-survey' ),
			[ 'status' => 400 ]
		);

		if ( ! str_starts_with( $data_url, 'data:image/png;base64,' ) ) {
			return $invalid;
		}

		$encoded = substr( $data_url, strlen( 'data:image/png;base64,' ) );

		// Bound the *encoded* length before decoding, so a hostile payload is rejected before it is
		// expanded in memory rather than after.
		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			return $invalid;
		}

		$binary = base64_decode( $encoded, true );

		if ( false === $binary || '' === $binary ) {
			return $invalid;
		}

		if ( ! str_starts_with( $binary, "\x89PNG\r\n\x1a\n" ) ) {
			return $invalid;
		}

		$size = @getimagesizefromstring( $binary );

		if ( false === $size || IMAGETYPE_PNG !== ( $size[2] ?? 0 ) ) {
			return $invalid;
		}

		$width  = (int) ( $size[0] ?? 0 );
		$height = (int) ( $size[1] ?? 0 );

		if ( $width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION ) {
			return $invalid;
		}

		// getimagesizefromstring() is not the guarantee it looks like: it reads the IHDR fields and
		// believes them, without verifying the chunk CRC or that any pixel data follows. Eight valid
		// magic bytes followed by "AAAA..." is reported back as a legitimate 1094795585-pixel-square
		// PNG. Only actually decoding proves there is an image here, so that is what we do — after
		// the dimension check above has made decoding safe.
		$image = @imagecreatefromstring( $binary );

		if ( false === $image ) {
			return $invalid;
		}

		imagedestroy( $image );

		return $binary;
	}

	/**
	 * Write the PNG into the media library, attached to the survey.
	 *
	 * @return int|\WP_Error
	 */
	private static function store_image( int $survey_id, string $png, string $name ): int|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_upload_bits( 'signature-' . $survey_id . '-' . time() . '.png', null, $png );

		if ( ! empty( $uploaded['error'] ) ) {
			return new \WP_Error(
				'pe_signature_not_saved',
				(string) $uploaded['error'],
				[ 'status' => 500 ]
			);
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => sprintf(
					/* translators: %s: the signer's name. */
					__( 'Signature — %s', 'paumalu-site-survey' ),
					$name
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$uploaded['file'],
			$survey_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] )
		);

		// Flagged so the photo gallery and the technician's photo grid never offer a signature as
		// evidence of a corroded neutral bar.
		update_post_meta( $attachment_id, Meta::SIGNATURE_IMAGE, 1 );

		return (int) $attachment_id;
	}

	/**
	 * Best-effort client IP.
	 *
	 * REMOTE_ADDR only. The forwarded-for headers are trivially spoofed by the client and this site
	 * is not behind a proxy we control, so trusting them would mean recording whatever the signer
	 * felt like claiming — worse than recording the proxy's own address, because it looks equally
	 * authoritative in the record.
	 */
	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		return (string) filter_var( $ip, FILTER_VALIDATE_IP ) ?: '';
	}
}
