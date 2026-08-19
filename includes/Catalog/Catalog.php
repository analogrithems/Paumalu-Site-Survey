<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the versioned question catalog.
 *
 * Surveys snapshot the catalog they were created against, so revising the punch list never changes
 * the meaning of a survey that has already been filled out.
 */
final class Catalog {

	public const CURRENT_VERSION = 1;

	/** @var array<int, array<string, mixed>> */
	private static array $cache = [];

	/**
	 * @return array<string, mixed>
	 */
	public static function get( int $version = self::CURRENT_VERSION ): array {
		if ( isset( self::$cache[ $version ] ) ) {
			return self::$cache[ $version ];
		}

		$path = \Paumalu\SiteSurvey\PLUGIN_DIR . "includes/Catalog/catalog-v{$version}.php";

		if ( ! is_readable( $path ) ) {
			$path = \Paumalu\SiteSurvey\PLUGIN_DIR . 'includes/Catalog/catalog-v' . self::CURRENT_VERSION . '.php';
		}

		return self::$cache[ $version ] = require $path;
	}

	/**
	 * Flat map of item key => item definition, with the owning section merged in.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function items( int $version = self::CURRENT_VERSION ): array {
		$items = [];

		foreach ( self::get( $version )['sections'] as $section ) {
			foreach ( $section['items'] as $item ) {
				$items[ $item['key'] ] = $item + [
					'input'         => 'status',
					'section'       => $section['key'],
					'section_label' => $section['label'],
					'scope'         => $section['scope'],
					'polarity'      => $section['polarity'] ?? 'normal',
				];
			}
		}

		return $items;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function upgrades( int $version = self::CURRENT_VERSION ): array {
		return array_column( self::get( $version )['upgrades'], null, 'key' );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function sections( string $scope, int $version = self::CURRENT_VERSION ): array {
		return array_values(
			array_filter(
				self::get( $version )['sections'],
				static fn( array $section ): bool => $scope === $section['scope']
			)
		);
	}
}
