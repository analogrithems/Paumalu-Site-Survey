<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place for the GitHub project URL and its docs, so the front-end app and the wp-admin menu
 * (see Admin\DocsMenu) never drift apart on where they send people.
 */
final class Links {

	public const GITHUB = 'https://github.com/analogrithems/Paumalu-Site-Survey';

	private const DOCS_BASE = self::GITHUB . '/blob/main/docs/';

	public const TECHNICIAN_GUIDE    = self::DOCS_BASE . 'technician-guide.md';
	public const EDITOR_GUIDE        = self::DOCS_BASE . 'editor-guide.md';
	public const ADMINISTRATOR_GUIDE = self::DOCS_BASE . 'administrator-guide.md';
}
