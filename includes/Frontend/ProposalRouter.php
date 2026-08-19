<?php

declare( strict_types=1 );

namespace Paumalu\SiteSurvey\Frontend;

use Paumalu\SiteSurvey\Proposal\Proposal;
use Paumalu\SiteSurvey\Proposal\Signature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The customer-facing proposal page.
 *
 * This is the only route in the plugin an anonymous visitor can legitimately reach, and the only one
 * whose URL is emailed around, forwarded, and pasted into text messages. It is treated accordingly:
 * unguessable token, never indexed, never cached, and rate limited so the token space cannot be
 * walked.
 */
final class ProposalRouter {

	public const BASE = 'proposal';

	public const QUERY_TOKEN = 'pe_proposal_token';

	/** Attempts allowed from one IP per window before we stop answering. */
	private const RATE_LIMIT  = 20;
	private const RATE_WINDOW = 600;

	public function register(): void {
		add_action( 'init', [ self::class, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );
		add_filter( 'template_include', [ $this, 'load_template' ] );
	}

	public static function add_rules(): void {
		$base = self::BASE;

		// The token pattern is pinned to hex of exactly the right length, so a malformed URL never
		// becomes a database query — it simply does not match a rule and 404s like any other path.
		add_rewrite_rule( "^{$base}/([a-f0-9]{40})/?$", 'index.php?' . self::QUERY_TOKEN . '=$matches[1]', 'top' );
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_TOKEN;

		return $vars;
	}

	public static function current_token(): string {
		return (string) get_query_var( self::QUERY_TOKEN );
	}

	/** Resolved once per request so the template does not have to look it up again. */
	private static int $survey_id = 0;

	public static function survey_id(): int {
		return self::$survey_id;
	}

	public function handle_request(): void {
		$token = self::current_token();

		if ( '' === $token ) {
			return;
		}

		$this->prevent_caching();

		if ( $this->is_rate_limited() ) {
			wp_die(
				esc_html__( 'Too many attempts. Please wait a few minutes and try again.', 'paumalu-site-survey' ),
				esc_html__( 'Slow down', 'paumalu-site-survey' ),
				[ 'response' => 429 ]
			);
		}

		$survey_id = Proposal::find_by_token( $token );

		if ( null === $survey_id ) {
			$this->record_miss();

			// A wrong token and an expired one are the same 404 on purpose. Telling an anonymous
			// caller "that link has expired" confirms it once existed, which is the one bit of
			// information somebody guessing would actually find useful.
			status_header( 404 );
			nocache_headers();

			wp_die(
				esc_html__( 'This link is no longer valid. Please contact us and we will send you a new one.', 'paumalu-site-survey' ),
				esc_html__( 'Link not found', 'paumalu-site-survey' ),
				[ 'response' => 404 ]
			);
		}

		self::$survey_id = $survey_id;

		$this->maybe_sign( $survey_id );

		Proposal::mark_viewed( $survey_id );

		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Handle a signature or a decline posted from the page.
	 *
	 * Posting back to the same URL rather than to a REST route: the customer is anonymous, so there
	 * is no cookie to carry a REST nonce, and the token in the URL is already the credential. A nonce
	 * is still used to bind the submission to a rendered page.
	 */
	private function maybe_sign( int $survey_id ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = sanitize_key( (string) ( $_POST['pe_action'] ?? '' ) );

		if ( '' === $action ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $_POST['pe_nonce'] ?? '' ) ), 'pe_proposal_sign' ) ) {
			$this->redirect_with( 'error' );
		}

		if ( 'decline' === $action ) {
			Signature::decline( $survey_id, (string) wp_unslash( $_POST['pe_reason'] ?? '' ) );
			$this->redirect_with( 'declined' );
		}

		$result = Signature::record(
			$survey_id,
			[
				'name' => sanitize_text_field( (string) wp_unslash( $_POST['pe_name'] ?? '' ) ),
				// Not sanitized here: this is a base64 data URL that Signature::decode_png() parses
				// byte by byte and rejects unless it is a real PNG. Running it through a text
				// sanitizer first would corrupt valid input while stopping nothing.
				'image' => (string) wp_unslash( $_POST['pe_signature'] ?? '' ),
				'via'  => 'link',
			]
		);

		$this->redirect_with( is_wp_error( $result ) ? 'error' : 'signed' );
	}

	/**
	 * Redirect after a POST so a refresh does not resubmit.
	 */
	private function redirect_with( string $result ): void {
		wp_safe_redirect(
			add_query_arg( 'result', $result, Proposal::url( self::current_token() ) )
		);

		exit;
	}

	/**
	 * Token guessing costs an attacker one HTTP request per attempt; this makes it cost more.
	 *
	 * The space is 160 bits, so this is not what makes the token safe — it is what keeps a bored
	 * script from filling the error log and the database with lookups. Counting only misses means a
	 * customer refreshing their own proposal is never affected.
	 */
	private function is_rate_limited(): bool {
		$misses = get_transient( $this->rate_key() );

		return is_numeric( $misses ) && (int) $misses >= self::RATE_LIMIT;
	}

	private function record_miss(): void {
		$key    = $this->rate_key();
		$misses = (int) get_transient( $key );

		set_transient( $key, $misses + 1, self::RATE_WINDOW );
	}

	private function rate_key(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		return 'pe_proposal_miss_' . md5( $ip );
	}

	/**
	 * The page records a first view and reflects signature state, so a cached copy would both lie to
	 * the customer and hide the view from Josh.
	 */
	private function prevent_caching(): void {
		foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ] as $flag ) {
			if ( ! defined( $flag ) ) {
				define( $flag, true );
			}
		}

		nocache_headers();
	}

	public function load_template( string $template ): string {
		if ( '' === self::current_token() || 0 === self::$survey_id ) {
			return $template;
		}

		return \Paumalu\SiteSurvey\PLUGIN_DIR . 'templates/proposal.php';
	}
}
