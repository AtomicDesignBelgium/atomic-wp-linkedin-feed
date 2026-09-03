<?php
/**
 * LinkedIn provider adapter.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers\LinkedIn;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Model\NormalizedSocialPost;
use AtomicWPSocialSync\Providers\ProviderCapabilities;
use AtomicWPSocialSync\Providers\ProviderException;
use AtomicWPSocialSync\Providers\SocialProviderInterface;
use AtomicWPSocialSync\Providers\VerificationResult;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class LinkedInProvider implements SocialProviderInterface {
	public const SLUG = 'linkedin';

	public function __construct(
		private readonly LinkedInClient $client,
		private readonly LinkedInTokenManager $token_manager
	) {}

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return __( 'LinkedIn', 'atomic-wp-social-sync' );
	}

	public function capabilities(): ProviderCapabilities {
		// Publishing is deliberately disabled in V1 even when OAuth includes the media-required write scope.
		return new ProviderCapabilities( true, false, false, false, true, true, true );
	}

	/** @return array<string,mixed> */
	public function testConnection( Connection $connection ): array {
		$connection = $this->token_manager->ensureFresh( $connection );
		$this->client->get(
			$connection,
			'posts',
			array(
				'author' => 'urn:li:organization:' . $connection->account_external_id,
				'q' => 'author',
				'count' => 1,
				'viewContext' => 'READER',
			),
			array( 'X-RestLi-Method' => 'FINDER' )
		);
		$account = $this->getAccount( $connection );
		return array( 'ok' => true, 'account' => $account, 'api_version' => LinkedInClient::API_VERSION );
	}

	/** @return array{id:string,name:string} */
	public function getAccount( Connection $connection ): array {
		$connection = $this->token_manager->ensureFresh( $connection );
		try {
			$data = $this->client->get( $connection, 'organizations/' . rawurlencode( $connection->account_external_id ) );
		} catch ( ProviderException $exception ) {
			if ( ProviderException::AUTHORIZATION !== $exception->category ) {
				throw $exception;
			}
			$data = array();
		}
		return array(
			'id'   => $connection->account_external_id,
			'name' => sanitize_text_field( (string) ( $data['localizedName'] ?? $connection->account_name ) ),
		);
	}

	/** @return NormalizedSocialPost[] */
	public function fetchPosts( Connection $connection, int $limit = 100, int $start = 0 ): array {
		$connection = $this->token_manager->ensureFresh( $connection );
		$limit = max( 1, min( 100, $limit ) );
		$data = $this->client->get(
			$connection,
			'posts',
			array(
				'author'      => 'urn:li:organization:' . $connection->account_external_id,
				'q'           => 'author',
				'count'       => $limit,
				'start'       => max( 0, $start ),
				'sortBy'      => 'LAST_MODIFIED',
				'viewContext' => 'AUTHOR',
			),
			array( 'X-RestLi-Method' => 'FINDER' )
		);

		$posts = array();
		foreach ( $data['elements'] ?? array() as $remote_post ) {
			if ( is_array( $remote_post ) && 'PUBLISHED' === ( $remote_post['lifecycleState'] ?? '' ) ) {
				$posts[] = $this->normalizePost( $connection, $remote_post, true );
			}
		}
		return $posts;
	}

	public function fetchPost( Connection $connection, string $external_id ): ?NormalizedSocialPost {
		$connection = $this->token_manager->ensureFresh( $connection );
		$data = $this->client->get(
			$connection,
			'posts/' . rawurlencode( $external_id ),
			array( 'viewContext' => 'AUTHOR' )
		);
		return $this->normalizePost( $connection, $data, true );
	}

	public function verifyPostExists( Connection $connection, string $external_id ): VerificationResult {
		try {
			$post = $this->fetchPost( $connection, $external_id );
			return VerificationResult::exists( $post );
		} catch ( ProviderException $exception ) {
			// Only an authoritative single-resource NOT_FOUND is deletion evidence.
			if ( ProviderException::NOT_FOUND === $exception->category ) {
				return VerificationResult::missing();
			}
			throw $exception;
		}
	}

	/**
	 * Converts a current Posts API record into the provider-neutral domain model.
	 *
	 * @param array<string,mixed> $remote_post
	 */
	public function normalizePost( Connection $connection, array $remote_post, bool $enrich_media = false ): NormalizedSocialPost {
		$external_id = (string) ( $remote_post['id'] ?? '' );
		if ( '' === $external_id ) {
			throw new ProviderException( __( 'LinkedIn post has no ID.', 'atomic-wp-social-sync' ), ProviderException::NORMALIZATION );
		}

		$published_at = $this->dateFromMilliseconds( $remote_post['publishedAt'] ?? $remote_post['createdAt'] ?? null );
		$modified_at  = $this->dateFromMilliseconds( $remote_post['lastModifiedAt'] ?? $remote_post['publishedAt'] ?? null );
		$text         = (string) ( $remote_post['commentary'] ?? '' );
		$content      = is_array( $remote_post['content'] ?? null ) ? $remote_post['content'] : array();
		$title        = (string) ( $content['article']['title'] ?? $content['media']['title'] ?? '' );
		$excerpt      = (string) ( $content['article']['description'] ?? $text );
		$media        = $this->normalizeMedia( $connection, $content, $enrich_media );

		return new NormalizedSocialPost(
			self::SLUG,
			$connection->id,
			$external_id,
			'https://www.linkedin.com/feed/update/' . $external_id . '/',
			$title,
			$text,
			$excerpt,
			$published_at,
			$modified_at,
			(string) ( $remote_post['author'] ?? '' ),
			$connection->account_name,
			strtolower( (string) ( $remote_post['lifecycleState'] ?? 'unknown' ) ),
			$this->contentType( $content ),
			$media
		);
	}

	private function dateFromMilliseconds( mixed $milliseconds ): DateTimeImmutable {
		if ( ! is_numeric( $milliseconds ) ) {
			throw new ProviderException( __( 'LinkedIn post has no valid timestamp.', 'atomic-wp-social-sync' ), ProviderException::NORMALIZATION );
		}
		return ( new DateTimeImmutable( '@' . (string) floor( (float) $milliseconds / 1000 ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/** @param array<string,mixed> $content @return array<int,array<string,mixed>> */
	private function normalizeMedia( Connection $connection, array $content, bool $enrich ): array {
		$candidates = array();
		if ( is_array( $content['media'] ?? null ) ) {
			$candidates[] = $content['media'];
		}
		if ( is_array( $content['multiImage']['images'] ?? null ) ) {
			$candidates = array_merge( $candidates, $content['multiImage']['images'] );
		}
		if ( ! empty( $content['article']['thumbnail'] ) ) {
			$candidates[] = array( 'id' => $content['article']['thumbnail'], 'altText' => $content['article']['title'] ?? '' );
		}

		$media = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || empty( $candidate['id'] ) ) {
				continue;
			}
			$source_id = (string) $candidate['id'];
			$type      = str_contains( $source_id, ':video:' ) ? 'video' : ( str_contains( $source_id, ':image:' ) ? 'image' : 'unsupported' );
			$entry     = array(
				'type'      => $type,
				'source_id' => $source_id,
				'alt'       => sanitize_text_field( (string) ( $candidate['altText'] ?? $candidate['title'] ?? '' ) ),
				'url'       => '',
				'thumbnail' => '',
			);
			if ( $enrich && in_array( $type, array( 'image', 'video' ), true ) ) {
				try {
					$asset = $this->client->get( $connection, ( 'image' === $type ? 'images/' : 'videos/' ) . rawurlencode( $source_id ) );
					$entry['url']       = esc_url_raw( (string) ( $asset['downloadUrl'] ?? '' ) );
					$entry['thumbnail'] = esc_url_raw( (string) ( $asset['thumbnail'] ?? '' ) );
				} catch ( Throwable ) {
					// Media permission or processing failures must not discard the post itself.
				}
			}
			$media[] = $entry;
		}
		return $media;
	}

	/** @param array<string,mixed> $content */
	private function contentType( array $content ): string {
		foreach ( array( 'multiImage', 'media', 'article', 'poll', 'celebration' ) as $type ) {
			if ( isset( $content[ $type ] ) ) {
				return sanitize_key( $type );
			}
		}
		return 'text';
	}
}
