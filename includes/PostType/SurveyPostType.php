<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\PostType;

use Paumalu\SiteSurvey\Setup\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SurveyPostType {

	public const POST_TYPE = 'pe_site_survey';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'               => __( 'Site Surveys', 'paumalu-site-survey' ),
					'singular_name'      => __( 'Site Survey', 'paumalu-site-survey' ),
					'menu_name'          => __( 'Site Surveys', 'paumalu-site-survey' ),
					'add_new_item'       => __( 'Add New Site Survey', 'paumalu-site-survey' ),
					'edit_item'          => __( 'Edit Site Survey', 'paumalu-site-survey' ),
					'search_items'       => __( 'Search Site Surveys', 'paumalu-site-survey' ),
					'not_found'          => __( 'No site surveys found.', 'paumalu-site-survey' ),
					'not_found_in_trash' => __( 'No site surveys found in Trash.', 'paumalu-site-survey' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-clipboard',
				'menu_position'       => 26,
				'hierarchical'        => false,
				'supports'            => [ 'title', 'author' ],
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'delete_with_user'    => false,
				'map_meta_cap'        => true,
				'capability_type'     => [ 'site_survey', 'site_surveys' ],
				'capabilities'        => Capabilities::post_type_caps(),
			]
		);
	}
}
