<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Proposal;

use Paumalu\SiteSurvey\Catalog\Catalog;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\Media\PhotoService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a completed survey into the first draft of a customer proposal.
 *
 * This is the translation layer the whole plugin exists for. The survey is written for a technician
 * — "Check neutral bar for multiple conductors where prohibited" — and is meaningless to a homeowner.
 * The catalog carries a `proposal` rewording for every item, and this walks the failures and emits
 * those instead, bucketed by severity.
 *
 * It produces a *draft*. Josh edits the wording, drops lines, moves things between buckets and writes
 * the intro. Nothing here is ever shown to a customer without a human having read it first, which is
 * why the generated text can afford to be blunt rather than hedged.
 */
final class ProposalBuilder {

	/**
	 * Build a fresh draft from the current state of the survey.
	 *
	 * @return array<string, mixed>
	 */
	public static function draft( int $survey_id ): array {
		$doc     = SurveyRepository::get( $survey_id );
		$version = SurveyRepository::catalog_version( $survey_id );
		$items   = Catalog::items( $version );
		$panels  = self::panel_labels( $doc );

		$groups = [
			Proposal::IMMEDIATE   => [],
			Proposal::RECOMMENDED => [],
			Proposal::OPTIONAL    => [],
		];

		foreach ( SurveyRepository::iterate_answers( $doc ) as [ $answer, $key, $panel_id ] ) {
			if ( 'fail' !== ( $answer['status'] ?? '' ) ) {
				continue;
			}

			$item = $items[ $key ] ?? null;

			if ( null === $item ) {
				continue;
			}

			// The answer's severity wins over the catalog default: the technician may have downgraded
			// it standing in front of the thing, and the catalog default is only ever a starting point.
			$bucket = (string) ( $answer['severity'] ?? '' );

			if ( ! isset( $groups[ $bucket ] ) ) {
				$bucket = (string) ( $item['severity'] ?? Proposal::RECOMMENDED );
			}

			if ( ! isset( $groups[ $bucket ] ) ) {
				$bucket = Proposal::RECOMMENDED;
			}

			$groups[ $bucket ][] = [
				'key'    => $key,
				'panel'  => (string) ( $panel_id ?? '' ),
				'source' => 'item',
				'text'   => self::text_for( $item, (string) ( $panel_id ?? '' ), $panels ),
				'photos' => array_values( array_map( 'intval', (array) ( $answer['photos'] ?? [] ) ) ),
			];
		}

		// Upgrades are opportunities rather than findings, so they can never be derived from the punch
		// list — they land in the optional bucket directly, after the failures that got there on merit.
		foreach ( Catalog::upgrades( $version ) as $key => $upgrade ) {
			$chosen = (array) ( $doc['upgrades'][ $key ] ?? [] );

			if ( empty( $chosen['interested'] ) ) {
				continue;
			}

			$groups[ Proposal::OPTIONAL ][] = [
				'key'    => $key,
				'panel'  => '',
				'source' => 'upgrade',
				'text'   => (string) ( $upgrade['proposal'] ?? $upgrade['label'] ?? '' ),
				'photos' => [],
			];
		}

		return [
			'status'       => Proposal::DRAFT,
			'intro'        => self::intro( $doc, $groups ),
			'groups'       => $groups,
			'photos'       => self::suggested_photos( $survey_id, $groups ),
			'generated_at' => current_time( 'mysql' ),
		];
	}

	/**
	 * Regenerate without discarding the reviewer's work.
	 *
	 * A survey can be edited after a proposal has been drafted — a technician adds a finding, Josh
	 * asks for a photo — and the honest response is to pick up the new findings while keeping every
	 * word Josh has already written. Matching on key plus panel, a line he reworded stays reworded, a
	 * line he deleted stays deleted, and only genuinely new findings are appended.
	 *
	 * @param array<string, mixed> $existing
	 * @return array<string, mixed>
	 */
	public static function regenerate( int $survey_id, array $existing ): array {
		$fresh = self::draft( $survey_id );
		$seen  = [];

		// A line the reviewer deleted is absent from the document, so the current groups alone cannot
		// tell "never seen" from "seen and thrown out". Without the dismissed list, Refresh quietly
		// undoes his editorial decisions — he removes an upgrade the customer already said no to, adds
		// a new finding, and the removed line is back in the document he then sends.
		foreach ( (array) ( $existing['dismissed'] ?? [] ) as $id ) {
			$seen[ (string) $id ] = true;
		}

		foreach ( (array) ( $existing['groups'] ?? [] ) as $lines ) {
			foreach ( (array) $lines as $line ) {
				$seen[ self::line_id( (array) $line ) ] = true;
			}
		}

		$added = 0;

		foreach ( $fresh['groups'] as $bucket => $lines ) {
			foreach ( $lines as $line ) {
				if ( isset( $seen[ self::line_id( $line ) ] ) ) {
					continue;
				}

				$existing['groups'][ $bucket ][] = $line;
				++$added;
			}
		}

		$existing['regenerated_at'] = current_time( 'mysql' );
		$existing['added_count']    = $added;

		return $existing;
	}

	/**
	 * Every line id this survey can currently produce.
	 *
	 * Used as the baseline for working out what the reviewer has thrown away. It has to be derived
	 * rather than remembered, because the draft he edits is generated on read and never stored — so
	 * before his first save there is no record anywhere of what he was shown.
	 *
	 * @return list<string>
	 */
	public static function generatable_ids( int $survey_id ): array {
		$ids = [];

		foreach ( self::draft( $survey_id )['groups'] as $lines ) {
			foreach ( $lines as $line ) {
				$ids[] = self::line_id( $line );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Identity of a proposal line, for matching a draft against a regenerated one.
	 *
	 * Panel is part of it because the same item fails independently on each panel: "dead front
	 * missing" on the main panel and on a subpanel are two findings, not one.
	 *
	 * @param array<string, mixed> $line
	 */
	public static function line_id( array $line ): string {
		return (string) ( $line['key'] ?? '' ) . '|' . (string) ( $line['panel'] ?? '' );
	}

	/**
	 * The customer-facing wording, prefixed with the panel when the finding belongs to one.
	 *
	 * Catalog text is written panel-neutral ("The dead front is missing") precisely so this can put
	 * it in context without the sentence reading twice. A homeowner with a main panel and two
	 * subpanels needs to know which one, and "Subpanel A — the dead front is missing" is how a
	 * tradesperson would actually say it.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, string> $panels
	 */
	private static function text_for( array $item, string $panel_id, array $panels ): string {
		$text  = (string) ( $item['proposal'] ?? $item['label'] ?? '' );
		$label = $panels[ $panel_id ] ?? '';

		if ( '' === $label || '' === $text ) {
			return $text;
		}

		return sprintf(
			/* translators: 1: panel label, e.g. "Main Panel". 2: the finding. */
			__( '%1$s — %2$s', 'paumalu-site-survey' ),
			$label,
			$text
		);
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array<string, string>
	 */
	private static function panel_labels( array $doc ): array {
		$labels = [];

		foreach ( (array) ( $doc['panels'] ?? [] ) as $panel ) {
			$labels[ (string) ( $panel['id'] ?? '' ) ] = (string) ( $panel['label'] ?? '' );
		}

		return $labels;
	}

	/**
	 * An opening paragraph that states what was found, so Josh starts from something rather than
	 * from an empty box.
	 *
	 * Deliberately factual and free of sales language. Josh can add warmth; what he should not have
	 * to do is reconstruct the counts from the list below it.
	 *
	 * @param array<string, mixed> $doc
	 * @param array<string, list<array<string, mixed>>> $groups
	 */
	private static function intro( array $doc, array $groups ): string {
		$address   = trim( (string) ( $doc['customer']['address'] ?? '' ) );
		$date      = (string) ( $doc['inspection']['date'] ?? '' );
		$immediate = count( $groups[ Proposal::IMMEDIATE ] );

		$when = '';

		if ( '' !== $date ) {
			$stamp = strtotime( $date );
			$when  = false !== $stamp ? wp_date( 'F j, Y', $stamp ) : '';
		}

		$opening = '' !== $when
			? sprintf(
				/* translators: %s: inspection date. */
				__( 'Thank you for having us out on %s.', 'paumalu-site-survey' ),
				$when
			)
			: __( 'Thank you for having us out.', 'paumalu-site-survey' );

		$where = '' !== $address
			? sprintf(
				/* translators: %s: the first line of the service address. */
				__( ' We completed a visual inspection of the electrical service at %s.', 'paumalu-site-survey' ),
				self::first_line( $address )
			)
			: __( ' We completed a visual inspection of your electrical service.', 'paumalu-site-survey' );

		$found = $immediate > 0
			? sprintf(
				/* translators: %d: number of immediate-priority findings. */
				_n(
					' We found %d item that we consider a safety concern and recommend correcting right away, along with the other items below.',
					' We found %d items that we consider safety concerns and recommend correcting right away, along with the other items below.',
					$immediate,
					'paumalu-site-survey'
				),
				$immediate
			)
			: __( ' We found no immediate safety concerns. The items below are recommended to keep the system in good condition.', 'paumalu-site-survey' );

		return $opening . $where . $found;
	}

	private static function first_line( string $address ): string {
		$lines = preg_split( '/\R/', $address ) ?: [ $address ];

		return trim( (string) $lines[0] );
	}

	/**
	 * Photos worth putting in front of a customer, best-guess.
	 *
	 * Anything already flagged featured is honoured first — that is a human decision. Beyond that we
	 * suggest photos attached to immediate findings, because a photograph of the thing that is
	 * actually dangerous is the one that does the work. Capped at four: this is a proposal, not an
	 * album, and past four the customer stops looking.
	 *
	 * @param array<string, list<array<string, mixed>>> $groups
	 * @return list<array<string, mixed>>
	 */
	private static function suggested_photos( int $survey_id, array $groups ): array {
		$available = [];

		foreach ( PhotoService::for_survey( $survey_id ) as $photo ) {
			$available[ (int) $photo['id'] ] = $photo;
		}

		$ordered = PhotoService::featured_ids( $survey_id );

		foreach ( [ Proposal::IMMEDIATE, Proposal::RECOMMENDED, Proposal::OPTIONAL ] as $bucket ) {
			foreach ( $groups[ $bucket ] as $line ) {
				foreach ( (array) ( $line['photos'] ?? [] ) as $id ) {
					$ordered[] = (int) $id;
				}
			}
		}

		$chosen = [];

		foreach ( array_unique( $ordered ) as $id ) {
			if ( ! isset( $available[ $id ] ) || count( $chosen ) >= Proposal::MAX_PHOTOS ) {
				continue;
			}

			$chosen[] = [
				'id'      => $id,
				// The technician's caption is the starting point. They wrote it standing in front of
				// the panel, which is more than can be said for anything generated here.
				'caption' => (string) ( $available[ $id ]['caption'] ?? '' ),
			];
		}

		return $chosen;
	}
}
