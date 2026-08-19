<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Data;

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\PostType\Statuses;
use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes survey answers.
 *
 * Answers are stored as one JSON blob so a survey is a single atomic document rather than ~200 meta
 * rows. The flat metas written alongside it in {@see self::derive_flat_meta()} exist only so the
 * admin list table can sort and filter without unpacking JSON per row.
 */
final class SurveyRepository {

	public const STATUSES = [ 'pass', 'fail', 'na' ];

	public const SEVERITIES = [ 'immediate', 'recommended', 'optional' ];

	/**
	 * Answers are validated against the catalog version the survey was created with, not the
	 * current one, so revising the punch list never invalidates an in-flight survey.
	 */
	public static function catalog_version( int $post_id ): int {
		$version = (int) get_post_meta( $post_id, Meta::SCHEMA_VERSION, true );

		return $version > 0 ? $version : Catalog::CURRENT_VERSION;
	}

	public static function create( int $author_id, string $title = '' ): int|\WP_Error {
		$post_id = wp_insert_post(
			[
				'post_type'   => SurveyPostType::POST_TYPE,
				'post_status' => Statuses::DRAFT,
				'post_author' => $author_id,
				'post_title'  => '' !== $title ? $title : __( 'New site survey', 'paumalu-site-survey' ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, Meta::SCHEMA_VERSION, Catalog::CURRENT_VERSION );
		self::store_json( $post_id, Meta::DATA, self::blank_document() );

		return $post_id;
	}

	/**
	 * Write an array to post meta as JSON, surviving WordPress's unslashing.
	 *
	 * update_post_meta() calls wp_unslash() on its input, which eats the backslashes JSON uses for
	 * its own escaping — "\n" becomes "n", "\/" becomes "/", and a technician's multi-line note is
	 * quietly corrupted. Pre-slashing cancels that out. Every JSON meta on a survey must go through
	 * here.
	 *
	 * @param array<string, mixed> $value
	 */
	public static function store_json( int $post_id, string $meta_key, array $value ): string {
		$encoded = (string) wp_json_encode( $value );

		update_post_meta( $post_id, $meta_key, wp_slash( $encoded ) );

		return $encoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function blank_document(): array {
		return [
			'schema_version' => Catalog::CURRENT_VERSION,
			'customer'       => [
				'name'    => '',
				'email'   => '',
				'phone'   => '',
				'address' => '',
			],
			'site'           => [
				'year_built'   => '',
				'service_amps' => '',
				'meter_no'     => '',
			],
			'inspection'     => [
				'date' => current_time( 'Y-m-d' ),
			],
			'panels'         => [
				[
					'id'       => 'panel-main',
					'label'    => __( 'Main Panel', 'paumalu-site-survey' ),
					'location' => '',
					'brand'    => '',
					'model'    => '',
					'amps'     => '',
					'items'    => (object) [],
					'readings' => (object) [],
				],
			],
			'sections'       => (object) [],
			'upgrades'       => (object) [],
			'summary'        => [
				'overall'     => '',
				'immediate'   => '',
				'maintenance' => '',
				'upgrades'    => '',
				'timeframe'   => '',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get( int $post_id ): array {
		$raw = get_post_meta( $post_id, Meta::DATA, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::blank_document();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : self::blank_document();
	}

	/**
	 * Validate and persist. Unknown item keys and out-of-range values are dropped rather than
	 * rejected outright — a technician mid-inspection should never lose a whole save because one
	 * field was malformed.
	 *
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed> The sanitized document as stored.
	 */
	public static function save( int $post_id, array $incoming ): array {
		$version = self::catalog_version( $post_id );
		$clean   = self::sanitize_document( $incoming, $version );

		// Compare the stored bytes against the bytes about to be stored, rather than comparing the
		// decoded arrays. Empty maps are held as (object) [] so they serialize to {} instead of [],
		// which the client needs — but json_decode turns them back into [], so a decoded comparison
		// never matches and every autosave would look like a change.
		$encoded  = (string) wp_json_encode( $clean );
		$previous = (string) get_post_meta( $post_id, Meta::DATA, true );

		// wp_slash() is mandatory here: update_post_meta() runs wp_unslash() on whatever it is
		// given, which strips JSON's own escapes — a note containing a line break would come back
		// with "\n" collapsed to a literal "n". See self::store_json().
		update_post_meta( $post_id, Meta::DATA, wp_slash( $encoded ) );
		self::derive_flat_meta( $post_id, $clean, $version );
		self::flag_if_dirty( $post_id, $previous, $encoded );

		return $clean;
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>
	 */
	private static function sanitize_document( array $doc, int $version ): array {
		$catalog  = Catalog::items( $version );
		$upgrades = Catalog::upgrades( $version );
		$summary  = Catalog::get( $version )['summary'];
		$clean    = self::blank_document();

		$clean['schema_version'] = $version;

		$clean['customer'] = [
			'name'    => sanitize_text_field( (string) ( $doc['customer']['name'] ?? '' ) ),
			'email'   => sanitize_email( (string) ( $doc['customer']['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( (string) ( $doc['customer']['phone'] ?? '' ) ),
			'address' => sanitize_textarea_field( (string) ( $doc['customer']['address'] ?? '' ) ),
		];

		$clean['site'] = [
			'year_built'   => sanitize_text_field( (string) ( $doc['site']['year_built'] ?? '' ) ),
			'service_amps' => sanitize_text_field( (string) ( $doc['site']['service_amps'] ?? '' ) ),
			'meter_no'     => sanitize_text_field( (string) ( $doc['site']['meter_no'] ?? '' ) ),
		];

		$date                = (string) ( $doc['inspection']['date'] ?? '' );
		$clean['inspection'] = [
			'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
		];

		// Survey-scoped answers.
		$sections = [];

		foreach ( (array) ( $doc['sections'] ?? [] ) as $section_key => $section ) {
			foreach ( (array) ( $section['items'] ?? [] ) as $item_key => $answer ) {
				$item = $catalog[ $item_key ] ?? null;

				if ( null === $item || 'survey' !== $item['scope'] || $item['section'] !== $section_key ) {
					continue;
				}

				$sections[ $section_key ]['items'][ $item_key ] = self::sanitize_answer( $answer, $item );
			}
		}

		$clean['sections'] = $sections ?: (object) [];

		// Panels, each carrying its own copy of the panel-scoped items.
		$panels = [];

		foreach ( array_values( (array) ( $doc['panels'] ?? [] ) ) as $index => $panel ) {
			$id = sanitize_key( (string) ( $panel['id'] ?? '' ) );

			$panels[] = [
				'id'       => '' !== $id ? $id : 'panel-' . ( $index + 1 ),
				'label'    => sanitize_text_field( (string) ( $panel['label'] ?? sprintf( /* translators: %d: panel number. */ __( 'Panel %d', 'paumalu-site-survey' ), $index + 1 ) ) ),
				'location' => sanitize_text_field( (string) ( $panel['location'] ?? '' ) ),
				'brand'    => sanitize_text_field( (string) ( $panel['brand'] ?? '' ) ),
				'model'    => sanitize_text_field( (string) ( $panel['model'] ?? '' ) ),
				'amps'     => sanitize_text_field( (string) ( $panel['amps'] ?? '' ) ),
				'items'    => self::sanitize_panel_items( (array) ( $panel['items'] ?? [] ), $catalog ),
				'readings' => self::sanitize_readings( (array) ( $panel['readings'] ?? [] ), $version ),
			];
		}

		$clean['panels'] = $panels ?: self::blank_document()['panels'];

		// Upgrade opportunities.
		$selected = [];

		foreach ( (array) ( $doc['upgrades'] ?? [] ) as $key => $value ) {
			if ( ! isset( $upgrades[ $key ] ) ) {
				continue;
			}

			$selected[ $key ] = [
				'interested' => (bool) ( $value['interested'] ?? false ),
				'note'       => sanitize_textarea_field( (string) ( $value['note'] ?? '' ) ),
			];
		}

		$clean['upgrades'] = $selected ?: (object) [];

		$overall   = (string) ( $doc['summary']['overall'] ?? '' );
		$timeframe = (string) ( $doc['summary']['timeframe'] ?? '' );

		$clean['summary'] = [
			'overall'     => isset( $summary['conditions'][ $overall ] ) ? $overall : '',
			'immediate'   => sanitize_textarea_field( (string) ( $doc['summary']['immediate'] ?? '' ) ),
			'maintenance' => sanitize_textarea_field( (string) ( $doc['summary']['maintenance'] ?? '' ) ),
			'upgrades'    => sanitize_textarea_field( (string) ( $doc['summary']['upgrades'] ?? '' ) ),
			'timeframe'   => isset( $summary['timeframes'][ $timeframe ] ) ? $timeframe : '',
		];

		return $clean;
	}

	/**
	 * @param array<string, mixed>                $items
	 * @param array<string, array<string, mixed>> $catalog
	 * @return array<string, mixed>|object
	 */
	private static function sanitize_panel_items( array $items, array $catalog ): array|object {
		$clean = [];

		foreach ( $items as $item_key => $answer ) {
			$item = $catalog[ $item_key ] ?? null;

			if ( null === $item || 'panel' !== $item['scope'] ) {
				continue;
			}

			$clean[ $item_key ] = self::sanitize_answer( $answer, $item );
		}

		return $clean ?: (object) [];
	}

	/**
	 * @param array<string, mixed> $readings
	 * @return array<string, float>|object
	 */
	private static function sanitize_readings( array $readings, int $version ): array|object {
		$defined = [];

		foreach ( Catalog::get( $version )['sections'] as $section ) {
			foreach ( $section['readings'] ?? [] as $reading ) {
				$defined[ $reading['key'] ] = $reading;
			}
		}

		$clean = [];

		foreach ( $readings as $key => $value ) {
			if ( ! isset( $defined[ $key ] ) || '' === $value || null === $value ) {
				continue;
			}

			if ( ! is_numeric( $value ) ) {
				continue;
			}

			$number = (float) $value;

			if ( $number < $defined[ $key ]['min'] || $number > $defined[ $key ]['max'] ) {
				continue;
			}

			$clean[ $key ] = $number;
		}

		return $clean ?: (object) [];
	}

	/**
	 * @param mixed                $answer
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function sanitize_answer( $answer, array $item ): array {
		$answer = is_array( $answer ) ? $answer : [];
		$status = (string) ( $answer['status'] ?? '' );

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = '';
		}

		$severity = (string) ( $answer['severity'] ?? '' );

		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			// Fall back to the catalog default so a failure always lands in a bucket.
			$severity = 'fail' === $status ? (string) $item['severity'] : '';
		}

		// Not absint() — that turns -4 into 4, silently pointing the answer at an unrelated
		// attachment. A malformed id should vanish, not become a different valid one.
		$photos = [];

		foreach ( (array) ( $answer['photos'] ?? [] ) as $photo_id ) {
			$photo_id = is_numeric( $photo_id ) ? (int) $photo_id : 0;

			if ( $photo_id > 0 ) {
				$photos[] = $photo_id;
			}
		}

		$photos = array_values( array_unique( $photos ) );

		return [
			'status'   => $status,
			'severity' => $severity,
			'note'     => sanitize_textarea_field( (string) ( $answer['note'] ?? '' ) ),
			'photos'   => $photos,
		];
	}

	/**
	 * Mirror a few values out of the JSON blob so the admin list table can query them.
	 *
	 * @param array<string, mixed> $doc
	 */
	private static function derive_flat_meta( int $post_id, array $doc, int $version ): void {
		update_post_meta( $post_id, Meta::CUSTOMER_NAME, $doc['customer']['name'] );
		update_post_meta( $post_id, Meta::CUSTOMER_EMAIL, $doc['customer']['email'] );
		update_post_meta( $post_id, Meta::CUSTOMER_PHONE, $doc['customer']['phone'] );
		update_post_meta( $post_id, Meta::SERVICE_ADDRESS, $doc['customer']['address'] );
		update_post_meta( $post_id, Meta::INSPECTION_DATE, $doc['inspection']['date'] );
		update_post_meta( $post_id, Meta::OVERALL_CONDITION, $doc['summary']['overall'] );
		update_post_meta( $post_id, Meta::FAIL_COUNTS, self::count_failures( $doc, $version ) );

		$name = $doc['customer']['name'];

		if ( '' !== $name ) {
			$title = sprintf(
				/* translators: 1: customer name, 2: inspection date. */
				__( '%1$s — %2$s', 'paumalu-site-survey' ),
				$name,
				mysql2date( 'M j, Y', $doc['inspection']['date'] )
			);

			wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
		}
	}

	/**
	 * Failures grouped by the bucket they will land in on the action plan.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, int>
	 */
	public static function count_failures( array $doc, int $version ): array {
		$counts = [
			'immediate'   => 0,
			'recommended' => 0,
			'optional'    => 0,
		];

		foreach ( self::iterate_answers( $doc ) as [ $answer ] ) {
			if ( 'fail' !== ( $answer['status'] ?? '' ) ) {
				continue;
			}

			$severity = (string) ( $answer['severity'] ?? '' );

			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
		}

		// Upgrade opportunities the customer showed interest in are optional-bucket items.
		foreach ( (array) ( $doc['upgrades'] ?? [] ) as $upgrade ) {
			if ( ! empty( $upgrade['interested'] ) ) {
				++$counts['optional'];
			}
		}

		return $counts;
	}

	/**
	 * Walk every answered item, survey-scoped and panel-scoped alike.
	 *
	 * @param array<string, mixed> $doc
	 * @return \Generator<array{0: array<string, mixed>, 1: string, 2: string|null}>
	 */
	public static function iterate_answers( array $doc ): \Generator {
		foreach ( (array) ( $doc['sections'] ?? [] ) as $section ) {
			foreach ( (array) ( $section['items'] ?? [] ) as $item_key => $answer ) {
				yield [ $answer, (string) $item_key, null ];
			}
		}

		foreach ( (array) ( $doc['panels'] ?? [] ) as $panel ) {
			foreach ( (array) ( $panel['items'] ?? [] ) as $item_key => $answer ) {
				yield [ $answer, (string) $item_key, (string) ( $panel['id'] ?? '' ) ];
			}
		}
	}

	/**
	 * Aaron chose to let technicians keep editing after submission, so a reviewer can be looking at
	 * a document that moved. Flag any change made once a survey has left draft.
	 *
	 * Both arguments are the JSON as stored, not decoded arrays — see {@see self::save()}.
	 */
	private static function flag_if_dirty( int $post_id, string $previous, string $current ): void {
		$status = (string) get_post_status( $post_id );

		if ( Statuses::DRAFT === $status || '' === (string) get_post_meta( $post_id, Meta::SUBMITTED_AT, true ) ) {
			return;
		}

		if ( $previous === $current ) {
			return;
		}

		update_post_meta( $post_id, Meta::DIRTY_SINCE_REVIEW, current_time( 'mysql' ) );
	}

	/**
	 * Compare the live document against the snapshot taken at submission.
	 *
	 * The whole before and after answers travel with each entry, not just the status. An answer can
	 * change without its status moving — a note rewritten, a severity downgraded, a photo deleted —
	 * and a reviewer told only "Fail → Fail" learns nothing from being told anything at all.
	 *
	 * @return list<array{key: string, panel: string|null, from: string, to: string, before: array<string, mixed>, after: array<string, mixed>}>
	 */
	public static function diff_since_submission( int $post_id ): array {
		$raw = get_post_meta( $post_id, Meta::REVIEW_SNAPSHOT, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$snapshot = json_decode( $raw, true );

		if ( ! is_array( $snapshot ) ) {
			return [];
		}

		$flatten = static function ( array $doc ): array {
			$flat = [];

			foreach ( self::iterate_answers( $doc ) as [ $answer, $key, $panel ] ) {
				$flat[ $panel . '|' . $key ] = $answer;
			}

			return $flat;
		};

		$before  = $flatten( $snapshot );
		$after   = $flatten( self::get( $post_id ) );
		$changes = [];

		foreach ( array_keys( $before + $after ) as $composite ) {
			$old = $before[ $composite ] ?? [];
			$new = $after[ $composite ] ?? [];

			if ( wp_json_encode( $old ) === wp_json_encode( $new ) ) {
				continue;
			}

			[ $panel, $key ] = explode( '|', (string) $composite, 2 );

			$changes[] = [
				'key'    => $key,
				'panel'  => '' !== $panel ? $panel : null,
				'from'   => (string) ( $old['status'] ?? '' ),
				'to'     => (string) ( $new['status'] ?? '' ),
				'before' => $old,
				'after'  => $new,
			];
		}

		return $changes;
	}
}
