<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Roles {

	public static function install(): void {
		remove_role( Capabilities::TECHNICIAN_ROLE );

		add_role(
			Capabilities::TECHNICIAN_ROLE,
			__( 'Technician', 'paumalu-site-survey' ),
			Capabilities::technician_caps()
		);

		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( Capabilities::reviewer_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function uninstall(): void {
		remove_role( Capabilities::TECHNICIAN_ROLE );

		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( Capabilities::reviewer_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
