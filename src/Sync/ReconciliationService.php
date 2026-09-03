<?php
/**
 * Provider-neutral remote/local reconciliation rules.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Sync;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Media\MediaImporter;
use AtomicWPSocialSync\Model\NormalizedSocialPost;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use WP_Post;

final class ReconciliationService {
	public function __construct(
		private readonly SocialPostRepository $posts,
		private readonly PluginSettings $settings,
		private readonly MediaImporter $media_importer
	) {}

	public function reconcile( NormalizedSocialPost $remote_post, Connection $connection, SyncResult $result ): int {
		$local_post = $this->posts->findByRemoteIdentity( $remote_post->provider, $remote_post->connection_id, $remote_post->external_id );
		if ( ! $local_post instanceof WP_Post ) {
			$post_id = $this->posts->create( $remote_post, $connection );
			$this->maybeImportMedia( $post_id, $remote_post, $result );
			++$result->created;
			return $post_id;
		}

		if ( get_post_meta( $local_post->ID, MetaKeys::DETACHED, true ) ) {
			++$result->skipped;
			return $local_post->ID;
		}

		$stored_hash = (string) get_post_meta( $local_post->ID, MetaKeys::CONTENT_HASH, true );
		if ( '' !== $stored_hash && hash_equals( $stored_hash, $remote_post->contentHash() ) ) {
			// A previously missing post may reappear without changing content.
			$this->posts->writeRemoteMetadata( $local_post->ID, $remote_post );
			++$result->unchanged;
			return $local_post->ID;
		}

		$policy          = (string) $this->settings->get( 'remote_edit_policy', PluginSettings::EDIT_AUTOMATIC );
		$local_unchanged = $this->posts->localContentIsUnchanged( $local_post );
		if ( PluginSettings::EDIT_IGNORE === $policy ) {
			// Record the observed source state without changing local display content.
			$this->posts->writeRemoteMetadata( $local_post->ID, $remote_post );
			++$result->skipped;
			return $local_post->ID;
		}

		if ( PluginSettings::EDIT_REVIEW === $policy || ! $local_unchanged ) {
			update_post_meta( $local_post->ID, MetaKeys::SYNC_CONFLICT, $local_unchanged ? 'review' : 'conflict' );
			update_post_meta( $local_post->ID, MetaKeys::PENDING_REMOTE, wp_json_encode( $remote_post->toArray() ) );
			++$result->conflicts;
			return $local_post->ID;
		}

		$this->posts->updateFromRemote( $local_post->ID, $remote_post );
		$this->maybeImportMedia( $local_post->ID, $remote_post, $result );
		++$result->updated;
		return $local_post->ID;
	}

	public function useRemoteVersion( int $post_id ): bool {
		$pending = json_decode( (string) get_post_meta( $post_id, MetaKeys::PENDING_REMOTE, true ), true );
		if ( ! is_array( $pending ) ) {
			return false;
		}
		$this->posts->updateFromRemote( $post_id, NormalizedSocialPost::fromArray( $pending ) );
		return true;
	}

	public function keepWordPressVersion( int $post_id ): void {
		$pending = json_decode( (string) get_post_meta( $post_id, MetaKeys::PENDING_REMOTE, true ), true );
		if ( is_array( $pending ) ) {
			$remote = NormalizedSocialPost::fromArray( $pending );
			$this->posts->writeRemoteMetadata( $post_id, $remote );
		}
		delete_post_meta( $post_id, MetaKeys::SYNC_CONFLICT );
		delete_post_meta( $post_id, MetaKeys::PENDING_REMOTE );
	}

	private function maybeImportMedia( int $post_id, NormalizedSocialPost $post, SyncResult $result ): void {
		if ( ! $this->settings->get( 'import_images', true ) ) {
			return;
		}
		try {
			$this->media_importer->importFeaturedImage( $post_id, $post );
		} catch ( \Throwable $exception ) {
			// The post remains successfully imported when optional media fails.
			$result->addError( $exception->getMessage() );
		}
	}
}
