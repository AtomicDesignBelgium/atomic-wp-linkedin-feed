<?php
/**
 * Connection-based provider dispatch and synchronization orchestration.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Sync;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Providers\ProviderException;
use AtomicWPSocialSync\Providers\ProviderRegistry;
use AtomicWPSocialSync\Support\Logger;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use Throwable;
use WP_Post;

final class SyncService {
	private const FETCH_LIMIT               = 25;
	private const VERIFICATIONS_PER_RUN     = 5;
	private const LOCK_TTL                  = 900;
	private const LOCK_OPTION_PREFIX        = 'atomic_social_sync_lock_';

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly ConnectionRepository $connections,
		private readonly SocialPostRepository $posts,
		private readonly ReconciliationService $reconciliation,
		private readonly PluginSettings $settings,
		private readonly Logger $logger
	) {}

	public function syncConnection( string $connection_id ): SyncResult {
		$result     = new SyncResult();
		$started_at = microtime( true );
		$connection = $this->connections->find( $connection_id );
		if ( null === $connection ) {
			$result->addError( __( 'Connection was not found.', 'atomic-wp-social-sync' ) );
			return $result;
		}
		if ( ! $this->acquireLock( $connection_id ) ) {
			$result->addError( __( 'A synchronization is already running for this connection.', 'atomic-wp-social-sync' ) );
			return $result;
		}

		try {
			$provider    = $this->providers->get( $connection->provider );
			$remote_posts = $provider->fetchPosts( $connection, self::FETCH_LIMIT );
			$result->fetched = count( $remote_posts );
			$fetched_ids = array();
			foreach ( $remote_posts as $remote_post ) {
				$fetched_ids[] = $remote_post->external_id;
				$this->reconciliation->reconcile( $remote_post, $connection, $result );
			}

			// Feed absence is never deletion evidence. A limited number of absent IDs are
			// checked with the provider's authoritative single-resource endpoint instead.
			if ( $provider->capabilities()->can_verify_remote_existence ) {
				$this->verifyAbsentPosts( $connection, $fetched_ids, $result );
			}
			$this->saveSuccessfulRun( $connection, $result, $started_at );
		} catch ( ProviderException $exception ) {
			$result->addError( $exception->getMessage() );
			$status = ProviderException::AUTHENTICATION === $exception->category ? Connection::STATUS_RENEWAL : Connection::STATUS_ERROR;
			$current_connection = $this->connections->find( $connection->id ) ?? $connection;
			$this->connections->save(
				$current_connection->with(
					array(
						'status'     => $status,
						'last_error' => $exception->getMessage(),
						'next_sync_at' => $this->nextSyncAt( $connection->sync_frequency ),
					)
				)
			);
			$this->logger->error( 'Provider synchronization failed.', array( 'provider' => $connection->provider, 'category' => $exception->category, 'http_status' => $exception->http_status ) );
		} catch ( Throwable $exception ) {
			$result->addError( $exception->getMessage() );
			$current_connection = $this->connections->find( $connection->id ) ?? $connection;
			$this->connections->save(
				$current_connection->with(
					array(
						'status'       => Connection::STATUS_ERROR,
						'last_error'   => $exception->getMessage(),
						'next_sync_at' => $this->nextSyncAt( $connection->sync_frequency ),
					)
				)
			);
			$this->logger->error( 'Synchronization failed.', array( 'provider' => $connection->provider, 'connection_id' => $connection->id ) );
		} finally {
			$result->duration = round( microtime( true ) - $started_at, 3 );
			$this->releaseLock( $connection_id );
		}

		return $result;
	}

	/** @param string[] $fetched_ids */
	private function verifyAbsentPosts( Connection $connection, array $fetched_ids, SyncResult $result ): void {
		$provider = $this->providers->get( $connection->provider );
		$checked  = 0;
		foreach ( $this->posts->postsForConnection( $connection->id ) as $local_post ) {
			if ( $checked >= self::VERIFICATIONS_PER_RUN || ! $local_post instanceof WP_Post ) {
				break;
			}
			if ( get_post_meta( $local_post->ID, MetaKeys::DETACHED, true ) ) {
				continue;
			}
			$external_id = (string) get_post_meta( $local_post->ID, MetaKeys::EXTERNAL_ID, true );
			if ( '' === $external_id || in_array( $external_id, $fetched_ids, true ) ) {
				continue;
			}
			++$checked;
			$verification = $provider->verifyPostExists( $connection, $external_id );
			if ( 'exists' === $verification->state && null !== $verification->post ) {
				$this->reconciliation->reconcile( $verification->post, $connection, $result );
				continue;
			}
			$this->handleMissing( $local_post, $result );
		}
	}

	private function handleMissing( WP_Post $local_post, SyncResult $result ): void {
		$missing_since = (string) get_post_meta( $local_post->ID, MetaKeys::REMOTE_MISSING_SINCE, true );
		$now           = gmdate( 'c' );
		update_post_meta( $local_post->ID, MetaKeys::LAST_VERIFIED_AT, $now );
		update_post_meta( $local_post->ID, MetaKeys::REMOTE_STATUS, 'missing' );
		++$result->missing;
		if ( '' === $missing_since ) {
			update_post_meta( $local_post->ID, MetaKeys::REMOTE_MISSING_SINCE, $now );
			return;
		}

		$policy = (string) $this->settings->get( 'remote_delete_policy', PluginSettings::DELETE_DRAFT );
		if ( PluginSettings::DELETE_KEEP === $policy ) {
			return;
		}
		if ( PluginSettings::DELETE_TRASH === $policy ) {
			wp_trash_post( $local_post->ID );
			return;
		}
		wp_update_post( array( 'ID' => $local_post->ID, 'post_status' => 'draft' ) );
		++$result->drafted;
	}

	private function saveSuccessfulRun( Connection $connection, SyncResult $result, float $started_at ): void {
		$now = time();
		$result->duration = round( microtime( true ) - $started_at, 3 );
		$current_connection = $this->connections->find( $connection->id ) ?? $connection;
		$this->connections->save(
			$current_connection->with(
				array(
					'status'       => Connection::STATUS_CONNECTED,
					'last_sync_at' => $now,
					'next_sync_at' => $this->nextSyncAt( $connection->sync_frequency, $now ),
					'last_error'   => null,
					'diagnostics'  => array_merge( $result->toArray(), array( 'last_request_at' => $now ) ),
				)
			)
		);
	}

	private function nextSyncAt( string $frequency, ?int $from = null ): ?int {
		$from = $from ?? time();
		$seconds = match ( $frequency ) {
			PluginSettings::FREQUENCY_HOURLY => HOUR_IN_SECONDS,
			PluginSettings::FREQUENCY_TWICE  => 12 * HOUR_IN_SECONDS,
			PluginSettings::FREQUENCY_DAILY  => DAY_IN_SECONDS,
			default                          => null,
		};
		return null === $seconds ? null : $from + $seconds;
	}

	private function acquireLock( string $connection_id ): bool {
		$key = self::LOCK_OPTION_PREFIX . sanitize_key( $connection_id );
		if ( add_option( $key, time() + self::LOCK_TTL, '', false ) ) {
			return true;
		}
		$expires_at = (int) get_option( $key, 0 );
		if ( $expires_at > 0 && $expires_at < time() ) {
			delete_option( $key );
			return add_option( $key, time() + self::LOCK_TTL, '', false );
		}
		return false;
	}

	private function releaseLock( string $connection_id ): void {
		delete_option( self::LOCK_OPTION_PREFIX . sanitize_key( $connection_id ) );
	}
}
