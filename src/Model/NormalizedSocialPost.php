<?php
/**
 * Provider-independent remote post value object.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final class NormalizedSocialPost {
	/**
	 * @param array<int,array<string,mixed>> $media
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $connection_id,
		public readonly string $external_id,
		public readonly string $external_url,
		public readonly string $title,
		public readonly string $text,
		public readonly string $excerpt,
		public readonly DateTimeImmutable $published_at,
		public readonly DateTimeImmutable $modified_at,
		public readonly string $author_id,
		public readonly string $author_name,
		public readonly string $remote_status,
		public readonly string $raw_type,
		public readonly array $media = array()
	) {
		if ( '' === $provider || '' === $connection_id || '' === $external_id ) {
			throw new InvalidArgumentException( __( 'Provider, connection ID, and external ID are required.', 'atomic-wp-social-sync' ) );
		}
	}

	public function identity(): string {
		return $this->provider . '|' . $this->connection_id . '|' . $this->external_id;
	}

	public function contentHash(): string {
		$media_identity = array();
		foreach ( $this->media as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$media_identity[] = array(
				'type'      => sanitize_key( (string) ( $entry['type'] ?? '' ) ),
				'source_id' => sanitize_text_field( (string) ( $entry['source_id'] ?? '' ) ),
				'alt'       => sanitize_text_field( (string) ( $entry['alt'] ?? '' ) ),
			);
		}
		usort(
			$media_identity,
			static function ( array $left, array $right ): int {
				return ( $left['type'] . '|' . $left['source_id'] ) <=> ( $right['type'] . '|' . $right['source_id'] );
			}
		);

		return hash(
			'sha256',
			wp_json_encode(
				array(
					'title'       => $this->title,
					'text'        => $this->text,
					'excerpt'     => $this->excerpt,
					'modified_at' => $this->modified_at->format( DATE_ATOM ),
					'status'      => $this->remote_status,
					'media'       => $media_identity,
				)
			)
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'provider'       => $this->provider,
			'connection_id'  => $this->connection_id,
			'external_id'    => $this->external_id,
			'external_url'   => $this->external_url,
			'title'          => $this->title,
			'text'           => $this->text,
			'excerpt'        => $this->excerpt,
			'published_at'   => $this->published_at->format( DATE_ATOM ),
			'modified_at'    => $this->modified_at->format( DATE_ATOM ),
			'author_id'      => $this->author_id,
			'author_name'    => $this->author_name,
			'remote_status'  => $this->remote_status,
			'raw_type'       => $this->raw_type,
			'media'          => $this->media,
		);
	}

	/** @param array<string,mixed> $data */
	public static function fromArray( array $data ): self {
		return new self(
			(string) $data['provider'],
			(string) $data['connection_id'],
			(string) $data['external_id'],
			(string) ( $data['external_url'] ?? '' ),
			(string) ( $data['title'] ?? '' ),
			(string) ( $data['text'] ?? '' ),
			(string) ( $data['excerpt'] ?? '' ),
			new DateTimeImmutable( (string) $data['published_at'] ),
			new DateTimeImmutable( (string) $data['modified_at'] ),
			(string) ( $data['author_id'] ?? '' ),
			(string) ( $data['author_name'] ?? '' ),
			(string) ( $data['remote_status'] ?? 'published' ),
			(string) ( $data['raw_type'] ?? 'unknown' ),
			is_array( $data['media'] ?? null ) ? $data['media'] : array()
		);
	}
}
