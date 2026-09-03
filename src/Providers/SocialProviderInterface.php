<?php
/**
 * Contract between provider adapters and the generic synchronization core.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Model\NormalizedSocialPost;

interface SocialProviderInterface {
	public function slug(): string;

	public function label(): string;

	public function capabilities(): ProviderCapabilities;

	/** @return array<string,mixed> Sanitized connection information. */
	public function testConnection( Connection $connection ): array;

	/** @return array{id:string,name:string} */
	public function getAccount( Connection $connection ): array;

	/**
	 * Fetches and normalizes remote posts. No WordPress persistence occurs here.
	 *
	 * @return NormalizedSocialPost[]
	 */
	public function fetchPosts( Connection $connection, int $limit = 100, int $start = 0 ): array;

	public function fetchPost( Connection $connection, string $external_id ): ?NormalizedSocialPost;

	public function verifyPostExists( Connection $connection, string $external_id ): VerificationResult;
}
