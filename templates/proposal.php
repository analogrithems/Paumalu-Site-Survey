<?php
/**
 * The customer-facing action plan.
 *
 * Server-rendered rather than React: this is read once by a homeowner, possibly on an old phone with
 * a bad connection, and possibly printed. It must work with no JavaScript at all — the signature pad
 * is the only enhanced part, and there is a typed-name fallback beneath it.
 *
 * @package Paumalu\SiteSurvey
 */

use Paumalu\SiteSurvey\Admin\SettingsPage;
use Paumalu\SiteSurvey\Data\Meta;
use Paumalu\SiteSurvey\Data\SurveyRepository;
use Paumalu\SiteSurvey\Frontend\ProposalRouter;
use Paumalu\SiteSurvey\Proposal\Proposal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$survey_id = ProposalRouter::survey_id();
$proposal  = Proposal::get( $survey_id );
$doc       = SurveyRepository::get( $survey_id );
$settings  = SettingsPage::get();
$groups    = Proposal::group_labels();
$result    = isset( $_GET['result'] ) ? sanitize_key( (string) $_GET['result'] ) : '';

$customer  = trim( (string) ( $doc['customer']['name'] ?? '' ) );
$address   = trim( (string) ( $doc['customer']['address'] ?? '' ) );
$date      = (string) ( $doc['inspection']['date'] ?? '' );
$stamp     = '' !== $date ? strtotime( $date ) : false;
$is_signed = Proposal::SIGNED === $proposal['status'];
$declined  = Proposal::DECLINED === $proposal['status'];

$logo_id  = (int) ( $settings['logo_id'] ?? 0 );
$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12455f">
	<meta name="robots" content="noindex, nofollow, noarchive">
	<title><?php echo esc_html__( 'Your Electrical Action Plan', 'paumalu-site-survey' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( \Paumalu\SiteSurvey\PLUGIN_URL . 'assets/proposal.css?v=' . \Paumalu\SiteSurvey\VERSION ); ?>">
</head>
<body class="pp">

<header class="pp-head">
	<div class="pp-head__inner">
		<?php if ( '' !== $logo_url ) : ?>
			<img class="pp-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $settings['company_name'] ); ?>">
		<?php else : ?>
			<span class="pp-wordmark"><?php echo esc_html( $settings['company_name'] ); ?></span>
		<?php endif; ?>
		<button type="button" class="pp-print" onclick="window.print()">
			<?php esc_html_e( 'Print / Save PDF', 'paumalu-site-survey' ); ?>
		</button>
	</div>
</header>

<main class="pp-wrap">

	<?php if ( 'signed' === $result ) : ?>
		<p class="pp-flash pp-flash--ok" role="status">
			<?php esc_html_e( 'Thank you — your approval has been recorded. We will be in touch to schedule the work.', 'paumalu-site-survey' ); ?>
		</p>
	<?php elseif ( 'declined' === $result ) : ?>
		<p class="pp-flash" role="status">
			<?php esc_html_e( 'Thank you for letting us know. We have recorded your response.', 'paumalu-site-survey' ); ?>
		</p>
	<?php elseif ( 'error' === $result ) : ?>
		<p class="pp-flash pp-flash--bad" role="alert">
			<?php esc_html_e( 'Something went wrong recording that. Please try again, or reply to our email and we will sort it out.', 'paumalu-site-survey' ); ?>
		</p>
	<?php endif; ?>

	<section class="pp-intro">
		<h1><?php esc_html_e( 'Your Electrical Action Plan', 'paumalu-site-survey' ); ?></h1>

		<dl class="pp-meta">
			<?php if ( '' !== $customer ) : ?>
				<div><dt><?php esc_html_e( 'Prepared for', 'paumalu-site-survey' ); ?></dt><dd><?php echo esc_html( $customer ); ?></dd></div>
			<?php endif; ?>
			<?php if ( '' !== $address ) : ?>
				<div><dt><?php esc_html_e( 'Property', 'paumalu-site-survey' ); ?></dt><dd><?php echo nl2br( esc_html( $address ) ); ?></dd></div>
			<?php endif; ?>
			<?php if ( false !== $stamp ) : ?>
				<div><dt><?php esc_html_e( 'Inspected', 'paumalu-site-survey' ); ?></dt><dd><?php echo esc_html( wp_date( 'F j, Y', $stamp ) ); ?></dd></div>
			<?php endif; ?>
		</dl>

		<?php if ( '' !== trim( (string) $proposal['intro'] ) ) : ?>
			<p class="pp-lede"><?php echo nl2br( esc_html( $proposal['intro'] ) ); ?></p>
		<?php endif; ?>
	</section>

	<?php
	foreach ( $groups as $bucket => $label ) :
		$lines = (array) ( $proposal['groups'][ $bucket ] ?? [] );

		if ( [] === $lines ) {
			continue;
		}
		?>
		<section class="pp-group pp-group--<?php echo esc_attr( $bucket ); ?>">
			<h2 class="pp-group__title">
				<span class="pp-dot" aria-hidden="true"></span>
				<?php echo esc_html( $label ); ?>
				<span class="pp-count"><?php echo esc_html( (string) count( $lines ) ); ?></span>
			</h2>

			<?php if ( Proposal::IMMEDIATE === $bucket ) : ?>
				<p class="pp-group__note">
					<?php esc_html_e( 'These are conditions we consider a safety risk and recommend correcting as soon as possible.', 'paumalu-site-survey' ); ?>
				</p>
			<?php endif; ?>

			<ol class="pp-list">
				<?php foreach ( $lines as $line ) : ?>
					<li class="pp-list__item"><?php echo esc_html( (string) ( $line['text'] ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endforeach; ?>

	<?php
	$photos = (array) ( $proposal['photos'] ?? [] );

	if ( [] !== $photos ) :
		?>
		<section class="pp-gallery">
			<h2 class="pp-group__title"><?php esc_html_e( 'What we found', 'paumalu-site-survey' ); ?></h2>
			<div class="pp-gallery__grid">
				<?php
				foreach ( $photos as $photo ) :
					$id  = (int) ( $photo['id'] ?? 0 );
					$src = wp_get_attachment_image_url( $id, 'large' );

					if ( ! $src ) {
						continue;
					}
					?>
					<figure class="pp-shot">
						<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( (string) ( $photo['caption'] ?? '' ) ); ?>" loading="lazy">
						<?php if ( '' !== trim( (string) ( $photo['caption'] ?? '' ) ) ) : ?>
							<figcaption><?php echo esc_html( $photo['caption'] ); ?></figcaption>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php // ------------------------------------------------------- sign-off. ?>

	<section class="pp-signoff" id="approve">
		<h2 class="pp-group__title"><?php esc_html_e( 'Approval', 'paumalu-site-survey' ); ?></h2>

		<?php if ( $is_signed ) : ?>
			<div class="pp-signed">
				<p class="pp-signed__by">
					<?php
					printf(
						/* translators: 1: signer name, 2: date signed. */
						esc_html__( 'Approved by %1$s on %2$s.', 'paumalu-site-survey' ),
						esc_html( (string) ( $proposal['signature']['name'] ?? '' ) ),
						esc_html(
							wp_date(
								'F j, Y',
								strtotime( (string) ( $proposal['signature']['signed_at'] ?? 'now' ) ) ?: time()
							)
						)
					);
					?>
				</p>
				<?php
				$sig_id  = (int) ( $proposal['signature']['attachment_id'] ?? 0 );
				$sig_url = $sig_id > 0 ? wp_get_attachment_image_url( $sig_id, 'medium' ) : '';

				if ( '' !== $sig_url ) :
					?>
					<img class="pp-signed__mark" src="<?php echo esc_url( $sig_url ); ?>" alt="<?php esc_attr_e( 'Signature', 'paumalu-site-survey' ); ?>">
				<?php endif; ?>
			</div>
		<?php elseif ( $declined ) : ?>
			<p class="pp-flash"><?php esc_html_e( 'You let us know this is not something you want to move ahead with. If that changes, just reply to our email.', 'paumalu-site-survey' ); ?></p>
		<?php else : ?>

			<?php // Scope approval, not price acceptance — there are no dollar figures anywhere in this document. ?>
			<p class="pp-terms"><?php echo esc_html( $settings['proposal_terms'] ); ?></p>

			<form class="pp-form" method="post" action="">
				<?php wp_nonce_field( 'pe_proposal_sign', 'pe_nonce' ); ?>
				<input type="hidden" name="pe_action" value="sign">
				<input type="hidden" name="pe_signature" id="pe-signature-data" value="">

				<label class="pp-field">
					<span><?php esc_html_e( 'Your full name', 'paumalu-site-survey' ); ?></span>
					<input type="text" name="pe_name" required autocomplete="name">
				</label>

				<div class="pp-pad" id="pe-pad-wrap" hidden>
					<span class="pp-field__label"><?php esc_html_e( 'Sign below', 'paumalu-site-survey' ); ?></span>
					<canvas id="pe-pad" class="pp-pad__canvas" width="600" height="200"
						aria-label="<?php esc_attr_e( 'Signature area', 'paumalu-site-survey' ); ?>"></canvas>
					<button type="button" class="pp-clear" id="pe-pad-clear">
						<?php esc_html_e( 'Clear', 'paumalu-site-survey' ); ?>
					</button>
				</div>

				<button type="submit" class="pp-btn pp-btn--primary">
					<?php esc_html_e( 'Approve this work', 'paumalu-site-survey' ); ?>
				</button>
			</form>

			<form class="pp-form pp-form--decline" method="post" action="">
				<?php wp_nonce_field( 'pe_proposal_sign', 'pe_nonce' ); ?>
				<input type="hidden" name="pe_action" value="decline">
				<details>
					<summary><?php esc_html_e( 'Not right now', 'paumalu-site-survey' ); ?></summary>
					<label class="pp-field">
						<span><?php esc_html_e( 'Anything you would like us to know? (optional)', 'paumalu-site-survey' ); ?></span>
						<textarea name="pe_reason" rows="3"></textarea>
					</label>
					<button type="submit" class="pp-btn"><?php esc_html_e( 'Send response', 'paumalu-site-survey' ); ?></button>
				</details>
			</form>

		<?php endif; ?>
	</section>

	<footer class="pp-foot">
		<p class="pp-disclaimer"><?php echo esc_html( $settings['disclaimer'] ); ?></p>
		<p class="pp-company">
			<?php
			echo esc_html(
				implode(
					' · ',
					array_filter(
						[
							$settings['company_name'],
							$settings['phone'],
							'' !== $settings['license_number']
								? sprintf(
									/* translators: %s: contractor license number. */
									__( 'License %s', 'paumalu-site-survey' ),
									$settings['license_number']
								)
								: '',
						]
					)
				)
			);
			?>
		</p>
		<?php if ( '' !== trim( (string) $settings['address'] ) ) : ?>
			<p class="pp-company"><?php echo nl2br( esc_html( $settings['address'] ) ); ?></p>
		<?php endif; ?>
	</footer>

</main>

<?php if ( ! $is_signed && ! $declined ) : ?>
	<script src="<?php echo esc_url( \Paumalu\SiteSurvey\PLUGIN_URL . 'assets/signature.js?v=' . \Paumalu\SiteSurvey\VERSION ); ?>" defer></script>
<?php endif; ?>

</body>
</html>
