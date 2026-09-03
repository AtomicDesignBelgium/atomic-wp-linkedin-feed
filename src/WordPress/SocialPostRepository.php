<?php
/**
 * WordPress persistence for normalized social posts.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\WordPress;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Model\NormalizedSocialPost;
use AtomicWPSocialSync\Support\MetaKeys;
use RuntimeException;
use WP_Post;

final class SocialPostRepository {
	private static bool $synchronizing = false;

	public static function isSynchronizing(): bool {
		return self::$synchronizing;
	}

	public function findByRemoteIdentity( string $provider, string $connection_id, string $external_id ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => self::allStatuses(),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::PROVIDER, 'value' => $provider ),
					array( 'key' => MetaKeys::CONNECTION_ID, 'value' => $connection_id ),
					array( 'key' => MetaKeys::EXTERNAL_ID, 'value' => $external_id ),
				),
			)
		);
		return $posts[0] ?? null;
	}

	public function create( NormalizedSocialPost $normalized_post, Connection $connection ): int {
		$generated_title = $this->generateTitle( $normalized_post );
		$published_gmt   = $normalized_post->published_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		self::$synchronizing = true;
		$post_id = wp_insert_post(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => 'publish',
				'post_title'     => $generated_title,
				'post_content'   => wp_kses_post( $normalized_post->text ),
				'post_excerpt'   => wp_strip_all_tags( $normalized_post->excerpt ),
				'post_date_gmt'  => $published_gmt,
				'post_date'      => get_date_from_gmt( $published_gmt ),
			),
			true
		);
		self::$synchronizing = false;

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}

		$this->writeRemoteMetadata( $post_id, $normalized_post );
		update_post_meta( $post_id, MetaKeys::GENERATED_TITLE, $generated_title );
		update_post_meta( $post_id, MetaKeys::LOCAL_CONTENT_HASH, $this->localContentHash( $normalized_post->text, $normalized_post->excerpt ) );
		update_post_meta( $post_id, MetaKeys::SHOW_ON_HOMEPAGE, '1' );
		update_post_meta( $post_id, MetaKeys::HIDDEN_FROM_FEED, '0' );
		update_post_meta( $post_id, MetaKeys::PINNED, '0' );
		update_post_meta( $post_id, MetaKeys::DETACHED, '0' );
		$this->classify( $post_id, $normalized_post->provider, $connection );

		return $post_id;
	}

	public function updateFromRemote( int $post_id, NormalizedSocialPost $normalized_post ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( __( 'Social post was not found.', 'atomic-wp-social-sync' ) );
		}

		$post_update = array(
			'ID'           => $post_id,
			'post_content' => wp_kses_post( $normalized_post->text ),
			'post_excerpt' => wp_strip_all_tags( $normalized_post->excerpt ),
		);
		if ( ! get_post_meta( $post_id, MetaKeys::TITLE_LOCKED, true ) ) {
			$post_update['post_title'] = $this->generateTitle( $normalized_post );
		}

		self::$synchronizing = true;
		$result = wp_update_post( $post_update, true );
		self::$synchronizing = false;
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}

		if ( isset( $post_update['post_title'] ) ) {
			update_post_meta( $post_id, MetaKeys::GENERATED_TITLE, $post_update['post_title'] );
		}
		update_post_meta( $post_id, MetaKeys::LOCAL_CONTENT_HASH, $this->localContentHash( $normalized_post->text, $normalized_post->excerpt ) );
		$this->writeRemoteMetadata( $post_id, $normalized_post );
		delete_post_meta( $post_id, MetaKeys::SYNC_CONFLICT );
		delete_post_meta( $post_id, MetaKeys::PENDING_REMOTE );
	}

	public function writeRemoteMetadata( int $post_id, NormalizedSocialPost $normalized_post ): void {
		$now = gmdate( 'c' );
		$values = array(
			MetaKeys::EXTERNAL_ID          => $normalized_post->external_id,
			MetaKeys::EXTERNAL_URL         => esc_url_raw( $normalized_post->external_url ),
			MetaKeys::PROVIDER             => $normalized_post->provider,
			MetaKeys::CONNECTION_ID        => $normalized_post->connection_id,
			MetaKeys::REMOTE_TEXT          => $normalized_post->text,
			MetaKeys::REMOTE_PUBLISHED_AT  => $normalized_post->published_at->format( DATE_ATOM ),
			MetaKeys::REMOTE_MODIFIED_AT   => $normalized_post->modified_at->format( DATE_ATOM ),
			MetaKeys::REMOTE_AUTHOR_ID     => $normalized_post->author_id,
			MetaKeys::REMOTE_AUTHOR_NAME   => $normalized_post->author_name,
			MetaKeys::LAST_SYNCED_AT       => $now,
			MetaKeys::LAST_VERIFIED_AT     => $now,
			MetaKeys::CONTENT_HASH         => $normalized_post->contentHash(),
			MetaKeys::REMOTE_STATUS        => $normalized_post->remote_status,
			MetaKeys::RAW_TYPE             => $normalized_post->raw_type,
			MetaKeys::MEDIA_TYPE           => array_values( array_filter( array_map( static fn( mixed $media ): string => is_array( $media ) ? sanitize_key( (string) ( $media['type'] ?? '' ) ) : '', $normalized_post->media ) ) ),
			MetaKeys::MEDIA_SOURCE_ID      => array_values( array_filter( array_map( static fn( mixed $media ): string => is_array( $media ) ? sanitize_text_field( (string) ( $media['source_id'] ?? '' ) ) : '', $normalized_post->media ) ) ),
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		delete_post_meta( $post_id, MetaKeys::REMOTE_MISSING_SINCE );
	}

	public function localContentIsUnchanged( WP_Post $post ): bool {
		$baseline = (string) get_post_meta( $post->ID, MetaKeys::LOCAL_CONTENT_HASH, true );
		return '' !== $baseline && hash_equals( $baseline, $this->localContentHash( $post->post_content, $post->post_excerpt ) );
	}

	/** @return WP_Post[] */
	public function postsForConnection( string $connection_id ): array {
		$posts = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => self::allStatuses(),
				'posts_per_page' => -1,
				'meta_key'       => MetaKeys::CONNECTION_ID,
				'meta_value'     => $connection_id,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		usort(
			$posts,
			static function ( WP_Post $left, WP_Post $right ): int {
				$left_verified  = strtotime( (string) get_post_meta( $left->ID, MetaKeys::LAST_VERIFIED_AT, true ) ) ?: 0;
				$right_verified = strtotime( (string) get_post_meta( $right->ID, MetaKeys::LAST_VERIFIED_AT, true ) ) ?: 0;
				return $left_verified === $right_verified ? $left->ID <=> $right->ID : $left_verified <=> $right_verified;
			}
		);
		return $posts;
	}

	private function generateTitle( NormalizedSocialPost $post ): string {
		$candidate = '' !== trim( $post->title ) ? $post->title : $post->text;
		$candidate = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $candidate ) ) ?? '' );
		if ( '' === $candidate ) {
			$candidate = __( 'Untitled social post', 'atomic-wp-social-sync' );
		}
		return function_exists( 'mb_strimwidth' )
			? mb_strimwidth( $candidate, 0, 80, '…', 'UTF-8' )
			: wp_html_excerpt( $candidate, 79, '…' );
	}

	private function localContentHash( string $content, string $excerpt ): string {
		return hash( 'sha256', wp_json_encode( array( 'content' => $content, 'excerpt' => $excerpt ) ) );
	}

	private function classify( int $post_id, string $provider, Connection $connection ): void {
		wp_set_object_terms( $post_id, $provider, SocialPostType::PROVIDER_TAXONOMY );
		$term = term_exists( $connection->id, SocialPostType::CONNECTION_TAXONOMY );
		if ( ! $term ) {
			$term_name = $connection->account_name;
			if ( term_exists( $term_name, SocialPostType::CONNECTION_TAXONOMY ) ) {
				$term_name .= ' [' . substr( $connection->id, 0, 8 ) . ']';
			}
			$term = wp_insert_term(
				$term_name,
				SocialPostType::CONNECTION_TAXONOMY,
				array( 'slug' => $connection->id )
			);
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $post_id, $connection->id, SocialPostType::CONNECTION_TAXONOMY );
		}
	}

	/** @return string[] */
	private static function allStatuses(): array {
		// Trash is included so a remote item can never be duplicated after a local/editorial trash action.
		return array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
	}
}
