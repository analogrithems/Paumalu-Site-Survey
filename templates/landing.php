<?php
/**
 * The signpost at the site root.
 *
 * Three audiences arrive here and each gets one obvious thing to do: a customer who wanted the
 * company gets the public site and the phone number, a technician gets the way into the app, and a
 * search engine gets nothing (Landing::robots()).
 *
 * Styles are inline rather than in assets/. This page is a dead end — nobody reads it twice — so a
 * second request for two kilobytes of CSS would be most of its cost.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Frontend\Landing;
use Paumalu\SiteSurvey\Frontend\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = SettingsPage::get();
$company  = (string) ( $settings['company_name'] ?? 'Paumalu Electric' );
$phone    = trim( (string) ( $settings['phone'] ?? '' ) );

$logo_id  = (int) ( $settings['logo_id'] ?? 0 );
$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

// Somebody already signed in should be taken to work, not asked to sign in again. Everybody else
// gets the login form with the app as the destination, so the round trip lands in the right place.
$staff_can  = is_user_logged_in() && current_user_can( 'edit_site_surveys' );
$staff_url  = $staff_can ? Router::url() : wp_login_url( Router::url() );
$staff_text = $staff_can
	? __( 'Open site surveys', 'paumalu-site-survey' )
	: __( 'Staff sign in', 'paumalu-site-survey' );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12455f">
	<title><?php
		/* translators: %s: company name. */
		echo esc_html( sprintf( __( '%s — Internal Tools', 'paumalu-site-survey' ), $company ) );
	?></title>
	<?php wp_robots(); ?>
	<style>
		:root { --ink: #12455f; --muted: #5b6b74; --line: #dfe5e8; }

		* { box-sizing: border-box; }

		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			padding: 2rem 1.25rem;
			background: #f4f7f8;
			color: #1d2b33;
			font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			text-align: center;
		}

		.pl-card {
			width: 100%;
			max-width: 30rem;
			background: #fff;
			border: 1px solid var(--line);
			border-radius: 14px;
			padding: 2.25rem 1.5rem;
			box-shadow: 0 1px 3px rgba(18, 69, 95, .08);
		}

		.pl-logo { max-width: 190px; height: auto; margin: 0 auto 1.25rem; display: block; }

		.pl-wordmark {
			display: block;
			margin-bottom: 1.25rem;
			font-size: 1.35rem;
			font-weight: 700;
			letter-spacing: .02em;
			color: var(--ink);
		}

		h1 { margin: 0 0 .75rem; font-size: 1.15rem; line-height: 1.35; color: var(--ink); }

		p { margin: 0 0 1rem; color: var(--muted); }

		.pl-actions { display: grid; gap: .65rem; margin-top: 1.75rem; }

		.pl-btn {
			display: block;
			padding: .85rem 1.1rem;
			border: 1px solid var(--ink);
			border-radius: 8px;
			font-weight: 600;
			text-decoration: none;
			color: var(--ink);
			background: #fff;
		}

		.pl-btn--primary { background: var(--ink); color: #fff; }

		.pl-btn:hover { opacity: .9; }

		.pl-foot { margin: 1.5rem 0 0; font-size: .85rem; color: var(--muted); }

		.pl-foot a { color: var(--ink); }
	</style>
</head>
<body>

<main class="pl-card">
	<?php if ( '' !== $logo_url ) : ?>
		<img class="pl-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $company ); ?>">
	<?php else : ?>
		<span class="pl-wordmark"><?php echo esc_html( $company ); ?></span>
	<?php endif; ?>

	<h1><?php esc_html_e( 'This site is an internal tool.', 'paumalu-site-survey' ); ?></h1>

	<p>
		<?php
		/* translators: %s: company name. */
		echo esc_html( sprintf( __( 'It runs the electrical inspection app %s technicians use in the field. There is nothing here for the public.', 'paumalu-site-survey' ), $company ) );
		?>
	</p>

	<p><?php esc_html_e( 'Looking for us? Our website is over here.', 'paumalu-site-survey' ); ?></p>

	<div class="pl-actions">
		<?php // rel="noopener" on an off-site link, and no target: this is a hand-off, not a detour. ?>
		<a class="pl-btn pl-btn--primary" rel="noopener" href="<?php echo esc_url( Landing::PUBLIC_SITE ); ?>">
			<?php
			/* translators: %s: company name. */
			echo esc_html( sprintf( __( 'Go to the %s website', 'paumalu-site-survey' ), $company ) );
			?>
		</a>

		<a class="pl-btn" href="<?php echo esc_url( $staff_url ); ?>"><?php echo esc_html( $staff_text ); ?></a>
	</div>

	<?php if ( '' !== $phone ) : ?>
		<p class="pl-foot">
			<?php esc_html_e( 'Need an electrician?', 'paumalu-site-survey' ); ?>
			<?php // A phone number a customer can tap is worth more than any of the above. ?>
			<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
		</p>
	<?php endif; ?>
</main>

</body>
</html>
