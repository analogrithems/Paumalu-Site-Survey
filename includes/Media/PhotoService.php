<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Media;

use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspection photos: storing them, describing them, and taking them away again.
 *
 * A photo belongs to a survey through post_parent and to a specific punch-list answer through meta.
 * The answer document itself only ever holds attachment ids — the URLs are resolved at read time, so
 * a survey written today still renders correctly after a site move or an SSL change.
 */
final class PhotoService {

	public function register(): void {
		add_action( 'before_delete_post', [ self::class, 'purge_for_survey' ], 10, 2 );
	}

	/**
	 * Take a survey's photos with it when it is permanently deleted.
	 *
	 * Only on permanent deletion, never on trashing: a survey binned by a mistyped tap in the field
	 * has to be recoverable with its evidence intact, and WordPress leaves attachments alone when a
	 * parent is trashed precisely so that untrashing works.
	 */
	public static function purge_for_survey( int $post_id, ?\WP_Post $post = null ): void {
		$post ??= get_post( $post_id );

		if ( ! $post instanceof \WP_Post || SurveyPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		foreach ( array_keys( self::for_survey( $post_id ) ) as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * The client resizes to ~250KB before uploading. This ceiling is for the fallback path, where
	 * the browser could not decode the image and sent the original: still generous enough for a raw
	 * phone photo, tight enough that a video picked by mistake is rejected rather than uploaded over
	 * LTE.
	 */
	public const MAX_BYTES = 12582912; // 12 MB.

	/** Photos per answer. Beyond a handful nobody looks, and the payload stops being mobile-sized. */
	public const MAX_PER_ITEM = 6;

	/** The proposal gallery is a sales document, not an archive. */
	public const MAX_FEATURED = 4;

	/**
	 * Deliberately narrower than WordPress's default image set.
	 *
	 * No GIF, no BMP, no TIFF: they are never what a technician meant to send, and each one is a
	 * decoder the proposal template would have to survive.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_mimes(): array {
		return [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		];
	}

	/**
	 * Store an uploaded file against a survey answer.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
	 * @param array{item_key?: string, panel_id?: string, caption?: string}                   $args
	 */
	public static function attach( int $survey_id, array $file, array $args = [] ): int|\WP_Error {
		$survey = get_post( $survey_id );

		if ( ! $survey instanceof \WP_Post || SurveyPostType::POST_TYPE !== $survey->post_type ) {
			return new \WP_Error(
				'pe_survey_not_found',
				__( 'That survey does not exist.', 'paumalu-site-survey' ),
				[ 'status' => 404 ]
			);
		}

		$invalid = self::validate_file( $file );

		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		$item_key = sanitize_key( (string) ( $args['item_key'] ?? '' ) );
		$panel_id = sanitize_key( (string) ( $args['panel_id'] ?? '' ) );

		if ( '' !== $item_key ) {
			$existing = self::count_for_item( $survey_id, $item_key, $panel_id );

			if ( $existing >= self::MAX_PER_ITEM ) {
				return new \WP_Error(
					'pe_photo_limit',
					sprintf(
						/* translators: %d: maximum number of photos. */
						__( 'There are already %d photos on this item.', 'paumalu-site-survey' ),
						self::MAX_PER_ITEM
					),
					[ 'status' => 409 ]
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = [
			'test_form' => false,
			'mimes'     => self::allowed_mimes(),
		];

		// wp_handle_upload() insists on move_uploaded_file(), which is exactly right for a real
		// request and refuses to work anywhere else. The sideload variant is the same routine with
		// rename() instead, for the test harness and wp-cli. Choosing on is_uploaded_file() rather
		// than on a flag means the production path can never accidentally take the looser branch.
		$handled = is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) )
			? wp_handle_upload( $file, $overrides )
			: wp_handle_sideload( $file, $overrides );

		if ( ! is_array( $handled ) || isset( $handled['error'] ) ) {
			return new \WP_Error(
				'pe_upload_failed',
				(string) ( $handled['error'] ?? __( 'That photo could not be saved.', 'paumalu-site-survey' ) ),
				[ 'status' => 400 ]
			);
		}

		$caption = sanitize_text_field( (string) ( $args['caption'] ?? '' ) );

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $handled['type'],
				'post_title'     => self::title_for( $survey_id, $item_key, $handled['file'] ),
				'post_excerpt'   => $caption,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			],
			$handled['file'],
			$survey_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			// The bytes are already on disk; leaving them there would be a silent orphan.
			wp_delete_file( $handled['file'] );

			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $handled['file'] )
		);

		// The caption doubles as the alt text: a technician writing "Corroded neutral bar in main
		// panel" has already written the best description of the image that will ever exist.
		if ( '' !== $caption ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );
		}

		update_post_meta( $attachment_id, Meta::PHOTO_ITEM_KEY, $item_key );
		update_post_meta( $attachment_id, Meta::PHOTO_PANEL_ID, $panel_id );

		return $attachment_id;
	}

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
	 */
	private static function validate_file( array $file ): true|\WP_Error {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			$message = in_array( $error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true )
				? __( 'That photo is larger than this server accepts.', 'paumalu-site-survey' )
				: __( 'That photo did not finish uploading. Try again.', 'paumalu-site-survey' );

			return new \WP_Error( 'pe_upload_incomplete', $message, [ 'status' => 400 ] );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return new \WP_Error(
				'pe_upload_missing',
				__( 'No photo was received.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		$size = (int) ( $file['size'] ?? filesize( $tmp ) );

		if ( $size > self::MAX_BYTES ) {
			return new \WP_Error(
				'pe_upload_too_large',
				sprintf(
					/* translators: %s: human-readable file size, e.g. "12 MB". */
					__( 'Photos have to be under %s.', 'paumalu-site-survey' ),
					size_format( self::MAX_BYTES )
				),
				[ 'status' => 413 ]
			);
		}

		// Trust the bytes, not the declared type or the extension. getimagesize() returning false is
		// how a renamed .heic or a PHP file wearing a .jpg suffix gets caught before it is stored.
		$info = @getimagesize( $tmp );

		if ( false === $info || ! isset( $info['mime'] ) || ! in_array( $info['mime'], array_values( self::allowed_mimes() ), true ) ) {
			return new \WP_Error(
				'pe_upload_not_an_image',
				__( 'That file is not a JPEG, PNG or WebP image. If it came from an iPhone, open it once in Photos and try again.', 'paumalu-site-survey' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private static function title_for( int $survey_id, string $item_key, string $path ): string {
		$customer = (string) get_post_meta( $survey_id, Meta::CUSTOMER_NAME, true );
		$parts    = array_filter( [ $customer, str_replace( '_', ' ', $item_key ) ] );

		return '' !== implode( '', $parts )
			? implode( ' — ', $parts )
			: sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) );
	}

	// ------------------------------------------------------------------- reads.

	/**
	 * Every photo on a survey, keyed by attachment id.
	 *
	 * Returned as a map rather than a list so the client can look up the ids stored in an answer in
	 * constant time, and so an id pointing at a photo that has since been deleted simply resolves to
	 * nothing instead of rendering a broken image.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_survey( int $survey_id ): array {
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $survey_id,
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// A captured signature is attached to the survey exactly like a photo is, so it has
				// to be excluded by hand. Left in, it would show up in the technician's photo grid
				// and could be chosen for the customer-facing gallery.
				'meta_query'     => [
					[
						'key'     => Meta::SIGNATURE_IMAGE,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$photos = [];

		foreach ( $attachments as $attachment ) {
			$photos[ (int) $attachment->ID ] = self::prepare( $attachment );
		}

		return $photos;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function prepare( \WP_Post $attachment ): array {
		$id    = (int) $attachment->ID;
		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
		$large = wp_get_attachment_image_src( $id, 'large' );

		return [
			'id'       => $id,
			'url'      => (string) wp_get_attachment_url( $id ),
			'thumb'    => is_array( $thumb ) ? $thumb[0] : (string) wp_get_attachment_url( $id ),
			'large'    => is_array( $large ) ? $large[0] : (string) wp_get_attachment_url( $id ),
			'caption'  => (string) $attachment->post_excerpt,
			'item_key' => (string) get_post_meta( $id, Meta::PHOTO_ITEM_KEY, true ),
			'panel_id' => (string) get_post_meta( $id, Meta::PHOTO_PANEL_ID, true ),
			'featured' => (bool) get_post_meta( $id, Meta::PHOTO_FEATURED, true ),
			'survey'   => (int) $attachment->post_parent,
			'uploaded' => (string) get_post_time( 'c', true, $attachment ),
		];
	}

	private static function count_for_item( int $survey_id, string $item_key, string $panel_id ): int {
		$count = 0;

		foreach ( self::for_survey( $survey_id ) as $photo ) {
			if ( $photo['item_key'] === $item_key && $photo['panel_id'] === $panel_id ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @return list<int>
	 */
	public static function featured_ids( int $survey_id ): array {
		$ids = [];

		foreach ( self::for_survey( $survey_id ) as $id => $photo ) {
			if ( $photo['featured'] ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	// ------------------------------------------------------------------ writes.

	public static function set_featured( int $attachment_id, int $survey_id, bool $featured ): true|\WP_Error {
		if ( ! $featured ) {
			delete_post_meta( $attachment_id, Meta::PHOTO_FEATURED );

			return true;
		}

		$current = self::featured_ids( $survey_id );

		if ( ! in_array( $attachment_id, $current, true ) && count( $current ) >= self::MAX_FEATURED ) {
			return new \WP_Error(
				'pe_featured_limit',
				sprintf(
					/* translators: %d: maximum number of gallery photos. */
					__( 'The proposal gallery holds %d photos. Remove one first.', 'paumalu-site-survey' ),
					self::MAX_FEATURED
				),
				[ 'status' => 409 ]
			);
		}

		update_post_meta( $attachment_id, Meta::PHOTO_FEATURED, 1 );

		return true;
	}

	/**
	 * Delete a photo and cut it out of the answer document in the same breath.
	 *
	 * Doing both here rather than relying on the app to save afterwards means a delete that happens
	 * as the phone loses signal cannot leave the survey pointing at an attachment that is gone.
	 */
	public static function detach( int $attachment_id ): bool {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		$survey_id = (int) $attachment->post_parent;

		if ( $survey_id > 0 ) {
			self::forget_in_document( $survey_id, $attachment_id );
		}

		return null !== wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Strip an attachment id out of every answer that references it.
	 */
	private static function forget_in_document( int $survey_id, int $attachment_id ): void {
		$doc     = SurveyRepository::get( $survey_id );
		$touched = false;

		$prune = static function ( array $answer ) use ( $attachment_id, &$touched ): array {
			$photos = array_values(
				array_filter(
					(array) ( $answer['photos'] ?? [] ),
					static fn( $id ): bool => (int) $id !== $attachment_id
				)
			);

			if ( count( $photos ) !== count( (array) ( $answer['photos'] ?? [] ) ) ) {
				$touched = true;
			}

			$answer['photos'] = $photos;

			return $answer;
		};

		foreach ( (array) ( $doc['sections'] ?? [] ) as $section_key => $section ) {
			foreach ( (array) ( $section['items'] ?? [] ) as $item_key => $answer ) {
				$doc['sections'][ $section_key ]['items'][ $item_key ] = $prune( (array) $answer );
			}
		}

		foreach ( (array) ( $doc['panels'] ?? [] ) as $index => $panel ) {
			foreach ( (array) ( $panel['items'] ?? [] ) as $item_key => $answer ) {
				$doc['panels'][ $index ]['items'][ $item_key ] = $prune( (array) $answer );
			}
		}

		if ( $touched ) {
			SurveyRepository::save( $survey_id, $doc );
		}
	}
}
