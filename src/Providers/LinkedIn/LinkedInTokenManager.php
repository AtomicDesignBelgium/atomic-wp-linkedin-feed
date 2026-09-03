<?php
/**
 * Refreshes LinkedIn credentials only when the approved tier supplied a refresh token.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers\LinkedIn;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Providers\ProviderException;
use AtomicWPSocialSync\Support\CredentialVault;
use Throwable;

final class LinkedInTokenManager {
	private const REFRESH_WINDOW = 300;

	public function __construct(
		private readonly LinkedInOAuth $oauth,
		private readonly CredentialVault $vault,
		private readonly ConnectionRepository $connections
	) {}

	public function ensureFresh( Connection $connection ): Connection {
		if ( null === $connection->token_expires_at || $connection->token_expires_at > time() + self::REFRESH_WINDOW ) {
			return $connection;
		}
		$refresh_token = $this->vault->get( LinkedInClient::refreshTokenKey( $connection->id ) );
		if ( null === $refresh_token || ( null !== $connection->refresh_token_expires_at && $connection->refresh_token_expires_at <= time() ) ) {
			throw new ProviderException( __( 'LinkedIn connection needs renewal.', 'atomic-wp-social-sync' ), ProviderException::AUTHENTICATION, 401 );
		}

		try {
			$token_data = $this->oauth->refreshAccessToken( $refresh_token );
		} catch ( Throwable $exception ) {
			throw new ProviderException( $exception->getMessage(), ProviderException::AUTHENTICATION, 401 );
		}
		$this->vault->put( LinkedInClient::accessTokenKey( $connection->id ), (string) $token_data['access_token'] );
		if ( ! empty( $token_data['refresh_token'] ) ) {
			$this->vault->put( LinkedInClient::refreshTokenKey( $connection->id ), (string) $token_data['refresh_token'] );
		}
		$updated = $connection->with(
			array(
				'token_expires_at' => time() + (int) ( $token_data['expires_in'] ?? 0 ),
				'refresh_token_expires_at' => ! empty( $token_data['refresh_token_expires_in'] ) ? time() + (int) $token_data['refresh_token_expires_in'] : $connection->refresh_token_expires_at,
				'status' => Connection::STATUS_CONNECTED,
			)
		);
		$this->connections->save( $updated );
		return $updated;
	}
}
