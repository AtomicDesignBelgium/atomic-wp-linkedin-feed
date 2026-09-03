<?php
/**
 * Result of an authoritative provider lookup.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers;

use AtomicWPSocialSync\Model\NormalizedSocialPost;

final class VerificationResult {
	private function __construct(
		public readonly string $state,
		public readonly ?NormalizedSocialPost $post = null
	) {}

	public static function exists( NormalizedSocialPost $post ): self {
		return new self( 'exists', $post );
	}

	public static function missing(): self {
		return new self( 'missing' );
	}
}
