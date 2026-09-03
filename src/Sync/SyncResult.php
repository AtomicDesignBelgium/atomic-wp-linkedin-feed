<?php
/**
 * Explicit synchronization counters and safe errors.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Sync;

final class SyncResult {
	public int $fetched = 0;
	public int $created = 0;
	public int $updated = 0;
	public int $unchanged = 0;
	public int $missing = 0;
	public int $drafted = 0;
	public int $conflicts = 0;
	public int $skipped = 0;
	/** @var string[] */
	public array $errors = array();
	public float $duration = 0.0;

	public function addError( string $message ): void {
		$this->errors[] = sanitize_text_field( $message );
	}

	/** @return array<string,int|float|string[]> */
	public function toArray(): array {
		return get_object_vars( $this );
	}
}
