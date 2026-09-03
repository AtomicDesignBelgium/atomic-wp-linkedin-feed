<?php
/**
 * Local encrypted credential storage.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

use RuntimeException;

final class CredentialVault {
	private const CIPHER = 'aes-256-gcm';

	public function isAvailable(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	public function put( string $credential_key, string $secret ): void {
		if ( ! $this->isAvailable() ) {
			throw new RuntimeException( __( 'OpenSSL is required for secure credential storage.', 'atomic-wp-social-sync' ) );
		}

		$credentials = $this->storedCredentials();
		$iv          = random_bytes( 12 );
		$tag         = '';
		$ciphertext  = openssl_encrypt( $secret, self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new RuntimeException( __( 'Credential encryption failed.', 'atomic-wp-social-sync' ) );
		}

		$credentials[ $credential_key ] = base64_encode( $iv . $tag . $ciphertext );
		update_option( PluginSettings::CREDENTIALS, $credentials, false );
	}

	public function get( string $credential_key ): ?string {
		$encoded = $this->storedCredentials()[ $credential_key ] ?? null;
		if ( ! is_string( $encoded ) || '' === $encoded || ! $this->isAvailable() ) {
			return null;
		}

		$payload = base64_decode( $encoded, true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return null;
		}

		$plain = openssl_decrypt(
			substr( $payload, 28 ),
			self::CIPHER,
			$this->encryptionKey(),
			OPENSSL_RAW_DATA,
			substr( $payload, 0, 12 ),
			substr( $payload, 12, 16 )
		);

		return false === $plain ? null : $plain;
	}

	public function delete( string $credential_key ): void {
		$credentials = $this->storedCredentials();
		unset( $credentials[ $credential_key ] );
		update_option( PluginSettings::CREDENTIALS, $credentials, false );
	}

	/** @return array<string,string> */
	private function storedCredentials(): array {
		$credentials = get_option( PluginSettings::CREDENTIALS, array() );
		return is_array( $credentials ) ? $credentials : array();
	}

	private function encryptionKey(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|atomic-wp-social-sync', true );
	}
}
