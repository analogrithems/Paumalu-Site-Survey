<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Technicians work entirely on the front end. They hold upload_files and edit_site_surveys, which
 * would otherwise give them a (useless, confusing) wp-admin dashboard.
 */
final class AdminLockout {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'redirect_technicians' ] );
		add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar' ] );
	}

	public function redirect_technicians(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! self::is_technician() ) {
			return;
		}

		wp_safe_redirect( home_url( '/survey/' ) );
		exit;
	}

	public function hide_admin_bar( bool $show ): bool {
		return self::is_technician() ? false : $show;
	}

	private static function is_technician(): bool {
		$user = wp_get_current_user();

		if ( 0 === $user->ID ) {
			return false;
		}

		// Keyed off the review capability rather than the role name so that an admin who also
		// carries the technician role is not locked out of their own dashboard.
		return in_array( Capabilities::TECHNICIAN_ROLE, (array) $user->roles, true )
			&& ! user_can( $user, Capabilities::REVIEW );
	}
}
