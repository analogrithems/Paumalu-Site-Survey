<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Admin;

use Paumalu\SiteSurvey\PostType\SurveyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const OPTION = 'pe_site_survey_settings';

	private const GROUP = 'pe_site_survey_settings_group';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			'company_name'      => 'Paumalu Electric',
			'license_number'    => '',
			'phone'             => '(808) 638-9054',
			'address'           => '',
			'logo_id'           => '',
			'from_email'        => '',
			'notify_emails'     => get_option( 'admin_email', '' ),
			'token_expiry_days' => '60',
			'disclaimer'        => __(
				'This inspection is a visual assessment of accessible electrical components on the date of service. It is not a code-compliance certification, a warranty, or a guarantee of future condition. Concealed wiring and inaccessible areas were not inspected.',
				'paumalu-site-survey'
			),
			'proposal_terms'    => __(
				'Approval of this action plan authorizes Paumalu Electric to schedule the work described above. Pricing will be provided separately prior to the start of work.',
				'paumalu-site-survey'
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function value( string $key ): string {
		return self::get()[ $key ] ?? '';
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . SurveyPostType::POST_TYPE,
			__( 'Site Survey Settings', 'paumalu-site-survey' ),
			__( 'Settings', 'paumalu-site-survey' ),
			'manage_options',
			'pe-site-survey-settings',
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, string>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		foreach ( [ 'company_name', 'license_number', 'phone', 'logo_id' ] as $key ) {
			$clean[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		}

		foreach ( [ 'address', 'disclaimer', 'proposal_terms' ] as $key ) {
			$clean[ $key ] = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
		}

		$from_email = sanitize_email( trim( (string) ( $input['from_email'] ?? '' ) ) );

		$clean['from_email'] = is_email( $from_email ) ? $from_email : '';

		$emails = array_filter(
			array_map(
				static fn( string $email ): string => sanitize_email( trim( $email ) ),
				explode( ',', (string) ( $input['notify_emails'] ?? '' ) )
			),
			static fn( string $email ): bool => is_email( $email ) !== false
		);

		$clean['notify_emails'] = implode( ', ', $emails );

		$days                       = absint( $input['token_expiry_days'] ?? 60 );
		$clean['token_expiry_days'] = (string) min( 365, max( 1, $days ) );

		return $clean;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$fields   = [
			'company_name'      => [ __( 'Company name', 'paumalu-site-survey' ), 'text', '' ],
			'license_number'    => [ __( 'Contractor license number', 'paumalu-site-survey' ), 'text', __( 'Appears on every customer-facing proposal.', 'paumalu-site-survey' ) ],
			'phone'             => [ __( 'Phone', 'paumalu-site-survey' ), 'text', '' ],
			'address'           => [ __( 'Mailing address', 'paumalu-site-survey' ), 'textarea', '' ],
			'from_email'        => [ __( 'Proposal from address', 'paumalu-site-survey' ), 'email', __( 'Proposal emails to customers are sent from this address. Leave blank to use the site default.', 'paumalu-site-survey' ) ],
			'notify_emails'     => [ __( 'Notify on submission', 'paumalu-site-survey' ), 'text', __( 'Comma-separated. These addresses are emailed when a technician submits a survey for review.', 'paumalu-site-survey' ) ],
			'token_expiry_days' => [ __( 'Proposal link expires after (days)', 'paumalu-site-survey' ), 'number', '' ],
			'disclaimer'        => [ __( 'Inspection disclaimer', 'paumalu-site-survey' ), 'textarea', '' ],
			'proposal_terms'    => [ __( 'Sign-off terms', 'paumalu-site-survey' ), 'textarea', '' ],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Survey Settings', 'paumalu-site-survey' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => [$label, $type, $help] ) : ?>
						<tr>
							<th scope="row">
								<label for="pe-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
							</th>
							<td>
								<?php if ( 'textarea' === $type ) : ?>
									<textarea id="pe-<?php echo esc_attr( $key ); ?>"
										name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
										rows="4" class="large-text"><?php echo esc_textarea( $settings[ $key ] ); ?></textarea>
								<?php else : ?>
									<input id="pe-<?php echo esc_attr( $key ); ?>"
										type="<?php echo esc_attr( $type ); ?>"
										name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
										value="<?php echo esc_attr( $settings[ $key ] ); ?>"
										class="regular-text" />
								<?php endif; ?>
								<?php if ( '' !== $help ) : ?>
									<p class="description"><?php echo esc_html( $help ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
