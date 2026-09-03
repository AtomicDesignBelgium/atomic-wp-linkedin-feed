<?php
/**
 * LinkedIn REST client using the WordPress HTTP API.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers\LinkedIn;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Providers\ProviderException;
use AtomicWPSocialSync\Support\CredentialVault;
use AtomicWPSocialSync\Support\Logger;

final class LinkedInClient {
	public const API_VERSION = '202608';
	private const API_BASE   = 'https://api.linkedin.com/rest/';

	public function __construct(
		private readonly CredentialVault $vault,
		private readonly Logger $logger
	) {}

	/** @return array<string,mixed> */
	public function get( Connection $connection, string $path, array $query = array(), array $headers = array() ): array {
		$token = $this->vault->get( self::accessTokenKey( $connection->id ) );
		if ( null === $token ) {
			throw new ProviderException( __( 'LinkedIn access token is unavailable.', 'atomic-wp-social-sync' ), ProviderException::AUTHENTICATION, 401 );
		}
		return $this->requestWithToken( 'GET', $path, $token, $query, $headers );
	}

	/**
	 * Used during OAuth setup before a Connection exists.
	 *
	 * @return array<string,mixed>
	 */
	public function requestWithToken( string $method, string $path, string $access_token, array $query = array(), array $headers = array() ): array {
		$url = self::API_BASE . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$request_headers = array_merge(
			array(
				'Authorization'              => 'Bearer ' . $access_token,
				'Linkedin-Version'           => self::API_VERSION,
				'X-Restli-Protocol-Version' => '2.0.0',
				'Accept'                     => 'application/json',
			),
			$headers
		);
		$this->logger->debug( 'LinkedIn API request.', array( 'method' => $method, 'path' => $path ) );
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $request_headers,
				'timeout'     => 20,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new ProviderException( sprintf( __( 'LinkedIn network request failed: %s', 'atomic-wp-social-sync' ), $response->get_error_message() ), ProviderException::NETWORK, null, true );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		$this->logger->debug( 'LinkedIn API response.', array( 'path' => $path, 'http_status' => $status ) );
		if ( $status < 200 || $status >= 300 ) {
			$this->throwResponseException( $status, is_array( $data ) ? $data : array() );
		}
		if ( '' !== $body && ! is_array( $data ) ) {
			throw new ProviderException( __( 'LinkedIn returned invalid JSON.', 'atomic-wp-social-sync' ), ProviderException::API, $status, true );
		}

		return is_array( $data ) ? $data : array();
	}

	public static function accessTokenKey( string $connection_id ): string {
		return 'linkedin_access_' . $connection_id;
	}

	public static function refreshTokenKey( string $connection_id ): string {
		return 'linkedin_refresh_' . $connection_id;
	}

	/**
	 * Discovers Page roles granted to the authenticating member.
	 *
	 * @return array<int,array{id:string,name:string,role:string}>
	 */
	public function discoverOrganizations( string $access_token ): array {
		$response = $this->requestWithToken(
			'GET',
			'organizationAcls',
			$access_token,
			array( 'q' => 'roleAssignee', 'state' => 'APPROVED', 'count' => 100 ),
			array( 'X-RestLi-Method' => 'FINDER' )
		);
		$organizations = array();
		foreach ( $response['elements'] ?? array() as $access_control ) {
			if ( ! is_array( $access_control ) || 'APPROVED' !== ( $access_control['state'] ?? '' ) ) {
				continue;
			}
			$role = (string) ( $access_control['role'] ?? '' );
			if ( ! in_array( $role, array( 'ADMINISTRATOR', 'DIRECT_SPONSORED_CONTENT_POSTER', 'CONTENT_ADMIN', 'CONTENT_ADMINISTRATOR' ), true ) ) {
				continue;
			}
			$organization_urn = (string) ( $access_control['organization'] ?? '' );
			$organization_id  = $this->idFromUrn( $organization_urn );
			if ( '' === $organization_id ) {
				continue;
			}
			$name = sprintf( 'LinkedIn Page %s', $organization_id );
			try {
				$organization = $this->requestWithToken( 'GET', 'organizations/' . rawurlencode( $organization_id ), $access_token );
				$name = (string) ( $organization['localizedName'] ?? $organization['name']['localized']['en_US'] ?? $name );
			} catch ( ProviderException $exception ) {
				// A role may permit post access without expanded organization profile access.
				$this->logger->debug( 'LinkedIn organization name lookup was unavailable.', array( 'http_status' => $exception->http_status ) );
			}
			$organizations[ $organization_id ] = array(
				'id'   => $organization_id,
				'name' => sanitize_text_field( $name ),
				'role' => sanitize_key( strtolower( $role ) ),
			);
		}
		return array_values( $organizations );
	}

	private function idFromUrn( string $urn ): string {
		$parts = explode( ':', $urn );
		return sanitize_text_field( (string) end( $parts ) );
	}

	/** @param array<string,mixed> $data */
	private function throwResponseException( int $status, array $data ): never {
		$message = sanitize_text_field( (string) ( $data['message'] ?? __( 'LinkedIn API request failed.', 'atomic-wp-social-sync' ) ) );
		$category = match ( $status ) {
			401     => ProviderException::AUTHENTICATION,
			403     => ProviderException::AUTHORIZATION,
			404     => ProviderException::NOT_FOUND,
			429     => ProviderException::RATE_LIMIT,
			default => ProviderException::API,
		};
		throw new ProviderException( $message, $category, $status, 429 === $status || $status >= 500 );
	}
}
