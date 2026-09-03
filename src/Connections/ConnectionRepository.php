<?php
/**
 * WordPress-native connection persistence.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Connections;

use AtomicWPSocialSync\Support\PluginSettings;

final class ConnectionRepository {
	/** @return Connection[] */
	public function all(): array {
		$stored = get_option( PluginSettings::CONNECTIONS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$connections = array();
		foreach ( $stored as $connection_data ) {
			if ( is_array( $connection_data ) ) {
				$connection = Connection::fromArray( $connection_data );
				if ( '' !== $connection->id && '' !== $connection->provider ) {
					$connections[] = $connection;
				}
			}
		}
		return $connections;
	}

	public function find( string $connection_id ): ?Connection {
		foreach ( $this->all() as $connection ) {
			if ( hash_equals( $connection->id, $connection_id ) ) {
				return $connection;
			}
		}
		return null;
	}

	public function save( Connection $connection ): void {
		$connections = $this->all();
		$replaced    = false;
		foreach ( $connections as $index => $existing ) {
			if ( $existing->id === $connection->id ) {
				$connections[ $index ] = $connection;
				$replaced              = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$connections[] = $connection;
		}
		$this->store( $connections );
	}

	public function delete( string $connection_id ): void {
		$remaining = array_filter(
			$this->all(),
			static fn( Connection $connection ): bool => $connection->id !== $connection_id
		);
		$this->store( array_values( $remaining ) );
	}

	/** @return Connection[] */
	public function due( int $now ): array {
		return array_values(
			array_filter( $this->all(), static fn( Connection $connection ): bool => $connection->isDue( $now ) )
		);
	}

	/** @param Connection[] $connections */
	private function store( array $connections ): void {
		update_option(
			PluginSettings::CONNECTIONS,
			array_map( static fn( Connection $connection ): array => $connection->toArray(), $connections ),
			false
		);
	}
}
