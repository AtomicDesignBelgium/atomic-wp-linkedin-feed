<?php
/**
 * Runtime registry for installed provider adapters.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers;

use InvalidArgumentException;

final class ProviderRegistry {
	/** @var array<string,SocialProviderInterface> */
	private array $providers = array();

	public function register( SocialProviderInterface $provider ): void {
		$this->providers[ $provider->slug() ] = $provider;
	}

	public function get( string $slug ): SocialProviderInterface {
		if ( ! isset( $this->providers[ $slug ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Provider "%s" is not registered.', $slug ) );
		}
		return $this->providers[ $slug ];
	}

	/** @return array<string,SocialProviderInterface> */
	public function all(): array {
		return $this->providers;
	}
}
