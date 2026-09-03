<?php
/**
 * Explicit provider capability declaration.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers;

final class ProviderCapabilities {
	public function __construct(
		public readonly bool $can_read,
		public readonly bool $can_create,
		public readonly bool $can_update,
		public readonly bool $can_delete,
		public readonly bool $can_verify_remote_existence,
		public readonly bool $can_import_media,
		public readonly bool $can_refresh_token
	) {}

	/** @return array<string,bool> */
	public function toArray(): array {
		return get_object_vars( $this );
	}
}
