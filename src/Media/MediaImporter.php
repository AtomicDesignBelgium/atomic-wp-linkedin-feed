<?php
/**
 * Imports provider media into the WordPress Media Library.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Media;

use AtomicWPSocialSync\Model\NormalizedSocialPost;
use AtomicWPSocialSync\Support\MetaKeys;
use RuntimeException;

final class MediaImporter {
	private static bool $importing = false;

	public static function isImporting(): bool {
		return self::$importing;
	}

	public function importFeaturedImage( int $post_id, NormalizedSocialPost $normalized_post ): ?int {
		if ( get_post_meta( $post_id, MetaKeys::FEATURED_IMAGE_LOCKED, true ) ) {
			return null;
		}

		foreach ( $normalized_post->media as $media ) {
			if ( ! is_array( $media ) ) {
				continue;
			}
			$source_id = sanitize_text_field( (string) ( $media['source_id'] ?? '' ) );
			$type      = sanitize_key( (string) ( $media['type'] ?? '' ) );
			$url       = 'video' === $type ? (string) ( $media['thumbnail'] ?? '' ) : (string) ( $media['url'] ?? '' );
			if ( '' === $source_id || '' === $url || ! str_starts_with( $url, 'https://' ) ) {
				continue;
			}

			$existing_id = $this->findBySourceId( $normalized_post->provider, $source_id );
			if ( null !== $existing_id ) {
				self::$importing = true;
				set_post_thumbnail( $post_id, $existing_id );
				self::$importing = false;
				return $existing_id;
			}

			$attachment_id = $this->download( $url, $post_id, $source_id, (string) ( $media['alt'] ?? '' ) );
			update_post_meta( $attachment_id, MetaKeys::PROVIDER, $normalized_post->provider );
			update_post_meta( $attachment_id, MetaKeys::MEDIA_SOURCE_ID, $source_id );
			update_post_meta( $attachment_id, MetaKeys::MEDIA_TYPE, $type );
			self::$importing = true;
			set_post_thumbnail( $post_id, $attachment_id );
			self::$importing = false;
			return $attachment_id;
		}

		return null;
	}

	private function findBySourceId( string $provider, string $source_id ): ?int {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::PROVIDER, 'value' => $provider ),
					array( 'key' => MetaKeys::MEDIA_SOURCE_ID, 'value' => $source_id ),
				),
			)
		);
		return isset( $attachments[0] ) ? (int) $attachments[0] : null;
	}

	private function download( string $url, int $post_id, string $source_id, string $alt ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $url, 30 );
		if ( is_wp_error( $temp_file ) ) {
			throw new RuntimeException( sprintf( __( 'Media download failed: %s', 'atomic-wp-social-sync' ), $temp_file->get_error_message() ) );
		}

		$path         = (string) wp_parse_url( $url, PHP_URL_PATH );
		$original     = sanitize_file_name( wp_basename( $path ) );
		$filename     = '' !== $original && str_contains( $original, '.' ) ? $original : sanitize_file_name( $source_id ) . '.jpg';
		$file         = array( 'name' => $filename, 'tmp_name' => $temp_file );
		$attachment_id = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp_file );
			throw new RuntimeException( sprintf( __( 'Media import failed: %s', 'atomic-wp-social-sync' ), $attachment_id->get_error_message() ) );
		}
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $attachment_id;
	}
}
