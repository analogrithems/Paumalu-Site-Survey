<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta keys.
 *
 * Answers live in one JSON blob under DATA. The flat keys beside it are duplicated out of that blob
 * on save purely so the admin list table and future reporting can sort and filter without unpacking
 * JSON in PHP for every row.
 */
final class Meta {

	public const DATA             = '_pe_survey_data';
	public const SCHEMA_VERSION   = '_pe_schema_version';
	public const CATALOG_SNAPSHOT = '_pe_catalog_snapshot';

	public const CUSTOMER_NAME    = '_pe_customer_name';
	public const CUSTOMER_EMAIL   = '_pe_customer_email';
	public const CUSTOMER_PHONE   = '_pe_customer_phone';
	public const SERVICE_ADDRESS  = '_pe_service_address';
	public const INSPECTION_DATE  = '_pe_inspection_date';
	public const OVERALL_CONDITION = '_pe_overall_condition';
	public const FAIL_COUNTS      = '_pe_fail_counts';

	public const SUBMITTED_AT     = '_pe_submitted_at';
	public const REVIEW_SNAPSHOT  = '_pe_review_snapshot';
	public const DIRTY_SINCE_REVIEW = '_pe_dirty_since_review';
	public const REVIEWED_AT      = '_pe_reviewed_at';
	public const REVIEWED_BY      = '_pe_reviewed_by';
	public const ACCEPTED_AT      = '_pe_accepted_at';

	public const PROPOSAL         = '_pe_proposal';
	public const PROPOSAL_TOKEN   = '_pe_proposal_token_hash';
	public const PROPOSAL_EXPIRES = '_pe_proposal_expires';
	public const PROPOSAL_SENT_TO = '_pe_proposal_sent_to';

	/** Attachment-side keys, set on photos rather than on the survey. */
	public const PHOTO_ITEM_KEY   = '_pe_photo_item_key';
	public const PHOTO_PANEL_ID   = '_pe_photo_panel_id';
	public const PHOTO_FEATURED   = '_pe_proposal_featured';

	/**
	 * Marks an attachment as a captured signature rather than inspection evidence.
	 *
	 * Signatures hang off the survey by post_parent exactly like photos do, so without this flag they
	 * would appear in the technician's photo grid and could be picked for the proposal gallery.
	 */
	public const SIGNATURE_IMAGE  = '_pe_signature_image';
}
