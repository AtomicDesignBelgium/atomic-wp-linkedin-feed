<?php
/**
 * Safe, classifiable provider failure.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers;

use RuntimeException;

final class ProviderException extends RuntimeException {
	public const AUTHENTICATION = 'authentication';
	public const AUTHORIZATION  = 'authorization';
	public const RATE_LIMIT     = 'rate_limit';
	public const NETWORK        = 'network';
	public const API            = 'api';
	public const NOT_FOUND      = 'not_found';
	public const NORMALIZATION  = 'normalization';

	public function __construct(
		string $message,
		public readonly string $category,
		public readonly ?int $http_status = null,
		public readonly bool $retryable = false
	) {
		parent::__construct( $message );
	}
}
