<?php
/**
 * Document shell for the technician app.
 *
 * Deliberately not get_header()/get_footer(). This is a form somebody fills in one-handed on a
 * phone, sometimes in a crawlspace — the theme's navigation, widgets and cookie banners are all
 * liabilities here, and the viewport and theme-color tags need to be exact.
 *
 * @package Paumalu\SiteSurvey
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php // viewport-fit=cover so the layout reaches under the notch; user-scalable stays on for accessibility. ?>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#0b3d5c">
	<meta name="robots" content="noindex, nofollow">
	<meta name="format-detection" content="telephone=no">
	<title><?php echo esc_html__( 'Site Survey', 'paumalu-site-survey' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="pe-survey-body">
	<div id="pe-survey-app" class="pe-app">
		<noscript>
			<p class="pe-noscript">
				<?php esc_html_e( 'This inspection form needs JavaScript enabled.', 'paumalu-site-survey' ); ?>
			</p>
		</noscript>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
