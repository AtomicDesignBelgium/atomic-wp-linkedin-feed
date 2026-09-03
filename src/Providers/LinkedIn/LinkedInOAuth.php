<?php
/**
 * LinkedIn 3-legged OAuth authorization-code flow.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers\LinkedIn;

use AtomicWPSocialSync\Support\CredentialVault;
use AtomicWPSocialSync\Support\PluginSettings;
use RuntimeException;

final class LinkedInOAuth {
	private const AUTHORIZE_ENDPOINT = 'https://www.linkedin.com/oauth/v2/authorization';
	private const TOKEN_ENDPOINT     = 'https://www.linkedin.com/oauth/v2/accessToken';
	private const STATE_TTL          = 600;
	private const CLIENT_SECRET_KEY  = 'linkedin_client_secret';

	public function __construct(
		private readonly PluginSettings $settings,
		private readonly CredentialVault $vault
	) {}

	public function callbackUrl(): string {
		return rest_url( 'atomic-wp-social-sync/v1/linkedin/callback' );
	}

	public function authorizationUrl( int $user_id, ?string $reconnect_connection_id = null ): string {
		$client_id = (string) $this->settings->get( 'linkedin_client_id', '' );
		if ( '' === $client_id || null === $this->vault->get( self::CLIENT_SECRET_KEY ) ) {
			throw new RuntimeException( __( 'Save the LinkedIn Client ID and Client Secret first.', 'atomic-wp-social-sync' ) );
		}

		$state = bin2hex( random_bytes( 32 ) );
		set_transient(
			$this->stateKey( $state ),
			array( 'user_id' => $user_id, 'created_at' => time(), 'reconnect_connection_id' => $reconnect_connection_id ),
			self::STATE_TTL
		);

		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'redirect_uri'  => $this->callbackUrl(),
				'state'         => $state,
				// Image retrieval currently requires the organization write scope even though V1 never publishes.
				'scope'         => 'r_organization_admin r_organization_social w_organization_social',
			),
			self::AUTHORIZE_ENDPOINT
		);
	}

	/** @return array<string,mixed> */
	public function exchangeCode( string $code, string $state ): array {
		$state_data = get_transient( $this->stateKey( $state ) );
		delete_transient( $this->stateKey( $state ) );
		if ( ! is_array( $state_data ) || empty( $state_data['user_id'] ) ) {
			throw new RuntimeException( __( 'LinkedIn OAuth state is invalid or expired.', 'atomic-wp-social-sync' ) );
		}

		$client_secret = $this->vault->get( self::CLIENT_SECRET_KEY );
		if ( null === $client_secret ) {
			throw new RuntimeException( __( 'LinkedIn Client Secret is unavailable.', 'atomic-wp-social-sync' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => (string) $this->settings->get( 'linkedin_client_id', '' ),
					'client_secret' => $client_secret,
					'redirect_uri'  => $this->callbackUrl(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( __( 'LinkedIn token exchange failed.', 'atomic-wp-social-sync' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			throw new RuntimeException( __( 'LinkedIn did not return a usable access token.', 'atomic-wp-social-sync' ) );
		}
		$data['oauth_user_id'] = (int) $state_data['user_id'];
		$data['reconnect_connection_id'] = sanitize_text_field( (string) ( $state_data['reconnect_connection_id'] ?? '' ) );
		return $data;
	}

	public function saveClientSecret( string $client_secret ): void {
		if ( '' !== $client_secret ) {
			$this->vault->put( self::CLIENT_SECRET_KEY, $client_secret );
		}
	}

	public function hasClientSecret(): bool {
		return null !== $this->vault->get( self::CLIENT_SECRET_KEY );
	}

	/** @return array<string,mixed> */
	public function refreshAccessToken( string $refresh_token ): array {
		$client_secret = $this->vault->get( self::CLIENT_SECRET_KEY );
		if ( null === $client_secret ) {
			throw new RuntimeException( __( 'LinkedIn Client Secret is unavailable.', 'atomic-wp-social-sync' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => (string) $this->settings->get( 'linkedin_client_id', '' ),
					'client_secret' => $client_secret,
				),
			)
		);
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			throw new RuntimeException( __( 'LinkedIn token refresh failed. Reconnect the account.', 'atomic-wp-social-sync' ) );
		}
		return $data;
	}

	private function stateKey( string $state ): string {
		return 'atomic_social_oauth_' . hash( 'sha256', $state );
	}
}
