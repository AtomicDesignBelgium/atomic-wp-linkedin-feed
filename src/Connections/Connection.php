<?php
/**
 * Provider-independent configured source account.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Connections;

use AtomicWPSocialSync\Support\PluginSettings;

final class Connection {
	public const STATUS_CONNECTED = 'connected';
	public const STATUS_RENEWAL   = 'renewal_required';
	public const STATUS_ERROR     = 'error';
	public const STATUS_DISABLED  = 'disabled';

	/**
	 * @param string[] $granted_scopes
	 * @param array<string,bool> $granted_capabilities
	 * @param array<string,int|float|string> $diagnostics
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $provider,
		public readonly string $account_external_id,
		public readonly string $account_name,
		public readonly string $status = self::STATUS_CONNECTED,
		public readonly array $granted_scopes = array(),
		public readonly array $granted_capabilities = array(),
		public readonly ?int $token_expires_at = null,
		public readonly ?int $refresh_token_expires_at = null,
		public readonly string $sync_frequency = PluginSettings::FREQUENCY_TWICE,
		public readonly ?int $last_sync_at = null,
		public readonly ?int $next_sync_at = null,
		public readonly ?string $last_error = null,
		public readonly array $diagnostics = array()
	) {}

	/** @param array<string,mixed> $data */
	public static function fromArray( array $data ): self {
		return new self(
			sanitize_text_field( (string) ( $data['id'] ?? '' ) ),
			sanitize_key( (string) ( $data['provider'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['account_external_id'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['account_name'] ?? '' ) ),
			sanitize_key( (string) ( $data['status'] ?? self::STATUS_CONNECTED ) ),
			self::stringList( $data['granted_scopes'] ?? array() ),
			is_array( $data['granted_capabilities'] ?? null ) ? array_map( static fn( mixed $capability ): bool => (bool) $capability, $data['granted_capabilities'] ) : array(),
			self::nullableInt( $data['token_expires_at'] ?? null ),
			self::nullableInt( $data['refresh_token_expires_at'] ?? null ),
			sanitize_key( (string) ( $data['sync_frequency'] ?? PluginSettings::FREQUENCY_TWICE ) ),
			self::nullableInt( $data['last_sync_at'] ?? null ),
			self::nullableInt( $data['next_sync_at'] ?? null ),
			isset( $data['last_error'] ) ? sanitize_text_field( (string) $data['last_error'] ) : null,
			is_array( $data['diagnostics'] ?? null ) ? $data['diagnostics'] : array()
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	/** @param array<string,mixed> $changes */
	public function with( array $changes ): self {
		return self::fromArray( array_merge( $this->toArray(), $changes ) );
	}

	public function isDue( int $now ): bool {
		return in_array( $this->status, array( self::STATUS_CONNECTED, self::STATUS_ERROR ), true )
			&& PluginSettings::FREQUENCY_OFF !== $this->sync_frequency
			&& ( null === $this->next_sync_at || $this->next_sync_at <= $now );
	}

	/** @param mixed $value @return string[] */
	private static function stringList( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
	}

	private static function nullableInt( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}
}
